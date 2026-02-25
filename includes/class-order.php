<?php
class SPC_Order extends Sunshine_Data {

	protected $post_type = 'sunshine-order';
	protected $status;
	protected $cart = array();
	protected $meta = array(
		'cart'                 => array(),

		'currency'             => '',
		'display_price'        => '',

		'discounts'            => array(),
		'discount_names'       => array(),
		'discount'             => 0,
		'shipping'             => 0,
		'shipping_tax'         => 0.00,
		'subtotal'             => 0,
		'subtotal_tax'         => 0,
		'tax'                  => 0,
		'total'                => 0,

		'customer_id'          => 0,
		'order_key'            => '',

		'billing_first_name'   => '',
		'billing_last_name'    => '',
		'billing_address1'     => '',
		'billing_address2'     => '',
		'billing_city'         => '',
		'billing_state'        => '',
		'billing_postcode'     => '',
		'billing_country'      => '',
		'shipping_first_name'  => '',
		'shipping_last_name'   => '',
		'shipping_address1'    => '',
		'shipping_address2'    => '',
		'shipping_city'        => '',
		'shipping_state'       => '',
		'shipping_postcode'    => '',
		'shipping_country'     => '',

		'delivery_method'      => '',
		'delivery_method_name' => '',
		'shipping_method'      => '',
		'shipping_method_name' => '',
		'payment_method'       => '',
		'payment_method_name'  => '',
		'transaction_id'       => '',

		// 'created_via'          => '',
		'customer_notes'       => '',
		'notes'                => '',
		'fees'                 => array(),

		'mode'                 => 'live',
	);

	public function __construct( $object = '' ) {

		if ( is_numeric( $object ) && $object > 0 ) {
			$post = get_post( $object );
			if ( empty( $post ) || $post->post_type != $this->post_type ) {
				return false; }
			$this->id   = $post->ID;
			$this->data = $post;
		} elseif ( is_a( $object, 'WP_Post' ) ) {
			if ( $object->post_type != $this->post_type ) {
				return false; }
			$this->id   = $object->ID;
			$this->data = $object;
		}

		if ( $this->id > 0 ) {
			$this->set_meta_data();
		}

	}

	public function get_status() {
		if ( empty( $this->status ) ) {
			$current_status = get_the_terms( $this->get_id(), 'sunshine-order-status' );
			if ( ! empty( $current_status ) ) {
				$this->status = $current_status[0]->slug;
			}
		}
		return $this->status;
	}

	public function get_status_object() {
		if ( empty( $this->status ) ) {
			$this->get_status();
		}
		return new SPC_Order_Status( $this->status );
	}

	public function get_status_name() {
		$status = $this->get_status_object();
		if ( $status ) {
			return $status->get_name();
		}
		return false;
	}

	public function get_status_description() {
		$status = $this->get_status_object();
		if ( $status ) {
			return $status->get_description();
		}
		return false;
	}

	public function set_status( $new_status, $custom_log = '' ) {
		if ( sunshine_order_status_is_valid( $new_status ) ) {
			$result = wp_set_object_terms( $this->get_id(), $new_status, 'sunshine-order-status' );
			if ( is_wp_error( $result ) ) {
				return false;
			}
			$current_status = $this->get_status();
			$this->status   = $new_status;
			do_action( 'sunshine_order_status_change', $new_status, $this );
			do_action( 'sunshine_order_status_change_' . $new_status, $this );
			if ( ! empty( $current_status ) && $current_status != $new_status ) {
				do_action( 'sunshine_order_status_change_' . $current_status . '_to_' . $new_status, $this );
				/* translators: %1$s is the previous order status, %2$s is the new order status */
				$log = sprintf( __( 'Status change from %1$s to %2$s', 'sunshine-photo-cart' ), $current_status, $new_status );
			} else {
				do_action( 'sunshine_order_status_set_to_' . $new_status, $this );
				/* translators: %s is the order status name */
				$log = sprintf( __( 'Status set to %s', 'sunshine-photo-cart' ), $new_status );
			}
			$this->add_log( ( $custom_log ) ? $custom_log : $log );
			SPC()->log( $this->get_name() . ' status changed to ' . $new_status );
			return true;
		}
		return false;
	}

	public function has_status( $status ) {
		$current_status = $this->get_status();
		if ( $current_status && $current_status->slug == $status ) {
			return true;
		}
		return false;
	}

	public function is_paid() {
		return sunshine_order_is_paid( $this->get_status() );
	}

	public function get_order_key() {
		// TODO: Do we use this? I think original idea was for guest orders to view receipt page
		return $this->get_meta_value( 'order_key' );
	}

