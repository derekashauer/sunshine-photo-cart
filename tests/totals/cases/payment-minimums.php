<?php
/**
 * Payment methods with a smallest amount they can take. An order under it is refused with a
 * reason that names the payment method and its minimum, both when the method is chosen and
 * when the order is submitted. Orders with nothing to pay are never refused.
 *
 * Stripe's own minimums are checked in core-gateways-stripe.php.
 */

$t = 'SPC_Totals_Test';

$t::test(
	'Payment minimums: no minimum unless the payment method has one',
	function () use ( $t ) {
		$test = sunshine_get_payment_method_by_id( 'test' );
		$t::same( 0.0, $test->get_minimum_amount(), 'the Test method has no minimum' );
		$t::same( '', $test->get_minimum_amount_error( 0.01 ), 'so a cent is fine' );
	}
);

$t::test(
	'Payment minimums: a site can set a minimum with the filter',
	function () use ( $t ) {
		$minimum = function ( $amount, $currency, $payment_method ) {
			return ( 'test' === $payment_method->get_id() && 'USD' === $currency ) ? 100 : $amount;
		};
		add_filter( 'sunshine_payment_method_minimum_amount', $minimum, 10, 3 );
		try {
			$t::add( $t::product( 50, array( 'taxable' => false ) ) );
			$t::checkout_address();
			$t::calculate();
			$error = SPC()->cart->get_payment_minimum_error( 'test', SPC()->cart->get_total() );
			$name  = sunshine_get_payment_method_by_id( 'test' )->get_name();
			$t::same( true, 0 === strpos( $error, $name . ' can\'t take payments under ' . sunshine_price( 100 ) ), 'the reason names the method and the minimum (got "' . wp_strip_all_tags( $error ) . '")' );

			SPC()->cart->process_section( 'payment', array( 'payment_method' => 'test' ) );
			$t::same( true, in_array( $error, SPC()->cart->get_errors(), true ), 'choosing it at the payment step is refused' );

			$placed = null;
			try {
				$placed = $t::place_order( 'test' );
			} catch ( Exception $e ) {
				$placed = false;
			}
			$t::same( false, $placed, 'submitting the order is refused' );
		} finally {
			remove_filter( 'sunshine_payment_method_minimum_amount', $minimum, 10 );
		}

		// Without the minimum the same cart can be ordered.
		SPC()->cart = new SPC_Cart();
		$t::calculate();
		$order = $t::place_order( 'test' );
		$t::money( 50, $order->get_total(), 'the order goes through once the minimum is gone' );
	}
);

$t::test(
	'Payment minimums: an order at the minimum is fine, and nothing to pay is never refused',
	function () use ( $t ) {
		$minimum = function ( $amount, $currency, $payment_method ) {
			return ( 'test' === $payment_method->get_id() ) ? 50 : $amount;
		};
		add_filter( 'sunshine_payment_method_minimum_amount', $minimum, 10, 3 );
		try {
			$test = sunshine_get_payment_method_by_id( 'test' );
			$t::same( '', $test->get_minimum_amount_error( 50 ), 'at the minimum' );
			$t::same( '', $test->get_minimum_amount_error( 50.01 ), 'over the minimum' );
			$t::same( true, '' !== $test->get_minimum_amount_error( 49.99 ), 'a cent under' );
			$t::same( '', $test->get_minimum_amount_error( 0 ), 'nothing to pay' );

			$t::add( $t::product( 50, array( 'taxable' => false ) ) );
			$t::calculate();
			$order = $t::place_order( 'test' );
			$t::money( 50, $order->get_total(), 'an order at the minimum goes through' );
		} finally {
			remove_filter( 'sunshine_payment_method_minimum_amount', $minimum, 10 );
		}
	}
);

$t::test(
	'Payment minimums: Payfast can\'t take under R5.00',
	function () use ( $t ) {
		$t::require_plugin( 'sunshine_payment_method_payfast', 'Payfast add-on' );
		$payfast = sunshine_get_payment_method_by_id( 'payfast' );
		if ( ! $payfast ) {
			$t::skip( 'Payfast is not a registered payment method' );
		}
		$t::option( 'currency', 'ZAR' );
		$t::same( 5.0, $payfast->get_minimum_amount(), 'R5.00 minimum in rand' );
		$t::same( true, '' !== $payfast->get_minimum_amount_error( 4 ), 'R4.00 is refused' );
		$t::same( '', $payfast->get_minimum_amount_error( 5 ), 'R5.00 is fine' );
		$t::same( true, false !== strpos( wp_strip_all_tags( SPC()->cart->get_payment_minimum_error( 'payfast', 4 ) ), 'can\'t take payments under' ), 'the checkout gets the reason' );
		$t::same( 0.0, $payfast->get_minimum_amount( 'USD' ), 'no minimum is guessed for other currencies' );
	}
);
