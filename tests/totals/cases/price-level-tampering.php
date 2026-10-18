<?php
/**
 * Can a customer get a cheaper price level by changing the request?
 *
 * Products with no photo (Sell Anything products, session fees, gift cards) take their price
 * level from the request, because the page the customer came from picked it (a shortcode
 * attribute). Products for a photo take the level of the photo or its gallery. These tests send
 * the same requests the store's pages send, but with the price level (or gallery) changed, the
 * way a customer could by editing the request in their browser.
 *
 * The business rule used here: a customer pays the price they were shown. A price level that
 * is only used by a private gallery they can't open (a "VIP" level) must not be reachable by
 * typing its number into a request. Where the store refuses the request instead of falling back
 * to the public price, that is fine too; only the private price is wrong.
 */

$t = 'SPC_Totals_Test';

// PHP warnings, notices and deprecations raised by the store code these tests call.
$plt_warnings = array();
// Outgoing web requests that were stopped while placing orders.
$plt_blocked = array();

/**
 * A new price level. Named to sort after the store's own levels, because the store treats the
 * first level by name as its default and other tests rely on that.
 */
$plt_level = function ( $label ) use ( $t ) {
	// A price level created while these are posted copies prices onto every product in the store.
	$saved_post = $_POST;
	unset( $_POST['price_level'], $_POST['percent'] );
	$term  = wp_insert_term( 'Zz Tamper ' . $label . ' ' . wp_rand( 100000, 999999 ), 'sunshine-product-price-level' );
	$_POST = $saved_post;
	if ( is_wp_error( $term ) ) {
		throw new Exception( 'Could not create a price level: ' . $term->get_error_message() );
	}
	$t::track( 'term', $term['term_id'], 'sunshine-product-price-level' );
	return (int) $term['term_id'];
};

/**
 * A product saved the way the product edit screen saves it: a price for every price level
 * (empty where it is not sold), a shipping amount for every level, and the other fields.
 * $prices is level id => price. Create price levels before the product, like the admin does.
 */
$plt_product = function ( $type, $prices, $fields = array(), $name = 'Tampering test product' ) use ( $t ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-product',
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);
	if ( ! $id || is_wp_error( $id ) ) {
		throw new Exception( 'Could not make a product' );
	}
	$t::track( 'post', $id );
	$all_prices   = array();
	$all_shipping = array();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		if ( $level ) {
			$all_prices[ $level->get_id() ]   = isset( $prices[ $level->get_id() ] ) ? (string) $prices[ $level->get_id() ] : '';
			$all_shipping[ $level->get_id() ] = '';
		}
	}
	// Keys that are not price levels (a deleted level) are kept as given.
	foreach ( $prices as $level_id => $price ) {
		if ( ! isset( $all_prices[ $level_id ] ) ) {
			$all_prices[ $level_id ] = (string) $price;
		}
	}
	$meta = array(
		'type'             => $type,
		'price'            => $all_prices,
		'taxable'          => '',
		'disable_shipping' => '',
		'shipping'         => $all_shipping,
		'min_qty'          => '',
		'max_qty'          => '',
	);
	if ( 'general' === $type ) {
		// The Stock box the Sell Anything add-on adds, left blank for unlimited.
		$meta['stock'] = '';
	}
	foreach ( array_merge( $meta, $fields ) as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
};

/**
 * A published gallery saved the way the gallery edit screen saves it. $status is 'default',
 * 'password' or 'private'. $extra sets more fields, e.g. private_users or password.
 */
$plt_gallery = function ( $price_level = '', $status = 'default', $extra = array() ) use ( $t ) {
	$t::option( 'proofing', '' );
	$gallery_id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-gallery',
			'post_status' => 'publish',
			'post_title'  => 'Tampering test gallery',
		)
	);
	if ( ! $gallery_id || is_wp_error( $gallery_id ) ) {
		throw new Exception( 'Could not create a gallery' );
	}
	$t::track( 'post', $gallery_id );
	$meta = array_merge(
		array(
			'status'           => $status,
			'access_type'      => '',
			'disable_products' => '',
			'price_level'      => (string) $price_level,
		),
		$extra
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $gallery_id, $key, $value );
	}
	return $gallery_id;
};

/**
 * A photo in a gallery, like an upload. $price_level is the per-photo price level from the
 * media edit screen, which saves intval() of the choice.
 */
$plt_photo = function ( $gallery_id, $price_level = null ) use ( $t ) {
	$name     = 'IMG_' . wp_rand( 1000, 9999 );
	$image_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $name,
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		'',
		$gallery_id
	);
	if ( ! $image_id || is_wp_error( $image_id ) ) {
		throw new Exception( 'Could not create a photo' );
	}
	$t::track( 'post', $image_id );
	add_post_meta( $image_id, 'sunshine_file_name', $name . '.jpg' );
	add_post_meta( $image_id, 'sunshine_watermark', 0 );
	if ( null !== $price_level ) {
		update_post_meta( $image_id, 'sunshine_price_level', intval( $price_level ) );
	}
	sunshine_get_gallery( $gallery_id )->add_image_id( $image_id );
	return $image_id;
};

/**
 * The photographer's VIP setup: a cheap price level used only by a private gallery for one
 * client. The customer in the tests is someone else, so they were never shown these prices.
 */
