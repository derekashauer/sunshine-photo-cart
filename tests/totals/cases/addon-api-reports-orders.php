<?php
/**
 * API add-on: orders made, changed and read through the REST API (sunshine/v1/orders).
 *
 * An order made through the API must hold together the same way a checkout order does:
 * its totals match its lines, adding or removing a line changes the totals, and what the
 * API reads back is what the order screen shows.
 *
 * The API stores each line the same way checkout does: `price` is the price of one, `tax` is
 * the tax on one, and `discount` is the discount on the whole line.
 */

$t = 'SPC_Totals_Test';
if ( ! isset( $api_reports_test ) ) {
	require __DIR__ . '/addon-api-reports-helpers.php';
}

// Assumption: when an API client sends lines but no totals, the order's totals are worked out
// from the lines, like checkout does. A $0 order would show as $0 on the order screen, in
// reports, in exports and in the customer's total spent. Like a checkout order, the subtotal
// is the lines before their discounts and the order's discount is what the lines took off.
$api_reports_test(
	'API order: totals come from the lines when none are sent',
	function () use ( $t, $api_reports_create_order ) {
		$print = $t::product( 30 );
		$mug   = $t::product( 10 );
		$id    = $api_reports_create_order(
			array(
				'status'     => 'processing',
				'mode'       => 'live',
				'line_items' => array(
					array(
						'product_id' => $print,
						'qty'        => 2,
						'price'      => 30,
					),
					array(
						'product_id' => $mug,
						'qty'        => 3,
						'price'      => 10,
						'discount'   => 6,
					),
				),
			)
		);
		$order = sunshine_get_order( $id );
		// 2 x 30 + 3 x 10 = 90, less the 6 taken off the second line = 84.
		$t::money( 90, $order->get_subtotal(), 'subtotal' );
		$t::money( 6, $order->get_discount(), 'discount' );
		$t::money( 84, $order->get_total(), 'total' );
	}
);

$api_reports_test(
	'API order: a line with no price uses the product price',
	function () use ( $t, $api_reports_create_order, $api_reports_rest, $api_reports_lines ) {
		$print = $t::product( 30 );
		$id    = $api_reports_create_order(
			array(
				'status'     => 'processing',
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
		$items = $api_reports_rest( 'GET', '/orders/' . $id . '/items' )->get_data();
		$t::money( 30, isset( $items[0]['price'] ) ? $items[0]['price'] : null, 'price of one from the product' );
		$t::same( 2, isset( $items[0]['qty'] ) ? $items[0]['qty'] : null, 'quantity' );
		$lines = $api_reports_lines( $id );
		$t::money( 60, $lines['total'], 'line total on the order screen (2 x 30)' );
	}
);

// Each price level has its own price. A line that names a price level gets that level's price.
$api_reports_test(
	'API order: a line with no price uses the price for its price level',
	function () use ( $t, $api_reports_create_order, $api_reports_lines ) {
		$default = sunshine_get_default_price_level_id();
		$other   = 0;
		foreach ( (array) sunshine_get_price_levels() as $level ) {
			if ( (int) $level->get_id() !== (int) $default ) {
				$other = (int) $level->get_id();
				break;
			}
		}
		if ( ! $other ) {
			$t::skip( 'needs a second price level on the site' );
		}
		$print = $t::product( 30 );
		update_post_meta(
			$print,
			'price',
			array(
				$default => '30',
				$other   => '45',
			)
		);
		$id    = $api_reports_create_order(
			array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id'  => $print,
						'price_level' => $other,
						'qty'         => 1,
					),
				),
				'subtotal'   => 45,
				'total'      => 45,
			)
		);
		$lines = $api_reports_lines( $id );
		$t::money( 45, $lines['total'], 'line uses the other price level price' );
	}
);

// A photographer recording a free print (a gift, a replacement) sends a price of 0.
$api_reports_test(
	'API order: a line priced at 0 stays free',
	function () use ( $t, $api_reports_create_order, $api_reports_rest, $api_reports_lines ) {
		$print = $t::product( 30 );
		$id    = $api_reports_create_order(
			array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id' => $print,
						'qty'        => 1,
						'price'      => 0,
					),
				),
				'subtotal'   => 0,
				'total'      => 0,
			)
		);
		$items = $api_reports_rest( 'GET', '/orders/' . $id . '/items' )->get_data();
		$t::money( 0, isset( $items[0]['price'] ) ? $items[0]['price'] : null, 'price sent was 0' );
		$lines = $api_reports_lines( $id );
		$t::money( 0, $lines['total'], 'line total on the order screen' );
		$t::money( 0, sunshine_get_order( $id )->get_total(), 'order total' );
	}
);

