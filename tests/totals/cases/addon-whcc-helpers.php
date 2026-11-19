<?php
/**
 * Shared setup for the WHCC add-on tests (addon-whcc.php and addon-whcc-orders.php), also used
 * by the WHCC prints in combo-product-types.php. This file has no tests of its own.
 *
 * How a WHCC item gets into the cart in real life: the customer picks a WHCC product on a photo.
 * The add-on builds the item at WHCC (a "configured product") and sends the customer to WHCC's
 * editor. WHCC's finish button comes back with ?whcc_item=<configured product id>, and the add-on
 * asks WHCC what the item costs now ("calculate") and adds it at WHCC's price plus the markup.
 *
 * Nothing here talks to WHCC. Every outgoing request is answered by a made-up WHCC ("the lab")
 * or blocked. The lab answers in the same shape as WHCC's API, for these calls only:
 *   GET  catalog/products, catalog/products/{ref}      the products a store can sell
 *   POST commerce/configured-products                  build an item from a photo
 *   GET  commerce/configured-products?ids=              is the item built yet
 *   POST commerce/configured-products/{id}/edit-link   a link into WHCC's editor
 *   POST commerce/submissions/calculate                prices, and with an address, shipping
 *   POST commerce/submissions                          send a paid order (needs Idempotency-Key)
 *   GET  commerce/submissions/{id}                     what WHCC made of a sent order
 */

if ( isset( $whcc_test ) ) {
	return; // Already loaded by another file.
}

$t = 'SPC_Totals_Test';

// The made-up key the tests save. The made-up WHCC refuses any request without it, as WHCC does.
$whcc_key = 'spc-totals-offline-key';

// Every WHCC product and item made here is new, so nothing from an earlier run can match it.
$whcc_run     = 'spcwhcc' . wp_rand( 100000, 999999 );
$whcc_counter = 0;

// WHCC's own names for its shipping methods, and what the lab charges for one package with
// each. The keys are what the store's WHCC methods are called after "whcc_".
$whcc_methods = array(
	'lowest_cost'      => array( 'Economy', 8.00 ),
	'expedited'        => array( 'Expedited', 12.00 ),
	'one_day_standard' => array( 'Standard One-Day', 20.00 ),
	'one_day_priority' => array( 'Priority One-Day', 30.00 ),
);

// The made-up lab. Reset before every test.
$whcc_lab       = array();
$whcc_lab_reset = function () use ( &$whcc_lab, $whcc_methods ) {
	$per_package = array();
	foreach ( $whcc_methods as $key => $method ) {
		$per_package[ $key ] = $method[1];
	}
	$whcc_lab = array(
		// Catalog products by ref.
		'catalog'     => array(),
		// Configured products by id: what was built and what WHCC charges for it.
		'items'       => array(),
		// What a new build ends up as: ready, or failed (WHCC couldn't fetch the photo).
		'build'       => 'ready',
		// Shipping methods WHCC offers for the address, and the price of one package with each.
		'shipping'    => $per_package,
		// Set to a status code to make calculate fail (500 = WHCC is down, 400 = a bad request).
		'calculate'   => 0,
		// How the next send of an order goes: ok, lost (WHCC placed it but the answer never came
		// back), timeout (it never got to WHCC), 500, or refused (WHCC says it can't be placed).
		'submit'      => 'ok',
		// Sent orders by submission id, and the Idempotency-Key each came with.
		'submissions' => array(),
		'keys'        => array(),
		'requests'    => array(),
		'blocked'     => array(),
	);
};
$whcc_lab_reset();

$whcc_money = function ( $value ) {
	return array(
		'value' => number_format( (float) $value, 2, '.', '' ),
		'code'  => 'USD',
	);
};

// WHCC's price for one of an item at a quantity. 'prices' is lowest quantity => price for one,
// so array( 1 => 10, 5 => 8 ) is 10.00 each, or 8.00 each for 5 or more.
$whcc_unit_price = function ( $item, $qty ) {
	$unit = 0;
	foreach ( $item['prices'] as $from => $price ) {
		if ( $qty >= $from ) {
			$unit = $price;
		}
	}
	return (float) $unit;
};

// The packages WHCC ships an order in: one per product group (prints, wall art, ...).
$whcc_packages = function ( $ids ) use ( &$whcc_lab ) {
	$groups = array();
	foreach ( $ids as $id ) {
		$groups[ $whcc_lab['items'][ $id ]['group'] ] = true;
	}
	return array_keys( $groups );
};

// What calculate (and a submission) says about a list of items. Returns array( items, product
// subtotal, packages ), or a 400 reply when an item isn't one WHCC knows.
$whcc_price_basket = function ( $rows ) use ( &$whcc_lab, $whcc_money, $whcc_unit_price, $whcc_packages ) {
	$items   = array();
	$product = 0;
	$ids     = array();
	foreach ( array_values( (array) $rows ) as $n => $row ) {
		$id = isset( $row['configuredProductId'] ) ? $row['configuredProductId'] : '';
		if ( empty( $whcc_lab['items'][ $id ] ) ) {
			return array(
				400,
				array(
					'title'         => 'Bad Request',
					'status'        => 400,
					'detail'        => 'Some items are not valid.',
					'invalidParams' => array(
						array(
							'name'   => 'items.' . $n . '.configuredProductId',
							'reason' => 'Not found.',
						),
					),
				),
			);
		}
		$item  = $whcc_lab['items'][ $id ];
		$qty   = isset( $row['quantity'] ) ? intval( $row['quantity'] ) : $item['qty'];
		$unit  = $whcc_unit_price( $item, $qty );
		$reply = array(
			'configuredProductId' => $id,
			'primaryDisplayName'  => $item['name'],
			'quantity'            => $qty,
			'unitPrice'           => $whcc_money( $unit ),
			'totalPrice'          => $whcc_money( $unit * $qty ),
			'productId'           => $item['ref'],
			'isValidForOrder'     => $item['valid'],
		);
		if ( ! $item['valid'] ) {
			$reply['validationSummary'] = array();
			foreach ( $item['problems'] as $problem ) {
				$reply['validationSummary'][] = array(
					'code'    => '1001',
					'level'   => 'error',
					'message' => $problem,
				);
			}
		}
		$items[]  = $reply;
		$product += round( $unit * $qty, 2 );
		$ids[]    = $id;
	}
	return array( $items, $product, $whcc_packages( $ids ) );
};

