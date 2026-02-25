<?php

class SPC_Shipping_Method_Free extends SPC_Shipping_Method {

	public function init() {
		$this->id            = 'free';
		$this->name          = __( 'Free', 'sunshine-photo-cart' );
		$this->class         = 'SPC_Shipping_Method_Free';
		$this->description   = __( 'A free shipping method', 'sunshine-photo-cart' );
		$this->can_be_cloned = true;
	}

	public function options( $fields, $instance_id ) {
		unset( $fields[30] );
		unset( $fields[40] );
		$fields['2200'] = array(
			'name'        => __( 'Via Discount', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_discount_' . $instance_id,
			'type'        => 'checkbox',
			'description' => __( 'Only allow this method via discount code that enables Free Shipping', 'sunshine-photo-cart' ),
		);
		return $fields;
	}

	public function is_allowed() {

		if ( empty( $this->instance_id ) ) {
			return false;
		}

		$allowed = true;

		$discount_required = SPC()->get_option( $this->id . '_discount_' . $this->instance_id );

		// Discount required and no discounts in cart, auto fail.
		if ( ! empty( $discount_required ) && empty( SPC()->cart->get_discounts() ) ) {
			return false;
		}

		// Go through discount codes and see if one applies here.
		if ( ! empty( $discount_required ) ) {
			$discounts = SPC()->cart->get_discounts();
			if ( ! empty( $discounts ) ) {
				$enables_free = false;
				foreach ( $discounts as $discount ) {
					if ( $discount->enables_free_shipping() ) {
						$enables_free = true;
					}
				}
				if ( ! $enables_free ) {
					return false; // must have discount to be available.
				}
			}
		}

		$allowed = apply_filters( 'sunshine_shipping_free_allowed', $allowed, $this );

		return $allowed;

	}

	public function get_price() {
		return 0;
	}

}

new SPC_Shipping_Method_Free();
