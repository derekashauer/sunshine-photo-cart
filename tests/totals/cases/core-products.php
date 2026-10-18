<?php
/**
 * Product prices: price levels (per gallery and per photo), real photos in real galleries,
 * the same photo added twice, minimum and maximum quantities, discount codes limited to
 * product categories, and the Price Levels add-on screens that set prices.
 *
 * Photos are added through the same request the add to cart window sends, so the price level
 * is worked out by the store the way it is for a customer.
 */

require_once __DIR__ . '/core-products-helpers.php';

$t = 'SPC_Totals_Test';

/*
 * Price levels
 */

$t::test(
	'Price level: a photo uses the price level of its gallery',
	function () use ( $t, $products_level, $products_prices, $products_gallery, $products_photo, $products_add_photo, $products_lines ) {
		$t::tax( 10 );
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 10 );
		$products_prices( $product, array( $default => '10', $wedding => '25' ) );
		$photo = $products_photo( $products_gallery( $wedding ) );

		$response = $products_add_photo( $product, $photo, 2 );
		$t::same( true, ! empty( $response['success'] ), 'added to cart' );
		$cart  = $t::calculate();
		$lines = $products_lines();
		$t::same( 1, count( $lines ), 'one line' );
		if ( $lines ) {
			$data = $lines[0]->get_item_data();
			$t::same( $wedding, (int) $data['price_level'], 'line is at the gallery price level' );
			$t::money( 25, $lines[0]->get_price(), 'price at the wedding level' );
		}
		// 2 x 25 = 50, 10% tax = 5.
		$t::money( 50, $cart->get_subtotal(), 'subtotal' );
		$t::money( 5, $cart->get_tax(), 'tax' );
		$t::money( 55, $cart->get_total(), 'total' );
	}
);

