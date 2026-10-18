<?php
/**
 * Flat rate shipping: the price types (whole cart, per line, per item), products' Extra
 * Shipping Cost, and tax on shipping with prices that include or leave out tax.
 *
 * Shipping methods are set up the way Sunshine > Settings > Delivery & Shipping saves them:
 * a list of instances in "shipping_methods", plus one option per setting named
 * {method}_{setting}_{instance}. With the Advanced Shipping add-on active, saving a flat rate
 * stores a price type of "cart", "line" or "qty". Without it no price type is saved at all.
 */

$t = 'SPC_Totals_Test';

// Each time Sunshine builds a shipping or delivery method object, the object adds a filter
// that is never taken off again, so a long test run gets slower with every cart it works out
// (reported as a finding). When each test here ends, take off the ones it added, so these
// tests leave the run as they found it.
$shipping_test = function ( $name, $callback ) use ( $t ) {
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

// Turn on shipping methods. $methods is instance => array( 'id' => method, setting => value ).
$shipping_setup = function ( $methods ) use ( $t ) {
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
$shipping_flat = function ( $price, $price_type = 'cart', $taxable = false ) use ( $shipping_setup ) {
	$shipping_setup(
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

// A product's Extra Shipping Cost is saved per price level, like its price.
$shipping_extra_cost = function ( $product_id, $amount, $price_level_id = 0 ) {
	if ( ! $price_level_id ) {
		$price_level_id = sunshine_get_default_price_level_id();
	}
	$costs = get_post_meta( $product_id, 'shipping', true );
	if ( ! is_array( $costs ) ) {
		$costs = array();
	}
	$costs[ $price_level_id ] = (string) $amount;
	update_post_meta( $product_id, 'shipping', $costs );
};

// What the customer pays for shipping, tax included.
$shipping_paid = function ( $cart ) {
	return (float) $cart->get_shipping() + (float) $cart->get_shipping_tax();
};

// The cart from the Advanced Shipping docs: 3 lines holding 5 items.
$shipping_doc_cart = function () use ( $t ) {
	$t::add( $t::product( 10, array( 'taxable' => false ) ), 1 );
	$t::add( $t::product( 10, array( 'taxable' => false ) ), 2 );
	$t::add( $t::product( 10, array( 'taxable' => false ) ), 2 );
};

// A store without Advanced Shipping saves no price type, and should charge the same as "cart".
foreach ( array( 'cart' => 'whole cart', '' => 'no price type saved' ) as $shipping_type => $shipping_label ) {
	$shipping_test(
		'Flat rate: one price for the whole cart (' . $shipping_label . ')',
		function () use ( $t, $shipping_flat, $shipping_doc_cart, $shipping_type ) {
			$shipping_doc_cart();
			$shipping_flat( 5, $shipping_type );
			$cart = $t::calculate();
			$t::money( 5, $cart->get_shipping(), 'shipping is 5 once' );
			$t::money( 55, $cart->get_total(), 'total 50 + 5' );
		}
	);
}

$shipping_test(
	'Flat rate: price for each line in the cart',
	function () use ( $t, $shipping_flat, $shipping_doc_cart ) {
		$shipping_doc_cart();
		$shipping_flat( 5, 'line' );
		$cart = $t::calculate();
		$t::money( 15, $cart->get_shipping(), 'shipping is 3 lines x 5' );
		$t::money( 65, $cart->get_total(), 'total 50 + 15' );
	}
);

$shipping_test(
	'Flat rate: price for each item in the cart',
	function () use ( $t, $shipping_flat, $shipping_doc_cart ) {
		$shipping_doc_cart();
		$shipping_flat( 5, 'qty' );
		$cart = $t::calculate();
		$t::money( 25, $cart->get_shipping(), 'shipping is 5 items x 5' );
		$t::money( 75, $cart->get_total(), 'total 50 + 25' );
	}
);

// Per line and per item prices only count things that are actually shipped.
$shipping_test(
	'Flat rate per line or per item skips items that are not shipped',
	function () use ( $t, $shipping_setup ) {
		$print = $t::product( 10, array( 'taxable' => false ) );
		$t::add( $print, 1 );
		$t::add( $t::product( 10, array( 'taxable' => false ) ), 3 );
		$t::add( $t::product( 20, array( 'type' => 'download', 'taxable' => false ) ), 1 );
		$no_ship = $t::product( 10, array( 'taxable' => false ) );
		update_post_meta( $no_ship, 'disable_shipping', '1' );
		$t::add( $no_ship, 2 );

		$shipping_setup(
			array(
				'spcshipline' => array(
					'id'         => 'flat_rate',
					'price'      => '4',
					'price_type' => 'line',
				),
				'spcshipqty'  => array(
					'id'         => 'flat_rate',
					'price'      => '4',
					'price_type' => 'qty',
				),
			)
		);
		SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
		SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcshipline' );
		$cart = $t::calculate();
		// Items: 10 + 30 + 20 + 20 = 80.
		$t::money( 8, $cart->get_shipping(), 'per line: 2 shipped lines x 4' );
		$t::money( 88, $cart->get_total(), 'per line total' );

		SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcshipqty' );
		$cart = $t::new_request();
		$t::money( 16, $cart->get_shipping(), 'per item: 4 shipped items x 4' );
		$t::money( 96, $cart->get_total(), 'per item total' );
	}
);

// A big order: 300 prints, with shipping charged per item and taxed.
$shipping_test(
	'Flat rate per item with a large quantity and taxed shipping',
	function () use ( $t, $shipping_flat ) {
		$t::tax( 7.25 );
		$t::add( $t::product( 1, array( 'taxable' => false ) ), 300 );
		$shipping_flat( 0.35, 'qty', true );
		$cart = $t::calculate();
		// 300 x 0.35 = 105. Tax 105 x 7.25% = 7.6125.
		$t::money( 105, $cart->get_shipping(), 'shipping' );
		$t::money( 7.61, $cart->get_shipping_tax(), 'shipping tax' );
		$t::money( 7.61, $cart->get_tax(), 'tax' );
		$t::money( 412.61, $cart->get_total(), 'total 300 + 105 + 7.61' );
	}
);

$shipping_test(
	'Flat rate of zero adds no shipping and no tax',
	function () use ( $t, $shipping_flat ) {
		$t::tax( 10 );
		$t::add( $t::product( 20 ) );
		$shipping_flat( 0, 'cart', true );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 0, $cart->get_shipping_tax(), 'shipping tax' );
		$t::money( 2, $cart->get_tax(), 'tax on the item only' );
		$t::money( 22, $cart->get_total(), 'total' );
	}
);

// From the Extra Shipping Cost docs: a $3 flat rate, plus $2 for each canvas, is one $7 price.
foreach ( array( 'cart' => 'whole cart', '' => 'no price type saved' ) as $shipping_type => $shipping_label ) {
	$shipping_test(
		'Extra shipping cost is added for each item (' . $shipping_label . ')',
		function () use ( $t, $shipping_flat, $shipping_extra_cost, $shipping_type ) {
			$canvas = $t::product( 40, array( 'taxable' => false ) );
			$shipping_extra_cost( $canvas, 2 );
			$t::add( $canvas, 2 );
			$t::add( $t::product( 10, array( 'taxable' => false ) ), 1 );
			$shipping_flat( 3, $shipping_type );
			$cart = $t::calculate();
			$t::money( 7, $cart->get_shipping(), 'shipping 3 + 2 x 2' );
			$t::money( 97, $cart->get_total(), 'total 90 + 7' );
		}
	);
}

// The extra cost belongs to the item, so it is added once per canvas whatever the price type.
$shipping_test(
	'Extra shipping cost with per line and per item prices',
	function () use ( $t, $shipping_setup, $shipping_extra_cost ) {
		$canvas = $t::product( 40, array( 'taxable' => false ) );
		$shipping_extra_cost( $canvas, 2 );
		$t::add( $canvas, 2 );
		$t::add( $t::product( 10, array( 'taxable' => false ) ), 1 );
		$shipping_setup(
			array(
				'spcshipline' => array(
					'id'         => 'flat_rate',
					'price'      => '3',
					'price_type' => 'line',
				),
				'spcshipqty'  => array(
					'id'         => 'flat_rate',
					'price'      => '3',
					'price_type' => 'qty',
				),
			)
		);
		SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
		SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcshipline' );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_shipping(), 'per line: 2 lines x 3 + 2 canvases x 2' );

		SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcshipqty' );
		$cart = $t::new_request();
		$t::money( 13, $cart->get_shipping(), 'per item: 3 items x 3 + 2 canvases x 2' );
	}
);

// Each price level has its own Extra Shipping Cost, like its own price.
$shipping_test(
	'Extra shipping cost comes from the price level the item was added at',
	function () use ( $t, $shipping_flat, $shipping_extra_cost ) {
		$default_level = sunshine_get_default_price_level_id();
		$other_level   = 0;
		foreach ( (array) sunshine_get_price_levels() as $level ) {
			if ( $level->get_id() != $default_level ) {
				$other_level = $level->get_id();
				break;
			}
		}
		if ( ! $other_level ) {
			$t::skip( 'needs a second price level' );
		}
		$canvas = $t::product( 40, array( 'taxable' => false ) );
		update_post_meta(
			$canvas,
			'price',
			array(
				$default_level => '40',
				$other_level   => '50',
			)
		);
		$shipping_extra_cost( $canvas, 2, $default_level );
		$shipping_extra_cost( $canvas, 6, $other_level );
		$t::add( $canvas, 1 );
		if ( ! SPC()->cart->add_item( $canvas, 0, '', $other_level, array(), 1 ) ) {
			throw new Exception( 'Could not add the product at the second price level' );
		}
		$shipping_flat( 3 );
		$cart = $t::calculate();
		$t::money( 11, $cart->get_shipping(), 'shipping 3 + 2 + 6' );
		$t::money( 101, $cart->get_total(), 'total 40 + 50 + 11' );
	}
);

// An item that isn't shipped can't cost anything to ship. "Disable Shipping" hides the Extra
// Shipping Cost field in the product editor but keeps any amount typed there before, and
// downloads still show the field.
foreach ( array( 'cart' => 'whole cart', '' => 'no price type saved' ) as $shipping_type => $shipping_label ) {
	$shipping_test(
		'Extra shipping cost on an item that is not shipped is not charged (' . $shipping_label . ')',
		function () use ( $t, $shipping_flat, $shipping_extra_cost, $shipping_type ) {
			$t::add( $t::product( 10, array( 'taxable' => false ) ) );
			$no_ship = $t::product( 30, array( 'taxable' => false ) );
			update_post_meta( $no_ship, 'disable_shipping', '1' );
			$shipping_extra_cost( $no_ship, 5 );
			$t::add( $no_ship );
			$download = $t::product( 20, array( 'type' => 'download', 'taxable' => false ) );
			$shipping_extra_cost( $download, 5 );
			$t::add( $download );
			$shipping_flat( 3, $shipping_type );
			$cart = $t::calculate();
			$t::money( 3, $cart->get_shipping(), 'only the flat rate' );
			$t::money( 63, $cart->get_total(), 'total 60 + 3' );
		}
	);
}

$shipping_test(
	'Taxed shipping, prices without tax',
	function () use ( $t, $shipping_flat ) {
		$t::tax( 7.25 );
		$t::add( $t::product( 40 ) );
		$shipping_flat( 9.99, 'cart', true );
		$cart = $t::calculate();
		// Items 40 x 7.25% = 2.90. Shipping 9.99 x 7.25% = 0.7243.
		$t::money( 9.99, $cart->get_shipping(), 'shipping' );
		$t::money( 0.72, $cart->get_shipping_tax(), 'shipping tax' );
		$t::money( 3.62, $cart->get_tax(), 'tax 2.90 + 0.72' );
		$t::money( 53.61, $cart->get_total(), 'total' );
	}
);

// When prices include tax, the customer pays exactly the shipping price that was entered,
// and the tax is the part of it that is tax.
foreach ( array( 'cart' => 'whole cart', '' => 'no price type saved' ) as $shipping_type => $shipping_label ) {
	$shipping_test(
		'Taxed shipping, prices include tax (' . $shipping_label . ')',
		function () use ( $t, $shipping_flat, $shipping_paid, $shipping_type ) {
			$t::tax( 7.25 );
			$t::option( 'price_has_tax', 'yes' );
			$t::add( $t::product( 42.90 ) );
			$shipping_flat( 9.99, $shipping_type, true );
			$cart = $t::calculate();
			// 9.99 / 1.0725 = 9.31 before tax, so 0.68 of the 9.99 is tax.
			$t::money( 9.99, $shipping_paid( $cart ), 'shipping paid is the 9.99 entered' );
			$t::money( 0.68, $cart->get_shipping_tax(), 'tax in the shipping' );
			$t::money( 3.58, $cart->get_tax(), 'tax 2.90 + 0.68' );
			$t::money( 52.89, $cart->get_total(), 'total 42.90 + 9.99' );
		}
	);
}

$shipping_test(
	'Taxed shipping per item, prices include tax',
	function () use ( $t, $shipping_flat, $shipping_paid ) {
		$t::tax( 7.25 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 10, array( 'taxable' => false ) ), 3 );
		$shipping_flat( 1.99, 'qty', true );
		$cart = $t::calculate();
		// 3 x 1.99 = 5.97 paid. Tax in it: 5.97 - 5.97 / 1.0725 = 0.40.
		$t::money( 5.97, $shipping_paid( $cart ), 'shipping paid is 3 x 1.99' );
		$t::money( 0.40, $cart->get_tax(), 'tax' );
		$t::money( 35.97, $cart->get_total(), 'total 30 + 5.97' );
	}
);

foreach ( array( 'cart' => 'whole cart', '' => 'no price type saved' ) as $shipping_type => $shipping_label ) {
	$shipping_test(
		'Extra shipping cost, prices include tax (' . $shipping_label . ')',
		function () use ( $t, $shipping_flat, $shipping_extra_cost, $shipping_paid, $shipping_type ) {
			$t::tax( 10 );
			$t::option( 'price_has_tax', 'yes' );
			$canvas = $t::product( 22 );
			$shipping_extra_cost( $canvas, 2.20 );
			$t::add( $canvas, 2 );
			$shipping_flat( 5, $shipping_type, true );
			$cart = $t::calculate();
			// Shipping 5 + 2 x 2.20 = 9.40, of which 0.45 + 2 x 0.20 = 0.85 is tax.
			$t::money( 9.40, $shipping_paid( $cart ), 'shipping paid' );
			$t::money( 0.85, $cart->get_shipping_tax(), 'tax in the shipping' );
			$t::money( 4.85, $cart->get_tax(), 'tax 4 + 0.85' );
			$t::money( 53.40, $cart->get_total(), 'total 44 + 9.40' );
		}
	);
}

$shipping_test(
	'Shipping that is not taxed, prices include tax',
	function () use ( $t, $shipping_flat, $shipping_paid ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$shipping_flat( 10, 'cart', false );
		$cart = $t::calculate();
		$t::money( 10, $shipping_paid( $cart ), 'shipping paid' );
		$t::money( 0, $cart->get_shipping_tax(), 'no tax in the shipping' );
		$t::money( 10, $cart->get_tax(), 'tax in the item only' );
		$t::money( 120, $cart->get_total(), 'total' );
	}
);
