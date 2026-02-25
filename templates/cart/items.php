<table id="sunshine--cart--items">
<thead>
	<tr>
		<th class="sunshine--cart-item--image"><?php esc_html_e( 'Image', 'sunshine-photo-cart' ); ?></th>
		<th class="sunshine--cart-item--name"><?php esc_html_e( 'Product', 'sunshine-photo-cart' ); ?></th>
		<th class="sunshine--cart-item--price"><?php esc_html_e( 'Item Price', 'sunshine-photo-cart' ); ?></th>
		<th class="sunshine--cart-item--qty"><?php esc_html_e( 'Qty', 'sunshine-photo-cart' ); ?></th>
		<th class="sunshine--cart-item--total"><?php esc_html_e( 'Item Total', 'sunshine-photo-cart' ); ?></th>
	</tr>
</thead>
<tbody>
<?php
$i = 0;
foreach ( SPC()->cart->get_cart_items() as $cart_item ) {
	$i++;
	sunshine_get_template(
		'cart/item',
		array(
			'cart_item' => $cart_item,
			'iterator'  => $i,
		)
	);
}
?>
</tbody>
</table>
