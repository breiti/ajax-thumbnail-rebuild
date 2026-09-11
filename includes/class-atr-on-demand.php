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

		if ( ! self::is_resizable( (string) get_post_mime_type( $id ) ) ) {
			/* wp_attachment_is_image() says yes to an SVG, so everything that asks an image
			   for its sizes asks one of these for all of them — and a drawing has no crop to
			   make. Core never cuts one and neither should this. */
			return $downsize;
		}

		$file = get_attached_file( $id );

		if ( ! $file || ! file_exists( $file ) ) {
			return $downsize;
		}

		self::$generated[ $id . '|' . $size ] = true;

		$source = ATR_Regenerator::source_file( $id, $file );

		if ( ! self::fits( $source, $file, $metadata, $sizes[ $size ] ) ) {
			/* The original is smaller than the box being asked for, and WordPress does not
			   upscale, so there is nothing here to cut — not on this page view and not on
			   any later one either.

			   Worked out by arithmetic rather than by trying. image_make_intermediate_size()
			   reaches the same answer, but it loads an image editor and reads the file to
			   get there, and self::$generated above remembers the attempt only for the
			   length of the request: a size too large for its original was being attempted
			   again on every single render, for as long as a page asked for it. Measured on
			   one site at 37 ms a time, six of them on the front page, on every hit. */
			return $downsize;
		}

		ATR_Regenerator::load_image_api();

		$intermediate = image_make_intermediate_size(
			$source,
			$sizes[ $size ]['width'],
			$sizes[ $size ]['height'],
			$sizes[ $size ]['crop']
		);

		if ( ! $intermediate ) {
			/* The arithmetic said there was a crop here and the editor disagreed — a file
			   that is not what its mime type says, or one it cannot read. Core's own
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

	/**
	 * Whether a crop of this size can be cut from the source at all.
	 *
	 * The cut is made from the source file, which is the unscaled original wherever
	 * WordPress kept one — so the metadata's own width and height, which describe the
	 * scaled file beside it, are not the figures to measure against. They are used
	 * only when the two are the same file, which saves reading a header for the
	 * common case of an upload small enough never to have been scaled.
	 *
	 * @param  string $source   Path the crop would be cut from.
	 * @param  string $file     Path the attachment metadata describes.
	 * @param  array  $metadata Attachment metadata.
	 * @param  array  $size     Registered size, as ATR_Image_Sizes::all() returns one.
	 * @return bool
	 */
	private static function fits( string $source, string $file, array $metadata, array $size ): bool {
		if ( $source === $file ) {
			$width  = (int) ( $metadata['width'] ?? 0 );
			$height = (int) ( $metadata['height'] ?? 0 );
		} else {
			$dimensions = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$width      = (int) ( $dimensions[0] ?? 0 );
			$height     = (int) ( $dimensions[1] ?? 0 );
		}

		if ( ! $width || ! $height ) {
			// Nothing to measure. Leave the answer to the editor, as before.
			return true;
		}

		return false !== image_resize_dimensions(
			$width,
			$height,
			(int) $size['width'],
			(int) $size['height'],
			$size['crop']
		);
	}

	/**
	 * Whether an image editor here will resize this mime type.
	 *
	 * Vectors are answered without asking. An Imagick built with librsvg says it can
	 * resize an SVG and is right — it would rasterise the drawing and write a PNG crop
	 * of a mark that was already the right size at every size. Core never cuts one
	 * whatever the editors say.
	 *
	 * Answered per mime type rather than per attachment: which formats can be resized
	 * is a property of the GD and Imagick this site runs on, and the question is asked
	 * once per size per image on a page.
	 *
	 * @param  string $mime Mime type.
	 * @return bool
	 */
	private static function is_resizable( string $mime ): bool {
		if ( 'image/svg+xml' === $mime || 'image/svg' === $mime ) {
			return false;
		}

		static $supported = array();

		if ( ! isset( $supported[ $mime ] ) ) {
			$supported[ $mime ] = (bool) wp_image_editor_supports(
				array(
					'mime_type' => $mime,
					'methods'   => array( 'resize' ),
				)
			);
		}

		return $supported[ $mime ];
	}
}
