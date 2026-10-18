<?php
/**
 * Spending the same money twice.
 *
 * A gift card balance, a customer's store credits and a discount code's last allowed use can
 * each pay for one order only. Here two checkouts use the same card, credits or code at the
 * same time, the way two shoppers (or one shopper on a laptop and a phone) can: order A is
 * placed and goes off to be paid, order B is placed and finished, and then order A finishes.
 *
 * The rule every test checks: the orders that end up paid never say they took more from a
 * card, an account or a code than the card, account or code really gave. Which order loses
 * out (A refused, A changed, or B never offered the money) is up to the plugin; the money must
 * add up either way.
 *
 * Orders are placed through the real checkout step (init_order) with the Test payment method.
 * They are finished the way the shopper's next page load finishes them (process_order), or the
 * way a payment provider's return page or webhook finishes them (post_process_order).
 * Nothing leaves the site: every web request, email and order receipt is stopped.
 */

$t = 'SPC_Totals_Test';

$double_spend_blocked  = array();
$double_spend_products = array();
$double_spend_emails   = array();
$double_spend_orders   = array();

// Adding to the cart and finishing an order make the Analytics add-on log rows in its own
// table, and the Automated Emails add-on may queue an email. Remember the products and
// queued emails this file makes, so they can be deleted at the end of this file.
$double_spend_remember = function ( $post_id, $post ) use ( &$double_spend_products, &$double_spend_emails ) {
	if ( 'sunshine-product' === $post->post_type ) {
		$double_spend_products[] = (int) $post_id;
	} elseif ( 'sunshine-email-log' === $post->post_type ) {
		$double_spend_emails[] = (int) $post_id;
	}
};
add_action( 'wp_insert_post', $double_spend_remember, 10, 2 );

// Run one test with every web request, email and order receipt stopped. Any request that was
// tried is noted and listed at the end.
$double_spend_test = function ( $name, $body ) use ( $t, &$double_spend_blocked ) {
	$t::test(
		$name,
		function () use ( $body, &$double_spend_blocked ) {
			$offline = function ( $pre, $args, $url ) use ( &$double_spend_blocked ) {
				$double_spend_blocked[] = $url;
				return new WP_Error( 'spc_totals_offline', 'The totals tests never reach outside services' );
			};
			$no      = function () {
				return false;
			};
			add_filter( 'pre_http_request', $offline, PHP_INT_MAX, 3 );
			add_filter( 'pre_wp_mail', $no, PHP_INT_MAX );
			add_filter( 'sunshine_checkout_allow_order_notify', $no, PHP_INT_MAX );
			try {
				$body();
			} finally {
				remove_filter( 'pre_http_request', $offline, PHP_INT_MAX );
				remove_filter( 'pre_wp_mail', $no, PHP_INT_MAX );
				remove_filter( 'sunshine_checkout_allow_order_notify', $no, PHP_INT_MAX );
			}
		}
	);
};

/*
 * Browsers. Everything a shopper's browser holds lives in the Sunshine session (a logged in
 * customer's cart lives on their account, so both of their devices see the same cart).
 * Saving those keys and putting them back is the same as switching to another browser.
 */

$double_spend_keys = array( 'cart', 'discounts', 'checkout_data', 'checkout_sections_completed', 'gift_cards', 'gift_cards_applied', 'gift_card_amounts', 'use_credits', 'checkout_order_id', 'paypal_order_id' );

// Remember the browser in use now.
$double_spend_browser = function () use ( $double_spend_keys ) {
	$session = array();
	foreach ( $double_spend_keys as $key ) {
		$session[ $key ] = SPC()->session->get( $key );
	}
	return array(
		'user'    => get_current_user_id(),
		'session' => $session,
	);
};

// Switch to a remembered browser, or to a new one (empty session) for $user_id when none is
// given, and load a page there.
$double_spend_open = function ( $browser = null, $user_id = 0 ) use ( $double_spend_keys ) {
	$user = $browser ? $browser['user'] : $user_id;
	foreach ( $double_spend_keys as $key ) {
		SPC()->session->set( $key, $browser ? $browser['session'][ $key ] : '' );
	}
	wp_set_current_user( $user );
	SPC()->customer = new SPC_Customer( $user );
	SPC()->cart     = new SPC_Cart();
	SPC()->cart->setup( true );
};

// Place the order through the real checkout step. It is saved unpaid while the shopper goes
// off to pay for it.
$double_spend_place = function () use ( $t, &$double_spend_orders ) {
	SPC()->cart->setup( true );
	$order                 = $t::place_order();
	$double_spend_orders[] = $order->get_id();
	return $order;
};

// The shopper's next page load after paying: the cart is built again, then the checkout
// finishes the order it is holding (what process_payment and the Stripe return page do).
// Returns the order, or false when the checkout refused to finish it.
$double_spend_finish = function () {
	SPC()->cart = new SPC_Cart();
	SPC()->cart->setup( true );
	$order = SPC()->cart->process_order();
	return $order ? sunshine_get_order( $order->get_id() ) : false;
};

