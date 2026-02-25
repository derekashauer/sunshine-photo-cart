<?php
/**
 * Customer account related function
 *
 * @package SunshinePhotoCart\Functions
 * @version 3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get customer class
 *
 * @since  3.0.0
 * @return class SPC_Customer
 */
function sunshine_get_customer( $user_id ) {
	return new SPC_Customer( $user_id );
}


/**
 * Get default customer role for Sunshine
 *
 * @since  3.0.0
 * @return string WordPress role, can be filtered
 */
function sunshine_get_customer_role() {
	return apply_filters( 'sunshine_customer_role', 'sunshine_customer' );
}

/**
 * Get customers
 *
 * @since  3.0.0
 * @return array|SPC_Customer Array of SPC_Customer
 */
function sunshine_get_customers( $custom_args = array() ) {

	$roles = array( sunshine_get_customer_role() );
	if ( is_admin() ) {
		$roles[] = 'administrator';
	}

	$args       = array(
		'role__in' => $roles,
	);
	$args       = wp_parse_args( $custom_args, $args );
	$user_query = new WP_User_Query( $args );
	$users      = $user_query->get_results();

	if ( ! empty( $users ) ) {
		$customers = array();
		foreach ( $users as $user ) {
			$customers[ $user->ID ] = new SPC_Customer( $user->ID );
		}
		return $customers;
	}
	return array();
}

/**
 * Get sign up template
 *
 * @since  3.0.0
 */
function sunshine_modal_display_signup() {

	$result = array( 'html' => sunshine_get_template_html( 'account/signup' ) );
	wp_send_json_success( $result );

}
add_action( 'sunshine_modal_display_signup', 'sunshine_modal_display_signup' );

/**
 * Get login template
 *
 * @since  3.0.0
 */
function sunshine_modal_display_login() {

	$result = array( 'html' => sunshine_get_template_html( 'account/login' ) );
	wp_send_json_success( $result );

}
add_action( 'sunshine_modal_display_login', 'sunshine_modal_display_login' );

/**
 * Get login/sign up template
 *
 * @since  3.0.0
 */
function sunshine_modal_display_require_login() {

	if ( is_user_logged_in() ) {
		return false;
	}

	$post_data = wp_unslash( $_POST );

	if ( ! isset( $post_data['security'] ) || ! wp_verify_nonce( $post_data['security'], 'sunshinephotocart' ) ) {
		SPC()->log( 'Login failed nonce' );
		wp_send_json_error( __( 'Invalid login attempt', 'sunshine-photo-cart' ) );
	}

	// If we passed the image id to add to favorites, set session to process after login.
	if ( isset( $post_data['after'] ) && isset( $post_data['imageId'] ) ) {
		SPC()->session->set( 'add_to_favorites', intval( $post_data['imageId'] ) );
	}

	// Build a message that can appear at the top of this modal template.
	$message = apply_filters( 'sunshine_account_require_login_message', '', $post_data );

	$result = array( 'html' => sunshine_get_template_html( 'account/login-signup', array( 'message' => $message ) ) );
	wp_send_json_success( $result );

}
add_action( 'sunshine_modal_display_require_login', 'sunshine_modal_display_require_login' );

/**
 * Process login request
 *
 * @since  3.0.0
 */
