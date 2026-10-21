<?php
/**
 * Shared pieces for every way images are imported.
 *
 * @package SunshinePhotoCart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Start timing a batch of imported images.
 *
 * Imports used to do one image per request. Every request starts WordPress from
 * scratch, and that startup was most of the time an image took. Doing as many
 * images as fit in a few seconds per request spreads that cost across all of
 * them.
 *
 * Kept well under the 30 to 60 seconds hosts and proxies allow a request, so a
 * batch always finishes and reports back before anything cuts it off.
 *
 * @return array Batch state for sunshine_import_batch_has_time().
 */
function sunshine_import_batch_start() {
	/**
	 * How many seconds one import request keeps working before it reports back.
	 *
	 * @param int $seconds Seconds.
	 */
	$seconds = (int) apply_filters( 'sunshine_import_batch_seconds', 20 );

	return array(
		'started' => microtime( true ),
		'seconds' => max( 1, $seconds ),
		'slowest' => 0,
		'last'    => microtime( true ),
		'done'    => 0,
	);
}

/**
 * Whether there is time and memory left for one more image in this batch.
 *
 * Call once after each image. Leaves room for the slowest image seen so far, so
 * a batch does not start an image it cannot finish in time.
 *
 * @param array $batch Batch state from sunshine_import_batch_start(), updated in place.
 * @return bool
 */
function sunshine_import_batch_has_time( &$batch ) {
	$now              = microtime( true );
	$batch['slowest'] = max( $batch['slowest'], $now - $batch['last'] );
	$batch['last']    = $now;
	++$batch['done'];

	if ( $now - $batch['started'] + $batch['slowest'] > $batch['seconds'] ) {
		return false;
	}

	$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
	if ( $limit > 0 && memory_get_usage( true ) > $limit * 0.7 ) {
		return false;
	}

	return true;
}

/**
 * Claim an import so only one request works on it at a time.
 *
 * When a host or proxy gives up on a slow request, PHP can keep running it while
 * the browser already sends the next one. Two requests on the same import could
 * then bring the same photo in twice. The browser is told to wait instead, and
 * tries again in a few seconds.
 *
 * Released at the end of the request, even when it stops with an error. A claim
 * older than the longest a request may run counts as left over and is taken.
 *
 * @param string $key Which import, for example the gallery or import run.
 * @return bool Whether this request has it.
 */
function sunshine_import_lock( $key ) {
	global $wpdb;

	$option = 'sunshine_import_lock_' . md5( $key );

	// When it was claimed, and a token only this request knows, so it only ever
	// lets go of its own claim. A request that ran so long its claim was taken
	// over must not then release the claim of the request that took it.
	$value = time() . '|' . wp_generate_password( 20, false );

	// A plain insert either adds the row or finds it there already, in one step,
	// so two requests can never both win.
	$claim = function () use ( $wpdb, $option, $value ) {
		return (bool) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, $value ) );
	};

	if ( ! $claim() ) {
		$held  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		$since = (int) strtok( $held, '|' );
		if ( $since > time() - 10 * MINUTE_IN_SECONDS ) {
			return false;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $option, $held ) );
		if ( ! $claim() ) {
			return false;
		}
	}

	register_shutdown_function(
		function () use ( $wpdb, $option, $value ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $option, $value ) );
		}
	);

	return true;
}

/**
 * List a gallery folder once per request instead of once per photo.
 *
 * Every time WordPress picks a file name for a new photo it lists every file
 * already in the folder. A volume gallery's folder holds tens of thousands of
 * files, so that listing was taking longer for every photo added. The folder is
 * listed the first time it is needed, and each file written after that is added
 * to the list, so the answer stays the same.
 *
 * Turned on by sunshine_doing_upload(), so it only applies while images are
 * being added.
 *
 * @param array|null $files List already decided by someone else, or null.
 * @param string     $dir   Folder the new file goes into.
 * @return array|null
 */
function sunshine_import_folder_file_list( $files, $dir ) {
	if ( null !== $files ) {
		return $files;
	}
	if ( ! isset( $GLOBALS['sunshine_import_folder_files'][ $dir ] ) ) {
		$list = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- matches wp_unique_filename().
		if ( false === $list ) {
			return null;
		}
		$GLOBALS['sunshine_import_folder_files'][ $dir ] = $list;
	}
	return $GLOBALS['sunshine_import_folder_files'][ $dir ];
}

/**
 * Add a file to the remembered folder list once it has a name.
 *
 * @param string $filename Name WordPress picked.
 * @param string $ext      Extension.
 * @param string $dir      Folder.
 * @return string
 */
function sunshine_import_remember_file_name( $filename, $ext, $dir ) {
	if ( isset( $GLOBALS['sunshine_import_folder_files'][ $dir ] ) ) {
		$GLOBALS['sunshine_import_folder_files'][ $dir ][] = $filename;
	}
	return $filename;
}

/**
 * Add an image's sizes to the remembered folder list once they are saved.
 *
 * Sizes are made without asking for a unique name, so this is how the list
 * hears about them.
 *
 * @param array $metadata      Attachment metadata.
 * @param int   $attachment_id Attachment.
 * @return array
 */
function sunshine_import_remember_size_files( $metadata, $attachment_id ) {
	if ( empty( $metadata['file'] ) || empty( $GLOBALS['sunshine_import_folder_files'] ) ) {
		return $metadata;
	}
	$upload_dir = wp_upload_dir();
	$dir        = dirname( trailingslashit( $upload_dir['basedir'] ) . $metadata['file'] );
	if ( isset( $GLOBALS['sunshine_import_folder_files'][ $dir ] ) ) {
		$GLOBALS['sunshine_import_folder_files'][ $dir ][] = basename( $metadata['file'] );
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$GLOBALS['sunshine_import_folder_files'][ $dir ][] = $size['file'];
			}
		}
	}
	return $metadata;
}

