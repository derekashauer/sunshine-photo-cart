<?php
/**
 * The payment checks in the gateway add-ons: Mollie, PayFast, Systempay, QuickPay, Paystack,
 * Mercado Pago (card form and Checkout Pro) and Authorize.net.
 *
 * Same rule as the core gateways: an order is only marked paid when the gateway says the money
 * came in, for the whole order total, in the store's currency, to the store's own account, for
 * this order. Anything else leaves it "pending".
 *
 * Helpers are in underpaid-checks-helpers.php. Nothing reaches any gateway.
 */

$t = 'SPC_Totals_Test';

// The order is still waiting for payment and was not finished.
$underpaid_still_pending = function ( $order_id, $label = '' ) use ( $t, $underpaid_fresh, $underpaid_finished ) {
	$saved = $underpaid_fresh( $order_id );
	$t::same( 'pending', $saved->get_status(), $label . 'order still waits for payment' );
	$t::same( false, $underpaid_finished( $saved ), $label . 'order not finished' );
};

/*
 * Mollie. The customer pays on Mollie's page. Mollie then calls the site's webhook with the
 * payment ID, the site looks the payment up at Mollie and finishes the order it was made for
 * (SPC_Payment_Method_Mollie::webhook).
 */

$underpaid_mollie_use = function () use ( $t, $underpaid_settings ) {
	if ( ! class_exists( 'SPC_Payment_Method_Mollie' ) ) {
		$t::skip( 'Mollie add-on is not active' );
	}
	$underpaid_settings(
		array(
			'mollie_active'          => 1,
			'mollie_mode'            => 'test',
			'mollie_test_secret_key' => 'test_' . str_repeat( 'spctotals', 4 ),
			'mollie_fee'             => 'none',
		)
	);
};

// A Mollie payment as Mollie's API returns it, paid, made for order $order_id.
$underpaid_mollie_payment = function ( $id, $order_id, $value, $currency = 'USD' ) {
	return array(
		'resource'    => 'payment',
		'id'          => $id,
		'mode'        => 'test',
		'status'      => 'paid',
		'paidAt'      => '2026-10-17T10:00:00+00:00',
		'amount'      => array(
			'value'    => $value,
			'currency' => $currency,
		),
		'description' => 'Order #' . $order_id,
		'metadata'    => array( 'order_id' => $order_id ),
		'_links'      => array(
			'self' => array(
				'href' => 'https://api.mollie.com/v2/payments/' . $id,
				'type' => 'application/hal+json',
			),
		),
	);
};

// Mollie calls the webhook for payment $payment['id']. The look up of the payment is answered
// by a stand-in for Mollie's connection, so nothing is sent.
$underpaid_mollie_webhook = function ( $payment ) use ( $t, $underpaid_run ) {
	require_once plugin_dir_path( ( new ReflectionClass( 'SPC_Payment_Method_Mollie' ) )->getFileName() ) . 'vendor/autoload.php';
	$connection = new class( $payment ) implements \Mollie\Api\HttpAdapter\MollieHttpAdapterInterface {
		public $sent = array();
		private $payment;
		public function __construct( $payment ) {
			$this->payment = $payment;
		}
		public function send( $http_method, $url, $headers, $http_body ) {
			$this->sent[] = $http_method . ' ' . $url;
			if ( 'GET' === $http_method && preg_match( '#/v2/payments/' . preg_quote( $this->payment['id'], '#' ) . '$#', $url ) ) {
				return json_decode( wp_json_encode( $this->payment ) );
			}
			throw new \Mollie\Api\Exceptions\ApiException( 'Blocked by the totals tests' );
		}
		public function versionString() {
			return 'SunshineTotalsTest/1.0';
		}
	};
	$client     = new \Mollie\Api\MollieApiClient( $connection );
	$client->setApiKey( 'test_' . str_repeat( 'spctotals', 4 ) );
	// The real Mollie payment method, except setup() hands it the client above. Its constructor
	// is skipped so no hooks are added twice.
	$mollie = new class( $client ) extends SPC_Payment_Method_Mollie {
		private $totals_client;
		public function __construct( $client ) {
			$this->totals_client = $client;
			$this->id            = 'mollie';
		}
		public function setup( $mode = '' ) {
			$property = new ReflectionProperty( 'SPC_Payment_Method_Mollie', 'mollie' );
			$property->setAccessible( true );
			$property->setValue( $this, $this->totals_client );
		}
	};
	$_GET['sunshine_mollie_webhook'] = '1';
	$_POST['id']                     = $payment['id'];
	try {
		$underpaid_run(
			function () use ( $mollie ) {
				$mollie->webhook();
			},
			null,
			'Mollie webhook'
		);
	} finally {
		unset( $_GET['sunshine_mollie_webhook'], $_POST['id'] );
	}
	return $connection->sent;
};

$t::test(
	'Mollie: a paid payment of the order total completes the order',
	function () use ( $t, $underpaid_mollie_use, $underpaid_place, $underpaid_mollie_webhook, $underpaid_mollie_payment, $underpaid_fresh ) {
		$underpaid_mollie_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $underpaid_place( 'mollie', 110 );
		$sent  = $underpaid_mollie_webhook( $underpaid_mollie_payment( 'tr_spcfull', $order->get_id(), '110.00' ) );
		$t::same( 1, count( $sent ), 'the payment was looked up at Mollie' );
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'order is paid' );
	}
);

// The customer was sent to Mollie with one $50 print (55 with tax), came back and added a
// second print. The same order is now 110, but the first Mollie payment (55) is still open,
// and that is the one they paid.
$t::test(
	'Mollie: a payment smaller than the order leaves it unpaid',
	function () use ( $t, $underpaid_mollie_use, $underpaid_place, $underpaid_mollie_webhook, $underpaid_mollie_payment, $underpaid_still_pending ) {
		$underpaid_mollie_use();
		$t::tax( 10 );
		$t::add( $t::product( 50 ), 2 );
		$order = $underpaid_place( 'mollie', 110 );
		$underpaid_mollie_webhook( $underpaid_mollie_payment( 'tr_spcshort', $order->get_id(), '55.00' ) );
		$underpaid_still_pending( $order->get_id() );
	}
);

$t::test(
	'Mollie: a payment in another currency leaves the order unpaid',
	function () use ( $t, $underpaid_mollie_use, $underpaid_place, $underpaid_mollie_webhook, $underpaid_mollie_payment, $underpaid_still_pending ) {
		$underpaid_mollie_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $underpaid_place( 'mollie', 110 );
		$underpaid_mollie_webhook( $underpaid_mollie_payment( 'tr_spceuro', $order->get_id(), '110.00', 'EUR' ) );
		$underpaid_still_pending( $order->get_id() );
	}
);

// Mollie finishes the order named in the payment itself, so a payment for one order can't be
// used to finish another.
$t::test(
	'Mollie: a payment made for another order does not complete this one',
	function () use ( $t, $underpaid_mollie_use, $underpaid_place, $underpaid_next_order, $underpaid_mollie_webhook, $underpaid_mollie_payment, $underpaid_still_pending ) {
		$underpaid_mollie_use();
		$t::tax( 10 );
		$product = $t::product( 100 );
		$t::add( $product );
		$first = $underpaid_place( 'mollie', 110 );
		$underpaid_next_order();
		$t::add( $product );
		$second = $underpaid_place( 'mollie', 110 );
		$underpaid_mollie_webhook( $underpaid_mollie_payment( 'tr_spcfirst', $first->get_id(), '110.00' ) );
		$underpaid_still_pending( $second->get_id(), 'other order: ' );
	}
);

/*
 * PayFast. The customer is sent to PayFast with a form. PayFast then posts an ITN to the site
 * (SPC_Payment_Method_Payfast::notify), signed with the store's passphrase when it has one, and
 * sends the customer back to the return link (success()). Both end with exit, so they run in a
 * separate process. PayFast's own guide says to check the ITN's amount and payment status, and
 * to confirm it with PayFast, before treating an order as paid.
 */

$underpaid_payfast_settings = function ( $passphrase = 'sunshine totals' ) use ( $t, $underpaid_settings ) {
	$t::require_plugin( 'sunshine_payment_method_payfast', 'PayFast add-on' );
	return $underpaid_settings(
		array(
			'currency'                  => 'ZAR',
			'payfast_active'            => 1,
			'payfast_mode'              => 'test',
			'payfast_merchant_id_test'  => '10000100',
			'payfast_merchant_key_test' => '46f0cd694581a',
			'payfast_passphrase_test'   => $passphrase,
			'payfast_fee'               => 'none',
		)
	);
};

