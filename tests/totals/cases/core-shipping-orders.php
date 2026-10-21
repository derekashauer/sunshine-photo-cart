<?php
/**
 * Shipping together with discount codes, gift cards, store credit and payment fees, and
 * placed orders that must save the shipping the customer saw in the cart.
 */

$t = 'SPC_Totals_Test';

// Each time Sunshine builds a shipping or delivery method object, the object adds a filter
// that is never taken off again, so a long test run gets slower with every cart it works out
// (reported as a finding). When each test here ends, take off the ones it added, so these
// tests leave the run as they found it.
$shiporders_test = function ( $name, $callback ) use ( $t ) {
	$t::test(
		$name,
		function () use ( $callback ) {
			global $wp_filter;
			$hooks  = array( 'sunshine_shipping_methods', 'sunshine_checkout_delivery_options', 'sunshine_delivery_methods', 'sunshine_options_shipping' );
			$before = array();
			foreach ( $hooks as $hook ) {
				$before[ $hook ] = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array();
			}
			try {
				$callback();
			} finally {
				foreach ( $hooks as $hook ) {
					if ( empty( $wp_filter[ $hook ] ) ) {
						continue;
					}
					foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
						foreach ( $callbacks as $callback_id => $entry ) {
							if ( ! isset( $before[ $hook ][ $priority ][ $callback_id ] ) ) {
								remove_filter( $hook, $entry['function'], $priority );
							}
						}
					}
				}
			}
		}
	);
};

// Turn on shipping methods the way the Delivery & Shipping settings save them.
// $methods is instance => array( 'id' => method, setting => value ).
$shiporders_setup = function ( $methods ) use ( $t ) {
	$list = array();
	foreach ( $methods as $instance => $settings ) {
		$method_id         = $settings['id'];
		$list[ $instance ] = array(
			'id'     => $method_id,
			'active' => true,
		);
		unset( $settings['id'] );
		foreach ( $settings as $key => $value ) {
			$t::option( $method_id . '_' . $key . '_' . $instance, $value );
		}
	}
	$t::option( 'shipping_methods', $list );
};

// One flat rate, chosen at checkout.
$shiporders_flat = function ( $price, $price_type = 'cart', $taxable = false ) use ( $shiporders_setup ) {
	$shiporders_setup(
		array(
			'spcshipflat' => array(
				'id'         => 'flat_rate',
				'price'      => (string) $price,
				'price_type' => $price_type,
				'taxable'    => $taxable ? '1' : '',
			),
		)
	);
	SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
	SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcshipflat' );
};

// Place the order with every outgoing web request blocked, so nothing placed here can reach
// an outside service (webhooks, mailing lists, and so on).
$shiporders_place = function () use ( $t ) {
	$block = function ( $pre, $args, $url ) {
		WP_CLI::log( 'NOTE core-shipping-orders blocked an outgoing request while placing an order: ' . $url );
		return new WP_Error( 'spc_totals_blocked', 'Outgoing requests are blocked by the totals tests' );
	};
	add_filter( 'pre_http_request', $block, PHP_INT_MAX, 3 );
	try {
		return $t::place_order();
	} finally {
		remove_filter( 'pre_http_request', $block, PHP_INT_MAX );
	}
};

// What the cart shows right before the customer places the order.
$shiporders_snapshot = function () {
	$cart  = SPC()->cart;
	$lines = array();
	foreach ( (array) $cart->get_cart_items() as $line ) {
		$lines[] = array(
			'total' => (float) $line->get_total(),
			'tax'   => (float) $line->get_tax_total(),
		);
	}
	return array(
		'subtotal'     => (float) $cart->get_subtotal(),
		'discount'     => (float) $cart->get_discount(),
		'shipping'     => (float) $cart->get_shipping(),
		'shipping_tax' => (float) $cart->get_shipping_tax(),
		'tax'          => (float) $cart->get_tax(),
		'total'        => (float) $cart->get_total(),
		'lines'        => $lines,
	);
};

$shiporders_matches_cart = function ( $order, $seen ) use ( $t ) {
	$t::money( $seen['subtotal'], $order->get_subtotal(), 'order subtotal matches the cart' );
	$t::money( $seen['discount'], $order->get_discount(), 'order discount matches the cart' );
	$t::money( $seen['shipping'], $order->get_shipping(), 'order shipping matches the cart' );
	$t::money( $seen['shipping_tax'], $order->get_shipping_tax(), 'order shipping tax matches the cart' );
	$t::money( $seen['tax'], $order->get_tax(), 'order tax matches the cart' );
	$t::money( $seen['total'], $order->get_total(), 'order total matches the cart' );
	$items = array_values( (array) $order->get_items() );
	$t::same( count( $seen['lines'] ), count( $items ), 'number of order lines' );
	foreach ( $seen['lines'] as $i => $line ) {
		if ( empty( $items[ $i ] ) ) {
			continue;
		}
		$t::money( $line['total'], $items[ $i ]->get_total(), 'line ' . ( $i + 1 ) . ' total' );
		$t::money( $line['tax'], $items[ $i ]->get_tax_total(), 'line ' . ( $i + 1 ) . ' tax' );
	}
};

