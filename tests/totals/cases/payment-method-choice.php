<?php
/**
 * Which payment method the checkout lets an order go through with.
 *
 * The customer picks how they pay on the last checkout step. The checkout saves whatever the
 * request names for "payment_method" straight away, before any check (SPC_Cart::process_section,
 * class-cart.php:2337). The order then takes that value when it is saved (init_order,
 * class-cart.php:2687), and the one check when the order is finished (process_order,
 * class-cart.php:2771) only stops two things: the "Free" method when money is still owed, and a
 * method that does not match the one saved on the order.
 *
 * Nothing stops a request from naming a method the customer is not allowed to use at all -- a
 * method that is turned off, a method only admins may use, or the Gift Cards add-on's own
 * internal "gift-card" method. Because the order copies that same value, the mismatch check at
 * 2771 never fires, and methods like "test" and "gift-card" mark an order paid ("new") without
 * ever taking a payment. So a crafted checkout request can finish an order as paid, send the
 * receipts, and charge nothing.
 *
 * These tests drive the real checkout handlers with crafted requests (the nonce set the way the
 * checkout sets it, init_order's JSON reply and process_order captured). The expected results
 * are the rule a photographer would expect: the checkout only finishes an order through a
 * payment method the customer was actually allowed to use, and never marks an order paid while
 * money is owed. Tests that fail mark a real bug (listed in the findings).
 *
 * Nothing leaves the site: every web request, email and order receipt is blocked while each
 * test runs, and the add-ons that only report an order to an outside service are switched off.
 */

$t = 'SPC_Totals_Test';

// Add-ons on sunshine_checkout_create_order that have nothing to do with the money (mailing
// lists, Light Blue, queued follow-up emails, Analytics rows). Taken off while a test finishes
// an order, so a test leaves nothing behind in those tables.
$pmc_side_hooks = array(
	array( 'sunshine_checkout_create_order', 'sunshine_mailchimp_order_create' ),
	array( 'sunshine_checkout_create_order', 'sunshine_campaign_monitor_order_create' ),
	array( 'sunshine_checkout_create_order', 'sunshine_lightblue_order_post_process' ),
	array( 'sunshine_checkout_create_order', 'sunshine_adv_reports_order_create' ),
	array( 'sunshine_checkout_create_order', 'sunshine_email_queue_after_order' ),
	array( 'sunshine_order_create', 'sunshine_adv_reports_order_create' ),
);

// Remember any queued-email posts a finished order makes, so they can be deleted at the end.
$pmc_emails   = array();
$pmc_remember = function ( $post_id, $post ) use ( &$pmc_emails ) {
	if ( 'sunshine-email-log' === $post->post_type ) {
		$pmc_emails[] = (int) $post_id;
	}
};
add_action( 'wp_insert_post', $pmc_remember, 10, 2 );

