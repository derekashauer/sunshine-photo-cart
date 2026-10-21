<?php
/**
 * WooCommerce add-on: can a shopper get a cheaper price level by changing the request?
 *
 * With the WooCommerce add-on on, every gallery product is bought from a photo: the gallery
 * popup, the favorites popup and the product page all send the photo, and the add-on sets the
 * price from the photo's price level (the photo's own level, or its gallery's). No page in this
 * mode adds a gallery product without a photo, and the add-on hides gallery products from the
 * shop on purpose ("a gallery product on the shop page has no image context").
 *
 * The add-to-cart code (SPC_WC_Modal::add_to_wc_cart) still reads a price_level field from the
 * request when no photo and no gallery are sent. These tests send the same requests the store's
 * pages send, with the photo taken out and a price level put in, the way a shopper could by
 * editing the request in their browser.
 *
 * The business rule used here (the same one as the core price-level-tampering tests): a shopper
 * pays a price they were shown. A price level used only by a private gallery for one client (a
 * "VIP" level) must not be reachable by typing its number into a request. Refusing the request
 * is fine, and so is charging the product's normal WooCommerce price, because anyone can buy the
 * product at that price in the shop. Only the private price is wrong.
 *
 * WooCommerce settings are changed only in memory, every outgoing web request is blocked, and
 * everything made here is deleted at the end of each test.
 */

$t = 'SPC_Totals_Test';

// What a test made or changed, so it can all be put back when the test ends.
$wcpl = (object) array(
	'filters'  => array(),
	'posts'    => array(),
	'orders'   => array(),
	'terms'    => array(),
	'post'     => array(),
	'request'  => array(),
	'warnings' => array(),
	'blocked'  => array(),
);

$wcpl_filter = function ( $hook, $callback, $priority = 10, $args = 1 ) use ( $wcpl ) {
	add_filter( $hook, $callback, $priority, $args );
	$wcpl->filters[] = array( $hook, $callback, $priority );
};

// A WooCommerce setting for this test only. Nothing is written to the database.
$wcpl_setting = function ( $name, $value ) use ( $wcpl_filter ) {
	$wcpl_filter(
		'pre_option_' . $name,
		function () use ( $value ) {
			return $value;
		}
	);
};

