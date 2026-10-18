<?php
/**
 * What Sunshine sends to PayPal when the PayPal button creates an order, compared with what the
 * customer saw in the cart. Nothing reaches PayPal: every outgoing request is caught before it
 * leaves the site.
 *
 * PayPal's rules for the order it is sent:
 * - amount = item_total + tax_total + shipping - discount, to the cent.
 * - item_total = each item's unit_amount x quantity, added up.
 * - No item below zero, no part of the breakdown below zero.
 * - USD amounts have at most 2 decimals. JPY has no decimals at all.
 */

$t = 'SPC_Totals_Test';

// The customer picked PayPal at checkout. Fees come from this test only, never the site's settings.
$gateways_paypal_use = function ( $fee_type = 'none', $fee_amount = '' ) use ( $t ) {
	$t::option( 'paypal_fee', $fee_type );
	$t::option( 'paypal_fee_amount', (string) $fee_amount );
	$t::option( 'paypal_fee_name', 'PayPal fee' );
	SPC()->cart->set_checkout_data_item( 'payment_method', 'paypal' );
};

// Build the PayPal order for the current cart and return what would be sent to PayPal.
$gateways_paypal_args = function () use ( $t ) {
	$paypal = sunshine_get_payment_method_by_id( 'paypal' );
	if ( ! $paypal ) {
		$t::skip( 'PayPal payment method not available' );
	}

	$sent    = null;
	$capture = function ( $args ) use ( &$sent ) {
		$sent = $args;
		return $args;
	};
	// Every request gets a made up empty answer, so nothing ever leaves the site.
	$block_http = function () {
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	$die        = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};

	add_filter( 'sunshine_paypal_order_args', $capture, 999 );
	add_filter( 'pre_http_request', $block_http, PHP_INT_MAX );
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $die );
	$_REQUEST['security'] = wp_create_nonce( 'sunshine_checkout_paypal_create_order' );
	ob_start();
	try {
		$paypal->create_order();
	} catch ( SPC_Totals_Test_Done $e ) {
		// create_order() always ends by sending a JSON response.
	} finally {
		ob_end_clean();
		unset( $_REQUEST['security'] );
		remove_filter( 'sunshine_paypal_order_args', $capture, 999 );
		remove_filter( 'pre_http_request', $block_http, PHP_INT_MAX );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $die );
		SPC()->session->set( 'paypal_order_id', '' );
	}

	if ( empty( $sent ) ) {
		throw new Exception( 'No PayPal order was built' );
	}
	return $sent;
};

// Check the PayPal order against PayPal's rules and the numbers worked out by hand.
// $expected holds 'amount' and any of 'item_total', 'tax_total', 'shipping', 'discount'.
$gateways_paypal_check = function ( $args, $expected, $decimals = 2 ) use ( $t ) {
	$unit      = $args['purchase_units'][0];
	$breakdown = $unit['amount']['breakdown'];

	$negative   = 0;
	$items_sum  = 0;
	$all_values = array( 'amount' => $unit['amount']['value'] );
	foreach ( $unit['items'] as $i => $item ) {
		if ( (float) $item['unit_amount']['value'] < 0 ) {
			++$negative;
		}
		$items_sum                         += (float) $item['unit_amount']['value'] * (int) $item['quantity'];
		$all_values[ 'item ' . ( $i + 1 ) ] = $item['unit_amount']['value'];
	}
	foreach ( $breakdown as $key => $part ) {
		$all_values[ $key ] = $part['value'];
		if ( (float) $part['value'] < 0 ) {
			++$negative;
		}
	}
	$t::same( 0, $negative, 'nothing below zero' );

	// PayPal refuses more decimals than the currency has.
	$pattern = ( 0 === $decimals ) ? '/^\d+$/' : '/^\d+(\.\d{1,2})?$/';
	$bad     = array();
	foreach ( $all_values as $key => $value ) {
		if ( ! preg_match( $pattern, (string) $value ) ) {
			$bad[] = $key . '=' . $value;
		}
	}
	$t::same( array(), $bad, 'every amount has at most ' . $decimals . ' decimals' );

	$t::money( $items_sum, $breakdown['item_total']['value'], 'item total is the items added up' );
	$sum = (float) $breakdown['item_total']['value'] + (float) $breakdown['tax_total']['value'] + (float) $breakdown['shipping']['value'] - (float) $breakdown['discount']['value'];
	$t::money( $unit['amount']['value'], $sum, 'breakdown adds up to the amount' );

	$t::money( $expected['amount'], $unit['amount']['value'], 'amount charged' );
	foreach ( array( 'item_total', 'tax_total', 'shipping', 'discount' ) as $key ) {
		if ( isset( $expected[ $key ] ) ) {
			$t::money( $expected[ $key ], $breakdown[ $key ]['value'], $key );
		}
	}
};

