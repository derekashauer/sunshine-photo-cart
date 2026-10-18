<?php
/**
 * Cancel links. When the customer is sent to a payment page (PayFast, QuickPay, Paystack,
 * PayPal Legacy), that page has a "cancel" link back to the store. Opening it gives up on the
 * payment: QuickPay, Paystack and PayPal Legacy delete the waiting order, PayFast marks it
 * Failed.
 *
 * The rule tested here: giving up on an unpaid payment is fine, but a cancel link must never
 * throw away (or block) an order the customer really paid for. A customer can pay in one tab and
 * press cancel in another, or press cancel and then go back and pay, and the payment's own
 * confirmation can arrive after the cancel.
 *
 * Helpers are in payment-results-helpers.php. Nothing reaches any payment service.
 */

$t = 'SPC_Totals_Test';

// The checkout errors shown to the customer, as one line of text.
$payment_results_links_errors = function () {
	return wp_strip_all_tags( implode( ' | ', (array) SPC()->cart->get_errors() ) );
};

// The order lines saved for an order (rows in the order items table).
$payment_results_links_line_rows = function ( $order_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sunshine_order_items WHERE order_id = %d", $order_id ) );
};

/*
 * PayFast: the cancel link marks the order Failed.
 */

$t::test(
	'PayFast: cancelling on PayFast\'s page leaves the order unpaid and tells the customer',
	function () use ( $t, $payment_results_payfast_settings, $payment_results_place, $payment_results_payfast_form, $payment_results_visit, $payment_results_fresh, $payment_results_finished, $payment_results_exists, $payment_results_links_errors ) {
		$payment_results_payfast_settings();
		$t::tax( 15 );
		$t::add( $t::product( 200 ) );
		// 200 + 15% VAT = 230 rand.
		$order  = $payment_results_place( 'payfast', 230 );
		$fields = $payment_results_payfast_form( $order );
		$t::money( 230, $fields['amount'], 'PayFast is asked for the order total' );
		$payfast = sunshine_get_payment_method_by_id( 'payfast' );
		$payment_results_visit(
			$fields['cancel_url'],
			function () use ( $payfast ) {
				$payfast->cancel();
			},
			null,
			'PayFast cancel link'
		);
		$t::same( true, $payment_results_exists( $order->get_id() ), 'order is kept' );
		$saved = $payment_results_fresh( $order->get_id() );
		$t::same( false, $payment_results_finished( $saved ), 'order not finished' );
		$t::same( true, false !== stripos( $payment_results_links_errors(), 'cancelled' ), 'the customer is told the payment was cancelled' );
		$t::money( 230, $saved->get_total(), 'order total unchanged' );
	}
);

// The customer pressed cancel, then went back to the PayFast page (still open in the browser)
// and paid after all. PayFast's signed ITN for the full 230 arrives after the cancel. PayFast
// has the money, so the order must be paid.
$t::test(
	'PayFast: a payment PayFast confirms after the cancel link completes the order',
	function () use ( $t, $payment_results_payfast_settings, $payment_results_place, $payment_results_payfast_form, $payment_results_visit, $payment_results_payfast_itn, $payment_results_payfast_notify, $payment_results_fresh ) {
		$options = $payment_results_payfast_settings();
		$t::tax( 15 );
		$t::add( $t::product( 200 ) );
		$order   = $payment_results_place( 'payfast', 230 );
		$fields  = $payment_results_payfast_form( $order );
		$payfast = sunshine_get_payment_method_by_id( 'payfast' );
		$payment_results_visit(
			$fields['cancel_url'],
			function () use ( $payfast ) {
				$payfast->cancel();
			},
			null,
			'PayFast cancel link'
		);
		$payment_results_payfast_notify( $order, $options, $payment_results_payfast_itn( $order, 230 ) );
		$saved = $payment_results_fresh( $order->get_id() );
		$t::same( true, $saved->is_paid(), 'order is paid' );
		$t::same( '1089250', (string) $saved->get_meta_value( 'payfast_payment_id' ), 'PayFast payment saved on the order' );
	}
);

