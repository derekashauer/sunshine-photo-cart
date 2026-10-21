<?php
/**
 * Digital Downloads add-on: what a customer pays for digital files in the cart.
 *
 * A digital file is bought once. Asking for 3 of the same photo's download, adding it twice or
 * changing the quantity on the cart page still charges for one. Each photo is its own download.
 * An "Entire gallery download" is one price for a whole gallery, at the gallery's price level.
 * Downloads are never shipped, can be taxed or not like any product, and work with discount
 * codes, quantity discounts and gift cards.
 *
 * Items are added through the same requests the gallery pages send.
 */

require_once __DIR__ . '/addon-digital-downloads-helpers.php';

/*
 * One photo.
 */

$dl_test(
	'Download: one photo as a digital file',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo, $dl_lines ) {
		$t::tax( 8 );
		$gallery  = $dl_gallery( 2 );
		$download = $dl_product( 25 );
		$reply    = $dl_add_photo( $download, $gallery['photos'][0] );
		$t::same( true, ! empty( $reply['success'] ), 'added to the cart' );
		$cart  = $t::calculate();
		$lines = $dl_lines();
		$t::same( 1, count( $lines ), 'one line' );
		$t::same( 1, $lines ? $lines[0]->get_qty() : 0, 'one file' );
		$t::same( false, $cart->needs_shipping(), 'nothing to ship' );
		$t::money( 25, $cart->get_subtotal(), 'subtotal' );
		// 25 x 8%.
		$t::money( 2, $cart->get_tax(), 'tax' );
		$t::money( 27, $cart->get_total(), 'total' );
	}
);

// The add to cart window has a quantity box. A file is the same file however many are asked
// for, so the customer pays for one (changelog 3.0.4).
$dl_test(
	'Download: asking for 3 of the same photo charges for one',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo, $dl_lines ) {
		$gallery  = $dl_gallery( 1 );
		$download = $dl_product( 25, array( 'taxable' => '' ) );
		$dl_add_photo( $download, $gallery['photos'][0], 3 );
		$cart  = $t::calculate();
		$lines = $dl_lines();
		$t::same( 1, $lines ? $lines[0]->get_qty() : 0, 'one file' );
		$t::same( 1, $cart->get_item_count(), 'cart count' );
		$t::money( 25, $cart->get_total(), 'total' );
	}
);

$dl_test(
	'Download: adding the same photo twice charges for one',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo, $dl_lines ) {
		$gallery  = $dl_gallery( 1 );
		$download = $dl_product( 25, array( 'taxable' => '' ) );
		$dl_add_photo( $download, $gallery['photos'][0] );
		$dl_add_photo( $download, $gallery['photos'][0] );
		$cart  = $t::new_request();
		$lines = $dl_lines();
		$t::same( 1, count( $lines ), 'one line' );
		$t::same( 1, $lines ? $lines[0]->get_qty() : 0, 'one file' );
		$t::money( 25, $cart->get_total(), 'total' );
	}
);

// What the cart page's Update button does with a typed quantity.
$dl_test(
	'Download: changing the quantity on the cart page keeps it at one',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo ) {
		$gallery  = $dl_gallery( 1 );
		$download = $dl_product( 25, array( 'taxable' => '' ) );
		$dl_add_photo( $download, $gallery['photos'][0] );
		foreach ( SPC()->cart->get_cart() as $key => $line ) {
			SPC()->cart->update_item_quantity( $key, 5 );
		}
		$cart = $t::new_request();
		$t::same( 1, $cart->get_item_count(), 'still one file' );
		$t::money( 25, $cart->get_total(), 'total' );
	}
);

// Download products saved before version 3.2.6 can still have a Minimum Quantity, a field the
// product screen now hides for downloads because the quantity is always 1.
$dl_test(
	'Download: an old minimum quantity on a download still charges for one',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo ) {
		$gallery  = $dl_gallery( 1 );
		$download = $dl_product(
			25,
			array(
				'taxable' => '',
				'min_qty' => '3',
			)
		);
		$dl_add_photo( $download, $gallery['photos'][0] );
		$cart = $t::new_request();
		$t::same( 1, $cart->get_item_count(), 'one file' );
		$t::money( 25, $cart->get_total(), 'total' );
	}
);

