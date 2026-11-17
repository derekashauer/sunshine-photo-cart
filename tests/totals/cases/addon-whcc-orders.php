<?php
/**
 * WHCC add-on: placed orders, sending them to WHCC, and WHCC's order updates.
 *
 * A placed order must match what the customer saw in the cart, and is checked with WHCC once
 * more right before payment. Once it is paid it goes to WHCC as one submission. WHCC must never
 * be able to print an order twice, so the request is saved with an Idempotency-Key before it is
 * sent, and a send that got no answer repeats exactly the same request. WHCC can turn one
 * submission into several WHCC orders, and later posts updates for each one (accepted,
 * rejected, shipped, cancelled).
 *
 * Orders are placed through the real checkout step with the Test payment method, then marked
 * paid the way a payment does it. Nothing reaches WHCC: a made-up WHCC answers.
 * Setup is in addon-whcc-helpers.php.
 */

require_once __DIR__ . '/addon-whcc-helpers.php';

$t = 'SPC_Totals_Test';

// What the cart shows right before the customer places the order.
$whcc_cart_snapshot = function () {
	$cart  = SPC()->cart;
	$lines = array();
	foreach ( (array) $cart->get_cart_items() as $line ) {
		$lines[] = array(
			'total'    => (float) $line->get_total(),
			'tax'      => (float) $line->get_tax_total(),
			'discount' => (float) $line->get_discount_total(),
		);
	}
	return array(
		'subtotal' => (float) $cart->get_subtotal(),
		'discount' => (float) $cart->get_discount(),
		'shipping' => (float) $cart->get_shipping(),
		'tax'      => (float) $cart->get_tax(),
		'total'    => (float) $cart->get_total(),
		'lines'    => $lines,
	);
};

$whcc_order_matches_cart = function ( $order, $seen ) use ( $t ) {
	$t::money( $seen['subtotal'], $order->get_subtotal(), 'order subtotal' );
	$t::money( $seen['discount'], $order->get_discount(), 'order discount' );
	$t::money( $seen['shipping'], $order->get_shipping(), 'order shipping' );
	$t::money( $seen['tax'], $order->get_tax(), 'order tax' );
	$t::money( $seen['total'], $order->get_total(), 'order total' );
	$items = array_values( (array) $order->get_items() );
	$t::same( count( $seen['lines'] ), count( $items ), 'number of order lines' );
	foreach ( $seen['lines'] as $i => $line ) {
		if ( empty( $items[ $i ] ) ) {
			continue;
		}
		$t::money( $line['total'], $items[ $i ]->get_total(), 'line ' . ( $i + 1 ) . ' total' );
		$t::money( $line['tax'], $items[ $i ]->get_tax_total(), 'line ' . ( $i + 1 ) . ' tax' );
		$t::money( $line['discount'], $items[ $i ]->get_discount_total(), 'line ' . ( $i + 1 ) . ' discount' );
	}
};

// Press "Send to WHCC" on the order screen, as a store manager. Returns the AJAX reply.
$whcc_resend = function ( $order ) use ( $t, $whcc_ajax ) {
	$t::customer();
	$can = function ( $allcaps ) {
		$allcaps['sunshine_manage_options'] = true;
		return $allcaps;
	};
	add_filter( 'user_has_cap', $can );
	$_REQUEST['security'] = wp_create_nonce( 'sunshine_admin' );
	$_POST['order_id']    = (string) $order->get_id();
	try {
		return $whcc_ajax( 'sunshine_whcc_ajax_submit_order' );
	} finally {
		remove_filter( 'user_has_cap', $can );
		unset( $_REQUEST['security'], $_POST['order_id'] );
		wp_set_current_user( 0 );
	}
};

// A fresh copy of the order, with everything the add-on saved on it since.
$whcc_reload = function ( $order ) {
	return sunshine_get_order( $order->get_id() );
};

/**
 * A paid order that went to WHCC and that WHCC finished placing, as one WHCC order per
 * package. $wall_art adds a canvas, which WHCC ships (and places) separately.
 */
$whcc_sent_order = function ( $wall_art = false ) use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_settle, $whcc_reload ) {
	$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
	if ( $wall_art ) {
		$whcc_buy( $whcc_product( null, false, 'Wall Art' ), 40.00, 1 );
	}
	$whcc_ship_setup( array( 'lowest_cost' ) );
	$whcc_ship_with( 'lowest_cost' );
	$t::calculate();
	$order = $whcc_pay( $t::place_order() );
	$whcc_settle( $order->get_meta_value( 'whcc_submission_id' ) );
	sunshine_whcc_refresh_submission( $order );
	return $whcc_reload( $order );
};

// The confirmation IDs of the WHCC orders an order became, in order.
$whcc_confirmations = function ( $order ) {
	return array_keys( sunshine_whcc_get_whcc_orders( $order ) );
};

// One WHCC order's saved status.
$whcc_part_status = function ( $order, $id ) {
	$orders = sunshine_whcc_get_whcc_orders( sunshine_get_order( $order->get_id() ) );
	return isset( $orders[ $id ]['status'] ) ? $orders[ $id ]['status'] : null;
};

/*
 * Placing the order
 */

