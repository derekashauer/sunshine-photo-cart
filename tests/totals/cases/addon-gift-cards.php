<?php
/**
 * Gift Cards add-on. A gift card pays for what is left after discounts, tax and shipping,
 * and never takes more from the card than the order costs.
 */

$t = 'SPC_Totals_Test';

$gift_card_applied = function () {
	return array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) );
};

$t::test(
	'Gift card covers part of an order',
	function () use ( $t, $gift_card_applied ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::gift_card( 50 );
		$cart = $t::calculate();
		$t::money( 50, $gift_card_applied(), 'taken from the card' );
		$t::money( 60, $cart->get_total(), 'left to pay' );
	}
);

// The card used to be sized before the discount code was worked out, so it paid for the
// full price and the customer's card lost the discounted amount.
$t::test(
	'Gift card with a discount code takes only what the order costs',
	function () use ( $t, $gift_card_applied ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::discount( 'percent-total', 20 );
		$t::gift_card( 200 );
		$cart = $t::calculate();
		$t::money( 20, $cart->get_discount(), 'discount' );
		$t::money( 80, $gift_card_applied(), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

$t::test(
	'Gift card with a discount code and tax',
	function () use ( $t, $gift_card_applied ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$t::gift_card( 200 );
		$cart = $t::calculate();
		// 100 - 20 discount + 8 tax.
		$t::money( 88, $gift_card_applied(), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

$t::test(
	'Gift card with a discount after tax',
	function () use ( $t, $gift_card_applied ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$t::gift_card( 200 );
		$cart = $t::calculate();
		// 100 + 10 tax - 22 discount.
		$t::money( 88, $gift_card_applied(), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

$t::test(
	'Gift card with shipping',
	function () use ( $t, $gift_card_applied ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$t::gift_card( 500 );
		$cart = $t::calculate();
		$t::money( 121, $gift_card_applied(), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

$t::test(
	'Gift card amount stays the same on the next page load',
	function () use ( $t, $gift_card_applied ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::discount( 'percent-total', 20 );
		$t::gift_card( 200 );
		$t::calculate();
		$cart = $t::new_request();
		$t::money( 80, $gift_card_applied(), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);