// The lab's answer to one request: array( status code, body ), a WP_Error when the answer is
// lost, or null for "not a call WHCC knows".
$whcc_reply = function ( $method, $path, $query, $body, $headers ) use ( &$whcc_lab, $whcc_methods, $whcc_money, $whcc_price_basket, $whcc_key ) {

	// WHCC refuses a request without the key, and the add-on is built against API version
	// 2026-10. A change to either has to be a deliberate change to this check too.
	$auth    = isset( $headers['Authorization'] ) ? $headers['Authorization'] : '';
	$version = isset( $headers['WHCC-API-Version'] ) ? $headers['WHCC-API-Version'] : '';
	if ( 'Bearer ' . $whcc_key !== $auth || '2026-10' !== $version ) {
		return array(
			401,
			array(
				'title'  => 'Unauthorized',
				'status' => 401,
			),
		);
	}

	// 'unit', 'size_units' and 'group' are the lab's own notes on a catalog product, not WHCC fields.
	$catalog_entry = function ( $entry ) {
		return array_diff_key( $entry, array_flip( array( 'unit', 'size_units', 'size_invalid', 'group' ) ) );
	};

	if ( 'GET' === $method && 'catalog/products' === $path ) {
		return array( 200, array( 'products' => array_values( array_map( $catalog_entry, $whcc_lab['catalog'] ) ) ) );
	}
	if ( 'GET' === $method && preg_match( '#^catalog/products/([^/]+)$#', $path, $match ) ) {
		$ref = rawurldecode( $match[1] );
		if ( empty( $whcc_lab['catalog'][ $ref ] ) ) {
			return array(
				404,
				array(
					'title'  => 'Not Found',
					'status' => 404,
				),
			);
		}
		return array( 200, $catalog_entry( $whcc_lab['catalog'][ $ref ] ) );
	}

	if ( 'POST' === $method && 'commerce/configured-products' === $path ) {
		$built = array();
		foreach ( (array) ( isset( $body['configuredProducts'] ) ? $body['configuredProducts'] : array() ) as $request ) {
			$ref = isset( $request['productId'] ) ? $request['productId'] : '';
			if ( empty( $whcc_lab['catalog'][ $ref ] ) ) {
				return array(
					400,
					array(
						'title'  => 'Bad Request',
						'status' => 400,
						'detail' => 'Unknown product.',
					),
				);
			}
			$id                           = 'CF_' . wp_generate_password( 16, false );
			$whcc_lab['items'][ $id ] = array(
				'ref'      => $ref,
				'name'     => $whcc_lab['catalog'][ $ref ]['name'],
				'group'    => $whcc_lab['catalog'][ $ref ]['group'],
				// 'size_units' (size => price for one) is the lab's note when sizes cost different amounts.
				'prices'   => array( 1 => isset( $request['selections']['size'], $whcc_lab['catalog'][ $ref ]['size_units'][ $request['selections']['size'] ] ) ? $whcc_lab['catalog'][ $ref ]['size_units'][ $request['selections']['size'] ] : $whcc_lab['catalog'][ $ref ]['unit'] ),
				'qty'      => 1,
				// 'size_invalid' (sizes) is the lab's note for sizes WHCC won't make with the starting choices.
				'valid'    => 'ready' === $whcc_lab['build'] && ! ( isset( $request['selections']['size'], $whcc_lab['catalog'][ $ref ]['size_invalid'] ) && in_array( $request['selections']['size'], $whcc_lab['catalog'][ $ref ]['size_invalid'], true ) ),
				'problems' => 'ready' === $whcc_lab['build'] ? array() : array( 'The photo could not be fetched.' ),
				'status'   => $whcc_lab['build'],
			);
			$built[] = array(
				'configuredProductId' => $id,
				'status'              => 'processing',
			);
		}
		return array( 201, array( 'items' => $built ) );
	}
	if ( 'GET' === $method && 'commerce/configured-products' === $path ) {
		parse_str( $query, $args );
		$items = array();
		foreach ( explode( ',', isset( $args['ids'] ) ? $args['ids'] : '' ) as $id ) {
			if ( isset( $whcc_lab['items'][ $id ] ) ) {
				$items[] = array(
					'configuredProductId' => $id,
					'status'              => $whcc_lab['items'][ $id ]['status'],
				);
			}
		}
		return array( 200, array( 'items' => $items ) );
	}
	if ( 'POST' === $method && preg_match( '#^commerce/configured-products/([^/]+)/edit-link$#', $path, $match ) ) {
		$id = rawurldecode( $match[1] );
		if ( empty( $whcc_lab['items'][ $id ] ) ) {
			return array(
				404,
				array(
					'title'  => 'Not Found',
					'status' => 404,
				),
			);
		}
		return array(
			200,
			array(
				'actions' => array(
					'edit' => array(
						'url'    => 'https://editor.whcc.example/edit/' . rawurlencode( $id ) . '?session=spc',
						'method' => 'GET',
					),
				),
			),
		);
	}

	if ( 'POST' === $method && 'commerce/submissions/calculate' === $path ) {
		if ( $whcc_lab['calculate'] ) {
			return array(
				$whcc_lab['calculate'],
				array(
					'title'  => 'Error',
					'status' => $whcc_lab['calculate'],
					'detail' => 'The made-up WHCC is not answering prices.',
				),
			);
		}
		$basket = $whcc_price_basket( isset( $body['items'] ) ? $body['items'] : array() );
		if ( is_int( $basket[0] ) ) {
			return $basket;
		}
		list( $items, $product, $packages ) = $basket;
		$reply = array(
			'items'      => $items,
			'totalPrice' => array_merge(
				$whcc_money( $product ),
				array( 'subtotals' => array( 'product' => $whcc_money( $product ) ) )
			),
			'validation' => array( 'isValidForSubmit' => false ),
		);
		// Without an address WHCC leaves shipping out entirely, rather than showing zeros.
		if ( ! empty( $body['shipping']['address'] ) && $whcc_lab['shipping'] ) {
			$reply['options'] = array( 'shipping' => array() );
			foreach ( $whcc_lab['shipping'] as $key => $per_package ) {
				$reply['options']['shipping'][] = array(
					'id'       => 'dropship_' . $key,
					'type'     => 'dropship',
					'priority' => 'dropship_' . $key,
					'label'    => $whcc_methods[ $key ][0],
					'price'    => $whcc_money( $per_package * count( $packages ) ),
				);
			}
			$first     = key( $whcc_lab['shipping'] );
			$breakdown = array();
			foreach ( $packages as $package ) {
				$breakdown[] = array_merge( array( 'label' => $package ), $whcc_money( $whcc_lab['shipping'][ $first ] ) );
			}
			$reply['totalPrice']['subtotals']['shipping'] = array_merge(
				array( 'label' => $whcc_methods[ $first ][0] ),
				$whcc_money( $whcc_lab['shipping'][ $first ] * count( $packages ) ),
				array( 'items' => $breakdown )
			);
		}
		return array( 200, $reply );
	}

	if ( 'POST' === $method && 'commerce/submissions' === $path ) {
		$key = isset( $headers['Idempotency-Key'] ) ? (string) $headers['Idempotency-Key'] : '';
		if ( '' === $key ) {
			return array(
				400,
				array(
					'title'  => 'Bad Request',
					'status' => 400,
					'code'   => 'submission.idempotency_key_required',
				),
			);
		}
		// The same key: the same body gets the first answer back, a different body is a conflict.
		$same = wp_json_encode( $body );
		if ( isset( $whcc_lab['keys'][ $key ] ) ) {
			if ( $whcc_lab['keys'][ $key ]['body'] !== $same ) {
				return array(
					409,
					array(
						'type'   => 'about:blank',
						'title'  => 'The request body does not match a previous request that used this Idempotency-Key.',
						'status' => 409,
						'code'   => 'submission.idempotency_conflict',
					),
				);
			}
			$id = $whcc_lab['keys'][ $key ]['id'];
		} else {
			$mode = $whcc_lab['submit'];
			if ( 'timeout' === $mode ) {
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			}
			if ( '500' === (string) $mode ) {
				return array(
					500,
					array(
						'title'  => 'Internal Server Error',
						'status' => 500,
					),
				);
			}
			if ( 'refused' === $mode ) {
				return array(
					400,
					array(
						'title'         => 'Bad Request',
						'status'        => 400,
						'code'          => 'submission.not_submittable',
						'detail'        => 'The submission is not ready to place.',
						'invalidParams' => array(
							array(
								'name'   => 'shipping.address.zip',
								'reason' => 'Required for this shipping type.',
							),
						),
					),
				);
			}
			$basket = $whcc_price_basket( isset( $body['items'] ) ? $body['items'] : array() );
			if ( is_int( $basket[0] ) ) {
				return $basket;
			}
			$id                               = 'sub_' . wp_generate_password( 12, false );
			$whcc_lab['keys'][ $key ]         = array(
				'body' => $same,
				'id'   => $id,
			);
			$priority                         = isset( $body['shipping']['priority'] ) ? substr( $body['shipping']['priority'], strlen( 'dropship_' ) ) : 'lowest_cost';
			$per_package                      = isset( $whcc_lab['shipping'][ $priority ] ) ? $whcc_lab['shipping'][ $priority ] : 0;
			$whcc_lab['submissions'][ $id ] = array(
				'id'       => $id,
				'key'      => $key,
				'body'     => $body,
				'status'   => 'submitting',
				'orders'   => null,
				'product'  => $basket[1],
				'shipping' => round( $per_package * count( $basket[2] ), 2 ),
				'packages' => $basket[2],
			);
			if ( 'lost' === $mode ) {
				// WHCC has it, but the answer never reaches the store.
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			}
		}
		$submission = $whcc_lab['submissions'][ $id ];
		return array(
			202,
			array(
				'id'         => $id,
				'status'     => 'staged',
				'reference'  => isset( $submission['body']['reference'] ) ? $submission['body']['reference'] : '',
				'totalPrice' => array_merge(
					$whcc_money( $submission['product'] + $submission['shipping'] ),
					array(
						'subtotals' => array(
							'product'  => $whcc_money( $submission['product'] ),
							'shipping' => $whcc_money( $submission['shipping'] ),
						),
					)
				),
			),
		);
	}
	if ( 'GET' === $method && preg_match( '#^commerce/submissions/([^/]+)$#', $path, $match ) ) {
		$id = rawurldecode( $match[1] );
		if ( empty( $whcc_lab['submissions'][ $id ] ) ) {
			return array(
				404,
				array(
					'title'  => 'Not Found',
					'status' => 404,
				),
			);
		}
		$submission = $whcc_lab['submissions'][ $id ];
		$reply      = array(
			'id'         => $id,
			'status'     => $submission['status'],
			'totalPrice' => array_merge(
				$whcc_money( $submission['product'] + $submission['shipping'] ),
				array(
					'subtotals' => array(
						'product'  => $whcc_money( $submission['product'] ),
						'shipping' => $whcc_money( $submission['shipping'] ),
					),
				)
			),
		);
		// While WHCC is still placing it there is no orders key at all.
		if ( null !== $submission['orders'] ) {
			$reply['orders'] = $submission['orders'];
		}
		return array( 200, $reply );
	}

	return null;
};

