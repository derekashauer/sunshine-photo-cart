<?php
/**
 * The Admin Payment Problem email: the store owner hears about a payment Sunshine refused (wrong
 * amount, currency, order or account) and about orders marked Failed, so they can check the
 * payment before refunding or marking the order paid. It is off until the store owner turns it
 * on, because not every store wants these.
 *
 * Uses the Mollie, PayFast and Systempay helpers from underpaid-checks-redirect-gateways.php, so
 * run it with that file and underpaid-checks-helpers.php. Mail is caught with pre_wp_mail and
 * never sent.
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

// The store owner turned the email on and gave it its own address. The admin order receipt goes
// somewhere else, which this email must not use.
$store_email_on = function () use ( $t ) {
	$t::option( 'email_admin-payment-problem_active', 1 );
	$t::option( 'email_admin-payment-problem_recipients', 'owner@example.com' );
	$t::option( 'email_admin-receipt_recipients', 'orders@example.com' );
	return array(
		'email_admin-payment-problem_active'     => 1,
		'email_admin-payment-problem_recipients' => 'owner@example.com',
		'email_admin-receipt_recipients'         => 'orders@example.com',
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

// Who gets it: the addresses typed for this email, never the admin order receipt's. Left empty,
// the site admin email gets it so a turned-on email is never sent to nobody.
$t::test(
	'Payment problem email: goes to its own recipients, or the site admin email when none are set',
	function () use ( $t, $store_email_catch, $store_email_on ) {
		$store_email_on();
		$t::add( $t::product( 30 ) );
		$t::calculate();
		$order = $t::place_order();
		$to    = function ( $mail ) {
			$list = array();
			foreach ( $mail as $one ) {
				$list = array_merge( $list, (array) $one['to'] );
			}
			return implode( ',', $list );
		};

		$mail = $store_email_catch(
			function () use ( $order ) {
				sunshine_order_payment_problem( $order, 'First reason.', 'test' );
			}
		);
		$t::same( 'owner@example.com', $to( $mail ), 'sent to the address set for this email only' );

		$t::option( 'email_admin-payment-problem_recipients', '' );
		$mail = $store_email_catch(
			function () use ( $order ) {
				sunshine_order_payment_problem( $order, 'Second reason.', 'test' );
			}
		);
		$t::same( get_bloginfo( 'admin_email' ), $to( $mail ), 'no address set: the site admin email' );

		$t::same( true, SPC()->emails->get_email_by_id( 'admin-payment-problem' )->allows_custom_recipients(), 'the settings show a Recipient(s) box' );
	}
);

/*
 * "Send when": the store owner picks which problems this email is for. Left alone, it is for a
 * payment that didn't match the order (the customer may have paid, but the order stayed unpaid)
 * and a payment service marking an order Failed. A declined or cancelled payment and an admin
 * marking an order Failed by hand are off by default, because those need no checking in the
 * payment account.
 */

// Make each kind of problem happen once on $order and count the emails each one sent. $label
// keeps the reasons apart, since the same reason is only emailed once per order.
$store_email_kinds = function ( $order, $label ) use ( $store_email_catch, $underpaid_fresh ) {
	$sent = array();
	$runs = array(
		'mismatch' => function () use ( $order, $label ) {
			sunshine_order_payment_problem( $order, 'Wrong amount ' . $label . '.', 'test' );
		},
		'declined' => function () use ( $order, $label ) {
			sunshine_order_payment_problem( $order, 'Declined ' . $label . '.', 'test', 'declined' );
		},
		'failed'   => function () use ( $order, $underpaid_fresh ) {
			$underpaid_fresh( $order->get_id() )->set_status( 'failed' );
		},
		'manual'   => function () use ( $order, $underpaid_fresh ) {
			// What the order screen and the bulk action do.
			$underpaid_fresh( $order->get_id() )->set_status( 'failed', '', true );
		},
	);
	foreach ( $runs as $kind => $run ) {
		$underpaid_fresh( $order->get_id() )->set_status( 'pending' );
		$sent[ $kind ] = count( $store_email_catch( $run ) );
	}
	return $sent;
};

