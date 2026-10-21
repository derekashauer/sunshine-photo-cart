<?php
/**
 * The Reports screen (Sunshine > Reports): the Orders report (Total Received, Completed Orders,
 * Average Order, Total Unpaid Orders and the chart) and the Tax report (Tax Collected, which
 * photographers use to file sales tax).
 *
 * Orders are placed through the real checkout step, paid with Offline (turned on in memory, so
 * they are real "live" orders) and then moved to the status being tested, the way a
 * photographer does on the order screen. Each test moves its orders to its own day in the 1980s
 * and runs the report for just that day, so orders made by anything else on the site are never
 * counted. The reports keep their numbers in a cached copy for an hour; each run here asks for
 * fresh numbers ("Refresh data") and deletes the cached copy afterwards.
 *
 * Nothing here reaches an outside service or sends an email: every outgoing request and email
 * is stopped while this file runs.
 */

$t = 'SPC_Totals_Test';

// The report screens only load in wp-admin. Load them the same way.
if ( ! function_exists( 'sunshine_report_orders_display' ) ) {
	include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/reports.php';
}

// ---------------------------------------------------------------------------------------------
// Keep this file to itself: no outside requests, no emails, no other agents' automatic
// discounts, and nothing left behind.
// ---------------------------------------------------------------------------------------------

