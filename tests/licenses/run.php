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
$saved = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'sunshine\\_license%'" );

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

WP_CLI::log( 'The plan is renewed after it expired (the add-on\'s own copy says expired)' );
spc_lt_set( $plan, $pro_key, 'valid', $future );
spc_lt_set( $addon, $pro_key, 'expired', $past );
spc_lt_check( 'the add-on is active again right away', ! $addon->is_expired() && array_key_exists( $addon_id, apply_filters( 'sunshine_active_addons', array() ) ) );

WP_CLI::log( 'An add-on still on an older, expired plan\'s key after the site moved up to Pro' );
$basic = $all['sunshine-photo-cart-basic'] ?? null;
if ( $basic ) {
	$basic_key = 'testbasickey00000000000000000e5f6';
	spc_lt_set( $basic, $basic_key, 'expired', $past );
	spc_lt_set( $addon, $basic_key, 'valid', $future );
	spc_lt_check( 'it keeps its own status until the next check moves it to Pro', 'valid' === $addon->get_status() && ! $addon->is_expired() );
	$store = array( 'mode' => 'valid', 'expires' => $future );
	$addon->check_license();
	spc_lt_check( 'the next check moves it to the Pro key', $pro_key === $addon->get_license_key() && $addon->is_covered_by_plan() );
	spc_lt_set( $basic, '', '', '' );

	WP_CLI::log( 'The site moved down to Basic, which does not cover an add-on still on the expired Pro key' );
	spc_lt_set( $basic, $basic_key, 'valid', $future );
	spc_lt_set( $plan, $pro_key, 'expired', $past );
	spc_lt_set( $addon, $pro_key, 'expired', $past );
	spc_lt_check( 'the red bar names the add-on as expired', array_key_exists( $addon_id, $expired() ) );
	spc_lt_set( $basic, '', '', '' );
}

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
spc_lt_set( $plan, $pro_key, 'expired', $past, array( 'time' => time() - $day, 'ok' => false, 'error' => 'cURL error 28', 'last_ok' => time() - 20 * $day, 'first_fail' => time() - 19 * $day ) );
spc_lt_check( 'after 20 days with no check getting through, the plan is a problem, not expired', array_key_exists( 'sunshine-photo-cart-pro', $problems() ) && ! array_key_exists( 'sunshine-photo-cart-pro', $expired() ) );
spc_lt_check( 'the Licenses screen says the status may be out of date', false !== strpos( (string) ( apply_filters( 'sunshine_options_licenses', array() )['plansunshine-photo-cart-pro']['description'] ?? '' ), 'may be out of date' ) );
spc_lt_set( $plan, $pro_key, 'expired', $past, array( 'time' => time() - $day, 'ok' => false, 'error' => 'cURL error 28', 'last_ok' => time() - 3 * $day, 'first_fail' => time() - $day ) );
spc_lt_check( 'one failed check 3 days after a good one still says expired', array_key_exists( 'sunshine-photo-cart-pro', $expired() ) && ! array_key_exists( 'sunshine-photo-cart-pro', $problems() ) );
spc_lt_set( $plan, $pro_key, 'valid', $future );
$store = array( 'mode' => 'fail' );
$plan->check_license();
spc_lt_check( 'the first failed check on a site with no record yet is not "weeks of failures"', ! $plan->is_check_stale() && ! array_key_exists( 'sunshine-photo-cart-pro', $problems() ) );
$plan->check_license();
spc_lt_check( 'a second failure keeps when the failures started', $plan->get_last_check()['first_fail'] <= time() && ! $plan->is_check_stale() );
spc_lt_set( $addon, $own_key, '', '', array( 'time' => time(), 'ok' => false, 'error' => 'x', 'last_ok' => 0, 'first_fail' => time() - 20 * $day ) );
spc_lt_check( 'a license deactivated on purpose is not called a problem', ! array_key_exists( $addon_id, $problems() ) );
$store = array( 'mode' => 'valid' );

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
$requests = 0;
$count    = function ( $pre ) use ( &$requests ) {
	++$requests;
	return $pre;
};
add_filter( 'pre_http_request', $count, 0 );
SPC_License::check_all();
remove_filter( 'pre_http_request', $count, 0 );
spc_lt_check( 'a refresh that cannot reach the store stops after one try', 1 === $requests );

