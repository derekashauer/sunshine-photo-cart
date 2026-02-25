<?php
class SPC_Emails {

	private $emails = array();

	function __construct() {
		$this->init();
	}

	public function init() {

		$methods_to_load = array(
			'SPC_Email_Customer_Receipt',
			'SPC_Email_Admin_Receipt',
			'SPC_Email_Order_Status',
			'SPC_Email_Order_Comment',
			'SPC_Email_Admin_Favorites',
			'SPC_Email_Custom_Favorites',
			'SPC_Email_Summary',
			'SPC_Email_Image_Comment',
			'SPC_Email_Signup',
			'SPC_Email_Admin_Signup',
			'SPC_Email_Reset_Password',
		);

		$methods_to_load = apply_filters( 'sunshine_emails', $methods_to_load );

		foreach ( $methods_to_load as $method_class ) {

			if ( is_string( $method_class ) && class_exists( $method_class ) ) {

				$method = new $method_class();

				if ( ! is_a( $method, 'SPC_Email' ) ) {
					continue;
				}

				$this->emails[ $method->id ] = $method;

			}
		}

	}

	public function get_emails() {
		return $this->emails;
	}

	function get_email_by_id( $id ) {
		if ( array_key_exists( $id, $this->emails ) ) {
			return $this->emails[ $id ];
		}
		return false;
	}

}