// A payment provider's webhook: a request with no shopper session and nobody logged in that
// finishes the order with post_process_order, the way the Stripe, Square, Mollie, PayFast,
// Paystack, QuickPay, Systempay and Mercado Pago webhooks do. The shopper's browser is put
// back afterwards.
$double_spend_webhook = function ( $order_id ) use ( $double_spend_browser, $double_spend_open ) {
	$shopper = $double_spend_browser();
	$double_spend_open( null, 0 );
	SPC()->cart->post_process_order( sunshine_get_order( $order_id ) );
	$double_spend_open( $shopper );
};

// A payment provider's return page in the shopper's own browser, the way the PayFast and
// QuickPay success pages finish an order: post_process_order, then the cart is emptied.
$double_spend_return_page = function ( $order_id ) {
	SPC()->cart = new SPC_Cart();
	SPC()->cart->setup( true );
	SPC()->cart->post_process_order( sunshine_get_order( $order_id ) );
	SPC()->session->set( 'checkout_data', '' );
	SPC()->session->set( 'checkout_sections_completed', '' );
	SPC()->cart->empty_cart();
	SPC()->session->set( 'checkout_order_id', '' );
};

/*
 * Gift cards. Read straight from the saved card, the same as the card's admin screen.
 */

$double_spend_code_of = function ( $card_id ) {
	return get_post_meta( $card_id, 'code', true );
};

// What the Apply button does with a gift card code.
$double_spend_apply_card = function ( $code ) use ( $t ) {
	$t::same( true, (bool) sunshine_cart_add_gift_card( $code ), 'gift card accepted' );
	SPC()->cart->update();
};

$double_spend_balance = function ( $card_id ) {
	return (float) get_post_meta( $card_id, 'balance', true );
};

// What the card's usage history says it paid for one order.
$double_spend_card_gave = function ( $card_id, $order_id ) {
	$total = 0;
	$log   = get_post_meta( $card_id, 'usage_log', true );
	foreach ( is_array( $log ) ? $log : array() as $entry ) {
		if ( (int) $entry['order_id'] === (int) $order_id ) {
			$total += (float) $entry['amount'];
		}
	}
	return $total;
};

// What the order shows was paid by gift card: the Gift Card line on the order and receipt.
$double_spend_order_card = function ( $order_id ) {
	$fees = (array) sunshine_get_order( $order_id )->get_fees();
	return isset( $fees['gift_card'] ) ? -1 * (float) $fees['gift_card']['amount'] : 0.0;
};

$double_spend_cart_card = function () {
	return array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) );
};

// An order the checkout refused to finish is passed as 0, and is not paid.
$double_spend_paid = function ( $order_id ) {
	return ! empty( $order_id ) && sunshine_get_order( $order_id )->is_paid();
};

// The rule for gift cards: every paid order's Gift Card line matches what the cards' usage
// history says they paid for it, all of it together matches what came off the cards, and no
// card goes below zero.
$double_spend_check_cards = function ( $card_ids, $orders ) use ( $t, $double_spend_paid, $double_spend_order_card, $double_spend_card_gave, $double_spend_balance ) {
	$shown = 0;
	foreach ( $orders as $label => $order_id ) {
		if ( ! $double_spend_paid( $order_id ) ) {
			continue;
		}
		$order_shows = $double_spend_order_card( $order_id );
		$cards_gave  = 0;
		foreach ( $card_ids as $card_id ) {
			$cards_gave += $double_spend_card_gave( $card_id, $order_id );
		}
		$t::money( $order_shows, $cards_gave, $label . ': gift card money the order shows vs what the card gave it' );
		$shown += $order_shows;
	}
	$taken = 0;
	foreach ( $card_ids as $card_id ) {
		$taken += (float) get_post_meta( $card_id, 'original_amount', true ) - $double_spend_balance( $card_id );
		$t::same( true, $double_spend_balance( $card_id ) >= 0, 'card balance is not below zero' );
	}
	$t::money( $shown, $taken, 'gift card money on the paid orders vs money that came off the cards' );
};

/*
 * Store credits. The customer screen saves whole numbers, so whole dollars are used here
 * (credits losing their cents is a separate, known problem).
 */

$double_spend_give_credits = function ( $customer_id, $amount ) {
	$customer = new SPC_Customer( $customer_id );
	$customer->set_credits( $amount );
};

$double_spend_credits_left = function ( $customer_id ) {
	return ( new SPC_Customer( $customer_id ) )->get_credits();
};

// The "Use my credits" box on the payment step.
$double_spend_use_credits = function () {
	SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
	SPC()->cart->update();
};

// The rule for credits: the credits on the paid orders add up to what came off the account.
$double_spend_check_credits = function ( $customer_id, $start, $orders ) use ( $t, $double_spend_paid, $double_spend_credits_left ) {
	$on_orders = 0;
	foreach ( $orders as $order_id ) {
		if ( $double_spend_paid( $order_id ) ) {
			$on_orders += (float) sunshine_get_order( $order_id )->get_credits();
		}
	}
	$left = $double_spend_credits_left( $customer_id );
	$t::same( true, $left >= 0, 'credits are not below zero' );
	$t::money( $on_orders, $start - $left, 'credits on the paid orders vs credits that came off the account' );
};

/*
 * Discount codes, made the way the Discounts screen saves them: every field, empty ones as ''
 * and product and gallery lists as arrays. The code is not put in the cart.
 */
