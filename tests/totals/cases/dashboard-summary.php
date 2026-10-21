<?php
/**
 * The two places a photographer sees their sales at a glance:
 *
 * - The "Sunshine Photo Cart Sales Summary" box on the WordPress dashboard (Current month sales,
 *   Last month sales, Lifetime sales and New Orders). It adds up order totals with its own
 *   database query, then takes off refunds for the orders that query found.
 * - The weekly Summary email (Total Received, Orders and Avg Order for the last week).
 *
 * Orders are placed through the real checkout step, paid with Offline (turned on in memory, so
 * they are real "live" orders), then moved to the status being tested the way a photographer
 * does on the order screen, and dated where each test needs them.
 *
 * Other agents place orders on this site at the same time, so each run of the dashboard box and
 * of the email only looks at the orders the current test made: the dashboard's queries get
 * "and only these order IDs" added, and the email's order lookup gets the same limit. Nothing
 * else about them is changed. The dashboard box keeps its numbers in a cached copy for a day;
 * each run here asks for fresh numbers and deletes the cached copy afterwards (the plugin itself
 * deletes it whenever an order is made).
 *
 * The store's time zone is set to UTC in memory, so "this month" means the same thing to the
 * dashboard (which uses the server's UTC date) and to the order dates here. Nothing here reaches
 * an outside service or sends an email: every outgoing request and email is stopped while this
 * file runs.
 */

$t = 'SPC_Totals_Test';

// The dashboard box only loads in wp-admin. Load it the same way. Loading the file makes its own
// box object, which hooks into the admin dashboard and into every new order; take those hooks
// off again so the rest of the run is not changed. The tests below use an object of their own
// that adds no hooks at all.
if ( ! class_exists( 'SPC_Dashboard_Widget' ) ) {
	include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/dashboard.php';
	if ( isset( $spc_dashboard_widget ) && $spc_dashboard_widget instanceof SPC_Dashboard_Widget ) {
		remove_action( 'wp_dashboard_setup', array( $spc_dashboard_widget, 'init' ) );
		remove_action( 'wp_ajax_sunshine_dashboard_calculate_stats', array( $spc_dashboard_widget, 'calculate_stats' ) );
		remove_action( 'sunshine_order_create', array( $spc_dashboard_widget, 'recalculate_stats' ) );
	}
}
$dashboard_summary_widget = ( new ReflectionClass( 'SPC_Dashboard_Widget' ) )->newInstanceWithoutConstructor();

// ---------------------------------------------------------------------------------------------
// Keep this file to itself: no outside requests, no emails, no other agents' automatic
// discounts, and nothing left behind.
// ---------------------------------------------------------------------------------------------

