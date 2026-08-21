<?php
/**
 * Serving images through the wsrv.nl image proxy.
 *
 * Nothing is copied anywhere: the front end simply asks wsrv.nl for the image,
 * the proxy fetches it from this site once, resizes and re-encodes it, and
 * serves the result from its CDN. Switching the option off puts every URL back.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Image_Proxy {

	const ENDPOINT = 'https://wsrv.nl/';

	/** File types the proxy cannot usefully handle. */
	const SKIP_EXTENSIONS = array( 'svg', 'svgz', 'ico' );

	public function register(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_image_src' ), 20, 3 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset' ), 20 );
		add_filter( 'wp_content_img_tag', array( $this, 'filter_content_img' ), 20 );
	}

	public static function is_enabled(): bool {
		return (bool) apply_filters( 'ajax_thumbnail_rebuild_proxy_enabled', (bool) ATR_Settings::get( 'proxy_enabled' ) );
	}

	/**
	 * Whether this request should have its image URLs rewritten at all.
	 */
	private function should_rewrite(): bool {
		if ( is_feed() || is_customize_preview() ) {
			// Feed readers and the customizer both need URLs on this site.
			return false;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		return true;
	}

	/**
	 * @param array|false $image         Array of URL, width, height, is_intermediate.
	 * @param int         $attachment_id Attachment id.
	 * @param string|int[] $size         Requested size.
	 * @return array|false
	 */
	public function filter_image_src( $image, $attachment_id, $size ) {
		if ( ! is_array( $image ) || empty( $image[0] ) || ! $this->should_rewrite() ) {
			return $image;
		}

		$image[0] = self::proxy_url( $image[0], array(
			'w'   => (int) ( $image[1] ?? 0 ),
			'h'   => (int) ( $image[2] ?? 0 ),
			'fit' => $this->is_cropped( $size ) ? 'cover' : 'inside',
		) );

		return $image;
	}

	/**
	 * @param array $sources One entry per candidate, each with 'url' and 'value'.
	 * @return array
	 */
	public function filter_srcset( $sources ) {
		if ( ! is_array( $sources ) || ! $this->should_rewrite() ) {
			return $sources;
		}

		foreach ( $sources as $key => $source ) {
			if ( empty( $source['url'] ) || 'w' !== ( $source['descriptor'] ?? 'w' ) ) {
				continue;
			}

			$sources[ $key ]['url'] = self::proxy_url( $source['url'], array(
				'w'   => (int) ( $source['value'] ?? 0 ),
				'fit' => 'inside',
			) );
		}

		return $sources;
	}

	/**
	 * Images written into post content, which carry their own src and srcset.
	 *
	 * @param string $html The <img> tag.
	 * @return string
	 */
	public function filter_content_img( $html ) {
		if ( ! is_string( $html ) || ! $this->should_rewrite() ) {
			return $html;
		}

		$width = 0;

		if ( preg_match( '/\swidth=["\'](\d+)["\']/', $html, $match ) ) {
			$width = (int) $match[1];
		}

		return (string) preg_replace_callback(
			'/\s(src|srcset)=["\']([^"\']+)["\']/',
			function ( $match ) use ( $width ) {
				if ( 'src' === $match[1] ) {
					$value = self::proxy_url( $match[2], array( 'w' => $width, 'fit' => 'inside' ) );

					return sprintf( ' src="%s"', esc_url( $value ) );
				}

				return sprintf( ' srcset="%s"', esc_attr( $this->rewrite_srcset_attribute( $match[2] ) ) );
			},
			$html
		);
	}

	/**
	 * Rewrite each candidate of a srcset attribute string.
	 */
	private function rewrite_srcset_attribute( string $srcset ): string {
		$candidates = array_map( 'trim', explode( ',', $srcset ) );

		foreach ( $candidates as $index => $candidate ) {
			$parts = preg_split( '/\s+/', $candidate );

			if ( ! $parts || empty( $parts[0] ) ) {
				continue;
			}

			$descriptor = $parts[1] ?? '';
			$width      = 'w' === substr( $descriptor, -1 ) ? (int) $descriptor : 0;
			$parts[0]   = self::proxy_url( $parts[0], array( 'w' => $width, 'fit' => 'inside' ) );

			$candidates[ $index ] = implode( ' ', $parts );
		}

		return implode( ', ', $candidates );
	}

	/**
	 * Whether the requested size crops, and so needs fit=cover from the proxy.
	 *
	 * @param string|int[] $size Requested size.
	 */
	private function is_cropped( $size ): bool {
		if ( ! is_string( $size ) ) {
			return false;
		}

		$sizes = ATR_Image_Sizes::all();

		return ! empty( $sizes[ $size ]['crop'] );
	}

	/**
	 * The proxied URL for one image, or the original when it cannot be proxied.
	 *
	 * @param string $url  Image URL on this site.
	 * @param array  $args Proxy arguments: w, h, fit.
	 * @return string
	 */
	public static function proxy_url( string $url, array $args = array() ): string {
		if ( ! self::is_proxyable( $url ) ) {
			return $url;
		}

		$query = array(
			'url' => preg_replace( '#^https?://#', '', $url ),
			'q'   => (int) ATR_Settings::get( 'proxy_quality', 82 ),
		);

		if ( ! empty( $args['w'] ) ) {
			$query['w'] = (int) $args['w'];
		}

		if ( ! empty( $args['h'] ) ) {
			$query['h'] = (int) $args['h'];
		}

		if ( ! empty( $args['fit'] ) ) {
			$query['fit'] = $args['fit'];
		}

		if ( ! empty( $query['w'] ) || ! empty( $query['h'] ) ) {
			// Never scale a small original up to fill the requested box.
			$query['we'] = '';
		}

		$output = (string) ATR_Settings::get( 'proxy_output', 'webp' );

		if ( 'original' !== $output ) {
			$query['output'] = $output;
		}

		/**
		 * Filter the query arguments sent to the proxy.
		 *
		 * @param array  $query Arguments, 'url' among them.
		 * @param string $url   The original image URL.
		 * @param array  $args  What the caller asked for.
		 */
		$query = (array) apply_filters( 'ajax_thumbnail_rebuild_proxy_args', $query, $url, $args );

		$proxied = self::ENDPOINT . '?' . self::build_query( $query );

		/**
		 * Filter the finished proxy URL.
		 *
		 * @param string $proxied The proxied URL.
		 * @param string $url     The original image URL.
		 */
		return (string) apply_filters( 'ajax_thumbnail_rebuild_proxy_url', $proxied, $url );
	}

	/**
	 * build_query, but keeping valueless flags such as "we" as bare keys.
	 */
	private static function build_query( array $query ): string {
		$pairs = array();

		foreach ( $query as $key => $value ) {
			$pairs[] = '' === $value
				? rawurlencode( (string) $key )
				: rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}

		return implode( '&', $pairs );
	}

	/**
	 * Only images that live on this site, and that the proxy can read.
	 */
	private static function is_proxyable( string $url ): bool {
		if ( 0 === strpos( $url, self::ENDPOINT ) ) {
			// Already proxied - filters can run more than once on the same URL.
			return false;
		}

		if ( ! preg_match( '#^https?://#', $url ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host || strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return false;
		}

		$extension = strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		return '' !== $extension && ! in_array( $extension, self::SKIP_EXTENSIONS, true );
	}

	/**
	 * Whether this site looks like one the proxy will not be able to reach.
	 */
	public static function site_looks_local(): bool {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' === $host || 'localhost' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		foreach ( array( '.local', '.test', '.localhost', '.lndo.site', '.ddev.site', '.invalid' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}
}
