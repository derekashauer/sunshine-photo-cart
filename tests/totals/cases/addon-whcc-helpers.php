<?php
/**
 * Shared setup for the WHCC add-on tests (addon-whcc.php and addon-whcc-orders.php).
 * This file has no tests of its own. Both test files load it, so either one can run alone.
 *
 * How a WHCC item gets into the cart in real life: the customer builds the item in WHCC's
 * editor, WHCC sends them back with ?whcc_editor_id=..., and the add-on asks WHCC what that
 * editor costs ("export"). WHCC answers with the line price after the photographer's markup
 * (markedUpPrice), the quantity, and WHCC's own cost for one item (unitBasePrice).
 *
 * Nothing here talks to WHCC. Every outgoing request is answered by a made-up WHCC ("the lab")
 * or blocked. The lab answers in the same shape the add-on reads, for these calls only:
 *   PUT  oas/editors/export        prices (and, unless price only, the package split)
 *   GET  editors/{id}              what the customer built
 *   POST editors                   open a new editor (records the markup it was given)
 *   POST oas/orders/create         send a paid order to WHCC
 *   POST oas/orders/{id}/confirm   confirm it
 * WHCC's product list is fed through the cached copy the add-on reads (in memory only).
 */

if ( isset( $whcc_test ) ) {
	return; // Already loaded by the other WHCC test file.
}

$t = 'SPC_Totals_Test';

// Every WHCC product id made here is new, so looking a product up by its WHCC id only ever
// finds the product made for that test.
$whcc_run     = 'spcwhcc' . wp_rand( 100000, 999999 );
$whcc_counter = 0;

// WHCC's product list, as the add-on caches it. Only the fields the add-on reads.
// Shipping groups come from the category name: Albums, Books and Wall Art have their own
// shipping rules, anything else ships as "Everything Else".
$whcc_catalog    = array();
$whcc_categories = array(
	'print' => array( 'Photo Print', 'Photo Prints' ),
	'card'  => array( '5x7 Flat Card Vertical', 'Cards' ),
	'wrap'  => array( 'Gallery Wrap', 'Wall Art' ),
	'album' => array( 'Album', 'Albums' ),
	'book'  => array( 'Hardcover Book', 'Books' ),
);

// The made-up lab. Reset before every test.
$whcc_lab       = array();
$whcc_lab_reset = function () use ( &$whcc_lab ) {
	$whcc_lab = array(
		// editor id => what the customer built and what WHCC charges for it.
		'editors'     => array(),
		// How WHCC splits the order into packages: a list of lists of editor ids.
		// null = one package per shipping group, and one per design for cards, albums and books.
		'split'       => null,
		'fail_export' => false,
		'requests'    => array(),
		'blocked'     => array(),
		'confirmed'   => 0,
	);
};
$whcc_lab_reset();

$whcc_group_of_type = function ( $type ) {
	$groups = array(
		'wrap'  => 'wall_art',
		'album' => 'albums',
		'book'  => 'books',
	);
	return isset( $groups[ $type ] ) ? $groups[ $type ] : 'everything_else';
};

// The packages WHCC makes from a set of editors, shaped like export's order.Orders.
$whcc_sub_orders = function ( $editor_ids ) use ( &$whcc_lab, $whcc_group_of_type ) {
	$split = $whcc_lab['split'];
	if ( null === $split ) {
		$split = array();
		foreach ( $editor_ids as $editor_id ) {
			$type = $whcc_lab['editors'][ $editor_id ]['type'];
			$key  = in_array( $type, array( 'card', 'album', 'book' ), true ) ? $editor_id : $whcc_group_of_type( $type );
			$split[ $key ][] = $editor_id;
		}
		$split = array_values( $split );
	}
	$orders = array();
	foreach ( $split as $i => $ids ) {
		$items = array();
		foreach ( $ids as $editor_id ) {
			if ( ! in_array( $editor_id, $editor_ids, true ) ) {
				continue;
			}
			$items[] = array(
				'EditorId'   => $editor_id,
				'ProductUID' => 1000 + $i,
				'Quantity'   => $whcc_lab['editors'][ $editor_id ]['qty'],
			);
		}
		if ( $items ) {
			$orders[] = array(
				'SequenceNumber' => $i + 1,
				'OrderItems'     => $items,
			);
		}
	}
	return $orders;
};

