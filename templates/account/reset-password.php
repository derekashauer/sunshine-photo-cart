<form method="post">
	<?php wp_nonce_field( 'sunshine_password_reset_' . $key, 'sunshine_password_reset' ); ?>
	<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>" />
	<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>" />
	<div class="sunshine--form--field">
		<label for="sunshine-reset-password"><?php esc_html_e( 'New Password', 'sunshine-photo-cart' ); ?></label>
		<input type="password" name="sunshine_new_password" id="sunshine-reset-password" required="required" autocomplete="new-password" />
	</div>
	<div class="sunshine--form--field">
		<label for="sunshine-reset-password-confirm"><?php esc_html_e( 'New Password Confirmation', 'sunshine-photo-cart' ); ?></label>
		<input type="password" name="sunshine_new_password_confirm" id="sunshine-reset-password-confirm" required="required" autocomplete="new-password" />
	</div>
	<div class="sunshine--form--field">
		<button type="submit" class="button sunshine--button"><?php esc_html_e( 'Reset Password', 'sunshine-photo-cart' ); ?></button>
	</div>
</form>
