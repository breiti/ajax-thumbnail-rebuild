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

	/** Anything below this is not worth the write, on a file of any size. */
	const MIN_SAVING_BYTES = 512;

	/** The floor for a small file, where 512 bytes would be most of it. */
	const MIN_SAVING_FLOOR = 64;

	/** A saving worth taking on a small file, as a share of what it weighs. */
	const MIN_SAVING_SHARE = 0.05;

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
	 * @return array{metadata:array,saved:int,files:int,message:string,reason:string}
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
				'reason'   => 'missing',
			);
		}

		$quality = (int) ATR_Settings::get( 'optimize_quality', ATR_Uploads::CORE_QUALITY );

		/* Re-encoding is lossy, so running it again over files that were already
		   encoded at this quality would only degrade them for a few bytes. A rebuild
		   clears the mark, because it writes the files afresh - and so does
		   installing an optimiser the last pass did not have. */
		if ( self::already_done( $metadata, $quality ) ) {
			return array(
				'metadata' => $metadata,
				'saved'    => 0,
				'files'    => 0,
				'message'  => '',
				'reason'   => 'already',
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

		/* The untouched original is the archive copy, so it is left alone unless a
		   site asks for it - and then only the lossless programs touch it: never a
		   re-encode, never a lossy pass, never sent to a service. */
		$archive = '';

		if ( ATR_Settings::get( 'optimize_original' ) && ! empty( $metadata['original_image'] ) ) {
			$archive = $directory . $metadata['original_image'];

			if ( file_exists( $archive ) ) {
				$paths[] = $archive;
			}
		}

		$mimes = array();

		foreach ( array_unique( $paths ) as $path ) {
			$mime = file_exists( $path ) ? wp_get_image_mime( $path ) : '';

			if ( $mime ) {
				$mimes[ $mime ] = true;
			}

			$gain = self::optimize_file( $path, $path === $archive );

			if ( $gain > 0 ) {
				$saved += $gain;
				$files++;
			}
		}

		if ( $saved > 0 ) {
			// The stored sizes carry their own byte counts; keep them honest.
			$metadata = self::refresh_filesizes( $metadata, $file );
		}

		$metadata[ self::DONE_KEY ] = array(
			'quality' => $quality,
			'tools'   => ATR_Tools::signature(),
			'lossy'   => (bool) ATR_Settings::get( 'optimize_lossy' ),
		);

		return array(
			'metadata' => $metadata,
			'saved'    => $saved,
			'files'    => $files,
			'message'  => '',
			'reason'   => self::reason_for_nothing( $saved, $mimes ),
		);
	}

	/**
	 * Whether this attachment has already been through exactly this treatment.
	 *
	 * @param array $metadata Attachment metadata.
	 * @param int   $quality  Quality it would be optimised at now.
	 */
	private static function already_done( array $metadata, int $quality ): bool {
		$mark = $metadata[ self::DONE_KEY ] ?? null;

		if ( null === $mark || $quality <= 0 ) {
			return false;
		}

		// Before 2.0 the mark was the quality on its own, with no programs involved.
		$done_quality = is_array( $mark ) ? (int) ( $mark['quality'] ?? 0 ) : (int) $mark;
		$done_tools   = is_array( $mark ) ? (string) ( $mark['tools'] ?? '' ) : '';
		$done_lossy   = is_array( $mark ) ? ! empty( $mark['lossy'] ) : false;

		/* Turning lossy compression on is a new offer to make, so a pass that was
		   made without it does not count as this pass having been made. */
		if ( ATR_Settings::get( 'optimize_lossy' ) && ! $done_lossy ) {
			return false;
		}

		return $done_quality >= $quality && $done_tools === ATR_Tools::signature();
	}

	/**
	 * Why an attachment came out no smaller, so the screen can say something
	 * more useful than "0 B".
	 *
	 * @param int   $saved Bytes saved.
	 * @param array $mimes Mime types seen, keyed by type.
	 * @return string Empty when there is nothing to explain.
	 */
	private static function reason_for_nothing( $saved, array $mimes ) {
		if ( $saved > 0 ) {
			return '';
		}

		/* Without an optimiser installed all this can do is re-encode, which wins
		   nothing on files WordPress itself has just written at this quality. That
		   is the answer worth giving, before anything about the format. */
		if ( ! ATR_Tools::available() ) {
			return 'no_tools';
		}

		if ( $mimes && array( 'image/png' => true ) === $mimes ) {
			$available = ATR_Tools::available();

			/* A PNG that optipng cannot shrink any further is not a dead end: the
			   thing that does shrink it is pngquant, which the site has turned off
			   because it is lossy. Worth pointing at rather than sending somebody
			   away with "nothing to do". */
			if ( isset( $available['pngquant'] ) && ! ATR_Settings::get( 'optimize_lossy' ) ) {
				return 'png_lossy_off';
			}

			return 'png';
		}

		return 'no_gain';
	}

	/**
	 * Re-encode one file, keeping the result only when it is smaller.
	 *
	 * @param string $path Absolute path.
	 * @return int Bytes saved, 0 when the file was left as it was.
	 */
	public static function optimize_file( string $path, bool $lossless_only = false ): int {
		if ( ! file_exists( $path ) || ! is_writable( $path ) ) {
			return 0;
		}

		$mime = wp_get_image_mime( $path );

		if ( ! in_array( $mime, self::MIMES, true ) ) {
			return 0;
		}

		$before = (int) filesize( $path );

		if ( $before < self::MIN_SAVING_FLOOR ) {
			return 0;
		}

		/* A service, when a site has set one up: it does the same job as the local
		   programs, only better, and it is the answer on a host that has none. */
		if ( ! $lossless_only ) {
			$gain = self::run_service( $path, $mime, $before );

			if ( $gain > 0 ) {
				return $gain;
			}
		}

		/* Then whatever is installed here: it re-packs the file rather than
		   re-encoding it, which is both a real saving and not lossy. */
		$gain = self::run_tools( $path, $mime, $before, $lossless_only );

		if ( $gain > 0 || $lossless_only ) {
			return $gain;
		}

		/* And only if none of that helped, writing the image out again - which wins
		   little on a file WordPress has already written at this quality. */
		return self::reencode( $path, $mime, $before );
	}

	/**
	 * Hand the file to whatever optimiser the server has for its format.
	 *
	 * @param string $path   Absolute path.
	 * @param string $mime   Its mime type.
	 * @param int    $before What it weighs now.
	 * @return int Bytes saved.
	 */
	private static function run_tools( string $path, string $mime, int $before, bool $lossless_only = false ): int {
		$lossy    = ! $lossless_only && (bool) ATR_Settings::get( 'optimize_lossy' );
		$programs = ATR_Tools::programs_for( $mime, $lossy );

		if ( ! $programs ) {
			return 0;
		}

		/* Each program works on what the one before it produced, on a copy: two of
		   them in a row do better than either alone, and nothing touches the file
		   itself until the whole chain has finished and won. */
		$work = self::temporary_path( $path );

		if ( ! copy( $path, $work ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return 0;
		}

		foreach ( $programs as $program ) {
			/* Making a file progressive helps a browser draw it sooner, which is
			   worth nothing on the archive copy nobody ever downloads - and a
			   progressive JPEG is marginally slower to decode when a size is cut
			   from it later. */
			if ( $lossless_only ) {
				$program['args'] = array_values( array_diff( $program['args'], array( '--all-progressive', '-progressive' ) ) );
			}

			$candidate = self::temporary_path( $path, $program['name'] );

			if ( ! ATR_Tools::run( $program, $work, $candidate ) ) {
				self::discard( $candidate );

				continue;
			}

			clearstatcache( true, $candidate );
			clearstatcache( true, $work );

			// A program that made it bigger, or changed it into something else, is
			// simply not used; the chain carries on with what it had.
			if ( filesize( $candidate ) < filesize( $work )
				&& wp_get_image_mime( $candidate ) === $mime
				&& self::same_dimensions( $work, $candidate ) ) {
				self::discard( $work );

				$work = $candidate;
			} else {
				self::discard( $candidate );
			}
		}

		return self::keep_if_better( $path, $work, $before, $mime );
	}

	/**
	 * Hand the file to the optimisation service, if there is one.
	 *
	 * @param string $path   Absolute path.
	 * @param string $mime   Its mime type.
	 * @param int    $before What it weighs now.
	 * @return int Bytes saved.
	 */
	private static function run_service( string $path, string $mime, int $before ): int {
		if ( ! ATR_Service::handles( $mime ) ) {
			return 0;
		}

		$candidate = self::temporary_path( $path, 'service' );
		$sent      = ATR_Service::optimize( $path, $mime, $candidate );

		if ( is_wp_error( $sent ) ) {
			self::discard( $candidate );
			self::remember_service_error( $sent );

			return 0;
		}

		return self::keep_if_better( $path, $candidate, $before, $mime );
	}

	/**
	 * Keep the last thing a service complained about, so the screen can show it
	 * rather than leaving somebody guessing why nothing happened.
	 *
	 * @param WP_Error $error What went wrong.
	 */
	private static function remember_service_error( WP_Error $error ): void {
		set_transient( 'atr_service_error', $error->get_error_message(), HOUR_IN_SECONDS );
	}

	/**
	 * Write the image out again with PHP's own library.
	 *
	 * @param string $path   Absolute path.
	 * @param string $mime   Its mime type.
	 * @param int    $before What it weighs now.
	 * @return int Bytes saved.
	 */
	private static function reencode( string $path, string $mime, int $before ): int {
		$editor = wp_get_image_editor( $path );

		if ( is_wp_error( $editor ) ) {
			return 0;
		}

		$editor->set_quality( (int) ATR_Settings::get( 'optimize_quality', ATR_Uploads::CORE_QUALITY ) );

		/* Written beside the file and moved over it only if it wins, so a failed or
		   fatter encode can never damage what is already there. The name keeps the
		   extension: the editor rewrites it to match the format anyway. */
		$saved = $editor->save( self::temporary_path( $path ), $mime );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			return 0;
		}

		return self::keep_if_better( $path, $saved['path'], $before, $mime );
	}

	/**
	 * Put a candidate in place of the file, if it is worth it.
	 *
	 * @param string $path      The file as it stands.
	 * @param string $candidate What was just written beside it.
	 * @param int    $before    What the file weighed.
	 * @param string $mime      The format it must still be in.
	 * @return int Bytes saved, 0 when the candidate was thrown away.
	 */
	private static function keep_if_better( string $path, string $candidate, int $before, string $mime ): int {
		clearstatcache( true, $candidate );

		$after = (int) filesize( $candidate );
		$gain  = $before - $after;

		if ( $gain < self::worth_writing( $before )
			|| wp_get_image_mime( $candidate ) !== $mime
			|| ! self::same_dimensions( $path, $candidate ) ) {
			self::discard( $candidate );

			return 0;
		}

		if ( ! @rename( $candidate, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::discard( $candidate );

			return 0;
		}

		return $gain;
	}

	/**
	 * How much has to be saved before the file is worth rewriting.
	 *
	 * A flat 512 bytes is right for a photograph and absurd for a 900 byte icon,
	 * where it is most of the file: on small files a share of the size is the
	 * measure that means anything.
	 *
	 * @param int $before What the file weighs.
	 */
	private static function worth_writing( int $before ): int {
		return max( self::MIN_SAVING_FLOOR, (int) min( self::MIN_SAVING_BYTES, $before * self::MIN_SAVING_SHARE ) );
	}

	/**
	 * Where a candidate is written while it is being judged.
	 */
	private static function temporary_path( string $path, string $tag = '' ): string {
		return trailingslashit( dirname( $path ) ) . 'atr-optimizing-' . ( '' === $tag ? '' : $tag . '-' ) . wp_basename( $path );
	}

	/**
	 * @param string $path File to remove, if it is there.
	 */
	private static function discard( string $path ): void {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
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
