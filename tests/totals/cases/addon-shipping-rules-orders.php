<?php
/**
 * Advanced Shipping add-on: placed orders charge the shipping the rules allow, and match what
 * the cart showed. Orders are placed through the real checkout step with the Test payment
 * method. See addon-shipping-rules.php for the rules these follow.
 */

$t = 'SPC_Totals_Test';

// Turn on shipping methods the way Delivery & Shipping saves them, with "Ship" picked.
$shiprules_order_setup = function ( $methods ) use ( $t ) {
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
	SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
};

// $3 flat rate up to $49.99, free shipping from $50, as in the docs.
$shiprules_order_flat_or_free = function () use ( $shiprules_order_setup ) {
	$shiprules_order_setup(
		array(
			'spcshiprulesflat' => array(
				'id'         => 'flat_rate',
				'price'      => '3',
				'price_type' => 'cart',
				'order_max'  => '49.99',
			),
			'spcshiprulesfree' => array(
				'id'        => 'free',
				'order_min' => '50',
			),
		)
	);
};

// What the cart shows right before the customer places the order.
$shiprules_order_snapshot = function () {
	$cart = SPC()->cart;
	return array(
		'subtotal' => (float) $cart->get_subtotal(),
		'discount' => (float) $cart->get_discount(),
		'shipping' => (float) $cart->get_shipping(),
		'tax'      => (float) $cart->get_tax(),
		'total'    => (float) $cart->get_total(),
	);
};

$shiprules_order_matches = function ( $order, $seen ) use ( $t ) {
	$t::money( $seen['subtotal'], $order->get_subtotal(), 'order subtotal matches the cart' );
	$t::money( $seen['discount'], $order->get_discount(), 'order discount matches the cart' );
	$t::money( $seen['shipping'], $order->get_shipping(), 'order shipping matches the cart' );
	$t::money( $seen['tax'], $order->get_tax(), 'order tax matches the cart' );
	$t::money( $seen['total'], $order->get_total(), 'order total matches the cart' );
};

$t::test(
	'Shipping rules order: free shipping at exactly the minimum',
	function () use ( $t, $shiprules_order_flat_or_free, $shiprules_order_snapshot, $shiprules_order_matches ) {
		$t::add( $t::product( 25, array( 'taxable' => false ) ), 2 );
		$shiprules_order_flat_or_free();
		$t::checkout_address();
		$t::calculate();
		$seen  = $shiprules_order_snapshot();
		$order = $t::place_order();
		$shiprules_order_matches( $order, $seen );
		// 2 x 25 = 50.00 meets the 50 minimum.
		$t::same( 'spcshiprulesfree', $order->get_shipping_method(), 'order ships free' );
		$t::money( 0, $order->get_shipping(), 'order shipping' );
		$t::money( 50, $order->get_total(), 'order total' );
	}
);

$t::test(
	'Shipping rules order: prices include tax, a 55.00 order ships free',
	function () use ( $t, $shiprules_order_flat_or_free, $shiprules_order_snapshot, $shiprules_order_matches ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$t::option( 'display_price', 'with_tax' );
		$t::add( $t::product( 27.50 ), 2 );
		$shiprules_order_flat_or_free();
		$t::checkout_address();
		$t::calculate();
		$seen  = $shiprules_order_snapshot();
		$order = $t::place_order();
		$shiprules_order_matches( $order, $seen );
		// The customer saw 2 x 27.50 = 55.00, over the 50 minimum.
		$t::same( 'spcshiprulesfree', $order->get_shipping_method(), 'order ships free' );
		$t::money( 0, $order->get_shipping(), 'order shipping' );
		$t::money( 55, $order->get_total(), 'order total is the 55.00 shown' );
	}
);