// The PayFast form fields for the order, as the customer's browser gets them.
$underpaid_payfast_form = function ( $order ) use ( $underpaid_run ) {
	$payfast = sunshine_get_payment_method_by_id( 'payfast' );
	$fields  = null;
	$grab    = function ( $args ) use ( &$fields ) {
		$fields = $args;
		throw new SPC_Totals_Test_Done();
	};
	add_filter( 'sunshine_payfast_args', $grab, PHP_INT_MAX );
	try {
		$underpaid_run(
			function () use ( $payfast, $order ) {
				$payfast->redirect_to_payment( $order );
			},
			null,
			'PayFast form'
		);
	} finally {
		remove_filter( 'sunshine_payfast_args', $grab, PHP_INT_MAX );
	}
	if ( empty( $fields ) ) {
		throw new Exception( 'No PayFast form was built' );
	}
	return $fields;
};

// An ITN from PayFast about a payment of $amount rand for the order. $changes are applied
// before signing; $after_signing are applied after (a changed message).
$underpaid_payfast_itn = function ( $order, $amount, $passphrase, $changes = array(), $after_signing = array() ) {
	$fields = array_merge(
		array(
			'm_payment_id'     => (string) $order->get_id(),
			'pf_payment_id'    => '1089250',
			'payment_status'   => 'COMPLETE',
			'item_name'        => $order->get_name(),
			'item_description' => '',
			'amount_gross'     => number_format( $amount, 2, '.', '' ),
			'amount_fee'       => '-2.53',
			'amount_net'       => number_format( $amount - 2.53, 2, '.', '' ),
			'custom_str1'      => '',
			'custom_int1'      => '',
			'name_first'       => 'Totals',
			'name_last'        => 'Test',
			'email_address'    => 'totals-test@example.com',
			'merchant_id'      => '10000100',
		),
		$changes
	);
	// PayFast signs every field in the order sent, then the passphrase when there is one.
	$pairs = array();
	foreach ( $fields as $key => $value ) {
		$pairs[] = $key . '=' . urlencode( trim( (string) $value ) );
	}
	$text = implode( '&', $pairs );
	if ( '' !== (string) $passphrase ) {
		$text .= '&passphrase=' . urlencode( trim( $passphrase ) );
	}
	$fields['signature'] = md5( $text );
	return array_merge( $fields, $after_signing );
};

// PayFast posts the ITN to the site.
$underpaid_payfast_notify = function ( $order, $options, $fields ) use ( $underpaid_child ) {
	return $underpaid_child(
		array(
			'method'   => 'payfast',
			'function' => 'notify',
			'options'  => $options,
			'get'      => array( 'sunshine_payfast_notify' => '1' ),
			'post'     => $fields,
			// PayFast's answer when the site sends the ITN back to check PayFast sent it.
			'replies'  => array( array( '#^https://sandbox\.payfast\.co\.za/eng/query/validate$#', 200, 'VALID' ) ),
			'orders'   => array( $order->get_id() ),
		)
	);
};

$t::test(
	'PayFast: a signed ITN for the order total completes the order',
	function () use ( $t, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_form, $underpaid_payfast_itn, $underpaid_payfast_notify, $underpaid_fresh, $underpaid_warnings_from ) {
		$options = $underpaid_payfast_settings();
		$t::tax( 15 );
		$t::add( $t::product( 200 ) );
		// 200 + 15% VAT = 230 rand.
		$order  = $underpaid_place( 'payfast', 230 );
		$fields = $underpaid_payfast_form( $order );
		$t::money( 230, $fields['amount'], 'PayFast is asked for the order total' );
		$child = $underpaid_payfast_notify( $order, $options, $underpaid_payfast_itn( $order, 230, 'sunshine totals' ) );
		$saved = $underpaid_fresh( $order->get_id() );
		$t::same( true, $saved->is_paid(), 'order is paid' );
		$t::same( '1089250', (string) $saved->get_meta_value( 'payfast_payment_id' ), 'PayFast payment saved on the order' );
		$t::same( array(), $underpaid_warnings_from( $child, 'sunshine-payfast' ), 'no PHP warnings from the ITN' );
	}
);

// The customer was sent to PayFast for one print (115), came back, added a second, and the same
// order became 230. They then paid the first PayFast page: a real, signed ITN for 115.
$t::test(
	'PayFast: a signed ITN for less than the order leaves it unpaid',
	function () use ( $t, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_itn, $underpaid_payfast_notify, $underpaid_still_pending ) {
		$options = $underpaid_payfast_settings();
		$t::tax( 15 );
		$t::add( $t::product( 100 ), 2 );
		$order = $underpaid_place( 'payfast', 230 );
		$underpaid_payfast_notify( $order, $options, $underpaid_payfast_itn( $order, 115, 'sunshine totals' ) );
		$underpaid_still_pending( $order->get_id() );
	}
);

// PayFast also sends an ITN when a payment is cancelled.
$t::test(
	'PayFast: an ITN for a cancelled payment leaves the order unpaid',
	function () use ( $t, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_itn, $underpaid_payfast_notify, $underpaid_still_pending, $underpaid_declined_note ) {
		$options = $underpaid_payfast_settings();
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'payfast', 230 );
		$underpaid_payfast_notify( $order, $options, $underpaid_payfast_itn( $order, 230, 'sunshine totals', array( 'payment_status' => 'CANCELLED' ) ) );
		$underpaid_still_pending( $order->get_id() );
		$underpaid_declined_note( $order->get_id() );
	}
);

// A PayFast passphrase is optional. Without one, an ITN is signed with nothing secret, so a
// payment into a different PayFast account (its merchant ID is in the ITN) looks just as real.
$t::test(
	'PayFast: a payment to another PayFast account leaves the order unpaid',
	function () use ( $t, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_itn, $underpaid_payfast_notify, $underpaid_still_pending ) {
		$options = $underpaid_payfast_settings( '' );
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'payfast', 230 );
		$underpaid_payfast_notify( $order, $options, $underpaid_payfast_itn( $order, 230, '', array( 'merchant_id' => '10099999' ) ) );
		$underpaid_still_pending( $order->get_id() );
	}
);

// A real ITN for the first order, with its order number changed to the second: the signature
// no longer matches, so the second order is not finished.
$t::test(
	'PayFast: an ITN changed to point at another order leaves that order unpaid',
	function () use ( $t, $underpaid_payfast_settings, $underpaid_place, $underpaid_next_order, $underpaid_payfast_itn, $underpaid_payfast_notify, $underpaid_still_pending ) {
		$options = $underpaid_payfast_settings();
		$product = $t::product( 230, array( 'taxable' => false ) );
		$t::add( $product );
		$first = $underpaid_place( 'payfast', 230 );
		$underpaid_next_order();
		$t::add( $product );
		$second = $underpaid_place( 'payfast', 230 );
		$itn    = $underpaid_payfast_itn( $first, 230, 'sunshine totals', array(), array( 'm_payment_id' => (string) $second->get_id() ) );
		$underpaid_payfast_notify( $second, $options, $itn );
		$underpaid_still_pending( $second->get_id(), 'other order: ' );
	}
);

// The return link is in the PayFast form the customer's browser gets, so it can be opened
// without paying. Coming back to it is not proof of payment: PayFast's ITN is.
$t::test(
	'PayFast: opening the return link without paying leaves the order unpaid',
	function () use ( $t, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_form, $underpaid_child, $underpaid_still_pending ) {
		$options = $underpaid_payfast_settings();
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$order  = $underpaid_place( 'payfast', 230 );
		$fields = $underpaid_payfast_form( $order );
		parse_str( (string) wp_parse_url( $fields['return_url'], PHP_URL_QUERY ), $return );
		$t::same( true, ! empty( $return['payfast_success'] ), 'the return link is in the form' );
		$child = $underpaid_child(
			array(
				'method'   => 'payfast',
				'function' => 'success',
				'options'  => $options,
				'get'      => $return,
				'orders'   => array( $order->get_id() ),
			)
		);
		$t::same( $order->get_received_permalink(), $child['result']['redirect'], 'the return link was accepted and goes to the receipt' );
		$underpaid_still_pending( $order->get_id() );
	}
);

/*
 * PayFast's API, for when PayFast's ITN never arrives. When the customer comes back to the
 * return link and the order is still unpaid, the site asks PayFast's transaction history
 * (GET https://api.payfast.co.za/transactions/history, CSV) whether the order was paid. Only a
 * received payment PayFast lists for this order, for the whole total, in rand, finishes it.
 * Coming back to the link is never enough on its own, and anything PayFast can't confirm (an
 * error, an answer that can't be read, a rejected signature) leaves the order unpaid.
 */