$plt_vip = function () use ( $t, $plt_level, $plt_gallery, $plt_photo ) {
	$level  = $plt_level( 'vip' );
	$client = wp_insert_user(
		array(
			'user_login' => 'spctamper' . wp_rand( 100000, 999999 ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'sunshine_customer',
		)
	);
	if ( is_wp_error( $client ) ) {
		throw new Exception( 'Could not make the VIP client' );
	}
	$t::track( 'user', $client );
	$gallery = $plt_gallery( $level, 'private', array( 'private_users' => array( (int) $client ) ) );
	$photo   = $plt_photo( $gallery );
	return array(
		'level'   => $level,
		'gallery' => $gallery,
		'photo'   => $photo,
	);
};

/**
 * Run some store code and keep any PHP warnings it raises, then pass them on as usual.
 */
$plt_watch = function ( $callback ) use ( &$plt_warnings ) {
	set_error_handler(
		function ( $errno, $errstr, $errfile, $errline ) use ( &$plt_warnings ) {
			if ( error_reporting() & $errno ) {
				$plt_warnings[] = $errstr . ' (' . basename( $errfile ) . ':' . $errline . ')';
			}
			return false;
		}
	);
	try {
		return $callback();
	} finally {
		restore_error_handler();
	}
};

/**
 * Send an ajax request the way admin-ajax.php does: every handler hooked to the action runs, in
 * order, until one sends its JSON reply. The store's nonce is added unless the post has one.
 */
$plt_ajax = function ( $action, $post ) use ( $plt_watch ) {
	$saved_post = $_POST;
	$_POST      = $post;
	if ( ! isset( $_POST['security'] ) ) {
		$_POST['security'] = wp_create_nonce( 'sunshinephotocart' );
	}
	$_POST['action'] = $action;
	$ajax            = '__return_true';
	$die             = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'wp_doing_ajax', $ajax );
	add_filter( 'wp_die_ajax_handler', $die );
	$output = '';
	ob_start();
	try {
		$plt_watch(
			function () use ( $action ) {
				do_action( ( is_user_logged_in() ? 'wp_ajax_' : 'wp_ajax_nopriv_' ) . $action );
			}
		);
	} catch ( SPC_Totals_Test_Done $e ) {
		// Every handler ends by sending JSON.
	} finally {
		$output = ob_get_clean();
		$_POST  = $saved_post;
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );
	}
	$response = json_decode( $output, true );
	if ( ! is_array( $response ) ) {
		$response = array(
			'success' => false,
			'raw'     => substr( $output, 0, 300 ),
		);
	}
	return $response;
};

/**
 * Run a page handler that ends by redirecting, and stop at the redirect instead of exiting.
 * Returns where it would have sent the browser ('' when it did not redirect).
 */
$plt_redirected = function ( $callback ) use ( $plt_watch ) {
	$stop = function ( $location ) {
		throw new Exception( 'plt-redirect:' . $location );
	};
	add_filter( 'wp_redirect', $stop, -1000 );
	$location = '';
	try {
		$plt_watch( $callback );
	} catch ( Exception $e ) {
		if ( 0 !== strpos( $e->getMessage(), 'plt-redirect:' ) ) {
			throw $e;
		}
		$location = substr( $e->getMessage(), strlen( 'plt-redirect:' ) );
	} finally {
		remove_filter( 'wp_redirect', $stop, -1000 );
	}
	return $location;
};

/**
 * Open an add to cart link, like the session fees "Buy now" button:
 * ?sunshine_action=add_to_cart&product_id=..&price_level=..
 */
$plt_link = function ( $get ) use ( $plt_redirected ) {
	$saved_get = $_GET;
	$had_uri   = isset( $_SERVER['REQUEST_URI'] );
	$saved_uri = $had_uri ? $_SERVER['REQUEST_URI'] : null;
	$_GET      = array_merge( array( 'sunshine_action' => 'add_to_cart' ), $get );
	$_SERVER['REQUEST_URI'] = '/checkout/?' . http_build_query( $_GET );
	try {
		return $plt_redirected( 'sunshine_add_to_cart_url' );
	} finally {
		$_GET = $saved_get;
		if ( $had_uri ) {
			$_SERVER['REQUEST_URI'] = $saved_uri;
		} else {
			unset( $_SERVER['REQUEST_URI'] );
		}
	}
};

/**
 * Send the booking form on a session fee page. The form has a hidden price_level field.
 */
$plt_book_session = function ( $post ) use ( $plt_redirected ) {
	$saved_post = $_POST;
	$_POST      = array_merge( array( 'sunshine_add_session' => wp_create_nonce( 'sunshine_add_session' ) ), $post );
	try {
		return $plt_redirected( 'sunshine_session_fees_add_to_cart' );
	} finally {
		$_POST = $saved_post;
	}
};

/**
 * Place the order with every outgoing web request blocked (order emails, license checks and
 * the like never leave the test site). Returns the saved order.
 */
$plt_place_order = function () use ( $t, &$plt_blocked, $plt_watch ) {
	$block = function ( $pre, $args, $url ) use ( &$plt_blocked ) {
		$plt_blocked[] = $url;
		return new WP_Error( 'plt_blocked', 'Outgoing requests are blocked in the totals tests' );
	};
	add_filter( 'pre_http_request', $block, PHP_INT_MAX, 3 );
	try {
		return $plt_watch( array( $t, 'place_order' ) );
	} finally {
		remove_filter( 'pre_http_request', $block, PHP_INT_MAX );
	}
};

$plt_lines = function () {
	$items = SPC()->cart->get_cart_items();
	return $items ? array_values( $items ) : array();
};

/**
 * After a changed request: the customer pays the public price, or the store refused the item.
 * Either is fine. The private price is not.
 */
$plt_public_or_refused = function ( $public_total, $private_level, $label ) use ( $t, $plt_lines ) {
	$cart = $t::calculate();
	if ( $cart->is_empty() ) {
		$t::same( true, true, $label . ': refused' );
		return false;
	}
	$t::money( $public_total, $cart->get_total(), $label . ': total at the public price' );
	foreach ( $plt_lines() as $i => $line ) {
		$data = $line->get_item_data();
		$t::same( false, (int) $private_level === (int) $data['price_level'], $label . ': line ' . ( $i + 1 ) . ' is at the private price level' );
	}
	return true;
};

/**
 * A discount code saved the way the Discounts screen saves it (every field, empty ones as ''),
 * then entered at checkout.
 */
