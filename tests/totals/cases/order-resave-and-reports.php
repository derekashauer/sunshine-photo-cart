<?php
/**
 * The same unpaid order saved a second time.
 *
 * When a customer reaches the payment step, the order is saved as Pending. If they go back,
 * change something and submit again, checkout saves that same Pending order again instead of
 * making a new one (SPC_Cart::init_order() and SPC_Order::update()). Whatever they changed, the
 * saved order must then match the new cart exactly: lines, totals, fees, gift cards, store
 * credit and payment method, with nothing left over from the first try.
 *
 * Orders are placed through the real checkout step with the Test payment method (or Offline,
 * turned on in memory). Nothing here reaches an outside service or sends an email: every
 * outgoing request and email is stopped while this file runs.
 */

$t = 'SPC_Totals_Test';

// ---------------------------------------------------------------------------------------------
// Keep this file to itself: no outside requests, no emails, no other agents' automatic
// discounts, and nothing left behind.
// ---------------------------------------------------------------------------------------------

$order_resave_blocked = array();
$order_resave_offline = function ( $pre, $args, $url ) use ( &$order_resave_blocked ) {
	if ( false !== $pre ) {
		return $pre;
	}
	$order_resave_blocked[] = $url;
	return new WP_Error( 'spc_totals_offline', 'The totals tests never reach outside services' );
};
$order_resave_no      = function () {
	return false;
};
add_filter( 'pre_http_request', $order_resave_offline, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', $order_resave_no, PHP_INT_MAX );
add_filter( 'sunshine_checkout_allow_order_notify', $order_resave_no, PHP_INT_MAX );

// Other agents test automatic discounts on this site at the same time. One of theirs must never
// land in these carts, so automatic discounts are not looked up while this file runs.
$order_resave_auto_hook = has_action( 'sunshine_set_discounts', 'sunshine_discounts_set_auto' );
if ( false !== $order_resave_auto_hook ) {
	remove_action( 'sunshine_set_discounts', 'sunshine_discounts_set_auto', $order_resave_auto_hook );
}

// Adding to the cart makes the Analytics add-on log a row, and for a logged in customer the
// Automated Emails add-on may queue an email. Remember the products and queued emails made here,
// so those rows can be deleted at the end of this file.
$order_resave_made_products = array();
$order_resave_made_emails   = array();
$order_resave_remember      = function ( $post_id, $post ) use ( &$order_resave_made_products, &$order_resave_made_emails ) {
	if ( 'sunshine-product' === $post->post_type ) {
		$order_resave_made_products[] = (int) $post_id;
	} elseif ( 'sunshine-email-log' === $post->post_type ) {
		$order_resave_made_emails[] = (int) $post_id;
	}
};
add_action( 'wp_insert_post', $order_resave_remember, 10, 2 );

// This site writes PHP warnings to a log file, not the screen. Note every warning, notice and
// deprecation raised while this file runs, and print them at the end (PHP still logs them too).
$order_resave_php_notes = array();
set_error_handler(
	function ( $level, $message, $file, $line ) use ( &$order_resave_php_notes ) {
		$order_resave_php_notes[ $message . ' (' . preg_replace( '#^.*/(sunshine-[^/]+/)#', '$1', $file ) . ':' . $line . ')' ] = true;
		return false;
	}
);

// Every order line ID these tests have seen. Saving an order again deletes its lines and adds
// new ones, so the old lines' details have to be cleaned up at the end of this file too (the
// run's own clean up only knows the lines that still exist).
$order_resave_seen_item_ids = array();

// ---------------------------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------------------------

// The line IDs saved for an order right now.
$order_resave_item_ids = function ( $order_id ) use ( &$order_resave_seen_item_ids ) {
	global $wpdb;
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT order_item_id FROM {$wpdb->prefix}sunshine_order_items WHERE order_id = %d ORDER BY order_item_id", $order_id ) ) );
	foreach ( $ids as $id ) {
		$order_resave_seen_item_ids[ $id ] = $id;
	}
	return $ids;
};

// How many detail rows (product name, options, cost and so on) are saved for these line IDs.
$order_resave_detail_rows = function ( $item_ids ) {
	global $wpdb;
	if ( empty( $item_ids ) ) {
		return 0;
	}
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sunshine_order_itemmeta WHERE order_item_id IN (" . implode( ',', array_map( 'intval', $item_ids ) ) . ')' );
};

// Place the order. The first call saves a new Pending order; later calls in the same test are
// the customer submitting again, which saves that same order again.
$order_resave_place = function ( $payment_method = 'test' ) use ( $t, $order_resave_item_ids ) {
	$order = $t::place_order( $payment_method );
	$order_resave_item_ids( $order->get_id() );
	return $order;
};

// The customer changes the quantity on the cart page (sunshine_update_cart()).
$order_resave_set_qty = function ( $hash, $qty ) {
	foreach ( SPC()->cart->get_cart() as $key => $item ) {
		if ( $item['hash'] === $hash ) {
			SPC()->cart->update_item_quantity( $key, $qty );
		}
	}
};

// The customer deletes a line on the cart page (sunshine_delete_cart_item()).
$order_resave_remove = function ( $hash ) {
	foreach ( SPC()->cart->get_cart() as $key => $item ) {
		if ( $item['hash'] === $hash ) {
			SPC()->cart->remove_item( $key );
			SPC()->cart->update_cart();
		}
	}
};

// The fees as plain numbers, by fee id. The cart keeps them as text with 2 decimals.
$order_resave_fees = function ( $fees ) {
	$plain = array();
	foreach ( (array) $fees as $id => $fee ) {
		$plain[ $id ] = round( (float) $fee['amount'], 2 );
	}
	ksort( $plain );
	return $plain;
};

// What the cart shows right before the customer submits again.
$order_resave_snapshot = function () use ( $order_resave_fees ) {
	$cart  = SPC()->cart;
	$lines = array();
	foreach ( (array) $cart->get_cart_items() as $line ) {
		$lines[] = array(
			'product'  => (int) $line->get_product_id(),
			'qty'      => (int) $line->get_qty(),
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
		'credits'  => (float) $cart->get_credits_applied(),
		'fees'     => $order_resave_fees( $cart->get_fees() ),
		'total'    => (float) $cart->get_total(),
		'lines'    => $lines,
	);
};

// The order saved again must match the cart as it is now, line for line.
$order_resave_matches_cart = function ( $order, $seen ) use ( $t, $order_resave_fees ) {
	$order = sunshine_get_order( $order->get_id() );
	$t::money( $seen['subtotal'], $order->get_subtotal(), 'order subtotal matches the cart' );
	$t::money( $seen['discount'], $order->get_discount(), 'order discount matches the cart' );
	$t::money( $seen['shipping'], $order->get_shipping(), 'order shipping matches the cart' );
	$t::money( $seen['tax'], $order->get_tax(), 'order tax matches the cart' );
	$t::money( $seen['credits'], $order->get_credits(), 'order credits match the cart' );
	$t::money( $seen['total'], $order->get_total(), 'order total matches the cart' );
	$t::same( $seen['fees'], $order_resave_fees( $order->get_fees() ), 'order fees match the cart' );

	$items = array_values( (array) $order->get_items() );
	$t::same( count( $seen['lines'] ), count( $items ), 'number of order lines' );
	foreach ( $seen['lines'] as $i => $line ) {
		if ( empty( $items[ $i ] ) ) {
			continue;
		}
		$t::same( $line['product'], (int) $items[ $i ]->get_product_id(), 'line ' . ( $i + 1 ) . ' product' );
		$t::same( $line['qty'], (int) $items[ $i ]->get_qty(), 'line ' . ( $i + 1 ) . ' quantity' );
		$t::money( $line['total'], $items[ $i ]->get_total(), 'line ' . ( $i + 1 ) . ' total' );
		$t::money( $line['tax'], $items[ $i ]->get_tax_total(), 'line ' . ( $i + 1 ) . ' tax' );
		$t::money( $line['discount'], $items[ $i ]->get_discount_total(), 'line ' . ( $i + 1 ) . ' discount' );
	}
};

// A payment method fee, the way the Payments settings save it (fee type, amount, name).
$order_resave_fee = function ( $method, $type, $amount, $name ) use ( $t ) {
	$t::option( $method . '_fee', $type );
	$t::option( $method . '_fee_amount', (string) $amount );
	$t::option( $method . '_fee_name', $name );
};

// The code of a gift card made with $t::gift_card(), as the customer typed it.
$order_resave_card_code = function ( $card_id ) {
	return (string) get_post_meta( $card_id, 'code', true );
};

// Each time Sunshine builds a shipping or delivery method object, the object adds a filter that
// is never taken off (already reported), so a long run gets slower with every cart. Take off the
// ones a test added once it is done.
$order_resave_without_new_filters = function ( $callback ) {
	global $wp_filter;
	$hooks  = array( 'sunshine_shipping_methods', 'sunshine_checkout_delivery_options', 'sunshine_delivery_methods', 'sunshine_options_shipping' );
	$before = array();
	foreach ( $hooks as $hook ) {
		$before[ $hook ] = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array();
	}
	try {
		return $callback();
	} finally {
		foreach ( $hooks as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback_id => $entry ) {
					if ( ! isset( $before[ $hook ][ $priority ][ $callback_id ] ) ) {
						remove_filter( $hook, $entry['function'], $priority );
					}
				}
			}
		}
	}
};

// ---------------------------------------------------------------------------------------------
// Lines
// ---------------------------------------------------------------------------------------------

// First try: 20 + 2 x 15 = 50 + 10% tax = 55.
// Then 3 of the 15 print and a new untaxed 8 print: 20 + 45 + 8 = 73, tax 10% of 65 = 6.50,
// total 79.50.
$t::test(
	'Saved again: a print added and a quantity changed',
	function () use ( $t, $order_resave_place, $order_resave_set_qty, $order_resave_snapshot, $order_resave_matches_cart ) {
		$t::tax( 10 );
		$a = $t::product( 20, array( 'name' => 'Resave 8x10' ) );
		$b = $t::product( 15, array( 'name' => 'Resave 5x7' ) );
		$c = $t::product(
			8,
			array(
				'name'    => 'Resave wallet',
				'taxable' => false,
			)
		);
		$t::add( $a );
		$b_item = $t::add( $b, 2 );
		$t::calculate();
		$first = $order_resave_place();
		$t::money( 55, $first->get_total(), 'first try total' );

		$order_resave_set_qty( $b_item['hash'], 3 );
		$t::add( $c );
		$t::new_request();
		$seen = $order_resave_snapshot();
		$t::money( 79.50, $seen['total'], 'cart total now (73 + 6.50 tax)' );

		$order = $order_resave_place();
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 73, $order->get_subtotal(), 'subtotal' );
		$t::money( 6.50, $order->get_tax(), 'tax on the 65 that is taxed' );
		$t::money( 79.50, $order->get_total(), 'total' );
		$order_resave_matches_cart( $order, $seen );
		$t::same( 'pending', $order->get_status(), 'still waiting for payment' );
	}
);

