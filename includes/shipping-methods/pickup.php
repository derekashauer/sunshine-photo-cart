<?php

class SPC_Shipping_Method_Pickup extends SPC_Shipping_Method {

	public function init() {
		$this->id                     = 'pickup';
		$this->name                   = __( 'Pickup', 'sunshine-photo-cart' );
		$this->class                  = 'SPC_Shipping_Method_Pickup';
		$this->description            = __( 'Pick up your order at a designated location', 'sunshine-photo-cart' );
		$this->can_be_cloned          = true;
		$this->needs_shipping_address = false;
	}

	public function options( $fields, $instance_id ) {
		$fields['2200'] = array(
			'name'        => __( 'Pickup Location Details', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_location_' . $instance_id,
			'type'        => 'textarea',
			'description' => __( 'Address, hours, or other instructions for this pickup location. Shown to the customer on the order confirmation.', 'sunshine-photo-cart' ),
		);
		return $fields;
	}

	public function is_allowed() {

		if ( empty( $this->instance_id ) ) {
			return false;
		}

		$allowed = true;
		$allowed = apply_filters( 'sunshine_shipping_pickup_allowed', $allowed, $this );

		return $allowed;

	}

}

new SPC_Shipping_Method_Pickup();
