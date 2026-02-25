<?php

add_action( 'sunshine_modal_display_add_to_cart', 'sunshine_modal_display_add_to_cart' );
function sunshine_modal_display_add_to_cart() {

	if ( empty( $_POST['imageId'] ) ) {
		wp_send_json_error( __( 'No image ID provided', 'sunshine-photo-cart' ) );
	}

	$image = sunshine_get_image( intval( $_POST['imageId'] ) );
	if ( empty( $image->get_id() ) ) {
		wp_send_json_error( __( 'Not a valid image ID', 'sunshine-photo-cart' ) );
	}

	$result = array( 'html' => sunshine_get_template_html( 'image/add-to-cart', array( 'image' => $image ) ) );
	wp_send_json_success( $result );

}

add_action( 'sunshine_modal_display_add_favorites_to_cart', 'sunshine_modal_display_add_favorites_to_cart' );
function sunshine_modal_display_add_favorites_to_cart() {

	$images = SPC()->customer->get_favorites();
	if ( empty( $images ) ) {
		wp_send_json_error( __( 'No favorites found', 'sunshine-photo-cart' ) );
	}

	$result = array(
		'html' => sunshine_get_template_html(
			'image/add-to-cart',
			array(
				'images'         => $images,
				'bulk_mode'      => true,
				'bulk_mode_type' => 'favorites',
			)
		),
	);
	wp_send_json_success( $result );

}

/**
 * Recursively sanitize product option values sent from modal AJAX requests.
 *
 * @param mixed $options Option payload.
 * @return array
 */
function sunshine_modal_sanitize_add_to_cart_options( $options ) {
	if ( ! is_array( $options ) ) {
		return array();
	}

	$sanitized = array();
	foreach ( $options as $option_id => $option_value ) {
		$sanitized_option_id = is_string( $option_id ) ? sanitize_text_field( wp_unslash( $option_id ) ) : $option_id;
		if ( is_array( $option_value ) ) {
			$sanitized[ $sanitized_option_id ] = sunshine_modal_sanitize_add_to_cart_options( $option_value );
		} elseif ( is_scalar( $option_value ) ) {
			$sanitized[ $sanitized_option_id ] = sanitize_text_field( wp_unslash( (string) $option_value ) );
		}
	}

	return $sanitized;
}

/**
 * Normalize images option value to a list of image IDs.
 *
 * @param mixed $images_option Images option value.
 * @return array
 */
function sunshine_modal_get_add_to_cart_image_ids_from_option( $images_option ) {
	if ( empty( $images_option ) ) {
		return array();
	}

	if ( is_array( $images_option ) ) {
		$image_ids = array_map( 'intval', $images_option );
	} else {
		$images_option = sanitize_text_field( wp_unslash( (string) $images_option ) );
		$image_ids     = array_map( 'intval', explode( ',', $images_option ) );
	}

	return array_values( array_filter( $image_ids ) );
}

/**
 * Add cart items for modal add-to-cart flows.
 *
 * @param SPC_Product $product Product object.
 * @param int         $product_id Product ID.
 * @param int         $image_id Image ID.
 * @param int         $gallery_id Gallery ID.
 * @param int|string  $price_level Price level.
 * @param array       $options Product options.
 * @param int         $qty Quantity.
 * @param string      $comments Comments.
 * @return array|false
 */
function sunshine_modal_add_items_to_cart( $product, $product_id, $image_id, $gallery_id, $price_level, $options, $qty, $comments ) {
	$add_to_cart_result = false;
	$image_ids_option   = sunshine_modal_get_add_to_cart_image_ids_from_option( $options['images'] ?? array() );

	// Add each selected image individually for non multi-image products.
	if ( $product->get_type() !== 'multi-image' && ! empty( $image_ids_option ) ) {
		unset( $options['images'] );
		foreach ( $image_ids_option as $selected_image_id ) {
			$add_to_cart_result = SPC()->cart->add_item( $product_id, $selected_image_id, $gallery_id, $price_level, ( ! empty( $options ) ) ? $options : '', intval( $qty ), $comments );
		}
		return $add_to_cart_result;
	}

	if ( ! empty( $image_ids_option ) ) {
		$options['images'] = $image_ids_option;
	}

	return SPC()->cart->add_item( $product_id, $image_id, $gallery_id, $price_level, ( ! empty( $options ) ) ? $options : '', intval( $qty ), $comments );
}


