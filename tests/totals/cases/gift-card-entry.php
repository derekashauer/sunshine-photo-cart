<?php
/**
 * Gift Cards add-on: typing a gift card code into the checkout's code box, and taking it off.
 *
 * The other gift card tests put the code straight into the session. These send the same
 * requests the checkout page sends: the Apply button posts to sunshine_checkout_discount_code
 * (the gift card handler runs first, then the Discounts handler), and the x button next to an
 * applied code posts to sunshine_checkout_discount_code_delete. Then the page is loaded again,
 * the way the checkout reloads after either one.
 *
 * Gift cards are made the way the Gift Cards admin screen saves them: code, original amount and
 * balance as typed, no purchase order and no recipient. Codes come from the add-on's own code
 * maker (12 capital letters and numbers).
 *
 * Nothing leaves the site: every web request and email is stopped.
 */

$t = 'SPC_Totals_Test';

// PHP warnings, notices and deprecations raised by the store code these tests call.
$gift_card_entry_warnings = array();
// Outgoing web requests that were stopped.
$gift_card_entry_blocked = array();
// Products, gift cards, email logs and orders made while this file runs, for cleanup.
$gift_card_entry_made   = array();
$gift_card_entry_orders = array();

$gift_card_entry_remember = function ( $post_id, $post ) use ( $t, &$gift_card_entry_made ) {
	if ( in_array( $post->post_type, array( 'sunshine-product', 'sunshine-gift-card', 'sunshine-email-log' ), true ) ) {
		$gift_card_entry_made[ $post->post_type ][] = (int) $post_id;
		if ( 'sunshine-gift-card' === $post->post_type ) {
			$t::track( 'post', $post_id );
		}
	}
};
add_action( 'wp_insert_post', $gift_card_entry_remember, 10, 2 );

/**
 * Run one test with every web request, email and order receipt stopped.
 */
