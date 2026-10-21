<?php
/**
 * The step that finishes a paid order (SPC_Cart::post_process_order), run the way every payment
 * gateway's message runs it: from the gateway's server, with nobody logged in and an empty
 * session. The customer who placed the order is saved on it, so the order must be finished for
 * that customer exactly as if they were still at checkout.
 *
 * Orders here use the built-in Test payment method, so the only thing under test is the
 * finishing step. The real gateway messages are in order-finish-guest-webhooks.php.
 */

$t = 'SPC_Totals_Test';

// Finish the order the way a gateway's message does: nobody logged in, empty session.
$finish_guest_orders_by_message = function ( $order_id ) use ( $finish_guest_run ) {
	$finish_guest_run(
		'finishing step with nobody logged in',
		$order_id,
		function () use ( $order_id ) {
			SPC()->cart->post_process_order( sunshine_get_order( $order_id ) );
		}
	);
};

// Store credit is part of what the customer paid with, the same as a gift card. Once the order
// is paid it must come off their account, or they can spend it again on the next order.
$t::test(
	'Finished with nobody logged in: store credit comes off the customer account',
	function () use ( $t, $finish_guest_shopper, $finish_guest_seen, $finish_guest_place, $finish_guest_orders_by_message, $finish_guest_finished ) {
		$customer = $finish_guest_shopper( 30 );
		$t::add( $t::product( 80, array( 'taxable' => false ) ) );
		$t::checkout_address();
		$cart = $t::calculate();
		// 80 - 30 store credit = 50 left to pay.
		$t::money( 30, $cart->get_credits_applied(), 'store credit used at checkout' );
		$t::money( 50, $cart->get_total(), 'left to pay' );
		$seen  = $finish_guest_seen();
		$order = $finish_guest_place( 'test', $seen );
		$finish_guest_orders_by_message( $order->get_id() );
		$finish_guest_finished(
			$order->get_id(),
			$seen,
			array(
				'customer'     => $customer,
				'credits_left' => 0,
			)
		);
	}
);

// Taxed items and taxed shipping, with the credit covering part of it. The credit that comes off
// is what the order used, not a rounded or re-worked amount.
$t::test(
	'Finished with nobody logged in: store credit with tax and shipping',
	function () use ( $t, $finish_guest_shopper, $finish_guest_seen, $finish_guest_place, $finish_guest_orders_by_message, $finish_guest_credits_left ) {
		$customer = $finish_guest_shopper( 40 );
		$t::tax( 8 );
		$t::add( $t::product( 25 ), 2 );
		$t::flat_shipping( 10, true );
		$t::checkout_address();
		$cart = $t::calculate();
		// 50 + 4 tax + 10 shipping + 0.80 shipping tax = 64.80. 40 credit, 24.80 left to pay.
		$t::money( 64.80, $cart->get_total( array( 'credits' ) ), 'order before credit' );
		$t::money( 40, $cart->get_credits_applied(), 'store credit used at checkout' );
		$t::money( 24.80, $cart->get_total(), 'left to pay' );
		$seen  = $finish_guest_seen();
		$order = $finish_guest_place( 'test', $seen );
		$finish_guest_orders_by_message( $order->get_id() );
		$order = sunshine_get_order( $order->get_id() );
		$t::same( 'new', $order->get_status(), 'order is marked paid (New)' );
		$t::money( 40, $order->get_credits(), 'credit on the order' );
		$t::money( 0, $finish_guest_credits_left( $customer ), 'store credit left on the customer account' );
	}
);