// Answers every outgoing request. Nothing ever leaves: WHCC calls get the made-up lab,
// everything else is blocked.
$whcc_http = function ( $preempt, $args, $url ) use ( &$whcc_lab, $whcc_reply ) {
	$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
	$body   = ( isset( $args['body'] ) && is_string( $args['body'] ) && '' !== $args['body'] ) ? json_decode( $args['body'], true ) : null;
	$marker = 'whcc.com/api/v1/';
	$pos    = strpos( $url, $marker );
	$reply  = null;
	if ( false !== $pos && 0 === strpos( $url, 'https://apis-stage.whcc.com/' ) ) {
		$endpoint = substr( $url, $pos + strlen( $marker ) );
		$parts    = explode( '?', $endpoint, 2 );
		$headers  = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		$whcc_lab['requests'][] = array(
			'method'   => $method,
			'endpoint' => $parts[0],
			'query'    => isset( $parts[1] ) ? $parts[1] : '',
			'body'     => $body,
			'headers'  => $headers,
		);
		$reply = $whcc_reply( $method, $parts[0], isset( $parts[1] ) ? $parts[1] : '', is_array( $body ) ? $body : array(), $headers );
	}
	if ( is_wp_error( $reply ) ) {
		return $reply;
	}
	if ( null === $reply ) {
		$whcc_lab['blocked'][] = $method . ' ' . $url;
		return new WP_Error( 'spc_totals_blocked', 'Outgoing requests are blocked in the totals tests' );
	}
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( $reply[1] ),
		'response' => array(
			'code'    => $reply[0],
			'message' => $reply[0] < 400 ? 'OK' : 'Error',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};

// The requests the add-on sent to the made-up WHCC for one endpoint, in order.
$whcc_sent = function ( $method, $endpoint ) use ( &$whcc_lab ) {
	$found = array();
	foreach ( $whcc_lab['requests'] as $request ) {
		if ( $request['method'] === $method && $request['endpoint'] === $endpoint ) {
			$found[] = $request;
		}
	}
	return $found;
};

// The shipping quotes the add-on remembers for the rest of a page load.
$whcc_forget_page = function () {
	if ( class_exists( 'SPC_WHCC_Quotes' ) ) {
		$quotes = new ReflectionProperty( 'SPC_WHCC_Quotes', 'quotes' );
		$quotes->setAccessible( true );
		$quotes->setValue( null, array() );
	}
};

// Transients the add-on saved during a test (catalog products, shipping quotes). They are
// deleted at the end, so the test site's database is left as it was.
$whcc_transients     = array();
$whcc_note_transient = function ( $transient ) use ( &$whcc_transients ) {
	if ( 0 === strpos( $transient, 'sunshine_whcc_' ) ) {
		$whcc_transients[ $transient ] = true;
	}
};

// WordPress 6.8 renamed the hook that says a transient was saved.
$whcc_transient_hook = version_compare( get_bloginfo( 'version' ), '6.8', '>=' ) ? 'set_transient' : 'setted_transient';

// Jobs the add-on scheduled (reading a sent order back a minute later). They are kept here
// instead of in WP-Cron, so nothing runs later on the test site.
$whcc_scheduled = array();
$whcc_schedule  = function ( $pre, $event ) use ( &$whcc_scheduled ) {
	if ( is_object( $event ) && 0 === strpos( $event->hook, 'sunshine_whcc_' ) ) {
		$whcc_scheduled[] = $event;
		return true;
	}
	return $pre;
};

// Clears what the customer would be told, so a test only sees its own messages.
$whcc_clear_notices = function () {
	SPC()->session->set( 'notices', array() );
	$notices = new ReflectionProperty( 'SPC_Notices', 'notices' );
	$notices->setAccessible( true );
	$notices->setValue( SPC()->notices, array() );
};

// Listeners switched off while a test runs, and the visitor's IP address, put back at the end.
// Analytics and the automated email queue write to shared tables on add to cart and don't touch
// the money math. The WHCC rate limit counts visitors by IP address and saves the count, and
// WP-CLI reports 127.0.0.1, so the address is left out.
$whcc_paused = array(
	'listeners'   => array(),
	'remote_addr' => null,
);

// Writes the add-on makes to its own settings while a test runs, kept out of the database.
$whcc_keep_option = function ( $value, $old_value ) {
	return $old_value;
};
$whcc_kept_options = array( 'sunshine_whcc_sandbox_last_event_at', 'sunshine_whcc_production_last_event_at' );

// WHCC's prices per size, which the add-on saves in an option, live here while a test runs, so
// the test site's real saved prices are left alone and each test starts with none.
$whcc_saved_prices = array();
$whcc_prices_read  = function () use ( &$whcc_saved_prices ) {
	return $whcc_saved_prices;
};
$whcc_prices_write = function ( $value, $old_value ) use ( &$whcc_saved_prices ) {
	$whcc_saved_prices = $value;
	return $old_value;
};

/**
 * Start the made-up WHCC for one test: WHCC's settings at their defaults with a made-up key,
 * the lab answering every request (unless $own_http, for a test file that answers requests
 * itself and passes WHCC's on to $whcc_http), mail stopped, and anything the add-on saves
 * noted so $whcc_close() can take it away.
 */
$whcc_open = function ( $own_http = false ) use ( $t, &$whcc_lab, $whcc_lab_reset, $whcc_http, &$whcc_transients, $whcc_note_transient, $whcc_transient_hook, &$whcc_scheduled, $whcc_schedule, $whcc_forget_page, $whcc_keep_option, $whcc_kept_options, $whcc_key, $whcc_clear_notices, &$whcc_paused, &$whcc_saved_prices, $whcc_prices_read, $whcc_prices_write ) {
	$whcc_lab_reset();
	$whcc_saved_prices = array();
	add_filter( 'pre_option_sunshine_whcc_prices_sandbox', $whcc_prices_read );
	add_filter( 'pre_update_option_sunshine_whcc_prices_sandbox', $whcc_prices_write, 10, 2 );
	$whcc_transients = array();
	$whcc_scheduled  = array();
	$whcc_clear_notices();

	$whcc_paused = array(
		'listeners'   => array(),
		'remote_addr' => isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null,
	);
	$listeners   = array(
		'sunshine_add_cart_item' => array( 'sunshine_adv_reports_add_to_cart', 'sunshine_email_queue_after_cart' ),
		'sunshine_cart_update'   => array( 'sunshine_adv_reports_cart_update' ),
	);
	foreach ( $listeners as $hook => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$priority = has_action( $hook, $callback );
			if ( false !== $priority ) {
				remove_action( $hook, $callback, $priority );
				$whcc_paused['listeners'][] = array( $hook, $callback, $priority );
			}
		}
	}
	unset( $_SERVER['REMOTE_ADDR'] );

	$t::option( 'whcc_environment', 'sandbox' );
	$t::option( 'whcc_sandbox_api_key', $whcc_key );
	$t::option( 'whcc_production_api_key', '' );
	$t::option( 'whcc_sandbox_webhook_secret', 'spc-totals-webhook-secret' );
	$t::option( 'whcc_production_webhook_secret', '' );
	$t::option( 'whcc_mixed_cart_surcharge', '' );
	$t::option( 'whcc_show_shipping_packages', 'no' );

	if ( ! $own_http ) {
		add_filter( 'pre_http_request', $whcc_http, PHP_INT_MAX, 3 );
	}
	add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
	add_filter( 'pre_schedule_event', $whcc_schedule, 10, 2 );
	add_action( $whcc_transient_hook, $whcc_note_transient );
	foreach ( $whcc_kept_options as $option ) {
		add_filter( 'pre_update_option_' . $option, $whcc_keep_option, 10, 2 );
	}
	$whcc_forget_page();
};