// The add-on remembers prices until the page load ends. A new page load starts with nothing.
$wcpl_forget_cached_prices = function () {
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

$wcpl_begin = function () use ( $t, $wcpl, $wcpl_filter, $wcpl_setting, $wcpl_forget_cached_prices ) {
	$t::require_plugin( 'sunshine_wc_init', 'WooCommerce add-on' );
	if ( ! function_exists( 'WC' ) || ! class_exists( 'SPC_WC_Modal' ) || ! class_exists( 'SPC_WC_Price_Levels' ) ) {
		$t::skip( 'WooCommerce is not active, so the WooCommerce add-on is not running' );
	}

	$wcpl->post    = $_POST;
	$wcpl->request = $_REQUEST;
	$_POST         = array();
	$_REQUEST      = array();

	// Deleting a test gallery must not queue a background job to delete its photos.
	$t::option( 'delete_images', false );
	// Galleries here sell products (proofing-only mode stops every purchase).
	$t::option( 'proofing', '' );

	// A US store in Colorado that charges in dollars, with tax and shipping off.
	$wcpl_setting( 'woocommerce_default_country', 'US:CO' );
	$wcpl_setting( 'woocommerce_allowed_countries', 'all' );
	$wcpl_setting( 'woocommerce_ship_to_countries', '' );
	$wcpl_setting( 'woocommerce_currency', 'USD' );
	$wcpl_setting( 'woocommerce_price_num_decimals', '2' );
	$wcpl_setting( 'woocommerce_calc_taxes', 'no' );
	$wcpl_setting( 'woocommerce_prices_include_tax', 'no' );
	$wcpl_setting( 'woocommerce_tax_based_on', 'base' );
	$wcpl_setting( 'woocommerce_tax_round_at_subtotal', 'no' );
	$wcpl_setting( 'woocommerce_tax_display_cart', 'excl' );
	$wcpl_setting( 'woocommerce_shipping_tax_class', 'inherit' );
	$wcpl_setting( 'woocommerce_calc_discounts_sequentially', 'no' );
	$wcpl_setting( 'woocommerce_enable_coupons', 'yes' );
	$wcpl_setting( 'woocommerce_cart_redirect_after_add', 'no' );
	$wcpl_filter( 'woocommerce_cart_needs_shipping', '__return_false' );

	// No browser to send cookies to, no background jobs from test products, no web requests.
	$no_job = function () {
		return 0;
	};
	$wcpl_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
	$wcpl_filter( 'pre_as_schedule_single_action', $no_job );
	$wcpl_filter( 'pre_as_enqueue_async_action', $no_job );
	$wcpl_filter( 'pre_as_schedule_recurring_action', $no_job );
	$wcpl_filter( 'pre_as_schedule_cron_action', $no_job );
	$wcpl_filter(
		'pre_http_request',
		function ( $pre, $args, $url ) use ( $wcpl ) {
			$wcpl->blocked[] = $url;
			return new WP_Error( 'spc_totals_blocked', 'Web requests are blocked in the totals tests' );
		},
		1,
		3
	);

	$wcpl_forget_cached_prices();
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

$wcpl_end = function () use ( $wcpl, $wcpl_forget_cached_prices ) {
	if ( function_exists( 'WC' ) && WC()->cart ) {
		wc_clear_notices();
		WC()->cart->empty_cart();
		if ( WC()->session ) {
			// Also drops anything the test saved to the session table.
			WC()->session->destroy_session();
		}
	}
	foreach ( array_reverse( $wcpl->orders ) as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	foreach ( array_reverse( $wcpl->posts ) as $post_id ) {
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
	foreach ( $wcpl->terms as $term_id ) {
		if ( term_exists( (int) $term_id, 'sunshine-product-price-level' ) ) {
			wp_delete_term( $term_id, 'sunshine-product-price-level' );
		}
	}
	$wcpl->orders = array();
	$wcpl->posts  = array();
	$wcpl->terms  = array();

	foreach ( $wcpl->filters as $filter ) {
		remove_filter( $filter[0], $filter[1], $filter[2] );
	}
	$wcpl->filters = array();

	if ( class_exists( 'SPC_WC_Modal' ) ) {
		SPC_WC_Modal::clear_render_context();
	}
	unset( $GLOBALS['product'] );
	$_POST    = $wcpl->post;
	$_REQUEST = $wcpl->request;
	$wcpl_forget_cached_prices();
};

// Every test in this file starts from the same WooCommerce store and puts everything back after.
// PHP warnings, notices and deprecations raised while it runs are kept and listed at the end.
$wcpl_test = function ( $name, $callback ) use ( $t, $wcpl, $wcpl_begin, $wcpl_end ) {
	$t::test(
		$name,
		function () use ( $wcpl, $callback, $wcpl_begin, $wcpl_end ) {
			set_error_handler(
				function ( $errno, $errstr, $errfile, $errline ) use ( $wcpl ) {
					if ( error_reporting() & $errno ) {
						$wcpl->warnings[] = $errstr . ' (' . basename( $errfile ) . ':' . $errline . ')';
					}
					return false;
				}
			);
			try {
				$wcpl_begin();
				$callback();
			} finally {
				$wcpl_end();
				restore_error_handler();
			}
		}
	);
};

/*
 * Fixtures, saved in the same shape the admin screens save them.
 */

// A price level, like Products > Price Levels > Add. "Zz" keeps test levels at the end of the
// list, so the store's default level (the first one by name) does not change while tests run.
// The Price Levels add-on copies prices onto every product when a level is made while a form
// with price_level and percent is posted, so nothing is posted here.
$wcpl_level = function ( $name ) use ( $t, $wcpl ) {
	$saved_post = $_POST;
	$_POST      = array();
	$term       = wp_insert_term( 'Zz Totals WCPL ' . $name . ' ' . wp_rand( 10000, 99999 ), 'sunshine-product-price-level' );
	$_POST      = $saved_post;
	if ( is_wp_error( $term ) ) {
		throw new Exception( 'Could not create price level: ' . $term->get_error_message() );
	}
	$t::track( 'term', $term['term_id'], 'sunshine-product-price-level' );
	$wcpl->terms[] = (int) $term['term_id'];
	return (int) $term['term_id'];
};

// Save the Sunshine tab of the WooCommerce product screen through the add-on's own save handler.
// The form has a price box for every price level on the site. A level marked "not available"
// sends only its checkbox, because the form disables its price box.
$wcpl_save_sunshine_tab = function ( $product_id, $args ) {
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

	$saved_post = $_POST;
	$_POST      = wp_slash( $form );
	$product    = wc_get_product( $product_id );
	sunshine_wc_save_product_data( $product );
	$product->save();
	$_POST = $saved_post;
};

// A WooCommerce product offered in galleries. $args: name, taxable, add_mode,
// level_prices (level ID => price, '' or 'off').
$wcpl_product = function ( $price, $args = array() ) use ( $t, $wcpl, $wcpl_save_sunshine_tab ) {
	$args    = wp_parse_args(
		$args,
		array(
			'name'    => 'Totals WCPL print',
			'taxable' => true,
		)
	);
	$product = new WC_Product_Simple();
	$product->set_name( $args['name'] );
	$product->set_regular_price( (string) $price );
	$product->set_tax_status( $args['taxable'] ? 'taxable' : 'none' );
	$product->set_status( 'publish' );
	$id = $product->save();
	$t::track( 'post', $id );
	$wcpl->posts[] = $id;
	$wcpl_save_sunshine_tab( $id, $args );
	return $id;
};

// A product with a Size option (WooCommerce variations). $sizes: size => array( price, level_prices ).
// Each variation's level price boxes are saved through the add-on's variation save handler.
$wcpl_product_with_sizes = function ( $sizes, $args = array() ) use ( $t, $wcpl, $wcpl_save_sunshine_tab ) {
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
	$product->set_name( 'Totals WCPL print with sizes' );
	$product->set_attributes( array( $attribute ) );
	$product->set_tax_status( 'taxable' );
	$product->set_status( 'publish' );
	$id = $product->save();
	$t::track( 'post', $id );
	$wcpl->posts[] = $id;

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
		$wcpl->posts[] = $variation_id;

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
	$wcpl_save_sunshine_tab( $id, $args );
	return array(
		'id'    => $id,
		'sizes' => $variation_ids,
	);
};

// A published gallery with photos, like the gallery screen and uploader save it.
// $status is 'default', 'password' or 'private'; $meta sets more fields (password, private_users,
// end_date).
$wcpl_gallery = function ( $level_id = 0, $status = 'default', $meta = array(), $photos = 1 ) use ( $t, $wcpl ) {
	$gallery_id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-gallery',
			'post_status' => 'publish',
			'post_title'  => 'Totals WCPL gallery',
		)
	);
	if ( ! $gallery_id || is_wp_error( $gallery_id ) ) {
		throw new Exception( 'Could not create a gallery' );
	}
	$t::track( 'post', $gallery_id );
	$wcpl->posts[] = $gallery_id;
	$fields        = array_merge(
		array(
			'status'           => $status,
			'access_type'      => '',
			'disable_products' => '',
			'price_level'      => $level_id ? (string) $level_id : '',
		),
		$meta
	);
	foreach ( $fields as $key => $value ) {
		update_post_meta( $gallery_id, $key, $value );
	}
	$image_ids = array();
	for ( $i = 0; $i < $photos; $i++ ) {
		$name     = 'totals-wcpl-photo-' . wp_rand( 100000, 999999 );
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
		$wcpl->posts[] = $image_id;
		$image_ids[]   = $image_id;
	}
	sunshine_get_gallery( $gallery_id )->set_image_ids( $image_ids );
	return array(
		'id'     => $gallery_id,
		'photos' => $image_ids,
	);
};

// The photographer's VIP setup: a cheap price level used only by a private gallery for one
// client. The shopper in the tests is someone else, so they were never shown these prices.
$wcpl_vip = function () use ( $t, $wcpl_level, $wcpl_gallery ) {
	$level  = $wcpl_level( 'VIP' );
	$client = wp_insert_user(
		array(
			'user_login' => 'spcwcpl' . wp_rand( 100000, 999999 ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'sunshine_customer',
		)
	);
	if ( is_wp_error( $client ) ) {
		throw new Exception( 'Could not make the VIP client' );
	}
	$t::track( 'user', $client );
	$gallery = $wcpl_gallery( $level, 'private', array( 'private_users' => array( (int) $client ) ) );
	return array(
		'level'   => $level,
		'gallery' => $gallery['id'],
		'photo'   => $gallery['photos'][0],
	);
};

// Send an AJAX request the way admin-ajax.php does: WordPress's wp_loaded hooks first (they
// include WooCommerce's own add-to-cart form handler), then every handler hooked to the action,
// in order, until one sends its JSON reply. The store's nonce is added unless the post has one.
$wcpl_ajax = function ( $action, $fields ) {
	if ( ! isset( $fields['security'] ) ) {
		$fields['security'] = wp_create_nonce( 'sunshinephotocart' );
	}
	$fields['action'] = $action;
	$saved_post       = $_POST;
	$saved_request    = $_REQUEST;
	$_POST            = wp_slash( $fields );
	$_REQUEST         = $_POST;
	$ajax             = '__return_true';
	$die              = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'wp_doing_ajax', $ajax );
	add_filter( 'wp_die_ajax_handler', $die );
	ob_start();
	try {
		WC_Form_Handler::add_to_cart_action();
		do_action( ( is_user_logged_in() ? 'wp_ajax_' : 'wp_ajax_nopriv_' ) . $action );
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
		$response = array(
			'success' => false,
			'raw'     => substr( wp_strip_all_tags( $output ), 0, 300 ),
		);
	}
	wc_clear_notices();
	return $response;
};

// The fields jQuery's serialize() sends from the first add-to-cart form in some HTML:
// inputs, selects and textareas with a name, but never buttons.
$wcpl_form_fields = function ( $html ) {
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

// Open a product for a photo in the gallery popup and read its WooCommerce form, plus the
// fields sunshine-wc.js adds when Add to cart is pressed. This is what the browser would send.
$wcpl_popup_form = function ( $image_id, $product_id ) use ( $wcpl_ajax, $wcpl_form_fields ) {
	$response = $wcpl_ajax(
		'sunshine_product_details',
		array(
			'image_id'   => $image_id,
			'product_id' => $product_id,
		)
	);
	if ( empty( $response['success'] ) || empty( $response['data']['html'] ) ) {
		throw new Exception( 'The product view did not load: ' . wp_json_encode( $response ) );
	}
	$form               = $wcpl_form_fields( $response['data']['html'] );
	$form['product_id'] = (string) $product_id;
	return $form;
};

$wcpl_calculate = function () {
	WC()->cart->calculate_totals();
	return WC()->cart;
};

$wcpl_lines = function () {
	return array_values( WC()->cart->get_cart() );
};

// A new page load: WooCommerce builds the cart again from what it saved in the session,
// with fresh product objects, and the add-on's remembered prices start empty.
$wcpl_next_page_load = function () use ( $wcpl_forget_cached_prices ) {
	WC()->cart->set_session();
	$wcpl_forget_cached_prices();
	WC()->cart->get_cart_from_session();
	WC()->cart->calculate_totals();
	return WC()->cart;
};

// The cart and checkout pages check every gallery line again before showing it.
$wcpl_check_cart = function () {
	do_action( 'woocommerce_check_cart_items' );
	wc_clear_notices();
	WC()->cart->calculate_totals();
	return WC()->cart;
};

// Turn on one tax rate for the whole store, without touching the tax rate table.
$wcpl_tax = function ( $percent ) use ( $wcpl_setting, $wcpl_filter ) {
	$wcpl_setting( 'woocommerce_calc_taxes', 'yes' );
	$wcpl_filter(
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
$wcpl_flat_shipping = function ( $cost ) use ( $wcpl_filter ) {
	$wcpl_filter( 'woocommerce_cart_needs_shipping', '__return_true', 20 );
	$wcpl_filter(
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
$wcpl_coupon = function ( $type, $amount ) use ( $t, $wcpl ) {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'spcwcpl' . wp_rand( 100000, 999999 ) );
	$coupon->set_discount_type( $type );
	$coupon->set_amount( (string) $amount );
	$id = $coupon->save();
	$t::track( 'post', $id );
	$wcpl->posts[] = $id;
	$applied       = WC()->cart->apply_coupon( $coupon->get_code() );
	wc_clear_notices();
	return (bool) $applied;
};

$wcpl_error_notices = function () {
	$messages = array();
	foreach ( wc_get_notices( 'error' ) as $notice ) {
		$messages[] = wp_strip_all_tags( $notice['notice'] );
	}
	return $messages;
};

// Press Place order: WooCommerce runs its cart checks, then saves the order from the cart.
// Returns null when the cart checks left nothing to order.
$wcpl_place_order = function () use ( $t, $wcpl, $wcpl_error_notices ) {
	WC()->cart->calculate_totals();
	do_action( 'woocommerce_check_cart_items' );
	if ( WC()->cart->is_empty() ) {
		wc_clear_notices();
		return null;
	}
	do_action( 'woocommerce_checkout_process' );
	$errors = $wcpl_error_notices();
	if ( $errors ) {
		throw new Exception( 'Checkout stopped: ' . implode( ' | ', $errors ) );
	}
	WC()->cart->calculate_totals();
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
	$wcpl->orders[] = $order_id;
	return wc_get_order( $order_id );
};

/**
 * After a changed request: the shopper pays the public price, or the store refused (or later
 * took out) the item. Either is fine. The private price is not. Returns whether a line is left.
 */
$wcpl_public_or_refused = function ( $public_total, $private_level, $label ) use ( $t, $wcpl_calculate, $wcpl_lines ) {
	$cart = $wcpl_calculate();
	if ( $cart->is_empty() ) {
		$t::same( true, true, $label . ': refused' );
		return false;
	}
	$t::money( $public_total, $cart->get_total( 'edit' ), $label . ': total at the public price' );
	foreach ( $wcpl_lines() as $i => $line ) {
		$level = isset( $line['sunshine']['price_level'] ) ? (int) $line['sunshine']['price_level'] : 0;
		$t::same( false, (int) $private_level === $level, $label . ': line ' . ( $i + 1 ) . ' is at the private price level' );
	}
	return true;
};

/*
 * No photo and no gallery in the request: the price level comes from the request.
 */

// The baseline: with no level in the request there is nothing to change, so the add-on uses the
// product's normal WooCommerce price (or refuses a line with no photo, which is also fine).
$wcpl_test(
	'WooCommerce price level: a product sent with no photo and no level costs its normal price',
	function () use ( $t, $wcpl_vip, $wcpl_product, $wcpl_ajax, $wcpl_public_or_refused ) {
		$vip     = $wcpl_vip();
		$product = $wcpl_product( 20, array( 'level_prices' => array( $vip['level'] => '5' ) ) );
		$wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id' => $product,
				'qty'        => 2,
			)
		);
		// 2 x 20 normal price = 40.
		$wcpl_public_or_refused( 40, $vip['level'], 'no level' );
	}
);

$wcpl_test(
	'WooCommerce price level: a product with no photo can\'t be bought at a private level by changing the request',
	function () use ( $t, $wcpl_vip, $wcpl_product, $wcpl_ajax, $wcpl_public_or_refused ) {
		$vip     = $wcpl_vip();
		$product = $wcpl_product( 20, array( 'level_prices' => array( $vip['level'] => '5' ) ) );

		// The popup sends the photo. The shopper takes it out and puts in the VIP level.
		$wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'price_level' => $vip['level'],
				'qty'         => 2,
			)
		);
		// 2 x 20 normal price = 40. The VIP price would make it 2 x 5 = 10.
		$wcpl_public_or_refused( 40, $vip['level'], 'print' );
	}
);

// The photographer gives VIP clients a free print at their level (price 0).
$wcpl_test(
	'WooCommerce price level: a level where the product is free can\'t be reached by changing the request',
	function () use ( $t, $wcpl_vip, $wcpl_product, $wcpl_ajax, $wcpl_public_or_refused ) {
		$vip     = $wcpl_vip();
		$product = $wcpl_product( 20, array( 'level_prices' => array( $vip['level'] => '0' ) ) );
		$wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'price_level' => $vip['level'],
				'qty'         => 4,
			)
		);
		// 4 x 20 = 80 at the normal price. The VIP price would make all 4 free.
		$wcpl_public_or_refused( 80, $vip['level'], 'free VIP print' );
	}
);

