<?php
/**
 * Shared setup for the core-products case files: price levels, galleries with photos,
 * product options, and adding to the cart through the same requests the store's
 * add to cart window sends.
 *
 * Each core-products file loads this with require_once, so any one of them can run alone.
 * Everything here is a closure in a variable, so all case files can share one PHP process.
 */

$t = 'SPC_Totals_Test';

/**
 * A new price level. Named to sort after the store's own levels, because the store
 * treats the first level by name as its default and other tests rely on that.
 */
$products_level = function ( $label ) use ( $t ) {
	// A price level created while these are posted copies prices onto every product in the store.
	$saved_post = $_POST;
	unset( $_POST['price_level'], $_POST['percent'] );
	$term  = wp_insert_term( 'Zz Totals ' . $label . ' ' . wp_rand( 100000, 999999 ), 'sunshine-product-price-level' );
	$_POST = $saved_post;
	if ( is_wp_error( $term ) ) {
		throw new Exception( 'Could not create a price level: ' . $term->get_error_message() );
	}
	$t::track( 'term', $term['term_id'], 'sunshine-product-price-level' );
	return (int) $term['term_id'];
};

/**
 * Prices for every price level in the store, the way the product edit screen saves them:
 * a string for each level, and an empty string for a level the product is not sold at.
 */
$products_all_levels = function ( $prices ) {
	$all = array();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		if ( $level ) {
			$all[ $level->get_id() ] = '';
		}
	}
	foreach ( $prices as $level_id => $price ) {
		$all[ $level_id ] = (string) $price;
	}
	return $all;
};

$products_prices = function ( $product_id, $prices ) use ( $products_all_levels ) {
	update_post_meta( $product_id, 'price', $products_all_levels( $prices ) );
};

/**
 * A published gallery that anyone can buy from, saved the way the gallery edit screen saves it.
 * Give $parent_id to make it a sub-gallery.
 */
$products_gallery = function ( $price_level = '', $parent_id = 0 ) use ( $t ) {
	$t::option( 'proofing', '' );
	$gallery_id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-gallery',
			'post_status' => 'publish',
			'post_title'  => 'Totals products test gallery',
			'post_parent' => $parent_id,
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

/**
 * A photo in a gallery, like an upload: an attachment whose parent is the gallery, listed in
 * the gallery's images. $price_level is the per-photo price level from the media edit screen,
 * which saves intval() of the choice (0 for "Use gallery price level").
 */
$products_photo = function ( $gallery_id, $price_level = null ) use ( $t ) {
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
 * Run one of the store's ajax handlers with these posted values and return its JSON reply.
 */
$products_ajax = function ( $handler, $post ) {
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
 * Add a product for a photo the way the add to cart window does. Options are sent the way the
 * window's script sends them: option id => chosen item id, "1" for a checkbox, or the typed text.
 */
$products_add_photo = function ( $product_id, $image_id, $qty = 1, $options = array() ) use ( $products_ajax ) {
	$image = sunshine_get_image( $image_id );
	$post  = array(
		'product_id' => $product_id,
		'image_id'   => $image_id,
		'gallery_id' => $image->get_gallery_id(),
		'qty'        => $qty,
	);
	if ( ! empty( $options ) ) {
		$post['options'] = $options;
	}
	return $products_ajax( 'sunshine_modal_add_item_to_cart', $post );
};

/**
 * Add a store product that has no photo, at a price level, the way the store window does.
 */
$products_add_store = function ( $product_id, $price_level, $qty = 1, $options = array() ) use ( $products_ajax ) {
	$post = array(
		'product_id'  => $product_id,
		'price_level' => $price_level,
		'qty'         => $qty,
	);
	// Store pages sign the price level they offer; newer versions only trust a signed one.
	if ( function_exists( 'sunshine_price_level_token' ) ) {
		$post['price_level_token'] = sunshine_price_level_token( $price_level );
	}
	if ( ! empty( $options ) ) {
		$post['options'] = $options;
	}
	return $products_ajax( 'sunshine_modal_add_item_to_cart', $post );
};

/**
 * A product option, created through the Product Options add-on's own save code, so the term
 * meta is exactly what Products > Product Options saves. $items is item id => name for a
 * "select one of many" option.
 */
$products_option = function ( $type, $items = array() ) use ( $t ) {
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
		$term = wp_insert_term( 'Totals test ' . $type . ' ' . wp_rand( 100000, 999999 ), 'sunshine-product-option' );
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
 * them: option id => level => price for a checkbox or text option, and
 * option id => 'items' => item id => level => price for a "select one of many" option.
 * Every level gets a value, empty where the option is not sold.
 */
$products_option_prices = function ( $product_id, $prices ) use ( $products_all_levels ) {
	$meta = array();
	foreach ( $prices as $option_id => $option_prices ) {
		if ( isset( $option_prices['items'] ) ) {
			foreach ( $option_prices['items'] as $item_id => $item_prices ) {
				$meta[ $option_id ]['items'][ $item_id ] = $products_all_levels( $item_prices );
			}
		} else {
			$meta[ $option_id ] = $products_all_levels( $option_prices );
		}
	}
	update_post_meta( $product_id, 'options', $meta );
};

/**
 * The cart lines right now, as a plain list.
 */
$products_lines = function () {
	$items = SPC()->cart->get_cart_items();
	return $items ? array_values( $items ) : array();
};

/**
 * Run some code and return the PHP warnings, notices and deprecations it raised.
 */
$products_warnings = function ( $callback ) {
	$warnings = array();
	set_error_handler(
		function ( $errno, $errstr, $errfile, $errline ) use ( &$warnings ) {
			if ( error_reporting() & $errno ) {
				$warnings[] = $errstr . ' (' . basename( $errfile ) . ':' . $errline . ')';
			}
			return false;
		}
	);
	try {
		$callback();
	} finally {
		restore_error_handler();
	}
	return $warnings;
};

/**
 * What the cart shows right before the order is placed, to compare with the saved order.
 */
$products_cart_snapshot = function () use ( $products_lines ) {
	$lines = array();
	foreach ( $products_lines() as $line ) {
		$lines[] = array(
			'qty'      => $line->get_qty(),
			'price'    => (float) $line->get_price(),
			'total'    => (float) $line->get_total(),
			'tax'      => (float) $line->get_tax_total(),
			'discount' => (float) $line->get_discount_total(),
		);
	}
	$cart = SPC()->cart;
	return array(
		'subtotal' => (float) $cart->get_subtotal(),
		'discount' => (float) $cart->get_discount(),
		'shipping' => (float) $cart->get_shipping(),
		'tax'      => (float) $cart->get_tax(),
		'total'    => (float) $cart->get_total(),
		'lines'    => $lines,
	);
};

$products_order_matches_cart = function ( $order, $seen ) use ( $t ) {
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
		$n = 'order line ' . ( $i + 1 );
		$t::same( $line['qty'], (int) $items[ $i ]->get_qty(), $n . ' quantity' );
		$t::money( $line['price'], $items[ $i ]->get_price(), $n . ' price' );
		$t::money( $line['total'], $items[ $i ]->get_total(), $n . ' total' );
		$t::money( $line['tax'], $items[ $i ]->get_tax_total(), $n . ' tax' );
		$t::money( $line['discount'], $items[ $i ]->get_discount_total(), $n . ' discount' );
	}
	return $items;
};
