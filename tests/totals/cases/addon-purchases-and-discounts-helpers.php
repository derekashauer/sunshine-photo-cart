<?php
/**
 * Helpers shared by the addon-purchases-and-discounts case files: buying gift cards and session
 * fees, quantity discount rules, and products included in a session the customer paid for.
 *
 * This file's name sorts before the other two, so it loads first. Run the three together with
 * SPC_TOTALS_ONLY=addon-purchases-and-discounts.
 *
 * Every fixture is saved in the same shape the admin screens and the add-ons save it.
 */

$t = 'SPC_Totals_Test';

// Nothing in these tests may leave the site: every web request gets an error back, emails are
// stopped (buying a gift card emails it to the recipient), and order receipts are not sent.
$purchases_offline_http = function () {
	return new WP_Error( 'spc_totals_offline', 'Web requests are blocked while the totals tests run' );
};

$purchases_offline_on = function () use ( $purchases_offline_http ) {
	add_filter( 'pre_http_request', $purchases_offline_http, PHP_INT_MAX );
	add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
	add_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
};

$purchases_offline_off = function () use ( $purchases_offline_http ) {
	remove_filter( 'pre_http_request', $purchases_offline_http, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
	remove_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
};

// A gift card product the way the Gift Cards add-on makes one when it is turned on, and the way
// the product screen saves it: the price box is hidden so the price is empty, preset amounts,
// custom amounts allowed up to 1000. Taxable is empty unless a test says otherwise.
$purchases_gift_card_product = function ( $taxable = '' ) use ( $t ) {
	$t::require_plugin( 'sunshine_gift_card_add_to_cart_item', 'Gift Cards add-on' );
	$id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-product',
			'post_status' => 'publish',
			'post_title'  => 'Totals test gift card',
		)
	);
	$t::track( 'post', $id );
	update_post_meta( $id, 'type', 'gift-card' );
	update_post_meta( $id, 'price', array( sunshine_get_default_price_level_id() => '' ) );
	update_post_meta( $id, 'taxable', $taxable );
	update_post_meta( $id, 'gift_card_preset_amounts', '25,50,100,200,500' );
	update_post_meta( $id, 'gift_card_allow_custom', '1' );
	update_post_meta( $id, 'gift_card_max_amount', '1000' );
	return $id;
};

// Add a gift card to the cart the way the Gift Cards page does: the amount and the recipient
// come in the form post, and the card goes in with no photo and no options.
$purchases_add_gift_card = function ( $product_id, $amount, $qty = 1 ) {
	$_POST['gift_card_amount']          = (string) $amount;
	$_POST['gift_card_delivery']        = 'immediate';
	$_POST['gift_card_recipient_name']  = 'Totals Gift';
	$_POST['gift_card_recipient_email'] = 'totals-gift@example.com';
	try {
		$item = SPC()->cart->add_item( $product_id, 0, '', sunshine_get_default_price_level_id(), '', $qty );
	} finally {
		unset( $_POST['gift_card_amount'], $_POST['gift_card_delivery'], $_POST['gift_card_recipient_name'], $_POST['gift_card_recipient_email'] );
	}
	if ( empty( $item ) ) {
		throw new Exception( 'Could not add the gift card to the cart' );
	}
	return $item;
};

// Quantity discount tiers the way the product screen saves them: every value is the text typed
// in, and "Calculate quantities per line item" is only saved when it is ticked.
// Each tier is array( min qty, discount per item, 'amount' or 'percent' ).
$purchases_qty_rule = function ( $tiers, $per_line = false ) {
	$rule = array();
	if ( $per_line ) {
		$rule['calc'] = '1';
	}
	$rule['items'] = array();
	foreach ( $tiers as $tier ) {
		$rule['items'][] = array(
			'qty'      => (string) $tier[0],
			'discount' => (string) $tier[1],
			'type'     => isset( $tier[2] ) ? $tier[2] : 'amount',
		);
	}
	return $rule;
};

