<?php
/**
 * The payment checks in the core plugin: PayPal, Square and PayPal (Legacy).
 *
 * Rule for every gateway: an order is only marked paid when the gateway says the money came in,
 * for the whole order total, in the store's currency, to the store's own account, for this
 * order. Anything else leaves the order "pending" so the photographer can look at it.
 *
 * Helpers are in underpaid-checks-helpers.php. Nothing reaches PayPal or Square.
 */

$t = 'SPC_Totals_Test';

/*
 * PayPal. The PayPal button creates a PayPal order and the customer approves it. When the order
 * is submitted, Sunshine captures the PayPal order and checks what PayPal captured
 * (SPC_Payment_Method_PayPal::process_payment).
 */

$underpaid_paypal_use = function () use ( $underpaid_settings ) {
	$underpaid_settings(
		array(
			'paypal_active'            => 1,
			'paypal_mode'              => 'test',
			'paypal_client_id_sandbox' => 'spctotals-client',
			'paypal_secret_sandbox'    => 'spctotals-secret',
			'paypal_fee'               => 'none',
		)
	);
};

// What PayPal answers when Sunshine captures an approved PayPal order.
$underpaid_paypal_capture = function ( $value, $currency = 'USD' ) {
	return array(
		'id'             => 'SPCPPORDER',
		'status'         => 'COMPLETED',
		'purchase_units' => array(
			array(
				'reference_id' => 'default',
				'payments'     => array(
					'captures' => array(
						array(
							'id'                          => 'SPCCAPTURE',
							'status'                      => 'COMPLETED',
							'amount'                      => array(
								'currency_code' => $currency,
								'value'         => $value,
							),
							'seller_receivable_breakdown' => array(
								'gross_amount' => array(
									'currency_code' => $currency,
									'value'         => $value,
								),
							),
						),
					),
				),
			),
		),
	);
};

/**
 * Submit the order after the customer approved PayPal order $paypal_order_id. The checkout
 * remembers which PayPal order it created ($session_id, the same one unless a test says
 * otherwise). PayPal answers the capture with $capture. Returns what was caught.
 */
$underpaid_paypal_submit = function ( $paypal_order_id, $capture, $session_id = null ) use ( $underpaid_submit ) {
	SPC()->session->set( 'paypal_order_id', null === $session_id ? $paypal_order_id : $session_id );
	$_POST['paypal_order_id']       = $paypal_order_id;
	$_POST['paypal_payer_id']       = 'SPCPAYER';
	$_POST['paypal_payment_source'] = 'paypal';
	// A saved access token, so Sunshine doesn't ask PayPal for one.
	$token = function () {
		return 'spctotals-token';
	};
	add_filter( 'pre_transient_sunshine_paypal_token_test', $token );
	add_filter( 'pre_transient_sunshine_paypal_token_live', $token );
	try {
		return $underpaid_submit(
			function ( $request ) use ( $paypal_order_id, $capture ) {
				if ( '/v2/checkout/orders/' . $paypal_order_id . '/capture' === $request['path'] ) {
					return array( 201, $capture );
				}
				return null;
			},
			'PayPal capture'
		);
	} finally {
		unset( $_POST['paypal_order_id'], $_POST['paypal_payer_id'], $_POST['paypal_payment_source'] );
		remove_filter( 'pre_transient_sunshine_paypal_token_test', $token );
		remove_filter( 'pre_transient_sunshine_paypal_token_live', $token );
		SPC()->session->set( 'paypal_order_id', '' );
	}
};

// The order is still waiting for payment: not paid, not finished, no capture saved on it.
$underpaid_paypal_unpaid = function ( $order_id ) use ( $t, $underpaid_fresh, $underpaid_finished ) {
	$saved = $underpaid_fresh( $order_id );
	$t::same( 'pending', $saved->get_status(), 'order still waits for payment' );
	$t::same( false, $underpaid_finished( $saved ), 'order not finished' );
	$t::same( '', (string) $saved->get_meta_value( 'paypal_capture_id' ), 'no PayPal capture saved on the order' );
};

$t::test(
	'PayPal: capturing the full order total completes the order',
	function () use ( $t, $underpaid_paypal_use, $underpaid_place, $underpaid_paypal_submit, $underpaid_paypal_capture, $underpaid_fresh ) {
		$underpaid_paypal_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		// 100 + 10% tax = 110.
		$order = $underpaid_place( 'paypal', 110 );
		$underpaid_paypal_submit( 'SPCPPORDER', $underpaid_paypal_capture( '110.00' ) );
		$t::same( array(), (array) SPC()->cart->get_errors(), 'no checkout errors' );
		$saved = $underpaid_fresh( $order->get_id() );
		$t::same( true, $saved->is_paid(), 'order is paid' );
		$t::same( 'SPCCAPTURE', (string) $saved->get_meta_value( 'paypal_capture_id' ), 'PayPal capture saved on the order' );
	}
);

