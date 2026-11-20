<?php
/**
 * A mark left by a version that could not optimise a format does not count
 * for that format.
 *
 * Before 2.3.0 the optimiser left GIFs untouched - GIF was missing from the
 * formats it worked on - but still marked them done. Those marks must not keep
 * the GIFs out of the list, or the fix would only ever reach new uploads. A
 * JPEG with the same old mark stays done: optimising it again would re-encode
 * a lossy file for nothing.
 *
 *     tests/run.sh stale-marks
 *
 * @package ajax-thumbnail-rebuild
 */

require __DIR__ . '/bootstrap.php';

atr_settings( array() );

// What 2.2 wrote: quality, programs, lossy - and no word on formats.
$old_mark = array(
	'quality' => (int) ATR_Settings::get( 'optimize_quality', ATR_Uploads::CORE_QUALITY ),
	'tools'   => ATR_Tools::signature(),
	'lossy'   => false,
);

echo "A mark from before GIFs were optimised\n";

atr_check( 'does not count for a GIF', ATR_Optimizer::needs_optimizing( array( ATR_Optimizer::DONE_KEY => $old_mark ), 'image/gif' ) );
atr_check( 'still counts for a JPEG', ! ATR_Optimizer::needs_optimizing( array( ATR_Optimizer::DONE_KEY => $old_mark ), 'image/jpeg' ) );
atr_check( 'and for a PNG', ! ATR_Optimizer::needs_optimizing( array( ATR_Optimizer::DONE_KEY => $old_mark ), 'image/png' ) );

echo "A GIF in the library with that mark\n";

$gif = atr_attach( atr_fixture_gif( false ) );

$metadata                               = wp_get_attachment_metadata( $gif, true );
$metadata[ ATR_Optimizer::DONE_KEY ]    = $old_mark;
wp_update_attachment_metadata( $gif, $metadata );

$listed = wp_list_pluck( ATR_Largest::weigh( array( $gif ) ), 'id' );

atr_check( 'is listed as needing it', array( $gif ) === array_map( 'intval', $listed ), wp_json_encode( $listed ) );

$result = ATR_Optimizer::optimize_and_store( $gif );

atr_check( 'is optimised, not turned away', 'already' !== $result['reason'] && $result['saved'] > 0, wp_json_encode( array_diff_key( $result, array( 'metadata' => 1 ) ) ) );
atr_check( 'and is done after that', ! ATR_Optimizer::needs_optimizing( wp_get_attachment_metadata( $gif, true ), 'image/gif' ) );

atr_report();
