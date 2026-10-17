<?php
/**
 * Every discount code type, worked out by hand.
 */

$t = 'SPC_Totals_Test';

$t::test(
	'Discount: fixed amount off the order',
	function () use ( $t ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'amount-total', 15 );
		$cart = $t::calculate();
		$t::money( 15, $cart->get_discount(), 'discount' );
		$t::money( 8.50, $cart->get_tax(), 'tax on 85' );
		$t::money( 93.50, $cart->get_total(), 'total' );
	}
);

// A discount can't be worth more than what it applies to.
$t::test(
	'Discount: fixed amount bigger than the order',
	function () use ( $t ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'amount-total', 150 );
		$cart = $t::calculate();
		$t::money( 100, $cart->get_discount(), 'discount is capped at the order' );
		$t::money( 0, $cart->get_tax(), 'tax' );
		$t::money( 0, $cart->get_total(), 'total' );
	}
);

// $15 off a $100 order where half is taxed: half the discount comes off the taxed part.
$t::test(
	'Discount: fixed amount on taxed and untaxed items',
	function () use ( $t ) {
		$t::tax( 10 );
		$t::add( $t::product( 50 ) );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::discount( 'amount-total', 15 );
		$cart = $t::calculate();
		$t::money( 15, $cart->get_discount(), 'discount' );
		$t::money( 4.25, $cart->get_tax(), 'tax on 50 - 7.50' );
		$t::money( 89.25, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Discount: percent off chosen products',
	function () use ( $t ) {
		$t::tax( 10 );
		$a = $t::product( 50 );
		$b = $t::product( 50 );
		$t::add( $a );
		$t::add( $b );
		$t::discount( 'percent-product', 10, array( 'allowed_products' => array( $a ) ) );
		$cart = $t::calculate();
		$t::money( 5, $cart->get_discount(), 'discount' );
		$t::money( 9.50, $cart->get_tax(), 'tax on 95' );
		$t::money( 104.50, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Discount: amount off each chosen product',
	function () use ( $t ) {
		$t::tax( 10 );
		$a = $t::product( 10 );
		$b = $t::product( 10 );
		$t::add( $a, 3 );
		$t::add( $b );
		$t::discount( 'amount-product', 2, array( 'allowed_products' => array( $a ) ) );
		$cart = $t::calculate();
		$t::money( 6, $cart->get_discount(), 'discount' );
		$t::money( 3.40, $cart->get_tax(), 'tax on 34' );
		$t::money( 37.40, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Discount: limited to a number of each product',
	function () use ( $t ) {
		$a = $t::product( 10, array( 'taxable' => false ) );
		$t::add( $a, 5 );
		$t::discount( 'percent-product', 50, array( 'max_product_quantity' => 2 ) );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_discount(), 'half off 2 of the 5' );
		$t::money( 40, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Discount: two codes together',
	function () use ( $t ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::discount( 'percent-total', 10 );
		$t::discount( 'amount-total', 5 );
		$cart = $t::calculate();
		$t::money( 15, $cart->get_discount(), 'discount' );
		$t::money( 85, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Discount: taxed and untaxed items',
	function () use ( $t ) {
		$t::tax( 10 );
		$t::add( $t::product( 50 ) );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 4.50, $cart->get_tax(), 'tax on the taxed 45' );
		$t::money( 94.50, $cart->get_total(), 'total' );
	}
);

// The shipping part of the discount comes off shipping that isn't taxed, so it must not
// lower the tax.
$t::test(
	'Discount: includes shipping that is not taxed',
	function () use ( $t ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, false );
		$t::discount( 'percent-total', 10, array( 'apply_to_shipping' => 1 ) );
		$cart = $t::calculate();
		$t::money( 11, $cart->get_discount(), 'discount' );
		$t::money( 9, $cart->get_tax(), 'tax on 90' );
		$t::money( 108, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Discount: includes shipping that is taxed',
	function () use ( $t ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$t::discount( 'percent-total', 10, array( 'apply_to_shipping' => 1 ) );
		$cart = $t::calculate();
		$t::money( 11, $cart->get_discount(), 'discount' );
		$t::money( 9.90, $cart->get_tax(), 'tax on 99' );
		$t::money( 108.90, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Discount: prices include tax',
	function () use ( $t ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		$t::money( 11, $cart->get_discount(), 'discount' );
		$t::money( 9, $cart->get_tax(), 'tax included in 99' );
		$t::money( 99, $cart->get_total(), 'total' );
	}
);
