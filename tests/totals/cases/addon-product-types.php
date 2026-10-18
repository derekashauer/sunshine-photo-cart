<?php
/**
 * Product types from add-ons: Sell Anything (general products with no photo),
 * Multi-Image Products (one product filled with several photos, like an album)
 * and Video Sales (a video bought as a download, always one of each video).
 *
 * Products, galleries and photos are saved the way the admin screens save them.
 * Every outgoing request is blocked while these tests run.
 */

$t = 'SPC_Totals_Test';

// Nothing in these tests should talk to an outside service. If something tries, stop it
// and say so in the output.
$product_types_offline = function ( $pre, $args, $url ) {
	$parts = wp_parse_url( $url );
	WP_CLI::warning( 'Blocked an outgoing request during a product types test: ' . ( isset( $parts['host'] ) ? $parts['host'] : '' ) . ( isset( $parts['path'] ) ? $parts['path'] : '' ) );
	return new WP_Error( 'spc_totals_offline', 'Outgoing requests are blocked while the totals tests run.' );
};

// Galleries made by the current test. Their photo list is emptied when the test ends, so
// deleting the gallery at the end of the run does not queue a background job for its photos.
$product_types_galleries = array();

$product_types_test = function ( $name, $callback ) use ( $t, $product_types_offline, &$product_types_galleries ) {
	$t::test(
		$name,
		function () use ( $callback, $product_types_offline, &$product_types_galleries ) {
			$product_types_galleries = array();
			add_filter( 'pre_http_request', $product_types_offline, 1, 3 );
			try {
				$callback();
			} finally {
				remove_filter( 'pre_http_request', $product_types_offline, 1 );
				foreach ( $product_types_galleries as $gallery_id ) {
					update_post_meta( $gallery_id, 'images', array() );
				}
			}
		}
	);
};

/**
 * A product saved the way the product edit screen saves it: every field on the screen is
 * stored, and a field left empty is stored as an empty string. Prices and extra shipping
 * are stored per price level. $fields changes any of them.
 */
$product_types_product = function ( $type, $price, $fields = array() ) use ( $t ) {
	$level = sunshine_get_default_price_level_id();
	$id    = wp_insert_post(
		array(
			'post_type'   => 'sunshine-product',
			'post_status' => 'publish',
			'post_title'  => 'Product types test ' . $type,
		)
	);
	$t::track( 'post', $id );
	$meta = array(
		'type'             => $type,
		'price'            => array( $level => (string) $price ),
		'taxable'          => '1',
		'disable_shipping' => '',
		'shipping'         => array( $level => '' ),
		'min_qty'          => '',
		'max_qty'          => '',
	);
	if ( 'general' === $type ) {
		// Sell Anything: blank stock means unlimited.
		$meta['stock'] = '';
	}
	if ( 'multi-image' === $type ) {
		$meta['image_count']     = '3';
		$meta['allowed_sources'] = array( 'current' );
	}
	if ( 'download' === $type ) {
		// Digital Downloads tab, plus the Video Product checkbox Video Sales adds to it.
		$meta['gallery_download'] = '';
		$meta['subgalleries']     = '';
		$meta['dimensions']       = array(
			'w' => '',
			'h' => '',
		);
		$meta['watermark']        = '';
		$meta['free']             = '';
		$meta['order']            = '';
		$meta['video_product']    = '';
	}
	foreach ( array_merge( $meta, $fields ) as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
};

$product_types_general = function ( $price, $fields = array() ) use ( $t, $product_types_product ) {
	$t::require_plugin( 'sunshine_general_product_max_qty', 'Sell Anything add-on' );
	return $product_types_product( 'general', $price, $fields );
};

$product_types_album = function ( $price, $fields = array() ) use ( $t, $product_types_product ) {
	$t::require_plugin( 'sunshine_register_multi_image_type', 'Multi-Image Products add-on' );
	return $product_types_product( 'multi-image', $price, $fields );
};

$product_types_video_product = function ( $price, $fields = array() ) use ( $t, $product_types_product ) {
	$t::require_plugin( 'sunshine_product_is_download', 'Digital Downloads add-on' );
	$t::require_plugin( 'sunshine_video_sales_cart_item_max_qty', 'Video Sales add-on' );
	return $product_types_product( 'download', $price, array_merge( array( 'video_product' => '1' ), $fields ) );
};

$product_types_print = function ( $price, $fields = array() ) use ( $product_types_product ) {
	return $product_types_product( 'print', $price, $fields );
};

/**
 * A public gallery with some photos in it, like one made in the admin. The photos have no
 * file, which the cart does not need.
 */
$product_types_gallery = function ( $photo_count = 4 ) use ( $t, &$product_types_galleries ) {
	$gallery_id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-gallery',
			'post_status' => 'publish',
			'post_title'  => 'Product types test gallery',
		)
	);
	$t::track( 'post', $gallery_id );
	$product_types_galleries[] = $gallery_id;
	update_post_meta( $gallery_id, 'status', 'default' );
	update_post_meta( $gallery_id, 'access_type', '' );
	update_post_meta( $gallery_id, 'price_level', (string) sunshine_get_default_price_level_id() );
	$photo_ids = array();
	for ( $i = 1; $i <= $photo_count; $i++ ) {
		$photo_id = wp_insert_attachment(
			array(
				'post_title'     => 'product-types-photo-' . $i,
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			),
			false,
			$gallery_id
		);
		$t::track( 'post', $photo_id );
		add_post_meta( $photo_id, 'sunshine_file_name', 'product-types-photo-' . $i . '.jpg' );
		add_post_meta( $photo_id, 'sunshine_watermark', 0 );
		$photo_ids[] = $photo_id;
	}
	update_post_meta( $gallery_id, 'images', $photo_ids );
	return array(
		'id'     => $gallery_id,
		'photos' => $photo_ids,
	);
};

