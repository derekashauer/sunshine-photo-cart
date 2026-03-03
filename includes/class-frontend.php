<?php
class SPC_Frontend {

	public $current_gallery;
	public $current_image;
	public $current_order;
	private $output;
	public $pages = array();
	private $page_title;
	public $search_results = array();
	public $search_term;
	public $gallery_search_results = array();

	function __construct() {

		add_action( 'wp', array( $this, 'set_view_values' ), 1 );
		// add_action( 'wp', array( $this, 'require_login' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp', array( $this, 'admin_bar' ) );
		add_action( 'wp', array( $this, 'remove_canonical' ), 99 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		// add_filter( 'the_title', array( $this, 'the_title' ), 10, 2 );
		add_filter( 'protected_title_format', array( $this, 'protected_title_format' ), 10, 2 );
		add_filter( 'private_title_format', array( $this, 'private_title_format' ), 10, 2 );
		add_filter( 'sunshine_main_menu', array( $this, 'build_main_menu' ), 10 );
		add_filter( 'sunshine_image_menu', array( $this, 'build_image_menu' ), 10, 2 );
		add_filter( 'sunshine_action_menu', array( $this, 'build_action_menu' ), 10 );
		add_action( 'wp_head', array( $this, 'meta' ), 1 );
		add_action( 'wp_head', array( $this, 'head' ), 2 );
		add_action( 'wp_head', array( $this, 'custom_css' ), 999 );
		add_action( 'wp_footer', array( $this, 'protection' ) );
		add_action( 'wp_footer', array( $this, 'selection_tray_widget' ), 10 );
		add_action( 'wp_footer', array( $this, 'version_output' ), 9999 );
		// add_action( 'template_redirect', array( $this, 'can_view_gallery' ) );
		add_action( 'template_redirect', array( $this, 'can_view_image' ) );
		add_action( 'template_redirect', array( $this, 'can_view_order' ) );
		add_action( 'template_redirect', array( $this, 'can_use_cart' ) );
		add_filter( 'comments_open', array( $this, 'disable_comments' ), 10, 2 );
		add_filter( 'previous_post_link', array( $this, 'disable_prev_next' ), 99, 5 );
		add_filter( 'next_post_link', array( $this, 'disable_prev_next' ), 99, 5 );
		add_action( 'template_redirect', array( $this, 'order_invoice_pdf' ), 1 );
		add_action( 'wp', array( $this, 'process_password' ) );
		add_action( 'wp', array( $this, 'process_access' ) );
		add_action( 'wp', array( $this, 'check_account_endpoints' ) );
		add_action( 'wp', array( $this, 'theme_functions' ) );
		add_action( 'template_redirect', array( $this, 'no_cache' ) );
		add_action( 'post_thumbnail_size', array( $this, 'post_thumbnail_size' ), 10, 2 );

		// Filter the_content for FSE themes since full template_include fails with get_header()
		// TODO: Somehow create a single-gallery.html FSE template like WooCommerce does
		if ( ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && 'theme' == SPC()->get_option( 'theme' ) ) || SPC()->get_option( 'page_builder' ) ) {
			add_filter( 'the_content', array( $this, 'the_content' ) );
		} else {
			add_filter( 'template_include', array( $this, 'template_include' ), 999 );
		}

	}

	function theme_functions() {
		// Get selected theme's functions
		if ( SPC()->frontend->is_gallery() ) {
			$theme = SPC()->get_option( 'theme_gallery', 'theme' );
		} else {
			$theme = SPC()->get_option( 'theme', 'theme' );
		}
		$theme_functions_file = SUNSHINE_PHOTO_CART_PATH . 'themes/' . $theme . '/functions.php';
		if ( file_exists( $theme_functions_file ) ) {
			include_once $theme_functions_file;
		}
	}

	function template_include( $template ) {

		$theme = SPC()->get_option( 'theme' );
		if ( 'theme' != $theme ) {
			if ( is_sunshine_page( 'home' ) ) {
				$template = sunshine_locate_template( 'home' );
			} elseif ( is_sunshine_page( 'cart' ) ) {
				$template = sunshine_locate_template( 'cart' );
			} elseif ( is_sunshine_page( 'checkout' ) ) {
				$template = sunshine_locate_template( 'checkout' );
			} elseif ( is_sunshine_page( 'favorites' ) ) {
				$template = sunshine_locate_template( 'favorites' );
			} elseif ( is_sunshine_page( 'account' ) && ! SPC()->frontend->is_order() ) {
				$template = sunshine_locate_template( 'account' );
			}
		}

		if ( $this->is_search() ) {
			// Overall search results.
			$template = sunshine_locate_template( 'search' );
		} elseif ( $this->is_image() ) {
			$this->page_title = $this->current_image->get_name();
			$template         = sunshine_locate_template( 'image' );
		} elseif ( $this->is_gallery() ) {
			$this->page_title = $this->current_gallery->get_name();
			$template         = sunshine_locate_template( 'gallery' );
		} elseif ( $this->is_order() ) {
			$this->page_title = $this->current_order->get_name();
			$template         = sunshine_locate_template( 'order' );
		}

		if ( is_sunshine_page( 'checkout' ) && SPC()->get_option( 'checkout_standalone' ) && ! $this->is_order() ) {
			$template = sunshine_locate_template( 'checkout-standalone' );
		}

		return $template;

	}

	public function the_content( $content ) {

		if ( $this->is_search() ) {
			$content = sunshine_get_template_html( 'search/search' );
		} elseif ( $this->is_image() ) {
			$content = sunshine_get_template_html( 'image/single' );
		} elseif ( $this->is_gallery() ) {
			$content = sunshine_get_template_html( 'gallery/single' );
		} elseif ( $this->is_order() ) {
			$content = sunshine_get_template_html( 'order/single' );
		}

		return $content;
	}

	function get_page_title() {

		if ( $this->is_search() ) {
			$template = __( 'Search results', 'sunshine-photo-cart' );
		} elseif ( $this->is_image() ) {
			$this->page_title = $this->current_image->get_name();
		} elseif ( $this->is_gallery() ) {
			$this->page_title = $this->current_gallery->get_name();
		} elseif ( $this->is_order() ) {
			$this->page_title = $this->current_order->get_name();
		}

		return $this->page_title;
	}

