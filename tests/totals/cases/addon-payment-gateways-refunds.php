<?php
/**
 * Refunding a PayFast payment from the order screen ("Refund payment in Payfast").
 *
 * PayFast's Refunds API (https://developers.payfast.co.za/api#refunds) is asked first how the
 * payment can be refunded (GET /refunds/query/{pf_payment_id}), then the refund is created
 * (POST /refunds/{pf_payment_id}) with the amount in cents. A refund is only recorded on the
 * order when PayFast says it worked, and never for more than is left on the order, so the
 * store's books match what the customer really got back. Every request is signed the way
 * PayFast's API guide says, and test mode adds testing=true so the sandbox is used.
 *
 * Nothing reaches PayFast: every request is caught and answered here.
 * Helpers are in addon-payment-gateways-helpers.php.
 */

$t = 'SPC_Totals_Test';

// PayFast in test mode, with a passphrase (PayFast's API needs one).
$payfast_refunds_use = function ( $mode = 'test' ) use ( $t, $addon_gateways_use ) {
	$t::require_plugin( 'sunshine_payment_method_payfast', 'PayFast add-on' );
	$t::option( 'currency', 'ZAR' );
	foreach ( array( 'test', 'live' ) as $each ) {
		$t::option( 'payfast_merchant_id_' . $each, '10000100' );
		$t::option( 'payfast_merchant_key_' . $each, '46f0cd694581a' );
		$t::option( 'payfast_passphrase_' . $each, 'sunshine totals' );
	}
	$addon_gateways_use( 'payfast' );
	$t::option( 'payfast_mode', $mode );
};

// A R100.00 order paid through PayFast, with PayFast's payment ID saved the way the ITN saves it.
$payfast_refunds_order = function ( $payment_id = '2290001' ) use ( $t, $addon_gateways_place ) {
	$t::add( $t::product( 100 ) );
	$order = $addon_gateways_place( 'payfast', 100 );
	$order->set_status( 'new' );
	if ( $payment_id ) {
		$order->update_meta_value( 'payfast_payment_id', $payment_id );
	}
	return sunshine_get_order( $order->get_id() );
};

// PayFast's answer to the refund query (the shape its API guide shows), for a card payment
// that can go back to the card. Amounts are in cents.
$payfast_refunds_query = function ( $available, $changes = array() ) {
	return array_merge(
		array(
			'token'                       => 'a3b3ae55-ab8b-b388-df23-4e6882b86ce0',
			'funding_type'                => 'Credit Card',
			'amount_original'             => 10000,
			'amount_available_for_refund' => $available,
			'status'                      => 'REFUNDABLE',
			'errors'                      => array(),
			'refund_full'                 => array( 'method' => 'PAYMENT_SOURCE' ),
			'refund_partial'              => array( 'method' => 'PAYMENT_SOURCE' ),
		),
		$changes
	);
};

// PayFast's answer when a refund is created, from its API guide.
$payfast_refunds_ok = array(
	'code'   => 200,
	'status' => 'success',
	'data'   => array(
		'response' => true,
		'message'  => 'Success',
	),
);

/**
 * Press "Refund payment in Payfast" on the order screen with $post filled in. $query and
 * $create are the replies to the refund query and to creating the refund. Returns what was
 * caught, plus every request's headers, the requests to the refunds API and the error notice.
 */
$payfast_refunds_run = function ( $order, $post, $query, $create ) use ( $addon_gateways_order_action ) {
	$headers = array();
	$grab    = function ( $pre, $args, $url ) use ( &$headers ) {
		$headers[] = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		return $pre;
	};
	SPC()->notices->clear_admin();
	add_filter( 'pre_http_request', $grab, 10, 3 );
	try {
		$run = $addon_gateways_order_action(
			function () use ( $order ) {
				sunshine_get_payment_method_by_id( 'payfast' )->process_refund( $order->get_id() );
			},
			$post,
			function ( $request ) use ( $query, $create ) {
				if ( 'api.payfast.co.za' !== $request['host'] ) {
					return null;
				}
				if ( 'GET' === $request['method'] && preg_match( '#^/refunds/query/[^/]+$#', $request['path'] ) ) {
					return $query;
				}
				if ( 'POST' === $request['method'] && preg_match( '#^/refunds/[^/]+$#', $request['path'] ) ) {
					return $create;
				}
				return null;
			}
		);
	} finally {
		remove_filter( 'pre_http_request', $grab, 10 );
	}
	$run['headers'] = $headers;
	$run['create']  = null;
	$run['query']   = null;
	foreach ( $run['sent'] as $i => $request ) {
		if ( 'GET' === $request['method'] && 0 === strpos( $request['path'], '/refunds/query/' ) ) {
			$run['query'] = $request + array( 'headers' => $headers[ $i ] );
		} elseif ( 'POST' === $request['method'] && 0 === strpos( $request['path'], '/refunds/' ) ) {
			$run['create'] = $request + array( 'headers' => $headers[ $i ] );
		}
	}
	$notices      = (array) SPC()->notices->get_admin_notices();
	$key          = 'payfast_refund_fail_' . $order->get_id();
	$run['error'] = isset( $notices[ $key ]['text'] ) ? $notices[ $key ]['text'] : '';
	SPC()->notices->clear_admin();
	return $run;
};