WP_CLI::log( 'System Info' );
$info = SPC_License::system_info_fields();
spc_lt_check( 'there is a line for the plan and the add-on', isset( $info['license_info_sunshine-photo-cart-pro'], $info[ 'license_info_' . $addon_id ] ) );
spc_lt_check( 'it shows the end of the key but never the whole key', false !== strpos( $info['license_info_sunshine-photo-cart-pro']['value'], '...a1b2' ) && false === strpos( wp_json_encode( $info ), $pro_key ) );
spc_lt_check( 'it says whether the weekly check is scheduled and WP-Cron is on', isset( $info['license_info_schedule'] ) );
// System Info is only loaded in wp-admin.
if ( ! function_exists( 'sunshine_site_health_info' ) ) {
	include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/system-info.php';
}
if ( ! function_exists( 'sunshine_get_settings_fields' ) ) {
	include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/settings-fields.php';
}
$site_info = sunshine_site_health_info( array() );
spc_lt_check( 'the license lines are in the Sunshine Photo Cart section, not a section of their own', isset( $site_info['sunshine-photo-cart']['fields']['license_info_sunshine-photo-cart-pro'] ) && ! isset( $site_info['sunshine-photo-cart-licenses'] ) );
spc_lt_check( 'the old "Hidden, but present" license lines are gone', ! isset( $site_info['sunshine-photo-cart']['fields']['license_sunshine-photo-cart-pro'] ) );

/*
 * Add-ons stop working without an active license, from SPC_License::STOP_START on.
 * The date is faked with the sunshine_license_now filter. Turning add-ons off and on really
 * changes the active plugins on this test site; they are put back at the end.
 */
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$active_before = get_option( 'active_plugins' );
$now           = time();
add_filter(
	'sunshine_license_now',
	function () use ( &$now ) {
		return $now;
	}
);
$start     = SPC_License::stop_start();
$fmt       = function ( $ts ) {
	return wp_date( get_option( 'date_format' ), $ts );
};
$ran_out   = '2026-10-10 23:59:59';
$addon_file = plugin_basename( ( function () {
	return $this->file;
} )->call( $addon ) );
$is_on     = function () use ( $addon_file ) {
	wp_cache_delete( 'alloptions', 'options' );
	return is_plugin_active( $addon_file );
};
$group_for = function ( $license ) {
	foreach ( SPC_License::get_stop_groups() as $group ) {
		if ( in_array( $license->get_name(), $group['names'], true ) ) {
			return $group;
		}
	}
	return null;
};
$requests  = 0;
$count     = function ( $pre ) use ( &$requests ) {
	++$requests;
	return $pre;
};
add_filter( 'pre_http_request', $count, 0 );
// Every add-on on the expired Pro key, checked by the store 3 weeks before the start date.
$set_expired_pro = function ( $check_time ) use ( $plan, $addons, $pro_key, $ran_out ) {
	spc_lt_set( $plan, $pro_key, 'expired', $ran_out, array( 'time' => $check_time, 'ok' => true, 'error' => '', 'last_ok' => $check_time, 'first_fail' => 0 ) );
	foreach ( $addons as $a ) {
		spc_lt_set( $a, $pro_key, 'expired', $ran_out );
	}
};

WP_CLI::log( 'Before the start date: Pro expired in October, so its add-ons only warn' );
$now = $start - 17 * DAY_IN_SECONDS;
$set_expired_pro( $now - DAY_IN_SECONDS );
$group = $group_for( $addon );
spc_lt_check( 'the add-on will stop on the start date', 'warn' === $addon->get_stop_phase() && $start === $addon->get_stop_time() );
spc_lt_check( 'the add-ons are named under the plan', $group && $plan_name === $group['plan'] && 'expired' === $group['reason'] );
spc_lt_check( 'the bar says when they will stop', $group && sprintf( 'Your license for %s has expired, so its add-ons will stop working on %s', $plan_name, $fmt( $start ) ) === SPC_License::stop_headline( $group ) );
$fields = apply_filters( 'sunshine_options_licenses', array() );
spc_lt_check( 'the plan on the Licenses screen explains the start date', false !== strpos( (string) ( $fields['plansunshine-photo-cart-pro']['description'] ?? '' ), 'Starting ' . $fmt( $start ) . ', add-ons with an expired license stop working' ) );
spc_lt_check( 'the add-on on the Licenses screen says when it stops', false !== strpos( (string) ( $fields[ 'xaddon' . $addon_id ]['description'] ?? '' ), $addon->get_name() . ' will stop working on ' . $fmt( $start ) ) );
SPC_License::enforce( true );
spc_lt_check( 'nothing is turned off before the start date', $is_on() );

