<?php
// TODO
// Doing sandbox requires manually entering keys

// PRO - Orders API, digital wallets?

class SPC_Payment_Method_Square extends SPC_Payment_Method {

	private $total    = 0;
	private $currency = 'USD';
	private $environmentUrl; // 'https://connect.squareup.com' or 'https://connect.squareupsandbox.com' based on mode

	public function init() {

		$this->id                    = 'square';
		$this->name                  = __( 'Square', 'sunshine-photo-cart' );
		$this->class                 = get_class( $this );
		$this->description           = __( 'Pay with credit card', 'sunshine-photo-cart' );
		$this->can_be_enabled        = true;
		$this->needs_billing_address = true;

		add_action( 'sunshine_square_connect_display', array( $this, 'square_connect_display' ) );
		add_action( 'admin_init', array( $this, 'square_connect_return' ) );
		add_action( 'admin_init', array( $this, 'square_disconnect' ) );
		add_action( 'admin_init', array( $this, 'square_refresh_locations' ) );
		add_action( 'admin_init', array( $this, 'square_refresh_token' ) );
		add_action( 'admin_init', array( $this, 'square_refresh_fee' ) );

		add_filter( 'sunshine_export_order_headers', array( $this, 'export_header' ) );
		add_filter( 'sunshine_export_order_row', array( $this, 'export_row' ), 10, 2 );

		if ( ! $this->is_active() || ! $this->is_allowed() ) {
			return;
		}

		add_action( 'sunshine_square_access_token_refresh', array( $this, 'refresh_token' ) );
		add_action( 'sunshine_square_refresh_fees', array( $this, 'refresh_processing_fees' ) );
		add_action( 'sunshine_square_reconcile_pending', array( $this, 'reconcile_pending_orders' ) );

		// Background reconcile is a safety net only - normal orders finalize
		// synchronously at checkout. Scheduled here (not just at connect time) so
		// already-connected sites pick it up on upgrade. Filterable off.
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		if ( apply_filters( 'sunshine_square_background_reconcile', true ) ) {
			if ( ! wp_next_scheduled( 'sunshine_square_reconcile_pending' ) ) {
				wp_schedule_event( time(), 'sunshine_square_quarter_hour', 'sunshine_square_reconcile_pending' );
			}
		} elseif ( wp_next_scheduled( 'sunshine_square_reconcile_pending' ) ) {
			wp_clear_scheduled_hook( 'sunshine_square_reconcile_pending' );
		}

		add_action( 'wp', array( $this, 'init_setup' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		// add_filter( 'sunshine_checkout_field_payment_method_square', array( $this, 'buttons' ) );

		add_action( 'wp_ajax_nopriv_sunshine_square_init_order', array( $this, 'init_order' ) );
		add_action( 'wp_ajax_sunshine_square_init_order', array( $this, 'init_order' ) );

		add_action( 'sunshine_checkout_process_payment_square', array( $this, 'process_payment' ) );

		add_action( 'wp', array( $this, 'check_order_paid' ) );

		// add_action( 'template_redirect', array( $this, 'square_return_listener' ), 999 );

		// add_filter( 'sunshine_order_transaction_url', array( $this, 'transaction_url' ) );

		add_filter( 'sunshine_admin_order_tabs', array( $this, 'admin_order_tab' ), 10, 2 );
		add_action( 'sunshine_admin_order_tab_square', array( $this, 'admin_order_tab_content_square' ) );

		add_action( 'sunshine_order_actions', array( $this, 'order_actions' ), 10, 2 );
		add_action( 'sunshine_order_actions_options', array( $this, 'order_actions_options' ) );
		add_action( 'sunshine_order_process_action_square_refund', array( $this, 'process_refund' ) );
		add_action( 'sunshine_order_process_action_square_recheck', array( $this, 'process_recheck' ) );

		add_action( 'sunshine_checkout_validation', array( $this, 'checkout_validation' ) );

	}

	/* ADMIN */
	public function options( $options ) {

		foreach ( $options as &$option ) {
			if ( $option['id'] == 'square_header' && $this->get_application_fee_percent() > 0 ) {
				/* translators: %s is the application fee percentage */
				$option['description'] = sprintf( __( 'Note: You are using the free Square payment gateway integration. This includes an additional %s%% fee for payment processing on each order that goes to Sunshine Photo Cart in addition to Square processing fees. This added fee is removed by using the Square Pro add-on.', 'sunshine-photo-cart' ), $this->get_application_fee_percent() ) . ' <a href="https://www.sunshinephotocart.com/addon/square/?utm_source=plugin&utm_medium=link&utm_campaign=square" target="_blank">' . __( 'Learn more', 'sunshine-photo-cart' ) . '</a>';
			}
		}

		// TODO: Better descriptions and help on how to get the keys in square

		$options[] = array(
			'name'    => __( 'Mode', 'sunshine-photo-cart' ),
			'id'      => $this->id . '_mode',
			'type'    => 'radio',
			'options' => array(
				'live' => __( 'Live', 'sunshine-photo-cart' ),
				'test' => __( 'Sandbox', 'sunshine-photo-cart' ),
			),
			'default' => 'live',
		);

		$options[] = array(
			'name'             => __( 'Connect (Production)', 'sunshine-photo-cart' ),
			'id'               => $this->id . '_connect_live',
			'type'             => 'square_connect',
			'conditions'       => array(
				array(
					'field'   => $this->id . '_mode',
					'compare' => '==',
					'value'   => 'live',
					'action'  => 'show',
				),
			),
			'hide_system_info' => true,
		);

		$options[] = array(
			'name'             => __( 'Sandbox Settings', 'sunshine-photo-cart' ),
			'id'               => $this->id . '_sandbox_settings',
			'type'             => 'header',
			/* translators: %s is the URL to Square developer apps */
			'description'      => sprintf( __( 'Sandbox details can be created at: %s', 'sunshine-photo-cart' ), '<a href="https://developer.squareup.com/apps" target="_blank">https://developer.squareup.com/apps</a>' ),
			'conditions'       => array(
				array(
					'field'   => $this->id . '_mode',
					'compare' => '==',
					'value'   => 'test',
					'action'  => 'show',
				),
			),
			'hide_system_info' => true,
		);

		$options[] = array(
			'name'             => __( 'Sandbox Application ID', 'sunshine-photo-cart' ),
			'id'               => $this->id . '_application_id_test',
			'type'             => 'text',
			'conditions'       => array(
				array(
					'field'   => $this->id . '_mode',
					'compare' => '==',
					'value'   => 'test',
					'action'  => 'show',
				),
			),
			'hide_system_info' => true,
		);
		$options[] = array(
			'name'             => __( 'Sandbox Access Token', 'sunshine-photo-cart' ),
			'id'               => $this->id . '_access_token_test',
			'type'             => 'text',
			'conditions'       => array(
				array(
					'field'   => $this->id . '_mode',
					'compare' => '==',
					'value'   => 'test',
					'action'  => 'show',
				),
			),
			'hide_system_info' => true,
		);

		if ( $this->get_access_token( 'live' ) ) {
			$locations        = array( '' => __( 'Use default location', 'sunshine-photo-cart' ) );
			$square_locations = $this->get_option( 'locations_live' );
			if ( ! empty( $square_locations ) && is_array( $square_locations ) ) {
				$locations = array_merge( $locations, $square_locations );
			}
			$options[] = array(
				'name'             => __( 'Location (Production)', 'sunshine-photo-cart' ),
				'id'               => $this->id . '_location_id_live',
				'type'             => 'select',
				'options'          => $locations,
				'description'      => '<a href="' . wp_nonce_url( admin_url( 'admin.php?sunshine_square_refresh_locations' ), 'sunshine_square_refresh_locations' ) . '">' . __( 'Refresh locations', 'sunshine-photo-cart' ) . '</a>',
				'conditions'       => array(
					array(
						'field'   => $this->id . '_mode',
						'compare' => '==',
						'value'   => 'live',
						'action'  => 'show',
					),
				),
				'hide_system_info' => true,
			);
		}

		if ( $this->get_access_token( 'test' ) ) {
			$locations        = array( '' => __( 'Use default location', 'sunshine-photo-cart' ) );
			$square_locations = $this->get_option( 'locations_test' );
			if ( ! empty( $square_locations ) && is_array( $square_locations ) ) {
				$locations = array_merge( $locations, $square_locations );
			}
			$options[] = array(
				'name'             => __( 'Sandbox Location', 'sunshine-photo-cart' ),
				'id'               => $this->id . '_location_id_test',
				'type'             => 'select',
				'options'          => $locations,
				'description'      => '<a href="' . wp_nonce_url( admin_url( 'admin.php?sunshine_square_refresh_locations' ), 'sunshine_square_refresh_locations' ) . '">' . __( 'Refresh locations', 'sunshine-photo-cart' ) . '</a>',
				'conditions'       => array(
					array(
						'field'   => $this->id . '_mode',
						'compare' => '==',
						'value'   => 'test',
						'action'  => 'show',
					),
				),
				'hide_system_info' => true,
			);
		}

		return $options;
	}

	function square_connect_display( $field ) {

		if ( $field['id'] == 'square_connect_live' ) {
			$mode = 'live';
		} else {
			$mode = 'test';
		}

		$access_token = $this->get_access_token( $mode );

		if ( $access_token ) {
			?>

			<p><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?sunshine_square_disconnect' ), 'sunshine_square_disconnect' ) ); ?>" class="button"><?php esc_html_e( 'Disconnect from Square', 'sunshine-photo-cart' ); ?></a></p>
			<p><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?sunshine_square_refresh_token' ), 'sunshine_square_refresh_token' ) ); ?>"><?php esc_html_e( 'Refresh connection', 'sunshine-photo-cart' ); ?></a></p>

	<?php } else { ?>

			<p><a href="https://www.sunshinephotocart.com/?square_connect=1&mode=<?php echo esc_attr( $mode ); ?>&nonce=<?php echo esc_attr( wp_create_nonce( 'sunshine_square_connect' ) ); ?>&return_url=<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="button"><?php esc_html_e( 'Connect to Square', 'sunshine-photo-cart' ); ?></a></p>

			<?php
	}

	}

	function square_connect_return() {

		if ( ! isset( $_GET['sunshine_square_connect_return'] ) || ! current_user_can( 'sunshine_manage_options' ) ) {
			return false;
		}

		if ( empty( $_GET['nonce'] ) || ! wp_verify_nonce( $_GET['nonce'], 'sunshine_square_connect' ) || empty( $_GET['access_token'] ) || empty( $_GET['refresh_token'] ) ) {
			SPC()->notices->add_admin( 'square_connect_fail', __( 'Square could not be connected', 'sunshine-photo-cart' ), 'error' );
			wp_redirect( admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=square' ) );
			exit;
		}

		if ( isset( $_GET['mode'] ) && $_GET['mode'] == 'live' ) {
			$mode = 'live';
		} else {
			$mode = 'test';
		}

		$access_token  = $this->secure_token( sanitize_text_field( $_GET['access_token'] ), 'e' );
		$refresh_token = $this->secure_token( sanitize_text_field( $_GET['refresh_token'] ), 'e' );
		$this->update_option( 'access_token_' . $mode, $access_token );
		$this->update_option( 'refresh_token_' . $mode, $refresh_token );
		$this->update_option( 'merchant_id_' . $mode, sanitize_text_field( $_GET['merchant_id'] ) );
		$this->update_option( 'token_date_' . $mode, current_time( 'timestamp' ) );
		$this->update_option( 'mode', $mode );
		SPC()->log( 'Square connected' );

		$this->get_locations( $mode );

		if ( ! wp_next_scheduled( 'sunshine_square_access_token_refresh' ) ) {
			$result = wp_schedule_event( current_time( 'timestamp' ), 'weekly', 'sunshine_square_access_token_refresh' );
			if ( is_wp_error( $result ) ) {
				SPC()->log( 'Square access token refresh not scheduled' );
			} else {
				SPC()->log( 'Square access token refresh scheduled' );
			}
		}

		if ( ! wp_next_scheduled( 'sunshine_square_refresh_fees' ) ) {
			$result = wp_schedule_event( current_time( 'timestamp' ), 'daily', 'sunshine_square_refresh_fees' );
			if ( is_wp_error( $result ) ) {
				SPC()->log( 'Square processing fee refresh not scheduled' );
			} else {
				SPC()->log( 'Square processing fee refresh scheduled' );
			}
		}

		SPC()->notices->add_admin( 'square_connected', __( 'Square has successfully been connected', 'sunshine-photo-cart' ), 'success' );

		wp_redirect( admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=square' ) );
		exit;

	}

	function square_disconnect() {

		if ( ! isset( $_GET['sunshine_square_disconnect'] ) || ! check_admin_referer( 'sunshine_square_disconnect' ) || ! current_user_can( 'sunshine_manage_options' ) ) {
			return;
		}

		$this->setup();

		$mode = $this->get_mode_value();

		// Disconnect on sunshinephotocart.com
		$request = array(
			'body'    => array(
				'merchant_id' => $this->get_merchant_id(),
				// 'access_token' => $this->get_access_token(),
			),
			'timeout' => 45,
		);

		// make the request
		$response = wp_remote_post( 'https://www.sunshinephotocart.com/?square_disconnect=1', $request );
		if ( is_wp_error( $response ) ) {
			SPC()->notices->add_admin( 'square_failed_disconnect', $response->get_error_message(), 'error' );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $body->error ) || isset( $body->data->error ) ) {
			/* translators: %s is the error message */
			SPC()->log( sprintf( __( 'Failed to remove association from Sunshine Photo Cart: %s', 'sunshine-photo-cart' ), $body->data->error ) );
			/* translators: %s is the error message */
			SPC()->notices->add_admin( 'square_failed_disconnect', sprintf( __( 'Failed to remove association from Sunshine Photo Cart: %s', 'sunshine-photo-cart' ), $body->data->error ), 'error' );
		}

		$this->update_option( 'access_token_' . $mode, '' );
		$this->update_option( 'refresh_token_' . $mode, '' );
		$this->update_option( 'merchant_id_' . $mode, '' );
		$this->update_option( 'locations_' . $mode, '' );
		$this->update_option( 'location_id_' . $mode, '' );
		$this->update_option( 'token_date_' . $mode, '' );
		SPC()->log( 'Square disconnected' );

		wp_clear_scheduled_hook( 'sunshine_square_access_token_refresh' );
		wp_clear_scheduled_hook( 'sunshine_square_refresh_fees' );
		SPC()->log( 'Square cron unscheduled' );

		SPC()->notices->add_admin( 'square_disconnected_success', __( 'Square has successfully been disconnected', 'sunshine-photo-cart' ), 'success' );

		wp_redirect( admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=square' ) );
		exit;

	}

	function square_refresh_locations() {

		if ( ! isset( $_GET['sunshine_square_refresh_locations'] ) || ! check_admin_referer( 'sunshine_square_refresh_locations' ) ) {
			return;
		}

		SPC()->log( 'Square locations manually refresh triggered' );
		$this->get_locations();

		wp_redirect( admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=square' ) );
		exit;

	}

	private function get_locations() {

		$this->setup();

		$response = $this->api_request( 'v2/locations', '', 'GET' );

		if ( is_wp_error( $response ) ) {
			SPC()->log( 'Failed Square location: ' . print_r( $response, 1 ) );
			SPC()->notices->add_admin( 'square_locations', __( 'Cannot get locations', 'sunshine-photo-cart' ) );
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['errors'] ) ) {
			SPC()->log( 'Failed Square location body: ' . print_r( $body, 1 ) );
			SPC()->notices->add_admin( 'square_locations', __( 'Cannot get locations', 'sunshine-photo-cart' ) );
			return;
		}

		if ( empty( $body['locations'] ) ) {
			SPC()->log( 'Failed Square location body: ' . print_r( $body, 1 ) );
			SPC()->notices->add_admin( 'square_locations', __( 'Cannot get locations', 'sunshine-photo-cart' ) );
			return;
		}

		$locations = array();
		foreach ( $body['locations'] as $location ) {
			$locations[ $location['id'] ] = $location['name'] . ' (' . $location['address']['address_line_1'] . ')';
		}
		$this->update_option( 'locations_' . $this->get_mode_value(), $locations );
		SPC()->notices->add_admin( 'square_location_refresh', __( 'Locations refreshed from Square', 'sunshine-photo-cart' ) );
		SPC()->log( 'Square locations updated' );

	}

	function square_refresh_token() {

		if ( ! isset( $_GET['sunshine_square_refresh_token'] ) || ! check_admin_referer( 'sunshine_square_refresh_token' ) ) {
			return;
		}

		$result = $this->refresh_token();
		wp_redirect( admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=square' ) );
		exit;

	}

	function square_refresh_fee() {

		if ( ! isset( $_GET['sunshine_square_refresh_fee'] ) || empty( $_GET['post'] ) ) {
			return;
		}

		$order_id = intval( $_GET['post'] );
		if ( ! check_admin_referer( 'sunshine_square_refresh_fee_' . $order_id ) ) {
			return;
		}

		$order = sunshine_get_order( $order_id );
		if ( ! $order || ! $order->exists() || $order->get_payment_method() !== $this->id ) {
			return;
		}

		$payment_id = $order->get_meta_value( 'square_payment_id' );
		if ( ! $payment_id ) {
			return;
		}

		$this->setup( $order->get_mode() );

		$response = $this->api_request( 'v2/payments/' . $payment_id, '', 'GET' );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message from Square */
			SPC()->notices->add_admin( 'square_refresh_fee_fail_' . $payment_id, sprintf( __( 'Could not refresh Square processing fee: %s', 'sunshine-photo-cart' ), $response->get_error_message() ), 'error' );
			wp_redirect( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) );
			exit;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['payment']['processing_fee'] ) ) {
			$processing_fee_total = 0;
			foreach ( $body['payment']['processing_fee'] as $fee_entry ) {
				if ( isset( $fee_entry['amount_money']['amount'] ) ) {
					$processing_fee_total += intval( $fee_entry['amount_money']['amount'] );
				}
			}
			if ( $processing_fee_total > 0 ) {
				$order->update_meta_value( 'square_processing_fee', $processing_fee_total / 100 );
				SPC()->notices->add_admin( 'square_refresh_fee_success_' . $payment_id, __( 'Square processing fee refreshed', 'sunshine-photo-cart' ) );
			} else {
				SPC()->notices->add_admin( 'square_refresh_fee_empty_' . $payment_id, __( 'Square has not yet calculated the processing fee for this payment. Try again after the payment has settled.', 'sunshine-photo-cart' ) );
			}
		} else {
			SPC()->notices->add_admin( 'square_refresh_fee_empty_' . $payment_id, __( 'Square has not yet calculated the processing fee for this payment. Try again after the payment has settled.', 'sunshine-photo-cart' ) );
		}

		wp_redirect( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) );
		exit;

	}

	public function refresh_processing_fees() {

		SPC()->log( 'Square processing fee refresh cron running' );

		$query = new WP_Query(
			array(
				'post_type'      => 'sunshine-order',
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'after'     => '30 days ago',
						'before'    => '24 hours ago',
						'inclusive' => false,
					),
				),
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => 'payment_method',
						'value' => $this->id,
					),
					array(
						'key'     => 'square_payment_id',
						'value'   => '',
						'compare' => '!=',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => 'square_processing_fee',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'   => 'square_processing_fee',
							'value' => '',
						),
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			SPC()->log( 'Square processing fee refresh: no orders to refresh' );
			return;
		}