// PayFast's transaction history: a CSV header row (the columns PayFast's API guide shows), then
// one line per transaction. Each row is merged into a received card payment made just now.
$underpaid_payfast_history = function ( $rows ) {
	$columns = array( 'Date', 'Type', 'Sign', 'Party', 'Name', 'Description', 'Currency', 'Funding Type', 'Gross', 'Fee', 'Net', 'Balance', 'M Payment ID', 'PF Payment ID', 'custom str1', 'custom int1', 'custom str2', 'custom int2', 'custom str3', 'custom str4', 'custom str5', 'custom int3', 'custom int4', 'custom int5' );
	$now     = new DateTime( 'now', new DateTimeZone( 'Africa/Johannesburg' ) );
	$handle  = fopen( 'php://temp', 'r+' );
	fputcsv( $handle, $columns, ',', '"', '\\' );
	foreach ( $rows as $row ) {
		$row  = array_merge(
			array(
				'Date'          => $now->format( 'Y-m-d H:i:s' ),
				'Type'          => 'FUNDS_RECEIVED',
				'Sign'          => 'CREDIT',
				'Party'         => 'Totals Test',
				'Name'          => 'Order',
				'Currency'      => 'ZAR',
				'Funding Type'  => 'CC',
				'Fee'           => '-5.29',
				'Balance'       => '1,000.00',
				'PF Payment ID' => '2290001',
			),
			$row
		);
		$line = array();
		foreach ( $columns as $column ) {
			$line[] = isset( $row[ $column ] ) ? $row[ $column ] : '';
		}
		fputcsv( $handle, $line, ',', '"', '\\' );
	}
	rewind( $handle );
	$csv = stream_get_contents( $handle );
	fclose( $handle );
	return $csv;
};

// A reply for PayFast's API that answers the transaction history with $status and $body.
$underpaid_payfast_api_reply = function ( $status, $body ) {
	return function ( $request ) use ( $status, $body ) {
		return 'api.payfast.co.za' === $request['host'] && '/transactions/history' === $request['path'] ? array( $status, $body ) : null;
	};
};

// Run $callback with PayFast's API answered by $reply. Returns what $underpaid_run gives, plus
// the headers of every request sent.
$underpaid_payfast_api_run = function ( $callback, $reply, $label ) use ( $underpaid_run ) {
	$headers = array();
	$grab    = function ( $pre, $args ) use ( &$headers ) {
		$headers[] = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		return $pre;
	};
	add_filter( 'pre_http_request', $grab, 10, 2 );
	try {
		$run = $underpaid_run( $callback, $reply, $label );
	} finally {
		remove_filter( 'pre_http_request', $grab, 10 );
	}
	$run['headers'] = $headers;
	return $run;
};

// The customer comes back to PayFast's return link, in their own browser.
$underpaid_payfast_return = function ( $order, $reply ) use ( $underpaid_payfast_api_run ) {
	$_GET['payfast_success'] = wp_create_nonce( 'payfast_success_' . $order->get_id() );
	$_GET['order_id']        = (string) $order->get_id();
	try {
		return $underpaid_payfast_api_run(
			function () {
				sunshine_get_payment_method_by_id( 'payfast' )->success();
			},
			$reply,
			'PayFast return link'
		);
	} finally {
		unset( $_GET['payfast_success'], $_GET['order_id'] );
	}
};

// The order's log lines that contain $text.
$underpaid_payfast_log = function ( $order_id, $text ) {
	$found = array();
	foreach ( get_comments(
		array(
			'post_id' => $order_id,
			'type'    => 'sunshine_order_log',
		)
	) as $comment ) {
		if ( false !== strpos( $comment->comment_content, $text ) ) {
			$found[] = $comment->comment_content;
		}
	}
	return $found;
};

$underpaid_payfast_confirmed_note = 'Payfast payment confirmed through the PayFast API (payment notice not received)';

$t::test(
	'PayFast API: a payment PayFast lists for the full total finishes the order once, with a correctly signed request',
	function () use ( $t, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_history, $underpaid_payfast_api_reply, $underpaid_payfast_return, $underpaid_payfast_api_run, $underpaid_fresh, $underpaid_payfast_log, $underpaid_payfast_confirmed_note ) {
		$underpaid_payfast_settings();
		$t::tax( 15 );
		$t::add( $t::product( 200 ) );
		$order = $underpaid_place( 'payfast', 230 );
		$csv   = $underpaid_payfast_history(
			array(
				array(
					'M Payment ID' => '999999',
					'Gross'        => '230.00',
					'PF Payment ID' => '2290000',
				),
				array(
					'M Payment ID' => (string) $order->get_id(),
					'Gross'        => '230.00',
				),
			)
		);
		$run   = $underpaid_payfast_return( $order, $underpaid_payfast_api_reply( 200, $csv ) );
		$t::same( $order->get_received_permalink(), $run['redirect'], 'customer goes to the receipt' );

		$saved = $underpaid_fresh( $order->get_id() );
		$t::same( 'new', $saved->get_status(), 'order is paid' );
		$t::same( true, '' !== (string) $saved->get_meta_value( 'post_processed' ), 'order is finished' );
		$t::same( '2290001', (string) $saved->get_meta_value( 'payfast_payment_id' ), 'PayFast payment ID saved from the history' );
		$t::money( 5.29, $saved->get_meta_value( 'payfast_fees' ), 'PayFast fee saved' );
		$t::same( 1, count( $underpaid_payfast_log( $order->get_id(), $underpaid_payfast_confirmed_note ) ), 'one note says the API confirmed it' );

		// What was sent: PayFast's documented headers, signed the way its API guide says (every
		// header, query value and the passphrase, sorted by name, urlencoded, joined with &,
		// md5), with testing=true on the address but left out of the signature.
		$t::same( 1, count( $run['sent'] ), 'one request to PayFast' );
		$t::same( 'api.payfast.co.za', $run['sent'][0]['host'], 'sent to PayFast\'s API' );
		$t::same( 'GET', $run['sent'][0]['method'], 'a GET request' );
		parse_str( (string) wp_parse_url( $run['sent'][0]['url'], PHP_URL_QUERY ), $query );
		$t::same( 'true', isset( $query['testing'] ) ? $query['testing'] : '', 'sandbox asked for with testing=true' );
		$headers = $run['headers'][0];
		$t::same( '10000100', isset( $headers['merchant-id'] ) ? $headers['merchant-id'] : '', 'merchant-id header' );
		$t::same( 'v1', isset( $headers['version'] ) ? $headers['version'] : '', 'version header' );
		$t::same( 1, preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', isset( $headers['timestamp'] ) ? $headers['timestamp'] : '' ), 'ISO-8601 timestamp header' );
		$signed = $query;
		unset( $signed['testing'] );
		$signed['merchant-id'] = $headers['merchant-id'];
		$signed['version']     = $headers['version'];
		$signed['timestamp']   = $headers['timestamp'];
		$signed['passphrase']  = 'sunshine totals';
		ksort( $signed );
		$t::same( md5( http_build_query( $signed ) ), isset( $headers['signature'] ) ? $headers['signature'] : '', 'signature as PayFast\'s API guide builds it' );
		$t::same( false, isset( $query['passphrase'] ), 'passphrase is never sent' );
		$t::same( 1, preg_match( '/^\d{4}-\d{2}-\d{2}$/', isset( $query['from'] ) ? $query['from'] : '' ), 'from date' );
		$t::same( 1, preg_match( '/^\d{4}-\d{2}-\d{2}$/', isset( $query['to'] ) ? $query['to'] : '' ), 'to date' );

		// Asking again (the customer reloads the link, or admin asks) doesn't finish it again.
		$again = $underpaid_payfast_api_run(
			function () use ( $saved ) {
				sunshine_get_payment_method_by_id( 'payfast' )->confirm_payment( $saved );
			},
			$underpaid_payfast_api_reply( 200, $csv ),
			'PayFast API again'
		);
		$t::same( 0, count( $again['sent'] ), 'a paid order is not looked up again' );
		$t::same( 1, count( $underpaid_payfast_log( $order->get_id(), $underpaid_payfast_confirmed_note ) ), 'still one confirmation note' );
	}
);

// A payment that was for less than the order (the order grew after the PayFast page opened).
$t::test(
	'PayFast API: a payment for a different amount leaves the order unpaid, with a note',
	function () use ( $t, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_history, $underpaid_payfast_api_reply, $underpaid_payfast_return, $underpaid_still_pending, $underpaid_payfast_log ) {
		$underpaid_payfast_settings();
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'payfast', 230 );
		$csv   = $underpaid_payfast_history(
			array(
				array(
					'M Payment ID' => (string) $order->get_id(),
					'Gross'        => '229.99',
				),
			)
		);
		$underpaid_payfast_return( $order, $underpaid_payfast_api_reply( 200, $csv ) );
		$underpaid_still_pending( $order->get_id() );
		$t::same( 1, count( $underpaid_payfast_log( $order->get_id(), 'does not match the order total' ) ), 'note says the amount is wrong' );
	}
);

