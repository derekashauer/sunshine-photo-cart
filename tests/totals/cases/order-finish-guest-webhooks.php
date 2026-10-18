<?php
/**
 * Each payment gateway's own message that confirms a payment, sent to the site the way the
 * gateway sends it, with nobody logged in. The customer placed the order while logged in, with
 * store credit, a once-per-customer discount code and (where it applies) a gift card, then paid
 * on the gateway's page and never came back to the site.
 *
 * After the message, the order must be paid and finished for that customer: the credit and the
 * gift card balance are used up, and the code counts as used by them. Messages for a payment that
 * did not go through, or that paid less than the order, must leave the order unpaid.
 *
 * Nothing reaches a gateway. Requests the handlers make (to check a payment with the gateway)
 * are caught and answered here with a made-up reply. Signatures are made with test secrets set
 * for the test only. Helpers are in order-finish-guest-helpers.php.
 */

$t = 'SPC_Totals_Test';

/*
 * Stripe hosted checkout. The customer is sent to Stripe's own page and the order is only ever
 * finished by Stripe's checkout.session.completed webhook.
 */

// What creating the Stripe Checkout Session saves on the order: the session ID, what the session
// charges in cents before tax (Sunshine works out the tax, so Stripe adds none) and the order
// total at that moment. Returns the session ID.
$finish_guest_stripe_session = function ( $order ) {
	$session_id = 'cs_spcguest' . $order->get_id();
	$order->update_meta_value( 'stripe_checkout_session_id', $session_id );
	$order->update_meta_value( 'stripe_checkout_amount', (int) round( $order->get_total() * 100 ) );
	$order->update_meta_value( 'stripe_checkout_order_total', $order->get_total() );
	return $session_id;
};

$finish_guest_stripe_session_paid = function ( $order, $session_id ) use ( $finish_guest_stripe_event ) {
	$cents = (int) round( $order->get_total() * 100 );
	return $finish_guest_stripe_event(
		$order->get_id(),
		array(
			'id'   => 'evt_spcguest' . $order->get_id(),
			'type' => 'checkout.session.completed',
			'data' => array(
				'object' => array(
					'id'             => $session_id,
					'object'         => 'checkout.session',
					'payment_status' => 'paid',
					'status'         => 'complete',
					'currency'       => 'usd',
					'amount_total'   => $cents,
					'total_details'  => array(
						'amount_tax'      => 0,
						'amount_shipping' => 0,
					),
					'payment_intent' => 'pi_spcguesthosted' . $order->get_id(),
					'customer'       => 'cus_spcguest',
					'metadata'       => array( 'order_id' => (string) $order->get_id() ),
				),
			),
		)
	);
};

