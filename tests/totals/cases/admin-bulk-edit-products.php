<?php
/**
 * Quick Edit and Bulk Edit on the Products list (Products > All Products).
 *
 * Quick Edit shows every Sunshine box of one product, filled with what is saved, and saves them
 * all again on Update. Bulk Edit shows the boxes empty ("No change") and should only change the
 * boxes the photographer fills in, on every ticked product.
 *
 * Extra Shipping Cost is one box per price level. It is added to a flat rate once for each item,
 * at the price level of the cart line (see the Extra Shipping docs).
 *
 * Every test uses its own second price level, named to sort after the store's levels, so the
 * store's default level stays the default.
 */

require_once __DIR__ . '/admin-bulk-edit-helpers.php';

$t = 'SPC_Totals_Test';

// Add products to the cart for one photo in a new gallery at this price level, the way a customer
// does in the add to cart window. $products is product id => quantity.
$abe_prod_buy = function ( $level_id, $products ) use ( $abe_gallery, $abe_photo, $abe_add_photo ) {
	$photo = $abe_photo( $abe_gallery( $level_id ) );
	foreach ( $products as $product_id => $qty ) {
		$reply = $abe_add_photo( $product_id, $photo, $qty );
		if ( empty( $reply['success'] ) ) {
			throw new Exception( 'Could not add product ' . $product_id . ' to the cart: ' . wp_json_encode( $reply ) );
		}
	}
	return $photo;
};

// What the customer pays for shipping, tax included.
$abe_prod_ship_paid = function ( $cart ) {
	return (float) $cart->get_shipping() + (float) $cart->get_shipping_tax();
};

// Everything the Quick/Bulk Edit boxes of a product hold, at the two levels a test uses.
$abe_prod_settings = function ( $product_id, $levels ) use ( $abe_saved ) {
	$settings = array();
	foreach ( $levels as $level_id ) {
		$settings[ 'price ' . $level_id ]    = $abe_saved( $product_id, 'price', $level_id );
		$settings[ 'shipping ' . $level_id ] = $abe_saved( $product_id, 'shipping', $level_id );
	}
	foreach ( array( 'type', 'taxable', 'disable_shipping', 'min_qty', 'max_qty' ) as $key ) {
		$settings[ $key ] = get_post_meta( $product_id, $key, true );
	}
	return $settings;
};

/*
 * Bulk Edit
 */

$abe_test(
	'Bulk Edit: every box left on "No change" keeps prices, Extra Shipping Cost and settings',
	function () use ( $t, $abe_level, $abe_product, $abe_bulk_edit, $abe_prod_buy, $abe_prod_settings ) {
		$t::tax( 10 );
		$default = sunshine_get_default_price_level_id();
		$level   = $abe_level( 'keep' );
		$canvas  = $abe_product(
			array(
				'price'    => array( $default => '20', $level => '30' ),
				'shipping' => array( $default => '2', $level => '3' ),
				'max_qty'  => '5',
			)
		);
		$print   = $abe_product(
			array(
				'price'    => array( $default => '8', $level => '9' ),
				'shipping' => array( $default => '1', $level => '1.50' ),
				'taxable'  => false,
				'min_qty'  => '2',
			)
		);
		$before  = array( $abe_prod_settings( $canvas, array( $default, $level ) ), $abe_prod_settings( $print, array( $default, $level ) ) );

		$done = $abe_bulk_edit( array( $canvas, $print ) );
		$t::same( array( $canvas, $print ), $done['updated'], 'both products saved' );
		$t::same( $before[0], $abe_prod_settings( $canvas, array( $default, $level ) ), 'canvas settings kept' );
		$t::same( $before[1], $abe_prod_settings( $print, array( $default, $level ) ), 'print settings kept' );

		$t::flat_shipping( 5 );
		$abe_prod_buy( $level, array( $canvas => 2, $print => 2 ) );
		$cart = $t::calculate();
		// Items 2 x 30 + 2 x 9 = 78. Tax 10% of the canvases' 60 = 6.
		// Shipping 5 + 2 x 3 + 2 x 1.50 = 14. Total 78 + 6 + 14 = 98.
		$t::money( 78, $cart->get_subtotal(), 'subtotal' );
		$t::money( 6, $cart->get_tax(), 'tax' );
		$t::money( 14, $cart->get_shipping(), 'shipping' );
		$t::money( 98, $cart->get_total(), 'total' );
	}
);