// The lab's answer to one request: array( status code, body ), or null for "not a call WHCC knows".
$whcc_reply = function ( $method, $endpoint, $body ) use ( &$whcc_lab, $whcc_sub_orders ) {
	if ( 'PUT' === $method && 'oas/editors/export' === $endpoint ) {
		if ( $whcc_lab['fail_export'] ) {
			return array( 500, array( 'message' => 'WHCC is not answering' ) );
		}
		$items = array();
		$ids   = array();
		foreach ( (array) ( isset( $body['editors'] ) ? $body['editors'] : array() ) as $row ) {
			$editor_id = isset( $row['editorId'] ) ? $row['editorId'] : '';
			if ( empty( $whcc_lab['editors'][ $editor_id ] ) ) {
				continue;
			}
			$editor = $whcc_lab['editors'][ $editor_id ];
			$qty    = isset( $row['quantity'] ) ? intval( $row['quantity'] ) : $editor['qty'];
			$line   = isset( $editor['lines'][ $qty ] ) ? $editor['lines'][ $qty ] : round( $editor['unit'] * $qty, 2 );
			$items[] = array(
				'editor'  => array(
					'id'                => $editor_id,
					'selectionsSummary' => $editor['summary'],
				),
				'pricing' => array(
					'markedUpPrice' => $line,
					'quantity'      => $qty,
					'unitBasePrice' => $editor['base'],
				),
			);
			$ids[]   = $editor_id;
		}
		$reply = array( 'items' => $items );
		if ( empty( $body['priceOnly'] ) ) {
			$reply['order'] = array( 'Orders' => $whcc_sub_orders( $ids ) );
		}
		return array( 200, $reply );
	}
	if ( 'GET' === $method && preg_match( '#^editors/([^/?]+)$#', $endpoint, $match ) && ! empty( $whcc_lab['editors'][ $match[1] ] ) ) {
		$editor = $whcc_lab['editors'][ $match[1] ];
		$reply  = array(
			'editorId'          => $match[1],
			'productId'         => $editor['whcc_id'],
			'selections'        => array( 'quantity' => $editor['qty'] ),
			'selectionsSummary' => $editor['summary'],
			'productPreviews'   => array(),
		);
		if ( ! empty( $editor['photo'] ) ) {
			$reply['selections']['photo'] = array( 'galleryId' => (string) $editor['photo'] );
		}
		return array( 200, $reply );
	}
	if ( 'POST' === $method && 'editors' === $endpoint ) {
		return array(
			200,
			array(
				'editorId' => 'spcnewed' . count( $whcc_lab['requests'] ),
				'url'      => 'https://editor.example.invalid/spc',
			),
		);
	}
	if ( 'POST' === $method && 'oas/orders/create' === $endpoint ) {
		return array(
			200,
			array(
				'ConfirmationID' => 'SPCTEST-CONFIRM',
				'Orders'         => isset( $body['Orders'] ) ? $body['Orders'] : array(),
			),
		);
	}
	if ( 'POST' === $method && preg_match( '#^oas/orders/([^/]+)/confirm$#', $endpoint, $match ) ) {
		$whcc_lab['confirmed']++;
		return array(
			200,
			array( 'ConfirmedOrders' => array( array( 'ConfirmationID' => $match[1] ) ) ),
		);
	}
	return null;
};

// Answers every outgoing request. Nothing ever leaves: WHCC calls get the made-up lab,
// everything else is blocked.
$whcc_http = function ( $preempt, $args, $url ) use ( &$whcc_lab, $whcc_reply ) {
	$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
	$body   = ( isset( $args['body'] ) && is_string( $args['body'] ) ) ? json_decode( $args['body'], true ) : null;
	$marker = 'dragdrop.design/api/v1/';
	$pos    = strpos( $url, $marker );
	$reply  = null;
	if ( false !== $pos ) {
		$endpoint                 = substr( $url, $pos + strlen( $marker ) );
		$whcc_lab['requests'][] = array(
			'method'   => $method,
			'endpoint' => $endpoint,
			'body'     => $body,
		);
		$reply                    = $whcc_reply( $method, $endpoint, is_array( $body ) ? $body : array() );
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
			'message' => 200 === $reply[0] ? 'OK' : 'Error',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};

// The cached WHCC product list the add-on reads, fed from memory.
$whcc_products_transient = function () use ( &$whcc_catalog ) {
	return array_values( $whcc_catalog );
};

// The one object the add-on uses for the "back from the editor" add to cart.
$whcc_add_to_cart_object = function () {
	global $wp_filter;
	if ( empty( $wp_filter['sunshine_add_to_cart_item'] ) ) {
		return null;
	}
	foreach ( $wp_filter['sunshine_add_to_cart_item']->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			if ( is_array( $callback['function'] ) && is_object( $callback['function'][0] ) && $callback['function'][0] instanceof SPC_WHCC_Add_to_Cart ) {
				return $callback['function'][0];
			}
		}
	}
	return null;
};

