<?php
/**
 * Shared helpers for the underpaid payment tests (underpaid-checks-*.php).
 *
 * Those tests check the code that decides whether a payment really paid an order: the right
 * amount, in the store's currency, to the store's own account, for this order. A payment that
 * is too small, in another currency, sent to someone else, or made for a different order must
 * leave the order unpaid ("pending").
 *
 * Nothing ever reaches a payment gateway. Every reply from a gateway is made up here.
 *
 * Some gateway handlers (PayPal Legacy IPN, PayFast ITN, Systempay IPN, QuickPay callback,
 * Mercado Pago Checkout Pro webhook) end with exit, which would stop the whole test run. Those
 * run in a separate WP-CLI process instead (see $underpaid_child). That process gets the same
 * in-memory settings, blocks every outgoing request before WordPress even starts, and never
 * sends mail. It only changes orders these tests created.
 *
 * This file only sets up helpers. The tests are in the other underpaid-checks files.
 */

$t = 'SPC_Totals_Test';

// If a handler ends the PHP process while a test is running inside this one, still clean up
// what the tests made and say what happened, instead of leaving test orders behind.
$GLOBALS['spc_underpaid_running'] = '';
register_shutdown_function(
	function () {
		if ( empty( $GLOBALS['spc_underpaid_running'] ) ) {
			return;
		}
		WP_CLI::log( 'FAIL underpaid-checks: the run ended in the middle of "' . $GLOBALS['spc_underpaid_running'] . '" (a handler called exit). Cleaning up.' );
		SPC_Totals_Test::cleanup();
		SPC_Totals_Test::report();
	}
);

// Set store settings for this test (in memory) and return them, so a separate process can be
// given the same ones.
$underpaid_settings = function ( $options ) use ( $t ) {
	foreach ( $options as $key => $value ) {
		$t::option( $key, $value );
	}
	return $options;
};

// Fill in the checkout, check the cart total, place the order with this payment method and
// check the order total. The order is "pending" until a payment completes it.
$underpaid_place = function ( $method_id, $expected_total ) use ( $t ) {
	SPC()->cart->set_checkout_data_item( 'payment_method', $method_id );
	$t::checkout_address();
	$cart = $t::calculate();
	$t::money( $expected_total, $cart->get_total(), 'cart total' );
	$order = $t::place_order( $method_id );
	$t::money( $expected_total, $order->get_total(), 'order total' );
	$t::same( 'pending', $order->get_status(), 'new order waits for payment' );
	return $order;
};

