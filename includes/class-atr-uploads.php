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

		/* After core has cut the sub sizes from the original, and before the
		   optimiser at 15, so what that optimises is the file we leave behind
		   rather than one we are about to write over. */
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'enforce_limit_after_generate' ), 12, 2 );
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

	/**
	 * Bring every file of an upload down to the limit, once the sizes are cut.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment id.
	 * @return array
	 */
	public function enforce_limit_after_generate( $metadata, $attachment_id ) {
		if ( ! is_array( $metadata ) ) {
			return $metadata;
		}

		return self::enforce_limit( (int) $attachment_id, $metadata );
	}

	/**
	 * No file of this attachment may be longer than the limit on its long edge.
	 *
	 * The sub sizes are cut from the original, so this runs after they are made
	 * and never before. What is left afterwards is an original at the limit -
	 * the full resolution it arrived at is gone, which is what the setting says
	 * it does.
	 *
	 * On an image already in the library the "-scaled" copy was written to the
	 * old limit and is now larger than the original beside it, so it comes down
	 * too. It keeps its name: the URL is in the content of pages already.
	 *
	 * @param int   $attachment_id Attachment to work on.
	 * @param array $metadata      Metadata to update.
	 * @return array Metadata, with the served file's dimensions kept honest.
	 */
	public static function enforce_limit( int $attachment_id, array $metadata ): array {
		if ( ! ATR_Settings::get( 'shrink_original' ) ) {
			return $metadata;
		}

		$limit = (int) ATR_Settings::get( 'max_upload_dimension', self::CORE_THRESHOLD );

		if ( $limit <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
			return $metadata;
		}

		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return $metadata;
		}

		$directory = trailingslashit( dirname( $file ) );
		$original  = empty( $metadata['original_image'] )
			? $file
			: $directory . wp_basename( (string) $metadata['original_image'] );

		$written = self::shrink_to( $original, $limit );

		/* The served copy, when it is a file of its own. On a fresh upload core
		   wrote it to this same limit already and there is nothing to do. */
		if ( $original !== $file ) {
			$written = self::shrink_to( $file, $limit ) || $written;
		}

		if ( ! $written ) {
			return $metadata;
		}

		$imagesize = wp_getimagesize( $file );

		if ( $imagesize ) {
			$metadata['width']  = (int) $imagesize[0];
			$metadata['height'] = (int) $imagesize[1];
		}

		if ( function_exists( 'wp_filesize' ) ) {
			$metadata['filesize'] = wp_filesize( $file );
		}

		// Freshly written files: whatever optimising was done to the old ones is gone.
		unset( $metadata[ ATR_Optimizer::DONE_KEY ] );

		return $metadata;
	}

	/**
	 * Scale one file down to the limit, in place and under its own name.
	 *
	 * Written beside the file and moved over it only once it is there, so a
	 * failed resize cannot damage what the site is already serving.
	 *
	 * @param string $path  Absolute path.
	 * @param int    $limit Longest edge allowed.
	 * @return bool Whether the file was replaced.
	 */
	private static function shrink_to( string $path, int $limit ): bool {
		if ( ! file_exists( $path ) || ! is_writable( $path ) ) {
			return false;
		}

		$imagesize = wp_getimagesize( $path );

		if ( ! $imagesize || ( (int) $imagesize[0] <= $limit && (int) $imagesize[1] <= $limit ) ) {
			return false;
		}

		$editor = wp_get_image_editor( $path );

		if ( is_wp_error( $editor ) ) {
			return false;
		}

		if ( is_wp_error( $editor->resize( $limit, $limit ) ) ) {
			return false;
		}

		$mime  = (string) wp_get_image_mime( $path );
		$saved = $editor->save( self::temporary_path( $path ), $mime ? $mime : null );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			return false;
		}

		if ( ! rename( $saved['path'], $path ) ) {
			wp_delete_file( $saved['path'] );

			return false;
		}

		clearstatcache( true, $path );

		return true;
	}

	/**
	 * Somewhere to write the smaller file while it is being made.
	 *
	 * @param string $path File it is made from.
	 * @return string
	 */
	private static function temporary_path( string $path ): string {
		$extension = pathinfo( $path, PATHINFO_EXTENSION );

		return preg_replace( '/\.[^.]+$/', '', $path ) . '-atr-shrinking' . ( $extension ? '.' . $extension : '' );
	}
}
