<?php
/**
 * Placed orders for things that change money later: gift cards bought, sessions bought, products
 * a session includes, and quantity discounts.
 *
 * Orders are placed through the real checkout step with the Test payment method, then finished
 * the way a paid order is (see $purchases_finish_order in addon-purchases-and-discounts-helpers.php).
 * Nothing leaves the site: web requests and emails are blocked for this whole file.
 */

$t = 'SPC_Totals_Test';

$purchases_offline_on();

/*
 * Gift cards bought in an order.
 */

$t::test(
	'Order: buying a gift card makes a card worth what was paid',
	function () use ( $t, $purchases_gift_card_product, $purchases_add_gift_card, $purchases_cart_snapshot, $purchases_order_matches_cart, $purchases_finish_order, $purchases_cards_from_order ) {
		$purchases_add_gift_card( $purchases_gift_card_product(), '75.25' );
		$t::calculate();
		$seen  = $purchases_cart_snapshot();
		$order = $t::place_order();
		$purchases_order_matches_cart( $order, $seen );
		$t::money( 75.25, $order->get_total(), 'paid for the card' );
		$t::money( 0, $order->get_tax(), 'no tax' );

		$order = $purchases_finish_order( $order );
		$cards = $purchases_cards_from_order( $order->get_id() );
		$t::same( 1, count( $cards ), 'one card made' );
		$t::money( 75.25, array_sum( $cards ), 'card balance' );
		foreach ( array_keys( $cards ) as $card_id ) {
			$t::money( 75.25, get_post_meta( $card_id, 'original_amount', true ), 'card original amount' );
		}
	}
);

// Two 50 cards on one line: the customer pays 100 and should get 100 of gift cards.
// Assumed form: one card per quantity, the way two cards bought on separate lines come out.
$t::test(
	'Order: buying 2 of the same gift card makes cards for the full 100 paid',
	function () use ( $t, $purchases_gift_card_product, $purchases_add_gift_card, $purchases_cart_snapshot, $purchases_order_matches_cart, $purchases_finish_order, $purchases_cards_from_order ) {
		$purchases_add_gift_card( $purchases_gift_card_product(), 50, 2 );
		$t::calculate();
		$seen  = $purchases_cart_snapshot();
		$order = $t::place_order();
		$purchases_order_matches_cart( $order, $seen );
		$t::money( 100, $order->get_total(), 'paid 2 x 50' );
		$items = array_values( (array) $order->get_items() );
		$t::money( 100, $items ? $items[0]->get_total() : null, 'order line total 2 x 50' );

		$order = $purchases_finish_order( $order );
		$cards = $purchases_cards_from_order( $order->get_id() );
		$t::money( 100, array_sum( $cards ), 'gift card value made' );
		$t::same( 2, count( $cards ), 'two cards made' );
		$codes = array_map(
			function ( $card_id ) {
				return get_post_meta( $card_id, 'code', true );
			},
			array_keys( $cards )
		);
		$t::same( 2, count( array_unique( $codes ) ), 'each card has its own code' );

		// Finishing the order again makes no extra cards.
		sunshine_gift_cards_create_from_order( sunshine_get_order( $order->get_id() ) );
		$t::same( 2, count( $purchases_cards_from_order( $order->get_id() ) ), 'still two cards after a second run' );

		// A cancelled order voids both cards, not only the first.
		$order->set_status( 'cancelled' );
		$t::money( 0, array_sum( $purchases_cards_from_order( $order->get_id() ) ), 'both cards voided on cancel' );
	}
);

// A 50 card bought with 30 left on another card: 20 to pay, the old card is used up.
$t::test(
	'Order: buying a gift card with another gift card',
	function () use ( $t, $purchases_gift_card_product, $purchases_add_gift_card, $purchases_finish_order, $purchases_cards_from_order ) {
		$old_card = $t::gift_card( 30 );
		$purchases_add_gift_card( $purchases_gift_card_product(), 50 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 20, $order->get_total(), 'left to pay' );

		$order = $purchases_finish_order( $order );
		$t::money( 0, get_post_meta( $old_card, 'balance', true ), 'old card used up' );
		$t::money( 50, array_sum( $purchases_cards_from_order( $order->get_id() ) ), 'new card is worth 50' );
	}
);

/*
 * Sessions bought in an order. A paid session gives the customer what it includes: prints they
 * get free later, store credit, and download credits.
 */