// With prices that include tax, the customer sees shipping as 11. Ten percent off everything
// including shipping is ten percent of what they see: 10% of ( 110 + 11 ) = 12.10.
$shiporders_test(
	'Discount that includes taxed shipping, prices include tax',
	function () use ( $t, $shiporders_flat ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$shiporders_flat( 11, 'cart', true );
		$t::discount( 'percent-total', 10, array( 'apply_to_shipping' => '1' ) );
		$cart = $t::calculate();
		$t::money( 12.10, $cart->get_discount(), 'discount is 10% of 121' );
		// 121 - 12.10 = 108.90, of which 108.90 / 11 = 9.90 is tax.
		$t::money( 9.90, $cart->get_tax(), 'tax' );
		$t::money( 108.90, $cart->get_total(), 'total' );
	}
);

// Same store, but the code is for the items only: shipping keeps its full price and tax.
$shiporders_test(
	'Discount on the items only with taxed shipping, prices include tax',
	function () use ( $t, $shiporders_flat ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$shiporders_flat( 11, 'cart', true );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		$t::money( 11, $cart->get_discount(), 'discount is 10% of the 110 item' );
		// The item now costs 99 (9 tax) and shipping 11 (1 tax).
		$t::money( 10, $cart->get_tax(), 'tax' );
		$t::money( 110, $cart->get_total(), 'total 110 - 11 + 11' );
	}
);

$shiporders_test(
	'Discount that includes untaxed shipping, prices include tax',
	function () use ( $t, $shiporders_flat ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$shiporders_flat( 10, 'cart', false );
		$t::discount( 'percent-total', 10, array( 'apply_to_shipping' => '1' ) );
		$cart = $t::calculate();
		$t::money( 12, $cart->get_discount(), 'discount is 11 off the item + 1 off shipping' );
		// The item now costs 99, of which 9 is tax. Shipping isn't taxed.
		$t::money( 9, $cart->get_tax(), 'tax' );
		$t::money( 108, $cart->get_total(), 'total 110 + 10 - 12' );
	}
);

$shiporders_test(
	'Discount that includes taxed shipping, discount after tax',
	function () use ( $t, $shiporders_flat ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$shiporders_flat( 10, 'cart', true );
		$t::discount( 'percent-total', 10, array( 'apply_to_shipping' => '1' ) );
		$cart = $t::calculate();
		// Tax is on the full 110. The discount is 10% of 100 + 10 + 10 + 1.
		$t::money( 11, $cart->get_tax(), 'tax' );
		$t::money( 12.10, $cart->get_discount(), 'discount' );
		$t::money( 108.90, $cart->get_total(), 'total 121 - 12.10' );
	}
);

$shiporders_test(
	'Discount after tax on the items only, with taxed shipping',
	function () use ( $t, $shiporders_flat ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$shiporders_flat( 10, 'cart', true );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		// Tax is on the full 110. The discount is 10% of the item with its tax: 100 + 10.
		$t::money( 11, $cart->get_tax(), 'tax' );
		$t::money( 11, $cart->get_discount(), 'discount' );
		$t::money( 110, $cart->get_total(), 'total 100 + 10 + 11 - 11' );
	}
);

$shiporders_test(
	'Discount that includes per item shipping',
	function () use ( $t, $shiporders_flat ) {
		$t::add( $t::product( 10, array( 'taxable' => false ) ), 5 );
		$shiporders_flat( 2, 'qty' );
		$t::discount( 'percent-total', 20, array( 'apply_to_shipping' => '1' ) );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_shipping(), 'shipping 5 x 2' );
		$t::money( 12, $cart->get_discount(), 'discount 20% of 60' );
		$t::money( 48, $cart->get_total(), 'total' );
	}
);

// A fixed amount off the order is for the items. Shipping and its tax are still paid.
$shiporders_test(
	'Fixed amount discount bigger than the items still leaves shipping to pay',
	function () use ( $t, $shiporders_flat ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$shiporders_flat( 10, 'cart', true );
		$t::discount( 'amount-total', 150 );
		$cart = $t::calculate();
		$t::money( 100, $cart->get_discount(), 'discount is capped at the items' );
		$t::money( 1, $cart->get_tax(), 'tax on the shipping only' );
		$t::money( 11, $cart->get_total(), 'total 10 + 1' );
	}
);

