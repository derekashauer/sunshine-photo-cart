<?php
/**
 * Placed orders carry the tax of the address they were taxed at: the amount, the rate's name
 * and the addresses themselves. Orders are placed through the real checkout step with the
 * Test payment method, so nothing is charged anywhere.
 */

$t = 'SPC_Totals_Test';

$tax_locations_orders_rate = function ( $country, $state, $postcode, $rate, $name = '' ) {
	return array(
		'country'  => $country,
		'state'    => $state,
		'postcode' => $postcode,
		'rate'     => (string) $rate,
		'name'     => $name,
	);
};

$tax_locations_orders_rates = function ( $rates ) use ( $t ) {
	$t::option( 'taxes_enabled', 1 );
	$t::option( 'tax_rates', $rates );
};

$tax_locations_orders_address = function ( $prefix, $country, $state, $postcode ) {
	$fields = array(
		'first_name' => 'Totals',
		'last_name'  => 'Test',
		'address1'   => '1 Main St',
		'city'       => 'Somewhere',
		'state'      => $state,
		'postcode'   => $postcode,
		'country'    => $country,
	);
	foreach ( $fields as $key => $value ) {
		SPC()->cart->set_checkout_data_item( $prefix . $key, $value );
	}
};

$tax_locations_orders_contact = function () {
	foreach ( array(
		'email'      => 'totals-test@example.com',
		'first_name' => 'Totals',
		'last_name'  => 'Test',
		'phone'      => '5555555555',
	) as $key => $value ) {
		SPC()->cart->set_checkout_data_item( $key, $value );
	}
};

// Same as SPC_Totals_Test::place_order(), but keeps the addresses the test entered instead of
// filling in a Denver address for both, because these tests are about which address is used.
$tax_locations_orders_place = function () use ( $t ) {
	$ajax = '__return_true';
	$die  = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'wp_doing_ajax', $ajax );
	add_filter( 'wp_die_ajax_handler', $die );
	$_POST['security']       = wp_create_nonce( 'sunshine-checkout-init-order' );
	$_POST['payment_method'] = 'test';
	SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
	ob_start();
	try {
		SPC()->cart->init_order();
	} catch ( SPC_Totals_Test_Done $e ) {
		// init_order() always ends by sending a JSON response.
	}
	$output = ob_get_clean();
	unset( $_POST['security'], $_POST['payment_method'] );
	remove_filter( 'wp_doing_ajax', $ajax );
	remove_filter( 'wp_die_ajax_handler', $die );

	$response = json_decode( $output, true );
	if ( empty( $response['success'] ) || empty( $response['data']['order_id'] ) ) {
		throw new Exception( 'Order was not placed: ' . wp_json_encode( SPC()->cart->get_errors() ) . ' ' . substr( $output, 0, 300 ) );
	}
	$t::track( 'order', $response['data']['order_id'] );
	return sunshine_get_order( $response['data']['order_id'] );
};

$tax_locations_orders_line_tax = function ( $order ) {
	$sum = 0;
	foreach ( (array) $order->get_items() as $item ) {
		$sum += (float) $item->get_tax_total();
	}
	return $sum;
};

$t::test(
	'Tax order: a zip rate, its name and both addresses are saved on the order',
	function () use ( $t, $tax_locations_orders_rates, $tax_locations_orders_rate, $tax_locations_orders_address, $tax_locations_orders_contact, $tax_locations_orders_place, $tax_locations_orders_line_tax ) {
		$tax_locations_orders_rates(
			array(
				$tax_locations_orders_rate( 'US', 'CO', '80202', 8.81, 'Denver tax' ),
				$tax_locations_orders_rate( 'US', 'CO', '', 2.9 ),
				$tax_locations_orders_rate( 'US', 'CA', '', 7.25 ),
			)
		);
		$t::add( $t::product( 19.99 ), 3 );
		$t::flat_shipping( 10, true );
		$tax_locations_orders_contact();
		$tax_locations_orders_address( 'shipping_', 'US', 'CO', '80202' );
		SPC()->cart->set_checkout_data_item( 'shipping_as_billing', '' );
		$tax_locations_orders_address( 'billing_', 'US', 'CA', '90001' );
		$cart = $t::calculate();
		// Items 59.97 x 8.81% = 5.28. Shipping 10 x 8.81% = 0.88. Tax 6.16.
		$t::money( 6.16, $cart->get_tax(), 'cart tax' );
		$t::money( 76.13, $cart->get_total(), 'cart total 59.97 + 10 + 6.16' );

		$order = $tax_locations_orders_place();
		$t::money( 6.16, $order->get_tax(), 'order tax' );
		$t::money( 0.88, $order->get_shipping_tax(), 'order shipping tax' );
		$t::money( 5.28, $tax_locations_orders_line_tax( $order ), 'order line tax' );
		$t::money( 76.13, $order->get_total(), 'order total' );
		$t::same( 'Denver tax', $order->get_tax_label(), 'order tax name' );
		$t::same( '80202', $order->get_meta_value( 'shipping_postcode' ), 'shipping zip saved' );
		$t::same( 'CA', $order->get_meta_value( 'billing_state' ), 'billing state saved' );
	}
);

