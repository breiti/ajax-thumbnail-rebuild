<?php
/**
 * Replacing the file behind an attachment.
 *
 * The attachment keeps its id, its title, its alt text and - the point of the
 * exercise - its URL, so every post, product and email that already points at
 * it goes on pointing at the same address and simply gets the new picture.
 *
 * That is only possible while the file name stays the same, which is why a
 * replacement has to be the same kind of file as the one it replaces.
 *
 * @package ajax-thumbnail-rebuild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATR_Replace {

	/**
	 * Put a new file behind an existing attachment.
	 *
	 * @param int   $attachment_id Attachment to replace.
	 * @param array $upload        One entry of $_FILES.
	 * @return array|WP_Error url, width, height, sizes.
	 */
	public static function replace( int $attachment_id, array $upload ) {
		// Reading and writing images is wp-admin's department, which REST does not load.
		ATR_Regenerator::load_image_api();

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'atr_replace_not_image', __( 'Only images can be replaced here.', 'ajax-thumbnail-rebuild' ) );
		}

		$checked = self::check_upload( $upload );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$attached = get_attached_file( $attachment_id );

		if ( ! $attached ) {
			return new WP_Error( 'atr_replace_missing', __( 'That attachment has no file.', 'ajax-thumbnail-rebuild' ) );
		}

		$metadata  = wp_get_attachment_metadata( $attachment_id, true );
		$metadata  = is_array( $metadata ) ? $metadata : array();
		$directory = trailingslashit( dirname( $attached ) );

		/* Where the new file goes: the name of the untouched original when there
		   is one, because that is what every size is cut from. */
		$target = $directory . ( ! empty( $metadata['original_image'] )
			? $metadata['original_image']
			: wp_basename( $attached ) );

		$expected = strtolower( (string) pathinfo( $target, PATHINFO_EXTENSION ) );

		if ( $checked['ext'] !== $expected ) {
			return new WP_Error(
				'atr_replace_type',
				sprintf(
					/* translators: 1: the extension of the file being uploaded, 2: the extension it has to be. */
					__( 'This is a .%1$s file and the image it would replace is a .%2$s. Replacing keeps the file name so that every link to it goes on working, which only holds while the type stays the same.', 'ajax-thumbnail-rebuild' ),
					$checked['ext'],
					$expected
				)
			);
		}

		if ( ! is_writable( $directory ) ) {
			return new WP_Error( 'atr_replace_permission', __( 'The uploads folder cannot be written to.', 'ajax-thumbnail-rebuild' ) );
		}

		// Everything the old picture left on disk, before the new one lands.
		self::delete_old_files( $attachment_id, $metadata, $attached, $target );

		if ( ! self::move( $upload['tmp_name'], $target ) ) {
			return new WP_Error( 'atr_replace_write', __( 'The new file could not be written into the uploads folder.', 'ajax-thumbnail-rebuild' ) );
		}

		update_attached_file( $attachment_id, $target );

		/* Core's own path from here: it cuts the sizes, scales the upload down if
		   the site says so, and fires the filter this plugin's WebP copies and
		   optimising hang off. */
		$fresh = wp_generate_attachment_metadata( $attachment_id, $target );

		if ( ! is_array( $fresh ) ) {
			return new WP_Error( 'atr_replace_metadata', __( 'The new file was written but its sizes could not be generated.', 'ajax-thumbnail-rebuild' ) );
		}

		// A scaled upload means the file the site serves is not the one just written.
		if ( ! empty( $fresh['file'] ) ) {
			update_attached_file( $attachment_id, trailingslashit( wp_get_upload_dir()['basedir'] ) . $fresh['file'] );
		}

		wp_update_attachment_metadata( $attachment_id, $fresh );

		wp_update_post( array(
			'ID'            => $attachment_id,
			'post_mime_type' => $checked['type'],
		) );

		clean_post_cache( $attachment_id );

		return array(
			'url'    => (string) wp_get_attachment_url( $attachment_id ),
			'thumb'  => (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
			'width'  => (int) ( $fresh['width'] ?? 0 ),
			'height' => (int) ( $fresh['height'] ?? 0 ),
			'sizes'  => array_keys( (array) ( $fresh['sizes'] ?? array() ) ),
			'bytes'  => file_exists( $target ) ? (int) filesize( $target ) : 0,
		);
	}

	/**
	 * Is this something WordPress would have accepted as an upload?
	 *
	 * @param array $upload One entry of $_FILES.
	 * @return array{ext:string,type:string}|WP_Error
	 */
	private static function check_upload( array $upload ) {
		if ( ! empty( $upload['error'] ) || empty( $upload['tmp_name'] ) || ! is_uploaded_file( $upload['tmp_name'] ) ) {
			return new WP_Error( 'atr_replace_upload', __( 'The file did not arrive in one piece.', 'ajax-thumbnail-rebuild' ) );
		}

		$name    = sanitize_file_name( (string) ( $upload['name'] ?? '' ) );
		$checked = wp_check_filetype_and_ext( $upload['tmp_name'], $name );
		$ext     = strtolower( (string) ( $checked['ext'] ?? '' ) );
		$type    = (string) ( $checked['type'] ?? '' );

		if ( '' === $ext || '' === $type || ! in_array( $type, get_allowed_mime_types(), true ) ) {
			return new WP_Error( 'atr_replace_filetype', __( 'That kind of file cannot be uploaded here.', 'ajax-thumbnail-rebuild' ) );
		}

		if ( 0 !== strpos( $type, 'image/' ) || ! file_is_displayable_image( $upload['tmp_name'] ) ) {
			return new WP_Error( 'atr_replace_not_image', __( 'That file is not an image WordPress can read.', 'ajax-thumbnail-rebuild' ) );
		}

		return array(
			'ext'  => $ext,
			'type' => $type,
		);
	}

	/**
	 * Remove what the old picture left behind, so nothing of it survives the
	 * replacement under a name the new sizes happen not to use.
	 *
	 * @param int    $attachment_id Attachment.
	 * @param array  $metadata      Its metadata as it stands.
	 * @param string $attached      The file it currently serves.
	 * @param string $target        Where the new file is about to go.
	 */
	private static function delete_old_files( int $attachment_id, array $metadata, string $attached, string $target ): void {
		// The AVIF and WebP copies first: they are found from the metadata that is about to change.
		ATR_Copies::delete_for_attachment( $attachment_id );

		$directory = trailingslashit( dirname( $attached ) );
		$paths     = array( $attached );

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$paths[] = $directory . $size['file'];
			}
		}

		foreach ( array_unique( $paths ) as $path ) {
			// Not the file we are about to write over: that one is simply replaced.
			if ( $path !== $target && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * @param string $from Uploaded temporary file.
	 * @param string $to   Where it belongs.
	 */
	private static function move( string $from, string $to ): bool {
		/* The old file goes first rather than being written over. Writing over it
		   needs permission on the file itself, which is not a given: anything put
		   there over FTP, or by a WP-CLI run as another user, belongs to somebody
		   else. Removing it needs permission on the folder, which is the one this
		   already checked. */
		if ( file_exists( $to ) ) {
			wp_delete_file( $to );
		}

		if ( ! move_uploaded_file( $from, $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}

		$permissions = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : ( fileperms( dirname( $to ) ) & 0000666 );

		if ( $permissions ) {
			chmod( $to, $permissions ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		clearstatcache( true, $to );

		return true;
	}
}