$gift_card_entry_test = function ( $name, $body ) use ( $t, &$gift_card_entry_blocked ) {
	$t::test(
		$name,
		function () use ( $t, $body, &$gift_card_entry_blocked ) {
			$t::require_plugin( 'sunshine_gift_card_check_discount_code', 'Gift Cards add-on' );
			$offline = function ( $pre, $args, $url ) use ( &$gift_card_entry_blocked ) {
				$gift_card_entry_blocked[] = $url;
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

/**
 * Run some store code and keep any PHP warnings it raises, then pass them on as usual.
 */
$gift_card_entry_watch = function ( $callback ) use ( &$gift_card_entry_warnings ) {
	set_error_handler(
		function ( $errno, $errstr, $errfile, $errline ) use ( &$gift_card_entry_warnings ) {
			if ( error_reporting() & $errno ) {
				$gift_card_entry_warnings[] = $errstr . ' (' . basename( $errfile ) . ':' . $errline . ')';
			}
			return false;
		}
	);
	try {
		return $callback();
	} finally {
		restore_error_handler();
	}
};

/**
 * A gift card made on the Gift Cards admin screen. $balance and $original are what the
 * photographer typed (the screen keeps them as text). $status is 'publish' or 'draft'.
 * Returns the card's id and code.
 */
$gift_card_entry_card = function ( $balance, $status = 'publish', $original = null ) use ( $t ) {
	$t::require_plugin( 'sunshine_generate_gift_card_code', 'Gift Cards add-on' );
	$code = sunshine_generate_gift_card_code();
	$id   = wp_insert_post(
		array(
			'post_type'   => 'sunshine-gift-card',
			'post_status' => $status,
			'post_title'  => 'Gift card entry test',
		)
	);
	if ( ! $id || is_wp_error( $id ) ) {
		throw new Exception( 'Could not make a gift card' );
	}
	$t::track( 'post', $id );
	$meta = array(
		'code'              => $code,
		'original_amount'   => (string) ( null === $original ? $balance : $original ),
		'balance'           => (string) $balance,
		'purchase_order_id' => '',
		'recipient_email'   => '',
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return array( $id, $code );
};

/**
 * A discount code made the way the Discounts screen saves it. It is not put in the cart.
 */
$gift_card_entry_discount = function ( $meta = array() ) use ( $t ) {
	$t::require_plugin( 'sunshine_get_discount_by_code', 'Discount Codes add-on' );
	$code = 'SPCGCE' . wp_rand( 100000, 999999 );
	$id   = wp_insert_post(
		array(
			'post_type'   => 'sunshine-discount',
			'post_status' => 'publish',
			'post_title'  => 'Gift card entry test ' . $code,
		)
	);
	$t::track( 'post', $id );
	$meta = array_merge(
		array(
			'auto'                  => '',
			'code'                  => $code,
			'discount_type'         => 'percent-total',
			'discount_amount'       => '10',
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

/**
 * Send an ajax request the way admin-ajax.php does: every handler hooked to the action runs, in
 * order, until one sends its JSON reply.
 */
$gift_card_entry_ajax = function ( $action, $post ) use ( $gift_card_entry_watch ) {
	$saved_post      = $_POST;
	$_POST           = $post;
	$_POST['action'] = $action;
	$ajax            = '__return_true';
	$die             = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'wp_doing_ajax', $ajax );
	add_filter( 'wp_die_ajax_handler', $die );
	$output = '';
	ob_start();
	try {
		$gift_card_entry_watch(
			function () use ( $action ) {
				do_action( ( is_user_logged_in() ? 'wp_ajax_' : 'wp_ajax_nopriv_' ) . $action );
			}
		);
	} catch ( SPC_Totals_Test_Done $e ) {
		// Every handler ends by sending JSON.
	} finally {
		$output = ob_get_clean();
		$_POST  = $saved_post;
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );
	}
	$response = json_decode( $output, true );
	if ( ! is_array( $response ) ) {
		$response = array(
			'success' => false,
			'raw'     => substr( $output, 0, 300 ),
		);
	}
	return $response;
};

// The checkout's Apply button, with what the customer typed in the code box.
$gift_card_entry_apply = function ( $typed ) use ( $gift_card_entry_ajax ) {
	return $gift_card_entry_ajax(
		'sunshine_checkout_discount_code',
		array(
			'discount_code' => $typed,
			'security'      => wp_create_nonce( 'sunshine-checkout-discount-code' ),
		)
	);
};

// The x button next to an applied code. It sends the code printed on the button.
$gift_card_entry_remove = function ( $code ) use ( $gift_card_entry_ajax ) {
	return $gift_card_entry_ajax(
		'sunshine_checkout_discount_code_delete',
		array(
			'discount_code' => $code,
			'security'      => wp_create_nonce( 'sunshine-checkout-discount-code-delete' ),
		)
	);
};

$gift_card_entry_ok = function ( $response ) {
	return ! empty( $response['success'] );
};

$gift_card_entry_message = function ( $response ) {
	return isset( $response['data']['message'] ) ? $response['data']['message'] : '';
};

// What the cards pay on this cart, as the add-on worked it out.
$gift_card_entry_used = function () {
	return array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) );
};

// The Gift Card line on the checkout's totals (a negative fee).
$gift_card_entry_fee = function () {
	$fees = (array) SPC()->cart->get_fees();
	return isset( $fees['gift_card'] ) ? (float) $fees['gift_card']['amount'] : 0.0;
};

// The code printed on the x button for a card in the "applied" list, or '' when the card is
// not in the list. This is what the checkout shows and what the x button sends.
$gift_card_entry_button_code = function ( $card_id ) {
	$applied = apply_filters( 'sunshine_checkout_discounts_applied_for_template', SPC()->cart->get_discounts() );
	$key     = 'gc_' . $card_id;
	return ( is_array( $applied ) && isset( $applied[ $key ] ) ) ? $applied[ $key ]->get_code() : '';
};

$gift_card_entry_balance = function ( $card_id ) {
	return (float) get_post_meta( $card_id, 'balance', true );
};

// Place the order through the real checkout step, then finish it the way the shopper's next
// page load does after paying. Returns the finished order fresh from the database.
$gift_card_entry_place_and_finish = function ( $payment_method = 'test' ) use ( $t, &$gift_card_entry_orders, $gift_card_entry_watch ) {
	SPC()->cart->setup( true );
	$order                    = $gift_card_entry_watch(
		function () use ( $t, $payment_method ) {
			return $t::place_order( $payment_method );
		}
	);
	$gift_card_entry_orders[] = $order->get_id();
	$placed_total             = (float) $order->get_total();
	SPC()->cart               = new SPC_Cart();
	SPC()->cart->setup( true );
	$finished = $gift_card_entry_watch(
		function () {
			return SPC()->cart->process_order();
		}
	);
	if ( ! $finished ) {
		throw new Exception( 'The order was placed but not finished: ' . wp_json_encode( SPC()->cart->get_errors() ) );
	}
	return array( sunshine_get_order( $order->get_id() ), $placed_total );
};

$gift_card_entry_order_card = function ( $order ) {
	$fees = (array) $order->get_fees();
	return isset( $fees['gift_card'] ) ? (float) $fees['gift_card']['amount'] : 0.0;
};

/*
 * Entering a card.
 */

$gift_card_entry_test(
	'Gift card entry: a code typed at checkout comes off the total',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used, $gift_card_entry_fee, $gift_card_entry_button_code ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '50' );
		$response            = $gift_card_entry_apply( $code );
		$t::same( true, $gift_card_entry_ok( $response ), 'Apply says the code worked' );
		$t::same( true, ! empty( $response['data']['reload_checkout'] ), 'the checkout is told to reload' );
		$cart = $t::new_request();
		// 100 + 10 tax = 110. The card pays 50, 60 is left to pay.
		$t::money( 50, $gift_card_entry_used(), 'taken from the card' );
		$t::money( -50, $gift_card_entry_fee(), 'Gift Card line on the checkout' );
		$t::money( 60, $cart->get_total(), 'left to pay' );
		$t::same( $code, $gift_card_entry_button_code( $card ), 'card shows in the applied codes' );
	}
);

$gift_card_entry_test(
	'Gift card entry: a card bigger than the order pays the order and keeps the rest',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used, $gift_card_entry_place_and_finish, $gift_card_entry_balance, $gift_card_entry_order_card ) {
		$t::tax( 10 );
		$t::add( $t::product( 40 ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '100' );
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( $code ) ), 'card accepted' );
		$cart = $t::new_request();
		// 40 + 4 tax = 44, all of it paid by the card.
		$t::money( 44, $gift_card_entry_used(), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'nothing left to pay' );
		// Nothing to pay, so the checkout submits the order with the Free payment method.
		list( $order, $placed ) = $gift_card_entry_place_and_finish( 'free' );
		$t::money( 0, $placed, 'placed order total' );
		$t::money( -44, $gift_card_entry_order_card( $order ), 'Gift Card line on the order' );
		$t::same( true, $order->is_paid(), 'order is paid' );
		$t::same( 'gift-card', $order->get_payment_method(), 'order says it was paid by gift card' );
		// 100 - 44 = 56 stays on the card.
		$t::money( 56, $gift_card_entry_balance( $card ), 'left on the card' );
	}
);

