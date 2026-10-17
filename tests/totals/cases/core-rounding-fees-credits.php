<?php
/**
 * Rounding to the cent, payment method fees, and store credit.
 */

$t = 'SPC_Totals_Test';

$t::test(
	'Rounding: tax on a line is rounded to the cent',
	function () use ( $t ) {
		$t::tax( 7.25 );
		$t::add( $t::product( 19.99 ), 3 );
		$cart = $t::calculate();
		// 59.97 x 7.25% = 4.3478.
		$t::money( 4.35, $cart->get_tax(), 'tax' );
		$t::money( 64.32, $cart->get_total(), 'total' );
	}
);

// Each line's tax is rounded on its own, then added up.
$t::test(
	'Rounding: many small lines',
	function () use ( $t ) {
		$t::tax( 7.25 );
		$product_id = $t::product( 0.99 );
		for ( $i = 0; $i < 7; $i++ ) {
			$t::add( $product_id );
		}
		$cart = $t::calculate();
		$t::same( 7, count( $cart->get_cart_items() ), 'seven lines' );
		// 0.99 x 7.25% = 0.0718, so 0.07 a line.
		$t::money( 0.49, $cart->get_tax(), 'tax' );
		$t::money( 7.42, $cart->get_total(), 'total' );
	}
);

// When prices include tax, the customer pays exactly the prices they saw.
$t::test(
	'Rounding: prices include tax add up to the prices shown',
	function () use ( $t ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 12.99 ), 3 );
		$t::flat_shipping( 6, true );
		$cart = $t::calculate();
		$t::money( 44.97, $cart->get_total(), 'total' );
		$tax = (float) $cart->get_tax();
		$t::same( true, $tax > 7.45 && $tax < 7.55, 'tax is about 44.97 / 6 (got ' . $tax . ')' );
	}
);

$t::test(
	'Rounding: percent discount to the cent',
	function () use ( $t ) {
		$t::add( $t::product( 33.33, array( 'taxable' => false ) ) );
		$t::discount( 'percent-total', 15 );
		$cart = $t::calculate();
		$t::money( 5, $cart->get_discount(), 'discount (4.9995)' );
		$t::money( 28.33, $cart->get_total(), 'total' );
	}
);

$payment_fee = function ( $type, $amount ) use ( $t ) {
	$t::option( 'test_fee', $type );
	$t::option( 'test_fee_amount', (string) $amount );
	$t::option( 'test_fee_name', 'Card fee' );
	SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
};

$t::test(
	'Payment fee: fixed amount',
	function () use ( $t, $payment_fee ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$payment_fee( 'amount', 2 );
		$cart = $t::calculate();
		$t::money( 102, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Payment fee: percent of the order',
	function () use ( $t, $payment_fee ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$payment_fee( 'percent', 3 );
		$cart = $t::calculate();
		$t::money( 113.30, $cart->get_total(), '110 + 3% of 110' );
	}
);

$t::test(
	'Payment fee: percent after a discount',
	function () use ( $t, $payment_fee ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::discount( 'percent-total', 10 );
		$payment_fee( 'percent', 3 );
		$cart = $t::calculate();
		$t::money( 92.70, $cart->get_total(), '90 + 3% of 90' );
	}
);

// The fee is for taking the payment, so it is a percent of what is left to pay.
$t::test(
	'Payment fee: percent after a gift card',
	function () use ( $t, $payment_fee ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::gift_card( 30 );
		$payment_fee( 'percent', 3 );
		$cart = $t::calculate();
		$t::money( 72.10, $cart->get_total(), '70 + 3% of 70' );
	}
);

$credits = function ( $amount ) use ( $t ) {
	$customer_id = $t::customer();
	update_user_meta( $customer_id, 'sunshine_credits', $amount );
	SPC()->customer = new SPC_Customer( $customer_id );
	SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
};

$t::test(
	'Credits: part of the order',
	function () use ( $t, $credits ) {
		$credits( 20 );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$cart = $t::calculate();
		$t::money( 20, $cart->get_credits_applied(), 'credits used' );
		$t::money( 10, $cart->get_tax(), 'tax is on the full price' );
		$t::money( 90, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Credits: more than the order',
	function () use ( $t, $credits ) {
		$credits( 500 );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$cart = $t::calculate();
		$t::money( 110, $cart->get_credits_applied(), 'credits used' );
		$t::money( 0, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Credits: with a percent payment fee',
	function () use ( $t, $credits, $payment_fee ) {
		$credits( 20 );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$payment_fee( 'percent', 3 );
		$cart = $t::calculate();
		$t::money( 82.40, $cart->get_total(), '80 + 3% of 80' );
	}
);
