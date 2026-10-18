<?php
/**
 * Advanced Reports add-on: the Profit row it adds to the totals on the admin order screen
 * (the sunshine_admin_order_totals hook), worked out by SPC_Order::get_profit().
 *
 * From the Profit Tracking doc: profit is product revenue minus product costs. It does not
 * count shipping or tax. Costs are the ones set when the order was placed. Since August 2026 the
 * fee Sunshine Photo Cart takes on a payment and discount codes also come off.
 */

$t = 'SPC_Totals_Test';
if ( ! isset( $api_reports_test ) ) {
	require __DIR__ . '/addon-api-reports-helpers.php';
}

// Check the profit both ways: the number the order works out and the row the screen shows.
$api_reports_profit_is = function ( $expected, $order_id, $label ) use ( $t, $api_reports_profit_shown ) {
	$t::money( $expected, sunshine_get_order( $order_id )->get_profit(), $label . ' (order profit)' );
	$t::money( $expected, $api_reports_profit_shown( $order_id ), $label . ' (Profit row on the order screen)' );
};

$api_reports_test(
	'Profit: what the items sold for minus what they cost',
	function () use ( $t, $api_reports_product, $api_reports_profit_is ) {
		$t::require_plugin( 'sunshine_adv_report_admin_order_totals', 'Advanced Reports add-on' );
		$t::add( $api_reports_product( 30, '12' ), 3 );
		$t::calculate();
		$order = $t::place_order();
		// 3 x 30 = 90 sold, 3 x 12 = 36 cost.
		$api_reports_profit_is( 54, $order->get_id(), 'profit' );
	}
);

$api_reports_test(
	'Profit: tax and shipping are not profit',
	function () use ( $t, $api_reports_product, $api_reports_profit_is ) {
		$t::require_plugin( 'sunshine_adv_report_admin_order_totals', 'Advanced Reports add-on' );
		$t::tax( 10 );
		$t::add( $api_reports_product( 30, '12' ), 3 );
		$t::flat_shipping( 10, true );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 110, $order->get_total(), 'order total (90 + 10 shipping + 10 tax)' );
		$api_reports_profit_is( 54, $order->get_id(), 'profit (90 - 36)' );
	}
);

$api_reports_test(
	'Profit: a discount code comes off',
	function () use ( $t, $api_reports_product, $api_reports_profit_is ) {
		$t::require_plugin( 'sunshine_adv_report_admin_order_totals', 'Advanced Reports add-on' );
		$t::tax( 10 );
		$t::add( $api_reports_product( 30, '12' ), 3 );
		$t::discount( 'percent-total', 10 );
		$t::calculate();
		$order = $t::place_order();
		// 90 - 9 discount = 81 sold, 36 cost.
		$api_reports_profit_is( 45, $order->get_id(), 'profit' );
	}
);

// When prices include tax the discount is saved with its tax in it (9.90, of which 0.90 is
// tax), while the items are counted without tax (90). The tax part of the discount was never
// the photographer's money, so only 9.00 should come off.
$api_reports_test(
	'Profit: prices include tax and a discount code',
	function () use ( $t, $api_reports_product, $api_reports_profit_is ) {
		$t::require_plugin( 'sunshine_adv_report_admin_order_totals', 'Advanced Reports add-on' );
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $api_reports_product( 33, '12' ), 3 );
		$t::discount( 'percent-total', 10 );
		$t::calculate();
		$order = $t::place_order();
		// 3 x 33 = 99 with tax, 10% off = 89.10 paid, of which 8.10 is tax: 81 is the
		// photographer's. 81 - 36 cost = 45.
		$t::money( 89.10, $order->get_total(), 'order total' );
		$t::money( 8.10, $order->get_tax(), 'order tax' );
		$api_reports_profit_is( 45, $order->get_id(), 'profit' );
	}
);

// Discount after tax: tax is charged on the full price, then the discount comes off what the
// customer pays. The photographer still owes the full 10 of tax.
$api_reports_test(
	'Profit: discount after tax',
	function () use ( $t, $api_reports_product, $api_reports_profit_is ) {
		$t::require_plugin( 'sunshine_adv_report_admin_order_totals', 'Advanced Reports add-on' );
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $api_reports_product( 100, '40' ) );
		$t::discount( 'percent-total', 20 );
		$t::calculate();
		$order = $t::place_order();
		// 110 - 22 = 88 paid, 10 of it is tax: 78 is the photographer's. 78 - 40 = 38.
		$t::money( 88, $order->get_total(), 'order total' );
		$api_reports_profit_is( 38, $order->get_id(), 'profit' );
	}
);

// A gift card is a way to pay. The money came in when the card was sold, and the prints still
// sold for 100, so the profit on this order is the same as if the customer paid by card.
$api_reports_test(
	'Profit: paying with a gift card does not lower it',
	function () use ( $t, $api_reports_product, $api_reports_profit_is ) {
		$t::require_plugin( 'sunshine_adv_report_admin_order_totals', 'Advanced Reports add-on' );
		$t::add( $api_reports_product( 100, '40', array( 'taxable' => false ) ) );
		$t::gift_card( 50 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 50, $order->get_total(), 'left to pay after the gift card' );
		$api_reports_profit_is( 60, $order->get_id(), 'profit (100 - 40)' );
	}
);

