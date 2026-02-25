<?php
// Get price level from image or first image in bulk mode.
$price_level = ! empty( $image ) ? $image->get_price_level() : ( ! empty( $images ) ? $images[0]->get_price_level() : 0 );

// Get allowed product types - exclude packages in bulk mode.
$allowed_types = sunshine_get_allowed_product_types_for_image();
if ( ! empty( $bulk_mode ) && ! empty( $images ) && count( $images ) > 1 ) {
	$allowed_types = array_diff( $allowed_types, array( 'package' ) );
}

// foreach category
foreach ( $categories as $category ) {
	$products = sunshine_get_products( $price_level, $category->get_id(), $allowed_types );
	if ( ! empty( $products ) ) {
		?>

		<div class="sunshine--image--add-to-cart--category" id="sunshine--image--add-to-cart--category-<?php echo esc_attr( $category->get_id() ); ?>" aria-selected="true">
			<div class="sunshine--image--add-to-cart--category-name"><?php echo esc_html( $category->get_name() ); ?></div>
			<?php if ( $category->get_description() ) { ?>
				<div class="sunshine--image--add-to-cart--category-description"><?php echo wp_kses_post( $category->get_description() ); ?></div>
			<?php } ?>
			<div class="sunshine--image--add-to-cart--product-list">
				<?php
				foreach ( $products as $product ) {
					sunshine_get_template(
						'image/product-item',
						array(
							'product'   => $product,
							'image'     => $image,
							'images'    => ! empty( $images ) ? $images : null,
							'bulk_mode' => ! empty( $bulk_mode ) ? $bulk_mode : false,
						)
					);
				}
				?>
			</div>
		</div>

		<?php
	}
}
?>