// The bulk panel shows one Extra Shipping Cost box per price level, each saying "No change".
// Filling one box should change only that level. The other levels keep what each product has.
$abe_test(
	'Bulk Edit: Extra Shipping Cost typed for one price level keeps the other levels',
	function () use ( $t, $abe_level, $abe_product, $abe_bulk_edit, $abe_prod_buy, $abe_saved ) {
		$default = sunshine_get_default_price_level_id();
		$level   = $abe_level( 'one level' );
		$canvas  = $abe_product(
			array(
				'price'    => array( $default => '20', $level => '30' ),
				'shipping' => array( $default => '2', $level => '3' ),
				'taxable'  => false,
			)
		);
		$metal   = $abe_product(
			array(
				'price'    => array( $default => '10', $level => '10' ),
				'shipping' => array( $default => '1', $level => '4' ),
				'taxable'  => false,
			)
		);

		$abe_bulk_edit( array( $canvas, $metal ), array( 'shipping[' . $default . ']' => '6' ) );
		$t::money( 6, $abe_saved( $canvas, 'shipping', $default ), 'canvas: typed level is 6' );
		$t::money( 6, $abe_saved( $metal, 'shipping', $default ), 'metal print: typed level is 6' );
		$t::same( '3', $abe_saved( $canvas, 'shipping', $level ), 'canvas: other level keeps 3' );
		$t::same( '4', $abe_saved( $metal, 'shipping', $level ), 'metal print: other level keeps 4' );

		$t::flat_shipping( 5 );
		$abe_prod_buy( $level, array( $canvas => 1, $metal => 2 ) );
		$abe_prod_buy( $default, array( $canvas => 1 ) );
		$t::checkout_address();
		$cart = $t::calculate();
		// Items: 30 + 2 x 10 + 20 = 70.
		// Shipping: 5 + 3 (canvas, other level) + 2 x 4 (metal, other level) + 6 (canvas, typed level) = 22.
		$t::money( 22, $cart->get_shipping(), 'cart shipping' );
		$t::money( 92, $cart->get_total(), 'cart total 70 + 22' );
		$order = $t::place_order();
		$t::money( 22, $order->get_shipping(), 'order shipping' );
		$t::money( 92, $order->get_total(), 'order total' );
	}
);

// 0 is a value, not "No change": the photographer makes the extra cost free at one level.
$abe_test(
	'Bulk Edit: Extra Shipping Cost of 0 for one level makes it free there and keeps the other level',
	function () use ( $t, $abe_level, $abe_product, $abe_bulk_edit, $abe_prod_buy, $abe_saved ) {
		$default = sunshine_get_default_price_level_id();
		$level   = $abe_level( 'zero' );
		$canvas  = $abe_product(
			array(
				'price'    => array( $default => '15', $level => '15' ),
				'shipping' => array( $default => '4', $level => '3' ),
				'taxable'  => false,
			)
		);

		$abe_bulk_edit( array( $canvas ), array( 'shipping[' . $level . ']' => '0' ) );
		$t::same( '0', $abe_saved( $canvas, 'shipping', $level ), 'typed level is 0' );
		$t::same( '4', $abe_saved( $canvas, 'shipping', $default ), 'other level keeps 4' );

		$t::flat_shipping( 5 );
		$abe_prod_buy( $default, array( $canvas => 2 ) );
		$abe_prod_buy( $level, array( $canvas => 1 ) );
		$cart = $t::calculate();
		// Shipping 5 + 2 x 4 (default level) + 1 x 0 (free level) = 13. Items 3 x 15 = 45.
		$t::money( 13, $cart->get_shipping(), 'shipping' );
		$t::money( 58, $cart->get_total(), 'total 45 + 13' );
	}
);

