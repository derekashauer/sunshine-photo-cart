<?php
/**
 * WooCommerce add-on (sunshine-woocommerce).
 *
 * With the add-on on, WooCommerce owns the cart and checkout. A photo bought from a gallery
 * becomes a WooCommerce cart line that remembers the photo, its gallery and the price level.
 * Every time WooCommerce works out the cart, the add-on sets the line's price from what the
 * photographer typed for that price level on the product's Sunshine tab:
 *   a number          = the price in galleries at that level
 *   blank             = the product's normal WooCommerce price
 *   "not available"   = the product can't be bought at that level
 * Product options are WooCommerce variations: a variation's own level price comes first, then
 * the main product's level price, then the variation's normal price. A package or a
 * multi-image product is one cart line at that product's own price.
 *
 * WooCommerce settings are changed only in memory. Tax comes from an in-memory rate, shipping
 * is off unless a test adds its own in-memory rate, background jobs and cookies are switched
 * off, and every outgoing web request is blocked. Products, galleries, photos, price levels,
 * coupons and orders made here are deleted at the end of each test.
 */

$t = 'SPC_Totals_Test';

// What a test made or changed, so it can all be put back when the test ends.
$woo = (object) array(
	'filters' => array(),
	'posts'   => array(),
	'orders'  => array(),
	'terms'   => array(),
	'post'    => array(),
	'request' => array(),
);

$woo_filter = function ( $hook, $callback, $priority = 10, $args = 1 ) use ( $woo ) {
	add_filter( $hook, $callback, $priority, $args );
	$woo->filters[] = array( $hook, $callback, $priority );
};

// A WooCommerce setting for this test only. Nothing is written to the database.
$woo_setting = function ( $name, $value ) use ( $woo_filter ) {
	$woo_filter(
		'pre_option_' . $name,
		function () use ( $value ) {
			return $value;
		}
	);
};

// The add-on remembers prices until the page load ends. A new page load starts with nothing.
$woo_forget_cached_prices = function () {
	foreach ( array( 'SPC_WC_Price_Levels', 'SPC_WC_Products' ) as $class ) {
		if ( class_exists( $class ) && property_exists( $class, 'cache' ) ) {
			$cache = new ReflectionProperty( $class, 'cache' );
			if ( PHP_VERSION_ID < 80100 ) {
				$cache->setAccessible( true );
			}
			$cache->setValue( null, array() );
		}
	}
};

$woo_begin = function () use ( $t, $woo, $woo_filter, $woo_setting, $woo_forget_cached_prices ) {
	$t::require_plugin( 'sunshine_wc_init', 'WooCommerce add-on' );
	if ( ! function_exists( 'WC' ) || ! class_exists( 'SPC_WC_Price_Levels' ) ) {
		$t::skip( 'WooCommerce is not active, so the WooCommerce add-on is not running' );
	}

	$woo->post    = $_POST;
	$woo->request = $_REQUEST;
	$_POST        = array();
	$_REQUEST     = array();

	// Deleting a test gallery must not queue a background job to delete its photos.
	$t::option( 'delete_images', false );

	// A US store in Colorado that charges in dollars, with tax and shipping off.
	$woo_setting( 'woocommerce_default_country', 'US:CO' );
	$woo_setting( 'woocommerce_allowed_countries', 'all' );
	$woo_setting( 'woocommerce_ship_to_countries', '' );
	$woo_setting( 'woocommerce_currency', 'USD' );
	$woo_setting( 'woocommerce_price_num_decimals', '2' );
	$woo_setting( 'woocommerce_calc_taxes', 'no' );
	$woo_setting( 'woocommerce_prices_include_tax', 'no' );
	$woo_setting( 'woocommerce_tax_based_on', 'base' );
	$woo_setting( 'woocommerce_tax_round_at_subtotal', 'no' );
	$woo_setting( 'woocommerce_tax_display_cart', 'excl' );
	$woo_setting( 'woocommerce_shipping_tax_class', 'inherit' );
	$woo_setting( 'woocommerce_calc_discounts_sequentially', 'no' );
	$woo_setting( 'woocommerce_enable_coupons', 'yes' );
	$woo_setting( 'woocommerce_cart_redirect_after_add', 'no' );
	$woo_filter( 'woocommerce_cart_needs_shipping', '__return_false' );

	// No browser to send cookies to, no background jobs from test products, no web requests.
	$no_job = function () {
		return 0;
	};
	$woo_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
	$woo_filter( 'pre_as_schedule_single_action', $no_job );
	$woo_filter( 'pre_as_enqueue_async_action', $no_job );
	$woo_filter( 'pre_as_schedule_recurring_action', $no_job );
	$woo_filter( 'pre_as_schedule_cron_action', $no_job );
	$woo_filter(
		'pre_http_request',
		function () {
			return new WP_Error( 'spc_totals_blocked', 'Web requests are blocked in the totals tests' );
		},
		1
	);

	$woo_forget_cached_prices();
	if ( ! WC()->cart ) {
		wc_load_cart();
	}
	WC()->cart->empty_cart();
	wc_clear_notices();

	// The shopper is in Denver, like the store.
	foreach ( array( 'billing', 'shipping' ) as $type ) {
		WC()->customer->{"set_{$type}_country"}( 'US' );
		WC()->customer->{"set_{$type}_state"}( 'CO' );
		WC()->customer->{"set_{$type}_postcode"}( '80202' );
		WC()->customer->{"set_{$type}_city"}( 'Denver' );
	}
	WC()->customer->set_is_vat_exempt( false );
};

$woo_end = function () use ( $woo, $woo_forget_cached_prices ) {
	if ( function_exists( 'WC' ) && WC()->cart ) {
		wc_clear_notices();
		WC()->cart->empty_cart();
		if ( WC()->session ) {
			// Also drops anything the test saved to the session table.
			WC()->session->destroy_session();
		}
	}
	foreach ( array_reverse( $woo->orders ) as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	foreach ( array_reverse( $woo->posts ) as $post_id ) {
		$type = get_post_type( $post_id );
		if ( in_array( $type, array( 'product', 'product_variation' ), true ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				$product->delete( true );
			}
		} elseif ( 'shop_coupon' === $type ) {
			$coupon = new WC_Coupon( $post_id );
			$coupon->delete( true );
		} elseif ( $type ) {
			wp_delete_post( $post_id, true );
		}
	}
	foreach ( $woo->terms as $term_id ) {
		wp_delete_term( $term_id, 'sunshine-product-price-level' );
	}
	$woo->orders = array();
	$woo->posts  = array();
	$woo->terms  = array();

	foreach ( $woo->filters as $filter ) {
		remove_filter( $filter[0], $filter[1], $filter[2] );
	}
	$woo->filters = array();

	if ( class_exists( 'SPC_WC_Modal' ) ) {
		SPC_WC_Modal::clear_render_context();
	}
	unset( $GLOBALS['product'] );
	$_POST    = $woo->post;
	$_REQUEST = $woo->request;
	$woo_forget_cached_prices();
};

// Every test in this file starts from the same WooCommerce store and puts everything back after.
$woo_test = function ( $name, $callback ) use ( $t, $woo_begin, $woo_end ) {
	$t::test(
		$name,
		function () use ( $callback, $woo_begin, $woo_end ) {
			try {
				$woo_begin();
				$callback();
			} finally {
				$woo_end();
			}
		}
	);
};

/*
 * Fixtures, saved in the same shape the admin screens save them.
 */

// A price level, like Products > Price Levels > Add. "Zz" keeps test levels at the end of the
// list, so the store's default level (the first one) does not change while the tests run.
$woo_level = function ( $name ) use ( $t, $woo ) {
	$term = wp_insert_term( 'Zz Totals WC ' . $name . ' ' . wp_rand( 10000, 99999 ), 'sunshine-product-price-level' );
	if ( is_wp_error( $term ) ) {
		throw new Exception( 'Could not create price level: ' . $term->get_error_message() );
	}
	$t::track( 'term', $term['term_id'], 'sunshine-product-price-level' );
	$woo->terms[] = (int) $term['term_id'];
	return (int) $term['term_id'];
};

// Save the Sunshine tab of the WooCommerce product screen through the add-on's own save handler.
// The form has a price box for every price level on the site. A level marked "not available"
// sends only its checkbox, because the form disables its price box.
$woo_save_sunshine_tab = function ( $product_id, $args ) {
	if ( ! function_exists( 'sunshine_wc_save_product_data' ) ) {
		require_once SUNSHINE_WC_PATH . '/admin/product-data.php';
	}
	$args = wp_parse_args(
		$args,
		array(
			'sunshine_type' => '',
			'add_mode'      => '',
			'level_prices'  => array(),
			'image_count'   => '',
			'package_items' => array(),
		)
	);

	$prices = array();
	$off    = array();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		$level_id = $level->get_id();
		$value    = isset( $args['level_prices'][ $level_id ] ) ? (string) $args['level_prices'][ $level_id ] : '';
		if ( 'off' === $value ) {
			$off[ $level_id ] = '1';
		} else {
			$prices[ $level_id ] = $value;
		}
	}

	$form = array(
		'_sunshine_enabled'       => 'yes',
		'_sunshine_type'          => $args['sunshine_type'],
		'_sunshine_add_mode'      => $args['add_mode'],
		'_sunshine_download_size' => 'original',
		'_sunshine_image_count'   => (string) $args['image_count'],
		'_sunshine_level_prices'  => $prices,
	);
	if ( $off ) {
		$form['_sunshine_level_off'] = $off;
	}
	if ( $args['package_items'] ) {
		// New package rows get keys like "new0..." from the admin screen's script.
		$rows = array();
		foreach ( $args['package_items'] as $i => $row ) {
			$rows[ 'new' . $i ] = array(
				'wc_product_id' => (string) $row[0],
				'qty'           => (string) $row[1],
			);
		}
		$form['_sunshine_package_items'] = $rows;
	}

	$saved_post = $_POST;
	$_POST      = wp_slash( $form );
	$product    = wc_get_product( $product_id );
	sunshine_wc_save_product_data( $product );
	$product->save();
	$_POST = $saved_post;
};