// The PayPal button made the PayPal order while the cart held one $50 print (55 with tax).
// The customer then added a second print and submitted: the order is 110, PayPal took 55.
$t::test(
	'PayPal: a capture smaller than the order leaves it unpaid',
	function () use ( $t, $underpaid_paypal_use, $underpaid_place, $underpaid_paypal_submit, $underpaid_paypal_capture, $underpaid_paypal_unpaid ) {
		$underpaid_paypal_use();
		$t::tax( 10 );
		$t::add( $t::product( 50 ), 2 );
		$order = $underpaid_place( 'paypal', 110 );
		$run   = $underpaid_paypal_submit( 'SPCPPORDER', $underpaid_paypal_capture( '55.00' ) );
		$t::same( 1, count( $run['sent'] ), 'the capture was asked for' );
		$t::same( true, count( (array) SPC()->cart->get_errors() ) > 0, 'checkout shows an error' );
		$underpaid_paypal_unpaid( $order->get_id() );
	}
);

// 110.00 euros is not 110.00 dollars.
$t::test(
	'PayPal: a capture in another currency leaves the order unpaid',
	function () use ( $t, $underpaid_paypal_use, $underpaid_place, $underpaid_paypal_submit, $underpaid_paypal_capture, $underpaid_paypal_unpaid ) {
		$underpaid_paypal_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $underpaid_place( 'paypal', 110 );
		$underpaid_paypal_submit( 'SPCPPORDER', $underpaid_paypal_capture( '110.00', 'EUR' ) );
		$t::same( true, count( (array) SPC()->cart->get_errors() ) > 0, 'checkout shows an error' );
		$underpaid_paypal_unpaid( $order->get_id() );
	}
);

// 3 x 19.99 = 59.97, 7.25% tax = 4.3478, so 4.35. Total 64.32, and PayPal captured 64.32.
$t::test(
	'PayPal: odd cents captured in full complete the order',
	function () use ( $t, $underpaid_paypal_use, $underpaid_place, $underpaid_paypal_submit, $underpaid_paypal_capture, $underpaid_fresh ) {
		$underpaid_paypal_use();
		$t::tax( 7.25 );
		$t::add( $t::product( 19.99 ), 3 );
		$order = $underpaid_place( 'paypal', 64.32 );
		$underpaid_paypal_submit( 'SPCPPORDER', $underpaid_paypal_capture( '64.32' ) );
		$t::same( array(), (array) SPC()->cart->get_errors(), 'no checkout errors' );
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'order is paid' );
	}
);

// The browser sends the PayPal order ID. One this checkout did not create (another customer's,
// or one from an earlier, cheaper cart) must not be captured and used for this order.
$t::test(
	'PayPal: a PayPal order this checkout did not create is not used',
	function () use ( $t, $underpaid_paypal_use, $underpaid_place, $underpaid_paypal_submit, $underpaid_paypal_capture, $underpaid_paypal_unpaid ) {
		$underpaid_paypal_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $underpaid_place( 'paypal', 110 );
		$run   = $underpaid_paypal_submit( 'SPCPPOTHER', $underpaid_paypal_capture( '110.00' ), 'SPCPPMINE' );
		$t::same( 0, count( $run['sent'] ), 'nothing sent to PayPal' );
		$t::same( true, count( (array) SPC()->cart->get_errors() ) > 0, 'checkout shows an error' );
		$underpaid_paypal_unpaid( $order->get_id() );
	}
);

// $110 with 10% tax inside, 10% off = 99, then a $30 gift card: 69 is left to pay with PayPal.
// The check is against the order total, which is what is left after the gift card.
$t::test(
	'PayPal: prices include tax, discount and gift card: capturing what was left to pay completes the order',
	function () use ( $t, $underpaid_paypal_use, $underpaid_place, $underpaid_paypal_submit, $underpaid_paypal_capture, $underpaid_fresh ) {
		$underpaid_paypal_use();
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$t::gift_card( 30 );
		$order = $underpaid_place( 'paypal', 69 );
		$underpaid_paypal_submit( 'SPCPPORDER', $underpaid_paypal_capture( '69.00' ) );
		$t::same( array(), (array) SPC()->cart->get_errors(), 'no checkout errors' );
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'order is paid' );
	}
);

