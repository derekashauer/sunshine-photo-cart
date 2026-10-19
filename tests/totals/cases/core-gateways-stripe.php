<?php
/**
 * What Sunshine sends to Stripe, compared with the order total. Nothing reaches Stripe: every
 * outgoing request is caught before it leaves the site and answered with a made up reply.
 *
 * - Inline payment form: the PaymentIntent amount and the platform fee (5% without Stripe Pro).
 * - Stripe takes amounts in the smallest unit of the currency: cents for USD, whole yen for JPY.
 * - Hosted checkout with Stripe Tax: the tax Stripe charged is written back to the order.
 *
 * Real Stripe test mode checks for hosted checkout are in stripe-hosted.php.
 */

$t = 'SPC_Totals_Test';

// The customer picked Stripe at checkout. Fees come from this test only, never the site's settings.
$gateways_stripe_use = function ( $fee_type = 'none', $fee_amount = '' ) use ( $t ) {
	$t::option( 'stripe_fee', $fee_type );
	$t::option( 'stripe_fee_amount', (string) $fee_amount );
	$t::option( 'stripe_fee_name', 'Card fee' );
	$t::option( 'stripe_checkout_mode', 'inline' );
	$t::option( 'stripe_optimized_checkout', 'yes' );
	$t::option( 'stripe_tax_mode', 'sunshine' );
	$t::checkout_address();
	SPC()->cart->set_checkout_data_item( 'payment_method', 'stripe' );
};

// Stripe keeps the payment it is working on in the session and on the payment method itself.
// Start each test without one, so a payment left over from another test is never updated.
$gateways_stripe_forget = function () {
	$stripe = sunshine_get_payment_method_by_id( 'stripe' );
	foreach ( array( 'payment_intent_id', 'client_secret' ) as $property ) {
		$prop = new ReflectionProperty( $stripe, $property );
		$prop->setAccessible( true );
		$prop->setValue( $stripe, '' );
	}
	$prop = new ReflectionProperty( $stripe, 'total' );
	$prop->setAccessible( true );
	$prop->setValue( $stripe, 0 );
	foreach ( array( 'stripe_payment_intent_id', 'stripe_client_secret', 'stripe_idempotency_key', 'stripe_customer_id' ) as $key ) {
		SPC()->session->set( $key, '' );
	}
};

// Sunshine without Stripe Pro takes a 5% platform fee. Stripe Pro is active on the test site,
// so the tests that check the fee turn it back on.
$gateways_stripe_platform_fee = function () {
	return 5;
};

/**
 * Run $callback with every outgoing request answered like Stripe would, without sending it.
 * Returns the list of requests that would have been sent: method, path (after /v1/), body.
 */