WP_CLI::log( 'The start date passes before the add-ons are turned off' );
$now      = $start + HOUR_IN_SECONDS;
$requests = 0;
spc_lt_check( 'the add-on is due', 'due' === $addon->get_stop_phase() );
spc_lt_check( 'a wp-admin page load asks for a background store check instead of making one', true === SPC_License::enforce( false ) && 0 === $requests && $is_on() );
spc_lt_check( 'the bar does not say it stopped yet', false !== strpos( SPC_License::stop_headline( $group_for( $addon ) ), 'about to stop working' ) );

WP_CLI::log( 'The background check: the store still says expired' );
$store = array( 'mode' => 'expired', 'expires' => $ran_out );
SPC_License::enforce( true );
spc_lt_check( 'the add-on is turned off', ! $is_on() && 'off' === $addon->get_stop_phase() );
spc_lt_check( 'the store was asked once, about the plan, not once per add-on', 1 === $requests );
spc_lt_check( 'the bar says when it stopped', sprintf( 'Your license for %s expired, so its add-ons stopped working on %s', $plan_name, $fmt( $now ) ) === SPC_License::stop_headline( $group_for( $addon ) ) );
ob_start();
$addon->plugin_row( $addon_file );
$row = ob_get_clean();
spc_lt_check( 'the Plugins screen row says it was turned off', false !== strpos( $row, 'was turned off on ' . $fmt( $now ) . ' because its license expired' ) );
spc_lt_check( 'System Info says it was turned off', false !== strpos( SPC_License::system_info_fields()[ 'license_info_' . $addon_id ]['value'], 'turned off:' ) );

WP_CLI::log( 'Turned back on by hand while the license is still expired' );
activate_plugin( $addon_file, '', false, true );
$requests = 0;
SPC_License::enforce( false );
spc_lt_check( 'it is turned off again, with no store check', ! $is_on() && 0 === $requests );

WP_CLI::log( 'The plan is renewed' );
$now  += 3 * DAY_IN_SECONDS;
$store = array( 'mode' => 'valid', 'expires' => gmdate( 'Y-m-d 23:59:59', $now + 365 * DAY_IN_SECONDS ) );
SPC_License::check_all();
SPC_License::enforce( false );
spc_lt_check( 'the add-on is turned back on from what the check saved', $is_on() && '' === $addon->get_stop_phase() && ! SPC_License::get_stop_groups() );

WP_CLI::log( 'An add-on the site owner turned off stays off' );
deactivate_plugins( $addon_file, true );
SPC_License::enforce( true );
spc_lt_check( 'it is not turned back on', ! $is_on() );
activate_plugin( $addon_file, '', false, true );

WP_CLI::log( 'A renewal the site has not heard about yet' );
$now = $start + 30 * DAY_IN_SECONDS;
spc_lt_set( $plan, $pro_key, 'valid', $ran_out, array( 'time' => $now - 10 * DAY_IN_SECONDS, 'ok' => true, 'error' => '', 'last_ok' => $now - 10 * DAY_IN_SECONDS, 'first_fail' => 0 ) );
spc_lt_check( 'the saved end date says it is due', 'due' === $addon->get_stop_phase() );
SPC_License::enforce( true );
spc_lt_check( 'the last store check finds the renewal, so nothing is turned off', $is_on() && 'valid' === $plan->get_status() && ! $plan->is_expired() );

