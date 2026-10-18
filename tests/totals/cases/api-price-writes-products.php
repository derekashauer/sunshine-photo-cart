<?php
/**
 * API add-on: product prices and product option sets changed through the REST API
 * (sunshine/v1/products, /products/batch, /product-options) and the AI tools.
 *
 * A product keeps one price per price level ("price" is level id => price). The rules used here:
 * - Changing one level's price leaves the other levels alone.
 * - A price is never below zero.
 * - Sending an option set back unchanged (read it, save it again) changes nothing a customer pays.
 */

$t = 'SPC_Totals_Test';
if ( ! isset( $apw_test ) ) {
	require __DIR__ . '/api-price-writes-helpers.php';
}

$apw_test(
	'Product update: a new price at a level is what the cart and order charge',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_price, $apw_buy ) {
		$level = $apw_level( 'product' );
		$a     = $apw_product( array( $level => '20' ), array( 'taxable' => 1 ) );
		$t::tax( 10 );

		// Read the product and send its price map back with one price changed, the careful way.
		list( , $data )     = $apw_rest( 'GET', '/products/' . $a );
		$prices             = isset( $data['price'] ) ? (array) $data['price'] : array();
		$prices[ $level ]   = 24.5;
		list( $status )     = $apw_rest( 'PUT', '/products/' . $a, array( 'price' => $prices ) );
		$t::same( 200, $status, 'update accepted' );
		$t::money( 24.50, $apw_price( $a, $level ), 'new price' );

		$apw_buy( $a, $level, 2 );
		$cart = $t::calculate();
		// 2 x 24.50 = 49.00, tax 10% = 4.90.
		$t::money( 4.90, $cart->get_tax(), 'cart tax' );
		$t::money( 53.90, $cart->get_total(), 'cart total' );
		$order = $t::place_order();
		$t::money( 49, $order->get_subtotal(), 'order subtotal' );
		$t::money( 4.90, $order->get_tax(), 'order tax' );
		$t::money( 53.90, $order->get_total(), 'order total' );
	}
);

// The AI tool's description says "Send only the fields you want to change." An AI helper asked
// to change the Wedding price sends the Wedding price. Assumption: the product keeps its prices
// at the levels that were not named, instead of being taken off sale at every other level.
$apw_test(
	'Product update: changing the price at one level keeps the other levels',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_price ) {
		$level          = $apw_level( 'onelevel' );
		$default        = sunshine_get_default_price_level_id();
		$a              = $apw_product( array( $default => '20', $level => '30' ) );
		list( $status ) = $apw_rest( 'PUT', '/products/' . $a, array( 'price' => array( $level => 35 ) ) );
		$t::same( 200, $status, 'update accepted' );
		$t::money( 35, $apw_price( $a, $level ), 'named level has the new price' );
		$t::money( 20, $apw_price( $a, $default ), 'the level that was not named keeps its price' );
	}
);

$apw_test(
	'Product update: a negative price is refused',
	function () use ( $t, $apw_rest, $apw_refused, $apw_level, $apw_product, $apw_price, $apw_buy ) {
		$level          = $apw_level( 'negprice' );
		$a              = $apw_product( array( $level => '20' ) );
		list( $status ) = $apw_rest( 'PUT', '/products/' . $a, array( 'price' => array( $level => -5 ) ) );
		$t::same( true, $apw_refused( $status ), 'refused as a bad request (got ' . $status . ')' );
		$t::money( 20, $apw_price( $a, $level ), 'price stays 20' );
		$apw_buy( $a, $level, 1 );
		$t::money( 20, $t::calculate()->get_total(), 'the customer still pays 20' );
	}
);

// The update-product AI tool takes `price` as one number plus `price_level` as the level id.
$apw_test(
	'Product update through the AI tool: price and price_level set that level\'s price',
	function () use ( $t, $apw_tool, $apw_level, $apw_product, $apw_price ) {
		$level  = $apw_level( 'tool' );
		$a      = $apw_product( array( $level => '20' ) );
		$result = $apw_tool(
			'sunshine/update-product',
			array(
				'id'          => $a,
				'price'       => 24,
				'price_level' => $level,
			)
		);
		$t::same( '', is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '', 'tool error' );
		$t::money( 24, $apw_price( $a, $level ), 'price at the level' );
	}
);

// Bulk updates skip the checks a single update gets. A single price sent in a bulk update must
// not wipe out the product's price list. Either the update is refused (prices kept) or the
// price lands on the default level; the product's other levels keep their prices either way.
$apw_test(
	'Bulk product update with a single price does not take the product off sale',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_price ) {
		$level   = $apw_level( 'batch' );
		$default = sunshine_get_default_price_level_id();
		$a       = $apw_product( array( $default => '20', $level => '30' ) );
		$apw_rest(
			'PUT',
			'/products/batch',
			array(
				'update' => array(
					array(
						'id'    => $a,
						'price' => 24,
					),
				),
			)
		);
		$at_default = $apw_price( $a, $default );
		$t::same( true, in_array( (float) $at_default, array( 20.0, 24.0 ), true ) && '' !== $at_default, 'default level price is the old 20 or the new 24 (got ' . var_export( $at_default, true ) . ')' );
		$t::money( 30, $apw_price( $a, $level ), 'other level keeps 30' );
		$t::same( true, is_array( get_post_meta( $a, 'price', true ) ), 'price is still saved as one price per level' );
	}
);

