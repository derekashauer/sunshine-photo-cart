<?php
/**
 * Sunshine Gallery Navigator
 *
 * Modal-based hierarchical gallery navigator for the admin area.
 *
 * @package Sunshine_Photo_Cart
 */

add_action( 'admin_enqueue_scripts', 'sunshine_gallery_navigator_enqueue_scripts' );
function sunshine_gallery_navigator_enqueue_scripts( $hook ) {
	if ( 'edit.php' !== $hook || ! isset( $_GET['post_type'] ) || 'sunshine-gallery' !== $_GET['post_type'] ) {
		return;
	}

	wp_enqueue_style( 'sunshine-gallery-navigator', SUNSHINE_PHOTO_CART_URL . 'assets/css/admin-gallery-navigator.css', array(), SUNSHINE_PHOTO_CART_VERSION );
	wp_enqueue_script( 'sunshine-gallery-navigator', SUNSHINE_PHOTO_CART_URL . 'assets/js/admin-gallery-navigator.js', array( 'jquery', 'jquery-ui-sortable' ), SUNSHINE_PHOTO_CART_VERSION, true );

	wp_localize_script(
		'sunshine-gallery-navigator',
		'sunshineGalleryNavigator',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'sunshine_gallery_navigator' ),
		)
	);
}

add_action( 'manage_posts_extra_tablenav', 'sunshine_gallery_navigator_button' );
function sunshine_gallery_navigator_button( $which ) {
	if ( 'top' !== $which ) {
		return;
	}

	$screen = get_current_screen();
	if ( 'edit-sunshine-gallery' !== $screen->id ) {
		return;
	}

	?>
	<div class="alignleft actions">
		<button type="button" id="sunshine-gallery-navigator-open" class="button">
			<span class="dashicons dashicons-category" style="margin-top: 3px;"></span>
			<?php esc_html_e( 'Gallery Navigator', 'sunshine-photo-cart' ); ?>
		</button>
	</div>

	<!-- Gallery Navigator Modal -->
	<div id="sunshine-gallery-navigator-modal" style="display: none;">
		<div class="sunshine-gallery-navigator-overlay"></div>
		<div class="sunshine-gallery-navigator-container">
			<div class="sunshine-gallery-navigator-header">
				<h2><?php esc_html_e( 'Gallery Navigator', 'sunshine-photo-cart' ); ?></h2>
				<button type="button" class="sunshine-gallery-navigator-close">
					<span class="dashicons dashicons-no"></span>
				</button>
			</div>
			<div class="sunshine-gallery-navigator-search">
				<input type="search" id="sunshine-gallery-navigator-search-input" placeholder="<?php esc_attr_e( 'Search galleries...', 'sunshine-photo-cart' ); ?>" />
				<button type="button" id="sunshine-gallery-navigator-search-clear" style="display: none;" title="<?php esc_attr_e( 'Clear search', 'sunshine-photo-cart' ); ?>">
					<span class="dashicons dashicons-dismiss"></span>
				</button>
			</div>
			<div class="sunshine-gallery-navigator-content">
				<div class="sunshine-gallery-navigator-loading" style="display: none;">
					<span class="spinner is-active"></span>
				</div>
				<div id="sunshine-gallery-navigator-list"></div>
			</div>
		</div>
	</div>
	<?php
}

add_action( 'wp_ajax_sunshine_gallery_navigator_load', 'sunshine_gallery_navigator_load' );
function sunshine_gallery_navigator_load() {

	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sunshine_gallery_navigator' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed', 'sunshine-photo-cart' ) ) );
	}

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied', 'sunshine-photo-cart' ) ) );
	}

	$parent_id   = isset( $_POST['parent_id'] ) ? intval( $_POST['parent_id'] ) : 0;
	$search_term = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';

	try {
		if ( ! empty( $search_term ) ) {
			$html = sunshine_gallery_navigator_search_results( $search_term );
		} else {
			$html = sunshine_gallery_navigator_render_galleries( $parent_id );
		}

		wp_send_json_success( array( 'html' => $html ) );
	} catch ( Exception $e ) {
		wp_send_json_error( array( 'message' => $e->getMessage() ) );
	}
}

