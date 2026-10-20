<?php
/**
 * Lean loading for image import requests.
 *
 * An import is thousands of requests in a row, and every one of them normally
 * starts every plugin on the site and runs all of their admin setup, only for
 * Sunshine to add a photo. That startup is most of the time a photo takes.
 *
 * This file runs from wp-content/mu-plugins/sunshine-fast-imports.php, which
 * Sunshine writes there (see sunshine_fast_imports_install()). Must-use plugins
 * load before regular plugins, which is the only point where the list of plugins
 * to load can still be changed. For import requests it keeps Sunshine and its
 * add-ons, plus the few kinds of plugins an import really needs, and leaves the
 * rest out for that one request. Every other request is untouched.
 *
 * Turn it off with define( 'SUNSHINE_FAST_IMPORTS', false ); in wp-config.php.
 * Keep more plugins with define( 'SUNSHINE_FAST_IMPORTS_KEEP', array( 'plugin-folder' ) );
 *
 * If an import request ever crashes because something it calls belongs to a
 * plugin that was left out (often the theme or a code snippet relying on
 * another plugin), lean loading pauses itself on that site until its plugins
 * or theme change. See sunshine_fast_imports_pause_on_crash().
 *
 * @package SunshinePhotoCart
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'SUNSHINE_FAST_IMPORTS' ) && ! SUNSHINE_FAST_IMPORTS ) {
	return;
}

/**
 * Whether this request only exists to bring images in.
 *
 * Only plain requests are matched here: Sunshine itself is not loaded yet, so
 * nothing else can be asked.
 *
 * @return bool
 */
function sunshine_fast_imports_is_import_request() {
	// The import itself asks for every plugin when it will send email or create
	// customers, so mail and CRM plugins are there to do their part.
	if ( ! empty( $_REQUEST['sunshine_full_load'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only routing.
		return false;
	}

	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		$actions = array(
			'sunshine_volume_galleries_save',          // Bulk Galleries.
			'sunshine_volume_galleries_process_video', // Bulk Galleries, video thumbnails.
			'sunshine_gallery_import',                 // Import from FTP folder.
			'sunshine_gallery_import_list',
			'sunshine_gallery_upload',                 // Upload from the gallery screen.
			'spc_process_images',                      // Delayed image processing queue.
		);
		$action  = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only routing; the handler checks the nonce.
		return in_array( $action, $actions, true );
	}

	// Photos sent through the Sunshine API (Lightroom), either URL style.
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		$route = isset( $_GET['rest_route'] ) ? (string) $_GET['rest_route'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $route && isset( $_SERVER['REQUEST_URI'] ) ) {
			$route = (string) wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
		}
		return (bool) preg_match( '#/sunshine/v1/galleries/\d+/images/?$#', $route );
	}

	return false;
}

/**
 * Whether a plugin has to stay on for an import.
 *
 * - Sunshine and its add-ons, which do the importing, watermark, and add sizes.
 * - Plugins that move uploads to cloud storage, or photos would stay on the server.
 * - Code snippet plugins, where photographers keep their Sunshine customizations.
 *
 * @param string $plugin Plugin basename, like folder/file.php.
 * @return bool
 */
function sunshine_fast_imports_keep_plugin( $plugin ) {
	// Sunshine itself, found by its main file, so a renamed folder still counts.
	if ( '/sunshine-photo-cart.php' === substr( $plugin, -strlen( '/sunshine-photo-cart.php' ) ) ) {
		return true;
	}

	$folder = strtolower( false !== strpos( $plugin, '/' ) ? dirname( $plugin ) : basename( $plugin, '.php' ) );

	if ( false !== strpos( $folder, 'sunshine' ) ) {
		return true;
	}

	$keep = array(
		// Cloud storage offloading. Without these, photos would be saved to the
		// server while the rest of the site looks for them in the cloud.
		'amazon-s3-and-cloudfront',
		'amazon-s3-and-cloudfront-pro',
		'ilab-media-tools',
		'ilab-media-tools-premium',
		'wp-stateless',
		'advanced-media-offloader',
		's3-uploads',
		'infinite-uploads',
		'leopard-wordpress-offload-media',
		'next3-offload',
		'cloudinary-image-management-and-manipulation-in-the-cloud-cdn',
		// Custom fields, which themes and snippets often call directly.
		'advanced-custom-fields',
		'advanced-custom-fields-pro',
		'secure-custom-fields',
		// Languages, so new photos are given one.
		'sitepress-multilingual-cms',
		'polylang',
		'polylang-pro',
		// Code snippets.
		'code-snippets',
		'code-snippets-pro',
		'insert-headers-and-footers',
		'wpcode-premium',
		'insert-php',
		'snippets',
	);

	if ( defined( 'SUNSHINE_FAST_IMPORTS_KEEP' ) && is_array( SUNSHINE_FAST_IMPORTS_KEEP ) ) {
		$keep = array_merge( $keep, array_map( 'strtolower', SUNSHINE_FAST_IMPORTS_KEEP ) );
	}

	return in_array( $folder, $keep, true );
}

