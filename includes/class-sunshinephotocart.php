<?php

defined( 'ABSPATH' ) || exit;

final class Sunshine_Photo_Cart {

	protected static $_instance = null;
	public $log_file;
	public $error_log_file;
	public $customer = null;
	public $session;
	public $cart;
	public $options;
	public $version;
	public $notices;
	public $countries;
	private $post_types = array();
	public $prefix;
	public $pages            = array();
	public $payment_methods  = array();
	public $shipping_methods = array();
	public $emails           = array();
	public $pro;
	public $plans = array();
	public $plan;
	public $frontend;
	public $active_addons = array();
	private $previous_error_handler = null;

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		add_action( 'init', array( $this, 'init' ), 1 );
	}

	public function log( $message ) {

		if ( ! SPC()->get_option( 'enable_log' ) ) {
			return;
		}

		if ( is_array( $message ) || is_object( $message ) ) {
			$message = print_r( $message, true );
		}

		$log_message = current_time( 'y-m-d H:i:s' ) . ': ' . $message;
		if ( is_user_logged_in() ) {
			$log_message .= ' (User ID: ' . get_current_user_id() . ')';
		}
		$log_message .= "\n";
		$fp           = fopen( $this->log_file, 'a' );
		if ( ! $fp ) {
			return;
		}
		fwrite( $fp, $log_message );
		fclose( $fp );

		if ( WP_DEBUG ) {
			sunshine_log( $message );
		}

	}

	public function includes() {

		// Utilities
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/modal.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/forms.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/misc.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/delivery-methods.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/shipping-methods.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/payment-methods.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/template.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/store.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/account.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/cart.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/checkout.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/order.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/product.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/product-options.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/gallery.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/image.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/taxes.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/formatting.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/add-to-cart.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/favorites.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/comments.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/share.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/watermark.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/seo.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/functions/plugin-compat.php';

		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/widgets.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/shortcodes.php';

		// Delivery Methods
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-delivery-method.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/delivery-methods/shipping.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/delivery-methods/pickup.php';

		// Shipping
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-shipping-method.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/shipping-methods/local.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/shipping-methods/flat-rate.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/shipping-methods/free.php';

		// Payment Methods
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-payment-methods.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-payment-method.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/payment-methods/test.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/payment-methods/free.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/payment-methods/offline.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/payment-methods/paypal.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/payment-methods/paypal-legacy.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/payment-methods/stripe.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/payment-methods/square.php';

		// Important classes
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-emails.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-email.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/emails/class-email-signup.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/emails/class-email-reset-password.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/emails/class-email-customer-receipt.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/emails/class-email-admin-receipt.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/emails/class-email-order-status.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/emails/class-email-order-comment.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/emails/class-email-admin-favorites.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/emails/class-email-custom-favorites.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/emails/class-email-summary.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/emails/class-email-image-comment.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/emails/class-email-admin-signup.php';

		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-notices.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-customer.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-countries.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-data.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-session.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-product.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-product-category.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-price-level.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-product-option.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-image.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-gallery.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-cart.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-cart-item.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-order.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-order-item.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-order-status.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-shipping.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-discount.php';

		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-frontend.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/template-hooks.php';
		// TODO: include_once SUNSHINE_PHOTO_CART_PATH . 'includes/blocks/gallery-images/gallery-images.php';

		// TODO: Why does this fail register_post_meta when behind is_admin()??
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/class-admin-meta-box.php';

		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/libraries/async-request.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/libraries/background-process.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-background.php';

		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/class-addon-update.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/class-license.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/tracking.php';
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/class-privacy.php';
		new SPC_Privacy();

		// Various admin functions
		if ( is_admin() ) {

			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/class-admin.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/class-update.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/sunshine-gallery.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/gallery-navigator.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/sunshine-product.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/sunshine-product-category.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/sunshine-order.php';

			// TODO: Only load some of these on necessary admin screens. Use admin version of is_sunshine somehow?
			// Notices likely needs to be on all pages
			// include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/class-license.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/addons.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/dashboard.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/system-info.php';

			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/settings-fields.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/class-options.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/options/shipping-methods.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/options/payment-methods.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/options/taxes.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/options/emails.php';

			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/tools.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/class-tool.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/tools/regenerate.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/tools/sessions.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/tools/orphans.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/tools/unused-image-sizes.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/tools/reinstall.php';
			// include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/tools/duplicate-images.php';

			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/customers.php';
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/class-customers-table.php';

			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/reports.php';

			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/promos.php';

		}

	}

	private function hooks() {

		// add_filter( 'attachment_link', array( $this, 'image_permalink' ), 10, 2 );
		/*
		add_filter( 'post_type_link', array( 'set_permalinks' ), 999, 2 );
		add_filter( 'the_permalink', array( 'set_permalinks' ), 999 );
		*/

	}

	public function init() {

		// Set log files
		$wp_upload_dir = wp_upload_dir();
		$log_file_name = get_option( 'sunshine_log_file_name' );
		if ( $log_file_name ) {
			$this->log_file = $wp_upload_dir['basedir'] . '/sunshine/' . $log_file_name;
		} else {
			$this->log_file = $wp_upload_dir['basedir'] . '/sunshine/sunshine.txt';
		}
		$error_log_file_name = get_option( 'sunshine_error_log_file_name' );
		if ( $error_log_file_name ) {
			$this->error_log_file = $wp_upload_dir['basedir'] . '/sunshine/' . $error_log_file_name;
		}

		$this->prefix = apply_filters( 'sunshine_prefix', 'sunshine_' );

		$this->includes();

		do_action( 'sunshine_before_init' );

		$this->active_addons = apply_filters( 'sunshine_active_addons', array() );

		$this->session = new SPC_Session();
		$this->version = $this->get_option( 'version' );

		$this->customer  = new SPC_Customer( get_current_user_id() );
		$this->countries = new SPC_Countries();
		$this->notices   = new SPC_Notices();

		$this->cart = new SPC_Cart();

		$this->emails          = new SPC_Emails();
		$this->payment_methods = new SPC_Payment_Methods();

		load_plugin_textdomain( 'sunshine-photo-cart', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );

		$this->set_pages();
		$this->post_types();
		$this->image_sizes();

		if ( ! is_admin() || wp_doing_ajax() ) {
			$this->frontend = new SPC_Frontend();
		}

		if ( is_admin() || wp_doing_cron() ) {
			$this->plans['pro']   = new SPC_License( 44, 'Sunshine Photo Cart Pro', SUNSHINE_PHOTO_CART_VERSION, '', 'pro' );
			$this->plans['plus']  = new SPC_License( 129771, 'Sunshine Photo Cart Plus', SUNSHINE_PHOTO_CART_VERSION, '', 'plus' );
			$this->plans['basic'] = new SPC_License( 129769, 'Sunshine Photo Cart Basic', SUNSHINE_PHOTO_CART_VERSION, '', 'basic' );
			foreach ( $this->plans as $plan ) {
				if ( $plan->is_valid() ) {
					$this->plan = $plan;
					break;
				}
			}
		}

		// TODO: move to function
		add_rewrite_endpoint( $this->get_option( 'account_orders_endpoint', 'my-orders' ), EP_PERMALINK | EP_PAGES );
		add_rewrite_endpoint( $this->get_option( 'account_view_order_endpoint', 'order-details' ), EP_PERMALINK | EP_PAGES );
		add_rewrite_endpoint( $this->get_option( 'account_addresses_endpoint', 'my-addresses' ), EP_PERMALINK | EP_PAGES );
		add_rewrite_endpoint( $this->get_option( 'account_galleries_endpoint', 'my-galleries' ), EP_PERMALINK | EP_PAGES );
		add_rewrite_endpoint( $this->get_option( 'account_edit_endpoint', 'my-profile' ), EP_PERMALINK | EP_PAGES );
		add_rewrite_endpoint( $this->get_option( 'account_reset_password_endpoint', 'reset-password' ), EP_PERMALINK | EP_PAGES );
		add_rewrite_endpoint( $this->get_option( 'account_login_endpoint', 'login' ), EP_PERMALINK | EP_PAGES );
		add_rewrite_endpoint( $this->get_option( 'account_logout_endpoint', 'logout' ), EP_PERMALINK | EP_PAGES );
		add_rewrite_endpoint( $this->get_option( 'endpoint_store', 'store' ), EP_PERMALINK | EP_PAGES );
		add_rewrite_endpoint( $this->get_option( 'endpoint_order_received', 'order-received' ), EP_PERMALINK | EP_PAGES );

		/*
		add_rewrite_endpoint( $this->get_option( 'endpoint_gallery' ), EP_PERMALINK | EP_PAGES );
		add_rewrite_endpoint( $this->get_option( 'endpoint_image' ), EP_PERMALINK | EP_PAGES );
		add_rewrite_endpoint( $this->get_option( 'endpoint_order' ), EP_PERMALINK | EP_PAGES );
		*/

		do_action( 'sunshine_after_init' );

		if ( $this->get_option( 'enable_error_log' ) && $this->error_log_file ) {
			$this->register_error_handlers();
		}

	}

	private function set_pages() {
		$pages       = array(
			'home'      => SPC()->get_option( 'page' ),
			'account'   => SPC()->get_option( 'page_account' ),
			'cart'      => SPC()->get_option( 'page_cart' ),
			'checkout'  => SPC()->get_option( 'page_checkout' ),
			'favorites' => SPC()->get_option( 'page_favorites' ),
			'terms'     => SPC()->get_option( 'page_terms' ),
		);
		$this->pages = apply_filters( 'sunshine_pages', $pages );
	}

	public function get_page( $page ) {
		if ( is_numeric( $page ) ) {
			if ( in_array( $page, $this->pages ) ) {
				return $this->pages[ array_search( $page, $this->pages ) ];
			}
		} else {
			if ( array_key_exists( $page, $this->pages ) ) {
				return $this->pages[ $page ];
			}
		}
		return false;
	}

	public function payment_methods() {
		return SPC_Payment_Methods::instance();
	}

	public function emails() {
		return SPC_Emails::instance();
	}

	private function post_types() {

		$this->post_types = array( 'sunshine-gallery', 'sunshine-product', 'sunshine-order' );

		$plugin_dir_path = dirname( __FILE__ );
		$menu_icon       = plugins_url( 'assets/images/sunshine-icon.png', $plugin_dir_path );

		$base_path = trailingslashit( get_post_field( 'post_name', $this->get_page( 'home' ) ) );

		/* SUNSHINE GALLERIES post type */
		$labels = array(
			'name'                  => __( 'Galleries', 'sunshine-photo-cart' ),
			'singular_name'         => __( 'Gallery', 'sunshine-photo-cart' ),
			'menu_name'             => 'Sunshine',
			'name_admin_bar'        => __( 'Gallery', 'sunshine-photo-cart' ),
			'add_new'               => __( 'Add New', 'sunshine-photo-cart' ),
			'add_new_item'          => __( 'Add New Gallery', 'sunshine-photo-cart' ),
			'new_item'              => __( 'New Gallery', 'sunshine-photo-cart' ),
			'edit_item'             => __( 'Edit Gallery', 'sunshine-photo-cart' ),
			'view_item'             => __( 'View Gallery', 'sunshine-photo-cart' ),
			'all_items'             => __( 'All Galleries', 'sunshine-photo-cart' ),
			'search_items'          => __( 'Search Galleries', 'sunshine-photo-cart' ),
			'parent_item_colon'     => __( 'Parent Gallery:', 'sunshine-photo-cart' ),
			'not_found'             => __( 'No galleries found.', 'sunshine-photo-cart' ),
			'not_found_in_trash'    => __( 'No galleries found in Trash', 'sunshine-photo-cart' ),
			'featured_image'        => __( 'Gallery Featured Image', 'sunshine-photo-cart' ),
			'set_featured_image'    => __( 'Set featured image image', 'sunshine-photo-cart' ),
			'remove_featured_image' => __( 'Remove featured image', 'sunshine-photo-cart' ),
			'use_featured_image'    => __( 'Use as featured image', 'sunshine-photo-cart' ),
			'archives'              => __( 'Gallery archives', 'sunshine-photo-cart' ),
			'insert_into_item'      => __( 'Insert into gallery', 'sunshine-photo-cart' ),
			'uploaded_to_this_item' => __( 'Uploaded to this gallery', 'sunshine-photo-cart' ),
			'filter_items_list'     => __( 'Filter galleries list', 'sunshine-photo-cart' ),
			'items_list_navigation' => __( 'Galleries list navigation', 'sunshine-photo-cart' ),
			'items_list'            => __( 'Galleries list', 'sunshine-photo-cart' ),
		);
		$args   = array(
			'labels'              => $labels,
			'public'              => true,
			'exclude_from_search' => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_nav_menus'   => true,
			'show_in_menu'        => true,
			'menu_icon'           => $menu_icon,
			'menu_position'       => 45,
			'query_var'           => true,
			'has_archive'         => false,
			'hierarchical'        => true,
			'capability_type'     => array( 'sunshine_gallery', 'sunshine_galleries' ),
			'map_meta_cap'        => true,
			'show_in_rest'        => true,
			'rewrite'             => array(
				'slug'       => $base_path . SPC()->get_option( 'endpoint_gallery', 'gallery' ),
				'with_front' => false,
			),
			'supports'            => array( 'title', 'editor', 'page-attributes', 'thumbnail' ),
		);

		register_post_type( 'sunshine-gallery', apply_filters( 'sunshine_gallery_post_type_args', $args ) );

		/* SUNSHINE_PRODUCTS Custom Post Type */
		$labels = array(
			'name'               => __( 'Products', 'sunshine-photo-cart' ),
			'singular_name'      => __( 'Product', 'sunshine-photo-cart' ),
			'add_new'            => __( 'Add Product', 'sunshine-photo-cart' ),
			'add_new_item'       => __( 'Add New Product', 'sunshine-photo-cart' ),
			'edit_item'          => __( 'Edit Product', 'sunshine-photo-cart' ),
			'new_item'           => __( 'New Product', 'sunshine-photo-cart' ),
			'all_items'          => __( 'All Products', 'sunshine-photo-cart' ),
			'view_item'          => __( 'View Products', 'sunshine-photo-cart' ),
			'search_items'       => __( 'Search Products', 'sunshine-photo-cart' ),
			'not_found'          => __( 'No products found', 'sunshine-photo-cart' ),
			'not_found_in_trash' => __( 'No products found in trash', 'sunshine-photo-cart' ),
			'parent_item_colon'  => '',
			'menu_name'          => __( 'Products', 'sunshine-photo-cart' ),
		);
		$args   = array(
			'labels'              => $labels,
			'public'              => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_nav_menus'   => false,
			'show_in_menu'        => true,
			'menu_icon'           => $menu_icon,
			'menu_position'       => 46,
			'query_var'           => true,
			'rewrite'             => true,
			'capability_type'     => 'sunshine_product',
			'map_meta_cap'        => true,
			'has_archive'         => false,
			'hierarchical'        => false,
			'show_in_rest'        => true,
			'supports'            => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
		);
		register_post_type( 'sunshine-product', $args );

		$labels = array(
			'name'                       => __( 'Product Categories', 'sunshine-photo-cart' ),
			'singular_name'              => __( 'Product Category', 'sunshine-photo-cart' ),
			'search_items'               => __( 'Search Product Categories', 'sunshine-photo-cart' ),
			'all_items'                  => __( 'All Product Categories', 'sunshine-photo-cart' ),
			'parent_item'                => __( 'Parent Product Category', 'sunshine-photo-cart' ),
			'parent_item_colon'          => __( 'Parent Product Category:', 'sunshine-photo-cart' ),
			'edit_item'                  => __( 'Edit Product Category', 'sunshine-photo-cart' ),
			'update_item'                => __( 'Update Product Category', 'sunshine-photo-cart' ),
			'add_new_item'               => __( 'Add New Product Category', 'sunshine-photo-cart' ),
			'new_item_name'              => __( 'New Product Category Name', 'sunshine-photo-cart' ),
			'separate_items_with_commas' => __( 'Separate categories with commas', 'sunshine-photo-cart' ),
			'choose_from_most_used'      => __( 'Choose from the most used categories', 'sunshine-photo-cart' ),
			'popular_items'              => null,
			'name_field_description'     => '',
			'desc_field_description'     => '',
		);
		$args   = array(
			'label'              => __( 'Product Category', 'sunshine-photo-cart' ),
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_nav_menus'  => false,
			'capabilities'       => array(
				'manage_terms' => 'edit_sunshine_products',
				'edit_terms'   => 'edit_sunshine_products',
				'delete_terms' => 'edit_sunshine_products',
				'assign_terms' => 'edit_sunshine_products',
			),
			'hierarchical'       => true,
			'query_var'          => false,
			'show_in_rest'       => true,
		);
		register_taxonomy( 'sunshine-product-category', 'sunshine-product', $args );

		$labels = array(
			'name'                   => __( 'Price Levels', 'sunshine-photo-cart' ),
			'singular_name'          => __( 'Price Level', 'sunshine-photo-cart' ),
			'search_items'           => __( 'Search Price Levels', 'sunshine-photo-cart' ),
			'all_items'              => __( 'All Price Levels', 'sunshine-photo-cart' ),
			'parent_item'            => __( 'Parent Price Level', 'sunshine-photo-cart' ),
			'parent_item_colon'      => __( 'Parent Price Level:', 'sunshine-photo-cart' ),
			'edit_item'              => __( 'Edit Price Level', 'sunshine-photo-cart' ),
			'update_item'            => __( 'Update Price Level', 'sunshine-photo-cart' ),
			'add_new_item'           => __( 'Add New Price Level', 'sunshine-photo-cart' ),
			'new_item_name'          => __( 'New Price Level', 'sunshine-photo-cart' ),
			'name_field_description' => '',
			'desc_field_description' => '',
		);
		$args   = array(
			'label'              => __( 'Price Level', 'sunshine-photo-cart' ),
			'labels'             => $labels,
			'capabilities'       => array(
				'manage_terms' => 'edit_sunshine_products',
				'edit_terms'   => 'edit_sunshine_products',
				'delete_terms' => 'edit_sunshine_products',
				'assign_terms' => 'edit_sunshine_products',
			),
			'public'             => false,
			'hierarchical'       => false,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'query_var'          => true,
			'show_in_nav_menus'  => false,
			'show_in_quick_edit' => false,
		);
		register_taxonomy( 'sunshine-product-price-level', 'sunshine-product', apply_filters( 'sunshine_product_price_level_args', $args ) );

		/* SUNSHINE_ORDERS Custom Post Type */
		$labels = array(
			'name'               => __( 'Orders', 'sunshine-photo-cart' ),
			'singular_name'      => __( 'Order', 'sunshine-photo-cart' ),
			'add_new'            => __( 'Add New', 'sunshine-photo-cart' ),
			'add_new_item'       => __( 'Add New Order', 'sunshine-photo-cart' ),
			'edit_item'          => __( 'Edit Order', 'sunshine-photo-cart' ),
			'new_item'           => __( 'New Order', 'sunshine-photo-cart' ),
			'all_items'          => __( 'All Orders', 'sunshine-photo-cart' ),
			'view_item'          => __( 'View Orders', 'sunshine-photo-cart' ),
			'search_items'       => __( 'Search Orders', 'sunshine-photo-cart' ),
			'not_found'          => __( 'No orders found', 'sunshine-photo-cart' ),
			'not_found_in_trash' => __( 'No orders found in trash', 'sunshine-photo-cart' ),
			'parent_item_colon'  => '',
			'menu_name'          => __( 'Orders', 'sunshine-photo-cart' ),
		);
		$args   = array(
			'labels'              => $labels,
			'public'              => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_nav_menus'   => false,
			'show_in_menu'        => true,
			'menu_icon'           => $menu_icon,
			'menu_position'       => 47,
			'query_var'           => true,
			'capability_type'     => 'sunshine_order',
			'capabilities'        => array(
				'edit_post'              => 'edit_sunshine_order',
				'read_post'              => 'read_sunshine_order',
				'delete_post'            => 'delete_sunshine_order',
				'edit_posts'             => 'edit_sunshine_orders',
				'edit_others_posts'      => 'edit_others_sunshine_orders',
				'publish_posts'          => 'publish_sunshine_orders',
				'read_private_posts'     => 'read_private_sunshine_orders',
				'delete_posts'           => 'delete_sunshine_orders',
				'delete_private_posts'   => 'delete_private_sunshine_orders',
				'delete_published_posts' => 'delete_published_sunshine_orders',
				'delete_others_posts'    => 'delete_others_sunshine_orders',
				'edit_private_posts'     => 'edit_private_sunshine_orders',
				'edit_published_posts'   => 'edit_published_sunshine_orders',
				'create_posts'           => 'do_not_allow',
			),
			'map_meta_cap'        => false,
			'has_archive'         => false,
			'hierarchical'        => false,
		);
		register_post_type( 'sunshine-order', $args );

		$labels = array(
			'name'              => __( 'Order Statuses', 'sunshine-photo-cart' ),
			'singular_name'     => __( 'Order Status', 'sunshine-photo-cart' ),
			'search_items'      => __( 'Search Order Status', 'sunshine-photo-cart' ),
			'all_items'         => __( 'All Order Status', 'sunshine-photo-cart' ),
			'parent_item'       => __( 'Parent Order Status', 'sunshine-photo-cart' ),
			'parent_item_colon' => __( 'Parent Order Status:', 'sunshine-photo-cart' ),
			'edit_item'         => __( 'Edit Order Status', 'sunshine-photo-cart' ),
			'update_item'       => __( 'Update Order Status', 'sunshine-photo-cart' ),
			'add_new_item'      => __( 'Add New Order Status', 'sunshine-photo-cart' ),
			'new_item_name'     => __( 'New Order Status', 'sunshine-photo-cart' ),
		);
		$args   = array(
			'label'             => __( 'Order Status', 'sunshine-photo-cart' ),
			'labels'            => $labels,
			'public'            => false,
			'hierarchical'      => false,
			'show_ui'           => true,
			'query_var'         => true,
			'show_in_nav_menus' => false,
			'capabilities'      => array(
				'manage_terms' => 'manage_sunshine_order_statuses',
				'edit_terms'   => 'edit_sunshine_order_statuses',
				'delete_terms' => 'delete_sunshine_order_statuses',
				'assign_terms' => 'assign_sunshine_order_statuses',
			),
		);
		register_taxonomy( 'sunshine-order-status', 'sunshine-order', $args );

	}

	public function get_post_types() {
		return apply_filters( 'sunshine_post_types', $this->post_types );
	}

	private function image_sizes() {

		// Allow post thumbnails if current theme doesn't have it already
		if ( ! current_theme_supports( 'post-thumbnails' ) ) {
			add_theme_support( 'post-thumbnails' );
		}

		// Define Sunshine's thumbnail image size
		$thumbnail_size   = $this->get_option( 'thumbnail_size' );
		$thumbnail_width  = ( ! empty( $thumbnail_size['w'] ) ) ? $thumbnail_size['w'] : 600;
		$thumbnail_height = ( ! empty( $thumbnail_size['h'] ) ) ? $thumbnail_size['h'] : 450;
		$large_size       = $this->get_option( 'large_size' );
		$large_width      = ( ! empty( $large_size['w'] ) ) ? $large_size['w'] : 1200;
		$large_height     = ( ! empty( $large_size['h'] ) ) ? $large_size['h'] : 1200;
		add_image_size( 'sunshine-thumbnail', $thumbnail_width, $thumbnail_height, $this->get_option( 'thumbnail_crop' ) );
		add_image_size( 'sunshine-large', $large_width, $large_height, false );

		if ( is_sunshine() ) {
			set_post_thumbnail_size( $thumbnail_size['w'], $thumbnail_size['h'], $this->get_option( 'thumbnail_crop' ) );
		}

	}

	public function get_option( $key, $default = false ) {
		$value = get_option( $this->prefix . $key, $default );
		if ( empty( $value ) && $default ) {
			$value = $default;
		}
		return ( $value !== '' ) ? maybe_unserialize( $value ) : '';
	}

	public function update_option( $key, $value, $autoload = false ) {
		update_option( $this->prefix . $key, $value, $autoload );
	}

	// Backwards compat
	public function is_pro() {
		if ( ! empty( $this->plans['pro'] ) && $this->plans['pro']->is_valid() ) {
			return true;
		}
		return false;
	}

	public function has_plan() {
		return ( ! empty( $this->plan ) && $this->plan->is_valid() ) ? true : false;
	}

	/*
	public function get_license_key() {
		return get_option( 'sunshine_license_key' );
	}
	*/

	public function has_addon( $slug ) {
		if ( is_plugin_active( 'sunshine-' . $slug ) ) {
			return true;
		}
		if ( file_exists( SUNSHINE_PHOTO_CART_PATH . 'add-ons/' . $slug . '/' . $slug . '.php' ) ) {
			return true;
		}
		return false;
	}

	public function store_enabled() {
		$enabled = true;
		if ( ! $this->get_option( 'allow_store' ) || SPC()->get_option( 'proofing' ) ) {
			$enabled = false;
		}
		return $enabled;
	}

	public function generate_log_filename( $type = 'log' ) {
		$random    = wp_generate_password( 16, false );
		$prefix    = ( $type === 'error_log' ) ? 'sunshine-errors-' : 'sunshine-';
		$file_name = $prefix . $random . '.txt';
		$option    = ( $type === 'error_log' ) ? 'sunshine_error_log_file_name' : 'sunshine_log_file_name';
		update_option( $option, $file_name, false );

		$wp_upload_dir = wp_upload_dir();
		$file_path     = $wp_upload_dir['basedir'] . '/sunshine/' . $file_name;

		if ( $type === 'error_log' ) {
			$this->error_log_file = $file_path;
		} else {
			$this->log_file = $file_path;
		}

		return $file_name;
	}

	public function register_error_handlers() {
		$this->previous_error_handler = set_error_handler( array( $this, 'php_error_handler' ) );
		register_shutdown_function( array( $this, 'php_fatal_error_handler' ) );
	}

	public function php_error_handler( $errno, $errstr, $errfile, $errline ) {
		if ( ! ( error_reporting() & $errno ) ) {
			if ( is_callable( $this->previous_error_handler ) ) {
				return call_user_func( $this->previous_error_handler, $errno, $errstr, $errfile, $errline );
			}
			return false;
		}

		$error_types = array(
			E_NOTICE          => 'PHP Notice',
			E_WARNING         => 'PHP Warning',
			E_DEPRECATED      => 'PHP Deprecated',
			E_USER_NOTICE     => 'PHP User Notice',
			E_USER_WARNING    => 'PHP User Warning',
			E_USER_DEPRECATED => 'PHP User Deprecated',
			E_STRICT          => 'PHP Strict',
		);

		$type = isset( $error_types[ $errno ] ) ? $error_types[ $errno ] : 'PHP Error ' . $errno;

		$message = sprintf( '[%s] %s in %s on line %d', $type, $errstr, $errfile, $errline );

		$log_message = current_time( 'y-m-d H:i:s' ) . ': ' . $message . "\n";
		$fp          = fopen( $this->error_log_file, 'a' );
		if ( $fp ) {
			fwrite( $fp, $log_message );
			fclose( $fp );
		}

		if ( is_callable( $this->previous_error_handler ) ) {
			return call_user_func( $this->previous_error_handler, $errno, $errstr, $errfile, $errline );
		}

		return false;
	}

	public function php_fatal_error_handler() {
		$error = error_get_last();
		if ( $error === null ) {
			return;
		}

		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR );
		if ( ! in_array( $error['type'], $fatal_types ) ) {
			return;
		}

		$message = sprintf( '[PHP Fatal Error] %s in %s on line %d', $error['message'], $error['file'], $error['line'] );

		$log_message = current_time( 'y-m-d H:i:s' ) . ': ' . $message . "\n";
		$fp          = @fopen( $this->error_log_file, 'a' );
		if ( $fp ) {
			fwrite( $fp, $log_message );
			fclose( $fp );
		}
	}

}