$whcc_close = function ( $own_http = false ) use ( &$whcc_transients, $whcc_note_transient, $whcc_transient_hook, $whcc_http, $whcc_schedule, $whcc_forget_page, $whcc_keep_option, $whcc_kept_options, &$whcc_paused, $whcc_prices_read, $whcc_prices_write ) {
	remove_filter( 'pre_option_sunshine_whcc_prices_sandbox', $whcc_prices_read );
	remove_filter( 'pre_update_option_sunshine_whcc_prices_sandbox', $whcc_prices_write, 10 );
	foreach ( $whcc_paused['listeners'] as $listener ) {
		add_action( $listener[0], $listener[1], $listener[2] );
	}
	if ( null !== $whcc_paused['remote_addr'] ) {
		$_SERVER['REMOTE_ADDR'] = $whcc_paused['remote_addr'];
	}
	$whcc_paused = array(
		'listeners'   => array(),
		'remote_addr' => null,
	);
	if ( ! $own_http ) {
		remove_filter( 'pre_http_request', $whcc_http, PHP_INT_MAX );
	}
	remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
	remove_filter( 'pre_schedule_event', $whcc_schedule, 10 );
	remove_action( $whcc_transient_hook, $whcc_note_transient );
	foreach ( $whcc_kept_options as $option ) {
		remove_filter( 'pre_update_option_' . $option, $whcc_keep_option, 10 );
	}
	foreach ( array_keys( $whcc_transients ) as $transient ) {
		delete_transient( $transient );
	}
	$whcc_transients = array();
	$whcc_forget_page();
	unset( $_GET['whcc_item'], $_GET['whcc_edit'], $_GET['whcc_updated_item'], $_GET['sunshine_whcc_webhook'] );
};

