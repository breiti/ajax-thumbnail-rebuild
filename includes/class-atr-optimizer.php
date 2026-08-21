<?php
/**
 * Squeezing bytes out of files that are already the right size.
 *
 * Optimising is not resizing: every file keeps its dimensions and its name, and
 * a file is only replaced when the new one is genuinely smaller. The untouched
 * original kept beside a scaled upload is left alone - it is the archive copy.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Optimizer {

	/** Formats worth re-encoding. */
	const MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/** Anything below this is not worth the write. */
	const MIN_SAVING_BYTES = 512;

	/** Metadata key recording the quality an attachment was last optimised at. */
	const DONE_KEY = 'atr_optimized';

	public function register(): void {
		/* Before the WebP copies at 20, so those are made from the optimised files. */
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'optimize_after_generate' ), 15, 2 );
	}

	public static function is_enabled(): bool {
		return (bool) apply_filters( 'ajax_thumbnail_rebuild_optimize_enabled', (bool) ATR_Settings::get( 'optimize_enabled' ) );
	}

	/**
	 * Optimise on upload and after a rebuild, when the setting says so.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment id.
	 * @return array
	 */
	public function optimize_after_generate( $metadata, $attachment_id ) {
		if ( ! is_array( $metadata ) || ! self::is_enabled() ) {
			return $metadata;
		}

		$result = self::optimize_attachment( (int) $attachment_id, $metadata );

		return $result['metadata'];
	}

	/**
	 * Optimise every file of one attachment.
	 *
	 * @param int        $attachment_id Attachment id.
	 * @param array|null $metadata      Metadata to work from, read from the attachment when null.
	 * @return array{metadata:array,saved:int,files:int,message:string}
	 */
	public static function optimize_attachment( int $attachment_id, ?array $metadata = null ): array {
		if ( null === $metadata ) {
			$stored   = wp_get_attachment_metadata( $attachment_id, true );
			$metadata = is_array( $stored ) ? $stored : array();
		}

		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return array(
				'metadata' => $metadata,
				'saved'    => 0,
				'files'    => 0,
				'message'  => __( 'The file is missing from the uploads folder.', 'ajax-thumbnail-rebuild' ),
			);
		}

		$quality = (int) ATR_Settings::get( 'optimize_quality', ATR_Uploads::CORE_QUALITY );

		/* Re-encoding is lossy, so running it again over files that were already
		   encoded at this quality would only degrade them for a few bytes. A rebuild
		   clears the mark, because it writes the files afresh. */
		if ( (int) ( $metadata[ self::DONE_KEY ] ?? 0 ) >= $quality && $quality > 0 ) {
			return array(
				'metadata' => $metadata,
				'saved'    => 0,
				'files'    => 0,
				'message'  => '',
			);
		}

		ATR_Regenerator::load_image_api();

		$directory = trailingslashit( dirname( $file ) );
		$saved     = 0;
		$files     = 0;

		$paths = array( $file );

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$paths[] = $directory . $size['file'];
			}
		}

		foreach ( array_unique( $paths ) as $path ) {
			$gain = self::optimize_file( $path );

			if ( $gain > 0 ) {
				$saved += $gain;
				$files++;
			}
		}

		if ( $saved > 0 ) {
			// The stored sizes carry their own byte counts; keep them honest.
			$metadata = self::refresh_filesizes( $metadata, $file );
		}

		$metadata[ self::DONE_KEY ] = $quality;

		return array(
			'metadata' => $metadata,
			'saved'    => $saved,
			'files'    => $files,
			'message'  => '',
		);
	}

	/**
	 * Re-encode one file, keeping the result only when it is smaller.
	 *
	 * @param string $path Absolute path.
	 * @return int Bytes saved, 0 when the file was left as it was.
	 */
	public static function optimize_file( string $path ): int {
		if ( ! file_exists( $path ) || ! is_writable( $path ) ) {
			return 0;
		}

		$mime = wp_get_image_mime( $path );

		if ( ! in_array( $mime, self::MIMES, true ) ) {
			return 0;
		}

		$before = (int) filesize( $path );

		if ( $before < self::MIN_SAVING_BYTES ) {
			return 0;
		}

		$editor = wp_get_image_editor( $path );

		if ( is_wp_error( $editor ) ) {
			return 0;
		}

		$editor->set_quality( (int) ATR_Settings::get( 'optimize_quality', ATR_Uploads::CORE_QUALITY ) );

		/* Written beside the file and moved over it only if it wins, so a failed or
		   fatter encode can never damage what is already there. The name keeps the
		   extension: the editor rewrites it to match the format anyway. */
		$temporary = trailingslashit( dirname( $path ) ) . 'atr-optimizing-' . wp_basename( $path );
		$saved     = $editor->save( $temporary, $mime );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			return 0;
		}

		$after = (int) filesize( $saved['path'] );
		$gain  = $before - $after;

		if ( $gain < self::MIN_SAVING_BYTES || ! self::same_dimensions( $path, $saved['path'] ) ) {
			wp_delete_file( $saved['path'] );

			return 0;
		}

		if ( ! @rename( $saved['path'], $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_delete_file( $saved['path'] );

			return 0;
		}

		return $gain;
	}

	/**
	 * Optimising must not change what the file looks like on the page.
	 */
	private static function same_dimensions( string $original, string $candidate ): bool {
		$a = wp_getimagesize( $original );
		$b = wp_getimagesize( $candidate );

		return $a && $b && $a[0] === $b[0] && $a[1] === $b[1];
	}

	/**
	 * Update the byte counts stored in the metadata after files have shrunk.
	 *
	 * @param array  $metadata Attachment metadata.
	 * @param string $file     Full size path.
	 * @return array
	 */
	private static function refresh_filesizes( array $metadata, string $file ): array {
		if ( function_exists( 'wp_filesize' ) && file_exists( $file ) ) {
			$metadata['filesize'] = wp_filesize( $file );
		}

		$directory = trailingslashit( dirname( $file ) );

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $name => $size ) {
			if ( empty( $size['file'] ) || ! isset( $size['filesize'] ) ) {
				continue;
			}

			$path = $directory . $size['file'];

			if ( file_exists( $path ) ) {
				$metadata['sizes'][ $name ]['filesize'] = wp_filesize( $path );
			}
		}

		return $metadata;
	}
}
