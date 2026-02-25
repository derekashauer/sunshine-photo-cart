<?php

class SPC_Payment_Method_Offline extends SPC_Payment_Method {

	public function init() {
		$this->id                    = 'offline';
		$this->name                  = __( 'Offline', 'sunshine-photo-cart' );
		$this->class                 = get_class( $this );
		$this->description           = __( 'Payments handled offline (like check or cash)', 'sunshine-photo-cart' );
		$this->can_be_enabled        = true;
		$this->needs_billing_address = false;

		add_action( 'sunshine_order', array( $this, 'show_instructions' ), 12 );
		add_action( 'sunshine_email_order_receipt', array( $this, 'show_instructions_email' ), 12 );

	}

	public function options( $options ) {
		$options[] = array(
			'name'        => __( 'Instructions', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_instructions',
			'type'        => 'textarea',
			'description' => __( 'Instructions included on order receipt page and email with how to complete payment', 'sunshine-photo-cart' ),
		);
		/*
		$options[] = array(
			'name'        => __( 'Ask for billing address', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_needs_billing',
			'type'        => 'checkbox',
			'description' => __( 'Do you want the checkout to ask the customer for billing address at checkout', 'sunshine-photo-cart' ),
		);
		*/
		return $options;
	}

	public function show_instructions() {

		$payment_method = SPC()->frontend->current_order->get_payment_method();
		if ( $payment_method == $this->id && ! in_array( SPC()->frontend->current_order->get_status(), sunshine_order_statuses_paid() ) ) {
			$instructions = $this->get_option( 'instructions' );
			if ( $instructions ) {
				sunshine_get_template( 'order/instructions', array( 'instructions' => $instructions ) );
			}
		}

	}

	public function show_instructions_email( $order ) {

		if ( $order->get_payment_method() == $this->id && ! in_array( $order->get_status(), sunshine_order_statuses_paid() ) ) {
			$instructions = $this->get_option( 'instructions' );
			if ( $instructions ) {
				sunshine_get_template( 'order/instructions', array( 'instructions' => $instructions ) );
			}
		}

	}

	public function get_submit_label() {
		return __( 'Submit order', 'sunshine-photo-cart' );
	}

	/*
	public function needs_billing_address() {
		return SPC()->get_option( $this->id . '_needs_billing' );
	}
	*/

}