// Every shipping method object the cart builds hooks itself onto these filters again and is
// never unhooked, so a long run gets slower and slower. Each WHCC test removes the copies it
// added (the methods stay registered once, as at the start).
$whcc_method_hooks = array( 'sunshine_shipping_methods', 'sunshine_checkout_delivery_options' );
$whcc_hook_ids     = function () use ( $whcc_method_hooks ) {
	global $wp_filter;
	$ids = array();
	foreach ( $whcc_method_hooks as $hook ) {
		$ids[ $hook ] = array();
		if ( ! empty( $wp_filter[ $hook ] ) ) {
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( array_keys( $callbacks ) as $id ) {
					$ids[ $hook ][ $priority . '|' . $id ] = true;
				}
			}
		}
	}
	return $ids;
};
$whcc_hook_trim    = function ( $before ) {
	global $wp_filter;
	foreach ( $before as $hook => $ids ) {
		if ( empty( $wp_filter[ $hook ] ) ) {
			continue;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback ) {
				if ( ! isset( $ids[ $priority . '|' . $id ] ) && is_array( $callback['function'] ) && is_object( $callback['function'][0] ) && $callback['function'][0] instanceof SPC_Shipping_Method ) {
					remove_filter( $hook, $callback['function'], $priority );
				}
			}
		}
	}
};

/**
 * Run one WHCC test: the lab answers every request, mail is blocked, the WHCC settings start
 * at their defaults, and what the add-on saved is cleared at the end. Every test also checks
 * that no PHP warning was raised and that nothing tried to go anywhere but the made-up WHCC.
 */
$whcc_test = function ( $name, $callback ) use ( $t, &$whcc_lab, $whcc_open, $whcc_close, $whcc_hook_ids, $whcc_hook_trim ) {
	$t::test(
		'WHCC: ' . $name,
		function () use ( $t, &$whcc_lab, $whcc_open, $whcc_close, $whcc_hook_ids, $whcc_hook_trim, $callback ) {
			$hooks_before = $whcc_hook_ids();
			$t::require_plugin( 'sunshine_whcc_init', 'WHCC add-on' );
			if ( ! function_exists( 'sunshine_whcc_ajax_add_to_cart' ) || ! class_exists( 'SPC_WHCC_Quotes' ) ) {
				$t::skip( 'WHCC add-on did not load' );
			}
			$whcc_open();

			// PHP warnings and notices from the code under test (deprecations still print).
			$warnings = array();
			set_error_handler(
				function ( $level, $message, $file, $line ) use ( &$warnings ) {
					if ( E_DEPRECATED === $level || E_USER_DEPRECATED === $level ) {
						return false;
					}
					$warnings[] = $message . ' (' . basename( $file ) . ':' . $line . ')';
					return true;
				}
			);

			try {
				$callback();
			} finally {
				restore_error_handler();
				$whcc_close();
				$whcc_hook_trim( $hooks_before );
			}

			$t::same( array(), $warnings, 'no PHP warnings or notices' );
			$t::same( array(), $whcc_lab['blocked'], 'no request went anywhere the made-up WHCC does not answer' );
		}
	);
};

/**
 * A WHCC product, saved the way the product edit screen saves it: type "whcc", the chosen
 * WHCC catalog product, a markup percent per price level, and a price of 0 for every price
 * level that has a markup ('' where it has none), because WHCC sets the real price.
 * $markups is price level id => markup percent as typed. $group is how WHCC packs it: items
 * in different groups ship as separate packages.
 */