$gift_card_entry_test(
	'Gift card entry: a card with cents left pays to the cent',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used, $gift_card_entry_place_and_finish, $gift_card_entry_balance, $gift_card_entry_order_card ) {
		$t::tax( 8.25 );
		$t::add( $t::product( 9.99 ) );
		$t::calculate();
		// A card that was partly used before: 25.00 at first, 12.34 left.
		list( $card, $code ) = $gift_card_entry_card( '12.34', 'publish', '25.00' );
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( $code ) ), 'card accepted' );
		$cart = $t::new_request();
		// 9.99 + 0.82 tax (8.25% of 9.99 = 0.824) = 10.81, all paid by the card.
		$t::money( 10.81, $gift_card_entry_used(), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'nothing left to pay' );
		list( $order, $placed ) = $gift_card_entry_place_and_finish( 'free' );
		$t::money( -10.81, $gift_card_entry_order_card( $order ), 'Gift Card line on the order' );
		// 12.34 - 10.81 = 1.53.
		$t::money( 1.53, $gift_card_entry_balance( $card ), 'left on the card' );
	}
);

$gift_card_entry_test(
	'Gift card entry: a card with nothing left is refused',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used, $gift_card_entry_button_code ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '0', 'publish', '50' );
		$response            = $gift_card_entry_apply( $code );
		$t::same( false, $gift_card_entry_ok( $response ), 'Apply says the code did not work' );
		$cart = $t::new_request();
		$t::money( 0, $gift_card_entry_used(), 'nothing taken from the card' );
		$t::money( 100, $cart->get_total(), 'full price left to pay' );
		$t::same( '', $gift_card_entry_button_code( $card ), 'card is not in the applied codes' );
	}
);

