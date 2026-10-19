<?php
/**
 * API add-on: changing prices at a price level through the REST API and the AI tools.
 *
 *   POST /products/price-levels/{id}/scale   raise or lower every price at a level by a percent
 *   POST /products/price-levels/{id}/apply   set one price (or one per product) at a level
 *   POST /products/price-levels              make a new level, optionally copied from another
 *
 * One call changes many products at once, so a bad call can reprice a whole store. The rules
 * used here are the ones a photographer would expect:
 * - A price cut never makes a product free or priced below zero by accident.
 * - "Raise everything at this level by 10%" raises everything a customer pays at that level.
 * - A blank price means "not sold at this level", the same as on the product screen.
 * - A price typed in the store's own number format keeps its cents.
 *
 * Every call here names only the products the test made, or a price level only those products
 * use, so no other product on the site can change.
 */

$t = 'SPC_Totals_Test';
if ( ! isset( $apw_test ) ) {
	require __DIR__ . '/api-price-writes-helpers.php';
}

$apw_test(
	'Scale: +10% raises each price at the level, and the cart and order charge the new price',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_price, $apw_buy ) {
		$level   = $apw_level( 'scale' );
		$default = sunshine_get_default_price_level_id();
		$a       = $apw_product( array( $level => '20' ) );
		$b       = $apw_product( array( $level => '18.50', $default => '30' ) );

		list( $status ) = $apw_rest(
			'POST',
			'/products/price-levels/' . $level . '/scale',
			array(
				'percent'  => 10,
				'products' => array( $a, $b ),
			)
		);
		$t::same( 200, $status, 'scale accepted' );
		$t::money( 22, $apw_price( $a, $level ), 'A: 20 x 1.10' );
		$t::money( 20.35, $apw_price( $b, $level ), 'B: 18.50 x 1.10' );
		$t::money( 30, $apw_price( $b, $default ), 'B at another level is not touched' );
		$t::same( '', $apw_price( $a, $default ), 'A is still not sold at a level it had no price for' );

		$apw_buy( $a, $level, 2 );
		$apw_buy( $b, $level, 1 );
		$cart = $t::calculate();
		// 2 x 22 + 20.35 = 64.35.
		$t::money( 64.35, $cart->get_total(), 'cart total' );
		$order = $t::place_order();
		$t::money( 64.35, $order->get_subtotal(), 'order subtotal' );
		$t::money( 64.35, $order->get_total(), 'order total' );
	}
);

$apw_test(
	'Scale: each new price is rounded to the cent',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_price ) {
		$level = $apw_level( 'round' );
		$a     = $apw_product( array( $level => '9.99' ) );
		$b     = $apw_product( array( $level => '33.33' ) );
		$apw_rest( 'POST', '/products/price-levels/' . $level . '/scale', array( 'percent' => 15, 'products' => array( $a ) ) );
		$apw_rest( 'POST', '/products/price-levels/' . $level . '/scale', array( 'percent' => -10, 'products' => array( $b ) ) );
		// 9.99 x 1.15 = 11.4885, rounds to 11.49. 33.33 x 0.90 = 29.997, rounds to 30.00.
		$t::money( 11.49, $apw_price( $a, $level ), 'A: 9.99 + 15%' );
		$t::money( 30, $apw_price( $b, $level ), 'B: 33.33 - 10%' );
	}
);

// When prices include tax, the saved price is what the customer pays, tax and all. A 10% rise
// raises that amount by 10%, and the tax inside it goes up with it.
$apw_test(
	'Scale: with prices including tax, the customer pays the scaled price and the order matches',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_price, $apw_buy ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$level = $apw_level( 'withtax' );
		$a     = $apw_product( array( $level => '22' ), array( 'taxable' => 1 ) );
		$apw_rest( 'POST', '/products/price-levels/' . $level . '/scale', array( 'percent' => 10, 'products' => array( $a ) ) );
		$t::money( 24.20, $apw_price( $a, $level ), 'new price: 22 x 1.10' );

		$apw_buy( $a, $level, 3 );
		$cart = $t::calculate();
		// 3 x 24.20 = 72.60 with tax. Tax inside it: 72.60 / 1.10 x 0.10 = 6.60.
		$t::money( 72.60, $cart->get_total(), 'cart total' );
		$t::money( 6.60, $cart->get_tax(), 'tax inside the total' );
		$order = $t::place_order();
		$t::money( 72.60, $order->get_total(), 'order total' );
		$t::money( 6.60, $order->get_tax(), 'order tax' );
	}
);

