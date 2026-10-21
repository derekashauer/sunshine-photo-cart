<?php
/**
 * Packages add-on: the cart math.
 *
 * A package is one product sold at its own set price for a group of items, for example two
 * 8x10 prints and a digital download for $99. The cart charges the package price, never the
 * prices of what is inside. Tax uses the package's own Taxable box. Discount codes, gift cards,
 * store credit and payment fees treat the package like any other product at that price.
 * Shipping looks inside: a package of prints is shipped, a package of downloads is not.
 *
 * Packages are made the way the product edit screen saves them: type "package", a price for
 * every price level (blank where none is set), an extra shipping cost for every level, and the
 * "package" list of { product, qty } rows saved as strings.
 */

$t = 'SPC_Totals_Test';

// Every test here runs with outgoing requests and mail blocked. Adding to the cart also writes
// to the Analytics activity log and the automated email queue; those are switched off while a
// test runs so nothing is left behind in shared tables. Neither touches the money math.
$packages_test = function ( $name, $callback ) use ( $t ) {
	$t::test(
		$name,
		function () use ( $t, $callback ) {
			$t::require_plugin( 'sunshine_packages_init', 'Packages add-on' );
			$block_http = function () {
				return new WP_Error( 'spc_totals_blocked', 'Outgoing requests are blocked in the totals tests' );
			};
			add_filter( 'pre_http_request', $block_http, 1 );
			add_filter( 'pre_wp_mail', '__return_false', 1 );
			$quiet = array();
			$hooks = array(
				'sunshine_add_cart_item' => array( 'sunshine_adv_reports_add_to_cart', 'sunshine_email_queue_after_cart' ),
				'sunshine_cart_update'   => array( 'sunshine_adv_reports_cart_update' ),
			);
			foreach ( $hooks as $hook => $callbacks ) {
				foreach ( $callbacks as $hook_callback ) {
					$priority = has_action( $hook, $hook_callback );
					if ( false !== $priority ) {
						remove_action( $hook, $hook_callback, $priority );
						$quiet[] = array( $hook, $hook_callback, $priority );
					}
				}
			}
			try {
				$callback();
			} finally {
				remove_filter( 'pre_http_request', $block_http, 1 );
				remove_filter( 'pre_wp_mail', '__return_false', 1 );
				foreach ( $quiet as $hook ) {
					add_action( $hook[0], $hook[1], $hook[2] );
				}
			}
		}
	);
};

/**
 * Make a package product.
 *
 * $contents is a list of array( product_id, qty ).
 * $args: taxable, disable_shipping, shipping (extra shipping cost), prices (price level id => price),
 * qty_discount, category (term id).
 */
$packages_make = function ( $price, $contents, $args = array() ) use ( $t ) {
	$args = wp_parse_args(
		$args,
		array(
			'name'             => 'Totals test package',
			'taxable'          => true,
			'disable_shipping' => false,
			'shipping'         => '',
			'prices'           => array(),
			'qty_discount'     => '',
			'category'         => 0,
		)
	);
	$id   = $t::product(
		$price,
		array(
			'name'         => $args['name'],
			'type'         => 'package',
			'taxable'      => $args['taxable'],
			'qty_discount' => $args['qty_discount'],
		)
	);

	$rows = array();
	foreach ( $contents as $row ) {
		$rows[] = array(
			'product' => (string) $row[0],
			'qty'     => (string) $row[1],
		);
	}
	update_post_meta( $id, 'package', $rows );
	update_post_meta( $id, 'allowed_sources', '' );
	update_post_meta( $id, 'disable_shipping', $args['disable_shipping'] ? '1' : '' );

	// Price and extra shipping are saved for every price level, blank where none is set.
	$default_level = sunshine_get_default_price_level_id();
	$prices        = array();
	$shipping      = array();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		$level_id = $level->get_id();
		if ( isset( $args['prices'][ $level_id ] ) ) {
			$prices[ $level_id ] = (string) $args['prices'][ $level_id ];
		} else {
			$prices[ $level_id ] = ( $level_id == $default_level ) ? (string) $price : '';
		}
		$shipping[ $level_id ] = ( $level_id == $default_level ) ? (string) $args['shipping'] : '';
	}
	update_post_meta( $id, 'price', $prices );
	update_post_meta( $id, 'shipping', $shipping );

	if ( $args['category'] ) {
		wp_set_object_terms( $id, array( (int) $args['category'] ), 'sunshine-product-category' );
	}
	return $id;
};