// Link a video to a gallery photo, the way the Video Sales upload does.
$product_types_attach_video = function ( $photo_id ) use ( $t ) {
	$video_id = wp_insert_attachment(
		array(
			'post_title'     => 'product-types-clip-' . $photo_id,
			'post_mime_type' => 'video/mp4',
			'post_status'    => 'inherit',
		),
		false,
		0
	);
	$t::track( 'post', $video_id );
	update_post_meta( $photo_id, 'sunshine_video_attachment_id', $video_id );
	update_post_meta( $photo_id, 'sunshine_video_file_path', '/tmp/product-types-clip-' . $photo_id . '.mp4' );
	update_post_meta( $photo_id, 'sunshine_video_file_url', 'https://test.invalid/product-types-clip-' . $photo_id . '.mp4' );
	update_post_meta( $video_id, 'sunshine_video_image_id', $photo_id );
	update_post_meta( $video_id, '_sunshine_hide_from_media_library', true );
	return $video_id;
};

// A gallery whose photos all have a video.
$product_types_video_gallery = function ( $count = 3 ) use ( $product_types_gallery, $product_types_attach_video ) {
	$gallery = $product_types_gallery( $count );
	foreach ( $gallery['photos'] as $photo_id ) {
		$product_types_attach_video( $photo_id );
	}
	return $gallery;
};

/**
 * Add to the cart the way the add to cart window does: a photo (or none, from the store),
 * its gallery, the gallery's price level, and for a multi-image product the chosen photos.
 */
$product_types_add = function ( $product_id, $qty = 1, $photo_id = 0, $gallery_id = '', $options = array() ) {
	$item = SPC()->cart->add_item( $product_id, $photo_id, $gallery_id, sunshine_get_default_price_level_id(), $options, $qty );
	if ( empty( $item ) ) {
		throw new Exception( 'Could not add product ' . $product_id . ' to the cart' );
	}
	return $item;
};

$product_types_lines = function () {
	return array_values( (array) SPC()->cart->get_cart_items() );
};

// Offer one flat rate shipping method, charged per item, and choose it.
$product_types_flat_rate_per_item = function ( $price, $taxable = false ) use ( $t ) {
	$t::flat_shipping( $price, $taxable );
	$t::option( 'flat_rate_price_type_spctestflat', 'qty' );
};

$product_types_gift_card_used = function () {
	return array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) );
};

// What the cart shows right before the customer places the order.
$product_types_snapshot = function () {
	$cart  = SPC()->cart;
	$lines = array();
	foreach ( (array) $cart->get_cart_items() as $line ) {
		$lines[] = array(
			'qty'      => (int) $line->get_qty(),
			'total'    => (float) $line->get_total(),
			'tax'      => (float) $line->get_tax_total(),
			'discount' => (float) $line->get_discount_total(),
		);
	}
	return array(
		'subtotal' => (float) $cart->get_subtotal(),
		'discount' => (float) $cart->get_discount(),
		'shipping' => (float) $cart->get_shipping(),
		'tax'      => (float) $cart->get_tax(),
		'total'    => (float) $cart->get_total(),
		'lines'    => $lines,
	);
};

$product_types_order_matches = function ( $order, $seen ) use ( $t ) {
	$t::money( $seen['subtotal'], $order->get_subtotal(), 'order subtotal' );
	$t::money( $seen['discount'], $order->get_discount(), 'order discount' );
	$t::money( $seen['shipping'], $order->get_shipping(), 'order shipping' );
	$t::money( $seen['tax'], $order->get_tax(), 'order tax' );
	$t::money( $seen['total'], $order->get_total(), 'order total' );
	$items = array_values( (array) $order->get_items() );
	$t::same( count( $seen['lines'] ), count( $items ), 'number of order lines' );
	foreach ( $seen['lines'] as $i => $line ) {
		if ( empty( $items[ $i ] ) ) {
			continue;
		}
		$t::same( $line['qty'], (int) $items[ $i ]->get_qty(), 'line ' . ( $i + 1 ) . ' quantity' );
		$t::money( $line['total'], $items[ $i ]->get_total(), 'line ' . ( $i + 1 ) . ' total' );
		$t::money( $line['tax'], $items[ $i ]->get_tax_total(), 'line ' . ( $i + 1 ) . ' tax' );
		$t::money( $line['discount'], $items[ $i ]->get_discount_total(), 'line ' . ( $i + 1 ) . ' discount' );
	}
};

/*
 * Sell Anything: general products with no photo.
 */

$product_types_test(
	'General product: price times quantity, with tax',
	function () use ( $t, $product_types_general, $product_types_lines ) {
		$t::tax( 8 );
		$t::add( $product_types_general( 24.99 ), 3 );
		$t::add( $product_types_general( 10, array( 'taxable' => '' ) ) );
		$cart  = $t::calculate();
		$lines = $product_types_lines();
		$t::same( 3, (int) $lines[0]->get_qty(), 'quantity' );
		// 24.99 x 3 = 74.97, 8% of that is 5.9976. The 10 item is not taxed.
		$t::money( 84.97, $cart->get_subtotal(), 'subtotal' );
		$t::money( 6.00, $cart->get_tax(), 'tax' );
		$t::money( 90.97, $cart->get_total(), 'total' );
	}
);