// Assumption: a cut of 100% or more is a mistake (a typo, or "-100" meant as "100 less"), not
// a request to give the whole level away. The API should refuse it and leave prices alone.
$apw_test(
	'Scale: -100% is refused, so products never become free',
	function () use ( $t, $apw_rest, $apw_refused, $apw_level, $apw_product, $apw_price, $apw_buy ) {
		$level          = $apw_level( 'minus100' );
		$a              = $apw_product( array( $level => '20' ) );
		list( $status ) = $apw_rest( 'POST', '/products/price-levels/' . $level . '/scale', array( 'percent' => -100, 'products' => array( $a ) ) );
		$t::same( true, $apw_refused( $status ), 'refused as a bad request (got ' . $status . ')' );
		$t::money( 20, $apw_price( $a, $level ), 'price stays 20' );
		$apw_buy( $a, $level, 1 );
		$t::money( 20, $t::calculate()->get_total(), 'the customer still pays 20' );
	}
);

$apw_test(
	'Scale: a cut bigger than 100% never saves a price below zero',
	function () use ( $t, $apw_rest, $apw_refused, $apw_level, $apw_product, $apw_price, $apw_buy ) {
		$level          = $apw_level( 'minus150' );
		$a              = $apw_product( array( $level => '20' ) );
		list( $status ) = $apw_rest( 'POST', '/products/price-levels/' . $level . '/scale', array( 'percent' => -150, 'products' => array( $a ) ) );
		$t::same( true, $apw_refused( $status ), 'refused as a bad request (got ' . $status . ')' );
		$t::same( true, (float) $apw_price( $a, $level ) >= 0, 'price is not below zero (got ' . var_export( $apw_price( $a, $level ), true ) . ')' );
		$t::money( 20, $apw_price( $a, $level ), 'price stays 20' );
		$apw_buy( $a, $level, 1 );
		$t::money( 20, $t::calculate()->get_total(), 'the customer still pays 20' );
	}
);

// In a store set to show 18,50 the product screen saves the price as typed, "18,50", and the
// store reads it as 18.50. A 10% rise must start from 18.50, not from 18.
$apw_test(
	'Scale: in a decimal-comma store, prices keep their cents',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_price, $apw_buy ) {
		$t::option( 'currency_decimal_separator', ',' );
		$t::option( 'currency_thousands_separator', '.' );
		$level = $apw_level( 'comma' );
		$a     = $apw_product( array( $level => '18,50' ) );
		$t::money( 18.50, $apw_price( $a, $level ), 'the store reads the saved price as 18.50' );

		list( $status, $data ) = $apw_rest( 'POST', '/products/price-levels/' . $level . '/scale', array( 'percent' => 10, 'products' => array( $a ) ) );
		$t::same( 200, $status, 'scale accepted' );
		$old = isset( $data['updated'][0]['old_price'] ) ? $data['updated'][0]['old_price'] : null;
		$t::money( 18.50, $old, 'old price the API reports' );
		// 18.50 x 1.10 = 20.35.
		$t::money( 20.35, $apw_price( $a, $level ), 'new price' );
		$apw_buy( $a, $level, 1 );
		$t::money( 20.35, $t::calculate()->get_total(), 'cart total' );
	}
);

// Assumption: "raise everything at this level by 10%" includes the option prices at that level
// (frames, rush printing), because they are part of what the customer pays at that level. The
// Price Levels add-on's own "Base price off" copy applies its percent to option prices too.
$apw_test(
	'Scale: option prices at the level go up with the product price',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_price, $apw_option, $apw_option_prices, $apw_buy ) {
		$level = $apw_level( 'options' );
		$frame = $apw_option( 'select', array( 'black01' => 'Black' ) );
		$rush  = $apw_option( 'checkbox' );
		$a     = $apw_product( array( $level => '20' ) );
		$apw_option_prices(
			$a,
			array(
				$frame => array( 'items' => array( 'black01' => array( $level => '20' ) ) ),
				$rush  => array( $level => '5' ),
			)
		);
		$apw_rest( 'POST', '/products/price-levels/' . $level . '/scale', array( 'percent' => 10, 'products' => array( $a ) ) );
		$t::money( 22, $apw_price( $a, $level ), 'product: 20 x 1.10' );

		$apw_buy( $a, $level, 1, array( $frame => 'black01', $rush => '1' ) );
		$cart = $t::calculate();
		// 22 product + 22 frame (20 x 1.10) + 5.50 rush (5 x 1.10) = 49.50.
		$t::money( 49.50, $cart->get_total(), 'cart total with both options' );
	}
);