/**
 * Remove the plugins an import does not need from a list of active plugins.
 *
 * @param array $plugins Plugin basenames.
 * @param bool  $keyed   Whether basenames are the keys (network active list).
 * @return array
 */
function sunshine_fast_imports_filter_list( $plugins, $keyed = false ) {
	if ( ! is_array( $plugins ) ) {
		return $plugins;
	}

	$kept    = array();
	$skipped = array();
	foreach ( $plugins as $key => $value ) {
		$plugin = $keyed ? $key : $value;
		if ( sunshine_fast_imports_keep_plugin( $plugin ) ) {
			$kept[ $key ] = $value;
		} else {
			$skipped[] = $plugin;
		}
	}

	// Kept per list, since the list is filtered every time it is read.
	$GLOBALS['sunshine_fast_imports_skipped'][ $keyed ? 'network' : 'site' ] = $skipped;

	return $keyed ? $kept : array_values( $kept );
}

/**
 * Pause lean loading if an import request crashed for want of a plugin.
 *
 * Only crashes about something missing count. Running out of memory on a huge
 * photo has nothing to do with which plugins loaded, so it doesn't pause.
 */
function sunshine_fast_imports_pause_on_crash() {
	$error = error_get_last();
	if ( empty( $error ) || ! in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
		return;
	}
	if ( ! preg_match( '/Call to undefined (function|method)|Class ["\'][^"\']+["\'] not found|Undefined constant|Interface ["\'][^"\']+["\'] not found|Trait ["\'][^"\']+["\'] not found/i', $error['message'] ) ) {
		return;
	}
	update_option(
		'sunshine_fast_imports_paused',
		array(
			'time'  => time(),
			'error' => substr( $error['message'], 0, 500 ),
			'file'  => $error['file'] . ':' . $error['line'],
		),
		false
	);
	if ( function_exists( 'SPC' ) ) {
		SPC()->log( 'Fast imports: paused, because an import request stopped with an error that looks like a plugin left out of it was needed: ' . $error['message'] );
	}
}

// Nothing to do unless Sunshine itself is on, and lean loading isn't paused.
$sunshine_fast_imports_active = (array) get_option( 'active_plugins', array() );
if ( is_multisite() ) {
	$sunshine_fast_imports_active = array_merge( $sunshine_fast_imports_active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
}
$sunshine_fast_imports_on = false;
foreach ( $sunshine_fast_imports_active as $sunshine_fast_imports_plugin ) {
	if ( '/sunshine-photo-cart.php' === substr( $sunshine_fast_imports_plugin, -strlen( '/sunshine-photo-cart.php' ) ) ) {
		$sunshine_fast_imports_on = true;
		break;
	}
}
unset( $sunshine_fast_imports_active, $sunshine_fast_imports_plugin );

if ( $sunshine_fast_imports_on && ! get_option( 'sunshine_fast_imports_paused' ) && sunshine_fast_imports_is_import_request() ) {

	register_shutdown_function( 'sunshine_fast_imports_pause_on_crash' );

	// Read the real lists before filtering, so they can be put back if anything
	// tries to save them during this request. Saving the shortened list would
	// switch those plugins off for good.
	$GLOBALS['sunshine_fast_imports_real'] = array(
		'active_plugins'          => get_option( 'active_plugins', array() ),
		'active_sitewide_plugins' => is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : array(),
	);

	add_filter(
		'option_active_plugins',
		function ( $plugins ) {
			return sunshine_fast_imports_filter_list( $plugins );
		},
		PHP_INT_MAX
	);
	add_filter(
		'pre_update_option_active_plugins',
		function () {
			return $GLOBALS['sunshine_fast_imports_real']['active_plugins'];
		},
		PHP_INT_MAX
	);

	if ( is_multisite() ) {
		add_filter(
			'site_option_active_sitewide_plugins',
			function ( $plugins ) {
				return sunshine_fast_imports_filter_list( $plugins, true );
			},
			PHP_INT_MAX
		);
		add_filter(
			'pre_update_site_option_active_sitewide_plugins',
			function () {
				return $GLOBALS['sunshine_fast_imports_real']['active_sitewide_plugins'];
			},
			PHP_INT_MAX
		);
	}
}
unset( $sunshine_fast_imports_on );
