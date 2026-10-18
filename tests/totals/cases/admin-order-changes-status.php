<?php
/**
 * Order status changes the photographer makes by hand: on the order screen (pick a status and
 * press Update) or with "Change status to" in the orders list's Bulk actions.
 *
 * Marking an order paid must finish it exactly once: gift card balances and store credit come
 * off, gift cards bought are made, included prints and stock are used up, the code is counted.
 * Cancelling an order must give back what the order took. Helpers are in
 * admin-order-changes-helpers.php.
 *
 * Assumed rule for Cancelled (the docs don't spell it out): "How to process an order" tells the
 * photographer to use Cancelled/Refunded when the customer cancels, and the Gift Cards docs say a
 * refunded order gives the gift card balance back. So a cancelled order gives back what it took
 * (gift card balance, store credit, free prints, stock, the session date) and a gift card bought
 * on it can't be spent. What the order never took is never given back.
 */

$t = 'SPC_Totals_Test';

// The helpers load first when every admin-order-changes file runs; this lets one file run alone.
if ( ! isset( $admin_order_changes_test ) ) {
	require_once __DIR__ . '/admin-order-changes-helpers.php';
}

/*
 * Marking an order paid.
 */

// Offline (check or cash): everything happens at checkout while the order waits as Pending.
// When the cheque arrives the photographer marks it New, later Processing and Shipped. None
// of that may take anything a second time.
$admin_order_changes_test(
	'Mark paid: an Offline order marked New, then Processing and Shipped, takes nothing twice',
	function () use ( $t, $admin_order_changes_everything_cart, $admin_order_changes_checkout, $admin_order_changes_save_status, $admin_order_changes_check_finished, &$admin_order_changes_warnings ) {
		$ids   = $admin_order_changes_everything_cart();
		$order = $admin_order_changes_checkout( 'offline' );
		$t::same( 'pending', $order->get_status(), 'Offline order waits as Pending' );
		$t::money( 147, $order->get_total(), 'order total' );

		foreach ( array( 'new', 'processing', 'shipped' ) as $status ) {
			$order = $admin_order_changes_save_status( $order->get_id(), $status );
			$t::same( $status, $order->get_status(), 'status is now ' . $status );
			$admin_order_changes_check_finished( $order->get_id(), $ids, 'marked ' . $status );
		}
		$t::same( array(), $admin_order_changes_warnings, 'no PHP warnings while changing the status' );
	}
);

// Prices include 20% tax. 2 x 24.00 = 48.00 (8.00 tax inside), 6.00 off the order leaves 42.00
// (7.00 tax inside). A 10.00 gift card pays 10, 32.00 is left to pay by cheque. Marking it paid
// and moving it on must not change a cent of the order or the card.
$admin_order_changes_test(
	'Mark paid: an Offline order with prices that include tax keeps every total to the cent',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_save_status, $admin_order_changes_card_balance ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 24 ), 2 );
		$t::discount( 'amount-total', 6 );
		$card  = $t::gift_card( 10 );
		$order = $admin_order_changes_checkout( 'offline' );
		$t::money( 32, $order->get_total(), 'left to pay by cheque (48 - 6 - 10)' );
		$t::money( 7, $order->get_tax(), 'tax inside 42.00' );
		$t::money( 0, $admin_order_changes_card_balance( $card ), 'card used at checkout' );
		$saved = array(
			'subtotal' => $order->get_subtotal(),
			'discount' => $order->get_discount(),
			'tax'      => $order->get_tax(),
			'total'    => $order->get_total(),
		);

		foreach ( array( 'new', 'processing', 'shipped' ) as $status ) {
			$order = $admin_order_changes_save_status( $order->get_id(), $status );
			foreach ( $saved as $key => $value ) {
				$t::money( $value, call_user_func( array( $order, 'get_' . $key ) ), 'marked ' . $status . ': order ' . $key . ' unchanged' );
			}
			$t::money( 0, $admin_order_changes_card_balance( $card ), 'marked ' . $status . ': card still used once' );
			$t::same( 1, count( sunshine_get_gift_card( $card )->get_usage_log() ), 'marked ' . $status . ': card history has one use' );
		}
	}
);

