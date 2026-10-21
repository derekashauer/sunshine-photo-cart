<?php
/**
 * Payment gateways that take the payment from Sunshine itself: Mollie, Authorize.net and the
 * Mercado Pago card form, plus the Stripe Pro and Square Pro add-ons, which only remove the
 * platform fee. The amount charged must be the order total, in the format the gateway expects.
 * Helpers are in addon-payment-gateways-helpers.php. Nothing is sent to any gateway.
 */

$t = 'SPC_Totals_Test';

/*
 * Mollie takes the amount as text with the exact number of decimals the currency has:
 * "73.92" for dollars and euros, "2200" for yen (yen has no decimals).
 */

// Start a Mollie payment for the order. Returns what was sent to Mollie to create the payment.
$addon_gateways_mollie_start = function ( $order ) use ( $t, $addon_gateways_mollie, $addon_gateways_run ) {
	list( $mollie, $connection ) = $addon_gateways_mollie(
		function ( $method, $url, $body ) {
			if ( 'POST' === $method && preg_match( '#/v2/payments$#', $url ) ) {
				$request = json_decode( $body, true );
				return array(
					'resource'    => 'payment',
					'id'          => 'tr_spctotals',
					'mode'        => 'test',
					'status'      => 'open',
					'amount'      => $request['amount'],
					'description' => $request['description'],
					'_links'      => array(
						'checkout' => array(
							'href' => 'https://www.mollie.com/checkout/spctotals',
							'type' => 'text/html',
						),
					),
				);
			}
			throw new \Mollie\Api\Exceptions\ApiException( 'Blocked by the totals tests' );
		}
	);
	$run = $addon_gateways_run(
		function () use ( $mollie, $order ) {
			$mollie->process( $order );
		}
	);
	$t::same( 'https://www.mollie.com/checkout/spctotals', $run['redirect'], 'customer is sent to Mollie' );
	return empty( $connection->sent[0]['body'] ) ? array() : $connection->sent[0]['body'];
};

$t::test(
	'Mollie: amount is the order total with two decimals (odd cents)',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_odd, $addon_gateways_place, $addon_gateways_mollie_start ) {
		$addon_gateways_cart_odd();
		$addon_gateways_use( 'mollie' );
		$order = $addon_gateways_place( 'mollie', 73.92 );
		$sent  = $addon_gateways_mollie_start( $order );
		$t::same( '73.92', isset( $sent['amount']['value'] ) ? $sent['amount']['value'] : null, 'amount' );
		$t::same( 'USD', isset( $sent['amount']['currency'] ) ? $sent['amount']['currency'] : null, 'currency' );
		$t::same( $order->get_id(), isset( $sent['metadata']['order_id'] ) ? $sent['metadata']['order_id'] : null, 'order number' );
	}
);

$t::test(
	'Mollie: discount code, gift card and taxed shipping',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_card, $addon_gateways_place, $addon_gateways_mollie_start ) {
		$addon_gateways_cart_card();
		$addon_gateways_use( 'mollie' );
		$order = $addon_gateways_place( 'mollie', 69 );
		$sent  = $addon_gateways_mollie_start( $order );
		$t::same( '69.00', isset( $sent['amount']['value'] ) ? $sent['amount']['value'] : null, 'amount' );
	}
);

// 100 - 20 store credit + 2.50 fee = 82.50.
$t::test(
	'Mollie: store credit and a fixed payment fee',
	function () use ( $t, $addon_gateways_use, $addon_gateways_credits, $addon_gateways_place, $addon_gateways_mollie_start ) {
		$addon_gateways_credits( 20 );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$addon_gateways_use( 'mollie', 'amount', 2.50 );
		$order = $addon_gateways_place( 'mollie', 82.50 );
		$sent  = $addon_gateways_mollie_start( $order );
		$t::same( '82.50', isset( $sent['amount']['value'] ) ? $sent['amount']['value'] : null, 'amount' );
	}
);