$t::test(
	'PayFast API: a payment in another currency, dated before the order, or refunded leaves the order unpaid',
	function () use ( $t, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_history, $underpaid_payfast_api_reply, $underpaid_payfast_api_run, $underpaid_still_pending, $underpaid_payfast_log ) {
		$underpaid_payfast_settings();
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$order   = $underpaid_place( 'payfast', 230 );
		$payfast = sunshine_get_payment_method_by_id( 'payfast' );
		$cases   = array(
			'another currency'   => array(
				array(
					'Currency' => 'USD',
				),
				'not ZAR',
			),
			// The same order number at another shop on the same PayFast account, paid last year.
			'dated before order' => array(
				array(
					'Date' => '2025-01-15 10:00:00',
				),
				'before the order was placed',
			),
			'refunded'           => array(
				array(),
				'REFUND',
			),
		);
		foreach ( $cases as $label => $case ) {
			$rows = array( array_merge( array( 'M Payment ID' => (string) $order->get_id(), 'Gross' => '230.00' ), $case[0] ) );
			if ( 'refunded' === $label ) {
				$rows[] = array(
					'M Payment ID' => (string) $order->get_id(),
					'Type'         => 'REFUND',
					'Sign'         => 'DEBIT',
					'Gross'        => '-230.00',
				);
			}
			$result = null;
			$underpaid_payfast_api_run(
				function () use ( $payfast, $order, &$result ) {
					$result = $payfast->confirm_payment( sunshine_get_order( $order->get_id() ) );
				},
				$underpaid_payfast_api_reply( 200, $underpaid_payfast_history( $rows ) ),
				'PayFast API ' . $label
			);
			$t::same( 'problem', $result, $label . ': PayFast\'s answer is not accepted' );
			$underpaid_still_pending( $order->get_id(), $label . ': ' );
			$t::same( 1, count( $underpaid_payfast_log( $order->get_id(), $case[1] ) ), $label . ': note says why' );
		}
	}
);

// PayFast's history is the store's own account. Asking with another account's ID, or a wrong
// passphrase, gets "Merchant authorisation failed" or "Merchant not found" and proves nothing.
$t::test(
	'PayFast API: rejected credentials, no passphrase, errors and unreadable answers leave the order unpaid',
	function () use ( $t, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_api_reply, $underpaid_payfast_api_run, $underpaid_still_pending, $underpaid_payfast_log ) {
		$options = $underpaid_payfast_settings();
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$order   = $underpaid_place( 'payfast', 230 );
		$payfast = sunshine_get_payment_method_by_id( 'payfast' );
		$replies = array(
			'merchant not found'    => $underpaid_payfast_api_reply(
				401,
				array(
					'code'   => 401,
					'status' => 'failed',
					'data'   => array(
						'response' => false,
						'message'  => 'Merchant not found',
					),
				)
			),
			'authorisation failed'  => $underpaid_payfast_api_reply(
				401,
				array(
					'code'   => 401,
					'status' => 'failed',
					'data'   => array(
						'response' => false,
						'message'  => 'Merchant authorisation failed',
					),
				)
			),
			'server error'          => $underpaid_payfast_api_reply( 500, 'Application Error' ),
			'no answer'             => null,
			'not the history'       => $underpaid_payfast_api_reply( 200, '<html><body>Maintenance</body></html>' ),
			'JSON instead of CSV'   => $underpaid_payfast_api_reply( 200, array( 'response' => true ) ),
		);
		foreach ( $replies as $label => $reply ) {
			$result = null;
			$underpaid_payfast_api_run(
				function () use ( $payfast, $order, &$result ) {
					$result = $payfast->confirm_payment( sunshine_get_order( $order->get_id() ) );
				},
				$reply,
				'PayFast API ' . $label
			);
			$t::same( 'error', $result, $label . ': treated as an error' );
			$underpaid_still_pending( $order->get_id(), $label . ': ' );
		}
		$t::same( 1, count( $underpaid_payfast_log( $order->get_id(), 'Merchant authorisation failed' ) ), 'PayFast\'s reason is on the order' );

		// PayFast's API only works for an account with a passphrase, so without one nothing is sent.
		$t::option( 'payfast_passphrase_test', '' );
		$result = null;
		$run    = $underpaid_payfast_api_run(
			function () use ( $payfast, $order, &$result ) {
				$result = $payfast->confirm_payment( sunshine_get_order( $order->get_id() ) );
			},
			null,
			'PayFast API no passphrase'
		);
		$t::same( 'error', $result, 'no passphrase: not checked' );
		$t::same( 0, count( $run['sent'] ), 'no passphrase: nothing sent to PayFast' );
		$underpaid_still_pending( $order->get_id(), 'no passphrase: ' );
	}
);

// PayFast may take a moment to list a payment made seconds ago: the return link asks a second
// time, then leaves the order waiting for the ITN.
$t::test(
	'PayFast API: a payment PayFast doesn\'t list yet leaves the order waiting, after one more try',
	function () use ( $t, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_history, $underpaid_payfast_api_reply, $underpaid_payfast_return, $underpaid_still_pending, $underpaid_payfast_log ) {
		$underpaid_payfast_settings();
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'payfast', 230 );
		$csv   = $underpaid_payfast_history(
			array(
				array(
					'M Payment ID' => (string) ( $order->get_id() + 1 ),
					'Gross'        => '230.00',
				),
			)
		);
		$run   = $underpaid_payfast_return( $order, $underpaid_payfast_api_reply( 200, $csv ) );
		$t::same( 2, count( $run['sent'] ), 'PayFast asked twice' );
		$t::same( $order->get_received_permalink(), $run['redirect'], 'customer still goes to the receipt' );
		$underpaid_still_pending( $order->get_id() );
		$t::same( array(), $underpaid_payfast_log( $order->get_id(), 'Payfast API' ), 'no note: nothing wrong, just not listed yet' );

		// An empty history (no transactions at all) is the same.
		$run = $underpaid_payfast_return( $order, $underpaid_payfast_api_reply( 200, '' ) );
		$underpaid_still_pending( $order->get_id(), 'empty history: ' );
	}
);

// The ITN and the API check can both try to finish the order. Whichever comes second must not
// finish it again.
$t::test(
	'PayFast API: the ITN arriving after the API finished the order doesn\'t finish it twice, and an ITN first means no API call',
	function () use ( $t, $underpaid_payfast_settings, $underpaid_place, $underpaid_next_order, $underpaid_payfast_history, $underpaid_payfast_api_reply, $underpaid_payfast_return, $underpaid_payfast_itn, $underpaid_payfast_notify, $underpaid_fresh, $underpaid_payfast_log, $underpaid_payfast_confirmed_note ) {
		$options = $underpaid_payfast_settings();
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'payfast', 230 );
		$csv   = $underpaid_payfast_history(
			array(
				array(
					'M Payment ID' => (string) $order->get_id(),
					'Gross'        => '230.00',
				),
			)
		);
		$underpaid_payfast_return( $order, $underpaid_payfast_api_reply( 200, $csv ) );
		$saved     = $underpaid_fresh( $order->get_id() );
		$processed = (string) $saved->get_meta_value( 'post_processed' );
		$t::same( 'new', $saved->get_status(), 'API finished the order' );

		$child = $underpaid_payfast_notify( $order, $options, $underpaid_payfast_itn( $order, 230, 'sunshine totals' ) );
		$saved = $underpaid_fresh( $order->get_id() );
		$t::same( 'new', $saved->get_status(), 'still paid after the ITN' );
		$t::same( $processed, (string) $saved->get_meta_value( 'post_processed' ), 'ITN did not finish it again' );
		$t::same( 1, count( $underpaid_payfast_log( $order->get_id(), 'Checkout is setting status to new' ) ), 'status set to paid once' );
		$t::same( 1, count( $underpaid_payfast_log( $order->get_id(), $underpaid_payfast_confirmed_note ) ), 'one confirmation note' );
		$t::same( null, $child['result']['error'], 'ITN handler ran without errors' );

		// ITN first: the customer comes back to an order that is already paid, nothing is asked.
		$underpaid_next_order();
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$second = $underpaid_place( 'payfast', 230 );
		$underpaid_payfast_notify( $second, $options, $underpaid_payfast_itn( $second, 230, 'sunshine totals' ) );
		$t::same( true, $underpaid_fresh( $second->get_id() )->is_paid(), 'ITN finished the second order' );
		$run = $underpaid_payfast_return( $underpaid_fresh( $second->get_id() ), $underpaid_payfast_api_reply( 200, $csv ) );
		$t::same( 0, count( $run['sent'] ), 'no API call for a paid order' );
		$t::same( array(), $underpaid_payfast_log( $second->get_id(), $underpaid_payfast_confirmed_note ), 'no API note on the ITN-paid order' );
	}
);