$gift_card_entry_test(
	'Gift card entry: a card saved as a Draft is refused',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '50', 'draft' );
		$response            = $gift_card_entry_apply( $code );
		$t::same( false, $gift_card_entry_ok( $response ), 'Apply says the code did not work' );
		$cart = $t::new_request();
		$t::money( 0, $gift_card_entry_used(), 'nothing taken from the card' );
		$t::money( 100, $cart->get_total(), 'full price left to pay' );
	}
);

// The photographer turns a card back to Draft (for example it was reported stolen) while it is
// sitting in a customer's checkout. The next page load must drop it.
$gift_card_entry_test(
	'Gift card entry: a card switched to Draft after it was entered stops paying',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_used ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		list( $card, $code ) = $gift_card_entry_card( '50' );
		// The card was entered on an earlier page load. This request has not looked it up yet.
		SPC()->session->set( 'gift_cards', array( $code ) );
		wp_update_post(
			array(
				'ID'          => $card,
				'post_status' => 'draft',
			)
		);
		$cart = $t::new_request();
		$t::money( 0, $gift_card_entry_used(), 'nothing taken from the card' );
		$t::money( 100, $cart->get_total(), 'full price left to pay' );
		$t::same( array(), array_values( array_filter( (array) SPC()->session->get( 'gift_cards' ) ) ), 'card taken out of the checkout' );
	}
);

$gift_card_entry_test(
	'Gift card entry: a code that is not a gift card or a discount code is refused',
	function () use ( $t, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		$response = $gift_card_entry_apply( 'NOSUCHCODE' . wp_rand( 1000, 9999 ) );
		$t::same( false, $gift_card_entry_ok( $response ), 'Apply says the code did not work' );
		$cart = $t::new_request();
		$t::money( 0, $gift_card_entry_used(), 'no gift card' );
		$t::money( 0, $cart->get_discount(), 'no discount' );
		$t::money( 100, $cart->get_total(), 'full price left to pay' );
	}
);

// A request that does not come from the checkout page (no valid security code) changes nothing.
$gift_card_entry_test(
	'Gift card entry: a request without the checkout page security code adds nothing',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_ajax, $gift_card_entry_ok, $gift_card_entry_used ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '50' );
		$response            = $gift_card_entry_ajax(
			'sunshine_checkout_discount_code',
			array(
				'discount_code' => $code,
				'security'      => 'not-a-real-nonce',
			)
		);
		$t::same( false, $gift_card_entry_ok( $response ), 'request refused' );
		$cart = $t::new_request();
		$t::money( 0, $gift_card_entry_used(), 'nothing taken from the card' );
		$t::money( 100, $cart->get_total(), 'full price left to pay' );
	}
);

/*
 * How customers type codes.
 */

// The code comes in an email in capitals. Customers often type it in small letters.
$gift_card_entry_test(
	'Gift card entry: a code typed in small letters works, through to the paid order',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used, $gift_card_entry_place_and_finish, $gift_card_entry_balance, $gift_card_entry_order_card ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '30' );
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( strtolower( $code ) ) ), 'card accepted' );
		$cart = $t::new_request();
		$t::money( 30, $gift_card_entry_used(), 'taken from the card' );
		$t::money( 70, $cart->get_total(), 'left to pay' );
		list( $order, $placed ) = $gift_card_entry_place_and_finish();
		$t::money( 70, $placed, 'order total' );
		$t::money( -30, $gift_card_entry_order_card( $order ), 'Gift Card line on the order' );
		$t::money( 0, $gift_card_entry_balance( $card ), 'card used up' );
	}
);

// Copying a code out of an email often brings spaces or a line break with it.
$gift_card_entry_test(
	'Gift card entry: a code pasted with spaces around it works',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used, $gift_card_entry_button_code ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '30' );
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( "  " . $code . " \n" ) ), 'card accepted' );
		$cart = $t::new_request();
		$t::money( 30, $gift_card_entry_used(), 'taken from the card' );
		$t::money( 70, $cart->get_total(), 'left to pay' );
		$t::same( $code, $gift_card_entry_button_code( $card ), 'card shows in the applied codes' );
	}
);

