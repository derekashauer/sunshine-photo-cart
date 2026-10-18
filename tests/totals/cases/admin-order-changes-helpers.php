<?php
/**
 * Helpers for the admin-order-changes case files: changes the photographer makes by hand on an
 * order after it was placed.
 * - Changing the order status on the order screen (the Update button) or with the bulk action
 *   on the orders list: marking an unpaid order paid, cancelling, refunding.
 * - "Attempt to fix it" on an order whose saved lines don't match its cart, which deletes the
 *   lines and builds them again.
 * - The Packages add-on's update for old Sunshine 2 orders, which rebuilds package lines when the
 *   order screen opens.
 *
 * This file only sets up helpers. The tests are in admin-order-changes-items.php and
 * admin-order-changes-status.php, which load after it. Run them with
 * SPC_TOTALS_ONLY=admin-order-changes.
 *
 * The order screen code only loads in wp-admin, so it is loaded here once and its hooks are
 * taken back off. The tests call its steps the way a click on the order screen does.
 *
 * Nothing leaves the site: every web request is refused, emails are stopped, order receipts are
 * not sent, and add-ons that only report the order to outside services are switched off while a
 * test runs. Everything a test makes is deleted at the end of the run.
 */

$t = 'SPC_Totals_Test';

// Web requests a test tried to send. All of them are refused.
$admin_order_changes_sent = array();

$admin_order_changes_http = function ( $pre, $args, $url ) use ( &$admin_order_changes_sent ) {
	$admin_order_changes_sent[] = $url;
	return new WP_Error( 'spc_totals_offline', 'Web requests are blocked while the totals tests run' );
};

// Add-ons on sunshine_checkout_create_order / sunshine_order_create that only send the order to
// mailing lists or Light Blue, queue follow-up emails, or write Analytics rows.
$admin_order_changes_side_hooks = array(
	array( 'sunshine_checkout_create_order', 'sunshine_mailchimp_order_create' ),
	array( 'sunshine_checkout_create_order', 'sunshine_campaign_monitor_order_create' ),
	array( 'sunshine_checkout_create_order', 'sunshine_lightblue_order_post_process' ),
	array( 'sunshine_checkout_create_order', 'sunshine_adv_reports_order_create' ),
	array( 'sunshine_checkout_create_order', 'sunshine_email_queue_after_order' ),
	array( 'sunshine_order_create', 'sunshine_adv_reports_order_create' ),
);

// PHP warnings, notices and deprecations raised by the photographer's steps in this test.
$admin_order_changes_warnings = array();

/**
 * One admin-order-changes test. Same as $t::test(), with web requests refused, no emails, no
 * order receipts and the side add-ons off.
 */
$admin_order_changes_test = function ( $name, $callback ) use ( $t, $admin_order_changes_http, &$admin_order_changes_sent, $admin_order_changes_side_hooks, &$admin_order_changes_warnings ) {
	$t::test(
		$name,
		function () use ( $callback, $admin_order_changes_http, &$admin_order_changes_sent, $admin_order_changes_side_hooks, &$admin_order_changes_warnings ) {
			global $wp_filter;
			$admin_order_changes_sent     = array();
			$admin_order_changes_warnings = array();
			add_filter( 'pre_http_request', $admin_order_changes_http, PHP_INT_MAX, 3 );
			add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
			add_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );

			$notify = isset( $wp_filter['sunshine_order_notify'] ) ? $wp_filter['sunshine_order_notify'] : null;
			unset( $wp_filter['sunshine_order_notify'] );

			$removed = array();
			foreach ( $admin_order_changes_side_hooks as $side ) {
				$priority = has_action( $side[0], $side[1] );
				if ( false === $priority ) {
					continue;
				}
				$args      = $wp_filter[ $side[0] ]->callbacks[ $priority ][ $side[1] ]['accepted_args'];
				$removed[] = array( $side[0], $side[1], $priority, $args );
				remove_action( $side[0], $side[1], $priority );
			}

			$filters_before = $GLOBALS['wp_current_filter'];
			try {
				$callback();
			} finally {
				$GLOBALS['wp_current_filter'] = $filters_before;
				remove_filter( 'pre_http_request', $admin_order_changes_http, PHP_INT_MAX );
				remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
				remove_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
				if ( $notify ) {
					$wp_filter['sunshine_order_notify'] = $notify;
				}
				foreach ( $removed as $side ) {
					add_action( $side[0], $side[1], $side[2], $side[3] );
				}
				foreach ( array( '_GET', '_POST', '_REQUEST' ) as $global ) {
					foreach ( array_keys( $GLOBALS[ $global ] ) as $key ) {
						unset( $GLOBALS[ $global ][ $key ] );
					}
				}
			}
		}
	);
};

