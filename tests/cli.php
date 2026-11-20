<?php
/**
 * wp atr largest and wp atr optimize.
 *
 * Run in this process rather than a new one, so the settings pinned here hold
 * for the commands too; and narrowed to the fixtures by file name, so nothing in
 * the site's own library is touched.
 *
 *     tests/run.sh cli
 *
 * @package ajax-thumbnail-rebuild
 */

require __DIR__ . '/bootstrap.php';

atr_settings( array() );

$run = static function ( string $command ) {
	return WP_CLI::runcommand(
		$command,
		array(
			'launch'     => false,
			'return'     => 'all',
			'exit_error' => false,
		)
	);
};

$big   = atr_attach( atr_fixture_jpeg( 3000, 2000 ) );
$small = atr_attach( atr_fixture_jpeg( 800, 600 ) );
$done  = atr_attach( atr_fixture_jpeg( 1600, 1200 ) );

ATR_Optimizer::optimize_and_store( $done );

// No shell between here and the command, so no quotes: they would arrive as part of the pattern.
$only_fixtures = '--filename=atr-test-*';

echo "wp atr largest\n";

$ids = $run( "atr largest --format=ids --number=2 $only_fixtures" );

atr_check( 'the heaviest two that need it, as ids', trim( $ids->stdout ) === "$big $small", wp_json_encode( $ids ) );

$count = $run( "atr largest --format=count --all $only_fixtures" );

atr_check( '--all counts the optimised one too', '3' === trim( $count->stdout ), wp_json_encode( $count ) );

$json = json_decode( $run( "atr largest --format=json --number=1 $only_fixtures" )->stdout, true );

atr_check( 'json carries the bytes', isset( $json[0]['bytes'] ) && $big === (int) $json[0]['id'], wp_json_encode( $json ) );

echo "wp atr optimize --dry-run\n";

$dry = $run( "atr optimize --dry-run --largest=1 $only_fixtures" );

atr_check( 'names the image it would optimise', false !== strpos( $dry->stdout, wp_basename( get_attached_file( $big ) ) ), wp_json_encode( $dry ) );
atr_check( 'says which programs it would use', false !== stripos( $dry->stdout, 'jpegoptim' ), $dry->stdout );
atr_check( 'and does not optimise it', ATR_Optimizer::needs_optimizing( wp_get_attachment_metadata( $big, true ) ) );

echo "wp atr optimize <id>...\n";

$real = $run( "atr optimize $big 999999999" );

atr_check( 'warns about the id that is not an image', false !== strpos( $real->stderr, '999999999' ), wp_json_encode( $real ) );
atr_check( 'and carries on', 0 === $real->return_code, (string) $real->return_code );
atr_check( 'optimises the one that is', ! ATR_Optimizer::needs_optimizing( wp_get_attachment_metadata( $big, true ) ) );
atr_check( 'and says so', false !== strpos( $real->stdout, 'Success' ), $real->stdout );

$again = $run( "atr optimize $big" );

atr_check( 'a second time it is skipped as already optimised', false !== stripos( $again->stdout, 'already' ), $again->stdout );

atr_report();