// Yen has no smaller unit: PayPal captures "2200" for a 2200 yen order. 2199 yen is short.
$t::test(
	'PayPal: yen order paid in whole yen, and one yen short',
	function () use ( $t, $underpaid_paypal_use, $underpaid_place, $underpaid_paypal_submit, $underpaid_paypal_capture, $underpaid_fresh, $underpaid_paypal_unpaid, $underpaid_next_order ) {
		$underpaid_paypal_use();
		$t::option( 'currency', 'JPY' );
		$t::option( 'country', 'JP' );
		$t::tax( 10 );
		$product = $t::product( 1000 );
		$t::add( $product, 2 );
		// 2000 + 10% tax = 2200 yen.
		$order = $underpaid_place( 'paypal', 2200 );
		$underpaid_paypal_submit( 'SPCPPORDER', $underpaid_paypal_capture( '2200', 'JPY' ) );
		$t::same( array(), (array) SPC()->cart->get_errors(), 'no checkout errors for 2200 yen' );
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'paid in full: order is paid' );

		// The same cart again, a new order, and PayPal took one yen less.
		$underpaid_next_order();
		$t::add( $product, 2 );
		$short = $underpaid_place( 'paypal', 2200 );
		$underpaid_paypal_submit( 'SPCPPORDER', $underpaid_paypal_capture( '2199', 'JPY' ) );
		$underpaid_paypal_unpaid( $short->get_id() );
	}
);

/*
 * Square. The checkout charges the card from the server and saves the Square payment ID on the
 * order. When the order is submitted, Sunshine asks Square for that payment and checks it
 * (process_payment, payment_matches_order). Orders still pending later are checked again when
 * the customer reloads the checkout page (check_order_paid) and every 15 minutes
 * (reconcile_pending_orders). Square amounts are in the smallest unit of the currency: cents
 * for dollars, whole yen for yen.
 */

$underpaid_square_use = function () use ( $underpaid_settings ) {
	$underpaid_settings(
		array(
			'square_active'              => 1,
			'square_mode'                => 'test',
			'square_access_token_test'   => 'spctotals-token',
			'square_application_id_test' => 'sandbox-sq0idb-spctotals',
			'square_location_id_test'    => 'LSPCTOTALS',
			'square_fee'                 => 'none',
		)
	);
};

// A Square payment as Square's API returns it. $reference is the order number the payment was
// made for (this order's, unless a test says otherwise).
$underpaid_square_payment = function ( $order, $amount, $currency = 'USD', $reference = null ) {
	return array(
		'payment' => array(
			'id'           => 'sqpay_spc' . $order->get_id(),
			'status'       => 'COMPLETED',
			'amount_money' => array(
				'amount'   => $amount,
				'currency' => $currency,
			),
			'total_money'  => array(
				'amount'   => $amount,
				'currency' => $currency,
			),
			'reference_id' => null === $reference ? substr( (string) $order->get_order_number(), 0, 40 ) : $reference,
			'order_id'     => 'sqorder_spc' . $order->get_id(),
			'location_id'  => 'LSPCTOTALS',
		),
	);
};

// Square answers a look up of payment $payment_id with $payment.
$underpaid_square_reply = function ( $payments ) {
	return function ( $request ) use ( $payments ) {
		foreach ( $payments as $payment ) {
			if ( 'GET' === $request['method'] && '/v2/payments/' . $payment['payment']['id'] === $request['path'] ) {
				return array( 200, $payment );
			}
		}
		return null;
	};
};

// Submit a Square order whose card was charged: the payment ID is on the order, the way the
// checkout's card step saves it.
$underpaid_square_submit = function ( $order, $payment ) use ( $underpaid_submit, $underpaid_square_reply ) {
	$order->update_meta_value( 'square_payment_id', $payment['payment']['id'] );
	$_POST['square_payment_id'] = $payment['payment']['id'];
	try {
		return $underpaid_submit( $underpaid_square_reply( array( $payment ) ), 'Square payment check' );
	} finally {
		unset( $_POST['square_payment_id'] );
	}
};

$t::test(
	'Square: a payment of the full order total completes the order',
	function () use ( $t, $underpaid_square_use, $underpaid_place, $underpaid_square_submit, $underpaid_square_payment, $underpaid_fresh ) {
		$underpaid_square_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $underpaid_place( 'square', 110 );
		// 110.00 is 11000 cents.
		$underpaid_square_submit( $order, $underpaid_square_payment( $order, 11000 ) );
		$t::same( array(), (array) SPC()->cart->get_errors(), 'no checkout errors' );
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'order is paid' );
	}
);