$whcc_test(
	'order: the placed order matches the cart',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_cart_snapshot, $whcc_order_matches_cart, $whcc_sent, $whcc_reload ) {
		$t::tax( 10 );
		$id = $whcc_buy( $whcc_product(), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ), true, array(), array( 'lowest_cost' => array( '', '2' ) ) );
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		// 27.00 + 10.00 shipping + 2.70 and 1.00 tax.
		$t::money( 40.70, $cart->get_total(), 'cart total' );
		$seen  = $whcc_cart_snapshot();
		$order = $whcc_reload( $t::place_order() );
		$whcc_order_matches_cart( $order, $seen );

		$items = array_values( (array) $order->get_items() );
		$t::same( $id, isset( $items[0] ) ? $items[0]->get_meta_value( 'whcc_configured_product_id' ) : null, 'the line keeps its WHCC item' );
		$t::money( 6.00, isset( $items[0] ) ? $items[0]->get_meta_value( 'cost' ) : null, "the line's cost is WHCC's price for one" );
		$t::same( 'dropship_lowest_cost', $order->get_meta_value( 'whcc_shipping_priority' ), 'the WHCC method the customer paid for' );
		$quote = $order->get_meta_value( 'whcc_shipping_quote' );
		$t::money( 8.00, isset( $quote['cost'] ) ? $quote['cost'] : null, "WHCC's shipping price at checkout is kept" );
		$t::same( 0, count( $whcc_sent( 'POST', 'commerce/submissions' ) ), 'nothing goes to WHCC before it is paid' );
	}
);

$whcc_test(
	'order: prices include tax',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_cart_snapshot, $whcc_order_matches_cart, $whcc_try_order ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$whcc_buy( $whcc_product( array( sunshine_get_default_price_level_id() => '10' ) ), 10.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ), true, array(), array( 'lowest_cost' => array( '', '3' ) ) );
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::money( 44.00, $cart->get_total(), 'cart total: 33.00 + 11.00, tax inside' );
		$seen  = $whcc_cart_snapshot();
		$tried = $whcc_try_order();
		$t::same( true, $tried['placed'], 'the order is placed [bug: the check before payment (cart.php) compares the price with tax inside, 11.00, to the cart line\'s price before tax, 10.00, so it always says the price changed]' );
		if ( $tried['placed'] ) {
			$whcc_order_matches_cart( sunshine_get_order( $tried['order_id'] ), $seen );
		}
	}
);

// A percent payment fee is on everything the customer pays, WHCC shipping included.
$whcc_test(
	'order: a percent payment fee',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_cart_snapshot, $whcc_order_matches_cart ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::option( 'test_fee', 'percent' );
		$t::option( 'test_fee_amount', '3' );
		$t::option( 'test_fee_name', 'Card fee' );
		SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
		$cart = $t::calculate();
		// 3% of 35.00 = 1.05.
		$t::money( 36.05, $cart->get_total(), '27.00 + 8.00 + 1.05' );
		$seen  = $whcc_cart_snapshot();
		$order = $t::place_order();
		$whcc_order_matches_cart( $order, $seen );
		$fees = (array) $order->get_fees();
		$t::money( 1.05, isset( $fees['payment_method'] ) ? $fees['payment_method']['amount'] : 0, 'fee on the order' );
	}
);

// Core skips shipping when no method is switched on. The customer would pay nothing for
// shipping, and the photographer would pay WHCC for it.
$whcc_test(
	'order: an order with WHCC items can\'t be placed without WHCC\'s shipping price',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_try_order, $whcc_sent ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
		$t::calculate();
		$tried = $whcc_try_order();
		$t::same( false, $tried['placed'], 'with no shipping method on: not placed' );
		$t::same( true, (bool) preg_grep( '/shipping cost for your order could not be worked out/', $tried['errors'] ), 'the customer is told why' );

		// WHCC methods on, but WHCC can't price shipping.
		$whcc_lab['calculate'] = 500;
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::new_request();
		$tried = $whcc_try_order();
		$t::same( false, $tried['placed'], 'with no price from WHCC: not placed' );
		$t::same( true, ! empty( $tried['errors'] ), 'the customer is told' );
		$t::same( 0, count( $whcc_sent( 'POST', 'commerce/submissions' ) ), 'nothing was sent to WHCC' );
	}
);

// An item can change at WHCC without the cart hearing of it (edited in WHCC's editor and then
// left with Cancel). The customer must see the new price before paying it.
$whcc_test(
	'order: a price changed at WHCC stops the order and updates the cart',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_buy, $whcc_line, $whcc_ship_setup, $whcc_ship_with, $whcc_try_order ) {
		$id = $whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$whcc_lab['items'][ $id ]['prices'] = array( 1 => 7.00 );
		$tried                              = $whcc_try_order();
		$t::same( false, $tried['placed'], 'not placed' );
		$t::same( array( 'The price of an item in your cart has changed. Please review your order and try again.' ), $tried['errors'], 'the customer is told' );

		$cart = $t::new_request();
		$line = $whcc_line( $id );
		$t::money( 10.50, $line ? $line->get_price() : null, 'the cart has the new price: 7.00 + 50%' );
		$t::money( 7.00, $line ? $line->get_meta_value( 'whcc_unit_cost' ) : null, "and WHCC's new price" );

		// The customer reviews it and places the order again.
		$tried = $whcc_try_order();
		$t::same( true, $tried['placed'], 'placed at the new price' );
		$order = sunshine_get_order( $tried['order_id'] );
		$t::money( 31.50, $order ? $order->get_subtotal() : null, 'order subtotal: 3 x 10.50' );
	}
);