function sunshine_modal_login() {

	$post_data = wp_unslash( $_POST );

	if ( ! isset( $post_data['email'] ) || ! isset( $post_data['password'] ) || ! isset( $post_data['security'] ) || ! wp_verify_nonce( $post_data['security'], 'sunshine_login' ) ) {
		SPC()->log( 'Login failed nonce' );
		wp_send_json_error( __( 'Invalid login attempt', 'sunshine-photo-cart' ) );
	}

	// Honeypot check - if this field has a value, it's likely a bot.
	if ( ! empty( $post_data['sunshine_login_website'] ) ) {
		SPC()->log( 'Login blocked - spam detected' );
		wp_send_json_error( __( 'Invalid login attempt', 'sunshine-photo-cart' ) );
	}

	// Allow 3rd party validation (e.g., captcha).
	$validation_error = apply_filters( 'sunshine_login_validation', '', $post_data );
	if ( ! empty( $validation_error ) ) {
		SPC()->log( 'Login blocked - validation failed: ' . $validation_error );
		wp_send_json_error( $validation_error );
	}

	$creds = array(
		'user_login'    => sanitize_email( $post_data['email'] ),
		'user_password' => sanitize_text_field( $post_data['password'] ),
		'remember'      => true,
	);
	$login = wp_signon( $creds, is_ssl() );
	if ( is_wp_error( $login ) ) {
		SPC()->log( 'Failed modal login: ' . $login->get_error_message() );
		wp_send_json_error( __( 'Invalid email or password, please try again', 'sunshine-photo-cart' ) );
	}

	SPC()->customer = new SPC_Customer( $login->ID );
	// SPC()->customer->add_action( 'login' );
	$cart = SPC()->session->get( 'cart' );
	if ( $cart ) {
		SPC()->customer->set_cart( $cart );
	}

	SPC()->log( 'Customer logged in: ' . SPC()->customer->get_id() );
	SPC()->notices->add( __( 'You have been logged in', 'sunshine-photo-cart' ) );

	// Let after login actions have a chance to do something.
	do_action( 'sunshine_after_login', $post_data );

	$result = apply_filters( 'sunshine_after_login_result', array(), SPC()->customer );
	if ( ! empty( $result['redirect_to'] ) ) {
		$result['redirect_to'] = esc_url( $result['redirect_to'] );
	}

	wp_send_json_success( $result );

}
add_action( 'wp_ajax_nopriv_sunshine_modal_login', 'sunshine_modal_login' );
add_action( 'wp_ajax_sunshine_modal_login', 'sunshine_modal_login' );

add_action( 'wp_logout', 'sunshine_logout' );
function sunshine_logout( $user_id ) {
	$customer = new SPC_Customer( $user_id );
	$cart     = $customer->get_cart();
	if ( $cart ) {
		SPC()->session->set( 'cart', $cart );
	}
}

/**
 * Process signup request
 *
 * @since  3.0.0
 */
function sunshine_modal_signup() {

	if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( $_POST['security'], 'sunshine_signup' ) ) {
		SPC()->log( 'Signup failed nonce' );
		wp_send_json_error( __( 'Invalid signup attempt', 'sunshine-photo-cart' ) );
	}

	// Honeypot check - if this field has a value, it's likely a bot.
	if ( ! empty( $_POST['sunshine_signup_website'] ) ) {
		SPC()->log( 'Signup blocked - spam detected' );
		wp_send_json_error( __( 'Invalid signup attempt', 'sunshine-photo-cart' ) );
	}

	// Allow 3rd party validation (e.g., captcha).
	$validation_error = apply_filters( 'sunshine_signup_validation', '', $_POST );
	if ( ! empty( $validation_error ) ) {
		SPC()->log( 'Signup blocked - validation failed: ' . $validation_error );
		wp_send_json_error( $validation_error );
	}

	$email = sanitize_email( $_POST['sunshine_signup_email'] );

	// Check if valid email.
	if ( ! is_email( $email ) ) {
		wp_send_json_error( __( 'Invalid email address', 'sunshine-photo-cart' ) );
	}

	// Get user by email address.
	$user = get_user_by( 'email', $email );
	if ( $user ) {
		wp_send_json_error( __( 'User account already exists with that email address', 'sunshine-photo-cart' ) );
	}

	$password_notice = false;
	if ( empty( $_POST['sunshine_signup_password'] ) ) {
		$password        = wp_generate_password();
		$password_notice = true;
	} else {
		$password = sanitize_text_field( $_POST['sunshine_signup_password'] );
	}

	$first_name = ( ! empty( $_POST['sunshine_signup_first_name'] ) ) ? sanitize_text_field( $_POST['sunshine_signup_first_name'] ) : '';
	$last_name  = ( ! empty( $_POST['sunshine_signup_last_name'] ) ) ? sanitize_text_field( $_POST['sunshine_signup_last_name'] ) : '';

	$args    = array(
		'user_login' => $email,
		'user_email' => $email,
		'user_pass'  => $password,
		'role'       => sunshine_get_customer_role(),
		'first_name' => $first_name,
		'last_name'  => $last_name,
	);
	$user_id = wp_insert_user( $args );
	if ( is_wp_error( $user_id ) ) {
		wp_send_json_error( $user_id->get_error_message() );
	}

	$creds = array(
		'user_login'    => $email,
		'user_password' => $password,
		'remember'      => true,
	);
	$login = wp_signon( $creds, is_ssl() );
	if ( is_wp_error( $login ) ) {
		wp_send_json_error( $login->get_error_message() );
	}

	$customer       = new SPC_Customer( $login->ID );
	SPC()->customer = $customer;
	// SPC()->customer->add_action( 'signup' );

	SPC()->notices->add(
		sprintf(
			/* translators: %s: User email address */
			__( 'A new user account for %s has been created and you have been automatically logged in', 'sunshine-photo-cart' ),
			$email
		)
	);

	do_action( 'sunshine_after_signup', $customer, $email, $password_notice );

	wp_send_json_success();

}
add_action( 'wp_ajax_nopriv_sunshine_modal_signup', 'sunshine_modal_signup' );
add_action( 'wp_ajax_sunshine_modal_signup', 'sunshine_modal_signup' );