$dashboard_summary_blocked = array();
$dashboard_summary_offline = function ( $pre, $args, $url ) use ( &$dashboard_summary_blocked ) {
	if ( false !== $pre ) {
		return $pre;
	}
	$dashboard_summary_blocked[] = $url;
	return new WP_Error( 'spc_totals_offline', 'The totals tests never reach outside services' );
};
$dashboard_summary_no      = function () {
	return false;
};
add_filter( 'pre_http_request', $dashboard_summary_offline, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', $dashboard_summary_no, PHP_INT_MAX );
add_filter( 'sunshine_checkout_allow_order_notify', $dashboard_summary_no, PHP_INT_MAX );

// Other agents test automatic discounts on this site at the same time. One of theirs must never
// land in these carts, so automatic discounts are not looked up while this file runs.
$dashboard_summary_auto_hook = has_action( 'sunshine_set_discounts', 'sunshine_discounts_set_auto' );
if ( false !== $dashboard_summary_auto_hook ) {
	remove_action( 'sunshine_set_discounts', 'sunshine_discounts_set_auto', $dashboard_summary_auto_hook );
}

// Adding to the cart makes the Analytics add-on log a row, and for a logged in customer the
// Automated Emails add-on may queue an email. Remember the products and queued emails made here,
// so those rows can be deleted at the end of this file.
$dashboard_summary_made_products = array();
$dashboard_summary_made_emails   = array();
$dashboard_summary_remember      = function ( $post_id, $post ) use ( &$dashboard_summary_made_products, &$dashboard_summary_made_emails ) {
	if ( 'sunshine-product' === $post->post_type ) {
		$dashboard_summary_made_products[] = (int) $post_id;
	} elseif ( 'sunshine-email-log' === $post->post_type ) {
		$dashboard_summary_made_emails[] = (int) $post_id;
	}
};
add_action( 'wp_insert_post', $dashboard_summary_remember, 10, 2 );

// This site writes PHP warnings to a log file, not the screen. Note every warning, notice and
// deprecation raised while this file runs, and print them at the end (PHP still logs them too).
$dashboard_summary_php_notes = array();
set_error_handler(
	function ( $level, $message, $file, $line ) use ( &$dashboard_summary_php_notes ) {
		$dashboard_summary_php_notes[ $message . ' (' . preg_replace( '#^.*/(sunshine-[^/]+/)#', '$1', $file ) . ':' . $line . ')' ] = true;
		return false;
	}
);

// The photographer who looks at the dashboard. Made once for the whole file.
$dashboard_summary_admin_id = wp_insert_user(
	array(
		'user_login' => 'spcdashboard' . wp_rand( 100000, 999999 ),
		'user_pass'  => wp_generate_password(),
		'user_email' => 'spcdashboard' . wp_rand( 100000, 999999 ) . '@example.com',
		'role'       => 'administrator',
	)
);
if ( ! is_wp_error( $dashboard_summary_admin_id ) ) {
	$t::track( 'user', $dashboard_summary_admin_id );
}

// ---------------------------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------------------------

// The orders the current test made. Only these are looked at by the dashboard and the email.
$dashboard_summary_ids = array();

// The store's time zone, set in memory. UTC unless a test asks for another one.
$dashboard_summary_zone_filter = null;
$dashboard_summary_zone        = function ( $zone ) use ( &$dashboard_summary_zone_filter ) {
	if ( $dashboard_summary_zone_filter ) {
		remove_filter( 'pre_option_timezone_string', $dashboard_summary_zone_filter );
	}
	$dashboard_summary_zone_filter = function () use ( $zone ) {
		return $zone;
	};
	add_filter( 'pre_option_timezone_string', $dashboard_summary_zone_filter );
};

// Run one test with a clean list of orders and the store in UTC, and put the time zone back after.
$dashboard_summary_test = function ( $name, $body ) use ( $t, &$dashboard_summary_ids, &$dashboard_summary_zone_filter, $dashboard_summary_zone ) {
	$t::test(
		$name,
		function () use ( $body, &$dashboard_summary_ids, &$dashboard_summary_zone_filter, $dashboard_summary_zone ) {
			$dashboard_summary_ids = array();
			$dashboard_summary_zone( 'UTC' );
			try {
				$body();
			} finally {
				remove_filter( 'pre_option_timezone_string', $dashboard_summary_zone_filter );
				$dashboard_summary_zone_filter = null;
			}
		}
	);
};

// US dollars shown as $1,234.56, so the amounts printed by the dashboard can be read back.
$dashboard_summary_us_dollars = function () use ( $t ) {
	$t::option( 'currency', 'USD' );
	$t::option( 'currency_symbol_position', 'left' );
	$t::option( 'currency_thousands_separator', ',' );
	$t::option( 'currency_decimal_separator', '.' );
	$t::option( 'currency_decimals', 2 );
	$t::option( 'currency_show_code', '' );
};

// An amount as printed ("<span class="sunshine-price">$1,234.56</span>") as a number.
$dashboard_summary_amount = function ( $html ) {
	$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
	return (float) preg_replace( '#[^0-9.\-]#', '', $text );
};

// An empty cart for the next order of the same test, keeping the test's store settings.
$dashboard_summary_fresh_cart = function () {
	foreach ( array( 'cart', 'discounts', 'gift_cards', 'gift_cards_applied', 'gift_card_amounts', 'checkout_order_id', 'use_credits' ) as $key ) {
		SPC()->session->set( $key, '' );
	}
	if ( is_user_logged_in() ) {
		SPC()->customer->set_cart( array() );
	}
	SPC()->cart = new SPC_Cart();
	SPC()->cart->set_checkout_data_item( 'use_credits', '' );
};

// Move an order to a date and time (store time), the way it would be if it had been placed then.
$dashboard_summary_date = function ( $order_id, $local ) {
	global $wpdb;
	$wpdb->update(
		$wpdb->posts,
		array(
			'post_date'     => $local,
			'post_date_gmt' => get_gmt_from_date( $local ),
		),
		array( 'ID' => $order_id )
	);
	clean_post_cache( $order_id );
};

// Place one order: $fill puts things in the cart, then the order is placed with $payment_method,
// moved to $status the way the order screen does, and dated $local (store time).
$dashboard_summary_order = function ( $local, $status, $fill, $payment_method = 'offline' ) use ( $t, &$dashboard_summary_ids, $dashboard_summary_fresh_cart, $dashboard_summary_date ) {
	$t::option( 'offline_active', 1 );
	$dashboard_summary_fresh_cart();
	$fill();
	$t::calculate();
	$order                   = $t::place_order( $payment_method );
	$dashboard_summary_ids[] = (int) $order->get_id();
	if ( $order->get_status() !== $status ) {
		$order->set_status( $status );
	}
	$dashboard_summary_date( $order->get_id(), $local );
	$dashboard_summary_fresh_cart();
	return sunshine_get_order( $order->get_id() );
};

// One taxed (or untaxed) product in the cart, $qty of them.
$dashboard_summary_buy = function ( $price, $qty = 1, $taxable = true ) use ( $t ) {
	return function () use ( $t, $price, $qty, $taxable ) {
		$t::add( $t::product( $price, array( 'taxable' => $taxable ) ), $qty );
	};
};

// What every payment method's "Refund" button does on the order once the payment company says
// the refund went through (Stripe, Square, PayPal, PayFast, Mollie, QuickPay and the others):
// the order is set to Refunded and the refunded amount is recorded on it.
$dashboard_summary_refund_through_payment_method = function ( $order, $amount ) {
	$order->set_status( 'refunded' );
	$order->add_refund( $amount );
	return sunshine_get_order( $order->get_id() );
};

// Dates (store time, which is UTC in these tests) used by the dashboard tests.
$dashboard_summary_dates = function () {
	$first_of_month = gmmktime( 0, 0, 0, (int) gmdate( 'n' ), 1, (int) gmdate( 'Y' ) );
	$last_month_end = $first_of_month - 1;
	$older          = gmmktime( 12, 0, 0, (int) gmdate( 'n', $last_month_end ) - 2, 15, (int) gmdate( 'Y', $last_month_end ) );
	return array(
		'this_month_start' => gmdate( 'Y-m-d 00:00:00', $first_of_month ),
		'today'            => gmdate( 'Y-m-d 00:00:01' ),
		'last_month_start' => gmdate( 'Y-m-01 00:00:00', $last_month_end ),
		'last_month_end'   => gmdate( 'Y-m-d 23:59:59', $last_month_end ),
		'older'            => gmdate( 'Y-m-d H:i:s', $older ),
		'long_ago'         => '1985-06-15 12:00:00',
	);
};

// Ask for the dashboard box's numbers the way the box does when the dashboard opens (its AJAX
// call), as the photographer. Returns each box's amount and count as numbers, plus the reply.
//
// The database is set to cut joined lists off at 1,024 characters, which is MySQL's own
// default (this site runs MariaDB, whose default is much larger). Most WordPress hosts run MySQL.
//
// $use_cache: false asks for fresh numbers (and deletes the cached copy afterwards), true reads
// the cached copy the way the dashboard does when it is opened again.
$dashboard_summary_stats = function ( $use_cache = false ) use ( &$dashboard_summary_ids, $dashboard_summary_widget, $dashboard_summary_admin_id, $dashboard_summary_us_dollars, $dashboard_summary_amount ) {
	global $wpdb;
	$dashboard_summary_us_dollars();
	$ids       = $dashboard_summary_ids ? implode( ',', array_map( 'intval', $dashboard_summary_ids ) ) : '0';
	$only_mine = function ( $query ) use ( $ids ) {
		if ( false !== strpos( $query, "p.post_type = 'sunshine-order'" ) ) {
			$query = rtrim( $query ) . " AND p.ID IN ($ids)";
		}
		return $query;
	};
	$ajax      = '__return_true';
	$die       = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	$old_limit = $wpdb->get_var( 'SELECT @@SESSION.group_concat_max_len' );
	$wpdb->query( 'SET SESSION group_concat_max_len = 1024' );
	$user = get_current_user_id();
	wp_set_current_user( $dashboard_summary_admin_id );
	$_REQUEST['security'] = wp_create_nonce( 'sunshine_dashboard' );
	add_filter( 'query', $only_mine );
	add_filter( 'wp_doing_ajax', $ajax );
	add_filter( 'wp_die_ajax_handler', $die );
	if ( ! $use_cache ) {
		add_filter( 'transient_sunshine-dashboard-sales', '__return_false' );
	}
	ob_start();
	try {
		$dashboard_summary_widget->calculate_stats();
	} catch ( SPC_Totals_Test_Done $e ) {
		// calculate_stats() always ends by sending a JSON reply.
	} finally {
		$output = ob_get_clean();
		remove_filter( 'query', $only_mine );
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );
		remove_filter( 'transient_sunshine-dashboard-sales', '__return_false' );
		$wpdb->query( $wpdb->prepare( 'SET SESSION group_concat_max_len = %d', $old_limit ) );
		unset( $_REQUEST['security'] );
		wp_set_current_user( $user );
		if ( ! $use_cache ) {
			delete_transient( 'sunshine-dashboard-sales' );
		}
	}
	$data = json_decode( $output, true );
	if ( ! is_array( $data ) || empty( $data['lifetime'] ) ) {
		throw new Exception( 'The dashboard did not send its numbers: ' . substr( $output, 0, 300 ) );
	}
	$stats = array( 'raw' => $data );
	foreach ( array( 'this_month', 'last_month', 'lifetime' ) as $box ) {
		$stats[ $box ] = array(
			'total' => $dashboard_summary_amount( $data[ $box ]['total'] ),
			'count' => (int) $data[ $box ]['count'],
		);
	}
	$stats['new'] = (int) $data['new']['count'];
	return $stats;
};