$whcc_test(
	'order: an item WHCC can\'t make stops the order',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_try_order ) {
		$id = $whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$whcc_lab['items'][ $id ]['valid']    = false;
		$whcc_lab['items'][ $id ]['problems'] = array( 'The photo could not be fetched.' );
		$tried                                = $whcc_try_order();
		$t::same( false, $tried['placed'], 'not placed' );
		$t::same( true, (bool) preg_grep( "/WHCC Photo Print.*can't be ordered: The photo could not be fetched\\./", $tried['errors'] ), "the customer is told which item and WHCC's reason" );
	}
);

// Never charge a price that couldn't be checked.
$whcc_test(
	'order: when WHCC can\'t check the prices, the order waits',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_try_order ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		// Shipping was priced on the cart page; now WHCC stops answering.
		$whcc_lab['calculate'] = 500;
		$tried                 = $whcc_try_order();
		$t::same( false, $tried['placed'], 'not placed' );
		$t::same( array( "Your order couldn't be checked with the lab just now. Please wait a minute and try again." ), $tried['errors'], 'the customer is told to wait' );
	}
);

/*
 * Sending the order to WHCC
 */

$whcc_test(
	'order: a paid order goes to WHCC as one submission',
	function () use ( $t, &$whcc_lab, &$whcc_scheduled, $whcc_product, $whcc_buy, $whcc_update_cart, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_sent, $whcc_reload ) {
		// Built with a quantity of 1, then changed to 4 in the cart: the cart's quantity is sent.
		$print  = $whcc_buy( $whcc_product( null, false ), 6.00, 1 );
		$canvas = $whcc_buy( $whcc_product( null, false, 'Wall Art' ), 40.00, 1 );
		$whcc_update_cart( array( $print => 4 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'expedited' );
		$cart = $t::calculate();
		// 4 x 9.00 + 60.00, and 12.00 for each of the 2 packages.
		$t::money( 120.00, $cart->get_total(), 'cart total' );
		$order = $whcc_reload( $whcc_pay( $t::place_order() ) );

		$sent = $whcc_sent( 'POST', 'commerce/submissions' );
		$t::same( 1, count( $sent ), 'sent once' );
		$body = isset( $sent[0]['body'] ) ? $sent[0]['body'] : array();
		$t::same(
			array(
				array(
					'configuredProductId' => $print,
					'quantity'            => 4,
				),
				array(
					'configuredProductId' => $canvas,
					'quantity'            => 1,
				),
			),
			isset( $body['items'] ) ? $body['items'] : null,
			'the items, with the quantities the customer paid for'
		);
		$t::same( 'dropship', isset( $body['shipping']['type'] ) ? $body['shipping']['type'] : null, 'drop shipped to the customer' );
		$t::same( 'dropship_expedited', isset( $body['shipping']['priority'] ) ? $body['shipping']['priority'] : null, 'with the method the customer paid for' );
		$t::same(
			array(
				'name'    => 'Totals Test',
				'addr1'   => '1 Main St',
				'city'    => 'Denver',
				'state'   => 'CO',
				'zip'     => '80202',
				'country' => 'US',
				'email'   => 'totals-test@example.com',
			),
			isset( $body['shipping']['address'] ) ? $body['shipping']['address'] : null,
			"to the customer's address"
		);
		$t::same( false, isset( $body['shipping']['returnAddress'] ), "no return address: the store's address isn't complete" );
		$t::same( (string) $order->get_id(), isset( $body['reference'] ) ? $body['reference'] : null, 'the order number goes to WHCC, which prints it and sends it back with every update' );

		// The key and the exact request are saved, so a resend can repeat them word for word.
		$key = $order->get_meta_value( 'whcc_idempotency_key' );
		$t::same( true, '' !== (string) $key, 'an Idempotency-Key is saved on the order' );
		$t::same( $key, isset( $sent[0]['headers']['Idempotency-Key'] ) ? $sent[0]['headers']['Idempotency-Key'] : null, 'and sent with the request' );
		$t::same( $body, $order->get_meta_value( 'whcc_submission_body' ), 'the exact request is saved' );

		$t::same( 'submitted', $order->get_meta_value( 'whcc_order_status' ), 'marked as sent' );
		$t::same( true, isset( $whcc_lab['submissions'][ $order->get_meta_value( 'whcc_submission_id' ) ] ), "WHCC's submission ID is saved" );
		// What WHCC charges, for profit reports: 4 x 6.00 + 40.00, and 12.00 x 2 packages.
		$t::money( 64.00, $order->get_meta_value( 'whcc_products_cost' ), "WHCC's price for the products" );
		$t::money( 24.00, $order->get_meta_value( 'whcc_shipping_cost' ), "WHCC's price for shipping" );

		// WHCC places the order in the background, so the site reads it back a minute later.
		$check = null;
		foreach ( $whcc_scheduled as $event ) {
			if ( 'sunshine_whcc_check_submission' === $event->hook ) {
				$check = $event;
			}
		}
		$t::same( array( $order->get_id(), 1 ), $check ? $check->args : null, 'reading it back is scheduled' );
		$t::same( true, $check && abs( $check->timestamp - ( time() + 60 ) ) <= 5, 'a minute from now' );

		// Later status changes don't send it again.
		$order->set_status( 'processing' );
		$t::same( 1, count( $whcc_sent( 'POST', 'commerce/submissions' ) ), 'still sent once' );
	}
);

// WHCC prints the return address on the label only for US addresses. Without one the label
// carries the address on the WHCC account.
$whcc_test(
	'order: the store\'s address goes on the label only when it is a complete US address',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$order = $t::place_order();

		$t::option( 'address1', '5 Studio Way' );
		$t::option( 'address2', '' );
		$t::option( 'city', 'Boulder' );
		$t::option( 'state', 'CO' );
		$t::option( 'postcode', '80301' );
		$body = sunshine_whcc_submission_body( $order );
		$t::same(
			array(
				'name'    => get_bloginfo( 'name' ),
				'addr1'   => '5 Studio Way',
				'city'    => 'Boulder',
				'state'   => 'CO',
				'zip'     => '80301',
				'country' => 'US',
			),
			isset( $body['shipping']['returnAddress'] ) ? $body['shipping']['returnAddress'] : null,
			'a complete US address'
		);

		$t::option( 'state', 'Colorado' );
		$body = sunshine_whcc_submission_body( $order );
		$t::same( false, isset( $body['shipping']['returnAddress'] ), 'not with a state WHCC wouldn\'t read' );

		$t::option( 'state', 'ON' );
		$t::option( 'country', 'CA' );
		$body = sunshine_whcc_submission_body( $order );
		$t::same( false, isset( $body['shipping']['returnAddress'] ), 'not outside the US' );
	}
);

