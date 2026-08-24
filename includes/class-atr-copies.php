<?php
/**
 * Modern-format copies of the images this site serves.
 *
 * A copy is written next to every generated size as "<file><suffix>", and the
 * front end serves it through a <picture> element with the original as the
 * fallback source. That works behind a page cache, which sniffing the Accept
 * header does not: one cached page would then be served to every browser.
 *
 * One subclass per format - AVIF and WebP today - and this class does
 * everything that is the same for both: writing the copies, deleting them, and
 * building the <picture> that offers each of them in turn. The browser takes
 * the first <source> it can read, so the order in formats() is the order the
 * formats are preferred in.
 *
 * Nothing replaces the original file, so switching an option off simply stops
 * that format being offered and every image is served from its own file again.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class ATR_Copies {

	/** Suffix appended to the original file name, e.g. photo-300x300.jpg.webp. */
	const SUFFIX = '';

	/** What the copies are written as, and what the <source> is typed as. */
	const MIME = '';

	/** Short name, used for the option keys and the filter names. */
	const FORMAT = '';

	/** Where the written file names are recorded in the attachment metadata. */
	const METADATA_KEY = '';

	/** Quality used when nothing says otherwise. */
	const DEFAULT_QUALITY = 82;

	/** Source types worth converting. */
	const SOURCE_MIMES = array( 'image/jpeg', 'image/png' );

	/**
	 * The formats a copy can be written in, best compression first.
	 *
	 * The browser reads a <picture> from the top and stops at the first source
	 * it understands, so this order decides what a browser that can read both
	 * ends up downloading.
	 *
	 * @return string[] Class names.
	 */
	public static function formats(): array {
		/**
		 * Filter the copy formats and the order they are offered in.
		 *
		 * @param string[] $formats Class names, each an ATR_Copies subclass.
		 */
		$formats = (array) apply_filters(
			'ajax_thumbnail_rebuild_copy_formats',
			array( ATR_Avif::class, ATR_Webp::class )
		);

		return array_values( array_filter(
			$formats,
			static function ( $format ) {
				return is_string( $format ) && is_subclass_of( $format, self::class );
			}
		) );
	}

	/**
	 * The formats this site is set to write.
	 *
	 * @return string[] Class names.
	 */
	public static function enabled_formats(): array {
		return array_values( array_filter(
			self::formats(),
			static function ( $format ) {
				return $format::is_enabled();
			}
		) );
	}

	/**
	 * Every suffix a copy can carry, whether that format is switched on or not.
	 *
	 * Anything that looks at files on disk needs all of them: a copy written
	 * while a format was on is still on disk after it is switched off.
	 *
	 * @return string[]
	 */
	public static function suffixes(): array {
		return array_map(
			static function ( $format ) {
				return $format::SUFFIX;
			},
			self::formats()
		);
	}

	/**
	 * The option key holding whether this format is written.
	 */
	public static function setting_enabled(): string {
		return static::FORMAT . '_enabled';
	}

	/**
	 * The option key holding the quality this format is written at.
	 */
	public static function setting_quality(): string {
		return static::FORMAT . '_quality';
	}

	public static function register(): void {
		// Generation follows the metadata, so uploads and rebuilds are both covered.
		add_filter( 'wp_generate_attachment_metadata', array( self::class, 'generate_for_attachment' ), 20, 2 );
		add_action( 'delete_attachment', array( self::class, 'delete_for_attachment' ) );

		if ( ! self::is_serving() ) {
			return;
		}

		add_filter( 'wp_content_img_tag', array( self::class, 'filter_img_tag' ), 30 );
		add_filter( 'wp_get_attachment_image', array( self::class, 'filter_img_tag' ), 30 );
	}

	/**
	 * Whether copies in this format are written at all.
	 */
	public static function is_enabled(): bool {
		/**
		 * Filter whether this format is written.
		 *
		 * One filter per format: ajax_thumbnail_rebuild_webp_enabled and
		 * ajax_thumbnail_rebuild_avif_enabled.
		 *
		 * @param bool $enabled Whether the setting is on.
		 */
		return (bool) apply_filters(
			'ajax_thumbnail_rebuild_' . static::FORMAT . '_enabled',
			(bool) ATR_Settings::get( static::setting_enabled() )
		);
	}

	/**
	 * Whether the front end should serve any of the copies.
	 *
	 * Independent of the proxy: both features can be on at once. A URL the proxy
	 * has already rewritten is not one of this site's files any more, so it finds
	 * no copy to offer and leaves the tag alone - the proxy is serving its own
	 * modern format from its CDN for that image anyway.
	 */
	public static function is_serving(): bool {
		return (bool) self::enabled_formats();
	}

	/**
	 * Write a copy of the full size and of every generated size, in every format.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment id.
	 * @return array Metadata, untouched apart from our own bookkeeping keys.
	 */
	public static function generate_for_attachment( $metadata, $attachment_id ) {
		$formats = self::enabled_formats();

		if ( ! is_array( $metadata ) || ! $formats ) {
			return $metadata;
		}

		$file = get_attached_file( (int) $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return $metadata;
		}

		$sources = self::source_files( $metadata, $file );

		foreach ( $formats as $format ) {
			$written = array();

			foreach ( $sources as $path ) {
				if ( $format::convert( $path ) ) {
					$written[] = wp_basename( $path ) . $format::SUFFIX;
				}
			}

			/* Recorded so the copies can be found and deleted later without guessing at
			   file names, and so a site can see what was written. */
			if ( $written ) {
				$metadata[ $format::METADATA_KEY ] = $written;
			} else {
				unset( $metadata[ $format::METADATA_KEY ] );
			}
		}

		return $metadata;
	}

	/**
	 * Every file belonging to an attachment that is worth a copy.
	 *
	 * @param array  $metadata Attachment metadata.
	 * @param string $file     Full size path.
	 * @return string[] Absolute paths.
	 */
	private static function source_files( array $metadata, string $file ): array {
		$directory = trailingslashit( dirname( $file ) );
		$paths     = array( $file );

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$paths[] = $directory . $size['file'];
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Convert one file into every format that is switched on.
	 *
	 * @param string $path Absolute path of the source image.
	 */
	public static function convert_all( string $path ): void {
		foreach ( self::enabled_formats() as $format ) {
			$format::convert( $path );
		}
	}

	/**
	 * Convert one file, unless the result would be no smaller than the original.
	 *
	 * @param string $path Absolute path of the source image.
	 * @return bool Whether a copy in this format exists for this file afterwards.
	 */
	public static function convert( string $path ): bool {
		if ( ! file_exists( $path ) ) {
			return false;
		}

		$mime = wp_get_image_mime( $path );

		if ( ! in_array( $mime, static::SOURCE_MIMES, true ) ) {
			return false;
		}

		$target = $path . static::SUFFIX;

		if ( file_exists( $target ) && filemtime( $target ) >= filemtime( $path ) ) {
			// Already converted and still current.
			return true;
		}

		if ( ! self::supported( static::MIME ) ) {
			return false;
		}

		$editor = wp_get_image_editor( $path );

		if ( is_wp_error( $editor ) ) {
			return false;
		}

		$editor->set_quality( self::quality() );

		$saved = $editor->save( $target, static::MIME );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return false;
		}

		/* A copy that is bigger than the file it replaces is worse than no copy at
		   all - PNG line art does this regularly. */
		if ( filesize( $saved['path'] ) >= filesize( $path ) ) {
			wp_delete_file( $saved['path'] );

			return false;
		}

		return true;
	}

	/**
	 * The quality this format is written at.
	 */
	public static function quality(): int {
		$quality = (int) ATR_Settings::get( static::setting_quality(), static::DEFAULT_QUALITY );

		return min( 100, max( 1, $quality ) );
	}

	/**
	 * Whether the image library on this server can write a format.
	 *
	 * Asked often enough - on every conversion and twice on the settings screen -
	 * to be worth remembering, and the answer cannot change inside one request.
	 *
	 * @param string $mime Mime type to write.
	 */
	public static function supported( string $mime ): bool {
		static $answers = array();

		if ( ! isset( $answers[ $mime ] ) ) {
			$answers[ $mime ] = wp_image_editor_supports( array( 'mime_type' => $mime ) );
		}

		return $answers[ $mime ];
	}

	/**
	 * Remove the copies, in every format, along with the attachment.
	 *
	 * Not limited to the formats that are switched on: a copy written while a
	 * format was on outlives the setting, and would outlive the attachment too.
	 *
	 * @param int $attachment_id Attachment being deleted.
	 */
	public static function delete_for_attachment( $attachment_id ): void {
		$file = get_attached_file( (int) $attachment_id );

		if ( ! $file ) {
			return;
		}

		$metadata = wp_get_attachment_metadata( (int) $attachment_id, true );
		$sources  = self::source_files( is_array( $metadata ) ? $metadata : array(), $file );

		foreach ( $sources as $path ) {
			foreach ( self::suffixes() as $suffix ) {
				$copy = $path . $suffix;

				if ( file_exists( $copy ) ) {
					wp_delete_file( $copy );
				}
			}
		}
	}

	/**
	 * Wrap an image tag in a <picture> with the copies in front of it.
	 *
	 * @param string $html The <img> tag.
	 * @return string
	 */
	public static function filter_img_tag( $html ) {
		if ( ! is_string( $html ) || false !== strpos( $html, '<picture' ) || is_feed() ) {
			return $html;
		}

		if ( ! preg_match( '/\ssrc=["\']([^"\']+)["\']/', $html, $match ) ) {
			return $html;
		}

		$srcset = preg_match( '/\ssrcset=["\']([^"\']+)["\']/', $html, $srcset_match ) ? $srcset_match[1] : '';
		$sizes  = preg_match( '/\ssizes=["\']([^"\']+)["\']/', $html, $sizes_match ) ? $sizes_match[1] : '';
		$offers = array();

		foreach ( self::enabled_formats() as $format ) {
			$src = $format::copy_url( $match[1] );

			if ( ! $src ) {
				// No copy for the main file: a partial <source> would only add markup.
				continue;
			}

			// Empty when any candidate has no copy: a mixed srcset would 404 half the time.
			$candidates = $srcset ? $format::copy_srcset( $srcset ) : '';
			$attributes = array( sprintf( 'type="%s" srcset="%s"', esc_attr( $format::MIME ), esc_attr( $candidates ?: $src ) ) );

			/* sizes only means something beside a srcset with width descriptors, which is
			   exactly the case where the whole srcset could be mapped. */
			if ( $candidates && $sizes ) {
				$attributes[] = sprintf( 'sizes="%s"', esc_attr( $sizes ) );
			}

			$offers[] = sprintf( '<source %s />', implode( ' ', $attributes ) );
		}

		if ( ! $offers ) {
			return $html;
		}

		return sprintf( '<picture>%s%s</picture>', implode( '', $offers ), $html );
	}

	/**
	 * Rewrite every candidate of a srcset, giving up if one has no copy.
	 */
	public static function copy_srcset( string $srcset ): string {
		$candidates = array();

		foreach ( array_map( 'trim', explode( ',', $srcset ) ) as $candidate ) {
			$parts = preg_split( '/\s+/', $candidate );

			if ( ! $parts || empty( $parts[0] ) ) {
				continue;
			}

			$url = static::copy_url( $parts[0] );

			if ( ! $url ) {
				// One missing copy would otherwise let the browser pick a 404.
				return '';
			}

			$parts[0]     = $url;
			$candidates[] = implode( ' ', $parts );
		}

		return implode( ', ', $candidates );
	}

	/**
	 * The URL of this format's copy of an image URL, when the copy is on disk.
	 *
	 * @param string $url Image URL on this site.
	 * @return string Empty when there is no copy to serve.
	 */
	public static function copy_url( string $url ): string {
		$uploads = wp_get_upload_dir();
		$base    = set_url_scheme( $uploads['baseurl'] );
		$clean   = set_url_scheme( strtok( $url, '?' ) );

		if ( 0 !== strpos( $clean, $base ) ) {
			return '';
		}

		$relative = ltrim( substr( $clean, strlen( $base ) ), '/' );
		$path     = trailingslashit( $uploads['basedir'] ) . $relative . static::SUFFIX;

		if ( ! file_exists( $path ) ) {
			return '';
		}

		return $uploads['baseurl'] . '/' . $relative . static::SUFFIX;
	}
}
