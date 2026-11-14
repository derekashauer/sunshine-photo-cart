<?php
/**
 * Print labs: add-ons that send orders to a print lab (WHCC, Prodigi) share one Print Labs
 * settings tab, with a page for each lab, the way payment methods do.
 *
 * A lab add-on registers itself with the sunshine_print_labs filter:
 *     $labs['whcc'] = array( 'name' => 'WHCC', 'description' => '...' );
 * and supplies its settings fields with the sunshine_options_print_lab_{id} filter.
 *
 * @package SunshinePhotoCart
 */

/**
 * The print lab add-ons that are active.
 *
 * @return array Lab id => array( 'name', 'description' ).
 */
function sunshine_get_print_labs() {
	$labs = apply_filters( 'sunshine_print_labs', array() );
	return is_array( $labs ) ? $labs : array();
}

/**
 * Link to the Print Labs settings tab, or to one lab's settings page.
 *
 * @param string $id Lab id, or empty for the list of labs.
 * @return string
 */
function sunshine_get_print_lab_settings_url( $id = '' ) {
	$args = array(
		'page'    => 'sunshine',
		'section' => 'print_labs',
	);
	if ( $id ) {
		$args['print_lab'] = sanitize_key( $id );
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}
