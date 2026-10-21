<?php
/**
 * Exports add-on: the order file (CSV or XML) photographers download for their bookkeeping.
 * Each row's money columns must match the saved order and what the order screen shows.
 *
 * The file is built the way the Exports screen builds it, for just the orders a test makes.
 */

$t = 'SPC_Totals_Test';
if ( ! isset( $api_reports_test ) ) {
	require __DIR__ . '/addon-api-reports-helpers.php';
}

$api_reports_cell = function ( $row, $header ) {
	return ( is_array( $row ) && isset( $row[ $header ] ) ) ? $row[ $header ] : null;
};

// Assumption: for accounting, the money columns in a row explain the Order Total:
// Subtotal - Discounts + Shipping + Tax, then any gift card, credit, fee or refund column.
// The Stripe/Square processing fee columns are what the payment company kept, not part of
// what the customer paid, so they are left out.
$api_reports_row_adds_up = function ( $row ) {
	$sum = (float) $row['Order Subtotal'] - (float) $row['Discounts'] + (float) $row['Shipping Cost'] + (float) $row['Order Tax'];
	foreach ( $row as $header => $value ) {
		if ( preg_match( '/processing fee/i', $header ) || '' === (string) $value || ! is_numeric( $value ) ) {
			continue;
		}
		if ( preg_match( '/gift|credit|refund/i', $header ) ) {
			$sum -= abs( (float) $value );
		} elseif ( preg_match( '/\bfees?\b/i', $header ) ) {
			$sum += (float) $value;
		}
	}
	return round( $sum, 2 );
};

// 2 x 40 with 10% tax, a 10% discount code and 10 of taxed shipping.
$api_reports_export_order = function () use ( $t ) {
	$t::tax( 10 );
	$t::add( $t::product( 40, array( 'name' => 'Export print' ) ), 2 );
	$t::flat_shipping( 10, true );
	$t::discount( 'percent-total', 10 );
	$t::calculate();
	return $t::place_order();
};

$api_reports_test(
	'Export: an order row has the same money as the order',
	function () use ( $t, $api_reports_export, $api_reports_export_order, $api_reports_cell ) {
		$order = $api_reports_export_order();
		$file  = $api_reports_export( array( $order->get_id() ) );
		$t::same( 1, count( $file['rows'] ), 'one row for the order' );
		$row = isset( $file['rows'][0] ) ? $file['rows'][0] : array();
		$t::same( (string) $order->get_id(), $api_reports_cell( $row, 'Order ID' ), 'order id' );
		// 80 - 8 discount = 72; tax 10% of 72 + 10% of 10 shipping = 8.20; 72 + 10 + 8.20 = 90.20.
		$t::money( 80, $api_reports_cell( $row, 'Order Subtotal' ), 'Order Subtotal' );
		$t::money( 8, $api_reports_cell( $row, 'Discounts' ), 'Discounts' );
		$t::money( 10, $api_reports_cell( $row, 'Shipping Cost' ), 'Shipping Cost' );
		$t::money( 8.20, $api_reports_cell( $row, 'Order Tax' ), 'Order Tax' );
		$t::money( 90.20, $api_reports_cell( $row, 'Order Total' ), 'Order Total' );
		$t::money( $order->get_total(), $api_reports_cell( $row, 'Order Total' ), 'Order Total = saved order' );
		$t::same( '2 x Export print', $api_reports_cell( $row, 'Product(s)' ), 'products column' );
	}
);

// A row with fewer or more cells than there are headers moves every later value under the
// wrong heading, so money ends up in the wrong column in a spreadsheet.
$api_reports_test(
	'Export: every row has one cell for each column, in every format',
	function () use ( $t, $api_reports_export, $api_reports_export_order ) {
		$order = $api_reports_export_order();
		foreach ( array( 'combined', 'rows', 'columns' ) as $format ) {
			$file = $api_reports_export( array( $order->get_id() ), $format );
			$t::same( true, count( $file['raw'] ) > 0, $format . ': has rows' );
			foreach ( $file['raw'] as $i => $cells ) {
				$t::same( count( $file['headers'] ), count( $cells ), $format . ' row ' . ( $i + 1 ) . ': cells = headers' );
			}
		}
	}
);

