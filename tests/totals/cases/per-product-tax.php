<?php
/**
 * A different tax rate on some products, using the snippet from the doc
 * "Charge a different tax rate on some products" (.docs/different-tax-rate-per-product.md).
 *
 * What the doc promises:
 * - Each item gets its tax from the store's rate, unless the snippet changes it.
 * - Each item is saved on the order with the tax for its own rate, and the order's tax adds
 *   them together.
 * - No tax is charged when the address matches no tax rate, and the snippet changes nothing.
 * - Shipping keeps the normal rate.
 * - The chosen products still need Taxable checked.
 * - Discount codes on the whole order work out their tax at the normal rate (a known limit).
 *   Discounts on single items are fine.
 *
 * Every test uses the snippet exactly as the doc publishes it, added at the start of the test
 * and taken off at the end. The normal rate is 20% and the snippet's rate is 5%, like a store
 * charging a lower VAT rate on prints.
 */

$t = 'SPC_Totals_Test';

// Option 1 from the doc, exactly as published: every print gets the 5% rate.
$ppt_snippet_prints = function ( $tax_rate, $cart_item ) {
	if ( ! empty( $tax_rate ) && 'print' === $cart_item->get_type() ) {
		$tax_rate['rate'] = 0.05; // 5% rate.
	}
	return $tax_rate;
};

// Option 2 from the doc, exactly as published, with the test's product IDs in place of
// 123, 456, 789. The doc says to change 0.05 to the rate you want, so a test can pick another.
$ppt_snippet_products = function ( $different_rate_products, $rate = 0.05 ) {
	return function ( $tax_rate, $cart_item ) use ( $different_rate_products, $rate ) {
		if ( ! empty( $tax_rate ) && in_array( $cart_item->get_product_id(), $different_rate_products ) ) {
			$tax_rate['rate'] = $rate; // 5% rate.
		}
		return $tax_rate;
	};
};

// Nothing here should talk to an outside service. If something tries, stop it and say so.
$ppt_offline = function ( $pre, $args, $url ) {
	$parts = wp_parse_url( $url );
	WP_CLI::warning( 'Blocked an outgoing request during a per-product tax test: ' . ( isset( $parts['host'] ) ? $parts['host'] : '' ) . ( isset( $parts['path'] ) ? $parts['path'] : '' ) );
	return new WP_Error( 'spc_totals_offline', 'Outgoing requests are blocked while the totals tests run.' );
};

// Snippets added by the current test. They come off when the test ends, even if it fails.
$ppt_snippets = array();

$ppt_use = function ( $snippet ) use ( &$ppt_snippets ) {
	add_filter( 'sunshine_cart_item_tax_rate', $snippet, 10, 2 );
	$ppt_snippets[] = $snippet;
};

$ppt_test = function ( $name, $callback ) use ( $t, $ppt_offline, &$ppt_snippets ) {
	$t::test(
		$name,
		function () use ( $callback, $ppt_offline, &$ppt_snippets ) {
			$ppt_snippets = array();
			add_filter( 'pre_http_request', $ppt_offline, 1, 3 );
			try {
				$callback();
			} finally {
				foreach ( $ppt_snippets as $snippet ) {
					remove_filter( 'sunshine_cart_item_tax_rate', $snippet, 10 );
				}
				$ppt_snippets = array();
				remove_filter( 'pre_http_request', $ppt_offline, 1 );
			}
		}
	);
};

// A Sell Anything product. Its type is "general", so the print snippet leaves it alone.
$ppt_general = function ( $price ) use ( $t ) {
	$t::require_plugin( 'sunshine_general_product_max_qty', 'Sell Anything add-on' );
	$id = $t::product(
		$price,
		array(
			'type' => 'general',
			'name' => 'Per-product tax general product',
		)
	);
	// Sell Anything's stock field, saved blank (unlimited) the way the product screen saves it.
	update_post_meta( $id, 'stock', '' );
	return $id;
};

// Tax on each cart line, in cart order.
$ppt_line_taxes = function ( $cart ) {
	$taxes = array();
	foreach ( (array) $cart->get_cart_items() as $line ) {
		$taxes[] = round( (float) $line->get_tax_total(), 2 );
	}
	return $taxes;
};

// A quantity discount rule saved the way the product screen saves it: 5 or more, 2 off each.
$ppt_qty_rule = array(
	'items' => array(
		array(
			'qty'      => '5',
			'discount' => '2',
			'type'     => 'amount',
		),
	),
);

/*
 * The basics: each product taxed at its own rate.
 */

