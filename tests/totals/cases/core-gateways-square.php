<?php
/**
 * What Sunshine sends to Square, compared with the order total. Nothing reaches Square: every
 * outgoing request is caught before it leaves the site and answered with a made up reply.
 *
 * When a card is paid, Sunshine sends Square two things:
 * - An itemized Square order (line items, shipping as a service charge, taxes, discounts).
 *   Square works out its own total from these. When it doesn't match, Sunshine cancels the
 *   Square order and the payment goes through without the itemized list.
 * - The payment itself, for the amount to charge.
 *
 * How Square adds up an order (Square Orders API):
 * - Each line: base_price_money x quantity. Money is in the smallest unit of the currency
 *   (cents for USD, whole yen for JPY).
 * - An ORDER discount is shared across the line items by their price, before tax.
 * - ADDITIVE tax is added on top of each taxed line (after its share of the discount) and each
 *   taxed service charge. INCLUSIVE tax is already inside the prices and adds nothing.
 * - Service charges in the subtotal phase are added as they are.
 * - Line items can't be priced below zero; money off has to be a discount.
 */

$t = 'SPC_Totals_Test';

// The customer picked Square at checkout. Fees come from this test only, never the site's settings.
$gateways_square_use = function ( $fee_type = 'none', $fee_amount = '' ) use ( $t ) {
	$t::option( 'square_fee', $fee_type );
	$t::option( 'square_fee_amount', (string) $fee_amount );
	$t::option( 'square_fee_name', 'Card fee' );
	SPC()->cart->set_checkout_data_item( 'payment_method', 'square' );
};

// Sunshine without Square Pro takes a 5% platform fee (US stores only). Square Pro is active
// on the test site, so the tests that check the fee turn it back on.
$gateways_square_platform_fee = function () {
	return 5;
};

// What Square would charge for an itemized order, in the smallest unit of the currency.
$gateways_square_charge = function ( $square_order ) {
	$taxes = array();
	foreach ( (array) ( isset( $square_order['taxes'] ) ? $square_order['taxes'] : array() ) as $tax ) {
		$taxes[ $tax['uid'] ] = $tax;
	}
	$tax_on = function ( $amount, $applied ) use ( $taxes ) {
		$tax = 0;
		foreach ( (array) $applied as $applied_tax ) {
			$uid = $applied_tax['tax_uid'];
			if ( isset( $taxes[ $uid ] ) && 'ADDITIVE' === $taxes[ $uid ]['type'] ) {
				$tax += (int) round( $amount * floatval( $taxes[ $uid ]['percentage'] ) / 100 );
			}
		}
		return $tax;
	};

	$lines = array();
	foreach ( $square_order['line_items'] as $line ) {
		$lines[] = array(
			'gross' => intval( $line['base_price_money']['amount'] ) * intval( $line['quantity'] ),
			'taxes' => isset( $line['applied_taxes'] ) ? $line['applied_taxes'] : array(),
		);
	}

	// Share the order discount across the line items by their price; the last line takes
	// whatever is left so the shares add up exactly.
	$discount = 0;
	foreach ( (array) ( isset( $square_order['discounts'] ) ? $square_order['discounts'] : array() ) as $order_discount ) {
		$discount += intval( $order_discount['amount_money']['amount'] );
	}
	$gross = array_sum( wp_list_pluck( $lines, 'gross' ) );
	$left  = $discount;
	$last  = count( $lines ) - 1;
	foreach ( $lines as $i => $line ) {
		$share              = ( $i === $last || ! $gross ) ? $left : (int) round( $discount * $line['gross'] / $gross );
		$left              -= $share;
		$lines[ $i ]['net'] = $line['gross'] - $share;
	}

	$total = 0;
	foreach ( $lines as $line ) {
		$total += $line['net'] + $tax_on( $line['net'], $line['taxes'] );
	}
	foreach ( (array) ( isset( $square_order['service_charges'] ) ? $square_order['service_charges'] : array() ) as $charge ) {
		$amount = intval( $charge['amount_money']['amount'] );
		$total += $amount;
		if ( ! empty( $charge['taxable'] ) ) {
			$total += $tax_on( $amount, isset( $charge['applied_taxes'] ) ? $charge['applied_taxes'] : array() );
		}
	}
	return $total;
};