// The customer was sent to PayPal and the payment never came back to the site (they paid the
// photographer another way, or PayPal's notice never arrived). The photographer sees the money
// and marks the order New. The order shows the gift card and store credit paying part of it, so
// they must come off now, and everything else a paid order does must happen too.
$admin_order_changes_test(
	'Mark paid: an order left at the payment page and marked New by the photographer is finished',
	function () use ( $t, $admin_order_changes_everything_cart, $admin_order_changes_leave_to_pay, $admin_order_changes_save_status, $admin_order_changes_check_finished, $admin_order_changes_card_balance ) {
		$ids   = $admin_order_changes_everything_cart();
		$order = $admin_order_changes_leave_to_pay( 'paypal-legacy' );
		$t::money( 147, $order->get_total(), 'order total' );
		// Nothing is taken while the order waits for the payment.
		$t::money( 25, $admin_order_changes_card_balance( $ids['card'] ), 'gift card untouched while waiting' );

		$order = $admin_order_changes_save_status( $order->get_id(), 'new' );
		$t::same( 'new', $order->get_status(), 'status is now New' );
		$admin_order_changes_check_finished( $order->get_id(), $ids, 'marked New by the photographer' );

		// Moving it on must not do anything a second time.
		$order = $admin_order_changes_save_status( $order->get_id(), 'shipped' );
		$admin_order_changes_check_finished( $order->get_id(), $ids, 'then marked Shipped' );
	}
);

// Same as above with Bulk actions on the orders list instead of the order screen.
// 100 + 10% tax = 110. A 30.00 card pays 30, 80 is left to pay at PayPal.
$admin_order_changes_test(
	'Mark paid: Bulk actions "Change status to New" on an order left at the payment page takes the gift card',
	function () use ( $t, $admin_order_changes_leave_to_pay, $admin_order_changes_bulk_status, $admin_order_changes_card_balance ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$card  = $t::gift_card( 30 );
		$order = $admin_order_changes_leave_to_pay( 'paypal-legacy' );
		$t::money( 80, $order->get_total(), 'left to pay at PayPal (110 - 30)' );

		$admin_order_changes_bulk_status( array( $order->get_id() ), 'new' );
		$order = sunshine_get_order( $order->get_id() );
		$t::same( 'new', $order->get_status(), 'status is now New' );
		$t::money( 80, $order->get_total(), 'order total unchanged' );
		$t::money( 0, $admin_order_changes_card_balance( $card ), 'gift card comes down from 30.00 to 0.00' );
		$t::same( 1, count( sunshine_get_gift_card( $card )->get_usage_log() ), 'gift card history shows the order' );
	}
);

/*
 * Cancelling an order.
 */

// 100 + 10% tax = 110. A 30.00 card pays 30 at checkout, 80 left to pay by cheque. The cheque
// never comes and the photographer cancels the order.
$admin_order_changes_test(
	'Cancel: an Offline order that was never paid gives the gift card balance back',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_save_status, $admin_order_changes_card_balance ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$card  = $t::gift_card( 30 );
		$order = $admin_order_changes_checkout( 'offline' );
		$t::money( 80, $order->get_total(), 'left to pay by cheque (110 - 30)' );
		$t::money( 0, $admin_order_changes_card_balance( $card ), 'card used at checkout' );

		$order = $admin_order_changes_save_status( $order->get_id(), 'cancelled' );
		$t::same( 'cancelled', $order->get_status(), 'status is now Cancelled' );
		$t::money( 80, $order->get_total(), 'order total unchanged' );
		$t::money( 30, $admin_order_changes_card_balance( $card ), 'card is back to 30.00' );
		$t::same( array(), sunshine_get_gift_card( $card )->get_usage_log(), 'the order is gone from the card history' );
	}
);

// 50.00 print, 20.00 card, 30.00 paid by card (Test payment). The photographer can't fill the
// order, cancels it and refunds the 30.00 in the payment service.
$admin_order_changes_test(
	'Cancel: a paid order gives the gift card balance back',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_save_status, $admin_order_changes_card_balance ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$card  = $t::gift_card( 20 );
		$order = $admin_order_changes_checkout();
		$t::same( true, $order->is_paid(), 'order is paid' );
		$t::money( 30, $order->get_total(), 'paid by card (50 - 20)' );
		$t::money( 0, $admin_order_changes_card_balance( $card ), 'card used' );

		$order = $admin_order_changes_save_status( $order->get_id(), 'cancelled' );
		$t::money( 20, $admin_order_changes_card_balance( $card ), 'card is back to 20.00' );
	}
);

// 50.00 print, 20 store credit, 30.00 left to pay by cheque.
$admin_order_changes_test(
	'Cancel: store credit used on the order comes back',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_save_status, $admin_order_changes_credits ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', '20' );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$order = $admin_order_changes_checkout( 'offline' );
		$t::money( 20, $order->get_credits(), 'credit used on the order' );
		$t::money( 30, $order->get_total(), 'left to pay (50 - 20)' );
		$t::money( 0, $admin_order_changes_credits( $customer_id ), 'credit used at checkout' );

		$admin_order_changes_save_status( $order->get_id(), 'cancelled' );
		$t::money( 20, $admin_order_changes_credits( $customer_id ), 'credit is back to 20' );
	}
);

