<?php

class SPC_Payment_Method_Test extends SPC_Payment_Method {

	public function init() {
		$this->id             = 'test';
		$this->name           = __( 'Test', 'sunshine-photo-cart' );
		$this->class          = get_class( $this );
		$this->description    = __( 'For processing test orders', 'sunshine-photo-cart' );
		$this->can_be_enabled = true;

		add_action( 'admin_notices', array( $this, 'active_warning' ) );
	}

	// Test orders are marked paid without any payment, so make it hard to leave this on by accident.
	public function active_warning() {
		if ( ! $this->is_active() || ! current_user_can( 'sunshine_manage_options' ) ) {
			return;
		}
		if ( SPC()->get_option( $this->id . '_allow_customers' ) ) {
			$message = __( 'The Test payment method is on and every customer can use it, so orders can be placed without paying. Turn it off when you are done testing.', 'sunshine-photo-cart' );
			$class   = 'notice-error';
		} else {
			$message = __( 'The Test payment method is on. Only admins can use it at checkout. Turn it off when you are done testing.', 'sunshine-photo-cart' );
			$class   = 'notice-warning';
		}
		echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>Sunshine Photo Cart:</strong> ' . esc_html( $message ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=' . $this->id ) ) . '">' . esc_html__( 'Payment method settings', 'sunshine-photo-cart' ) . '</a></p></div>';
	}

	public function create_order_status( $status, $order ) {
		if ( $order->get_payment_method() == $this->id ) {
			SPC()->log( 'Setting order status to new for test payment' );
			return 'new'; // Straight to new, no payment needed
		}
		return $status;
	}

	public function mode( $mode, $order ) {
		if ( $order->get_payment_method() == 'test' ) {
			return 'test'; // Always a test mode order
		}
		return $mode;
	}

	public function options( $options ) {
		$options[] = array(
			'name'        => __( 'Allow Customers', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_allow_customers',
			'type'        => 'checkbox',
			'description' => __( 'Let customers who are not admins use this payment method. Their orders are marked paid without any payment, so only turn this on while testing.', 'sunshine-photo-cart' ),
		);
		return $options;
	}


	public function is_allowed() {

		// Admins only, unless the store has chosen to let customers use it too.
		return current_user_can( 'sunshine_manage_options' ) || SPC()->get_option( $this->id . '_allow_customers' );

	}



}
