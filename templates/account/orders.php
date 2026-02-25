<?php if ( ! empty( $orders ) ) { ?>
	<table id="sunshine--orders">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Order #', 'sunshine-photo-cart' ); ?></th>
				<th><?php esc_html_e( 'Date', 'sunshine-photo-cart' ); ?></th>
				<th><?php esc_html_e( 'Total', 'sunshine-photo-cart' ); ?></th>
				<th><?php esc_html_e( 'Status', 'sunshine-photo-cart' ); ?></th>
				<?php if ( ! SPC()->get_option( 'disable_invoice' ) ) { ?>
					<th><?php esc_html_e( 'Invoice', 'sunshine-photo-cart' ); ?></th>
				<?php } ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $orders as $order ) { ?>
				<tr>
					<td><a href="<?php echo esc_url( $order->get_permalink() ); ?>"><?php echo esc_html( $order->get_name() ); ?></a></td>
					<td><?php echo esc_html( $order->get_date( get_option( 'date_format' ) ) ); ?></td>
					<td><?php echo wp_kses_post( $order->get_total_formatted() ); ?></td>
					<td><?php echo esc_html( $order->get_status_name() ); ?></td>
					<?php if ( ! SPC()->get_option( 'disable_invoice' ) ) { ?>
						<td><a href="<?php echo esc_url( $order->get_invoice_permalink() ); ?>"><?php esc_html_e( 'View invoice', 'sunshine-photo-cart' ); ?></a></td>
					<?php } ?>
				</tr>
			<?php } ?>
		</tbody>
	</table>
<?php } else { ?>
	<?php esc_html_e( 'You do not have any orders yet', 'sunshine-photo-cart' ); ?>
<?php } ?>
