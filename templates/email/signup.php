<?php /* translators: %s is the site name */ ?>
<h2><?php echo esc_html( sprintf( __( 'An account has been created for you at %s', 'sunshine-photo-cart' ), get_bloginfo( 'name' ) ) ); ?></h2>

<?php
if ( ! empty( $message ) ) {
	echo '<div id="custom-message">' . wp_kses_post( wpautop( $message ) ) . '</div>';
}
?>
<p><strong><?php esc_html_e( 'Email', 'sunshine-photo-cart' ); ?>:</strong> <?php echo esc_html( $email ); ?></p>

<?php if ( $reset_password_url ) { ?>
	<p><a href="<?php echo esc_url( $reset_password_url ); ?>" class="button"><?php esc_html_e( 'Set your password', 'sunshine-photo-cart' ); ?>
<?php } else { ?>
	<p><a href="<?php echo esc_url( sunshine_get_page_url( 'account' ) ); ?>" class="button"><?php esc_html_e( 'Access account', 'sunshine-photo-cart' ); ?></a></p>
<?php } ?>