// Both levels typed: everything changes, so nothing is lost. Prices include tax and the flat rate
// is taxed, so the Extra Shipping Cost the photographer types includes tax too.
$abe_test(
	'Bulk Edit: Extra Shipping Cost typed for both levels, prices include tax, order placed',
	function () use ( $t, $abe_level, $abe_product, $abe_bulk_edit, $abe_prod_buy, $abe_prod_ship_paid ) {
		$t::tax( 25 );
		$t::option( 'price_has_tax', 'yes' );
		$default = sunshine_get_default_price_level_id();
		$level   = $abe_level( 'both' );
		$canvas  = $abe_product(
			array(
				'price' => array( $default => '50', $level => '40' ),
			)
		);

		$abe_bulk_edit(
			array( $canvas ),
			array(
				'shipping[' . $default . ']' => '2.50',
				'shipping[' . $level . ']'   => '1.25',
			)
		);

		$t::flat_shipping( 5, true );
		$abe_prod_buy( $default, array( $canvas => 1 ) );
		$abe_prod_buy( $level, array( $canvas => 2 ) );
		$t::checkout_address();
		$cart = $t::calculate();
		// Shipping paid: 5 + 2.50 + 2 x 1.25 = 10, of which tax is 1 + 0.50 + 2 x 0.25 = 2.
		// Tax in the items: 50 - 40 = 10, and 2 x (40 - 32) = 16. Total 50 + 80 + 10 = 140.
		$t::money( 10, $abe_prod_ship_paid( $cart ), 'shipping paid' );
		$t::money( 2, $cart->get_shipping_tax(), 'tax in the shipping' );
		$t::money( 28, $cart->get_tax(), 'tax 10 + 16 + 2' );
		$t::money( 140, $cart->get_total(), 'total' );
		$order = $t::place_order();
		$t::money( 10, (float) $order->get_shipping() + (float) $order->get_shipping_tax(), 'order shipping paid' );
		$t::money( 28, $order->get_tax(), 'order tax' );
		$t::money( 140, $order->get_total(), 'order total' );
	}
);

$abe_test(
	'Bulk Edit: Taxable set to No stops tax on the ticked products only',
	function () use ( $t, $abe_product, $abe_bulk_edit ) {
		$t::tax( 10 );
		$default = sunshine_get_default_price_level_id();
		$canvas  = $abe_product( array( 'price' => array( $default => '20' ) ) );
		$print   = $abe_product( array( 'price' => array( $default => '10' ) ) );
		$card    = $abe_product( array( 'price' => array( $default => '5' ) ) );

		$abe_bulk_edit( array( $canvas, $print ), array( 'taxable' => '0' ) );
		$t::same( false, sunshine_get_product( $canvas )->is_taxable(), 'canvas not taxable' );
		$t::same( false, sunshine_get_product( $print )->is_taxable(), 'print not taxable' );
		$t::same( true, sunshine_get_product( $card )->is_taxable(), 'card (not ticked) still taxable' );

		$t::add( $canvas, 1 );
		$t::add( $print, 2 );
		$t::add( $card, 2 );
		$cart = $t::calculate();
		// Items 20 + 2 x 10 + 2 x 5 = 50. Tax only on the cards: 10% of 10 = 1.
		$t::money( 50, $cart->get_subtotal(), 'subtotal' );
		$t::money( 1, $cart->get_tax(), 'tax' );
		$t::money( 51, $cart->get_total(), 'total' );
	}
);

$abe_test(
	'Bulk Edit: Taxable set to No, prices include tax, order placed',
	function () use ( $t, $abe_product, $abe_bulk_edit ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$default = sunshine_get_default_price_level_id();
		$canvas  = $abe_product( array( 'price' => array( $default => '24' ) ) );
		$print   = $abe_product( array( 'price' => array( $default => '12' ) ) );

		$abe_bulk_edit( array( $canvas ), array( 'taxable' => '0' ) );

		$t::add( $canvas, 1 );
		$t::add( $print, 1 );
		$cart = $t::calculate();
		// The customer pays the prices shown: 24 + 12 = 36. Only the print has tax in it: 12 - 10 = 2.
		$t::money( 2, $cart->get_tax(), 'tax' );
		$t::money( 36, $cart->get_total(), 'total' );
		$order = $t::place_order();
		$t::money( 2, $order->get_tax(), 'order tax' );
		$t::money( 36, $order->get_total(), 'order total' );
	}
);