$whcc_test(
	'order: paid in full with a gift card is still sent to WHCC',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_pay ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::gift_card( 50 );
		$t::calculate();
		$order = $whcc_pay( $t::place_order() );
		$t::money( 0, $order->get_total(), 'nothing left to pay' );
		$fees = (array) $order->get_fees();
		$t::money( -35.00, isset( $fees['gift_card'] ) ? $fees['gift_card']['amount'] : 0, 'gift card paid 27.00 + 8.00' );
		$t::same( 'submitted', $order->get_meta_value( 'whcc_order_status' ), 'sent to WHCC' );
	}
);

/*
 * Sending safely: an order is never printed twice
 */

// The answer can be lost after WHCC has the order. Sending a new request then would print it
// twice, so the same key and the same body go again, and WHCC hands back the first one.
$whcc_test(
	'order: no answer from WHCC: sending again repeats the same request',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_sent, $whcc_reload, $whcc_resend ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$whcc_lab['submit'] = 'lost';
		$order              = $whcc_reload( $whcc_pay( $t::place_order() ) );
		$key                = $order->get_meta_value( 'whcc_idempotency_key' );
		$body               = $order->get_meta_value( 'whcc_submission_body' );
		$t::same( 'yes', $order->get_meta_value( 'whcc_submit_unknown' ), 'the order says WHCC never answered' );
		$t::same( true, '' !== (string) $key && is_array( $body ), 'the key and the request are kept' );
		$t::same( '', (string) $order->get_meta_value( 'whcc_submission_id' ), 'no submission ID yet' );
		$t::same( true, sunshine_whcc_can_resubmit( $order ), 'it can be sent again' );

		// The order is edited before it's sent again. The resend must still be the first request.
		$order->update_meta_value( 'shipping_city', 'Aurora' );
		$whcc_lab['submit'] = 'ok';
		$reply              = $whcc_resend( $order );
		$t::same( true, ! empty( $reply['success'] ), 'sent again' );
		$sent = $whcc_sent( 'POST', 'commerce/submissions' );
		$last = end( $sent );
		$t::same( $key, isset( $last['headers']['Idempotency-Key'] ) ? $last['headers']['Idempotency-Key'] : null, 'with the same key' );
		$t::same( $body, isset( $last['body'] ) ? $last['body'] : null, 'and the same request, from before the edit' );
		$t::same( 1, count( $whcc_lab['submissions'] ), 'WHCC has the order once' );
		$order = $whcc_reload( $order );
		$t::same( true, isset( $whcc_lab['submissions'][ $order->get_meta_value( 'whcc_submission_id' ) ] ), 'the order has the submission WHCC placed the first time' );
		$t::same( 'submitted', $order->get_meta_value( 'whcc_order_status' ), 'marked as sent' );
		$t::same( '', (string) $order->get_meta_value( 'whcc_submit_unknown' ), 'no longer unknown' );
	}
);

// A 5xx from WHCC is no answer too: WHCC may have placed it.
$whcc_test(
	'order: a server error from WHCC keeps the key and request',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_reload ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$whcc_lab['submit'] = '500';
		$order              = $whcc_reload( $whcc_pay( $t::place_order() ) );
		$t::same( 'yes', $order->get_meta_value( 'whcc_submit_unknown' ), 'treated as no answer' );
		$t::same( true, '' !== (string) $order->get_meta_value( 'whcc_idempotency_key' ), 'the key is kept' );
		$t::same( 'failed', $order->get_meta_value( 'whcc_order_status' ), 'shown as failed until it goes through' );
	}
);

// A clear refusal means nothing was placed. The next send is a new request (the order may have
// been fixed since), so the old key and request are dropped.
$whcc_test(
	'order: a refusal from WHCC drops the key, so the next send is a new request',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_reload, $whcc_resend ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$whcc_lab['submit'] = 'refused';
		$order              = $whcc_reload( $whcc_pay( $t::place_order() ) );
		$t::same( 'failed', $order->get_meta_value( 'whcc_order_status' ), 'failed' );
		$t::same( true, false !== strpos( (string) $order->get_meta_value( 'whcc_last_error' ), 'shipping.address.zip' ), "WHCC's reason is on the order" );
		$t::same( '', (string) $order->get_meta_value( 'whcc_idempotency_key' ), 'the key is dropped' );
		$t::same( '', (string) $order->get_meta_value( 'whcc_submission_body' ), 'the request is dropped' );
		$t::same( '', (string) $order->get_meta_value( 'whcc_submit_unknown' ), 'WHCC did answer' );

		$whcc_lab['submit'] = 'ok';
		$reply              = $whcc_resend( $order );
		$t::same( true, ! empty( $reply['success'] ), 'sent again once fixed' );
		$t::same( 1, count( $whcc_lab['submissions'] ), 'placed once' );
	}
);

