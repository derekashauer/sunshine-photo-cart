<?php
/**
 * Gift Cards add-on: gift card balances changed through the REST API.
 *
 *   POST /gift-cards               make a card
 *   PUT  /gift-cards/{id}          change a card (balance, recipient, message ...)
 *   POST /gift-cards/{id}/usage    record money spent from a card, e.g. in the studio
 *
 * A gift card balance is money. The rules used here:
 * - Recording a use takes money off the card and never adds to it.
 * - A card can't give out more than it holds.
 * - Changing a card's details (recipient, message) never changes its balance.
 * - A balance set to 0 stays 0.
 */

$t = 'SPC_Totals_Test';
if ( ! isset( $apw_test ) ) {
	require __DIR__ . '/api-price-writes-helpers.php';
}

$apw_test(
	'Gift card usage through the API takes the amount off, and the cart uses what is left',
	function () use ( $t, $apw_rest, $apw_make_card, $apw_balance, $apw_use_card, $apw_card_applied ) {
		$card                  = $apw_make_card( array( 'original_amount' => 50 ) );
		$t::money( 50, $apw_balance( $card ), 'new card holds the original amount' );
		list( $status, $data ) = $apw_rest( 'POST', '/gift-cards/' . $card . '/usage', array( 'amount' => 20 ) );
		$t::same( 200, $status, 'usage recorded' );
		$t::money( 30, $apw_balance( $card ), 'balance: 50 - 20' );
		$log = isset( $data['usage_log'] ) ? (array) $data['usage_log'] : array();
		$t::money( 20, isset( $log[0]['amount'] ) ? $log[0]['amount'] : null, 'usage log amount' );
		$t::money( 30, isset( $log[0]['remaining'] ) ? $log[0]['remaining'] : null, 'usage log remaining' );

		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$apw_use_card( get_post_meta( $card, 'code', true ) );
		$cart = $t::calculate();
		// 100 + 10 tax = 110. The card pays the 30 left on it.
		$t::money( 30, $apw_card_applied(), 'taken from the card' );
		$t::money( 80, $cart->get_total(), 'left to pay' );
	}
);

$apw_test(
	'Gift card usage: a negative or non-number amount is refused',
	function () use ( $t, $apw_rest, $apw_refused, $apw_make_card, $apw_balance, $apw_use_card, $apw_card_applied ) {
		$card           = $apw_make_card( array( 'original_amount' => 50 ) );
		list( $status ) = $apw_rest( 'POST', '/gift-cards/' . $card . '/usage', array( 'amount' => -50 ) );
		$t::same( true, $apw_refused( $status ), 'negative amount refused (got ' . $status . ')' );
		$t::money( 50, $apw_balance( $card ), 'balance stays 50' );
		list( $status ) = $apw_rest( 'POST', '/gift-cards/' . $card . '/usage', array( 'amount' => 'twenty' ) );
		$t::same( true, $apw_refused( $status ), 'text amount refused (got ' . $status . ')' );
		$log = get_post_meta( $card, 'usage_log', true );
		$t::same( 0, is_array( $log ) ? count( $log ) : 0, 'nothing written to the usage log' );

		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$apw_use_card( get_post_meta( $card, 'code', true ) );
		$t::calculate();
		$t::money( 50, $apw_card_applied(), 'the card pays 50 at most' );
	}
);

// A $50 card can't pay $80. Either the API refuses the use (balance and log unchanged), or it
// records only the $50 the card really had. The log must never say more was spent than the card held.
$apw_test(
	'Gift card usage: more than the balance is refused or capped at the balance',
	function () use ( $t, $apw_rest, $apw_refused, $apw_make_card, $apw_balance ) {
		$card           = $apw_make_card( array( 'original_amount' => 50 ) );
		list( $status ) = $apw_rest( 'POST', '/gift-cards/' . $card . '/usage', array( 'amount' => 80 ) );
		$log            = get_post_meta( $card, 'usage_log', true );
		$spent          = 0;
		foreach ( is_array( $log ) ? $log : array() as $entry ) {
			$spent += (float) $entry['amount'];
		}
		if ( $apw_refused( $status ) ) {
			$t::money( 50, $apw_balance( $card ), 'refused: balance stays 50' );
			$t::money( 0, $spent, 'refused: nothing in the usage log' );
		} else {
			$t::money( 0, $apw_balance( $card ), 'balance used up' );
			$t::money( 50, $spent, 'usage log shows the 50 the card had, not 80' );
		}
	}
);

