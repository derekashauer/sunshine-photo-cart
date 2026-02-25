<?php

class SPC_Delivery_Method_Pickup extends SPC_Delivery_Method {

	public function init() {
		$this->id             = 'pickup';
		$this->name           = __( 'Pickup', 'sunshine-photo-cart' );
		$this->class          = 'SPC_Delivery_Method_Pickup';
		$this->description    = __( 'Pickup up your order items', 'sunshine-photo-cart' );
		$this->needs_shipping = false;
		$this->can_be_enabled = true;
	}

}

$sunshine_delivery_method_pickup = new SPC_Delivery_Method_Pickup();