// A product category of our own, so category codes don't depend on the store's categories.
$packages_category = function () use ( $t ) {
	$term = wp_insert_term( 'Totals test packages ' . wp_rand( 100000, 999999 ), 'sunshine-product-category' );
	if ( is_wp_error( $term ) ) {
		throw new Exception( 'Could not make a product category: ' . $term->get_error_message() );
	}
	$t::track( 'term', $term['term_id'], 'sunshine-product-category' );
	return $term['term_id'];
};

$packages_gift_card_used = function () {
	return array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) );
};

// A typical package: two 8x10 prints at $45 each and a $50 digital download, $140 if bought
// one at a time.
$packages_typical = function ( $price, $args = array() ) use ( $t, $packages_make ) {
	$print    = $t::product( 45, array( 'name' => 'Totals test 8x10' ) );
	$download = $t::product( 50, array( 'name' => 'Totals test download', 'type' => 'download', 'taxable' => false ) );
	return $packages_make( $price, array( array( $print, 2 ), array( $download, 1 ) ), $args );
};

/*
 * Price and tax.
 */

$packages_test(
	'Package: costs the package price, not the price of what is inside',
	function () use ( $t, $packages_typical ) {
		$package = $packages_typical( 99 );
		$t::add( $package );
		$cart = $t::calculate();
		$t::same( 1, count( $cart->get_cart_items() ), 'one line' );
		$t::same( 1, $cart->get_item_count(), 'the package counts as one item' );
		$lines = $cart->get_cart_items();
		$t::money( 99, $lines[0]->get_price(), 'line price' );
		// The items inside would be 45 x 2 + 50 = 140 on their own.
		$t::money( 99, $cart->get_subtotal(), 'subtotal' );
		$t::money( 99, $cart->get_total(), 'total' );
	}
);

// The docs say a product with a blank price can't be bought on its own but can go in a package.
$packages_test(
	'Package: items with no price of their own can be in a package',
	function () use ( $t, $packages_make ) {
		$package_only = $t::product( '', array( 'name' => 'Totals test package-only 5x7' ) );
		$package      = $packages_make( 60, array( array( $package_only, 3 ) ) );
		$t::same( false, SPC()->cart->add_item( $package_only ), 'the item with no price is not sold on its own' );
		$t::add( $package );
		$cart = $t::calculate();
		$t::money( 60, $cart->get_total(), 'total' );
	}
);

$packages_test(
	'Package: three of the same package with tax',
	function () use ( $t, $packages_typical ) {
		$t::tax( 7.25 );
		$t::add( $packages_typical( 49.99 ), 3 );
		$cart = $t::calculate();
		// 49.99 x 3 = 149.97. Tax 149.97 x 7.25% = 10.8728.
		$t::money( 149.97, $cart->get_subtotal(), 'subtotal' );
		$t::money( 10.87, $cart->get_tax(), 'tax' );
		$t::money( 160.84, $cart->get_total(), 'total' );
	}
);

// The package has its own Taxable box on the product screen. That box decides, not the
// boxes of the products inside it.
$packages_test(
	'Package: taxed by its own Taxable setting, not by what is inside',
	function () use ( $t, $packages_make ) {
		$t::tax( 10 );
		$untaxed_download = $t::product( 30, array( 'type' => 'download', 'taxable' => false ) );
		$taxed_print      = $t::product( 30 );
		$t::add( $packages_make( 100, array( array( $untaxed_download, 1 ) ), array( 'taxable' => true ) ) );
		$t::add( $packages_make( 50, array( array( $taxed_print, 2 ) ), array( 'taxable' => false ) ) );
		$cart = $t::calculate();
		// Only the $100 package is taxed: 10% of 100.
		$t::money( 10, $cart->get_tax(), 'tax' );
		$t::money( 160, $cart->get_total(), 'total' );
	}
);

