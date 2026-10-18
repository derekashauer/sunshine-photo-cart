<?php
/**
 * Finishing an order at checkout: SPC_Cart::process_order() end to end. It takes the payment
 * (the Test payment method here, or Free when nothing is left to pay), marks the order paid,
 * takes store credit off and counts the discount code, then fires sunshine_checkout_create_order
 * so the add-ons take gift card balances off, make the gift cards that were bought, use up
 * included session prints and lower Sell Anything stock.
 *
 * Helpers are in order-finish-paths-helpers.php.
 */

$t = 'SPC_Totals_Test';

// Place the order and run the checkout's Place Order step on it. Returns the order as saved.
$order_finish_checkout = function ( $payment_method = 'test' ) use ( $t ) {
	$t::checkout_address();
	$t::calculate();
	$order = $t::place_order( $payment_method );
	$done  = SPC()->cart->process_order();
	$t::same( true, ! empty( $done ), 'checkout finished the order: ' . wp_json_encode( SPC()->cart->get_errors() ) );
	return sunshine_get_order( $order->get_id() );
};

$order_finish_test(
	'Finish at checkout: everything happens once the order is paid (Test payment)',
	function () use ( $t, $order_finish_everything_cart, $order_finish_place_everything, $order_finish_check_everything ) {
		$ids   = $order_finish_everything_cart();
		$order = $order_finish_place_everything( 'test' );
		$done  = SPC()->cart->process_order();
		$t::same( true, ! empty( $done ), 'checkout finished the order' );
		$order_finish_check_everything( $order->get_id(), $ids, 'Test payment' );
		// The cart is emptied once the order is finished.
		$t::same( true, SPC()->cart->is_empty(), 'cart is empty after the order' );
		$t::same( array(), array_values( array_filter( (array) SPC()->session->get( 'gift_cards' ) ) ), 'gift card code is cleared from the cart' );
	}
);

// Three 19.99 prints, 7.25% tax and 8.95 taxed shipping: 59.97 + 4.35 + 8.95 + 0.65 = 73.92.
// A 50.00 card pays 50.00 of it and 23.92 is paid by card.
$order_finish_test(
	'Finish at checkout: gift card pays part, to the cent',
	function () use ( $t, $order_finish_checkout, $order_finish_card_balance ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::tax( 7.25 );
		$t::add( $t::product( 19.99 ), 3 );
		$t::flat_shipping( 8.95, true );
		$card  = $t::gift_card( 50 );
		$order = $order_finish_checkout();
		$t::money( 23.92, $order->get_total(), 'left to pay' );
		$t::money( 0, $order_finish_card_balance( $card ), 'card used up' );
		$amounts = (array) $order->get_meta_value( 'gift_card_amounts' );
		$t::money( 50, isset( $amounts[ $card ] ) ? $amounts[ $card ] : 0, 'order keeps how much the card paid' );
		$log = sunshine_get_gift_card( $card )->get_usage_log();
		$t::same( 1, count( $log ), 'card history has one use' );
		$t::money( 50, isset( $log[0]['amount'] ) ? $log[0]['amount'] : 0, 'card history shows 50.00 used' );
	}
);

// Same 73.92 order with a 100.00 card: the card pays it all and keeps 26.08.
$order_finish_test(
	'Finish at checkout: gift card pays the whole order and keeps the rest',
	function () use ( $t, $order_finish_checkout, $order_finish_card_balance ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::tax( 7.25 );
		$t::add( $t::product( 19.99 ), 3 );
		$t::flat_shipping( 8.95, true );
		$card  = $t::gift_card( 100 );
		$order = $order_finish_checkout( 'free' );
		$t::money( 0, $order->get_total(), 'nothing left to pay' );
		$t::same( true, $order->is_paid(), 'order is paid' );
		$t::money( 26.08, $order_finish_card_balance( $card ), 'card keeps 100.00 - 73.92' );
		$t::same( 'gift-card', $order->get_payment_method(), 'order says a gift card paid for it' );
		$t::same( array( $card ), array_map( 'intval', (array) $order->get_meta_value( 'gift_card_ids' ) ), 'order keeps which card paid' );
	}
);

