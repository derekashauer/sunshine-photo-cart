<?php
/**
 * Discount Codes add-on: discounts made and changed through the REST API (sunshine/v1/discounts).
 *
 * A discount made through the API must work exactly like one saved on the Discounts screen.
 * The rules used here:
 * - A discount takes money off; it never adds to what the customer pays.
 * - A percent discount is at most 100%.
 * - An update changes only the fields it sends.
 */

$t = 'SPC_Totals_Test';
if ( ! isset( $apw_test ) ) {
	require __DIR__ . '/api-price-writes-helpers.php';
}

$apw_test(
	'Discount made through the API: percent off the order, cart and order match',
	function () use ( $t, $apw_make_discount, $apw_use_code ) {
		$code                  = 'APWPCT' . wp_rand( 100000, 999999 );
		list( $status, $data ) = $apw_make_discount(
			array(
				'title'           => $code,
				'code'            => $code,
				'discount_type'   => 'percent-total',
				'discount_amount' => 10,
			)
		);
		$t::same( 201, $status, 'discount made' );
		$t::same( 'publish', isset( $data['status'] ) ? $data['status'] : null, 'published' );

		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$apw_use_code( $code );
		$cart = $t::calculate();
		// 10% of 100 = 10. Tax on 90 = 9. Total 99.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 9, $cart->get_tax(), 'tax' );
		$t::money( 99, $cart->get_total(), 'total' );
		$order = $t::place_order();
		$t::money( 10, $order->get_discount(), 'order discount' );
		$t::money( 9, $order->get_tax(), 'order tax' );
		$t::money( 99, $order->get_total(), 'order total' );
	}
);

$apw_test(
	'Discount made through the API: amount off each chosen product',
	function () use ( $t, $apw_make_discount, $apw_use_code ) {
		$a    = $t::product( 10, array( 'taxable' => false ) );
		$b    = $t::product( 10, array( 'taxable' => false ) );
		$code = 'APWAMT' . wp_rand( 100000, 999999 );
		$apw_make_discount(
			array(
				'title'            => $code,
				'code'             => $code,
				'discount_type'    => 'amount-product',
				'discount_amount'  => 2,
				'allowed_products' => array( $a ),
			)
		);
		$t::add( $a, 3 );
		$t::add( $b, 1 );
		$apw_use_code( $code );
		$cart = $t::calculate();
		// 2 off each of the 3 chosen products = 6. 40 - 6 = 34.
		$t::money( 6, $cart->get_discount(), 'discount' );
		$t::money( 34, $cart->get_total(), 'total' );
	}
);

$apw_test(
	'Discount made through the API: percent after tax',
	function () use ( $t, $apw_make_discount, $apw_use_code ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$code = 'APWAFT' . wp_rand( 100000, 999999 );
		$apw_make_discount(
			array(
				'title'           => $code,
				'code'            => $code,
				'discount_type'   => 'percent-total',
				'discount_amount' => 10,
			)
		);
		$t::add( $t::product( 100 ) );
		$apw_use_code( $code );
		$cart = $t::calculate();
		// Tax on the full 100 = 10. 10% of 110 = 11. Total 99.
		$t::money( 11, $cart->get_discount(), 'discount' );
		$t::money( 99, $cart->get_total(), 'total' );
	}
);

$apw_test(
	'Discount update through the API changes only what is sent',
	function () use ( $t, $apw_make_discount, $apw_rest, $apw_use_code ) {
		$a                     = $t::product( 50, array( 'taxable' => false ) );
		$b                     = $t::product( 50, array( 'taxable' => false ) );
		$code                  = 'APWUPD' . wp_rand( 100000, 999999 );
		list( $status, $data ) = $apw_make_discount(
			array(
				'title'            => $code,
				'code'             => $code,
				'discount_type'    => 'percent-product',
				'discount_amount'  => 10,
				'allowed_products' => array( $a ),
				'min_amount'       => 60,
			)
		);
		$id                    = isset( $data['id'] ) ? (int) $data['id'] : 0;
		list( $status, $data ) = $apw_rest( 'PUT', '/discounts/' . $id, array( 'discount_amount' => 20 ) );
		$t::same( 200, $status, 'update accepted' );
		$t::same( $code, isset( $data['code'] ) ? $data['code'] : null, 'code kept' );
		$t::same( 'percent-product', isset( $data['discount_type'] ) ? $data['discount_type'] : null, 'type kept' );
		$t::same( array( $a ), isset( $data['allowed_products'] ) ? $data['allowed_products'] : null, 'chosen products kept' );
		$t::money( 60, isset( $data['min_amount'] ) ? $data['min_amount'] : null, 'minimum kept' );
		$t::same( 'publish', isset( $data['status'] ) ? $data['status'] : null, 'still published' );

		$t::add( $a );
		$t::add( $b );
		$apw_use_code( $code );
		$cart = $t::calculate();
		// 20% of the chosen product's 50 = 10. 100 - 10 = 90.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 90, $cart->get_total(), 'total' );
	}
);