// When prices include tax the customer pays exactly the package price shown.
$packages_test(
	'Package: prices include tax',
	function () use ( $t, $packages_typical ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $packages_typical( 119.99 ) );
		$cart = $t::calculate();
		// Tax inside 119.99 at 20% is 119.99 / 6 = 19.998.
		$t::money( 20, $cart->get_tax(), 'tax included' );
		$t::money( 119.99, $cart->get_total(), 'total' );
	}
);

// A package offered free (price 0) is still a real line, and a gift card pays nothing for it.
$packages_test(
	'Package: a free package costs nothing and uses none of a gift card',
	function () use ( $t, $packages_typical, $packages_gift_card_used ) {
		$t::tax( 10 );
		$t::add( $packages_typical( 0 ) );
		$t::gift_card( 25 );
		$cart = $t::calculate();
		$t::same( 1, count( (array) $cart->get_cart_items() ), 'the free package is in the cart' );
		$t::money( 0, $cart->get_tax(), 'tax' );
		$t::money( 0, $packages_gift_card_used(), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'total' );
	}
);

/*
 * Discount codes.
 */

$packages_test(
	'Package discount: percent off the order',
	function () use ( $t, $packages_typical ) {
		$t::tax( 10 );
		$t::add( $packages_typical( 150 ) );
		$t::discount( 'percent-total', 20 );
		$cart = $t::calculate();
		// 20% of 150 = 30. Tax on 120 = 12.
		$t::money( 30, $cart->get_discount(), 'discount' );
		$t::money( 12, $cart->get_tax(), 'tax' );
		$t::money( 132, $cart->get_total(), 'total' );
	}
);

// $25 off a $100 order where $80 of it is a taxed package: 80% of the discount comes off
// the taxed part.
$packages_test(
	'Package discount: fixed amount off an order with a taxed package and an untaxed print',
	function () use ( $t, $packages_typical ) {
		$t::tax( 10 );
		$t::add( $packages_typical( 80 ) );
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$t::discount( 'amount-total', 25 );
		$cart = $t::calculate();
		// Taxed part of the discount: 25 x 80 / 100 = 20. Tax on 80 - 20 = 60 is 6.
		$t::money( 25, $cart->get_discount(), 'discount' );
		$t::money( 6, $cart->get_tax(), 'tax' );
		$t::money( 81, $cart->get_total(), 'total' );
	}
);

$packages_test(
	'Package discount: percent off chosen products, the package is chosen',
	function () use ( $t, $packages_typical ) {
		$t::tax( 10 );
		$package = $packages_typical( 100 );
		$t::add( $package );
		$t::add( $t::product( 20 ) );
		$t::discount( 'percent-product', 10, array( 'allowed_products' => array( $package ) ) );
		$cart = $t::calculate();
		// 10% of the 100 package only. Tax on 120 - 10 = 110 is 11.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 11, $cart->get_tax(), 'tax' );
		$t::money( 121, $cart->get_total(), 'total' );
	}
);

// Assumed rule: a code for "8x10 prints" is for 8x10s bought on their own. The package has its
// own set price, so an 8x10 inside it does not make the package cheaper.
$packages_test(
	'Package discount: a code for a product inside the package does not discount the package',
	function () use ( $t, $packages_make ) {
		$print   = $t::product( 20, array( 'taxable' => false, 'name' => 'Totals test 8x10' ) );
		$package = $packages_make( 100, array( array( $print, 2 ) ), array( 'taxable' => false ) );
		$t::add( $package );
		$t::add( $print );
		$t::discount( 'percent-product', 50, array( 'allowed_products' => array( $print ) ) );
		$cart = $t::calculate();
		// Half off the 8x10 bought on its own: 10.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 110, $cart->get_total(), 'total' );
	}
);

$packages_test(
	'Package discount: amount off each package',
	function () use ( $t, $packages_typical ) {
		$package = $packages_typical( 60, array( 'taxable' => false ) );
		$t::add( $package, 2 );
		$t::discount( 'amount-product', 15, array( 'allowed_products' => array( $package ) ) );
		$cart = $t::calculate();
		// 15 off each of 2.
		$t::money( 30, $cart->get_discount(), 'discount' );
		$t::money( 90, $cart->get_total(), 'total' );
	}
);