// First try: 20 + 2 x 15 = 50 + 10% tax = 55. The 5x7 line is deleted: 20 + 2 tax = 22.
$t::test(
	'Saved again: a line deleted',
	function () use ( $t, $order_resave_place, $order_resave_remove, $order_resave_snapshot, $order_resave_matches_cart, $order_resave_item_ids ) {
		$t::tax( 10 );
		$t::add( $t::product( 20 ) );
		$b_item = $t::add( $t::product( 15 ), 2 );
		$t::calculate();
		$first = $order_resave_place();
		$t::same( 2, count( $order_resave_item_ids( $first->get_id() ) ), 'first try saves 2 lines' );

		$order_resave_remove( $b_item['hash'] );
		$t::new_request();
		$seen  = $order_resave_snapshot();
		$order = $order_resave_place();
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 22, $order->get_total(), 'total (20 + 2 tax)' );
		$order_resave_matches_cart( $order, $seen );
		$t::same( 1, count( $order_resave_item_ids( $order->get_id() ) ), 'only 1 line is saved for the order' );
	}
);

// Saving again deletes the order's lines and saves new ones. The details saved with each old
// line (product name, category, options, image name, cost) must go with it. Left behind, they
// pile up in the database with every retry, and if the database ever hands out an old line
// number again (MySQL 5.7 and older MariaDB do after a restart) a new line picks up an old
// line's product name and options.
$t::test(
	'Saved again: the first try\'s line details are not left behind',
	function () use ( $t, $order_resave_place, $order_resave_set_qty, $order_resave_item_ids, $order_resave_detail_rows ) {
		$a_item = $t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$t::add( $t::product( 15, array( 'taxable' => false ) ) );
		$t::calculate();
		$first     = $order_resave_place();
		$first_ids = $order_resave_item_ids( $first->get_id() );
		$t::same( true, $order_resave_detail_rows( $first_ids ) > 0, 'the first try saved details for its lines' );

		$order_resave_set_qty( $a_item['hash'], 2 );
		$t::new_request();
		$order   = $order_resave_place();
		$now_ids = $order_resave_item_ids( $order->get_id() );
		$t::same( 2, count( $now_ids ), 'the order has 2 lines, not 4' );
		$old_ids = array_values( array_diff( $first_ids, $now_ids ) );
		$t::same( 2, count( $old_ids ), 'both lines were saved new' );
		$t::same( 0, $order_resave_detail_rows( $old_ids ), 'no details are left for the deleted lines' );
		$t::money( 55, $order->get_total(), 'total (2 x 20 + 15)' );
	}
);