$shiporders_test(
	'Gift card pays for a pickup fee',
	function () use ( $t, $shiporders_setup ) {
		$t::tax( 10 );
		$t::add( $t::product( 20 ) );
		$shiporders_setup(
			array(
				'spcshippickup' => array(
					'id'      => 'pickup',
					'price'   => '5',
					'taxable' => '1',
				),
			)
		);
		SPC()->cart->set_delivery_method( 'spcshippickup' );
		$t::gift_card( 25 );
		$cart = $t::calculate();
		$t::money( 5, $cart->get_shipping(), 'pickup fee' );
		// 20 + 5 + 2.50 tax = 27.50.
		$t::money( 25, array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) ), 'taken from the card' );
		$t::money( 2.50, $cart->get_total(), 'left to pay' );
	}
);

$shiporders_test(
	'Store credit covers shipping and its tax',
	function () use ( $t, $shiporders_flat ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 500 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$shiporders_flat( 10, 'cart', true );
		$cart = $t::calculate();
		$t::money( 121, $cart->get_credits_applied(), 'credits used 100 + 10 + 11 tax' );
		$t::money( 0, $cart->get_total(), 'total' );
	}
);

// The fee is for taking the payment, and the payment includes shipping.
$shiporders_test(
	'Percent payment fee includes shipping',
	function () use ( $t, $shiporders_flat ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$shiporders_flat( 10 );
		$t::option( 'test_fee', 'percent' );
		$t::option( 'test_fee_amount', '3' );
		$t::option( 'test_fee_name', 'Card fee' );
		SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
		$cart = $t::calculate();
		$t::money( 113.30, $cart->get_total(), '110 + 3% of 110' );
	}
);

$shiporders_test(
	'Order: per item shipping, taxed, with a discount that includes shipping',
	function () use ( $t, $shiporders_flat, $shiporders_place, $shiporders_snapshot, $shiporders_matches_cart ) {
		$t::tax( 10 );
		$t::add( $t::product( 10 ), 3 );
		$shiporders_flat( 2, 'qty', true );
		$t::discount( 'percent-total', 10, array( 'apply_to_shipping' => '1' ) );
		$t::checkout_address();
		$cart = $t::calculate();
		// Shipping 3 x 2 = 6. Discount 10% of 36 = 3.60. Tax 10% of 32.40 = 3.24.
		$t::money( 6, $cart->get_shipping(), 'cart shipping' );
		$t::money( 3.60, $cart->get_discount(), 'cart discount' );
		$t::money( 3.24, $cart->get_tax(), 'cart tax' );
		$t::money( 35.64, $cart->get_total(), 'cart total 30 + 6 - 3.60 + 3.24' );
		$seen  = $shiporders_snapshot();
		$order = $shiporders_place();
		$shiporders_matches_cart( $order, $seen );
		$t::money( 6, $order->get_shipping(), 'order shipping' );
		$t::money( 35.64, $order->get_total(), 'order total' );
		$t::same( 'shipping', $order->get_delivery_method(), 'order delivery method' );
		$t::same( 'spcshipflat', $order->get_shipping_method(), 'order shipping method' );
	}
);

$shiporders_test(
	'Order: local delivery',
	function () use ( $t, $shiporders_setup, $shiporders_place, $shiporders_snapshot, $shiporders_matches_cart ) {
		$t::tax( 10 );
		$t::add( $t::product( 40 ) );
		$shiporders_setup(
			array(
				'spcshiplocal' => array(
					'id'        => 'local',
					'name'      => 'Studio delivery',
					'price'     => '15',
					'taxable'   => '1',
					'postcodes' => '802*',
				),
			)
		);
		$t::checkout_address();
		SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
		SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcshiplocal' );
		$t::calculate();
		$seen  = $shiporders_snapshot();
		$order = $shiporders_place();
		$shiporders_matches_cart( $order, $seen );
		$t::money( 15, $order->get_shipping(), 'order shipping' );
		$t::money( 1.50, $order->get_shipping_tax(), 'order shipping tax' );
		$t::money( 5.50, $order->get_tax(), 'order tax 4 + 1.50' );
		$t::money( 60.50, $order->get_total(), 'order total' );
		$t::same( 'Studio delivery', $order->get_shipping_method_name(), 'order shipping method name' );
	}
);