$double_spend_make_code = function ( $meta = array() ) use ( $t ) {
	$t::require_plugin( 'sunshine_get_discount_by_code', 'Discount Codes add-on' );
	$code = 'SPCDOUBLE' . wp_rand( 100000, 999999 );
	$id   = wp_insert_post(
		array(
			'post_type'   => 'sunshine-discount',
			'post_status' => 'publish',
			'post_title'  => 'Double spend test ' . $code,
		)
	);
	$t::track( 'post', $id );
	$meta = array_merge(
		array(
			'auto'                  => '',
			'code'                  => $code,
			'discount_type'         => 'percent-total',
			'discount_amount'       => '25',
			'apply_to_shipping'     => '',
			'free_shipping'         => '',
			'start_date'            => '',
			'end_date'              => '',
			'solo'                  => '',
			'required_products'     => array(),
			'allowed_products'      => array(),
			'disallowed_products'   => array(),
			'allowed_categories'    => '',
			'disallowed_categories' => '',
			'allowed_galleries'     => array(),
			'disallowed_galleries'  => array(),
			'min_amount'            => '',
			'min_quantity'          => '',
			'max_uses'              => '',
			'max_uses_per_person'   => '',
			'max_product_quantity'  => '',
		),
		$meta
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return array( $id, $meta['code'] );
};

// What the checkout's Apply button does with a discount code. True when it was accepted.
$double_spend_enter_code = function ( $code ) {
	$result = SPC()->cart->add_discount( $code );
	SPC()->cart->update();
	return ! empty( $result );
};

$double_spend_uses = function ( $discount_id ) {
	return (int) get_post_meta( $discount_id, 'use_count', true );
};

// The rule for a code's uses: no more paid orders carry the code than its Max Uses, they
// take off no more than that many uses are worth, and the use count says the same.
$double_spend_check_code = function ( $discount_id, $code, $max_uses, $one_use_worth, $orders ) use ( $t, $double_spend_paid, $double_spend_uses ) {
	$with_code = 0;
	$taken_off = 0;
	foreach ( $orders as $order_id ) {
		if ( ! $double_spend_paid( $order_id ) ) {
			continue;
		}
		$order = sunshine_get_order( $order_id );
		if ( in_array( $code, (array) $order->get_discounts(), true ) ) {
			$with_code++;
			$taken_off += (float) $order->get_discount();
		}
	}
	$t::same( $max_uses, $with_code, 'paid orders that got the code' );
	$t::money( $max_uses * $one_use_worth, $taken_off, 'discount given on the paid orders' );
	$t::same( true, $double_spend_uses( $discount_id ) <= $max_uses, 'use count (' . $double_spend_uses( $discount_id ) . ') is within Max Uses (' . $max_uses . ')' );
};

/*
 * The normal path first: one order at a time. These show the checks above pass when the money
 * really is spent once.
 */

$double_spend_test(
	'Double spend: a finished order takes from the gift card exactly what the order shows',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_balance, $double_spend_order_card, $double_spend_check_cards ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$card = $t::gift_card( 80 );
		$cart = $t::calculate();
		// 100 + 10 tax = 110. The card pays 80, 30 is left to pay.
		$t::money( 30, $cart->get_total(), 'left to pay' );
		$order = $double_spend_place();
		$t::money( 80, $double_spend_order_card( $order->get_id() ), 'Gift Card line on the order' );
		$t::money( 80, $double_spend_balance( $card ), 'nothing comes off the card until the order is paid' );
		$order = $double_spend_finish();
		$t::same( true, $order && $order->is_paid(), 'order finished and paid' );
		$t::money( 0, $double_spend_balance( $card ), 'card used up' );
		$double_spend_check_cards( array( $card ), array( 'the order' => $order->get_id() ) );
	}
);

$double_spend_test(
	'Double spend: a second order only gets what is left on the gift card',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_open, $double_spend_apply_card, $double_spend_code_of, $double_spend_balance, $double_spend_cart_card, $double_spend_check_cards ) {
		$t::add( $t::product( 30, array( 'taxable' => false ) ) );
		$card = $t::gift_card( 50 );
		$t::calculate();
		$first = $double_spend_place();
		$first = $double_spend_finish();
		$t::money( 20, $double_spend_balance( $card ), 'left on the card (50 - 30)' );

		// Someone else uses the same card later.
		$double_spend_open( null, 0 );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$double_spend_apply_card( $double_spend_code_of( $card ) );
		$cart = $t::calculate();
		$t::money( 20, $double_spend_cart_card(), 'the card pays only the 20 left' );
		$t::money( 80, $cart->get_total(), 'left to pay' );
		$second = $double_spend_place();
		$second = $double_spend_finish();
		$t::money( 0, $double_spend_balance( $card ), 'card used up' );
		$double_spend_check_cards(
			array( $card ),
			array(
				'first order'  => $first->get_id(),
				'second order' => $second->get_id(),
			)
		);
	}
);