$plt_code = function ( $meta ) use ( $t ) {
	$t::require_plugin( 'sunshine_get_discount_by_code', 'Discount Codes add-on' );
	$code = 'SPCTAMPER' . wp_rand( 100000, 999999 );
	$id   = wp_insert_post(
		array(
			'post_type'   => 'sunshine-discount',
			'post_status' => 'publish',
			'post_title'  => $code,
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
			'max_uses_per_person'   => '',
			'max_product_quantity'  => '',
		),
		$meta
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	$codes   = array_filter( (array) SPC()->session->get( 'discounts' ) );
	$codes[] = $code;
	SPC()->session->set( 'discounts', array_values( $codes ) );
	return $code;
};

/**
 * The data-* attributes of the first button in some HTML that matches $pattern, named the way
 * the store's script sends them: jQuery-style (productId) or posted (product_id).
 */
$plt_button_data = function ( $html, $pattern, $style ) {
	if ( ! preg_match( $pattern, (string) $html, $match ) ) {
		return array();
	}
	preg_match_all( '/data-([a-z0-9-]+)="([^"]*)"/', $match[0], $attrs, PREG_SET_ORDER );
	$data = array();
	foreach ( $attrs as $attr ) {
		$parts = explode( '-', $attr[1] );
		if ( 'camel' === $style ) {
			$key = array_shift( $parts ) . implode( '', array_map( 'ucfirst', $parts ) );
		} else {
			$key = implode( '_', $parts );
		}
		$data[ $key ] = html_entity_decode( $attr[2] );
	}
	return $data;
};

/*
 * Products with no photo: the price level comes from the request
 */

$t::test(
	'Price level tampering: a Sell Anything product can\'t be bought at a private level by changing the request',
	function () use ( $t, $plt_vip, $plt_product, $plt_ajax, $plt_public_or_refused ) {
		$t::require_plugin( 'sunshine_sell_anything_shortcode', 'Sell Anything add-on' );
		$vip     = $plt_vip();
		$default = sunshine_get_default_price_level_id();
		$product = $plt_product( 'general', array( $default => '20', $vip['level'] => '5' ), array(), 'Tampering test mug' );

		// The public store page sends the default level. The customer changes it to the VIP one.
		$plt_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'price_level' => $vip['level'],
				'qty'         => 2,
			)
		);
		// 2 x 20 public price = 40. The VIP price would be 2 x 5 = 10.
		$plt_public_or_refused( 40, $vip['level'], 'mug' );
	}
);

$t::test(
	'Price level tampering: a product only sold at a private level can\'t be added from a public page',
	function () use ( $t, $plt_vip, $plt_product, $plt_ajax ) {
		$t::require_plugin( 'sunshine_sell_anything_shortcode', 'Sell Anything add-on' );
		$vip = $plt_vip();
		// Not sold at any public level: only VIP clients may buy it, at 5.
		$product = $plt_product( 'general', array( $vip['level'] => '5' ), array(), 'Tampering test VIP-only album' );

		$response = $plt_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'price_level' => $vip['level'],
				'qty'         => 1,
			)
		);
		$cart = $t::calculate();
		$t::same( false, ! empty( $response['success'] ), 'request refused' );
		$t::same( true, $cart->is_empty(), 'cart is empty' );
		$t::money( 0, $cart->get_total(), 'total' );
	}
);

// The Price Levels screen deletes a level but leaves its old prices saved on each product.
$t::test(
	'Price level tampering: the old prices of a deleted price level can\'t be used',
	function () use ( $t, $plt_level, $plt_product, $plt_ajax, $plt_public_or_refused ) {
		$t::require_plugin( 'sunshine_sell_anything_shortcode', 'Sell Anything add-on' );
		$default = sunshine_get_default_price_level_id();
		$old     = $plt_level( 'last year promo' );
		$product = $plt_product( 'general', array( $default => '20', $old => '1' ), array(), 'Tampering test frame' );
		wp_delete_term( $old, 'sunshine-product-price-level' );
		$t::same( false, (bool) term_exists( $old, 'sunshine-product-price-level' ), 'old level is gone' );

		$plt_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'price_level' => $old,
				'qty'         => 3,
			)
		);
		// 3 x 20 = 60 at the public price. The deleted level's price would make it 3 x 1 = 3.
		$plt_public_or_refused( 60, $old, 'frame' );
	}
);

$t::test(
	'Price level tampering: a made-up price level is refused or charged the public price, never free',
	function () use ( $t, $plt_product, $plt_ajax, $plt_public_or_refused ) {
		$t::require_plugin( 'sunshine_sell_anything_shortcode', 'Sell Anything add-on' );
		$default = sunshine_get_default_price_level_id();
		$product = $plt_product( 'general', array( $default => '20' ), array(), 'Tampering test print box' );

		// The product's own post ID, a negative number, a word and zero instead of a level.
		foreach ( array( $product, -3, 'abc', '0' ) as $made_up ) {
			$t::reset();
			$plt_ajax(
				'sunshine_modal_add_item_to_cart',
				array(
					'product_id'  => $product,
					'price_level' => $made_up,
					'qty'         => 1,
				)
			);
			// 1 x 20 at the public price, or refused.
			$plt_public_or_refused( 20, -999, 'price level ' . var_export( $made_up, true ) );
		}
	}
);

