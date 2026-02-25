<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

sunshine_get_template( 'header' );

// Get page content and apply proper WordPress filters.
$page_id = SPC()->get_page( 'favorites' );
$content = get_post_field( 'post_content', $page_id );
echo apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Everything has been escaped by the previous functions used to build this massive HTML string.

sunshine_get_template( 'footer' );