$order_reports_blocked = array();
$order_reports_offline = function ( $pre, $args, $url ) use ( &$order_reports_blocked ) {
	if ( false !== $pre ) {
		return $pre;
	}
	$order_reports_blocked[] = $url;
	return new WP_Error( 'spc_totals_offline', 'The totals tests never reach outside services' );
};
$order_reports_no      = function () {
	return false;
};
add_filter( 'pre_http_request', $order_reports_offline, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', $order_reports_no, PHP_INT_MAX );
add_filter( 'sunshine_checkout_allow_order_notify', $order_reports_no, PHP_INT_MAX );

$order_reports_auto_hook = has_action( 'sunshine_set_discounts', 'sunshine_discounts_set_auto' );
if ( false !== $order_reports_auto_hook ) {
	remove_action( 'sunshine_set_discounts', 'sunshine_discounts_set_auto', $order_reports_auto_hook );
}

$order_reports_made_products = array();
$order_reports_made_emails   = array();
$order_reports_remember      = function ( $post_id, $post ) use ( &$order_reports_made_products, &$order_reports_made_emails ) {
	if ( 'sunshine-product' === $post->post_type ) {
		$order_reports_made_products[] = (int) $post_id;
	} elseif ( 'sunshine-email-log' === $post->post_type ) {
		$order_reports_made_emails[] = (int) $post_id;
	}
};
add_action( 'wp_insert_post', $order_reports_remember, 10, 2 );

// This site writes PHP warnings to a log file, not the screen. Note every warning, notice and
// deprecation raised while this file runs, and print them at the end (PHP still logs them too).
$order_reports_php_notes = array();
set_error_handler(
	function ( $level, $message, $file, $line ) use ( &$order_reports_php_notes ) {
		$order_reports_php_notes[ $message . ' (' . preg_replace( '#^.*/(sunshine-[^/]+/)#', '$1', $file ) . ':' . $line . ')' ] = true;
		return false;
	}
);

// ---------------------------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------------------------

// A day of its own for one test, in the 1980s.
$order_reports_new_day = function () {
	return gmdate( 'Y-m-d', gmmktime( 0, 0, 0, wp_rand( 1, 12 ), wp_rand( 1, 28 ), wp_rand( 1981, 1989 ) ) );
};

$order_reports_plus_days = function ( $day, $days ) {
	return gmdate( 'Y-m-d', strtotime( $day . ' 00:00:00 UTC' ) + $days * DAY_IN_SECONDS );
};

// An empty cart for the next order of the same test, keeping the test's store settings.
$order_reports_fresh_cart = function () {
	foreach ( array( 'cart', 'discounts', 'gift_cards', 'gift_cards_applied', 'gift_card_amounts', 'checkout_order_id', 'use_credits' ) as $key ) {
		SPC()->session->set( $key, '' );
	}
	if ( is_user_logged_in() ) {
		SPC()->customer->set_cart( array() );
	}
	SPC()->cart = new SPC_Cart();
	SPC()->cart->set_checkout_data_item( 'use_credits', '' );
};

// Place one order: $fill puts things in the cart, then the order is placed with $payment_method,
// moved to $status the way the order screen does, and dated $day at $time (store time).
$order_reports_order = function ( $day, $status, $fill, $payment_method = 'offline', $time = '12:00:00' ) use ( $t, $order_reports_fresh_cart ) {
	global $wpdb;
	$t::option( 'offline_active', 1 );
	$order_reports_fresh_cart();
	$fill();
	$t::calculate();
	$order = $t::place_order( $payment_method );
	if ( 'pending' !== $status ) {
		$order->set_status( $status );
	}
	$local = $day . ' ' . $time;
	$wpdb->update(
		$wpdb->posts,
		array(
			'post_date'     => $local,
			'post_date_gmt' => get_gmt_from_date( $local ),
		),
		array( 'ID' => $order->get_id() )
	);
	clean_post_cache( $order->get_id() );
	$order_reports_fresh_cart();
	return sunshine_get_order( $order->get_id() );
};

// One untaxed-or-taxed product in the cart, $qty of them.
$order_reports_buy = function ( $price, $qty = 1, $taxable = true ) use ( $t ) {
	return function () use ( $t, $price, $qty, $taxable ) {
		$t::add( $t::product( $price, array( 'taxable' => $taxable ) ), $qty );
	};
};

// Run a report the way the Reports screen does, with "Refresh data" clicked. Returns what the
// report worked out, the page it printed, and any PHP warnings or notices it raised.
// $report is 'orders' or 'tax'. $test_mode is the screen's "test" switch (test orders only).
$order_reports_run = function ( $report, $duration, $from, $to, $test_mode = false ) {
	$after  = $from . ' 00:00:00';
	$before = $to . ' 23:59:59';
	$key    = ( 'tax' === $report ) ? 'sunshine_reports_tax' . md5( $after . $before ) : 'sunshine_reports_orders' . md5( $after . $before ) . ( $test_mode ? 'test' : 'live' );

	$_GET['refresh'] = 1;
	if ( $test_mode ) {
		$_GET['test'] = 1;
	}
	$warnings = array();
	set_error_handler(
		function ( $level, $message, $file, $line ) use ( &$warnings ) {
			$warnings[] = $message . ' (' . basename( $file ) . ':' . $line . ')';
			return true;
		},
		E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE
	);
	ob_start();
	try {
		if ( 'tax' === $report ) {
			sunshine_report_tax_display( $duration, $after, $before );
		} else {
			sunshine_report_orders_display( $duration, $after, $before );
		}
	} finally {
		$html = ob_get_clean();
		restore_error_handler();
		unset( $_GET['refresh'], $_GET['test'] );
		$data = get_transient( $key );
		delete_transient( $key );
	}
	return array(
		'data'     => $data,
		'html'     => $html,
		'warnings' => $warnings,
	);
};

// The amount printed under a heading on the report, as a number ('' when it is not there).
$order_reports_shown = function ( $html, $heading ) {
	if ( ! preg_match( '#<h3>\s*' . preg_quote( $heading, '#' ) . '\s*</h3>\s*<p>(.*?)</p>#s', $html, $match ) ) {
		return '';
	}
	$text = html_entity_decode( wp_strip_all_tags( $match[1] ), ENT_QUOTES, 'UTF-8' );
	// "1 ($33.00)" for unpaid orders: keep the amount in the brackets.
	if ( preg_match( '#\((.*)\)#', $text, $inner ) ) {
		$text = $inner[1];
	}
	return (float) preg_replace( '#[^0-9.\-]#', '', $text );
};

// ---------------------------------------------------------------------------------------------
// Orders report and Tax report: which orders count
// ---------------------------------------------------------------------------------------------

// Sunshine counts New, Processing, Ready for pickup and Shipped as paid. Pending is waiting for
// payment and shows on its own as unpaid. Cancelled, Refunded and Failed brought in nothing.
// Every order has 10% tax:
// - New 40 -> 44, Shipped 25.50 -> 28.05, Processing 60 -> 66, Ready for pickup 10 -> 11
// - Pending 30 -> 33, Cancelled 15 -> 16.50, Refunded 20 -> 22, Failed 10 -> 11
// Total Received = 44 + 28.05 + 66 + 11 = 149.05 from 4 orders, average 149.05 / 4 = 37.26.
// Unpaid: 1 order of 33. Tax Collected = 4 + 2.55 + 6 + 1 = 13.55.
$t::test(
	'Reports: paid orders add up, unpaid ones are shown apart, the rest are left out',
	function () use ( $t, $order_reports_new_day, $order_reports_order, $order_reports_buy, $order_reports_run, $order_reports_shown ) {
		$t::tax( 10 );
		$day = $order_reports_new_day();
		$order_reports_order( $day, 'new', $order_reports_buy( 40 ) );
		$order_reports_order( $day, 'shipped', $order_reports_buy( 25.50 ) );
		$order_reports_order( $day, 'processing', $order_reports_buy( 60 ) );
		$order_reports_order( $day, 'pickup', $order_reports_buy( 10 ) );
		$pending = $order_reports_order( $day, 'pending', $order_reports_buy( 30 ) );
		$t::money( 33, $pending->get_total(), 'the pending order is 33' );
		$order_reports_order( $day, 'cancelled', $order_reports_buy( 15 ) );
		$refunded = $order_reports_order( $day, 'refunded', $order_reports_buy( 20 ) );
		$refunded->add_refund( 22, 'Customer changed their mind' );
		$order_reports_order( $day, 'failed', $order_reports_buy( 10 ) );

		$orders = $order_reports_run( 'orders', 'custom', $day, $day );
		$t::money( 149.05, $orders['data']['paid_total'], 'Total Received' );
		$t::same( 4, $orders['data']['paid_count'], 'Completed Orders' );
		$t::same( 1, $orders['data']['needs_payment_count'], 'unpaid orders' );
		$t::money( 33, $orders['data']['needs_payment_total'], 'unpaid total' );
		$t::money( 149.05, $order_reports_shown( $orders['html'], 'Total Received' ), 'Total Received shown on the screen' );
		$t::money( 37.26, $order_reports_shown( $orders['html'], 'Average Order' ), 'Average Order shown (149.05 / 4)' );
		$t::money( 33, $order_reports_shown( $orders['html'], 'Total Unpaid Orders' ), 'Total Unpaid Orders shown' );
		$t::same( array(), $orders['warnings'], 'no PHP warnings' );

		$tax = $order_reports_run( 'tax', 'custom', $day, $day );
		$t::money( 13.55, $tax['data']['tax'], 'Tax Collected' );
		$t::same( 4, $tax['data']['order_count'], 'Completed Orders on the tax report' );
		$t::money( 13.55, $order_reports_shown( $tax['html'], 'Tax Collected' ), 'Tax Collected shown on the screen' );
	}
);

// Orders paid with the Test payment method are saved as test orders: no real money moved. They
// stay out of the normal report, which can be switched to show test orders only.
// Live: 50 + 5 tax = 55. Test: 70 + 7 tax = 77.
$t::test(
	'Reports: test orders stay out of the real numbers',
	function () use ( $t, $order_reports_new_day, $order_reports_order, $order_reports_buy, $order_reports_run ) {
		$t::tax( 10 );
		$day  = $order_reports_new_day();
		$live = $order_reports_order( $day, 'new', $order_reports_buy( 50 ) );
		$test = $order_reports_order( $day, 'new', $order_reports_buy( 70 ), 'test' );
		$t::same( 'live', $live->get_mode(), 'the Offline order is a real order' );
		$t::same( 'test', $test->get_mode(), 'the Test order is a test order' );

		$orders = $order_reports_run( 'orders', 'custom', $day, $day );
		$t::money( 55, $orders['data']['paid_total'], 'Total Received leaves out the test order' );
		$t::same( 1, $orders['data']['paid_count'], 'Completed Orders' );

		$test_orders = $order_reports_run( 'orders', 'custom', $day, $day, true );
		$t::money( 77, $test_orders['data']['paid_total'], 'test orders report' );
		$t::same( 1, $test_orders['data']['paid_count'], 'test orders count' );

		$tax = $order_reports_run( 'tax', 'custom', $day, $day );
		$t::money( 5, $tax['data']['tax'], 'Tax Collected leaves out the test order' );
	}
);

// The tax of each order is rounded to the cent on the order, and the report adds those up.
// 3 x 19.99 = 59.97 at 7.25% = 4.3478 -> 4.35, total 64.32. 100 at 7.25% = 7.25, total 107.25.
$t::test(
	'Reports: Tax Collected adds up each order\'s tax to the cent',
	function () use ( $t, $order_reports_new_day, $order_reports_order, $order_reports_buy, $order_reports_run ) {
		$t::tax( 7.25 );
		$day = $order_reports_new_day();
		$a   = $order_reports_order( $day, 'new', $order_reports_buy( 19.99, 3 ) );
		$b   = $order_reports_order( $day, 'shipped', $order_reports_buy( 100 ) );
		$t::money( 4.35, $a->get_tax(), 'first order tax' );
		$t::money( 7.25, $b->get_tax(), 'second order tax' );

		$tax = $order_reports_run( 'tax', 'custom', $day, $day );
		$t::money( 11.60, $tax['data']['tax'], 'Tax Collected (4.35 + 7.25)' );
		$orders = $order_reports_run( 'orders', 'custom', $day, $day );
		$t::money( 171.57, $orders['data']['paid_total'], 'Total Received (64.32 + 107.25)' );
	}
);

// Prices include 20% tax: 3 x 12 = 36, of which 6 is tax; 30, of which 5 is tax.
$t::test(
	'Reports: prices include tax',
	function () use ( $t, $order_reports_new_day, $order_reports_order, $order_reports_buy, $order_reports_run ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$day = $order_reports_new_day();
		$order_reports_order( $day, 'new', $order_reports_buy( 12, 3 ) );
		$order_reports_order( $day, 'processing', $order_reports_buy( 30 ) );

		$orders = $order_reports_run( 'orders', 'custom', $day, $day );
		$t::money( 66, $orders['data']['paid_total'], 'Total Received (36 + 30)' );
		$tax = $order_reports_run( 'tax', 'custom', $day, $day );
		$t::money( 11, $tax['data']['tax'], 'Tax Collected (6 + 5)' );
	}
);

// A gift card or store credit pays part of an order, and the rest comes in through the payment
// method. Total Received is the money that came in for the order (the gift card was already
// counted when it was sold), while the tax on the order was still collected in full.
// - 100 + 10 tax = 110, 30 on a gift card -> 80 received, 10 tax
// - 40 + 4 tax = 44, all on a gift card    -> 0 received, 4 tax (still a completed order)
// - 50 + 5 tax = 55, 20 in store credit    -> 35 received, 5 tax
// Total Received 115 from 3 orders, Tax Collected 19.
$t::test(
	'Reports: orders paid partly or fully with a gift card or store credit',
	function () use ( $t, $order_reports_new_day, $order_reports_order, $order_reports_run ) {
		$t::require_plugin( 'sunshine_get_gift_card_by_code', 'Gift Cards add-on' );
		$t::tax( 10 );
		$day = $order_reports_new_day();
		$a   = $order_reports_order(
			$day,
			'new',
			function () use ( $t ) {
				$t::add( $t::product( 100 ) );
				$t::gift_card( 30 );
			}
		);
		$t::money( 80, $a->get_total(), 'gift card order total' );
		$b = $order_reports_order(
			$day,
			'new',
			function () use ( $t ) {
				$t::add( $t::product( 40 ) );
				$t::gift_card( 100 );
			}
		);
		$t::money( 0, $b->get_total(), 'order paid all by gift card' );
		$t::same( 'live', $b->get_mode(), 'the gift card order is a real order' );
		$c = $order_reports_order(
			$day,
			'new',
			function () use ( $t ) {
				$customer_id = $t::customer();
				update_user_meta( $customer_id, 'sunshine_credits', '20' );
				SPC()->customer = new SPC_Customer( $customer_id );
				SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
				$t::add( $t::product( 50 ) );
			}
		);
		$t::money( 35, $c->get_total(), 'store credit order total' );

		$orders = $order_reports_run( 'orders', 'custom', $day, $day );
		$t::money( 115, $orders['data']['paid_total'], 'Total Received (80 + 0 + 35)' );
		$t::same( 3, $orders['data']['paid_count'], 'Completed Orders' );
		$tax = $order_reports_run( 'tax', 'custom', $day, $day );
		$t::money( 19, $tax['data']['tax'], 'Tax Collected (10 + 4 + 5)' );
	}
);

// Shipping that is taxed: its tax was collected too. 100 + 10 shipping, 10% on both:
// 11 tax, 121 total.
$t::test(
	'Reports: Tax Collected includes the tax on shipping',
	function () use ( $t, $order_reports_new_day, $order_reports_order, $order_reports_run ) {
		global $wp_filter;
		// Each shipping method object adds a filter that is never taken off (already reported).
		// Take off the ones this test adds once it is done.
		$hooks  = array( 'sunshine_shipping_methods', 'sunshine_checkout_delivery_options', 'sunshine_delivery_methods', 'sunshine_options_shipping' );
		$before = array();
		foreach ( $hooks as $hook ) {
			$before[ $hook ] = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array();
		}
		try {
			$t::tax( 10 );
			$day   = $order_reports_new_day();
			$order = $order_reports_order(
				$day,
				'shipped',
				function () use ( $t ) {
					$t::flat_shipping( 10, true );
					$t::add( $t::product( 100 ) );
				}
			);
			$t::money( 1, $order->get_shipping_tax(), 'tax on shipping' );
			$t::money( 121, $order->get_total(), 'order total' );

			$tax = $order_reports_run( 'tax', 'custom', $day, $day );
			$t::money( 11, $tax['data']['tax'], 'Tax Collected (10 on the print + 1 on shipping)' );
			$orders = $order_reports_run( 'orders', 'custom', $day, $day );
			$t::money( 121, $orders['data']['paid_total'], 'Total Received' );
		} finally {
			foreach ( $hooks as $hook ) {
				if ( empty( $wp_filter[ $hook ] ) ) {
					continue;
				}
				foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
					foreach ( $callbacks as $callback_id => $entry ) {
						if ( ! isset( $before[ $hook ][ $priority ][ $callback_id ] ) ) {
							remove_filter( $hook, $entry['function'], $priority );
						}
					}
				}
			}
		}
	}
);