$double_spend_test(
	'Double spend: a second order only gets the credits that are left',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_give_credits, $double_spend_use_credits, $double_spend_credits_left, $double_spend_check_credits ) {
		$customer = $t::customer();
		$double_spend_give_credits( $customer, 50 );
		SPC()->customer = new SPC_Customer( $customer );
		$t::add( $t::product( 30, array( 'taxable' => false ) ) );
		$double_spend_use_credits();
		$first = $double_spend_place();
		$t::money( 30, $first->get_credits(), 'credits on the first order' );
		$first = $double_spend_finish();
		$t::money( 20, $double_spend_credits_left( $customer ), 'credits left (50 - 30)' );

		SPC()->customer = new SPC_Customer( $customer );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$double_spend_use_credits();
		$cart = $t::new_request();
		$t::money( 20, $cart->get_credits_applied(), 'only the 20 left are used' );
		$t::money( 80, $cart->get_total(), 'left to pay' );
		$second = $double_spend_place();
		$second = $double_spend_finish();
		$double_spend_check_credits( $customer, 50, array( $first->get_id(), $second->get_id() ) );
	}
);

// The payment provider can tell the site about a payment twice (the shopper's return and a
// webhook), or the shopper can reload the page. Only the first one may take the money.
$double_spend_test(
	'Double spend: finishing the same order again takes nothing more from the card, credits or code',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_webhook, $double_spend_give_credits, $double_spend_use_credits, $double_spend_make_code, $double_spend_enter_code, $double_spend_uses, $double_spend_balance, $double_spend_credits_left, $double_spend_check_cards, $double_spend_check_credits ) {
		$customer = $t::customer();
		$double_spend_give_credits( $customer, 20 );
		SPC()->customer = new SPC_Customer( $customer );
		list( $discount, $code ) = $double_spend_make_code( array( 'discount_amount' => '10' ) );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$card = $t::gift_card( 30 );
		$t::calculate();
		$t::same( true, $double_spend_enter_code( $code ), 'code accepted' );
		$double_spend_use_credits();
		$cart = $t::new_request();
		// 100 - 10% = 90. The card pays 30, credits 20, 40 left to pay.
		$t::money( 40, $cart->get_total(), 'left to pay' );
		$order = $double_spend_place();
		$order = $double_spend_finish();
		$t::same( true, $order && $order->is_paid(), 'order finished' );
		// The webhook for the same payment arrives afterwards.
		$double_spend_webhook( $order->get_id() );
		// And the provider's return page runs it once more.
		SPC()->cart->post_process_order( sunshine_get_order( $order->get_id() ) );
		$t::money( 0, $double_spend_balance( $card ), 'card used once' );
		$t::money( 0, $double_spend_credits_left( $customer ), 'credits used once' );
		$t::same( 1, $double_spend_uses( $discount ), 'code counted once' );
		$double_spend_check_cards( array( $card ), array( 'the order' => $order->get_id() ) );
		$double_spend_check_credits( $customer, 20, array( $order->get_id() ) );
	}
);

/*
 * Two checkouts at once. Order A is placed (saved unpaid while the shopper pays for it),
 * order B is placed and finished with the same card, credits or code, then order A finishes.
 */

$double_spend_test(
	'Double spend: a gift card used up by another order while the first is being paid',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_browser, $double_spend_open, $double_spend_apply_card, $double_spend_code_of, $double_spend_balance, $double_spend_order_card, $double_spend_check_cards ) {
		$product = $t::product( 100, array( 'taxable' => false ) );
		$t::add( $product );
		$card = $t::gift_card( 40 );
		$t::calculate();
		$a = $double_spend_place();
		// 100 - 40 card = 60 to pay.
		$t::money( 40, $double_spend_order_card( $a->get_id() ), 'order A: Gift Card line' );
		$t::money( 60, $a->get_total(), 'order A: total to pay' );
		$shopper_a = $double_spend_browser();

		// A second shopper with the same card code checks out and finishes first.
		$double_spend_open( null, 0 );
		$t::add( $product );
		$double_spend_apply_card( $double_spend_code_of( $card ) );
		$t::calculate();
		$b = $double_spend_place();
		$b = $double_spend_finish();
		$t::same( true, $b && $b->is_paid(), 'order B finished' );
		$t::money( 0, $double_spend_balance( $card ), 'card used up by order B' );

		// Shopper A's payment goes through and they come back to the site.
		$double_spend_open( $shopper_a );
		$a = $double_spend_finish();
		$double_spend_check_cards(
			array( $card ),
			array(
				'order A' => $a ? $a->get_id() : 0,
				'order B' => $b->get_id(),
			)
		);
	}
);

// The card still has some money when order A finishes, just not all that order A shows.
$double_spend_test(
	'Double spend: a gift card partly used by another order, with tax, taxed shipping and a code',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_browser, $double_spend_open, $double_spend_apply_card, $double_spend_code_of, $double_spend_balance, $double_spend_order_card, $double_spend_cart_card, $double_spend_make_code, $double_spend_enter_code, $double_spend_check_cards ) {
		$t::tax( 10 );
		$t::flat_shipping( 10, true );
		list( , $code ) = $double_spend_make_code( array( 'discount_amount' => '20' ) );
		$t::add( $t::product( 100 ) );
		$card = $t::gift_card( 60 );
		$t::calculate();
		$t::same( true, $double_spend_enter_code( $code ), 'code accepted' );
		$cart = $t::new_request();
		// 100 - 20 = 80, + 8 tax, + 10 shipping, + 1 shipping tax = 99. The card pays 60.
		$t::money( 39, $cart->get_total(), 'order A: left to pay' );
		$a = $double_spend_place();
		$t::money( 60, $double_spend_order_card( $a->get_id() ), 'order A: Gift Card line' );
		$shopper_a = $double_spend_browser();

		// 25 + 2.50 tax + 10 shipping + 1 shipping tax = 38.50, all paid by the card.
		$double_spend_open( null, 0 );
		$t::flat_shipping( 10, true );
		$t::add( $t::product( 25 ) );
		$double_spend_apply_card( $double_spend_code_of( $card ) );
		$cart = $t::calculate();
		$t::money( 38.50, $double_spend_cart_card(), 'order B: paid by the card' );
		$t::money( 0, $cart->get_total(), 'order B: nothing left to pay' );
		$b = $double_spend_place();
		$b = $double_spend_finish();
		$t::money( 21.50, $double_spend_balance( $card ), 'left on the card after order B (60 - 38.50)' );

		$double_spend_open( $shopper_a );
		$a = $double_spend_finish();
		$double_spend_check_cards(
			array( $card ),
			array(
				'order A' => $a ? $a->get_id() : 0,
				'order B' => $b->get_id(),
			)
		);
	}
);

