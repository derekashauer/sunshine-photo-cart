<?php
/**
 * Packages add-on: adding packages from photos and from Favorites, filling them with photos,
 * and placed orders.
 *
 * These go through the same AJAX handlers the gallery pages use (add to cart, fill a package
 * slot, "Add all to cart" on Favorites), so the cart line is built exactly as a customer's would
 * be. Orders are placed through the real checkout step with the Test payment method.
 */

$t = 'SPC_Totals_Test';

// Galleries made by a test. Their image list is cleared when the test ends. Otherwise, with
// the store's "delete images with the gallery" setting on, deleting a test gallery at the end of
// the run queues a background job for its photos, and the run deletes those photos itself.
$packages_orders_galleries = array();

// Every test here runs with outgoing requests and mail blocked. Adding to the cart also writes
// to the Analytics activity log and the automated email queue; those are switched off while a
// test runs so nothing is left behind in shared tables. Neither touches the money math.
$packages_orders_test = function ( $name, $callback ) use ( $t, &$packages_orders_galleries ) {
	$t::test(
		$name,
		function () use ( $t, $callback, &$packages_orders_galleries ) {
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
			// Proofing mode turns off buying, so make sure it is off for these tests.
			$t::option( 'proofing', '' );
			try {
				$callback();
			} finally {
				remove_filter( 'pre_http_request', $block_http, 1 );
				remove_filter( 'pre_wp_mail', '__return_false', 1 );
				foreach ( $quiet as $hook ) {
					add_action( $hook[0], $hook[1], $hook[2] );
				}
				foreach ( $packages_orders_galleries as $gallery_id ) {
					delete_post_meta( $gallery_id, 'images' );
				}
				$packages_orders_galleries = array();
			}
		}
	);
};

/**
 * A public gallery with some photos, saved the way the gallery screen and uploader save them.
 * Returns array( 'id' => gallery id, 'images' => image ids ).
 */
$packages_orders_gallery = function ( $image_count = 3, $price_level = '' ) use ( $t, &$packages_orders_galleries ) {
	$gallery_id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-gallery',
			'post_status' => 'publish',
			'post_title'  => 'Totals test gallery',
		)
	);
	if ( ! $gallery_id || is_wp_error( $gallery_id ) ) {
		throw new Exception( 'Could not make a gallery' );
	}
	$t::track( 'post', $gallery_id );
	$packages_orders_galleries[] = $gallery_id;
	update_post_meta( $gallery_id, 'status', 'default' );
	update_post_meta( $gallery_id, 'access_type', '' );
	update_post_meta( $gallery_id, 'disable_products', '' );
	update_post_meta( $gallery_id, 'price_level', (string) $price_level );
	// Mark it as already announced, so nothing emails customers about a test gallery.
	update_post_meta( $gallery_id, '_sunshine_gallery_ready', time() );

	$image_ids = array();
	for ( $i = 1; $i <= $image_count; $i++ ) {
		$image_id = wp_insert_attachment(
			array(
				'post_title'     => 'Totals photo ' . $i,
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			),
			false,
			$gallery_id
		);
		if ( ! $image_id || is_wp_error( $image_id ) ) {
			throw new Exception( 'Could not make a photo' );
		}
		$t::track( 'post', $image_id );
		update_post_meta( $image_id, 'sunshine_file_name', 'totals-photo-' . $i . '.jpg' );
		update_post_meta( $image_id, 'sunshine_watermark', 0 );
		$image_ids[] = $image_id;
	}
	update_post_meta( $gallery_id, 'images', $image_ids );

	return array(
		'id'     => $gallery_id,
		'images' => $image_ids,
	);
};

// Make a package product the way the product screen saves it. $contents is a list of
// array( product_id, qty ). $prices and $shipping (Extra Shipping Cost) are price level id =>
// amount, for the levels that have one.
$packages_orders_make = function ( $price, $contents, $args = array() ) use ( $t ) {
	$args = wp_parse_args(
		$args,
		array(
			'taxable'      => true,
			'prices'       => array(),
			'shipping'     => array(),
			'qty_discount' => '',
		)
	);
	$id   = $t::product(
		$price,
		array(
			'name'         => 'Totals test package',
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
	update_post_meta( $id, 'disable_shipping', '' );
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
		$shipping[ $level_id ] = isset( $args['shipping'][ $level_id ] ) ? (string) $args['shipping'][ $level_id ] : '';
	}
	update_post_meta( $id, 'price', $prices );
	update_post_meta( $id, 'shipping', $shipping );
	return $id;
};

// A price level that is not the store default, or skip the test.
$packages_orders_other_level = function () use ( $t ) {
	$default = sunshine_get_default_price_level_id();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		if ( $level && $level->get_id() != $default ) {
			return $level->get_id();
		}
	}
	$t::skip( 'The store needs a second price level' );
};

