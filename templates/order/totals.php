<div id="sunshine--cart--totals">

	<table id="sunshine--cart--totals--items">
		<tr class="sunshine--cart--subtotal">
			<th><?php esc_html_e( 'Subtotal', 'sunshine-photo-cart' ); ?></th>
			<td><?php echo wp_kses_post( $order->get_subtotal_formatted() ); ?></td>
		</tr>
		<?php if ( ! empty( $order->get_shipping() ) ) { ?>
		<tr class="sunshine--cart--shipping">
			<?php /* translators: %s is the shipping method name */ ?>
			<th><?php echo esc_html( sprintf( __( 'Shipping via %s', 'sunshine-photo-cart' ), $order->get_shipping_method_name() ) ); ?></th>
			<td><?php echo wp_kses_post( $order->get_shipping_formatted() ); ?></td>
		</tr>
		<?php } ?>
		<?php if ( $order->get_tax() && $order->get_meta_value( 'display_price' ) !== 'with_tax' ) { ?>
		<tr class="sunshine--cart--tax">
			<th><?php esc_html_e( 'Tax', 'sunshine-photo-cart' ); ?></th>
			<td><?php echo wp_kses_post( $order->get_tax_formatted() ); ?></td>
		</tr>
		<?php } ?>
		<?php if ( $order->get_fees() ) { ?>
			<?php foreach ( $order->get_fees() as $fee ) { ?>
				<tr class="sunshine--cart--fee">
					<th><?php echo esc_html( $fee['name'] ); ?></th>
					<td><?php echo wp_kses_post( sunshine_price( $fee['amount'] ) ); ?></td>
				</tr>
			<?php } ?>
		<?php } ?>
		<?php if ( ! empty( $order->has_discount() ) ) { ?>
		<tr class="sunshine--cart--discount">
			<th>
			<?php esc_html_e( 'Discounts', 'sunshine-photo-cart' ); ?>
			<?php
			$discount_names = $order->get_discount_names();
			if ( ! empty( $discount_names ) ) {
				echo '<div class="sunshine--cart--discount--names">' . wp_kses_post( join( '<br />', $discount_names ), array( 'br' => array() ) ) . '</div>';
			}
			?>
			</th>
			<td><?php echo wp_kses_post( $order->get_discount_formatted() ); ?></td>
		</tr>
		<?php } ?>
		<?php if ( $order->get_credits() > 0 ) { ?>
		<tr class="sunshine--cart--credits">
			<th><?php esc_html_e( 'Credits Applied', 'sunshine-photo-cart' ); ?></th>
			<td><?php echo wp_kses_post( '-' . $order->get_credits_formatted() ); ?></td>
		</tr>
		<?php } ?>
		<?php if ( $order->get_refunds() ) { ?>
		<tr class="sunshine--cart--refunds">
			<th><?php esc_html_e( 'Refunds', 'sunshine-photo-cart' ); ?></th>
			<td><?php echo wp_kses_post( $order->get_refund_total_formatted() ); ?></td>
		</tr>
		<?php } ?>
		<tr class="sunshine--cart--total">
			<th><?php esc_html_e( 'Order Total', 'sunshine-photo-cart' ); ?></th>
			<td>
				<?php echo wp_kses_post( $order->get_total_formatted() ); ?>
				<?php if ( $order->get_total() > 0 && $order->get_tax() && $order->get_meta_value( 'display_price' ) == 'with_tax' ) { ?>
					<?php /* translators: %s is the tax amount */ ?>
					<span class="sunshine--cart--total--tax--explain">(<?php echo wp_kses_post( sprintf( __( 'includes %s tax', 'sunshine-photo-cart' ), $order->get_tax_formatted() ) ); ?>)</span>
				<?php } ?>
			</td>
		</tr>
	</table>

</div>
