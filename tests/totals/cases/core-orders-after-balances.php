<?php
/**
 * Gift card balances and store credits after an order is saved: what comes off when the
 * order is paid, and what comes back when it is refunded.
 *
 * Nothing leaves the site: web requests are caught with a made-up reply and emails are stopped.
 */

$t = 'SPC_Totals_Test';

// Run $body with every web request and email caught, and no order emails sent.
$orders_after_balances_offline = function ( $body ) {
	$http = function ( $pre, $args, $url ) {
		$reply = '{}';
		if ( false !== strpos( $url, 'stripe.com/v1/payment_intents/' ) ) {
			$reply = '{"id":"pi_offline","amount":100000}';
		} elseif ( false !== strpos( $url, 'stripe.com/v1/refunds' ) ) {
			$reply = '{"id":"re_offline","status":"succeeded"}';
		}
		return array(
			'headers'  => array(),
			'body'     => $reply,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
	add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
	add_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
	try {
		$body();
	} finally {
		remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
		remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
		remove_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
		unset( $_POST['stripe_refund_amount'] );
	}
};

// Once the order is paid, the Gift Cards add-on saves which cards paid for it and takes the
// amounts off the cards (both on sunshine_checkout_create_order). They are called directly here:
// the other add-ons on that hook (mailing lists, email queues) would reach outside services or
// leave rows behind on this shared test site.
$orders_after_balances_take_gift_cards = function ( $order ) {
	sunshine_gift_cards_store_order_meta( $order, array() );
	sunshine_gift_cards_deduct_balance( $order, array() );
	return sunshine_get_order( $order->get_id() );
};

$orders_after_balances_card = function ( $card_id ) {
	return sunshine_get_gift_card( $card_id )->get_balance();
};

$orders_after_balances_stripe_refund = function ( $order, $amount = '' ) use ( $t ) {
	$stripe = sunshine_get_payment_method_by_id( 'stripe' );
	if ( ! $stripe ) {
		$t::skip( 'Stripe payment method not available' );
	}
	$t::option( 'stripe_mode', 'test' );
	$t::option( 'stripe_secret_key_test', 'sk_test_offline' );
	$order->update_meta_value( 'payment_method', 'stripe' );
	$order->update_meta_value( 'stripe_payment_intent_id', 'pi_offline' );
	if ( '' !== $amount ) {
		$_POST['stripe_refund_amount'] = $amount;
	}
	$stripe->process_refund( $order->get_id() );
	return sunshine_get_order( $order->get_id() );
};

// Store credits are typed in dollars and cents on the customer's admin screen (the box steps by
// .01), the same way they are used at checkout. The customer has 20.00.
$orders_after_balances_credits = function ( $amount ) use ( $t ) {
	$customer_id = $t::customer();
	update_user_meta( $customer_id, 'sunshine_credits', (string) $amount );
	SPC()->customer = new SPC_Customer( $customer_id );
	SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
	return $customer_id;
};

$t::test(
	'Gift card: balance comes back when an order it paid for is refunded',
	function () use ( $t, $orders_after_balances_offline, $orders_after_balances_take_gift_cards, $orders_after_balances_card ) {
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$card_id = $t::gift_card( 80 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 0, $order->get_total(), 'gift card pays the whole order' );
		$order = $orders_after_balances_take_gift_cards( $order );
		$t::money( 30, $orders_after_balances_card( $card_id ), 'left on the card after the order (80 - 50)' );

		$orders_after_balances_offline(
			function () use ( $order ) {
				$order->set_status( 'new' );
				$order->set_status( 'refunded' );
				// Saving the order as Refunded a second time must not add the money again.
				sunshine_get_order( $order->get_id() )->set_status( 'refunded' );
			}
		);
		$t::money( 80, $orders_after_balances_card( $card_id ), 'card is back to 80, and only once' );
		$t::same( array(), sunshine_get_gift_card( $card_id )->get_usage_log(), 'the order is gone from the card history' );
	}
);

// 100 + 10 tax = 110. The card pays 25 and Stripe charges 85. A full refund gives both back.
$t::test(
	'Gift card: full refund in Stripe also gives back the gift card part',
	function () use ( $t, $orders_after_balances_offline, $orders_after_balances_take_gift_cards, $orders_after_balances_card, $orders_after_balances_stripe_refund ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$card_id = $t::gift_card( 25 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 85, $order->get_total(), 'charged to the card in Stripe' );
		$order = $orders_after_balances_take_gift_cards( $order );
		$t::money( 0, $orders_after_balances_card( $card_id ), 'card used up' );

		$orders_after_balances_offline(
			function () use ( &$order, $orders_after_balances_stripe_refund ) {
				$order->set_status( 'new' );
				$order = $orders_after_balances_stripe_refund( $order );
			}
		);
		$t::money( 85, $order->get_refund_total(), 'refunded in Stripe' );
		$t::money( 25, $orders_after_balances_card( $card_id ), 'gift card balance is back' );
	}
);

// Assumed rule (no doc covers partial refunds with a gift card): a partial refund sends that
// amount back through Stripe and nothing more. The gift card balance only comes back when the
// whole order is refunded. Today any refund marks the order Refunded, and the Gift Cards add-on
// gives the whole card amount back on that status.
$t::test(
	'Gift card: a partial refund in Stripe leaves the gift card alone',
	function () use ( $t, $orders_after_balances_offline, $orders_after_balances_take_gift_cards, $orders_after_balances_card, $orders_after_balances_stripe_refund ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$card_id = $t::gift_card( 25 );
		$t::calculate();
		$order = $t::place_order();
		$order = $orders_after_balances_take_gift_cards( $order );

		$orders_after_balances_offline(
			function () use ( &$order, $orders_after_balances_stripe_refund ) {
				$order->set_status( 'new' );
				$order = $orders_after_balances_stripe_refund( $order, '10.00' );
			}
		);
		$t::money( 10, $order->get_refund_total(), 'refunded in Stripe' );
		$t::money( 75, $order->get_total_minus_refunds(), 'still paid in Stripe (85 - 10)' );
		// The customer gets 10 back, not 10 plus the 25 they spent from the card.
		$t::money( 0, $orders_after_balances_card( $card_id ), 'gift card stays used up' );
	}
);

$t::test(
	'Credits: credits used on an order come off the balance to the cent',
	function () use ( $t, $orders_after_balances_offline, $orders_after_balances_credits ) {
		$customer_id = $orders_after_balances_credits( '20.00' );
		$t::add( $t::product( 7.50, array( 'taxable' => false ) ) );
		$cart = $t::calculate();
		$t::money( 7.50, $cart->get_credits_applied(), 'credits used at checkout' );
		$order = $t::place_order();
		$t::money( 7.50, $order->get_credits(), 'credits saved on the order' );

		// The step that runs once the order is paid takes the credits off the customer.
		$orders_after_balances_offline(
			function () use ( $order ) {
				SPC()->cart->post_process_order( $order );
			}
		);
		$customer = new SPC_Customer( $customer_id );
		$t::money( 12.50, $customer->get_credits(), 'credits left (20.00 - 7.50)' );
	}
);

// The customer admin screen saves what was typed with set_credits().
$t::test(
	'Credits: credits given by the photographer keep their cents',
	function () use ( $t ) {
		$customer_id = $t::customer();
		$customer    = new SPC_Customer( $customer_id );
		$customer->set_credits( sanitize_text_field( '12.50' ) );
		$customer = new SPC_Customer( $customer_id );
		$t::money( 12.50, $customer->get_credits(), 'credits on the account' );

		SPC()->customer = $customer;
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::add( $t::product( 30, array( 'taxable' => false ) ) );
		$cart = $t::calculate();
		$t::money( 12.50, $cart->get_credits_applied(), 'credits used at checkout' );
		$t::money( 17.50, $cart->get_total(), 'left to pay (30 - 12.50)' );
	}
);

// Assumed rule (the docs do not say): credits spent on an order come back when the order is
// refunded, the same way a gift card balance does, so the customer can use them again.
$t::test(
	'Credits: credits come back when the order is refunded',
	function () use ( $t, $orders_after_balances_offline, $orders_after_balances_credits ) {
		$customer_id = $orders_after_balances_credits( '20.00' );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::calculate();
		$order = $t::place_order();
		// 20 in credits, 30 left to pay.
		$t::money( 30, $order->get_total(), 'left to pay after credits' );
		$orders_after_balances_offline(
			function () use ( $order ) {
				SPC()->cart->post_process_order( $order );
			}
		);
		$t::money( 0, ( new SPC_Customer( $customer_id ) )->get_credits(), 'credits used up' );

		$orders_after_balances_offline(
			function () use ( $order ) {
				sunshine_get_order( $order->get_id() )->set_status( 'refunded' );
			}
		);
		$t::money( 20, ( new SPC_Customer( $customer_id ) )->get_credits(), 'credits back on the account' );
	}
);