// Call one of the store's AJAX handlers and return its JSON answer.
$packages_orders_ajax = function ( $handler, $post ) {
	$die = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $die );
	$saved_post        = $_POST;
	$saved_request     = $_REQUEST;
	$_POST             = $post;
	$_POST['security'] = wp_create_nonce( 'sunshinephotocart' );
	// check_ajax_referer() reads the nonce from $_REQUEST.
	$_REQUEST = array_merge( $_REQUEST, $_POST );
	ob_start();
	try {
		call_user_func( $handler );
	} catch ( SPC_Totals_Test_Done $e ) {
		// Every handler ends by sending a JSON answer.
	} finally {
		$output   = ob_get_clean();
		$_POST    = $saved_post;
		$_REQUEST = $saved_request;
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $die );
	}
	$answer = json_decode( $output, true );
	if ( ! is_array( $answer ) ) {
		throw new Exception( 'No JSON answer from ' . $handler . ': ' . substr( $output, 0, 200 ) );
	}
	return $answer;
};

// Click "Add to cart" for a product on a photo in a gallery.
$packages_orders_add_from_photo = function ( $product_id, $image_id, $gallery_id, $qty = 1 ) use ( $packages_orders_ajax ) {
	$answer = $packages_orders_ajax(
		'sunshine_modal_add_item_to_cart',
		array(
			'product_id' => (string) $product_id,
			'image_id'   => (string) $image_id,
			'gallery_id' => (string) $gallery_id,
			'qty'        => (string) $qty,
		)
	);
	if ( empty( $answer['success'] ) ) {
		throw new Exception( 'Package not added: ' . wp_json_encode( $answer ) );
	}
	return $answer['data'];
};

// Pick photos for one item of a package in the cart ($key is its row on the Package tab).
$packages_orders_fill = function ( $hash, $key, $image_ids ) use ( $packages_orders_ajax ) {
	return $packages_orders_ajax(
		'sunshine_multi_image_select_images_item',
		array(
			'ref'                => $hash,
			'key'                => (string) $key,
			'selected_image_ids' => array_map( 'strval', $image_ids ),
		)
	);
};

$packages_orders_saved_line = function ( $hash ) {
	return SPC()->cart->get_item_by_hash( $hash );
};

// What the cart shows right before the customer places the order.
$packages_orders_snapshot = function () {
	$cart  = SPC()->cart;
	$lines = array();
	foreach ( (array) $cart->get_cart_items() as $line ) {
		$lines[] = array(
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

$packages_orders_matches_cart = function ( $order, $seen ) use ( $t ) {
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
		$t::money( $line['total'], $items[ $i ]->get_total(), 'line ' . ( $i + 1 ) . ' total' );
		$t::money( $line['tax'], $items[ $i ]->get_tax_total(), 'line ' . ( $i + 1 ) . ' tax' );
		$t::money( $line['discount'], $items[ $i ]->get_discount_total(), 'line ' . ( $i + 1 ) . ' discount' );
	}
};

// The same checkout step as $t::place_order(), for a test where saving the order may fail
// part way. It always closes its output buffer and removes its filters, and it remembers every
// order it makes, because an order that fails part way has already been saved.
$packages_orders_place_safely = function () use ( $t ) {
	$t::checkout_address();
	$die          = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	$track_orders = function ( $post_id, $post ) use ( $t ) {
		if ( 'sunshine-order' === $post->post_type ) {
			$t::track( 'order', $post_id );
		}
	};
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $die );
	add_action( 'wp_insert_post', $track_orders, 10, 2 );
	$_POST['security']       = wp_create_nonce( 'sunshine-checkout-init-order' );
	$_POST['payment_method'] = 'test';
	SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
	$level  = ob_get_level();
	$output = '';
	ob_start();
	try {
		SPC()->cart->init_order();
	} catch ( SPC_Totals_Test_Done $e ) {
		// init_order() always ends by sending a JSON answer.
	} finally {
		while ( ob_get_level() > $level ) {
			$output = ob_get_clean() . $output;
		}
		unset( $_POST['security'], $_POST['payment_method'] );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $die );
		remove_action( 'wp_insert_post', $track_orders, 10 );
	}
	$response = json_decode( $output, true );
	if ( empty( $response['success'] ) || empty( $response['data']['order_id'] ) ) {
		throw new Exception( 'Order was not placed: ' . wp_json_encode( SPC()->cart->get_errors() ) . ' ' . substr( $output, 0, 300 ) );
	}
	return sunshine_get_order( $response['data']['order_id'] );
};