// 100 + 10 tax = 110, 20% off after tax = 88.
$t::test(
	'Mollie: discount after tax',
	function () use ( $t, $addon_gateways_use, $addon_gateways_place, $addon_gateways_mollie_start ) {
		$t::option( 'currency', 'EUR' );
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$addon_gateways_use( 'mollie' );
		$order = $addon_gateways_place( 'mollie', 88 );
		$sent  = $addon_gateways_mollie_start( $order );
		$t::same( '88.00', isset( $sent['amount']['value'] ) ? $sent['amount']['value'] : null, 'amount' );
		$t::same( 'EUR', isset( $sent['amount']['currency'] ) ? $sent['amount']['currency'] : null, 'currency' );
	}
);

// 2000 yen + 200 tax = 2200 yen. Mollie wants yen with no decimals: "2200", not "2200.00".
$t::test(
	'Mollie: yen is sent without decimals',
	function () use ( $t, $addon_gateways_use, $addon_gateways_place, $addon_gateways_mollie_start ) {
		$t::option( 'currency', 'JPY' );
		$t::tax( 10 );
		$t::add( $t::product( 1000 ), 2 );
		$addon_gateways_use( 'mollie' );
		$order = $addon_gateways_place( 'mollie', 2200 );
		$sent  = $addon_gateways_mollie_start( $order );
		$t::same( '2200', isset( $sent['amount']['value'] ) ? $sent['amount']['value'] : null, 'amount in yen' );
		$t::same( 'JPY', isset( $sent['amount']['currency'] ) ? $sent['amount']['currency'] : null, 'currency' );
	}
);

$t::test(
	'Mollie: part refund to the cent',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_odd, $addon_gateways_place, $addon_gateways_mollie, $addon_gateways_order_action ) {
		$addon_gateways_cart_odd();
		$addon_gateways_use( 'mollie' );
		$order = $addon_gateways_place( 'mollie', 73.92 );
		$order->update_meta_value( 'mollie_payment_id', 'tr_spctotals' );

		list( $mollie, $connection ) = $addon_gateways_mollie(
			function ( $method, $url, $body ) {
				if ( 'GET' === $method && preg_match( '#/v2/payments/tr_spctotals$#', $url ) ) {
					return array(
						'resource'        => 'payment',
						'id'              => 'tr_spctotals',
						'status'          => 'paid',
						'amount'          => array(
							'value'    => '73.92',
							'currency' => 'USD',
						),
						'amountRemaining' => array(
							'value'    => '73.92',
							'currency' => 'USD',
						),
						'_links'          => array(
							'self' => array(
								'href' => 'https://api.mollie.com/v2/payments/tr_spctotals',
								'type' => 'application/hal+json',
							),
						),
					);
				}
				if ( 'POST' === $method && preg_match( '#/v2/payments/tr_spctotals/refunds$#', $url ) ) {
					$request = json_decode( $body, true );
					return array(
						'resource'  => 'refund',
						'id'        => 're_spctotals',
						'amount'    => $request['amount'],
						'status'    => 'pending',
						'paymentId' => 'tr_spctotals',
					);
				}
				throw new \Mollie\Api\Exceptions\ApiException( 'Blocked by the totals tests' );
			}
		);
		$addon_gateways_order_action(
			function () use ( $mollie, $order ) {
				$mollie->process_refund( $order->get_id() );
			},
			array(
				'mollie_refund_amount' => '19.99',
				'mollie_refund_reason' => 'Totals test',
			),
			null
		);
		$refund = null;
		foreach ( $connection->sent as $request ) {
			if ( 'POST' === $request['method'] && preg_match( '#/refunds$#', $request['url'] ) ) {
				$refund = $request['body'];
			}
		}
		$t::same( '19.99', isset( $refund['amount']['value'] ) ? $refund['amount']['value'] : null, 'refund amount' );
		$t::same( 'USD', isset( $refund['amount']['currency'] ) ? $refund['amount']['currency'] : null, 'refund currency' );
		$t::money( 19.99, sunshine_get_order( $order->get_id() )->get_refund_total(), 'refund on the order' );
	}
);

/*
 * Authorize.net charges the card right away from the checkout page. The amount is in dollars
 * with cents.
 */

// The card the customer typed in at checkout (Authorize.net's test Visa number).
$addon_gateways_authorizenet_card = array(
	'authorizenet_card'       => '4111 1111 1111 1111',
	'authorizenet_expiration' => '12/30',
	'authorizenet_cvc'        => '123',
	'authorizenet_zipcode'    => '80202',
);

