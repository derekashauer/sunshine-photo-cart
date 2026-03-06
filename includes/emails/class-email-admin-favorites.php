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

		// Generate a favorites URL for the admin to view.
		if ( SPC()->customer->is_guest() ) {
			$share_key     = sunshine_create_guest_favorites_share_key();
			$favorites_url = add_query_arg( 'favorites_key', $share_key, sunshine_get_page_permalink( 'favorites' ) );
		} else {
			$favorites_url = admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-customers&customer=' . SPC()->customer->ID );
		}

		$args = array(
			'favorites'     => SPC()->customer->get_favorites(),
			'note'          => wpautop( sanitize_textarea_field( $post_data['note'] ) ),
			'favorites_url' => $favorites_url,
		);
		$this->add_args( $args );

		// Use guest-provided info if available.
		if ( ! empty( $post_data['guest_name'] ) ) {
			$name_parts = explode( ' ', $post_data['guest_name'], 2 );
			$first_name = $name_parts[0];
			$last_name  = isset( $name_parts[1] ) ? $name_parts[1] : '';
		} else {
			$first_name = SPC()->customer->get_first_name();
			$last_name  = SPC()->customer->get_last_name();
		}
		$email = ! empty( $post_data['guest_email'] ) ? $post_data['guest_email'] : SPC()->customer->get_email();

		$search_replace = array(
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
		);
		$this->add_search_replace( $search_replace );

		// Send email
		$result = $this->send();

	}

}