$gift_card_entry_test(
	'Gift card entry: the same card entered twice is only used once',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used, $gift_card_entry_message ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '30' );
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( $code ) ), 'first time accepted' );
		$t::new_request();
		$second = $gift_card_entry_apply( $code );
		$t::same( false, $gift_card_entry_ok( $second ), 'second time refused' );
		$t::same( true, '' !== $gift_card_entry_message( $second ), 'the customer is told why' );
		$cart = $t::new_request();
		// One 30 card on 100: 70 left to pay, not 40.
		$t::money( 30, $gift_card_entry_used(), 'taken from the card' );
		$t::money( 70, $cart->get_total(), 'left to pay' );
	}
);

// The same card typed in capitals, in small letters, or with only the first letter capital is
// still one card with one balance. It must pay once, not once for each way it was typed.
$gift_card_entry_test(
	'Gift card entry: the same card typed in capitals and in small letters is only used once',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used, $gift_card_entry_fee, $gift_card_entry_place_and_finish, $gift_card_entry_balance, $gift_card_entry_order_card ) {
		$t::add( $t::product( 200, array( 'taxable' => false ) ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '50' );
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( strtolower( $code ) ) ), 'small letters accepted' );
		$t::new_request();
		$t::same( false, $gift_card_entry_ok( $gift_card_entry_apply( $code ) ), 'capitals refused as the same card' );
		$t::new_request();
		$t::same( false, $gift_card_entry_ok( $gift_card_entry_apply( ucfirst( strtolower( $code ) ) ) ), 'first letter capital refused as the same card' );
		$cart = $t::new_request();
		// One 50 card on 200: the card pays 50 and 150 is left to pay.
		$t::money( -50, $gift_card_entry_fee(), 'Gift Card line on the checkout' );
		$t::money( 50, $gift_card_entry_used(), 'what the card records it gave' );
		$t::money( 150, $cart->get_total(), 'left to pay' );
		// Whatever the checkout showed, the paid order and the card must agree.
		$payment                = $cart->get_total() > 0 ? 'test' : 'free';
		list( $order, $placed ) = $gift_card_entry_place_and_finish( $payment );
		$taken                  = 50 - $gift_card_entry_balance( $card );
		$t::money( $taken, -$gift_card_entry_order_card( $order ), 'Gift Card line on the order matches what came off the card' );
		$t::money( 150, $placed, 'order total' );
	}
);

/*
 * Taking a card off.
 */

$gift_card_entry_test(
	'Gift card entry: the x button takes the card off and the total goes back up',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_remove, $gift_card_entry_ok, $gift_card_entry_used, $gift_card_entry_fee, $gift_card_entry_button_code ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '50' );
		$gift_card_entry_apply( $code );
		$t::new_request();
		$button   = $gift_card_entry_button_code( $card );
		$response = $gift_card_entry_remove( $button );
		$t::same( true, $gift_card_entry_ok( $response ), 'x button says the card was taken off' );
		$cart = $t::new_request();
		$t::money( 0, $gift_card_entry_used(), 'nothing taken from the card' );
		$t::money( 0, $gift_card_entry_fee(), 'no Gift Card line' );
		// 100 + 10 tax.
		$t::money( 110, $cart->get_total(), 'full price left to pay' );
		$t::same( '', $gift_card_entry_button_code( $card ), 'card is gone from the applied codes' );
	}
);

// The x button sends the code the way the card has it saved (in capitals). A card typed in
// small letters must still come off.
$gift_card_entry_test(
	'Gift card entry: a card typed in small letters can be taken off with its x button',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_remove, $gift_card_entry_ok, $gift_card_entry_used ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '50' );
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( strtolower( $code ) ) ), 'card accepted' );
		$t::new_request();
		$response = $gift_card_entry_remove( $code );
		$t::same( true, $gift_card_entry_ok( $response ), 'x button says the card was taken off' );
		$cart = $t::new_request();
		$t::money( 0, $gift_card_entry_used(), 'nothing taken from the card' );
		$t::money( 100, $cart->get_total(), 'full price left to pay' );
	}
);

