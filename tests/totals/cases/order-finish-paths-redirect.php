<?php
/**
 * Finishing an order when the payment service sends the customer away to pay. The checkout's
 * Place Order step ends with the redirect, so the order is finished later by the service's own
 * step: a server notice (webhook, IPN, ITN) or the customer coming back to the site. Either way,
 * once the order is paid everything must happen that happens for an order finished at checkout
 * (see order-finish-paths-paid.php): gift card balances taken off, gift cards bought made, the session
 * date booked, included prints used up, Sell Anything stock lowered, the discount code counted
 * and store credit taken off.
 *
 * Every service's reply is made up here. Nothing reaches any payment service. Helpers are in
 * order-finish-paths-helpers.php.
 */

$t = 'SPC_Totals_Test';

// Run $callback with php://input reading $body, the way a service's notice arrives.
$order_finish_with_input = function ( $body, $callback ) {
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
	$input_class::$body = $body;
	stream_wrapper_unregister( 'php' );
	stream_wrapper_register( 'php', $input_class );
	try {
		return $callback();
	} finally {
		stream_wrapper_restore( 'php' );
	}
};

/*
 * Mollie: the webhook (a server notice) asks Mollie for the payment and finishes the order when
 * it is paid.
 */
$order_finish_test(
	'Mollie: paid webhook finishes everything',
	function () use ( $t, $order_finish_use, $order_finish_everything_cart, $order_finish_place_everything, $order_finish_checkout_leaves, $order_finish_mollie, $order_finish_as_server, $order_finish_check_everything ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		if ( ! class_exists( 'SPC_Payment_Method_Mollie' ) ) {
			$t::skip( 'Mollie add-on is not active' );
		}
		$ids = $order_finish_everything_cart();
		$order_finish_use( 'mollie' );
		$order_finish_place_everything( 'mollie' );
		$order = $order_finish_checkout_leaves( 'mollie' );

		list( $mollie, $connection ) = $order_finish_mollie(
			function ( $method, $url ) use ( $order ) {
				if ( 'GET' === $method && preg_match( '#/v2/payments/tr_spcfinish$#', $url ) ) {
					return array(
						'resource' => 'payment',
						'id'       => 'tr_spcfinish',
						'mode'     => 'test',
						'status'   => 'paid',
						'paidAt'   => gmdate( 'c' ),
						'amount'   => array(
							'value'    => number_format( $order->get_total(), 2, '.', '' ),
							'currency' => 'USD',
						),
						'metadata' => array( 'order_id' => $order->get_id() ),
						'_links'   => array(
							'self' => array(
								'href' => 'https://api.mollie.com/v2/payments/tr_spcfinish',
								'type' => 'application/hal+json',
							),
						),
					);
				}
				throw new \Mollie\Api\Exceptions\ApiException( 'Blocked by the totals tests' );
			}
		);
		$order_finish_as_server(
			function () use ( $mollie ) {
				$_GET['sunshine_mollie_webhook'] = 1;
				$_POST['id']                     = 'tr_spcfinish';
				$mollie->webhook();
			}
		);
		$t::same( 1, count( $connection->sent ), 'webhook asked Mollie for the payment' );
		$order_finish_check_everything( $order->get_id(), $ids, 'Mollie webhook' );
	}
);

/*
 * PayFast: PayFast posts an ITN (a server notice) signed with the store's passphrase, and the
 * customer also comes back to a success page. Either one finishes the order.
 */

$order_finish_payfast_use = function () use ( $t, $order_finish_use ) {
	$t::require_plugin( 'sunshine_payment_method_payfast', 'PayFast add-on' );
	$order_finish_use( 'payfast' );
	$t::option( 'payfast_merchant_id_test', '10000100' );
	$t::option( 'payfast_merchant_key_test', '46f0cd694581a' );
	$t::option( 'payfast_passphrase_test', 'sunshine totals' );
};