$abe_test(
	'Bulk Edit: Taxable set to Yes adds tax, order placed',
	function () use ( $t, $abe_product, $abe_bulk_edit ) {
		$t::tax( 8.25 );
		$default = sunshine_get_default_price_level_id();
		$print   = $abe_product(
			array(
				'price'   => array( $default => '19.99' ),
				'taxable' => false,
			)
		);

		$abe_bulk_edit( array( $print ), array( 'taxable' => '1' ) );

		$t::add( $print, 3 );
		$cart = $t::calculate();
		// 3 x 19.99 = 59.97. Tax 8.25% = 4.9475 = 4.95. Total 64.92.
		$t::money( 4.95, $cart->get_tax(), 'tax' );
		$t::money( 64.92, $cart->get_total(), 'total' );
		$order = $t::place_order();
		$t::money( 4.95, $order->get_tax(), 'order tax' );
		$t::money( 64.92, $order->get_total(), 'order total' );
	}
);

// A discount code and a gift card on top of a bulk change to Taxable.
$abe_test(
	'Bulk Edit: Taxable set to No, with a discount code and a gift card, order placed',
	function () use ( $t, $abe_product, $abe_bulk_edit ) {
		$t::tax( 10 );
		$default = sunshine_get_default_price_level_id();
		$album   = $abe_product( array( 'price' => array( $default => '40' ) ) );
		$canvas  = $abe_product( array( 'price' => array( $default => '60' ) ) );

		$abe_bulk_edit( array( $album ), array( 'taxable' => '0' ) );

		$t::add( $album, 1 );
		$t::add( $canvas, 1 );
		$t::discount( 'percent-total', 10 );
		$t::gift_card( 50 );
		$cart = $t::calculate();
		// 10% off: album 40 - 4 = 36, canvas 60 - 6 = 54. Tax only on the canvas: 10% of 54 = 5.40.
		// Order 36 + 54 + 5.40 = 95.40. The gift card pays 50, leaving 45.40.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 5.40, $cart->get_tax(), 'tax' );
		$t::money( 45.40, $cart->get_total(), 'left to pay' );
		$order = $t::place_order();
		$t::money( 5.40, $order->get_tax(), 'order tax' );
		$t::money( 45.40, $order->get_total(), 'order total' );
	}
);

$abe_test(
	'Bulk Edit: Disable Shipping set to Yes, then back to No',
	function () use ( $t, $abe_product, $abe_bulk_edit ) {
		$default  = sunshine_get_default_price_level_id();
		$download = $abe_product(
			array(
				'price'   => array( $default => '20' ),
				'taxable' => false,
			)
		);
		$print    = $abe_product(
			array(
				'price'   => array( $default => '10' ),
				'taxable' => false,
			)
		);

		$abe_bulk_edit( array( $download ), array( 'disable_shipping' => '1' ) );
		$t::flat_shipping( 5 );
		$t::add( $download, 2 );
		$cart = $t::calculate();
		// Nothing in the cart ships: 2 x 20, no shipping.
		$t::same( false, $cart->needs_shipping(), 'cart needs no shipping' );
		$t::money( 0, $cart->get_shipping(), 'no shipping' );
		$t::money( 40, $cart->get_total(), 'total' );

		// The print (not ticked) still ships.
		$t::add( $print, 1 );
		$cart = $t::new_request();
		$t::money( 5, $cart->get_shipping(), 'shipping for the print' );
		$t::money( 55, $cart->get_total(), 'total 40 + 10 + 5' );

		$abe_bulk_edit( array( $download ), array( 'disable_shipping' => '0' ) );
		$t::same( true, sunshine_get_product( $download )->needs_shipping(), 'ships again' );
	}
);

$abe_test(
	'Bulk Edit: Max Quantity left blank keeps each limit, a typed limit applies',
	function () use ( $t, $abe_product, $abe_bulk_edit ) {
		$default = sunshine_get_default_price_level_id();
		$canvas  = $abe_product(
			array(
				'price'   => array( $default => '10' ),
				'taxable' => false,
				'max_qty' => '3',
			)
		);
		$print   = $abe_product(
			array(
				'price'   => array( $default => '4' ),
				'taxable' => false,
			)
		);

		$abe_bulk_edit( array( $canvas, $print ) );
		$t::same( '3', get_post_meta( $canvas, 'max_qty', true ), 'canvas keeps its limit of 3' );
		$abe_bulk_edit( array( $print ), array( 'max_qty' => '2' ) );

		$t::add( $canvas, 5 );
		$t::add( $print, 5 );
		$cart = $t::calculate();
		// 3 canvases x 10 + 2 prints x 4 = 38.
		$t::money( 38, $cart->get_total(), 'total' );
	}
);

