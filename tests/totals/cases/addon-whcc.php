<?php
/**
 * WHCC add-on: the cart math.
 *
 * WHCC sets the price of what the customer builds. The photographer sets a markup percent per
 * price level, and the cart charges WHCC's price for one plus that markup. WHCC's price for one
 * can drop as the quantity rises, so the cart asks WHCC again whenever the cart changes. Tax,
 * discount codes, gift cards and store credit then treat the item like any other product.
 *
 * Shipping is WHCC's drop ship. One price check with the customer's address prices every
 * shipping method WHCC offers for it, and the photographer can add a percent and a flat amount
 * on top of each. WHCC charges for each package it ships.
 *
 * Setup is in addon-whcc-helpers.php. Nothing here reaches WHCC: a made-up WHCC answers.
 */

require_once __DIR__ . '/addon-whcc-helpers.php';

$t = 'SPC_Totals_Test';

/*
 * Adding an item
 */

$whcc_test(
	'the cart charges WHCC\'s price plus the markup',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_line, $whcc_sent ) {
		$product = $whcc_product( null, false );
		// Built with a quantity of 3. WHCC charges 6.00 each; with the 50% markup that's 9.00.
		$id   = $whcc_buy( $product, 6.00, 3 );
		$cart = $t::calculate();
		$line = $whcc_line( $id );
		$t::same( 3, $line ? (int) $line->get_qty() : null, 'quantity from the editor' );
		$t::money( 9.00, $line ? $line->get_price() : null, 'price for one: 6.00 + 50%' );
		$t::money( 27.00, $line ? $line->get_total() : null, 'line total' );
		$t::money( 6.00, $line ? $line->get_meta_value( 'whcc_unit_cost' ) : null, "WHCC's price for one is kept for profit reports" );
		$t::same( '8×10 Photo Print', $line ? $line->get_meta_value( 'whcc_name' ) : null, 'what WHCC calls the item is shown in the cart' );
		$t::money( 27.00, $cart->get_total(), 'total' );

		// The price check doesn't guess where the order is going, and leaves the quantity to WHCC
		// (what the customer chose in the editor).
		$sent = $whcc_sent( 'POST', 'commerce/submissions/calculate' );
		$body = isset( $sent[0]['body'] ) ? $sent[0]['body'] : array();
		$t::same( array( array( 'configuredProductId' => $id ) ), isset( $body['items'] ) ? $body['items'] : null, 'WHCC is asked about this item' );
		$t::same( false, isset( $body['shipping']['address'] ), 'no address is sent before checkout' );
	}
);