// The money reaches the saved order: prices include tax, a discount code and shipping.
$t::test(
	'Price level tampering: the placed order is at the public price (prices include tax, discount code, shipping)',
	function () use ( $t, $plt_vip, $plt_product, $plt_ajax, $plt_code, $plt_public_or_refused, $plt_place_order ) {
		$t::require_plugin( 'sunshine_sell_anything_shortcode', 'Sell Anything add-on' );
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$vip     = $plt_vip();
		$default = sunshine_get_default_price_level_id();
		$product = $plt_product( 'general', array( $default => '22', $vip['level'] => '11' ), array( 'taxable' => '1' ), 'Tampering test canvas bag' );

		$plt_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'price_level' => $vip['level'],
				'qty'         => 1,
			)
		);
		$plt_code(
			array(
				'discount_type'   => 'percent-total',
				'discount_amount' => '10',
			)
		);
		$t::flat_shipping( 5, false );
		// 22 including 10% tax, 10% off = 2.20 off, 19.80 left, which includes 19.80 / 1.1 = 18
		// plus 1.80 tax. Shipping 5 (not taxed). Total 24.80. At the VIP price it would be 14.90.
		if ( ! $plt_public_or_refused( 24.80, $vip['level'], 'cart' ) ) {
			return;
		}
		$cart = $t::calculate();
		$t::money( 2.20, $cart->get_discount(), 'discount' );
		$t::money( 1.80, $cart->get_tax(), 'tax included' );
		$order = $plt_place_order();
		$t::money( 24.80, $order->get_total(), 'order total' );
		$t::money( 1.80, $order->get_tax(), 'order tax' );
		$items = array_values( (array) $order->get_items() );
		$t::same( 1, count( $items ), 'one order line' );
		if ( $items ) {
			$t::same( false, (int) $vip['level'] === (int) $items[0]->get_price_level(), 'order line is at the private price level' );
		}
	}
);

// A photographer can put the Sell Anything shortcode with price_level="ID" on a page only VIP
// clients know about. A customer who came from that page must still get its prices.
$t::test(
	'Price level: a Sell Anything page set to a level charges the price that page shows',
	function () use ( $t, $plt_vip, $plt_product, $plt_ajax, $plt_button_data ) {
		$t::require_plugin( 'sunshine_sell_anything_shortcode', 'Sell Anything add-on' );
		$vip     = $plt_vip();
		$default = sunshine_get_default_price_level_id();
		$product = $plt_product( 'general', array( $default => '20', $vip['level'] => '5' ), array(), 'Tampering test VIP tote' );
		$term    = wp_insert_term( 'Tampering test category ' . wp_rand( 100000, 999999 ), 'sunshine-product-category' );
		if ( is_wp_error( $term ) ) {
			throw new Exception( 'Could not make a product category' );
		}
		$t::track( 'term', $term['term_id'], 'sunshine-product-category' );
		wp_set_object_terms( $product, array( (int) $term['term_id'] ), 'sunshine-product-category' );

		// The page, then the "See options" button, then the "Add to cart" button, each sending
		// exactly what the one before it gave the browser.
		$page = do_shortcode( '[sunshine_sell_anything price_level="' . $vip['level'] . '"]' );
		$open = $plt_button_data( $page, '/<button[^>]*sunshine--open-modal[^>]*data-product-id="' . $product . '"[^>]*>/', 'camel' );
		$t::same( (string) $vip['level'], isset( $open['priceLevel'] ) ? $open['priceLevel'] : null, 'page lists the product at its level' );
		if ( empty( $open ) ) {
			return;
		}
		$modal = $plt_ajax( 'sunshine_modal_display', $open );
		$html  = isset( $modal['data']['html'] ) ? $modal['data']['html'] : '';
		$shown = preg_match( '/<div id="sunshine--product--details--price">(.*?)<\/div>/s', $html, $price_html ) ? $price_html[1] : '';
		$text  = function ( $markup ) {
			return trim( html_entity_decode( wp_strip_all_tags( $markup ), ENT_QUOTES ) );
		};
		$t::same( $text( sunshine_price( 5 ) ), $text( $shown ), 'product window shows the VIP price of 5' );
		$add        = $plt_button_data( $html, '/<div id="sunshine--product--details--action">\s*<button[^>]*>/', 'post' );
		$add['qty'] = 2;
		$response   = $plt_ajax( 'sunshine_modal_add_item_to_cart', $add );
		$t::same( true, ! empty( $response['success'] ), 'added' );
		$cart = $t::calculate();
		// 2 x 5 at the page's level.
		$t::money( 10, $cart->get_total(), 'total' );
	}
);

/*
 * The gallery's store: the gallery decides the level
 */

$t::test(
	'Price level: in a gallery\'s store the gallery\'s level wins over the level in the request',
	function () use ( $t, $plt_vip, $plt_product, $plt_gallery, $plt_photo, $plt_ajax, $plt_public_or_refused ) {
		$t::require_plugin( 'sunshine_sell_anything_shortcode', 'Sell Anything add-on' );
		$t::tax( 10 );
		$vip     = $plt_vip();
		$default = sunshine_get_default_price_level_id();
		$product = $plt_product( 'general', array( $default => '20', $vip['level'] => '5' ), array( 'taxable' => '1' ), 'Tampering test keychain' );
		$public  = $plt_gallery( $default );
		$plt_photo( $public );

		$response = $plt_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'gallery_id'  => $public,
				'price_level' => $vip['level'],
				'qty'         => 1,
			)
		);
		$t::same( true, ! empty( $response['success'] ), 'added' );
		// 20 at the gallery's level, plus 10% tax = 22.
		$plt_public_or_refused( 22, $vip['level'], 'keychain' );
	}
);

$t::test(
	'Price level: naming a private or locked gallery in the request does not give its prices',
	function () use ( $t, $plt_vip, $plt_product, $plt_gallery, $plt_photo, $plt_ajax ) {
		$t::require_plugin( 'sunshine_sell_anything_shortcode', 'Sell Anything add-on' );
		$vip     = $plt_vip();
		$default = sunshine_get_default_price_level_id();
		$product = $plt_product( 'general', array( $default => '20', $vip['level'] => '5' ), array(), 'Tampering test magnet' );
		// A password gallery at the VIP level that this customer has not unlocked.
		$locked = $plt_gallery( $vip['level'], 'password', array( 'password' => 'SECRET' . wp_rand( 1000, 9999 ) ) );
		$plt_photo( $locked );

		foreach ( array( 'private gallery' => $vip['gallery'], 'locked gallery' => $locked ) as $label => $gallery_id ) {
			$t::reset();
			// reset() puts the store options back; galleries here are sold, not proofing only.
			$t::option( 'proofing', '' );
			$response = $plt_ajax(
				'sunshine_modal_add_item_to_cart',
				array(
					'product_id' => $product,
					'gallery_id' => $gallery_id,
					'qty'        => 1,
				)
			);
			$cart = $t::calculate();
			$t::same( false, ! empty( $response['success'] ), $label . ': refused' );
			$t::money( 0, $cart->get_total(), $label . ': nothing in the cart' );
		}
	}
);

