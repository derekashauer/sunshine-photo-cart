<?php
class SPC_Customer extends WP_User {

	private $favorite_ids = array();
	private $favorites    = array();
	private $favorite_key;
	private $credits = 0;

	public function __construct( $user_id ) {
		parent::__construct( $user_id );
		$all_meta = get_user_meta( $user_id );
		if ( ! empty( $all_meta['first_name'][0] ) ) {
			$this->data->first_name = $all_meta['first_name'][0];
		}
		if ( ! empty( $all_meta['last_name'][0] ) ) {
			$this->data->last_name = $all_meta['last_name'][0];
		}
		if ( ! empty( $all_meta['sunshine_favorites'][0] ) ) {
			$this->favorite_ids = maybe_unserialize( $all_meta['sunshine_favorites'][0] );
		}
		if ( ! empty( $all_meta['sunshine_favorite_key'][0] ) ) {
			$this->favorite_key = $all_meta['sunshine_favorite_key'][0];
		}
		if ( ! empty( $all_meta['sunshine_credits'][0] ) ) {
			$this->credits = $all_meta['sunshine_credits'][0];
		}
	}

	public function get_id() {
		return $this->ID;
	}

	/**
	 * Check if this is a guest customer (not logged in).
	 *
	 * @return bool True if guest, false if logged in.
	 */
	public function is_guest() {
		return $this->ID === 0;
	}

	public function add_meta( $key, $value ) {
		if ( $this->ID > 0 ) {
			if ( is_serialized( $value ) ) {
				return false;
			}
			add_user_meta( $this->ID, SPC()->prefix . $key, $value );
		}
	}


	public function update_meta( $key, $value ) {
		if ( $this->ID > 0 ) {

			if ( is_serialized( $value ) ) {
				return false;
			}

			$prefix = SPC()->prefix;
			// Disable prefix to use core first/last name.
			if ( $key == 'first_name' || $key == 'last_name' ) {
				$prefix = '';
			}
			update_user_meta( $this->ID, $prefix . $key, $value );
		}
	}

	public function delete_meta( $key ) {
		if ( $this->ID > 0 ) {
			delete_user_meta( $this->ID, SPC()->prefix . $key );
		}
	}

	public function get_meta( $key, $single = true ) {
		if ( $this->ID > 0 ) {
			return get_user_meta( $this->ID, SPC()->prefix . $key, $single );
		}
		return false;
	}

	public function get_name( $fallback = '' ) {
		if ( $this->get_first_name() ) {
			return $this->get_first_name() . ' ' . $this->get_last_name();
		}
		if ( ! empty( $fallback ) ) {
			return $fallback;
		}
		if ( empty( $fallback ) && $this->display_name ) {
			return $this->display_name;
		}
	}
	/**
	 * Get the customer's first name.
	 *
	 * @return string The customer's first name or an empty string if not set.
	 */
	public function get_first_name() {
		return ( ! empty( $this->data->first_name ) ) ? $this->data->first_name : '';
	}
	/**
	 * Get the customer's last name.
	 *
	 * @return string The customer's last name or an empty string if not set.
	 */
	public function get_last_name() {
		return ( ! empty( $this->data->last_name ) ) ? $this->data->last_name : '';
	}

	/**
	 * Get the customer's email address.
	 *
	 * @return string The customer's email address or an empty string if not set.
	 */
	public function get_email() {
		return ( ! empty( $this->data->user_email ) ) ? $this->data->user_email : '';
	}

	/**
	 * Set the customer's cart contents.
	 *
	 * @param mixed $contents The cart contents to set.
	 */
	public function set_cart( $contents ) {
		$this->update_meta( 'cart', $contents );
	}

	/**
	 * Get the customer's cart contents.
	 *
	 * @return mixed The customer's cart contents.
	 */
	public function get_cart() {
		return $this->get_meta( 'cart' );
	}

	/**
	 * Get the customer's cart items.
	 *
	 * @return array|false An array of SPC_Cart_Item objects or false if the cart is empty.
	 */
	public function get_cart_items() {
		$cart = $this->get_cart();
		if ( ! empty( $cart ) ) {
			$final_contents = array();
			foreach ( $cart as $key => $item ) {
				 $cart_item = new SPC_Cart_Item( $item );
				if ( ! empty( $cart_item->get_product() ) ) {
					$final_contents[] = $cart_item;
				}
			}
			return $final_contents;
		}
		return false;
	}

