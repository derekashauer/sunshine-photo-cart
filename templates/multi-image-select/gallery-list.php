<?php
if ( empty( $id ) ) {
	$id = 'sunshine--multi-image-select-' . uniqid();
}
$images = $gallery->get_images( array( 'nopaging' => true ) );
if ( $images ) {
	?>

<div id="sunshine--multi-image-select--source-<?php echo esc_attr( $gallery->get_id() ); ?>" class="sunshine--multi-image-select--source--list" data-product-type="<?php echo esc_attr( $product->get_type() ); ?>">
	<?php foreach ( $images as $image ) { ?>
	<figure class="sunshine--multi-image-select--image sunshine--multi-image-select--source-<?php echo esc_attr( $gallery->get_id() ); ?> <?php echo ( SPC()->customer->has_favorite( $image->get_id() ) ) ? 'sunshine--multi-image-select--source-favorites' : ''; ?>">
		<input type="checkbox" required="required" id="image-<?php echo esc_attr( $gallery->get_id() ); ?>-<?php echo esc_attr( $image->get_id() ); ?>-<?php echo esc_attr( $id ); ?>" name="images[]" data-option-id="images" value="<?php echo esc_attr( $image->get_id() ); ?>" data-image-url="<?php echo esc_url( $image->get_image_url() ); ?>" <?php checked( ! empty( $selected ) && in_array( $image->get_id(), $selected ), true ); ?> />
		<label for="image-<?php echo esc_attr( $gallery->get_id() ); ?>-<?php echo esc_attr( $image->get_id() ); ?>-<?php echo esc_attr( $id ); ?>">
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

<?php } else { ?>
	<div id="sunshine--multi-image-select--source-<?php echo esc_attr( $gallery->get_id() ); ?>" class="sunshine--multi-image-select--source--list">
		<?php esc_html_e( 'No images in this gallery', 'sunshine-photo-cart' ); ?>
	</div>
<?php } ?>