// The customer pressed cancel, landed back on the checkout, and submitted the order again. The
// same order is reused and sent to PayFast again for 230. They pay, and PayFast's ITN for 230
// arrives. The order must be waiting for payment again after the new submit, and paid after
// the ITN.
$t::test(
	'PayFast: cancel, submit again and pay: the order is paid',
	function () use ( $t, $payment_results_payfast_settings, $payment_results_place, $payment_results_payfast_form, $payment_results_visit, $payment_results_payfast_itn, $payment_results_payfast_notify, $payment_results_fresh ) {
		$options = $payment_results_payfast_settings();
		$t::tax( 15 );
		$t::add( $t::product( 200 ) );
		$order   = $payment_results_place( 'payfast', 230 );
		$fields  = $payment_results_payfast_form( $order );
		$payfast = sunshine_get_payment_method_by_id( 'payfast' );
		$payment_results_visit(
			$fields['cancel_url'],
			function () use ( $payfast ) {
				$payfast->cancel();
			},
			null,
			'PayFast cancel link'
		);

		$t::new_request();
		$again = $payment_results_place( 'payfast', 230, 'second try: ' );
		$t::same( $order->get_id(), $again->get_id(), 'the same order is used for the second try' );
		$t::same( 'pending', $payment_results_fresh( $order->get_id() )->get_status(), 'order waits for payment again' );
		$fields = $payment_results_payfast_form( $again );
		$t::money( 230, $fields['amount'], 'PayFast is asked for 230 again' );

		$payment_results_payfast_notify( $order, $options, $payment_results_payfast_itn( $order, 230 ) );
		$t::same( true, $payment_results_fresh( $order->get_id() )->is_paid(), 'order is paid after the second try' );
	}
);

// The order was paid (PayFast's ITN for the full 230 finished it). Then the cancel link is
// opened from the browser history. The paid order must stay paid.
$t::test(
	'PayFast: the cancel link leaves an order that is already paid alone',
	function () use ( $t, $payment_results_payfast_settings, $payment_results_place, $payment_results_payfast_form, $payment_results_payfast_itn, $payment_results_payfast_notify, $payment_results_visit, $payment_results_fresh ) {
		$options = $payment_results_payfast_settings();
		$t::tax( 15 );
		$t::add( $t::product( 200 ) );
		$order  = $payment_results_place( 'payfast', 230 );
		$fields = $payment_results_payfast_form( $order );
		$payment_results_payfast_notify( $order, $options, $payment_results_payfast_itn( $order, 230 ) );
		$t::same( true, $payment_results_fresh( $order->get_id() )->is_paid(), 'order is paid by the ITN' );
		$payfast = sunshine_get_payment_method_by_id( 'payfast' );
		$payment_results_visit(
			$fields['cancel_url'],
			function () use ( $payfast ) {
				$payfast->cancel();
			},
			null,
			'PayFast cancel link'
		);
		$saved = $payment_results_fresh( $order->get_id() );
		$t::same( true, $saved->is_paid(), 'order is still paid after the cancel link' );
		$t::money( 230, $saved->get_total(), 'order total unchanged' );
	}
);

/*
 * QuickPay: the cancel link deletes the order when it is still waiting for payment. The order
 * keeps the QuickPay payment ID, so QuickPay can be asked whether it was paid.
 */

// Send the customer to QuickPay. Returns the continue and cancel links QuickPay was given.
$payment_results_quickpay_start = function ( $order ) use ( $payment_results_run, $payment_results_find, $payment_results_wire ) {
	$quickpay = sunshine_get_payment_method_by_id( 'quickpay' );
	$run      = $payment_results_run(
		function () use ( $quickpay, $order ) {
			$quickpay->redirect_to_payment( $order );
		},
		function ( $request ) {
			if ( '/payments' === $request['path'] && 'POST' === $request['method'] ) {
				return array( 201, array( 'id' => 90210 ) );
			}
			if ( '/payments/90210/link' === $request['path'] ) {
				return array( 200, array( 'url' => 'https://payment.quickpay.net/spctotals' ) );
			}
			return null;
		},
		'QuickPay start'
	);
	$link = $payment_results_wire( $payment_results_find( $run['sent'], '#^/payments/90210/link$#' ) );
	return array(
		'amount'   => isset( $link['amount'] ) ? $link['amount'] : null,
		'continue' => isset( $link['continue_url'] ) ? $link['continue_url'] : '',
		'cancel'   => isset( $link['cancel_url'] ) ? $link['cancel_url'] : '',
	);
};

