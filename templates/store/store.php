<div id="sunshine--store">
	<div id="sunshine--store--categories">
	<?php
	// Get all product categories and display
	$categories = sunshine_get_product_categories( $gallery->get_price_level(), sunshine_get_allowed_product_types_for_store() );
	if ( ! empty( $categories ) ) {
		foreach ( $categories as $category ) {
			sunshine_get_template(
				'store/category',
				array(
					'gallery'  => $gallery,
					'category' => $category,
				)
			);
		}
	}
	?>
	</div>
</div>