/**
 * Process reset password
 *
 * @since  3.0.0
 */
function sunshine_modal_reset_password() {

	if ( empty( $_POST['security'] ) || ! wp_verify_nonce( $_POST['security'], 'sunshine_reset_password_nonce' ) ) {
		SPC()->log( 'Password reset failed nonce' );
		wp_send_json_error( __( 'Invalid password reset attempt', 'sunshine-photo-cart' ) );
	}

	// Honeypot check - if this field has a value, it's likely a bot.
	if ( ! empty( $_POST['sunshine_reset_website'] ) ) {
		SPC()->log( 'Password reset blocked - spam detected' );
		wp_send_json_error( __( 'Invalid password reset attempt', 'sunshine-photo-cart' ) );
	}

	// Allow 3rd party validation (e.g., captcha).
	$validation_error = apply_filters( 'sunshine_lost_password_validation', '', $_POST );
	if ( ! empty( $validation_error ) ) {
		SPC()->log( 'Password reset blocked - validation failed: ' . $validation_error );
		wp_send_json_error( $validation_error );
	}

	$email = sanitize_email( $_POST['email'] );

	// Check if valid email.
	if ( ! is_email( $email ) ) {
		wp_send_json_error( __( 'Invalid email address', 'sunshine-photo-cart' ) );
	}

	// Get user by email address.
	$user = get_user_by( 'email', $email );
	if ( empty( $user ) ) {
		wp_send_json_error( __( 'No user with that email address', 'sunshine-photo-cart' ) );
	}

	SPC()->notices->add(
		sprintf(
			/* translators: %s: User email address */
			__( 'An email has been sent to %s with information on resetting your password', 'sunshine-photo-cart' ),
			$email
		)
	);

	do_action( 'sunshine_reset_password', $user );

	wp_send_json_success();

}
add_action( 'wp_ajax_nopriv_sunshine_modal_reset_password', 'sunshine_modal_reset_password' );
add_action( 'wp_ajax_sunshine_modal_reset_password', 'sunshine_modal_reset_password' );

/**
 * Process signup request
 *
 * @since  3.0.0
 */