/*
 * The order screen.
 */

// The order screen's code (SPC_Admin_Order). It only loads in wp-admin, so it is loaded here.
// Loading the file also hooks a copy of it into WordPress for the rest of the run; those hooks
// are taken back off so the other case files run the same as before. The steps are called on a
// copy made without its hooks.
$admin_order_changes_admin = function () {
	static $admin = null;
	if ( null !== $admin ) {
		return $admin;
	}
	if ( ! class_exists( 'SPC_Admin_Order' ) ) {
		global $wp_filter;
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/sunshine-order.php';
		if ( isset( $SPC_Admin_Order ) && is_object( $SPC_Admin_Order ) ) {
			foreach ( $wp_filter as $hook => $hook_object ) {
				foreach ( $hook_object->callbacks as $priority => $callbacks ) {
					foreach ( $callbacks as $callback ) {
						if ( is_array( $callback['function'] ) && $callback['function'][0] === $SPC_Admin_Order ) {
							remove_filter( $hook, $callback['function'], $priority );
						}
					}
				}
			}
		}
		remove_action( 'wp', 'sunshine_admin_order_check' );
	}
	$admin = ( new ReflectionClass( 'SPC_Admin_Order' ) )->newInstanceWithoutConstructor();
	return $admin;
};

// The photographer's login: a Sunshine Manager account, made once and deleted at the end of the
// run. It never buys anything, so it has no cart, credits or free prints of its own.
$admin_order_changes_photographer_id = array( 'id' => 0 );

$admin_order_changes_photographer = function () use ( $t, &$admin_order_changes_photographer_id ) {
	if ( $admin_order_changes_photographer_id['id'] && get_userdata( $admin_order_changes_photographer_id['id'] ) ) {
		return $admin_order_changes_photographer_id['id'];
	}
	$id = wp_insert_user(
		array(
			'user_login' => 'spcphotog' . wp_rand( 100000, 999999 ),
			'user_pass'  => wp_generate_password(),
			'role'       => get_role( 'sunshine_manager' ) ? 'sunshine_manager' : 'administrator',
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new Exception( 'Could not make the photographer account: ' . $id->get_error_message() );
	}
	$t::track( 'user', $id );
	$admin_order_changes_photographer_id['id'] = $id;
	return $id;
};

// Session values the customer's checkout keeps. The photographer has a session of their own.
$admin_order_changes_session_keys = array( 'cart', 'discounts', 'checkout_data', 'checkout_sections_completed', 'gift_cards', 'gift_cards_applied', 'gift_card_amounts', 'use_credits', 'checkout_order_id', 'paypal_order_id' );

/**
 * Run $callback as the photographer working in wp-admin: logged in with their own account and
 * their own session. PHP warnings it raises are kept for the test to check. The customer's login
 * and session are put back afterwards.
 */
$admin_order_changes_as_photographer = function ( $callback ) use ( $admin_order_changes_photographer, $admin_order_changes_session_keys, &$admin_order_changes_warnings ) {
	$user_id = get_current_user_id();
	$saved   = array();
	foreach ( $admin_order_changes_session_keys as $key ) {
		$saved[ $key ] = SPC()->session->get( $key );
		SPC()->session->set( $key, '' );
	}
	$photographer = $admin_order_changes_photographer();
	wp_set_current_user( $photographer );
	SPC()->customer = new SPC_Customer( $photographer );
	SPC()->cart     = new SPC_Cart();
	set_error_handler(
		function ( $errno, $errstr, $errfile, $errline ) use ( &$admin_order_changes_warnings ) {
			if ( error_reporting() & $errno ) {
				$admin_order_changes_warnings[] = $errstr . ' (' . basename( $errfile ) . ':' . $errline . ')';
			}
			return false;
		}
	);
	try {
		return $callback();
	} finally {
		restore_error_handler();
		foreach ( $saved as $key => $value ) {
			SPC()->session->set( $key, $value );
		}
		wp_set_current_user( $user_id );
		SPC()->customer = new SPC_Customer( $user_id );
		SPC()->cart     = new SPC_Cart();
	}
};

/**
 * The photographer picks a new status in the order screen's status box and presses Update.
 * WordPress saves the post and fires save_post, where the order screen changes the status.
 * "Notify customer" is left off. $extra adds other fields the form sends.
 */
$admin_order_changes_save_status = function ( $order_id, $status, $extra = array() ) use ( $admin_order_changes_admin, $admin_order_changes_as_photographer ) {
	$admin = $admin_order_changes_admin();
	$admin_order_changes_as_photographer(
		function () use ( $admin, $order_id, $status, $extra ) {
			$_POST = array_merge(
				array(
					'post_ID'      => (string) $order_id,
					'post_type'    => 'sunshine-order',
					'order_status' => $status,
					// The order screen's own nonce, as WordPress prints it on the edit screen.
					'_wpnonce'     => wp_create_nonce( 'update-post_' . $order_id ),
				),
				$extra
			);
			add_action( 'save_post', array( $admin, 'save_post' ) );
			try {
				do_action( 'save_post', $order_id, get_post( $order_id ), true );
			} finally {
				remove_action( 'save_post', array( $admin, 'save_post' ) );
				$_POST = array();
			}
		}
	);
	return sunshine_get_order( $order_id );
};

// The orders list: tick orders and pick "Change status to: ..." from Bulk actions.
$admin_order_changes_bulk_status = function ( $order_ids, $status ) use ( $admin_order_changes_admin, $admin_order_changes_as_photographer ) {
	$admin = $admin_order_changes_admin();
	$admin_order_changes_as_photographer(
		function () use ( $admin, $order_ids, $status ) {
			$admin->handle_bulk_actions( admin_url( 'edit.php' ), 'sunshine_order_status_' . $status, $order_ids );
		}
	);
};

/*
 * Order lines, read straight from the database: what the order keeps for each line.
 */

// Lines by product ID: qty, price for one item, tax for one item and the line's discount.
$admin_order_changes_rows = function ( $order_id ) {
	global $wpdb;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT order_item_id, product_id, qty, price, tax, discount FROM {$wpdb->prefix}sunshine_order_items WHERE order_id = %d ORDER BY order_item_id",
			$order_id
		),
		ARRAY_A
	);
	$lines = array();
	foreach ( $rows as $row ) {
		$lines[ (int) $row['product_id'] ][] = $row;
	}
	return $lines;
};