// Two 8x10 prints and a digital download, filled from a gallery.
$packages_orders_typical = function ( $price, $args = array() ) use ( $t, $packages_orders_make ) {
	$print    = $t::product( 45, array( 'name' => 'Totals test 8x10' ) );
	$download = $t::product( 50, array( 'name' => 'Totals test download', 'type' => 'download', 'taxable' => false ) );
	return array(
		'package'  => $packages_orders_make( $price, array( array( $print, 2 ), array( $download, 1 ) ), $args ),
		'print'    => $print,
		'download' => $download,
	);
};

/*
 * Adding packages to the cart.
 */

$packages_orders_test(
	'Package added from a photo: in the cart at the package price, without the photo',
	function () use ( $t, $packages_orders_gallery, $packages_orders_typical, $packages_orders_add_from_photo, $packages_orders_saved_line ) {
		$gallery = $packages_orders_gallery( 3 );
		$made    = $packages_orders_typical( 99 );
		$added   = $packages_orders_add_from_photo( $made['package'], $gallery['images'][0], $gallery['id'] );
		$t::same( 'package', $added['type'], 'the answer says it is a package, so the fill window opens' );
		$line = $packages_orders_saved_line( $added['item']['hash'] );
		// The photo clicked on is not one of the package photos; the customer picks those next.
		$t::same( true, empty( $line['image_id'] ), 'no photo on the line' );
		$t::same( (string) $gallery['id'], (string) $line['gallery_id'], 'the gallery is kept for filling' );
		$cart = $t::calculate();
		$t::money( 99, $cart->get_total(), 'total' );
	}
);

// Each package gets its own photos, so adding the same package again makes a second line.
$packages_orders_test(
	'Package added twice from the same photo is two packages to fill',
	function () use ( $t, $packages_orders_gallery, $packages_orders_typical, $packages_orders_add_from_photo ) {
		$gallery = $packages_orders_gallery( 3 );
		$made    = $packages_orders_typical( 99 );
		$packages_orders_add_from_photo( $made['package'], $gallery['images'][0], $gallery['id'] );
		$packages_orders_add_from_photo( $made['package'], $gallery['images'][0], $gallery['id'] );
		$cart = $t::calculate();
		$t::same( 2, count( $cart->get_cart_items() ), 'two lines' );
		$t::money( 198, $cart->get_total(), 'total' );
	}
);

$packages_orders_test(
	'Package added from a gallery on another price level costs that level\'s price',
	function () use ( $t, $packages_orders_gallery, $packages_orders_typical, $packages_orders_add_from_photo, $packages_orders_other_level ) {
		$level   = $packages_orders_other_level();
		$gallery = $packages_orders_gallery( 3, $level );
		$made    = $packages_orders_typical( 150, array( 'prices' => array( $level => '120' ) ) );
		$t::tax( 10 );
		$packages_orders_add_from_photo( $made['package'], $gallery['images'][0], $gallery['id'] );
		$cart = $t::calculate();
		// 120 at the gallery's level, not the 150 default. Tax 12.
		$t::money( 120, $cart->get_subtotal(), 'subtotal' );
		$t::money( 12, $cart->get_tax(), 'tax' );
		$t::money( 132, $cart->get_total(), 'total' );
	}
);

$packages_orders_test(
	'Package discount: a code for one gallery covers a package added from that gallery',
	function () use ( $t, $packages_orders_gallery, $packages_orders_typical, $packages_orders_add_from_photo ) {
		$gallery = $packages_orders_gallery( 2 );
		$made    = $packages_orders_typical( 100, array( 'taxable' => false ) );
		$packages_orders_add_from_photo( $made['package'], $gallery['images'][0], $gallery['id'] );
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		// The discount screen saves chosen galleries as a list of ids.
		$t::discount( 'percent-total', 10, array( 'allowed_galleries' => array( $gallery['id'] ) ) );
		$cart = $t::calculate();
		// 10% of the package from that gallery. The store product has no gallery.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 110, $cart->get_total(), 'total' );
	}
);

