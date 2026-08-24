/**
 * The rebuild button on a single attachment, in the media list and in the modal.
 *
 * The handler is delegated, so it also works for the fields the media modal
 * renders after this script has run.
 */
( function( apiFetch, i18n ) {
	'use strict';

	var __ = i18n.__;
	var sprintf = i18n.sprintf;

	/**
	 * Why an image came out no smaller. "0 B" on its own tells nobody anything.
	 *
	 * @param {Object} response What the endpoint answered.
	 *
	 * @return {string} A sentence for the person who pressed the button.
	 */
	function nothingSaved( response ) {
		if ( 'no_tools' === response.reason ) {
			return __( 'Nothing to save: this server has no image optimiser installed, so all this can do is write the image out again.', 'ajax-thumbnail-rebuild' );
		}

		if ( 'png_lossy_off' === response.reason ) {
			return __( 'Already as small as lossless optimising makes it. Allow lossy PNG in the settings to take it further.', 'ajax-thumbnail-rebuild' );
		}

		if ( 'png' === response.reason ) {
			return __( 'PNG files do not get smaller by re-encoding. AVIF and WebP copies are what helps for these.', 'ajax-thumbnail-rebuild' );
		}

		if ( 'already' === response.reason ) {
			/* translators: %d: the quality setting, e.g. 82. */
			return sprintf( __( 'Already optimised at quality %d.', 'ajax-thumbnail-rebuild' ), response.quality );
		}

		/* translators: %d: the quality setting, e.g. 82. */
		return sprintf( __( 'Already as small as it gets at quality %d.', 'ajax-thumbnail-rebuild' ), response.quality );
	}

	function formatBytes( bytes ) {
		if ( bytes >= 1048576 ) {
			return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
		}

		if ( bytes >= 1024 ) {
			return Math.round( bytes / 1024 ) + ' KB';
		}

		return bytes + ' B';
	}

	document.addEventListener( 'click', function( event ) {
		var button = event.target.closest ? event.target.closest( '.atr-rebuild-button' ) : null;

		if ( ! button || ! button.dataset.id ) {
			return;
		}

		event.preventDefault();

		var message = button.parentNode.querySelector( '.atr-rebuild-message' );

		function say( text ) {
			if ( message ) {
				message.textContent = text;
			}
		}

		var optimizing = 'optimize' === button.dataset.mode;

		button.disabled = true;
		say( optimizing ? __( 'Optimising…', 'ajax-thumbnail-rebuild' ) : __( 'Rebuilding…', 'ajax-thumbnail-rebuild' ) );

		apiFetch( {
			path: optimizing ? '/ajax-thumbnail-rebuild/v1/optimize' : '/ajax-thumbnail-rebuild/v1/rebuild',
			method: 'POST',
			data: { id: parseInt( button.dataset.id, 10 ) }
		} ).then( function( response ) {
			if ( response.skipped ) {
				say( response.message );
				return;
			}

			if ( ! optimizing ) {
				say( __( 'Done.', 'ajax-thumbnail-rebuild' ) );
				return;
			}

			say( response.saved
				/* translators: %s: how much smaller the files are, e.g. "120 KB". */
				? sprintf( __( 'Done, %s saved.', 'ajax-thumbnail-rebuild' ), formatBytes( response.saved ) )
				: nothingSaved( response ) );
		} ).catch( function( error ) {
			say( error.message || __( 'The thumbnails could not be rebuilt.', 'ajax-thumbnail-rebuild' ) );
		} ).then( function() {
			button.disabled = false;
		} );
	} );
	/* -------------------------------------------------------------------
	 * Replacing the file behind an image.
	 * ---------------------------------------------------------------- */

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.atr-replace-button' ) : null;

		if ( ! button || ! button.dataset.id ) {
			return;
		}

		event.preventDefault();

		var field = button.parentNode;
		var input = field.querySelector( '.atr-replace-file' );
		var message = field.querySelector( '.atr-replace-message' );

		function say( text ) {
			if ( message ) {
				message.textContent = ' ' + text;
			}
		}

		if ( ! input || ! input.files || ! input.files.length ) {
			say( __( 'Choose a file first.', 'ajax-thumbnail-rebuild' ) );
			return;
		}

		var body = new FormData();
		body.append( 'id', button.dataset.id );
		body.append( 'file', input.files[0] );

		button.disabled = true;
		say( __( 'Replacing…', 'ajax-thumbnail-rebuild' ) );

		apiFetch( {
			path: '/ajax-thumbnail-rebuild/v1/replace',
			method: 'POST',
			body: body
		} ).then( function ( response ) {
			say( sprintf(
				/* translators: 1: image width, 2: image height. */
				__( 'Replaced. The image is now %1$d by %2$d; reload to see it.', 'ajax-thumbnail-rebuild' ),
				response.width,
				response.height
			) );

			refresh( response );
		} ).catch( function ( error ) {
			say( error.message || __( 'The image could not be replaced.', 'ajax-thumbnail-rebuild' ) );
		} ).then( function () {
			button.disabled = false;
		} );
	} );

	/**
	 * Show the new picture without a reload.
	 *
	 * The URL has not changed - that is the point of replacing - so the browser
	 * would go on showing what it already has; a throwaway query string is what
	 * makes it look again.
	 *
	 * @param {Object} response What the endpoint answered.
	 */
	function refresh( response ) {
		var stamp = 'atr=' + Date.now();

		document.querySelectorAll( '.media-modal img, .attachment-details img, .wp_attachment_image img, .attachment-preview img' ).forEach( function ( image ) {
			if ( ! image.src ) {
				return;
			}

			image.src = image.src.split( '#' )[0].replace( /([?&])atr=\d+/, '$1' ).replace( /[?&]$/, '' )
				+ ( image.src.indexOf( '?' ) === -1 ? '?' : '&' ) + stamp;
		} );
	}
} )( wp.apiFetch, wp.i18n );
