<div id="sunshine--account--login-signup">

	<?php if ( ! empty( $message ) ) { ?>
		<div id="sunshine--account--login-signup--header">
			<?php echo wp_kses_post( $message ); ?>
		</div>
	<?php } ?>

	<div id="sunshine--account--login">
		<?php echo sunshine_get_template_html( 'account/login', array( 'redirect' => ( isset( $redirect ) ) ? $redirect : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<?php if ( ! SPC()->get_option( 'disable_signup', false ) ) { ?>
	<div id="sunshine--account--signup">
		<?php echo sunshine_get_template_html( 'account/signup', array( 'redirect' => ( isset( $redirect ) ) ? $redirect : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<?php } ?>

</div>