// Print 100 at 5% = 5. Sell Anything product 50 at 20% = 10. Tax 15, total 165.
$ppt_test(
	'Per-product tax: prints at 5% by product type, other products at the normal 20%',
	function () use ( $t, $ppt_use, $ppt_snippet_prints, $ppt_general, $ppt_line_taxes ) {
		$t::tax( 20 );
		$general = $ppt_general( 50 );
		$ppt_use( $ppt_snippet_prints );
		$t::add( $t::product( 100 ) );
		$t::add( $general );
		$cart = $t::calculate();
		$t::same( array( 5.0, 10.0 ), $ppt_line_taxes( $cart ), 'tax on each line' );
		$t::money( 15, $cart->get_tax(), 'tax' );
		$t::money( 165, $cart->get_total(), 'total' );
	}
);

// Two prints at 100. The one in the snippet's list pays 5, the other 20. Tax 25, total 225.
$ppt_test(
	'Per-product tax: products listed by ID at 5%, the rest at the normal 20%',
	function () use ( $t, $ppt_use, $ppt_snippet_products, $ppt_line_taxes ) {
		$t::tax( 20 );
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$cart = $t::calculate();
		$t::same( array( 5.0, 20.0 ), $ppt_line_taxes( $cart ), 'tax on each line' );
		$t::money( 200, $cart->get_subtotal(), 'subtotal' );
		$t::money( 25, $cart->get_tax(), 'tax' );
		$t::money( 225, $cart->get_total(), 'total' );
	}
);

// 3 x 19.99 = 59.97 at 5% = 2.9985, so 3.00. 2 x 4.99 = 9.98 at 20% = 1.996, so 2.00.
// Tax 5.00, total 69.95 + 5.00 = 74.95.
$ppt_test(
	'Per-product tax: tax on each line is rounded to the cent',
	function () use ( $t, $ppt_use, $ppt_snippet_products, $ppt_line_taxes ) {
		$t::tax( 20 );
		$listed = $t::product( 19.99 );
		$other  = $t::product( 4.99 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed, 3 );
		$t::add( $other, 2 );
		$cart = $t::calculate();
		$t::same( array( 3.0, 2.0 ), $ppt_line_taxes( $cart ), 'tax on each line' );
		$t::money( 5, $cart->get_tax(), 'tax' );
		$t::money( 74.95, $cart->get_total(), 'total' );
	}
);

// 100 of a 0.99 print at 5%: 99.00 x 5% = 4.95. Total 103.95.
$ppt_test(
	'Per-product tax: 100 of one reduced-rate print',
	function () use ( $t, $ppt_use, $ppt_snippet_products ) {
		$t::tax( 20 );
		$listed = $t::product( 0.99 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed, 100 );
		$cart = $t::calculate();
		$t::money( 4.95, $cart->get_tax(), 'tax' );
		$t::money( 103.95, $cart->get_total(), 'total' );
	}
);

// The doc: the chosen products still need Taxable checked. A listed product that isn't taxable
// pays no tax. The other print pays 20. Tax 20, total 220.
$ppt_test(
	'Per-product tax: a listed product that is not Taxable stays untaxed',
	function () use ( $t, $ppt_use, $ppt_snippet_products, $ppt_line_taxes ) {
		$t::tax( 20 );
		$listed = $t::product( 100, array( 'taxable' => false ) );
		$other  = $t::product( 100 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$cart = $t::calculate();
		$t::same( array( 0.0, 20.0 ), $ppt_line_taxes( $cart ), 'tax on each line' );
		$t::money( 20, $cart->get_tax(), 'tax' );
		$t::money( 220, $cart->get_total(), 'total' );
	}
);

// The doc: when the address matches none of the store's rates, no tax is charged and the
// snippet makes no change. The only rate is for Great Britain; the store and cart are in the US.
$ppt_test(
	'Per-product tax: no tax when the address matches no tax rate',
	function () use ( $t, $ppt_use, $ppt_snippet_products, $ppt_line_taxes ) {
		$t::option( 'taxes_enabled', 1 );
		$t::option(
			'tax_rates',
			array(
				array(
					'country'  => 'GB',
					'state'    => '',
					'postcode' => '',
					'rate'     => '20',
					'name'     => 'VAT',
				),
			)
		);
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$cart = $t::calculate();
		$t::same( array( 0.0, 0.0 ), $ppt_line_taxes( $cart ), 'tax on each line' );
		$t::money( 0, $cart->get_tax(), 'tax' );
		$t::money( 200, $cart->get_total(), 'total' );
	}
);

// The doc: shipping keeps the normal rate. Print 100 at 5% = 5. Shipping 10 at 20% = 2.
// Tax 7, total 117.
$ppt_test(
	'Per-product tax: taxed shipping keeps the normal rate',
	function () use ( $t, $ppt_use, $ppt_snippet_prints ) {
		$t::tax( 20 );
		$ppt_use( $ppt_snippet_prints );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_shipping(), 'shipping' );
		$t::money( 2, $cart->get_shipping_tax(), 'shipping tax at 20%' );
		$t::money( 7, $cart->get_tax(), 'tax' );
		$t::money( 117, $cart->get_total(), 'total' );
	}
);

