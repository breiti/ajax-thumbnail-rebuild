<?php
/**
 * The Largest images tab: that it is there, that its script is, and that the
 * Rebuild tab no longer says every selection came from the media library.
 *
 * What the tab does in a browser is checked in a browser; this is the part a
 * script can see.
 *
 *     tests/run.sh largest-tab
 *
 * @package ajax-thumbnail-rebuild
 */

require __DIR__ . '/bootstrap.php';

atr_settings( array() );

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) ( $admin[0] ?? 0 ) );

$render = static function ( array $query ): string {
	$_GET = $query;

	ob_start();
	( new ATR_Admin_Page() )->render();

	return (string) ob_get_clean();
};

echo "The tab\n";

$html = $render( array( 'tab' => 'largest' ) );

atr_check( 'is in the tab bar', false !== strpos( $html, 'tab=largest' ) && false !== strpos( $html, 'Largest images' ) );
atr_check( 'is the one shown', 1 === preg_match( '/tab=largest[^"]*"\s+class="nav-tab nav-tab-active"/', $html ) );

foreach ( array( 'atr-largest-all', 'atr-largest-scan', 'atr-largest-progress', 'atr-largest-results', 'atr-largest-summary', 'atr-largest-optimize' ) as $element ) {
	atr_check( "has #$element", false !== strpos( $html, 'id="' . $element . '"' ) );
}

echo "Its script\n";

$enqueue = new ReflectionMethod( ATR_Plugin::class, 'enqueue_page' );
$enqueue->setAccessible( true );
$enqueue->invoke( ajax_thumbnail_rebuild() );

atr_check( 'is loaded', wp_script_is( 'ajax-thumbnail-rebuild-largest', 'enqueued' ) );
atr_check( 'knows where the Rebuild tab is', false !== strpos( (string) wp_scripts()->get_data( 'ajax-thumbnail-rebuild', 'data' ), 'rebuildUrl' ) );

echo "The Rebuild tab, handed a selection\n";

$attachment = atr_attach( atr_fixture_jpeg( 300, 200 ) );
$html       = $render( array( 'tab' => 'rebuild', 'ids' => (string) $attachment, 'mode' => 'optimize' ) );

atr_check( 'says the images were picked', false !== strpos( $html, 'image you picked.' ), 'notice not found' );
atr_check( 'no longer says where from', false === strpos( $html, 'picked in the media library' ) );

$_GET = array();

atr_report();
