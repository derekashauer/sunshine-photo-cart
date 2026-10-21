<?php

class SPC_Shipping_Method_Flat_Rate extends SPC_Shipping_Method {

	public function init() {
		$this->id            = 'flat_rate';
		$this->name          = __( 'Flat Rate', 'sunshine-photo-cart' );
		$this->class         = 'SPC_Shipping_Method_Flat_Rate';
		$this->description   = '';
		$this->can_be_cloned = true;
	}

	public function is_allowed() {

		if ( empty( $this->instance_id ) ) {
			return false;
		}

		$allowed = apply_filters( 'sunshine_shipping_flat_rate_allowed', true, $this );

		return $allowed;

	}


}

$sunshine_shipping_flat_rate = new SPC_Shipping_Method_Flat_Rate();