// A WooCommerce product offered in galleries. $args: name, sale_price, taxable, sunshine_type,
// add_mode, level_prices (level ID => price, '' or 'off'), image_count, package_items.
$woo_product = function ( $price, $args = array() ) use ( $t, $woo, $woo_save_sunshine_tab ) {
	$args    = wp_parse_args(
		$args,
		array(
			'name'       => 'Totals WC print',
			'sale_price' => '',
			'taxable'    => true,
		)
	);
	$product = new WC_Product_Simple();
	$product->set_name( $args['name'] );
	$product->set_regular_price( (string) $price );
	if ( '' !== $args['sale_price'] ) {
		$product->set_sale_price( (string) $args['sale_price'] );
	}
	$product->set_tax_status( $args['taxable'] ? 'taxable' : 'none' );
	$product->set_status( 'publish' );
	$id = $product->save();
	$t::track( 'post', $id );
	$woo->posts[] = $id;
	$woo_save_sunshine_tab( $id, $args );
	return $id;
};

// A product with a Size option (WooCommerce variations). $sizes: size => array( price, level_prices ).
// Each variation's level price boxes are saved through the add-on's variation save handler.
$woo_product_with_sizes = function ( $sizes, $args = array() ) use ( $t, $woo, $woo_save_sunshine_tab ) {
	if ( ! function_exists( 'sunshine_wc_save_variation_level_prices' ) ) {
		require_once SUNSHINE_WC_PATH . '/admin/product-data.php';
	}
	$product   = new WC_Product_Variable();
	$attribute = new WC_Product_Attribute();
	$attribute->set_id( 0 );
	$attribute->set_name( 'Size' );
	$attribute->set_options( array_map( 'strval', array_keys( $sizes ) ) );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$product->set_name( 'Totals WC print with sizes' );
	$product->set_attributes( array( $attribute ) );
	$product->set_tax_status( 'taxable' );
	$product->set_status( 'publish' );
	$id = $product->save();
	$t::track( 'post', $id );
	$woo->posts[] = $id;

	$variation_ids = array();
	$loop          = 0;
	foreach ( $sizes as $size => $size_args ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $id );
		$variation->set_attributes( array( 'size' => (string) $size ) );
		$variation->set_regular_price( (string) $size_args['price'] );
		$variation->set_status( 'publish' );
		$variation_id = $variation->save();
		$t::track( 'post', $variation_id );
		$woo->posts[] = $variation_id;

		$boxes = array();
		foreach ( (array) sunshine_get_price_levels() as $level ) {
			$level_id           = $level->get_id();
			$boxes[ $level_id ] = isset( $size_args['level_prices'][ $level_id ] ) ? (string) $size_args['level_prices'][ $level_id ] : '';
		}
		$saved_post = $_POST;
		$_POST      = wp_slash( array( '_sunshine_variation_level_prices' => array( $loop => $boxes ) ) );
		sunshine_wc_save_variation_level_prices( $variation_id, $loop );
		$_POST = $saved_post;

		$variation_ids[ (string) $size ] = $variation_id;
		$loop++;
	}
	WC_Product_Variable::sync( $id );
	$woo_save_sunshine_tab( $id, $args );
	return array(
		'id'    => $id,
		'sizes' => $variation_ids,
	);
};

// A published gallery with photos, like the gallery screen and uploader save it.
$woo_gallery = function ( $level_id = 0, $photos = 1, $meta = array() ) use ( $t, $woo ) {
	$gallery_id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-gallery',
			'post_status' => 'publish',
			'post_title'  => 'Totals WC gallery',
		)
	);
	$t::track( 'post', $gallery_id );
	$woo->posts[] = $gallery_id;
	update_post_meta( $gallery_id, 'status', 'default' );
	if ( $level_id ) {
		update_post_meta( $gallery_id, 'price_level', (string) $level_id );
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $gallery_id, $key, $value );
	}
	$image_ids = array();
	for ( $i = 0; $i < $photos; $i++ ) {
		$name     = 'totals-wc-photo-' . wp_rand( 100000, 999999 );
		$image_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $name,
				'post_content'   => '',
				'post_status'    => 'inherit',
				'comment_status' => 'inherit',
				'ping_status'    => 'inherit',
			),
			$name . '.jpg',
			$gallery_id
		);
		$t::track( 'post', $image_id );
		$woo->posts[] = $image_id;
		$image_ids[]  = $image_id;
	}
	sunshine_get_gallery( $gallery_id )->set_image_ids( $image_ids );
	return array(
		'id'     => $gallery_id,
		'photos' => $image_ids,
	);
};

// The per-photo "Price level" box on the media screen, saved as a whole number.
$woo_photo_level = function ( $image_id, $level_id ) {
	update_post_meta( $image_id, 'sunshine_price_level', intval( $level_id ) );
};

// Add a photo + product line the way the gallery popup does it.
$woo_add = function ( $product_id, $image_id, $qty = 1, $options = array() ) {
	$key = SPC_WC_Modal::add_to_wc_cart( $product_id, $image_id, 0, $qty, '', $options );
	if ( is_wp_error( $key ) ) {
		throw new Exception( 'Could not add product ' . $product_id . ': ' . $key->get_error_message() );
	}
	return $key;
};

$woo_calculate = function () {
	WC()->cart->calculate_totals();
	return WC()->cart;
};

$woo_lines = function () {
	return array_values( WC()->cart->get_cart() );
};

// A new page load: WooCommerce builds the cart again from what it saved in the session,
// with fresh product objects, and the add-on's remembered prices start empty.
$woo_next_page_load = function () use ( $woo_forget_cached_prices ) {
	WC()->cart->set_session();
	$woo_forget_cached_prices();
	WC()->cart->get_cart_from_session();
	WC()->cart->calculate_totals();
	return WC()->cart;
};

// The cart and checkout pages check every gallery line again before showing it.
$woo_check_cart = function () {
	do_action( 'woocommerce_check_cart_items' );
	WC()->cart->calculate_totals();
	return WC()->cart;
};

// Turn on one tax rate for the whole store, without touching the tax rate table.
$woo_tax = function ( $percent ) use ( $woo_setting, $woo_filter ) {
	$woo_setting( 'woocommerce_calc_taxes', 'yes' );
	$woo_filter(
		'woocommerce_find_rates',
		function ( $rates, $args ) use ( $percent ) {
			if ( ! empty( $args['tax_class'] ) ) {
				return array();
			}
			return array(
				990001 => array(
					'rate'     => (float) $percent,
					'label'    => 'Totals test tax',
					'shipping' => 'yes',
					'compound' => 'no',
				),
			);
		},
		10,
		2
	);
};

// One flat shipping rate, in memory instead of a shipping zone.
$woo_flat_shipping = function ( $cost ) use ( $woo_filter ) {
	$woo_filter( 'woocommerce_cart_needs_shipping', '__return_true', 20 );
	$woo_filter(
		'woocommerce_package_rates',
		function () use ( $cost ) {
			$taxes = wc_tax_enabled() ? WC_Tax::calc_shipping_tax( $cost, WC_Tax::get_shipping_tax_rates() ) : array();
			$rate  = new WC_Shipping_Rate( 'spc_totals_flat:1', 'Totals test shipping', $cost, $taxes, 'spc_totals_flat', 1 );
			return array( $rate->get_id() => $rate );
		},
		999
	);
};