function sunshine_gallery_navigator_render_galleries( $parent_id = 0, $level = 0 ) {
	$args = array(
		'post_type'      => 'sunshine-gallery',
		'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
		'post_parent'    => $parent_id,
		'posts_per_page' => -1,
		'orderby'        => 'menu_order',
		'order'          => 'ASC',
	);

	$galleries = get_posts( $args );

	if ( empty( $galleries ) ) {
		if ( 0 === $parent_id ) {
			return '<p class="sunshine-gallery-navigator-empty">' . esc_html__( 'No galleries found.', 'sunshine-photo-cart' ) . '</p>';
		}
		return '';
	}

	$html = '<ul class="sunshine-gallery-navigator-items" data-level="' . esc_attr( $level ) . '" data-parent-id="' . esc_attr( $parent_id ) . '">';

	foreach ( $galleries as $gallery_post ) {
		$gallery        = sunshine_get_gallery( $gallery_post );
		$children_count = sunshine_gallery_navigator_count_children( $gallery->get_id() );

		$classes = array( 'sunshine-gallery-navigator-item' );
		if ( $children_count > 0 ) {
			$classes[] = 'has-children';
		}
		if ( $gallery->is_expired() ) {
			$classes[] = 'expired';
		}

		$html .= '<li class="' . esc_attr( join( ' ', $classes ) ) . '" data-gallery-id="' . esc_attr( $gallery->get_id() ) . '" data-parent-id="' . esc_attr( $parent_id ) . '">';

		$html .= '<div class="sunshine-gallery-navigator-item-content">';

		// Drag handle
		$html .= '<span class="sunshine-gallery-navigator-drag-handle" title="' . esc_attr__( 'Drag to reorder', 'sunshine-photo-cart' ) . '">
			<span class="dashicons dashicons-menu"></span>
		</span>';

		// Toggle arrow for children
		if ( $children_count > 0 ) {
			$html .= '<button class="sunshine-gallery-navigator-toggle" data-gallery-id="' . esc_attr( $gallery->get_id() ) . '" aria-expanded="false">
				<span class="dashicons dashicons-arrow-right"></span>
			</button>';
		} else {
			$html .= '<span class="sunshine-gallery-navigator-spacer"></span>';
		}

		// Gallery name
		$html .= '<span class="sunshine-gallery-navigator-name">';
		$html .= esc_html( $gallery->get_name() );

		// Status indicators
		if ( 'password' === $gallery->get_status() ) {
			$html .= ' <span class="dashicons dashicons-lock" title="' . esc_attr__( 'Password Protected', 'sunshine-photo-cart' ) . '"></span>';
		} elseif ( 'private' === $gallery->get_status() ) {
			$html .= ' <span class="dashicons dashicons-hidden" title="' . esc_attr__( 'Private', 'sunshine-photo-cart' ) . '"></span>';
		}

		if ( 'draft' === $gallery_post->post_status ) {
			$html .= ' <span class="sunshine-gallery-navigator-draft">(' . esc_html__( 'Draft', 'sunshine-photo-cart' ) . ')</span>';
		}

		if ( $gallery->is_expired() ) {
			$html .= ' <span class="sunshine-gallery-navigator-expired">' . esc_html__( 'Expired', 'sunshine-photo-cart' ) . '</span>';
		}

		$html .= '</span>';

		// Meta info
		$html .= '<span class="sunshine-gallery-navigator-meta">';
		$html .= esc_html( $gallery->get_image_count() ) . ' ' . esc_html__( 'images', 'sunshine-photo-cart' );
		if ( $children_count > 0 ) {
			$html .= ' | ' . intval( $children_count ) . ' ' . esc_html( _n( 'subgallery', 'subgalleries', $children_count, 'sunshine-photo-cart' ) );
		}
		$html .= '</span>';

		// Actions
		$html .= '<span class="sunshine-gallery-navigator-actions">';
		$html .= '<a href="' . esc_url( admin_url( 'post.php?post=' . $gallery->get_id() . '&action=edit' ) ) . '" class="button button-small button-primary">' . esc_html__( 'Edit', 'sunshine-photo-cart' ) . '</a>';
		$html .= '</span>';

		$html .= '</div>'; // .sunshine-gallery-navigator-item-content

		// Children container (loaded via AJAX when expanded)
		if ( $children_count > 0 ) {
			$html .= '<div class="sunshine-gallery-navigator-children" style="display: none;"></div>';
		}

		$html .= '</li>';
	}

	$html .= '</ul>';

	return $html;
}