$order_finish_test(
	'PayFast: ITN finishes everything',
	function () use ( $t, $order_finish_payfast_use, $order_finish_everything_cart, $order_finish_place_everything, $order_finish_checkout_leaves, $order_finish_as_server, $order_finish_until_meta, $order_finish_check_everything ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$ids = $order_finish_everything_cart();
		$order_finish_payfast_use();
		$order_finish_place_everything( 'payfast' );
		$order = $order_finish_checkout_leaves( 'payfast' );

		// The ITN fields PayFast posts, in its order, signed the way PayFast signs them.
		$itn  = array(
			'm_payment_id'   => (string) $order->get_id(),
			'pf_payment_id'  => '1089250',
			'payment_status' => 'COMPLETE',
			'item_name'      => $order->get_name(),
			'amount_gross'   => '147.00',
			'amount_fee'     => '-3.38',
			'amount_net'     => '143.62',
			'merchant_id'    => '10000100',
		);
		$sign = array();
		foreach ( $itn as $key => $value ) {
			$sign[] = $key . '=' . urlencode( trim( $value ) );
		}
		$itn['signature'] = md5( implode( '&', $sign ) . '&passphrase=' . urlencode( 'sunshine totals' ) );

		// The ITN step ends with exit whenever it stops early, which would end the whole run. Make
		// sure it gets as far as finishing the order before calling it.
		$payfast = sunshine_get_payment_method_by_id( 'payfast' );
		$check   = new ReflectionMethod( $payfast, 'verify_itn_signature' );
		$check->setAccessible( true );
		$t::same( true, $check->invoke( $payfast, $itn ), 'ITN signature is accepted' );
		if ( ! $check->invoke( $payfast, $itn ) || 'pending' !== sunshine_get_order( $order->get_id() )->get_status() ) {
			return;
		}

		$reached = $order_finish_as_server(
			function () use ( $payfast, $itn, $order, $order_finish_until_meta ) {
				$_GET['sunshine_payfast_notify'] = 1;
				foreach ( $itn as $key => $value ) {
					$_POST[ $key ] = $value;
				}
				return $order_finish_until_meta(
					$order->get_id(),
					'payfast_fees',
					'PayFast: ITN finishes everything',
					function () use ( $payfast ) {
						$payfast->notify();
					}
				);
			}
		);
		$t::same( true, $reached, 'ITN finished the order' );
		$order_finish_check_everything( $order->get_id(), $ids, 'PayFast ITN' );
	}
);

$order_finish_test(
	'PayFast: customer coming back to the success page finishes everything',
	function () use ( $t, $order_finish_payfast_use, $order_finish_everything_cart, $order_finish_place_everything, $order_finish_checkout_leaves, $order_finish_until_redirect, $order_finish_check_everything ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$ids = $order_finish_everything_cart();
		$order_finish_payfast_use();
		$order_finish_place_everything( 'payfast' );
		$order   = $order_finish_checkout_leaves( 'payfast' );
		$payfast = sunshine_get_payment_method_by_id( 'payfast' );

		// The return link PayFast sends the customer back to, in the customer's own browser.
		$redirect = $order_finish_until_redirect(
			function () use ( $payfast, $order ) {
				$_GET['payfast_success'] = wp_create_nonce( 'payfast_success_' . $order->get_id() );
				$_GET['order_id']        = (string) $order->get_id();
				$payfast->success();
			}
		);
		$t::same( $order->get_received_permalink(), $redirect, 'customer is sent to the order page' );
		$order_finish_check_everything( $order->get_id(), $ids, 'PayFast success page' );
	}
);

/*
 * Paystack: the customer comes back to the site, which asks Paystack whether the payment went
 * through and then finishes the order. Paystack has no webhook in Sunshine.
 */
$order_finish_test(
	'Paystack: customer coming back after paying finishes everything',
	function () use ( $t, $order_finish_use, $order_finish_everything_cart, $order_finish_place_everything, $order_finish_checkout_leaves, $order_finish_until_redirect, $order_finish_check_everything, &$order_finish_replies ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::require_plugin( 'sunshine_payment_method_paystack', 'Paystack add-on' );
		$ids = $order_finish_everything_cart();
		$order_finish_use( 'paystack' );
		$order_finish_place_everything( 'paystack' );
		$order = $order_finish_checkout_leaves( 'paystack' );

		$order_finish_replies[] = array(
			'#^https://api\.paystack\.co/transaction/verify/spcfinishref$#',
			200,
			array(
				'status' => true,
				'data'   => array(
					'id'       => 4099260516,
					'status'   => 'success',
					'amount'   => 14700,
					'currency' => 'USD',
					'fees'     => 230,
				),
			),
		);
		$paystack = sunshine_get_payment_method_by_id( 'paystack' );
		$redirect = $order_finish_until_redirect(
			function () use ( $paystack, $order ) {
				$_GET['paystack_success'] = wp_create_nonce( 'paystack_success_' . $order->get_id() );
				$_GET['order_id']         = (string) $order->get_id();
				$_REQUEST['trxref']       = 'spcfinishref';
				$paystack->success();
			}
		);
		$t::same( $order->get_received_permalink(), $redirect, 'customer is sent to the order page' );
		$order_finish_check_everything( $order->get_id(), $ids, 'Paystack return' );
	}
);