// A discount code before tax: 100 - 15 = 85 + 8.50 tax = 93.50.
$t::test(
	'Reports: an order with a discount code',
	function () use ( $t, $order_reports_new_day, $order_reports_order, $order_reports_run ) {
		$t::tax( 10 );
		$day = $order_reports_new_day();
		$order_reports_order(
			$day,
			'new',
			function () use ( $t ) {
				$t::add( $t::product( 100 ) );
				$t::discount( 'percent-total', 15 );
			}
		);
		$orders = $order_reports_run( 'orders', 'custom', $day, $day );
		$t::money( 93.50, $orders['data']['paid_total'], 'Total Received' );
		$tax = $order_reports_run( 'tax', 'custom', $day, $day );
		$t::money( 8.50, $tax['data']['tax'], 'Tax Collected on the discounted 85' );
	}
);

// ---------------------------------------------------------------------------------------------
// Refunds
// ---------------------------------------------------------------------------------------------

// Part of an order was refunded and the order is still being filled, so it is still a paid
// order (for example a refund recorded through the API add-on, or the photographer set the
// order back to Processing after a part refund). 100 + 10 tax = 110, 21 refunded: 89 came in.
// Assumed rule for tax (nothing records how much of a refund was tax): the order's tax still
// counts in full, since the sale stands.
// 89 + (50 + 5 tax) = 144 received from 2 orders. Tax Collected 10 + 5 = 15.
$t::test(
	'Reports: a paid order with part refunded counts what was kept',
	function () use ( $t, $order_reports_new_day, $order_reports_order, $order_reports_buy, $order_reports_run ) {
		$t::tax( 10 );
		$day   = $order_reports_new_day();
		$order = $order_reports_order( $day, 'processing', $order_reports_buy( 100 ) );
		$order->add_refund( 21, 'One print was damaged' );
		$order_reports_order( $day, 'new', $order_reports_buy( 50 ) );

		$orders = $order_reports_run( 'orders', 'custom', $day, $day );
		$t::money( 144, $orders['data']['paid_total'], 'Total Received (110 - 21 + 55)' );
		$t::same( 2, $orders['data']['paid_count'], 'Completed Orders' );
		$tax = $order_reports_run( 'tax', 'custom', $day, $day );
		$t::money( 15, $tax['data']['tax'], 'Tax Collected' );
	}
);