// 100 + 10% tax = 110. Two cards: 30.00 then 50.00 both used up, 30.00 left to pay.
$order_finish_test(
	'Finish at checkout: two gift cards',
	function () use ( $t, $order_finish_checkout, $order_finish_card_balance ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$first  = $t::gift_card( 30 );
		$second = $t::gift_card( 50 );
		$order  = $order_finish_checkout();
		$t::money( 30, $order->get_total(), 'left to pay (110 - 30 - 50)' );
		$t::money( 0, $order_finish_card_balance( $first ), 'first card used up' );
		$t::money( 0, $order_finish_card_balance( $second ), 'second card used up' );
		$ids = array_map( 'intval', (array) $order->get_meta_value( 'gift_card_ids' ) );
		sort( $ids );
		$t::same( array( $first, $second ), $ids, 'order keeps both cards' );
	}
);

// 100 + 10 tax = 110, 20% off after tax = 88. A 50.00 card pays 50, 38.00 left.
$order_finish_test(
	'Finish at checkout: gift card with a discount after tax',
	function () use ( $t, $order_finish_checkout, $order_finish_card_balance ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$card  = $t::gift_card( 50 );
		$order = $order_finish_checkout();
		$t::money( 38, $order->get_total(), 'left to pay' );
		$t::money( 0, $order_finish_card_balance( $card ), 'card used up' );
	}
);

// 110 with 10% tax inside the price, 10% off = 99. A 200.00 card pays 99 and keeps 101.
$order_finish_test(
	'Finish at checkout: gift card when prices include tax',
	function () use ( $t, $order_finish_checkout, $order_finish_card_balance ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$card  = $t::gift_card( 200 );
		$order = $order_finish_checkout( 'free' );
		$t::money( 0, $order->get_total(), 'nothing left to pay' );
		$t::money( 101, $order_finish_card_balance( $card ), 'card keeps 200 - 99' );
	}
);

// Store credit is whole dollars here (credits with cents are a known separate bug).
// 50.00 credit on a 30.00 order: 30 used, 20 left on the account.
$order_finish_test(
	'Finish at checkout: store credit comes off the customer who placed the order',
	function () use ( $t, $order_finish_checkout ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', '50' );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::add( $t::product( 30, array( 'taxable' => false ) ) );
		$order = $order_finish_checkout( 'free' );
		$t::money( 30, $order->get_credits(), 'credit used on the order' );
		$t::money( 20, ( new SPC_Customer( $customer_id ) )->get_credits(), 'credit left (50 - 30)' );
	}
);

// The payment fee is a percent of what is left to pay after the gift card and the credit:
// 100 - 30 card - 20 credit = 50, plus 3% of 50 = 1.50, so 51.50 is charged. The card and the
// credit are taken off in full, the fee doesn't change what they pay.
$order_finish_test(
	'Finish at checkout: gift card, store credit and a percent payment fee',
	function () use ( $t, $order_finish_checkout, $order_finish_card_balance ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', '20' );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$card = $t::gift_card( 30 );
		$t::option( 'test_fee', 'percent' );
		$t::option( 'test_fee_amount', '3' );
		$t::option( 'test_fee_name', 'Card fee' );
		$order = $order_finish_checkout();
		$t::money( 51.50, $order->get_total(), 'charged (50 + 1.50 fee)' );
		$t::money( 0, $order_finish_card_balance( $card ), 'card used up' );
		$t::money( 0, ( new SPC_Customer( $customer_id ) )->get_credits(), 'credit used up' );
	}
);

// A code allowed once in all: after one finished order nobody can use it again.
$order_finish_test(
	'Finish at checkout: a code allowed once is used up after the order',
	function () use ( $t, $order_finish_checkout ) {
		$t::add( $t::product( 40, array( 'taxable' => false ) ) );
		$code  = $t::discount( 'amount-total', 5, array( 'max_uses' => '1' ) );
		$order = $order_finish_checkout();
		$t::money( 35, $order->get_total(), 'order total (40 - 5)' );
		$discount = sunshine_get_discount_by_code( $code );
		$t::same( 1, $discount->get_use_count(), 'code used once' );
		$t::same( false, (bool) $discount->is_valid(), 'code can not be used again' );
	}
);