$product_types_test(
	'General product: a large quantity is worked out to the cent',
	function () use ( $t, $product_types_general ) {
		$t::tax( 7.25 );
		$t::add( $product_types_general( 1.99 ), 250 );
		$cart = $t::calculate();
		// 1.99 x 250 = 497.50, 7.25% of that is 36.06875.
		$t::money( 497.50, $cart->get_subtotal(), 'subtotal' );
		$t::money( 36.07, $cart->get_tax(), 'tax' );
		$t::money( 533.57, $cart->get_total(), 'total' );
	}
);

// A free item (a printed price list, say) costs nothing but the order still pays shipping.
$product_types_test(
	'General product: a free item adds nothing to the price',
	function () use ( $t, $product_types_general, $product_types_lines ) {
		$t::tax( 10 );
		$t::add( $product_types_general( 0 ), 2 );
		$t::add( $product_types_general( 15 ) );
		$t::flat_shipping( 5, false );
		$cart  = $t::calculate();
		$lines = $product_types_lines();
		$t::same( 2, count( $lines ), 'both items are in the cart' );
		$t::money( 0, $lines[0]->get_total(), 'free line' );
		$t::money( 15, $cart->get_subtotal(), 'subtotal' );
		$t::money( 1.50, $cart->get_tax(), 'tax on 15' );
		$t::money( 5, $cart->get_shipping(), 'shipping' );
		$t::money( 21.50, $cart->get_total(), 'total' );
	}
);

$product_types_test(
	'General product: stock limits how many can be added',
	function () use ( $t, $product_types_general ) {
		$product_id = $product_types_general( 10, array( 'taxable' => '', 'stock' => '3' ) );
		$t::add( $product_id, 5 );
		$cart = $t::calculate();
		$t::same( 3, $cart->get_item_count(), 'only the 3 in stock' );
		$t::money( 30, $cart->get_total(), 'total for 3' );
	}
);

// General products have no photo, so adding one again makes a second cart line. The stock
// is what the photographer has on the shelf, so it limits the whole cart, not each line.
$product_types_test(
	'General product: adding it again cannot go past the stock',
	function () use ( $t, $product_types_general ) {
		$product_id = $product_types_general( 10, array( 'taxable' => '', 'stock' => '3' ) );
		$t::add( $product_id, 2 );
		$t::add( $product_id, 2 );
		$cart = $t::new_request();
		$t::same( 3, $cart->get_item_count(), 'only the 3 in stock, across the cart' );
		$t::money( 30, $cart->get_total(), 'total for 3' );
	}
);

$product_types_test(
	'General product: changing the quantity in the cart cannot go past the stock',
	function () use ( $t, $product_types_general ) {
		$product_id = $product_types_general( 10, array( 'taxable' => '', 'stock' => '3' ) );
		$item       = $t::add( $product_id, 1 );
		SPC()->cart->update_item_quantity_by_hash( $item['hash'], 10 );
		$cart = $t::new_request();
		$t::same( 3, $cart->get_item_count(), 'only the 3 in stock' );
		$t::money( 30, $cart->get_total(), 'total for 3' );
	}
);

$product_types_test(
	'General product: minimum quantity',
	function () use ( $t, $product_types_general ) {
		$product_id = $product_types_general( 10, array( 'taxable' => '', 'min_qty' => '2' ) );
		$item       = $t::add( $product_id, 1 );
		$cart       = $t::new_request();
		$t::same( 2, $cart->get_item_count(), 'raised to 2' );
		$t::money( 20, $cart->get_total(), 'total for 2' );
		SPC()->cart->update_item_quantity_by_hash( $item['hash'], 1 );
		$cart = $t::new_request();
		$t::same( 2, $cart->get_item_count(), 'still 2 after changing the quantity' );
		$t::money( 20, $cart->get_total(), 'total after changing the quantity' );
	}
);

$product_types_test(
	'General product: stock and max quantity, the lower one wins',
	function () use ( $t, $product_types_general ) {
		$max_lower   = $product_types_general( 10, array( 'taxable' => '', 'stock' => '5', 'max_qty' => '2' ) );
		$stock_lower = $product_types_general( 10, array( 'taxable' => '', 'stock' => '1', 'max_qty' => '4' ) );
		$t::add( $max_lower, 4 );
		$t::add( $stock_lower, 3 );
		$cart = $t::new_request();
		// 2 of the first (max 2) and 1 of the second (1 in stock).
		$t::same( 3, $cart->get_item_count(), 'items in the cart' );
		$t::money( 30, $cart->get_total(), 'total for 3' );
	}
);

// Sell Anything lowers the stock when the order goes through. That runs on
// sunshine_checkout_create_order, which also sends emails and runs other add-ons, so only
// Sell Anything's own step is run here.
$product_types_test(
	'General product: a placed order lowers the stock',
	function () use ( $t, $product_types_general ) {
		$product_id = $product_types_general( 10, array( 'taxable' => '', 'stock' => '5' ) );
		$t::add( $product_id, 2 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 20, $order->get_total(), 'order total' );
		sunshine_general_product_reduce_stock( $order );
		$t::same( 3, (int) get_post_meta( $product_id, 'stock', true ), '5 in stock less the 2 sold' );
	}
);

// The stock field says "Leave blank to allow unlimited", so 0 means none are left.
$product_types_test(
	'General product: selling the last one means it cannot be bought again',
	function () use ( $t, $product_types_general ) {
		$product_id = $product_types_general( 10, array( 'taxable' => '', 'stock' => '2' ) );
		$t::add( $product_id, 2 );
		$t::calculate();
		$order = $t::place_order();
		sunshine_general_product_reduce_stock( $order );
		$t::same( 0, (int) get_post_meta( $product_id, 'stock', true ), 'none left' );

		// The next customer.
		$t::reset();
		$added = SPC()->cart->add_item( $product_id, 0, '', sunshine_get_default_price_level_id(), array(), 1 );
		$cart  = $t::new_request();
		$t::same( true, empty( $added ), 'a sold out product is not added' );
		$t::same( 0, $cart->get_item_count(), 'nothing in the cart' );
		$t::money( 0, $cart->get_total(), 'nothing to pay' );
	}
);