/*
 * QuickPay: the customer comes back to the site, which asks QuickPay whether the payment was
 * accepted and then finishes the order.
 */
$order_finish_test(
	'QuickPay: customer coming back after paying finishes everything',
	function () use ( $t, $order_finish_use, $order_finish_everything_cart, $order_finish_place_everything, $order_finish_checkout_leaves, $order_finish_until_redirect, $order_finish_check_everything, &$order_finish_replies ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::require_plugin( 'sunshine_payment_method_quickpay', 'QuickPay add-on' );
		$ids = $order_finish_everything_cart();
		$order_finish_use( 'quickpay' );
		$order_finish_place_everything( 'quickpay' );
		$order = $order_finish_checkout_leaves( 'quickpay' );
		// Saved by QuickPay's own step before it sent the customer away.
		$order->update_meta_value( 'quickpay_payment_id', '90210' );

		$order_finish_replies[] = array(
			'#^https://api\.quickpay\.net/payments/90210$#',
			200,
			array(
				'id'        => 90210,
				'accepted'  => true,
				'test_mode' => true,
				'currency'  => 'USD',
			),
		);
		$quickpay = sunshine_get_payment_method_by_id( 'quickpay' );
		$redirect = $order_finish_until_redirect(
			function () use ( $quickpay, $order ) {
				$_GET['quickpay_success'] = wp_create_nonce( 'quickpay_success_' . $order->get_id() );
				$_GET['order_id']         = (string) $order->get_id();
				$quickpay->success();
			}
		);
		$t::same( $order->get_received_permalink(), $redirect, 'customer is sent to the order page' );
		$order_finish_check_everything( $order->get_id(), $ids, 'QuickPay return' );
	}
);

/*
 * Systempay: Systempay calls the site's IPN address (a server notice) once the payment is done.
 */
$order_finish_test(
	'Systempay: IPN finishes everything',
	function () use ( $t, $order_finish_use, $order_finish_everything_cart, $order_finish_place_everything, $order_finish_checkout_leaves, $order_finish_as_server, $order_finish_until_meta, $order_finish_check_everything ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		if ( ! class_exists( 'SPC_Payment_Method_SystemPay' ) ) {
			$t::skip( 'Systempay add-on is not active' );
		}
		$ids = $order_finish_everything_cart();
		$order_finish_use( 'systempay' );
		$order_finish_place_everything( 'systempay' );
		$order = $order_finish_checkout_leaves( 'systempay' );

		// Systempay's own step builds the payment form, which saves the transaction ID on the order.
		$systempay = sunshine_get_payment_method_by_id( 'systempay' );
		$build     = new ReflectionMethod( $systempay, 'get_payment_args' );
		$build->setAccessible( true );
		$fields   = $build->invoke( $systempay, $order );
		$trans_id = (string) get_post_meta( $order->get_id(), 'vads_trans_id', true );
		$t::same( true, '' !== $trans_id && $trans_id === (string) $fields['vads_trans_id'], 'transaction ID saved on the order' );
		// The IPN step ends with exit whenever the transaction ID doesn't match, which would end
		// the whole run.
		if ( '' === $trans_id || $trans_id !== (string) $fields['vads_trans_id'] ) {
			return;
		}

		$reached = $order_finish_as_server(
			function () use ( $systempay, $order, $trans_id, $order_finish_until_meta ) {
				$_GET['sunshine_systempay_ipn'] = 1;
				$_POST['vads_order_id']         = (string) $order->get_id();
				$_POST['vads_trans_id']         = $trans_id;
				$_POST['vads_trans_status']     = 'AUTHORISED';
				$_POST['vads_amount']           = '14700';
				return $order_finish_until_meta(
					$order->get_id(),
					'post_processed',
					'Systempay: IPN finishes everything',
					function () use ( $systempay ) {
						$systempay->ipn();
					}
				);
			}
		);
		$t::same( true, $reached, 'IPN finished the order' );
		$order_finish_check_everything( $order->get_id(), $ids, 'Systempay IPN' );
	}
);

/*
 * Mercado Pago (Checkout Pro): Mercado Pago calls the site's webhook (a server notice), which
 * asks Mercado Pago for the payment and finishes the order once it is approved.
 */
