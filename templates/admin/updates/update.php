<div id="sunshine-install">
	<p><img src="<?php echo esc_url( SUNSHINE_PHOTO_CART_URL ); ?>assets/images/logo.svg" alt="Sunshine Photo Cart" width="300" /></p>

	<h1>Thank you for updating Sunshine Photo Cart!</h1>
	<p class="sunshine-install--tagline">Sunshine Photo Cart <?php echo esc_html( SUNSHINE_PHOTO_CART_VERSION ); ?> is the most comprehensive client proofing and photo cart plugin for WordPress. I hope you enjoy greater selling success!</p>
	<table style="margin: 20px auto; text-align: left; ">
		<tr>
			<td><img src="<?php echo esc_url( get_avatar_url( 'derek@ashwebstudio.com' ) ); ?>" width="75" style="border-radius: 50%; margin-right: 15px;" alt="Derek Ashauer" /></td>
			<td style="font-size: 14px;">
				<strong style="font-size: 18px;">Derek Ashauer</strong><br />
				Sunshine Photo Cart Lead Developer & Support
			</td>
		</tr>
	</table>

	<?php
	if ( ! empty( $update_actions ) ) {
		foreach ( $update_actions as $version ) {
			do_action( 'sunshine_update_' . $version );
		}
	}
	?>

	<div class="sunshine-install--step">
		<?php
		$readme        = file_get_contents( SUNSHINE_PHOTO_CART_PATH . '/readme.txt' );
		$readme_pieces = explode( '== Changelog ==', $readme );
		$changelog     = nl2br( htmlspecialchars( trim( $readme_pieces[1] ) ) );
		$changelog     = str_replace( array( ' =', '= ' ), array( '</h3>', '<h3>' ), $changelog );
		$nth           = nth_strpos( $changelog, '<h3>', 7, true );
		if ( $nth !== false ) {
			$changelog = substr( $changelog, 0, $nth );
		}
		?>
		<h2><?php esc_html_e( 'Recent Improvements', 'sunshine-photo-cart' ); ?></h2>
		<div class="changelog"><?php echo wp_kses_post( $changelog ); ?></div>
		<p><a href="https://wordpress.org/plugins/sunshine-photo-cart/#developers" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See the full Changelog', 'sunshine-photo-cart' ); ?></a></p>
	</div>

</div>
