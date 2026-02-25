<tr class="sunshine--cart-item <?php echo esc_attr( $cart_item->classes() ); ?>">
	<td class="sunshine--cart-item--image" data-label="<?php esc_attr_e( 'Image', 'sunshine-photo-cart' ); ?>">
		<?php echo wp_kses_post( $cart_item->get_image_html() ); ?>
	</td>
	<td class="sunshine--cart-item--name" data-label="<?php esc_attr_e( 'Product', 'sunshine-photo-cart' ); ?>">
		<div class="sunshine--cart-item--product-name"><?php echo wp_kses_post( $cart_item->get_name() ); ?></div>
		<div class="sunshine--cart-item--product-options"><?php echo wp_kses_post( $cart_item->get_options_formatted() ); ?></div>
		<div class="sunshine--cart-item--image-name"><?php echo wp_kses_post( $cart_item->get_image_name() ); ?></div>
		<div class="sunshine--cart-item--comments"><?php echo wp_kses_post( $cart_item->get_comments() ); ?></div>
		<div class="sunshine--cart-item--extra"><?php echo wp_kses_post( $cart_item->get_extra() ); ?></div>
	</td>
	<td class="sunshine--cart-item--price" data-label="<?php esc_attr_e( 'Price', 'sunshine-photo-cart' ); ?>">
		<?php echo wp_kses_post( $cart_item->get_price_formatted() ); ?>
	</td>
	<td class="sunshine--cart-item--qty" data-label="<?php esc_attr_e( 'Qty', 'sunshine-photo-cart' ); ?>">
		<input type="number" name="item[<?php echo esc_attr( $iterator ); ?>][qty]" class="sunshine--qty" value="<?php echo esc_attr( $cart_item->get_qty() ); ?>" size="4" tabindex="<?php echo esc_attr( $iterator ); ?>" min="<?php echo ( $cart_item->get_min_qty() ) ? esc_attr( $cart_item->get_min_qty() ) : 0; ?>" <?php echo ( $cart_item->get_max_qty() ) ? 'max="' . esc_attr( $cart_item->get_max_qty() ) . '"' : ''; ?> />
		<a href="<?php echo esc_url( $cart_item->get_remove_url() ); ?>" class="sunshine--cart-item--delete" aria-label="<?php esc_attr_e( 'Delete', 'sunshine-photo-cart' ); ?>"></a>
	</td>
	<td class="sunshine--cart-item--total" data-label="<?php esc_attr_e( 'Total', 'sunshine-photo-cart' ); ?>">
		<?php echo wp_kses_post( $cart_item->get_subtotal_formatted() ); ?>
		<input type="hidden" name="item[<?php echo esc_attr( $iterator ); ?>][product_id]" value="<?php echo esc_attr( $cart_item->product->get_id() ); ?>" />
		<input type="hidden" name="item[<?php echo esc_attr( $iterator ); ?>][hash]" value="<?php echo esc_attr( $cart_item->get_hash() ); ?>" />
	</td>
</tr>
