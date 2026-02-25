<?php if ( ! isset( $_GET['page'] ) || ( $_GET['page'] != 'sunshine-install' && $_GET['page'] != 'sunshine-update' ) ) { ?>

	<?php
	$expired_addons = apply_filters( 'sunshine_expired_addons', array() );
	if ( ! empty( $expired_addons ) ) {
		echo '<div class="sunshine-header--notice" id="sunshine-header--notice--expired-addons">';
		if ( array_key_exists( 'sunshine-photo-cart-pro', $expired_addons ) ) {
			/* translators: %s is the addon name */
			echo sprintf( esc_html__( 'Your license for %1$s has expired', 'sunshine-photo-cart' ), 'Sunshine Photo Cart Pro' ) . ' <a href="https://www.sunshinephotocart.com/account/licenses/?utm_source=plugin&utm_medium=link&utm_campaign=expired-license" target="_blank" class="button alt">' . esc_html__( 'Renew now', 'sunshine-photo-cart' ) . '</a>';
		} elseif ( array_key_exists( 'sunshine-photo-cart-plus', $expired_addons ) ) {
			/* translators: %s is the addon name */
			echo sprintf( esc_html__( 'Your license for %1$s has expired', 'sunshine-photo-cart' ), 'Sunshine Photo Cart Plus' ) . ' <a href="https://www.sunshinephotocart.com/account/licenses/?utm_source=plugin&utm_medium=link&utm_campaign=expired-license" target="_blank" class="button alt">' . esc_html__( 'Renew now', 'sunshine-photo-cart' ) . '</a>';
		} elseif ( array_key_exists( 'sunshine-photo-cart-basic', $expired_addons ) ) {
			/* translators: %s is the addon name */
			echo sprintf( esc_html__( 'Your license for %1$s has expired', 'sunshine-photo-cart' ), 'Sunshine Photo Cart Basic' ) . ' <a href="https://www.sunshinephotocart.com/account/licenses/?utm_source=plugin&utm_medium=link&utm_campaign=expired-license" target="_blank" class="button alt">' . esc_html__( 'Renew now', 'sunshine-photo-cart' ) . '</a>';
		} else {
			if ( count( $expired_addons ) > 3 ) {
				$expired_addons_string = join( ', ', array_slice( $expired_addons, 0, 3 ) ) . ' (and more) ';
			} else {
				$expired_addons_string = join( ', ', $expired_addons );
			}
			/* translators: %s is the list of expired add-ons */
			echo sprintf( esc_html__( 'Your license for %1$s has expired', 'sunshine-photo-cart' ), esc_html( $expired_addons_string ) ) . ' <a href="https://www.sunshinephotocart.com/account/licenses/?utm_source=plugin&utm_medium=link&utm_campaign=expired-license" target="_blank" class="button alt">' . esc_html__( 'Renew now', 'sunshine-photo-cart' ) . '</a>';
		}
		echo '</div>';
	} else {
		?>

		<?php if ( ! SPC()->get_option( 'address1' ) ) { ?>
			<div class="sunshine-header--notice">
				<strong><?php esc_html_e( 'Setup Guide:', 'sunshine-photo-cart' ); ?></strong> <?php esc_html_e( 'Start configuring your store with your business information...', 'sunshine-photo-cart' ); ?>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine' ) ); ?>" class="button alt">See settings</a>
			</div>
		<?php } elseif ( wp_count_posts( 'sunshine-product' )->publish <= 0 ) { ?>
			<div class="sunshine-header--notice">
				<strong><?php esc_html_e( 'Setup Guide:', 'sunshine-photo-cart' ); ?></strong> <?php esc_html_e( 'Create products and set prices to start selling', 'sunshine-photo-cart' ); ?>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-product' ) ); ?>" class="button alt">Add products</a>
			</div>
		<?php } elseif ( empty( sunshine_get_active_payment_methods() ) ) { ?>
			<div class="sunshine-header--notice">
				<strong><?php esc_html_e( 'Setup Guide:', 'sunshine-photo-cart' ); ?></strong> <?php esc_html_e( 'Configure payment methods to start receiving money', 'sunshine-photo-cart' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine&section=payment_methods' ) ); ?>" class="button alt">Select payment methods</a>
			</div>
		<?php } elseif ( empty( sunshine_get_active_shipping_methods() ) ) { ?>
			<div class="sunshine-header--notice">
				<strong><?php esc_html_e( 'Setup Guide:', 'sunshine-photo-cart' ); ?></strong> <?php esc_html_e( 'Configure shipping methods to get orders to customers', 'sunshine-photo-cart' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine&section=shipping_methods' ) ); ?>" class="button alt">Setup shipping methods</a>
			</div>
		<?php } elseif ( ! SPC()->get_option( 'logo' ) ) { ?>
			<div class="sunshine-header--notice">
				<strong><?php esc_html_e( 'Setup Guide:', 'sunshine-photo-cart' ); ?></strong> <?php esc_html_e( 'Customize the look of Sunshine with your logo and other options', 'sunshine-photo-cart' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine&section=display' ) ); ?>" class="button alt">Configure display options</a>
			</div>
		<?php } elseif ( ! SPC()->is_pro() ) { ?>
			<div class="sunshine-header--notice">
				<?php esc_html_e( 'Unlock more professional level features for Sunshine Photo Cart by upgrading', 'sunshine-photo-cart' ); ?>
				<a href="https://www.sunshinephotocart.com/upgrade/?utm_source=plugin&utm_medium=link&utm_campaign=upgrade" target="_blank" class="button alt"><?php esc_html_e( 'Learn more', 'sunshine-photo-cart' ); ?></a>
			</div>
		<?php } ?>

	<?php } ?>

<?php } ?>

<div id="sunshine-header">
	<a href="https://www.sunshinephotocart.com/?utm_source=plugin&utm_medium=link&utm_campaign=pluginheader" target="_blank" id="sunshine-logo"><img src="<?php echo esc_url( SUNSHINE_PHOTO_CART_URL ); ?>assets/images/logo.svg" alt="Sunshine Photo Cart by WP Sunshine" /></a>

	<?php
	if ( ! empty( $header_links ) ) {
		echo '<div id="sunshine-header--links">';
		foreach ( $header_links as $key => $link ) {
			echo '<a href="' . esc_url( $link['url'] ) . '?utm_source=plugin&utm_medium=link&utm_campaign=pluginheader" target="_blank" id="sunshine-header--link--' . esc_attr( $key ) . '">' . esc_html( $link['label'] ) . '</a>';
		}
		echo '</div>';
	}
	?>

	<?php if ( ! empty( SPC()->plan ) && SPC()->plan->is_trialing() ) { ?>
		<div id="sunshine-header--trial">
			<?php
			$expiration = SPC()->plan->get_expiration();
			// Expiration is in the format of 2025-07-05 23:59:59, convert to a timestamp and get the difference in days.
			$expiration_timestamp = strtotime( $expiration );
			$days_remaining       = floor( ( $expiration_timestamp - time() ) / ( 60 * 60 * 24 ) );
			?>
			Trial expires in <?php echo esc_html( $days_remaining ); ?> days
		</div>
	<?php } ?>

	<?php if ( count( $tabs ) > 1 ) { ?>
	<nav id="sunshine-options--menu">
		<ul>
			<?php foreach ( $tabs as $key => $label ) { ?>
				<li 
				<?php
				if ( $_GET['tab'] == $key ) {
					?>
					class="sunshine-options--active"<?php } ?>><a href="<?php echo esc_url( admin_url( 'options-general.php?page=sunshine&tab=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a></li>
			<?php } ?>
		</ul>
	</nav>
	<?php } ?>

</div>
