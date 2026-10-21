<?php
/**
 * WHCC add-on: placed orders.
 *
 * A placed order must match what the customer saw in the cart. The order also keeps the WHCC
 * shipping method and each package's price, and each line keeps WHCC's cost so profit is right.
 * Once the order is paid it is sent to WHCC with the same shipping the customer paid for
 * (method, signature), and the order records what WHCC charges for that shipping.
 *
 * Orders are placed through the real checkout step with the Test payment method, then marked
 * paid the way a payment does it. Nothing reaches WHCC: a made-up WHCC answers.
 * Setup is in addon-whcc-helpers.php.
 */

require_once __DIR__ . '/addon-whcc-helpers.php';

$t = 'SPC_Totals_Test';

// The WHCC attribute numbers sent with each package, keyed by the editors in it.
$whcc_sent_attributes = function () use ( $whcc_sent ) {
	$sent  = $whcc_sent( 'POST', 'oas/orders/create' );
	$found = array();
	if ( empty( $sent[0]['Orders'] ) ) {
		return $found;
	}
	foreach ( $sent[0]['Orders'] as $sub_order ) {
		$editors = array();
		foreach ( (array) $sub_order['OrderItems'] as $order_item ) {
			$editors[] = $order_item['EditorId'];
		}
		$uids = array();
		foreach ( (array) ( isset( $sub_order['OrderAttributes'] ) ? $sub_order['OrderAttributes'] : array() ) as $attribute ) {
			$uids[] = $attribute['AttributeUID'];
		}
		$found[ implode( ',', $editors ) ] = $uids;
	}
	return $found;
};

// The packages saved on the order, as group => customer price and group => WHCC cost.
$whcc_packages = function ( $order ) {
	$packages = sunshine_get_order( $order->get_id() )->get_meta_value( 'whcc_shipping_packages' );
	$prices   = array();
	$costs    = array();
	foreach ( (array) $packages as $package ) {
		$group            = $package['group'];
		$prices[ $group ] = isset( $prices[ $group ] ) ? $prices[ $group ] : 0;
		$prices[ $group ] = isset( $package['price'] ) ? round( $prices[ $group ] + (float) $package['price'], 2 ) : $prices[ $group ];
		if ( isset( $package['lab_cost'] ) ) {
			$costs[ $group ] = round( ( isset( $costs[ $group ] ) ? $costs[ $group ] : 0 ) + (float) $package['lab_cost'], 2 );
		}
	}
	ksort( $prices );
	ksort( $costs );
	return array(
		'prices' => $prices,
		'costs'  => $costs,
		'count'  => count( (array) $packages ),
	);
};

// Mark the order paid, the way a finished payment does, and read it back.
$whcc_pay = function ( $order ) {
	$order->set_status( 'new' );
	return sunshine_get_order( $order->get_id() );
};

$whcc_test(
	'order: the placed order matches the cart',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_cart_snapshot, $whcc_order_matches_cart, $whcc_packages ) {
		$t::tax( 10 );
		$t::option( 'whcc_shipping_prices', array( 'everything_else' => array( 'lowest_cost' => 10.0 ) ) );
		$editor = $whcc_editor( $whcc_product( 'print' ), 3, 9.00, 6.00 );
		$whcc_add_from_editor( $editor );
		$whcc_ship_setup( array( 'lowest_cost' ), true );
		$whcc_ship_with( 'lowest_cost' );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		// 27.00 - 2.70 = 24.30, tax 2.43 + 1.00 on the 10.00 shipping.
		$t::money( 37.73, $cart->get_total(), 'cart total: 24.30 + 10.00 + 3.43' );
		$seen  = $whcc_cart_snapshot();
		$order = $t::place_order();
		$whcc_order_matches_cart( $order, $seen );

		$items = array_values( (array) $order->get_items() );
		$t::same( $editor, isset( $items[0] ) ? $items[0]->get_meta_value( 'whcc_editor_id' ) : null, 'the line keeps its WHCC editor' );
		$t::money( 6.00, isset( $items[0] ) ? $items[0]->get_meta_value( 'cost' ) : null, "the line's cost is WHCC's cost for one" );
		$t::same( 'lowest_cost', sunshine_get_order( $order->get_id() )->get_meta_value( 'whcc_shipping_method' ), 'WHCC shipping method' );
		$packages = $whcc_packages( $order );
		$t::same( array( 'everything_else' => 10.0 ), $packages['prices'], 'one package at the price charged' );
	}
);

