/**
 * Prompt on phones and tablets to add the gallery to the home screen.
 * Android browsers get an Add button; iPhone and iPad get the steps, since Safari has no button for it.
 */
( function() {

	var prompt = document.getElementById( 'sunshine--app-prompt' );
	if ( ! prompt ) {
		return;
	}

	// Already opened from the home screen.
	if ( window.matchMedia( '(display-mode: standalone)' ).matches || window.navigator.standalone ) {
		return;
	}

	var storage_key = 'sunshine_app_prompt_closed_' + prompt.getAttribute( 'data-gallery' );
	try {
		if ( window.localStorage.getItem( storage_key ) ) {
			return;
		}
	} catch ( e ) {}

	var add_button   = document.getElementById( 'sunshine--app-prompt--add' );
	var steps        = document.getElementById( 'sunshine--app-prompt--steps' );
	var install_event = null;

	function show() {
		prompt.hidden = false;
		document.body.classList.add( 'sunshine--app-prompt-open' );
		// Lets the favorites tray sit above the prompt.
		document.body.style.setProperty( '--sunshine--app-prompt-height', prompt.offsetHeight + 'px' );
	}

	function hide() {
		prompt.hidden = true;
		document.body.classList.remove( 'sunshine--app-prompt-open' );
	}

	document.getElementById( 'sunshine--app-prompt--close' ).addEventListener( 'click', function() {
		hide();
		try {
			window.localStorage.setItem( storage_key, '1' );
		} catch ( e ) {}
	} );

	var is_ios = /iPhone|iPad|iPod/.test( navigator.userAgent ) || ( 'MacIntel' === navigator.platform && navigator.maxTouchPoints > 1 );

	if ( is_ios ) {
		add_button.hidden = true;
		show();
		return;
	}

	// Android: the browser says when the gallery can be installed, and not when it already is.
	steps.hidden = true;
	window.addEventListener( 'beforeinstallprompt', function( event ) {
		if ( ! window.matchMedia( '(pointer: coarse)' ).matches ) {
			return;
		}
		event.preventDefault();
		install_event = event;
		show();
	} );

	add_button.addEventListener( 'click', function() {
		if ( ! install_event ) {
			return;
		}
		install_event.prompt();
		install_event.userChoice.then( function() {
			install_event = null;
			hide();
		} );
	} );

	window.addEventListener( 'appinstalled', hide );

} )();