// A WooCommerce coupon, applied to the cart. Returns whether WooCommerce accepted it.
$woo_coupon = function ( $type, $amount, $props = array() ) use ( $t, $woo ) {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'spctotalswc' . wp_rand( 100000, 999999 ) );
	$coupon->set_discount_type( $type );
	$coupon->set_amount( (string) $amount );
	foreach ( $props as $prop => $value ) {
		$coupon->{"set_{$prop}"}( $value );
	}
	$id = $coupon->save();
	$t::track( 'post', $id );
	$woo->posts[] = $id;
	$applied      = WC()->cart->apply_coupon( $coupon->get_code() );
	wc_clear_notices();
	return (bool) $applied;
};

// Run an AJAX handler the way admin-ajax.php does and return its JSON answer.
// $wp_loaded runs first, with the same request, like WordPress's wp_loaded hooks do.
$woo_ajax = function ( $handler, $fields, $wp_loaded = null ) {
	if ( ! isset( $fields['security'] ) ) {
		$fields['security'] = wp_create_nonce( 'sunshinephotocart' );
	}
	$saved_post    = $_POST;
	$saved_request = $_REQUEST;
	$_POST         = wp_slash( $fields );
	$_REQUEST      = $_POST;
	$ajax          = '__return_true';
	$die           = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'wp_doing_ajax', $ajax );
	add_filter( 'wp_die_ajax_handler', $die );
	ob_start();
	try {
		if ( $wp_loaded ) {
			call_user_func( $wp_loaded );
		}
		call_user_func( $handler );
	} catch ( SPC_Totals_Test_Done $e ) {
		// wp_send_json() always ends the request.
	} finally {
		$output = ob_get_clean();
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );
		$_POST    = $saved_post;
		$_REQUEST = $saved_request;
	}
	$response = json_decode( $output, true );
	if ( ! is_array( $response ) ) {
		throw new Exception( 'No JSON answer: ' . substr( wp_strip_all_tags( $output ), 0, 300 ) );
	}
	return $response;
};

// The fields jQuery's serialize() sends from the first add-to-cart form in some HTML:
// inputs, selects and textareas with a name, but never buttons.
$woo_form_fields = function ( $html ) {
	$dom      = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	$xpath = new DOMXPath( $dom );
	$form  = $xpath->query( '//form[contains(concat(" ", normalize-space(@class), " "), " cart ")]' )->item( 0 );
	if ( ! $form ) {
		throw new Exception( 'No WooCommerce add-to-cart form in the product view' );
	}
	$fields = array();
	foreach ( $xpath->query( './/input|.//select|.//textarea', $form ) as $element ) {
		$name = $element->getAttribute( 'name' );
		if ( '' === $name || $element->hasAttribute( 'disabled' ) ) {
			continue;
		}
		$tag = strtolower( $element->nodeName );
		if ( 'input' === $tag ) {
			$type = strtolower( $element->getAttribute( 'type' ) ? $element->getAttribute( 'type' ) : 'text' );
			if ( in_array( $type, array( 'submit', 'button', 'image', 'reset', 'file' ), true ) ) {
				continue;
			}
			if ( in_array( $type, array( 'checkbox', 'radio' ), true ) && ! $element->hasAttribute( 'checked' ) ) {
				continue;
			}
			$fields[ $name ] = $element->getAttribute( 'value' );
		} elseif ( 'select' === $tag ) {
			$value = null;
			foreach ( $xpath->query( './/option', $element ) as $option ) {
				if ( null === $value || $option->hasAttribute( 'selected' ) ) {
					$value = $option->getAttribute( 'value' );
				}
				if ( $option->hasAttribute( 'selected' ) ) {
					break;
				}
			}
			$fields[ $name ] = (string) $value;
		} else {
			$fields[ $name ] = $element->textContent;
		}
	}
	return $fields;
};

// Open a product for a photo in the gallery popup and fill in its WooCommerce form the way the
// customer would ($choices), plus the fields sunshine-wc.js adds when Add to cart is pressed.
$woo_popup_form = function ( $image_id, $product_id, $choices = array(), $bulk_image_ids = array() ) use ( $woo_ajax, $woo_form_fields ) {
	$view = array(
		'image_id'   => $image_id,
		'product_id' => $product_id,
	);
	if ( $bulk_image_ids ) {
		$view['imageIds'] = implode( ',', $bulk_image_ids );
	}
	$response = $woo_ajax( array( 'SPC_WC_Modal', 'product_details' ), $view );
	if ( empty( $response['success'] ) || empty( $response['data']['html'] ) ) {
		throw new Exception( 'The product view did not load: ' . wp_json_encode( $response ) );
	}
	$form = $woo_form_fields( $response['data']['html'] );
	foreach ( $choices as $name => $value ) {
		$form[ $name ] = (string) $value;
	}
	$form['action']     = 'sunshine_wc_add_form_to_cart';
	$form['product_id'] = (string) $product_id;
	if ( $bulk_image_ids ) {
		$form['imageIds'] = implode( ',', $bulk_image_ids );
	}
	return $form;
};

// Press Add to cart in the popup. admin-ajax.php first runs WordPress's wp_loaded hooks, which
// include WooCommerce's own add-to-cart form handler, and then the add-on's AJAX action.
$woo_send_popup_form = function ( $form ) use ( $woo_ajax ) {
	return $woo_ajax( array( 'SPC_WC_Modal', 'add_form_to_cart' ), $form, array( 'WC_Form_Handler', 'add_to_cart_action' ) );
};

// A normal (not AJAX) form post, like the WooCommerce product page's Add to cart button.
$woo_form_post = function ( $fields ) {
	$saved_post    = $_POST;
	$saved_request = $_REQUEST;
	$_POST         = wp_slash( $fields );
	$_REQUEST      = $_POST;
	try {
		WC_Form_Handler::add_to_cart_action();
	} finally {
		$_POST    = $saved_post;
		$_REQUEST = $saved_request;
	}
};

$woo_error_notices = function () {
	$messages = array();
	foreach ( wc_get_notices( 'error' ) as $notice ) {
		$messages[] = wp_strip_all_tags( $notice['notice'] );
	}
	return $messages;
};

// Press Place order: WooCommerce runs its cart checks, then saves the order from the cart.
$woo_place_order = function () use ( $t, $woo, $woo_error_notices ) {
	WC()->cart->calculate_totals();
	do_action( 'woocommerce_check_cart_items' );
	do_action( 'woocommerce_checkout_process' );
	$errors = $woo_error_notices();
	if ( $errors ) {
		throw new Exception( 'Checkout stopped: ' . implode( ' | ', $errors ) );
	}
	$order_id = WC()->checkout()->create_order(
		array(
			'payment_method'     => '',
			'billing_email'      => 'totals-test@example.com',
			'billing_first_name' => 'Totals',
			'billing_last_name'  => 'Test',
			'billing_address_1'  => '1 Main St',
			'billing_city'       => 'Denver',
			'billing_state'      => 'CO',
			'billing_postcode'   => '80202',
			'billing_country'    => 'US',
		)
	);
	if ( is_wp_error( $order_id ) || ! $order_id ) {
		throw new Exception( 'Order was not created: ' . ( is_wp_error( $order_id ) ? $order_id->get_error_message() : 'no ID' ) );
	}
	$t::track( 'post', $order_id );
	$woo->orders[] = $order_id;
	return wc_get_order( $order_id );
};

// What the cart shows right before the customer places the order.
$woo_cart_snapshot = function () {
	$cart  = WC()->cart;
	$lines = array();
	foreach ( $cart->get_cart() as $item ) {
		$lines[] = array(
			'qty'      => (int) $item['quantity'],
			'subtotal' => (float) $item['line_subtotal'],
			'total'    => (float) $item['line_total'],
			'tax'      => (float) $item['line_tax'],
		);
	}
	return array(
		'subtotal' => (float) $cart->get_subtotal(),
		'discount' => (float) $cart->get_discount_total(),
		'shipping' => (float) $cart->get_shipping_total(),
		'tax'      => (float) $cart->get_total_tax(),
		'total'    => (float) $cart->get_total( 'edit' ),
		'lines'    => $lines,
	);
};

