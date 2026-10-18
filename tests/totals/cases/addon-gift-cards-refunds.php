<?php
/**
 * Gift Cards add-on: what a gift card gets back when the order it paid for is refunded.
 *
 * Rule: the money paid by other means (the order total, e.g. charged in Stripe) is refunded
 * first. The card only gets its part back once that is fully refunded, less anything refunded
 * on top of it. A partial refund gives the card nothing. Some payment methods record the refund
 * before setting the order to Refunded and some after, so both orders are checked.
 *
 * Every order here: 100 + 10 tax = 110. A 25.00 card pays 25, 85 is paid by other means.
 */

$t = 'SPC_Totals_Test';

$gift_card_refunds_order = function () use ( $t ) {
	$t::require_plugin( 'sunshine_gift_cards_sync_order_balances', 'Gift Cards add-on' );
	$t::tax( 10 );
	$t::add( $t::product( 100 ) );
	$card_id = $t::gift_card( 25 );
	$t::calculate();
	$order = $t::place_order();
	$t::money( 85, $order->get_total(), 'paid by other means' );
	sunshine_gift_cards_store_order_meta( $order, array() );
	sunshine_gift_cards_deduct_balance( $order, array() );
	$order = sunshine_get_order( $order->get_id() );
	$t::money( 0, sunshine_get_gift_card( $card_id )->get_balance(), 'card used up' );
	return array( $order, $card_id );
};

// Status changes with no emails sent.
$gift_card_refunds_quiet = function ( $body ) {
	add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
	add_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
	try {
		$body();
	} finally {
		remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
		remove_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
	}
};

$gift_card_refunds_balance = function ( $card_id ) {
	return (float) get_post_meta( $card_id, 'balance', true );
};

$t::test(
	'Gift card refunds: a partial refund recorded before the order is set to Refunded leaves the card alone',
	function () use ( $t, $gift_card_refunds_order, $gift_card_refunds_quiet, $gift_card_refunds_balance ) {
		list( $order, $card_id ) = $gift_card_refunds_order();
		$gift_card_refunds_quiet(
			function () use ( $order ) {
				$order->set_status( 'new' );
				$order->add_refund( 10 );
				$order->set_status( 'refunded' );
			}
		);
		$t::money( 0, $gift_card_refunds_balance( $card_id ), 'card stays used up' );
		$t::same( 1, count( sunshine_get_gift_card( $card_id )->get_usage_log() ), 'card history still shows the order' );
	}
);

$t::test(
	'Gift card refunds: refunding the rest later gives the card back once',
	function () use ( $t, $gift_card_refunds_order, $gift_card_refunds_quiet, $gift_card_refunds_balance ) {
		list( $order, $card_id ) = $gift_card_refunds_order();
		$gift_card_refunds_quiet(
			function () use ( $order, $t, $card_id, $gift_card_refunds_balance ) {
				$order->set_status( 'new' );
				// Set to Refunded first, then the amount recorded, the way Stripe does it.
				$order->set_status( 'refunded' );
				$order->add_refund( 10 );
				$t::money( 0, $gift_card_refunds_balance( $card_id ), 'after the 10.00 refund the card stays used up' );
				$order->add_refund( 75 );
				$order->set_status( 'refunded' );
			}
		);
		$t::money( 25, $gift_card_refunds_balance( $card_id ), 'all 85 refunded: card is back to 25.00, once' );
		$t::same( array(), sunshine_get_gift_card( $card_id )->get_usage_log(), 'the order is gone from the card history' );
	}
);

// 90 refunded: the 85 paid plus 5 of the card's value went back as money, so the card gets 20.
$t::test(
	'Gift card refunds: money refunded beyond what was paid comes off what the card gets back',
	function () use ( $t, $gift_card_refunds_order, $gift_card_refunds_quiet, $gift_card_refunds_balance ) {
		list( $order, $card_id ) = $gift_card_refunds_order();
		$gift_card_refunds_quiet(
			function () use ( $order ) {
				$order->set_status( 'new' );
				$order->add_refund( 90 );
				$order->set_status( 'refunded' );
			}
		);
		$t::money( 20, $gift_card_refunds_balance( $card_id ), 'card gets 25 - 5' );
		$log = sunshine_get_gift_card( $card_id )->get_usage_log();
		$t::money( 5, isset( $log[0]['amount'] ) ? $log[0]['amount'] : 0, 'card history shows 5.00 still used on the order' );
	}
);

// Refunded by mistake and set back to Shipped: the card pays for the order again, once.
$t::test(
	'Gift card refunds: an order set back from Refunded takes the card again',
	function () use ( $t, $gift_card_refunds_order, $gift_card_refunds_quiet, $gift_card_refunds_balance ) {
		list( $order, $card_id ) = $gift_card_refunds_order();
		$gift_card_refunds_quiet(
			function () use ( $order ) {
				$order->set_status( 'new' );
				$order->set_status( 'refunded' );
				$order->set_status( 'shipped' );
				$order->set_status( 'shipped' );
			}
		);
		$t::money( 0, $gift_card_refunds_balance( $card_id ), 'card used up again' );
		$t::same( 1, count( sunshine_get_gift_card( $card_id )->get_usage_log() ), 'card history has one use' );
	}
);

// Codes are matched without regard to capitals. Taking the card off with the code typed another
// way takes off every spelling that is in the checkout.
$t::test(
	'Gift card refunds: a card in the session under two spellings pays once and comes off together',
	function () use ( $t ) {
		$t::require_plugin( 'sunshine_cart_remove_gift_card', 'Gift Cards add-on' );
		$t::add( $t::product( 200, array( 'taxable' => false ) ) );
		$card_id = $t::gift_card( 50 );
		$code    = get_post_meta( $card_id, 'code', true );
		// A session saved before the fix can hold the same card twice.
		SPC()->session->set( 'gift_cards', array( $code, strtolower( $code ) ) );
		$cart = $t::calculate();
		$t::money( 150, $cart->get_total(), 'card pays 50 once' );
		$t::same( true, sunshine_cart_remove_gift_card( strtolower( $code ) ), 'x button takes it off' );
		$cart = $t::calculate();
		$t::money( 200, $cart->get_total(), 'full price again' );
	}
);