$whcc_test(
	'order: prices include tax',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_cart_snapshot, $whcc_order_matches_cart ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::option( 'whcc_shipping_prices', array( 'everything_else' => array( 'lowest_cost' => 11.0 ) ) );
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print' ), 3, 11.00, 6.00 ) );
		$whcc_ship_setup( array( 'lowest_cost' ), true );
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::money( 44.00, $cart->get_total(), 'cart total: 33.00 + 11.00, tax inside' );
		$seen  = $whcc_cart_snapshot();
		$order = $t::place_order();
		$whcc_order_matches_cart( $order, $seen );
	}
);

$whcc_test(
	'order: each package and its price are saved',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_packages ) {
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 ) );
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'wrap', null, false ), 1, 80.00, 50.00 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'expedited' );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 20.64, $order->get_shipping(), 'shipping: 9.14 + 11.50' );
		$t::money( 127.64, $order->get_total(), 'total' );
		$t::same( 'expedited', sunshine_get_order( $order->get_id() )->get_meta_value( 'whcc_shipping_method' ), 'WHCC shipping method' );
		$packages = $whcc_packages( $order );
		$t::same(
			array(
				'everything_else' => 11.5,
				'wall_art'        => 9.14,
			),
			$packages['prices'],
			'packages and prices'
		);
	}
);

// An album and prints, Lowest Cost. WHCC's "Lowest Cost" for albums is attribute 554, for
// everything else 553, and 548 (drop ship to the customer) has to come with either.
$whcc_test(
	'order: a paid order is sent to WHCC with the shipping the customer paid for',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_sent, $whcc_sent_attributes, $whcc_packages ) {
		$album_editor = $whcc_editor( $whcc_product( 'album', null, false ), 1, 400.00, 250.00 );
		$print_editor = $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 );
		$whcc_add_from_editor( $album_editor );
		$whcc_add_from_editor( $print_editor );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::money( 15.90, $cart->get_shipping(), 'customer pays 7.95 for each package' );
		$order = $whcc_pay( $t::place_order() );

		$t::same( 'submitted', $order->get_meta_value( 'whcc_order_status' ), 'sent to WHCC' );
		$t::same( 1, $whcc_lab['confirmed'], 'and confirmed' );
		$attributes = $whcc_sent_attributes();
		$t::same( array( 548, 554 ), isset( $attributes[ $album_editor ] ) ? $attributes[ $album_editor ] : null, 'album package shipping' );
		$t::same( array( 548, 553 ), isset( $attributes[ $print_editor ] ) ? $attributes[ $print_editor ] : null, 'print package shipping' );
		$t::money( 15.90, $order->get_meta_value( 'whcc_shipping_cost' ), 'what WHCC charges for shipping' );
		$packages = $whcc_packages( $order );
		$t::same(
			array(
				'albums'          => 7.95,
				'everything_else' => 7.95,
			),
			$packages['costs'],
			"WHCC's cost for each package"
		);
		$sent = $whcc_sent( 'POST', 'oas/orders/create' );
		$t::same( (string) $order->get_id(), isset( $sent[0]['Orders'][0]['Reference'] ) ? (string) $sent[0]['Orders'][0]['Reference'] : null, 'the order number goes to WHCC' );
		$t::same( 'CO', isset( $sent[0]['Orders'][0]['ShipToAddress']['State'] ) ? $sent[0]['Orders'][0]['ShipToAddress']['State'] : null, "shipped to the customer's address" );
	}
);

$whcc_test(
	'order: priority one-day with signature is ordered from WHCC that way',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_sent_attributes ) {
		$t::option( 'whcc_require_signature', 'yes' );
		$editor = $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 );
		$whcc_add_from_editor( $editor );
		$whcc_ship_setup();
		$whcc_ship_with( 'one_day_priority' );
		$cart = $t::calculate();
		$t::money( 29.38, $cart->get_shipping(), 'customer pays (7.95 + 6.95 + 10.00) + 18%' );
		$order      = $whcc_pay( $t::place_order() );
		$attributes = $whcc_sent_attributes();
		$t::same( array( 548, 556, 3145 ), isset( $attributes[ $editor ] ) ? $attributes[ $editor ] : null, 'priority one-day with signature' );
		$t::same( 'yes', $order->get_meta_value( 'whcc_signature_required' ), 'signature noted on the order' );
		$t::money( 29.38, $order->get_meta_value( 'whcc_shipping_cost' ), 'what WHCC charges' );
	}
);