// WHCC already has another request under this order's key: the first one stands. A new key
// could place the order a second time, so the key is kept and the photographer contacts WHCC.
$whcc_test(
	'order: a key WHCC already has with another request is a conflict, and no new key is made',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_reload, $whcc_sent ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$whcc_lab['submit'] = 'timeout';
		$order              = $whcc_reload( $whcc_pay( $t::place_order() ) );
		$key                = $order->get_meta_value( 'whcc_idempotency_key' );
		// Meanwhile a different request reached WHCC under the same key.
		$whcc_lab['keys'][ $key ] = array(
			'body' => wp_json_encode( array( 'reference' => 'another request' ) ),
			'id'   => 'sub_other',
		);
		$whcc_lab['submit']       = 'ok';

		$t::same( false, sunshine_whcc_submit_order( $order ), 'not sent' );
		$order = $whcc_reload( $order );
		$t::same( $key, $order->get_meta_value( 'whcc_idempotency_key' ), 'the key is kept' );
		$t::same( 'partially_failed', $order->get_meta_value( 'whcc_order_status' ), 'shown as needing attention' );
		$error = (string) $order->get_meta_value( 'whcc_last_error' );
		$t::same( true, false !== strpos( $error, $key ) && false !== strpos( $error, 'integrations@whcc.com' ), 'the photographer is told to contact WHCC with the key' );

		sunshine_whcc_submit_order( $order );
		$sent = $whcc_sent( 'POST', 'commerce/submissions' );
		$last = end( $sent );
		$t::same( $key, isset( $last['headers']['Idempotency-Key'] ) ? $last['headers']['Idempotency-Key'] : null, 'trying again still uses the same key' );
		$t::same( 0, count( $whcc_lab['submissions'] ), 'nothing new was placed' );
	}
);

// "Send to WHCC" on the order screen is offered only when it can't print anything twice.
// When only part of an order failed, WHCC doesn't say which items were in that part.
$whcc_test(
	'order: when an order can be sent to WHCC again',
	function () use ( $t, &$whcc_lab, $whcc_sent_order, $whcc_reload, $whcc_resend, $whcc_sent ) {
		$order = $whcc_sent_order();
		foreach ( array( 'placed', 'accepted', 'partially_shipped', 'shipped', 'partially_failed' ) as $status ) {
			$order->update_meta_value( 'whcc_order_status', $status );
			$t::same( false, sunshine_whcc_can_resubmit( $order ), $status . ': not again' );
		}
		foreach ( array( 'failed', 'rejected', 'cancelled' ) as $status ) {
			$order->update_meta_value( 'whcc_order_status', $status );
			$t::same( true, sunshine_whcc_can_resubmit( $order ), $status . ': can be sent again' );
		}

		$order->update_meta_value( 'whcc_order_status', 'partially_failed' );
		$reply = $whcc_resend( $order );
		$t::same( true, empty( $reply['success'] ), 'partly failed: the button refuses' );
		$t::same( 1, count( $whcc_sent( 'POST', 'commerce/submissions' ) ), 'nothing more was sent' );

		// Everything WHCC made of it was rejected: a new submission, with a new key.
		$old_key = $order->get_meta_value( 'whcc_idempotency_key' );
		$order->update_meta_value( 'whcc_order_status', 'rejected' );
		$reply = $whcc_resend( $order );
		$t::same( true, ! empty( $reply['success'] ), 'rejected: sent again' );
		$order = $whcc_reload( $order );
		$t::same( 2, count( $whcc_lab['submissions'] ), 'a second submission' );
		$t::same( true, $old_key !== $order->get_meta_value( 'whcc_idempotency_key' ), 'with a new key' );
		$t::same( array(), sunshine_whcc_get_whcc_orders( $order ), 'the rejected WHCC orders are cleared until WHCC places the new one' );
	}
);

/*
 * Reading what WHCC made of the order
 */