/*
 * Filling packages with photos.
 */

$packages_orders_test(
	'Filling a package with photos keeps the package price',
	function () use ( $t, $packages_orders_gallery, $packages_orders_typical, $packages_orders_add_from_photo, $packages_orders_fill, $packages_orders_saved_line ) {
		$gallery = $packages_orders_gallery( 3 );
		$made    = $packages_orders_typical( 99 );
		$t::tax( 10 );
		$added = $packages_orders_add_from_photo( $made['package'], $gallery['images'][0], $gallery['id'] );
		$hash  = $added['item']['hash'];
		$packages_orders_fill( $hash, 0, array( $gallery['images'][0], $gallery['images'][1] ) );
		$packages_orders_fill( $hash, 1, array( $gallery['images'][2] ) );
		$line = $packages_orders_saved_line( $hash );
		$t::same( array( $gallery['images'][0], $gallery['images'][1] ), $line['meta']['package'][0]['images'], 'photos for the 8x10s' );
		$t::same( array( $gallery['images'][2] ), $line['meta']['package'][1]['images'], 'photo for the download' );
		$cart = $t::new_request();
		$t::same( 1, count( $cart->get_cart_items() ), 'still one line' );
		$t::money( 9.90, $cart->get_tax(), 'tax' );
		$t::money( 108.90, $cart->get_total(), 'total' );
	}
);

$packages_orders_test(
	'Filling a package with more photos than an item holds keeps only what fits',
	function () use ( $t, $packages_orders_gallery, $packages_orders_typical, $packages_orders_add_from_photo, $packages_orders_fill, $packages_orders_saved_line ) {
		$gallery = $packages_orders_gallery( 3 );
		$made    = $packages_orders_typical( 99 );
		$added   = $packages_orders_add_from_photo( $made['package'], $gallery['images'][0], $gallery['id'] );
		$hash    = $added['item']['hash'];
		// The package holds two 8x10s.
		$packages_orders_fill( $hash, 0, $gallery['images'] );
		$line = $packages_orders_saved_line( $hash );
		$t::same( array( $gallery['images'][0], $gallery['images'][1] ), $line['meta']['package'][0]['images'], 'first two photos kept' );
		$cart = $t::new_request();
		$t::money( 99, $cart->get_total(), 'total' );
	}
);

// A multi-image product (a collage of 3 photos) in a package twice holds 2 x 3 = 6 photos.
// The same photo can be picked more than once (changelog 3.1).
$packages_orders_test(
	'Filling a multi-image product in a package holds its photo count for each one',
	function () use ( $t, $packages_orders_gallery, $packages_orders_make, $packages_orders_add_from_photo, $packages_orders_fill, $packages_orders_saved_line ) {
		$gallery = $packages_orders_gallery( 4 );
		$collage = $t::product( 30, array( 'type' => 'multi-image', 'name' => 'Totals test collage' ) );
		update_post_meta( $collage, 'image_count', '3' );
		$package = $packages_orders_make( 75, array( array( $collage, 2 ) ) );
		$added   = $packages_orders_add_from_photo( $package, $gallery['images'][0], $gallery['id'] );
		$hash    = $added['item']['hash'];
		$picked  = array_merge( $gallery['images'], array( $gallery['images'][0], $gallery['images'][1], $gallery['images'][2] ) );
		$packages_orders_fill( $hash, 0, $picked );
		$line = $packages_orders_saved_line( $hash );
		$t::same( array_slice( $picked, 0, 6 ), $line['meta']['package'][0]['images'], 'six photos kept' );
		$cart = $t::new_request();
		$t::money( 75, $cart->get_total(), 'total' );
	}
);

$packages_orders_test(
	'Changing the quantity of a filled package charges for each one and keeps the photos',
	function () use ( $t, $packages_orders_gallery, $packages_orders_typical, $packages_orders_add_from_photo, $packages_orders_fill, $packages_orders_saved_line ) {
		$gallery = $packages_orders_gallery( 3 );
		$made    = $packages_orders_typical( 99 );
		$t::tax( 10 );
		$added = $packages_orders_add_from_photo( $made['package'], $gallery['images'][0], $gallery['id'] );
		$hash  = $added['item']['hash'];
		$packages_orders_fill( $hash, 0, array( $gallery['images'][0], $gallery['images'][1] ) );
		SPC()->cart->update_item_quantity_by_hash( $hash, 2 );
		$cart = $t::new_request();
		$line = $packages_orders_saved_line( $hash );
		$t::same( 2, (int) $line['qty'], 'quantity 2' );
		$t::same( array( $gallery['images'][0], $gallery['images'][1] ), $line['meta']['package'][0]['images'], 'photos kept' );
		// 99 x 2 = 198, tax 19.80.
		$t::money( 19.80, $cart->get_tax(), 'tax' );
		$t::money( 217.80, $cart->get_total(), 'total' );
	}
);