// The whole path a customer takes: pick a WHCC product on a photo, the add-on builds it at
// WHCC with that photo and opens WHCC's editor, the editor sends them back, and it's in the cart.
$whcc_test(
	'choosing a WHCC product on a photo builds it at WHCC and opens WHCC\'s editor',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_gallery, $whcc_sent, $whcc_return, $whcc_line ) {
		$product = $whcc_product( null, false );
		$ref     = get_post_meta( $product, 'whcc_product', true );
		$gallery = $whcc_gallery( sunshine_get_default_price_level_id() );
		$image   = sunshine_get_image( $gallery['photo'] );
		// A test site can send WHCC a public photo of its own (sunshine_whcc_image_url). This
		// checks the add-on's own link, so that filter is set aside while the item is built.
		global $wp_filter;
		$site_photo_filter = isset( $wp_filter['sunshine_whcc_image_url'] ) ? $wp_filter['sunshine_whcc_image_url'] : null;
		unset( $wp_filter['sunshine_whcc_image_url'] );
		try {
			$result = sunshine_whcc_product_details( array( 'html' => '' ), sunshine_get_product( $product, $image->get_price_level() ), $image );
		} finally {
			if ( $site_photo_filter ) {
				$wp_filter['sunshine_whcc_image_url'] = $site_photo_filter;
			}
		}

		$built = $whcc_sent( 'POST', 'commerce/configured-products' );
		$t::same( 1, count( $built ), 'the item is built at WHCC once' );
		$request = isset( $built[0]['body']['configuredProducts'][0] ) ? $built[0]['body']['configuredProducts'][0] : array();
		$t::same( $ref, isset( $request['productId'] ) ? $request['productId'] : null, 'from the WHCC product the photographer chose' );
		// Every field starts at its default or first choice WHCC allows. The canvas finish is left
		// out because the surface is paper (WHCC refuses a choice for a surface not picked), and
		// orientation is left to WHCC, which reads it from the photo.
		$t::same(
			array(
				'size'    => '8x10',
				'surface' => 'paper',
				'paper'   => 'lustre',
			),
			isset( $request['selections'] ) ? $request['selections'] : null,
			'starting choices'
		);
		$photo_url = isset( $request['images'][0]['url'] ) ? $request['images'][0]['url'] : '';
		$t::same( true, false !== strpos( $photo_url, 'sunshine_lab_file=' . $gallery['photo'] ), 'WHCC fetches the photo the customer picked from a signed link' );

		$id = '';
		foreach ( $whcc_lab['items'] as $item_id => $item ) {
			$id = $item_id;
		}
		$links = $whcc_sent( 'POST', 'commerce/configured-products/' . rawurlencode( $id ) . '/edit-link' );
		$link  = isset( $links[0]['body'] ) ? $links[0]['body'] : array();
		$t::same( 'https://editor.whcc.example/edit/' . rawurlencode( $id ) . '?session=spc', isset( $result['url'] ) ? $result['url'] : null, 'the customer is sent to WHCC\'s editor' );
		// WHCC's own prices would not match the store's, quantity is set in the cart so the cart
		// and WHCC never disagree, and the customer stays on this product with this photo.
		$t::same( true, ! empty( $link['settings']['hidePricing'] ), "WHCC's prices are hidden" );
		$t::same( false, isset( $link['settings']['quantitySelector'] ) ? $link['settings']['quantitySelector'] : null, 'no quantity in the editor' );
		$t::same( true, ! empty( $link['settings']['disableUploads'] ), 'no other photos can be uploaded' );
		$t::same( false, isset( $link['settings']['productSwitcher'] ) ? $link['settings']['productSwitcher'] : null, 'no switching to another product' );
		$complete = isset( $link['redirects']['complete']['url'] ) ? $link['redirects']['complete']['url'] : '';
		$t::same( true, false !== strpos( $complete, 'whcc_item=' . rawurlencode( $id ) ), 'finishing in the editor comes back with the item' );

		$remembered = sunshine_whcc_get_remembered_item( $id );
		$t::same( (int) $product, $remembered ? (int) $remembered['product_id'] : null, 'this visitor built it, for this product' );

		// Back from the editor: the item is added at WHCC's price plus the markup.
		$item = $whcc_return( $id );
		$t::same( true, ! empty( $item ), 'the item is in the cart' );
		$t::same( (int) $gallery['photo'], $item ? (int) $item['image_id'] : null, 'with the photo it was made from' );
		$t::calculate();
		$line = $whcc_line( $id );
		$t::money( 15.00, $line ? $line->get_price() : null, '10.00 + 50%' );

		// When WHCC can't fetch the photo, the editor would open blank. The customer is told,
		// and nothing is remembered for the cart.
		// Every editor link asked for so far, for any item: the failed build has a new item id.
		$edit_links        = function () use ( &$whcc_lab ) {
			return count( preg_grep( '#/edit-link$#', array_column( $whcc_lab['requests'], 'endpoint' ) ) );
		};
		$links_before      = $edit_links();
		$items_before      = array_keys( $whcc_lab['items'] );
		$whcc_lab['build'] = 'failed';
		$result            = sunshine_whcc_product_details( array( 'html' => '' ), sunshine_get_product( $product, $image->get_price_level() ), $image );
		$failed_id         = current( array_diff( array_keys( $whcc_lab['items'] ), $items_before ) );
		$t::same( true, false !== $failed_id, 'WHCC was asked to build it' );
		$t::same( true, empty( $result['url'] ), 'the editor does not open when WHCC could not fetch the photo' );
		$t::same( true, ! empty( $result['html'] ), 'the customer is told' );
		$t::same( $links_before, $edit_links(), 'no editor link was asked for' );
		$t::same( false, (bool) sunshine_whcc_get_remembered_item( $failed_id ), 'the failed item is not remembered for the cart' );
	}
);

// The link back from the editor is a plain link anyone could copy. Only an item this visitor
// built can come into this cart, or one customer could buy another's item at their price.
$whcc_test(
	'an item this visitor did not build is not added',
	function () use ( $t, $whcc_product, $whcc_item, $whcc_return, $whcc_sent, $whcc_notices ) {
		$product = $whcc_product( null, false );
		$id      = $whcc_item( $product, 6.00, 1 );
		$item    = $whcc_return( $id );
		$t::same( null, $item, 'not in the cart' );
		$t::same( 0, count( $whcc_sent( 'POST', 'commerce/submissions/calculate' ) ), 'WHCC is not even asked' );
		$t::same( true, in_array( 'error: Could not add item to cart', $whcc_notices(), true ), 'the customer is told' );
	}
);

