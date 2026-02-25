<?php

class SPC_Update {

	private $current_version;
	private $need_update    = false;
	private $update_actions = array();
	private $notices        = array();
	private $batch          = 500;

	function __construct() {

		$this->current_version = get_option( 'sunshine_version' );
		if ( $this->current_version < SUNSHINE_PHOTO_CART_VERSION ) {
			$this->need_update = true;
		}

		$this->update_actions = array( '3.0', '3.0.17', '3.0.18', '3.5.6', '3.6.0' );

		add_action( 'admin_init', array( $this, 'update_check' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );

		add_action( 'sunshine_update_3.0', array( $this, 'update_3' ) );
		add_action( 'wp_ajax_sunshine_update_3_settings_data', array( $this, 'update_3_settings_data' ) );
		add_action( 'wp_ajax_sunshine_update_3_customers_update', array( $this, 'update_3_customers_update' ) );
		add_action( 'wp_ajax_sunshine_update_3_products_update', array( $this, 'update_3_products_update' ) );
		add_action( 'wp_ajax_sunshine_update_3_discounts_update', array( $this, 'update_3_discounts_update' ) );
		add_action( 'wp_ajax_sunshine_update_3_emails_update', array( $this, 'update_3_emails_update' ) );
		add_action( 'wp_ajax_sunshine_update_3_orders_update', array( $this, 'update_3_orders_update' ) );
		add_action( 'wp_ajax_sunshine_update_3_galleries_common_update', array( $this, 'update_3_galleries_common_update' ) );
		add_action( 'wp_ajax_sunshine_update_3_galleries_update', array( $this, 'update_3_galleries_update' ) );
		add_action( 'wp_ajax_sunshine_update_3_images_update', array( $this, 'update_3_images_update' ) );
		add_action( 'wp_ajax_sunshine_update_3_galleries_duplicate_meta', array( $this, 'update_3_galleries_duplicate_meta' ) );
		add_action( 'wp_ajax_sunshine_update_3_complete', array( $this, 'update_3_complete' ) );
		add_action( 'wp_ajax_sunshine_update_3_cleanup', array( $this, 'update_3_cleanup' ) );

		add_action( 'sunshine_update_3.0.17', array( $this, 'update_3_0_17' ) );
		add_action( 'sunshine_update_3.0.18', array( $this, 'update_3_0_18' ) );
		add_action( 'sunshine_update_3.5.6', array( $this, 'update_3_5_6' ) );
		add_action( 'sunshine_update_3.6.0', array( $this, 'update_3_6_0' ) );

		add_action( 'activated_plugin', array( $this, 'check_plugin_activation' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'show_deactivation_notice' ), 10, 2 );

		if ( ! empty( $_POST['batch'] ) ) {
			$this->batch = intval( $_POST['batch'] );
		}

	}

	function update_check() {

		if ( ! current_user_can( 'sunshine_manage_options' ) ) {
			return;
		}

		$update_3    = SPC()->get_option( 'update_3' );
		$old_options = get_option( 'sunshine_options' );
		if ( $old_options && ! $update_3 && ( ! isset( $_GET['page'] ) || $_GET['page'] != 'sunshine-update' ) ) {
			SPC()->notices->add_admin( 'update_3', 'Sunshine has been updated to version 3 and needs the database to be updated. <a href="' . admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-update' ) . '">Click here to start the update process</a>', 'error' );
		}

		if ( $this->need_update ) {
			if ( empty( $_GET['page'] ) || $_GET['page'] != 'sunshine-update' ) {
				maybe_sunshine_create_custom_tables();
				delete_user_meta( get_current_user_id(), 'sunshine_cart' );
				wp_redirect( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-update' ) );
				exit;
			}
		}

	}

	function menu() {
		$base        = '';
		$update_3    = SPC()->get_option( 'update_3' );
		$old_options = get_option( 'sunshine_options' );
		if ( $old_options && ! $update_3 ) {
			// Make the link visible in the admin menu.
			$base = 'edit.php?post_type=sunshine-gallery';
		}
		add_submenu_page( $base, __( 'Update', 'sunshine-photo-cart' ), '<span style="color:red;font-weight:bold;">' . __( 'Update', 'sunshine-photo-cart' ) . '</span>', 'manage_options', 'sunshine-update', array( $this, 'update_page' ) );
	}

	function update_page() {
		global $wpdb;

		$run_updates = array();
		foreach ( $this->update_actions as $version ) {
			// Only run updates if:
			// 1. The update version is greater than the user's previous version.
			// 2. The update version is less than or equal to the current plugin version.
			if ( version_compare( $version, $this->current_version, '>' ) && version_compare( SUNSHINE_PHOTO_CART_VERSION, $version, '>=' ) ) {
				$run_updates[] = $version;
			}
		}

		// Set new version only when we have arrived on the update page.
		SPC()->update_option( 'version', SUNSHINE_PHOTO_CART_VERSION );

		$this->common_updates();

		sunshine_get_template( 'admin/updates/update', array( 'update_actions' => $run_updates ) );

	}

	function add_notice( $message ) {
		$notices   = SPC()->get_option( 'update_3_notices' );
		$notices[] = $message;
		SPC()->update_option( 'update_3_notices', $notices );
	}

	// Things to check every time there is an update.
	function common_updates() {

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

		$allowed_tracking = get_option( 'sunshine_allow_tracking' );
		if ( $allowed_tracking ) {
			update_option( 'sunshine_tracking_allow', 1, false );
			delete_option( 'sunshine_allow_tracking' );
		} else {
			$sunshine2_tracking = get_option( 'sunshine_tracking' );
			$sunshine3_tracking = get_option( 'sunshine_tracking_allow' );
			if ( ! $sunshine3_tracking && $sunshine2_tracking && $sunshine2_tracking != 'deny' ) {
				update_option( 'sunshine_tracking_allow', 1, false );
			}
		}

		sunshine_set_roles();

		sunshine_create_htaccess( true );

		sunshine_tracking_send();

	}

	function update_3() {
		global $wpdb;

		$completed_update_3 = SPC()->get_option( 'update_3' );
		if ( ! $completed_update_3 || isset( $_GET['force'] ) ) {
			sunshine_get_template( 'admin/updates/3' );
		}

	}

	function update_3_0_17() {
		if ( get_option( 'sunshine_signup_password_optional' ) === false ) {
			SPC()->update_option( 'signup_password_optional', 1 );
		}
		if ( get_option( 'sunshine_signup_name_optional' ) === false ) {
			SPC()->update_option( 'signup_name_optional', 1 );
		}
	}

	function update_3_0_18() {
		// Set the new gallery theme option to the same as general theme if it does not exist.
		if ( get_option( 'sunshine_theme_gallery' ) === false ) {
			SPC()->update_option( 'theme_gallery', SPC()->get_option( 'theme' ) );
		}
		if ( get_option( 'account_galleries_endpoint' ) === false ) {
			SPC()->update_option( 'account_galleries_endpoint', 'my-galleries' );
		}
	}

	function update_3_5_6() {
		global $wpdb;
		// Delete all usermeta with key "sunshine_customer_notes"
		$wpdb->query( "DELETE FROM {$wpdb->prefix}usermeta WHERE meta_key = 'sunshine_customer_notes'" );
	}

	function update_3_6_0() {
		// Migrate disable_favorites to enable_favorites (only if not already migrated).
		if ( get_option( 'sunshine_enable_favorites' ) === false ) {
			$disable_favorites = get_option( 'sunshine_disable_favorites' );
			if ( ! $disable_favorites ) {
				SPC()->update_option( 'enable_favorites', true );
			}
		}

		// Migrate disable_store to allow_store (only if not already migrated).
		if ( get_option( 'sunshine_allow_store' ) === false ) {
			$disable_store = get_option( 'sunshine_disable_store' );
			if ( ! $disable_store ) {
				SPC()->update_option( 'allow_store', true );
			}
		}

		// Migrate disable_gallery_sharing to allow_gallery_sharing.
		if ( get_option( 'sunshine_allow_gallery_sharing' ) === false ) {
			$disable_gallery_sharing = get_option( 'sunshine_disable_gallery_sharing' );
			if ( ! $disable_gallery_sharing ) {
				SPC()->update_option( 'allow_gallery_sharing', true );
			}
		}

		// Migrate disable_image_sharing to allow_image_sharing.
		if ( get_option( 'sunshine_allow_image_sharing' ) === false ) {
			$disable_image_sharing = get_option( 'sunshine_disable_image_sharing' );
			if ( ! $disable_image_sharing ) {
				SPC()->update_option( 'allow_image_sharing', true );
			}
		}

		// Migrate disable_secure_file_names to use_secure_file_names.
		if ( get_option( 'sunshine_use_secure_file_names' ) === false ) {
			$disable_secure_file_names = get_option( 'sunshine_disable_secure_file_names' );
			if ( ! $disable_secure_file_names ) {
				SPC()->update_option( 'use_secure_file_names', true );
			}
		}

		// Regenerate .htaccess to include log file protection rules.
		sunshine_create_htaccess( true );

		// Generate random log filename if logging is currently enabled.
		if ( SPC()->get_option( 'enable_log' ) && ! get_option( 'sunshine_log_file_name' ) ) {
			SPC()->generate_log_filename();
		}
	}

	public function update_3_settings_data() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sunshine-photo-cart' ) ) );
		}

		check_admin_referer( 'sunshine_update_3', 'security' );

		$done_updating = SPC()->get_option( 'update_3_settings' );
		if ( $done_updating ) {
			return;
		}

		$wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE 'sunshine_session_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE 'sunshine_cart_hash_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}usermeta WHERE meta_key = 'sunshine_cart'" );

		$valid_plugin = validate_plugin( 'sunshine-mosaic/mosaic.php' );
		if ( ! is_wp_error( $valid_plugin ) ) {
			deactivate_plugins( 'sunshine-mosaic/mosaic.php' );
			$this->add_notice( 'The Mosaic add-on has been deactivated as it is now part of the core plugin. You may safely delete this plugin.' );
		}
		$valid_plugin = validate_plugin( 'sunshine-masonry/masonry.php' );
		if ( ! is_wp_error( $valid_plugin ) ) {
			deactivate_plugins( 'sunshine-masonry/masonry.php' );
			$this->add_notice( 'The Masonry add-on has been deactivated as it is now part of the core plugin. You may safely delete this plugin.' );
		}

		// Get all the current settings and overwrite each as needed.
		$options = get_option( 'sunshine_options' );

		if ( empty( $options ) ) {
			return;
		}

		// Almost all options became their own row with the "sunshine_" prefix, so assume that for all for quick change first.
		foreach ( $options as $key => $value ) {
			if ( substr( $key, -4 ) === 'desc' ) {
				$key = substr_replace( $key, 'description', -4 );
			}
			update_option( 'sunshine_' . $key, $value, false );
		}

		// A few have some unique changes to them so handle on case by case basis.
		$options3 = array();

		if ( ! empty( $options['sunshine_license_key'] ) ) {
			$type             = get_option( 'sunshine_license_type' );
			$options3['plan'] = $type;
			$options3[ 'license_sunshine-photo-cart-' . $type ]            = $options['sunshine_license_key'];
			$options3[ 'license_status_sunshine-photo-cart-' . $type ]     = get_option( 'sunshine_license_active' );
			$options3[ 'license_expiration_sunshine-photo-cart-' . $type ] = get_transient( 'sunshine_license_expiration' );
		}

		$options3['per_page']       = $options['columns'] * $options['rows'];
		$options3['thumbnail_size'] = array(
			'w' => $options['thumbnail_width'],
			'h' => $options['thumbnail_height'],
		);
		$options3['large_size']     = array(
			'w' => ( ! empty( $options['lowres_width'] ) ) ? $options['lowres_width'] : 1000,
			'h' => ( ! empty( $options['lowres_height'] ) ) ? $options['lowres_height'] : 1000,
		);

		$options3['hide_galleries'] = ( ! empty( $options['hide_galleries_link'] ) ) ? $options['hide_galleries_link'] : '';

		if ( $options['theme'] != 'theme' ) {
			$options3['theme'] = 'classic';

			if ( ! empty( $options['template_background_color'] ) ) {
				$options3['classic_main_background_color'] = $options['template_background_color'];
			}
			if ( ! empty( $options['template_link_color'] ) ) {
				$options3['classic_menu_links_color'] = $options['template_link_color'];
			}

			if ( ! empty( $options['template_search_box'] ) ) {
				$options3['classic_search'] = $options['template_search_box'];
			}
			if ( ! empty( $options['template_gallery_password_box'] ) ) {
				$options3['classic_password'] = $options['template_gallery_password_box'];
			}
		}

		if ( ! empty( $options['template_logo'] ) ) {
			$options3['logo'] = $options['template_logo'];
		}

		if ( ! empty( $options['masonry'] ) ) {
			if ( ! empty( $options['masonry_usage']['galleries'] ) ) {
				$options3['gallery_layout'] = 'masonry';
			}
			if ( ! empty( $options['masonry_usage']['images'] ) ) {
				$options3['images_layout'] = 'masonry';
			}
		}

		if ( ! empty( $options['lightbox'] ) ) {
			$options3['lightbox_enabled']            = 1;
			$options3['lightbox_slideshow_interval'] = ! empty( $options['slideshow_interval'] ) ? $options['slideshow_interval'] : 5;
			$options3['lightbox_navigation']         = 'thumbnail';
			$options3['lightbox_slideshow']          = 1;
		}

		if ( ! empty( $options['mosaic'] ) ) {
			$options3['gallery_layout'] = 'justified';
			$options3['images_layout']  = 'justified';
		}

		if ( ! empty( $options['tax_rate'] ) ) {
			$tax_location              = explode( '|', $options['tax_location'] );
			$tax_data                  = array(
				'country' => $tax_location[0],
				'state'   => $tax_location[1],
				'rate'    => $options['tax_rate'],
			);
			$options3['tax_rates']     = array( $tax_data );
			$options3['taxes_enabled'] = 1;
		}

		if ( ! empty( $options['paypal_active'] ) ) {
			$this->add_notice( 'Please visit the <a href="' . admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=paypal' ) . '" target="_blank">Payment settings</a> to reconnect PayPal' );
		}
		$options3['paypal_active'] = 0;
		$options3['paypal_mode']   = ( ! empty( $options['paypal_test_mode'] ) ) ? 'test' : 'live';

		if ( ! empty( $options['square_active'] ) ) {
			$this->add_notice( 'Please visit the <a href="' . admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=square' ) . '" target="_blank">Payment settings</a> to reconnect Square' );
		}
		$options3['square_active'] = 0;
		$options3['square_mode']   = ( ! empty( $options['square_test'] ) ) ? 'test' : 'live';

		if ( ! empty( $options['stripe_active'] ) ) {
			$this->add_notice( 'Please visit the <a href="' . admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=stripe' ) . '" target="_blank">Payment settings</a> to reconnect Stripe' );
		}
		$options3['stripe_active'] = 0;
		$options3['stripe_mode']   = ( $options['stripe_test'] ) ? 'test' : 'live';

		if ( ! empty( $options['authorizenet_active'] ) ) {
			$options3['authorizenet_mode']                 = ( $options['authorizenet_test'] ) ? 'test' : 'live';
			$options3['authorizenet_login_id_test']        = $options['authorizenet_test_login'];
			$options3['authorizenet_transaction_key_test'] = $options['authorizenet_test_key'];
			$options3['authorizenet_login_id_live']        = $options['authorizenet_live_login'];
			$options3['authorizenet_transaction_key_live'] = $options['authorizenet_live_key'];
		}

		if ( ! empty( $options['mollie_active'] ) ) {
			$options3['mollie_mode'] = ( $options['mollie_test'] ) ? 'test' : 'live';
		}

		if ( ! empty( $options['pickup_active'] ) ) {
			$options3['pickup_enabled'] = 1;
		}

		$options3['shipping_methods'] = array();

		if ( ! empty( $options['flat_rate_active'] ) ) {
			$key                                     = wp_hash( 'flat_rate' . current_time( 'timestamp' ) );
			$options3['shipping_methods'][ $key ]    = array(
				'id'     => 'flat_rate',
				'active' => 1,
			);
			$options3[ 'flat_rate_name_' . $key ]    = $options['flat_rate_name'];
			$options3[ 'flat_rate_taxable_' . $key ] = $options['flat_rate_taxable'];
			$options3[ 'flat_rate_price_' . $key ]   = $options['flat_rate_cost'];
		}

		// Convert Tiered Shipping - each one needs it's own shipping instance now.
		if ( ! empty( $options['tiered_shipping_rates'] ) ) {
			$rates = explode( '|', $options['tiered_shipping_rates'] );
			$count = 1;
			$min   = 0;
			foreach ( $rates as $rate ) {
				$rate_data = explode( ':', $rate );
				if ( empty( $rate_data[0] ) || empty( $rate_data[1] ) ) {
					continue;
				}
				$key                                        = wp_hash( 'flat_rate' . $count . wp_generate_password() );
				$options3['shipping_methods'][ $key ]       = array(
					'id'     => 'flat_rate',
					'active' => 1,
				);
				$options3[ 'flat_rate_name_' . $key ]       = $options['tiered_shipping_name'] . ' (' . $count . ')';
				$options3[ 'flat_rate_taxable_' . $key ]    = $options['tiered_shipping_taxable'];
				$options3[ 'flat_rate_price_' . $key ]      = $rate_data[1];
				$options3[ 'flat_rate_order_min_' . $key ]  = $min;
				$options3[ 'flat_rate_order_max_' . $key ]  = $rate_data[0];
				$options3[ 'flat_rate_price_type_' . $key ] = 'cart';
				$count++;
				$min = $rate_data[0] + 0.01;
			}
			$this->add_notice( 'Tiered Shipping add-on is now part of the new Advanced Shipping add-on. A new Flat Rate shipping option has been created for each tier with advanced settings. You must have the new Advanced Shipping add-on enabled to see these options.' );

		}

		if ( ! empty( $options['local_active'] ) ) {
			$key                                   = wp_hash( 'local' . current_time( 'timestamp' ) );
			$options3['shipping_methods'][ $key ]  = array(
				'id'     => 'local',
				'active' => 1,
			);
			$options3[ 'local_name_' . $key ]      = $options['local_name'];
			$options3[ 'local_taxable_' . $key ]   = $options['local_taxable'];
			$options3[ 'local_price_' . $key ]     = $options['local_cost'];
			$options3[ 'local_postcodes_' . $key ] = ( ! empty( $options['local_zipcodes'] ) ) ? $options['local_zipcodes'] : '';
		}

		// Make new terms page.
		if ( ! empty( $options['terms'] ) ) {
			$options3['page_terms'] = wp_insert_post(
				array(
					'post_title'     => __( 'Terms & Conditions', 'sunshine-photo-cart' ),
					'post_content'   => $options['terms'],
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'post_parent'    => $options['page'],
				)
			);
		}

		// Email subjects, signatures, and content.
		$options3['email_signup_active']              = 1;
		$options3['email_reset-password_active']      = 1;
		$options3['email_customer-receipt_active']    = 1;
		$options3['email_admin-receipt_active']       = 1;
		$options3['email_order-comment_active']       = 1;
		$options3['email_admin-favorites_active']     = 1;
		$options3['email_custom-favorites_active']    = 1;
		$options3['email_summary_active']             = 1;
		$options3['email_image-comment_active']       = 1;
		$options3['email_message-custom_active']      = 1;
		$options3['email_message-gallery_active']     = 1;
		$options3['email_message-user_active']        = 1;
		$options3['email_order-files-notify_active']  = 1;
		$options3['email_admin-receipt_recipients']   = ( ! empty( $options['order_notifications'] ) ) ? $options['order_notifications'] : get_bloginfo( 'admin_email' );
		$options3['email_admin-favorites_recipients'] = ( ! empty( $options['favorite_notifications'] ) ) ? $options['favorite_notifications'] : get_bloginfo( 'admin_email' );
		$options3['email_summary_recipients']         = ( ! empty( $options['order_notifications'] ) ) ? $options['order_notifications'] : get_bloginfo( 'admin_email' );
		$options3['email_image-comment_recipients']   = ( ! empty( $options['order_notifications'] ) ) ? $options['order_notifications'] : get_bloginfo( 'admin_email' );
		$options3['email_signup_subject']             = $options['email_subject_register'];
		$options3['email_customer-receipt_subject']   = $options['email_subject_order_receipt'];
		$options3['email_order-comment_subject']      = $options['email_subject_order_comment'];
		$options3['email_signup_message']             = $options['email_register'];
		$options3['email_customer-receipt_message']   = ( ! empty( $options['email_receipt'] ) ) ? $options['email_receipt'] : '';
		$options3['email_order-comment_message']      = ( ! empty( $options['email_order_status'] ) ) ? $options['email_order_status'] : '';

		// Messaging templates.
		$options3['email_message-custom_active']   = 1;
		$options3['email_message-gallery_active']  = 1;
		$options3['email_message-gallery_subject'] = ( ! empty( $options['message_gallery_invite_subject'] ) ) ? $options['message_gallery_invite_subject'] : '';
		$options3['email_message-gallery_message'] = ( ! empty( $options['message_gallery_invite_content'] ) ) ? $options['message_gallery_invite_content'] : '';
		$options3['email_message-gallery_active']  = 1;
		$options3['email_message-user_subject']    = ( ! empty( $options['message_register_invite_subject'] ) ) ? $options['message_register_invite_subject'] : '';
		$options3['email_message-user_message']    = ( ! empty( $options['message_register_invite_content'] ) ) ? $options['message_register_invite_content'] : '';

		foreach ( $options3 as $key => $value ) {
			update_option( 'sunshine_' . $key, $value, false );
		}

		// Put shortcode on all pages that already exist.
		if ( ! empty( $options['page'] ) ) {
			$page = get_post( $options['page'] );
			if ( $page ) {
				if ( has_shortcode( $page->post_content, 'sunshine' ) ) {
					$page->post_content = str_replace( '[sunshine]', '[sunshine_galleries]', $post->post_content );
				}
				if ( ! has_shortcode( $page->post_content, 'sunshine_galleries' ) ) {
					$page->post_content .= '<!-- wp:shortcode -->[sunshine_galleries]<!-- /wp:shortcode -->';
				}
				wp_update_post(
					array(
						'ID'           => $options['page'],
						'post_content' => $page->post_content,
					)
				);
			}
		}
		if ( ! empty( $options['page_account'] ) ) {
			$page = get_post( $options['page_account'] );
			if ( $page && ! has_shortcode( $page->post_content, 'sunshine_account' ) ) {
				wp_update_post(
					array(
						'ID'           => $options['page_account'],
						'post_content' => $page->post_content . '<!-- wp:shortcode -->[sunshine_account]<!-- /wp:shortcode -->',
					)
				);
			}
		}
		if ( ! empty( $options['page_cart'] ) ) {
			$page = get_post( $options['page_cart'] );
			if ( $page && ! has_shortcode( $page->post_content, 'sunshine_cart' ) ) {
				wp_update_post(
					array(
						'ID'           => $options['page_cart'],
						'post_content' => $page->post_content . '<!-- wp:shortcode -->[sunshine_cart]<!-- /wp:shortcode -->',
					)
				);
			}
		}
		if ( ! empty( $options['page_checkout'] ) ) {
			$page = get_post( $options['page_checkout'] );
			if ( $page && ! has_shortcode( $page->post_content, 'sunshine_checkout' ) ) {
				wp_update_post(
					array(
						'ID'           => $options['page_checkout'],
						'post_content' => $page->post_content . '<!-- wp:shortcode -->[sunshine_checkout]<!-- /wp:shortcode -->',
					)
				);
			}
		}
		if ( ! empty( $options['page_favorites'] ) ) {
			$page = get_post( $options['page_favorites'] );
			if ( $page && ! has_shortcode( $page->post_content, 'sunshine_favorites' ) ) {
				wp_update_post(
					array(
						'ID'           => $options['page_favorites'],
						'post_content' => $page->post_content . '<!-- wp:shortcode -->[sunshine_favorites]<!-- /wp:shortcode -->',
					)
				);
			}
		}

		register_taxonomy( 'sunshine-product-option', 'sunshine-product' );

		// Product Options.
		$product_options = get_posts(
			array(
				'post_type'   => 'sunshine-product-opt',
				'nopaging'    => true,
				'post_parent' => 0,
			)
		);
		if ( ! empty( $product_options ) ) {
			foreach ( $product_options as $product_option ) {
				$term = wp_insert_term(
					$product_option->post_title,
					'sunshine-product-option',
					array(
						'description' => strip_tags( $product_option->post_content ),
					)
				);
				if ( ! is_wp_error( $term ) ) {
					$product_option_items = get_posts(
						array(
							'post_type'   => 'sunshine-product-opt',
							'nopaging'    => true,
							'post_parent' => $product_option->ID,
						)
					);
					if ( ! empty( $product_option_items ) ) {
						$options = array();
						foreach ( $product_option_items as $product_option_item ) {
							$options[] = array(
								'id'             => wp_generate_password( 8, false ),
								'name'           => $product_option_item->post_title,
								'description'    => '',
								'image'          => get_post_meta( $product_option_item->ID, 'sunshine_image', true ),
								'product_opt_id' => $product_option_item->ID,
							);
						}
						update_term_meta( $term['term_id'], 'options', $options );
					}
					update_term_meta( $term['term_id'], 'type', 'select' );
					update_term_meta( $term['term_id'], 'image', get_post_thumbnail_id( $product_option->ID ) );
				}
			}
		}

		// All product categories need a new order meta and set first as default.
		// Using $wpdb because without any 'order' meta yet, we get no results in our plugin.
		$sql                = "
		SELECT t.term_id, t.name, t.slug
		FROM {$wpdb->terms} AS t
		INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
		WHERE tt.taxonomy = 'sunshine-product-category'
		ORDER BY t.slug ASC
		";
		$product_categories = $wpdb->get_results( $sql );
		if ( ! empty( $product_categories ) ) {
			$order = 1;
			foreach ( $product_categories as $product_category ) {
				update_term_meta( $product_category->term_id, 'order', $order );
				if ( $order == 1 ) {
					update_term_meta( $product_category->term_id, 'default', 1 );
				}
				$order++;
			}
		}

		// Move Upload folders to new location.
		require_once ABSPATH . 'wp-admin/includes/file.php';

		WP_Filesystem();
		global $wp_filesystem;
		$upload_dir = wp_upload_dir();

		$old_folder = $upload_dir['basedir'] . '/sunshine';
		$new_folder = $upload_dir['basedir'] . '/sunshine/upload';

		if ( ! $wp_filesystem->is_dir( $new_folder ) ) {
			$wp_filesystem->mkdir( $new_folder );
		}
		$dirlist = $wp_filesystem->dirlist( $old_folder );
		if ( ! empty( $dirlist ) ) {
			foreach ( $dirlist as $dirname => $dirinfo ) {
				// If the directory name is not numeric
				if ( $dirinfo['type'] === 'd' && ! is_numeric( $dirname ) ) {
					// Move the directory to the new location
					$wp_filesystem->move( $old_folder . '/' . $dirname, $new_folder . '/' . $dirname );
				}
			}
		}

		// Run fresh install which will fill in the holes of any new settings.
		sunshine_base_install();

		SPC()->update_option( 'update_3_settings', time() );

		SPC()->log( 'Completed settings update' );

	}

	public function update_3_customers_update() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sunshine-photo-cart' ) ) );
		}

		check_admin_referer( 'sunshine_update_3', 'security' );

		$query     = "
			SELECT *
			FROM {$wpdb->prefix}users
			WHERE NOT EXISTS (
				SELECT 1
				FROM {$wpdb->prefix}usermeta
				WHERE {$wpdb->prefix}usermeta.user_id = {$wpdb->prefix}users.ID
				AND {$wpdb->prefix}usermeta.meta_key = 'sunshine_update_3'
			)
			LIMIT 1
		";
		$customers = $wpdb->get_results( $query );
		if ( ! empty( $customers ) ) {
			$object = new WP_User( $customers[0] );
			SPC()->log( 'Updating user ID: ' . $object->ID );

			$object->add_role( sunshine_get_customer_role() );

			$orders = get_posts(
				array(
					'post_type'  => 'sunshine-order',
					'meta_key'   => '_sunshine_customer_id',
					'meta_value' => $object->ID,
				)
			);
			if ( $orders ) {

				if ( empty( $object->first_name ) ) {
					$last_order      = $orders[0];
					$last_order_data = get_post_meta( $last_order->ID, '_sunshine_order_data', true );
					if ( ! empty( $last_order_data['first_name'] ) ) {
						$userdata = array(
							'ID'           => $object->ID,
							'first_name'   => $last_order_data['first_name'],
							'last_name'    => $last_order_data['last_name'],
							'display_name' => $last_order_data['first_name'] . ' ' . $last_order_data['last_name'],
						);
						wp_update_user( $userdata );
					}
				}
			}

			delete_user_meta( $object->ID, 'sunshine_cart' );

			$favorites = get_user_meta( $object->ID, 'sunshine_favorite', false );
			if ( ! empty( $favorites ) ) {
				update_user_meta( $object->ID, 'sunshine_favorites', $favorites );
				update_user_meta( $object->ID, 'sunshine_favorites_count', count( $favorites ) );
			}

			update_user_meta( $object->ID, 'sunshine_update_3', time() );

			wp_send_json_success( array( 'updated' => $object->ID ) );

		}

		SPC()->update_option( 'update_3_customers', time() );
		wp_send_json_success();

	}

	public function update_3_products_update() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sunshine-photo-cart' ) ) );
		}