// The same rule for the card itself, whoever calls it: a refund gives back what the usage log
// says the card paid, so a log of 80 on a $50 card would later put 80 back on it.
$apw_test(
	'Gift card: a use bigger than the balance logs only what the card had',
	function () use ( $t, $apw_make_card, $apw_balance ) {
		$card_id = $apw_make_card( array( 'original_amount' => 50 ) );
		$card    = new SPC_Gift_Card( $card_id );
		$card->add_usage( 0, 80 );
		$log   = get_post_meta( $card_id, 'usage_log', true );
		$spent = 0;
		foreach ( is_array( $log ) ? $log : array() as $entry ) {
			$spent += (float) $entry['amount'];
		}
		$t::money( 0, $apw_balance( $card_id ), 'balance used up' );
		$t::money( 50, $spent, 'usage log shows the 50 the card had, not 80' );
	}
);

$apw_test(
	'Gift card update: a balance set to 0 stays 0',
	function () use ( $t, $apw_rest, $apw_make_card, $apw_balance, $apw_use_card, $apw_card_applied ) {
		$card                  = $apw_make_card( array( 'original_amount' => 50 ) );
		list( $status, $data ) = $apw_rest( 'PUT', '/gift-cards/' . $card, array( 'balance' => 0 ) );
		$t::same( 200, $status, 'update accepted' );
		$t::money( 0, isset( $data['balance'] ) ? $data['balance'] : null, 'balance the API reports' );
		$t::money( 0, $apw_balance( $card ), 'saved balance' );

		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$apw_use_card( get_post_meta( $card, 'code', true ) );
		$cart = $t::calculate();
		$t::money( 0, $apw_card_applied(), 'the card pays nothing' );
		$t::money( 100, $cart->get_total(), 'left to pay' );
	}
);

$apw_test(
	'Gift card update: a balance with cents is what the cart uses',
	function () use ( $t, $apw_rest, $apw_make_card, $apw_balance, $apw_use_card, $apw_card_applied ) {
		$card = $apw_make_card( array( 'original_amount' => 50 ) );
		$apw_rest( 'PUT', '/gift-cards/' . $card, array( 'balance' => 12.34 ) );
		$t::money( 12.34, $apw_balance( $card ), 'saved balance' );
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$apw_use_card( get_post_meta( $card, 'code', true ) );
		$cart = $t::calculate();
		// 110 with tax, less 12.34 = 97.66.
		$t::money( 12.34, $apw_card_applied(), 'taken from the card' );
		$t::money( 97.66, $cart->get_total(), 'left to pay' );
	}
);

$apw_test(
	'Gift card made through the API with cents pays exactly that, and the order matches',
	function () use ( $t, $apw_make_card, $apw_balance, $apw_use_card, $apw_card_applied, $apw_finish ) {
		$card = $apw_make_card( array( 'original_amount' => 40.25 ) );
		$t::money( 40.25, $apw_balance( $card ), 'balance' );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$apw_use_card( get_post_meta( $card, 'code', true ) );
		$cart = $t::calculate();
		// 100 + 10 tax = 110, less 40.25 = 69.75.
		$t::money( 40.25, $apw_card_applied(), 'taken from the card' );
		$t::money( 69.75, $cart->get_total(), 'left to pay' );
		$order = $t::place_order();
		$t::money( 69.75, $order->get_total(), 'order total' );
		$order = $apw_finish();
		$t::same( true, $order && $order->is_paid(), 'order finished and paid' );
		$t::money( 0, $apw_balance( $card ), 'card used up' );
	}
);

// A card spent at checkout has a balance of 0. Changing its recipient (or any other detail)
// through the API must not give the money back.
$apw_test(
	'Gift card update: changing the recipient of a used-up card does not refill it',
	function () use ( $t, $apw_rest, $apw_balance, $apw_finish, $apw_use_card, $apw_card_applied ) {
		$card = $t::gift_card( 50 );
		$t::add( $t::product( 80, array( 'taxable' => false ) ) );
		$cart = $t::calculate();
		// 80 - 50 card = 30 left to pay.
		$t::money( 30, $cart->get_total(), 'left to pay' );
		$t::place_order();
		$order = $apw_finish();
		$t::same( true, $order && $order->is_paid(), 'order finished and paid' );
		$t::money( 0, $apw_balance( $card ), 'card used up at checkout' );

		list( $status, $data ) = $apw_rest( 'PUT', '/gift-cards/' . $card, array( 'recipient_name' => 'Sam Rivera' ) );
		$t::same( 200, $status, 'update accepted' );
		$t::money( 0, $apw_balance( $card ), 'balance after changing the recipient' );
		$t::money( 0, isset( $data['balance'] ) ? $data['balance'] : null, 'balance the API reports' );

		// A new shopper tries the card.
		$t::reset();
		$t::add( $t::product( 80, array( 'taxable' => false ) ) );
		$apw_use_card( get_post_meta( $card, 'code', true ) );
		$t::calculate();
		$t::money( 0, $apw_card_applied(), 'the used-up card pays nothing' );
	}
);

foreach ( $apw_warnings as $apw_name => $apw_list ) {
	WP_CLI::log( 'WARN ' . $apw_name . ': ' . implode( ' | ', array_unique( $apw_list ) ) );
}
$apw_warnings = array();
