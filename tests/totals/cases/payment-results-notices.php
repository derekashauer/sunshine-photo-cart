<?php
/**
 * Payment notices: messages a payment service sends to the site on its own, in the background,
 * about a payment that is not simply "paid".
 *
 * - PayPal Legacy IPNs that PayPal says are INVALID, or that are about a payment still Pending,
 *   Refunded or Reversed.
 * - PayFast ITNs with a payment status other than COMPLETE.
 * - Mollie webhooks for a payment that was refunded in Mollie's own dashboard
 *   (SPC_Payment_Method_Mollie::process_refund_webhook), or cancelled.
 *
 * Only a finished, full payment may mark an order paid. A refund made at the payment service
 * is recorded once, for exactly its amount, and the order shows "Refunded" (the docs: "The Order
 * Status will automatically change to Refunded", also after a part refund).
 *
 * Helpers are in payment-results-helpers.php. Nothing reaches any payment service.
 */

$t = 'SPC_Totals_Test';

/*
 * PayPal Legacy IPN.
 */

$t::test(
	'PayPal Legacy: an IPN that PayPal reports as INVALID leaves the order unpaid',
	function () use ( $t, $payment_results_legacy_settings, $payment_results_place, $payment_results_legacy_form, $payment_results_legacy_ipn, $payment_results_unpaid ) {
		$options = $payment_results_legacy_settings();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $payment_results_place( 'paypal-legacy', 110 );
		$payment_results_legacy_form( $order, $options );
		$child = $payment_results_legacy_ipn( $order, $options, '110.00', array(), 'INVALID' );
		$asked = false;
		foreach ( $child['result']['sent'] as $request ) {
			if ( false !== strpos( $request['body'], 'cmd=_notify-validate' ) ) {
				$asked = true;
			}
		}
		$t::same( true, $asked, 'PayPal was asked whether the IPN is real' );
		$payment_results_unpaid( $order->get_id() );
	}
);

// An eCheck takes days to clear, so PayPal first sends a Pending IPN and later a Completed one.
// PayPal also sends the Completed IPN again if it did not hear back in time. The order is paid
// only after Completed, and the discount code on it is counted once.
// 100 - 10% code = 90, + 10% tax = 9, total 99.00.
$t::test(
	'PayPal Legacy: a Pending payment leaves the order unpaid until PayPal says Completed',
	function () use ( $t, $payment_results_legacy_settings, $payment_results_place, $payment_results_legacy_form, $payment_results_legacy_ipn, $payment_results_unpaid, $payment_results_fresh ) {
		$options = $payment_results_legacy_settings();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$code   = $t::discount( 'percent-total', 10 );
		$order  = $payment_results_place( 'paypal-legacy', 99 );
		$fields = $payment_results_legacy_form( $order, $options );
		$payment_results_legacy_ipn(
			$order,
			$options,
			'99.00',
			array(
				'payment_status' => 'Pending',
				'pending_reason' => 'echeck',
				'payment_type'   => 'echeck',
			)
		);
		$payment_results_unpaid( $order->get_id(), 'eCheck pending: ' );

		$payment_results_legacy_ipn( $order, $options, '99.00', array( 'payment_type' => 'echeck' ) );
		$payment_results_legacy_ipn( $order, $options, '99.00', array( 'payment_type' => 'echeck' ) );
		$saved = $payment_results_fresh( $order->get_id() );
		$t::same( true, $saved->is_paid(), 'order is paid once the eCheck cleared' );
		$t::money( 99, $saved->get_total(), 'order total' );
		// The IPNs ran in another process, so read the code's count fresh from the database.
		clean_post_cache( sunshine_get_discount_by_code( $code )->get_id() );
		$t::same( 1, sunshine_get_discount_by_code( $code )->get_use_count(), 'discount code counted as used once' );
	}
);

