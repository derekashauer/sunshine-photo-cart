jQuery( document ).ready( function( $ ) {

	var searchTimeout;

	// Open Gallery Navigator modal
	$( document ).on( 'click', '#sunshine-gallery-navigator-open', function( e ) {
		e.preventDefault();
		$( '#sunshine-gallery-navigator-modal' ).fadeIn( 200 );
		$( 'body' ).addClass( 'sunshine-gallery-navigator-open' );

		// Load initial galleries if not already loaded
		if ( $( '#sunshine-gallery-navigator-list' ).is( ':empty' ) ) {
			loadGalleries( 0 );
		}
	});

	// Close modal
	$( document ).on( 'click', '.sunshine-gallery-navigator-close, .sunshine-gallery-navigator-overlay', function( e ) {
		e.preventDefault();
		$( '#sunshine-gallery-navigator-modal' ).fadeOut( 200 );
		$( 'body' ).removeClass( 'sunshine-gallery-navigator-open' );
	});

	// Close on Escape key
	$( document ).on( 'keydown', function( e ) {
		if ( e.key === 'Escape' && $( '#sunshine-gallery-navigator-modal' ).is( ':visible' ) ) {
			$( '.sunshine-gallery-navigator-close' ).trigger( 'click' );
		}
	});

	// Load galleries (root or specific parent)
	function loadGalleries( parentId, targetContainer ) {
		parentId = parentId || 0;
		targetContainer = targetContainer || $( '#sunshine-gallery-navigator-list' );

		targetContainer.html( '<div class="sunshine-gallery-navigator-loading"><span class="spinner is-active"></span></div>' );

		$.ajax({
			type: 'POST',
			url: sunshineGalleryNavigator.ajax_url,
			data: {
				action: 'sunshine_gallery_navigator_load',
				nonce: sunshineGalleryNavigator.nonce,
				parent_id: parentId
			},
			success: function( response ) {
				if ( response.success ) {
					targetContainer.html( response.data.html );
					initializeSortable( targetContainer );
				} else {
					var errorMsg = response.data.message || response.data || 'Error loading galleries';
					targetContainer.html( '<p class="sunshine-gallery-navigator-empty">' + errorMsg + '</p>' );
				}
			},
			error: function( xhr, status, error ) {
				console.error( 'Gallery Navigator Load Error:', error );
				console.error( 'Response:', xhr.responseText );
				targetContainer.html( '<p class="sunshine-gallery-navigator-empty">Error loading galleries. Check browser console for details.</p>' );
			}
		});
	}

	// Toggle children
	$( document ).on( 'click', '.sunshine-gallery-navigator-toggle', function( e ) {
		e.preventDefault();
		e.stopPropagation();

		var $button = $( this );
		var $item = $button.closest( '.sunshine-gallery-navigator-item' );
		var $children = $item.find( '> .sunshine-gallery-navigator-children' );
		var galleryId = $button.data( 'gallery-id' );
		var isExpanded = $button.attr( 'aria-expanded' ) === 'true';

		if ( isExpanded ) {
			// Collapse
			$children.slideUp( 200 );
			$button.attr( 'aria-expanded', 'false' );
			$button.find( '.dashicons' ).removeClass( 'dashicons-arrow-down' ).addClass( 'dashicons-arrow-right' );
			$item.removeClass( 'expanded' );
		} else {
			// Expand
			if ( $children.is( ':empty' ) ) {
				// Load children via AJAX
				loadGalleries( galleryId, $children );
			}
			$children.slideDown( 200 );
			$button.attr( 'aria-expanded', 'true' );
			$button.find( '.dashicons' ).removeClass( 'dashicons-arrow-right' ).addClass( 'dashicons-arrow-down' );
			$item.addClass( 'expanded' );
		}

		return false;
	});

	// Search functionality with debounce
	$( document ).on( 'input', '#sunshine-gallery-navigator-search-input', function() {
		var searchTerm = $( this ).val().trim();

		clearTimeout( searchTimeout );

		if ( searchTerm.length === 0 ) {
			$( '#sunshine-gallery-navigator-search-clear' ).hide();
			// Reload root galleries
			searchTimeout = setTimeout( function() {
				loadGalleries( 0 );
			}, 300 );
			return;
		}

		$( '#sunshine-gallery-navigator-search-clear' ).show();

		// Debounce search
		searchTimeout = setTimeout( function() {
			performSearch( searchTerm );
		}, 500 );
	});

	// Clear search
	$( document ).on( 'click', '#sunshine-gallery-navigator-search-clear', function( e ) {
		e.preventDefault();
		$( '#sunshine-gallery-navigator-search-input' ).val( '' ).trigger( 'input' );
	});

	// Perform search
	function performSearch( searchTerm ) {
		var $container = $( '#sunshine-gallery-navigator-list' );
		$container.html( '<div class="sunshine-gallery-navigator-loading"><span class="spinner is-active"></span></div>' );

		$.ajax({
			type: 'POST',
			url: sunshineGalleryNavigator.ajax_url,
			data: {
				action: 'sunshine_gallery_navigator_load',
				nonce: sunshineGalleryNavigator.nonce,
				search: searchTerm
			},
			success: function( response ) {
				if ( response.success ) {
					$container.html( response.data.html );
					// Don't initialize sortable for search results
				} else {
					var errorMsg = response.data.message || response.data || 'Error performing search';
					$container.html( '<p class="sunshine-gallery-navigator-empty">' + errorMsg + '</p>' );
				}
			},
			error: function( xhr, status, error ) {
				console.error( 'Gallery Navigator Search Error:', error );
				console.error( 'Response:', xhr.responseText );
				$container.html( '<p class="sunshine-gallery-navigator-empty">Error performing search. Check browser console for details.</p>' );
			}
		});
	}

	// Initialize sortable for drag and drop
	function initializeSortable( $container ) {
		$container.find( '.sunshine-gallery-navigator-items' ).each( function() {
			var $list = $( this );

			$list.sortable({
				handle: '.sunshine-gallery-navigator-drag-handle',
				items: '> .sunshine-gallery-navigator-item',
				placeholder: 'sunshine-gallery-navigator-placeholder',
				cursor: 'move',
				opacity: 0.8,
				tolerance: 'pointer',
				update: function( event, ui ) {
					saveOrder( $list );
				},
				start: function( event, ui ) {
					ui.placeholder.height( ui.item.height() );
				}
			});
		});
	}

	// Save new order via AJAX
	function saveOrder( $list ) {
		var order = [];
		var parentId = $list.data( 'parent-id' ) || 0;

		$list.find( '> .sunshine-gallery-navigator-item' ).each( function() {
			order.push( $( this ).data( 'gallery-id' ) );
		});

		// Show saving indicator
		var $saveIndicator = $( '<span class="sunshine-gallery-navigator-saving">Saving...</span>' );
		$list.before( $saveIndicator );

		$.ajax({
			type: 'POST',
			url: sunshineGalleryNavigator.ajax_url,
			data: {
				action: 'sunshine_gallery_navigator_reorder',
				nonce: sunshineGalleryNavigator.nonce,
				order: order,
				parent_id: parentId
			},
			success: function( response ) {
				$saveIndicator.text( 'Saved!' ).addClass( 'success' );
				setTimeout( function() {
					$saveIndicator.fadeOut( 300, function() {
						$( this ).remove();
					});
				}, 1500 );
			},
			error: function() {
				$saveIndicator.text( 'Error saving order' ).addClass( 'error' );
				setTimeout( function() {
					$saveIndicator.fadeOut( 300, function() {
						$( this ).remove();
					});
				}, 3000 );
			}
		});
	}

	// Re-initialize sortable when children are loaded
	$( document ).on( 'DOMNodeInserted', '.sunshine-gallery-navigator-children', function() {
		if ( $( this ).find( '.sunshine-gallery-navigator-items' ).length ) {
			initializeSortable( $( this ) );
		}
	});

});