// An order marked Refunded with only part of it refunded (an add-on gateway, older orders, or
// the photographer setting the status by hand). The dashboard and the weekly Summary email count
// what was kept, so the Reports screen must too, or the three show different sales.
// 100 + 10 tax = 110, 21 refunded, status Refunded: 89 kept. 40 + 4 tax = 44 refunded in full,
// status Refunded: nothing kept, left out. Another order 55.
// 89 + 55 = 144 received from 2 orders. Tax Collected 10 + 5 = 15 from 2 orders.
$t::test(
	'Reports: a Refunded order with part refunded counts what was kept, like the dashboard',
	function () use ( $t, $order_reports_new_day, $order_reports_order, $order_reports_buy, $order_reports_run ) {
		$t::tax( 10 );
		$day   = $order_reports_new_day();
		$order = $order_reports_order( $day, 'refunded', $order_reports_buy( 100 ) );
		$order->add_refund( 21, 'One print was damaged' );
		$full = $order_reports_order( $day, 'refunded', $order_reports_buy( 40 ) );
		$full->add_refund( 44, 'Refunded in full' );
		$order_reports_order( $day, 'new', $order_reports_buy( 50 ) );

		$orders = $order_reports_run( 'orders', 'custom', $day, $day );
		$t::money( 144, $orders['data']['paid_total'], 'Total Received (110 - 21 + 55)' );
		$t::same( 2, $orders['data']['paid_count'], 'Completed Orders (the fully refunded one is left out)' );
		$tax = $order_reports_run( 'tax', 'custom', $day, $day );
		$t::money( 15, $tax['data']['tax'], 'Tax Collected' );
		$t::same( 2, $tax['data']['order_count'], 'Completed Orders on the tax report' );
	}
);