	public function set_view_values( $wp ) {
		global $post, $wp_query, $wpdb;

		// Check by post type
		if ( is_attachment() ) {
			if ( $post->post_parent ) {
				$parent = get_post( $post->post_parent );
				if ( get_post_type( $parent ) == 'sunshine-gallery' ) {
					$this->current_gallery = sunshine_get_gallery( $parent );
					$this->current_image   = sunshine_get_image( $post );
					if ( ! $this->current_image->can_view() ) {
						$this->die();
					}
					do_action( 'sunshine_view_image', $this->current_image );
				}
			}
		} elseif ( is_singular( 'sunshine-gallery' ) ) {
			$this->current_gallery = sunshine_get_gallery( $post->ID );
			/*
			if ( ! $this->current_gallery->can_view() ) {
				$this->die();
			}
			*/
			if ( $this->current_gallery->can_access() ) {
				do_action( 'sunshine_view_gallery', $this->current_gallery );
				$last_viewed_gallery = SPC()->session->get( 'last_gallery' );
				if ( $last_viewed_gallery != $this->current_gallery->get_id() ) {
					$this->set_last_viewed_gallery( $this->current_gallery->get_id() );
				}
			}
		} elseif ( get_query_var( SPC()->get_option( 'account_view_order_endpoint', 'order-details' ) ) ) {
			$order_id = get_query_var( SPC()->get_option( 'account_view_order_endpoint', 'order-details' ) );
			$order    = new SPC_Order( $order_id );
			if ( $order->can_access() ) { // Permission check to make sure access to this sensitive data is allowed
				$this->current_order = $order;
			} else {
				// TODO: Redirect to login page?
				$url = sunshine_get_page_url( 'account' );
				$url = add_query_arg( 'redirect', $order->get_permalink(), $url );
				wp_safe_redirect( $url );
				exit;
				// $this->die();
			}
		} elseif ( get_query_var( SPC()->get_option( 'endpoint_order_received', 'receipt' ) ) ) {
			$order_id = get_query_var( SPC()->get_option( 'endpoint_order_received', 'receipt' ) );
			$order    = new SPC_Order( $order_id );
			if ( $order->can_access() ) { // Permission check to make sure access to this sensitive data is allowed
				$this->current_order = $order;
			} else {
				$this->die();
			}
		}

		if ( is_sunshine_page( 'favorites' ) && isset( $_GET['key'] ) ) {
			SPC()->session->set( 'favorite_key', sanitize_text_field( $_GET['key'] ) );
		}

		if ( isset( $_GET['sunshine_search'] ) ) {
			$this->search_term = sanitize_text_field( $_GET['sunshine_search'] );
			$args              = array(
				's' => $this->search_term,
			);
			if ( $this->is_gallery() ) {
				$args['post_parent__in'] = array( $this->current_gallery->get_id() );
				$descendants             = sunshine_get_gallery_descendants( $this->current_gallery->get_id() );
				if ( ! empty( $descendants ) ) {
					foreach ( $descendants as $descendant ) {
						$descendant_gallery = sunshine_get_gallery( $descendant );
						if ( $descendant_gallery->can_access() ) {
							$args['post_parent__in'][] = $descendant->ID;
						}
					}
				}
			}
			$args                 = apply_filters( 'sunshine_search_args', $args );
			$this->search_results = sunshine_get_images( $args );

			// Search for matching sub-galleries when searching within a gallery.
			if ( $this->is_gallery() ) {
				// Get all descendant gallery IDs to search within.
				$descendant_ids = array( $this->current_gallery->get_id() );
				$descendants    = sunshine_get_gallery_descendants( $this->current_gallery->get_id() );
				if ( ! empty( $descendants ) ) {
					foreach ( $descendants as $descendant ) {
						$descendant_ids[] = $descendant->ID;
					}
				}
				$gallery_args                 = array(
					's'               => $this->search_term,
					'post_parent__in' => $descendant_ids,
				);
				$gallery_args                 = apply_filters( 'sunshine_search_gallery_args', $gallery_args );
				$this->gallery_search_results = sunshine_get_galleries( $gallery_args, 'access' );
			}

			do_action( 'sunshine_search', $this->search_term, $this->search_results );
		}

	}

	public function die( $message = '' ) {
		if ( empty( $message ) ) {
			$message = __( 'Sorry, you do not have permission to view this page', 'sunshine-photo-cart' );
		}
		wp_die( esc_html( $message ) );
	}

	/*
	public function set_gallery( $gallery ) {
		$gallery = sunshine_get_gallery( $gallery );
		if ( empty( $gallery ) ) {
			return;
		}
		$this->current_gallery = $gallery;
	}
	*/

	public function set_last_viewed_gallery( $gallery_id ) {
		SPC()->session->set( 'last_gallery', $gallery_id );
	}

	public function is_image() {
		if ( ! empty( $this->current_image ) ) {
			return true;
		}
		return false;
	}

	public function is_gallery() {
		if ( empty( $this->current_image ) && ! empty( $this->current_gallery ) ) {
			return true;
		}
		return false;
	}

	public function is_order() {
		if ( ! empty( $this->current_order ) ) {
			return true;
		}
		return false;
	}

	public function is_store() {
		global $wp_query;
		if ( ! empty( $wp_query->query ) && array_key_exists( SPC()->get_option( 'endpoint_store', 'store' ), $wp_query->query ) ) {
			return true;
		}
		return false;
	}

	public function is_search() {
		if ( isset( $_GET['sunshine_search'] ) && wp_verify_nonce( $_GET['sunshine_search_nonce'], 'sunshine_search' ) ) {
			return true;
		}
		return false;
	}

	public function image_permalink( $url, $post_id ) {

		// See if we are in a current gallery
		if ( $this->is_gallery() ) {

		}

		// Get current gallery image IDs

		// If in the list of gallery IDs, then build off this gallery URL base

		$post_obj = get_post( $post_id );

		if ( empty( $post_obj ) ) {
			return $url;
		}

		$parent = get_post( $post_obj->post_parent );
		if ( 2 == 1 ) {
			$url = trailingslashit( get_permalink( $parent ) . '/' . $post_obj->post_name );
		}

		return $url;

	}