$abe_test(
	'Bulk Edit: Minimum Quantity typed applies to every ticked product',
	function () use ( $t, $abe_product, $abe_bulk_edit ) {
		$default = sunshine_get_default_price_level_id();
		$wallet  = $abe_product(
			array(
				'price'   => array( $default => '3' ),
				'taxable' => false,
			)
		);
		$mini    = $abe_product(
			array(
				'price'   => array( $default => '3' ),
				'taxable' => false,
			)
		);

		$abe_bulk_edit( array( $wallet, $mini ), array( 'min_qty' => '4' ) );

		$t::add( $wallet, 1 );
		$t::add( $mini, 6 );
		$cart = $t::calculate();
		// Wallets go up to the minimum of 4: 4 x 3 + 6 x 3 = 30.
		$t::money( 30, $cart->get_total(), 'total' );
	}
);

/*
 * Quick Edit
 */

// Quick Edit saves every box again on Update, filled from what is saved. Changing only the title
// must leave the money settings as they were.
$abe_test(
	'Quick Edit: changing only the title keeps prices, Extra Shipping Cost and settings',
	function () use ( $t, $abe_level, $abe_product, $abe_quick_edit, $abe_prod_buy, $abe_prod_settings ) {
		$t::tax( 10 );
		$default = sunshine_get_default_price_level_id();
		$level   = $abe_level( 'title' );
		$canvas  = $abe_product(
			array(
				'price'    => array( $default => '20', $level => '30' ),
				'shipping' => array( $default => '2', $level => '3' ),
				'max_qty'  => '5',
			)
		);
		$print   = $abe_product(
			array(
				'price'    => array( $default => '8', $level => '9' ),
				'shipping' => array( $default => '1', $level => '1.50' ),
				'taxable'  => false,
				'min_qty'  => '2',
			)
		);
		$before  = array( $abe_prod_settings( $canvas, array( $default, $level ) ), $abe_prod_settings( $print, array( $default, $level ) ) );

		$abe_quick_edit( $canvas, array(), 'Canvas 16x20' );
		$abe_quick_edit( $print, array(), 'Print 8x10' );
		$t::same( 'Canvas 16x20', get_post_field( 'post_title', $canvas ), 'new title saved' );
		$t::same( $before[0], $abe_prod_settings( $canvas, array( $default, $level ) ), 'canvas settings kept' );
		$t::same( $before[1], $abe_prod_settings( $print, array( $default, $level ) ), 'print settings kept' );

		$t::flat_shipping( 5 );
		$abe_prod_buy( $level, array( $canvas => 2, $print => 2 ) );
		$cart = $t::calculate();
		// Same as before the edit: items 78, tax 6, shipping 5 + 6 + 3 = 14, total 98.
		$t::money( 6, $cart->get_tax(), 'tax' );
		$t::money( 14, $cart->get_shipping(), 'shipping' );
		$t::money( 98, $cart->get_total(), 'total' );
	}
);

$abe_test(
	'Quick Edit: new price for one level, the other level keeps its price, order placed',
	function () use ( $t, $abe_level, $abe_product, $abe_quick_edit, $abe_prod_buy, $abe_saved ) {
		$t::tax( 10 );
		$default = sunshine_get_default_price_level_id();
		$level   = $abe_level( 'price' );
		$print   = $abe_product( array( 'price' => array( $default => '20', $level => '30' ) ) );

		$abe_quick_edit( $print, array( 'price[' . $default . ']' => '22.50' ) );
		$t::money( 22.50, sunshine_get_product( $print, $default )->get_price(), 'typed level' );
		$t::same( '30', $abe_saved( $print, 'price', $level ), 'other level kept' );

		$abe_prod_buy( $default, array( $print => 2 ) );
		$abe_prod_buy( $level, array( $print => 1 ) );
		$cart = $t::calculate();
		// 2 x 22.50 + 30 = 75. Tax 7.50. Total 82.50.
		$t::money( 75, $cart->get_subtotal(), 'subtotal' );
		$t::money( 7.50, $cart->get_tax(), 'tax' );
		$t::money( 82.50, $cart->get_total(), 'total' );
		$order = $t::place_order();
		$t::money( 82.50, $order->get_total(), 'order total' );
		$t::money( 7.50, $order->get_tax(), 'order tax' );
		$items = array_values( (array) $order->get_items() );
		$t::same( 2, count( $items ), 'two order lines' );
		if ( 2 === count( $items ) ) {
			$t::money( 22.50, $items[0]->get_price(), 'line 1 price' );
			$t::money( 30, $items[1]->get_price(), 'line 2 price' );
		}
	}
);

