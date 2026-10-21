<?php
/**
 * Stores that write money with a decimal comma: 4,95 for four ninety-five, and 1.250,00 for
 * one thousand two hundred fifty (Sunshine > Settings > General: Decimal separator "," and
 * Thousands separator ".").
 *
 * This file covers the Price box of each shipping or delivery method (Flat Rate, Local Delivery
 * and Pickup). That box is a plain text box, so a store with a decimal comma types its price
 * the same way it types product prices: 4,95. The cart reads product prices with
 * sunshine_sanitize_amount(), which follows the store's separators, so a shipping price typed the
 * same way must cost the same amount.
 *
 * The prices are saved the way Sunshine > Settings > Delivery & Shipping saves them: the box has
 * no clean-up step of its own (no "callback" on the field), so WordPress stores exactly what was
 * typed. The helper below runs the same steps on the real field list of each method.
 */

$t = 'SPC_Totals_Test';

// Each time Sunshine builds a shipping or delivery method object, the object adds a filter
// that is never taken off again (already reported). When each test here ends, take off the ones
// it added, so these tests leave the run as they found it. Every test here runs in a store that
// writes money with a decimal comma.
$decimal_comma_ship_test = function ( $name, $callback ) use ( $t ) {
	$t::test(
		'Decimal comma: ' . $name,
		function () use ( $t, $callback ) {
			global $wp_filter;
			$hooks  = array( 'sunshine_shipping_methods', 'sunshine_checkout_delivery_options', 'sunshine_delivery_methods', 'sunshine_options_shipping' );
			$before = array();
			foreach ( $hooks as $hook ) {
				$before[ $hook ] = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array();
			}
			$t::option( 'currency', 'EUR' );
			$t::option( 'currency_thousands_separator', '.' );
			$t::option( 'currency_decimal_separator', ',' );
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

// What the settings screen stores when this is typed into one of a method's boxes.
// It finds the box in the method's real field list (the core fields plus what add-ons such as
// Advanced Shipping add), then does what the settings screen does on save: run the field's own
// clean-up step if it has one, then WordPress's sanitize_option(). The method object is made
// without its constructor, so it hooks nothing.
$decimal_comma_ship_saved = function ( $method_id, $instance, $setting, $typed ) {
	$classes = array(
		'flat_rate' => 'SPC_Shipping_Method_Flat_Rate',
		'local'     => 'SPC_Shipping_Method_Local',
		'pickup'    => 'SPC_Shipping_Method_Pickup',
	);
	$method  = ( new ReflectionClass( $classes[ $method_id ] ) )->newInstanceWithoutConstructor();
	$method->init();
	$fields   = $method->options( $method->default_options( array(), $instance ), $instance );
	$field_id = $method_id . '_' . $setting . '_' . $instance;
	foreach ( $fields as $field ) {
		if ( isset( $field['id'] ) && $field_id === $field['id'] ) {
			$value = $typed;
			if ( isset( $field['callback'] ) ) {
				$value = call_user_func( $field['callback'], $value );
			}
			return sanitize_option( 'sunshine_' . $field_id, $value );
		}
	}
	throw new Exception( 'The settings screen has no ' . $field_id . ' box' );
};

// Turn on shipping methods with prices typed into the settings screen.
// $methods is instance => array( 'id' => method, setting => value as typed ).
$decimal_comma_ship_setup = function ( $methods ) use ( $t, $decimal_comma_ship_saved ) {
	$list = array();
	foreach ( $methods as $instance => $settings ) {
		$method_id         = $settings['id'];
		$list[ $instance ] = array(
			'id'     => $method_id,
			'active' => true,
		);
		unset( $settings['id'] );
		foreach ( $settings as $key => $value ) {
			if ( 'price' === $key ) {
				$value = $decimal_comma_ship_saved( $method_id, $instance, 'price', $value );
			}
			$t::option( $method_id . '_' . $key . '_' . $instance, $value );
		}
	}
	$t::option( 'shipping_methods', $list );
};

// One flat rate, chosen at checkout. With Advanced Shipping active (as on the test site) the
// settings screen saves a price type, "cart" unless another is picked. Without it, none is saved.
$decimal_comma_ship_flat = function ( $typed_price, $price_type = 'cart', $taxable = false ) use ( $decimal_comma_ship_setup ) {
	$decimal_comma_ship_setup(
		array(
			'spcdcflat' => array(
				'id'         => 'flat_rate',
				'price'      => $typed_price,
				'price_type' => $price_type,
				'taxable'    => $taxable ? '1' : '',
			),
		)
	);
	SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
	SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcdcflat' );
};

// What the customer pays for shipping, tax included.
$decimal_comma_ship_paid = function ( $cart ) {
	return (float) $cart->get_shipping() + (float) $cart->get_shipping_tax();
};

// A product's Extra Shipping Cost typed on the product edit screen. The box is a "price" field
// with one box per price level (sunshine-product.php). On save, save_meta_boxes() runs the
// field type's clean-up (Sunshine_Admin_Meta_Boxes::sanitize(), which removes the store's
// thousands separator), then the field's _validate filter, then saves it. The meta box object is
// made without its constructor, so it hooks nothing.
$decimal_comma_ship_extra_cost = function ( $product_id, $typed ) {
	$posted = array();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		if ( $level ) {
			$posted[ $level->get_id() ] = '';
		}
	}
	$posted[ sunshine_get_default_price_level_id() ] = $typed;
	$box   = ( new ReflectionClass( 'Sunshine_Admin_Meta_Boxes' ) )->newInstanceWithoutConstructor();
	$value = $box->sanitize( $posted, 'price' );
	$value = apply_filters( 'sunshine_meta_shipping_validate', $value );
	delete_post_meta( $product_id, 'shipping' );
	update_post_meta( $product_id, 'shipping', $value );
};

/*
 * The price box on its own.
 */

foreach ( array( 'cart' => 'whole cart', '' => 'no price type saved' ) as $decimal_comma_ship_type => $decimal_comma_ship_label ) {
	$decimal_comma_ship_test(
		'flat rate typed as 4,95 charges 4.95 (' . $decimal_comma_ship_label . ')',
		function () use ( $t, $decimal_comma_ship_flat, $decimal_comma_ship_type ) {
			$t::add( $t::product( 20, array( 'taxable' => false ) ) );
			$decimal_comma_ship_flat( '4,95', $decimal_comma_ship_type );
			$cart = $t::calculate();
			$t::money( 4.95, $cart->get_shipping(), 'shipping' );
			$t::money( 24.95, $cart->get_total(), 'total 20 + 4.95' );
		}
	);
}

// A price under 1 typed with a comma must not turn into free shipping.
$decimal_comma_ship_test(
	'flat rate per item typed as 0,35 on 300 prints',
	function () use ( $t, $decimal_comma_ship_flat ) {
		$t::add( $t::product( 1, array( 'taxable' => false ) ), 300 );
		$decimal_comma_ship_flat( '0,35', 'qty' );
		$cart = $t::calculate();
		// 300 x 0.35 = 105.
		$t::money( 105, $cart->get_shipping(), 'shipping' );
		$t::money( 405, $cart->get_total(), 'total 300 + 105' );
	}
);

$decimal_comma_ship_test(
	'flat rate per line typed as 2,25',
	function () use ( $t, $decimal_comma_ship_flat ) {
		$t::add( $t::product( 10, array( 'taxable' => false ) ), 1 );
		$t::add( $t::product( 10, array( 'taxable' => false ) ), 3 );
		$decimal_comma_ship_flat( '2,25', 'line' );
		$cart = $t::calculate();
		// 2 lines x 2.25 = 4.50.
		$t::money( 4.50, $cart->get_shipping(), 'shipping' );
		$t::money( 44.50, $cart->get_total(), 'total 40 + 4.50' );
	}
);

// Freight for a very large order, typed with the store's thousands separator too.
$decimal_comma_ship_test(
	'flat rate typed as 1.250,00 charges 1250',
	function () use ( $t, $decimal_comma_ship_flat ) {
		$t::add( $t::product( 2000, array( 'taxable' => false ) ) );
		$decimal_comma_ship_flat( '1.250,00' );
		$cart = $t::calculate();
		$t::money( 1250, $cart->get_shipping(), 'shipping' );
		$t::money( 3250, $cart->get_total(), 'total 2000 + 1250' );
	}
);

// A store that switched to a decimal comma after setting up shipping still has prices saved
// with a dot. sunshine_sanitize_amount(), which the cart uses for product prices, reads a dot as
// the decimal point when there is no comma in the amount, so those keep their cents.
$decimal_comma_ship_test(
	'flat rate saved earlier as 4.95 still charges 4.95',
	function () use ( $t, $decimal_comma_ship_flat ) {
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$decimal_comma_ship_flat( '4.95' );
		$cart = $t::calculate();
		$t::money( 4.95, $cart->get_shipping(), 'shipping' );
		$t::money( 24.95, $cart->get_total(), 'total' );
	}
);

$decimal_comma_ship_test(
	'flat rate typed as 0,00 is free',
	function () use ( $t, $decimal_comma_ship_flat ) {
		$t::tax( 10 );
		$t::add( $t::product( 20 ) );
		$decimal_comma_ship_flat( '0,00', 'cart', true );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 0, $cart->get_shipping_tax(), 'shipping tax' );
		$t::money( 22, $cart->get_total(), 'total 20 + 2 tax' );
	}
);

$decimal_comma_ship_test(
	'local delivery typed as 7,50',
	function () use ( $t, $decimal_comma_ship_setup ) {
		$t::add( $t::product( 30, array( 'taxable' => false ) ) );
		$decimal_comma_ship_setup(
			array(
				'spcdclocal' => array(
					'id'        => 'local',
					'price'     => '7,50',
					'taxable'   => '',
					'postcodes' => '',
				),
			)
		);
		SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
		SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcdclocal' );
		$cart = $t::calculate();
		$t::money( 7.50, $cart->get_shipping(), 'local delivery' );
		$t::money( 37.50, $cart->get_total(), 'total 30 + 7.50' );
	}
);

$decimal_comma_ship_test(
	'pickup fee typed as 2,50, taxed',
	function () use ( $t, $decimal_comma_ship_setup ) {
		$t::tax( 10 );
		$t::add( $t::product( 40 ) );
		$decimal_comma_ship_setup(
			array(
				'spcdcpickup' => array(
					'id'       => 'pickup',
					'price'    => '2,50',
					'taxable'  => '1',
					'location' => '1 Studio Lane',
				),
			)
		);
		SPC()->cart->set_delivery_method( 'spcdcpickup' );
		$cart = $t::calculate();
		$t::same( 'pickup', SPC()->cart->get_delivery_method_id(), 'delivery method' );
		// Tax: 40 x 10% = 4.00, plus 2.50 x 10% = 0.25.
		$t::money( 2.50, $cart->get_shipping(), 'pickup fee' );
		$t::money( 0.25, $cart->get_shipping_tax(), 'tax on the fee' );
		$t::money( 4.25, $cart->get_tax(), 'tax' );
		$t::money( 46.75, $cart->get_total(), 'total 40 + 2.50 + 4.25' );
	}
);

/*
 * Tax on shipping.
 */

$decimal_comma_ship_test(
	'taxed flat rate 4,95, prices without tax',
	function () use ( $t, $decimal_comma_ship_flat ) {
		$t::tax( 20 );
		$t::add( $t::product( 50 ) );
		$decimal_comma_ship_flat( '4,95', 'cart', true );
		$cart = $t::calculate();
		// Items 50 x 20% = 10. Shipping 4.95 x 20% = 0.99.
		$t::money( 4.95, $cart->get_shipping(), 'shipping' );
		$t::money( 0.99, $cart->get_shipping_tax(), 'shipping tax' );
		$t::money( 10.99, $cart->get_tax(), 'tax 10 + 0.99' );
		$t::money( 65.94, $cart->get_total(), 'total 50 + 4.95 + 10.99' );
	}
);

// No price type here: a saved price type with prices that include tax is already reported.
$decimal_comma_ship_test(
	'taxed flat rate 5,95, prices include tax',
	function () use ( $t, $decimal_comma_ship_flat, $decimal_comma_ship_paid ) {
		$t::tax( 19 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 119 ) );
		$decimal_comma_ship_flat( '5,95', '', true );
		$cart = $t::calculate();
		// The customer pays the 5.95 typed. 5.95 / 1.19 = 5.00, so 0.95 of it is tax.
		$t::money( 5.95, $decimal_comma_ship_paid( $cart ), 'shipping paid is the 5,95 typed' );
		$t::money( 0.95, $cart->get_shipping_tax(), 'tax in the shipping' );
		$t::money( 19.95, $cart->get_tax(), 'tax 19 + 0.95' );
		$t::money( 124.95, $cart->get_total(), 'total 119 + 5.95' );
	}
);

