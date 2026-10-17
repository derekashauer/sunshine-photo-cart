<?php
/**
 * A placed order must match what the customer saw in the cart: the order totals and every line.
 * Orders are placed through the real checkout step with the Test payment method.
 */

$t = 'SPC_Totals_Test';

// What the cart shows right before the customer places the order.
$cart_snapshot = function () {
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

$order_matches_cart = function ( $order, $seen ) use ( $t ) {
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

$t::test(
	'Order: taxable items and taxable shipping',
	function () use ( $t, $cart_snapshot, $order_matches_cart ) {
		$t::tax( 7.25 );
		$t::add( $t::product( 19.99 ), 3 );
		$t::add( $t::product( 4.50, array( 'taxable' => false ) ), 2 );
		$t::flat_shipping( 8.95, true );
		$t::checkout_address();
		$t::calculate();
		$seen  = $cart_snapshot();
		$order = $t::place_order();
		$order_matches_cart( $order, $seen );
	}
);

$t::test(
	'Order: prices include tax',
	function () use ( $t, $cart_snapshot, $order_matches_cart ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 12.99 ), 3 );
		$t::flat_shipping( 6, true );
		$t::checkout_address();
		$t::calculate();
		$seen  = $cart_snapshot();
		$order = $t::place_order();
		$order_matches_cart( $order, $seen );
	}
);

$t::test(
	'Order: discount code and gift card',
	function () use ( $t, $cart_snapshot, $order_matches_cart ) {
		$t::tax( 10 );
		$t::add( $t::product( 50 ), 2 );
		$t::flat_shipping( 10, true );
		$t::discount( 'percent-total', 15 );
		$t::gift_card( 25 );
		$t::checkout_address();
		$t::calculate();
		$seen  = $cart_snapshot();
		$order = $t::place_order();
		$order_matches_cart( $order, $seen );
		$fees = (array) $order->get_fees();
		$t::money( -25, isset( $fees['gift_card'] ) ? $fees['gift_card']['amount'] : 0, 'gift card on the order' );
	}
);

$t::test(
	'Order: discount after tax',
	function () use ( $t, $cart_snapshot, $order_matches_cart ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$t::checkout_address();
		$t::calculate();
		$seen  = $cart_snapshot();
		$t::money( 88, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$order_matches_cart( $order, $seen );
	}
);

$t::test(
	'Order: session fee included products stay free',
	function () use ( $t, $cart_snapshot, $order_matches_cart ) {
		$t::require_plugin( 'sunshine_session_fees_cart_item_discount', 'Session Fees add-on' );
		$customer_id = $t::customer();
		$product_id  = $t::product( 10, array( 'taxable' => false ) );
		update_user_meta( $customer_id, 'sunshine_session_products', array( array( 'product' => $product_id, 'qty' => 2 ) ) );
		$t::add( $product_id, 3 );
		$t::checkout_address();
		$t::calculate();
		$seen = $cart_snapshot();
		$t::money( 10, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$order_matches_cart( $order, $seen );
	}
);

$t::test(
	'Order: quantity discount',
	function () use ( $t, $cart_snapshot, $order_matches_cart ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$rule = array(
			'calc'  => '',
			'items' => array(
				array(
					'qty'      => 5,
					'discount' => 10,
					'type'     => 'percent',
				),
			),
		);
		$t::tax( 8 );
		$t::add( $t::product( 3.33, array( 'qty_discount' => $rule ) ), 7 );
		$t::checkout_address();
		$t::calculate();
		$seen  = $cart_snapshot();
		$order = $t::place_order();
		$order_matches_cart( $order, $seen );
	}
);
