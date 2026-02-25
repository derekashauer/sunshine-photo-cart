<?php
class SPC_Email_Signup extends SPC_Email {

	function init() {

		$this->id          = 'signup';
		$this->class       = get_class( $this );
		$this->name        = __( 'Sign Up', 'sunshine-photo-cart' );
		$this->description = __( 'Email sent to customer after account sign up', 'sunshine-photo-cart' );
		/* translators: %s is the site name */
		$this->subject = sprintf( __( 'Your new account on %s', 'sunshine-photo-cart' ), '[sitename]' );

		$this->add_search_replace(
			array(
				'first_name' => '',
				'last_name'  => '',
			)
		);

		add_action( 'sunshine_after_signup', array( $this, 'trigger' ), 10, 3 );

	}

	public function trigger( $customer, $email, $set_password_notice = false ) {

		$this->set_template( $this->id );
		$this->set_subject( $this->get_subject() );

		$this->add_recipient( $customer->get_email() );

		$reset_password_url = '';
		if ( $set_password_notice ) {
			$key                = get_password_reset_key( $customer );
			$reset_password_url = sunshine_get_account_endpoint_url( 'reset-password' );
			$reset_password_url = add_query_arg(
				array(
					'key'   => $key,
					'login' => $customer->user_login,
				),
				$reset_password_url
			);
		}

		$args = array(
			'customer'           => $customer,
			'email'              => $email,
			'reset_password_url' => $reset_password_url,
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
