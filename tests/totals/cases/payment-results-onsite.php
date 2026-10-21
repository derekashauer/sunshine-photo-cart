<?php
/**
 * Authorize.net: the card is charged from the checkout page itself, and Authorize.net answers
 * right away. Only an approved charge (Authorize.net's response code 1) means the money came in.
 * A declined card (2), an error (3) or a charge held for the store's review (4) is not a payment
 * yet, so the order must keep waiting for payment.
 *
 * Helpers are in payment-results-helpers.php. Nothing reaches Authorize.net: its replies are made
 * up and read from a local file.
 */

$t = 'SPC_Totals_Test';

// Submit the order at checkout with Authorize.net, which answers with $reply. Returns every
// request the site made to Authorize.net (as the Authorize.net library logged it).
$payment_results_anet_submit = function ( $reply ) use ( $t, $payment_results_submit ) {
	if ( ! class_exists( 'SPC_Payment_Method_Authorizenet' ) || ! class_exists( '\net\authorize\util\LogFactory' ) ) {
		$t::skip( 'Authorize.net add-on is not active' );
	}
	$folder = trailingslashit( get_temp_dir() ) . 'spc-payres-anet-' . wp_generate_password( 10, false );
	wp_mkdir_p( $folder . '/xml/v1' );
	$reply_file = $folder . '/xml/v1/request.api';
	$log_file   = $folder . '/request.log';
	file_put_contents( $reply_file, wp_json_encode( $reply ) );

	$logger  = \net\authorize\util\LogFactory::getLog( 'SunshineTotalsTest' );
	$old_log = $logger->getLogFile();
	$gateway = new class( 'file://' . $folder ) extends SPC_Payment_Method_Authorizenet {
		private $totals_endpoint;
		public function __construct( $endpoint ) {
			$this->totals_endpoint = $endpoint;
			$this->id              = 'authorizenet';
		}
		// The real setup() picks Authorize.net's own address. This one points at the local file.
		public function setup( $mode = '' ) {
			$values = array(
				'login_id'        => 'spctotals',
				'transaction_key' => 'spctotals',
				'execute_method'  => $this->totals_endpoint,
			);
			foreach ( $values as $name => $value ) {
				$property = new ReflectionProperty( 'SPC_Payment_Method_Authorizenet', $name );
				$property->setAccessible( true );
				$property->setValue( $this, $value );
			}
		}
	};

	// The checkout runs the payment step through this hook. Swap the real payment method for the
	// one above while the order is submitted, so the real one can't reach Authorize.net.
	$hook = 'sunshine_checkout_process_payment_authorizenet';
	$real = sunshine_get_payment_method_by_id( 'authorizenet' );
	$had  = $real ? has_filter( $hook, array( $real, 'process' ) ) : false;
	if ( false !== $had ) {
		remove_filter( $hook, array( $real, 'process' ), $had );
	}
	add_filter( $hook, array( $gateway, 'process' ) );
	$card = array(
		'authorizenet_card'       => '4111 1111 1111 1111',
		'authorizenet_expiration' => '12/30',
		'authorizenet_cvc'        => '123',
		'authorizenet_zipcode'    => '80202',
	);
	foreach ( $card as $key => $value ) {
		$_POST[ $key ] = $value;
	}
	$logger->setLogFile( $log_file );
	try {
		if ( $real && false !== has_filter( $hook, array( $real, 'process' ) ) ) {
			$t::skip( 'Could not hold back the real Authorize.net payment step' );
		}
		$payment_results_submit( null, 'Authorize.net submit' );
	} finally {
		$logger->setLogFile( $old_log );
		remove_filter( $hook, array( $gateway, 'process' ) );
		if ( false !== $had ) {
			add_filter( $hook, array( $real, 'process' ), $had );
		}
		foreach ( array_keys( $card ) as $key ) {
			unset( $_POST[ $key ] );
		}
		$log = file_exists( $log_file ) ? (string) file_get_contents( $log_file ) : '';
		foreach ( array( $log_file, $reply_file ) as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( array( $folder . '/xml/v1', $folder . '/xml', $folder ) as $dir ) {
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
	}
	$requests = array();
	if ( preg_match_all( '/Request to AnetApi: \n(\{.*)$/m', $log, $matches ) ) {
		foreach ( $matches[1] as $json ) {
			$decoded = json_decode( $json, true );
			if ( is_array( $decoded ) ) {
				$requests[] = reset( $decoded );
			}
		}
	}
	return $requests;
};

// Authorize.net's reply to a charge. $transaction is merged into the transaction part and
// $result replaces the overall result (Ok, or Error with its message).
$payment_results_anet_reply = function ( $transaction, $result = null ) {
	$reply = array(
		'transactionResponse' => array_merge(
			array(
				'responseCode'  => '1',
				'authCode'      => 'SPCTST',
				'transId'       => '60000000001',
				'accountNumber' => 'XXXX1111',
				'accountType'   => 'Visa',
				'messages'      => array(
					array(
						'code'        => '1',
						'description' => 'This transaction has been approved.',
					),
				),
			),
			(array) $transaction
		),
		'refId'               => 'spctest',
		'messages'            => $result ? $result : array(
			'resultCode' => 'Ok',
			'message'    => array(
				array(
					'code' => 'I00001',
					'text' => 'Successful.',
				),
			),
		),
	);
	if ( null === $transaction ) {
		unset( $reply['transactionResponse'] );
	}
	return $reply;
};

// How Authorize.net answers a declined card: the whole request is "Error" (E00027, the
// transaction was unsuccessful), and the transaction says code 2, declined, with no messages.
$payment_results_anet_declined = function () use ( $payment_results_anet_reply ) {
	$reply = $payment_results_anet_reply(
		array(
			'responseCode' => '2',
			'authCode'     => '000000',
			'transId'      => '0',
			'errors'       => array(
				array(
					'errorCode' => '2',
					'errorText' => 'This transaction has been declined.',
				),
			),
		),
		array(
			'resultCode' => 'Error',
			'message'    => array(
				array(
					'code' => 'E00027',
					'text' => 'The transaction was unsuccessful.',
				),
			),
		)
	);
	unset( $reply['transactionResponse']['messages'] );
	return $reply;
};

$payment_results_anet_use = function () use ( $payment_results_settings ) {
	$payment_results_settings(
		array(
			'authorizenet_active'           => 1,
			'authorizenet_mode'             => 'test',
			'authorizenet_transaction_type' => 'capture',
			'authorizenet_fee'              => 'none',
		)
	);
};

// The checkout errors shown to the customer, as one line of text.
$payment_results_anet_errors = function () {
	return wp_strip_all_tags( implode( ' | ', (array) SPC()->cart->get_errors() ) );
};

// The store has Authorize.net's fraud filters set to hold some charges for review. Authorize.net
// answers "Ok" with response code 4: reason 253 means the card was authorized but the money is
// on hold until the photographer approves it in Authorize.net; reason 252 means it was not even
// authorized yet. Either way no money has come in, and the photographer may still decline it,
// so the order must keep waiting for payment and not be finished (no receipt, no "New" order).
$t::test(
	'Authorize.net: a charge held for review leaves the order waiting for payment',
	function () use ( $t, $payment_results_anet_use, $payment_results_place, $payment_results_next_order, $payment_results_anet_submit, $payment_results_anet_reply, $payment_results_unpaid ) {
		$payment_results_anet_use();
		$t::tax( 10 );
		$product = $t::product( 100 );
		$held    = array(
			'253' => 'The transaction was accepted and was authorized, but is being held for merchant review.',
			'252' => 'Your order has been received. Thank you for your business!',
		);
		foreach ( $held as $reason => $text ) {
			$t::add( $product );
			// 100 + 10% tax = 110.
			$order    = $payment_results_place( 'authorizenet', 110, 'reason ' . $reason . ': ' );
			$requests = $payment_results_anet_submit(
				$payment_results_anet_reply(
					array(
						'responseCode' => '4',
						'transId'      => '6000000' . $reason,
						'messages'     => array(
							array(
								'code'        => $reason,
								'description' => $text,
							),
						),
					)
				)
			);
			$t::money( 110, isset( $requests[0]['transactionRequest']['amount'] ) ? $requests[0]['transactionRequest']['amount'] : null, 'reason ' . $reason . ': Authorize.net is asked for the order total' );
			$payment_results_unpaid( $order->get_id(), 'reason ' . $reason . ': ' );
			$payment_results_next_order();
		}
	}
);

$t::test(
	'Authorize.net: a declined card leaves the order waiting for payment and tells the customer',
	function () use ( $t, $payment_results_anet_use, $payment_results_place, $payment_results_anet_submit, $payment_results_anet_declined, $payment_results_unpaid, $payment_results_fresh, $payment_results_anet_errors ) {
		$payment_results_anet_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order    = $payment_results_place( 'authorizenet', 110 );
		$requests = $payment_results_anet_submit( $payment_results_anet_declined() );
		$t::same( 1, count( $requests ), 'the card was tried once' );
		$payment_results_unpaid( $order->get_id() );
		$t::same( true, false !== stripos( $payment_results_anet_errors(), 'declined' ), 'the customer is told the card was declined' );
		$t::same( '', (string) $payment_results_fresh( $order->get_id() )->get_meta_value( 'authorizenet_transaction_id' ), 'no Authorize.net transaction saved on the order' );
		$t::same( 1, count( (array) SPC()->cart->get_cart() ), 'cart is kept so the customer can try again' );
	}
);

// Authorize.net turns the request away before it reaches the card network (here: the store's
// login is wrong). There is no transaction at all, just an error.
$t::test(
	'Authorize.net: a refused request with no transaction leaves the order waiting for payment',
	function () use ( $t, $payment_results_anet_use, $payment_results_place, $payment_results_anet_submit, $payment_results_anet_reply, $payment_results_unpaid, $payment_results_anet_errors ) {
		$payment_results_anet_use();
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$order = $payment_results_place( 'authorizenet', 100 );
		$payment_results_anet_submit(
			$payment_results_anet_reply(
				null,
				array(
					'resultCode' => 'Error',
					'message'    => array(
						array(
							'code' => 'E00007',
							'text' => 'User authentication failed due to invalid authentication values.',
						),
					),
				)
			)
		);
		$payment_results_unpaid( $order->get_id() );
		$t::same( true, false !== strpos( $payment_results_anet_errors(), 'E00007' ), 'the customer sees the error' );
	}
);

// The first card is declined, the customer types in another card and submits again. The same
// order is charged the full amount the second time, is paid once, and the discount code on it is
// counted as used once.
// 100 - 10% code = 90, + 10% tax = 9, total 99.00 both times.
$t::test(
	'Authorize.net: a declined card, then a good card: the order is paid once for its full total',
	function () use ( $t, $payment_results_anet_use, $payment_results_place, $payment_results_anet_submit, $payment_results_anet_declined, $payment_results_anet_reply, $payment_results_fresh, $payment_results_unpaid ) {
		$payment_results_anet_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$code  = $t::discount( 'percent-total', 10 );
		$order = $payment_results_place( 'authorizenet', 99 );
		$first = $payment_results_anet_submit( $payment_results_anet_declined() );
		$t::money( 99, isset( $first[0]['transactionRequest']['amount'] ) ? $first[0]['transactionRequest']['amount'] : null, 'first card is asked for 99.00' );
		$payment_results_unpaid( $order->get_id(), 'after the decline: ' );

		// The checkout page is loaded again with the error showing, and the order submitted again.
		$t::new_request();
		$second = $payment_results_anet_submit( $payment_results_anet_reply( array( 'transId' => '60000000077' ) ) );
		$t::money( 99, isset( $second[0]['transactionRequest']['amount'] ) ? $second[0]['transactionRequest']['amount'] : null, 'second card is asked for 99.00' );
		$t::same( array(), (array) SPC()->cart->get_errors(), 'no checkout errors the second time' );
		$saved = $payment_results_fresh( $order->get_id() );
		$t::same( true, $saved->is_paid(), 'order is paid' );
		$t::money( 99, $saved->get_total(), 'order total is unchanged' );
		$t::same( '60000000077', (string) $saved->get_meta_value( 'authorizenet_transaction_id' ), 'the approved transaction is saved' );
		$discount = sunshine_get_discount_by_code( $code );
		$t::same( 1, $discount->get_use_count(), 'discount code counted as used once' );
	}
);