// When a payment is refunded or taken back (a chargeback) in PayPal, PayPal sends an IPN with
// payment_status Refunded or Reversed, a negative amount and the first payment's ID in
// parent_txn_id. It has no txn_type. Here the order was never paid (the shopper was sent to
// PayPal and the payment was refunded before the Completed IPN got through). Neither notice may
// mark the order paid, and neither should make PHP warnings.
$t::test(
	'PayPal Legacy: a refund or reversal notice never marks an unpaid order paid',
	function () use ( $t, $payment_results_legacy_settings, $payment_results_place, $payment_results_legacy_form, $payment_results_legacy_ipn, $payment_results_unpaid, $payment_results_warnings_from ) {
		$options = $payment_results_legacy_settings();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $payment_results_place( 'paypal-legacy', 110 );
		$payment_results_legacy_form( $order, $options );
		$warnings = array();
		foreach ( array( 'Refunded' => 'refund', 'Reversed' => 'chargeback' ) as $status => $reason ) {
			$child    = $payment_results_legacy_ipn(
				$order,
				$options,
				'-110.00',
				array(
					'txn_type'       => null,
					'payment_status' => $status,
					'reason_code'    => $reason,
					'parent_txn_id'  => 'SPCTXN' . $order->get_id(),
					'txn_id'         => 'SPCREF' . $order->get_id() . $reason,
				)
			);
			$warnings = array_merge( $warnings, $payment_results_warnings_from( $child, 'paypal-legacy.php' ) );
			$payment_results_unpaid( $order->get_id(), $status . ': ' );
		}
		$t::same( array(), $warnings, 'no PHP warnings from the refund and reversal IPNs' );
	}
);

/*
 * PayFast ITN payment statuses. The existing tests cover COMPLETE and CANCELLED. PayFast's ITN
 * can also say FAILED, or PENDING (a payment that is not finished yet, for example an EFT).
 */

$t::test(
	'PayFast: an ITN for a FAILED payment leaves the order unpaid',
	function () use ( $t, $payment_results_payfast_settings, $payment_results_place, $payment_results_payfast_itn, $payment_results_payfast_notify, $payment_results_unpaid ) {
		$options = $payment_results_payfast_settings();
		$t::tax( 15 );
		$t::add( $t::product( 200 ) );
		// 200 + 15% VAT = 230 rand.
		$order = $payment_results_place( 'payfast', 230 );
		$payment_results_payfast_notify( $order, $options, $payment_results_payfast_itn( $order, 230, array( 'payment_status' => 'FAILED' ) ) );
		$payment_results_unpaid( $order->get_id() );
	}
);

$t::test(
	'PayFast: a PENDING ITN leaves the order unpaid, and the COMPLETE ITN after it finishes it',
	function () use ( $t, $payment_results_payfast_settings, $payment_results_place, $payment_results_payfast_itn, $payment_results_payfast_notify, $payment_results_unpaid, $payment_results_fresh ) {
		$options = $payment_results_payfast_settings();
		$t::tax( 15 );
		$t::add( $t::product( 200 ) );
		$order = $payment_results_place( 'payfast', 230 );
		$payment_results_payfast_notify( $order, $options, $payment_results_payfast_itn( $order, 230, array( 'payment_status' => 'PENDING' ) ) );
		$payment_results_unpaid( $order->get_id(), 'PENDING: ' );
		$payment_results_payfast_notify( $order, $options, $payment_results_payfast_itn( $order, 230 ) );
		$t::same( true, $payment_results_fresh( $order->get_id() )->is_paid(), 'COMPLETE: order is paid' );
	}
);

/*
 * Mollie refunds made in Mollie's own dashboard.
 */