// Only PayFast confirming the payment pays the order. The return link with PayFast listing
// nothing for it (the customer never paid) changes nothing, however often it's opened.
$t::test(
	'PayFast API: the return link without PayFast confirming the payment never pays the order',
	function () use ( $t, $underpaid_payfast_settings, $underpaid_place, $underpaid_payfast_history, $underpaid_payfast_api_reply, $underpaid_payfast_return, $underpaid_still_pending ) {
		$underpaid_payfast_settings();
		$t::add( $t::product( 230, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'payfast', 230 );
		// A payment for the full amount, but for another order.
		$other = $underpaid_payfast_history(
			array(
				array(
					'M Payment ID' => (string) ( $order->get_id() + 7 ),
					'Gross'        => '230.00',
				),
			)
		);
		foreach ( array( 'blocked' => null, 'another order\'s payment' => $underpaid_payfast_api_reply( 200, $other ) ) as $label => $reply ) {
			$underpaid_payfast_return( $order, $reply );
			$underpaid_still_pending( $order->get_id(), $label . ': ' );
		}
	}
);

/*
 * Systempay. The customer is sent to Systempay with a signed form. Systempay then posts an IPN
 * to the site (ipn()) with the result, signed with the store's key. The IPN ends with exit, so
 * it runs in a separate process. Systempay's guide says to check the IPN signature and the
 * payment status (vads_trans_status) before treating an order as paid.
 */

$underpaid_systempay_settings = function () use ( $t, $underpaid_settings ) {
	if ( ! class_exists( 'SPC_Payment_Method_SystemPay' ) ) {
		$t::skip( 'Systempay add-on is not active' );
	}
	return $underpaid_settings(
		array(
			'currency'           => 'EUR',
			'systempay_active'   => 1,
			'systempay_mode'     => 'test',
			'systempay_site_id'  => '12345678',
			'systempay_test_key' => 'spctotalskey',
			'systempay_fee'      => 'none',
		)
	);
};

// Build the Systempay form for the order, the way the redirect does. This saves the
// transaction ID on the order. Returns the form fields.
$underpaid_systempay_form = function ( $order ) {
	$systempay = sunshine_get_payment_method_by_id( 'systempay' );
	$build     = new ReflectionMethod( $systempay, 'get_payment_args' );
	$build->setAccessible( true );
	return $build->invoke( $systempay, $order );
};

// The IPN Systempay posts about a payment, signed with $key: every vads_ field sorted by
// name, values joined with +, then the key, HMAC-SHA-256 in base64.
$underpaid_systempay_ipn = function ( $form, $amount_cents, $changes = array(), $key = 'spctotalskey' ) {
	$fields = array_merge(
		array(
			'vads_amount'         => (string) $amount_cents,
			'vads_auth_result'    => '00',
			'vads_ctx_mode'       => 'TEST',
			'vads_currency'       => (string) $form['vads_currency'],
			'vads_operation_type' => 'DEBIT',
			'vads_order_id'       => (string) $form['vads_order_id'],
			'vads_page_action'    => 'PAYMENT',
			'vads_payment_config' => 'SINGLE',
			'vads_result'         => '00',
			'vads_site_id'        => (string) $form['vads_site_id'],
			'vads_trans_date'     => (string) $form['vads_trans_date'],
			'vads_trans_id'       => (string) $form['vads_trans_id'],
			'vads_trans_status'   => 'AUTHORISED',
			'vads_url_check_src'  => 'PAY',
			'vads_version'        => 'V2',
		),
		$changes
	);
	ksort( $fields );
	$fields['signature'] = base64_encode( hash_hmac( 'sha256', implode( '+', $fields ) . '+' . $key, $key, true ) );
	return $fields;
};

$underpaid_systempay_notify = function ( $order, $options, $fields ) use ( $underpaid_child ) {
	return $underpaid_child(
		array(
			'method'   => 'systempay',
			'function' => 'ipn',
			'options'  => $options,
			'get'      => array( 'sunshine_systempay_ipn' => '1' ),
			'post'     => $fields,
			'orders'   => array( $order->get_id() ),
		)
	);
};

$t::test(
	'Systempay: a signed IPN for the order total completes the order',
	function () use ( $t, $underpaid_systempay_settings, $underpaid_place, $underpaid_systempay_form, $underpaid_systempay_ipn, $underpaid_systempay_notify, $underpaid_fresh ) {
		$options = $underpaid_systempay_settings();
		$t::tax( 20 );
		$t::add( $t::product( 50 ) );
		// 50 + 20% VAT = 60 euros, 6000 cents.
		$order = $underpaid_place( 'systempay', 60 );
		$form  = $underpaid_systempay_form( $order );
		$t::same( '6000', (string) $form['vads_amount'], 'Systempay is asked for the order total in cents' );
		$underpaid_systempay_notify( $order, $options, $underpaid_systempay_ipn( $form, 6000 ) );
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'order is paid' );
	}
);

// Systempay reports a payment of 30.00 for a 60.00 order, then (second order) a payment of
// 60.00 in dollars for an order in euros. Systempay's guide says to check the amount and currency
// in the IPN against the order.
$t::test(
	'Systempay: a signed IPN for less than the order or in another currency leaves it unpaid',
	function () use ( $t, $underpaid_systempay_settings, $underpaid_place, $underpaid_next_order, $underpaid_systempay_form, $underpaid_systempay_ipn, $underpaid_systempay_notify, $underpaid_still_pending ) {
		$options = $underpaid_systempay_settings();
		$t::tax( 20 );
		$product = $t::product( 25 );
		$t::add( $product, 2 );
		$short = $underpaid_place( 'systempay', 60 );
		$form  = $underpaid_systempay_form( $short );
		$underpaid_systempay_notify( $short, $options, $underpaid_systempay_ipn( $form, 3000 ) );
		$underpaid_still_pending( $short->get_id(), '30 of 60 euros: ' );

		$underpaid_next_order();
		$t::add( $product, 2 );
		$dollars = $underpaid_place( 'systempay', 60 );
		$form    = $underpaid_systempay_form( $dollars );
		// 840 is the currency number for US dollars; the store is in euros (978).
		$underpaid_systempay_notify( $dollars, $options, $underpaid_systempay_ipn( $form, 6000, array( 'vads_currency' => '840' ) ) );
		$underpaid_still_pending( $dollars->get_id(), 'paid in dollars: ' );
	}
);

