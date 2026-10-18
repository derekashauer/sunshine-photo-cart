<?php
/**
 * Digital Downloads add-on: placed orders and download credits.
 *
 * A placed order must match what the customer saw in the cart, line by line. Orders are placed
 * through the real checkout step with the Test payment method, then finished the way a
 * completed payment finishes them (SPC_Cart::post_process_order). An order with only downloads
 * is marked Shipped once paid, because there is nothing left to send, and its files can be
 * downloaded.
 *
 * Download credits let a logged in customer download a photo without buying it: one credit a
 * photo, and a photo they already used a credit on is free to download again.
 */

require_once __DIR__ . '/addon-digital-downloads-helpers.php';

// What the cart shows right before the customer places the order.
$dl_snapshot = function () use ( $dl_lines ) {
	$lines = array();
	foreach ( $dl_lines() as $line ) {
		$lines[] = array(
			'qty'        => (int) $line->get_qty(),
			'price'      => (float) $line->get_price(),
			'total'      => (float) $line->get_total(),
			'tax'        => (float) $line->get_tax_total(),
			'discount'   => (float) $line->get_discount_total(),
			'image_id'   => (int) $line->get_image_id(),
			'gallery_id' => (int) $line->get_gallery_id(),
		);
	}
	$cart = SPC()->cart;
	return array(
		'subtotal' => (float) $cart->get_subtotal(),
		'discount' => (float) $cart->get_discount(),
		'shipping' => (float) $cart->get_shipping(),
		'tax'      => (float) $cart->get_tax(),
		'credits'  => (float) $cart->get_credits_applied(),
		'total'    => (float) $cart->get_total(),
		'lines'    => $lines,
	);
};

$dl_order_matches_cart = function ( $order, $seen ) use ( $t ) {
	$t::money( $seen['subtotal'], $order->get_subtotal(), 'order subtotal' );
	$t::money( $seen['discount'], $order->get_discount(), 'order discount' );
	$t::money( $seen['shipping'], $order->get_shipping(), 'order shipping' );
	$t::money( $seen['tax'], $order->get_tax(), 'order tax' );
	$t::money( $seen['credits'], $order->get_credits(), 'order credits' );
	$t::money( $seen['total'], $order->get_total(), 'order total' );

	$items = array_values( (array) $order->get_items() );
	$t::same( count( $seen['lines'] ), count( $items ), 'number of order lines' );
	foreach ( $seen['lines'] as $i => $line ) {
		if ( empty( $items[ $i ] ) ) {
			continue;
		}
		$label = 'line ' . ( $i + 1 ) . ' ';
		$t::same( $line['qty'], (int) $items[ $i ]->get_qty(), $label . 'quantity' );
		$t::money( $line['price'], $items[ $i ]->get_price(), $label . 'price' );
		$t::money( $line['total'], $items[ $i ]->get_total(), $label . 'total' );
		$t::money( $line['tax'], $items[ $i ]->get_tax_total(), $label . 'tax' );
		$t::money( $line['discount'], $items[ $i ]->get_discount_total(), $label . 'discount' );
		$t::same( $line['image_id'], (int) $items[ $i ]->get_image_id(), $label . 'photo' );
		$t::same( $line['gallery_id'], (int) $items[ $i ]->get_gallery_id(), $label . 'gallery' );
	}
};

// Finish an order the way a completed payment does, then read it fresh from the database.
$dl_finish = function ( $order ) {
	SPC()->cart->post_process_order( $order );
	return sunshine_get_order( $order->get_id() );
};

/*
 * Placed orders.
 */

$dl_test(
	'Order: photo downloads only',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo, $dl_snapshot, $dl_order_matches_cart, $dl_finish ) {
		$t::tax( 7.25 );
		$gallery  = $dl_gallery( 3 );
		$download = $dl_product( 15 );
		foreach ( $gallery['photos'] as $photo_id ) {
			$dl_add_photo( $download, $photo_id );
		}
		$t::checkout_address();
		$t::calculate();
		$seen = $dl_snapshot();
		// 15 x 7.25% = 1.0875, so 1.09 a file, 3.27 for 3.
		$t::money( 3.27, $seen['tax'], 'cart tax' );
		$t::money( 48.27, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$dl_order_matches_cart( $order, $seen );
		$t::money( 0, $order->get_shipping(), 'nothing shipped' );
		$t::same( '', (string) $order->get_shipping_method(), 'no shipping method' );

		$order = $dl_finish( $order );
		$t::same( 'shipped', $order->get_status(), 'paid and complete, nothing to send' );
		$t::same( true, $order->is_paid(), 'the files can be downloaded' );
	}
);