// A Session Fee product. What it includes is saved the way its Session Fees tab saves it:
// rows of a product ID (or "credits" / "download_credits") and a quantity, both as text.
// Each included row is array( product ID or 'credits' or 'download_credits', qty ).
$purchases_session_product = function ( $price, $included = array(), $taxable = false ) use ( $t ) {
	$t::require_plugin( 'sunshine_session_fees_cart_item_discount', 'Session Fees add-on' );
	$id   = $t::product(
		$price,
		array(
			'name'    => 'Totals test session',
			'type'    => 'session-fee',
			'taxable' => $taxable,
		)
	);
	$rows = array();
	foreach ( $included as $row ) {
		$rows[] = array(
			'product' => (string) $row[0],
			'qty'     => (string) $row[1],
		);
	}
	update_post_meta( $id, 'require_session', '' );
	update_post_meta( $id, 'session_products', $rows );
	return $id;
};

// Add a session fee to the cart the way the session fees page does (no date picked).
$purchases_add_session = function ( $product_id ) {
	$product = sunshine_get_product( $product_id );
	$meta    = array();
	if ( $product->get_included_products() ) {
		$meta['session_products'] = $product->get_included_products();
	}
	$item = SPC()->cart->add_item( $product_id, '', '', sunshine_get_default_price_level_id(), '', 1, '', false, $meta );
	if ( empty( $item ) ) {
		throw new Exception( 'Could not add the session fee to the cart' );
	}
	SPC()->cart->update_item_by_hash( $item['hash'], $item );
	return $item;
};

// Products a paid session left on the customer, in the shape sunshine_session_fees_status_change()
// saves them: the product ID and quantity from the session's tab, plus the order they came from.
// Each row is array( product ID, qty, order ID ).
$purchases_give_included = function ( $customer_id, $rows ) {
	$value = array();
	foreach ( $rows as $row ) {
		$value[] = array(
			'product'  => (string) $row[0],
			'qty'      => (string) $row[1],
			'order_id' => isset( $row[2] ) ? $row[2] : 0,
		);
	}
	update_user_meta( $customer_id, 'sunshine_session_products', $value );
	SPC()->customer = new SPC_Customer( $customer_id );
};

// How many of a product the customer can still get free. Counted the way the cart counts it:
// a row at zero or below gives nothing.
$purchases_included_left = function ( $customer_id, $product_id ) {
	$left = 0;
	foreach ( (array) get_user_meta( $customer_id, 'sunshine_session_products', true ) as $row ) {
		if ( is_array( $row ) && isset( $row['product'], $row['qty'] ) && $row['product'] == $product_id && $row['qty'] > 0 ) {
			$left += $row['qty'];
		}
	}
	return $left;
};

// Store credit typed on the customer's admin screen (saved as whole dollars), used at checkout.
$purchases_credits = function ( $customer_id, $amount ) {
	update_user_meta( $customer_id, 'sunshine_credits', (string) $amount );
	SPC()->customer = new SPC_Customer( $customer_id );
	SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
};

// The cart's lines. An empty cart gives false, and (array) false is a list with one false in it.
$purchases_lines = function ( $cart ) {
	$lines = $cart->get_cart_items();
	return $lines ? array_values( $lines ) : array();
};

$purchases_line_discounts = function ( $cart ) use ( $purchases_lines ) {
	$total = 0;
	foreach ( $purchases_lines( $cart ) as $line ) {
		$total += (float) $line->get_discount_total();
	}
	return $total;
};

$purchases_gift_card_applied = function () {
	return array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) );
};

