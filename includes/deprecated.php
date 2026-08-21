<?php
/**
 * Function names kept from before 2.0.0, for anything that hooked into them.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ajax_thumbnail_rebuild_get_sizes' ) ) {
	/**
	 * @deprecated 2.0.0 Use ATR_Image_Sizes::all().
	 *
	 * @return array<string,array>
	 */
	function ajax_thumbnail_rebuild_get_sizes() {
		return ATR_Image_Sizes::all();
	}
}

if ( ! function_exists( 'wp_generate_attachment_metadata_custom' ) ) {
	/**
	 * @deprecated 2.0.0 Use ATR_Regenerator::generate_metadata().
	 *
	 * @param int        $attachment_id Attachment id.
	 * @param string     $file          File path.
	 * @param array|null $thumbnails    Size names, or null for all.
	 * @return array
	 */
	function wp_generate_attachment_metadata_custom( $attachment_id, $file, $thumbnails = null ) {
		return ATR_Regenerator::generate_metadata(
			(int) $attachment_id,
			(string) $file,
			is_array( $thumbnails ) ? $thumbnails : null
		);
	}
}
