<?php /* translators: %s is the order name */ ?>
<h2><?php echo esc_html( sprintf( __( '%s has shipped', 'sunshine-photo-cart' ), $order->get_name() ) ); ?></h2>

<?php
if ( ! empty( $message ) ) {
	echo '<div id="custom-message">' . wp_kses_post( wpautop( $message ) ) . '</div>';
}
?>

<?php
// Print lab add-ons show the tracking here, the same way they do in the Order Status Update email.
do_action( 'sunshine_email_order_status', $order );
?>

<div id="order-actions">
	<a href="<?php echo esc_url( $order->get_permalink() ); ?>" class="button"><?php esc_html_e( 'View order', 'sunshine-photo-cart' ); ?></a>
	<a href="<?php echo esc_url( $order->get_invoice_permalink() ); ?>" class="button"><?php esc_html_e( 'View invoice', 'sunshine-photo-cart' ); ?></a>
	<?php do_action( 'sunshine_email_order_status_actions', $order ); ?>
</div>

<?php sunshine_get_template( 'email/order-details', array( 'order' => $order ) ); ?>