// ---------------------------------------------------------------------------------------------
// Dashboard: which orders count
// ---------------------------------------------------------------------------------------------

// Sunshine counts New, Processing, Ready for pickup and Shipped as paid. Pending is waiting for
// payment; Cancelled and Failed brought in nothing; an order paid with the Test payment method
// is a test order. All placed this month, every order has 10% tax:
// - New 40 -> 44, Processing 25.50 -> 28.05, Ready for pickup 10 -> 11, Shipped 60 -> 66
// - Pending 30, Cancelled 15, Failed 10, and a Test order (New) 70: none of these count
// Current month and Lifetime: 44 + 28.05 + 11 + 66 = 149.05 from 4 sales. Last month: 0 from 0.
// New Orders: 1 (the live New order; the test order is not a real order).
$dashboard_summary_test(
	'Dashboard: paid orders add up, unpaid, cancelled, failed and test orders are left out',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_stats, $dashboard_summary_dates ) {
		$t::tax( 10 );
		$day = $dashboard_summary_dates()['today'];
		$dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 40 ) );
		$dashboard_summary_order( $day, 'processing', $dashboard_summary_buy( 25.50 ) );
		$dashboard_summary_order( $day, 'pickup', $dashboard_summary_buy( 10 ) );
		$dashboard_summary_order( $day, 'shipped', $dashboard_summary_buy( 60 ) );
		$dashboard_summary_order( $day, 'pending', $dashboard_summary_buy( 30 ) );
		$dashboard_summary_order( $day, 'cancelled', $dashboard_summary_buy( 15 ) );
		$dashboard_summary_order( $day, 'failed', $dashboard_summary_buy( 10 ) );
		$test = $dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 70 ), 'test' );
		$t::same( 'test', $test->get_mode(), 'the Test payment order is a test order' );

		$stats = $dashboard_summary_stats();
		$t::money( 149.05, $stats['this_month']['total'], 'Current month sales' );
		$t::same( 4, $stats['this_month']['count'], 'Current month number of sales' );
		$t::money( 149.05, $stats['lifetime']['total'], 'Lifetime sales' );
		$t::same( 4, $stats['lifetime']['count'], 'Lifetime number of sales' );
		$t::money( 0, $stats['last_month']['total'], 'Last month sales' );
		$t::same( 0, $stats['last_month']['count'], 'Last month number of sales' );
		$t::same( 1, $stats['new'], 'New Orders' );
	}
);