// The photographer refunds 10.10 of a 64.32 order with the "Refund payment in Stripe" action
// (Full refund unticked). The customer still paid 54.22 for prints they keep, and the tax on
// that sale was collected. Stripe's refund marks the order Refunded (as the Stripe guide says),
// and the reports leave out every Refunded order, so the 54.22 and its tax vanish from both
// reports. Assumed rule: the reports count what was kept on an order that was only partly
// refunded (a fully refunded order adds nothing either way, since its total less refunds is 0).
// Nothing reaches Stripe: every request gets a made-up "it worked" reply.
$t::test(
	'Reports: a part refund through Stripe still counts what was kept',
	function () use ( $t, $order_reports_new_day, $order_reports_order, $order_reports_buy, $order_reports_run ) {
		$stripe = sunshine_get_payment_method_by_id( 'stripe' );
		if ( ! $stripe ) {
			$t::skip( 'Stripe payment method not available' );
		}
		$t::tax( 7.25 );
		$t::option( 'stripe_mode', 'test' );
		$t::option( 'stripe_secret_key_test', 'sk_test_offline' );
		$day   = $order_reports_new_day();
		$order = $order_reports_order( $day, 'new', $order_reports_buy( 19.99, 3 ) );
		$t::money( 64.32, $order->get_total(), 'order total (59.97 + 4.35 tax)' );
		$order->update_meta_value( 'payment_method', 'stripe' );
		$order->update_meta_value( 'stripe_payment_intent_id', 'pi_offline' );

		$sent  = array();
		$reply = function ( $pre, $args, $url ) use ( &$sent ) {
			$sent[] = $url;
			$body   = '{}';
			if ( false !== strpos( $url, 'stripe.com/v1/payment_intents/' ) ) {
				$body = '{"id":"pi_offline","amount":6432}';
			} elseif ( false !== strpos( $url, 'stripe.com/v1/refunds' ) ) {
				$body = '{"id":"re_offline","status":"succeeded"}';
			}
			return array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $reply, 10, 3 );
		$_POST['stripe_refund_amount'] = '10.10';
		try {
			$stripe->process_refund( $order->get_id() );
		} finally {
			remove_filter( 'pre_http_request', $reply, 10 );
			unset( $_POST['stripe_refund_amount'] );
		}
		$order = sunshine_get_order( $order->get_id() );
		$t::money( 10.10, $order->get_refund_total(), 'refund recorded' );
		$t::money( 54.22, $order->get_total(), 'kept (64.32 - 10.10)' );

		$orders = $order_reports_run( 'orders', 'custom', $day, $day );
		$t::money( 54.22, $orders['data']['paid_total'], 'Total Received counts what was kept' );
		$tax = $order_reports_run( 'tax', 'custom', $day, $day );
		$t::money( 4.35, $tax['data']['tax'], 'Tax Collected counts the tax on the sale' );
	}
);

