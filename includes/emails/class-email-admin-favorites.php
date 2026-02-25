<?php
class SPC_Email_Admin_Favorites extends SPC_Email {

	function init() {

		$this->id          = 'admin-favorites';
		$this->class       = get_class( $this );
		$this->name        = __( 'Favorites (Admin)', 'sunshine-photo-cart' );
		$this->description = __( 'Favorites notification sent by customers to site admin', 'sunshine-photo-cart' );
		/* translators: %1$s is the customer email address, %2$s is the site name */
		$this->subject           = sprintf( __( 'Favorites submitted by %1$s on %2$s', 'sunshine-photo-cart' ), '[email]', '[sitename]' );
		$this->custom_recipients = true;

		$this->add_search_replace(
			array(
				'email'      => '',
				'first_name' => '',
				'last_name'  => '',
			)
		);

		add_action( 'sunshine_favorites_share', array( $this, 'trigger' ) );

	}

	public function trigger( $post_data ) {

		if ( empty( $post_data['recipients'] ) || ! in_array( 'admin', $post_data['recipients'] ) ) {
			return;
		}

		$this->set_template( $this->id );
		$this->set_subject( $this->get_subject() );

		$args = array(
			'favorites' => SPC()->customer->get_favorites(),
			'note'      => wpautop( sanitize_textarea_field( $post_data['note'] ) ),
		);
		$this->add_args( $args );

		$search_replace = array(
			'email'      => SPC()->customer->get_email(),
			'first_name' => SPC()->customer->get_first_name(),
			'last_name'  => SPC()->customer->get_last_name(),
		);
		$this->add_search_replace( $search_replace );

		// Send email
		$result = $this->send();

	}

}
