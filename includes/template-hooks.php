<?php
/* GENERAL */

add_action( 'sunshine_before_content', 'sunshine_show_notices', 5 );
function sunshine_show_notices() {
	SPC()->notices->show();
}

add_action( 'sunshine_before_content', 'sunshine_before_custom_content', 4 );
function sunshine_before_custom_content() {
	$before = SPC()->get_option( 'before' );
	if ( $before ) {
		echo '<div id="sunshine--before">' . do_shortcode( wp_kses_post( $before ) ) . '</div>';
	}
}

add_action( 'sunshine_after_content', 'sunshine_after_custom_content', 0 );
function sunshine_after_custom_content() {
	$after = SPC()->get_option( 'after' );
	if ( $after ) {
		echo '<div id="sunshine--after">' . do_shortcode( wp_kses_post( $after ) ) . '</div>';
	}
}

add_action( 'sunshine_single_gallery', 'sunshine_show_gallery_page_header', 8 );
function sunshine_show_gallery_page_header() {
	if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
		// return;
	}
	sunshine_get_template( 'gallery/page-header', array( 'gallery' => SPC()->frontend->current_gallery ) );
}

add_action( 'sunshine_single_image', 'sunshine_show_image_page_header', 8 );
function sunshine_show_image_page_header() {
	if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
		// return;
	}
	sunshine_get_template( 'image/page-header', array( 'image' => SPC()->frontend->current_image ) );
}

/* GALLERIES */
add_action( 'sunshine_galleries', 'sunshine_gallery_loop_display', 50 );
function sunshine_gallery_loop_display( $galleries = array() ) {

	if ( ! empty( $galleries ) && ! is_array( $galleries ) ) {
		$galleries = array( $galleries ); // Fixes issue where if only one gallery is passed do_action reduces it to a single object and removes the array ref. We want this to always be an array dammit!
	}

	if ( empty( $galleries ) ) {
		$galleries = sunshine_get_galleries( array( 'post_parent' => 0 ), 'view' );
	}

	if ( empty( $galleries ) ) {
		sunshine_get_template( 'galleries/empty' );
		return;
	}

	sunshine_get_template( 'galleries/galleries', array( 'galleries' => $galleries ) );
}

/*
 SINGLE GALLERY */
// add_action( 'sunshine_single_gallery', 'sunshine_show_page_content', 9 );
function sunshine_show_page_content() {

	$content = get_the_content();
	if ( $content && ! SPC()->frontend->is_store() ) {
		echo '<div id="sunshine--content">' . do_shortcode( wp_kses_post( $content ) ) . '</div>';
	}

}