$double_spend_test(
	'Double spend: two gift cards on one order, one of them used up by another order',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_browser, $double_spend_open, $double_spend_apply_card, $double_spend_code_of, $double_spend_balance, $double_spend_order_card, $double_spend_check_cards ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$first_card  = $t::gift_card( 30 );
		$second_card = $t::gift_card( 30 );
		$t::calculate();
		$a = $double_spend_place();
		// 100 - 30 - 30 = 40 to pay.
		$t::money( 60, $double_spend_order_card( $a->get_id() ), 'order A: Gift Card line' );
		$t::money( 40, $a->get_total(), 'order A: total to pay' );
		$shopper_a = $double_spend_browser();

		$double_spend_open( null, 0 );
		$t::add( $t::product( 40, array( 'taxable' => false ) ) );
		$double_spend_apply_card( $double_spend_code_of( $second_card ) );
		$t::calculate();
		$b = $double_spend_place();
		$b = $double_spend_finish();
		$t::money( 0, $double_spend_balance( $second_card ), 'second card used up by order B' );

		$double_spend_open( $shopper_a );
		$a = $double_spend_finish();
		$t::money( 0, $double_spend_balance( $first_card ), 'first card paid its 30 to order A' );
		$double_spend_check_cards(
			array( $first_card, $second_card ),
			array(
				'order A' => $a ? $a->get_id() : 0,
				'order B' => $b->get_id(),
			)
		);
	}
);

// $110 with 10% tax inside, 10% off after tax = 99. The card pays 50, 49 is left to pay.
$double_spend_test(
	'Double spend: a gift card used up by another order, prices include tax and discount after tax',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_browser, $double_spend_open, $double_spend_apply_card, $double_spend_code_of, $double_spend_balance, $double_spend_order_card, $double_spend_make_code, $double_spend_enter_code, $double_spend_check_cards ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::option( 'discount_after_tax', 1 );
		list( , $code ) = $double_spend_make_code( array( 'discount_amount' => '10' ) );
		$t::add( $t::product( 110 ) );
		$card = $t::gift_card( 50 );
		$t::calculate();
		$t::same( true, $double_spend_enter_code( $code ), 'code accepted' );
		$cart = $t::new_request();
		$t::money( 49, $cart->get_total(), 'order A: left to pay' );
		$a = $double_spend_place();
		$t::money( 50, $double_spend_order_card( $a->get_id() ), 'order A: Gift Card line' );
		$shopper_a = $double_spend_browser();

		// $55 with tax inside. The card pays 50, 5 is left to pay.
		$double_spend_open( null, 0 );
		$t::add( $t::product( 55 ) );
		$double_spend_apply_card( $double_spend_code_of( $card ) );
		$cart = $t::calculate();
		$t::money( 5, $cart->get_total(), 'order B: left to pay' );
		$b = $double_spend_place();
		$b = $double_spend_finish();
		$t::money( 0, $double_spend_balance( $card ), 'card used up by order B' );

		$double_spend_open( $shopper_a );
		$a = $double_spend_finish();
		$double_spend_check_cards(
			array( $card ),
			array(
				'order A' => $a ? $a->get_id() : 0,
				'order B' => $b->get_id(),
			)
		);
	}
);

// One customer account, two devices. A logged in customer's cart is kept on their account,
// so the phone shows the same cart the laptop is paying for. Order A is paid through a
// payment provider that sends the customer back to its return page (PayFast and QuickPay
// finish the order there). The checkout's own finishing step is not used for order A: order
// B emptied the cart both devices share, so the laptop's checkout drops to the Free payment
// method and will not finish an order that still has money to pay.
$double_spend_test(
	'Double spend: store credits spent on a second device while the first order is being paid',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_return_page, $double_spend_browser, $double_spend_open, $double_spend_give_credits, $double_spend_use_credits, $double_spend_credits_left, $double_spend_check_credits ) {
		$customer = $t::customer();
		$double_spend_give_credits( $customer, 30 );
		SPC()->customer = new SPC_Customer( $customer );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$double_spend_use_credits();
		$a = $double_spend_place();
		// 100 - 30 credits = 70 to pay.
		$t::money( 30, $a->get_credits(), 'order A: credits used' );
		$t::money( 70, $a->get_total(), 'order A: total to pay' );
		$laptop = $double_spend_browser();

		$double_spend_open( null, $customer );
		$double_spend_use_credits();
		$cart = $t::calculate();
		$t::money( 30, $cart->get_credits_applied(), 'order B: credits used' );
		$b = $double_spend_place();
		$b = $double_spend_finish();
		$t::money( 0, $double_spend_credits_left( $customer ), 'credits used up by order B' );

		$double_spend_open( $laptop );
		$double_spend_return_page( $a->get_id() );
		$t::same( true, sunshine_get_order( $a->get_id() )->is_paid(), 'order A finished' );
		$double_spend_check_credits( $customer, 30, array( $a->get_id(), $b->get_id() ) );
	}
);