$woo_order_matches_cart = function ( $order, $seen ) use ( $t ) {
	$t::money( $seen['subtotal'], $order->get_subtotal(), 'order subtotal' );
	$t::money( $seen['discount'], $order->get_discount_total(), 'order discount' );
	$t::money( $seen['shipping'], $order->get_shipping_total(), 'order shipping' );
	$t::money( $seen['tax'], $order->get_total_tax(), 'order tax' );
	$t::money( $seen['total'], $order->get_total(), 'order total' );
	$items = array_values( $order->get_items() );
	$t::same( count( $seen['lines'] ), count( $items ), 'number of order lines' );
	foreach ( $seen['lines'] as $i => $line ) {
		if ( empty( $items[ $i ] ) ) {
			continue;
		}
		$t::same( $line['qty'], (int) $items[ $i ]->get_quantity(), 'line ' . ( $i + 1 ) . ' quantity' );
		$t::money( $line['subtotal'], $items[ $i ]->get_subtotal(), 'line ' . ( $i + 1 ) . ' subtotal' );
		$t::money( $line['total'], $items[ $i ]->get_total(), 'line ' . ( $i + 1 ) . ' total' );
		$t::money( $line['tax'], $items[ $i ]->get_total_tax(), 'line ' . ( $i + 1 ) . ' tax' );
	}
};

/*
 * Price levels.
 */

$woo_test(
	'WooCommerce: photo uses its gallery\'s price level price',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_lines ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '25' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 2 );
		$cart  = $woo_calculate();
		$lines = $woo_lines();
		$t::money( 25, $lines[0]['data']->get_price(), 'line price is the level price, not the normal 20' );
		// 2 x 25.
		$t::money( 50, $lines[0]['line_subtotal'], 'line subtotal' );
		$t::money( 50, $cart->get_total( 'edit' ), 'total' );
	}
);

$woo_test(
	'WooCommerce: a blank level price uses the normal WooCommerce price',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate ) {
		$level   = $woo_level( 'Portrait' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 3 );
		$cart = $woo_calculate();
		// 3 x 20.
		$t::money( 60, $cart->get_total( 'edit' ), 'total' );
	}
);

$woo_test(
	'WooCommerce: a photo with its own price level uses that level',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_photo_level, $woo_add, $woo_calculate, $woo_lines ) {
		$gallery_level = $woo_level( 'Gallery' );
		$photo_level   = $woo_level( 'Hero photo' );
		$product       = $woo_product(
			20,
			array(
				'level_prices' => array(
					$gallery_level => '25',
					$photo_level   => '30',
				),
			)
		);
		$gallery = $woo_gallery( $gallery_level, 2 );
		$woo_photo_level( $gallery['photos'][0], $photo_level );
		$woo_add( $product, $gallery['photos'][0], 1 );
		$woo_add( $product, $gallery['photos'][1], 1 );
		$cart  = $woo_calculate();
		$lines = $woo_lines();
		$t::money( 30, $lines[0]['line_total'], 'photo with its own level' );
		$t::money( 25, $lines[1]['line_total'], 'photo using the gallery level' );
		$t::money( 55, $cart->get_total( 'edit' ), 'total' );
	}
);

$woo_test(
	'WooCommerce: a gallery with no price level uses the default level',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate ) {
		$other   = $woo_level( 'Other' );
		$default = sunshine_get_default_price_level_id();
		$t::same( false, in_array( (int) $default, array( $other ), true ), 'test levels never become the default level' );
		$product = $woo_product(
			20,
			array(
				'level_prices' => array(
					$default => '18',
					$other   => '40',
				),
			)
		);
		$gallery = $woo_gallery( 0 );
		$woo_add( $product, $gallery['photos'][0], 1 );
		$cart = $woo_calculate();
		$t::money( 18, $cart->get_total( 'edit' ), 'default level price' );
	}
);

$woo_test(
	'WooCommerce: a product marked not available at a level can\'t be added',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_calculate, $woo_forget_cached_prices ) {
		$open    = $woo_level( 'Open' );
		$closed  = $woo_level( 'Closed' );
		$product = $woo_product(
			20,
			array(
				'level_prices' => array(
					$open   => '25',
					$closed => 'off',
				),
			)
		);
		$gallery = $woo_gallery( $closed );
		$added   = SPC_WC_Modal::add_to_wc_cart( $product, $gallery['photos'][0], 0, 1 );
		$t::same( true, is_wp_error( $added ), 'add to cart refused' );
		$cart = $woo_calculate();
		$t::same( 0, $cart->get_cart_contents_count(), 'cart is empty' );
		$t::money( 0, $cart->get_total( 'edit' ), 'total' );

		// The gallery popup only lists the product where it can be bought.
		$woo_forget_cached_prices();
		$listed_ids = function ( $level ) {
			return array_map(
				function ( $listed ) {
					return $listed->get_id();
				},
				SPC_WC_Products::get_products_for_level( $level )
			);
		};
		$t::same( false, in_array( $product, $listed_ids( $closed ), true ), 'not listed at the closed level' );
		$t::same( true, in_array( $product, $listed_ids( $open ), true ), 'listed at the open level' );
	}
);

$woo_test(
	'WooCommerce: a product turned off after it was added is taken out before checkout',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_save_sunshine_tab, $woo_next_page_load, $woo_check_cart ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '25' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 2 );
		$t::money( 50, $woo_calculate()->get_total( 'edit' ), 'total before' );

		// The photographer marks the product not available at this level.
		$woo_save_sunshine_tab( $product, array( 'level_prices' => array( $level => 'off' ) ) );
		$woo_next_page_load();
		$cart = $woo_check_cart();
		$t::same( 0, $cart->get_cart_contents_count(), 'line taken out' );
		$t::money( 0, $cart->get_total( 'edit' ), 'nothing left to charge' );
	}
);

// Zero is a real price: the photographer typed 0, so the product is free at this level.
$woo_test(
	'WooCommerce: a level price of 0 makes the product free',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate ) {
		$level   = $woo_level( 'Included' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '0' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 2 );
		$cart = $woo_calculate();
		$t::same( 1, count( $cart->get_cart() ), 'line is in the cart' );
		$t::money( 0, $cart->get_total( 'edit' ), 'total' );
	}
);

$woo_test(
	'WooCommerce: the same product from two galleries keeps each gallery\'s price',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_lines, $woo_next_page_load ) {
		$wedding  = $woo_level( 'Wedding' );
		$school   = $woo_level( 'School' );
		$product  = $woo_product(
			20,
			array(
				'level_prices' => array(
					$wedding => '25',
					$school  => '12',
				),
			)
		);
		$gallery1 = $woo_gallery( $wedding );
		$gallery2 = $woo_gallery( $school );
		$woo_add( $product, $gallery1['photos'][0], 2 );
		$woo_add( $product, $gallery2['photos'][0], 2 );
		$cart  = $woo_calculate();
		$lines = $woo_lines();
		$t::money( 50, $lines[0]['line_total'], 'wedding photo: 2 x 25' );
		$t::money( 24, $lines[1]['line_total'], 'school photo: 2 x 12' );
		$t::money( 74, $cart->get_total( 'edit' ), 'total' );
		$cart = $woo_next_page_load();
		$t::money( 74, $cart->get_total( 'edit' ), 'total on the next page load' );
	}
);

// The add-on promises that buying the product in the normal shop is untouched.
$woo_test(
	'WooCommerce: buying the same product in the shop keeps the normal price',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_lines, $woo_next_page_load ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '25' ) ) );
		$gallery = $woo_gallery( $level );
		WC()->cart->add_to_cart( $product, 1 );
		$woo_add( $product, $gallery['photos'][0], 1 );
		$cart  = $woo_calculate();
		$lines = $woo_lines();
		$t::money( 20, $lines[0]['line_total'], 'shop line' );
		$t::money( 25, $lines[1]['line_total'], 'gallery line' );
		$t::money( 45, $cart->get_total( 'edit' ), 'total' );
		$cart  = $woo_next_page_load();
		$lines = $woo_lines();
		$t::money( 20, $lines[0]['line_total'], 'shop line on the next page load' );
		$t::money( 45, $cart->get_total( 'edit' ), 'total on the next page load' );
	}
);

$woo_test(
	'WooCommerce: level prices stay after the next page load',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_lines, $woo_next_page_load ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '25' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 2 );
		$woo_calculate();
		$cart  = $woo_next_page_load();
		$lines = $woo_lines();
		$t::same( $level, isset( $lines[0]['sunshine']['price_level'] ) ? (int) $lines[0]['sunshine']['price_level'] : null, 'line still knows its price level' );
		$t::money( 25, $lines[0]['data']->get_price(), 'line price' );
		$t::money( 50, $cart->get_total( 'edit' ), 'total' );
	}
);

// Level prices are read fresh like any WooCommerce price, so the cart follows a price change.
$woo_test(
	'WooCommerce: a changed level price shows on the next page load',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_save_sunshine_tab, $woo_next_page_load ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '25' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 2 );
		$t::money( 50, $woo_calculate()->get_total( 'edit' ), 'total before' );
		$woo_save_sunshine_tab( $product, array( 'level_prices' => array( $level => '28' ) ) );
		$cart = $woo_next_page_load();
		// 2 x 28.
		$t::money( 56, $cart->get_total( 'edit' ), 'total after the price change' );
	}
);

