<button id="sunshine--checkout--order-review--toggle"><?php esc_html_e( 'Order Summary', 'sunshine-photo-cart' ); ?> <span><?php echo wp_kses_post( SPC()->cart->get_total_formatted() ); ?></span></button>
<div id="sunshine--checkout--order-review">
	<h2><?php esc_html_e( 'Order Summary', 'sunshine-photo-cart' ); ?></h2>
	<div id="sunshine--checkout--order-review--cart">
		<table id="sunshine--checkout--cart-items">
		<?php foreach ( SPC()->cart->get_cart_items() as $cart_item ) { ?>
			<tr class="sunshine--checkout--cart-item <?php echo esc_attr( $cart_item->classes() ); ?>">
				<td class="sunshine--checkout--cart-item--image" data-label="<?php esc_attr_e( 'Image', 'sunshine-photo-cart' ); ?>">
					<?php echo wp_kses_post( $cart_item->get_image_html() ); ?>
					<span class="sunshine--qty sunshine--count"><?php echo esc_html( $cart_item->get_qty() ); ?></span>
				</td>
				<td class="sunshine--checkout--cart-item--name" data-label="<?php esc_attr_e( 'Product', 'sunshine-photo-cart' ); ?>">
					<div class="sunshine--checkout--cart-item--product-name"><?php echo wp_kses_post( $cart_item->get_name() ); ?></div>
					<div class="sunshine--checkout--cart-item--product-options"><?php echo wp_kses_post( $cart_item->get_options_formatted() ); ?></div>
				</td>
				<td class="sunshine--checkout--cart-item--total" data-label="<?php esc_attr_e( 'Total', 'sunshine-photo-cart' ); ?>">
					<?php echo wp_kses_post( $cart_item->get_subtotal_formatted() ); ?>
				</td>
			</tr>
		<?php } ?>
		</table>
	</div>

	<?php do_action( 'sunshine_after_order_review_cart' ); ?>

	<table id="sunshine--checkout--order-review--amounts">
		<tr id="sunshine--checkout--order-review--subtotal">
			<th><?php esc_html_e( 'Subtotal', 'sunshine-photo-cart' ); ?></th>
			<td><?php echo wp_kses_post( SPC()->cart->get_subtotal_formatted() ); ?></td>
		</tr>
		<tr id="sunshine--checkout--order-review--shipping"
		<?php
		if ( ! SPC()->cart->needs_shipping() ) {
			echo ' style="display: none;"'; }
		?>
		>
			<th><?php esc_html_e( 'Shipping', 'sunshine-photo-cart' ); ?></th>
			<td>
				<?php
				if ( ! empty( SPC()->cart->get_shipping_method() ) ) {
					// Show shipping total
					echo wp_kses_post( SPC()->cart->get_shipping_formatted() );
				} else {
					esc_html_e( 'Select shipping method', 'sunshine-photo-cart' );
				}
				?>
			</td>
		</tr>
		<?php
		$discount_after_tax = SPC()->get_option( 'discount_after_tax' );
		if ( ! $discount_after_tax && SPC()->cart->get_discount() > 0 ) {
			?>
		<tr id="sunshine--checkout--order-review--discount">
			<th><?php esc_html_e( 'Discounts', 'sunshine-photo-cart' ); ?></th>
			<td><?php echo wp_kses_post( SPC()->cart->get_discount_formatted() ); ?></td>
		</tr>
		<?php } ?>
		<?php if ( SPC()->cart->get_tax() && SPC()->get_option( 'display_price' ) != 'with_tax' ) { ?>
		<tr id="sunshine--checkout--order-review--tax">
			<th><?php esc_html_e( 'Tax', 'sunshine-photo-cart' ); ?></th>
			<td><?php echo wp_kses_post( SPC()->cart->get_tax_formatted() ); ?></td>
		</tr>
		<?php } ?>
		<?php if ( $discount_after_tax && SPC()->cart->get_discount() > 0 ) { ?>
		<tr id="sunshine--checkout--order-review--discount">
			<th><?php esc_html_e( 'Discounts', 'sunshine-photo-cart' ); ?></th>
			<td><?php echo wp_kses_post( SPC()->cart->get_discount_formatted() ); ?></td>
		</tr>
		<?php } ?>
		<?php if ( SPC()->cart->get_fees() ) { ?>
			<?php foreach ( SPC()->cart->get_fees() as $fee ) { ?>
				<tr class="sunshine--cart--fee">
					<th><?php echo esc_html( $fee['name'] ); ?></th>
					<td><?php echo wp_kses_post( sunshine_price( $fee['amount'] ) ); ?></td>
				</tr>
			<?php } ?>
		<?php } ?>
		<tr id="sunshine--checkout--order-review--credits"
		<?php
		if ( ! SPC()->cart->use_credits() ) {
			echo ' style="display: none;"'; }
		?>
		>
			<th><?php esc_html_e( 'Credits Applied', 'sunshine-photo-cart' ); ?></th>
			<td>
				<?php
				$credits = SPC()->cart->get_credits_applied();
				if ( $credits ) {
					echo '-' . wp_kses_post( SPC()->cart->get_credits_applied_formatted() );
				}
				?>
			</td>
		</tr>
		<tr id="sunshine--checkout--order-review--total">
			<th><?php esc_html_e( 'Order Total', 'sunshine-photo-cart' ); ?></th>
			<td>
				<?php echo wp_kses_post( SPC()->cart->get_total_formatted() ); ?>
				<?php if ( SPC()->cart->get_total() > 0 && SPC()->cart->get_tax() && SPC()->get_option( 'display_price' ) == 'with_tax' ) { ?>
					<?php /* translators: %s is the tax amount */ ?>
					<span class="sunshine--cart--total--tax--explain">(<?php echo wp_kses_post( sprintf( __( 'includes %s tax', 'sunshine-photo-cart' ), SPC()->cart->get_tax_formatted() ) ); ?>)</span>
				<?php } ?>
			</td>
		</tr>
	</table>
</div>

<?php do_action( 'sunshine_after_order_review_summary' ); ?>