	/**
	 * Set the customer's credits.
	 *
	 * @param float $credits The amount of credits to set.
	 */
	public function set_credits( $credits ) {
		$this->update_meta( 'credits', intval( $credits ) );
		SPC()->log( 'Customer ' . $this->get_id() . ' set credits to ' . $credits );
	}

	/**
	 * Get the customer's credits.
	 *
	 * @return float The customer's current credits.
	 */
	public function get_credits() {
		return intval( $this->credits );
	}

	/**
	 * Decrease the customer's credits.
	 *
	 * @param float $amount The amount to decrease.
	 */
	public function decrease_credits( $amount ) {
		$credits  = $this->get_credits();
		$credits -= intval( $amount );
		$this->set_credits( max( $credits, 0 ) );
		SPC()->log( 'Customer ' . $this->get_id() . ' decreased credits by ' . $amount . ' to ' . $credits );
	}

	/**
	 * Increase the customer's credits.
	 *
	 * @param float $amount The amount to increase.
	 */
	public function increase_credits( $amount ) {
		$amount   = intval( $amount );
		$credits  = $this->get_credits();
		$credits += $amount;
		$this->set_credits( max( $credits, 0 ) );
		SPC()->log( 'Customer ' . $this->get_id() . ' increased credits by ' . $amount . ' to ' . $credits );
	}

	/**
	 * Get the customer's favorite image IDs.
	 *
	 * @return array An array of favorite image IDs.
	 */
	public function get_favorite_ids() {
		if ( $this->is_guest() ) {
			return sunshine_get_session_favorite_ids();
		}
		return $this->favorite_ids;
	}

	/**
	 * Get the customer's favorite images.
	 *
	 * @return array|false An array of favorite image objects or false if no favorites.
	 */
	public function get_favorites() {
		// For guests, don't use cache since session can change.
		if ( $this->is_guest() ) {
			$favorite_ids = $this->get_favorite_ids();
			if ( empty( $favorite_ids ) ) {
				return false;
			}
			$favorites = array();
			foreach ( $favorite_ids as $favorite_id ) {
				$image = sunshine_get_image( $favorite_id );
				if ( $image && $image->exists() && $image->can_view() ) {
					$favorites[] = $image;
				}
			}
			return ! empty( $favorites ) ? $favorites : false;
		}

		if ( ! empty( $this->favorites ) ) {
			return $this->favorites;
		}
		if ( ! empty( $this->favorite_ids ) ) {
			foreach ( $this->favorite_ids as $favorite_id ) {
				$image = sunshine_get_image( $favorite_id );
				if ( $image->exists() && $image->get_gallery() ) {
					$this->favorites[] = $image;
				} else {
					$this->delete_favorite( $favorite_id );
				}
			}
			return $this->favorites;
		}
		return false;
	}

	/**
	 * Get the count of customer's favorites.
	 *
	 * @return int The number of favorite images.
	 */
	public function get_favorites_count() {
		return count( $this->get_favorite_ids() );
	}

	/**
	 * Add an image to the customer's favorites.
	 *
	 * @param int $image_id The ID of the image to add to favorites.
	 */
	public function add_favorite( $image_id ) {
		$image_id = intval( $image_id );

		if ( $this->is_guest() ) {
			sunshine_add_session_favorite( $image_id );
			return;
		}

		if ( ! in_array( $image_id, $this->favorite_ids, true ) ) {
			$this->favorite_ids[] = $image_id;
			$this->update_meta( 'favorites', $this->favorite_ids ); // TODO: Use the class function here and throughout for get/set/delete user meta
			$this->increase_favorites_count();
			do_action( 'sunshine_add_favorite', $image_id, $this );
			SPC()->log( 'Customer ' . $this->get_id() . ' added favorite ' . $image_id );
		}

	}