// Per-photo pricing: the media edit screen saves 0 for "Use gallery price level".
$t::test(
	'Price level: a photo with its own price level uses it instead of the gallery level',
	function () use ( $t, $products_level, $products_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_prices( $product, array( $default => '10', $wedding => '25' ) );
		$gallery     = $products_gallery( $wedding );
		$own_level   = $products_photo( $gallery, $default );
		$use_gallery = $products_photo( $gallery, 0 );

		$products_add_photo( $product, $own_level, 1 );
		$products_add_photo( $product, $use_gallery, 1 );
		$cart = $t::calculate();
		// 10 for the photo with its own (default) level + 25 for the photo using the gallery level.
		$t::money( 35, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Price level: a gallery with no price level set uses the default level',
	function () use ( $t, $products_level, $products_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_prices( $product, array( $default => '10', $wedding => '25' ) );
		$photo = $products_photo( $products_gallery( '' ) );

		$products_add_photo( $product, $photo, 3 );
		$cart = $t::calculate();
		$t::money( 30, $cart->get_total(), '3 x 10' );
	}
);

// From the docs: "Leaving a price empty means the product will not be made available for the
// respective Price Level".
$t::test(
	'Price level: a product with no price at the gallery level cannot be added',
	function () use ( $t, $products_level, $products_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 10 );
		$products_prices( $product, array( $default => '10' ) );
		$photo = $products_photo( $products_gallery( $wedding ) );

		$response = $products_add_photo( $product, $photo, 1 );
		$t::same( false, ! empty( $response['success'] ), 'not added' );
		$cart = $t::calculate();
		$t::same( true, $cart->is_empty(), 'cart is empty' );
		$t::money( 0, $cart->get_total(), 'total' );
	}
);

// A price of 0 is a price: the product is free at that level, not missing.
$t::test(
	'Price level: a product priced 0 at a level is free, not refused',
	function () use ( $t, $products_level, $products_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$t::tax( 10 );
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$free    = $t::product( 10 );
		$paid    = $t::product( 10 );
		$products_prices( $free, array( $default => '10', $wedding => '0' ) );
		$products_prices( $paid, array( $default => '10', $wedding => '25' ) );
		$photo = $products_photo( $products_gallery( $wedding ) );

		$response = $products_add_photo( $free, $photo, 2 );
		$t::same( true, ! empty( $response['success'] ), 'free product added' );
		$products_add_photo( $paid, $photo, 1 );
		$cart = $t::calculate();
		$t::same( 2, count( (array) $cart->get_cart_items() ), 'two lines' );
		// 0 + 25, 10% tax on 25.
		$t::money( 2.50, $cart->get_tax(), 'tax' );
		$t::money( 27.50, $cart->get_total(), 'total' );
	}
);

// Adding favorites from several galleries at once: each photo uses its own gallery's level.
$t::test(
	'Price level: favorites from two galleries each use their own gallery price level',
	function () use ( $t, $products_level, $products_prices, $products_gallery, $products_photo, $products_ajax ) {
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_prices( $product, array( $default => '10', $wedding => '25' ) );
		$portrait_photo = $products_photo( $products_gallery( $default ) );
		$wedding_photo  = $products_photo( $products_gallery( $wedding ) );

		$response = $products_ajax(
			'sunshine_modal_add_favorites_to_cart',
			array(
				'product_id' => $product,
				'qty'        => 1,
				'imageIds'   => $portrait_photo . ',' . $wedding_photo,
			)
		);
		$t::same( 2, isset( $response['data']['added_count'] ) ? (int) $response['data']['added_count'] : 0, 'two photos added' );
		$cart = $t::calculate();
		$t::money( 35, $cart->get_total(), '10 + 25' );
	}
);

// Several photos picked in the add to cart window opened from one photo.
$t::test(
	'Price level: photos picked together in the add to cart window each use their own level',
	function () use ( $t, $products_level, $products_prices, $products_gallery, $products_photo, $products_ajax ) {
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_prices( $product, array( $default => '10', $wedding => '25' ) );
		$portrait_photo = $products_photo( $products_gallery( $default ) );
		$wedding_photo  = $products_photo( $products_gallery( $wedding ) );

		$response = $products_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id' => $product,
				'image_id'   => $portrait_photo,
				'gallery_id' => sunshine_get_image( $portrait_photo )->get_gallery_id(),
				'qty'        => 2,
				'options'    => array( 'images' => $portrait_photo . ',' . $wedding_photo ),
			)
		);
		$t::same( true, ! empty( $response['success'] ), 'added' );
		$cart = $t::calculate();
		// 2 x 10 + 2 x 25.
		$t::money( 70, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Price level: prices include tax at a non-default level',
	function () use ( $t, $products_level, $products_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 12 );
		$products_prices( $product, array( $default => '12', $wedding => '30' ) );
		$photo = $products_photo( $products_gallery( $wedding ) );

		$products_add_photo( $product, $photo, 3 );
		$cart = $t::calculate();
		// 3 x 30 = 90 including 20% tax: 75 + 15 tax.
		$t::money( 90, $cart->get_total(), 'total is the prices shown' );
		$t::money( 15, $cart->get_tax(), 'tax included' );
	}
);

$t::test(
	'Price level: a large quantity at a non-default level with tax',
	function () use ( $t, $products_level, $products_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$t::tax( 7.25 );
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 1 );
		$products_prices( $product, array( $default => '1', $wedding => '0.99' ) );
		$photo = $products_photo( $products_gallery( $wedding ) );

		$products_add_photo( $product, $photo, 1000 );
		$cart = $t::calculate();
		// 1000 x 0.99 = 990. 990 x 7.25% = 71.775, rounds to 71.78.
		$t::money( 990, $cart->get_subtotal(), 'subtotal' );
		$t::money( 71.78, $cart->get_tax(), 'tax' );
		$t::money( 1061.78, $cart->get_total(), 'total' );
	}
);