// ---------------------------------------------------------------------------------------------
// The chart and the date buttons
// ---------------------------------------------------------------------------------------------

// Three days: 40 on the first, nothing on the second, 25 + 10 on the third. No tax.
$t::test(
	'Reports chart: each day shows that day\'s orders',
	function () use ( $t, $order_reports_new_day, $order_reports_plus_days, $order_reports_order, $order_reports_buy, $order_reports_run ) {
		$first = $order_reports_new_day();
		$last  = $order_reports_plus_days( $first, 2 );
		$order_reports_order( $first, 'new', $order_reports_buy( 40 ), 'offline', '09:15:00' );
		$order_reports_order( $last, 'shipped', $order_reports_buy( 25 ), 'offline', '23:30:00' );
		$order_reports_order( $last, 'new', $order_reports_buy( 10 ), 'offline', '00:05:00' );

		$report = $order_reports_run( 'orders', 'custom', $first, $last );
		$values = $report['data']['paid_values'];
		$t::same( array( $first, $order_reports_plus_days( $first, 1 ), $last ), array_keys( $values ), 'one point for each day' );
		$t::money( 40, isset( $values[ $first ] ) ? $values[ $first ] : null, 'first day' );
		$t::money( 0, isset( $values[ $order_reports_plus_days( $first, 1 ) ] ) ? $values[ $order_reports_plus_days( $first, 1 ) ] : null, 'second day' );
		$t::money( 35, isset( $values[ $last ] ) ? $values[ $last ] : null, 'third day (25 + 10)' );
		$t::money( 75, $report['data']['paid_total'], 'Total Received' );
		$t::same( array(), $report['warnings'], 'no PHP warnings' );
	}
);