$apw_test(
	'Scale: Extra Shipping Cost is scaled when asked, and only then',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_price, $apw_shipping ) {
		$level = $apw_level( 'shipping' );
		$a     = $apw_product( array( $level => '20' ), array( 'shipping' => array( $level => '2' ) ) );
		$b     = $apw_product( array( $level => '20' ), array( 'shipping' => array( $level => '2' ) ) );
		$apw_rest(
			'POST',
			'/products/price-levels/' . $level . '/scale',
			array(
				'percent'  => 10,
				'products' => array( $a ),
				'fields'   => array( 'price', 'shipping' ),
			)
		);
		$apw_rest( 'POST', '/products/price-levels/' . $level . '/scale', array( 'percent' => 10, 'products' => array( $b ) ) );
		$t::money( 22, $apw_price( $a, $level ), 'A price: 20 x 1.10' );
		$t::money( 2.20, $apw_shipping( $a, $level ), 'A shipping: 2 x 1.10' );
		$t::money( 22, $apw_price( $b, $level ), 'B price: 20 x 1.10' );
		$t::money( 2, $apw_shipping( $b, $level ), 'B shipping not asked for, stays 2' );
	}
);

$apw_test(
	'Scale: a product not sold at the level stays not sold',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_price ) {
		$level                 = $apw_level( 'notsold' );
		$default               = sunshine_get_default_price_level_id();
		$a                     = $apw_product( array( $default => '20' ) );
		list( $status, $data ) = $apw_rest( 'POST', '/products/price-levels/' . $level . '/scale', array( 'percent' => 10, 'products' => array( $a ) ) );
		$t::same( 200, $status, 'scale accepted' );
		$t::same( '', $apw_price( $a, $level ), 'still not sold at this level (blank, not 0)' );
		$t::money( 20, $apw_price( $a, $default ), 'other level untouched' );
		$t::same( 1, count( isset( $data['skipped'] ) ? $data['skipped'] : array() ), 'listed as skipped' );
	}
);

// The AI tool says: "Without products[], scales every product currently assigned to the level."
// A photographer sets a product's price for a level on the product screen; that is what puts
// the product on the level. Only products this test made have a price at this new level, so
// scaling "every product at the level" here can only touch them.
$apw_test(
	'Scale: with no product list, products priced at the level on the product screen are scaled',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_price ) {
		$level                 = $apw_level( 'nolist' );
		$a                     = $apw_product( array( $level => '20' ) );
		list( $status, $data ) = $apw_rest( 'POST', '/products/price-levels/' . $level . '/scale', array( 'percent' => 10 ) );
		$t::same( 200, $status, 'scale accepted' );
		$t::money( 22, $apw_price( $a, $level ), 'A: 20 x 1.10' );
		$t::same( 1, count( isset( $data['updated'] ) ? $data['updated'] : array() ), 'one product reported as updated' );
	}
);

$apw_test(
	'Apply: one price for chosen products at a level, other levels untouched',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_price, $apw_buy ) {
		$level   = $apw_level( 'apply' );
		$default = sunshine_get_default_price_level_id();
		$a       = $apw_product( array( $level => '20' ) );
		$b       = $apw_product( array( $default => '30' ) );
		list( $status ) = $apw_rest(
			'POST',
			'/products/price-levels/' . $level . '/apply',
			array(
				'products' => array( $a, $b ),
				'price'    => 25,
			)
		);
		$t::same( 200, $status, 'apply accepted' );
		$t::money( 25, $apw_price( $a, $level ), 'A at the level' );
		$t::money( 25, $apw_price( $b, $level ), 'B at the level' );
		$t::money( 30, $apw_price( $b, $default ), 'B at its other level' );
		$t::same( '', $apw_price( $a, $default ), 'A still not sold at a level it was not sold at' );
		$apw_buy( $a, $level, 1 );
		$apw_buy( $b, $level, 1 );
		$t::money( 50, $t::calculate()->get_total(), 'cart: 25 + 25' );
	}
);

