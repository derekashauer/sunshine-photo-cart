<div id="sunshine--guest-favorites-modal">

	<div id="sunshine--guest-favorites-modal--signup">
		<div id="sunshine--guest-favorites-modal--header">
			<?php echo wp_kses_post( __( 'Create an account to save your favorites across multiple visits', 'sunshine-photo-cart' ) ); ?>
		</div>
		<?php echo sunshine_get_template_html( 'account/signup', array( 'redirect' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<p><a href="#" id="sunshine--guest-favorites-modal--toggle-login" class="sunshine--button-alt"><?php esc_html_e( 'I already have an account', 'sunshine-photo-cart' ); ?></a></p>

	<div id="sunshine--guest-favorites-modal--login" style="display:none;">
		<?php echo sunshine_get_template_html( 'account/login', array( 'redirect' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<?php if ( SPC()->get_option( 'enable_guest_favorites' ) ) { ?>
		<p><a href="#" id="sunshine--guest-favorites-modal--continue-guest" class="sunshine--button-alt"><?php esc_html_e( 'Continue as guest', 'sunshine-photo-cart' ); ?></a></p>
	<?php } ?>

	<div id="sunshine--guest-favorites-modal--notice" style="display:none;"></div>

</div>