// QuickPay's answer when asked about payment 90210 for the order: accepted (paid) for $amount
// in the smallest unit, or not accepted.
$payment_results_quickpay_lookup = function ( $order, $amount, $accepted ) {
	$payment = array(
		'id'         => 90210,
		'order_id'   => (string) $order->get_id(),
		'accepted'   => $accepted,
		'test_mode'  => true,
		'currency'   => 'USD',
		'state'      => $accepted ? 'new' : 'initial',
		'operations' => $accepted ? array(
			array(
				'id'             => 1,
				'type'           => 'authorize',
				'amount'         => $amount,
				'pending'        => false,
				'qp_status_code' => '20000',
				'aq_status_code' => '20000',
			),
		) : array(),
		'link'       => array( 'amount' => $amount ),
	);
	return function ( $request ) use ( $payment ) {
		return '/payments/90210' === $request['path'] && 'GET' === $request['method'] ? array( 200, $payment ) : null;
	};
};

$payment_results_quickpay_use = function () use ( $t, $payment_results_settings ) {
	$t::require_plugin( 'sunshine_payment_method_quickpay', 'QuickPay add-on' );
	$payment_results_settings(
		array(
			'quickpay_active'      => 1,
			'quickpay_mode'        => 'test',
			'quickpay_merchant_id' => '12345',
			'quickpay_api_key'     => 'spctotals-api-key',
			'quickpay_private_key' => 'spctotals-private-key',
			'quickpay_fee'         => 'none',
		)
	);
};

// Giving up before paying: the waiting order goes away, and checking out again makes a fresh
// order for the same total. Deleting an order should also remove its saved lines.
$t::test(
	'QuickPay: cancelling before paying removes the unpaid order, and checking out again costs the same',
	function () use ( $t, $payment_results_quickpay_use, $payment_results_place, $payment_results_quickpay_start, $payment_results_quickpay_lookup, $payment_results_visit, $payment_results_exists, $payment_results_links_errors, $payment_results_links_line_rows ) {
		$payment_results_quickpay_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $payment_results_place( 'quickpay', 110 );
		$links = $payment_results_quickpay_start( $order );
		$t::same( 11000, (int) $links['amount'], 'QuickPay is asked for 110.00 (in cents)' );
		$quickpay = sunshine_get_payment_method_by_id( 'quickpay' );
		$payment_results_visit(
			$links['cancel'],
			function () use ( $quickpay ) {
				$quickpay->cancel();
			},
			$payment_results_quickpay_lookup( $order, 11000, false ),
			'QuickPay cancel link'
		);
		$t::same( false, $payment_results_exists( $order->get_id() ), 'the unpaid order is removed' );
		$t::same( 0, $payment_results_links_line_rows( $order->get_id() ), 'its order lines are removed too' );
		$t::same( true, false !== stripos( $payment_results_links_errors(), 'cancelled' ), 'the customer is told the payment was cancelled' );

		$t::new_request();
		$again = $payment_results_place( 'quickpay', 110, 'second try: ' );
		$t::same( true, $again->get_id() !== $order->get_id(), 'a new order is made' );
		$t::same( 'pending', $again->get_status(), 'the new order waits for payment' );
	}
);

// The customer paid on QuickPay's page in one tab (QuickPay accepted 110.00), and pressed
// cancel on the same payment page in another tab before the first tab came back. Then the
// first tab comes back on the continue link. QuickPay has the money, so the order must be kept
// and end up paid.
$t::test(
	'QuickPay: paid in one tab and cancelled in another: the paid order is kept',
	function () use ( $t, $payment_results_quickpay_use, $payment_results_place, $payment_results_quickpay_start, $payment_results_quickpay_lookup, $payment_results_visit, $payment_results_exists, $payment_results_fresh ) {
		$payment_results_quickpay_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order    = $payment_results_place( 'quickpay', 110 );
		$links    = $payment_results_quickpay_start( $order );
		$quickpay = sunshine_get_payment_method_by_id( 'quickpay' );
		$paid     = $payment_results_quickpay_lookup( $order, 11000, true );
		$payment_results_visit(
			$links['cancel'],
			function () use ( $quickpay ) {
				$quickpay->cancel();
			},
			$paid,
			'QuickPay cancel link'
		);
		$t::same( true, $payment_results_exists( $order->get_id() ), 'the order QuickPay was paid for is kept after the cancel link' );

		$payment_results_visit(
			$links['continue'],
			function () use ( $quickpay ) {
				$quickpay->success();
			},
			$paid,
			'QuickPay continue link'
		);
		$t::same( true, $payment_results_exists( $order->get_id() ) && $payment_results_fresh( $order->get_id() )->is_paid(), 'order is paid after the continue link' );
	}
);