// "Max Uses: the maximum number of times this discount code can be used across all customers.
// Once reached, the code stops working."
$double_spend_test(
	'Double spend: a code with one use left, used by two shoppers at the same time',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_browser, $double_spend_open, $double_spend_make_code, $double_spend_enter_code, $double_spend_check_code ) {
		list( $discount, $code ) = $double_spend_make_code( array( 'max_uses' => '1' ) );
		$product                 = $t::product( 100, array( 'taxable' => false ) );
		$t::add( $product );
		$t::calculate();
		$t::same( true, $double_spend_enter_code( $code ), 'shopper A: code accepted' );
		$a = $double_spend_place();
		// 25% off 100.
		$t::money( 75, $a->get_total(), 'order A: total to pay' );
		$shopper_a = $double_spend_browser();

		$double_spend_open( null, 0 );
		$t::add( $product );
		$t::calculate();
		$t::same( true, $double_spend_enter_code( $code ), 'shopper B: code accepted (not used yet)' );
		$b = $double_spend_place();
		$b = $double_spend_finish();

		$double_spend_open( $shopper_a );
		$a = $double_spend_finish();
		$double_spend_check_code( $discount, $code, 1, 25, array( $a ? $a->get_id() : 0, $b->get_id() ) );
	}
);

$double_spend_test(
	'Double spend: a code with two uses, three shoppers check out at the same time',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_browser, $double_spend_open, $double_spend_make_code, $double_spend_enter_code, $double_spend_check_code ) {
		$t::tax( 8 );
		list( $discount, $code ) = $double_spend_make_code(
			array(
				'discount_type'   => 'amount-total',
				'discount_amount' => '15',
				'max_uses'        => '2',
			)
		);
		$product                 = $t::product( 60 );
		$browsers                = array();
		$orders                  = array();
		foreach ( array( 'A', 'B', 'C' ) as $shopper ) {
			$double_spend_open( null, 0 );
			$t::add( $product );
			$t::calculate();
			$t::same( true, $double_spend_enter_code( $code ), 'shopper ' . $shopper . ': code accepted' );
			$order = $double_spend_place();
			// 60 - 15 = 45, + 3.60 tax.
			$t::money( 48.60, $order->get_total(), 'order ' . $shopper . ': total to pay' );
			$orders[ $shopper ]   = $order->get_id();
			$browsers[ $shopper ] = $double_spend_browser();
		}
		foreach ( array( 'C', 'B', 'A' ) as $shopper ) {
			$double_spend_open( $browsers[ $shopper ] );
			$double_spend_finish();
		}
		$double_spend_check_code( $discount, $code, 2, 15, array_values( $orders ) );
	}
);

/*
 * Going back and placing order A again after the money was spent elsewhere. The checkout
 * works the cart out again and saves order A again, so order A must lose the money that is
 * no longer there.
 */

$double_spend_test(
	'Double spend: placing order A again after its gift card was used up asks for the full amount',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_browser, $double_spend_open, $double_spend_apply_card, $double_spend_code_of, $double_spend_order_card, $double_spend_check_cards ) {
		$product = $t::product( 100, array( 'taxable' => false ) );
		$t::add( $product );
		$card = $t::gift_card( 40 );
		$t::calculate();
		$first_try = $double_spend_place();
		$shopper_a = $double_spend_browser();

		$double_spend_open( null, 0 );
		$t::add( $product );
		$double_spend_apply_card( $double_spend_code_of( $card ) );
		$t::calculate();
		$b = $double_spend_place();
		$b = $double_spend_finish();

		// Shopper A's payment did not go through. They come back and place the order again.
		$double_spend_open( $shopper_a );
		$a = $double_spend_place();
		$t::same( $first_try->get_id(), $a->get_id(), 'the same unpaid order A is saved again' );
		$t::money( 0, $double_spend_order_card( $a->get_id() ), 'order A: no Gift Card line now' );
		$t::money( 100, $a->get_total(), 'order A: full price to pay' );
		$a = $double_spend_finish();
		$double_spend_check_cards(
			array( $card ),
			array(
				'order A' => $a ? $a->get_id() : 0,
				'order B' => $b->get_id(),
			)
		);
	}
);

