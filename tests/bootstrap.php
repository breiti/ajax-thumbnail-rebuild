<?php
/**
 * What every test script here needs: checks, a report, settings it can rely on,
 * and images of its own to work on.
 *
 * The tests run against a real site through WP-CLI, never against the images
 * already in its library - optimising those is not something a test may do.
 * Every fixture is made here, attached under uploads/atr-tests/, and deleted
 * again when the script ends.
 *
 *     lando wp eval-file wp-content/plugins/ajax-thumbnail-rebuild/tests/<file>.php
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit( "Run through WP-CLI: lando wp eval-file wp-content/plugins/ajax-thumbnail-rebuild/tests/<file>.php\n" );
}

$GLOBALS['atr_test_checks']      = 0;
$GLOBALS['atr_test_failures']    = array();
$GLOBALS['atr_test_attachments'] = array();
$GLOBALS['atr_test_files']       = array();

/**
 * Record one check and say how it went.
 */
function atr_check( string $what, bool $passed, string $detail = '' ): void {
	$GLOBALS['atr_test_checks']++;

	if ( $passed ) {
		echo "  ok   $what\n";

		return;
	}

	$GLOBALS['atr_test_failures'][] = $what;
	echo "  FAIL $what" . ( '' === $detail ? '' : " - $detail" ) . "\n";
}

/**
 * The last line every script prints, and the exit code a runner can read.
 */
function atr_report(): void {
	$failed = count( $GLOBALS['atr_test_failures'] );

	echo "\n{$GLOBALS['atr_test_checks']} checks, $failed failed\n";

	if ( $failed > 0 ) {
		atr_cleanup();
		exit( 1 );
	}
}

/**
 * Settings the test can rely on, whatever the site has saved.
 *
 * Everything that would act on an upload by itself is off - optimising, the
 * queue, WebP and AVIF copies, sizes left for later - so a fixture arrives as
 * WordPress alone would write it. And there is never a service: a test must not
 * be able to spend a site's TinyPNG or ShortPixel quota.
 *
 * Called again, it replaces what the last call set.
 *
 * @param array $overrides Settings this test needs on top.
 */
function atr_settings( array $overrides ): void {
	static $filter = null;

	if ( null !== $filter ) {
		remove_filter( 'option_' . ATR_Settings::OPTION, $filter, PHP_INT_MAX );
	}

	$pinned = array_merge(
		array(
			'max_upload_dimension' => ATR_Uploads::CORE_THRESHOLD,
			'shrink_original'      => false,
			'optimize_enabled'     => false,
			'optimize_lossy'       => false,
			'optimize_original'    => false,
			'background_enabled'   => false,
			'proxy_enabled'        => false,
			'webp_enabled'         => false,
			'avif_enabled'         => false,
			'on_demand_enabled'    => false,
			'on_demand_sizes'      => array(),
		),
		$overrides,
		array(
			'optimize_service'     => 'none',
			'optimize_service_key' => '',
		)
	);

	$filter = static function ( $stored ) use ( $pinned ) {
		return array_merge( is_array( $stored ) ? $stored : array(), $pinned );
	};

	add_filter( 'option_' . ATR_Settings::OPTION, $filter, PHP_INT_MAX );
}

/**
 * Somewhere to write a fixture before it is attached.
 */
function atr_temp_path( string $extension ): string {
	$path = trailingslashit( get_temp_dir() ) . 'atr-test-' . wp_generate_password( 8, false ) . '.' . $extension;

	$GLOBALS['atr_test_files'][] = $path;

	return $path;
}

/**
 * A JPEG with no two neighbouring blocks alike, so it is big and has room to
 * shrink.
 */
