<?php
if ( ! empty( $message ) ) {
	echo '<div id="custom-message">' . wp_kses_post( wpautop( $message ) ) . '</div>';
}
?>

<p><?php esc_html_e( 'Please click the button below to set a new password. If you did not make this request, you can safely ignore and delete this email', 'sunshine-photo-cart' ); ?></p>
<p><a href="<?php echo esc_url( $reset_password_url ); ?>" class="button"><?php esc_html_e( 'Click to reset password', 'sunshine-photo-cart' ); ?></a></p>