// Things the add-on remembers for the rest of a page load. In real life each add to cart
// is its own page load, so they are cleared after each one and between tests.
$whcc_forget = function () use ( $whcc_add_to_cart_object ) {
	$adder = $whcc_add_to_cart_object();
	if ( $adder ) {
		foreach ( array( 'line_price', 'image_url', 'qty' ) as $property ) {
			$reflection = new ReflectionProperty( $adder, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $adder, null );
		}
	}
	if ( class_exists( 'SPC_WHCC_Shipping' ) ) {
		$cache = new ReflectionProperty( 'SPC_WHCC_Shipping', 'product_category_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
	}
	SPC()->session->set( 'whcc_cart_split', '' );
	SPC()->session->set( 'whcc_cart_split_failed', '' );
	SPC()->session->set( 'whcc_editor_ids', '' );
	if ( class_exists( 'SPC_WHCC_Shipping' ) && property_exists( 'SPC_WHCC_Shipping', 'split_lookups' ) ) {
		$lookups = new ReflectionProperty( 'SPC_WHCC_Shipping', 'split_lookups' );
		$lookups->setAccessible( true );
		$lookups->setValue( null, array() );
	}
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
 * at their defaults, and the add-on's per-page-load memory is cleared before and after.
 */
$whcc_test = function ( $name, $callback, $with_video_sales = false ) use ( $t, &$whcc_lab, $whcc_lab_reset, $whcc_http, $whcc_products_transient, $whcc_forget, $whcc_hook_ids, $whcc_hook_trim ) {
	$t::test(
		'WHCC: ' . $name,
		function () use ( $t, &$whcc_lab, $whcc_lab_reset, $whcc_http, $whcc_products_transient, $whcc_forget, $whcc_hook_ids, $whcc_hook_trim, $callback, $with_video_sales ) {
			$hooks_before = $whcc_hook_ids();
			$t::require_plugin( 'sunshine_whcc_init', 'WHCC add-on' );
			if ( ! class_exists( 'SPC_WHCC_Shipping' ) || ! function_exists( 'sunshine_whcc_update_cart' ) ) {
				$t::skip( 'WHCC add-on did not load' );
			}
			$whcc_lab_reset();

			// WHCC settings at their defaults. A made-up API key, so the real one is never used.
			$t::option( 'whcc_environment', 'sandbox' );
			$t::option( 'whcc_sandbox_api_key', 'spc-totals-offline-key' );
			$t::option( 'whcc_production_api_key', 'spc-totals-offline-key' );
			$t::option( 'whcc_shipping_prices', array() );
			$t::option( 'whcc_require_signature', 'no' );
			$t::option( 'whcc_signature_threshold', '' );
			$t::option( 'whcc_mixed_cart_surcharge', '' );
			$t::option( 'whcc_show_shipping_packages', 'no' );
			$t::option( 'whcc_send_customer_emails', 'no' );

			add_filter( 'pre_http_request', $whcc_http, PHP_INT_MAX, 3 );
			add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
			add_filter( 'pre_transient_sunshine_whcc_products', $whcc_products_transient, PHP_INT_MAX );

			// Analytics and the automated email queue write to shared tables on add to cart.
			// Neither touches the money math, so they are switched off while a test runs.
			$quiet = array();
			$hooks = array(
				'sunshine_add_cart_item' => array( 'sunshine_adv_reports_add_to_cart', 'sunshine_email_queue_after_cart' ),
				'sunshine_cart_update'   => array( 'sunshine_adv_reports_cart_update' ),
			);
			// With the Video Sales add-on on, finding the product for a WHCC editor matches the
			// wrong product (one test below shows it). Every other test leaves that filter off
			// so it checks WHCC's own math.
			if ( ! $with_video_sales ) {
				$hooks['sunshine_get_product_args'] = array( 'sunshine_video_sales_filter_product_args' );
			}
			foreach ( $hooks as $hook => $callbacks ) {
				foreach ( $callbacks as $hook_callback ) {
					$priority = has_action( $hook, $hook_callback );
					if ( false !== $priority ) {
						remove_action( $hook, $hook_callback, $priority );
						$quiet[] = array( $hook, $hook_callback, $priority );
					}
				}
			}

			// The WHCC rate limit counts visitors by IP address and saves the count. WP-CLI
			// reports 127.0.0.1, so leave the address out while testing.
			$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
			unset( $_SERVER['REMOTE_ADDR'] );

			$whcc_forget();

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
				remove_filter( 'pre_http_request', $whcc_http, PHP_INT_MAX );
				remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
				remove_filter( 'pre_transient_sunshine_whcc_products', $whcc_products_transient, PHP_INT_MAX );
				foreach ( $quiet as $hook ) {
					add_action( $hook[0], $hook[1], $hook[2] );
				}
				if ( null !== $remote_addr ) {
					$_SERVER['REMOTE_ADDR'] = $remote_addr;
				}
				unset( $_GET['whcc_editor_id'], $_GET['whcc_updated_item'], $_POST['security'], $_POST['design_id'], $_POST['product_id'], $_POST['image_id'] );
				$whcc_forget();
				$whcc_hook_trim( $hooks_before );
			}

			$t::same( array(), $warnings, 'no PHP warnings or notices' );
			$t::same( array(), $whcc_lab['blocked'], 'no request went anywhere the made-up WHCC does not answer' );
		}
	);
};

/**
 * A WHCC product, saved the way the product edit screen saves it: type "whcc", the chosen
 * WHCC product, a markup percent per price level, and a price of 0 for every price level that
 * has a markup ('' where it has none), because WHCC sets the real price.
 * $markups is price level id => markup percent as typed.
 */
$whcc_product = function ( $type, $markups = null, $taxable = true ) use ( $t, $whcc_run, &$whcc_counter, &$whcc_catalog, $whcc_categories, $whcc_forget ) {
	$whcc_counter++;
	$whcc_id = $whcc_run . $type . $whcc_counter;
	$whcc_catalog[ $whcc_id ] = array(
		'_id'      => $whcc_id,
		'name'     => $whcc_categories[ $type ][0],
		'category' => array( 'name' => $whcc_categories[ $type ][1] ),
	);
	if ( null === $markups ) {
		$markups = array( sunshine_get_default_price_level_id() => '50' );
	}

	$id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-product',
			'post_status' => 'publish',
			'post_title'  => 'WHCC ' . $whcc_categories[ $type ][0],
		)
	);
	$t::track( 'post', $id );

	$saved_markups = array();
	$prices        = array();
	$shipping      = array();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		$level_id                   = $level->get_id();
		$saved_markups[ $level_id ] = isset( $markups[ $level_id ] ) ? (string) $markups[ $level_id ] : '';
		$prices[ $level_id ]        = ( '' !== $saved_markups[ $level_id ] ) ? 0 : '';
		$shipping[ $level_id ]      = '';
	}
	update_post_meta( $id, 'type', 'whcc' );
	update_post_meta( $id, 'whcc_product', $whcc_id );
	update_post_meta( $id, 'whcc_markup', $saved_markups );
	update_post_meta( $id, 'price', $prices );
	update_post_meta( $id, 'taxable', $taxable ? '1' : '' );
	update_post_meta( $id, 'disable_shipping', '' );
	update_post_meta( $id, 'shipping', $shipping );
	update_post_meta( $id, 'min_qty', '' );
	update_post_meta( $id, 'max_qty', '' );
	if ( in_array( $type, array( 'card', 'album', 'book' ), true ) ) {
		update_post_meta( $id, 'has_whcc_designs', true );
	}

	$whcc_forget();
	return $id;
};

