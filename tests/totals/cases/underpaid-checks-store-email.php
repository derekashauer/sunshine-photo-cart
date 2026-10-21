<?php
/**
 * The Admin Payment Problem email: the store owner hears about a payment Sunshine refused (wrong
 * amount, currency, order or account) and about orders marked Failed, so they can check the
 * payment before refunding or marking the order paid. It is off until the store owner turns it
 * on, because not every store wants these.
 *
 * Uses the Mollie and PayFast helpers from underpaid-checks-redirect-gateways.php, so run it with
 * that file and underpaid-checks-helpers.php. Mail is caught with pre_wp_mail and never sent.
 */

$t = 'SPC_Totals_Test';

// Run $callback and return every payment problem email WordPress was asked to send (to,
// subject, message). Other mail (like the order receipts a paid order sends) is stopped too.
$store_email_catch = function ( $callback ) {
	$mail  = array();
	$catch = function ( $pre, $atts ) use ( &$mail ) {
		if ( false !== strpos( (string) $atts['subject'], 'Payment problem with' ) ) {
			$mail[] = $atts;
		}
		return false;
	};
	add_filter( 'pre_wp_mail', $catch, 1, 2 );
	try {
		$callback();
	} finally {
		remove_filter( 'pre_wp_mail', $catch, 1 );
	}
	return $mail;
};

// The store owner turned the email on. The admin order receipt goes to one address.
$store_email_on = function () use ( $t ) {
	$t::option( 'email_admin-payment-problem_active', 1 );
	$t::option( 'email_admin-receipt_recipients', 'owner@example.com' );
	return array(
		'email_admin-payment-problem_active' => 1,
		'email_admin-receipt_recipients'     => 'owner@example.com',
	);
};

