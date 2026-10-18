<?php
/**
 * Placed orders with a different tax rate on some products, using the snippet from the doc
 * "Charge a different tax rate on some products" (.docs/different-tax-rate-per-product.md).
 *
 * The doc promises: "Each item is saved on the order with the tax for its own rate, and the
 * order's total tax adds them together." Shipping keeps the normal rate.
 *
 * Orders are placed through the real checkout step with the Test payment method, so nothing is
 * charged anywhere. The normal rate is 20% and the snippet's rate is 5%.
 */

$t = 'SPC_Totals_Test';

// Option 1 from the doc, exactly as published: every print gets the 5% rate.
$ppt_orders_snippet_prints = function ( $tax_rate, $cart_item ) {
	if ( ! empty( $tax_rate ) && 'print' === $cart_item->get_type() ) {
		$tax_rate['rate'] = 0.05; // 5% rate.
	}
	return $tax_rate;
};

// Option 2 from the doc, exactly as published, with the test's product IDs in place of
// 123, 456, 789. The doc says to change 0.05 to the rate you want, so a test can pick another.
$ppt_orders_snippet_products = function ( $different_rate_products, $rate = 0.05 ) {
	return function ( $tax_rate, $cart_item ) use ( $different_rate_products, $rate ) {
		if ( ! empty( $tax_rate ) && in_array( $cart_item->get_product_id(), $different_rate_products ) ) {
			$tax_rate['rate'] = $rate; // 5% rate.
		}
		return $tax_rate;
	};
};

// Nothing here should talk to an outside service. If something tries, stop it and say so.
$ppt_orders_offline = function ( $pre, $args, $url ) {
	$parts = wp_parse_url( $url );
	WP_CLI::warning( 'Blocked an outgoing request during a per-product tax order test: ' . ( isset( $parts['host'] ) ? $parts['host'] : '' ) . ( isset( $parts['path'] ) ? $parts['path'] : '' ) );
	return new WP_Error( 'spc_totals_offline', 'Outgoing requests are blocked while the totals tests run.' );
};

// Snippets added by the current test. They come off when the test ends, even if it fails.
$ppt_orders_snippets = array();

$ppt_orders_use = function ( $snippet ) use ( &$ppt_orders_snippets ) {
	add_filter( 'sunshine_cart_item_tax_rate', $snippet, 10, 2 );
	$ppt_orders_snippets[] = $snippet;
};

$ppt_orders_test = function ( $name, $callback ) use ( $t, $ppt_orders_offline, &$ppt_orders_snippets ) {
	$t::test(
		$name,
		function () use ( $callback, $ppt_orders_offline, &$ppt_orders_snippets ) {
			$ppt_orders_snippets = array();
			add_filter( 'pre_http_request', $ppt_orders_offline, 1, 3 );
			try {
				$callback();
			} finally {
				foreach ( $ppt_orders_snippets as $snippet ) {
					remove_filter( 'sunshine_cart_item_tax_rate', $snippet, 10 );
				}
				$ppt_orders_snippets = array();
				remove_filter( 'pre_http_request', $ppt_orders_offline, 1 );
			}
		}
	);
};