// A discount can't be worth more than what it applies to. "$50 off the package" on a $40
// package makes the package free, it doesn't take $10 off the print as well.
$packages_test(
	'Package discount: amount off each package that is more than the package price',
	function () use ( $t, $packages_typical ) {
		$package = $packages_typical( 40, array( 'taxable' => false ) );
		$t::add( $package );
		$t::add( $t::product( 30, array( 'taxable' => false ) ) );
		$t::discount( 'amount-product', 50, array( 'allowed_products' => array( $package ) ) );
		$cart = $t::calculate();
		$t::money( 40, $cart->get_discount(), 'discount is capped at the package price' );
		$t::money( 30, $cart->get_total(), 'the print is still paid in full' );
	}
);

$packages_test(
	'Package discount: code for the package category',
	function () use ( $t, $packages_typical, $packages_category ) {
		$category = $packages_category();
		$t::add( $packages_typical( 100, array( 'taxable' => false, 'category' => $category ) ) );
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		// The discount screen saves chosen categories as a list of id strings.
		$t::discount( 'percent-total', 25, array( 'allowed_categories' => array( (string) $category ) ) );
		$cart = $t::calculate();
		// 25% of the 100 package only.
		$t::money( 25, $cart->get_discount(), 'discount' );
		$t::money( 95, $cart->get_total(), 'total' );
	}
);

$packages_test(
	'Package discount: code that leaves out the package category',
	function () use ( $t, $packages_typical, $packages_category ) {
		$category = $packages_category();
		$t::add( $packages_typical( 100, array( 'taxable' => false, 'category' => $category ) ) );
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$t::discount( 'percent-total', 10, array( 'disallowed_categories' => array( (string) $category ) ) );
		$cart = $t::calculate();
		// 10% of the 20 print only.
		$t::money( 2, $cart->get_discount(), 'discount' );
		$t::money( 118, $cart->get_total(), 'total' );
	}
);

$packages_test(
	'Package discount: limited to one of each product',
	function () use ( $t, $packages_typical ) {
		$package = $packages_typical( 50, array( 'taxable' => false ) );
		$t::add( $package, 3 );
		$t::discount(
			'percent-product',
			20,
			array(
				'allowed_products'     => array( $package ),
				'max_product_quantity' => '1',
			)
		);
		$cart = $t::calculate();
		// 20% off one of the three 50 packages.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 140, $cart->get_total(), 'total' );
	}
);

$packages_test(
	'Package discount: discount after tax',
	function () use ( $t, $packages_typical ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $packages_typical( 100 ) );
		$t::discount( 'percent-total', 20 );
		$cart = $t::calculate();
		// Tax on the full 100 is 10. Then 20% off 110 = 22.
		$t::money( 10, $cart->get_tax(), 'tax' );
		$t::money( 22, $cart->get_discount(), 'discount' );
		$t::money( 88, $cart->get_total(), 'total' );
	}
);

$packages_test(
	'Package discount: prices include tax',
	function () use ( $t, $packages_typical ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $packages_typical( 110 ) );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		// 10% of 110 = 11. The customer pays 99, which has 9 tax in it.
		$t::money( 11, $cart->get_discount(), 'discount' );
		$t::money( 9, $cart->get_tax(), 'tax included' );
		$t::money( 99, $cart->get_total(), 'total' );
	}
);

$packages_test(
	'Package discount: percent off three packages rounds to the cent',
	function () use ( $t, $packages_typical ) {
		$t::add( $packages_typical( 33.33, array( 'taxable' => false ) ), 3 );
		$t::discount( 'percent-total', 15 );
		$cart = $t::calculate();
		// 99.99 x 15% = 14.9985.
		$t::money( 15, $cart->get_discount(), 'discount' );
		$t::money( 84.99, $cart->get_total(), 'total' );
	}
);

