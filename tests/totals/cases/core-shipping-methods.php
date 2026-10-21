<?php
/**
 * Free shipping, free shipping from a discount code, Local Delivery and Pickup, carts with
 * items that are not shipped, and the chosen shipping being dropped when it no longer applies.
 */

$t = 'SPC_Totals_Test';

// Each time Sunshine builds a shipping or delivery method object, the object adds a filter
// that is never taken off again, so a long test run gets slower with every cart it works out
// (reported as a finding). When each test here ends, take off the ones it added, so these
// tests leave the run as they found it.
$shipmethods_test = function ( $name, $callback ) use ( $t ) {
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
$shipmethods_setup = function ( $methods ) use ( $t ) {
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

// The customer picks "Ship" and then a shipping method.
$shipmethods_ship_with = function ( $instance ) {
	SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
	SPC()->cart->set_checkout_data_item( 'shipping_method', $instance );
};

$shipmethods_extra_cost = function ( $product_id, $amount ) {
	update_post_meta( $product_id, 'shipping', array( sunshine_get_default_price_level_id() => (string) $amount ) );
};

$shipmethods_saved_choice = function () {
	return (string) SPC()->cart->get_checkout_data_item( 'shipping_method' );
};

// The shipping method the cart is using right now.
$shipmethods_chosen = function () {
	$method = SPC()->cart->get_shipping_method();
	return $method ? $method->get_instance_id() : '';
};

$shipmethods_flat_and_free = array(
	'spcshipflat' => array(
		'id'         => 'flat_rate',
		'price'      => '8',
		'price_type' => 'cart',
	),
	'spcshipfree' => array(
		'id' => 'free',
	),
);

// Free has no price field, and products' Extra Shipping Cost must not sneak a price into it.
$shipmethods_test(
	'Free shipping costs nothing, even with an extra shipping cost',
	function () use ( $t, $shipmethods_setup, $shipmethods_ship_with, $shipmethods_extra_cost, $shipmethods_flat_and_free, $shipmethods_chosen ) {
		$t::tax( 10 );
		$canvas = $t::product( 100 );
		$shipmethods_extra_cost( $canvas, 5 );
		$t::add( $canvas );
		$shipmethods_setup( $shipmethods_flat_and_free );
		$shipmethods_ship_with( 'spcshipfree' );
		$cart = $t::calculate();
		$t::same( 'spcshipfree', $shipmethods_chosen(), 'free shipping is chosen' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 0, $cart->get_shipping_tax(), 'shipping tax' );
		$t::money( 10, $cart->get_tax(), 'tax on the item' );
		$t::money( 110, $cart->get_total(), 'total' );
	}
);

// Free shipping set to "Via Discount", next to an $8 flat rate.
$shipmethods_free_by_code = function () use ( $shipmethods_setup, $shipmethods_flat_and_free ) {
	$methods                            = $shipmethods_flat_and_free;
	$methods['spcshipfree']['discount'] = '1';
	$shipmethods_setup( $methods );
};

$shipmethods_test(
	'Free shipping by discount code is not offered without the code',
	function () use ( $t, $shipmethods_free_by_code, $shipmethods_ship_with, $shipmethods_saved_choice ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$shipmethods_free_by_code();
		$shipmethods_ship_with( 'spcshipfree' );
		$cart = $t::calculate();
		$t::same( array( 'spcshipflat' ), array_keys( sunshine_get_allowed_shipping_methods() ), 'only the flat rate is offered' );
		$t::same( true, 'spcshipfree' !== $shipmethods_saved_choice(), 'free shipping is not kept as the choice' );
		$t::money( 8, $cart->get_shipping(), 'the flat rate is charged' );
		$t::money( 108, $cart->get_total(), 'total' );
	}
);

// A code that only gives free shipping has no discount type or amount (see the docs).
$shipmethods_test(
	'Free shipping by discount code',
	function () use ( $t, $shipmethods_free_by_code, $shipmethods_ship_with, $shipmethods_chosen ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$shipmethods_free_by_code();
		$t::discount( '', '', array( 'free_shipping' => '1' ) );
		$shipmethods_ship_with( 'spcshipfree' );
		$cart = $t::calculate();
		$t::same( 'spcshipfree', $shipmethods_chosen(), 'free shipping is chosen' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 0, $cart->get_discount(), 'the code takes nothing else off' );
		$t::money( 100, $cart->get_total(), 'total' );

		$cart = $t::new_request();
		$t::same( 'spcshipfree', $shipmethods_chosen(), 'free shipping is still chosen on the next page load' );
		$t::money( 0, $cart->get_shipping(), 'still free on the next page load' );
		$t::money( 100, $cart->get_total(), 'total on the next page load' );
	}
);

$shipmethods_test(
	'Free shipping by discount code with a percent off',
	function () use ( $t, $shipmethods_free_by_code, $shipmethods_ship_with, $shipmethods_chosen ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$shipmethods_free_by_code();
		$t::discount( 'percent-total', 10, array( 'free_shipping' => '1' ) );
		$shipmethods_ship_with( 'spcshipfree' );
		$cart = $t::calculate();
		$t::same( 'spcshipfree', $shipmethods_chosen(), 'free shipping is chosen' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 9, $cart->get_tax(), 'tax on 90' );
		$t::money( 99, $cart->get_total(), 'total' );
	}
);

// Taking the code off must not leave the customer with free shipping.
$shipmethods_test(
	'Removing the free shipping code brings back the shipping price',
	function () use ( $t, $shipmethods_free_by_code, $shipmethods_ship_with, $shipmethods_saved_choice, $shipmethods_chosen ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$shipmethods_free_by_code();
		$code = $t::discount( '', '', array( 'free_shipping' => '1' ) );
		$shipmethods_ship_with( 'spcshipfree' );
		$cart = $t::calculate();
		$t::same( 'spcshipfree', $shipmethods_chosen(), 'free shipping is chosen with the code' );
		$t::money( 0, $cart->get_shipping(), 'free with the code' );

		SPC()->cart->remove_discount( $code );
		$cart = $t::new_request();
		$t::same( true, 'spcshipfree' !== $shipmethods_saved_choice(), 'free shipping is no longer the choice' );
		$t::money( 8, $cart->get_shipping(), 'the flat rate is charged' );
		$t::money( 108, $cart->get_total(), 'total' );
	}
);

// Local Delivery: its own price, and (per the docs) no Extra Shipping Cost added.
$shipmethods_test(
	'Local delivery charges its own price, taxed, with no extra shipping cost',
	function () use ( $t, $shipmethods_setup, $shipmethods_ship_with, $shipmethods_extra_cost ) {
		$t::tax( 10 );
		$canvas = $t::product( 100 );
		$shipmethods_extra_cost( $canvas, 5 );
		$t::add( $canvas, 2 );
		$shipmethods_setup(
			array(
				'spcshiplocal' => array(
					'id'        => 'local',
					'price'     => '15',
					'taxable'   => '1',
					'postcodes' => '802*',
				),
			)
		);
		$t::checkout_address();
		$shipmethods_ship_with( 'spcshiplocal' );
		$cart = $t::calculate();
		$t::money( 15, $cart->get_shipping(), 'shipping' );
		$t::money( 1.50, $cart->get_shipping_tax(), 'shipping tax' );
		$t::money( 21.50, $cart->get_tax(), 'tax 20 + 1.50' );
		$t::money( 236.50, $cart->get_total(), 'total 200 + 15 + 21.50' );
	}
);

$shipmethods_test(
	'Local delivery, prices include tax',
	function () use ( $t, $shipmethods_setup, $shipmethods_ship_with ) {
		$t::tax( 7.25 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 10, array( 'taxable' => false ) ) );
		$shipmethods_setup(
			array(
				'spcshiplocal' => array(
					'id'      => 'local',
					'price'   => '9.99',
					'taxable' => '1',
				),
			)
		);
		$t::checkout_address();
		$shipmethods_ship_with( 'spcshiplocal' );
		$cart = $t::calculate();
		// 9.99 / 1.0725 = 9.31 before tax, so 0.68 of the 9.99 is tax.
		$t::money( 9.99, (float) $cart->get_shipping() + (float) $cart->get_shipping_tax(), 'shipping paid is the 9.99 entered' );
		$t::money( 0.68, $cart->get_tax(), 'tax' );
		$t::money( 19.99, $cart->get_total(), 'total' );
	}
);

$shipmethods_test(
	'Local delivery is dropped when the address moves out of its area',
	function () use ( $t, $shipmethods_setup, $shipmethods_ship_with, $shipmethods_saved_choice ) {
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$shipmethods_setup(
			array(
				'spcshipflat'  => array(
					'id'         => 'flat_rate',
					'price'      => '8',
					'price_type' => 'cart',
				),
				'spcshiplocal' => array(
					'id'        => 'local',
					'price'     => '3',
					'postcodes' => '80202',
				),
			)
		);
		$t::checkout_address();
		$shipmethods_ship_with( 'spcshiplocal' );
		$cart = $t::calculate();
		$t::money( 3, $cart->get_shipping(), 'local delivery inside the area' );

		SPC()->cart->set_checkout_data_item( 'shipping_postcode', '90210' );
		$cart = $t::new_request();
		$t::same( true, 'spcshiplocal' !== $shipmethods_saved_choice(), 'local delivery is no longer the choice' );
		$t::money( 8, $cart->get_shipping(), 'the flat rate is charged' );
		$t::money( 58, $cart->get_total(), 'total' );
	}
);

// The Advanced Shipping add-on adds its Price Type setting to every shipping method, and the
// Local Delivery docs list "charge per cart, per line item, or per image" as one of its options.
$shipmethods_test(
	'Local delivery priced per line or per item',
	function () use ( $t, $shipmethods_setup, $shipmethods_ship_with ) {
		$t::require_plugin( 'sunshine_advanced_shipping_options', 'Advanced Shipping add-on' );
		$t::add( $t::product( 10, array( 'taxable' => false ) ), 2 );
		$t::add( $t::product( 10, array( 'taxable' => false ) ), 3 );
		$shipmethods_setup(
			array(
				'spcshiplocalline' => array(
					'id'         => 'local',
					'price'      => '2',
					'price_type' => 'line',
				),
				'spcshiplocalqty'  => array(
					'id'         => 'local',
					'price'      => '2',
					'price_type' => 'qty',
				),
			)
		);
		$t::checkout_address();
		$shipmethods_ship_with( 'spcshiplocalline' );
		$cart = $t::calculate();
		$t::money( 4, $cart->get_shipping(), 'per line: 2 lines x 2' );
		$t::money( 54, $cart->get_total(), 'per line total' );

		$shipmethods_ship_with( 'spcshiplocalqty' );
		$cart = $t::new_request();
		$t::money( 10, $cart->get_shipping(), 'per item: 5 items x 2' );
		$t::money( 60, $cart->get_total(), 'per item total' );
	}
);

// Pickup gets the same Price Type setting, so a fee set per item has to count the items
// (a studio charging a packing fee per print), not charge it once.
$shipmethods_test(
	'Pickup priced per item',
	function () use ( $t, $shipmethods_setup ) {
		$t::require_plugin( 'sunshine_advanced_shipping_options', 'Advanced Shipping add-on' );
		$t::add( $t::product( 10, array( 'taxable' => false ) ), 2 );
		$t::add( $t::product( 10, array( 'taxable' => false ) ), 3 );
		$shipmethods_setup(
			array(
				'spcshippickupqty' => array(
					'id'         => 'pickup',
					'price'      => '1',
					'price_type' => 'qty',
					'location'   => '1 Studio Lane',
				),
			)
		);
		SPC()->cart->set_delivery_method( 'spcshippickupqty' );
		$cart = $t::calculate();
		$t::same( 'pickup', SPC()->cart->get_delivery_method_id(), 'delivery method' );
		$t::money( 5, $cart->get_shipping(), 'per item: 5 items x 1' );
		$t::money( 55, $cart->get_total(), 'total 50 + 5' );
	}
);

// Pickup: its own fee, and since 3.7.3 no Extra Shipping Cost added.
$shipmethods_flat_and_pickup = function ( $pickup_price, $pickup_taxable = false ) use ( $shipmethods_setup ) {
	$shipmethods_setup(
		array(
			'spcshipflat'   => array(
				'id'         => 'flat_rate',
				'price'      => '10',
				'price_type' => 'cart',
				'taxable'    => '1',
			),
			'spcshippickup' => array(
				'id'       => 'pickup',
				'price'    => (string) $pickup_price,
				'taxable'  => $pickup_taxable ? '1' : '',
				'location' => '1 Studio Lane',
			),
		)
	);
};

$shipmethods_test(
	'Pickup charges its own fee, taxed, with no extra shipping cost',
	function () use ( $t, $shipmethods_flat_and_pickup, $shipmethods_extra_cost ) {
		$t::tax( 10 );
		$canvas = $t::product( 100 );
		$shipmethods_extra_cost( $canvas, 5 );
		$t::add( $canvas );
		$shipmethods_flat_and_pickup( 5, true );
		SPC()->cart->set_delivery_method( 'spcshippickup' );
		$cart = $t::calculate();
		$t::same( 'pickup', SPC()->cart->get_delivery_method_id(), 'delivery method' );
		$t::money( 5, $cart->get_shipping(), 'pickup fee' );
		$t::money( 0.50, $cart->get_shipping_tax(), 'tax on the fee' );
		$t::money( 10.50, $cart->get_tax(), 'tax 10 + 0.50' );
		$t::money( 115.50, $cart->get_total(), 'total' );
	}
);

$shipmethods_test(
	'Free pickup stays free with large items',
	function () use ( $t, $shipmethods_flat_and_pickup, $shipmethods_extra_cost, $shipmethods_chosen ) {
		$canvas = $t::product( 40, array( 'taxable' => false ) );
		$shipmethods_extra_cost( $canvas, 5 );
		$t::add( $canvas, 2 );
		$shipmethods_flat_and_pickup( 0 );
		SPC()->cart->set_delivery_method( 'spcshippickup' );
		$cart = $t::calculate();
		$t::same( 'spcshippickup', $shipmethods_chosen(), 'pickup is chosen' );
		$t::money( 0, $cart->get_shipping(), 'pickup' );
		$t::money( 80, $cart->get_total(), 'total' );
	}
);

// Pickup can be added once per location, each with its own price.
$shipmethods_test(
	'Each pickup location charges its own fee',
	function () use ( $t, $shipmethods_setup, $shipmethods_chosen ) {
		$t::add( $t::product( 40, array( 'taxable' => false ) ) );
		$shipmethods_setup(
			array(
				'spcshipstudio' => array(
					'id'       => 'pickup',
					'price'    => '0',
					'location' => '1 Studio Lane',
				),
				'spcshipmall'   => array(
					'id'       => 'pickup',
					'price'    => '7.50',
					'location' => 'Riverside Mall',
				),
			)
		);
		SPC()->cart->set_delivery_method( 'spcshipmall' );
		$cart = $t::calculate();
		$t::same( 'spcshipmall', $shipmethods_chosen(), 'mall pickup is chosen' );
		$t::money( 7.50, $cart->get_shipping(), 'mall pickup fee' );
		$t::money( 47.50, $cart->get_total(), 'total at the mall' );

		SPC()->cart->set_delivery_method( 'spcshipstudio' );
		$cart = $t::calculate();
		$t::same( 'spcshipstudio', $shipmethods_chosen(), 'studio pickup is chosen' );
		$t::money( 0, $cart->get_shipping(), 'studio pickup is free' );
		$t::money( 40, $cart->get_total(), 'total at the studio' );
	}
);

// Something collected in person is taxed at the store's rate, not where the customer lives.
// Store in California (10%), customer in Colorado (5%).
$shipmethods_test(
	'Switching between shipping and pickup changes shipping and tax',
	function () use ( $t, $shipmethods_flat_and_pickup, $shipmethods_chosen ) {
		$t::option( 'state', 'CA' );
		$t::option( 'taxes_enabled', 1 );
		$t::option(
			'tax_rates',
			array(
				array(
					'country'  => 'US',
					'state'    => 'CA',
					'postcode' => '',
					'rate'     => '10',
				),
				array(
					'country'  => 'US',
					'state'    => 'CO',
					'postcode' => '',
					'rate'     => '5',
				),
			)
		);
		$t::add( $t::product( 100 ) );
		$shipmethods_flat_and_pickup( 0 );
		$t::checkout_address();
		SPC()->cart->set_delivery_method( 'shipping' );
		SPC()->cart->set_shipping_method( 'spcshipflat' );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_shipping(), 'shipped: shipping' );
		$t::money( 5.50, $cart->get_tax(), 'shipped: Colorado tax on 110' );
		$t::money( 115.50, $cart->get_total(), 'shipped: total' );

		SPC()->cart->set_delivery_method( 'spcshippickup' );
		$cart = $t::calculate();
		$t::same( 'spcshippickup', $shipmethods_chosen(), 'pickup is chosen' );
		$t::money( 0, $cart->get_shipping(), 'pickup: no shipping' );
		$t::money( 10, $cart->get_tax(), 'pickup: California tax on 100' );
		$t::money( 110, $cart->get_total(), 'pickup: total' );

		SPC()->cart->set_delivery_method( 'shipping' );
		SPC()->cart->set_shipping_method( 'spcshipflat' );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_shipping(), 'shipped again: shipping' );
		$t::money( 115.50, $cart->get_total(), 'shipped again: total' );
	}
);