$apw_test(
	'Product made through the API with a price map: the cart charges it with tax',
	function () use ( $t, $apw_rest, $apw_level, $apw_price, $apw_buy ) {
		$level                 = $apw_level( 'create' );
		list( $status, $data ) = $apw_rest(
			'POST',
			'/products',
			array(
				'title'   => 'API writes made product',
				'status'  => 'publish',
				'type'    => 'print',
				'taxable' => true,
				'price'   => array( $level => 12.5 ),
			)
		);
		if ( ! empty( $data['id'] ) ) {
			$t::track( 'post', (int) $data['id'] );
		}
		$t::same( 201, $status, 'product made' );
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$t::money( 12.50, $apw_price( $id, $level ), 'price at the level' );
		$t::tax( 10 );
		$apw_buy( $id, $level, 3 );
		$cart = $t::calculate();
		// 3 x 12.50 = 37.50, tax 10% = 3.75.
		$t::money( 3.75, $cart->get_tax(), 'tax' );
		$t::money( 41.25, $cart->get_total(), 'total' );
	}
);

/*
 * Option sets. Products > Product Options offers three kinds: "Select one of many", "Single
 * checkbox (Yes/No)" and "Text field". A checkbox's price is saved on the product as
 * option id => level => price; a select's as option id => 'items' => item id => level => price.
 */

// Reading an option set and saving it back unchanged is what an AI helper does to edit one
// field (here the name). It must not change what a customer pays for the option.
$apw_test(
	'Option set saved back unchanged: a checkbox stays a checkbox and keeps its price',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_option, $apw_option_prices, $apw_buy ) {
		$level = $apw_level( 'checkbox' );
		$rush  = $apw_option( 'checkbox' );
		$a     = $apw_product( array( $level => '20' ) );
		$apw_option_prices( $a, array( $rush => array( $level => '5' ) ) );

		list( , $data )     = $apw_rest( 'GET', '/product-options/' . $rush );
		$t::same( 'checkbox', isset( $data['type'] ) ? $data['type'] : null, 'type read back' );
		list( $status )     = $apw_rest(
			'PUT',
			'/product-options/' . $rush,
			array(
				'name'    => 'Rush printing',
				'type'    => isset( $data['type'] ) ? $data['type'] : '',
				'options' => isset( $data['options'] ) ? $data['options'] : array(),
			)
		);
		$t::same( 200, $status, 'update accepted' );
		$t::same( 'checkbox', get_term_meta( $rush, 'type', true ), 'type after saving back' );

		// What the product window offers: the options for this level and each one's choices.
		$offered = sunshine_get_product( $a, $level )->get_options( $level );
		$items   = ( is_array( $offered ) && isset( $offered[ $rush ] ) ) ? $offered[ $rush ]->get_items() : array();
		$first   = is_array( $items ) && ! empty( $items ) ? reset( $items ) : array();
		$t::money( 5, isset( $first['price'] ) ? $first['price'] : null, 'product window offers rush printing for 5' );

		$apw_buy( $a, $level, 1, array( $rush => '1' ) );
		$cart = $t::calculate();
		$t::money( 25, $cart->get_total(), 'cart: 20 + 5 rush' );
	}
);

$apw_test(
	'Option set update: renaming a choice keeps its price on products',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_option, $apw_option_prices, $apw_buy ) {
		$level = $apw_level( 'rename' );
		$frame = $apw_option( 'select', array( 'black01' => 'Black', 'white01' => 'White' ) );
		$a     = $apw_product( array( $level => '20' ) );
		$apw_option_prices( $a, array( $frame => array( 'items' => array( 'black01' => array( $level => '15' ), 'white01' => array( $level => '10' ) ) ) ) );

		list( , $data ) = $apw_rest( 'GET', '/product-options/' . $frame );
		$choices        = isset( $data['options'] ) ? (array) $data['options'] : array();
		foreach ( $choices as $i => $choice ) {
			if ( isset( $choice['id'] ) && 'black01' === $choice['id'] ) {
				$choices[ $i ]['name'] = 'Matte black';
			}
		}
		list( $status ) = $apw_rest( 'PUT', '/product-options/' . $frame, array( 'options' => $choices ) );
		$t::same( 200, $status, 'update accepted' );

		$apw_buy( $a, $level, 2, array( $frame => 'black01' ) );
		$cart = $t::calculate();
		// (20 + 15) x 2 = 70.
		$t::money( 70, $cart->get_total(), 'cart total' );
	}
);

foreach ( $apw_warnings as $apw_name => $apw_list ) {
	WP_CLI::log( 'WARN ' . $apw_name . ': ' . implode( ' | ', array_unique( $apw_list ) ) );
}
$apw_warnings = array();
