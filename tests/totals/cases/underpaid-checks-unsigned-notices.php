<?php
/**
 * Payment notices the payment service didn't send, and Paystack's finish and cancel steps.
 *
 * Anyone can post to a PayFast or Systempay notice address with a real order number. A notice
 * without the store's signature (or, for PayFast, one PayFast doesn't confirm it sent) leaves
 * the order unpaid and goes only in the debug log: no order note, and no payment problem email
 * to the store owner, so strangers can't fill orders with notes or the owner's inbox with mail.
 *
 * Paystack: a paid order sends the customer receipt and the admin email, and a customer who
 * cancels on Paystack's page gets one plain "cancelled" note, not a declined payment report.
 *
 * Uses the helpers from underpaid-checks-helpers.php, underpaid-checks-redirect-gateways.php
 * and underpaid-checks-store-email.php, so run it with those files.
 */

$t = 'SPC_Totals_Test';

// The order's notes that contain $text.
$unsigned_notes = function ( $order_id, $text ) {
	$found = 0;
	foreach ( get_comments(
		array(
			'post_id' => $order_id,
			'type'    => 'sunshine_order_log',
		)
	) as $comment ) {
		if ( false !== strpos( $comment->comment_content, $text ) ) {
			$found++;
		}
	}
	return $found;
};

$t::test(
	'PayFast: an ITN with a signature that doesn\'t match adds no note and sends no email',
	function () use ( $t, $store_email_on, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_itn, $underpaid_payfast_notify, $underpaid_still_pending, $unsigned_notes ) {
		$options = array_merge( $underpaid_payfast_settings(), $store_email_on() );
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'payfast', 230 );
		// Signed with a passphrase that isn't the store's.
		$underpaid_payfast_notify( $order, $options, $underpaid_payfast_itn( $order, 115, 'not the passphrase' ) );
		$underpaid_still_pending( $order->get_id() );
		$t::same( 0, $unsigned_notes( $order->get_id(), 'Payfast' ), 'no PayFast note on the order' );
		$t::same( 0, $unsigned_notes( $order->get_id(), 'Email sent to admin: Admin Payment Problem' ), 'no email to the store owner' );
	}
);

$t::test(
	'PayFast: an ITN PayFast doesn\'t confirm it sent adds no note and sends no email',
	function () use ( $t, $store_email_on, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_itn, $underpaid_child, $underpaid_still_pending, $unsigned_notes ) {
		// No passphrase is set, so anyone can make a matching signature. PayFast's own
		// validate address is what says the notice is real.
		$options = array_merge( $underpaid_payfast_settings( '' ), $store_email_on() );
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'payfast', 230 );
		$underpaid_child(
			array(
				'method'   => 'payfast',
				'function' => 'notify',
				'options'  => $options,
				'get'      => array( 'sunshine_payfast_notify' => '1' ),
				'post'     => $underpaid_payfast_itn( $order, 115, '' ),
				'replies'  => array( array( '#^https://sandbox\.payfast\.co\.za/eng/query/validate$#', 200, 'INVALID' ) ),
				'orders'   => array( $order->get_id() ),
			)
		);
		$underpaid_still_pending( $order->get_id() );
		$t::same( 0, $unsigned_notes( $order->get_id(), 'Payfast' ), 'no PayFast note on the order' );
		$t::same( 0, $unsigned_notes( $order->get_id(), 'Email sent to admin: Admin Payment Problem' ), 'no email to the store owner' );
	}
);

