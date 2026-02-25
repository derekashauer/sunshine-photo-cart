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

		if ( ! $this->is_active() || ! $this->is_allowed() ) {
			return;
		}

		add_action( 'sunshine_square_access_token_refresh', array( $this, 'refresh_token' ) );

		add_action( 'wp', array( $this, 'init_setup' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		// add_filter( 'sunshine_checkout_field_payment_method_square', array( $this, 'buttons' ) );

		add_action( 'wp_ajax_nopriv_sunshine_square_init_order', array( $this, 'init_order' ) );
		add_action( 'wp_ajax_sunshine_square_init_order', array( $this, 'init_order' ) );

		add_action( 'sunshine_checkout_process_payment_square', array( $this, 'process_payment' ) );

		// add_action( 'template_redirect', array( $this, 'square_return_listener' ), 999 );
		// add_action( 'template_redirect', array( $this, 'webhooks' ) );

		// add_filter( 'sunshine_order_transaction_url', array( $this, 'transaction_url' ) );

		add_filter( 'sunshine_admin_order_tabs', array( $this, 'admin_order_tab' ), 10, 2 );
		add_action( 'sunshine_admin_order_tab_square', array( $this, 'admin_order_tab_content_square' ) );

		add_action( 'sunshine_order_actions', array( $this, 'order_actions' ), 10, 2 );
		add_action( 'sunshine_order_actions_options', array( $this, 'order_actions_options' ) );
		add_action( 'sunshine_order_process_action_square_refund', array( $this, 'process_refund' ) );

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

	public function init_order() {

		if ( empty( $_POST['security'] ) ) {
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

		$args = array(
			'source_id'       => $source_id,
			'idempotency_key' => $this->get_idempotency_key( $source_id ),
			'amount_money'    => array(
				'amount'   => $this->total,
				'currency' => $this->currency,
			),
			'autocomplete'    => true,
			'location_id'     => $this->get_location_id(),
		);

		$app_fee = $this->get_application_fee_amount();
		if ( $app_fee ) {
			$args['app_fee_money'] = array(
				'amount'   => $app_fee,
				'currency' => $this->currency,
			);
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
			wp_send_json_error( array( 'reasons' => $body['errors'][0]['detail'] ) );
			return;
		}

		if ( empty( $body['payment'] ) ) {
			SPC()->log( 'Failed Square payment request, no payment: ' . print_r( $body, 1 ) );
			wp_send_json_error( array( 'reasons' => 'No payment' ) );
			return;
		}

		SPC()->log( 'Square successful payment' );

		$payment = $body['payment'];
		wp_send_json_success( array( 'payment_id' => $payment['id'] ) );

	}

	public function process_payment( $order ) {

		$this->setup();

		$payment_id = sanitize_text_field( $_POST['square_payment_id'] );

		$order->add_meta_value( 'square_payment_id', $payment_id );

		$response = $this->api_request( 'v2/payments/' . $payment_id, '', 'GET' );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'reasons' => $response->get_error_message() ) );
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['errors'] ) ) {
			return;
		}

		if ( empty( $body['payment'] ) ) {
			return;
		}

		$payment = $body['payment'];
		if ( ! empty( $payment['app_fee_money'] ) ) {
			$order->add_meta_value( 'square_app_fee', $payment['app_fee_money']['amount'] / 100 );
		}

	}

	public function create_order_status( $status, $order ) {
		if ( $order->get_payment_method() == $this->id ) {
			SPC()->log( 'Setting order status to new for Square payment' );
			return 'new'; // Straight to new.
		}
		return $status;
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

		$response = $this->api_request( 'v2/refunds', $args );

		if ( is_wp_error( $response ) ) {
			/* translators: %s is the error reasons */
			SPC()->notices->add_admin( 'square_refund_fail_' . $payment_id, sprintf( __( 'Could not refund payment: %s', 'sunshine-photo-cart' ), print_r( $reasons, 1 ) ), 'error' );
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['errors'] ) ) {
			/* translators: %s is the error detail message */
			SPC()->notices->add_admin( 'square_refund_fail_' . $payment_id, sprintf( __( 'Could not refund payment: %s', 'sunshine-photo-cart' ), $body['errors'][0]['detail'] ), 'error' );
			return;
		}

		if ( empty( $body['refund'] ) ) {
			SPC()->notices->add_admin( 'square_refund_fail_' . $payment_id, __( 'Could not refund payment', 'sunshine-photo-cart' ), 'error' );
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