	function admin_bar() {
		if ( ! current_user_can( 'sunshine_manage_options' ) ) {
			show_admin_bar( apply_filters( 'sunshine_admin_bar', false ) );
		}
	}

	function require_login() {

		if ( is_sunshine_page( 'account' ) && ! is_user_logged_in() && ! isset( $_GET['key'] ) ) {
			wp_safe_redirect( apply_filters( 'sunshine_login_url', wp_login_url( sunshine_current_url( false ) ) ) );
			exit;
		}

	}

	function the_title( $title, $id = '' ) {
		global $post;

		/*
		if ( ! in_the_loop() && $id == SPC()->get_option( 'page_cart' ) && SPC()->get_option( 'theme' ) == 'theme' ) {
			$count = SPC()->cart->get_item_count();
			$title .= ' <span class="sunshine-count sunshine-cart-count">' . $count . '</span>';
		}
		*/

		/*
		if ( isset( $_GET['sunshine_search'] ) && in_the_loop() && $id == SPC()->get_option( 'page' ) ) {
			$title = __( 'Search for','sunshine-photo-cart' ).' "'.sanitize_text_field( $_GET['sunshine_search'] ).'"';
		}
		*/

		return $title;
	}

	function protected_title_format( $format, $post ) {
		if ( $post->post_type == 'sunshine-gallery' ) {
			$format = '%s';
		}
		return $format;
	}

	function private_title_format( $format, $post ) {
		if ( $post->post_type == 'sunshine-gallery' ) {
			$format = '%s';
		}
		return $format;
	}

	function disable_comments( $open, $post_id ) {
		$post = get_post( $post_id );
		if ( $post->post_type == 'attachment' && get_post_type( $post->post_parent ) == 'sunshine-gallery' ) {
			$gallery = sunshine_get_gallery( $post->post_parent );
			if ( $gallery->allow_comments() ) {
				return true;
			}
			return false;
		} elseif ( $post->post_type == 'sunshine-order' ) {
			return false; // No comments on any order
		}
		return $open;
	}

	public function enqueue_scripts() {

		wp_register_style( 'sunshine-photo-cart', SUNSHINE_PHOTO_CART_URL . 'assets/css/sunshine.css', '', SUNSHINE_PHOTO_CART_VERSION );
		wp_register_style( 'sunshine-photo-cart-icons', SUNSHINE_PHOTO_CART_URL . 'assets/css/icons.css', '', SUNSHINE_PHOTO_CART_VERSION );

		if ( is_sunshine() ) {

			if ( empty( SPC()->get_option( 'disable_sunshine_css' ) ) || ! SPC()->get_option( 'disable_sunshine_css' ) ) {
				wp_enqueue_style( 'sunshine-photo-cart' );
				wp_enqueue_style( 'sunshine-photo-cart-icons' );
				if ( SPC()->frontend->is_gallery() ) {
					$theme = SPC()->get_option( 'theme_gallery', 'theme' );
				} else {
					$theme = SPC()->get_option( 'theme', 'theme' );
				}
				if ( $theme != 'theme' ) {
					$css_file = SUNSHINE_PHOTO_CART_URL . 'themes/' . $theme . '/style.css';
					wp_enqueue_style( 'sunshine-photo-cart-' . $theme, $css_file );
				}
			}

			wp_enqueue_script( 'sunshine-photo-cart', SUNSHINE_PHOTO_CART_URL . 'assets/js/sunshine.js', array( 'jquery' ), SUNSHINE_PHOTO_CART_VERSION, true );
			// Build favorites data for the tray widget.
			$favorites_data = array();
			foreach ( SPC()->customer->get_favorite_ids() as $fav_id ) {
				$src = wp_get_attachment_image_url( $fav_id, 'sunshine-thumbnail' );
				if ( $src ) {
					$favorites_data[] = array(
						'id'  => $fav_id,
						'src' => $src,
					);
				}
			}

			wp_localize_script(
				'sunshine-photo-cart',
				'sunshine_photo_cart',
				array(
					'ajax_url'             => admin_url( 'admin-ajax.php' ),
					'security'             => wp_create_nonce( 'sunshinephotocart' ),
					'cart_url'             => sunshine_get_page_permalink( 'cart' ),
					'checkout_url'         => sunshine_get_page_permalink( 'checkout' ),
					'favorites_url'        => sunshine_get_page_permalink( 'favorites' ),
					'favorites'            => $favorites_data,
					'is_logged_in'         => is_user_logged_in() ? 1 : 0,
					'guest_favorites_mode' => ( ! is_user_logged_in() && SPC()->get_option( 'enable_guest_favorites' ) && SPC()->session->get( 'guest_favorites_mode' ) ) ? 1 : 0,
					'lang'                 => array(
						'error'                   => __( 'Sorry, there was an error with your request', 'sunshine-photo-cart' ),
						'max_images'              => __( 'You have already selected the maximum number of photos allowed', 'sunshine-photo-cart' ),
						'view_cart'               => __( 'View Cart', 'sunshine-photo-cart' ),
						'preparing_download'      => __( 'Preparing download...', 'sunshine-photo-cart' ),
						'download_started'        => __( 'Download started', 'sunshine-photo-cart' ),
						'downloading'             => __( 'Downloading...', 'sunshine-photo-cart' ),
						'download_complete'       => __( 'Download complete!', 'sunshine-photo-cart' ),
						'guest_favorites_message' => __( 'Create an account to save your favorites permanently', 'sunshine-photo-cart' ),
						'guest_favorites_notice'  => __( 'Your selections will only be saved for this browser session. To save them permanently, <a href="#" id="sunshine--guest-to-signup">create an account</a>.', 'sunshine-photo-cart' ),
						'continue_as_guest'       => __( 'Continue as guest', 'sunshine-photo-cart' ),
						'already_have_account'    => __( 'I already have an account', 'sunshine-photo-cart' ),
						'back_to_signup'          => __( 'Back to sign up', 'sunshine-photo-cart' ),
						'continue'                => __( 'I understand, continue', 'sunshine-photo-cart' ),
					),
				)
			);

			/*
			if ( is_sunshine_page( 'checkout' ) ) {
				wp_enqueue_script( 'sunshine-photo-cart-checkout', SUNSHINE_PHOTO_CART_URL . 'assets/js/checkout.js', array( 'jquery' ), SUNSHINE_PHOTO_CART_VERSION, true );
				wp_localize_script(
					'sunshine-photo-cart-checkout',
					'sunshine_photo_cart_checkout',
					array(
						'ajax_url' => admin_url( 'admin-ajax.php' ),
						'security' => wp_create_nonce( 'sunshinephotocartcheckout' ),
					)
				);
			}
			*/

			// Load masonry if needed
			if ( is_sunshine() && ( SPC()->get_option( 'gallery_layout' ) == 'masonry' || SPC()->get_option( 'image_layout' ) == 'masonry' ) ) {
				wp_enqueue_script( 'sunshine-photo-cart-masonry', SUNSHINE_PHOTO_CART_URL . 'assets/js/masonry.js', '', SUNSHINE_PHOTO_CART_VERSION, true );
				wp_localize_script(
					'sunshine-photo-cart-masonry',
					'sunshine_photo_cart_masonry',
					array(
						'gallery' => ( SPC()->get_option( 'gallery_layout' ) == 'masonry' ) ? 1 : 0,
						'image'   => ( SPC()->get_option( 'image_layout' ) == 'masonry' ) ? 1 : 0,
					)
				);
			}

			do_action( 'sunshine_enqueue_scripts' );

		}

	}

