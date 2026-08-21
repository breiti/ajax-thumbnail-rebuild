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
				: __( 'Already as small as it gets.', 'ajax-thumbnail-rebuild' ) );
		} ).catch( function( error ) {
			say( error.message || __( 'The thumbnails could not be rebuilt.', 'ajax-thumbnail-rebuild' ) );
		} ).then( function() {
			button.disabled = false;
		} );
	} );
} )( wp.apiFetch, wp.i18n );
