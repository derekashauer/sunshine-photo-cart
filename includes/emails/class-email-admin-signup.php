<?php
class SPC_Email_Admin_Signup extends SPC_Email {

	function init() {

		$this->id          = 'admin-signup';
		$this->class       = get_class( $this );
		$this->name        = __( 'Sign Up (Admin)', 'sunshine-photo-cart' );
		$this->description = __( 'Sign up notification with customer information', 'sunshine-photo-cart' );
		/* translators: %s is the site name */
		$this->subject           = sprintf( __( 'Customer signup at %s', 'sunshine-photo-cart' ), '[sitename]' );
		$this->custom_recipients = true;

		add_action( 'sunshine_after_signup', array( $this, 'trigger' ) );

	}

	public function trigger( $customer ) {

		if ( is_user_logged_in() && current_user_can( 'sunshine_manage_options' ) ) {
			// Do not need to send when already doing something as an admin.
			return;
		}

		$this->set_template( $this->id );
		$this->set_subject( $this->get_subject() );

		$args = array(
			'customer' => $customer,
		);
		$this->add_args( $args );

		$this->send();

	}

}