$abe_test(
	'Quick Edit: a price typed as 1,250.00 costs 1250',
	function () use ( $t, $abe_product, $abe_quick_edit ) {
		$default = sunshine_get_default_price_level_id();
		$mural   = $abe_product(
			array(
				'price'   => array( $default => '900' ),
				'taxable' => false,
			)
		);
		$abe_quick_edit( $mural, array( 'price[' . $default . ']' => '1,250.00' ) );
		$t::add( $mural, 2 );
		$cart = $t::calculate();
		$t::money( 2500, $cart->get_total(), '2 x 1250' );
	}
);

$abe_test(
	'Quick Edit: new price with prices that include tax, order placed',
	function () use ( $t, $abe_product, $abe_quick_edit ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$default = sunshine_get_default_price_level_id();
		$print   = $abe_product( array( 'price' => array( $default => '10' ) ) );

		$abe_quick_edit( $print, array( 'price[' . $default . ']' => '14.40' ) );
		$t::add( $print, 3 );
		$cart = $t::calculate();
		// 3 x 14.40 = 43.20, of which 3 x 2.40 = 7.20 is tax.
		$t::money( 7.20, $cart->get_tax(), 'tax' );
		$t::money( 43.20, $cart->get_total(), 'total' );
		$order = $t::place_order();
		$t::money( 7.20, $order->get_tax(), 'order tax' );
		$t::money( 43.20, $order->get_total(), 'order total' );
	}
);

// From the docs: "Leaving a price empty means the product will not be made available for the
// respective Price Level".
$abe_test(
	'Quick Edit: clearing one level\'s price stops sales at that level only',
	function () use ( $t, $abe_level, $abe_product, $abe_quick_edit, $abe_gallery, $abe_photo, $abe_add_photo ) {
		$default = sunshine_get_default_price_level_id();
		$level   = $abe_level( 'cleared' );
		$print   = $abe_product(
			array(
				'price'   => array( $default => '10', $level => '25' ),
				'taxable' => false,
			)
		);

		$abe_quick_edit( $print, array( 'price[' . $level . ']' => '' ) );
		$reply = $abe_add_photo( $print, $abe_photo( $abe_gallery( $level ) ), 1 );
		$t::same( false, ! empty( $reply['success'] ), 'not sold at the cleared level' );
		$reply = $abe_add_photo( $print, $abe_photo( $abe_gallery( $default ) ), 1 );
		$t::same( true, ! empty( $reply['success'] ), 'still sold at the default level' );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_total(), 'total' );
	}
);

// A price of 0 is a price: the product is free at that level, not missing.
$abe_test(
	'Quick Edit: a price of 0 makes the product free, not unavailable',
	function () use ( $t, $abe_product, $abe_quick_edit ) {
		$t::tax( 10 );
		$default = sunshine_get_default_price_level_id();
		$sample  = $abe_product( array( 'price' => array( $default => '5' ) ) );
		$print   = $abe_product( array( 'price' => array( $default => '20' ) ) );

		$abe_quick_edit( $sample, array( 'price[' . $default . ']' => '0' ) );
		$t::add( $sample, 2 );
		$t::add( $print, 1 );
		$cart = $t::calculate();
		$t::same( 2, count( (array) $cart->get_cart_items() ), 'both lines in the cart' );
		// 2 x 0 + 20 = 20, tax 2.
		$t::money( 2, $cart->get_tax(), 'tax' );
		$t::money( 22, $cart->get_total(), 'total' );
	}
);

$abe_test(
	'Quick Edit: unticking Taxable stops tax, ticking it again brings it back',
	function () use ( $t, $abe_product, $abe_quick_edit ) {
		$t::tax( 10 );
		$default = sunshine_get_default_price_level_id();
		$canvas  = $abe_product( array( 'price' => array( $default => '50' ) ) );

		$abe_quick_edit( $canvas, array( 'taxable' => false ) );
		$t::add( $canvas, 1 );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_tax(), 'no tax' );
		$t::money( 50, $cart->get_total(), 'total' );

		$abe_quick_edit( $canvas, array( 'taxable' => true ) );
		$cart = $t::new_request();
		$t::money( 5, $cart->get_tax(), 'tax 10% of 50' );
		$t::money( 55, $cart->get_total(), 'total' );
	}
);