add_action( 'wp_ajax_sunshine_gallery_navigator_reorder', 'sunshine_gallery_navigator_reorder' );
function sunshine_gallery_navigator_reorder() {
	check_ajax_referer( 'sunshine_gallery_navigator', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( __( 'Permission denied', 'sunshine-photo-cart' ) );
	}

	if ( empty( $_POST['order'] ) || ! is_array( $_POST['order'] ) ) {
		wp_send_json_error( __( 'Invalid order data', 'sunshine-photo-cart' ) );
	}

	$order     = array_map( 'intval', $_POST['order'] );
	$parent_id = isset( $_POST['parent_id'] ) ? intval( $_POST['parent_id'] ) : 0;

	// Update menu_order for each gallery
	$menu_order = 0;
	foreach ( $order as $gallery_id ) {
		wp_update_post(
			array(
				'ID'         => $gallery_id,
				'menu_order' => $menu_order,
			)
		);
		$menu_order++;
	}

	wp_send_json_success( array( 'message' => __( 'Gallery order updated', 'sunshine-photo-cart' ) ) );
}

function sunshine_gallery_navigator_search_results( $search_term ) {
	// Find all galleries matching the search term
	$args = array(
		'post_type'      => 'sunshine-gallery',
		'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
		'posts_per_page' => -1,
		's'              => $search_term,
		'orderby'        => 'title',
		'order'          => 'ASC',
	);

	$search_results = get_posts( $args );

	if ( empty( $search_results ) ) {
		return '<p class="sunshine-gallery-navigator-empty">' . esc_html__( 'No galleries found matching your search.', 'sunshine-photo-cart' ) . '</p>';
	}

	$html  = '<div class="sunshine-gallery-navigator-search-results">';
	$html .= '<p class="sunshine-gallery-navigator-search-info">';
	/* translators: %1$d is the number of results, %2$s is the search term */
	$html .= sprintf( esc_html( _n( '%1$d gallery found for "%2$s"', '%1$d galleries found for "%2$s"', count( $search_results ), 'sunshine-photo-cart' ) ), count( $search_results ), esc_html( $search_term ) );
	$html .= '</p>';

	$html .= '<ul class="sunshine-gallery-navigator-items sunshine-gallery-navigator-search-items">';

	foreach ( $search_results as $gallery_post ) {
		$gallery = sunshine_get_gallery( $gallery_post );

		if ( empty( $gallery ) || empty( $gallery->get_id() ) ) {
			continue;
		}

		$children_count = sunshine_gallery_navigator_count_children( $gallery->get_id() );

		// Get parent hierarchy
		$parents = sunshine_gallery_navigator_get_parents( $gallery->get_id() );

		// Show breadcrumb if has parents
		if ( ! empty( $parents ) ) {
			$breadcrumb = array();
			foreach ( $parents as $parent_id ) {
				$parent_gallery = sunshine_get_gallery( $parent_id );
				if ( $parent_gallery && $parent_gallery->get_id() ) {
					$breadcrumb[] = $parent_gallery->get_name();
				}
			}
			if ( ! empty( $breadcrumb ) ) {
				$html .= '<li class="sunshine-gallery-navigator-breadcrumb">';
				$html .= '<span class="dashicons dashicons-arrow-right-alt2"></span> ';
				$html .= esc_html( join( ' › ', $breadcrumb ) );
				$html .= '</li>';
			}
		}

		$classes = array( 'sunshine-gallery-navigator-item', 'search-match' );
		if ( $children_count > 0 ) {
			$classes[] = 'has-children';
		}
		if ( $gallery->is_expired() ) {
			$classes[] = 'expired';
		}

		$parent_id = $gallery->get_parent_gallery_id();
		$html     .= '<li class="' . esc_attr( join( ' ', $classes ) ) . '" data-gallery-id="' . esc_attr( $gallery->get_id() ) . '" data-parent-id="' . esc_attr( $parent_id ) . '">';

		$html .= '<div class="sunshine-gallery-navigator-item-content">';

		// Drag handle
		$html .= '<span class="sunshine-gallery-navigator-drag-handle" title="' . esc_attr__( 'Drag to reorder', 'sunshine-photo-cart' ) . '">
			<span class="dashicons dashicons-menu"></span>
		</span>';

		// Toggle arrow for children
		if ( $children_count > 0 ) {
			$html .= '<button class="sunshine-gallery-navigator-toggle" data-gallery-id="' . esc_attr( $gallery->get_id() ) . '" aria-expanded="false">
				<span class="dashicons dashicons-arrow-right"></span>
			</button>';
		} else {
			$html .= '<span class="sunshine-gallery-navigator-spacer"></span>';
		}

		// Gallery name
		$html .= '<span class="sunshine-gallery-navigator-name">';
		$html .= esc_html( $gallery->get_name() );

		// Status indicators
		if ( 'password' === $gallery->get_status() ) {
			$html .= ' <span class="dashicons dashicons-lock" title="' . esc_attr__( 'Password Protected', 'sunshine-photo-cart' ) . '"></span>';
		} elseif ( 'private' === $gallery->get_status() ) {
			$html .= ' <span class="dashicons dashicons-hidden" title="' . esc_attr__( 'Private', 'sunshine-photo-cart' ) . '"></span>';
		}

		if ( 'draft' === $gallery_post->post_status ) {
			$html .= ' <span class="sunshine-gallery-navigator-draft">(' . esc_html__( 'Draft', 'sunshine-photo-cart' ) . ')</span>';
		}

		if ( $gallery->is_expired() ) {
			$html .= ' <span class="sunshine-gallery-navigator-expired">' . esc_html__( 'Expired', 'sunshine-photo-cart' ) . '</span>';
		}

		$html .= '</span>';

		// Meta info
		$html .= '<span class="sunshine-gallery-navigator-meta">';
		$html .= esc_html( $gallery->get_image_count() ) . ' ' . esc_html__( 'images', 'sunshine-photo-cart' );
		if ( $children_count > 0 ) {
			$html .= ' | ' . intval( $children_count ) . ' ' . esc_html( _n( 'subgallery', 'subgalleries', $children_count, 'sunshine-photo-cart' ) );
		}
		$html .= '</span>';

		// Actions
		$html .= '<span class="sunshine-gallery-navigator-actions">';
		$html .= '<a href="' . esc_url( admin_url( 'post.php?post=' . $gallery->get_id() . '&action=edit' ) ) . '" class="button button-small button-primary">' . esc_html__( 'Edit', 'sunshine-photo-cart' ) . '</a>';
		$html .= '</span>';

		$html .= '</div>'; // .sunshine-gallery-navigator-item-content

		// Children container (loaded via AJAX when expanded)
		if ( $children_count > 0 ) {
			$html .= '<div class="sunshine-gallery-navigator-children" style="display: none;"></div>';
		}

		$html .= '</li>';
	}

	$html .= '</ul>';
	$html .= '</div>';

	return $html;
}

function sunshine_gallery_navigator_count_children( $gallery_id ) {
	$args = array(
		'post_type'      => 'sunshine-gallery',
		'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
		'post_parent'    => $gallery_id,
		'posts_per_page' => 1,
	);

	$query = new WP_Query( $args );
	return $query->found_posts;
}

function sunshine_gallery_navigator_get_parents( $gallery_id ) {
	$parents   = array();
	$gallery   = sunshine_get_gallery( $gallery_id );
	$parent_id = $gallery->get_parent_gallery_id();

	while ( $parent_id ) {
		array_unshift( $parents, $parent_id );
		$parent_gallery = sunshine_get_gallery( $parent_id );
		$parent_id      = $parent_gallery->get_parent_gallery_id();
	}

	return $parents;
}