// "Max uses per person" is checked against the logged in customer. The use has to be counted for
// the customer on the order even when nobody is logged in when it is finished.
$t::test(
	'Finished with nobody logged in: a once-per-customer code counts for that customer',
	function () use ( $t, $finish_guest_shopper, $finish_guest_code, $finish_guest_enter, $finish_guest_seen, $finish_guest_place, $finish_guest_orders_by_message, $finish_guest_finished ) {
		$customer          = $finish_guest_shopper( 0 );
		$product           = $t::product( 50, array( 'taxable' => false ) );
		list( $id, $code ) = $finish_guest_code();
		$t::add( $product );
		$t::calculate();
		$t::same( true, $finish_guest_enter( $code ), 'code accepted at checkout' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 10% off 50.
		$t::money( 5, $cart->get_discount(), 'discount' );
		$t::money( 45, $cart->get_total(), 'left to pay' );
		$seen  = $finish_guest_seen();
		$order = $finish_guest_place( 'test', $seen );
		$finish_guest_orders_by_message( $order->get_id() );
		$finish_guest_finished(
			$order->get_id(),
			$seen,
			array(
				'customer'     => $customer,
				'credits_left' => 0,
				'code'         => array( $id, $code, $product ),
			)
		);
	}
);

// "Max uses" counts every customer, logged in or not, so this one works either way.
$t::test(
	'Finished with nobody logged in: a code with one use left is used up',
	function () use ( $t, $finish_guest_shopper, $finish_guest_code, $finish_guest_enter, $finish_guest_seen, $finish_guest_place, $finish_guest_orders_by_message, $finish_guest_uses ) {
		$finish_guest_shopper( 0 );
		$product           = $t::product( 40, array( 'taxable' => false ) );
		list( $id, $code ) = $finish_guest_code(
			array(
				'max_uses'            => '1',
				'max_uses_per_person' => '',
			)
		);
		$t::add( $product );
		$t::calculate();
		$t::same( true, $finish_guest_enter( $code ), 'code accepted at checkout' );
		$t::checkout_address();
		$t::calculate();
		$order = $finish_guest_place( 'test', $finish_guest_seen() );
		$finish_guest_orders_by_message( $order->get_id() );
		$t::same( 1, $finish_guest_uses( $id ), 'use counted' );
		// Another customer tries the code.
		$t::customer();
		$t::add( $product );
		$t::calculate();
		$t::same( false, $finish_guest_enter( $code ), 'next customer is refused' );
	}
);

// Gateways send the same message again when the site is slow to answer. The second one must not
// count anything a second time.
$t::test(
	'Payment message sent twice: the code is counted once',
	function () use ( $t, $finish_guest_shopper, $finish_guest_code, $finish_guest_enter, $finish_guest_seen, $finish_guest_place, $finish_guest_orders_by_message, $finish_guest_uses, $finish_guest_credits_left ) {
		$customer          = $finish_guest_shopper( 20 );
		$product           = $t::product( 60, array( 'taxable' => false ) );
		list( $id, $code ) = $finish_guest_code( array( 'max_uses_per_person' => '' ) );
		$t::add( $product );
		$t::calculate();
		$t::same( true, $finish_guest_enter( $code ), 'code accepted at checkout' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 60 - 6 discount - 20 credit = 34.
		$t::money( 34, $cart->get_total(), 'left to pay' );
		$order = $finish_guest_place( 'test', $finish_guest_seen() );
		$finish_guest_orders_by_message( $order->get_id() );
		$finish_guest_orders_by_message( $order->get_id() );
		$t::same( 1, $finish_guest_uses( $id ), 'code counted once' );
		$t::same( 'new', sunshine_get_order( $order->get_id() )->get_status(), 'order is marked paid (New)' );
		$t::money( 0, $finish_guest_credits_left( $customer ), 'store credit left on the customer account' );
	}
);

// The customer came back to the site, so checkout finished the order while they were logged in.
// The gateway's message arrives afterwards and must leave the order alone.
$t::test(
	'Checkout finished the order, then the payment message arrives: nothing is counted twice',
	function () use ( $t, $finish_guest_shopper, $finish_guest_code, $finish_guest_enter, $finish_guest_seen, $finish_guest_place, $finish_guest_run, $finish_guest_orders_by_message, $finish_guest_uses, $finish_guest_credits_left ) {
		$customer          = $finish_guest_shopper( 20 );
		$product           = $t::product( 60, array( 'taxable' => false ) );
		list( $id, $code ) = $finish_guest_code();
		$t::add( $product );
		$t::calculate();
		$t::same( true, $finish_guest_enter( $code ), 'code accepted at checkout' );
		$t::checkout_address();
		$t::calculate();
		$order = $finish_guest_place( 'test', $finish_guest_seen() );
		// Checkout's own finishing step, with the customer still logged in.
		$finish_guest_run(
			'checkout finishing step',
			$order->get_id(),
			function () use ( $order ) {
				SPC()->cart->post_process_order( sunshine_get_order( $order->get_id() ) );
			},
			array( 'guest' => false )
		);
		$t::money( 0, $finish_guest_credits_left( $customer ), 'store credit used up by checkout' );
		// The photographer adds 15 of credit before the gateway's message arrives.
		( new SPC_Customer( $customer ) )->set_credits( '15' );
		$finish_guest_orders_by_message( $order->get_id() );
		$t::same( 1, $finish_guest_uses( $id ), 'code counted once' );
		$used_by = array_map( 'strval', (array) get_post_meta( $id, 'used_by' ) );
		$t::same( 1, count( array_keys( $used_by, (string) $customer, true ) ), 'counted once for this customer' );
		$t::money( 15, $finish_guest_credits_left( $customer ), 'the new credit is not touched' );
	}
);

$finish_guest_tidy();
