<?php
/**
 * Making an account partway through checkout (SPC_Cart::create_customer).
 *
 * A shopper who is not logged in fills in the Contact step and types a password. The real
 * Contact step request (sunshine_checkout_process_section) checks the step, makes the account
 * and logs the shopper in. From the next page load on they are that customer, so the cart, the
 * checkout and the order must all follow them to the account. Session fees need an account,
 * because the credits and free prints that come with a paid session are kept on it.
 *
 * The tests run each request the way the browser sends it: the Contact step request as a guest,
 * then the next page load as whoever the login cookie from that request says, then placing and
 * finishing the order (SPC_Cart::init_order() and SPC_Cart::process_order()) in later requests.
 *
 * Nothing leaves the site: web requests are refused, emails and order receipts are stopped, no
 * login cookie is sent, and add-ons that only pass new accounts or orders on to mailing lists,
 * email queues or Light Blue are switched off while each test runs. Accounts, products, codes,
 * cards, session dates and orders made here are deleted at the end of the run.
 */

$t = 'SPC_Totals_Test';

// Who the login cookie made by the Contact step is for, and every web request that was tried.
$checkout_account_state = (object) array(
	'cookie_user' => 0,
	'sent'        => array(),
);

// The Analytics add-on logs a row in its own table each time a shopper adds to their cart or
// logs in. Remember the accounts and products made here, so those rows can be deleted at the
// end of the file. Rows that belong to anyone else are never touched.
$checkout_account_made          = (object) array(
	'products' => array(),
	'users'    => array(),
);
$checkout_account_remember_post = function ( $post_id, $post ) use ( $checkout_account_made ) {
	if ( 'sunshine-product' === $post->post_type ) {
		$checkout_account_made->products[] = (int) $post_id;
	}
};
$checkout_account_remember_user = function ( $user_id ) use ( $checkout_account_made ) {
	$checkout_account_made->users[] = (int) $user_id;
};
add_action( 'wp_insert_post', $checkout_account_remember_post, 10, 2 );
add_action( 'user_register', $checkout_account_remember_user );

// Add-ons that only pass a new account or a new order on to someone else (mailing lists, email
// queues, Light Blue, Analytics reports). They have nothing to do with money, so they are taken
// off while a test runs and put back after.
$checkout_account_side_hooks = array(
	array( 'sunshine_after_signup', 'sunshine_email_queue_after_signup' ),
	array( 'sunshine_after_signup', 'sunshine_mailchimp_after_signup' ),
	array( 'sunshine_after_signup', 'sunshine_campaign_monitor_after_signup' ),
	array( 'sunshine_checkout_create_order', 'sunshine_mailchimp_order_create' ),
	array( 'sunshine_checkout_create_order', 'sunshine_campaign_monitor_order_create' ),
	array( 'sunshine_checkout_create_order', 'sunshine_lightblue_order_post_process' ),
	array( 'sunshine_checkout_create_order', 'sunshine_adv_reports_order_create' ),
	array( 'sunshine_checkout_create_order', 'sunshine_email_queue_after_order' ),
	array( 'sunshine_order_create', 'sunshine_adv_reports_order_create' ),
);

$checkout_account_block_http = function ( $pre, $args, $url ) use ( $checkout_account_state ) {
	$checkout_account_state->sent[] = $url;
	return new WP_Error( 'spc_totals_offline', 'Web requests are blocked while the totals tests run' );
};

// wp_signon() makes the login cookie. Remember who it is for: that is who the browser is
// logged in as on the next page load. The cookie itself is never sent.
$checkout_account_cookie = function ( $cookie, $expire, $expiration, $user_id ) use ( $checkout_account_state ) {
	$checkout_account_state->cookie_user = (int) $user_id;
};

/**
 * One test. Same as $t::test(), with web requests refused, no emails, no order receipts, no
 * login cookies sent, and the side add-ons off.
 */
$checkout_account_test = function ( $name, $callback ) use ( $t, $checkout_account_state, $checkout_account_side_hooks, $checkout_account_block_http, $checkout_account_cookie ) {
	$t::test(
		$name,
		function () use ( $callback, $checkout_account_state, $checkout_account_side_hooks, $checkout_account_block_http, $checkout_account_cookie ) {
			global $wp_filter;
			$checkout_account_state->cookie_user = 0;
			$checkout_account_state->sent        = array();
			add_filter( 'pre_http_request', $checkout_account_block_http, PHP_INT_MAX, 3 );
			add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
			add_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
			add_action( 'set_logged_in_cookie', $checkout_account_cookie, 10, 4 );
			add_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );

			$notify = isset( $wp_filter['sunshine_order_notify'] ) ? $wp_filter['sunshine_order_notify'] : null;
			unset( $wp_filter['sunshine_order_notify'] );

			$removed = array();
			foreach ( $checkout_account_side_hooks as $side ) {
				$priority = has_action( $side[0], $side[1] );
				if ( false === $priority ) {
					continue;
				}
				$args      = $wp_filter[ $side[0] ]->callbacks[ $priority ][ $side[1] ]['accepted_args'];
				$removed[] = array( $side[0], $side[1], $priority, $args );
				remove_action( $side[0], $side[1], $priority );
			}

			$filters_before = $GLOBALS['wp_current_filter'];
			try {
				$callback();
			} finally {
				$GLOBALS['wp_current_filter'] = $filters_before;
				remove_filter( 'pre_http_request', $checkout_account_block_http, PHP_INT_MAX );
				remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
				remove_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
				remove_action( 'set_logged_in_cookie', $checkout_account_cookie, 10 );
				remove_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
				if ( $notify ) {
					$wp_filter['sunshine_order_notify'] = $notify;
				}
				foreach ( $removed as $side ) {
					add_action( $side[0], $side[1], $side[2], $side[3] );
				}
			}
		}
	);
};

// A made-up email address no account has yet.
$checkout_account_email = function ( $label = 'shopper' ) {
	return 'spc-acct-' . $label . '-' . wp_rand( 100000, 999999 ) . '@example.com';
};

// Print any PHP warning that was printed into a captured response, so it is not lost.
$checkout_account_warnings = function ( $label, $output ) {
	if ( preg_match( '/(Warning|Notice|Deprecated|Fatal error)\b[^\n]{0,300}/', wp_strip_all_tags( (string) $output ), $match ) ) {
		WP_CLI::log( 'PHP message during ' . $label . ': ' . trim( $match[0] ) );
	}
};

/**
 * Send the Contact step the way the checkout page does: the sunshine_checkout_process_section
 * request with the form fields, as whoever is logged in right now (usually nobody).
 *
 * Returns 'ok' (the step was accepted), 'next' (the step the page moves to), 'errors' (what the
 * shopper is told), 'user_id' (the account with that email after the request, 0 if none),
 * 'made' (true when this request made that account) and 'cookie_user' (who the browser is
 * logged in as afterwards).
 */
