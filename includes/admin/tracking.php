<?php
add_action(
	'admin_notices',
	function() {
		if ( isset( $_GET['sunshine_tracking_send'] ) && current_user_can( 'manage_options' ) ) {
			sunshine_tracking_send();
		}
	}
);

add_action( 'sunshine_tracking_send', 'sunshine_tracking_send' );
function sunshine_tracking_send() {
	global $wpdb;

	$allow_tracking = get_option( 'sunshine_tracking_allow' );
	if ( ! $allow_tracking ) {
		// return;
	}

	include_once ABSPATH . 'wp-admin/includes/plugin.php';

	$data = array(
		'action'         => 'tracking',
		'key'            => md5( get_bloginfo( 'url' ) ),
		'country'        => SPC()->get_option( 'country' ),
		'language'       => get_locale(),
		'currency'       => SPC()->get_option( 'currency' ),
		'gallery_count'  => wp_count_posts( 'sunshine-gallery' )->publish,
		'product_count'  => wp_count_posts( 'sunshine-product' )->publish,
		'install_date'   => get_option( 'sunshine_install_time' ),
		'theme'          => ( wp_get_theme()->parent() ) ? wp_get_theme()->parent()->get( 'Name' ) : wp_get_theme()->get( 'Name' ),
		'sunshine_theme' => SPC()->get_option( 'theme' ),
		'website'        => ( SPC()->get_option( 'page' ) ) ? get_permalink( SPC()->get_option( 'page' ) ) : get_bloginfo( 'url' ),
		'email'          => get_bloginfo( 'admin_email' ),
		'pro'            => ( SPC()->is_pro() ) ? 1 : 0,
		'niches'         => SPC()->get_option( 'tracking_niches' ),
		'version'        => SUNSHINE_PHOTO_CART_VERSION,
		'php'            => phpversion(),
		'wp'             => get_bloginfo( 'version' ),
		'environment'    => wp_get_environment_type(),
	);

	// Order totals
	/*
	$sql = "SELECT SUM(order_items.price * order_items.qty) as total, COUNT(*) as order_count
			FROM {$wpdb->prefix}sunshine_order_items order_items
			LEFT JOIN {$wpdb->prefix}posts p ON p.ID = order_items.order_id
			WHERE p.post_type = 'sunshine-order'
			AND p.post_status = 'publish'";
			*/

	$sql                 = "SELECT
				COUNT({$wpdb->prefix}posts.ID) AS order_count,
				SUM(meta_total.meta_value) AS total
			FROM {$wpdb->prefix}posts
			JOIN {$wpdb->prefix}postmeta AS meta_total ON {$wpdb->prefix}posts.ID = meta_total.post_id
			JOIN {$wpdb->prefix}postmeta AS meta_mode ON {$wpdb->prefix}posts.ID = meta_mode.post_id
			WHERE {$wpdb->prefix}posts.post_type = 'sunshine-order'
			AND meta_mode.meta_key = 'mode'
			AND meta_mode.meta_value = 'live'
			AND meta_total.meta_key = 'total'";
	$orders              = $wpdb->get_row( $sql );
	$data['order_count'] = $orders->order_count;
	$data['order_total'] = round( $orders->total, 2 );

	$sql                  = "SELECT
			    COUNT(DISTINCT {$wpdb->prefix}posts.ID) AS order_count,
			    SUM(meta_total.meta_value) AS total
			FROM {$wpdb->prefix}posts
			JOIN {$wpdb->prefix}postmeta AS meta_total ON {$wpdb->prefix}posts.ID = meta_total.post_id AND meta_total.meta_key = 'total'
			JOIN {$wpdb->prefix}postmeta AS meta_payment ON {$wpdb->prefix}posts.ID = meta_payment.post_id AND meta_payment.meta_key = 'payment_method' AND meta_payment.meta_value = 'paypal'
			JOIN {$wpdb->prefix}postmeta AS meta_mode ON {$wpdb->prefix}posts.ID = meta_mode.post_id AND meta_mode.meta_key = 'mode' AND meta_mode.meta_value = 'live'
			WHERE {$wpdb->prefix}posts.post_type = 'sunshine-order'
			";
	$orders               = $wpdb->get_row( $sql );
	$data['paypal_count'] = $orders->order_count;
	$data['paypal_total'] = $orders->total;

	// Square app fee totals
	$sql                          = "SELECT
			    COUNT(DISTINCT {$wpdb->prefix}posts.ID) AS order_count,
			    SUM(meta_fee.meta_value) AS total
			FROM {$wpdb->prefix}posts
			JOIN {$wpdb->prefix}postmeta AS meta_fee ON {$wpdb->prefix}posts.ID = meta_fee.post_id AND meta_fee.meta_key = 'square_app_fee'
			JOIN {$wpdb->prefix}postmeta AS meta_mode ON {$wpdb->prefix}posts.ID = meta_mode.post_id AND meta_mode.meta_key = 'mode' AND meta_mode.meta_value = 'live'
			WHERE {$wpdb->prefix}posts.post_type = 'sunshine-order'
			";
	$orders                       = $wpdb->get_row( $sql );
	$data['square_app_fee']       = round( $orders->total, 2 );
	$data['square_app_fee_count'] = (int) $orders->order_count;

	// Stripe app fee totals
	$sql                          = "SELECT
			    COUNT(DISTINCT {$wpdb->prefix}posts.ID) AS order_count,
			    SUM(meta_fee.meta_value) AS total
			FROM {$wpdb->prefix}posts
			JOIN {$wpdb->prefix}postmeta AS meta_fee ON {$wpdb->prefix}posts.ID = meta_fee.post_id AND meta_fee.meta_key = 'application_fee_amount'
			JOIN {$wpdb->prefix}postmeta AS meta_mode ON {$wpdb->prefix}posts.ID = meta_mode.post_id AND meta_mode.meta_key = 'mode' AND meta_mode.meta_value = 'live'
			WHERE {$wpdb->prefix}posts.post_type = 'sunshine-order'
			";
	$orders                       = $wpdb->get_row( $sql );
	$data['stripe_app_fee']       = round( $orders->total, 2 );
	$data['stripe_app_fee_count'] = (int) $orders->order_count;

	$result              = $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->prefix}posts p
		INNER JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
		WHERE p.post_type = 'attachment'
		AND pm.meta_key = 'sunshine_file_name'"
	);
	$data['image_count'] = $result;

	$payment_gateways       = array();
	$active_payment_methods = sunshine_get_active_payment_methods();
	if ( ! empty( $active_payment_methods ) ) {
		foreach ( $active_payment_methods as $payment_method ) {
			$payment_gateways[] = $payment_method->get_id();
		}
	}
	$data['payment_gateways'] = $payment_gateways;

	$shipping                = array();
	$active_shipping_methods = sunshine_get_active_shipping_methods();
	if ( ! empty( $active_shipping_methods ) ) {
		foreach ( $active_shipping_methods as $shipping_method ) {
			$shipping[] = $shipping_method['id'];
		}
	}
	$data['shipping'] = $shipping;

	$data['addons'] = array();
	$all_plugins    = get_plugins();
	foreach ( $all_plugins as $plugin_path => $plugin_data ) {
		$folder_name = explode( '/', $plugin_path )[0];
		if ( strpos( $folder_name, 'sunshine' ) === 0 && $folder_name !== 'sunshine-photo-cart' && is_plugin_active( $plugin_path ) ) {
			$data['addons'][] = str_replace( 'sunshine-', '', $folder_name );
		}
	}

	// get the most recent sunshine-gallery created date
	$gallery = get_posts(
		array(
			'post_type'      => 'sunshine-gallery',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'posts_per_page' => 1,
		)
	);
	if ( ! empty( $gallery ) ) {
		$data['gallery_last_created'] = $gallery[0]->post_date;
	}

	sunshine_log( $data, 'Tracking data' );

	SPC()->log( 'Tracking data sent' );

	$response = wp_remote_post(
		trailingslashit( SUNSHINE_PHOTO_CART_STORE_URL ),
		array(
			'timeout'   => 100,
			'sslverify' => true,
			'body'      => $data,
		)
	);

}