function sunshine_password_reset_process() {

	if ( empty( $_POST['sunshine_password_reset'] ) || empty( $_POST['key'] ) || ! wp_verify_nonce( $_POST['sunshine_password_reset'], 'sunshine_password_reset_' . $_POST['key'] ) ) {
		return;
	}

	if ( empty( $_POST['login'] ) || empty( $_POST['sunshine_new_password'] ) || empty( $_POST['sunshine_new_password_confirm'] ) ) {
		SPC()->log( 'Invalid password reset attempt - missing information' );
		SPC()->notices->add( __( 'Invalid password reset attempt', 'sunshine-photo-cart' ) );
		wp_safe_redirect( sunshine_get_page_url( 'account' ) );
		exit;
	}

	$key              = sanitize_text_field( $_POST['key'] );
	$login            = sanitize_text_field( $_POST['login'] );
	$password         = sanitize_text_field( $_POST['sunshine_new_password'] );
	$password_confirm = sanitize_text_field( $_POST['sunshine_new_password_confirm'] );

	$user = check_password_reset_key( $key, $login );
	if ( is_wp_error( $user ) ) {
		SPC()->log( 'Invalid password reset attempt - invalid key' );
		SPC()->notices->add( __( 'Invalid password reset attempt', 'sunshine-photo-cart' ), 'error' );
		wp_safe_redirect( sunshine_get_page_url( 'account' ) );
		exit;
	}

	if ( $password != $password_confirm ) {
		SPC()->log( 'Invalid password reset attempt - passwords do not match' );
		SPC()->notices->add( __( 'Passwords do not match', 'sunshine-photo-cart' ), 'error' );
		return;
	}

	wp_set_password( $password, $user->ID );
	SPC()->log( 'Password reset successful for user: ' . $user->ID );
	SPC()->notices->add( __( 'Password has been set', 'sunshine-photo-cart' ) );
	wp_safe_redirect( sunshine_get_account_endpoint_url( 'login' ) );
	exit;

}
add_action( 'wp', 'sunshine_password_reset_process' );

/**
 * Get array of account menu items
 *
 * @since  3.0.0
 * @return array
 */
function sunshine_get_account_menu_items() {
	$account_url = sunshine_get_page_url( SPC()->get_page( 'account' ) );
	$items       = array(
		'dashboard' => array(
			'url'   => sunshine_get_account_endpoint_url( 'dashboard' ),
			'label' => __( 'Dashboard', 'sunshine-photo-cart' ),
			'order' => 10,
		),
		'orders'    => array(
			'url'   => sunshine_get_account_endpoint_url( 'orders' ),
			'label' => __( 'Orders', 'sunshine-photo-cart' ),
			'order' => 20,
		),
		'galleries' => array(
			'url'   => sunshine_get_account_endpoint_url( 'galleries' ),
			'label' => __( 'Galleries', 'sunshine-photo-cart' ),
			'order' => 30,
		),
		'addresses' => array(
			'url'   => sunshine_get_account_endpoint_url( 'addresses' ),
			'label' => __( 'Addresses', 'sunshine-photo-cart' ),
			'order' => 40,
		),
		'profile'   => array(
			'url'   => sunshine_get_account_endpoint_url( 'profile' ),
			'label' => __( 'Account Details', 'sunshine-photo-cart' ),
			'order' => 50,
		),
		'logout'    => array(
			'url'   => sunshine_get_account_endpoint_url( 'logout' ),
			'label' => __( 'Logout', 'sunshine-photo-cart' ),
			'order' => 1000,
		),
	);

	$items = apply_filters( 'sunshine_account_menu_items', $items );

	usort(
		$items,
		function( $a, $b ) {
			return $a['order'] <=> $b['order'];
		}
	);

	return $items;

}

/**
 * Get url for an account endpoint
 *
 * @since  3.0.0
 * @return string URL of endpoint
 */
function sunshine_get_account_endpoint_url( $endpoint ) {
	$account_url = sunshine_get_page_url( 'account' );
	$url         = '';
	if ( 'dashboard' == $endpoint ) {
		return $account_url;
	} else {
		$endpoints = sunshine_get_account_endpoints();
		if ( array_key_exists( $endpoint, $endpoints ) ) {
			$url = trailingslashit( $account_url ) . $endpoints[ $endpoint ];
		}
	}
	return apply_filters( 'sunshine_account_endpoint_url', $url, $endpoint );
}

/**
 * Get available account endpoints
 *
 * @since  3.0.0
 * @return string URL of endpoint
 */
