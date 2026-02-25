<html>
<head>
	<title><?php esc_html_e( 'Order item list', 'sunshine-photo-cart' ); ?></title>
	<style type="text/css">
	body { font-family: sans-serif; font-size: 16px; }
	h1 { font-size: 30px; }
	h2 { font-size: 20px; }
	table { border-spacing: 0; }
	table thead th { background: #EFEFEF; color: #666; padding: 5px; font-size: 11px; text-transform: uppercase; font-weight: normal; }
	table tbody td { padding: 10px 15px 10px 0; font-size: 15px; }
	</style>
</head>
<body>
	<h1><?php esc_html_e( 'Order item list', 'sunshine-photo-cart' ); ?></h1>
	<?php
	foreach ( $ids as $order_id ) {
		$order = new SPC_Order( $order_id );
		$cart  = $order->get_cart();
		?>
		<h2><?php echo esc_html( $order->get_name() ); ?></h2>
		<table id="sunshine-cart-items">
		<thead>
			<tr>
				<th class="sunshine-cart-image"><?php esc_html_e( 'Image', 'sunshine-photo-cart' ); ?></th>
				<th class="sunshine-cart-name"><?php esc_html_e( 'Product', 'sunshine-photo-cart' ); ?></th>
				<th class="sunshine-cart-qty"><?php esc_html_e( 'Qty', 'sunshine-photo-cart' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $cart as $cart_item ) { ?>
			<tr class="sunshine-cart-item <?php echo esc_attr( $cart_item->classes() ); ?>">
				<td class="sunshine-cart-item-image" data-label="<?php esc_attr_e( 'Image', 'sunshine-photo-cart' ); ?>">
					<?php echo wp_kses_post( $cart_item->get_image_html( '', '', array( 'width' => '50' ) ) ); ?>
				</td>
				<td class="sunshine-cart-item-name" data-label="<?php esc_attr_e( 'Product', 'sunshine-photo-cart' ); ?>">
					<div class="sunshine-cart-item-name-image"><?php echo esc_html( $cart_item->get_image_name() ); ?></div>
					<div class="sunshine-cart-item-file"><?php echo esc_html( $cart_item->get_filename() ); ?></div>
					<div class="sunshine-cart-item-name-product"><?php echo esc_html( $cart_item->get_name() ); ?></div>
					<div class="sunshine-cart-item-comments"><?php echo wp_kses_post( $cart_item->get_comments() ); ?></div>
				</td>
				<td class="sunshine-cart-item-qty" data-label="<?php esc_attr_e( 'Qty', 'sunshine-photo-cart' ); ?>">
					<?php echo esc_html( $cart_item->get_qty() ); ?>
				</td>
			</tr>
		<?php } ?>
		</tbody>
		</table>

	<?php } ?>
</body>
</html>