// 3 free prints left from a session. 2 of them go on this order (plus a 20.00 product to pay
// for by cheque), so 1 is left. Cancelling gives the 2 back: 3 left.
$admin_order_changes_test(
	'Cancel: free prints from a session used on the order come back',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_save_status, $admin_order_changes_give_included, $admin_order_changes_included_left ) {
		$t::require_plugin( 'sunshine_session_fees_checkout_create_order', 'Session Fees add-on' );
		$customer_id = $t::customer();
		$print       = $t::product( 12, array( 'taxable' => false ) );
		$admin_order_changes_give_included( $customer_id, $print, 3 );
		$t::add( $print, 2 );
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$order = $admin_order_changes_checkout( 'offline' );
		$t::money( 20, $order->get_total(), 'only the 20.00 product to pay' );
		$t::same( 1, (int) $admin_order_changes_included_left( $customer_id, $print ), '1 free print left after checkout (3 - 2)' );

		$admin_order_changes_save_status( $order->get_id(), 'cancelled' );
		$t::same( 3, (int) $admin_order_changes_included_left( $customer_id, $print ), '3 free prints left after cancelling' );
	}
);

// 5 in stock, 2 sold on a cheque order that is then cancelled.
$admin_order_changes_test(
	'Cancel: Sell Anything stock comes back',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_save_status, $admin_order_changes_general ) {
		$general = $admin_order_changes_general( 15, 5 );
		$t::add( $general, 2 );
		$order = $admin_order_changes_checkout( 'offline' );
		$t::money( 30, $order->get_total(), 'order total (2 x 15)' );
		$t::same( 3, (int) get_post_meta( $general, 'stock', true ), 'stock 3 after checkout' );

		$admin_order_changes_save_status( $order->get_id(), 'cancelled' );
		$t::same( 5, (int) get_post_meta( $general, 'stock', true ), 'stock back to 5' );
	}
);

// A 100.00 session fee that includes 2 free prints, paid by card. Once paid the customer has the
// 2 prints. The photographer cancels the session order, so the 2 prints go away again.
$admin_order_changes_test(
	'Cancel: free prints a cancelled session order gave are taken back',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_save_status, $admin_order_changes_session, $admin_order_changes_included_left ) {
		$customer_id          = $t::customer();
		$print                = $t::product( 10, array( 'taxable' => false ) );
		list( $session, $date ) = $admin_order_changes_session( 100 );
		// The product screen's Session Fees box saves each included product as text.
		update_post_meta(
			$session,
			'session_products',
			array(
				array(
					'product' => (string) $print,
					'qty'     => '2',
				),
			)
		);
		$t::add( $session );
		$order = $admin_order_changes_checkout();
		$t::money( 100, $order->get_total(), 'order total' );
		$t::same( 2, (int) $admin_order_changes_included_left( $customer_id, $print ), '2 free prints once paid' );

		$admin_order_changes_save_status( $order->get_id(), 'cancelled' );
		$t::same( 0, (int) $admin_order_changes_included_left( $customer_id, $print ), 'free prints taken back' );
	}
);

// A paid session booking is cancelled with Bulk actions: the date must be free to book again.
$admin_order_changes_test(
	'Cancel: Bulk actions "Change status to Cancelled" frees the session date',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_bulk_status, $admin_order_changes_session, $admin_order_changes_add_session ) {
		$t::customer();
		list( $session, $date_id ) = $admin_order_changes_session( 100 );
		$admin_order_changes_add_session( $session, $date_id );
		$order = $admin_order_changes_checkout();
		$t::money( 100, $order->get_total(), 'order total' );
		$t::same( true, sunshine_get_session_date( $date_id )->is_booked(), 'date booked after the order' );

		$admin_order_changes_bulk_status( array( $order->get_id() ), 'cancelled' );
		$t::same( 'cancelled', sunshine_get_order( $order->get_id() )->get_status(), 'status is now Cancelled' );
		$t::same( false, sunshine_get_session_date( $date_id )->is_booked(), 'date is free to book again' );
	}
);

// The customer picked Offline, bought a 50.00 gift card and never paid. The card is made and
// emailed at checkout. Once the photographer cancels the order, the card must not be spendable.
$admin_order_changes_test(
	'Cancel: a gift card bought on an unpaid order can not be spent',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_save_status, $admin_order_changes_gift_card_product, $admin_order_changes_buy_gift_card, $admin_order_changes_cards_made ) {
		$admin_order_changes_buy_gift_card( $admin_order_changes_gift_card_product(), 50 );
		$order = $admin_order_changes_checkout( 'offline' );
		$t::same( 'pending', $order->get_status(), 'order waits for the cheque' );
		$t::money( 50, $order->get_total(), 'order total' );
		$t::same( 1, count( $admin_order_changes_cards_made( $order->get_id() ) ), 'card made at checkout' );

		$admin_order_changes_save_status( $order->get_id(), 'cancelled' );
		foreach ( $admin_order_changes_cards_made( $order->get_id() ) as $card_id => $balance ) {
			$t::money( 0, $balance, 'card bought on the cancelled order has nothing to spend' );
			$t::same( false, sunshine_get_gift_card( $card_id )->is_valid(), 'card bought on the cancelled order is not accepted' );
		}
	}
);