// Charge the card for the order. Returns the transaction request Authorize.net would get.
$addon_gateways_authorizenet_charge = function ( $order ) use ( $addon_gateways_authorizenet, $addon_gateways_authorizenet_card ) {
	$requests = $addon_gateways_authorizenet(
		function ( $gateway ) use ( $order, $addon_gateways_authorizenet_card ) {
			foreach ( $addon_gateways_authorizenet_card as $key => $value ) {
				$_POST[ $key ] = $value;
			}
			try {
				$gateway->process( $order );
			} finally {
				foreach ( array_keys( $addon_gateways_authorizenet_card ) as $key ) {
					unset( $_POST[ $key ] );
				}
			}
		}
	);
	return isset( $requests[0]['transactionRequest'] ) ? $requests[0]['transactionRequest'] : array();
};

// 69 (see the shared cart) + 1.50 fee = 70.50.
$t::test(
	'Authorize.net: amount charged is the order total (discount, gift card, fixed fee)',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_card, $addon_gateways_place, $addon_gateways_authorizenet_charge ) {
		$addon_gateways_cart_card();
		$addon_gateways_use( 'authorizenet', 'amount', 1.50 );
		$t::option( 'authorizenet_transaction_type', 'capture' );
		$order   = $addon_gateways_place( 'authorizenet', 70.50 );
		$request = $addon_gateways_authorizenet_charge( $order );
		$t::money( 70.50, isset( $request['amount'] ) ? $request['amount'] : null, 'amount charged' );
		$t::same( 'authCaptureTransaction', isset( $request['transactionType'] ) ? $request['transactionType'] : null, 'charge right away' );
		$t::same( array(), (array) SPC()->cart->get_errors(), 'no card errors' );
		$t::same( '60000000001', sunshine_get_order( $order->get_id() )->get_meta_value( 'authorizenet_transaction_id' ), 'transaction saved on the order' );
	}
);

// $110 with 10% tax inside, minus 10 store credit = 100.
$t::test(
	'Authorize.net: prices include tax with store credit',
	function () use ( $t, $addon_gateways_use, $addon_gateways_credits, $addon_gateways_place, $addon_gateways_authorizenet_charge ) {
		$addon_gateways_credits( 10 );
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$addon_gateways_use( 'authorizenet' );
		$order   = $addon_gateways_place( 'authorizenet', 100 );
		$request = $addon_gateways_authorizenet_charge( $order );
		$t::money( 100, isset( $request['amount'] ) ? $request['amount'] : null, 'amount charged' );
	}
);

// The settings offer "Authorize Only" so the photographer can check an order before the money
// is taken. With it chosen, the card must only be authorized, not charged.
$t::test(
	'Authorize.net: "Authorize Only" setting does not charge the card yet',
	function () use ( $t, $addon_gateways_use, $addon_gateways_place, $addon_gateways_authorizenet_charge ) {
		$t::add( $t::product( 25, array( 'taxable' => false ) ) );
		$addon_gateways_use( 'authorizenet' );
		$t::option( 'authorizenet_transaction_type', 'auth' );
		$order   = $addon_gateways_place( 'authorizenet', 25 );
		$request = $addon_gateways_authorizenet_charge( $order );
		$t::money( 25, isset( $request['amount'] ) ? $request['amount'] : null, 'amount' );
		$t::same( 'authOnlyTransaction', isset( $request['transactionType'] ) ? $request['transactionType'] : null, 'authorize only' );
	}
);

$t::test(
	'Authorize.net: part refund to the cent',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_odd, $addon_gateways_place, $addon_gateways_authorizenet ) {
		$addon_gateways_cart_odd();
		$addon_gateways_use( 'authorizenet' );
		$order = $addon_gateways_place( 'authorizenet', 73.92 );
		$order->update_meta_value( 'authorizenet_transaction_id', '60000000001' );
		$order->update_meta_value( 'authorizenet_last4', '1111' );
		$order->update_meta_value( 'authorizenet_exp_date', '2030-12' );
		$requests = $addon_gateways_authorizenet(
			function ( $gateway ) use ( $order ) {
				$_POST['authorizenet_refund_amount'] = '19.99';
				try {
					$gateway->process_refund( $order->get_id() );
				} finally {
					unset( $_POST['authorizenet_refund_amount'] );
				}
			}
		);
		$request = isset( $requests[0]['transactionRequest'] ) ? $requests[0]['transactionRequest'] : array();
		$t::same( 'refundTransaction', isset( $request['transactionType'] ) ? $request['transactionType'] : null, 'refund' );
		$t::money( 19.99, isset( $request['amount'] ) ? $request['amount'] : null, 'refund amount' );
		$t::same( '60000000001', isset( $request['refTransId'] ) ? $request['refTransId'] : null, 'refunds the right payment' );
		$t::money( 19.99, sunshine_get_order( $order->get_id() )->get_refund_total(), 'refund on the order' );
	}
);