$api_reports_test(
	'Export: separate rows has one row per line, each with the order money',
	function () use ( $t, $api_reports_export, $api_reports_cell ) {
		$t::tax( 10 );
		$t::add( $t::product( 40, array( 'name' => 'Export print' ) ), 2 );
		$card = $t::product(
			15,
			array(
				'name'    => 'Export card',
				'taxable' => false,
			)
		);
		$t::add( $card, 3 );
		$t::calculate();
		$order = $t::place_order();
		$file  = $api_reports_export( array( $order->get_id() ), 'rows' );
		$t::same( 2, count( $file['rows'] ), 'one row per line' );
		$qty = array();
		foreach ( $file['rows'] as $row ) {
			$qty[ $api_reports_cell( $row, 'Product Name' ) ] = $api_reports_cell( $row, 'Product Qty' );
			// 80 + 45 = 125; tax 10% of 80 = 8; total 133.
			$t::money( 125, $api_reports_cell( $row, 'Order Subtotal' ), 'Order Subtotal on each row' );
			$t::money( 8, $api_reports_cell( $row, 'Order Tax' ), 'Order Tax on each row' );
			$t::money( 133, $api_reports_cell( $row, 'Order Total' ), 'Order Total on each row' );
		}
		ksort( $qty );
		$t::same(
			array(
				'Export card'  => '3',
				'Export print' => '2',
			),
			$qty,
			'quantity of each line'
		);
	}
);

$api_reports_test(
	'Export: separate columns has the order money and each line',
	function () use ( $t, $api_reports_export, $api_reports_export_order, $api_reports_cell ) {
		$order = $api_reports_export_order();
		$file  = $api_reports_export( array( $order->get_id() ), 'columns' );
		$row   = isset( $file['rows'][0] ) ? $file['rows'][0] : array();
		$t::money( 90.20, $api_reports_cell( $row, 'Order Total' ), 'Order Total' );
		$t::same( 'Export print', $api_reports_cell( $row, 'Product 1 Name' ), 'Product 1 Name' );
		$t::same( '2', $api_reports_cell( $row, 'Product 1 Qty' ), 'Product 1 Qty' );
	}
);

$api_reports_test(
	'Export: an XML file has the same money as the CSV file',
	function () use ( $t, $api_reports_export, $api_reports_export_order ) {
		$order = $api_reports_export_order();
		$csv   = $api_reports_export( array( $order->get_id() ), 'combined', 'csv' );
		$xml   = $api_reports_export( array( $order->get_id() ), 'combined', 'xml' );
		$t::same( count( $csv['rows'] ), count( $xml['rows'] ), 'rows' );
		foreach ( array( 'Order Subtotal', 'Discounts', 'Shipping Cost', 'Order Tax', 'Order Total' ) as $header ) {
			$t::money( $csv['rows'][0][ $header ], $xml['rows'][0][ $header ], $header );
		}
	}
);

// When prices include tax, the subtotal is saved without tax but the discount with its tax
// in it. The order screen shows the discount without its tax (10) next to the subtotal
// without tax (100). The file shows 11, so its columns don't add up to the total.
$api_reports_test(
	'Export: prices include tax: Discounts matches the order screen',
	function () use ( $t, $api_reports_export, $api_reports_cell, $api_reports_row_adds_up ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$t::calculate();
		$order = $t::place_order();
		$file  = $api_reports_export( array( $order->get_id() ) );
		$row   = isset( $file['rows'][0] ) ? $file['rows'][0] : array();
		// 110 with tax is 100 + 10 tax. 10% off = 11, of which 1 is tax. Paid 99, tax 9.
		$t::money( 100, $api_reports_cell( $row, 'Order Subtotal' ), 'Order Subtotal (without tax)' );
		$t::money( 9, $api_reports_cell( $row, 'Order Tax' ), 'Order Tax' );
		$t::money( 99, $api_reports_cell( $row, 'Order Total' ), 'Order Total' );
		$t::money( 10, $api_reports_cell( $row, 'Discounts' ), 'Discounts (without tax, like the subtotal)' );
		$t::money( 99, $api_reports_row_adds_up( $row ), 'the money columns add up to the Order Total' );
	}
);

