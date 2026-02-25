<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

sunshine_get_template( 'header' );

echo '<h1>' . esc_html( get_the_title( SPC()->get_page( 'favorites' ) ) ) . '</h1>';

do_action( 'sunshine_before_content' );

// Get page content and apply proper WordPress filters.
$page_id = SPC()->get_page( 'favorites' );
$content = get_post_field( 'post_content', $page_id );
echo apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Everything has been escaped by the previous functions used to build this massive HTML string.

do_action( 'sunshine_after_content' );

sunshine_get_template( 'footer' );