	// Add to body_class
	function body_class( $classes ) {
		if ( is_sunshine() ) {
			$classes[] = 'sunshine-photo-cart';
		}
		if ( SPC()->get_option( 'dark_mode' ) ) {
			$classes[] = 'sunshine--dark';
		}
		if ( SPC()->get_option( 'checkout_standalone' ) && is_sunshine_page( 'checkout' ) ) {
			$classes[] = 'sunshine--checkout--standalone';
		}
		return $classes;
	}

	function build_main_menu( $menu ) {

		if ( is_sunshine_page( 'checkout' ) && SPC()->get_option( 'checkout_standalone' ) && ! $this->is_order() ) {
			return false;
		}

		if ( is_user_logged_in() ) {
			$menu[110] = array(
				'name'  => __( 'Logout', 'sunshine-photo-cart' ),
				'url'   => sunshine_get_account_endpoint_url( 'logout' ),
				'class' => 'sunshine--logout',
			);

			$selected = '';
			if ( is_sunshine_page( 'account' ) ) {
				$selected = ' sunshine--selected';
			}
			$menu[100] = array(
				'name'  => get_the_title( SPC()->get_option( 'page_account' ) ),
				'url'   => sunshine_get_page_permalink( 'account' ),
				'class' => 'sunshine--account' . $selected,
			);

		} else {
			$menu[100] = array(
				'name'    => __( 'Login', 'sunshine-photo-cart' ),
				'url'     => sunshine_get_account_endpoint_url( 'login' ),
				'class'   => 'sunshine--login',
				'a_class' => 'sunshine--open-modal',
				'attr'    => array(
					'data-hook'     => 'login',
					'aria-haspopup' => 'dialog',
				),
			);
			if ( ! SPC()->get_option( 'disable_signup', false ) ) {
				$menu[110] = array(
					'name'    => __( 'Sign Up', 'sunshine-photo-cart' ),
					'url'     => '#signup',
					'class'   => 'sunshine--signup',
					'a_class' => 'sunshine--open-modal',
					'attr'    => array(
						'data-hook'     => 'signup',
						'aria-haspopup' => 'dialog',
					),
				);
			}
		}

		if ( SPC()->get_option( 'page_favorites' ) && SPC()->get_option( 'enable_favorites', true ) ) {
			$favorites_count = SPC()->customer->get_favorite_count();
			$count           = $favorites_count > 0 ? '<span class="sunshine--count sunshine--favorites--count">' . $favorites_count . '</span>' : '';

			$selected = '';
			if ( is_sunshine_page( 'favorites' ) ) {
				$selected = ' sunshine--selected';
			}
			$menu[25] = array(
				'name'    => get_the_title( SPC()->get_option( 'page_favorites' ) ),
				'after_a' => $count,
				'url'     => sunshine_get_page_permalink( 'favorites' ),
				'class'   => 'sunshine--favorites' . $selected,
			);
		}

		if ( empty( SPC()->get_option( 'hide_galleries_link' ) ) || SPC()->get_option( 'hide_galleries_link' ) != 1 ) {

			$selected = '';
			if ( is_sunshine_page( 'home' ) ) {
				$selected = ' sunshine--selected';
			}
			$menu[10] = array(
				'name'  => get_the_title( sunshine_get_page( 'home' ) ),
				'url'   => sunshine_get_page_permalink( 'home' ),
				'class' => 'sunshine--galleries' . $selected,
			);

			if ( ( $this->is_gallery() || $this->is_image() ) && SPC()->store_enabled() && $this->current_gallery->can_purchase() ) {
				$selected = '';
				if ( $this->is_store() ) {
					$selected = ' sunshine--selected';
				}
				$menu[15] = array(
					'name'  => __( 'Store', 'sunshine-photo-cart' ),
					'url'   => $this->current_gallery->get_store_url(),
					'class' => 'sunshine--store' . $selected,
				);
			}
		}

		if ( ! SPC()->get_option( 'proofing', false ) ) {

			$cart_count = '';
			$cart_count = '<span class="sunshine--count sunshine--cart--count">' . SPC()->cart->get_item_count() . '</span>';

			$selected = '';
			$menu[40] = array(
				'name'    => get_the_title( sunshine_get_page( 'cart' ) ),
				'url'     => sunshine_get_page_permalink( 'cart' ),
				'class'   => 'sunshine--cart',
				'after_a' => $cart_count,
			);

			$selected = '';
			if ( is_sunshine_page( 'checkout' ) ) {
				$selected = ' sunshine--selected';
			}
			$menu[50] = array(
				'name'  => get_the_title( sunshine_get_page( 'checkout' ) ),
				'url'   => sunshine_get_page_permalink( 'checkout' ),
				'class' => 'sunshine--checkout' . $selected,
			);

		}

		return $menu;
	}