// 3 x 19.99 = 59.97, 7.25% tax = 4.35, total 64.32. The full payment is 6432 cents and must
// complete the order. 6431 cents is a cent short and must not.
$t::test(
	'Square: odd cents: the full amount completes the order, a cent short does not',
	function () use ( $t, $underpaid_square_use, $underpaid_place, $underpaid_square_submit, $underpaid_square_payment, $underpaid_fresh, $underpaid_finished, $underpaid_next_order ) {
		$underpaid_square_use();
		$t::tax( 7.25 );
		$product = $t::product( 19.99 );
		$t::add( $product, 3 );
		$order = $underpaid_place( 'square', 64.32 );
		$underpaid_square_submit( $order, $underpaid_square_payment( $order, 6432 ) );
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), '6432 cents: order is paid' );

		$underpaid_next_order();
		$t::add( $product, 3 );
		$short = $underpaid_place( 'square', 64.32 );
		$underpaid_square_submit( $short, $underpaid_square_payment( $short, 6431 ) );
		$saved = $underpaid_fresh( $short->get_id() );
		$t::same( 'pending', $saved->get_status(), '6431 cents: order still waits for payment' );
		$t::same( false, $underpaid_finished( $saved ), '6431 cents: order not finished' );
	}
);

// Square says the card payment failed (declined), for the full amount.
$t::test(
	'Square: a failed payment leaves the order unpaid and the note says declined',
	function () use ( $t, $underpaid_square_use, $underpaid_place, $underpaid_square_submit, $underpaid_square_payment, $underpaid_fresh, $underpaid_finished, $underpaid_declined_note ) {
		$underpaid_square_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order                        = $underpaid_place( 'square', 110 );
		$payment                      = $underpaid_square_payment( $order, 11000 );
		$payment['payment']['status'] = 'FAILED';
		$underpaid_square_submit( $order, $payment );
		$errors = implode( ' ', (array) SPC()->cart->get_errors() );
		$t::same( true, false !== strpos( $errors, 'declined' ) && false === strpos( $errors, 'match' ), 'the customer is told it was declined (got: ' . $errors . ')' );
		$saved = $underpaid_fresh( $order->get_id() );
		$t::same( 'pending', $saved->get_status(), 'order still waits for payment' );
		$t::same( false, $underpaid_finished( $saved ), 'order not finished' );
		$underpaid_declined_note( $order->get_id() );
	}
);

$t::test(
	'Square: a payment in another currency leaves the order unpaid',
	function () use ( $t, $underpaid_square_use, $underpaid_place, $underpaid_square_submit, $underpaid_square_payment, $underpaid_fresh, $underpaid_finished ) {
		$underpaid_square_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $underpaid_place( 'square', 110 );
		// 11000 Canadian cents is not 110 US dollars.
		$underpaid_square_submit( $order, $underpaid_square_payment( $order, 11000, 'CAD' ) );
		$t::same( true, count( (array) SPC()->cart->get_errors() ) > 0, 'checkout shows an error' );
		$saved = $underpaid_fresh( $order->get_id() );
		$t::same( 'pending', $saved->get_status(), 'order still waits for payment' );
		$t::same( false, $underpaid_finished( $saved ), 'order not finished' );
	}
);

// Two orders of the same total. The card step for the second order was never finished, so it
// has no payment on it, and the browser sends the first order's payment ID instead.
$t::test(
	'Square: a payment made for another order leaves this one unpaid',
	function () use ( $t, $underpaid_square_use, $underpaid_place, $underpaid_submit, $underpaid_square_payment, $underpaid_square_reply, $underpaid_fresh, $underpaid_finished ) {
		$underpaid_square_use();
		$t::tax( 10 );
		$product = $t::product( 100 );
		$t::add( $product );
		$first   = $underpaid_place( 'square', 110 );
		$payment = $underpaid_square_payment( $first, 11000 );

		SPC()->session->set( 'checkout_order_id', '' );
		$second = $underpaid_place( 'square', 110 );
		$t::same( true, $first->get_id() !== $second->get_id(), 'two different orders' );

		$_POST['square_payment_id'] = $payment['payment']['id'];
		try {
			$underpaid_submit( $underpaid_square_reply( array( $payment ) ), 'Square payment check' );
		} finally {
			unset( $_POST['square_payment_id'] );
		}
		$t::same( true, count( (array) SPC()->cart->get_errors() ) > 0, 'checkout shows an error' );
		$saved = $underpaid_fresh( $second->get_id() );
		$t::same( 'pending', $saved->get_status(), 'second order still waits for payment' );
		$t::same( false, $underpaid_finished( $saved ), 'second order not finished' );
	}
);