// One test with every outside request, email and order receipt blocked and the side add-ons off.
$pmc_test = function ( $name, $body ) use ( $t, $pmc_side_hooks ) {
	$t::test(
		$name,
		function () use ( $body, $pmc_side_hooks ) {
			global $wp_filter;
			$offline = function () {
				return new WP_Error( 'spc_totals_offline', 'The totals tests never reach outside services' );
			};
			$no = function () {
				return false;
			};
			add_filter( 'pre_http_request', $offline, PHP_INT_MAX, 3 );
			add_filter( 'pre_wp_mail', $no, PHP_INT_MAX );
			add_filter( 'sunshine_checkout_allow_order_notify', $no, PHP_INT_MAX );

			$notify = isset( $wp_filter['sunshine_order_notify'] ) ? $wp_filter['sunshine_order_notify'] : null;
			unset( $wp_filter['sunshine_order_notify'] );

			$removed = array();
			foreach ( $pmc_side_hooks as $side ) {
				$priority = has_action( $side[0], $side[1] );
				if ( false === $priority ) {
					continue;
				}
				$args      = $wp_filter[ $side[0] ]->callbacks[ $priority ][ $side[1] ]['accepted_args'];
				$removed[] = array( $side[0], $side[1], $priority, $args );
				remove_action( $side[0], $side[1], $priority );
			}

			try {
				$body();
			} finally {
				remove_filter( 'pre_http_request', $offline, PHP_INT_MAX );
				remove_filter( 'pre_wp_mail', $no, PHP_INT_MAX );
				remove_filter( 'sunshine_checkout_allow_order_notify', $no, PHP_INT_MAX );
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

// Place the order through the real checkout step (init_order) naming $method, then run the step
// that finishes it (process_order). Returns array( init order, process result, order reloaded ).
$pmc_place_and_finish = function ( $method ) use ( $t ) {
	$order  = $t::place_order( $method );
	$result = SPC()->cart->process_order();
	$fresh  = sunshine_get_order( $order->get_id() );
	return array( $order, $result, $fresh );
};

// ---------------------------------------------------------------------------------------------
// The bugs: a crafted request names a method the customer may not use, and the order finishes.
// ---------------------------------------------------------------------------------------------

// The Gift Cards add-on registers its own "gift-card" method. It is never offered at checkout
// (it can't be turned on), and it marks any order that carries it "new" (paid) with no money
// check. A crafted request names it on a real $50 order. The checkout should refuse it.
$pmc_test(
	'A crafted request naming the gift-card method is refused, not finished as paid',
	function () use ( $t, $pmc_place_and_finish ) {
		$t::require_plugin( 'sunshine_get_gift_card_by_code', 'Gift Cards add-on' );
		$t::customer();
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		// $50, nothing paid by a real gift card: the order owes $50.
		list( , $result, $fresh ) = $pmc_place_and_finish( 'gift-card' );
		$t::same( false, (bool) $result, 'checkout refuses the unauthorized gift-card method' );
		$t::same( false, $fresh->is_paid(), 'order is not marked paid when nothing was charged (status ' . $fresh->get_status() . ')' );
	}
);

// The built-in Test method set to "Admin Only" must not be usable by a customer. A non-admin
// customer crafts a request naming "test" on a real $50 order. It is not in their allowed list,
// yet the order still finishes as paid with nothing charged.
$pmc_test(
	'A customer cannot use the Admin Only Test method with a crafted request',
	function () use ( $t, $pmc_place_and_finish ) {
		$t::option( 'test_admin', 1 );
		// Newer versions call it "Allow Customers" and keep the Test method to admins when it's off.
		$t::option( 'test_allow_customers', '' );
		$t::customer();
		// Proof the method really is off-limits to this customer.
		$t::same( false, array_key_exists( 'test', (array) sunshine_get_allowed_payment_methods() ), 'Test is not an allowed method for a non-admin customer' );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		list( , $result, $fresh ) = $pmc_place_and_finish( 'test' );
		$t::same( false, (bool) $result, 'checkout refuses the admin-only method for a customer' );
		$t::same( false, $fresh->is_paid(), 'order is not marked paid when nothing was charged (status ' . $fresh->get_status() . ')' );
	}
);

// The root cause, one step earlier: the checkout saves whatever method the request names,
// without checking it is one the customer may use (process_section, class-cart.php:2337).
$pmc_test(
	'Choosing a payment method only keeps one the customer is allowed to use',
	function () use ( $t ) {
		$t::require_plugin( 'sunshine_get_gift_card_by_code', 'Gift Cards add-on' );
		$t::customer();
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::checkout_address();
		$t::calculate();
		// A crafted "choose payment method" submit naming the internal gift-card method.
		SPC()->cart->process_section( 'payment', array( 'payment_method' => 'gift-card' ) );
		$saved   = (string) SPC()->cart->get_checkout_data_item( 'payment_method' );
		$allowed = array_keys( (array) sunshine_get_allowed_payment_methods() );
		// The saved method should be one the customer may use, or left unset -- never the
		// gift-card method, which is not on the list.
		$t::same( true, '' === $saved || in_array( $saved, $allowed, true ), 'checkout did not keep an unallowed method (kept "' . $saved . '")' );
	}
);

// ---------------------------------------------------------------------------------------------
// The checks that do work, pinned so a change to them is noticed.
// ---------------------------------------------------------------------------------------------

// The Free method stays saved once the total has been zero, even after the total climbs back
// above zero (a known checkout bug). The order-finishing step must still refuse to finish it
// for free while money is owed.
$pmc_test(
	'Free stays refused once money is owed again, even if it was saved while the total was zero',
	function () use ( $t, $pmc_place_and_finish ) {
		$customer = $t::customer();
		update_user_meta( $customer, 'sunshine_credits', '5' );
		SPC()->customer = new SPC_Customer( $customer );
		$t::add( $t::product( 3, array( 'taxable' => false ) ) );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$cart = $t::calculate();
		// $3 fully paid by the $5 credit, so the checkout saves "free".
		$t::money( 0, $cart->get_total(), 'total is zero while credit covers the cart' );
		$t::same( 'free', (string) SPC()->cart->get_checkout_data_item( 'payment_method' ), 'checkout saved the Free method at a zero total' );
		// Add a $50 print: now $48 is owed, but "free" is still the saved method.
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$cart = $t::new_request();
		$t::money( 48, $cart->get_total(), 'total is 48 after adding the $50 print (53 - 5 credit)' );
		list( , $result, $fresh ) = $pmc_place_and_finish( 'free' );
		$t::same( false, (bool) $result, 'checkout refuses to finish a Free order while money is owed' );
		$t::same( false, $fresh->is_paid(), 'order is not marked paid for free while money is owed' );
	}
);

// The order is finished by its own saved payment method. If the checkout's saved method has
// drifted to a different (allowed) one since the order was made, finishing is refused.
$pmc_test(
	'An order is refused when the checkout method no longer matches the order',
	function () use ( $t ) {
		$t::option( 'offline_active', 1 );
		$t::customer();
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$order = $t::place_order( 'test' );
		// The customer switched the checkout to Offline after the order was built.
		SPC()->cart->set_checkout_data_item( 'payment_method', 'offline' );
		$result = SPC()->cart->process_order();
		$fresh  = sunshine_get_order( $order->get_id() );
		$t::same( false, (bool) $result, 'checkout refuses to finish when the method does not match the order' );
		$t::same( false, $fresh->is_paid(), 'the mismatched order is not finished as paid' );
	}
);

// The match check only applies when the checkout has a method saved. With none saved, this
// gate lets the order through and the later "No payment method" check refuses it instead.
$pmc_test(
	'With no checkout method saved, the method check steps aside for the No payment method check',
	function () use ( $t ) {
		$t::customer();
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::place_order( 'test' );
		SPC()->cart->set_checkout_data_item( 'payment_method', '' );
		$result = SPC()->cart->process_order();
		$errors = (array) SPC()->cart->get_errors();
		$t::same( false, (bool) $result, 'an order with money owed and no method is not finished' );
		$t::same( false, in_array( 'Please select a payment method before completing your order.', $errors, true ), 'the method check did not refuse it' );
		$t::same( true, in_array( 'No payment method', $errors, true ), 'the No payment method check refused it' );
	}
);

// ---------------------------------------------------------------------------------------------
// Methods a customer may legitimately use, so the refusals above are not just "refuse everything".
// ---------------------------------------------------------------------------------------------

// The Test method, left on for everyone, is a no-charge method: it finishes the order as paid.
$pmc_test(
	'The Test method, allowed for everyone, finishes the order',
	function () use ( $t, $pmc_place_and_finish ) {
		$t::customer();
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		list( , $result, $fresh ) = $pmc_place_and_finish( 'test' );
		$t::same( true, (bool) $result, 'the allowed Test method finishes the order' );
		$t::same( true, $fresh->is_paid(), 'the order is marked paid' );
		$t::same( 'test', $fresh->get_payment_method(), 'the order keeps the Test method' );
		$t::money( 50, $fresh->get_total(), 'order total is the $50 that was owed' );
	}
);

// Offline is allowed, but payment happens later (check or cash), so the order is accepted and
// left waiting as Pending, not marked paid.
$pmc_test(
	'The Offline method is accepted but leaves the order waiting to be paid',
	function () use ( $t, $pmc_place_and_finish ) {
		$t::option( 'offline_active', 1 );
		$t::customer();
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		list( , $result, $fresh ) = $pmc_place_and_finish( 'offline' );
		$t::same( true, (bool) $result, 'the allowed Offline method is accepted' );
		$t::same( false, $fresh->is_paid(), 'the order waits for payment, not marked paid (status ' . $fresh->get_status() . ')' );
		$t::same( 'pending', $fresh->get_status(), 'the Offline order is left Pending' );
	}
);

// A genuinely free order (the whole total is covered, so nothing is owed) is allowed to finish
// through the Free method. This is the one time Free is the right answer.
$pmc_test(
	'A fully covered order finishes for free',
	function () use ( $t, $pmc_place_and_finish ) {
		$customer = $t::customer();
		update_user_meta( $customer, 'sunshine_credits', '50' );
		SPC()->customer = new SPC_Customer( $customer );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$cart = $t::calculate();
		// $50 fully paid by the $50 credit: nothing is owed.
		$t::money( 0, $cart->get_total(), 'nothing is owed after the credit covers the order' );
		list( , $result, $fresh ) = $pmc_place_and_finish( 'free' );
		$t::same( true, (bool) $result, 'the free order finishes' );
		$t::same( true, $fresh->is_paid(), 'the free order is marked paid' );
		$t::money( 0, $fresh->get_total(), 'the order total is zero' );
	}
);

// Cleanup for the queued-email posts any finished order left behind.
$t::test(
	'payment-method-choice cleanup',
	function () use ( &$pmc_emails ) {
		foreach ( array_unique( $pmc_emails ) as $email_id ) {
			wp_delete_post( $email_id, true );
		}
		$pmc_emails = array();
		SPC_Totals_Test::same( true, true, 'cleanup ran' );
	}
);
