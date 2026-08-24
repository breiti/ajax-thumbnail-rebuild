<?php
/**
 * WebP copies of the images this site serves.
 *
 * WebP is the safe half of the pair: every browser still in use reads it, and
 * every build of GD or Imagick worth having can write it. It is the fallback
 * behind AVIF in the <picture>, and on a server that cannot write AVIF it is
 * the only copy there is.
 *
 * Everything the two formats share lives in ATR_Copies.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Webp extends ATR_Copies {

	/** Suffix appended to the original file name, e.g. photo-300x300.jpg.webp. */
	const SUFFIX = '.webp';

	const MIME = 'image/webp';

	const FORMAT = 'webp';

	const METADATA_KEY = 'atr_webp';

	const DEFAULT_QUALITY = 82;

	/**
	 * The URL of the WebP copy of an image URL, when the copy is on disk.
	 *
	 * @deprecated Use ATR_Webp::copy_url().
	 *
	 * @param string $url Image URL on this site.
	 * @return string Empty when there is no copy to serve.
	 */
	public static function webp_url( string $url ): string {
		return self::copy_url( $url );
	}
}