$gateways_stripe_run = function ( $callback ) {
	$calls   = array();
	$coupons = array();
	$http    = function ( $pre, $args, $url ) use ( &$calls, &$coupons ) {
		$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
		$body   = array();
		if ( ! empty( $args['body'] ) ) {
			if ( is_string( $args['body'] ) ) {
				parse_str( $args['body'], $body );
			} else {
				$body = (array) $args['body'];
			}
		}
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $query_args );
			$body = array_merge( $query_args, $body );
		}
		$path    = preg_replace( '#^/v1/#', '', (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$host    = (string) wp_parse_url( $url, PHP_URL_HOST );
		$calls[] = array(
			'method'  => $method,
			'host'    => $host,
			'path'    => $path,
			'body'    => $body,
			'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
		);

		$reply = array( 'error' => array( 'message' => 'Blocked by the totals tests' ) );
		if ( 'api.stripe.com' === $host ) {
			$email = SPC()->cart->get_checkout_data_item( 'email' );
			if ( 'customers' === $path && 'GET' === $method ) {
				$reply = array(
					'object' => 'list',
					'data'   => array(),
				);
			} elseif ( 'customers' === $path ) {
				$reply = array(
					'id'    => 'cus_spctest',
					'email' => $email,
				);
			} elseif ( 0 === strpos( $path, 'customers/' ) ) {
				$reply = array(
					'id'    => substr( $path, 10 ),
					'email' => $email,
				);
			} elseif ( 'payment_intents' === $path && 'POST' === $method ) {
				$id    = 'pi_spctest' . count( $calls );
				$reply = array(
					'id'            => $id,
					'client_secret' => $id . '_secret_spctest',
					'status'        => 'requires_payment_method',
					'amount'        => isset( $body['amount'] ) ? intval( $body['amount'] ) : 0,
					'customer'      => isset( $body['customer'] ) ? $body['customer'] : null,
				);
			} elseif ( preg_match( '#^payment_intents/([^/]+)$#', $path, $match ) ) {
				// The payment was made for the shopper's Stripe customer. That can be one reused
				// from an earlier order with the same email, not always cus_spctest.
				$customer = sunshine_get_payment_method_by_id( 'stripe' )->get_stripe_customer_id();
				$reply    = array(
					'id'            => $match[1],
					'client_secret' => $match[1] . '_secret_spctest',
					'status'        => 'requires_payment_method',
					'customer'      => $customer ? $customer : 'cus_spctest',
				);
				if ( isset( $body['amount'] ) ) {
					$reply['amount'] = intval( $body['amount'] );
				}
			} elseif ( 0 === strpos( $path, 'coupons/' ) && 'GET' === $method ) {
				$reply = array(
					'error' => array(
						'code'    => 'resource_missing',
						'message' => 'No such coupon',
					),
				);
			} elseif ( 'coupons' === $path && 'POST' === $method ) {
				$coupons[ $body['id'] ] = intval( $body['amount_off'] );
				$reply                  = array(
					'id'         => $body['id'],
					'amount_off' => intval( $body['amount_off'] ),
					'currency'   => $body['currency'],
				);
			} elseif ( 'checkout/sessions' === $path && 'POST' === $method ) {
				// What Stripe charges for a session: line items, plus shipping, minus the coupon.
				// Stripe Tax adds tax only once the customer gives an address, so none yet.
				$amount = 0;
				foreach ( (array) $body['line_items'] as $line ) {
					$amount += intval( $line['price_data']['unit_amount'] ) * intval( $line['quantity'] );
				}
				if ( ! empty( $body['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'] ) ) {
					$amount += intval( $body['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'] );
				}
				if ( ! empty( $body['discounts'][0]['coupon'] ) && isset( $coupons[ $body['discounts'][0]['coupon'] ] ) ) {
					$amount -= $coupons[ $body['discounts'][0]['coupon'] ];
				}
				$reply = array(
					'id'            => 'cs_spctest' . count( $calls ),
					'url'           => 'https://checkout.stripe.com/c/pay/cs_spctest',
					'currency'      => strtolower( $body['line_items'][0]['price_data']['currency'] ),
					'amount_total'  => $amount,
					'total_details' => array( 'amount_tax' => 0 ),
				);
			}
		}

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $reply ),
			'response' => array(
				'code'    => isset( $reply['error'] ) ? 400 : 200,
				'message' => isset( $reply['error'] ) ? 'Bad Request' : 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};

	add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
	try {
		$result = $callback();
	} finally {
		remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
	}
	return array(
		'calls'  => $calls,
		'result' => $result,
	);
};

// The checkout page asks the site for a Stripe payment (the inline form does this on load and
// whenever the cart changes). Returns the requests that would have gone to Stripe.
$gateways_stripe_intent = function () use ( $gateways_stripe_run ) {
	$stripe = sunshine_get_payment_method_by_id( 'stripe' );
	$run    = $gateways_stripe_run(
		function () use ( $stripe ) {
			$die = function () {
				return function () {
					throw new SPC_Totals_Test_Done();
				};
			};
			add_filter( 'wp_doing_ajax', '__return_true' );
			add_filter( 'wp_die_ajax_handler', $die );
			$_POST['security'] = wp_create_nonce( 'sunshine_stripe' );
			ob_start();
			try {
				$stripe->create_payment_intent();
			} catch ( SPC_Totals_Test_Done $e ) {
				// create_payment_intent() always ends by sending a JSON response.
			} finally {
				$output = ob_get_clean();
				unset( $_POST['security'] );
				remove_filter( 'wp_doing_ajax', '__return_true' );
				remove_filter( 'wp_die_ajax_handler', $die );
			}
			return json_decode( $output, true );
		}
	);
	return $run['calls'];
};

