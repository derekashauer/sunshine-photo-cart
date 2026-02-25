<?php
$max_qty      = $product->get_max_qty();
$can_purchase = $product->can_purchase();
?>
<div id="sunshine--image--cart-review">
	<?php sunshine_get_template( 'cart/mini-cart' ); ?>
</div>
<div id="sunshine--store--product-details" class="<?php $product->classes(); ?>">
	<div id="sunshine--store--product-details--header">
		<div id="sunshine--store--product-details--header--product">
			<?php $product->get_image_html( 'large' ); ?>
			<div id="sunshine--store--product-details--header--product--title"><?php echo esc_html( $product->get_name() ); ?></div>
			<div id="sunshine--store--product-details--header--product--description"><?php echo wp_kses_post( $product->get_description() ); ?></div>
		</div>
	</div>
	<div id="sunshine--store--product-details--content">

		<?php if ( $product->allow_store_image_select() && $can_purchase ) { ?>
		<div class="sunshine--product-options--item sunshine--product-options--item--select" id="sunshine--product-options--image-select">
			<div class="sunshine--product-options--item--name">
				<?php esc_html_e( 'Images', 'sunshine-photo-cart' ); ?> <?php echo wp_kses_post( sunshine_get_required_notice() ); ?>
			</div>
			<button
				class="sunshine--multi-image-select--open sunshine--button-link"
				data-id="sunshine--multi-image-select--multi-image"
				data-ref="<?php echo esc_attr( ! empty( $ref ) ? $ref : '' ); ?>"
				data-key="<?php echo esc_attr( isset( $key ) ? $key : '0' ); ?>"
				data-image-count="<?php echo esc_attr( $image_count ); ?>"
				data-target="sunshine--store--product-details--content"
				data-value-target="images"
				data-selected-target="sunshine--multi-image-select--selected-images"
				data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
				data-gallery-id="<?php echo esc_attr( $gallery->get_id() ); ?>">
					<?php esc_html_e( 'Select Images', 'sunshine-photo-cart' ); ?>
				</button>
			<input type="hidden" name="images" value="" required="required" />
			<div class="sunshine--multi-image-select--selected-images" id="sunshine--multi-image-select--selected-images--<?php echo esc_attr( $key ); ?>">
				<div class="sunshine--multi-image-select--selected-images--item"></div>
				<?php
				for ( $i = 1; $i < $image_count; $i++ ) {
					echo '<div class="sunshine--multi-image-select--selected-images--item"></div>';
				}
				?>
			</div>
		</div>
		<?php } ?>

		<?php do_action( 'sunshine_store_product_details_before_price', $product ); ?>

		<div id="sunshine--product--details--price">
			<?php if ( $product->allow_store_image_select() ) { ?>
				<?php /* translators: %s is the price */ ?>
				<?php echo wp_kses_post( sprintf( __( '%s/each', 'sunshine-photo-cart' ), $product->get_price_formatted() ) ); ?>
			<?php } else { ?>
				<?php echo wp_kses_post( $product->get_price_formatted() ); ?>
			<?php } ?>
		</div>

		<?php do_action( 'sunshine_store_product_details_after_price', $product ); ?>

		<?php if ( $can_purchase ) { ?>

			<?php if ( ! $max_qty || $max_qty > 1 ) { ?>
			<div id="sunshine--product--details--qty">
				<button class="sunshine--qty--down"><span><?php esc_html_e( 'Increase quantity', 'sunshine-photo-cart' ); ?></span></button>
				<input type="text" name="qty" class="sunshine--qty" min="1" <?php echo ( $max_qty ) ? 'max="' . esc_attr( $max_qty ) . '"' : ''; ?> pattern="[0-9]+" value="1" />
				<button class="sunshine--qty--up"><span><?php esc_html_e( 'Decrease quantity', 'sunshine-photo-cart' ); ?></span></button>
			</div>
			<?php } ?>

			<?php if ( SPC()->get_option( 'product_comments' ) ) { ?>
			<div id="sunshine--product--details--comments">
				<input type="text" name="comments" placeholder="<?php echo esc_attr( __( 'Add comments for this item', 'sunshine-photo-cart' ) ); ?>" />
			</div>
			<?php } ?>

			<div id="sunshine--product--details--action">
				<button class="sunshine--button button" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-gallery-id="<?php echo esc_attr( $gallery->get_id() ); ?>"><?php esc_html_e( 'Add to cart', 'sunshine-photo-cart' ); ?></button>
			</div>

		<?php } ?>

		<?php if ( $can_purchase && $max_qty ) { ?>
			<?php /* translators: %1$s is the maximum quantity, %2$s is the product name */ ?>
			<div id="sunshine--product--details--cart-qty"><?php echo wp_kses_post( sprintf( __( 'You can only add (%1$s) %2$s to cart', 'sunshine-photo-cart' ), $product->get_max_qty(), $product->get_name() ) ); ?></div>
		<?php } ?>

	</div>

</div>
