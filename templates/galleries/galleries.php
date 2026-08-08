<?php
if ( empty( $galleries ) ) {
	// Fetch only the current page of galleries; the per-page limit is applied
	// before gallery objects are constructed so memory use stays constant no
	// matter how many galleries exist.
	$per_page        = sunshine_galleries_per_page();
	$current_page    = ( isset( $_GET['galleries_pagination'] ) ) ? max( 1, intval( $_GET['galleries_pagination'] ) ) : 1;
	$paginated       = sunshine_get_galleries_paginated( array( 'post_parent' => 0 ), 'view', $current_page, $per_page );
	$galleries       = $paginated['galleries'];
	$total_galleries = $paginated['total'];
} else {
	// Galleries were supplied by the caller; paginate the given set.
	$total_galleries = count( $galleries );
	$per_page        = sunshine_galleries_per_page();

	// Apply pagination if per_page is set and greater than 0
	if ( ! empty( $per_page ) && $per_page > 0 ) {
		$current_page      = ( isset( $_GET['galleries_pagination'] ) ) ? intval( $_GET['galleries_pagination'] ) : 1;
		$offset            = ( $current_page - 1 ) * $per_page;
		$galleries         = array_slice( $galleries, $offset, $per_page );
	}
}

if ( ! empty( $galleries ) ) {
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