$t::test(
	'Payment problem email: never saved, Send when is a payment that didn\'t match and a payment service marking Failed',
	function () use ( $t, $store_email_on, $store_email_kinds ) {
		$store_email_on();
		$t::same( false, get_option( 'sunshine_email_admin-payment-problem_events' ), 'no saved Send when on this site' );
		$t::add( $t::product( 30 ) );
		$t::calculate();
		$order = $t::place_order();
		$t::same(
			array(
				'mismatch' => 1,
				'declined' => 0,
				'failed'   => 1,
				'manual'   => 0,
			),
			$store_email_kinds( $order, 'defaults' ),
			'emails sent for each kind'
		);

		// The settings screen shows the same two ticked, so saving it to turn the email on keeps them.
		$email  = SPC()->emails->get_email_by_id( 'admin-payment-problem' );
		$fields = $email->default_options( array() );
		$field  = null;
		foreach ( $fields as $one ) {
			if ( isset( $one['id'] ) && 'email_admin-payment-problem_events' === $one['id'] ) {
				$field = $one;
			}
		}
		$t::same( 'checkbox_multi', isset( $field['type'] ) ? $field['type'] : '', 'Send when is a list of checkboxes' );
		$t::same( array( 'mismatch', 'declined', 'failed', 'manual' ), array_keys( isset( $field['options'] ) ? $field['options'] : array() ), 'one checkbox for each kind' );
		$t::same( array( 'mismatch', 'failed' ), isset( $field['default'] ) ? $field['default'] : null, 'never saved: the defaults are ticked' );
	}
);

$t::test(
	'Payment problem email: each Send when choice on its own sends only for that kind, and none ticked sends nothing',
	function () use ( $t, $store_email_on, $store_email_kinds ) {
		$store_email_on();
		$t::add( $t::product( 30 ) );
		$t::calculate();
		$order = $t::place_order();
		$kinds = array( 'mismatch', 'declined', 'failed', 'manual' );
		foreach ( $kinds as $kind ) {
			$t::option( 'email_admin-payment-problem_events', array( $kind ) );
			$expected          = array_fill_keys( $kinds, 0 );
			$expected[ $kind ] = 1;
			$t::same( $expected, $store_email_kinds( $order, 'only ' . $kind ), 'only ' . $kind . ' ticked' );
		}

		$t::option( 'email_admin-payment-problem_events', array( 'declined', 'manual' ) );
		$t::same(
			array(
				'mismatch' => 0,
				'declined' => 1,
				'failed'   => 0,
				'manual'   => 1,
			),
			$store_email_kinds( $order, 'two ticked' ),
			'declined and manual ticked'
		);

		// Saving the settings with every box unticked saves an empty value. That means none.
		$t::option( 'email_admin-payment-problem_events', '' );
		$t::same( array_fill_keys( $kinds, 0 ), $store_email_kinds( $order, 'none ticked' ), 'none ticked: nothing sent' );
		$fields = SPC()->emails->get_email_by_id( 'admin-payment-problem' )->default_options( array() );
		$field  = array();
		foreach ( $fields as $one ) {
			if ( isset( $one['id'] ) && 'email_admin-payment-problem_events' === $one['id'] ) {
				$field = $one;
			}
		}
		$t::same( true, empty( $field['default'] ), 'none ticked: the settings screen does not tick the defaults again' );
	}
);

