<?php

// NOT USED
// If user is logged in, then set cart to the customer meta
function sunshine_maybe_set_customer_cart( $contents ) {
	if ( is_user_logged_in() ) {
		$customer = new SPC_Customer( get_current_user_id() );
		if ( $customer ) {
			$customer->set_cart( $contents );
		}
	}
}

/**
 * Listening to update cart request, updating cart
 *
 * @since 1.0
 * @return void
 */
add_action( 'init', 'sunshine_update_cart', 99 );
function sunshine_update_cart() {
	if ( isset( $_POST['sunshine_update_cart'] ) && $_POST['sunshine_update_cart'] == 1 && wp_verify_nonce( $_POST['nonce'], 'sunshine_update_cart' ) ) {
		$cart_items = SPC()->cart->get_cart();
		foreach ( $cart_items as $cart_key => &$cart_item ) {
			foreach ( $_POST['item'] as $key => $item ) {
				if ( $item['hash'] == $cart_item['hash'] ) {
					if ( ! isset( $item['qty'] ) || $item['qty'] <= 0 ) {
						SPC()->cart->remove_item( $cart_key );
					} elseif ( $item['qty'] != $cart_item['qty'] ) {
						SPC()->cart->update_item_quantity( $cart_key, intval( $item['qty'] ) );
					}
					unset( $_POST['item'][ $key ] );
					break;
				}
			}
		}

		SPC()->notices->add( __( 'Cart updated', 'sunshine-photo-cart' ) );
		do_action( 'sunshine_cart_update' );

		wp_safe_redirect( sunshine_get_page_permalink( 'cart' ) );
		exit;
	}
}

/**
 * Listening for delete cart item request, deleting item
 *
 * @since 1.0
 * @return void
 */
add_action( 'init', 'sunshine_delete_cart_item', 10 );
function sunshine_delete_cart_item() {
	if ( isset( $_GET['delete_cart_item'] ) && wp_verify_nonce( $_GET['nonce'], 'sunshine_delete_cart_item' ) ) {
		$items = SPC()->cart->get_cart();
		foreach ( $items as $key => $item ) {
			if ( $_GET['delete_cart_item'] == $item['hash'] ) {
				SPC()->cart->remove_item( $key );
				SPC()->cart->update_cart();
				SPC()->notices->add( __( 'Item removed from cart', 'sunshine-photo-cart' ) );
				$redirect = add_query_arg( 'deleted', $item['hash'], sunshine_get_page_permalink( 'cart' ) );
				wp_safe_redirect( $redirect );
				exit;
			}
		}
	}
}

add_action( 'sunshine_after_signup', 'sunshine_update_cart_after_signup' );
function sunshine_update_cart_after_signup( $customer_id ) {
	$cart = SPC()->session->get( 'cart' );
	if ( $cart ) {
		$customer = sunshine_get_customer( $customer_id );
		$customer->set_cart( $cart );
	}
}

/*
add_action( 'wp', 'sunshine_check_max_quantities_in_cart' );
function sunshine_check_max_quantities_in_cart() {

	if ( is_sunshine_page( 'cart' ) || is_sunshine_page( 'checkout' ) ) {

		if ( ! SPC()->cart->is_empty() ) {
			foreach ( SPC()->cart->get_cart_items() as $cart_item ) {
				$max_qty[ $cart_item->get_product_id() ] = $cart_item->get_max_qty();
			}
			foreach ( SPC()->cart->get_cart_items() as $cart_item ) {
				// Go through cart items and get quantities.
				// If they go over the max quantity for the product, then either reduce qty or remove item.
			}
		}

	}

}
*/
