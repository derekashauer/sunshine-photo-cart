<h1>Thank you for activating Sunshine Photo Cart</h1>
<p class="sunshine-install--tagline">You are just minutes away from selling images on your own WordPress website!</p>

<form method="post" action="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install&step=default' ) ); ?>">
	<?php echo wp_nonce_field( 'sunshine_install_default', 'sunshine_install_default' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install&step=business' ) ); ?>" class="button-primary large">Get Started <i class="sunshine--icon--right-arrow"></i></a></p>

	<div class="sunshine-install--quote">
		<img src="<?php echo esc_url( SUNSHINE_PHOTO_CART_URL ); ?>/assets/images/nadia.jpg" alt="Nadia Hall" />
		<blockquote>
			"I have seen a huge increase in sales compared to other photo websites."
			<cite>Nadia Hall</cite>
		</blockquote>
	</div>

	<div class="sunshine-install--quote">
		<img src="<?php echo esc_url( SUNSHINE_PHOTO_CART_URL ); ?>/assets/images/kendra.png" alt="Kendra Heller" />
		<blockquote>
			"Sunshine elevated my small photography business and I know it can elevate yours as well."
			<cite>Kendra Heller</cite>
		</blockquote>
	</div>

	<div class="sunshine-install--quote">
		<img src="<?php echo esc_url( SUNSHINE_PHOTO_CART_URL ); ?>/assets/images/lewis.jpg" alt="Lewis Duncan" />
		<blockquote>
			"Our sales have increased this year purely down to the design which is much slicker."
			<cite>Lewis Duncan</cite>
		</blockquote>
	</div>

</form>