$whcc_product = function ( $markups = null, $taxable = true, $group = 'Prints' ) use ( $t, $whcc_run, &$whcc_counter, &$whcc_lab ) {
	$whcc_counter++;
	$ref                         = $whcc_run . '-' . $whcc_counter;
	$whcc_lab['catalog'][ $ref ] = array(
		'ref'           => $ref,
		'name'          => 'Wall Art' === $group ? 'Canvas' : 'Photo Print',
		'group'         => $group,
		'unit'          => 10.00,
		'requirements'  => array(
			'requiresImage'  => true,
			'requiresDesign' => false,
			'workflow'       => 'configurable',
		),
		'configuration' => array(
			'fields' => array(
				array(
					'field'   => 'size',
					'kind'    => 'enum',
					'default' => '8x10',
					'values'  => array(
						array(
							'id'      => '8x10',
							'enabled' => true,
						),
						array(
							'id'      => '11x14',
							'enabled' => true,
						),
					),
				),
				array(
					'field'   => 'surface',
					'kind'    => 'enum',
					'default' => '',
					'values'  => array(
						array(
							'id'      => 'paper',
							'enabled' => true,
						),
						array(
							'id'      => 'canvas',
							'enabled' => true,
						),
					),
				),
				// Belongs to the "paper" surface.
				array(
					'field'   => 'paper',
					'kind'    => 'enum',
					'default' => 'lustre',
					'values'  => array(
						array(
							'id'      => 'lustre',
							'enabled' => true,
						),
					),
				),
				// Belongs to the "canvas" surface.
				array(
					'field'   => 'canvas',
					'kind'    => 'enum',
					'default' => '',
					'values'  => array(
						array(
							'id'      => 'gloss',
							'enabled' => false,
						),
						array(
							'id'      => 'matte',
							'enabled' => true,
						),
					),
				),
				array(
					'field'   => 'orientation',
					'kind'    => 'enum',
					'default' => 'portrait',
					'values'  => array(
						array(
							'id'      => 'portrait',
							'enabled' => true,
						),
					),
				),
			),
		),
	);
	if ( null === $markups ) {
		$markups = array( sunshine_get_default_price_level_id() => '50' );
	}

	$id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-product',
			'post_status' => 'publish',
			'post_title'  => 'WHCC ' . $whcc_lab['catalog'][ $ref ]['name'],
		)
	);
	$t::track( 'post', $id );

	$saved_markups = array();
	$prices        = array();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		$level_id                   = $level->get_id();
		$saved_markups[ $level_id ] = isset( $markups[ $level_id ] ) ? (string) $markups[ $level_id ] : '';
		$prices[ $level_id ]        = ( '' !== $saved_markups[ $level_id ] ) ? 0 : '';
	}
	update_post_meta( $id, 'type', 'whcc' );
	update_post_meta( $id, 'whcc_product', $ref );
	update_post_meta( $id, 'whcc_markup', $saved_markups );
	update_post_meta( $id, 'price', $prices );
	update_post_meta( $id, 'taxable', $taxable ? '1' : '' );
	update_post_meta( $id, 'min_qty', '' );
	update_post_meta( $id, 'max_qty', '' );
	return $id;
};

/**
 * An item the customer finished in WHCC's editor (a configured product), for a Sunshine
 * WHCC product. $prices is WHCC's price for one (a number), or lowest quantity => price for
 * one. $qty is the quantity it was built with. $extra can set 'name', 'valid' and 'problems'.
 */
$whcc_item = function ( $product_id, $prices, $qty = 1, $extra = array() ) use ( &$whcc_lab ) {
	$ref = get_post_meta( $product_id, 'whcc_product', true );
	$id  = 'CF_spc' . wp_generate_password( 12, false );

	$whcc_lab['items'][ $id ] = array_merge(
		array(
			'ref'      => $ref,
			'name'     => '8×10 ' . $whcc_lab['catalog'][ $ref ]['name'],
			'group'    => $whcc_lab['catalog'][ $ref ]['group'],
			'prices'   => is_array( $prices ) ? $prices : array( 1 => $prices ),
			'qty'      => $qty,
			'valid'    => true,
			'problems' => array(),
			'status'   => 'ready',
		),
		$extra
	);
	return $id;
};

// Run something that ends in a redirect, and stop at the redirect instead of exiting.
// Returns where it was going, or null if it didn't redirect.
$whcc_until_redirect = function ( $callback ) {
	$location = null;
	$redirect = function ( $to ) use ( &$location ) {
		$location = $to;
		throw new SPC_Totals_Test_Done();
	};
	add_filter( 'wp_redirect', $redirect, -1000 );
	try {
		$callback();
	} catch ( SPC_Totals_Test_Done $e ) {
		// It redirected.
	} finally {
		remove_filter( 'wp_redirect', $redirect, -1000 );
	}
	return $location;
};

// The cart line array for a configured product, or null.
$whcc_cart_line = function ( $configured_id ) {
	foreach ( (array) SPC()->cart->get_cart() as $item ) {
		if ( ! empty( $item['meta']['whcc_configured_product_id'] ) && $item['meta']['whcc_configured_product_id'] === $configured_id ) {
			return $item;
		}
	}
	return null;
};

// The cart line object for a configured product, or null.
$whcc_line = function ( $configured_id ) {
	foreach ( (array) SPC()->cart->get_cart_items( true ) as $line ) {
		if ( $line->get_meta_value( 'whcc_configured_product_id' ) === $configured_id ) {
			return $line;
		}
	}
	return null;
};

// Run an AJAX handler the way admin-ajax.php does and return its JSON reply.
$whcc_ajax = function ( $handler ) {
	$die = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $die );
	ob_start();
	try {
		$handler();
	} catch ( SPC_Totals_Test_Done $e ) {
		// The handler always ends by sending a JSON reply.
	} finally {
		$output = ob_get_clean();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $die );
	}
	return json_decode( $output, true );
};

// What the add-on's two answers said the last time a customer came back from the editor: the
// price window ('window') and its Add to cart ('add'). Each is the JSON reply, or null when the
// step wasn't reached.
$whcc_replies = array(
	'window' => null,
	'add'    => null,
);

/**
 * The customer finishes in WHCC's editor and WHCC sends them back to the page with
 * ?whcc_item=..., which opens the price window. Nothing is in the cart until they press its Add
 * to cart. Both are the add-on's real AJAX answers. The item must have been built by this visitor,
 * which $remember says (what the add-on keeps when it opens the editor): the product, photo,
 * gallery and price level it was picked on. $add false stops at the window. Returns the new cart
 * line, or null if it was not added; the replies are in $whcc_replies.
 */
$whcc_return = function ( $configured_id, $remember = null, $add = true ) use ( $whcc_ajax, $whcc_cart_line, $whcc_forget_page, &$whcc_replies ) {
	if ( is_array( $remember ) ) {
		sunshine_whcc_remember_item( $configured_id, $remember );
	}
	$whcc_replies = array(
		'window' => null,
		'add'    => null,
	);
	$_POST['item']     = $configured_id;
	$_POST['security'] = wp_create_nonce( 'sunshinephotocart' );
	try {
		$whcc_replies['window'] = $whcc_ajax( 'sunshine_whcc_modal_item' );
		if ( $add ) {
			// Pressing Add to cart is a new request.
			$whcc_forget_page();
			$whcc_replies['add'] = $whcc_ajax( 'sunshine_whcc_ajax_add_to_cart' );
		}
	} finally {
		unset( $_POST['item'], $_POST['security'] );
	}
	$whcc_forget_page();
	return $whcc_cart_line( $configured_id );
};

// The price window's text, without tags.
$whcc_window_text = function () use ( &$whcc_replies ) {
	$html = isset( $whcc_replies['window']['data']['html'] ) ? $whcc_replies['window']['data']['html'] : '';
	return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
};