// The last request that created or changed a payment (PaymentIntent), or null.
$gateways_stripe_last_payment = function ( $calls ) {
	$found = null;
	foreach ( $calls as $call ) {
		if ( 'POST' === $call['method'] && preg_match( '#^payment_intents(/[^/]+)?$#', $call['path'] ) ) {
			$found = $call;
		}
	}
	return $found;
};

// Would Sunshine accept a Stripe payment of this many units as paying the order in full?
$gateways_stripe_accepts = function ( $order, $amount, $currency ) {
	$stripe = sunshine_get_payment_method_by_id( 'stripe' );
	$check  = new ReflectionMethod( $stripe, 'payment_intent_matches_order' );
	$check->setAccessible( true );
	return $check->invoke(
		$stripe,
		array(
			'id'              => 'pi_spctest_check',
			'amount_received' => $amount,
			'currency'        => $currency,
		),
		$order
	);
};

// Send a Stripe webhook to the site, signed with the site's webhook secret, and return the
// HTTP status it answers with. The webhook reads the raw request body, so php://input is
// swapped for the event while it runs. It ends with exit(), so the status it sets just before
// is turned into an exception to get back here.
$gateways_stripe_webhook = function ( $event ) {
	$stripe  = sunshine_get_payment_method_by_id( 'stripe' );
	$payload = wp_json_encode( $event );
	$secret  = SPC()->get_option( 'stripe_webhook_secret_test' );
	$time    = time();

	$input              = new class() {
		public $context;
		public static $body = '';
		private $position   = 0;
		public function stream_open( $path, $mode, $options, &$opened_path ) {
			$this->position = 0;
			return 'php://input' === strtolower( $path );
		}
		public function stream_read( $count ) {
			$chunk           = substr( self::$body, $this->position, $count );
			$this->position += strlen( $chunk );
			return $chunk;
		}
		public function stream_eof() {
			return $this->position >= strlen( self::$body );
		}
		public function stream_stat() {
			return array();
		}
	};
	$input_class        = get_class( $input );
	$input_class::$body = $payload;

	$status         = null;
	$stop           = function ( $header, $code ) use ( &$status ) {
		$status = $code;
		throw new SPC_Totals_Test_Done();
	};
	$filters_before = $GLOBALS['wp_current_filter'];

	add_filter( 'status_header', $stop, PHP_INT_MAX, 2 );
	$_GET['sunshine_stripe_webhook']  = 1;
	$_SERVER['HTTP_STRIPE_SIGNATURE'] = 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $payload, $secret );
	stream_wrapper_unregister( 'php' );
	stream_wrapper_register( 'php', $input_class );
	try {
		$stripe->process_webhook();
	} catch ( SPC_Totals_Test_Done $e ) {
		// process_webhook() always ends with a status and exit().
	} finally {
		stream_wrapper_restore( 'php' );
		remove_filter( 'status_header', $stop, PHP_INT_MAX );
		unset( $_GET['sunshine_stripe_webhook'], $_SERVER['HTTP_STRIPE_SIGNATURE'] );
		$GLOBALS['wp_current_filter'] = $filters_before;
	}
	return $status;
};

$t::test(
	'Stripe: payment is the order total (tax and taxed shipping)',
	function () use ( $t, $gateways_stripe_use, $gateways_stripe_forget, $gateways_stripe_intent, $gateways_stripe_last_payment, $gateways_stripe_accepts ) {
		$gateways_stripe_forget();
		$t::tax( 7.25 );
		$t::add( $t::product( 19.99 ), 3 );
		$t::add( $t::product( 4.50, array( 'taxable' => false ) ), 2 );
		$t::flat_shipping( 8.95, true );
		$gateways_stripe_use();
		$t::calculate();
		$payment = $gateways_stripe_last_payment( $gateways_stripe_intent() );
		// 59.97 taxed + 9.00 not taxed + 8.95 shipping taxed. Tax: 4.35 on the prints + 0.65 on
		// shipping = 5.00. Total 82.92, sent as 8292 cents.
		$t::same( 'payment_intents', $payment ? $payment['path'] : null, 'a payment was started' );
		$t::same( '8292', $payment ? $payment['body']['amount'] : null, 'amount in cents' );
		$t::same( 'usd', $payment ? strtolower( $payment['body']['currency'] ) : null, 'currency' );
		$t::same( false, $payment ? isset( $payment['body']['application_fee_amount'] ) : null, 'no platform fee with Stripe Pro' );

		$order = $t::place_order( 'stripe' );
		$t::money( 82.92, $order->get_total(), 'order total' );
		$t::same( true, $gateways_stripe_accepts( $order, 8292, 'usd' ), 'Stripe paying 8292 cents completes the order' );
		$t::same( false, $gateways_stripe_accepts( $order, 8291, 'usd' ), 'a cent short does not' );
		$gateways_stripe_forget();
	}
);

