<?php $items = $order->get_items(); ?>
<table id="sunshine--cart--items">
<thead>
	<tr>
		<th class="sunshine--cart-item--image"><?php esc_html_e( 'Image', 'sunshine-photo-cart' ); ?></th>
		<th class="sunshine--cart-item--name"><?php esc_html_e( 'Product', 'sunshine-photo-cart' ); ?></th>
		<th class="sunshine--cart-item--qty"><?php esc_html_e( 'Qty', 'sunshine-photo-cart' ); ?></th>
		<th class="sunshine--cart-item--price"><?php esc_html_e( 'Item Price', 'sunshine-photo-cart' ); ?></th>
		<th class="sunshine--cart-item--total"><?php esc_html_e( 'Item Total', 'sunshine-photo-cart' ); ?></th>
	</tr>
</thead>
<tbody>
<?php
foreach ( $items as $item ) {
	sunshine_get_template( 'order/item', array( 'item' => $item ) );
}
?>
</tbody>
</table>