// "Add to cart" for several photos at once (from Favorites): one file of each photo, even when
// a quantity of 2 was picked.
$dl_test(
	'Download: adding several photos at once buys one file of each',
	function () use ( $t, $dl_gallery, $dl_product, $dl_ajax, $dl_lines ) {
		$gallery  = $dl_gallery( 4 );
		$download = $dl_product( 12.50, array( 'taxable' => '' ) );
		$reply    = $dl_ajax(
			'sunshine_modal_add_favorites_to_cart',
			array(
				'product_id' => $download,
				'qty'        => 2,
				'imageIds'   => implode( ',', array_slice( $gallery['photos'], 0, 3 ) ),
			)
		);
		$t::same( 3, isset( $reply['data']['added_count'] ) ? $reply['data']['added_count'] : 0, 'three photos added' );
		$cart  = $t::new_request();
		$lines = $dl_lines();
		$t::same( 3, count( $lines ), 'a line for each photo' );
		$qtys = array();
		foreach ( $lines as $line ) {
			$qtys[] = $line->get_qty();
		}
		$t::same( array( 1, 1, 1 ), $qtys, 'one file of each' );
		// 3 x 12.50.
		$t::money( 37.50, $cart->get_total(), 'total' );
	}
);

// A gallery set to the Wholesale (or any other) price level sells its files at that price.
$dl_test(
	'Download: priced at the gallery price level',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo, $dl_other_level ) {
		$level    = $dl_other_level();
		$gallery  = $dl_gallery( 1, $level );
		$download = $dl_product(
			array(
				sunshine_get_default_price_level_id() => 30,
				$level                                => 18,
			),
			array( 'taxable' => '' )
		);
		$dl_add_photo( $download, $gallery['photos'][0] );
		$cart = $t::new_request();
		$t::money( 18, $cart->get_total(), 'the gallery level price' );
	}
);

/*
 * Entire gallery downloads.
 */

$dl_test(
	'Gallery download: the whole gallery for one price',
	function () use ( $t, $dl_gallery, $dl_gallery_product, $dl_add_gallery, $dl_lines ) {
		$t::tax( 10 );
		$gallery = $dl_gallery( 5 );
		$product = $dl_gallery_product( 150 );
		$reply   = $dl_add_gallery( $product, $gallery['id'] );
		$t::same( true, ! empty( $reply['success'] ), 'added to the cart' );
		$cart  = $t::new_request();
		$lines = $dl_lines();
		$t::same( 1, count( $lines ), 'one line for the gallery' );
		if ( $lines ) {
			$t::same( 1, $lines[0]->get_qty(), 'bought once' );
			$t::same( (int) $gallery['id'], (int) $lines[0]->get_gallery_id(), 'for this gallery' );
			$t::same( true, empty( $lines[0]->get_image_id() ), 'not tied to one photo' );
		}
		$t::same( false, $cart->needs_shipping(), 'nothing to ship' );
		// 150 + 10% tax, no matter how many photos the gallery has.
		$t::money( 15, $cart->get_tax(), 'tax' );
		$t::money( 165, $cart->get_total(), 'total' );
	}
);

// Changelog 3.1.2: "Don't allow gallery download to be added more than 1 time for a gallery."
// The window hides its button once the gallery is in the cart, but a second click that is
// already on its way (a double click, or the gallery open in two tabs) must not charge again.
$dl_test(
	'Gallery download: buying the same gallery twice charges once',
	function () use ( $t, $dl_gallery, $dl_gallery_product, $dl_add_gallery, $dl_ajax, $dl_lines ) {
		$gallery = $dl_gallery( 3 );
		$product = $dl_gallery_product( 150, array( 'taxable' => '' ) );
		$dl_add_gallery( $product, $gallery['id'] );
		$t::calculate();
		$window = $dl_ajax(
			'sunshine_modal_display_gallery_add_to_cart',
			array(
				'galleryId' => $gallery['id'],
				'productId' => $product,
			),
			''
		);
		$html = isset( $window['data']['html'] ) ? $window['data']['html'] : '';
		$t::same( true, false !== strpos( $html, 'already in your cart' ), 'the window says it is already in the cart' );

		$dl_add_gallery( $product, $gallery['id'] );
		$cart = $t::new_request();
		$t::same( 1, count( $dl_lines() ), 'one line for the gallery' );
		$t::money( 150, $cart->get_total(), 'charged once' );
	}
);

$dl_test(
	'Gallery download: two galleries are two purchases',
	function () use ( $t, $dl_gallery, $dl_gallery_product, $dl_add_gallery, $dl_lines ) {
		$first   = $dl_gallery( 2 );
		$second  = $dl_gallery( 2 );
		$product = $dl_gallery_product( 150, array( 'taxable' => '' ) );
		$dl_add_gallery( $product, $first['id'] );
		$dl_add_gallery( $product, $second['id'] );
		$cart = $t::new_request();
		$t::same( 2, count( $dl_lines() ), 'a line for each gallery' );
		$t::money( 300, $cart->get_total(), '2 x 150' );
	}
);

