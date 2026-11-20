<?php
/**
 * The route the Largest images tab weighs the library through.
 *
 *     tests/run.sh rest-weigh
 *
 * @package ajax-thumbnail-rebuild
 */

require __DIR__ . '/bootstrap.php';

atr_settings( array() );

$weigh = static function ( array $body ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/ajax-thumbnail-rebuild/v1/weigh' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );

	return rest_do_request( $request );
};

$one   = atr_attach( atr_fixture_jpeg( 800, 600 ) );
$two   = atr_attach( atr_fixture_jpeg( 800, 600 ) );
$post  = wp_insert_post( array( 'post_title' => 'atr-test post', 'post_status' => 'draft' ) );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );

echo "Who may ask\n";

wp_set_current_user( 0 );

atr_check( 'nobody signed in is turned away', 401 === $weigh( array( 'ids' => array( $one ) ) )->get_status() );

wp_set_current_user( (int) ( $admin[0] ?? 0 ) );

echo "What comes back\n";

$response = $weigh( array( 'ids' => array( $one, $post, 999999999, $two ) ) );
$items    = $response->get_data()['items'] ?? null;

atr_check( 'an administrator gets an answer', 200 === $response->get_status(), (string) $response->get_status() );
atr_check( 'only the two images, a post and a missing id left out quietly', is_array( $items ) && array_map( 'intval', wp_list_pluck( $items, 'id' ) ) === array( $one, $two ), wp_json_encode( $items ) );
atr_check( 'each with its weight', is_array( $items ) && isset( $items[0]['bytes'], $items[0]['files'], $items[0]['file'], $items[0]['done'] ) );

echo "Too much at once\n";

atr_check( '501 ids are refused', 400 === $weigh( array( 'ids' => range( 1, 501 ) ) )->get_status() );
atr_check( 'no ids are refused', 400 === $weigh( array() )->get_status() );

wp_delete_post( $post, true );

atr_report();