/*
 * Paystack: the cancel link deletes the order whenever it is not marked paid. The order keeps
 * the Paystack transaction reference, so Paystack can be asked whether it was paid.
 */

$payment_results_paystack_use = function () use ( $t, $payment_results_settings ) {
	$t::require_plugin( 'sunshine_payment_method_paystack', 'Paystack add-on' );
	$payment_results_settings(
		array(
			'paystack_active'          => 1,
			'paystack_mode'            => 'test',
			'paystack_test_secret_key' => 'sk_test_spctotals',
			'paystack_test_public_key' => 'pk_test_spctotals',
			'paystack_fee'             => 'none',
		)
	);
};

// Send the customer to Paystack. Returns the amount Paystack was asked for, and the return
// (callback) and cancel links it was given.
$payment_results_paystack_start = function ( $order ) use ( $payment_results_run, $payment_results_find, $payment_results_wire ) {
	$paystack = sunshine_get_payment_method_by_id( 'paystack' );
	$run      = $payment_results_run(
		function () use ( $paystack, $order ) {
			$paystack->redirect_to_payment( $order );
		},
		function ( $request ) use ( $order ) {
			if ( '/transaction/initialize' === $request['path'] ) {
				return array(
					200,
					array(
						'status' => true,
						'data'   => array(
							'authorization_url' => 'https://checkout.paystack.com/spctotals',
							'access_code'       => 'spcaccess' . $order->get_id(),
							'reference'         => 'spcref' . $order->get_id(),
						),
					),
				);
			}
			return null;
		},
		'Paystack start'
	);
	$sent = $payment_results_wire( $payment_results_find( $run['sent'], '#^/transaction/initialize$#' ) );
	return array(
		'amount' => isset( $sent['amount'] ) ? $sent['amount'] : null,
		'back'   => isset( $sent['callback_url'] ) ? $sent['callback_url'] : '',
		'cancel' => isset( $sent['metadata']['cancel_action'] ) ? $sent['metadata']['cancel_action'] : '',
	);
};

// Paystack's answer when asked about transaction $reference: a successful payment of $amount
// (smallest unit), or an abandoned one.
$payment_results_paystack_lookup = function ( $reference, $amount, $status = 'success' ) {
	return function ( $request ) use ( $reference, $amount, $status ) {
		if ( '/transaction/verify/' . $reference !== $request['path'] ) {
			return null;
		}
		return array(
			200,
			array(
				'status'  => true,
				'message' => 'Verification successful',
				'data'    => array(
					'id'        => 4099260516,
					'status'    => $status,
					'reference' => $reference,
					'amount'    => $amount,
					'currency'  => 'USD',
					'fees'      => 315,
				),
			),
		);
	};
};

// Open a Paystack link (return or cancel) for transaction $reference.
$payment_results_paystack_open = function ( $url, $reference, $callback, $reply, $label ) use ( $payment_results_visit ) {
	$url = add_query_arg(
		array(
			'trxref'    => $reference,
			'reference' => $reference,
		),
		$url
	);
	return $payment_results_visit( $url, $callback, $reply, $label );
};

$t::test(
	'Paystack: cancelling before paying removes the unpaid order, and checking out again costs the same',
	function () use ( $t, $payment_results_paystack_use, $payment_results_place, $payment_results_paystack_start, $payment_results_paystack_lookup, $payment_results_paystack_open, $payment_results_exists, $payment_results_links_errors ) {
		$payment_results_paystack_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order    = $payment_results_place( 'paystack', 110 );
		$links    = $payment_results_paystack_start( $order );
		$t::same( 11000, (int) $links['amount'], 'Paystack is asked for 110.00 (in cents)' );
		$paystack = sunshine_get_payment_method_by_id( 'paystack' );
		$payment_results_paystack_open(
			$links['cancel'],
			'spcref' . $order->get_id(),
			function () use ( $paystack ) {
				$paystack->cancel();
			},
			$payment_results_paystack_lookup( 'spcref' . $order->get_id(), 11000, 'abandoned' ),
			'Paystack cancel link'
		);
		$t::same( false, $payment_results_exists( $order->get_id() ), 'the unpaid order is removed' );
		$t::same( true, false !== stripos( $payment_results_links_errors(), 'cancelled' ), 'the customer is told the payment was cancelled' );

		$t::new_request();
		$again = $payment_results_place( 'paystack', 110, 'second try: ' );
		$t::same( true, $again->get_id() !== $order->get_id(), 'a new order is made' );
	}
);