// Place an order paid through Mollie: Mollie's webhook says payment $payment_id was paid in
// full. Returns the paid order.
$payment_results_mollie_paid_order = function ( $total, $payment_id ) use ( $t, $payment_results_mollie_use, $payment_results_place, $payment_results_mollie_webhook, $payment_results_mollie_payment, $payment_results_fresh ) {
	$payment_results_mollie_use();
	$order = $payment_results_place( 'mollie', $total );
	$payment_results_mollie_webhook( $payment_results_mollie_payment( $payment_id, $order->get_id(), number_format( $total, 2, '.', '' ) ) );
	$saved = $payment_results_fresh( $order->get_id() );
	$t::same( true, $saved->is_paid(), 'order is paid by the Mollie webhook' );
	return $saved;
};

// Mollie's webhook for payment $payment_id after refunds $refunds (id => array( value, status )).
$payment_results_mollie_refunded = function ( $order, $payment_id, $total, $refunds ) use ( $payment_results_mollie_webhook, $payment_results_mollie_payment, $payment_results_mollie_refund ) {
	$list = array();
	foreach ( $refunds as $id => $refund ) {
		$list[] = $payment_results_mollie_refund( $id, $payment_id, $refund[0], isset( $refund[1] ) ? $refund[1] : 'refunded' );
	}
	return $payment_results_mollie_webhook( $payment_results_mollie_payment( $payment_id, $order->get_id(), number_format( $total, 2, '.', '' ), 'paid', $list ), $list );
};

// Prices include tax: a 110.00 print (10% tax inside) with a 10% code costs 99.00. The
// photographer refunds the whole 99.00 in Mollie's dashboard.
$t::test(
	'Mollie: a full refund made in Mollie\'s dashboard marks the order refunded and takes off the whole amount',
	function () use ( $t, $payment_results_mollie_paid_order, $payment_results_mollie_refunded, $payment_results_fresh ) {
		$t::option( 'price_has_tax', 'yes' );
		$t::tax( 10 );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		// 110 - 10% = 99.00, tax included.
		$order = $payment_results_mollie_paid_order( 99, 'tr_spcfull' );
		$sent  = $payment_results_mollie_refunded( $order, 'tr_spcfull', 99, array( 're_spcfull' => array( '99.00' ) ) );
		$t::same( true, in_array( 'GET', wp_list_pluck( $sent, 'method' ), true ) && 2 === count( $sent ), 'the payment and its refunds were looked up at Mollie' );
		$saved = $payment_results_fresh( $order->get_id() );
		$t::same( 'refunded', $saved->get_status(), 'order status is Refunded' );
		$t::money( 99, $saved->get_refund_total(), 'refund recorded' );
		$t::money( 0, $saved->get_total_minus_refunds(), 'nothing left on the order' );
		$t::same( 1, count( (array) $saved->get_refunds() ), 'one refund on the order' );
	}
);

// 3 prints at 19.99 = 59.97, 8.25% tax = 4.947525, rounds to 4.95, total 64.92. The
// photographer refunds one print with its tax in Mollie: 19.99 + 1.65 = 21.64. Left on the
// order: 64.92 - 21.64 = 43.28. The customer's "total spent" drops to 43.28 too.
$t::test(
	'Mollie: a part refund made in Mollie takes off only that amount, to the cent',
	function () use ( $t, $payment_results_mollie_paid_order, $payment_results_mollie_refunded, $payment_results_fresh ) {
		$customer_id = $t::customer();
		$t::tax( 8.25 );
		$t::add( $t::product( 19.99 ), 3 );
		$order = $payment_results_mollie_paid_order( 64.92, 'tr_spcpart' );
		$payment_results_mollie_refunded( $order, 'tr_spcpart', 64.92, array( 're_spcpart' => array( '21.64' ) ) );
		$saved = $payment_results_fresh( $order->get_id() );
		$t::same( 'refunded', $saved->get_status(), 'order status is Refunded' );
		$t::money( 21.64, $saved->get_refund_total(), 'refund recorded' );
		$t::money( 43.28, $saved->get_total_minus_refunds(), 'left on the order' );
		$t::money( 43.28, $saved->get_total(), 'order total shown' );
		wp_cache_delete( $customer_id, 'user_meta' );
		$t::money( 43.28, ( new SPC_Customer( $customer_id ) )->get_order_totals(), 'customer total spent' );
	}
);