// A rate of 0 (the doc says to put in the rate you want) for products that are zero rated.
// Listed print 100 pays nothing, the other pays 20. Tax 20, total 220.
$ppt_test(
	'Per-product tax: a 0% rate on some products',
	function () use ( $t, $ppt_use, $ppt_snippet_products, $ppt_line_taxes ) {
		$t::tax( 20 );
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_use( $ppt_snippet_products( array( $listed ), 0 ) );
		$t::add( $listed );
		$t::add( $other );
		$cart = $t::calculate();
		$t::same( array( 0.0, 20.0 ), $ppt_line_taxes( $cart ), 'tax on each line' );
		$t::money( 20, $cart->get_tax(), 'tax' );
		$t::money( 220, $cart->get_total(), 'total' );
	}
);

/*
 * Prices that include tax.
 */

// Listed print 105 at 5%: 100 + 5 tax. Other print 120 at 20%: 100 + 20 tax.
// The customer pays the prices they saw, 225, and the tax inside is 25.
$ppt_test(
	'Per-product tax: prices include tax, each product\'s tax comes out of its own price',
	function () use ( $t, $ppt_use, $ppt_snippet_products, $ppt_line_taxes ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$listed = $t::product( 105 );
		$other  = $t::product( 120 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$cart  = $t::calculate();
		$lines = array_values( (array) $cart->get_cart_items() );
		$t::money( 100, isset( $lines[0] ) ? $lines[0]->get_total() : null, 'listed print without its tax' );
		$t::money( 100, isset( $lines[1] ) ? $lines[1]->get_total() : null, 'other print without its tax' );
		$t::same( array( 5.0, 20.0 ), $ppt_line_taxes( $cart ), 'tax on each line' );
		$t::money( 25, $cart->get_tax(), 'tax' );
		$t::money( 225, $cart->get_total(), 'total' );
	}
);

// Print 105 at 5%: 100 + 5. Shipping 12 at the normal 20%: 10 + 2. Tax 7, total 117.
$ppt_test(
	'Per-product tax: prices include tax, taxed shipping keeps the normal rate',
	function () use ( $t, $ppt_use, $ppt_snippet_prints ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$ppt_use( $ppt_snippet_prints );
		$t::add( $t::product( 105 ) );
		$t::flat_shipping( 12, true );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_shipping(), 'shipping without its tax' );
		$t::money( 2, $cart->get_shipping_tax(), 'shipping tax at 20%' );
		$t::money( 7, $cart->get_tax(), 'tax' );
		$t::money( 117, $cart->get_total(), 'total' );
	}
);

// 3 x 12.99 = 38.97 with 5% inside: 38.97 / 1.05 = 37.114, so the tax is 1.86.
$ppt_test(
	'Per-product tax: prices include tax, odd price and a quantity',
	function () use ( $t, $ppt_use, $ppt_snippet_products, $ppt_line_taxes ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$listed = $t::product( 12.99 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed, 3 );
		$cart = $t::calculate();
		$t::same( array( 1.86 ), $ppt_line_taxes( $cart ), 'tax on the line' );
		$t::money( 1.86, $cart->get_tax(), 'tax' );
		$t::money( 38.97, $cart->get_total(), 'total' );
	}
);

// A 0% product with prices that include tax: 100 has no tax inside, so it stays 100.
// Other print 120 = 100 + 20 tax. Tax 20, total 220.
$ppt_test(
	'Per-product tax: prices include tax, a 0% rate on some products',
	function () use ( $t, $ppt_use, $ppt_snippet_products, $ppt_line_taxes ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$listed = $t::product( 100 );
		$other  = $t::product( 120 );
		$ppt_use( $ppt_snippet_products( array( $listed ), 0 ) );
		$t::add( $listed );
		$t::add( $other );
		$cart  = $t::calculate();
		$lines = array_values( (array) $cart->get_cart_items() );
		$t::money( 100, isset( $lines[0] ) ? $lines[0]->get_total() : null, 'zero rated print keeps its whole price' );
		$t::same( array( 0.0, 20.0 ), $ppt_line_taxes( $cart ), 'tax on each line' );
		$t::money( 20, $cart->get_tax(), 'tax' );
		$t::money( 220, $cart->get_total(), 'total' );
	}
);

