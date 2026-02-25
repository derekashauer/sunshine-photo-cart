<form method="post" action="" class="sunshine--form--fields" id="sunshine--password">
	<?php wp_nonce_field( 'sunshine_password', 'sunshine_password_nonce' ); ?>
	<div class="sunshine--form--field">
		<label for="sunshine--password--field"><?php esc_html_e( 'Password', 'sunshine-photo-cart' ); ?></label>
		<input type="password" name="sunshine_password" id="sunshine--password--field" required="required" />
	</div>
	<div class="sunshine--form--field sunshine--form--field-submit">
		<button type="submit" class="button sunshine--button"><?php esc_html_e( 'Submit', 'sunshine-photo-cart' ); ?></button>
	</div>
</form>