// "All time" over more than a year shows one point a month, starting from the first order's
// date. Every month in the range needs its point, including the month of today, and each order
// must land on its own month. Two ranges:
// - starting on the 20th and ending on the 17th: the last month is the one with "today" in it
// - starting on the 31st: the next month (February) has no 31st
// No tax. 25 in the first month, 40 in the last (or 15 in February). These ranges cover years,
// so they are in the 1960s, away from the single days the other tests here use.
$t::test(
	'Reports chart: All time has a point for every month',
	function () use ( $t, $order_reports_order, $order_reports_buy, $order_reports_run ) {
		$year  = wp_rand( 1961, 1964 );
		$first = $year . '-01-20';
		$last  = ( $year + 2 ) . '-03-17';
		$order_reports_order( $year . '-01-25', 'new', $order_reports_buy( 25 ) );
		$order_reports_order( ( $year + 2 ) . '-03-10', 'new', $order_reports_buy( 40 ) );

		$report = $order_reports_run( 'orders', 'all', $first, $last );
		$labels = $report['data']['paid_labels'];
		$values = $report['data']['paid_values'];
		// January of the first year to March two years later: 12 + 12 + 3 = 27 months.
		$t::same( 27, count( $labels ), 'a label for every month' );
		$t::same( array_keys( $labels ), array_keys( $values ), 'every point has its label' );
		$t::money( 25, isset( $values[ $year . '-01' ] ) ? $values[ $year . '-01' ] : null, 'first month' );
		$t::money( 40, isset( $values[ ( $year + 2 ) . '-03' ] ) ? $values[ ( $year + 2 ) . '-03' ] : null, 'last month' );
		$t::money( 65, $report['data']['paid_total'], 'Total Received' );
		$t::same( array(), $report['warnings'], 'no PHP warnings' );

		$first = ( $year + 3 ) . '-01-31';
		$last  = ( $year + 4 ) . '-06-15';
		$order_reports_order( ( $year + 3 ) . '-02-14', 'new', $order_reports_buy( 15 ) );
		$report = $order_reports_run( 'orders', 'all', $first, $last );
		$labels = $report['data']['paid_labels'];
		$values = $report['data']['paid_values'];
		$t::same( true, isset( $labels[ ( $year + 3 ) . '-02' ] ), 'February has a label' );
		$t::same( array_keys( $labels ), array_keys( $values ), 'every point has its label (from the 31st)' );
		$t::money( 15, isset( $values[ ( $year + 3 ) . '-02' ] ) ? $values[ ( $year + 3 ) . '-02' ] : null, 'February' );
		$t::same( array(), $report['warnings'], 'no PHP warnings (from the 31st)' );
	}
);