// install.php marks the level it creates as the default, and the docs say the Default Price
// Level can be renamed but not deleted. Renaming it must not hand "default" to whichever level
// now comes first by name. The test level is named to sort last and is marked default only
// for the moment it takes to ask.
$t::test(
	'Default price level: the level marked as default is the default, whatever its name',
	function () use ( $t, $products_level ) {
		$level = $products_level( 'marked default' );
		add_term_meta( $level, 'default', true );
		try {
			$default = sunshine_get_default_price_level_id();
		} finally {
			delete_term_meta( $level, 'default' );
		}
		$t::same( $level, (int) $default, 'default price level id' );
	}
);

/*
 * The same photo added more than once
 */

$t::test(
	'Same photo and product added twice is one line with the quantities added up',
	function () use ( $t, $products_level, $products_prices, $products_gallery, $products_photo, $products_add_photo, $products_lines ) {
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_prices( $product, array( $default => '10', $wedding => '25' ) );
		$photo = $products_photo( $products_gallery( $wedding ) );

		$products_add_photo( $product, $photo, 2 );
		$products_add_photo( $product, $photo, 3 );
		$cart  = $t::calculate();
		$lines = $products_lines();
		$t::same( 1, count( $lines ), 'one line' );
		$t::same( 5, $lines ? $lines[0]->get_qty() : 0, 'quantity 2 + 3' );
		$t::money( 125, $cart->get_total(), '5 x 25' );
	}
);

/*
 * Minimum and maximum quantity. The product screen says these are "for each image".
 */

$t::test(
	'Max quantity: adding more than the max adds only the max',
	function () use ( $t, $products_gallery, $products_photo, $products_add_photo, $products_lines ) {
		$product = $t::product( 10, array( 'taxable' => false ) );
		update_post_meta( $product, 'max_qty', '3' );
		$photo = $products_photo( $products_gallery() );

		$products_add_photo( $product, $photo, 5 );
		$cart  = $t::calculate();
		$lines = $products_lines();
		$t::same( 3, $lines ? $lines[0]->get_qty() : 0, 'quantity' );
		$t::money( 30, $cart->get_total(), '3 x 10' );
	}
);

$t::test(
	'Max quantity: adding the same photo again or changing the quantity stops at the max',
	function () use ( $t, $products_gallery, $products_photo, $products_add_photo, $products_lines ) {
		$product = $t::product( 10, array( 'taxable' => false ) );
		update_post_meta( $product, 'max_qty', '3' );
		$gallery = $products_gallery();
		$photo_a = $products_photo( $gallery );
		$photo_b = $products_photo( $gallery );

		// Photo A: 2, then 2 more.
		$products_add_photo( $product, $photo_a, 2 );
		$products_add_photo( $product, $photo_a, 2 );
		// Photo B: 1, then changed to 10 in the cart.
		$item = $products_add_photo( $product, $photo_b, 1 );
		$hash = isset( $item['data']['item']['hash'] ) ? $item['data']['item']['hash'] : '';
		SPC()->cart->update_item_quantity_by_hash( $hash, 10 );

		$cart = $t::new_request();
		$qty  = array();
		foreach ( $products_lines() as $line ) {
			$qty[ $line->get_image_id() ] = $line->get_qty();
		}
		$t::same( 3, isset( $qty[ $photo_a ] ) ? $qty[ $photo_a ] : 0, 'photo A quantity' );
		$t::same( 3, isset( $qty[ $photo_b ] ) ? $qty[ $photo_b ] : 0, 'photo B quantity' );
		$t::money( 60, $cart->get_total(), '6 x 10' );
	}
);

