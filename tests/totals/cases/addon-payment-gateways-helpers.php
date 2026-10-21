<?php
/**
 * Shared helpers for the payment gateway add-on tests (Mollie, Paystack, PayFast, Mercado Pago,
 * QuickPay, Systempay, Authorize.net, and the Stripe Pro / Square Pro fee add-ons).
 *
 * Each test builds a cart, places the order, then starts the payment the way the checkout does
 * and checks the amount the gateway would be asked to charge. Nothing ever reaches a gateway:
 * - Requests made through WordPress are caught with pre_http_request and answered here.
 * - Mollie and Authorize.net use their own libraries, which don't go through WordPress. Those
 *   tests swap in a stand-in connection (Mollie) or point the library at a local file
 *   (Authorize.net), so the request is recorded and never sent.
 * - Redirects, wp_die(), JSON replies and emails are stopped, so a gateway that ends with
 *   exit comes back to the test instead.
 *
 * This file only sets up helpers. The tests are in the other addon-payment-gateways files.
 */

$t = 'SPC_Totals_Test';

// The customer picked this payment method at checkout. The method is switched on and its fee
// is set for this test only, never from the site's own settings.
$addon_gateways_use = function ( $id, $fee_type = 'none', $fee_amount = '' ) use ( $t ) {
	$t::option( $id . '_active', 1 );
	$t::option( $id . '_mode', 'test' );
	$t::option( $id . '_fee', $fee_type );
	$t::option( $id . '_fee_amount', (string) $fee_amount );
	$t::option( $id . '_fee_name', 'Card fee' );
	SPC()->cart->set_checkout_data_item( 'payment_method', $id );
};

// Log in a customer with store credit and use it at checkout. Call before adding products.
$addon_gateways_credits = function ( $amount ) use ( $t ) {
	$customer_id = $t::customer();
	update_user_meta( $customer_id, 'sunshine_credits', $amount );
	SPC()->customer = new SPC_Customer( $customer_id );
	SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
};

// $100 print with 10% tax, $10 of taxed shipping, 20% off with a code, and a $30 gift card.
// 100 - 20 = 80, + 8 tax, + 10 shipping, + 1 shipping tax = 99, - 30 gift card = 69.
$addon_gateways_cart_card = function () use ( $t ) {
	$t::tax( 10 );
	$t::add( $t::product( 100 ) );
	$t::flat_shipping( 10, true );
	$t::discount( 'percent-total', 20 );
	$t::gift_card( 30 );
};

// Three $19.99 prints with 7.25% tax and $8.95 of taxed shipping.
// Tax: 59.97 x 7.25% = 4.3478 -> 4.35. Shipping tax: 8.95 x 7.25% = 0.6489 -> 0.65.
// 59.97 + 4.35 + 8.95 + 0.65 = 73.92.
$addon_gateways_cart_odd = function () use ( $t ) {
	$t::tax( 7.25 );
	$t::add( $t::product( 19.99 ), 3 );
	$t::flat_shipping( 8.95, true );
};

// Fill in the checkout address, work out the cart the way the payment step shows it, check its
// total, place the order and check the order total too.
$addon_gateways_place = function ( $id, $expected_total ) use ( $t ) {
	$t::checkout_address();
	$cart = $t::calculate();
	$t::money( $expected_total, $cart->get_total(), 'cart total' );
	$order = $t::place_order( $id );
	$t::money( $expected_total, $order->get_total(), 'order total' );
	return $order;
};

/**
 * Run $callback with every outgoing WordPress request caught before it leaves the site.
 * $reply gets each request and returns array( status code, reply body ). Redirects, wp_die(),
 * JSON replies, order emails and other mail are stopped too. Set $ajax for code that answers
 * an ajax call with wp_send_json().
 *
 * Returns 'sent' (each request: url, host, path, method, body), 'redirect' (where the customer
 * would have been sent) and 'output' (anything printed).
 */