// The back button or a reload opens the same "back from the editor" link again.
$whcc_test(
	'coming back from the editor twice adds the item once',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_return, $whcc_sent ) {
		$product = $whcc_product( null, false );
		$id      = $whcc_buy( $product, 6.00, 3 );
		$asked   = count( $whcc_sent( 'POST', 'commerce/submissions/calculate' ) );
		$whcc_return( $id );
		$cart = $t::calculate();
		$t::same( 1, count( (array) $cart->get_cart() ), 'one line' );
		$t::money( 27.00, $cart->get_total(), 'still 3 x 9.00' );
		$t::same( $asked, count( $whcc_sent( 'POST', 'commerce/submissions/calculate' ) ), 'WHCC is not asked again' );
	}
);

// A WHCC product saves a price of 0, so core would let it into the cart any other way (a store
// page, a direct request). It would cost nothing and never reach WHCC.
$whcc_test(
	'a WHCC product can only be added as an item built at WHCC',
	function () use ( $t, $whcc_product ) {
		$product = $whcc_product( null, false );
		SPC()->cart->add_item( $product, 0, '', '', array(), 2 );
		$cart = $t::calculate();
		$t::same( 0, count( (array) $cart->get_cart() ), 'nothing was added' );
		$t::money( 0, $cart->get_total(), 'nothing to pay' );
	}
);

/*
 * Markup per price level. A gallery's photos are sold at the gallery's price level.
 */

$whcc_test(
	'each price level has its own markup',
	function () use ( $t, $whcc_product, $whcc_other_level ) {
		$default = sunshine_get_default_price_level_id();
		$other   = $whcc_other_level();
		$product = $whcc_product(
			array(
				$default => '35',
				$other   => '20',
			)
		);
		$t::same( 35.0, sunshine_get_product( $product, $default )->get_markup(), 'default price level' );
		$t::same( 20.0, sunshine_get_product( $product, $other )->get_markup(), 'other price level' );
		$t::same( 35.0, sunshine_get_product( $product )->get_markup(), 'no price level given uses the default' );
		$t::money( 13.50, sunshine_whcc_customer_unit_price( sunshine_get_product( $product ), 10.00, $default ), '10.00 + 35%' );
		$t::money( 12.00, sunshine_whcc_customer_unit_price( sunshine_get_product( $product ), 10.00, $other ), '10.00 + 20%' );

		// A markup of 0 sells at WHCC's price. It is not the same as no markup.
		$zero = $whcc_product( array( $default => '0' ) );
		$t::same( 0.0, sunshine_get_product( $zero, $default )->get_markup(), 'a markup of 0 is kept as 0' );
		$t::money( 10.00, sunshine_whcc_customer_unit_price( sunshine_get_product( $zero ), 10.00, $default ), "sold at WHCC's price" );
	}
);

$whcc_test(
	'a photo from a gallery at another price level gets that level\'s markup',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_line, $whcc_other_level, $whcc_gallery, $whcc_cart_line ) {
		$default = sunshine_get_default_price_level_id();
		$other   = $whcc_other_level();
		$product = $whcc_product(
			array(
				$default => '35',
				$other   => '20',
			),
			false
		);
		$gallery = $whcc_gallery( $other );
		$id      = $whcc_buy(
			$product,
			10.00,
			2,
			array(
				'image_id'    => $gallery['photo'],
				'gallery_id'  => $gallery['id'],
				'price_level' => $other,
			)
		);
		$item = $whcc_cart_line( $id );
		$t::same( $other, $item ? (int) $item['price_level'] : null, "the line uses the gallery's price level" );
		$cart = $t::calculate();
		$line = $whcc_line( $id );
		$t::money( 12.00, $line ? $line->get_price() : null, '10.00 + 20%, not the default 35%' );
		$t::money( 24.00, $cart->get_total(), '2 x 12.00' );

		// Prices change when the cart changes, and must stay at the gallery's markup.
		do_action( 'sunshine_cart_update' );
		$cart = $t::new_request();
		$t::money( 24.00, $cart->get_total(), 'still 2 x 12.00 after the cart is re-priced' );
	}
);