$checkout_account_contact = function ( $fields ) use ( $t, $checkout_account_state, $checkout_account_warnings ) {
	global $wp_filter;
	$fields = array_merge(
		array(
			'first_name' => 'Avery',
			'last_name'  => 'Newcustomer',
			'email'      => '',
			'password'   => '',
		),
		$fields
	);
	$before = $fields['email'] ? get_user_by( 'email', $fields['email'] ) : false;

	// A request of its own: the cart is built again from what is saved, with no errors left
	// over from an earlier request.
	SPC()->cart = new SPC_Cart();

	// The test run makes a new SPC_Cart for every test, and each one adds its own
	// create_customer() to this hook. On a real page there is only one cart, so only the
	// current cart's hook is left on while the request runs.
	$hook  = 'sunshine_checkout_section_contact_process';
	$saved = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ] : null;
	if ( $saved ) {
		$wp_filter[ $hook ] = clone $saved;
		foreach ( $saved->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof SPC_Cart && $callback['function'][0] !== SPC()->cart ) {
					remove_action( $hook, $callback['function'], $priority );
				}
			}
		}
	}

	$old_post    = $_POST;
	$old_request = $_REQUEST;
	$post        = $fields;

	$post['sunshine_checkout_section'] = 'contact';
	$post['security']                  = wp_create_nonce( 'sunshine-checkout-process-section' );
	// WordPress adds slashes to posted form fields.
	$_POST    = wp_slash( $post );
	$_REQUEST = $_POST;

	$ajax = '__return_true';
	$die  = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'wp_doing_ajax', $ajax );
	add_filter( 'wp_die_ajax_handler', $die );
	ob_start();
	try {
		sunshine_checkout_process_section();
	} catch ( SPC_Totals_Test_Done $e ) {
		// The request always ends by sending a JSON answer.
	} finally {
		$output = ob_get_clean();
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );
		$_POST    = $old_post;
		$_REQUEST = $old_request;
		if ( $saved ) {
			$wp_filter[ $hook ] = $saved;
		}
	}
	$checkout_account_warnings( 'Contact step', $output );

	$after = $fields['email'] ? get_user_by( 'email', $fields['email'] ) : false;
	if ( $after && ! $before ) {
		$t::track( 'user', $after->ID );
	}
	$json = json_decode( $output, true );
	return array(
		'ok'          => ! empty( $json['success'] ),
		'next'        => isset( $json['data']['next_section'] ) ? $json['data']['next_section'] : '',
		'errors'      => SPC()->cart->get_errors(),
		'user_id'     => $after ? (int) $after->ID : 0,
		'made'        => ( $after && ! $before ),
		'cookie_user' => $checkout_account_state->cookie_user,
	);
};

// The browser's next page load, logged in as $user_id (0 for nobody). The cart is built again
// from what is saved, the way every page load builds it.
$checkout_account_next_page = function ( $user_id ) use ( $t ) {
	wp_set_current_user( $user_id );
	SPC()->customer = new SPC_Customer( $user_id );
	SPC()->cart     = new SPC_Cart();
	return $t::calculate();
};

$checkout_account_completed = function () {
	$completed = SPC()->session->get( 'checkout_sections_completed' );
	return is_array( $completed ) ? $completed : array();
};

/**
 * Fill in the address steps and place the order through the real init_order() request. Unlike
 * $t::place_order() the name and email are left as the shopper typed them in the Contact step.
 * Returns the saved order, or false when the order was refused.
 */
$checkout_account_place = function ( $payment_method = 'test' ) use ( $t, $checkout_account_warnings ) {
	$fields = array( 'shipping_as_billing' => 'yes' );
	foreach ( array( 'shipping_', 'billing_' ) as $prefix ) {
		$fields[ $prefix . 'first_name' ] = 'Avery';
		$fields[ $prefix . 'last_name' ]  = 'Newcustomer';
		$fields[ $prefix . 'address1' ]   = '1 Main St';
		$fields[ $prefix . 'city' ]       = 'Denver';
		$fields[ $prefix . 'state' ]      = 'CO';
		$fields[ $prefix . 'postcode' ]   = '80202';
		$fields[ $prefix . 'country' ]    = 'US';
	}
	foreach ( $fields as $key => $value ) {
		SPC()->cart->set_checkout_data_item( $key, $value );
	}

	$ajax = '__return_true';
	$die  = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'wp_doing_ajax', $ajax );
	add_filter( 'wp_die_ajax_handler', $die );
	$_POST['security']       = wp_create_nonce( 'sunshine-checkout-init-order' );
	$_POST['payment_method'] = $payment_method;
	SPC()->cart->set_checkout_data_item( 'payment_method', $payment_method );
	ob_start();
	try {
		SPC()->cart->init_order();
	} catch ( SPC_Totals_Test_Done $e ) {
		// init_order() always ends by sending a JSON answer.
	} finally {
		$output = ob_get_clean();
		unset( $_POST['security'], $_POST['payment_method'] );
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );
	}
	$checkout_account_warnings( 'placing the order', $output );

	$json = json_decode( $output, true );
	if ( empty( $json['success'] ) || empty( $json['data']['order_id'] ) ) {
		return false;
	}
	$t::track( 'order', $json['data']['order_id'] );
	return sunshine_get_order( $json['data']['order_id'] );
};

// The checkout's Place Order step: take the payment and finish the order. Returns the order as
// saved afterwards.
$checkout_account_finish = function ( $order ) use ( $t ) {
	$done = SPC()->cart->process_order();
	$t::same( true, ! empty( $done ), 'checkout finished the order ' . wp_json_encode( SPC()->cart->get_errors() ) );
	return sunshine_get_order( $order->get_id() );
};

// An account someone already made, the way WordPress saves one.
$checkout_account_existing = function ( $email ) use ( $t ) {
	$id = wp_insert_user(
		array(
			'user_login' => $email,
			'user_email' => $email,
			'user_pass'  => wp_generate_password(),
			'first_name' => 'Earlier',
			'last_name'  => 'Customer',
			'role'       => sunshine_get_customer_role(),
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new Exception( 'Could not make the earlier account: ' . $id->get_error_message() );
	}
	$t::track( 'user', $id );
	return $id;
};

/**
 * A Session Fee product saved the way its product screen saves it. The Session Fees tab saves
 * rows of a product ID (or "credits" / "download_credits") and a quantity, both as text. Each
 * $included row is array( product ID or 'credits' or 'download_credits', qty ).
 * With $with_date a session date is added too, the way the Schedule screen adds one: a published
 * sunshine-session post dated in the future, booked saved as false.
 * Returns array( product ID, session date ID or 0 ).
 */
$checkout_account_session = function ( $price, $included = array(), $with_date = false ) use ( $t ) {
	$t::require_plugin( 'sunshine_session_fees_checkout_create_order', 'Session Fees add-on' );
	$product_id = $t::product(
		$price,
		array(
			'name'    => 'Checkout account test session',
			'type'    => 'session-fee',
			'taxable' => false,
		)
	);
	$rows = array();
	foreach ( $included as $row ) {
		$rows[] = array(
			'product' => (string) $row[0],
			'qty'     => (string) $row[1],
		);
	}
	update_post_meta( $product_id, 'require_session', $with_date ? '1' : '' );
	update_post_meta( $product_id, 'session_products', $rows ? $rows : '' );

	$date_id = 0;
	if ( $with_date ) {
		$when    = gmdate( 'Y-m-d', time() + 40 * DAY_IN_SECONDS ) . ' 14:00:00';
		$date_id = wp_insert_post(
			array(
				'post_status'   => 'publish',
				'post_type'     => 'sunshine-session',
				'post_title'    => 'Session ' . substr( $when, 0, 10 ) . ' @ 14:00',
				'post_date'     => $when,
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $when ) ),
			)
		);
		$t::track( 'post', $date_id );
		update_post_meta( $date_id, 'booked', false );
	}
	return array( $product_id, $date_id );
};

