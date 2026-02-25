<?php
if ( empty( $galleries ) ) {
	$galleries = sunshine_get_galleries( array( 'post_parent' => 0 ), 'view' );
}

if ( ! empty( $galleries ) ) {

	$total_galleries = count( $galleries );
	$per_page        = sunshine_galleries_per_page();

	// Apply pagination if per_page is set and greater than 0
	if ( ! empty( $per_page ) && $per_page > 0 ) {
		$current_page      = ( isset( $_GET['galleries_pagination'] ) ) ? intval( $_GET['galleries_pagination'] ) : 1;
		$offset            = ( $current_page - 1 ) * $per_page;
		$galleries         = array_slice( $galleries, $offset, $per_page );
	}
?>

	<div id="sunshine--gallery-items" class="sunshine--layout--<?php echo esc_attr( SPC()->get_option( 'gallery_layout', 'standard' ) ); ?> sunshine--col-<?php echo esc_attr( SPC()->get_option( 'galleries_columns', SPC()->get_option( 'columns' ) ) ); ?>">

	<?php
	foreach ( $galleries as $gallery ) {
		sunshine_get_template( 'galleries/gallery-item', array( 'gallery' => $gallery ) );
	}
	?>

	</div>

	<?php sunshine_galleries_pagination( $total_galleries ); ?>

<?php
}
