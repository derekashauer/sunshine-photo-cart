/**
 * Image and file upload fields, used by Sunshine settings and the gallery editor.
 *
 * Markup: a button with id "{field}_button", a hidden input with id "{field}",
 * a "{field}_preview" box for images or "{field}_file" for files, and a "{field}_delete" button.
 */
jQuery( document ).ready( function( $ ) {

	function open_media( button, preview_media ) {
		var field_id = button.attr( 'id' ).replace( /_button$/, '' );

		// One media window per field, so picking an image for one field never fills another.
		var frame = button.data( 'sunshine_media_frame' );
		if ( ! frame ) {
			frame = wp.media( {
				title: button.data( 'uploader_title' ),
				button: {
					text: button.data( 'uploader_button_text' )
				},
				library: {
					type: button.data( 'media_type' )
				},
				multiple: false
			} );

			frame.on( 'select', function() {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				$( '#' + field_id ).val( attachment.id );
				if ( preview_media ) {
					var preview = $( '#' + field_id + '_preview' );
					if ( ! preview.find( 'img' ).length ) {
						$( '<img />' ).appendTo( preview );
					}
					var size = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium : attachment.sizes.full;
					preview.find( 'img' ).attr( 'src', size.url );
				} else {
					$( '#' + field_id + '_file' ).html( $( '<a target="_blank" />' ).attr( 'href', attachment.url ).text( attachment.filename ) );
				}
				$( '#' + field_id + '_delete' ).show();
			} );

			button.data( 'sunshine_media_frame', frame );
		}

		frame.open();
	}

	$( document ).on( 'click', '.image_upload_button', function( event ) {
		event.preventDefault();
		open_media( $( this ), true );
	} );

	$( document ).on( 'click', '.file_upload_button', function( event ) {
		event.preventDefault();
		open_media( $( this ), false );
	} );

	$( document ).on( 'click', '.image_delete_button', function( event ) {
		event.preventDefault();
		var field_id = $( this ).data( 'field' );
		$( '#' + field_id ).val( '' );
		$( '#' + field_id + '_preview img' ).remove();
		$( this ).hide();
	} );

	$( document ).on( 'click', '.file_delete_button', function( event ) {
		event.preventDefault();
		var field_id = $( this ).data( 'field' );
		$( '#' + field_id ).val( '' );
		$( '#' + field_id + '_file' ).html( '' );
		$( this ).hide();
	} );

} );
