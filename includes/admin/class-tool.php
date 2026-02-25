<?php
class SPC_Tool {

	protected $name;
	protected $key;
	protected $description;
	protected $button_label;

	function __construct( $name, $key, $description = '', $button_label = '' ) {

		$this->name         = $name;
		$this->key          = $key;
		$this->description  = $description;
		$this->button_label = $button_label;

		add_filter( 'sunshine_tools', array( $this, 'register' ) );

	}

	function get_name() {
		return $this->name;
	}

	function get_key() {
		return $this->key;
	}

	function get_description() {
		return $this->description;
	}

	function get_button_label() {
		return $this->button_label;
	}

	function register( $tools ) {
		$tools[ $this->get_key() ] = $this;
		return $tools;
	}

	function pre_process() { }

	function process() {
		// Verify nonce for security
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'sunshine_tool_' . $this->get_key() ) ) {
			wp_die( esc_html__( 'Security check failed', 'sunshine-photo-cart' ) );
		}

		// Verify user capabilities
		if ( ! current_user_can( 'sunshine_manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this tool', 'sunshine-photo-cart' ) );
		}

		// Call the child class's implementation
		$this->do_process();
	}

	protected function do_process() { }

}
