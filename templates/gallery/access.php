<form method="post" action="<?php echo esc_url( $gallery->get_permalink() ); ?>" class="sunshine--form--fields" id="sunshine--gallery--access">
	<?php wp_nonce_field( 'sunshine_gallery_access', 'sunshine_gallery_access' ); ?>
	<input type="hidden" name="sunshine_gallery_id" value="<?php echo esc_attr( $gallery->get_id() ); ?>" />
	<?php
	if ( $password ) {
		echo sunshine_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'sunshine_gallery_password',
			array(
				'type'        => 'password',
				'name'        => __( 'Enter Gallery Access Code', 'sunshine-photo-cart' ),
				'required'    => true,
				'description' => esc_html( $gallery->get_password_hint() ),
			),
		);
	}
	?>

	<?php
	if ( $email ) {
		echo sunshine_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'sunshine_gallery_email',
			array(
				'type'         => 'email',
				'name'         => __( 'Email', 'sunshine-photo-cart' ),
				'required'     => true,
				'autocomplete' => 'email',
			),
		);
		do_action( 'sunshine_gallery_email_after', $gallery );
	}
	?>

	<div class="sunshine--form--field sunshine--form--field-submit">
		<button type="submit" class="sunshine--button"><?php esc_html_e( 'Submit', 'sunshine-photo-cart' ); ?></button>
	</div>

	<?php if ( ! empty( $redirect_to ) ) { ?>
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>" />
	<?php } ?>

</form>
