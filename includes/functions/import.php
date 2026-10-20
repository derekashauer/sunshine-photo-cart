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
	$folder = basename( dirname( SUNSHINE_PHOTO_CART_FILE ) );

	return "<?php\n"
		. "/**\n"
		. " * Plugin Name: Sunshine Photo Cart Fast Imports\n"
		. " * Description: Added by Sunshine Photo Cart. While photos are being imported, loads only the plugins an import needs, so imports run faster. Every other page is untouched. Removed when Sunshine Photo Cart is deactivated.\n"
		. " */\n"
		. "\$sunshine_fast_imports = WP_PLUGIN_DIR . '/" . $folder . "/includes/fast-imports.php';\n"
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

	if ( file_exists( $path ) && file_get_contents( $path ) === $contents ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return;
	}

	if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
		return;
	}

	if ( false === @file_put_contents( $path, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- an unwritable folder is expected on some hosts.
		// Only say so once, not on every admin page.
		if ( ! get_transient( 'sunshine_fast_imports_install_failed' ) ) {
			SPC()->log( 'Fast imports: could not write ' . $path . ', so imports load every plugin' );
			set_transient( 'sunshine_fast_imports_install_failed', 1, DAY_IN_SECONDS );
		}
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