$double_spend_test(
	'Double spend: placing order A again after its credits were spent asks for the full amount',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_browser, $double_spend_open, $double_spend_give_credits, $double_spend_use_credits, $double_spend_check_credits ) {
		$customer = $t::customer();
		$double_spend_give_credits( $customer, 30 );
		SPC()->customer = new SPC_Customer( $customer );
		$product = $t::product( 100, array( 'taxable' => false ) );
		$t::add( $product );
		$double_spend_use_credits();
		$first_try = $double_spend_place();
		$laptop    = $double_spend_browser();

		$double_spend_open( null, $customer );
		$double_spend_use_credits();
		$t::calculate();
		$b = $double_spend_place();
		$b = $double_spend_finish();

		// Back on the laptop, the order is placed again. Order B emptied the shared cart, so the
		// customer adds the print again first.
		$double_spend_open( $laptop );
		$t::add( $product );
		$a = $double_spend_place();
		$t::same( $first_try->get_id(), $a->get_id(), 'the same unpaid order A is saved again' );
		$t::money( 0, $a->get_credits(), 'order A: no credits now' );
		$t::money( 100, $a->get_total(), 'order A: full price to pay' );
		$a = $double_spend_finish();
		$double_spend_check_credits( $customer, 30, array( $a ? $a->get_id() : 0, $b->get_id() ) );
	}
);

$double_spend_test(
	'Double spend: placing order A again after its code ran out of uses asks for the full amount',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_browser, $double_spend_open, $double_spend_make_code, $double_spend_enter_code, $double_spend_check_code ) {
		list( $discount, $code ) = $double_spend_make_code( array( 'max_uses' => '1' ) );
		$product                 = $t::product( 100, array( 'taxable' => false ) );
		$t::add( $product );
		$t::calculate();
		$double_spend_enter_code( $code );
		$first_try = $double_spend_place();
		$shopper_a = $double_spend_browser();

		$double_spend_open( null, 0 );
		$t::add( $product );
		$t::calculate();
		$double_spend_enter_code( $code );
		$b = $double_spend_place();
		$b = $double_spend_finish();

		$double_spend_open( $shopper_a );
		$a = $double_spend_place();
		$t::same( $first_try->get_id(), $a->get_id(), 'the same unpaid order A is saved again' );
		$t::money( 0, $a->get_discount(), 'order A: no discount now' );
		$t::money( 100, $a->get_total(), 'order A: full price to pay' );
		$a = $double_spend_finish();
		$double_spend_check_code( $discount, $code, 1, 25, array( $a ? $a->get_id() : 0, $b->get_id() ) );
	}
);

/*
 * Orders finished by the payment provider instead of the checkout. Many payment add-ons send
 * the shopper away to pay and finish the order from their return page or a webhook with
 * post_process_order alone. The card, credits and code must be spent there too, or the same
 * money can be used again on the next order.
 */

$double_spend_test(
	'Double spend: an order finished on the payment provider\'s return page takes the gift card money',
	function () use ( $t, $double_spend_place, $double_spend_return_page, $double_spend_open, $double_spend_apply_card, $double_spend_code_of, $double_spend_balance, $double_spend_cart_card, $double_spend_check_cards ) {
		$product = $t::product( 100, array( 'taxable' => false ) );
		$t::add( $product );
		$card = $t::gift_card( 40 );
		$t::calculate();
		$order = $double_spend_place();
		$double_spend_return_page( $order->get_id() );
		$t::same( true, sunshine_get_order( $order->get_id() )->is_paid(), 'order finished' );
		$t::money( 0, $double_spend_balance( $card ), 'card used up by the order' );
		$double_spend_check_cards( array( $card ), array( 'the order' => $order->get_id() ) );

		// The same card on the next order.
		$double_spend_open( null, 0 );
		$t::add( $product );
		sunshine_cart_add_gift_card( $double_spend_code_of( $card ) );
		$cart = $t::new_request();
		$t::money( 0, $double_spend_cart_card(), 'next order: nothing left on the card' );
		$t::money( 100, $cart->get_total(), 'next order: full price to pay' );
	}
);

$double_spend_test(
	'Double spend: an order finished by a payment webhook takes the gift card money',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_webhook, $double_spend_balance, $double_spend_check_cards ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$card = $t::gift_card( 40 );
		$t::calculate();
		$order = $double_spend_place();
		// 110 - 40 = 70 to pay.
		$t::money( 70, $order->get_total(), 'total to pay' );
		// The webhook gets there before the shopper is back on the site.
		$double_spend_webhook( $order->get_id() );
		$double_spend_finish();
		$t::same( true, sunshine_get_order( $order->get_id() )->is_paid(), 'order finished' );
		$t::money( 0, $double_spend_balance( $card ), 'card used up by the order' );
		$double_spend_check_cards( array( $card ), array( 'the order' => $order->get_id() ) );
	}
);

$double_spend_test(
	'Double spend: an order finished by a payment webhook takes the store credits',
	function () use ( $t, $double_spend_place, $double_spend_webhook, $double_spend_give_credits, $double_spend_use_credits, $double_spend_credits_left, $double_spend_check_credits ) {
		$customer = $t::customer();
		$double_spend_give_credits( $customer, 30 );
		SPC()->customer = new SPC_Customer( $customer );
		$product = $t::product( 100, array( 'taxable' => false ) );
		$t::add( $product );
		$double_spend_use_credits();
		$order = $double_spend_place();
		$t::money( 70, $order->get_total(), 'total to pay (100 - 30 credits)' );
		$double_spend_webhook( $order->get_id() );
		$t::same( true, sunshine_get_order( $order->get_id() )->is_paid(), 'order finished' );
		$t::money( 0, $double_spend_credits_left( $customer ), 'credits used up by the order' );
		$double_spend_check_credits( $customer, 30, array( $order->get_id() ) );

		// The customer's next order, started from an empty cart.
		SPC()->customer = new SPC_Customer( $customer );
		SPC()->cart->empty_cart();
		SPC()->session->set( 'checkout_order_id', '' );
		$t::add( $product );
		$double_spend_use_credits();
		$cart = $t::new_request();
		$t::money( 0, $cart->get_credits_applied(), 'next order: no credits left to use' );
		$t::money( 100, $cart->get_total(), 'next order: full price to pay' );
	}
);