// The order as it is saved right now. Another process may have changed it, so nothing is
// read from this process's cache.
$underpaid_fresh = function ( $order_id ) {
	clean_post_cache( $order_id );
	wp_cache_delete( $order_id, 'post_meta' );
	return sunshine_get_order( $order_id );
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
$underpaid_run = function ( $callback, $reply = null, $label = '' ) {
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
	$GLOBALS['spc_underpaid_running'] = $label ? $label : 'a payment step';
	ob_start();
	try {
		$callback();
	} catch ( SPC_Totals_Test_Done $e ) {
		// The payment step finished by redirecting, dying or sending a JSON reply.
	} finally {
		$output                           = ob_get_clean();
		$GLOBALS['spc_underpaid_running'] = '';
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

// The first caught request whose path matches $pattern, or null.
$underpaid_find = function ( $sent, $pattern, $method = '' ) {
	foreach ( $sent as $request ) {
		if ( preg_match( $pattern, $request['path'] ) && ( '' === $method || $method === $request['method'] ) ) {
			return $request;
		}
	}
	return null;
};

// A form or JSON request body, decoded the way the gateway would read it.
$underpaid_wire = function ( $request ) {
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

// The checkout submits the order after the customer paid: the same step the checkout page runs
// (SPC_Cart::process_order). It runs the payment method's own payment check, and only when
// that check finds no problem is the order finished and marked paid.
$underpaid_submit = function ( $reply, $label = 'checkout submit' ) use ( $underpaid_run ) {
	return $underpaid_run(
		function () {
			SPC()->cart->process_order();
		},
		$reply,
		$label
	);
};

/*
 * A separate WP-CLI process for handlers that end with exit. The script below is loaded twice
 * in that process: first with --require, before WordPress starts, to put the settings, the
 * request blocker and the mail blocker in place; then with eval-file, to make the request the
 * gateway would make (GET, POST and the raw body) and call the handler. When the process ends,
 * normally or through exit, it prints one result line.
 */
$underpaid_child_script = <<<'CHILD'
<?php
$spc_underpaid = json_decode( (string) file_get_contents( getenv( 'SPC_UNDERPAID_PAYLOAD' ) ), true );
if ( ! is_array( $spc_underpaid ) ) {
	return;
}

if ( ! function_exists( 'add_filter' ) ) {
	// Before WordPress starts.
	foreach ( (array) $spc_underpaid['options'] as $spc_key => $spc_value ) {
		WP_CLI::add_wp_hook(
			'pre_option_sunshine_' . $spc_key,
			function () use ( $spc_value ) {
				return ( false === $spc_value || null === $spc_value ) ? '' : $spc_value;
			}
		);
	}
	$GLOBALS['spc_underpaid_sent'] = array();
	WP_CLI::add_wp_hook(
		'pre_http_request',
		function ( $pre, $args, $url ) use ( $spc_underpaid ) {
			$body                            = isset( $args['body'] ) ? $args['body'] : '';
			$GLOBALS['spc_underpaid_sent'][] = array(
				'url'    => $url,
				'method' => isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET',
				'body'   => is_array( $body ) ? http_build_query( $body ) : (string) $body,
			);
			foreach ( (array) $spc_underpaid['replies'] as $reply ) {
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
	// A gateway's server-to-server call has no shopper session; don't start one.
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
			$GLOBALS['spc_underpaid_redirect'] = $location;
			return false;
		},
		-PHP_INT_MAX
	);
	$spc_die = function () {
		return function ( $message = '' ) {
			$GLOBALS['spc_underpaid_died'] = is_scalar( $message ) ? (string) $message : 'wp_die';
			exit;
		};
	};
	WP_CLI::add_wp_hook( 'wp_die_handler', $spc_die, PHP_INT_MAX );
	WP_CLI::add_wp_hook( 'wp_die_ajax_handler', $spc_die, PHP_INT_MAX );
	return;
}

// WordPress has started: make the gateway's request and call the handler.
$_GET     = (array) $spc_underpaid['get'];
$_POST    = (array) $spc_underpaid['post'];
$_REQUEST = array_merge( $_GET, $_POST );
foreach ( (array) $spc_underpaid['server'] as $spc_key => $spc_value ) {
	$_SERVER[ $spc_key ] = $spc_value;
}
if ( ! empty( $spc_underpaid['post'] ) ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
}

register_shutdown_function(
	function () {
		echo "\nSPC_UNDERPAID_RESULT " . wp_json_encode(
			array(
				'sent'     => isset( $GLOBALS['spc_underpaid_sent'] ) ? $GLOBALS['spc_underpaid_sent'] : array(),
				'redirect' => isset( $GLOBALS['spc_underpaid_redirect'] ) ? $GLOBALS['spc_underpaid_redirect'] : null,
				'died'     => isset( $GLOBALS['spc_underpaid_died'] ) ? $GLOBALS['spc_underpaid_died'] : null,
				'returned' => ! empty( $GLOBALS['spc_underpaid_returned'] ),
				'error'    => isset( $GLOBALS['spc_underpaid_error'] ) ? $GLOBALS['spc_underpaid_error'] : null,
			)
		) . "\n";
	}
);

if ( isset( $spc_underpaid['raw_body'] ) && null !== $spc_underpaid['raw_body'] ) {
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
	$spc_input_class::$body = (string) $spc_underpaid['raw_body'];
	stream_wrapper_unregister( 'php' );
	stream_wrapper_register( 'php', $spc_input_class );
}

$spc_method = sunshine_get_payment_method_by_id( $spc_underpaid['method'] );
if ( ! $spc_method ) {
	$GLOBALS['spc_underpaid_error'] = 'No payment method ' . $spc_underpaid['method'];
	return;
}
$spc_args = array();
if ( ! empty( $spc_underpaid['order_id'] ) ) {
	$spc_args[] = sunshine_get_order( $spc_underpaid['order_id'] );
}
try {
	call_user_func_array( array( $spc_method, $spc_underpaid['function'] ), $spc_args );
	$GLOBALS['spc_underpaid_returned'] = true;
} catch ( Throwable $e ) {
	$GLOBALS['spc_underpaid_error'] = get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
}
CHILD;

/**
 * Call one payment method handler in a separate WP-CLI process. $spec:
 * - method, function: the payment method ID and the method to call on it
 * - order_id: when set, the order is passed to the handler
 * - options: store settings (key without the sunshine_ prefix)
 * - get, post, server, raw_body: the request the gateway makes
 * - replies: list of array( url regex, status code, reply ) for outgoing requests
 * - orders: IDs of orders the handler may change, so they are read fresh afterwards
 *
 * Returns 'result' (sent, redirect, died, returned, error), 'stdout', and 'warnings': every PHP
 * warning, notice or deprecation line the process printed.
 */
$underpaid_child = function ( $spec ) use ( $underpaid_child_script ) {
	$spec = wp_parse_args(
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

	$dir = trailingslashit( get_temp_dir() ) . 'spc-underpaid-' . wp_generate_password( 12, false );
	wp_mkdir_p( $dir );
	$payload = $dir . '/payload.json';
	$script  = $dir . '/child.php';
	file_put_contents( $payload, wp_json_encode( $spec ) );
	file_put_contents( $script, $underpaid_child_script );
	putenv( 'SPC_UNDERPAID_PAYLOAD=' . $payload );
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
		putenv( 'SPC_UNDERPAID_PAYLOAD' );
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
	if ( preg_match( '/^SPC_UNDERPAID_RESULT (.*)$/m', (string) $run->stdout, $match ) ) {
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
	// SPC_UNDERPAID_DEBUG=1 prints every warning the separate processes give, not just the ones
	// a test checks.
	if ( getenv( 'SPC_UNDERPAID_DEBUG' ) ) {
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
	}
	return array(
		'result'   => $result,
		'stdout'   => (string) $run->stdout,
		'warnings' => $warnings,
	);
};

// The PHP warnings a separate process printed that come from files whose path contains $part.
$underpaid_warnings_from = function ( $child, $part ) {
	return array_values(
		array_filter(
			$child['warnings'],
			function ( $line ) use ( $part ) {
				return false !== strpos( $line, $part );
			}
		)
	);
};

// The hidden fields of a payment form printed by a handler, as name => value.
$underpaid_form_fields = function ( $html ) {
	$fields = array();
	if ( preg_match_all( '/<input type="hidden" name="([^"]*)" value="([^"]*)"/', $html, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$fields[ html_entity_decode( $match[1], ENT_QUOTES ) ] = html_entity_decode( $match[2], ENT_QUOTES );
		}
	}
	return $fields;
};

// Start a second order in the same test: an empty cart and no order in progress, so the next
// order placed is a new one.
$underpaid_next_order = function () {
	SPC()->cart->empty_cart();
	SPC()->session->set( 'checkout_order_id', '' );
	SPC()->cart = new SPC_Cart();
};

// True once the order has been finished by a payment: a paid status and the "post processed"
// mark that checkout and the payment webhooks set when they finish an order.
$underpaid_finished = function ( $order ) {
	return $order->is_paid() || '' !== (string) $order->get_meta_value( 'post_processed' );
};