$order_finish_test(
	'Mercado Pago Pro: approved payment webhook finishes everything',
	function () use ( $t, $order_finish_use, $order_finish_everything_cart, $order_finish_place_everything, $order_finish_checkout_leaves, $order_finish_as_server, $order_finish_until_meta, $order_finish_check_everything, &$order_finish_replies ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$t::require_plugin( 'sunshine_payment_method_mercadopago', 'Mercado Pago add-on' );
		$ids = $order_finish_everything_cart();
		$order_finish_use( 'mercadopagopro' );
		$t::option( 'mercadopagopro_access_token_test', 'TEST-spctotals' );
		$order_finish_place_everything( 'mercadopagopro' );
		$order = $order_finish_checkout_leaves( 'mercadopagopro' );

		// The webhook step ends with exit when the payment is not approved, which would end the
		// whole run, so the reply is always an approved payment for this order.
		$order_finish_replies[] = array(
			'#^https://api\.mercadopago\.com/v1/payments/5550001$#',
			200,
			array(
				'id'                 => 5550001,
				'status'             => 'approved',
				'external_reference' => (string) $order->get_id(),
				'transaction_amount' => 147,
				'currency_id'        => 'USD',
				'fee_details'        => array(
					array(
						'type'   => 'mercadopago_fee',
						'amount' => 7.35,
					),
				),
			),
		);
		$gateway = sunshine_get_payment_method_by_id( 'mercadopagopro' );
		$reached = $order_finish_as_server(
			function () use ( $gateway, $order, $order_finish_until_meta ) {
				$_GET['sunshine_mercadopagopro_webhook'] = '';
				$_GET['type']                            = 'payment';
				$_GET['data_id']                         = '5550001';
				return $order_finish_until_meta(
					$order->get_id(),
					'post_processed',
					'Mercado Pago Pro: approved payment webhook finishes everything',
					function () use ( $gateway ) {
						$gateway->webhooks();
					}
				);
			}
		);
		$t::same( true, $reached, 'webhook finished the order' );
		$order_finish_check_everything( $order->get_id(), $ids, 'Mercado Pago Pro webhook' );
	}
);

/*
 * PayPal Legacy (PayPal Standard): PayPal posts an IPN (a server notice). The site sends it back
 * to PayPal to check it is real, checks who was paid and how much, then finishes the order.
 */
$order_finish_test(
	'PayPal Legacy: IPN finishes everything',
	function () use ( $t, $order_finish_use, $order_finish_everything_cart, $order_finish_place_everything, $order_finish_checkout_leaves, $order_finish_as_server, $order_finish_until_meta, $order_finish_with_input, $order_finish_check_everything, &$order_finish_replies ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$ids = $order_finish_everything_cart();
		$order_finish_use( 'paypal-legacy' );
		$t::option( 'paypal-legacy_email_test', 'seller@example.com' );
		$order_finish_place_everything( 'paypal-legacy' );
		$order = $order_finish_checkout_leaves( 'paypal-legacy' );

		$ipn = array(
			'txn_type'       => 'cart',
			'payment_status' => 'Completed',
			'receiver_email' => 'seller@example.com',
			'mc_gross'       => '147.00',
			'mc_currency'    => (string) SPC()->get_option( 'currency' ),
			'custom'         => (string) $order->get_id(),
			'txn_id'         => '61E67681CH3238416',
		);
		// PayPal confirms the IPN is real.
		$order_finish_replies[] = array( '#^https://www\.sandbox\.paypal\.com/cgi-bin/webscr$#', 200, 'VERIFIED' );

		// The IPN step ends with exit whenever a check fails, which would end the whole run. These
		// are its checks: who was paid, the currency and the amount.
		$fits = 'USD' === $ipn['mc_currency'] && 147.0 === round( (float) sunshine_get_order( $order->get_id() )->get_total(), 2 );
		$t::same( true, $fits, 'IPN matches the order' );
		if ( ! $fits ) {
			return;
		}

		$reached = $order_finish_as_server(
			function () use ( $ipn, $order, $order_finish_until_meta, $order_finish_with_input ) {
				$_GET['sunshine_paypal_ipn'] = 'paypal_standard_ipn';
				foreach ( $ipn as $key => $value ) {
					$_POST[ $key ] = $value;
				}
				$paypal = sunshine_get_payment_method_by_id( 'paypal-legacy' );
				return $order_finish_with_input(
					http_build_query( $ipn ),
					function () use ( $paypal, $order, $order_finish_until_meta ) {
						return $order_finish_until_meta(
							$order->get_id(),
							'post_processed',
							'PayPal Legacy: IPN finishes everything',
							function () use ( $paypal ) {
								$paypal->ipn();
							}
						);
					}
				);
			}
		);
		$t::same( true, $reached, 'IPN finished the order' );
		$order_finish_check_everything( $order->get_id(), $ids, 'PayPal Legacy IPN' );
	}
);