// The "a la carte discount with a package" setup from the docs: buying a package unlocks a
// code for everything else. The code needs the package in the cart but does not discount it.
$packages_test(
	'Package discount: a package unlocks a code for everything else in the cart',
	function () use ( $t, $packages_typical ) {
		$package = $packages_typical( 100, array( 'taxable' => false ) );
		$t::add( $package );
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$t::add( $t::product( 30, array( 'taxable' => false ) ) );
		$t::discount(
			'percent-total',
			20,
			array(
				'required_products'   => array( $package ),
				'disallowed_products' => array( $package ),
			)
		);
		$cart = $t::calculate();
		// 20% of the 50 in prints.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 140, $cart->get_total(), 'total' );
	}
);

$packages_test(
	'Package discount: the unlocked code does nothing without the package',
	function () use ( $t, $packages_typical ) {
		$package = $packages_typical( 100, array( 'taxable' => false ) );
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$t::discount(
			'percent-total',
			20,
			array(
				'required_products'   => array( $package ),
				'disallowed_products' => array( $package ),
			)
		);
		$cart = $t::calculate();
		$t::money( 0, $cart->get_discount(), 'discount' );
		$t::money( 20, $cart->get_total(), 'total' );
	}
);

/*
 * Quantity discounts (set on the package's own product screen).
 */

// Each package is added as its own line so it can be filled with its own photos. A rule for
// "2 or more in the cart" must count both lines.
$packages_test(
	'Package quantity discount: two packages on separate lines both get it',
	function () use ( $t, $packages_typical ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$rule    = array(
			'calc'  => '',
			'items' => array(
				array(
					'qty'      => 2,
					'discount' => 10,
					'type'     => 'percent',
				),
			),
		);
		$package = $packages_typical( 100, array( 'taxable' => false, 'qty_discount' => $rule ) );
		$t::add( $package );
		$t::add( $package );
		$cart = $t::calculate();
		$t::same( 2, count( $cart->get_cart_items() ), 'two lines' );
		$line_discount = 0;
		foreach ( $cart->get_cart_items() as $line ) {
			$line_discount += (float) $line->get_discount_total();
		}
		// 10% off each 100 package.
		$t::money( 20, $line_discount, 'line discounts' );
		$t::money( 180, $cart->get_total(), 'total' );
	}
);

$packages_test(
	'Package quantity discount with a percent code and tax',
	function () use ( $t, $packages_typical ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$rule = array(
			'calc'  => '',
			'items' => array(
				array(
					'qty'      => 2,
					'discount' => 10,
					'type'     => 'percent',
				),
			),
		);
		$t::tax( 10 );
		$t::add( $packages_typical( 100, array( 'qty_discount' => $rule ) ), 2 );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		// 200 - 20 quantity discount = 180. Code: 10% of 180 = 18. Tax on 162 = 16.20.
		$t::money( 18, $cart->get_discount(), 'code discount' );
		$t::money( 16.20, $cart->get_tax(), 'tax' );
		$t::money( 178.20, $cart->get_total(), 'total' );
	}
);

/*
 * Shipping.
 */

$packages_test(
	'Package shipping: a package with prints is shipped',
	function () use ( $t, $packages_typical ) {
		$t::tax( 10 );
		$t::add( $packages_typical( 100 ) );
		$t::flat_shipping( 10, false );
		$cart = $t::calculate();
		$t::same( true, (bool) $cart->needs_shipping(), 'needs shipping' );
		$t::money( 10, $cart->get_shipping(), 'shipping' );
		$t::money( 10, $cart->get_tax(), 'tax on the package only' );
		$t::money( 120, $cart->get_total(), 'total' );
	}
);

$packages_test(
	'Package shipping: a package of only digital downloads is not shipped',
	function () use ( $t, $packages_make ) {
		$download = $t::product( 30, array( 'type' => 'download', 'taxable' => false ) );
		$t::add( $packages_make( 100, array( array( $download, 2 ) ), array( 'taxable' => false ) ) );
		$t::flat_shipping( 10, false );
		$cart = $t::calculate();
		$t::same( false, (bool) $cart->needs_shipping(), 'needs shipping' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 100, $cart->get_total(), 'total' );
	}
);