// Tiers: 5 up to 49.99, 8 from 50 to 99.99, 12 from 100. The middle tier is taxed.
$t::test(
	'Shipping rules order: middle tier, taxed, with a discount code and a gift card',
	function () use ( $t, $shiprules_order_setup, $shiprules_order_snapshot, $shiprules_order_matches ) {
		$t::tax( 10 );
		$t::option( 'display_price', 'without_tax' );
		$t::add( $t::product( 20 ), 3 );
		$shiprules_order_setup(
			array(
				'spcshiprulestier1' => array(
					'id'         => 'flat_rate',
					'price'      => '5',
					'price_type' => 'cart',
					'order_max'  => '49.99',
				),
				'spcshiprulestier2' => array(
					'id'         => 'flat_rate',
					'price'      => '8',
					'price_type' => 'cart',
					'taxable'    => '1',
					'order_min'  => '50',
					'order_max'  => '99.99',
				),
				'spcshiprulestier3' => array(
					'id'         => 'flat_rate',
					'price'      => '12',
					'price_type' => 'cart',
					'order_min'  => '100',
				),
			)
		);
		$t::discount( 'percent-total', 10 );
		$t::gift_card( 25 );
		$t::checkout_address();
		$cart = $t::calculate();
		// Subtotal 60 is in the middle tier. 10% off = 6. Tax 10% of 54 = 5.40, plus 0.80 on
		// the 8 shipping = 6.20. 54 + 8 + 6.20 = 68.20, less the 25 gift card = 43.20.
		$t::money( 8, $cart->get_shipping(), 'cart shipping' );
		$t::money( 6.20, $cart->get_tax(), 'cart tax' );
		$t::money( 43.20, $cart->get_total(), 'cart left to pay' );
		$seen  = $shiprules_order_snapshot();
		$order = $t::place_order();
		$shiprules_order_matches( $order, $seen );
		$t::same( 'spcshiprulestier2', $order->get_shipping_method(), 'order uses the middle tier' );
		$fees = (array) $order->get_fees();
		$t::money( -25, isset( $fees['gift_card'] ) ? $fees['gift_card']['amount'] : 0, 'gift card on the order' );
	}
);

// The customer picked free shipping, then took an item out before placing the order.
$t::test(
	'Shipping rules order: free shipping picked earlier is not kept under the minimum',
	function () use ( $t, $shiprules_order_flat_or_free ) {
		$line = $t::add( $t::product( 30, array( 'taxable' => false ) ), 2 );
		$shiprules_order_flat_or_free();
		SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcshiprulesfree' );
		$t::checkout_address();
		$cart = $t::calculate();
		$t::money( 0, $cart->get_shipping(), 'free at 60' );

		SPC()->cart->update_item_quantity_by_hash( $line['hash'], 1 );
		$t::new_request();
		$order = $t::place_order();
		$t::same( 'spcshiprulesflat', $order->get_shipping_method(), 'order uses the flat rate' );
		$t::money( 3, $order->get_shipping(), 'order shipping' );
		$t::money( 33, $order->get_total(), 'order total 30 + 3' );
	}
);

$t::test(
	'Shipping rules order: hide others charges the rate that costs the most',
	function () use ( $t, $shiprules_order_setup, $shiprules_order_snapshot, $shiprules_order_matches ) {
		$t::add( $t::product( 10, array( 'taxable' => false ) ), 4 );
		$shiprules_order_setup(
			array(
				'spcshiprulesbox'  => array(
					'id'         => 'flat_rate',
					'price'      => '5',
					'price_type' => 'cart',
					'solo'       => '1',
				),
				'spcshiprulesitem' => array(
					'id'         => 'flat_rate',
					'price'      => '2',
					'price_type' => 'qty',
					'solo'       => '1',
				),
			)
		);
		$t::checkout_address();
		$t::calculate();
		$seen  = $shiprules_order_snapshot();
		$order = $t::place_order();
		$shiprules_order_matches( $order, $seen );
		// 4 items x 2 = 8, more than the 5 box rate.
		$t::same( 'spcshiprulesitem', $order->get_shipping_method(), 'order uses the per item rate' );
		$t::money( 8, $order->get_shipping(), 'order shipping' );
		$t::money( 48, $order->get_total(), 'order total 40 + 8' );
	}
);
