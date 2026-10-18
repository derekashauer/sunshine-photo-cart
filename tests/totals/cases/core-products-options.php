<?php
/**
 * Product options (Product Options add-on): a "select one of many" option like a frame, a
 * checkbox like rush printing, and a text field like a name to print. Each option item has a
 * price per price level, added to the product price for every unit on the line.
 *
 * Options are sent the way the add to cart window's script sends them: option id => chosen
 * item id for a select, "1" for a ticked checkbox, and the typed text for a text field.
 */

require_once __DIR__ . '/core-products-helpers.php';

$t = 'SPC_Totals_Test';

/**
 * The options most tests use: a frame (select), rush printing (checkbox) and a name to print
 * (text field).
 */
$products_make_options = function () use ( $products_option ) {
	return array(
		'frame' => $products_option(
			'select',
			array(
				'black01' => 'Black',
				'white01' => 'White',
				'none001' => 'No frame',
				'oak0001' => 'Oak',
			)
		),
		'rush'  => $products_option( 'checkbox' ),
		'name'  => $products_option( 'text' ),
	);
};

$t::test(
	'Option: a select option adds the chosen item price, and an item priced 0 adds nothing',
	function () use ( $t, $products_make_options, $products_option_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_option_prices(
			$product,
			array(
				$o['frame'] => array(
					'items' => array(
						'black01' => array( $default => '20' ),
						'none001' => array( $default => '0' ),
					),
				),
			)
		);
		$gallery = $products_gallery( $default );

		$products_add_photo( $product, $products_photo( $gallery ), 2, array( $o['frame'] => 'black01' ) );
		$products_add_photo( $product, $products_photo( $gallery ), 1, array( $o['frame'] => 'none001' ) );
		$cart = $t::calculate();
		// (10 + 20) x 2 + (10 + 0) x 1.
		$t::money( 70, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Option: a ticked checkbox and typed text add their prices',
	function () use ( $t, $products_make_options, $products_option_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_option_prices(
			$product,
			array(
				$o['rush'] => array( $default => '5' ),
				$o['name'] => array( $default => '3' ),
			)
		);
		$gallery = $products_gallery( $default );

		$products_add_photo( $product, $products_photo( $gallery ), 1, array( $o['rush'] => '1' ) );
		$products_add_photo( $product, $products_photo( $gallery ), 2, array( $o['name'] => 'Emma' ) );
		$cart = $t::calculate();
		// (10 + 5) + (10 + 3) x 2.
		$t::money( 41, $cart->get_total(), 'total' );
	}
);

// The window's script sends every text field, typed in or not. The product window adds a text
// option's price "only if text is entered", so the cart must not charge for an empty one.
$t::test(
	'Option: a text field left empty costs nothing',
	function () use ( $t, $products_make_options, $products_option_prices, $products_gallery, $products_photo, $products_add_photo, $products_lines ) {
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_option_prices( $product, array( $o['name'] => array( $default => '3' ) ) );
		$photo = $products_photo( $products_gallery( $default ) );

		$products_add_photo( $product, $photo, 2, array( $o['name'] => '' ) );
		$cart  = $t::calculate();
		$lines = $products_lines();
		$t::money( 10, $lines ? $lines[0]->get_price() : 0, 'line price' );
		$t::money( 20, $cart->get_total(), '2 x 10' );
	}
);

$t::test(
	'Option: two options on one line with quantity 3',
	function () use ( $t, $products_make_options, $products_option_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_option_prices(
			$product,
			array(
				$o['frame'] => array( 'items' => array( 'black01' => array( $default => '20' ) ) ),
				$o['rush']  => array( $default => '5' ),
			)
		);
		$photo = $products_photo( $products_gallery( $default ) );

		$products_add_photo( $product, $photo, 3, array( $o['frame'] => 'black01', $o['rush'] => '1' ) );
		$cart = $t::calculate();
		$t::money( 105, $cart->get_total(), '(10 + 20 + 5) x 3' );
	}
);

$t::test(
	'Option: option prices follow the price level of the photo',
	function () use ( $t, $products_make_options, $products_level, $products_prices, $products_option_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_prices( $product, array( $default => '10', $wedding => '25' ) );
		$products_option_prices(
			$product,
			array(
				$o['frame'] => array( 'items' => array( 'black01' => array( $default => '20', $wedding => '30' ) ) ),
				$o['rush']  => array( $default => '5', $wedding => '8' ),
			)
		);
		$chosen = array( $o['frame'] => 'black01', $o['rush'] => '1' );

		// Photo in a wedding gallery: 25 + 30 + 8 = 63.
		$products_add_photo( $product, $products_photo( $products_gallery( $wedding ) ), 1, $chosen );
		// Photo in a default gallery: 10 + 20 + 5 = 35.
		$products_add_photo( $product, $products_photo( $products_gallery( $default ) ), 1, $chosen );
		// Photo with its own wedding level in a default gallery: 63.
		$products_add_photo( $product, $products_photo( $products_gallery( $default ), $wedding ), 1, $chosen );
		$cart = $t::calculate();
		$t::money( 161, $cart->get_total(), '63 + 35 + 63' );
	}
);

// The product window shows the price as options are picked. It must show what the cart charges.
$products_window_price = function ( $product_id, $image_id, $options ) use ( $products_ajax ) {
	// The Add to cart button in the product window has the product, gallery and photo ids but
	// no price level, so the script (product-options.js) posts an empty price level.
	$response = $products_ajax(
		'sunshine_modal_select_options_price',
		array(
			'product_id'  => $product_id,
			'gallery_id'  => sunshine_get_image( $image_id )->get_gallery_id(),
			'price_level' => '',
			'options'     => $options,
		)
	);
	return isset( $response['data']['price'] ) ? $response['data']['price'] : null;
};

$t::test(
	'Option: the price shown in the product window matches the cart line',
	function () use ( $t, $products_make_options, $products_level, $products_prices, $products_option_prices, $products_gallery, $products_photo, $products_add_photo, $products_lines, $products_window_price ) {
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_prices( $product, array( $default => '10', $wedding => '25' ) );
		$products_option_prices(
			$product,
			array(
				$o['frame'] => array( 'items' => array( 'black01' => array( $default => '20', $wedding => '30' ) ) ),
				$o['rush']  => array( $default => '5', $wedding => '8' ),
				$o['name']  => array( $default => '2', $wedding => '3' ),
			)
		);
		$photo  = $products_photo( $products_gallery( $wedding ) );
		$chosen = array( $o['frame'] => 'black01', $o['rush'] => '1', $o['name'] => 'Emma' );

		$t::money( 66, $products_window_price( $product, $photo, $chosen ), 'price shown: 25 + 30 + 8 + 3' );
		$products_add_photo( $product, $photo, 1, $chosen );
		$t::calculate();
		$lines = $products_lines();
		$t::money( 66, $lines ? $lines[0]->get_price() : 0, 'cart line price' );
	}
);

// Per-photo pricing: a photo can have its own price level, different from its gallery's.
$t::test(
	'Option: the product window price for a photo with its own price level matches the cart',
	function () use ( $t, $products_make_options, $products_level, $products_prices, $products_option_prices, $products_gallery, $products_photo, $products_add_photo, $products_lines, $products_window_price ) {
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_prices( $product, array( $default => '10', $wedding => '25' ) );
		$products_option_prices(
			$product,
			array( $o['frame'] => array( 'items' => array( 'black01' => array( $default => '20', $wedding => '30' ) ) ) )
		);
		// The photo is priced at the wedding level; its gallery uses the default level.
		$photo  = $products_photo( $products_gallery( $default ), $wedding );
		$chosen = array( $o['frame'] => 'black01' );

		$products_add_photo( $product, $photo, 1, $chosen );
		$t::calculate();
		$lines = $products_lines();
		$t::money( 55, $lines ? $lines[0]->get_price() : 0, 'cart line price: 25 + 30' );
		$t::money( 55, $products_window_price( $product, $photo, $chosen ), 'price shown in the product window' );
	}
);

// The option-price check in the add-on says it is there "to make sure nobody is gaming the
// pricing". An option with no price at this level is not offered here, so a request that sends
// it anyway (or a cart line from before the photographer removed that price) must not get it free.
$t::test(
	'Option: an option not sold at the photo price level is not given away free',
	function () use ( $t, $products_make_options, $products_level, $products_prices, $products_option_prices, $products_gallery, $products_photo, $products_add_photo, $products_lines ) {
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_prices( $product, array( $default => '10', $wedding => '25' ) );
		$products_option_prices(
			$product,
			array(
				$o['frame'] => array(
					'items' => array(
						'black01' => array( $default => '20', $wedding => '30' ),
						'oak0001' => array( $default => '40' ),
					),
				),
			)
		);
		$photo = $products_photo( $products_gallery( $wedding ) );

		$products_add_photo( $product, $photo, 1, array( $o['frame'] => 'oak0001' ) );
		$t::calculate();
		$free_oak = 0;
		foreach ( $products_lines() as $line ) {
			$options = (array) $line->get_options();
			if ( isset( $options[ $o['frame'] ] ) && 'oak0001' === $options[ $o['frame'] ] ) {
				$free_oak++;
			}
		}
		$t::same( 0, $free_oak, 'cart lines with an oak frame that is not sold at the wedding level' );
	}
);

$t::test(
	'Option: the same photo with another option is a new line, with the same option it adds up',
	function () use ( $t, $products_make_options, $products_option_prices, $products_gallery, $products_photo, $products_add_photo, $products_lines ) {
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_option_prices(
			$product,
			array(
				$o['frame'] => array(
					'items' => array(
						'black01' => array( $default => '20' ),
						'white01' => array( $default => '15' ),
					),
				),
			)
		);
		$photo = $products_photo( $products_gallery( $default ) );

		$products_add_photo( $product, $photo, 1, array( $o['frame'] => 'black01' ) );
		$products_add_photo( $product, $photo, 1, array( $o['frame'] => 'black01' ) );
		$products_add_photo( $product, $photo, 1, array( $o['frame'] => 'white01' ) );
		$cart = $t::calculate();
		$qty  = array();
		foreach ( $products_lines() as $line ) {
			$options                        = (array) $line->get_options();
			$qty[ $options[ $o['frame'] ] ] = $line->get_qty();
		}
		$t::same( array( 'black01' => 2, 'white01' => 1 ), $qty, 'quantity per frame' );
		// (10 + 20) x 2 + (10 + 15).
		$t::money( 85, $cart->get_total(), 'total' );
	}
);

/*
 * Options with tax, discounts, gift cards, store credit and payment fees
 */

$t::test(
	'Option: tax is on the product price plus its options',
	function () use ( $t, $products_make_options, $products_option_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$t::tax( 10 );
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$product = $t::product( 10 );
		$products_option_prices(
			$product,
			array(
				$o['frame'] => array( 'items' => array( 'black01' => array( $default => '20' ) ) ),
				$o['rush']  => array( $default => '5' ),
			)
		);
		$photo = $products_photo( $products_gallery( $default ) );

		$products_add_photo( $product, $photo, 2, array( $o['frame'] => 'black01', $o['rush'] => '1' ) );
		$cart = $t::calculate();
		// (10 + 20 + 5) x 2 = 70, 10% tax = 7.
		$t::money( 70, $cart->get_subtotal(), 'subtotal' );
		$t::money( 7, $cart->get_tax(), 'tax' );
		$t::money( 77, $cart->get_total(), 'total' );
	}
);

// When prices include tax, the customer pays exactly the product and option prices shown.
$t::test(
	'Option: prices include tax add up to the prices shown',
	function () use ( $t, $products_make_options, $products_option_prices, $products_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$product = $t::product( 12.99 );
		$products_option_prices( $product, array( $o['rush'] => array( $default => '4.99' ) ) );
		$photo = $products_photo( $products_gallery( $default ) );

		$products_add_photo( $product, $photo, 3, array( $o['rush'] => '1' ) );
		$cart = $t::calculate();
		// (12.99 + 4.99) x 3 = 53.94. The tax in it is about 53.94 / 6 = 8.99; each part is
		// worked out per unit and rounded, so it can be a cent or two off.
		$t::money( 53.94, $cart->get_total(), 'total' );
		$tax = (float) $cart->get_tax();
		$t::same( true, $tax >= 8.96 && $tax <= 9.02, 'tax is about 8.99 (got ' . $tax . ')' );
	}
);

$t::test(
	'Option: a product discount includes the option price',
	function () use ( $t, $products_make_options, $products_option_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$framed  = $t::product( 10, array( 'taxable' => false ) );
		$other   = $t::product( 10, array( 'taxable' => false ) );
		$products_option_prices( $framed, array( $o['frame'] => array( 'items' => array( 'black01' => array( $default => '20' ) ) ) ) );
		$photo = $products_photo( $products_gallery( $default ) );

		$products_add_photo( $framed, $photo, 2, array( $o['frame'] => 'black01' ) );
		$products_add_photo( $other, $photo, 1 );
		$t::discount( 'percent-product', 10, array( 'allowed_products' => array( $framed ) ) );
		$cart = $t::calculate();
		// 10% of (10 + 20) x 2 = 6. The other product is not discounted.
		$t::money( 6, $cart->get_discount(), 'discount' );
		$t::money( 64, $cart->get_total(), '60 - 6 + 10' );
	}
);

$t::test(
	'Option: discount after tax on a product with options',
	function () use ( $t, $products_make_options, $products_option_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$product = $t::product( 10 );
		$products_option_prices( $product, array( $o['rush'] => array( $default => '5' ) ) );
		$photo = $products_photo( $products_gallery( $default ) );

		$products_add_photo( $product, $photo, 1, array( $o['rush'] => '1' ) );
		$t::discount( 'percent-total', 20 );
		$cart = $t::calculate();
		// 15 + 1.50 tax = 16.50, 20% off = 3.30.
		$t::money( 1.50, $cart->get_tax(), 'tax' );
		$t::money( 3.30, $cart->get_discount(), 'discount' );
		$t::money( 13.20, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Option: a gift card pays for options, tax and shipping and no more',
	function () use ( $t, $products_make_options, $products_option_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$t::require_plugin( 'sunshine_get_gift_card_by_code', 'Gift Cards add-on' );
		$t::tax( 10 );
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$product = $t::product( 10 );
		$products_option_prices(
			$product,
			array(
				$o['frame'] => array( 'items' => array( 'black01' => array( $default => '20' ) ) ),
				$o['rush']  => array( $default => '5' ),
			)
		);
		$photo = $products_photo( $products_gallery( $default ) );

		$products_add_photo( $product, $photo, 1, array( $o['frame'] => 'black01', $o['rush'] => '1' ) );
		$t::flat_shipping( 10, true );
		$t::gift_card( 100 );
		$cart = $t::calculate();
		// 35 + 3.50 tax + 10 shipping + 1 shipping tax = 49.50.
		$t::money( 49.50, array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) ), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

$t::test(
	'Option: store credit and a percent payment fee',
	function () use ( $t, $products_make_options, $products_option_prices, $products_gallery, $products_photo, $products_add_photo ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		$o              = $products_make_options();
		$default        = sunshine_get_default_price_level_id();
		$product        = $t::product( 10, array( 'taxable' => false ) );
		$products_option_prices(
			$product,
			array(
				$o['frame'] => array( 'items' => array( 'black01' => array( $default => '20' ) ) ),
				$o['rush']  => array( $default => '5' ),
			)
		);
		$photo = $products_photo( $products_gallery( $default ) );

		$products_add_photo( $product, $photo, 1, array( $o['frame'] => 'black01', $o['rush'] => '1' ) );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::option( 'test_fee', 'percent' );
		$t::option( 'test_fee_amount', '3' );
		$t::option( 'test_fee_name', 'Card fee' );
		SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
		$cart = $t::calculate();
		// 35 - 20 credit = 15, plus 3% = 15.45.
		$t::money( 20, $cart->get_credits_applied(), 'credits used' );
		$t::money( 15.45, $cart->get_total(), 'total' );
	}
);

/*
 * Placed orders
 */

$t::test(
	'Order: option prices at the photo price level are saved with the order',
	function () use ( $t, $products_make_options, $products_level, $products_prices, $products_option_prices, $products_gallery, $products_photo, $products_add_photo, $products_cart_snapshot, $products_order_matches_cart ) {
		$t::tax( 10 );
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 10 );
		$products_prices( $product, array( $default => '10', $wedding => '25' ) );
		$products_option_prices(
			$product,
			array(
				$o['frame'] => array( 'items' => array( 'black01' => array( $default => '20', $wedding => '30' ) ) ),
				$o['rush']  => array( $default => '5', $wedding => '8' ),
				$o['name']  => array( $default => '2', $wedding => '3' ),
			)
		);
		$photo = $products_photo( $products_gallery( $wedding ) );

		$products_add_photo( $product, $photo, 2, array( $o['frame'] => 'black01', $o['rush'] => '1', $o['name'] => 'Emma' ) );
		$t::checkout_address();
		$cart = $t::calculate();
		// (25 + 30 + 8 + 3) x 2 = 132, 10% tax = 13.20.
		$t::money( 132, $cart->get_subtotal(), 'cart subtotal' );
		$t::money( 145.20, $cart->get_total(), 'cart total' );
		$seen  = $products_cart_snapshot();
		$order = $t::place_order();
		$items = $products_order_matches_cart( $order, $seen );
		if ( empty( $items ) ) {
			return;
		}
		$line = $items[0];
		$t::money( 66, $line->get_price(), 'order line price includes the options' );
		$t::same( $wedding, (int) $line->get_price_level(), 'order line price level' );
		$saved = (array) $line->get_meta_value( 'options' );
		$t::money( 30, isset( $saved[ $o['frame'] ]['price'] ) ? $saved[ $o['frame'] ]['price'] : null, 'saved frame price' );
		$t::same( 'Black', isset( $saved[ $o['frame'] ]['value'] ) ? $saved[ $o['frame'] ]['value'] : null, 'saved frame choice' );
		$t::money( 8, isset( $saved[ $o['rush'] ]['price'] ) ? $saved[ $o['rush'] ]['price'] : null, 'saved rush price' );
		$t::money( 3, isset( $saved[ $o['name'] ]['price'] ) ? $saved[ $o['name'] ]['price'] : null, 'saved name price' );
		$t::same( 'Emma', isset( $saved[ $o['name'] ]['value'] ) ? $saved[ $o['name'] ]['value'] : null, 'saved name text' );
	}
);

// Each line's tax is worked out on the whole line and rounded once. The saved order line
// must show the same tax the cart line did.
$t::test(
	'Order: line tax for a product with options and quantity 3 matches the cart',
	function () use ( $t, $products_make_options, $products_option_prices, $products_gallery, $products_photo, $products_add_photo, $products_cart_snapshot, $products_order_matches_cart ) {
		$t::tax( 7.25 );
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$product = $t::product( 10 );
		$products_option_prices( $product, array( $o['rush'] => array( $default => '2.50' ) ) );
		$photo = $products_photo( $products_gallery( $default ) );

		$products_add_photo( $product, $photo, 3, array( $o['rush'] => '1' ) );
		$t::checkout_address();
		$cart = $t::calculate();
		// (10 + 2.50) x 3 = 37.50. 37.50 x 7.25% = 2.71875, rounds to 2.72.
		$t::money( 2.72, $cart->get_tax(), 'cart tax' );
		$t::money( 40.22, $cart->get_total(), 'cart total' );
		$seen  = $products_cart_snapshot();
		$order = $t::place_order();
		$products_order_matches_cart( $order, $seen );
	}
);

$t::test(
	'Order: prices include tax with options at a non-default level',
	function () use ( $t, $products_make_options, $products_level, $products_prices, $products_option_prices, $products_gallery, $products_photo, $products_add_photo, $products_cart_snapshot, $products_order_matches_cart ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$product = $t::product( 12 );
		$products_prices( $product, array( $default => '12', $wedding => '30' ) );
		$products_option_prices( $product, array( $o['frame'] => array( 'items' => array( 'black01' => array( $default => '6', $wedding => '12' ) ) ) ) );
		$photo = $products_photo( $products_gallery( $wedding ) );

		$products_add_photo( $product, $photo, 1, array( $o['frame'] => 'black01' ) );
		$t::checkout_address();
		$cart = $t::calculate();
		// 30 + 12 = 42 including 20% tax: 35 + 7 tax.
		$t::money( 42, $cart->get_total(), 'cart total' );
		$t::money( 7, $cart->get_tax(), 'cart tax' );
		$seen  = $products_cart_snapshot();
		$order = $t::place_order();
		$items = $products_order_matches_cart( $order, $seen );
		if ( empty( $items ) ) {
			return;
		}
		$t::money( 35, $items[0]->get_price(), 'order line price without tax' );
		$saved = (array) $items[0]->get_meta_value( 'options' );
		$t::money( 12, isset( $saved[ $o['frame'] ]['price'] ) ? $saved[ $o['frame'] ]['price'] : null, 'saved frame price, as the photographer set it' );
	}
);

/*
 * Option prices as the product edit screen saves them
 */

/**
 * Open the product edit screen's Options box and save it again without changing anything:
 * read each price box for this option the way the browser would post it, and save what is
 * posted the way the meta box saves an "options" field.
 */
$products_resave_options_box = function ( $product_id, $option_id ) {
	$meta = get_post_meta( $product_id, 'options', true );
	ob_start();
	sunshine_product_option_meta_field_display(
		array(
			'id'          => 'options',
			'placeholder' => '',
		),
		$meta
	);
	$html = ob_get_clean();
	preg_match_all( '/name="options\[' . $option_id . '\]\[(\d+)\]"[^>]*value="([^"]*)"/', $html, $matches, PREG_SET_ORDER );
	$posted = array();
	foreach ( $matches as $match ) {
		$posted[ $option_id ][ $match[1] ] = html_entity_decode( $match[2], ENT_QUOTES );
	}
	// What the meta box does with a field of this type before it saves it.
	$posted = apply_filters( 'sunshine_meta_product_options_sanitize', $posted );
	$posted = apply_filters( 'sunshine_meta_options_validate', $posted );
	update_post_meta( $product_id, 'options', $posted );
	return $posted;
};

$t::test(
	'Option price of 1,000 or more keeps its price after the product is saved again',
	function () use ( $t, $products_make_options, $products_option_prices, $products_resave_options_box, $products_add_store ) {
		$t::option( 'currency_thousands_separator', ',' );
		$t::option( 'currency_decimal_separator', '.' );
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$product = $t::product( 100, array( 'taxable' => false ) );
		// Large canvas upgrade.
		$products_option_prices( $product, array( $o['rush'] => array( $default => '1250' ) ) );

		$products_resave_options_box( $product, $o['rush'] );

		$products_add_store( $product, $default, 1, array( $o['rush'] => '1' ) );
		$cart = $t::calculate();
		$t::money( 1350, $cart->get_total(), '100 + 1,250' );
	}
);

// Stores that write 12,50 for twelve and a half. The product price box reads "10,00" as 10, so
// the option price boxes must read "12,50" as 12.50.
$t::test(
	'Option price typed with a decimal comma keeps its cents',
	function () use ( $t, $products_make_options, $products_prices, $products_option_prices, $products_add_store ) {
		$t::option( 'currency_thousands_separator', '.' );
		$t::option( 'currency_decimal_separator', ',' );
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$product = $t::product( 10, array( 'taxable' => false ) );
		$products_prices( $product, array( $default => '10,00' ) );
		$products_option_prices( $product, array( $o['rush'] => array( $default => '12,50' ) ) );

		$products_add_store( $product, $default, 2, array( $o['rush'] => '1' ) );
		$cart = $t::calculate();
		$t::money( 45, $cart->get_total(), '(10 + 12.50) x 2' );
	}
);

/*
 * SPC_Product::get_option_item_price(), which the cart and the saved order use for each option.
 */

$t::test(
	'Option price lookup: without a price level, and on a product loaded without one',
	function () use ( $t, $products_make_options, $products_level, $products_prices, $products_option_prices, $products_warnings ) {
		$o       = $products_make_options();
		$default = sunshine_get_default_price_level_id();
		$wedding = $products_level( 'wedding' );
		$id      = $t::product( 10 );
		$products_prices( $id, array( $default => '10', $wedding => '25' ) );
		$products_option_prices(
			$id,
			array(
				$o['frame'] => array( 'items' => array( 'black01' => array( $default => '20', $wedding => '30' ) ) ),
				$o['rush']  => array( $default => '5', $wedding => '8' ),
			)
		);
		$prices   = array();
		$warnings = $products_warnings(
			function () use ( $id, $o, $wedding, &$prices ) {
				// No level given: use the level the product was loaded at.
				$at_wedding         = sunshine_get_product( $id, $wedding );
				$prices['checkbox'] = $at_wedding->get_option_item_price( $o['rush'], '1' );
				$prices['select']   = $at_wedding->get_option_item_price( $o['frame'], 'black01' );
				// Level given, product loaded without one.
				$plain                    = sunshine_get_product( $id );
				$prices['plain checkbox'] = $plain->get_option_item_price( $o['rush'], '1', $wedding );
				$prices['plain select']   = $plain->get_option_item_price( $o['frame'], 'black01', $wedding );
			}
		);
		$t::money( 8, $prices['checkbox'], 'checkbox, no level given' );
		$t::money( 30, $prices['select'], 'select, no level given' );
		$t::money( 8, $prices['plain checkbox'], 'checkbox, product loaded without a level' );
		$t::money( 30, $prices['plain select'], 'select, product loaded without a level' );
		$t::same( array(), $warnings, 'PHP warnings' );
	}
);