$api_reports_test(
	'Profit: paying with store credit does not lower it',
	function () use ( $t, $api_reports_product, $api_reports_profit_is ) {
		$t::require_plugin( 'sunshine_adv_report_admin_order_totals', 'Advanced Reports add-on' );
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 30 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::add( $api_reports_product( 100, '40', array( 'taxable' => false ) ) );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 70, $order->get_total(), 'left to pay after the credit' );
		$api_reports_profit_is( 60, $order->get_id(), 'profit (100 - 40)' );
	}
);

// The Profit Tracking doc: changing a product's cost later doesn't change past orders.
$api_reports_test(
	'Profit: changing a cost later does not change a past order',
	function () use ( $t, $api_reports_product, $api_reports_profit_is ) {
		$t::require_plugin( 'sunshine_adv_report_admin_order_totals', 'Advanced Reports add-on' );
		$print = $api_reports_product( 30, '12' );
		$t::add( $print, 3 );
		$t::calculate();
		$order = $t::place_order();
		update_post_meta( $print, 'cost', '20' );
		$api_reports_profit_is( 54, $order->get_id(), 'profit still uses the cost of 12' );
	}
);

$api_reports_test(
	'Profit: a quantity discount on the line is counted once',
	function () use ( $t, $api_reports_product, $api_reports_profit_is ) {
		$t::require_plugin( 'sunshine_adv_report_admin_order_totals', 'Advanced Reports add-on' );
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$rule  = array(
			'calc'  => '',
			'items' => array(
				array(
					'qty'      => 5,
					'discount' => 10,
					'type'     => 'percent',
				),
			),
		);
		$print = $api_reports_product(
			10,
			'4',
			array(
				'taxable'      => false,
				'qty_discount' => $rule,
			)
		);
		$t::add( $print, 5 );
		$t::calculate();
		$order = $t::place_order();
		// 5 x 10 = 50, 10% off for buying 5 = 45 sold, 5 x 4 = 20 cost.
		$t::money( 45, $order->get_total(), 'order total' );
		$api_reports_profit_is( 25, $order->get_id(), 'profit' );
	}
);

// Stripe saves the fee Sunshine Photo Cart took on the payment, in dollars, as
// application_fee_amount (stripe.php, after the payment). That money never reached the
// photographer, so it comes off the profit.
$api_reports_test(
	'Profit: the Sunshine Photo Cart fee on a Stripe payment comes off',
	function () use ( $t, $api_reports_product, $api_reports_profit_is ) {
		$t::require_plugin( 'sunshine_adv_report_admin_order_totals', 'Advanced Reports add-on' );
		$methods = SPC()->payment_methods->get_payment_methods();
		if ( empty( $methods['stripe'] ) ) {
			$t::skip( 'Stripe payment method is not available' );
		}
		$t::add( $api_reports_product( 30, '12', array( 'taxable' => false ) ), 3 );
		$t::calculate();
		$order = $t::place_order();
		// What the Stripe payment step saves on a paid order.
		$order->update_meta_value( 'payment_method', 'stripe' );
		$order->update_meta_value( 'application_fee_amount', 4.5 );
		// 90 sold - 36 cost - 4.50 fee (5% of 90).
		$api_reports_profit_is( 49.50, $order->get_id(), 'profit' );
	}
);

$api_reports_test(
	'Profit: a refund comes off',
	function () use ( $t, $api_reports_product, $api_reports_profit_is ) {
		$t::require_plugin( 'sunshine_adv_report_admin_order_totals', 'Advanced Reports add-on' );
		$t::add( $api_reports_product( 100, '40', array( 'taxable' => false ) ) );
		$t::calculate();
		$order = $t::place_order();
		$order->add_refund( 30, 'One print damaged' );
		// 100 - 30 refunded = 70 kept, 40 cost.
		$api_reports_profit_is( 30, $order->get_id(), 'profit' );
	}
);

// The API copies the product name onto each line "so reports still show the right name" later,
// the same snapshot checkout takes. Checkout also copies the product's cost onto the line.
$api_reports_test(
	'Profit: an order made through the API counts the product cost',
	function () use ( $t, $api_reports_product, $api_reports_create_order, $api_reports_profit_is ) {
		$t::require_plugin( 'sunshine_adv_report_admin_order_totals', 'Advanced Reports add-on' );
		$print = $api_reports_product( 30, '12', array( 'taxable' => false ) );
		$id    = $api_reports_create_order(
			array(
				'status'     => 'processing',
				'mode'       => 'live',
				'line_items' => array(
					array(
						'product_id' => $print,
						'qty'        => 2,
					),
				),
				'subtotal'   => 60,
				'total'      => 60,
			)
		);
		// 2 x 30 = 60 sold, 2 x 12 = 24 cost.
		$api_reports_profit_is( 36, $id, 'profit' );
	}
);
