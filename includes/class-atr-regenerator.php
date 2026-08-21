<?php
/**
 * Rebuilding the sub sizes of a single attachment.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Regenerator {

	/**
	 * Rebuild the selected sizes of one attachment.
	 *
	 * @param int        $attachment_id Attachment to rebuild.
	 * @param array|null $only_sizes    Size names to rebuild, or null for all of them.
	 * @return array{skipped:bool,message:string,thumbnail:string,sizes:array} Result for the caller.
	 */
	public static function rebuild( int $attachment_id, ?array $only_sizes = null ): array {
		self::load_image_api();

		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			/* Nothing the caller can do about it, so report a skip rather than an error:
			   a run of a thousand images should not stop on one missing file. */
			return array(
				'skipped'   => true,
				'message'   => __( 'The file is missing from the uploads folder.', 'ajax-thumbnail-rebuild' ),
				'thumbnail' => '',
				'sizes'     => array(),
			);
		}

		if ( ! wp_attachment_is_image( $attachment_id ) || ! file_is_displayable_image( $file ) ) {
			return array(
				'skipped'   => true,
				'message'   => __( 'The file could not be read as an image.', 'ajax-thumbnail-rebuild' ),
				'thumbnail' => '',
				'sizes'     => array(),
			);
		}

		/* Resizing is slow and memory hungry; without raising both, a busy library
		   reliably dies part way through the run. */
		self::raise_limits();

		$metadata = self::generate_metadata( $attachment_id, $file, $only_sizes );

		wp_update_attachment_metadata( $attachment_id, $metadata );

		return array(
			'skipped'   => false,
			'message'   => '',
			'thumbnail' => (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
			'sizes'     => array_keys( (array) ( $metadata['sizes'] ?? array() ) ),
		);
	}

	/**
	 * Generate attachment metadata for the selected sizes.
	 *
	 * @param int        $attachment_id Attachment id to process.
	 * @param string     $file          File path of the attached image.
	 * @param array|null $only_sizes    Size names to rebuild, or null for all of them.
	 * @return array Metadata for the attachment.
	 */
	public static function generate_metadata( int $attachment_id, string $file, ?array $only_sizes = null ): array {
		self::load_image_api();

		$attachment = get_post( $attachment_id );

		/* The result goes straight to wp_update_attachment_metadata(), which replaces the
		   whole record, so start from what is stored and overwrite only what we rebuild.
		   Starting from an empty array drops 'original_image' - the pointer WordPress needs
		   to find and delete the untouched upload - and 'filesize'. */
		$metadata = wp_get_attachment_metadata( $attachment_id, true );

		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		$mime = $attachment ? (string) get_post_mime_type( $attachment ) : '';

		if ( 0 !== strpos( $mime, 'image/' ) || ! file_is_displayable_image( $file ) ) {
			return $metadata;
		}

		$imagesize = wp_getimagesize( $file );

		if ( ! $imagesize ) {
			// Unreadable or corrupt: keep what is stored rather than writing a broken record.
			return $metadata;
		}

		$metadata['width']  = (int) $imagesize[0];
		$metadata['height'] = (int) $imagesize[1];

		// Make the file path relative to the upload dir.
		$metadata['file'] = _wp_relative_upload_path( $file );

		if ( function_exists( 'wp_filesize' ) ) {
			$metadata['filesize'] = wp_filesize( $file );
		}

		if ( ! isset( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			$metadata['sizes'] = array();
		}

		$source = self::source_file( $attachment_id, $file );

		$on_demand = ATR_On_Demand::sizes();

		foreach ( ATR_Image_Sizes::all( $metadata, $attachment_id ) as $name => $size ) {
			if ( null !== $only_sizes && ! in_array( $name, $only_sizes, true ) ) {
				// Not selected, so keep the size already stored in $metadata.
				continue;
			}

			if ( in_array( $name, $on_demand, true ) ) {
				// Marked as on demand: it is cut when a page asks for it, not here.
				continue;
			}

			$intermediate = image_make_intermediate_size( $source, $size['width'], $size['height'], $size['crop'] );

			if ( $intermediate ) {
				$metadata['sizes'][ $name ] = $intermediate;

				// Freshly written files: whatever optimising was done to the old ones is gone.
				unset( $metadata[ ATR_Optimizer::DONE_KEY ] );
			}
		}

		// Fetch additional metadata from exif/iptc.
		$image_meta = wp_read_image_metadata( $file );

		if ( $image_meta ) {
			$metadata['image_meta'] = $image_meta;
		}

		return (array) apply_filters( 'wp_generate_attachment_metadata', $metadata, $attachment_id, 'update' );
	}

	/**
	 * The image the sub sizes are cut from.
	 *
	 * Core builds them from the untouched original in wp_create_image_subsizes(). When
	 * an upload was scaled down, the attached file is the "-scaled" copy, and resizing
	 * that writes a second set of files named after it instead of replacing the ones
	 * the site is already using.
	 */
	public static function source_file( int $attachment_id, string $file ): string {
		if ( ! function_exists( 'wp_get_original_image_path' ) ) {
			return $file;
		}

		$original = wp_get_original_image_path( $attachment_id );

		return $original && file_exists( $original ) ? $original : $file;
	}

	/**
	 * The resizing functions live in wp-admin, which a REST request does not load.
	 */
	public static function load_image_api(): void {
		if ( function_exists( 'file_is_displayable_image' ) && function_exists( 'image_make_intermediate_size' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Give the resize as much time and memory as the host allows.
	 */
	private static function raise_limits(): void {
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		wp_raise_memory_limit( 'image' );
	}
}