// After the customer approves in PayPal, the order is placed and the captured payment must equal
// the saved order total, or Sunshine refuses to complete it. So the amount PayPal was asked for
// has to be the placed order's total.
$gateways_paypal_order_matches = function ( $args ) use ( $t ) {
	$order = $t::place_order( 'paypal' );
	$t::money( $order->get_total(), $args['purchase_units'][0]['amount']['value'], 'PayPal amount is the placed order total' );
};

// A percent fee for paying with PayPal is part of what PayPal collects, so it is sent as an item.
$t::test(
	'PayPal: percent payment fee',
	function () use ( $t, $gateways_paypal_use, $gateways_paypal_args, $gateways_paypal_check, $gateways_paypal_order_matches ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$gateways_paypal_use( 'percent', 3 );
		$cart = $t::calculate();
		// 100 + 10 tax = 110, fee 3% of 110 = 3.30, total 113.30.
		$t::money( 113.30, $cart->get_total(), 'cart total' );
		$args = $gateways_paypal_args();
		$gateways_paypal_check(
			$args,
			array(
				'amount'     => 113.30,
				'item_total' => 103.30,
				'tax_total'  => 10,
				'discount'   => 0,
			)
		);
		$gateways_paypal_order_matches( $args );
	}
);

$t::test(
	'PayPal: fixed payment fee with a gift card',
	function () use ( $t, $gateways_paypal_use, $gateways_paypal_args, $gateways_paypal_check ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::gift_card( 30 );
		$gateways_paypal_use( 'amount', 2 );
		$t::calculate();
		// 100 + 2 fee - 30 card = 72. The card goes in the discount, the fee is an item.
		$gateways_paypal_check(
			$gateways_paypal_args(),
			array(
				'amount'     => 72,
				'item_total' => 102,
				'discount'   => 30,
			)
		);
	}
);

// The customer saw 100 + 10 tax - 22 discount = 88.
$t::test(
	'PayPal: discount after tax',
	function () use ( $t, $gateways_paypal_use, $gateways_paypal_args, $gateways_paypal_check, $gateways_paypal_order_matches ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$gateways_paypal_use();
		$t::calculate();
		$args = $gateways_paypal_args();
		$gateways_paypal_check(
			$args,
			array(
				'amount'     => 88,
				'item_total' => 100,
				'tax_total'  => 10,
				'discount'   => 22,
			)
		);
		$gateways_paypal_order_matches( $args );
	}
);

// PayPal wants prices without tax plus the tax. 110 with 10% tax inside is 100 + 10.
// 10% off 110 = 11 off, of which 10 is price and 1 is tax: 100 - 10 + 9 tax = 99.
$t::test(
	'PayPal: prices include tax with a discount code',
	function () use ( $t, $gateways_paypal_use, $gateways_paypal_args, $gateways_paypal_check ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$gateways_paypal_use();
		$t::calculate();
		$gateways_paypal_check(
			$gateways_paypal_args(),
			array(
				'amount'     => 99,
				'item_total' => 100,
				'tax_total'  => 9,
				'discount'   => 10,
			)
		);
	}
);

// No discount here, so PayPal must not be sent a made up discount to cover rounding.
$t::test(
	'PayPal: prices include tax with taxed shipping',
	function () use ( $t, $gateways_paypal_use, $gateways_paypal_args, $gateways_paypal_check ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 12.99 ), 3 );
		$t::flat_shipping( 6, true );
		$gateways_paypal_use();
		$t::calculate();
		// 12.99 / 1.2 = 10.83 + 2.16 tax, x3 = 32.49 + 6.48 tax. Shipping 6 = 5.00 + 1.00 tax.
		// 32.49 + 5.00 + 7.48 tax = 44.97.
		$gateways_paypal_check(
			$gateways_paypal_args(),
			array(
				'amount'     => 44.97,
				'item_total' => 32.49,
				'shipping'   => 5,
				'tax_total'  => 7.48,
				'discount'   => 0,
			)
		);
	}
);