WP_CLI::log( 'A site that cannot reach the store' );
$set_expired_pro( $now - 40 * DAY_IN_SECONDS );
$store = array( 'mode' => 'fail' );
spc_lt_set( $plan, $pro_key, 'expired', $ran_out, array( 'time' => $now - 2 * HOUR_IN_SECONDS, 'ok' => false, 'error' => 'cURL error 28', 'last_ok' => $now - 40 * DAY_IN_SECONDS, 'first_fail' => $now - 10 * DAY_IN_SECONDS ) );
SPC_License::enforce( true );
spc_lt_check( 'after 10 days of failed checks, nothing is turned off', $is_on() );
spc_lt_set( $plan, $pro_key, 'expired', $ran_out, array( 'time' => $now - 7 * DAY_IN_SECONDS, 'ok' => false, 'error' => 'cURL error 28', 'last_ok' => $now - 50 * DAY_IN_SECONDS, 'first_fail' => $now - 40 * DAY_IN_SECONDS ) );
$requests = 0;
spc_lt_check( 'after 40 days, but with the last try a week old, a page load asks for a fresh try instead of turning it off', true === SPC_License::enforce( false ) && $is_on() && 0 === $requests );
$store = array( 'mode' => 'valid', 'expires' => gmdate( 'Y-m-d 23:59:59', $now + 365 * DAY_IN_SECONDS ) );
SPC_License::enforce( true );
spc_lt_check( 'the fresh try finds the store back and the license renewed, so it stays on', $is_on() && 'valid' === $plan->get_status() );
$set_expired_pro( $now - 40 * DAY_IN_SECONDS );
$store = array( 'mode' => 'fail' );
spc_lt_set( $plan, $pro_key, 'expired', $ran_out, array( 'time' => $now - 2 * HOUR_IN_SECONDS, 'ok' => false, 'error' => 'cURL error 28', 'last_ok' => $now - 40 * DAY_IN_SECONDS, 'first_fail' => $now - 31 * DAY_IN_SECONDS ) );
SPC_License::enforce( true );
spc_lt_check( 'after 31 days, the add-ons are turned off anyway', ! $is_on() );
$store = array( 'mode' => 'valid', 'expires' => gmdate( 'Y-m-d 23:59:59', $now + 365 * DAY_IN_SECONDS ) );
SPC_License::check_all();
SPC_License::enforce( false );
spc_lt_check( 'and back on once the store answers again', $is_on() );

WP_CLI::log( 'Lifetime licenses' );
spc_lt_set( $plan, '', '', '' );
spc_lt_set( $addon, $own_key, 'valid', 'lifetime' );
spc_lt_check( 'a lifetime add-on never stops', '' === $addon->get_stop_phase() );

WP_CLI::log( 'An add-on with no license key' );
foreach ( $addons as $a ) {
	spc_lt_set( $a, $own_key, 'valid', gmdate( 'Y-m-d 23:59:59', $now + 365 * DAY_IN_SECONDS ) );
}
spc_lt_set( $addon, '', '', '' );
$requests = 0;
SPC_License::enforce( true );
spc_lt_check( 'it gets 14 days to have a key entered', 'warn' === $addon->get_stop_phase() && $now + SPC_License::STOP_SETUP === $addon->get_stop_time() && $is_on() );
spc_lt_check( 'the bar says it has no active license', false !== strpos( SPC_License::stop_headline( $group_for( $addon ) ), 'does not have an active license, so it will stop working on ' . $fmt( $now + SPC_License::STOP_SETUP ) ) );
$now += 15 * DAY_IN_SECONDS;
SPC_License::enforce( true );
spc_lt_check( 'after 14 days it is turned off, with no plan to try', ! $is_on() && 0 === $requests );
spc_lt_set( $addon, $own_key, 'valid', gmdate( 'Y-m-d 23:59:59', $now + 365 * DAY_IN_SECONDS ) );
SPC_License::enforce( false );
spc_lt_check( 'a key entered later turns it back on', $is_on() );

WP_CLI::log( 'Daily checks near the end date' );
spc_lt_set( $addon, $own_key, 'valid', gmdate( 'Y-m-d H:i:s', $now + 2 * DAY_IN_SECONDS ), array( 'time' => $now - DAY_IN_SECONDS, 'ok' => true, 'error' => '', 'last_ok' => $now - DAY_IN_SECONDS, 'first_fail' => 0 ) );
foreach ( array_slice( $addons, 1 ) as $a ) {
	spc_lt_set( $a, $own_key, 'valid', gmdate( 'Y-m-d H:i:s', $now + 200 * DAY_IN_SECONDS ), array( 'time' => $now - DAY_IN_SECONDS, 'ok' => true, 'error' => '', 'last_ok' => $now - DAY_IN_SECONDS, 'first_fail' => 0 ) );
}
$requests = 0;
SPC_License::daily_check();
spc_lt_check( 'only the license ending in 2 days is checked (' . $requests . ' requests)', 1 === $requests );

