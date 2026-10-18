<?php
/**
 * Order lines the admin builds again by hand.
 *
 * "Attempt to fix it": when an order's saved lines don't match what was in the cart (a line went
 * missing while the order was saved), the order screen offers a link that deletes the lines and
 * builds them all again from the cart saved on the order. The rebuilt lines must hold what the
 * customer was charged: the same price, tax and line discount, even if prices, tax or discount
 * rules changed since, and even though the photographer (not the customer) clicks the link.
 *
 * The Packages add-on's update for old Sunshine 2 orders rebuilds package lines the first time
 * the order screen opens. It must keep the old order's prices too.
 *
 * Lines are read straight from the database (sunshine_order_items), so these checks are about
 * what the rebuild saves, not how an order screen shows it later. Helpers are in
 * admin-order-changes-helpers.php.
 */

$t = 'SPC_Totals_Test';

// The helpers load first when every admin-order-changes file runs; this lets one file run alone.
if ( ! isset( $admin_order_changes_test ) ) {
	require_once __DIR__ . '/admin-order-changes-helpers.php';
}

/*
 * "Attempt to fix it".
 */

// Nothing changed since the order: the missing line comes back as it was and the other line
// stays as it was. 10% tax: 2 x 50.00 (5.00 tax each) and 1 x 20.00 (2.00 tax).
$admin_order_changes_test(
	'Fix order lines: the missing line comes back with what was charged',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_rows, $admin_order_changes_lose_line, $admin_order_changes_fix_items, $admin_order_changes_same_line ) {
		$t::tax( 10 );
		$a = $t::product( 50 );
		$b = $t::product( 20 );
		$t::add( $a, 2 );
		$t::add( $b );
		$order  = $admin_order_changes_checkout();
		$before = $admin_order_changes_rows( $order->get_id() );
		$t::money( 5, $before[ $a ][0]['tax'], 'tax for one 50.00 print when ordered' );

		$admin_order_changes_lose_line( $order->get_id(), $b );
		$admin_order_changes_fix_items( $order->get_id() );
		$after = $admin_order_changes_rows( $order->get_id() );
		$t::same( 2, count( $after ), 'two lines again' );
		$admin_order_changes_same_line( $before[ $a ][0], isset( $after[ $a ] ) ? $after[ $a ][0] : null, '50.00 print line' );
		$admin_order_changes_same_line( $before[ $b ][0], isset( $after[ $b ] ) ? $after[ $b ][0] : null, '20.00 print line' );
	}
);

// The tax rate went from 10% to 20% after the order. The customer paid 10%: 12.00 tax in all
// (2 x 5.00 + 2.00), and the rebuilt lines must still add up to that.
$admin_order_changes_test(
	'Fix order lines: tax stays what was charged after the tax rate changed',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_rows, $admin_order_changes_lose_line, $admin_order_changes_fix_items, $admin_order_changes_same_line ) {
		$t::tax( 10 );
		$a = $t::product( 50 );
		$b = $t::product( 20 );
		$t::add( $a, 2 );
		$t::add( $b );
		$order  = $admin_order_changes_checkout();
		$before = $admin_order_changes_rows( $order->get_id() );
		$t::money( 12, $order->get_tax(), 'order tax' );

		$t::tax( 20 );
		$admin_order_changes_lose_line( $order->get_id(), $b );
		$admin_order_changes_fix_items( $order->get_id() );
		$after = $admin_order_changes_rows( $order->get_id() );
		$admin_order_changes_same_line( $before[ $a ][0], isset( $after[ $a ] ) ? $after[ $a ][0] : null, '50.00 print line (5.00 tax each)' );
		$admin_order_changes_same_line( $before[ $b ][0], isset( $after[ $b ] ) ? $after[ $b ][0] : null, '20.00 print line (2.00 tax)' );

		$line_tax = 0;
		foreach ( $after as $rows ) {
			foreach ( $rows as $row ) {
				$line_tax += $row['tax'] * $row['qty'];
			}
		}
		$t::money( 12, $line_tax, 'line tax adds up to the order tax' );
		$t::money( 12, sunshine_get_order( $order->get_id() )->get_tax(), 'order tax unchanged' );
	}
);