$t::test(
	'Min quantity: adding or changing to fewer than the min uses the min',
	function () use ( $t, $products_gallery, $products_photo, $products_add_photo, $products_lines ) {
		$product = $t::product( 10, array( 'taxable' => false ) );
		update_post_meta( $product, 'min_qty', '2' );
		$gallery = $products_gallery();
		$photo_a = $products_photo( $gallery );
		$photo_b = $products_photo( $gallery );

		$products_add_photo( $product, $photo_a, 1 );
		$item = $products_add_photo( $product, $photo_b, 4 );
		$hash = isset( $item['data']['item']['hash'] ) ? $item['data']['item']['hash'] : '';
		SPC()->cart->update_item_quantity_by_hash( $hash, 1 );

		$cart = $t::new_request();
		$qty  = array();
		foreach ( $products_lines() as $line ) {
			$qty[ $line->get_image_id() ] = $line->get_qty();
		}
		$t::same( 2, isset( $qty[ $photo_a ] ) ? $qty[ $photo_a ] : 0, 'photo A raised to 2' );
		$t::same( 2, isset( $qty[ $photo_b ] ) ? $qty[ $photo_b ] : 0, 'photo B kept at 2' );
		$t::money( 40, $cart->get_total(), '4 x 10' );
	}
);

$t::test(
	'Order: min and max quantities at a non-default level match the cart',
	function () use ( $t, $products_level, $products_prices, $products_gallery, $products_photo, $products_add_photo, $products_cart_snapshot, $products_order_matches_cart ) {
		$t::tax( 8 );
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 10 );
		$products_prices( $product, array( $default => '10', $wedding => '12.49' ) );
		update_post_meta( $product, 'min_qty', '2' );
		update_post_meta( $product, 'max_qty', '4' );
		$gallery = $products_gallery( $wedding );
		$photo_a = $products_photo( $gallery );
		$photo_b = $products_photo( $gallery );

		$products_add_photo( $product, $photo_a, 1 );
		$products_add_photo( $product, $photo_b, 9 );
		$t::checkout_address();
		$cart = $t::calculate();
		// 2 + 4 = 6 x 12.49 = 74.94. Line tax: 24.98 x 8% = 2.00, 49.96 x 8% = 4.00.
		$t::money( 74.94, $cart->get_subtotal(), 'subtotal' );
		$t::money( 6, $cart->get_tax(), 'tax' );
		$t::money( 80.94, $cart->get_total(), 'total' );
		$seen  = $products_cart_snapshot();
		$order = $t::place_order();
		$items = $products_order_matches_cart( $order, $seen );
		foreach ( $items as $i => $item ) {
			$t::same( $wedding, (int) $item->get_price_level(), 'order line ' . ( $i + 1 ) . ' price level' );
		}
	}
);

/*
 * Discount codes limited to product categories
 */

$products_category = function ( $label ) use ( $t ) {
	$term = wp_insert_term( 'Zz Totals category ' . $label . ' ' . wp_rand( 100000, 999999 ), 'sunshine-product-category' );
	if ( is_wp_error( $term ) ) {
		throw new Exception( 'Could not create a product category: ' . $term->get_error_message() );
	}
	$t::track( 'term', $term['term_id'], 'sunshine-product-category' );
	return (int) $term['term_id'];
};