// What happens once a placed order is paid (SPC_Cart::process_order): the paid status is set, which
// is when Session Fees hands out what a session includes, then the add-ons on
// sunshine_checkout_create_order run. Only the Gift Cards and Session Fees parts of that hook are
// called, in the order the hook runs them: the others (mailing lists, email queues, reports) would
// reach outside services or leave rows behind on this shared site.
// Gift cards made by the order are deleted at the end of the run.
$purchases_finish_order = function ( $order ) use ( $t ) {
	SPC()->cart->post_process_order( $order );
	$order = sunshine_get_order( $order->get_id() );
	// Newer versions run the order created step (gift cards, sessions) when the order is
	// finished, once. Older ones only ran it at the end of checkout, so run it here for them.
	$finishing_runs_it = method_exists( SPC()->cart, 'order_created' );
	if ( ! $finishing_runs_it && function_exists( 'sunshine_gift_cards_store_order_meta' ) ) {
		sunshine_gift_cards_store_order_meta( $order, array() );
		sunshine_gift_cards_create_from_order( $order, array() );
		sunshine_gift_cards_deduct_balance( $order, array() );
	}
	if ( ! $finishing_runs_it && function_exists( 'sunshine_session_fees_checkout_create_order' ) ) {
		sunshine_session_fees_checkout_create_order( $order );
	}
	if ( ! $finishing_runs_it && function_exists( 'sunshine_gift_cards_set_payment_method' ) ) {
		sunshine_gift_cards_set_payment_method( $order, array() );
	}
	$cards = get_posts(
		array(
			'post_type'      => 'sunshine-gift-card',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => 'purchase_order_id',
			'meta_value'     => $order->get_id(),
		)
	);
	foreach ( $cards as $card_id ) {
		$t::track( 'post', $card_id );
	}
	return sunshine_get_order( $order->get_id() );
};

// Gift cards an order made, as array( card ID => balance ).
$purchases_cards_from_order = function ( $order_id ) {
	$cards = get_posts(
		array(
			'post_type'      => 'sunshine-gift-card',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => 'purchase_order_id',
			'meta_value'     => $order_id,
		)
	);
	$balances = array();
	foreach ( $cards as $card_id ) {
		$balances[ $card_id ] = (float) get_post_meta( $card_id, 'balance', true );
	}
	return $balances;
};

// What the cart shows right before the customer places the order.
$purchases_cart_snapshot = function () use ( $purchases_lines ) {
	$cart  = SPC()->cart;
	$lines = array();
	foreach ( $purchases_lines( $cart ) as $line ) {
		$lines[] = array(
			'qty'      => (int) $line->get_qty(),
			'total'    => (float) $line->get_total(),
			'tax'      => (float) $line->get_tax_total(),
			'discount' => (float) $line->get_discount_total(),
		);
	}
	return array(
		'subtotal' => (float) $cart->get_subtotal(),
		'discount' => (float) $cart->get_discount(),
		'tax'      => (float) $cart->get_tax(),
		'total'    => (float) $cart->get_total(),
		'lines'    => $lines,
	);
};

// The saved order must match what the cart showed: the totals and every line.
$purchases_order_matches_cart = function ( $order, $seen ) use ( $t ) {
	$t::money( $seen['subtotal'], $order->get_subtotal(), 'order subtotal matches the cart' );
	$t::money( $seen['discount'], $order->get_discount(), 'order discount matches the cart' );
	$t::money( $seen['tax'], $order->get_tax(), 'order tax matches the cart' );
	$t::money( $seen['total'], $order->get_total(), 'order total matches the cart' );
	$items = array_values( (array) $order->get_items() );
	$t::same( count( $seen['lines'] ), count( $items ), 'number of order lines' );
	foreach ( $seen['lines'] as $i => $line ) {
		if ( empty( $items[ $i ] ) ) {
			continue;
		}
		$t::same( $line['qty'], (int) $items[ $i ]->get_qty(), 'line ' . ( $i + 1 ) . ' quantity matches the cart' );
		$t::money( $line['total'], $items[ $i ]->get_total(), 'line ' . ( $i + 1 ) . ' total matches the cart' );
		$t::money( $line['tax'], $items[ $i ]->get_tax_total(), 'line ' . ( $i + 1 ) . ' tax matches the cart' );
		$t::money( $line['discount'], $items[ $i ]->get_discount_total(), 'line ' . ( $i + 1 ) . ' discount matches the cart' );
	}
};
