<?php
if ( empty( $id ) ) {
	$id = 'sunshine--multi-image-select-' . uniqid();
}
?>
<div class="sunshine--multi-image-select <?php echo ( $image_count > 0 && ! empty( $selected ) && count( $selected ) == $image_count ) ? 'sunshine--completed' : ''; ?>"
	id="<?php echo esc_attr( $id ); ?>"
	data-ref="<?php echo esc_attr( ! empty( $ref ) ? $ref : '' ); ?>"
	data-key="<?php echo esc_attr( isset( $key ) ? $key : '' ); ?>"
	data-image-count="<?php echo esc_attr( ! empty( $image_count ) ? max( 0, $image_count ) : '' ); ?>"
	data-product-id="<?php echo esc_attr( ! empty( $product ) ? $product->get_id() : '' ); ?>"
	data-gallery-id="<?php echo esc_attr( ! empty( $gallery ) ? $gallery->get_id() : '' ); ?>"
	data-value-target="<?php echo esc_attr( ! empty( $value_target ) ? $value_target : '' ); ?>"
	data-selected-target="<?php echo esc_attr( ! empty( $selected_target ) ? $selected_target : '' ); ?>">
	<input type="hidden" name="<?php echo esc_attr( ! empty( $value_target ) ? $value_target : '' ); ?>" value="<?php echo ( ! empty( $selected ) ) ? esc_js( join( ',', $selected ) ) : ''; ?>" required="required" />
	<div class="sunshine--multi-image-select--header">
		<div class="sunshine--multi-image-select--header--count">
			<?php if ( $image_count > 0 ) { ?>
				<span class="sunshine--multi-image-select--counts--selected"><?php echo ( ! empty( $selected ) ) ? count( $selected ) : '0'; ?></span> / <span class="sunshine--multi-image-select--counts--total"><?php echo esc_html( $image_count ); ?></span>
			<?php } ?>
		</div>
		<div class="sunshine--multi-image-select--header--action"><button class="button sunshine--button sunshine--multi-image-select--close"><?php esc_html_e( 'Apply selected images', 'sunshine-photo-cart' ); ?></button></div>
	</div>
	<?php
	$favorite_count = SPC()->customer->get_favorite_count();
	if ( count( $sources ) > 1 || $favorite_count > 0 ) {
		?>
	<div class="sunshine--multi-image-select--sources">
		<label>
			<?php esc_html_e( 'Select photos', 'sunshine-photo-cart' ); ?>
			<select name="source">
				<?php
				if ( $favorite_count > 0 ) {
					?>
					<option value="favorites"><?php esc_html_e( 'Favorites', 'sunshine-photo-cart' ); ?> (<?php echo esc_html( $favorite_count ); ?>)</option>
					<?php
				}
				sunshine_source_dropdown_options( $sources, $gallery->get_id() );
				?>
			</select>
		</label>
		<!-- <a class="sunshine--multi-image-select--show-selected" href="#"><?php esc_html_e( 'Show selected', 'sunshine-photo-cart' ); ?></a> -->
	</div>
	<?php } ?>
	<div class="sunshine--multi-image-select--list">
		<?php
		sunshine_get_template(
			'multi-image-select/gallery-list',
			array(
				'gallery'     => $gallery,
				'product'     => $product,
				'image_count' => $image_count,
				'id'          => $id,
				'selected'    => $selected,
			)
		);
		?>
		<?php if ( $favorite_count > 0 ) { ?>
			<div id="sunshine--multi-image-select--source-favorites" class="sunshine--multi-image-select--source--list" data-product-type="<?php echo esc_attr( $product->get_type() ); ?>" style="display:none;">
				<?php foreach ( SPC()->customer->get_favorites() as $image ) { ?>
					<figure class="sunshine--multi-image-select--image sunshine--multi-image-select--source-favorites">
						<input type="checkbox" required="required" id="image-favorites-<?php echo esc_attr( $image->get_gallery_id() ); ?>-<?php echo esc_attr( $image->get_id() ); ?>-<?php echo esc_attr( $id ); ?>" name="images[]" data-option-id="images" value="<?php echo esc_attr( $image->get_id() ); ?>" data-image-url="<?php echo esc_url( $image->get_image_url() ); ?>" <?php checked( ! empty( $selected ) && in_array( $image->get_id(), $selected ), true ); ?> />
						<label for="image-favorites-<?php echo esc_attr( $image->get_gallery_id() ); ?>-<?php echo esc_attr( $image->get_id() ); ?>-<?php echo esc_attr( $id ); ?>">
							<?php echo wp_kses_post( $image->output() ); ?>
						</label>
						<?php if ( ! empty( SPC()->get_option( 'show_image_data' ) ) ) { ?>
							<figcaption class="sunshine--image--name"><?php echo esc_html( $image->get_name() ); ?></figcaption>
						<?php } ?>
						<?php
						if ( $image_count == '' ) {
							$image_count = 25;
						}
						$total_selected       = count( $selected );
						$value_counts         = array_count_values( $selected );
						$image_count_selected = 0;
						if ( ! empty( $value_counts[ $image->get_id() ] ) ) {
							$image_count_selected = $value_counts[ $image->get_id() ];
						}
						$allowed_qty = $image_count - $total_selected;
						if ( $image_count_selected > 0 ) {
							$allowed_qty += $image_count_selected; // We can allow more for this because it is already selected at least once.
						}
						echo '<div class="sunshine--multi-image-select--qty">' . esc_html__( 'Quantity', 'sunshine-photo-cart' ) . ': ';
						echo '<select name="qty[' . esc_attr( $image->get_id() ) . ']" ' . ( ( $product->get_type() == 'download' ) ? 'data-max="1"' : '' ) . '>';
						for ( $i = 0; $i <= $image_count; $i++ ) {
							if ( $product->get_type() == 'download' && $i > 1 ) {
								continue;
							}
							echo '<option value="' . esc_attr( $i ) . '" ' . selected( $image_count_selected, $i, false ) . ' ' . disabled( ( $allowed_qty < $i ), true, false ) . '>' . esc_html( $i ) . '</option>';
						}
						echo '</select>';
						echo '</div>';
						?>
				</figure>
			<?php } ?>
			</div>
		<?php } ?>
	</div>
</div>
