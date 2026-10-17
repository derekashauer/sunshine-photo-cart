<?php
/**
 * Stripe hosted checkout. These make real Checkout Sessions in Stripe test mode and compare
 * what Stripe will charge with the order total. Off unless SPC_TOTALS_STRIPE=1 is set, and only
 * ever with a test key (sk_test_). Each session is expired and its coupon deleted afterwards.
 *
 *   SPC_TOTALS_STRIPE=1 wp eval-file wp-content/plugins/sunshine-photo-cart/tests/totals/run.php
 */

$t = 'SPC_Totals_Test';

$stripe_session_total = function ( $order ) use ( $t ) {
	if ( ! getenv( 'SPC_TOTALS_STRIPE' ) ) {
		$t::skip( 'set SPC_TOTALS_STRIPE=1 to run against Stripe test mode' );
	}
	$stripe = sunshine_get_payment_method_by_id( 'stripe' );
	if ( ! $stripe || 0 !== strpos( (string) SPC()->get_option( 'stripe_secret_key_test' ), 'sk_test_' ) || 'test' !== SPC()->get_option( 'stripe_mode' ) ) {
		$t::skip( 'Stripe is not in test mode with a test key' );
	}
	$t::option( 'stripe_tax_mode', 'sunshine' );

	$create = new ReflectionMethod( $stripe, 'create_checkout_session' );
	$create->setAccessible( true );
	$session = $create->invoke( $stripe, $order );

	$request = new ReflectionMethod( $stripe, 'make_stripe_request' );
	$request->setAccessible( true );
	$coupon_id = 'sunshine_order_' . $order->get_id();
	$request->invoke( $stripe, 'coupons/' . $coupon_id, array(), 'DELETE' );

	if ( is_wp_error( $session ) ) {
		return 'Stripe refused it: ' . $session->get_error_message();
	}
	$request->invoke( $stripe, 'checkout/sessions/' . $session['id'] . '/expire', array(), 'POST' );
	return intval( $session['amount_total'] ) / 100;
};

$t::test(
	'Stripe hosted: taxed items and taxed shipping',
	function () use ( $t, $stripe_session_total ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$t::calculate();
		$order = $t::place_order();
		$t::money( $order->get_total(), $stripe_session_total( $order ), 'Stripe charges the order total (121)' );
	}
);

$t::test(
	'Stripe hosted: discount code',
	function () use ( $t, $stripe_session_total ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( $order->get_total(), $stripe_session_total( $order ), 'Stripe charges the order total (88)' );
	}
);

$t::test(
	'Stripe hosted: gift card pays part',
	function () use ( $t, $stripe_session_total ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::gift_card( 30 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( $order->get_total(), $stripe_session_total( $order ), 'Stripe charges the order total (80)' );
	}
);

$t::test(
	'Stripe hosted: discount code, gift card and shipping',
	function () use ( $t, $stripe_session_total ) {
		$t::tax( 10 );
		$t::add( $t::product( 50 ), 2 );
		$t::flat_shipping( 10, true );
		$t::discount( 'percent-total', 15 );
		$t::gift_card( 25 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( $order->get_total(), $stripe_session_total( $order ), 'Stripe charges the order total' );
	}
);

$t::test(
	'Stripe hosted: store credit',
	function () use ( $t, $stripe_session_total ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 90, $order->get_total(), 'order total' );
		$t::money( $order->get_total(), $stripe_session_total( $order ), 'Stripe charges the order total (90)' );
	}
);

$t::test(
	'Stripe hosted: discount after tax',
	function () use ( $t, $stripe_session_total ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( $order->get_total(), $stripe_session_total( $order ), 'Stripe charges the order total (88)' );
	}
);

$t::test(
	'Stripe hosted: prices include tax with a gift card',
	function () use ( $t, $stripe_session_total ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 12.99 ), 3 );
		$t::gift_card( 10 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( $order->get_total(), $stripe_session_total( $order ), 'Stripe charges the order total (28.97)' );
	}
);