$wcpl_test(
	'WooCommerce price level: a product option with no photo can\'t be bought at a private level',
	function () use ( $t, $wcpl_vip, $wcpl_product_with_sizes, $wcpl_ajax, $wcpl_public_or_refused ) {
		$vip     = $wcpl_vip();
		$product = $wcpl_product_with_sizes(
			array(
				'8x10' => array(
					'price'        => '20',
					'level_prices' => array( $vip['level'] => '6' ),
				),
				'5x7'  => array( 'price' => '10' ),
			)
		);
		$wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product['id'],
				'price_level' => $vip['level'],
				'qty'         => 3,
				'options'     => array( 'attribute_size' => '8x10' ),
			)
		);
		// 3 x 20 = 60 at the 8x10's normal price. The VIP price would make it 3 x 6 = 18.
		$wcpl_public_or_refused( 60, $vip['level'], '8x10' );
	}
);

// The real popup sends WooCommerce's own form. The shopper empties the photo fields and adds
// a price_level field before it is sent.
$wcpl_test(
	'WooCommerce price level: the popup form can\'t be sent with the photo taken out and a private level put in',
	function () use ( $t, $wcpl_vip, $wcpl_level, $wcpl_product, $wcpl_gallery, $wcpl_popup_form, $wcpl_ajax, $wcpl_public_or_refused ) {
		$vip     = $wcpl_vip();
		$wedding = $wcpl_level( 'Wedding' );
		$product = $wcpl_product(
			20,
			array(
				'level_prices' => array(
					$wedding      => '25',
					$vip['level'] => '5',
				),
			)
		);
		$gallery = $wcpl_gallery( $wedding );
		$form    = $wcpl_popup_form( $gallery['photos'][0], $product );
		$t::same( (string) $gallery['photos'][0], isset( $form['sunshine_image'] ) ? (string) $form['sunshine_image'] : null, 'the form carries the photo' );

		$form['sunshine_image']   = '';
		$form['sunshine_gallery'] = '';
		$form['price_level']      = (string) $vip['level'];
		$form['quantity']         = '3';
		$wcpl_ajax( 'sunshine_wc_add_form_to_cart', $form );
		// 3 x 20 = 60 at the normal price. The VIP price would make it 3 x 5 = 15.
		$wcpl_public_or_refused( 60, $vip['level'], 'popup form' );
	}
);