$t::test(
	'Category discount: only products in the allowed category are discounted',
	function () use ( $t, $products_category ) {
		$prints = $products_category( 'A prints' );
		$albums = $products_category( 'B albums' );
		$print  = $t::product( 50, array( 'taxable' => false ) );
		$album  = $t::product( 50, array( 'taxable' => false ) );
		wp_set_object_terms( $print, array( $prints ), 'sunshine-product-category' );
		wp_set_object_terms( $album, array( $albums ), 'sunshine-product-category' );
		$t::add( $print );
		$t::add( $album );
		// The discount screen saves its category list as strings.
		$t::discount( 'percent-total', 10, array( 'allowed_categories' => array( (string) $prints ) ) );
		$cart = $t::calculate();
		$t::money( 5, $cart->get_discount(), '10% of the 50 print' );
		$t::money( 95, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Category discount: products in a left-out category are not discounted',
	function () use ( $t, $products_category ) {
		$prints = $products_category( 'A prints' );
		$albums = $products_category( 'B albums' );
		$print  = $t::product( 50, array( 'taxable' => false ) );
		$album  = $t::product( 50, array( 'taxable' => false ) );
		wp_set_object_terms( $print, array( $prints ), 'sunshine-product-category' );
		wp_set_object_terms( $album, array( $albums ), 'sunshine-product-category' );
		$t::add( $print );
		$t::add( $album );
		$t::discount( 'percent-total', 10, array( 'disallowed_categories' => array( (string) $albums ) ) );
		$cart = $t::calculate();
		$t::money( 5, $cart->get_discount(), '10% of the 50 print' );
		$t::money( 95, $cart->get_total(), 'total' );
	}
);

// Product categories are checkboxes, so a product can be in more than one. Being in the
// allowed category is enough, whichever of its categories comes first by name.
$t::test(
	'Category discount: a product in two categories counts for either one',
	function () use ( $t, $products_category ) {
		$prints = $products_category( 'A prints' );
		$sale   = $products_category( 'B holiday sale' );
		$print  = $t::product( 100, array( 'taxable' => false ) );
		wp_set_object_terms( $print, array( $prints, $sale ), 'sunshine-product-category' );
		$t::add( $print );
		$t::discount( 'percent-total', 10, array( 'allowed_categories' => array( (string) $sale ) ) );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_discount(), '10% of 100' );
		$t::money( 90, $cart->get_total(), 'total' );
	}
);

/*
 * Discount codes limited to galleries. The discount screen says: "Child galleries of selected
 * galleries will automatically be included". It saves the galleries as whole numbers.
 */

$t::test(
	'Gallery discount: photos from the allowed gallery and its sub-galleries are discounted',
	function () use ( $t, $products_gallery, $products_photo, $products_add_photo ) {
		$product  = $t::product( 10, array( 'taxable' => false ) );
		$wedding  = $products_gallery();
		$ceremony = $products_gallery( '', $wedding );
		$other    = $products_gallery();
		$products_add_photo( $product, $products_photo( $wedding ), 1 );
		$products_add_photo( $product, $products_photo( $ceremony ), 1 );
		$products_add_photo( $product, $products_photo( $other ), 1 );
		$t::discount( 'percent-total', 10, array( 'allowed_galleries' => array( $wedding ) ) );
		$cart = $t::calculate();
		// 10% of the 10 + 10 from the wedding gallery and its sub-gallery.
		$t::money( 2, $cart->get_discount(), 'discount' );
		$t::money( 28, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Gallery discount: photos from a left-out gallery and its sub-galleries are not discounted',
	function () use ( $t, $products_gallery, $products_photo, $products_add_photo ) {
		$product  = $t::product( 10, array( 'taxable' => false ) );
		$wedding  = $products_gallery();
		$ceremony = $products_gallery( '', $wedding );
		$other    = $products_gallery();
		$products_add_photo( $product, $products_photo( $wedding ), 1 );
		$products_add_photo( $product, $products_photo( $ceremony ), 1 );
		$products_add_photo( $product, $products_photo( $other ), 1 );
		$t::discount( 'percent-total', 10, array( 'disallowed_galleries' => array( $wedding ) ) );
		$cart = $t::calculate();
		// Only the photo from the other gallery: 10% of 10.
		$t::money( 1, $cart->get_discount(), 'discount' );
		$t::money( 29, $cart->get_total(), 'total' );
	}
);

/*
 * Price Levels add-on: the screens that set prices for a whole level.
 */

// Products > Price Levels > Add: "Base price off" another level at a percentage. The add-on
// reprices every product in the store, so the test limits it to its own products and checks
// that the limit worked before it creates the level.
$t::test(
	'Price Levels add-on: a new level based on another at 150% sets product and option prices',
	function () use ( $t, $products_level, $products_prices, $products_all_levels, $products_option, $products_gallery, $products_photo, $products_add_photo ) {
		$t::require_plugin( 'sunshine_create_price_level', 'Price Levels add-on' );
		$default = sunshine_get_default_price_level_id();
		$base    = $products_level( 'base' );
		$print   = $t::product( 10, array( 'taxable' => false ) );
		$canvas  = $t::product( 80, array( 'taxable' => false ) );
		$products_prices( $print, array( $default => '10', $base => '12.99' ) );
		// Not sold at the base level, so it must not be sold at the new level either.
		$products_prices( $canvas, array( $default => '80' ) );

		$has_options = function_exists( 'sunshine_product_options_save' );
		if ( $has_options ) {
			$rush  = $products_option( 'checkbox' );
			$paper = $products_option( 'select', array( 'gloss01' => 'Gloss', 'matte01' => 'Matte' ) );
			update_post_meta(
				$print,
				'options',
				array(
					$rush  => $products_all_levels( array( $base => '5' ) ),
					$paper => array(
						'items' => array(
							'gloss01' => $products_all_levels( array( $base => '2.50' ) ),
							'matte01' => $products_all_levels( array( $default => '1' ) ),
						),
					),
				)
			);
		}

		$mine = function ( $args ) use ( $print, $canvas ) {
			$args['post__in'] = array( $print, $canvas );
			return $args;
		};
		add_filter( 'sunshine_get_product_args', $mine );
		$found = array();
		foreach ( (array) sunshine_get_products() as $found_product ) {
			$found[] = (int) $found_product->get_id();
		}
		sort( $found );
		$expected_found = array( $print, $canvas );
		sort( $expected_found );
		if ( $found !== $expected_found ) {
			remove_filter( 'sunshine_get_product_args', $mine );
			throw new Exception( 'Could not limit the add-on to this test\'s products, so the level was not created' );
		}

		$saved_post = $_POST;
		$_POST      = array(
			'price_level' => (string) $base,
			'percent'     => '150',
		);
		try {
			$term = wp_insert_term( 'Zz Totals 150 percent ' . wp_rand( 100000, 999999 ), 'sunshine-product-price-level' );
		} finally {
			$_POST = $saved_post;
			remove_filter( 'sunshine_get_product_args', $mine );
		}
		if ( is_wp_error( $term ) ) {
			throw new Exception( 'Could not create the price level' );
		}
		$t::track( 'term', $term['term_id'], 'sunshine-product-price-level' );
		$new = (int) $term['term_id'];

		// 12.99 x 150% = 19.485, rounds to 19.49.
		$t::money( 19.49, sunshine_get_product( $print, $new )->get_price( $new ), 'print price at the new level' );
		$t::same( '', sunshine_get_product( $canvas, $new )->get_price( $new ), 'canvas is not sold at the new level' );

		$photo = $products_photo( $products_gallery( $new ) );
		if ( $has_options ) {
			$saved = get_post_meta( $print, 'options', true );
			// 5 x 150% = 7.50, 2.50 x 150% = 3.75. Matte had no base price, so it gets none.
			$t::money( 7.50, isset( $saved[ $rush ][ $new ] ) ? $saved[ $rush ][ $new ] : 0, 'rush option at the new level' );
			$t::money( 3.75, isset( $saved[ $paper ]['items']['gloss01'][ $new ] ) ? $saved[ $paper ]['items']['gloss01'][ $new ] : 0, 'gloss at the new level' );
			$t::same( true, ! isset( $saved[ $paper ]['items']['matte01'][ $new ] ) || '' === $saved[ $paper ]['items']['matte01'][ $new ], 'matte has no price at the new level' );
			$products_add_photo( $print, $photo, 1, array( $rush => '1', $paper => 'gloss01' ) );
			$cart = $t::calculate();
			// 19.49 + 7.50 + 3.75.
			$t::money( 30.74, $cart->get_total(), 'cart total' );
		} else {
			$products_add_photo( $print, $photo, 1 );
			$cart = $t::calculate();
			$t::money( 19.49, $cart->get_total(), 'cart total' );
		}
		$response = $products_add_photo( $canvas, $photo, 1 );
		$t::same( false, ! empty( $response['success'] ), 'canvas cannot be added at the new level' );
	}
);

// Products > Price Levels > Edit: one price box per product.
$products_save_level_screen = function ( $level, $prices ) {
	$saved_post = $_POST;
	$_POST      = array( 'products' => $prices );
	try {
		wp_update_term( $level, 'sunshine-product-price-level', array() );
	} finally {
		$_POST = $saved_post;
	}
};

$t::test(
	'Price Levels add-on: prices saved on the price level screen are used in the cart',
	function () use ( $t, $products_level, $products_prices, $products_save_level_screen, $products_add_store ) {
		$t::require_plugin( 'sunshine_save_price_level', 'Price Levels add-on' );
		$default = sunshine_get_default_price_level_id();
		$level   = $products_level( 'edited' );
		$print   = $t::product( 10, array( 'taxable' => false ) );
		$canvas  = $t::product( 80, array( 'taxable' => false ) );
		$products_prices( $print, array( $default => '10' ) );
		$products_prices( $canvas, array( $default => '80', $level => '95' ) );

		// Price the print, and empty the canvas price so it is no longer sold at this level.
		$products_save_level_screen( $level, array( $print => '18.50', $canvas => '' ) );

		$added = $products_add_store( $print, $level, 2 );
		$t::same( true, ! empty( $added['success'] ), 'print added' );
		$refused = $products_add_store( $canvas, $level, 1 );
		$t::same( false, ! empty( $refused['success'] ), 'canvas not sold at this level any more' );
		$cart = $t::calculate();
		$t::money( 37, $cart->get_total(), '2 x 18.50' );
	}
);

// The product edit screen removes the store's thousands separator before saving a price.
// A price typed the same way on the price level screen must be saved as the same amount.
$t::test(
	'Price Levels add-on: a price typed with a thousands separator is saved in full',
	function () use ( $t, $products_level, $products_prices, $products_save_level_screen, $products_add_store ) {
		$t::require_plugin( 'sunshine_save_price_level', 'Price Levels add-on' );
		$t::option( 'currency_thousands_separator', ',' );
		$t::option( 'currency_decimal_separator', '.' );
		$default = sunshine_get_default_price_level_id();
		$level   = $products_level( 'canvas' );
		$canvas  = $t::product( 900, array( 'taxable' => false ) );
		$products_prices( $canvas, array( $default => '900' ) );

		$products_save_level_screen( $level, array( $canvas => '1,250.00' ) );

		$t::money( 1250, sunshine_get_product( $canvas, $level )->get_price( $level ), 'saved price' );
		$products_add_store( $canvas, $level, 1 );
		$cart = $t::calculate();
		$t::money( 1250, $cart->get_total(), 'cart total' );
	}
);

// Stores that write 12,50 for twelve and a half. The product edit screen and the cart both
// read prices this way, so the price level screen must too.
$t::test(
	'Price Levels add-on: a price typed with a decimal comma keeps its cents',
	function () use ( $t, $products_level, $products_prices, $products_save_level_screen, $products_add_store ) {
		$t::require_plugin( 'sunshine_save_price_level', 'Price Levels add-on' );
		$t::option( 'currency_thousands_separator', '.' );
		$t::option( 'currency_decimal_separator', ',' );
		$default = sunshine_get_default_price_level_id();
		$level   = $products_level( 'euro' );
		$print   = $t::product( 10, array( 'taxable' => false ) );
		$products_prices( $print, array( $default => '10' ) );

		$products_save_level_screen( $level, array( $print => '12,50' ) );

		$t::money( 12.50, sunshine_get_product( $print, $level )->get_price( $level ), 'saved price' );
		$products_add_store( $print, $level, 2 );
		$cart = $t::calculate();
		$t::money( 25, $cart->get_total(), '2 x 12.50' );
	}
);