WP_CLI::log( 'Someone who cannot see license keys (a Sunshine Manager)' );
spc_lt_set( $addon, '', '', '' );
( function () {
	$this->loaded = false;
} )->call( $addon );
spc_lt_check( 'is never told an add-on has no license', '' === $addon->get_stop_phase() );
( function () {
	$this->loaded = true;
} )->call( $addon );

WP_CLI::log( 'An add-on with no key on a site with a valid plan, when the store cannot be reached' );
spc_lt_set( $plan, $pro_key, 'valid', gmdate( 'Y-m-d 23:59:59', $now + 365 * DAY_IN_SECONDS ) );
SPC()->update_option( 'license_unlicensed_since_' . $addon_id, $now - 20 * DAY_IN_SECONDS );
$store = array( 'mode' => 'fail' );
SPC_License::enforce( true );
spc_lt_check( 'it is not turned off because of a request that did not get through', $is_on() );
$store = array( 'mode' => 'valid', 'expires' => gmdate( 'Y-m-d 23:59:59', $now + 365 * DAY_IN_SECONDS ) );
SPC_License::enforce( true );
spc_lt_check( 'once the store answers, it moves to the plan key and keeps working', $is_on() && $pro_key === $addon->get_license_key() && '' === $addon->get_stop_phase() );

WP_CLI::log( 'Several add-ons due when the store cannot be reached' );
spc_lt_set( $plan, '', '', '' );
foreach ( $addons as $a ) {
	spc_lt_set( $a, $own_key, 'expired', $ran_out, array( 'time' => $now - 5 * DAY_IN_SECONDS, 'ok' => true, 'error' => '', 'last_ok' => $now - 5 * DAY_IN_SECONDS, 'first_fail' => 0 ) );
}
$store    = array( 'mode' => 'fail' );
$requests = 0;
SPC_License::enforce( true );
spc_lt_check( 'the store is asked once, not once per add-on (' . $requests . ' requests)', 1 === $requests && $is_on() );
$store = array( 'mode' => 'valid', 'expires' => gmdate( 'Y-m-d 23:59:59', $now + 365 * DAY_IN_SECONDS ) );

WP_CLI::log( 'The store said expired but sent no end date' );
spc_lt_set( $plan, $pro_key, 'expired', '' );
foreach ( $addons as $a ) {
	spc_lt_set( $a, $pro_key, 'expired', '' );
}
$now   = $start - DAY_IN_SECONDS;
$group = $group_for( $addon );
$text  = $group ? SPC_License::stop_sentence( $group ) : '';
ob_start();
$addon->plugin_row( $addon_file );
$row = ob_get_clean();
spc_lt_check( 'the notice has no empty date', $text && false === strpos( $text, ' on ,' ) && false !== strpos( $text, 'license has expired, so these add-ons will stop working on ' . $fmt( $start ) ) );
spc_lt_check( 'the Plugins screen row has no empty date', $row && false === strpos( $row, ' on ,' ) && false !== strpos( $row, 'has expired, so it will stop working on ' . $fmt( $start ) ) );

remove_filter( 'pre_http_request', $count, 0 );
update_option( 'active_plugins', $active_before );

// Put every license option back.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sunshine\\_license%'" );
foreach ( $saved as $row ) {
	$wpdb->insert(
		$wpdb->options,
		array(
			'option_name'  => $row->option_name,
			'option_value' => $row->option_value,
			'autoload'     => $row->autoload,
		)
	);
}
wp_cache_flush();
SPC()->notices->delete_admin( 'sunshine_license_check_all' );

WP_CLI::log( "\n" . $GLOBALS['spc_lt']['passes'] . ' passed, ' . $GLOBALS['spc_lt']['failures'] . ' failed' );
if ( $GLOBALS['spc_lt']['failures'] ) {
	WP_CLI::halt( 1 );
}