$dl_test(
	'Order: a print and downloads with shipping, tax and a discount',
	function () use ( $t, $dl_gallery, $dl_product, $dl_print, $dl_add_photo, $dl_snapshot, $dl_order_matches_cart, $dl_finish ) {
		$t::tax( 10 );
		$gallery  = $dl_gallery( 3 );
		$download = $dl_product( 15 );
		$dl_add_photo( $dl_print( 20 ), $gallery['photos'][0], 2 );
		$dl_add_photo( $download, $gallery['photos'][1] );
		$dl_add_photo( $download, $gallery['photos'][2] );
		$t::flat_shipping( 8, true );
		$t::discount( 'percent-total', 10 );
		$t::checkout_address();
		$t::calculate();
		$seen = $dl_snapshot();
		// 40 in prints + 30 in files = 70. 10% off = 7.00. Tax on 63 + 8 shipping = 7.10.
		$t::money( 7, $seen['discount'], 'cart discount' );
		$t::money( 8, $seen['shipping'], 'cart shipping' );
		$t::money( 7.10, $seen['tax'], 'cart tax' );
		$t::money( 78.10, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$dl_order_matches_cart( $order, $seen );

		// The print still has to be sent, so the order is not marked Shipped yet.
		$order = $dl_finish( $order );
		$t::same( 'new', $order->get_status(), 'waiting for the print to ship' );
	}
);

$dl_test(
	'Order: a full gallery download keeps its gallery',
	function () use ( $t, $dl_gallery, $dl_gallery_product, $dl_add_gallery, $dl_snapshot, $dl_order_matches_cart, $dl_finish ) {
		$t::tax( 10 );
		$gallery = $dl_gallery( 4 );
		$dl_add_gallery( $dl_gallery_product( 150 ), $gallery['id'] );
		$t::checkout_address();
		$t::calculate();
		$seen  = $dl_snapshot();
		$order = $t::place_order();
		$dl_order_matches_cart( $order, $seen );
		$items = array_values( (array) $order->get_items() );
		$t::same( (int) $gallery['id'], $items ? (int) $items[0]->get_gallery_id() : 0, 'the order line is for this gallery' );
		// 150 + 10% tax.
		$t::money( 165, $order->get_total(), 'order total' );
		$order = $dl_finish( $order );
		$t::same( 'shipped', $order->get_status(), 'paid and complete' );
	}
);

$dl_test(
	'Order: a gift card pays for all the downloads',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo, $dl_snapshot, $dl_order_matches_cart, $dl_finish ) {
		$t::tax( 10 );
		$gallery  = $dl_gallery( 2 );
		$download = $dl_product( 25 );
		$dl_add_photo( $download, $gallery['photos'][0] );
		$dl_add_photo( $download, $gallery['photos'][1] );
		$t::gift_card( 100 );
		$t::checkout_address();
		$t::calculate();
		$seen  = $dl_snapshot();
		$order = $t::place_order();
		$dl_order_matches_cart( $order, $seen );
		$fees = (array) $order->get_fees();
		// 50 + 5 tax, all on the card.
		$t::money( -55, isset( $fees['gift_card'] ) ? $fees['gift_card']['amount'] : 0, 'gift card on the order' );
		$t::money( 0, $order->get_total(), 'nothing left to pay' );
		$order = $dl_finish( $order );
		$t::same( true, $order->is_paid(), 'the files can be downloaded' );
		$t::same( 'shipped', $order->get_status(), 'paid and complete' );
	}
);