$product_types_test(
	'General product: stock set to 0 in the admin means sold out',
	function () use ( $t, $product_types_general ) {
		$product_id = $product_types_general( 10, array( 'taxable' => '', 'stock' => '0' ) );
		$added      = SPC()->cart->add_item( $product_id, 0, '', sunshine_get_default_price_level_id(), array(), 1 );
		$cart       = $t::new_request();
		$t::same( true, empty( $added ), 'a sold out product is not added' );
		$t::same( 0, $cart->get_item_count(), 'nothing in the cart' );
		$t::money( 0, $cart->get_total(), 'nothing to pay' );
	}
);

$product_types_test(
	'General product: flat rate per item counts general products that ship',
	function () use ( $t, $product_types_general, $product_types_flat_rate_per_item ) {
		$t::add( $product_types_general( 10, array( 'taxable' => '' ) ), 3 );
		$t::add( $t::product( 5, array( 'taxable' => false ) ), 2 );
		$product_types_flat_rate_per_item( 2 );
		$cart = $t::calculate();
		// 3 + 2 items at 2 each.
		$t::money( 10, $cart->get_shipping(), 'shipping' );
		$t::money( 50, $cart->get_total(), '30 + 10 + 10 shipping' );
	}
);

$product_types_test(
	'General product with shipping turned off: no shipping on an order of only that',
	function () use ( $t, $product_types_general ) {
		$t::add( $product_types_general( 40, array( 'taxable' => '', 'disable_shipping' => '1' ) ) );
		$t::flat_shipping( 10, false );
		$cart = $t::calculate();
		$t::same( false, (bool) $cart->needs_shipping(), 'order does not need shipping' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 40, $cart->get_total(), 'total' );
	}
);

// The product screen hides Extra Shipping Cost once Disable Shipping is ticked, but the
// hidden field is still saved, so a product can have both. Extra shipping is for items
// that cost more to ship, so an item that is not shipped adds none.
$product_types_test(
	'General product with shipping turned off adds no extra shipping cost',
	function () use ( $t, $product_types_general ) {
		$level = sunshine_get_default_price_level_id();
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$t::add(
			$product_types_general(
				15,
				array(
					'taxable'          => '',
					'disable_shipping' => '1',
					'shipping'         => array( $level => '5' ),
				)
			),
			2
		);
		$t::flat_shipping( 10, false );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_shipping(), 'only the flat rate' );
		$t::money( 60, $cart->get_total(), '20 + 30 + 10 shipping' );
	}
);

$product_types_test(
	'General product: percent off one product leaves the others alone',
	function () use ( $t, $product_types_general ) {
		$t::tax( 10 );
		$general_id = $product_types_general( 40 );
		$t::add( $general_id );
		$t::add( $t::product( 20 ) );
		$t::discount( 'percent-product', 25, array( 'allowed_products' => array( $general_id ) ) );
		$cart = $t::calculate();
		// 25% of 40. Tax is 10% of 30 + 20.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 5, $cart->get_tax(), 'tax on 50' );
		$t::money( 55, $cart->get_total(), 'total' );
	}
);

// "$5 off each" can't take more off an item than the item costs. The rest of the order
// is not part of this discount.
$product_types_test(
	'General product: amount off each item is never more than the item costs',
	function () use ( $t, $product_types_general ) {
		$general_id = $product_types_general( 3, array( 'taxable' => '' ) );
		$t::add( $general_id, 2 );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::discount( 'amount-product', 5, array( 'allowed_products' => array( $general_id ) ) );
		$cart = $t::calculate();
		// Each item costs 3, so 3 off each of the 2.
		$t::money( 6, $cart->get_discount(), 'discount is the price of the 2 items' );
		$t::money( 50, $cart->get_total(), 'the 50 print is not discounted' );
	}
);

$product_types_test(
	'General product: prices include tax, with shipping',
	function () use ( $t, $product_types_general ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $product_types_general( 36 ), 2 );
		$t::flat_shipping( 6, true );
		$cart = $t::calculate();
		// The customer pays the prices shown: 72 + 6. The tax inside is 72 / 6 + 6 / 6.
		$t::money( 78, $cart->get_total(), 'total' );
		$t::money( 13, $cart->get_tax(), 'tax included' );
	}
);

$product_types_test(
	'General product: store credit and a percent payment fee',
	function () use ( $t, $product_types_general ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::add( $product_types_general( 100, array( 'taxable' => '' ) ) );
		$t::flat_shipping( 10, false );
		$t::option( 'test_fee', 'percent' );
		$t::option( 'test_fee_amount', '3' );
		$t::option( 'test_fee_name', 'Card fee' );
		SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
		$cart = $t::calculate();
		$t::money( 20, $cart->get_credits_applied(), 'credits used' );
		// 100 + 10 shipping - 20 credit = 90, plus 3% of 90.
		$t::money( 92.70, $cart->get_total(), 'total' );
	}
);

/*
 * Multi-Image Products: one product, like an album, filled with several photos.
 */

