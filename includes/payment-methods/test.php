<?php

class SPC_Payment_Method_Test extends SPC_Payment_Method {

	public function init() {
		$this->id             = 'test';
		$this->name           = __( 'Test', 'sunshine-photo-cart' );
		$this->class          = get_class( $this );
		$this->description    = __( 'For processing test orders', 'sunshine-photo-cart' );
		$this->can_be_enabled = true;
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
			'name'        => __( 'Admin Only', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_admin',
			'type'        => 'checkbox',
			'description' => __( 'Only allow for admin users', 'sunshine-photo-cart' ),
		);
		return $options;
	}


	public function is_allowed() {

		$allowed = true;
		if ( SPC()->get_option( $this->id . '_admin' ) && ! current_user_can( 'sunshine_manage_options' ) ) {
			$allowed = false;
		}

		return $allowed;

	}



}
