<?php
/**
 * API add-on reports (GET /reports/sales and /reports/top-products): the money they report
 * must match the orders they cover.
 *
 * Each test puts its orders in its own one-minute window in the 1990s and runs the report for
 * just that window, so orders made by anything else on the site are never counted.
 */

$t = 'SPC_Totals_Test';
if ( ! isset( $api_reports_test ) ) {
	require __DIR__ . '/addon-api-reports-helpers.php';
}

// One untaxed order of $amount in the window, made through the API.
$api_reports_sales_order = function ( $window, $status, $amount, $extra = array() ) use ( $t, $api_reports_create_order ) {
	$product = $t::product( $amount, array( 'taxable' => false ) );
	return $api_reports_create_order(
		array_merge(
			array(
				'date_created' => $window['date'],
				'status'       => $status,
				'mode'         => 'live',
				'line_items'   => array(
					array(
						'product_id' => $product,
						'qty'        => 1,
						'price'      => $amount,
					),
				),
				'subtotal'     => $amount,
				'total'        => $amount,
			),
			$extra
		)
	);
};

// Sunshine counts New, Processing, Ready for pickup and Shipped as paid
// (sunshine_order_statuses_paid()). A card payment at checkout puts the order in New.
$api_reports_test(
	'API sales report: a paid order in New status is revenue',
	function () use ( $t, $api_reports_window, $api_reports_sales_order, $api_reports_report ) {
		$window = $api_reports_window();
		$api_reports_sales_order( $window, 'new', 50 );
		$report = $api_reports_report( 'sales', $window );
		$t::same( 1, $report['order_count'], 'orders counted' );
		$t::money( 50, $report['revenue'], 'revenue' );
	}
);

$api_reports_test(
	'API sales report: unpaid, failed and cancelled orders are not revenue',
	function () use ( $t, $api_reports_window, $api_reports_sales_order, $api_reports_report ) {
		$window = $api_reports_window();
		$api_reports_sales_order( $window, 'pending', 20 );
		$api_reports_sales_order( $window, 'failed', 30 );
		$api_reports_sales_order( $window, 'cancelled', 40 );
		$api_reports_sales_order( $window, 'processing', 60 );
		$api_reports_sales_order( $window, 'shipped', 15 );
		$report = $api_reports_report( 'sales', $window );
		$t::same( 2, $report['order_count'], 'orders counted' );
		$t::money( 75, $report['revenue'], 'revenue (60 + 15)' );
	}
);

$api_reports_test(
	'API sales report: refunds come off the revenue',
	function () use ( $t, $api_reports_window, $api_reports_sales_order, $api_reports_report, $api_reports_rest ) {
		$window = $api_reports_window();
		$id     = $api_reports_sales_order( $window, 'processing', 100 );
		$api_reports_rest( 'PUT', '/orders/' . $id, array( 'refunds' => array( array( 'amount' => 25 ) ) ) );
		$api_reports_sales_order( $window, 'shipped', 40 );
		$report = $api_reports_report( 'sales', $window );
		$t::money( 140, $report['gross_revenue'], 'gross revenue (100 + 40)' );
		$t::money( 25, $report['refunds'], 'refunds' );
		$t::money( 115, $report['revenue'], 'revenue (140 - 25)' );
	}
);

$api_reports_test(
	'API sales report: tax and shipping are reported from the orders',
	function () use ( $t, $api_reports_window, $api_reports_create_order, $api_reports_report ) {
		$window  = $api_reports_window();
		$product = $t::product( 80 );
		$api_reports_create_order(
			array(
				'date_created' => $window['date'],
				'status'       => 'processing',
				'mode'         => 'live',
				'line_items'   => array(
					array(
						'product_id' => $product,
						'qty'        => 1,
						'price'      => 80,
						'tax'        => 6.40,
					),
				),
				'subtotal'     => 80,
				'shipping'     => 12,
				'tax'          => 6.40,
				'total'        => 98.40,
			)
		);
		$report = $api_reports_report( 'sales', $window );
		$t::money( 98.40, $report['revenue'], 'revenue' );
		$t::money( 6.40, $report['tax'], 'tax' );
		$t::money( 12, $report['shipping'], 'shipping' );
	}
);

