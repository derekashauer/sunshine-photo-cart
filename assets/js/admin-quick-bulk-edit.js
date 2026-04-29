( function ( $ ) {
	'use strict';

	if ( typeof inlineEditPost === 'undefined' || ! inlineEditPost.edit ) {
		return;
	}

	var qbe = ( typeof window.sunshineQBE !== 'undefined' ) ? window.sunshineQBE : { ajaxUrl: window.ajaxurl, noChange: '__sunshine_no_change__' };

	var originalEdit = inlineEditPost.edit;
	inlineEditPost.edit = function ( id ) {
		originalEdit.apply( this, arguments );

		var postId = 0;
		if ( typeof id === 'object' ) {
			postId = parseInt( this.getId( id ), 10 );
		}
		if ( ! postId ) {
			return;
		}
		var $row     = $( '#post-' + postId );
		var $editRow = $( '#edit-' + postId );
		var $form    = $editRow.find( '.inline-edit-sunshine-qbe' );
		if ( ! $form.length ) {
			return;
		}
		populateFromRow( $form, $row );
		initUsersSelects( $form );
		applyConditions( $form );
	};

	if ( typeof inlineEditPost.setBulk === 'function' ) {
		var originalSetBulk = inlineEditPost.setBulk;
		inlineEditPost.setBulk = function () {
			originalSetBulk.apply( this, arguments );
			var $form = $( '#bulk-edit .inline-edit-sunshine-qbe' );
			if ( ! $form.length ) {
				return;
			}
			initUsersSelects( $form );
			applyConditions( $form );
		};
	}

	function populateFromRow( $form, $row ) {
		$row.find( '.sunshine-qbe-data span' ).each( function () {
			var $span     = $( this );
			var fieldName = $span.attr( 'data-field' );
			if ( ! fieldName ) {
				return;
			}
			if ( $span.is( '[data-users]' ) ) {
				populateUsersField( $form, fieldName, $span.attr( 'data-users' ) );
			} else {
				populateField( $form, fieldName, $span.attr( 'data-value' ) );
			}
		} );
	}

	function populateField( $form, fieldName, value ) {
		var selector = '[name="' + fieldName.replace( /"/g, '\\"' ) + '"]';
		var $inputs  = $form.find( selector );
		if ( ! $inputs.length ) {
			return;
		}
		var first = $inputs.get( 0 );
		if ( first.type === 'checkbox' ) {
			$inputs.prop( 'checked', value === '1' );
		} else if ( first.type === 'radio' ) {
			$inputs.each( function () {
				$( this ).prop( 'checked', this.value === value );
			} );
		} else {
			$inputs.val( value );
		}
	}

	function populateUsersField( $form, fieldName, jsonData ) {
		var $select = $form.find( 'select[name="' + fieldName + '[]"]' );
		if ( ! $select.length ) {
			return;
		}
		try {
			var users = jsonData ? JSON.parse( jsonData ) : [];
			$select.empty();
			$.each( users, function ( i, u ) {
				$select.append( new Option( u.text, u.id, true, true ) );
			} );
			if ( $select.data( 'select2' ) ) {
				$select.trigger( 'change' );
			}
		} catch ( e ) {
			// ignore malformed json
		}
	}

	function initUsersSelects( $form ) {
		if ( typeof $.fn.select2 !== 'function' ) {
			return;
		}
		$form.find( '.sunshine-qbe-users' ).each( function () {
			var $select = $( this );
			if ( $select.data( 'select2' ) ) {
				$select.select2( 'destroy' );
			}
			$select.select2( {
				width: '100%',
				placeholder: $select.data( 'placeholder' ) || '',
				allowClear: true,
				ajax: {
					url: qbe.ajaxUrl,
					dataType: 'json',
					delay: 250,
					data: function ( params ) {
						return {
							action: 'sunshine_search_users',
							search: params.term,
							security: $select.data( 'search-nonce' )
						};
					},
					processResults: function ( data ) {
						return { results: data };
					}
				},
				minimumInputLength: 3
			} );
		} );
	}

	function applyConditions( $form ) {
		var statusValue = readFieldValue( $form, 'status' );
		toggleRow( $form, 'password', statusValue === 'password' );
		toggleRow( $form, 'password_hint', statusValue === 'password' );
		toggleRow( $form, 'private_users', statusValue === 'private' );

		var commentsOn = readBoolFieldValue( $form, 'image_comments' );
		toggleRow( $form, 'image_comments_approval', commentsOn );

		var disableShipping = readBoolFieldValue( $form, 'disable_shipping' );
		toggleRow( $form, 'shipping', ! disableShipping );

		var productsDisabled = readBoolFieldValue( $form, 'disable_products' );
		toggleRow( $form, 'price_level', ! productsDisabled );
	}

	function readFieldValue( $form, fieldId ) {
		var $field = $form.find( '[name="' + fieldId + '"]' );
		if ( ! $field.length ) {
			return null;
		}
		if ( $field.is( 'select' ) ) {
			return $field.val();
		}
		return $form.find( '[name="' + fieldId + '"]:checked' ).val();
	}

	function readBoolFieldValue( $form, fieldId ) {
		var $field = $form.find( '[name="' + fieldId + '"]' );
		if ( ! $field.length ) {
			return false;
		}
		if ( $field.is( 'select' ) ) {
			return $field.val() === '1';
		}
		return $form.find( 'input[name="' + fieldId + '"][type="checkbox"]' ).is( ':checked' );
	}

	function toggleRow( $form, fieldId, show ) {
		var $row = $form.find( '[data-sunshine-qbe-field="' + fieldId + '"]' );
		if ( show ) {
			$row.show();
		} else {
			$row.hide();
		}
	}

	$( document ).on( 'change', '.inline-edit-sunshine-qbe input, .inline-edit-sunshine-qbe select', function () {
		var $form = $( this ).closest( '.inline-edit-sunshine-qbe' );
		if ( $form.length ) {
			applyConditions( $form );
		}
	} );

} )( jQuery );