		SPC()->log( 'Square processing fee refresh: checking ' . count( $query->posts ) . ' orders' );

		$updated = 0;
		foreach ( $query->posts as $order_id ) {
			$order = sunshine_get_order( $order_id );
			if ( ! $order || ! $order->exists() ) {
				continue;
			}
			$payment_id = $order->get_meta_value( 'square_payment_id' );
			if ( ! $payment_id ) {
				continue;
			}
			$mode = $order->get_mode();
			if ( ! $this->get_access_token( $mode ) ) {
				continue;
			}

			$this->environmentUrl = ( $mode === 'live' ) ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';

			$response = $this->api_request( 'v2/payments/' . $payment_id, '', 'GET', $mode );
			if ( is_wp_error( $response ) ) {
				continue;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $body['payment']['processing_fee'] ) ) {
				continue;
			}

			$total = 0;
			foreach ( $body['payment']['processing_fee'] as $fee_entry ) {
				if ( isset( $fee_entry['amount_money']['amount'] ) ) {
					$total += intval( $fee_entry['amount_money']['amount'] );
				}
			}
			if ( $total > 0 ) {
				$order->update_meta_value( 'square_processing_fee', $total / 100 );
				++$updated;
			}
		}

		SPC()->log( 'Square processing fee refresh: updated ' . $updated . ' orders' );

	}

	public function export_header( $headers ) {
		$headers[] = __( 'Square Processing Fee', 'sunshine-photo-cart' );
		return $headers;
	}

	public function export_row( $row, $order ) {
		$fee = '';
		if ( $order->get_payment_method() === $this->id ) {
			$fee = $order->get_meta_value( 'square_processing_fee' );
		}
		$row[] = $fee ? $fee : '';
		return $row;
	}


	public function refresh_token() {

		$request = array(
			'body'    => array(
				'refresh_token' => $this->get_refresh_token(),
			),
			'timeout' => 45,
		);

		// make the request
		$response = wp_remote_post( 'https://www.sunshinephotocart.com/?square_connect_refresh=1', $request );
		if ( is_wp_error( $response ) ) {
			SPC()->notices->add_admin( 'square_failed_refresh_connect', $response->get_error_message(), 'error' );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $body->error ) || isset( $body->data->error ) ) {
			/* translators: %s is the error message */
			SPC()->log( sprintf( __( 'Failed to refresh Square token: %s', 'sunshine-photo-cart' ), $body->data->error ) );
			/* translators: %s is the error message */
			SPC()->notices->add_admin( 'square_failed_refresh', sprintf( __( 'Failed to refresh Square token: %s', 'sunshine-photo-cart' ), $body->data->error ), 'error' );
			return false;
		}

		if ( $body->success ) {
			$mode          = $this->get_mode_value();
			$access_token  = $this->secure_token( sanitize_text_field( $body->data->access_token ), 'e' );
			$refresh_token = $this->secure_token( sanitize_text_field( $body->data->refresh_token ), 'e' );
			$this->update_option( 'access_token_' . $mode, $access_token );
			$this->update_option( 'refresh_token_' . $mode, $refresh_token );
			$this->update_option( 'merchant_id_' . $mode, $body->data->merchant_id );
			$this->update_option( 'token_date_' . $mode, current_time( 'timestamp' ) );
			SPC()->notices->add_admin( 'square_token_refresh', __( 'Square token successfully refreshed', 'sunshine-photo-cart' ), 'success' );
		}

	}

	private function secure_token( $string, $method = 'e' ) {

		// Set default output value
		$output = null;

		// Set secret keys
		$secret_key = wp_salt();
		$secret_iv  = wp_salt( 'secure_auth' );
		$key        = hash( 'sha256', $secret_key );
		$iv         = substr( hash( 'sha256', $secret_iv ), 0, 16 );

		// Check whether encryption or decryption
		if ( $method == 'e' ) {
			$output = base64_encode( openssl_encrypt( $string, 'AES-256-CBC', $key, 0, $iv ) );
		} elseif ( $method == 'd' ) {
			$output = openssl_decrypt( base64_decode( $string ), 'AES-256-CBC', $key, 0, $iv );
		}

		return $output;

	}

	/* PUBLIC */
	public function init_setup() {

		if ( ! is_sunshine_page( 'checkout' ) ) {
			return;
		}

		$this->setup();

	}

	private function setup( $mode = '' ) {

		if ( empty( $mode ) ) {
			$mode = $this->get_mode_value();
		}

		if ( empty( $this->get_access_token( $mode ) ) ) {
			return false;
		}

		$this->environmentUrl = ( $mode === 'live' ) ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';

		if ( empty( SPC()->cart ) ) {
			SPC()->cart->setup();
		}
		$this->total    = floor( 100 * SPC()->cart->get_total() );
		$this->currency = SPC()->get_option( 'currency' );

	}

	private function get_merchant_id( $mode = '' ) {
		if ( empty( $mode ) ) {
			$mode = $this->get_mode_value();
		}
		$merchant_id = $this->get_option( 'merchant_id_' . $mode );
		return $merchant_id;
	}

	private function get_access_token( $mode = '' ) {
		if ( empty( $mode ) ) {
			$mode = $this->get_mode_value();
		}
		$access_token = $this->get_option( 'access_token_' . $mode );
		if ( $mode == 'live' ) {
			$access_token = $this->secure_token( $access_token, 'd' );
		}
		return $access_token;
	}

	private function get_refresh_token( $mode = '' ) {
		if ( empty( $mode ) ) {
			$mode = $this->get_mode_value();
		}
		$access_token = $this->get_option( 'refresh_token_' . $mode );
		$access_token = $this->secure_token( $access_token, 'd' );
		return $access_token;
	}

	private function get_location_id( $mode = '' ) {
		if ( empty( $mode ) ) {
			$mode = $this->get_mode_value();
		}
		$location_id = $this->get_option( 'location_id_' . $mode );
		if ( empty( $location_id ) ) {
			$locations = $this->get_option( 'locations_' . $mode );
			if ( ! empty( $locations ) && is_array( $locations ) ) {
				$location_id = array_key_first( $locations );
			}
		}
		return $location_id;
	}

	public function get_application_id() {
		if ( $this->get_mode_value() == 'live' ) {
			// return 'sandbox-sq0idb-aNDJReIdhctf2o3OZA0FTA';
			return 'sq0idp-19QAyk7l68V7ymxc2Fl9EQ';
		}
		return $this->get_option( 'application_id_test' );
	}

	public function is_allowed() {
		if ( ! empty( $this->get_access_token() ) && ! empty( $this->get_application_id() ) ) {
			return true;
		}
		return false;
	}

	public function enqueue_scripts() {

		if ( ! is_sunshine_page( 'checkout' ) ) {
			return false;
		}

		if ( $this->get_mode_value() == 'live' ) {
			wp_enqueue_script( 'sunshine-square', 'https://web.squarecdn.com/v1/square.js' );
		} else {
			wp_enqueue_script( 'sunshine-square', 'https://sandbox.web.squarecdn.com/v1/square.js' );
		}
		wp_enqueue_script( 'sunshine-square-processing', SUNSHINE_PHOTO_CART_URL . '/assets/js/square-processing.js', array( 'jquery' ), SUNSHINE_PHOTO_CART_VERSION, true );
		wp_localize_script(
			'sunshine-square-processing',
			'spc_square_vars',
			array(
				'application_id' => $this->get_application_id(),
				'location_id'    => $this->get_location_id(),
				'currency'       => $this->currency,
				'total'          => SPC()->cart->get_total(), // Base currency for JavaScript SDK
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'security'       => wp_create_nonce( 'sunshine_square' ),
			)
		);

	}

	public function get_fields() {

		ob_start();

		if ( $this->get_mode_value() == 'test' ) {
			echo '<div class="sunshine--payment--test">' . esc_html__( 'This will be processed as a test payment and no real money will be exchanged', 'sunshine-photo-cart' ) . '</div>';
		}

		?>

		<div id="sunshine-square-payment">
			<div id="sunshine-square-payment-fields"></div>
			<div id="sunshine-square-payment-errors"></div>
		</div>

		<?php
		$output = ob_get_contents();
		ob_end_clean();
		return $output;

	}

	private function get_application_fee_percent() {
		return floatval( apply_filters( 'sunshine_square_application_fee_percent', 5 ) );
	}

	private function get_application_fee_amount() {

		$percentage = $this->get_application_fee_percent();

		// Some countries do not allow us to use application fees. If we are in one of those
		// countries, we should set the percentage to 0. This is a temporary fix until we
		// have a better solution or until all countries allow us to use application fees.
		$country                             = SPC()->get_option( 'country' );
		$countries_to_allow_application_fees = array(
			'US',
		);
		if ( ! in_array( $country, $countries_to_allow_application_fees ) ) {
			$percentage = 0;
		}

		if ( $percentage <= 0 ) {
			return 0;
		}

		$percentage = floatval( $percentage );

		return round( $this->total * ( $percentage / 100 ) );

	}

	public function get_idempotency_key( $key_input = '', $append_key_input = true ) {

		if ( '' === $key_input ) {
			$key_input = uniqid( '', false );
		}

		return substr( apply_filters( 'sunshine_square_idempotency_key', sha1( get_option( 'siteurl' ) . $key_input ) . ( $append_key_input ? ':' . $key_input : '' ) ), -40 );
	}

	private function api_request( $endpoint, $body = array(), $method = 'POST', $mode = '' ) {

		if ( empty( $mode ) ) {
			$mode = $this->get_mode_value();
		}

		$url  = trailingslashit( $this->environmentUrl ) . $endpoint;
		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_access_token( $mode ),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => ( ! empty( $body ) ) ? json_encode( $body ) : '',
			'timeout' => 45,
		);

		return wp_remote_request( $url, $args );

	}

	private function build_square_order_args( $order ) {

		$price_has_tax = SPC()->get_option( 'price_has_tax' ) === 'yes';
		$cart_tax_rate = SPC()->cart->get_tax_rate();
		$rate          = ! empty( $cart_tax_rate['rate'] ) ? floatval( $cart_tax_rate['rate'] ) : 0;

		$line_items = array();
		foreach ( $order->get_items() as $item ) {
			$qty      = max( 1, intval( $item->get_qty() ) );
			$discount = floatval( $item->get_discount() );

			if ( $price_has_tax && $item->is_taxable() ) {
				// Tax-inclusive mode: send the per-unit price the customer actually sees.
				// Square is told (via the taxes block below) that this tax is INCLUSIVE
				// so it does not add it again. Per-line discount is also in inclusive currency.
				$per_unit_inclusive = floatval( $item->get_price() ) + floatval( $item->get_tax() );
				$line_inclusive     = ( $per_unit_inclusive * $qty ) - $discount;
				$per_unit_for_square = $line_inclusive / $qty;
			} else {
				$per_unit_for_square = $item->get_total() / $qty;
			}

			$line_item = array(
				'name'             => substr( $item->get_name_raw(), 0, 512 ),
				'quantity'         => (string) $qty,
				'base_price_money' => array(
					'amount'   => intval( round( $per_unit_for_square * 100 ) ),
					'currency' => $this->currency,
				),
			);
			if ( $item->is_taxable() ) {
				$line_item['applied_taxes'] = array( array( 'tax_uid' => 'tax-1' ) );
			}
			$line_items[] = $line_item;
		}

		foreach ( (array) $order->get_fees() as $fee ) {
			if ( empty( $fee['amount'] ) ) {
				continue;
			}
			$line_items[] = array(
				'name'             => substr( $fee['name'], 0, 512 ),
				'quantity'         => '1',
				'base_price_money' => array(
					'amount'   => intval( round( $fee['amount'] * 100 ) ),
					'currency' => $this->currency,
				),
			);
		}

		$order_data = array(
			'location_id'  => $this->get_location_id(),
			'reference_id' => substr( (string) $order->get_order_number(), 0, 40 ),
			'line_items'   => $line_items,
		);

		if ( $order->get_shipping() > 0 ) {
			$shipping_name    = $order->get_shipping_method_name() ? $order->get_shipping_method_name() : __( 'Shipping', 'sunshine-photo-cart' );
			$shipping_taxable = ( $order->get_shipping_tax() > 0 );
			$shipping_amount  = floatval( $order->get_shipping() );
			if ( $price_has_tax && $shipping_taxable ) {
				// INCLUSIVE tax: send the inclusive shipping amount so it agrees
				// with the inclusive-priced line items above.
				$shipping_amount += floatval( $order->get_shipping_tax() );
			}
			$service_charge = array(
				'uid'                => 'shipping',
				'name'               => substr( $shipping_name, 0, 255 ),
				'amount_money'       => array(
					'amount'   => intval( round( $shipping_amount * 100 ) ),
					'currency' => $this->currency,
				),
				'calculation_phase'  => 'SUBTOTAL_PHASE',
				'taxable'            => $shipping_taxable,
			);
			if ( $shipping_taxable ) {
				$service_charge['applied_taxes'] = array( array( 'tax_uid' => 'tax-1' ) );
			}
			$order_data['service_charges'] = array( $service_charge );
		}

		if ( $order->get_tax() > 0 ) {
			$tax_rate = SPC()->cart->get_tax_rate();
			if ( ! empty( $tax_rate['rate'] ) ) {
				$order_data['taxes'] = array(
					array(
						'uid'        => 'tax-1',
						'name'       => __( 'Tax', 'sunshine-photo-cart' ),
						'percentage' => number_format( $tax_rate['rate'] * 100, 5, '.', '' ),
						'scope'      => 'LINE_ITEM',
						'type'       => $price_has_tax ? 'INCLUSIVE' : 'ADDITIVE',
					),
				);
			}
		}

		$discount_total = floatval( $order->get_discount() ) + floatval( $order->get_credits() );
		if ( $discount_total > 0 ) {
			$discount_names = $order->get_discount_names();
			$discount_label = ! empty( $discount_names )
				/* translators: %s is a comma-separated list of discount codes */
				? sprintf( __( 'Discount: %s', 'sunshine-photo-cart' ), implode( ', ', (array) $discount_names ) )
				: __( 'Discount', 'sunshine-photo-cart' );
			$order_data['discounts'] = array(
				array(
					'uid'          => 'discount-1',
					'name'         => substr( $discount_label, 0, 255 ),
					'amount_money' => array(
						'amount'   => intval( round( $discount_total * 100 ) ),
						'currency' => $this->currency,
					),
					'scope'        => 'ORDER',
				),
			);
		}

		return array( 'order' => $order_data );

	}

	private function cancel_square_order( $square_order_id, $version ) {
		if ( empty( $square_order_id ) ) {
			return;
		}
		$response = $this->api_request(
			'v2/orders/' . $square_order_id,
			array(
				'order'           => array(
					'version' => max( 1, intval( $version ) ),
					'state'   => 'CANCELED',
				),
				'idempotency_key' => substr( 'c_' . sha1( 'cancel:' . $square_order_id ), 0, 40 ),
			),
			'PUT'
		);
		if ( is_wp_error( $response ) ) {
			SPC()->log( 'Square Orders API: failed to cancel orphan order ' . $square_order_id . ': ' . $response->get_error_message() );
			return;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			SPC()->log( 'Square Orders API: cancelled orphan order ' . $square_order_id );
		} else {
			SPC()->log( 'Square Orders API: cancel returned HTTP ' . $code . ' for ' . $square_order_id . ' body: ' . wp_remote_retrieve_body( $response ) );
		}
	}

	/**
	 * Retrieve a Square Order and return the payment id of its first tender, if
	 * any. Used to recover a payment whose CreatePayment response was lost before
	 * we could store its id: the order holds a tender that references the payment.
	 * Returns '' when the order has no completed tender yet.
	 */
	private function get_payment_id_from_order( $square_order_id, $mode ) {
		if ( empty( $square_order_id ) || ! $this->get_access_token( $mode ) ) {
			return '';
		}
		$this->environmentUrl = ( $mode === 'live' ) ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
		$response = $this->api_request( 'v2/orders/' . $square_order_id, '', 'GET', $mode );
		if ( is_wp_error( $response ) ) {
			SPC()->log( 'get_payment_id_from_order: WP_Error ' . $response->get_error_message() );
			return '';
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['order']['tenders'] ) || ! is_array( $body['order']['tenders'] ) ) {
			return '';
		}
		foreach ( $body['order']['tenders'] as $tender ) {
			if ( ! empty( $tender['payment_id'] ) ) {
				return $tender['payment_id'];
			}
			if ( ! empty( $tender['id'] ) ) {
				return $tender['id'];
			}
		}
		return '';
	}

	public function init_order() {

		if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( $_POST['security'], 'sunshine_square' ) ) {
			wp_send_json_error( array( 'reasons' => __( 'Failed to pass security', 'sunshine-photo-cart' ) ) );
			return;
		}

		if ( empty( $_POST['source_id'] ) ) {
			wp_send_json_error( array( 'reasons' => __( 'No source ID', 'sunshine-photo-cart' ) ) );
			return;
		}

		$source_id = sanitize_text_field( $_POST['source_id'] );

		SPC()->log( 'Square init order source_id: ' . $source_id );

		$this->setup();

		$order_id = SPC()->session->get( 'checkout_order_id' );
		$order    = $order_id ? sunshine_get_order( $order_id ) : null;

		// If this order is already paid, do not charge again. This happens when a
		// prior attempt succeeded but the customer re-submitted (re-entered card,
		// double-click, refresh). Each card entry produces a new single-use Square
		// token, so charging again would either double-charge or trip Square's
		// idempotency-reuse error. Return success so the checkout flow continues to
		// the received page; process_order() bails on its own is_paid() guard.
		if ( $order && $order->exists() && $order->is_paid() ) {
			SPC()->log( 'Square init order: order ' . $order->get_id() . ' is already paid, skipping charge' );
			wp_send_json_success( array( 'payment_id' => $order->get_meta_value( 'square_payment_id' ) ) );
			return;
		}

		// Reuse the same idempotency key for every attempt on this order so
		// refreshes, network retries, and double-submits don't create duplicate
		// charges. Stored on the order itself (not the session) so the key is
		// tied to the order, not to the user's browser session. Cleared only
		// when Square reports a payment-method failure (declined card etc.)
		// so the customer can retry the same order with a different card.
		$idempotency_key = '';
		if ( $order && $order->exists() ) {
			$idempotency_key = $order->get_meta_value( 'square_idempotency_key' );
		}
		if ( empty( $idempotency_key ) ) {
			$idempotency_key = $this->get_idempotency_key( $source_id );
			if ( $order && $order->exists() ) {
				$order->update_meta_value( 'square_idempotency_key', $idempotency_key );
			}
		}

		$args = array(
			'source_id'       => $source_id,
			'idempotency_key' => $idempotency_key,
			'amount_money'    => array(
				'amount'   => $this->total,
				'currency' => $this->currency,
			),
			'autocomplete'    => true,
			'location_id'     => $this->get_location_id(),
		);

		if ( ! empty( $_POST['verification_token'] ) ) {
			$args['verification_token'] = sanitize_text_field( $_POST['verification_token'] );
		}

		if ( $order && $order->exists() ) {
			$args['reference_id'] = substr( (string) $order->get_order_number(), 0, 40 );
			$args['note']         = substr( $order->get_name(), 0, 500 );
		}

		$email = SPC()->cart->get_checkout_data_item( 'email' );
		if ( ! empty( $email ) ) {
			$args['buyer_email_address'] = $email;
		}

		$billing_address = array(
			'first_name'                      => SPC()->cart->get_checkout_data_item( 'first_name' ),
			'last_name'                       => SPC()->cart->get_checkout_data_item( 'last_name' ),
			'address_line_1'                  => SPC()->cart->get_checkout_data_item( 'billing_address1' ),
			'address_line_2'                  => SPC()->cart->get_checkout_data_item( 'billing_address2' ),
			'locality'                        => SPC()->cart->get_checkout_data_item( 'billing_city' ),
			'administrative_district_level_1' => SPC()->cart->get_checkout_data_item( 'billing_state' ),
			'postal_code'                     => SPC()->cart->get_checkout_data_item( 'billing_postcode' ),
			'country'                         => SPC()->cart->get_checkout_data_item( 'billing_country' ),
		);
		$billing_address = array_filter( $billing_address );
		if ( ! empty( $billing_address ) ) {
			$args['billing_address'] = $billing_address;
		}

		$app_fee = $this->get_application_fee_amount();
		if ( $app_fee ) {
			$args['app_fee_money'] = array(
				'amount'   => $app_fee,
				'currency' => $this->currency,
			);
		}

		$square_order_id = '';
		SPC()->log( 'Square Orders API: starting. order_id=' . ( $order_id ?: 'none' ) . ', $order exists=' . ( $order && $order->exists() ? 'yes' : 'no' ) );
		if ( $order && $order->exists() ) {
			$order_args = $this->build_square_order_args( $order );
			$order_args['idempotency_key'] = substr( 'o_' . sha1( 'order:' . $idempotency_key ), 0, 40 );

			SPC()->log( 'Square Orders API request payload: ' . wp_json_encode( $order_args ) );

			$order_response = $this->api_request( 'v2/orders', $order_args );
			if ( is_wp_error( $order_response ) ) {
				SPC()->log( 'Square Orders API: WP_Error - ' . $order_response->get_error_message() );
			} else {
				$response_code = wp_remote_retrieve_response_code( $order_response );
				$raw_body      = wp_remote_retrieve_body( $order_response );
				SPC()->log( 'Square Orders API: HTTP ' . $response_code . ' response body: ' . $raw_body );

				$order_body = json_decode( $raw_body, true );
				if ( ! empty( $order_body['errors'] ) ) {
					SPC()->log( 'Square Orders API: returned errors: ' . wp_json_encode( $order_body['errors'] ) );
				} elseif ( ! empty( $order_body['order']['id'] ) && isset( $order_body['order']['total_money']['amount'] ) ) {
					$square_total = intval( $order_body['order']['total_money']['amount'] );
					SPC()->log( sprintf( 'Square Orders API: created order %s, square_total=%d, sunshine_total=%d, match=%s', $order_body['order']['id'], $square_total, $this->total, ( $square_total === intval( $this->total ) ? 'yes' : 'NO' ) ) );
					if ( $square_total === intval( $this->total ) ) {
						$square_order_id  = $order_body['order']['id'];
						$args['order_id'] = $square_order_id;
					} else {
						// Cancel the orphan Square Order so the merchant does not see
						// an OPEN unpaid order with the wrong total sitting in their
						// dashboard. The fallback bare payment still goes through.
						$this->cancel_square_order( $order_body['order']['id'], intval( $order_body['order']['version'] ?? 1 ) );
					}
				} else {
					SPC()->log( 'Square Orders API: missing order.id or total_money in response' );
				}
			}
		}

		$response = $this->api_request( 'v2/payments', $args );

		if ( is_wp_error( $response ) ) {
			SPC()->log( 'Failed Square payment request: ' . print_r( $response, 1 ) );
			wp_send_json_error( array( 'reasons' => $response->get_error_message() ) );
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['errors'] ) ) {
			SPC()->log( 'Failed Square payment body: ' . print_r( $body, 1 ) );

			$is_payment_method_error = false;
			$is_idempotency_reuse    = false;
			foreach ( $body['errors'] as $error ) {
				$category = isset( $error['category'] ) ? $error['category'] : '';
				$code     = isset( $error['code'] ) ? $error['code'] : '';
				if ( $category === 'PAYMENT_METHOD_ERROR' ) {
					$is_payment_method_error = true;
				}
				if ( $code === 'IDEMPOTENCY_KEY_REUSED' ) {
					$is_idempotency_reuse = true;
				}
			}

			// Declined card etc. - clear the stored key so the customer can retry
			// with another card on a fresh key.
			if ( $is_payment_method_error && $order && $order->exists() ) {
				$order->update_meta_value( 'square_idempotency_key', '' );
			}

			// Idempotency reuse: the customer re-entered their card (Square issues a
			// new single-use token each time), so this request carried the stored key
			// from a prior attempt but a different source_id, and Square rejected the
			// mismatch. A prior attempt already exists under that key - recover it
			// rather than charging again or showing the raw Square error.
			if ( $is_idempotency_reuse && $order && $order->exists() ) {
				SPC()->log( 'Square payment: IDEMPOTENCY_KEY_REUSED for order ' . $order->get_id() . ', attempting recovery' );

				$known_payment_id = $order->get_meta_value( 'square_payment_id' );

				// If we never recorded a payment id, the first attempt's payment may
				// have succeeded but its response was lost before we could store it.
				// The Square Order created earlier in THIS request is the same one the
				// first attempt created (the order idempotency key is deterministic),
				// so pull the payment id off its tender and recover from that.
				if ( empty( $known_payment_id ) && ! empty( $square_order_id ) ) {
					$recovered_payment_id = $this->get_payment_id_from_order( $square_order_id, $order->get_mode() );
					if ( $recovered_payment_id ) {
						$order->update_meta_value( 'square_payment_id', $recovered_payment_id );
						$known_payment_id = $recovered_payment_id;
						SPC()->log( 'Square payment: recovered payment id ' . $recovered_payment_id . ' from Square order ' . $square_order_id );
					}
				}

				if ( $known_payment_id ) {
					$result = $this->reconcile_order( $order, array( 'force' => true ) );
					if ( ! empty( $result['action'] ) && $result['action'] === 'finalized' ) {
						wp_send_json_success( array( 'payment_id' => $order->get_meta_value( 'square_payment_id' ) ) );
						return;
					}
				}
				wp_send_json_error(
					array(
						'reasons' => __( 'Your payment was already submitted and is being verified. Please refresh this page in a moment to continue - you do not need to re-enter your card.', 'sunshine-photo-cart' ),
					)
				);
				return;
			}

			wp_send_json_error( array( 'reasons' => $body['errors'][0]['detail'] ) );
			return;
		}

		if ( empty( $body['payment'] ) ) {
			SPC()->log( 'Failed Square payment request, no payment: ' . print_r( $body, 1 ) );
			wp_send_json_error( array( 'reasons' => 'No payment' ) );
			return;
		}

		$payment        = $body['payment'];
		$payment_status = isset( $payment['status'] ) ? strtoupper( $payment['status'] ) : '';

		// PENDING / APPROVED / FAILED / CANCELED handling. Square's autocomplete=true
		// typically yields COMPLETED, but the API can return non-final states. Without
		// this branch the gateway used to forward every status as success and the
		// order would mark itself paid based on a non-final payment.
		if ( $payment_status === 'FAILED' || $payment_status === 'CANCELED' ) {
			SPC()->log( 'Square payment ' . $payment['id'] . ' returned status ' . $payment_status );
			if ( $order && $order->exists() ) {
				$reason = '';
				if ( ! empty( $payment['failure_reason'] ) ) {
					$reason = $payment['failure_reason'];
				} elseif ( ! empty( $payment['delay_reason'] ) ) {
					$reason = $payment['delay_reason'];
				}
				$order->update_meta_value( 'square_idempotency_key', '' );
				/* translators: %1$s: Square payment ID, %2$s: payment status */
				$log_msg = sprintf( __( 'Square reports payment %1$s as %2$s', 'sunshine-photo-cart' ), $payment['id'], $payment_status );
				if ( $reason ) {
					$log_msg .= ': ' . $reason;
				}
				$order->set_status( 'failed', $log_msg );
			}
			$reason_text = ! empty( $payment['failure_reason'] ) ? $payment['failure_reason'] : __( 'Payment could not be completed', 'sunshine-photo-cart' );
			wp_send_json_error( array( 'reasons' => $reason_text ) );
			return;
		}

		if ( $payment_status !== 'COMPLETED' && $payment_status !== 'APPROVED' ) {
			// PENDING or unknown - store the payment id so the recovery paths can
			// finish the order when Square eventually resolves it, but do not let
			// the form post. Keep the idempotency key so retries land on the same
			// Square payment instead of creating a parallel one.
			SPC()->log( 'Square payment ' . $payment['id'] . ' returned non-final status: ' . $payment_status );
			if ( $order && $order->exists() ) {
				$this->store_payment_meta( $order, $payment, $square_order_id );
				/* translators: %1$s: Square payment ID, %2$s: payment status */
				$order->add_log( sprintf( __( 'Square payment %1$s returned status %2$s - waiting for completion', 'sunshine-photo-cart' ), $payment['id'], $payment_status ?: 'unknown' ) );
			}
			wp_send_json_error(
				array(
					'reasons' => __( 'Your payment is still being verified by Square. Please wait a moment and refresh the page to continue.', 'sunshine-photo-cart' ),
				)
			);
			return;
		}

		if ( $payment_status === 'APPROVED' ) {
			SPC()->log( 'Square payment ' . $payment['id'] . ' returned APPROVED instead of COMPLETED - forwarding as success since the customer was charged' );
		} else {
			SPC()->log( 'Square successful payment' );
		}

		if ( $order && $order->exists() ) {
			$this->store_payment_meta( $order, $payment, $square_order_id );
		}

		wp_send_json_success( array( 'payment_id' => $payment['id'] ) );

	}

	public function process_payment( $order ) {
		$payment_id = isset( $_POST['square_payment_id'] ) ? sanitize_text_field( $_POST['square_payment_id'] ) : '';
		if ( ! empty( $payment_id ) && empty( $order->get_meta_value( 'square_payment_id' ) ) ) {
			$order->update_meta_value( 'square_payment_id', $payment_id );
		}
	}

	public function create_order_status( $status, $order ) {
		if ( $order->get_payment_method() == $this->id ) {
			SPC()->log( 'Setting order status to new for Square payment' );
			return 'new'; // Straight to new.
		}
		return $status;
	}

	private function store_payment_meta( $order, $payment, $square_order_id = '' ) {
		if ( ! $order || ! $order->exists() || empty( $payment['id'] ) ) {
			return;
		}
		$order->update_meta_value( 'square_payment_id', $payment['id'] );
		if ( ! empty( $square_order_id ) ) {
			$order->update_meta_value( 'square_order_id', $square_order_id );
		}
		if ( ! empty( $payment['app_fee_money']['amount'] ) ) {
			$order->update_meta_value( 'square_app_fee', $payment['app_fee_money']['amount'] / 100 );
		}
		if ( ! empty( $payment['processing_fee'] ) ) {
			$processing_fee_total = 0;
			foreach ( $payment['processing_fee'] as $fee_entry ) {
				if ( isset( $fee_entry['amount_money']['amount'] ) ) {
					$processing_fee_total += intval( $fee_entry['amount_money']['amount'] );
				}
			}
			if ( $processing_fee_total > 0 ) {
				$order->update_meta_value( 'square_processing_fee', $processing_fee_total / 100 );
			}
		}
		if ( ! empty( $payment['card_details']['card'] ) ) {
			$card = $payment['card_details']['card'];
			if ( ! empty( $card['last_4'] ) ) {
				$order->update_meta_value( 'square_card_last_4', $card['last_4'] );
			}
			if ( ! empty( $card['card_brand'] ) ) {
				$order->update_meta_value( 'square_card_brand', $card['card_brand'] );
			}
			if ( ! empty( $card['exp_month'] ) && ! empty( $card['exp_year'] ) ) {
				$order->update_meta_value( 'square_card_expiry', $card['exp_month'] . '/' . $card['exp_year'] );
			}
		}
	}

	public function check_order_paid() {

		if ( ! is_sunshine_page( 'checkout' ) ) {
			return;
		}

		$order_id = SPC()->session->get( 'checkout_order_id' );
		if ( empty( $order_id ) ) {
			return;
		}

		$order = sunshine_get_order( $order_id );
		if ( ! $order || ! $order->exists() ) {
			return;
		}
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}
		if ( $order->is_paid() ) {
			return;
		}
		if ( empty( $order->get_meta_value( 'square_payment_id' ) ) ) {
			return;
		}

		// Burn the 3-attempt retry only inside the fresh-charge window where Square may
		// still be transitioning APPROVED -> COMPLETED. Outside that window a single
		// shot is enough; the throttle inside reconcile_order prevents reload-loop hammering.
		$post_time   = (int) get_post_time( 'U', true, $order->get_id() );
		$age_seconds = $post_time ? ( time() - $post_time ) : 0;
		$retry       = ( $age_seconds <= 60 ) ? 3 : 1;

		$result = $this->reconcile_order( $order, array( 'retry' => $retry ) );

		if ( ! empty( $result['action'] ) && $result['action'] === 'finalized' ) {
			wp_safe_redirect( $order->get_received_permalink() );
			exit;
		}
	}

	public function add_cron_schedule( $schedules ) {
		if ( ! isset( $schedules['sunshine_square_quarter_hour'] ) ) {
			$schedules['sunshine_square_quarter_hour'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes', 'sunshine-photo-cart' ),
			);
		}
		return $schedules;
	}

	/**
	 * Safety-net cron: re-check recent orders still stuck in pending against
	 * Square, in case the synchronous checkout flow never finalized them (e.g.
	 * the customer was charged then closed the browser before the page returned).
	 * This NEVER touches a healthy order - those are already paid and excluded
	 * from the query, and reconcile_order() bails on its is_paid() guard anyway.
	 */
	public function reconcile_pending_orders() {

		if ( ! apply_filters( 'sunshine_square_background_reconcile', true ) ) {
			return;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'sunshine-order',
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'after' => '3 days ago',
					),
				),
				'tax_query'      => array(
					array(
						'taxonomy' => 'sunshine-order-status',
						'field'    => 'slug',
						'terms'    => 'pending',
					),
				),
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => 'payment_method',
						'value' => $this->id,
					),
					array(
						'key'     => 'square_payment_id',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return;
		}

		SPC()->log( 'Square background reconcile: checking ' . count( $query->posts ) . ' pending order(s)' );

		$finalized = 0;
		foreach ( $query->posts as $order_id ) {
			$order = sunshine_get_order( $order_id );
			if ( ! $order || ! $order->exists() ) {
				continue;
			}
			$result = $this->reconcile_order( $order );
			if ( ! empty( $result['action'] ) && $result['action'] === 'finalized' ) {
				++$finalized;
			}
		}

		SPC()->log( 'Square background reconcile: finalized ' . $finalized . ' order(s)' );
	}

	public function reconcile_order( $order, $args = array() ) {

		$defaults = array(
			'retry' => 1,
			'force' => false,
		);
		$args     = wp_parse_args( $args, $defaults );
		$retry    = max( 1, intval( $args['retry'] ) );
		$force    = ! empty( $args['force'] );

		if ( ! $order || ! $order->exists() ) {
			return array( 'ok' => false, 'action' => 'invalid', 'error' => 'Order missing' );
		}

		if ( $order->get_payment_method() !== $this->id ) {
			return array( 'ok' => false, 'action' => 'invalid', 'error' => 'Not a Square order' );
		}

		if ( $order->is_paid() ) {
			return array( 'ok' => true, 'action' => 'already_paid' );
		}

		$payment_id = $order->get_meta_value( 'square_payment_id' );
		if ( empty( $payment_id ) ) {
			return array( 'ok' => false, 'action' => 'invalid', 'error' => 'No square_payment_id on order' );
		}

		if ( ! $force ) {
			$last_check = (int) $order->get_meta_value( 'square_last_reconcile_check' );
			if ( $last_check && ( current_time( 'timestamp' ) - $last_check ) < 30 ) {
				return array( 'ok' => true, 'action' => 'throttled' );
			}
		}

		$lock_key = 'spc_square_reconcile_' . $payment_id;
		if ( get_transient( $lock_key ) ) {
			return array( 'ok' => true, 'action' => 'in_progress' );
		}
		set_transient( $lock_key, 1, 30 );

		$mode = $order->get_mode();
		if ( ! $this->get_access_token( $mode ) ) {
			delete_transient( $lock_key );
			return array( 'ok' => false, 'action' => 'invalid', 'error' => 'No Square access token for mode ' . $mode );
		}
		$this->environmentUrl = ( $mode === 'live' ) ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';

		$payment = null;
		for ( $attempt = 1; $attempt <= $retry; $attempt++ ) {
			$response = $this->api_request( 'v2/payments/' . $payment_id, '', 'GET', $mode );
			if ( is_wp_error( $response ) ) {
				SPC()->log( 'reconcile_order: Square API WP_Error for ' . $payment_id . ': ' . $response->get_error_message() );
				delete_transient( $lock_key );
				return array( 'ok' => false, 'action' => 'error', 'error' => $response->get_error_message() );
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $body['payment']['status'] ) ) {
				$payment = $body['payment'];
				if ( strtoupper( $payment['status'] ) === 'COMPLETED' ) {
					break;
				}
			}
			if ( $attempt < $retry ) {
				sleep( 1 );
			}
		}

		$order->update_meta_value( 'square_last_reconcile_check', current_time( 'timestamp' ) );

		if ( empty( $payment ) ) {
			delete_transient( $lock_key );
			SPC()->log( 'reconcile_order: empty payment response for ' . $payment_id );
			return array( 'ok' => false, 'action' => 'error', 'error' => 'Empty Square response' );
		}

		$status = strtoupper( $payment['status'] );

		if ( $status === 'COMPLETED' ) {
			$square_order_id = ! empty( $payment['order_id'] ) ? $payment['order_id'] : '';
			$this->store_payment_meta( $order, $payment, $square_order_id );
			/* translators: %s: Square payment ID */
			$order->add_log( sprintf( __( 'Square payment %s reconciled as COMPLETED', 'sunshine-photo-cart' ), $payment_id ) );
			SPC()->log( 'reconcile_order: finalizing order ' . $order->get_id() . ' for Square payment ' . $payment_id );
			SPC()->cart->post_process_order( $order );
			$order->update_meta_value( 'paid_date', current_time( 'timestamp' ) );
			delete_transient( $lock_key );
			return array( 'ok' => true, 'action' => 'finalized' );
		}

		if ( $status === 'FAILED' || $status === 'CANCELED' ) {
			$reason = '';
			if ( ! empty( $payment['failure_reason'] ) ) {
				$reason = $payment['failure_reason'];
			} elseif ( ! empty( $payment['delay_reason'] ) ) {
				$reason = $payment['delay_reason'];
			}
			/* translators: %1$s: Square payment ID, %2$s: payment status */
			$log_msg = sprintf( __( 'Square reports payment %1$s as %2$s', 'sunshine-photo-cart' ), $payment_id, $status );
			if ( $reason ) {
				$log_msg .= ': ' . $reason;
			}
			$order->set_status( 'failed', $log_msg );
			$order->update_meta_value( 'square_idempotency_key', '' );
			delete_transient( $lock_key );
			return array( 'ok' => true, 'action' => 'marked_failed', 'error' => $reason );
		}

		// APPROVED, PENDING, or anything else - leave alone, recovery will try again later.
		delete_transient( $lock_key );
		return array( 'ok' => true, 'action' => 'still_pending', 'error' => $status );
	}

	public function get_transaction_id( $order ) {
		return $order->get_meta_value( 'square_payment_id' );
	}

	public function get_transaction_url( $order ) {
		if ( $order->get_payment_method() == 'square' ) {
			$transaction_id = $this->get_transaction_id( $order );
			if ( $transaction_id ) {
				$mode             = $order->get_mode();
				$transaction_url  = ( $mode == 'test' ) ? 'https://squareupsandbox.com/dashboard/sales/transactions/' : 'https://squareup.com/dashboard/sales/transactions/';
				$transaction_url .= $transaction_id;
				return $transaction_url;
			}
		}
		return false;
	}

	public function get_app_fee( $order ) {
		return $order->get_meta_value( 'square_app_fee' );
	}

	public function admin_order_tab( $tabs, $order ) {
		if ( $order->get_payment_method() == $this->id ) {
			$tabs['square'] = __( 'Square', 'sunshine-photo-cart' );
		}
		return $tabs;
	}

	public function admin_order_tab_content_square( $order ) {

		echo '<table class="sunshine-data">';
		echo '<tr><th>' . esc_html__( 'Transaction ID', 'sunshine-photo-cart' ) . '</th>';
		echo '<td>' . esc_html( $this->get_transaction_id( $order ) ) . '</td></tr>';

		$square_order_id = $order->get_meta_value( 'square_order_id' );
		if ( $square_order_id ) {
			echo '<tr><th>' . esc_html__( 'Square Order ID', 'sunshine-photo-cart' ) . '</th>';
			echo '<td>' . esc_html( $square_order_id ) . '</td></tr>';
		}

		$processing_fee = $order->get_meta_value( 'square_processing_fee' );
		echo '<tr><th>' . esc_html__( 'Square Processing Fee', 'sunshine-photo-cart' ) . '</th>';
		echo '<td>';
		if ( $processing_fee ) {
			echo wp_kses_post( sunshine_price( $processing_fee ) );
		} else {
			echo '<em>' . esc_html__( 'Not yet available - Square calculates fees at settlement. ', 'sunshine-photo-cart' ) . '</em>';
			echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'post.php?post=' . $order->get_id() . '&action=edit&sunshine_square_refresh_fee=1' ), 'sunshine_square_refresh_fee_' . $order->get_id() ) ) . '">' . esc_html__( 'Refresh from Square', 'sunshine-photo-cart' ) . '</a>';
		}
		echo '</td></tr>';

		$card_brand  = $order->get_meta_value( 'square_card_brand' );
		$card_last_4 = $order->get_meta_value( 'square_card_last_4' );
		if ( $card_brand || $card_last_4 ) {
			echo '<tr><th>' . esc_html__( 'Card', 'sunshine-photo-cart' ) . '</th>';
			echo '<td>';
			if ( $card_brand ) {
				echo esc_html( $card_brand );
			}
			if ( $card_last_4 ) {
				echo ' &bull;&bull;&bull;&bull; ' . esc_html( $card_last_4 );
			}
			$card_expiry = $order->get_meta_value( 'square_card_expiry' );
			if ( $card_expiry ) {
				echo ' (' . esc_html__( 'exp.', 'sunshine-photo-cart' ) . ' ' . esc_html( $card_expiry ) . ')';
			}
			echo '</td></tr>';
		}

		$application_fee_amount = $this->get_app_fee( $order );
		if ( $application_fee_amount ) {
			echo '<tr>';
			echo '<th>' . esc_html__( 'Application Fee Amount (To Sunshine)', 'sunshine-photo-cart' ) . '</th>';
			echo '<td>' . wp_kses_post( sunshine_price( $application_fee_amount ) ) . ' (<a href="https://www.sunshinephotocart.com/upgrade/?utm_source=plugin&utm_medium=link&utm_campaign=stripe" target="_blank">' . esc_html__( 'Upgrade to remove this fee on future transactions', 'sunshine-photo-cart' ) . '</a>)' . '</td>';
			echo '</tr>';
		}

		echo '</table>';

	}

	function order_actions( $actions, $post_id ) {
		$order = new SPC_Order( $post_id );
		if ( $order->get_payment_method() == $this->id ) {
			// Only offer recheck for orders still awaiting payment that have a
			// Square payment id to look up. Paid orders have nothing to recover.
			if ( ! $order->is_paid() && $order->get_meta_value( 'square_payment_id' ) ) {
				$actions['square_recheck'] = __( 'Recheck payment status in Square', 'sunshine-photo-cart' );
			}
			$actions['square_refund'] = __( 'Refund payment in Square', 'sunshine-photo-cart' );
		}
		return $actions;
	}

	function order_actions_options( $order ) {
		?>
		<div id="square-refund-order-actions" style="display: none;">
			<p><label><input type="checkbox" name="square_refund_notify" value="yes" checked="checked" /> <?php esc_html_e( 'Notify customer via email', 'sunshine-photo-cart' ); ?></label></p>
			<p><label><input type="checkbox" name="square_refund_full" value="yes" checked="checked" /> <?php esc_html_e( 'Full refund', 'sunshine-photo-cart' ); ?></label></p>
			<p id="square-refund-amount" style="display: none;"><label><input type="number" name="square_refund_amount" step=".01" size="6" style="width:100px" max="<?php echo esc_attr( $order->get_total_minus_refunds() ); ?>" value="<?php echo esc_attr( $order->get_total_minus_refunds() ); ?>" /> <?php esc_html_e( 'Amount to refund', 'sunshine-photo-cart' ); ?></label></p>
		</div>
		<script>
			jQuery( 'select[name="sunshine_order_action"]' ).on( 'change', function(){
				let selected_action = jQuery( 'option:selected', this ).val();
				if ( selected_action == 'square_refund' ) {
					jQuery( '#square-refund-order-actions' ).show();
				} else {
					jQuery( '#square-refund-order-actions' ).hide();
				}
			});
			jQuery( 'input[name="square_refund_full"]' ).on( 'change', function(){
				if ( !jQuery(this).prop( "checked" ) ) {
					jQuery( '#square-refund-amount' ).show();
				} else {
					jQuery( '#square-refund-amount' ).hide();
				}
			});
		</script>
		<?php
	}

	function process_refund( $order_id ) {

		$order = new SPC_Order( $order_id );

		$refund_amount = $order->get_total_minus_refunds();

		if ( ! empty( $_POST['square_refund_amount'] ) && $_POST['square_refund_amount'] < $refund_amount ) {
			$refund_amount = sanitize_text_field( $_POST['square_refund_amount'] );
		}

		$refund_amount_square = intval( $refund_amount * 100 ); // Lose decimals because Square

		$this->setup( $order->get_mode() );

		$payment_id = $this->get_transaction_id( $order );

		// Refunds run against the mode the order was placed in. If there are no
		// Square credentials for that mode (e.g. the order was placed in a different
		// mode than is currently connected), fail clearly instead of letting the
		// request go out with an empty environment URL.
		if ( ! $this->get_access_token( $order->get_mode() ) ) {
			/* translators: %s is the order's Square mode (live or test) */
			$msg = sprintf( __( 'Could not refund payment: no Square credentials are connected for this order\'s mode (%s). This order may have been placed under a different Square connection.', 'sunshine-photo-cart' ), $order->get_mode() );
			SPC()->log( 'Square refund aborted for order ' . $order_id . ': ' . $msg );
			SPC()->notices->delete_admin( 'square_refund_fail_' . $payment_id );
			SPC()->notices->add_admin( 'square_refund_fail_' . $payment_id, $msg, 'error' );
			return;
		}

		$args = array(
			'idempotency_key' => md5( time() . $payment_id ),
			'payment_id'      => $payment_id,
			'amount_money'    => array(
				'amount'   => $refund_amount_square,
				'currency' => $order->get_currency(),
			),
		);

		$app_fee = $this->get_app_fee( $order );
		if ( $app_fee ) {
			$refund_percent        = $refund_amount / $order->get_total_minus_refunds();
			$refund_app_fee        = ( $app_fee * $refund_percent ) * 100;
			$args['app_fee_money'] = array(
				'amount'   => intval( $refund_app_fee ),
				'currency' => $order->get_currency(),
			);
		}

		$fail_key = 'square_refund_fail_' . $payment_id;
		// Clear any prior failure notice for this payment so a fresh attempt always
		// surfaces its result (admin notices dedupe by key and skip when dismissed).
		SPC()->notices->delete_admin( $fail_key );

		SPC()->log( 'Square refund request for payment ' . $payment_id . ': ' . wp_json_encode( $args ) );

		$response = $this->api_request( 'v2/refunds', $args );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			SPC()->log( 'Square refund WP_Error for payment ' . $payment_id . ': ' . $error_message );
			/* translators: %s is the error message */
			SPC()->notices->add_admin( $fail_key, sprintf( __( 'Could not refund payment: %s', 'sunshine-photo-cart' ), $error_message ), 'error' );
			return;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$raw_body      = wp_remote_retrieve_body( $response );
		SPC()->log( 'Square refund response (HTTP ' . $response_code . ') for payment ' . $payment_id . ': ' . $raw_body );

		$body = json_decode( $raw_body, true );
		if ( ! empty( $body['errors'] ) ) {
			$detail = ! empty( $body['errors'][0]['detail'] ) ? $body['errors'][0]['detail'] : ( ! empty( $body['errors'][0]['code'] ) ? $body['errors'][0]['code'] : __( 'Unknown error', 'sunshine-photo-cart' ) );
			SPC()->log( 'Square refund errors for payment ' . $payment_id . ': ' . wp_json_encode( $body['errors'] ) );
			/* translators: %s is the error detail message */
			SPC()->notices->add_admin( $fail_key, sprintf( __( 'Could not refund payment: %s', 'sunshine-photo-cart' ), $detail ), 'error' );
			return;
		}

		if ( empty( $body['refund'] ) ) {
			SPC()->log( 'Square refund: no refund object in response for payment ' . $payment_id . ': ' . $raw_body );
			SPC()->notices->add_admin( $fail_key, __( 'Could not refund payment: Square did not return a refund', 'sunshine-photo-cart' ), 'error' );
			return;
		}

		$order->set_status( 'refunded' );
		$order->add_refund( $refund_amount );
		$order->update();

		if ( ! empty( $refund_app_fee ) ) {
			/* translators: %s is the refund amount formatted as price */
			$order->add_log( sprintf( __( 'Sunshine contributed %s towards refund from processing fees', 'sunshine-photo-cart' ), sunshine_price( $refund_app_fee / 100 ) ) );
		}

		/* translators: %s is the refund amount formatted as price */
		SPC()->notices->add_admin( 'square_refund_success_' . $payment_id, sprintf( __( 'Refund has been processed for %s', 'sunshine-photo-cart' ), sunshine_price( $refund_amount ) ) );

		if ( ! empty( $_POST['square_refund_notify'] ) ) {
			$order->notify( false );
			SPC()->notices->add_admin( 'square_refund_notify_' . $payment_id, __( 'Customer sent email about refund', 'sunshine-photo-cart' ) );
		}

	}

	public function process_recheck( $order_id ) {

		$order = new SPC_Order( $order_id );
		if ( ! $order || ! $order->exists() ) {
			return;
		}

		$payment_id = $order->get_meta_value( 'square_payment_id' );

		$result = $this->reconcile_order( $order, array( 'force' => true ) );
		$action = isset( $result['action'] ) ? $result['action'] : 'unknown';
		$key    = 'square_recheck_' . $payment_id;

		switch ( $action ) {
			case 'finalized':
				SPC()->notices->add_admin( $key, __( 'Square confirmed this payment was completed. The order has been finalized.', 'sunshine-photo-cart' ), 'success' );
				break;
			case 'already_paid':
				SPC()->notices->add_admin( $key, __( 'This order is already marked as paid.', 'sunshine-photo-cart' ) );
				break;
			case 'marked_failed':
				$reason = ! empty( $result['error'] ) ? ' (' . $result['error'] . ')' : '';
				/* translators: %s is the Square failure reason, if any */
				SPC()->notices->add_admin( $key, sprintf( __( 'Square reports this payment failed or was canceled%s. The order has been marked as failed.', 'sunshine-photo-cart' ), $reason ), 'error' );
				break;
			case 'still_pending':
				$status = ! empty( $result['error'] ) ? $result['error'] : 'PENDING';
				/* translators: %s is the Square payment status */
				SPC()->notices->add_admin( $key, sprintf( __( 'Square still reports this payment as %s. The order remains pending. Try again in a moment.', 'sunshine-photo-cart' ), $status ), 'warning' );
				break;
			case 'in_progress':
				SPC()->notices->add_admin( $key, __( 'A recheck for this payment is already running. Please wait a moment and try again.', 'sunshine-photo-cart' ), 'warning' );
				break;
			default:
				$error = ! empty( $result['error'] ) ? $result['error'] : __( 'Could not reach Square', 'sunshine-photo-cart' );
				/* translators: %s is the error message */
				SPC()->notices->add_admin( $key, sprintf( __( 'Could not recheck this payment: %s', 'sunshine-photo-cart' ), $error ), 'error' );
				break;
		}

	}

	public function mode( $mode, $order ) {
		if ( $order->get_payment_method() == 'square' ) {
			return $this->get_mode_value();
		}
		return $mode;
	}

	public function checkout_validation( $section ) {
		if ( $section == 'payment' && SPC()->cart->get_total() > 0 && SPC()->cart->get_checkout_data_item( 'payment_method' ) == 'square' ) {
			if ( empty( $_POST['square_payment_id'] ) ) {
				SPC()->cart->add_error( __( 'Invalid payment', 'sunshine-photo-cart' ) );
			}
		}
	}

}