		check_admin_referer( 'sunshine_update_3', 'security' );

		$query    = "
			SELECT *
			FROM {$wpdb->prefix}posts
			WHERE post_type = 'sunshine-product'
			AND NOT EXISTS (
				SELECT 1
				FROM {$wpdb->prefix}postmeta
				WHERE {$wpdb->prefix}postmeta.post_id = {$wpdb->prefix}posts.ID
				AND {$wpdb->prefix}postmeta.meta_key = 'update_3'
			)
			LIMIT 1
		";
		$products = $wpdb->get_results( $query );
		if ( ! empty( $products ) ) {
			$object = $products[0];

			SPC()->log( 'Updating product ID: ' . $object->ID );

			$sunshine3_prices = get_post_meta( $object->ID, 'price', true );
			if ( $sunshine3_prices ) {
				// Already a Sunshine 3 product, do not update again.
				wp_send_json_success( array( 'updated' => $object->ID ) );
			}

			// Determine type.
			$type        = 'print';
			$is_download = get_post_meta( $object->ID, 'sunshine_product_download', true );
			if ( $is_download ) {
				$type = 'download';
			} else {
				$is_package = get_post_meta( $object->ID, 'sunshine_product_package', true );
				if ( $is_package ) {
					$type            = 'package';
					$package_options = get_post_meta( $object->ID, 'sunshine_product_package_option' );
					if ( ! empty( $package_options ) ) {
						$new_package_items = array();
						foreach ( $package_options as $package_option ) {
							$new_package_items[] = array(
								'product' => $package_option['product_id'],
								'qty'     => $package_option['qty'],
							);
						}
						add_post_meta( $object->ID, 'package', $new_package_items );
					}
				}
			}
			update_post_meta( $object->ID, 'type', $type );

			// Set pricing.
			$price_levels = sunshine_get_price_levels();
			if ( ! empty( $price_levels ) ) {
				$prices = array();
				foreach ( $price_levels as $price_level ) {
					$prices[ $price_level->get_id() ] = get_post_meta( $object->ID, 'sunshine_product_price_' . $price_level->get_id(), true );
				}
				update_post_meta( $object->ID, 'price', $prices );
			}

			// Download meta.
			$gallery_download = get_post_meta( $object->ID, 'sunshine_product_gallery_download', true );
			if ( $gallery_download ) {
				update_post_meta( $object->ID, 'gallery_download', true );
			}

			$dimensions = array();
			$width      = get_post_meta( $object->ID, 'sunshine_product_download_width', true );
			if ( $width ) {
				$dimensions['w'] = $width;
			}
			$height = get_post_meta( $object->ID, 'sunshine_product_download_height', true );
			if ( $height ) {
				$dimensions['h'] = $height;
			}
			if ( ! empty( $dimensions ) ) {
				update_post_meta( $object->ID, 'dimensions', $dimensions );
			}

			$free_download = get_post_meta( $object->ID, 'sunshine_product_download_free', true );
			if ( $free_download ) {
				update_post_meta( $object->ID, 'free', true );
			}

			$watermark = get_post_meta( $object->ID, 'sunshine_product_download_watermark', true );
			if ( $watermark ) {
				update_post_meta( $object->ID, 'watermark', true );
			}

			$taxable = get_post_meta( $object->ID, 'sunshine_product_taxable', true );
			update_post_meta( $object->ID, 'taxable', $taxable );

			// Product Options.
			$product_options = get_post_meta( $object->ID, 'sunshine_product_options', true );
			if ( ! empty( $product_options ) ) {

				$required = get_post_meta( $object->ID, 'sunshine_product_options_required', true );

				register_taxonomy( 'sunshine-product-option', 'sunshine-product' );

				// Get all product options and put into array with term_id => id.
				$product_option_terms = get_terms(
					array(
						'taxonomy'   => 'sunshine-product-option',
						'hide_empty' => false,
					)
				);
				if ( ! is_wp_error( $product_option_terms ) && ! empty( $product_option_terms ) ) {

					$base_product_options      = array();
					$base_product_option_terms = array();
					foreach ( $product_option_terms as $term ) {
						$term_options = get_term_meta( $term->term_id, 'options', true );
						if ( ! empty( $term_options ) ) {
							foreach ( $term_options as $term_option ) {
								$base_product_options[ $term_option['product_opt_id'] ]      = $term_option['id'];
								$base_product_option_terms[ $term_option['product_opt_id'] ] = $term->term_id;
							}
						}
					}

					$new_product_options = array();
					foreach ( $product_options as $option_id => $option_items ) {
						foreach ( $option_items as $option_item_id => $prices ) {
							if ( ! empty( $base_product_options[ $option_item_id ] ) ) {
								foreach ( $prices as $price_level_id => $price ) {
									$new_product_options[ $base_product_option_terms[ $option_item_id ] ]['items'][ $base_product_options[ $option_item_id ] ][ $price_level_id ] = $price;
								}
								if ( $required && in_array( $option_id, $required ) ) {
									$new_product_options[ $base_product_option_terms[ $option_item_id ] ]['required'] = 1;
								}
							}
						}
					}
					update_post_meta( $object->ID, 'options', $new_product_options );
				}
			}

			// Tiered Pricing.
			$tiered_pricing_options = get_post_meta( $object->ID, 'sunshine_product_tiered_pricing_option' );
			if ( ! empty( $tiered_pricing_options ) ) {
				$new_tiered_pricing = array( 'items' => array() );
				foreach ( $tiered_pricing_options as $tiered_pricing_option ) {
					if ( empty( $tiered_pricing_option['qty'] ) || empty( $tiered_pricing_option['discount'] ) ) {
						continue;
					}
					$new_tiered_pricing['items'][] = array(
						'qty'      => intval( $tiered_pricing_option['qty'] ),
						'discount' => floatval( $tiered_pricing_option['discount'] ),
					);
				}
				update_post_meta( $object->ID, 'qty_discount', $new_tiered_pricing );
			}

			update_post_meta( $object->ID, 'update_3', time() );

			wp_send_json_success( array( 'updated' => $object->ID ) );

		}