// The Price Levels screen deletes a level but leaves its old prices saved on each product.
$wcpl_test(
	'WooCommerce price level: the old prices of a deleted price level can\'t be used',
	function () use ( $t, $wcpl_level, $wcpl_product, $wcpl_ajax, $wcpl_public_or_refused ) {
		$old     = $wcpl_level( 'last year promo' );
		$product = $wcpl_product( 20, array( 'level_prices' => array( $old => '1' ) ) );
		wp_delete_term( $old, 'sunshine-product-price-level' );
		$t::same( false, (bool) term_exists( $old, 'sunshine-product-price-level' ), 'old level is gone' );
		$t::same( '1', (string) SPC_WC_Price_Levels::get_level_prices( wc_get_product( $product ) )[ $old ], 'its old price is still saved on the product' );

		$wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'price_level' => $old,
				'qty'         => 3,
			)
		);
		// 3 x 20 = 60 at the normal price. The deleted level's price would make it 3 x 1 = 3.
		$wcpl_public_or_refused( 60, $old, 'deleted level' );
	}
);

$wcpl_test(
	'WooCommerce price level: a made-up price level is refused or charged the normal price, never less',
	function () use ( $t, $wcpl_product, $wcpl_ajax, $wcpl_public_or_refused ) {
		$product = $wcpl_product( 20 );
		// The product's own ID, a negative number, a word, zero and a level that never existed.
		foreach ( array( $product, -3, 'abc', '0', 99999999 ) as $made_up ) {
			WC()->cart->empty_cart();
			$wcpl_ajax(
				'sunshine_modal_add_item_to_cart',
				array(
					'product_id'  => $product,
					'price_level' => $made_up,
					'qty'         => 1,
				)
			);
			// 1 x 20 at the normal price, or refused.
			$wcpl_public_or_refused( 20, -999, 'price level ' . var_export( $made_up, true ) );
		}
	}
);