$shipmethods_test(
	'Only downloads in the cart: no shipping is charged',
	function () use ( $t, $shipmethods_setup, $shipmethods_ship_with, $shipmethods_saved_choice ) {
		$shipmethods_setup(
			array(
				'spcshipflat' => array(
					'id'         => 'flat_rate',
					'price'      => '10',
					'price_type' => 'cart',
				),
			)
		);
		$shipmethods_ship_with( 'spcshipflat' );
		$t::add( $t::product( 20, array( 'type' => 'download', 'taxable' => false ) ) );
		$cart = $t::calculate();
		$t::same( false, $cart->needs_shipping(), 'the cart does not need shipping' );
		$t::same( '', $shipmethods_saved_choice(), 'no shipping method is kept' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 20, $cart->get_total(), 'total' );
	}
);

$shipmethods_test(
	'Only items with shipping turned off: no shipping is charged',
	function () use ( $t, $shipmethods_setup, $shipmethods_ship_with ) {
		$shipmethods_setup(
			array(
				'spcshipflat' => array(
					'id'         => 'flat_rate',
					'price'      => '10',
					'price_type' => 'cart',
				),
			)
		);
		$shipmethods_ship_with( 'spcshipflat' );
		$no_ship = $t::product( 25, array( 'taxable' => false ) );
		update_post_meta( $no_ship, 'disable_shipping', '1' );
		$t::add( $no_ship, 2 );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 50, $cart->get_total(), 'total' );
	}
);

