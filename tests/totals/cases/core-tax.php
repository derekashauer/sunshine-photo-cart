<?php
/**
 * Tax, discount codes and shipping in core, with no add-ons involved.
 */

$t = 'SPC_Totals_Test';

$t::test(
	'Taxable item, prices without tax',
	function () use ( $t ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_tax(), 'tax' );
		$t::money( 110, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Taxable item, prices include tax',
	function () use ( $t ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_tax(), 'tax' );
		$t::money( 110, $cart->get_total(), 'total' );
	}
);

// A discount lowers the amount that is taxed once. With taxable shipping it used to be
// taken off twice: once from the items' tax and again from the shipping's taxable amount,
// so the store collected too little tax.
$t::test(
	'Discount code with taxable shipping is taken off the taxed amount once',
	function () use ( $t ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$t::discount( 'percent-total', 20 );
		$cart = $t::calculate();
		$t::money( 20, $cart->get_discount(), 'discount' );
		$t::money( 10, $cart->get_shipping(), 'shipping' );
		// ( 100 - 20 + 10 ) x 10%.
		$t::money( 9, $cart->get_tax(), 'tax' );
		$t::money( 99, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Discount code with shipping that is not taxed',
	function () use ( $t ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, false );
		$t::discount( 'percent-total', 20 );
		$cart = $t::calculate();
		$t::money( 8, $cart->get_tax(), 'tax' );
		$t::money( 98, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Taxable shipping with no discount',
	function () use ( $t ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$cart = $t::calculate();
		$t::money( 11, $cart->get_tax(), 'tax' );
		$t::money( 121, $cart->get_total(), 'total' );
	}
);

// 0.20 x 5% is exactly one cent, which used to be shown and charged as no tax.
$t::test(
	'A one cent tax is kept',
	function () use ( $t ) {
		$t::tax( 5 );
		$t::add( $t::product( 0.20 ) );
		$cart = $t::calculate();
		$t::money( 0.01, $cart->get_tax(), 'tax' );
		$t::money( 0.21, $cart->get_total(), 'total' );
	}
);

// With "discount after tax" on, tax is worked out on the full price. Working the cart out a
// second time in the same request (as checkout does) used to reuse the discount from the first
// pass and lower the tax.
$t::test(
	'Discount after tax gives the same tax every time the cart is worked out',
	function () use ( $t ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_tax(), 'tax, first pass' );
		$t::money( 88, $cart->get_total(), 'total, first pass' );
		$cart->setup( true );
		$t::money( 10, $cart->get_tax(), 'tax, second pass' );
		$t::money( 88, $cart->get_total(), 'total, second pass' );
	}
);