// Discount after tax: tax is on the full 100, then 20% comes off the 110 the customer owes.
$api_reports_test(
	'Export: discount after tax: the money columns add up',
	function () use ( $t, $api_reports_export, $api_reports_cell, $api_reports_row_adds_up ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$t::calculate();
		$order = $t::place_order();
		$file  = $api_reports_export( array( $order->get_id() ) );
		$row   = isset( $file['rows'][0] ) ? $file['rows'][0] : array();
		// 100 + 10 tax = 110, 20% off = 22, 88 to pay.
		$t::money( 100, $api_reports_cell( $row, 'Order Subtotal' ), 'Order Subtotal' );
		$t::money( 10, $api_reports_cell( $row, 'Order Tax' ), 'Order Tax' );
		$t::money( 22, $api_reports_cell( $row, 'Discounts' ), 'Discounts' );
		$t::money( 88, $api_reports_cell( $row, 'Order Total' ), 'Order Total' );
		$t::money( 88, $api_reports_row_adds_up( $row ), 'the money columns add up to the Order Total' );
	}
);

$api_reports_test(
	'Export: odd prices and a big quantity match to the cent',
	function () use ( $t, $api_reports_export, $api_reports_cell, $api_reports_row_adds_up ) {
		$t::tax( 7.25 );
		$t::add( $t::product( 19.99 ), 37 );
		$t::calculate();
		$order = $t::place_order();
		$file  = $api_reports_export( array( $order->get_id() ) );
		$row   = isset( $file['rows'][0] ) ? $file['rows'][0] : array();
		// 37 x 19.99 = 739.63; 7.25% of 739.63 = 53.6232, so 53.62; 739.63 + 53.62 = 793.25.
		$t::money( 739.63, $api_reports_cell( $row, 'Order Subtotal' ), 'Order Subtotal' );
		$t::money( 53.62, $api_reports_cell( $row, 'Order Tax' ), 'Order Tax' );
		$t::money( 793.25, $api_reports_cell( $row, 'Order Total' ), 'Order Total' );
		$t::money( 793.25, $api_reports_row_adds_up( $row ), 'the money columns add up to the Order Total' );
	}
);

$api_reports_test(
	'Export: a gift card on the order shows in the row',
	function () use ( $t, $api_reports_export, $api_reports_cell, $api_reports_row_adds_up ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::gift_card( 50 );
		$t::calculate();
		$order = $t::place_order();
		$file  = $api_reports_export( array( $order->get_id() ) );
		$row   = isset( $file['rows'][0] ) ? $file['rows'][0] : array();
		// 100 + 10 tax - 50 gift card = 60 left to pay.
		$t::money( 60, $api_reports_cell( $row, 'Order Total' ), 'Order Total' );
		$t::money( 60, $api_reports_row_adds_up( $row ), 'the money columns add up to the Order Total' );
	}
);

$api_reports_test(
	'Export: store credit on the order shows in the row',
	function () use ( $t, $api_reports_export, $api_reports_cell, $api_reports_row_adds_up ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		$order = $t::place_order();
		$file  = $api_reports_export( array( $order->get_id() ) );
		$row   = isset( $file['rows'][0] ) ? $file['rows'][0] : array();
		$t::money( 80, $api_reports_cell( $row, 'Order Total' ), 'Order Total (100 - 20 credit)' );
		$t::money( 80, $api_reports_row_adds_up( $row ), 'the money columns add up to the Order Total' );
	}
);

