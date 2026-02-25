<?php
add_action( 'sunshine_modal_display_store_product', 'sunshine_modal_display_store_product' );
function sunshine_modal_display_store_product() {

	if ( empty( $_POST['galleryId'] ) || empty( $_POST['productId'] ) ) {
		wp_send_json_error();
	}

	$gallery = sunshine_get_gallery( intval( $_POST['galleryId'] ) );
	$product = sunshine_get_product( intval( $_POST['productId'] ), $gallery->get_price_level() );

	$image_count = '';
	if ( 'multi-image' == $product->get_type() ) {
		$image_count = $product->get_meta_value( 'image_count' );
	}

	$result = array(
		'html' => sunshine_get_template_html(
			'store/product-details',
			array(
				'gallery'     => $gallery,
				'product'     => $product,
				'image_count' => $image_count,
				'key'         => uniqid(),
			),
		),
	);
	wp_send_json_success( $result );

}

function sunshine_get_sources( $product, $gallery ) {

	// Gallery sources
	$sources          = array();
	$source_galleries = array();

	$allowed_sources = $product->get_meta_value( 'allowed_sources' );
	if ( ! empty( $allowed_sources ) && is_array( $allowed_sources ) && ! in_array( 'any', $allowed_sources ) ) {

		// Same gallery the current image is in.
		if ( in_array( 'current', $allowed_sources ) ) {
			$source_galleries[ $gallery->get_id() ] = $gallery;
		}

		// Same gallery the current image is in.
		if ( in_array( 'siblings', $allowed_sources ) ) {
			$galleries        = sunshine_get_galleries( array( 'post_parent' => $gallery->get_parent_gallery_id() ) );
			$source_galleries = array_merge( $source_galleries, $galleries );
		}

		if ( in_array( 'ancestors', $allowed_sources ) ) {
			$ancestor_ids     = get_post_ancestors( $gallery->get_id() );
			$galleries        = sunshine_get_galleries( array( 'post__in' => $ancestor_ids ) );
			$source_galleries = array_merge( $source_galleries, $galleries );
		}
	} else {
		// Fallback to all accessible galleries if nothing is selected.
		$source_galleries = sunshine_get_galleries( array( 'meta_query' => array() ) );
	}

	if ( ! array_key_exists( $gallery->get_id(), $source_galleries ) ) {
		$source_galleries[] = $gallery;
	}

	return $source_galleries;

}

function sunshine_source_dropdown_options( $galleries, $selected = '', $parent_id = 0, $level = 0 ) {
	$indent = str_repeat( '--', $level );
	foreach ( $galleries as $gallery ) {
		if ( $parent_id == $gallery->get_parent_gallery_id() && ( $gallery->has_images() || $gallery->has_children_galleries() ) ) {
			echo '<option value="' . esc_attr( $gallery->get_id() ) . '" ' . selected( $selected, $gallery->get_id(), false ) . '>' . esc_html( $indent . $gallery->get_name() . ' (' . $gallery->get_image_count() . ')' ) . '</option>';
			sunshine_source_dropdown_options( $galleries, $selected, $gallery->get_id(), $level + 1 );
		}
	}
}


