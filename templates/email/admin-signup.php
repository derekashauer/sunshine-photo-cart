<?php
if ( ! empty( $message ) ) {
	echo '<div id="custom-message">' . wp_kses_post( wpautop( $message ) ) . '</div>';
}
?>
<p><?php esc_html_e( 'A new customer has signed up in Sunshine Photo Cart', 'sunshine-photo-cart' ); ?>: <?php echo esc_html( $customer->get_email() ); ?></p>
<p>
	<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-customers&customer=' . $customer->get_id() ) ); ?>" class="button">
		<?php esc_html_e( 'View Customer', 'sunshine-photo-cart' ); ?>
	</a>
</p>
