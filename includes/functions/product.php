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

	$args = wp_parse_args(
		$args,
		array(
			'nopaging'   => true,
			'meta_query' => array(),
			'tax_query'  => array(),
			'orderby'    => 'menu_order',
			'order'      => 'ASC',
		)
	);

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

	// Static cache to avoid redundant DB queries within the same request.
	static $cache = array();
	$cache_key    = md5( serialize( array( $price_level, $category, $types, $args, $ignore_price ) ) );
	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	$products = get_posts( $args );
	if ( ! empty( $products ) ) {
		$final_products      = array();
		$this_price_level_id = ( $price_level === 'all' ) ? '' : intval( $price_level );
		foreach ( $products as $product ) {
			$p = sunshine_get_product( $product, $this_price_level_id );
			if ( ( $price_level == 'all' || $ignore_price || $p->get_price( $this_price_level_id ) !== '' ) && ( empty( $types ) || in_array( $p->get_type(), $types ) ) ) {
				$final_products[] = $p;
			}
		}
		$cache[ $cache_key ] = $final_products;
		return $final_products;
	}

	$cache[ $cache_key ] = false;
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
	$terms = get_terms(
		'sunshine-product-category',
		array(
			'hide_empty' => 0,
			'meta_key'   => 'default',
			'meta_value' => 1,
		)
	);
	if ( ! empty( $terms ) ) {
		return new SPC_Product_Category( $terms[0] );
	}
	// No default category marked — fall back to first available category
	$all_terms = get_terms(
		'sunshine-product-category',
		array(
			'hide_empty' => 0,
			'orderby'    => 'meta_value_num',
			'meta_key'   => 'order',
			'order'      => 'ASC',
			'number'     => 1,
		)
	);
	if ( ! empty( $all_terms ) ) {
		return new SPC_Product_Category( $all_terms[0] );
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

		if ( $price_level ) {
			// Fetch all products once and determine which categories have products.
			$all_products        = sunshine_get_products( $price_level, '', $type );
			$active_category_ids = array();
			if ( ! empty( $all_products ) ) {
				foreach ( $all_products as $product ) {
					$cat_id = $product->get_category_id();
					if ( $cat_id ) {
						$active_category_ids[ $cat_id ] = true;
					}
				}
			}
			foreach ( $terms as $term ) {
				if ( isset( $active_category_ids[ $term->term_id ] ) ) {
					$product_categories[] = new SPC_Product_Category( $term );
				}
			}
		} else {
			foreach ( $terms as $term ) {
				$product_categories[] = new SPC_Product_Category( $term );
			}
		}

		return $product_categories;
	}
	return false;
}