$t::test(
	'Order: a paid session gives its included prints, credits and download credits',
	function () use ( $t, $purchases_session_product, $purchases_add_session, $purchases_finish_order, $purchases_included_left ) {
		$customer_id = $t::customer();
		// Credits the photographer gave earlier. They are not used on this order.
		update_user_meta( $customer_id, 'sunshine_credits', '10' );
		SPC()->customer = new SPC_Customer( $customer_id );
		$print          = $t::product( 10 );
		$session        = $purchases_session_product(
			150,
			array(
				array( $print, 2 ),
				array( 'credits', 25 ),
				array( 'download_credits', 3 ),
			)
		);
		$purchases_add_session( $session );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 150, $order->get_total(), 'paid for the session' );

		$purchases_finish_order( $order );
		$t::money( 2, $purchases_included_left( $customer_id, $print ), '2 prints to get free later' );
		$t::money( 35, ( new SPC_Customer( $customer_id ) )->get_credits(), '10 already there + 25 from the session' );
		$t::same( 3, (int) get_user_meta( $customer_id, 'sunshine_download_credits', true ), '3 download credits' );
	}
);

// The photographer moves the paid order along: New, then Processing, then Shipped. Each is a
// separate page load in the admin. The session's credits are given once.
$t::test(
	'Order: moving a paid session order to Processing and Shipped does not give it again',
	function () use ( $t, $purchases_session_product, $purchases_add_session, $purchases_finish_order, $purchases_included_left ) {
		$customer_id = $t::customer();
		$print       = $t::product( 10 );
		$session     = $purchases_session_product(
			150,
			array(
				array( $print, 2 ),
				array( 'credits', 25 ),
				array( 'download_credits', 3 ),
			)
		);
		$purchases_add_session( $session );
		$t::calculate();
		$order = $purchases_finish_order( $t::place_order() );
		sunshine_get_order( $order->get_id() )->set_status( 'processing' );
		sunshine_get_order( $order->get_id() )->set_status( 'shipped' );

		$t::money( 25, ( new SPC_Customer( $customer_id ) )->get_credits(), 'credits from one session' );
		$t::same( 3, (int) get_user_meta( $customer_id, 'sunshine_download_credits', true ), 'download credits from one session' );
		$t::money( 2, $purchases_included_left( $customer_id, $print ), 'included prints from one session' );
	}
);

// Two sessions in one order (lines with no photo are never merged): 10 + 25 credits, 2 + 3
// download credits.
$t::test(
	'Order: two sessions in one order give both sets of credits',
	function () use ( $t, $purchases_session_product, $purchases_add_session, $purchases_finish_order ) {
		$customer_id = $t::customer();
		$purchases_add_session(
			$purchases_session_product(
				100,
				array(
					array( 'credits', 10 ),
					array( 'download_credits', 2 ),
				)
			)
		);
		$purchases_add_session(
			$purchases_session_product(
				100,
				array(
					array( 'credits', 25 ),
					array( 'download_credits', 3 ),
				)
			)
		);
		$t::calculate();
		$order = $t::place_order();
		$t::money( 200, $order->get_total(), 'paid for both sessions' );
		$purchases_finish_order( $order );
		$t::money( 35, ( new SPC_Customer( $customer_id ) )->get_credits(), 'credits 10 + 25' );
		$t::same( 5, (int) get_user_meta( $customer_id, 'sunshine_download_credits', true ), 'download credits 2 + 3' );
	}
);

// Assumed rule (no doc covers it): refunding a paid session takes back what it gave that the
// customer has not used yet, the way a refunded order gives a gift card its balance back.
// Nothing was used here, so the customer is left with nothing from the session.
$t::test(
	'Order: refunding a paid session takes back what it gave',
	function () use ( $t, $purchases_session_product, $purchases_add_session, $purchases_finish_order, $purchases_included_left ) {
		$customer_id = $t::customer();
		$print       = $t::product( 10 );
		$session     = $purchases_session_product(
			150,
			array(
				array( $print, 2 ),
				array( 'credits', 25 ),
				array( 'download_credits', 3 ),
			)
		);
		$purchases_add_session( $session );
		$t::calculate();
		$order = $purchases_finish_order( $t::place_order() );
		sunshine_get_order( $order->get_id() )->set_status( 'refunded' );

		$t::money( 0, $purchases_included_left( $customer_id, $print ), 'included prints taken back' );
		$t::money( 0, ( new SPC_Customer( $customer_id ) )->get_credits(), 'credits taken back' );
		$t::same( 0, (int) get_user_meta( $customer_id, 'sunshine_download_credits', true ), 'download credits taken back' );
	}
);