$product_types_test(
	'Multi-image: one price for the product, however many photos are in it',
	function () use ( $t, $product_types_album, $product_types_gallery, $product_types_add, $product_types_lines ) {
		$t::tax( 10 );
		$gallery  = $product_types_gallery( 4 );
		$photos   = $gallery['photos'];
		$album_id = $product_types_album( 150 );
		// From the gallery's store page: a full album and one with only 1 of its 3 photos picked.
		$product_types_add( $album_id, 1, 0, $gallery['id'], array( 'images' => array( $photos[0], $photos[1], $photos[2] ) ) );
		$product_types_add( $album_id, 1, 0, $gallery['id'], array( 'images' => array( $photos[3] ) ) );
		$cart  = $t::calculate();
		$lines = $product_types_lines();
		$t::same( 2, count( $lines ), 'two albums, two lines' );
		$t::money( 150, $lines[0]->get_total(), 'full album' );
		$t::money( 150, $lines[1]->get_total(), 'album with 1 photo' );
		$t::money( 300, $cart->get_subtotal(), 'subtotal' );
		$t::money( 30, $cart->get_tax(), 'tax' );
		$t::money( 330, $cart->get_total(), 'total' );
	}
);

$product_types_test(
	'Multi-image: the same photos added again become a quantity of 2',
	function () use ( $t, $product_types_album, $product_types_gallery, $product_types_add, $product_types_lines ) {
		$gallery  = $product_types_gallery( 4 );
		$photos   = $gallery['photos'];
		$album_id = $product_types_album( 150, array( 'taxable' => '' ) );
		$same     = array( 'images' => array( $photos[0], $photos[1], $photos[2] ) );
		// Added from a photo's page, so the line has that photo.
		$product_types_add( $album_id, 1, $photos[0], $gallery['id'], $same );
		$product_types_add( $album_id, 1, $photos[0], $gallery['id'], $same );
		$cart  = $t::new_request();
		$lines = $product_types_lines();
		$t::same( 1, count( $lines ), 'one line' );
		$t::same( 2, (int) $lines[0]->get_qty(), 'quantity 2' );
		$t::money( 300, $cart->get_total(), '2 albums' );

		// Different photos are a different album.
		$product_types_add( $album_id, 1, $photos[0], $gallery['id'], array( 'images' => array( $photos[1], $photos[2], $photos[3] ) ) );
		$cart = $t::new_request();
		$t::same( 2, count( $product_types_lines() ), 'a new line' );
		$t::money( 450, $cart->get_total(), '3 albums' );
	}
);

$product_types_test(
	'Multi-image: max quantity',
	function () use ( $t, $product_types_album, $product_types_gallery, $product_types_add ) {
		$gallery  = $product_types_gallery( 3 );
		$photos   = $gallery['photos'];
		$album_id = $product_types_album( 150, array( 'taxable' => '', 'max_qty' => '1' ) );
		$item     = $product_types_add( $album_id, 3, $photos[0], $gallery['id'], array( 'images' => $photos ) );
		$cart     = $t::new_request();
		$t::same( 1, $cart->get_item_count(), 'only 1' );
		$t::money( 150, $cart->get_total(), 'total' );
		SPC()->cart->update_item_quantity_by_hash( $item['hash'], 4 );
		$cart = $t::new_request();
		$t::same( 1, $cart->get_item_count(), 'still 1 after changing the quantity' );
		$t::money( 150, $cart->get_total(), 'total after changing the quantity' );
	}
);

$product_types_test(
	'Multi-image: flat rate per item counts each album once, not each photo',
	function () use ( $t, $product_types_album, $product_types_print, $product_types_gallery, $product_types_add, $product_types_flat_rate_per_item ) {
		$gallery = $product_types_gallery( 3 );
		$photos  = $gallery['photos'];
		$product_types_add( $product_types_album( 150, array( 'taxable' => '' ) ), 2, 0, $gallery['id'], array( 'images' => $photos ) );
		$product_types_add( $product_types_print( 10, array( 'taxable' => '' ) ), 1, $photos[0], $gallery['id'] );
		$product_types_flat_rate_per_item( 5 );
		$cart = $t::calculate();
		// 2 albums + 1 print = 3 items at 5 each.
		$t::money( 15, $cart->get_shipping(), 'shipping' );
		$t::money( 325, $cart->get_total(), '300 + 10 + 15 shipping' );
	}
);

$product_types_test(
	'Multi-image: extra shipping cost is per album',
	function () use ( $t, $product_types_album, $product_types_gallery, $product_types_add ) {
		$level   = sunshine_get_default_price_level_id();
		$gallery = $product_types_gallery( 3 );
		$product_types_add(
			$product_types_album(
				150,
				array(
					'taxable'  => '',
					'shipping' => array( $level => '7.50' ),
				)
			),
			2,
			0,
			$gallery['id'],
			array( 'images' => $gallery['photos'] )
		);
		$t::flat_shipping( 10, false );
		$cart = $t::calculate();
		// 10 flat rate + 7.50 for each of the 2 albums.
		$t::money( 25, $cart->get_shipping(), 'shipping' );
		$t::money( 325, $cart->get_total(), 'total' );
	}
);

$product_types_test(
	'Multi-image: discount code and gift card',
	function () use ( $t, $product_types_album, $product_types_print, $product_types_gallery, $product_types_add, $product_types_gift_card_used ) {
		$t::tax( 8 );
		$gallery = $product_types_gallery( 3 );
		$photos  = $gallery['photos'];
		$product_types_add( $product_types_album( 120 ), 1, 0, $gallery['id'], array( 'images' => $photos ) );
		$product_types_add( $product_types_print( 30 ), 1, $photos[0], $gallery['id'] );
		$t::discount( 'percent-total', 10 );
		$t::gift_card( 50 );
		$cart = $t::calculate();
		// 10% of 150. Tax is 8% of 135. 150 - 15 + 10.80 = 145.80, less the 50 card.
		$t::money( 15, $cart->get_discount(), 'discount' );
		$t::money( 10.80, $cart->get_tax(), 'tax' );
		$t::money( 50, $product_types_gift_card_used(), 'taken from the card' );
		$t::money( 95.80, $cart->get_total(), 'left to pay' );
	}
);