	/**
	 * Delete an image from the customer's favorites.
	 *
	 * @param int $image_id The ID of the image to remove from favorites.
	 */
	public function delete_favorite( $image_id ) {
		if ( $this->is_guest() ) {
			sunshine_delete_session_favorite( $image_id );
			return;
		}

		$key = array_search( $image_id, $this->favorite_ids, true );
		if ( $key !== false ) {
			unset( $this->favorite_ids[ $key ] );
			$this->update_meta( 'favorites', $this->favorite_ids ); // TODO: Use the class function here and throughout for get/set/delete user meta
			$this->decrease_favorites_count();
			do_action( 'sunshine_delete_favorite', $image_id, $this );
			SPC()->log( 'Customer ' . $this->get_id() . ' deleted favorite ' . $image_id );
		}

	}

	/**
	 * Check if the customer has any favorites.
	 *
	 * @return bool True if the customer has favorites, false otherwise.
	 */
	public function has_favorites() {
		$favorite_ids = $this->get_favorite_ids();
		return ! empty( $favorite_ids );
	}

	/**
	 * Check if a specific image is in the customer's favorites.
	 *
	 * @param int $image_id The ID of the image to check.
	 * @return bool True if the image is a favorite, false otherwise.
	 */
	public function has_favorite( $image_id ) {
		$favorite_ids = $this->get_favorite_ids();
		return ! empty( $favorite_ids ) && in_array( intval( $image_id ), $favorite_ids, true );
	}

	/**
	 * Get the count of customer's favorites.
	 *
	 * @return int The number of favorite images.
	 */
	public function get_favorite_count() {
		return count( $this->get_favorite_ids() );
	}

	/**
	 * Clear all of the customer's favorites.
	 */
	public function clear_favorites() {
		if ( $this->is_guest() ) {
			$favorite_ids = $this->get_favorite_ids();
			foreach ( $favorite_ids as $image_id ) {
				do_action( 'sunshine_delete_favorite', $image_id );
			}
			sunshine_clear_session_favorites();
			return;
		}

		$favorite_ids = $this->get_favorite_ids();
		if ( ! empty( $favorite_ids ) ) {
			foreach ( $favorite_ids as $image_id ) {
				do_action( 'sunshine_delete_favorite', $image_id, $this );
			}
			$this->update_meta( 'favorites', '' );
			SPC()->log( 'Customer ' . $this->get_id() . ' cleared favorites' );
		}
	}

	/**
	 * Get the customer's favorite key.
	 *
	 * @return string The customer's favorite key.
	 */
	public function get_favorite_key() {
		if ( empty( $this->favorite_key ) ) {
			$this->favorite_key = wp_generate_password( 20, false );
			$this->update_meta( 'favorite_key', $this->favorite_key );
		}
		return $this->favorite_key;
	}

	/**
	 * Get the customer's shipping address line 1.
	 *
	 * @return string The shipping address line 1.
	 */
	public function get_shipping_address1() {
		$key = SPC()->prefix . 'shipping_address1';
		return $this->{$key};
	}

	/**
	 * Get the customer's shipping address line 2.
	 *
	 * @return string The shipping address line 2.
	 */
	public function get_shipping_address2() {
		$key = SPC()->prefix . 'shipping_address2';
		return $this->{$key};
	}

	/**
	 * Get the customer's shipping city.
	 *
	 * @return string The shipping city.
	 */
	public function get_shipping_city() {
		$key = SPC()->prefix . 'shipping_city';
		return $this->{$key};
	}

	/**
	 * Get the customer's shipping state.
	 *
	 * @return string The shipping state.
	 */
	public function get_shipping_state() {
		$key = SPC()->prefix . 'shipping_state';
		return $this->{$key};
	}

	/**
	 * Get the customer's shipping postcode.
	 *
	 * @return string The shipping postcode.
	 */
	public function get_shipping_postcode() {
		$key = SPC()->prefix . 'shipping_postcode';
		return $this->{$key};
	}

	/**
	 * Get the customer's shipping country.
	 *
	 * @return string The shipping country.
	 */
	public function get_shipping_country() {
		$key = SPC()->prefix . 'shipping_country';
		return $this->{$key};
	}

	/**
	 * Get the customer's formatted shipping address.
	 *
	 * @return string The formatted shipping address.
	 */
	public function get_shipping_address_formatted() {
		$args = array(
			'address1' => $this->get_shipping_address1(),
			'address2' => $this->get_shipping_address2(),
			'city'     => $this->get_shipping_city(),
			'state'    => $this->get_shipping_state(),
			'postcode' => $this->get_shipping_postcode(),
			'country'  => $this->get_shipping_country(),
		);
		return SPC()->countries->get_formatted_address( $args );
	}

