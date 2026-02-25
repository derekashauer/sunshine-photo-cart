<?php
function sunshine_activation() {

	$install_time = get_option( 'sunshine_install_time' );
	if ( empty( $install_time ) ) {
		update_option( 'sunshine_install_time', current_time( 'timestamp' ), false );
		update_option( 'sunshine_install_redirect', 1, false );
		maybe_sunshine_create_custom_tables();
	}

}

function maybe_sunshine_create_custom_tables() {
	global $wpdb;

	// Setup custom tables.
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$collate = '';
	if ( $wpdb->has_cap( 'collation' ) ) {
		$collate = $wpdb->get_charset_collate();
	}
	$created = maybe_create_table(
		"{$wpdb->prefix}sunshine_sessions",
		"CREATE TABLE {$wpdb->prefix}sunshine_sessions (
			session_id char(32) NOT NULL,
			data LONGTEXT NOT NULL,
			expiration BIGINT(20) UNSIGNED NOT NULL,
			PRIMARY KEY  (session_id)
		) $collate;"
	);

	$created = maybe_create_table(
		"{$wpdb->prefix}sunshine_order_items",
		"CREATE TABLE `{$wpdb->prefix}sunshine_order_items` (
			`order_item_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`order_id` bigint(20) unsigned NOT NULL,
			`type` varchar(50) COLLATE utf8mb4_unicode_520_ci NOT NULL,
			`image_id` bigint(20) NOT NULL,
			`qty` tinyint(3) unsigned NOT NULL,
			`product_id` bigint(20) unsigned NOT NULL,
			`gallery_id` bigint(20) unsigned NOT NULL,
			`price_level` bigint(20) unsigned NOT NULL,
			`price` float unsigned NOT NULL,
			`tax` float unsigned NOT NULL,
			`discount` float unsigned NOT NULL,
			PRIMARY KEY (`order_item_id`)
		) $collate;"
	);

	$created = maybe_create_table(
		"{$wpdb->prefix}sunshine_order_itemmeta",
		"CREATE TABLE `{$wpdb->prefix}sunshine_order_itemmeta` (
			`order_itemmeta_id` bigint(20) NOT NULL AUTO_INCREMENT,
			  `order_item_id` bigint(20) NOT NULL,
			  `meta_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
			  `meta_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
			  PRIMARY KEY (`order_itemmeta_id`),
			  KEY `order_item_id` (`order_item_id`),
			  KEY `meta_key` (`meta_key`)
		) $collate;"
	);

}

add_action( 'admin_init', 'sunshine_install_redirect' );
function sunshine_install_redirect() {
	if ( get_option( 'sunshine_install_redirect', false ) ) {
		// Check user capabilities - require either capability.
		if ( ! ( current_user_can( 'sunshine_manage_options' ) || current_user_can( 'manage_options' ) ) ) {
			return;
		}
		sunshine_base_install();
		delete_option( 'sunshine_install_redirect' );
		wp_redirect( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install' ) );
		exit;
	}
}

add_action( 'admin_init', 'sunshine_force_base_install' );
function sunshine_force_base_install() {
	if ( isset( $_GET['sunshine_force_base_install'] ) ) {
		// Check user capabilities - require either capability.
		if ( ! ( current_user_can( 'sunshine_manage_options' ) || current_user_can( 'manage_options' ) ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'sunshine-photo-cart' ), 403 );
		}

		sunshine_base_install();
		wp_redirect( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install' ) );
		exit;
	}
}

// On install, flush rewrite rules.
add_action( 'admin_init', 'sunshine_flush_rewrite_rules', 100 );
function sunshine_flush_rewrite_rules() {
	if ( is_admin() && isset( $_GET['page'] ) && sanitize_text_field( wp_unslash( $_GET['page'] ) ) === 'sunshine-install' ) {
		// Check user capabilities - require either capability.
		if ( ! ( current_user_can( 'sunshine_manage_options' ) || current_user_can( 'manage_options' ) ) ) {
			return;
		}
		flush_rewrite_rules();
	}
}

function sunshine_deactivation() {
	wp_clear_scheduled_hook( 'sunshine_addon_check' );
	wp_clear_scheduled_hook( 'sunshine_session_garbage_collection' );
	wp_clear_scheduled_hook( 'sunshine_send_summary' );
	wp_clear_scheduled_hook( 'sunshine_license_check' );
	wp_clear_scheduled_hook( 'sunshine_tracking_send' );
}

function sunshine_base_install() {
	global $wpdb;

	SPC()->log( 'Sunshine Photo Cart base install...' );
	update_option( 'sunshine_version', SUNSHINE_PHOTO_CART_VERSION, false );

	sunshine_set_roles();

	// Get default options so we don't redo any.
	$options      = array();
	$options_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->options} WHERE option_name LIKE 'sunshine_%'" );
	foreach ( $options_rows as $option_row ) {
		$key             = str_replace( 'sunshine_', '', $option_row->option_name );
		$options[ $key ] = maybe_unserialize( $option_row->option_value );
	}

	// Default options
	$defaults = array(
		'currency'                        => 'USD',
		'currency_symbol_position'        => 'left',
		'currency_thousands_separator'    => ',',
		'currency_decimal_separator'      => '.',
		'currency_decimals'               => '2',
		'currency_show_code'              => false,

		'endpoint_gallery'                => 'gallery',
		'endpoint_order_received'         => 'receipt',
		'endpoint_store'                  => 'store',
		'account_orders_endpoint'         => 'my-orders',
		'account_view_order_endpoint'     => '',
		'account_addresses_endpoint'      => '',
		'account_edit_endpoint'           => '',
		'account_login_endpoint'          => '',
		'account_reset_password_endpoint' => '',
		'account_logout_endpoint'         => '',
		'account_logout_endpoint'         => '',

		'delete_images'                   => true,
		'delete_images_folder'            => false,
		'show_media_library'              => false,

		'hide_galleries'                  => false,
		'gallery_order'                   => 'date_new_old',
		'image_order'                     => 'date_new_old',
		'gallery_layout'                  => 'justified',
		'image_layout'                    => 'justified',
		'columns'                         => 4,
		'per_page'                        => 20,
		'pagination'                      => 'numbers',
		'disable_right_click'             => false,
		'proofing'                        => false,
		'show_image_data'                 => '',
		'disable_favorites'               => false,
		'enable_favorites'                => true,
		'disable_sharing'                 => false,
		'allow_store'                     => true,
		'allow_gallery_sharing'           => true,
		'allow_image_sharing'             => true,
		'use_secure_file_names'           => true,

		'thumbnail_size'                  => array(
			'w' => 400,
			'h' => 400,
		),
		'thumbnail_crop'                  => false,
		'large_size'                      => array(
			'w' => 1000,
			'h' => 1000,
		),
		'image_quality'                   => 95,

		'display_price'                   => 'without_tax',
		'price_has_tax'                   => 'no',

		'checkout_standalone'             => false,
		'allow_guest_checkout'            => true,

		'main_menu'                       => true,

		'from_name'                       => get_bloginfo( 'name' ),
		'from_email'                      => get_bloginfo( 'admin_email' ),

		'theme'                           => 'theme',
	);

	$options = wp_parse_args( $options, $defaults );

	if ( empty( $options['endpoint_gallery'] ) ) {
		$post_types = get_post_types();
		foreach ( $post_types as $post_type ) {
			if ( $post_type == 'gallery' ) {
				$options['endpoint_gallery'] = 'sgallery';
			}
		}
	}

	if ( empty( $options['page'] ) ) {
		$options['page'] = wp_insert_post(
			array(
				'post_title'     => __( 'Client Galleries', 'sunshine-photo-cart' ),
				'post_content'   => '<!-- wp:shortcode -->[sunshine_galleries]<!-- /wp:shortcode -->',
				'post_type'      => 'page',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_status'    => 'publish',
			)
		);
	}
	if ( empty( $options['page_account'] ) ) {
		$options['page_account'] = wp_insert_post(
			array(
				'post_title'     => __( 'Account', 'sunshine-photo-cart' ),
				'post_content'   => '<!-- wp:shortcode -->[sunshine_account]<!-- /wp:shortcode -->',
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_parent'    => $options['page'],
			)
		);
	}
	if ( empty( $options['page_cart'] ) ) {
		$options['page_cart'] = wp_insert_post(
			array(
				'post_title'     => __( 'Cart', 'sunshine-photo-cart' ),
				'post_content'   => '<!-- wp:shortcode -->[sunshine_cart]<!-- /wp:shortcode -->',
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_parent'    => $options['page'],
			)
		);
	}
	if ( empty( $options['page_checkout'] ) ) {
		$options['page_checkout'] = wp_insert_post(
			array(
				'post_title'     => __( 'Checkout', 'sunshine-photo-cart' ),
				'post_content'   => '<!-- wp:shortcode -->[sunshine_checkout]<!-- /wp:shortcode -->',
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_parent'    => $options['page'],
			)
		);
	}
	if ( empty( $options['page_terms'] ) ) {
		$options['page_terms'] = wp_insert_post(
			array(
				'post_title'     => __( 'Terms & Conditions', 'sunshine-photo-cart' ),
				'post_content'   => '',
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_parent'    => $options['page'],
			)
		);
	}
	if ( empty( $options['page_favorites'] ) ) {
		$options['page_favorites'] = wp_insert_post(
			array(
				'post_title'     => __( 'Favorites', 'sunshine-photo-cart' ),
				'post_content'   => '<!-- wp:shortcode -->[sunshine_favorites]<!-- /wp:shortcode -->',
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_parent'    => $options['page'],
			)
		);
	}

	if ( ! term_exists( 'pending', 'sunshine-order-status' ) ) {
		wp_insert_term(
			__( 'Pending Payment', 'sunshine-photo-cart' ),
			'sunshine-order-status',
			array(
				'slug'        => 'pending',
				'description' => __(
					'We have received your order but payment is still pending',
					'sunshine-photo-cart'
				),
			)
		);
	}
	if ( ! term_exists( 'new', 'sunshine-order-status' ) ) {
		wp_insert_term(
			__( 'New', 'sunshine-photo-cart' ),
			'sunshine-order-status',
			array(
				'slug'        => 'new',
				'description' => __(
					'We have received your order and payment',
					'sunshine-photo-cart'
				),
			)
		);
	}
	if ( ! term_exists( 'processing', 'sunshine-order-status' ) ) {
		wp_insert_term(
			__( 'Processing', 'sunshine-photo-cart' ),
			'sunshine-order-status',
			array(
				'slug'        => 'processing',
				'description' => __(
					'The images in your order are being processed and/or printed',
					'sunshine-photo-cart'
				),
			)
		);
	}
	if ( ! term_exists( 'shipped', 'sunshine-order-status' ) ) {
		wp_insert_term(
			__( 'Shipped/Completed', 'sunshine-photo-cart' ),
			'sunshine-order-status',
			array(
				'slug'        => 'shipped',
				'description' => __(
					'Your items have shipped (or are available for download)!',
					'sunshine-photo-cart'
				),
			)
		);
	}
	if ( ! term_exists( 'cancelled', 'sunshine-order-status' ) ) {
		wp_insert_term(
			__( 'Cancelled', 'sunshine-photo-cart' ),
			'sunshine-order-status',
			array(
				'slug'        => 'cancelled',
				'description' => __(
					'Your order was cancelled',
					'sunshine-photo-cart'
				),
			)
		);
	}
	if ( ! term_exists( 'refunded', 'sunshine-order-status' ) ) {
		wp_insert_term(
			__( 'Refunded', 'sunshine-photo-cart' ),
			'sunshine-order-status',
			array(
				'slug'        => 'refunded',
				'description' => __(
					'Your order was refunded',
					'sunshine-photo-cart'
				),
			)
		);
	}
	if ( ! term_exists( 'pickup', 'sunshine-order-status' ) ) {
		wp_insert_term(
			__( 'Ready for pickup', 'sunshine-photo-cart' ),
			'sunshine-order-status',
			array(
				'slug'        => 'pickup',
				'description' => __(
					'Your order is ready to be picked up',
					'sunshine-photo-cart'
				),
			)
		);
	}
	if ( ! term_exists( 'failed', 'sunshine-order-status' ) ) {
		wp_insert_term(
			__( 'Failed', 'sunshine-photo-cart' ),
			'sunshine-order-status',
			array(
				'slug'        => 'failed',
				'description' => __(
					'Payment failed',
					'sunshine-photo-cart'
				),
			)
		);
	}

	$terms = get_terms( 'sunshine-product-price-level', array( 'hide_empty' => 0 ) );
	if ( empty( $terms ) ) {
		$result = wp_insert_term( __( 'Default', 'sunshine-photo-cart' ), 'sunshine-product-price-level' );
		if ( ! is_wp_error( $result ) ) {
			add_term_meta( $result['term_id'], 'default', true );
		}
		SPC()->log( 'Creating default price level' );
	}

	$terms = get_terms( 'sunshine-product-category', array( 'hide_empty' => 0 ) );
	if ( empty( $terms ) ) {
		$result = wp_insert_term( __( 'Prints', 'sunshine-photo-cart' ), 'sunshine-product-category' );
		if ( ! is_wp_error( $result ) ) {
			add_term_meta( $result['term_id'], 'default', true );
		}
		SPC()->log( 'Creating default product category' );
	}

	SPC()->log( 'Setting up scheduled events' );

	if ( ! wp_next_scheduled( 'sunshine_addon_check' ) ) {
		wp_schedule_event( time(), 'weekly', 'sunshine_addon_check' );
	}
	if ( ! wp_next_scheduled( 'sunshine_session_garbage_collection' ) ) {
		wp_schedule_event( time(), 'twicedaily', 'sunshine_session_garbage_collection' );
	}
	if ( ! wp_next_scheduled( 'sunshine_send_summary' ) ) {
		$week_start = (int) get_option( 'start_of_week' );
		$start      = strtotime( "Sunday + $week_start days 8am" );
		wp_schedule_event( $start, 'weekly', 'sunshine_send_summary' );
	}
	if ( ! wp_next_scheduled( 'sunshine_license_check' ) ) {
		wp_schedule_event( time(), 'weekly', 'sunshine_license_check' );
	}
	if ( ! wp_next_scheduled( 'sunshine_tracking_send' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', 'sunshine_tracking_send' );
	}
	if ( ! wp_next_scheduled( 'sunshine_daily' ) ) {
		wp_schedule_event( time(), 'daily', 'sunshine_daily' );
	}

	// Make default directory for uploads.
	$upload_dir = wp_upload_dir();
	if ( ! is_dir( $upload_dir['basedir'] . '/sunshine' ) ) {
		wp_mkdir_p( $upload_dir['basedir'] . '/sunshine' );
		wp_mkdir_p( $upload_dir['basedir'] . '/sunshine/upload' );
		SPC()->log( 'Creating default upload directory' );
	}

	// Enable all emails and set default for them.
	$emails = SPC()->emails->get_emails();
	foreach ( $emails as $id => $email ) {
		$options[ 'email_' . $id . '_active' ] = true;
		if ( $email->allows_custom_recipients() ) {
			$options[ 'email_' . $id . '_recipients' ] = get_bloginfo( 'admin_email' );
		}
	}

	SPC()->log( 'Setting default options' );
	foreach ( $options as $key => $value ) {
		update_option( 'sunshine_' . $key, $value, false );
	}

	sunshine_create_htaccess( true );

	flush_rewrite_rules();

}

function sunshine_set_roles() {

	SPC()->log( 'Setting up roles' );
	add_role( 'sunshine_customer', 'Sunshine Customer' );
	add_role( 'sunshine_manager', 'Sunshine Manager' );
	$manager = get_role( 'sunshine_manager' );
	$admin   = get_role( 'administrator' );

	$capabilities = array(

		'edit_sunshine_gallery',
		'read_sunshine_gallery',
		'delete_sunshine_gallery',
		'edit_sunshine_galleries',
		'edit_others_sunshine_galleries',
		'publish_sunshine_galleries',
		'publish_sunshine_gallery',
		'read_private_sunshine_galleries',
		'delete_sunshine_galleries',
		'delete_private_sunshine_galleries',
		'delete_published_sunshine_galleries',
		'delete_others_sunshine_galleries',
		'edit_private_sunshine_galleries',
		'edit_published_sunshine_galleries',

		'edit_sunshine_product',
		'read_sunshine_product',
		'delete_sunshine_product',
		'edit_sunshine_products',
		'edit_others_sunshine_products',
		'publish_sunshine_products',
		'publish_sunshine_product',
		'read_private_sunshine_products',
		'delete_sunshine_products',
		'delete_private_sunshine_products',
		'delete_published_sunshine_products',
		'delete_others_sunshine_products',
		'edit_private_sunshine_products',
		'edit_published_sunshine_products',

		'edit_sunshine_order',
		'edit_sunshine_orders',
		'edit_others_sunshine_orders',
		'edit_others_sunshine_orders',
		'read_sunshine_order',
		'delete_sunshine_order',
		'read_private_sunshine_orders',
		'delete_sunshine_orders',
		'delete_private_sunshine_orders',
		'delete_published_sunshine_orders',
		'delete_others_sunshine_orders',
		'edit_private_sunshine_orders',
		'edit_published_sunshine_orders',

		'manage_sunshine_order_statuses',
		'edit_sunshine_order_statuses',
		'delete_sunshine_order_statuses',
		'assign_sunshine_order_statuses',

		'edit_sunshine_discount',
		'read_sunshine_discount',
		'delete_sunshine_discount',
		'edit_sunshine_discounts',
		'edit_others_sunshine_discounts',
		'publish_sunshine_discounts',
		'publish_sunshine_discount',
		'delete_sunshine_discounts',
		'delete_published_sunshine_discounts',
		'delete_others_sunshine_discounts',
		'edit_published_sunshine_discounts',

		'sunshine_manage_options',

		'sunshine_customers',
		'sunshine_reports',
		'sunshine_tools',
		'sunshine_addons',

		'read',

		'upload_files',
	);
	foreach ( $capabilities as $cap ) {
		$admin->add_cap( $cap );
		$manager->add_cap( $cap );
	}

	$manager->add_cap( 'sunshine_media_only' );

}

function sunshine_install_page( $step = '' ) {

	$step = ( isset( $_GET['step'] ) ) ? sanitize_text_field( $_GET['step'] ) : $step;
	?>
	<div id="sunshine-install" class="wrap">
		<p><img src="<?php echo esc_url( SUNSHINE_PHOTO_CART_URL ); ?>assets/images/logo.svg" alt="Sunshine Photo Cart" width="300" /></p>

		<?php
		add_thickbox();
		wp_enqueue_media();

		$sunshine_install_error = get_transient( 'sunshine_install_error' );
		if ( ! empty( $sunshine_install_error ) ) {
			?>
			<div class="sunshine-install--error" style="background:red; padding: 5px 20px; text-align: center; color: #FFF; border-radius: 5px; max-width: 750px; margin: 40px auto -50px auto;">
				<p><?php echo esc_html( $sunshine_install_error ); ?></p>
			</div>
			<?php
			delete_transient( 'sunshine_install_error' );
		}

		if ( empty( $step ) ) {
			sunshine_get_template( 'admin/install/default' );
		} elseif ( $step == 'business' ) {
			sunshine_get_template( 'admin/install/business' );
		} elseif ( $step == 'data' ) {
			sunshine_get_template( 'admin/install/data' );
		} elseif ( $step == 'license' ) {
			sunshine_get_template( 'admin/install/license' );
		} elseif ( $step == 'upgrade' ) {
			sunshine_get_template( 'admin/install/upgrade' );
		} elseif ( $step == 'updates' ) {
			sunshine_get_template( 'admin/install/updates' );
		} elseif ( $step == 'guide' ) {
			sunshine_get_template( 'admin/install/guide' );
		}
		?>
	</div>

	<?php
}

function sunshine_guide_page() {
	sunshine_install_page( 'guide' );
}

add_action( 'admin_init', 'sunshine_install_process_data' );
function sunshine_install_process_data() {

	if ( isset( $_POST['sunshine_install_data'] ) && wp_verify_nonce( $_POST['sunshine_install_data'], 'sunshine_install_data' ) ) {

		update_option( 'sunshine_tracking_allow', 1, false );

		wp_redirect( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install&step=updates' ) );
		exit;

	} elseif ( isset( $_POST['sunshine_install_business'] ) && wp_verify_nonce( $_POST['sunshine_install_business'], 'sunshine_install_business' ) ) {

		update_option( 'sunshine_address1', sanitize_text_field( $_POST['address1'] ), false );
		update_option( 'sunshine_address2', sanitize_text_field( $_POST['address2'] ), false );
		update_option( 'sunshine_city', sanitize_text_field( $_POST['city'] ), false );
		update_option( 'sunshine_state', sanitize_text_field( $_POST['state'] ), false );
		update_option( 'sunshine_postcode', sanitize_text_field( $_POST['postcode'] ), false );
		update_option( 'sunshine_country', sanitize_text_field( $_POST['country'] ), false );
		update_option( 'sunshine_logo', intval( $_POST['logo'] ), false );

		if ( ! empty( $_POST['niches'] ) ) {
			$niches = array_map( 'sanitize_text_field', $_POST['niches'] );
			update_option( 'sunshine_tracking_niches', $niches, false );
		}

		wp_redirect( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install&step=license' ) );
		exit;

	} elseif ( isset( $_POST['sunshine_install_license'] ) && wp_verify_nonce( $_POST['sunshine_install_license'], 'sunshine_install_license' ) ) {

		$license = sanitize_text_field( $_POST['license'] );
		$product = sanitize_text_field( $_POST['product'] );

		if ( ! array_key_exists( $product, SPC()->plans ) ) {
			return;
		}

		$plan      = SPC()->plans[ $product ];
		$activated = $plan->activate( $license );
		if ( $activated ) {
			update_option( 'sunshine_plan', $product );
			wp_redirect( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install&step=data&license=true' ) );
			exit;
		}

		set_transient( 'sunshine_install_error', __( 'Could not activate license key', 'sunshine-photo-cart' ), 5000 );

	} elseif ( isset( $_POST['sunshine_install_updates'] ) && wp_verify_nonce( $_POST['sunshine_install_updates'], 'sunshine_install_updates' ) ) {

		$email      = sanitize_text_field( $_POST['email'] );
		$first_name = sanitize_text_field( $_POST['first_name'] );

		$contact_data = array(
			'email'      => $email,
			'first_name' => $first_name,
			'time_zone'  => wp_timezone()->getName(),
		);
		$args         = array(
			'method'      => 'POST',
			'headers'     => array(
				'Content-type' => sprintf( 'application/json; charset=%s', get_bloginfo( 'charset' ) ),
			),
			'body'        => wp_json_encode( $contact_data ),
			'data_format' => 'body',
			'sslverify'   => true,
			'user-agent'  => 'SunshinePhotoCart/' . SUNSHINE_PHOTO_CART_VERSION . '; ' . home_url(),
		);
		$response     = wp_remote_post( 'https://www.sunshinephotocart.com/wp-json/gh/v4/webhooks/121-webhook-listener?token=t5YM9nJ', $args );
		if ( $response ) {
			$json = json_decode( wp_remote_retrieve_body( $response ) );
			if ( ! empty( $json->contact ) ) {
				wp_redirect( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install&step=guide' ) );
				exit;
			}
		}

		set_transient( 'sunshine_install_error', __( 'Could not subscribe to email list', 'sunshine-photo-cart' ), 5000 );

	}

}

function sunshine_updated_page() {
	?>
<div id="sunshine-header">
	<?php /* translators: %s is the plugin version number */ ?>
	<h1><?php printf( esc_html__( 'Welcome to Sunshine Photo Cart %s', 'sunshine-photo-cart' ), esc_html( SPC()->version ) ); ?></h1>
	<p>
		<?php
		/* translators: %1$s is the plugin version number */
		printf( esc_html__( '<strong>Thank you for updating to the latest version!</strong> Sunshine %1$s is the most comprehensive client proofing and photo cart plugin for WordPress. We hope you enjoy greater selling success!', 'sunshine-photo-cart' ), esc_html( SPC()->version ) );
		?>
	</p>
</div>

<div class="sunshine-wrap">

	<div class="sunshine-about-content">

			<div class="sunshine-changelog">
				<?php
				$readme        = file_get_contents( SUNSHINE_PHOTO_CART_PATH . '/readme.txt' );
				$readme_pieces = explode( '== Changelog ==', $readme );
				$changelog     = nl2br( htmlspecialchars( trim( $readme_pieces[1] ) ) );
				$changelog     = str_replace( array( ' =', '= ' ), array( '</h3>', '<h3>' ), $changelog );
				$nth           = nth_strpos( $changelog, '<h3>', 7, true );
				if ( $nth !== false ) {
					$changelog = substr( $changelog, 0, $nth );
				}
				?>
				<h2><?php esc_html_e( 'Recent Improvements', 'sunshine-photo-cart' ); ?></h2>
				<div class="changelog"><?php echo wp_kses_post( $changelog ); ?></div>
				<p><a href="https://wordpress.org/plugins/sunshine-photo-cart/#developers" target="_blank"><?php esc_html_e( 'See the full Changelog', 'sunshine-photo-cart' ); ?></a></p>
			</div>

	</div>

</div>
	<?php
}

function nth_strpos( $str, $substr, $n, $stri = false ) {
	if ( $stri ) {
		$str    = strtolower( $str );
		$substr = strtolower( $substr );
	}
	$ct  = 0;
	$pos = 0;
	while ( ( $pos = strpos( $str, $substr, $pos ) ) !== false ) {
		if ( ++$ct == $n ) {
			return $pos;
		}
		$pos++;
	}
	return false;
}