// Paid on Paystack's page in one tab (Paystack reports a successful 110.00 payment), cancel
// pressed in another tab before the first one came back. Then the first tab comes back on the
// return link. Paystack has the money, so the order must be kept and end up paid.
$t::test(
	'Paystack: paid in one tab and cancelled in another: the paid order is kept',
	function () use ( $t, $payment_results_paystack_use, $payment_results_place, $payment_results_paystack_start, $payment_results_paystack_lookup, $payment_results_paystack_open, $payment_results_exists, $payment_results_fresh ) {
		$payment_results_paystack_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order     = $payment_results_place( 'paystack', 110 );
		$links     = $payment_results_paystack_start( $order );
		$reference = 'spcref' . $order->get_id();
		$paid      = $payment_results_paystack_lookup( $reference, 11000 );
		$paystack  = sunshine_get_payment_method_by_id( 'paystack' );
		$payment_results_paystack_open(
			$links['cancel'],
			$reference,
			function () use ( $paystack ) {
				$paystack->cancel();
			},
			$paid,
			'Paystack cancel link'
		);
		$t::same( true, $payment_results_exists( $order->get_id() ), 'the order Paystack was paid for is kept after the cancel link' );

		$payment_results_paystack_open(
			$links['back'],
			$reference,
			function () use ( $paystack ) {
				$paystack->success();
			},
			$paid,
			'Paystack return link'
		);
		$t::same( true, $payment_results_exists( $order->get_id() ) && $payment_results_fresh( $order->get_id() )->is_paid(), 'order is paid after the return link' );
	}
);

// A paid order the photographer later refunded in full ("Refunded" status, refund of 110.00
// recorded, as Paystack's own refund action does). The cancel link is still in the customer's
// browser history and is opened again the same day. The refunded order is a record of real
// money in and out, so it must stay.
$t::test(
	'Paystack: an old cancel link does not delete a refunded order',
	function () use ( $t, $payment_results_paystack_use, $payment_results_place, $payment_results_paystack_start, $payment_results_paystack_lookup, $payment_results_paystack_open, $payment_results_exists, $payment_results_fresh ) {
		$payment_results_paystack_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order     = $payment_results_place( 'paystack', 110 );
		$links     = $payment_results_paystack_start( $order );
		$reference = 'spcref' . $order->get_id();
		$paid      = $payment_results_paystack_lookup( $reference, 11000 );
		$paystack  = sunshine_get_payment_method_by_id( 'paystack' );
		$payment_results_paystack_open(
			$links['back'],
			$reference,
			function () use ( $paystack ) {
				$paystack->success();
			},
			$paid,
			'Paystack return link'
		);
		$saved = $payment_results_fresh( $order->get_id() );
		$t::same( true, $saved->is_paid(), 'order is paid' );
		$saved->set_status( 'refunded' );
		$saved->add_refund( 110 );

		$payment_results_paystack_open(
			$links['cancel'],
			$reference,
			function () use ( $paystack ) {
				$paystack->cancel();
			},
			$paid,
			'Paystack cancel link'
		);
		$t::same( true, $payment_results_exists( $order->get_id() ), 'the refunded order is kept' );
		if ( $payment_results_exists( $order->get_id() ) ) {
			$t::money( 110, $payment_results_fresh( $order->get_id() )->get_refund_total(), 'its refund is still recorded' );
		}
	}
);

/*
 * PayPal Legacy: the cancel link (cancel_return in the PayPal form) deletes the order. Its
 * security check is the same for every order, and the order number is a separate part of the
 * link.
 */

