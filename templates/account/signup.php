<div class="sunshine--account--login--title sunshine--modal--title"><?php esc_html_e( 'Sign Up', 'sunshine-photo-cart' ); ?></div>
<form method="post" action="" id="sunshine--account--signup-form" class="sunshine--form--fields">
	<?php wp_nonce_field( 'sunshine_signup', 'sunshine_signup' ); ?>
	<div class="sunshine--form--field">
		<label for="sunshine-signup-email"><?php esc_html_e( 'E-mail address', 'sunshine-photo-cart' ); ?></label>
		<input type="email" name="sunshine_signup_email" id="sunshine-signup-email" required="required" autocomplete="email" />
	</div>
	<div class="sunshine--form--field">
		<label for="sunshine-signup-password"><?php esc_html_e( 'Password', 'sunshine-photo-cart' ); ?> <?php
		if ( SPC()->get_option( 'signup_password_optional' ) ) {
			?>
			<span class="sunshine--form--field--desc"><?php esc_html_e( 'Optional', 'sunshine-photo-cart' ); ?></span><?php } ?></label>
		<input type="password" name="sunshine_signup_password" id="sunshine-signup-password" autocomplete="new-password" 
		<?php
		if ( ! SPC()->get_option( 'signup_password_optional' ) ) {
			?>
			required="required"<?php } ?> />
	</div>
	<div class="sunshine--form--field sunshine--form--field-half">
		<label for="sunshine-signup-first-name"><?php esc_html_e( 'First Name', 'sunshine-photo-cart' ); ?> <?php
		if ( SPC()->get_option( 'signup_name_optional' ) ) {
			?>
			<span class="sunshine--form--field--desc"><?php esc_html_e( 'Optional', 'sunshine-photo-cart' ); ?></span><?php } ?></label>
		<input type="text" name="sunshine_signup_first_name" id="sunshine-signup-first-name" autocomplete="given-name" />
	</div>
	<div class="sunshine--form--field sunshine--form--field-half">
		<label for="sunshine-signup-last-name"><?php esc_html_e( 'Last Name', 'sunshine-photo-cart' ); ?> <?php
		if ( SPC()->get_option( 'signup_name_optional' ) ) {
			?>
			<span class="sunshine--form--field--desc"><?php esc_html_e( 'Optional', 'sunshine-photo-cart' ); ?></span><?php } ?></label>
		<input type="text" name="sunshine_signup_last_name" id="sunshine-signup-last-name" autocomplete="family-name" 
		<?php
		if ( ! SPC()->get_option( 'signup_name_optional' ) ) {
			?>
			required="required"<?php } ?> />
	</div>
	<?php do_action( 'sunshine_signup_fields' ); ?>
	<div class="sunshine--form--field sunshine--furl" aria-hidden="true">
		<label for="sunshine-signup-website"><?php esc_html_e( 'Website', 'sunshine-photo-cart' ); ?></label>
		<input type="text" name="sunshine_signup_website" id="sunshine-signup-website" autocomplete="off" tabindex="-1" />
	</div>
	<?php do_action( 'sunshine_signup_form_before_submit' ); ?>
	<div class="sunshine--form--field sunshine--form--field-submit">
		<button type="submit" class="sunshine--button"><?php esc_html_e( 'Create Account', 'sunshine-photo-cart' ); ?></button>
	</div>
	<?php if ( ! empty( $_GET['redirect'] ) ) { ?>
		<input type="hidden" name="redirect" value="<?php echo esc_url( $_GET['redirect'] ); ?>" />
	<?php } ?>
</form>