$t::test(
	'Systempay: an IPN not signed with the store key adds no note and sends no email',
	function () use ( $t, $store_email_on, $underpaid_systempay_settings, $underpaid_place, $underpaid_systempay_form, $underpaid_systempay_ipn, $underpaid_systempay_notify, $underpaid_still_pending, $unsigned_notes ) {
		$options = array_merge( $underpaid_systempay_settings(), $store_email_on() );
		$t::add( $t::product( 60, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'systempay', 60 );
		$form  = $underpaid_systempay_form( $order );
		$underpaid_systempay_notify( $order, $options, $underpaid_systempay_ipn( $form, 3000, array(), 'not-the-store-key' ) );
		// No signature at all.
		$unsigned = $underpaid_systempay_ipn( $form, 3000 );
		unset( $unsigned['signature'] );
		$underpaid_systempay_notify( $order, $options, $unsigned );
		$underpaid_still_pending( $order->get_id() );
		$t::same( 0, $unsigned_notes( $order->get_id(), 'Systempay' ), 'no Systempay note on the order' );
		$t::same( 0, $unsigned_notes( $order->get_id(), 'Email sent to admin: Admin Payment Problem' ), 'no email to the store owner' );
	}
);

// A signed notice for the wrong amount is a real problem, so it still gets its note and email.
$t::test(
	'Systempay: a signed IPN for the wrong amount still adds a note and sends one email',
	function () use ( $t, $store_email_on, $underpaid_systempay_settings, $underpaid_place, $underpaid_systempay_form, $underpaid_systempay_ipn, $underpaid_systempay_notify, $underpaid_still_pending, $unsigned_notes ) {
		$options = array_merge( $underpaid_systempay_settings(), $store_email_on() );
		$t::add( $t::product( 60, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'systempay', 60 );
		$form  = $underpaid_systempay_form( $order );
		$underpaid_systempay_notify( $order, $options, $underpaid_systempay_ipn( $form, 3000 ) );
		$underpaid_still_pending( $order->get_id() );
		$t::same( 1, $unsigned_notes( $order->get_id(), 'A Systempay payment notice was not accepted' ), 'note on the order' );
		$t::same( 1, $unsigned_notes( $order->get_id(), 'Email sent to admin: Admin Payment Problem' ), 'one email to the store owner' );
	}
);

// Paystack holds back the receipts while the order waits for payment (order_notify()), so the
// step that finishes the order sends them.
$t::test(
	'Paystack: a paid order sends the receipts',
	function () use ( $t, $underpaid_paystack_use, $underpaid_place, $underpaid_paystack_start, $underpaid_paystack_return, $underpaid_fresh ) {
		$underpaid_paystack_use();
		$t::add( $t::product( 110, array( 'taxable' => false ) ) );
		$order    = $underpaid_place( 'paystack', 110 );
		$back     = $underpaid_paystack_start( $order );
		$notified = array();
		$count    = function ( $notified_order ) use ( &$notified ) {
			$notified[] = (int) $notified_order->get_id();
		};
		add_action( 'sunshine_order_notify', $count, 1 );
		try {
			$underpaid_paystack_return( $back, 'spcref' . $order->get_id(), 11000 );
		} finally {
			remove_action( 'sunshine_order_notify', $count, 1 );
		}
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'order is paid' );
		$t::same( array( (int) $order->get_id() ), $notified, 'receipts sent once' );
	}
);

// The customer presses cancel on Paystack's page. The site asks Paystack whether it was paid
// after all (status abandoned), then cancels the order.
$t::test(
	'Paystack: cancelling adds one cancelled note and no declined payment report',
	function () use ( $t, $underpaid_paystack_use, $underpaid_place, $underpaid_paystack_start, $underpaid_run, $underpaid_fresh, $unsigned_notes ) {
		foreach ( array( 'abandoned' => 200, 'unreachable' => 500 ) as $case => $code ) {
			$t::reset();
			$underpaid_paystack_use();
			$t::add( $t::product( 110, array( 'taxable' => false ) ) );
			$order = $underpaid_place( 'paystack', 110 );
			$underpaid_paystack_start( $order );
			SPC()->session->set( 'checkout_order_id', $order->get_id() );
			$get = array(
				'paystack_cancel' => wp_create_nonce( 'paystack_cancel_' . $order->get_id() ),
				'order_id'        => (string) $order->get_id(),
			);
			foreach ( $get as $key => $value ) {
				$_GET[ $key ] = $value;
			}
			$problems = 0;
			$count    = function () use ( &$problems ) {
				$problems++;
			};
			add_action( 'sunshine_order_payment_problem', $count );
			try {
				$underpaid_run(
					function () {
						sunshine_get_payment_method_by_id( 'paystack' )->cancel();
					},
					function ( $request ) use ( $code ) {
						if ( 0 !== strpos( $request['path'], '/transaction/verify/' ) ) {
							return null;
						}
						return array(
							$code,
							array(
								'status' => 200 === $code,
								'data'   => array( 'status' => 'abandoned' ),
							),
						);
					},
					'Paystack cancel'
				);
			} finally {
				remove_action( 'sunshine_order_payment_problem', $count );
				foreach ( array_keys( $get ) as $key ) {
					unset( $_GET[ $key ] );
				}
			}
			$t::same( 'cancelled', $underpaid_fresh( $order->get_id() )->get_status(), $case . ': order cancelled' );
			$t::same( 1, $unsigned_notes( $order->get_id(), 'Customer cancelled the Paystack payment' ), $case . ': one cancelled note' );
			$t::same( 0, $unsigned_notes( $order->get_id(), 'Paystack payment' ) - 1, $case . ': no other Paystack payment note' );
			$t::same( 0, $problems, $case . ': no payment problem reported' );
		}
	}
);
