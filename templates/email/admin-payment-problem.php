<h2><a href="<?php echo esc_url( $order->get_admin_permalink() ); ?>"><?php echo esc_html( $order->get_name() ); ?></a></h2>

<p><?php echo esc_html( $reason ); ?></p>

<?php
if ( ! empty( $message ) ) {
	echo '<div id="custom-message">' . wp_kses_post( wpautop( $message ) ) . '</div>';
}
?>

<table>
	<tr>
		<th><?php esc_html_e( 'Customer', 'sunshine-photo-cart' ); ?></th>
		<td><?php echo esc_html( trim( $order->get_customer_name() ) ); ?> (<?php echo esc_html( $order->get_email() ); ?>)</td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Order total', 'sunshine-photo-cart' ); ?></th>
		<td><?php echo wp_kses_post( sunshine_price( $order->get_total(), false, $order->get_currency() ) ); ?> <?php echo esc_html( $order->get_currency() ); ?></td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Payment method', 'sunshine-photo-cart' ); ?></th>
		<td><?php echo esc_html( $payment_method_name ); ?></td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Order status', 'sunshine-photo-cart' ); ?></th>
		<td><?php echo esc_html( $order->get_status_name() ); ?></td>
	</tr>
</table>

<p>
	<?php
	if ( $payment_method_name ) {
		/* translators: %s is the payment method name, like Stripe or PayPal */
		echo esc_html( sprintf( __( 'Check this payment in %s\'s dashboard before refunding or marking the order paid.', 'sunshine-photo-cart' ), $payment_method_name ) );
	} else {
		esc_html_e( 'Check this payment in your payment processor\'s dashboard before refunding or marking the order paid.', 'sunshine-photo-cart' );
	}
	?>
</p>

<p>
	<a href="<?php echo esc_url( $order->get_admin_permalink() ); ?>" class="button"><?php esc_html_e( 'View Order', 'sunshine-photo-cart' ); ?></a>
</p>
