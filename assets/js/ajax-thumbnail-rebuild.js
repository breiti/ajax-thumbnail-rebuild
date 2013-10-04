jQuery( document ).ready(function($) {
	$( '#regenerate' ).click( function(event) {
		event.preventDefault();

		var thumbnails = '', inputs;

		$( "#ajax_thumbnail_rebuild" ).prop( "disabled", true );

		setMessage( "<p>" + ajaxthumbnail.reading_attachments + "</p>" );

		inputs	= $( 'input:checked' );

		if( inputs.length != $( 'input[type="checkbox"]' ).length ) {
			inputs.each(function( count, elem ) {
				thumbnails += '&thumbnails[]=' + $( elem ).val();
			});
		}

		var onlyfeatured	= $( '#onlyfeatured' ).prop( 'checked' ) ? 1 : 0;

		$.ajax({
			url: ajaxurl,
			type: "POST",
			data: {
				'action' : 'ajax_thumbnail_rebuild',
				'do' : 'getlist',
				'onlyfeatured' : onlyfeatured
			},
			success: function(result) {
				var list	= $.parseJSON( result );

				if ( !list ) {
					setMessage( ajaxthumbnail.no_attachments );
					$( "#ajax_thumbnail_rebuild" ).prop( "disabled", false );
					return;
				}

				function regenItem( list, curr ) {
					if ( curr >= list.length ) {
						$( "#ajax_thumbnail_rebuild" ).prop( "disabled", false );
						setMessage( ajaxthumbnail.done );
						return;
					}
					setMessage( sprintf( ajaxthumbnail.rebuilding, ( curr + 1 ), list.length, list[curr].title ) );

					$.ajax({
						url : ajaxurl,
						type : "POST",
						data: {
							'action' : 'ajax_thumbnail_rebuild',
							'do' : 'regen',
							'id' : list[curr].id + thumbnails
						},
						success: function(result) {
							$( "#thumb" ).show();
							$( "#thumb-img" ).attr( "src", result );

							regenItem( list, curr + 1 );
						}
					});
				}

				regenItem( list, 0 );
			},
			error: function(request, status, error) {
				setMessage( ajaxthumbnail.error_msg + request.status);
			}
		});
	});

	$( '#size-toggle' ).click( function(event) {
		event.preventDefault();

		var checkable, attribute;
		var checked		= $( '#sizeselect' ).find( 'input[type="checkbox"]:checked' );
		var unchecked	= $( '#sizeselect' ).find(' input[type="checkbox"]' ).not( ':checked' );

		if( checked.length > unchecked.length ) {
			checkable	= unchecked;
			attribute	= false;
		}
		else {
			checkable	= checked;
			attribute	= true;
		}

		if( checkable.length == 0 ) {
			$( '#sizeselect' ).find( 'input[type="checkbox"]' ).each( function( count, elem ) {
				$( elem ).prop( 'checked', attribute );
			});
		}
		else {
			checkable.each( function( count, elem ) {
				$( elem ).prop( 'checked', ! $( elem ).prop( 'checked' ) );
			});
		}
	});

	function setMessage( msg ) {
		$( "#message" ).html( msg );
		$( "#message" ).show();
	}
});