// Leaving the markup blank for a price level is how a photographer chooses not to sell a WHCC
// product in galleries at that level.
$whcc_test(
	'a product with no markup at a gallery\'s price level is not sold there',
	function () use ( $t, $whcc_product, $whcc_item, $whcc_return, $whcc_other_level, $whcc_notices, $whcc_sent ) {
		$other   = $whcc_other_level();
		$product = $whcc_product( array( sunshine_get_default_price_level_id() => '50' ), false );
		$id      = $whcc_item( $product, 10.00, 1 );
		$item    = $whcc_return(
			$id,
			array(
				'product_id'  => $product,
				'image_id'    => 0,
				'gallery_id'  => '',
				'price_level' => $other,
			)
		);
		$t::same( null, $item, 'not added' );
		// WHCC priced it and found nothing wrong, so the missing markup is what refused it.
		$t::same( 1, count( $whcc_sent( 'POST', 'commerce/submissions/calculate' ) ), 'WHCC priced the item' );
		$t::same( true, in_array( 'error: Could not add item to cart', $whcc_notices(), true ), 'the customer is told' );
	}
);

// Stores that write money with a decimal comma type the markup as 12,5.
$whcc_test(
	'a markup typed as 12,5 in a decimal-comma store',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_line ) {
		$t::option( 'currency', 'EUR' );
		$t::option( 'currency_thousands_separator', '.' );
		$t::option( 'currency_decimal_separator', ',' );
		$product = $whcc_product( array( sunshine_get_default_price_level_id() => '12,5' ), false );
		$t::same( 12.5, sunshine_get_product( $product )->get_markup(), 'the markup is 12.5%' );
		$id   = $whcc_buy( $product, 10.00, 2 );
		$cart = $t::calculate();
		$line = $whcc_line( $id );
		$t::money( 11.25, $line ? $line->get_price() : null, '10.00 + 12.5%' );
		$t::money( 22.50, $cart->get_total(), '2 x 11.25' );
	}
);

/*
 * Quantity and changes made at WHCC
 */

// WHCC's price for one can drop as the quantity rises. The cart's new quantity has to be the
// one WHCC prices. It once sent the quantity from before the change, so 5 prints were charged
// at the price for 2.
$whcc_test(
	'changing the quantity in the cart gets WHCC\'s price for the new quantity',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_line, $whcc_update_cart, $whcc_sent ) {
		$product = $whcc_product( null, false );
		// 10.00 each, or 8.00 each for 5 or more.
		$id = $whcc_buy(
			$product,
			array(
				1 => 10.00,
				5 => 8.00,
			),
			2
		);
		$cart = $t::calculate();
		$t::money( 30.00, $cart->get_total(), '2 x 15.00' );

		$cart = $whcc_update_cart( array( $id => 5 ) );
		$sent = $whcc_sent( 'POST', 'commerce/submissions/calculate' );
		$last = end( $sent );
		$t::same( 5, isset( $last['body']['items'][0]['quantity'] ) ? $last['body']['items'][0]['quantity'] : null, 'WHCC is asked about 5' );
		$line = $whcc_line( $id );
		$t::same( 5, $line ? (int) $line->get_qty() : null, 'quantity' );
		$t::money( 12.00, $line ? $line->get_price() : null, '8.00 + 50%' );
		$t::money( 8.00, $line ? $line->get_meta_value( 'whcc_unit_cost' ) : null, "WHCC's price for one at 5" );
		$t::money( 60.00, $cart->get_total(), '5 x 12.00' );

		$cart = $t::new_request();
		$t::money( 60.00, $cart->get_total(), 'total on the next page load' );

		// And back down: 2 are 10.00 each again.
		$cart = $whcc_update_cart( array( $id => 2 ) );
		$t::money( 30.00, $cart->get_total(), 'back to 2 x 15.00' );
	}
);

$whcc_test(
	'if WHCC cannot be reached, the cart keeps the price it had',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_buy, $whcc_line, $whcc_update_cart, $whcc_sent ) {
		$id                    = $whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_lab['calculate'] = 500;
		$asked                 = count( $whcc_sent( 'POST', 'commerce/submissions/calculate' ) );
		$cart                  = $whcc_update_cart();
		$line                  = $whcc_line( $id );
		$t::same( true, count( $whcc_sent( 'POST', 'commerce/submissions/calculate' ) ) > $asked, 'the cart update asked WHCC' );
		$t::money( 27.00, $cart->get_total(), 'total is still 3 x 9.00' );
		$t::money( 6.00, $line ? $line->get_meta_value( 'whcc_unit_cost' ) : null, "WHCC's price is kept" );
	}
);