// On the Package tab, clicking Remove on a middle row and saving keeps the old row numbers for
// the rows left (0 and 2), because the form fields are not renumbered. The customer fills the
// package through the same window as always, so every photo they pick must still be saved and
// must reach the order.
$packages_orders_test(
	'Filling a package after a middle item was removed on the product screen keeps every photo',
	function () use ( $t, $packages_orders_gallery, $packages_orders_make, $packages_orders_add_from_photo, $packages_orders_ajax, $packages_orders_fill, $packages_orders_saved_line ) {
		$gallery  = $packages_orders_gallery( 3 );
		$print    = $t::product( 45, array( 'name' => 'Totals test 8x10' ) );
		$removed  = $t::product( 20, array( 'name' => 'Totals test 5x7' ) );
		$download = $t::product( 50, array( 'name' => 'Totals test download', 'type' => 'download', 'taxable' => false ) );
		$package  = $packages_orders_make( 99, array( array( $print, 2 ), array( $removed, 1 ), array( $download, 1 ) ) );
		// What the product screen saves after the 5x7 row is removed: package[0] and package[2].
		update_post_meta(
			$package,
			'package',
			array(
				0 => array(
					'product' => (string) $print,
					'qty'     => '2',
				),
				2 => array(
					'product' => (string) $download,
					'qty'     => '1',
				),
			)
		);
		$t::tax( 10 );
		$added = $packages_orders_add_from_photo( $package, $gallery['images'][0], $gallery['id'] );
		$hash  = $added['item']['hash'];

		// Open the fill window the way the gallery page does right after adding a package, and
		// pick photos with the item numbers that window gives each "Select Image(s)" button.
		$window = $packages_orders_ajax(
			'sunshine_modal_display',
			array(
				'hook' => 'package_fill',
				'item' => $added['item'],
			)
		);
		$html   = isset( $window['data']['html'] ) ? $window['data']['html'] : '';
		preg_match_all( '/data-key="([^"]*)"/', $html, $found );
		$keys = $found[1];
		$t::same( 2, count( $keys ), 'the window has two items to fill' );
		if ( 2 !== count( $keys ) ) {
			return;
		}
		$packages_orders_fill( $hash, $keys[0], array( $gallery['images'][0], $gallery['images'][1] ) );
		$packages_orders_fill( $hash, $keys[1], array( $gallery['images'][2] ) );

		// The line must hold a photo for each item, under the item it was picked for.
		$line   = $packages_orders_saved_line( $hash );
		$filled = isset( $line['meta']['package'] ) ? $line['meta']['package'] : array();
		$t::same( array( $gallery['images'][0], $gallery['images'][1] ), isset( $filled[0]['images'] ) ? $filled[0]['images'] : array(), 'photos for the 8x10s' );
		$t::same( array( $gallery['images'][2] ), isset( $filled[2]['images'] ) ? $filled[2]['images'] : array(), 'photo for the download' );

		$t::checkout_address();
		$t::new_request();
		// 99 + 9.90 tax.
		$t::money( 108.90, SPC()->cart->get_total(), 'cart total' );
		$order = $t::place_order();
		$t::money( 108.90, $order->get_total(), 'order total' );
		$items    = array_values( (array) $order->get_items() );
		$contents = empty( $items[0] ) ? array() : $items[0]->get_meta_value( 'package' );
		$contents = is_array( $contents ) ? array_values( $contents ) : array();
		$t::same( 2, count( $contents ), 'two items in the package on the order' );
		if ( 2 === count( $contents ) ) {
			$t::same( array( 'totals-photo-1', 'totals-photo-2' ), wp_list_pluck( (array) $contents[0]['images'], 'filename' ), '8x10 photos on the order' );
			$t::same( array( 'totals-photo-3' ), wp_list_pluck( (array) $contents[1]['images'], 'filename' ), 'download photo on the order' );
		}
	}
);

/*
 * "Add all to cart" on the Favorites page.
 */