// Store credit is money. Download credits are "one photo each". Paying with one must leave the
// other alone.
$dl_test(
	'Order: store credit pays part, download credits are kept apart',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo, $dl_customer_with_credits, $dl_download_credits, $dl_snapshot, $dl_order_matches_cart, $dl_finish ) {
		$customer_id = $dl_customer_with_credits( 4 );
		// What the admin customer screen's Credits box saves.
		SPC()->customer->set_credits( 15 );
		SPC()->customer = new SPC_Customer( $customer_id );
		$t::tax( 10 );
		$gallery  = $dl_gallery( 2 );
		$download = $dl_product( 10 );
		$dl_add_photo( $download, $gallery['photos'][0] );
		$dl_add_photo( $download, $gallery['photos'][1] );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::checkout_address();
		$t::calculate();
		$seen = $dl_snapshot();
		// 20 + 2 tax = 22, 15 of it from store credit.
		$t::money( 15, $seen['credits'], 'store credit used' );
		$t::money( 7, $seen['total'], 'left to pay' );
		$order = $t::place_order();
		$dl_order_matches_cart( $order, $seen );

		$order = $dl_finish( $order );
		$t::same( 0, ( new SPC_Customer( $customer_id ) )->get_credits(), 'store credit used up' );
		$t::same( 4, $dl_download_credits( $customer_id ), 'download credits untouched' );
	}
);

$dl_test(
	'Order: prices include tax with a discount on downloads',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo, $dl_snapshot, $dl_order_matches_cart ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$gallery  = $dl_gallery( 3 );
		$download = $dl_product( 12 );
		foreach ( $gallery['photos'] as $photo_id ) {
			$dl_add_photo( $download, $photo_id );
		}
		$t::discount( 'percent-total', 25 );
		$t::checkout_address();
		$t::calculate();
		$seen = $dl_snapshot();
		// 36 shown, 25% off = 9. The 27 paid includes 4.50 VAT (27 / 1.2 = 22.50).
		$t::money( 9, $seen['discount'], 'cart discount' );
		$t::money( 4.50, $seen['tax'], 'cart tax' );
		$t::money( 27, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$dl_order_matches_cart( $order, $seen );
	}
);