$woo_test(
	'WooCommerce: adding the same photo again adds to its quantity',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_lines ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '25' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 1 );
		$woo_add( $product, $gallery['photos'][0], 2 );
		$cart  = $woo_calculate();
		$lines = $woo_lines();
		$t::same( 1, count( $lines ), 'one line' );
		$t::same( 3, (int) $lines[0]['quantity'], 'quantity 3' );
		$t::money( 75, $cart->get_total( 'edit' ), 'total' );
	}
);

// Stores that write prices like 12,50 type level prices the same way.
$woo_test(
	'WooCommerce: a level price typed with a decimal comma',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_setting ) {
		$woo_setting( 'woocommerce_price_decimal_sep', ',' );
		$woo_setting( 'woocommerce_price_thousand_sep', '.' );
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '12,50' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 2 );
		$cart = $woo_calculate();
		// 2 x 12.50.
		$t::money( 25, $cart->get_total( 'edit' ), 'total' );
	}
);

// The add-on's own rule (SPC_WC_Products): a product is offered in a gallery when it is turned
// on, not marked unavailable at the level, and has a price there. A level price is a price,
// even when the product's normal WooCommerce price box is left empty.
$woo_test(
	'WooCommerce: a product priced only at a level can be bought in that gallery',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_calculate, $woo_forget_cached_prices ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( '', array( 'level_prices' => array( $level => '25' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_forget_cached_prices();
		$listed = array_map(
			function ( $listed ) {
				return $listed->get_id();
			},
			SPC_WC_Products::get_products_for_level( $level )
		);
		$t::same( true, in_array( $product, $listed, true ), 'listed in the gallery' );
		$added = SPC_WC_Modal::add_to_wc_cart( $product, $gallery['photos'][0], 0, 2 );
		$t::same( false, is_wp_error( $added ), 'added to the cart' );
		$t::money( 50, $woo_calculate()->get_total( 'edit' ), 'total: 2 x 25' );
	}
);

// That product has no price in the normal shop, so it can't be bought there for nothing. In a
// gallery's cart it stays when WooCommerce loads the cart again on the next page.
$woo_test(
	'WooCommerce: a product priced only at a level stays in a gallery cart, not in the shop',
	function () use ( $t, $woo_level, $woo_product, $woo_forget_cached_prices ) {
		$level   = $woo_level( 'Wedding' );
		$product = wc_get_product( $woo_product( '', array( 'level_prices' => array( $level => '25' ) ) ) );
		$woo_forget_cached_prices();
		$t::same( false, $product->is_purchasable(), 'not for sale in the normal shop' );
		$gallery_item = array( 'sunshine' => array( 'price_level' => $level ) );
		$t::same( true, apply_filters( 'woocommerce_cart_item_is_purchasable', $product->is_purchasable(), 'key', $gallery_item, $product ), 'kept in a gallery cart' );
		$t::same( false, apply_filters( 'woocommerce_cart_item_is_purchasable', $product->is_purchasable(), 'key', array(), $product ), 'not kept in a shop cart' );
		$t::same( false, $product->is_purchasable(), 'still not for sale in the shop afterwards' );
	}
);

$woo_test(
	'WooCommerce: large quantity at a level price with cents',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate ) {
		$level   = $woo_level( 'School' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '12.99' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 250 );
		$cart = $woo_calculate();
		// 250 x 12.99.
		$t::money( 3247.50, $cart->get_total( 'edit' ), 'total' );
	}
);

/*
 * Tax.
 */

$woo_test(
	'WooCommerce: tax is worked out on the level price',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_tax ) {
		$woo_tax( 10 );
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '25' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 2 );
		$cart = $woo_calculate();
		// 10% of 50, not of the normal 40.
		$t::money( 5, $cart->get_total_tax(), 'tax' );
		$t::money( 55, $cart->get_total( 'edit' ), 'total' );
	}
);

$woo_test(
	'WooCommerce: tax on a level price with cents rounds to the cent',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_tax ) {
		$woo_tax( 8.25 );
		$level   = $woo_level( 'School' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '3.33' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 7 );
		$cart = $woo_calculate();
		// 7 x 3.33 = 23.31. 8.25% of 23.31 = 1.923075, so 1.92.
		$t::money( 23.31, $cart->get_subtotal(), 'subtotal' );
		$t::money( 1.92, $cart->get_total_tax(), 'tax' );
		$t::money( 25.23, $cart->get_total( 'edit' ), 'total' );
	}
);

$woo_test(
	'WooCommerce: prices include tax, the level price is what the customer pays',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_tax, $woo_setting, $woo_cart_snapshot, $woo_place_order, $woo_order_matches_cart ) {
		$woo_tax( 10 );
		$woo_setting( 'woocommerce_prices_include_tax', 'yes' );
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( 22, array( 'level_prices' => array( $level => '33' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 1 );
		$cart = $woo_calculate();
		// 33 includes 10% tax: 30 + 3.
		$t::money( 30, $cart->get_subtotal(), 'subtotal without tax' );
		$t::money( 3, $cart->get_total_tax(), 'tax included' );
		$t::money( 33, $cart->get_total( 'edit' ), 'total' );
		$seen  = $woo_cart_snapshot();
		$order = $woo_place_order();
		$woo_order_matches_cart( $order, $seen );
		$t::money( 33, $order->get_total(), 'order total' );
	}
);

$woo_test(
	'WooCommerce: a product that is not taxed stays untaxed at a level price',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_tax ) {
		$woo_tax( 10 );
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product(
			20,
			array(
				'taxable'      => false,
				'level_prices' => array( $level => '25' ),
			)
		);
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 2 );
		$cart = $woo_calculate();
		$t::money( 0, $cart->get_total_tax(), 'tax' );
		$t::money( 50, $cart->get_total( 'edit' ), 'total' );
	}
);

/*
 * WooCommerce coupons on level prices.
 */

$woo_test(
	'WooCommerce: a percent coupon comes off the level price',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_coupon ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '30' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 2 );
		$t::same( true, $woo_coupon( 'percent', 10 ), 'coupon accepted' );
		$cart = $woo_calculate();
		// 10% of 60 (2 x 30), not of 40 (2 x the normal 20).
		$t::money( 6, $cart->get_discount_total(), 'discount' );
		$t::money( 54, $cart->get_total( 'edit' ), 'total' );
	}
);

$woo_test(
	'WooCommerce: a fixed cart coupon with tax on level prices',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_coupon, $woo_tax ) {
		$woo_tax( 10 );
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '25' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 2 );
		$t::same( true, $woo_coupon( 'fixed_cart', 15 ), 'coupon accepted' );
		$cart = $woo_calculate();
		// 50 - 15 = 35, tax 3.50.
		$t::money( 15, $cart->get_discount_total(), 'discount' );
		$t::money( 3.50, $cart->get_total_tax(), 'tax' );
		$t::money( 38.50, $cart->get_total( 'edit' ), 'total' );
	}
);

$woo_test(
	'WooCommerce: a fixed product coupon on a level price',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_coupon ) {
		$level   = $woo_level( 'School' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '12' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 3 );
		$t::same( true, $woo_coupon( 'fixed_product', 5 ), 'coupon accepted' );
		$cart = $woo_calculate();
		// 5 off each of 3: 36 - 15.
		$t::money( 15, $cart->get_discount_total(), 'discount' );
		$t::money( 21, $cart->get_total( 'edit' ), 'total' );
	}
);

$woo_test(
	'WooCommerce: a coupon bigger than the order',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_coupon ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '25' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 1 );
		$t::same( true, $woo_coupon( 'fixed_cart', 100 ), 'coupon accepted' );
		$cart = $woo_calculate();
		$t::money( 25, $cart->get_discount_total(), 'discount is capped at the level price' );
		$t::money( 0, $cart->get_total( 'edit' ), 'total' );
	}
);

$woo_test(
	'WooCommerce: a coupon when prices include tax',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_coupon, $woo_tax, $woo_setting ) {
		$woo_tax( 10 );
		$woo_setting( 'woocommerce_prices_include_tax', 'yes' );
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( 22, array( 'level_prices' => array( $level => '33' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 1 );
		$t::same( true, $woo_coupon( 'percent', 10 ), 'coupon accepted' );
		$cart = $woo_calculate();
		// 10% off 33 = 3.30 (3.00 + 0.30 tax). The customer pays 29.70, of which 2.70 is tax.
		$t::money( 3, $cart->get_discount_total(), 'discount without tax' );
		$t::money( 0.30, $cart->get_discount_tax(), 'tax part of the discount' );
		$t::money( 2.70, $cart->get_total_tax(), 'tax' );
		$t::money( 29.70, $cart->get_total( 'edit' ), 'total' );
	}
);

/*
 * Shop sale prices.
 */

$woo_test(
	'WooCommerce: a shop sale price is used when the level price is blank',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate ) {
		$level   = $woo_level( 'Portrait' );
		$product = $woo_product(
			20,
			array(
				'sale_price'   => '15',
				'level_prices' => array( $level => '' ),
			)
		);
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 2 );
		$cart = $woo_calculate();
		// Blank means "the normal WooCommerce price", which is the sale price while the sale runs.
		$t::money( 30, $cart->get_total( 'edit' ), 'total: 2 x 15' );
	}
);