/**
 * Place the order with Square and send the card payment, the way the checkout page does once
 * the customer enters a card. Returns the order and what would have been sent to Square:
 * 'square_order' (the itemized order) and 'payment' (the charge).
 */
$gateways_square_pay = function () use ( $t, $gateways_square_charge ) {
	$square = sunshine_get_payment_method_by_id( 'square' );
	if ( ! $square || ! $square->is_allowed() ) {
		$t::skip( 'Square is not connected on this site' );
	}
	$order = $t::place_order( 'square' );

	$sent = array();
	$http = function ( $pre, $args, $url ) use ( &$sent, $gateways_square_charge ) {
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$body   = json_decode( isset( $args['body'] ) ? (string) $args['body'] : '', true );
		$sent[] = array(
			'method' => isset( $args['method'] ) ? $args['method'] : 'GET',
			'path'   => $path,
			'body'   => $body,
		);
		$reply  = array(
			'errors' => array(
				array(
					'category' => 'API_ERROR',
					'code'     => 'BLOCKED_BY_TEST',
					'detail'   => 'Blocked by the totals tests',
				),
			),
		);
		if ( '/v2/orders' === $path && ! empty( $body['order'] ) ) {
			// Square answers with the order and the total it worked out.
			$reply = array(
				'order' => array(
					'id'          => 'sq_order_spctest',
					'version'     => 1,
					'total_money' => array(
						'amount'   => $gateways_square_charge( $body['order'] ),
						'currency' => $body['order']['line_items'][0]['base_price_money']['currency'],
					),
				),
			);
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $reply ),
			'response' => array(
				'code'    => isset( $reply['errors'] ) ? 400 : 200,
				'message' => isset( $reply['errors'] ) ? 'Bad Request' : 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	$die  = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};

	add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $die );
	$_POST['security']  = wp_create_nonce( 'sunshine_square' );
	$_POST['source_id'] = 'cnon:card-nonce-ok';
	ob_start();
	try {
		$square->init_order();
	} catch ( SPC_Totals_Test_Done $e ) {
		// init_order() always ends by sending a JSON response.
	} finally {
		ob_end_clean();
		unset( $_POST['security'], $_POST['source_id'] );
		remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $die );
	}

	$found = array(
		'order'        => $order,
		'square_order' => null,
		'payment'      => null,
	);
	foreach ( $sent as $request ) {
		if ( '/v2/orders' === $request['path'] && ! empty( $request['body']['order']['line_items'] ) ) {
			$found['square_order'] = $request['body']['order'];
		} elseif ( '/v2/payments' === $request['path'] ) {
			$found['payment'] = $request['body'];
		}
	}
	if ( empty( $found['square_order'] ) || empty( $found['payment'] ) ) {
		throw new Exception( 'Square order or payment was not sent: ' . wp_json_encode( wp_list_pluck( $sent, 'path' ) ) );
	}
	return $found;
};

// Square's itemized order and its payment both come to the order total, and no line is below zero.
// $unit is how many of the smallest money unit make one: 100 for USD, 1 for JPY.
$gateways_square_check = function ( $sent, $expected_total, $unit = 100 ) use ( $t, $gateways_square_charge ) {
	$order = $sent['order'];
	$t::money( $expected_total, $order->get_total(), 'order total' );

	$negative = array();
	foreach ( $sent['square_order']['line_items'] as $line ) {
		if ( intval( $line['base_price_money']['amount'] ) < 0 ) {
			$negative[] = $line['name'] . ' ' . $line['base_price_money']['amount'];
		}
	}
	$t::same( array(), $negative, 'no Square line item below zero' );

	$charge = $gateways_square_charge( $sent['square_order'] );
	$t::money( $order->get_total(), $charge / $unit, 'Square order adds up to the order total' );
	$t::money( $order->get_total(), intval( $sent['payment']['amount_money']['amount'] ) / $unit, 'amount Square charges' );
};