// Each order lands in the box for the month it was placed in, right up to the first and last
// second of the month. No tax.
// - This month: 20 on the 1st at 00:00:00, 30 today        -> 50.00 from 2
// - Last month: 12.34 on the 1st at 00:00:00, 7.66 on its last day at 23:59:59 -> 20.00 from 2
// - Older: 100 three months back, 50 in 1985               -> Lifetime only
// Lifetime: 50 + 20 + 100 + 50 = 220.00 from 6.
// (Run on the 29th to 31st of a month, "last month" also checks the dashboard's month math.)
$dashboard_summary_test(
	'Dashboard: this month, last month and older orders land in the right box',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_stats, $dashboard_summary_dates ) {
		$dates = $dashboard_summary_dates();
		$dashboard_summary_order( $dates['this_month_start'], 'new', $dashboard_summary_buy( 20, 1, false ) );
		$dashboard_summary_order( $dates['today'], 'processing', $dashboard_summary_buy( 30, 1, false ) );
		$dashboard_summary_order( $dates['last_month_start'], 'shipped', $dashboard_summary_buy( 12.34, 1, false ) );
		$dashboard_summary_order( $dates['last_month_end'], 'new', $dashboard_summary_buy( 7.66, 1, false ) );
		$dashboard_summary_order( $dates['older'], 'shipped', $dashboard_summary_buy( 100, 1, false ) );
		$dashboard_summary_order( $dates['long_ago'], 'new', $dashboard_summary_buy( 50, 1, false ) );

		$stats = $dashboard_summary_stats();
		$t::money( 50, $stats['this_month']['total'], 'Current month sales (20 + 30)' );
		$t::same( 2, $stats['this_month']['count'], 'Current month number of sales' );
		$t::money( 20, $stats['last_month']['total'], 'Last month sales (12.34 + 7.66)' );
		$t::same( 2, $stats['last_month']['count'], 'Last month number of sales' );
		$t::money( 220, $stats['lifetime']['total'], 'Lifetime sales' );
		$t::same( 6, $stats['lifetime']['count'], 'Lifetime number of sales' );
		$t::same( 3, $stats['new'], 'New Orders (the 20, 7.66 and 50 orders are still marked New)' );
	}
);

// Order totals with cents add up to the cent. 7.25% tax:
// - 3 x 19.99 = 59.97 + 4.35 tax = 64.32
// - 100 + 7.25 tax = 107.25
// - seven orders of 0.10 with no tax = 0.70 (adding 0.10 many times is where sums drift)
// 64.32 + 107.25 + 0.70 = 172.27 from 9 sales.
$dashboard_summary_test(
	'Dashboard: amounts add up to the cent',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_stats, $dashboard_summary_dates ) {
		$t::tax( 7.25 );
		$day = $dashboard_summary_dates()['today'];
		$a   = $dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 19.99, 3 ) );
		$b   = $dashboard_summary_order( $day, 'shipped', $dashboard_summary_buy( 100 ) );
		$t::money( 64.32, $a->get_total(), 'first order total' );
		$t::money( 107.25, $b->get_total(), 'second order total' );
		for ( $i = 0; $i < 7; $i++ ) {
			$dashboard_summary_order( $day, 'processing', $dashboard_summary_buy( 0.10, 1, false ) );
		}

		$stats = $dashboard_summary_stats();
		$t::money( 172.27, $stats['this_month']['total'], 'Current month sales' );
		$t::money( 172.27, $stats['lifetime']['total'], 'Lifetime sales' );
		$t::same( 9, $stats['lifetime']['count'], 'Lifetime number of sales' );
	}
);

// The dashboard counts the money that came in for each order. A gift card or store credit was
// not new money (the gift card was counted when it was sold), so only what the customer paid
// on top counts, the same as Total Received on the Reports screen.
// - Prices include 20% tax: 3 x 12 = 36 (tax is inside)          -> 36
// - 100 + 10 tax = 110, 30 paid with a gift card                  -> 80
// - 40 + 4 tax = 44, all paid with a gift card                     -> 0 (still a sale)
// - 50 + 5 tax = 55, 20 paid with store credit                     -> 35
// Lifetime: 36 + 80 + 0 + 35 = 151 from 4 sales.
$dashboard_summary_test(
	'Dashboard: prices with tax included, gift cards and store credit',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_stats, $dashboard_summary_dates ) {
		$t::require_plugin( 'sunshine_get_gift_card_by_code', 'Gift Cards add-on' );
		$day = $dashboard_summary_dates()['today'];
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$a = $dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 12, 3 ) );
		$t::money( 36, $a->get_total(), 'prices include tax order total' );

		$t::tax( 10 );
		$t::option( 'price_has_tax', 'no' );
		$b = $dashboard_summary_order(
			$day,
			'new',
			function () use ( $t ) {
				$t::add( $t::product( 100 ) );
				$t::gift_card( 30 );
			}
		);
		$t::money( 80, $b->get_total(), 'part gift card order total' );
		$c = $dashboard_summary_order(
			$day,
			'new',
			function () use ( $t ) {
				$t::add( $t::product( 40 ) );
				$t::gift_card( 100 );
			}
		);
		$t::money( 0, $c->get_total(), 'order paid all by gift card' );
		$t::same( 'live', $c->get_mode(), 'the gift card order is a real order' );
		$d = $dashboard_summary_order(
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
		$t::money( 35, $d->get_total(), 'store credit order total' );

		$stats = $dashboard_summary_stats();
		$t::money( 151, $stats['lifetime']['total'], 'Lifetime sales (36 + 80 + 0 + 35)' );
		$t::same( 4, $stats['lifetime']['count'], 'Lifetime number of sales' );
		$t::money( 151, $stats['this_month']['total'], 'Current month sales' );
	}
);

// ---------------------------------------------------------------------------------------------
// Dashboard: refunds
// ---------------------------------------------------------------------------------------------

// Part of an order is refunded through the payment method, which marks the order Refunded. The
// rest of the order is still being made, so the photographer sets it back to Processing.
// 100 + 10 tax = 110, 21 refunded: 89 was kept. Another order: 50 + 5 tax = 55.
// 89 + 55 = 144 from 2 sales.
$dashboard_summary_test(
	'Dashboard: a part refund on an order that is still paid is taken off',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_stats, $dashboard_summary_dates, $dashboard_summary_refund_through_payment_method ) {
		$t::tax( 10 );
		$day   = $dashboard_summary_dates()['today'];
		$order = $dashboard_summary_order( $day, 'processing', $dashboard_summary_buy( 100 ) );
		$order = $dashboard_summary_refund_through_payment_method( $order, 21 );
		$order->set_status( 'processing' );
		$dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 50 ) );

		$stats = $dashboard_summary_stats();
		$t::money( 144, $stats['this_month']['total'], 'Current month sales (110 - 21 + 55)' );
		$t::money( 144, $stats['lifetime']['total'], 'Lifetime sales (110 - 21 + 55)' );
		$t::same( 2, $stats['lifetime']['count'], 'Lifetime number of sales' );
	}
);