// Prices include 20% tax. First try: 12, of which 2 is tax.
// Then 3 of them and a 6 print: 36 + 6 = 42, of which 42 / 6 = 7 is tax.
$t::test(
	'Saved again: prices include tax and the cart changed',
	function () use ( $t, $order_resave_place, $order_resave_set_qty, $order_resave_snapshot, $order_resave_matches_cart ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$a_item = $t::add( $t::product( 12 ) );
		$t::calculate();
		$first = $order_resave_place();
		$t::money( 12, $first->get_total(), 'first try total' );
		$t::money( 2, $first->get_tax(), 'first try tax' );

		$order_resave_set_qty( $a_item['hash'], 3 );
		$t::add( $t::product( 6 ) );
		$t::new_request();
		$seen  = $order_resave_snapshot();
		$order = $order_resave_place();
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 42, $order->get_total(), 'total (36 + 6)' );
		$t::money( 7, $order->get_tax(), 'tax inside the prices (42 / 6)' );
		$order_resave_matches_cart( $order, $seen );
	}
);

// ---------------------------------------------------------------------------------------------
// Gift cards
// ---------------------------------------------------------------------------------------------

// First try: 100 + 10 tax = 110, nothing taken off. Then a 30 gift card: 110 - 30 = 80.
$t::test(
	'Saved again: a gift card added',
	function () use ( $t, $order_resave_place, $order_resave_snapshot, $order_resave_matches_cart ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		$first = $order_resave_place();
		$t::money( 110, $first->get_total(), 'first try total' );

		$t::gift_card( 30 );
		$t::new_request();
		$seen  = $order_resave_snapshot();
		$order = $order_resave_place();
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 80, $order->get_total(), 'left to pay (110 - 30)' );
		$fees = (array) $order->get_fees();
		$t::money( -30, isset( $fees['gift_card'] ) ? $fees['gift_card']['amount'] : 0, 'gift card on the order' );
		$order_resave_matches_cart( $order, $seen );
		$t::same( 'test', $order->get_payment_method(), 'still paid with the chosen payment method' );
	}
);

