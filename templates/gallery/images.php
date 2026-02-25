<div id="sunshine--image-items" class="sunshine--layout--<?php echo esc_attr( SPC()->get_option( 'image_layout', 'standard' ) ); ?> sunshine--col-<?php echo esc_attr( ( ! empty( $cols ) ? $cols : SPC()->get_option( 'columns' ) ) ); ?>" style="--columns: <?php echo esc_attr( ( ! empty( $cols ) ? $cols : SPC()->get_option( 'columns' ) ) ); ?>;">
	<?php
	foreach ( $images as $image ) {
		sunshine_get_template( 'gallery/image-item', array( 'image' => $image ) );
	}
	?>
</div>

<?php
if ( ! empty( $gallery ) ) {
	sunshine_gallery_pagination( $gallery );
}
?>