$whcc_test(
	'order: reading back what WHCC placed',
	function () use ( $t, &$whcc_lab, &$whcc_scheduled, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_reload, $whcc_settle ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_buy( $whcc_product( null, false, 'Wall Art' ), 40.00, 1 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$order = $whcc_reload( $whcc_pay( $t::place_order() ) );
		$id    = $order->get_meta_value( 'whcc_submission_id' );

		// Still being placed: nothing to read yet, so it looks again a little later.
		$t::same( false, sunshine_whcc_refresh_submission( $order ), 'not settled' );
		$whcc_scheduled = array();
		sunshine_whcc_check_submission( $order->get_id(), 1 );
		$t::same( 1, count( $whcc_scheduled ), 'looks again' );
		$t::same( array( $order->get_id(), 2 ), isset( $whcc_scheduled[0] ) ? $whcc_scheduled[0]->args : null, 'as the second try' );
		$whcc_scheduled = array();
		sunshine_whcc_check_submission( $order->get_id(), 6 );
		$t::same( 0, count( $whcc_scheduled ), 'stops after the sixth try' );

		// WHCC made two orders, one per package.
		$whcc_settle(
			$id,
			'placed',
			array(
				array( 'spcconf-a', 99000001 ),
				array( 'spcconf-b', 99000002 ),
			)
		);
		$t::same( true, sunshine_whcc_refresh_submission( $order ), 'settled' );
		$order = $whcc_reload( $order );
		$t::same(
			array(
				'spcconf-a' => array(
					'order_number' => '99000001',
					'status'       => 'placed',
					'failure'      => '',
				),
				'spcconf-b' => array(
					'order_number' => '99000002',
					'status'       => 'placed',
					'failure'      => '',
				),
			),
			sunshine_whcc_get_whcc_orders( $order ),
			'each WHCC order is kept by its confirmation ID'
		);
		$t::same( 'placed', $order->get_meta_value( 'whcc_order_status' ), 'summed up as placed' );

		// An update that arrived before the read (accepted) is newer than "placed".
		$orders                            = sunshine_whcc_get_whcc_orders( $order );
		$orders['spcconf-a']['status']     = 'accepted';
		$order->update_meta_value( 'whcc_orders', $orders );
		sunshine_whcc_refresh_submission( $order );
		$orders = sunshine_whcc_get_whcc_orders( $whcc_reload( $order ) );
		$t::same( 'accepted', isset( $orders['spcconf-a']['status'] ) ? $orders['spcconf-a']['status'] : null, 'a newer status is kept' );
	}
);

// Part of the order is on its way and part isn't: the photographer has to know, and sending it
// all again would print the placed part twice.
$whcc_test(
	'order: some of what WHCC made failed',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_reload, $whcc_settle ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_buy( $whcc_product( null, false, 'Wall Art' ), 40.00, 1 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$order = $whcc_reload( $whcc_pay( $t::place_order() ) );
		$whcc_settle(
			$order->get_meta_value( 'whcc_submission_id' ),
			'partially_failed',
			array(
				array( 'spcconf-a', 99000001 ),
				array( 'failure' => 'The canvas photo could not be printed.' ),
			)
		);
		sunshine_whcc_refresh_submission( $order );
		$order  = $whcc_reload( $order );
		$orders = sunshine_whcc_get_whcc_orders( $order );
		$t::same( array( 'spcconf-a', 'failed-1' ), array_keys( $orders ), 'the placed one and the failed one' );
		$t::same( 'failed', isset( $orders['failed-1']['status'] ) ? $orders['failed-1']['status'] : null, 'failed' );
		$t::same( 'The canvas photo could not be printed.', isset( $orders['failed-1']['failure'] ) ? $orders['failed-1']['failure'] : null, "WHCC's reason" );
		$t::same( 'partially_failed', $order->get_meta_value( 'whcc_order_status' ), 'summed up as partly failed' );
		$t::same( true, false !== strpos( (string) $order->get_meta_value( 'whcc_last_error' ), 'The canvas photo could not be printed.' ), 'the reason is on the order' );
		$t::same( false, sunshine_whcc_can_resubmit( $order ), 'it can\'t be sent again' );
	}
);

// A submission can fail before any WHCC order exists. No update will ever come for it, so this
// read is the only way the photographer hears of it.
$whcc_test(
	'order: WHCC couldn\'t place any of it',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_reload, $whcc_settle ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$order = $whcc_reload( $whcc_pay( $t::place_order() ) );
		$whcc_settle( $order->get_meta_value( 'whcc_submission_id' ), 'failed', array() );
		sunshine_whcc_refresh_submission( $order );
		$order = $whcc_reload( $order );
		$t::same( array( 'failed-0' ), array_keys( sunshine_whcc_get_whcc_orders( $order ) ), 'one failed entry' );
		$t::same( 'failed', $order->get_meta_value( 'whcc_order_status' ), 'failed' );
		$t::same( true, false !== strpos( (string) $order->get_meta_value( 'whcc_last_error' ), 'integrations@whcc.com' ), 'the photographer is told who to contact' );
		$t::same( true, sunshine_whcc_can_resubmit( $order ), 'nothing was placed, so it can be sent again' );
	}
);

/*
 * WHCC's order updates (webhook)
 */

// The webhook changes orders and emails customers, so only WHCC may use it.
$whcc_test(
	'updates: only deliveries signed with this site\'s secret are accepted',
	function () use ( $t, $whcc_sent_order, $whcc_confirmations, $whcc_deliver, $whcc_update, $whcc_part_status ) {
		$order = $whcc_sent_order();
		$conf  = $whcc_confirmations( $order )[0];
		$data  = $whcc_update( $order, $conf, 'accepted' );
		$t::same( 401, $whcc_deliver( $data, 'wrong' ), 'signed with another secret: refused' );
		$t::same( 401, $whcc_deliver( $data, 'none' ), 'not signed: refused' );
		$t::same( 401, $whcc_deliver( $data, 'old' ), 'signed more than a week ago: refused' );
		$t::same( 'placed', $whcc_part_status( $order, $conf ), 'nothing changed' );
		// While WHCC changes a secret it signs with both, the new one second.
		$t::same( 200, $whcc_deliver( $data, 'second' ), 'the right signature among two: accepted' );
		$t::same( 'accepted', $whcc_part_status( $order, $conf ), 'and processed' );
	}
);

// WHCC sends updates for every order on the account (other tools, test events), and their
// Reference can match an unrelated order number here.
$whcc_test(
	'updates: an update about a WHCC order this order didn\'t become is ignored',
	function () use ( $t, $whcc_sent_order, $whcc_deliver, $whcc_update, $whcc_reload ) {
		$order = $whcc_sent_order();
		$t::same( 200, $whcc_deliver( $whcc_update( $order, 'someone-elses-order', 'shipped', array( 'ShippingInfo' => array( array( 'TrackingNumber' => '1Z999' ) ) ) ) ), 'answered, so WHCC stops sending it' );
		$order = $whcc_reload( $order );
		$t::same( 'new', $order->get_status(), 'the order is not marked shipped' );
		$t::same( true, empty( $order->get_meta_value( 'shipment_tracking' ) ), 'no tracking added' );
	}
);