// Docs: "One package goes in the cart, not one per favorite."
$packages_orders_test(
	'Favorites: Add all to cart with a package adds one package, not one per favorite',
	function () use ( $t, $packages_orders_gallery, $packages_orders_typical, $packages_orders_ajax ) {
		$gallery     = $packages_orders_gallery( 4 );
		$made        = $packages_orders_typical( 99 );
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_favorites', $gallery['images'] );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart     = new SPC_Cart();
		$answer         = $packages_orders_ajax(
			'sunshine_modal_add_favorites_to_cart',
			array(
				'product_id' => (string) $made['package'],
				'qty'        => '1',
			)
		);
		$t::same( true, ! empty( $answer['success'] ), 'added' );
		$t::same( 'package', isset( $answer['data']['type'] ) ? $answer['data']['type'] : '', 'the answer says it is a package, so the fill window opens' );
		$cart  = $t::new_request();
		$lines = (array) $cart->get_cart();
		$t::same( 1, count( $lines ), 'one line' );
		$line = reset( $lines );
		$t::same( 1, (int) $line['qty'], 'quantity 1' );
		$t::same( 'favorites', isset( $line['meta']['source'] ) ? $line['meta']['source'] : '', 'the fill window starts on the favorites' );
		$t::money( 99, $cart->get_total(), 'total' );
	}
);

$packages_orders_test(
	'Favorites: a package from Add all to cart is priced at the favorites\' gallery price level',
	function () use ( $t, $packages_orders_gallery, $packages_orders_typical, $packages_orders_ajax, $packages_orders_other_level ) {
		$level       = $packages_orders_other_level();
		$gallery     = $packages_orders_gallery( 2, $level );
		$made        = $packages_orders_typical( 150, array( 'prices' => array( $level => '120' ) ) );
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_favorites', $gallery['images'] );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart     = new SPC_Cart();
		$packages_orders_ajax(
			'sunshine_modal_add_favorites_to_cart',
			array(
				'product_id' => (string) $made['package'],
				'qty'        => '1',
			)
		);
		$cart = $t::new_request();
		$t::money( 120, $cart->get_total(), 'total' );
	}
);

/*
 * Placed orders.
 */