$shipmethods_test(
	'A download and a print together: taxed shipping is charged once',
	function () use ( $t, $shipmethods_setup, $shipmethods_ship_with ) {
		$t::tax( 10 );
		$shipmethods_setup(
			array(
				'spcshipflat' => array(
					'id'         => 'flat_rate',
					'price'      => '10',
					'price_type' => 'cart',
					'taxable'    => '1',
				),
			)
		);
		$shipmethods_ship_with( 'spcshipflat' );
		$t::add( $t::product( 20, array( 'type' => 'download' ) ) );
		$t::add( $t::product( 10 ) );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_shipping(), 'shipping' );
		$t::money( 4, $cart->get_tax(), 'tax on 20 + 10 + 10' );
		$t::money( 44, $cart->get_total(), 'total' );
	}
);

$shipmethods_test(
	'Removing the last shipped item removes the shipping',
	function () use ( $t, $shipmethods_setup, $shipmethods_ship_with, $shipmethods_saved_choice ) {
		$shipmethods_setup(
			array(
				'spcshipflat' => array(
					'id'         => 'flat_rate',
					'price'      => '10',
					'price_type' => 'cart',
				),
			)
		);
		$shipmethods_ship_with( 'spcshipflat' );
		$t::add( $t::product( 20, array( 'type' => 'download', 'taxable' => false ) ) );
		$print = $t::add( $t::product( 10, array( 'taxable' => false ) ) );
		$cart  = $t::calculate();
		$t::money( 10, $cart->get_shipping(), 'shipping with the print' );

		// What the cart page's remove link does.
		foreach ( SPC()->cart->get_cart() as $key => $line ) {
			if ( $line['hash'] === $print['hash'] ) {
				SPC()->cart->remove_item( $key );
			}
		}
		$cart = $t::new_request();
		$t::same( '', $shipmethods_saved_choice(), 'no shipping method is kept' );
		$t::money( 0, $cart->get_shipping(), 'shipping after removing the print' );
		$t::money( 20, $cart->get_total(), 'total' );
	}
);