/*
 * Products for a photo: the photo (or its gallery) decides the level
 */

$t::test(
	'Price level: a photo\'s gallery level wins over the level in the request',
	function () use ( $t, $plt_vip, $plt_product, $plt_gallery, $plt_photo, $plt_ajax, $plt_public_or_refused ) {
		$t::tax( 10 );
		$vip     = $plt_vip();
		$default = sunshine_get_default_price_level_id();
		$print   = $plt_product( 'print', array( $default => '20', $vip['level'] => '5' ), array( 'taxable' => '1' ), 'Tampering test 8x10' );
		$gallery = $plt_gallery( $default );
		$photo   = $plt_photo( $gallery );

		$response = $plt_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $print,
				'image_id'    => $photo,
				'gallery_id'  => $gallery,
				'price_level' => $vip['level'],
				'qty'         => 3,
			)
		);
		$t::same( true, ! empty( $response['success'] ), 'added' );
		// 3 x 20 = 60, 10% tax = 6, total 66.
		$plt_public_or_refused( 66, $vip['level'], '8x10' );
	}
);

// The gallery's level holds all the way to the saved order, with the other money parts on.
$t::test(
	'Price level: a photo ordered with a changed request is charged its gallery price (discount after tax, shipping, gift card)',
	function () use ( $t, $plt_vip, $plt_product, $plt_gallery, $plt_photo, $plt_ajax, $plt_code, $plt_place_order ) {
		$t::require_plugin( 'sunshine_get_gift_card_by_code', 'Gift Cards add-on' );
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$vip     = $plt_vip();
		$default = sunshine_get_default_price_level_id();
		$print   = $plt_product( 'print', array( $default => '20', $vip['level'] => '5' ), array( 'taxable' => '1' ), 'Tampering test 8x12' );
		$gallery = $plt_gallery( $default );
		$photo   = $plt_photo( $gallery );

		$plt_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $print,
				'image_id'    => $photo,
				'gallery_id'  => $gallery,
				'price_level' => $vip['level'],
				'qty'         => 2,
			)
		);
		$plt_code(
			array(
				'discount_type'   => 'percent-total',
				'discount_amount' => '10',
			)
		);
		$t::flat_shipping( 6, false );
		$t::gift_card( 20 );
		$cart = $t::calculate();
		// 2 x 20 = 40, tax 4. 10% off after tax = 4.40. Shipping 6. 40 + 4 - 4.40 + 6 = 45.60,
		// and the 20 gift card leaves 25.60 to pay.
		$t::money( 40, $cart->get_subtotal(), 'subtotal' );
		$t::money( 4, $cart->get_tax(), 'tax' );
		$t::money( 4.40, $cart->get_discount(), 'discount' );
		$t::money( 25.60, $cart->get_total(), 'left to pay' );
		$order = $plt_place_order();
		$t::money( 40, $order->get_subtotal(), 'order subtotal' );
		$t::money( 4, $order->get_tax(), 'order tax' );
		$t::money( 4.40, $order->get_discount(), 'order discount' );
		$t::money( 25.60, $order->get_total(), 'order total' );
		$items = array_values( (array) $order->get_items() );
		if ( $items ) {
			$t::same( (int) $default, (int) $items[0]->get_price_level(), 'order line is at the gallery\'s level' );
			$t::money( 20, $items[0]->get_price(), 'order line price' );
		}
	}
);

$t::test(
	'Price level: a photo keeps its own gallery when the request names a private VIP gallery',
	function () use ( $t, $plt_vip, $plt_product, $plt_gallery, $plt_photo, $plt_ajax, $plt_public_or_refused, $plt_lines ) {
		$vip     = $plt_vip();
		$default = sunshine_get_default_price_level_id();
		$print   = $plt_product( 'print', array( $default => '20', $vip['level'] => '5' ), array(), 'Tampering test 5x7' );
		$gallery = $plt_gallery( $default );
		$photo   = $plt_photo( $gallery );

		$plt_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $print,
				'image_id'    => $photo,
				'gallery_id'  => $vip['gallery'],
				'price_level' => $vip['level'],
				'qty'         => 1,
			)
		);
		// 1 x 20 at the photo's gallery level.
		$plt_public_or_refused( 20, $vip['level'], '5x7' );
		$lines = $plt_lines();
		if ( $lines ) {
			$t::same( (int) $gallery, (int) $lines[0]->get_gallery_id(), 'line belongs to the photo\'s own gallery' );
		}
	}
);