// The customer opens a cart item in WHCC's editor again and changes it (here an 8x10 becomes a
// 16x20). Edits change the same item at WHCC, so the cart has to ask what it is now. The
// quantity stays the cart's: the editor has no quantity.
$whcc_test(
	'editing an item in WHCC\'s editor updates its price and name',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_buy, $whcc_line, $whcc_cart_line, $whcc_until_redirect, $whcc_sent, $whcc_notices, $whcc_clear_notices ) {
		$id   = $whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$item = $whcc_cart_line( $id );
		$hash = $item ? $item['hash'] : '';

		$_GET['whcc_edit'] = $hash;
		$location          = $whcc_until_redirect( 'sunshine_whcc_cart_edit_item' );
		unset( $_GET['whcc_edit'] );
		$t::same( 'https://editor.whcc.example/edit/' . rawurlencode( $id ) . '?session=spc', $location, 'Edit opens the same item in WHCC\'s editor' );
		$links    = $whcc_sent( 'POST', 'commerce/configured-products/' . rawurlencode( $id ) . '/edit-link' );
		$complete = isset( $links[0]['body']['redirects']['complete']['url'] ) ? $links[0]['body']['redirects']['complete']['url'] : '';
		$t::same( true, false !== strpos( $complete, 'whcc_updated_item=' . $hash ), 'finishing comes back to this cart line' );

		// In the editor: now a 16x20 at 40.00.
		$whcc_lab['items'][ $id ]['name']   = '16×20 Photo Print';
		$whcc_lab['items'][ $id ]['prices'] = array( 1 => 40.00 );
		$whcc_clear_notices();
		$_GET['whcc_updated_item'] = $hash;
		$whcc_until_redirect( 'sunshine_whcc_updated_item' );
		unset( $_GET['whcc_updated_item'] );
		$sent = $whcc_sent( 'POST', 'commerce/submissions/calculate' );
		$last = end( $sent );
		$t::same( 3, isset( $last['body']['items'][0]['quantity'] ) ? $last['body']['items'][0]['quantity'] : null, "priced at the cart's quantity" );

		$cart = $t::new_request();
		$line = $whcc_line( $id );
		$t::same( 3, $line ? (int) $line->get_qty() : null, 'quantity' );
		$t::money( 60.00, $line ? $line->get_price() : null, '40.00 + 50%' );
		$t::money( 180.00, $cart->get_total(), '3 x 60.00' );
		$t::same( '16×20 Photo Print', $line ? $line->get_meta_value( 'whcc_name' ) : null, 'the new size is shown' );
		$t::money( 40.00, $line ? $line->get_meta_value( 'whcc_unit_cost' ) : null, "WHCC's new price is kept for profit reports" );
		$t::same( array( 'success: Cart updated' ), $whcc_notices(), 'the customer is told' );

		// An edit WHCC can't make yet (the photo is too small for the new size) is shown at once,
		// so the customer can fix it before checkout.
		$whcc_lab['items'][ $id ]['valid']    = false;
		$whcc_lab['items'][ $id ]['problems'] = array( 'The photo is too small for this size.' );
		$whcc_clear_notices();
		$_GET['whcc_updated_item'] = $hash;
		$whcc_until_redirect( 'sunshine_whcc_updated_item' );
		unset( $_GET['whcc_updated_item'] );
		$t::same( array( "error: This item isn't ready to order: The photo is too small for this size." ), $whcc_notices(), 'the customer is told what is wrong' );
	}
);

/*
 * Tax
 */

$whcc_test(
	'tax on a taxed WHCC item next to an untaxed one',
	function () use ( $t, $whcc_product, $whcc_buy ) {
		$t::tax( 10 );
		$whcc_buy( $whcc_product(), 6.00, 3 );
		$whcc_buy( $whcc_product( null, false, 'Wall Art' ), 40.00, 1 );
		$cart = $t::calculate();
		// 10% of the taxed 27.00. The 60.00 canvas is not taxed.
		$t::money( 2.70, $cart->get_tax(), 'tax' );
		$t::money( 89.70, $cart->get_total(), '27.00 + 60.00 + 2.70' );
	}
);