		SPC()->update_option( 'update_3_products', time() );
		wp_send_json_success();

	}

	public function update_3_orders_update() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sunshine-photo-cart' ) ) );
		}

		check_admin_referer( 'sunshine_update_3', 'security' );

		$query   = "
			SELECT *
			FROM {$wpdb->prefix}posts
			WHERE post_type = 'sunshine-order'
			AND NOT EXISTS (
				SELECT 1
				FROM {$wpdb->prefix}postmeta
				WHERE {$wpdb->prefix}postmeta.post_id = {$wpdb->prefix}posts.ID
				AND {$wpdb->prefix}postmeta.meta_key = 'update_3'
			)
			LIMIT 1
		";
		$objects = $wpdb->get_results( $query );
		if ( ! empty( $objects ) ) {

			$object = $objects[0];

			SPC()->log( 'Updating order ID: ' . $object->ID );

			// General data changes.
			$orderdata = maybe_unserialize( get_post_meta( $object->ID, '_sunshine_order_data', true ) );

			if ( empty( $orderdata ) || ! is_array( $orderdata ) ) {
				$orderdata = array();
			}

			if ( ! empty( $orderdata['first_name'] ) ) {
				$orderdata['billing_first_name'] = $orderdata['first_name'];
				unset( $orderdata['first_name'] );
			}
			if ( ! empty( $orderdata['last_name'] ) ) {
				$orderdata['billing_last_name'] = $orderdata['last_name'];
				unset( $orderdata['last_name'] );
			}
			if ( ! empty( $orderdata['country'] ) ) {
				$orderdata['billing_country'] = $orderdata['country'];
				unset( $orderdata['country'] );
			}
			if ( ! empty( $orderdata['address'] ) ) {
				$orderdata['billing_address1'] = $orderdata['address'];
				unset( $orderdata['address'] );
			}
			if ( ! empty( $orderdata['address2'] ) ) {
				$orderdata['billing_address2'] = $orderdata['address2'];
				unset( $orderdata['address2'] );
			}
			if ( ! empty( $orderdata['city'] ) ) {
				$orderdata['billing_city'] = $orderdata['city'];
				unset( $orderdata['city'] );
			}
			if ( ! empty( $orderdata['state'] ) ) {
				$orderdata['billing_state'] = $orderdata['state'];
				unset( $orderdata['state'] );
			}
			if ( ! empty( $orderdata['zip'] ) ) {
				$orderdata['billing_postcode'] = $orderdata['zip'];
				unset( $orderdata['zip'] );
			}

			if ( ! empty( $orderdata['shipping_address'] ) ) {
				$orderdata['shipping_address1'] = $orderdata['shipping_address'];
				unset( $orderdata['shipping_address'] );
			}
			if ( ! empty( $orderdata['shipping_zip'] ) ) {
				$orderdata['shipping_postcode'] = $orderdata['shipping_zip'];
				unset( $orderdata['shipping_zip'] );
			}

			// Anything commented out matched the old key and didn't need to be changed.
			$orderdata['order_key']     = sunshine_generate_order_key();
			$orderdata['customer_id']   = get_post_meta( $object->ID, '_sunshine_customer_id', true );
			$orderdata['currency']      = SPC()->get_option( 'currency' );
			$orderdata['display_price'] = SPC()->get_option( 'display_price' );
			if ( ! empty( $orderdata['shipping_cost'] ) ) {
				$orderdata['shipping'] = $orderdata['shipping_cost'];
				unset( $orderdata['shipping_cost'] );
			}
			if ( ! empty( $orderdata['tax_shipping'] ) ) {
				$orderdata['shipping_tax'] = $orderdata['tax_shipping'];
			}
			// $orderdata['subtotal'] = '';
			// $orderdata['subtotal_tax'] = '';
			if ( ! empty( $orderdata['tax_cart'] ) ) {
				$orderdata['tax'] = $orderdata['tax_cart'];
			}
			// $orderdata['total'] = '';
			// $orderdata['first_name'] = '';
			// $orderdata['last_name'] = '';
			// $orderdata['email'] = '';
			// $orderdata['credits'] = '';
			$orderdata['price_has_tax'] = SPC()->get_option( 'price_has_tax' );

			if ( ! empty( $orderdata['discount_total'] ) ) {
				$orderdata['discount'] = $orderdata['discount_total'];
				unset( $orderdata['discount_total'] );
			}
			if ( ! empty( $orderdata['discount_items'] ) ) {
				foreach ( $orderdata['discount_items'] as $discount_item ) {
					$orderdata['discounts'][] = get_post_meta( $discount_item->ID, 'code', true );
				}
				unset( $orderdata['discount_items'] );
			}

			if ( ! empty( $orderdata['meta'] ) ) {
				foreach ( $orderdata['meta'] as $key => $value ) {
					$orderdata[ $key ] = $value;
				}
				unset( $orderdata['meta'] );
			}

			$stripe_mode = get_post_meta( $object->ID, 'stripe_mode', true );
			if ( ! empty( $stripe_mode ) ) {
				$orderdata['mode'] = ( $stripe_mode == 'test' ) ? 'test' : 'live';
			}

			$square_mode = get_post_meta( $object->ID, 'square_mode', true );
			if ( ! empty( $square_mode ) ) {
				$orderdata['mode'] = ( $square_mode == 'test' ) ? 'test' : 'live';
			}
			$square_charge_id = get_post_meta( $object->ID, 'square_charge_id', true );
			if ( ! empty( $square_charge_id ) ) {
				$orderdata['square_payment_id'] = $square_charge_id;
			}

			if ( empty( $orderdata['mode'] ) ) {
				$orderdata['mode'] = 'live'; // Assume mode was a live for any order that did not have it set.
			}

			$conversions = array(
				'txn_id'      => 'paypal_order_id',
				'payer_id'    => 'paypal_payer_id',
				'payment_fee' => 'paypal_fee',
			);
			foreach ( $conversions as $current_key => $new_key ) {
				$value = get_post_meta( $object->ID, $current_key, true );
				if ( $value ) {
					update_post_meta( $object->ID, $new_key, $value );
				}
			}

			if ( ! empty( $orderdata['shipping_method'] ) && $orderdata['shipping_method'] == 'pickup' ) {
				$orderdata['delivery_method']      = 'pickup';
				$orderdata['delivery_method_name'] = __( 'Pickup', 'sunshine-photo-cart' );
			} else {
				$orderdata['delivery_method']      = 'shipping';
				$orderdata['delivery_method_name'] = __( 'Ship', 'sunshine-photo-cart' );

				if ( ! empty( $orderdata['shipping_method'] ) ) {
					$shipping_methods = sunshine_get_active_shipping_methods();
					if ( ! empty( $shipping_methods ) ) {
						if ( $orderdata['shipping_method'] == 'local' ) {
							foreach ( $shipping_methods as $instance_id => $shipping_method ) {
								if ( $shipping_method['id'] == 'local' ) {
									$orderdata['shipping_method']      = $instance_id;
									$orderdata['shipping_method_name'] = SPC()->get_option( $shipping_method['id'] . '_name_' . $instance_id );
									break;
								}
							}
						} else {
							foreach ( $shipping_methods as $instance_id => $shipping_method ) {
								if ( $shipping_method['id'] == 'flat_rate' ) {
									$orderdata['shipping_method']      = $instance_id;
									$orderdata['shipping_method_name'] = SPC()->get_option( $shipping_method['id'] . '_name_' . $instance_id );
									break;
								}
							}
						}
					}
				}
			}

			if ( ! empty( $orderdata['payment_method'] ) ) {
				$payment_methods = sunshine_get_active_payment_methods();
				if ( ! empty( $payment_methods ) ) {
					foreach ( $payment_methods as $id => $payment_method ) {
						if ( $id == $orderdata['payment_method'] ) {
							$orderdata['payment_method_name'] = $payment_method->get_name();
						}
					}
				}
			}

			foreach ( $orderdata as $key => $value ) {
				update_post_meta( $object->ID, $key, $value );
			}

			// Line item changes.
			$orderitems = maybe_unserialize( get_post_meta( $object->ID, '_sunshine_order_items', true ) );
			if ( ! empty( $orderitems ) ) {

				// If there is order items, delete current order items as we will be redoing them.
				$wpdb->delete( $wpdb->prefix . 'sunshine_order_items', array( 'order_id' => $object->ID ) );

				foreach ( $orderitems as $orderitem ) {
					$is_download = get_post_meta( $orderitem['product_id'], 'sunshine_product_download', true );
					if ( $is_download ) {
						$type = 'download';
					} elseif ( $orderitem['type'] == 'package' ) {
						$type = 'package';
					} else {
						$type = 'print';
					}

					if ( empty( $orderitem['gallery_id'] ) ) {
						$orderitem['gallery_id'] = 0;
						$image                   = sunshine_get_image( $orderitem['image_id'] );
						if ( $image->exists() ) {
							$orderitem['gallery_id'] = $image->get_gallery_id();
						}
					}

					// Insert into order item database table.
					$insert_result = $wpdb->insert(
						$wpdb->prefix . 'sunshine_order_items',
						array(
							'order_id'    => $object->ID,
							'type'        => $type,
							'image_id'    => $orderitem['image_id'],
							'qty'         => $orderitem['qty'],
							'product_id'  => $orderitem['product_id'],
							'gallery_id'  => $orderitem['gallery_id'],
							'price_level' => $orderitem['price_level'],
							'price'       => $orderitem['price'],
						)
					);

					// Insert any of the item meta data.
					if ( $insert_result ) {
						$order_item_id = $wpdb->insert_id;
						if ( $order_item_id ) {

							$meta = array(
								'gallery_name'     => get_the_title( $orderitem['gallery_id'] ),
								'image_name'       => get_the_title( $orderitem['image_id'] ),
								'product_name'     => get_the_title( $orderitem['product_id'] ),
								'product_cat_name' => '',
							);

							if ( ! empty( $orderitem['filename'] ) ) {
								$filename         = parse_url( $orderitem['filename'], PHP_URL_PATH ); // Get the path part of the URL
								$filename         = pathinfo( $filename, PATHINFO_BASENAME ); // Extract the base name of the file
								$meta['filename'] = $filename;
							}

							// Product Options.
							if ( ! empty( $orderitem['product_options'] ) ) {
								$meta['options'] = array();
								foreach ( $orderitem['product_options'] as $product_option_item_id => $product_option_item ) {
									$new_option_item          = array(
										'price' => $product_option_item['amount'],
										'name'  => $product_option_item['name'],
									);
									$product_option_item_post = get_post( $product_option_item_id );
									if ( $product_option_item_post && $product_option_item_post->post_parent > 0 ) {
										$product_option_post = get_post( $product_option_item_post->post_parent );
										if ( $product_option_post ) {
											$new_option_item['name']  = $product_option_post->post_title;
											$new_option_item['value'] = $product_option_item['name'];
										}
									}
									$meta['options'][] = $new_option_item;
								}
							}

							// Package items.
							if ( ! empty( $orderitem['package_products'] ) ) {
								$package = array();
								foreach ( $orderitem['package_products'] as $package_product ) {
									$product_name = '';
									if ( is_numeric( $package_product['product_id'] ) ) {
										$product = sunshine_get_product( $package_product['product_id'] );
										if ( $product ) {
											$product_name = $product->get_name();
										}
									} elseif ( $package_product['product_id'] == 'GALLERY' ) {
										$product_name = 'Entire gallery download';
									}
									$image_data = array();
									if ( ! empty( $package_product['image_id'] ) ) {
										$image = sunshine_get_image( $package_product['image_id'] );
										if ( $image ) {
											$gallery_name = '';
											if ( $image->get_gallery() ) {
												$gallery_name = $image->get_gallery()->get_name();
											}
											$image_data[] = array(
												'id'       => $image->get_id(),
												'name'     => $image->get_name(),
												'filename' => $image->get_file_name(),
												'gallery_name' => $gallery_name,
											);
										}
									}
									$package[] = array(
										'product'      => $package_product['product_id'],
										'product_name' => $product_name,
										'qty'          => $package_product['qty'],
										'images'       => $image_data,
									);
								}
								$meta['package'] = $package;

								update_post_meta( $object->ID, 'package_items_updated', 'yes' );

							}

							foreach ( $meta as $key => $value ) {
								if ( empty( $value ) ) {
									continue;
								}
								$wpdb->insert(
									$wpdb->prefix . 'sunshine_order_itemmeta',
									array(
										'order_item_id' => $order_item_id,
										'meta_key'      => $key,
										'meta_value'    => maybe_serialize( $value ),
									)
								);
							}
						}
					}
				}
			}

			update_post_meta( $object->ID, 'update_3', time() );

			wp_send_json_success( array( 'updated' => $object->ID ) );

		}

		SPC()->update_option( 'update_3_orders', time() );
		wp_send_json_success();

	}

	public function update_3_discounts_update() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sunshine-photo-cart' ) ) );
		}

		check_admin_referer( 'sunshine_update_3', 'security' );

		$query   = "
			SELECT *
			FROM {$wpdb->prefix}posts
			WHERE post_type = 'sunshine-discount'
			AND NOT EXISTS (
				SELECT 1
				FROM {$wpdb->prefix}postmeta
				WHERE {$wpdb->prefix}postmeta.post_id = {$wpdb->prefix}posts.ID
				AND {$wpdb->prefix}postmeta.meta_key = 'update_3'
			)
			LIMIT 1
		";
		$objects = $wpdb->get_results( $query );
		if ( ! empty( $objects ) ) {
			$object = $objects[0];
			SPC()->log( 'Updating discount ID: ' . $object->ID );
			$amount = get_post_meta( $object->ID, 'amount', true );
			if ( $amount ) {
				update_post_meta( $object->ID, 'discount_amount', $amount );
			}
			update_post_meta( $object->ID, 'update_3', time() );
			wp_send_json_success( array( 'updated' => $object->ID ) );

		}

		SPC()->update_option( 'update_3_discounts', time() );
		wp_send_json_success();

	}

	public function update_3_emails_update() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sunshine-photo-cart' ) ) );
		}

		check_admin_referer( 'sunshine_update_3', 'security' );

		$search  = array(
			'{gallery_name}',
			'{gallery_url}',
			'{gallery_password}',
			'{gallery_images}',
			'{gallery_featured_image}',
			'{gallery_featured_image_url}',
			'{gallery_expiration_date}',
			'{register_url}',
			'{first_name}',
			'{last_name}',
			'{site_name}',
			'{site_url}',
			'{sunshine_url}',
			'{discount_code}',
			'{cart}',
			'{favorites}',
		);
		$replace = array(
			'[gallery_name]',
			'[gallery_url]',
			'[gallery_password]',
			'[gallery_images]',
			'[gallery_featured_image]',
			'[gallery_featured_image_url]',
			'[gallery_expiration_date]',
			'[register_url]',
			'[first_name]',
			'[last_name]',
			'[site_name]',
			'[site_url]',
			'[sunshine_url]',
			'[discount_code]',
			'[cart]',
			'[favorites]',
		);

		// Update the Messages templates
		$keys = array(
			'email_message-gallery_subject',
			'email_message-gallery_message',
			'email_message-user_subject',
			'email_message-user_message',
		);
		foreach ( $keys as $key ) {
			$value = get_option( 'sunshine_' . $key );
			if ( $value ) {
				$value = str_replace( $search, $replace, $value );
				update_option( 'sunshine_' . $key, $value );
			}
		}

		$query   = "
			SELECT *
			FROM {$wpdb->prefix}posts
			WHERE post_type = 'sunshine-email'
			AND NOT EXISTS (
				SELECT 1
				FROM {$wpdb->prefix}postmeta
				WHERE {$wpdb->prefix}postmeta.post_id = {$wpdb->prefix}posts.ID
				AND {$wpdb->prefix}postmeta.meta_key = 'update_3'
			)
			LIMIT 1
		";
		$objects = $wpdb->get_results( $query );
		if ( ! empty( $objects ) ) {
			$object = $objects[0];
			SPC()->log( 'Updating email ID: ' . $object->ID );

			$subject = get_post_meta( $object->ID, 'subject', true );

			$subject = str_replace( $search, $replace, $subject );
			$content = str_replace( $search, $replace, $object->post_content );
			wp_update_post(
				array(
					'ID'           => $object->ID,
					'post_title'   => $subject,
					'post_content' => $content,
				)
			);

			$time     = get_post_meta( $object->ID, 'time', true );
			$interval = get_post_meta( $object->ID, 'time_interval', true );
			if ( $time && $interval ) {
				update_post_meta(
					$object->ID,
					'time',
					array(
						'time'     => $time,
						'interval' => $interval,
					)
				);
			}

			$discount_time          = get_post_meta( $object->ID, 'discount_time', true );
			$discount_time_interval = get_post_meta( $object->ID, 'discount_time_interval', true );
			if ( $discount_time && $discount_time_interval ) {
				update_post_meta(
					$object->ID,
					'discount_time',
					array(
						'time'     => $discount_time,
						'interval' => $discount_time_interval,
					)
				);
			}

			update_post_meta( $object->ID, 'update_3', true );
			wp_send_json_success( array( 'updated' => $object->ID ) );

		}

		SPC()->update_option( 'update_3_emails', time() );
		wp_send_json_success();

	}

	public function update_3_galleries_common_update() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sunshine-photo-cart' ) ) );
		}

		// Check nonce for CSRF protection.
		check_admin_referer( 'sunshine_update_3', 'security' );

		$conversions = array(
			'sunshine_gallery_price_level'       => 'price_level',
			'sunshine_gallery_password_hint'     => 'password_hint',
			'sunshine_free_image_download_count' => 'free_image_download_count',
			'sunshine_download_users'            => 'download_users',
			'sunshine_gallery_minimum_order'     => 'minimum_order',
			'sunshine_gallery_status'            => 'status',
			'sunshine_gallery_access'            => 'access_type',
			'sunshine_gallery_image_comments'    => 'image_comments',
			'sunshine_gallery_images_directory'  => 'images_directory',
			'sunshine_gallery_disable_products'  => 'disable_products',
			'sunshine_gallery_disable_favorites' => 'disable_favorites',
			'sunshine_gallery_private_user'      => 'private_users',
			'sunshine_gallery_end_date'          => 'end_date',
			'sunshine_email_exclusions'          => 'email_exclusions',
			'sunshine_gallery_view'              => 'view_list',
		);
		$total       = count( $conversions );
		$current     = 0;
		foreach ( $conversions as $current_key => $new_key ) {

			$current++;

			// See if any exist for this one.
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
					$current_key,
				)
			);
			if ( $results ) {

				// Do the update query.
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
						$new_key,
						$current_key,
					)
				);
				wp_send_json_success(
					array(
						'current' => $current,
						'total'   => $total,
						'updated' => $new_key,
					)
				);

			}
		}

		SPC()->update_option( 'update_3_galleries_common', time() );
		wp_send_json_success();

	}

	public function update_3_galleries_update() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sunshine-photo-cart' ) ) );
		}

		check_admin_referer( 'sunshine_update_3', 'security' );

		$query   = "
			SELECT *
			FROM {$wpdb->prefix}posts
			WHERE post_type = 'sunshine-gallery'
			AND NOT EXISTS (
				SELECT 1
				FROM {$wpdb->prefix}postmeta
				WHERE {$wpdb->prefix}postmeta.post_id = {$wpdb->prefix}posts.ID
				AND {$wpdb->prefix}postmeta.meta_key = 'update_3'
			)
			LIMIT 1
		";
		$objects = $wpdb->get_results( $query );
		if ( ! empty( $objects ) ) {

			$object = $objects[0];

			SPC()->log( 'Updating gallery ID: ' . $object->ID );

			$meta_updates = array();

			$meta = get_post_meta( $object->ID );

			if ( empty( $meta['images'] ) ) {
				// Gallery data like images.
				$query       = $wpdb->prepare( "SELECT DISTINCT ID FROM $wpdb->posts WHERE post_parent = %d AND post_type = 'attachment'", $object->ID );
				$attachments = $wpdb->get_col( $query );
				if ( ! empty( $attachments ) ) {
					$meta_updates['images'] = maybe_serialize( $attachments );
				}
			} else {
				update_post_meta( $object->ID, 'update_3', time() );
				wp_send_json_success( array( 'updated' => $object->ID ) );
			}

			if ( $object->post_status == 'private' ) {
				$object->post_status = 'publish';
			}

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts}
					SET post_password = %s, post_status = %s
					WHERE ID = %d",
					'',
					$object->post_status,
					$object->ID
				)
			);

			// update_post_meta( $object->ID, 'password', $object->post_password );
			if ( $object->post_password ) {
				$meta_updates['password'] = $object->post_password;
			}

			// $image_sharing = get_post_meta( $object->ID, 'sunshine_image_share', true );
			if ( ! empty( $meta['sunshine_image_share'][0] ) == 'disallow' ) {
				// update_post_meta( $object->ID, 'disable_image_sharing', true );
				$meta_updates['disable_image_sharing'] = true;
			}

			// $gallery_sharing = get_post_meta( $object->ID, 'sunshine_gallery_share', true );
			if ( ! empty( $meta['sunshine_gallery_share'][0] ) == 'disallow' ) {
				// update_post_meta( $object->ID, 'disable_gallery_sharing', true );
				$meta_updates['sunshine_gallery_share'] = true;
			}

			$free_downloads = array();
			// $free_image_downloads = get_post_meta( $object->ID, 'sunshine_free_image_downloads', true );
			if ( ! empty( $meta['sunshine_free_image_downloads'][0] ) ) {
				$free_downloads[] = 'image';
			}
			// $free_gallery_downloads = get_post_meta( $object->ID, 'sunshine_free_gallery_downloads', true );
			if ( ! empty( $meta['sunshine_free_gallery_downloads'][0] ) ) {
				$free_downloads[] = 'gallery';
			}
			if ( ! empty( $free_downloads ) ) {
				// update_post_meta( $object->ID, 'free_downloads', $free_downloads );
				$meta_updates['free_downloads'] = maybe_serialize( $free_downloads );
			}

			// $gallery_download = get_post_meta( $object->ID, 'sunshine_product_gallery_download', true );
			if ( ! empty( $meta['sunshine_product_gallery_download'][0] ) ) {
				// update_post_meta( $object->ID, 'gallery_download', true );
				$meta_updates['gallery_download'] = true;
			}

			$meta_updates['update_3'] = time();

			if ( ! empty( $meta_updates ) ) {
				foreach ( $meta_updates as $meta_key => $meta_value ) {
					$wpdb->insert(
						$wpdb->postmeta,
						array(
							'post_id'    => $object->ID,
							'meta_key'   => $meta_key,
							'meta_value' => $meta_value,
						)
					);
				}
			}

			// update_post_meta( $object->ID, 'update_3', time() );
			wp_send_json_success( array( 'updated' => $object->ID ) );

		}

		SPC()->update_option( 'update_3_galleries', time() );
		wp_send_json_success();

	}

	public function update_3_images_update() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sunshine-photo-cart' ) ) );
		}

		check_admin_referer( 'sunshine_update_3', 'security' );

		$prepared_sql = $wpdb->prepare(
			"UPDATE $wpdb->postmeta
			 SET meta_value = REPLACE(meta_value, %s, %s)
			 WHERE meta_key = '_wp_attachment_metadata'
			 AND meta_value LIKE %s
			 LIMIT %d",
			's:15:"sunshine-lowres";',
			's:14:"sunshine-large";',
			'%s:15:"sunshine-lowres";%',
			$this->batch
		);
		$result       = $wpdb->query( $prepared_sql );
		if ( $wpdb->rows_affected >= $this->batch ) {
			wp_send_json_success( array( 'updated' => $wpdb->rows_affected ) );
		}

		SPC()->update_option( 'update_3_images', time() );
		wp_send_json_success();

	}

	public function update_3_galleries_duplicate_meta() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sunshine-photo-cart' ) ) );
		}

		check_admin_referer( 'sunshine_update_3', 'security' );

		$query   = "
			SELECT *
			FROM {$wpdb->prefix}posts
			WHERE post_type = 'sunshine-gallery'
			AND NOT EXISTS (
				SELECT 1
				FROM {$wpdb->prefix}postmeta
				WHERE {$wpdb->prefix}postmeta.post_id = {$wpdb->prefix}posts.ID
				AND {$wpdb->prefix}postmeta.meta_key = 'update_3_duplicate_meta'
			)
			LIMIT 1
		";
		$objects = $wpdb->get_results( $query );
		if ( ! empty( $objects ) ) {

			$object = $objects[0];

			$query = $wpdb->prepare(
				"
				DELETE wp1
				FROM {$wpdb->postmeta} wp1
				INNER JOIN {$wpdb->postmeta} wp2
				WHERE
					wp1.meta_id > wp2.meta_id AND
					wp1.post_id = wp2.post_id AND
					wp1.meta_key = wp2.meta_key AND
					wp1.meta_value = wp2.meta_value AND
					wp1.post_id = %d
			",
				$object->ID
			);

			$result = $wpdb->query( $query );

			update_post_meta( $object->ID, 'update_3_duplicate_meta', time() );
			wp_send_json_success( array( 'updated' => $object->ID ) );

		}

		wp_send_json_success();

	}


	public function update_3_complete() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sunshine-photo-cart' ) ) );
		}

		check_admin_referer( 'sunshine_update_3', 'security' );
		SPC()->update_option( 'update_3', true );
		flush_rewrite_rules();

		SPC()->log( 'Update complete!' );

		wp_send_json( array( 'notices' => SPC()->get_option( 'update_3_notices' ) ) );

	}

	public function update_3_cleanup() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sunshine-photo-cart' ) ) );
		}

		check_admin_referer( 'sunshine_update_3', 'security' );

		// Delete postmeta.
		$wpdb->query(
			$wpdb->prepare(
				"
		        DELETE FROM {$wpdb->postmeta}
		        WHERE meta_key = %s
		        ",
				'update_3'
			)
		);

		// Delete usermeta.
		$wpdb->query(
			$wpdb->prepare(
				"
		        DELETE FROM {$wpdb->usermeta}
		        WHERE meta_key = %s
		        ",
				'sunshine_update_3'
			)
		);

		delete_option( 'sunshine_update_3_settings' );

		// Delete the old metadata rows with the meta_key "sunshine_favorite"
		$wpdb->query(
			$wpdb->prepare(
				"
		        DELETE FROM {$wpdb->usermeta}
		        WHERE meta_key = %s
		        ",
				'sunshine_favorite'
			)
		);

		// Delete all product option CPT posts.
		$args         = array(
			'post_type'      => 'sunshine-product-opt',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		$product_opts = get_posts( $args );
		if ( ! empty( $product_opts ) ) {
			foreach ( $product_opts as $post ) {
				wp_delete_post( $post, true );
			}
		}

		// Delete no longer used postmeta.
		$meta_key = $wpdb->esc_like( 'sunshine_' ) . '%'; // Escaping the meta key prefix and adding wildcard (%)
		$query    = $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $meta_key );
		$wpdb->query( $query );

		$meta_key = $wpdb->esc_like( '_sunshine_' ) . '%'; // Escaping the meta key prefix and adding wildcard (%)
		$query    = $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $meta_key );
		$wpdb->query( $query );

		$keys = array(
			'txn_id',
			'stripe_mode',
			'square_mode',
			'square_charge_id',
			'payer_id',
		);
		foreach ( $keys as $key ) {
			$query = $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $key );
			$wpdb->query( $query );
		}

		// Delete the old options.
		delete_option( 'sunshine_options' );

		SPC()->log( 'Updating clean up complete!' );

	}

	function check_plugin_activation( $plugin, $network_wide ) {
		// Get the plugin folder name
		$plugin_folder_name = explode( '/', $plugin )[0];

		// Check if plugin folder name starts with "sunshine-"
		if ( strpos( $plugin_folder_name, 'sunshine-' ) === 0 ) {
			// Get plugin data to check version
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );

			// Check if plugin version is less than 3
			if ( version_compare( $plugin_data['Version'], '3', '<' ) ) {
				// Deactivate the plugin
				deactivate_plugins( $plugin );

				$plugin_name = $plugin_data['Name'];
				set_transient( 'sunshine_addon_deactivation_notice', $plugin_name, 500 );

			}
		}
	}

	function show_deactivation_notice() {

		$plugin_name = get_transient( 'sunshine_addon_deactivation_notice' );

		if ( $plugin_name ) {
			echo '<div class="notice notice-error is-dismissible">
					<p>The Sunshine add-on plugin(s) you tried to activate are not compatible with Sunshine 3. They have been deactivated to prevent your site from crashing. <a href="https://www.sunshinephotocart.com/docs/sunshine-3-update-and-deactivated-add-ons/" target="_blank" class="button">Learn more here</a></p>
				  </div>';

			// Delete the transient so the notice doesn't keep showing
			delete_transient( 'sunshine_addon_deactivation_notice' );
		}
	}


}

new SPC_Update();