$double_spend_test(
	'Double spend: an order finished by a payment webhook counts the code\'s use',
	function () use ( $t, $double_spend_place, $double_spend_webhook, $double_spend_open, $double_spend_make_code, $double_spend_enter_code, $double_spend_uses ) {
		list( $discount, $code ) = $double_spend_make_code( array( 'max_uses' => '1' ) );
		$product                 = $t::product( 100, array( 'taxable' => false ) );
		$t::add( $product );
		$t::calculate();
		$double_spend_enter_code( $code );
		$order = $double_spend_place();
		$double_spend_webhook( $order->get_id() );
		$t::same( 1, $double_spend_uses( $discount ), 'use counted' );
		$double_spend_open( null, 0 );
		$t::add( $product );
		$t::calculate();
		$t::same( false, $double_spend_enter_code( $code ), 'next shopper: code refused' );
	}
);

// A payment that fails leaves order A unpaid. It must not hold on to the card or the code.
$double_spend_test(
	'Double spend: an order whose payment failed does not use up the gift card or the code',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_open, $double_spend_apply_card, $double_spend_code_of, $double_spend_cart_card, $double_spend_make_code, $double_spend_enter_code, $double_spend_check_cards, $double_spend_check_code ) {
		list( $discount, $code ) = $double_spend_make_code( array( 'max_uses' => '1' ) );
		$product                 = $t::product( 100, array( 'taxable' => false ) );
		$t::add( $product );
		$card = $t::gift_card( 40 );
		$t::calculate();
		$double_spend_enter_code( $code );
		$a = $double_spend_place();
		// 100 - 25% = 75, - 40 card = 35 to pay.
		$t::money( 35, $a->get_total(), 'order A: total to pay' );
		// What the Stripe return page does when the payment fails.
		$a->set_status( 'failed' );

		$double_spend_open( null, 0 );
		$t::add( $product );
		$t::calculate();
		$t::same( true, $double_spend_enter_code( $code ), 'next shopper: code accepted' );
		$double_spend_apply_card( $double_spend_code_of( $card ) );
		$cart = $t::calculate();
		$t::money( 40, $double_spend_cart_card(), 'next shopper: the whole card' );
		$t::money( 35, $cart->get_total(), 'next shopper: left to pay' );
		$b      = $double_spend_place();
		$b      = $double_spend_finish();
		$orders = array(
			'order A' => $a->get_id(),
			'order B' => $b->get_id(),
		);
		$double_spend_check_cards( array( $card ), $orders );
		$double_spend_check_code( $discount, $code, 1, 25, array_values( $orders ) );
	}
);

/*
 * Refunds after a double spend: a refund may only put back what really came off the card.
 */

$double_spend_test(
	'Double spend: refunding both orders puts back only what came off the gift card',
	function () use ( $t, $double_spend_place, $double_spend_finish, $double_spend_browser, $double_spend_open, $double_spend_apply_card, $double_spend_code_of, $double_spend_balance ) {
		$product = $t::product( 100, array( 'taxable' => false ) );
		$t::add( $product );
		$card = $t::gift_card( 40 );
		$t::calculate();
		$a         = $double_spend_place();
		$shopper_a = $double_spend_browser();

		$double_spend_open( null, 0 );
		$t::add( $product );
		$double_spend_apply_card( $double_spend_code_of( $card ) );
		$t::calculate();
		$b = $double_spend_place();
		$b = $double_spend_finish();

		$double_spend_open( $shopper_a );
		$double_spend_finish();

		foreach ( array( $a->get_id(), $b->get_id() ) as $order_id ) {
			$order = sunshine_get_order( $order_id );
			if ( $order->is_paid() ) {
				$order->set_status( 'refunded' );
			}
		}
		$t::money( 40, $double_spend_balance( $card ), 'card is back to 40, not more' );
	}
);

remove_action( 'wp_insert_post', $double_spend_remember, 10 );
foreach ( array_unique( $double_spend_emails ) as $double_spend_email_id ) {
	wp_delete_post( $double_spend_email_id, true );
}
global $wpdb;
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sunshine_actions' ) ) ) {
	if ( ! empty( $double_spend_products ) ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_actions WHERE action_type = 'add_to_cart' AND action_object_id IN (" . implode( ',', array_map( 'intval', array_unique( $double_spend_products ) ) ) . ')' );
	}
	if ( ! empty( $double_spend_orders ) ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_actions WHERE action_type = 'order' AND action_object_id IN (" . implode( ',', array_map( 'intval', array_unique( $double_spend_orders ) ) ) . ')' );
	}
}
if ( ! empty( $double_spend_blocked ) ) {
	WP_CLI::log( 'double-spend blocked outgoing requests to: ' . implode( ', ', array_unique( $double_spend_blocked ) ) );
}
