<?php
/*
add_shortcode( 'sunshine-photo-cart', 'sunshine_content_shortcode' );
add_shortcode( 'sunshine', 'sunshine_content_shortcode' );
function sunshine_content_shortcode() {
	if ( !is_admin() ) {
		return SPC()->frontend->sunshine_content( $content = '', true );
	}
}
*/

// TODO: Run everything off shortcodes?!

add_shortcode( 'sunshine_galleries', 'sunshine_galleries_shortcode' );
function sunshine_galleries_shortcode() {

	if ( is_admin() ) {
		return false;
	}

	ob_start();
	do_action( 'sunshine_before_content' );
	do_action( 'sunshine_galleries' );
	do_action( 'sunshine_after_content' );
	$output = ob_get_contents();
	ob_end_clean();

	return $output;

}

add_shortcode( 'sunshine_gallery', 'sunshine_gallery_shortcode' );
function sunshine_gallery_shortcode( $atts ) {

	if ( is_admin() || wp_doing_ajax() ) {
		return false;
	}

	$atts = shortcode_atts(
		array(
			'id'             => '',
			'show_main_menu' => false,
		),
		$atts
	);

	$gallery = sunshine_get_gallery( $atts['id'] );
	if ( ! $gallery->get_id() ) {
		return '<p>' . __( 'Sorry, no gallery with that ID', 'sunshine-photo-cart' ) . '</p>';
	}

	ob_start();
	echo '<div id="sunshine" class="' . esc_attr( sunshine_classes( false ) ) . '">';
	echo '<div id="sunshine--main"><div class="sunshine--container">';
	if ( $atts['show_main_menu'] && $atts['show_main_menu'] == 'true' ) {
		sunshine_main_menu();
	}
	sunshine_single_gallery_display( $gallery );
	echo '</div></div> <!-- CLOSE "sunshine--main" -->';
	echo '</div> <!-- CLOSE "sunshine" -->';
	$output = ob_get_contents();
	ob_end_clean();

	return $output;

}

add_shortcode( 'sunshine_cart', 'sunshine_cart_shortcode' );
function sunshine_cart_shortcode() {

	if ( is_admin() ) {
		return false;
	}

	ob_start();
	do_action( 'sunshine_before_content' );
	do_action( 'sunshine_cart' );
	do_action( 'sunshine_after_content' );
	$output = ob_get_contents();
	ob_end_clean();

	return $output;

}

add_shortcode( 'sunshine_checkout', 'sunshine_checkout_shortcode' );
function sunshine_checkout_shortcode() {

	if ( is_admin() ) {
		return false;
	}

	ob_start();
	do_action( 'sunshine_before_content' );
	do_action( 'sunshine_checkout' );
	do_action( 'sunshine_after_content' );
	$output = ob_get_contents();
	ob_end_clean();

	return $output;

}

add_shortcode( 'sunshine_favorites', 'sunshine_favorites_shortcode' );
function sunshine_favorites_shortcode() {

	if ( is_admin() ) {
		return false;
	}

	ob_start();
	do_action( 'sunshine_before_content' );
	do_action( 'sunshine_favorites' );
	do_action( 'sunshine_after_content' );
	$output = ob_get_contents();
	ob_end_clean();

	return $output;

}

add_shortcode( 'sunshine_account', 'sunshine_account_shortcode' );
function sunshine_account_shortcode() {

	if ( is_admin() ) {
		return false;
	}

	ob_start();
	do_action( 'sunshine_before_content' );
	do_action( 'sunshine_account' );
	do_action( 'sunshine_after_content' );
	$output = ob_get_contents();
	ob_end_clean();

	return $output;

}


add_shortcode( 'sunshine_gallery_password', 'sunshine_gallery_password_shortcode' );
add_shortcode( 'sunshine-gallery-password', 'sunshine_gallery_password_shortcode' );
function sunshine_gallery_password_shortcode() {

	if ( is_admin() ) {
		return false;
	}

	ob_start();
	SPC()->notices->show();
	// do_action( 'sunshine_before_content' );
	sunshine_gallery_password_form();
	// do_action( 'sunshine_after_content' );
	$output = ob_get_contents();
	ob_end_clean();

	return $output;

}

add_shortcode( 'sunshine_menu', 'sunshine_menu_shortcode' );
function sunshine_menu_shortcode() {
	return sunshine_main_menu( false );
}

add_shortcode( 'sunshine_search', 'sunshine_search_shortcode' );
function sunshine_search_shortcode( $atts ) {
	$atts    = shortcode_atts(
		array(
			'gallery' => '',
		),
		$atts,
		'sunshine_search'
	);
	$gallery = '';
	if ( ! empty( $atts['gallery'] ) ) {
		$g = sunshine_get_gallery( intval( $atts['gallery'] ) );
		if ( $g ) {
			$gallery = $g;
		}
	}
	return sunshine_search_form( $gallery, false );
}

add_shortcode( 'sunshine_cart_quantity', 'sunshine_cart_quantity_shortcode' );
function sunshine_cart_quantity_shortcode() {
	return '<span class="sunshine--cart--count sunshine--count">' . SPC()->cart->get_item_count() . '</span>';
}

add_shortcode( 'sunshine_mini_cart', 'sunshine_mini_cart_shortcode' );
function sunshine_mini_cart_shortcode() {
	return sunshine_get_template_html( 'cart/mini-cart' );
}