$gift_card_entry_test(
	'Gift card entry: taking off a card that is not in the checkout changes nothing',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_remove, $gift_card_entry_ok, $gift_card_entry_used ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		list( $applied_card, $applied_code ) = $gift_card_entry_card( '30' );
		list( $other_card, $other_code )     = $gift_card_entry_card( '40' );
		$gift_card_entry_apply( $applied_code );
		$t::new_request();
		$response = $gift_card_entry_remove( $other_code );
		$t::same( false, $gift_card_entry_ok( $response ), 'nothing to take off' );
		$cart = $t::new_request();
		$t::money( 30, $gift_card_entry_used(), 'the applied card still pays' );
		$t::money( 70, $cart->get_total(), 'left to pay' );
	}
);

/*
 * Gift cards with discount codes in the same box.
 */

// With Gift Cards active, the gift card handler sees every code first. A real discount code
// must still go through to the Discounts add-on.
$gift_card_entry_test(
	'Gift card entry: a discount code typed in the same box still works, and the card pays what is left',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_discount, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		list( $card, $card_code )    = $gift_card_entry_card( '200' );
		list( $discount, $disc_code ) = $gift_card_entry_discount( array( 'discount_amount' => '20' ) );
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( $card_code ) ), 'card accepted' );
		$t::new_request();
		$t::money( 110, $gift_card_entry_used(), 'card pays 100 + 10 tax before the code' );
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( $disc_code ) ), 'discount code accepted' );
		$cart = $t::new_request();
		// 100 - 20% = 80, + 8 tax = 88. The card now pays only 88.
		$t::money( 20, $cart->get_discount(), 'discount' );
		$t::money( 88, $gift_card_entry_used(), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'nothing left to pay' );
	}
);

$gift_card_entry_test(
	'Gift card entry: taking off the discount code leaves the card on, and the card pays more',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_discount, $gift_card_entry_apply, $gift_card_entry_remove, $gift_card_entry_ok, $gift_card_entry_used, $gift_card_entry_button_code ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		list( $card, $card_code )    = $gift_card_entry_card( '60' );
		list( $discount, $disc_code ) = $gift_card_entry_discount( array( 'discount_amount' => '50' ) );
		$gift_card_entry_apply( $disc_code );
		$t::new_request();
		$gift_card_entry_apply( $card_code );
		$cart = $t::new_request();
		// 100 - 50% = 50, all paid by the 60 card.
		$t::money( 50, $gift_card_entry_used(), 'card pays the discounted 50' );
		$t::money( 0, $cart->get_total(), 'nothing left to pay' );
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_remove( $disc_code ) ), 'discount code taken off' );
		$cart = $t::new_request();
		// 100 with no discount. The card pays its full 60, 40 is left.
		$t::money( 0, $cart->get_discount(), 'no discount' );
		$t::money( 60, $gift_card_entry_used(), 'card pays its whole balance' );
		$t::money( 40, $cart->get_total(), 'left to pay' );
		$t::same( $card_code, $gift_card_entry_button_code( $card ), 'card is still applied' );
	}
);

$gift_card_entry_test(
	'Gift card entry: taking off the card leaves the discount code on',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_discount, $gift_card_entry_apply, $gift_card_entry_remove, $gift_card_entry_ok, $gift_card_entry_used ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		list( $card, $card_code )    = $gift_card_entry_card( '30' );
		list( $discount, $disc_code ) = $gift_card_entry_discount( array( 'discount_amount' => '10' ) );
		$gift_card_entry_apply( $disc_code );
		$t::new_request();
		$gift_card_entry_apply( $card_code );
		$t::new_request();
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_remove( $card_code ) ), 'card taken off' );
		$cart = $t::new_request();
		// 100 - 10% = 90, no card.
		$t::money( 10, $cart->get_discount(), 'discount stays' );
		$t::money( 0, $gift_card_entry_used(), 'no card' );
		$t::money( 90, $cart->get_total(), 'left to pay' );
	}
);

