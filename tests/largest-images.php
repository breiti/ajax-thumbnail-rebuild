<?php
/**
 * Which images weigh the most, among those the optimiser would still work on.
 *
 *     tests/run.sh largest-images
 *
 * @package ajax-thumbnail-rebuild
 */

require __DIR__ . '/bootstrap.php';

atr_settings( array() );

$big   = atr_attach( atr_fixture_jpeg( 3000, 2000 ) );
$small = atr_attach( atr_fixture_jpeg( 800, 600 ) );
$done  = atr_attach( atr_fixture_jpeg( 2000, 1500 ) );
$svg   = atr_attach( atr_fixture_svg() );
$gone  = atr_attach( atr_fixture_jpeg( 800, 600 ) );

ATR_Optimizer::optimize_and_store( $done );
unlink( get_attached_file( $gone ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

$five = array( $big, $small, $done, $svg, $gone );
$ids  = static function ( array $rows ): array {
	return array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
};

echo "What still needs optimising\n";

$rows = ATR_Largest::weigh( $five );

atr_check( 'only the two that need it, in the order asked', $ids( $rows ) === array( $big, $small ), wp_json_encode( $ids( $rows ) ) );

echo "Everything, optimised or not\n";

$all      = ATR_Largest::weigh( $five, true );
$all_done = wp_list_pluck( $all, 'done', 'id' );

atr_check( 'the optimised one is listed', isset( $all_done[ $done ] ) );
atr_check( 'and marked as done', true === ( $all_done[ $done ] ?? null ) );
atr_check( 'the others are not marked', false === ( $all_done[ $big ] ?? null ) );
atr_check( 'never an SVG', ! isset( $all_done[ $svg ] ) );
atr_check( 'never one whose file is gone', ! isset( $all_done[ $gone ] ) );

echo "What a row weighs\n";

$file  = get_attached_file( $big );
$paths = ATR_Optimizer::paths( $file, wp_get_attachment_metadata( $big, true ) );
$row   = $rows[0];

atr_check( 'the bytes of the files the optimiser works on', $row['bytes'] === array_sum( array_map( 'filesize', $paths ) ), $row['bytes'] . ' vs ' . array_sum( array_map( 'filesize', $paths ) ) );
atr_check( 'counted as files', $row['files'] === count( $paths ), $row['files'] . ' vs ' . count( $paths ) );
atr_check( 'named by its file', $row['file'] === wp_basename( $file ), $row['file'] );

echo "Ranking\n";

$ranked = ATR_Largest::rank( $rows );

atr_check( 'heaviest first', $ids( $ranked ) === array( $big, $small ), wp_json_encode( $ids( $ranked ) ) );
atr_check( 'cut to a number', 1 === count( ATR_Largest::rank( $rows, 1 ) ) );
atr_check(
	'a tie goes to the lower id',
	$ids( ATR_Largest::rank( array( array( 'id' => 9, 'bytes' => 5 ), array( 'id' => 3, 'bytes' => 5 ) ) ) ) === array( 3, 9 )
);

echo "Metadata that is not what it should be\n";

$broken  = atr_attach( atr_fixture_jpeg( 800, 600 ) );
$no_size = atr_attach( atr_fixture_jpeg( 800, 600 ) );

update_post_meta( $broken, '_wp_attachment_metadata', 'not an array' );
update_post_meta( $no_size, '_wp_attachment_metadata', array( 'width' => 800, 'height' => 600, 'file' => get_post_meta( $no_size, '_wp_attached_file', true ) ) );

$error_before = error_get_last();
$odd          = wp_list_pluck( ATR_Largest::weigh( array( $broken, $no_size ) ), 'files', 'id' );

atr_check( 'no warning', error_get_last() === $error_before, wp_json_encode( error_get_last() ) );
atr_check( 'a string for metadata weighs the attached file alone', 1 === ( $odd[ $broken ] ?? null ), wp_json_encode( $odd ) );
atr_check( 'no sizes weighs the attached file alone', 1 === ( $odd[ $no_size ] ?? null ), wp_json_encode( $odd ) );

echo "The top of the list is something the optimiser works on\n";

$top = ATR_Largest::find( array( 'include' => $five ), false, 1 );

atr_check( 'one row', 1 === count( $top ) );
atr_check( 'the heaviest', $big === ( $top[0]['id'] ?? 0 ) );

$result = ATR_Optimizer::optimize_and_store( (int) ( $top[0]['id'] ?? 0 ) );

atr_check( 'which it does not turn away as already done', 'already' !== $result['reason'], $result['reason'] );

atr_report();
