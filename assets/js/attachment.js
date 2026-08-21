/**
 * The rebuild button on a single attachment, in the media list and in the modal.
 *
 * The handler is delegated, so it also works for the fields the media modal
 * renders after this script has run.
 */
( function( apiFetch, i18n ) {
	'use strict';

	var __ = i18n.__;

	document.addEventListener( 'click', function( event ) {
		var button = event.target.closest ? event.target.closest( '.atr-rebuild' ) : null;

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		var message = button.parentNode.querySelector( '.atr-rebuild-message' );

		function say( text ) {
			if ( message ) {
				message.textContent = text;
			}
		}

		button.disabled = true;
		say( __( 'Rebuilding…', 'ajax-thumbnail-rebuild' ) );

		apiFetch( {
			path: '/ajax-thumbnail-rebuild/v1/rebuild',
			method: 'POST',
			data: { id: parseInt( button.dataset.id, 10 ) }
		} ).then( function( response ) {
			say( response.skipped ? response.message : __( 'Done.', 'ajax-thumbnail-rebuild' ) );
		} ).catch( function( error ) {
			say( error.message || __( 'The thumbnails could not be rebuilt.', 'ajax-thumbnail-rebuild' ) );
		} ).then( function() {
			button.disabled = false;
		} );
	} );
} )( wp.apiFetch, wp.i18n );