// An update can arrive before the site read which WHCC orders the submission became.
$whcc_test(
	'updates: an update that comes before the order was read back reads it first',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_reload, $whcc_settle, $whcc_deliver, $whcc_update, $whcc_part_status ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$order = $whcc_reload( $whcc_pay( $t::place_order() ) );
		$data  = $whcc_update( $order, 'spcconf-early', 'accepted' );

		// WHCC is still placing it, so the site can't tell yet: WHCC is asked to send it again.
		$t::same( 503, $whcc_deliver( $data ), 'try again later' );

		$whcc_settle( $order->get_meta_value( 'whcc_submission_id' ), 'placed', array( array( 'spcconf-early', 99000001 ) ) );
		$t::same( 200, $whcc_deliver( $data ), 'accepted once WHCC placed it' );
		$t::same( 'accepted', $whcc_part_status( $order, 'spcconf-early' ), 'the WHCC order is accepted' );
	}
);

// The customer gets one "processing" email, not one per WHCC order.
$whcc_test(
	'updates: the first acceptance moves the order to processing, once',
	function () use ( $t, $whcc_sent_order, $whcc_confirmations, $whcc_deliver, $whcc_update, $whcc_reload ) {
		$order = $whcc_sent_order( true );
		list( $first, $second ) = $whcc_confirmations( $order );
		$told  = 0;
		$count = function () use ( &$told ) {
			$told++;
		};
		add_action( 'sunshine_admin_order_status_update', $count );
		try {
			$whcc_deliver( $whcc_update( $order, $first, 'accepted' ) );
			$t::same( 'processing', $whcc_reload( $order )->get_status(), 'processing' );
			$t::same( 'accepted', $whcc_reload( $order )->get_meta_value( 'whcc_order_status' ), 'summed up as accepted' );
			$whcc_deliver( $whcc_update( $order, $second, 'accepted' ) );
		} finally {
			remove_action( 'sunshine_admin_order_status_update', $count );
		}
		$t::same( 'processing', $whcc_reload( $order )->get_status(), 'still processing' );
		$t::same( 1, $told, 'the customer is told once [bug: core SPC_Order::has_status() reads ->slug from a string, so it is always false]' );
	}
);

$whcc_test(
	'updates: shipped adds the tracking and marks the order shipped',
	function () use ( $t, $whcc_sent_order, $whcc_confirmations, $whcc_deliver, $whcc_update, $whcc_reload, $whcc_part_status ) {
		$order   = $whcc_sent_order();
		$conf    = $whcc_confirmations( $order )[0];
		$package = function ( $number ) {
			return array(
				'Carrier'        => 'FedEx',
				'ShipDate'       => '2026-11-17T16:59:59-05:00',
				'TrackingNumber' => $number,
				'TrackingUrl'    => 'https://www.fedex.com/fedextrack/?trknbr=' . $number,
				'Weight'         => 1.0,
			);
		};
		$shipped = $whcc_update(
			$order,
			$conf,
			'shipped',
			array(
				'ShippingInfo' => array( $package( 'TRACK-A' ) ),
				'OrderNumber'  => 99000001,
			)
		);
		$t::same( 200, $whcc_deliver( $shipped ), 'accepted' );
		$order = $whcc_reload( $order );
		$t::same( 'shipped', $order->get_status(), 'the order is shipped' );
		$t::same( array( 'TRACK-A' ), wp_list_pluck( (array) $order->get_meta_value( 'shipment_tracking' ), 'tracking_number' ), 'with its tracking' );
		$t::same( 'shipped', $order->get_meta_value( 'whcc_order_status' ), 'summed up as shipped' );

		// The same delivery again (WHCC delivers at least once) changes nothing.
		$whcc_deliver( $shipped );
		$t::same( array( 'TRACK-A' ), wp_list_pluck( (array) $whcc_reload( $order )->get_meta_value( 'shipment_tracking' ), 'tracking_number' ), 'tracking is not added twice' );

		// A second package for the same WHCC order, in a later delivery.
		$whcc_deliver( $whcc_update( $order, $conf, 'shipped', array( 'ShippingInfo' => array( $package( 'TRACK-A' ), $package( 'TRACK-B' ) ) ) ) );
		$t::same( array( 'TRACK-A', 'TRACK-B' ), wp_list_pluck( (array) $whcc_reload( $order )->get_meta_value( 'shipment_tracking' ), 'tracking_number' ), 'the new tracking number is added' );
		$t::same( 'shipped', $whcc_part_status( $order, $conf ), 'the WHCC order is shipped' );
	}
);