/**
 * A finished WHCC editor: what the customer built and what WHCC charges for it.
 * $unit is WHCC's price for one item after the markup, $base is WHCC's cost for one item.
 * $extra can set 'lines' (quantity => exact line price WHCC quotes) and 'photo' (image id).
 */
$whcc_editor = function ( $product_id, $qty, $unit, $base, $extra = array() ) use ( &$whcc_lab, $whcc_run, $whcc_categories ) {
	$whcc_id = get_post_meta( $product_id, 'whcc_product', true );
	$type    = 'print';
	foreach ( array_keys( $whcc_categories ) as $candidate ) {
		if ( false !== strpos( substr( $whcc_id, strlen( $whcc_run ) ), $candidate ) ) {
			$type = $candidate;
			break;
		}
	}
	$editor_id                         = 'spced' . wp_rand( 1000000, 9999999 ) . count( $whcc_lab['editors'] );
	$whcc_lab['editors'][ $editor_id ] = array_merge(
		array(
			'whcc_id' => $whcc_id,
			'type'    => $type,
			'qty'     => $qty,
			'unit'    => $unit,
			'base'    => $base,
			'lines'   => array(),
			'photo'   => 0,
			'summary' => array(
				array(
					'name'        => 'size',
					'description' => '8x10',
				),
			),
		),
		$extra
	);
	return $editor_id;
};

