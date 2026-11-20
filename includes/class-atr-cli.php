<?php
/**
 * Finding the heaviest images and optimising them, from the command line.
 *
 * Where there is a shell, this is the better place for a big library: no
 * browser tab has to stay open, and no host's time limit on a web request
 * applies. The work itself is the same code the Tools screen runs.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find the images that weigh the most and optimise them first.
 */
class ATR_CLI {

	/**
	 * List the heaviest images that still need optimising.
	 *
	 * An image weighs what the optimiser would work on: the uploaded file and its
	 * sizes, and the untouched original where the settings say to optimise that
	 * too. Images already optimised at the current settings are left out.
	 *
	 * ## OPTIONS
	 *
	 * [--number=<n>]
	 * : How many to list. 0 lists every one.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--all]
	 * : Include images that are already optimised.
	 *
	 * [--featured]
	 * : Only images used as a featured image, WooCommerce product galleries included.
	 *
	 * [--filename=<glob>]
	 * : Only images whose file name matches, e.g. 'banner-*.jpg'.
	 *
	 * [--format=<format>]
	 * : How to print them.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - ids
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # The 20 heaviest images that still need optimising.
	 *     $ wp atr largest
	 *
	 *     # The 50 heaviest product images, optimised or not.
	 *     $ wp atr largest --featured --all --number=50
	 *
	 *     # Optimise the ten heaviest.
	 *     $ wp atr optimize $(wp atr largest --number=10 --format=ids)
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function largest( $args, $assoc_args ): void {
		$all    = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );
		$format = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$rows   = ATR_Largest::find( self::filters( $assoc_args ), $all, absint( $assoc_args['number'] ?? 20 ) );

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $rows, 'id' ) ) );

			return;
		}

		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $rows ) );

			return;
		}

		if ( 'json' === $format ) {
			\WP_CLI\Utils\format_items( 'json', $rows, array( 'id', 'file', 'bytes', 'files', 'done' ) );

			return;
		}

		if ( ! $rows ) {
			WP_CLI::log( $all ? 'No images found.' : 'Every image is already optimised.' );

			return;
		}

		self::print_rows( $rows, $format, $all );
	}

	/**
	 * Optimise images, the heaviest first.
	 *
	 * With ids, those images in the order given. Without, every image that still
	 * needs it, heaviest first - or only the heaviest few with --largest. An image
	 * already optimised at the current settings is skipped, as it is on the Tools
	 * screen.
	 *
	 * Where a TinyPNG or ShortPixel key is set up, images go there first and use
	 * its quota; the first line printed says so, and --dry-run stops there.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment ids to optimise.
	 *
	 * [--largest=<n>]
	 * : Only the n heaviest that still need optimising.
	 *
	 * [--featured]
	 * : Only images used as a featured image, WooCommerce product galleries included.
	 *
	 * [--filename=<glob>]
	 * : Only images whose file name matches, e.g. 'banner-*.jpg'.
	 *
	 * [--dry-run]
	 * : Say what would be optimised, and with what, and stop.
	 *
	 * ## EXAMPLES
	 *
	 *     # The 50 heaviest images.
	 *     $ wp atr optimize --largest=50
	 *
	 *     # Everything that still needs it, heaviest first.
	 *     $ wp atr optimize
	 *
	 *     # Two images, in this order.
	 *     $ wp atr optimize 812 77
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Attachment ids.
	 * @param array $assoc_args Options.
	 */
	public function optimize( $args, $assoc_args ): void {
		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		if ( $args ) {
			$ids  = self::attachment_ids( $args );
			$rows = ATR_Largest::weigh( $ids, true );
		} else {
			$rows = ATR_Largest::find( self::filters( $assoc_args ), false, absint( $assoc_args['largest'] ?? 0 ) );
			$ids  = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		}

		if ( ! $ids ) {
			WP_CLI::success( $args ? 'Nothing to optimise.' : 'Every image is already optimised.' );

			return;
		}

		self::announce( $rows );

		if ( $dry_run ) {
			self::print_rows( $rows, 'table', (bool) $args );

			return;
		}

		self::run( $ids );
	}

