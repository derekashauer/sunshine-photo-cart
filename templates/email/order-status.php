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

<div id="order-cart">
	<h3><?php esc_html_e( 'Order Summary', 'sunshine-photo-cart' ); ?></h3>
	<?php $cart = $order->get_cart(); ?>
	<table>
		<tbody>
		<?php foreach ( $cart as $cart_item ) { ?>
			<tr class="order-item <?php echo esc_attr( $cart_item->classes() ); ?>">
				<td class="order-item--image">
					<?php echo wp_kses_post( $cart_item->get_image_html() ); ?>
				</td>
				<td class="order-item--data">
					<div class="order-item--name"><?php echo wp_kses_post( $cart_item->get_name() ); ?> x <?php echo esc_html( $cart_item->get_qty() ); ?></div>
					<div class="order-item--product-options"><?php echo wp_kses_post( $cart_item->get_options_formatted() ); ?></div>
					<div class="order-item--image-name"><?php echo wp_kses_post( $cart_item->get_image_name() ); ?></div>
					<div class="order-item--comments"><?php echo wp_kses_post( $cart_item->get_comments() ); ?></div>
					<div class="order-item--extra"><?php echo wp_kses_post( $cart_item->get_extra() ); ?></div>
				</td>
				<td class="order-item--total">
					<?php echo wp_kses_post( $cart_item->get_total_formatted() ); ?>
				</td>
			</tr>
		<?php } ?>
		</tbody>
		<tfoot>
			<tr>
				<td colspan="5">
					<table>
						<tr id="order-subtotal">
							<th><?php esc_html_e( 'Subtotal', 'sunshine-photo-cart' ); ?></th>
							<td><?php echo wp_kses_post( $order->get_subtotal_formatted() ); ?></td>
						</tr>
						<?php if ( ! empty( $order->get_shipping() ) ) { ?>
						<tr id="order-shipping">
							<?php /* translators: %s is the shipping method name */ ?>
							<th><?php echo esc_html( sprintf( __( 'Shipping via %s', 'sunshine-photo-cart' ), $order->get_shipping_method_name() ) ); ?></th>
							<td><?php echo wp_kses_post( $order->get_shipping_formatted() ); ?></td>
						</tr>
						<?php } ?>
						<?php if ( ! empty( $order->has_discount() ) ) { ?>
						<tr id="order-discount">
							<th>
								<?php esc_html_e( 'Discounts', 'sunshine-photo-cart' ); ?>
								<?php
								$discounts = $order->get_discounts();
								if ( ! empty( $discounts ) ) {
									echo '(' . esc_html( join( ', ', $order->get_discounts() ) ) . ')';
								}
								?>
							</th>
							<td><?php echo wp_kses_post( $order->get_discount_formatted() ); ?></td>
						</tr>
						<?php } ?>
						<?php if ( $order->get_tax() && $order->get_meta_value( 'display_price' ) !== 'with_tax' ) { ?>
						<tr id="order-tax">
							<th><?php esc_html_e( 'Tax', 'sunshine-photo-cart' ); ?></th>
							<td><?php echo wp_kses_post( $order->get_tax_formatted() ); ?></td>
						</tr>
						<?php } ?>
						<?php if ( $order->get_credits() > 0 ) { ?>
						<tr id="order-credits">
							<th><?php esc_html_e( 'Credits Applied', 'sunshine-photo-cart' ); ?></th>
							<td><?php echo wp_kses_post( $order->get_credits_formatted() ); ?></td>
						</tr>
						<?php } ?>
						<tr id="order-total">
							<th><?php esc_html_e( 'Order Total', 'sunshine-photo-cart' ); ?></th>
							<td>
								<?php echo wp_kses_post( $order->get_total_formatted() ); ?>
								<?php if ( $order->get_tax() && $order->get_meta_value( 'display_price' ) == 'with_tax' ) { ?>
									<?php /* translators: %s is the tax amount */ ?>
									<span class="sunshine--cart--total--tax--explain">(<?php echo wp_kses_post( sprintf( __( 'includes %s tax', 'sunshine-photo-cart' ), $order->get_tax_formatted() ) ); ?>)</span>
								<?php } ?>
							</td>
						</tr>
						<tr id="order-payment-method">
							<th><?php esc_html_e( 'Payment Method', 'sunshine-photo-cart' ); ?></th>
							<td><?php echo esc_html( $order->get_payment_method_name() ); ?></td>
						</tr>
					</table>
				</td>
			</tr>
		</tfoot>
	</table>
</div>

<?php if ( $order->get_customer_notes() ) { ?>
	<div id="order-notes">
		<h3><?php esc_html_e( 'Notes', 'sunshine-photo-cart' ); ?></h3>
		<?php echo wp_kses_post( $order->get_customer_notes() ); ?>
	</div>
<?php } ?>

<?php do_action( 'sunshine_email_order_status_after_order_notes', $order ); ?>

<div id="order-customer">
	<h3><?php esc_html_e( 'Customer Information', 'sunshine-photo-cart' ); ?></h3>
	<p><?php echo esc_html( $order->get_customer_name() ); ?></p>
	<p><a href="mailto:<?php echo esc_url( $order->get_email() ); ?>"><?php echo esc_html( $order->get_email() ); ?></a></p>
	<?php
	if ( $order->get_phone() ) {
		echo '<p>' . esc_html( $order->get_phone() ) . '</p>';
	}
	?>
	<table>
	<tr>
		<?php if ( $order->has_shipping_address() ) { ?>
		<td id="order-shipping" valign="top">
			<h4><?php esc_html_e( 'Shipping', 'sunshine-photo-cart' ); ?></h4>
			<p><?php echo wp_kses_post( $order->get_shipping_address_formatted() ); ?></p>
			<?php do_action( 'sunshine_email_receipt_after_order_shipping', $order ); ?>
		</td>
		<?php } ?>
		<?php if ( $order->has_billing_address() ) { ?>
		<td id="order-billing" valign="top">
			<h4><?php esc_html_e( 'Billing', 'sunshine-photo-cart' ); ?></h4>
			<p><?php echo wp_kses_post( $order->get_billing_address_formatted() ); ?></p>
		</td>
		<?php } ?>
	</tr>
	<?php if ( $order->get_vat() ) { ?>
		<tr>
			<td colspan="2">
				<h4><?php echo ( SPC()->get_option( 'vat_label' ) ) ? esc_html( SPC()->get_option( 'vat_label' ) ) : esc_html__( 'EU VAT Number', 'sunshine-photo-cart' ); ?></h4>
				<p><?php echo wp_kses_post( $order->get_vat() ); ?></p>
			</td>
		</tr>
	<?php } ?>
	</table>
</div>