$t::test(
	'Stripe: discount code, gift card and shipping',
	function () use ( $t, $gateways_stripe_use, $gateways_stripe_forget, $gateways_stripe_intent, $gateways_stripe_last_payment ) {
		$gateways_stripe_forget();
		$t::tax( 10 );
		$t::add( $t::product( 50 ), 2 );
		$t::flat_shipping( 10, true );
		$t::discount( 'percent-total', 15 );
		$t::gift_card( 25 );
		$gateways_stripe_use();
		$cart    = $t::calculate();
		$payment = $gateways_stripe_last_payment( $gateways_stripe_intent() );
		// 100 - 15 = 85, + 10 shipping, tax on 95 = 9.50, - 25 card = 79.50.
		$t::money( 79.50, $cart->get_total(), 'cart total' );
		$t::same( '7950', $payment ? $payment['body']['amount'] : null, 'amount in cents' );
		$gateways_stripe_forget();
	}
);

$t::test(
	'Stripe: store credit',
	function () use ( $t, $gateways_stripe_use, $gateways_stripe_forget, $gateways_stripe_intent, $gateways_stripe_last_payment ) {
		$gateways_stripe_forget();
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$gateways_stripe_use();
		$t::calculate();
		$payment = $gateways_stripe_last_payment( $gateways_stripe_intent() );
		// 100 + 10 tax - 20 credit = 90.
		$t::same( '9000', $payment ? $payment['body']['amount'] : null, 'amount in cents' );
		$gateways_stripe_forget();
	}
);

$t::test(
	'Stripe: percent payment fee',
	function () use ( $t, $gateways_stripe_use, $gateways_stripe_forget, $gateways_stripe_intent, $gateways_stripe_last_payment ) {
		$gateways_stripe_forget();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$gateways_stripe_use( 'percent', 3 );
		$t::calculate();
		$payment = $gateways_stripe_last_payment( $gateways_stripe_intent() );
		// 110 + 3% of 110 = 113.30.
		$t::same( '11330', $payment ? $payment['body']['amount'] : null, 'amount in cents' );
		$gateways_stripe_forget();
	}
);

$t::test(
	'Stripe: prices include tax with a discount code',
	function () use ( $t, $gateways_stripe_use, $gateways_stripe_forget, $gateways_stripe_intent, $gateways_stripe_last_payment ) {
		$gateways_stripe_forget();
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$gateways_stripe_use();
		$t::calculate();
		$payment = $gateways_stripe_last_payment( $gateways_stripe_intent() );
		// 110 - 11 = 99, tax is already inside.
		$t::same( '9900', $payment ? $payment['body']['amount'] : null, 'amount in cents' );
		$gateways_stripe_forget();
	}
);

$t::test(
	'Stripe: discount after tax',
	function () use ( $t, $gateways_stripe_use, $gateways_stripe_forget, $gateways_stripe_intent, $gateways_stripe_last_payment ) {
		$gateways_stripe_forget();
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$gateways_stripe_use();
		$t::calculate();
		$payment = $gateways_stripe_last_payment( $gateways_stripe_intent() );
		// 100 + 10 tax - 22 = 88.
		$t::same( '8800', $payment ? $payment['body']['amount'] : null, 'amount in cents' );
		$gateways_stripe_forget();
	}
);