// Add the session fee the way the session fees page's Book button does.
$checkout_account_add_session = function ( $product_id, $date_id = 0 ) {
	$product = sunshine_get_product( $product_id );
	$meta    = array();
	if ( $date_id ) {
		$meta['session_date'] = $date_id;
	}
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

// How many free prints of a product the customer still has from paid sessions.
$checkout_account_included_left = function ( $customer_id, $product_id ) {
	$left = 0;
	foreach ( (array) get_user_meta( $customer_id, 'sunshine_session_products', true ) as $row ) {
		if ( is_array( $row ) && isset( $row['product'], $row['qty'] ) && $row['product'] == $product_id && $row['qty'] > 0 ) {
			$left += $row['qty'];
		}
	}
	return $left;
};

$checkout_account_credits = function ( $customer_id ) {
	return ( new SPC_Customer( $customer_id ) )->get_credits();
};

/**
 * A discount code the way the Discounts screen saves one: every field is saved, empty ones as '',
 * lists as arrays. By default 10% off the order. Returns array( ID, code ).
 */
$checkout_account_code = function ( $meta = array() ) use ( $t ) {
	$t::require_plugin( 'sunshine_get_discount_by_code', 'Discount Codes add-on' );
	$code = 'SPCACCT' . wp_rand( 100000, 999999 );
	$id   = wp_insert_post(
		array(
			'post_type'   => 'sunshine-discount',
			'post_status' => 'publish',
			'post_title'  => 'Checkout account test ' . $code,
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

// What the Apply button at checkout does. True when the code was accepted.
$checkout_account_enter = function ( $code ) {
	$result = SPC()->cart->add_discount( $code );
	SPC()->cart->update();
	return ! empty( $result );
};

// The customer comes back another day logged in, puts a product in a fresh cart and types the
// code. True when the code is accepted.
$checkout_account_code_again = function ( $customer_id, $code, $product_id ) use ( $t, $checkout_account_next_page, $checkout_account_enter ) {
	foreach ( array( 'cart', 'discounts', 'checkout_data', 'checkout_sections_completed', 'gift_cards', 'gift_cards_applied', 'gift_card_amounts', 'use_credits', 'checkout_order_id' ) as $key ) {
		SPC()->session->set( $key, '' );
	}
	$checkout_account_next_page( $customer_id );
	$t::add( $product_id );
	$t::calculate();
	return $checkout_account_enter( $code );
};

// A session fee bought by a guest who makes an account in the Contact step, finished at
// checkout with the Test payment. Used by more than one test. Returns the IDs the checks need.
//
// The session is 150.00 (not taxed) with a date, and includes 2 free 12.00 prints, 50 in store
// credit and 3 download credits.
$checkout_account_buy_session = function () use ( $t, $checkout_account_session, $checkout_account_add_session, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish ) {
	$print                    = $t::product( 12, array( 'taxable' => false ) );
	list( $session, $date_id ) = $checkout_account_session(
		150,
		array(
			array( $print, 2 ),
			array( 'credits', 50 ),
			array( 'download_credits', 3 ),
		),
		true
	);
	$checkout_account_add_session( $session, $date_id );
	$t::money( 150, $t::calculate()->get_total(), 'session fee in the guest cart' );

	$email   = $checkout_account_email( 'session' );
	$contact = $checkout_account_contact(
		array(
			'email'    => $email,
			'password' => 'Session-Test-2026',
		)
	);
	$t::same( true, $contact['ok'], 'Contact step with a password is accepted ' . wp_json_encode( $contact['errors'] ) );
	$t::same( true, $contact['made'], 'an account is made for ' . $email );

	$cart = $checkout_account_next_page( $contact['cookie_user'] );
	$t::money( 150, $cart->get_total(), 'session fee still in the cart once logged in' );
	$order = $checkout_account_place( 'test' );
	if ( ! $order ) {
		throw new Exception( 'The session fee order was not placed: ' . wp_json_encode( SPC()->cart->get_errors() ) );
	}
	$order = $checkout_account_finish( $order );
	return array(
		'customer' => $contact['user_id'],
		'email'    => $email,
		'order'    => $order,
		'print'    => $print,
		'date'     => $date_id,
	);
};

/*
 * The account is made and the cart follows it.
 */

// 2 prints at 25.00 (taxed 8%), 1 print at 12.50 (not taxed) and 10.00 taxed shipping:
// 62.50 + (50.00 + 10.00) x 8% = 4.80 tax + 10.00 shipping = 77.30.
$checkout_account_test(
	'Account made at the Contact step: the cart moves to the new account and the totals stay the same',
	function () use ( $t, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_completed ) {
		$t::tax( 8 );
		$t::add( $t::product( 25 ), 2 );
		$t::add( $t::product( 12.50, array( 'taxable' => false ) ) );
		$t::flat_shipping( 10, true );
		$cart = $t::calculate();
		$t::money( 77.30, $cart->get_total(), 'guest cart total' );

		$email   = $checkout_account_email();
		$contact = $checkout_account_contact(
			array(
				'email'    => $email,
				'password' => 'Account-Test-2026',
			)
		);
		$t::same( true, $contact['ok'], 'Contact step with a password is accepted ' . wp_json_encode( $contact['errors'] ) );
		$t::same( true, $contact['made'], 'an account is made with the email typed' );
		$t::same( true, is_string( $contact['next'] ) && '' !== $contact['next'] && 'contact' !== $contact['next'], 'checkout moves on to the next step (' . $contact['next'] . ')' );
		$t::same( $contact['user_id'], $contact['cookie_user'], 'the browser is logged in as the new account' );
		$user = get_userdata( $contact['user_id'] );
		$t::same( true, $user && in_array( sunshine_get_customer_role(), (array) $user->roles, true ), 'new account is a Sunshine customer' );
		$t::same( 'Avery', $user ? $user->first_name : '', 'first name is saved on the account' );
		$t::same( true, in_array( 'contact', $checkout_account_completed(), true ), 'Contact step is done' );

		// The account keeps its own cart. It must hold the same lines the guest had.
		$saved = get_user_meta( $contact['user_id'], 'sunshine_cart', true );
		$t::same( 2, is_array( $saved ) ? count( $saved ) : 0, 'account cart has both lines' );
		$t::same( 3, is_array( $saved ) ? (int) array_sum( wp_list_pluck( $saved, 'qty' ) ) : 0, 'account cart has all 3 prints' );

		$cart = $checkout_account_next_page( $contact['cookie_user'] );
		$t::same( 3, $cart->get_item_count(), 'next page, logged in: 3 prints in the cart' );
		$t::money( 62.50, $cart->get_subtotal(), 'next page: subtotal' );
		$t::money( 10, $cart->get_shipping(), 'next page: shipping is still chosen' );
		$t::money( 4.80, $cart->get_tax(), 'next page: tax' );
		$t::money( 77.30, $cart->get_total(), 'next page: total' );

		// The customer goes back and fixes a typo in their name. Now logged in, so no second
		// account is made and the cart is not touched.
		$again = $checkout_account_contact(
			array(
				'first_name' => 'Avery-Jo',
				'email'      => $email,
				'password'   => '',
			)
		);
		$t::same( true, $again['ok'], 'editing the Contact step again is accepted ' . wp_json_encode( $again['errors'] ) );
		$cart = $checkout_account_next_page( $contact['user_id'] );
		$t::same( 3, $cart->get_item_count(), 'after editing: still 3 prints' );
		$t::money( 77.30, $cart->get_total(), 'after editing: total' );
	}
);

// 1 print at 40.00 (taxed 8%) and 5.00 shipping (not taxed): 40.00 + 3.20 + 5.00 = 48.20.
$checkout_account_test(
	'Account made at the Contact step: the order belongs to the new account and the cart is emptied after',
	function () use ( $t, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish ) {
		$t::tax( 8 );
		$t::add( $t::product( 40 ) );
		$t::flat_shipping( 5 );
		$t::money( 48.20, $t::calculate()->get_total(), 'guest cart total' );

		$email   = $checkout_account_email();
		$contact = $checkout_account_contact(
			array(
				'email'    => $email,
				'password' => 'Account-Test-2026',
			)
		);
		$t::same( true, $contact['made'], 'an account is made ' . wp_json_encode( $contact['errors'] ) );

		$cart = $checkout_account_next_page( $contact['cookie_user'] );
		$t::money( 48.20, $cart->get_total(), 'logged in cart total' );
		$order = $checkout_account_place( 'test' );
		$t::same( true, (bool) $order, 'order is placed ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $order ) {
			return;
		}
		$t::same( $contact['user_id'], (int) $order->get_customer_id(), 'order belongs to the new account' );
		$t::same( $email, $order->get_email(), 'order has the email the shopper typed' );
		$t::money( 48.20, $order->get_total(), 'order total' );
		$t::money( 3.20, $order->get_tax(), 'order tax' );

		$order = $checkout_account_finish( $order );
		$t::same( 'new', $order->get_status(), 'order is paid (New)' );
		$customer = new SPC_Customer( $contact['user_id'] );
		$t::same( 1, (int) $customer->get_order_count(), 'account shows 1 order' );
		$t::money( 48.20, $customer->get_order_totals(), 'account total spent' );

		// Nothing is left in the cart for the next visit, on the account or in the browser.
		$t::same( true, empty( get_user_meta( $contact['user_id'], 'sunshine_cart', true ) ), 'account cart is emptied' );
		$t::same( true, empty( SPC()->session->get( 'cart' ) ), 'browser cart is emptied' );
		$cart = $checkout_account_next_page( $contact['user_id'] );
		$t::same( true, $cart->is_empty(), 'next visit, logged in: cart is empty' );
		$t::money( 0, $cart->get_total(), 'next visit: nothing to pay' );
	}
);

// Guest checkout on and no password typed: the shopper stays a guest. 30.00 print, no tax.
$checkout_account_test(
	'No password with guest checkout on: no account is made and the order is a guest order',
	function () use ( $t, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish ) {
		$t::option( 'allow_guest_checkout', 1 );
		$t::add( $t::product( 30, array( 'taxable' => false ) ) );
		$t::calculate();

		$email   = $checkout_account_email( 'guest' );
		$contact = $checkout_account_contact( array( 'email' => $email ) );
		$t::same( true, $contact['ok'], 'Contact step without a password is accepted ' . wp_json_encode( $contact['errors'] ) );
		$t::same( 0, $contact['user_id'], 'no account is made' );
		$t::same( 0, $contact['cookie_user'], 'nobody is logged in' );

		$cart = $checkout_account_next_page( 0 );
		$t::money( 30, $cart->get_total(), 'cart total' );
		$order = $checkout_account_place( 'test' );
		$t::same( true, (bool) $order, 'guest order is placed ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $order ) {
			return;
		}
		$t::same( 0, (int) $order->get_customer_id(), 'order has no account' );
		$order = $checkout_account_finish( $order );
		$t::same( 'new', $order->get_status(), 'order is paid (New)' );
		$t::money( 30, $order->get_total(), 'order total' );
	}
);

// Guest checkout off: every shopper needs an account, so a password is required.
$checkout_account_test(
	'Guest checkout off: a password is required, and with one the order belongs to the new account',
	function () use ( $t, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish, $checkout_account_completed ) {
		$t::option( 'allow_guest_checkout', '' );
		$t::option( 'disable_signup', '' );
		$t::add( $t::product( 30, array( 'taxable' => false ) ), 2 );
		$t::calculate();

		$email = $checkout_account_email();
		$blank = $checkout_account_contact( array( 'email' => $email ) );
		$t::same( false, $blank['ok'], 'Contact step without a password is refused' );
		$t::same( true, in_array( 'Password is required', $blank['errors'], true ), 'shopper is told the password is required' );
		$t::same( false, in_array( 'contact', $checkout_account_completed(), true ), 'Contact step is not done' );
		$t::same( 0, $blank['user_id'], 'no account is made' );

		$contact = $checkout_account_contact(
			array(
				'email'    => $email,
				'password' => 'Account-Test-2026',
			)
		);
		$t::same( true, $contact['made'], 'with a password an account is made ' . wp_json_encode( $contact['errors'] ) );
		$cart = $checkout_account_next_page( $contact['cookie_user'] );
		$t::money( 60, $cart->get_total(), 'logged in cart total (2 x 30.00)' );
		$order = $checkout_account_place( 'test' );
		$t::same( true, (bool) $order, 'order is placed ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $order ) {
			return;
		}
		$t::same( $contact['user_id'], (int) $order->get_customer_id(), 'order belongs to the new account' );
		$order = $checkout_account_finish( $order );
		$t::money( 60, $order->get_total(), 'order total' );
	}
);

// 250 prints at 1.25 taxed 8% (0.10 tax each): 312.50 + 25.00 tax = 337.50. A big cart must
// move to the account whole.
$checkout_account_test(
	'Large quantity: 250 prints move to the new account and onto the order',
	function () use ( $t, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish ) {
		$t::tax( 8 );
		$t::add( $t::product( 1.25 ), 250 );
		$t::money( 337.50, $t::calculate()->get_total(), 'guest cart total' );

		$contact = $checkout_account_contact(
			array(
				'email'    => $checkout_account_email(),
				'password' => 'Account-Test-2026',
			)
		);
		$t::same( true, $contact['made'], 'an account is made ' . wp_json_encode( $contact['errors'] ) );
		$cart = $checkout_account_next_page( $contact['cookie_user'] );
		$t::same( 250, $cart->get_item_count(), 'logged in: 250 prints' );
		$t::money( 25, $cart->get_tax(), 'logged in: tax' );
		$t::money( 337.50, $cart->get_total(), 'logged in: total' );

		$order = $checkout_account_place( 'test' );
		$t::same( true, (bool) $order, 'order is placed ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $order ) {
			return;
		}
		$order = $checkout_account_finish( $order );
		$items = $order->get_items();
		$line  = $items ? reset( $items ) : null;
		$t::same( 250, $line ? (int) $line->get_qty() : 0, 'order line has 250 prints' );
		$t::money( 25, $order->get_tax(), 'order tax' );
		$t::money( 337.50, $order->get_total(), 'order total' );
	}
);

/*
 * Session fees: what comes with the session has to land on the account.
 */

$checkout_account_test(
	'Session fee: leaving the password blank is refused, even with guest checkout on',
	function () use ( $t, $checkout_account_session, $checkout_account_add_session, $checkout_account_email, $checkout_account_contact, $checkout_account_completed ) {
		$t::option( 'allow_guest_checkout', 1 );
		list( $session ) = $checkout_account_session( 200, array( array( 'credits', 25 ) ) );
		$checkout_account_add_session( $session );
		$t::calculate();
		$t::same( true, SPC()->cart->needs_account(), 'a session fee needs an account' );

		$contact = $checkout_account_contact( array( 'email' => $checkout_account_email() ) );
		$t::same( false, $contact['ok'], 'Contact step without a password is refused' );
		$t::same( true, in_array( 'Password is required', $contact['errors'], true ), 'shopper is told the password is required' );
		$t::same( false, in_array( 'contact', $checkout_account_completed(), true ), 'Contact step is not done' );
		$t::same( 0, $contact['user_id'], 'no account is made' );
	}
);

// The session is 150.00 with a date, 2 free prints, 50 in credit and 3 download credits.
$checkout_account_test(
	'Session fee bought by a guest who makes an account: the credits, free prints and download credits go to the new account',
	function () use ( $t, $checkout_account_buy_session, $checkout_account_included_left, $checkout_account_credits ) {
		$bought = $checkout_account_buy_session();
		$order  = $bought['order'];
		$t::same( $bought['customer'], (int) $order->get_customer_id(), 'order belongs to the new account' );
		$t::same( 'new', $order->get_status(), 'order is paid (New)' );
		$t::money( 150, $order->get_total(), 'order total' );

		$t::money( 50, $checkout_account_credits( $bought['customer'] ), 'new account has the 50.00 store credit from the session' );
		$t::same( 2, (int) $checkout_account_included_left( $bought['customer'], $bought['print'] ), 'new account has the 2 free prints' );
		$t::same( 3, (int) get_user_meta( $bought['customer'], 'sunshine_download_credits', true ), 'new account has the 3 download credits' );

		$date = sunshine_get_session_date( $bought['date'] );
		$t::same( true, $date->is_booked(), 'session date is booked' );
		$t::same( (string) $order->get_id(), (string) $date->get_order_id(), 'session date is tied to the order' );
		$dates = get_user_meta( $bought['customer'], 'sunshine_session_dates', true );
		$t::same( array( (string) $bought['date'] ), array_map( 'strval', (array) $dates ), 'session date is on the new account' );
	}
);

// Next visit, logged in: 3 of the 12.00 print. 2 are free from the session, so 12.00 is left,
// and the 50.00 credit pays it: nothing to pay, 38.00 credit left, no free prints left.
$checkout_account_test(
	'Session bought at sign up: its free prints and credit pay for the next order',
	function () use ( $t, $checkout_account_buy_session, $checkout_account_next_page, $checkout_account_contact, $checkout_account_place, $checkout_account_finish, $checkout_account_included_left, $checkout_account_credits ) {
		$bought = $checkout_account_buy_session();

		$cart = $checkout_account_next_page( $bought['customer'] );
		$t::same( true, $cart->is_empty(), 'cart is empty after the session order' );
		$t::add( $bought['print'], 3 );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$cart = $t::calculate();
		$t::money( 12, $cart->get_total( array( 'credits' ) ), 'after the 2 free prints: 12.00' );
		$t::money( 12, $cart->get_credits_applied(), 'store credit used' );
		$t::money( 0, $cart->get_total(), 'nothing left to pay' );

		// The last order cleared the checkout form, so the Contact step is sent again. The
		// customer is logged in now, so no password is asked for.
		$contact = $checkout_account_contact( array( 'email' => $bought['email'] ) );
		$t::same( true, $contact['ok'], 'Contact step, logged in, is accepted ' . wp_json_encode( $contact['errors'] ) );
		$checkout_account_next_page( $bought['customer'] );

		$order = $checkout_account_place( 'free' );
		$t::same( true, (bool) $order, 'order is placed ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $order ) {
			return;
		}
		$t::same( $bought['customer'], (int) $order->get_customer_id(), 'order belongs to the account' );
		$t::money( 12, $order->get_credits(), 'store credit on the order' );
		$order = $checkout_account_finish( $order );
		$t::same( true, $order->is_paid(), 'order is paid' );
		$t::money( 0, $order->get_total(), 'order total' );
		$t::money( 38, $checkout_account_credits( $bought['customer'] ), 'store credit left: 50.00 - 12.00' );
		$t::same( 0, (int) $checkout_account_included_left( $bought['customer'], $bought['print'] ), 'no free prints left' );
	}
);

// A session (150.00, not taxed, 30.00 credit included) and 2 prints at 25.00 taxed 8%, with
// 10.00 taxed shipping: 150.00 + 50.00 + (50.00 + 10.00) x 8% = 4.80 + 10.00 = 214.80.
$checkout_account_test(
	'Session fee and prints in one order, bought by a guest who makes an account',
	function () use ( $t, $checkout_account_session, $checkout_account_add_session, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish, $checkout_account_credits ) {
		$t::tax( 8 );
		list( $session ) = $checkout_account_session( 150, array( array( 'credits', 30 ) ) );
		$checkout_account_add_session( $session );
		$t::add( $t::product( 25 ), 2 );
		$t::flat_shipping( 10, true );
		$cart = $t::calculate();
		$t::money( 214.80, $cart->get_total(), 'guest cart total' );

		$contact = $checkout_account_contact(
			array(
				'email'    => $checkout_account_email(),
				'password' => 'Account-Test-2026',
			)
		);
		$t::same( true, $contact['made'], 'an account is made ' . wp_json_encode( $contact['errors'] ) );
		$cart = $checkout_account_next_page( $contact['cookie_user'] );
		$t::money( 200, $cart->get_subtotal(), 'logged in: subtotal' );
		$t::money( 4.80, $cart->get_tax(), 'logged in: tax' );
		$t::money( 214.80, $cart->get_total(), 'logged in: total' );

		$order = $checkout_account_place( 'test' );
		$t::same( true, (bool) $order, 'order is placed ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $order ) {
			return;
		}
		$order = $checkout_account_finish( $order );
		$t::same( $contact['user_id'], (int) $order->get_customer_id(), 'order belongs to the new account' );
		$t::money( 10, $order->get_shipping(), 'order shipping' );
		$t::money( 4.80, $order->get_tax(), 'order tax' );
		$t::money( 214.80, $order->get_total(), 'order total' );
		$t::money( 30, $checkout_account_credits( $contact['user_id'] ), 'new account has the 30.00 credit from the session' );
	}
);

// A free consultation session (0.00) that comes with 25.00 credit and 1 free print. Nothing to
// pay, but the account still has to be made and given what the session includes.
$checkout_account_test(
	'Free session (0.00) with credit: the credit and the free print go to the new account',
	function () use ( $t, $checkout_account_session, $checkout_account_add_session, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish, $checkout_account_credits, $checkout_account_included_left ) {
		$print           = $t::product( 15, array( 'taxable' => false ) );
		list( $session ) = $checkout_account_session(
			0,
			array(
				array( 'credits', 25 ),
				array( $print, 1 ),
			)
		);
		$checkout_account_add_session( $session );
		$t::money( 0, $t::calculate()->get_total(), 'guest cart total' );

		$contact = $checkout_account_contact(
			array(
				'email'    => $checkout_account_email(),
				'password' => 'Account-Test-2026',
			)
		);
		$t::same( true, $contact['made'], 'an account is made ' . wp_json_encode( $contact['errors'] ) );
		$checkout_account_next_page( $contact['cookie_user'] );
		$order = $checkout_account_place( 'free' );
		$t::same( true, (bool) $order, 'order is placed ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $order ) {
			return;
		}
		$order = $checkout_account_finish( $order );
		$t::same( true, $order->is_paid(), 'order is paid' );
		$t::same( $contact['user_id'], (int) $order->get_customer_id(), 'order belongs to the new account' );
		$t::money( 25, $checkout_account_credits( $contact['user_id'] ), 'new account has the 25.00 credit' );
		$t::same( 1, (int) $checkout_account_included_left( $contact['user_id'], $print ), 'new account has the free print' );
	}
);

// Shared setup: a guest with a 100.00 session fee (40.00 credit included) types an email that
// already has an account, with a password.
$checkout_account_existing_email = function () use ( $t, $checkout_account_session, $checkout_account_add_session, $checkout_account_email, $checkout_account_existing, $checkout_account_contact ) {
	$t::option( 'allow_guest_checkout', 1 );
	$email    = $checkout_account_email( 'earlier' );
	$existing = $checkout_account_existing( $email );
	list( $session ) = $checkout_account_session( 100, array( array( 'credits', 40 ) ) );
	$checkout_account_add_session( $session );
	$t::calculate();
	$contact = $checkout_account_contact(
		array(
			'email'    => $email,
			'password' => 'Account-Test-2026',
		)
	);
	return array( $existing, $contact );
};

// The checkout says "please login to continue". Until they log in, the Contact step is not done,
// or the shopper can carry on as a guest with a cart that needs an account.
$checkout_account_test(
	'Signing up with an email that already has an account: the Contact step stays open',
	function () use ( $t, $checkout_account_existing_email, $checkout_account_completed ) {
		list( $existing, $contact ) = $checkout_account_existing_email();
		$t::same( false, $contact['ok'], 'Contact step is refused' );
		$t::same( true, (bool) preg_grep( '/already exists/', $contact['errors'] ), 'shopper is told to log in' );
		$t::same( 0, $contact['cookie_user'], 'nobody is logged in' );
		$t::same( false, $contact['made'], 'no second account is made' );
		$t::same( false, in_array( 'contact', $checkout_account_completed(), true ), 'Contact step is not done' );
	}
);

// A session's credit and free prints are kept on an account. The order must not go through with
// nobody logged in, or the shopper pays for them and they go to nobody.
$checkout_account_test(
	'Signing up with an email that already has an account: the session fee cannot be bought as a guest',
	function () use ( $t, $checkout_account_existing_email, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish, $checkout_account_credits ) {
		list( $existing, $contact ) = $checkout_account_existing_email();
		$cart = $checkout_account_next_page( 0 );
		$t::money( 100, $cart->get_total(), 'cart total' );
		$order = $checkout_account_place( 'test' );
		$t::same( false, (bool) $order, 'order is refused while nobody is logged in' );
		if ( ! $order ) {
			return;
		}
		$order = $checkout_account_finish( $order );
		$t::same( true, (int) $order->get_customer_id() > 0, 'the paid session belongs to an account (customer ' . (int) $order->get_customer_id() . ', paid ' . $order->get_total() . ')' );
		$t::money( 0, $checkout_account_credits( $existing ), 'the earlier account (not logged in) is not given the credit by email alone' );
	}
);

// The shopper finished the Contact step as a guest with only a print in the cart (guest checkout
// is on), then added a session fee. The cart now needs an account.
$checkout_account_test(
	'Session fee added after the Contact step was done as a guest: the order is not placed as a guest',
	function () use ( $t, $checkout_account_session, $checkout_account_add_session, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish, $checkout_account_completed ) {
		$t::option( 'allow_guest_checkout', 1 );
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$t::calculate();
		$contact = $checkout_account_contact( array( 'email' => $checkout_account_email( 'guest' ) ) );
		$t::same( true, $contact['ok'], 'Contact step as a guest is accepted ' . wp_json_encode( $contact['errors'] ) );

		list( $session ) = $checkout_account_session( 100, array( array( 'credits', 25 ) ) );
		$checkout_account_add_session( $session );
		$cart = $checkout_account_next_page( 0 );
		$t::same( true, $cart->needs_account(), 'the cart now needs an account' );
		$t::money( 120, $cart->get_total(), 'cart total (20.00 + 100.00)' );
		$reopened = ! in_array( 'contact', $checkout_account_completed(), true );

		$order = $checkout_account_place( 'test' );
		$t::same( false, (bool) $order, 'order is refused while nobody is logged in (Contact step reopened: ' . ( $reopened ? 'yes' : 'no' ) . ')' );
		if ( ! $order ) {
			return;
		}
		$order = $checkout_account_finish( $order );
		$t::same( true, (int) $order->get_customer_id() > 0, 'the paid session belongs to an account (customer ' . (int) $order->get_customer_id() . ', paid ' . $order->get_total() . ')' );
	}
);

// Emails are used as the WordPress username, and WordPress usernames stop at 60 characters.
// A valid email that is longer can't become an account the same way.
$checkout_account_test(
	'Email longer than 60 characters: the Contact step does not pass, and the session fee is not bought as a guest',
	function () use ( $t, $checkout_account_session, $checkout_account_add_session, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_completed ) {
		$t::option( 'allow_guest_checkout', 1 );
		list( $session ) = $checkout_account_session( 100, array( array( 'credits', 40 ) ) );
		$checkout_account_add_session( $session );
		$t::calculate();
		// 'spc-acct-long-' is 14 characters, then 6 digits, 40 letters and '@example.com' (12): 72.
		$email = 'spc-acct-long-' . wp_rand( 100000, 999999 ) . str_repeat( 'x', 40 ) . '@example.com';
		$t::same( true, (bool) is_email( $email ), 'the email is a valid address' );

		$contact = $checkout_account_contact(
			array(
				'email'    => $email,
				'password' => 'Account-Test-2026',
			)
		);
		if ( $contact['made'] ) {
			// An account was made after all, so it is a normal sign up.
			$t::same( $contact['user_id'], $contact['cookie_user'], 'the browser is logged in as the new account' );
			return;
		}
		$t::same( false, $contact['ok'], 'Contact step is refused when the account cannot be made (' . implode( ' / ', $contact['errors'] ) . ')' );
		$t::same( false, in_array( 'contact', $checkout_account_completed(), true ), 'Contact step is not done' );
		$checkout_account_next_page( 0 );
		$order = $checkout_account_place( 'test' );
		$t::same( false, (bool) $order, 'session fee order is refused while nobody is logged in' );
	}
);

/*
 * Codes, gift cards and tax settings carry over to the account.
 */

// 2 prints at 25.00 (taxed 8%), 10.00 shipping (not taxed), 10% off the order:
// discount 5.00, tax (50.00 - 5.00) x 8% = 3.60, total 50.00 - 5.00 + 3.60 + 10.00 = 58.60.
$checkout_account_test(
	'Discount code entered as a guest stays on after making an account, and the order matches',
	function () use ( $t, $checkout_account_code, $checkout_account_enter, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish ) {
		$t::tax( 8 );
		$t::add( $t::product( 25 ), 2 );
		$t::flat_shipping( 10 );
		$t::calculate();
		list( $id, $code ) = $checkout_account_code();
		$t::same( true, $checkout_account_enter( $code ), 'code accepted for the guest' );
		$cart = $t::calculate();
		$t::money( 5, $cart->get_discount(), 'guest discount' );
		$t::money( 58.60, $cart->get_total(), 'guest total' );

		$contact = $checkout_account_contact(
			array(
				'email'    => $checkout_account_email(),
				'password' => 'Account-Test-2026',
			)
		);
		$t::same( true, $contact['made'], 'an account is made ' . wp_json_encode( $contact['errors'] ) );
		$cart = $checkout_account_next_page( $contact['cookie_user'] );
		$t::money( 5, $cart->get_discount(), 'logged in: discount' );
		$t::money( 3.60, $cart->get_tax(), 'logged in: tax' );
		$t::money( 58.60, $cart->get_total(), 'logged in: total' );

		$order = $checkout_account_place( 'test' );
		$t::same( true, (bool) $order, 'order is placed ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $order ) {
			return;
		}
		$order = $checkout_account_finish( $order );
		$t::same( $contact['user_id'], (int) $order->get_customer_id(), 'order belongs to the new account' );
		$t::money( 5, $order->get_discount(), 'order discount' );
		$t::money( 3.60, $order->get_tax(), 'order tax' );
		$t::money( 58.60, $order->get_total(), 'order total' );
		$t::same( 1, (int) get_post_meta( $id, 'use_count', true ), 'code use counted once' );
	}
);

// "Max uses per person" is counted for the logged in customer. A code typed while still a guest
// must count for the account the shopper made, so the same customer can't use it twice.
// 60.00 print, 10% off: 54.00.
$checkout_account_test(
	'Once-per-customer code entered as a guest counts for the new account',
	function () use ( $t, $checkout_account_code, $checkout_account_enter, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish, $checkout_account_code_again ) {
		$product           = $t::product( 60, array( 'taxable' => false ) );
		list( $id, $code ) = $checkout_account_code( array( 'max_uses_per_person' => '1' ) );
		$t::add( $product );
		$t::calculate();
		$t::same( true, $checkout_account_enter( $code ), 'code accepted for the guest' );

		$email   = $checkout_account_email();
		$contact = $checkout_account_contact(
			array(
				'email'    => $email,
				'password' => 'Account-Test-2026',
			)
		);
		$t::same( true, $contact['made'], 'an account is made ' . wp_json_encode( $contact['errors'] ) );
		$cart = $checkout_account_next_page( $contact['cookie_user'] );
		$t::money( 6, $cart->get_discount(), 'logged in: code still gives 10% off' );
		$t::money( 54, $cart->get_total(), 'logged in: total' );
		$order = $checkout_account_place( 'test' );
		$t::same( true, (bool) $order, 'order is placed ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $order ) {
			return;
		}
		$order = $checkout_account_finish( $order );
		$t::money( 54, $order->get_total(), 'order total' );

		$discount = sunshine_get_discount( $id );
		$t::same( 1, $discount->get_use_count(), 'code use counted once' );
		// Each use is saved as a "used_by" row for the email and one for the logged in account.
		$used_by = array_map( 'strval', (array) get_post_meta( $id, 'used_by' ) );
		$t::same( 1, count( array_keys( $used_by, (string) $contact['user_id'], true ) ), 'use saved for the new account' );
		$t::same( 1, count( array_keys( $used_by, $email, true ) ), 'use saved for the email' );

		$t::same( false, $checkout_account_code_again( $contact['user_id'], $code, $product ), 'the same customer is refused the code next time' );
		$t::same( true, $checkout_account_code_again( $t::customer(), $code, $product ), 'a different customer can still use it' );
	}
);

// 2 prints at 25.00 (taxed 8%) and 10.00 taxed shipping: 50.00 + 4.00 + 10.00 + 0.80 = 64.80.
// A 30.00 gift card typed as a guest pays 30.00, so 34.80 is left to pay.
$checkout_account_test(
	'Gift card entered as a guest still pays after making an account',
	function () use ( $t, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish ) {
		$t::tax( 8 );
		$t::add( $t::product( 25 ), 2 );
		$t::flat_shipping( 10, true );
		$card = $t::gift_card( 30 );
		$cart = $t::calculate();
		$t::money( 34.80, $cart->get_total(), 'guest: left to pay' );

		$contact = $checkout_account_contact(
			array(
				'email'    => $checkout_account_email(),
				'password' => 'Account-Test-2026',
			)
		);
		$t::same( true, $contact['made'], 'an account is made ' . wp_json_encode( $contact['errors'] ) );
		$cart = $checkout_account_next_page( $contact['cookie_user'] );
		// The gift card is taken off as a fee, so leaving out fees gives the order before it.
		$t::money( 64.80, $cart->get_total( array( 'fees' ) ), 'logged in: order before the gift card' );
		$t::money( 34.80, $cart->get_total(), 'logged in: left to pay' );

		$order = $checkout_account_place( 'test' );
		$t::same( true, (bool) $order, 'order is placed ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $order ) {
			return;
		}
		$order = $checkout_account_finish( $order );
		$t::same( $contact['user_id'], (int) $order->get_customer_id(), 'order belongs to the new account' );
		$t::money( 34.80, $order->get_total(), 'order total' );
		$fees = (array) $order->get_fees();
		$t::money( -30, isset( $fees['gift_card'] ) ? $fees['gift_card']['amount'] : 0, 'gift card on the order' );
		$t::money( 0, (float) get_post_meta( $card, 'balance', true ), 'gift card is used up' );
	}
);

// Prices include 10% tax. 2 prints at 55.00 (50.00 + 5.00 tax each) and 8.00 shipping (not
// taxed): tax 10.00, total 110.00 + 8.00 = 118.00.
$checkout_account_test(
	'Prices include tax: totals are the same after making an account, and the order matches',
	function () use ( $t, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish ) {
		$t::option( 'price_has_tax', 'yes' );
		$t::tax( 10 );
		$t::add( $t::product( 55 ), 2 );
		$t::flat_shipping( 8 );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_tax(), 'guest: tax' );
		$t::money( 118, $cart->get_total(), 'guest: total' );

		$contact = $checkout_account_contact(
			array(
				'email'    => $checkout_account_email(),
				'password' => 'Account-Test-2026',
			)
		);
		$t::same( true, $contact['made'], 'an account is made ' . wp_json_encode( $contact['errors'] ) );
		$cart = $checkout_account_next_page( $contact['cookie_user'] );
		$t::money( 10, $cart->get_tax(), 'logged in: tax' );
		$t::money( 118, $cart->get_total(), 'logged in: total' );

		$order = $checkout_account_place( 'test' );
		$t::same( true, (bool) $order, 'order is placed ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $order ) {
			return;
		}
		$order = $checkout_account_finish( $order );
		$t::same( $contact['user_id'], (int) $order->get_customer_id(), 'order belongs to the new account' );
		$t::money( 10, $order->get_tax(), 'order tax' );
		$t::money( 118, $order->get_total(), 'order total' );
	}
);

// Discount after tax: 100.00 print + 10% tax = 110.00, then 20% off that = 22.00: 88.00.
$checkout_account_test(
	'Discount after tax: totals are the same after making an account, and the order matches',
	function () use ( $t, $checkout_account_code, $checkout_account_enter, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		list( $id, $code ) = $checkout_account_code( array( 'discount_amount' => '20' ) );
		$t::same( true, $checkout_account_enter( $code ), 'code accepted for the guest' );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_tax(), 'guest: tax' );
		$t::money( 88, $cart->get_total(), 'guest: total' );

		$contact = $checkout_account_contact(
			array(
				'email'    => $checkout_account_email(),
				'password' => 'Account-Test-2026',
			)
		);
		$t::same( true, $contact['made'], 'an account is made ' . wp_json_encode( $contact['errors'] ) );
		$cart = $checkout_account_next_page( $contact['cookie_user'] );
		$t::money( 10, $cart->get_tax(), 'logged in: tax' );
		$t::money( 88, $cart->get_total(), 'logged in: total' );

		$order = $checkout_account_place( 'test' );
		$t::same( true, (bool) $order, 'order is placed ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $order ) {
			return;
		}
		$order = $checkout_account_finish( $order );
		$t::money( 10, $order->get_tax(), 'order tax' );
		$t::money( 88, $order->get_total(), 'order total' );
	}
);

/*
 * An order started as a guest, finished with an account.
 */

// The shopper got as far as paying as a guest (the order is saved, waiting for payment), went
// back to the Contact step and typed a password. The same order is used when they pay, and it
// must now belong to the account. 30.00 print, no tax.
$checkout_account_test(
	'Order started as a guest, account made before paying: the same order belongs to the account',
	function () use ( $t, $checkout_account_email, $checkout_account_contact, $checkout_account_next_page, $checkout_account_place, $checkout_account_finish ) {
		$t::option( 'allow_guest_checkout', 1 );
		$t::add( $t::product( 30, array( 'taxable' => false ) ) );
		$t::calculate();
		$email = $checkout_account_email();
		$guest = $checkout_account_contact( array( 'email' => $email ) );
		$t::same( true, $guest['ok'], 'Contact step as a guest is accepted ' . wp_json_encode( $guest['errors'] ) );
		$checkout_account_next_page( 0 );
		$first = $checkout_account_place( 'test' );
		$t::same( true, (bool) $first, 'order is saved while the guest pays ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $first ) {
			return;
		}
		$t::same( 0, (int) $first->get_customer_id(), 'saved as a guest order for now' );

		// Back to the Contact step, this time with a password.
		$checkout_account_next_page( 0 );
		$contact = $checkout_account_contact(
			array(
				'email'    => $email,
				'password' => 'Account-Test-2026',
			)
		);
		$t::same( true, $contact['made'], 'an account is made ' . wp_json_encode( $contact['errors'] ) );
		$cart = $checkout_account_next_page( $contact['cookie_user'] );
		$t::money( 30, $cart->get_total(), 'logged in: total' );
		$order = $checkout_account_place( 'test' );
		$t::same( true, (bool) $order, 'order is placed again ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $order ) {
			return;
		}
		$t::same( $first->get_id(), $order->get_id(), 'the same order is used' );
		$order = $checkout_account_finish( $order );
		$t::same( $contact['user_id'], (int) $order->get_customer_id(), 'order belongs to the new account' );
		$t::money( 30, $order->get_total(), 'order total' );
		$customer = new SPC_Customer( $contact['user_id'] );
		$t::same( 1, (int) $customer->get_order_count(), 'account shows 1 order' );
		$t::money( 30, $customer->get_order_totals(), 'account total spent' );
	}
);

/*
 * Store credit and who is logged in when the order is finished.
 */

// A logged in customer with 20.00 store credit buys a 100.00 session that comes with 50.00 credit,
// and uses the 20.00 on it: 80.00 to pay. Once paid the 20.00 is spent and the session's 50.00
// is added: 20.00 - 20.00 + 50.00 = 50.00 left.
$checkout_account_test(
	'Store credit spent on a session that comes with credit: the new credit is kept',
	function () use ( $t, $checkout_account_session, $checkout_account_add_session, $checkout_account_place, $checkout_account_finish, $checkout_account_credits ) {
		$customer = $t::customer();
		// Store credit the way the customer's admin screen saves it.
		( new SPC_Customer( $customer ) )->set_credits( '20' );
		SPC()->customer = new SPC_Customer( $customer );
		SPC()->cart     = new SPC_Cart();
		list( $session ) = $checkout_account_session( 100, array( array( 'credits', 50 ) ) );
		$checkout_account_add_session( $session );
		SPC()->cart->set_checkout_data_item( 'email', 'spc-acct-credit-' . $customer . '@example.com' );
		SPC()->cart->set_checkout_data_item( 'first_name', 'Avery' );
		SPC()->cart->set_checkout_data_item( 'last_name', 'Newcustomer' );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$cart = $t::calculate();
		$t::money( 20, $cart->get_credits_applied(), 'store credit used' );
		$t::money( 80, $cart->get_total(), 'left to pay' );

		$order = $checkout_account_place( 'test' );
		$t::same( true, (bool) $order, 'order is placed ' . wp_json_encode( SPC()->cart->get_errors() ) );
		if ( ! $order ) {
			return;
		}
		$t::money( 20, $order->get_credits(), 'store credit on the order' );
		$order = $checkout_account_finish( $order );
		$t::same( 'new', $order->get_status(), 'order is paid (New)' );
		$t::money( 50, $checkout_account_credits( $customer ), 'store credit left: 20.00 - 20.00 + 50.00 from the session' );
	}
);

// Delete the Analytics rows for the accounts and products made here.
( function () use ( $checkout_account_made, $checkout_account_remember_post, $checkout_account_remember_user ) {
	global $wpdb;
	remove_action( 'wp_insert_post', $checkout_account_remember_post, 10 );
	remove_action( 'user_register', $checkout_account_remember_user );
	$table = $wpdb->prefix . 'sunshine_actions';
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		return;
	}
	$users = array_map( 'intval', array_unique( $checkout_account_made->users ) );
	if ( $users ) {
		$wpdb->query( "DELETE FROM {$table} WHERE customer_id IN (" . implode( ',', $users ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$products = array_map( 'intval', array_unique( $checkout_account_made->products ) );
	if ( $products ) {
		$wpdb->query( "DELETE FROM {$table} WHERE action_type = 'add_to_cart' AND action_object_id IN (" . implode( ',', $products ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
} )();
