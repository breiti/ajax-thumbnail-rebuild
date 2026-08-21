<?php
/**
 * WebP copies of the images this site serves.
 *
 * A WebP copy is written next to every generated size as "<file>.webp", and the
 * front end serves it through a <picture> element with the original as the
 * fallback source. That works behind a page cache, which sniffing the Accept
 * header does not: one cached page would then be served to every browser.
 *
 * Nothing replaces the original file, so switching the option off simply stops
 * the <picture> wrapping and every image is served from its own file again.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Webp {

	/** Suffix appended to the original file name, e.g. photo-300x300.jpg.webp. */
	const SUFFIX = '.webp';

	/** Source types worth converting. */
	const SOURCE_MIMES = array( 'image/jpeg', 'image/png' );

	public function register(): void {
		// Generation follows the metadata, so uploads and rebuilds are both covered.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'generate_for_attachment' ), 20, 2 );
		add_action( 'delete_attachment', array( $this, 'delete_for_attachment' ) );

		if ( ! self::is_serving() ) {
			return;
		}

		add_filter( 'wp_content_img_tag', array( $this, 'filter_img_tag' ), 30 );
		add_filter( 'wp_get_attachment_image', array( $this, 'filter_img_tag' ), 30 );
	}

	/**
	 * Whether WebP copies are written at all.
	 */
	public static function is_enabled(): bool {
		return (bool) apply_filters( 'ajax_thumbnail_rebuild_webp_enabled', (bool) ATR_Settings::get( 'webp_enabled' ) );
	}

	/**
	 * Whether the front end should serve them.
	 *
	 * Independent of the proxy: both features can be on at once. A URL the proxy
	 * has already rewritten is not one of this site's files any more, so it finds
	 * no copy to offer and leaves the tag alone - the proxy is serving WebP from
	 * its CDN for that image anyway.
	 */
	public static function is_serving(): bool {
		return self::is_enabled();
	}

	/**
	 * Write a WebP copy of the full size and of every generated size.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment id.
	 * @return array Metadata, untouched apart from our own bookkeeping key.
	 */
	public function generate_for_attachment( $metadata, $attachment_id ) {
		if ( ! is_array( $metadata ) || ! self::is_enabled() ) {
			return $metadata;
		}

		$file = get_attached_file( (int) $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return $metadata;
		}

		$written = array();

		foreach ( self::source_files( $metadata, $file ) as $path ) {
			if ( self::convert( $path ) ) {
				$written[] = wp_basename( $path ) . self::SUFFIX;
			}
		}

		/* Recorded so the copies can be found and deleted later without guessing at
		   file names, and so a site can see what was written. */
		if ( $written ) {
			$metadata['atr_webp'] = $written;
		} else {
			unset( $metadata['atr_webp'] );
		}

		return $metadata;
	}

	/**
	 * Every file belonging to an attachment that is worth a WebP copy.
	 *
	 * @param array  $metadata Attachment metadata.
	 * @param string $file     Full size path.
	 * @return string[] Absolute paths.
	 */
	private static function source_files( array $metadata, string $file ): array {
		$directory = trailingslashit( dirname( $file ) );
		$paths     = array( $file );

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$paths[] = $directory . $size['file'];
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Convert one file, unless the result would be no smaller than the original.
	 *
	 * @param string $path Absolute path of the source image.
	 * @return bool Whether a WebP copy exists for this file afterwards.
	 */
	public static function convert( string $path ): bool {
		if ( ! file_exists( $path ) ) {
			return false;
		}

		$mime = wp_get_image_mime( $path );

		if ( ! in_array( $mime, self::SOURCE_MIMES, true ) ) {
			return false;
		}

		$target = $path . self::SUFFIX;

		if ( file_exists( $target ) && filemtime( $target ) >= filemtime( $path ) ) {
			// Already converted and still current.
			return true;
		}

		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return false;
		}

		$editor = wp_get_image_editor( $path );

		if ( is_wp_error( $editor ) ) {
			return false;
		}

		$editor->set_quality( (int) ATR_Settings::get( 'webp_quality', 82 ) );

		$saved = $editor->save( $target, 'image/webp' );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return false;
		}

		/* A WebP that is bigger than the file it replaces is worse than no WebP at
		   all - PNG line art does this regularly. */
		if ( filesize( $saved['path'] ) >= filesize( $path ) ) {
			wp_delete_file( $saved['path'] );

			return false;
		}

		return true;
	}

	/**
	 * Remove the copies along with the attachment.
	 *
	 * @param int $attachment_id Attachment being deleted.
	 */
	public function delete_for_attachment( $attachment_id ): void {
		$file = get_attached_file( (int) $attachment_id );

		if ( ! $file ) {
			return;
		}

		$metadata = wp_get_attachment_metadata( (int) $attachment_id, true );

		foreach ( self::source_files( is_array( $metadata ) ? $metadata : array(), $file ) as $path ) {
			$copy = $path . self::SUFFIX;

			if ( file_exists( $copy ) ) {
				wp_delete_file( $copy );
			}
		}
	}

	/**
	 * Wrap an image tag in a <picture> with the WebP copies in front.
	 *
	 * @param string $html The <img> tag.
	 * @return string
	 */
	public function filter_img_tag( $html ) {
		if ( ! is_string( $html ) || false !== strpos( $html, '<picture' ) || is_feed() ) {
			return $html;
		}

		if ( ! preg_match( '/\ssrc=["\']([^"\']+)["\']/', $html, $match ) ) {
			return $html;
		}

		$webp_src = self::webp_url( $match[1] );

		if ( ! $webp_src ) {
			// No copy for the main file: a partial <picture> would only add markup.
			return $html;
		}

		$srcset = '';

		if ( preg_match( '/\ssrcset=["\']([^"\']+)["\']/', $html, $srcset_match ) ) {
			// Empty when any candidate has no copy: a mixed srcset would 404 half the time.
			$srcset = self::webp_srcset( $srcset_match[1] );
		}

		$attributes = array( sprintf( 'type="image/webp" srcset="%s"', esc_attr( $srcset ?: $webp_src ) ) );

		/* sizes only means something beside a srcset with width descriptors, which is
		   exactly the case where the whole srcset could be mapped. */
		if ( $srcset && preg_match( '/\ssizes=["\']([^"\']+)["\']/', $html, $sizes_match ) ) {
			$attributes[] = sprintf( 'sizes="%s"', esc_attr( $sizes_match[1] ) );
		}

		return sprintf( '<picture><source %s />%s</picture>', implode( ' ', $attributes ), $html );
	}

	/**
	 * Rewrite every candidate of a srcset, dropping the ones with no copy.
	 */
	private static function webp_srcset( string $srcset ): string {
		$candidates = array();

		foreach ( array_map( 'trim', explode( ',', $srcset ) ) as $candidate ) {
			$parts = preg_split( '/\s+/', $candidate );

			if ( ! $parts || empty( $parts[0] ) ) {
				continue;
			}

			$url = self::webp_url( $parts[0] );

			if ( ! $url ) {
				// One missing copy would otherwise let the browser pick a 404.
				return '';
			}

			$parts[0]     = $url;
			$candidates[] = implode( ' ', $parts );
		}

		return implode( ', ', $candidates );
	}

	/**
	 * The URL of the WebP copy of an image URL, when the copy is on disk.
	 *
	 * @param string $url Image URL on this site.
	 * @return string Empty when there is no copy to serve.
	 */
	public static function webp_url( string $url ): string {
		$uploads = wp_get_upload_dir();
		$base    = set_url_scheme( $uploads['baseurl'] );
		$clean   = set_url_scheme( strtok( $url, '?' ) );

		if ( 0 !== strpos( $clean, $base ) ) {
			return '';
		}

		$relative = ltrim( substr( $clean, strlen( $base ) ), '/' );
		$path     = trailingslashit( $uploads['basedir'] ) . $relative . self::SUFFIX;

		if ( ! file_exists( $path ) ) {
			return '';
		}

		return $uploads['baseurl'] . '/' . $relative . self::SUFFIX;
	}
}
