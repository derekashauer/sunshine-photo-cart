<?php
/**
 * Order lines are built again from what was saved every time an order is shown: the order
 * screen, the customer's account, the invoice and the emails. A past order must keep showing
 * what the customer was charged, even after prices, tax or discounts change on the site.
 */

$t = 'SPC_Totals_Test';

// The order's lines, read back from the database the way every order screen does.
$orders_after_items_lines = function ( $order ) {
	return array_values( (array) sunshine_get_order( $order->get_id() )->get_items() );
};

// The amount a customer reads in a formatted price, leaving out a struck-through old price.
$orders_after_items_shown = function ( $html ) {
	$text = html_entity_decode( wp_strip_all_tags( preg_replace( '#<s>.*?</s>#s', '', (string) $html ) ), ENT_QUOTES, 'UTF-8' );
	if ( ! preg_match( '/(-?)[^0-9\-]*([0-9][0-9,]*(?:\.[0-9]+)?)/', $text, $match ) ) {
		return null;
	}
	$amount = (float) str_replace( ',', '', $match[2] );
	return ( '-' === $match[1] ) ? -$amount : $amount;
};

// Prices on the order screens are shown with tax in these tests, so line tax shows up.
$orders_after_items_with_tax_display = function () use ( $t ) {
	$t::option( 'display_price', 'with_tax' );
	$t::option( 'price_suffix', '' );
	$t::option( 'currency_symbol_position', 'left' );
	$t::option( 'currency_thousands_separator', ',' );
	$t::option( 'currency_decimal_separator', '.' );
};

// Here the store had no tax turned on when the order was placed, then turned tax on later.
// The customer has prints in their cart again and opens the old order from their account.
$t::test(
	'Order line: tax stays what was charged after tax is turned on',
	function () use ( $t, $orders_after_items_lines, $orders_after_items_shown, $orders_after_items_with_tax_display ) {
		$orders_after_items_with_tax_display();
		$t::add( $t::product( 50 ), 2 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 0, $order->get_tax(), 'no tax charged' );

		$t::tax( 10 );
		$t::calculate();
		$lines = $orders_after_items_lines( $order );
		$t::money( 0, $lines[0]->get_tax(), 'line tax per print' );
		$t::money( 0, $lines[0]->get_tax_total(), 'line tax' );
		$t::money( 100, $orders_after_items_shown( $lines[0]->get_subtotal_formatted() ), 'line total shown (2 x 50, no tax)' );
		$t::money( 0, sunshine_get_order( $order->get_id() )->get_tax(), 'order tax' );
	}
);

$t::test(
	'Order line: tax stays what was charged after the tax rate changes',
	function () use ( $t, $orders_after_items_lines ) {
		$t::tax( 10 );
		$t::add( $t::product( 50 ), 2 );
		$t::calculate();
		$order = $t::place_order();

		$t::tax( 20 );
		$t::calculate();
		$lines = $orders_after_items_lines( $order );
		$t::money( 5, $lines[0]->get_tax(), 'line tax per print (10% of 50)' );
		$t::money( 10, $lines[0]->get_tax_total(), 'line tax' );
		$t::money( 10, sunshine_get_order( $order->get_id() )->get_tax(), 'order tax' );
	}
);

$t::test(
	'Order line: price stays what was paid after the product price changes',
	function () use ( $t, $orders_after_items_lines ) {
		$product_id = $t::product( 25, array( 'taxable' => false ) );
		$t::add( $product_id, 2 );
		$t::calculate();
		$order = $t::place_order();

		update_post_meta( $product_id, 'price', array( sunshine_get_default_price_level_id() => '40' ) );
		$lines = $orders_after_items_lines( $order );
		$t::money( 25, $lines[0]->get_price(), 'line price' );
		$t::money( 50, $lines[0]->get_total(), 'line total (2 x 25)' );
	}
);

$t::test(
	'Order line: a free product stays free after its price changes',
	function () use ( $t, $orders_after_items_lines ) {
		$free_id = $t::product( 0, array( 'taxable' => false ) );
		$t::add( $t::product( 5, array( 'taxable' => false ) ) );
		$t::add( $free_id );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 5, $order->get_total(), 'order total' );

		update_post_meta( $free_id, 'price', array( sunshine_get_default_price_level_id() => '15' ) );
		$lines = $orders_after_items_lines( $order );
		$t::same( 2, count( $lines ), 'two lines' );
		$free_line = null;
		foreach ( $lines as $line ) {
			if ( intval( $line->get_product_id() ) === intval( $free_id ) ) {
				$free_line = $line;
			}
		}
		$t::money( 0, $free_line ? $free_line->get_price() : null, 'free line price' );
		$t::money( 0, $free_line ? $free_line->get_total() : null, 'free line total' );
	}
);

// Quantity discount rules, saved the way the product screen saves them.
$orders_after_items_qty_rule = function ( $percent ) {
	return array(
		'calc'  => '1',
		'items' => array(
			array(
				'qty'      => '5',
				'discount' => (string) $percent,
				'type'     => 'percent',
			),
		),
	);
};