$wcpl_test(
	'WooCommerce price level: a level marked not available for the product is refused when sent in the request',
	function () use ( $t, $wcpl_vip, $wcpl_product, $wcpl_ajax, $wcpl_calculate ) {
		$vip      = $wcpl_vip();
		$product  = $wcpl_product( 20, array( 'level_prices' => array( $vip['level'] => 'off' ) ) );
		$response = $wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'price_level' => $vip['level'],
				'qty'         => 1,
			)
		);
		$t::same( false, ! empty( $response['success'] ), 'request refused' );
		$cart = $wcpl_calculate();
		$t::same( 0, $cart->get_cart_contents_count(), 'cart is empty' );
		$t::money( 0, $cart->get_total( 'edit' ), 'total' );
	}
);

// The cart and checkout pages check every gallery line again. A line with no photo and no
// gallery has nothing for that check to look at, so the private price must not survive it.
$wcpl_test(
	'WooCommerce price level: a private level from a changed request does not last to checkout',
	function () use ( $t, $wcpl_vip, $wcpl_product, $wcpl_ajax, $wcpl_next_page_load, $wcpl_check_cart, $wcpl_public_or_refused ) {
		$vip     = $wcpl_vip();
		$product = $wcpl_product( 20, array( 'level_prices' => array( $vip['level'] => '5' ) ) );
		$wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'price_level' => $vip['level'],
				'qty'         => 2,
			)
		);
		$wcpl_next_page_load();
		$wcpl_check_cart();
		// 2 x 20 = 40 at the normal price, or taken out. The VIP price would be 2 x 5 = 10.
		$wcpl_public_or_refused( 40, $vip['level'], 'after the next page load and the checkout check' );
	}
);

// The money reaches the saved order: tax, a coupon and shipping on top.
$wcpl_test(
	'WooCommerce price level: the placed order is not at a private level from a changed request (tax, coupon, shipping)',
	function () use ( $t, $wcpl_vip, $wcpl_product, $wcpl_ajax, $wcpl_tax, $wcpl_flat_shipping, $wcpl_coupon, $wcpl_place_order, $wcpl_public_or_refused ) {
		$wcpl_tax( 10 );
		$wcpl_flat_shipping( 7.50 );
		$vip     = $wcpl_vip();
		$product = $wcpl_product( 30, array( 'level_prices' => array( $vip['level'] => '10' ) ) );
		$wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'price_level' => $vip['level'],
				'qty'         => 2,
			)
		);
		$t::same( true, $wcpl_coupon( 'percent', 10 ), 'coupon accepted' );
		// 2 x 30 = 60. 10% off = 54. Tax 10% of 54 = 5.40. Shipping 7.50 + 0.75 tax.
		// Total 54 + 5.40 + 7.50 + 0.75 = 67.65. At the VIP price: 20 - 2 + 1.80 + 8.25 = 28.05.
		if ( ! $wcpl_public_or_refused( 67.65, $vip['level'], 'cart' ) ) {
			return;
		}
		$order = $wcpl_place_order();
		if ( ! $order ) {
			$t::same( true, true, 'taken out at checkout' );
			return;
		}
		$t::money( 60, $order->get_subtotal(), 'order subtotal' );
		$t::money( 6, $order->get_discount_total(), 'order discount' );
		$t::money( 6.15, $order->get_total_tax(), 'order tax' );
		$t::money( 67.65, $order->get_total(), 'order total' );
		$items = array_values( $order->get_items() );
		$t::same( false, isset( $items[0] ) && (int) $vip['level'] === (int) $items[0]->get_meta( '_sunshine_price_level' ), 'order line is at the private price level' );
	}
);

// A multi-image product is finished in the cart: the photos are picked there, from any gallery
// the shopper can open. So a line added with no photo can still be completed and ordered.
$wcpl_test(
	'WooCommerce price level: a multi-image product added with no photo at a private level and finished in the cart',
	function () use ( $t, $wcpl_vip, $wcpl_product, $wcpl_gallery, $wcpl_ajax, $wcpl_lines, $wcpl_place_order, $wcpl_public_or_refused ) {
		$vip     = $wcpl_vip();
		$product = $wcpl_product(
			45,
			array(
				'name'          => 'Totals WCPL collage',
				'sunshine_type' => 'multi_image',
				'image_count'   => 3,
				'level_prices'  => array( $vip['level'] => '12' ),
			)
		);
		$public  = $wcpl_gallery( 0, 'default', array(), 3 );
		$wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'price_level' => $vip['level'],
				'qty'         => 1,
			)
		);
		$lines = $wcpl_lines();
		if ( $lines ) {
			// The cart's photo picker saves the 3 picks through this hook.
			$keys = array_keys( WC()->cart->get_cart() );
			do_action( 'sunshine_multi_image_select_images_item', $public['photos'], $keys[0], 'images' );
		}
		// One collage at its normal price, 45. The VIP price would make it 12.
		if ( ! $wcpl_public_or_refused( 45, $vip['level'], 'collage in the cart' ) ) {
			return;
		}
		$order = $wcpl_place_order();
		$t::money( 45, $order ? $order->get_total() : 0, 'order total' );
	}
);

$wcpl_test(
	'WooCommerce price level: prices include tax, a changed request does not lower the price',
	function () use ( $t, $wcpl_vip, $wcpl_product, $wcpl_ajax, $wcpl_tax, $wcpl_setting, $wcpl_calculate, $wcpl_public_or_refused ) {
		$wcpl_tax( 10 );
		$wcpl_setting( 'woocommerce_prices_include_tax', 'yes' );
		$vip     = $wcpl_vip();
		$product = $wcpl_product( 22, array( 'level_prices' => array( $vip['level'] => '11' ) ) );
		$wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'price_level' => $vip['level'],
				'qty'         => 1,
			)
		);
		// 22 includes 10% tax: 20 + 2. At the VIP price it would be 11 (10 + 1 tax).
		if ( $wcpl_public_or_refused( 22, $vip['level'], 'tax included' ) ) {
			$t::money( 2, $wcpl_calculate()->get_total_tax(), 'tax included' );
		}
	}
);

