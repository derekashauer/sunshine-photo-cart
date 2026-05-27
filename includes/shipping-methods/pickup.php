<?php

class SPC_Shipping_Method_Pickup extends SPC_Shipping_Method {

	public function init() {
		$this->id                     = 'pickup';
		$this->name                   = __( 'Pickup', 'sunshine-photo-cart' );
		$this->class                  = 'SPC_Shipping_Method_Pickup';
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

	public function get_location_details() {
		if ( empty( $this->instance_id ) ) {
			return '';
		}
		return SPC()->get_option( $this->id . '_location_' . $this->instance_id );
	}

}

new SPC_Shipping_Method_Pickup();

// Pickup instructions render AFTER the order details on the on-site order page
// (sunshine_display_order_details fires at priority 20, so 25 sits right after it).
add_action( 'sunshine_order', 'sunshine_pickup_show_instructions', 25 );
add_action( 'sunshine_order_received', 'sunshine_pickup_show_instructions', 25 );
function sunshine_pickup_show_instructions() {
	$order = SPC()->frontend->current_order;
	if ( $order && $order->is_pickup_order() ) {
		$instructions = $order->get_pickup_location_details();
		if ( $instructions ) {
			sunshine_get_template( 'order/instructions', array( 'instructions' => $instructions ) );
		}
	}
}

// In the receipt email the "order details" are the cart items + totals table; the
// after_order_notes hook fires right after that block.
add_action( 'sunshine_email_receipt_after_order_notes', 'sunshine_pickup_show_instructions_email' );
function sunshine_pickup_show_instructions_email( $order ) {
	if ( $order && $order->is_pickup_order() ) {
		$instructions = $order->get_pickup_location_details();
		if ( $instructions ) {
			sunshine_get_template( 'order/instructions', array( 'instructions' => $instructions ) );
		}
	}
}