$t::test(
	'PayPal Legacy: cancelling before paying lets the customer check out again for the same total',
	function () use ( $t, $payment_results_legacy_settings, $payment_results_place, $payment_results_legacy_form, $payment_results_visit, $payment_results_exists, $payment_results_links_errors, $payment_results_links_line_rows ) {
		$options = $payment_results_legacy_settings();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order  = $payment_results_place( 'paypal-legacy', 110 );
		$fields = $payment_results_legacy_form( $order, $options );
		$t::same( true, ! empty( $fields['cancel_return'] ), 'PayPal is given a cancel link' );
		$legacy = sunshine_get_payment_method_by_id( 'paypal-legacy' );
		$run    = $payment_results_visit(
			$fields['cancel_return'],
			function () use ( $legacy ) {
				$legacy->cancel_order();
			},
			null,
			'PayPal Legacy cancel link'
		);
		$t::same( sunshine_get_page_url( 'checkout' ), $run['redirect'], 'customer is sent back to the checkout' );
		$t::same( false, $payment_results_exists( $order->get_id() ), 'the unpaid order is removed' );
		$t::same( 0, $payment_results_links_line_rows( $order->get_id() ), 'its order lines are removed too' );
		$t::same( true, false !== stripos( $payment_results_links_errors(), 'cancelled' ), 'the customer is told the payment was cancelled' );

		$t::new_request();
		$again = $payment_results_place( 'paypal-legacy', 110, 'second try: ' );
		$t::same( true, $again->get_id() !== $order->get_id(), 'a new order is made' );
	}
);

// The customer paid at PayPal and PayPal's IPN completed the order. The cancel link from the
// PayPal page is then opened (back button, or a second tab). The paid order must stay.
$t::test(
	'PayPal Legacy: the cancel link does not delete an order PayPal already paid',
	function () use ( $t, $payment_results_legacy_settings, $payment_results_place, $payment_results_legacy_form, $payment_results_legacy_ipn, $payment_results_visit, $payment_results_exists, $payment_results_fresh ) {
		$options = $payment_results_legacy_settings();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order  = $payment_results_place( 'paypal-legacy', 110 );
		$fields = $payment_results_legacy_form( $order, $options );
		$payment_results_legacy_ipn( $order, $options, '110.00' );
		$t::same( true, $payment_results_fresh( $order->get_id() )->is_paid(), 'order is paid by the IPN' );

		$legacy = sunshine_get_payment_method_by_id( 'paypal-legacy' );
		$payment_results_visit(
			$fields['cancel_return'],
			function () use ( $legacy ) {
				$legacy->cancel_order();
			},
			null,
			'PayPal Legacy cancel link'
		);
		$t::same( true, $payment_results_exists( $order->get_id() ), 'the paid order is kept' );
		if ( $payment_results_exists( $order->get_id() ) ) {
			$t::same( true, $payment_results_fresh( $order->get_id() )->is_paid(), 'and is still paid' );
		}
	}
);

// Two shoppers, both checking out as guests. The first one's order is paid through PayPal.
// The second one starts a PayPal checkout, gets their own cancel link, and changes the order
// number in it to the first shopper's order. A cancel link must only ever cancel the order it
// was made for.
$t::test(
	'PayPal Legacy: a cancel link can\'t delete a different order',
	function () use ( $t, $payment_results_legacy_settings, $payment_results_place, $payment_results_next_order, $payment_results_legacy_form, $payment_results_legacy_ipn, $payment_results_visit, $payment_results_exists, $payment_results_fresh ) {
		$options = $payment_results_legacy_settings();
		$product = $t::product( 100, array( 'taxable' => false ) );
		$t::add( $product );
		$first = $payment_results_place( 'paypal-legacy', 100, 'first shopper: ' );
		$payment_results_legacy_form( $first, $options );
		$payment_results_legacy_ipn( $first, $options, '100.00' );
		$t::same( true, $payment_results_fresh( $first->get_id() )->is_paid(), 'first shopper\'s order is paid' );

		$payment_results_next_order();
		$t::add( $product );
		$second = $payment_results_place( 'paypal-legacy', 100, 'second shopper: ' );
		$fields = $payment_results_legacy_form( $second, $options );
		$link   = add_query_arg( 'order_id', $first->get_id(), $fields['cancel_return'] );
		$legacy = sunshine_get_payment_method_by_id( 'paypal-legacy' );
		$payment_results_visit(
			$link,
			function () use ( $legacy ) {
				$legacy->cancel_order();
			},
			null,
			'PayPal Legacy changed cancel link'
		);
		$t::same( true, $payment_results_exists( $first->get_id() ), 'the first shopper\'s paid order is kept' );
	}
);