$product_types_test(
	'Multi-image: discount after tax',
	function () use ( $t, $product_types_album, $product_types_gallery, $product_types_add ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$gallery = $product_types_gallery( 3 );
		$product_types_add( $product_types_album( 200 ), 1, 0, $gallery['id'], array( 'images' => $gallery['photos'] ) );
		$t::discount( 'percent-total', 20 );
		$cart = $t::calculate();
		// Tax on the full 200, then 20% off 220.
		$t::money( 20, $cart->get_tax(), 'tax' );
		$t::money( 44, $cart->get_discount(), 'discount' );
		$t::money( 176, $cart->get_total(), 'total' );
	}
);

$product_types_test(
	'Multi-image: prices include tax, with a discount on the album',
	function () use ( $t, $product_types_album, $product_types_gallery, $product_types_add ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$gallery  = $product_types_gallery( 3 );
		$album_id = $product_types_album( 180 );
		$product_types_add( $album_id, 1, 0, $gallery['id'], array( 'images' => $gallery['photos'] ) );
		$cart = $t::calculate();
		$t::money( 180, $cart->get_total(), 'total before the discount' );
		$t::money( 30, $cart->get_tax(), 'tax included in 180' );

		$t::discount( 'percent-product', 10, array( 'allowed_products' => array( $album_id ) ) );
		$cart = $t::calculate();
		// 10% of 180 is 18. The tax inside 162 is 162 / 6.
		$t::money( 18, $cart->get_discount(), 'discount' );
		$t::money( 162, $cart->get_total(), 'total' );
		$t::money( 27, $cart->get_tax(), 'tax included in 162' );
	}
);

$product_types_test(
	'Multi-image: store credit bigger than the order',
	function () use ( $t, $product_types_album, $product_types_gallery, $product_types_add ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 500 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::tax( 10 );
		$gallery = $product_types_gallery( 3 );
		$product_types_add( $product_types_album( 150 ), 1, 0, $gallery['id'], array( 'images' => $gallery['photos'] ) );
		$t::flat_shipping( 10, true );
		$cart = $t::calculate();
		// 150 + 15 tax + 10 shipping + 1 shipping tax.
		$t::money( 176, $cart->get_credits_applied(), 'credits used' );
		$t::money( 0, $cart->get_total(), 'nothing left to pay' );
	}
);

$product_types_test(
	'Order: multi-image product keeps its price and its photos',
	function () use ( $t, $product_types_album, $product_types_print, $product_types_gallery, $product_types_add, $product_types_snapshot, $product_types_order_matches ) {
		$t::tax( 7.25 );
		$gallery = $product_types_gallery( 3 );
		$photos  = $gallery['photos'];
		$product_types_add( $product_types_album( 89.99 ), 1, 0, $gallery['id'], array( 'images' => $photos ) );
		$product_types_add( $product_types_print( 12 ), 2, $photos[1], $gallery['id'] );
		$t::flat_shipping( 8.95, true );
		$t::checkout_address();
		$cart = $t::calculate();
		// 89.99 + 24 = 113.99. Tax is 7.25% of 113.99 + 8.95 = 8.91315.
		$t::money( 113.99, $cart->get_subtotal(), 'cart subtotal' );
		$t::money( 8.91, $cart->get_tax(), 'cart tax' );
		$t::money( 131.85, $cart->get_total(), 'cart total' );
		$seen  = $product_types_snapshot();
		$order = $t::place_order();
		$product_types_order_matches( $order, $seen );
		$items   = array_values( (array) $order->get_items() );
		$options = $items ? $items[0]->get_meta_value( 'options' ) : array();
		$saved   = ( ! empty( $options['images'] ) ) ? array_map( 'intval', wp_list_pluck( $options['images'], 'image_id' ) ) : array();
		$t::same( array_map( 'intval', $photos ), $saved, 'the album keeps the 3 chosen photos' );
	}
);

/*
 * Video Sales: a video is a download product, and each video is bought once.
 */

$product_types_test(
	'Video: always one of each video',
	function () use ( $t, $product_types_video_product, $product_types_video_gallery, $product_types_add, $product_types_lines ) {
		$t::tax( 10 );
		$gallery = $product_types_video_gallery( 1 );
		$product_types_add( $product_types_video_product( 25 ), 3, $gallery['photos'][0], $gallery['id'] );
		$cart  = $t::calculate();
		$lines = $product_types_lines();
		$t::same( 1, (int) $lines[0]->get_qty(), 'quantity is 1' );
		$t::money( 25, $cart->get_subtotal(), 'subtotal' );
		$t::money( 2.50, $cart->get_tax(), 'tax' );
		$t::money( 27.50, $cart->get_total(), 'total' );
	}
);

$product_types_test(
	'Video: adding the same video again keeps one',
	function () use ( $t, $product_types_video_product, $product_types_video_gallery, $product_types_add, $product_types_lines ) {
		$gallery    = $product_types_video_gallery( 1 );
		$product_id = $product_types_video_product( 25, array( 'taxable' => '' ) );
		$product_types_add( $product_id, 1, $gallery['photos'][0], $gallery['id'] );
		$product_types_add( $product_id, 1, $gallery['photos'][0], $gallery['id'] );
		$cart = $t::new_request();
		$t::same( 1, count( $product_types_lines() ), 'one line' );
		$t::same( 1, $cart->get_item_count(), 'one video' );
		$t::money( 25, $cart->get_total(), 'total' );
	}
);

