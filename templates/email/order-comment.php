<?php /* translators: %s is the order name */ ?>
<h2><?php echo esc_html( sprintf( __( 'A comment on %s', 'sunshine-photo-cart' ), $order->get_name() ) ); ?></h2>

<?php
if ( ! empty( $message ) ) {
	echo '<div id="custom-message">' . wp_kses_post( wpautop( $message ) ) . '</div>';
}
?>

<?php
if ( ! empty( $comment ) ) {
	echo '<div id="order-comment">' . wp_kses_post( wpautop( $comment ) ) . '</div>';
}
?>

<div id="order-actions">
	<a href="<?php echo esc_url( $order->get_permalink() ); ?>" class="button"><?php esc_html_e( 'View order', 'sunshine-photo-cart' ); ?></a> <?php esc_html_e( 'or reply to this email', 'sunshine-photo-cart' ); ?></a>
</div>