// Mollie calls the webhook again for the same payment (it retries, and it calls again for
// any later change). A refund already recorded must not be taken off a second time.
$t::test(
	'Mollie: the same refund notice twice is counted once',
	function () use ( $t, $payment_results_mollie_paid_order, $payment_results_mollie_refunded, $payment_results_fresh ) {
		$t::tax( 8.25 );
		$t::add( $t::product( 19.99 ), 3 );
		$order = $payment_results_mollie_paid_order( 64.92, 'tr_spctwice' );
		$payment_results_mollie_refunded( $order, 'tr_spctwice', 64.92, array( 're_spctwice' => array( '21.64' ) ) );
		$payment_results_mollie_refunded( $order, 'tr_spctwice', 64.92, array( 're_spctwice' => array( '21.64' ) ) );
		$saved = $payment_results_fresh( $order->get_id() );
		$t::money( 21.64, $saved->get_refund_total(), 'refund recorded once' );
		$t::same( 1, count( (array) $saved->get_refunds() ), 'one refund on the order' );
		$t::money( 43.28, $saved->get_total_minus_refunds(), 'left on the order' );
	}
);

// 100 + 10% tax = 110. A 10.00 refund one day, a 5.25 refund the next. Mollie lists both
// refunds on the second notice. Refunded 15.25 in all, 94.75 left.
$t::test(
	'Mollie: a second refund later adds to the first',
	function () use ( $t, $payment_results_mollie_paid_order, $payment_results_mollie_refunded, $payment_results_fresh ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $payment_results_mollie_paid_order( 110, 'tr_spclater' );
		$payment_results_mollie_refunded( $order, 'tr_spclater', 110, array( 're_spcone' => array( '10.00' ) ) );
		$t::money( 10, $payment_results_fresh( $order->get_id() )->get_refund_total(), 'first refund recorded' );
		$payment_results_mollie_refunded(
			$order,
			'tr_spclater',
			110,
			array(
				're_spcone' => array( '10.00' ),
				're_spctwo' => array( '5.25' ),
			)
		);
		$saved = $payment_results_fresh( $order->get_id() );
		$t::money( 15.25, $saved->get_refund_total(), 'both refunds recorded' );
		$t::same( 2, count( (array) $saved->get_refunds() ), 'two refunds on the order' );
		$t::money( 94.75, $saved->get_total_minus_refunds(), 'left on the order' );
	}
);

// Two refunds made close together reach the site in one notice. Both count, and the next
// notice (same two refunds) adds nothing. 110 - 10.00 - 5.25 = 94.75.
$t::test(
	'Mollie: two new refunds in one notice are both counted, once',
	function () use ( $t, $payment_results_mollie_paid_order, $payment_results_mollie_refunded, $payment_results_fresh ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order   = $payment_results_mollie_paid_order( 110, 'tr_spcpair' );
		$refunds = array(
			're_spcpaira' => array( '10.00' ),
			're_spcpairb' => array( '5.25' ),
		);
		$payment_results_mollie_refunded( $order, 'tr_spcpair', 110, $refunds );
		$t::money( 15.25, $payment_results_fresh( $order->get_id() )->get_refund_total(), 'both refunds recorded' );
		$payment_results_mollie_refunded( $order, 'tr_spcpair', 110, $refunds );
		$saved = $payment_results_fresh( $order->get_id() );
		$t::money( 15.25, $saved->get_refund_total(), 'nothing added by the repeat notice' );
		$t::same( 2, count( (array) $saved->get_refunds() ), 'two refunds on the order' );
		$t::money( 94.75, $saved->get_total_minus_refunds(), 'left on the order' );
	}
);