$t::test(
	'Tax order: a pickup order carries the store rate and its name',
	function () use ( $t, $tax_locations_orders_rates, $tax_locations_orders_rate, $tax_locations_orders_address, $tax_locations_orders_contact, $tax_locations_orders_place, $tax_locations_orders_line_tax ) {
		$t::option( 'country', 'US' );
		$t::option( 'state', 'NY' );
		$t::option( 'postcode', '10001' );
		$tax_locations_orders_rates(
			array(
				$tax_locations_orders_rate( 'US', 'NY', '', 8.875, 'NY tax' ),
				$tax_locations_orders_rate( 'US', 'CA', '', 7.25, 'CA tax' ),
			)
		);
		$t::option(
			'shipping_methods',
			array(
				'spctestflat'   => array(
					'id'     => 'flat_rate',
					'active' => 1,
				),
				'spctestpickup' => array(
					'id'     => 'pickup',
					'active' => 1,
				),
			)
		);
		$t::option( 'flat_rate_price_spctestflat', '10' );
		$t::option( 'pickup_price_spctestpickup', '0' );
		$t::add( $t::product( 200 ) );
		SPC()->cart->set_checkout_data_item( 'delivery_method', 'spctestpickup' );
		$tax_locations_orders_contact();
		$tax_locations_orders_address( 'billing_', 'US', 'CA', '90001' );
		$cart = $t::calculate();
		// 200 x 8.875% New York.
		$t::money( 17.75, $cart->get_tax(), 'cart tax' );

		$order = $tax_locations_orders_place();
		$t::same( 'pickup', $order->get_delivery_method(), 'order is a pickup order' );
		$t::money( 17.75, $order->get_tax(), 'order tax' );
		$t::money( 17.75, $tax_locations_orders_line_tax( $order ), 'order line tax' );
		$t::money( 217.75, $order->get_total(), 'order total' );
		$t::same( 'NY tax', $order->get_tax_label(), 'order tax name' );
	}
);

$t::test(
	'Tax order: a download-only order carries the billing address rate',
	function () use ( $t, $tax_locations_orders_rates, $tax_locations_orders_rate, $tax_locations_orders_address, $tax_locations_orders_contact, $tax_locations_orders_place, $tax_locations_orders_line_tax ) {
		$t::require_plugin( 'sunshine_get_download_product', 'Digital Downloads add-on' );
		$tax_locations_orders_rates(
			array(
				$tax_locations_orders_rate( 'US', 'CO', '', 2.9, 'CO tax' ),
				$tax_locations_orders_rate( 'US', 'CA', '', 7.25, 'CA tax' ),
			)
		);
		$t::option(
			'shipping_methods',
			array(
				'spctestflat' => array(
					'id'     => 'flat_rate',
					'active' => 1,
				),
			)
		);
		$t::option( 'flat_rate_price_spctestflat', '10' );
		$t::add( $t::product( 20, array( 'type' => 'download' ) ) );
		$t::add( $t::product( 40, array( 'type' => 'download' ) ) );
		$tax_locations_orders_contact();
		$tax_locations_orders_address( 'billing_', 'US', 'CA', '90001' );
		$cart = $t::calculate();
		// 20 x 7.25% = 1.45 and 40 x 7.25% = 2.90.
		$t::money( 4.35, $cart->get_tax(), 'cart tax' );

		$order = $tax_locations_orders_place();
		$t::money( 4.35, $order->get_tax(), 'order tax' );
		$t::money( 4.35, $tax_locations_orders_line_tax( $order ), 'order line tax' );
		$t::money( 64.35, $order->get_total(), 'order total' );
		$t::money( 0, $order->get_shipping(), 'no shipping' );
		$t::same( 'CA tax', $order->get_tax_label(), 'order tax name' );
	}
);

// The customer removed the only print before paying. Nothing is shipped, so the download is
// taxed at the billing address (California), not the shipping address left in the session.
$t::test(
	'Tax order: a download order placed after the print was removed uses the billing address',
	function () use ( $t, $tax_locations_orders_rates, $tax_locations_orders_rate, $tax_locations_orders_address, $tax_locations_orders_contact, $tax_locations_orders_place ) {
		$t::require_plugin( 'sunshine_get_download_product', 'Digital Downloads add-on' );
		$tax_locations_orders_rates(
			array(
				$tax_locations_orders_rate( 'US', 'CO', '', 2.9, 'CO tax' ),
				$tax_locations_orders_rate( 'US', 'CA', '', 7.25, 'CA tax' ),
			)
		);
		$print = $t::product( 100 );
		$t::add( $print );
		$t::add( $t::product( 20, array( 'type' => 'download' ) ) );
		$t::flat_shipping( 10, false );
		$tax_locations_orders_contact();
		$tax_locations_orders_address( 'shipping_', 'US', 'CO', '80202' );
		SPC()->cart->set_checkout_data_item( 'shipping_as_billing', '' );
		$tax_locations_orders_address( 'billing_', 'US', 'CA', '90001' );
		$cart = $t::calculate();
		foreach ( $cart->get_cart() as $key => $line ) {
			if ( (int) $line['product_id'] === (int) $print ) {
				$cart->remove_item( $key );
			}
		}
		$t::new_request();

		$order = $tax_locations_orders_place();
		// 20 x 7.25% California.
		$t::money( 1.45, $order->get_tax(), 'order tax' );
		$t::money( 21.45, $order->get_total(), 'order total' );
		$t::same( 'CA tax', $order->get_tax_label(), 'order tax name' );
	}
);