// The order fresh from the database.
$payfast_refunds_reload = function ( $order ) {
	clean_post_cache( $order->get_id() );
	wp_cache_delete( $order->get_id(), 'post_meta' );
	return sunshine_get_order( $order->get_id() );
};

// The order's log lines that contain $text.
$payfast_refunds_log = function ( $order, $text ) {
	$found = 0;
	foreach ( get_comments(
		array(
			'post_id' => $order->get_id(),
			'type'    => 'sunshine_order_log',
		)
	) as $comment ) {
		if ( false !== strpos( $comment->comment_content, $text ) ) {
			$found++;
		}
	}
	return $found;
};

// A full refund sends PayFast the whole R100.00 as 10000 cents, back to the card, and only
// then records it and marks the order Refunded.
$t::test(
	'PayFast refund: full refund sends the whole total in cents and marks the order Refunded',
	function () use ( $t, $payfast_refunds_use, $payfast_refunds_order, $payfast_refunds_run, $payfast_refunds_query, $payfast_refunds_ok, $payfast_refunds_reload, $addon_gateways_wire ) {
		$payfast_refunds_use();
		$order = $payfast_refunds_order();
		$run   = $payfast_refunds_run(
			$order,
			array(
				'payfast_refund_full'   => 'yes',
				'payfast_refund_notify' => 'yes',
				'payfast_refund_amount' => '100',
			),
			array( 200, $payfast_refunds_query( 10000 ) ),
			array( 200, $payfast_refunds_ok )
		);
		$t::same( '/refunds/query/2290001', $run['query'] ? $run['query']['path'] : null, 'asks PayFast how the payment can be refunded first' );
		$t::same( '/refunds/2290001', $run['create'] ? $run['create']['path'] : null, 'creates the refund for the right payment' );
		$body = $addon_gateways_wire( $run['create'] );
		$t::same( '10000', isset( $body['amount'] ) ? $body['amount'] : null, 'amount in cents' );
		$t::same( '1', isset( $body['notify_buyer'] ) ? $body['notify_buyer'] : null, 'PayFast emails the customer' );
		$t::same( true, isset( $body['reason'] ) && strlen( $body['reason'] ) >= 3, 'a reason of at least 3 characters is sent' );
		$order = $payfast_refunds_reload( $order );
		$t::money( 100, $order->get_refund_total(), 'refund on the order' );
		$t::same( 'refunded', $order->get_status(), 'order status' );
		$t::same( '', $run['error'], 'no error' );
	}
);

// A part refund keeps the order's status. A second one can't go over what is left: asking for
// R90.00 when R80.01 is left refunds R80.01, and that one makes the order Refunded.
$t::test(
	'PayFast refund: part refunds keep the status and never go over what is left',
	function () use ( $t, $payfast_refunds_use, $payfast_refunds_order, $payfast_refunds_run, $payfast_refunds_query, $payfast_refunds_ok, $payfast_refunds_reload, $addon_gateways_wire ) {
		$payfast_refunds_use();
		$order = $payfast_refunds_order();
		$run   = $payfast_refunds_run(
			$order,
			array(
				'payfast_refund_amount' => '19.99',
				'payfast_refund_reason' => 'Damaged print',
			),
			array( 200, $payfast_refunds_query( 10000 ) ),
			array( 200, $payfast_refunds_ok )
		);
		$body = $addon_gateways_wire( $run['create'] );
		$t::same( '1999', isset( $body['amount'] ) ? $body['amount'] : null, 'first part refund in cents' );
		$t::same( 'Damaged print', isset( $body['reason'] ) ? $body['reason'] : null, 'the reason typed is sent' );
		$t::same( '0', isset( $body['notify_buyer'] ) ? $body['notify_buyer'] : null, 'customer not emailed when the box is unticked' );
		$order = $payfast_refunds_reload( $order );
		$t::money( 19.99, $order->get_refund_total(), 'first refund on the order' );
		$t::same( 'new', $order->get_status(), 'a part refund keeps the status' );

		$run  = $payfast_refunds_run(
			$order,
			array( 'payfast_refund_amount' => '90' ),
			array( 200, $payfast_refunds_query( 8001 ) ),
			array( 200, $payfast_refunds_ok )
		);
		$body = $addon_gateways_wire( $run['create'] );
		$t::same( '8001', isset( $body['amount'] ) ? $body['amount'] : null, 'second refund is only what is left' );
		$order = $payfast_refunds_reload( $order );
		$t::money( 100, $order->get_refund_total(), 'both refunds on the order' );
		$t::same( 'refunded', $order->get_status(), 'nothing left, so Refunded' );
	}
);