$woo_test(
	'WooCommerce: a level price wins over a shop sale price',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product(
			20,
			array(
				'sale_price'   => '15',
				'level_prices' => array( $level => '18' ),
			)
		);
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 2 );
		$cart = $woo_calculate();
		$t::money( 36, $cart->get_total( 'edit' ), 'total: 2 x 18' );
	}
);

// The add-on sets the level price as the line's regular price "so sale logic sees the level
// price as the base". A level price is the full price for that gallery, not a sale, so a coupon
// that skips sale items should still take 10% off it.
$woo_test(
	'WooCommerce: a coupon that skips sale items still applies to a level price',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_coupon, $woo_lines ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product(
			20,
			array(
				'sale_price'   => '15',
				'level_prices' => array( $level => '25' ),
			)
		);
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 1 );
		$woo_calculate();
		$lines = $woo_lines();
		$t::same( false, $lines[0]['data']->is_on_sale(), 'the gallery line is not on sale' );
		$t::same( true, $woo_coupon( 'percent', 10, array( 'exclude_sale_items' => true ) ), 'coupon accepted' );
		$cart = $woo_calculate();
		// 10% of 25.
		$t::money( 2.50, $cart->get_discount_total(), 'discount' );
		$t::money( 22.50, $cart->get_total( 'edit' ), 'total' );
	}
);

// The other side of the same rule: a blank level price means "the normal WooCommerce price",
// and while the shop sale runs that is a sale price. The gallery line should count as on sale,
// like the same product bought in the shop, so a coupon that skips sale items skips it too.
$woo_test(
	'WooCommerce: a gallery line at the shop sale price counts as on sale',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_coupon, $woo_lines ) {
		$level   = $woo_level( 'Portrait' );
		$product = $woo_product(
			20,
			array(
				'sale_price'   => '15',
				'level_prices' => array( $level => '' ),
			)
		);
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 1 );
		$woo_calculate();
		$lines = $woo_lines();
		$t::same( true, $lines[0]['data']->is_on_sale(), 'the gallery line is on sale' );
		$woo_coupon( 'percent', 10, array( 'exclude_sale_items' => true ) );
		$cart = $woo_calculate();
		$t::money( 0, $cart->get_discount_total(), 'no extra discount on a sale price' );
		$t::money( 15, $cart->get_total( 'edit' ), 'total' );
	}
);

/*
 * Product options (WooCommerce variations).
 */

$woo_test(
	'WooCommerce: a product option uses its own level price, then the product\'s',
	function () use ( $t, $woo_level, $woo_product_with_sizes, $woo_gallery, $woo_add, $woo_calculate, $woo_lines ) {
		$wedding = $woo_level( 'Wedding' );
		$school  = $woo_level( 'School' );
		$product = $woo_product_with_sizes(
			array(
				'8x10' => array(
					'price'        => '20',
					'level_prices' => array( $wedding => '26' ),
				),
				'5x7'  => array( 'price' => '10' ),
			),
			array( 'level_prices' => array( $wedding => '22' ) )
		);
		$gallery1 = $woo_gallery( $wedding );
		$gallery2 = $woo_gallery( $school );
		$woo_add( $product['id'], $gallery1['photos'][0], 1, array( 'attribute_size' => '8x10' ) );
		$woo_add( $product['id'], $gallery1['photos'][0], 1, array( 'attribute_size' => '5x7' ) );
		$woo_add( $product['id'], $gallery2['photos'][0], 1, array( 'attribute_size' => '5x7' ) );
		$cart  = $woo_calculate();
		$lines = $woo_lines();
		$t::money( 26, $lines[0]['line_total'], '8x10 at wedding: its own level price' );
		$t::money( 22, $lines[1]['line_total'], '5x7 at wedding: the product\'s level price' );
		$t::money( 10, $lines[2]['line_total'], '5x7 at school: its normal price' );
		$t::money( 58, $cart->get_total( 'edit' ), 'total' );
	}
);

$woo_test(
	'WooCommerce: a product option can\'t be added where the product is not available',
	function () use ( $t, $woo_level, $woo_product_with_sizes, $woo_gallery, $woo_calculate ) {
		$closed  = $woo_level( 'Closed' );
		$product = $woo_product_with_sizes(
			array(
				'8x10' => array(
					'price'        => '20',
					'level_prices' => array( $closed => '15' ),
				),
			),
			array( 'level_prices' => array( $closed => 'off' ) )
		);
		$gallery = $woo_gallery( $closed );
		$added   = SPC_WC_Modal::add_to_wc_cart( $product['id'], $gallery['photos'][0], 0, 1, '', array( 'attribute_size' => '8x10' ) );
		$t::same( true, is_wp_error( $added ), 'add to cart refused' );
		$t::money( 0, $woo_calculate()->get_total( 'edit' ), 'total' );
	}
);

// The real popup: the product view is WooCommerce's own form. For a product with options that
// form also carries WooCommerce's hidden "add-to-cart" field.
$woo_test(
	'WooCommerce: a product option added from the gallery popup goes in the cart once',
	function () use ( $t, $woo_level, $woo_product_with_sizes, $woo_gallery, $woo_calculate, $woo_lines, $woo_popup_form, $woo_send_popup_form ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product_with_sizes(
			array(
				'8x10' => array(
					'price'        => '20',
					'level_prices' => array( $level => '26' ),
				),
				'5x7'  => array( 'price' => '10' ),
			)
		);
		$gallery  = $woo_gallery( $level );
		$form     = $woo_popup_form(
			$gallery['photos'][0],
			$product['id'],
			array(
				'attribute_size' => '8x10',
				'variation_id'   => $product['sizes']['8x10'],
				'quantity'       => 2,
			)
		);
		$response = $woo_send_popup_form( $form );
		$t::same( true, ! empty( $response['success'] ), 'added' );
		$t::same( 2, isset( $response['data']['count'] ) ? (int) $response['data']['count'] : null, 'popup shows 2 items in the cart' );
		$cart = $woo_calculate();
		$t::same( 1, count( $woo_lines() ), 'one cart line' );
		$t::same( 2, $cart->get_cart_contents_count(), 'two prints' );
		// 2 x 26.
		$t::money( 52, $cart->get_total( 'edit' ), 'total' );
	}
);

// Favorites or selected photos sent through the popup: one line for each photo.
$woo_test(
	'WooCommerce: a product option added for several photos at once makes one line per photo',
	function () use ( $t, $woo_level, $woo_product_with_sizes, $woo_gallery, $woo_calculate, $woo_lines, $woo_popup_form, $woo_send_popup_form ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product_with_sizes(
			array(
				'8x10' => array(
					'price'        => '20',
					'level_prices' => array( $level => '26' ),
				),
			)
		);
		$gallery  = $woo_gallery( $level, 2 );
		$form     = $woo_popup_form(
			$gallery['photos'][0],
			$product['id'],
			array(
				'attribute_size' => '8x10',
				'variation_id'   => $product['sizes']['8x10'],
				'quantity'       => 1,
			),
			$gallery['photos']
		);
		$response = $woo_send_popup_form( $form );
		$t::same( 2, isset( $response['data']['added_count'] ) ? (int) $response['data']['added_count'] : null, 'two photos added' );
		$cart = $woo_calculate();
		$t::same( 2, count( $woo_lines() ), 'one line per photo' );
		// 2 photos x 26.
		$t::money( 52, $cart->get_total( 'edit' ), 'total' );
	}
);

