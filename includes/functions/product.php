<?php
function sunshine_get_product_types( $field = '' ) {
	$types = apply_filters(
		'sunshine_product_types',
		array(
			'print' => array(
				'name'  => __( 'Print', 'sunshine-photo-cart' ),
				'image' => 1,
				'store' => 1,
			),
		)
	);
	if ( ! $field ) {
		return $types;
	}
	$final_types = array();
	foreach ( $types as $key => $type ) {
		if ( array_key_exists( $field, $type ) ) {
			$final_types[ $key ] = $type[ $field ];
		}
	}
	return $final_types;
}

function sunshine_get_allowed_product_types_for_image() {
	$types = sunshine_get_product_types();
	if ( ! empty( $types ) ) {
		$allowed_types = array();
		foreach ( $types as $key => $type ) {
			if ( ! empty( $type['image'] ) ) {
				$allowed_types[] = $key;
			}
		}
		return apply_filters( 'sunshine_allowed_product_types_for_image', $allowed_types );
	}
	return false;
}

function sunshine_get_allowed_product_types_for_store() {
	$types = sunshine_get_product_types();
	if ( ! empty( $types ) ) {
		$allowed_types = array();
		foreach ( $types as $key => $type ) {
			if ( ! empty( $type['store'] ) ) {
				$allowed_types[] = $key;
			}
		}
		return apply_filters( 'sunshine_allowed_product_types_for_store', $allowed_types );
	}
	return false;
}

// Price level must be int when passed.
function sunshine_get_products( $price_level = 'all', $category = '', $types = '', $args = array(), $ignore_price = false ) {

	$args = wp_parse_args( $args, array(
		'nopaging'   => true,
		'meta_query' => array(),
		'tax_query'  => array(),
		'orderby'    => 'menu_order',
		'order'      => 'ASC',
	) );

	$args['post_type'] = 'sunshine-product'; // Make sure we always get this post type.

	// Get products from specific category.
	if ( ! empty( $category ) ) {
		$args['tax_query'][] = array(
			'taxonomy' => 'sunshine-product-category',
			'terms'    => $category,
		);
	}

	$args = apply_filters( 'sunshine_get_product_args', $args );

	if ( ! empty( $types ) && ! is_array( $types ) ) {
		$types = array( $types );
	}

	$products = get_posts( $args );
	if ( ! empty( $products ) ) {
		$final_products = array();
		$this_price_level_id = ( $price_level === 'all' ) ? '' : intval( $price_level );
		foreach ( $products as $product ) {
			$p = sunshine_get_product( $product, $this_price_level_id );
			if ( ( $price_level == 'all' || $ignore_price || $p->get_price( $this_price_level_id ) !== '' ) && ( empty( $types ) || in_array( $p->get_type(), $types ) ) ) {
				$final_products[] = $p;
			}
		}
		return $final_products;
	}

	return false;

}

function sunshine_get_product( $product_id, $price_level_id = '' ) {
	$product = new SPC_Product( $product_id, intval( $price_level_id ) );
	return apply_filters( 'sunshine_get_product', $product, $price_level_id );
}

function sunshine_get_price_levels() {
	$terms = get_terms( 'sunshine-product-price-level', array( 'hide_empty' => false ) );
	if ( ! empty( $terms ) ) {
		$price_levels = array();
		foreach ( $terms as $term ) {
			$price_levels[] = new SPC_Price_Level( $term );
		}
		return apply_filters( 'sunshine_price_levels', $price_levels );
	}
	return false;
}

function sunshine_get_default_price_level() {
	$price_levels = sunshine_get_price_levels();
	if ( ! empty( $price_levels ) ) {
		return array_shift( $price_levels );
	}
	return false;
}

function sunshine_get_default_price_level_id() {
	$price_level = sunshine_get_default_price_level();
	if ( $price_level ) {
		return $price_level->get_id();
	}
	return false;
}


function sunshine_get_default_product_category() {
	$terms = get_terms( 'sunshine-product-category', array( 'hide_empty' => 0, 'meta_key' => 'default', 'meta_value' => 1 ) );
	if ( ! empty( $terms ) ) {
		return new SPC_Product_Category( $terms[0] );
	}
	return false;
}

function sunshine_get_product_categories( $price_level = '', $type = '' ) {
	$terms = get_terms(
		'sunshine-product-category',
		array(
			'hide_empty' => false,
			'orderby'    => 'meta_value_num',
			'meta_key'   => 'order',
			'order'      => 'ASC',
		)
	);
	if ( ! empty( $terms ) ) {
		$product_categories = array();
		foreach ( $terms as $term ) {
			// Filter based on price level
			if ( $price_level ) {
				$products = sunshine_get_products( $price_level, $term->term_id, $type );
				if ( empty( $products ) ) {
					continue;
				}
			}
			$product_categories[] = new SPC_Product_Category( $term );
		}
		return $product_categories;
	}
	return false;
}