// Why the price window's Add to cart refused the item, as the customer reads it.
$whcc_refusal = function () use ( &$whcc_replies ) {
	return isset( $whcc_replies['add']['data']['message'] ) ? $whcc_replies['add']['data']['message'] : null;
};

/**
 * Put a WHCC item in the cart the way a customer does (built by them, back from the editor).
 * Returns the configured product id. $prices and $qty are as for $whcc_item.
 */
$whcc_buy = function ( $product_id, $prices, $qty = 1, $where = array(), $extra = array() ) use ( $whcc_item, $whcc_return, &$whcc_replies ) {
	$id   = $whcc_item( $product_id, $prices, $qty, $extra );
	$line = $whcc_return(
		$id,
		array_merge(
			array(
				'product_id'  => $product_id,
				'image_id'    => 0,
				'gallery_id'  => '',
				'price_level' => sunshine_get_default_price_level_id(),
			),
			$where
		)
	);
	if ( ! $line ) {
		throw new Exception( 'The WHCC item was not added: ' . wp_json_encode( $whcc_replies ) );
	}
	return $id;
};

// The customer presses Update on the cart page with new quantities (configured product id =>
// quantity). Runs core's real cart update, then the next page load.
$whcc_update_cart = function ( $quantities = array() ) use ( $t, $whcc_until_redirect, $whcc_forget_page ) {
	$posted = array();
	foreach ( (array) SPC()->cart->get_cart() as $item ) {
		$id       = isset( $item['meta']['whcc_configured_product_id'] ) ? $item['meta']['whcc_configured_product_id'] : '';
		$posted[] = array(
			'hash' => $item['hash'],
			'qty'  => ( $id && isset( $quantities[ $id ] ) ) ? $quantities[ $id ] : $item['qty'],
		);
	}
	$_POST['sunshine_update_cart'] = 1;
	$_POST['nonce']                = wp_create_nonce( 'sunshine_update_cart' );
	$_POST['item']                 = $posted;
	try {
		$whcc_until_redirect( 'sunshine_update_cart' );
	} finally {
		unset( $_POST['sunshine_update_cart'], $_POST['nonce'], $_POST['item'] );
	}
	$whcc_forget_page();
	return $t::new_request();
};

// A new page load: the cart is built again, and what the add-on kept for one page is gone.
$whcc_next_page = function () use ( $t, $whcc_forget_page ) {
	$whcc_forget_page();
	return $t::new_request();
};

/**
 * Offer WHCC shipping methods the way Delivery & Shipping saves them, one instance each.
 * $markups is method key => array( percent, flat ) as typed. $extra adds other methods:
 * instance => array( 'id' => ..., settings... ).
 */
$whcc_ship_setup = function ( $methods = array( 'lowest_cost', 'expedited', 'one_day_standard', 'one_day_priority' ), $taxable = false, $extra = array(), $markups = array() ) use ( $t ) {
	$list = array();
	foreach ( $methods as $key ) {
		$instance          = 'spcwhcc' . $key;
		$list[ $instance ] = array(
			'id'     => 'whcc_' . $key,
			'active' => 1,
		);
		$t::option( 'whcc_' . $key . '_taxable_' . $instance, $taxable ? 1 : '' );
		$t::option( 'whcc_' . $key . '_markup_percent_' . $instance, isset( $markups[ $key ] ) ? (string) $markups[ $key ][0] : '' );
		$t::option( 'whcc_' . $key . '_markup_flat_' . $instance, isset( $markups[ $key ] ) ? (string) $markups[ $key ][1] : '' );
		$t::option( 'whcc_' . $key . '_name_' . $instance, '' );
		$t::option( 'whcc_' . $key . '_description_' . $instance, '' );
	}
	foreach ( $extra as $instance => $settings ) {
		$method_id         = $settings['id'];
		$list[ $instance ] = array(
			'id'     => $method_id,
			'active' => 1,
		);
		unset( $settings['id'] );
		foreach ( $settings as $key => $value ) {
			$t::option( $method_id . '_' . $key . '_' . $instance, $value );
		}
	}
	$t::option( 'shipping_methods', $list );
};

// The customer picks "Ship" and a WHCC method, to a US address (Colorado unless given).
$whcc_ship_with = function ( $method_key, $state = 'CO', $country = 'US' ) use ( $t ) {
	$t::checkout_address();
	SPC()->cart->set_checkout_data_item( 'shipping_state', $state );
	SPC()->cart->set_checkout_data_item( 'shipping_country', $country );
	SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
	SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcwhcc' . $method_key );
};

// Which shipping methods the checkout offers right now (WHCC method keys, others by instance).
$whcc_offered = function () {
	$keys = array();
	foreach ( array_keys( (array) sunshine_get_allowed_shipping_methods() ) as $instance ) {
		$keys[] = 0 === strpos( $instance, 'spcwhcc' ) ? substr( $instance, 7 ) : $instance;
	}
	return $keys;
};

// A public gallery at a price level, with one photo (no file, which nothing here needs).
$whcc_gallery = function ( $level_id ) use ( $t ) {
	$gallery_id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-gallery',
			'post_status' => 'publish',
			'post_title'  => 'WHCC test gallery',
		)
	);
	$t::track( 'post', $gallery_id );
	update_post_meta( $gallery_id, 'status', 'default' );
	update_post_meta( $gallery_id, 'access_type', '' );
	update_post_meta( $gallery_id, 'price_level', (string) $level_id );
	$photo_id = wp_insert_attachment(
		array(
			'post_title'     => 'whcc-test-photo',
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		),
		false,
		$gallery_id
	);
	$t::track( 'post', $photo_id );
	add_post_meta( $photo_id, 'sunshine_file_name', 'whcc-test-photo.jpg' );
	add_post_meta( $photo_id, 'sunshine_watermark', 0 );
	update_post_meta( $gallery_id, 'images', array( $photo_id ) );
	return array(
		'id'    => $gallery_id,
		'photo' => $photo_id,
	);
};

// A price level that is not the default one, or skip the test when the site has only one.
$whcc_other_level = function () use ( $t ) {
	$default = sunshine_get_default_price_level_id();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		if ( $level && (int) $level->get_id() !== (int) $default ) {
			return (int) $level->get_id();
		}
	}
	$t::skip( 'Needs a second price level' );
};