// A refund comes off the box of the month the order was placed in, the same way the Reports
// screen counts each order's total less its refunds on the order's date.
// - Last month: 100 + 10 tax = 110, 10 refunded while it stays paid -> 100
// - This month: 50 + 5 tax = 55
// Current month 55 from 1, last month 100 from 1, Lifetime 155 from 2.
$dashboard_summary_test(
	'Dashboard: a refund comes off the month the order was placed in',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_stats, $dashboard_summary_dates ) {
		$t::tax( 10 );
		$dates = $dashboard_summary_dates();
		$old   = $dashboard_summary_order( $dates['last_month_end'], 'shipped', $dashboard_summary_buy( 100 ) );
		$old->add_refund( 10, 'Late delivery' );
		$dashboard_summary_order( $dates['today'], 'new', $dashboard_summary_buy( 50 ) );

		$stats = $dashboard_summary_stats();
		$t::money( 55, $stats['this_month']['total'], 'Current month sales' );
		$t::same( 1, $stats['this_month']['count'], 'Current month number of sales' );
		$t::money( 100, $stats['last_month']['total'], 'Last month sales (110 - 10)' );
		$t::same( 1, $stats['last_month']['count'], 'Last month number of sales' );
		$t::money( 155, $stats['lifetime']['total'], 'Lifetime sales (100 + 55)' );
		$t::same( 2, $stats['lifetime']['count'], 'Lifetime number of sales' );
	}
);

// The photographer refunds 10.10 of a 64.32 order with the payment method's Refund button (for
// example one damaged print). The payment method marks the order Refunded. The customer still
// paid 54.22 for prints they keep. Another order: 40 + 2.90 tax = 42.90.
// Assumed rule (the same one the Reports tests use): an order that was only partly refunded
// still counts as a sale, for what was kept.
// Lifetime: 54.22 + 42.90 = 97.12 from 2 sales.
$dashboard_summary_test(
	'Dashboard: a part refund through the payment method still counts what was kept',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_stats, $dashboard_summary_dates, $dashboard_summary_refund_through_payment_method ) {
		$t::tax( 7.25 );
		$day   = $dashboard_summary_dates()['today'];
		$order = $dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 19.99, 3 ) );
		$t::money( 64.32, $order->get_total(), 'order total (59.97 + 4.35 tax)' );
		$order = $dashboard_summary_refund_through_payment_method( $order, 10.10 );
		$t::money( 54.22, $order->get_total(), 'kept (64.32 - 10.10)' );
		$other = $dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 40 ) );
		$t::money( 42.90, $other->get_total(), 'other order total' );

		$stats = $dashboard_summary_stats();
		$t::money( 97.12, $stats['this_month']['total'], 'Current month sales (54.22 + 42.90)' );
		$t::same( 2, $stats['this_month']['count'], 'Current month number of sales' );
		$t::money( 97.12, $stats['lifetime']['total'], 'Lifetime sales (54.22 + 42.90)' );
		$t::same( 2, $stats['lifetime']['count'], 'Lifetime number of sales' );
	}
);

// A fully refunded order brought in nothing, whether the payment method marked it Refunded or
// the refund was recorded while the order kept its status (for example through the API
// add-on). 40 + 4 tax = 44, all 44 refunded, twice over. Another order: 50 + 5 tax = 55.
// Lifetime: 0 + 0 + 55 = 55. (Whether a fully refunded order still counts as "a sale" is left
// open here; only the amount is checked.)
$dashboard_summary_test(
	'Dashboard: a fully refunded order adds nothing',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_stats, $dashboard_summary_dates, $dashboard_summary_refund_through_payment_method ) {
		$t::tax( 10 );
		$day = $dashboard_summary_dates()['today'];
		$a   = $dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 40 ) );
		$dashboard_summary_refund_through_payment_method( $a, 44 );
		$b = $dashboard_summary_order( $day, 'shipped', $dashboard_summary_buy( 40 ) );
		$b->add_refund( 44, 'Refunded in full' );
		$dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 50 ) );

		$stats = $dashboard_summary_stats();
		$t::money( 55, $stats['this_month']['total'], 'Current month sales' );
		$t::money( 55, $stats['lifetime']['total'], 'Lifetime sales' );
	}
);

// A busy store: 160 paid orders of 10.00 this month, and 1.00 was given back on each one (for
// example a shipping overcharge, recorded on the order while it stays paid). The customers
// paid 160 x 10.00 = 1,600.00 and got 160 x 1.00 = 160.00 back: 1,440.00 came in, from 160 sales.
// The dashboard joins the IDs of the orders it found into one list of text to look up their
// refunds. On MySQL (most hosts) that list is cut off at 1,024 characters by default, which is
// about 146 orders with 6 digit IDs, so the refunds of the rest are not taken off.
$dashboard_summary_test(
	'Dashboard: refunds are taken off even with more than 150 orders',
	function () use ( $t, &$dashboard_summary_ids, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_stats, $dashboard_summary_dates ) {
		$day = $dashboard_summary_dates()['today'];
		for ( $i = 0; $i < 160; $i++ ) {
			$order = $dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 10, 1, false ) );
			$order->add_refund( 1, 'Shipping overcharge' );
		}
		$t::same( true, strlen( implode( ',', $dashboard_summary_ids ) ) > 1024, 'the list of order IDs is longer than 1,024 characters (' . strlen( implode( ',', $dashboard_summary_ids ) ) . ')' );

		$stats = $dashboard_summary_stats();
		$t::money( 1440, $stats['this_month']['total'], 'Current month sales (1,600 - 160)' );
		$t::same( 160, $stats['this_month']['count'], 'Current month number of sales' );
		$t::money( 1440, $stats['lifetime']['total'], 'Lifetime sales (1,600 - 160)' );
		$t::same( 160, $stats['lifetime']['count'], 'Lifetime number of sales' );
	}
);

