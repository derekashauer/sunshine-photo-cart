<?php
class SPC_Delivery_Method {

	protected $id;
	protected $active;
	protected $name;
	protected $description;
	protected $needs_shipping = true;
	protected $class;
	protected $can_be_enabled = false;

	public function __construct() {
		$this->init();
		add_filter( 'sunshine_delivery_methods', array( $this, 'register' ) );
		add_filter( 'sunshine_options_shipping', array( $this, 'options' ) );
	}

	public function init() { }

	public function register( $delivery_methods = array() ) {
		if ( ! empty( $this->id ) && $this->is_enabled() ) {
			$delivery_methods[ $this->id ] = array(
				'id'          => $this->id,
				'name'        => $this->name,
				'description' => $this->description,
				'class'       => $this->class,
			);
		}
		return $delivery_methods;
	}

	private function set_id( $id ) {
		$this->id = sanitize_key( $id );
	}

	public function get_id() {
		return $this->id;
	}

	public function set_name( $name ) {
		$this->name = sanitize_text_field( $name );
	}

	public function get_name() {
		return $this->name;
	}

	public function get_display_name() {
		$name         = $this->name;
		$custom_label = SPC()->get_option( $this->id . '_label' );
		if ( ! empty( $custom_label ) ) {
			$name = $custom_label;
		}
		return apply_filters( 'sunshine_delivery_method_' . $this->id . '_name', $name );
	}

	public function set_description( $description ) {
		$this->description = esc_html( $description );
	}

	public function get_description() {
		$description = SPC()->get_option( $this->id . '_description' );
		return apply_filters( 'sunshine_delivery_method_' . $this->id . '_description', $description );
	}

	public function needs_shipping() {
		return $this->needs_shipping;
	}

	public function options( $options ) {
		if ( $this->can_be_enabled ) {
			$options[] = array(
				'id'    => $this->id . '_enabled',
				/* translators: %s is the delivery method name */
				'name'  => sprintf( __( 'Enable %s', 'sunshine-photo-cart' ), $this->get_name() ),
				'type'  => 'checkbox',
				'class' => ( isset( $_GET['instance_id'] ) ) ? 'hidden' : '',
			);
			$options[] = array(
				'id'         => $this->id . '_label',
				/* translators: %s is the delivery method name */
				'name'       => sprintf( __( '%s Label', 'sunshine-photo-cart' ), $this->get_name() ),
				'type'       => 'text',
				'class'      => ( isset( $_GET['instance_id'] ) ) ? 'hidden' : '',
				'conditions' => array(
					array(
						'compare' => '==',
						'value'   => '1',
						'field'   => $this->id . '_enabled',
						'action'  => 'show',
					),
				),
			);
			$options[] = array(
				'id'         => $this->id . '_description',
				/* translators: %s is the delivery method name */
				'name'       => sprintf( __( '%s Description', 'sunshine-photo-cart' ), $this->get_name() ),
				'type'       => 'textarea',
				'class'      => ( isset( $_GET['instance_id'] ) ) ? 'hidden' : '',
				'conditions' => array(
					array(
						'compare' => '==',
						'value'   => '1',
						'field'   => $this->id . '_enabled',
						'action'  => 'show',
					),
				),
			);
		}
		return $options;
	}

	public function is_enabled() {
		return SPC()->get_option( $this->id . '_enabled' );
	}

}