$packages_orders_test(
	'Order: a filled package with tax and shipping matches the cart and keeps its photos',
	function () use ( $t, $packages_orders_gallery, $packages_orders_typical, $packages_orders_add_from_photo, $packages_orders_fill, $packages_orders_snapshot, $packages_orders_matches_cart ) {
		$gallery = $packages_orders_gallery( 3 );
		$made    = $packages_orders_typical( 89.99 );
		$t::tax( 7.25 );
		$added = $packages_orders_add_from_photo( $made['package'], $gallery['images'][0], $gallery['id'] );
		$hash  = $added['item']['hash'];
		$packages_orders_fill( $hash, 0, array( $gallery['images'][0], $gallery['images'][1] ) );
		$packages_orders_fill( $hash, 1, array( $gallery['images'][2] ) );
		$t::flat_shipping( 8.95, true );
		$t::checkout_address();
		$t::new_request();
		$seen = $packages_orders_snapshot();
		// Tax: 89.99 x 7.25% = 6.52, plus 8.95 x 7.25% = 0.65. Total 89.99 + 8.95 + 7.17.
		$t::money( 7.17, $seen['tax'], 'cart tax' );
		$t::money( 106.11, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$packages_orders_matches_cart( $order, $seen );

		$items = array_values( (array) $order->get_items() );
		if ( empty( $items[0] ) ) {
			return;
		}
		$t::same( 'package', $items[0]->get_type(), 'order line type' );
		$t::money( 89.99, $items[0]->get_price(), 'order line price' );
		// The order keeps what is in the package and which photo goes on each item.
		$contents = $items[0]->get_meta_value( 'package' );
		$t::same( 2, is_array( $contents ) ? count( $contents ) : 0, 'two items in the package' );
		if ( is_array( $contents ) && 2 === count( $contents ) ) {
			$t::same( (string) $made['print'], (string) $contents[0]['product'], 'first item is the 8x10' );
			$t::same( '2', (string) $contents[0]['qty'], 'two 8x10s' );
			$t::same( array( 'totals-photo-1', 'totals-photo-2' ), wp_list_pluck( $contents[0]['images'], 'filename' ), '8x10 photos' );
			$t::same( (string) $made['download'], (string) $contents[1]['product'], 'second item is the download' );
			$t::same( array( 'totals-photo-3' ), wp_list_pluck( $contents[1]['images'], 'filename' ), 'download photo' );
			$t::same( 'Totals test gallery', $contents[0]['images'][0]['gallery_name'], 'gallery name on each photo' );
		}
	}
);

// The order must keep the same line tax the cart showed. Each line's tax is 7.25% of 99.99,
// 7.25 in all; it must not be rebuilt from a rounded tax for one package.
$packages_orders_test(
	'Order: three of the same package keep the cart\'s line tax',
	function () use ( $t, $packages_orders_typical, $packages_orders_snapshot, $packages_orders_matches_cart ) {
		$made = $packages_orders_typical( 33.33 );
		$t::tax( 7.25 );
		$t::add( $made['package'], 3 );
		$t::checkout_address();
		$t::calculate();
		$seen = $packages_orders_snapshot();
		// 99.99 x 7.25% = 7.2493.
		$t::money( 7.25, $seen['tax'], 'cart tax' );
		$t::money( 7.25, $seen['lines'][0]['tax'], 'cart line tax' );
		$t::money( 107.24, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$packages_orders_matches_cart( $order, $seen );
	}
);

$packages_orders_test(
	'Order: a package with a code, a gift card and shipping',
	function () use ( $t, $packages_orders_typical, $packages_orders_snapshot, $packages_orders_matches_cart ) {
		$made = $packages_orders_typical( 100 );
		$t::tax( 10 );
		$t::add( $made['package'] );
		$t::flat_shipping( 10, true );
		$t::discount( 'percent-total', 20 );
		$t::gift_card( 50 );
		$t::checkout_address();
		$t::calculate();
		$seen = $packages_orders_snapshot();
		// 100 - 20 + 10 shipping + 9 tax - 50 gift card.
		$t::money( 49, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$packages_orders_matches_cart( $order, $seen );
		$fees = (array) $order->get_fees();
		$t::money( -50, isset( $fees['gift_card'] ) ? $fees['gift_card']['amount'] : 0, 'gift card on the order' );
	}
);

$packages_orders_test(
	'Order: a package when prices include tax',
	function () use ( $t, $packages_orders_typical, $packages_orders_snapshot, $packages_orders_matches_cart ) {
		$made = $packages_orders_typical( 119.99 );
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $made['package'] );
		$t::flat_shipping( 6, true );
		$t::checkout_address();
		$t::calculate();
		$seen = $packages_orders_snapshot();
		// The customer pays the prices shown: 119.99 + 6. Tax inside: 20.00 + 1.00.
		$t::money( 125.99, $seen['total'], 'cart total' );
		$t::money( 21, $seen['tax'], 'cart tax' );
		$order = $t::place_order();
		$packages_orders_matches_cart( $order, $seen );
	}
);

$packages_orders_test(
	'Order: a package line with a quantity discount',
	function () use ( $t, $packages_orders_typical, $packages_orders_snapshot, $packages_orders_matches_cart ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$rule = array(
			'calc'  => '',
			'items' => array(
				array(
					'qty'      => 2,
					'discount' => 15,
					'type'     => 'amount',
				),
			),
		);
		$made = $packages_orders_typical( 80, array( 'qty_discount' => $rule ) );
		$t::tax( 8 );
		$t::add( $made['package'], 2 );
		$t::checkout_address();
		$t::calculate();
		$seen = $packages_orders_snapshot();
		// 160 - 15 x 2 = 130. Tax 8% of 130 = 10.40.
		$t::money( 30, $seen['lines'][0]['discount'], 'cart line discount' );
		$t::money( 140.40, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$packages_orders_matches_cart( $order, $seen );
	}
);

// A photographer can delete a photo after a customer picked it for a package. Assumed rule:
// the customer can still check out and pays what the cart showed; the missing photo just
// can't be listed on the order.
$packages_orders_test(
	'Order: a package photo deleted before checkout does not stop the order',
	function () use ( $t, $packages_orders_gallery, $packages_orders_typical, $packages_orders_add_from_photo, $packages_orders_fill, $packages_orders_snapshot, $packages_orders_matches_cart, $packages_orders_place_safely ) {
		$gallery = $packages_orders_gallery( 3 );
		$made    = $packages_orders_typical( 99, array( 'taxable' => false ) );
		$added   = $packages_orders_add_from_photo( $made['package'], $gallery['images'][0], $gallery['id'] );
		$hash    = $added['item']['hash'];
		$packages_orders_fill( $hash, 0, array( $gallery['images'][0], $gallery['images'][1] ) );
		$packages_orders_fill( $hash, 1, array( $gallery['images'][2] ) );

		// The photographer deletes the third photo from the gallery.
		wp_delete_post( $gallery['images'][2], true );
		update_post_meta( $gallery['id'], 'images', array( $gallery['images'][0], $gallery['images'][1] ) );

		$t::checkout_address();
		$t::new_request();
		$seen = $packages_orders_snapshot();
		$t::money( 99, $seen['total'], 'cart total' );
		$order = $packages_orders_place_safely();
		$packages_orders_matches_cart( $order, $seen );
	}
);

$packages_orders_test(
	'Order: a package of downloads is placed with no shipping',
	function () use ( $t, $packages_orders_make, $packages_orders_snapshot, $packages_orders_matches_cart ) {
		$download = $t::product( 30, array( 'type' => 'download', 'taxable' => false ) );
		$package  = $packages_orders_make( 70, array( array( $download, 3 ) ) );
		$t::tax( 10 );
		$t::add( $package );
		$t::flat_shipping( 10, true );
		$t::checkout_address();
		$t::calculate();
		$seen = $packages_orders_snapshot();
		// The package is taxable: 70 + 7 tax, no shipping.
		$t::money( 0, $seen['shipping'], 'cart shipping' );
		$t::money( 77, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$packages_orders_matches_cart( $order, $seen );
	}
);

// The Extra Shipping Cost is typed in for each price level, like the price. A package added from
// a gallery on another price level adds that level's extra cost, not the default level's.
$packages_orders_test(
	'Order: a package from a gallery on another price level adds that level\'s extra shipping',
	function () use ( $t, $packages_orders_gallery, $packages_orders_typical, $packages_orders_add_from_photo, $packages_orders_other_level, $packages_orders_snapshot, $packages_orders_matches_cart ) {
		$level   = $packages_orders_other_level();
		$default = sunshine_get_default_price_level_id();
		$gallery = $packages_orders_gallery( 1, $level );
		$made    = $packages_orders_typical(
			150,
			array(
				'taxable'  => false,
				'prices'   => array( $level => '120' ),
				'shipping' => array(
					$default => '8',
					$level   => '5',
				),
			)
		);
		$packages_orders_add_from_photo( $made['package'], $gallery['images'][0], $gallery['id'] );
		$t::flat_shipping( 10, false );
		$t::checkout_address();
		$t::calculate();
		$seen = $packages_orders_snapshot();
		// 10 flat rate + 5 extra at the gallery's level. 120 + 15.
		$t::money( 15, $seen['shipping'], 'cart shipping' );
		$t::money( 135, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$packages_orders_matches_cart( $order, $seen );
	}
);

// A session fee can include a package (the session's product list offers every product type but
// session fees). Each package is its own line, so with one package included the first line is
// free and the second is charged in full, with tax only on the one that is charged.
$packages_orders_test(
	'Order: a package included with the customer\'s session is free, a second one is charged',
	function () use ( $t, $packages_orders_gallery, $packages_orders_typical, $packages_orders_add_from_photo, $packages_orders_snapshot, $packages_orders_matches_cart ) {
		$t::require_plugin( 'sunshine_session_fees_cart_item_discount', 'Session Fees add-on' );
		$gallery     = $packages_orders_gallery( 2 );
		$made        = $packages_orders_typical( 99 );
		$customer_id = $t::customer();
		// What a paid session fee order gives the customer: the session fee product's list of
		// included products, saved as text the way its product screen saves it.
		update_user_meta(
			$customer_id,
			'sunshine_session_products',
			array(
				array(
					'product' => (string) $made['package'],
					'qty'     => '1',
				),
			)
		);
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart     = new SPC_Cart();
		$t::tax( 10 );
		$packages_orders_add_from_photo( $made['package'], $gallery['images'][0], $gallery['id'] );
		$packages_orders_add_from_photo( $made['package'], $gallery['images'][1], $gallery['id'] );
		$t::checkout_address();
		$t::new_request();
		$seen = $packages_orders_snapshot();
		$t::same( 2, count( $seen['lines'] ), 'two package lines' );
		// First package free (99 off), second 99 + 9.90 tax.
		$t::money( 99, $seen['lines'][0]['discount'], 'first package is included' );
		$t::money( 0, isset( $seen['lines'][1] ) ? $seen['lines'][1]['discount'] : -1, 'second package is not' );
		$t::money( 9.90, $seen['tax'], 'cart tax' );
		$t::money( 108.90, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$packages_orders_matches_cart( $order, $seen );
	}
);