// ---------------------------------------------------------------------------------------------
// Dashboard: the numbers kept for a day
// ---------------------------------------------------------------------------------------------

// The dashboard keeps its numbers for a day and works them out again only when a new order is
// made. An Offline order is made as Pending; the photographer opens the dashboard; then the
// customer pays and the photographer marks the order New. Opening the dashboard again must show
// the sale. Later 4.00 of it is refunded (recorded while it stays New), and the dashboard must
// show that too. 40 + 4 tax = 44, then 44 - 4 = 40.
// Assumed rule: the box shows the current numbers whenever an order is paid or refunded, not
// numbers up to a day old.
$dashboard_summary_test(
	'Dashboard: an order paid or refunded after the dashboard was opened shows up',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_stats, $dashboard_summary_dates ) {
		$t::tax( 10 );
		$day = $dashboard_summary_dates()['today'];
		try {
			$order = $dashboard_summary_order( $day, 'pending', $dashboard_summary_buy( 40 ) );
			// Making an order clears the dashboard's kept numbers (SPC_Dashboard_Widget::recalculate_stats()).
			delete_transient( 'sunshine-dashboard-sales' );
			$before = $dashboard_summary_stats( true );
			$t::money( 0, $before['lifetime']['total'], 'nothing paid yet' );

			$order->set_status( 'new' );
			$paid = $dashboard_summary_stats( true );
			$t::money( 44, $paid['lifetime']['total'], 'Lifetime sales after the order is paid' );
			$t::money( 44, $paid['this_month']['total'], 'Current month sales after the order is paid' );
			$t::same( 1, $paid['new'], 'New Orders after the order is paid' );

			// Start again from fresh numbers, then refund part of the order.
			delete_transient( 'sunshine-dashboard-sales' );
			$dashboard_summary_stats( true );
			sunshine_get_order( $order->get_id() )->add_refund( 4, 'One print was damaged' );
			$refunded = $dashboard_summary_stats( true );
			$t::money( 40, $refunded['lifetime']['total'], 'Lifetime sales after the refund (44 - 4)' );
		} finally {
			delete_transient( 'sunshine-dashboard-sales' );
		}
	}
);

// ---------------------------------------------------------------------------------------------
// Summary email
// ---------------------------------------------------------------------------------------------

// Run the weekly Summary email the way the weekly scheduled task does, with only this test's
// orders looked at. The email is turned on in memory with a made-up address and is stopped
// before it leaves. Returns Total Received, Orders and Avg Order as worked out for the email,
// and the Total Received amount printed in the email.
$dashboard_summary_email = function () use ( $t, &$dashboard_summary_ids, $dashboard_summary_us_dollars, $dashboard_summary_amount ) {
	$email = ( SPC()->emails && method_exists( SPC()->emails, 'get_email_by_id' ) ) ? SPC()->emails->get_email_by_id( 'summary' ) : false;
	if ( ! $email ) {
		$t::skip( 'The Summary email is not loaded' );
	}
	$dashboard_summary_us_dollars();
	$t::option( 'email_summary_active', 1 );
	$t::option( 'email_summary_recipients', 'dashboard-summary-test@example.com' );
	$t::option( 'email_summary_subject', '[sitename] summary' );

	$args      = null;
	$html      = '';
	$ids       = $dashboard_summary_ids ? array_map( 'intval', $dashboard_summary_ids ) : array( 0 );
	$grab_args = function ( $attachments, $id, $email_args ) use ( &$args ) {
		if ( 'summary' === $id ) {
			$args = $email_args;
		}
		return $attachments;
	};
	$grab_mail = function ( $atts ) use ( &$html ) {
		$html = isset( $atts['message'] ) ? $atts['message'] : '';
		return $atts;
	};
	$only_mine = function ( $query_args ) use ( $ids ) {
		$query_args['post__in'] = $ids;
		return $query_args;
	};
	add_filter( 'sunshine_email_attachments', $grab_args, 10, 3 );
	add_filter( 'wp_mail', $grab_mail, PHP_INT_MAX );
	add_filter( 'sunshine_get_order_args', $only_mine, PHP_INT_MAX );
	try {
		$email->trigger();
	} finally {
		remove_filter( 'sunshine_email_attachments', $grab_args, 10 );
		remove_filter( 'wp_mail', $grab_mail, PHP_INT_MAX );
		remove_filter( 'sunshine_get_order_args', $only_mine, PHP_INT_MAX );
	}
	if ( ! is_array( $args ) ) {
		throw new Exception( 'The Summary email was not built' );
	}
	$shown = '';
	if ( preg_match( '#Total Received\s*</h3>\s*<p[^>]*>(.*?)</p>#s', $html, $match ) ) {
		$shown = $dashboard_summary_amount( $match[1] );
	}
	return array(
		'total' => (float) $args['paid_total'],
		'count' => (int) $args['paid_count'],
		'avg'   => (float) $args['avg_order'],
		'shown' => $shown,
	);
};

// A time this many days (and hours) before now, as store time.
$dashboard_summary_ago = function ( $days, $hours = 0 ) {
	return wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS - $hours * HOUR_IN_SECONDS );
};