/*
 * Mercado Pago card form ("Bricks"). The checkout page asks the site for a preference with the
 * cart total, the card form shows it, and when the customer pays, the site sends the payment
 * to Mercado Pago. When the order is submitted, the site looks the payment up and accepts it.
 */

$addon_gateways_mercadopago_use = function ( $fee_type = 'none', $fee_amount = '' ) use ( $t, $addon_gateways_use ) {
	$t::require_plugin( 'sunshine_payment_method_mercadopago', 'Mercado Pago add-on' );
	$t::option( 'mercadopago_access_token_test', 'TEST-spctotals' );
	$t::option( 'mercadopago_public_key_test', 'TEST-spctotals-public' );
	$addon_gateways_use( 'mercadopago', $fee_type, $fee_amount );
};

// Shared cart (69) plus a 3% fee on what is left to pay: 69 x 3% = 2.07, so 71.07.
$t::test(
	'Mercado Pago: card form is set up for the cart total (discount, gift card, percent fee)',
	function () use ( $t, $addon_gateways_mercadopago_use, $addon_gateways_cart_card, $addon_gateways_run, $addon_gateways_find, $addon_gateways_wire ) {
		$addon_gateways_cart_card();
		$addon_gateways_mercadopago_use( 'percent', 3 );
		$t::checkout_address();
		$cart = $t::calculate();
		$t::money( 71.07, $cart->get_total(), 'cart total' );

		$gateway           = sunshine_get_payment_method_by_id( 'mercadopago' );
		$_POST['security'] = wp_create_nonce( 'sunshine_mercadopago' );
		try {
			$run = $addon_gateways_run(
				function () use ( $gateway ) {
					$gateway->init_brick();
				},
				function ( $request ) {
					return '/checkout/preferences' === $request['path'] ? array( 201, array( 'id' => 'pref-spctotals' ) ) : null;
				},
				true
			);
		} finally {
			unset( $_POST['security'] );
		}
		$preference = $addon_gateways_wire( $addon_gateways_find( $run['sent'], '#^/checkout/preferences$#' ) );
		$items      = isset( $preference['items'] ) ? $preference['items'] : array();
		$t::same( 1, count( $items ), 'one line' );
		$t::money( 71.07, isset( $items[0]['unit_price'] ) ? $items[0]['unit_price'] : null, 'line price' );
		$t::same( 'USD', isset( $items[0]['currency_id'] ) ? $items[0]['currency_id'] : null, 'currency' );
		$reply = json_decode( $run['output'], true );
		$t::money( 71.07, isset( $reply['data']['total'] ) ? $reply['data']['total'] : null, 'amount the card form is told to charge' );
	}
);