// The platform fee is 5% of what Stripe charges, rounded to the cent.
$t::test(
	'Stripe: platform fee is 5% of the payment',
	function () use ( $t, $gateways_stripe_use, $gateways_stripe_forget, $gateways_stripe_intent, $gateways_stripe_last_payment, $gateways_stripe_platform_fee ) {
		$gateways_stripe_forget();
		add_filter( 'sunshine_stripe_application_fee_percent', $gateways_stripe_platform_fee, 999 );
		try {
			$t::tax( 10 );
			$t::add( $t::product( 100 ) );
			$gateways_stripe_use( 'percent', 3 );
			$t::calculate();
			$payment = $gateways_stripe_last_payment( $gateways_stripe_intent() );
			// 113.30 charged, 5% = 5.665, rounds to 5.67.
			$t::same( '11330', $payment ? $payment['body']['amount'] : null, 'amount in cents' );
			$t::same( '567', $payment && isset( $payment['body']['application_fee_amount'] ) ? $payment['body']['application_fee_amount'] : null, 'platform fee in cents' );
		} finally {
			remove_filter( 'sunshine_stripe_application_fee_percent', $gateways_stripe_platform_fee, 999 );
			$gateways_stripe_forget();
		}
	}
);

// A gift card pays part of the order outside Stripe, so the fee is only on what Stripe charges.
$t::test(
	'Stripe: platform fee with a gift card',
	function () use ( $t, $gateways_stripe_use, $gateways_stripe_forget, $gateways_stripe_intent, $gateways_stripe_last_payment, $gateways_stripe_platform_fee ) {
		$gateways_stripe_forget();
		add_filter( 'sunshine_stripe_application_fee_percent', $gateways_stripe_platform_fee, 999 );
		try {
			$t::add( $t::product( 100, array( 'taxable' => false ) ) );
			$t::gift_card( 30 );
			$gateways_stripe_use();
			$t::calculate();
			$payment = $gateways_stripe_last_payment( $gateways_stripe_intent() );
			// 100 - 30 card = 70 charged, 5% = 3.50.
			$t::same( '7000', $payment ? $payment['body']['amount'] : null, 'amount in cents' );
			$t::same( '350', $payment && isset( $payment['body']['application_fee_amount'] ) ? $payment['body']['application_fee_amount'] : null, 'platform fee in cents' );
		} finally {
			remove_filter( 'sunshine_stripe_application_fee_percent', $gateways_stripe_platform_fee, 999 );
			$gateways_stripe_forget();
		}
	}
);

// The payment form loads, then the customer adds a gift card. The same Stripe payment is
// changed to the new total, fee included.
$t::test(
	'Stripe: payment is updated when the cart changes',
	function () use ( $t, $gateways_stripe_use, $gateways_stripe_forget, $gateways_stripe_intent, $gateways_stripe_last_payment, $gateways_stripe_platform_fee ) {
		$gateways_stripe_forget();
		add_filter( 'sunshine_stripe_application_fee_percent', $gateways_stripe_platform_fee, 999 );
		try {
			$t::tax( 10 );
			$t::add( $t::product( 100 ) );
			$gateways_stripe_use();
			$t::calculate();
			$first = $gateways_stripe_last_payment( $gateways_stripe_intent() );
			$t::same( '11000', $first ? $first['body']['amount'] : null, 'first amount' );

			$t::gift_card( 30 );
			$t::new_request();
			$second = $gateways_stripe_last_payment( $gateways_stripe_intent() );
			// 110 - 30 card = 80, fee 4.00. Same payment, so it is an update, not a new one.
			$t::same( true, $second && 'payment_intents' !== $second['path'], 'the same payment is updated' );
			$t::same( '8000', $second ? $second['body']['amount'] : null, 'new amount' );
			$t::same( '400', $second && isset( $second['body']['application_fee_amount'] ) ? $second['body']['application_fee_amount'] : null, 'new platform fee' );
		} finally {
			remove_filter( 'sunshine_stripe_application_fee_percent', $gateways_stripe_platform_fee, 999 );
			$gateways_stripe_forget();
		}
	}
);

$t::test(
	'Stripe: nothing to pay starts no payment',
	function () use ( $t, $gateways_stripe_use, $gateways_stripe_forget, $gateways_stripe_intent ) {
		$gateways_stripe_forget();
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::gift_card( 200 );
		$gateways_stripe_use();
		$cart = $t::calculate();
		$t::money( 0, $cart->get_total(), 'cart total' );
		$calls    = $gateways_stripe_intent();
		$payments = array_filter(
			$calls,
			function ( $call ) {
				return 0 === strpos( $call['path'], 'payment_intents' );
			}
		);
		$t::same( 0, count( $payments ), 'no payment requests' );
		$gateways_stripe_forget();
	}
);