$api_reports_test(
	'Export: a payment method fee shows in the row',
	function () use ( $t, $api_reports_export, $api_reports_cell, $api_reports_row_adds_up ) {
		$t::option( 'test_fee', 'amount' );
		$t::option( 'test_fee_amount', '2' );
		$t::option( 'test_fee_name', 'Card fee' );
		SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		$order = $t::place_order();
		$file  = $api_reports_export( array( $order->get_id() ) );
		$row   = isset( $file['rows'][0] ) ? $file['rows'][0] : array();
		$t::money( 102, $api_reports_cell( $row, 'Order Total' ), 'Order Total (100 + 2 fee)' );
		$t::money( 102, $api_reports_row_adds_up( $row ), 'the money columns add up to the Order Total' );
	}
);

// The order screen shows the refund and an Order Total with the refund taken off.
$api_reports_test(
	'Export: a refunded order shows the total after the refund',
	function () use ( $t, $api_reports_export, $api_reports_cell, $api_reports_row_adds_up ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		$order = $t::place_order();
		$order->add_refund( 30, 'One print damaged' );
		$file = $api_reports_export( array( $order->get_id() ) );
		$row  = isset( $file['rows'][0] ) ? $file['rows'][0] : array();
		$t::money( 80, $api_reports_cell( $row, 'Order Total' ), 'Order Total (110 - 30), same as the order screen' );
		$t::money( 80, $api_reports_row_adds_up( $row ), 'the money columns add up to the Order Total' );
	}
);

$api_reports_test(
	'Export: the Profit column matches the order screen',
	function () use ( $t, $api_reports_export, $api_reports_cell, $api_reports_product, $api_reports_profit_shown ) {
		$t::require_plugin( 'sunshine_adv_report_export_order_profit', 'Advanced Reports add-on' );
		$t::tax( 10 );
		$t::add( $api_reports_product( 30, '12' ), 3 );
		$t::flat_shipping( 10, true );
		$t::calculate();
		$order = $t::place_order();
		$file  = $api_reports_export( array( $order->get_id() ) );
		$row   = isset( $file['rows'][0] ) ? $file['rows'][0] : array();
		// 3 x 30 = 90 sold, 3 x 12 = 36 cost.
		$t::money( 54, $api_reports_cell( $row, 'Profit' ), 'Profit column' );
		$t::money( $api_reports_profit_shown( $order->get_id() ), $api_reports_cell( $row, 'Profit' ), 'Profit column = Profit row on the order screen' );
	}
);

$api_reports_test(
	'Export: an order made through the API exports the money it was given',
	function () use ( $t, $api_reports_export, $api_reports_cell, $api_reports_create_order ) {
		$print = $t::product( 30 );
		$id    = $api_reports_create_order(
			array(
				'status'     => 'processing',
				'mode'       => 'live',
				'line_items' => array(
					array(
						'product_id' => $print,
						'qty'        => 2,
						'price'      => 30,
						'tax'        => 2.40,
					),
				),
				'subtotal'   => 60,
				'tax'        => 4.80,
				'shipping'   => 7,
				'discount'   => 5,
				'total'      => 66.80,
			)
		);
		$file  = $api_reports_export( array( $id ) );
		$row   = isset( $file['rows'][0] ) ? $file['rows'][0] : array();
		$t::money( 60, $api_reports_cell( $row, 'Order Subtotal' ), 'Order Subtotal' );
		$t::money( 5, $api_reports_cell( $row, 'Discounts' ), 'Discounts' );
		$t::money( 7, $api_reports_cell( $row, 'Shipping Cost' ), 'Shipping Cost' );
		$t::money( 4.80, $api_reports_cell( $row, 'Order Tax' ), 'Order Tax' );
		// 60 - 5 + 7 + 4.80 = 66.80.
		$t::money( 66.80, $api_reports_cell( $row, 'Order Total' ), 'Order Total' );
		$t::same( 'processing', $api_reports_cell( $row, 'Order Status' ), 'Order Status' );
	}
);
