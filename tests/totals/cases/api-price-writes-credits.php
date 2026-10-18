<?php
/**
 * API add-on: a customer's store credits set through PUT /customers/{id} (and the
 * update-customer AI tool, which sends the same request).
 *
 * Store credit is money the customer can spend at checkout. The rules used here:
 * - Whatever amount the API saves and reports is the amount the customer can spend.
 * - Credits are never below zero.
 */

$t = 'SPC_Totals_Test';
if ( ! isset( $apw_test ) ) {
	require __DIR__ . '/api-price-writes-helpers.php';
}

$apw_test(
	'Credits: whole dollars set through the API pay for the order',
	function () use ( $t, $apw_credits_customer, $apw_rest ) {
		list( $customer_id, $status, $data ) = $apw_credits_customer( 30 );
		$t::same( 200, $status, 'update accepted' );
		$t::money( 30, isset( $data['credits'] ) ? $data['credits'] : null, 'credits the API reports' );

		$t::tax( 10 );
		$t::add( $t::product( 50 ) );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$cart = $t::calculate();
		// 50 + 5 tax = 55, less 30 credits = 25.
		$t::money( 30, $cart->get_credits_applied(), 'credits used' );
		$t::money( 25, $cart->get_total(), 'left to pay' );
		$order = $t::place_order();
		$t::money( 30, $order->get_credits(), 'credits on the order' );
		$t::money( 25, $order->get_total(), 'order total' );
	}
);

// The API saves 12.50 and reports 12.50 back, so a photographer (or AI helper) believes the
// customer has $12.50 to spend.
$apw_test(
	'Credits: cents set through the API can all be spent',
	function () use ( $t, $apw_credits_customer, $apw_rest ) {
		list( $customer_id, $status, $data ) = $apw_credits_customer( 12.50 );
		$t::same( 200, $status, 'update accepted' );
		$t::money( 12.50, isset( $data['credits'] ) ? $data['credits'] : null, 'credits the API reports' );
		$t::money( 12.50, SPC()->customer->get_credits(), 'credits the store gives the customer' );

		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$cart = $t::calculate();
		// 20 - 12.50 = 7.50.
		$t::money( 12.50, $cart->get_credits_applied(), 'credits used' );
		$t::money( 7.50, $cart->get_total(), 'left to pay' );
		$order = $t::place_order();
		$t::money( 12.50, $order->get_credits(), 'credits on the order' );
		$t::money( 7.50, $order->get_total(), 'order total' );
	}
);

$apw_test(
	'Credits: cents with prices including tax and a discount code',
	function () use ( $t, $apw_credits_customer ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$apw_credits_customer( 20.25 );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$cart = $t::calculate();
		// 110 with tax, 10% off = 99, less 20.25 credits = 78.75.
		$t::money( 11, $cart->get_discount(), 'discount' );
		$t::money( 20.25, $cart->get_credits_applied(), 'credits used' );
		$t::money( 78.75, $cart->get_total(), 'left to pay' );
	}
);

// The customer screen's credits box has a minimum of 0. A negative balance means the customer
// owes credit, which Sunshine has no way to collect or show.
$apw_test(
	'Credits: a negative amount is refused and never reaches an order',
	function () use ( $t, $apw_credits_customer, $apw_refused ) {
		list( $customer_id, $status, $data ) = $apw_credits_customer( -20 );
		$t::same( true, $apw_refused( $status ), 'refused as a bad request (got ' . $status . ')' );
		$t::money( 0, get_user_meta( $customer_id, 'sunshine_credits', true ), 'saved credits' );

		// If it was saved anyway, the checkout offers "Use my credits (-$20.00 available)".
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$cart = $t::calculate();
		$t::money( 100, $cart->get_total(), 'customer pays 100' );
		$order = $t::place_order();
		$t::money( 0, $order->get_credits(), 'credits on the order' );
		$t::money( 100, $order->get_total(), 'order total' );
	}
);

$apw_test(
	'Credits: text instead of a number is refused',
	function () use ( $t, $apw_credits_customer, $apw_rest, $apw_refused ) {
		list( $customer_id ) = $apw_credits_customer( 25 );
		list( $status )      = $apw_rest( 'PUT', '/customers/' . $customer_id, array( 'credits' => 'twenty' ) );
		$t::same( true, $apw_refused( $status ), 'refused as a bad request (got ' . $status . ')' );
		$t::money( 25, get_user_meta( $customer_id, 'sunshine_credits', true ), 'credits stay 25' );
	}
);

// The bulk update (the bulk-write-customers AI tool) skips the checks a single update gets.
$apw_test(
	'Credits: a bulk customer update with text credits does not wipe the credits',
	function () use ( $t, $apw_credits_customer, $apw_rest ) {
		list( $customer_id ) = $apw_credits_customer( 25 );
		$apw_rest(
			'PUT',
			'/customers/batch',
			array(
				'update' => array(
					array(
						'id'      => $customer_id,
						'credits' => 'twenty',
					),
				),
			)
		);
		$t::money( 25, get_user_meta( $customer_id, 'sunshine_credits', true ), 'credits stay 25' );
	}
);

foreach ( $apw_warnings as $apw_name => $apw_list ) {
	WP_CLI::log( 'WARN ' . $apw_name . ': ' . implode( ' | ', array_unique( $apw_list ) ) );
}
$apw_warnings = array();