// Assumption: the API either refuses totals that don't match the lines, or fixes them.
// Saving them as sent gives an order whose lines say one thing and whose total says another.
$api_reports_test(
	'API order: a total that does not match the lines is refused or fixed',
	function () use ( $t, $api_reports_rest, $api_reports_require_api ) {
		$api_reports_require_api();
		$print    = $t::product( 30, array( 'taxable' => false ) );
		$response = $api_reports_rest(
			'POST',
			'/orders',
			array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id' => $print,
						'qty'        => 2,
						'price'      => 30,
					),
				),
				'subtotal'   => 60,
				'total'      => 5,
			)
		);
		$data     = $response->get_data();
		if ( 201 === $response->get_status() && ! empty( $data['id'] ) ) {
			$t::track( 'order', (int) $data['id'] );
			// 2 x 30 = 60, nothing else on the order.
			$t::money( 60, sunshine_get_order( $data['id'] )->get_total(), 'saved total matches the lines' );
		} else {
			$t::same( true, $response->get_status() >= 400 && $response->get_status() < 500, 'refused as a bad request' );
		}
	}
);

// This is the order the API add-on's own test script (tests/orders.sh) sends: line 1 is
// 2 prints with "tax": 2.40, and the order tax is 3.60. Sunshine reads a line's tax as the
// tax on one print, so line 1 shows 4.80 of tax and the lines add up to 6.00, not 3.60.
$api_reports_test(
	'API order: the order tax agrees with the tax on its lines',
	function () use ( $t, $api_reports_create_order, $api_reports_lines ) {
		$print = $t::product( 30 );
		$id    = $api_reports_create_order(
			array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id' => $print,
						'qty'        => 2,
						'price'      => 30,
						'tax'        => 2.40,
					),
					array(
						'product_id' => $print,
						'qty'        => 1,
						'price'      => 30,
						'tax'        => 1.20,
					),
				),
				'subtotal'   => 90,
				'shipping'   => 5,
				'tax'        => 3.60,
				'total'      => 98.60,
			)
		);
		$order = sunshine_get_order( $id );
		$lines = $api_reports_lines( $id );
		$t::money( $order->get_tax(), $lines['tax'], 'tax on the lines (as the order screen shows them) = order tax' );
		$t::money( $order->get_subtotal(), $lines['total'], 'line totals = order subtotal' );
		$t::money( $lines['total'] + $lines['tax'] + 5, $order->get_total(), 'lines + tax + shipping = order total' );
	}
);

// Assumption: adding a line to an order changes what the order costs. Otherwise the order
// screen lists the new print but the total, reports and exports don't include it.
$api_reports_test(
	'API order: adding a line raises the order totals',
	function () use ( $t, $api_reports_create_order, $api_reports_rest, $api_reports_lines ) {
		$print = $t::product( 30, array( 'taxable' => false ) );
		$card  = $t::product( 12.50, array( 'taxable' => false ) );
		$id    = $api_reports_create_order(
			array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id' => $print,
						'qty'        => 1,
						'price'      => 30,
					),
				),
				'subtotal'   => 30,
				'total'      => 30,
			)
		);
		$added = $api_reports_rest(
			'POST',
			'/orders/' . $id . '/items',
			array(
				'product_id' => $card,
				'qty'        => 2,
				'price'      => 12.50,
			)
		);
		$t::same( 201, $added->get_status(), 'line added' );
		$lines = $api_reports_lines( $id );
		$t::money( 55, $lines['total'], 'lines now 30 + 2 x 12.50' );
		$order = sunshine_get_order( $id );
		$t::money( 55, $order->get_subtotal(), 'order subtotal' );
		$t::money( 55, $order->get_total(), 'order total' );
		$read = $api_reports_rest( 'GET', '/orders/' . $id )->get_data();
		$t::money( 55, $read['total'], 'total the API reads back' );
	}
);