$t::test(
	'Payment problem email: off by default, a refused Mollie payment sends nothing',
	function () use ( $t, $store_email_catch, $underpaid_mollie_use, $underpaid_place, $underpaid_mollie_webhook, $underpaid_mollie_payment, $underpaid_still_pending ) {
		$email = SPC()->emails->get_email_by_id( 'admin-payment-problem' );
		$t::same( true, (bool) $email, 'the email is registered' );
		$t::same( false, $email->is_active_by_default(), 'install leaves it off' );
		$t::same( false, get_option( 'sunshine_email_admin-payment-problem_active' ), 'no saved setting on this site' );
		$t::same( false, (bool) $email->is_active(), 'no setting means off' );

		$underpaid_mollie_use();
		$t::option( 'email_admin-receipt_recipients', 'owner@example.com' );
		$t::add( $t::product( 110, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'mollie', 110 );
		$mail  = $store_email_catch(
			function () use ( $underpaid_mollie_webhook, $underpaid_mollie_payment, $order ) {
				$underpaid_mollie_webhook( $underpaid_mollie_payment( 'tr_spcmailoff', $order->get_id(), '55.00' ) );
			}
		);
		$underpaid_still_pending( $order->get_id() );
		$t::same( 0, count( $mail ), 'no email sent' );
	}
);

$t::test(
	'Payment problem email: a refused Mollie payment sends one email, even when Mollie calls again',
	function () use ( $t, $store_email_catch, $store_email_on, $underpaid_mollie_use, $underpaid_place, $underpaid_mollie_webhook, $underpaid_mollie_payment, $underpaid_still_pending ) {
		$underpaid_mollie_use();
		$store_email_on();
		$t::add( $t::product( 110, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'mollie', 110 );
		$mail  = $store_email_catch(
			function () use ( $underpaid_mollie_webhook, $underpaid_mollie_payment, $order ) {
				$underpaid_mollie_webhook( $underpaid_mollie_payment( 'tr_spcmailon', $order->get_id(), '55.00' ) );
				// Mollie retries webhooks; the same refused payment is not emailed twice.
				$underpaid_mollie_webhook( $underpaid_mollie_payment( 'tr_spcmailon', $order->get_id(), '55.00' ) );
			}
		);
		$underpaid_still_pending( $order->get_id() );
		$t::same( 1, count( $mail ), 'one email sent' );
		$sent = isset( $mail[0] ) ? $mail[0] : array( 'to' => '', 'subject' => '', 'message' => '' );
		$t::same( 'owner@example.com', $sent['to'], 'sent to the admin order receipt address' );
		$t::same( true, false !== strpos( $sent['subject'], $order->get_name() ), 'subject names the order' );
		$t::same( true, false !== strpos( $sent['message'], (string) $order->get_order_number() ), 'email has the order number' );
		$t::same( true, false !== strpos( $sent['message'], 'tr_spcmailon' ) && false !== strpos( $sent['message'], 'the order total is' ), 'email says why' );
		$t::same( true, false !== strpos( $sent['message'], 'post=' . $order->get_id() . '&amp;action=edit' ) || false !== strpos( $sent['message'], 'post=' . $order->get_id() . '&action=edit' ), 'email links to the order in admin' );
		$t::same( true, false !== strpos( $sent['message'], 'dashboard before refunding' ), 'email says what to do' );
	}
);

$t::test(
	'Payment problem email: a paid Mollie order sends none',
	function () use ( $t, $store_email_catch, $store_email_on, $underpaid_mollie_use, $underpaid_place, $underpaid_mollie_webhook, $underpaid_mollie_payment, $underpaid_fresh ) {
		$underpaid_mollie_use();
		$store_email_on();
		$t::add( $t::product( 110, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'mollie', 110 );
		$mail  = $store_email_catch(
			function () use ( $underpaid_mollie_webhook, $underpaid_mollie_payment, $order ) {
				$underpaid_mollie_webhook( $underpaid_mollie_payment( 'tr_spcmailpaid', $order->get_id(), '110.00' ) );
			}
		);
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'order is paid' );
		$t::same( 0, count( $mail ), 'no email sent' );
	}
);

$t::test(
	'Payment problem email: a PayFast payment the API lists for the wrong amount sends one email',
	function () use ( $t, $store_email_catch, $store_email_on, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_history, $underpaid_payfast_api_reply, $underpaid_payfast_api_run, $underpaid_still_pending ) {
		$underpaid_payfast_settings();
		$store_email_on();
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$order   = $underpaid_place( 'payfast', 230 );
		$payfast = sunshine_get_payment_method_by_id( 'payfast' );
		$csv     = $underpaid_payfast_history(
			array(
				array(
					'M Payment ID' => (string) $order->get_id(),
					'Gross'        => '229.99',
				),
			)
		);
		$mail    = $store_email_catch(
			function () use ( $underpaid_payfast_api_run, $underpaid_payfast_api_reply, $payfast, $order, $csv ) {
				$underpaid_payfast_api_run(
					function () use ( $payfast, $order ) {
						$payfast->confirm_payment( sunshine_get_order( $order->get_id() ) );
					},
					$underpaid_payfast_api_reply( 200, $csv ),
					'PayFast API wrong amount'
				);
			}
		);
		$underpaid_still_pending( $order->get_id() );
		$t::same( 1, count( $mail ), 'one email sent' );
		$message = isset( $mail[0]['message'] ) ? $mail[0]['message'] : '';
		$t::same( true, false !== strpos( $message, (string) $order->get_order_number() ), 'email has the order number' );
		$t::same( true, false !== strpos( $message, 'does not match the order total' ), 'email says why' );
		// PayFast only takes rand, and the amount is shown in the order's own currency.
		$t::same( true, false !== strpos( $message, 'ZAR' ), 'total shown in the order currency' );
	}
);

// PayFast's ITN ends with exit, so it runs in a separate process that never sends mail. The
// order note the email adds when it goes out shows it was sent, and only once.
$t::test(
	'Payment problem email: a signed PayFast ITN for the wrong amount sends one email',
	function () use ( $t, $store_email_on, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_itn, $underpaid_payfast_notify, $underpaid_still_pending, $underpaid_payfast_log ) {
		$options = array_merge( $underpaid_payfast_settings(), $store_email_on() );
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'payfast', 230 );
		$itn   = $underpaid_payfast_itn( $order, 115, 'sunshine totals' );
		$underpaid_payfast_notify( $order, $options, $itn );
		$underpaid_payfast_notify( $order, $options, $itn );
		$underpaid_still_pending( $order->get_id() );
		$t::same( 1, count( $underpaid_payfast_log( $order->get_id(), 'Email sent to admin: Admin Payment Problem' ) ), 'one email sent for the same refused ITN' );
	}
);

$t::test(
	'Payment problem email: an order changed to Failed sends one email, with (test mode) for test orders',
	function () use ( $t, $store_email_catch, $store_email_on, $underpaid_mollie_use, $underpaid_place, $underpaid_fresh ) {
		$underpaid_mollie_use();
		$store_email_on();
		$t::add( $t::product( 110, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'mollie', 110 );
		$order->update_meta_value( 'mode', 'test' );
		$mail = $store_email_catch(
			function () use ( $order, $underpaid_fresh ) {
				$order->set_status( 'failed' );
				// Setting Failed again is not a change.
				$underpaid_fresh( $order->get_id() )->set_status( 'failed' );
			}
		);
		$t::same( 1, count( $mail ), 'one email sent' );
		$subject = isset( $mail[0]['subject'] ) ? $mail[0]['subject'] : '';
		$t::same( true, false !== strpos( $subject, $order->get_name() ), 'subject names the order' );
		$t::same( true, false !== strpos( $subject, '(test mode)' ), 'subject says test mode' );
		$t::same( true, false !== strpos( isset( $mail[0]['message'] ) ? $mail[0]['message'] : '', 'marked Failed' ), 'email says why' );

		// Back to Pending, then Failed again: that is a new change.
		$again = $store_email_catch(
			function () use ( $order, $underpaid_fresh ) {
				$underpaid_fresh( $order->get_id() )->set_status( 'pending' );
				$underpaid_fresh( $order->get_id() )->set_status( 'failed' );
			}
		);
		$t::same( 1, count( $again ), 'failing again sends one more' );
	}
);

$t::test(
	'Payment problem email: a live order marked Failed has no (test mode) in the subject, and none is sent while off',
	function () use ( $t, $store_email_catch, $store_email_on, $underpaid_mollie_use, $underpaid_place ) {
		$underpaid_mollie_use();
		$t::add( $t::product( 110, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'mollie', 110 );
		$order->update_meta_value( 'mode', 'live' );
		$off = $store_email_catch(
			function () use ( $order ) {
				$order->set_status( 'failed' );
			}
		);
		$t::same( 0, count( $off ), 'off: no email' );

		$store_email_on();
		$order->set_status( 'pending' );
		$on = $store_email_catch(
			function () use ( $order ) {
				$order->set_status( 'failed' );
			}
		);
		$t::same( 1, count( $on ), 'on: one email' );
		$t::same( false, false !== strpos( isset( $on[0]['subject'] ) ? $on[0]['subject'] : '', 'test mode' ), 'no test mode in a live order subject' );
	}
);
