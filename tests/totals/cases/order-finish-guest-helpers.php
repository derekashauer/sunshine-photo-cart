<?php
/**
 * Helpers for the order-finish-guest case files: orders whose payment is confirmed by a message
 * from the payment gateway (a webhook, IPN or ITN, or Square's background check every 15 minutes)
 * instead of the customer's own browser.
 *
 * That message comes from the gateway's server. Nobody is logged in, and the session has no cart,
 * no checkout and no codes. The order must still be finished the same way checkout finishes it:
 * marked paid, store credit taken off the customer's account, the gift card balance taken off,
 * the discount code counted for the customer, and included session prints used up.
 *
 * Nothing leaves the site: every web request is caught and answered here, and emails and receipts
 * are stopped. Most of these handlers end with exit, so each run is stopped with an exception at
 * the last thing the handler saves before it exits, or when it sets the HTTP status.
 *
 * This file's name sorts before the other order-finish-guest files, so it loads first. Run them
 * together with SPC_TOTALS_ONLY=order-finish-guest.
 */

$t = 'SPC_Totals_Test';

// If a handler ever calls exit before a test can stop it (for example once a check is added
// that refuses a bad payment), the whole run would end there and leave its products, customers
// and orders behind. This cleans up, prints the results so far, and fails the run.
$finish_guest_state = (object) array(
	'inside' => '',
	'input'  => false,
);
register_shutdown_function(
	function () use ( $finish_guest_state ) {
		if ( '' === $finish_guest_state->inside ) {
			return;
		}
		if ( $finish_guest_state->input ) {
			stream_wrapper_restore( 'php' );
		}
		WP_CLI::log( 'FAIL ' . $finish_guest_state->inside . ': the payment handler ended the whole run with exit, so the tests after it did not run' );
		SPC_Totals_Test::cleanup();
		SPC_Totals_Test::report();
		exit( 1 );
	}
);

// The Analytics add-on logs a row in its own table each time a customer adds to or changes a
// cart. Remember the customers and products these files make, so those rows can be deleted at
// the end of each file. Rows that belong to anyone else are never touched.
$finish_guest_made          = (object) array(
	'products' => array(),
	'users'    => array(),
);
$finish_guest_remember_post = function ( $post_id, $post ) use ( $finish_guest_made ) {
	if ( 'sunshine-product' === $post->post_type ) {
		$finish_guest_made->products[] = (int) $post_id;
	}
};
$finish_guest_remember_user = function ( $user_id ) use ( $finish_guest_made ) {
	$finish_guest_made->users[] = (int) $user_id;
};
add_action( 'wp_insert_post', $finish_guest_remember_post, 10, 2 );
add_action( 'user_register', $finish_guest_remember_user );