$api_reports_test(
	'API order: removing a line lowers the order totals',
	function () use ( $t, $api_reports_create_order, $api_reports_rest, $api_reports_lines ) {
		$print   = $t::product( 30, array( 'taxable' => false ) );
		$card    = $t::product( 12.50, array( 'taxable' => false ) );
		$id      = $api_reports_create_order(
			array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id' => $print,
						'qty'        => 1,
						'price'      => 30,
					),
					array(
						'product_id' => $card,
						'qty'        => 2,
						'price'      => 12.50,
					),
				),
				'subtotal'   => 55,
				'total'      => 55,
			)
		);
		$items   = $api_reports_rest( 'GET', '/orders/' . $id . '/items' )->get_data();
		$card_id = 0;
		foreach ( (array) $items as $item ) {
			if ( (int) $item['product_id'] === (int) $card ) {
				$card_id = (int) $item['id'];
			}
		}
		$removed = $api_reports_rest( 'DELETE', '/orders/' . $id . '/items/' . $card_id );
		$t::same( 200, $removed->get_status(), 'line removed' );
		$lines = $api_reports_lines( $id );
		$t::money( 30, $lines['total'], 'one line of 30 left' );
		$order = sunshine_get_order( $id );
		$t::money( 30, $order->get_subtotal(), 'order subtotal' );
		$t::money( 30, $order->get_total(), 'order total' );
	}
);

$api_reports_test(
	'API order: changing the status leaves the money alone',
	function () use ( $t, $api_reports_create_order, $api_reports_rest ) {
		$print = $t::product( 30 );
		$id    = $api_reports_create_order(
			array(
				'status'     => 'pending',
				'line_items' => array(
					array(
						'product_id' => $print,
						'qty'        => 2,
						'price'      => 30,
						'tax'        => 3,
					),
				),
				'subtotal'   => 60,
				'tax'        => 6,
				'shipping'   => 8,
				'total'      => 74,
			)
		);
		$api_reports_rest( 'PUT', '/orders/' . $id . '/status', array( 'status' => 'processing' ) );
		$api_reports_rest( 'PUT', '/orders/' . $id, array( 'status' => 'shipped' ) );
		$order = sunshine_get_order( $id );
		$t::same( 'shipped', $order->get_status(), 'status' );
		$t::money( 60, $order->get_subtotal(), 'subtotal' );
		$t::money( 6, $order->get_tax(), 'tax' );
		$t::money( 8, $order->get_shipping(), 'shipping' );
		$t::money( 74, $order->get_total(), 'total' );
	}
);

// An order placed at checkout, read back through the API: same money as the order screen.
$api_reports_test(
	'API order: reading a checkout order gives the same money',
	function () use ( $t, $api_reports_rest ) {
		$t::tax( 10 );
		$t::add( $t::product( 40 ), 2 );
		$t::flat_shipping( 10, true );
		$t::discount( 'percent-total', 10 );
		$t::calculate();
		$order = $t::place_order();
		$read  = $api_reports_rest( 'GET', '/orders/' . $order->get_id() )->get_data();
		// 80 - 8 discount = 72; tax 10% of 72 + 10% of 10 shipping = 8.20; 72 + 10 + 8.20 = 90.20.
		$t::money( 80, $read['subtotal'], 'subtotal' );
		$t::money( 8, $read['discount'], 'discount' );
		$t::money( 10, $read['shipping'], 'shipping' );
		$t::money( 1, $read['shipping_tax'], 'shipping tax' );
		$t::money( 8.20, $read['tax'], 'tax' );
		$t::money( 90.20, $read['total'], 'total' );

		$items = array_values( (array) $api_reports_rest( 'GET', '/orders/' . $order->get_id() . '/items' )->get_data() );
		$saved = array_values( (array) $order->get_items() );
		$t::same( count( $saved ), count( $items ), 'number of lines' );
		foreach ( $saved as $i => $line ) {
			if ( empty( $items[ $i ] ) ) {
				continue;
			}
			$t::money( $line->get_price(), $items[ $i ]['price'], 'line ' . ( $i + 1 ) . ' price' );
			$t::same( (int) $line->get_qty(), $items[ $i ]['qty'], 'line ' . ( $i + 1 ) . ' quantity' );
			$t::money( $line->get_tax(), $items[ $i ]['tax'], 'line ' . ( $i + 1 ) . ' tax on one' );
			$t::money( $line->get_discount(), $items[ $i ]['discount'], 'line ' . ( $i + 1 ) . ' discount' );
		}
	}
);

// Assumption: an API client (an accounting app, say) must be able to see where every dollar
// went: subtotal - discount + shipping + tax + fees - store credit = total.
$api_reports_test(
	'API order: store credit used on an order shows in what the API reads back',
	function () use ( $t, $api_reports_rest ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 80, $order->get_total(), 'order total after 20 of credit' );
		$read = $api_reports_rest( 'GET', '/orders/' . $order->get_id() )->get_data();
		$t::money( 20, isset( $read['credits'] ) ? $read['credits'] : null, 'credits on the order' );
		$fees = 0;
		foreach ( (array) $read['fees'] as $fee ) {
			$fees += (float) $fee['amount'];
		}
		$credits = isset( $read['credits'] ) ? (float) $read['credits'] : 0;
		$t::money( $read['total'], $read['subtotal'] - $read['discount'] + $read['shipping'] + $read['tax'] + $fees - $credits, 'the money in the reply adds up to the total' );
	}
);