// The card form runs in the customer's browser, so the amount it sends back can be changed
// there. The site must charge the cart total, not whatever amount the browser sends.
$t::test(
	'Mercado Pago: the amount charged comes from the cart, not the browser',
	function () use ( $t, $addon_gateways_mercadopago_use, $addon_gateways_run, $addon_gateways_find, $addon_gateways_wire ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$addon_gateways_mercadopago_use();
		$t::checkout_address();
		$cart = $t::calculate();
		$t::money( 110, $cart->get_total(), 'cart total' );

		$gateway            = sunshine_get_payment_method_by_id( 'mercadopago' );
		$_POST['security']  = wp_create_nonce( 'sunshine_mercadopago' );
		$_POST['form_data'] = array(
			'token'              => 'card-token-spctotals',
			'issuer_id'          => '24',
			'payment_method_id'  => 'visa',
			'transaction_amount' => '1.00',
			'installments'       => '1',
			'payer'              => array(
				'email'          => 'totals-test@example.com',
				'identification' => array(
					'type'   => 'CPF',
					'number' => '12345678909',
				),
			),
		);
		try {
			$run = $addon_gateways_run(
				function () use ( $gateway ) {
					$gateway->create_payment();
				},
				function ( $request ) {
					return '/v1/payments' === $request['path'] ? array(
						201,
						array(
							'id'     => 5550001,
							'status' => 'approved',
						),
					) : null;
				},
				true
			);
		} finally {
			unset( $_POST['security'], $_POST['form_data'] );
			SPC()->session->set( 'mercadopago_payment_id', '' );
		}
		$payment = $addon_gateways_wire( $addon_gateways_find( $run['sent'], '#^/v1/payments$#' ) );
		$t::money( 110, isset( $payment['transaction_amount'] ) ? $payment['transaction_amount'] : null, 'amount charged' );
	}
);

// Submit the order after paying in the card form. $paid is what Mercado Pago says was paid.
// Returns the errors the checkout shows; the order is only completed when there are none.
$addon_gateways_mercadopago_submit = function ( $order, $paid ) use ( $addon_gateways_run ) {
	$gateway = sunshine_get_payment_method_by_id( 'mercadopago' );
	SPC()->session->set( 'mercadopago_payment_id', 5550001 );
	$_GET['mercadopago_payment_id'] = '5550001';
	try {
		$addon_gateways_run(
			function () use ( $gateway, $order ) {
				$gateway->process_payment( $order );
			},
			function ( $request ) use ( $paid, $order ) {
				if ( '/v1/payments/5550001' !== $request['path'] ) {
					return null;
				}
				return array(
					200,
					array(
						'id'                 => 5550001,
						'status'             => 'approved',
						'transaction_amount' => $paid,
						'currency_id'        => $order->get_currency(),
						'fee_details'        => array(
							array(
								'type'   => 'mercadopago_fee',
								'amount' => 4.50,
							),
						),
					),
				);
			}
		);
	} finally {
		unset( $_GET['mercadopago_payment_id'] );
		SPC()->session->set( 'mercadopago_payment_id', '' );
	}
	return (array) SPC()->cart->get_errors();
};

$t::test(
	'Mercado Pago: a payment for the full order is accepted',
	function () use ( $t, $addon_gateways_mercadopago_use, $addon_gateways_place, $addon_gateways_mercadopago_submit ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$addon_gateways_mercadopago_use();
		$order  = $addon_gateways_place( 'mercadopago', 110 );
		$errors = $addon_gateways_mercadopago_submit( $order, 110 );
		$t::same( array(), $errors, 'no checkout errors' );
		$saved = sunshine_get_order( $order->get_id() );
		$t::same( '5550001', (string) $saved->get_meta_value( 'mercadopago_payment_id' ), 'payment saved on the order' );
		$t::money( 4.50, $saved->get_meta_value( 'mercadopago_fees' ), 'Mercado Pago fee saved on the order' );
	}
);

// An approved Mercado Pago payment for $1 must not complete a $110 order.
$t::test(
	'Mercado Pago: a payment smaller than the order is not accepted',
	function () use ( $t, $addon_gateways_mercadopago_use, $addon_gateways_place, $addon_gateways_mercadopago_submit ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$addon_gateways_mercadopago_use();
		$order  = $addon_gateways_place( 'mercadopago', 110 );
		$errors = $addon_gateways_mercadopago_submit( $order, 1.00 );
		$t::same( true, count( $errors ) > 0, 'checkout shows an error' );
		$t::same( '', (string) sunshine_get_order( $order->get_id() )->get_meta_value( 'mercadopago_payment_id' ), 'payment not saved on the order' );
	}
);