// Two days ago, every order has 10% tax:
// - New 40 -> 44, Processing 25.50 -> 28.05, Shipped 60 -> 66, Ready for pickup 10 -> 11
// - Pending 30, Cancelled 15, Failed 10, and a Test order (New) 70: none of these count
// Ten days ago: New 99 -> 108.90, not in this week.
// Total Received 44 + 28.05 + 66 + 11 = 149.05 from 4 orders, Avg Order 149.05 / 4 = 37.26.
$dashboard_summary_test(
	'Summary email: paid orders from the last week add up',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_email, $dashboard_summary_ago ) {
		$t::tax( 10 );
		$day = $dashboard_summary_ago( 2 );
		$dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 40 ) );
		$dashboard_summary_order( $day, 'processing', $dashboard_summary_buy( 25.50 ) );
		$dashboard_summary_order( $day, 'shipped', $dashboard_summary_buy( 60 ) );
		$dashboard_summary_order( $day, 'pickup', $dashboard_summary_buy( 10 ) );
		$dashboard_summary_order( $day, 'pending', $dashboard_summary_buy( 30 ) );
		$dashboard_summary_order( $day, 'cancelled', $dashboard_summary_buy( 15 ) );
		$dashboard_summary_order( $day, 'failed', $dashboard_summary_buy( 10 ) );
		$dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 70 ), 'test' );
		$dashboard_summary_order( $dashboard_summary_ago( 10 ), 'new', $dashboard_summary_buy( 99 ) );

		$email = $dashboard_summary_email();
		$t::money( 149.05, $email['total'], 'Total Received' );
		$t::same( 4, $email['count'], 'Orders' );
		$t::money( 37.26, $email['avg'], 'Avg Order (149.05 / 4)' );
		$t::money( 149.05, $email['shown'], 'Total Received printed in the email' );
	}
);

// A week with no paid orders: everything is 0 and nothing breaks.
$dashboard_summary_test(
	'Summary email: a week with no sales shows 0',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_email, $dashboard_summary_ago ) {
		$dashboard_summary_order( $dashboard_summary_ago( 1 ), 'pending', $dashboard_summary_buy( 25, 1, false ) );
		$email = $dashboard_summary_email();
		$t::money( 0, $email['total'], 'Total Received' );
		$t::same( 0, $email['count'], 'Orders' );
		$t::money( 0, $email['avg'], 'Avg Order' );
		$t::money( 0, $email['shown'], 'Total Received printed in the email' );
	}
);

// Same orders as the dashboard test, placed 4 days ago: Total Received is the money that came
// in for each order.
// - Prices include 20% tax: 3 x 12 = 36                   -> 36
// - 110 with 30 paid by gift card                          -> 80
// - 44 all paid by gift card                               -> 0 (still an order)
// - 55 with 20 paid by store credit                        -> 35
// Total Received 151 from 4 orders, Avg Order 151 / 4 = 37.75.
$dashboard_summary_test(
	'Summary email: prices with tax included, gift cards and store credit',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_email, $dashboard_summary_ago ) {
		$t::require_plugin( 'sunshine_get_gift_card_by_code', 'Gift Cards add-on' );
		$day = $dashboard_summary_ago( 4 );
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 12, 3 ) );

		$t::tax( 10 );
		$t::option( 'price_has_tax', 'no' );
		$dashboard_summary_order(
			$day,
			'processing',
			function () use ( $t ) {
				$t::add( $t::product( 100 ) );
				$t::gift_card( 30 );
			}
		);
		$dashboard_summary_order(
			$day,
			'shipped',
			function () use ( $t ) {
				$t::add( $t::product( 40 ) );
				$t::gift_card( 100 );
			}
		);
		$dashboard_summary_order(
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

		$email = $dashboard_summary_email();
		$t::money( 151, $email['total'], 'Total Received (36 + 80 + 0 + 35)' );
		$t::same( 4, $email['count'], 'Orders' );
		$t::money( 37.75, $email['avg'], 'Avg Order (151 / 4)' );
		$t::money( 151, $email['shown'], 'Total Received printed in the email' );
	}
);

// Same refund as the dashboard test: 110, 21 refunded through the payment method, set back to
// Processing: 89 kept. Another order 55. Total Received 144 from 2, Avg Order 72.
$dashboard_summary_test(
	'Summary email: a part refund on an order that is still paid is taken off',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_email, $dashboard_summary_ago, $dashboard_summary_refund_through_payment_method ) {
		$t::tax( 10 );
		$day   = $dashboard_summary_ago( 3 );
		$order = $dashboard_summary_order( $day, 'processing', $dashboard_summary_buy( 100 ) );
		$order = $dashboard_summary_refund_through_payment_method( $order, 21 );
		$order->set_status( 'processing' );
		$dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 50 ) );

		$email = $dashboard_summary_email();
		$t::money( 144, $email['total'], 'Total Received (110 - 21 + 55)' );
		$t::same( 2, $email['count'], 'Orders' );
		$t::money( 72, $email['avg'], 'Avg Order (144 / 2)' );
	}
);

// Same as the dashboard test: 64.32, 10.10 refunded through the payment method (which marks the
// order Refunded): 54.22 kept. Another order 42.90.
// Assumed rule: a partly refunded order still counts, for what was kept.
// Total Received 54.22 + 42.90 = 97.12 from 2 orders, Avg Order 48.56.
$dashboard_summary_test(
	'Summary email: a part refund through the payment method still counts what was kept',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_email, $dashboard_summary_ago, $dashboard_summary_refund_through_payment_method ) {
		$t::tax( 7.25 );
		$day   = $dashboard_summary_ago( 3 );
		$order = $dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 19.99, 3 ) );
		$dashboard_summary_refund_through_payment_method( $order, 10.10 );
		$dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 40 ) );

		$email = $dashboard_summary_email();
		$t::money( 97.12, $email['total'], 'Total Received (54.22 + 42.90)' );
		$t::same( 2, $email['count'], 'Orders' );
		$t::money( 48.56, $email['avg'], 'Avg Order (97.12 / 2)' );
	}
);

// Same as the dashboard test: a fully refunded order brought in nothing, whether it was marked
// Refunded or kept its paid status. 40 + 4 tax = 44, all 44 refunded, twice over. Another 55.
// The Refunded one is left out; the one still marked paid counts as an order of 0.
// Total Received 55 from 2 orders.
$dashboard_summary_test(
	'Summary email: a fully refunded order adds nothing',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_email, $dashboard_summary_ago, $dashboard_summary_refund_through_payment_method ) {
		$t::tax( 10 );
		$day = $dashboard_summary_ago( 3 );
		$a   = $dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 40 ) );
		$dashboard_summary_refund_through_payment_method( $a, 44 );
		$b = $dashboard_summary_order( $day, 'shipped', $dashboard_summary_buy( 40 ) );
		$b->add_refund( 44, 'Refunded in full' );
		$dashboard_summary_order( $day, 'new', $dashboard_summary_buy( 50 ) );

		$email = $dashboard_summary_email();
		$t::money( 55, $email['total'], 'Total Received (0 + 0 + 55)' );
		$t::same( 2, $email['count'], 'Orders (the Refunded one is left out)' );
	}
);

