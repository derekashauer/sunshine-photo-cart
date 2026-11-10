<?php /* translators: %s is the order name */ ?>
<h2><?php echo esc_html( sprintf( __( 'Order status for %s has changed', 'sunshine-photo-cart' ), $order->get_name() ) ); ?></h2>

<p id="order-status"><?php echo esc_html( $order->get_status_name() ); ?>: <?php echo esc_html( $order->get_status_description() ); ?></p>

<?php
if ( ! empty( $message ) ) {
	echo '<div id="custom-message">' . wp_kses_post( wpautop( $message ) ) . '</div>';
}
?>

<?php do_action( 'sunshine_email_order_status', $order ); ?>

<div id="order-actions">
	<a href="<?php echo esc_url( $order->get_permalink() ); ?>" class="button"><?php esc_html_e( 'View order', 'sunshine-photo-cart' ); ?></a>
	<a href="<?php echo esc_url( $order->get_invoice_permalink() ); ?>" class="button"><?php esc_html_e( 'View invoice', 'sunshine-photo-cart' ); ?></a>
	<?php do_action( 'sunshine_email_order_status_actions', $order ); ?>
</div>

<?php sunshine_get_template( 'email/order-details', array( 'order' => $order ) ); ?>