// Logged-in shoppers go through WordPress's other AJAX hook (wp_ajax_ instead of wp_ajax_nopriv_).
$wcpl_test(
	'WooCommerce price level: a logged-in customer can\'t use another client\'s private level by changing the request',
	function () use ( $t, $wcpl_vip, $wcpl_product, $wcpl_ajax, $wcpl_public_or_refused ) {
		$vip = $wcpl_vip();
		$t::customer();
		$product = $wcpl_product( 20, array( 'level_prices' => array( $vip['level'] => '5' ) ) );
		$wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'price_level' => $vip['level'],
				'qty'         => 2,
			)
		);
		// 2 x 20 = 40 at the normal price. The VIP price would make it 2 x 5 = 10.
		$wcpl_public_or_refused( 40, $vip['level'], 'logged-in customer' );
	}
);

// The other side of the rule: the client the private gallery was made for still gets its prices,
// all the way to the order.
$wcpl_test(
	'WooCommerce price level: the VIP client still gets their level for photos in their own private gallery',
	function () use ( $t, $wcpl_level, $wcpl_product, $wcpl_gallery, $wcpl_ajax, $wcpl_check_cart, $wcpl_place_order ) {
		$level   = $wcpl_level( 'VIP' );
		$client  = $t::customer();
		$product = $wcpl_product( 20, array( 'level_prices' => array( $level => '5' ) ) );
		$gallery = $wcpl_gallery( $level, 'private', array( 'private_users' => array( (int) $client ) ) );
		$response = $wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id' => $product,
				'image_id'   => $gallery['photos'][0],
				'gallery_id' => $gallery['id'],
				'qty'        => 3,
			)
		);
		$t::same( true, ! empty( $response['success'] ), 'added' );
		$cart = $wcpl_check_cart();
		// 3 x 5 at the VIP level.
		$t::money( 15, $cart->get_total( 'edit' ), 'total after the checkout check' );
		$order = $wcpl_place_order();
		$t::money( 15, $order ? $order->get_total() : 0, 'order total' );
		$items = $order ? array_values( $order->get_items() ) : array();
		$t::same( $level, isset( $items[0] ) ? (int) $items[0]->get_meta( '_sunshine_price_level' ) : null, 'order line keeps the VIP level' );
	}
);

/*
 * A gallery in the request, but no photo: the gallery decides the level.
 */

$wcpl_test(
	'WooCommerce price level: a public gallery named in the request decides the level, not the level sent with it',
	function () use ( $t, $wcpl_vip, $wcpl_level, $wcpl_product, $wcpl_gallery, $wcpl_ajax, $wcpl_public_or_refused ) {
		$vip     = $wcpl_vip();
		$wedding = $wcpl_level( 'Wedding' );
		$product = $wcpl_product(
			20,
			array(
				'level_prices' => array(
					$wedding      => '25',
					$vip['level'] => '5',
				),
			)
		);
		$gallery = $wcpl_gallery( $wedding );
		$wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id'  => $product,
				'gallery_id'  => $gallery['id'],
				'price_level' => $vip['level'],
				'qty'         => 2,
			)
		);
		// The shopper can open this gallery, so its price (2 x 25 = 50) is a price they were shown.
		$wcpl_public_or_refused( 50, $vip['level'], 'public gallery' );
	}
);

// The add step only checks that the gallery exists. The checkout check then asks whether the
// shopper may buy from it, so the private price must be gone by then.
$wcpl_test(
	'WooCommerce price level: naming a private or locked gallery in the request never gives its prices at checkout',
	function () use ( $t, $wcpl_vip, $wcpl_product, $wcpl_gallery, $wcpl_ajax, $wcpl_check_cart, $wcpl_public_or_refused ) {
		$vip     = $wcpl_vip();
		$product = $wcpl_product( 20, array( 'level_prices' => array( $vip['level'] => '5' ) ) );
		// A password gallery at the VIP level that this shopper has not unlocked.
		$locked = $wcpl_gallery( $vip['level'], 'password', array( 'password' => 'SECRET' . wp_rand( 1000, 9999 ) ) );

		foreach ( array( 'private gallery' => $vip['gallery'], 'locked gallery' => $locked['id'] ) as $label => $gallery_id ) {
			WC()->cart->empty_cart();
			$wcpl_ajax(
				'sunshine_modal_add_item_to_cart',
				array(
					'product_id' => $product,
					'gallery_id' => $gallery_id,
					'qty'        => 2,
				)
			);
			$wcpl_check_cart();
			// Taken out, or 2 x 20 = 40 at the normal price. The VIP price would be 2 x 5 = 10.
			$wcpl_public_or_refused( 40, $vip['level'], $label );
		}
	}
);

$wcpl_test(
	'WooCommerce price level: naming a gallery that does not exist does not keep a private level',
	function () use ( $t, $wcpl_vip, $wcpl_product, $wcpl_ajax, $wcpl_check_cart, $wcpl_public_or_refused ) {
		$vip     = $wcpl_vip();
		$product = $wcpl_product( 20, array( 'level_prices' => array( $vip['level'] => '5' ) ) );
		// A number that is no post at all, and the product's own ID (a post, but not a gallery).
		foreach ( array( 'no such post' => 999999999, 'not a gallery' => $product ) as $label => $gallery_id ) {
			WC()->cart->empty_cart();
			$wcpl_ajax(
				'sunshine_modal_add_item_to_cart',
				array(
					'product_id'  => $product,
					'gallery_id'  => $gallery_id,
					'price_level' => $vip['level'],
					'qty'         => 1,
				)
			);
			$wcpl_check_cart();
			// Taken out, or 20 at the normal price. Never 5.
			$wcpl_public_or_refused( 20, $vip['level'], $label );
		}
	}
);

/*
 * A photo in the request: the photo (or its gallery) decides the level.
 */