// Paid 50.00 for a gift card, then the photographer refunds the money in the payment service and
// marks the order Refunded. The card must not keep its 50.00.
$admin_order_changes_test(
	'Refunded: a gift card bought on a refunded order can not be spent',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_save_status, $admin_order_changes_gift_card_product, $admin_order_changes_buy_gift_card, $admin_order_changes_cards_made ) {
		$admin_order_changes_buy_gift_card( $admin_order_changes_gift_card_product(), 50 );
		$order = $admin_order_changes_checkout();
		$made  = $admin_order_changes_cards_made( $order->get_id() );
		$t::same( 1, count( $made ), 'one card made' );
		$t::money( 50, $made ? reset( $made ) : 0, 'card has 50.00 after the order' );

		$admin_order_changes_save_status( $order->get_id(), 'refunded' );
		foreach ( $admin_order_changes_cards_made( $order->get_id() ) as $card_id => $balance ) {
			$t::money( 0, $balance, 'card bought on the refunded order has nothing to spend' );
		}
	}
);

// Prices include 20% tax and the code comes off after tax: 3 x 12.99 = 38.97, 10% off is 3.90
// (3.897 to the cent), so 35.07. A 50.00 card pays it all and keeps 14.93. The photographer marks
// the order Refunded: the card is back to exactly 50.00.
$admin_order_changes_test(
	'Refunded: the gift card comes back to the cent when prices include tax and the code is after tax',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_save_status, $admin_order_changes_card_balance ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 12.99 ), 3 );
		$t::discount( 'percent-total', 10 );
		$card  = $t::gift_card( 50 );
		$order = $admin_order_changes_checkout( 'free' );
		$t::money( 0, $order->get_total(), 'nothing left to pay' );
		$t::money( 14.93, $admin_order_changes_card_balance( $card ), 'card keeps 50.00 - 35.07' );

		$admin_order_changes_save_status( $order->get_id(), 'refunded' );
		$t::money( 50, $admin_order_changes_card_balance( $card ), 'card is back to 50.00' );
		$t::same( array(), sunshine_get_gift_card( $card )->get_usage_log(), 'the order is gone from the card history' );
	}
);

// The order is cancelled by mistake and set back to New. Whatever cancelling gave back must be
// taken again, and only once: 110 order, 30.00 card, card ends at 0.00 with one use.
$admin_order_changes_test(
	'Cancel then New again: the gift card pays for the order once',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_save_status, $admin_order_changes_card_balance ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$card  = $t::gift_card( 30 );
		$order = $admin_order_changes_checkout( 'offline' );
		$admin_order_changes_save_status( $order->get_id(), 'cancelled' );
		$order = $admin_order_changes_save_status( $order->get_id(), 'new' );
		$t::same( 'new', $order->get_status(), 'status is New again' );
		$t::money( 80, $order->get_total(), 'order total unchanged' );
		$t::money( 0, $admin_order_changes_card_balance( $card ), 'card used for this order' );
		$t::same( 1, count( sunshine_get_gift_card( $card )->get_usage_log() ), 'card history has one use' );
	}
);

// An order left at the payment page never took anything, so cancelling it gives nothing back.
$admin_order_changes_test(
	'Cancel: an order whose payment never finished gives nothing back',
	function () use ( $t, $admin_order_changes_everything_cart, $admin_order_changes_leave_to_pay, $admin_order_changes_save_status, $admin_order_changes_card_balance, $admin_order_changes_included_left, $admin_order_changes_credits ) {
		$ids   = $admin_order_changes_everything_cart();
		$order = $admin_order_changes_leave_to_pay( 'paypal-legacy' );
		$admin_order_changes_save_status( $order->get_id(), 'cancelled' );

		$t::money( 25, $admin_order_changes_card_balance( $ids['card'] ), 'gift card stays at 25.00' );
		$t::same( 20, (int) $admin_order_changes_credits( $ids['customer'] ), 'store credit stays at 20' );
		$t::same( 2, (int) $admin_order_changes_included_left( $ids['customer'], $ids['print'] ), 'free prints stay at 2' );
		$t::same( 5, (int) get_post_meta( $ids['general'], 'stock', true ), 'stock stays at 5' );
		$t::same( false, sunshine_get_session_date( $ids['session_date'] )->is_booked(), 'session date stays free' );
	}
);
