<?php
/**
 * Finding, and removing, the resized files nothing points at any more.
 *
 * Change a size from 300x300 to 400x400 and WordPress writes the new file and
 * forgets the old one, which stays on disk for good. On a library of any age
 * that is thousands of files nothing will ever serve.
 *
 * Everything here works one attachment at a time and only ever looks at files
 * named after that attachment. A file is a candidate only when the attachment's
 * own metadata does not mention it and it is not an attachment in its own right.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Cleanup {

	/**
	 * Files named after this attachment that its metadata does not account for.
	 *
	 * @param int $attachment_id Attachment to look at.
	 * @return array{files:array<int,array{file:string,bytes:int}>,bytes:int}
	 */
	public static function orphans( int $attachment_id ): array {
		$empty = array(
			'files' => array(),
			'bytes' => 0,
		);

		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return $empty;
		}

		$directory = trailingslashit( dirname( $file ) );
		$keep      = self::referenced_files( $attachment_id, $file );
		$attached  = self::attached_files_in( $directory );

		$found = array();
		$bytes = 0;

		foreach ( self::candidates( $file ) as $candidate ) {
			$name = wp_basename( $candidate );

			if ( isset( $keep[ $name ] ) ) {
				continue;
			}

			if ( isset( $attached[ $name ] ) ) {
				// Somebody uploaded a file with this name; it is not a leftover.
				continue;
			}

			$size = (int) filesize( $candidate );

			$found[] = array(
				'file'  => $name,
				'bytes' => $size,
			);

			$bytes += $size;
		}

		return array(
			'files' => $found,
			'bytes' => $bytes,
		);
	}

	/**
	 * Delete files this attachment has no use for.
	 *
	 * The list is checked again here rather than taken on trust: whatever the
	 * caller asks for, only a file that is still an orphan is removed.
	 *
	 * @param int      $attachment_id Attachment the files belong to.
	 * @param string[] $files         File names, as returned by orphans().
	 * @return array{deleted:int,bytes:int}
	 */
	public static function delete( int $attachment_id, array $files ): array {
		$orphans = self::orphans( $attachment_id );
		$allowed = array();

		foreach ( $orphans['files'] as $orphan ) {
			$allowed[ $orphan['file'] ] = $orphan['bytes'];
		}

		$file = get_attached_file( $attachment_id );

		if ( ! $file ) {
			return array(
				'deleted' => 0,
				'bytes'   => 0,
			);
		}

		$directory = trailingslashit( dirname( $file ) );
		$deleted   = 0;
		$bytes     = 0;

		foreach ( $files as $name ) {
			$name = wp_basename( (string) $name );

			if ( ! isset( $allowed[ $name ] ) ) {
				continue;
			}

			$path = $directory . $name;

			if ( ! file_exists( $path ) ) {
				continue;
			}

			wp_delete_file( $path );

			if ( ! file_exists( $path ) ) {
				$deleted++;
				$bytes += $allowed[ $name ];
			}
		}

		return array(
			'deleted' => $deleted,
			'bytes'   => $bytes,
		);
	}

	/**
	 * Every file name this attachment legitimately owns.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $file          Attached file path.
	 * @return array<string,true> Keyed by file name.
	 */
	private static function referenced_files( int $attachment_id, string $file ): array {
		$names    = array( wp_basename( $file ) );
		$metadata = wp_get_attachment_metadata( $attachment_id, true );

		if ( is_array( $metadata ) ) {
			if ( ! empty( $metadata['original_image'] ) ) {
				$names[] = wp_basename( (string) $metadata['original_image'] );
			}

			foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$names[] = wp_basename( (string) $size['file'] );
				}
			}
		}

		// The AVIF or WebP copy of a file we keep is a file we keep.
		$copies = array();

		foreach ( $names as $name ) {
			foreach ( ATR_Copies::suffixes() as $suffix ) {
				$copies[] = $name . $suffix;
			}
		}

		return array_fill_keys( array_merge( $names, $copies ), true );
	}

	/**
	 * Files on disk that look like a resized copy of this one.
	 *
	 * @param string $file Attached file path.
	 * @return string[] Absolute paths.
	 */
	private static function candidates( string $file ): array {
		$directory = trailingslashit( dirname( $file ) );
		$extension = pathinfo( $file, PATHINFO_EXTENSION );
		$base      = wp_basename( $file, ".{$extension}" );

		/* WordPress scales an original down to "name-scaled.ext" and cuts sizes as
		   "name-800x600.ext", sometimes with a "-1" when a name was taken. Our AVIF and
		   WebP copies sit beside any of those. */
		$suffixes = array_map(
			static function ( $suffix ) {
				return preg_quote( $suffix, '#' );
			},
			ATR_Copies::suffixes()
		);

		$pattern = sprintf(
			'#^%s-(\d+x\d+|scaled)(-\d+)?\.%s(%s)?$#i',
			preg_quote( $base, '#' ),
			preg_quote( $extension, '#' ),
			implode( '|', $suffixes )
		);

		$found = array();
		$items = @scandir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		foreach ( (array) $items as $item ) {
			if ( '.' === $item || '..' === $item || ! preg_match( $pattern, $item ) ) {
				continue;
			}

			$path = $directory . $item;

			if ( is_file( $path ) ) {
				$found[] = $path;
			}
		}

		return $found;
	}

	/**
	 * The files in one uploads folder that are attachments in their own right.
	 *
	 * Read once per folder per request: a scan walks a folder's attachments one
	 * after another, and they all need the same answer.
	 *
	 * @param string $directory Absolute path, with a trailing slash.
	 * @return array<string,true> Keyed by file name.
	 */
	private static function attached_files_in( string $directory ): array {
		static $cache = array();

		if ( isset( $cache[ $directory ] ) ) {
			return $cache[ $directory ];
		}

		global $wpdb;

		$uploads  = wp_get_upload_dir();
		$relative = ltrim( str_replace( trailingslashit( $uploads['basedir'] ), '', $directory ), '/' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
			$wpdb->esc_like( $relative ) . '%'
		) );

		$names = array();

		foreach ( (array) $rows as $row ) {
			$names[ wp_basename( (string) $row ) ] = true;
		}

		$cache[ $directory ] = $names;

		return $names;
	}
}