// 3 free prints left, 2 bought: both free, 1 still left for later.
$order_finish_test(
	'Finish at checkout: included session prints are used up only as far as they were used',
	function () use ( $t, $order_finish_checkout, $order_finish_give_included, $order_finish_included_left ) {
		$t::require_plugin( 'sunshine_session_fees_checkout_create_order', 'Session Fees add-on' );
		$customer_id = $t::customer();
		$print       = $t::product( 12, array( 'taxable' => false ) );
		$order_finish_give_included( $customer_id, $print, 3 );
		$t::add( $print, 2 );
		$t::flat_shipping( 5 );
		$order = $order_finish_checkout();
		$t::money( 5, $order->get_total(), 'only shipping to pay' );
		$t::same( 1, (int) $order_finish_included_left( $customer_id, $print ), '1 free print left (3 - 2)' );
	}
);

// Gift card bought for 75.00 with a print: the order makes one 75.00 card for the recipient.
$order_finish_test(
	'Finish at checkout: a gift card bought becomes a card with that amount',
	function () use ( $t, $order_finish_checkout, $order_finish_gift_card_product, $order_finish_buy_gift_card, $order_finish_cards_made ) {
		$order_finish_buy_gift_card( $order_finish_gift_card_product(), 75 );
		$t::add( $t::product( 10, array( 'taxable' => false ) ) );
		$t::flat_shipping( 5 );
		$order = $order_finish_checkout();
		$t::money( 90, $order->get_total(), 'order total (75 + 10 + 5)' );
		$made = $order_finish_cards_made( $order->get_id() );
		$t::same( 1, count( $made ), 'one card made' );
		$t::money( 75, $made ? reset( $made ) : 0, 'new card has 75.00' );
		$card_id = $made ? key( $made ) : 0;
		$t::same( 'order-finish-gift@example.com', (string) get_post_meta( $card_id, 'recipient_email', true ), 'card goes to the recipient' );
	}
);

// Checkout and a late payment notice can both try to finish the same order, and the customer
// can press Place Order twice. Whatever runs again must not take anything off a second time.
// 2 Sell Anything products at 15.00 (5 in stock) and a 50.00 gift card bought = 80.00, paid by a
// 100.00 card that keeps 20.00.
$order_finish_test(
	'Finish at checkout: finishing the same order again takes nothing off twice',
	function () use ( $t, $order_finish_general, $order_finish_gift_card_product, $order_finish_buy_gift_card, $order_finish_cards_made, $order_finish_card_balance, $order_finish_as_server ) {
		$general = $order_finish_general( 15, 5 );
		$t::add( $general, 2 );
		$order_finish_buy_gift_card( $order_finish_gift_card_product(), 50 );
		$t::flat_shipping( 0 );
		$card = $t::gift_card( 100 );
		$code = $t::discount( 'amount-total', 0.01, array() );
		$t::checkout_address();
		$t::calculate();
		$order = $t::place_order( 'free' );
		$t::money( 0, $order->get_total(), 'nothing left to pay' );
		SPC()->cart->process_order();

		// A late notice from the payment service finishes it again.
		$order_finish_as_server(
			function () use ( $order ) {
				SPC()->cart->post_process_order( sunshine_get_order( $order->get_id() ) );
			}
		);
		// The customer pressed Place Order a second time with the same order still in the session.
		SPC()->session->set( 'checkout_order_id', $order->get_id() );
		SPC()->cart->process_order();

		$t::money( 20.01, $order_finish_card_balance( $card ), 'card taken off once (100 - 79.99)' );
		$t::same( 1, count( sunshine_get_gift_card( $card )->get_usage_log() ), 'card history has one use' );
		$t::same( 3, (int) get_post_meta( $general, 'stock', true ), 'stock lowered once (5 - 2)' );
		$t::same( 1, count( $order_finish_cards_made( $order->get_id() ) ), 'one gift card made' );
		$t::same( 1, sunshine_get_discount_by_code( $code )->get_use_count(), 'code counted once' );
	}
);