/*
 * Discounts.
 */

// The doc warns: discount codes on the whole order work out their tax at the normal rate.
// Lines: 100 at 5% = 5, 100 at 20% = 20, so 25. 10% off the order = 20 off, and its tax is
// taken off at 20%: 20 x 20% = 4. Tax 21, total 200 - 20 + 21 = 201.
// (Splitting the code over each line's own rate would give 4.50 + 18 = 22.50; the doc says
// that is not what happens, so this test checks the documented behavior.)
$ppt_test(
	'Per-product tax: discount code on the whole order takes its tax off at the normal rate',
	function () use ( $t, $ppt_use, $ppt_snippet_products ) {
		$t::tax( 20 );
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		$t::money( 20, $cart->get_discount(), 'discount' );
		$t::money( 21, $cart->get_tax(), 'tax' );
		$t::money( 201, $cart->get_total(), 'total' );
	}
);

// The doc says discounts on single items are fine. A "Percentage off product(s)" code only for
// the 5% print takes 10 off it: that print is now 90, taxed at 5% = 4.50. The other print
// keeps 20. Tax 24.50, total 200 - 10 + 24.50 = 214.50.
// Assumption: "discounts on single items" in the doc includes a code limited to one product.
$ppt_test(
	'Per-product tax: discount code on one reduced-rate product uses that product\'s rate',
	function () use ( $t, $ppt_use, $ppt_snippet_products ) {
		$t::tax( 20 );
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$t::discount( 'percent-product', 10, array( 'allowed_products' => array( (string) $listed ) ) );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 24.50, $cart->get_tax(), 'tax: 90 x 5% + 100 x 20%' );
		$t::money( 214.50, $cart->get_total(), 'total' );
	}
);

// With "discount after tax" the code comes off after tax, so each line keeps its own tax.
// Tax 5 + 20 = 25. 10% off 225 = 22.50. Total 202.50.
$ppt_test(
	'Per-product tax: discount after tax keeps each product\'s own tax',
	function () use ( $t, $ppt_use, $ppt_snippet_products ) {
		$t::tax( 20 );
		$t::option( 'discount_after_tax', 1 );
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		$t::money( 25, $cart->get_tax(), 'tax' );
		$t::money( 22.50, $cart->get_discount(), 'discount' );
		$t::money( 202.50, $cart->get_total(), 'total' );
	}
);

// Prices include tax. Listed print 105 (5 tax inside), other print 120 (20 tax inside), so the
// tax is 25 before the code. 10% off 225 = 22.50, the customer pays 202.50.
// The doc's rule: the code's tax is worked out at the normal rate: 22.50 - 22.50 / 1.2 = 3.75.
// Tax 25 - 3.75 = 21.25. Whatever the split, money off can never make the tax bigger than the
// 25 it was with no code.
$ppt_test(
	'Per-product tax: prices include tax with a discount code on the whole order',
	function () use ( $t, $ppt_use, $ppt_snippet_products ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$listed = $t::product( 105 );
		$other  = $t::product( 120 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		$tax  = (float) $cart->get_tax();
		$t::money( 22.50, $cart->get_discount(), 'discount' );
		$t::money( 202.50, $cart->get_total(), 'total' );
		$t::same( true, $tax <= 25, 'tax is not more than with no code, 25.00 (got ' . $tax . ')' );
		$t::money( 21.25, $tax, 'tax: 25 less the code\'s tax at the normal rate' );
	}
);

// A quantity discount is a discount on one line, which the doc says is fine.
// Listed print 10 x 5 = 50, 2 off each = 40, at 5% = 2. Other print 50 at 20% = 10.
// Tax 12, total 40 + 50 + 12 = 102.
$ppt_test(
	'Per-product tax: quantity discount on a reduced-rate product',
	function () use ( $t, $ppt_use, $ppt_snippet_products, $ppt_qty_rule, $ppt_line_taxes ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::tax( 20 );
		$listed = $t::product( 10, array( 'qty_discount' => $ppt_qty_rule ) );
		$other  = $t::product( 50 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed, 5 );
		$t::add( $other );
		$cart  = $t::calculate();
		$lines = array_values( (array) $cart->get_cart_items() );
		$t::money( 10, isset( $lines[0] ) ? $lines[0]->get_discount_total() : null, 'quantity discount on the line' );
		$t::same( array( 2.0, 10.0 ), $ppt_line_taxes( $cart ), 'tax on each line' );
		$t::money( 12, $cart->get_tax(), 'tax' );
		$t::money( 102, $cart->get_total(), 'total' );
	}
);

// Prices include tax. Listed print 10.50 x 5 = 52.50, 2 off each = 42.50 with 5% inside:
// 42.50 / 1.05 = 40.476, so the tax is 2.02. The customer pays 42.50.
$ppt_test(
	'Per-product tax: prices include tax with a quantity discount',
	function () use ( $t, $ppt_use, $ppt_snippet_products, $ppt_qty_rule, $ppt_line_taxes ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$listed = $t::product( 10.50, array( 'qty_discount' => $ppt_qty_rule ) );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed, 5 );
		$cart = $t::calculate();
		$t::same( array( 2.02 ), $ppt_line_taxes( $cart ), 'tax on the line' );
		$t::money( 2.02, $cart->get_tax(), 'tax' );
		$t::money( 42.50, $cart->get_total(), 'total' );
	}
);