$admin_order_changes_row_ids = function ( $order_id ) {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT order_item_id FROM {$wpdb->prefix}sunshine_order_items WHERE order_id = %d", $order_id ) ) );
};

// One saved line goes missing, the way a line does when saving the order stops part way.
// The order screen then says "This order has 2 items in meta, but 1 items in the database".
$admin_order_changes_lose_line = function ( $order_id, $product_id ) {
	global $wpdb;
	$item_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_item_id FROM {$wpdb->prefix}sunshine_order_items WHERE order_id = %d AND product_id = %d LIMIT 1", $order_id, $product_id ) );
	if ( ! $item_id ) {
		throw new Exception( 'No line for product ' . $product_id . ' on order ' . $order_id );
	}
	$wpdb->delete( $wpdb->prefix . 'sunshine_order_itemmeta', array( 'order_item_id' => $item_id ) );
	$wpdb->delete( $wpdb->prefix . 'sunshine_order_items', array( 'order_item_id' => $item_id ) );
};

/**
 * The photographer clicks "Attempt to fix it" on the order screen. The link carries the order ID
 * and a nonce, and the order screen rebuilds the lines on admin_init.
 *
 * The rebuild deletes the old lines but leaves their extra details (item meta) in the database,
 * so those are deleted here afterwards to keep the shared site clean.
 */
$admin_order_changes_fix_items = function ( $order_id ) use ( $admin_order_changes_admin, $admin_order_changes_as_photographer, $admin_order_changes_row_ids ) {
	global $wpdb;
	$admin  = $admin_order_changes_admin();
	$before = $admin_order_changes_row_ids( $order_id );
	$admin_order_changes_as_photographer(
		function () use ( $admin, $order_id ) {
			$_GET['post']            = (string) $order_id;
			$_GET['action']          = 'edit';
			$_GET['fix_order_items'] = wp_create_nonce( 'sunshine_fix_order_items' );
			try {
				$admin->fix_order_items();
			} finally {
				unset( $_GET['post'], $_GET['action'], $_GET['fix_order_items'] );
			}
		}
	);
	$after = $admin_order_changes_row_ids( $order_id );
	foreach ( array_diff( $before, $after ) as $gone ) {
		$wpdb->delete( $wpdb->prefix . 'sunshine_order_itemmeta', array( 'order_item_id' => $gone ) );
	}
};