	function build_image_menu( $menu, $image ) {

		if ( $this->is_image() ) {
			$menu[0] = array(
				'name'  => __( 'Return to gallery', 'sunshine-photo-cart' ),
				'class' => 'sunshine--return',
				'url'   => $image->get_gallery()->get_permalink(),
			);
		}

		if ( SPC()->get_option( 'enable_favorites', true ) && $image->allow_favorites() ) {
			// Always use sunshine--add-to-favorites; JS handles guest flow.
			$menu[10] = array(
				'name'    => __( 'Favorite', 'sunshine-photo-cart' ) . ': ' . $image->get_name(),
				'class'   => 'sunshine--favorite',
				'a_class' => 'sunshine--add-to-favorites',
				'attr'    => array(
					'data-image-id' => $image->get_id(),
				),
			);
		}

		if ( $image->can_purchase() && ! SPC()->get_option( 'proofing', false ) ) {
			if ( SPC()->get_option( 'products_require_account' ) && ! is_user_logged_in() ) {
				$menu[20] = array(
					'name'    => __( 'Purchase options', 'sunshine-photo-cart' ) . ': ' . $image->get_name(),
					'class'   => 'sunshine--purchase',
					'a_class' => 'sunshine--open-modal',
					'attr'    => array(
						'data-image-id' => $image->get_id(),
						'data-hook'     => 'require_login',
						'data-reason'   => 'products_require_account',
						'aria-haspopup' => 'dialog',
					),
				);
			} else {
				$menu[20] = array(
					'name'    => __( 'Purchase options', 'sunshine-photo-cart' ) . ': ' . $image->get_name(),
					'class'   => 'sunshine--purchase',
					'a_class' => 'sunshine--open-modal',
					'attr'    => array(
						'data-image-id' => $image->get_id(),
						'data-hook'     => 'add_to_cart',
						'aria-haspopup' => 'dialog',
					),
				);
			}
		}

		if ( $image->allow_comments() ) {
			$after_a       = '';
			$comment_count = $image->get_comment_count();
			if ( $comment_count > 0 ) {
				$after_a = '<span class="sunshine--count sunshine--comment-count">' . esc_html( $comment_count ) . '</span>';
			}
			$menu[30] = array(
				'name'    => __( 'Comments', 'sunshine-photo-cart' ) . ': ' . $image->get_name(),
				// 'url'     => $image->get_permalink() . '#comments',
				'class'   => 'sunshine--comments',
				'after_a' => $after_a,
				'a_class' => 'sunshine--open-modal',
				'attr'    => array(
					'data-image-id' => $image->get_id(),
					'data-hook'     => 'comments',
					'aria-haspopup' => 'dialog',
				),
			);
		}

		if ( SPC()->get_option( 'allow_image_sharing' ) && $image->allow_image_sharing() ) {
			$menu[50] = array(
				'name'    => __( 'Share', 'sunshine-photo-cart' ) . ': ' . $image->get_name(),
				// 'url'     => $image->get_permalink() . '#share',
				'class'   => 'sunshine--share',
				'a_class' => 'sunshine--open-modal',
				'attr'    => array(
					'data-image-id' => $image->get_id(),
					'data-hook'     => 'share',
					'aria-haspopup' => 'dialog',
				),
			);
		}

		// Kill image menu when viewing shared favorites with an access key (not the user's own favorites page)
		if ( ! $image->can_access() && ! empty( SPC()->session->get( 'favorite_key' ) ) && isset( $_GET['favorites_key'] ) ) {
			$menu = array();
		}

		return $menu;
	}

	function build_action_menu( $menu ) {

		if ( ( $this->is_image() || $this->is_gallery() ) && SPC()->store_enabled() && $this->current_gallery->can_purchase() ) {
			if ( ! SPC()->frontend->is_store() ) {
				$menu[10] = array(
					'name'  => __( 'Visit store', 'sunshine-photo-cart' ),
					'url'   => $this->current_gallery->get_store_url(),
					'class' => 'sunshine--gallery-store',
				);
			}
		}

		if ( ( $this->is_image() && $this->current_image->can_access() ) || $this->is_store() ) {

			$menu[1] = array(
				'name'  => __( 'Return to gallery', 'sunshine-photo-cart' ),
				'url'   => $this->current_gallery->get_permalink(),
				'class' => 'sunshine--gallery-return',
			);

		}

		if ( $this->is_gallery() && $this->current_gallery->get_parent_gallery_id() && ! SPC()->get_option( 'hide_galleries_link' ) ) {
			$parent_gallery = $this->current_gallery->get_parent_gallery();
			if ( $parent_gallery->can_access() ) {
				$menu[2] = array(
					/* translators: %s is the parent gallery name */
					'name'  => sprintf( __( 'Return to %s', 'sunshine-photo-cart' ), $parent_gallery->get_name() ),
					'class' => 'sunshine--gallery-return',
					'url'   => $parent_gallery->get_permalink(),
				);
			}
		}

		if ( $this->is_search() && $this->is_gallery() ) {
			$menu[2] = array(
				/* translators: %s is the gallery name */
				'name'  => sprintf( __( 'Return to %s', 'sunshine-photo-cart' ), $this->current_gallery->get_name() ),
				'class' => 'sunshine--gallery-return',
				'url'   => $this->current_gallery->get_permalink(),
			);
		}

		if ( is_sunshine_page( 'favorites' ) ) {

			if ( SPC()->customer->has_favorites() ) {

				if ( ! SPC()->get_option( 'proofing', false ) ) {
					$menu[9] = array(
						'name'    => __( 'Add all to cart', 'sunshine-photo-cart' ),
						'class'   => 'sunshine--favorites-all',
						'a_class' => 'sunshine--open-modal',
						'attr'    => array(
							'data-hook'     => 'add_favorites_to_cart',
							'aria-haspopup' => 'dialog',
						),
					);
				}

				if ( is_user_logged_in() && SPC()->get_option( 'allow_image_sharing' ) ) {
					$menu[10] = array(
						'name'    => __( 'Share your favorites', 'sunshine-photo-cart' ),
						'class'   => 'sunshine--favorites-share',
						'a_class' => 'sunshine--open-modal',
						'attr'    => array(
							'data-hook' => 'share_favorites',
						),
					);
				}

				$url      = sunshine_get_page_url( 'favorites' );
				$url      = wp_nonce_url( $url, 'sunshine_clear_favorites', 'clear_favorites' );
				$menu[11] = array(
					'name'  => __( 'Clear all favorites', 'sunshine-photo-cart' ),
					'url'   => $url,
					'class' => 'sunshine--favorites-clear',
				);

			}

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
				$menu[12] = array(
					/* translators: %s is the gallery name */
					'name'  => sprintf( __( 'Return to gallery "%s"', 'sunshine-photo-cart' ), esc_html( $gallery->get_name() ) ),
					'class' => 'sunshine--gallery-return',
					'url'   => $url,
				);

			}
		}