$api_reports_test(
	'API order: a gift card on an order shows in what the API reads back',
	function () use ( $t, $api_reports_rest ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::gift_card( 50 );
		$t::calculate();
		$order = $t::place_order();
		$read  = $api_reports_rest( 'GET', '/orders/' . $order->get_id() )->get_data();
		// 100 + 10 tax - 50 gift card = 60.
		$t::money( 60, $read['total'], 'total' );
		$fees = 0;
		foreach ( (array) $read['fees'] as $fee ) {
			$fees += (float) $fee['amount'];
		}
		$t::money( -50, $fees, 'gift card listed as a fee' );
		$t::money( $read['total'], $read['subtotal'] - $read['discount'] + $read['shipping'] + $read['tax'] + $fees, 'the money in the reply adds up to the total' );
	}
);

// When prices include tax, Sunshine saves the subtotal without tax but the discount with tax.
// The order screen takes the tax back out of the discount (discount_tax) before showing it.
// The reply must give an API client discount_tax and price_has_tax so it can do the same.
$api_reports_test(
	'API order: prices include tax: the money in the reply adds up',
	function () use ( $t, $api_reports_rest ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 99, $order->get_total(), 'order total (110 - 11)' );
		$read = $api_reports_rest( 'GET', '/orders/' . $order->get_id() )->get_data();
		$t::same( true, isset( $read['price_has_tax'] ) ? $read['price_has_tax'] : null, 'reply says prices include tax' );
		$discount_tax = isset( $read['discount_tax'] ) ? (float) $read['discount_tax'] : 0;
		// 100 without tax - (11 discount - 1 tax in it) + 9 tax = 99.
		$t::money( 99, $read['subtotal'] - ( $read['discount'] - $discount_tax ) + $read['shipping'] + $read['tax'], 'subtotal - discount without its tax + shipping + tax' );
	}
);

// A refund can't be more than the customer paid, and can't add money to the order.
$api_reports_test(
	'API order: a refund bigger than the order is refused',
	function () use ( $t, $api_reports_create_order, $api_reports_rest ) {
		$print = $t::product( 100, array( 'taxable' => false ) );
		$id    = $api_reports_create_order(
			array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id' => $print,
						'qty'        => 1,
						'price'      => 100,
					),
				),
				'subtotal'   => 100,
				'total'      => 100,
			)
		);
		$api_reports_rest( 'PUT', '/orders/' . $id, array( 'refunds' => array( array( 'amount' => 500 ) ) ) );
		$order = sunshine_get_order( $id );
		$t::same( true, $order->get_refund_total() <= 100, 'refunded no more than the 100 paid (got ' . $order->get_refund_total() . ')' );
		$t::same( true, $order->get_total() >= 0, 'order total is not below zero (got ' . $order->get_total() . ')' );
	}
);

$api_reports_test(
	'API order: a refund below zero is refused',
	function () use ( $t, $api_reports_create_order, $api_reports_rest ) {
		$print = $t::product( 100, array( 'taxable' => false ) );
		$id    = $api_reports_create_order(
			array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id' => $print,
						'qty'        => 1,
						'price'      => 100,
					),
				),
				'subtotal'   => 100,
				'total'      => 100,
			)
		);
		$api_reports_rest( 'PUT', '/orders/' . $id, array( 'refunds' => array( array( 'amount' => -20 ) ) ) );
		$order = sunshine_get_order( $id );
		$t::money( 100, $order->get_total(), 'order total is still 100' );
	}
);

$api_reports_test(
	'API order: a refund comes off the total the order screen shows',
	function () use ( $t, $api_reports_create_order, $api_reports_rest ) {
		$print = $t::product( 100 );
		$id    = $api_reports_create_order(
			array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id' => $print,
						'qty'        => 1,
						'price'      => 100,
						'tax'        => 10,
					),
				),
				'subtotal'   => 100,
				'tax'        => 10,
				'total'      => 110,
			)
		);
		$api_reports_rest( 'PUT', '/orders/' . $id, array( 'refunds' => array( array( 'amount' => 30 ) ) ) );
		$order = sunshine_get_order( $id );
		$t::money( 30, $order->get_refund_total(), 'refunded' );
		$t::money( 80, $order->get_total(), 'order total after the refund (110 - 30)' );
		$read = $api_reports_rest( 'GET', '/orders/' . $id . '/refunds' )->get_data();
		$t::money( 30, isset( $read[0]['amount'] ) ? $read[0]['amount'] : null, 'refund the API reads back' );
	}
);