// First try: 110 - 30 gift card = 80. The card is taken off: 110 again, no gift card on the order.
$t::test(
	'Saved again: a gift card taken off',
	function () use ( $t, $order_resave_place, $order_resave_snapshot, $order_resave_matches_cart, $order_resave_card_code ) {
		$t::require_plugin( 'sunshine_cart_remove_gift_card', 'Gift Cards add-on' );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$card_id = $t::gift_card( 30 );
		$t::calculate();
		$first = $order_resave_place();
		$t::money( 80, $first->get_total(), 'first try total (110 - 30)' );

		$t::same( true, sunshine_cart_remove_gift_card( $order_resave_card_code( $card_id ) ), 'card taken off the cart' );
		SPC()->cart->update();
		$t::new_request();
		$seen  = $order_resave_snapshot();
		$order = $order_resave_place();
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 110, $order->get_total(), 'total' );
		$t::same( false, isset( $order->get_fees()['gift_card'] ), 'no gift card on the order' );
		$order_resave_matches_cart( $order, $seen );
	}
);

// First try: 40 + 4 tax = 44, all paid by a 100 gift card, so the order is saved as Free.
// The card is taken off: 44 to pay with the payment method the customer picks.
$t::test(
	'Saved again: a gift card that paid for everything taken off',
	function () use ( $t, $order_resave_place, $order_resave_snapshot, $order_resave_matches_cart, $order_resave_card_code ) {
		$t::require_plugin( 'sunshine_cart_remove_gift_card', 'Gift Cards add-on' );
		$t::tax( 10 );
		$t::add( $t::product( 40 ) );
		$card_id = $t::gift_card( 100 );
		$t::calculate();
		$first = $order_resave_place();
		$t::money( 0, $first->get_total(), 'first try: nothing to pay' );
		$t::same( 'free', $first->get_payment_method(), 'first try is saved as Free' );

		sunshine_cart_remove_gift_card( $order_resave_card_code( $card_id ) );
		SPC()->cart->update();
		$t::new_request();
		$seen  = $order_resave_snapshot();
		$order = $order_resave_place();
		$order = sunshine_get_order( $order->get_id() );
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 44, $order->get_total(), 'total (40 + 4 tax)' );
		$t::same( 'test', $order->get_payment_method(), 'payment method is the one picked, not Free' );
		$t::same( 'Test', $order->get_payment_method_name(), 'payment method name' );
		$t::same( false, isset( $order->get_fees()['gift_card'] ), 'no gift card on the order' );
		$order_resave_matches_cart( $order, $seen );
	}
);

// First try: 100, all on a 200 gift card. A 50 print is added before submitting again: the card
// now pays 150. Once paid, the card must be charged what the final order used (150), not the
// first try's 100, leaving 50 on it.
$t::test(
	'Saved again, then paid: the gift card is charged what the final order used',
	function () use ( $t, $order_resave_place, $order_resave_snapshot, $order_resave_matches_cart ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$card_id = $t::gift_card( 200 );
		$t::calculate();
		$first = $order_resave_place();
		$t::money( -100, $first->get_fees()['gift_card']['amount'], 'first try: card pays 100' );

		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::new_request();
		$seen  = $order_resave_snapshot();
		$order = $order_resave_place();
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 0, $order->get_total(), 'nothing left to pay' );
		$t::money( -150, $order->get_fees()['gift_card']['amount'], 'card pays 150 on the order' );
		$order_resave_matches_cart( $order, $seen );

		// What the Gift Cards add-on does once the order is paid (both run on
		// sunshine_checkout_create_order). Called directly so other add-ons on that hook
		// (mailing lists, email queues) do not run on this shared site.
		sunshine_gift_cards_store_order_meta( $order, array() );
		sunshine_gift_cards_deduct_balance( sunshine_get_order( $order->get_id() ), array() );
		$t::money( 50, sunshine_get_gift_card( $card_id )->get_balance(), 'left on the card (200 - 150)' );
	}
);

