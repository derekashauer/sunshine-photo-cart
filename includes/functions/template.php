<?php
/******************************
	GETTING URLS
 ******************************/
function sunshine_url( $page = 'home', $args = array() ) {
	return sunshine_get_page_permalink( $page );
}

function sunshine_current_url( $echo = 1 ) {
	$url = $_SERVER['REQUEST_URI'];
	$url = apply_filters( 'sunshine_current_url', $url );
	if ( $echo ) {
		echo esc_url( $url );
	} else {
		return $url;
	}
}

function sunshine_get_page( $page ) {
	return SPC()->get_page( $page );
}

function sunshine_get_page_permalink( $page ) {
	$page_id = sunshine_get_page( $page );
	if ( $page_id ) {
		return get_permalink( $page_id );
	}
	return false;
}

function sunshine_locate_template( $template, $args = array(), $base = '' ) {

	// See if it is in the WordPress theme
	$located_template = locate_template( 'sunshine/' . $template . '.php', false, true, $args );
	if ( $located_template ) {
		return $located_template;
	}

	if ( $base ) {
		$template_path = trailingslashit( $base ) . $template . '.php';
		if ( file_exists( $template_path ) ) {
			return $template_path;
		}
	}

	// Next try the Sunshine theme.
	$theme = '';
	if ( ! empty( SPC()->frontend ) && SPC()->frontend->is_gallery() ) {
		// Get the gallery theme option.
		$theme = SPC()->get_option( 'theme_gallery' );
	}
	if ( empty( $theme ) ) {
		// Use the general theme option.
		$theme = SPC()->get_option( 'theme', 'theme' );
	}
	$base          = SUNSHINE_PHOTO_CART_PATH . 'themes/' . $theme;
	$template_path = trailingslashit( $base ) . $template . '.php';
	if ( file_exists( $template_path ) ) {
		return $template_path;
	}

	// Now check default templates path.
	$base          = SUNSHINE_PHOTO_CART_PATH . 'templates';
	$template_path = trailingslashit( $base ) . $template . '.php';
	if ( file_exists( $template_path ) ) {
		return $template_path;
	}

	return false;

}

function sunshine_get_template( $template, $args = array(), $base = '' ) {
	$located_template = sunshine_locate_template( $template, $args, $base );
	if ( $located_template ) {
		if ( ! empty( $args ) && is_array( $args ) ) {
			extract( $args );
		}
		do_action( 'sunshine_template_before_' . $template, $args );
		include $located_template;
		do_action( 'sunshine_template_after_' . $template, $args );
		return;
	}
	return false;
}

function sunshine_get_template_html( $template, $args = array(), $base = '' ) {
	ob_start();
	sunshine_get_template( $template, $args, $base );
	return ob_get_clean();
}

// TODO: Finish
function sunshine_page_title( $echo = true ) {

	$page_title = apply_filters( 'sunshine_page_title', SPC()->frontend->get_page_title() );

	if ( $echo ) {
		echo esc_html( $page_title );
	} else {
		return $page_title;
	}

}

