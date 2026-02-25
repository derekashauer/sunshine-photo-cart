<tr class="sunshine--cart-item <?php echo esc_attr( $item->classes() ); ?>">
	<td class="sunshine--cart-item--image" data-label="<?php esc_attr_e( 'Image', 'sunshine-photo-cart' ); ?>">
		<?php echo wp_kses_post( $item->get_image_html() ); ?>
	</td>
	<td class="sunshine--cart-item--name" data-label="<?php esc_attr_e( 'Product', 'sunshine-photo-cart' ); ?>">
		<div class="sunshine--cart-item--product-name"><?php echo wp_kses_post( $item->get_name() ); ?></div>
		<div class="sunshine--cart-item--product-options"><?php echo wp_kses_post( $item->get_options_formatted() ); ?></div>
		<div class="sunshine--cart-item--image-name"><?php echo wp_kses_post( $item->get_image_name() ); ?></div>
		<div class="sunshine--cart-item--comments"><?php echo wp_kses_post( $item->get_comments() ); ?></div>
		<div class="sunshine--cart-item--extra"><?php echo wp_kses_post( $item->get_extra() ); ?></div>
	</td>
	<td class="sunshine--cart-item--qty" data-label="<?php esc_attr_e( 'Qty', 'sunshine-photo-cart' ); ?>">
		<?php echo esc_html( $item->get_qty() ); ?>
	</td>
	<td class="sunshine--cart-item--price" data-label="<?php esc_attr_e( 'Price', 'sunshine-photo-cart' ); ?>">
		<?php echo wp_kses_post( $item->get_price_formatted() ); ?>
	</td>
	<td class="sunshine--cart-item--total" data-label="<?php esc_attr_e( 'Total', 'sunshine-photo-cart' ); ?>">
		<?php echo wp_kses_post( $item->get_subtotal_formatted() ); ?>
	</td>
</tr>