// Yen has no smaller unit, so 2200 yen is sent as 2200, not 220000.
$t::test(
	'Stripe: yen is sent in whole yen',
	function () use ( $t, $gateways_stripe_use, $gateways_stripe_forget, $gateways_stripe_intent, $gateways_stripe_last_payment, $gateways_stripe_platform_fee, $gateways_stripe_accepts ) {
		$gateways_stripe_forget();
		add_filter( 'sunshine_stripe_application_fee_percent', $gateways_stripe_platform_fee, 999 );
		try {
			$t::option( 'currency', 'JPY' );
			$t::option( 'country', 'JP' );
			$t::tax( 10 );
			$t::add( $t::product( 1000 ), 2 );
			$gateways_stripe_use();
			$t::calculate();
			$payment = $gateways_stripe_last_payment( $gateways_stripe_intent() );
			// 2000 + 200 tax = 2200 yen. Fee 5% = 110 yen.
			$t::same( '2200', $payment ? $payment['body']['amount'] : null, 'amount in yen' );
			$t::same( 'jpy', $payment ? strtolower( $payment['body']['currency'] ) : null, 'currency' );
			$t::same( '110', $payment && isset( $payment['body']['application_fee_amount'] ) ? $payment['body']['application_fee_amount'] : null, 'platform fee in yen' );
			$order = $t::place_order( 'stripe' );
			$t::same( true, $gateways_stripe_accepts( $order, 2200, 'jpy' ), 'Stripe paying 2200 yen completes the order' );
		} finally {
			remove_filter( 'sunshine_stripe_application_fee_percent', $gateways_stripe_platform_fee, 999 );
			$gateways_stripe_forget();
		}
	}
);

// 10% of 1005 yen is 100.5 yen. Stripe can only charge whole yen, so the order total must be
// whole yen too, or what was charged and what the order says don't agree.
$t::test(
	'Stripe: yen tax of half a yen',
	function () use ( $t, $gateways_stripe_use, $gateways_stripe_forget, $gateways_stripe_intent, $gateways_stripe_last_payment ) {
		$gateways_stripe_forget();
		$t::option( 'currency', 'JPY' );
		$t::option( 'country', 'JP' );
		$t::tax( 10 );
		$t::add( $t::product( 1005 ) );
		$gateways_stripe_use();
		$t::calculate();
		$payment = $gateways_stripe_last_payment( $gateways_stripe_intent() );
		$order   = $t::place_order( 'stripe' );
		$total   = (float) $order->get_total();
		$t::same( true, abs( $total - round( $total ) ) < 0.001, 'order total is whole yen (got ' . $total . ')' );
		$t::money( $total, $payment ? $payment['body']['amount'] : null, 'Stripe charges the order total' );
		$gateways_stripe_forget();
	}
);

// Hosted checkout in yen: every amount on the Stripe page is whole yen and adds up to the order.
$t::test(
	'Stripe hosted: yen items, shipping and discount',
	function () use ( $t, $gateways_stripe_use, $gateways_stripe_forget, $gateways_stripe_run, $gateways_stripe_platform_fee ) {
		$gateways_stripe_forget();
		add_filter( 'sunshine_stripe_application_fee_percent', $gateways_stripe_platform_fee, 999 );
		try {
			$t::option( 'currency', 'JPY' );
			$t::option( 'country', 'JP' );
			$t::add( $t::product( 1000 ), 2 );
			$t::flat_shipping( 500, false );
			$t::discount( 'percent-total', 10 );
			$gateways_stripe_use();
			$t::option( 'stripe_checkout_mode', 'hosted' );
			$t::calculate();
			$order = $t::place_order( 'stripe' );
			// 2000 - 200 discount + 500 shipping = 2300 yen.
			$t::money( 2300, $order->get_total(), 'order total' );

			$stripe = sunshine_get_payment_method_by_id( 'stripe' );
			$create = new ReflectionMethod( $stripe, 'create_checkout_session' );
			$create->setAccessible( true );
			$run     = $gateways_stripe_run(
				function () use ( $create, $stripe, $order ) {
					return $create->invoke( $stripe, $order );
				}
			);
			$session = null;
			$coupon  = null;
			foreach ( $run['calls'] as $call ) {
				if ( 'checkout/sessions' === $call['path'] ) {
					$session = $call['body'];
				} elseif ( 'coupons' === $call['path'] && 'POST' === $call['method'] ) {
					$coupon = $call['body'];
				}
			}
			$t::same( true, is_array( $session ), 'a checkout session was made' );
			$t::same( '1000', $session ? $session['line_items'][0]['price_data']['unit_amount'] : null, 'price each in yen' );
			$t::same( '2', $session ? $session['line_items'][0]['quantity'] : null, 'quantity' );
			$t::same( '500', $session && isset( $session['shipping_options'][0] ) ? $session['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'] : null, 'shipping in yen' );
			$t::same( '200', $coupon ? $coupon['amount_off'] : null, 'discount in yen' );
			$t::same( '115', $session && isset( $session['payment_intent_data']['application_fee_amount'] ) ? $session['payment_intent_data']['application_fee_amount'] : null, 'platform fee 5% of 2300' );
			$t::same( false, is_wp_error( $run['result'] ), 'session made' );
			$t::money( 2300, is_array( $run['result'] ) ? $run['result']['amount_total'] : null, 'Stripe charges the order total' );
		} finally {
			remove_filter( 'sunshine_stripe_application_fee_percent', $gateways_stripe_platform_fee, 999 );
			$gateways_stripe_forget();
		}
	}
);