function sunshine_action_menu() {
	do_action( 'sunshine_before_action_menu' );
	$menu = array();
	$menu = apply_filters( 'sunshine_action_menu', $menu );
	if ( $menu ) {
		ksort( $menu );
		$menu_html_safe = '<nav class="sunshine--action-menu"><ul>';
		foreach ( $menu as $key => $item ) {
			$attributes = '';
			if ( isset( $item['attr'] ) ) {
				foreach ( $item['attr'] as $attr => $value ) {
					$attributes .= ' ' . esc_attr( $attr ) . '="' . esc_attr( $value ) . '"';
				}
			}
			$menu_html_safe .= '<li';
			if ( isset( $item['class'] ) ) {
				$menu_html_safe .= ' class="' . esc_attr( $item['class'] ) . ' sunshine--action-menu--item-' . esc_attr( $key ) . '"';
			}
			$menu_html_safe .= '>';
			if ( isset( $item['before_a'] ) ) {
				$menu_html_safe .= esc_html( $item['before_a'] );
			}
			if ( isset( $item['url'] ) ) {
				$menu_html_safe .= '<a href="' . esc_url( $item['url'] ) . '"';
				if ( isset( $item['a_class'] ) ) {
					$menu_html_safe .= ' class="' . esc_attr( $item['a_class'] ) . '" ';
				}
				$menu_html_safe .= $attributes;
				if ( isset( $item['target'] ) ) {
					$menu_html_safe .= ' target="' . esc_attr( $item['target'] ) . '" ';
				}
				$menu_html_safe .= '>';
			} else {
				$menu_html_safe .= '<button';
				if ( isset( $item['a_class'] ) ) {
					$menu_html_safe .= ' class="' . esc_attr( $item['a_class'] ) . '" ';
				}
				$menu_html_safe .= $attributes;
				$menu_html_safe .= '>';
			}
			if ( isset( $item['svg_inline'] ) ) {
				$menu_html_safe .= $item['svg_inline'];
			}
			if ( isset( $item['svg'] ) ) {
				$menu_html_safe .= sunshine_get_svg( $item['svg'] );
			}
			/*
			if ( isset( $item['icon'] ) )
				$menu_html .= '<i class="fa fa-'.$item['icon'].'"></i> ';
			*/
			if ( ! empty( $item['name'] ) ) {
				$menu_html_safe .= '<span class="sunshine--action-menu--name">' . esc_html( $item['name'] ) . '</span>';
			}
			if ( isset( $item['url'] ) ) {
				$menu_html_safe .= '</a>';
			} else {
				$menu_html_safe .= '</button>';
			}
			if ( isset( $item['after_a'] ) ) {
				$menu_html_safe .= esc_html( $item['after_a'] ) . '</li>';
			}
		}
		$menu_html_safe .= '</ul></nav>';
		// Everything above all do escaping, so we can safely output the result.
		echo $menu_html_safe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	do_action( 'sunshine_after_action_menu', $menu );
}


function sunshine_image_class( $image_id, $classes = array(), $echo = true ) {

	$cart_contents = SPC()->cart->get_cart();
	if ( ! empty( $cart_contents ) ) {
		foreach ( $cart_contents as $item ) {
			if ( ! empty( $item['image_id'] ) && $item['image_id'] == $image_id ) {
				$classes[] = 'sunshine--image--in-cart';
				break;
			}
		}
	}
	$comments = get_comments( array( 'post_id' => $image_id ) );
	if ( $comments ) {
		$classes[] = 'sunshine--image--has-comments';
	}

	if ( SPC()->customer->has_favorite( $image_id ) ) {
		$classes[] = 'sunshine--image--is-favorite';
	}

	$class_names = '';
	$classes     = apply_filters( 'sunshine_image_class', $classes, $image_id );
	if ( ! empty( $classes ) ) {
		$class_names = join( ' ', $classes );
	}
	$class_names = $class_names;
	if ( $echo ) {
		echo esc_attr( $class_names );
	} else {
		return $class_names;
	}

}

function sunshine_classes( $echo = true ) {
	global $post;

	$classes = array();
	if ( SPC()->frontend->is_image() ) {
		$classes[] = 'sunshine--image';
		$classes[] = 'sunshine--image-' . SPC()->frontend->current_image->get_id();
		if ( SPC()->customer->has_favorite( SPC()->frontend->current_image->get_id() ) ) {
			$classes[] = 'sunshine--image--is-favorite';
		}
		if ( SPC()->frontend->current_image->in_cart() ) {
			$classes[] = 'sunshine--image--in-cart';
		}
	} elseif ( SPC()->frontend->is_gallery() ) {
		$classes[] = 'sunshine--gallery';
		$classes[] = SPC()->frontend->current_gallery->post_name;
	} elseif ( SPC()->frontend->is_order() ) {
		$classes[] = 'sunshine--order';
	} elseif ( ! empty( $post ) && in_array( $post->ID, SPC()->pages ) ) {
		$page_class = array_search( $post->ID, SPC()->pages );
		if ( $page_class ) {
			$classes[] = 'sunshine--page--' . $page_class;
		}
	}

	if ( ! empty( SPC()->get_option( 'proofing' ) ) && SPC()->get_option( 'proofing' ) == 1 ) {
		$classes[] = 'sunshine--proofing';
	}

	$html = esc_attr( join( ' ', apply_filters( 'sunshine_classes', $classes ) ) );
	if ( $echo ) {
		echo esc_attr( $html );
	}
	return $html;
}

// BACKWARDS COMPAT
function sunshine_featured_image( $gallery_id = '', $size = 'sunshine-thumbnail', $echo = 1 ) {
	$gallery = sunshine_get_gallery( $gallery_id );
	return $gallery->featured_image( $size, $echo );
}

// BACKWARDS COMPAT
function sunshine_featured_image_id( $gallery_id = '' ) {
	global $post;
	if ( empty( $gallery_id ) ) {
		$gallery_id = $post->ID;
	}
	$gallery = sunshine_get_gallery( $gallery_id );
	return $gallery->get_featured_image_id();
}

// BACKWARDS COMPAT
function sunshine_is_gallery_expired( $gallery_id = '' ) {
	$gallery = sunshine_get_gallery( $gallery_id );
	return $gallery->is_expired();
}

function sunshine_gallery_columns() {
	return SPC()->get_option( 'columns' );
}

/*
function sunshine_gallery_rows() {
	SPC()->get_option( 'rows' );
}
*/

function sunshine_gallery_images_per_page() {
	return SPC()->get_option( 'per_page', 16 );
	// return SPC()->get_option( 'columns' ) * SPC()->get_option( 'rows' );
}

function sunshine_galleries_per_page() {
	return SPC()->get_option( 'galleries_per_page', 0 );
}

function sunshine_galleries_pagination_style() {
	return SPC()->get_option( 'galleries_pagination', SPC()->get_option( 'pagination' ) );
}

function sunshine_gallery_pagination( $gallery = '', $echo = true, $class = 'sunshine--pagination' ) {
	global $wp_query;

	if ( ( empty( $gallery ) && empty( SPC()->frontend->current_gallery ) ) || SPC()->frontend->is_search() ) {
		return false;
	}

	if ( empty( $gallery ) ) {
		$gallery = SPC()->frontend->current_gallery;
	}

	if ( ! $gallery->can_access() ) {
		return;
	}

	$image_count = $gallery->get_image_count();
	$per_page    = sunshine_gallery_images_per_page();

	if ( $image_count <= $per_page ) {
		return;
	}

	$format = SPC()->get_option( 'pagination' );

	$html = '<nav class="' . esc_attr( $class ) . ' sunshine--pagination--' . esc_attr( $format ) . '">';

	if ( empty( $format ) || $format == 'numbers' ) {

		$page_number          = ( isset( $_GET['pagination'] ) ) ? intval( $_GET['pagination'] ) : 1;
		$current_gallery_page = array( $gallery->get_id(), $page_number );
		SPC()->session->set( 'current_gallery_page', $current_gallery_page );

		$base_url = sunshine_current_url( false );
		$pages    = ceil( $image_count / $per_page );
		if ( $page_number > 1 ) {
			$prev_page = $page_number - 1;
			$url       = add_query_arg( 'pagination', $prev_page, $base_url );
			$html     .= '<a href="' . esc_url( $url ) . '">' . esc_html( apply_filters( 'sunshine_pagination_previous_label', '&laquo; ' . __( 'Previous', 'sunshine-photo-cart' ) ) ) . '</a> ';
		}
		for ( $i = 1; $i <= $pages; $i++ ) {
			$class = ( $page_number == $i || ( $page_number == 0 && $i == 1 ) ) ? 'current' : '';
			$url   = add_query_arg( 'pagination', $i, $base_url );
			$html .= '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $i ) . '</a> ';
		}
		if ( $page_number < $pages ) {
			$next_page = $page_number + 1;
			$url       = add_query_arg( 'pagination', $next_page, $base_url );
			$html     .= ' <a href="' . esc_url( $url ) . '">' . esc_html( apply_filters( 'sunshine_pagination_next_label', __( 'Next', 'sunshine-photo-cart' ) . '  &raquo;' ) ) . '</a>';
		}
	} else {

		$image_count = $gallery->get_image_count();
		$total_pages = ceil( $image_count / $per_page );

		$html .= '<button id="sunshine--pagination--load-more" class="sunshine--button button" data-gallery="' . esc_attr( $gallery->get_id() ) . '" data-page="1" data-total="' . esc_attr( $total_pages ) . '">' . esc_html__( 'Load more', 'sunshine-photo-cart' ) . '</button>';

	}

	$html .= '</nav>';

	if ( $echo ) {
		// Everything above all do escaping, so we can safely output the result.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		return $html;
	}

}

add_action( 'wp_ajax_sunshine_gallery_pagination', 'sunshine_gallery_pagination_load' );
add_action( 'wp_ajax_nopriv_sunshine_gallery_pagination', 'sunshine_gallery_pagination_load' );
function sunshine_gallery_pagination_load() {

	check_ajax_referer( 'sunshinephotocart', 'security' );

	$gallery = sunshine_get_gallery( intval( $_POST['gallery'] ) );
	$args    = array(
		'offset'         => sunshine_gallery_images_per_page() * intval( $_POST['page'] ),
		'posts_per_page' => sunshine_gallery_images_per_page(),
	);
	$images  = $gallery->get_images( $args );
	if ( $images ) {
		$html = '';
		foreach ( $images as $image ) {
			$html .= sunshine_get_template_html( 'gallery/image-item', array( 'image' => $image ) );
		}
		wp_send_json_success(
			array(
				'html' => $html,
			)
		);
	}

	wp_send_json_error();

}

function sunshine_galleries_pagination( $gallery_count = 0, $echo = true, $class = 'sunshine--pagination' ) {

	$per_page = sunshine_galleries_per_page();

	// If per_page is 0 or empty, no pagination needed
	if ( empty( $per_page ) || $per_page <= 0 ) {
		return;
	}

	if ( $gallery_count <= $per_page ) {
		return;
	}

	$format = sunshine_galleries_pagination_style();

	$html = '<nav class="' . esc_attr( $class ) . ' sunshine--pagination--' . esc_attr( $format ) . '">';

	if ( empty( $format ) || $format == 'numbers' ) {

		$page_number = ( isset( $_GET['galleries_pagination'] ) ) ? intval( $_GET['galleries_pagination'] ) : 1;

		$base_url = sunshine_current_url( false );
		$pages    = ceil( $gallery_count / $per_page );
		if ( $page_number > 1 ) {
			$prev_page = $page_number - 1;
			$url       = add_query_arg( 'galleries_pagination', $prev_page, $base_url );
			$html     .= '<a href="' . esc_url( $url ) . '">' . esc_html( apply_filters( 'sunshine_pagination_previous_label', '&laquo; ' . __( 'Previous', 'sunshine-photo-cart' ) ) ) . '</a> ';
		}
		for ( $i = 1; $i <= $pages; $i++ ) {
			$current_class = ( $page_number == $i || ( $page_number == 0 && $i == 1 ) ) ? 'current' : '';
			$url           = add_query_arg( 'galleries_pagination', $i, $base_url );
			$html         .= '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $current_class ) . '">' . esc_html( $i ) . '</a> ';
		}
		if ( $page_number < $pages ) {
			$next_page = $page_number + 1;
			$url       = add_query_arg( 'galleries_pagination', $next_page, $base_url );
			$html     .= ' <a href="' . esc_url( $url ) . '">' . esc_html( apply_filters( 'sunshine_pagination_next_label', __( 'Next', 'sunshine-photo-cart' ) . '  &raquo;' ) ) . '</a>';
		}
	} else {

		$total_pages = ceil( $gallery_count / $per_page );

		$html .= '<button id="sunshine--galleries-pagination--load-more" class="sunshine--button button" data-page="1" data-total="' . esc_attr( $total_pages ) . '">' . esc_html__( 'Load more', 'sunshine-photo-cart' ) . '</button>';

	}

	$html .= '</nav>';

	if ( $echo ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		return $html;
	}

}

add_action( 'wp_ajax_sunshine_galleries_pagination', 'sunshine_galleries_pagination_load' );
add_action( 'wp_ajax_nopriv_sunshine_galleries_pagination', 'sunshine_galleries_pagination_load' );
function sunshine_galleries_pagination_load() {

	check_ajax_referer( 'sunshinephotocart', 'security' );

	$per_page   = sunshine_galleries_per_page();
	$page       = intval( $_POST['page'] );
	$offset     = $per_page * $page;
	$galleries  = sunshine_get_galleries( array( 'post_parent' => 0 ), 'view' );

	if ( $galleries ) {
		$galleries_slice = array_slice( $galleries, $offset, $per_page );
		if ( ! empty( $galleries_slice ) ) {
			$html = '';
			foreach ( $galleries_slice as $gallery ) {
				$html .= sunshine_get_template_html( 'galleries/gallery-item', array( 'gallery' => $gallery ) );
			}
			wp_send_json_success(
				array(
					'html' => $html,
				)
			);
		}
	}

	wp_send_json_error();

}

// BACKWARDS COMPAT
function sunshine_product_class( $product_id = '' ) {
	if ( $product_id ) {
		$product = sunshine_get_product( $product_id );
		$product->classes();
	}
}

function sunshine_main_menu( $echo = true ) {
	$menu = array();
	$menu = apply_filters( 'sunshine_main_menu', $menu );
	if ( $menu ) {
		ksort( $menu );
		// $menu_html = '<div>';
		$unique_id       = 'sunshine--main-menu--toggle--' . uniqid();
		$menu_html_safe  = '<div id="sunshine--main-menu" class="sunshine--main-menu">';
		$menu_html_safe .= '<input type="checkbox" name="sunshine_main_menu_toggle" id="' . esc_attr( $unique_id ) . '" />';
		$menu_html_safe .= '<label for="' . esc_attr( $unique_id ) . '" class="sunshine--main-menu--toggle sunshine--main-menu--open">' . __( 'Menu', 'sunshine-photo-cart' ) . '</label>';
		$menu_html_safe .= '<label for="' . esc_attr( $unique_id ) . '" class="sunshine--main-menu--toggle sunshine--main-menu--close">' . __( 'Close', 'sunshine-photo-cart' ) . '</label>';
		$menu_html_safe .= '<nav aria-label="' . __( 'Gallery navigation', 'sunshine-photo-cart' ) . '"><ul>';
		foreach ( $menu as $item ) {
			$attributes = '';
			if ( isset( $item['attr'] ) ) {
				foreach ( $item['attr'] as $attr => $value ) {
					$attributes .= ' ' . esc_attr( $attr ) . '="' . esc_attr( $value ) . '"';
				}
			}
			$menu_html_safe .= '<li';
			if ( isset( $item['class'] ) ) {
				$menu_html_safe .= ' class="' . esc_attr( $item['class'] ) . '"';
			}
			$menu_html_safe .= '>';
			if ( isset( $item['before_a'] ) ) {
				$menu_html_safe .= wp_kses_post( $item['before_a'] );
			}
			if ( ! empty( $item['url'] ) ) {
				$menu_html_safe .= '<a href="' . esc_url( $item['url'] ) . '"';
				if ( isset( $item['a_class'] ) ) {
					$menu_html_safe .= ' class="' . esc_attr( $item['a_class'] ) . '" ';
				}
				$menu_html_safe .= $attributes;
				if ( isset( $item['target'] ) ) {
					$menu_html_safe .= ' target="' . esc_attr( $item['target'] ) . '" ';
				}
				$menu_html_safe .= '>';
			} else {
				$menu_html_safe .= '<button';
				if ( isset( $item['a_class'] ) ) {
					$menu_html_safe .= ' class="' . esc_attr( $item['a_class'] ) . '" ';
				}
				$menu_html_safe .= $attributes;
				$menu_html_safe .= '>';
			}
			if ( isset( $item['icon'] ) ) {
				$menu_html_safe .= '<i class="' . esc_attr( $item['icon'] ) . '"></i> ';
			}
			if ( isset( $item['name'] ) ) {
				$menu_html_safe .= '<span class="sunshine--main-menu--name">' . esc_html( $item['name'] ) . '</span>';
			}
			if ( ! empty( $item['url'] ) ) {
				$menu_html_safe .= '</a>';
			} else {
				$menu_html_safe .= '</button>';
			}
			if ( isset( $item['after_a'] ) ) {
				$menu_html_safe .= wp_kses_post( $item['after_a'] );
			}
			$menu_html_safe .= '</li>';
		}
		$menu_html_safe .= '</ul></nav></div>';

		// $menu_html .=  '</div>';

		if ( $echo ) {
			// Everything above all do escaping, so we can safely output the result.
			echo $menu_html_safe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			return $menu_html_safe;
		}
	}
}

function sunshine_image_menu( $image = '', $echo = true ) {

	if ( empty( $image ) ) {
		$image = SPC()->frontend->current_image;
	}
	if ( empty( $image ) ) {
		return false;
	}
	$menu = array();
	$menu = apply_filters( 'sunshine_image_menu', $menu, $image );
	if ( $menu ) {
		ksort( $menu );
		$menu_html_safe = '<div class="sunshine--image-menu"><ul>';
		foreach ( $menu as $item ) {
			$attributes = '';
			if ( isset( $item['attr'] ) ) {
				foreach ( $item['attr'] as $attr => $value ) {
					$attributes .= ' ' . esc_attr( $attr ) . '="' . esc_attr( $value ) . '"';
				}
			}
			$menu_html_safe .= '<li';
			if ( isset( $item['class'] ) ) {
				$menu_html_safe .= ' class="' . esc_attr( $item['class'] ) . '"';
			}
			$menu_html_safe .= '>';
			if ( isset( $item['before_a'] ) ) {
				$menu_html_safe .= wp_kses_post( $item['before_a'] );
			}
			if ( ! empty( $item['url'] ) ) {
				$menu_html_safe .= '<a href="' . esc_url( $item['url'] ) . '"';
				if ( isset( $item['a_class'] ) ) {
					$menu_html_safe .= ' class="' . esc_attr( $item['a_class'] ) . '" ';
				}
				$menu_html_safe .= $attributes;
				if ( isset( $item['target'] ) ) {
					$menu_html_safe .= ' target="' . esc_attr( $item['target'] ) . '" ';
				}
				$menu_html_safe .= '>';
			} else {
				$menu_html_safe .= '<button';
				if ( isset( $item['a_class'] ) ) {
					$menu_html_safe .= ' class="' . esc_attr( $item['a_class'] ) . '" ';
				}
				$menu_html_safe .= $attributes;
				$menu_html_safe .= '>';
			}
			if ( isset( $item['svg_inline'] ) ) {
				$menu_html_safe .= wp_kses_post( $item['svg_inline'] );
			}
			if ( isset( $item['svg'] ) ) {
				$menu_html_safe .= sunshine_get_svg( $item['svg'] );
			}
			if ( isset( $item['icon'] ) ) {
				$menu_html_safe .= '<i class="' . esc_attr( $item['icon'] ) . '"></i> ';
			}
			if ( isset( $item['name'] ) ) {
				$menu_html_safe .= '<span class="sunshine--image-menu--name">' . esc_html( $item['name'] ) . '</span>';
			}
			if ( ! empty( $item['url'] ) ) {
				$menu_html_safe .= '</a>';
			} else {
				$menu_html_safe .= '</button>';
			}
			if ( isset( $item['after_a'] ) ) {
				$menu_html_safe .= wp_kses_post( $item['after_a'] );
			}
			$menu_html_safe .= '</li>';
		}
		$menu_html_safe .= '</ul></div>';
		if ( $echo ) {
			// Everything above all do escaping, so we can safely output the result.
			echo $menu_html_safe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			return $menu_html_safe;
		}
	}
}

function sunshine_image_status( $image ) {
	$status   = array();
	$status[] = '<span class="sunshine--image--is-favorite"></span>';
	$status[] = '<span class="sunshine--image--in-cart"></span>';
	$status[] = '<span class="sunshine--image--has-comments"></span>';
	$status = apply_filters( 'sunshine_image_status', $status, $image );
	if ( ! empty( $status ) ) {
		echo '<div class="sunshine--image-status">' . wp_kses_post( join( '', $status ) ) . '</div>';
	}
}

function sunshine_image_nav( $image = '' ) {
	if ( ! SPC()->frontend->is_image() ) {
		return false;
	}

	if ( empty( $image ) ) {
		$image = SPC()->frontend->current_image;
	}

	if ( ! $image->can_access() ) {
		return false;
	}
	?>
	<nav id="sunshine--image--nav">
		<span id="sunshine-prev"><?php sunshine_adjacent_image_link( $image, true ); ?></span>
		<span id="sunshine-next"><?php sunshine_adjacent_image_link( $image, false ); ?></span>
	</nav>
	<?php
}

function sunshine_get_svg( $file ) {
	if ( file_exists( $file ) ) {
		return file_get_contents( $file );
	} else {
		$path = SUNSHINE_PHOTO_CART_PATH . 'assets/images/' . sanitize_text_field( $file ) . '.svg';
		if ( file_exists( $path ) ) {
			return file_get_contents( $path );
		}
	}
	return false;
}

// BACKWARDS COMPAT
function sunshine_cart_items() {
	return SPC()->cart->get_content();
}

function sunshine_head() {
	do_action( 'sunshine_head' );
}

add_action( 'sunshine_checkout_start_form', 'sunshine_checkout_login_form' );
function sunshine_checkout_login_form() {

	if ( is_user_logged_in() || SPC()->cart->get_item_count() == 0 ) {
		return;
	}
	?>

	<div id="sunshine--checkout--login">
		<?php esc_html_e( 'Already have an account?', 'sunshine-photo-cart' ); ?>
		<a href="#login" class="sunshine--open-modal" data-hook="login"><?php esc_html_e( 'Click here to login', 'sunshine-photo-cart' ); ?></a>
	</div>

	<?php
}

function sunshine_logo() {
	if ( SPC()->get_option( 'template_logo' ) > 0 ) {
		echo wp_kses_post( wp_get_attachment_image( SPC()->get_option( 'template_logo' ), 'full' ) );
	} else {
		echo esc_html( get_bloginfo( 'name' ) );
	}
}

function sunshine_sidebar() {
	// No longer used
	return;
}

function sunshine_adjacent_image_link( $image, $prev = true, $echo = true ) {

	$image_ids = $image->gallery->get_images( array( 'posts_per_page' => -1 ), true );
	if ( count( $image_ids ) <= 1 ) {
		return;
	}

	$link_image_id = 0;

	$current_image_id = 0;
	foreach ( $image_ids as $k => $image_id ) {
		if ( $image_id == $image->get_id() ) {
			$current_image_id = $image_id;
			break;
		}
	}

	// Do we want the one before or after
	if ( $prev ) {
		$k        -= 1;
		$direction = 'prev';
	} else {
		$k        += 1;
		$direction = 'next';
	}

	// Let's determine which image ID we want here
	if ( array_key_exists( $k, $image_ids ) ) { // Key we are looking for exists!
		$link_image_id = $image_ids[ $k ];
	} else { // Doesn't exist, boo
		// If we are looking for previous, then there are no more in front and we want to then get the last one to circle around backwards
		if ( $prev ) {
			$link_image_id = end( $image_ids );
		} else { // If looking for the next, loop around and get the first one
			$link_image_id = $image_ids[0];
		}
	}

	if ( $link_image_id ) {
		$link_image = sunshine_get_image( $link_image_id );
		if ( ! $link_image->can_view() ) {
			return false;
		}
		$link_url = $link_image->get_permalink();
		if ( $prev ) {
			$label = apply_filters( 'sunshine_image_previous_label', '&laquo; ' . __( 'Previous', 'sunshine-photo-cart' ) );
		} else {
			$label = apply_filters( 'sunshine_image_next_label', __( 'Next', 'sunshine-photo-cart' ) . ' &raquo;' );
		}
		$link = '<a href="' . esc_url( $link_url ) . '" class="sunshine-adjacent-link sunshine-adjacent-link-' . esc_attr( $direction ) . '">' . esc_html( $label ) . '</a>';
		if ( $echo ) {
			echo wp_kses_post( $link );
		} else {
			return $link;
		}
	}

}

function sunshine_breadcrumb( $divider = ' / ', $echo = true ) {

	if ( ! empty( SPC()->get_option( 'disable_breadcrumbs' ) ) && SPC()->get_option( 'disable_breadcrumbs' ) ) {
		return;
	}
	$breadcrumb_safe = '<a href="' . esc_url( get_permalink( SPC()->get_option( 'page' ) ) ) . '">' . esc_html( get_the_title( SPC()->get_option( 'page' ) ) ) . '</a>';
	if ( SPC()->frontend->is_gallery() ) {
		$breadcrumb_safe .= sunshine_breadcrumb_gallery( SPC()->frontend->current_gallery, $divider );
	} elseif ( SPC()->frontend->is_image() ) {
		$breadcrumb_safe .= sunshine_breadcrumb_gallery( SPC()->frontend->current_gallery, $divider );
		$breadcrumb_safe .= $divider . '<a href="' . esc_url( SPC()->frontend->current_image->get_permalink() ) . '">' . esc_html( SPC()->frontend->current_image->get_name() ) . '</a>';
	}
	if ( $echo ) {
		// Everything above all do escaping, so we can safely output the result.
		echo $breadcrumb_safe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return;
	}
	return $breadcrumb_safe;
}

// Adds the parent gallery this current gallery to breadcrumb, iterates on itself for full hierarchy of galleries
function sunshine_breadcrumb_gallery( $gallery, $divider = ' / ' ) {

	$parent = $gallery->get_parent_gallery();
	if ( empty( $parent ) ) {
		$breadcrumb = $divider . '<a href="' . esc_url( $gallery->get_permalink() ) . '">' . esc_html( $gallery->get_name() ) . '</a>';
	} else {
		$breadcrumb  = sunshine_breadcrumb_gallery( $parent, $divider );
		$breadcrumb .= $divider . '<a href="' . esc_url( $gallery->get_permalink() ) . '">' . esc_html( $gallery->get_name() ) . '</a>';
	}
	return $breadcrumb;

}

function sunshine_gallery_password_form( $echo = true ) {

	$form = sunshine_get_template_html( 'shortcodes/password' );
	$form = apply_filters( 'sunshine_shortcode_gallery_password_form', $form );

	if ( $echo ) {
		echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		return $form;
	}

}

/* TODO: Redo: make into template, use gallery class functions */
function sunshine_gallery_expiration_notice() {
	if ( SPC()->frontend->is_gallery() ) {
		$end_date = get_post_meta( SPC()->frontend->current_gallery->get_id(), 'sunshine_gallery_end_date', true );
		if ( $end_date != '' && $end_date > current_time( 'timestamp' ) ) {
			echo '<div id="sunshine--gallery--expiration-notice">';
			/* translators: %s is the gallery expiration date and time */
			echo wp_kses_post( apply_filters( 'sunshine_gallery_expiration_notice', sprintf( __( 'This gallery is set to expire on <strong>%s</strong>', 'sunshine-photo-cart' ), date_i18n( get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' ), $end_date ) ) ) );
			echo '</div>';
		}
	}
}

/*
* 	Show a search form
*
*	@return void
*/
function sunshine_search_form( $gallery = '', $echo = true ) {

	if ( empty( $gallery ) && ! is_admin() && SPC()->frontend->is_gallery() ) {
		$gallery = SPC()->frontend->current_gallery;
	}

	$form = sunshine_get_template_html( 'shortcodes/search', array( 'gallery' => $gallery ) );
	$form = apply_filters( 'sunshine_shortcode_search_form', $form );

	if ( $echo ) {
		echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		return $form;
	}

}

function sunshine_search( $gallery, $echo = true ) {
	return sunshine_search_form( $gallery, $echo );
}


add_action( 'sunshine_after_cart', 'sunshine_gallery_return', 5 );
// add_action( 'sunshine_after_favorites', 'sunshine_gallery_return', 5 );
function sunshine_gallery_return() {
	$last_gallery = SPC()->session->get( 'last_gallery' );
	if ( ! empty( $last_gallery ) ) {
		$gallery = sunshine_get_gallery( $last_gallery );
		if ( empty( $gallery ) ) {
			return;
		}
		$url                  = $gallery->get_permalink();
		$current_gallery_page = SPC()->session->get( 'current_gallery_page' );
		if ( ! empty( $current_gallery_page ) ) {
			$url = add_query_arg( 'pagination', $current_gallery_page[1], $url );
		}
		?>
		<div id="sunshine--cart--gallery-return">
			<?php /* translators: %s is the gallery name */ ?>
			<a href="<?php echo esc_url( $url ); ?>"><?php echo sprintf( esc_html__( 'Return to gallery "%s"', 'sunshine-photo-cart' ), esc_html( $gallery->get_name() ) ); ?></a> // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		</div>
		<?php
	}
}

function sunshine_get_required_notice() {
	return apply_filters( 'sunshine_required_notice', '<span class="sunshine--required">* ' . esc_html__( 'Required', 'sunshine-photo-cart' ) . '</span>' );
}
?>
