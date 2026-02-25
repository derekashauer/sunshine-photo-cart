<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPC_Privacy {

	/**
	 * Order meta keys that contain personal data and should be erased.
	 */
	private static $order_personal_data_keys = array(
		'first_name',
		'last_name',
		'billing_first_name',
		'billing_last_name',
		'billing_address1',
		'billing_address2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_country',
		'shipping_first_name',
		'shipping_last_name',
		'shipping_address1',
		'shipping_address2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
		'shipping_country',
		'email',
		'phone',
		'customer_notes',
		'transaction_id',
	);

	/**
	 * Customer meta keys (without sunshine_ prefix) that contain personal data.
	 */
	private static $customer_personal_data_keys = array(
		'shipping_address1',
		'shipping_address2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
		'shipping_country',
		'billing_address1',
		'billing_address2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_country',
		'phone',
		'vat',
		'favorites',
		'favorite_key',
		'credits',
		'cart',
		'order_count',
		'order_totals',
		'favorites_count',
		'action',
		'last_login',
	);

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
		add_action( 'sunshine_daily', array( $this, 'retention_cleanup' ) );
	}

	/**
	 * Register data erasers with WordPress privacy system.
	 */
	public function register_erasers( $erasers ) {
		$erasers['sunshine-customer-data'] = array(
			'eraser_friendly_name' => __( 'Sunshine Photo Cart Customer Data', 'sunshine-photo-cart' ),
			'callback'             => array( $this, 'customer_data_eraser' ),
		);
		$erasers['sunshine-order-data'] = array(
			'eraser_friendly_name' => __( 'Sunshine Photo Cart Order Data', 'sunshine-photo-cart' ),
			'callback'             => array( $this, 'order_data_eraser' ),
		);
		return $erasers;
	}

	/**
	 * Register data exporters with WordPress privacy system.
	 */
	public function register_exporters( $exporters ) {
		$exporters['sunshine-customer-data'] = array(
			'exporter_friendly_name' => __( 'Sunshine Photo Cart Customer Data', 'sunshine-photo-cart' ),
			'callback'               => array( $this, 'customer_data_exporter' ),
		);
		$exporters['sunshine-order-data'] = array(
			'exporter_friendly_name' => __( 'Sunshine Photo Cart Order Data', 'sunshine-photo-cart' ),
			'callback'               => array( $this, 'order_data_exporter' ),
		);
		return $exporters;
	}

	/**
	 * Erase customer personal data (user meta).
	 */
	public function customer_data_eraser( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$items_removed = false;

		foreach ( self::$customer_personal_data_keys as $key ) {
			$meta_key = SPC()->prefix . $key;
			$value    = get_user_meta( $user->ID, $meta_key, true );
			if ( ! empty( $value ) ) {
				delete_user_meta( $user->ID, $meta_key );
				$items_removed = true;
			}
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Erase personal data from orders.
	 */
	public function order_data_eraser( $email_address, $page = 1 ) {
		if ( ! SPC()->get_option( 'privacy_erasure_remove_order_data' ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array(
					__( 'Sunshine Photo Cart order data was retained due to privacy settings.', 'sunshine-photo-cart' ),
				),
				'done'           => true,
			);
		}

		$per_page = 10;
		$page     = (int) $page;

		$orders = $this->get_orders_by_email( $email_address, $page, $per_page );

		$items_removed = false;

		foreach ( $orders as $order_post ) {
			if ( self::anonymize_order( $order_post->ID ) ) {
				$items_removed = true;
			}
		}

		$done = count( $orders ) < $per_page;

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $done,
		);
	}

	/**
	 * Export customer personal data.
	 */
	public function customer_data_exporter( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$data = array();

		$export_keys = array(
			'phone'             => __( 'Phone', 'sunshine-photo-cart' ),
			'vat'               => __( 'VAT Number', 'sunshine-photo-cart' ),
			'billing_address1'  => __( 'Billing Address 1', 'sunshine-photo-cart' ),
			'billing_address2'  => __( 'Billing Address 2', 'sunshine-photo-cart' ),
			'billing_city'      => __( 'Billing City', 'sunshine-photo-cart' ),
			'billing_state'     => __( 'Billing State', 'sunshine-photo-cart' ),
			'billing_postcode'  => __( 'Billing Postcode', 'sunshine-photo-cart' ),
			'billing_country'   => __( 'Billing Country', 'sunshine-photo-cart' ),
			'shipping_address1' => __( 'Shipping Address 1', 'sunshine-photo-cart' ),
			'shipping_address2' => __( 'Shipping Address 2', 'sunshine-photo-cart' ),
			'shipping_city'     => __( 'Shipping City', 'sunshine-photo-cart' ),
			'shipping_state'    => __( 'Shipping State', 'sunshine-photo-cart' ),
			'shipping_postcode' => __( 'Shipping Postcode', 'sunshine-photo-cart' ),
			'shipping_country'  => __( 'Shipping Country', 'sunshine-photo-cart' ),
			'favorites'         => __( 'Favorite Images', 'sunshine-photo-cart' ),
			'credits'           => __( 'Account Credits', 'sunshine-photo-cart' ),
			'last_login'        => __( 'Last Login', 'sunshine-photo-cart' ),
		);

		foreach ( $export_keys as $key => $label ) {
			$value = get_user_meta( $user->ID, SPC()->prefix . $key, true );
			if ( ! empty( $value ) ) {
				if ( is_array( $value ) ) {
					$value = implode( ', ', $value );
				}
				$data[] = array(
					'name'  => $label,
					'value' => $value,
				);
			}
		}

		$export_data = array();
		if ( ! empty( $data ) ) {
			$export_data[] = array(
				'group_id'    => 'sunshine-customer',
				'group_label' => __( 'Sunshine Photo Cart Customer Data', 'sunshine-photo-cart' ),
				'item_id'     => 'sunshine-customer-' . $user->ID,
				'data'        => $data,
			);
		}

		return array(
			'data' => $export_data,
			'done' => true,
		);
	}

	/**
	 * Export order data.
	 */
	public function order_data_exporter( $email_address, $page = 1 ) {
		$per_page = 10;
		$page     = (int) $page;

		$orders = $this->get_orders_by_email( $email_address, $page, $per_page );

		$export_data = array();

		foreach ( $orders as $order_post ) {
			$order = new SPC_Order( $order_post->ID );

			$data = array(
				array( 'name' => __( 'Order Number', 'sunshine-photo-cart' ), 'value' => $order->get_name() ),
				array( 'name' => __( 'Date', 'sunshine-photo-cart' ), 'value' => $order_post->post_date ),
				array( 'name' => __( 'Status', 'sunshine-photo-cart' ), 'value' => $order->get_status() ),
				array( 'name' => __( 'Email', 'sunshine-photo-cart' ), 'value' => $order->get_email() ),
				array( 'name' => __( 'Phone', 'sunshine-photo-cart' ), 'value' => $order->get_phone() ),
				array( 'name' => __( 'Billing Name', 'sunshine-photo-cart' ), 'value' => $order->get_meta_value( 'billing_first_name' ) . ' ' . $order->get_meta_value( 'billing_last_name' ) ),
				array( 'name' => __( 'Billing Address', 'sunshine-photo-cart' ), 'value' => implode( ', ', array_filter( array(
					$order->get_meta_value( 'billing_address1' ),
					$order->get_meta_value( 'billing_address2' ),
					$order->get_meta_value( 'billing_city' ),
					$order->get_meta_value( 'billing_state' ),
					$order->get_meta_value( 'billing_postcode' ),
					$order->get_meta_value( 'billing_country' ),
				) ) ) ),
				array( 'name' => __( 'Shipping Name', 'sunshine-photo-cart' ), 'value' => $order->get_meta_value( 'shipping_first_name' ) . ' ' . $order->get_meta_value( 'shipping_last_name' ) ),
				array( 'name' => __( 'Shipping Address', 'sunshine-photo-cart' ), 'value' => implode( ', ', array_filter( array(
					$order->get_meta_value( 'shipping_address1' ),
					$order->get_meta_value( 'shipping_address2' ),
					$order->get_meta_value( 'shipping_city' ),
					$order->get_meta_value( 'shipping_state' ),
					$order->get_meta_value( 'shipping_postcode' ),
					$order->get_meta_value( 'shipping_country' ),
				) ) ) ),
				array( 'name' => __( 'Customer Notes', 'sunshine-photo-cart' ), 'value' => $order->get_meta_value( 'customer_notes' ) ),
				array( 'name' => __( 'Total', 'sunshine-photo-cart' ), 'value' => $order->get_meta_value( 'total' ) ),
			);

			// Filter out empty values.
			$data = array_filter( $data, function( $item ) {
				return ! empty( trim( $item['value'] ) );
			} );

			$export_data[] = array(
				'group_id'    => 'sunshine-orders',
				'group_label' => __( 'Sunshine Photo Cart Orders', 'sunshine-photo-cart' ),
				'item_id'     => 'sunshine-order-' . $order_post->ID,
				'data'        => array_values( $data ),
			);
		}

		$done = count( $orders ) < $per_page;

		return array(
			'data' => $export_data,
			'done' => $done,
		);
	}

	/**
	 * Anonymize an order's personal data.
	 *
	 * @param int $order_id The order post ID.
	 * @return bool Whether anonymization was performed.
	 */
	public static function anonymize_order( $order_id ) {
		$order = new SPC_Order( $order_id );

		if ( ! $order->get_id() ) {
			return false;
		}

		// Skip if already anonymized.
		if ( get_post_meta( $order_id, '_sunshine_personal_data_removed', true ) ) {
			return false;
		}

		// Blank all PII meta fields.
		foreach ( self::$order_personal_data_keys as $key ) {
			$order->update_meta_value( $key, '' );
		}

		// Set customer_id to 0.
		$order->update_meta_value( 'customer_id', 0 );

		// Anonymize order item meta (comments field may contain PII).
		global $wpdb;
		$item_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT order_item_id FROM {$wpdb->prefix}sunshine_order_items WHERE order_id = %d",
			$order_id
		) );

		if ( ! empty( $item_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}sunshine_order_itemmeta SET meta_value = '' WHERE meta_key = 'comments' AND order_item_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$item_ids
			) );
		}

		// Anonymize order log entries (WP comments).
		$wpdb->update(
			$wpdb->comments,
			array(
				'comment_author'       => __( 'Removed', 'sunshine-photo-cart' ),
				'comment_author_email' => '',
			),
			array(
				'comment_post_ID' => $order_id,
				'comment_type'    => 'sunshine_order_log',
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		// Set post_author to 0.
		wp_update_post( array(
			'ID'          => $order_id,
			'post_author' => 0,
		) );

		// Flag order as anonymized.
		update_post_meta( $order_id, '_sunshine_personal_data_removed', true );

		// Add order log.
		$order->add_log( __( 'Personal data removed.', 'sunshine-photo-cart' ) );

		return true;
	}

	/**
	 * Daily retention cleanup.
	 */
	public function retention_cleanup() {
		$this->cleanup_inactive_accounts();
		$this->cleanup_old_orders();
	}

	/**
	 * Remove inactive customer accounts based on retention setting.
	 */
	private function cleanup_inactive_accounts() {
		$cutoff = $this->get_retention_cutoff( 'inactive_accounts' );
		if ( ! $cutoff ) {
			return;
		}

		$cutoff_date = gmdate( 'Y-m-d H:i:s', $cutoff );

		$users = get_users( array(
			'role'       => sunshine_get_customer_role(),
			'number'     => 20,
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key'     => SPC()->prefix . 'last_login',
					'value'   => $cutoff,
					'compare' => '<',
					'type'    => 'NUMERIC',
				),
				array(
					'relation' => 'AND',
					array(
						'key'     => SPC()->prefix . 'last_login',
						'compare' => 'NOT EXISTS',
					),
				),
			),
		) );

		foreach ( $users as $user ) {
			// For users without last_login, check user_registered.
			$last_login = get_user_meta( $user->ID, SPC()->prefix . 'last_login', true );
			if ( empty( $last_login ) ) {
				if ( strtotime( $user->user_registered ) >= $cutoff ) {
					continue;
				}
			}

			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user->ID );
		}
	}

	/**
	 * Anonymize old orders based on retention setting.
	 */
	private function cleanup_old_orders() {
		$cutoff = $this->get_retention_cutoff( 'orders' );
		if ( ! $cutoff ) {
			return;
		}

		$cutoff_date = gmdate( 'Y-m-d H:i:s', $cutoff );

		$orders = get_posts( array(
			'post_type'      => 'sunshine-order',
			'posts_per_page' => 20,
			'post_status'    => 'any',
			'date_query'     => array(
				array(
					'before' => $cutoff_date,
				),
			),
			'meta_query'     => array(
				array(
					'key'     => '_sunshine_personal_data_removed',
					'compare' => 'NOT EXISTS',
				),
			),
			'fields'         => 'ids',
		) );

		foreach ( $orders as $order_id ) {
			self::anonymize_order( $order_id );
		}
	}

	/**
	 * Calculate the retention cutoff timestamp from a duration setting.
	 *
	 * @param string $type Setting key suffix (e.g., 'inactive_accounts', 'orders').
	 * @return int|false Unix timestamp cutoff, or false if retention is indefinite.
	 */
	private function get_retention_cutoff( $type ) {
		$value = SPC()->get_option( 'privacy_retain_' . $type );

		if ( empty( $value ) || empty( $value['number'] ) ) {
			return false;
		}

		$number = absint( $value['number'] );
		$unit   = isset( $value['unit'] ) ? $value['unit'] : 'months';

		$valid_units = array( 'days', 'weeks', 'months', 'years' );
		if ( ! in_array( $unit, $valid_units, true ) ) {
			$unit = 'months';
		}

		return strtotime( '-' . $number . ' ' . $unit );
	}

	/**
	 * Query orders by customer email address.
	 *
	 * @param string $email_address Customer email.
	 * @param int    $page          Page number.
	 * @param int    $per_page      Results per page.
	 * @return array Array of WP_Post objects.
	 */
	private function get_orders_by_email( $email_address, $page = 1, $per_page = 10 ) {
		$user     = get_user_by( 'email', $email_address );
		$user_id  = $user ? $user->ID : 0;

		$args = array(
			'post_type'      => 'sunshine-order',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => 'any',
		);

		// Search by email meta, or by author if user exists.
		if ( $user_id ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'   => 'email',
					'value' => $email_address,
				),
			);
			// Also match orders by post_author.
			// WP_Query doesn't natively support OR between author and meta_query,
			// so we run two queries and merge.
			$meta_orders   = get_posts( $args );
			$author_orders = get_posts( array(
				'post_type'      => 'sunshine-order',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'post_status'    => 'any',
				'author'         => $user_id,
			) );

			// Merge and deduplicate.
			$all_orders = array_merge( $meta_orders, $author_orders );
			$seen_ids   = array();
			$orders     = array();
			foreach ( $all_orders as $order ) {
				if ( ! in_array( $order->ID, $seen_ids, true ) ) {
					$seen_ids[] = $order->ID;
					$orders[]   = $order;
				}
			}
			return $orders;
		}

		// Guest orders - search by email meta only.
		$args['meta_query'] = array(
			array(
				'key'   => 'email',
				'value' => $email_address,
			),
		);

		return get_posts( $args );
	}
}