$product_types_test(
	'Video: changing the quantity in the cart keeps it at one',
	function () use ( $t, $product_types_video_product, $product_types_video_gallery, $product_types_add ) {
		$gallery = $product_types_video_gallery( 1 );
		$item    = $product_types_add( $product_types_video_product( 25, array( 'taxable' => '' ) ), 1, $gallery['photos'][0], $gallery['id'] );
		SPC()->cart->update_item_quantity_by_hash( $item['hash'], 5 );
		$cart = $t::new_request();
		$t::same( 1, $cart->get_item_count(), 'one video' );
		$t::money( 25, $cart->get_total(), 'total' );
	}
);

$product_types_test(
	'Video: two different videos are two items',
	function () use ( $t, $product_types_video_product, $product_types_video_gallery, $product_types_add, $product_types_lines ) {
		$gallery    = $product_types_video_gallery( 2 );
		$product_id = $product_types_video_product( 25, array( 'taxable' => '' ) );
		$product_types_add( $product_id, 1, $gallery['photos'][0], $gallery['id'] );
		$product_types_add( $product_id, 1, $gallery['photos'][1], $gallery['id'] );
		$cart = $t::new_request();
		$t::same( 2, count( $product_types_lines() ), 'two lines' );
		$t::money( 50, $cart->get_total(), 'total' );
	}
);

// The customer picked shipping while a print was in the cart, then removed the print.
$product_types_test(
	'Video: no shipping on an order of only videos',
	function () use ( $t, $product_types_video_product, $product_types_video_gallery, $product_types_add ) {
		$gallery = $product_types_video_gallery( 1 );
		$product_types_add( $product_types_video_product( 25, array( 'taxable' => '' ) ), 1, $gallery['photos'][0], $gallery['id'] );
		$t::flat_shipping( 10, false );
		$cart = $t::calculate();
		$t::same( false, (bool) $cart->needs_shipping(), 'order does not need shipping' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 25, $cart->get_total(), 'total' );
	}
);

$product_types_test(
	'Video with prints: flat rate per item counts only the prints',
	function () use ( $t, $product_types_video_product, $product_types_print, $product_types_video_gallery, $product_types_add, $product_types_flat_rate_per_item ) {
		$gallery = $product_types_video_gallery( 2 );
		$product_types_add( $product_types_video_product( 25, array( 'taxable' => '' ) ), 1, $gallery['photos'][0], $gallery['id'] );
		$product_types_add( $product_types_print( 10, array( 'taxable' => '' ) ), 2, $gallery['photos'][1], $gallery['id'] );
		$product_types_flat_rate_per_item( 3 );
		$cart = $t::calculate();
		// 2 prints at 3 each. The video is not shipped.
		$t::money( 6, $cart->get_shipping(), 'shipping' );
		$t::money( 51, $cart->get_total(), '25 + 20 + 6 shipping' );
	}
);

$product_types_test(
	'Video: a discount limited to one item takes off one video',
	function () use ( $t, $product_types_video_product, $product_types_video_gallery, $product_types_add ) {
		$gallery    = $product_types_video_gallery( 3 );
		$product_id = $product_types_video_product( 20, array( 'taxable' => '' ) );
		foreach ( $gallery['photos'] as $photo_id ) {
			$product_types_add( $product_id, 1, $photo_id, $gallery['id'] );
		}
		$t::discount( 'percent-product', 50, array( 'max_product_quantity' => 1 ) );
		$cart = $t::calculate();
		// Half off 1 of the 3 videos.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 50, $cart->get_total(), 'total' );
	}
);

$product_types_test(
	'Video: a fixed discount bigger than the video',
	function () use ( $t, $product_types_video_product, $product_types_video_gallery, $product_types_add ) {
		$t::tax( 10 );
		$gallery = $product_types_video_gallery( 1 );
		$product_types_add( $product_types_video_product( 25 ), 1, $gallery['photos'][0], $gallery['id'] );
		$t::discount( 'amount-total', 50 );
		$cart = $t::calculate();
		$t::money( 25, $cart->get_discount(), 'discount is capped at the video price' );
		$t::money( 0, $cart->get_tax(), 'no tax on nothing' );
		$t::money( 0, $cart->get_total(), 'total' );
	}
);