$apw_test(
	'Discount update to draft turns the code off',
	function () use ( $t, $apw_make_discount, $apw_rest, $apw_use_code ) {
		$code           = 'APWOFF' . wp_rand( 100000, 999999 );
		list( , $data ) = $apw_make_discount(
			array(
				'title'           => $code,
				'code'            => $code,
				'discount_type'   => 'amount-total',
				'discount_amount' => 15,
			)
		);
		$apw_rest( 'PUT', '/discounts/' . ( isset( $data['id'] ) ? (int) $data['id'] : 0 ), array( 'status' => 'draft' ) );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$apw_use_code( $code );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_discount(), 'no discount' );
		$t::money( 100, $cart->get_total(), 'total' );
	}
);

// The Discounts screen has no top limit on the amount either, but a percent over 100 only makes
// sense as a typo (150 for 15). Assumption: the API refuses it. If it is saved anyway, one code
// must still not take more than the products cost, so shipping and tax are still paid.
$apw_test(
	'Discount over 100% is refused, and never takes shipping and tax with it',
	function () use ( $t, $apw_make_discount, $apw_refused, $apw_use_code ) {
		$code           = 'APW150' . wp_rand( 100000, 999999 );
		list( $status ) = $apw_make_discount(
			array(
				'title'           => $code,
				'code'            => $code,
				'discount_type'   => 'percent-total',
				'discount_amount' => 150,
			)
		);
		$t::same( true, $apw_refused( $status ), 'refused as a bad request (got ' . $status . ')' );

		$t::tax( 10 );
		$t::flat_shipping( 10 );
		$t::add( $t::product( 100 ) );
		$apw_use_code( $code );
		$cart = $t::calculate();
		// If saved: at most the 100 of products comes off. Tax on 0 = 0. Shipping 10 is still paid.
		$t::same( true, (float) $cart->get_discount() <= 100, 'discount is not more than the products (got ' . $cart->get_discount() . ')' );
		$t::money( 10, $cart->get_total(), 'shipping is still paid' );
	}
);

$apw_test(
	'Discount with a negative amount is refused, and never raises the price or the tax',
	function () use ( $t, $apw_make_discount, $apw_refused, $apw_use_code ) {
		$code           = 'APWNEG' . wp_rand( 100000, 999999 );
		list( $status ) = $apw_make_discount(
			array(
				'title'           => $code,
				'code'            => $code,
				'discount_type'   => 'percent-total',
				'discount_amount' => -10,
			)
		);
		$t::same( true, $apw_refused( $status ), 'refused as a bad request (got ' . $status . ')' );

		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$apw_use_code( $code );
		$cart = $t::calculate();
		// No discount: 100 + 10 tax = 110.
		$t::money( 10, $cart->get_tax(), 'tax' );
		$t::money( 110, $cart->get_total(), 'total' );
		$order = $t::place_order();
		$t::money( 0, $order->get_discount(), 'order discount' );
		$t::money( 10, $order->get_tax(), 'order tax' );
		$t::money( 110, $order->get_total(), 'order total' );

		// The same code when prices include tax: 110 with tax, nothing off.
		$t::reset();
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$apw_use_code( $code );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_tax(), 'tax included, prices include tax' );
		$t::money( 110, $cart->get_total(), 'total, prices include tax' );
	}
);

foreach ( $apw_warnings as $apw_name => $apw_list ) {
	WP_CLI::log( 'WARN ' . $apw_name . ': ' . implode( ' | ', array_unique( $apw_list ) ) );
}
$apw_warnings = array();
