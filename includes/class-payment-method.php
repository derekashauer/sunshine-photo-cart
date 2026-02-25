<?php
class SPC_Payment_Method {

	public $id;
	protected $active;
	protected $name;
	protected $description;
	protected $class;
	protected $can_be_enabled        = true;
	protected $needs_billing_address = false;

	public function __construct() {
		$this->init();
		// add_filter( 'sunshine_payment_methods', array( $this, 'register' ) );

		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			add_filter( 'sunshine_options_payment_method_' . $this->id, array( $this, 'default_options' ), 1 );
			add_filter( 'sunshine_options_payment_method_' . $this->id, array( $this, 'options' ), 10 );
		}

		add_filter( 'sunshine_create_order_status', array( $this, 'create_order_status' ), 10, 2 );
		add_filter( 'sunshine_order_transaction_url', array( $this, 'get_transaction_url' ) );
		add_filter( 'sunshine_checkout_create_order_mode', array( $this, 'mode' ), 10, 2 );
	}

	public function init() { }

	/*
	public function register( $payment_methods = array() ) {
		if ( !empty( $this->id ) && !empty( $this->class ) ) {
			$payment_methods[] = $this->class;
		}
		return $payment_methods;
	}
	*/

	// Every payment method will at least have these options
	public function default_options( $fields ) {
		$fields[10] = array(
			'id'          => $this->id . '_header',
			'name'        => $this->name,
			'type'        => 'header',
			'description' => '',
		);
		$fields[20] = array(
			'name'        => __( 'Name', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_name',
			'type'        => 'text',
			'description' => __( 'Name displayed on the checkout page to the customer', 'sunshine-photo-cart' ),
			'placeholder' => $this->name,
		);
		$fields[30] = array(
			'name'        => __( 'Description', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_description',
			'type'        => 'text',
			'description' => __( 'Description displayed on the checkout page to the customer', 'sunshine-photo-cart' ),
			'placeholder' => $this->description,
		);
		$fields[40] = array(
			'name'        => __( 'Fees', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_fee',
			'type'        => 'radio',
			'description' => __( 'Fees added to the order for using this payment method', 'sunshine-photo-cart' ),
			'options'     => array(
				'none'    => __( 'No added fees', 'sunshine-photo-cart' ),
				'percent' => __( 'Percentage of total order', 'sunshine-photo-cart' ),
				'amount'  => __( 'Fixed amount', 'sunshine-photo-cart' ),
			),
		);
		$fields[41] = array(
			'name'        => __( 'Fee Name', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_fee_name',
			'type'        => 'text',
			'description' => __( 'What description shown to customer at checkout', 'sunshine-photo-cart' ),
			'conditions'  => array(
				array(
					'compare' => '==',
					'value'   => 'none',
					'field'   => $this->id . '_fee',
					'action'  => 'hide',
				),
			),
		);
		$fields[42] = array(
			'name'       => __( 'Fee Amount', 'sunshine-photo-cart' ),
			'id'         => $this->id . '_fee_amount',
			'type'       => 'number',
			'step'       => '.01',
			'conditions' => array(
				array(
					'compare' => '==',
					'value'   => 'none',
					'field'   => $this->id . '_fee',
					'action'  => 'hide',
				),
			),
		);
		return $fields;
	}

	public function create_order_status( $status, $order ) {
		return $status;
	}

	public function options( $options ) {
		return $options;
	}

	public function get_option( $key ) {
		return SPC()->get_option( $this->id . '_' . $key );
	}

	public function update_option( $key, $value ) {
		return SPC()->update_option( $this->id . '_' . $key, $value );
	}

	public function set_id( $id ) {
		$this->id = sanitize_key( $id );
	}

	public function get_id() {
		return $this->id;
	}

	public function set_name( $name ) {
		$this->name = sanitize_text_field( $name );
	}

	public function get_name() {
		$custom_name = $this->get_option( 'name' );
		if ( ! empty( $custom_name ) ) {
			return $custom_name;
		}
		return $this->name;
	}

	public function set_description( $description ) {
		$this->description = esc_html( $description );
	}

	public function get_description() {
		$custom_description = $this->get_option( 'description' );
		if ( ! empty( $custom_description ) ) {
			return $custom_description;
		}
		return $this->description;
	}

	public function is_active() {
		$active = $this->get_option( 'active' );
		if ( ! empty( $active ) ) {
			return true;
		}
		return false;
	}

	private function get_mode() {
		return $this->get_option( 'mode' );
	}

	public function get_mode_value() {
		return ( $this->get_mode() == 'live' ) ? 'live' : 'test';
	}

	public function is_allowed() {
		return $this->is_active();
	}

	public function can_be_enabled() {
		return $this->can_be_enabled;
	}

	public function needs_billing_address() {
		return $this->needs_billing_address;
	}

	public function get_transaction_id( $order ) {
		return false;
	}

	public function get_transaction_url( $order ) {
		return false;
	}

	public function mode( $mode, $order ) {
		return $mode;
	}

	public function get_fields() {
		return false;
	}

	public function get_submit_label() {
		/* translators: %s is the order total amount formatted as price */
		return sprintf( __( 'Submit Order & Pay %s', 'sunshine-photo-cart' ), '<span class="sunshine-total">' . SPC()->cart->get_total_formatted() . '</span>' );
	}

	public function get_fee() {
		$fee      = array();
		$fee_type = $this->get_option( 'fee' );
		if ( $fee_type && $fee_type != 'none' ) {
			$fee_amount = $this->get_option( 'fee_amount' );
			if ( ! empty( $fee_amount ) ) {
				if ( $fee_type == 'percent' ) {
					$cart_total = SPC()->cart->get_total( array( 'fees' ) );
					$amount     = ( $fee_amount / 100 ) * $cart_total;
				} elseif ( $fee_type == 'amount' ) {
					$amount = $fee_amount;
				}
				$name = $this->get_option( 'fee_name' );
				if ( empty( $name ) ) {
					/* translators: %s is the payment method name */
					$name = sprintf( __( '%s fee', 'sunshine-photo-cart' ), $this->get_name() );
				}
				$fee = array(
					'amount' => $amount,
					'name'   => $name,
				);
			}
		}
		return $fee;
	}

}
