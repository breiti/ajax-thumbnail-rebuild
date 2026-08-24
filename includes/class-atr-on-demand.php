<?php
/**
 * Making the resized copies when they are first asked for.
 *
 * A site with a dozen registered sizes writes a dozen files for every upload,
 * most of which no page ever requests. With this on, an upload keeps its own
 * file and each size is cut the first time something asks for it - after which
 * it is on disk like any other, recorded in the attachment metadata.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_On_Demand {

	/** Priority of the callback that keeps WordPress from generating sizes up front. */
	const STRIP_PRIORITY = 999;

	/** Attachments generated during this request, so one request cuts a size once. */
	private static array $generated = array();

	public function register(): void {
		if ( ! self::is_enabled() && ! self::sizes() ) {
			return;
		}

		add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'strip_sizes' ), self::STRIP_PRIORITY, 3 );
		add_filter( 'image_downsize', array( $this, 'maybe_generate' ), 10, 3 );
	}

	/**
	 * Whether every size waits to be asked for.
	 */
	public static function is_enabled(): bool {
		return (bool) apply_filters( 'ajax_thumbnail_rebuild_on_demand_enabled', (bool) ATR_Settings::get( 'on_demand_enabled' ) );
	}

	/**
	 * Sizes marked as on demand one by one, whatever the setting above says.
	 *
	 * @return string[]
	 */
	public static function sizes(): array {
		$sizes = (array) apply_filters( 'ajax_thumbnail_rebuild_on_demand_sizes', (array) ATR_Settings::get( 'on_demand_sizes', array() ) );

		return array_values( array_filter( array_map( 'strval', $sizes ) ) );
	}

	/**
	 * Whether this one size is left for the first request that needs it.
	 */
	public static function covers( string $size ): bool {
		return self::is_enabled() || in_array( $size, self::sizes(), true );
	}

	/**
	 * Take the sizes that wait out of the list WordPress generates on upload.
	 *
	 * The plugin's own screens and its rebuild take this callback off the filter
	 * while they read the size list, so they still see every registered size.
	 *
	 * @param array $sizes Sizes core is about to generate.
	 * @return array
	 */
	public static function strip_sizes( $sizes ) {
		if ( self::is_enabled() ) {
			return array();
		}

		return array_diff_key( (array) $sizes, array_flip( self::sizes() ) );
	}

	/**
	 * Cut a missing size the first time it is asked for.
	 *
	 * @param array|false  $downsize Whether another filter already handled this.
	 * @param int          $id       Attachment id.
	 * @param string|int[] $size     Requested size.
	 * @return array|false
	 */
	public function maybe_generate( $downsize, $id, $size ) {
		if ( false !== $downsize || ! is_string( $size ) || 'full' === $size ) {
			return $downsize;
		}

		$id = (int) $id;

		if ( isset( self::$generated[ $id . '|' . $size ] ) ) {
			return $downsize;
		}

		if ( ! self::covers( $size ) ) {
			/* Only some sizes wait, and this is not one of them: a missing file here
			   is not this feature's business. */
			return $downsize;
		}

		$sizes = ATR_Image_Sizes::all();

		if ( ! isset( $sizes[ $size ] ) ) {
			// Not a size this site registers; core will fall back to the full file.
			return $downsize;
		}

		$metadata = wp_get_attachment_metadata( $id, true );

		if ( ! is_array( $metadata ) || ! empty( $metadata['sizes'][ $size ] ) ) {
			// Already generated, or an attachment with no metadata to add it to.
			return $downsize;
		}

		if ( ! wp_attachment_is_image( $id ) ) {
			return $downsize;
		}

		$file = get_attached_file( $id );

		if ( ! $file || ! file_exists( $file ) ) {
			return $downsize;
		}

		self::$generated[ $id . '|' . $size ] = true;

		ATR_Regenerator::load_image_api();

		$source       = ATR_Regenerator::source_file( $id, $file );
		$intermediate = image_make_intermediate_size(
			$source,
			$sizes[ $size ]['width'],
			$sizes[ $size ]['height'],
			$sizes[ $size ]['crop']
		);

		if ( ! $intermediate ) {
			/* Smaller than the requested box, so there is nothing to cut: core's own
			   fallback to the full file is the right answer. */
			return $downsize;
		}

		$metadata['sizes'][ $size ] = $intermediate;
		wp_update_attachment_metadata( $id, $metadata );

		ATR_Copies::convert_all( trailingslashit( dirname( $file ) ) . $intermediate['file'] );

		$upload_dir = trailingslashit( dirname( (string) _wp_relative_upload_path( $file ) ) );
		$base       = trailingslashit( wp_get_upload_dir()['baseurl'] );

		return array(
			$base . ( './' === $upload_dir ? '' : $upload_dir ) . $intermediate['file'],
			(int) $intermediate['width'],
			(int) $intermediate['height'],
			true,
		);
	}
}