// On the product screen and the Price Levels screen, a blank price means "not sold at this
// level". An AI helper removing a product from a level sends a blank (or null) price.
// Assumption: the API means the same thing by a blank price, or refuses it. It must not make
// the product free.
$apw_test(
	'Apply: a blank or null price takes the product off the level instead of making it free',
	function () use ( $t, $apw_rest, $apw_refused, $apw_level, $apw_product, $apw_price, $apw_buy ) {
		$level = $apw_level( 'blank' );
		$a     = $apw_product( array( $level => '20' ) );
		$b     = $apw_product( array( $level => '20' ) );
		$sent  = array(
			'blank' => array( $a, '' ),
			'null'  => array( $b, null ),
		);
		foreach ( $sent as $label => $pair ) {
			list( $status ) = $apw_rest(
				'POST',
				'/products/price-levels/' . $level . '/apply',
				array(
					'products' => array( $pair[0] ),
					'price'    => $pair[1],
				)
			);
			$price = $apw_price( $pair[0], $level );
			$ok    = $apw_refused( $status ) ? ( 20.0 === (float) $price ) : ( '' === $price );
			$t::same( true, $ok, $label . ' price: refused with price kept, or not sold at the level (status ' . $status . ', price ' . var_export( $price, true ) . ')' );
		}

		// A product that is not sold at a level can't go in the cart at that level.
		$apw_buy( $a, $level, 1 );
		$apw_buy( $b, $level, 1 );
		$cart = $t::calculate();
		$free = 0;
		// An empty cart gives false, not an empty list.
		foreach ( $cart->get_cart_items() ?: array() as $line ) {
			if ( (float) $line->get_total() <= 0 ) {
				$free++;
			}
		}
		$t::same( 0, $free, 'cart lines that cost nothing' );
	}
);

$apw_test(
	'Apply: a negative price is refused',
	function () use ( $t, $apw_rest, $apw_refused, $apw_level, $apw_product, $apw_price, $apw_buy ) {
		$level          = $apw_level( 'negative' );
		$a              = $apw_product( array( $level => '20' ) );
		list( $status ) = $apw_rest(
			'POST',
			'/products/price-levels/' . $level . '/apply',
			array(
				'products' => array( $a ),
				'price'    => -5,
			)
		);
		$t::same( true, $apw_refused( $status ), 'refused as a bad request (got ' . $status . ')' );
		$t::money( 20, $apw_price( $a, $level ), 'price stays 20' );
		$apw_buy( $a, $level, 1 );
		$t::money( 20, $t::calculate()->get_total(), 'the customer still pays 20' );
	}
);

// GET /products in a decimal-comma store returns prices the way the product screen saved them
// ("18,50"). An AI helper that sends a price back in that same form must not lose the cents.
$apw_test(
	'Apply: in a decimal-comma store, a price written the store\'s way keeps its cents',
	function () use ( $t, $apw_rest, $apw_refused, $apw_level, $apw_product, $apw_price ) {
		$t::option( 'currency_decimal_separator', ',' );
		$t::option( 'currency_thousands_separator', '.' );
		$level          = $apw_level( 'applycomma' );
		$a              = $apw_product( array( $level => '20' ) );
		list( $status ) = $apw_rest(
			'POST',
			'/products/price-levels/' . $level . '/apply',
			array(
				'products' => array( $a ),
				'price'    => '18,50',
			)
		);
		$price          = $apw_price( $a, $level );
		$ok             = $apw_refused( $status ) ? ( 20.0 === (float) $price ) : ( abs( (float) $price - 18.50 ) < 0.001 );
		$t::same( true, $ok, 'price is 18.50, or the request is refused and 20 is kept (status ' . $status . ', price ' . var_export( $price, true ) . ')' );
	}
);

$apw_test(
	'Apply per product: each product gets its own price, and shipping only where sent',
	function () use ( $t, $apw_rest, $apw_level, $apw_product, $apw_price, $apw_shipping ) {
		$level = $apw_level( 'items' );
		$a     = $apw_product( array( $level => '20' ), array( 'shipping' => array( $level => '1' ) ) );
		$b     = $apw_product( array( $level => '20' ), array( 'shipping' => array( $level => '1' ) ) );
		list( $status ) = $apw_rest(
			'POST',
			'/products/price-levels/' . $level . '/apply',
			array(
				'items' => array(
					array(
						'product'  => $a,
						'price'    => 18,
						'shipping' => 2.5,
					),
					array(
						'product' => $b,
						'price'   => 25.75,
					),
				),
			)
		);
		$t::same( 200, $status, 'apply accepted' );
		$t::money( 18, $apw_price( $a, $level ), 'A price' );
		$t::money( 2.50, $apw_shipping( $a, $level ), 'A shipping' );
		$t::money( 25.75, $apw_price( $b, $level ), 'B price' );
		$t::money( 1, $apw_shipping( $b, $level ), 'B shipping not sent, stays 1' );
	}
);