$t::test(
	'Square: taxed items and taxed shipping',
	function () use ( $t, $gateways_square_use, $gateways_square_pay, $gateways_square_check ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$gateways_square_use();
		$t::calculate();
		$sent = $gateways_square_pay();
		// 100 + 10 shipping, tax on 110 = 11, total 121.
		$gateways_square_check( $sent, 121 );
		$t::same( 'USD', $sent['payment']['amount_money']['currency'], 'currency' );
		$t::same( false, isset( $sent['payment']['app_fee_money'] ), 'no platform fee with Square Pro' );
	}
);

// 3 x 19.99 = 59.97, tax 7.25% = 4.35, total 64.32. Square must charge 6432 cents.
$t::test(
	'Square: odd cents are charged in full',
	function () use ( $t, $gateways_square_use, $gateways_square_pay, $gateways_square_check ) {
		$t::tax( 7.25 );
		$t::add( $t::product( 19.99 ), 3 );
		$gateways_square_use();
		$t::calculate();
		$sent = $gateways_square_pay();
		$t::same( 6432, intval( $sent['payment']['amount_money']['amount'] ), 'amount in cents' );
		$gateways_square_check( $sent, 64.32 );
	}
);

$t::test(
	'Square: discount code before tax',
	function () use ( $t, $gateways_square_use, $gateways_square_pay, $gateways_square_check ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$gateways_square_use();
		$t::calculate();
		// 100 - 20 = 80, tax 8, total 88.
		$gateways_square_check( $gateways_square_pay(), 88 );
	}
);

// $15 off a $100 order where half is taxed: half the discount comes off the taxed part, the same
// way Square shares an order discount. Tax on 50 - 7.50 = 4.25. 85 + 4.25 = 89.25.
$t::test(
	'Square: fixed discount on taxed and untaxed items',
	function () use ( $t, $gateways_square_use, $gateways_square_pay, $gateways_square_check ) {
		$t::tax( 10 );
		$t::add( $t::product( 50 ) );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::discount( 'amount-total', 15 );
		$gateways_square_use();
		$t::calculate();
		$gateways_square_check( $gateways_square_pay(), 89.25 );
	}
);

// $10 off only the untaxed product: the taxed one keeps its full tax. 50 + 5 tax + 40 = 95.
// Square spreads an order discount over every line, which lowers the tax it works out.
$t::test(
	'Square: discount on one untaxed product',
	function () use ( $t, $gateways_square_use, $gateways_square_pay, $gateways_square_check ) {
		$t::tax( 10 );
		$taxed   = $t::product( 50 );
		$untaxed = $t::product( 50, array( 'taxable' => false ) );
		$t::add( $taxed );
		$t::add( $untaxed );
		$t::discount( 'amount-product', 10, array( 'allowed_products' => array( (string) $untaxed ) ) );
		$gateways_square_use();
		$cart = $t::calculate();
		$t::money( 5, $cart->get_tax(), 'tax on the taxed product only' );
		$gateways_square_check( $gateways_square_pay(), 95 );
	}
);

// The discount comes off after tax: 100 + 10 tax - 22 = 88. Square takes an order discount off
// before working out tax, so it has to be told the tax some other way.
$t::test(
	'Square: discount after tax',
	function () use ( $t, $gateways_square_use, $gateways_square_pay, $gateways_square_check ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$gateways_square_use();
		$t::calculate();
		$gateways_square_check( $gateways_square_pay(), 88 );
	}
);

// Credit pays part of the order after tax: 100 + 10 tax - 20 = 90.
$t::test(
	'Square: store credit',
	function () use ( $t, $gateways_square_use, $gateways_square_pay, $gateways_square_check ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$gateways_square_use();
		$t::calculate();
		$gateways_square_check( $gateways_square_pay(), 90 );
	}
);