/**
 * The customer finishes the editor and WHCC sends them back with ?whcc_editor_id=..., which
 * runs the add-on's real add to cart. Returns the new cart line, or null if it was not added.
 */
$whcc_add_from_editor = function ( $editor_id ) use ( $whcc_add_to_cart_object, $whcc_forget ) {
	$adder = $whcc_add_to_cart_object();
	if ( ! $adder ) {
		throw new Exception( 'The WHCC add to cart step is not hooked' );
	}
	// The editor was opened by this visitor.
	sunshine_whcc_register_session_editor( $editor_id );
	$_GET['whcc_editor_id'] = $editor_id;
	// It ends by redirecting; stop there instead of exiting (ahead of WP-CLI's redirect warning).
	$redirect = function () {
		throw new Exception( 'spc-whcc-redirect' );
	};
	add_filter( 'wp_redirect', $redirect, -1000 );
	try {
		$adder->add_to_cart();
	} catch ( Exception $e ) {
		if ( 'spc-whcc-redirect' !== $e->getMessage() ) {
			throw $e;
		}
	} finally {
		remove_filter( 'wp_redirect', $redirect, -1000 );
		unset( $_GET['whcc_editor_id'] );
		// The next add to cart is a new page load in real life.
		$adder_price = new ReflectionProperty( $adder, 'line_price' );
		$adder_price->setAccessible( true );
		$adder_price->setValue( $adder, null );
	}
	foreach ( (array) SPC()->cart->get_cart() as $item ) {
		if ( ! empty( $item['meta']['whcc_editor_id'] ) && $item['meta']['whcc_editor_id'] === $editor_id ) {
			return $item;
		}
	}
	return null;
};

// The cart line object for a WHCC editor.
$whcc_line = function ( $editor_id ) {
	foreach ( (array) SPC()->cart->get_cart_items() as $line ) {
		if ( $line->get_meta_value( 'whcc_editor_id' ) === $editor_id ) {
			return $line;
		}
	}
	return null;
};

/**
 * The customer presses Update on the cart page, after maybe changing quantities
 * (editor id => new quantity). Same steps as sunshine_update_cart(), then the next page load.
 */
$whcc_update_cart = function ( $quantities = array() ) use ( $t ) {
	foreach ( (array) SPC()->cart->get_cart() as $key => $item ) {
		$editor_id = isset( $item['meta']['whcc_editor_id'] ) ? $item['meta']['whcc_editor_id'] : '';
		if ( $editor_id && isset( $quantities[ $editor_id ] ) ) {
			SPC()->cart->update_item_quantity( $key, $quantities[ $editor_id ] );
		}
	}
	do_action( 'sunshine_cart_update' );
	return $t::new_request();
};

/**
 * Offer WHCC shipping methods the way Delivery & Shipping saves them, one instance each.
 * $extra adds other methods: instance => array( 'id' => ..., settings... ).
 */
