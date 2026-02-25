<div class="sunshine--store--product-item">
	<?php if ( $product->has_image() ) { ?>
		<div class="sunshine--store--product-item--image"><?php echo wp_kses_post( $product->get_image_html( 'thumbnail', false ) ); ?></div>
	<?php } ?>
	<div class="sunshine--store--product-item--name"><?php echo esc_html( $product->get_name() ); ?></div>
	<div class="sunshine--store--product-item--price"><?php echo wp_kses_post( $product->get_price_formatted() ); ?></div>
	<div class="sunshine--store--product-item--action">
		<button class="sunshine--store--product-item--select-options sunshine--open-modal" data-hook="store_product" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-gallery-id="<?php echo esc_attr( $gallery->get_id() ); ?>"><span><?php esc_html_e( 'See options', 'sunshine-photo-cart' ); ?></span></button>
	</div>
</div>
