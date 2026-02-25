<?php

class SPC_Delivery_Method_Shipping extends SPC_Delivery_Method {

	public function init() {
		$this->id = 'shipping';
		// Translators: This is the name of the delivery method that will be displayed to the customer.
		$this->name           = __( 'Ship', 'sunshine-photo-cart' );
		$this->class          = 'SPC_Delivery_Method_Shipping';
		$this->description    = __( 'Order items shipped or delivered to your provided address', 'sunshine-photo-cart' );
		$this->needs_shipping = true;
	}

	public function is_enabled() {
		if ( sunshine_get_allowed_shipping_methods() ) {
			return true;
		}
		return false;
	}

}

$sunshine_delivery_method_shipping = new SPC_Delivery_Method_Shipping();