/*
 * Shipping typed with a comma, together with discounts, gift cards and payment fees.
 */

$decimal_comma_ship_test(
	'discount code that includes shipping typed as 5,50',
	function () use ( $t, $decimal_comma_ship_flat ) {
		$t::add( $t::product( 44.50, array( 'taxable' => false ) ) );
		$decimal_comma_ship_flat( '5,50' );
		$t::discount( 'percent-total', '10', array( 'apply_to_shipping' => '1' ) );
		$cart = $t::calculate();
		// 10% of 44.50 + 5.50 = 5.00.
		$t::money( 5.50, $cart->get_shipping(), 'shipping' );
		$t::money( 5, $cart->get_discount(), 'discount' );
		$t::money( 45, $cart->get_total(), 'total 50 - 5' );
	}
);

$decimal_comma_ship_test(
	'discount after tax with taxed shipping typed as 5,50',
	function () use ( $t, $decimal_comma_ship_flat ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$decimal_comma_ship_flat( '5,50', 'cart', true );
		$t::discount( 'percent-total', '10' );
		$cart = $t::calculate();
		// Item 100 + 10 tax, 10% off that = 11. Shipping 5.50 + 0.55 tax.
		// 110 - 11 + 5.50 + 0.55 = 105.05.
		$t::money( 5.50, $cart->get_shipping(), 'shipping' );
		$t::money( 0.55, $cart->get_shipping_tax(), 'shipping tax' );
		$t::money( 105.05, $cart->get_total(), 'total' );
	}
);

