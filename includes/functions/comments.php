<?php
add_action( 'sunshine_modal_display_comments', 'sunshine_modal_display_comments' );
function sunshine_modal_display_comments() {

	if ( empty( $_POST['imageId'] ) ) {
		wp_send_json_error( __( 'No image ID provided', 'sunshine-photo-cart' ) );
	}

	$image = sunshine_get_image( intval( $_POST['imageId'] ) );
	if ( empty( $image->get_id() ) ) {
		wp_send_json_error( __( 'Not a valid image ID', 'sunshine-photo-cart' ) );
	}

	$comments = $image->get_comments();
	$result   = array(
		'html' => sunshine_get_template_html(
			'image/comments',
			array(
				'image'    => $image,
				'comments' => $comments,
			)
		),
	);

	wp_send_json_success( $result );

}

add_action( 'wp_ajax_nopriv_sunshine_modal_add_comment', 'sunshine_modal_add_comment' );
add_action( 'wp_ajax_sunshine_modal_add_comment', 'sunshine_modal_add_comment' );
function sunshine_modal_add_comment() {

	if ( ! wp_verify_nonce( $_POST['security'], 'sunshine_image_comment_nonce' ) ) {
		SPC()->log( 'Add comment failed nonce' );
		wp_send_json_error( __( 'Invalid comment submission - failed security check', 'sunshine-photo-cart' ) );
	}

	if ( empty( $_POST['image_id'] ) || empty( $_POST['content'] ) ) {
		SPC()->log( 'Add comment failed: No image ID or comment content' );
		wp_send_json_error( __( 'Invalid comment submission - no image ID or content', 'sunshine-photo-cart' ) );
	}

	$image_id = intval( $_POST['image_id'] );
	$image = sunshine_get_image( $image_id );
	if ( empty( $image ) ) {
		SPC()->log( 'Add comment failed: No image found' );
		wp_send_json_error( __( 'Invalid comment submission - no image found', 'sunshine-photo-cart' ) );
	}

	if ( ! $image->allow_comments() ) {
		SPC()->log( 'Add comment failed: Attempt on image within gallery that does not allow comments' );
		wp_send_json_error( __( 'Invalid comment submission - comments not allowed', 'sunshine-photo-cart' ) );
	}

	$content = sanitize_textarea_field( $_POST['content'] );

	$args = array(
		'comment_post_ID'  => intval( $image_id ),
		'comment_approved' => ( $image->comments_require_approval() ) ? 0 : 1,
		'comment_content'  => $content,
	);

	if ( is_user_logged_in() ) {
		$args['comment_author']       = SPC()->customer->get_name();
		$args['comment_author_email'] = SPC()->customer->get_email();
		$args['user_id']              = SPC()->customer->get_id();
	} else {
		$name = sanitize_text_field( $_POST['name'] );
		$email = sanitize_email( $_POST['email'] );
		if ( ! is_email( $email ) ) {
			SPC()->log( 'Add comment failed: Not valid email address' );
			wp_send_json_error( __( 'Invalid comment submission - invalid email address', 'sunshine-photo-cart' ) );
		}
		$args['comment_author']       = $name;
		$args['comment_author_email'] = $email;

		// Let's see if this email is tied to an account by chance, what the heck
		$user = get_user_by( 'email', $email );
		if ( ! empty( $user ) ) {
			$args['user_id'] = $user->ID;
		}
	}

	$comment_id = wp_insert_comment( $args );
	if ( $comment_id ) {
		$comment = get_comment( $comment_id );
		$result  = array(
			'html'  => sunshine_get_template_html(
				'image/comment',
				array(
					'image'   => $image,
					'comment' => $comment,
				)
			),
			'count' => $image->get_comment_count(),
		);

		do_action( 'sunshine_add_comment', $comment, $image );

		wp_send_json_success( $result );
	}

	SPC()->log( 'Add comment failed: Not added to database' );
	wp_send_json_error( __( 'Invalid comment submission - unknown reason', 'sunshine-photo-cart' ) );

}