// The existing WooCommerce tests check the popup's item request. These check the other doors:
// the popup's WooCommerce form, favorites, and the product page, each with a level added.
$wcpl_test(
	'WooCommerce price level: the photo\'s level wins over a level sent with the popup form, favorites or product page',
	function () use ( $t, $wcpl_vip, $wcpl_level, $wcpl_product, $wcpl_gallery, $wcpl_popup_form, $wcpl_ajax, $wcpl_calculate, $wcpl_lines ) {
		$vip     = $wcpl_vip();
		$wedding = $wcpl_level( 'Wedding' );
		$prices  = array(
			'level_prices' => array(
				$wedding      => '25',
				$vip['level'] => '5',
			),
		);
		$product      = $wcpl_product( 20, $prices );
		$page_product = $wcpl_product( 20, array_merge( $prices, array( 'add_mode' => 'product_page' ) ) );
		$gallery      = $wcpl_gallery( $wedding, 'default', array(), 2 );

		// Popup form, with a level added.
		$form                = $wcpl_popup_form( $gallery['photos'][0], $product );
		$form['price_level'] = (string) $vip['level'];
		$form['quantity']    = '1';
		$response            = $wcpl_ajax( 'sunshine_wc_add_form_to_cart', $form );
		$t::same( true, ! empty( $response['success'] ), 'popup form: added' );

		// Favorites, with a level added.
		$response = $wcpl_ajax(
			'sunshine_modal_add_favorites_to_cart',
			array(
				'product_id'  => $product,
				'qty'         => 1,
				'imageIds'    => (string) $gallery['photos'][1],
				'price_level' => $vip['level'],
			)
		);
		$t::same( 1, isset( $response['data']['added_count'] ) ? (int) $response['data']['added_count'] : null, 'favorites: added' );

		// Product page: the popup hands out a signed link, and the page's form posts it back.
		$response = $wcpl_ajax(
			'sunshine_product_details',
			array(
				'image_id'   => $gallery['photos'][0],
				'product_id' => $page_product,
			)
		);
		$args = array();
		parse_str( (string) wp_parse_url( isset( $response['data']['url'] ) ? $response['data']['url'] : '', PHP_URL_QUERY ), $args );
		$t::same( true, ! empty( $args['sunshine_token'] ), 'popup sends the shopper to the product page' );
		$saved_post    = $_POST;
		$saved_request = $_REQUEST;
		$_POST         = wp_slash(
			array(
				'add-to-cart'      => $page_product,
				'quantity'         => 1,
				'sunshine_image'   => isset( $args['sunshine_image'] ) ? $args['sunshine_image'] : '',
				'sunshine_gallery' => isset( $args['sunshine_gallery'] ) ? $args['sunshine_gallery'] : '',
				'sunshine_token'   => isset( $args['sunshine_token'] ) ? $args['sunshine_token'] : '',
				'price_level'      => $vip['level'],
			)
		);
		$_REQUEST      = $_POST;
		try {
			WC_Form_Handler::add_to_cart_action();
		} finally {
			$_POST    = $saved_post;
			$_REQUEST = $saved_request;
		}
		wc_clear_notices();

		$cart  = $wcpl_calculate();
		$lines = $wcpl_lines();
		$t::same( 3, count( $lines ), 'three lines' );
		foreach ( $lines as $i => $line ) {
			$t::money( 25, $line['line_total'], 'line ' . ( $i + 1 ) . ' at the gallery level' );
		}
		// 3 x 25. With the VIP level it would be 3 x 5 = 15.
		$t::money( 75, $cart->get_total( 'edit' ), 'total' );
	}
);

$wcpl_test(
	'WooCommerce price level: a photo from a private gallery the shopper can\'t open is refused',
	function () use ( $t, $wcpl_vip, $wcpl_product, $wcpl_ajax, $wcpl_calculate ) {
		$vip      = $wcpl_vip();
		$product  = $wcpl_product( 20, array( 'level_prices' => array( $vip['level'] => '5' ) ) );
		$response = $wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id' => $product,
				'image_id'   => $vip['photo'],
				'qty'        => 2,
			)
		);
		$t::same( false, ! empty( $response['success'] ), 'request refused' );
		$t::money( 0, $wcpl_calculate()->get_total( 'edit' ), 'nothing in the cart' );
	}
);

// A locked (password) gallery's photos can be added, because the add step only asks whether
// the photo is in a private gallery. The checkout check then asks whether the gallery can be
// bought from, and takes the line out.
$wcpl_test(
	'WooCommerce price level: a photo from a locked or expired gallery is taken out at checkout',
	function () use ( $t, $wcpl_level, $wcpl_product, $wcpl_gallery, $wcpl_ajax, $wcpl_check_cart ) {
		$vip     = $wcpl_level( 'VIP' );
		$product = $wcpl_product( 20, array( 'level_prices' => array( $vip => '5' ) ) );
		$locked  = $wcpl_gallery( $vip, 'password', array( 'password' => 'SECRET' . wp_rand( 1000, 9999 ) ) );
		$expired = $wcpl_gallery( $vip, 'default', array( 'end_date' => current_time( 'timestamp' ) - DAY_IN_SECONDS ) );

		foreach ( array( 'locked gallery' => $locked, 'expired gallery' => $expired ) as $label => $gallery ) {
			WC()->cart->empty_cart();
			$wcpl_ajax(
				'sunshine_modal_add_item_to_cart',
				array(
					'product_id' => $product,
					'image_id'   => $gallery['photos'][0],
					'gallery_id' => $gallery['id'],
					'qty'        => 2,
				)
			);
			$cart = $wcpl_check_cart();
			$t::same( 0, $cart->get_cart_contents_count(), $label . ': taken out' );
			$t::money( 0, $cart->get_total( 'edit' ), $label . ': nothing to charge' );
		}
	}
);