$t::test(
	'Stripe hosted: the webhook finishes the order (prices include tax, code, gift card, store credit)',
	function () use ( $t, $finish_guest_stripe_use, $finish_guest_shopper, $finish_guest_code, $finish_guest_enter, $finish_guest_seen, $finish_guest_place, $finish_guest_stripe_session, $finish_guest_stripe_session_paid, $finish_guest_finished ) {
		$finish_guest_stripe_use( 'hosted' );
		$customer = $finish_guest_shopper( 30 );
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$product           = $t::product( 110 );
		list( $id, $code ) = $finish_guest_code();
		$card              = $t::gift_card( 25 );
		$t::add( $product );
		$t::calculate();
		$t::same( true, $finish_guest_enter( $code ), 'code accepted at checkout' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 110 with 10 tax inside. 10% off is 11, leaving 99 with 9 tax inside.
		// The gift card pays 25 and store credit 30, so 44 is left to pay on Stripe.
		$t::money( 11, $cart->get_discount(), 'discount' );
		$t::money( 9, $cart->get_tax(), 'tax inside the price' );
		$t::money( 44, $cart->get_total(), 'left to pay' );
		$seen = $finish_guest_seen();
		$t::money( 25, $seen['gift'], 'taken from the gift card' );
		$t::money( 30, $seen['credits'], 'store credit used' );
		$order      = $finish_guest_place( 'stripe', $seen );
		$session_id = $finish_guest_stripe_session( $order );

		$run = $finish_guest_stripe_session_paid( $order, $session_id );
		$t::same( 200, $run['status'], 'webhook accepted' );
		$finish_guest_finished(
			$order->get_id(),
			$seen,
			array(
				'customer'     => $customer,
				'credits_left' => 0,
				'card'         => $card,
				'card_left'    => 0,
				'code'         => array( $id, $code, $product ),
			)
		);
	}
);

// The Gift Cards add-on makes the card the customer bought once the order is finished. A card
// bought through Stripe hosted checkout must be made too.
$t::test(
	'Stripe hosted: a gift card bought in the order is made when the webhook finishes it',
	function () use ( $t, $finish_guest_stripe_use, $finish_guest_shopper, $finish_guest_gift_card_product, $finish_guest_add_gift_card, $finish_guest_seen, $finish_guest_place, $finish_guest_stripe_session, $finish_guest_stripe_session_paid, $finish_guest_cards_bought ) {
		$finish_guest_stripe_use( 'hosted' );
		$finish_guest_shopper( 0 );
		$finish_guest_add_gift_card( $finish_guest_gift_card_product(), 50 );
		$t::checkout_address();
		$cart = $t::calculate();
		$t::money( 50, $cart->get_total(), 'a 50 gift card costs 50' );
		$order = $finish_guest_place( 'stripe', $finish_guest_seen() );
		$run   = $finish_guest_stripe_session_paid( $order, $finish_guest_stripe_session( $order ) );
		$t::same( 200, $run['status'], 'webhook accepted' );
		$t::same( true, sunshine_get_order( $order->get_id() )->is_paid(), 'order is paid' );
		$cards = $finish_guest_cards_bought( $order->get_id() );
		$t::same( 1, count( $cards ), 'one gift card made' );
		$t::money( 50, array_sum( $cards ), 'balance on the new gift card' );
	}
);

/*
 * Stripe payment form on the checkout page. Checkout finishes the order when the customer's
 * browser comes back after paying. The payment_intent.succeeded webhook is the backup for when
 * it never does (closed the tab during a bank check, lost connection).
 */

// What the payment form saves on the order: the PaymentIntent it charged. Returns its ID.
$finish_guest_stripe_intent = function ( $order ) {
	$intent_id = 'pi_spcguest' . $order->get_id();
	$order->update_meta_value( 'stripe_payment_intent_id', $intent_id );
	return $intent_id;
};

$finish_guest_stripe_intent_paid = function ( $order, $intent_id ) use ( $finish_guest_stripe_event ) {
	$cents = (int) round( $order->get_total() * 100 );
	return $finish_guest_stripe_event(
		$order->get_id(),
		array(
			'id'   => 'evt_spcguestpi' . $order->get_id(),
			'type' => 'payment_intent.succeeded',
			'data' => array(
				'object' => array(
					'id'              => $intent_id,
					'object'          => 'payment_intent',
					'status'          => 'succeeded',
					'amount'          => $cents,
					'amount_received' => $cents,
					'currency'        => 'usd',
					'customer'        => 'cus_spcguest',
					'metadata'        => array( 'order_id' => (string) $order->get_id() ),
				),
			),
		)
	);
};

$t::test(
	'Stripe payment form: the backup webhook finishes the order (discount after tax, odd cents, gift card, store credit)',
	function () use ( $t, $finish_guest_stripe_use, $finish_guest_shopper, $finish_guest_code, $finish_guest_enter, $finish_guest_seen, $finish_guest_place, $finish_guest_stripe_intent, $finish_guest_stripe_intent_paid, $finish_guest_finished ) {
		$finish_guest_stripe_use( 'inline' );
		$customer = $finish_guest_shopper( 20 );
		$t::tax( 7.25 );
		$t::option( 'discount_after_tax', 1 );
		$product           = $t::product( 19.99 );
		list( $id, $code ) = $finish_guest_code();
		$card              = $t::gift_card( 10 );
		$t::add( $product, 3 );
		$t::flat_shipping( 8.95, true );
		$t::calculate();
		$t::same( true, $finish_guest_enter( $code ), 'code accepted at checkout' );
		$t::checkout_address();
		$cart = $t::calculate();
		// Prints 59.97, tax 4.3478 -> 4.35. Shipping 8.95, tax 0.6489 -> 0.65.
		// 10% off after tax, on the prints and their tax: 64.32 x 10% = 6.432 -> 6.43.
		// 59.97 + 4.35 + 8.95 + 0.65 - 6.43 = 67.49. Gift card 10, store credit 20: 37.49 to pay.
		$t::money( 4.35 + 0.65, $cart->get_tax(), 'tax' );
		$t::money( 6.43, $cart->get_discount(), 'discount' );
		$t::money( 37.49, $cart->get_total(), 'left to pay' );
		$seen  = $finish_guest_seen();
		$order = $finish_guest_place( 'stripe', $seen );
		$run   = $finish_guest_stripe_intent_paid( $order, $finish_guest_stripe_intent( $order ) );
		$t::same( 200, $run['status'], 'webhook accepted' );
		$finish_guest_finished(
			$order->get_id(),
			$seen,
			array(
				'customer'     => $customer,
				'credits_left' => 0,
				'card'         => $card,
				'card_left'    => 0,
				'code'         => array( $id, $code, $product ),
			)
		);
	}
);

// Prints included with a session the customer paid for are free on their next order, and are
// used up once that order is paid, however it was paid.
$t::test(
	'Stripe payment form: included session prints are used up when the backup webhook finishes the order',
	function () use ( $t, $finish_guest_stripe_use, $finish_guest_shopper, $finish_guest_give_included, $finish_guest_included_left, $finish_guest_seen, $finish_guest_place, $finish_guest_stripe_intent, $finish_guest_stripe_intent_paid ) {
		$finish_guest_stripe_use( 'inline' );
		$customer = $finish_guest_shopper( 0 );
		$print    = $t::product( 12, array( 'taxable' => false ) );
		$finish_guest_give_included( $customer, $print, 2, 0 );
		$t::add( $print, 3 );
		$t::checkout_address();
		$cart = $t::calculate();
		// Two of the three prints are free, the third is 12.
		$t::money( 12, $cart->get_total(), 'left to pay' );
		$order = $finish_guest_place( 'stripe', $finish_guest_seen() );
		$run   = $finish_guest_stripe_intent_paid( $order, $finish_guest_stripe_intent( $order ) );
		$t::same( 200, $run['status'], 'webhook accepted' );
		$t::same( 'new', sunshine_get_order( $order->get_id() )->get_status(), 'order is marked paid (New)' );
		$t::same( 0, (int) $finish_guest_included_left( $customer, $print ), 'free prints left for the customer' );
	}
);

$t::test(
	'Stripe payment form: the webhook after checkout finished the order changes nothing',
	function () use ( $t, $finish_guest_stripe_use, $finish_guest_shopper, $finish_guest_code, $finish_guest_enter, $finish_guest_seen, $finish_guest_place, $finish_guest_run, $finish_guest_stripe_intent, $finish_guest_stripe_intent_paid, $finish_guest_uses, $finish_guest_credits_left ) {
		$finish_guest_stripe_use( 'inline' );
		$customer          = $finish_guest_shopper( 20 );
		list( $id, $code ) = $finish_guest_code();
		$t::add( $t::product( 60, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $finish_guest_enter( $code ), 'code accepted at checkout' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 60 - 6 - 20 credit = 34.
		$t::money( 34, $cart->get_total(), 'left to pay' );
		$order     = $finish_guest_place( 'stripe', $finish_guest_seen() );
		$intent_id = $finish_guest_stripe_intent( $order );
		// The customer's browser came back and checkout finished the order.
		$finish_guest_run(
			'checkout finishing step',
			$order->get_id(),
			function () use ( $order ) {
				SPC()->cart->post_process_order( sunshine_get_order( $order->get_id() ) );
			},
			array( 'guest' => false )
		);
		$t::money( 0, $finish_guest_credits_left( $customer ), 'store credit used up by checkout' );
		// The photographer adds 15 of credit before Stripe's webhook arrives.
		( new SPC_Customer( $customer ) )->set_credits( '15' );
		$run = $finish_guest_stripe_intent_paid( $order, $intent_id );
		$t::same( 200, $run['status'], 'webhook answered' );
		$t::same( 1, $finish_guest_uses( $id ), 'code counted once' );
		$t::money( 15, $finish_guest_credits_left( $customer ), 'the new credit is not touched' );
		$t::money( 34, sunshine_get_order( $order->get_id() )->get_total(), 'order total' );
	}
);

/*
 * Mollie. The customer pays on Mollie's page. Mollie then calls the webhook with only the payment
 * ID, and the site asks Mollie for that payment to see whether it was paid.
 */

$t::test(
	'Mollie: the webhook finishes the order (taxed shipping, code, gift card, store credit)',
	function () use ( $t, $finish_guest_switch_on, $finish_guest_shopper, $finish_guest_code, $finish_guest_enter, $finish_guest_seen, $finish_guest_place, $finish_guest_mollie, $finish_guest_run, $finish_guest_finished ) {
		$t::option( 'currency', 'EUR' );
		$finish_guest_switch_on( 'mollie' );
		$customer = $finish_guest_shopper( 30 );
		$t::tax( 10 );
		$product           = $t::product( 100 );
		list( $id, $code ) = $finish_guest_code();
		$card              = $t::gift_card( 25 );
		$t::add( $product );
		$t::flat_shipping( 10, true );
		$t::calculate();
		$t::same( true, $finish_guest_enter( $code ), 'code accepted at checkout' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 100 - 10 discount = 90, + 9 tax, + 10 shipping, + 1 shipping tax = 110.
		// Gift card 25 and store credit 30 leave 55 to pay on Mollie.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 10, $cart->get_tax(), 'tax on 90 and on the shipping' );
		$t::money( 55, $cart->get_total(), 'left to pay' );
		$seen       = $finish_guest_seen();
		$order      = $finish_guest_place( 'mollie', $seen );
		$payment_id = 'tr_spcguest' . $order->get_id();

		list( $mollie, $connection ) = $finish_guest_mollie(
			function ( $method, $url, $body ) use ( $order, $payment_id ) {
				$payment = array(
					'resource'    => 'payment',
					'id'          => $payment_id,
					'mode'        => 'test',
					'amount'      => array(
						'value'    => number_format( $order->get_total(), 2, '.', '' ),
						'currency' => 'EUR',
					),
					'description' => $order->get_name(),
					'metadata'    => array( 'order_id' => $order->get_id() ),
					'_links'      => array(
						'self' => array(
							'href' => 'https://api.mollie.com/v2/payments/' . $payment_id,
							'type' => 'application/hal+json',
						),
					),
				);
				if ( 'POST' === $method && preg_match( '#/v2/payments$#', $url ) ) {
					$payment['status']             = 'open';
					$payment['_links']['checkout'] = array(
						'href' => 'https://www.mollie.com/checkout/spcguest',
						'type' => 'text/html',
					);
					return $payment;
				}
				if ( 'GET' === $method && false !== strpos( $url, '/v2/payments/' . $payment_id ) ) {
					$payment['status'] = 'paid';
					$payment['paidAt'] = gmdate( 'Y-m-d\TH:i:s+00:00' );
					return $payment;
				}
				throw new \Mollie\Api\Exceptions\ApiException( 'Blocked by the totals tests' );
			}
		);

		// The customer is sent to Mollie to pay.
		$start = $finish_guest_run(
			'Mollie payment start',
			$order->get_id(),
			function () use ( $mollie, $order ) {
				$mollie->process( sunshine_get_order( $order->get_id() ) );
			},
			array( 'guest' => false )
		);
		$t::same( 'https://www.mollie.com/checkout/spcguest', $start['redirect'], 'customer is sent to Mollie' );
		$t::same( '55.00', isset( $connection->sent[0]['body']['amount']['value'] ) ? $connection->sent[0]['body']['amount']['value'] : null, 'Mollie is asked for what is left to pay' );

		// Mollie calls the webhook with the payment ID.
		$finish_guest_run(
			'Mollie webhook',
			$order->get_id(),
			function () use ( $mollie ) {
				$mollie->webhook();
			},
			array(
				'get'  => array( 'sunshine_mollie_webhook' => 1 ),
				'post' => array( 'id' => $payment_id ),
			)
		);
		$finish_guest_finished(
			$order->get_id(),
			$seen,
			array(
				'customer'     => $customer,
				'credits_left' => 0,
				'card'         => $card,
				'card_left'    => 0,
				'code'         => array( $id, $code, $product ),
			)
		);
	}
);

// Checkout keeps using the same unpaid order when the customer goes back and changes the cart.
// So a Mollie payment started for the old, smaller cart can still be paid in the first tab after
// the order has grown. Stripe, PayPal and Square all check that the payment covers the order
// total as it is now. Assumed rule: Mollie must too, so 50 paid on a 150 order leaves it unpaid.
$t::test(
	'Mollie: a payment started before the cart grew does not mark the bigger order paid',
	function () use ( $t, $finish_guest_switch_on, $finish_guest_shopper, $finish_guest_seen, $finish_guest_place, $finish_guest_mollie, $finish_guest_run ) {
		$t::option( 'currency', 'EUR' );
		$finish_guest_switch_on( 'mollie' );
		$finish_guest_shopper( 0 );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::checkout_address();
		$t::calculate();
		$order = $finish_guest_place( 'mollie', $finish_guest_seen() );

		// Mollie makes a payment for whatever amount it is asked, and reports it back the same.
		$payments       = array();
		list( $mollie ) = $finish_guest_mollie(
			function ( $method, $url, $body ) use ( &$payments, $order ) {
				if ( 'POST' === $method && preg_match( '#/v2/payments$#', $url ) ) {
					$request                 = json_decode( $body, true );
					$payment_id              = 'tr_spcguest' . $order->get_id() . 'n' . count( $payments );
					$payments[ $payment_id ] = $request['amount'];
					return array(
						'resource' => 'payment',
						'id'       => $payment_id,
						'mode'     => 'test',
						'status'   => 'open',
						'amount'   => $request['amount'],
						'metadata' => $request['metadata'],
						'_links'   => array(
							'checkout' => array(
								'href' => 'https://www.mollie.com/checkout/' . $payment_id,
								'type' => 'text/html',
							),
						),
					);
				}
				foreach ( $payments as $payment_id => $amount ) {
					if ( 'GET' === $method && false !== strpos( $url, '/v2/payments/' . $payment_id ) ) {
						return array(
							'resource' => 'payment',
							'id'       => $payment_id,
							'mode'     => 'test',
							'status'   => 'paid',
							'paidAt'   => gmdate( 'Y-m-d\TH:i:s+00:00' ),
							'amount'   => $amount,
							'metadata' => array( 'order_id' => $order->get_id() ),
							'_links'   => array(
								'self' => array(
									'href' => 'https://api.mollie.com/v2/payments/' . $payment_id,
									'type' => 'application/hal+json',
								),
							),
						);
					}
				}
				throw new \Mollie\Api\Exceptions\ApiException( 'Blocked by the totals tests' );
			}
		);
		$start          = function () use ( $finish_guest_run, $mollie, $order ) {
			return $finish_guest_run(
				'Mollie payment start',
				$order->get_id(),
				function () use ( $mollie, $order ) {
					$mollie->process( sunshine_get_order( $order->get_id() ) );
				},
				array( 'guest' => false )
			);
		};
		$start();
		$first_payment = (string) key( $payments );
		$t::same( '50.00', $payments[ $first_payment ]['value'], 'first Mollie payment is for 50' );

		// The customer comes back without paying, adds a 100 print and places the order again.
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$cart = $t::calculate();
		$t::money( 150, $cart->get_total(), 'cart is now 150' );
		$again = $finish_guest_place( 'mollie', $finish_guest_seen() );
		$t::same( $order->get_id(), $again->get_id(), 'checkout reuses the same unpaid order' );
		$t::money( 150, $again->get_total(), 'order total is now 150' );

		// The customer then pays the first Mollie payment, still open in another tab.
		$finish_guest_run(
			'Mollie webhook',
			$order->get_id(),
			function () use ( $mollie ) {
				$mollie->webhook();
			},
			array(
				'get'  => array( 'sunshine_mollie_webhook' => 1 ),
				'post' => array( 'id' => $first_payment ),
			)
		);
		$paid = sunshine_get_order( $order->get_id() );
		$t::same( 'pending', $paid->get_status(), 'order stays unpaid (Pending): only 50 of 150 was paid' );
	}
);

/*
 * PayPal (Legacy), also called PayPal Standard. Checkout sends the customer to PayPal with a form
 * of the order lines. PayPal then posts an IPN to the site, which the site sends back to PayPal to
 * check (PayPal answers VERIFIED) and compares with what PayPal was asked to charge.
 */

$finish_guest_paypal_legacy_use = function () use ( $t, $finish_guest_switch_on ) {
	$t::option( 'currency', 'USD' );
	$finish_guest_switch_on( 'paypal-legacy' );
	$t::option( 'paypal-legacy_email_test', 'photos@example.com' );
};

// The customer presses Place Order: checkout saves what PayPal will be asked to charge and sends
// the customer to PayPal. Returns that amount.
$finish_guest_paypal_legacy_start = function ( $order ) use ( $t, $finish_guest_run ) {
	$run = $finish_guest_run(
		'PayPal Legacy redirect',
		$order->get_id(),
		function () {
			SPC()->cart->process_order();
		},
		array(
			'guest' => false,
			'stop'  => array( 'paypal_legacy_order_total' ),
		)
	);
	$t::same( 'paypal_legacy_order_total', $run['stopped'], 'customer is sent to PayPal' );
	return (float) get_post_meta( $order->get_id(), 'paypal_legacy_expected_total', true );
};

$finish_guest_paypal_ipn = function ( $order, $amount ) use ( $finish_guest_run ) {
	$fields = array(
		'mc_gross'       => number_format( $amount, 2, '.', '' ),
		'payer_id'       => 'SPCGUESTPAYER',
		'payment_date'   => '10:00:00 Oct 17, 2026 PDT',
		'payment_status' => 'Completed',
		'charset'        => 'UTF-8',
		'mc_fee'         => '2.04',
		'txn_type'       => 'cart',
		'receiver_email' => 'photos@example.com',
		'receiver_id'    => 'SPCSHOPID',
		'payer_email'    => 'totals-test@example.com',
		'mc_currency'    => 'USD',
		'custom'         => (string) $order->get_id(),
		'txn_id'         => 'SPCGUEST' . $order->get_id(),
		'payment_type'   => 'instant',
	);
	return $finish_guest_run(
		'PayPal IPN',
		$order->get_id(),
		function () {
			sunshine_get_payment_method_by_id( 'paypal-legacy' )->ipn();
		},
		array(
			'get'   => array( 'sunshine_paypal_ipn' => 'paypal_standard_ipn' ),
			'post'  => $fields,
			'input' => http_build_query( $fields, '', '&' ),
			// PayPal answers VERIFIED when the site sends the IPN back to check it.
			'reply' => function ( $request ) {
				if ( 'www.sandbox.paypal.com' === $request['host'] && '/cgi-bin/webscr' === $request['path'] ) {
					return array( 200, 'VERIFIED' );
				}
				return null;
			},
			'stop'  => array( 'post_processed' ),
			'logs'  => array( 'has status', 'does not match' ),
		)
	);
};

$t::test(
	'PayPal Legacy: the IPN finishes the order (code, store credit)',
	function () use ( $t, $finish_guest_paypal_legacy_use, $finish_guest_shopper, $finish_guest_code, $finish_guest_enter, $finish_guest_seen, $finish_guest_place, $finish_guest_paypal_legacy_start, $finish_guest_paypal_ipn, $finish_guest_finished ) {
		$finish_guest_paypal_legacy_use();
		$customer          = $finish_guest_shopper( 30 );
		$product           = $t::product( 100, array( 'taxable' => false ) );
		list( $id, $code ) = $finish_guest_code();
		$t::add( $product );
		$t::calculate();
		$t::same( true, $finish_guest_enter( $code ), 'code accepted at checkout' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 100 - 10 discount - 30 store credit = 60.
		$t::money( 60, $cart->get_total(), 'left to pay' );
		$seen  = $finish_guest_seen();
		$order = $finish_guest_place( 'paypal-legacy', $seen );
		$t::money( 60, $finish_guest_paypal_legacy_start( $order ), 'PayPal is asked for the order total' );

		$run = $finish_guest_paypal_ipn( $order, 60 );
		$t::same( 'post_processed', $run['stopped'], 'IPN accepted' );
		$check = isset( $run['sent'][0]['body'] ) ? (string) $run['sent'][0]['body'] : '';
		$t::same( 0, strpos( $check, 'cmd=_notify-validate&' ), 'the IPN is sent back to PayPal to check it' );
		$finish_guest_finished(
			$order->get_id(),
			$seen,
			array(
				'customer'     => $customer,
				'credits_left' => 0,
				'code'         => array( $id, $code, $product ),
			)
		);
	}
);

// The IPN only accepts a payment of what PayPal was asked to charge, so that amount has to be
// what is left after the gift card. Store credit and discounts are taken off; the gift card is too.
$t::test(
	'PayPal Legacy: PayPal is asked for what is left after a gift card',
	function () use ( $t, $finish_guest_paypal_legacy_use, $finish_guest_shopper, $finish_guest_seen, $finish_guest_place, $finish_guest_paypal_legacy_start ) {
		$finish_guest_paypal_legacy_use();
		$finish_guest_shopper( 0 );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::gift_card( 25 );
		$t::checkout_address();
		$cart = $t::calculate();
		// 100 - 25 gift card = 75.
		$t::money( 75, $cart->get_total(), 'left to pay' );
		$order = $finish_guest_place( 'paypal-legacy', $finish_guest_seen() );
		$t::money( 75, $finish_guest_paypal_legacy_start( $order ), 'PayPal is asked for the order total' );
	}
);

// Every payment method can add a fee (Payment Methods settings, "Fees"). The customer agreed to
// pay it at checkout, so PayPal must be asked for it too.
$t::test(
	'PayPal Legacy: PayPal is asked for the payment method fee too',
	function () use ( $t, $finish_guest_paypal_legacy_use, $finish_guest_shopper, $finish_guest_seen, $finish_guest_place, $finish_guest_paypal_legacy_start ) {
		$finish_guest_paypal_legacy_use();
		$t::option( 'paypal-legacy_fee', 'amount' );
		$t::option( 'paypal-legacy_fee_amount', '2.50' );
		$t::option( 'paypal-legacy_fee_name', 'PayPal fee' );
		$finish_guest_shopper( 0 );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::checkout_address();
		// The fee is added once the customer picks the payment method.
		SPC()->cart->set_checkout_data_item( 'payment_method', 'paypal-legacy' );
		$cart = $t::calculate();
		// 100 + 2.50 fee = 102.50.
		$t::money( 102.50, $cart->get_total(), 'left to pay' );
		$order = $finish_guest_place( 'paypal-legacy', $finish_guest_seen() );
		$t::money( 102.50, $finish_guest_paypal_legacy_start( $order ), 'PayPal is asked for the order total' );
	}
);

/*
 * PayFast. Checkout posts a signed form to PayFast. PayFast then posts an ITN to the site, signed
 * with the store's passphrase.
 */

$finish_guest_payfast_use = function () use ( $t, $finish_guest_switch_on ) {
	$t::option( 'currency', 'ZAR' );
	$finish_guest_switch_on( 'payfast' );
	$t::option( 'payfast_merchant_id_test', '10000100' );
	$t::option( 'payfast_merchant_key_test', '46f0cd694581a' );
	$t::option( 'payfast_passphrase_test', 'sunshine totals' );
};

// Checkout builds the PayFast form and sends the customer to PayFast. Returns the form fields.
$finish_guest_payfast_start = function ( $order ) use ( $t, $finish_guest_run ) {
	$run = $finish_guest_run(
		'PayFast redirect',
		$order->get_id(),
		function () {
			SPC()->cart->process_order();
		},
		array(
			'guest'   => false,
			'filters' => array( 'sunshine_payfast_args' ),
		)
	);
	$t::same( 'sunshine_payfast_args', $run['stopped'], 'customer is sent to PayFast' );
	return (array) $run['caught'];
};

// PayFast's ITN for a payment of $gross: the fields in the order PayFast sends them, signed the way
// PayFast signs (md5 of key=urlencoded value pairs joined with &, then the passphrase).
$finish_guest_payfast_itn = function ( $order, $gross ) use ( $finish_guest_run ) {
	$fee    = round( $gross * 0.035, 2 );
	$fields = array(
		'm_payment_id'   => (string) $order->get_id(),
		'pf_payment_id'  => '2' . $order->get_id(),
		'payment_status' => 'COMPLETE',
		'item_name'      => $order->get_name(),
		'amount_gross'   => number_format( $gross, 2, '.', '' ),
		'amount_fee'     => number_format( -$fee, 2, '.', '' ),
		'amount_net'     => number_format( $gross - $fee, 2, '.', '' ),
		'name_first'     => 'Totals',
		'name_last'      => 'Test',
		'email_address'  => 'totals-test@example.com',
		'merchant_id'    => '10000100',
	);
	$pairs  = array();
	foreach ( $fields as $key => $value ) {
		$pairs[] = $key . '=' . urlencode( trim( $value ) );
	}
	$fields['signature'] = md5( implode( '&', $pairs ) . '&passphrase=' . urlencode( 'sunshine totals' ) );
	return $finish_guest_run(
		'PayFast ITN',
		$order->get_id(),
		function () {
			sunshine_get_payment_method_by_id( 'payfast' )->notify();
		},
		array(
			'get'   => array( 'sunshine_payfast_notify' => 1 ),
			'post'  => $fields,
			// PayFast's answer when the site sends the ITN back to check PayFast sent it.
			'reply' => function ( $request ) {
				return 'sandbox.payfast.co.za' === $request['host'] && '/eng/query/validate' === $request['path'] ? array( 200, 'VALID' ) : null;
			},
			// Every way out of the ITN handler writes a "Payfast notify - ..." line first.
			'logs' => array( 'Payfast notify - ' ),
		)
	);
};

$t::test(
	'PayFast: the ITN finishes the order (prices include VAT, code, gift card, store credit)',
	function () use ( $t, $finish_guest_payfast_use, $finish_guest_shopper, $finish_guest_code, $finish_guest_enter, $finish_guest_seen, $finish_guest_place, $finish_guest_payfast_start, $finish_guest_payfast_itn, $finish_guest_finished ) {
		$finish_guest_payfast_use();
		$customer = $finish_guest_shopper( 30 );
		$t::tax( 15 );
		$t::option( 'price_has_tax', 'yes' );
		$product           = $t::product( 115 );
		list( $id, $code ) = $finish_guest_code();
		$card              = $t::gift_card( 20 );
		$t::add( $product );
		$t::calculate();
		$t::same( true, $finish_guest_enter( $code ), 'code accepted at checkout' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 115 with 15 VAT inside. 10% off is 11.50, leaving 103.50 with 13.50 VAT inside.
		// Gift card 20 and store credit 30 leave 53.50 to pay on PayFast.
		$t::money( 11.50, $cart->get_discount(), 'discount' );
		$t::money( 13.50, $cart->get_tax(), 'VAT inside the price' );
		$t::money( 53.50, $cart->get_total(), 'left to pay' );
		$seen  = $finish_guest_seen();
		$order = $finish_guest_place( 'payfast', $seen );
		$form  = $finish_guest_payfast_start( $order );
		$t::money( 53.50, isset( $form['amount'] ) ? $form['amount'] : null, 'PayFast is asked for what is left to pay' );

		$run = $finish_guest_payfast_itn( $order, 53.50 );
		$t::same( 'Payfast notify - order processed', $run['stopped'], 'ITN accepted' );
		$finish_guest_finished(
			$order->get_id(),
			$seen,
			array(
				'customer'     => $customer,
				'credits_left' => 0,
				'card'         => $card,
				'card_left'    => 0,
				'code'         => array( $id, $code, $product ),
			)
		);
	}
);

// PayFast's own guide lists checking that the amount paid matches the order as one of the checks
// a site must make before accepting an ITN. A short payment must leave the order unpaid.
$t::test(
	'PayFast: an ITN for less than the order total does not mark it paid',
	function () use ( $t, $finish_guest_payfast_use, $finish_guest_shopper, $finish_guest_seen, $finish_guest_place, $finish_guest_payfast_start, $finish_guest_payfast_itn, $finish_guest_credits_left ) {
		$finish_guest_payfast_use();
		$customer = $finish_guest_shopper( 30 );
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$t::checkout_address();
		$cart = $t::calculate();
		// 230 - 30 store credit = 200.
		$t::money( 200, $cart->get_total(), 'left to pay' );
		$order = $finish_guest_place( 'payfast', $finish_guest_seen() );
		$finish_guest_payfast_start( $order );

		$finish_guest_payfast_itn( $order, 100 );
		$order = sunshine_get_order( $order->get_id() );
		$t::same( 'pending', $order->get_status(), 'order stays unpaid (Pending)' );
		$t::same( '', (string) get_post_meta( $order->get_id(), 'post_processed', true ), 'order is not finished' );
		$t::money( 30, $finish_guest_credits_left( $customer ), 'store credit stays on the account' );
	}
);

/*
 * Systempay. Checkout posts a signed form to Systempay. At the end of the payment, accepted or
 * refused, Systempay posts an IPN to the site with the result in vads_trans_status, signed with
 * the shop key.
 */

$finish_guest_systempay_use = function () use ( $t, $finish_guest_switch_on ) {
	$t::option( 'currency', 'EUR' );
	$finish_guest_switch_on( 'systempay' );
	$t::option( 'systempay_site_id', '12345678' );
	$t::option( 'systempay_test_key', 'spcguestsystempaytestkey' );
};

// Checkout makes the Systempay form, which saves a new transaction ID on the order, and sends the
// customer to Systempay. Returns the transaction ID.
$finish_guest_systempay_start = function ( $order ) use ( $t, $finish_guest_run ) {
	$run = $finish_guest_run(
		'Systempay redirect',
		$order->get_id(),
		function () {
			SPC()->cart->process_order();
		},
		array(
			'guest' => false,
			'stop'  => array( 'vads_trans_id' ),
		)
	);
	$t::same( 'vads_trans_id', $run['stopped'], 'customer is sent to Systempay' );
	return (string) get_post_meta( $order->get_id(), 'vads_trans_id', true );
};

// Systempay's IPN: the vads_ fields of the payment, signed the way Systempay signs them (values in
// key order joined with +, then + the key, HMAC-SHA-256 with the key, base64).
$finish_guest_systempay_ipn = function ( $order, $trans_id, $status, $cents ) use ( $finish_guest_run ) {
	$key    = 'spcguestsystempaytestkey';
	$fields = array(
		'vads_amount'         => (string) $cents,
		'vads_auth_result'    => ( 'AUTHORISED' === $status ) ? '00' : '05',
		'vads_ctx_mode'       => 'TEST',
		'vads_currency'       => '978',
		'vads_order_id'       => (string) $order->get_id(),
		'vads_page_action'    => 'PAYMENT',
		'vads_payment_config' => 'SINGLE',
		'vads_site_id'        => '12345678',
		'vads_trans_date'     => gmdate( 'YmdHis' ),
		'vads_trans_id'       => $trans_id,
		'vads_trans_status'   => $status,
		'vads_url_check_src'  => 'PAY',
		'vads_version'        => 'V2',
	);
	ksort( $fields );
	$fields['signature'] = base64_encode( hash_hmac( 'sha256', implode( '+', $fields ) . '+' . $key, $key, true ) );
	return $finish_guest_run(
		'Systempay IPN',
		$order->get_id(),
		function () {
			sunshine_get_payment_method_by_id( 'systempay' )->ipn();
		},
		array(
			'get'         => array( 'sunshine_systempay_ipn' => 1 ),
			'post'        => $fields,
			'stop'        => array( 'post_processed' ),
			// So a handler that turns the IPN away with a log line comes back here.
			'logs'        => array( 'System Pay', 'Systempay', 'SystemPay' ),
			'logs_ignore' => array( 'Received System Pay IPN' ),
		)
	);
};

$t::test(
	'Systempay: the IPN finishes the order (prices include VAT, code, store credit)',
	function () use ( $t, $finish_guest_systempay_use, $finish_guest_shopper, $finish_guest_code, $finish_guest_enter, $finish_guest_seen, $finish_guest_place, $finish_guest_systempay_start, $finish_guest_systempay_ipn, $finish_guest_finished ) {
		$finish_guest_systempay_use();
		$customer = $finish_guest_shopper( 30 );
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$product           = $t::product( 120 );
		list( $id, $code ) = $finish_guest_code();
		$t::add( $product );
		$t::calculate();
		$t::same( true, $finish_guest_enter( $code ), 'code accepted at checkout' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 120 with 20 VAT inside. 10% off is 12, leaving 108 with 18 VAT inside.
		// Store credit 30 leaves 78 to pay.
		$t::money( 12, $cart->get_discount(), 'discount' );
		$t::money( 18, $cart->get_tax(), 'VAT inside the price' );
		$t::money( 78, $cart->get_total(), 'left to pay' );
		$seen     = $finish_guest_seen();
		$order    = $finish_guest_place( 'systempay', $seen );
		$trans_id = $finish_guest_systempay_start( $order );

		$run = $finish_guest_systempay_ipn( $order, $trans_id, 'AUTHORISED', 7800 );
		$t::same( 'post_processed', $run['stopped'], 'IPN accepted' );
		$finish_guest_finished(
			$order->get_id(),
			$seen,
			array(
				'customer'     => $customer,
				'credits_left' => 0,
				'code'         => array( $id, $code, $product ),
			)
		);
	}
);

// Assumed rule: only an accepted payment (vads_trans_status AUTHORISED, or CAPTURED once it is
// taken) pays for the order. A refused card must leave the order unpaid so the customer can try
// again.
$t::test(
	'Systempay: an IPN for a refused payment does not mark the order paid',
	function () use ( $t, $finish_guest_systempay_use, $finish_guest_shopper, $finish_guest_seen, $finish_guest_place, $finish_guest_systempay_start, $finish_guest_systempay_ipn, $finish_guest_credits_left ) {
		$finish_guest_systempay_use();
		$customer = $finish_guest_shopper( 30 );
		$t::add( $t::product( 150, array( 'taxable' => false ) ) );
		$t::checkout_address();
		$cart = $t::calculate();
		// 150 - 30 store credit = 120.
		$t::money( 120, $cart->get_total(), 'left to pay' );
		$order    = $finish_guest_place( 'systempay', $finish_guest_seen() );
		$trans_id = $finish_guest_systempay_start( $order );

		$finish_guest_systempay_ipn( $order, $trans_id, 'REFUSED', 12000 );
		$order = sunshine_get_order( $order->get_id() );
		$t::same( 'pending', $order->get_status(), 'order stays unpaid (Pending)' );
		$t::same( '', (string) get_post_meta( $order->get_id(), 'post_processed', true ), 'order is not finished' );
		$t::money( 30, $finish_guest_credits_left( $customer ), 'store credit stays on the account' );
	}
);

/*
 * Mercado Pago (Checkout Pro). The customer pays on Mercado Pago's page. Mercado Pago calls the
 * webhook with the payment ID, and the site asks Mercado Pago for that payment.
 */

$t::test(
	'Mercado Pago Checkout Pro: the webhook marks the order paid and finishes it (code, store credit)',
	function () use ( $t, $finish_guest_switch_on, $finish_guest_shopper, $finish_guest_code, $finish_guest_enter, $finish_guest_seen, $finish_guest_place, $finish_guest_run, $finish_guest_finished ) {
		$t::option( 'currency', 'BRL' );
		$finish_guest_switch_on( 'mercadopagopro' );
		$t::option( 'mercadopagopro_access_token_test', 'TEST-spcguest' );
		$customer          = $finish_guest_shopper( 30 );
		$product           = $t::product( 100, array( 'taxable' => false ) );
		list( $id, $code ) = $finish_guest_code();
		$t::add( $product );
		$t::calculate();
		$t::same( true, $finish_guest_enter( $code ), 'code accepted at checkout' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 100 - 10 discount - 30 store credit = 60.
		$t::money( 60, $cart->get_total(), 'left to pay' );
		$seen       = $finish_guest_seen();
		$order      = $finish_guest_place( 'mercadopagopro', $seen );
		$payment_id = '1319' . $order->get_id();
		$init_point = 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=spcguest';

		// Checkout makes a Mercado Pago "preference" for the order and sends the customer there.
		$start = $finish_guest_run(
			'Mercado Pago Pro redirect',
			$order->get_id(),
			function () {
				SPC()->cart->process_order();
			},
			array(
				'guest' => false,
				'reply' => function ( $request ) use ( $init_point ) {
					if ( 'api.mercadopago.com' === $request['host'] && '/checkout/preferences' === $request['path'] ) {
						return array(
							201,
							array(
								'id'         => 'spcguest-preference',
								'init_point' => $init_point,
							),
						);
					}
					return null;
				},
			)
		);
		$t::same( $init_point, $start['redirect'], 'customer is sent to Mercado Pago' );
		$preference = isset( $start['sent'][0]['body'] ) ? json_decode( (string) $start['sent'][0]['body'], true ) : array();
		$t::money( 60, isset( $preference['items'][0]['unit_price'] ) ? $preference['items'][0]['unit_price'] : null, 'Mercado Pago is asked for the order total' );

		$run = $finish_guest_run(
			'Mercado Pago Pro webhook',
			$order->get_id(),
			function () {
				sunshine_get_payment_method_by_id( 'mercadopagopro' )->webhooks();
			},
			array(
				'get'   => array(
					'sunshine_mercadopagopro_webhook' => '',
					'type'                            => 'payment',
					'data_id'                         => $payment_id,
				),
				'reply' => function ( $request ) use ( $order, $payment_id ) {
					if ( 'api.mercadopago.com' === $request['host'] && '/v1/payments/' . $payment_id === $request['path'] ) {
						return array(
							200,
							array(
								'id'                 => (int) $payment_id,
								'status'             => 'approved',
								'status_detail'      => 'accredited',
								'external_reference' => (string) $order->get_id(),
								'transaction_amount' => 60,
								'currency_id'        => 'BRL',
								'fee_details'        => array(
									array(
										'type'      => 'mercadopago_fee',
										'amount'    => 2.99,
										'fee_payer' => 'collector',
									),
								),
							),
						);
					}
					return null;
				},
				'stop'  => array( 'post_processed' ),
				'logs'  => array( 'could not be verified', 'not approved' ),
			)
		);
		$t::same( 'post_processed', $run['stopped'], 'webhook accepted' );
		$finish_guest_finished(
			$order->get_id(),
			$seen,
			array(
				'customer'     => $customer,
				'credits_left' => 0,
				'code'         => array( $id, $code, $product ),
			)
		);
	}
);

/*
 * Square. The card is charged from the checkout page and the order is finished when the page
 * comes back. Every 15 minutes a background check looks again at orders still waiting, in case
 * the customer was charged and closed the page before it came back. Here that check is run for
 * the test's own order only (the real one looks at every waiting Square order on the site).
 */

$finish_guest_square_use = function () use ( $t ) {
	$t::option( 'currency', 'USD' );
	$t::option( 'square_active', 1 );
	$t::option( 'square_mode', 'test' );
	$t::option( 'square_access_token_test', 'EAAAspcguesttest' );
	$t::option( 'square_application_id_test', 'sandbox-sq0idb-spcguest' );
	$t::option( 'square_location_id_test', 'LSPCGUEST' );
	$t::option( 'square_fee', 'none' );
};

// Square's background check for the order, with Square saying the payment is $status for $cents.
// With $photographer set, it is the Recheck button on the order screen instead: the same check,
// forced, run while the photographer is logged in.
$finish_guest_square_check = function ( $order, $cents, $status = 'COMPLETED', $photographer = 0 ) use ( $finish_guest_run ) {
	$payment_id    = (string) get_post_meta( $order->get_id(), 'square_payment_id', true );
	$result        = null;
	$run           = $finish_guest_run(
		$photographer ? 'Square recheck button' : 'Square background check',
		$order->get_id(),
		function () use ( &$result, $order, $photographer ) {
			$args = array();
			if ( $photographer ) {
				wp_set_current_user( $photographer );
				SPC()->customer = new SPC_Customer( $photographer );
				$args['force']  = true;
			}
			$result = sunshine_get_payment_method_by_id( 'square' )->reconcile_order( sunshine_get_order( $order->get_id() ), $args );
		},
		array(
			'guest' => ! $photographer,
			'reply' => function ( $request ) use ( $order, $payment_id, $cents, $status ) {
				if ( 'GET' === $request['method'] && '/v2/payments/' . $payment_id === $request['path'] ) {
					return array(
						200,
						array(
							'payment' => array(
								'id'           => $payment_id,
								'status'       => $status,
								'amount_money' => array(
									'amount'   => $cents,
									'currency' => 'USD',
								),
								'total_money'  => array(
									'amount'   => $cents,
									'currency' => 'USD',
								),
								'reference_id' => (string) $order->get_order_number(),
								'order_id'     => 'sqorder_spcguest' . $order->get_id(),
								'location_id'  => 'LSPCGUEST',
								'source_type'  => 'CARD',
							),
						),
					);
				}
				return null;
			},
		)
	);
	$run['result'] = $result;
	return $run;
};

$t::test(
	'Square: the background check finishes an order the customer left after paying (tax, amount-off code, store credit)',
	function () use ( $t, $finish_guest_square_use, $finish_guest_shopper, $finish_guest_code, $finish_guest_enter, $finish_guest_seen, $finish_guest_place, $finish_guest_square_check, $finish_guest_finished ) {
		$finish_guest_square_use();
		$customer = $finish_guest_shopper( 30 );
		$t::tax( 8 );
		$product           = $t::product( 100 );
		list( $id, $code ) = $finish_guest_code(
			array(
				'discount_type'   => 'amount-total',
				'discount_amount' => '10',
			)
		);
		$t::add( $product );
		$t::calculate();
		$t::same( true, $finish_guest_enter( $code ), 'code accepted at checkout' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 100 - 10 discount = 90, + 7.20 tax = 97.20. Store credit 30 leaves 67.20 to pay.
		$t::money( 7.20, $cart->get_tax(), 'tax' );
		$t::money( 67.20, $cart->get_total(), 'left to pay' );
		$seen  = $finish_guest_seen();
		$order = $finish_guest_place( 'square', $seen );
		// What Square's card step saves on the order once the card is charged.
		$order->update_meta_value( 'square_payment_id', 'sqpay_spcguest' . $order->get_id() );

		$run = $finish_guest_square_check( $order, 6720 );
		$t::same( 'finalized', isset( $run['result']['action'] ) ? $run['result']['action'] : null, 'payment found and order finished' );
		$finish_guest_finished(
			$order->get_id(),
			$seen,
			array(
				'customer'     => $customer,
				'credits_left' => 0,
				'code'         => array( $id, $code, $product ),
			)
		);
	}
);

$t::test(
	'Square: the background check does not finish an order paid for less than its total',
	function () use ( $t, $finish_guest_square_use, $finish_guest_shopper, $finish_guest_seen, $finish_guest_place, $finish_guest_square_check, $finish_guest_credits_left ) {
		$finish_guest_square_use();
		$customer = $finish_guest_shopper( 30 );
		$t::add( $t::product( 130, array( 'taxable' => false ) ) );
		$t::checkout_address();
		$cart = $t::calculate();
		// 130 - 30 store credit = 100.
		$t::money( 100, $cart->get_total(), 'left to pay' );
		$order = $finish_guest_place( 'square', $finish_guest_seen() );
		$order->update_meta_value( 'square_payment_id', 'sqpay_spcguest' . $order->get_id() );

		$run = $finish_guest_square_check( $order, 5000 );
		$t::same( 'mismatch', isset( $run['result']['action'] ) ? $run['result']['action'] : null, 'payment does not match the order' );
		$t::same( 'pending', sunshine_get_order( $order->get_id() )->get_status(), 'order stays unpaid (Pending)' );
		$t::money( 30, $finish_guest_credits_left( $customer ), 'store credit stays on the account' );
	}
);

// The photographer sees a Square order still waiting and presses Recheck on the order screen.
// The order is finished while the photographer is logged in, but it is still the customer's
// order: the customer's credit and code use are counted, never the photographer's.
$t::test(
	'Square Recheck button: the customer\'s store credit is used, not the photographer\'s',
	function () use ( $t, $finish_guest_square_use, $finish_guest_shopper, $finish_guest_code, $finish_guest_enter, $finish_guest_seen, $finish_guest_place, $finish_guest_square_check, $finish_guest_credits_left ) {
		$finish_guest_square_use();
		$customer          = $finish_guest_shopper( 30 );
		list( $id, $code ) = $finish_guest_code();
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $finish_guest_enter( $code ), 'code accepted at checkout' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 100 - 10 discount - 30 store credit = 60.
		$t::money( 60, $cart->get_total(), 'left to pay' );
		$order = $finish_guest_place( 'square', $finish_guest_seen() );
		$order->update_meta_value( 'square_payment_id', 'sqpay_spcguest' . $order->get_id() );

		// A photographer account that also has store credit of its own (from testing the store).
		$photographer = wp_insert_user(
			array(
				'user_login' => 'spcguestadmin' . wp_rand( 100000, 999999 ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);
		$t::track( 'user', $photographer );
		( new SPC_Customer( $photographer ) )->set_credits( '40' );

		$run = $finish_guest_square_check( $order, 6000, 'COMPLETED', $photographer );
		$t::same( 'finalized', isset( $run['result']['action'] ) ? $run['result']['action'] : null, 'payment found and order finished' );
		$t::same( 'new', sunshine_get_order( $order->get_id() )->get_status(), 'order is marked paid (New)' );
		$t::money( 0, $finish_guest_credits_left( $customer ), 'store credit left on the customer account' );
		$t::money( 40, $finish_guest_credits_left( $photographer ), 'the photographer\'s own credit is not touched' );
		$used_by = array_map( 'strval', (array) get_post_meta( $id, 'used_by' ) );
		$t::same( false, in_array( (string) $photographer, $used_by, true ), 'the code is not counted as used by the photographer' );
		$t::same( true, in_array( (string) $customer, $used_by, true ), 'the code is counted as used by the customer' );
	}
);

$finish_guest_tidy( true );
