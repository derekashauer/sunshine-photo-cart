<?php
// add_action( 'sunshine_options_design', 'sunshine_theme_cover_options' );
function sunshine_theme_cover_options( $fields ) {
	$fields['1500'] = array(
		'id'   => 'cover',
		'name' => __( 'Cover Theme Options', 'sunshine-photo-cart' ),
		'type' => 'header',
	);
	$fields['1501'] = array(
		'id'   => 'classic_menu_background',
		'name' => __( 'Menu Background', 'sunshine-photo-cart' ),
		'type' => 'color',
	);
	$fields['1502'] = array(
		'id'   => 'classic_menu_links',
		'name' => __( 'Menu Links', 'sunshine-photo-cart' ),
		'type' => 'color',
	);
	$fields['1503'] = array(
		'id'   => 'classic_main_background',
		'name' => __( 'Main Background', 'sunshine-photo-cart' ),
		'type' => 'color',
	);
	return $fields;
}

add_action( 'wp_head', 'sunshine_theme_cover_css' );
function sunshine_theme_cover_css() {
	$menu_background = SPC()->get_option( 'classic_menu_background' );
	$menu_links      = SPC()->get_option( 'classic_menu_links' );
	$main_background = SPC()->get_option( 'classic_main_background' );
	?>
	<style id="sunshine--classic">
		<?php if ( $menu_background ) { ?>
			#sunshine--header { background-color: <?php echo esc_attr( $menu_background ); ?>}
		<?php } ?>
		<?php if ( $menu_links ) { ?>
			#sunshine--header li a { color: <?php echo esc_attr( $menu_links ); ?>}
		<?php } ?>
		<?php if ( $main_background ) { ?>
			body { background-color: <?php echo esc_attr( $main_background ); ?>}
		<?php } ?>
	</style>
	<?php
}

add_filter( 'sunshine_main_menu', 'sunshine_theme_cover_main_menu' );
function sunshine_theme_cover_main_menu( $menu ) {

	unset( $menu['50'] ); // Remove the Checkout link.

	if ( is_user_logged_in() ) {
		unset( $menu['110'] ); // Remove logout link.
	}

	if ( ( SPC()->frontend->is_image() && SPC()->frontend->current_image->can_access() ) || SPC()->frontend->is_store() ) {

		$menu[1] = array(
			/* translators: %s is the gallery name */
			'name'  => sprintf( __( 'Return to %s', 'sunshine-photo-cart' ), SPC()->frontend->current_gallery->get_name() ),
			'url'   => SPC()->frontend->current_gallery->get_permalink(),
			'class' => 'sunshine--gallery-return',
		);

	}

	if ( SPC()->frontend->is_gallery() && SPC()->frontend->current_gallery->get_parent_gallery_id() ) {
		$parent_gallery = SPC()->frontend->current_gallery->get_parent_gallery();
		if ( $parent_gallery->can_access() ) {
			$menu[1] = array(
				/* translators: %s is the parent gallery name */
				'name'  => sprintf( __( 'Return to %s', 'sunshine-photo-cart' ), $parent_gallery->get_name() ),
				'class' => 'sunshine--gallery-return',
				'url'   => $parent_gallery->get_permalink(),
			);
		}
	}

	return $menu;
}
