<div id="sunshine--account--login-form">

	<?php if ( ! empty( $message ) ) { ?>
		<div id="sunshine--account--login-signup--header">
			<?php echo esc_html( $message ); ?>
		</div>
	<?php } ?>

	<div class="sunshine--account--login--title sunshine--modal--title"><?php esc_html_e( 'Login', 'sunshine-photo-cart' ); ?></div>

	<form method="post" action="" class="sunshine--form--fields">
		<?php wp_nonce_field( 'sunshine_login', 'sunshine_login' ); ?>
		<div class="sunshine--form--field">
			<label for="sunshine-login-email"><?php esc_html_e( 'E-mail address', 'sunshine-photo-cart' ); ?></label>
			<input type="email" name="sunshine_login_email" id="sunshine-login-email" required="required" />
		</div>
		<div class="sunshine--form--field">
			<label for="sunshine-login-password"><?php esc_html_e( 'Password', 'sunshine-photo-cart' ); ?></label>
			<input type="password" name="sunshine_login_password" id="sunshine-login-password" required="required" />
		</div>
		<div class="sunshine--form--field sunshine--furl" aria-hidden="true">
			<label for="sunshine-login-website"><?php esc_html_e( 'Website', 'sunshine-photo-cart' ); ?></label>
			<input type="text" name="sunshine_login_website" id="sunshine-login-website" autocomplete="off" tabindex="-1" />
		</div>
		<?php do_action( 'sunshine_login_form_before_submit' ); ?>
		<div class="sunshine--form--field sunshine--form--field-submit">
			<button type="submit" class="button sunshine--button"><?php esc_html_e( 'Login', 'sunshine-photo-cart' ); ?></button>
			<div class="sunshine--form--field--desc sunshine--account--reset-password-toggle"><a href="#password" onclick="jQuery( '#sunshine--account--login-form, #sunshine--account--reset-password-form' ).toggle(); return false;"><?php esc_html_e( 'Lost password?', 'sunshine-photo-cart' ); ?></a></div>
		</div>
		<?php if ( ! empty( $_GET['redirect'] ) ) { ?>
			<input type="hidden" name="redirect" value="<?php echo esc_url( $_GET['redirect'] ); ?>" />
		<?php } ?>
	</form>

</div>

<div id="sunshine--account--reset-password-form" style="display: none;">
	<div class="sunshine--account--login--title sunshine--modal--title"><?php esc_html_e( 'Reset Password', 'sunshine-photo-cart' ); ?></div>
	<form method="post" action="" class="sunshine--form--fields">
		<?php wp_nonce_field( 'sunshine_reset_password_nonce', 'sunshine_reset_password_nonce' ); ?>
		<div class="sunshine--form--field">
			<label for="sunshine-reset-password-email"><?php esc_html_e( 'E-mail address', 'sunshine-photo-cart' ); ?></label>
			<input type="email" name="sunshine_reset_password_email" id="sunshine-reset-password-email" required="required" autocomplete="email" />
			<div class="sunshine--form--field--desc"><?php esc_html_e( 'An email will be sent to this address with instructions on how to reset your password', 'sunshine-photo-cart' ); ?></div>
		</div>
		<div class="sunshine--form--field sunshine--furl" aria-hidden="true">
			<label for="sunshine-reset-website"><?php esc_html_e( 'Website', 'sunshine-photo-cart' ); ?></label>
			<input type="text" name="sunshine_reset_website" id="sunshine-reset-website" autocomplete="off" tabindex="-1" />
		</div>
		<?php do_action( 'sunshine_lost_password_form_before_submit' ); ?>
		<div class="sunshine--form--field sunshine--form--field-submit">
			<button type="submit" class="button sunshine--button"><?php esc_html_e( 'Get New Password', 'sunshine-photo-cart' ); ?></button>
			<div class="sunshine--form--field--desc sunshine--account--reset-password-toggle"><a href="#password" onclick="jQuery( '#sunshine--account--login-form, #sunshine--account--reset-password-form' ).toggle(); return false;"><?php esc_html_e( 'Back to login', 'sunshine-photo-cart' ); ?></a></div>
		</div>
	</form>
</div>