// The signature is decided from the order amount at checkout and again when the order is
// sent. Both must agree, or the customer pays for a signature that WHCC never gets (or the
// photographer pays for one the customer did not).
$whcc_test(
	'order: a signature charged for the order amount is ordered from WHCC',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_sent_attributes ) {
		$t::option( 'whcc_signature_threshold', '50' );
		$product = $whcc_product( 'print', null, false );
		$first   = $whcc_editor( $product, 3, 9.00, 6.00 );
		$second  = $whcc_editor( $product, 3, 9.00, 6.00 );
		$whcc_add_from_editor( $first );
		$whcc_add_from_editor( $second );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::money( 17.95, $cart->get_shipping(), 'a 54.00 order pays 7.95 + 10.00 signature' );
		$order      = $whcc_pay( $t::place_order() );
		$attributes = $whcc_sent_attributes();
		$t::same( array( 548, 553, 3145 ), isset( $attributes[ $first . ',' . $second ] ) ? $attributes[ $first . ',' . $second ] : null, 'signature ordered from WHCC' );
		$t::same( 'yes', $order->get_meta_value( 'whcc_signature_required' ), 'signature noted on the order' );
		$t::money( 17.95, $order->get_meta_value( 'whcc_shipping_cost' ), 'what WHCC charges' );
	}
);

$whcc_test(
	'order: the photographer\'s shipping price and WHCC\'s cost are both kept',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_packages ) {
		$t::option( 'whcc_shipping_prices', array( 'everything_else' => array( 'lowest_cost' => 13.0 ) ) );
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$order = $whcc_pay( $t::place_order() );
		$t::money( 13.00, $order->get_shipping(), 'customer paid 13.00' );
		$t::money( 7.95, $order->get_meta_value( 'whcc_shipping_cost' ), 'WHCC charges 7.95' );
		$packages = $whcc_packages( $order );
		$t::same( array( 'everything_else' => 13.0 ), $packages['prices'], 'package price' );
		$t::same( array( 'everything_else' => 7.95 ), $packages['costs'], 'package cost' );
	}
);

// The order's WHCC tab has a "Resubmit to WHCC" button. Sending it again must leave one set of
// packages and WHCC's shipping cost counted once.
$whcc_test(
	'order: sending an order to WHCC again counts WHCC\'s shipping once',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_packages, $whcc_ajax ) {
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 ) );
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'wrap', null, false ), 1, 80.00, 50.00 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$order = $whcc_pay( $t::place_order() );
		$t::money( 15.90, $order->get_meta_value( 'whcc_shipping_cost' ), 'first send: 7.95 x 2' );

		$t::customer();
		$can = function ( $allcaps ) {
			$allcaps['sunshine_manage_options'] = true;
			return $allcaps;
		};
		add_filter( 'user_has_cap', $can );
		$_REQUEST['security'] = wp_create_nonce( 'sunshine_admin' );
		$_POST['order_id']    = (string) $order->get_id();
		try {
			$reply = $whcc_ajax( 'sunshine_whcc_ajax_submit_order' );
		} finally {
			remove_filter( 'user_has_cap', $can );
			unset( $_REQUEST['security'], $_POST['order_id'] );
		}
		$t::same( true, ! empty( $reply['success'] ), 'sent again' );
		$t::same( 2, $whcc_lab['confirmed'], 'confirmed again' );
		$order = sunshine_get_order( $order->get_id() );
		$t::money( 15.90, $order->get_meta_value( 'whcc_shipping_cost' ), "WHCC's shipping is still 15.90" );
		$packages = $whcc_packages( $order );
		$t::same( 2, $packages['count'], 'still two packages' );
		$t::same(
			array(
				'everything_else' => 7.95,
				'wall_art'        => 7.95,
			),
			$packages['costs'],
			"WHCC's cost for each package"
		);
	}
);

// WHCC only ships its own package. The surcharge pays for the photographer's own shipping,
// so it is not part of what WHCC charges.
$whcc_test(
	'order: the mixed cart surcharge is kept apart from WHCC\'s cost',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_pay, $whcc_packages ) {
		$t::option( 'whcc_mixed_cart_surcharge', '6.00' );
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 ) );
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$order = $whcc_pay( $t::place_order() );
		$t::money( 13.95, $order->get_shipping(), 'customer paid 7.95 + 6.00' );
		$t::money( 60.95, $order->get_total(), 'total' );
		$t::money( 7.95, $order->get_meta_value( 'whcc_shipping_cost' ), 'WHCC charges for its one package' );
		$packages = $whcc_packages( $order );
		$t::same(
			array(
				'everything_else' => 7.95,
				'mixed_cart'      => 6.0,
			),
			$packages['prices'],
			'package prices'
		);
		$t::same( array( 'everything_else' => 7.95 ), $packages['costs'], "WHCC's cost is only on WHCC's package" );
	}
);