// What the customer sees before pressing Add to cart must be the price the cart charges.
$woo_test(
	'WooCommerce: the gallery popup shows each option at its level price',
	function () use ( $t, $woo_level, $woo_product_with_sizes, $woo_gallery, $woo_ajax ) {
		$level    = $woo_level( 'Wedding' );
		$product  = $woo_product_with_sizes(
			array(
				'8x10' => array(
					'price'        => '20',
					'level_prices' => array( $level => '26' ),
				),
				'5x7'  => array( 'price' => '10' ),
			)
		);
		$gallery  = $woo_gallery( $level );
		$response = $woo_ajax(
			array( 'SPC_WC_Modal', 'product_details' ),
			array(
				'image_id'   => $gallery['photos'][0],
				'product_id' => $product['id'],
			)
		);
		$html = isset( $response['data']['html'] ) ? $response['data']['html'] : '';
		$dom  = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		$xpath  = new DOMXPath( $dom );
		$form   = $xpath->query( '//form[@data-product_variations]' )->item( 0 );
		$shown  = array();
		$offers = $form ? json_decode( $form->getAttribute( 'data-product_variations' ), true ) : array();
		foreach ( (array) $offers as $offer ) {
			$shown[ (int) $offer['variation_id'] ] = $offer['display_price'];
		}
		$t::money( 26, isset( $shown[ $product['sizes']['8x10'] ] ) ? $shown[ $product['sizes']['8x10'] ] : null, '8x10 shows its level price' );
		$t::money( 10, isset( $shown[ $product['sizes']['5x7'] ] ) ? $shown[ $product['sizes']['5x7'] ] : null, '5x7 shows its normal price' );
		$price_box = $xpath->query( '//*[@id="sunshine--product--details--price"]' )->item( 0 );
		$range     = $price_box ? preg_replace( '/\s+/', ' ', $price_box->textContent ) : '';
		$t::same( true, false !== strpos( $range, '10.00' ) && false !== strpos( $range, '26.00' ), 'price range shows 10.00 to 26.00 (shown: ' . trim( $range ) . ')' );
	}
);

$woo_test(
	'WooCommerce: a product without options added from the gallery popup',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_calculate, $woo_lines, $woo_popup_form, $woo_send_popup_form ) {
		$level    = $woo_level( 'Wedding' );
		$product  = $woo_product( 20, array( 'level_prices' => array( $level => '25' ) ) );
		$gallery  = $woo_gallery( $level );
		$form     = $woo_popup_form( $gallery['photos'][0], $product, array( 'quantity' => 3 ) );
		$response = $woo_send_popup_form( $form );
		$t::same( true, ! empty( $response['success'] ), 'added' );
		$cart  = $woo_calculate();
		$lines = $woo_lines();
		$t::same( 1, count( $lines ), 'one cart line' );
		$t::same( $gallery['photos'][0], isset( $lines[0]['sunshine']['image_id'] ) ? (int) $lines[0]['sunshine']['image_id'] : null, 'line carries the photo' );
		// 3 x 25.
		$t::money( 75, $cart->get_total( 'edit' ), 'total' );
	}
);

// A price level sent with the request only counts for products with no photo or gallery.
$woo_test(
	'WooCommerce: a customer can\'t pick a cheaper price level for a photo',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_ajax, $woo_calculate ) {
		$wedding = $woo_level( 'Wedding' );
		$cheap   = $woo_level( 'Cheap' );
		$product = $woo_product(
			20,
			array(
				'level_prices' => array(
					$wedding => '25',
					$cheap   => '5',
				),
			)
		);
		$gallery  = $woo_gallery( $wedding );
		$response = $woo_ajax(
			array( 'SPC_WC_Modal', 'add_item_to_cart' ),
			array(
				'product_id'  => $product,
				'image_id'    => $gallery['photos'][0],
				'gallery_id'  => $gallery['id'],
				'qty'         => 2,
				'price_level' => $cheap,
			)
		);
		$t::same( true, ! empty( $response['success'] ), 'added' );
		$cart = $woo_calculate();
		$t::money( 50, $cart->get_total( 'edit' ), 'total: 2 x 25, not 2 x 5' );
	}
);

$woo_test(
	'WooCommerce: a product added from its WooCommerce product page with the photo',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_ajax, $woo_form_post, $woo_calculate, $woo_lines ) {
		$level    = $woo_level( 'Wedding' );
		$product  = $woo_product(
			20,
			array(
				'add_mode'     => 'product_page',
				'level_prices' => array( $level => '25' ),
			)
		);
		$gallery  = $woo_gallery( $level );
		$response = $woo_ajax(
			array( 'SPC_WC_Modal', 'product_details' ),
			array(
				'image_id'   => $gallery['photos'][0],
				'product_id' => $product,
			)
		);
		$url = isset( $response['data']['url'] ) ? $response['data']['url'] : '';
		$t::same( true, '' !== $url, 'popup sends the customer to the product page' );
		$args = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );

		// The product page form posts the photo fields with its Add to cart button.
		$woo_form_post(
			array(
				'add-to-cart'      => $product,
				'quantity'         => 2,
				'sunshine_image'   => isset( $args['sunshine_image'] ) ? $args['sunshine_image'] : '',
				'sunshine_gallery' => isset( $args['sunshine_gallery'] ) ? $args['sunshine_gallery'] : '',
				'sunshine_token'   => isset( $args['sunshine_token'] ) ? $args['sunshine_token'] : '',
			)
		);
		// Someone changes the link: without a matching token it's a normal shop purchase.
		$woo_form_post(
			array(
				'add-to-cart'      => $product,
				'quantity'         => 1,
				'sunshine_image'   => $gallery['photos'][0],
				'sunshine_gallery' => $gallery['id'],
				'sunshine_token'   => 'not-the-real-token',
			)
		);
		wc_clear_notices();
		$cart  = $woo_calculate();
		$lines = $woo_lines();
		$t::same( 2, count( $lines ), 'two lines' );
		$t::money( 50, isset( $lines[0] ) ? $lines[0]['line_total'] : 0, 'product page line: 2 x 25' );
		$t::money( 20, isset( $lines[1] ) ? $lines[1]['line_total'] : 0, 'line without a valid link: normal price' );
		$t::money( 70, $cart->get_total( 'edit' ), 'total' );
	}
);

/*
 * Favorites and bulk adds: every photo is priced at its own level.
 */

$woo_test(
	'WooCommerce: adding favorites from two galleries prices each photo at its own level',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_ajax, $woo_calculate, $woo_lines ) {
		$wedding  = $woo_level( 'Wedding' );
		$school   = $woo_level( 'School' );
		$product  = $woo_product(
			20,
			array(
				'level_prices' => array(
					$wedding => '25',
					$school  => '12',
				),
			)
		);
		$gallery1 = $woo_gallery( $wedding );
		$gallery2 = $woo_gallery( $school );
		$response = $woo_ajax(
			array( 'SPC_WC_Modal', 'add_favorites_to_cart' ),
			array(
				'product_id' => $product,
				'qty'        => 2,
				'imageIds'   => $gallery1['photos'][0] . ',' . $gallery2['photos'][0],
			)
		);
		$t::same( 2, isset( $response['data']['added_count'] ) ? (int) $response['data']['added_count'] : null, 'both photos added' );
		$cart  = $woo_calculate();
		$lines = $woo_lines();
		$t::money( 50, $lines[0]['line_total'], 'wedding photo: 2 x 25' );
		$t::money( 24, $lines[1]['line_total'], 'school photo: 2 x 12' );
		$t::money( 74, $cart->get_total( 'edit' ), 'total' );
	}
);

$woo_test(
	'WooCommerce: a bulk add from the popup prices each photo at its own level',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_calculate, $woo_lines, $woo_popup_form, $woo_send_popup_form, $woo_photo_level ) {
		$wedding = $woo_level( 'Wedding' );
		$hero    = $woo_level( 'Hero photo' );
		$product = $woo_product(
			20,
			array(
				'level_prices' => array(
					$wedding => '25',
					$hero    => '30',
				),
			)
		);
		$gallery = $woo_gallery( $wedding, 2 );
		$woo_photo_level( $gallery['photos'][1], $hero );
		$form     = $woo_popup_form( $gallery['photos'][0], $product, array( 'quantity' => 1 ), $gallery['photos'] );
		$response = $woo_send_popup_form( $form );
		$t::same( 2, isset( $response['data']['added_count'] ) ? (int) $response['data']['added_count'] : null, 'both photos added' );
		$cart  = $woo_calculate();
		$lines = $woo_lines();
		$t::same( 2, count( $lines ), 'one line per photo' );
		$t::money( 25, isset( $lines[0] ) ? $lines[0]['line_total'] : 0, 'first photo at the gallery level' );
		$t::money( 30, isset( $lines[1] ) ? $lines[1]['line_total'] : 0, 'second photo at its own level' );
		$t::money( 55, $cart->get_total( 'edit' ), 'total' );
	}
);

$woo_test(
	'WooCommerce: a photo from a gallery that has expired is taken out of the cart',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_check_cart ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product( 20, array( 'level_prices' => array( $level => '25' ) ) );
		$gallery = $woo_gallery( $level );
		$woo_add( $product, $gallery['photos'][0], 1 );
		$t::money( 25, $woo_calculate()->get_total( 'edit' ), 'total before' );
		// The gallery's expiration passes (saved as a timestamp, like the gallery screen does).
		update_post_meta( $gallery['id'], 'end_date', current_time( 'timestamp' ) - DAY_IN_SECONDS );
		$cart = $woo_check_cart();
		$t::same( 0, $cart->get_cart_contents_count(), 'line taken out' );
		$t::money( 0, $cart->get_total( 'edit' ), 'nothing left to charge' );
	}
);

