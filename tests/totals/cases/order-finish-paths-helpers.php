<?php
/**
 * Helpers for the order-finish-paths case files: what happens once a placed order is paid.
 *
 * There are two ways an order gets finished:
 * - At checkout, SPC_Cart::process_order() takes the payment, finishes the order with
 *   post_process_order() and then fires sunshine_checkout_create_order, where the add-ons do
 *   their part: take gift card balances off, make the gift cards that were bought, use up
 *   included session prints, lower Sell Anything stock.
 * - Payment services that send the customer away to pay (Mollie, PayFast, Paystack, QuickPay,
 *   Systempay, Mercado Pago Pro, PayPal Legacy, Stripe hosted checkout) end the checkout
 *   request with a redirect and exit. The order is finished later, when the service tells the
 *   site the customer paid (a server notice) or the customer comes back (a browser return).
 *
 * This file only sets up helpers. The tests are in order-finish-paths-paid.php and
 * order-finish-paths-redirect.php, which load after it. Run them together with
 * SPC_TOTALS_ONLY=order-finish-paths.
 *
 * Nothing leaves the site: every web request is answered here (or refused), emails are stopped,
 * order receipts are not sent, and add-ons that only report the order to outside services or
 * write report rows (mailing lists, Light Blue, email queues, Analytics) are switched off while
 * each test runs. Everything a test makes is deleted at the end of the run.
 */

$t = 'SPC_Totals_Test';

// Replies for outgoing web requests in the current test: array( url regex, status code, body ).
// The body is sent as is when it is text, as JSON when it is an array. Anything else is refused.
$order_finish_replies = array();
$order_finish_sent    = array();