	/**
	 * Check if the customer has a shipping address.
	 *
	 * @return bool True if the customer has a shipping address, false otherwise.
	 */
	public function has_shipping_address() {
		if ( $this->get_shipping_address1() ) {
			return true;
		}
		return false;
	}

	/**
	 * Get the customer's billing address line 1.
	 *
	 * @return string The billing address line 1.
	 */
	public function get_billing_address1() {
		$key = SPC()->prefix . 'billing_address1';
		return $this->{$key};
	}

	/**
	 * Get the customer's billing address line 2.
	 *
	 * @return string The billing address line 2.
	 */
	public function get_billing_address2() {
		$key = SPC()->prefix . 'billing_address2';
		return $this->{$key};
	}

	/**
	 * Get the customer's billing city.
	 *
	 * @return string The billing city.
	 */
	public function get_billing_city() {
		$key = SPC()->prefix . 'billing_city';
		return $this->{$key};
	}

	/**
	 * Get the customer's billing state.
	 *
	 * @return string The billing state.
	 */
	public function get_billing_state() {
		$key = SPC()->prefix . 'billing_state';
		return $this->{$key};
	}

	/**
	 * Get the customer's billing postcode.
	 *
	 * @return string The billing postcode.
	 */
	public function get_billing_postcode() {
		$key = SPC()->prefix . 'billing_postcode';
		return $this->{$key};
	}

	/**
	 * Get the customer's billing country.
	 *
	 * @return string The billing country.
	 */
	public function get_billing_country() {
		$key = SPC()->prefix . 'billing_country';
		return $this->{$key};
	}

	/**
	 * Get the customer's formatted billing address.
	 *
	 * @return string The formatted billing address.
	 */
	public function get_billing_address_formatted() {
		$args = array(
			'address1' => $this->get_billing_address1(),
			'address2' => $this->get_billing_address2(),
			'city'     => $this->get_billing_city(),
			'state'    => $this->get_billing_state(),
			'postcode' => $this->get_billing_postcode(),
			'country'  => $this->get_billing_country(),
		);
		return SPC()->countries->get_formatted_address( $args );
	}

	/**
	 * Check if the customer has a billing address.
	 *
	 * @return bool True if the customer has a billing address, false otherwise.
	 */
	public function has_billing_address() {
		if ( $this->get_billing_address1() ) {
			return true;
		}
		return false;
	}

	/**
	 * Get the customer's phone number.
	 *
	 * @return string The customer's phone number.
	 */
	public function get_phone() {
		return $this->sunshine_phone;
	}

	/**
	 * Get the customer's VAT number.
	 *
	 * @return string The customer's VAT number.
	 */
	public function get_vat() {
		return $this->vat;
	}