// $20 of store credit pays part of the order: 100 + 10 tax - 20 = 90 left for the card. The
// check is against the order total, which is what is left after the credit.
$t::test(
	'Square: with store credit, paying what is left completes the order',
	function () use ( $t, $underpaid_square_use, $underpaid_place, $underpaid_square_submit, $underpaid_square_payment, $underpaid_fresh ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$underpaid_square_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $underpaid_place( 'square', 90 );
		$underpaid_square_submit( $order, $underpaid_square_payment( $order, 9000 ) );
		$t::same( array(), (array) SPC()->cart->get_errors(), 'no checkout errors' );
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'order is paid' );
	}
);

// Square takes yen in whole yen (yen has no smaller unit), so a 2200 yen order is paid with an
// amount of 2200.
$t::test(
	'Square: yen order paid in whole yen completes the order',
	function () use ( $t, $underpaid_square_use, $underpaid_place, $underpaid_square_submit, $underpaid_square_payment, $underpaid_fresh ) {
		$underpaid_square_use();
		$t::option( 'currency', 'JPY' );
		$t::option( 'country', 'JP' );
		$t::tax( 10 );
		$t::add( $t::product( 1000 ), 2 );
		$order = $underpaid_place( 'square', 2200 );
		$underpaid_square_submit( $order, $underpaid_square_payment( $order, 2200, 'JPY' ) );
		$t::same( array(), (array) SPC()->cart->get_errors(), 'no checkout errors' );
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'order is paid' );
	}
);

// A Square order left pending with a payment on it, the way a customer who was charged and then
// closed the browser leaves it.
$underpaid_square_stuck = function ( $price ) use ( $t, $underpaid_place, $underpaid_next_order ) {
	$underpaid_next_order();
	$t::add( $t::product( $price, array( 'taxable' => false ) ) );
	$order = $underpaid_place( 'square', $price );
	$order->update_meta_value( 'square_payment_id', 'sqpay_spc' . $order->get_id() );
	return $order;
};

// Every 15 minutes Sunshine checks pending Square orders from the last 3 days. Here only this
// test's two orders are looked at: one paid in full, one paid 50.00 of 100.00.
$t::test(
	'Square: the 15 minute check finishes the order paid in full and not the one paid short',
	function () use ( $t, $underpaid_square_use, $underpaid_square_stuck, $underpaid_square_payment, $underpaid_square_reply, $underpaid_run, $underpaid_fresh, $underpaid_finished ) {
		$underpaid_square_use();
		$full  = $underpaid_square_stuck( 100 );
		$short = $underpaid_square_stuck( 100 );
		$ids   = array( $full->get_id(), $short->get_id() );
		$only  = function ( $query ) use ( $ids ) {
			if ( 'sunshine-order' === $query->get( 'post_type' ) ) {
				$query->set( 'post__in', $ids );
			}
		};
		$square = sunshine_get_payment_method_by_id( 'square' );
		add_action( 'pre_get_posts', $only );
		try {
			$underpaid_run(
				function () use ( $square ) {
					$square->reconcile_pending_orders();
				},
				$underpaid_square_reply(
					array(
						$underpaid_square_payment( $full, 10000 ),
						$underpaid_square_payment( $short, 5000 ),
					)
				),
				'Square 15 minute check'
			);
		} finally {
			remove_action( 'pre_get_posts', $only );
		}
		$saved_full  = $underpaid_fresh( $full->get_id() );
		$saved_short = $underpaid_fresh( $short->get_id() );
		$t::same( true, $saved_full->is_paid(), 'paid in full: order is paid' );
		$t::same( 'pending', $saved_short->get_status(), 'paid short: order still waits for payment' );
		$t::same( false, $underpaid_finished( $saved_short ), 'paid short: order not finished' );
		$t::same( '1', (string) $saved_short->get_meta_value( 'square_payment_mismatch' ), 'paid short: mismatch noted on the order' );
	}
);