$t::test(
	'Tax order: changing the address just before paying, the order has the new tax',
	function () use ( $t, $tax_locations_orders_rates, $tax_locations_orders_rate, $tax_locations_orders_address, $tax_locations_orders_contact, $tax_locations_orders_place ) {
		$tax_locations_orders_rates(
			array(
				$tax_locations_orders_rate( 'US', 'CO', '', 2.9, 'CO tax' ),
				$tax_locations_orders_rate( 'US', 'CA', '', 7.25, 'CA tax' ),
			)
		);
		$t::add( $t::product( 80 ) );
		$t::flat_shipping( 20, true );
		$tax_locations_orders_contact();
		SPC()->cart->set_checkout_data_item( 'shipping_as_billing', 'yes' );
		$tax_locations_orders_address( 'shipping_', 'US', 'CO', '80202' );
		$cart = $t::calculate();
		$t::money( 2.90, $cart->get_tax(), 'Colorado tax ( 80 + 20 ) x 2.9%' );

		$tax_locations_orders_address( 'shipping_', 'US', 'CA', '90001' );
		$t::calculate();
		$order = $tax_locations_orders_place();
		// ( 80 + 20 ) x 7.25%.
		$t::money( 7.25, $order->get_tax(), 'order tax' );
		$t::money( 107.25, $order->get_total(), 'order total' );
		$t::same( 'CA tax', $order->get_tax_label(), 'order tax name' );
		$t::same( 'CA', $order->get_meta_value( 'billing_state' ), 'billing copied from the new shipping address' );
	}
);

$t::test(
	'Tax order: discount code with a location rate',
	function () use ( $t, $tax_locations_orders_rates, $tax_locations_orders_rate, $tax_locations_orders_address, $tax_locations_orders_contact, $tax_locations_orders_place, $tax_locations_orders_line_tax ) {
		$tax_locations_orders_rates(
			array(
				$tax_locations_orders_rate( 'US', 'CO', '', 2.9 ),
				$tax_locations_orders_rate( 'US', 'CA', '', 7.25 ),
			)
		);
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 20, true );
		$t::discount( 'percent-total', 20 );
		$tax_locations_orders_contact();
		SPC()->cart->set_checkout_data_item( 'shipping_as_billing', 'yes' );
		$tax_locations_orders_address( 'shipping_', 'US', 'CA', '90001' );
		$cart = $t::calculate();
		// ( 100 - 20 discount + 20 shipping ) x 7.25% = 7.25.
		$t::money( 20, $cart->get_discount(), 'cart discount' );
		$t::money( 7.25, $cart->get_tax(), 'cart tax' );
		$t::money( 107.25, $cart->get_total(), 'cart total 80 + 20 + 7.25' );

		$order = $tax_locations_orders_place();
		$t::money( 20, $order->get_discount(), 'order discount' );
		$t::money( 7.25, $order->get_tax(), 'order tax' );
		$t::money( 1.45, $order->get_shipping_tax(), 'order shipping tax 20 x 7.25%' );
		$t::money( 107.25, $order->get_total(), 'order total' );
	}
);

$t::test(
	'Tax order: prices include tax, customer in a higher rate area',
	function () use ( $t, $tax_locations_orders_rates, $tax_locations_orders_rate, $tax_locations_orders_address, $tax_locations_orders_contact, $tax_locations_orders_place, $tax_locations_orders_line_tax ) {
		$t::option( 'price_has_tax', 'yes' );
		$tax_locations_orders_rates(
			array(
				$tax_locations_orders_rate( 'US', 'CO', '', 10 ),
				$tax_locations_orders_rate( 'US', 'CA', '', 20, 'VAT' ),
			)
		);
		$t::add( $t::product( 110 ) );
		$t::flat_shipping( 0, false );
		$tax_locations_orders_contact();
		SPC()->cart->set_checkout_data_item( 'shipping_as_billing', 'yes' );
		$tax_locations_orders_address( 'shipping_', 'US', 'CA', '90001' );
		$cart = $t::calculate();
		// 110 / 1.20 = 91.67, so 18.33 is tax and the customer pays the 110 shelf price.
		$t::money( 18.33, $cart->get_tax(), 'cart tax' );
		$t::money( 110, $cart->get_total(), 'cart total' );

		$order = $tax_locations_orders_place();
		$t::money( 18.33, $order->get_tax(), 'order tax' );
		$t::money( 18.33, $tax_locations_orders_line_tax( $order ), 'order line tax' );
		$t::money( 110, $order->get_total(), 'order total' );
		$t::same( 'VAT', $order->get_tax_label(), 'order tax name' );
	}
);