/*
 * A new level copied from another one. The add-on's create hook copies prices onto every
 * product in the store, so the product list is limited to this test's own products, and the
 * test stops before the call if that limit does not hold.
 */
$apw_copy_level = function ( $product_ids, $input ) use ( $t, $apw_tool ) {
	$only = function ( $args ) use ( $product_ids ) {
		$args['post__in']    = array_map( 'intval', $product_ids );
		$args['post_status'] = 'publish';
		return $args;
	};
	add_filter( 'sunshine_get_product_args', $only, PHP_INT_MAX );
	try {
		$seen = array();
		foreach ( (array) sunshine_get_products() as $product ) {
			$seen[] = (int) $product->get_id();
		}
		sort( $seen );
		$want = array_map( 'intval', $product_ids );
		sort( $want );
		if ( $seen !== $want ) {
			$t::skip( 'could not limit the price level copy to this test\'s products' );
		}
		$result = $apw_tool( 'sunshine/create-price-level', $input );
	} finally {
		remove_filter( 'sunshine_get_product_args', $only, PHP_INT_MAX );
	}
	if ( is_array( $result ) && ! empty( $result['id'] ) ) {
		$t::track( 'term', (int) $result['id'], 'sunshine-product-price-level' );
	}
	return $result;
};

// The AI tool describes `percent` as "Percentage adjustment applied to copied prices (e.g.
// 10 = +10%, -5 = -5%)", the same meaning `percent` has on /scale. The REST code comment says
// "100 = same, 110 = +10%" instead. Assumption: one call, one meaning; the tool's description
// is what AI helpers read, so 10 means +10%.
$apw_test(
	'New level copied from another at +10%, as the AI tool describes it',
	function () use ( $t, $apw_level, $apw_product, $apw_price, $apw_option, $apw_option_prices, $apw_copy_level, $apw_buy ) {
		$source = $apw_level( 'source' );
		$rush   = $apw_option( 'checkbox' );
		$a      = $apw_product( array( $source => '20' ) );
		$apw_option_prices( $a, array( $rush => array( $source => '5' ) ) );

		$result = $apw_copy_level(
			array( $a ),
			array(
				'name'               => 'Zz API writes copy ' . wp_rand( 100000, 999999 ),
				'source_price_level' => $source,
				'percent'            => 10,
			)
		);
		$t::same( false, is_wp_error( $result ), 'level made' );
		$new = is_array( $result ) && ! empty( $result['id'] ) ? (int) $result['id'] : 0;
		// 20 + 10% = 22. Rush 5 + 10% = 5.50.
		$t::money( 22, $apw_price( $a, $new ), 'product price at the new level' );
		$apw_buy( $a, $new, 1, array( $rush => '1' ) );
		$t::money( 27.50, $t::calculate()->get_total(), 'cart: 22 + 5.50 rush' );
	}
);

// Whatever `percent` means, a copied level must never get prices below zero.
$apw_test(
	'New level copied from another at -5% never gets a price below zero',
	function () use ( $t, $apw_level, $apw_product, $apw_price, $apw_copy_level ) {
		$source = $apw_level( 'source' );
		$a      = $apw_product( array( $source => '20' ) );
		$result = $apw_copy_level(
			array( $a ),
			array(
				'name'               => 'Zz API writes copy ' . wp_rand( 100000, 999999 ),
				'source_price_level' => $source,
				'percent'            => -5,
			)
		);
		$new   = is_array( $result ) && ! empty( $result['id'] ) ? (int) $result['id'] : 0;
		$price = $new ? $apw_price( $a, $new ) : null;
		if ( is_wp_error( $result ) ) {
			// Refusing a negative percent is fine too.
			$t::same( true, true, 'refused' );
			return;
		}
		$t::same( true, '' === $price || (float) $price > 0, 'price at the new level is above zero (got ' . var_export( $price, true ) . ')' );
		// The tool's meaning: 20 - 5% = 19.
		$t::money( 19, $price, 'price at the new level' );
	}
);

foreach ( $apw_warnings as $apw_name => $apw_list ) {
	WP_CLI::log( 'WARN ' . $apw_name . ': ' . implode( ' | ', array_unique( $apw_list ) ) );
}
$apw_warnings = array();
