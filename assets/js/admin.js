/**
 * Tools -> Rebuild Thumbnails.
 *
 * One image per request, so the server never has to resize a whole library
 * inside a single script run.
 */
( function( apiFetch, i18n, settings ) {
	'use strict';

	var __ = i18n.__;
	var sprintf = i18n.sprintf;
	var _n = i18n._n;

	ready( function() {
		var startButton = document.getElementById( 'atr-start' );

		if ( ! startButton ) {
			return;
		}

		var stopButton = document.getElementById( 'atr-stop' );
		var spinner = document.getElementById( 'atr-spinner' );
		var progress = document.getElementById( 'atr-progress' );
		var bar = document.getElementById( 'atr-progress-bar' );
		var status = document.getElementById( 'atr-status' );
		var preview = document.getElementById( 'atr-preview' );
		var result = document.getElementById( 'atr-result' );
		var skippedBox = document.getElementById( 'atr-skipped-box' );
		var skippedList = document.getElementById( 'atr-skipped' );
		var selectAll = document.getElementById( 'atr-size-select-all' );
		var sizeBoxes = Array.prototype.slice.call( document.querySelectorAll( '.atr-size' ) );

		var stopped = false;

		/* ---------------------------------------------------------------
		 * Size selection, the way a list table behaves.
		 * ------------------------------------------------------------ */

		function syncSelectAll() {
			var checked = sizeBoxes.filter( function( box ) {
				return box.checked;
			} ).length;

			if ( selectAll ) {
				selectAll.checked = checked === sizeBoxes.length;
				selectAll.indeterminate = checked > 0 && checked < sizeBoxes.length;
			}

			startButton.disabled = checked === 0;
		}

		if ( selectAll ) {
			selectAll.addEventListener( 'change', function() {
				sizeBoxes.forEach( function( box ) {
					box.checked = selectAll.checked;
				} );
				syncSelectAll();
			} );
		}

		sizeBoxes.forEach( function( box ) {
			box.addEventListener( 'change', syncSelectAll );
		} );

		syncSelectAll();

		/* ---------------------------------------------------------------
		 * The run.
		 * ------------------------------------------------------------ */

		function selectedSizes() {
			var checked = sizeBoxes.filter( function( box ) {
				return box.checked;
			} );

			// Everything selected means "all sizes", which the endpoint reads as an empty list.
			return checked.length === sizeBoxes.length ? [] : checked.map( function( box ) {
				return box.value;
			} );
		}

		function setBusy( busy ) {
			startButton.disabled = busy;
			stopButton.hidden = ! busy;
			spinner.classList.toggle( 'is-active', busy );

			document.querySelectorAll( '#atr-sizes input, #atr-only-featured, #atr-filename' ).forEach( function( field ) {
				field.disabled = busy;
			} );

			if ( ! busy ) {
				// Nothing selected means nothing to start.
				syncSelectAll();
			}
		}

		function setProgress( done, total ) {
			var percent = total ? Math.round( ( done / total ) * 100 ) : 0;

			progress.hidden = false;
			bar.style.width = percent + '%';
			bar.setAttribute( 'aria-valuenow', String( percent ) );
		}

		function notice( type, message ) {
			result.innerHTML = '';

			var wrapper = document.createElement( 'div' );
			wrapper.className = 'notice notice-' + type + ' inline';

			var paragraph = document.createElement( 'p' );
			paragraph.textContent = message;

			wrapper.appendChild( paragraph );
			result.appendChild( wrapper );
		}

		function addSkipped( item, reason ) {
			skippedBox.hidden = false;

			var entry = document.createElement( 'li' );
			var link = document.createElement( 'a' );

			link.href = settings.editLink.replace( '%d', String( item.id ) );
			/* translators: %d: attachment id, shown when the image has no title. */
			link.textContent = item.title || sprintf( __( 'Attachment %d', 'ajax-thumbnail-rebuild' ), item.id );

			var why = document.createElement( 'span' );
			why.className = 'atr-skipped__reason';
			why.textContent = reason;

			entry.appendChild( link );
			entry.appendChild( why );
			skippedList.appendChild( entry );
		}

		function reset() {
			stopped = false;
			result.innerHTML = '';
			skippedList.innerHTML = '';
			skippedBox.hidden = true;
			preview.hidden = true;
			bar.classList.remove( 'is-done' );
			setProgress( 0, 0 );
		}

		startButton.addEventListener( 'click', function() {
			reset();
			setBusy( true );
			status.textContent = __( 'Reading the media library…', 'ajax-thumbnail-rebuild' );

			var sizes = selectedSizes();
			var query = {
				only_featured: document.getElementById( 'atr-only-featured' ).checked,
				filename: document.getElementById( 'atr-filename' ).value.trim()
			};

			/* A selection handed over from the media library replaces the search:
			   those are the images, whatever the filters on this screen say. */
			if ( settings.include && settings.include.length ) {
				query = { include: settings.include };
			}

			apiFetch( {
				path: addQueryArgs( '/ajax-thumbnail-rebuild/v1/attachments', query )
			} ).then( function( response ) {
				if ( ! response.items.length ) {
					setBusy( false );
					progress.hidden = true;
					status.textContent = '';
					notice( 'warning', __( 'No images match those settings.', 'ajax-thumbnail-rebuild' ) );
					return;
				}

				run( response.items, sizes );
			} ).catch( function( error ) {
				setBusy( false );
				notice( 'error', error.message || __( 'The media library could not be read.', 'ajax-thumbnail-rebuild' ) );
			} );
		} );

		stopButton.addEventListener( 'click', function() {
			stopped = true;
			status.textContent = __( 'Finishing the current image…', 'ajax-thumbnail-rebuild' );
		} );

		function run( items, sizes ) {
			var index = 0;
			var skipped = 0;
			var rebuilt = 0;

			function finish( interrupted ) {
				setBusy( false );
				status.textContent = '';
				bar.classList.add( 'is-done' );

				var summary = sprintf(
					/* translators: %1$s: number of images rebuilt, %2$s: number of images skipped. */
					_n( '%1$s image rebuilt, %2$s skipped.', '%1$s images rebuilt, %2$s skipped.', rebuilt, 'ajax-thumbnail-rebuild' ),
					rebuilt,
					skipped
				);

				if ( interrupted ) {
					notice( 'warning', __( 'Stopped.', 'ajax-thumbnail-rebuild' ) + ' ' + summary );
				} else {
					notice( skipped ? 'warning' : 'success', summary );
				}
			}

			function step( delay ) {
				if ( stopped ) {
					finish( true );
					return;
				}

				if ( index >= items.length ) {
					finish( false );
					return;
				}

				var item = items[ index ];

				setProgress( index, items.length );
				status.textContent = sprintf(
					/* translators: 1: current image number, 2: total images, 3: image title. */
					__( 'Rebuilding %1$s of %2$s — %3$s', 'ajax-thumbnail-rebuild' ),
					index + 1,
					items.length,
					item.title || '#' + item.id
				);

				apiFetch( {
					path: '/ajax-thumbnail-rebuild/v1/rebuild',
					method: 'POST',
					data: { id: item.id, sizes: sizes }
				} ).then( function( response ) {
					index++;

					if ( response.skipped ) {
						skipped++;
						addSkipped( item, response.message );
					} else {
						rebuilt++;

						if ( response.thumbnail ) {
							preview.src = response.thumbnail;
							preview.alt = item.title || '';
							preview.hidden = false;
						}
					}

					setProgress( index, items.length );
					window.setTimeout( function() {
						step( 0 );
					}, delay );
				} ).catch( function( error ) {
					/* Back off and retry: a resize that ran out of memory or hit a busy
					   server usually succeeds on the next attempt. Give up on an image
					   that keeps failing rather than stalling the whole run. */
					if ( delay >= 8000 ) {
						index++;
						skipped++;
						addSkipped( item, error.message || __( 'The server could not rebuild this image.', 'ajax-thumbnail-rebuild' ) );
						step( 0 );
						return;
					}

					window.setTimeout( function() {
						step( delay ? delay * 2 : 1000 );
					}, delay || 1000 );
				} );
			}

			step( 0 );
		}
	} );

	function ready( callback ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', callback );
		} else {
			callback();
		}
	}

	/**
	 * Small stand-in for wp.url.addQueryArgs, which is not worth a dependency here.
	 */
	function addQueryArgs( path, args ) {
		var query = Object.keys( args )
			.filter( function( key ) {
				return args[ key ] !== '' && args[ key ] !== false;
			} )
			.map( function( key ) {
				// The REST API reads a comma separated list as an array argument.
				var value = Array.isArray( args[ key ] ) ? args[ key ].join( ',' ) : args[ key ];

				return encodeURIComponent( key ) + '=' + encodeURIComponent( value );
			} );

		return query.length ? path + '?' + query.join( '&' ) : path;
	}
} )( wp.apiFetch, wp.i18n, window.ATRSettings || { editLink: '', include: [] } );
