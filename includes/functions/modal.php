<?php
add_action( 'wp_ajax_nopriv_sunshine_modal_display', 'sunshine_modal_display' );
add_action( 'wp_ajax_sunshine_modal_display', 'sunshine_modal_display' );
function sunshine_modal_display() {

	if ( empty( $_POST['hook'] ) || empty( $_POST['security'] ) || ! wp_verify_nonce( $_POST['security'], 'sunshinephotocart' ) ) {
		SPC()->log( 'Failed modal security check' . ': ' . print_r( $_POST, 1 ) );
		wp_send_json_error( array( 'reason' => __( 'Failed security check', 'sunshine-photo-cart' ) ) );
	}

	$hook = sanitize_text_field( $_POST['hook'] );
	do_action( 'sunshine_modal_display_' . $hook );

}

function sunshine_modal_check_security( $key = 'sunshinephotocart', $text = '' ) {

	if ( empty( $_POST['security'] ) || ! wp_verify_nonce( $_POST['security'], $key ) ) {
		SPC()->log( 'Failed modal security check' . ': ' . print_r( $_POST, 1 ) );
		if ( empty( $text ) ) {
			$text = __( 'Failed security check', 'sunshine-photo-cart' );
		}
		wp_send_json_error( $text );
	}

}