$dl_test(
	'Gallery download: priced at the gallery price level',
	function () use ( $t, $dl_gallery, $dl_gallery_product, $dl_add_gallery, $dl_other_level ) {
		$level   = $dl_other_level();
		$gallery = $dl_gallery( 2, $level );
		$product = $dl_gallery_product(
			array(
				sunshine_get_default_price_level_id() => 150,
				$level                                => 120,
			),
			array( 'taxable' => '' )
		);
		$dl_add_gallery( $product, $gallery['id'] );
		$cart = $t::new_request();
		$t::money( 120, $cart->get_total(), 'the gallery level price' );
	}
);

/*
 * Free downloads.
 */

// Docs (enable-free-image-downloads): a download product used only for free downloads has no
// price, "and it will not be made available for sale".
$dl_test(
	'Free download: a free-only file with no price cannot be bought',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo ) {
		$gallery = $dl_gallery( 1, '', array( 'free_downloads' => array( 'image' ) ) );
		$free    = $dl_product( '', array( 'free' => '1' ) );
		$reply   = $dl_add_photo( $free, $gallery['photos'][0] );
		$t::same( false, ! empty( $reply['success'] ), 'not added' );
		$cart = $t::new_request();
		$t::same( true, $cart->is_empty(), 'the cart is empty' );
		$t::money( 0, $cart->get_total(), 'total' );
	}
);

// A $0 file (say a free web size) can still go in the cart next to things that cost money.
$dl_test(
	'Free download: a $0 file costs nothing next to a print',
	function () use ( $t, $dl_gallery, $dl_product, $dl_print, $dl_add_photo ) {
		$t::tax( 10 );
		$gallery = $dl_gallery( 1 );
		$dl_add_photo( $dl_product( '0' ), $gallery['photos'][0] );
		$dl_add_photo( $dl_print( 10 ), $gallery['photos'][0] );
		$cart = $t::new_request();
		$t::same( 2, $cart->get_item_count(), 'both are in the cart' );
		$t::money( 10, $cart->get_subtotal(), 'subtotal' );
		$t::money( 1, $cart->get_tax(), 'tax on the print only' );
		$t::money( 11, $cart->get_total(), 'total' );
	}
);

/*
 * Download credits. Docs (creating-sessions-schedule): "Each credit lets the customer download
 * one image from a gallery without purchasing it." Credits are spent from the photo's
 * "Download with credit" button, not at checkout, so they never change what the cart charges,
 * and they are not store credit (dollars).
 */
$dl_test(
	'Download credits: not taken off the price in the cart',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo, $dl_customer_with_credits, $dl_download_credits ) {
		$customer_id = $dl_customer_with_credits( 5 );
		$t::tax( 10 );
		$gallery = $dl_gallery( 2 );
		$dl_add_photo( $dl_product( 15 ), $gallery['photos'][0] );
		// The customer ticks "use my credits" at checkout. They have no store credit.
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$cart = $t::new_request();
		$t::money( 0, $cart->get_credits_applied(), 'no store credit used' );
		$t::money( 0, $cart->get_discount(), 'no discount' );
		// 15 + 1.50 tax.
		$t::money( 16.50, $cart->get_total(), 'full price' );
		$t::same( 5, $dl_download_credits( $customer_id ), 'download credits untouched' );
	}
);

/*
 * Tax.
 */

// Many US states do not tax digital files, so the download is set not taxable while the print
// and shipping are.
$dl_test(
	'Tax: an untaxed download next to a taxed print and taxed shipping',
	function () use ( $t, $dl_gallery, $dl_product, $dl_print, $dl_add_photo ) {
		$t::tax( 8 );
		$gallery = $dl_gallery( 2 );
		$dl_add_photo( $dl_print( 20 ), $gallery['photos'][0] );
		$dl_add_photo( $dl_product( 30, array( 'taxable' => '' ) ), $gallery['photos'][1] );
		$t::flat_shipping( 5, true );
		$cart = $t::new_request();
		$t::money( 50, $cart->get_subtotal(), 'subtotal' );
		$t::money( 5, $cart->get_shipping(), 'shipping for the print' );
		// (20 print + 5 shipping) x 8%.
		$t::money( 2, $cart->get_tax(), 'tax' );
		$t::money( 57, $cart->get_total(), 'total' );
	}
);

