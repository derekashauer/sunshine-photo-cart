<?php

class SPC_Shipping_Method_Local extends SPC_Shipping_Method {

	public function init() {
		$this->id    = 'local';
		$this->name  = __( 'Local Delivery', 'sunshine-photo-cart' );
		$this->class = 'SPC_Shipping_Method_Local';
		// $this->description   = __( 'We personally deliver your order', 'sunshine-photo-cart' );
		$this->can_be_cloned = true;
		// Per the docs, Local Delivery charges only its own price, no Extra Shipping Cost.
		$this->adds_product_shipping = false;
	}

	public function options( $fields, $instance_id ) {
		$fields['2200'] = array(
			'name'        => __( 'Allowed Zip / Post codes', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_postcodes_' . $instance_id,
			'type'        => 'textarea',
			'description' => __( 'Allowed zip or postal codes for this shipping option, separated by commas. Ex: 80525,80526,80527. Use * as a wildcard (805* matches anything starting with 805) or ... for a numeric range (80525...80527). Leave empty to allow all.', 'sunshine-photo-cart' ),
		);
		return $fields;
	}

	public function is_allowed() {

		if ( empty( $this->instance_id ) ) {
			return false;
		}

		$allowed = true;

		// If no zipcodes are set, let anyone use this
		$postcodes = SPC()->get_option( $this->id . '_postcodes_' . $this->instance_id );
		if ( ! empty( $postcodes ) ) {

			// If has any zipcodes, but no customer zipcode set somehow then we fail
			$customer_postcode = SPC()->cart->get_checkout_data_item( 'shipping_postcode' );
			if ( empty( $customer_postcode ) ) {
				return false;
			}

			// Customer zipcode not in allowed zipcodes
			if ( ! sunshine_postcode_matches( $customer_postcode, $postcodes ) ) {
				return false;
			}
		}

		$allowed = apply_filters( 'sunshine_shipping_local_allowed', $allowed, $this );

		return $allowed;

	}

}

$sunshine_shipping_local = new SPC_Shipping_Method_Local();