// WHCC can split an order after placing it. The parts share the confirmation ID, and each has
// its own order number, tracking and progress.
$whcc_test(
	'updates: the parts of a split WHCC order are kept apart',
	function () use ( $t, $whcc_sent_order, $whcc_confirmations, $whcc_deliver, $whcc_update, $whcc_reload, $whcc_part_status ) {
		$order = $whcc_sent_order();
		$conf  = $whcc_confirmations( $order )[0];
		$whcc_deliver(
			$whcc_update(
				$order,
				$conf,
				'shipped',
				array(
					'ShippingInfo' => array( array( 'TrackingNumber' => 'TRACK-1' ) ),
					'OrderNumber'  => 99000001,
				)
			)
		);
		$whcc_deliver(
			$whcc_update(
				$order,
				$conf,
				'shipped',
				array(
					'ShippingInfo'   => array( array( 'TrackingNumber' => 'TRACK-2' ) ),
					'OrderNumber'    => 99000777,
					'SequenceNumber' => '1.2',
				)
			)
		);
		$orders = sunshine_whcc_get_whcc_orders( $whcc_reload( $order ) );
		$t::same( array( $conf, $conf . '#1.2' ), array_keys( $orders ), 'part 1 and part 1.2' );
		$t::same( '99000777', isset( $orders[ $conf . '#1.2' ]['order_number'] ) ? $orders[ $conf . '#1.2' ]['order_number'] : null, "part 1.2's own order number" );
		$t::same( array( 'TRACK-1', 'TRACK-2' ), wp_list_pluck( (array) $whcc_reload( $order )->get_meta_value( 'shipment_tracking' ), 'tracking_number' ), 'both tracking numbers' );

		// A new part 1.3 is only accepted so far. Part 1 having shipped says nothing about it.
		$whcc_deliver( $whcc_update( $order, $conf, 'accepted', array( 'SequenceNumber' => '1.3' ) ) );
		$t::same( 'accepted', $whcc_part_status( $order, $conf . '#1.3' ), 'part 1.3 is accepted, not shipped' );
		$t::same( 'partially_shipped', $whcc_reload( $order )->get_meta_value( 'whcc_order_status' ), 'summed up as partly shipped' );
	}
);

// Updates can arrive late or out of order. A late "accepted" must not undo "shipped", but a
// rejection or cancellation always counts.
$whcc_test(
	'updates: a WHCC order\'s status never moves backward',
	function () use ( $t, $whcc_sent_order, $whcc_confirmations, $whcc_deliver, $whcc_update, $whcc_reload, $whcc_part_status ) {
		$order = $whcc_sent_order( true );
		list( $first, $second ) = $whcc_confirmations( $order );
		$whcc_deliver( $whcc_update( $order, $first, 'shipped', array( 'ShippingInfo' => array( array( 'TrackingNumber' => 'TRACK-1' ) ) ) ) );
		$whcc_deliver( $whcc_update( $order, $first, 'accepted' ) );
		$t::same( 'shipped', $whcc_part_status( $order, $first ), 'a late "accepted" leaves it shipped' );
		$t::same( 'shipped', $whcc_reload( $order )->get_status(), 'the order stays shipped [bug: core SPC_Order::has_status() is always false, so a late acceptance moves it back to processing]' );

		$rejected = $whcc_update(
			$order,
			$second,
			'rejected',
			array(
				'Errors' => array(
					array(
						'ErrorCode' => '400.01',
						'Error'     => "Image hash value doesn't match, order canceled.",
					),
				),
			)
		);
		$told     = 0;
		$count    = function () use ( &$told ) {
			$told++;
		};
		add_action( 'sunshine_whcc_order_rejected', $count );
		try {
			$whcc_deliver( $rejected );
			$whcc_deliver( $rejected );
		} finally {
			remove_action( 'sunshine_whcc_order_rejected', $count );
		}
		$t::same( 'rejected', $whcc_part_status( $order, $second ), 'rejected' );
		$t::same( 1, $told, 'a repeated delivery is handled once' );
		$order = $whcc_reload( $order );
		$t::same( 'partially_failed', $order->get_meta_value( 'whcc_order_status' ), 'one shipped, one rejected: partly failed' );
		$t::same( true, false !== strpos( (string) $order->get_meta_value( 'whcc_last_error' ), "(400.01) Image hash value doesn't match" ), "WHCC's reason is on the order" );

		// A cancellation counts even after shipping.
		$whcc_deliver( $whcc_update( $order, $first, 'cancelled' ) );
		$t::same( 'cancelled', $whcc_part_status( $order, $first ), 'cancelled' );
		$t::same( 'rejected', $whcc_reload( $order )->get_meta_value( 'whcc_order_status' ), 'nothing left on its way' );
		$t::same( true, sunshine_whcc_can_resubmit( $whcc_reload( $order ) ), 'so it can be sent again' );
	}
);

/*
 * Profit
 */

// Profit is what the customer paid for the items less WHCC's price for what was made.
$whcc_test(
	'order: profit uses WHCC\'s price for what was made',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_pay ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$order = $whcc_pay( $t::place_order() );
		// 27.00 - 3 x 6.00.
		$t::money( 9.00, $order->get_profit(), 'profit' );
	}
);

// What WHCC charges for an order, for reports that take it off the profit.
$whcc_test(
	'order: WHCC\'s charges are offered to profit reports',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_pay ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$order = $t::place_order();
		$t::same( array(), apply_filters( 'sunshine_order_profit_deductions', array(), $order ), 'nothing before WHCC was sent the order' );

		$order      = $whcc_pay( $order );
		$deductions = apply_filters( 'sunshine_order_profit_deductions', array(), $order );
		$t::money( 18.00, isset( $deductions['whcc_products']['amount'] ) ? $deductions['whcc_products']['amount'] : null, 'WHCC products: 3 x 6.00' );
		$t::money( 8.00, isset( $deductions['whcc_shipping']['amount'] ) ? $deductions['whcc_shipping']['amount'] : null, 'WHCC shipping' );
	}
);