// Systempay sends an IPN for a refused card too, with vads_trans_status REFUSED.
$t::test(
	'Systempay: an IPN for a refused payment leaves the order unpaid',
	function () use ( $t, $underpaid_systempay_settings, $underpaid_place, $underpaid_systempay_form, $underpaid_systempay_ipn, $underpaid_systempay_notify, $underpaid_still_pending, $underpaid_declined_note ) {
		$options = $underpaid_systempay_settings();
		$t::add( $t::product( 60, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'systempay', 60 );
		$form  = $underpaid_systempay_form( $order );
		$underpaid_systempay_notify(
			$order,
			$options,
			$underpaid_systempay_ipn(
				$form,
				6000,
				array(
					'vads_trans_status' => 'REFUSED',
					'vads_result'       => '05',
					'vads_auth_result'  => '05',
				)
			)
		);
		$underpaid_still_pending( $order->get_id() );
		$underpaid_declined_note( $order->get_id() );
	}
);

// The transaction ID is in the form the customer's browser gets. Without a valid signature
// (only Systempay and the store know the key), a posted IPN proves nothing.
$t::test(
	'Systempay: an IPN not signed with the store key leaves the order unpaid',
	function () use ( $t, $underpaid_systempay_settings, $underpaid_place, $underpaid_systempay_form, $underpaid_systempay_ipn, $underpaid_systempay_notify, $underpaid_still_pending ) {
		$options = $underpaid_systempay_settings();
		$t::add( $t::product( 60, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'systempay', 60 );
		$form  = $underpaid_systempay_form( $order );
		$underpaid_systempay_notify( $order, $options, $underpaid_systempay_ipn( $form, 6000, array(), 'not-the-store-key' ) );
		$underpaid_still_pending( $order->get_id() );
	}
);

$t::test(
	'Systempay: an IPN with another order\'s transaction leaves this order unpaid',
	function () use ( $t, $underpaid_systempay_settings, $underpaid_place, $underpaid_next_order, $underpaid_systempay_form, $underpaid_systempay_ipn, $underpaid_systempay_notify, $underpaid_still_pending ) {
		$options = $underpaid_systempay_settings();
		$product = $t::product( 60, array( 'taxable' => false ) );
		$t::add( $product );
		$first      = $underpaid_place( 'systempay', 60 );
		$first_form = $underpaid_systempay_form( $first );
		$underpaid_next_order();
		$t::add( $product );
		$second = $underpaid_place( 'systempay', 60 );
		$underpaid_systempay_form( $second );
		$underpaid_systempay_notify( $second, $options, $underpaid_systempay_ipn( $first_form, 6000, array( 'vads_order_id' => (string) $second->get_id() ) ) );
		$underpaid_still_pending( $second->get_id(), 'other order: ' );
	}
);

/*
 * QuickPay. The site makes a QuickPay payment and a payment link for the order total. After
 * paying, QuickPay sends the customer to the continue link, and the site looks the payment up
 * at QuickPay before finishing the order (success()).
 */

$underpaid_quickpay_use = function () use ( $t, $underpaid_settings ) {
	$t::require_plugin( 'sunshine_payment_method_quickpay', 'QuickPay add-on' );
	return $underpaid_settings(
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

// Send the customer to QuickPay. Returns the continue link QuickPay will send them back to.
$underpaid_quickpay_start = function ( $order ) use ( $underpaid_run, $underpaid_find, $underpaid_wire ) {
	$quickpay = sunshine_get_payment_method_by_id( 'quickpay' );
	$run      = $underpaid_run(
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
	$link = $underpaid_wire( $underpaid_find( $run['sent'], '#^/payments/90210/link$#' ) );
	return isset( $link['continue_url'] ) ? $link['continue_url'] : '';
};

// The customer comes back on the continue link. QuickPay answers the look up with a payment
// that was accepted for $amount (smallest unit) in $currency.
$underpaid_quickpay_return = function ( $order, $continue_url, $amount, $currency = 'USD', $accepted = true ) use ( $underpaid_run ) {
	parse_str( (string) wp_parse_url( $continue_url, PHP_URL_QUERY ), $query );
	$quickpay = sunshine_get_payment_method_by_id( 'quickpay' );
	$payment  = array(
		'id'         => 90210,
		'order_id'   => (string) $order->get_id(),
		'accepted'   => $accepted,
		'test_mode'  => true,
		'currency'   => $currency,
		'state'      => 'new',
		'operations' => array(
			array(
				'id'             => 1,
				'type'           => 'authorize',
				'amount'         => $amount,
				'pending'        => false,
				'qp_status_code' => '20000',
				'aq_status_code' => '20000',
			),
		),
		'link'       => array( 'amount' => $amount ),
	);
	foreach ( $query as $key => $value ) {
		$_GET[ $key ] = $value;
	}
	try {
		return $underpaid_run(
			function () use ( $quickpay ) {
				$quickpay->success();
			},
			function ( $request ) use ( $payment ) {
				return '/payments/90210' === $request['path'] && 'GET' === $request['method'] ? array( 200, $payment ) : null;
			},
			'QuickPay return'
		);
	} finally {
		foreach ( array_keys( $query ) as $key ) {
			unset( $_GET[ $key ] );
		}
	}
};

$t::test(
	'QuickPay: a payment accepted for the order total completes the order',
	function () use ( $t, $underpaid_quickpay_use, $underpaid_place, $underpaid_quickpay_start, $underpaid_quickpay_return, $underpaid_fresh ) {
		$underpaid_quickpay_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order    = $underpaid_place( 'quickpay', 110 );
		$continue = $underpaid_quickpay_start( $order );
		$t::same( true, '' !== $continue, 'QuickPay was given a continue link' );
		$run = $underpaid_quickpay_return( $order, $continue, 11000 );
		$t::same( $order->get_received_permalink(), $run['redirect'], 'customer sent to the receipt' );
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'order is paid' );
	}
);

// QuickPay says the payment was accepted, but only 55.00 of the 110.00 order was authorized
// (for example, the same order was updated with a second print in another tab after its payment
// link was made). However it happened, an order must not be marked paid for less than its total.
$t::test(
	'QuickPay: a payment accepted for less than the order leaves it unpaid',
	function () use ( $t, $underpaid_quickpay_use, $underpaid_place, $underpaid_quickpay_start, $underpaid_quickpay_return, $underpaid_still_pending ) {
		$underpaid_quickpay_use();
		$t::tax( 10 );
		$t::add( $t::product( 50 ), 2 );
		$order    = $underpaid_place( 'quickpay', 110 );
		$continue = $underpaid_quickpay_start( $order );
		$underpaid_quickpay_return( $order, $continue, 5500 );
		$underpaid_still_pending( $order->get_id() );
	}
);

$t::test(
	'QuickPay: a payment accepted in another currency leaves the order unpaid',
	function () use ( $t, $underpaid_quickpay_use, $underpaid_place, $underpaid_quickpay_start, $underpaid_quickpay_return, $underpaid_still_pending ) {
		$underpaid_quickpay_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order    = $underpaid_place( 'quickpay', 110 );
		$continue = $underpaid_quickpay_start( $order );
		// 110 Danish kroner is about 16 dollars.
		$underpaid_quickpay_return( $order, $continue, 11000, 'DKK' );
		$underpaid_still_pending( $order->get_id() );
	}
);

// The customer comes back, but QuickPay has not accepted the payment (the card was declined).
$t::test(
	'QuickPay: a payment QuickPay did not accept leaves the order unpaid and the note says declined',
	function () use ( $t, $underpaid_quickpay_use, $underpaid_place, $underpaid_quickpay_start, $underpaid_quickpay_return, $underpaid_still_pending, $underpaid_declined_note ) {
		$underpaid_quickpay_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order    = $underpaid_place( 'quickpay', 110 );
		$continue = $underpaid_quickpay_start( $order );
		$underpaid_quickpay_return( $order, $continue, 11000, 'USD', false );
		$underpaid_still_pending( $order->get_id() );
		$underpaid_declined_note( $order->get_id() );
	}
);

// QuickPay also calls the site in the background (callback()), signed with the private key.
// It ends with exit, so it runs in a separate process.
$t::test(
	'QuickPay: the signed payment callback runs without PHP warnings',
	function () use ( $t, $underpaid_quickpay_use, $underpaid_place, $underpaid_child, $underpaid_warnings_from ) {
		$options = $underpaid_quickpay_use();
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$order = $underpaid_place( 'quickpay', 100 );
		$body  = wp_json_encode(
			array(
				'id'         => 90210,
				'order_id'   => (string) $order->get_id(),
				'accepted'   => true,
				'currency'   => 'USD',
				'operations' => array(
					array(
						'id'     => 1,
						'type'   => 'authorize',
						'amount' => 10000,
					),
				),
			)
		);
		$child = $underpaid_child(
			array(
				'method'   => 'quickpay',
				'function' => 'callback',
				'options'  => $options,
				'get'      => array( 'quickpay_callback' => '1' ),
				'post'     => array( 'x' => '1' ),
				'server'   => array( 'HTTP_QUICKPAY_CHECKSUM_SHA256' => hash_hmac( 'sha256', $body, 'spctotals-private-key' ) ),
				'raw_body' => $body,
				'orders'   => array( $order->get_id() ),
			)
		);
		$t::same( null, $child['result']['error'], 'callback ran to the end' );
		$t::same( array(), $underpaid_warnings_from( $child, 'sunshine-quickpay' ), 'no PHP warnings from the callback' );
	}
);

/*
 * Paystack. The site starts a Paystack transaction for the order total, and Paystack sends the
 * customer back with the transaction reference (trxref) in the link. The site looks that
 * transaction up at Paystack before finishing the order (success()). The reference in the link
 * comes from the browser, so it must be the one this order's payment was started with.
 */

$underpaid_paystack_use = function () use ( $t, $underpaid_settings ) {
	$t::require_plugin( 'sunshine_payment_method_paystack', 'Paystack add-on' );
	$underpaid_settings(
		array(
			'paystack_active'          => 1,
			'paystack_mode'            => 'test',
			'paystack_test_secret_key' => 'sk_test_spctotals',
			'paystack_test_public_key' => 'pk_test_spctotals',
			'paystack_fee'             => 'none',
		)
	);
};

// Send the customer to Paystack. Returns the link Paystack sends them back to.
$underpaid_paystack_start = function ( $order ) use ( $underpaid_run, $underpaid_find, $underpaid_wire ) {
	$paystack = sunshine_get_payment_method_by_id( 'paystack' );
	$run      = $underpaid_run(
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
	$sent = $underpaid_wire( $underpaid_find( $run['sent'], '#^/transaction/initialize$#' ) );
	return isset( $sent['callback_url'] ) ? $sent['callback_url'] : '';
};

// The customer comes back on the order's return link with transaction $reference, which
// Paystack reports as a payment of $amount (smallest unit) in $currency with $status
// (success, unless a test says failed or abandoned).
$underpaid_paystack_return = function ( $callback_url, $reference, $amount, $currency = 'USD', $status = 'success' ) use ( $underpaid_run ) {
	parse_str( (string) wp_parse_url( $callback_url, PHP_URL_QUERY ), $query );
	$query['trxref']    = $reference;
	$query['reference'] = $reference;
	$paystack           = sunshine_get_payment_method_by_id( 'paystack' );
	foreach ( $query as $key => $value ) {
		$_GET[ $key ]     = $value;
		$_REQUEST[ $key ] = $value;
	}
	try {
		return $underpaid_run(
			function () use ( $paystack ) {
				$paystack->success();
			},
			function ( $request ) use ( $reference, $amount, $currency, $status ) {
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
							'currency'  => $currency,
							'fees'      => 315,
						),
					),
				);
			},
			'Paystack return'
		);
	} finally {
		foreach ( array_keys( $query ) as $key ) {
			unset( $_GET[ $key ], $_REQUEST[ $key ] );
		}
	}
};

$t::test(
	'Paystack: a successful transaction for the order total completes the order',
	function () use ( $t, $underpaid_paystack_use, $underpaid_place, $underpaid_paystack_start, $underpaid_paystack_return, $underpaid_fresh ) {
		$underpaid_paystack_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $underpaid_place( 'paystack', 110 );
		$back  = $underpaid_paystack_start( $order );
		$t::same( true, '' !== $back, 'Paystack was given a return link' );
		$run = $underpaid_paystack_return( $back, 'spcref' . $order->get_id(), 11000 );
		$t::same( $order->get_received_permalink(), $run['redirect'], 'customer sent to the receipt' );
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'order is paid' );
	}
);

// Paystack reports the transaction on the return link as a successful payment of 1.00 for a
// 110.00 order, then (second order) a payment of 11000 kobo in naira for an order in dollars.
$t::test(
	'Paystack: a transaction for less than the order or in another currency leaves it unpaid',
	function () use ( $t, $underpaid_paystack_use, $underpaid_place, $underpaid_next_order, $underpaid_paystack_start, $underpaid_paystack_return, $underpaid_still_pending ) {
		$underpaid_paystack_use();
		$t::tax( 10 );
		$product = $t::product( 100 );
		$t::add( $product );
		$short = $underpaid_place( 'paystack', 110 );
		$back  = $underpaid_paystack_start( $short );
		$underpaid_paystack_return( $back, 'spcref' . $short->get_id(), 100 );
		$underpaid_still_pending( $short->get_id(), '1.00 of 110.00: ' );

		$underpaid_next_order();
		$t::add( $product );
		$naira = $underpaid_place( 'paystack', 110 );
		$back  = $underpaid_paystack_start( $naira );
		$underpaid_paystack_return( $back, 'spcref' . $naira->get_id(), 11000, 'NGN' );
		$underpaid_still_pending( $naira->get_id(), 'paid in naira: ' );
	}
);

// Paystack says the card payment failed.
$t::test(
	'Paystack: a failed transaction leaves the order unpaid and the note says declined',
	function () use ( $t, $underpaid_paystack_use, $underpaid_place, $underpaid_paystack_start, $underpaid_paystack_return, $underpaid_still_pending, $underpaid_declined_note ) {
		$underpaid_paystack_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $underpaid_place( 'paystack', 110 );
		$back  = $underpaid_paystack_start( $order );
		$underpaid_paystack_return( $back, 'spcref' . $order->get_id(), 11000, 'USD', 'failed' );
		$underpaid_still_pending( $order->get_id() );
		$underpaid_declined_note( $order->get_id() );
	}
);

// The customer paid an earlier order. On a new order's return link they put the earlier
// order's transaction reference instead of paying again.
$t::test(
	'Paystack: another order\'s transaction on this order\'s return link leaves it unpaid',
	function () use ( $t, $underpaid_paystack_use, $underpaid_place, $underpaid_next_order, $underpaid_paystack_start, $underpaid_paystack_return, $underpaid_still_pending ) {
		$underpaid_paystack_use();
		$t::tax( 10 );
		$product = $t::product( 100 );
		$t::add( $product );
		$first = $underpaid_place( 'paystack', 110 );
		$underpaid_paystack_start( $first );
		$underpaid_next_order();
		$t::add( $product );
		$second = $underpaid_place( 'paystack', 110 );
		$back   = $underpaid_paystack_start( $second );
		$underpaid_paystack_return( $back, 'spcref' . $first->get_id(), 11000 );
		$underpaid_still_pending( $second->get_id(), 'second order: ' );
	}
);

/*
 * Mercado Pago card form. The card form in the browser asks the site to make the payment, the
 * site keeps the payment ID in the shopper's session, and when the order is submitted the site
 * looks the payment up and accepts it (SPC_Payment_Method_MercadoPago::process_payment).
 */

$underpaid_mercadopago_use = function () use ( $t, $underpaid_settings ) {
	$t::require_plugin( 'sunshine_payment_method_mercadopago', 'Mercado Pago add-on' );
	$underpaid_settings(
		array(
			'mercadopago_active'            => 1,
			'mercadopago_mode'              => 'test',
			'mercadopago_access_token_test' => 'TEST-spctotals',
			'mercadopago_public_key_test'   => 'TEST-spctotals-public',
			'mercadopago_fee'               => 'none',
		)
	);
};

// Submit the order with Mercado Pago payment 5550001, which Mercado Pago reports as $status
// (approved, unless a test says rejected) for $paid in $currency.
$underpaid_mercadopago_submit = function ( $paid, $currency = 'USD', $status = 'approved' ) use ( $underpaid_submit ) {
	$_GET['mercadopago_payment_id'] = '5550001';
	try {
		return $underpaid_submit(
			function ( $request ) use ( $paid, $currency, $status ) {
				if ( '/v1/payments/5550001' !== $request['path'] ) {
					return null;
				}
				return array(
					200,
					array(
						'id'                 => 5550001,
						'status'             => $status,
						'transaction_amount' => $paid,
						'currency_id'        => $currency,
						'fee_details'        => array(),
					),
				);
			},
			'Mercado Pago submit'
		);
	} finally {
		unset( $_GET['mercadopago_payment_id'] );
	}
};

// 110 Brazilian reais is not 110 dollars.
$t::test(
	'Mercado Pago: a card payment in another currency leaves the order unpaid',
	function () use ( $t, $underpaid_mercadopago_use, $underpaid_place, $underpaid_mercadopago_submit, $underpaid_still_pending ) {
		$underpaid_mercadopago_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $underpaid_place( 'mercadopago', 110 );
		SPC()->session->set( 'mercadopago_payment_id', 5550001 );
		try {
			$underpaid_mercadopago_submit( 110, 'BRL' );
		} finally {
			SPC()->session->set( 'mercadopago_payment_id', '' );
		}
		$underpaid_still_pending( $order->get_id() );
	}
);

// Mercado Pago rejected the card.
$t::test(
	'Mercado Pago: a rejected card payment leaves the order unpaid and the note says declined',
	function () use ( $t, $underpaid_mercadopago_use, $underpaid_place, $underpaid_mercadopago_submit, $underpaid_still_pending, $underpaid_declined_note ) {
		$underpaid_mercadopago_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $underpaid_place( 'mercadopago', 110 );
		SPC()->session->set( 'mercadopago_payment_id', 5550001 );
		try {
			$underpaid_mercadopago_submit( 110, 'USD', 'rejected' );
		} finally {
			SPC()->session->set( 'mercadopago_payment_id', '' );
		}
		$underpaid_still_pending( $order->get_id() );
		$underpaid_declined_note( $order->get_id() );
	}
);

// The customer paid a first order with the card form (payment 5550001). In the same visit they
// place a second order of the same total and submit it with the same payment, without paying
// again.
$t::test(
	'Mercado Pago: a card payment already used for another order leaves the new order unpaid',
	function () use ( $t, $underpaid_mercadopago_use, $underpaid_place, $underpaid_next_order, $underpaid_mercadopago_submit, $underpaid_fresh, $underpaid_still_pending ) {
		$underpaid_mercadopago_use();
		$t::tax( 10 );
		$product = $t::product( 100 );
		$t::add( $product );
		$first = $underpaid_place( 'mercadopago', 110 );
		SPC()->session->set( 'mercadopago_payment_id', 5550001 );
		try {
			$underpaid_mercadopago_submit( 110 );
			$t::same( true, $underpaid_fresh( $first->get_id() )->is_paid(), 'first order is paid' );

			$underpaid_next_order();
			$t::add( $product );
			$second = $underpaid_place( 'mercadopago', 110 );
			$underpaid_mercadopago_submit( 110 );
			$underpaid_still_pending( $second->get_id(), 'second order: ' );
		} finally {
			SPC()->session->set( 'mercadopago_payment_id', '' );
		}
	}
);

/*
 * Mercado Pago Checkout Pro. The customer pays on Mercado Pago's page. Mercado Pago then calls
 * the site's webhook with the payment ID, and the site looks the payment up and finishes the
 * order named in it (webhooks()). The webhook ends with exit, so it runs in a separate process.
 */

$underpaid_mercadopagopro_settings = function () use ( $t, $underpaid_settings ) {
	$t::require_plugin( 'sunshine_payment_method_mercadopago', 'Mercado Pago add-on' );
	return $underpaid_settings(
		array(
			'currency'                         => 'USD',
			'mercadopagopro_active'            => 1,
			'mercadopagopro_mode'              => 'test',
			'mercadopagopro_access_token_test' => 'TEST-spctotals',
			'mercadopagopro_public_key_test'   => 'TEST-spctotals-public',
			'mercadopagopro_fee'               => 'none',
		)
	);
};

// Mercado Pago calls the webhook about payment 5550002, approved for $paid in $currency and
// made for the order.
$underpaid_mercadopagopro_webhook = function ( $order, $options, $paid, $currency = 'USD' ) use ( $underpaid_child ) {
	return $underpaid_child(
		array(
			'method'   => 'mercadopagopro',
			'function' => 'webhooks',
			'options'  => $options,
			'get'      => array(
				'sunshine_mercadopagopro_webhook' => '',
				'type'                            => 'payment',
				'data_id'                         => '5550002',
			),
			'replies'  => array(
				array(
					'#api\.mercadopago\.com/v1/payments/5550002$#',
					200,
					array(
						'id'                 => 5550002,
						'status'             => 'approved',
						'external_reference' => (string) $order->get_id(),
						'transaction_amount' => $paid,
						'currency_id'        => $currency,
						'fee_details'        => array(
							array(
								'type'   => 'mercadopago_fee',
								'amount' => 4.50,
							),
						),
					),
				),
			),
			'orders'   => array( $order->get_id() ),
		)
	);
};

$t::test(
	'Mercado Pago Pro: an approved payment of the order total completes the order',
	function () use ( $t, $underpaid_mercadopagopro_settings, $underpaid_place, $underpaid_mercadopagopro_webhook, $underpaid_fresh ) {
		$options = $underpaid_mercadopagopro_settings();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $underpaid_place( 'mercadopagopro', 110 );
		$child = $underpaid_mercadopagopro_webhook( $order, $options, 110 );
		$saved = $underpaid_fresh( $order->get_id() );
		$t::same( '5550002', (string) $saved->get_meta_value( 'mercadopago_payment_id' ), 'payment saved on the order' );
		$t::same( true, $saved->is_paid(), 'order is paid (status ' . $saved->get_status() . ')' );
		$t::same( null, $child['result']['error'], 'webhook ran to the end' );
	}
);

$t::test(
	'Mercado Pago Pro: an approved payment for less than the order or in another currency leaves it unpaid',
	function () use ( $t, $underpaid_mercadopagopro_settings, $underpaid_place, $underpaid_next_order, $underpaid_mercadopagopro_webhook, $underpaid_still_pending ) {
		$options = $underpaid_mercadopagopro_settings();
		$t::tax( 10 );
		$product = $t::product( 100 );
		$t::add( $product );
		$short = $underpaid_place( 'mercadopagopro', 110 );
		$underpaid_mercadopagopro_webhook( $short, $options, 11 );
		$underpaid_still_pending( $short->get_id(), '11 of 110: ' );

		$underpaid_next_order();
		$t::add( $product );
		$reais = $underpaid_place( 'mercadopagopro', 110 );
		$underpaid_mercadopagopro_webhook( $reais, $options, 110, 'BRL' );
		$underpaid_still_pending( $reais->get_id(), 'paid in reais: ' );
	}
);

/*
 * Authorize.net charges the card from the site when the order is submitted (process()). A
 * prepaid or gift card can be "partially approved": Authorize.net approves only what is on the
 * card and says more payment is needed (response code 4, message 295).
 */

/**
 * Submit the order with Authorize.net answering $reply. The real payment method is pointed at a
 * local file holding the reply instead of Authorize.net, so nothing is sent. Returns the
 * requests that would have been sent (read from the library's log).
 */
$underpaid_authorizenet_submit = function ( $reply ) use ( $t, $underpaid_submit ) {
	if ( ! class_exists( 'SPC_Payment_Method_Authorizenet' ) || ! class_exists( '\net\authorize\util\LogFactory' ) ) {
		$t::skip( 'Authorize.net add-on is not active' );
	}
	$folder = trailingslashit( get_temp_dir() ) . 'spc-underpaid-anet-' . wp_generate_password( 10, false );
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
		$underpaid_submit( null, 'Authorize.net submit' );
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

// Authorize.net's reply. $transaction is merged into the transaction response.
$underpaid_authorizenet_reply = function ( $transaction ) {
	return array(
		'transactionResponse' => array_merge(
			array(
				'responseCode'  => '1',
				'authCode'      => 'SPCTST',
				'transId'       => '60000000001',
				'accountNumber' => 'XXXX1111',
				'messages'      => array(
					array(
						'code'        => '1',
						'description' => 'This transaction has been approved.',
					),
				),
			),
			$transaction
		),
		'refId'               => 'spctest',
		'messages'            => array(
			'resultCode' => 'Ok',
			'message'    => array(
				array(
					'code' => 'I00001',
					'text' => 'Successful.',
				),
			),
		),
	);
};

$underpaid_authorizenet_use = function () use ( $underpaid_settings ) {
	$underpaid_settings(
		array(
			'authorizenet_active'           => 1,
			'authorizenet_mode'             => 'test',
			'authorizenet_transaction_type' => 'capture',
			'authorizenet_fee'              => 'none',
		)
	);
};

$t::test(
	'Authorize.net: an approved charge of the order total completes the order',
	function () use ( $t, $underpaid_authorizenet_use, $underpaid_place, $underpaid_authorizenet_submit, $underpaid_authorizenet_reply, $underpaid_fresh ) {
		$underpaid_authorizenet_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order    = $underpaid_place( 'authorizenet', 110 );
		$requests = $underpaid_authorizenet_submit( $underpaid_authorizenet_reply( array() ) );
		$t::money( 110, isset( $requests[0]['transactionRequest']['amount'] ) ? $requests[0]['transactionRequest']['amount'] : null, 'Authorize.net is asked for the order total' );
		$t::same( array(), (array) SPC()->cart->get_errors(), 'no checkout errors' );
		$t::same( true, $underpaid_fresh( $order->get_id() )->is_paid(), 'order is paid' );
	}
);

// A prepaid card with $50 on it: Authorize.net approves 50.00 of 110.00 and says another
// payment is needed for the rest.
$t::test(
	'Authorize.net: a charge only partly approved leaves the order unpaid',
	function () use ( $t, $underpaid_authorizenet_use, $underpaid_place, $underpaid_authorizenet_submit, $underpaid_authorizenet_reply, $underpaid_still_pending ) {
		$underpaid_authorizenet_use();
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$order = $underpaid_place( 'authorizenet', 110 );
		$underpaid_authorizenet_submit(
			$underpaid_authorizenet_reply(
				array(
					'responseCode'  => '4',
					'transId'       => '60000000002',
					'splitTenderId' => '115901',
					'prePaidCard'   => array(
						'requestedAmount' => '110.00',
						'approvedAmount'  => '50.00',
						'balanceOnCard'   => '0.00',
					),
					'messages'      => array(
						array(
							'code'        => '295',
							'description' => 'The amount of this request was only partially approved on the given prepaid card. An additional payment is required to fulfill the balance of this transaction.',
						),
					),
				)
			)
		);
		$underpaid_still_pending( $order->get_id() );
	}
);