// The same photo, but the request names a public gallery next to it. The line keeps the
// photo's level (the locked gallery's VIP level) but is checked against the public gallery.
$wcpl_test(
	'WooCommerce price level: a photo from a locked or expired gallery can\'t be bought at its price by naming a public gallery',
	function () use ( $t, $wcpl_level, $wcpl_product, $wcpl_gallery, $wcpl_ajax, $wcpl_check_cart, $wcpl_calculate, $wcpl_place_order ) {
		$vip     = $wcpl_level( 'VIP' );
		$product = $wcpl_product( 20, array( 'level_prices' => array( $vip => '5' ) ) );
		$public  = $wcpl_gallery( 0 );
		$locked  = $wcpl_gallery( $vip, 'password', array( 'password' => 'SECRET' . wp_rand( 1000, 9999 ) ) );
		$expired = $wcpl_gallery( $vip, 'default', array( 'end_date' => current_time( 'timestamp' ) - DAY_IN_SECONDS ) );

		$doors = array(
			'popup item request' => function ( $photo ) use ( $wcpl_ajax, $product, $public ) {
				$wcpl_ajax(
					'sunshine_modal_add_item_to_cart',
					array(
						'product_id' => $product,
						'image_id'   => $photo,
						'gallery_id' => $public['id'],
						'qty'        => 2,
					)
				);
			},
			'popup form'         => function ( $photo ) use ( $wcpl_ajax, $product, $public ) {
				$wcpl_ajax(
					'sunshine_wc_add_form_to_cart',
					array(
						'product_id'       => $product,
						'quantity'         => 2,
						'sunshine_image'   => $photo,
						'sunshine_gallery' => $public['id'],
					)
				);
			},
		);

		foreach ( array( 'locked gallery' => $locked, 'expired gallery' => $expired ) as $label => $gallery ) {
			foreach ( $doors as $door => $send ) {
				WC()->cart->empty_cart();
				$send( $gallery['photos'][0] );
				$cart = $wcpl_check_cart();
				// The photo can't be bought from its own gallery, so it must be taken out like it
				// is when the request names nothing else. Kept, it costs 2 x 5 = 10 at VIP prices.
				$t::same( 0, $cart->get_cart_contents_count(), $label . ', ' . $door . ': taken out' );
				$t::money( 0, $cart->get_total( 'edit' ), $label . ', ' . $door . ': nothing to charge' );
			}
		}

		// What reaches the order when the locked photo stays in the cart.
		WC()->cart->empty_cart();
		$doors['popup item request']( $locked['photos'][0] );
		$order = $wcpl_place_order();
		$t::money( 0, $order ? $order->get_total() : 0, 'order placed for a photo from the locked gallery' );
	}
);

/*
 * Rules agreed after the tests above (Derek, 2026-10-17): price level 0, the product's normal
 * WooCommerce price, is right for products sold outside a gallery, so it stays. Instead the
 * add-on refuses products that are always bought from a photo when no photo is sent, and a
 * gallery named in the request has to be one the shopper can buy from.
 */

// Prints, multi-image products and packages are only ever added from a photo. Without one the
// line would get level 0, which can be less than the gallery's level or a product the
// gallery doesn't sell at all.
$wcpl_test(
	'WooCommerce price level: a print, multi-image product or package sent with no photo is refused',
	function () use ( $t, $wcpl_level, $wcpl_product, $wcpl_gallery, $wcpl_ajax, $wcpl_calculate ) {
		$wedding = $wcpl_level( 'Wedding' );
		$gallery = $wcpl_gallery( $wedding );
		$types   = array(
			'print'       => '',
			'multi-image' => 'multi_image',
			'package'     => 'package',
		);
		foreach ( $types as $label => $type ) {
			// 20 normally, 25 in the Wedding gallery.
			$product = $wcpl_product(
				20,
				array(
					'name'          => 'Totals WCPL ' . $label,
					'sunshine_type' => $type,
					'image_count'   => 'multi_image' === $type ? 2 : '',
					'level_prices'  => array( $wedding => '25' ),
				)
			);
			$requests = array(
				'nothing named'          => array(),
				'only the gallery named' => array( 'gallery_id' => $gallery['id'] ),
			);
			foreach ( $requests as $request_label => $fields ) {
				WC()->cart->empty_cart();
				$response = $wcpl_ajax(
					'sunshine_modal_add_item_to_cart',
					array_merge(
						array(
							'product_id' => $product,
							'qty'        => 1,
						),
						$fields
					)
				);
				$t::same( false, ! empty( $response['success'] ), $label . ', ' . $request_label . ': refused' );
				$t::same( 0, $wcpl_calculate()->get_cart_contents_count(), $label . ', ' . $request_label . ': cart is empty' );
			}
		}
	}
);

// A download with no photo can still be named with a gallery. The gallery decides its level,
// so it has to be a gallery the shopper can buy from, checked when it is added.
$wcpl_test(
	'WooCommerce price level: a gallery the shopper can\'t open is refused when added, an open one sets the level',
	function () use ( $t, $wcpl_vip, $wcpl_level, $wcpl_product, $wcpl_gallery, $wcpl_ajax, $wcpl_calculate ) {
		$vip     = $wcpl_vip();
		$wedding = $wcpl_level( 'Wedding' );
		$product = $wcpl_product(
			20,
			array(
				'name'          => 'Totals WCPL download',
				'sunshine_type' => 'download',
				'level_prices'  => array(
					$vip['level'] => '5',
					$wedding      => '25',
				),
			)
		);
		$locked  = $wcpl_gallery( $vip['level'], 'password', array( 'password' => 'SECRET' . wp_rand( 1000, 9999 ) ) );
		$expired = $wcpl_gallery( $vip['level'], 'default', array( 'end_date' => current_time( 'timestamp' ) - DAY_IN_SECONDS ) );

		$closed = array(
			'private gallery' => $vip['gallery'],
			'locked gallery'  => $locked['id'],
			'expired gallery' => $expired['id'],
		);
		foreach ( $closed as $label => $gallery_id ) {
			WC()->cart->empty_cart();
			$response = $wcpl_ajax(
				'sunshine_modal_add_item_to_cart',
				array(
					'product_id' => $product,
					'gallery_id' => $gallery_id,
					'qty'        => 1,
				)
			);
			// Refused right away, not only later at checkout. Kept, it would cost 5.
			$t::same( false, ! empty( $response['success'] ), $label . ': refused' );
			$t::same( 0, $wcpl_calculate()->get_cart_contents_count(), $label . ': cart is empty' );
		}

		// A gallery anyone can open still sets its own price.
		WC()->cart->empty_cart();
		$open     = $wcpl_gallery( $wedding );
		$response = $wcpl_ajax(
			'sunshine_modal_add_item_to_cart',
			array(
				'product_id' => $product,
				'gallery_id' => $open['id'],
				'qty'        => 1,
			)
		);
		$t::same( true, ! empty( $response['success'] ), 'open gallery: added' );
		$t::money( 25, $wcpl_calculate()->get_total( 'edit' ), 'open gallery: at its Wedding price' );
	}
);

if ( $wcpl->blocked ) {
	foreach ( array_unique( $wcpl->blocked ) as $wcpl_url ) {
		WP_CLI::log( 'BLOCKED REQUEST (woocommerce-price-level) ' . $wcpl_url );
	}
}

if ( $wcpl->warnings ) {
	foreach ( array_unique( $wcpl->warnings ) as $wcpl_warning ) {
		WP_CLI::log( 'PHP WARNING (woocommerce-price-level) ' . $wcpl_warning );
	}
}
