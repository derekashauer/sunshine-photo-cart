<?php
$product_image_qty = SPC()->cart->get_product_with_image_count( $product->get_id(), $image->get_id() );
$product_qty       = SPC()->cart->get_product_count( $product->get_id() );
$max_qty           = $product->get_max_qty();
$min_qty           = $product->get_min_qty();
$can_purchase      = $product->can_purchase();
?>
<div id="sunshine--product--details" class="<?php $product->classes(); ?>" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-image-id="<?php echo esc_attr( $image->get_id() ); ?>">

	<?php if ( empty( $single_product_mode ) ) : ?>
		<button id="sunshine--product--details--close"><?php esc_html_e( 'Return to products', 'sunshine-photo-cart' ); ?></button>
	<?php endif; ?>

	<div id="sunshine--product--details--title"><?php echo esc_html( $product->get_name() ); ?></div>
	<?php if ( $product->has_image() ) { ?>
		<div id="sunshine--product--details--image"><?php echo wp_kses_post( $product->get_image_html( 'large' ) ?? '' ); ?></div>
	<?php } ?>

	<?php if ( $product->get_description() ) { ?>
		<div class="sunshine--product--details--description"><?php echo wp_kses_post( $product->get_description() ?? '' ); ?></div>
	<?php } ?>

	<?php do_action( 'sunshine_product_details_before_price', $product, $image ); ?>

	<?php if ( ! empty( $bulk_mode ) && ! empty( $images ) && count( $images ) > 1 ) : ?>
		<div id="sunshine--product--details--image-selection">
			<div class="sunshine--product--details--image-selection--header">
				<h4><?php esc_html_e( 'Select images for this product:', 'sunshine-photo-cart' ); ?></h4>
				<div class="sunshine--product--details--image-selection--actions">
					<button type="button" class="sunshine--button-link" id="sunshine--product--details--select-all"><?php esc_html_e( 'Select All', 'sunshine-photo-cart' ); ?></button>
					<button type="button" class="sunshine--button-link" id="sunshine--product--details--select-none"><?php esc_html_e( 'Select None', 'sunshine-photo-cart' ); ?></button>
				</div>
			</div>
			<div class="sunshine--product--details--image-selection--grid">
				<?php foreach ( $images as $bulk_image ) : ?>
					<div class="sunshine--product--details--image-selection--item">
						<label>
							<input type="checkbox" name="selected_images[]" value="<?php echo esc_attr( $bulk_image->get_id() ); ?>" checked />
							<div class="sunshine--product--details--image-selection--thumbnail">
								<?php $bulk_image->output( 'sunshine-thumbnail' ); ?>
							</div>
							<span class="sunshine--product--details--image-selection--name"><?php echo esc_html( $bulk_image->get_name() ); ?></span>
						</label>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<div id="sunshine--product--details--price">
		<?php echo wp_kses_post( $product->get_price_formatted() ?? '' ); ?>
	</div>

	<?php do_action( 'sunshine_product_details_after_price', $product, $image ); ?>

	<?php if ( $can_purchase && ( ! $max_qty || $max_qty > 1 ) ) { ?>
	<div id="sunshine--product--details--qty">
		<button class="sunshine--qty--down"><span><?php esc_html_e( 'Decrease quantity', 'sunshine-photo-cart' ); ?></span></button>
		<input type="text" name="qty" class="sunshine--qty" min="<?php echo ( $min_qty ) ? esc_attr( $min_qty ) : 0; ?>" <?php echo ( $max_qty ) ? 'max="' . esc_attr( $max_qty ) . '"' : ''; ?>  pattern="[0-9]+" value="<?php echo ( $min_qty ) ? esc_attr( $min_qty ) : 1; ?>" aria-label="<?php esc_attr_e( 'Qty', 'sunshine-photo-cart' ); ?>" />
		<button class="sunshine--qty--up"><span><?php esc_html_e( 'Increase quantity', 'sunshine-photo-cart' ); ?></span></button>
	</div>
	<?php } ?>

	<?php if ( SPC()->get_option( 'product_comments' ) ) { ?>
	<div id="sunshine--product--details--comments">
		<input type="text" name="comments" placeholder="<?php echo esc_attr( __( 'Add comments for this item', 'sunshine-photo-cart' ) ); ?>" />
	</div>
	<?php } ?>

	<?php if ( $can_purchase ) { ?>
		<div id="sunshine--product--details--action">
			<button class="sunshine--button" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-gallery-id="<?php echo esc_attr( $image->gallery->get_id() ); ?>" data-image-id="<?php echo esc_attr( $image->get_id() ); ?>"><?php esc_html_e( 'Add to cart', 'sunshine-photo-cart' ); ?></button>
		</div>
	<?php } ?>

	<?php if ( $can_purchase && ( $max_qty || $min_qty ) ) { ?>
		<div id="sunshine--product--details--cart-qty">
			<?php if ( $min_qty && $max_qty ) { ?>
				<?php /* translators: %1$s is the minimum quantity, %2$s is the maximum quantity */ ?>
				<?php echo esc_html( sprintf( __( 'You must add at least %1$s and no more than %2$s to cart', 'sunshine-photo-cart' ), $min_qty, $max_qty ) ); ?>
			<?php } elseif ( $min_qty ) { ?>
				<?php /* translators: %1$s is the minimum quantity */ ?>
				<?php echo esc_html( sprintf( __( 'You must add at least %1$s to cart', 'sunshine-photo-cart' ), $min_qty ) ); ?>
			<?php } elseif ( $max_qty ) { ?>
				<?php /* translators: %1$s is the maximum quantity */ ?>
				<?php echo esc_html( sprintf( __( 'You can only add up to %1$s to cart', 'sunshine-photo-cart' ), $max_qty, $product->get_name() ) ); ?>
			<?php } ?>
		</div>
	<?php } ?>

</div>