/*
 * Using included prints in an order.
 */

// 2 of print A are included and 3 are bought: 1 is paid for, none are left.
// 5 of print B are included and 2 are bought: both free, 3 are left.
$t::test(
	'Order: included prints used in an order come off what is left',
	function () use ( $t, $purchases_give_included, $purchases_finish_order, $purchases_included_left ) {
		$customer_id = $t::customer();
		$print_a     = $t::product( 10, array( 'taxable' => false ) );
		$print_b     = $t::product( 10, array( 'taxable' => false ) );
		$purchases_give_included(
			$customer_id,
			array(
				array( $print_a, 2 ),
				array( $print_b, 5 ),
			)
		);
		$t::add( $print_a, 3 );
		$t::add( $print_b, 2 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 10, $order->get_total(), 'only the third A is paid for' );
		$purchases_finish_order( $order );
		$t::money( 0, $purchases_included_left( $customer_id, $print_a ), 'no A left' );
		$t::money( 3, $purchases_included_left( $customer_id, $print_b ), '3 B left' );
	}
);

// Two earlier sessions each included 2 of the same print, so the cart gave 3 free out of 4.
// One is still owed.
$t::test(
	'Order: included prints from two sessions are used up one at a time',
	function () use ( $t, $purchases_give_included, $purchases_finish_order, $purchases_included_left ) {
		$customer_id = $t::customer();
		$print       = $t::product( 10, array( 'taxable' => false ) );
		$purchases_give_included(
			$customer_id,
			array(
				array( $print, 2, 9001 ),
				array( $print, 2, 9002 ),
			)
		);
		$t::add( $print, 3 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 0, $order->get_total(), 'all 3 free' );
		$purchases_finish_order( $order );
		$t::money( 1, $purchases_included_left( $customer_id, $print ), '4 included - 3 used' );
	}
);

// The session and a print it includes are bought together. The customer has no included prints
// yet at checkout, so the print is paid in full, and both included prints are still owed.
$t::test(
	'Order: buying a session and one of its prints together keeps both included prints',
	function () use ( $t, $purchases_session_product, $purchases_add_session, $purchases_finish_order, $purchases_included_left ) {
		$customer_id = $t::customer();
		$print       = $t::product( 10, array( 'taxable' => false ) );
		$purchases_add_session( $purchases_session_product( 150, array( array( $print, 2 ) ) ) );
		$t::add( $print, 1 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 160, $order->get_total(), '150 session + 10 print' );
		$purchases_finish_order( $order );
		$t::money( 2, $purchases_included_left( $customer_id, $print ), 'both included prints still owed' );
	}
);

/*
 * Quantity discounts in placed orders.
 */

// 5 at 10, 2 included, 2 off each at 5 or more: 2 free + 3 x 8 = 24, 10% tax 2.40.
$t::test(
	'Order: included prints and a quantity discount, saved the way the cart showed',
	function () use ( $t, $purchases_qty_rule, $purchases_give_included, $purchases_cart_snapshot, $purchases_order_matches_cart, $purchases_finish_order, $purchases_included_left ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$customer_id = $t::customer();
		$t::tax( 10 );
		$print = $t::product( 10, array( 'qty_discount' => $purchases_qty_rule( array( array( 5, '2' ) ) ) ) );
		$purchases_give_included( $customer_id, array( array( $print, 2 ) ) );
		$t::add( $print, 5 );
		$t::calculate();
		$seen = $purchases_cart_snapshot();
		$t::money( 26.40, $seen['total'], 'cart total' );
		$order = $t::place_order();
		$purchases_order_matches_cart( $order, $seen );
		$t::money( 26.40, $order->get_total(), 'order total' );
		$purchases_finish_order( $order );
		$t::money( 0, $purchases_included_left( $customer_id, $print ), 'both included prints used' );
	}
);

