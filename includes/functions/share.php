<?php
add_action( 'sunshine_modal_display_share', 'sunshine_modal_display_share' );
function sunshine_modal_display_share() {

	if ( empty( $_POST['imageId'] ) ) {
		wp_send_json_error( __( 'No image ID provided', 'sunshine-photo-cart' ) );
	}

	$image = sunshine_get_image( intval( $_POST['imageId'] ) );
	if ( empty( $image->get_id() ) ) {
		wp_send_json_error( __( 'Not a valid image ID', 'sunshine-photo-cart' ) );
	}

	do_action( 'sunshine_image_share', $image );

	$result = array( 'html' => sunshine_get_template_html( 'image/share', array( 'image' => $image ) ) );
	wp_send_json_success( $result );

}
