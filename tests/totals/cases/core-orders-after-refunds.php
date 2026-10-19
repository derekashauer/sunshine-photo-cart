<?php
/**
 * Refunds on a saved order: the order's own refund math, and what each payment method sends
 * when the photographer clicks "Refund payment" on the order screen.
 *
 * Nothing reaches Stripe, Square or PayPal. Every web request is caught before it leaves the
 * site and gets a made-up "it worked" reply, and every email is stopped. So these tests check
 * the amounts Sunshine sends and records, not whether the payment company accepts them.
 */

$t = 'SPC_Totals_Test';

// Run $body with every web request and email caught. $reply( $url ) gives the made-up reply
// for each request. Returns what would have been sent, as a list of url + body.
$orders_after_refunds_offline = function ( $reply, $body ) {
	$sent = array();
	$http = function ( $pre, $args, $url ) use ( &$sent, $reply ) {
		$sent[] = array(
			'url'  => $url,
			'body' => isset( $args['body'] ) ? $args['body'] : '',
		);
		return array(
			'headers'  => array(),
			'body'     => (string) $reply( $url ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
	add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
	try {
		$body();
	} finally {
		remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
		remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
		unset( $_POST['stripe_refund_amount'], $_POST['stripe_refund_notify'], $_POST['square_refund_amount'], $_POST['paypal_refund_amount'] );
	}
	return $sent;
};

// PHP warnings and notices raised while $body runs (deprecation notices are left to print).
$orders_after_refunds_warnings = function ( $body ) {
	$warnings = array();
	set_error_handler(
		function ( $level, $message, $file, $line ) use ( &$warnings ) {
			if ( E_DEPRECATED === $level || E_USER_DEPRECATED === $level ) {
				return false;
			}
			$warnings[] = $message . ' (' . basename( $file ) . ':' . $line . ')';
			return true;
		}
	);
	try {
		$body();
	} finally {
		restore_error_handler();
	}
	return $warnings;
};

// Made-up replies, shaped like the real ones the refund code reads.
$orders_after_refunds_reply = function ( $charged_cents ) {
	return function ( $url ) use ( $charged_cents ) {
		if ( false !== strpos( $url, 'stripe.com/v1/payment_intents/' ) ) {
			return wp_json_encode(
				array(
					'id'     => 'pi_offline',
					'amount' => $charged_cents,
				)
			);
		}
		if ( false !== strpos( $url, 'stripe.com/v1/refunds' ) ) {
			return '{"id":"re_offline","status":"succeeded"}';
		}
		if ( false !== strpos( $url, '/v2/refunds' ) ) {
			return '{"refund":{"id":"sq_offline","status":"PENDING"}}';
		}
		if ( false !== strpos( $url, '/captures/' ) ) {
			return '{"id":"pp_offline","status":"COMPLETED"}';
		}
		return '{}';
	};
};

// A paid order: 3 prints at 19.99 with 7.25% tax. 59.97 + 4.35 tax = 64.32.
$orders_after_refunds_paid_order = function () use ( $t ) {
	$t::tax( 7.25 );
	$t::add( $t::product( 19.99 ), 3 );
	$t::calculate();
	$order = $t::place_order();
	$t::money( 64.32, $order->get_total(), 'order total (59.97 + 4.35 tax)' );
	return $order;
};

// The amount (in cents) of the refund request that went to the payment company.
$orders_after_refunds_sent_amount = function ( $sent, $url_part ) {
	foreach ( $sent as $request ) {
		if ( false === strpos( $request['url'], $url_part ) ) {
			continue;
		}
		$body = $request['body'];
		if ( is_array( $body ) ) {
			return isset( $body['amount'] ) ? $body['amount'] : null;
		}
		$json = json_decode( $body, true );
		if ( is_array( $json ) && isset( $json['amount_money']['amount'] ) ) {
			return $json['amount_money']['amount'];
		}
		parse_str( $body, $form );
		return isset( $form['amount'] ) ? $form['amount'] : null;
	}
	return null;
};

$t::test(
	'Refund: part of an order',
	function () use ( $t, $orders_after_refunds_offline ) {
		$customer_id = $t::customer();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$t::calculate();
		// 100 + 10 shipping + 11 tax = 121.
		$order = $t::place_order();
		$t::money( 121, $order->get_total(), 'order total before the refund' );
		// Refunds are taken from paid orders, and only paid orders count toward the lifetime total.
		$orders_after_refunds_offline(
			function ( $url ) {
				return '';
			},
			function () use ( $order ) {
				$order->set_status( 'processing' );
			}
		);

		$order->add_refund( 21, 'One print was damaged' );

		$order = sunshine_get_order( $order->get_id() );
		$t::money( 21, $order->get_refund_total(), 'refunded so far' );
		$t::money( 100, $order->get_total_minus_refunds(), 'left after the refund (121 - 21)' );
		$t::money( 100, $order->get_total(), 'order total shown after the refund' );
		$t::money( 121, get_post_meta( $order->get_id(), 'total', true ), 'what was charged is still saved' );
		$t::money( 100, $order->get_subtotal(), 'subtotal does not change' );
		$t::money( 11, $order->get_tax(), 'tax does not change' );
		$t::money( 10, $order->get_shipping(), 'shipping does not change' );
		$t::same( 'processing', $order->get_status(), 'recording a refund on its own does not change the status' );

		// The customer's lifetime total on their admin profile drops by what was refunded.
		$customer = new SPC_Customer( $customer_id );
		$t::money( 100, $customer->get_order_totals(), 'customer lifetime total (121 - 21)' );
	}
);

// Amounts typed into the refund box arrive as text. Several refunds must still add up to the
// cent, and refunding "what is left" must leave exactly nothing.
$t::test(
	'Refund: several refunds add up to the cent',
	function () use ( $t, $orders_after_refunds_paid_order ) {
		$order = $orders_after_refunds_paid_order();
		$order->add_refund( '10.10' );
		$order = sunshine_get_order( $order->get_id() );
		$order->add_refund( '20.00' );
		$order = sunshine_get_order( $order->get_id() );
		$t::money( 30.10, $order->get_refund_total(), 'refunded so far' );
		// 64.32 - 30.10 = 34.22.
		$t::money( 34.22, $order->get_total_minus_refunds(), 'left to refund' );

		$order->add_refund( $order->get_total_minus_refunds() );
		$order = sunshine_get_order( $order->get_id() );
		$t::money( 64.32, $order->get_refund_total(), 'everything refunded' );
		$t::money( 0, $order->get_total_minus_refunds(), 'nothing left' );
		$total_shown = html_entity_decode( wp_strip_all_tags( $order->get_total_formatted() ) );
		$t::same( false, strpos( $total_shown, '-' ), 'order total is not shown as minus zero (shown: ' . $total_shown . ')' );
	}
);

$t::test(
	'Refund in Stripe: full refund sends the order total in cents',
	function () use ( $t, $orders_after_refunds_offline, $orders_after_refunds_reply, $orders_after_refunds_paid_order, $orders_after_refunds_sent_amount ) {
		$stripe = sunshine_get_payment_method_by_id( 'stripe' );
		if ( ! $stripe ) {
			$t::skip( 'Stripe payment method not available' );
		}
		$t::option( 'stripe_mode', 'test' );
		$t::option( 'stripe_secret_key_test', 'sk_test_offline' );
		$order = $orders_after_refunds_paid_order();
		$sent  = $orders_after_refunds_offline(
			$orders_after_refunds_reply( 6432 ),
			function () use ( $order, $stripe ) {
				$order->set_status( 'new' );
				$order->update_meta_value( 'payment_method', 'stripe' );
				$order->update_meta_value( 'stripe_payment_intent_id', 'pi_offline' );
				$stripe->process_refund( $order->get_id() );
			}
		);
		$t::same( 6432, intval( $orders_after_refunds_sent_amount( $sent, 'v1/refunds' ) ), 'cents sent to Stripe' );
		$order = sunshine_get_order( $order->get_id() );
		$t::money( 64.32, $order->get_refund_total(), 'refund recorded on the order' );
		$t::money( 0, $order->get_total_minus_refunds(), 'nothing left to refund' );
		$t::same( 'refunded', $order->get_status(), 'status' );
	}
);

// The second refund asks for more than is left, so only what is left is sent.
$t::test(
	'Refund in Stripe: part, then the rest',
	function () use ( $t, $orders_after_refunds_offline, $orders_after_refunds_reply, $orders_after_refunds_paid_order, $orders_after_refunds_sent_amount, $orders_after_refunds_warnings ) {
		$stripe = sunshine_get_payment_method_by_id( 'stripe' );
		if ( ! $stripe ) {
			$t::skip( 'Stripe payment method not available' );
		}
		$t::option( 'stripe_mode', 'test' );
		$t::option( 'stripe_secret_key_test', 'sk_test_offline' );
		$order    = $orders_after_refunds_paid_order();
		$warnings = array();
		$first    = $orders_after_refunds_offline(
			$orders_after_refunds_reply( 6432 ),
			function () use ( $order, $stripe, $orders_after_refunds_warnings, &$warnings ) {
				$order->set_status( 'new' );
				$order->update_meta_value( 'payment_method', 'stripe' );
				$order->update_meta_value( 'stripe_payment_intent_id', 'pi_offline' );
				// The refund form, with "Full refund" unticked and "Notify customer" ticked.
				$_POST['stripe_refund_amount'] = '10.10';
				$_POST['stripe_refund_notify'] = 'yes';
				$warnings = $orders_after_refunds_warnings(
					function () use ( $order, $stripe ) {
						$stripe->process_refund( $order->get_id() );
					}
				);
			}
		);
		$t::same( 1010, intval( $orders_after_refunds_sent_amount( $first, 'v1/refunds' ) ), 'first refund in cents' );
		$t::same( array(), $warnings, 'no PHP warnings while refunding and emailing the customer' );
		// A part refund must not mark the order Refunded: Reports, the dashboard and the Gift Cards
		// add-on treat a Refunded order as having nothing left.
		$t::same( 'new', sunshine_get_order( $order->get_id() )->get_status(), 'status after a part refund' );

		$second = $orders_after_refunds_offline(
			$orders_after_refunds_reply( 6432 ),
			function () use ( $order, $stripe ) {
				$_POST['stripe_refund_amount'] = '100';
				$stripe->process_refund( $order->get_id() );
			}
		);
		// 64.32 - 10.10 = 54.22 left.
		$t::same( 5422, intval( $orders_after_refunds_sent_amount( $second, 'v1/refunds' ) ), 'second refund is only what is left, in cents' );
		$order = sunshine_get_order( $order->get_id() );
		$t::money( 64.32, $order->get_refund_total(), 'refunds recorded on the order' );
		$t::money( 0, $order->get_total_minus_refunds(), 'nothing left to refund' );
		$t::same( 'refunded', $order->get_status(), 'status once everything is refunded' );
	}
);

// After Square accepts a full refund, the order must record what Square was asked to pay back
// (every cent of the 64.32) and still say it was paid with Square. The cents sent for other
// amounts are covered in core-gateways-square.php.
$t::test(
	'Refund in Square: the order after a full refund',
	function () use ( $t, $orders_after_refunds_offline, $orders_after_refunds_reply, $orders_after_refunds_paid_order, $orders_after_refunds_sent_amount ) {
		$square = sunshine_get_payment_method_by_id( 'square' );
		if ( ! $square ) {
			$t::skip( 'Square payment method not available' );
		}
		$t::option( 'square_mode', 'test' );
		$t::option( 'square_access_token_test', 'offline-token' );
		$order = $orders_after_refunds_paid_order();
		$sent  = $orders_after_refunds_offline(
			$orders_after_refunds_reply( 6432 ),
			function () use ( $order, $square ) {
				$order->set_status( 'new' );
				$order->update_meta_value( 'payment_method', 'square' );
				$order->update_meta_value( 'square_payment_id', 'sq_pay_offline' );
				$square->process_refund( $order->get_id() );
			}
		);
		$cents = intval( $orders_after_refunds_sent_amount( $sent, '/v2/refunds' ) );
		$order = sunshine_get_order( $order->get_id() );
		$t::money( 64.32, $order->get_refund_total(), 'refund recorded on the order' );
		$t::money( $order->get_refund_total(), $cents / 100, 'Square was asked to pay back what the order records' );
		$t::same( 'refunded', $order->get_status(), 'status' );
		// The order was paid in Square; refunding it must not turn it into a free order.
		$t::same( 'square', $order->get_payment_method(), 'payment method is still Square' );
	}
);

// PayPal wants the amount as text with no more than 2 decimals.
$t::test(
	'Refund in PayPal: part, then the rest',
	function () use ( $t, $orders_after_refunds_offline, $orders_after_refunds_reply, $orders_after_refunds_paid_order ) {
		$paypal = sunshine_get_payment_method_by_id( 'paypal' );
		if ( ! $paypal ) {
			$t::skip( 'PayPal payment method not available' );
		}
		$t::option( 'paypal_mode', 'sandbox' );
		// A made-up sign-in token, so no token is asked for or saved.
		$token = function () {
			return 'offline-token';
		};
		add_filter( 'pre_transient_sunshine_paypal_token_test', $token );
		add_filter( 'pre_transient_sunshine_paypal_token_live', $token );
		try {
			$order = $orders_after_refunds_paid_order();
			$sent  = $orders_after_refunds_offline(
				$orders_after_refunds_reply( 6432 ),
				function () use ( $order, $paypal ) {
					$order->set_status( 'new' );
					$order->update_meta_value( 'payment_method', 'paypal' );
					$order->update_meta_value( 'paypal_capture_id', 'cap_offline' );
					$_POST['paypal_refund_amount'] = '10.10';
					$paypal->process_refund( $order->get_id() );
					unset( $_POST['paypal_refund_amount'] );
					$paypal->process_refund( sunshine_get_order( $order->get_id() )->get_id() );
				}
			);
		} finally {
			remove_filter( 'pre_transient_sunshine_paypal_token_test', $token );
			remove_filter( 'pre_transient_sunshine_paypal_token_live', $token );
		}
		$amounts = array();
		foreach ( $sent as $request ) {
			if ( false !== strpos( $request['url'], '/refund' ) && preg_match( '/"value":\s*"?([^",}]+)/', (string) $request['body'], $match ) ) {
				$amounts[] = $match[1];
			}
		}
		$t::same( 2, count( $amounts ), 'two refunds sent' );
		$t::same( '10.10', isset( $amounts[0] ) ? $amounts[0] : null, 'first refund sent as' );
		// 64.32 - 10.10 = 54.22.
		$t::same( '54.22', isset( $amounts[1] ) ? $amounts[1] : null, 'second refund sent as' );
		$order = sunshine_get_order( $order->get_id() );
		$t::money( 64.32, $order->get_refund_total(), 'refunds recorded on the order' );
		$t::same( 'refunded', $order->get_status(), 'status' );
	}
);