// Line checks: the line still holds what the customer was charged.
$admin_order_changes_same_line = function ( $expected, $actual, $label ) use ( $t ) {
	if ( empty( $actual ) ) {
		$t::same( true, false, $label . ' is on the order' );
		return;
	}
	$t::same( (int) $expected['qty'], (int) $actual['qty'], $label . ' quantity' );
	$t::money( $expected['price'], $actual['price'], $label . ' price for one' );
	$t::money( $expected['tax'], $actual['tax'], $label . ' tax for one' );
	$t::money( $expected['discount'], $actual['discount'], $label . ' discount' );
};

/*
 * Checkout. Copied from the order-finish helpers (case files never load each other).
 */

// The customer picked this payment method at checkout: on for this test only, no fee.
$admin_order_changes_use = function ( $id ) use ( $t ) {
	$t::option( $id . '_active', 1 );
	$t::option( $id . '_mode', 'test' );
	$t::option( $id . '_fee', 'none' );
	$t::option( $id . '_fee_amount', '' );
	SPC()->cart->set_checkout_data_item( 'payment_method', $id );
};

// Place the order and press Place Order. With Test payment the order is paid (New). With
// Offline (check or cash) everything happens the same at checkout but the order stays Pending
// until the photographer marks it paid.
$admin_order_changes_checkout = function ( $payment_method = 'test' ) use ( $t, $admin_order_changes_use ) {
	if ( ! in_array( $payment_method, array( 'test', 'free' ), true ) ) {
		$admin_order_changes_use( $payment_method );
	}
	$t::checkout_address();
	$t::calculate();
	$order = $t::place_order( $payment_method );
	$done  = SPC()->cart->process_order();
	$t::same( true, ! empty( $done ), 'checkout finished the order: ' . wp_json_encode( SPC()->cart->get_errors() ) );
	return sunshine_get_order( $order->get_id() );
};

/**
 * The customer presses Place Order with a payment service that sends them away to pay, and never
 * comes back (they paid the photographer another way, or the service never sent its notice).
 * The service's step is swapped for one that ends the request the same way, so nothing reaches
 * the service and nothing after it in process_order() runs, just like on a live site.
 */
$admin_order_changes_leave_to_pay = function ( $gateway_id ) use ( $t, $admin_order_changes_use ) {
	global $wp_filter;
	$admin_order_changes_use( $gateway_id );
	$t::checkout_address();
	$t::calculate();
	$order = $t::place_order( $gateway_id );

	$hook  = 'sunshine_checkout_process_payment_' . $gateway_id;
	$saved = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ] : null;
	unset( $wp_filter[ $hook ] );
	$leave = function () {
		throw new SPC_Totals_Test_Done();
	};
	add_action( $hook, $leave );
	$filters_before = $GLOBALS['wp_current_filter'];
	$left           = false;
	try {
		SPC()->cart->process_order();
	} catch ( SPC_Totals_Test_Done $e ) {
		$left = true;
	} finally {
		$GLOBALS['wp_current_filter'] = $filters_before;
		unset( $wp_filter[ $hook ] );
		if ( $saved ) {
			$wp_filter[ $hook ] = $saved;
		}
	}
	$t::same( true, $left, 'customer is sent to the payment service' );
	$order = sunshine_get_order( $order->get_id() );
	$t::same( 'pending', $order->get_status(), 'order waits for the payment as Pending' );
	return $order;
};

/*
 * Fixtures, saved the way the admin screens and the add-ons save them. Copied from the
 * order-finish helpers.
 */