// When PayFast turns the refund down, nothing is recorded and PayFast's reason is shown.
$t::test(
	'PayFast refund: an error from PayFast records no refund and shows its reason',
	function () use ( $t, $payfast_refunds_use, $payfast_refunds_order, $payfast_refunds_run, $payfast_refunds_query, $payfast_refunds_reload, $payfast_refunds_log ) {
		$payfast_refunds_use();
		$order = $payfast_refunds_order();
		$run   = $payfast_refunds_run(
			$order,
			array( 'payfast_refund_full' => 'yes' ),
			array( 200, $payfast_refunds_query( 10000 ) ),
			array(
				400,
				array(
					'code'   => 400,
					'status' => 'failed',
					'data'   => array(
						'response' => false,
						'message'  => 'Insufficient balance for refund',
					),
				),
			)
		);
		$t::same( true, null !== $run['create'], 'the refund was asked for' );
		$order = $payfast_refunds_reload( $order );
		$t::money( 0, $order->get_refund_total(), 'no refund recorded' );
		$t::same( 'new', $order->get_status(), 'status unchanged' );
		$t::same( true, false !== strpos( $run['error'], 'Insufficient balance for refund' ), 'admin notice shows PayFast\'s reason' );
		$t::same( 1, $payfast_refunds_log( $order, 'Insufficient balance for refund' ), 'order note shows PayFast\'s reason' );

		// The shape PayFast's sandbox really sends: a summary plus field => problem details.
		$run = $payfast_refunds_run(
			$order,
			array( 'payfast_refund_full' => 'yes' ),
			array( 200, $payfast_refunds_query( 10000 ) ),
			array(
				400,
				array(
					'code'   => 400,
					'status' => 'error',
					'data'   => array(
						'response' => array( 'amount' => 'Minimum allowed value is 1' ),
						'message'  => 'Failed',
					),
				),
			)
		);
		$t::same( true, false !== strpos( $run['error'], 'amount: Minimum allowed value is 1' ), 'field problem from PayFast shown' );
		$t::money( 0, $payfast_refunds_reload( $order )->get_refund_total(), 'still no refund recorded' );
	}
);

// When the query says the payment can't be refunded here, the refund is never created.
$t::test(
	'PayFast refund: a payment PayFast can\'t refund, or only to a bank account, sends no refund',
	function () use ( $t, $payfast_refunds_use, $payfast_refunds_order, $payfast_refunds_run, $payfast_refunds_query, $payfast_refunds_ok, $payfast_refunds_reload ) {
		$payfast_refunds_use();
		$order = $payfast_refunds_order();
		$run   = $payfast_refunds_run(
			$order,
			array( 'payfast_refund_full' => 'yes' ),
			array(
				200,
				$payfast_refunds_query(
					0,
					array(
						'status' => 'NOT_AVAILABLE',
						'errors' => array( 'Refunds are not available for this payment' ),
					)
				),
			),
			array( 200, $payfast_refunds_ok )
		);
		$t::same( null, $run['create'], 'no refund created' );
		$t::same( true, false !== strpos( $run['error'], 'Refunds are not available for this payment' ), 'PayFast\'s reason shown' );

		$run = $payfast_refunds_run(
			$order,
			array( 'payfast_refund_full' => 'yes' ),
			array( 200, $payfast_refunds_query( 10000, array( 'refund_full' => array( 'method' => 'BANK_PAYOUT' ) ) ) ),
			array( 200, $payfast_refunds_ok )
		);
		$t::same( null, $run['create'], 'no refund created for a bank payout' );
		$t::same( true, '' !== $run['error'], 'bank payout explained' );

		$run = $payfast_refunds_run(
			$order,
			array( 'payfast_refund_full' => 'yes' ),
			array( 200, $payfast_refunds_query( 5000 ) ),
			array( 200, $payfast_refunds_ok )
		);
		$t::same( null, $run['create'], 'no refund for more than PayFast still holds' );
		$t::money( 0, $payfast_refunds_reload( $order )->get_refund_total(), 'nothing recorded' );
	}
);

