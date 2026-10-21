<?php
/**
 * Shared helpers for the payment results tests (payment-results-*.php).
 *
 * Those tests check what Sunshine records when a payment service reports back about a payment
 * that did not simply go through: a card that is declined or held for review, a payment notice
 * that is not a finished payment, a customer who cancels on the payment page, and a refund made
 * in the payment service's own dashboard. An order only counts as paid when the money really
 * came in. A paid order is never thrown away. A refund takes off exactly what was refunded, once.
 *
 * Nothing ever reaches a payment service. Every reply is made up here, and every outgoing
 * request is caught before it leaves the site.
 *
 * Handlers that end with exit (PayPal Legacy IPN and form, PayFast ITN) run in a separate WP-CLI
 * process (see $payment_results_child). These helpers are copied from
 * underpaid-checks-helpers.php so the payment-results files can run on their own.
 *
 * This file only sets up helpers. The tests are in the other payment-results files.
 */

$t = 'SPC_Totals_Test';

// If a handler ends the PHP process while a test is running inside this one, still clean up
// what the tests made and say what happened, instead of leaving test orders behind.
$GLOBALS['spc_payres_running'] = '';
register_shutdown_function(
	function () {
		if ( empty( $GLOBALS['spc_payres_running'] ) ) {
			return;
		}
		WP_CLI::log( 'FAIL payment-results: the run ended in the middle of "' . $GLOBALS['spc_payres_running'] . '" (a handler called exit). Cleaning up.' );
		SPC_Totals_Test::cleanup();
		SPC_Totals_Test::report();
	}
);

// Set store settings for this test (in memory) and return them, so a separate process can be
// given the same ones.
$payment_results_settings = function ( $options ) use ( $t ) {
	foreach ( $options as $key => $value ) {
		$t::option( $key, $value );
	}
	return $options;
};

// Fill in the checkout, check the cart total, place the order with this payment method and
// check the order total. The order is "pending" until a payment completes it.
$payment_results_place = function ( $method_id, $expected_total, $label = '' ) use ( $t ) {
	SPC()->cart->set_checkout_data_item( 'payment_method', $method_id );
	$t::checkout_address();
	$cart = $t::calculate();
	$t::money( $expected_total, $cart->get_total(), $label . 'cart total' );
	$order = $t::place_order( $method_id );
	$t::money( $expected_total, $order->get_total(), $label . 'order total' );
	return $order;
};

// The order as it is saved right now. Another process may have changed it, so nothing is
// read from this process's cache.
$payment_results_fresh = function ( $order_id ) {
	clean_post_cache( $order_id );
	wp_cache_delete( $order_id, 'post_meta' );
	wp_cache_delete( $order_id, 'sunshine-order-status_relationships' );
	return sunshine_get_order( $order_id );
};

// True once the order has been finished by a payment: a paid status and the "post processed"
// mark that checkout and the payment webhooks set when they finish an order.
$payment_results_finished = function ( $order ) {
	return $order->is_paid() || '' !== (string) $order->get_meta_value( 'post_processed' );
};

// The order is still waiting for payment and was not finished.
$payment_results_unpaid = function ( $order_id, $label = '' ) use ( $t, $payment_results_fresh, $payment_results_finished ) {
	$saved = $payment_results_fresh( $order_id );
	$t::same( 'pending', $saved->get_status(), $label . 'order still waits for payment' );
	$t::same( false, $payment_results_finished( $saved ), $label . 'order not finished' );
};

// True when the order is still saved (it was not deleted).
$payment_results_exists = function ( $order_id ) {
	clean_post_cache( $order_id );
	$post = get_post( $order_id );
	return ! empty( $post ) && 'sunshine-order' === $post->post_type;
};

/**
 * Run $callback with every outgoing request caught before it leaves the site.
 * $reply gets each request (url, host, path, method, body) and returns array( status code,
 * reply ) where the reply is an array (sent as JSON) or a string (sent as it is). Anything
 * without a reply gets an error. Redirects, wp_die(), JSON replies, order emails and all other
 * mail are stopped too.
 *
 * Returns 'sent' (each request), 'redirect' (where the customer would have been sent) and
 * 'output' (anything printed).
 */