// Profit is what the customer paid for the items, less discounts, less WHCC's cost of what
// was actually made (WHCC's cost for one x quantity).
$whcc_test(
	'order: profit uses WHCC\'s cost for what was made',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_pay ) {
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 ) );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::discount( 'percent-total', 10 );
		$t::calculate();
		$order = $whcc_pay( $t::place_order() );
		// 27.00 - 2.70 discount - 18.00 WHCC cost.
		$t::money( 6.30, $order->get_profit(), 'profit' );
	}
);

// The customer opens a cart item in WHCC's editor again and changes it (here 3 small prints
// become 2 large canvases). The cart must take WHCC's new price, quantity and cost.
$whcc_test(
	'editing an item in WHCC\'s editor updates its price, quantity and WHCC\'s cost',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_line, $whcc_ship_setup, $whcc_ship_with, $whcc_pay ) {
		$editor = $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 );
		$item   = $whcc_add_from_editor( $editor );

		// Now 2 x 16x20, 60.00 each, WHCC's cost 40.00 each.
		$whcc_lab['editors'][ $editor ]['qty']     = 2;
		$whcc_lab['editors'][ $editor ]['unit']    = 60.00;
		$whcc_lab['editors'][ $editor ]['base']    = 40.00;
		$whcc_lab['editors'][ $editor ]['summary'] = array(
			array(
				'name'        => 'size',
				'description' => '16x20',
			),
		);
		$_GET['whcc_updated_item'] = $item ? $item['hash'] : '';
		$redirect                  = function () {
			throw new Exception( 'spc-whcc-redirect' );
		};
		add_filter( 'wp_redirect', $redirect, -1000 );
		try {
			sunshine_whcc_updated_editor();
		} catch ( Exception $e ) {
			if ( 'spc-whcc-redirect' !== $e->getMessage() ) {
				throw $e;
			}
		} finally {
			remove_filter( 'wp_redirect', $redirect, -1000 );
			unset( $_GET['whcc_updated_item'] );
		}

		$cart = $t::new_request();
		$line = $whcc_line( $editor );
		$t::same( 2, $line ? $line->get_qty() : null, 'quantity' );
		$t::money( 60.00, $line ? $line->get_price() : null, 'price for one' );
		$t::money( 120.00, $cart->get_total(), 'total' );
		$selections = $line ? $line->get_meta_value( 'whcc_selections' ) : array();
		$t::same( '16x20', isset( $selections[0]['description'] ) ? $selections[0]['description'] : null, 'the new size is shown' );
		$t::money( 40.00, $line ? $line->get_meta_value( 'whcc_unit_cost' ) : null, "WHCC's cost for one is the new item's" );

		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::calculate();
		$order = $whcc_pay( $t::place_order() );
		// 120.00 - 2 x 40.00.
		$t::money( 40.00, $order->get_profit(), 'profit' );
	}
);

// A percent payment fee is on everything the customer pays, WHCC shipping included.
$whcc_test(
	'order: a percent payment fee',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_cart_snapshot, $whcc_order_matches_cart ) {
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 ) );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::option( 'test_fee', 'percent' );
		$t::option( 'test_fee_amount', '3' );
		$t::option( 'test_fee_name', 'Card fee' );
		SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
		$cart = $t::calculate();
		// 3% of 34.95 = 1.0485, so 1.05.
		$t::money( 36.00, $cart->get_total(), '27.00 + 7.95 + 1.05' );
		$seen  = $whcc_cart_snapshot();
		$order = $t::place_order();
		$whcc_order_matches_cart( $order, $seen );
		$fees = (array) $order->get_fees();
		$t::money( 1.05, isset( $fees['payment_method'] ) ? $fees['payment_method']['amount'] : 0, 'fee on the order' );
	}
);

$whcc_test(
	'order: paid in full with a gift card is still sent to WHCC',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_pay ) {
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 ) );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::gift_card( 50 );
		$t::calculate();
		$order = $whcc_pay( $t::place_order() );
		$t::money( 0, $order->get_total(), 'nothing left to pay' );
		$fees = (array) $order->get_fees();
		$t::money( -34.95, isset( $fees['gift_card'] ) ? $fees['gift_card']['amount'] : 0, 'gift card paid 27.00 + 7.95' );
		$t::same( 'submitted', $order->get_meta_value( 'whcc_order_status' ), 'sent to WHCC' );
		$t::money( 7.95, $order->get_meta_value( 'whcc_shipping_cost' ), 'what WHCC charges for shipping' );
	}
);