// Order dates are saved in the store's time zone (Settings > General), so "Day" must mean
// today in the store's time zone. The site's time zone is set (in memory) to one where the date
// is not the same as in London right now, so the check does not depend on the time of day.
$t::test(
	'Reports: the Day button covers today in the store\'s time zone',
	function () use ( $t ) {
		$zone     = ( (int) gmdate( 'G' ) >= 10 ) ? 'Pacific/Kiritimati' : 'Etc/GMT+12';
		$set_zone = function () use ( $zone ) {
			return $zone;
		};
		add_filter( 'pre_option_timezone_string', $set_zone );
		$seen   = array();
		$record = function ( $duration, $after, $before ) use ( &$seen ) {
			$seen = array( $duration, $after, $before );
		};
		$display = has_action( 'sunshine_report_orders', 'sunshine_report_orders_display' );
		if ( false !== $display ) {
			remove_action( 'sunshine_report_orders', 'sunshine_report_orders_display', $display );
		}
		add_action( 'sunshine_report_orders', $record, 10, 3 );
		$_GET['duration'] = 'day';
		$_GET['report']   = 'orders';
		ob_start();
		try {
			$today = current_time( 'Y-m-d' );
			$t::same( true, gmdate( 'Y-m-d' ) !== $today, 'the store\'s date is not London\'s date (store: ' . $today . ')' );
			sunshine_reports_page();
		} finally {
			ob_end_clean();
			unset( $_GET['duration'], $_GET['report'] );
			remove_action( 'sunshine_report_orders', $record, 10 );
			if ( false !== $display ) {
				add_action( 'sunshine_report_orders', 'sunshine_report_orders_display', $display, 3 );
			}
			remove_filter( 'pre_option_timezone_string', $set_zone );
		}
		$t::same( 'day', isset( $seen[0] ) ? $seen[0] : null, 'Day report' );
		$t::same( $today . ' 00:00:00', isset( $seen[1] ) ? $seen[1] : null, 'Day starts at the store\'s midnight today' );
		$t::same( $today . ' 23:59:59', isset( $seen[2] ) ? $seen[2] : null, 'Day ends at the end of the store\'s today' );
	}
);

// ---------------------------------------------------------------------------------------------
// An order saved again before it is paid
// ---------------------------------------------------------------------------------------------

// The customer reaches the payment step with 40 in the cart, goes back, adds a 20 print and
// submits again. It is one order waiting for payment, at its newest total of 60.
$t::test(
	'Reports: an order saved again before paying counts once, at its newest total',
	function () use ( $t, $order_reports_new_day, $order_reports_fresh_cart, $order_reports_run ) {
		global $wpdb;
		$t::option( 'offline_active', 1 );
		$day = $order_reports_new_day();
		$order_reports_fresh_cart();
		$t::add( $t::product( 40, array( 'taxable' => false ) ) );
		$t::calculate();
		$first = $t::place_order( 'offline' );
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$t::new_request();
		$order = $t::place_order( 'offline' );
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$local = $day . ' 12:00:00';
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => $local,
				'post_date_gmt' => get_gmt_from_date( $local ),
			),
			array( 'ID' => $order->get_id() )
		);
		clean_post_cache( $order->get_id() );

		$orders = $order_reports_run( 'orders', 'custom', $day, $day );
		$t::same( 1, $orders['data']['needs_payment_count'], 'one unpaid order' );
		$t::money( 60, $orders['data']['needs_payment_total'], 'unpaid total is the newest total (40 + 20)' );
		$t::same( 0, $orders['data']['paid_count'], 'nothing paid yet' );
	}
);

// ---------------------------------------------------------------------------------------------
// Put everything back the way it was.
// ---------------------------------------------------------------------------------------------

remove_filter( 'pre_http_request', $order_reports_offline, PHP_INT_MAX );
remove_filter( 'pre_wp_mail', $order_reports_no, PHP_INT_MAX );
remove_filter( 'sunshine_checkout_allow_order_notify', $order_reports_no, PHP_INT_MAX );
remove_action( 'wp_insert_post', $order_reports_remember, 10 );
if ( false !== $order_reports_auto_hook ) {
	add_action( 'sunshine_set_discounts', 'sunshine_discounts_set_auto', $order_reports_auto_hook );
}
foreach ( array_unique( $order_reports_made_emails ) as $order_reports_email_id ) {
	wp_delete_post( $order_reports_email_id, true );
}
global $wpdb;
if ( ! empty( $order_reports_made_products ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sunshine_actions' ) ) ) {
	$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_actions WHERE action_type = 'add_to_cart' AND action_object_id IN (" . implode( ',', array_map( 'intval', array_unique( $order_reports_made_products ) ) ) . ')' );
}
restore_error_handler();
if ( ! empty( $order_reports_php_notes ) ) {
	WP_CLI::log( 'order-resave-and-reports-screen PHP warnings and notices: ' . implode( ' | ', array_keys( $order_reports_php_notes ) ) );
}
if ( ! empty( $order_reports_blocked ) ) {
	WP_CLI::log( 'order-resave-and-reports-screen blocked outgoing requests to: ' . implode( ', ', array_unique( $order_reports_blocked ) ) );
}
