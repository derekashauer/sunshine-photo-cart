<?php
class SPC_Email_Custom_Favorites extends SPC_Email {

	function init() {

		$this->id          = 'custom-favorites';
		$this->class       = get_class( $this );
		$this->name        = __( 'Favorites (Custom Recipient)', 'sunshine-photo-cart' );
		$this->description = __( 'Favorites notification sent by customers to a custom recipient', 'sunshine-photo-cart' );
		/* translators: %1$s is the customer first name, %2$s is the customer last name, %3$s is the site name */
		$this->subject           = sprintf( __( '%1$s %2$s has sent you favorited images on %3$s', 'sunshine-photo-cart' ), '[first_name]', '[last_name]', '[sitename]' );
		$this->custom_recipients = false;

		$this->add_search_replace(
			array(
				'first_name'    => '',
				'last_name'     => '',
				'favorites_url' => '',
			)
		);

		add_action( 'sunshine_favorites_share', array( $this, 'trigger' ) );

	}

	public function trigger( $post_data ) {

		if ( empty( $post_data['recipients'] ) || ! in_array( 'custom', $post_data['recipients'] ) || empty( $post_data['email'] ) ) {
			return;
		}

		$this->set_template( $this->id );
		$this->set_subject( $this->get_subject() );

		// Generate favorites URL — use transient key for guests, user meta key for logged-in.
		if ( SPC()->customer->is_guest() ) {
			$share_key = sunshine_create_guest_favorites_share_key();
		} else {
			$share_key = SPC()->customer->get_favorite_key();
		}

		$args = array(
			'favorites'     => SPC()->customer->get_favorites(),
			'note'          => wpautop( sanitize_textarea_field( $post_data['note'] ) ),
			'favorites_url' => add_query_arg( 'favorites_key', $share_key, sunshine_get_page_permalink( 'favorites' ) ),
		);
		$this->add_args( $args );

		// Use guest-provided name if available, otherwise use customer data.
		if ( ! empty( $post_data['guest_name'] ) ) {
			$name_parts = explode( ' ', $post_data['guest_name'], 2 );
			$first_name = $name_parts[0];
			$last_name  = isset( $name_parts[1] ) ? $name_parts[1] : '';
		} else {
			$first_name = SPC()->customer->get_first_name();
			$last_name  = SPC()->customer->get_last_name();
		}

		$search_replace = array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
		);
		$this->add_search_replace( $search_replace );

		// Add email(s) and send
		$emails = explode( ',', $post_data['email'] );
		foreach ( $emails as $email ) {
			$this->add_recipient( trim( $email ) );
		}
		$result = $this->send();

	}

}
