<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

add_action( 'sunshine_before_content', 'sunshine_before_content_start_sunshine', 1 );
function sunshine_before_content_start_sunshine() {
	echo '<div id="sunshine" class="' . esc_attr( sunshine_classes( false ) ) . '">';
}

add_action( 'sunshine_before_content', 'sunshine_before_content_start_main', 3 );
function sunshine_before_content_start_main() {
	echo '<div id="sunshine--main"><div class="sunshine--container">';
}

add_action( 'sunshine_before_content', 'sunshine_main_menu_display', 4 );
function sunshine_main_menu_display() {
	if ( is_sunshine() && SPC()->get_option( 'main_menu' ) ) {
		sunshine_main_menu();
	}
}

add_action( 'sunshine_after_content', 'sunshine_after_content_end_main', 995 );
function sunshine_after_content_end_main() {
	echo '</div></div> <!-- CLOSE "sunshine--main" -->';
}

add_action( 'sunshine_after_content', 'sunshine_after_content_end_sunshine', 1 );
function sunshine_after_content_end_sunshine() {
	echo '</div> <!-- CLOSE "sunshine" -->';
}