	public function get_galleries() {
		$args      = array(
			'posts_per_page' => -1,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => 'private_users',
					'value'   => '"' . $this->ID . '"',
					'compare' => 'LIKE',
				),
				array(
					'key'   => 'status',
					'value' => 'private',
				),
			),
		);
		$args      = apply_filters( 'sunshine_customer_get_galleries_args', $args );
		$galleries = sunshine_get_galleries( $args );
		return $galleries;
	}

	/* ORDERS */
	public function get_orders() {
		$orders = array();
		$args   = array(
			'post_type'      => 'sunshine-order',
			'posts_per_page' => -1,
			'author'         => $this->ID,
		);
		$query  = new WP_Query( $args );
		while ( $query->have_posts() ) :
			$query->the_post();
			$orders[] = new SPC_Order( $query->posts[ $query->current_post ] );
		endwhile;
		wp_reset_postdata();
		return $orders;
	}

	/*
	public function get_order_totals() {
		$orders = $this->get_orders();
		if ( !empty( $orders ) ) {
			$orders_total = 0;
			foreach ( $orders as $order ) {
				$orders_total += $order->get_total();
			}
			return $orders_total;
		}
		return 0;
	}
	*/

	public function increase_order_count( $amount = 1 ) {
		$order_count  = absint( $this->get_meta( 'order_count' ) );
		$order_count += $amount;
		$this->update_meta( 'order_count', $order_count );
		SPC()->log( 'Customer ' . $this->get_id() . ' increased order count by ' . $amount . ' to ' . $order_count );
	}

	public function decrease_order_count( $amount = 1 ) {
		$order_count  = absint( $this->get_meta( 'order_count' ) );
		$order_count -= $amount;
		$this->update_meta( 'order_count', $order_count );
		SPC()->log( 'Customer ' . $this->get_id() . ' decreased order count by ' . $amount . ' to ' . $order_count );
	}

	public function set_order_count( $amount ) {
		$this->update_meta( 'order_count', absint( $amount ) );
		SPC()->log( 'Customer ' . $this->get_id() . ' set order count to ' . $amount );
	}

	public function get_order_count() {
		$count = $this->get_meta( 'order_count' );
		return ( $count ) ? $count : 0;
	}

	public function increase_order_totals( $amount ) {
		$order_totals  = floatval( $this->get_meta( 'order_totals' ) );
		$order_totals += $amount;
		$this->update_meta( 'order_totals', $order_totals );
		SPC()->log( 'Customer ' . $this->get_id() . ' increased order totals by ' . $amount . ' to ' . $order_totals );
	}

	public function decrease_order_totals( $amount ) {
		$order_totals  = floatval( $this->get_meta( 'order_totals' ) );
		$order_totals -= $amount;
		$this->update_meta( 'order_totals', $order_totals );
		SPC()->log( 'Customer ' . $this->get_id() . ' decreased order totals by ' . $amount . ' to ' . $order_totals );
	}

	public function set_order_totals( $amount ) {
		$this->update_meta( 'order_totals', floatval( $amount ) );
		SPC()->log( 'Customer ' . $this->get_id() . ' set order totals to ' . $amount );
	}

	public function get_order_totals() {
		return $this->get_meta( 'order_totals' );
	}

	public function increase_favorites_count( $amount = 1 ) {
		$favorites_count  = absint( $this->get_meta( 'favorites_count' ) );
		$favorites_count += $amount;
		$this->update_meta( 'favorites_count', $favorites_count );
		SPC()->log( 'Customer ' . $this->get_id() . ' increased favorites count by ' . $amount . ' to ' . $favorites_count );
	}

	public function decrease_favorites_count( $amount = 1 ) {
		$favorites_count  = absint( $this->get_meta( 'favorites_count' ) );
		$favorites_count -= $amount;
		$this->update_meta( 'favorites_count', $favorites_count );
		SPC()->log( 'Customer ' . $this->get_id() . ' decreased favorites count by ' . $amount . ' to ' . $favorites_count );
	}

	public function set_favorites_count( $amount ) {
		$this->update_meta( 'favorites_count', absint( $amount ) );
		SPC()->log( 'Customer ' . $this->get_id() . ' set favorites count to ' . $amount );
	}

	public function recalculate_stats() {
		if ( ! $this->ID ) {
			return;
		}

		$this->set_favorites_count( $this->get_favorites_count() );

		$args  = array(
			'post_type'      => 'sunshine-order',
			'posts_per_page' => -1,
			'author'         => $this->ID,
		);
		$query = new WP_Query( $args );
		$this->set_order_count( $query->found_posts );

		$order_totals = 0;
		while ( $query->have_posts() ) :
			$query->the_post();
			$order         = new SPC_Order( get_the_ID() );
			$order_totals += $order->get_total_minus_refunds();
		endwhile;
		wp_reset_postdata();

		$this->set_order_totals( $order_totals );

		do_action( 'sunshine_customer_recalculate_stats', $this );
		SPC()->log( 'Customer ' . $this->get_id() . ' recalculated stats' );

	}

	public function get_actions() {
		$actions = $this->get_meta( 'action', false );
		if ( empty( $actions ) ) {
			$actions = array();
		}
		// Sort items by time from most recent to oldest.
		usort(
			$actions,
			function ( $action1, $action2 ) {
				return $action2['time'] <=> $action1['time'];
			}
		);
		return $actions;
	}

	public function get_last_login() {
		return $this->get_meta( 'last_login' );
	}

	/*
	public function add_action( $type, $object_id = '', $data = array() ) {

		do_action( 'sunshine_customer_add_action', $type, $object_id, $data );

		if ( ! $this->ID ) {
			return;
		}

		//$this->add_meta( 'action', $action );
		SPC()->log( 'Customer action added: ' . $type );

	}
	*/

}
