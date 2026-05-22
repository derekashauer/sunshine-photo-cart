<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<title><?php esc_html_e( 'Order item list', 'sunshine-photo-cart' ); ?></title>
	<style type="text/css">
	* { box-sizing: border-box; }
	body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; font-size: 13px; color: #222; margin: 0; padding: 30px 40px; background: #fff; }
	h1 { font-size: 22px; margin: 0 0 24px; font-weight: 600; }
	h2 { font-size: 18px; margin: 0 0 6px; font-weight: 600; }
	.sunshine-toolbar { margin-bottom: 24px; }
	.sunshine-toolbar button { padding: 8px 18px; font-size: 14px; cursor: pointer; border: 1px solid #2271b1; background: #2271b1; color: #fff; border-radius: 3px; }
	.sunshine-toolbar button:hover { background: #135e96; border-color: #135e96; }
	.sunshine-order-block { margin-bottom: 40px; }
	.sunshine-order-meta { font-size: 12px; color: #555; margin-bottom: 14px; }
	.sunshine-order-meta dl { display: grid; grid-template-columns: max-content 1fr; gap: 3px 14px; margin: 0; max-width: 600px; }
	.sunshine-order-meta dt { font-weight: 600; color: #333; }
	.sunshine-order-meta dd { margin: 0; }
	table.sunshine-cart-items { width: 100%; border-collapse: collapse; }
	table.sunshine-cart-items th { background: #f3f3f3; color: #444; padding: 8px 10px; font-size: 11px; text-transform: uppercase; font-weight: 600; text-align: left; border-bottom: 2px solid #bbb; letter-spacing: 0.5px; }
	table.sunshine-cart-items td { padding: 10px; border-bottom: 1px solid #e0e0e0; vertical-align: top; }
	table.sunshine-cart-items .sunshine-cart-check { width: 30px; }
	table.sunshine-cart-items .sunshine-cart-image { width: 70px; }
	table.sunshine-cart-items td.sunshine-cart-file { font-family: "SF Mono", Menlo, Consolas, monospace; font-size: 12px; color: #555; word-break: break-all; }
	table.sunshine-cart-items .sunshine-cart-qty { width: 50px; text-align: center; }
	table.sunshine-cart-items td.sunshine-cart-qty { font-weight: 600; font-size: 14px; }
	table.sunshine-cart-items img { display: block; max-width: 50px; height: auto; }
	.sunshine-check-box { display: inline-block; width: 16px; height: 16px; border: 1.5px solid #444; border-radius: 2px; vertical-align: middle; }
	.sunshine-cart-item-package-child td { background: #fafafa; }
	.sunshine-cart-item-name-image { font-weight: 600; }
	.sunshine-cart-item-name-product small { color: #888; font-weight: normal; }
	.sunshine-cart-item-comments { margin-top: 6px; padding: 6px 8px; background: #fffbe6; border-left: 3px solid #f0b500; font-size: 12px; }

	@media print {
		body { padding: 0; }
		.sunshine-toolbar { display: none; }
		.sunshine-order-block { page-break-after: always; }
		.sunshine-order-block:last-child { page-break-after: auto; }
		table.sunshine-cart-items tr { page-break-inside: avoid; }
		table.sunshine-cart-items thead { display: table-header-group; }
		table.sunshine-cart-items th { background: #f3f3f3 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
		.sunshine-cart-item-package-child td { background: #fafafa !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
		.sunshine-cart-item-comments { background: #fffbe6 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
	}
	</style>
</head>
<body>
	<div class="sunshine-toolbar">
		<button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'sunshine-photo-cart' ); ?></button>
	</div>
	<?php
	foreach ( $ids as $order_id ) {
		$order = new SPC_Order( $order_id );
		$cart  = $order->get_cart();
		?>
		<div class="sunshine-order-block">
			<h2><?php echo esc_html( $order->get_name() ); ?></h2>
			<div class="sunshine-order-meta">
				<dl>
					<?php if ( $order->get_date() ) { ?>
						<dt><?php esc_html_e( 'Date', 'sunshine-photo-cart' ); ?></dt>
						<dd><?php echo esc_html( $order->get_date() ); ?></dd>
					<?php } ?>
					<?php if ( $order->get_customer_name() ) { ?>
						<dt><?php esc_html_e( 'Customer', 'sunshine-photo-cart' ); ?></dt>
						<dd><?php echo esc_html( $order->get_customer_name() ); ?></dd>
					<?php } ?>
					<?php if ( $order->get_email() ) { ?>
						<dt><?php esc_html_e( 'Email', 'sunshine-photo-cart' ); ?></dt>
						<dd><?php echo esc_html( $order->get_email() ); ?></dd>
					<?php } ?>
					<?php
					$delivery = $order->get_delivery_method_name();
					$shipping = $order->get_shipping_method_name();
					if ( $delivery || $shipping ) {
						?>
						<dt><?php esc_html_e( 'Delivery', 'sunshine-photo-cart' ); ?></dt>
						<dd>
							<?php
							$parts = array_filter( array( $delivery, $shipping ) );
							echo esc_html( implode( ' — ', $parts ) );
							?>
						</dd>
						<?php
					}
					?>
					<?php if ( $order->get_shipping_address_formatted() ) { ?>
						<dt><?php esc_html_e( 'Shipping address', 'sunshine-photo-cart' ); ?></dt>
						<dd><?php echo wp_kses_post( $order->get_shipping_address_formatted() ); ?></dd>
					<?php } ?>
					<?php if ( $order->get_customer_notes() ) { ?>
						<dt><?php esc_html_e( 'Customer notes', 'sunshine-photo-cart' ); ?></dt>
						<dd><?php echo wp_kses_post( $order->get_customer_notes() ); ?></dd>
					<?php } ?>
				</dl>
			</div>
			<table class="sunshine-cart-items">
				<thead>
					<tr>
						<th class="sunshine-cart-check"></th>
						<th class="sunshine-cart-image"><?php esc_html_e( 'Image', 'sunshine-photo-cart' ); ?></th>
						<th class="sunshine-cart-name"><?php esc_html_e( 'Name', 'sunshine-photo-cart' ); ?></th>
						<th class="sunshine-cart-file"><?php esc_html_e( 'Filename', 'sunshine-photo-cart' ); ?></th>
						<th class="sunshine-cart-product"><?php esc_html_e( 'Product', 'sunshine-photo-cart' ); ?></th>
						<th class="sunshine-cart-qty"><?php esc_html_e( 'Qty', 'sunshine-photo-cart' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $cart as $cart_item ) {
					$custom_rows = apply_filters( 'sunshine_admin_order_items_custom_rows', '', $cart_item );
					if ( $custom_rows !== '' ) {
						echo $custom_rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						continue;
					}
					$is_digital = ( $cart_item->get_type() === 'download' );
					?>
					<tr class="sunshine-cart-item <?php echo esc_attr( $cart_item->classes() ); ?>">
						<td class="sunshine-cart-check"><?php if ( ! $is_digital ) { ?><span class="sunshine-check-box"></span><?php } ?></td>
						<td class="sunshine-cart-image">
							<?php echo wp_kses_post( $cart_item->get_image_html( '', '', array( 'width' => '50' ) ) ); ?>
						</td>
						<td class="sunshine-cart-name">
							<div class="sunshine-cart-item-name-image"><?php echo wp_kses_post( $cart_item->get_image_name() ); ?></div>
						</td>
						<td class="sunshine-cart-file">
							<?php echo wp_kses_post( $cart_item->get_filename() ); ?>
						</td>
						<td class="sunshine-cart-product">
							<div class="sunshine-cart-item-name-product"><?php echo wp_kses_post( $cart_item->get_name() ); ?></div>
							<?php if ( $cart_item->get_comments() ) { ?>
								<div class="sunshine-cart-item-comments"><?php echo wp_kses_post( $cart_item->get_comments() ); ?></div>
							<?php } ?>
						</td>
						<td class="sunshine-cart-qty">
							<?php echo esc_html( $cart_item->get_qty() ); ?>
						</td>
					</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>
	<?php } ?>
</body>
</html>