$addon_gateways_run = function ( $callback, $reply = null, $ajax = false ) {
	$sent     = array();
	$redirect = null;
	$http     = function ( $pre, $args, $url ) use ( &$sent, $reply ) {
		$request = array(
			'url'    => $url,
			'host'   => (string) wp_parse_url( $url, PHP_URL_HOST ),
			'path'   => (string) wp_parse_url( $url, PHP_URL_PATH ),
			'method' => isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET',
			'body'   => isset( $args['body'] ) ? $args['body'] : null,
		);
		$sent[]  = $request;
		$answer  = $reply ? $reply( $request ) : null;
		if ( empty( $answer ) ) {
			$answer = array( 400, array( 'message' => 'Blocked by the totals tests' ) );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $answer[1] ),
			'response' => array(
				'code'    => $answer[0],
				'message' => 'Totals test',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	$stop_redirect = function ( $location ) use ( &$redirect ) {
		$redirect = $location;
		throw new SPC_Totals_Test_Done();
	};
	$stop_die      = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};

	add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
	// Before WP-CLI's own redirect warning, which would print a long backtrace.
	add_filter( 'wp_redirect', $stop_redirect, -PHP_INT_MAX );
	add_filter( 'wp_die_handler', $stop_die, PHP_INT_MAX );
	add_filter( 'wp_die_ajax_handler', $stop_die, PHP_INT_MAX );
	add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
	add_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
	if ( $ajax ) {
		add_filter( 'wp_doing_ajax', '__return_true', PHP_INT_MAX );
	}
	ob_start();
	try {
		$callback();
	} catch ( SPC_Totals_Test_Done $e ) {
		// The payment method finished by redirecting, dying or sending a JSON reply.
	} finally {
		$output = ob_get_clean();
		remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
		remove_filter( 'wp_redirect', $stop_redirect, -PHP_INT_MAX );
		remove_filter( 'wp_die_handler', $stop_die, PHP_INT_MAX );
		remove_filter( 'wp_die_ajax_handler', $stop_die, PHP_INT_MAX );
		remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
		remove_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
		remove_filter( 'wp_doing_ajax', '__return_true', PHP_INT_MAX );
	}
	return array(
		'sent'     => $sent,
		'redirect' => $redirect,
		'output'   => $output,
	);
};

// The first caught request whose path matches $pattern, or null.
$addon_gateways_find = function ( $sent, $pattern, $method = '' ) {
	foreach ( $sent as $request ) {
		if ( preg_match( $pattern, $request['path'] ) && ( '' === $method || $method === $request['method'] ) ) {
			return $request;
		}
	}
	return null;
};

// A request body the way the gateway receives it. Form bodies (arrays) are encoded the way
// WordPress sends them, so every value comes back as the exact text that went over the wire.
// JSON bodies are decoded.
$addon_gateways_wire = function ( $request ) {
	if ( empty( $request ) || ! isset( $request['body'] ) ) {
		return array();
	}
	if ( is_array( $request['body'] ) ) {
		parse_str( http_build_query( $request['body'], '', '&' ), $received );
		return $received;
	}
	$decoded = json_decode( (string) $request['body'], true );
	return is_array( $decoded ) ? $decoded : array();
};

// Ask a payment method to do an order screen action (refund, capture) the way the order screen
// does, with the amount typed into the form. Returns what was caught by $addon_gateways_run.
$addon_gateways_order_action = function ( $callback, $post, $reply ) use ( $addon_gateways_run ) {
	foreach ( $post as $key => $value ) {
		$_POST[ $key ] = $value;
	}
	try {
		return $addon_gateways_run( $callback, $reply );
	} finally {
		foreach ( array_keys( $post ) as $key ) {
			unset( $_POST[ $key ] );
		}
	}
};

/**
 * Mollie, with a stand-in for its connection so nothing is sent. Returns array( payment method,
 * connection ). The connection records each call in ->sent (method, url, body) and answers with
 * ->reply( $method, $url, $body ), which returns the reply as an array.
 */
$addon_gateways_mollie = function ( $reply ) use ( $t ) {
	if ( ! class_exists( 'SPC_Payment_Method_Mollie' ) ) {
		$t::skip( 'Mollie add-on is not active' );
	}
	require_once plugin_dir_path( ( new ReflectionClass( 'SPC_Payment_Method_Mollie' ) )->getFileName() ) . 'vendor/autoload.php';

	$connection = new class( $reply ) implements \Mollie\Api\HttpAdapter\MollieHttpAdapterInterface {
		public $sent = array();
		private $reply;
		public function __construct( $reply ) {
			$this->reply = $reply;
		}
		public function send( $http_method, $url, $headers, $http_body ) {
			$this->sent[] = array(
				'method' => $http_method,
				'url'    => $url,
				'body'   => null === $http_body ? null : json_decode( $http_body, true ),
			);
			$answer = call_user_func( $this->reply, $http_method, $url, $http_body );
			return null === $answer ? null : json_decode( wp_json_encode( $answer ) );
		}
		public function versionString() {
			return 'SunshineTotalsTest/1.0';
		}
	};

	$client = new \Mollie\Api\MollieApiClient( $connection );
	$client->setApiKey( 'test_' . str_repeat( 'spctotals', 4 ) );

	// The real Mollie payment method, except setup() hands it the client above instead of
	// making one that talks to Mollie. Its constructor is skipped so no hooks are added twice.
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

	return array( $mollie, $connection );
};

/**
 * Run $callback( $gateway ) with the Authorize.net payment method pointed at a local file
 * instead of Authorize.net, so the request never leaves the site. The file holds an approved
 * reply. The library writes each request to its log before sending it, and that log is how the
 * request is read back. Returns every request sent, decoded.
 */
$addon_gateways_authorizenet = function ( $callback ) use ( $t ) {
	if ( ! class_exists( 'SPC_Payment_Method_Authorizenet' ) || ! class_exists( '\net\authorize\util\LogFactory' ) ) {
		$t::skip( 'Authorize.net add-on is not active' );
	}

	$folder = trailingslashit( get_temp_dir() ) . 'spc-totals-anet-' . wp_generate_password( 10, false );
	wp_mkdir_p( $folder . '/xml/v1' );
	$reply_file = $folder . '/xml/v1/request.api';
	$log_file   = $folder . '/request.log';
	file_put_contents(
		$reply_file,
		wp_json_encode(
			array(
				'transactionResponse' => array(
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
			)
		)
	);

	$logger   = \net\authorize\util\LogFactory::getLog( 'SunshineTotalsTest' );
	$old_log  = $logger->getLogFile();
	$endpoint = 'file://' . $folder;
	$gateway  = new class( $endpoint ) extends SPC_Payment_Method_Authorizenet {
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

	$logger->setLogFile( $log_file );
	try {
		$callback( $gateway );
	} finally {
		$logger->setLogFile( $old_log );
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
