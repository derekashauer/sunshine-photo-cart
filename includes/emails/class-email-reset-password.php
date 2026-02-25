<?php
class SPC_Email_Reset_Password extends SPC_Email {

	function init() {

		$this->id          = 'reset-password';
		$this->class       = get_class( $this );
		$this->name        = __( 'Reset Password', 'sunshine-photo-cart' );
		$this->description = __( 'Email sent to customer when resetting password', 'sunshine-photo-cart' );
		/* translators: %s is the site name */
		$this->subject = sprintf( __( 'Password reset request at %s', 'sunshine-photo-cart' ), '[sitename]' );

		$this->add_search_replace(
			array(
				'first_name' => '',
				'last_name'  => '',
			)
		);

		add_action( 'sunshine_reset_password', array( $this, 'trigger' ) );

	}

	public function trigger( $user ) {

		$customer = new SPC_Customer( $user->ID );

		$this->set_template( $this->id );
		$this->set_subject( $this->get_subject() );

		$this->add_recipient( $customer->get_email() );

		$key = get_password_reset_key( $customer );
		$url = sunshine_get_account_endpoint_url( 'reset-password' );
		$url = add_query_arg(
			array(
				'key'   => $key,
				'login' => $customer->user_login,
			),
			$url
		);

		$args = array(
			'customer'           => $customer,
			'reset_password_url' => $url,
		);
		$this->add_args( $args );

		$search_replace = array(
			'first_name' => $customer->get_first_name(),
			'last_name'  => $customer->get_last_name(),
		);
		$this->add_search_replace( $search_replace );

		// Send email
		$result = $this->send();

	}

}