// A gift card pays part of the order after tax: 100 + 10 tax - 30 = 80.
$t::test(
	'Square: gift card',
	function () use ( $t, $gateways_square_use, $gateways_square_pay, $gateways_square_check ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::gift_card( 30 );
		$gateways_square_use();
		$t::calculate();
		$gateways_square_check( $gateways_square_pay(), 80 );
	}
);

$t::test(
	'Square: prices include tax with taxed shipping',
	function () use ( $t, $gateways_square_use, $gateways_square_pay, $gateways_square_check ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 12.99 ), 3 );
		$t::flat_shipping( 6, true );
		$gateways_square_use();
		$t::calculate();
		$sent = $gateways_square_pay();
		// The customer pays the prices they saw: 3 x 12.99 + 6 = 44.97, tax inside.
		$gateways_square_check( $sent, 44.97 );
		$taxes = isset( $sent['square_order']['taxes'] ) ? $sent['square_order']['taxes'] : array();
		$t::same( 'INCLUSIVE', isset( $taxes[0]['type'] ) ? $taxes[0]['type'] : null, 'Square is told the tax is already in the price' );
	}
);

$t::test(
	'Square: prices include tax with a discount code',
	function () use ( $t, $gateways_square_use, $gateways_square_pay, $gateways_square_check ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$gateways_square_use();
		$t::calculate();
		// 110 - 11 = 99, tax inside.
		$gateways_square_check( $gateways_square_pay(), 99 );
	}
);

$t::test(
	'Square: percent payment fee',
	function () use ( $t, $gateways_square_use, $gateways_square_pay, $gateways_square_check ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$gateways_square_use( 'percent', 3 );
		$t::calculate();
		// 110 + 3% of 110 = 113.30.
		$gateways_square_check( $gateways_square_pay(), 113.30 );
	}
);

// 7 x 3.33 = 23.31, 10% quantity discount = 2.331 off, line 20.979, so 20.98. Square only takes
// a price each, and 20.979 / 7 = 2.997 each, which can't be said in whole cents.
$t::test(
	'Square: quantity discount on a line',
	function () use ( $t, $gateways_square_use, $gateways_square_pay, $gateways_square_check ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$rule = array(
			'items' => array(
				array(
					'qty'      => '5',
					'discount' => '10',
					'type'     => 'percent',
				),
			),
		);
		$t::add(
			$t::product(
				3.33,
				array(
					'qty_discount' => $rule,
					'taxable'      => false,
				)
			),
			7
		);
		$gateways_square_use();
		$t::calculate();
		$gateways_square_check( $gateways_square_pay(), 20.98 );
	}
);

// The platform fee is 5% of what Square charges: 5% of 121 = 6.05.
$t::test(
	'Square: platform fee is 5% of the payment',
	function () use ( $t, $gateways_square_use, $gateways_square_pay, $gateways_square_platform_fee ) {
		add_filter( 'sunshine_square_application_fee_percent', $gateways_square_platform_fee, 999 );
		try {
			$t::tax( 10 );
			$t::add( $t::product( 100 ) );
			$t::flat_shipping( 10, true );
			$gateways_square_use();
			$t::calculate();
			$sent = $gateways_square_pay();
			$t::same( 12100, intval( $sent['payment']['amount_money']['amount'] ), 'amount in cents' );
			$t::same( 605, isset( $sent['payment']['app_fee_money'] ) ? intval( $sent['payment']['app_fee_money']['amount'] ) : null, 'platform fee in cents' );
		} finally {
			remove_filter( 'sunshine_square_application_fee_percent', $gateways_square_platform_fee, 999 );
		}
	}
);

