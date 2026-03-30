<div id="sunshine--image--cart-review">
	<!--<a href="#" class="sunshine--modal--close"><?php esc_html_e( 'Return to gallery', 'sunshine-photo-cart' ); ?></a>-->
	<?php sunshine_get_template( 'cart/mini-cart' ); ?>
</div>
<div id="sunshine--image--add-to-cart" <?php echo ( ! empty( $bulk_mode ) ) ? 'data-bulk-mode="true"' : ''; ?>
												  <?php
													if ( ! empty( $bulk_mode_type ) ) {
														echo ' data-bulk-mode-type="' . esc_attr( $bulk_mode_type ) . '"'; }
													?>
												  <?php
													if ( ! empty( $bulk_mode ) && ! empty( $images ) ) {
														$image_ids = array_map(
															function( $img ) {
																return $img->get_id();
															},
															$images
														);
														echo ' data-image-ids="' . esc_attr( implode( ',', $image_ids ) ) . '"'; }
													?>
>
	<div id="sunshine--image--add-to-cart--header">
		<div id="sunshine--image--add-to-cart--header--image">
			<?php
			// Single image mode.
			if ( ! empty( $image ) ) {
				$image->output();
				?>
				<span><?php echo esc_html( $image->get_name() ); ?></span>
				<?php
			} elseif ( ! empty( $images ) && ! empty( $bulk_mode ) ) {
				// Bulk mode - show up to 3 images in fandeck style.
				$display_images = array_slice( $images, 0, 3 );
				$total_count    = count( $images );
				?>
				<div class="sunshine--bulk-images">
					<?php
					foreach ( $display_images as $index => $bulk_image ) {
						$bulk_image->output();
					}
					?>
				</div>
				<span>
					<?php
					/* translators: %d is the number of images */
					echo esc_html( sprintf( _n( '%d image', '%d images', $total_count, 'sunshine-photo-cart' ), $total_count ) );
					?>
				</span>
				<?php
			}
			?>
		</div>
	</div>
	<div id="sunshine--image--add-to-cart--content">

		<div id="sunshine--image--add-to-cart--nav" class="sunshine--modal--tablist--nav" role="tablist">
			<?php
			// Get price level - use first image if bulk mode, otherwise use single image.
			if ( ! empty( $bulk_mode ) && ! empty( $images ) ) {
				$price_level = $images[0]->get_price_level();
			} elseif ( ! empty( $image ) ) {
				$price_level = $image->get_price_level();
			} else {
				$price_level = 0;
			}

			// Get allowed product types - exclude packages in bulk mode.
			$allowed_types = sunshine_get_allowed_product_types_for_image();
			if ( ! empty( $bulk_mode ) && ! empty( $images ) && count( $images ) > 1 ) {
				$allowed_types = array_diff( $allowed_types, array( 'package' ) );
			}

			$categories = sunshine_get_product_categories( $price_level, $allowed_types );
			if ( ! empty( $categories ) && count( $categories ) > 1 ) {
				?>
				<ul id="sunshine--image--add-to-cart--categories">
					<?php
					$i = 0;
					foreach ( $categories as $category ) {
						$i++;
						?>
						<li aria-controls="sunshine--image--add-to-cart--category-<?php echo esc_attr( $category->get_id() ); ?>" role="tab" data-id="<?php echo esc_attr( $category->get_id() ); ?>"><?php echo esc_html( $category->get_name() ); ?></li>
					<?php } ?>
				</ul>
			<?php } ?>
			<?php
			if ( SPC()->store_enabled() ) {
				if ( ! empty( $image ) ) {
					?>
					<a href="<?php echo esc_url( $image->gallery->get_store_url() ); ?>" id="sunshine--image--add-to-cart--store" class="sunshine--store-open"><?php esc_html_e( 'Store', 'sunshine-photo-cart' ); ?></a>
					<?php
				}
			}
			?>
		</div>

		<div id="sunshine--image--add-to-cart--products">

			<?php
			// Use single image or first image from bulk for action hooks.
			$action_image = ! empty( $image ) ? $image : ( ! empty( $images ) ? $images[0] : null );
			if ( $action_image ) {
				do_action( 'sunshine_before_product_list', $action_image );
			}
			?>

			<?php
			if ( ! empty( $categories ) ) {
				// Count total products across all categories to determine if we should skip to details view.
				$total_products = 0;
				$single_product = null;
				foreach ( $categories as $category ) {
					$products = sunshine_get_products( $price_level, $category->get_id(), $allowed_types );
					if ( ! empty( $products ) ) {
						$total_products += count( $products );
						if ( 1 === $total_products && is_null( $single_product ) ) {
							$single_product = reset( $products );
						}
					}
					// If more than 1 product found, no need to continue counting.
					if ( 1 < $total_products ) {
						break;
					}
				}

				// If only 1 product, show details directly.
				if ( 1 === $total_products && $single_product ) {
					// Add data attribute to indicate single product mode for JS to auto-close modal after add to cart.
					echo '<script>';
					echo 'var el = document.getElementById("sunshine--image--add-to-cart");';
					echo 'el.dataset.singleProduct = "true";';
					echo 'el.dataset.singleProductId = "' . (int) $single_product->get_id() . '";';
					echo 'el.dataset.singleProductType = "' . esc_js( $single_product->get_type() ) . '";';
					echo 'el.dataset.singleProductGalleryId = "' . (int) ( $action_image ? $action_image->get_gallery_id() : 0 ) . '";';
					echo '</script>';
					sunshine_get_template(
						'image/product-details',
						array(
							'product'             => $single_product,
							'image'               => $action_image,
							'images'              => ! empty( $images ) ? $images : null,
							'bulk_mode'           => ! empty( $bulk_mode ) ? $bulk_mode : false,
							'single_product_mode' => true,
						)
					);
				} else {
					// Otherwise show the normal product list.
					sunshine_get_template(
						'image/product-list',
						array(
							'image'      => $action_image,
							'images'     => ! empty( $images ) ? $images : null,
							'bulk_mode'  => ! empty( $bulk_mode ) ? $bulk_mode : false,
							'categories' => $categories,
						)
					);
				}
			}
			?>

			<?php
			if ( $action_image ) {
				do_action( 'sunshine_after_product_list', $action_image );
			}
			?>

		</div>

	</div>

</div>