// ---------------------------------------------------------------------------------------------
// Store credit
// ---------------------------------------------------------------------------------------------

// The customer has 20 in credits (whole dollars: credits with cents are already reported).
// First try uses them: 100 + 10 tax - 20 = 90. Then they untick "Use my credits": 110, and
// once paid their 20 credits are untouched.
$t::test(
	'Saved again: store credit turned off',
	function () use ( $t, $order_resave_place, $order_resave_snapshot, $order_resave_matches_cart ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', '20' );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		$first = $order_resave_place();
		$t::money( 20, $first->get_credits(), 'first try uses 20 credits' );
		$t::money( 90, $first->get_total(), 'first try total (110 - 20)' );

		SPC()->cart->set_use_credits( false );
		$t::new_request();
		$seen  = $order_resave_snapshot();
		$order = $order_resave_place();
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 0, $order->get_credits(), 'no credits on the order' );
		$t::money( 110, $order->get_total(), 'total' );
		$order_resave_matches_cart( $order, $seen );

		// The step that runs once the order is paid takes the order's credits off the customer.
		SPC()->cart->post_process_order( sunshine_get_order( $order->get_id() ) );
		$t::money( 20, ( new SPC_Customer( $customer_id ) )->get_credits(), 'the customer keeps all 20 credits' );
	}
);

// The reverse: first try without credits (110), then "Use my credits" is ticked: 110 - 20 = 90,
// and once paid the 20 credits are used up.
$t::test(
	'Saved again: store credit turned on',
	function () use ( $t, $order_resave_place, $order_resave_snapshot, $order_resave_matches_cart ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', '20' );
		SPC()->customer = new SPC_Customer( $customer_id );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		$first = $order_resave_place();
		$t::money( 110, $first->get_total(), 'first try total' );

		SPC()->cart->set_use_credits( true );
		$t::new_request();
		$seen  = $order_resave_snapshot();
		$order = $order_resave_place();
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 20, $order->get_credits(), 'credits on the order' );
		$t::money( 90, $order->get_total(), 'total (110 - 20)' );
		$order_resave_matches_cart( $order, $seen );

		SPC()->cart->post_process_order( sunshine_get_order( $order->get_id() ) );
		$t::money( 0, ( new SPC_Customer( $customer_id ) )->get_credits(), 'the 20 credits are used' );
	}
);

// ---------------------------------------------------------------------------------------------
// Payment method and its fee
// ---------------------------------------------------------------------------------------------

// The customer reaches the payment step, leaves, and comes back 5 days later (the checkout
// remembers them for 7 days) to submit again, maybe with a different cart. The order they
// place is dated the day they placed it. Assumed rule: the date moves to the last submit,
// since that is when the customer actually ordered. Kept at the first try's date, the receipt
// shows the wrong date and the Reports (Total Received, Tax Collected) put the sale on the
// abandoned day, which can be in a month whose sales tax was already filed.
$t::test(
	'Saved again days later: the order is dated when it was placed',
	function () use ( $t, $order_resave_place ) {
		global $wpdb;
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		$first     = $order_resave_place();
		$abandoned = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 5 * DAY_IN_SECONDS );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => $abandoned,
				'post_date_gmt' => get_gmt_from_date( $abandoned ),
			),
			array( 'ID' => $first->get_id() )
		);
		clean_post_cache( $first->get_id() );

		$t::add( $t::product( 25, array( 'taxable' => false ) ) );
		$t::new_request();
		$order = $order_resave_place();
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 125, $order->get_total(), 'total' );
		clean_post_cache( $order->get_id() );
		$t::same( current_time( 'Y-m-d' ), sunshine_get_order( $order->get_id() )->get_date( 'Y-m-d' ), 'order date is today, not 5 days ago' );
	}
);