// Square takes money in the smallest unit of the currency, which for yen is one yen.
// A 2000 yen order is 2000, not 200000.
$t::test(
	'Square: yen is sent in whole yen',
	function () use ( $t, $gateways_square_use, $gateways_square_pay, $gateways_square_check ) {
		$t::option( 'currency', 'JPY' );
		$t::option( 'country', 'JP' );
		$t::add( $t::product( 1000, array( 'taxable' => false ) ), 2 );
		$gateways_square_use();
		$t::calculate();
		$sent = $gateways_square_pay();
		$t::same( 'JPY', $sent['payment']['amount_money']['currency'], 'currency' );
		$t::same( 2000, intval( $sent['payment']['amount_money']['amount'] ), 'amount in yen' );
		$t::same( 1000, intval( $sent['square_order']['line_items'][0]['base_price_money']['amount'] ), 'price each in yen' );
		$gateways_square_check( $sent, 2000, 1 );
	}
);

/**
 * Ask Square to refund a placed order from the order screen, and return what would have been
 * sent to Square. The reply is an error, so the order is not marked refunded and no email goes
 * out. The error notice is saved for the logged in user, which is the test's own customer.
 */
$gateways_square_refund = function ( $order, $amount = '' ) {
	$square = sunshine_get_payment_method_by_id( 'square' );
	$sent   = null;
	$http   = function ( $pre, $args, $url ) use ( &$sent ) {
		if ( '/v2/refunds' === wp_parse_url( $url, PHP_URL_PATH ) ) {
			$sent = json_decode( (string) $args['body'], true );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'errors' => array(
						array(
							'category' => 'API_ERROR',
							'code'     => 'BLOCKED_BY_TEST',
							'detail'   => 'Blocked by the totals tests',
						),
					),
				)
			),
			'response' => array(
				'code'    => 400,
				'message' => 'Bad Request',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
	if ( '' !== $amount ) {
		$_POST['square_refund_amount'] = $amount;
	}
	try {
		$square->process_refund( $order->get_id() );
	} finally {
		remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
		unset( $_POST['square_refund_amount'] );
	}
	return $sent;
};

// A paid Square order, saved the way a real Square payment saves it: the payment ID, and the
// platform fee Square took (5% of 64.32 = 3.216, so 3.22).
$gateways_square_paid_order = function () use ( $t, $gateways_square_use ) {
	$square = sunshine_get_payment_method_by_id( 'square' );
	if ( ! $square || ! $square->is_allowed() ) {
		$t::skip( 'Square is not connected on this site' );
	}
	$t::customer();
	$t::tax( 7.25 );
	$t::add( $t::product( 19.99 ), 3 );
	$gateways_square_use();
	$t::calculate();
	$order = $t::place_order( 'square' );
	$order->update_meta_value( 'square_payment_id', 'sq_pay_spctest' );
	$order->update_meta_value( 'square_app_fee', 322 / 100 );
	return sunshine_get_order( $order->get_id() );
};

// The full refund is the whole order total, 64.32, and all of the 3.22 platform fee goes back.
$t::test(
	'Square: full refund is the order total',
	function () use ( $t, $gateways_square_paid_order, $gateways_square_refund ) {
		$order = $gateways_square_paid_order();
		$t::money( 64.32, $order->get_total(), 'order total' );
		$sent = $gateways_square_refund( $order );
		$t::same( true, is_array( $sent ), 'a refund was sent' );
		$t::same( 6432, $sent ? intval( $sent['amount_money']['amount'] ) : null, 'refund in cents' );
		$t::same( 322, $sent && isset( $sent['app_fee_money'] ) ? intval( $sent['app_fee_money']['amount'] ) : null, 'platform fee refunded in cents' );
	}
);

// Refund 10.01 of 64.32. The platform fee goes back in the same share: 3.22 x 10.01 / 64.32 = 0.50.
$t::test(
	'Square: part refund to the cent',
	function () use ( $t, $gateways_square_paid_order, $gateways_square_refund ) {
		$order = $gateways_square_paid_order();
		$sent  = $gateways_square_refund( $order, '10.01' );
		$t::same( 1001, $sent ? intval( $sent['amount_money']['amount'] ) : null, 'refund in cents' );
		$t::same( 50, $sent && isset( $sent['app_fee_money'] ) ? intval( $sent['app_fee_money']['amount'] ) : null, 'platform fee refunded in cents' );
	}
);