// Sell Anything product: the product screen saves every field, blank ones as ''. Stock is text.
$admin_order_changes_general = function ( $price, $stock ) use ( $t ) {
	$t::require_plugin( 'sunshine_general_product_reduce_stock', 'Sell Anything add-on' );
	$level = sunshine_get_default_price_level_id();
	$id    = wp_insert_post(
		array(
			'post_type'   => 'sunshine-product',
			'post_status' => 'publish',
			'post_title'  => 'Admin order changes general product',
		)
	);
	$t::track( 'post', $id );
	$meta = array(
		'type'             => 'general',
		'price'            => array( $level => (string) $price ),
		'taxable'          => '',
		'disable_shipping' => '',
		'shipping'         => array( $level => '' ),
		'min_qty'          => '',
		'max_qty'          => '',
		'stock'            => (string) $stock,
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
};

// A gift card product the way the Gift Cards add-on makes it.
$admin_order_changes_gift_card_product = function () use ( $t ) {
	$t::require_plugin( 'sunshine_gift_cards_create_from_order', 'Gift Cards add-on' );
	$id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-product',
			'post_status' => 'publish',
			'post_title'  => 'Admin order changes gift card',
		)
	);
	$t::track( 'post', $id );
	update_post_meta( $id, 'type', 'gift-card' );
	update_post_meta( $id, 'price', array( sunshine_get_default_price_level_id() => '' ) );
	update_post_meta( $id, 'taxable', '' );
	update_post_meta( $id, 'gift_card_preset_amounts', '25,50,100,200,500' );
	update_post_meta( $id, 'gift_card_allow_custom', '1' );
	update_post_meta( $id, 'gift_card_max_amount', '1000' );
	return $id;
};

// Buy a gift card the way the Gift Cards page adds it: amount and recipient in the form post.
$admin_order_changes_buy_gift_card = function ( $product_id, $amount ) {
	$_POST['gift_card_amount']          = (string) $amount;
	$_POST['gift_card_delivery']        = 'immediate';
	$_POST['gift_card_recipient_name']  = 'Admin Order Changes Gift';
	$_POST['gift_card_recipient_email'] = 'admin-order-changes-gift@example.com';
	try {
		$item = SPC()->cart->add_item( $product_id, 0, '', sunshine_get_default_price_level_id(), '', 1 );
	} finally {
		unset( $_POST['gift_card_amount'], $_POST['gift_card_delivery'], $_POST['gift_card_recipient_name'], $_POST['gift_card_recipient_email'] );
	}
	if ( empty( $item ) ) {
		throw new Exception( 'Could not add the gift card to the cart' );
	}
	return $item;
};

// Gift cards an order made, as array( card ID => balance ). Tracked so they are deleted.
$admin_order_changes_cards_made = function ( $order_id ) use ( $t ) {
	$cards    = get_posts(
		array(
			'post_type'      => 'sunshine-gift-card',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => 'purchase_order_id',
			'meta_value'     => $order_id,
		)
	);
	$balances = array();
	foreach ( $cards as $card_id ) {
		$t::track( 'post', $card_id );
		$balances[ $card_id ] = (float) get_post_meta( $card_id, 'balance', true );
	}
	return $balances;
};

$admin_order_changes_card_balance = function ( $card_id ) {
	return (float) get_post_meta( $card_id, 'balance', true );
};

// A Session Fee product with nothing included, and a session date for it the way the Schedule
// screen adds one: a published sunshine-session post dated in the future, booked saved as false.
$admin_order_changes_session = function ( $price ) use ( $t ) {
	$t::require_plugin( 'sunshine_session_fees_checkout_create_order', 'Session Fees add-on' );
	$product_id = $t::product(
		$price,
		array(
			'name'    => 'Admin order changes session',
			'type'    => 'session-fee',
			'taxable' => false,
		)
	);
	update_post_meta( $product_id, 'require_session', '' );
	update_post_meta( $product_id, 'session_products', array() );

	$when    = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ) . ' 10:00:00';
	$date_id = wp_insert_post(
		array(
			'post_status'   => 'publish',
			'post_type'     => 'sunshine-session',
			'post_title'    => 'Session ' . substr( $when, 0, 10 ) . ' @ 10:00',
			'post_date'     => $when,
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $when ) ),
		)
	);
	$t::track( 'post', $date_id );
	update_post_meta( $date_id, 'booked', false );
	return array( $product_id, $date_id );
};

// Add the session fee with its date the way the session fees page does.
$admin_order_changes_add_session = function ( $product_id, $date_id ) {
	$item = SPC()->cart->add_item( $product_id, '', '', sunshine_get_default_price_level_id(), '', 1, '', false, array( 'session_date' => $date_id ) );
	if ( empty( $item ) ) {
		throw new Exception( 'Could not add the session fee to the cart' );
	}
	SPC()->cart->update_item_by_hash( $item['hash'], $item );
	return $item;
};

