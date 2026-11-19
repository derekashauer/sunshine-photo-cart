/**
 * Photo protection.
 *
 * Shows the photographer's notice when someone tries to take a screenshot, save or print,
 * blurs part of each photo on touch screens, and reports each attempt so it can be recorded.
 * A website can't truly stop a screenshot; this tells people the photos are protected.
 */
( function () {

	/**
	 * The mask that decides which part of a photo stays sharp. Shared with the settings preview.
	 *
	 * @param {string} type   radial, vertical or diagonal.
	 * @param {number} amount Percent of the photo that is blurred.
	 * @param {number} x      Where the sharp part is, as a percent across.
	 * @param {number} y      Where the sharp part is, as a percent down.
	 * @return {string} A CSS mask-image value. Clear parts of the mask stay sharp.
	 */
	function blurMask( type, amount, x, y ) {
		var sharp = ( 100 - amount ) / 100;
		if ( 'radial' === type ) {
			// An oval the photo's shape that holds the sharp share of the photo, with a soft edge.
			var size = Math.sqrt( sharp / Math.PI ) * 100 / 0.85;
			return 'radial-gradient(ellipse ' + size + '% ' + size + '% at ' + x + '% ' + y + '%, transparent 70%, #000 100%)';
		}
		// A sharp strip across the photo, centered where the visitor tapped, so every part can be seen.
		var diagonal = ( 'diagonal' === type );
		var center = diagonal ? ( x + y ) / 200 : x / 100;
		// How much of the photo lies before a point on the line: straight for one side, and
		// growing with the square of the distance from a corner for a corner-to-corner line.
		var area = function ( t ) {
			t = Math.min( 1, Math.max( 0, t ) );
			if ( ! diagonal ) {
				return t;
			}
			return t <= 0.5 ? 2 * t * t : 1 - 2 * ( 1 - t ) * ( 1 - t );
		};
		// Widen the strip around the tap until it holds the sharp share of the photo.
		var low = 0, high = 1, half = 0.5;
		for ( var i = 0; i < 30; i++ ) {
			half = ( low + high ) / 2;
			if ( area( center + half ) - area( center - half ) < sharp ) {
				low = half;
			} else {
				high = half;
			}
		}
		var start = Math.max( 0, center - half ) * 100;
		var end = Math.min( 1, center + half ) * 100;
		var stops = [];
		if ( start > 0 ) {
			stops.push( '#000 ' + Math.max( 0, start - 5 ) + '%', 'transparent ' + ( start + 5 ) + '%' );
		} else {
			stops.push( 'transparent 0%' );
		}
		if ( end < 100 ) {
			stops.push( 'transparent ' + ( end - 5 ) + '%', '#000 ' + Math.min( 100, end + 5 ) + '%' );
		} else {
			stops.push( 'transparent 100%' );
		}
		return 'linear-gradient(' + ( diagonal ? 135 : 90 ) + 'deg, ' + stops.join( ', ' ) + ')';
	}

	/**
	 * Start position of the sharp part: the middle for radial, the left or top-left otherwise.
	 */
	function blurStart( type ) {
		return ( 'radial' === type ) ? [ 50, 50 ] : [ 0, 0 ];
	}

	/**
	 * Move the sharp part of a photo to where it was tapped or clicked.
	 */
	function moveBlur( element, event, type, amount ) {
		var rect = element.getBoundingClientRect();
		if ( ! rect.width || ! rect.height ) {
			return;
		}
		var x = Math.round( ( event.clientX - rect.left ) / rect.width * 100 );
		var y = Math.round( ( event.clientY - rect.top ) / rect.height * 100 );
		element.style.setProperty( '--sunshine-blur-mask', blurMask( type, amount, x, y ) );
	}

	window.sunshineProtection = {
		blurMask: blurMask,
		blurStart: blurStart,
		moveBlur: moveBlur
	};

	var config = window.sunshine_protection;
	if ( ! config ) {
		return;
	}

	var root = document.documentElement;
	var notice = document.getElementById( 'sunshine--protection-notice' );
	var lastReported = {};
	// Whether the last attempt of each kind was really saved, so the notice only says so when it was.
	var recorded = {};
	var hoveredImageId = 0;
	var lastPointerType = '';
	// The photo showing in the Lightbox add-on's popup, which it announces on every slide.
	var lightboxImageId = 0;
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'sunshine_lightbox_image', function ( event, image ) {
			lightboxImageId = ( image && parseInt( image.image_id, 10 ) ) || 0;
		} );
	}

	/**
	 * The photo an element belongs to: a gallery thumbnail, the photo in the Lightbox, or the
	 * photo on its own page.
	 */
	function imageIdFrom( element ) {
		if ( element && element.closest && element.closest( '.lg-container' ) && lightboxImageId ) {
			return lightboxImageId;
		}
		var item = ( element && element.closest ) ? element.closest( '.sunshine--image-item' ) : null;
		if ( item && item.id ) {
			return parseInt( item.id.replace( 'sunshine--image-', '' ), 10 ) || 0;
		}
		return parseInt( config.image_id, 10 ) || 0;
	}

	/**
	 * Send an attempt to be recorded. The same kind is sent at most once every few seconds,
	 * since holding keys down repeats them.
	 */
	function report( type, imageId ) {
		if ( ! parseInt( config.record, 10 ) || ! window.fetch ) {
			return;
		}
		var now = Date.now();
		if ( lastReported[ type ] && now - lastReported[ type ] < 5000 ) {
			showRecorded( recorded[ type ] );
			return;
		}
		lastReported[ type ] = now;
		recorded[ type ] = false;
		showRecorded( false );

		var data = new FormData();
		data.append( 'action', 'sunshine_protection_attempt' );
		data.append( 'security', config.security );
		data.append( 'type', type );
		data.append( 'gallery_id', config.gallery_id );
		// On a photo's own page, that photo is the one, even if the mouse hasn't moved over it.
		data.append( 'image_id', imageId || parseInt( config.image_id, 10 ) || 0 );
		// keepalive lets the request finish while the page prints or closes.
		fetch( config.ajax_url, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				recorded[ type ] = !! ( result && result.success );
				showRecorded( recorded[ type ] );
			} )
			.catch( function () {} );
	}

	/**
	 * The "This attempt has been recorded" line, shown only once the server saved it.
	 */
	function showRecorded( on ) {
		if ( notice ) {
			notice.classList.toggle( 'sunshine--recorded', !! on );
		}
	}

	function showNotice( type, imageId ) {
		report( type, imageId );
		if ( ! notice || notice.classList.contains( 'sunshine--open' ) ) {
			return;
		}
		notice.classList.add( 'sunshine--open' );
		root.classList.add( 'sunshine--protection-notice-open' );
		var close = document.getElementById( 'sunshine--protection-notice--close' );
		if ( close ) {
			close.focus();
		}
	}

	function hideNotice() {
		if ( ! notice ) {
			return;
		}
		notice.classList.remove( 'sunshine--open' );
		root.classList.remove( 'sunshine--protection-notice-open' );
		cover( false );
	}

	/**
	 * Blur every photo right away, while a screenshot may be about to happen.
	 */
	function cover( on ) {
		root.classList.toggle( 'sunshine--protection-cover', on );
	}

	if ( notice ) {
		document.getElementById( 'sunshine--protection-notice--close' ).addEventListener( 'click', hideNotice );
	}

	document.addEventListener( 'mouseover', function ( event ) {
		hoveredImageId = imageIdFrom( event.target );
	} );
	document.addEventListener( 'pointerdown', function ( event ) {
		lastPointerType = event.pointerType || '';
	}, true );

	/* Keys */

	// Cmd+Shift (Mac) or Windows+Shift is held, and the page hasn't seen the next key. The
	// screenshot keys that follow usually go straight to the computer, so this is all the page gets.
	var screenshotModifiers = false;

	function isScreenshotKeys( event ) {
		var code = event.code || '';
		if ( 'PrintScreen' === event.key || 'PrintScreen' === code ) {
			return true;
		}
		// Cmd+Shift+3/4/5/6 on a Mac, Windows+Shift+S on Windows.
		return event.metaKey && event.shiftKey && [ 'Digit3', 'Digit4', 'Digit5', 'Digit6', 'KeyS' ].indexOf( code ) > -1;
	}

	function isModifierKey( key ) {
		return [ 'Control', 'Alt', 'Meta', 'Shift' ].indexOf( key ) > -1;
	}

	// Ctrl+A, Ctrl+V and the like in a form field shouldn't blur the photos.
	function isTyping( target ) {
		return target && ( target.isContentEditable || [ 'INPUT', 'TEXTAREA', 'SELECT' ].indexOf( target.tagName ) > -1 );
	}

	function blockedKeysType( event ) {
		var code = event.code || '';
		var key = ( event.key || '' ).toLowerCase();
		var command = event.metaKey || event.ctrlKey;
		if ( isScreenshotKeys( event ) ) {
			return 'screenshot';
		}
		if ( command && ! event.shiftKey && ! event.altKey && 'KeyS' === code ) {
			return 'save';
		}
		if ( command && ! event.shiftKey && ! event.altKey && 'KeyP' === code ) {
			return 'print';
		}
		if ( 'f12' === key ) {
			return 'devtools';
		}
		if ( event.ctrlKey && event.shiftKey && [ 'KeyI', 'KeyJ', 'KeyC' ].indexOf( code ) > -1 ) {
			return 'devtools';
		}
		if ( event.metaKey && event.altKey && [ 'KeyI', 'KeyJ', 'KeyC', 'KeyU' ].indexOf( code ) > -1 ) {
			return 'devtools';
		}
		if ( event.ctrlKey && ! event.shiftKey && 'KeyU' === code ) {
			return 'devtools';
		}
		return '';
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && notice && notice.classList.contains( 'sunshine--open' ) ) {
			hideNotice();
			return;
		}
		if ( 'Meta' === event.key || 'Shift' === event.key ) {
			if ( event.metaKey && event.shiftKey ) {
				screenshotModifiers = true;
				cover( true );
				return;
			}
		}
		if ( isModifierKey( event.key ) ) {
			// Ctrl, Alt, Windows or Cmd on its own. Screenshot shortcuts start with one of these
			// (Ctrl+PrtSc, Alt+PrtSc, Windows+Shift+S), and the key that follows often never
			// reaches the page, so blur while it's held. Nothing is recorded for this.
			if ( 'Shift' !== event.key && ! isTyping( event.target ) ) {
				cover( true );
			}
			return;
		}
		screenshotModifiers = false;
		var type = blockedKeysType( event );
		if ( type ) {
			event.preventDefault();
			showNotice( type, hoveredImageId );
		} else if ( ! event.ctrlKey && ! event.altKey && ! event.metaKey ) {
			// Stays blurred while Ctrl, Alt, Windows or Cmd is still held, since a screenshot key can still follow.
			cover( false );
		}
	}, true );

	document.addEventListener( 'keyup', function ( event ) {
		if ( 'PrintScreen' === event.key || 'PrintScreen' === event.code ) {
			// Windows copies the screenshot to the clipboard, so replace it where the browser allows.
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( '' ).catch( function () {} );
			}
			showNotice( 'screenshot', hoveredImageId );
			return;
		}
		if ( isScreenshotKeys( event ) ) {
			showNotice( 'screenshot', hoveredImageId );
			return;
		}
		if ( ( 'Meta' === event.key || 'Shift' === event.key ) && screenshotModifiers ) {
			screenshotModifiers = false;
			screenshotModifiersReleased();
			return;
		}
		if ( isModifierKey( event.key ) && ! event.ctrlKey && ! event.altKey && ! event.metaKey && ( ! notice || ! notice.classList.contains( 'sunshine--open' ) ) ) {
			cover( false );
		}
	}, true );

	/**
	 * Cmd+Shift or Windows+Shift was let go without the page seeing another key, so the computer
	 * took that key. Switching windows or tabs takes the page out of focus; a screenshot doesn't.
	 */
	function screenshotModifiersReleased() {
		setTimeout( function () {
			if ( document.hasFocus() && 'visible' === document.visibilityState ) {
				showNotice( 'screenshot', hoveredImageId );
			} else {
				cover( false );
			}
		}, 300 );
	}

	// A screenshot tool that takes focus swallows the key release, so the page decides when it
	// gets focus back: held screenshot keys and then a lost focus count as a screenshot.
	window.addEventListener( 'focus', function () {
		if ( screenshotModifiers ) {
			screenshotModifiers = false;
			showNotice( 'screenshot', hoveredImageId );
		} else if ( ! notice || ! notice.classList.contains( 'sunshine--open' ) ) {
			cover( false );
		}
	} );

	/* Right-click, press-and-hold and dragging */

	document.addEventListener( 'contextmenu', function ( event ) {
		event.preventDefault();
		if ( event.target && 'IMG' === event.target.tagName ) {
			showNotice( 'touch' === lastPointerType ? 'long_press' : 'right_click', imageIdFrom( event.target ) );
		}
	}, true );

	document.addEventListener( 'dragstart', function ( event ) {
		if ( event.target && 'IMG' === event.target.tagName ) {
			event.preventDefault();
		}
	}, true );

	/* Printing from the browser menu. The page prints the notice instead of the photos. */

	window.addEventListener( 'beforeprint', function () {
		report( 'print', hoveredImageId );
	} );

	/* Blur while the page is in the background */

	if ( parseInt( config.focus_blur, 10 ) ) {
		var away = function () {
			// Clicking into an embedded frame, like a video, also takes focus from the page.
			setTimeout( function () {
				var active = document.activeElement;
				if ( active && 'IFRAME' === active.tagName ) {
					return;
				}
				root.classList.add( 'sunshine--protection-away' );
			}, 0 );
		};
		var back = function () {
			root.classList.remove( 'sunshine--protection-away' );
		};
		window.addEventListener( 'blur', away );
		window.addEventListener( 'focus', back );
		document.addEventListener( 'visibilitychange', function () {
			if ( 'hidden' === document.visibilityState ) {
				away();
			} else if ( document.hasFocus() ) {
				back();
			}
		} );
	}

	/* Part of each photo blurred on touch screens */

	var blur = config.blur || {};
	var touchScreen = window.matchMedia && window.matchMedia( '(hover: none) and (pointer: coarse)' ).matches;
	var display = document.getElementById( 'sunshine--image--content--display' );
	var blurGrid = parseInt( blur.grid, 10 ) && document.getElementById( 'sunshine--image-items' );
	var blurSingle = parseInt( blur.single, 10 ) && display;
	// The Lightbox add-on shows one photo at a time, so it follows the photo page setting. Its
	// popup is added after this script runs, so the layer is set up whenever the setting is on.
	var blurLightbox = parseInt( blur.single, 10 );

	if ( touchScreen && ( blurGrid || blurSingle || blurLightbox ) ) {
		var amount = parseInt( blur.amount, 10 ) || 50;
		var start = blurStart( blur.type );
		root.style.setProperty( '--sunshine-blur-mask', blurMask( blur.type, amount, start[0], start[1] ) );
		if ( blurGrid ) {
			root.classList.add( 'sunshine--blur-grid' );
		}
		if ( blurSingle ) {
			root.classList.add( 'sunshine--blur-single' );
			display.addEventListener( 'pointerdown', function ( event ) {
				moveBlur( display, event, blur.type, amount );
			} );
		}

		if ( blurLightbox ) {
			blurLightboxPhotos( blur.type, amount );
		}

		var banner = document.getElementById( 'sunshine--protection-banner' );
		// Right above the photos, so it lines up with them in any theme.
		var photos = document.getElementById( 'sunshine--image-items' ) || document.getElementById( 'sunshine--image--content' );
		var dismissed = false;
		try {
			dismissed = !! window.sessionStorage.getItem( 'sunshine_protection_banner' );
		} catch ( e ) {}
		// Only where the page's own photos are blurred, not for the Lightbox alone.
		if ( banner && photos && ! dismissed && ( blurGrid || blurSingle ) ) {
			photos.parentNode.insertBefore( banner, photos );
			banner.classList.add( 'sunshine--open' );
			document.getElementById( 'sunshine--protection-banner--close' ).addEventListener( 'click', function () {
				banner.classList.remove( 'sunshine--open' );
				try {
					window.sessionStorage.setItem( 'sunshine_protection_banner', '1' );
				} catch ( e ) {}
			} );
		}
	}

	/**
	 * The Lightbox's photo sits in wrappers that fill the screen, so a blur layer is laid exactly
	 * over each photo and kept there as photos load, the screen turns or the photo zooms.
	 * Tapping the photo moves the blur, like on a photo's own page.
	 */
	function blurLightboxPhotos( type, amount ) {
		function layerFor( img ) {
			var layer = img.nextElementSibling;
			if ( ! layer || ! layer.classList.contains( 'sunshine--protection-blur-layer' ) ) {
				layer = document.createElement( 'span' );
				layer.className = 'sunshine--protection-blur-layer';
				img.parentNode.insertBefore( layer, img.nextSibling );
			}
			return layer;
		}
		function place( img ) {
			if ( ! img.offsetWidth ) {
				return;
			}
			var layer = layerFor( img );
			var style = window.getComputedStyle( img );
			layer.style.left = img.offsetLeft + 'px';
			layer.style.top = img.offsetTop + 'px';
			layer.style.width = img.offsetWidth + 'px';
			layer.style.height = img.offsetHeight + 'px';
			layer.style.transform = style.transform;
			layer.style.transformOrigin = style.transformOrigin;
		}
		function placeAll() {
			document.querySelectorAll( '.lg-container img.lg-object' ).forEach( place );
		}
		document.addEventListener( 'load', function ( event ) {
			if ( event.target.matches && event.target.matches( '.lg-container img.lg-object' ) ) {
				place( event.target );
			}
		}, true );
		document.addEventListener( 'transitionend', placeAll, true );
		window.addEventListener( 'resize', placeAll );
		// A swipe to the next photo also starts with a touch, so only a tap moves the blur.
		document.addEventListener( 'click', function ( event ) {
			var img = event.target.closest && event.target.closest( '.lg-container picture' );
			img = img ? img.querySelector( 'img.lg-object' ) : null;
			if ( img ) {
				moveBlur( layerFor( img ), event, type, amount );
			}
		}, true );
	}

} )();