// A photographer can make a discount code with the same text as a gift card code. Typing it
// must not give both. The handler's own comment says gift cards are checked first, so the
// gift card is used (assumption) and the discount is not added on top.
$gift_card_entry_test(
	'Gift card entry: a code that is both a gift card and a discount code is used once, as the gift card',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_discount, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '20' );
		$gift_card_entry_discount(
			array(
				'code'            => $code,
				'discount_amount' => '10',
			)
		);
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( $code ) ), 'code accepted' );
		$cart = $t::new_request();
		// 100 - 20 card = 80. Not also 10% off.
		$t::money( 20, $gift_card_entry_used(), 'taken from the card' );
		$t::money( 0, $cart->get_discount(), 'no discount on top' );
		$t::money( 80, $cart->get_total(), 'left to pay' );
	}
);

/*
 * More than one card, and cards with other ways to pay.
 */

$gift_card_entry_test(
	'Gift card entry: two cards on one order, the first is used up before the second',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used, $gift_card_entry_place_and_finish, $gift_card_entry_balance, $gift_card_entry_order_card ) {
		$t::add( $t::product( 70, array( 'taxable' => false ) ) );
		$t::calculate();
		list( $first, $first_code )   = $gift_card_entry_card( '30' );
		list( $second, $second_code ) = $gift_card_entry_card( '50' );
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( $first_code ) ), 'first card accepted' );
		$t::new_request();
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( $second_code ) ), 'second card accepted' );
		$cart    = $t::new_request();
		$amounts = (array) SPC()->session->get( 'gift_card_amounts' );
		// 70: the first card pays all its 30, the second pays the other 40.
		$t::money( 30, isset( $amounts[ $first ] ) ? $amounts[ $first ] : 0, 'first card pays' );
		$t::money( 40, isset( $amounts[ $second ] ) ? $amounts[ $second ] : 0, 'second card pays' );
		$t::money( 0, $cart->get_total(), 'nothing left to pay' );
		list( $order, $placed ) = $gift_card_entry_place_and_finish( 'free' );
		$t::money( -70, $gift_card_entry_order_card( $order ), 'Gift Card line on the order' );
		$t::money( 0, $gift_card_entry_balance( $first ), 'first card used up' );
		$t::money( 10, $gift_card_entry_balance( $second ), 'second card keeps 10' );
	}
);

$gift_card_entry_test(
	'Gift card entry: prices include tax, a card covers part of the order',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used, $gift_card_entry_place_and_finish, $gift_card_entry_balance, $gift_card_entry_order_card ) {
		$t::option( 'price_has_tax', 'yes' );
		$t::tax( 10 );
		// 110 with tax included: 100 + 10 tax.
		$t::add( $t::product( 110 ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '50' );
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( $code ) ), 'card accepted' );
		$cart = $t::new_request();
		$t::money( 50, $gift_card_entry_used(), 'taken from the card' );
		$t::money( 10, $cart->get_tax(), 'tax is still worked out on the full price' );
		$t::money( 60, $cart->get_total(), 'left to pay' );
		list( $order, $placed ) = $gift_card_entry_place_and_finish();
		$t::money( 60, $placed, 'order total' );
		$t::money( -50, $gift_card_entry_order_card( $order ), 'Gift Card line on the order' );
		$t::money( 0, $gift_card_entry_balance( $card ), 'card used up' );
	}
);

$gift_card_entry_test(
	'Gift card entry: discount after tax, a code and a card bigger than the order',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_discount, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used, $gift_card_entry_place_and_finish, $gift_card_entry_balance, $gift_card_entry_order_card ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		list( $discount, $disc_code ) = $gift_card_entry_discount( array( 'discount_amount' => '20' ) );
		list( $card, $card_code )     = $gift_card_entry_card( '150' );
		$gift_card_entry_apply( $disc_code );
		$t::new_request();
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( $card_code ) ), 'card accepted' );
		$cart = $t::new_request();
		// 100 + 10 tax = 110, then 20% off = 88. All paid by the card.
		$t::money( 88, $gift_card_entry_used(), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'nothing left to pay' );
		list( $order, $placed ) = $gift_card_entry_place_and_finish( 'free' );
		$t::money( -88, $gift_card_entry_order_card( $order ), 'Gift Card line on the order' );
		// 150 - 88 = 62.
		$t::money( 62, $gift_card_entry_balance( $card ), 'left on the card' );
	}
);

