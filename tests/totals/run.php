<?php
/**
 * Cart totals tests.
 *
 * Run on a throwaway test site, never a live store:
 *   wp eval-file wp-content/plugins/sunshine-photo-cart/tests/totals/run.php
 *
 * Add-on tests are skipped when that add-on is not active.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'These tests create and delete products, discounts and customers. Run them on a test site.' );
}

require __DIR__ . '/lib.php';

foreach ( glob( __DIR__ . '/cases/*.php' ) as $file ) {
	require $file;
}

SPC_Totals_Test::cleanup();

if ( ! SPC_Totals_Test::report() ) {
	WP_CLI::halt( 1 );
}