/*
 * Stripe hosted checkout: the customer pays on Stripe's page and Stripe sends a
 * checkout.session.completed webhook (a server notice), which finishes the order.
 */
$order_finish_stripe_use = function ( $mode ) use ( $t, $order_finish_stripe_forget ) {
	if ( ! sunshine_get_payment_method_by_id( 'stripe' ) || ! has_action( 'sunshine_checkout_process_payment_stripe' ) ) {
		$t::skip( 'Stripe is not set up on this site' );
	}
	$order_finish_stripe_forget();
	$t::option( 'stripe_fee', 'none' );
	$t::option( 'stripe_fee_amount', '' );
	$t::option( 'stripe_checkout_mode', $mode );
	$t::option( 'stripe_optimized_checkout', 'yes' );
	$t::option( 'stripe_tax_mode', 'sunshine' );
	$t::option( 'stripe_webhook_secret_test', 'whsec_spcfinishtest' );
	SPC()->cart->set_checkout_data_item( 'payment_method', 'stripe' );
};

$order_finish_test(
	'Stripe hosted: checkout.session.completed webhook finishes everything',
	function () use ( $t, $order_finish_stripe_use, $order_finish_stripe_forget, $order_finish_everything_cart, $order_finish_place_everything, $order_finish_checkout_leaves, $order_finish_as_server, $order_finish_stripe_webhook, $order_finish_check_everything, &$order_finish_replies ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$ids = $order_finish_everything_cart();
		$order_finish_stripe_use( 'hosted' );
		$order_finish_place_everything( 'stripe' );
		$order = $order_finish_checkout_leaves( 'stripe' );

		// Stripe's own step makes a Checkout Session for the order before sending the customer to
		// Stripe. The session is made here with Stripe's replies made up: 147.00, no Stripe tax.
		$order_finish_replies[] = array( '#^https://api\.stripe\.com/v1/checkout/sessions$#', 200, array(
			'id'            => 'cs_test_spcfinish',
			'url'           => 'https://checkout.stripe.com/c/pay/cs_test_spcfinish',
			'currency'      => 'usd',
			'amount_total'  => 14700,
			'total_details' => array( 'amount_tax' => 0 ),
		) );
		$order_finish_replies[] = array( '#^https://api\.stripe\.com/v1/coupons/#', 404, array( 'error' => array( 'code' => 'resource_missing', 'message' => 'No such coupon' ) ) );
		$order_finish_replies[] = array( '#^https://api\.stripe\.com/v1/coupons$#', 200, array( 'id' => 'sunshine_order_' . $order->get_id() ) );
		$order_finish_replies[] = array( '#^https://api\.stripe\.com/v1/customers#', 200, array( 'object' => 'list', 'data' => array(), 'id' => 'cus_spcfinish' ) );
		$stripe  = sunshine_get_payment_method_by_id( 'stripe' );
		$create  = new ReflectionMethod( $stripe, 'create_checkout_session' );
		$create->setAccessible( true );
		$session = $create->invoke( $stripe, $order );
		$t::same( true, is_array( $session ) && ! empty( $session['id'] ), 'checkout session made' );

		$status = $order_finish_as_server(
			function () use ( $order, $order_finish_stripe_webhook ) {
				return $order_finish_stripe_webhook(
					array(
						'id'   => 'evt_spcfinish',
						'type' => 'checkout.session.completed',
						'data' => array(
							'object' => array(
								'id'             => 'cs_test_spcfinish',
								'object'         => 'checkout.session',
								'payment_status' => 'paid',
								'currency'       => 'usd',
								'amount_total'   => 14700,
								'total_details'  => array( 'amount_tax' => 0 ),
								'payment_intent' => 'pi_spcfinish_hosted',
								'customer'       => 'cus_spcfinish',
								'metadata'       => array( 'order_id' => (string) $order->get_id() ),
							),
						),
					)
				);
			}
		);
		$t::same( 200, $status, 'webhook accepted' );
		$order_finish_check_everything( $order->get_id(), $ids, 'Stripe hosted webhook' );
		$order_finish_stripe_forget();
	}
);

