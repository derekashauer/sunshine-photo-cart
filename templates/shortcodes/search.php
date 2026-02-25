<?php
if ( $gallery ) {
	$action_url = $gallery->get_permalink();
	/* translators: %s is the gallery name */
	$label = sprintf( __( 'Search %s', 'sunshine-photo-cart' ), $gallery->get_name() );
} else {
	$action_url = get_permalink( SPC()->get_option( 'page' ) );
	$label      = __( 'Search galleries', 'sunshine-photo-cart' );
}
?>
<form method="get" action="<?php echo esc_url( $action_url ); ?>" class="sunshine--form--fields" id="sunshine--search">
	<div class="sunshine--form--field">
		<input type="search" name="sunshine_search" id="sunshine--search--field" required="required" value="<?php echo esc_attr( ( isset( $_GET['sunshine_search'] ) ) ? sanitize_text_field( $_GET['sunshine_search'] ) : '' ); ?>" />
	</div>
	<div class="sunshine--form--field sunshine--form--field-submit">
		<button type="submit" class="button sunshine--button"><?php echo esc_html( $label ); ?></button>
	</div>
	<?php wp_nonce_field( 'sunshine_search', 'sunshine_search_nonce' ); ?>
</form>