$decimal_comma_ship_test(
	'gift card pays for an order with shipping typed as 4,95',
	function () use ( $t, $decimal_comma_ship_flat ) {
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$decimal_comma_ship_flat( '4,95' );
		$t::gift_card( 100 );
		$cart = $t::calculate();
		// The card pays all of 50 + 4.95.
		$taken = array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) );
		$t::money( 54.95, $taken, 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

// Whole-euro credits only: credits with cents are already reported as losing their cents.
$decimal_comma_ship_test(
	'store credit pays for an order with shipping typed as 4,95',
	function () use ( $t, $decimal_comma_ship_flat ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 100 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$decimal_comma_ship_flat( '4,95' );
		$cart = $t::calculate();
		// Credit pays all of 20 + 4.95.
		$t::money( 24.95, $cart->get_credits_applied(), 'credit used' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

// The payment fee box is a number box, and browsers send a number box as 2 or 2.5 whatever the
// store's separators are.
$decimal_comma_ship_test(
	'percent payment fee counts shipping typed as 4,95',
	function () use ( $t, $decimal_comma_ship_flat ) {
		$t::add( $t::product( 45.05, array( 'taxable' => false ) ) );
		$decimal_comma_ship_flat( '4,95' );
		$t::option( 'test_fee', 'percent' );
		$t::option( 'test_fee_amount', '2' );
		$t::option( 'test_fee_name', 'Card fee' );
		SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
		$cart = $t::calculate();
		// 2% of 45.05 + 4.95 = 1.00.
		$fees = (array) $cart->get_fees();
		$t::money( 1, isset( $fees['payment_method'] ) ? $fees['payment_method']['amount'] : 0, 'card fee' );
		$t::money( 51, $cart->get_total(), 'total 50 + 1' );
	}
);

/*
 * The placed order.
 */

$decimal_comma_ship_test(
	'order placed with taxed shipping typed as 4,95',
	function () use ( $t, $decimal_comma_ship_flat ) {
		$t::tax( 20 );
		$t::add( $t::product( 19.99 ), 2 );
		$decimal_comma_ship_flat( '4,95', 'cart', true );
		$t::checkout_address();
		$cart = $t::calculate();
		// Items 39.98, tax 7.996 = 8.00. Shipping 4.95, tax 0.99. Total 39.98 + 4.95 + 8.99.
		$t::money( 53.92, $cart->get_total(), 'cart total' );
		$order = $t::place_order();
		$t::money( 39.98, $order->get_subtotal(), 'order subtotal' );
		$t::money( 4.95, $order->get_shipping(), 'order shipping' );
		$t::money( 8.99, $order->get_tax(), 'order tax' );
		$t::money( 53.92, $order->get_total(), 'order total' );
	}
);

/*
 * A product's Extra Shipping Cost, typed on the product edit screen. It is added to the flat
 * rate, once for each item (see the Extra Shipping docs).
 */

foreach ( array( 'cart' => 'whole cart', '' => 'no price type saved' ) as $decimal_comma_ship_type => $decimal_comma_ship_label ) {
	$decimal_comma_ship_test(
		'Extra Shipping Cost typed as 2,50 (' . $decimal_comma_ship_label . ')',
		function () use ( $t, $decimal_comma_ship_flat, $decimal_comma_ship_extra_cost, $decimal_comma_ship_type ) {
			$canvas = $t::product( 20, array( 'taxable' => false ) );
			$decimal_comma_ship_extra_cost( $canvas, '2,50' );
			$t::add( $canvas, 2 );
			$decimal_comma_ship_flat( '5', $decimal_comma_ship_type );
			$cart = $t::calculate();
			// 5 + 2 x 2.50 = 10.
			$t::money( 10, $cart->get_shipping(), 'shipping' );
			$t::money( 50, $cart->get_total(), 'total 40 + 10' );
		}
	);
}

$decimal_comma_ship_test(
	'Extra Shipping Cost typed as 0,75 with a flat rate per item',
	function () use ( $t, $decimal_comma_ship_flat, $decimal_comma_ship_extra_cost ) {
		$canvas = $t::product( 10, array( 'taxable' => false ) );
		$decimal_comma_ship_extra_cost( $canvas, '0,75' );
		$t::add( $canvas, 4 );
		$decimal_comma_ship_flat( '1', 'qty' );
		$cart = $t::calculate();
		// 4 x 1 + 4 x 0.75 = 7.
		$t::money( 7, $cart->get_shipping(), 'shipping' );
		$t::money( 47, $cart->get_total(), 'total 40 + 7' );
	}
);

// No price type here: a saved price type with prices that include tax is already reported.
$decimal_comma_ship_test(
	'Extra Shipping Cost typed as 2,50, prices include tax',
	function () use ( $t, $decimal_comma_ship_flat, $decimal_comma_ship_extra_cost, $decimal_comma_ship_paid ) {
		$t::tax( 25 );
		$t::option( 'price_has_tax', 'yes' );
		$canvas = $t::product( 50 );
		$decimal_comma_ship_extra_cost( $canvas, '2,50' );
		$t::add( $canvas );
		$decimal_comma_ship_flat( '5', '', true );
		$cart = $t::calculate();
		// The customer pays 5 + 2.50 = 7.50 for shipping. Tax in it: 1.00 + 0.50.
		// Tax in the item: 50 - 50 / 1.25 = 10.
		$t::money( 7.50, $decimal_comma_ship_paid( $cart ), 'shipping paid' );
		$t::money( 1.50, $cart->get_shipping_tax(), 'tax in the shipping' );
		$t::money( 11.50, $cart->get_tax(), 'tax 10 + 1.50' );
		$t::money( 57.50, $cart->get_total(), 'total 50 + 7.50' );
	}
);

// The product screen takes the thousands separator out before saving, so this one is
// read right today. Kept so a fix for the cents does not break it.
$decimal_comma_ship_test(
	'Extra Shipping Cost typed as 1.250,00 for a large wall piece',
	function () use ( $t, $decimal_comma_ship_flat, $decimal_comma_ship_extra_cost ) {
		$mural = $t::product( 3000, array( 'taxable' => false ) );
		$decimal_comma_ship_extra_cost( $mural, '1.250,00' );
		$t::add( $mural );
		$decimal_comma_ship_flat( '15' );
		$cart = $t::calculate();
		$t::money( 1265, $cart->get_shipping(), 'shipping 15 + 1250' );
		$t::money( 4265, $cart->get_total(), 'total 3000 + 1265' );
	}
);

$decimal_comma_ship_test(
	'order placed with Extra Shipping Cost typed as 2,50',
	function () use ( $t, $decimal_comma_ship_flat, $decimal_comma_ship_extra_cost ) {
		$t::tax( 20 );
		$canvas = $t::product( 12.50 );
		$decimal_comma_ship_extra_cost( $canvas, '2,50' );
		$t::add( $canvas, 2 );
		$decimal_comma_ship_flat( '5', 'cart', true );
		$t::checkout_address();
		$cart = $t::calculate();
		// Items 25, tax 5. Shipping 5 + 2 x 2.50 = 10, tax 2. Total 25 + 10 + 7 = 42.
		$t::money( 42, $cart->get_total(), 'cart total' );
		$order = $t::place_order();
		$t::money( 10, $order->get_shipping(), 'order shipping' );
		$t::money( 7, $order->get_tax(), 'order tax' );
		$t::money( 42, $order->get_total(), 'order total' );
	}
);

// What the product edit screen shows in the Extra Shipping Cost boxes, as the browser sends it
// back when the photographer clicks Update without touching it.
$decimal_comma_ship_extra_shown = function ( $product_id ) {
	global $post;
	$saved_post = $post;
	$post       = get_post( $product_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$box        = ( new ReflectionClass( 'Sunshine_Admin_Meta_Boxes' ) )->newInstanceWithoutConstructor();
	ob_start();
	try {
		$box->display_field(
			array(
				'id'   => 'shipping',
				'name' => 'Extra Shipping Cost',
				'type' => 'price',
			)
		);
	} finally {
		$html = ob_get_clean();
		$post = $saved_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
	$shown = array();
	preg_match_all( '/name="shipping\[(\d+)\]"[^>]*value="([^"]*)"/', $html, $matches, PREG_SET_ORDER );
	foreach ( $matches as $match ) {
		$shown[ $match[1] ] = html_entity_decode( $match[2], ENT_QUOTES );
	}
	return $shown;
};

// The API's price level endpoint saves Extra Shipping Cost as a plain number with a dot (2.5).
// The product edit screen shows it as it is. Clicking Update must not change it: the screen's
// clean-up takes out the store's thousands separator, which is a dot here, so 2.5 would be
// saved as 25.
$decimal_comma_ship_test(
	'Extra Shipping Cost saved by code as 2.5 is kept when the product is saved again',
	function () use ( $t, $decimal_comma_ship_flat, $decimal_comma_ship_extra_shown ) {
		$canvas = $t::product( 20, array( 'taxable' => false ) );
		update_post_meta( $canvas, 'shipping', array( sunshine_get_default_price_level_id() => 2.5 ) );
		$t::add( $canvas, 2 );
		$decimal_comma_ship_flat( '5' );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_shipping(), 'before: 5 + 2 x 2.50' );

		$posted = $decimal_comma_ship_extra_shown( $canvas );
		$box    = ( new ReflectionClass( 'Sunshine_Admin_Meta_Boxes' ) )->newInstanceWithoutConstructor();
		$value  = apply_filters( 'sunshine_meta_shipping_validate', $box->sanitize( $posted, 'price' ) );
		delete_post_meta( $canvas, 'shipping' );
		update_post_meta( $canvas, 'shipping', $value );

		$cart = $t::new_request();
		$t::money( 10, $cart->get_shipping(), 'after saving the product again: 5 + 2 x 2.50' );
		$t::money( 50, $cart->get_total(), 'total 40 + 10' );
	}
);
