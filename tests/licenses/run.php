<?php
/**
 * License tests: add-ons on a plan key follow the plan, failed checks are recorded and shown.
 *
 * Run on a test site with at least one add-on active, never a live store. Cron mode makes core and
 * the add-ons set up their licenses the way they do in wp-admin:
 *   wp --exec='define( "DOING_CRON", true );' eval-file wp-content/plugins/sunshine-photo-cart/tests/licenses/run.php
 *
 * Store answers are faked in this process; nothing is sent to sunshinephotocart.com. Every
 * license option is saved first and put back at the end.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'These tests change license settings. Run them on a test site.' );
}
if ( ! wp_doing_cron() ) {
	WP_CLI::error( 'Run with --exec=\'define( "DOING_CRON", true );\' so the licenses are set up.' );
}

$all = ( function () {
	return self::$all;
} )->bindTo( null, 'SPC_License' )();

$plan    = $all['sunshine-photo-cart-pro'] ?? null;
$addons  = array_filter(
	$all,
	function ( $l ) {
		return ! ( function () {
			return $this->requires;
		} )->call( $l );
	}
);
if ( ! $plan || count( $addons ) < 2 ) {
	WP_CLI::error( 'Needs the Pro plan and at least two active add-ons.' );
}
$addon       = reset( $addons );
$other_addon = next( $addons );

// Save every license option, to put back at the end.
global $wpdb;
$saved = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'sunshine\\_license%'" );

$GLOBALS['spc_lt'] = array(
	'passes'   => 0,
	'failures' => 0,
);
function spc_lt_check( $what, $ok ) {
	if ( $ok ) {
		++$GLOBALS['spc_lt']['passes'];
		WP_CLI::log( "  ok   $what" );
	} else {
		++$GLOBALS['spc_lt']['failures'];
		WP_CLI::log( WP_CLI::colorize( "  %rFAIL%n $what" ) );
	}
}

// Give a license a key, a status and an end date, as if the store had said so.
function spc_lt_set( $license, $key, $status, $expiration, $check = null ) {
	$id = ( function () {
		return $this->id;
	} )->call( $license );
	SPC()->update_option( 'license_' . $id, $key );
	SPC()->update_option( 'license_status_' . $id, $status );
	SPC()->update_option( 'license_expiration_' . $id, $expiration );
	SPC()->update_option( 'license_url_' . $id, home_url() );
	if ( null === $check ) {
		delete_option( SPC()->prefix . 'license_check_' . $id );
	} else {
		SPC()->update_option( 'license_check_' . $id, $check );
	}
	( function () use ( $key ) {
		$this->license_key = $key;
	} )->call( $license );
}

// The store's answer for every license request in this process.
$store = array( 'mode' => 'valid' );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$store ) {
		if ( false === strpos( $url, 'sunshinephotocart.com' ) || empty( $args['body']['edd_action'] ) ) {
			return $pre;
		}
		if ( 'fail' === $store['mode'] ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' );
		}
		$license = $store['mode'];
		if ( isset( $store['items'][ (int) $args['body']['item_id'] ] ) ) {
			$license = $store['items'][ (int) $args['body']['item_id'] ];
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'success' => 'valid' === $license,
					'license' => $license,
					'item_id' => (int) $args['body']['item_id'],
					'expires' => $store['expires'] ?? '2027-01-07 23:59:59',
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	1,
	3
);

$pro_key   = 'testprokey000000000000000000a1b2';
$own_key   = 'testaddonkey0000000000000000c3d4';
$future    = gmdate( 'Y-m-d 23:59:59', time() + 200 * DAY_IN_SECONDS );
$past      = gmdate( 'Y-m-d 23:59:59', time() - 300 * DAY_IN_SECONDS );
$addon_id  = ( function () {
	return $this->id;
} )->call( $addon );
$expired   = function () {
	return apply_filters( 'sunshine_expired_addons', array() );
};
$problems  = function () {
	return apply_filters( 'sunshine_license_problem_addons', array() );
};
$plan_name = $plan->get_name();

WP_CLI::log( 'An add-on on the renewed plan key, with an old end date of its own (ticket #11790)' );
spc_lt_set( $plan, $pro_key, 'valid', $future );
spc_lt_set( $addon, $pro_key, 'valid', $past );
spc_lt_set( $other_addon, $pro_key, 'valid', '' );
foreach ( array_slice( $addons, 2 ) as $rest ) {
	spc_lt_set( $rest, $pro_key, 'valid', $past );
}
spc_lt_check( 'the add-on is not expired', ! $addon->is_expired() );
spc_lt_check( 'the add-on has the plan\'s end date', $future === $addon->get_expiration() );
spc_lt_check( 'an add-on with no saved end date is not expired either', ! $other_addon->is_expired() );
spc_lt_check( 'the red bar names nothing (' . wp_json_encode( array_merge( $expired(), $problems() ) ) . ')', ! $expired() && ! $problems() );
spc_lt_check( 'the add-on is listed under the plan', $addon->is_covered_by_plan() );
spc_lt_check( 'the add-on counts as active', array_key_exists( $addon_id, apply_filters( 'sunshine_active_addons', array() ) ) );

WP_CLI::log( 'The plan expires' );
spc_lt_set( $plan, $pro_key, 'expired', $past );
spc_lt_set( $addon, $pro_key, 'valid', $future );
spc_lt_check( 'the add-on is expired with it', $addon->is_expired() && 'expired' === $addon->get_status() );
spc_lt_check( 'the add-on no longer counts as active', ! array_key_exists( $addon_id, apply_filters( 'sunshine_active_addons', array() ) ) );
spc_lt_check( 'the red bar names the plan, not the add-on', array_key_exists( 'sunshine-photo-cart-pro', $expired() ) && ! array_key_exists( $addon_id, $expired() ) );

WP_CLI::log( 'An add-on bought on its own keeps its own end date' );
spc_lt_set( $plan, $pro_key, 'valid', $future );
spc_lt_set( $addon, $own_key, 'expired', $past );
spc_lt_check( 'its own expired date shows', $past === $addon->get_expiration() && $addon->is_expired() );
spc_lt_check( 'the red bar names it', array_key_exists( $addon_id, $expired() ) );

WP_CLI::log( 'The plan does not cover an add-on (store turned the plan key down for it)' );
spc_lt_set( $addon, $pro_key, 'item_name_mismatch', '' );
spc_lt_check( 'the add-on does not follow the plan', 'item_name_mismatch' === $addon->get_status() && ! $addon->is_covered_by_plan() );
spc_lt_check( 'the red bar names it as a problem', array_key_exists( $addon_id, $problems() ) );

WP_CLI::log( 'Checks that keep failing' );
spc_lt_set( $addon, $pro_key, 'valid', $future );
$day = DAY_IN_SECONDS;
spc_lt_set( $plan, $pro_key, 'expired', $past, array( 'time' => time() - $day, 'ok' => false, 'error' => 'cURL error 28', 'last_ok' => time() - 20 * $day ) );
spc_lt_check( 'after 20 days with no check getting through, the plan is a problem, not expired', array_key_exists( 'sunshine-photo-cart-pro', $problems() ) && ! array_key_exists( 'sunshine-photo-cart-pro', $expired() ) );
spc_lt_check( 'the Licenses screen says the status may be out of date', false !== strpos( (string) ( apply_filters( 'sunshine_options_licenses', array() )['plansunshine-photo-cart-pro']['description'] ?? '' ), 'may be out of date' ) );
spc_lt_set( $plan, $pro_key, 'expired', $past, array( 'time' => time() - $day, 'ok' => false, 'error' => 'cURL error 28', 'last_ok' => time() - 3 * $day ) );
spc_lt_check( 'one failed check 3 days after a good one still says expired', array_key_exists( 'sunshine-photo-cart-pro', $expired() ) && ! array_key_exists( 'sunshine-photo-cart-pro', $problems() ) );

WP_CLI::log( 'Refresh on the plan checks every license' );
spc_lt_set( $plan, $pro_key, 'expired', $past );
spc_lt_set( $addon, $own_key, 'expired', $past );
$store = array( 'mode' => 'valid', 'expires' => $future );
SPC_License::check_all();
spc_lt_check( 'the plan has the store\'s new end date', 'valid' === $plan->get_status() && $future === $plan->get_expiration() );
spc_lt_check( 'the add-on with its own key was checked too', 'valid' === $addon->get_status() && $future === $addon->get_expiration() );
$check = $plan->get_last_check();
spc_lt_check( 'the check is recorded as ok', $check && $check['ok'] && $check['last_ok'] >= time() - 60 );

WP_CLI::log( 'A refresh that cannot reach the store' );
$store = array( 'mode' => 'fail' );
$last_ok = $plan->get_last_check()['last_ok'];
SPC_License::check_all();
$check = $plan->get_last_check();
spc_lt_check( 'the failure and its error are recorded', $check && ! $check['ok'] && false !== strpos( $check['error'], 'cURL error 28' ) );
spc_lt_check( 'the last good check is kept', $last_ok === $check['last_ok'] );
spc_lt_check( 'the saved status is left alone', 'valid' === $plan->get_status() );

WP_CLI::log( 'System Info' );
$info = SPC_License::system_info_fields();
spc_lt_check( 'there is a line for the plan and the add-on', isset( $info['sunshine-photo-cart-pro'], $info[ $addon_id ] ) );
spc_lt_check( 'it shows the end of the key but never the whole key', false !== strpos( $info['sunshine-photo-cart-pro']['value'], '...a1b2' ) && false === strpos( wp_json_encode( $info ), $pro_key ) );

// Put every license option back.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sunshine\\_license%'" );
foreach ( $saved as $row ) {
	$wpdb->insert(
		$wpdb->options,
		array(
			'option_name'  => $row->option_name,
			'option_value' => $row->option_value,
			'autoload'     => 'no',
		)
	);
}
wp_cache_flush();

WP_CLI::log( "\n" . $GLOBALS['spc_lt']['passes'] . ' passed, ' . $GLOBALS['spc_lt']['failures'] . ' failed' );
if ( $GLOBALS['spc_lt']['failures'] ) {
	WP_CLI::halt( 1 );
}
