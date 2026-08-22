<?php
/**
 * The image optimisers a server may have, and running them.
 *
 * Re-encoding a file with PHP's own image library is worth almost nothing on
 * files WordPress has just written: it wrote them at this quality already. The
 * programs here are the ones that actually make a JPEG or a PNG smaller -
 * jpegoptim rebuilds the Huffman tables, optipng recompresses the stream - and
 * they do it without touching a pixel.
 *
 * None of them is on every server, so each one is looked for before it is used
 * and the plugin says plainly which are missing.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Tools {

	/** How long a look for the programs is remembered. */
	const CACHE_KEY = 'atr_tools';

	const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Every program this knows how to drive, in the order it would rather use them.
	 *
	 * `args` is the command line, with %in% and %out% standing for the file it
	 * reads and the file it writes; a program that edits in place gets the same
	 * path for both.
	 *
	 * @return array<string,array<string,array>>
	 */
	public static function definitions(): array {
		$definitions = array(
			'image/jpeg' => array(
				'jpegoptim' => array(
					/* Lossless: the pixels are re-packed, not re-encoded. The strip
					   flags name what goes rather than using --strip-all, which would
					   take the colour profile with it and shift the colours. */
					'args'     => array( '--quiet', '--strip-com', '--strip-exif', '--strip-iptc', '--strip-xmp', '--all-progressive', '--stdout', '%in%' ),
					'stdout'   => true,
					'lossless' => true,
				),
				'jpegtran'  => array(
					'args'     => array( '-copy', 'none', '-optimize', '-progressive', '-outfile', '%out%', '%in%' ),
					'lossless' => true,
				),
			),
			'image/png'  => array(
				'optipng'  => array(
					/* -o2 rather than -o7: the last levels cost minutes per image for
					   a percent or two, and this runs inside a web request. */
					'args'     => array( '-quiet', '-o2', '-out', '%out%', '%in%' ),
					'lossless' => true,
				),
				'pngquant' => array(
					// Lossy - fewer colours - so only when a site asks for it.
					'args'     => array( '--quality=65-90', '--force', '--skip-if-larger', '--output', '%out%', '--', '%in%' ),
					'lossless' => false,
				),
			),
			'image/gif'  => array(
				'gifsicle' => array(
					'args'     => array( '-O3', '-o', '%out%', '%in%' ),
					'lossless' => true,
				),
			),
			'image/webp' => array(),
		);

		/**
		 * Filter the programs the plugin will use.
		 *
		 * @param array $definitions Keyed by mime type, then by program name.
		 */
		return (array) apply_filters( 'ajax_thumbnail_rebuild_tools', $definitions );
	}

	/**
	 * Whether the server lets us run anything at all.
	 */
	public static function can_run(): bool {
		if ( ! function_exists( 'exec' ) || ! function_exists( 'escapeshellarg' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( 'exec', $disabled, true );
	}

	/**
	 * The programs that are actually installed, keyed by name.
	 *
	 * @param bool $fresh Look again rather than trusting the cached answer.
	 * @return array<string,string> Name => path.
	 */
	public static function available( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$found = array();

		if ( self::can_run() ) {
			foreach ( self::definitions() as $programs ) {
				foreach ( array_keys( (array) $programs ) as $name ) {
					if ( isset( $found[ $name ] ) ) {
						continue;
					}

					$path = self::locate( $name );

					if ( '' !== $path ) {
						$found[ $name ] = $path;
					}
				}
			}
		}

		set_transient( self::CACHE_KEY, $found, self::CACHE_TTL );

		return $found;
	}

	/**
	 * Where a program is, if it is anywhere.
	 *
	 * @param string $name Program name.
	 * @return string Empty when it is not installed.
	 */
	private static function locate( string $name ): string {
		/**
		 * Filter the path of one program, for a server that keeps them somewhere
		 * unusual or wants to point at a build of its own.
		 *
		 * @param string $path Path, empty to look for it.
		 * @param string $name Program name.
		 */
		$filtered = (string) apply_filters( 'ajax_thumbnail_rebuild_tool_path', '', $name );

		if ( '' !== $filtered ) {
			return is_executable( $filtered ) ? $filtered : '';
		}

		$output = array();
		$status = 1;

		exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null', $output, $status ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.DiscouragedPHPFunctions

		$path = 0 === $status && ! empty( $output[0] ) ? trim( $output[0] ) : '';

		return ( '' !== $path && is_executable( $path ) ) ? $path : '';
	}

	/**
	 * Every installed program that may touch this format, in the order to run
	 * them: each one works on what the one before it produced.
	 *
	 * @param string $mime  Mime type.
	 * @param bool   $lossy Whether the site allows a lossy program.
	 * @return array<int,array{name:string,path:string,args:array,stdout:bool}>
	 */
	public static function programs_for( string $mime, bool $lossy = false ): array {
		$definitions = self::definitions();
		$available   = self::available();
		$programs    = array();

		foreach ( (array) ( $definitions[ $mime ] ?? array() ) as $name => $program ) {
			if ( ! isset( $available[ $name ] ) ) {
				continue;
			}

			if ( empty( $program['lossless'] ) && ! $lossy ) {
				continue;
			}

			$programs[] = array(
				'name'   => $name,
				'path'   => $available[ $name ],
				'args'   => (array) $program['args'],
				'stdout' => ! empty( $program['stdout'] ),
			);
		}

		return $programs;
	}

	/**
	 * Run a program over one file, into a file of its own.
	 *
	 * @param array  $program From for_mime().
	 * @param string $in      File to read.
	 * @param string $out     File to write.
	 * @return bool Whether something was written.
	 */
	public static function run( array $program, string $in, string $out ): bool {
		if ( ! self::can_run() ) {
			return false;
		}

		$command = escapeshellarg( $program['path'] );

		foreach ( $program['args'] as $argument ) {
			if ( '%in%' === $argument ) {
				$command .= ' ' . escapeshellarg( $in );
			} elseif ( '%out%' === $argument ) {
				$command .= ' ' . escapeshellarg( $out );
			} else {
				$command .= ' ' . escapeshellarg( $argument );
			}
		}

		// A program that writes to standard output needs the shell to place it.
		if ( ! empty( $program['stdout'] ) ) {
			$command .= ' > ' . escapeshellarg( $out );
		}

		$output = array();
		$status = 1;

		exec( $command . ' 2>/dev/null', $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		clearstatcache( true, $out );

		return 0 === $status && file_exists( $out ) && filesize( $out ) > 0;
	}

	/**
	 * A short description of what is installed, for the settings screen and for
	 * the mark that records how an attachment was optimised.
	 *
	 * @return string Empty when there is nothing.
	 */
	public static function signature(): string {
		$names = array_keys( self::available() );

		sort( $names );

		return implode( ',', $names );
	}

	/**
	 * Forget what was found, so the next look is a fresh one.
	 */
	public static function forget(): void {
		delete_transient( self::CACHE_KEY );
	}
}
