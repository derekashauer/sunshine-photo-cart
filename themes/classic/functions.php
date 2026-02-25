<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

add_action( 'wp_head', 'sunshine_theme_classic_css' );
function sunshine_theme_classic_css() {

	if ( ! is_sunshine() ) {
		return;
	}

	$menu_background_color = SPC()->get_option( 'classic_menu_background_color' );
	$menu_links_color      = SPC()->get_option( 'classic_menu_links_color' );
	$main_background_color = SPC()->get_option( 'classic_main_background_color' );
	$main_text_color       = SPC()->get_option( 'classic_main_text_color' );
	$main_links_color      = SPC()->get_option( 'classic_main_links_color' );
	?>
	<style id="sunshine--classic">
		<?php if ( $main_text_color ) { ?>
			#sunshine h1,
			#sunshine h2,
			#sunshine h3,
			#sunshine div,
			#sunshine p,
			#sunshine li,
			#sunshine td,
			#sunshine th { color: <?php echo esc_html( $main_text_color ); ?>; }
		<?php } ?>
		<?php if ( $menu_background_color ) { ?>
			#sunshine--header { background-color: <?php echo esc_html( $menu_background_color ); ?>; }
		<?php } ?>
		<?php if ( $menu_links_color ) { ?>
			#sunshine--header .sunshine--main-menu a,
			#sunshine--logo a { color: <?php echo esc_html( $menu_links_color ); ?>; }
		<?php } ?>
		<?php if ( $main_background_color ) { ?>
			body { background-color: <?php echo esc_html( $main_background_color ); ?> !important; }
		<?php } ?>
		<?php if ( $main_links_color ) { ?>
			#sunshine a,
			#sunshine .sunshine--action-menu li,
			#sunshine .sunshine--action-menu ul li a { color: <?php echo esc_html( $main_links_color ); ?>; }
		<?php } ?>
	</style>
	<?php
}
