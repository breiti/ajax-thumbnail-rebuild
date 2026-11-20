<?php
/**
 * GIFs go through gifsicle, and are never written out again by the image editor.
 *
 * The editor keeps only the first frame of an animation, so the fallback that
 * re-encodes a file the programs could not shrink would turn an animated banner
 * into a still one.
 *
 *     lando wp eval-file wp-content/plugins/ajax-thumbnail-rebuild/tests/gif-optimise.php
 *
 * @package ajax-thumbnail-rebuild
 */

require __DIR__ . '/bootstrap.php';

atr_settings( array() );

echo "Formats the optimiser works on\n";

atr_check( 'GIF is optimised', ATR_Optimizer::optimizes( 'image/gif' ) );
atr_check( 'SVG is not', ! ATR_Optimizer::optimizes( 'image/svg+xml' ) );

foreach ( array( 'image/jpeg', 'image/png', 'image/webp' ) as $mime ) {
	atr_check( "$mime still is", ATR_Optimizer::optimizes( $mime ) );
}

echo "A GIF on a server with nothing to shrink it\n";

/* Without gifsicle and without a service that takes GIFs, nothing can make a
   GIF smaller - and it is never written out again - so it is not a format the
   optimiser works on here. Listing it would put the heaviest files on the page
   at the top of a list that then saves nothing. */
$no_gif_programs = static function ( array $definitions ): array {
	unset( $definitions['image/gif'] );

	return $definitions;
};

add_filter( 'ajax_thumbnail_rebuild_tools', $no_gif_programs );

atr_check( 'a GIF is not optimised', ! ATR_Optimizer::optimizes( 'image/gif' ) );
atr_check( 'a JPEG still is', ATR_Optimizer::optimizes( 'image/jpeg' ) );

remove_filter( 'ajax_thumbnail_rebuild_tools', $no_gif_programs );

atr_check( 'with gifsicle back, a GIF is', ATR_Optimizer::optimizes( 'image/gif' ) );

echo "An animated GIF stored the long way\n";

$path   = atr_fixture_gif( false );
$before = filesize( $path );
$saved  = ATR_Optimizer::optimize_file( $path );

clearstatcache( true, $path );

atr_check( 'gifsicle saves something', $saved > 0, "saved $saved" );
atr_check( 'the file is smaller', filesize( $path ) < $before, filesize( $path ) . " >= $before" );
atr_check( 'both frames are still there', 2 === atr_gif_frames( $path ), atr_gif_frames( $path ) . ' frames' );

echo "An animated GIF there is nothing left to win on\n";

$path  = atr_fixture_gif( true );
$hash  = md5_file( $path );
$saved = ATR_Optimizer::optimize_file( $path );

clearstatcache( true, $path );

atr_check( 'nothing is saved', 0 === $saved, "saved $saved" );
atr_check( 'the file is untouched, byte for byte', md5_file( $path ) === $hash );
atr_check( 'both frames are still there', 2 === atr_gif_frames( $path ), atr_gif_frames( $path ) . ' frames' );

atr_report();