// The order screen's Update button and the orders list's bulk action are an admin marking the
// order Failed by hand. The order screen only loads in wp-admin, so it is loaded here, its hooks
// are taken back off, and its steps are called as an administrator.
$store_email_admin = function ( $callback ) use ( $t ) {
	if ( ! class_exists( 'SPC_Admin_Order' ) ) {
		global $wp_filter;
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/sunshine-order.php';
		if ( isset( $SPC_Admin_Order ) && is_object( $SPC_Admin_Order ) ) {
			foreach ( $wp_filter as $hook => $hook_object ) {
				foreach ( $hook_object->callbacks as $priority => $callbacks ) {
					foreach ( $callbacks as $one ) {
						if ( is_array( $one['function'] ) && $one['function'][0] === $SPC_Admin_Order ) {
							remove_filter( $hook, $one['function'], $priority );
						}
					}
				}
			}
		}
		remove_action( 'wp', 'sunshine_admin_order_check' );
	}
	$admin   = ( new ReflectionClass( 'SPC_Admin_Order' ) )->newInstanceWithoutConstructor();
	$user_id = wp_insert_user(
		array(
			'user_login' => 'spcmailadmin' . wp_rand( 100000, 999999 ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'administrator',
		)
	);
	$t::track( 'user', $user_id );
	$before = get_current_user_id();
	wp_set_current_user( $user_id );
	try {
		$callback( $admin );
	} finally {
		wp_set_current_user( $before );
		$_POST = array();
	}
};

$t::test(
	'Payment problem email: an admin marking an order Failed on the order screen or with the bulk action is "by hand"',
	function () use ( $t, $store_email_on, $store_email_catch, $store_email_admin, $underpaid_fresh ) {
		$store_email_on();
		$t::add( $t::product( 30 ) );
		$t::calculate();
		$order      = $t::place_order();
		$order_id   = $order->get_id();
		$screen     = function ( $admin ) use ( $order_id ) {
			$_POST = array(
				'post_ID'      => (string) $order_id,
				'post_type'    => 'sunshine-order',
				'order_status' => 'failed',
				'_wpnonce'     => wp_create_nonce( 'update-post_' . $order_id ),
			);
			$admin->save_post( $order_id );
		};
		$bulk       = function ( $admin ) use ( $order_id ) {
			$admin->handle_bulk_actions( admin_url( 'edit.php' ), 'sunshine_order_status_failed', array( $order_id ) );
		};
		$count_sent = function ( $step ) use ( $store_email_catch, $store_email_admin, $underpaid_fresh, $order_id ) {
			$underpaid_fresh( $order_id )->set_status( 'pending' );
			return count(
				$store_email_catch(
					function () use ( $store_email_admin, $step ) {
						$store_email_admin( $step );
					}
				)
			);
		};

		// Never saved: by hand is off.
		$t::same( 0, $count_sent( $screen ), 'defaults: order screen sends nothing' );
		$t::same( 'failed', $underpaid_fresh( $order_id )->get_status(), 'the order screen did mark it Failed' );
		$t::same( 0, $count_sent( $bulk ), 'defaults: bulk action sends nothing' );

		// Only "by hand" ticked.
		$t::option( 'email_admin-payment-problem_events', array( 'manual' ) );
		$t::same( 1, $count_sent( $screen ), 'by hand ticked: order screen sends one' );
		$t::same( 1, $count_sent( $bulk ), 'by hand ticked: bulk action sends one' );

		// Only "payment service" ticked: neither admin step counts as one.
		$t::option( 'email_admin-payment-problem_events', array( 'failed' ) );
		$t::same( 0, $count_sent( $screen ), 'payment service ticked: order screen sends nothing' );
		$t::same( 0, $count_sent( $bulk ), 'payment service ticked: bulk action sends nothing' );
	}
);

// Mollie's webhook marks the order Failed when Mollie says the payment failed or expired. That
// is a payment service marking it Failed, not an admin.
$t::test(
	'Payment problem email: Mollie marking an order Failed is a payment service, not by hand',
	function () use ( $t, $store_email_catch, $store_email_on, $underpaid_mollie_use, $underpaid_place, $underpaid_mollie_webhook, $underpaid_mollie_payment, $underpaid_fresh ) {
		$underpaid_mollie_use();
		$store_email_on();
		$t::add( $t::product( 110, array( 'taxable' => false ) ) );
		$expired = function ( $id, $order ) use ( $underpaid_mollie_payment ) {
			$payment           = $underpaid_mollie_payment( $id, $order->get_id(), '110.00' );
			$payment['status'] = 'expired';
			unset( $payment['paidAt'] );
			return $payment;
		};

		$t::option( 'email_admin-payment-problem_events', array( 'manual' ) );
		$order = $underpaid_place( 'mollie', 110 );
		$order->update_meta_value( 'mollie_payment_id', 'tr_spcmailexp1' );
		$mail = $store_email_catch(
			function () use ( $underpaid_mollie_webhook, $expired, $order ) {
				$underpaid_mollie_webhook( $expired( 'tr_spcmailexp1', $order ) );
			}
		);
		$t::same( 'failed', $underpaid_fresh( $order->get_id() )->get_status(), 'Mollie marked the order Failed' );
		$t::same( 0, count( $mail ), 'only by hand ticked: nothing sent' );

		$t::option( 'email_admin-payment-problem_events', array( 'failed' ) );
		$underpaid_fresh( $order->get_id() )->set_status( 'pending' );
		$mail = $store_email_catch(
			function () use ( $underpaid_mollie_webhook, $expired, $order ) {
				$underpaid_mollie_webhook( $expired( 'tr_spcmailexp1', $order ) );
			}
		);
		$t::same( 'failed', $underpaid_fresh( $order->get_id() )->get_status(), 'Mollie marked the order Failed again' );
		$t::same( 1, count( $mail ), 'payment service ticked: one email' );
	}
);

// A cancelled or refused payment is a decline: off unless ticked. PayFast and Systempay notices
// run in a separate process that never sends mail, so the order note the email adds shows
// whether it went out.
$t::test(
	'Payment problem email: a PayFast ITN for a cancelled payment is a decline',
	function () use ( $t, $store_email_on, $underpaid_payfast_settings, $underpaid_place, $underpaid_next_order, $underpaid_payfast_itn, $underpaid_payfast_notify, $underpaid_still_pending, $underpaid_payfast_log ) {
		$options   = array_merge( $underpaid_payfast_settings(), $store_email_on() );
		$product   = $t::product( 230, array( 'taxable' => false ) );
		$cancelled = array( 'payment_status' => 'CANCELLED' );

		$t::add( $product );
		$order = $underpaid_place( 'payfast', 230 );
		$underpaid_payfast_notify( $order, $options, $underpaid_payfast_itn( $order, 230, 'sunshine totals', $cancelled ) );
		$underpaid_still_pending( $order->get_id() );
		$t::same( 0, count( $underpaid_payfast_log( $order->get_id(), 'Email sent to admin: Admin Payment Problem' ) ), 'defaults: no email' );

		$underpaid_next_order();
		$t::add( $product );
		$order = $underpaid_place( 'payfast', 230 );
		$underpaid_payfast_notify( $order, array_merge( $options, array( 'email_admin-payment-problem_events' => array( 'declined' ) ) ), $underpaid_payfast_itn( $order, 230, 'sunshine totals', $cancelled ) );
		$t::same( 1, count( $underpaid_payfast_log( $order->get_id(), 'Email sent to admin: Admin Payment Problem' ) ), 'declined ticked: one email' );

		// A payment for the wrong amount is still a payment that didn't match.
		$underpaid_next_order();
		$t::add( $product );
		$order = $underpaid_place( 'payfast', 230 );
		$underpaid_payfast_notify( $order, array_merge( $options, array( 'email_admin-payment-problem_events' => array( 'declined' ) ) ), $underpaid_payfast_itn( $order, 115, 'sunshine totals' ) );
		$t::same( 0, count( $underpaid_payfast_log( $order->get_id(), 'Email sent to admin: Admin Payment Problem' ) ), 'declined ticked: wrong amount sends nothing' );
	}
);

$t::test(
	'Payment problem email: a Systempay IPN for a refused payment is a decline',
	function () use ( $t, $store_email_on, $underpaid_systempay_settings, $underpaid_place, $underpaid_next_order, $underpaid_systempay_form, $underpaid_systempay_ipn, $underpaid_systempay_notify, $underpaid_still_pending, $underpaid_payfast_log ) {
		$options = array_merge( $underpaid_systempay_settings(), $store_email_on() );
		$product = $t::product( 60, array( 'taxable' => false ) );
		$refused = array(
			'vads_trans_status' => 'REFUSED',
			'vads_result'       => '05',
			'vads_auth_result'  => '05',
		);

		$t::add( $product );
		$order = $underpaid_place( 'systempay', 60 );
		$underpaid_systempay_notify( $order, $options, $underpaid_systempay_ipn( $underpaid_systempay_form( $order ), 6000, $refused ) );
		$underpaid_still_pending( $order->get_id() );
		$t::same( 0, count( $underpaid_payfast_log( $order->get_id(), 'Email sent to admin: Admin Payment Problem' ) ), 'defaults: no email' );

		$underpaid_next_order();
		$t::add( $product );
		$order = $underpaid_place( 'systempay', 60 );
		$underpaid_systempay_notify( $order, array_merge( $options, array( 'email_admin-payment-problem_events' => array( 'declined' ) ) ), $underpaid_systempay_ipn( $underpaid_systempay_form( $order ), 6000, $refused ) );
		$t::same( 1, count( $underpaid_payfast_log( $order->get_id(), 'Email sent to admin: Admin Payment Problem' ) ), 'declined ticked: one email' );

		// A payment for less than the order, with defaults: still emailed.
		$underpaid_next_order();
		$t::add( $product );
		$order = $underpaid_place( 'systempay', 60 );
		$underpaid_systempay_notify( $order, $options, $underpaid_systempay_ipn( $underpaid_systempay_form( $order ), 3000 ) );
		$t::same( 1, count( $underpaid_payfast_log( $order->get_id(), 'Email sent to admin: Admin Payment Problem' ) ), 'defaults: short payment sends one' );
	}
);