add_action( 'sunshine_single_gallery', 'sunshine_single_gallery_display' );
function sunshine_single_gallery_display( $gallery = '' ) {

	if ( empty( $gallery ) && ! empty( SPC()->frontend->current_gallery ) ) {
		$gallery = SPC()->frontend->current_gallery;
	} elseif ( ! empty( $gallery ) ) {
		if ( ! is_object( $gallery ) ) {
			$gallery = sunshine_get_gallery( $gallery );
		}
	} else {
		return false;
	}

	if ( empty( $gallery ) ) {
		return false;
	}

	if ( $gallery->get_status() == 'password' && current_user_can( 'sunshine_manage_options' ) ) {
		sunshine_get_template( 'gallery/special-access' );
	}

	if ( $gallery->get_expiration_date() ) {
		sunshine_get_template( 'gallery/expires', array( 'gallery' => $gallery ) );
	}

	if ( ! current_user_can( 'sunshine_manage_options' ) ) {

		if ( $gallery->is_expired() ) {
			sunshine_get_template( 'gallery/expired', array( 'gallery' => $gallery ) );
			return;
		}

		if ( $gallery->get_access_type() == 'account' && ! is_user_logged_in() ) {
			sunshine_get_template(
				'account/login-signup',
				array(
					'message'  => apply_filters( 'sunshine_gallery_require_login_signup_message', __( 'The gallery you tried to access is private and requires you to login first', 'sunshine-photo-cart' ), $gallery ),
					'redirect' => $gallery->get_permalink(),
				)
			);
			return;
		}

		if ( ! $gallery->can_view() ) {
			sunshine_get_template(
				'account/login',
				array(
					'message'  => apply_filters( 'sunshine_gallery_require_login_message', __( 'You must have proper permissions to view this gallery', 'sunshine-photo-cart' ), $gallery ),
					'redirect' => $gallery->get_permalink(),
				)
			);
			return;
		}

		$password_required = $gallery->password_required();
		$email_required    = $gallery->email_required();
		if ( $password_required || $email_required ) {
			sunshine_get_template(
				'gallery/access',
				array(
					'gallery'  => $gallery,
					'password' => $password_required,
					'email'    => $email_required,
				),
			);
			return;
		} else {
			$needs_password   = false;
			$password_content = '';
			$ancestors        = get_ancestors( $gallery->get_id(), 'sunshine-gallery', 'post_type' );
			if ( $ancestors ) {
				foreach ( $ancestors as $ancestor_id ) {
					$ancestor_gallery = sunshine_get_gallery( $ancestor_id );
					if ( $ancestor_gallery->password_required() ) {
						sunshine_get_template(
							'gallery/access',
							array(
								'gallery'     => $ancestor_gallery,
								'password'    => true,
								'email'       => $ancestor_gallery->email_required(),
								'redirect_to' => $gallery->get_permalink(),
							)
						);
						return;
					}
				}
			}
		}
	}

	if ( SPC()->frontend->is_store() ) {
		if ( $gallery->can_purchase() ) {
			sunshine_get_template( 'store/store', array( 'gallery' => $gallery ) );
			return;
		}
		sunshine_get_template( 'store/closed', array( 'gallery' => $gallery ) );
		return;
	}

	$content = $gallery->get_content();
	remove_filter( 'the_content', array( SPC()->frontend, 'the_content' ) );
	$content = apply_filters( 'the_content', $content );
	if ( ! empty( $content ) ) {
		echo '<div id="sunshine--content">' . $content . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - already filtered by "the_content" which is exactly how the_content() works in core.
	}
	add_filter( 'the_content', array( SPC()->frontend, 'the_content' ) );

	$child_galleries = $gallery->get_child_galleries();
	$images          = $gallery->get_images();

	if ( $child_galleries ) {
		sunshine_get_template( 'galleries/galleries', array( 'galleries' => $child_galleries ) );
	}

	$images = $gallery->get_images();
	if ( ! empty( $images ) ) {
		sunshine_get_template(
			'gallery/images',
			array(
				'gallery' => $gallery,
				'images'  => $images,
			)
		);
	} elseif ( empty( $child_galleries ) ) {
		sunshine_get_template( 'gallery/no-images', array( 'gallery' => $gallery ) );
	}

}

/* SINGLE IMAGE */
add_action( 'sunshine_single_image', 'sunshine_single_image_display' );
function sunshine_single_image_display() {

	$gallery = SPC()->frontend->current_gallery;
	$image   = SPC()->frontend->current_image;

	if ( ! current_user_can( 'sunshine_manage_options' ) ) {
		if ( $gallery->is_expired() ) {
			sunshine_get_template( 'gallery/expired', array( 'gallery' => $gallery ) );
			return;
		}
		$password_required = $gallery->password_required();
		$email_required    = $gallery->email_required();
		if ( $password_required || $email_required ) {
			sunshine_get_template(
				'gallery/access',
				array(
					'gallery'     => $gallery,
					'password'    => $password_required,
					'email'       => $email_required,
					'redirect_to' => $image->get_permalink(),
				)
			);
			return;
		} else {
			$needs_password   = false;
			$password_content = '';
			$ancestors        = get_ancestors( $gallery->get_id(), 'sunshine-gallery', 'post_type' );
			if ( $ancestors ) {
				foreach ( $ancestors as $ancestor_id ) {
					$ancestor_gallery = sunshine_get_gallery( $ancestor_id );
					if ( $ancestor_gallery->password_required() ) {
						sunshine_get_template(
							'gallery/access',
							array(
								'gallery'  => $gallery,
								'password' => true,
								'email'    => true,
							)
						);
						return;
					}
				}
			}
		}
	}

	sunshine_get_template( 'image/image', array( 'image' => SPC()->frontend->current_image ) );

}

add_action( 'sunshine_single_image', 'sunshine_image_nav', 20 );

/* CART */
add_action( 'sunshine_cart', 'sunshine_display_cart' );
function sunshine_display_cart() {
	if ( SPC()->cart->is_empty() ) {
		sunshine_get_template( 'cart/empty' );
	} else {
		sunshine_get_template( 'cart/cart' );
	}
}

/* CHECKOUT */
add_action( 'sunshine_checkout', 'sunshine_display_checkout' );
function sunshine_display_checkout() {
	if ( SPC()->cart->is_empty() ) {
		sunshine_get_template( 'cart/empty' );
	} else {
		sunshine_get_template( 'checkout/checkout' );
	}
}

