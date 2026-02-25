<?php

function sunshine_get_product_options( $price_level = 'all' ) {

	$product_options = get_terms(
		array(
			'taxonomy'   => 'sunshine-product-option',
			'hide_empty' => false,
			'parent'     => 0,
		)
	);
	if ( ! empty( $product_options ) ) {
		$final_product_options = array();
		foreach ( $product_options as $product_option ) {
			$final_product_options[] = new SPC_Product_Option( $product_option );
		}
		return $final_product_options;
	}

	return false;

}

function sunshine_get_product_option_types() {
	return array(
		'select'   => __( 'Select one of many', 'sunshine-photo-cart' ),
		'checkbox' => __( 'Single checkbox (Yes/No)', 'sunshine-photo-cart' ),
		'text'     => __( 'Text field', 'sunshine-photo-cart' ),
	);
}