function atr_fixture_jpeg( int $width, int $height ): string {
	$image = imagecreatetruecolor( $width, $height );

	for ( $y = 0; $y < $height; $y += 4 ) {
		for ( $x = 0; $x < $width; $x += 4 ) {
			imagefilledrectangle( $image, $x, $y, $x + 3, $y + 3, imagecolorallocate( $image, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
		}
	}

	$path = atr_temp_path( 'jpg' );
	imagejpeg( $image, $path, 95 );
	imagedestroy( $image );

	return $path;
}

/**
 * A two-frame animated GIF.
 *
 * The frames differ only in one small square, so a GIF that stores both in full
 * is far bigger than it needs to be: gifsicle -O3 crops the second frame down to
 * the square. With $preoptimised that has already been done, and there is
 * nothing left for the programs to win.
 */
function atr_fixture_gif( bool $preoptimised ): string {
	$frames = array();

	for ( $i = 0; $i < 2; $i++ ) {
		$image = imagecreate( 300, 300 );
		imagecolorallocate( $image, 240, 240, 240 );

		for ( $band = 0; $band < 300; $band += 6 ) {
			imagefilledrectangle( $image, 0, $band, 299, $band + 2, imagecolorallocate( $image, $band % 255, 80, 160 ) );
		}

		imagefilledrectangle( $image, 20 + $i * 40, 20, 50 + $i * 40, 50, imagecolorallocate( $image, 200, 20, 20 ) );

		$frame = atr_temp_path( 'gif' );
		imagegif( $image, $frame );
		imagedestroy( $image );

		$frames[] = $frame;
	}

	$path = atr_temp_path( 'gif' );
	$args = array( 'gifsicle', $preoptimised ? '-O3' : '--no-warnings', '--loopcount=forever', '--delay=50', $frames[0], $frames[1], '-o', $path );

	exec( implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1', $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

	if ( 0 !== $status || ! file_exists( $path ) ) {
		WP_CLI::error( 'gifsicle could not build the fixture: ' . implode( "\n", $output ) );
	}

	return $path;
}

function atr_fixture_svg(): string {
	$path = atr_temp_path( 'svg' );

	file_put_contents( $path, '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400"><rect width="400" height="400" fill="#c33"/></svg>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	return $path;
}

/**
 * Put a fixture in the media library, sizes and all.
 *
 * @param string $source Fixture made by one of the functions above.
 * @return int Attachment id.
 */
function atr_attach( string $source ): int {
	$directory = trailingslashit( wp_upload_dir()['basedir'] ) . 'atr-tests/';
	wp_mkdir_p( $directory );

	$path = $directory . 'atr-test-' . wp_generate_password( 8, false, false ) . '.' . pathinfo( $source, PATHINFO_EXTENSION );
	copy( $source, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	$type = wp_check_filetype( $path );
	$mime = $type['type'] ? $type['type'] : ( 'svg' === pathinfo( $path, PATHINFO_EXTENSION ) ? 'image/svg+xml' : 'application/octet-stream' );

	$id = wp_insert_attachment(
		array(
			'post_title'     => basename( $path ),
			'post_mime_type' => $mime,
			'post_status'    => 'inherit',
		),
		$path
	);

	if ( ! $id || is_wp_error( $id ) ) {
		WP_CLI::error( 'Could not attach ' . basename( $path ) );
	}

	$GLOBALS['atr_test_attachments'][] = (int) $id;

	ATR_Regenerator::load_image_api();
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );

	return (int) $id;
}

/**
 * Take every fixture away again: the attachments with their files, and the
 * sources left in the temp directory.
 */
function atr_cleanup(): void {
	foreach ( $GLOBALS['atr_test_attachments'] as $id ) {
		wp_delete_attachment( $id, true );
	}

	foreach ( $GLOBALS['atr_test_files'] as $path ) {
		if ( file_exists( $path ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	$GLOBALS['atr_test_attachments'] = array();
	$GLOBALS['atr_test_files']       = array();
}

function atr_gif_frames( string $path ): int {
	return ( new Imagick( $path ) )->getNumberImages();
}

register_shutdown_function( 'atr_cleanup' );