// With prices that include tax, the marked-up price is what the customer pays, tax inside it.
$whcc_test(
	'prices include tax',
	function () use ( $t, $whcc_product, $whcc_buy ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$whcc_buy( $whcc_product( array( sunshine_get_default_price_level_id() => '10' ) ), 10.00, 3 );
		$cart = $t::calculate();
		// 3 x 11.00 = 33.00 with tax inside: 30.00 + 3.00 tax.
		$t::money( 3.00, $cart->get_tax(), 'tax inside the price' );
		$t::money( 33.00, $cart->get_total(), 'total' );
	}
);

/*
 * Shipping
 */

$whcc_test(
	'shipping: each method costs what WHCC charges for the address',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_offered, $whcc_sent, $whcc_next_page ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::same( array( 'lowest_cost', 'expedited', 'one_day_standard', 'one_day_priority' ), $whcc_offered(), 'every method WHCC offers' );
		$t::money( 8.00, $cart->get_shipping(), 'Economy' );
		$t::money( 35.00, $cart->get_total(), '27.00 + 8.00' );

		$with_address = array();
		foreach ( $whcc_sent( 'POST', 'commerce/submissions/calculate' ) as $request ) {
			if ( ! empty( $request['body']['shipping']['address'] ) ) {
				$with_address[] = $request['body']['shipping'];
			}
		}
		$t::same(
			array(
				'name'    => 'Totals Test',
				'addr1'   => '1 Main St',
				'city'    => 'Denver',
				'state'   => 'CO',
				'zip'     => '80202',
				'country' => 'US',
			),
			isset( $with_address[0]['address'] ) ? $with_address[0]['address'] : null,
			"WHCC prices shipping to the customer's address"
		);
		$t::same( false, isset( $with_address[0]['priority'] ), 'every method is priced at once' );

		// Another method on the next page: same cart, same address, so WHCC isn't asked again.
		SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcwhccone_day_priority' );
		$cart = $whcc_next_page();
		$t::money( 30.00, $cart->get_shipping(), 'Priority One-Day' );
		$asked = 0;
		foreach ( $whcc_sent( 'POST', 'commerce/submissions/calculate' ) as $request ) {
			$asked += empty( $request['body']['shipping']['address'] ) ? 0 : 1;
		}
		$t::same( 1, $asked, 'one price check covered every method' );
	}
);

$whcc_test(
	'shipping: the photographer\'s percent and flat markup are added',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_next_page ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup(
			array( 'lowest_cost', 'expedited' ),
			false,
			array(),
			array(
				'lowest_cost' => array( '10', '1.50' ),
				'expedited'   => array( '25', '' ),
			)
		);
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		// 8.00 + 10% + 1.50.
		$t::money( 10.30, $cart->get_shipping(), 'Economy' );

		SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcwhccexpedited' );
		$cart = $whcc_next_page();
		// 12.00 + 25%.
		$t::money( 15.00, $cart->get_shipping(), 'Expedited' );
	}
);

// WHCC ships some product types separately and charges for each package.
$whcc_test(
	'shipping: WHCC charges for each package',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_buy( $whcc_product( null, false, 'Wall Art' ), 40.00, 1 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::money( 16.00, $cart->get_shipping(), '8.00 for each package' );
		$t::money( 103.00, $cart->get_total(), '27.00 + 60.00 + 16.00' );

		// The photographer can show why: the packages, under the method.
		$method = sunshine_get_shipping_method_by_instance( 'spcwhcclowest_cost' );
		$t::same( '', $method ? $method->get_description() : null, 'no breakdown unless the photographer turns it on' );
		$t::option( 'whcc_show_shipping_packages', 'yes' );
		$method = sunshine_get_shipping_method_by_instance( 'spcwhcclowest_cost' );
		$t::same( true, $method && false !== strpos( $method->get_description(), 'Ships in 2 packages: Prints, Wall Art' ), 'the packages are listed' );
	}
);