// Store credit pays part of the order. Tax stays on the full price.
$t::test(
	'PayPal: store credit',
	function () use ( $t, $gateways_paypal_use, $gateways_paypal_args, $gateways_paypal_check, $gateways_paypal_order_matches ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$gateways_paypal_use();
		$t::calculate();
		// 100 + 10 tax - 20 credit = 90.
		$args = $gateways_paypal_args();
		$gateways_paypal_check(
			$args,
			array(
				'amount'     => 90,
				'item_total' => 100,
				'tax_total'  => 10,
				'discount'   => 20,
			)
		);
		$gateways_paypal_order_matches( $args );
	}
);

$t::test(
	'PayPal: discount code with taxed shipping',
	function () use ( $t, $gateways_paypal_use, $gateways_paypal_args, $gateways_paypal_check ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$t::discount( 'percent-total', 20 );
		$gateways_paypal_use();
		$t::calculate();
		// 100 - 20 = 80, + 10 shipping = 90 taxed, tax 9. 80 + 10 + 9 = 99.
		$gateways_paypal_check(
			$gateways_paypal_args(),
			array(
				'amount'     => 99,
				'item_total' => 100,
				'shipping'   => 10,
				'tax_total'  => 9,
				'discount'   => 20,
			)
		);
	}
);

$t::test(
	'PayPal: discount that includes shipping',
	function () use ( $t, $gateways_paypal_use, $gateways_paypal_args, $gateways_paypal_check ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, false );
		$t::discount( 'percent-total', 10, array( 'apply_to_shipping' => 1 ) );
		$gateways_paypal_use();
		$t::calculate();
		// 10% of 110 = 11 off. Tax on 90 = 9. 100 + 10 + 9 - 11 = 108.
		$gateways_paypal_check(
			$gateways_paypal_args(),
			array(
				'amount'     => 108,
				'item_total' => 100,
				'shipping'   => 10,
				'tax_total'  => 9,
				'discount'   => 11,
			)
		);
	}
);

// A quantity discount lowers the line, not the unit price PayPal is sent, so it has to end up
// in PayPal's discount.
$t::test(
	'PayPal: quantity discount on a line',
	function () use ( $t, $gateways_paypal_use, $gateways_paypal_args, $gateways_paypal_check ) {
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
		$t::tax( 8 );
		$t::add( $t::product( 3.33, array( 'qty_discount' => $rule ) ), 7 );
		$gateways_paypal_use();
		$cart = $t::calculate();
		// 7 x 3.33 = 23.31, 10% off = 2.331, line 20.979 (20.98). Tax 8% of 20.979 = 1.68.
		// 20.98 + 1.68 = 22.66. PayPal: items 23.31 + tax 1.68 - discount 2.33 = 22.66.
		$t::money( 22.66, $cart->get_total(), 'cart total' );
		$gateways_paypal_check(
			$gateways_paypal_args(),
			array(
				'amount'     => 22.66,
				'item_total' => 23.31,
				'tax_total'  => 1.68,
				'discount'   => 2.33,
			)
		);
	}
);

// Yen has no decimals. PayPal refuses a yen amount with decimals.
$t::test(
	'PayPal: yen amounts are whole yen',
	function () use ( $t, $gateways_paypal_use, $gateways_paypal_args, $gateways_paypal_check ) {
		$t::option( 'currency', 'JPY' );
		$t::tax( 10 );
		$t::add( $t::product( 1000 ), 2 );
		$gateways_paypal_use();
		$t::calculate();
		$args = $gateways_paypal_args();
		$t::same( 'JPY', $args['purchase_units'][0]['amount']['currency_code'], 'currency' );
		// 2000 + 200 tax = 2200.
		$gateways_paypal_check(
			$args,
			array(
				'amount'     => 2200,
				'item_total' => 2000,
				'tax_total'  => 200,
				'discount'   => 0,
			),
			0
		);
	}
);

// 10% of 1005 yen is 100.5 yen, which can't be paid. The store has to settle on whole yen, and
// PayPal must be sent whole yen that still add up to the order total. Which way the half yen
// goes (1105 or 1106) is left open here.
$t::test(
	'PayPal: yen tax of half a yen',
	function () use ( $t, $gateways_paypal_use, $gateways_paypal_args, $gateways_paypal_check ) {
		$t::option( 'currency', 'JPY' );
		$t::tax( 10 );
		$t::add( $t::product( 1005 ) );
		$gateways_paypal_use();
		$cart  = $t::calculate();
		$total = (float) $cart->get_total();
		$t::same( true, abs( $total - round( $total ) ) < 0.001, 'cart total is whole yen (got ' . $total . ')' );
		$args = $gateways_paypal_args();
		$gateways_paypal_check( $args, array( 'amount' => $total ), 0 );
	}
);