add_action( 'wp_ajax_nopriv_sunshine_product_details', 'sunshine_modal_product_details' );
add_action( 'wp_ajax_sunshine_product_details', 'sunshine_modal_product_details' );
function sunshine_modal_product_details() {

	sunshine_modal_check_security();

	if ( empty( $_POST['image_id'] ) || empty( $_POST['product_id'] ) ) {
		SPC()->log( 'Show product details failed' );
		wp_send_json_error( __( 'Show product options failed', 'sunshine-photo-cart' ) );
	}

	$image = sunshine_get_image( intval( $_POST['image_id'] ) );
	if ( ! $image->exists() || ! $image->can_view() ) {
		SPC()->log( 'Show product details failed: No image found: ' . intval( $_POST['image_id'] ) );
		wp_send_json_error( __( 'Show product details failed - no image found', 'sunshine-photo-cart' ) );
	}

	$product = sunshine_get_product( intval( $_POST['product_id'] ), $image->get_price_level() );
	if ( ! $product->exists() ) {
		SPC()->log( 'Show product details failed: No product found: ' . intval( $_POST['product_id'] ) );
		wp_send_json_error( __( 'Show product details failed - no product found', 'sunshine-photo-cart' ) );
	}

	// Check if we're in bulk mode with multiple images
	$images    = null;
	$bulk_mode = false;
	if ( ! empty( $_POST['imageIds'] ) ) {
		$image_ids = array_map( 'intval', explode( ',', sanitize_text_field( $_POST['imageIds'] ) ) );
		$images    = array();
		foreach ( $image_ids as $image_id ) {
			$img = sunshine_get_image( $image_id );
			if ( $img && $img->can_view() ) {
				$images[] = $img;
			}
		}
		if ( ! empty( $images ) && count( $images ) > 1 ) {
			$bulk_mode = true;
		}
	}

	// $options = $product->get_options( $image->get_price_level() );

	$result = array(
		'html' => sunshine_get_template_html(
			'image/product-details',
			array(
				'product'   => $product,
				// 'options' => $options,
				'image'     => $image,
				'images'    => $images,
				'bulk_mode' => $bulk_mode,
			)
		),
	);

	$result = apply_filters( 'sunshine_product_details', $result, $product, $image );

	wp_send_json_success( $result );

}