$dl_test(
	'Tax: an untaxed download, a taxed print and 10% off the order',
	function () use ( $t, $dl_gallery, $dl_product, $dl_print, $dl_add_photo ) {
		$t::tax( 8 );
		$gallery = $dl_gallery( 2 );
		$dl_add_photo( $dl_print( 20 ), $gallery['photos'][0] );
		$dl_add_photo( $dl_product( 30, array( 'taxable' => '' ) ), $gallery['photos'][1] );
		$t::flat_shipping( 5, true );
		$t::discount( 'percent-total', 10 );
		$cart = $t::new_request();
		// 10% of 50. Of that, 2.00 comes off the taxed print.
		$t::money( 5, $cart->get_discount(), 'discount' );
		// (18 print + 5 shipping) x 8% = 1.84.
		$t::money( 1.84, $cart->get_tax(), 'tax' );
		// 50 - 5 + 5 + 1.84.
		$t::money( 51.84, $cart->get_total(), 'total' );
	}
);

// When prices include tax (VAT), the customer pays the prices shown.
$dl_test(
	'Tax: prices include tax, a taxed download and an untaxed print',
	function () use ( $t, $dl_gallery, $dl_product, $dl_print, $dl_add_photo ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$gallery = $dl_gallery( 2 );
		$dl_add_photo( $dl_product( 12 ), $gallery['photos'][0] );
		$dl_add_photo( $dl_print( 10, array( 'taxable' => '' ) ), $gallery['photos'][1] );
		$cart = $t::new_request();
		// 12 includes 2.00 VAT (12 / 1.2 = 10). The print has none.
		$t::money( 2, $cart->get_tax(), 'tax inside the download price' );
		$t::money( 22, $cart->get_total(), 'the prices shown' );
	}
);

// Every photo is its own line, so a big download order has many small lines. The store rule
// (core-rounding-fees-credits) is that each line's tax is rounded on its own and then added up.
$dl_test(
	'Tax: seven $2.99 downloads, each line rounded to the cent',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo, $dl_lines ) {
		$t::tax( 7.25 );
		$gallery  = $dl_gallery( 7 );
		$download = $dl_product( 2.99 );
		foreach ( $gallery['photos'] as $photo_id ) {
			$dl_add_photo( $download, $photo_id );
		}
		$cart = $t::new_request();
		$t::same( 7, count( $dl_lines() ), 'seven lines' );
		// 2.99 x 7.25% = 0.2168, so 0.22 a line, 1.54 for 7.
		$t::money( 1.54, $cart->get_tax(), 'tax' );
		$line_tax = 0;
		foreach ( $dl_lines() as $line ) {
			$line_tax += $line->get_tax_total();
		}
		$t::money( $cart->get_tax(), $line_tax, 'the lines add up to the cart tax' );
		// 20.93 + 1.54.
		$t::money( 22.47, $cart->get_total(), 'total' );
	}
);

/*
 * Discount codes.
 */

$dl_test(
	'Discount: half off digital files only',
	function () use ( $t, $dl_gallery, $dl_product, $dl_print, $dl_add_photo ) {
		$t::tax( 10 );
		$gallery  = $dl_gallery( 3 );
		$download = $dl_product( 10 );
		$dl_add_photo( $download, $gallery['photos'][0] );
		$dl_add_photo( $download, $gallery['photos'][1] );
		$dl_add_photo( $dl_print( 20 ), $gallery['photos'][2] );
		$t::discount( 'percent-product', 50, array( 'allowed_products' => array( (int) $download ) ) );
		$cart = $t::new_request();
		// Half of the two 10.00 files.
		$t::money( 10, $cart->get_discount(), 'discount' );
		// (10 files + 20 print) x 10%.
		$t::money( 3, $cart->get_tax(), 'tax' );
		// 40 - 10 + 3.
		$t::money( 33, $cart->get_total(), 'total' );
	}
);

// A print sale that leaves digital files out.
$dl_test(
	'Discount: a print sale leaves downloads at full price',
	function () use ( $t, $dl_gallery, $dl_product, $dl_print, $dl_add_photo ) {
		$t::tax( 10 );
		$gallery  = $dl_gallery( 2 );
		$download = $dl_product( 25 );
		$dl_add_photo( $dl_print( 50 ), $gallery['photos'][0] );
		$dl_add_photo( $download, $gallery['photos'][1] );
		$t::discount( 'percent-total', 20, array( 'disallowed_products' => array( (int) $download ) ) );
		$cart = $t::new_request();
		// 20% of the 50 print.
		$t::money( 10, $cart->get_discount(), 'discount' );
		// (40 print + 25 file) x 10%.
		$t::money( 6.50, $cart->get_tax(), 'tax' );
		// 75 - 10 + 6.50.
		$t::money( 71.50, $cart->get_total(), 'total' );
	}
);