// What the cart shows right before the customer places the order.
$ppt_orders_snapshot = function () {
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

/**
 * Check the saved order against the numbers worked out by hand: $lines holds the expected tax
 * on each order line (for the whole line), then the order's tax, shipping tax and total.
 * Also checks every saved line against what the cart showed.
 */
$ppt_orders_check = function ( $order, $seen, $lines, $tax, $shipping_tax, $total ) use ( $t ) {
	$items      = array_values( (array) $order->get_items() );
	$line_taxes = array();
	foreach ( $items as $item ) {
		$line_taxes[] = round( (float) $item->get_tax_total(), 2 );
	}
	$t::same( $lines, $line_taxes, 'tax saved on each order line' );
	$t::money( $shipping_tax, $order->get_shipping_tax(), 'order shipping tax' );
	$t::money( $tax, $order->get_tax(), 'order tax' );
	$t::money( $total, $order->get_total(), 'order total' );

	// The order's tax is the lines' tax added up, plus the shipping's tax.
	$t::money( array_sum( $line_taxes ) + (float) $order->get_shipping_tax(), $order->get_tax(), 'order tax is its lines plus shipping tax' );

	// The order matches what the customer saw in the cart.
	$t::money( $seen['subtotal'], $order->get_subtotal(), 'order subtotal matches the cart' );
	$t::money( $seen['tax'], $order->get_tax(), 'order tax matches the cart' );
	$t::money( $seen['total'], $order->get_total(), 'order total matches the cart' );
	$t::same( count( $seen['lines'] ), count( $items ), 'number of order lines' );
	foreach ( $seen['lines'] as $i => $line ) {
		if ( empty( $items[ $i ] ) ) {
			continue;
		}
		$t::money( $line['total'], $items[ $i ]->get_total(), 'line ' . ( $i + 1 ) . ' total matches the cart' );
		$t::money( $line['tax'], $items[ $i ]->get_tax_total(), 'line ' . ( $i + 1 ) . ' tax matches the cart' );
		$t::money( $line['discount'], $items[ $i ]->get_discount_total(), 'line ' . ( $i + 1 ) . ' discount matches the cart' );
	}
};

// Listed print 100 at 5% = 5. Other print 100 at 20% = 20. Shipping 10 at 20% = 2.
// Tax 27, total 200 + 10 + 27 = 237.
$ppt_orders_test(
	'Per-product tax order: each line is saved with its own rate\'s tax',
	function () use ( $t, $ppt_orders_use, $ppt_orders_snippet_products, $ppt_orders_snapshot, $ppt_orders_check ) {
		$t::tax( 20 );
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_orders_use( $ppt_orders_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$t::flat_shipping( 10, true );
		$t::checkout_address();
		$t::calculate();
		$seen  = $ppt_orders_snapshot();
		$order = $t::place_order();
		$ppt_orders_check( $order, $seen, array( 5.0, 20.0 ), 27, 2, 237 );
	}
);

// Option 1 (every print at 5%). Print 100 = 5 tax. Sell Anything product 50 at 20% = 10.
// Tax 15, total 165.
$ppt_orders_test(
	'Per-product tax order: prints at 5% by product type',
	function () use ( $t, $ppt_orders_use, $ppt_orders_snippet_prints, $ppt_orders_snapshot, $ppt_orders_check ) {
		$t::require_plugin( 'sunshine_general_product_max_qty', 'Sell Anything add-on' );
		$t::tax( 20 );
		$general = $t::product(
			50,
			array(
				'type' => 'general',
				'name' => 'Per-product tax general product',
			)
		);
		// Sell Anything's stock field, saved blank (unlimited) the way the product screen saves it.
		update_post_meta( $general, 'stock', '' );
		$ppt_orders_use( $ppt_orders_snippet_prints );
		$t::add( $t::product( 100 ) );
		$t::add( $general );
		$t::checkout_address();
		$t::calculate();
		$seen  = $ppt_orders_snapshot();
		$order = $t::place_order();
		$ppt_orders_check( $order, $seen, array( 5.0, 10.0 ), 15, 0, 165 );
	}
);

// Prices include tax. Listed print 105 = 100 + 5 tax. Other print 120 = 100 + 20 tax.
// Shipping 12 = 10 + 2 tax at the normal rate. Tax 27, the customer pays 105 + 120 + 12 = 237.
$ppt_orders_test(
	'Per-product tax order: prices include tax',
	function () use ( $t, $ppt_orders_use, $ppt_orders_snippet_products, $ppt_orders_snapshot, $ppt_orders_check ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$listed = $t::product( 105 );
		$other  = $t::product( 120 );
		$ppt_orders_use( $ppt_orders_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$t::flat_shipping( 12, true );
		$t::checkout_address();
		$t::calculate();
		$seen  = $ppt_orders_snapshot();
		$order = $t::place_order();
		$ppt_orders_check( $order, $seen, array( 5.0, 20.0 ), 27, 2, 237 );
		$items = array_values( (array) $order->get_items() );
		$t::money( 100, isset( $items[0] ) ? $items[0]->get_total() : null, 'listed print saved without its 5% tax' );
		$t::money( 100, isset( $items[1] ) ? $items[1]->get_total() : null, 'other print saved without its 20% tax' );
	}
);

// 3 x 19.99 = 59.97 at 5% = 2.9985, so 3.00 (1.00 each). 2 x 4.99 = 9.98 at 20% = 1.996, so
// 2.00 (1.00 each). Tax 5.00, total 69.95 + 5.00 = 74.95.
$ppt_orders_test(
	'Per-product tax order: quantities and odd prices',
	function () use ( $t, $ppt_orders_use, $ppt_orders_snippet_products, $ppt_orders_snapshot, $ppt_orders_check ) {
		$t::tax( 20 );
		$listed = $t::product( 19.99 );
		$other  = $t::product( 4.99 );
		$ppt_orders_use( $ppt_orders_snippet_products( array( $listed ) ) );
		$t::add( $listed, 3 );
		$t::add( $other, 2 );
		$t::checkout_address();
		$t::calculate();
		$seen  = $ppt_orders_snapshot();
		$order = $t::place_order();
		$ppt_orders_check( $order, $seen, array( 3.0, 2.0 ), 5, 0, 74.95 );
	}
);

// A zero rated product (rate 0): 100 with no tax. Other print 100 at 20% = 20.
// Tax 20, total 220.
$ppt_orders_test(
	'Per-product tax order: a 0% product is saved with no tax',
	function () use ( $t, $ppt_orders_use, $ppt_orders_snippet_products, $ppt_orders_snapshot, $ppt_orders_check ) {
		$t::tax( 20 );
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_orders_use( $ppt_orders_snippet_products( array( $listed ), 0 ) );
		$t::add( $listed );
		$t::add( $other );
		$t::checkout_address();
		$t::calculate();
		$seen  = $ppt_orders_snapshot();
		$order = $t::place_order();
		$ppt_orders_check( $order, $seen, array( 0.0, 20.0 ), 20, 0, 220 );
	}
);

// Quantity discount on the 5% print: 10 x 5 = 50, 2 off each = 40, at 5% = 2.00 (0.40 each).
// Other print 50 at 20% = 10. Tax 12, total 40 + 50 + 12 = 102.
$ppt_orders_test(
	'Per-product tax order: quantity discount on a reduced-rate product',
	function () use ( $t, $ppt_orders_use, $ppt_orders_snippet_products, $ppt_orders_snapshot, $ppt_orders_check ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::tax( 20 );
		// Saved the way the product screen saves it: 5 or more, 2 off each.
		$rule   = array(
			'items' => array(
				array(
					'qty'      => '5',
					'discount' => '2',
					'type'     => 'amount',
				),
			),
		);
		$listed = $t::product( 10, array( 'qty_discount' => $rule ) );
		$other  = $t::product( 50 );
		$ppt_orders_use( $ppt_orders_snippet_products( array( $listed ) ) );
		$t::add( $listed, 5 );
		$t::add( $other );
		$t::checkout_address();
		$t::calculate();
		$seen  = $ppt_orders_snapshot();
		$order = $t::place_order();
		$ppt_orders_check( $order, $seen, array( 2.0, 10.0 ), 12, 0, 102 );
		$items = array_values( (array) $order->get_items() );
		$t::money( 10, isset( $items[0] ) ? $items[0]->get_discount_total() : null, 'quantity discount saved on the line' );
	}
);

// Gift card 50 pays part: 225 - 50 = 175 left to pay. Lines keep 5 and 20.
$ppt_orders_test(
	'Per-product tax order: gift card',
	function () use ( $t, $ppt_orders_use, $ppt_orders_snippet_products, $ppt_orders_snapshot, $ppt_orders_check ) {
		$t::tax( 20 );
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_orders_use( $ppt_orders_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$t::gift_card( 50 );
		$t::checkout_address();
		$t::calculate();
		$seen  = $ppt_orders_snapshot();
		$order = $t::place_order();
		$ppt_orders_check( $order, $seen, array( 5.0, 20.0 ), 25, 0, 175 );
		$fees = (array) $order->get_fees();
		$t::money( -50, isset( $fees['gift_card'] ) ? $fees['gift_card']['amount'] : 0, 'gift card on the order' );
	}
);

// The doc warns a code on the whole order takes its tax off at the normal rate.
// 10% off 200 = 20, its tax at 20% = 4. Tax 25 - 4 = 21. Total 200 - 20 + 21 = 201.
// The lines keep their own tax (5 and 20); the code's tax only shows in the order's tax,
// the same as for any order with a code, so only the order totals are checked here.
$ppt_orders_test(
	'Per-product tax order: discount code on the whole order',
	function () use ( $t, $ppt_orders_use, $ppt_orders_snippet_products, $ppt_orders_snapshot ) {
		$t::tax( 20 );
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_orders_use( $ppt_orders_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$t::discount( 'percent-total', 10 );
		$t::checkout_address();
		$t::calculate();
		$seen  = $ppt_orders_snapshot();
		$order = $t::place_order();
		$t::money( 20, $order->get_discount(), 'order discount' );
		$t::money( 21, $order->get_tax(), 'order tax' );
		$t::money( 201, $order->get_total(), 'order total' );
		$t::money( $seen['tax'], $order->get_tax(), 'order tax matches the cart' );
		$t::money( $seen['total'], $order->get_total(), 'order total matches the cart' );
	}
);

// Prices include tax. 105 (5 tax inside) + 120 (20 tax inside), 10% off = 22.50, the customer
// pays 202.50. By the doc's rule the code's tax is 22.50 - 22.50 / 1.2 = 3.75, so the order's
// tax is 25 - 3.75 = 21.25. It can never be more than the 25 it was with no code.
$ppt_orders_test(
	'Per-product tax order: prices include tax with a discount code on the whole order',
	function () use ( $t, $ppt_orders_use, $ppt_orders_snippet_products ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$listed = $t::product( 105 );
		$other  = $t::product( 120 );
		$ppt_orders_use( $ppt_orders_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$t::discount( 'percent-total', 10 );
		$t::checkout_address();
		$t::calculate();
		$order = $t::place_order();
		$tax   = (float) $order->get_tax();
		$t::money( 202.50, $order->get_total(), 'order total' );
		$t::same( true, $tax <= 25, 'tax recorded on the order is not more than with no code, 25.00 (got ' . $tax . ')' );
		$t::money( 21.25, $tax, 'order tax' );
	}
);