// First try with Test (no fee): 100. Then Offline, which has a 2.50 handling fee: 102.50.
$t::test(
	'Saved again: switched to a payment method with a fixed fee',
	function () use ( $t, $order_resave_place, $order_resave_snapshot, $order_resave_matches_cart, $order_resave_fee ) {
		$t::option( 'offline_active', 1 );
		$order_resave_fee( 'offline', 'amount', 2.50, 'Handling fee' );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		$first = $order_resave_place( 'test' );
		$t::money( 100, $first->get_total(), 'first try total' );
		$t::same( array(), (array) $first->get_fees(), 'first try has no fees' );

		SPC()->cart->set_checkout_data_item( 'payment_method', 'offline' );
		$t::new_request();
		$seen  = $order_resave_snapshot();
		$order = $order_resave_place( 'offline' );
		$order = sunshine_get_order( $order->get_id() );
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 102.50, $order->get_total(), 'total (100 + 2.50 fee)' );
		$fees = (array) $order->get_fees();
		$t::money( 2.50, isset( $fees['payment_method'] ) ? $fees['payment_method']['amount'] : 0, 'fee on the order' );
		$t::same( 'Handling fee', isset( $fees['payment_method'] ) ? $fees['payment_method']['name'] : '', 'fee name' );
		$t::same( 'offline', $order->get_payment_method(), 'payment method' );
		$t::same( 'Offline', $order->get_payment_method_name(), 'payment method name' );
		// Test payments are always test orders; an Offline payment is a real one.
		$t::same( 'live', $order->get_mode(), 'the order is no longer a test order' );
		$order_resave_matches_cart( $order, $seen );
	}
);

// The customer's card is declined at the payment step. Stripe (and the checkout's "payment
// failed" step) marks the order Failed, and the customer stays on the checkout page with the
// same order. They pick Offline (pay by check) and submit again: the Failed order is saved
// again as their Offline order. A new Offline order waits as Pending until the check arrives,
// and this one must too. Left Failed, the photographer passes over it, and the Reports leave it
// out of the unpaid orders. 100 untaxed.
$t::test(
	'Saved again after a declined card: paying offline instead is waiting for payment, not Failed',
	function () use ( $t, $order_resave_place ) {
		$t::option( 'offline_active', 1 );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		$first = $order_resave_place( 'test' );
		$first->set_status( 'failed', 'Payment failed: Your card was declined.' );

		SPC()->cart->set_checkout_data_item( 'payment_method', 'offline' );
		$t::new_request();
		$order = $order_resave_place( 'offline' );
		$t::same( $first->get_id(), $order->get_id(), 'the Failed order is saved again' );
		$t::money( 100, $order->get_total(), 'total' );
		$t::same( 'offline', $order->get_payment_method(), 'payment method' );
		$t::same( 'pending', sunshine_get_order( $order->get_id() )->get_status(), 'saved as waiting for payment' );

		// What finishing the checkout does once the Offline "payment" step is done.
		SPC()->cart->post_process_order( sunshine_get_order( $order->get_id() ) );
		$t::same( 'pending', sunshine_get_order( $order->get_id() )->get_status(), 'still waiting for payment after checkout finishes' );
	}
);

// First try with Test, which has a 3% fee: 100 + 3 = 103. Then Offline with no fee: 100.
$t::test(
	'Saved again: switched away from a payment method with a percent fee',
	function () use ( $t, $order_resave_place, $order_resave_snapshot, $order_resave_matches_cart, $order_resave_fee ) {
		$t::option( 'offline_active', 1 );
		$order_resave_fee( 'test', 'percent', 3, 'Card fee' );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		$first = $order_resave_place( 'test' );
		$t::money( 103, $first->get_total(), 'first try total (100 + 3% fee)' );

		SPC()->cart->set_checkout_data_item( 'payment_method', 'offline' );
		$t::new_request();
		$seen  = $order_resave_snapshot();
		$order = $order_resave_place( 'offline' );
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 100, $order->get_total(), 'total' );
		$t::same( false, isset( $order->get_fees()['payment_method'] ), 'no fee on the order' );
		$order_resave_matches_cart( $order, $seen );
	}
);

// A 3% card fee. First try: 100 + 10 tax = 110, fee 3.30, total 113.30.
// A 50 print is added: 150 + 15 tax = 165, fee 4.95, total 169.95.
$t::test(
	'Saved again: a percent fee follows the bigger cart',
	function () use ( $t, $order_resave_place, $order_resave_snapshot, $order_resave_matches_cart, $order_resave_fee ) {
		$order_resave_fee( 'test', 'percent', 3, 'Card fee' );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		$first = $order_resave_place();
		$t::money( 113.30, $first->get_total(), 'first try total' );

		$t::add( $t::product( 50 ) );
		$t::new_request();
		$seen  = $order_resave_snapshot();
		$order = $order_resave_place();
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 4.95, $order->get_fees()['payment_method']['amount'], 'fee (3% of 165)' );
		$t::money( 169.95, $order->get_total(), 'total' );
		$order_resave_matches_cart( $order, $seen );
	}
);

// ---------------------------------------------------------------------------------------------
// Shipping and discount codes
// ---------------------------------------------------------------------------------------------

