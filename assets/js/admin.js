function sunshine_generate_string( length = 8 ) {
    const list = "ABCDEFGHIJKLMNPQRSTUVWXYZ123456789";
    var res = "";
    for(var i = 0; i < length; i++) {
        var rnd = Math.floor(Math.random() * list.length);
        res = res + list.charAt(rnd);
    }
    return res;
}

jQuery( '#sunshine-discount-code-generate, #sunshine-gallery-password-generate' ).on( 'click', function(){
	var code = sunshine_generate_string();
	jQuery( this ).siblings( 'input' ).val( code );
});

jQuery( document ).ready(function($) {

	// Prevent default price level from being deleted.
    $( 'div.sunshine--default-term' ).each(function() {
        $( this ).closest( 'tr' ).find( 'input[type="checkbox"]' ).remove();
    });

	// Add-ons.
	$( '#sunshine--addons.free input[name="addon"], #sunshine--addons.plus input[name="addon"].pro' ).on( 'change', function( event ){

		$( '.sunshine--addon--upgrade-modal' ).hide();

		// Open sales popup.
		var slug = $( this ).val();
		$( '#sunshine--addon--upgrade-modal--' + slug ).show();

		event.preventDefault();
		$( this ).prop('checked', !$( this ).prop( 'checked' ) );

		return false;
	});

	// Add-ons.
	$( '.sunshine--addon--needs-upgrade' ).on( 'click', function( event ){
		// Open sales popup.
		var slug = $( this ).data( 'addon' );
		$( '#sunshine--addon--upgrade-modal--' + slug ).show();
		event.preventDefault();
		return false;
	});


	$( '.sunshine--addons--upgrade-modal--overlay, .sunshine--addons--upgrade-modal--close' ).on( 'click', function() {
		$( '.sunshine--addons--upgrade-modal' ).hide();
	});

    $( '#sunshine--addons.pro input[name="addon"].plus, #sunshine--addons.pro input[name="addon"].pro, #sunshine--addons.plus input[name="addon"].plus' ).on( 'change', function(){
        $( '.sunshine--addon--error' ).remove();
        var addon = $( this );
        $( this ).parent( '.sunshine-switch' ).addClass( 'sunshine-loading' );
        var data = {
            'action': 'sunshine_addon_toggle',
            'addon': $( this ).val(),
            'addon_security': sunshine_admin.addon_security
        };
        $.post( ajaxurl, data, function( response ) {
            if ( response.data.status == 'active' ) {
                addon.prop( 'checked', true );
            } else {
                addon.prop( 'checked', false );
                if ( response.data.reason ) {
                    addon.closest( '.sunshine--addon--actions' ).append( '<div class="sunshine--addon--error">' + response.data.reason + '</div>' );
                    setTimeout(function() {
                      // Fade out and remove the element
                      $('.sunshine--addon--error').fadeOut(500, function() {
                        $(this).remove();
                      });
                    }, 5000);
                }
            }
            addon.parent( '.sunshine-switch' ).removeClass( 'sunshine-loading' );
        });
    });

	if ( ! $( 'body' ).hasClass( 'block-editor-page' ) ) {
		$( '.sunshine-admin-meta-box-tabs input' ).removeAttr( 'required' );
	}

	$( '.sunshine-notice.is-dismissible .notice-dismiss, .sunshine-notice.is-dismissible .notice-dismiss-button, .sunshine-in-app-promo.is-dismissible .notice-dismiss, .sunshine-in-app-promo.is-dismissible .notice-dismiss-button' ).on( 'click', function(){
		var notice = $( this ).closest( '.sunshine-notice, .sunshine-in-app-promo' );
		var data = {
            'action': 'sunshine_notice_dismiss',
            'notice': notice.data( 'notice' )
        };
        $.post( ajaxurl, data, function( response ) {
			if ( response.success ) {
				notice.hide();
			}
        });
	});

	$( '.sunshine--tabs--menu a' ).on( 'click', function(){
		$( '.sunshine--tabs--menu a, .sunshine--tabs--content' ).removeClass( 'active' )
		$( this ).addClass( 'active' );
		var active_tab = $( this ).attr( 'href' );
		$( active_tab ).addClass( 'active' );
		return false;
	});

	$( '.sunshine-tooltip' ).tooltip( {
		position: {
			my: 'left top+5',
			at: 'left bottom'
		},
		show: {
			duration: 200
		},
		hide: {
			duration: 200
		},
		classes: {
			'ui-tooltip': 'sunshine-tooltip-ui'
		}
	} );


});