// Prices with 20% tax included, nothing changed since: 12.99 is 10.83 + 2.16 tax, 6.00 is
// 5.00 + 1.00 tax.
$admin_order_changes_test(
	'Fix order lines: prices that include tax come back the same',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_rows, $admin_order_changes_lose_line, $admin_order_changes_fix_items, $admin_order_changes_same_line ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$a = $t::product( 12.99 );
		$b = $t::product( 6 );
		$t::add( $a, 3 );
		$t::add( $b );
		$order  = $admin_order_changes_checkout();
		$before = $admin_order_changes_rows( $order->get_id() );
		$t::money( 10.83, $before[ $a ][0]['price'], '12.99 print saved without its tax' );
		$t::money( 2.16, $before[ $a ][0]['tax'], 'tax inside 12.99' );

		$admin_order_changes_lose_line( $order->get_id(), $b );
		$admin_order_changes_fix_items( $order->get_id() );
		$after = $admin_order_changes_rows( $order->get_id() );
		$admin_order_changes_same_line( $before[ $a ][0], isset( $after[ $a ] ) ? $after[ $a ][0] : null, '12.99 print line' );
		$admin_order_changes_same_line( $before[ $b ][0], isset( $after[ $b ] ) ? $after[ $b ][0] : null, '6.00 print line' );
	}
);

// The product's price went from 25.00 to 40.00 after the order. Lines keep 25.00.
$admin_order_changes_test(
	'Fix order lines: price stays what was paid after the product price changed',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_rows, $admin_order_changes_lose_line, $admin_order_changes_fix_items, $admin_order_changes_same_line ) {
		$a = $t::product( 25, array( 'taxable' => false ) );
		$b = $t::product( 10, array( 'taxable' => false ) );
		$t::add( $a, 2 );
		$t::add( $b );
		$order  = $admin_order_changes_checkout();
		$before = $admin_order_changes_rows( $order->get_id() );

		update_post_meta( $a, 'price', array( sunshine_get_default_price_level_id() => '40' ) );
		update_post_meta( $b, 'price', array( sunshine_get_default_price_level_id() => '12' ) );
		$admin_order_changes_lose_line( $order->get_id(), $b );
		$admin_order_changes_fix_items( $order->get_id() );
		$after = $admin_order_changes_rows( $order->get_id() );
		$admin_order_changes_same_line( $before[ $a ][0], isset( $after[ $a ] ) ? $after[ $a ][0] : null, '25.00 line' );
		$admin_order_changes_same_line( $before[ $b ][0], isset( $after[ $b ] ) ? $after[ $b ][0] : null, '10.00 line' );
	}
);

// A 10.00 print with a 5.00 rush option was 15.00 each. The rush price is now 8.00. The
// line that was fine is rebuilt too and must stay at 15.00.
$admin_order_changes_test(
	'Fix order lines: option price stays what was paid after it changed',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_rows, $admin_order_changes_lose_line, $admin_order_changes_fix_items, $admin_order_changes_same_line, $admin_order_changes_checkbox_option, $admin_order_changes_option_price, $admin_order_changes_add_with_options ) {
		$rush = $admin_order_changes_checkbox_option();
		$a    = $t::product( 10, array( 'taxable' => false ) );
		$b    = $t::product( 20, array( 'taxable' => false ) );
		$admin_order_changes_option_price( $a, $rush, '5' );
		$admin_order_changes_add_with_options( $a, 2, array( $rush => '1' ) );
		$t::add( $b );
		$order  = $admin_order_changes_checkout();
		$before = $admin_order_changes_rows( $order->get_id() );
		$t::money( 15, isset( $before[ $a ] ) ? $before[ $a ][0]['price'] : null, 'print with rush saved at 15.00' );
		$t::money( 50, $order->get_total(), 'order total (2 x 15 + 20)' );

		$admin_order_changes_option_price( $a, $rush, '8' );
		$admin_order_changes_lose_line( $order->get_id(), $b );
		$admin_order_changes_fix_items( $order->get_id() );
		$after = $admin_order_changes_rows( $order->get_id() );
		$admin_order_changes_same_line( $before[ $a ][0], isset( $after[ $a ] ) ? $after[ $a ][0] : null, 'print with rush line' );
	}
);