	public function add_log( $message, $user_id = 0 ) {

		if ( ! $this->get_id() ) {
			return;
		}

		if ( $user_id ) {
			$user                 = get_user_by( 'id', $user_id );
			$comment_author       = $user->display_name;
			$comment_author_email = $user->user_email;
		} elseif ( is_user_logged_in() && current_user_can( 'sunshine_manage_options' ) ) {
			$user                 = get_user_by( 'id', get_current_user_id() );
			$comment_author       = $user->display_name;
			$comment_author_email = $user->user_email;
		} else {
			$comment_author        = 'Sunshine Photo Cart';
			$comment_author_email  = 'sunshinephotocart@';
			$comment_author_email .= isset( $_SERVER['HTTP_HOST'] ) ? str_replace( 'www.', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : 'noreply.com'; // WPCS: input var ok.
			$comment_author_email  = sanitize_email( $comment_author_email );
		}
		$commentdata = apply_filters(
			'sunshine_order_note',
			array(
				'comment_post_ID'      => $this->get_id(),
				'comment_author'       => $comment_author,
				'comment_author_email' => $comment_author_email,
				'comment_author_url'   => '',
				'comment_content'      => $message,
				'comment_agent'        => 'SunshinePhotoCart',
				'comment_type'         => 'sunshine_order_log',
				'comment_parent'       => 0,
				'comment_approved'     => 1,
			),
			$this->get_id()
		);

		$comment_id = wp_insert_comment( $commentdata );

		do_action( 'sunshine_order_log_added', $comment_id, $this );

		SPC()->log( 'Order log item added to ' . $this->get_name() . ': ' . $message );

		return $comment_id;
	}

	public function get_log() {
		$log = get_comments(
			array(
				'post_id' => $this->get_id(),
				'type'    => 'sunshine_order_log',
			)
		);
		return $log;
	}

	public function get_name() {
		$order_number = $this->get_order_number();
		if ( empty( $order_number ) ) {
			$order_number = $this->get_id();
		}
		/* translators: %s is the order number */
		$this->name = sprintf( __( 'Order #%s', 'sunshine-photo-cart' ), $order_number );
		return apply_filters( 'sunshine_order_name', $this->name, $this );
	}

	public function get_order_number() {
		$order_number = $this->get_meta_value( 'order_number' );
		if ( empty( $order_number ) ) {
			$order_number = $this->get_id();
		}
		return $order_number;
	}

	function get_customer_id() {
		return $this->get_meta_value( 'customer_id' );
	}
	function get_user_id() {
		return $this->get_meta_value( 'customer_id' );
	}

	// Return a SPC_Customer for user tied to this order
	function get_customer() {
		$customer_id = $this->get_customer_id();
		if ( $customer_id ) {
			return new SPC_Customer( $customer_id );
		}
		return false;
	}

	// Return a WP_User for the user tied to this order
	public function get_user() {
		return $this->get_user_id() ? get_user_by( 'id', $this->get_user_id() ) : false;
	}

	public function get_customer_name() {
		return $this->get_customer_first_name() . ' ' . $this->get_customer_last_name();
	}

	public function get_customer_first_name() {
		// Check for customer
		$customer = $this->get_customer();
		if ( $customer ) {
			return $customer->get_first_name();
		}

		// Check meta field collected at start of order.
		if ( $this->get_meta_value( 'first_name' ) ) {
			return $this->get_meta_value( 'first_name' );
		}

		// Check for shipping name
		if ( $this->get_shipping_first_name() ) {
			return $this->get_shipping_first_name();
		}

		// Check for billing name
		if ( $this->get_billing_first_name() ) {
			return $this->get_billing_first_name();
		}

		return false;
	}

	public function get_customer_last_name() {
		// Check for customer
		$customer = $this->get_customer();
		if ( $customer ) {
			return $customer->get_last_name();
		}

		// Check meta field collected at start of order.
		if ( $this->get_meta_value( 'last_name' ) ) {
			return $this->get_meta_value( 'last_name' );
		}

		// Check for shipping name
		if ( $this->get_shipping_last_name() ) {
			return $this->get_shipping_last_name();
		}

		// Check for billing name
		if ( $this->get_billing_last_name() ) {
			return $this->get_billing_last_name();
		}

		return false;
	}

	public function get_received_permalink() {
		$url  = trailingslashit( sunshine_get_page_permalink( 'checkout' ) );
		$url .= trailingslashit( SPC()->get_option( 'endpoint_order_received', 'order-received' ) );
		$url .= $this->get_id();
		$url  = add_query_arg( 'order_key', $this->get_meta_value( 'order_key' ), $url );
		return $url;
	}

	public function get_permalink( $key = false ) {
		if ( $this->get_customer_id() ) {
			// If we have a customer, we can go to the account URL.
			$url  = trailingslashit( sunshine_get_page_permalink( 'account' ) );
			$url .= trailingslashit( SPC()->get_option( 'account_view_order_endpoint', 'order-details' ) );
			$url .= $this->get_id();
			if ( $key ) {
				$url = add_query_arg( 'order_key', $this->get_meta_value( 'order_key' ), $url );
			}
		} else {
			// Otherwise get the receipt/received permalink.
			$url = $this->get_received_permalink();
		}
		return $url;
	}

	public function get_admin_permalink() {
		return admin_url( 'post.php?post=' . esc_attr( $this->get_id() ) . '&action=edit' );
	}

	public function get_phone() {
		return $this->get_meta_value( 'phone' );
	}

	public function get_email() {
		return $this->get_meta_value( 'email' );
	}

	public function get_billing_first_name() {
		return $this->get_meta_value( 'billing_first_name' );
	}

	public function get_billing_last_name() {
		return $this->get_meta_value( 'billing_last_name' );
	}

	public function get_billing_address1() {
		return $this->get_meta_value( 'billing_address1' );
	}

	public function get_billing_address2() {
		return $this->get_meta_value( 'billing_address2' );
	}

	public function get_billing_city() {
		return $this->get_meta_value( 'billing_city' );
	}

	public function get_billing_state() {
		return $this->get_meta_value( 'billing_state' );
	}

	public function get_billing_postcode() {
		return $this->get_meta_value( 'billing_postcode' );
	}

	public function get_billing_country() {
		return $this->get_meta_value( 'billing_country' );
	}

	public function get_billing_address_formatted() {
		$args = array(
			'first_name' => $this->get_billing_first_name(),
			'last_name'  => $this->get_billing_last_name(),
			'address1'   => $this->get_billing_address1(),
			'address2'   => $this->get_billing_address2(),
			'city'       => $this->get_billing_city(),
			'state'      => $this->get_billing_state(),
			'postcode'   => $this->get_billing_postcode(),
			'country'    => $this->get_billing_country(),
		);
		return SPC()->countries->get_formatted_address( $args );
	}

	public function has_billing_address() {
		if ( $this->get_billing_address1() ) {
			return true;
		}
		return false;
	}


	public function get_shipping_first_name() {
		return $this->get_meta_value( 'shipping_first_name' );
	}

	public function get_shipping_last_name() {
		return $this->get_meta_value( 'shipping_last_name' );
	}

	public function get_shipping_address1() {
		return $this->get_meta_value( 'shipping_address1' );
	}

	public function get_shipping_address2() {
		return $this->get_meta_value( 'shipping_address2' );
	}

	public function get_shipping_city() {
		return $this->get_meta_value( 'shipping_city' );
	}

	public function get_shipping_state() {
		return $this->get_meta_value( 'shipping_state' );
	}

	public function get_shipping_postcode() {
		return $this->get_meta_value( 'shipping_postcode' );
	}

	public function get_shipping_country() {
		return $this->get_meta_value( 'shipping_country' );
	}

	public function get_shipping_address_formatted() {
		$args = array(
			'first_name' => $this->get_shipping_first_name(),
			'last_name'  => $this->get_shipping_last_name(),
			'address1'   => $this->get_shipping_address1(),
			'address2'   => $this->get_shipping_address2(),
			'city'       => $this->get_shipping_city(),
			'state'      => $this->get_shipping_state(),
			'postcode'   => $this->get_shipping_postcode(),
			'country'    => $this->get_shipping_country(),
		);
		return SPC()->countries->get_formatted_address( $args );
	}

	public function has_shipping_address() {
		$delivery_method = $this->get_delivery_method();
		$shipping_method = $this->get_shipping_method();
		if ( $delivery_method == 'shipping' && $this->get_shipping_address1() ) {
			return true;
		}
		return false;
	}

	public function price_formatted( $price, $tax = 0 ) {

		// $price_has_tax = $this->get_meta_value( 'price_has_tax' );
		$display_price = $this->get_meta_value( 'display_price' );
		$suffix        = SPC()->get_option( 'price_suffix' );

		if ( 'with_tax' === $display_price ) {
			$price += $tax;
		}

		$price_formatted = sunshine_price( $price );

		if ( $suffix ) {
			$price_formatted .= ' <small class="sunshine--price--suffix">' . $suffix . '</small>';
		}

		return $price_formatted;

	}

	public function get_subtotal() {
		return floatval( $this->get_meta_value( 'subtotal' ) );
	}

	public function get_subtotal_formatted() {
		return $this->price_formatted( $this->get_subtotal(), $this->get_subtotal_tax() );
	}
	public function set_subtotal( $value ) {
		$this->update_meta_value( 'subtotal', floatval( $value ) );
	}

	public function get_subtotal_tax() {
		return floatval( $this->meta['subtotal_tax'] );
	}
	public function set_subtotal_tax( $value ) {
		$this->update_meta_value( 'subtotal_tax', floatval( $value ) );
	}

	public function get_total() {
		$total = floatval( $this->get_meta_value( 'total' ) );
		if ( $this->get_refunds() ) {
			$total -= $this->get_refund_total();
		}
		return $total;
	}
	public function get_total_formatted() {
		$total           = $this->get_total();
		$total_formatted = $this->price_formatted( $total );
		return $total_formatted;
	}
	public function set_total( $value ) {
		$this->update_meta_value( 'total', floatval( $value ) );
	}

	public function get_total_minus_refunds() {
		$total   = floatval( $this->get_meta_value( 'total' ) );
		$refunds = $this->get_refunds();
		if ( ! empty( $refunds ) ) {
			foreach ( $refunds as $refund ) {
				$total -= floatval( $refund['amount'] );
			}
		}
		return $total;
	}

	public function get_refund_total() {
		$refund_total = 0;
		$refunds      = $this->get_refunds();
		if ( ! empty( $refunds ) ) {
			foreach ( $refunds as $refund ) {
				$refund_total += floatval( $refund['amount'] );
			}
		}
		return $refund_total;
	}

	public function get_refund_total_formatted() {
		return '-' . sunshine_price( $this->get_refund_total() );
	}

	public function get_credits() {
		return $this->get_meta_value( 'credits' );
	}
	public function get_credits_formatted() {
		return sunshine_price( $this->get_credits() );
	}
	public function set_credits( $value ) {
		$this->update_meta_value( 'credits', floatval( $value ) );
	}

	public function get_tax() {
		return floatval( $this->get_meta_value( 'tax' ) );
	}
	public function get_tax_formatted() {
		return sunshine_price( $this->get_tax() );
	}
	public function set_tax( $value ) {
		$this->update_meta_value( 'tax', floatval( $value ) );
	}

	public function get_discounts() {
		return $this->get_meta_value( 'discounts' );
	}

	/*
	 * @param array $value Array of discounts, could be classes or codes
	 */
	public function set_discounts( $values ) {
		if ( empty( $values ) ) {
			return;
		}
		$discounts = array();
		foreach ( $values as $discount ) {
			if ( is_a( $discount, 'SPC_Discount' ) ) {
				$discounts[] = $discount->get_code();
			} elseif ( is_string( $discount ) ) {
				$discounts[] = $discount;
			}
		}
		$this->update_meta_value( 'discounts', $discounts );
	}

	public function get_discount() {
		return floatval( $this->get_meta_value( 'discount' ) );
	}
	public function get_discount_formatted() {
		return '-' . sunshine_price( $this->get_discount() );
	}
	public function set_discount( $value ) {
		$this->update_meta_value( 'discount', floatval( $value ) );
	}
	public function has_discount() {
		return ( $this->get_discount() > 0 ) ? true : false;
	}
	public function set_discount_names( $value ) {
		$this->update_meta_value( 'discount_names', $value );
	}
	public function get_discount_names() {
		$discount_names = $this->meta['discount_names'];
		if ( ! empty( $discount_names ) ) {
			return $discount_names;
		}
		$discounts = $this->get_discounts();
		if ( empty( $discounts ) ) {
			return array();
		}
		$discount_names = array();
		foreach ( $discounts as $discount_code ) {
			$discount = sunshine_get_discount_by_code( $discount_code );
			if ( ! empty( $discount ) ) {
				$discount_names[] = $discount->get_name();
			} else {
				$discount_names[] = $discount_code; // Fallback to the code if not an active discount in database.
			}
		}
		return $discount_names;
	}

	public function get_delivery_method() {
		return $this->get_meta_value( 'delivery_method' );
	}
	public function get_delivery_method_name() {
		return $this->get_meta_value( 'delivery_method_name' );
	}
	public function set_delivery_method( $value ) {
		$this->update_meta_value( 'delivery_method', $value );
	}

	public function get_shipping() {
		return $this->get_meta_value( 'shipping' );
	}
	public function get_shipping_formatted() {
		$price_formatted = $this->price_formatted( $this->get_shipping(), $this->get_shipping_tax() );
		return $price_formatted;
		return sunshine_price( $this->get_shipping() );
	}
	public function set_shipping( $value ) {
		$this->update_meta_value( 'shipping', floatval( $value ) );
	}
	public function set_shipping_tax( $value ) {
		$this->update_meta_value( 'shipping_tax', floatval( $value ) );
	}
	public function get_shipping_tax() {
		return floatval( $this->meta['shipping_tax'] );
	}

	public function get_shipping_method() {
		return $this->get_meta_value( 'shipping_method' );
	}
	public function get_shipping_method_name() {
		return $this->get_meta_value( 'shipping_method_name' );
	}
	public function set_shipping_method( $value ) {
		$this->update_meta_value( 'shipping_method', $value );
	}

	public function get_payment_method() {
		return $this->get_meta_value( 'payment_method' );
	}
	public function get_payment_method_name() {
		return $this->get_meta_value( 'payment_method_name' );
	}
	public function set_payment_method( $value ) {
		$this->update_meta_value( 'payment_method', $value );
	}

	public function get_currency() {
		return $this->get_meta_value( 'currency' );
	}
	public function set_currency( $value ) {
		$this->update_meta_value( 'currency', $value );
	}

	public function get_date( $format = '' ) {
		if ( empty( $this->data->post_date ) ) {
			return '';
		}
		if ( empty( $format ) ) {
			$format = get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' );
		}
		return date( $format, strtotime( $this->data->post_date ) );
	}

	public function get_items() {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}sunshine_order_items WHERE order_id=%d",
				$this->get_id()
			)
		);

		$items = array();
		foreach ( $results as $item ) {
			$items[] = new SPC_Order_Item( (array) $item );
		}
		return $items;

	}

	public function get_cart() {
		return $this->get_items();
		/*
		$cart = $this->get_meta_value( 'cart' );
		if ( empty( $cart ) ) {
			return false;
		}
		$cart_items = array();
		foreach ( $cart as $item ) {
			$cart_items[] = new SPC_Order_Item( $item );
		}
		return $cart_items;
		*/
	}

	public function set_cart( $cart ) {
		$this->cart = $cart;
		$this->update_meta_value( 'cart', $cart );
	}

	public function get_customer_notes() {
		return $this->get_meta_value( 'customer_notes' );
	}

	public function get_notes() {
		return $this->get_meta_value( 'notes' );
	}

	public function get_vat() {
		return $this->get_meta_value( 'vat' );
	}
	public function set_vat( $value ) {
		$this->update_meta_value( 'vat', $value );
	}

	public function get_mode() {
		return $this->meta['mode'];
	}
	public function set_mode( $value ) {
		$this->update_meta_value( 'mode', $value );
	}

	public function set_price_has_tax( $value ) {
		if ( 'yes' === $value ) {
			$this->update_meta_value( 'price_has_tax', 'yes' );
		} else {
			$this->update_meta_value( 'price_has_tax', 'no' );
		}
	}

	public function get_galleries() {
		$cart = $this->get_cart();
		if ( ! empty( $cart ) ) {
			$galleries = array();
			foreach ( $cart as $order_item ) {
				if ( ! empty( $order_item->get_gallery_id() ) && ! in_array( $order_item->get_gallery_id(), $galleries ) ) {
					$galleries[] = $order_item->get_gallery_id();
				}
			}
			return $galleries;
		}
		return false;
	}

	public function get_invoice_permalink() {
		return apply_filters( 'sunshine_order_invoice_permalink', wp_nonce_url( $this->get_permalink(), 'order_invoice_' . $this->get_id(), 'order_invoice' ) );
	}

	public function get_comments() {
		$comments = get_comments( 'post_id=' . $this->get_id() . '&status=approve&order=ASC' );
		return $comments;
	}

	public function add_fee( $id, $amount, $name ) {
		$this->meta['fees'][ $id ] = array(
			'amount' => floatval( $amount ),
			'name'   => $name,
		);
		$this->update_meta_value( 'fees', $this->meta['fees'] );
	}

	public function update_fees( $fees ) {
		$this->clear_fees();
		if ( ! empty( $fees ) ) {
			foreach ( $fees as $id => $fee ) {
				$this->add_fee( $id, $fee['amount'], $fee['name'] );
			}
		}
	}

	public function get_fees() {
		return $this->meta['fees'];
	}

	public function clear_fees() {
		$this->update_meta_value( 'fees', array() );
	}

	public function get_fees_total() {
		$total = 0;
		if ( ! empty( $this->meta['fees'] ) ) {
			foreach ( $this->meta['fees'] as $fee ) {
				$total += $fee['amount'];
			}
		}
		return $total;
	}

	public function can_access( $order_key = '' ) {
		if ( current_user_can( 'sunshine_manage_options' ) ) { // Is admin
			return true;
		}
		if ( is_user_logged_in() && get_current_user_id() == $this->get_customer_id() ) { // logged in user is for this user
			return true;
		}
		if ( empty( $order_key ) ) {
			$order_key = $_GET['order_key'];
		}
		if ( $order_key == $this->get_order_key() ) {
			return true;
		}
		return false;
	}

	public function has_refunds() {
		return ( $this->get_refunds() ) ? true : false;
	}

	public function get_refunds() {
		return $this->get_meta_value( 'refunds' );
	}

	public function add_refund( $amount, $reason = '' ) {
		$refunds = $this->get_refunds();
		if ( empty( $refunds ) ) {
			$refunds = array();
		}
		$refunds[] = array(
			'date'   => current_time( 'timestamp' ),
			'amount' => $amount,
			'reason' => $reason,
		);
		$this->update_meta_value( 'refunds', $refunds );

		/* translators: %s is the refund amount formatted as price */
		$this->add_log( sprintf( __( 'Refund has been processed for %s', 'sunshine-photo-cart' ), sunshine_price( $amount ) ) );

		// Update this customer's order stats.
		$customer = $this->get_customer();
		if ( $customer ) {
			$customer->recalculate_stats();
		}
	}

	public function get_profit() {
		$items = $this->get_items();
		$cost  = 0;
		$price = 0;
		$fees  = floatval( $this->get_fees_total() );
		if ( ! empty( $items ) ) {
			foreach ( $items as $item ) {
				$cost  += floatval( $item->get_meta_value( 'cost' ) ) * intval( $item->get_qty() );
				$price += floatval( $item->get_total() );
			}
		}
		if ( $this->get_refunds() ) {
			$price -= floatval( $this->get_refund_total() );
		}
		$profit = $price + $fees - $cost;
		return max( $profit, 0 );
	}

	public function notify( $admin = true ) {
		do_action( 'sunshine_order_notify', $this, $admin );
	}

	public function create() {
		global $wpdb;

		// Set payment method to free if order total is 0
		if ( $this->get_total() == 0 ) {
			$this->set_payment_method( 'free' );
		}

		// Don't let through unless you have data required
		if ( empty( $this->get_payment_method() ) || empty( $this->cart ) ) {
			SPC()->log( 'Did not have enough info to create an order' );
			SPC()->log( 'Payment method: ' . $this->get_payment_method() );
			SPC()->log( 'Shipping method: ' . $this->get_shipping_method() );
			SPC()->log( 'Cart: ' . print_r( $this->cart, true ) );
			return false;
		}

		$payment_method_id = $this->get_meta_value( 'payment_method' );
		$payment_method    = SPC()->payment_methods->get_payment_method_by_id( $payment_method_id );
		if ( $payment_method ) {
			$this->update_meta_value( 'payment_method_name', $payment_method->get_name() );
		}

		$delivery_method_id = $this->get_meta_value( 'delivery_method' );
		if ( $delivery_method_id ) {
			$delivery_method = sunshine_get_delivery_method_by_id( $delivery_method_id );
			if ( $delivery_method ) {
				$this->update_meta_value( 'delivery_method_name', $delivery_method->get_name() );
			}
		}

		$shipping_method_instance = $this->get_meta_value( 'shipping_method' );
		if ( $shipping_method_instance ) {
			$shipping_method = sunshine_get_shipping_method_by_instance( $shipping_method_instance );
			if ( $shipping_method ) {
				$this->update_meta_value( 'shipping_method_name', $shipping_method->get_name() );
			}
		}

		// Let's populate any missing things with their defaults
		if ( empty( $this->get_meta_value( 'currency' ) ) ) {
			$this->update_meta_value( 'currency', SPC()->get_option( 'currency' ) );
		}
		if ( empty( $this->get_meta_value( 'display_price' ) ) ) {
			$this->update_meta_value( 'display_price', SPC()->get_option( 'display_price' ) );
		}

		if ( empty( $this->get_meta_value( 'order_key' ) ) ) {
			$this->update_meta_value( 'order_key', sunshine_generate_order_key() );
		}

		$order_id = wp_insert_post(
			array(
				'post_author'    => $this->get_meta_value( 'customer_id' ),
				'post_title'     => __( 'Order', 'sunshine-photo-cart' ),
				'post_status'    => 'publish',
				'post_type'      => $this->post_type,
				'comment_status' => 'closed',
				'meta_input'     => $this->meta,
			)
		);

		if ( is_wp_error( $order_id ) ) {
			return false;
		}

		$this->set_id( $order_id );
		$this->data = get_post( $order_id );

		$next_order_number = SPC()->get_option( 'next_order_number' );
		if ( empty( $next_order_number ) ) {
			$order_number = $order_id;
		} else {
			$order_number = $next_order_number;
			$this->update_meta_value( 'order_number', $order_number );
		}

		wp_update_post(
			array(
				'ID'         => $order_id,
				/* translators: %s is the order number */
				'post_title' => apply_filters( 'sunshine_new_order_title', sprintf( __( 'Order #%s', 'sunshine-photo-cart' ), $order_number ) ),
				'post_name'  => $order_number,
			)
		);

		if ( ! empty( $next_order_number ) ) {
			$next_order_number++;
			SPC()->update_option( 'next_order_number', $next_order_number );
		}

		// Get more verbose details on the cart items.
		// We want this saved to database so it is permanent should products/images be removed in the future.
		foreach ( $this->cart as &$item ) {
			$this->add_item( $item );
		}

		// Set order status
		SPC()->log( 'Setting order status to pending in order create()' );
		$this->set_status( 'pending' );

		do_action( 'sunshine_order_create', $this );

		SPC()->log( $this->get_name() . ' created' );

		return $order_id;

	}

	public function update() {
		global $wpdb;

		// Set payment method to free if order total is 0
		if ( $this->get_total() == 0 ) {
			$this->set_payment_method( 'free' );
		}

		// Don't let through unless you have data required
		if ( empty( $this->get_payment_method() ) || empty( $this->cart ) ) {
			SPC()->log( 'Did not have enough info to create an order' );
			SPC()->log( 'Payment method: ' . $this->get_payment_method() );
			SPC()->log( 'Shipping method: ' . $this->get_shipping_method() );
			SPC()->log( 'Cart: ' . print_r( $this->cart, true ) );
			return false;
		}

		$payment_method_id = $this->get_meta_value( 'payment_method' );
		$payment_method    = SPC()->payment_methods->get_payment_method_by_id( $payment_method_id );
		if ( $payment_method ) {
			$this->update_meta_value( 'payment_method_name', $payment_method->get_name() );
		}

		$delivery_method_id = $this->get_meta_value( 'delivery_method' );
		if ( $delivery_method_id ) {
			$delivery_method = sunshine_get_delivery_method_by_id( $delivery_method_id );
			if ( $delivery_method ) {
				$this->update_meta_value( 'delivery_method_name', $delivery_method->get_name() );
			}
		}

		$shipping_method_instance = $this->get_meta_value( 'shipping_method' );
		if ( $shipping_method_instance ) {
			$shipping_method = sunshine_get_shipping_method_by_instance( $shipping_method_instance );
			if ( $shipping_method ) {
				$this->update_meta_value( 'shipping_method_name', $shipping_method->get_name() );
			}
		}

		// Let's populate any missing things with their defaults
		if ( empty( $this->get_meta_value( 'currency' ) ) ) {
			$this->update_meta_value( 'currency', SPC()->get_option( 'currency' ) );
		}
		if ( empty( $this->get_meta_value( 'display_price' ) ) ) {
			$this->update_meta_value( 'display_price', SPC()->get_option( 'display_price' ) );
		}

		if ( empty( $this->get_meta_value( 'order_key' ) ) ) {
			$this->update_meta_value( 'order_key', sunshine_generate_order_key() );
		}

		// Get more verbose details on the cart items.
		// We want this saved to database so it is permanent should products/images be removed in the future.

		// Instead of inserting, we are going to delete and re-insert.
		$wpdb->delete(
			$wpdb->prefix . 'sunshine_order_items',
			array( 'order_id' => $this->get_id() )
		);

		foreach ( $this->cart as &$item ) {
			// TODO: Need to open this up to more than just images.
			$this->add_item( $item );
		}

		do_action( 'sunshine_order_update', $this );

		SPC()->log( $this->get_name() . ' updated' );

		return $this->get_id();

	}

	public function add_item( $item ) {
		global $wpdb;

		$cart_item = new SPC_Cart_Item( $item );

		if ( empty( $item['meta'] ) ) {
			$item['meta'] = array();
		}

		if ( ! empty( $item['image_id'] ) ) {
			$image = $cart_item->get_image();
			// $item['gallery_id']   = $cart_item->get_gallery_id();
			// $item['price_level']   = $image->gallery->get_price_level();
			// $item['meta']['gallery_name'] = $cart_item->get_gallery_name();
			$item['meta']['filename']   = $image->get_file_name();
			$item['meta']['image_name'] = $image->get_name();
		}

		if ( ! empty( $cart_item->get_gallery() ) ) {
			$item['meta']['gallery_name'] = $cart_item->get_gallery()->get_name();
		}

		if ( ! empty( $item['comments'] ) ) {
			$item['meta']['comments'] = $item['comments'];
		}

		$item['price'] = 0;

		if ( ! empty( $item['product_id'] ) ) {
			$product                          = sunshine_get_product( intval( $item['product_id'] ), intval( $item['price_level'] ) );
			$item['type']                     = $product->get_type();
			$item['price']                    = $cart_item->get_price();
			$item['meta']['product_name']     = $product->get_name();
			$item['meta']['product_cat_name'] = $product->get_category_name();
		}

		if ( ! empty( $item['options'] ) ) {
			$options = array();
			foreach ( $item['options'] as $option_id => $option_item_id ) {
				if ( $option_id == 'images' && ! empty( $item['options']['images'] ) ) {
					$options['images'] = array();
					foreach ( $item['options']['images'] as $image_id ) {
						$image               = sunshine_get_image( $image_id );
						$options['images'][] = array(
							'image_id'     => $image_id,
							'name'         => $image->get_name(),
							'filename'     => $image->get_file_name(),
							'gallery_name' => $image->get_gallery_name(),
						);
					}
					continue;
				}
				$option                = new SPC_Product_Option( $option_id );
				$option_price          = $product->get_option_item_price( $option_id, $option_item_id, $item['price_level'] );
				$options[ $option_id ] = array(
					// 'id'    => $option_item_id,
					'price' => $option_price,
				);
				// $item['price'] += $option_price;
				if ( $option->get_type() == 'checkbox' ) {
					$options[ $option_id ]['name'] = $option->get_name();
				} else {
					$options[ $option_id ]['name']  = $option->get_name();
					$options[ $option_id ]['value'] = $option->get_item_name( $option_item_id );
				}
			}
			$item['meta']['options'] = $options;
		}

		$item = apply_filters( 'sunshine_cart_item_before_order', $item );

		$insert_result = $wpdb->insert(
			$wpdb->prefix . 'sunshine_order_items',
			array(
				'order_id'    => $this->get_id(),
				'type'        => ( ! empty( $item['type'] ) ) ? $item['type'] : '',
				'image_id'    => ( ! empty( $item['image_id'] ) ) ? intval( $item['image_id'] ) : '',
				'qty'         => ( ! empty( $item['qty'] ) ) ? intval( $item['qty'] ) : '',
				'product_id'  => ( ! empty( $item['product_id'] ) ) ? intval( $item['product_id'] ) : '',
				'gallery_id'  => ( ! empty( $item['gallery_id'] ) ) ? intval( $item['gallery_id'] ) : '',
				'price_level' => ( ! empty( $item['price_level'] ) ) ? intval( $item['price_level'] ) : '',
				'price'       => ( ! empty( $item['price'] ) ) ? floatval( $item['price'] ) : '',
				'tax'         => $cart_item->get_tax(),
				'discount'    => $cart_item->get_discount(),
			)
		);

		// Insert any of the item meta data.
		if ( $insert_result ) {
			$order_item_id = $wpdb->insert_id;
			if ( $order_item_id && ! empty( $item['meta'] ) ) {
				foreach ( $item['meta'] as $key => $value ) {
					if ( empty( $value ) ) {
						continue;
					}
					$wpdb->insert(
						$wpdb->prefix . 'sunshine_order_itemmeta',
						array(
							'order_item_id' => $order_item_id,
							'meta_key'      => $key,
							'meta_value'    => maybe_serialize( $value ),
						)
					);
				}
			}
		}

	}

	function get_price_to_display( $price, $tax = 0, $force_suffix = false ) {
		$price_has_tax = $this->get_meta_value( 'price_has_tax' );
		$display_price = $this->get_meta_value( 'display_price' );
		$suffix        = SPC()->get_option( 'price_suffix' );

		if ( 'with_tax' === $display_price && $price_has_tax == 'yes' ) {
			$price += $tax;
		}

		$price_formatted = sunshine_price( $price );

		if ( ( $price > 0 && $suffix ) || $force_suffix ) {
			$price_formatted .= ' <small class="sunshine--price--suffix">' . $suffix . '</small>';
		}

		return $price_formatted;
	}

}