$shiporders_test(
	'Order: pickup',
	function () use ( $t, $shiporders_setup, $shiporders_place, $shiporders_snapshot, $shiporders_matches_cart ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$shiporders_setup(
			array(
				'spcshipflat'   => array(
					'id'         => 'flat_rate',
					'price'      => '10',
					'price_type' => 'cart',
				),
				'spcshippickup' => array(
					'id'       => 'pickup',
					'price'    => '5',
					'taxable'  => '1',
					'location' => '1 Studio Lane',
				),
			)
		);
		SPC()->cart->set_delivery_method( 'spcshippickup' );
		$t::checkout_address();
		$t::calculate();
		$seen  = $shiporders_snapshot();
		$order = $shiporders_place();
		$shiporders_matches_cart( $order, $seen );
		$t::money( 5, $order->get_shipping(), 'order pickup fee' );
		$t::money( 0.50, $order->get_shipping_tax(), 'order tax on the fee' );
		$t::money( 115.50, $order->get_total(), 'order total 100 + 5 + 10.50' );
		$t::same( true, $order->is_pickup_order(), 'it is a pickup order' );
		$t::same( '1 Studio Lane', $order->get_pickup_location_details(), 'pickup location saved' );
	}
);

$shiporders_test(
	'Order: free shipping from a discount code',
	function () use ( $t, $shiporders_setup, $shiporders_place, $shiporders_snapshot, $shiporders_matches_cart ) {
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$shiporders_setup(
			array(
				'spcshipflat' => array(
					'id'         => 'flat_rate',
					'price'      => '8',
					'price_type' => 'cart',
				),
				'spcshipfree' => array(
					'id'       => 'free',
					'discount' => '1',
				),
			)
		);
		$t::discount( '', '', array( 'free_shipping' => '1' ) );
		SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
		SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcshipfree' );
		$t::checkout_address();
		$t::calculate();
		$seen  = $shiporders_snapshot();
		$order = $shiporders_place();
		$shiporders_matches_cart( $order, $seen );
		$t::same( 'spcshipfree', $order->get_shipping_method(), 'order shipping method' );
		$t::money( 0, $order->get_shipping(), 'order shipping' );
		$t::money( 50, $order->get_total(), 'order total' );
	}
);

$shiporders_test(
	'Order: taxed shipping, prices include tax',
	function () use ( $t, $shiporders_flat, $shiporders_place, $shiporders_snapshot, $shiporders_matches_cart ) {
		$t::tax( 7.25 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 42.90 ) );
		$shiporders_flat( 9.99, '', true );
		$t::checkout_address();
		$t::calculate();
		$seen  = $shiporders_snapshot();
		$order = $shiporders_place();
		$shiporders_matches_cart( $order, $seen );
		// Shipping 9.99 is 9.31 + 0.68 tax.
		$t::money( 9.31, $order->get_shipping(), 'order shipping before tax' );
		$t::money( 0.68, $order->get_shipping_tax(), 'order shipping tax' );
		$t::money( 3.58, $order->get_tax(), 'order tax 2.90 + 0.68' );
		$t::money( 52.89, $order->get_total(), 'order total 42.90 + 9.99' );
	}
);

$shiporders_test(
	'Order: only downloads, no shipping',
	function () use ( $t, $shiporders_flat, $shiporders_place, $shiporders_snapshot, $shiporders_matches_cart ) {
		$shiporders_flat( 10 );
		$t::add( $t::product( 20, array( 'type' => 'download', 'taxable' => false ) ) );
		$t::checkout_address();
		$t::calculate();
		$seen  = $shiporders_snapshot();
		$order = $shiporders_place();
		$shiporders_matches_cart( $order, $seen );
		$t::money( 0, $order->get_shipping(), 'order shipping' );
		$t::same( '', (string) $order->get_shipping_method(), 'no shipping method on the order' );
		$t::money( 20, $order->get_total(), 'order total' );
	}
);

$shiporders_test(
	'Order: a download and a print with per line shipping',
	function () use ( $t, $shiporders_flat, $shiporders_place, $shiporders_snapshot, $shiporders_matches_cart ) {
		$t::add( $t::product( 20, array( 'type' => 'download', 'taxable' => false ) ) );
		$t::add( $t::product( 10, array( 'taxable' => false ) ), 2 );
		$shiporders_flat( 4, 'line' );
		$t::checkout_address();
		$t::calculate();
		$seen  = $shiporders_snapshot();
		$order = $shiporders_place();
		$shiporders_matches_cart( $order, $seen );
		$t::money( 4, $order->get_shipping(), 'order shipping for the one shipped line' );
		$t::money( 44, $order->get_total(), 'order total 20 + 20 + 4' );
	}
);