// Two flat rates, both taxed at 10%. First try with Ground (10): 100 + 10 + 11 tax = 121.
// Then Express (25): 100 + 25 + 12.50 tax = 137.50.
$t::test(
	'Saved again: a different shipping method',
	function () use ( $t, $order_resave_place, $order_resave_snapshot, $order_resave_matches_cart, $order_resave_without_new_filters ) {
		$order_resave_without_new_filters(
			function () use ( $t, $order_resave_place, $order_resave_snapshot, $order_resave_matches_cart ) {
				$t::tax( 10 );
				$t::option(
					'shipping_methods',
					array(
						'spcresaveground'  => array(
							'id'     => 'flat_rate',
							'active' => true,
						),
						'spcresaveexpress' => array(
							'id'     => 'flat_rate',
							'active' => true,
						),
					)
				);
				$rates = array(
					'spcresaveground'  => array( '10', 'Ground' ),
					'spcresaveexpress' => array( '25', 'Express' ),
				);
				foreach ( $rates as $instance => $settings ) {
					$t::option( 'flat_rate_price_' . $instance, $settings[0] );
					$t::option( 'flat_rate_price_type_' . $instance, '' );
					$t::option( 'flat_rate_taxable_' . $instance, '1' );
					$t::option( 'flat_rate_name_' . $instance, $settings[1] );
				}
				SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
				SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcresaveground' );
				$t::add( $t::product( 100 ) );
				$t::calculate();
				$first = $order_resave_place();
				$t::money( 121, $first->get_total(), 'first try total' );

				SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcresaveexpress' );
				$t::new_request();
				$seen  = $order_resave_snapshot();
				$order = $order_resave_place();
				$order = sunshine_get_order( $order->get_id() );
				$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
				$t::money( 25, $order->get_shipping(), 'shipping' );
				$t::money( 2.50, $order->get_shipping_tax(), 'tax on shipping' );
				$t::money( 12.50, $order->get_tax(), 'tax' );
				$t::money( 137.50, $order->get_total(), 'total' );
				$t::same( 'spcresaveexpress', $order->get_shipping_method(), 'shipping method' );
				$t::same( 'Express', $order->get_shipping_method_name(), 'shipping method name' );
				$order_resave_matches_cart( $order, $seen );
			}
		);
	}
);

// First try: a 30 print shipped flat rate 10, plus a 20 item marked "Disable Shipping"
// (no tax): 60. The print is deleted, so nothing needs shipping any more: 20, no shipping, and
// the order must not still say it ships by Flat Rate.
$t::test(
	'Saved again: nothing left that needs shipping',
	function () use ( $t, $order_resave_place, $order_resave_remove, $order_resave_snapshot, $order_resave_matches_cart, $order_resave_without_new_filters ) {
		$order_resave_without_new_filters(
			function () use ( $t, $order_resave_place, $order_resave_remove, $order_resave_snapshot, $order_resave_matches_cart ) {
				$t::flat_shipping( 10 );
				$t::option( 'flat_rate_name_spctestflat', 'Flat Rate' );
				$print   = $t::add( $t::product( 30, array( 'taxable' => false ) ) );
				$no_ship = $t::product( 20, array( 'taxable' => false ) );
				update_post_meta( $no_ship, 'disable_shipping', '1' );
				$t::add( $no_ship );
				$t::calculate();
				$first = $order_resave_place();
				$t::money( 60, $first->get_total(), 'first try total (30 + 20 + 10 shipping)' );
				$t::same( 'spctestflat', $first->get_shipping_method(), 'first try ships by flat rate' );

				$order_resave_remove( $print['hash'] );
				$t::new_request();
				$seen  = $order_resave_snapshot();
				$order = $order_resave_place();
				$order = sunshine_get_order( $order->get_id() );
				$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
				$t::money( 0, $order->get_shipping(), 'no shipping' );
				$t::money( 20, $order->get_total(), 'total' );
				$t::same( false, $order->get_shipping_method(), 'no shipping method on the order' );
				$t::same( false, $order->get_shipping_method_name(), 'no shipping method name on the order' );
				$order_resave_matches_cart( $order, $seen );
			}
		);
	}
);

// First try: 100 + 10 tax = 110. Then a 15% code: 15 off, tax on 85 = 8.50, total 93.50.
$t::test(
	'Saved again: a discount code added',
	function () use ( $t, $order_resave_place, $order_resave_snapshot, $order_resave_matches_cart ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		$first = $order_resave_place();
		$t::money( 110, $first->get_total(), 'first try total' );

		$code = $t::discount( 'percent-total', 15 );
		$t::new_request();
		$seen  = $order_resave_snapshot();
		$order = $order_resave_place();
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 15, $order->get_discount(), 'discount' );
		$t::money( 8.50, $order->get_tax(), 'tax on 85' );
		$t::money( 93.50, $order->get_total(), 'total' );
		$t::same( array( $code ), array_values( (array) $order->get_discounts() ), 'code saved on the order' );
		$order_resave_matches_cart( $order, $seen );
	}
);

