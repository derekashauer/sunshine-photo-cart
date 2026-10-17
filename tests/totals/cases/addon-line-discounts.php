<?php
/**
 * Add-ons that discount cart lines: Session Fees (products included in a paid session)
 * and Quantity Discounts.
 *
 * Checkout builds each line more than once in a request: for the cart, again while working
 * out totals, and again when the order is saved. Every build must give the same discount.
 */

$t = 'SPC_Totals_Test';

// Build the lines again from the saved cart, the way the order is created at checkout.
$order_line_discounts = function () {
	$total = 0;
	foreach ( (array) SPC()->cart->get_cart() as $raw_item ) {
		$line   = new SPC_Cart_Item( $raw_item );
		$total += (float) $line->get_discount_total();
	}
	return $total;
};

$cart_line_discounts = function ( $cart ) {
	$total = 0;
	foreach ( (array) $cart->get_cart_items() as $line ) {
		$total += (float) $line->get_discount_total();
	}
	return $total;
};

$t::test(
	'Session fee: included products stay free every time the lines are built',
	function () use ( $t, $order_line_discounts, $cart_line_discounts ) {
		$t::require_plugin( 'sunshine_session_fees_cart_item_discount', 'Session Fees add-on' );
		$customer_id = $t::customer();
		$product_id  = $t::product( 10, array( 'taxable' => false ) );
		// The customer's paid session includes two of this product.
		update_user_meta( $customer_id, 'sunshine_session_products', array( array( 'product' => $product_id, 'qty' => 2 ) ) );
		$t::add( $product_id, 3 );
		$cart = $t::calculate();
		$t::money( 20, $cart_line_discounts( $cart ), 'line discount, first build' );
		$t::money( 10, $cart->get_total(), 'total, first build' );
		$cart->get_cart_items( true );
		$t::money( 20, $cart_line_discounts( $cart ), 'line discount, cart rebuilt' );
		$t::money( 20, $order_line_discounts(), 'line discount when the order is saved' );
	}
);

$t::test(
	'Session fee: included quantity is shared across two lines of the same product',
	function () use ( $t, $order_line_discounts, $cart_line_discounts ) {
		$t::require_plugin( 'sunshine_session_fees_cart_item_discount', 'Session Fees add-on' );
		$customer_id = $t::customer();
		$product_id  = $t::product( 10, array( 'taxable' => false ) );
		update_user_meta( $customer_id, 'sunshine_session_products', array( array( 'product' => $product_id, 'qty' => 3 ) ) );
		// Lines with no photo are never merged, so this is two lines of 2.
		$t::add( $product_id, 2 );
		$t::add( $product_id, 2 );
		$cart = $t::calculate();
		$t::same( 2, count( $cart->get_cart_items() ), 'two lines' );
		$t::money( 30, $cart_line_discounts( $cart ), 'three included, across both lines' );
		$t::money( 10, $cart->get_total(), 'total' );
		$t::money( 30, $order_line_discounts(), 'line discount when the order is saved' );
	}
);

$qty_rule = array(
	'calc'  => '',
	'items' => array(
		array(
			'qty'      => 5,
			'discount' => 2,
			'type'     => 'amount',
		),
	),
);

$t::test(
	'Quantity discount counts the cart as it is now, after more is added',
	function () use ( $t, $qty_rule, $cart_line_discounts ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$product_id = $t::product( 10, array( 'taxable' => false, 'qty_discount' => $qty_rule ) );
		$t::add( $product_id, 3 );
		$cart = $t::calculate();
		$t::money( 0, $cart_line_discounts( $cart ), 'no discount at 3' );
		// Adding to the cart works the cart out again in the same request.
		$t::add( $product_id, 2 );
		$cart = $t::calculate();
		$t::money( 10, $cart_line_discounts( $cart ), '2 off each of 5' );
		$t::money( 40, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Quantity discount is the same when the order is saved',
	function () use ( $t, $qty_rule, $order_line_discounts ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$product_id = $t::product( 10, array( 'taxable' => false, 'qty_discount' => $qty_rule ) );
		$t::add( $product_id, 5 );
		$t::calculate();
		$t::money( 10, $order_line_discounts(), 'line discount when the order is saved' );
	}
);