/* ORDER */
add_action( 'sunshine_order', 'sunshine_display_order_title', 1 );
add_action( 'sunshine_order_received', 'sunshine_display_order_title', 1 );
function sunshine_display_order_title() {
	if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
		// return;
	}
	if ( SPC()->frontend->current_order ) {
		sunshine_get_template( 'order/title', array( 'order' => SPC()->frontend->current_order ) );
	}
}

add_action( 'sunshine_order', 'sunshine_display_order_status', 10 );
add_action( 'sunshine_order_received', 'sunshine_display_order_status', 10 );
function sunshine_display_order_status() {
	if ( SPC()->frontend->current_order ) {
		if ( SPC()->frontend->current_order->get_mode() == 'test' ) {
			sunshine_get_template( 'order/test', array( 'order' => SPC()->frontend->current_order ) );
		}
		sunshine_get_template( 'order/status', array( 'order' => SPC()->frontend->current_order ) );
	}
}

add_action( 'sunshine_order', 'sunshine_display_order_details', 20 );
add_action( 'sunshine_order_received', 'sunshine_display_order_details', 20 );
function sunshine_display_order_details() {
	if ( SPC()->frontend->current_order ) {
		sunshine_get_template( 'order/details', array( 'order' => SPC()->frontend->current_order ) );
	}
}

add_action( 'sunshine_order', 'sunshine_display_order_items', 30 );
add_action( 'sunshine_order_received', 'sunshine_display_order_items', 30 );
function sunshine_display_order_items() {
	if ( SPC()->frontend->current_order ) {
		sunshine_get_template( 'order/items', array( 'order' => SPC()->frontend->current_order ) );
	}
}

add_action( 'sunshine_order', 'sunshine_display_order_totals', 40 );
add_action( 'sunshine_order_received', 'sunshine_display_order_totals', 40 );
function sunshine_display_order_totals() {
	if ( SPC()->frontend->current_order ) {
		sunshine_get_template( 'order/totals', array( 'order' => SPC()->frontend->current_order ) );
	}
}


/* FAVORITES */
add_action( 'sunshine_favorites', 'sunshine_display_favorites' );
function sunshine_display_favorites() {

	// If we have a custom key, let's pull from that.
	if ( isset( $_GET['favorites_key'] ) ) {
		$images = sunshine_get_favorites_by_key( sanitize_text_field( $_GET['favorites_key'] ), false );
	} else {
		// Works for both logged-in users and guests.
		$images = SPC()->customer->get_favorites();
	}

	if ( empty( $images ) ) {
		sunshine_get_template( 'favorites/empty' );
	} else {
		sunshine_get_template( 'favorites/favorites', array( 'images' => $images ) );
	}

}

/* ACCOUNT */
add_action( 'sunshine_account', 'sunshine_display_account' );
function sunshine_display_account() {
	global $wp_query;

	$this_endpoint = '';
	$items         = sunshine_get_account_endpoints();
	foreach ( $items as $key => $endpoint ) {
		if ( isset( $wp_query->query_vars[ $endpoint ] ) ) {
			$this_endpoint = $endpoint;
			break;
		}
	}
	if ( empty( $this_endpoint ) ) {
		$this_endpoint = 'dashboard';
	}
	if ( is_user_logged_in() ) {
		sunshine_get_template( 'account/account', array( 'endpoint' => $this_endpoint ) );
	} elseif ( SPC()->get_option( 'account_login_endpoint' ) === $this_endpoint ) {
		sunshine_get_template( 'account/login' );
	} elseif ( SPC()->get_option( 'account_reset_password_endpoint' ) === $this_endpoint && isset( $_GET['key'] ) && isset( $_GET['login'] ) ) {
		$key     = sanitize_text_field( $_GET['key'] );
		$login   = sanitize_text_field( $_GET['login'] );
		$allowed = check_password_reset_key( $key, $login );
		if ( ! is_wp_error( $allowed ) ) {
			sunshine_get_template(
				'account/reset-password',
				array(
					'key'   => $key,
					'login' => $login,
				)
			);
		} else {
			echo esc_html( $allowed->get_error_message() );
		}
	} else {
		sunshine_get_template( 'account/login-signup' );
	}

}

add_action( 'sunshine_account_menu', 'sunshine_display_account_menu' );
function sunshine_display_account_menu() {
	if ( is_user_logged_in() ) {
		sunshine_get_template( 'account/menu' );
	}
}

