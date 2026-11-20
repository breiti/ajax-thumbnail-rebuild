<?php
/**
 * A selection handed to the Rebuild tab is worked through in the order it was
 * handed over.
 *
 * The Largest images tab hands over the heaviest first. If the list came back
 * newest first instead, a Stop or a spent quota halfway could leave the heaviest
 * undone - the opposite of what the tab is for.
 *
 *     tests/run.sh attachments-order
 *
 * @package ajax-thumbnail-rebuild
 */

require __DIR__ . '/bootstrap.php';

atr_settings( array() );

$a = atr_attach( atr_fixture_jpeg( 300, 200 ) );
$b = atr_attach( atr_fixture_jpeg( 300, 200 ) );
$c = atr_attach( atr_fixture_jpeg( 300, 200 ) );

/* Days apart, so newest first is c, b, a - and neither order asked for below
   could come back that way by accident. */
foreach ( array( $a => '2001-01-01 00:00:00', $b => '2001-01-02 00:00:00', $c => '2001-01-03 00:00:00' ) as $id => $date ) {
	wp_update_post( array( 'ID' => $id, 'post_date' => $date, 'post_date_gmt' => $date ) );
}

$ids = static function ( array $include ): array {
	return array_map( 'intval', wp_list_pluck( ATR_Attachments::query( array( 'include' => $include ) ), 'id' ) );
};

echo "A selection keeps its order\n";

atr_check( 'b, c, a', $ids( array( $b, $c, $a ) ) === array( $b, $c, $a ), wp_json_encode( $ids( array( $b, $c, $a ) ) ) );
atr_check( 'c, a, b', $ids( array( $c, $a, $b ) ) === array( $c, $a, $b ), wp_json_encode( $ids( array( $c, $a, $b ) ) ) );

echo "Without a selection, newest first as before\n";

$listed = array_values( array_intersect( array_map( 'intval', wp_list_pluck( ATR_Attachments::query(), 'id' ) ), array( $a, $b, $c ) ) );

atr_check( 'c, b, a', $listed === array( $c, $b, $a ), wp_json_encode( $listed ) );

atr_report();