// Shipping prices are kept for 15 minutes for the same cart and address. An edit in WHCC's
// editor keeps the item's id and quantity but can change what it costs to ship (a bigger
// size), so the kept price must not be used after an edit.
$whcc_test(
	'shipping: an item edited in WHCC\'s editor gets a new shipping price',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_buy, $whcc_cart_line, $whcc_ship_setup, $whcc_ship_with, $whcc_until_redirect, $whcc_next_page ) {
		$id = $whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::money( 8.00, $cart->get_shipping(), 'Economy for the 8x10s' );

		// In the editor the prints become 16x20s, which cost more to make and to ship.
		$whcc_lab['items'][ $id ]['name']    = '16×20 Photo Print';
		$whcc_lab['items'][ $id ]['prices']  = array( 1 => 40.00 );
		$whcc_lab['shipping']['lowest_cost'] = 11.00;
		$item                                = $whcc_cart_line( $id );
		$_GET['whcc_updated_item']           = $item ? $item['hash'] : '';
		$whcc_until_redirect( 'sunshine_whcc_updated_item' );
		unset( $_GET['whcc_updated_item'] );

		$cart = $whcc_next_page();
		$t::money( 11.00, $cart->get_shipping(), "WHCC's new shipping price, not the one kept from before the edit" );
		$t::money( 191.00, $cart->get_total(), '3 x 60.00 + 11.00' );
	}
);

$whcc_test(
	'shipping: methods WHCC doesn\'t offer for the address are not shown',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_offered ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		unset( $whcc_lab['shipping']['expedited'], $whcc_lab['shipping']['one_day_standard'] );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::same( array( 'lowest_cost', 'one_day_priority' ), $whcc_offered(), 'only what WHCC offers' );
		$t::money( 8.00, $cart->get_shipping(), 'Economy' );
	}
);

// Core needs the shipping methods to draw the Shipping Method step before there's an address.
// Until then there's nothing to price.
$whcc_test(
	'shipping: before the customer gives an address, nothing is priced',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_offered, $whcc_sent ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup();
		SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
		$cart = $t::calculate();
		$t::same( array( 'lowest_cost', 'expedited', 'one_day_standard', 'one_day_priority' ), $whcc_offered(), 'the methods are listed' );
		$t::money( 0, $cart->get_shipping(), 'no shipping charged yet' );
		$asked = 0;
		foreach ( $whcc_sent( 'POST', 'commerce/submissions/calculate' ) as $request ) {
			$asked += isset( $request['body']['shipping']['address'] ) ? 1 : 0;
		}
		$t::same( 0, $asked, 'WHCC is not asked about shipping without an address' );
	}
);

// When WHCC can't price shipping, no method may show a made-up price, and the customer is told
// to wait rather than to check an address that is fine.
$whcc_test(
	'shipping: when WHCC can\'t price shipping, no method is offered and the customer is told to wait',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_offered, $whcc_sent, $whcc_next_page ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_lab['calculate'] = 500;
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::same( array(), $whcc_offered(), 'no method' );
		$t::money( 0, $cart->get_shipping(), 'nothing is charged for shipping' );
		$t::same( "Shipping prices couldn't be loaded just now. Please wait a minute and try again.", apply_filters( 'sunshine_checkout_shipping_unavailable_message', 'core message' ), 'the customer is told to wait' );

		// The failure is remembered for a minute, so every page doesn't wait on WHCC again.
		$asked = count( $whcc_sent( 'POST', 'commerce/submissions/calculate' ) );
		$whcc_next_page();
		$whcc_offered();
		$t::same( $asked, count( $whcc_sent( 'POST', 'commerce/submissions/calculate' ) ), 'not asked again on the next page' );
	}
);

$whcc_test(
	'shipping: an address WHCC doesn\'t ship to offers no method',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_offered ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_lab['shipping'] = array();
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost', 'ON', 'CA' );
		$cart = $t::calculate();
		$t::same( array(), $whcc_offered(), 'no method' );
		$t::money( 0, $cart->get_shipping(), 'nothing is charged for shipping' );
		$t::same( 'core message', apply_filters( 'sunshine_checkout_shipping_unavailable_message', 'core message' ), "core's message about the address stays" );
	}
);

// Any other method would charge the customer a price that has nothing to do with what WHCC
// charges the photographer.
$whcc_test(
	'shipping: WHCC items only ship with WHCC methods',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_offered ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$flat = array(
			'spcwhccflat' => array(
				'id'         => 'flat_rate',
				'price'      => '5',
				'price_type' => '',
			),
		);
		$whcc_ship_setup( array( 'lowest_cost' ), false, $flat );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$t::same( array( 'lowest_cost' ), $whcc_offered(), 'the flat rate is not offered' );

		$whcc_ship_setup( array(), false, $flat );
		$t::new_request();
		$t::same( array(), $whcc_offered(), 'with no WHCC method on, nothing is offered' );
	}
);