// A logged-in customer's Apply button goes to the logged-in handler. The card pays first, then
// the customer's store credits pay what is left.
$gift_card_entry_test(
	'Gift card entry: logged-in customer with store credit, the card pays first and credits pay the rest',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used, $gift_card_entry_place_and_finish, $gift_card_entry_balance, $gift_card_entry_order_card ) {
		$customer = $t::customer();
		if ( is_wp_error( $customer ) || ! get_current_user_id() ) {
			// The shared customer() helper does not check for this; seen once while other test runs were busy.
			$t::skip( 'could not make a test customer' );
		}
		( new SPC_Customer( $customer ) )->set_credits( 20 );
		SPC()->customer = new SPC_Customer( $customer );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '50' );
		$t::same( true, $gift_card_entry_ok( $gift_card_entry_apply( $code ) ), 'card accepted' );
		// The "Use my credits" box on the payment step.
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$cart = $t::new_request();
		// 100 + 10 tax = 110. Card 50, credits 20, 40 left to pay.
		$t::money( 50, $gift_card_entry_used(), 'taken from the card' );
		$t::money( 20, $cart->get_credits_applied(), 'credits used' );
		$t::money( 40, $cart->get_total(), 'left to pay' );
		list( $order, $placed ) = $gift_card_entry_place_and_finish();
		$t::money( 40, $placed, 'order total' );
		$t::money( -50, $gift_card_entry_order_card( $order ), 'Gift Card line on the order' );
		$t::money( 0, $gift_card_entry_balance( $card ), 'card used up' );
		$t::money( 0, ( new SPC_Customer( $customer ) )->get_credits(), 'credits used up' );
	}
);

// When the cart changes after the card was entered, the card follows the new total.
$gift_card_entry_test(
	'Gift card entry: removing an item after the card was entered lowers what the card pays',
	function () use ( $t, $gift_card_entry_card, $gift_card_entry_apply, $gift_card_entry_ok, $gift_card_entry_used ) {
		$t::add( $t::product( 60, array( 'taxable' => false ) ) );
		$t::add( $t::product( 40, array( 'taxable' => false ) ) );
		$t::calculate();
		list( $card, $code ) = $gift_card_entry_card( '100' );
		$gift_card_entry_apply( $code );
		$t::new_request();
		$t::money( 100, $gift_card_entry_used(), 'card pays the whole 100' );
		// The cart page's remove link for the 40 item (the second line).
		SPC()->cart->remove_item( 1 );
		$cart = $t::new_request();
		$t::money( 60, $gift_card_entry_used(), 'card pays only the 60 left' );
		$t::money( 0, $cart->get_total(), 'nothing left to pay' );
	}
);

remove_action( 'wp_insert_post', $gift_card_entry_remember, 10 );
foreach ( isset( $gift_card_entry_made['sunshine-email-log'] ) ? array_unique( $gift_card_entry_made['sunshine-email-log'] ) : array() as $gift_card_entry_email_id ) {
	wp_delete_post( $gift_card_entry_email_id, true );
}
global $wpdb;
// The Analytics add-on logs add to cart and order rows in its own table.
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sunshine_actions' ) ) ) {
	if ( ! empty( $gift_card_entry_made['sunshine-product'] ) ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_actions WHERE action_type = 'add_to_cart' AND action_object_id IN (" . implode( ',', array_map( 'intval', array_unique( $gift_card_entry_made['sunshine-product'] ) ) ) . ')' );
	}
	if ( ! empty( $gift_card_entry_orders ) ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_actions WHERE action_type = 'order' AND action_object_id IN (" . implode( ',', array_map( 'intval', array_unique( $gift_card_entry_orders ) ) ) . ')' );
	}
}
foreach ( array_unique( $gift_card_entry_blocked ) as $gift_card_entry_url ) {
	WP_CLI::log( 'BLOCKED REQUEST (gift-card-entry) ' . $gift_card_entry_url );
}
foreach ( array_unique( $gift_card_entry_warnings ) as $gift_card_entry_warning ) {
	WP_CLI::log( 'PHP WARNING (gift-card-entry) ' . $gift_card_entry_warning );
}
