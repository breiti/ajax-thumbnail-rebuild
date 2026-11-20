<?php
/**
 * Which images weigh the most, among those optimising would still help.
 *
 * A heavy library is rarely worth optimising all at once - it takes hours, and
 * where a TinyPNG or ShortPixel key is set up it spends a monthly quota. The
 * heaviest images first gets the most back for the least.
 *
 * What an image weighs here is what the optimiser would work on: the files
 * ATR_Optimizer::paths() lists, and nothing it would leave alone. The WebP and
 * AVIF copies are not counted - optimising does not make them any smaller - and
 * an image the optimiser would turn away, as already done or as a format it
 * does not handle, is not listed at all unless asked for. The list and the
 * optimiser ask the same questions of the same code, so the top of the list is
 * always something the optimiser will actually work on.
 *
 * Measured from the disk every time rather than kept in an index: an index
 * would have to be kept true by every upload, rebuild, replace, cleanup and
 * size cut on demand, and the first of those to forget would make the ranking
 * lie without anyone noticing.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Largest {

	/** Images weighed at a time when going through the whole library. */
	const CHUNK = 200;

	/**
	 * Weigh these images.
	 *
	 * Left out: anything that is not an attachment, a format the optimiser does
	 * not handle, an image whose file is missing, and - unless $all - one the
	 * optimiser would turn away as already done. The first three are decided
	 * from the database alone, before a single file is measured, and so is the
	 * last: on a library that is mostly optimised already, a scan is quick.
	 *
	 * @param int[] $ids Attachment ids.
	 * @param bool  $all Include images that are already optimised.
	 * @return array<int,array{id:int,file:string,bytes:int,files:int,done:bool}> In the order of $ids.
	 */
	public static function weigh( array $ids, bool $all = false ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		// One query for the posts and one for their meta, not two per image.
		_prime_post_caches( $ids, false, true );

		$rows = array();

		foreach ( $ids as $id ) {
			$row = self::weigh_one( $id, $all );

			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Heaviest first; of two that weigh the same, the older upload first, so the
	 * order is the same every time.
	 *
	 * @param array $rows   Rows from weigh().
	 * @param int   $number Keep this many. 0 keeps them all.
	 * @return array
	 */
	public static function rank( array $rows, int $number = 0 ): array {
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( $b['bytes'], $a['id'] ) <=> array( $a['bytes'], $b['id'] );
			}
		);

		return $number > 0 ? array_slice( $rows, 0, $number ) : $rows;
	}

	/**
	 * The heaviest images across the library, or across part of it.
	 *
	 * @param array $filters What ATR_Attachments::query() takes: only_featured, filename, include.
	 * @param bool  $all     Include images that are already optimised.
	 * @param int   $number  Keep this many. 0 keeps them all.
	 * @return array Ranked rows.
	 */
	public static function find( array $filters = array(), bool $all = false, int $number = 0 ): array {
		$ids  = array_map( 'intval', wp_list_pluck( ATR_Attachments::query( $filters ), 'id' ) );
		$rows = array();

		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$rows = array_merge( $rows, self::weigh( $chunk, $all ) );

			/* Every post and its meta stays in the runtime cache once read, which on a
			   library of tens of thousands is the memory limit long before the end. A
			   row is all that is needed from each chunk. */
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}

		return self::rank( $rows, $number );
	}

	/**
	 * @param int  $id  Attachment id.
	 * @param bool $all Include it when it is already optimised.
	 * @return array|null Null when it is not listed.
	 */
	private static function weigh_one( int $id, bool $all ): ?array {
		$post = get_post( $id );

		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type || ! ATR_Optimizer::optimizes( (string) $post->post_mime_type ) ) {
			return null;
		}

		// An old import can leave anything here; the optimiser reads it the same way.
		$stored   = wp_get_attachment_metadata( $id, true );
		$metadata = is_array( $stored ) ? $stored : array();
		$done     = ! ATR_Optimizer::needs_optimizing( $metadata, (string) $post->post_mime_type );

		if ( $done && ! $all ) {
			return null;
		}

		$file = get_attached_file( $id );

		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}

		$bytes = 0;
		$files = 0;

		foreach ( ATR_Optimizer::paths( $file, $metadata ) as $path ) {
			if ( file_exists( $path ) ) {
				$bytes += (int) filesize( $path );
				$files++;
			}
		}

		return array(
			'id'    => $id,
			'file'  => wp_basename( $file ),
			'bytes' => $bytes,
			'files' => $files,
			'done'  => $done,
		);
	}
}