$t::test(
	'Square: the later check does not finish an order with a payment in another currency or for another order',
	function () use ( $t, $underpaid_square_use, $underpaid_square_stuck, $underpaid_square_payment, $underpaid_square_reply, $underpaid_run, $underpaid_fresh, $underpaid_finished ) {
		$underpaid_square_use();
		$euros = $underpaid_square_stuck( 100 );
		$other = $underpaid_square_stuck( 100 );
		$square = sunshine_get_payment_method_by_id( 'square' );
		$results = array();
		$underpaid_run(
			function () use ( $square, $euros, $other, &$results ) {
				$results['euros'] = $square->reconcile_order( $euros, array( 'force' => true ) );
				$results['other'] = $square->reconcile_order( $other, array( 'force' => true ) );
			},
			$underpaid_square_reply(
				array(
					// 10000 euro cents for a 100 dollar order.
					$underpaid_square_payment( $euros, 10000, 'EUR' ),
					// The right amount, but made for the first order.
					$underpaid_square_payment( $other, 10000, 'USD', substr( (string) $euros->get_order_number(), 0, 40 ) ),
				)
			),
			'Square later check'
		);
		foreach ( array(
			'other currency' => $euros,
			'other order'    => $other,
		) as $label => $order ) {
			$saved = $underpaid_fresh( $order->get_id() );
			$t::same( 'pending', $saved->get_status(), $label . ': order still waits for payment' );
			$t::same( false, $underpaid_finished( $saved ), $label . ': order not finished' );
		}
		$t::same( 'mismatch', isset( $results['euros']['action'] ) ? $results['euros']['action'] : null, 'other currency: found as not matching' );
		$t::same( 'mismatch', isset( $results['other']['action'] ) ? $results['other']['action'] : null, 'other order: found as not matching' );
	}
);

// The customer was charged but the page never moved on. Reloading the checkout page finishes
// the order and sends them to the receipt, only when the payment is right.
$t::test(
	'Square: reloading the checkout finishes a paid order, not one paid short',
	function () use ( $t, $underpaid_square_use, $underpaid_square_stuck, $underpaid_square_payment, $underpaid_square_reply, $underpaid_run, $underpaid_fresh, $underpaid_finished ) {
		$underpaid_square_use();
		$square  = sunshine_get_payment_method_by_id( 'square' );
		$on_page = function ( $is_page, $page ) {
			return 'checkout' === $page ? true : $is_page;
		};
		add_filter( 'is_sunshine_page', $on_page, 10, 2 );
		try {
			$full = $underpaid_square_stuck( 100 );
			$run  = $underpaid_run(
				function () use ( $square ) {
					$square->check_order_paid();
				},
				$underpaid_square_reply( array( $underpaid_square_payment( $full, 10000 ) ) ),
				'Square checkout reload'
			);
			$t::same( true, $underpaid_fresh( $full->get_id() )->is_paid(), 'paid in full: order is paid' );
			$t::same( $full->get_received_permalink(), $run['redirect'], 'paid in full: customer sent to the receipt' );

			$short = $underpaid_square_stuck( 100 );
			$run   = $underpaid_run(
				function () use ( $square ) {
					$square->check_order_paid();
				},
				$underpaid_square_reply( array( $underpaid_square_payment( $short, 9999 ) ) ),
				'Square checkout reload'
			);
			$saved = $underpaid_fresh( $short->get_id() );
			$t::same( 'pending', $saved->get_status(), 'a cent short: order still waits for payment' );
			$t::same( false, $underpaid_finished( $saved ), 'a cent short: order not finished' );
			$t::same( null, $run['redirect'], 'a cent short: customer stays on the checkout' );
		} finally {
			remove_filter( 'is_sunshine_page', $on_page, 10 );
		}
	}
);

/*
 * PayPal (Legacy). The customer is sent to PayPal with a form that lists the items, and PayPal
 * tells the site about the payment afterwards with an IPN. Sunshine asks PayPal to confirm the
 * IPN is real ("VERIFIED"), then checks who was paid, the currency and the amount
 * (SPC_Payment_Method_PayPal_Legacy::ipn). Both the form and the IPN end with exit, so they run
 * in a separate process.
 */

$underpaid_legacy_settings = function () use ( $underpaid_settings ) {
	return $underpaid_settings(
		array(
			'currency'                 => 'USD',
			'paypal-legacy_active'     => 1,
			'paypal-legacy_mode'       => 'test',
			'paypal-legacy_email_test' => 'photos@example.com',
			'paypal-legacy_fee'        => 'none',
		)
	);
};