$order_finish_http = function ( $pre, $args, $url ) use ( &$order_finish_replies, &$order_finish_sent ) {
	$order_finish_sent[] = array(
		'url'    => $url,
		'method' => isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET',
		'body'   => isset( $args['body'] ) ? $args['body'] : null,
	);
	foreach ( $order_finish_replies as $reply ) {
		if ( preg_match( $reply[0], $url ) ) {
			return array(
				'headers'  => array(),
				'body'     => is_string( $reply[2] ) ? $reply[2] : wp_json_encode( $reply[2] ),
				'response' => array(
					'code'    => $reply[1],
					'message' => 'Totals test',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
	}
	return new WP_Error( 'spc_totals_offline', 'Web requests are blocked while the totals tests run' );
};

// Add-ons on sunshine_checkout_create_order / sunshine_order_create that have nothing to do with
// money: they send the order to mailing lists or Light Blue, queue follow-up emails, or write
// Analytics rows. They are taken off while a test runs and put back after.
$order_finish_side_hooks = array(
	array( 'sunshine_checkout_create_order', 'sunshine_mailchimp_order_create' ),
	array( 'sunshine_checkout_create_order', 'sunshine_campaign_monitor_order_create' ),
	array( 'sunshine_checkout_create_order', 'sunshine_lightblue_order_post_process' ),
	array( 'sunshine_checkout_create_order', 'sunshine_adv_reports_order_create' ),
	array( 'sunshine_checkout_create_order', 'sunshine_email_queue_after_order' ),
	array( 'sunshine_order_create', 'sunshine_adv_reports_order_create' ),
);

// The name of the test that is running a payment service step, so a step that ends the whole
// run with exit at least cleans up and says where it happened.
$order_finish_guard = array( 'name' => '' );
register_shutdown_function(
	function () use ( &$order_finish_guard ) {
		if ( '' === $order_finish_guard['name'] ) {
			return;
		}
		WP_CLI::log( 'FAIL ' . $order_finish_guard['name'] . ': the payment service step ended the whole run with exit, so the tests after it did not run' );
		SPC_Totals_Test::cleanup();
		SPC_Totals_Test::report();
	}
);

/**
 * One order-finish test. Same as $t::test(), with every web request answered from
 * $order_finish_replies (or refused), no emails, no order receipts and the side add-ons off.
 */
$order_finish_test = function ( $name, $callback ) use ( $t, $order_finish_http, &$order_finish_replies, &$order_finish_sent, $order_finish_side_hooks ) {
	$t::test(
		$name,
		function () use ( $callback, $order_finish_http, &$order_finish_replies, &$order_finish_sent, $order_finish_side_hooks ) {
			global $wp_filter;
			$order_finish_replies = array();
			$order_finish_sent    = array();
			add_filter( 'pre_http_request', $order_finish_http, PHP_INT_MAX, 3 );
			add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
			add_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );

			// Some payment services send the receipts themselves ($order->notify()), so the
			// receipt emails are taken off completely.
			$notify = isset( $wp_filter['sunshine_order_notify'] ) ? $wp_filter['sunshine_order_notify'] : null;
			unset( $wp_filter['sunshine_order_notify'] );

			$removed = array();
			foreach ( $order_finish_side_hooks as $side ) {
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
				remove_filter( 'pre_http_request', $order_finish_http, PHP_INT_MAX );
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

// The customer picked this payment method at checkout. Switched on for this test only, in test
// mode, with no payment fee.
$order_finish_use = function ( $id ) use ( $t ) {
	$t::option( $id . '_active', 1 );
	$t::option( $id . '_mode', 'test' );
	$t::option( $id . '_fee', 'none' );
	$t::option( $id . '_fee_amount', '' );
	SPC()->cart->set_checkout_data_item( 'payment_method', $id );
};

/*
 * Fixtures, saved the way the admin screens and the add-ons save them.
 */

// Sell Anything product: the product screen saves every field, blank ones as ''. Stock is text.
$order_finish_general = function ( $price, $stock ) use ( $t ) {
	$t::require_plugin( 'sunshine_general_product_reduce_stock', 'Sell Anything add-on' );
	$level = sunshine_get_default_price_level_id();
	$id    = wp_insert_post(
		array(
			'post_type'   => 'sunshine-product',
			'post_status' => 'publish',
			'post_title'  => 'Order finish test general product',
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

// A gift card product the way the Gift Cards add-on makes it: price box hidden (empty price),
// preset amounts, custom amounts up to 1000, not taxable.
$order_finish_gift_card_product = function () use ( $t ) {
	$t::require_plugin( 'sunshine_gift_cards_create_from_order', 'Gift Cards add-on' );
	$id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-product',
			'post_status' => 'publish',
			'post_title'  => 'Order finish test gift card',
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
$order_finish_buy_gift_card = function ( $product_id, $amount ) {
	$_POST['gift_card_amount']          = (string) $amount;
	$_POST['gift_card_delivery']        = 'immediate';
	$_POST['gift_card_recipient_name']  = 'Order Finish Gift';
	$_POST['gift_card_recipient_email'] = 'order-finish-gift@example.com';
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

// A Session Fee product with nothing included (its Session Fees tab left empty), and a session
// date for it the way the Schedule screen adds one: a published sunshine-session post dated in the
// future, booked saved as false.
$order_finish_session = function ( $price ) use ( $t ) {
	$t::require_plugin( 'sunshine_session_fees_checkout_create_order', 'Session Fees add-on' );
	$product_id = $t::product(
		$price,
		array(
			'name'    => 'Order finish test session',
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
$order_finish_add_session = function ( $product_id, $date_id ) {
	$item = SPC()->cart->add_item( $product_id, '', '', sunshine_get_default_price_level_id(), '', 1, '', false, array( 'session_date' => $date_id ) );
	if ( empty( $item ) ) {
		throw new Exception( 'Could not add the session fee to the cart' );
	}
	SPC()->cart->update_item_by_hash( $item['hash'], $item );
	return $item;
};

// Prints a session paid for earlier left on the customer, in the shape Session Fees saves them.
$order_finish_give_included = function ( $customer_id, $product_id, $qty ) {
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
$order_finish_included_left = function ( $customer_id, $product_id ) {
	$left = 0;
	foreach ( (array) get_user_meta( $customer_id, 'sunshine_session_products', true ) as $row ) {
		if ( is_array( $row ) && isset( $row['product'], $row['qty'] ) && $row['product'] == $product_id && $row['qty'] > 0 ) {
			$left += $row['qty'];
		}
	}
	return $left;
};

// Gift cards an order made, as array( card ID => balance ). Tracked so they are deleted.
$order_finish_cards_made = function ( $order_id ) use ( $t ) {
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

$order_finish_card_balance = function ( $card_id ) {
	return (float) get_post_meta( $card_id, 'balance', true );
};

/**
 * The cart used for every way of finishing an order: one of each thing that has to happen once
 * the order is paid. No tax, so the numbers are easy to follow.
 *
 * A logged in customer with 20.00 store credit and 2 free prints left from an earlier session.
 * - 3 prints at 10.00, 2 of them free: 10.00
 * - 2 Sell Anything products at 15.00 (5 in stock), 10% off with a code: 30.00 - 3.00 = 27.00
 * - a 50.00 gift card for someone else: 50.00
 * - a 100.00 session fee with a session date: 100.00
 * - 5.00 flat rate shipping
 * 10 + 27 + 50 + 100 + 5 = 192.00. A 25.00 gift card pays 25 and store credit pays 20:
 * 192 - 25 - 20 = 147.00 left to pay.
 *
 * Returns the IDs the checks need.
 */
$order_finish_everything_cart = function () use ( $t, $order_finish_general, $order_finish_gift_card_product, $order_finish_buy_gift_card, $order_finish_session, $order_finish_add_session, $order_finish_give_included ) {
	$customer_id = $t::customer();
	update_user_meta( $customer_id, 'sunshine_credits', '20' );

	$print = $t::product( 10, array( 'taxable' => false ) );
	$order_finish_give_included( $customer_id, $print, 2 );
	$general                       = $order_finish_general( 15, 5 );
	$card_product                  = $order_finish_gift_card_product();
	list( $session, $session_date ) = $order_finish_session( 100 );

	$t::add( $print, 3 );
	$t::add( $general, 2 );
	$order_finish_buy_gift_card( $card_product, 50 );
	$order_finish_add_session( $session, $session_date );

	$code = $t::discount( 'percent-product', 10, array( 'allowed_products' => array( $general ) ) );
	$card = $t::gift_card( 25 );
	$t::flat_shipping( 5 );
	SPC()->cart->set_checkout_data_item( 'use_credits', 1 );

	return array(
		'customer'     => $customer_id,
		'print'        => $print,
		'general'      => $general,
		'session_date' => $session_date,
		'discount'     => sunshine_get_discount_by_code( $code )->get_id(),
		'card'         => $card,
	);
};

// Check the cart, place the order with this payment method and check the saved order.
$order_finish_place_everything = function ( $payment_method ) use ( $t ) {
	$t::checkout_address();
	$cart = $t::calculate();
	$t::money( 192, $cart->get_total( array( 'fees', 'credits' ) ), 'cart total before gift card and credit' );
	$t::money( 147, $cart->get_total(), 'cart total left to pay' );
	$order = $t::place_order( $payment_method );
	$t::money( 147, $order->get_total(), 'order total' );
	$t::money( 20, $order->get_credits(), 'store credit on the order' );
	$fees = (array) $order->get_fees();
	$t::money( -25, isset( $fees['gift_card'] ) ? $fees['gift_card']['amount'] : 0, 'gift card on the order' );
	return $order;
};

/**
 * Everything that should have happened once the order with the cart above is paid. $label says
 * which way the order was finished, so a failure line says which path is wrong.
 */
$order_finish_check_everything = function ( $order_id, $ids, $label ) use ( $t, $order_finish_card_balance, $order_finish_cards_made, $order_finish_included_left ) {
	$order    = sunshine_get_order( $order_id );
	$customer = new SPC_Customer( $ids['customer'] );

	$t::same( true, $order->is_paid(), $label . ': order is marked paid (status ' . $order->get_status() . ')' );
	$t::money( 147, $order->get_total(), $label . ': order total is still what the customer paid' );

	// 25.00 card fully used on this order, and the order says which card paid.
	$t::money( 0, $order_finish_card_balance( $ids['card'] ), $label . ': gift card used comes down from 25.00 to 0.00' );
	$saved_cards = $order->get_meta_value( 'gift_card_ids' );
	$t::same( array( $ids['card'] ), is_array( $saved_cards ) ? array_map( 'intval', $saved_cards ) : $saved_cards, $label . ': order keeps which gift card paid for it' );

	// One new 50.00 card for the recipient.
	$made = $order_finish_cards_made( $order_id );
	$t::same( 1, count( $made ), $label . ': gift card bought is made' );
	$t::money( 50, $made ? reset( $made ) : 0, $label . ': gift card bought has 50.00 on it' );

	// The session date can't be booked by anyone else now.
	$date = sunshine_get_session_date( $ids['session_date'] );
	$t::same( true, $date->is_booked(), $label . ': session date is booked' );
	$t::same( (string) $order_id, (string) $date->get_order_id(), $label . ': session date is tied to the order' );

	// The 2 free prints were used on this order.
	$t::same( 0, (int) $order_finish_included_left( $ids['customer'], $ids['print'] ), $label . ': 2 free prints from the earlier session are used up' );

	// 5 in stock, 2 sold.
	$t::same( 3, (int) get_post_meta( $ids['general'], 'stock', true ), $label . ': Sell Anything stock goes from 5 to 3' );

	// The code was used once, by this customer.
	$discount = sunshine_get_discount( $ids['discount'] );
	$t::same( 1, $discount->get_use_count(), $label . ': discount code use is counted' );
	$t::same( 1, $discount->get_use_count_by( $ids['customer'] ), $label . ': discount code use is counted for this customer' );

	// 20.00 credit used.
	$t::money( 0, $customer->get_credits(), $label . ': store credit comes down from 20.00 to 0.00' );
};

/**
 * The checkout's Place Order step for a payment service that sends the customer away to pay.
 * SPC_Cart::process_order() runs the service's own step, which redirects (or prints a form)
 * and ends the request with exit. That step is swapped for a stand-in that ends the request the
 * same way, so nothing reaches the service and nothing after it in process_order() runs, just
 * like on a live site.
 */
$order_finish_checkout_leaves = function ( $gateway_id ) use ( $t ) {
	global $wp_filter;
	$hook     = 'sunshine_checkout_process_payment_' . $gateway_id;
	$saved    = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ] : null;
	$order_id = SPC()->session->get( 'checkout_order_id' );
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
	$t::same( 'pending', sunshine_get_order( $order_id )->get_status(), 'order waits for the payment as Pending' );
	return sunshine_get_order( $order_id );
};

/**
 * Run $callback the way a payment service's server notice (webhook, IPN, ITN) reaches the site:
 * nobody is logged in and the session is a new, empty one. The customer's login and session are
 * put back afterwards.
 */
$order_finish_session_keys = array( 'cart', 'discounts', 'checkout_data', 'checkout_sections_completed', 'gift_cards', 'gift_cards_applied', 'gift_card_amounts', 'use_credits', 'checkout_order_id', 'paypal_order_id', 'stripe_payment_intent_id', 'stripe_client_secret', 'stripe_idempotency_key', 'stripe_customer_id' );

$order_finish_as_server = function ( $callback ) use ( $order_finish_session_keys ) {
	$user_id = get_current_user_id();
	$saved   = array();
	foreach ( $order_finish_session_keys as $key ) {
		$saved[ $key ] = SPC()->session->get( $key );
		SPC()->session->set( $key, '' );
	}
	wp_set_current_user( 0 );
	SPC()->customer = new SPC_Customer( 0 );
	SPC()->cart     = new SPC_Cart();
	try {
		return $callback();
	} finally {
		foreach ( $saved as $key => $value ) {
			SPC()->session->set( $key, $value );
		}
		wp_set_current_user( $user_id );
		SPC()->customer = new SPC_Customer( $user_id );
		SPC()->cart     = new SPC_Cart();
	}
};

/**
 * Run a payment service step that ends with a redirect. The redirect is stopped and returned.
 */
$order_finish_until_redirect = function ( $callback ) {
	$redirect = null;
	$stop     = function ( $location ) use ( &$redirect ) {
		$redirect = $location;
		throw new SPC_Totals_Test_Done();
	};
	$die      = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'wp_redirect', $stop, -PHP_INT_MAX );
	add_filter( 'wp_die_handler', $die, PHP_INT_MAX );
	$filters_before = $GLOBALS['wp_current_filter'];
	ob_start();
	try {
		$callback();
	} catch ( SPC_Totals_Test_Done $e ) {
		// The step ended with a redirect.
	} finally {
		ob_end_clean();
		$GLOBALS['wp_current_filter'] = $filters_before;
		remove_filter( 'wp_redirect', $stop, -PHP_INT_MAX );
		remove_filter( 'wp_die_handler', $die, PHP_INT_MAX );
	}
	return $redirect;
};

/**
 * Run a payment service step that ends with a bare exit right after it finishes the order. It is
 * stopped just after the order's $meta_key is saved (the last thing the step does before exit),
 * and the lock post_process_order() holds is let go. Only call it once the test has made sure
 * the step will get that far: anything that makes the step exit earlier would end the whole run.
 */
$order_finish_until_meta = function ( $order_id, $meta_key, $name, $callback ) use ( &$order_finish_guard ) {
	global $wpdb;
	$reached = false;
	$stop    = function ( $meta_id, $object_id, $key ) use ( $order_id, $meta_key, &$reached ) {
		if ( (int) $object_id === (int) $order_id && $key === $meta_key ) {
			$reached = true;
			throw new SPC_Totals_Test_Done();
		}
	};
	add_action( 'added_post_meta', $stop, PHP_INT_MAX, 3 );
	add_action( 'updated_post_meta', $stop, PHP_INT_MAX, 3 );
	$filters_before              = $GLOBALS['wp_current_filter'];
	$order_finish_guard['name'] = $name;
	ob_start();
	try {
		$callback();
	} catch ( SPC_Totals_Test_Done $e ) {
		// Stopped right before the step's exit.
	} finally {
		ob_end_clean();
		$order_finish_guard['name']  = '';
		$GLOBALS['wp_current_filter'] = $filters_before;
		remove_action( 'added_post_meta', $stop, PHP_INT_MAX );
		remove_action( 'updated_post_meta', $stop, PHP_INT_MAX );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'sunshine_post_process_' . $order_id ) );
	}
	return $reached;
};

/*
 * Stripe. Copied from core-gateways-stripe.php (case files never load each other).
 */

// Stripe keeps the payment it is working on in the session and on the payment method itself.
$order_finish_stripe_forget = function () {
	$stripe = sunshine_get_payment_method_by_id( 'stripe' );
	if ( ! $stripe ) {
		return;
	}
	foreach ( array( 'payment_intent_id', 'client_secret' ) as $property ) {
		$prop = new ReflectionProperty( $stripe, $property );
		$prop->setAccessible( true );
		$prop->setValue( $stripe, '' );
	}
	foreach ( array( 'stripe_payment_intent_id', 'stripe_client_secret', 'stripe_idempotency_key', 'stripe_customer_id' ) as $key ) {
		SPC()->session->set( $key, '' );
	}
};

// Send a Stripe webhook to the site, signed with the site's webhook secret, and return the HTTP
// status it answers with. php://input is swapped for the event while it runs. It always ends with
// a status and exit(), so the status is turned into an exception to get back here.
$order_finish_stripe_webhook = function ( $event ) {
	$stripe  = sunshine_get_payment_method_by_id( 'stripe' );
	$payload = wp_json_encode( $event );
	$secret  = SPC()->get_option( 'stripe_webhook_secret_test' );
	$time    = time();

	$input              = new class() {
		public $context;
		public static $body = '';
		private $position   = 0;
		public function stream_open( $path, $mode, $options, &$opened_path ) {
			$this->position = 0;
			return 'php://input' === strtolower( $path );
		}
		public function stream_read( $count ) {
			$chunk           = substr( self::$body, $this->position, $count );
			$this->position += strlen( $chunk );
			return $chunk;
		}
		public function stream_eof() {
			return $this->position >= strlen( self::$body );
		}
		public function stream_stat() {
			return array();
		}
	};
	$input_class        = get_class( $input );
	$input_class::$body = $payload;

	$status         = null;
	$stop           = function ( $header, $code ) use ( &$status ) {
		$status = $code;
		throw new SPC_Totals_Test_Done();
	};
	$filters_before = $GLOBALS['wp_current_filter'];

	add_filter( 'status_header', $stop, PHP_INT_MAX, 2 );
	$_GET['sunshine_stripe_webhook']  = 1;
	$_SERVER['HTTP_STRIPE_SIGNATURE'] = 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $payload, $secret );
	stream_wrapper_unregister( 'php' );
	stream_wrapper_register( 'php', $input_class );
	try {
		$stripe->process_webhook();
	} catch ( SPC_Totals_Test_Done $e ) {
		// process_webhook() always ends with a status and exit().
	} finally {
		stream_wrapper_restore( 'php' );
		remove_filter( 'status_header', $stop, PHP_INT_MAX );
		unset( $_GET['sunshine_stripe_webhook'], $_SERVER['HTTP_STRIPE_SIGNATURE'] );
		$GLOBALS['wp_current_filter'] = $filters_before;
	}
	return $status;
};

/*
 * Mollie, with a stand-in for its connection so nothing is sent (Mollie's library doesn't go
 * through WordPress, so pre_http_request can't catch it). Copied from
 * addon-payment-gateways-helpers.php. $reply( $method, $url, $body ) returns the reply as an array.
 */
$order_finish_mollie = function ( $reply ) use ( $t ) {
	if ( ! class_exists( 'SPC_Payment_Method_Mollie' ) ) {
		$t::skip( 'Mollie add-on is not active' );
	}
	require_once plugin_dir_path( ( new ReflectionClass( 'SPC_Payment_Method_Mollie' ) )->getFileName() ) . 'vendor/autoload.php';

	$connection = new class( $reply ) implements \Mollie\Api\HttpAdapter\MollieHttpAdapterInterface {
		public $sent = array();
		private $reply;
		public function __construct( $reply ) {
			$this->reply = $reply;
		}
		public function send( $http_method, $url, $headers, $http_body ) {
			$this->sent[] = array(
				'method' => $http_method,
				'url'    => $url,
			);
			$answer       = call_user_func( $this->reply, $http_method, $url, $http_body );
			return null === $answer ? null : json_decode( wp_json_encode( $answer ) );
		}
		public function versionString() {
			return 'SunshineTotalsTest/1.0';
		}
	};

	$client = new \Mollie\Api\MollieApiClient( $connection );
	$client->setApiKey( 'test_' . str_repeat( 'spctotals', 4 ) );

	// The real Mollie payment method, except setup() hands it the client above. Its constructor
	// is skipped so no hooks are added twice.
	$mollie = new class( $client ) extends SPC_Payment_Method_Mollie {
		private $totals_client;
		public function __construct( $client ) {
			$this->totals_client = $client;
			$this->id            = 'mollie';
		}
		public function setup( $mode = '' ) {
			$property = new ReflectionProperty( 'SPC_Payment_Method_Mollie', 'mollie' );
			$property->setAccessible( true );
			$property->setValue( $this, $this->totals_client );
		}
	};

	return array( $mollie, $connection );
};
