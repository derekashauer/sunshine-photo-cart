<?php
class SPC_Payment_Method_PayPal_Legacy extends SPC_Payment_Method {

	private $extra_meta_data = array();
	private $api_url;
	private $token;

	public function init() {

		$this->id                    = 'paypal-legacy';
		$this->name                  = 'PayPal (Legacy)';
		$this->class                 = get_class( $this );
		$this->description           = __( 'Pay with credit card or your PayPal account', 'sunshine-photo-cart' );
		$this->can_be_enabled        = true;
		$this->needs_billing_address = false;

		add_action( 'sunshine_checkout_process_payment_' . $this->id, array( $this, 'process_payment' ) );

		add_filter( 'sunshine_order_transaction_url', array( $this, 'transaction_url' ) );

		add_filter( 'sunshine_admin_order_tabs', array( $this, 'admin_order_tab' ), 10, 2 );
		add_action( 'sunshine_admin_order_tab_paypal-legacy', array( $this, 'admin_order_tab_content_paypal_legacy' ) );

		add_action( 'wp', array( $this, 'ipn' ) );
		add_action( 'wp', array( $this, 'clear_cart' ) );
		add_action( 'wp', array( $this, 'cancel_order' ) );

	}

	public function options( $options ) {

		$options[10]['description'] = 'This is a Legacy PayPal method and should only be used by PayPal Personal accounts';

		$options[] = array(
			'name'    => __( 'Mode', 'sunshine-photo-cart' ),
			'id'      => $this->id . '_mode',
			'type'    => 'radio',
			'options' => array(
				'live' => __( 'Live', 'sunshine-photo-cart' ),
				'test' => __( 'Sandbox', 'sunshine-photo-cart' ),
			),
			'default' => 'live',
		);
		$options[] = array(
			'name'       => __( 'Email', 'sunshine-photo-cart' ),
			'id'         => $this->id . '_email_live',
			'type'       => 'text',
			'conditions' => array(
				array(
					'field'   => $this->id . '_mode',
					'compare' => '==',
					'value'   => 'live',
					'action'  => 'show',
				),
			),
		);
		$options[] = array(
			'name'       => __( 'Email', 'sunshine-photo-cart' ),
			'id'         => $this->id . '_email_test',
			'type'       => 'text',
			'conditions' => array(
				array(
					'field'   => $this->id . '_mode',
					'compare' => '==',
					'value'   => 'test',
					'action'  => 'show',
				),
			),
		);
		return $options;
	}

	public function get_option( $key ) {
		return SPC()->get_option( $this->id . '_' . $key );
	}

	public function get_email() {
		return ( $this->get_mode_value() != 'live' ) ? $this->get_option( 'email_test' ) : $this->get_option( 'email_live' );
	}

	public function is_active() {
		$active = SPC()->get_option( $this->id . '_active' );
		if ( ! empty( $active ) ) {
			return true;
		}
		return false;
	}