$abe_test(
	'Quick Edit: ticking Disable Shipping means no shipping is charged',
	function () use ( $t, $abe_product, $abe_quick_edit ) {
		$default  = sunshine_get_default_price_level_id();
		$download = $abe_product(
			array(
				'price'   => array( $default => '20' ),
				'taxable' => false,
			)
		);
		$abe_quick_edit( $download, array( 'disable_shipping' => true ) );
		$t::flat_shipping( 5 );
		$t::add( $download, 1 );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_shipping(), 'no shipping' );
		$t::money( 20, $cart->get_total(), 'total' );
	}
);

$abe_test(
	'Quick Edit: Extra Shipping Cost typed for a second level, order placed',
	function () use ( $t, $abe_level, $abe_product, $abe_quick_edit, $abe_prod_buy, $abe_saved ) {
		$default = sunshine_get_default_price_level_id();
		$level   = $abe_level( 'extra' );
		$canvas  = $abe_product(
			array(
				'price'    => array( $default => '20', $level => '20' ),
				'shipping' => array( $default => '2' ),
				'taxable'  => false,
			)
		);

		$abe_quick_edit( $canvas, array( 'shipping[' . $level . ']' => '3.25' ) );
		$t::same( '2', $abe_saved( $canvas, 'shipping', $default ), 'default level kept' );

		$t::flat_shipping( 5 );
		$abe_prod_buy( $level, array( $canvas => 2 ) );
		$cart = $t::calculate();
		// Shipping 5 + 2 x 3.25 = 11.50. Items 40.
		$t::money( 11.50, $cart->get_shipping(), 'shipping' );
		$t::money( 51.50, $cart->get_total(), 'total' );
		$order = $t::place_order();
		$t::money( 11.50, $order->get_shipping(), 'order shipping' );
		$t::money( 51.50, $order->get_total(), 'order total' );
	}
);

/*
 * Stores that write money with a decimal comma (12,50 and 1.250,00).
 *
 * The Quick Edit boxes are filled with the saved text exactly as it is saved, and Update runs it
 * through the same clean-up as the product edit screen, which takes out the store's thousands
 * separator. In these stores that is the dot.
 */

$abe_test(
	'Decimal comma: Quick Edit price typed as 12,50, saved again, order placed',
	function () use ( $t, $abe_product, $abe_quick_edit, $abe_comma_store ) {
		$abe_comma_store();
		$t::tax( 20 );
		$default = sunshine_get_default_price_level_id();
		$print   = $abe_product( array( 'price' => array( $default => '1' ) ) );

		$abe_quick_edit( $print, array( 'price[' . $default . ']' => '12,50' ) );
		// The photographer opens Quick Edit again and changes only the title.
		$abe_quick_edit( $print, array(), 'Print 5x7' );
		$t::money( 12.50, sunshine_get_product( $print, $default )->get_price(), 'saved price' );

		$t::add( $print, 3 );
		$cart = $t::calculate();
		// 3 x 12.50 = 37.50, tax 20% = 7.50.
		$t::money( 7.50, $cart->get_tax(), 'tax' );
		$t::money( 45, $cart->get_total(), 'total' );
		$order = $t::place_order();
		$t::money( 7.50, $order->get_tax(), 'order tax' );
		$t::money( 45, $order->get_total(), 'order total' );
	}
);

$abe_test(
	'Decimal comma: Quick Edit price typed as 1.250,00',
	function () use ( $t, $abe_product, $abe_quick_edit, $abe_comma_store ) {
		$abe_comma_store();
		$t::tax( 20 );
		$default = sunshine_get_default_price_level_id();
		$mural   = $abe_product( array( 'price' => array( $default => '900' ) ) );

		$abe_quick_edit( $mural, array( 'price[' . $default . ']' => '1.250,00' ) );
		$t::add( $mural, 1 );
		$cart = $t::calculate();
		$t::money( 250, $cart->get_tax(), 'tax 20% of 1250' );
		$t::money( 1500, $cart->get_total(), 'total' );
	}
);