$t::test(
	'Price level: a photo with its own price level keeps it when the request names a cheaper gallery',
	function () use ( $t, $plt_level, $plt_product, $plt_gallery, $plt_photo, $plt_ajax ) {
		$default = sunshine_get_default_price_level_id();
		$fine    = $plt_level( 'fine art' );
		$mini    = $plt_level( 'mini session' );
		$print   = $plt_product( 'print', array( $default => '20', $fine => '30', $mini => '5' ), array(), 'Tampering test 11x14' );
		$gallery = $plt_gallery( $default );
		// The photographer set this one photo to the fine art level.
		$photo = $plt_photo( $gallery, $fine );
		// A public mini session gallery the customer can open, with a cheap level.
		$cheap = $plt_gallery( $mini );
		$plt_photo( $cheap );

		$plt_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $print,
				'image_id'    => $photo,
				'gallery_id'  => $cheap,
				'price_level' => $mini,
				'qty'         => 2,
			)
		);
		$cart = $t::calculate();
		// 2 x 30 at the photo's own level.
		$t::money( 60, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Price level: photos picked in a gallery\'s store each use their own level, not the request\'s',
	function () use ( $t, $plt_level, $plt_product, $plt_gallery, $plt_photo, $plt_ajax ) {
		$default = sunshine_get_default_price_level_id();
		$mini    = $plt_level( 'mini session' );
		$print   = $plt_product( 'print', array( $default => '20', $mini => '5' ), array(), 'Tampering test 4x6' );
		$wedding = $plt_gallery( $default );
		$wedding_photo = $plt_photo( $wedding );
		$cheap         = $plt_gallery( $mini );
		$cheap_photo   = $plt_photo( $cheap );

		// The store of the cheap gallery, with a photo from the wedding gallery picked too.
		$response = $plt_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $print,
				'gallery_id'  => $cheap,
				'price_level' => $mini,
				'qty'         => 2,
				'options'     => array( 'images' => $wedding_photo . ',' . $cheap_photo ),
			)
		);
		$t::same( true, ! empty( $response['success'] ), 'added' );
		$cart = $t::calculate();
		// 2 x 20 for the wedding photo + 2 x 5 for the mini session photo = 50.
		$t::money( 50, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Price level: a photo from a private or locked gallery can\'t be added, whatever the request says',
	function () use ( $t, $plt_vip, $plt_product, $plt_gallery, $plt_photo, $plt_ajax ) {
		$vip     = $plt_vip();
		$default = sunshine_get_default_price_level_id();
		$print   = $plt_product( 'print', array( $default => '20', $vip['level'] => '5' ), array(), 'Tampering test wallet prints' );
		$locked  = $plt_gallery( $vip['level'], 'password', array( 'password' => 'SECRET' . wp_rand( 1000, 9999 ) ) );
		$locked_photo = $plt_photo( $locked );
		$public       = $plt_gallery( $default );

		foreach ( array( 'private gallery photo' => $vip['photo'], 'locked gallery photo' => $locked_photo ) as $label => $photo ) {
			$t::reset();
			// reset() puts the store options back; galleries here are sold, not proofing only.
			$t::option( 'proofing', '' );
			$response = $plt_ajax(
				'sunshine_modal_add_item_to_cart',
				array(
					'product_id'  => $print,
					'image_id'    => $photo,
					'gallery_id'  => $public,
					'price_level' => $vip['level'],
					'qty'         => 1,
				)
			);
			$cart = $t::calculate();
			$t::same( false, ! empty( $response['success'] ), $label . ': refused' );
			$t::money( 0, $cart->get_total(), $label . ': nothing in the cart' );
		}
	}
);