$product_types_test(
	'Video: gift card pays for the whole order',
	function () use ( $t, $product_types_video_product, $product_types_video_gallery, $product_types_add, $product_types_gift_card_used ) {
		$t::tax( 10 );
		$gallery = $product_types_video_gallery( 1 );
		$product_types_add( $product_types_video_product( 25 ), 1, $gallery['photos'][0], $gallery['id'] );
		$t::gift_card( 100 );
		$cart = $t::calculate();
		$t::money( 27.50, $product_types_gift_card_used(), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

$product_types_test(
	'Video: prices include tax, with a discount code',
	function () use ( $t, $product_types_video_product, $product_types_video_gallery, $product_types_add ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$gallery = $product_types_video_gallery( 1 );
		$product_types_add( $product_types_video_product( 30 ), 1, $gallery['photos'][0], $gallery['id'] );
		$cart = $t::calculate();
		$t::money( 30, $cart->get_total(), 'total' );
		$t::money( 5, $cart->get_tax(), 'tax included in 30' );

		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		// 10% of 30. The tax inside 27 is 27 / 6.
		$t::money( 3, $cart->get_discount(), 'discount' );
		$t::money( 27, $cart->get_total(), 'total after the discount' );
		$t::money( 4.50, $cart->get_tax(), 'tax included in 27' );
	}
);

// The store ships prints, but this customer only bought a video.
$product_types_test(
	'Order: video only order has no shipping',
	function () use ( $t, $product_types_video_product, $product_types_video_gallery, $product_types_add, $product_types_snapshot, $product_types_order_matches ) {
		$t::tax( 10 );
		$t::option( 'shipping_methods', array( 'spctestflat' => array( 'id' => 'flat_rate', 'active' => 1 ) ) );
		$t::option( 'flat_rate_price_spctestflat', '10' );
		$t::option( 'flat_rate_price_type_spctestflat', '' );
		$t::option( 'flat_rate_taxable_spctestflat', '' );
		$gallery = $product_types_video_gallery( 1 );
		$product_types_add( $product_types_video_product( 25 ), 1, $gallery['photos'][0], $gallery['id'] );
		$t::checkout_address();
		$cart = $t::calculate();
		$t::money( 27.50, $cart->get_total(), 'cart total' );
		$seen  = $product_types_snapshot();
		$order = $t::place_order();
		$product_types_order_matches( $order, $seen );
		// With Gift Cards active an all-digital order has no delivery step at all, so only
		// check that nothing about shipping ended up on the order.
		$t::same( true, empty( $order->get_shipping_method() ), 'no shipping method on the order' );
		$t::money( 0, $order->get_shipping(), 'no shipping' );
		$t::money( 27.50, $order->get_total(), 'order total' );
	}
);

/*
 * Mixed carts: all the product types together, placed as real orders.
 */

$product_types_test(
	'Order: print, album, general product and video with tax, shipping and a discount',
	function () use ( $t, $product_types_album, $product_types_print, $product_types_general, $product_types_video_product, $product_types_gallery, $product_types_attach_video, $product_types_add, $product_types_snapshot, $product_types_order_matches ) {
		$t::tax( 7.25 );
		$gallery = $product_types_gallery( 4 );
		$photos  = $gallery['photos'];
		$product_types_attach_video( $photos[3] );
		$product_types_add( $product_types_print( 12.50 ), 2, $photos[0], $gallery['id'] );
		$product_types_add( $product_types_album( 89.99 ), 1, 0, $gallery['id'], array( 'images' => array( $photos[0], $photos[1], $photos[2] ) ) );
		$product_types_add( $product_types_general( 19.95 ), 1 );
		$product_types_add( $product_types_video_product( 35 ), 1, $photos[3], $gallery['id'] );
		$t::flat_shipping( 8.95, true );
		$t::discount( 'percent-total', 10 );
		$t::checkout_address();
		$cart = $t::calculate();
		// 25 + 89.99 + 19.95 + 35 = 169.94. 10% off is 16.994.
		// Tax is 7.25% of 169.94 - 16.99 + 8.95 shipping = 11.73775.
		$t::money( 169.94, $cart->get_subtotal(), 'cart subtotal' );
		$t::money( 16.99, $cart->get_discount(), 'cart discount' );
		$t::money( 8.95, $cart->get_shipping(), 'cart shipping' );
		$t::money( 11.74, $cart->get_tax(), 'cart tax' );
		$t::money( 173.64, $cart->get_total(), 'cart total' );
		$seen  = $product_types_snapshot();
		$order = $t::place_order();
		$product_types_order_matches( $order, $seen );
		$t::money( 173.64, $order->get_total(), 'order total' );
	}
);

$product_types_test(
	'Order: prices include tax with a general product, a video and shipping',
	function () use ( $t, $product_types_general, $product_types_video_product, $product_types_video_gallery, $product_types_add, $product_types_snapshot, $product_types_order_matches ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$gallery = $product_types_video_gallery( 1 );
		$product_types_add( $product_types_general( 36 ), 2 );
		$product_types_add( $product_types_video_product( 30 ), 1, $gallery['photos'][0], $gallery['id'] );
		$t::flat_shipping( 6, true );
		$t::checkout_address();
		$cart = $t::calculate();
		// The customer pays the prices shown: 72 + 30 + 6. The tax inside is a sixth of each.
		$t::money( 108, $cart->get_total(), 'cart total' );
		$t::money( 18, $cart->get_tax(), 'cart tax' );
		$seen  = $product_types_snapshot();
		$order = $t::place_order();
		$product_types_order_matches( $order, $seen );
		$t::money( 108, $order->get_total(), 'order total' );
	}
);

$product_types_test(
	'Order: discount after tax and a gift card on a general product and a video',
	function () use ( $t, $product_types_general, $product_types_video_product, $product_types_video_gallery, $product_types_add, $product_types_snapshot, $product_types_order_matches, $product_types_gift_card_used ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$gallery = $product_types_video_gallery( 1 );
		$product_types_add( $product_types_general( 50 ), 1 );
		$product_types_add( $product_types_video_product( 20 ), 1, $gallery['photos'][0], $gallery['id'] );
		$t::flat_shipping( 10, false );
		$t::discount( 'percent-total', 10 );
		$t::gift_card( 40 );
		$t::checkout_address();
		$cart = $t::calculate();
		// Tax is 10% of 70. Then 10% off 77 is 7.70. 70 + 7 - 7.70 + 10 shipping = 79.30, less the 40 card.
		$t::money( 7, $cart->get_tax(), 'cart tax' );
		$t::money( 7.70, $cart->get_discount(), 'cart discount' );
		$t::money( 40, $product_types_gift_card_used(), 'taken from the card' );
		$t::money( 39.30, $cart->get_total(), 'cart total' );
		$seen  = $product_types_snapshot();
		$order = $t::place_order();
		$product_types_order_matches( $order, $seen );
		$fees = (array) $order->get_fees();
		$t::money( -40, isset( $fees['gift_card'] ) ? $fees['gift_card']['amount'] : 0, 'gift card on the order' );
	}
);