$payment_results_run = function ( $callback, $reply = null, $label = '' ) {
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
			'body'     => is_string( $answer[1] ) ? $answer[1] : wp_json_encode( $answer[1] ),
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
	$GLOBALS['spc_payres_running'] = $label ? $label : 'a payment step';
	ob_start();
	try {
		$callback();
	} catch ( SPC_Totals_Test_Done $e ) {
		// The payment step finished by redirecting, dying or sending a JSON reply.
	} finally {
		$output                        = ob_get_clean();
		$GLOBALS['spc_payres_running'] = '';
		remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
		remove_filter( 'wp_redirect', $stop_redirect, -PHP_INT_MAX );
		remove_filter( 'wp_die_handler', $stop_die, PHP_INT_MAX );
		remove_filter( 'wp_die_ajax_handler', $stop_die, PHP_INT_MAX );
		remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
		remove_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
	}
	return array(
		'sent'     => $sent,
		'redirect' => $redirect,
		'output'   => $output,
	);
};

// Open a link the customer was given (a return or cancel link): its query is put in $_GET and
// $_REQUEST while $callback runs, with every outgoing request caught as in $payment_results_run.
$payment_results_visit = function ( $url, $callback, $reply = null, $label = '' ) use ( $payment_results_run ) {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	foreach ( $query as $key => $value ) {
		$_GET[ $key ]     = $value;
		$_REQUEST[ $key ] = $value;
	}
	try {
		return $payment_results_run( $callback, $reply, $label );
	} finally {
		foreach ( array_keys( $query ) as $key ) {
			unset( $_GET[ $key ], $_REQUEST[ $key ] );
		}
	}
};

// The first caught request whose path matches $pattern, or null.
$payment_results_find = function ( $sent, $pattern, $method = '' ) {
	foreach ( $sent as $request ) {
		if ( preg_match( $pattern, $request['path'] ) && ( '' === $method || $method === $request['method'] ) ) {
			return $request;
		}
	}
	return null;
};

// A form or JSON request body, decoded the way the payment service would read it.
$payment_results_wire = function ( $request ) {
	if ( empty( $request ) || ! isset( $request['body'] ) ) {
		return array();
	}
	if ( is_array( $request['body'] ) ) {
		parse_str( http_build_query( $request['body'], '', '&' ), $received );
		return $received;
	}
	$decoded = json_decode( (string) $request['body'], true );
	if ( is_array( $decoded ) ) {
		return $decoded;
	}
	parse_str( (string) $request['body'], $received );
	return $received;
};

// The checkout submits the order: the same step the checkout page runs (SPC_Cart::process_order).
// It runs the payment method's own payment step, and only when that step finds no problem is
// the order finished and marked paid.
$payment_results_submit = function ( $reply, $label = 'checkout submit' ) use ( $payment_results_run ) {
	return $payment_results_run(
		function () {
			SPC()->cart->process_order();
		},
		$reply,
		$label
	);
};

// Start a second order in the same test: an empty cart and no order in progress, so the next
// order placed is a new one.
$payment_results_next_order = function () {
	SPC()->cart->empty_cart();
	SPC()->session->set( 'checkout_order_id', '' );
	SPC()->cart = new SPC_Cart();
};

// The hidden fields of a payment form printed by a handler, as name => value.
$payment_results_form_fields = function ( $html ) {
	$fields = array();
	if ( preg_match_all( '/<input type="hidden" name="([^"]*)" value="([^"]*)"/', $html, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$fields[ html_entity_decode( $match[1], ENT_QUOTES ) ] = html_entity_decode( $match[2], ENT_QUOTES );
		}
	}
	return $fields;
};

/*
 * A separate WP-CLI process for handlers that end with exit. The script below is loaded twice
 * in that process: first with --require, before WordPress starts, to put the settings, the
 * request blocker and the mail blocker in place; then with eval-file, to make the request the
 * payment service would make (GET, POST and the raw body) and call the handler. When the
 * process ends, normally or through exit, it prints one result line.
 */