add_action( 'sunshine_account_content', 'sunshine_display_account_content' );
function sunshine_display_account_content() {
	global $wp_query;
	$items = sunshine_get_account_endpoints();
	foreach ( $items as $key => $endpoint ) {
		if ( isset( $wp_query->query_vars[ $endpoint ] ) ) {
			do_action( 'sunshine_account_' . $key, $endpoint );
			return;
		}
	}
	// Default to dashboard
	sunshine_get_template( 'account/dashboard' );
}

add_action( 'sunshine_account_orders', 'sunshine_display_account_orders' );
function sunshine_display_account_orders() {
	sunshine_get_template( 'account/orders', array( 'orders' => SPC()->customer->get_orders() ) );
}

add_action( 'sunshine_account_addresses', 'sunshine_display_account_addresses' );
function sunshine_display_account_addresses() {

	$default_country = SPC()->customer->get_shipping_country();
	$fields          = SPC()->countries->get_address_fields( $default_country, 'shipping_' );
	if ( ! empty( $fields ) ) {

		foreach ( $fields as $key => $field ) {
			$fields[ $key ]['default'] = SPC()->customer->get_meta( $field['id'] );
		}

		$fields   = array_merge(
			array(
				array(
					'id'   => 'shipping_address',
					'name' => __( 'Shipping Address', 'sunshine-photo-cart' ),
					'type' => 'legend',
				),
			),
			$fields,
		);
		$fields[] = array(
			'id'   => 'save_addresses',
			'type' => 'submit',
			'name' => __( 'Save Changes', 'sunshine-photo-cart' ),
		);

	}

	sunshine_get_template( 'account/addresses', array( 'fields' => $fields ) );
}

add_action( 'sunshine_account_galleries', 'sunshine_display_account_galleries' );
function sunshine_display_account_galleries() {
	sunshine_get_template( 'account/galleries', array( 'galleries' => SPC()->customer->get_galleries() ) );
}

add_action( 'sunshine_account_profile', 'sunshine_display_account_profile' );
function sunshine_display_account_profile() {
	$fields   = sunshine_get_profile_fields();
	$fields[] = array(
		'id'   => 'save_profile',
		'type' => 'submit',
		'name' => __( 'Save Changes', 'sunshine-photo-cart' ),
	);
	sunshine_get_template( 'account/profile', array( 'fields' => $fields ) );
}

add_action( 'sunshine_account_login', 'sunshine_display_account_login' );
function sunshine_display_account_login() {
	if ( ! is_user_logged_in() ) {
		sunshine_get_template( 'account/login' );
		return;
	}
	esc_html_e( 'You are already logged in', 'sunshine-photo-cart' );
}

add_action( 'sunshine_account_reset-password', 'sunshine_display_reset_password' );
function sunshine_display_reset_password() {
	// Check the reset key
	if ( ( isset( $_GET['key'] ) && isset( $_GET['login'] ) ) || ( ! empty( SPC()->session->get( 'reset_password_key' ) ) && ! empty( SPC()->session->get( 'reset_password_login' ) ) ) ) {
		$key       = ( isset( $_GET['key'] ) ) ? sanitize_text_field( $_GET['key'] ) : SPC()->session->get( 'reset_password_key' );
		$login     = ( isset( $_GET['login'] ) ) ? sanitize_text_field( $_GET['login'] ) : SPC()->session->get( 'reset_password_login' );
		$valid_key = check_password_reset_key( $key, $login );
		if ( ! is_wp_error( $valid_key ) ) {
			SPC()->session->set( 'reset_password_key', $key );
			SPC()->session->set( 'reset_password_login', $login );
			sunshine_get_template(
				'account/reset-password',
				array(
					'key'   => $key,
					'login' => $login,
				)
			);
			return;
		}
	}
	esc_html_e( 'Invalid reset password URL', 'sunshine-photo-cart' );
}

add_action( 'sunshine_search_results', 'sunshine_display_search_results' );
function sunshine_display_search_results() {

	if ( empty( $_GET['sunshine_search'] ) ) {
		esc_html_e( 'Sorry, invalid search', 'sunshine-photo-cart' );
		return;
	}

	sunshine_get_template( 'search/results', array( 'images' => SPC()->frontend->search_results ) );

}

add_action( 'sunshine_invoice_after_data', 'sunshine_invoice_custom_content' );
function sunshine_invoice_custom_content( $order ) {
	$custom_content = SPC()->get_option( 'invoice' );
	if ( $custom_content ) {
		echo '<div id="invoice-custom-content">' . wp_kses_post( wpautop( $custom_content ) ) . '</div>';
	}
}