// Changelog 3.1.3: when the package has Disable Shipping checked, that wins over its prints.
$packages_test(
	'Package shipping: a package with shipping turned off is not shipped, even with prints in it',
	function () use ( $t, $packages_typical ) {
		$t::add( $packages_typical( 100, array( 'taxable' => false, 'disable_shipping' => true ) ) );
		$t::flat_shipping( 10, false );
		$cart = $t::calculate();
		$t::same( false, (bool) $cart->needs_shipping(), 'needs shipping' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 100, $cart->get_total(), 'total' );
	}
);

// The package's own Extra Shipping Cost is added once for each package.
$packages_test(
	'Package shipping: extra shipping cost for each package',
	function () use ( $t, $packages_typical ) {
		$t::add( $packages_typical( 60, array( 'taxable' => false, 'shipping' => '7.50' ) ), 2 );
		$t::flat_shipping( 10, false );
		$cart = $t::calculate();
		// 10 flat + 7.50 x 2.
		$t::money( 25, $cart->get_shipping(), 'shipping' );
		$t::money( 145, $cart->get_total(), 'total' );
	}
);

// Assumed rule: with "Each item in cart" pricing, a package is one item, and a package of
// downloads is not shipped so it adds nothing.
$packages_test(
	'Package shipping: priced for each item, a package counts once and downloads are skipped',
	function () use ( $t, $packages_typical, $packages_make ) {
		$download = $t::product( 30, array( 'type' => 'download', 'taxable' => false ) );
		$t::add( $packages_typical( 60, array( 'taxable' => false ) ), 2 );
		$t::add( $packages_make( 40, array( array( $download, 1 ) ), array( 'taxable' => false ) ) );
		$t::flat_shipping( 5, false );
		$t::option( 'flat_rate_price_type_spctestflat', 'qty' );
		$cart = $t::calculate();
		// 5 x the 2 print packages.
		$t::money( 10, $cart->get_shipping(), 'shipping' );
		$t::money( 170, $cart->get_total(), 'total' );
	}
);

/*
 * Gift cards, store credit and payment fees.
 */

$packages_test(
	'Package paid with a gift card after a code, tax and shipping',
	function () use ( $t, $packages_typical, $packages_gift_card_used ) {
		$t::tax( 10 );
		$t::add( $packages_typical( 100 ) );
		$t::flat_shipping( 10, true );
		$t::discount( 'percent-total', 20 );
		$t::gift_card( 500 );
		$cart = $t::calculate();
		// 100 - 20 + 10 shipping + tax on 90 (9) = 99.
		$t::money( 9, $cart->get_tax(), 'tax' );
		$t::money( 99, $packages_gift_card_used(), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

$packages_test(
	'Package partly paid with a gift card',
	function () use ( $t, $packages_typical, $packages_gift_card_used ) {
		$t::tax( 10 );
		$t::add( $packages_typical( 150 ) );
		$t::gift_card( 50 );
		$cart = $t::calculate();
		// 150 + 15 tax - 50.
		$t::money( 50, $packages_gift_card_used(), 'taken from the card' );
		$t::money( 115, $cart->get_total(), 'left to pay' );
	}
);

$packages_test(
	'Package partly paid with store credit',
	function () use ( $t, $packages_typical ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 30 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::add( $packages_typical( 100, array( 'taxable' => false ) ) );
		$cart = $t::calculate();
		$t::money( 30, $cart->get_credits_applied(), 'credits used' );
		$t::money( 70, $cart->get_total(), 'total' );
	}
);

// The fee is for taking the payment, so it is a percent of what is left after the gift card.
$packages_test(
	'Package with a percent payment fee after a gift card',
	function () use ( $t, $packages_typical ) {
		$t::add( $packages_typical( 100, array( 'taxable' => false ) ) );
		$t::gift_card( 40 );
		$t::option( 'test_fee', 'percent' );
		$t::option( 'test_fee_amount', '3' );
		$t::option( 'test_fee_name', 'Card fee' );
		SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
		$cart = $t::calculate();
		// 60 left + 3% of 60.
		$t::money( 61.80, $cart->get_total(), 'total' );
	}
);
