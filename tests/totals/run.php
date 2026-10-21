<?php
/**
 * Cart totals tests.
 *
 * Run on a throwaway test site, never a live store:
 *   wp eval-file wp-content/plugins/sunshine-photo-cart/tests/totals/run.php
 *
 * Add-on tests are skipped when that add-on is not active.
 * SPC_TOTALS_ONLY=name runs only the case files with that in their name.
 *
 * The whole suite in one process gets very slow (each shipping method lookup adds filters that
 * are never removed), so run it with run-all.sh, which runs each group of files on its own.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'These tests create and delete products, discounts and customers. Run them on a test site.' );
}

require __DIR__ . '/lib.php';

// SPC_TOTALS_ONLY=name runs only the case files whose name contains it, e.g. SPC_TOTALS_ONLY=gift-cards.
// SPC_TOTALS_FILES=a,b runs exactly those case files (names without .php); run-all.sh uses it.
$only  = (string) getenv( 'SPC_TOTALS_ONLY' );
$files = array_filter( explode( ',', (string) getenv( 'SPC_TOTALS_FILES' ) ) );
foreach ( glob( __DIR__ . '/cases/*.php' ) as $file ) {
	$name = basename( $file, '.php' );
	if ( $files ? in_array( $name, $files, true ) : ( '' === $only || false !== strpos( $name, $only ) ) ) {
		require $file;
	}
}

SPC_Totals_Test::cleanup();

if ( ! SPC_Totals_Test::report() ) {
	WP_CLI::halt( 1 );
}
