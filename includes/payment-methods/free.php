<?php

class SPC_Payment_Method_Free extends SPC_Payment_Method {

	public function init() {
		$this->id             = 'free';
		$this->name           = __( 'Free', 'sunshine-photo-cart' );
		$this->class          = get_class( $this );
		$this->description    = __( 'For processing free orders', 'sunshine-photo-cart' );
		$this->can_be_enabled = false;
	}

	public function create_order_status( $status, $order ) {
		if ( $order->get_payment_method() == $this->id ) {
			return 'new'; // Straight to new, no payment needed.
		}
		return $status;
	}

}