// A code for one client's gallery also covers that gallery's full gallery download.
$dl_test(
	'Discount: a code for one gallery covers its full gallery download',
	function () use ( $t, $dl_gallery, $dl_gallery_product, $dl_add_gallery ) {
		$mine    = $dl_gallery( 2 );
		$other   = $dl_gallery( 2 );
		$product = $dl_gallery_product( 100, array( 'taxable' => '' ) );
		$dl_add_gallery( $product, $mine['id'] );
		$dl_add_gallery( $product, $other['id'] );
		$t::discount( 'percent-total', 25, array( 'allowed_galleries' => array( (int) $mine['id'] ) ) );
		$cart = $t::new_request();
		// 25% of the one 100 gallery.
		$t::money( 25, $cart->get_discount(), 'discount' );
		$t::money( 175, $cart->get_total(), 'total' );
	}
);

$dl_test(
	'Discount: taken off after tax on a download',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$gallery = $dl_gallery( 1 );
		$dl_add_photo( $dl_product( 50 ), $gallery['photos'][0] );
		$t::discount( 'percent-total', 20 );
		$cart = $t::new_request();
		$t::money( 5, $cart->get_tax(), 'tax on the full 50' );
		// 20% of 55.
		$t::money( 11, $cart->get_discount(), 'discount' );
		$t::money( 44, $cart->get_total(), 'total' );
	}
);

/*
 * Quantity discounts. A file is always one per photo, so a "buy more, pay less" price on a
 * download product counts the files in the whole cart (the default, not "per line item").
 * Saved the way the Quantity Discount box on the product screen saves it.
 */
$dl_test(
	'Quantity discount: 5 or more files, $2 off each',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$gallery  = $dl_gallery( 5 );
		$download = $dl_product(
			8,
			array(
				'taxable'      => '',
				'qty_discount' => array(
					'items' => array(
						array(
							'qty'      => '5',
							'discount' => '2',
							'type'     => 'amount',
						),
					),
				),
			)
		);
		foreach ( $gallery['photos'] as $photo_id ) {
			$dl_add_photo( $download, $photo_id );
		}
		$cart = $t::new_request();
		// 5 files at 8 - 2.
		$t::money( 30, $cart->get_total(), 'five files' );

		// The customer takes one out: 4 files, no discount.
		$keys = array_keys( SPC()->cart->get_cart() );
		SPC()->cart->remove_item( end( $keys ) );
		$cart = $t::new_request();
		$t::money( 32, $cart->get_total(), 'four files at full price' );
	}
);

$dl_test(
	'Quantity discount: 10 or more files 20% off, with tax',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::tax( 10 );
		$gallery  = $dl_gallery( 10 );
		$download = $dl_product(
			5,
			array(
				'qty_discount' => array(
					'items' => array(
						array(
							'qty'      => '10',
							'discount' => '20',
							'type'     => 'percent',
						),
					),
				),
			)
		);
		foreach ( $gallery['photos'] as $photo_id ) {
			$dl_add_photo( $download, $photo_id );
		}
		$cart = $t::new_request();
		// 10 files at 4.00 (5 less 20%), tax 0.40 each.
		$t::money( 40, $cart->get_subtotal(), 'subtotal' );
		$t::money( 4, $cart->get_tax(), 'tax' );
		$t::money( 44, $cart->get_total(), 'total' );
	}
);

/*
 * Gift cards.
 */

$dl_test(
	'Gift card: pays for a download-only order',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo ) {
		$t::tax( 10 );
		$gallery  = $dl_gallery( 2 );
		$download = $dl_product( 25 );
		$dl_add_photo( $download, $gallery['photos'][0] );
		$dl_add_photo( $download, $gallery['photos'][1] );
		$t::gift_card( 100 );
		$cart = $t::new_request();
		// 50 + 5 tax.
		$t::money( 55, array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) ), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

$dl_test(
	'Gift card: prices include tax, a discount code, and a card that covers part',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$gallery  = $dl_gallery( 2 );
		$download = $dl_product( 12 );
		$dl_add_photo( $download, $gallery['photos'][0] );
		$dl_add_photo( $download, $gallery['photos'][1] );
		$t::discount( 'percent-total', 25 );
		$t::gift_card( 10 );
		$cart = $t::new_request();
		// 25% of 24.
		$t::money( 6, $cart->get_discount(), 'discount' );
		// 18 left, which includes 3.00 VAT (18 / 1.2 = 15).
		$t::money( 3, $cart->get_tax(), 'tax' );
		$t::money( 10, array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) ), 'the whole card is used' );
		$t::money( 8, $cart->get_total(), 'left to pay' );
	}
);