$shipmethods_test(
	'Per item shipping follows quantity changes',
	function () use ( $t, $shipmethods_setup, $shipmethods_ship_with ) {
		$shipmethods_setup(
			array(
				'spcshipqty' => array(
					'id'         => 'flat_rate',
					'price'      => '2',
					'price_type' => 'qty',
				),
			)
		);
		$shipmethods_ship_with( 'spcshipqty' );
		$line = $t::add( $t::product( 10, array( 'taxable' => false ) ), 2 );
		$cart = $t::calculate();
		$t::money( 4, $cart->get_shipping(), '2 items x 2' );

		SPC()->cart->update_item_quantity_by_hash( $line['hash'], 5 );
		$cart = $t::new_request();
		$t::money( 10, $cart->get_shipping(), '5 items x 2' );
		$t::money( 60, $cart->get_total(), 'total 50 + 10' );
	}
);

// Every cart work-out looks shipping and delivery methods up again, and each lookup builds a new
// method object. If each new object hooks itself on again, the hook lists grow without end and
// every later lookup does the same work once more (a long run got twice as slow). Looking methods
// up many times must leave the hook lists the size they were.
$shipmethods_test(
	'Looking up shipping and delivery methods does not add hooks every time',
	function () use ( $t, $shipmethods_setup, $shipmethods_flat_and_free, $shipmethods_ship_with ) {
		global $wp_filter;
		$hooks = array( 'sunshine_shipping_methods', 'sunshine_checkout_delivery_options', 'sunshine_delivery_methods', 'sunshine_options_shipping' );
		$count = function () use ( $hooks ) {
			global $wp_filter;
			$total = 0;
			foreach ( $hooks as $hook ) {
				if ( ! empty( $wp_filter[ $hook ] ) ) {
					foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
						$total += count( $callbacks );
					}
				}
			}
			return $total;
		};
		$t::add( $t::product( 10, array( 'taxable' => false ) ) );
		$shipmethods_setup( $shipmethods_flat_and_free );
		$shipmethods_ship_with( 'spcshipflat' );
		// Warm up: the first lookup of each class may hook it on once.
		$t::calculate();
		sunshine_get_delivery_method_by_id( 'shipping' );
		$before = $count();
		for ( $i = 0; $i < 10; $i++ ) {
			sunshine_get_shipping_method_by_instance( 'spcshipflat' );
			sunshine_get_shipping_method_by_instance( 'spcshipfree' );
			sunshine_get_delivery_method_by_id( 'shipping' );
			$cart = $t::calculate();
		}
		$t::same( $before, $count(), 'hooks on the shipping and delivery method filters' );
		$t::money( 18, $cart->get_total(), 'the flat rate is still charged' );
	}
);