/**
 * Note in the Sunshine log which plugins were left out of an import request.
 *
 * Written once per request by the lean loader (includes/fast-imports.php).
 */
add_action( 'sunshine_before_init', 'sunshine_fast_imports_log_skipped' );
function sunshine_fast_imports_log_skipped() {
	if ( empty( $GLOBALS['sunshine_fast_imports_skipped'] ) ) {
		return;
	}
	$skipped = array_merge( ...array_values( $GLOBALS['sunshine_fast_imports_skipped'] ) );
	if ( ! empty( $skipped ) ) {
		SPC()->log( 'Fast imports: left out ' . count( $skipped ) . ' plugins for this import request: ' . implode( ', ', $skipped ) );
	}
}

/**
 * Try lean loading again once the site's plugins or theme change.
 *
 * It pauses itself when an import crashed for want of a plugin (see
 * includes/fast-imports.php), and a change here is what usually fixes that.
 */
add_action( 'update_option_active_plugins', 'sunshine_fast_imports_resume' );
add_action( 'update_site_option_active_sitewide_plugins', 'sunshine_fast_imports_resume' );
add_action( 'switch_theme', 'sunshine_fast_imports_resume' );
function sunshine_fast_imports_resume() {
	delete_option( 'sunshine_fast_imports_paused' );
}

/**
 * The must-use plugin that loads includes/fast-imports.php.
 *
 * @return string Contents of the file.
 */
function sunshine_fast_imports_mu_plugin_contents() {
	// var_export() so any folder name makes valid PHP. A broken file here would
	// stop every page of the site loading.
	// plugin_basename() gives the folder as WordPress loads it, which for a
	// linked folder can differ from where the files really are.
	$file = var_export( '/' . dirname( plugin_basename( SUNSHINE_PHOTO_CART_FILE ) ) . '/includes/fast-imports.php', true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writing PHP, not debugging.

	return "<?php\n"
		. "/**\n"
		. " * Plugin Name: Sunshine Photo Cart Fast Imports\n"
		. " * Description: Added by Sunshine Photo Cart. While photos are being imported, loads only the plugins an import needs, so imports run faster. Every other page is untouched. Removed when Sunshine Photo Cart is deactivated.\n"
		. " */\n"
		. "\$sunshine_fast_imports = WP_PLUGIN_DIR . " . $file . ";\n"
		. "if ( file_exists( \$sunshine_fast_imports ) ) {\n"
		. "\tinclude_once \$sunshine_fast_imports;\n"
		. "}\n"
		. "unset( \$sunshine_fast_imports );\n";
}

/**
 * Path of the must-use plugin file.
 *
 * @return string
 */
function sunshine_fast_imports_mu_plugin_path() {
	return WPMU_PLUGIN_DIR . '/sunshine-fast-imports.php';
}

/**
 * Put the must-use plugin in place, or bring it up to date.
 *
 * Checked on admin page loads rather than only on activation, so it comes back
 * after a site is moved or restored without it. If the folder cannot be written
 * to, imports still work, just without the speed up.
 */
add_action( 'admin_init', 'sunshine_fast_imports_install' );
function sunshine_fast_imports_install() {
	if ( wp_doing_ajax() || ( defined( 'SUNSHINE_FAST_IMPORTS' ) && ! SUNSHINE_FAST_IMPORTS ) ) {
		return;
	}

	$path     = sunshine_fast_imports_mu_plugin_path();
	$contents = sunshine_fast_imports_mu_plugin_contents();

	if ( file_exists( $path ) ) {
		$current = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		// Up to date, or a file of the same name that someone else put there.
		if ( $current === $contents || false === strpos( $current, 'Plugin Name: Sunshine Photo Cart Fast Imports' ) ) {
			return;
		}
	}

	if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
		return;
	}

	// Written under another name and then renamed into place, which happens in
	// one step. Every page on the site loads this file, so a page arriving while
	// it was half written would otherwise stop with an error. The temporary name
	// doesn't end in .php, so WordPress never loads it.
	$temp    = $path . '.tmp-' . wp_generate_password( 8, false );
	$written = false !== @file_put_contents( $temp, $contents ) && @rename( $temp, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- an unwritable folder is expected on some hosts.

	if ( ! $written ) {
		if ( file_exists( $temp ) ) {
			wp_delete_file( $temp );
		}
		// Only say so once, not on every admin page.
		if ( ! get_transient( 'sunshine_fast_imports_install_failed' ) ) {
			SPC()->log( 'Fast imports: could not write ' . $path . ', so imports load every plugin' );
			set_transient( 'sunshine_fast_imports_install_failed', 1, DAY_IN_SECONDS );
		}
		return;
	}

	// Some hosts never check files for changes once PHP has cached them, so an
	// updated file would keep running its old version until the cache cleared.
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- opcache can be restricted on some hosts.
	}
}

/**
 * Take the must-use plugin away again. Only removes the file Sunshine wrote.
 */
function sunshine_fast_imports_uninstall() {
	$path = sunshine_fast_imports_mu_plugin_path();
	if ( file_exists( $path ) && false !== strpos( (string) file_get_contents( $path ), 'Sunshine Photo Cart Fast Imports' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		wp_delete_file( $path );
	}
}