// The order screen offers "Refund payment in Mercado Pago". A part refund of 19.99 must reach
// Mercado Pago (its refunds endpoint) and be recorded on the order.
$t::test(
	'Mercado Pago: part refund from the order screen',
	function () use ( $t, $addon_gateways_mercadopago_use, $addon_gateways_place, $addon_gateways_order_action, $addon_gateways_find, $addon_gateways_wire ) {
		$addon_gateways_mercadopago_use();
		// A Mercado Pago library from somewhere else would send the refund itself, outside
		// WordPress, where it can't be stopped.
		if ( class_exists( 'PaymentRefundClient' ) ) {
			$t::skip( 'Another Mercado Pago library is loaded, so the refund could reach Mercado Pago' );
		}
		$t::add( $t::product( 73.92, array( 'taxable' => false ) ) );
		$order = $addon_gateways_place( 'mercadopago', 73.92 );
		$order->update_meta_value( 'mercadopago_payment_id', '5550001' );
		$gateway = sunshine_get_payment_method_by_id( 'mercadopago' );
		$run     = $addon_gateways_order_action(
			function () use ( $gateway, $order ) {
				$gateway->process_refund( $order->get_id() );
			},
			array( 'mercadopago_refund_amount' => '19.99' ),
			function ( $request ) {
				if ( '/v1/payments/5550001/refunds' !== $request['path'] ) {
					return null;
				}
				return array(
					201,
					array(
						'id'     => 777001,
						'amount' => 19.99,
						'status' => 'approved',
					),
				);
			}
		);
		$refund = $addon_gateways_wire( $addon_gateways_find( $run['sent'], '#^/v1/payments/5550001/refunds$#' ) );
		$t::money( 19.99, isset( $refund['amount'] ) ? $refund['amount'] : null, 'refund sent to Mercado Pago' );
		$t::money( 19.99, sunshine_get_order( $order->get_id() )->get_refund_total(), 'refund on the order' );
	}
);

/*
 * Stripe Pro and Square Pro remove Sunshine's 5% platform fee. With them active, a payment
 * carries no platform fee at all.
 */

// $100 with 10% tax and $10 taxed shipping: 121, all of it charged, no platform fee.
$t::test(
	'Square Pro: no platform fee on a Square payment',
	function () use ( $t, $addon_gateways_use, $addon_gateways_place, $addon_gateways_run, $addon_gateways_find, $addon_gateways_wire ) {
		$t::require_plugin( 'sunshine_square_init', 'Square Pro add-on' );
		$square = sunshine_get_payment_method_by_id( 'square' );
		if ( ! $square || ! $square->is_allowed() ) {
			$t::skip( 'Square is not connected on this site' );
		}
		$t::same( 0.0, (float) $square->get_effective_application_fee_percent(), 'platform fee percent' );

		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$addon_gateways_use( 'square' );
		$order = $addon_gateways_place( 'square', 121 );

		$_POST['security']  = wp_create_nonce( 'sunshine_square' );
		$_POST['source_id'] = 'cnon:card-nonce-ok';
		try {
			$run = $addon_gateways_run(
				function () use ( $square ) {
					$square->init_order();
				},
				function ( $request ) use ( $order ) {
					if ( '/v2/orders' !== $request['path'] ) {
						return null;
					}
					// Square answers with the itemized order and its total, here the order total.
					return array(
						200,
						array(
							'order' => array(
								'id'          => 'sq_order_spctotals',
								'version'     => 1,
								'total_money' => array(
									'amount'   => (int) round( $order->get_total() * 100 ),
									'currency' => 'USD',
								),
							),
						),
					);
				},
				true
			);
		} finally {
			unset( $_POST['security'], $_POST['source_id'] );
		}
		$payment = $addon_gateways_wire( $addon_gateways_find( $run['sent'], '#^/v2/payments$#' ) );
		$t::same( 12100, isset( $payment['amount_money']['amount'] ) ? (int) $payment['amount_money']['amount'] : null, 'amount in cents' );
		$t::same( false, array_key_exists( 'app_fee_money', $payment ), 'no platform fee sent to Square' );
	}
);

$t::test(
	'Stripe Pro: no platform fee on Stripe payments',
	function () use ( $t ) {
		$t::require_plugin( 'sunshine_stripe_init', 'Stripe Pro add-on' );
		$stripe = sunshine_get_payment_method_by_id( 'stripe' );
		if ( ! $stripe ) {
			$t::skip( 'Stripe payment method not available' );
		}
		$t::same( 0.0, (float) $stripe->get_application_fee_percent(), 'platform fee percent' );
		$t::same( 0.0, (float) $stripe->get_effective_application_fee_percent(), 'platform fee percent for this store' );
	}
);