// 6 prints at 10.00 with 10% off each from 5: 6.00 off, line 54.00. The rule is later raised
// to 20%. The line keeps the 6.00 it got.
$admin_order_changes_test(
	'Fix order lines: a quantity discount stays what was given after the rule changed',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_rows, $admin_order_changes_lose_line, $admin_order_changes_fix_items, $admin_order_changes_same_line, $admin_order_changes_qty_rule ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$a = $t::product(
			10,
			array(
				'taxable'      => false,
				'qty_discount' => $admin_order_changes_qty_rule( 10 ),
			)
		);
		$b = $t::product( 20, array( 'taxable' => false ) );
		$t::add( $a, 6 );
		$t::add( $b );
		$order  = $admin_order_changes_checkout();
		$before = $admin_order_changes_rows( $order->get_id() );
		$t::money( 6, $before[ $a ][0]['discount'], 'line discount when ordered' );
		$t::money( 74, $order->get_total(), 'order total (54 + 20)' );

		update_post_meta( $a, 'qty_discount', $admin_order_changes_qty_rule( 20 ) );
		$admin_order_changes_lose_line( $order->get_id(), $b );
		$admin_order_changes_fix_items( $order->get_id() );
		$after = $admin_order_changes_rows( $order->get_id() );
		$admin_order_changes_same_line( $before[ $a ][0], isset( $after[ $a ] ) ? $after[ $a ][0] : null, 'quantity discount line' );
	}
);

// The customer had 2 free prints from a session and ordered 3: 2 free, 1 at 10.00. The
// photographer clicks the link, and the photographer has no free prints of their own.
$admin_order_changes_test(
	'Fix order lines: free session prints stay free when the photographer rebuilds the lines',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_rows, $admin_order_changes_lose_line, $admin_order_changes_fix_items, $admin_order_changes_same_line, $admin_order_changes_give_included ) {
		$t::require_plugin( 'sunshine_session_fees_cart_item_discount', 'Session Fees add-on' );
		$customer_id = $t::customer();
		$a           = $t::product( 10, array( 'taxable' => false ) );
		$b           = $t::product( 20, array( 'taxable' => false ) );
		$admin_order_changes_give_included( $customer_id, $a, 2 );
		$t::add( $a, 3 );
		$t::add( $b );
		$order  = $admin_order_changes_checkout();
		$before = $admin_order_changes_rows( $order->get_id() );
		$t::money( 20, $before[ $a ][0]['discount'], '2 free prints when ordered' );
		$t::money( 30, $order->get_total(), 'order total (10 + 20)' );

		$admin_order_changes_lose_line( $order->get_id(), $b );
		$admin_order_changes_fix_items( $order->get_id() );
		$after = $admin_order_changes_rows( $order->get_id() );
		$admin_order_changes_same_line( $before[ $a ][0], isset( $after[ $a ] ) ? $after[ $a ][0] : null, 'print line with 2 free' );
	}
);

// With every line still there the link does nothing, even after the tax rate changed.
$admin_order_changes_test(
	'Fix order lines: an order with all its lines is left alone',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_rows, $admin_order_changes_fix_items, $admin_order_changes_same_line ) {
		$t::tax( 10 );
		$a = $t::product( 50 );
		$t::add( $a, 2 );
		$order  = $admin_order_changes_checkout();
		$before = $admin_order_changes_rows( $order->get_id() );

		$t::tax( 20 );
		$admin_order_changes_fix_items( $order->get_id() );
		$after = $admin_order_changes_rows( $order->get_id() );
		$t::same( $before[ $a ][0]['order_item_id'], isset( $after[ $a ] ) ? $after[ $a ][0]['order_item_id'] : null, 'same saved line' );
		$admin_order_changes_same_line( $before[ $a ][0], isset( $after[ $a ] ) ? $after[ $a ][0] : null, '50.00 print line' );
	}
);

/*
 * Packages add-on: the update for old Sunshine 2 orders.
 */

// The Packages add-on's order screen code only loads in wp-admin. It is loaded here once and its
// hooks are taken back off, then its update is called the way opening the order screen does.
$admin_order_changes_open_old_order = function ( $order_id ) use ( $t ) {
	$t::require_plugin( 'sunshine_packages_init', 'Packages add-on' );
	if ( ! function_exists( 'sunshine_package_update_order_items' ) ) {
		include_once dirname( ( new ReflectionFunction( 'sunshine_packages_init' ) )->getFileName() ) . '/admin/orders.php';
		remove_filter( 'sunshine_order_item_file_names', 'sunshine_package_order_item_file_names', 10 );
		remove_action( 'sunshine_order_item_extra', 'sunshine_package_admin_order_item_extra', 5 );
		remove_filter( 'sunshine_admin_order_items_custom_rows', 'sunshine_package_admin_order_items_custom_rows', 10 );
		remove_action( 'current_screen', 'sunshine_package_update_order_items' );
	}
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';

	$had_screen = array_key_exists( 'current_screen', $GLOBALS );
	$screen     = $had_screen ? $GLOBALS['current_screen'] : null;
	$_GET['post'] = (string) $order_id;
	$GLOBALS['current_screen'] = WP_Screen::get( 'sunshine-order' );
	try {
		sunshine_package_update_order_items();
	} finally {
		unset( $_GET['post'] );
		if ( $had_screen ) {
			$GLOBALS['current_screen'] = $screen;
		} else {
			unset( $GLOBALS['current_screen'] );
		}
	}
};

