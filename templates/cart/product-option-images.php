<div class="sunshine--cart-item--product-option-images">
	<?php
	$images      = $item->get_option( 'images' );
	$image_count = ( $images ) ? count( $images ) : 0;
	if ( empty( $images ) || ( $item->product && $image_count < $item->product->get_meta_value( 'image_count' ) ) ) { ?>
		<div class="sunshine--cart-item--product-option-images--incomplete">
		<?php
		$count = $item->product->get_meta_value( 'image_count' ) - $image_count;
		/* translators: %s is the number of images */
		echo esc_html( sprintf( _n( '%s more image available to select', '%s more images available to select', $count, 'sunshine-photo-cart' ), $count ) );
		?>
		</div>
	<?php } ?>
	<button type="button" class="sunshine--open-modal sunshine--button-link" data-hook="multi_image_product_images_edit" data-item="<?php echo esc_attr( $item->get_hash() ); ?>"><?php esc_html_e( 'Edit images', 'sunshine-photo-cart' ); ?></button>
</div>