$t::test(
	'Order line: a quantity discount added later does not change past orders',
	function () use ( $t, $orders_after_items_lines, $orders_after_items_qty_rule ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$product_id = $t::product( 10, array( 'taxable' => false ) );
		$t::add( $product_id, 6 );
		$t::calculate();
		$order = $t::place_order();
		SPC()->cart->empty_cart();

		update_post_meta( $product_id, 'qty_discount', $orders_after_items_qty_rule( 10 ) );
		$lines = $orders_after_items_lines( $order );
		$t::money( 0, $lines[0]->get_discount(), 'line discount' );
		$t::money( 60, $lines[0]->get_total(), 'line total (6 x 10)' );
		$t::money( 60, sunshine_get_order( $order->get_id() )->get_subtotal(), 'order subtotal' );
	}
);

$t::test(
	'Order line: a quantity discount on the order stays the same after the rule changes',
	function () use ( $t, $orders_after_items_lines, $orders_after_items_qty_rule, $orders_after_items_shown ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$product_id = $t::product( 10, array( 'qty_discount' => $orders_after_items_qty_rule( 10 ), 'taxable' => false ) );
		$t::add( $product_id, 6 );
		$t::calculate();
		$order = $t::place_order();
		SPC()->cart->empty_cart();

		update_post_meta( $product_id, 'qty_discount', $orders_after_items_qty_rule( 20 ) );
		$lines = $orders_after_items_lines( $order );
		// 10% off each of 6 at 10 = 6.
		$t::money( 6, $lines[0]->get_discount(), 'line discount' );
		$t::money( 54, $lines[0]->get_total(), 'line total' );
		$t::money( 9, $orders_after_items_shown( $lines[0]->get_price_formatted() ), 'price shown for each print after the discount' );
	}
);

// The customer ordered two prints, then later bought a session fee that includes two of the
// same print. Their old order must not start showing those two prints as free.
$t::test(
	'Order line: prints included in a later session fee do not change past orders',
	function () use ( $t, $orders_after_items_lines ) {
		$t::require_plugin( 'sunshine_session_fees_cart_item_discount', 'Session Fees add-on' );
		$customer_id = $t::customer();
		$product_id  = $t::product( 10, array( 'taxable' => false ) );
		$t::add( $product_id, 2 );
		$t::calculate();
		$order = $t::place_order();
		SPC()->cart->empty_cart();

		update_user_meta( $customer_id, 'sunshine_session_products', array( array( 'product' => $product_id, 'qty' => 2 ) ) );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart     = new SPC_Cart();
		$lines          = $orders_after_items_lines( $order );
		$t::money( 0, $lines[0]->get_discount(), 'line discount' );
		$t::money( 20, $lines[0]->get_total(), 'line total (2 x 10)' );
	}
);

// 100 wallet prints at 1.99 with 7.25% tax: 199 x 7.25% = 14.4275, so 14.43 was charged.
$t::test(
	'Order line: tax on a line of many prints matches what was charged',
	function () use ( $t, $orders_after_items_lines, $orders_after_items_shown, $orders_after_items_with_tax_display ) {
		$orders_after_items_with_tax_display();
		$t::tax( 7.25 );
		$t::add( $t::product( 1.99 ), 100 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 14.43, $order->get_tax(), 'order tax' );
		$t::money( 213.43, $order->get_total(), 'order total' );

		$lines = $orders_after_items_lines( $order );
		$t::money( 14.43, $lines[0]->get_tax_total(), 'line tax' );
		$t::money( 213.43, $orders_after_items_shown( $lines[0]->get_subtotal_formatted() ), 'line total shown with tax' );
	}
);

$t::test(
	'Order line: a price over 10,000 keeps its cents',
	function () use ( $t, $orders_after_items_lines ) {
		$t::add( $t::product( 12345.67, array( 'taxable' => false ) ) );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 12345.67, $order->get_subtotal(), 'order subtotal' );

		$lines = $orders_after_items_lines( $order );
		$t::money( 12345.67, $lines[0]->get_price(), 'line price' );
		$t::money( 12345.67, $lines[0]->get_total(), 'line total' );
	}
);

// Prices entered with 20% tax included: each 12.99 print is 10.83 + 2.16 tax.
$t::test(
	'Order line: prices that include tax still show what the customer paid',
	function () use ( $t, $orders_after_items_lines, $orders_after_items_shown, $orders_after_items_with_tax_display ) {
		$orders_after_items_with_tax_display();
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 12.99 ), 3 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 38.97, $order->get_total(), 'order total (3 x 12.99)' );

		$lines = $orders_after_items_lines( $order );
		$t::money( 12.99, $lines[0]->get_price() + $lines[0]->get_tax(), 'price plus tax for one print' );
		$t::money( 38.97, $lines[0]->get_total() + $lines[0]->get_tax_total(), 'line total plus line tax' );
		$t::money( 12.99, $orders_after_items_shown( $lines[0]->get_price_formatted() ), 'price shown' );
		$t::money( 38.97, $orders_after_items_shown( $lines[0]->get_subtotal_formatted() ), 'line total shown' );
	}
);