	public function process_payment( $order ) {
		$paypal_url            = ( $this->get_mode_value() != 'live' ) ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr';
		$paypal_args           = array();
		$paypal_args['custom'] = $order->get_id();
		?>

		<html>
	<head>
		<title><?php esc_html_e( 'Redirecting to PayPal', 'sunshine-photo-cart' ); ?>...</title>
		<style type="text/css">
		body, html { margin: 0; padding: 50px; background: #FFF; }
		h1 { color: #000; text-align: center; font-family: Arial; font-size: 24px; }
		</style>
	</head>
	<body>
		<h1><?php esc_html_e( 'Redirecting to PayPal', 'sunshine-photo-cart' ); ?>...</h1>
		<form method="post" action="<?php echo esc_url( $paypal_url ); ?>" id="paypal" style="display: none;">

		<?php
		$i = 1;
		foreach ( $order->get_items() as $item ) {
			$name_key                     = 'item_name_' . $i;
			$quantity_key                 = 'quantity_' . $i;
			$amount_key                   = 'amount_' . $i;
			$paypal_args[ $name_key ]     = $item->get_name_raw();
			$paypal_args[ $quantity_key ] = $item->get_qty();
			$paypal_args[ $amount_key ]   = $item->get_price() - $item->get_discount_per_item();
			$i++;
		}
		if ( $order->get_shipping() > 0 ) {
			$paypal_args[ 'item_name_' . $i ] = $order->get_shipping_method_name();
			$paypal_args[ 'quantity_' . $i ]  = 1;
			$paypal_args[ 'amount_' . $i ]    = round( $order->get_shipping(), 2 );
		}
		$paypal_args['tax_cart'] = round( $order->get_tax(), 2 );
		$discount_total          = 0;
		if ( $order->get_discount() ) {
			$discount_total = $order->get_discount();
		}
		if ( $order->get_credits() > 0 ) {
			$discount_total += $order->get_credits();
		}
		$paypal_args['discount_amount_cart'] = round( $discount_total, 2 );

		// Business Info
		$paypal_args['business']      = $this->get_email();
		$paypal_args['cmd']           = '_cart';
		$paypal_args['upload']        = '1';
		$paypal_args['charset']       = 'utf-8';
		$paypal_args['currency_code'] = SPC()->get_option( 'currency' );
		$paypal_args['return']        = add_query_arg( array( 'paypal_complete' => '1' ), $order->get_permalink() );
		$paypal_args['cancel_return'] = wp_nonce_url( add_query_arg( 'order_id', $order->get_id(), sunshine_get_page_url( 'checkout' ) ), 'paypal_cancel', 'paypal_cancel' );
		$paypal_args['notify_url']    = trailingslashit( get_bloginfo( 'url' ) ) . '?sunshine_paypal_ipn=paypal_standard_ipn';
		if ( $order->get_delivery_method() == 'pickup' || $order->get_delivery_method() == 'download' ) {
			// Don't need any shipping info, so don't pass anything
			$paypal_args['no_shipping'] = 1;
		} else {
			// Need shipping information
			$paypal_args['no_shipping']      = 2;
			$paypal_args['address_override'] = 1;
			// Send what we got
			$paypal_args['address1'] = $order->get_shipping_address1();
			$paypal_args['address2'] = $order->get_shipping_address2();
			$paypal_args['city']     = $order->get_shipping_city();
			$paypal_args['state']    = $order->get_shipping_state();
			$paypal_args['zip']      = $order->get_shipping_postcode();
			$paypal_args['country']  = $order->get_shipping_country();
			if ( empty( $paypal_args['country'] ) ) {
				$paypal_args['country'] = SPC()->get_option( 'country' );
			}
		}

		// Prefill user info
		$paypal_args['first_name']    = $order->get_customer_first_name();
		$paypal_args['last_name']     = $order->get_customer_last_name();
		$paypal_args['email']         = $order->get_email();
		$phone                        = preg_replace( '/[^0-9,.]/', '', $order->get_phone() );
		$paypal_args['night_phone_a'] = substr( $phone, 0, 3 );
		$paypal_args['night_phone_b'] = substr( $phone, 3, 3 );
		$paypal_args['night_phone_c'] = substr( $phone, 6, 4 );

		$paypal_args = apply_filters( 'sunshine_paypal_args', $paypal_args );

		foreach ( $paypal_args as $key => $value ) {
			$paypal_args_array_escaped[] = '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
		}
		echo implode( "\r\n", $paypal_args_array_escaped ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>

		<input type="submit" value="<?php esc_html_e( 'Submit payment via PayPal', 'sunshine-photo-cart' ); ?>" />
	</form>
	<script>
		document.getElementById("paypal").submit();
	</script>
	</body>
	</html>

		<?php
		exit;

	}

	public function get_transaction_id( $order ) {
		if ( $order->get_payment_method() == $this->id ) {
			return $order->get_meta_value( 'paypal_txn_id' );
		}
		return false;
	}

	public function get_transaction_url( $order ) {
		if ( $order->get_payment_method() == $this->id ) {
			$transaction_id = $this->get_transaction_id( $order );
			if ( $transaction_id ) {
				$transaction_url  = ( $this->get_mode_value() != 'live' ) ? 'https://www.sandbox.paypal.com/activity/payment/' : 'https://www.paypal.com/activity/payment/';
				$transaction_url .= $transaction_id;
				return $transaction_url;
			}
		}
		return false;
	}

	public function admin_order_tab( $tabs, $order ) {
		if ( $order->get_payment_method() == $this->id ) {
			$tabs[ $this->id ] = $this->name;
		}
		return $tabs;
	}

	public function admin_order_tab_content_paypal_legacy( $order ) {
		echo '<table class="sunshine-data">';
		if ( $order->get_meta_value( 'paypal_txn_id' ) ) {
			echo '<tr><th>' . esc_html__( 'Transaction ID', 'sunshine-photo-cart' ) . '</th><td><a href="' . esc_url( $this->get_transaction_url( $order ) ) . '" target="_blank">' . esc_html( $order->get_meta_value( 'paypal_txn_id' ) ) . '</a></td></tr>';
		}
		if ( $order->get_meta_value( 'paypal_payment_fee' ) ) {
			echo '<tr><th>' . esc_html__( 'Transaction fees', 'sunshine-photo-cart' ) . '</th><td>' . wp_kses_post( sunshine_price( $order->get_meta_value( 'paypal_payment_fee' ), true ) ) . '</td></tr>';
		}
		echo '</table>';
	}

	public function mode( $mode, $order ) {
		if ( $order->get_payment_method() == $this->id ) {
			return $this->get_mode_value();
		}
		return $mode;
	}

	function ipn() {

		if ( isset( $_GET['sunshine_paypal_ipn'] ) && $_GET['sunshine_paypal_ipn'] == 'paypal_standard_ipn' && ! empty( $_POST ) ) {

			SPC()->log( 'Receiving IPN from PayPal' );

			$raw_post_data  = file_get_contents( 'php://input' );
			$raw_post_array = explode( '&', $raw_post_data );
			$myPost         = array();
			foreach ( $raw_post_array as $keyval ) {
				$keyval = explode( '=', $keyval );
				if ( count( $keyval ) == 2 ) {
					$myPost[ $keyval[0] ] = urldecode( $keyval[1] );
				}
			}

			// Only handle new order IPNs
			if ( ! in_array( $myPost['txn_type'], array( 'cart', 'express_checkout' ) ) ) {
				exit;
			}

			// read the IPN message sent from PayPal and prepend 'cmd=_notify-validate'
			$req = 'cmd=_notify-validate';
			if ( function_exists( 'get_magic_quotes_gpc' ) ) {
				$get_magic_quotes_exists = true;
			}
			foreach ( $myPost as $key => $value ) {
				if ( $get_magic_quotes_exists == true && get_magic_quotes_gpc() == 1 ) {
					$value = urlencode( stripslashes( $value ) );
				} else {
					$value = urlencode( $value );
				}
				$req .= "&$key=$value";
			}

			$paypal_url = ( $this->get_mode_value() != 'live' ) ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr';

			$response = wp_remote_post(
				$paypal_url,
				array(
					'method'      => 'POST',
					'timeout'     => 45,
					'redirection' => 5,
					'httpversion' => '1.1',
					'blocking'    => true,
					'headers'     => array(),
					'body'        => $req,
					'cookies'     => array(),
				)
			);

			if ( is_wp_error( $response ) ) {
				exit;
			} else {
				$res = wp_remote_retrieve_body( $response );
			}

			if ( strcmp( $res, 'VERIFIED' ) != 0 ) {
				exit;
			}

			$order_id = intval( $_POST['custom'] );
			$order    = sunshine_get_order( $order_id );
			if ( ! $order->exists() ) {
				exit;
			}

			SPC()->log( 'Updating ' . $order->get_name() . ' from PayPal IPN' );

			foreach ( $myPost as $key => $value ) {
				$order->update_meta_value( 'paypal_' . sanitize_key( $key ), sanitize_text_field( $value ) );
			}

			SPC()->cart->post_process_order( $order );

			exit;
		}
	}

	public function create_order_status( $status, $order ) {
		if ( $order->get_payment_method() == $this->id ) {
			SPC()->log( 'Setting order status to new for PayPal Legacy payment' );
			return 'new';
		}
		return $status;
	}

	function clear_cart() {
		global $sunshine;
		if ( isset( $_GET['paypal_complete'] ) ) {
			SPC()->session->set( 'checkout_data', '' );
			SPC()->session->set( 'checkout_sections_completed', '' );
			SPC()->cart->empty_cart();
			$url = remove_query_arg( 'paypal_complete' );
			sleep( 5 ); // Give time for IPN to be received before showing receipt page.
			wp_safe_redirect( $url );
			exit;
		}
	}

	function cancel_order() {
		if ( isset( $_GET['paypal_cancel'] ) && wp_verify_nonce( $_GET['paypal_cancel'], 'paypal_cancel' ) && isset( $_GET['order_id'] ) ) {
			$order_id = intval( $_GET['order_id'] );
			$order    = sunshine_get_order( $order_id );
			if ( $order->exists() ) {
				$order->delete( true );
			}
			SPC()->cart->add_error( __( 'PayPal payment has been cancelled', 'sunshine-photo-cart' ) );
			wp_safe_redirect( sunshine_get_page_url( 'checkout' ) );
			exit;
		}
	}

	public function get_submit_label() {
		return __( 'Continue to PayPal', 'sunshine-photo-cart' );
	}

}
