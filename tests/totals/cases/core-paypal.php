<?php
/**
 * What is sent to PayPal when an order is created. Nothing reaches PayPal: the request is
 * caught before it leaves the site, so these tests check the numbers Sunshine sends, not
 * whether PayPal accepts them.
 */

$t = 'SPC_Totals_Test';

// Build the PayPal order for the current cart and return what would be sent.
$paypal_order_args = function () {
	$paypal = sunshine_get_payment_method_by_id( 'paypal' );
	if ( ! $paypal ) {
		SPC_Totals_Test::skip( 'PayPal payment method not available' );
	}

	$sent       = null;
	$capture    = function ( $args ) use ( &$sent ) {
		$sent = $args;
		return $args;
	};
	$block_http = function () {
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	};
	$ajax       = '__return_true';
	$die        = function () {
		return function () {
			throw new Exception( 'paypal create_order finished' );
		};
	};

	add_filter( 'sunshine_paypal_order_args', $capture, 999 );
	add_filter( 'pre_http_request', $block_http );
	add_filter( 'wp_doing_ajax', $ajax );
	add_filter( 'wp_die_ajax_handler', $die );
	$_REQUEST['security'] = wp_create_nonce( 'sunshine_checkout_paypal_create_order' );
	ob_start();
	try {
		$paypal->create_order();
	} catch ( Exception $e ) {
		// create_order() always ends by sending a JSON response.
	}
	ob_end_clean();
	unset( $_REQUEST['security'] );
	remove_filter( 'sunshine_paypal_order_args', $capture, 999 );
	remove_filter( 'pre_http_request', $block_http );
	remove_filter( 'wp_doing_ajax', $ajax );
	remove_filter( 'wp_die_ajax_handler', $die );

	if ( empty( $sent ) ) {
		throw new Exception( 'No PayPal order was built' );
	}
	return $sent;
};

// PayPal checks that amount = item_total + tax_total + shipping - discount, and items
// can't be below zero.
$check_paypal_breakdown = function ( $args, $expected_amount, $expected_discount ) use ( $t ) {
	$unit      = $args['purchase_units'][0];
	$breakdown = $unit['amount']['breakdown'];
	$negative  = array_filter(
		$unit['items'],
		function ( $item ) {
			return (float) $item['unit_amount']['value'] < 0;
		}
	);
	$t::same( 0, count( $negative ), 'no items below zero' );
	$t::money( $expected_amount, $unit['amount']['value'], 'amount charged' );
	$t::money( $expected_discount, $breakdown['discount']['value'], 'discount' );
	$sum = (float) $breakdown['item_total']['value'] + (float) $breakdown['tax_total']['value'] + (float) $breakdown['shipping']['value'] - (float) $breakdown['discount']['value'];
	$t::money( $unit['amount']['value'], $sum, 'breakdown adds up to the amount' );
};

$t::test(
	'PayPal: plain order',
	function () use ( $t, $paypal_order_args, $check_paypal_breakdown ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		$check_paypal_breakdown( $paypal_order_args(), 110, 0 );
	}
);

// A gift card is a fee below zero. It used to be sent as an item below zero, which PayPal
// does not allow.
$t::test(
	'PayPal: gift card is sent as part of the discount',
	function () use ( $t, $paypal_order_args, $check_paypal_breakdown ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::gift_card( 30 );
		$t::calculate();
		$check_paypal_breakdown( $paypal_order_args(), 80, 30 );
	}
);

$t::test(
	'PayPal: gift card and discount code together',
	function () use ( $t, $paypal_order_args, $check_paypal_breakdown ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$t::gift_card( 30 );
		$t::calculate();
		// 100 - 20 + 8 tax - 30 card.
		$check_paypal_breakdown( $paypal_order_args(), 58, 50 );
	}
);