// 11 x 2.99 = 32.89, less 15% (4.9335) = 27.9565, tax 8% = 2.24, total 30.20.
$t::test(
	'Order: percent quantity discount, saved the way the cart showed',
	function () use ( $t, $purchases_qty_rule, $purchases_cart_snapshot, $purchases_order_matches_cart ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::tax( 8 );
		$t::add( $t::product( 2.99, array( 'qty_discount' => $purchases_qty_rule( array( array( 10, '15', 'percent' ) ) ) ) ), 11 );
		$t::calculate();
		$seen  = $purchases_cart_snapshot();
		$order = $t::place_order();
		$purchases_order_matches_cart( $order, $seen );
		$items = array_values( (array) $order->get_items() );
		$t::money( 30.20, $order->get_total(), 'order total' );
		$t::money( 27.96, $items ? $items[0]->get_total() : null, 'order line total' );
		$t::money( 2.24, $items ? $items[0]->get_tax_total() : null, 'order line tax' );
		$t::money( 4.93, $items ? $items[0]->get_discount_total() : null, 'order line discount' );
	}
);

// Wallet prints at 1.00, half off at 100 or more. 300 wallets = 150.
$t::test(
	'Order: a large quantity with a quantity discount saves every print',
	function () use ( $t, $purchases_qty_rule, $purchases_cart_snapshot, $purchases_order_matches_cart ) {
		global $wpdb;
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::add( $t::product( 1, array( 'taxable' => false, 'qty_discount' => $purchases_qty_rule( array( array( 100, '50', 'percent' ) ) ) ) ), 300 );
		$cart = $t::calculate();
		$t::money( 150, $cart->get_total(), 'cart total' );
		// Order lines used to keep the quantity in a column that stops at 255. The fix (commit
		// fb1f07f) widens it on the next version update, which a test site may not have had yet.
		$qty_type = $wpdb->get_var( "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$wpdb->prefix}sunshine_order_items' AND COLUMN_NAME = 'qty'" );
		if ( 'tinyint' === $qty_type ) {
			$t::skip( 'this site\'s order lines table still stops quantities at 255; it is widened on the next Sunshine version update' );
		}
		$seen  = $purchases_cart_snapshot();
		$order = $t::place_order();
		$purchases_order_matches_cart( $order, $seen );
		$items = array_values( (array) $order->get_items() );
		$t::same( 300, $items ? (int) $items[0]->get_qty() : null, 'order line quantity' );
		$t::money( 150, $items ? $items[0]->get_total() : null, 'order line total' );
	}
);

/*
 * An order that was placed keeps its prices. Opening it later (the customer's account page or
 * receipt) must show what was paid, whatever the customer has now.
 */

$t::test(
	'Order: keeps its prices after the customer later gets included prints',
	function () use ( $t, $purchases_give_included, $purchases_finish_order ) {
		$customer_id = $t::customer();
		$print       = $t::product( 10, array( 'taxable' => false ) );
		$t::add( $print, 2 );
		$t::calculate();
		$order = $purchases_finish_order( $t::place_order() );
		// A paid order empties the cart.
		SPC()->cart->empty_cart();
		// Later the customer pays for a session that includes 2 of this print.
		$purchases_give_included( $customer_id, array( array( $print, 2, 9003 ) ) );

		$items = array_values( (array) sunshine_get_order( $order->get_id() )->get_items() );
		$t::money( 20, $items ? $items[0]->get_total() : null, 'order line still 2 x 10' );
		$t::money( 0, $items ? $items[0]->get_discount_total() : null, 'no discount on the old line' );
	}
);

$t::test(
	'Order: keeps its prices when the customer has more of the print in the cart now',
	function () use ( $t, $purchases_qty_rule, $purchases_finish_order ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::customer();
		$print = $t::product( 10, array( 'taxable' => false, 'qty_discount' => $purchases_qty_rule( array( array( 5, '2' ) ) ) ) );
		$t::add( $print, 2 );
		$t::calculate();
		$order = $purchases_finish_order( $t::place_order() );
		SPC()->cart->empty_cart();
		// Shopping again: 5 in the cart now reaches the tier, which has nothing to do with the old order.
		$t::add( $print, 5 );
		$t::calculate();

		$items = array_values( (array) sunshine_get_order( $order->get_id() )->get_items() );
		$t::money( 20, $items ? $items[0]->get_total() : null, 'order line still 2 x 10' );
		$t::money( 0, $items ? $items[0]->get_discount_total() : null, 'no discount on the old line' );
	}
);

$purchases_offline_off();