// Discount after tax. First try: 100 + 10 tax = 110. Then a 20% code taken off the 110:
// 22 off, tax stays 10, total 88.
$t::test(
	'Saved again: a discount code added, discount after tax',
	function () use ( $t, $order_resave_place, $order_resave_snapshot, $order_resave_matches_cart ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		$first = $order_resave_place();
		$t::money( 110, $first->get_total(), 'first try total' );

		$t::discount( 'percent-total', 20 );
		$t::new_request();
		$seen  = $order_resave_snapshot();
		$order = $order_resave_place();
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 22, $order->get_discount(), 'discount (20% of 110)' );
		$t::money( 10, $order->get_tax(), 'tax' );
		$t::money( 88, $order->get_total(), 'total' );
		$order_resave_matches_cart( $order, $seen );
	}
);

// Two codes: 10% and 5 off, on 100 untaxed = 15 off, 85. The 10% code is taken off: 5 off, 95,
// and only the 5 off code stays on the order. (Taking off every code is a known problem, covered
// in core-discount-rules-orders.php.)
$t::test(
	'Saved again: one of two discount codes taken off',
	function () use ( $t, $order_resave_place, $order_resave_snapshot, $order_resave_matches_cart ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$percent = $t::discount( 'percent-total', 10 );
		$amount  = $t::discount( 'amount-total', 5 );
		$t::calculate();
		$first = $order_resave_place();
		$t::money( 85, $first->get_total(), 'first try total' );

		SPC()->cart->remove_discount( $percent );
		SPC()->cart->update();
		$t::new_request();
		$seen  = $order_resave_snapshot();
		$order = $order_resave_place();
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is saved again' );
		$t::money( 5, $order->get_discount(), 'discount' );
		$t::money( 95, $order->get_total(), 'total' );
		$t::same( array( $amount ), array_values( (array) $order->get_discounts() ), 'only the code still in the cart is on the order' );
		$order_resave_matches_cart( $order, $seen );
	}
);

// ---------------------------------------------------------------------------------------------
// Put everything back the way it was.
// ---------------------------------------------------------------------------------------------

remove_filter( 'pre_http_request', $order_resave_offline, PHP_INT_MAX );
remove_filter( 'pre_wp_mail', $order_resave_no, PHP_INT_MAX );
remove_filter( 'sunshine_checkout_allow_order_notify', $order_resave_no, PHP_INT_MAX );
remove_action( 'wp_insert_post', $order_resave_remember, 10 );
if ( false !== $order_resave_auto_hook ) {
	add_action( 'sunshine_set_discounts', 'sunshine_discounts_set_auto', $order_resave_auto_hook );
}
foreach ( array_unique( $order_resave_made_emails ) as $order_resave_email_id ) {
	wp_delete_post( $order_resave_email_id, true );
}
global $wpdb;
if ( ! empty( $order_resave_made_products ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sunshine_actions' ) ) ) {
	$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_actions WHERE action_type = 'add_to_cart' AND action_object_id IN (" . implode( ',', array_map( 'intval', array_unique( $order_resave_made_products ) ) ) . ')' );
}
// Details of order lines that were deleted when an order was saved again. Lines that still
// exist are cleaned up with their orders at the end of the run.
if ( ! empty( $order_resave_seen_item_ids ) ) {
	$order_resave_ids_in = implode( ',', array_map( 'intval', $order_resave_seen_item_ids ) );
	$order_resave_gone   = $wpdb->get_col( "SELECT DISTINCT m.order_item_id FROM {$wpdb->prefix}sunshine_order_itemmeta m LEFT JOIN {$wpdb->prefix}sunshine_order_items i ON i.order_item_id = m.order_item_id WHERE m.order_item_id IN ($order_resave_ids_in) AND i.order_item_id IS NULL" );
	if ( ! empty( $order_resave_gone ) ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_order_itemmeta WHERE order_item_id IN (" . implode( ',', array_map( 'intval', $order_resave_gone ) ) . ')' );
	}
	$order_resave_left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sunshine_order_itemmeta m LEFT JOIN {$wpdb->prefix}sunshine_order_items i ON i.order_item_id = m.order_item_id WHERE m.order_item_id IN ($order_resave_ids_in) AND i.order_item_id IS NULL" );
	if ( $order_resave_left > 0 ) {
		WP_CLI::log( 'order-resave-and-reports could not delete ' . $order_resave_left . ' leftover order line detail rows' );
	}
}
restore_error_handler();
if ( ! empty( $order_resave_php_notes ) ) {
	WP_CLI::log( 'order-resave-and-reports PHP warnings and notices: ' . implode( ' | ', array_keys( $order_resave_php_notes ) ) );
}
if ( ! empty( $order_resave_blocked ) ) {
	WP_CLI::log( 'order-resave-and-reports blocked outgoing requests to: ' . implode( ', ', array_unique( $order_resave_blocked ) ) );
}