// Without PayFast's payment ID there is nothing to refund: no menu item and no request.
$t::test(
	'PayFast refund: an order with no PayFast payment ID sends nothing',
	function () use ( $t, $payfast_refunds_use, $payfast_refunds_order, $payfast_refunds_run, $payfast_refunds_query, $payfast_refunds_ok ) {
		$payfast_refunds_use();
		$order   = $payfast_refunds_order( '' );
		$payfast = sunshine_get_payment_method_by_id( 'payfast' );
		$t::same( false, array_key_exists( 'payfast_refund', $payfast->order_actions( array(), $order->get_id() ) ), 'no refund action offered' );
		$run = $payfast_refunds_run(
			$order,
			array( 'payfast_refund_full' => 'yes' ),
			array( 200, $payfast_refunds_query( 10000 ) ),
			array( 200, $payfast_refunds_ok )
		);
		$t::same( 0, count( $run['sent'] ), 'no request sent' );
		$t::same( true, '' !== $run['error'], 'admin told why' );

		$order->update_meta_value( 'payfast_payment_id', '2290001' );
		$t::same( true, array_key_exists( 'payfast_refund', $payfast->order_actions( array(), $order->get_id() ) ), 'refund action offered once the payment ID is there' );
	}
);

// Each request carries merchant-id, version v1, an ISO-8601 timestamp and a signature: the md5
// of the headers, body values and passphrase sorted by name, urlencoded and joined with &.
// testing=true is on the address in test mode only, and never part of the signature.
$t::test(
	'PayFast refund: requests are signed as PayFast says, testing=true only in test mode',
	function () use ( $t, $payfast_refunds_use, $payfast_refunds_order, $payfast_refunds_run, $payfast_refunds_query, $payfast_refunds_ok, $addon_gateways_wire ) {
		$sign = function ( $headers, $body ) {
			unset( $headers['signature'] );
			$values               = array_merge( $headers, $body );
			$values['passphrase'] = 'sunshine totals';
			ksort( $values );
			$pairs = array();
			foreach ( $values as $key => $value ) {
				if ( '' !== (string) $value ) {
					$pairs[] = $key . '=' . urlencode( trim( (string) $value ) );
				}
			}
			return md5( implode( '&', $pairs ) );
		};

		foreach ( array( 'test', 'live' ) as $mode ) {
			$t::reset();
			$payfast_refunds_use( $mode );
			$order = $payfast_refunds_order();
			$run   = $payfast_refunds_run(
				$order,
				array(
					'payfast_refund_full'   => 'yes',
					'payfast_refund_notify' => 'yes',
				),
				array( 200, $payfast_refunds_query( 10000 ) ),
				array( 200, $payfast_refunds_ok )
			);
			foreach ( array( 'query', 'create' ) as $which ) {
				$request = $run[ $which ];
				if ( ! $request ) {
					$t::same( 'sent', 'not sent', $mode . ' ' . $which );
					continue;
				}
				$headers = $request['headers'];
				$t::same( '10000100', isset( $headers['merchant-id'] ) ? $headers['merchant-id'] : null, $mode . ' ' . $which . ' merchant-id' );
				$t::same( 'v1', isset( $headers['version'] ) ? $headers['version'] : null, $mode . ' ' . $which . ' version' );
				$t::same( 1, preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', isset( $headers['timestamp'] ) ? $headers['timestamp'] : '' ), $mode . ' ' . $which . ' timestamp' );
				$body = 'create' === $which ? $addon_gateways_wire( $request ) : array();
				$t::same( $sign( $headers, $body ), isset( $headers['signature'] ) ? $headers['signature'] : null, $mode . ' ' . $which . ' signature' );
				parse_str( (string) wp_parse_url( $request['url'], PHP_URL_QUERY ), $query );
				$t::same( 'test' === $mode ? 'true' : null, isset( $query['testing'] ) ? $query['testing'] : null, $mode . ' ' . $which . ' testing flag' );
			}
		}
	}
);
