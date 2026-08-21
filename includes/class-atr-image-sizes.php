<?php
/**
 * The image sizes registered on this site.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Image_Sizes {

	/**
	 * Every registered intermediate size, with dimensions and crop setting.
	 *
	 * @param array $metadata      Attachment metadata, when generating for one attachment.
	 * @param int   $attachment_id Attachment being generated for, 0 when only listing.
	 * @return array<string,array> Keyed by size name.
	 */
	public static function all( array $metadata = array(), int $attachment_id = 0 ): array {
		global $_wp_additional_image_sizes;

		$sizes = array();

		foreach ( get_intermediate_image_sizes() as $name ) {
			/* Theme and plugin sizes live in $_wp_additional_image_sizes; the three core
			   ones are stored as options. */
			$registered = isset( $_wp_additional_image_sizes[ $name ] ) ? $_wp_additional_image_sizes[ $name ] : array();

			$crop = isset( $registered['crop'] ) ? $registered['crop'] : get_option( "{$name}_crop" );

			$sizes[ $name ] = array(
				'name'   => $name,
				'width'  => (int) ( $registered['width'] ?? get_option( "{$name}_size_w" ) ),
				'height' => (int) ( $registered['height'] ?? get_option( "{$name}_size_h" ) ),
				'crop'   => is_array( $crop ) ? $crop : (bool) $crop,
			);
		}

		/* Core passes the metadata and the attachment id along with the sizes. Plugins that
		   register their sizes through this filter rather than through add_image_size() read
		   those arguments, and used to be skipped because we called the filter with one. */
		$sizes = self::apply_registered_filter( $sizes, $metadata, $attachment_id );

		return self::normalise( $sizes );
	}

	/**
	 * Run the sizes through the filter every other plugin registers on.
	 *
	 * On-demand generation answers that same filter with an empty list, to stop
	 * WordPress cutting every size on upload. That answer is for core, not for us:
	 * this class is what the plugin's own screens and its rebuild read, and they
	 * are meant to see every registered size.
	 *
	 * @param array $sizes         Sizes to filter.
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment id.
	 * @return mixed
	 */
	private static function apply_registered_filter( array $sizes, array $metadata, int $attachment_id ) {
		$callback = array( 'ATR_On_Demand', 'strip_sizes' );
		$removed  = remove_filter( 'intermediate_image_sizes_advanced', $callback, ATR_On_Demand::STRIP_PRIORITY );

		$sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes, $metadata, $attachment_id );

		if ( $removed ) {
			add_filter( 'intermediate_image_sizes_advanced', $callback, ATR_On_Demand::STRIP_PRIORITY, 3 );
		}

		return $sizes;
	}

	/**
	 * A filter may hand back entries in a different shape; make callers' lives simple.
	 *
	 * @param mixed $sizes Whatever came back from the filter.
	 * @return array<string,array>
	 */
	private static function normalise( $sizes ): array {
		$normalised = array();

		foreach ( (array) $sizes as $name => $size ) {
			if ( ! is_array( $size ) ) {
				continue;
			}

			$crop = $size['crop'] ?? false;

			$normalised[ $name ] = array(
				'name'   => (string) ( $size['name'] ?? $name ),
				'width'  => (int) ( $size['width'] ?? 0 ),
				'height' => (int) ( $size['height'] ?? 0 ),
				'crop'   => is_array( $crop ) ? array_values( $crop ) : (bool) $crop,
			);
		}

		return $normalised;
	}

	/**
	 * Human readable crop setting, e.g. "cropped" or "left, top".
	 *
	 * @param array $size One entry from all().
	 * @return string Empty when the size is not cropped.
	 */
	public static function crop_label( array $size ): string {
		if ( empty( $size['crop'] ) ) {
			return '';
		}

		if ( is_array( $size['crop'] ) ) {
			return implode( ', ', array_map( 'strval', $size['crop'] ) );
		}

		return __( 'cropped', 'ajax-thumbnail-rebuild' );
	}

	/**
	 * Dimensions as shown in the UI, e.g. "300 × 300" or "1200 × any".
	 *
	 * @param array $size One entry from all().
	 * @return string
	 */
	public static function dimensions_label( array $size ): string {
		$any = __( 'any', 'ajax-thumbnail-rebuild' );

		return sprintf(
			'%s × %s',
			$size['width'] > 0 ? (string) $size['width'] : $any,
			$size['height'] > 0 ? (string) $size['height'] : $any
		);
	}
}