// Mollie calls the webhook when a refund starts processing and again when it is done. Only a
// refund Mollie says is "refunded" has really gone back to the customer (Sunshine's own code
// says the same), so the order changes only then. 110 - 20.00 = 90.00.
$t::test(
	'Mollie: a refund still processing is not counted until Mollie says it is refunded',
	function () use ( $t, $payment_results_mollie_paid_order, $payment_results_mollie_refunded, $payment_results_fresh ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $payment_results_mollie_paid_order( 110, 'tr_spcslow' );
		$payment_results_mollie_refunded( $order, 'tr_spcslow', 110, array( 're_spcslow' => array( '20.00', 'processing' ) ) );
		$saved = $payment_results_fresh( $order->get_id() );
		$t::money( 0, $saved->get_refund_total(), 'processing: nothing refunded yet' );
		$t::same( true, $saved->is_paid(), 'processing: order still paid' );
		$payment_results_mollie_refunded( $order, 'tr_spcslow', 110, array( 're_spcslow' => array( '20.00' ) ) );
		$saved = $payment_results_fresh( $order->get_id() );
		$t::money( 20, $saved->get_refund_total(), 'refunded: refund recorded' );
		$t::money( 90, $saved->get_total_minus_refunds(), 'refunded: left on the order' );
	}
);

// A refund Mollie could not make (status failed) gave nothing back, so the order keeps its
// full total and stays paid.
$t::test(
	'Mollie: a failed refund takes nothing off the order',
	function () use ( $t, $payment_results_mollie_paid_order, $payment_results_mollie_refunded, $payment_results_fresh ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $payment_results_mollie_paid_order( 110, 'tr_spcfail' );
		$payment_results_mollie_refunded( $order, 'tr_spcfail', 110, array( 're_spcfail' => array( '30.00', 'failed' ) ) );
		$saved = $payment_results_fresh( $order->get_id() );
		$t::money( 0, $saved->get_refund_total(), 'nothing refunded' );
		$t::money( 110, $saved->get_total_minus_refunds(), 'full total kept' );
		$t::same( true, $saved->is_paid(), 'order still paid' );
	}
);

// The photographer refunds 19.99 from the order screen (Sunshine asks Mollie to refund it, and
// records it right away). Mollie's webhook then reports that same refund as done, and later a
// second 10.00 refund made in Mollie's dashboard. 19.99 + 10.00 = 29.99 refunded, 110 - 29.99 =
// 80.01 left.
$t::test(
	'Mollie: a refund made from the order screen is not counted again when Mollie\'s notice comes',
	function () use ( $t, $payment_results_mollie_paid_order, $payment_results_mollie_refunded, $payment_results_mollie_client, $payment_results_mollie_payment, $payment_results_run, $payment_results_fresh ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $payment_results_mollie_paid_order( 110, 'tr_spcadmin' );
		$t::same( 'tr_spcadmin', (string) $order->get_meta_value( 'mollie_payment_id' ), 'Mollie payment saved on the order' );

		list( $mollie, $connection ) = $payment_results_mollie_client( $payment_results_mollie_payment( 'tr_spcadmin', $order->get_id(), '110.00' ) );
		$_POST['mollie_refund_amount'] = '19.99';
		$_POST['mollie_refund_reason'] = 'Wrong size';
		try {
			$payment_results_run(
				function () use ( $mollie, $order ) {
					$mollie->process_refund( $order->get_id() );
				},
				null,
				'Mollie refund from the order screen'
			);
		} finally {
			unset( $_POST['mollie_refund_amount'], $_POST['mollie_refund_reason'] );
		}
		$asked = null;
		foreach ( $connection->sent as $request ) {
			if ( 'POST' === $request['method'] ) {
				$asked = $request['body'];
			}
		}
		$t::same( '19.99', isset( $asked['amount']['value'] ) ? $asked['amount']['value'] : null, 'Mollie is asked to refund 19.99' );
		$t::money( 19.99, $payment_results_fresh( $order->get_id() )->get_refund_total(), 'refund recorded from the order screen' );

		$payment_results_mollie_refunded( $order, 'tr_spcadmin', 110, array( 're_spcadmin' => array( '19.99' ) ) );
		$t::money( 19.99, $payment_results_fresh( $order->get_id() )->get_refund_total(), 'Mollie\'s notice for the same refund adds nothing' );

		$payment_results_mollie_refunded(
			$order,
			'tr_spcadmin',
			110,
			array(
				're_spcadmin' => array( '19.99' ),
				're_spcdash'  => array( '10.00' ),
			)
		);
		$saved = $payment_results_fresh( $order->get_id() );
		$t::money( 29.99, $saved->get_refund_total(), 'the dashboard refund is added' );
		$t::money( 80.01, $saved->get_total_minus_refunds(), 'left on the order' );
	}
);