// With Stripe Tax, Stripe works out the tax once the customer gives an address on the Stripe
// page. The docs say the tax is synced back to the order after payment. The order must then say
// what the customer paid: its total has to include that tax.
$t::test(
	'Stripe Tax: order total includes the tax Stripe charged',
	function () use ( $t, $gateways_stripe_use, $gateways_stripe_forget, $gateways_stripe_run, $gateways_stripe_webhook ) {
		$gateways_stripe_forget();
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, false );
		$gateways_stripe_use();
		$t::option( 'stripe_checkout_mode', 'hosted' );
		$t::option( 'stripe_tax_mode', 'stripe' );
		$t::option( 'stripe_webhook_secret_test', 'whsec_spctotalstest' );
		$t::calculate();
		$order = $t::place_order( 'stripe' );
		// Sunshine's own taxes are off: 100 + 10 shipping = 110 before Stripe adds tax.
		$t::money( 110, $order->get_total(), 'order total before payment' );

		$stripe = sunshine_get_payment_method_by_id( 'stripe' );
		$create = new ReflectionMethod( $stripe, 'create_checkout_session' );
		$create->setAccessible( true );
		$run     = $gateways_stripe_run(
			function () use ( $create, $stripe, $order ) {
				return $create->invoke( $stripe, $order );
			}
		);
		$session = $run['result'];
		$t::same( true, is_array( $session ) && ! empty( $session['id'] ), 'checkout session made' );
		if ( ! is_array( $session ) || empty( $session['id'] ) ) {
			return;
		}

		// The customer paid on Stripe: 8.25 tax on the 100 print, 118.25 in all.
		// The order is marked finished already so the webhook doesn't send receipts.
		$order->update_meta_value( 'post_processed', time() );
		$status = $gateways_stripe_run(
			function () use ( $gateways_stripe_webhook, $session, $order ) {
				return $gateways_stripe_webhook(
					array(
						'id'   => 'evt_spctest',
						'type' => 'checkout.session.completed',
						'data' => array(
							'object' => array(
								'id'              => $session['id'],
								'object'          => 'checkout.session',
								'payment_status'  => 'paid',
								'currency'        => 'usd',
								'amount_total'    => 11825,
								'amount_subtotal' => 11000,
								'total_details'   => array(
									'amount_tax'      => 825,
									'amount_discount' => 0,
									'amount_shipping' => 1000,
								),
								'payment_intent'  => 'pi_spctest_tax',
								'metadata'        => array( 'order_id' => (string) $order->get_id() ),
							),
						),
					)
				);
			}
		);
		$t::same( 200, $status['result'], 'webhook accepted' );

		$paid = sunshine_get_order( $order->get_id() );
		$t::money( 8.25, $paid->get_tax(), 'order tax is what Stripe charged' );
		$t::money( 118.25, $paid->get_total(), 'order total is what the customer paid' );
		$gateways_stripe_forget();
	}
);