/*
 * Packages and multi-image products.
 */

// A package is sold at the package's price: what is inside it is not charged again.
$woo_test(
	'WooCommerce: a package costs its own level price, not what is inside it',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_lines, $woo_error_notices, $woo_place_order, $woo_cart_snapshot, $woo_order_matches_cart ) {
		$level    = $woo_level( 'Wedding' );
		$print    = $woo_product( 20, array( 'level_prices' => array( $level => '25' ) ) );
		$download = $woo_product(
			40,
			array(
				'sunshine_type' => 'download',
				'level_prices'  => array( $level => '50' ),
			)
		);
		$package  = $woo_product(
			80,
			array(
				'name'          => 'Totals WC package',
				'sunshine_type' => 'package',
				'level_prices'  => array( $level => '75' ),
				'package_items' => array( array( $print, 2 ), array( $download, 1 ) ),
			)
		);
		$gallery  = $woo_gallery( $level, 3 );
		$key      = $woo_add( $package, $gallery['photos'][0], 1 );
		$cart     = $woo_calculate();
		// 75, not 2 x 25 + 50 = 100.
		$t::money( 75, $cart->get_total( 'edit' ), 'cart total' );

		// Checkout waits until every package item has its photos.
		do_action( 'woocommerce_checkout_process' );
		$t::same( 1, count( $woo_error_notices() ), 'checkout stopped while photos are missing' );
		wc_clear_notices();

		// The customer picks photos for each package item (how the selection window saves).
		do_action( 'sunshine_multi_image_select_images_item', array( $gallery['photos'][0], $gallery['photos'][1] ), $key, 0 );
		do_action( 'sunshine_multi_image_select_images_item', array( $gallery['photos'][2] ), $key, 1 );
		$cart = $woo_calculate();
		$t::money( 75, $cart->get_total( 'edit' ), 'cart total after choosing photos' );
		$seen  = $woo_cart_snapshot();
		$order = $woo_place_order();
		$woo_order_matches_cart( $order, $seen );
		$t::money( 75, $order->get_total(), 'order total' );
	}
);

$woo_test(
	'WooCommerce: a multi-image product is one line at one price',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_calculate, $woo_lines, $woo_popup_form, $woo_send_popup_form, $woo_place_order, $woo_cart_snapshot, $woo_order_matches_cart ) {
		$level    = $woo_level( 'Wedding' );
		$product  = $woo_product(
			45,
			array(
				'name'          => 'Totals WC collage',
				'sunshine_type' => 'multi_image',
				'image_count'   => 3,
				'level_prices'  => array( $level => '40' ),
			)
		);
		$gallery  = $woo_gallery( $level, 3 );
		$form     = $woo_popup_form( $gallery['photos'][0], $product, array( 'quantity' => 1 ), $gallery['photos'] );
		$response = $woo_send_popup_form( $form );
		$t::same( true, ! empty( $response['success'] ), 'added' );
		$cart  = $woo_calculate();
		$lines = $woo_lines();
		$t::same( 1, count( $lines ), 'one line for all 3 photos' );
		$t::same( 3, isset( $lines[0]['sunshine']['image_ids'] ) ? count( $lines[0]['sunshine']['image_ids'] ) : 0, 'line carries the 3 photos' );
		// One collage at the level price, not 3 x 40.
		$t::money( 40, $cart->get_total( 'edit' ), 'cart total' );
		$seen  = $woo_cart_snapshot();
		$order = $woo_place_order();
		$woo_order_matches_cart( $order, $seen );
		$items = array_values( $order->get_items() );
		$t::same( 3, isset( $items[0] ) ? count( (array) $items[0]->get_meta( '_sunshine_image_ids' ) ) : 0, 'order line keeps the 3 photos' );
	}
);

$woo_test(
	'WooCommerce: a multi-image product can\'t be ordered until all its photos are picked',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_add, $woo_calculate, $woo_error_notices ) {
		$level   = $woo_level( 'Wedding' );
		$product = $woo_product(
			45,
			array(
				'sunshine_type' => 'multi_image',
				'image_count'   => 3,
				'level_prices'  => array( $level => '40' ),
			)
		);
		$gallery = $woo_gallery( $level, 3 );
		$key     = $woo_add( $product, $gallery['photos'][0], 1 );
		$woo_calculate();
		do_action( 'woocommerce_checkout_process' );
		$t::same( 1, count( $woo_error_notices() ), 'checkout stopped with 1 of 3 photos' );
		wc_clear_notices();
		do_action( 'sunshine_multi_image_select_images_item', $gallery['photos'], $key, 'images' );
		do_action( 'woocommerce_checkout_process' );
		$t::same( 0, count( $woo_error_notices() ), 'checkout allowed with 3 of 3 photos' );
		$t::money( 40, $woo_calculate()->get_total( 'edit' ), 'total' );
	}
);

/*
 * Placed orders.
 */

$woo_test(
	'WooCommerce: the placed order matches the cart (levels, shop item, coupon, tax, shipping)',
	function () use ( $t, $woo_level, $woo_product, $woo_gallery, $woo_photo_level, $woo_add, $woo_calculate, $woo_coupon, $woo_tax, $woo_flat_shipping, $woo_cart_snapshot, $woo_place_order, $woo_order_matches_cart ) {
		$woo_tax( 10 );
		$woo_flat_shipping( 7.50 );
		$wedding = $woo_level( 'Wedding' );
		$hero    = $woo_level( 'Hero photo' );
		$product = $woo_product(
			20,
			array(
				'level_prices' => array(
					$wedding => '25',
					$hero    => '30',
				),
			)
		);
		$gallery = $woo_gallery( $wedding, 2 );
		$woo_photo_level( $gallery['photos'][1], $hero );
		$woo_add( $product, $gallery['photos'][0], 2 );
		$woo_add( $product, $gallery['photos'][1], 1 );
		WC()->cart->add_to_cart( $product, 1 );
		$t::same( true, $woo_coupon( 'percent', 10 ), 'coupon accepted' );
		$cart = $woo_calculate();
		// Lines 50 + 30 + 20 = 100. 10% off = 90. Tax 10% of 90 = 9.
		// Shipping 7.50 + 0.75 tax. Total 90 + 9 + 7.50 + 0.75 = 107.25.
		$t::money( 100, $cart->get_subtotal(), 'subtotal' );
		$t::money( 10, $cart->get_discount_total(), 'discount' );
		$t::money( 7.50, $cart->get_shipping_total(), 'shipping' );
		$t::money( 9.75, $cart->get_total_tax(), 'tax' );
		$t::money( 107.25, $cart->get_total( 'edit' ), 'total' );

		$seen  = $woo_cart_snapshot();
		$order = $woo_place_order();
		$woo_order_matches_cart( $order, $seen );
		$t::same( 'yes', $order->get_meta( '_sunshine_order' ), 'order marked as a gallery order' );
		$items = array_values( $order->get_items() );
		$t::same( $wedding, isset( $items[0] ) ? (int) $items[0]->get_meta( '_sunshine_price_level' ) : null, 'line 1 keeps its price level' );
		$t::same( $gallery['photos'][0], isset( $items[0] ) ? (int) $items[0]->get_meta( '_sunshine_image_id' ) : null, 'line 1 keeps its photo' );
		$t::same( $gallery['id'], isset( $items[0] ) ? (int) $items[0]->get_meta( '_sunshine_gallery_id' ) : null, 'line 1 keeps its gallery' );
		$t::same( $hero, isset( $items[1] ) ? (int) $items[1]->get_meta( '_sunshine_price_level' ) : null, 'line 2 keeps the photo\'s own level' );
		$t::same( '', isset( $items[2] ) ? (string) $items[2]->get_meta( '_sunshine_price_level' ) : null, 'shop line has no price level' );
	}
);

/*
 * Sunshine's own cart.
 */

// While WooCommerce runs the store, Sunshine's cart and payment methods must not take orders
// at Sunshine prices.
$woo_test(
	'WooCommerce: Sunshine\'s own cart stays empty and has no payment methods',
	function () use ( $t ) {
		$product_id = $t::product( 10 );
		$added      = SPC()->cart->add_item( $product_id, 0, '', '', array(), 2 );
		$t::same( true, empty( $added ), 'Sunshine cart refuses the item' );
		$cart = $t::calculate();
		$t::same( 0, count( (array) $cart->get_cart() ), 'Sunshine cart is empty' );
		$t::money( 0, $cart->get_total(), 'Sunshine cart total' );
		$t::same( 0, count( (array) SPC()->payment_methods->get_active_payment_methods() ), 'no Sunshine payment methods' );
	}
);