add_action( 'wp_ajax_nopriv_sunshine_modal_add_item_to_cart', 'sunshine_modal_add_item_to_cart' );
add_action( 'wp_ajax_sunshine_modal_add_item_to_cart', 'sunshine_modal_add_item_to_cart' );
function sunshine_modal_add_item_to_cart() {

	sunshine_modal_check_security();

	if ( empty( $_POST['product_id'] ) ) {
		SPC()->log( 'Add to cart - No product provided' );
		wp_send_json_error( __( 'No product provided', 'sunshine-photo-cart' ) );
	}

	if ( ! isset( $_POST['qty'] ) ) {
		$qty = 1;
	} else {
		$qty = intval( $_POST['qty'] );
	}

	if ( ! isset( $_POST['image_id'] ) ) {
		$image_id = 0;
	} else {
		$image_id = intval( $_POST['image_id'] );
	}

	if ( ! isset( $_POST['gallery_id'] ) ) {
		$gallery_id = '';
	} else {
		$gallery_id = intval( $_POST['gallery_id'] );
	}

	if ( empty( $_POST['options'] ) ) {
		$options = array();
	} else {
		$options = sunshine_modal_sanitize_add_to_cart_options( $_POST['options'] );
	}

	$image = $product = $gallery = $price_level = '';

	if ( ! empty( $gallery_id ) ) {
		$gallery = sunshine_get_gallery( $gallery_id );
		if ( $gallery->exists() ) {
			$price_level = $gallery->get_price_level();
		} else {
			$gallery = '';
		}
	}

	if ( ! empty( $image_id ) ) {
		$image = sunshine_get_image( $image_id );
		if ( ! $image->exists() || ! $image->can_view() ) {
			SPC()->log( 'Add to cart - Invalid image ID provided: ' . $image_id );
			wp_send_json_error( array( 'message' => __( 'Invalid image provided', 'sunshine-photo-cart' ) ) );
		}
		$price_level = $image->get_price_level();
	}

	if ( ! empty( $_POST['price_level'] ) ) {
		$price_level = intval( $_POST['price_level'] );
	}

	$product_id = intval( $_POST['product_id'] );
	$product    = sunshine_get_product( $product_id, $price_level );
	if ( ! $product->exists() ) {
		SPC()->log( 'Add to cart - Invalid product ID provided: ' . $product_id );
		wp_send_json_error( array( 'message' => __( 'Invalid product provided', 'sunshine-photo-cart' ) ) );
	}

	$comments = ( ! empty( $_POST['comments'] ) ) ? sanitize_textarea_field( $_POST['comments'] ) : '';
	$add_to_cart_result = false;
	$logging_enabled    = SPC()->get_option( 'enable_log' );

	SPC()->log( '=== Main Add to Cart Handler ===' );
	SPC()->log( 'Product ID: ' . $product_id );
	SPC()->log( 'Product Type: ' . $product->get_type() );
	if ( $logging_enabled ) {
		SPC()->log( 'Options: ' . print_r( $options, true ) );
	}

	SPC()->log( 'Calling cart->add_item with: product_id=' . $product_id . ', image_id=' . $image_id . ', gallery_id=' . $gallery_id . ', price_level=' . $price_level );
	$add_to_cart_result = sunshine_modal_add_items_to_cart( $product, $product_id, $image_id, $gallery_id, $price_level, $options, $qty, $comments );
	SPC()->log( 'add_item returned: ' . ( $add_to_cart_result ? 'SUCCESS' : 'FALSE/EMPTY' ) );
	if ( $add_to_cart_result && $logging_enabled ) {
		SPC()->log( 'Returned item: ' . print_r( $add_to_cart_result, true ) );
	}

	// sunshine_log( 'subtotal: ' . SPC()->cart->get_subtotal() );

	// Add item to cart.
	if ( ! empty( $add_to_cart_result ) ) {
		$result = array(
			'item'            => $add_to_cart_result,
			'count'           => SPC()->cart->get_item_count(),
			'total_formatted' => SPC()->cart->get_total_formatted(),
			'mini_cart'       => sunshine_get_template_html( 'cart/mini-cart' ),
			'type'            => $product->get_type(),
			'cart_url'        => sunshine_get_page_url( 'cart' ),
			'checkout_url'    => sunshine_get_page_url( 'checkout' ),
		);
		SPC()->log( 'Sending success response' );
		wp_send_json_success( $result );
	} else {
		$product_price = $product->get_price( $price_level );
		SPC()->log( 'ERROR: Item not added to cart - add_item returned empty/false' );
		SPC()->log( 'Product exists: ' . ( $product->exists() ? 'yes' : 'no' ) );
		SPC()->log( 'Product has price: ' . ( $product_price !== '' ? 'yes (' . $product_price . ')' : 'no' ) );
		wp_send_json_error( array( 'message' => __( 'Item not added to cart', 'sunshine-photo-cart' ) ) );
	}

}

