<div class="sunshine--store--product-item">
	<?php if ( $product->has_image() ) { ?>
		<div class="sunshine--store--product-item--image"><?php echo wp_kses_post( $product->get_image_html( 'thumbnail', false ) ); ?></div>
	<?php } ?>
	<div class="sunshine--store--product-item--name"><?php echo esc_html( $product->get_name() ); ?></div>
	<div class="sunshine--store--product-item--price"><?php echo wp_kses_post( $product->get_price_formatted() ); ?></div>
	<div class="sunshine--store--product-item--action">
		<?php
		$direct_url = apply_filters( 'sunshine_store_product_item_link', '', $product, $gallery );
		if ( ! empty( $direct_url ) ) {
			?>
			<a class="sunshine--store--product-item--select-options" href="<?php echo esc_url( $direct_url ); ?>"><span><?php esc_html_e( 'See options', 'sunshine-photo-cart' ); ?></span></a>
			<?php
		} else {
			?>
			<button class="sunshine--store--product-item--select-options sunshine--open-modal" data-hook="store_product" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-gallery-id="<?php echo esc_attr( $gallery->get_id() ); ?>"><span><?php esc_html_e( 'See options', 'sunshine-photo-cart' ); ?></span></button>
			<?php
		}
		?>
	</div>
</div>