// Assumption: an order made through the API is a real (live) order unless the client says it
// is a test. The dashboard sales numbers and order lists only count orders saved as live.
$api_reports_test(
	'API order: an order made through the API counts as a live order',
	function () use ( $t, $api_reports_create_order ) {
		$print = $t::product( 50, array( 'taxable' => false ) );
		$id    = $api_reports_create_order(
			array(
				'status'     => 'new',
				'line_items' => array(
					array(
						'product_id' => $print,
						'qty'        => 1,
						'price'      => 50,
					),
				),
				'subtotal'   => 50,
				'total'      => 50,
			)
		);
		$live  = sunshine_get_order_ids( array( 'post__in' => array( $id ) ), 'live' );
		$t::same( array( $id ), $live, 'found by the live order list' );
	}
);

$api_reports_test(
	'API order: batch create saves each order and its lines',
	function () use ( $t, $api_reports_rest, $api_reports_require_api, $api_reports_lines ) {
		$api_reports_require_api();
		$print    = $t::product( 30, array( 'taxable' => false ) );
		$response = $api_reports_rest(
			'POST',
			'/orders/batch',
			array(
				'create' => array(
					array(
						'status'     => 'processing',
						'line_items' => array(
							array(
								'product_id' => $print,
								'qty'        => 1,
								'price'      => 30,
							),
						),
						'subtotal'   => 30,
						'total'      => 30,
					),
					array(
						'status'     => 'processing',
						'line_items' => array(
							array(
								'product_id' => $print,
								'qty'        => 3,
								'price'      => 30,
							),
						),
						'subtotal'   => 90,
						'shipping'   => 7.5,
						'total'      => 97.5,
					),
				),
			)
		);
		$data     = $response->get_data();
		$ids      = array();
		foreach ( (array) ( isset( $data['create'] ) ? $data['create'] : array() ) as $created ) {
			if ( ! empty( $created['id'] ) ) {
				$t::track( 'order', (int) $created['id'] );
				$ids[] = (int) $created['id'];
			}
		}
		$t::same( 2, count( $ids ), 'two orders made' );
		if ( 2 === count( $ids ) ) {
			$t::money( 30, sunshine_get_order( $ids[0] )->get_total(), 'first order total' );
			$t::money( 30, $api_reports_lines( $ids[0] )['total'], 'first order lines' );
			$t::money( 97.5, sunshine_get_order( $ids[1] )->get_total(), 'second order total' );
			$t::money( 90, $api_reports_lines( $ids[1] )['total'], 'second order lines (3 x 30)' );
		}
	}
);

// A customer's "total spent" (the Customers screen and the customers export) is kept up to date
// when the API changes their orders. Only paid orders count: a cancelled order was never paid.
$api_reports_test(
	'API order: the customer total spent follows API changes',
	function () use ( $t, $api_reports_create_order, $api_reports_rest ) {
		$customer_id = $t::customer();
		$print       = $t::product( 50, array( 'taxable' => false ) );
		$line        = array(
			array(
				'product_id' => $print,
				'qty'        => 1,
				'price'      => 50,
			),
		);
		$paid        = $api_reports_create_order(
			array(
				'status'      => 'new',
				'mode'        => 'live',
				'customer_id' => $customer_id,
				'line_items'  => $line,
				'subtotal'    => 50,
				'total'       => 50,
			)
		);
		$t::money( 50, ( new SPC_Customer( $customer_id ) )->get_order_totals(), 'after one paid order of 50' );

		$api_reports_rest( 'PUT', '/orders/' . $paid, array( 'refunds' => array( array( 'amount' => 20 ) ) ) );
		$t::money( 30, ( new SPC_Customer( $customer_id ) )->get_order_totals(), 'after a 20 refund' );

		$api_reports_create_order(
			array(
				'status'      => 'cancelled',
				'mode'        => 'live',
				'customer_id' => $customer_id,
				'line_items'  => $line,
				'subtotal'    => 50,
				'total'       => 50,
			)
		);
		$t::money( 30, ( new SPC_Customer( $customer_id ) )->get_order_totals(), 'a cancelled order adds nothing' );
	}
);