/*
 * Paying with a gift card, store credit and a payment fee.
 */

// The card pays everything: 200 + 5 + 20 tax = 225.
$ppt_test(
	'Per-product tax: gift card pays the whole order',
	function () use ( $t, $ppt_use, $ppt_snippet_products ) {
		$t::tax( 20 );
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$t::gift_card( 500 );
		$cart = $t::calculate();
		$t::money( 225, array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) ), 'taken from the card' );
		$t::money( 25, $cart->get_tax(), 'tax' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

// Credit pays after tax: 225 - 20 = 205. The tax stays 25.
$ppt_test(
	'Per-product tax: store credit pays part of the order',
	function () use ( $t, $ppt_use, $ppt_snippet_products ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::tax( 20 );
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$cart = $t::calculate();
		$t::money( 20, $cart->get_credits_applied(), 'credits used' );
		$t::money( 25, $cart->get_tax(), 'tax' );
		$t::money( 205, $cart->get_total(), 'total' );
	}
);

// A 3% card fee is 3% of what is owed: 225 x 3% = 6.75. Total 231.75.
$ppt_test(
	'Per-product tax: percent payment fee is on the total with each product\'s own tax',
	function () use ( $t, $ppt_use, $ppt_snippet_products ) {
		$t::tax( 20 );
		$t::option( 'test_fee', 'percent' );
		$t::option( 'test_fee_amount', '3' );
		$t::option( 'test_fee_name', 'Card fee' );
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
		$cart = $t::calculate();
		$t::money( 25, $cart->get_tax(), 'tax' );
		$t::money( 231.75, $cart->get_total(), 'total' );
	}
);

/*
 * The cart is built again on every page load.
 */

$ppt_test(
	'Per-product tax: same tax on the next page load',
	function () use ( $t, $ppt_use, $ppt_snippet_products, $ppt_line_taxes ) {
		$t::tax( 20 );
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$t::calculate();
		$cart = $t::new_request();
		$t::same( array( 5.0, 20.0 ), $ppt_line_taxes( $cart ), 'tax on each line' );
		$t::money( 25, $cart->get_tax(), 'tax' );
		$t::money( 225, $cart->get_total(), 'total' );
	}
);

// The doc: "The cart and checkout show the correct amount." With prices shown with tax, the
// listed print's line shows 100 + 5 = 105 and the other shows 100 + 20 = 120. (Sunshine leaves
// off a trailing .00 on purpose.)
$ppt_test(
	'Per-product tax: cart lines shown with tax use each product\'s own tax',
	function () use ( $t, $ppt_use, $ppt_snippet_products ) {
		$t::tax( 20 );
		$t::option( 'display_price', 'with_tax' );
		$t::option( 'price_suffix', '' );
		$listed = $t::product( 100 );
		$other  = $t::product( 100 );
		$ppt_use( $ppt_snippet_products( array( $listed ) ) );
		$t::add( $listed );
		$t::add( $other );
		$cart  = $t::calculate();
		$shown = array();
		foreach ( (array) $cart->get_cart_items() as $line ) {
			$text    = html_entity_decode( wp_strip_all_tags( $line->get_subtotal_formatted() ) );
			$shown[] = preg_replace( '/[^0-9.,]/', '', $text );
		}
		$t::same( array( '105', '120' ), $shown, 'line prices shown with tax' );
	}
);
