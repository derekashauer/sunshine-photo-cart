<?php
/**
 * Shared setup for the api-price-writes-*.php tests: prices, credits, gift card balances and
 * discounts changed through the REST API (sunshine/v1) and the AI tools (MCP abilities) that
 * call it.
 *
 * These are the calls an AI helper makes to change a store. Every test changes only products,
 * price levels, option sets, gift cards, discounts and customers it made itself. Bulk calls are
 * always limited to the test's own products.
 *
 * Nothing these tests do may reach another site: every web request and every email is stopped.
 */

if ( ! isset( $apw_test ) ) {

	$t = 'SPC_Totals_Test';

	// PHP warnings, notices and deprecations raised while the tests ran, by test name.
	$apw_warnings = array();

	// Run one test with web requests, emails and order receipts stopped. A request that tried to
	// leave fails the test. Analytics rows and queued emails made by the test are deleted after.
	$apw_test = function ( $name, $body ) use ( $t, &$apw_warnings ) {
		$t::test(
			$name,
			function () use ( $t, $name, $body, &$apw_warnings ) {
				$sent    = array();
				$made    = array();
				$block   = function ( $pre, $args, $url ) use ( &$sent ) {
					$sent[] = $url;
					return new WP_Error( 'spc_totals_offline', 'Web requests are stopped by the totals tests.' );
				};
				$no      = function () {
					return false;
				};
				$track   = function ( $post_id, $post ) use ( &$made ) {
					if ( in_array( $post->post_type, array( 'sunshine-product', 'sunshine-order', 'sunshine-email-log' ), true ) ) {
						$made[ $post->post_type ][] = (int) $post_id;
					}
				};
				add_filter( 'pre_http_request', $block, PHP_INT_MAX, 3 );
				add_filter( 'pre_wp_mail', $no, PHP_INT_MAX );
				add_filter( 'sunshine_checkout_allow_order_notify', $no, PHP_INT_MAX );
				add_action( 'wp_insert_post', $track, 10, 2 );
				set_error_handler(
					function ( $errno, $errstr, $errfile, $errline ) use ( $name, &$apw_warnings ) {
						if ( error_reporting() & $errno ) {
							$apw_warnings[ $name ][] = $errstr . ' (' . basename( $errfile ) . ':' . $errline . ')';
						}
						return false;
					}
				);
				try {
					$body();
				} finally {
					restore_error_handler();
					remove_filter( 'pre_http_request', $block, PHP_INT_MAX );
					remove_filter( 'pre_wp_mail', $no, PHP_INT_MAX );
					remove_filter( 'sunshine_checkout_allow_order_notify', $no, PHP_INT_MAX );
					remove_action( 'wp_insert_post', $track, 10 );

					global $wpdb;
					if ( ! empty( $made['sunshine-email-log'] ) ) {
						foreach ( $made['sunshine-email-log'] as $email_id ) {
							wp_delete_post( $email_id, true );
						}
					}
					if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sunshine_actions' ) ) ) {
						foreach ( array( 'sunshine-product' => 'add_to_cart', 'sunshine-order' => 'order' ) as $type => $action ) {
							if ( ! empty( $made[ $type ] ) ) {
								$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_actions WHERE action_type = '" . esc_sql( $action ) . "' AND action_object_id IN (" . implode( ',', array_map( 'intval', $made[ $type ] ) ) . ')' );
							}
						}
					}
				}
				if ( ! empty( $sent ) ) {
					$t::same( array(), $sent, 'web requests that tried to leave the site' );
				}
			}
		);
	};

	// One admin user for the API calls, made the first time it is needed and deleted at the end.
	$apw_admin_id = 0;
	$apw_admin    = function () use ( $t, &$apw_admin_id ) {
		if ( ! $apw_admin_id || ! get_userdata( $apw_admin_id ) ) {
			$id = wp_insert_user(
				array(
					'user_login' => 'spcapiwrites' . wp_rand( 100000, 999999 ),
					'user_pass'  => wp_generate_password(),
					'role'       => 'administrator',
				)
			);
			if ( is_wp_error( $id ) ) {
				throw new Exception( 'Could not make the API admin: ' . $id->get_error_message() );
			}
			$t::track( 'user', $id );
			$apw_admin_id = $id;
		}
		return $apw_admin_id;
	};

	$apw_require_api = function () use ( $t ) {
		$t::require_plugin( 'sunshine_api_init', 'API add-on' );
	};

	// Call a sunshine/v1 endpoint inside this process, as the admin, the way an API client does.
	// Returns array( status, data ).
	$apw_rest = function ( $method, $path, $body = null ) use ( $apw_admin, $apw_require_api ) {
		$apw_require_api();
		$previous = get_current_user_id();
		wp_set_current_user( $apw_admin() );
		$request = new WP_REST_Request( $method, '/sunshine/v1' . $path );
		if ( null !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		$response = rest_do_request( $request );
		wp_set_current_user( $previous );
		return array( $response->get_status(), $response->get_data() );
	};

	// Run one of the AI tools (MCP abilities) the API add-on registers, as the admin, the way an
	// AI helper calls it. Returns the tool's reply or a WP_Error.
	$apw_tool = function ( $name, $input ) use ( $t, $apw_admin, $apw_require_api ) {
		$apw_require_api();
		if ( ! function_exists( 'wp_get_ability' ) || ! wp_get_ability( $name ) ) {
			$t::skip( 'the AI tool ' . $name . ' is not registered' );
		}
		$previous = get_current_user_id();
		wp_set_current_user( $apw_admin() );
		try {
			return wp_get_ability( $name )->execute( $input );
		} finally {
			wp_set_current_user( $previous );
		}
	};

	// Was a write refused as a bad request (any 4xx reply)?
	$apw_refused = function ( $status ) {
		return $status >= 400 && $status < 500;
	};

	/**
	 * A new price level. Named to sort after the store's own levels, because the store treats
	 * the first level by name as its default and other tests rely on that.
	 */
	$apw_level = function ( $label ) use ( $t ) {
		// A price level created while these are posted copies prices onto every product in the store.
		$saved_post = $_POST;
		unset( $_POST['price_level'], $_POST['percent'] );
		$term  = wp_insert_term( 'Zz API writes ' . $label . ' ' . wp_rand( 100000, 999999 ), 'sunshine-product-price-level' );
		$_POST = $saved_post;
		if ( is_wp_error( $term ) ) {
			throw new Exception( 'Could not create a price level: ' . $term->get_error_message() );
		}
		$t::track( 'term', $term['term_id'], 'sunshine-product-price-level' );
		return (int) $term['term_id'];
	};

	/**
	 * Values for every price level in the store, the way the product edit screen saves them:
	 * a string for each level, and an empty string for a level the product is not sold at.
	 */
	$apw_all_levels = function ( $values ) {
		$all = array();
		foreach ( (array) sunshine_get_price_levels() as $level ) {
			if ( $level ) {
				$all[ $level->get_id() ] = '';
			}
		}
		foreach ( $values as $level_id => $value ) {
			$all[ $level_id ] = (string) $value;
		}
		return $all;
	};

	/**
	 * A product saved the way the product edit screen saves it: a price for every price level
	 * (empty where it is not sold), an Extra Shipping Cost for every level, and the other fields.
	 * Create price levels before the product, like the admin does.
	 */
	$apw_product = function ( $prices, $fields = array() ) use ( $t, $apw_all_levels ) {
		$fields = wp_parse_args(
			$fields,
			array(
				'taxable'  => '',
				'shipping' => array(),
			)
		);
		$id     = $t::product( 1, array( 'taxable' => ! empty( $fields['taxable'] ) ) );
		update_post_meta( $id, 'price', $apw_all_levels( $prices ) );
		update_post_meta( $id, 'shipping', $apw_all_levels( $fields['shipping'] ) );
		update_post_meta( $id, 'taxable', $fields['taxable'] ? 1 : '' );
		update_post_meta( $id, 'disable_shipping', '' );
		update_post_meta( $id, 'min_qty', '' );
		update_post_meta( $id, 'max_qty', '' );
		return $id;
	};

	// A product's price at a level, the way the store reads it ('' when it is not sold there).
	$apw_price = function ( $product_id, $level_id ) {
		return sunshine_get_product( $product_id, $level_id )->get_price( $level_id );
	};

	// A product's Extra Shipping Cost at a level, the way the store reads it.
	$apw_shipping = function ( $product_id, $level_id ) {
		return sunshine_get_product( $product_id, $level_id )->get_shipping();
	};

	/**
	 * A published gallery that anyone can buy from at a price level, saved the way the gallery
	 * edit screen saves it.
	 */
	$apw_gallery = function ( $price_level ) use ( $t ) {
		$t::option( 'proofing', '' );
		$gallery_id = wp_insert_post(
			array(
				'post_type'   => 'sunshine-gallery',
				'post_status' => 'publish',
				'post_title'  => 'API writes test gallery',
			)
		);
		if ( ! $gallery_id || is_wp_error( $gallery_id ) ) {
			throw new Exception( 'Could not create a gallery' );
		}
		$t::track( 'post', $gallery_id );
		update_post_meta( $gallery_id, 'status', 'default' );
		update_post_meta( $gallery_id, 'access_type', '' );
		update_post_meta( $gallery_id, 'disable_products', '' );
		update_post_meta( $gallery_id, 'price_level', (string) $price_level );
		return $gallery_id;
	};

	// A photo in a gallery, like an upload.
	$apw_photo = function ( $gallery_id ) use ( $t ) {
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
		sunshine_get_gallery( $gallery_id )->add_image_id( $image_id );
		return $image_id;
	};

	// Run one of the store's ajax handlers with these posted values and return its JSON reply.
	$apw_ajax = function ( $handler, $post ) {
		$saved_post        = $_POST;
		$_POST             = $post;
		$_POST['security'] = wp_create_nonce( 'sunshinephotocart' );
		$ajax              = '__return_true';
		$die               = function () {
			return function () {
				throw new SPC_Totals_Test_Done();
			};
		};
		add_filter( 'wp_doing_ajax', $ajax );
		add_filter( 'wp_die_ajax_handler', $die );
		$output = '';
		ob_start();
		try {
			call_user_func( $handler );
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
	 * Add a product for a new photo in a gallery at this price level, the way the add to cart
	 * window does. Options are sent the way the window's script sends them.
	 */
	$apw_buy = function ( $product_id, $level_id, $qty = 1, $options = array() ) use ( $apw_gallery, $apw_photo, $apw_ajax ) {
		$gallery = $apw_gallery( $level_id );
		$photo   = $apw_photo( $gallery );
		$post    = array(
			'product_id' => $product_id,
			'image_id'   => $photo,
			'gallery_id' => $gallery,
			'qty'        => $qty,
		);
		if ( ! empty( $options ) ) {
			$post['options'] = $options;
		}
		return $apw_ajax( 'sunshine_modal_add_item_to_cart', $post );
	};

	/**
	 * A product option, made through the Product Options add-on's own save code, so the term
	 * meta is exactly what Products > Product Options saves. $items is item id => name for a
	 * "select one of many" option.
	 */
	$apw_option = function ( $type, $items = array() ) use ( $t ) {
		$t::require_plugin( 'sunshine_product_options_save', 'Product Options add-on' );
		$saved_post = $_POST;
		$_POST      = array( 'type' => $type );
		if ( ! empty( $items ) ) {
			$_POST['option_item_id']          = array_keys( $items );
			$_POST['option_item_name']        = array_values( $items );
			$_POST['option_item_description'] = array_fill( 0, count( $items ), '' );
			$_POST['option_item_image']       = array_fill( 0, count( $items ), '' );
		}
		try {
			$term = wp_insert_term( 'API writes ' . $type . ' ' . wp_rand( 100000, 999999 ), 'sunshine-product-option' );
		} finally {
			$_POST = $saved_post;
		}
		if ( is_wp_error( $term ) ) {
			throw new Exception( 'Could not create a product option: ' . $term->get_error_message() );
		}
		$t::track( 'term', $term['term_id'], 'sunshine-product-option' );
		return (int) $term['term_id'];
	};

	/**
	 * A product's option prices, saved the way the Options box on the product edit screen saves
	 * them: option id => level => price for a checkbox option, and option id => 'items' =>
	 * item id => level => price for a "select one of many" option.
	 */
	$apw_option_prices = function ( $product_id, $prices ) use ( $apw_all_levels ) {
		$meta = array();
		foreach ( $prices as $option_id => $option_prices ) {
			if ( isset( $option_prices['items'] ) ) {
				foreach ( $option_prices['items'] as $item_id => $item_prices ) {
					$meta[ $option_id ]['items'][ $item_id ] = $apw_all_levels( $item_prices );
				}
			} else {
				$meta[ $option_id ] = $apw_all_levels( $option_prices );
			}
		}
		update_post_meta( $product_id, 'options', $meta );
	};

	// The cart lines right now, as a plain list.
	$apw_lines = function () {
		$items = SPC()->cart->get_cart_items();
		return $items ? array_values( $items ) : array();
	};

	// Put a gift card code in the cart, the way the Apply button leaves it in the session.
	$apw_use_card = function ( $code ) {
		$cards   = (array) SPC()->session->get( 'gift_cards' );
		$cards[] = $code;
		SPC()->session->set( 'gift_cards', array_values( array_filter( $cards ) ) );
	};

	// How much the gift cards in the cart pay right now.
	$apw_card_applied = function () {
		return array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) );
	};

	// Put a discount code in the cart, the way entering it at checkout does.
	$apw_use_code = function ( $code ) {
		$codes   = (array) SPC()->session->get( 'discounts' );
		$codes[] = $code;
		SPC()->session->set( 'discounts', array_values( array_filter( $codes ) ) );
	};

	// Make a discount through POST /discounts and return array( status, data ). It is deleted at the end.
	$apw_make_discount = function ( $body ) use ( $t, $apw_rest ) {
		$t::require_plugin( 'sunshine_get_discount_by_code', 'Discount Codes add-on' );
		list( $status, $data ) = $apw_rest( 'POST', '/discounts', $body );
		if ( ! empty( $data['id'] ) ) {
			$t::track( 'post', (int) $data['id'] );
		}
		return array( $status, $data );
	};

	// Make a gift card through POST /gift-cards and return its id. It is deleted at the end.
	$apw_make_card = function ( $body ) use ( $t, $apw_rest ) {
		$t::require_plugin( 'sunshine_get_gift_card_by_code', 'Gift Cards add-on' );
		list( $status, $data ) = $apw_rest( 'POST', '/gift-cards', $body );
		if ( ! empty( $data['id'] ) ) {
			$t::track( 'post', (int) $data['id'] );
		}
		if ( 201 !== $status || empty( $data['id'] ) ) {
			throw new Exception( 'The API did not make the gift card: ' . $status . ' ' . substr( wp_json_encode( $data ), 0, 300 ) );
		}
		return (int) $data['id'];
	};

	// A gift card's saved balance, read straight from the card like its admin screen does.
	$apw_balance = function ( $card_id ) {
		return (float) get_post_meta( $card_id, 'balance', true );
	};

	// Log in as a new customer and give them store credits through PUT /customers/{id}.
	// Returns array( customer id, status, data ).
	$apw_credits_customer = function ( $credits ) use ( $t, $apw_rest ) {
		$customer_id           = $t::customer();
		list( $status, $data ) = $apw_rest( 'PUT', '/customers/' . $customer_id, array( 'credits' => $credits ) );
		// The logged in customer is read again, the way the next page load reads it.
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart     = new SPC_Cart();
		return array( $customer_id, $status, $data );
	};

	// The shopper's next page load after paying: the cart is built again, then the checkout
	// finishes the order it is holding (what the Test payment method's return does).
	$apw_finish = function () {
		SPC()->cart = new SPC_Cart();
		SPC()->cart->setup( true );
		$order = SPC()->cart->process_order();
		return $order ? sunshine_get_order( $order->get_id() ) : false;
	};
}