	/**
	 * The ids given on the command line that are attachments, in the order given.
	 *
	 * @param array $args Positional arguments.
	 * @return int[]
	 */
	private static function attachment_ids( array $args ): array {
		$ids = array();

		foreach ( $args as $arg ) {
			$id = absint( $arg );

			if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
				WP_CLI::warning( sprintf( 'Skipping %s: not an attachment.', $arg ) );

				continue;
			}

			$ids[] = $id;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * What the optimiser has to work with, and where a service will spend quota.
	 *
	 * A service counts every file it is sent, and an image is its upload plus a
	 * dozen sizes or more, so the warning counts files: twenty images can be
	 * three hundred compressions. It is an upper bound - an image already
	 * optimised is skipped, the untouched original is never sent, and a service
	 * turns some formats away.
	 *
	 * @param array $rows Rows from ATR_Largest for the images about to be optimised.
	 */
	private static function announce( array $rows ): void {
		$programs = array_keys( ATR_Tools::available() );

		WP_CLI::log(
			$programs
				? sprintf( 'Optimisers on this server: %s.', implode( ', ', $programs ) )
				: 'No optimisers on this server - files can only be written out again, which saves little.'
		);

		$service = ATR_Service::chosen();

		if ( '' === $service ) {
			return;
		}

		$waiting = array_filter(
			$rows,
			static function ( array $row ): bool {
				return ! $row['done'];
			}
		);

		WP_CLI::log(
			sprintf(
				'Up to %1$d files from %2$d %3$s will be sent to %4$s, which counts each file against its quota.',
				array_sum( wp_list_pluck( $waiting, 'files' ) ),
				count( $waiting ),
				1 === count( $waiting ) ? 'image' : 'images',
				ATR_Service::services()[ $service ]['label']
			)
		);
	}

	/**
	 * Optimise one image after another, then say what came of it.
	 *
	 * @param int[] $ids Attachment ids, in the order to work on them.
	 */
	private static function run( array $ids ): void {
		$progress  = \WP_CLI\Utils\make_progress_bar( 'Optimising', count( $ids ) );
		$optimised = 0;
		$saved     = 0;
		$skipped   = array(
			'already' => 0,
			'missing' => 0,
			'nothing' => 0,
		);

		foreach ( $ids as $id ) {
			$result = ATR_Optimizer::optimize_and_store( $id );

			if ( $result['saved'] > 0 ) {
				$optimised++;
				$saved += (int) $result['saved'];
			} elseif ( isset( $skipped[ $result['reason'] ] ) ) {
				$skipped[ $result['reason'] ]++;
			} else {
				$skipped['nothing']++;
			}

			$progress->tick();
		}

		$progress->finish();

		$reasons = array_filter(
			array(
				$skipped['already'] ? sprintf( '%d already optimised', $skipped['already'] ) : '',
				$skipped['missing'] ? sprintf( '%d with the file missing', $skipped['missing'] ) : '',
				$skipped['nothing'] ? sprintf( '%d with nothing to gain', $skipped['nothing'] ) : '',
			)
		);

		if ( $reasons ) {
			WP_CLI::log( 'Skipped: ' . implode( ', ', $reasons ) . '.' );
		}

		WP_CLI::success( sprintf( '%1$d of %2$d images optimised, %3$s saved.', $optimised, count( $ids ), size_format( $saved, 1 ) ) );
	}

	/**
	 * @param array  $rows   Rows from ATR_Largest.
	 * @param string $format table or csv.
	 * @param bool   $marked Show whether each is already optimised.
	 */
	private static function print_rows( array $rows, string $format, bool $marked ): void {
		$fields = $marked ? array( 'id', 'file', 'size', 'files', 'optimised' ) : array( 'id', 'file', 'size', 'files' );
		$items  = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'id'        => $row['id'],
				'file'      => $row['file'],
				'size'      => size_format( $row['bytes'], 1 ),
				'files'     => $row['files'],
				'optimised' => $row['done'] ? 'yes' : 'no',
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * The options that narrow the library, as ATR_Attachments::query() takes them.
	 *
	 * @param array $assoc_args Options.
	 * @return array
	 */
	private static function filters( array $assoc_args ): array {
		return array(
			'only_featured' => (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'featured', false ),
			'filename'      => (string) ( $assoc_args['filename'] ?? '' ),
		);
	}
}