$abe_test(
	'Decimal comma: price typed as 12,50 on the product screen is kept by a title-only Quick Edit',
	function () use ( $t, $abe_product, $abe_quick_edit, $abe_comma_store ) {
		$abe_comma_store();
		$default = sunshine_get_default_price_level_id();
		$print   = $abe_product(
			array(
				'price'   => array( $default => '12,50' ),
				'taxable' => false,
			)
		);
		$abe_quick_edit( $print, array(), 'Print 5x7' );
		$t::add( $print, 2 );
		$cart = $t::calculate();
		$t::money( 25, $cart->get_total(), '2 x 12.50' );
	}
);

// SPC_Product::set_price() (used by the Price Levels add-on's price screen and "copy prices at a
// percent") and the API save prices as a plain number with a dot (12.5). Quick Edit fills the box
// with 12.5 and Update takes the dot out. The photographer only changed the title.
$abe_test(
	'Decimal comma: a price saved by code as 12.5 is kept by a title-only Quick Edit',
	function () use ( $t, $abe_product, $abe_quick_edit, $abe_comma_store ) {
		$abe_comma_store();
		$default = sunshine_get_default_price_level_id();
		$print   = $abe_product(
			array(
				'price'   => array( $default => '10' ),
				'taxable' => false,
			)
		);
		sunshine_get_product( $print )->set_price( 12.5 );
		$t::money( 12.50, sunshine_get_product( $print, $default )->get_price(), 'before Quick Edit' );

		$abe_quick_edit( $print, array(), 'Print 5x7' );
		$t::money( 12.50, sunshine_get_product( $print, $default )->get_price(), 'after Quick Edit' );
		$t::add( $print, 2 );
		$cart = $t::calculate();
		$t::money( 25, $cart->get_total(), '2 x 12.50' );
	}
);

// The API saves Extra Shipping Cost as a plain number with a dot (2.5).
$abe_test(
	'Decimal comma: Extra Shipping Cost saved by code as 2.5 is kept by a title-only Quick Edit',
	function () use ( $t, $abe_product, $abe_quick_edit, $abe_comma_store ) {
		$abe_comma_store();
		$default = sunshine_get_default_price_level_id();
		$canvas  = $abe_product(
			array(
				'price'   => array( $default => '20' ),
				'taxable' => false,
			)
		);
		update_post_meta( $canvas, 'shipping', array( $default => 2.5 ) );

		$abe_quick_edit( $canvas, array(), 'Canvas 16x20' );
		$t::flat_shipping( 5 );
		$t::add( $canvas, 2 );
		$cart = $t::calculate();
		// 5 + 2 x 2.50 = 10.
		$t::money( 10, $cart->get_shipping(), 'shipping' );
		$t::money( 50, $cart->get_total(), 'total 40 + 10' );
	}
);

// A whole number typed with a comma (3,00) is read right today, so this only shows what happens to
// the level that was left on "No change", where the API saved 1.5.
$abe_test(
	'Decimal comma: Bulk Edit Extra Shipping Cost for one level keeps the other level saved by code',
	function () use ( $t, $abe_level, $abe_product, $abe_bulk_edit, $abe_prod_buy, $abe_comma_store ) {
		$abe_comma_store();
		$default = sunshine_get_default_price_level_id();
		$level   = $abe_level( 'comma bulk' );
		$canvas  = $abe_product(
			array(
				'price'   => array( $default => '20', $level => '20' ),
				'taxable' => false,
			)
		);
		update_post_meta(
			$canvas,
			'shipping',
			array(
				$default => 2.5,
				$level   => 1.5,
			)
		);

		$abe_bulk_edit( array( $canvas ), array( 'shipping[' . $default . ']' => '3,00' ) );
		$t::flat_shipping( 5 );
		$abe_prod_buy( $default, array( $canvas => 1 ) );
		$abe_prod_buy( $level, array( $canvas => 2 ) );
		$cart = $t::calculate();
		// 5 + 3 (typed level) + 2 x 1.50 (level left on "No change") = 11. Items 60.
		$t::money( 11, $cart->get_shipping(), 'shipping' );
		$t::money( 71, $cart->get_total(), 'total' );
	}
);