$t::test(
	'Price level: adding favorites ignores a level in the request',
	function () use ( $t, $plt_vip, $plt_product, $plt_gallery, $plt_photo, $plt_ajax ) {
		$vip     = $plt_vip();
		$default = sunshine_get_default_price_level_id();
		$print   = $plt_product( 'print', array( $default => '20', $vip['level'] => '5' ), array(), 'Tampering test 8x8' );
		$gallery = $plt_gallery( $default );
		$a       = $plt_photo( $gallery );
		$b       = $plt_photo( $gallery );

		$plt_ajax(
			'sunshine_modal_add_favorites_to_cart',
			array(
				'product_id'  => $print,
				'qty'         => 1,
				'imageIds'    => $a . ',' . $b,
				'price_level' => $vip['level'],
			)
		);
		$cart = $t::calculate();
		// 2 photos x 20.
		$t::money( 40, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Full gallery download: naming a private VIP gallery does not add it at the VIP price',
	function () use ( $t, $plt_vip, $plt_product, $plt_ajax ) {
		$t::require_plugin( 'sunshine_modal_gallery_add_to_cart', 'Digital Downloads add-on' );
		$vip      = $plt_vip();
		$default  = sunshine_get_default_price_level_id();
		$download = $plt_product(
			'download',
			array( $default => '100', $vip['level'] => '10' ),
			array(
				'gallery_download' => '1',
				'subgalleries'     => '',
				'dimensions'       => array(
					'w' => '',
					'h' => '',
				),
				'watermark'        => '',
				'free'             => '',
				'order'            => '',
				'video_product'    => '',
				'disable_shipping' => '1',
			),
			'Tampering test full gallery download'
		);

		$plt_ajax(
			'sunshine_modal_gallery_add_to_cart',
			array(
				'product_id' => $download,
				'gallery_id' => $vip['gallery'],
			)
		);
		$cart = $t::calculate();
		$t::same( true, $cart->is_empty(), 'cart is empty' );
		$t::money( 0, $cart->get_total(), 'total' );
	}
);

/*
 * Discounts limited to galleries use the line's gallery, so the line must keep the photo's
 * real gallery even when the request names another one.
 */

$t::test(
	'Discount that leaves out a gallery: a photo from that gallery can\'t get it by naming another gallery',
	function () use ( $t, $plt_product, $plt_gallery, $plt_photo, $plt_ajax, $plt_code, $plt_lines ) {
		$default = sunshine_get_default_price_level_id();
		$print   = $plt_product( 'print', array( $default => '20' ), array(), 'Tampering test 16x20' );
		$sale    = $plt_gallery( $default );
		$sale_photo = $plt_photo( $sale );
		$other      = $plt_gallery( $default );
		$plt_photo( $other );

		// 20% off everything except the sale gallery, which is already discounted.
		$code_meta = array(
			'discount_type'        => 'percent-total',
			'discount_amount'      => '20',
			'disallowed_galleries' => array( (int) $sale ),
		);

		// First the request the gallery page really sends (the photo's own gallery), then the
		// same request with another gallery named instead.
		foreach ( array( 'own gallery named' => $sale, 'other gallery named' => $other ) as $label => $named ) {
			$t::reset();
			$t::option( 'proofing', '' );
			$plt_code( $code_meta );
			$plt_ajax(
				'sunshine_modal_add_item_to_cart',
				array(
					'product_id' => $print,
					'image_id'   => $sale_photo,
					'gallery_id' => $named,
					'qty'        => 2,
				)
			);
			$cart  = $t::calculate();
			$lines = $plt_lines();
			$t::same( (int) $sale, $lines ? (int) $lines[0]->get_gallery_id() : 0, $label . ': line belongs to the sale gallery' );
			// 2 x 20 = 40, no discount on sale gallery photos.
			$t::money( 0, $cart->get_discount(), $label . ': discount' );
			$t::money( 40, $cart->get_total(), $label . ': total' );
		}
	}
);

$t::test(
	'Discount for one gallery: a photo from another gallery can\'t get it by naming that gallery',
	function () use ( $t, $plt_product, $plt_gallery, $plt_photo, $plt_ajax, $plt_code ) {
		$t::tax( 10 );
		$default = sunshine_get_default_price_level_id();
		$print   = $plt_product( 'print', array( $default => '20' ), array( 'taxable' => '1' ), 'Tampering test 20x30' );
		$promo   = $plt_gallery( $default );
		$plt_photo( $promo );
		$other       = $plt_gallery( $default );
		$other_photo = $plt_photo( $other );

		// 50% off, only for photos in the promo gallery.
		$code_meta = array(
			'discount_type'     => 'percent-total',
			'discount_amount'   => '50',
			'allowed_galleries' => array( (int) $promo ),
		);

		foreach ( array( 'own gallery named' => $other, 'promo gallery named' => $promo ) as $label => $named ) {
			$t::reset();
			$t::option( 'proofing', '' );
			$t::tax( 10 );
			$plt_code( $code_meta );
			$plt_ajax(
				'sunshine_modal_add_item_to_cart',
				array(
					'product_id' => $print,
					'image_id'   => $other_photo,
					'gallery_id' => $named,
					'qty'        => 1,
				)
			);
			$cart = $t::calculate();
			// 20 + 10% tax = 22, and the promo code does not apply.
			$t::money( 0, $cart->get_discount(), $label . ': discount' );
			$t::money( 2, $cart->get_tax(), $label . ': tax' );
			$t::money( 22, $cart->get_total(), $label . ': total' );
		}
	}
);

// Advanced Shipping: a rate limited to one gallery (Settings > Delivery & Shipping, saved as
// {method}_{setting}_{instance} options, values as strings from the form).
$t::test(
	'Shipping rate for one gallery: a photo from another gallery can\'t get it by naming that gallery',
	function () use ( $t, $plt_product, $plt_gallery, $plt_photo, $plt_ajax ) {
		$t::require_plugin( 'sunshine_advanced_shipping_init', 'Advanced Shipping add-on' );
		$default = sunshine_get_default_price_level_id();
		$print   = $plt_product( 'print', array( $default => '20' ), array(), 'Tampering test 6x9' );
		$school  = $plt_gallery( $default );
		$plt_photo( $school );
		$wedding       = $plt_gallery( $default );
		$wedding_photo = $plt_photo( $wedding );

		// $1 delivery to the school, only for school photos. Everyone else ships for $8.
		$methods = array(
			'spctamperschool' => array(
				'price'             => '1',
				'price_type'        => 'cart',
				'allowed_galleries' => array( (string) $school ),
			),
			'spctamperbox'    => array(
				'price'      => '8',
				'price_type' => 'cart',
			),
		);

		// First the request the gallery page really sends (the photo's own gallery), then the
		// same request with the school gallery named instead.
		foreach ( array( 'own gallery named' => $wedding, 'school gallery named' => $school ) as $label => $named ) {
			$t::reset();
			$t::option( 'proofing', '' );
			$list = array();
			foreach ( $methods as $instance => $settings ) {
				$list[ $instance ] = array(
					'id'     => 'flat_rate',
					'active' => true,
				);
				foreach ( $settings as $key => $value ) {
					$t::option( 'flat_rate_' . $key . '_' . $instance, $value );
				}
			}
			$t::option( 'shipping_methods', $list );
			$plt_ajax(
				'sunshine_modal_add_item_to_cart',
				array(
					'product_id' => $print,
					'image_id'   => $wedding_photo,
					'gallery_id' => $named,
					'qty'        => 1,
				)
			);
			SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
			SPC()->cart->set_checkout_data_item( 'shipping_method', 'spctamperschool' );
			$cart    = $t::calculate();
			$offered = array_keys( (array) sunshine_get_allowed_shipping_methods() );
			sort( $offered );
			$t::same( array( 'spctamperbox' ), $offered, $label . ': only the $8 rate is offered for a wedding photo' );
			// 20 + 8 shipping.
			$t::money( 8, $cart->get_shipping(), $label . ': shipping' );
			$t::money( 28, $cart->get_total(), $label . ': total' );
		}
	}
);

/*
 * Add to cart links: ?sunshine_action=add_to_cart&product_id=..&price_level=..
 */

$t::test(
	'Add to cart link: a private price level in the link is not honored',
	function () use ( $t, $plt_vip, $plt_product, $plt_link, $plt_public_or_refused ) {
		$t::require_plugin( 'sunshine_sell_anything_shortcode', 'Sell Anything add-on' );
		$vip     = $plt_vip();
		$default = sunshine_get_default_price_level_id();
		$product = $plt_product( 'general', array( $default => '20', $vip['level'] => '5' ), array(), 'Tampering test calendar' );

		$to = $plt_link(
			array(
				'product_id'  => $product,
				'price_level' => $vip['level'],
				'qty'         => 2,
			)
		);
		$t::same( true, '' !== $to, 'link redirects back' );
		// 2 x 20 public price = 40.
		$plt_public_or_refused( 40, $vip['level'], 'calendar' );
	}
);

$t::test(
	'Add to cart link: a photo\'s gallery level wins over the level in the link',
	function () use ( $t, $plt_vip, $plt_product, $plt_gallery, $plt_photo, $plt_link, $plt_public_or_refused ) {
		$vip     = $plt_vip();
		$default = sunshine_get_default_price_level_id();
		$print   = $plt_product( 'print', array( $default => '20', $vip['level'] => '5' ), array(), 'Tampering test 12x12' );
		$photo   = $plt_photo( $plt_gallery( $default ) );

		$plt_link(
			array(
				'product_id'  => $print,
				'image_id'    => $photo,
				'price_level' => $vip['level'],
				'qty'         => 1,
			)
		);
		$cart = $t::calculate();
		$t::same( false, $cart->is_empty(), 'added' );
		// 1 x 20 at the gallery's level.
		$plt_public_or_refused( 20, $vip['level'], '12x12' );
	}
);

$t::test(
	'Add to cart link: a photo from a private gallery can\'t be added by link',
	function () use ( $t, $plt_vip, $plt_product, $plt_link ) {
		$vip     = $plt_vip();
		$default = sunshine_get_default_price_level_id();
		$print   = $plt_product( 'print', array( $default => '20', $vip['level'] => '5' ), array(), 'Tampering test 10x10' );

		$plt_link(
			array(
				'product_id'  => $print,
				'image_id'    => $vip['photo'],
				'price_level' => $vip['level'],
				'qty'         => 1,
			)
		);
		$cart = $t::calculate();
		$t::same( true, $cart->is_empty(), 'cart is empty' );
		$t::money( 0, $cart->get_total(), 'total' );
	}
);

/*
 * Session fees: the booking form and the "Buy now" link both carry the price level
 */

$t::test(
	'Session fee booking form: the level the page shows is the price charged',
	function () use ( $t, $plt_product, $plt_book_session ) {
		$t::require_plugin( 'sunshine_session_fees_add_to_cart', 'Session Fees add-on' );
		$t::customer();
		$default = sunshine_get_default_price_level_id();
		$session = $plt_product( 'session-fee', array( $default => '300' ), array( 'require_session' => '', 'session_products' => array() ), 'Tampering test family session' );
		// The page fills the hidden field with the session fee's level.
		$level = sunshine_get_product( $session )->get_price_level();

		$plt_book_session(
			array(
				'product_id'  => $session,
				'price_level' => $level,
			)
		);
		$cart = $t::calculate();
		$t::money( 300, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Session fee booking form: a private price level in the form is not honored, and the order is at the public price',
	function () use ( $t, $plt_vip, $plt_product, $plt_book_session, $plt_public_or_refused, $plt_place_order ) {
		$t::require_plugin( 'sunshine_session_fees_add_to_cart', 'Session Fees add-on' );
		$vip = $plt_vip();
		$t::customer();
		$default = sunshine_get_default_price_level_id();
		$session = $plt_product( 'session-fee', array( $default => '300', $vip['level'] => '50' ), array( 'require_session' => '', 'session_products' => array() ), 'Tampering test newborn session' );

		$to = $plt_book_session(
			array(
				'product_id'  => $session,
				'price_level' => $vip['level'],
			)
		);
		$t::same( true, '' !== $to, 'form redirects to the cart' );
		// The public session price is 300. The VIP price would be 50.
		if ( ! $plt_public_or_refused( 300, $vip['level'], 'session' ) ) {
			return;
		}
		$order = $plt_place_order();
		$t::money( 300, $order->get_total(), 'order total' );
	}
);

$t::test(
	'Session fee "Buy now" link: a private price level in the link is not honored',
	function () use ( $t, $plt_vip, $plt_product, $plt_link, $plt_public_or_refused ) {
		$t::require_plugin( 'sunshine_session_fees_add_to_cart', 'Session Fees add-on' );
		$vip = $plt_vip();
		$t::customer();
		$default = sunshine_get_default_price_level_id();
		$session = $plt_product( 'session-fee', array( $default => '300', $vip['level'] => '50' ), array( 'require_session' => '', 'session_products' => array() ), 'Tampering test senior session' );

		$plt_link(
			array(
				'product_id'  => $session,
				'price_level' => $vip['level'],
			)
		);
		// 300 at the public price.
		$plt_public_or_refused( 300, $vip['level'], 'session' );
	}
);

/*
 * Gift cards: the customer picks the amount, the price level plays no part
 */

$t::test(
	'Gift card page: the price level in the request does not change what the card costs',
	function () use ( $t, $plt_vip, $plt_product, $plt_ajax ) {
		$t::require_plugin( 'sunshine_gift_card_add_to_cart_item', 'Gift Cards add-on' );
		$vip     = $plt_vip();
		$default = sunshine_get_default_price_level_id();
		// The gift card product the add-on makes: no price of its own, preset amounts,
		// custom amounts up to 1000, and not taxed.
		$card = $plt_product(
			'gift-card',
			array( $default => '' ),
			array(
				'gift_card_preset_amounts' => '25,50,100,200,500',
				'gift_card_allow_custom'   => '1',
				'gift_card_max_amount'     => '1000',
			),
			'Tampering test gift card'
		);

		foreach ( array( 'VIP level' => $vip['level'], 'made-up level' => 99999999 ) as $label => $level ) {
			$t::reset();
			$response = $plt_ajax(
				'sunshine_modal_add_item_to_cart',
				array(
					'product_id'                => $card,
					'price_level'               => $level,
					'qty'                       => 1,
					'gift_card_amount'          => '50',
					'gift_card_delivery'        => 'immediate',
					'gift_card_recipient_name'  => 'Tamper Test',
					'gift_card_recipient_email' => 'tamper-test@example.com',
				)
			);
			$t::same( true, ! empty( $response['success'] ), $label . ': added' );
			$cart = $t::calculate();
			// The card costs the 50 chosen, whatever level was sent.
			$t::money( 50, $cart->get_total(), $label . ': total' );
		}
	}
);

if ( $plt_blocked ) {
	foreach ( array_unique( $plt_blocked ) as $plt_url ) {
		WP_CLI::log( 'BLOCKED REQUEST (price-level-tampering) ' . $plt_url );
	}
}

if ( $plt_warnings ) {
	foreach ( array_unique( $plt_warnings ) as $plt_warning ) {
		WP_CLI::log( 'PHP WARNING (price-level-tampering) ' . $plt_warning );
	}
}