// Orders taken while a payment method is in test mode are saved with mode "test". No real
// money moved, and the dashboard's sales numbers leave them out.
$api_reports_test(
	'API sales report: test mode orders are not revenue',
	function () use ( $t, $api_reports_window, $api_reports_sales_order, $api_reports_report ) {
		$window = $api_reports_window();
		$api_reports_sales_order( $window, 'processing', 40 );
		$api_reports_sales_order( $window, 'processing', 25, array( 'mode' => 'test' ) );
		$report = $api_reports_report( 'sales', $window );
		$t::same( 1, $report['order_count'], 'orders counted' );
		$t::money( 40, $report['revenue'], 'revenue' );
	}
);

$api_reports_test(
	'API sales report: grouped by day adds up to the same revenue',
	function () use ( $t, $api_reports_window, $api_reports_sales_order, $api_reports_report, $api_reports_rest ) {
		$window = $api_reports_window();
		$id     = $api_reports_sales_order( $window, 'processing', 70 );
		$api_reports_rest( 'PUT', '/orders/' . $id, array( 'refunds' => array( array( 'amount' => 10 ) ) ) );
		$api_reports_sales_order( $window, 'shipped', 30.55 );
		$days = $api_reports_report( 'sales', $window, array( 'group_by' => 'day' ) );
		$t::same( 1, count( $days ), 'one day' );
		$t::money( 90.55, isset( $days[0]['revenue'] ) ? $days[0]['revenue'] : null, 'revenue that day (70 - 10 + 30.55)' );
		$t::money( 10, isset( $days[0]['refunds'] ) ? $days[0]['refunds'] : null, 'refunds that day' );
	}
);

// Product revenue is what the product brought in. Refunds are already split across the lines
// of an order; assumption: a discount code on the order is split across its lines the same way.
$api_reports_test(
	'API product report: a discount code comes off product revenue',
	function () use ( $t, $api_reports_window, $api_reports_create_order, $api_reports_report ) {
		$window = $api_reports_window();
		$print  = $t::product( 60, array( 'taxable' => false ) );
		$album  = $t::product( 40, array( 'taxable' => false ) );
		$api_reports_create_order(
			array(
				'date_created' => $window['date'],
				'status'       => 'processing',
				'mode'         => 'live',
				'line_items'   => array(
					array(
						'product_id' => $print,
						'qty'        => 1,
						'price'      => 60,
					),
					array(
						'product_id' => $album,
						'qty'        => 1,
						'price'      => 40,
					),
				),
				'subtotal'     => 100,
				'discount'     => 20,
				'total'        => 80,
			)
		);
		$rows    = $api_reports_report( 'top-products', $window );
		$revenue = array();
		foreach ( (array) $rows as $row ) {
			$revenue[ (int) $row['product_id'] ] = $row['revenue'];
		}
		// 20% off the whole order: 60 - 12 = 48 and 40 - 8 = 32.
		$t::money( 48, isset( $revenue[ $print ] ) ? $revenue[ $print ] : null, 'print revenue' );
		$t::money( 32, isset( $revenue[ $album ] ) ? $revenue[ $album ] : null, 'album revenue' );
	}
);

$api_reports_test(
	'API product report: a refund is split across the products',
	function () use ( $t, $api_reports_window, $api_reports_create_order, $api_reports_report, $api_reports_rest ) {
		$window = $api_reports_window();
		$print  = $t::product( 60 );
		$album  = $t::product( 40 );
		$id     = $api_reports_create_order(
			array(
				'date_created' => $window['date'],
				'status'       => 'processing',
				'mode'         => 'live',
				'line_items'   => array(
					array(
						'product_id' => $print,
						'qty'        => 2,
						'price'      => 30,
						'tax'        => 3,
					),
					array(
						'product_id' => $album,
						'qty'        => 1,
						'price'      => 40,
						'tax'        => 4,
					),
				),
				'subtotal'     => 100,
				'tax'          => 10,
				'shipping'     => 10,
				'total'        => 120,
			)
		);
		// Half the order back: half of what each product brought in.
		$api_reports_rest( 'PUT', '/orders/' . $id, array( 'refunds' => array( array( 'amount' => 60 ) ) ) );
		$rows = $api_reports_report( 'top-products', $window );
		$by   = array();
		foreach ( (array) $rows as $row ) {
			$by[ (int) $row['product_id'] ] = $row;
		}
		$t::same( 2, isset( $by[ $print ] ) ? $by[ $print ]['qty'] : null, 'prints sold' );
		$t::money( 30, isset( $by[ $print ] ) ? $by[ $print ]['revenue'] : null, 'print revenue (60 - half)' );
		$t::money( 20, isset( $by[ $album ] ) ? $by[ $album ]['revenue'] : null, 'album revenue (40 - half)' );
	}
);