// Mollie's "paid" notice never got through (the site was down while Mollie retried). The
// photographer then refunds 10.00 of the 110.00 in Mollie, which sends a new notice. The
// customer did pay, so the order must be finished as a paid order (receipt, stock, credits,
// codes) and show the 10.00 refund, leaving 100.00.
$t::test(
	'Mollie: when the paid notice was missed, the refund notice still finishes the paid order',
	function () use ( $t, $payment_results_mollie_use, $payment_results_place, $payment_results_mollie_refunded, $payment_results_fresh ) {
		$payment_results_mollie_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $payment_results_place( 'mollie', 110 );
		$t::same( 'pending', $order->get_status(), 'order waits for payment' );
		$payment_results_mollie_refunded( $order, 'tr_spcmissed', 110, array( 're_spcmissed' => array( '10.00' ) ) );
		$saved = $payment_results_fresh( $order->get_id() );
		$t::same( true, '' !== (string) $saved->get_meta_value( 'post_processed' ), 'the paid order was finished' );
		$t::money( 10, $saved->get_refund_total(), 'refund recorded' );
		$t::money( 100, $saved->get_total_minus_refunds(), 'left on the order' );
	}
);

// The customer cancels on Mollie's page; Mollie's webhook says the payment was canceled and the
// order is marked Cancelled. The customer goes back to the checkout and submits again (the same
// order is reused and a new Mollie payment is made), then pays. Mollie's webhook for the new
// payment says paid, for the full 110.00. The order must end up paid.
$t::test(
	'Mollie: after a cancelled payment, paying on the second try completes the order',
	function () use ( $t, $payment_results_mollie_use, $payment_results_place, $payment_results_mollie_webhook, $payment_results_mollie_payment, $payment_results_fresh ) {
		$payment_results_mollie_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $payment_results_place( 'mollie', 110 );
		$payment_results_mollie_webhook( $payment_results_mollie_payment( 'tr_spcfirsttry', $order->get_id(), '110.00', 'canceled' ) );
		$saved = $payment_results_fresh( $order->get_id() );
		$t::same( false, $saved->is_paid(), 'first try cancelled: order not paid' );

		$t::new_request();
		$again = $payment_results_place( 'mollie', 110, 'second try: ' );
		$t::same( $order->get_id(), $again->get_id(), 'the same order is used for the second try' );
		$t::same( 'pending', $payment_results_fresh( $order->get_id() )->get_status(), 'order waits for payment again' );
		$payment_results_mollie_webhook( $payment_results_mollie_payment( 'tr_spcsecondtry', $order->get_id(), '110.00' ) );
		$saved = $payment_results_fresh( $order->get_id() );
		$t::same( true, $saved->is_paid(), 'order is paid after the second try' );
		$t::same( 'tr_spcsecondtry', (string) $saved->get_meta_value( 'mollie_payment_id' ), 'the paid Mollie payment is saved on the order' );
	}
);