function sunshine_get_account_endpoints() {
	return apply_filters(
		'sunshine_account_endpoints',
		array(
			'orders'         => SPC()->get_option( 'account_orders_endpoint', 'my-orders' ),
			'view-order'     => SPC()->get_option( 'account_view_order_endpoint', 'order-details' ),
			'addresses'      => SPC()->get_option( 'account_addresses_endpoint', 'my-addresses' ),
			'profile'        => SPC()->get_option( 'account_edit_endpoint', 'my-profile' ),
			'galleries'      => SPC()->get_option( 'account_galleries_endpoint', 'my-galleries' ),
			'reset-password' => SPC()->get_option( 'account_reset_password_endpoint', 'reset-password' ),
			'login'          => SPC()->get_option( 'account_login_endpoint', 'login' ),
			'logout'         => SPC()->get_option( 'account_logout_endpoint', 'logout' ),
		)
	);
}

add_action( 'wp', 'sunshine_login_process' );
function sunshine_login_process() {

	if ( ! isset( $_POST['sunshine_login'] ) || ! wp_verify_nonce( $_POST['sunshine_login'], 'sunshine_login' ) ) {
		return;
	}

	// Honeypot check - if this field has a value, it's likely a bot.
	if ( ! empty( $_POST['sunshine_login_website'] ) ) {
		SPC()->log( 'Login blocked - spam detected' );
		SPC()->notices->add( __( 'Invalid login attempt', 'sunshine-photo-cart' ), 'error' );
		return;
	}

	// Allow 3rd party validation (e.g., captcha).
	$validation_error = apply_filters( 'sunshine_login_validation', '', $_POST );
	if ( ! empty( $validation_error ) ) {
		SPC()->log( 'Login blocked - validation failed: ' . $validation_error );
		SPC()->notices->add( $validation_error, 'error' );
		return;
	}

	$creds = array(
		'user_login'    => sanitize_email( $_POST['sunshine_login_email'] ),
		'user_password' => sanitize_text_field( $_POST['sunshine_login_password'] ),
		'remember'      => true,
	);
	$login = wp_signon( $creds, is_ssl() );
	if ( is_wp_error( $login ) ) {
		SPC()->notices->add( __( 'Invalid email or password, please try again', 'sunshine-photo-cart' ), 'error' );
		return;
	}

	SPC()->notices->add( __( 'You have been logged in', 'sunshine-photo-cart' ) );

	// Let after login actions have a change to do something
	do_action( 'sunshine_after_login', $_POST );

	$result = array();

	if ( ! empty( $_POST['redirect'] ) ) {
		$result['redirect'] = sanitize_url( $_POST['redirect'] );
	} elseif ( SPC()->frontend->is_gallery() ) {
		$result['redirect'] = SPC()->frontend->current_gallery->get_permalink();
	} else {
		$result['redirect'] = sunshine_get_account_endpoint_url( 'dashboard' );
	}

	$user     = get_user_by( 'email', sanitize_email( $_POST['sunshine_login_email'] ) );
	$customer = new SPC_Customer( $user->ID );

	$result = apply_filters( 'sunshine_after_login_result', $result, $customer );

	wp_safe_redirect( $result['redirect'] );
	exit;

}