		if ( $this->is_order() && ! SPC()->get_option( 'disable_invoice' ) ) {
			$menu[20] = array(
				'name'  => __( 'View invoice', 'sunshine-photo-cart' ),
				'class' => 'sunshine--icon--purchase-order',
				'url'   => $this->current_order->get_invoice_permalink(),
			);
		}

		return $menu;
	}

	function meta() {

		if ( ! SPC()->get_option( 'open_graph', false ) ) {
			return;
		}

		// TODO: Is this required now that we are using default attachment URL by WordPress? Can SEO plugins handle this?
		// Image page
		if ( $this->is_image() && ! $this->current_gallery->password_required() ) {

			$image           = wp_get_attachment_image_src( $this->current_image->get_id(), apply_filters( 'sunshine_image_size', 'full' ) );
			$image_mime_type = get_post_mime_type( $this->current_image->get_id() );

				/* translators: %1$s is the image name, %2$s is the site name */
			echo '<meta property="og:title" content="' . esc_attr( apply_filters( 'sunshine_open_graph_image_title', $this->current_image->get_name() . ' by ' . get_bloginfo( 'name' ) ) ) . '"/>';
			echo '<meta property="og:type" content="website"/>';
			echo '<meta property="og:url" content="' . esc_url( trailingslashit( get_permalink( $this->current_image->ID ) ) ) . '"/>';
			echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '"/>';
			/* translators: %1$s is the gallery name, %2$s is the site name */
			echo '<meta property="og:description" content="' . esc_attr( sprintf( __( 'A photo from the gallery %1$s by %2$s', 'sunshine-photo-cart' ), wp_strip_all_tags( $this->current_image->gallery->get_name() ), get_bloginfo( 'name' ) ) ) . '"/>';
			if ( is_ssl() ) {
				$http_url = str_replace( 'https', 'http', $image[0] );
				echo '<meta property="og:image" content="' . esc_attr( $http_url ) . '"/>';
				echo '<meta property="og:image:url" content="' . esc_attr( $http_url ) . '"/>';
				echo '<meta property="og:image:secure_url" content="' . esc_attr( $image[0] ) . '"/>';
			} else {
				echo '<meta property="og:image" content="' . esc_attr( $image[0] ) . '"/>';
				echo '<meta property="og:image:url" content="' . esc_attr( $image[0] ) . '"/>';
			}
			echo '<meta property="og:image:type" content="' . esc_attr( $image_mime_type ) . '" />';
			echo '<meta property="og:image:height" content="' . esc_attr( $image[2] ) . '"/>';
			echo '<meta property="og:image:width" content="' . esc_attr( $image[1] ) . '"/>';

		} elseif ( $this->is_gallery() ) {

			$image_id = $this->current_gallery->get_featured_image_id();
			if ( $image_id ) {
				$image = wp_get_attachment_image_src( $image_id, apply_filters( 'sunshine_image_size', 'full' ) );
				if ( $image ) {
					$image_mime_type = get_post_mime_type( $image_id );
					echo '<meta property="og:title" content="' . esc_attr( apply_filters( 'sunshine_open_graph_gallery_title', $this->current_gallery->get_name() . ' by ' . get_bloginfo( 'name' ) ) ) . '"/>';
					echo '<meta property="og:type" content="website"/>';
					echo '<meta property="og:url" content="' . esc_attr( trailingslashit( $this->current_gallery->get_permalink() ) ) . '"/>';
					echo '<meta property="og:image" content="' . esc_attr( $image[0] ) . '"/>';
					echo '<meta property="og:image:height" content="' . esc_attr( $image[2] ) . '"/>';
					echo '<meta property="og:image:width" content="' . esc_attr( $image[1] ) . '"/>';
					echo '<meta property="og:image:type" content="' . esc_attr( $image_mime_type ) . '" />';
					echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '"/>';
					/* translators: %1$s is the gallery name, %2$s is the site name */
					echo '<meta property="og:description" content="' . esc_attr( sprintf( __( 'Photo gallery %1$s by %2$s', 'sunshine-photo-cart' ), get_the_title( $this->current_gallery->post_parent ), get_bloginfo( 'name' ) ) ) . '"/>';
				}
			}
		}

	}

	function head() {

		// Block search engines from all orders.
		if ( $this->is_order() ) {
			echo '<meta name="robots" content="noindex" />';
		}
		// Block search engines going to galleries if selected.
		if ( ! empty( SPC()->get_option( 'hide_galleries' ) ) && $this->is_gallery() ) {
			echo '<meta name="robots" content="noindex" />';
		}

	}

	function custom_css() {
		$css = SPC()->get_option( 'css' );
		if ( $css && is_sunshine() ) {
			echo '<style id="sunshine--custom-css">';
			$css = wp_strip_all_tags( $css );
			echo esc_attr( $css );
			echo '</style>';
		}
	}

	function protection() {

		if ( is_sunshine() && SPC()->get_option( 'disable_right_click' ) && ! current_user_can( 'manage_options' ) ) {
			?>
			<script type="text/javascript">
			jQuery(document).ready(function() {
				jQuery(document).bind("contextmenu",function(e){ return false; });
				jQuery("img").mousedown(function(){ return false; });
				document.body.style.webkitTouchCallout='none';
			});
			</script>
			<?php
		}

	}

	public function selection_tray_widget() {
		if ( is_sunshine() && ! is_sunshine_page( 'favorites' ) && SPC()->get_option( 'enable_selection_tray' ) ) {
			$position = SPC()->get_option( 'selection_tray_position', 'bottom_right' );
			if ( $position === 'before_content' ) {
				$position = 'bottom_right';
			}
			$favorites_url = sunshine_get_page_permalink( 'favorites' );
			?>
			<div id="sunshine--selection-tray-widget" class="sunshine--selection-tray-<?php echo esc_attr( $position ); ?>">
				<button id="sunshine--selection-tray-widget--minimize" title="<?php esc_attr_e( 'Minimize', 'sunshine-photo-cart' ); ?>"></button>
				<div id="sunshine--selection-tray-widget--header">
					<a href="<?php echo esc_url( $favorites_url ); ?>" id="sunshine--selection-tray-widget--title"><?php esc_html_e( 'Favorites', 'sunshine-photo-cart' ); ?> (<span id="sunshine--selection-tray-widget--count">0</span>)</a>
				</div>
				<div id="sunshine--selection-tray-widget--images"></div>
			</div>
			<button id="sunshine--selection-tray-minimized" title="<?php esc_attr_e( 'Show favorites', 'sunshine-photo-cart' ); ?>">
				<span id="sunshine--selection-tray-minimized--count">0</span>
			</button>
			<?php
		}
	}

	public function version_output() {
		if ( is_sunshine() ) {
			echo '<!-- Powered by Sunshine Photo Cart ' . esc_attr( SUNSHINE_PHOTO_CART_VERSION ) . ' -->';
		}
	}

	function can_view_gallery() {
		if ( $this->current_gallery && $this->current_gallery->post_status == 'private' && ! current_user_can( 'sunshine_manage_options' ) ) {
			$allowed_users = get_post_meta( $this->current_gallery->ID, 'sunshine_gallery_private_user' );
			if ( ! in_array( $current_user->ID, $allowed_users ) ) {
				wp_safe_redirect( add_query_arg( 'sunshine_login_notice', 'private_gallery', wp_login_url( sunshine_current_url( false ) ) ) );
				exit;
			}
		}
		if ( $this->current_gallery && $this->current_gallery->get_access_type() == 'account' && ! is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( 'sunshine_login_notice', 'gallery_requires_login', sunshine_get_page_url( 'account' ) ) );
			exit;
		}
	}

	function can_view_image() {
		if ( $this->is_image() && ! $this->current_image->can_view() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view this image', 'sunshine-photo-cart' ), esc_html__( 'Access denied', 'sunshine-photo-cart' ), array( 'back_link' => true ) );
			exit;
		}
	}

	function can_view_order( $order_id = '' ) {
		if ( ! empty( $this->current_order ) ) {
			return $this->current_order->can_access();
		}
		return false;
	}

	function can_use_cart() {

		if ( ( ! empty( SPC()->get_option( 'proofing', false ) ) && SPC()->get_option( 'proofing', false ) ) && ( is_page( SPC()->get_option( 'page_cart' ) ) || is_page( SPC()->get_option( 'page_checkout' ) ) ) ) {
			wp_safe_redirect( get_permalink( SPC()->get_option( 'page' ) ) );
			exit;
		}

	}

	function hide_order_comments( $template ) {
		global $post;
		if ( $post->post_type == 'sunshine-order' ) {
			return;
		}
		return $template;
	}

	function remove_image_commenting( $open, $post_id ) {
		global $post;
		if ( $post->post_type == 'attachment' ) {
			return false;
		}
		return $open;
	}

	function remove_parent_classes( $class ) {
		return ( $class == 'current_page_item' || $class == 'current_page_parent' || $class == 'current_page_ancestor' || $class == 'current-menu-item' ) ? false : true;
	}

	function add_class_to_wp_nav_menu( $classes, $item ) {

		switch ( get_post_type() ) {
			case 'sunshine-gallery':
				$classes = array_filter( $classes, array( $this, 'remove_parent_classes' ) );
				if ( $item->object_id == SPC()->get_option( 'page' ) ) {
					$classes[] = 'current_page_parent';
				}
				break;
			case 'sunshine-order':
				$classes = array_filter( $classes, array( $this, 'remove_parent_classes' ) );
				if ( $item->object_id == SPC()->get_option( 'page' ) ) {
					$classes[] = 'current_page_parent';
				}
				break;
		}
		return $classes;
	}

	function check_expirations() {

		if ( ! empty( $this->current_image ) && $this->current_gallery->is_expired() ) { // If looking at image but gallery is expired, redirect to gallery
			wp_safe_redirect( get_permalink( $this->current_gallery->get_permalink() ) );
			exit;
		} elseif ( is_sunshine_page( 'cart' ) ) { // Remove items from cart if gallery is expired
			$cart          = SPC()->cart->get_cart_items();
			$removed_items = false;
			if ( ! empty( $cart ) ) {
				foreach ( $cart as $key => $item ) {
					if ( ! empty( $item->gallery ) && ( empty( $item->gallery->get_id() ) || $item->gallery->is_expired() ) ) {
						SPC()->cart->delete_item( $key );
						$removed_items = true;
					}
				}
			}
			if ( $removed_items ) {
				SPC()->notices->add( __( 'Images in your cart have been removed because they are no longer available', 'sunshine-photo-cart' ) );
				wp_safe_redirect( get_permalink( SPC()->get_option( 'page_cart' ) ) );
				exit;
			}
		}

	}

	function remove_canonical() {
		if ( $this->is_gallery() ) {
			remove_action( 'wp_head', 'rel_canonical' );
		}
	}

	function disable_prev_next( $output, $format, $link, $post, $adjacent ) {
		if ( ! empty( $post ) && $post->post_type == 'sunshine-order' ) {
			return false;
		}
		return $output;
	}

	function order_invoice_pdf() {

		if ( $this->is_order() && isset( $_GET['order_invoice'] ) && wp_verify_nonce( $_GET['order_invoice'], 'order_invoice_' . $this->current_order->get_id() ) && ! SPC()->get_option( 'disable_invoice' ) ) {

			// Template has been fully escaped by the previous functions used to build this massive HTML string.
			echo sunshine_get_template( 'invoice/order', array( 'order' => $this->current_order ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;

		}

	}

	/*
	Password shortcode: Find a gallery with this password and redirect
	*/
	public function process_password() {

		if ( ! isset( $_POST['sunshine_password'] ) || ! wp_verify_nonce( $_POST['sunshine_password_nonce'], 'sunshine_password' ) ) {
			return false;
		}

		$password  = sanitize_text_field( $_POST['sunshine_password'] );
		$galleries = sunshine_get_galleries(
			array(
				'meta_query' => array(
					array(
						'key'   => 'password',
						'value' => $password,
					),
				),
			),
			'all'
		);
		if ( ! empty( $galleries ) ) {
			$gallery            = array_shift( $galleries );
			$password_galleries = SPC()->session->get( 'gallery_passwords' );
			if ( is_array( $password_galleries ) ) {
				$password_galleries[] = $gallery->get_id();
			} else {
				$password_galleries = array( $gallery->get_id() );
			}
			SPC()->session->set( 'gallery_passwords', $password_galleries );
			SPC()->log( 'Password access granted for ' . $gallery->get_name() );
			$redirect_url = $gallery->get_permalink();
			$redirect_url = add_query_arg( 'access', time(), $redirect_url );
			wp_safe_redirect( $redirect_url );
			exit;
		} else {
			SPC()->notices->add( __( 'No gallery found with that password', 'sunshine-photo-cart' ), 'error' );
			$redirect_url = sanitize_url( $_POST['_wp_http_referer'] );
			$redirect_url = add_query_arg( 'access', time(), $redirect_url );
			wp_safe_redirect( $redirect_url );
			exit;
		}

	}

	function process_access() {

		if ( ! isset( $_POST['sunshine_gallery_access'] ) || ! isset( $_POST['sunshine_gallery_id'] ) ) {
			return false;
		}

		// Verify nonce
		if ( ! wp_verify_nonce( $_POST['sunshine_gallery_access'], 'sunshine_gallery_access' ) ) {
			SPC()->notices->add( __( 'Invalid submission', 'sunshine-photo-cart' ), 'error' );
			SPC()->log( 'Invalid gallery password submission' );
			return;
		}

		$redirect   = false;
		$gallery_id = intval( $_POST['sunshine_gallery_id'] );
		$gallery    = sunshine_get_gallery( $gallery_id );

		if ( isset( $_POST['sunshine_gallery_password'] ) ) {

			// Check password against gallery
			if ( empty( $gallery ) ) {
				SPC()->notices->add( __( 'Invalid gallery', 'sunshine-photo-cart' ), 'error' );
				SPC()->log( 'Invalid gallery password submission: gallery not found' );
				return;
			}

			// Throw error if it does not match
			if ( $gallery->get_password() != stripslashes( $_POST['sunshine_gallery_password'] ) ) {
				SPC()->notices->add( __( 'Gallery access code is incorrect, please try again', 'sunshine-photo-cart' ), 'error' );
				SPC()->log( 'Invalid gallery password submission for ' . $gallery->get_name() . ': wrong password' );
				return;
			}

			// Add gallery id to list of allowed password galleries
			$password_galleries = SPC()->session->get( 'gallery_passwords' );
			if ( is_array( $password_galleries ) ) {
				$password_galleries[] = $gallery_id;
			} else {
				$password_galleries = array( $gallery_id );
			}
			SPC()->session->set( 'gallery_passwords', $password_galleries );
			SPC()->log( 'Password access granted for ' . $gallery->get_name() );
			$redirect = true;

		}

		if ( isset( $_POST['sunshine_gallery_email'] ) ) {

			$email = sanitize_email( $_POST['sunshine_gallery_email'] );
			if ( is_email( $email ) ) {
				$gallery_emails = SPC()->session->get( 'gallery_emails' );
				if ( empty( $gallery_emails ) ) {
					$gallery_emails = array();
				}
				$gallery_emails[] = $gallery_id;
				SPC()->session->set( 'gallery_emails', $gallery_emails );
				SPC()->session->set( 'email', $email );
				$existing_emails = $gallery->get_emails();
				if ( ! is_array( $existing_emails ) || ! in_array( $email, $existing_emails ) ) {
					$gallery->add_email( $email );
					SPC()->log( 'Email address provided for ' . $gallery->get_name() . ': ' . $email );
					do_action( 'sunshine_gallery_email', $email, $gallery_id );
					$redirect = true;
				}
			} else {
				SPC()->notices->add( __( 'Not a valid email address', 'sunshine-photo-cart' ), 'error' );
				SPC()->log( 'Invalid email address provided for ' . $gallery->get_name() . ': ' . $email );
			}
		}

		if ( $redirect ) {
			if ( isset( $_POST['redirect_to'] ) ) {
				$redirect_url = sanitize_url( $_POST['redirect_to'] );
			} else {
				$redirect_url = $gallery->get_permalink();
			}
			$redirect_url = add_query_arg( 'access', time(), $redirect_url );
			wp_safe_redirect( $redirect_url );
			exit;
		}

	}

	public function check_account_endpoints() {
		global $wp_query;

		$logout_endpoint = SPC()->get_option( 'account_logout_endpoint', 'logout' );
		if ( isset( $wp_query->query_vars[ $logout_endpoint ] ) ) {
			SPC()->notices->add( __( 'You have been logged out', 'sunshine-photo-cart' ) );
			do_action( 'sunshine_logout' );
			wp_logout();
			wp_safe_redirect( apply_filters( 'sunshine_logout_redirect', sunshine_get_page_url( 'home' ) ) );
			exit;
		}
	}

	public function no_cache() {
		if ( is_sunshine() && ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	public function post_thumbnail_size( $size, $post_id ) {
		// Check if the post type is the one you want to target.
		if ( get_post_type( $post_id ) === 'sunshine-gallery' ) {
			$size = 'sunshine-large'; // Replace with your desired image size.
		}

		return $size;
	}


}
?>
