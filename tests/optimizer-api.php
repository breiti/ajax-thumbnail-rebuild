<?php
/**
 * What the optimiser would work on, and whether it would work on it at all.
 *
 * Anything that ranks images by what optimising could win has to agree with
 * the optimiser on both, so the optimiser says.
 *
 *     tests/run.sh optimizer-api
 *
 * @package ajax-thumbnail-rebuild
 */

require __DIR__ . '/bootstrap.php';

atr_settings( array() );

$expected_paths = static function ( string $file, array $metadata ): array {
	$paths = array( $file );

	foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
		$paths[] = trailingslashit( dirname( $file ) ) . $size['file'];
	}

	return array_values( array_unique( $paths ) );
};

echo "The files of an upload WordPress did not scale\n";

$id       = atr_attach( atr_fixture_jpeg( 2400, 1600 ) );
$file     = get_attached_file( $id );
$metadata = wp_get_attachment_metadata( $id, true );
$paths    = ATR_Optimizer::paths( $file, $metadata );

atr_check( 'it has sizes to list', count( $metadata['sizes'] ?? array() ) > 0 );
atr_check( 'the attached file first', ( $paths[0] ?? '' ) === $file );
atr_check( 'then every size, once each', $paths === $expected_paths( $file, $metadata ), wp_json_encode( $paths ) );

echo "Two sizes that came out as the same file\n";

$doubled                     = $metadata;
$first                       = reset( $doubled['sizes'] );
$doubled['sizes']['twin-a']  = $first;
$doubled['sizes']['twin-b']  = $first;
$listed                      = ATR_Optimizer::paths( $file, $doubled );
$twin                        = trailingslashit( dirname( $file ) ) . $first['file'];

atr_check( 'the shared file is listed once', 1 === count( array_keys( $listed, $twin, true ) ) );

echo "The untouched original beside a scaled upload\n";

atr_settings( array( 'max_upload_dimension' => 1000 ) );

$scaled          = atr_attach( atr_fixture_jpeg( 2400, 1600 ) );
$scaled_file     = get_attached_file( $scaled );
$scaled_metadata = wp_get_attachment_metadata( $scaled, true );
$original        = trailingslashit( dirname( $scaled_file ) ) . ( $scaled_metadata['original_image'] ?? '' );

atr_check( 'WordPress kept an original', ! empty( $scaled_metadata['original_image'] ) && file_exists( $original ) );
atr_check( 'left out while the setting is off', ! in_array( $original, ATR_Optimizer::paths( $scaled_file, $scaled_metadata ), true ) );

atr_settings( array( 'max_upload_dimension' => 1000, 'optimize_original' => true ) );

$with_original = ATR_Optimizer::paths( $scaled_file, $scaled_metadata );

atr_check( 'listed last once it is on', end( $with_original ) === $original );

echo "Whether it still needs optimising\n";

atr_settings( array() );

atr_check( 'a fresh upload does', ATR_Optimizer::needs_optimizing( wp_get_attachment_metadata( $id, true ) ) );

$result = ATR_Optimizer::optimize_and_store( $id );

atr_check( 'optimising it saved something', $result['saved'] > 0, wp_json_encode( array_diff_key( $result, array( 'metadata' => 1 ) ) ) );
atr_check( 'and stored the mark', ! ATR_Optimizer::needs_optimizing( wp_get_attachment_metadata( $id, true ) ) );

$quality = (int) ATR_Settings::get( 'optimize_quality', ATR_Uploads::CORE_QUALITY );
atr_settings( array( 'optimize_quality' => $quality + 1 ) );

atr_check( 'a higher quality asks for it again', ATR_Optimizer::needs_optimizing( wp_get_attachment_metadata( $id, true ) ) );

atr_report();