$payment_results_child_script = <<<'CHILD'
<?php
$spc_payres = json_decode( (string) file_get_contents( getenv( 'SPC_PAYRES_PAYLOAD' ) ), true );
if ( ! is_array( $spc_payres ) ) {
	return;
}

if ( ! function_exists( 'add_filter' ) ) {
	// Before WordPress starts.
	foreach ( (array) $spc_payres['options'] as $spc_key => $spc_value ) {
		WP_CLI::add_wp_hook(
			'pre_option_sunshine_' . $spc_key,
			function () use ( $spc_value ) {
				return ( false === $spc_value || null === $spc_value ) ? '' : $spc_value;
			}
		);
	}
	$GLOBALS['spc_payres_sent'] = array();
	WP_CLI::add_wp_hook(
		'pre_http_request',
		function ( $pre, $args, $url ) use ( $spc_payres ) {
			$body                         = isset( $args['body'] ) ? $args['body'] : '';
			$GLOBALS['spc_payres_sent'][] = array(
				'url'    => $url,
				'method' => isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET',
				'body'   => is_array( $body ) ? http_build_query( $body ) : (string) $body,
			);
			foreach ( (array) $spc_payres['replies'] as $reply ) {
				if ( preg_match( $reply['url'], $url ) ) {
					return array(
						'headers'  => array(),
						'body'     => is_string( $reply['body'] ) ? $reply['body'] : wp_json_encode( $reply['body'] ),
						'response' => array(
							'code'    => $reply['code'],
							'message' => 'Totals test',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}
			}
			return new WP_Error( 'spc_totals_blocked', 'Blocked by the totals tests' );
		},
		PHP_INT_MAX,
		3
	);
	WP_CLI::add_wp_hook(
		'pre_wp_mail',
		function () {
			return false;
		},
		PHP_INT_MAX
	);
	WP_CLI::add_wp_hook(
		'sunshine_checkout_allow_order_notify',
		function () {
			return false;
		},
		PHP_INT_MAX
	);
	// A payment service's server-to-server call has no shopper session; don't start one.
	WP_CLI::add_wp_hook(
		'sunshine_start_session',
		function () {
			return false;
		},
		PHP_INT_MAX
	);
	WP_CLI::add_wp_hook(
		'wp_redirect',
		function ( $location ) {
			$GLOBALS['spc_payres_redirect'] = $location;
			return false;
		},
		-PHP_INT_MAX
	);
	$spc_die = function () {
		return function ( $message = '' ) {
			$GLOBALS['spc_payres_died'] = is_scalar( $message ) ? (string) $message : 'wp_die';
			exit;
		};
	};
	WP_CLI::add_wp_hook( 'wp_die_handler', $spc_die, PHP_INT_MAX );
	WP_CLI::add_wp_hook( 'wp_die_ajax_handler', $spc_die, PHP_INT_MAX );
	return;
}

// WordPress has started: make the payment service's request and call the handler.
$_GET     = (array) $spc_payres['get'];
$_POST    = (array) $spc_payres['post'];
$_REQUEST = array_merge( $_GET, $_POST );
foreach ( (array) $spc_payres['server'] as $spc_key => $spc_value ) {
	$_SERVER[ $spc_key ] = $spc_value;
}
if ( ! empty( $spc_payres['post'] ) ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
}

register_shutdown_function(
	function () {
		echo "\nSPC_PAYRES_RESULT " . wp_json_encode(
			array(
				'sent'     => isset( $GLOBALS['spc_payres_sent'] ) ? $GLOBALS['spc_payres_sent'] : array(),
				'redirect' => isset( $GLOBALS['spc_payres_redirect'] ) ? $GLOBALS['spc_payres_redirect'] : null,
				'died'     => isset( $GLOBALS['spc_payres_died'] ) ? $GLOBALS['spc_payres_died'] : null,
				'returned' => ! empty( $GLOBALS['spc_payres_returned'] ),
				'error'    => isset( $GLOBALS['spc_payres_error'] ) ? $GLOBALS['spc_payres_error'] : null,
			)
		) . "\n";
	}
);

if ( isset( $spc_payres['raw_body'] ) && null !== $spc_payres['raw_body'] ) {
	// The handler reads the request body from php://input.
	$spc_input              = new class() {
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
	$spc_input_class        = get_class( $spc_input );
	$spc_input_class::$body = (string) $spc_payres['raw_body'];
	stream_wrapper_unregister( 'php' );
	stream_wrapper_register( 'php', $spc_input_class );
}

$spc_method = sunshine_get_payment_method_by_id( $spc_payres['method'] );
if ( ! $spc_method ) {
	$GLOBALS['spc_payres_error'] = 'No payment method ' . $spc_payres['method'];
	return;
}
$spc_args = array();
if ( ! empty( $spc_payres['order_id'] ) ) {
	$spc_args[] = sunshine_get_order( $spc_payres['order_id'] );
}
try {
	call_user_func_array( array( $spc_method, $spc_payres['function'] ), $spc_args );
	$GLOBALS['spc_payres_returned'] = true;
} catch ( Throwable $e ) {
	$GLOBALS['spc_payres_error'] = get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
}
CHILD;

/**
 * Call one payment method handler in a separate WP-CLI process. $spec:
 * - method, function: the payment method ID and the method to call on it
 * - order_id: when set, the order is passed to the handler
 * - options: store settings (key without the sunshine_ prefix)
 * - get, post, server, raw_body: the request the payment service makes
 * - replies: list of array( url regex, status code, reply ) for outgoing requests
 * - orders: IDs of orders the handler may change, so they are read fresh afterwards
 *
 * Returns 'result' (sent, redirect, died, returned, error), 'stdout', and 'warnings': every PHP
 * warning, notice or deprecation line the process printed.
 */
$payment_results_child = function ( $spec ) use ( $payment_results_child_script ) {
	$spec    = wp_parse_args(
		$spec,
		array(
			'options'  => array(),
			'get'      => array(),
			'post'     => array(),
			'server'   => array(),
			'raw_body' => null,
			'replies'  => array(),
			'orders'   => array(),
			'order_id' => 0,
		)
	);
	$replies = array();
	foreach ( $spec['replies'] as $reply ) {
		$replies[] = array(
			'url'  => $reply[0],
			'code' => $reply[1],
			'body' => $reply[2],
		);
	}
	$spec['replies'] = $replies;

	$dir = trailingslashit( get_temp_dir() ) . 'spc-payres-' . wp_generate_password( 12, false );
	wp_mkdir_p( $dir );
	$payload = $dir . '/payload.json';
	$script  = $dir . '/child.php';
	file_put_contents( $payload, wp_json_encode( $spec ) );
	file_put_contents( $script, $payment_results_child_script );
	putenv( 'SPC_PAYRES_PAYLOAD=' . $payload );
	try {
		$run = WP_CLI::runcommand(
			'eval-file ' . escapeshellarg( $script ) . ' --require=' . escapeshellarg( $script ),
			array(
				'launch'     => true,
				'return'     => 'all',
				'exit_error' => false,
			)
		);
	} finally {
		putenv( 'SPC_PAYRES_PAYLOAD' );
		foreach ( array( $payload, $script ) as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	}

	$result = null;
	if ( preg_match( '/^SPC_PAYRES_RESULT (.*)$/m', (string) $run->stdout, $match ) ) {
		$result = json_decode( $match[1], true );
	}
	if ( ! is_array( $result ) ) {
		throw new Exception( 'The separate process gave no result. stderr: ' . substr( (string) $run->stderr, 0, 400 ) );
	}
	$warnings = array();
	foreach ( preg_split( '/\R/', $run->stdout . "\n" . $run->stderr ) as $line ) {
		if ( preg_match( '/\b(Warning|Notice|Deprecated|Fatal error)\b.* in .* on line \d+/', $line ) ) {
			$warnings[] = trim( $line );
		}
	}
	// SPC_PAYRES_DEBUG=1 prints every warning the separate processes give, not just the ones a
	// test checks.
	if ( getenv( 'SPC_PAYRES_DEBUG' ) ) {
		foreach ( $warnings as $line ) {
			WP_CLI::log( 'child ' . $spec['method'] . '->' . $spec['function'] . ': ' . $line );
		}
		if ( ! empty( $result['error'] ) || ! empty( $result['died'] ) ) {
			WP_CLI::log( 'child ' . $spec['method'] . '->' . $spec['function'] . ' error/died: ' . $result['error'] . ' ' . $result['died'] );
		}
	}
	foreach ( (array) $spec['orders'] as $order_id ) {
		clean_post_cache( $order_id );
		wp_cache_delete( $order_id, 'post_meta' );
		wp_cache_delete( $order_id, 'sunshine-order-status_relationships' );
	}
	return array(
		'result'   => $result,
		'stdout'   => (string) $run->stdout,
		'warnings' => $warnings,
	);
};

// The PHP warnings a separate process printed that come from files whose path contains $part.
$payment_results_warnings_from = function ( $child, $part ) {
	return array_values(
		array_filter(
			$child['warnings'],
			function ( $line ) use ( $part ) {
				return false !== strpos( $line, $part );
			}
		)
	);
};

/*
 * PayFast. The customer is sent to PayFast with a form that holds a return link and a cancel
 * link. PayFast posts an ITN to the site (SPC_Payment_Method_Payfast::notify), signed with the
 * store's passphrase, telling it what happened to the payment. The ITN ends with exit, so it
 * runs in a separate process.
 */

$payment_results_payfast_settings = function () use ( $t, $payment_results_settings ) {
	$t::require_plugin( 'sunshine_payment_method_payfast', 'PayFast add-on' );
	return $payment_results_settings(
		array(
			'currency'                  => 'ZAR',
			'payfast_active'            => 1,
			'payfast_mode'              => 'test',
			'payfast_merchant_id_test'  => '10000100',
			'payfast_merchant_key_test' => '46f0cd694581a',
			'payfast_passphrase_test'   => 'sunshine totals',
			'payfast_fee'               => 'none',
		)
	);
};

// Send the customer to PayFast: the form fields their browser gets (amount, return_url,
// cancel_url, ...). This is also what happens when the order is submitted again.
$payment_results_payfast_form = function ( $order ) use ( $payment_results_run ) {
	$payfast = sunshine_get_payment_method_by_id( 'payfast' );
	$fields  = null;
	$grab    = function ( $args ) use ( &$fields ) {
		$fields = $args;
		throw new SPC_Totals_Test_Done();
	};
	add_filter( 'sunshine_payfast_args', $grab, PHP_INT_MAX );
	try {
		$payment_results_run(
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

// A signed ITN from PayFast about a payment of $amount rand for the order. $changes replace
// fields before signing (for example the payment_status).
$payment_results_payfast_itn = function ( $order, $amount, $changes = array() ) {
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
	// PayFast signs every field in the order sent, then the passphrase.
	$pairs = array();
	foreach ( $fields as $key => $value ) {
		$pairs[] = $key . '=' . urlencode( trim( (string) $value ) );
	}
	$fields['signature'] = md5( implode( '&', $pairs ) . '&passphrase=' . urlencode( 'sunshine totals' ) );
	return $fields;
};

// PayFast posts the ITN to the site.
$payment_results_payfast_notify = function ( $order, $options, $fields ) use ( $payment_results_child ) {
	return $payment_results_child(
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

/*
 * PayPal (Legacy). The customer is sent to PayPal with a form that lists the items and holds a
 * cancel link. PayPal tells the site about the payment with an IPN; Sunshine asks PayPal to
 * confirm the IPN is real ("VERIFIED") before using it (SPC_Payment_Method_PayPal_Legacy::ipn).
 * The form and the IPN end with exit, so they run in a separate process.
 */

$payment_results_legacy_settings = function () use ( $payment_results_settings ) {
	return $payment_results_settings(
		array(
			'currency'                 => 'USD',
			'paypal-legacy_active'     => 1,
			'paypal-legacy_mode'       => 'test',
			'paypal-legacy_email_test' => 'photos@example.com',
			'paypal-legacy_fee'        => 'none',
		)
	);
};

// Send the customer to PayPal: returns the form fields PayPal gets.
$payment_results_legacy_form = function ( $order, $options ) use ( $payment_results_child, $payment_results_form_fields ) {
	$child = $payment_results_child(
		array(
			'method'   => 'paypal-legacy',
			'function' => 'process_payment',
			'order_id' => $order->get_id(),
			'options'  => $options,
			'orders'   => array( $order->get_id() ),
		)
	);
	return $payment_results_form_fields( $child['stdout'] );
};

// PayPal sends an IPN about the order. $ipn holds the fields that differ from a completed
// payment of $gross dollars to the store; a field set to null is left out. $verify is what
// PayPal answers when asked whether the IPN is real.
$payment_results_legacy_ipn = function ( $order, $options, $gross, $ipn = array(), $verify = 'VERIFIED' ) use ( $payment_results_child ) {
	$fields = array_merge(
		array(
			'txn_type'       => 'cart',
			'payment_status' => 'Completed',
			'mc_gross'       => $gross,
			'mc_currency'    => 'USD',
			'receiver_email' => 'photos@example.com',
			'receiver_id'    => 'SPCRECEIVER',
			'business'       => 'photos@example.com',
			'custom'         => (string) $order->get_id(),
			'txn_id'         => 'SPCTXN' . $order->get_id(),
			'payer_email'    => 'buyer@example.com',
		),
		$ipn
	);
	$fields = array_filter(
		$fields,
		function ( $value ) {
			return null !== $value;
		}
	);
	return $payment_results_child(
		array(
			'method'   => 'paypal-legacy',
			'function' => 'ipn',
			'options'  => $options,
			'get'      => array( 'sunshine_paypal_ipn' => 'paypal_standard_ipn' ),
			'post'     => $fields,
			'raw_body' => http_build_query( $fields ),
			'replies'  => array( array( '#paypal\.com/cgi-bin/webscr#', 200, $verify ) ),
			'orders'   => array( $order->get_id() ),
		)
	);
};

/*
 * Mollie. The customer pays on Mollie's page. Mollie then calls the site's webhook with the
 * payment ID whenever something happens to the payment (paid, cancelled, refunded), and the site
 * looks the payment up at Mollie (SPC_Payment_Method_Mollie::webhook). A refund made in Mollie's
 * dashboard shows up as a refunds link on the payment; the site then reads the refunds and
 * records the finished ones on the order (process_refund_webhook).
 */

$payment_results_mollie_use = function () use ( $t, $payment_results_settings ) {
	if ( ! class_exists( 'SPC_Payment_Method_Mollie' ) ) {
		$t::skip( 'Mollie add-on is not active' );
	}
	$payment_results_settings(
		array(
			'mollie_active'          => 1,
			'mollie_mode'            => 'test',
			'mollie_test_secret_key' => 'test_' . str_repeat( 'spctotals', 4 ),
			'mollie_fee'             => 'none',
		)
	);
};

// A Mollie payment as Mollie's API returns it, made for order $order_id. When $refunds is not
// empty, the payment links to its refunds the way Mollie does once a payment has any.
$payment_results_mollie_payment = function ( $id, $order_id, $value, $status = 'paid', $refunds = array() ) {
	$payment = array(
		'resource'    => 'payment',
		'id'          => $id,
		'mode'        => 'test',
		'status'      => $status,
		'amount'      => array(
			'value'    => $value,
			'currency' => 'USD',
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
	if ( 'paid' === $status ) {
		// A paid payment always says how much was refunded and how much can still be refunded.
		$refunded = 0;
		foreach ( $refunds as $refund ) {
			if ( 'failed' !== $refund['status'] && 'canceled' !== $refund['status'] ) {
				$refunded += (float) $refund['amount']['value'];
			}
		}
		$payment['paidAt']          = '2026-10-17T10:00:00+00:00';
		$payment['amountRefunded']  = array(
			'value'    => number_format( $refunded, 2, '.', '' ),
			'currency' => 'USD',
		);
		$payment['amountRemaining'] = array(
			'value'    => number_format( (float) $value - $refunded, 2, '.', '' ),
			'currency' => 'USD',
		);
	}
	if ( ! empty( $refunds ) ) {
		$payment['_links']['refunds'] = array(
			'href' => 'https://api.mollie.com/v2/payments/' . $id . '/refunds',
			'type' => 'application/hal+json',
		);
	}
	return $payment;
};

// One refund as Mollie lists it.
$payment_results_mollie_refund = function ( $id, $payment_id, $value, $status = 'refunded' ) {
	return array(
		'resource'    => 'refund',
		'id'          => $id,
		'amount'      => array(
			'value'    => $value,
			'currency' => 'USD',
		),
		'status'      => $status,
		'createdAt'   => '2026-10-17T11:00:00+00:00',
		'description' => '',
		'paymentId'   => $payment_id,
		'_links'      => array(
			'self' => array(
				'href' => 'https://api.mollie.com/v2/payments/' . $payment_id . '/refunds/' . $id,
				'type' => 'application/hal+json',
			),
		),
	);
};

// A stand-in for Mollie's connection that answers the look up of $payment, its refunds list
// ($refunds) and the creation of a refund, and refuses everything else. Nothing is sent.
$payment_results_mollie_client = function ( $payment, $refunds = array() ) {
	require_once plugin_dir_path( ( new ReflectionClass( 'SPC_Payment_Method_Mollie' ) )->getFileName() ) . 'vendor/autoload.php';
	$connection = new class( $payment, $refunds ) implements \Mollie\Api\HttpAdapter\MollieHttpAdapterInterface {
		public $sent = array();
		private $payment;
		private $refunds;
		public function __construct( $payment, $refunds ) {
			$this->payment = $payment;
			$this->refunds = $refunds;
		}
		public function send( $http_method, $url, $headers, $http_body ) {
			$this->sent[] = array(
				'method' => $http_method,
				'url'    => $url,
				'body'   => $http_body ? json_decode( $http_body, true ) : null,
			);
			$base         = '#/v2/payments/' . preg_quote( $this->payment['id'], '#' );
			if ( 'GET' === $http_method && preg_match( $base . '$#', $url ) ) {
				return json_decode( wp_json_encode( $this->payment ) );
			}
			if ( 'GET' === $http_method && preg_match( $base . '/refunds$#', $url ) ) {
				return json_decode(
					wp_json_encode(
						array(
							'count'     => count( $this->refunds ),
							'_embedded' => array( 'refunds' => $this->refunds ),
							'_links'    => array(
								'self'     => array(
									'href' => $url,
									'type' => 'application/hal+json',
								),
								'previous' => null,
								'next'     => null,
							),
						)
					)
				);
			}
			if ( 'POST' === $http_method && preg_match( $base . '/refunds$#', $url ) ) {
				$request = json_decode( (string) $http_body, true );
				return json_decode(
					wp_json_encode(
						array(
							'resource'    => 'refund',
							'id'          => 're_spcadmin',
							'amount'      => $request['amount'],
							'status'      => 'pending',
							'description' => isset( $request['description'] ) ? $request['description'] : '',
							'paymentId'   => $this->payment['id'],
						)
					)
				);
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
	return array( $mollie, $connection );
};

// Mollie calls the webhook for $payment (with its $refunds, if any). Returns what the site asked
// Mollie for.
$payment_results_mollie_webhook = function ( $payment, $refunds = array() ) use ( $payment_results_run, $payment_results_mollie_client ) {
	list( $mollie, $connection ) = $payment_results_mollie_client( $payment, $refunds );
	$_GET['sunshine_mollie_webhook'] = '1';
	$_POST['id']                     = $payment['id'];
	try {
		$payment_results_run(
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