/*
Show image select
*/
add_action( 'wp_ajax_nopriv_sunshine_multi_image_select_images', 'sunshine_multi_image_select_images' );
add_action( 'wp_ajax_sunshine_multi_image_select_images', 'sunshine_multi_image_select_images' );
function sunshine_multi_image_select_images() {

	// TODO: Need nonce check.

	if ( empty( $_POST['gallery_id'] ) || empty( $_POST['product_id'] ) ) {
		wp_send_json_error();
	}

	$product = sunshine_get_product( intval( $_POST['product_id'] ) );
	if ( empty( $product ) ) {
		wp_send_json_error();
	}

	if ( ! empty( $_POST['source_product_id'] ) ) {
		$source_product = sunshine_get_product( intval( $_POST['source_product_id'] ) );
	}

	if ( empty( $_POST['image_count'] ) ) {
		$image_count = $product->get_meta_value( 'image_count' );
	} else {
		$image_count = intval( $_POST['image_count'] );
	}

	$gallery = sunshine_get_gallery( intval( $_POST['gallery_id'] ) );
	if ( empty( $gallery ) ) {
		wp_send_json_error();
	}

	$value_target    = ( ! empty( $_POST['value_target'] ) ) ? sanitize_text_field( $_POST['value_target'] ) : '';
	$selected_target = ( ! empty( $_POST['selected_target'] ) ) ? sanitize_text_field( $_POST['selected_target'] ) : '';
	$id              = ( ! empty( $_POST['id'] ) ) ? sanitize_text_field( $_POST['id'] ) : '';
	$ref             = ( ! empty( $_POST['ref'] ) ) ? sanitize_text_field( $_POST['ref'] ) : 'store-images';
	$key             = ( isset( $_POST['key'] ) ) ? sanitize_text_field( $_POST['key'] ) : '0';
	$selected        = ( isset( $_POST['selected'] ) ) ? sanitize_text_field( $_POST['selected'] ) : '';

	$sources = sunshine_get_sources( ( ! empty( $source_product ) ) ? $source_product : $product, $gallery );

	$args = array(
		'sources'         => $sources,
		'image_count'     => $image_count,
		'value_target'    => $value_target,
		'selected_target' => $selected_target,
		'product'         => $product,
		'gallery'         => $gallery,
		'id'              => $id,
		'ref'             => $ref,
		'key'             => $key,
		'selected'        => ( ! empty( $selected ) ) ? explode( ',', $selected ) : array(),
	);

	$result = array(
		'html' => sunshine_get_template_html(
			'multi-image-select/select-images',
			$args,
		),
	);
	wp_send_json_success( $result );

}

add_action( 'wp_ajax_nopriv_sunshine_multi_image_select_gallery_images', 'sunshine_multi_image_select_gallery_images' );
add_action( 'wp_ajax_sunshine_multi_image_select_gallery_images', 'sunshine_multi_image_select_gallery_images' );
function sunshine_multi_image_select_gallery_images() {

	if ( empty( $_POST['gallery_id'] ) ) {
		wp_send_json_error();
	}

	$args = array();

	$gallery_id      = intval( $_POST['gallery_id'] );
	$args['gallery'] = sunshine_get_gallery( $gallery_id );

	$args['selected'] = SPC()->session->get( 'selected' );
	if ( ! empty( $_POST['selected'] ) ) {
		$args['selected'] = array_map( 'intval', $_POST['selected'] );
	}
	if ( empty( $args['selected'] ) ) {
		$args['selected'] = array();
	}

	$args['product'] = '';
	if ( ! empty( $_POST['product_id'] ) ) {
		$product_id      = intval( $_POST['product_id'] );
		$args['product'] = sunshine_get_product( $product_id );
	}

	$args['image_count'] = 25;
	if ( ! empty( $_POST['image_count'] ) ) {
		$args['image_count'] = intval( $_POST['image_count'] );
	}

	$html = sunshine_get_template_html( 'multi-image-select/gallery-list', $args );
	wp_send_json_success( array( 'html' => $html ) );

}

add_action( 'wp_ajax_sunshine_multi_image_select_images_item', 'sunshine_multi_image_select_images_item' );
add_action( 'wp_ajax_nopriv_sunshine_multi_image_select_images_item', 'sunshine_multi_image_select_images_item' );
function sunshine_multi_image_select_images_item() {

	$hash      = ( ! empty( $_POST['ref'] ) ) ? sanitize_text_field( $_POST['ref'] ) : '';
	$key       = ( isset( $_POST['key'] ) ) ? sanitize_text_field( $_POST['key'] ) : '';
	$image_ids = ( ! empty( $_POST['selected_image_ids'] ) ) ? array_map( 'intval', $_POST['selected_image_ids'] ) : '';

	do_action( 'sunshine_multi_image_select_images_item', $image_ids, $hash, $key );

	$image_data = array();
	if ( ! empty( $image_ids ) ) {
		foreach ( $image_ids as $image_id ) {
			if ( $image_id <= 0 ) {
				continue;
			}
			$image        = sunshine_get_image( $image_id );
			$image_data[] = array(
				'id'  => $image->get_id(),
				'url' => $image->get_image_url(),
			);
		}
	}
	wp_send_json_success( $image_data );

}