/*
 * Stripe's inline card form takes the payment in the browser, then the browser submits the
 * checkout, which finishes the order through process_order(). Stripe also sends
 * payment_intent.succeeded, which is only a backup and must not do anything a second time.
 */

// What Stripe says about the inline payment once the customer paid 147.00.
$order_finish_stripe_paid_intent = function ( $order_id ) {
	return array(
		'id'              => 'pi_spcfinish_inline',
		'object'          => 'payment_intent',
		'status'          => 'succeeded',
		'amount'          => 14700,
		'amount_received' => 14700,
		'currency'        => 'usd',
		'customer'        => 'cus_spcfinish',
		'metadata'        => array( 'order_id' => (string) $order_id ),
	);
};

// The customer's browser submits the checkout after Stripe's form took the payment.
$order_finish_stripe_browser = function ( $order ) use ( $order_finish_stripe_paid_intent, &$order_finish_replies ) {
	$order_finish_replies[] = array( '#^https://api\.stripe\.com/v1/payment_intents/pi_spcfinish_inline#', 200, $order_finish_stripe_paid_intent( $order->get_id() ) );
	SPC()->session->set( 'checkout_order_id', $order->get_id() );
	$_POST['stripe_payment_intent_id'] = 'pi_spcfinish_inline';
	return SPC()->cart->process_order();
};

$order_finish_stripe_place_inline = function () use ( $order_finish_stripe_use, $order_finish_place_everything ) {
	$order_finish_stripe_use( 'inline' );
	// The payment the checkout page made with Stripe, kept in the session; placing the order saves
	// it on the order.
	SPC()->session->set( 'stripe_payment_intent_id', 'pi_spcfinish_inline' );
	$order = $order_finish_place_everything( 'stripe' );
	if ( 'pi_spcfinish_inline' !== $order->get_meta_value( 'stripe_payment_intent_id' ) ) {
		$order->update_meta_value( 'stripe_payment_intent_id', 'pi_spcfinish_inline' );
	}
	return $order;
};

$order_finish_stripe_inline_webhook = function ( $order ) use ( $order_finish_as_server, $order_finish_stripe_webhook, $order_finish_stripe_paid_intent ) {
	return $order_finish_as_server(
		function () use ( $order, $order_finish_stripe_webhook, $order_finish_stripe_paid_intent ) {
			return $order_finish_stripe_webhook(
				array(
					'id'   => 'evt_spcfinish_inline',
					'type' => 'payment_intent.succeeded',
					'data' => array( 'object' => $order_finish_stripe_paid_intent( $order->get_id() ) ),
				)
			);
		}
	);
};

$order_finish_test(
	'Stripe inline: browser finishes everything, the webhook after it changes nothing',
	function () use ( $t, $order_finish_stripe_forget, $order_finish_everything_cart, $order_finish_stripe_place_inline, $order_finish_stripe_browser, $order_finish_stripe_inline_webhook, $order_finish_check_everything ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$ids   = $order_finish_everything_cart();
		$order = $order_finish_stripe_place_inline();
		$done  = $order_finish_stripe_browser( $order );
		$t::same( true, ! empty( $done ), 'checkout finished the order: ' . wp_json_encode( SPC()->cart->get_errors() ) );
		$t::same( 200, $order_finish_stripe_inline_webhook( $order ), 'webhook accepted' );
		$order_finish_check_everything( $order->get_id(), $ids, 'Stripe inline, browser first' );
		$order_finish_stripe_forget();
	}
);

/*
 * The webhook can also arrive before the browser (it often does for bank redirects and wallets,
 * and it is the only thing that comes when the customer closes the tab after paying). The order
 * must still get everything.
 */
$order_finish_test(
	'Stripe inline: webhook arriving before the browser still finishes everything',
	function () use ( $t, $order_finish_stripe_forget, $order_finish_everything_cart, $order_finish_stripe_place_inline, $order_finish_stripe_browser, $order_finish_stripe_inline_webhook, $order_finish_check_everything ) {
		$t::require_plugin( 'sunshine_gift_cards_deduct_balance', 'Gift Cards add-on' );
		$ids   = $order_finish_everything_cart();
		$order = $order_finish_stripe_place_inline();
		$t::same( 200, $order_finish_stripe_inline_webhook( $order ), 'webhook accepted' );
		// Now the customer's browser submits the checkout with the same payment.
		$order_finish_stripe_browser( $order );
		$order_finish_check_everything( $order->get_id(), $ids, 'Stripe inline, webhook first' );
		$order_finish_stripe_forget();
	}
);