function sunshine_get_profile_fields() {
	$fields   = array();
	$fields[] = array(
		'id'           => 'first_name',
		'type'         => 'text',
		'name'         => __( 'First Name', 'sunshine-photo-cart' ),
		'default'      => SPC()->customer->get_first_name(),
		'autocomplete' => 'given-name',
		'size'         => 'half',
	);
	$fields[] = array(
		'id'           => 'last_name',
		'type'         => 'text',
		'name'         => __( 'Last Name', 'sunshine-photo-cart' ),
		'default'      => SPC()->customer->get_last_name(),
		'autocomplete' => 'family-name',
		'size'         => 'half',
	);
	$fields[] = array(
		'id'      => 'email',
		'type'    => 'email',
		'name'    => __( 'Email', 'sunshine-photo-cart' ),
		'default' => SPC()->customer->get_email(),
	);
	$fields[] = array(
		'id'          => 'current_password',
		'type'        => 'password',
		'name'        => __( 'Current Password', 'sunshine-photo-cart' ),
		'description' => __( 'Leave empty to keep current password', 'sunshine-photo-cart' ),
	);
	$fields[] = array(
		'id'          => 'new_password',
		'type'        => 'password',
		'name'        => __( 'New Password', 'sunshine-photo-cart' ),
		'description' => __( 'Leave empty to keep current password', 'sunshine-photo-cart' ),
	);
	$fields[] = array(
		'id'   => 'new_password_confirm',
		'type' => 'password',
		'name' => __( 'Confirm New Password', 'sunshine-photo-cart' ),
	);
	$fields   = apply_filters( 'sunshine_profile_fields', $fields );
	return $fields;
}

add_action( 'wp', 'sunshine_save_profile' );
function sunshine_save_profile() {

	if ( ! is_user_logged_in() || ! isset( $_POST['save_profile'] ) || ! wp_verify_nonce( $_POST['save_profile'], 'save_profile' ) ) {
		return;
	}

	// Check we have both password fields
	if ( ! empty( $_POST['current_password'] ) ) {
		if ( empty( $_POST['new_password'] ) || empty( $_POST['new_password_confirm'] ) ) {
			SPC()->notices->add( __( 'Missing required password reset fields', 'sunshine-photo-cart' ), 'error' );
		} elseif ( $_POST['new_password'] != $_POST['new_password_confirm'] ) {
			SPC()->notices->add( __( 'New passwords do not match', 'sunshine-photo-cart' ), 'error' );
		} else {
			$new_password = sanitize_text_field( $_POST['new_password'] );
			wp_set_password( $new_password, get_current_user_id() );
			wp_signon(
				array(
					'user_login'    => SPC()->customer->get_email(),
					'user_password' => $new_password,
				)
			);
			SPC()->notices->add( __( 'Password updated', 'sunshine-photo-cart' ) );
		}
	}

	$first_name = ( ! empty( $_POST['first_name'] ) ) ? sanitize_text_field( $_POST['first_name'] ) : '';
	$last_name  = ( ! empty( $_POST['last_name'] ) ) ? sanitize_text_field( $_POST['last_name'] ) : '';
	$email      = ( ! empty( $_POST['email'] ) ) ? sanitize_email( $_POST['email'] ) : '';

	$result = wp_update_user(
		array(
			'ID'         => get_current_user_id(),
			'user_email' => $email,
			'user_login' => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
		)
	);
	if ( $result ) {
		SPC()->notices->add( __( 'Profile updated', 'sunshine-photo-cart' ) );
	}

	// Redirect to login
	wp_safe_redirect( sunshine_get_account_endpoint_url( 'profile' ) );
	exit;

}

add_action( 'wp', 'sunshine_save_addresses' );
function sunshine_save_addresses() {

	if ( ! is_user_logged_in() || ! isset( $_POST['save_addresses'] ) || ! wp_verify_nonce( $_POST['save_addresses'], 'save_addresses' ) ) {
		return;
	}

	$default_country = SPC()->customer->get_shipping_country();
	$fields          = SPC()->countries->get_address_fields( $default_country, 'shipping_' );

	if ( ! empty( $fields ) ) {
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field['id'] ] ) ) {
				$value = sanitize_text_field( $_POST[ $field['id'] ] );
				SPC()->customer->update_meta( $field['id'], sanitize_text_field( $value ) );
			}
		}
		SPC()->notices->add( __( 'Addresses updated', 'sunshine-photo-cart' ) );
	}

	// Redirect to login
	wp_safe_redirect( sunshine_get_account_endpoint_url( 'addresses' ) );
	exit;

}

add_action( 'wp_login', 'sunshine_track_user_login', 10, 2 );
function sunshine_track_user_login( $username, $user ) {
	update_user_meta( $user->ID, 'sunshine_last_login', current_time( 'timestamp' ) );
}