$dl_test(
	'Order: a quantity discount on downloads is saved on each line',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo, $dl_snapshot, $dl_order_matches_cart ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::tax( 10 );
		$gallery  = $dl_gallery( 5 );
		$download = $dl_product(
			8,
			array(
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
		$t::checkout_address();
		$t::calculate();
		$seen = $dl_snapshot();
		// 5 files at 6.00, tax 0.60 each.
		$t::money( 30, $seen['subtotal'], 'cart subtotal' );
		$t::money( 3, $seen['tax'], 'cart tax' );
		$t::money( 33, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$dl_order_matches_cart( $order, $seen );
	}
);

// The card fee is a percent of what is left to pay after the gift card, the same rule as for
// any other order (core-rounding-fees-credits).
$dl_test(
	'Order: a card fee on a download order part paid by gift card',
	function () use ( $t, $dl_gallery, $dl_product, $dl_add_photo, $dl_snapshot, $dl_order_matches_cart ) {
		$t::tax( 10 );
		$gallery  = $dl_gallery( 2 );
		$download = $dl_product( 25 );
		$dl_add_photo( $download, $gallery['photos'][0] );
		$dl_add_photo( $download, $gallery['photos'][1] );
		$t::gift_card( 20 );
		// The Test payment method's fee settings: 3% called "Card fee".
		$t::option( 'test_fee', 'percent' );
		$t::option( 'test_fee_amount', '3' );
		$t::option( 'test_fee_name', 'Card fee' );
		SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
		$t::checkout_address();
		$t::calculate();
		$seen = $dl_snapshot();
		// 50 + 5 tax = 55, 20 on the card leaves 35, 3% of 35 = 1.05.
		$t::money( 36.05, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$dl_order_matches_cart( $order, $seen );
		$fees = (array) $order->get_fees();
		$t::money( -20, isset( $fees['gift_card'] ) ? $fees['gift_card']['amount'] : 0, 'gift card on the order' );
		$t::money( 1.05, isset( $fees['payment_method'] ) ? $fees['payment_method']['amount'] : 0, 'card fee on the order' );
	}
);

/*
 * Download credits.
 */

/**
 * Open a photo's "Download with credit" link, the way the gallery page does. Stops once the
 * photo is recorded as downloaded, before any file would be sent. Returns 'downloaded' when the
 * download went ahead, 'refused' when the store said no, or 'nothing' otherwise.
 */
$dl_credit_download = function ( $photo_id ) {
	$saved_get                     = $_GET;
	$_GET['download_credit_image'] = (string) $photo_id;
	$result                        = 'nothing';
	// The download history is saved at priority 99, so stop right after it.
	$stop = function () {
		throw new SPC_Totals_Test_Done( 'downloaded' );
	};
	$die  = function () {
		return function () {
			throw new SPC_Totals_Test_Done( 'refused' );
		};
	};
	add_action( 'sunshine_download_credit_image', $stop, 100 );
	add_filter( 'wp_die_handler', $die, PHP_INT_MAX );
	try {
		sunshine_download_credit_image_process();
	} catch ( SPC_Totals_Test_Done $e ) {
		$result = $e->getMessage();
	} finally {
		$_GET = $saved_get;
		remove_action( 'sunshine_download_credit_image', $stop, 100 );
		remove_filter( 'wp_die_handler', $die, PHP_INT_MAX );
	}
	return $result;
};

$dl_history = function ( $customer_id ) {
	return array_map( 'intval', (array) get_user_meta( $customer_id, SPC()->prefix . 'download_history', true ) );
};

$dl_test(
	'Download credit: downloading a photo uses one credit',
	function () use ( $t, $dl_gallery, $dl_customer_with_credits, $dl_download_credits, $dl_credit_download, $dl_history ) {
		$t::option( 'download_credit_products', '' );
		$customer_id = $dl_customer_with_credits( 3 );
		$gallery     = $dl_gallery( 2 );
		$t::same( 'downloaded', $dl_credit_download( $gallery['photos'][0] ), 'the download goes ahead' );
		$t::same( 2, $dl_download_credits( $customer_id ), '3 - 1 credits left' );
		$t::same( true, in_array( (int) $gallery['photos'][0], $dl_history( $customer_id ), true ), 'the photo is in their downloads' );
	}
);

// A photo already paid for with a credit can be downloaded again for free, a new photo costs
// another credit.
$dl_test(
	'Download credit: the same photo again uses no credit',
	function () use ( $t, $dl_gallery, $dl_customer_with_credits, $dl_download_credits, $dl_credit_download ) {
		$t::option( 'download_credit_products', '' );
		$customer_id = $dl_customer_with_credits( 3 );
		$gallery     = $dl_gallery( 2 );
		$dl_credit_download( $gallery['photos'][0] );
		$t::same( 'downloaded', $dl_credit_download( $gallery['photos'][0] ), 'the second download goes ahead' );
		$t::same( 2, $dl_download_credits( $customer_id ), 'still 2 after the same photo again' );
		$dl_credit_download( $gallery['photos'][1] );
		$t::same( 1, $dl_download_credits( $customer_id ), '1 left after a second photo' );
	}
);

$dl_test(
	'Download credit: with no credits left nothing is downloaded',
	function () use ( $t, $dl_gallery, $dl_customer_with_credits, $dl_download_credits, $dl_credit_download, $dl_history ) {
		$t::option( 'download_credit_products', '' );
		$customer_id = $dl_customer_with_credits( 1 );
		$gallery     = $dl_gallery( 2 );
		$dl_credit_download( $gallery['photos'][0] );
		$t::same( 0, $dl_download_credits( $customer_id ), 'the last credit is used' );
		$t::same( 'refused', $dl_credit_download( $gallery['photos'][1] ), 'a new photo is refused' );
		$t::same( 0, $dl_download_credits( $customer_id ), 'credits never go below 0' );
		$t::same( false, in_array( (int) $gallery['photos'][1], $dl_history( $customer_id ), true ), 'the refused photo is not in their downloads' );
	}
);

// Changelog 3.1.3: "Don't use credits for images when the gallery allows free image downloads".
$dl_test(
	'Download credit: a photo that is free to download is offered free, not for a credit',
	function () use ( $t, $dl_gallery, $dl_customer_with_credits ) {
		$t::option( 'download_allowed_count', '' );
		$dl_customer_with_credits( 5 );
		$free_gallery = $dl_gallery( 1, '', array( 'free_downloads' => array( 'image' ) ) );
		$paid_gallery = $dl_gallery( 1 );
		$hook         = function ( $photo_id ) {
			$menu = sunshine_download_image_menu( array(), sunshine_get_image( $photo_id ) );
			return isset( $menu[17]['attr']['data-hook'] ) ? $menu[17]['attr']['data-hook'] : '';
		};
		$t::same( 'download_free_image', $hook( $free_gallery['photos'][0] ), 'free gallery: free download' );
		$t::same( 'download_credit_image', $hook( $paid_gallery['photos'][0] ), 'other gallery: download with a credit' );
	}
);

// The API's "customer download-credit balance" must show the credits the customer really has,
// the same number the admin customer screen and the customer's Downloads page show.
$dl_test(
	'Download credit: the REST API reports the credits the customer has',
	function () use ( $t, $dl_customer_with_credits, $dl_download_credits ) {
		if ( ! class_exists( 'Sunshine_REST_Controller' ) || ! function_exists( 'sunshine_downloads_register_rest_controllers' ) ) {
			$t::skip( 'Sunshine API add-on is not active' );
		}
		if ( ! class_exists( 'Sunshine_REST_Downloads_Controller' ) ) {
			// The same file the add-on loads when the API starts.
			$file = new ReflectionFunction( 'sunshine_downloads_register_rest_controllers' );
			require_once dirname( $file->getFileName() ) . '/class-rest-downloads-controller.php';
		}
		$customer_id = $dl_customer_with_credits( 7 );
		$t::same( 7, $dl_download_credits( $customer_id ), 'saved by the customer screen' );
		$t::same( '7', (string) SPC()->customer->get_meta( 'download_credits' ), 'what the Downloads page shows' );

		$controller = new Sunshine_REST_Downloads_Controller();
		$request    = new WP_REST_Request( 'GET', '/' . SUNSHINE_API_NAMESPACE . '/downloads/credits/' . $customer_id );
		$request->set_url_params( array( 'customer' => $customer_id ) );
		$data = rest_ensure_response( $controller->get_credits( $request ) )->get_data();
		$t::same( 7, isset( $data['credits'] ) ? $data['credits'] : null, 'API balance' );
	}
);

// A session fee can include download credits and store credit (Session Fees add-on, "Download
// Credits" and "Credits" in its Products list). They are given when the order is paid. Marking
// the paid order Shipped later must not give them again.
$dl_test(
	'Download credit: a session fee gives its download credits once',
	function () use ( $t, $dl_save_product, $dl_download_credits, $dl_finish, $dl_warnings ) {
		$t::require_plugin( 'sunshine_session_fees_status_change', 'Session Fees add-on' );
		$customer_id = $t::customer();
		// Saved the way the session fee's Products list saves its rows.
		$included = array(
			array(
				'product' => 'download_credits',
				'qty'     => '10',
			),
			array(
				'product' => 'credits',
				'qty'     => '25',
			),
		);
		$fee         = $dl_save_product(
			'session-fee',
			100,
			array(
				'taxable'          => '',
				'require_session'  => '',
				'session_products' => $included,
			),
			'Downloads test session fee'
		);
		// What the session fee page's Book button adds.
		$item = SPC()->cart->add_item( $fee, '', '', sunshine_get_default_price_level_id(), '', 1, '', false, array( 'session_products' => $included ) );
		$t::same( true, ! empty( $item ), 'session fee added' );
		$t::checkout_address();
		$t::calculate();
		$order    = null;
		$warnings = $dl_warnings(
			function () use ( $t, $dl_finish, &$order ) {
				$order = $t::place_order();
				$order = $dl_finish( $order );
			}
		);
		$t::same( array(), $warnings, 'no PHP warnings while placing and paying' );
		$t::money( 100, $order->get_total(), 'order total' );
		$t::same( true, $order->is_paid(), 'paid' );
		$t::same( 10, $dl_download_credits( $customer_id ), 'download credits given when paid' );
		$t::same( 25, ( new SPC_Customer( $customer_id ) )->get_credits(), 'store credit given when paid' );

		// The photographer marks the paid order as done, the way the order screen does.
		$order->set_status( 'shipped' );
		$t::same( 10, $dl_download_credits( $customer_id ), 'still 10 download credits after marking it shipped' );
		$t::same( 25, ( new SPC_Customer( $customer_id ) )->get_credits(), 'still 25 store credit after marking it shipped' );
	}
);

// A customer can't spend a credit on a photo in a gallery they are not allowed to see.
$dl_test(
	'Download credit: a private gallery the customer is not on keeps their credit',
	function () use ( $t, $dl_gallery, $dl_customer_with_credits, $dl_download_credits, $dl_credit_download ) {
		$t::option( 'download_credit_products', '' );
		$customer_id = $dl_customer_with_credits( 2 );
		$gallery     = $dl_gallery(
			1,
			'',
			array(
				'status'        => 'private',
				'private_users' => array(),
			)
		);
		$t::same( 'refused', $dl_credit_download( $gallery['photos'][0] ), 'the download is refused' );
		$t::same( 2, $dl_download_credits( $customer_id ), 'no credit used' );
	}
);
