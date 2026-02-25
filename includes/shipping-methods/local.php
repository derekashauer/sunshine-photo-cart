<?php

class SPC_Shipping_Method_Local extends SPC_Shipping_Method {

	public function init() {
		$this->id    = 'local';
		$this->name  = __( 'Local Delivery', 'sunshine-photo-cart' );
		$this->class = 'SPC_Shipping_Method_Local';
		// $this->description   = __( 'We personally deliver your order', 'sunshine-photo-cart' );
		$this->can_be_cloned = true;
	}

	public function options( $fields, $instance_id ) {
		$fields['2200'] = array(
			'name'        => __( 'Allowed Zip / Post codes', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_postcodes_' . $instance_id,
			'type'        => 'textarea',
			'description' => __( 'Allowed zipcode or postal codes that are allowed for this shipping option, separated by commas. Ex: 80525,80526,80527. Leave empty to allow all.', 'sunshine-photo-cart' ),
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
			$postcodes = explode( ',', str_replace( ' ', '', $postcodes ) );
			if ( ! in_array( $customer_postcode, $postcodes ) ) {
				return false;
			}
		}

		$allowed = apply_filters( 'sunshine_shipping_local_allowed', $allowed, $this );

		return $allowed;

	}

	public function set_price() {

		if ( empty( $this->instance_id ) ) {
			return;
		}

		$price_has_tax = SPC()->get_option( 'price_has_tax' );
		$tax_rate      = SPC()->cart->get_tax_rate();

		// Get configured price and extract tax if needed.
		$this->price = floatval( SPC()->get_option( $this->id . '_price_' . $this->instance_id ) );

		if ( 'yes' === $price_has_tax && $this->is_taxable() && $tax_rate ) {
			// Extract tax from configured price.
			$base_price  = round( $this->price / ( $tax_rate['rate'] + 1 ), 2 );
			$this->tax   = round( $this->price - $base_price, 2 );
			$this->price = $base_price;
		} elseif ( $this->price && $this->is_taxable() && $tax_rate ) {
			// Calculate tax on price when tax not included.
			$this->tax = round( $this->price * $tax_rate['rate'], 2 );
		}

	}

}

$sunshine_shipping_local = new SPC_Shipping_Method_Local();
