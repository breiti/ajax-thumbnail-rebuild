<?php
/**
 * AVIF copies of the images this site serves.
 *
 * AVIF is a good deal smaller again than WebP at the same quality, which is why
 * it is offered first in the <picture>. Two things make it the second format
 * rather than the only one: encoding costs several times the CPU that WebP
 * does, and plenty of servers still ship an image library that cannot write it.
 * Where either is true the browser simply falls through to the WebP source.
 *
 * Quality does not mean the same number it does in JPEG or WebP - AVIF at 60
 * lands somewhere near JPEG at 82 - so this format keeps a default of its own.
 *
 * Everything the two formats share lives in ATR_Copies.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Avif extends ATR_Copies {

	/** Suffix appended to the original file name, e.g. photo-300x300.jpg.avif. */
	const SUFFIX = '.avif';

	const MIME = 'image/avif';

	const FORMAT = 'avif';

	const METADATA_KEY = 'atr_avif';

	const DEFAULT_QUALITY = 60;
}