// The email goes out once a week and covers the week before it. Every order should be in
// exactly one weekly email, so this week's email covers the 7 x 24 hours before it is sent.
// No tax.
// - 20 placed at midnight at the start of the day 7 days ago: more than 7 days ago, so last
//   week's email (sent exactly a week ago) already counted it
// - 30 placed 6 days and 23 hours ago: this week
// Total Received 30 from 1 order.
// Assumed rule: weekly emails do not overlap (the email prints the start date as the day 7 days
// ago, the same day last week's email printed as its end date).
$dashboard_summary_test(
	'Summary email: an order is counted in only one weekly email',
	function () use ( $t, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_email, $dashboard_summary_ago ) {
		$week_ago_midnight = substr( $dashboard_summary_ago( 7 ), 0, 10 ) . ' 00:00:00';
		$dashboard_summary_order( $week_ago_midnight, 'new', $dashboard_summary_buy( 20, 1, false ) );
		$dashboard_summary_order( $dashboard_summary_ago( 6, 23 ), 'new', $dashboard_summary_buy( 30, 1, false ) );

		$email = $dashboard_summary_email();
		$t::money( 30, $email['total'], 'Total Received (only the order from this week)' );
		$t::same( 1, $email['count'], 'Orders' );
	}
);

// Order dates are saved in the store's time zone (Settings > General). The store's time zone is
// set (in memory) to one where today's date is not the same as in London, so the check does not
// depend on the time of day. No tax.
// - Store a day ahead of London (Kiritimati, UTC+14): 20 placed at noon 8 days ago (store date)
//   is more than 7 days old and must not count; 30 placed 3 days ago counts. Total 30 from 1.
// - Store a day behind London (UTC-12): 20 placed at 23:00 on the store's date 7 days ago is
//   less than 7 days old (it is at least noon today in the store) and must count; 30 placed
//   3 days ago counts. Total 50 from 2. Last week's email went out before 23:00 that day, so if
//   this email leaves it out, no email ever counts it.
$dashboard_summary_test(
	'Summary email: the week follows the store\'s time zone',
	function () use ( $t, $dashboard_summary_zone, $dashboard_summary_order, $dashboard_summary_buy, $dashboard_summary_email, $dashboard_summary_ago ) {
		$ahead = ( (int) gmdate( 'G' ) >= 10 );
		$dashboard_summary_zone( $ahead ? 'Pacific/Kiritimati' : 'Etc/GMT+12' );
		$today = current_time( 'Y-m-d' );
		$t::same( true, gmdate( 'Y-m-d' ) !== $today, 'the store\'s date is not London\'s date (store: ' . $today . ')' );
		$store_day = function ( $days_back, $time ) use ( $today ) {
			return gmdate( 'Y-m-d', strtotime( $today . ' 00:00:00 UTC' ) - $days_back * DAY_IN_SECONDS ) . ' ' . $time;
		};
		if ( $ahead ) {
			$dashboard_summary_order( $store_day( 8, '12:00:00' ), 'new', $dashboard_summary_buy( 20, 1, false ) );
		} else {
			$dashboard_summary_order( $store_day( 7, '23:00:00' ), 'new', $dashboard_summary_buy( 20, 1, false ) );
		}
		$dashboard_summary_order( $dashboard_summary_ago( 3 ), 'new', $dashboard_summary_buy( 30, 1, false ) );

		$email = $dashboard_summary_email();
		if ( $ahead ) {
			$t::money( 30, $email['total'], 'Total Received (store a day ahead: the 8 day old order is left out)' );
			$t::same( 1, $email['count'], 'Orders (store a day ahead)' );
		} else {
			$t::money( 50, $email['total'], 'Total Received (store a day behind: the 6.5 day old order counts)' );
			$t::same( 2, $email['count'], 'Orders (store a day behind)' );
		}
	}
);

// ---------------------------------------------------------------------------------------------
// Put everything back the way it was.
// ---------------------------------------------------------------------------------------------

remove_filter( 'pre_http_request', $dashboard_summary_offline, PHP_INT_MAX );
remove_filter( 'pre_wp_mail', $dashboard_summary_no, PHP_INT_MAX );
remove_filter( 'sunshine_checkout_allow_order_notify', $dashboard_summary_no, PHP_INT_MAX );
remove_action( 'wp_insert_post', $dashboard_summary_remember, 10 );
if ( false !== $dashboard_summary_auto_hook ) {
	add_action( 'sunshine_set_discounts', 'sunshine_discounts_set_auto', $dashboard_summary_auto_hook );
}
foreach ( array_unique( $dashboard_summary_made_emails ) as $dashboard_summary_email_id ) {
	wp_delete_post( $dashboard_summary_email_id, true );
}
global $wpdb;
if ( ! empty( $dashboard_summary_made_products ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sunshine_actions' ) ) ) {
	$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_actions WHERE action_type = 'add_to_cart' AND action_object_id IN (" . implode( ',', array_map( 'intval', array_unique( $dashboard_summary_made_products ) ) ) . ')' );
}
restore_error_handler();
if ( ! empty( $dashboard_summary_php_notes ) ) {
	WP_CLI::log( 'dashboard-summary PHP warnings and notices: ' . implode( ' | ', array_keys( $dashboard_summary_php_notes ) ) );
}
if ( ! empty( $dashboard_summary_blocked ) ) {
	WP_CLI::log( 'dashboard-summary blocked outgoing requests to: ' . implode( ', ', array_unique( $dashboard_summary_blocked ) ) );
}