// Send the customer to PayPal: returns the form fields PayPal gets.
$underpaid_legacy_form = function ( $order, $options ) use ( $underpaid_child, $underpaid_form_fields ) {
	$child = $underpaid_child(
		array(
			'method'   => 'paypal-legacy',
			'function' => 'process_payment',
			'order_id' => $order->get_id(),
			'options'  => $options,
			'orders'   => array( $order->get_id() ),
		)
	);
	return $underpaid_form_fields( $child['stdout'] );
};

// What PayPal charges for a cart form: each line's amount x quantity, plus tax, minus discount.
$underpaid_legacy_form_total = function ( $fields ) {
	$total = 0;
	for ( $line = 1; isset( $fields[ 'amount_' . $line ] ); $line++ ) {
		$total += round( (float) $fields[ 'amount_' . $line ], 2 ) * intval( $fields[ 'quantity_' . $line ] );
	}
	$total += isset( $fields['tax_cart'] ) ? (float) $fields['tax_cart'] : 0;
	$total -= isset( $fields['discount_amount_cart'] ) ? (float) $fields['discount_amount_cart'] : 0;
	return round( $total, 2 );
};

// PayPal sends an IPN about a payment, and confirms it as VERIFIED when asked. $ipn holds the
// fields that differ from a payment of $gross dollars to the store for this order.
$underpaid_legacy_ipn = function ( $order, $options, $gross, $ipn = array() ) use ( $underpaid_child ) {
	$fields = array_merge(
		array(
			'txn_type'       => 'cart',
			'payment_status' => 'Completed',
			'mc_gross'       => $gross,
			'mc_currency'    => 'USD',
			'receiver_email' => 'photos@example.com',
			'receiver_id'    => 'SPCRECEIVER',
			'business'       => 'photos@example.com',
			'custom'         => (string) $order->get_id(),
			'txn_id'         => 'SPCTXN' . $order->get_id(),
			'payer_email'    => 'buyer@example.com',
		),
		$ipn
	);
	return $underpaid_child(
		array(
			'method'   => 'paypal-legacy',
			'function' => 'ipn',
			'options'  => $options,
			'get'      => array( 'sunshine_paypal_ipn' => 'paypal_standard_ipn' ),
			'post'     => $fields,
			'raw_body' => http_build_query( $fields ),
			'replies'  => array( array( '#paypal\.com/cgi-bin/webscr#', 200, 'VERIFIED' ) ),
			'orders'   => array( $order->get_id() ),
		)
	);
};

$underpaid_legacy_unpaid = function ( $order_id, $label = '' ) use ( $t, $underpaid_fresh, $underpaid_finished ) {
	$saved = $underpaid_fresh( $order_id );
	$t::same( 'pending', $saved->get_status(), $label . 'order still waits for payment' );
	$t::same( false, $underpaid_finished( $saved ), $label . 'order not finished' );
};

$t::test(
	'PayPal Legacy: a verified payment of the order total to the store completes the order',
	function () use ( $t, $underpaid_legacy_settings, $underpaid_place, $underpaid_legacy_form, $underpaid_legacy_form_total, $underpaid_legacy_ipn, $underpaid_fresh, $underpaid_warnings_from ) {
		$options = $underpaid_legacy_settings();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order  = $underpaid_place( 'paypal-legacy', 110 );
		$fields = $underpaid_legacy_form( $order, $options );
		// 100 + 10 tax = 110.
		$t::money( 110, $underpaid_legacy_form_total( $fields ), 'PayPal is asked for the order total' );
		$t::same( 'photos@example.com', isset( $fields['business'] ) ? $fields['business'] : null, 'paid to the store account' );
		$child = $underpaid_legacy_ipn( $order, $options, '110.00' );
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'order is paid' );
		$t::same( array(), $underpaid_warnings_from( $child, 'paypal-legacy.php' ), 'no PHP warnings from the IPN' );
	}
);

// The PayPal form is built in the customer's browser, so its amounts can be changed there.
// PayPal then charges what the form says, and the IPN is still VERIFIED.
$t::test(
	'PayPal Legacy: a payment smaller than the order leaves it unpaid',
	function () use ( $t, $underpaid_legacy_settings, $underpaid_place, $underpaid_legacy_form, $underpaid_legacy_ipn, $underpaid_legacy_unpaid ) {
		$options = $underpaid_legacy_settings();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $underpaid_place( 'paypal-legacy', 110 );
		$underpaid_legacy_form( $order, $options );
		$underpaid_legacy_ipn( $order, $options, '1.10' );
		$underpaid_legacy_unpaid( $order->get_id() );
	}
);

