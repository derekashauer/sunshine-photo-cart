<table id="sunshine--cart--totals--items">
	<tr class="sunshine--cart--subtotal">
		<th><?php esc_html_e( 'Subtotal', 'sunshine-photo-cart' ); ?></th>
		<td><?php echo wp_kses_post( SPC()->cart->get_subtotal_formatted() ); ?></td>
	</tr>
	<?php if ( ! empty( SPC()->cart->get_shipping_method() ) ) { ?>
	<tr class="sunshine--cart--shipping">
		<th><?php esc_html_e( 'Shipping', 'sunshine-photo-cart' ); ?></th>
		<td><?php echo wp_kses_post( SPC()->cart->get_shipping_formatted() ); ?></td>
	</tr>
	<?php } ?>
	<?php
	$discount_after_tax = SPC()->get_option( 'discount_after_tax' );
	if ( ! $discount_after_tax && SPC()->cart->get_discount() > 0 ) {
		?>
	<tr class="sunshine--cart--discount">
		<th>
			<?php esc_html_e( 'Discounts', 'sunshine-photo-cart' ); ?>
			<?php
			$discount_names = SPC()->cart->get_discount_names();
			if ( ! empty( $discount_names ) ) {
				echo '<div class="sunshine--cart--discount--names">' . wp_kses_post( join( '<br />', $discount_names ) ) . '</div>';
			}
			?>
		</th>
		<td><?php echo wp_kses_post( SPC()->cart->get_discount_formatted() ); ?></td>
	</tr>
	<?php } ?>
	<?php if ( SPC()->cart->get_tax() && SPC()->get_option( 'display_price' ) != 'with_tax' ) { ?>
	<tr class="sunshine--cart--tax">
		<th><?php esc_html_e( 'Tax', 'sunshine-photo-cart' ); ?></th>
		<td><?php echo wp_kses_post( SPC()->cart->get_tax_formatted() ); ?></td>
	</tr>
	<?php } ?>
	<?php if ( $discount_after_tax && SPC()->cart->get_discount() > 0 ) { ?>
	<tr class="sunshine--cart--discount">
		<th>
			<?php esc_html_e( 'Discounts', 'sunshine-photo-cart' ); ?>
			<?php
			$discount_names = SPC()->cart->get_discount_names();
			if ( ! empty( $discount_names ) ) {
				echo '<div class="sunshine--cart--discount--names">' . wp_kses_post( join( '<br />', $discount_names ) ) . '</div>';
			}
			?>
		</th>
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
	<tr class="sunshine--cart--total">
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
