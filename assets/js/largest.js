/**
 * Tools -> Rebuild Thumbnails -> Largest images.
 *
 * Weighs the library a page at a time, keeps every answer here, and shows the
 * heaviest. Optimising is not done here: what is picked goes to the Rebuild
 * tab, the way a selection from the media library does, heaviest first.
 */
( function( apiFetch, i18n, settings ) {
	'use strict';

	var __ = i18n.__;
	var sprintf = i18n.sprintf;
	var _n = i18n._n;

	// Ids sent per request. The route takes up to 500; this keeps each request short.
	var CHUNK = 200;
	// Rows shown, and how many of the heaviest are ticked to begin with.
	var SHOWN = 100;
	var TICKED = 20;

	function ready( callback ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', callback );
		} else {
			callback();
		}
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

	// Heaviest first; of two that weigh the same, the older upload - as wp atr largest does.
	function byWeight( a, b ) {
		return b.bytes - a.bytes || a.id - b.id;
	}

	ready( function() {
		var scanButton = document.getElementById( 'atr-largest-scan' );

		if ( ! scanButton ) {
			return;
		}

		var allBox = document.getElementById( 'atr-largest-all' );
		var spinner = document.getElementById( 'atr-largest-spinner' );
		var progress = document.getElementById( 'atr-largest-progress' );
		var bar = document.getElementById( 'atr-largest-bar' );
		var status = document.getElementById( 'atr-largest-status' );
		var result = document.getElementById( 'atr-largest-result' );
		var table = document.querySelector( 'table.atr-largest' );
		var rows = document.getElementById( 'atr-largest-results' );
		var toggle = document.getElementById( 'atr-largest-toggle' );
		var optimizeBox = document.getElementById( 'atr-largest-optimize-box' );
		var optimizeButton = document.getElementById( 'atr-largest-optimize' );
		var summary = document.getElementById( 'atr-largest-summary' );

		var shown = [];
		var scanned = false;

		function notice( type, message ) {
			result.innerHTML = '';

			if ( ! message ) {
				return;
			}

			var wrapper = document.createElement( 'div' );
			wrapper.className = 'notice notice-' + type + ' inline';

			var paragraph = document.createElement( 'p' );
			paragraph.textContent = message;

			wrapper.appendChild( paragraph );
			result.appendChild( wrapper );
		}

		function setBusy( busy ) {
			scanButton.disabled = busy;
			allBox.disabled = busy;
			spinner.classList.toggle( 'is-active', busy );
		}

		function setProgress( done, total ) {
			var percent = total ? Math.round( done / total * 100 ) : 100;

			bar.style.width = percent + '%';
			bar.setAttribute( 'aria-valuenow', String( percent ) );
			bar.classList.toggle( 'is-done', done >= total );

			/* translators: 1: images measured so far, 2: images in the library. */
			status.textContent = sprintf( __( '%1$s of %2$s images measured', 'ajax-thumbnail-rebuild' ), done.toLocaleString(), total.toLocaleString() );
		}

		function checkboxes() {
			return Array.prototype.slice.call( rows.querySelectorAll( 'input[type=checkbox]:not(:disabled)' ) );
		}

		// Ticked ids in table order, which is heaviest first.
		function selected() {
			return checkboxes().filter( function( box ) {
				return box.checked;
			} ).map( function( box ) {
				return parseInt( box.value, 10 );
			} );
		}

		function updateSummary() {
			var ids = selected();
			var bytes = shown.filter( function( row ) {
				return -1 !== ids.indexOf( row.id );
			} ).reduce( function( sum, row ) {
				return sum + row.bytes;
			}, 0 );
			var boxes = checkboxes();

			summary.textContent = sprintf(
				/* translators: 1: number of images selected, 2: what they weigh together, e.g. 12.3 MB. */
				_n( '%1$s image selected, %2$s.', '%1$s images selected, %2$s.', ids.length, 'ajax-thumbnail-rebuild' ),
				ids.length.toLocaleString(),
				formatBytes( bytes )
			);

			optimizeButton.disabled = 0 === ids.length;
			toggle.checked = boxes.length > 0 && ids.length === boxes.length;
			toggle.disabled = 0 === boxes.length;
		}

		function addRow( row, ticked ) {
			var tr = document.createElement( 'tr' );

			var check = document.createElement( 'th' );
			check.scope = 'row';
			check.className = 'check-column';

			var box = document.createElement( 'input' );
			box.type = 'checkbox';
			box.value = String( row.id );
			box.checked = ticked;
			box.disabled = row.done;
			/* translators: %s: file name. */
			box.setAttribute( 'aria-label', sprintf( __( 'Select %s', 'ajax-thumbnail-rebuild' ), row.file ) );
			box.addEventListener( 'change', updateSummary );
			check.appendChild( box );

			var image = document.createElement( 'td' );
			image.className = 'column-primary';

			var name = document.createElement( 'strong' );
			var link = document.createElement( 'a' );
			link.href = settings.editLink.replace( '%d', String( row.id ) );
			link.textContent = row.file;
			name.appendChild( link );
			image.appendChild( name );
			image.appendChild( document.createTextNode( ' #' + row.id ) );

			if ( row.done ) {
				var badge = document.createElement( 'span' );
				badge.className = 'atr-badge atr-badge--yes';
				badge.textContent = __( 'Optimised', 'ajax-thumbnail-rebuild' );
				image.appendChild( document.createTextNode( ' ' ) );
				image.appendChild( badge );
			}

			var weight = document.createElement( 'td' );
			weight.textContent = formatBytes( row.bytes );

			var files = document.createElement( 'td' );
			files.textContent = String( row.files );

			tr.appendChild( check );
			tr.appendChild( image );
			tr.appendChild( weight );
			tr.appendChild( files );
			rows.appendChild( tr );
		}

		function show( found, all ) {
			shown = found.sort( byWeight ).slice( 0, SHOWN );
			rows.innerHTML = '';

			if ( ! shown.length ) {
				table.hidden = true;
				optimizeBox.hidden = true;
				notice( 'success', all ? __( 'No images found.', 'ajax-thumbnail-rebuild' ) : __( 'Every image is already optimised.', 'ajax-thumbnail-rebuild' ) );

				return;
			}

			var ticked = 0;

			shown.forEach( function( row ) {
				var tick = ! row.done && ticked < TICKED;

				if ( tick ) {
					ticked++;
				}

				addRow( row, tick );
			} );

			if ( found.length > SHOWN ) {
				/* translators: 1: rows shown, 2: images found. */
				notice( 'info', sprintf( __( 'The heaviest %1$s of %2$s images.', 'ajax-thumbnail-rebuild' ), SHOWN.toLocaleString(), found.length.toLocaleString() ) );
			} else {
				notice( 'info', '' );
			}

			table.hidden = false;
			optimizeBox.hidden = false;
			updateSummary();
		}

		function scan() {
			var all = allBox.checked;

			scanned = true;
			var found = [];

			notice( 'info', '' );
			setBusy( true );
			progress.hidden = false;
			status.textContent = __( 'Listing the library...', 'ajax-thumbnail-rebuild' );

			apiFetch( { path: '/ajax-thumbnail-rebuild/v1/attachments' } ).then( function( response ) {
				var ids = response.items.map( function( item ) {
					return item.id;
				} );
				var offset = 0;

				function step() {
					setProgress( offset, ids.length );

					if ( offset >= ids.length ) {
						setBusy( false );
						show( found, all );

						return;
					}

					apiFetch( {
						path: '/ajax-thumbnail-rebuild/v1/weigh',
						method: 'POST',
						data: { ids: ids.slice( offset, offset + CHUNK ), all: all }
					} ).then( function( weighed ) {
						found = found.concat( weighed.items );
						// The last chunk is usually short; the count must not run past the library.
						offset = Math.min( offset + CHUNK, ids.length );
						step();
					} ).catch( fail );
				}

				step();
			} ).catch( fail );
		}

		function fail( error ) {
			setBusy( false );
			notice( 'error', ( error && error.message ) || __( 'The scan stopped: the site did not answer.', 'ajax-thumbnail-rebuild' ) );
		}

		scanButton.addEventListener( 'click', scan );

		// A different question, so the last answer no longer applies.
		allBox.addEventListener( 'change', function() {
			if ( scanned ) {
				scan();
			}
		} );

		toggle.addEventListener( 'change', function() {
			checkboxes().forEach( function( box ) {
				box.checked = toggle.checked;
			} );

			updateSummary();
		} );

		optimizeButton.addEventListener( 'click', function() {
			var ids = selected();

			if ( ids.length ) {
				window.location.href = settings.rebuildUrl + '&ids=' + ids.join( ',' ) + '&mode=optimize';
			}
		} );
	} );
} )( wp.apiFetch, wp.i18n, window.ATRSettings || { editLink: '', rebuildUrl: '' } );