$whcc_ship_setup = function ( $methods = array( 'lowest_cost', 'expedited', 'one_day_standard', 'one_day_priority' ), $taxable = false, $extra = array() ) use ( $t ) {
	$list = array();
	foreach ( $methods as $key ) {
		$instance          = 'spcwhcc' . $key;
		$list[ $instance ] = array(
			'id'     => 'whcc_' . $key,
			'active' => 1,
		);
		$t::option( 'whcc_' . $key . '_taxable_' . $instance, $taxable ? 1 : '' );
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

// The customer picks "Ship" and a WHCC method, to an address (US, Colorado unless given).
$whcc_ship_with = function ( $method_key, $state = 'CO', $address1 = '1 Main St', $country = 'US' ) use ( $t ) {
	$t::checkout_address();
	SPC()->cart->set_checkout_data_item( 'shipping_state', $state );
	SPC()->cart->set_checkout_data_item( 'shipping_address1', $address1 );
	SPC()->cart->set_checkout_data_item( 'shipping_country', $country );
	SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
	SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcwhcc' . $method_key );
};

// Which WHCC methods the checkout offers right now (method keys, in order).
$whcc_offered = function () {
	$keys = array();
	foreach ( array_keys( sunshine_get_allowed_shipping_methods() ) as $instance ) {
		$keys[] = 0 === strpos( $instance, 'spcwhcc' ) ? substr( $instance, 7 ) : $instance;
	}
	return $keys;
};

// The price a customer pays for shipping one package, and what WHCC charges for it.
$whcc_price_sheet = function ( $method, $group, $state = '', $signature = false ) {
	return array(
		'customer' => SPC_WHCC_Shipping::calculate_customer_price( $method, $group, $state, $signature ),
		'lab'      => SPC_WHCC_Shipping::calculate_lab_cost( $method, $group, $state, $signature ),
	);
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

// Run an AJAX handler the way admin-ajax.php does and return its JSON reply.
$whcc_ajax = function ( $handler ) {
	$die = function () {
		return function () {
			throw new Exception( 'spc-whcc-ajax-done' );
		};
	};
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $die );
	$error = null;
	ob_start();
	try {
		$handler();
	} catch ( Exception $e ) {
		if ( 'spc-whcc-ajax-done' !== $e->getMessage() ) {
			$error = $e;
		}
	} finally {
		$output = ob_get_clean();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $die );
	}
	if ( $error ) {
		throw $error;
	}
	return json_decode( $output, true );
};

// The requests the add-on sent to the made-up WHCC for one endpoint.
$whcc_sent = function ( $method, $endpoint ) use ( &$whcc_lab ) {
	$found = array();
	foreach ( $whcc_lab['requests'] as $request ) {
		if ( $request['method'] === $method && $request['endpoint'] === $endpoint ) {
			$found[] = $request['body'];
		}
	}
	return $found;
};

// What the cart shows right before the customer places the order.
$whcc_cart_snapshot = function () {
	$cart  = SPC()->cart;
	$lines = array();
	foreach ( (array) $cart->get_cart_items() as $line ) {
		$lines[] = array(
			'total'    => (float) $line->get_total(),
			'tax'      => (float) $line->get_tax_total(),
			'discount' => (float) $line->get_discount_total(),
		);
	}
	return array(
		'subtotal' => (float) $cart->get_subtotal(),
		'discount' => (float) $cart->get_discount(),
		'shipping' => (float) $cart->get_shipping(),
		'tax'      => (float) $cart->get_tax(),
		'total'    => (float) $cart->get_total(),
		'lines'    => $lines,
	);
};

$whcc_order_matches_cart = function ( $order, $seen ) use ( $t ) {
	$t::money( $seen['subtotal'], $order->get_subtotal(), 'order subtotal' );
	$t::money( $seen['discount'], $order->get_discount(), 'order discount' );
	$t::money( $seen['shipping'], $order->get_shipping(), 'order shipping' );
	$t::money( $seen['tax'], $order->get_tax(), 'order tax' );
	$t::money( $seen['total'], $order->get_total(), 'order total' );
	$items = array_values( (array) $order->get_items() );
	$t::same( count( $seen['lines'] ), count( $items ), 'number of order lines' );
	foreach ( $seen['lines'] as $i => $line ) {
		if ( empty( $items[ $i ] ) ) {
			continue;
		}
		$t::money( $line['total'], $items[ $i ]->get_total(), 'line ' . ( $i + 1 ) . ' total' );
		$t::money( $line['tax'], $items[ $i ]->get_tax_total(), 'line ' . ( $i + 1 ) . ' tax' );
		$t::money( $line['discount'], $items[ $i ]->get_discount_total(), 'line ' . ( $i + 1 ) . ' discount' );
	}
};