// Prints a session paid for earlier left on the customer, in the shape Session Fees saves them.
$admin_order_changes_give_included = function ( $customer_id, $product_id, $qty ) {
	update_user_meta(
		$customer_id,
		'sunshine_session_products',
		array(
			array(
				'product'  => (string) $product_id,
				'qty'      => (string) $qty,
				'order_id' => 0,
			),
		)
	);
	SPC()->customer = new SPC_Customer( $customer_id );
};

// How many free prints of a product the customer still has.
$admin_order_changes_included_left = function ( $customer_id, $product_id ) {
	$left = 0;
	foreach ( (array) get_user_meta( $customer_id, 'sunshine_session_products', true ) as $row ) {
		if ( is_array( $row ) && isset( $row['product'], $row['qty'] ) && $row['product'] == $product_id && $row['qty'] > 0 ) {
			$left += $row['qty'];
		}
	}
	return $left;
};

$admin_order_changes_credits = function ( $customer_id ) {
	return ( new SPC_Customer( $customer_id ) )->get_credits();
};

/**
 * One of each thing that happens once an order is paid. No tax, so the numbers are easy to
 * follow. Store credit is whole dollars (credits with cents are a known separate bug).
 *
 * A logged in customer with 20.00 store credit and 2 free prints left from an earlier session.
 * - 3 prints at 10.00, 2 of them free: 10.00
 * - 2 Sell Anything products at 15.00 (5 in stock), 10% off with a code: 30.00 - 3.00 = 27.00
 * - a 50.00 gift card for someone else: 50.00
 * - a 100.00 session fee with a session date: 100.00
 * - 5.00 flat rate shipping
 * 10 + 27 + 50 + 100 + 5 = 192.00. A 25.00 gift card pays 25 and store credit pays 20:
 * 192 - 25 - 20 = 147.00 left to pay.
 */
$admin_order_changes_everything_cart = function () use ( $t, $admin_order_changes_general, $admin_order_changes_gift_card_product, $admin_order_changes_buy_gift_card, $admin_order_changes_session, $admin_order_changes_add_session, $admin_order_changes_give_included ) {
	$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
	$customer_id = $t::customer();
	update_user_meta( $customer_id, 'sunshine_credits', '20' );

	$print = $t::product( 10, array( 'taxable' => false ) );
	$admin_order_changes_give_included( $customer_id, $print, 2 );
	$general                         = $admin_order_changes_general( 15, 5 );
	$card_product                    = $admin_order_changes_gift_card_product();
	list( $session, $session_date ) = $admin_order_changes_session( 100 );

	$t::add( $print, 3 );
	$t::add( $general, 2 );
	$admin_order_changes_buy_gift_card( $card_product, 50 );
	$admin_order_changes_add_session( $session, $session_date );

	$code = $t::discount( 'percent-product', 10, array( 'allowed_products' => array( $general ) ) );
	$card = $t::gift_card( 25 );
	$t::flat_shipping( 5 );
	SPC()->cart->set_checkout_data_item( 'use_credits', 1 );

	$t::checkout_address();
	$cart = $t::calculate();
	$t::money( 192, $cart->get_total( array( 'fees', 'credits' ) ), 'cart total before gift card and credit' );
	$t::money( 147, $cart->get_total(), 'cart total left to pay' );

	return array(
		'customer'     => $customer_id,
		'print'        => $print,
		'general'      => $general,
		'session_date' => $session_date,
		'discount'     => sunshine_get_discount_by_code( $code )->get_id(),
		'card'         => $card,
	);
};

/**
 * Everything that should be true once the order from the cart above has been paid, each thing
 * done exactly once. $label says which step was just taken, so a failure says where.
 */
