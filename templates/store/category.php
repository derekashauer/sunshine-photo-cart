<div class="sunshine--store--category">
	<?php if ( $category->has_image() ) { ?>
		<div class="sunshine--store--category--image"><?php echo wp_kses_post( $category->get_image_html( 'large', false ) ); ?></div>
	<?php } ?>
	<div class="sunshine--store--category--name"><?php echo esc_html( $category->get_name() ); ?></div>
	<?php if ( $category->get_description() ) { ?>
		<div class="sunshine--store--category--description"><?php echo wp_kses_post( $category->get_description() ); ?></div>
	<?php } ?>
	<div class="sunshine--store--category--products">
		<?php
		$products = sunshine_get_products( $gallery->get_price_level(), $category->get_id() );
		if ( ! empty( $products ) ) {
			foreach ( $products as $product ) {
				sunshine_get_template(
					'store/product-item',
					array(
						'gallery'  => $gallery,
						'category' => $category,
						'product'  => $product,
					)
				);
			}
		}
		?>
	</div>
</div>
