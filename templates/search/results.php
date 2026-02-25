<header id="sunshine--page-header">
	<?php /* translators: %s is the search term */ ?>
	<h1><?php echo esc_html( sprintf( __( 'You searched for "%s"', 'sunshine-photo-cart' ), SPC()->frontend->search_term ) ); ?></h1>
	<?php sunshine_action_menu(); ?>
</header>

<?php
sunshine_search_form();

// Display matching galleries first.
$galleries = SPC()->frontend->gallery_search_results;
if ( ! empty( $galleries ) ) {
	?>
	<div class="sunshine--search-galleries">
		<h2><?php esc_html_e( 'Matching Galleries', 'sunshine-photo-cart' ); ?></h2>
		<?php sunshine_get_template( 'galleries/galleries', array( 'galleries' => $galleries ) ); ?>
	</div>
	<?php
}

// Display matching images.
if ( ! empty( $images ) ) {
	if ( ! empty( $galleries ) ) {
		?>
		<h2><?php esc_html_e( 'Matching Images', 'sunshine-photo-cart' ); ?></h2>
		<?php
	}
	sunshine_get_template( 'gallery/images', array( 'images' => $images ) );
} elseif ( empty( $galleries ) ) {
	sunshine_get_template( 'search/no-images' );
}
?>