$finish_guest_tidy = function ( $last = false ) use ( $finish_guest_made, $finish_guest_remember_post, $finish_guest_remember_user ) {
	global $wpdb;
	$table = $wpdb->prefix . 'sunshine_actions';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		$users = array_map( 'intval', array_unique( $finish_guest_made->users ) );
		if ( $users ) {
			$wpdb->query( "DELETE FROM {$table} WHERE customer_id IN (" . implode( ',', $users ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$products = array_map( 'intval', array_unique( $finish_guest_made->products ) );
		if ( $products ) {
			$wpdb->query( "DELETE FROM {$table} WHERE action_type = 'add_to_cart' AND action_object_id IN (" . implode( ',', $products ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
	if ( $last ) {
		remove_action( 'wp_insert_post', $finish_guest_remember_post, 10 );
		remove_action( 'user_register', $finish_guest_remember_user );
	}
};

// The session keys a page load fills in for a shopper. A gateway's message has none of them.
$finish_guest_session_keys = array( 'cart', 'discounts', 'checkout_data', 'checkout_sections_completed', 'gift_cards', 'gift_cards_applied', 'gift_card_amounts', 'use_credits', 'checkout_order_id', 'paypal_order_id' );

// The customer's browser is gone: nobody is logged in and the session is empty.
$finish_guest_nobody = function () use ( $finish_guest_session_keys ) {
	wp_set_current_user( 0 );
	SPC()->customer = new SPC_Customer( 0 );
	foreach ( $finish_guest_session_keys as $key ) {
		SPC()->session->set( $key, '' );
	}
	SPC()->cart = new SPC_Cart();
};

/**
 * Run part of a payment the way it really happens, with nothing leaving the site.
 *
 * $args:
 *   'guest'   true for a message from the gateway: nobody logged in, an empty session (default).
 *             false for a step in the customer's own browser.
 *   'get', 'post'  request values for $_GET and $_POST.
 *   'input'   the raw request body the handler reads from php://input.
 *   'reply'   function( $request ) returning array( status code, body ) for a caught web
 *             request. A body that is an array is sent as JSON. No reply means an error.
 *   'stop'    order meta keys: the run stops right after the handler saves one of them on the
 *             order (the last thing it does before exit).
 *   'filters' filters that stop the run when they are applied. Their value is kept in 'caught'.
 *   'logs'    bits of text: the run stops when the handler writes a Sunshine log line holding
 *             one of them. Handlers log a line right before most of their exits.
 *   'logs_ignore'  log lines holding one of these never stop the run (the "received" line a
 *             handler writes when it starts, for example).
 *
 * Returns 'sent' (each caught request: url, host, path, method, body), 'status' (HTTP status the
 * handler set), 'redirect', 'stopped' (the meta key, filter or log line it stopped on), 'caught',
 * 'output'.
 */
$finish_guest_run = function ( $label, $order_id, $handler, $args = array() ) use ( $finish_guest_state, $finish_guest_nobody ) {
	global $wpdb;
	$args   = array_merge(
		array(
			'guest'       => true,
			'get'         => array(),
			'post'        => array(),
			'input'       => null,
			'reply'       => null,
			'stop'        => array(),
			'filters'     => array(),
			'logs'        => array(),
			'logs_ignore' => array(),
		),
		$args
	);
	$result = array(
		'sent'     => array(),
		'status'   => null,
		'redirect' => null,
		'stopped'  => '',
		'caught'   => null,
		'output'   => '',
	);

	// php://input is swapped for the request body only until the handler has read it. Anything
	// the handler does next (saving meta, a web request, finishing the order) puts it back.
	$restore_input  = function () use ( $finish_guest_state ) {
		if ( $finish_guest_state->input ) {
			$finish_guest_state->input = false;
			stream_wrapper_restore( 'php' );
		}
	};
	$restore_status = function ( $status ) use ( $restore_input ) {
		$restore_input();
		return $status;
	};
	$restore_action = function () use ( $restore_input ) {
		$restore_input();
	};

	$http     = function ( $pre, $request_args, $url ) use ( &$result, $args, $restore_input ) {
		$restore_input();
		$request          = array(
			'url'    => $url,
			'host'   => (string) wp_parse_url( $url, PHP_URL_HOST ),
			'path'   => (string) wp_parse_url( $url, PHP_URL_PATH ),
			'method' => isset( $request_args['method'] ) ? strtoupper( $request_args['method'] ) : 'GET',
			'body'   => isset( $request_args['body'] ) ? $request_args['body'] : null,
		);
		$result['sent'][] = $request;
		$answer           = $args['reply'] ? call_user_func( $args['reply'], $request ) : null;
		if ( empty( $answer ) ) {
			return new WP_Error( 'spc_totals_offline', 'The totals tests never reach outside services' );
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
	$meta     = function ( $meta_id, $object_id, $meta_key ) use ( &$result, $order_id, $args, $restore_input ) {
		$restore_input();
		if ( (int) $object_id === (int) $order_id && in_array( $meta_key, $args['stop'], true ) ) {
			$result['stopped'] = $meta_key;
			throw new SPC_Totals_Test_Done();
		}
	};
	$status   = function ( $header, $code ) use ( &$result ) {
		$result['status'] = $code;
		throw new SPC_Totals_Test_Done();
	};
	$redirect = function ( $location ) use ( &$result ) {
		$result['redirect'] = $location;
		throw new SPC_Totals_Test_Done();
	};
	$die      = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	// SPC()->log() reads the enable_log option first, so this sees every log line as it is written.
	$logger   = function ( $pre ) use ( &$result, $args ) {
		foreach ( debug_backtrace( 0, 8 ) as $frame ) {
			if ( isset( $frame['function'], $frame['class'], $frame['args'][0] ) && 'log' === $frame['function'] && is_string( $frame['args'][0] ) ) {
				foreach ( $args['logs_ignore'] as $text ) {
					if ( false !== strpos( $frame['args'][0], $text ) ) {
						return $pre;
					}
				}
				foreach ( $args['logs'] as $text ) {
					if ( false !== strpos( $frame['args'][0], $text ) ) {
						$result['stopped'] = $frame['args'][0];
						throw new SPC_Totals_Test_Done();
					}
				}
				break;
			}
		}
		return $pre;
	};
	$stoppers = array();
	foreach ( $args['filters'] as $filter ) {
		$stoppers[ $filter ] = function ( $value ) use ( &$result, $filter ) {
			$result['stopped'] = $filter;
			$result['caught']  = $value;
			throw new SPC_Totals_Test_Done();
		};
	}

	$saved = array(
		'get'  => array(),
		'post' => array(),
	);
	foreach ( array( 'get', 'post' ) as $where ) {
		foreach ( $args[ $where ] as $key => $value ) {
			$global                  = ( 'get' === $where ) ? $_GET : $_POST;
			$saved[ $where ][ $key ] = array_key_exists( $key, $global ) ? array( $global[ $key ] ) : null;
			if ( 'get' === $where ) {
				$_GET[ $key ] = $value;
			} else {
				$_POST[ $key ] = $value;
			}
		}
	}

	if ( $args['guest'] ) {
		$finish_guest_nobody();
	}

	add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
	add_action( 'added_post_meta', $meta, PHP_INT_MAX, 3 );
	add_action( 'updated_post_meta', $meta, PHP_INT_MAX, 3 );
	add_filter( 'status_header', $status, PHP_INT_MAX, 2 );
	// Before WP-CLI's own redirect warning, which would print a long backtrace.
	add_filter( 'wp_redirect', $redirect, -PHP_INT_MAX );
	add_filter( 'wp_die_handler', $die, PHP_INT_MAX );
	add_filter( 'wp_die_ajax_handler', $die, PHP_INT_MAX );
	add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
	add_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
	add_filter( 'sunshine_create_order_status', $restore_status, -PHP_INT_MAX );
	add_action( 'wp_insert_comment', $restore_action, -PHP_INT_MAX );
	foreach ( $stoppers as $filter => $stopper ) {
		add_filter( $filter, $stopper, PHP_INT_MAX );
	}
	if ( $args['logs'] ) {
		add_filter( 'pre_option_sunshine_enable_log', $logger, PHP_INT_MAX );
	}

	if ( null !== $args['input'] ) {
		$input              = new class() {
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
		$input_class        = get_class( $input );
		$input_class::$body = (string) $args['input'];
		stream_wrapper_unregister( 'php' );
		stream_wrapper_register( 'php', $input_class );
		$finish_guest_state->input = true;
	}

	$filters_before             = $GLOBALS['wp_current_filter'];
	$finish_guest_state->inside = $label;
	ob_start();
	try {
		$handler();
	} catch ( SPC_Totals_Test_Done $e ) {
		// The handler finished by exiting, redirecting or dying, and was stopped just before.
	} finally {
		$result['output'] = ob_get_clean();
		$restore_input();
		$finish_guest_state->inside   = '';
		$GLOBALS['wp_current_filter'] = $filters_before;
		remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
		remove_action( 'added_post_meta', $meta, PHP_INT_MAX );
		remove_action( 'updated_post_meta', $meta, PHP_INT_MAX );
		remove_filter( 'status_header', $status, PHP_INT_MAX );
		remove_filter( 'wp_redirect', $redirect, -PHP_INT_MAX );
		remove_filter( 'wp_die_handler', $die, PHP_INT_MAX );
		remove_filter( 'wp_die_ajax_handler', $die, PHP_INT_MAX );
		remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
		remove_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
		remove_filter( 'sunshine_create_order_status', $restore_status, -PHP_INT_MAX );
		remove_action( 'wp_insert_comment', $restore_action, -PHP_INT_MAX );
		foreach ( $stoppers as $filter => $stopper ) {
			remove_filter( $filter, $stopper, PHP_INT_MAX );
		}
		remove_filter( 'pre_option_sunshine_enable_log', $logger, PHP_INT_MAX );
		foreach ( array( 'get', 'post' ) as $where ) {
			foreach ( $saved[ $where ] as $key => $old ) {
				if ( 'get' === $where ) {
					if ( null === $old ) {
						unset( $_GET[ $key ] );
					} else {
						$_GET[ $key ] = $old[0];
					}
				} elseif ( null === $old ) {
					unset( $_POST[ $key ] );
				} else {
					$_POST[ $key ] = $old[0];
				}
			}
		}
		// Finishing an order holds a database lock on it. A run stopped inside that step never
		// got to let go of it, so do it here.
		if ( $order_id ) {
			for ( $i = 0; $i < 5; $i++ ) {
				if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'sunshine_post_process_' . $order_id ) ) ) {
					break;
				}
			}
		}
	}

	// PHP warnings printed while the handler ran would be hidden in the captured output.
	if ( preg_match( '/(Warning|Notice|Deprecated|Fatal error)\b[^\n]{0,300}/', wp_strip_all_tags( $result['output'] ), $match ) ) {
		WP_CLI::log( 'PHP message during ' . $label . ': ' . trim( $match[0] ) );
	}
	return $result;
};

// A logged in customer with store credit, using it at checkout. The photographer types the credit
// on the customer's admin screen, which saves it with set_credits(). Whole dollars only here:
// cents on credits are a separate, already known problem.
$finish_guest_shopper = function ( $credits ) use ( $t ) {
	$customer_id = $t::customer();
	( new SPC_Customer( $customer_id ) )->set_credits( (string) $credits );
	SPC()->customer = new SPC_Customer( $customer_id );
	if ( $credits > 0 ) {
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
	}
	return $customer_id;
};

$finish_guest_credits_left = function ( $customer_id ) {
	return ( new SPC_Customer( $customer_id ) )->get_credits();
};

/**
 * A discount code the way the Discounts screen saves one: every field is saved, empty ones as '',
 * lists as arrays. By default 10% off the order, once per customer. Returns its ID and code.
 */
$finish_guest_code = function ( $meta = array() ) use ( $t ) {
	$t::require_plugin( 'sunshine_get_discount_by_code', 'Discount Codes add-on' );
	$code = 'SPCGUEST' . wp_rand( 100000, 999999 );
	$id   = wp_insert_post(
		array(
			'post_type'   => 'sunshine-discount',
			'post_status' => 'publish',
			'post_title'  => 'Guest finish test ' . $code,
		)
	);
	$t::track( 'post', $id );
	$meta = array_merge(
		array(
			'auto'                  => '',
			'code'                  => $code,
			'discount_type'         => 'percent-total',
			'discount_amount'       => '10',
			'apply_to_shipping'     => '',
			'free_shipping'         => '',
			'start_date'            => '',
			'end_date'              => '',
			'solo'                  => '',
			'required_products'     => array(),
			'allowed_products'      => array(),
			'disallowed_products'   => array(),
			'allowed_categories'    => '',
			'disallowed_categories' => '',
			'allowed_galleries'     => array(),
			'disallowed_galleries'  => array(),
			'min_amount'            => '',
			'min_quantity'          => '',
			'max_uses'              => '',
			'max_uses_per_person'   => '1',
			'max_product_quantity'  => '',
		),
		$meta
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return array( $id, $meta['code'] );
};

// What the Apply button at checkout does. True when the code was accepted.
$finish_guest_enter = function ( $code ) {
	$result = SPC()->cart->add_discount( $code );
	SPC()->cart->update();
	return ! empty( $result );
};

$finish_guest_uses = function ( $discount_id ) {
	return (int) get_post_meta( $discount_id, 'use_count', true );
};

// The customer comes back later, logs in, puts a product in a fresh cart and types the code.
// True when the code is accepted.
$finish_guest_code_again = function ( $customer_id, $code, $product_id ) use ( $t, $finish_guest_session_keys, $finish_guest_enter ) {
	wp_set_current_user( $customer_id );
	SPC()->customer = new SPC_Customer( $customer_id );
	foreach ( $finish_guest_session_keys as $key ) {
		SPC()->session->set( $key, '' );
	}
	SPC()->cart = new SPC_Cart();
	$t::add( $product_id );
	$t::calculate();
	return $finish_guest_enter( $code );
};

$finish_guest_card_left = function ( $card_id ) {
	return (float) sunshine_get_gift_card( $card_id )->get_balance();
};

// What the cart shows right before the customer pays.
$finish_guest_seen = function () {
	$cart = SPC()->cart;
	return array(
		'subtotal' => (float) $cart->get_subtotal(),
		'discount' => (float) $cart->get_discount(),
		'shipping' => (float) $cart->get_shipping(),
		'tax'      => (float) $cart->get_tax(),
		'credits'  => (float) $cart->get_credits_applied(),
		'gift'     => (float) array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) ),
		'total'    => (float) $cart->get_total(),
	);
};

/**
 * Everything a finished order must leave behind, however the payment was confirmed.
 * $seen is what the cart showed. $expect can hold:
 *   'customer' => customer ID, 'credits_left' => store credit left on the account,
 *   'card' => gift card ID, 'card_left' => balance left on it,
 *   'code' => array( discount ID, code, product ID ) for a once-per-customer code.
 */
$finish_guest_finished = function ( $order_id, $seen, $expect ) use ( $t, $finish_guest_credits_left, $finish_guest_card_left, $finish_guest_uses, $finish_guest_code_again ) {
	$order = sunshine_get_order( $order_id );
	$t::same( 'new', $order->get_status(), 'order is marked paid (New)' );
	$t::money( $seen['total'], $order->get_total(), 'order total is what the customer paid' );
	$t::money( $seen['credits'], $order->get_credits(), 'store credit on the order' );
	$t::money( $seen['discount'], $order->get_discount(), 'discount on the order' );
	$t::money( $seen['tax'], $order->get_tax(), 'tax on the order' );
	if ( isset( $expect['customer'] ) ) {
		$t::money( $expect['credits_left'], $finish_guest_credits_left( $expect['customer'] ), 'store credit left on the customer account' );
	}
	if ( isset( $expect['card'] ) ) {
		$t::money( $expect['card_left'], $finish_guest_card_left( $expect['card'] ), 'balance left on the gift card' );
	}
	if ( isset( $expect['code'] ) ) {
		list( $discount_id, $code, $product_id ) = $expect['code'];
		$t::same( 1, $finish_guest_uses( $discount_id ), 'code counted as used once' );
		$t::same( false, $finish_guest_code_again( $expect['customer'], $code, $product_id ), 'the same customer cannot use the once-per-customer code again' );
	}
};

// Place the order at checkout with the given payment method and check the order saved what the
// cart showed. Returns the order.
$finish_guest_place = function ( $method, $seen ) use ( $t ) {
	$order = $t::place_order( $method );
	$t::money( $seen['total'], $order->get_total(), 'order total when placed' );
	$t::same( 'pending', $order->get_status(), 'order waits for the payment (Pending)' );
	return $order;
};

/*
 * Stripe.
 */

$finish_guest_stripe_use = function ( $mode ) use ( $t ) {
	$t::option( 'currency', 'USD' );
	$t::option( 'stripe_mode', 'test' );
	$t::option( 'stripe_checkout_mode', $mode );
	$t::option( 'stripe_tax_mode', 'sunshine' );
	$t::option( 'stripe_fee', 'none' );
	$t::option( 'stripe_webhook_secret_test', 'whsec_spcguesttest' );
};

// Send a Stripe event to the site's webhook, signed with the webhook secret the way Stripe signs
// it (t=time, v1=HMAC-SHA256 of "time.body"). Returns what $finish_guest_run returns.
$finish_guest_stripe_event = function ( $order_id, $event ) use ( $finish_guest_run ) {
	$stripe                           = sunshine_get_payment_method_by_id( 'stripe' );
	$payload                          = wp_json_encode( $event );
	$time                             = time();
	$sign                             = 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $payload, SPC()->get_option( 'stripe_webhook_secret_test' ) );
	$_SERVER['HTTP_STRIPE_SIGNATURE'] = $sign;
	try {
		return $finish_guest_run(
			'Stripe webhook ' . $event['type'],
			$order_id,
			function () use ( $stripe ) {
				$stripe->process_webhook();
			},
			array(
				'get'   => array( 'sunshine_stripe_webhook' => 1 ),
				'input' => $payload,
			)
		);
	} finally {
		unset( $_SERVER['HTTP_STRIPE_SIGNATURE'] );
	}
};

/*
 * Mollie, with a stand-in for its connection so nothing is sent. Returns array( payment method,
 * connection ). The connection records each call in ->sent and answers with $reply( method, url,
 * body ), which returns the reply as an array.
 */
$finish_guest_mollie = function ( $reply ) use ( $t ) {
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
			$answer       = call_user_func( $this->reply, $http_method, $url, $http_body );
			return null === $answer ? null : json_decode( wp_json_encode( $answer ) );
		}
		public function versionString() {
			return 'SunshineTotalsTest/1.0';
		}
	};

	$client = new \Mollie\Api\MollieApiClient( $connection );
	$client->setApiKey( 'test_' . str_repeat( 'spctotals', 4 ) );

	// The real Mollie payment method, except setup() hands it the client above instead of one
	// that talks to Mollie. Its constructor is skipped so no hooks are added twice.
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

// A gift card product the way the Gift Cards add-on makes one and the product screen saves it:
// no price (the price box is hidden), preset amounts, custom amounts allowed up to 1000.
$finish_guest_gift_card_product = function () use ( $t ) {
	$t::require_plugin( 'sunshine_gift_card_add_to_cart_item', 'Gift Cards add-on' );
	$id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-product',
			'post_status' => 'publish',
			'post_title'  => 'Totals test gift card',
		)
	);
	$t::track( 'post', $id );
	update_post_meta( $id, 'type', 'gift-card' );
	update_post_meta( $id, 'price', array( sunshine_get_default_price_level_id() => '' ) );
	update_post_meta( $id, 'taxable', '' );
	update_post_meta( $id, 'gift_card_preset_amounts', '25,50,100,200,500' );
	update_post_meta( $id, 'gift_card_allow_custom', '1' );
	update_post_meta( $id, 'gift_card_max_amount', '1000' );
	return $id;
};

// Add a gift card to the cart the way the Gift Cards page does: the amount and the recipient
// come in the form post.
$finish_guest_add_gift_card = function ( $product_id, $amount ) {
	$_POST['gift_card_amount']          = (string) $amount;
	$_POST['gift_card_delivery']        = 'immediate';
	$_POST['gift_card_recipient_name']  = 'Totals Gift';
	$_POST['gift_card_recipient_email'] = 'totals-gift@example.com';
	try {
		$item = SPC()->cart->add_item( $product_id, 0, '', sunshine_get_default_price_level_id(), '', 1 );
	} finally {
		unset( $_POST['gift_card_amount'], $_POST['gift_card_delivery'], $_POST['gift_card_recipient_name'], $_POST['gift_card_recipient_email'] );
	}
	if ( empty( $item ) ) {
		throw new Exception( 'Could not add the gift card to the cart' );
	}
	return $item;
};

// Gift cards an order made, as array( card ID => balance ). Each one is deleted after the run.
$finish_guest_cards_bought = function ( $order_id ) use ( $t ) {
	$cards    = get_posts(
		array(
			'post_type'      => 'sunshine-gift-card',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => 'purchase_order_id',
			'meta_value'     => $order_id,
		)
	);
	$balances = array();
	foreach ( $cards as $card_id ) {
		$t::track( 'post', $card_id );
		$balances[ $card_id ] = (float) get_post_meta( $card_id, 'balance', true );
	}
	return $balances;
};

// Prints a paid session left on the customer, in the shape sunshine_session_fees_status_change()
// saves them: the product ID and quantity from the session's tab, plus the order they came from.
$finish_guest_give_included = function ( $customer_id, $product_id, $qty, $session_order_id ) use ( $t ) {
	$t::require_plugin( 'sunshine_session_fees_cart_item_discount', 'Session Fees add-on' );
	update_user_meta(
		$customer_id,
		'sunshine_session_products',
		array(
			array(
				'product'  => (string) $product_id,
				'qty'      => (string) $qty,
				'order_id' => $session_order_id,
			),
		)
	);
	SPC()->customer = new SPC_Customer( $customer_id );
};

// How many of a product the customer can still get free.
$finish_guest_included_left = function ( $customer_id, $product_id ) {
	$left = 0;
	foreach ( (array) get_user_meta( $customer_id, 'sunshine_session_products', true ) as $row ) {
		if ( is_array( $row ) && isset( $row['product'], $row['qty'] ) && $row['product'] == $product_id && $row['qty'] > 0 ) {
			$left += $row['qty'];
		}
	}
	return $left;
};

// A payment method from an add-on, switched on for this test only, with no fee.
$finish_guest_switch_on = function ( $id ) use ( $t ) {
	$t::option( $id . '_active', 1 );
	$t::option( $id . '_mode', 'test' );
	$t::option( $id . '_fee', 'none' );
};
