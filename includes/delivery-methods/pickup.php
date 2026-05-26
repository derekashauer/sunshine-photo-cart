<?php
/**
 * Pickup delivery method.
 *
 * As of 3.6.10 pickup locations are configured as cloneable shipping method instances
 * (see /includes/shipping-methods/pickup.php). This delivery method registers itself
 * whenever at least one pickup shipping method instance is active, so the checkout
 * keeps showing the Ship vs Pickup radio. The old pickup_enabled / pickup_label /
 * pickup_description admin fields are gone.
 */

class SPC_Delivery_Method_Pickup extends SPC_Delivery_Method {

	public function init() {
		$this->id             = 'pickup';
		$this->name           = __( 'Pickup', 'sunshine-photo-cart' );
		$this->class          = 'SPC_Delivery_Method_Pickup';
		$this->description    = __( 'Pick up your order at a designated location', 'sunshine-photo-cart' );
		$this->needs_shipping = false;
		$this->can_be_enabled = false;
	}

	public function is_enabled() {
		$active = sunshine_get_active_shipping_methods();
		if ( empty( $active ) ) {
			return false;
		}
		foreach ( $active as $instance_id => $data ) {
			if ( ! empty( $data['id'] ) && $data['id'] === 'pickup' ) {
				return true;
			}
		}
		return false;
	}

}

new SPC_Delivery_Method_Pickup();