/**
 * An old Sunshine 2 order: 2 prints at 5.00 and a 30.00 package holding 2 of the print. Sunshine
 * 2 kept the lines in _sunshine_order_items. The core update copied them into the order lines
 * table without the package contents, and the Packages add-on adds those when the order screen
 * first opens. Both products cost more today (7.00 and 45.00).
 */
$admin_order_changes_test(
	'Packages update: an old order keeps its package price and quantity, and is updated once',
	function () use ( $t, $admin_order_changes_rows, $admin_order_changes_open_old_order ) {
		global $wpdb;
		$t::require_plugin( 'sunshine_packages_init', 'Packages add-on' );
		$level   = sunshine_get_default_price_level_id();
		$print   = $t::product( 5, array( 'taxable' => false ) );
		$package = $t::product(
			30,
			array(
				'type'    => 'package',
				'taxable' => false,
			)
		);
		$order_id = wp_insert_post(
			array(
				'post_type'   => 'sunshine-order',
				'post_status' => 'publish',
				'post_title'  => 'Order #old',
			)
		);
		$t::track( 'order', $order_id );
		$old_lines = array(
			array(
				'type'        => 'image',
				'image_id'    => 0,
				'qty'         => 2,
				'product_id'  => $print,
				'gallery_id'  => 0,
				'price_level' => $level,
				'price'       => 5,
			),
			array(
				'type'             => 'package',
				'image_id'         => 0,
				'qty'              => 1,
				'product_id'       => $package,
				'gallery_id'       => 0,
				'price_level'      => $level,
				'price'            => 30,
				'package_products' => array(
					array(
						'product_id' => $print,
						'qty'        => 2,
						'image_id'   => 0,
					),
				),
			),
		);
		update_post_meta( $order_id, '_sunshine_order_items', $old_lines );
		update_post_meta( $order_id, 'subtotal', 40 );
		update_post_meta( $order_id, 'total', 40 );
		// The rows the core update wrote: type, qty, product, price level and price.
		foreach ( array( array( 'print', $print, 2, 5 ), array( 'package', $package, 1, 30 ) ) as $line ) {
			$wpdb->insert(
				$wpdb->prefix . 'sunshine_order_items',
				array(
					'order_id'    => $order_id,
					'type'        => $line[0],
					'image_id'    => 0,
					'qty'         => $line[2],
					'product_id'  => $line[1],
					'gallery_id'  => 0,
					'price_level' => $level,
					'price'       => $line[3],
					'tax'         => 0,
					'discount'    => 0,
				)
			);
		}
		update_post_meta( $print, 'price', array( $level => '7' ) );
		update_post_meta( $package, 'price', array( $level => '45' ) );

		$admin_order_changes_open_old_order( $order_id );
		// Opening the order again must not add the package line a second time.
		$admin_order_changes_open_old_order( $order_id );

		$rows = $admin_order_changes_rows( $order_id );
		$t::same( 2, array_sum( array_map( 'count', $rows ) ), 'two lines' );
		$t::same( 1, isset( $rows[ $package ] ) ? count( $rows[ $package ] ) : 0, 'one package line' );
		if ( isset( $rows[ $package ] ) ) {
			$t::same( 1, (int) $rows[ $package ][0]['qty'], 'package quantity' );
			$t::money( 30, $rows[ $package ][0]['price'], 'package keeps its 30.00 price' );
			$t::money( 0, $rows[ $package ][0]['tax'], 'package tax' );
		}
		if ( isset( $rows[ $print ] ) ) {
			$t::same( 2, (int) $rows[ $print ][0]['qty'], 'print quantity' );
			$t::money( 5, $rows[ $print ][0]['price'], 'print keeps its 5.00 price' );
		}
		$t::same( 'yes', get_post_meta( $order_id, 'package_items_updated', true ), 'order marked as updated' );
		$lines_total = 0;
		foreach ( sunshine_get_order( $order_id )->get_items() as $item ) {
			$package_meta = $item->get_meta_value( 'package' );
			if ( 'package' === $item->get_type() ) {
				$t::same( true, ! empty( $package_meta ), 'package line lists what is in the package' );
			}
			$lines_total += (float) $item->get_price() * (int) $item->get_qty();
		}
		$t::money( 40, $lines_total, 'lines add up to the old order subtotal (2 x 5 + 30)' );
	}
);