// The notices the customer would see on the next page, as text.
$whcc_notices = function () {
	$texts = array();
	foreach ( (array) SPC()->session->get( 'notices' ) as $notice ) {
		if ( is_array( $notice ) ) {
			$texts[] = $notice['type'] . ': ' . wp_strip_all_tags( $notice['text'] );
		}
	}
	return $texts;
};

/**
 * Try to place the order through the real checkout step, the way pressing the button does.
 * Unlike $t::place_order() this doesn't expect it to work: it returns whether it did, the
 * order id (also for an order that was saved and then stopped before payment), and the
 * errors the customer sees.
 */
$whcc_try_order = function () use ( $t, $whcc_ajax ) {
	$t::checkout_address();
	$saved   = 0;
	$capture = function ( $order ) use ( &$saved, $t ) {
		$saved = $order->get_id();
		$t::track( 'order', $saved );
	};
	add_action( 'sunshine_checkout_init_order_success', $capture, -1000 );
	$errors_before = count( (array) SPC()->cart->get_errors() );
	$_POST['security']       = wp_create_nonce( 'sunshine-checkout-init-order' );
	$_POST['payment_method'] = 'test';
	SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
	try {
		$reply = $whcc_ajax( array( SPC()->cart, 'init_order' ) );
	} finally {
		unset( $_POST['security'], $_POST['payment_method'] );
		remove_action( 'sunshine_checkout_init_order_success', $capture, -1000 );
	}
	return array(
		'placed'   => ! empty( $reply['success'] ),
		'order_id' => $saved,
		'errors'   => array_slice( (array) SPC()->cart->get_errors(), $errors_before ),
	);
};

// Mark the order paid, the way a finished payment does, and read it back.
$whcc_pay = function ( $order ) {
	$order->set_status( 'new' );
	return sunshine_get_order( $order->get_id() );
};

/**
 * WHCC finishes placing a sent order. $orders lists the WHCC orders it became, each
 * array( confirmationId, orderNumber ) or array( 'failure' => message ) for one that failed;
 * left out, it is one WHCC order per package. $status is the submission's own status.
 */
$whcc_settle = function ( $submission_id, $status = 'placed', $orders = null ) use ( &$whcc_lab ) {
	if ( null === $orders ) {
		$orders = array();
		foreach ( $whcc_lab['submissions'][ $submission_id ]['packages'] as $n => $package ) {
			$orders[] = array( 'spcconf-' . $submission_id . '-' . ( $n + 1 ), 99000000 + $n + 1 );
		}
	}
	$entries = array();
	foreach ( $orders as $order ) {
		if ( isset( $order['failure'] ) ) {
			$entries[] = array(
				'status'  => 'order_failed',
				'failure' => array(
					'code'    => 'submission.failure.rejected_by_whcc',
					'message' => $order['failure'],
				),
			);
		} else {
			$entries[] = array(
				'confirmationId' => $order[0],
				'orderNumber'    => $order[1],
				'status'         => 'order_received',
			);
		}
	}
	$whcc_lab['submissions'][ $submission_id ]['status'] = $status;
	$whcc_lab['submissions'][ $submission_id ]['orders'] = $entries;
};

/**
 * Send WHCC's order update to the site's webhook, signed the way WHCC signs it, and return the
 * HTTP status the site answers with. $sign: 'ok' (with the stored secret), 'wrong' (another
 * secret), 'none' (no signature), 'old' (signed eight days ago), or 'second' (two v1 values,
 * the right one second, as while WHCC changes a secret).
 *
 * The webhook reads the raw request body, so php://input is swapped for the delivery while it
 * runs. It ends with exit(), so the status it sets just before is turned into an exception.
 */
$whcc_deliver = function ( $data, $sign = 'ok' ) use ( $t ) {
	$payload = wp_json_encode( $data );
	$secret  = SPC()->get_option( 'whcc_sandbox_webhook_secret' );
	$time    = 'old' === $sign ? time() - 8 * DAY_IN_SECONDS : time();

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
	$input_class::$body = $payload;

	$status         = null;
	$stop           = function ( $header, $code ) use ( &$status ) {
		$status = $code;
		throw new SPC_Totals_Test_Done();
	};
	$filters_before = $GLOBALS['wp_current_filter'];

	$good = hash_hmac( 'sha256', $time . '.' . $payload, $secret );
	$bad  = hash_hmac( 'sha256', $time . '.' . $payload, 'not-the-secret' );
	$headers = array(
		'ok'     => 't=' . $time . ',v1=' . $good,
		'wrong'  => 't=' . $time . ',v1=' . $bad,
		'none'   => '',
		'old'    => 't=' . $time . ',v1=' . $good,
		'second' => 't=' . $time . ',v1=' . $bad . ',v1=' . $good,
	);

	add_filter( 'status_header', $stop, PHP_INT_MAX, 2 );
	$_GET['sunshine_whcc_webhook']   = 1;
	$_SERVER['HTTP_WHCC_SIGNATURE'] = $headers[ $sign ];
	stream_wrapper_unregister( 'php' );
	stream_wrapper_register( 'php', $input_class );
	try {
		sunshine_whcc_webhook_process();
	} catch ( SPC_Totals_Test_Done $e ) {
		// The webhook always ends with a status and exit().
	} finally {
		stream_wrapper_restore( 'php' );
		remove_filter( 'status_header', $stop, PHP_INT_MAX );
		unset( $_GET['sunshine_whcc_webhook'], $_SERVER['HTTP_WHCC_SIGNATURE'] );
		$GLOBALS['wp_current_filter'] = $filters_before;
	}
	return $status;
};

// One WHCC order update, shaped like WHCC's version 2 deliveries. $event is accepted,
// rejected, shipped or cancelled.
$whcc_update = function ( $order, $confirmation_id, $event, $extra = array() ) {
	$data = array();
	if ( 'accepted' === $event || 'rejected' === $event ) {
		$data['Status'] = 'accepted' === $event ? 'Accepted' : 'Rejected';
		$data['Errors'] = array();
		$data['Event']  = 'Processed';
	} elseif ( 'shipped' === $event ) {
		$data['ShippingInfo'] = array();
		$data['Event']        = 'Shipped';
	} else {
		$data['Event'] = 'Order Cancelled';
	}
	$data = array_merge(
		$data,
		array(
			'ConfirmationId' => $confirmation_id,
			'EntryId'        => 'spc-entry-' . $confirmation_id,
			'Reference'      => (string) $order->get_id(),
			'SequenceNumber' => '1',
		),
		$extra
	);
	$data['EventType'] = 'order.' . $event;
	return $data;
};
