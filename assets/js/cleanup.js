/**
 * Tools -> Rebuild Thumbnails -> Cleanup.
 *
 * Scans one image per request, the same way the rebuild does, and lists what it
 * finds. Deleting is a separate decision, made after looking at the list.
 */
( function( apiFetch, i18n, settings ) {
	'use strict';

	var __ = i18n.__;
	var sprintf = i18n.sprintf;
	var _n = i18n._n;

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

	ready( function() {
		var scanButton = document.getElementById( 'atr-cleanup-scan' );

		if ( ! scanButton ) {
			return;
		}

		var stopButton = document.getElementById( 'atr-cleanup-stop' );
		var spinner = document.getElementById( 'atr-cleanup-spinner' );
		var progress = document.getElementById( 'atr-cleanup-progress' );
		var bar = document.getElementById( 'atr-cleanup-bar' );
		var status = document.getElementById( 'atr-cleanup-status' );
		var result = document.getElementById( 'atr-cleanup-result' );
		var table = document.getElementById( 'atr-cleanup-table' );
		var rows = document.getElementById( 'atr-cleanup-rows' );
		var deleteBox = document.getElementById( 'atr-cleanup-delete-box' );
		var deleteButton = document.getElementById( 'atr-cleanup-delete' );
		var summary = document.getElementById( 'atr-cleanup-summary' );

		// One entry per image that has leftovers: { id, title, files: [names] }.
		var found = [];
		var stopped = false;

		function notice( type, message ) {
			result.innerHTML = '';

			var wrapper = document.createElement( 'div' );
			wrapper.className = 'notice notice-' + type + ' inline';

			var paragraph = document.createElement( 'p' );
			paragraph.textContent = message;

			wrapper.appendChild( paragraph );
			result.appendChild( wrapper );
		}

		function setBusy( busy ) {
			scanButton.disabled = busy;
			stopButton.hidden = ! busy;
			spinner.classList.toggle( 'is-active', busy );
		}

		function setProgress( done, total ) {
			var percent = total ? Math.round( ( done / total ) * 100 ) : 0;

			progress.hidden = false;
			bar.style.width = percent + '%';
			bar.setAttribute( 'aria-valuenow', String( percent ) );
		}

		function addRow( item, file ) {
			var row = document.createElement( 'tr' );

			var name = document.createElement( 'td' );
			name.className = 'column-primary';
			name.innerHTML = '<code></code>';
			name.firstChild.textContent = file.file;

			var image = document.createElement( 'td' );
			var link = document.createElement( 'a' );
			link.href = settings.editLink.replace( '%d', String( item.id ) );
			link.textContent = item.title || '#' + item.id;
			image.appendChild( link );

			var size = document.createElement( 'td' );
			size.textContent = formatBytes( file.bytes );

			row.appendChild( name );
			row.appendChild( image );
			row.appendChild( size );
			rows.appendChild( row );

			table.hidden = false;
		}

		scanButton.addEventListener( 'click', function() {
			stopped = false;
			found = [];
			rows.innerHTML = '';
			table.hidden = true;
			deleteBox.hidden = true;
			result.innerHTML = '';
			setBusy( true );
			status.textContent = __( 'Reading the media library…', 'ajax-thumbnail-rebuild' );

			apiFetch( { path: '/ajax-thumbnail-rebuild/v1/attachments' } ).then( function( response ) {
				if ( ! response.items.length ) {
					setBusy( false );
					progress.hidden = true;
					notice( 'warning', __( 'There are no images to scan.', 'ajax-thumbnail-rebuild' ) );
					return;
				}

				scan( response.items );
			} ).catch( function( error ) {
				setBusy( false );
				notice( 'error', error.message || __( 'The media library could not be read.', 'ajax-thumbnail-rebuild' ) );
			} );
		} );

		stopButton.addEventListener( 'click', function() {
			stopped = true;
		} );

		function scan( items ) {
			var index = 0;
			var bytes = 0;
			var files = 0;

			function finish( interrupted ) {
				setBusy( false );
				status.textContent = '';
				bar.classList.add( 'is-done' );

				if ( ! files ) {
					notice( 'success', __( 'Nothing to clean up: every file belongs to the image it is named after.', 'ajax-thumbnail-rebuild' ) );
					return;
				}

				var text = sprintf(
					/* translators: 1: number of files, 2: how much disk space they take. */
					_n( '%1$s leftover file, %2$s.', '%1$s leftover files, %2$s.', files, 'ajax-thumbnail-rebuild' ),
					files,
					formatBytes( bytes )
				);

				notice( interrupted ? 'warning' : 'info', interrupted ? __( 'Stopped.', 'ajax-thumbnail-rebuild' ) + ' ' + text : text );
				summary.textContent = text;
				deleteBox.hidden = false;
			}

			function step() {
				if ( stopped || index >= items.length ) {
					finish( stopped );
					return;
				}

				var item = items[ index ];

				setProgress( index, items.length );
				status.textContent = sprintf(
					/* translators: 1: current image number, 2: total images. */
					__( 'Scanning %1$s of %2$s', 'ajax-thumbnail-rebuild' ),
					index + 1,
					items.length
				);

				apiFetch( {
					path: '/ajax-thumbnail-rebuild/v1/cleanup/scan',
					method: 'POST',
					data: { id: item.id }
				} ).then( function( response ) {
					if ( response.files.length ) {
						found.push( {
							id: item.id,
							title: item.title,
							files: response.files.map( function( file ) {
								return file.file;
							} )
						} );

						response.files.forEach( function( file ) {
							addRow( item, file );
						} );

						files += response.files.length;
						bytes += response.bytes;
					}

					index++;
					setProgress( index, items.length );
					step();
				} ).catch( function() {
					// One unreadable image should not end the scan.
					index++;
					step();
				} );
			}

			step();
		}

		deleteButton.addEventListener( 'click', function() {
			if ( ! found.length || ! window.confirm( deleteButton.dataset.confirm ) ) {
				return;
			}

			deleteButton.disabled = true;
			spinner.classList.add( 'is-active' );

			var index = 0;
			var deleted = 0;
			var bytes = 0;

			function step() {
				if ( index >= found.length ) {
					spinner.classList.remove( 'is-active' );
					deleteBox.hidden = true;
					table.hidden = true;
					rows.innerHTML = '';
					found = [];
					deleteButton.disabled = false;

					notice( 'success', sprintf(
						/* translators: 1: number of files deleted, 2: how much disk space was freed. */
						_n( '%1$s file deleted, %2$s freed.', '%1$s files deleted, %2$s freed.', deleted, 'ajax-thumbnail-rebuild' ),
						deleted,
						formatBytes( bytes )
					) );
					return;
				}

				var entry = found[ index ];

				status.textContent = sprintf(
					/* translators: 1: current image number, 2: total images. */
					__( 'Deleting %1$s of %2$s', 'ajax-thumbnail-rebuild' ),
					index + 1,
					found.length
				);

				apiFetch( {
					path: '/ajax-thumbnail-rebuild/v1/cleanup/delete',
					method: 'POST',
					data: { id: entry.id, files: entry.files }
				} ).then( function( response ) {
					deleted += response.deleted;
					bytes += response.bytes;
					index++;
					step();
				} ).catch( function() {
					index++;
					step();
				} );
			}

			step();
		} );
	} );
} )( wp.apiFetch, wp.i18n, window.ATRSettings || { editLink: '' } );