add_action( 'wp_ajax_nopriv_sunshine_modal_add_favorites_to_cart', 'sunshine_modal_add_favorites_to_cart' );
add_action( 'wp_ajax_sunshine_modal_add_favorites_to_cart', 'sunshine_modal_add_favorites_to_cart' );
function sunshine_modal_add_favorites_to_cart() {

	sunshine_modal_check_security();

	if ( empty( $_POST['product_id'] ) ) {
		SPC()->log( 'Bulk add to cart - No product provided' );
		wp_send_json_error( __( 'No product provided', 'sunshine-photo-cart' ) );
	}

	if ( ! isset( $_POST['qty'] ) ) {
		$qty = 1;
	} else {
		$qty = intval( $_POST['qty'] );
	}

	if ( empty( $_POST['options'] ) ) {
		$options = array();
	} else {
		$options = sunshine_modal_sanitize_add_to_cart_options( $_POST['options'] );
	}

	$product_id = intval( $_POST['product_id'] );
	$comments   = ( ! empty( $_POST['comments'] ) ) ? sanitize_textarea_field( $_POST['comments'] ) : '';

	// Get images - either from specific IDs or all favorites.
	if ( ! empty( $_POST['imageIds'] ) ) {
		$image_ids = array_map( 'intval', explode( ',', sanitize_text_field( $_POST['imageIds'] ) ) );
		$images    = array();
		foreach ( $image_ids as $image_id ) {
			$image = sunshine_get_image( $image_id );
			if ( $image && $image->can_view() ) {
				$images[] = $image;
			}
		}
	} else {
		// Get all favorite images (works for both logged-in users and guests).
		$images = SPC()->customer->get_favorites();
	}

	if ( empty( $images ) ) {
		wp_send_json_error( __( 'No images found', 'sunshine-photo-cart' ) );
	}

	$added_count = 0;
	$errors      = array();

	// Loop through each favorite image and add the product to cart.
	foreach ( $images as $image ) {
		$price_level = $image->get_price_level();
		$gallery_id  = $image->get_gallery_id();
		$image_id    = $image->get_id();

		$product = sunshine_get_product( $product_id, $price_level );
		if ( ! $product->exists() ) {
			$errors[] = $image->get_name();
			continue;
		}

		$add_to_cart_result = sunshine_modal_add_items_to_cart( $product, $product_id, $image_id, $gallery_id, $price_level, $options, $qty, $comments );

		if ( ! empty( $add_to_cart_result ) ) {
			$added_count++;
		} else {
			$errors[] = $image->get_name();
		}
	}

	if ( $added_count > 0 ) {
		$result = array(
			'count'           => SPC()->cart->get_item_count(),
			'total_formatted' => SPC()->cart->get_total_formatted(),
			'mini_cart'       => sunshine_get_template_html( 'cart/mini-cart' ),
			'added_count'     => $added_count,
		);

		if ( ! empty( $errors ) ) {
			$result['errors'] = $errors;
		}

		SPC()->log( 'Bulk add to cart: ' . $added_count . ' items added' );
		wp_send_json_success( $result );
	} else {
		SPC()->log( 'Bulk add to cart failed' );
		wp_send_json_error( __( 'Items not added to cart', 'sunshine-photo-cart' ) );
	}

}

// Add to cart from URL.
add_action( 'wp', 'sunshine_add_to_cart_url' );
function sunshine_add_to_cart_url() {
	if ( isset( $_GET['sunshine_action'] ) ) {
		$action = sanitize_text_field( $_GET['sunshine_action'] );
		if ( 'add_to_cart' == $action ) {

			$image_id    = '';
			$price_level = '';
			$gallery_id  = '';

			$qty = 1;
			if ( ! empty( $_GET['qty'] ) ) {
				$qty = intval( $_GET['qty'] );
				if ( $qty < 1 ) {
					$qty = 1;
				}
			}

			$product_id = '';
			if ( ! empty( $_GET['product_id'] ) ) {
				$product_id = intval( $_GET['product_id'] );
				$product    = sunshine_get_product( $product_id );
			}

			if ( ! empty( $_GET['price_level'] ) ) {
				$price_level = intval( $_GET['price_level'] );
			}

			if ( ! empty( $_GET['image_id'] ) ) {
				$image_id    = intval( $_GET['image_id'] );
				$image       = sunshine_get_image( $image_id );
				if ( $image->exists() ) {
					$price_level = $image->get_price_level();
					$gallery_id  = $image->get_gallery_id();
				}
			}

			if ( ! empty( $product_id ) ) {
				$add_to_cart_result = SPC()->cart->add_item( $product_id, $image_id, $gallery_id, $price_level, '', $qty );
				if ( $add_to_cart_result ) {
					/* translators: %s is the product name */
					SPC()->notices->add( sprintf( __( '%s added to cart', 'sunshine-photo-cart' ), $product->get_name() ) );
				}
			}
		}

		$url = remove_query_arg( array_keys( $_GET ) );
		wp_safe_redirect( $url );
		exit;

	}
}