// WHCC ships its items and the photographer ships their own, so the photographer's set
// surcharge is added to WHCC's shipping.
$whcc_test(
	'shipping: a cart with the photographer\'s own print adds the surcharge',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with, $whcc_offered ) {
		$t::option( 'whcc_mixed_cart_surcharge', '6.00' );
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::same( array( 'lowest_cost' ), $whcc_offered(), 'only WHCC shipping is offered' );
		$t::money( 14.00, $cart->get_shipping(), '8.00 + 6.00' );
		$t::money( 61.00, $cart->get_total(), '27.00 + 20.00 + 14.00' );
	}
);

// A download is not shipped, so it is not a reason for the photographer to ship anything.
$whcc_test(
	'shipping: a download in the cart adds no surcharge',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with ) {
		$t::require_plugin( 'sunshine_product_is_download', 'Digital Downloads add-on' );
		$t::option( 'whcc_mixed_cart_surcharge', '6.00' );
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$t::add( $t::product( 15, array( 'type' => 'download', 'taxable' => false ) ) );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::money( 8.00, $cart->get_shipping(), 'shipping' );
		$t::money( 50.00, $cart->get_total(), '27.00 + 15.00 + 8.00' );
	}
);

$whcc_test(
	'shipping: taxed shipping',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with ) {
		$t::tax( 10 );
		$whcc_buy( $whcc_product(), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ), true, array(), array( 'lowest_cost' => array( '', '2' ) ) );
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::money( 10.00, $cart->get_shipping(), '8.00 + 2.00' );
		$t::money( 1.00, $cart->get_shipping_tax(), 'tax on shipping' );
		$t::money( 3.70, $cart->get_tax(), '2.70 on the prints + 1.00 on shipping' );
		$t::money( 40.70, $cart->get_total(), '27.00 + 10.00 + 3.70' );
	}
);

// With prices that include tax, the shipping price is what the customer pays, tax inside it.
$whcc_test(
	'shipping: taxed shipping when prices include tax',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$whcc_buy( $whcc_product( array( sunshine_get_default_price_level_id() => '10' ) ), 10.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ), true, array(), array( 'lowest_cost' => array( '', '3' ) ) );
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		// 8.00 + 3.00 = 11.00 with tax inside: 10.00 + 1.00.
		$t::money( 10.00, $cart->get_shipping(), 'shipping before tax' );
		$t::money( 1.00, $cart->get_shipping_tax(), 'tax inside shipping' );
		$t::money( 44.00, $cart->get_total(), '33.00 + 11.00' );
	}
);

/*
 * Discount codes, gift cards and store credit
 */

$whcc_test(
	'a percent discount code',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with ) {
		$t::tax( 10 );
		$whcc_buy( $whcc_product(), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		$t::money( 2.70, $cart->get_discount(), '10% of 27.00' );
		$t::money( 2.43, $cart->get_tax(), '10% of 24.30' );
		$t::money( 8.00, $cart->get_shipping(), 'shipping' );
		$t::money( 34.73, $cart->get_total(), '24.30 + 2.43 + 8.00' );
	}
);

$whcc_test(
	'a discount code that includes shipping',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ), false, array(), array( 'lowest_cost' => array( '', '5' ) ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::discount( 'percent-total', 10, array( 'apply_to_shipping' => 1 ) );
		$cart = $t::calculate();
		// 10% of 27.00 + 10% of 13.00.
		$t::money( 4.00, $cart->get_discount(), 'discount' );
		$t::money( 36.00, $cart->get_total(), '40.00 - 4.00' );
	}
);

$whcc_test(
	'a discount taken after tax',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$whcc_buy( $whcc_product(), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::discount( 'percent-total', 20 );
		$cart = $t::calculate();
		// 27.00 + 2.70 tax = 29.70, less 20% = 23.76, then 8.00 shipping.
		$t::money( 5.94, $cart->get_discount(), '20% of 29.70' );
		$t::money( 31.76, $cart->get_total(), '23.76 + 8.00' );
	}
);

$whcc_test(
	'a gift card pays for the items and WHCC shipping',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with ) {
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::gift_card( 50 );
		$cart = $t::calculate();
		$t::money( 35.00, array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) ), 'taken from the card: 27.00 + 8.00' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

$whcc_test(
	'store credit',
	function () use ( $t, $whcc_product, $whcc_buy, $whcc_ship_setup, $whcc_ship_with ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		$whcc_buy( $whcc_product( null, false ), 6.00, 3 );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$cart = $t::calculate();
		$t::money( 20, $cart->get_credits_applied(), 'credit used' );
		$t::money( 15.00, $cart->get_total(), '35.00 - 20.00' );
	}
);