$admin_order_changes_check_finished = function ( $order_id, $ids, $label ) use ( $t, $admin_order_changes_card_balance, $admin_order_changes_cards_made, $admin_order_changes_included_left, $admin_order_changes_credits ) {
	$order = sunshine_get_order( $order_id );
	$t::same( true, $order->is_paid(), $label . ': order is paid (status ' . $order->get_status() . ')' );
	$t::money( 147, $order->get_total(), $label . ': order total is still what the customer pays' );
	$t::money( 20, $order->get_credits(), $label . ': store credit on the order' );

	// The 25.00 card paid 25.00 of this order, once.
	$t::money( 0, $admin_order_changes_card_balance( $ids['card'] ), $label . ': gift card used comes down from 25.00 to 0.00' );
	$t::same( 1, count( sunshine_get_gift_card( $ids['card'] )->get_usage_log() ), $label . ': gift card history has one use' );

	// One new 50.00 card for the recipient.
	$made = $admin_order_changes_cards_made( $order_id );
	$t::same( 1, count( $made ), $label . ': gift card bought is made once' );
	$t::money( 50, $made ? reset( $made ) : 0, $label . ': gift card bought has 50.00 on it' );

	$date = sunshine_get_session_date( $ids['session_date'] );
	$t::same( true, $date->is_booked(), $label . ': session date is booked' );
	$t::same( (string) $order_id, (string) $date->get_order_id(), $label . ': session date is tied to the order' );

	$t::same( 0, (int) $admin_order_changes_included_left( $ids['customer'], $ids['print'] ), $label . ': 2 free prints are used up' );
	$t::same( 3, (int) get_post_meta( $ids['general'], 'stock', true ), $label . ': Sell Anything stock goes from 5 to 3, once' );

	$discount = sunshine_get_discount( $ids['discount'] );
	$t::same( 1, $discount->get_use_count(), $label . ': discount code use is counted once' );
	$t::same( 0, (int) $admin_order_changes_credits( $ids['customer'] ), $label . ': store credit comes down from 20 to 0, once' );
};

/*
 * Product options, quantity discounts. Copied from core-products-helpers.php and
 * core-orders-after-items.php.
 */

$admin_order_changes_all_levels = function ( $prices ) {
	$all = array();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		if ( $level ) {
			$all[ $level->get_id() ] = '';
		}
	}
	foreach ( $prices as $level_id => $price ) {
		$all[ $level_id ] = (string) $price;
	}
	return $all;
};

// A checkbox option (like rush printing), made through the Product Options add-on's own save code.
$admin_order_changes_checkbox_option = function () use ( $t ) {
	$t::require_plugin( 'sunshine_product_options_save', 'Product Options add-on' );
	$saved_post = $_POST;
	$_POST      = array( 'type' => 'checkbox' );
	try {
		$term = wp_insert_term( 'Admin order changes rush ' . wp_rand( 100000, 999999 ), 'sunshine-product-option' );
	} finally {
		$_POST = $saved_post;
	}
	if ( is_wp_error( $term ) ) {
		throw new Exception( 'Could not create a product option: ' . $term->get_error_message() );
	}
	$t::track( 'term', $term['term_id'], 'sunshine-product-option' );
	return (int) $term['term_id'];
};

// A checkbox option's price on a product, saved the way the product screen's Options box does.
$admin_order_changes_option_price = function ( $product_id, $option_id, $price ) use ( $admin_order_changes_all_levels ) {
	update_post_meta( $product_id, 'options', array( $option_id => $admin_order_changes_all_levels( array( sunshine_get_default_price_level_id() => $price ) ) ) );
};

// Add a store product with options the way the store's add to cart window does.
$admin_order_changes_add_with_options = function ( $product_id, $qty, $options ) {
	$saved_post = $_POST;
	$_POST      = array(
		'product_id'  => $product_id,
		'price_level' => sunshine_get_default_price_level_id(),
		'qty'         => $qty,
		'options'     => $options,
		'security'    => wp_create_nonce( 'sunshinephotocart' ),
	);
	$ajax       = '__return_true';
	$die        = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'wp_doing_ajax', $ajax );
	add_filter( 'wp_die_ajax_handler', $die );
	ob_start();
	try {
		sunshine_modal_add_item_to_cart();
	} catch ( SPC_Totals_Test_Done $e ) {
		// The handler ends by sending JSON.
	} finally {
		$output = ob_get_clean();
		$_POST  = $saved_post;
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );
	}
	$response = json_decode( $output, true );
	if ( empty( $response['success'] ) ) {
		throw new Exception( 'Could not add the product with options: ' . substr( $output, 0, 300 ) );
	}
};

// A quantity discount rule saved the way the product screen saves it: X% off each from 5.
$admin_order_changes_qty_rule = function ( $percent ) {
	return array(
		'calc'  => '1',
		'items' => array(
			array(
				'qty'      => '5',
				'discount' => (string) $percent,
				'type'     => 'percent',
			),
		),
	);
};
