<?php
/**
 * What happens to an image on its way in.
 *
 * Two things WordPress decides for you and gives no screen for: how hard it
 * compresses the copies it writes, and how large an upload may stay before it
 * is scaled down.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Uploads {

	/** What WordPress compresses at when nothing says otherwise. */
	const CORE_QUALITY = 82;

	/** The long edge core scales an upload down to, when nothing says otherwise. */
	const CORE_THRESHOLD = 2560;

	public function register(): void {
		add_filter( 'wp_editor_set_quality', array( $this, 'filter_quality' ), 10, 2 );
		add_filter( 'big_image_size_threshold', array( $this, 'filter_threshold' ) );
	}

	/**
	 * Compression used for every copy WordPress writes.
	 *
	 * @param int    $quality   Quality core settled on.
	 * @param string $mime_type Format being written.
	 * @return int
	 */
	public function filter_quality( $quality, $mime_type = '' ) {
		if ( in_array( $mime_type, array( 'image/webp', 'image/avif' ), true ) ) {
			// The AVIF and WebP copies have a quality of their own.
			return $quality;
		}

		$setting = (int) ATR_Settings::get( 'upload_quality', self::CORE_QUALITY );

		return $setting > 0 ? min( 100, max( 1, $setting ) ) : (int) $quality;
	}

	/**
	 * The long edge an upload is scaled down to.
	 *
	 * @param int $threshold Pixels, or a falsy value to leave uploads alone.
	 * @return int|false
	 */
	public function filter_threshold( $threshold ) {
		$setting = (int) ATR_Settings::get( 'max_upload_dimension', self::CORE_THRESHOLD );

		if ( 0 === $setting ) {
			// Nothing is scaled: the file stays exactly as it was uploaded.
			return false;
		}

		return $setting > 0 ? $setting : (int) $threshold;
	}
}