$t::test(
	'PayPal Legacy: a payment in another currency or to another PayPal account leaves the order unpaid',
	function () use ( $t, $underpaid_legacy_settings, $underpaid_place, $underpaid_legacy_form, $underpaid_legacy_ipn, $underpaid_legacy_unpaid ) {
		$options = $underpaid_legacy_settings();
		$t::tax( 10 );
		$product = $t::product( 100 );
		$t::add( $product );
		$euros = $underpaid_place( 'paypal-legacy', 110 );
		$underpaid_legacy_form( $euros, $options );
		$underpaid_legacy_ipn( $euros, $options, '110.00', array( 'mc_currency' => 'EUR' ) );
		$underpaid_legacy_unpaid( $euros->get_id(), 'euros: ' );

		// The form's "business" was changed to someone else's account: they got the money.
		SPC()->session->set( 'checkout_order_id', '' );
		$elsewhere = $underpaid_place( 'paypal-legacy', 110 );
		$underpaid_legacy_form( $elsewhere, $options );
		$underpaid_legacy_ipn(
			$elsewhere,
			$options,
			'110.00',
			array(
				'receiver_email' => 'someone-else@example.com',
				'receiver_id'    => 'OTHERRECEIVER',
				'business'       => 'someone-else@example.com',
			)
		);
		$underpaid_legacy_unpaid( $elsewhere->get_id(), 'other account: ' );
	}
);

// The customer was sent to PayPal with one $50 print (55 with tax), came back to the shop,
// added a second print, and the same order was updated to 110. Then they paid the first,
// older PayPal page.
$t::test(
	'PayPal Legacy: paying an older, smaller PayPal page after the cart grew leaves the order unpaid',
	function () use ( $t, $underpaid_legacy_settings, $underpaid_place, $underpaid_legacy_form, $underpaid_legacy_ipn, $underpaid_legacy_unpaid ) {
		$options = $underpaid_legacy_settings();
		$t::tax( 10 );
		$product = $t::product( 50 );
		$t::add( $product );
		$order = $underpaid_place( 'paypal-legacy', 55 );
		$underpaid_legacy_form( $order, $options );
		$t::add( $product );
		$updated = $underpaid_place( 'paypal-legacy', 110 );
		$t::same( $order->get_id(), $updated->get_id(), 'the same order was updated' );
		$underpaid_legacy_ipn( $order, $options, '55.00' );
		$underpaid_legacy_unpaid( $order->get_id() );
	}
);

// A $2 fee for paying with PayPal is part of the order: 100 + 2 = 102. PayPal has to be asked
// for 102. If it charges only 100, the order is $2 short and must stay unpaid.
$t::test(
	'PayPal Legacy: the payment fee is charged, and a payment without it leaves the order unpaid',
	function () use ( $t, $underpaid_legacy_settings, $underpaid_place, $underpaid_legacy_form, $underpaid_legacy_form_total, $underpaid_legacy_ipn, $underpaid_legacy_unpaid ) {
		$options = $underpaid_legacy_settings();
		$options = array_merge( $options, array( 'paypal-legacy_fee' => 'amount', 'paypal-legacy_fee_amount' => '2', 'paypal-legacy_fee_name' => 'PayPal fee' ) );
		foreach ( $options as $key => $value ) {
			$t::option( $key, $value );
		}
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$order  = $underpaid_place( 'paypal-legacy', 102 );
		$fields = $underpaid_legacy_form( $order, $options );
		$t::money( 102, $underpaid_legacy_form_total( $fields ), 'PayPal is asked for the order total, fee included' );
		$underpaid_legacy_ipn( $order, $options, '100.00' );
		$underpaid_legacy_unpaid( $order->get_id() );
	}
);

// A $30 gift card pays part of a $100 order, so 70 is left for PayPal. PayPal must be asked for
// 70, and a verified payment of 70 completes the order.
$t::test(
	'PayPal Legacy: with a gift card, paying what is left completes the order',
	function () use ( $t, $underpaid_legacy_settings, $underpaid_place, $underpaid_legacy_form, $underpaid_legacy_form_total, $underpaid_legacy_ipn, $underpaid_fresh ) {
		$options = $underpaid_legacy_settings();
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::gift_card( 30 );
		$order  = $underpaid_place( 'paypal-legacy', 70 );
		$fields = $underpaid_legacy_form( $order, $options );
		$t::money( 70, $underpaid_legacy_form_total( $fields ), 'PayPal is asked for what is left to pay' );
		$underpaid_legacy_ipn( $order, $options, '70.00' );
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'order is paid' );
	}
);
