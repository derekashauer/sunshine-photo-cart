<?php
/**
 * Stripe Payment Method Class
 *
 * Handles Stripe payment processing including payment intents, webhooks, and refunds.
 *
 * @package Sunshine_Photo_Cart
 * @subpackage Payment_Methods
 */
class SPC_Payment_Method_Stripe extends SPC_Payment_Method {

	/**
	 * Stripe API instance for interacting with Stripe API
	 *
	 * @var object
	 */
	private $stripe;

	/**
	 * Extra metadata for orders
	 *
	 * @var array
	 */
	private $extra_meta_data = array();

	/**
	 * Stripe mode (live or test)
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Stripe publishable key
	 *
	 * @var string
	 */
	private $publishable_key;

	/**
	 * Stripe secret key
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Currency code
	 *
	 * @var string
	 */
	private $currency;

	/**
	 * Stripe payment intent ID
	 *
	 * @var string
	 */
	private $payment_intent_id;

	/**
	 * Stripe client secret for payment intent
	 *
	 * @var string
	 */
	private $client_secret;

	/**
	 * Stripe API version to pin for consistent behavior across all accounts.
	 *
	 * Using 2022-08-01 because:
	 * - HUF/ISK/TWD became zero-decimal for charges in this version
	 * - The charges field on PaymentIntents (used in check_order_paid) was
	 *   removed in 2022-11-15, so we stay before that version
	 */
	const STRIPE_API_VERSION = '2022-08-01';

	/**
	 * Total amount in smallest currency unit
	 *
	 * @var int
	 */
	private $total = 0;

	/**
	 * Make a Stripe API request
	 *
	 * @param string $endpoint The API endpoint
	 * @param array  $args Request arguments
	 * @param string $method HTTP method (GET, POST, etc.)
	 * @return array|WP_Error Response data or error
	 */
	private function make_stripe_request( $endpoint, $args = array(), $method = 'GET' ) {
		$url = 'https://api.stripe.com/v1/' . ltrim( $endpoint, '/' );

		$request_args = array(
			'method'      => $method,
			'timeout'     => 45,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => array(
				'Authorization'  => 'Bearer ' . $this->get_secret_key(),
				'Content-Type'   => 'application/x-www-form-urlencoded',
				'Stripe-Version' => self::STRIPE_API_VERSION,
			),
		);

		if ( ! empty( $args ) ) {
			if ( $method === 'GET' ) {
				$url = add_query_arg( $args, $url );
			} else {
				$request_args['body'] = http_build_query( $args );
			}
		}

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			return new WP_Error( 'stripe_api_error', $body['error']['message'], $body['error'] );
		}

		return $body;
	}

	/**
	 * Build shipping address array for Stripe
	 *
	 * @return array Shipping address array
	 */
	private function build_shipping_address() {
		return array(
			'name'    => SPC()->cart->get_checkout_data_item( 'first_name' ) . ' ' . SPC()->cart->get_checkout_data_item( 'last_name' ),
			'address' => array(
				'city'        => SPC()->cart->get_checkout_data_item( 'shipping_city' ) ? SPC()->cart->get_checkout_data_item( 'shipping_city' ) : '',
				'country'     => SPC()->cart->get_checkout_data_item( 'shipping_country' ) ? SPC()->cart->get_checkout_data_item( 'shipping_country' ) : '',
				'line1'       => SPC()->cart->get_checkout_data_item( 'shipping_address1' ) ? SPC()->cart->get_checkout_data_item( 'shipping_address1' ) : '',
				'line2'       => SPC()->cart->get_checkout_data_item( 'shipping_address2' ) ? SPC()->cart->get_checkout_data_item( 'shipping_address2' ) : '',
				'postal_code' => SPC()->cart->get_checkout_data_item( 'shipping_postcode' ) ? SPC()->cart->get_checkout_data_item( 'shipping_postcode' ) : '',
				'state'       => SPC()->cart->get_checkout_data_item( 'shipping_state' ) ? SPC()->cart->get_checkout_data_item( 'shipping_state' ) : '',
			),
		);
	}

	/**
	 * Build base payment intent arguments
	 *
	 * @param string $stripe_customer_id Customer ID
	 * @param string $order_id Order ID
	 * @param bool   $for_update Whether this is for updating an existing payment intent
	 * @return array Payment intent arguments
	 */
	private function build_payment_intent_args( $stripe_customer_id = '', $order_id = '', $for_update = false ) {
		$args = array(
			'amount'   => $this->total,
			'currency' => $this->currency,
		);

		// Only include payment method configuration when creating new payment intents.
		if ( ! $for_update ) {
			$optimized_checkout = $this->get_option( 'optimized_checkout' );

			SPC()->log( 'Stripe optimized_checkout setting value: ' . var_export( $optimized_checkout, true ) );

			if ( $optimized_checkout === 'yes' || $optimized_checkout === '1' || $optimized_checkout === false ) {
				// Use automatic payment methods (Optimized Checkout).
				$args['automatic_payment_methods'] = array(
					'enabled' => 'true',
				);
				SPC()->log( 'Using automatic_payment_methods (Optimized Checkout)' );
			} else {
				// Use manually selected payment methods
				$enabled_methods = $this->get_enabled_payment_methods();
				SPC()->log( 'Manually selected payment methods: ' . implode( ', ', $enabled_methods ) );
				if ( ! empty( $enabled_methods ) ) {
					$args['payment_method_types'] = $enabled_methods;
				} else {
					// Fallback to card only
					$args['payment_method_types'] = array( 'card' );
				}
			}
		}

		// Add statement descriptor if configured
		$statement_descriptor = $this->get_option( 'statement_descriptor' );
		if ( ! empty( $statement_descriptor ) ) {
			// Sanitize: only alphanumeric and spaces, max 22 chars, min 5 chars
			$sanitized_descriptor = preg_replace( '/[^A-Za-z0-9 ]/', '', $statement_descriptor );
			$sanitized_descriptor = substr( $sanitized_descriptor, 0, 22 );
			if ( strlen( $sanitized_descriptor ) >= 5 ) {
				$args['statement_descriptor'] = $sanitized_descriptor;
			}
		}

		// Add statement descriptor suffix (order number) if configured and order exists
		$use_suffix = $this->get_option( 'statement_descriptor_suffix' );
		if ( ( $use_suffix === 'yes' || $use_suffix === '1' ) && ! empty( $order_id ) ) {
			$order  = sunshine_get_order( $order_id );
			$suffix = $order->get_name();
			// Stripe disallows < > \ ' " * in statement descriptors
			$suffix = preg_replace( '/[<>\\\\\'"*]/', '', $suffix );
			// Fall back to compact format if name exceeds Stripe's 22-char limit
			if ( strlen( $suffix ) > 22 ) {
				$suffix = 'N' . $order->get_order_number();
			}
			$args['statement_descriptor_suffix'] = substr( $suffix, 0, 22 );
		}

		$args['shipping'] = $this->build_shipping_address();

		if ( $this->get_application_fee_amount() ) {
			$args['application_fee_amount'] = $this->get_application_fee_amount();
		}

		// Only include customer when creating new payment intents (Stripe doesn't allow changing customer on existing intents)
		if ( ! empty( $stripe_customer_id ) && ! $for_update ) {
			$args['customer'] = $stripe_customer_id;
		}

		// Get customer info for metadata and receipt.
		if ( is_user_logged_in() ) {
			$customer_email = SPC()->customer->get_email();
			$customer_name  = SPC()->customer->get_name();
		} else {
			$customer_email = SPC()->cart->get_checkout_data_item( 'email' );
			$customer_name  = SPC()->cart->get_checkout_data_item( 'first_name' ) . ' ' . SPC()->cart->get_checkout_data_item( 'last_name' );
		}

		// Add receipt email if available.
		if ( ! empty( $customer_email ) ) {
			$args['receipt_email'] = $customer_email;
		}

		// Add description with customer name.
		if ( ! empty( $customer_name ) ) {
			$args['description'] = sprintf( 'Order for %s', trim( $customer_name ) );
		}

		// Build metadata with customer info.
		$metadata = array();
		if ( ! empty( $order_id ) ) {
			$metadata['order_id'] = $order_id;
			$order                = sunshine_get_order( $order_id );
			$args['description']  = $order->get_name();
			if ( ! empty( $customer_name ) ) {
				$args['description'] .= ', ' . trim( $customer_name );
			}
		}
		if ( ! empty( $customer_email ) ) {
			$metadata['customer_email'] = $customer_email;
		}
		if ( ! empty( $customer_name ) ) {
			$metadata['customer_name'] = trim( $customer_name );
		}

		if ( ! empty( $metadata ) ) {
			$args['metadata'] = $metadata;
		}

		sunshine_log( $args, 'Stripe payment intent args' );

		return $args;
	}

	/**
	 * Handle idempotency key conflicts with retry logic
	 *
	 * @param array  $args Payment intent arguments
	 * @param string $idempotency_key Current idempotency key
	 * @return array|WP_Error Payment intent data or error
	 */
	private function create_payment_intent_with_retry( $args, $idempotency_key ) {
		$response = $this->make_stripe_request( 'payment_intents', $args, 'POST' );

		if ( is_wp_error( $response ) && $response->get_error_code() === 'stripe_api_error' ) {
			$error_data = $response->get_error_data();
			if ( isset( $error_data['code'] ) && $error_data['code'] === 'idempotency_key_in_use' ) {
				SPC()->log( 'Idempotency key conflict detected, retrying payment intent creation with new key' );
				sleep( 1 );

				$new_idempotency_key = $this->generate_idempotency_key();
				SPC()->session->set( 'stripe_idempotency_key', $new_idempotency_key );
				SPC()->log( 'Retrying with new idempotency key: ' . $new_idempotency_key );

				$retry_response = $this->make_stripe_request( 'payment_intents', $args, 'POST' );
				return $retry_response;
			}
		}

		return $response;
	}

	/**
	 * Initialize the Stripe payment method
	 *
	 * Sets up payment method properties and registers WordPress hooks.
	 *
	 * @return void
	 */
	public function init() {

		$this->id                    = 'stripe';
		$this->name                  = __( 'Stripe', 'sunshine-photo-cart' );
		$this->class                 = get_class( $this );
		$this->description           = __( 'Pay with credit card', 'sunshine-photo-cart' );
		$this->can_be_enabled        = true;
		$this->needs_billing_address = false;

		add_action( 'sunshine_stripe_connect_display', array( $this, 'stripe_connect_display' ) );
		add_action( 'sunshine_stripe_webhook_display', array( $this, 'stripe_webhook_display' ) );
		add_action( 'sunshine_stripe_payment_methods_display', array( $this, 'stripe_payment_methods_display' ) );
		add_action( 'admin_init', array( $this, 'stripe_connect_return' ) );
		add_action( 'admin_init', array( $this, 'stripe_disconnect_return' ) );
		add_action( 'admin_init', array( $this, 'setup_payment_domain_manual' ) );
		add_action( 'wp_ajax_sunshine_stripe_sync_payment_methods', array( $this, 'sync_payment_methods_ajax' ) );
		add_action( 'wp_ajax_sunshine_stripe_toggle_payment_method', array( $this, 'toggle_payment_method_ajax' ) );

		if ( ! $this->is_active() || ! $this->is_allowed() ) {
			return;
		}

		add_action( 'wp_ajax_sunshine_stripe_log_payment', array( $this, 'log_payment' ) );
		add_action( 'wp_ajax_nopriv_sunshine_stripe_log_payment', array( $this, 'log_payment' ) );

		add_action( 'wp_ajax_sunshine_stripe_create_payment_intent', array( $this, 'create_payment_intent' ) );
		add_action( 'wp_ajax_nopriv_sunshine_stripe_create_payment_intent', array( $this, 'create_payment_intent' ) );

		add_filter( 'sunshine_checkout_post_process_order', array( $this, 'checkout_post_process_order' ), 10, 3 );

		add_action( 'admin_init', array( $this, 'set_payment_intent_manually' ), 1 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		add_action( 'sunshine_checkout_update_payment_method', array( $this, 'update_payment_method' ) );

		add_action( 'sunshine_checkout_init_order_success', array( $this, 'init_order' ) );

		add_action( 'wp', array( $this, 'check_order_paid' ) );
		add_action( 'wp', array( $this, 'process_webhook' ) );

		add_action( 'wp', array( $this, 'payment_return' ), 20 );

		// Hosted checkout handlers
		add_action( 'wp', array( $this, 'stripe_checkout_return' ) );
		add_action( 'wp', array( $this, 'stripe_checkout_cancel' ) );

		// Filter order status for hosted checkout mode
		add_filter( 'sunshine_create_order_status', array( $this, 'create_order_status' ), 10, 2 );

		add_action( 'sunshine_checkout_process_payment_stripe', array( $this, 'process_payment' ) );

		add_filter( 'sunshine_order_transaction_url', array( $this, 'transaction_url' ) );

		add_filter( 'sunshine_admin_order_tabs', array( $this, 'admin_order_tab' ), 10, 2 );
		add_action( 'sunshine_admin_order_tab_stripe', array( $this, 'admin_order_tab_content_stripe' ) );

		add_action( 'sunshine_order_actions', array( $this, 'order_actions' ), 10, 2 );
		add_action( 'sunshine_order_actions_options', array( $this, 'order_actions_options' ) );
		add_action( 'sunshine_order_process_action_stripe_refund', array( $this, 'process_refund' ) );

		add_action( 'sunshine_checkout_validation', array( $this, 'checkout_validation' ) );

	}

	/**
	 * Log payment results from Stripe
	 *
	 * Handles AJAX requests to log payment results and update order status.
	 *
	 * @return void
	 */
	public function log_payment() {
		if ( ! isset( $_POST['result'] ) ) {
			return;
		}
		$result   = wp_unslash( $_POST['result'] );
		$order_id = SPC()->session->get( 'checkout_order_id' );
		if ( $order_id ) {
			$order = sunshine_get_order( $order_id );

			if ( ! empty( $result['error'] ) ) {
				SPC()->log( 'Stripe payment error: ' . print_r( $result['error'], true ) );
				// Set order to failed.
				$order_id = SPC()->session->get( 'checkout_order_id' );
				if ( $order_id ) {
					$order = sunshine_get_order( $order_id );
					$order->set_status( 'failed' );
					SPC()->log( 'Stripe payment error: ' . print_r( $result['error'], true ) );
				}
				return;
			}

			SPC()->log( 'Stripe payment result logged: ' . print_r( $result, true ) );
			if ( ! empty( $result['error'] ) ) {
				$order->add_log( 'Stripe payment error: ' . $result['error']['message'] );
			}
		}
	}

	public function show_payment_intent_id() {
		if ( SPC()->session->get( 'stripe_payment_intent_id' ) ) {
			echo 'Payment intent ID: ' . esc_html( SPC()->session->get( 'stripe_payment_intent_id' ) );
		}
	}

	public function admin_notices() {

		$mode    = $this->get_mode_value();
		$webhook = SPC()->get_option( 'stripe_webhook_' . $mode );
		if ( empty( $webhook_id ) ) {
			SPC()->notices->add_admin( 'stripe_webhook_not_setup', __( 'Stripe webhook is not setup', 'sunshine-photo-cart' ), 'error' ) . ' <a href="' . admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=stripe' ) . '">' . __( 'Configure here', 'sunshine-photo-cart' ) . '</a>';
		}

	}

	public function set_payment_intent_manually() {
		if ( isset( $_GET['stripe_payment_intent_id'] ) ) {
			SPC()->session->set( 'stripe_payment_intent_id', sanitize_text_field( $_GET['stripe_payment_intent_id'] ) );
			SPC()->log( 'Set payment intent manually: ' . SPC()->session->get( 'stripe_payment_intent_id' ) );
		}
	}

	/**
	 * Check if Stripe payment method is allowed
	 *
	 * @return bool True if Stripe account ID is configured, false otherwise
	 */
	public function is_allowed() {
		$account_id = $this->get_option( 'account_id_' . $this->get_mode_value() );
		if ( ! empty( $account_id ) ) {
			return true;
		}
		return false;
	}

	public function get_submit_label() {
		if ( $this->get_option( 'checkout_mode' ) === 'hosted' ) {
			return __( 'Continue to payment', 'sunshine-photo-cart' );
		}
		return parent::get_submit_label();
	}

	/**
	 * Get admin options for Stripe payment method
	 *
	 * @param array $options Existing options array
	 * @return array Modified options array with Stripe-specific options
	 */
	public function options( $options ) {

		// TODO: Need to show URL the user must use for webhook URL and how to do so

		foreach ( $options as &$option ) {
			if ( $option['id'] == 'stripe_header' && $this->get_application_fee_percent() > 0 ) {
				/* translators: %s is the application fee percentage */
				$option['description'] = sprintf( __( 'Note: You are using the free Stripe payment gateway integration. This includes an additional %s%% fee for payment processing on each order that goes to Sunshine Photo Cart in addition to Stripe processing fees. This added fee is removed by using the Stripe Pro add-on.', 'sunshine-photo-cart' ), $this->get_application_fee_percent() ) . ' <a href="https://www.sunshinephotocart.com/addon/stripe/?utm_source=plugin&utm_medium=link&utm_campaign=stripe" target="_blank">' . __( 'Learn more', 'sunshine-photo-cart' ) . '</a>';
			}
		}

		// Checkout Mode Setting (Inline vs Hosted)
		$options[] = array(
			'name'        => __( 'Checkout Mode', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_checkout_mode',
			'type'        => 'radio',
			'options'     => array(
				'inline' => __( 'Inline Payment Form (recommended)', 'sunshine-photo-cart' ),
				'hosted' => __( 'Redirect to Stripe Checkout', 'sunshine-photo-cart' ),
			),
			'default'     => 'inline',
			'description' => __( 'Inline shows the payment form on your checkout page. Redirect sends customers to Stripe\'s hosted checkout page.', 'sunshine-photo-cart' ) . ' <a href="https://docs.stripe.com/payments/checkout" target="_blank">' . __( 'Learn more about Stripe Checkout', 'sunshine-photo-cart' ) . '</a>',
		);

		// Tax Calculation Mode (only for hosted checkout)
		$options[] = array(
			'name'        => __( 'Tax Calculation', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_tax_mode',
			'type'        => 'radio',
			'options'     => array(
				'sunshine' => __( 'Use Sunshine tax calculations', 'sunshine-photo-cart' ),
				'stripe'   => __( 'Use Stripe Tax (automatic)', 'sunshine-photo-cart' ),
			),
			'default'     => 'sunshine',
			'description' => __( 'When using Stripe Tax, tax is calculated automatically by Stripe based on customer location. Requires Stripe Tax to be enabled in your Stripe Dashboard.', 'sunshine-photo-cart' ) . ' <a href="https://dashboard.stripe.com/tax" target="_blank">' . __( 'Enable Stripe Tax', 'sunshine-photo-cart' ) . '</a> | <a href="https://docs.stripe.com/tax" target="_blank">' . __( 'Learn more', 'sunshine-photo-cart' ) . '</a>',
			'conditions'  => array(
				array(
					'field'   => $this->id . '_checkout_mode',
					'compare' => '==',
					'value'   => 'hosted',
					'action'  => 'show',
				),
			),
		);

		$options[] = array(
			'name'        => __( 'Layout', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_layout',
			'type'        => 'radio',
			'options'     => array(
				'tabs'      => __( 'Tabs', 'sunshine-photo-cart' ),
				'accordion' => __( 'Accordion', 'sunshine-photo-cart' ),
			),
			'description' => '<a href="https://docs.stripe.com/payments/payment-element#layout" target="_blank">' . __( 'See differences in layout options', 'sunshine-photo-cart' ) . '</a>',
			'default'     => 'tabs',
			'conditions'  => array(
				array(
					'field'   => $this->id . '_checkout_mode',
					'compare' => '==',
					'value'   => 'inline',
					'action'  => 'show',
				),
			),
		);

		$options[] = array(
			'name'    => __( 'Mode', 'sunshine-photo-cart' ),
			'id'      => $this->id . '_mode',
			'type'    => 'radio',
			'options' => array(
				'live' => __( 'Live', 'sunshine-photo-cart' ),
				'test' => __( 'Test', 'sunshine-photo-cart' ),
			),
			'default' => 'live',
		);

		$options[] = array(
			'name'             => __( 'Stripe Connection (Live)', 'sunshine-photo-cart' ),
			'id'               => $this->id . '_connect_live',
			'type'             => 'stripe_connect',
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
			'name'             => __( 'Stripe Connection (Test)', 'sunshine-photo-cart' ),
			'id'               => $this->id . '_connect_test',
			'type'             => 'stripe_connect',
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

		// Webhook settings - right after connection
		$options[] = array(
			'name'             => __( 'Stripe Webhook', 'sunshine-photo-cart' ),
			'id'               => $this->id . '_webhook',
			'type'             => 'stripe_webhook',
			'hide_system_info' => true,
		);
		$options[] = array(
			'name'             => __( 'Stripe Webhook Secret (Live)', 'sunshine-photo-cart' ),
			'id'               => $this->id . '_webhook_secret_live',
			'type'             => 'text',
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
			'name'             => __( 'Stripe Webhook Secret (Test)', 'sunshine-photo-cart' ),
			'id'               => $this->id . '_webhook_secret_test',
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

		// Bank Statement Descriptor Settings (inline checkout only)
		$options[] = array(
			'name'        => __( 'Bank Statement Descriptor', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_statement_descriptor',
			'type'        => 'text',
			'default'     => '',
			'placeholder' => __( 'e.g., WWW.YOURSITE.COM', 'sunshine-photo-cart' ),
			'description' => __( 'The description that appears on your customer\'s bank statement. Max 22 characters, letters/numbers/spaces only. Leave blank to use your Stripe account default.', 'sunshine-photo-cart' ),
			'conditions'  => array(
				array(
					'field'   => $this->id . '_checkout_mode',
					'compare' => '==',
					'value'   => 'inline',
					'action'  => 'show',
				),
			),
		);

		$options[] = array(
			'name'        => __( 'Add Order Number to Statement', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_statement_descriptor_suffix',
			'type'        => 'checkbox',
			'default'     => '',
			'description' => __( 'Append the order number to the bank statement descriptor (e.g., "MYSHOP* #12345"). Helps customers identify charges.', 'sunshine-photo-cart' ),
			'conditions'  => array(
				array(
					'field'   => $this->id . '_checkout_mode',
					'compare' => '==',
					'value'   => 'inline',
					'action'  => 'show',
				),
			),
		);

		$options[] = array(
			'name'        => __( 'Shortened Descriptor', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_statement_descriptor_prefix',
			'type'        => 'text',
			'default'     => '',
			'placeholder' => __( 'e.g., MYSHOP', 'sunshine-photo-cart' ),
			'description' => __( 'A shortened version (max 10 characters) used with the order number suffix. If blank, uses first 10 characters of the full descriptor.', 'sunshine-photo-cart' ),
			'conditions'  => array(
				array(
					'field'   => $this->id . '_checkout_mode',
					'compare' => '==',
					'value'   => 'inline',
					'action'  => 'show',
				),
				array(
					'field'   => $this->id . '_statement_descriptor_suffix',
					'compare' => '==',
					'value'   => '1',
					'action'  => 'show',
				),
			),
		);

		// Optimized Checkout Setting (inline checkout only)
		$options[] = array(
			'name'        => __( 'Optimized Checkout', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_optimized_checkout',
			'type'        => 'checkbox',
			'default'     => 'yes',
			'description' => __( 'Use Stripe\'s Optimized Checkout Suite to dynamically display the most relevant payment methods to each customer. When disabled, you can manually select which payment methods to offer.', 'sunshine-photo-cart' ),
			'conditions'  => array(
				array(
					'field'   => $this->id . '_checkout_mode',
					'compare' => '==',
					'value'   => 'inline',
					'action'  => 'show',
				),
			),
		);

		// Payment Methods Setting (inline checkout only)
		$options[] = array(
			'name'             => __( 'Payment Methods', 'sunshine-photo-cart' ),
			'id'               => $this->id . '_payment_methods',
			'type'             => 'stripe_payment_methods',
			'hide_system_info' => true,
			'conditions'       => array(
				array(
					'field'   => $this->id . '_checkout_mode',
					'compare' => '==',
					'value'   => 'inline',
					'action'  => 'show',
				),
			),
		);

		return $options;

	}

	function stripe_connect_display( $field ) {

		if ( $field['id'] == 'stripe_connect_live' ) {
			$mode = 'live';
		} else {
			$mode = 'test';
		}

		$account_id = SPC()->get_option( 'stripe_account_id_' . $mode );

		if ( $account_id ) {
			// Get account details from Stripe
			$account_details = $this->get_stripe_account_details( $mode );
			?>

			<div class="sunshine-stripe-account-info" style="background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; padding: 15px;">
				<div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
					<span style="background: #635bff; color: #fff; padding: 5px 10px; border-radius: 4px; font-weight: bold; font-size: 12px;">STRIPE</span>
					<span style="color: #28a745; font-weight: 500;"><?php esc_html_e( 'Connected', 'sunshine-photo-cart' ); ?></span>
				</div>
				<?php if ( $account_details ) : ?>
					<div style="margin-bottom: 5px;">
						<strong><?php esc_html_e( 'Account:', 'sunshine-photo-cart' ); ?></strong>
						<?php echo esc_html( $account_details['name'] ); ?>
						<?php if ( ! empty( $account_details['email'] ) ) : ?>
							<span style="color: #666;">(<?php echo esc_html( $account_details['email'] ); ?>)</span>
						<?php endif; ?>
					</div>
					<div style="color: #666; font-size: 12px;">
						<strong><?php esc_html_e( 'Account ID:', 'sunshine-photo-cart' ); ?></strong>
						<code style="background: #eee; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html( $account_details['id'] ); ?></code>
					</div>
				<?php else : ?>
					<div style="color: #666; font-size: 12px;">
						<strong><?php esc_html_e( 'Account ID:', 'sunshine-photo-cart' ); ?></strong>
						<code style="background: #eee; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html( $account_id ); ?></code>
					</div>
				<?php endif; ?>
				<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">
					<a href="https://www.sunshinephotocart.com/?stripe_disconnect=1&account_id=<?php echo esc_attr( $account_id ); ?>&mode=<?php echo esc_html( $mode ); ?>&nonce=<?php echo esc_html( wp_create_nonce( 'sunshine_stripe_disconnect' ) ); ?>&return_url=<?php echo esc_url( admin_url( 'admin.php?sunshine_stripe_disconnect_return' ) ); ?>" style="color: #dc3545; text-decoration: none; font-size: 12px;"><?php esc_html_e( 'Disconnect Stripe account', 'sunshine-photo-cart' ); ?></a>
				</div>
			</div>

		<?php } else { ?>

			<p><a href="https://www.sunshinephotocart.com/?stripe_connect=1&nonce=<?php echo esc_attr( wp_create_nonce( 'sunshine_stripe_connect' ) ); ?>&return_url=<?php echo esc_url( admin_url( 'admin.php?sunshine_stripe_connect_return' ) ); ?>&mode=<?php echo esc_attr( $mode ); ?>" class="sunshine-stripe-connect"><span><?php esc_html_e( 'Connect to', 'sunshine-photo-cart' ); ?></span> <span class="stripe">Stripe</span></a></p>

			<?php
		}

	}

	function stripe_webhook_display( $field ) {

		echo '<p>Stripe Webhook URL: <code>' . esc_url( $this->get_webhook_url() ) . '</code></p>';
		echo '<p><a href="https://www.sunshinephotocart.com/docs/setting-up-stripe/#webhooks" target="_blank">' . esc_html__( 'Learn how to setup Stripe Webhooks', 'sunshine-photo-cart' ) . '</a></p>';

	}

	/**
	 * Display payment methods configuration UI
	 *
	 * Shows a grid of available payment methods with toggle switches
	 * and a sync button to refresh from Stripe.
	 *
	 * @param array $field Field configuration
	 * @return void
	 */
	function stripe_payment_methods_display( $field ) {
		$optimized_checkout = $this->get_option( 'optimized_checkout' );
		// Optimized is ON if: value is 'yes', '1', or false (never saved, use default 'yes')
		// Optimized is OFF if: value is '' (explicitly unchecked and saved)
		$is_optimized      = ( $optimized_checkout === 'yes' || $optimized_checkout === '1' || $optimized_checkout === false );
		$enabled_methods   = $this->get_enabled_payment_methods();
		$available_methods = $this->get_available_payment_methods();

		// Define all known payment methods with their display names
		$all_payment_methods = $this->get_all_payment_method_definitions();

		?>
		<div id="sunshine-stripe-payment-methods-container">
			<!-- Notice shown when Optimized Checkout is ON -->
			<div id="sunshine-stripe-optimized-notice" class="sunshine-stripe-optimized-notice" style="background: #d4edda; border: 1px solid #28a745; border-radius: 4px; padding: 10px 15px; <?php echo ! $is_optimized ? 'display: none;' : ''; ?>">
				<strong><?php esc_html_e( 'Optimized Checkout is enabled', 'sunshine-photo-cart' ); ?></strong><br>
				<?php esc_html_e( 'Stripe will automatically display the most relevant payment methods to each customer based on their location, device, and purchase amount. Disable Optimized Checkout above to manually select payment methods.', 'sunshine-photo-cart' ); ?>
			</div>

			<!-- Manual payment methods selection (hidden when Optimized Checkout is ON) -->
			<div id="sunshine-stripe-manual-methods" style="<?php echo $is_optimized ? 'display: none;' : ''; ?>">
				<div class="sunshine-stripe-payment-methods-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 15px;">
					<?php foreach ( $all_payment_methods as $method_id => $method_info ) : ?>
						<?php
						$is_enabled   = in_array( $method_id, $enabled_methods );
						$is_available = empty( $available_methods ) || in_array( $method_id, $available_methods );
						?>
						<div class="sunshine-stripe-payment-method" style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 12px; display: flex; align-items: center; justify-content: space-between; <?php echo ! $is_available ? 'opacity: 0.5;' : ''; ?>">
							<div style="display: flex; align-items: center; gap: 10px;">
								<span style="font-weight: 500;"><?php echo esc_html( $method_info['name'] ); ?></span>
							</div>
							<label class="sunshine-toggle-switch" style="position: relative; display: inline-block; width: 44px; height: 24px;">
								<input type="checkbox"
									class="sunshine-stripe-payment-method-toggle"
									data-method="<?php echo esc_attr( $method_id ); ?>"
									<?php checked( $is_enabled ); ?>
									<?php disabled( ! $is_available ); ?>
									style="opacity: 0; width: 0; height: 0;">
								<span class="sunshine-toggle-slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: <?php echo $is_enabled ? '#635bff' : '#ccc'; ?>; border-radius: 24px; transition: 0.3s;">
									<span style="position: absolute; content: ''; height: 18px; width: 18px; left: <?php echo $is_enabled ? '23px' : '3px'; ?>; bottom: 3px; background-color: white; border-radius: 50%; transition: 0.3s;"></span>
								</span>
							</label>
						</div>
					<?php endforeach; ?>
				</div>

				<button type="button" id="sunshine-stripe-sync-payment-methods" class="button">
					<?php esc_html_e( 'Sync Payment Methods', 'sunshine-photo-cart' ); ?>
				</button>
				<span id="sunshine-stripe-sync-status" style="margin-left: 10px;"></span>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			// Handle payment method toggle
			$('.sunshine-stripe-payment-method-toggle').on('change', function() {
				var $toggle = $(this);
				var method = $toggle.data('method');
				var enabled = $toggle.is(':checked');
				var $slider = $toggle.next('.sunshine-toggle-slider');
				var $sliderKnob = $slider.find('span');

				// Update visual state immediately
				$slider.css('background-color', enabled ? '#635bff' : '#ccc');
				$sliderKnob.css('left', enabled ? '23px' : '3px');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'sunshine_stripe_toggle_payment_method',
						method: method,
						enabled: enabled ? 1 : 0,
						nonce: '<?php echo esc_js( wp_create_nonce( 'sunshine_stripe_payment_methods' ) ); ?>'
					},
					error: function() {
						// Revert on error
						$toggle.prop('checked', !enabled);
						$slider.css('background-color', !enabled ? '#635bff' : '#ccc');
						$sliderKnob.css('left', !enabled ? '23px' : '3px');
					}
				});
			});

			// Handle sync button
			$('#sunshine-stripe-sync-payment-methods').on('click', function() {
				var $button = $(this);
				var $status = $('#sunshine-stripe-sync-status');

				$button.prop('disabled', true);
				$status.text('<?php esc_html_e( 'Syncing...', 'sunshine-photo-cart' ); ?>');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'sunshine_stripe_sync_payment_methods',
						nonce: '<?php echo esc_js( wp_create_nonce( 'sunshine_stripe_payment_methods' ) ); ?>'
					},
					success: function(response) {
						if (response.success) {
							$status.text('<?php esc_html_e( 'Synced successfully!', 'sunshine-photo-cart' ); ?>');
							setTimeout(function() {
								location.reload();
							}, 1000);
						} else {
							$status.text(response.data || '<?php esc_html_e( 'Sync failed', 'sunshine-photo-cart' ); ?>');
							$button.prop('disabled', false);
						}
					},
					error: function() {
						$status.text('<?php esc_html_e( 'Sync failed', 'sunshine-photo-cart' ); ?>');
						$button.prop('disabled', false);
					}
				});
			});

			// Handle optimized checkout toggle to show/hide payment methods
			$('input[name="sunshine_options[stripe_optimized_checkout]"]').on('change', function() {
				var isOptimized = $(this).is(':checked');

				if (isOptimized) {
					$('#sunshine-stripe-manual-methods').hide();
					$('#sunshine-stripe-optimized-notice').show();
				} else {
					$('#sunshine-stripe-manual-methods').show();
					$('#sunshine-stripe-optimized-notice').hide();
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Get all payment method definitions
	 *
	 * @return array Array of payment method definitions
	 */
	private function get_all_payment_method_definitions() {
		return array(
			'card'              => array(
				'name' => __( 'Credit Card', 'sunshine-photo-cart' ),
			),
			'us_bank_account'   => array(
				'name' => __( 'US Bank Account', 'sunshine-photo-cart' ),
			),
			'acss_debit'        => array(
				'name' => __( 'Canadian Debit', 'sunshine-photo-cart' ),
			),
			'affirm'            => array(
				'name' => __( 'Affirm', 'sunshine-photo-cart' ),
			),
			'afterpay_clearpay' => array(
				'name' => __( 'Afterpay/Clearpay', 'sunshine-photo-cart' ),
			),
			'alipay'            => array(
				'name' => __( 'Alipay', 'sunshine-photo-cart' ),
			),
			'amazon_pay'        => array(
				'name' => __( 'Amazon Pay', 'sunshine-photo-cart' ),
			),
			'apple_pay'         => array(
				'name' => __( 'Apple Pay', 'sunshine-photo-cart' ),
			),
			'bacs_debit'        => array(
				'name' => __( 'Bacs Debit', 'sunshine-photo-cart' ),
			),
			'bancontact'        => array(
				'name' => __( 'Bancontact', 'sunshine-photo-cart' ),
			),
			'cashapp'           => array(
				'name' => __( 'Cash App', 'sunshine-photo-cart' ),
			),
			'eps'               => array(
				'name' => __( 'EPS', 'sunshine-photo-cart' ),
			),
			'giropay'           => array(
				'name' => __( 'giropay', 'sunshine-photo-cart' ),
			),
			'google_pay'        => array(
				'name' => __( 'Google Pay', 'sunshine-photo-cart' ),
			),
			'ideal'             => array(
				'name' => __( 'iDEAL', 'sunshine-photo-cart' ),
			),
			'klarna'            => array(
				'name' => __( 'Klarna', 'sunshine-photo-cart' ),
			),
			'link'              => array(
				'name' => __( 'Link', 'sunshine-photo-cart' ),
			),
			'p24'               => array(
				'name' => __( 'Przelewy24', 'sunshine-photo-cart' ),
			),
			'sepa_debit'        => array(
				'name' => __( 'SEPA Direct Debit', 'sunshine-photo-cart' ),
			),
			'sofort'            => array(
				'name' => __( 'SOFORT', 'sunshine-photo-cart' ),
			),
			'wechat_pay'        => array(
				'name' => __( 'WeChat Pay', 'sunshine-photo-cart' ),
			),
		);
	}

	/**
	 * Get enabled payment methods from options
	 *
	 * @return array Array of enabled payment method IDs
	 */
	private function get_enabled_payment_methods() {
		$enabled = SPC()->get_option( 'stripe_enabled_payment_methods' );
		if ( empty( $enabled ) || ! is_array( $enabled ) ) {
			// Default to card only
			return array( 'card' );
		}
		return $enabled;
	}

	/**
	 * Get available payment methods from Stripe (cached)
	 *
	 * @return array Array of available payment method IDs, or empty if not synced
	 */
	private function get_available_payment_methods() {
		$mode      = $this->get_mode_value();
		$cache_key = 'sunshine_stripe_available_methods_' . $mode;
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		return array(); // Return empty if not synced yet (shows all as available)
	}

	/**
	 * AJAX handler for syncing payment methods from Stripe
	 *
	 * @return void
	 */
	public function sync_payment_methods_ajax() {
		check_ajax_referer( 'sunshine_stripe_payment_methods', 'nonce' );

		if ( ! current_user_can( 'sunshine_manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'sunshine-photo-cart' ) );
		}

		$this->setup();

		// Fetch payment method configurations from Stripe
		$response = $this->make_stripe_request( 'payment_method_configurations', array( 'limit' => 100 ) );

		if ( is_wp_error( $response ) ) {
			SPC()->log( 'Failed to sync payment methods: ' . $response->get_error_message() );
			wp_send_json_error( $response->get_error_message() );
		}

		$available_methods = array();

		// Parse the response to get available payment methods
		if ( ! empty( $response['data'] ) ) {
			foreach ( $response['data'] as $config ) {
				// Each config has payment method types
				if ( ! empty( $config ) && is_array( $config ) ) {
					foreach ( $config as $key => $value ) {
						// Check for enabled payment methods
						if ( is_array( $value ) && isset( $value['available'] ) && $value['available'] ) {
							$available_methods[] = $key;
						}
					}
				}
			}
		}

		// If we couldn't parse properly, try alternative endpoint
		if ( empty( $available_methods ) ) {
			// Fallback: Use account capabilities
			$account_id = $this->get_account_id();
			if ( $account_id ) {
				$account_response = $this->make_stripe_request( 'accounts/' . $account_id );
				if ( ! is_wp_error( $account_response ) && ! empty( $account_response['capabilities'] ) ) {
					foreach ( $account_response['capabilities'] as $capability => $status ) {
						if ( $status === 'active' ) {
							// Map capability names to payment method types
							$method_map = array(
								'card_payments'       => 'card',
								'transfers'           => null, // Not a payment method
								'us_bank_account_ach_payments' => 'us_bank_account',
								'affirm_payments'     => 'affirm',
								'afterpay_clearpay_payments' => 'afterpay_clearpay',
								'klarna_payments'     => 'klarna',
								'link_payments'       => 'link',
								'cashapp_payments'    => 'cashapp',
								'eps_payments'        => 'eps',
								'giropay_payments'    => 'giropay',
								'ideal_payments'      => 'ideal',
								'p24_payments'        => 'p24',
								'sepa_debit_payments' => 'sepa_debit',
								'sofort_payments'     => 'sofort',
								'bancontact_payments' => 'bancontact',
								'alipay_payments'     => 'alipay',
								'wechat_pay_payments' => 'wechat_pay',
							);

							if ( isset( $method_map[ $capability ] ) && $method_map[ $capability ] ) {
								$available_methods[] = $method_map[ $capability ];
							}
						}
					}
				}
			}
		}

		// Always include card as it's the most basic
		if ( ! in_array( 'card', $available_methods ) ) {
			array_unshift( $available_methods, 'card' );
		}

		$available_methods = array_unique( $available_methods );

		// Cache for 24 hours
		$mode      = $this->get_mode_value();
		$cache_key = 'sunshine_stripe_available_methods_' . $mode;
		set_transient( $cache_key, $available_methods, DAY_IN_SECONDS );

		SPC()->log( 'Synced payment methods from Stripe: ' . implode( ', ', $available_methods ) );

		wp_send_json_success( array( 'methods' => $available_methods ) );
	}

	/**
	 * AJAX handler for toggling a payment method
	 *
	 * @return void
	 */
	public function toggle_payment_method_ajax() {
		check_ajax_referer( 'sunshine_stripe_payment_methods', 'nonce' );

		if ( ! current_user_can( 'sunshine_manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'sunshine-photo-cart' ) );
		}

		$method  = isset( $_POST['method'] ) ? sanitize_text_field( $_POST['method'] ) : '';
		$enabled = isset( $_POST['enabled'] ) ? (bool) $_POST['enabled'] : false;

		if ( empty( $method ) ) {
			wp_send_json_error( __( 'Invalid payment method', 'sunshine-photo-cart' ) );
		}

		$enabled_methods = $this->get_enabled_payment_methods();

		if ( $enabled ) {
			if ( ! in_array( $method, $enabled_methods ) ) {
				$enabled_methods[] = $method;
			}
		} else {
			$enabled_methods = array_diff( $enabled_methods, array( $method ) );
			// Ensure at least card is enabled
			if ( empty( $enabled_methods ) ) {
				$enabled_methods = array( 'card' );
			}
		}

		SPC()->update_option( 'stripe_enabled_payment_methods', array_values( $enabled_methods ) );

		wp_send_json_success( array( 'enabled_methods' => $enabled_methods ) );
	}

	private function get_webhook_url() {
		$url = trailingslashit( get_bloginfo( 'url' ) );
		$url = add_query_arg(
			array(
				'sunshine_stripe_webhook' => 1,
			),
			$url
		);
		return $url;
	}

	function setup_payment_domain_manual() {

		if ( ! isset( $_GET['stripe_setup_payment_domain'] ) || ! current_user_can( 'sunshine_manage_options' ) ) {
			return;
		}

		$mode = sanitize_text_field( $_GET['stripe_setup_payment_domain'] );

		$result = $this->setup_payment_domain( $mode );
		if ( $result ) {
			SPC()->notices->add_admin( 'stripe_payment_domain_setup', __( 'Stripe payment domain setup successfully', 'sunshine-photo-cart' ), 'success' );
		} else {
			SPC()->notices->add_admin( 'stripe_payment_domain_setup_failed', __( 'Stripe payment domain setup failed', 'sunshine-photo-cart' ), 'error' );
		}

	}

	function setup_payment_domain( $mode = 'live' ) {

		$this->setup();

		$args = array(
			'domain_name' => $_SERVER['HTTP_HOST'],
		);

		$response = wp_remote_post(
			'https://api.stripe.com/v1/payment_method_domains',
			array(
				'method'      => 'POST',
				'timeout'     => 45,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->get_secret_key(),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'        => http_build_query( $args ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			SPC()->log( 'Failed setting up payment domain: ' . $error_message );
			return false;
		} else {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $data['error'] ) ) {
				SPC()->log( 'Failed setting up payment domain: ' . $data['error']['message'] );
				return false;
			}
			SPC()->log( 'Created stripe payment domain: ' . $data['id'] );
			SPC()->update_option( 'stripe_payment_domain_' . $mode, $data );
			return $data;
		}

	}

	function stripe_connect_return() {

		if ( ! isset( $_GET['sunshine_stripe_connect_return'] ) || ! current_user_can( 'sunshine_manage_options' ) ) {
			return false;
		}

		if ( isset( $_GET['error'] ) || empty( $_GET['account_id'] ) || empty( $_GET['publishable_key'] ) || empty( $_GET['secret_key'] ) || ! wp_verify_nonce( $_GET['nonce'], 'sunshine_stripe_connect' ) ) {
			SPC()->notices->add_admin( 'stripe_connect_fail', __( 'Stripe could not be connected', 'sunshine-photo-cart' ), 'error' );
			wp_redirect( admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=stripe' ) );
			exit;
		}

		if ( isset( $_GET['mode'] ) && $_GET['mode'] == 'live' ) {
			$mode = 'live';
		} else {
			$mode = 'test';
		}

		// Set some return values from Stripe Connect
		SPC()->update_option( 'stripe_account_id_' . $mode, sanitize_text_field( $_GET['account_id'] ) );
		SPC()->update_option( 'stripe_publishable_key_' . $mode, sanitize_text_field( $_GET['publishable_key'] ) );
		SPC()->update_option( 'stripe_secret_key_' . $mode, sanitize_text_field( $_GET['secret_key'] ) );
		SPC()->update_option( 'stripe_mode', $mode );

		SPC()->notices->add_admin( 'stripe_connected', __( 'Stripe has successfully been connected', 'sunshine-photo-cart' ), 'success' );

		$this->setup_payment_domain( $mode );

		wp_redirect( admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=stripe' ) );
		exit;

	}

	function stripe_disconnect_return() {

		if ( ! isset( $_GET['sunshine_stripe_disconnect_return'] ) || ! current_user_can( 'sunshine_manage_options' ) ) {
			return;
		}

		if ( empty( $_GET['status'] ) || empty( $_GET['nonce'] ) || ! wp_verify_nonce( wp_unslash( $_GET['nonce'] ), 'sunshine_stripe_disconnect' ) ) {
			SPC()->notices->add_admin( 'stripe_disconnect_fail', __( 'Stripe could not be disconnected', 'sunshine-photo-cart' ), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=stripe' ) );
			exit;
		}

		if ( isset( $_GET['mode'] ) && $_GET['mode'] == 'live' ) {
			$mode = 'live';
		} else {
			$mode = 'test';
		}

		SPC()->update_option( 'stripe_account_id_' . $mode, '' );
		SPC()->update_option( 'stripe_publishable_key_' . $mode, '' );
		SPC()->update_option( 'stripe_secret_key_' . $mode, '' );

		SPC()->notices->add_admin( 'stripe_disconnected_success', __( 'Stripe has successfully been disconnected', 'sunshine-photo-cart' ), 'success' );

		wp_safe_redirect( admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=stripe' ) );
		exit;

	}


	/* PUBLIC */
	public function init_setup() {

		if ( ! is_sunshine_page( 'checkout' ) || ! $this->is_allowed() ) {
			return;
		}

		$this->setup();
		$this->setup_payment_intent();

	}

	private function setup( $mode = '' ) {
		$this->currency = SPC()->get_option( 'currency' );
	}

	/**
	 * Check if currency is zero-decimal (no decimal places)
	 *
	 * Stripe requires amounts for zero-decimal currencies to be sent as whole numbers
	 * without multiplying by 100.
	 *
	 * Note: HUF, ISK, UGX, TWD are NOT included here despite being conceptually
	 * zero-decimal. Stripe's docs say these should be represented as two-decimal
	 * values for charges (backward compatibility). IDR, LAK, TZS are also excluded
	 * as they are not in Stripe's zero-decimal list.
	 *
	 * @see https://docs.stripe.com/currencies
	 * @return bool True if currency is zero-decimal
	 */
	private function is_zero_decimal_currency() {
		$zero_decimal_currencies = array(
			'BIF', // Burundian Franc
			'CLP', // Chilean Peso
			'DJF', // Djiboutian Franc
			'GNF', // Guinean Franc
			'JPY', // Japanese Yen
			'KMF', // Comorian Franc
			'KRW', // South Korean Won
			'MGA', // Malagasy Ariary
			'PYG', // Paraguayan Guarani
			'RWF', // Rwandan Franc
			'VND', // Vietnamese Dong
			'VUV', // Vanuatu Vatu
			'XAF', // Central African CFA Franc
			'XOF', // West African CFA Franc
			'XPF', // CFP Franc
		);
		return in_array( $this->currency, $zero_decimal_currencies, true );
	}

	/**
	 * Check if currency is three-decimal (three decimal places)
	 *
	 * Stripe requires amounts for three-decimal currencies to be multiplied by 1000
	 * to convert to the smallest currency unit. Three-decimal currencies include:
	 * BHD, JOD, KWD, OMR, TND
	 *
	 * @return bool True if currency is three-decimal
	 */
	private function is_three_decimal_currency() {
		$three_decimal_currencies = array(
			'BHD', // Bahraini Dinar
			'JOD', // Jordanian Dinar
			'KWD', // Kuwaiti Dinar
			'OMR', // Omani Rial
			'TND', // Tunisian Dinar
		);
		return in_array( $this->currency, $three_decimal_currencies, true );
	}

	/**
	 * Convert amount to Stripe format
	 *
	 * Stripe requires amounts in the smallest currency unit:
	 * - Zero-decimal currencies: no conversion (multiply by 1)
	 * - Three-decimal currencies: multiply by 1000
	 * - Two-decimal currencies: multiply by 100 (default)
	 *
	 * @param float $amount Amount in base currency
	 * @return int Amount in Stripe's smallest currency unit
	 */
	private function convert_amount_to_stripe( $amount ) {
		if ( $this->is_zero_decimal_currency() ) {
			return round( $amount );
		}
		if ( $this->is_three_decimal_currency() ) {
			return round( $amount * 1000 );
		}
		return round( $amount * 100 );
	}

	/**
	 * Convert amount from Stripe format
	 *
	 * Stripe returns amounts in the smallest currency unit:
	 * - Zero-decimal currencies: no conversion (divide by 1)
	 * - Three-decimal currencies: divide by 1000
	 * - Two-decimal currencies: divide by 100 (default)
	 *
	 * @param int|float $amount Amount in Stripe's smallest currency unit
	 * @return float Amount in base currency
	 */
	private function convert_amount_from_stripe( $amount ) {
		if ( $this->is_zero_decimal_currency() ) {
			return floatval( $amount );
		}
		if ( $this->is_three_decimal_currency() ) {
			return floatval( $amount / 1000 );
		}
		return floatval( $amount / 100 );
	}

	private function get_publishable_key( $mode = '' ) {
		return ( $mode == 'live' || $this->get_mode_value() == 'live' ) ? SPC()->get_option( $this->id . '_publishable_key_live' ) : SPC()->get_option( $this->id . '_publishable_key_test' );
	}

	private function get_secret_key( $mode = '' ) {
		return ( $mode == 'live' || $this->get_mode_value() == 'live' ) ? SPC()->get_option( $this->id . '_secret_key_live' ) : SPC()->get_option( $this->id . '_secret_key_test' );
	}

	private function get_account_id( $mode = '' ) {
		return ( $mode == 'live' || $this->get_mode_value() == 'live' ) ? SPC()->get_option( $this->id . '_account_id_live' ) : SPC()->get_option( $this->id . '_account_id_test' );
	}

	/**
	 * Get Stripe account details from the API
	 *
	 * Retrieves account name, ID, and other details from Stripe.
	 * Results are cached in a transient for 1 hour to avoid excessive API calls.
	 *
	 * @param string $mode Optional. 'live' or 'test'. Defaults to current mode.
	 * @return array|false Account details array or false on failure
	 */
	private function get_stripe_account_details( $mode = '' ) {
		if ( empty( $mode ) ) {
			$mode = $this->get_mode_value();
		}

		$account_id = $this->get_account_id( $mode );
		if ( empty( $account_id ) ) {
			return false;
		}

		// Check cache first
		$cache_key = 'sunshine_stripe_account_' . md5( $account_id );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached;
		}

		// Need to temporarily set up for the correct mode to get the right secret key
		$this->setup( $mode );

		$response = $this->make_stripe_request( 'accounts/' . $account_id );

		if ( is_wp_error( $response ) ) {
			SPC()->log( 'Failed to get Stripe account details: ' . $response->get_error_message() );
			return false;
		}

		// Extract relevant details
		$details = array(
			'id'    => $response['id'],
			'name'  => '',
			'email' => ! empty( $response['email'] ) ? $response['email'] : '',
		);

		// Try to get the business name from various places
		if ( ! empty( $response['business_profile']['name'] ) ) {
			$details['name'] = $response['business_profile']['name'];
		} elseif ( ! empty( $response['settings']['dashboard']['display_name'] ) ) {
			$details['name'] = $response['settings']['dashboard']['display_name'];
		} else {
			// Fallback to account ID if no name available
			$details['name'] = $response['id'];
		}

		// Cache for 1 hour
		set_transient( $cache_key, $details, HOUR_IN_SECONDS );

		return $details;
	}

	private function get_payment_intent_id() {
		if ( empty( $this->payment_intent_id ) ) {
			$this->payment_intent_id = SPC()->session->get( 'stripe_payment_intent_id' );
		}
		return $this->payment_intent_id;
	}

	private function set_payment_intent_id( $payment_intent_id ) {
		$this->payment_intent_id = $payment_intent_id;
		SPC()->session->set( 'stripe_payment_intent_id', $payment_intent_id );
	}

	private function get_client_secret() {
		if ( empty( $this->client_secret ) ) {
			$this->client_secret = SPC()->session->get( 'stripe_client_secret' );
		}
		return $this->client_secret;
	}

	private function set_client_secret( $client_secret ) {
		$this->client_secret = $client_secret;
		SPC()->session->set( 'stripe_client_secret', $client_secret );
	}

	private function get_webhook_secret() {
		return SPC()->get_option( 'stripe_webhook_secret_' . $this->get_mode_value() );
	}

	public function enqueue_scripts() {

		if ( ! is_sunshine_page( 'checkout' ) || empty( $this->get_publishable_key() ) || empty( $this->get_account_id() ) ) {
			return false;
		}

		// Only load Stripe JS for inline checkout mode
		$checkout_mode = $this->get_option( 'checkout_mode' );
		if ( $checkout_mode === 'hosted' ) {
			return; // No JS needed for hosted checkout - redirect handles everything
		}

		wp_enqueue_script( 'sunshine-stripe', 'https://js.stripe.com/v3/' );
		wp_enqueue_script( 'sunshine-stripe-processing', SUNSHINE_PHOTO_CART_URL . 'assets/js/stripe-processing.js', array( 'jquery' ), SUNSHINE_PHOTO_CART_VERSION, true );
		wp_localize_script(
			'sunshine-stripe-processing',
			'spc_stripe_vars',
			array(
				'publishable_key' => $this->get_publishable_key(),
				'account_id'      => $this->get_account_id(),
				'layout'          => ( $this->get_option( 'layout' ) ) ? $this->get_option( 'layout' ) : 'tabs',
				'return_url'      => sunshine_get_page_url( 'checkout' ) . '?section=payment&stripe_payment_return',
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'security'        => wp_create_nonce( 'sunshine_stripe' ),
				'strings'         => array(
					'elements_not_available'        => __( 'Payment form not properly initialized. Please refresh the page and try again.', 'sunshine-photo-cart' ),
					'payment_element_not_available' => __( 'Payment form not properly initialized. Please refresh the page and try again.', 'sunshine-photo-cart' ),
					'payment_element_not_mounted'   => __( 'Payment form not properly mounted. Please refresh the page and try again.', 'sunshine-photo-cart' ),
					'payment_intent_not_created'    => __( 'Payment intent not created. Please refresh the page and try again.', 'sunshine-photo-cart' ),
					'payment_not_processed'         => __( 'Payment was not processed, please try again', 'sunshine-photo-cart' ),
					'payment_did_not_succeed'       => __( 'Payment did not succeed', 'sunshine-photo-cart' ),
					'payment_processing_failed'     => __( 'Payment processing failed:', 'sunshine-photo-cart' ),
				),
			)
		);

	}

	/**
	 * Set up Stripe payment intent
	 *
	 * Creates or updates a Stripe payment intent with current cart data.
	 * Handles idempotency key conflicts and retries.
	 *
	 * @return void
	 */
	public function setup_payment_intent() {

		// Prevent creating new payment intent during active payment processing
		// This guards against race conditions where payment method changes during checkout
		if ( doing_action( 'sunshine_checkout_process_payment_stripe' ) ) {
			return;
		}

		if ( empty( SPC()->cart ) ) {
			SPC()->cart->setup();
		}

		// Set the cart total in Stripe's smallest currency unit
		$cart_total = SPC()->cart->get_total();
		if ( $cart_total <= 0 || SPC()->cart->is_empty() ) {
			return; // Don't create if there is no amount to charge yet or if the cart is empty.
		}

		$this->total = $this->convert_amount_to_stripe( $cart_total );
		sunshine_log( 'Setting up Stripe payment intent for amount: ' . $this->total . ' (' . $cart_total . ' in base currency)' );

		// CRITICAL: Check if existing payment intent was already used for a completed order
		// This prevents stale payment intents from being reused after successful orders.
		$existing_intent_id = $this->get_payment_intent_id();
		if ( ! empty( $existing_intent_id ) ) {
			$existing_order = $this->get_order_by_payment_intent( $existing_intent_id );
			if ( $existing_order && $existing_order->is_paid() ) {
				SPC()->log( 'Clearing stale payment intent ' . $existing_intent_id . ' that was already used for paid order ' . $existing_order->get_id() );
				$this->set_payment_intent_id( '' );
				$this->set_client_secret( '' );
				SPC()->session->set( 'stripe_idempotency_key', '' );
			}
		}

		// Get or create Stripe customer for both logged-in users and guests
		$stripe_customer_id = $this->get_stripe_customer_id();

		if ( $stripe_customer_id ) {
			// Validate existing customer ID
			$response = $this->make_stripe_request( "customers/$stripe_customer_id" );
			if ( is_wp_error( $response ) ) {
				// Handle error, unset customer ID if not found or other API issue
				SPC()->log( 'Stripe customer error: ' . $response->get_error_message() );
				$this->set_stripe_customer_id( '' );
				$stripe_customer_id = '';
			} else {
				SPC()->log( 'Using existing Stripe customer ID: ' . $stripe_customer_id );
			}
		}

		// If no customer ID, create one (for both logged-in users and guests)
		if ( empty( $stripe_customer_id ) ) {
			$stripe_customer_id = $this->create_stripe_customer();
			if ( ! $stripe_customer_id ) {
				SPC()->log( 'Failed to create Stripe customer' );
			}
		}

		$order_id = SPC()->session->get( 'checkout_order_id' );

		// Set up payment intent
		if ( empty( $this->get_payment_intent_id() ) ) {
			SPC()->log( 'Creating new payment intent...' );

			$args = $this->build_payment_intent_args( $stripe_customer_id, $order_id );

			// Generate idempotency key to prevent duplicate charges
			$idempotency_key = SPC()->session->get( 'stripe_idempotency_key' );
			if ( empty( $idempotency_key ) ) {
				$idempotency_key = $this->generate_idempotency_key();
				SPC()->session->set( 'stripe_idempotency_key', $idempotency_key );
			}
			$intent = $this->create_payment_intent_with_retry( $args, $idempotency_key );

			if ( is_wp_error( $intent ) ) {
				SPC()->log( 'Failed creating payment intent: ' . $intent->get_error_message() );
				return;
			}

			SPC()->log( 'Created new payment intent: ' . $intent['id'] );
			SPC()->session->set( 'stripe_payment_intent_id', $intent['id'] );
			$this->set_payment_intent_id( $intent['id'] );
			$this->client_secret = $intent['client_secret'];
			return;
		} else {
			// Check if customer ID has changed - if so, need to create new payment intent
			$existing_intent = $this->make_stripe_request( "payment_intents/$this->payment_intent_id" );
			if ( ! is_wp_error( $existing_intent ) && ! empty( $existing_intent['customer'] ) ) {
				if ( $existing_intent['customer'] != $stripe_customer_id ) {
					SPC()->log( 'Stripe customer changed from ' . $existing_intent['customer'] . ' to ' . $stripe_customer_id . ', creating new payment intent' );

					// Cancel the old payment intent
					$cancel_result = $this->make_stripe_request( "payment_intents/$this->payment_intent_id/cancel", array(), 'POST' );
					if ( is_wp_error( $cancel_result ) ) {
						SPC()->log( 'Warning: Could not cancel old payment intent: ' . $cancel_result->get_error_message() );
					} else {
						SPC()->log( 'Cancelled old payment intent: ' . $this->payment_intent_id );
					}

					// Clear old payment intent data
					$this->set_payment_intent_id( '' );
					SPC()->session->set( 'stripe_payment_intent_id', '' );
					SPC()->session->set( 'stripe_idempotency_key', '' );

					// Create new payment intent
					$args            = $this->build_payment_intent_args( $stripe_customer_id, $order_id );
					$idempotency_key = $this->generate_idempotency_key();
					SPC()->session->set( 'stripe_idempotency_key', $idempotency_key );

					$intent = $this->create_payment_intent_with_retry( $args, $idempotency_key );

					if ( is_wp_error( $intent ) ) {
						SPC()->log( 'Failed creating new payment intent: ' . $intent->get_error_message() );
						return;
					}

					SPC()->log( 'Created new payment intent with updated customer: ' . $intent['id'] );
					SPC()->session->set( 'stripe_payment_intent_id', $intent['id'] );
					$this->set_payment_intent_id( $intent['id'] );
					$this->client_secret = $intent['client_secret'];
					return;
				}
			}

			// Update existing payment intent (customer hasn't changed)
			$args = $this->build_payment_intent_args( $stripe_customer_id, $order_id, true );

			$intent = $this->make_stripe_request( "payment_intents/$this->payment_intent_id", $args, 'POST' );

			if ( is_wp_error( $intent ) ) {
				SPC()->log( 'Failed updating payment intent: ' . $intent->get_error_message() );
				return;
			}

			if ( isset( $intent['error'] ) ) {
				SPC()->log( 'Stripe API error updating payment intent (' . $this->payment_intent_id . '): ' . $intent['error']['message'] );
				if ( isset( $intent['error']['code'] ) && $intent['error']['code'] == 'payment_intent_unexpected_state' && $intent['error']['payment_intent']['status'] == 'succeeded' ) {
					// Payment has already succeeded.
					$current_checkout_order_id = SPC()->session->get( 'checkout_order_id' );
					if ( ! empty( $current_checkout_order_id ) ) {
						// If already succeeded and we have a checkout order id, then we actually need to process it and redirect to the order.
						$order = sunshine_get_order( $current_checkout_order_id );
						if ( $order->exists() && $order->get_payment_method() == $this->id && ! $order->is_paid() ) {
							SPC()->log( 'Found checkout order ID and payment intent succeeded, so processing order...' );
							SPC()->cart->process_order();
							wp_safe_redirect( $order->get_received_permalink() );
							exit;
						}
					}
				}
				$this->set_payment_intent_id( '' );
				$this->set_client_secret( '' );
				$this->setup_payment_intent();
				return;
			} elseif ( $intent['status'] != 'requires_payment_method' ) {
				SPC()->log( 'Stripe payment intent invalid status, resetting (' . $this->payment_intent_id . ')' );
				$this->set_payment_intent_id( '' );
				$this->set_client_secret( '' );
				$this->setup_payment_intent();
				return;
			}
			SPC()->log( 'Updated Stripe payment intent: ' . $intent['id'] );
			$this->set_payment_intent_id( $intent['id'] );
			$this->set_client_secret( $intent['client_secret'] );
			return;
		}

	}


	public function create_payment_intent() {

		// Testing: Add delay if test_delay parameter is provided
		if ( ! empty( $_POST['test_delay'] ) && is_numeric( $_POST['test_delay'] ) ) {
			$delay = intval( $_POST['test_delay'] );
			if ( $delay > 0 && $delay <= 60 ) { // Max 60 seconds for safety
				sleep( $delay );
			}
		}

		$this->setup();
		$this->setup_payment_intent();
		if ( empty( $this->payment_intent_id ) ) {
			SPC()->log( 'Failed creating Stripe payment intent' );
			wp_send_json_error();
		}
		wp_send_json_success(
			array(
				'payment_intent_id' => $this->payment_intent_id,
				'client_secret'     => $this->client_secret,
			)
		);

	}

	public function create_stripe_customer() {

		SPC()->log( 'Creating Stripe customer' );

		if ( is_user_logged_in() ) {
			$email = SPC()->customer->get_email();
		} else {
			$email = SPC()->cart->get_checkout_data_item( 'email' );
		}

		// Check Stripe API to see if we have a customer ID for this email address already
		if ( ! empty( $email ) ) {
			$customer = $this->make_stripe_request( 'customers', array( 'email' => $email ) );

			if ( is_wp_error( $customer ) ) {
				SPC()->log( 'Failed getting Stripe customer: ' . $customer->get_error_message() );
				return;
			}

			if ( ! empty( $customer['data'] ) && ! empty( $customer['data'][0]['id'] ) ) {
				SPC()->log( 'Found existing Stripe customer: ' . $customer['data'][0]['id'] );
				$this->set_stripe_customer_id( $customer['data'][0]['id'] );
				return $customer['data'][0]['id'];
			}
		}

		if ( is_user_logged_in() ) {
			SPC()->log( 'Creating Stripe customer for logged in user' );
			$args = array(
				'email'    => SPC()->customer->get_email(),
				'name'     => SPC()->customer->get_name(),
				'shipping' => array(
					'name'    => SPC()->customer->get_name(),
					'address' => array(
						'city'        => SPC()->customer->get_shipping_city(),
						'country'     => SPC()->customer->get_shipping_country(),
						'line1'       => SPC()->customer->get_shipping_address(),
						'line2'       => SPC()->customer->get_shipping_address2(),
						'postal_code' => SPC()->customer->get_shipping_postcode(),
						'state'       => SPC()->customer->get_shipping_state(),
					),
				),
			);
		} else {
			$email = SPC()->cart->get_checkout_data_item( 'email' );
			if ( empty( $email ) ) {
				SPC()->log( 'No email found in checkout data, cannot create Stripe customer yet' );
				return false;
			}

			// Look up past orders by this email address to see if we have a Stripe customer id in one of them
			$orders = sunshine_get_orders(
				array(
					'meta_query' => array(
						array(
							'key'   => 'email',
							'value' => $email,
						),
					),
				)
			);

			if ( ! empty( $orders ) ) {
				foreach ( $orders as $order ) {
					$stripe_customer_id = $order->get_meta_value( 'stripe_customer_id' );
					if ( ! empty( $stripe_customer_id ) ) {
						SPC()->log( 'Found Stripe customer ID in past order: ' . $stripe_customer_id );

						// Check if it is a valid customer ID.
						$stripe_customer = $this->make_stripe_request( "customers/$stripe_customer_id" );
						if ( is_wp_error( $stripe_customer ) ) {
							SPC()->log( 'Stripe customer error: ' . $stripe_customer->get_error_message() );
							break;
						}

						// Verify this customer's email matches (prevent using wrong customer)
						if ( isset( $stripe_customer['email'] ) && $stripe_customer['email'] !== $email ) {
							SPC()->log( 'WARNING: Stripe customer email mismatch! Expected: ' . $email . ', Got: ' . $stripe_customer['email'] );
							SPC()->log( 'NOT reusing this customer ID, will create new one' );
							break;
						}

						SPC()->log( 'Reusing Stripe customer ID ' . $stripe_customer_id . ' for guest email ' . $email );
						$this->set_stripe_customer_id( $stripe_customer_id );
						return $stripe_customer_id;
					}
				}
			}

			SPC()->log( 'Creating Stripe customer for guest user' );
			$args = array(
				'email'    => $email,
				'name'     => SPC()->cart->get_checkout_data_item( 'first_name' ) . ' ' . SPC()->cart->get_checkout_data_item( 'last_name' ),
				'shipping' => $this->build_shipping_address(),
			);
		}

		$customer = $this->make_stripe_request( 'customers', $args, 'POST' );

		if ( is_wp_error( $customer ) ) {
			SPC()->log( 'Failed creating Stripe customer: ' . $customer->get_error_message() );
			return;
		}

		SPC()->log( 'Created Stripe customer: ' . $customer['id'] );
		$this->set_stripe_customer_id( $customer['id'] );
		return $customer['id'];
	}

	public function init_order( $order ) {

		if ( $order->get_payment_method() != $this->id ) {
			return;
		}

		$payment_intent_id = $this->get_payment_intent_id();

		if ( ! empty( $payment_intent_id ) ) {
			SPC()->log( 'init_order: Updating order meta with Stripe payment intent ID: ' . $payment_intent_id );
			$order->update_meta_value( 'stripe_payment_intent_id', $payment_intent_id );
			$order->update_meta_value( 'stripe_customer_id', $this->get_stripe_customer_id() );
		} else {
			SPC()->log( 'init_order: No Stripe payment intent ID found, cannot update order meta' );
		}

	}

	public function checkout_post_process_order( $do_post_process, $order, $data ) {
		if ( $data['payment_method'] == $this->id && $this->get_webhook_secret() ) {
			SPC()->log( 'Stripe webhook secret is set, so not doing post process' );
			return false;
		}
		return $do_post_process;
	}

	/**
	 * Filter order status for hosted checkout mode
	 *
	 * For hosted checkout, order stays as 'pending' until the webhook confirms
	 * payment, then it becomes 'new'.
	 *
	 * @param string $status The order status
	 * @param object $order The order object
	 * @return string The filtered order status
	 */
	public function create_order_status( $status, $order ) {
		if ( $order->get_payment_method() === $this->id ) {
			return 'new';
		}
		return $status;
	}

	/**
	 * Create a Stripe Checkout Session for hosted checkout
	 *
	 * @param SPC_Order $order The order object
	 * @return array|WP_Error Checkout session data or error
	 */
	private function create_checkout_session( $order ) {
		$this->setup();

		// Get or create Stripe customer (required for Accounts v2)
		$stripe_customer_id = $this->get_stripe_customer_id();
		if ( empty( $stripe_customer_id ) ) {
			$stripe_customer_id = $this->create_stripe_customer();
		}

		$line_items     = array();
		$tax_mode       = $this->get_option( 'tax_mode' );
		$use_stripe_tax = ( $tax_mode === 'stripe' );

		// Build line items from order
		foreach ( $order->get_items() as $item ) {
			$line_item = array(
				'price_data' => array(
					'currency'     => strtolower( $this->currency ),
					'product_data' => array(
						'name' => $item->get_name_raw(),
					),
					'unit_amount'  => $this->convert_amount_to_stripe( $item->get_price() - $item->get_discount_per_item() ),
				),
				'quantity'   => $item->get_qty(),
			);

			// If using Stripe Tax, set tax behavior
			if ( $use_stripe_tax ) {
				$line_item['price_data']['tax_behavior'] = 'exclusive';
			}

			$line_items[] = $line_item;
		}

		// Add shipping if applicable
		if ( $order->get_shipping() > 0 ) {
			$shipping_item = array(
				'price_data' => array(
					'currency'     => strtolower( $this->currency ),
					'product_data' => array(
						'name' => $order->get_shipping_method_name() ? $order->get_shipping_method_name() : __( 'Shipping', 'sunshine-photo-cart' ),
					),
					'unit_amount'  => $this->convert_amount_to_stripe( $order->get_shipping() ),
				),
				'quantity'   => 1,
			);

			if ( $use_stripe_tax ) {
				$shipping_item['price_data']['tax_behavior'] = 'exclusive';
			}

			$line_items[] = $shipping_item;
		}

		// Add fees (e.g. payment gateway fee) as line items
		$fees = $order->get_fees();
		if ( ! empty( $fees ) ) {
			foreach ( $fees as $fee ) {
				$line_items[] = array(
					'price_data' => array(
						'currency'     => strtolower( $this->currency ),
						'product_data' => array(
							'name' => $fee['name'],
						),
						'unit_amount'  => $this->convert_amount_to_stripe( $fee['amount'] ),
					),
					'quantity'   => 1,
				);
			}
		}

		// Add tax as line item ONLY if NOT using Stripe Tax
		if ( ! $use_stripe_tax && $order->get_tax() > 0 ) {
			$line_items[] = array(
				'price_data' => array(
					'currency'     => strtolower( $this->currency ),
					'product_data' => array(
						'name' => __( 'Tax', 'sunshine-photo-cart' ),
					),
					'unit_amount'  => $this->convert_amount_to_stripe( $order->get_tax() ),
				),
				'quantity'   => 1,
			);
		}

		$args = array(
			'mode'                => 'payment',
			'success_url'         => add_query_arg( 'stripe_checkout_complete', '1', $order->get_received_permalink() ),
			'cancel_url'          => wp_nonce_url(
				add_query_arg( 'order_id', $order->get_id(), sunshine_get_page_url( 'checkout' ) ),
				'stripe_cancel',
				'stripe_cancel'
			),
			'line_items'          => $line_items,
			'metadata'            => array(
				'order_id'   => $order->get_id(),
				'order_name' => $order->get_name(),
				'site'       => get_bloginfo( 'name' ),
			),
			'payment_intent_data' => array(
				'metadata' => array(
					'order_id'   => $order->get_id(),
					'order_name' => $order->get_name(),
					'site'       => get_bloginfo( 'name' ),
				),
			),
		);

		// Pass customer ID if available, fall back to email
		if ( ! empty( $stripe_customer_id ) ) {
			$args['customer'] = $stripe_customer_id;
		} else {
			$args['customer_email'] = $order->get_email();
		}

		// Enable Stripe Tax if selected
		if ( $use_stripe_tax ) {
			$args['automatic_tax'] = array( 'enabled' => 'true' );
			// Store that we're using Stripe Tax so webhook knows to update order tax
			$order->update_meta_value( 'stripe_tax_mode', 'stripe' );
		}

		// Add statement descriptor if configured
		$statement_descriptor = $this->get_option( 'statement_descriptor' );
		if ( ! empty( $statement_descriptor ) ) {
			$sanitized_descriptor = preg_replace( '/[^A-Za-z0-9 ]/', '', $statement_descriptor );
			$sanitized_descriptor = substr( $sanitized_descriptor, 0, 22 );
			if ( strlen( $sanitized_descriptor ) >= 5 ) {
				$args['payment_intent_data']['statement_descriptor'] = $sanitized_descriptor;
			}
		}

		// Add application fee if applicable
		if ( $this->get_application_fee_percent() > 0 ) {
			$fee_amount = round( $order->get_total() * ( $this->get_application_fee_percent() / 100 ), 2 );
			$args['payment_intent_data']['application_fee_amount'] = $this->convert_amount_to_stripe( $fee_amount );
		}

		$response = $this->make_stripe_request( 'checkout/sessions', $args, 'POST' );

		if ( is_wp_error( $response ) ) {
			SPC()->log( 'Stripe Checkout Session creation failed: ' . $response->get_error_message() );
			return $response;
		}

		// Store session ID in order meta
		$order->update_meta_value( 'stripe_checkout_session_id', $response['id'] );
		SPC()->log( 'Stripe Checkout Session created: ' . $response['id'] );

		return $response;
	}

	/**
	 * Process hosted checkout - redirect to Stripe
	 *
	 * @param SPC_Order $order The order object
	 * @return void
	 */
	public function process_payment_hosted( $order ) {
		// Create Checkout Session
		$session = $this->create_checkout_session( $order );

		if ( is_wp_error( $session ) ) {
			SPC()->cart->add_error( $session->get_error_message() );
			wp_safe_redirect( sunshine_get_page_url( 'checkout' ) );
			exit;
		}

		// Output redirect page (similar to PayPal Legacy)
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<title><?php esc_html_e( 'Redirecting to Stripe', 'sunshine-photo-cart' ); ?>...</title>
			<style>
				body, html { margin: 0; padding: 50px; background: #FFF; }
				h1 { color: #000; text-align: center; font-family: Arial; font-size: 24px; }
			</style>
		</head>
		<body>
			<h1><?php esc_html_e( 'Redirecting to Stripe', 'sunshine-photo-cart' ); ?>...</h1>
			<script>
				window.location.href = '<?php echo esc_url( $session['url'] ); ?>';
			</script>
			<noscript>
				<p style="text-align: center;"><a href="<?php echo esc_url( $session['url'] ); ?>"><?php esc_html_e( 'Click here to continue to payment', 'sunshine-photo-cart' ); ?></a></p>
			</noscript>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Process payment after successful Stripe payment
	 *
	 * Updates order with payment information and metadata from Stripe.
	 *
	 * @param SPC_Order $order The order object
	 * @return void
	 */
	public function process_payment( $order ) {

		// Check if we're using hosted checkout mode
		$checkout_mode = $this->get_option( 'checkout_mode' );
		if ( $checkout_mode === 'hosted' ) {
			$this->process_payment_hosted( $order );
			return;
		}

		// At this point we already have a paid order from the Stripe JS and we are just updating the order with info.
		SPC()->log( 'Processing Stripe payment for order: ' . $order->get_id() );

		$this->setup();

		// Get payment intent ID from POST data (added by JS), not session
		// This prevents race conditions where session might be cleared or changed
		$payment_intent_id = ! empty( $_POST['stripe_payment_intent_id'] ) ? sanitize_text_field( $_POST['stripe_payment_intent_id'] ) : '';

		// Fallback to session if not in POST (for backwards compatibility)
		if ( empty( $payment_intent_id ) ) {
			$payment_intent_id = $this->get_payment_intent_id();
		}

		if ( empty( $payment_intent_id ) ) {
			SPC()->log( 'No payment intent ID found - cannot process payment' );
			SPC()->cart->add_error( __( 'Could not complete order, contact site owner for more details', 'sunshine-photo-cart' ) );
			return;
		}

		// CRITICAL: Check if this payment intent is already associated with a different order
		// This prevents the scenario where a stale payment intent from a previous order
		// is reused for a new order, resulting in an unpaid order being marked as paid.
		$existing_order = $this->get_order_by_payment_intent( $payment_intent_id );
		if ( $existing_order && $existing_order->get_id() != $order->get_id() ) {
			SPC()->log( 'Payment intent ' . $payment_intent_id . ' already used for order ' . $existing_order->get_id() . ', current order is ' . $order->get_id() );
			SPC()->cart->add_error( __( 'This payment has already been processed for a different order. Please refresh the page and try again.', 'sunshine-photo-cart' ) );
			return;
		}

		// Verify payment intent succeeded in Stripe BEFORE proceeding
		$payment_intent_object = $this->make_stripe_request( "payment_intents/$payment_intent_id" );

		if ( is_wp_error( $payment_intent_object ) ) {
			SPC()->log( 'Error getting the Stripe payment intent (' . $payment_intent_id . '): ' . $payment_intent_object->get_error_message() );
			SPC()->cart->add_error( __( 'Could not complete order, contact site owner for more details', 'sunshine-photo-cart' ) );
			return;
		}

		// Verify the payment actually succeeded
		if ( empty( $payment_intent_object['status'] ) || $payment_intent_object['status'] !== 'succeeded' ) {
			SPC()->log( 'Stripe payment intent status is not succeeded: ' . ( $payment_intent_object['status'] ?? 'unknown' ) );
			SPC()->cart->add_error( __( 'Payment was not successful, please try again', 'sunshine-photo-cart' ) );
			return;
		}

		SPC()->log( 'Stripe payment intent verified as succeeded: ' . $payment_intent_id );

		$order->update_meta_value( 'paid_date', current_time( 'timestamp' ) );

		if ( ! empty( $payment_intent_object['source'] ) ) {
			$order->update_meta_value( 'source', sanitize_text_field( $payment_intent_object['source'] ) );
		}
		if ( ! empty( $payment_intent_object['application_fee_amount'] ) ) {
			$order->update_meta_value( 'application_fee_amount', $this->convert_amount_from_stripe( $payment_intent_object['application_fee_amount'] ) );
		}

		// Continue to update metadata in Stripe.
		SPC()->log( 'Updating metadata in Stripe for payment' );
		$args = array(
			'metadata[order_id]'   => $order->get_id(),
			'metadata[order_name]' => $order->get_name(),
			'metadata[site]'       => get_bloginfo( 'name' ),
			'metadata[order_url]'  => admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
			'description'          => $order->get_name() . ', ' . $order->get_customer_name(),
		);
		$args = apply_filters( 'sunshine_stripe_payment_intent_args', $args, $order );

		$updated_intent = $this->make_stripe_request( "payment_intents/$payment_intent_id", $args, 'POST' );

		if ( is_wp_error( $updated_intent ) ) {
			SPC()->log( 'Failed to perform update payment intent (' . $payment_intent_id . '): ' . $updated_intent->get_error_message() );
			SPC()->cart->add_error( __( 'Could not complete order, contact site owner for more details', 'sunshine-photo-cart' ) );
			return;
		}

		// Only clear session data after everything succeeded
		// This prevents race conditions where session is cleared before payment is fully verified
		$this->set_payment_intent_id( '' );
		$this->set_client_secret( '' );
		SPC()->session->set( 'stripe_idempotency_key', '' );
		// Clear guest customer ID for next checkout (logged-in users keep theirs in user meta)
		if ( ! is_user_logged_in() ) {
			SPC()->session->set( 'stripe_customer_id', '' );
		}
		SPC()->log( 'Stripe payment processing complete' );

		// DEBUG: Add artificial delay to test race condition
		if ( isset( $_GET['test_race_condition'] ) ) {
			$delay = intval( $_GET['test_race_condition'] );
			if ( $delay > 0 && $delay <= 30 ) {
				SPC()->log( '!!! TESTING RACE CONDITION: Sleeping for ' . $delay . ' seconds !!!' );
				sleep( $delay );
				SPC()->log( '!!! Sleep complete, continuing to redirect !!!' );
			}
		}

	}


	public function payment_return() {

		if ( ! isset( $_GET['stripe_payment_return'] ) || ! isset( $_GET['payment_intent'] ) || ! isset( $_GET['payment_intent_client_secret'] ) ) {
			return false;
		}

		SPC()->log( 'Returned from Stripe after async payment' );

		// Pause for a couple seconds if we are waiting on the webhook to complete.
		sleep( 2 );

		$payment_intent_id = sanitize_text_field( $_GET['payment_intent'] );
		$client_secret     = sanitize_text_field( $_GET['payment_intent_client_secret'] );

		$payment_intent_object = $this->make_stripe_request( "payment_intents/$payment_intent_id" );

		if ( is_wp_error( $payment_intent_object ) ) {
			SPC()->log( 'Check payment intent on checkout error: ' . $payment_intent_object->get_error_message() );
			return;
		}

		$order = $this->get_order_by_payment_intent( $payment_intent_id );
		if ( ! $order || ! $order->exists() ) {
			SPC()->log( 'Could not find order by payment intent in payment return: ' . $payment_intent_id );
			return;
		}

		if ( ! empty( $payment_intent_object['status'] ) && $payment_intent_object['status'] == 'succeeded' ) {
			$order = SPC()->cart->process_order();
			if ( $order ) {
				$url = apply_filters( 'sunshine_checkout_redirect', $order->get_received_permalink() );
				SPC()->log( 'Created new order after stripe asynchronous payment and is redirecting' );
				wp_safe_redirect( $url );
				exit;
			}
		}

		$order->set_status( 'failed' );
		$order->add_log( 'Order failed from stripe asynchronous payment' );
		SPC()->notices->add( __( 'Could not process order, please try another payment method', 'sunshine-photo-cart' ), 'error' );
		wp_safe_redirect( sunshine_get_page_url( 'checkout' ) );
		exit;

	}

	/**
	 * Handle successful return from Stripe hosted checkout
	 *
	 * Clears cart/session and redirects to clean order URL.
	 * The actual order completion is handled by the webhook.
	 *
	 * @return void
	 */
	public function stripe_checkout_return() {
		if ( ! isset( $_GET['stripe_checkout_complete'] ) ) {
			return;
		}

		SPC()->log( 'Returned from Stripe hosted checkout' );

		// Clear session data
		SPC()->session->set( 'checkout_data', '' );
		SPC()->session->set( 'checkout_sections_completed', '' );
		SPC()->session->set( 'checkout_order_id', '' );
		SPC()->cart->empty_cart();

		// Wait briefly for webhook to process (similar to PayPal)
		sleep( 3 );

		// Remove query param and redirect to clean order received URL
		$url = remove_query_arg( 'stripe_checkout_complete' );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handle cancellation from Stripe hosted checkout
	 *
	 * Expires the checkout session, deletes the order, and returns to checkout.
	 *
	 * @return void
	 */
	public function stripe_checkout_cancel() {
		if ( ! isset( $_GET['stripe_cancel'] ) || ! wp_verify_nonce( $_GET['stripe_cancel'], 'stripe_cancel' ) ) {
			return;
		}

		if ( ! isset( $_GET['order_id'] ) ) {
			return;
		}

		$order_id = intval( $_GET['order_id'] );
		$order    = sunshine_get_order( $order_id );

		SPC()->log( 'Stripe hosted checkout cancelled for order: ' . $order_id );

		if ( $order->exists() ) {
			// Try to expire the Checkout Session if it exists
			$session_id = $order->get_meta_value( 'stripe_checkout_session_id' );
			if ( $session_id ) {
				$this->setup();
				$result = $this->make_stripe_request( 'checkout/sessions/' . $session_id . '/expire', array(), 'POST' );
				if ( is_wp_error( $result ) ) {
					SPC()->log( 'Could not expire Stripe checkout session: ' . $result->get_error_message() );
				}
			}

			// Delete the order
			$order->delete( true );
		}

		SPC()->cart->add_error( __( 'Stripe payment has been cancelled', 'sunshine-photo-cart' ) );
		wp_safe_redirect( sunshine_get_page_url( 'checkout' ) );
		exit;
	}

	/**
	 * Process Stripe webhook events
	 *
	 * Handles incoming webhook events from Stripe, verifies signatures,
	 * and processes payment_intent.succeeded events.
	 *
	 * @return void
	 */
	public function process_webhook() {

		if ( ! isset( $_GET['sunshine_stripe_webhook'] ) ) {
			return;
		}

		$payload        = file_get_contents( 'php://input' );
		$sig_header     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
		$webhook_secret = $this->get_webhook_secret();

		SPC()->log( 'Processing Stripe webhook with payload: ' . print_r( $payload, true ) );

		if ( empty( $webhook_secret ) ) {
			SPC()->log( 'Failed webhook check, missing webhook secret' );
			status_header( 400 );
			exit;
		}

		if ( empty( $payload ) ) {
			SPC()->log( 'Failed webhook check, missing payload' );
			status_header( 400 );
			exit;
		}

		if ( empty( $sig_header ) ) {
			SPC()->log( 'Failed webhook check, missing signature header' );
			status_header( 400 );
			exit;
		}

		// Parse the Stripe-Signature header
		$parts = array();
		foreach ( explode( ',', $sig_header ) as $part ) {
			list( $k, $v )       = explode( '=', $part, 2 );
			$parts[ trim( $k ) ] = $v;
		}

		$timestamp = $parts['t'] ?? '';
		$signature = $parts['v1'] ?? '';

		// Compute expected signature
		$signed_payload = $timestamp . '.' . $payload;
		$expected_sig   = hash_hmac( 'sha256', $signed_payload, $webhook_secret );

		// Compare securely
		if ( ! hash_equals( $expected_sig, $signature ) ) {
			SPC()->log( 'Failed webhook signature check' );
			status_header( 400 );
			exit;
		}

		// Webhook is verified — process it
		$event      = json_decode( $payload, true );
		$event_type = $event['type'] ?? '';

		// Handle checkout.session.completed (for hosted checkout mode)
		if ( $event_type === 'checkout.session.completed' ) {
			$session  = $event['data']['object'];
			$order_id = $session['metadata']['order_id'] ?? null;

			if ( ! $order_id ) {
				SPC()->log( 'Stripe Webhook: No order_id in checkout session metadata' );
				status_header( 400 );
				exit;
			}

			$order = sunshine_get_order( $order_id );
			if ( ! $order || ! $order->exists() ) {
				SPC()->log( 'Stripe Webhook: Order not found: ' . $order_id );
				status_header( 404 );
				exit;
			}

			SPC()->log( 'Processing Stripe webhook: checkout.session.completed for session ' . $session['id'] . ' and order ' . $order->get_id() );

			$order->add_log( 'Stripe webhook: checkout.session.completed for session ' . $session['id'] );

			// Store payment intent ID from session
			if ( ! empty( $session['payment_intent'] ) ) {
				$order->update_meta_value( 'stripe_payment_intent_id', $session['payment_intent'] );
			}

			// Check if Stripe Tax was used and update order tax accordingly
			$stripe_tax_mode = $order->get_meta_value( 'stripe_tax_mode' );
			if ( $stripe_tax_mode === 'stripe' ) {
				$total_details = $session['total_details'] ?? array();
				if ( isset( $total_details['amount_tax'] ) ) {
					$stripe_tax = $this->convert_amount_from_stripe( $total_details['amount_tax'] );
					$order->set_tax( $stripe_tax );
					$order->update_meta_value( 'stripe_calculated_tax', $stripe_tax );
					SPC()->log( 'Stripe Webhook: Updated order tax from Stripe Tax: ' . $stripe_tax );
				}
			}

			// Update order metadata
			$order->update_meta_value( 'paid_date', current_time( 'timestamp' ) );
			$order->update_meta_value( 'source', 'stripe_hosted_checkout' );

			// Store Stripe customer ID if available
			if ( ! empty( $session['customer'] ) ) {
				$order->update_meta_value( 'stripe_customer_id', $session['customer'] );
			}

			wp_update_post(
				array(
					'ID'        => $order->get_id(),
					'post_date' => current_time( 'mysql' ),
				)
			);

			// Post-process order (sends emails, updates stats, etc.)
			SPC()->cart->post_process_order( $order );

			SPC()->log( 'Stripe Webhook: Order ' . $order->get_name() . ' processed via checkout.session.completed' );
			status_header( 200 );
			exit;
		}

		// Handle payment_intent.succeeded (for inline checkout mode)
		if ( $event_type === 'payment_intent.succeeded' ) {
			$payment_intent = $event['data']['object'];
			$order          = $this->get_order_by_payment_intent( $payment_intent['id'] );

			if ( ! $order || ! $order->exists() ) {
				SPC()->log( 'Could not find order by payment intent in webhook: ' . $payment_intent['id'] );
				status_header( 400 );
				exit;
			}

			SPC()->log( 'Processing Stripe webhook: payment_intent.succeeded for ' . $payment_intent['id'] . ' and order ' . $order->get_id() );

			$order->add_log( 'Stripe webhook: payment_intent.succeeded for ' . $payment_intent['id'] );

			SPC()->cart->post_process_order( $order );

			$order->update_meta_value( 'paid_date', current_time( 'timestamp' ) );
			$order->update_meta_value( 'stripe_customer_id', $payment_intent['customer'] );

			wp_update_post(
				array(
					'ID'        => $order->get_id(),
					'post_date' => current_time( 'mysql' ),
				)
			);

			if ( ! empty( $payment_intent['source'] ) ) {
				$order->update_meta_value( 'source', sanitize_text_field( $payment_intent['source'] ) );
			}
			if ( ! empty( $payment_intent['application_fee_amount'] ) ) {
				$order->update_meta_value( 'application_fee_amount', $this->convert_amount_from_stripe( $payment_intent['application_fee_amount'] ) );
			}
		}

		status_header( 200 );
		exit;

	}

	public function check_order_paid() {

		if ( ! is_sunshine_page( 'checkout' ) ) {
			return;
		}

		$checkout_order_id = SPC()->session->get( 'checkout_order_id' );
		$payment_intent_id = SPC()->session->get( 'stripe_payment_intent_id' );

		// Only run checks if we have session data or user just submitted payment
		// This prevents unnecessary queries on normal checkout page loads
		$should_check = false;

		// Check 1: Has session data (standard flow)
		if ( ! empty( $checkout_order_id ) && ! empty( $payment_intent_id ) ) {
			$should_check = true;
		}

		// Check 2: User just came from payment (has payment intent in URL from Stripe redirect)
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Stripe redirect, no nonce available
		if ( ! empty( $_GET['payment_intent'] ) && ! empty( $_GET['payment_intent_client_secret'] ) ) {
			$should_check = true;
		}

		// Skip all checks if not needed (normal checkout page browsing)
		if ( ! $should_check ) {
			return;
		}

		// Standard check with session variables
		$recovery_completed = false; // Track if we successfully recovered

		if ( ! empty( $checkout_order_id ) && ! empty( $payment_intent_id ) ) {
			$order = sunshine_get_order( $checkout_order_id );

			if ( $order->exists() && $order->get_payment_method() == $this->id && ! $order->is_paid() ) {
				// Try checking the payment intent with retries for race condition scenarios
				// This handles cases where Stripe's API hasn't updated yet after payment
				$max_attempts          = 3;
				$has_successful_charge = false;
				$status                = '';

				for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
					// Check the payment intent status
					$intent = $this->make_stripe_request( "payment_intents/$payment_intent_id" );

					if ( is_wp_error( $intent ) ) {
						SPC()->log( 'check_order_paid: Failed checking payment intent: ' . $intent->get_error_message() );
						return;
					}

					$status = ! empty( $intent['status'] ) ? $intent['status'] : '';

					// Check if payment has any charges (more reliable than status for race conditions)
					if ( ! empty( $intent['charges'] ) && ! empty( $intent['charges']['data'] ) ) {
						foreach ( $intent['charges']['data'] as $charge ) {
							if ( ! empty( $charge['status'] ) && $charge['status'] === 'succeeded' ) {
								$has_successful_charge = true;
								break 2; // Break out of both loops
							}
						}
					}

					// If status is succeeded or we found a charge, break
					if ( $status == 'succeeded' || $has_successful_charge ) {
						break;
					}

					// Wait before next attempt (but not on last attempt)
					if ( $attempt < $max_attempts ) {
						sleep( 1 );
					}
				}

				if ( $status == 'succeeded' || $has_successful_charge ) {
					SPC()->log( 'check_order_paid: Payment succeeded for order ' . $order->get_id() . ', auto-processing (attempt ' . $attempt . ')' );
					$recovery_completed = true;
					SPC()->cart->process_order();
					wp_safe_redirect( $order->get_received_permalink() );
					exit;
				}
			}
		}

		// Additional check: If session was cleared (race condition), look for recent pending orders
		// Skip if we already recovered successfully above (performance optimization)
		if ( $recovery_completed ) {
			return;
		}

		// This handles cases where user refreshed during payment processing
		if ( is_user_logged_in() ) {
			$customer_id = get_current_user_id();
		} else {
			// For guests, check by email if available
			$email = SPC()->cart->get_checkout_data_item( 'email' );
			if ( empty( $email ) ) {
				return;
			}
			$customer_id = null;
		}

		// Look for very recent pending orders (within last 5 minutes)
		$recent_orders_args = array(
			'post_status' => array( 'publish' ),
			'date_query'  => array(
				array(
					'after' => '5 minutes ago',
				),
			),
			'meta_query'  => array(
				'relation' => 'AND',
				array(
					'key'     => 'status',
					'value'   => 'pending',
					'compare' => '=',
				),
				array(
					'key'     => 'payment_method',
					'value'   => $this->id,
					'compare' => '=',
				),
			),
		);

		// Add customer/email filter
		if ( is_user_logged_in() ) {
			$recent_orders_args['meta_query'][] = array(
				'key'   => 'customer_id',
				'value' => $customer_id,
			);
		} else {
			$recent_orders_args['meta_query'][] = array(
				'key'   => 'email',
				'value' => $email,
			);
		}

		$recent_orders = sunshine_get_orders( $recent_orders_args );

		if ( ! empty( $recent_orders ) ) {
			$found_completed_payment = false;
			foreach ( $recent_orders as $order ) {
				$order_payment_intent_id = $order->get_meta_value( 'stripe_payment_intent_id' );
				if ( ! empty( $order_payment_intent_id ) ) {
					// Try checking with retries
					$max_attempts          = 3;
					$has_successful_charge = false;
					$status                = '';

					for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
						// Check if this payment actually succeeded
						$intent = $this->make_stripe_request( "payment_intents/$order_payment_intent_id" );
						if ( ! is_wp_error( $intent ) ) {
							$status = ! empty( $intent['status'] ) ? $intent['status'] : '';

							// Check if payment has any charges (more reliable)
							if ( ! empty( $intent['charges'] ) && ! empty( $intent['charges']['data'] ) ) {
								foreach ( $intent['charges']['data'] as $charge ) {
									if ( ! empty( $charge['status'] ) && $charge['status'] === 'succeeded' ) {
										$has_successful_charge = true;
										break 2; // Break out of both loops
									}
								}
							}

							// If found, break
							if ( $status == 'succeeded' || $has_successful_charge ) {
								break;
							}

							// Wait before retry
							if ( $attempt < $max_attempts ) {
								sleep( 1 );
							}
						} else {
							SPC()->log( 'check_order_paid: Failed verifying orphaned order payment intent: ' . $intent->get_error_message() );
							break;
						}
					}

					if ( $status == 'succeeded' || $has_successful_charge ) {
						SPC()->log( 'check_order_paid: Found orphaned paid order ' . $order->get_id() . ', auto-processing (attempt ' . $attempt . ')' );
						$found_completed_payment = true;

						// Show success message to user
						SPC()->notices->add( __( 'Your payment was successful! Completing your order...', 'sunshine-photo-cart' ), 'success' );

						// Restore session variables temporarily
						SPC()->session->set( 'checkout_order_id', $order->get_id() );
						SPC()->session->set( 'stripe_payment_intent_id', $order_payment_intent_id );

						// Process the order
						SPC()->cart->process_order();
						wp_safe_redirect( $order->get_received_permalink() );
						exit;
					}
				}
			}

			// If we found pending orders but none with completed payments, warn user
			if ( ! $found_completed_payment && is_sunshine_page( 'checkout' ) ) {
				$pending_order = $recent_orders[0];
				SPC()->notices->add(
					sprintf(
					/* translators: %s is the order name/number */
						__( 'You have a recent pending order (#%s). If you recently made a payment, please wait a moment or contact support.', 'sunshine-photo-cart' ),
						$pending_order->get_name()
					),
					'info'
				);
			}
		}

	}

	public function get_stripe_customer_id() {
		if ( is_user_logged_in() ) {
			return SPC()->customer->sunshine_stripe_customer_id;
		} else {
			// For guests, store in session
			return SPC()->session->get( 'stripe_customer_id' );
		}
	}

	public function set_stripe_customer_id( $id ) {
		if ( is_user_logged_in() ) {
			SPC()->customer->update_meta( 'stripe_customer_id', $id );
		} else {
			// For guests, store in session for current checkout
			SPC()->session->set( 'stripe_customer_id', $id );
			SPC()->log( 'Set guest Stripe customer ID in session: ' . $id );
		}
	}

	private function get_application_fee_percent() {
		return floatval( apply_filters( 'sunshine_stripe_application_fee_percent', 5 ) );
	}

	private function get_application_fee_amount() {

		$percentage = $this->get_application_fee_percent();

		// Some countries do not allow us to use application fees. If we are in one of those
		// countries, we should set the percentage to 0. This is a temporary fix until we
		// have a better solution or until all countries allow us to use application fees.
		$country                               = SPC()->get_option( 'country' );
		$countries_to_disable_application_fees = array(
			'IN', // India.
			'MX', // Mexico.
			'MY', // Malaysia.
		);
		if ( in_array( $country, $countries_to_disable_application_fees ) ) {
			$percentage = 0;
		}

		if ( $percentage <= 0 ) {
			return 0;
		}

		$percentage = floatval( $percentage );

		return round( $this->total * ( $percentage / 100 ) );

	}

	/**
	 * Generate a unique idempotency key for payment intent creation.
	 * This prevents duplicate charges if the same request is made multiple times.
	 * Key remains stable for the same checkout session to handle page refreshes.
	 *
	 * @return string Unique idempotency key
	 */
	private function generate_idempotency_key() {
		$unique_id  = uniqid();
		$cart_total = SPC()->cart->get_total();
		$user_id    = get_current_user_id();

		// Create a hash of cart items for more reliable uniqueness
		$cart_items_hash = $this->get_cart_items_hash();

		// Create a stable key based on order, amount, user, and cart contents
		$key_data = array(
			'unique_id' => $unique_id,
			'total'     => $this->convert_amount_to_stripe( $cart_total ),
			'user_id'   => ( $user_id ? $user_id : 'guest' ),
			'cart'      => $cart_items_hash,
		);

		// Generate a hash-based key that's unique but deterministic for the same checkout
		$key_string = implode( '|', $key_data );
		return 'stripe_idempotency_' . hash( 'sha256', $key_string );
	}

	/**
	 * Generate a hash of the current cart items for idempotency key generation.
	 *
	 * @return string Hash of cart items
	 */
	private function get_cart_items_hash() {
		if ( empty( SPC()->cart ) || SPC()->cart->is_empty() ) {
			return 'empty-cart';
		}

		$cart_items  = SPC()->cart->get_cart_items();
		$item_hashes = array();

		foreach ( $cart_items as $item ) {
			// Use the existing hash from each cart item
			$item_hashes[] = $item->get_hash();
		}

		// Sort hashes to ensure consistent ordering regardless of cart item order
		sort( $item_hashes );

		// Combine all item hashes into one overall hash
		$combined_hashes = implode( '|', $item_hashes );
		return hash( 'sha256', $combined_hashes );
	}

	public function get_fields() {

		ob_start();

		if ( $this->get_mode_value() == 'test' ) {
			echo '<div class="sunshine--payment--test">' . esc_html__( 'This will be processed as a test payment and no real money will be exchanged', 'sunshine-photo-cart' ) . '</div>';
		}

		$checkout_mode = $this->get_option( 'checkout_mode' );

		if ( $checkout_mode === 'hosted' ) {
			// Hosted checkout - show redirect message
			?>
			<div id="sunshine-stripe-payment">
				<p style="margin: 0; color: #666;">
					<?php esc_html_e( 'You will be redirected to Stripe to complete your payment securely.', 'sunshine-photo-cart' ); ?>
				</p>
			</div>
			<?php
		} else {
			// Inline checkout - show Payment Element
			?>
			<div id="sunshine-stripe-payment">
				<div id="sunshine-stripe-payment-fields">
					<div id="sunshine-stripe-payment-loading" style="padding: 30px 20px; text-align: center; color: #666; font-size: 14px;">
						<?php esc_html_e( 'Loading secure payment form...', 'sunshine-photo-cart' ); ?>
					</div>
				</div>
				<div id="sunshine-stripe-payment-errors"></div>
			</div>
			<?php
		}

		$output = ob_get_contents();
		ob_end_clean();
		return $output;

	}

	public function order_notify( $notify, $order ) {
		if ( $order->get_payment_method() == $this->id ) {
			return false;
		}
		return $notify;
	}

	public function update_payment_method( $payment_method ) {

		if ( empty( $payment_method ) || $payment_method->get_id() != $this->id ) {
			return;
		}

		// Hosted checkout doesn't need a payment intent — it creates its own via Checkout Session
		$checkout_mode = $this->get_option( 'checkout_mode' );
		if ( $checkout_mode === 'hosted' ) {
			return;
		}

		// Set things up here only when we select stripe.
		$this->setup();
		$this->setup_payment_intent();

	}

	private function get_order_by_payment_intent( $payment_intent_id ) {
		$args   = array(
			'post_type'  => 'sunshine-order',
			'meta_query' => array(
				array(
					'key'   => 'stripe_payment_intent_id',
					'value' => $payment_intent_id,
				),
			),
		);
		$orders = get_posts( $args );
		if ( ! empty( $orders ) ) {
			$order = sunshine_get_order( $orders[0] );
			return $order;
		}
		return false;

	}

	public function get_transaction_id( $order ) {
		return $order->get_meta_value( 'stripe_payment_intent_id' );
	}

	public function get_transaction_url( $order ) {
		if ( $order->get_payment_method() == 'stripe' ) {
			$payment_intent_id = $this->get_transaction_id( $order );
			if ( $payment_intent_id ) {
				$mode             = $order->get_mode();
				$transaction_url  = ( $mode == 'test' || $mode == 'sandbox' ) ? 'https://dashboard.stripe.com/test/payments/' : 'https://dashboard.stripe.com/payments/';
				$transaction_url .= $payment_intent_id;
				return $transaction_url;
			}
		}
		return false;
	}

	public function admin_order_tab( $tabs, $order ) {
		if ( $order->get_payment_method() == $this->id ) {
			$tabs['stripe'] = __( 'Stripe', 'sunshine-photo-cart' );
		}
		return $tabs;
	}

	public function admin_order_tab_content_stripe( $order ) {

		echo '<table class="sunshine-data">';
		echo '<tr><th>' . esc_html__( 'Transaction ID', 'sunshine-photo-cart' ) . '</th>';
		echo '<td>' . esc_html( $this->get_transaction_id( $order ) ) . '</td></tr>';
		$application_fee_amount = $order->get_meta_value( 'application_fee_amount' );
		if ( $application_fee_amount ) {
			echo '<tr>';
			echo '<th>' . esc_html__( 'Application Fee Amount (To Sunshine)', 'sunshine-photo-cart' ) . '</th>';
			echo '<td>' . wp_kses_post( sunshine_price( $application_fee_amount ) ) . ' (<a href="https://www.sunshinephotocart.com/upgrade/?utm_source=plugin&utm_medium=link&utm_campaign=stripe" target="_blank">' . esc_html__( 'Upgrade to remove this fee on future transactions', 'sunshine-photo-cart' ) . '</a>)' . '</td>';
			echo '</tr>';
		}
		echo '</table>';

	}

	function order_actions( $actions, $post_id ) {
		$order = sunshine_get_order( $post_id );
		if ( $order->get_payment_method() == $this->id ) {
			/* translators: %s is the payment method name */
			$actions[ $this->id . '_refund' ] = sprintf( __( 'Refund payment in %s', 'sunshine-photo-cart' ), $this->name );
		}
		return $actions;
	}

	function order_actions_options( $order ) {
		?>
		<div id="stripe-refund-order-actions" style="display: none;">
			<p><label><input type="checkbox" name="stripe_refund_notify" value="yes" checked="checked" /> <?php esc_html_e( 'Notify customer via email', 'sunshine-photo-cart' ); ?></label></p>
			<p><label><input type="checkbox" name="stripe_refund_full" value="yes" checked="checked" /> <?php esc_html_e( 'Full refund', 'sunshine-photo-cart' ); ?></label></p>
			<p id="stripe-refund-amount" style="display: none;"><label><input type="number" name="stripe_refund_amount" step=".01" size="6" style="width:100px" max="<?php echo esc_attr( $order->get_total_minus_refunds() ); ?>" value="<?php echo esc_attr( $order->get_total_minus_refunds() ); ?>" /> <?php esc_html_e( 'Amount to refund', 'sunshine-photo-cart' ); ?></label></p>
		</div>
		<script>
			jQuery( 'select[name="sunshine_order_action"]' ).on( 'change', function(){
				let selected_action = jQuery( 'option:selected', this ).val();
				if ( selected_action == 'stripe_refund' ) {
					jQuery( '#stripe-refund-order-actions' ).show();
				} else {
					jQuery( '#stripe-refund-order-actions' ).hide();
				}
			});
			jQuery( 'input[name="stripe_refund_full"]' ).on( 'change', function(){
				if ( !jQuery(this).prop( "checked" ) ) {
					jQuery( '#stripe-refund-amount' ).show();
				} else {
					jQuery( '#stripe-refund-amount' ).hide();
				}
			});
		</script>
			<?php
	}

	/**
	 * Process refund for Stripe payment
	 *
	 * @param int $order_id The order ID to refund
	 * @return void
	 */
	function process_refund( $order_id ) {

		$order = sunshine_get_order( $order_id );

		$this->setup( $order->get_mode() );

		$payment_intent_id = $order->get_meta_value( 'stripe_payment_intent_id' );

		$payment_intent = $this->make_stripe_request( "payment_intents/$payment_intent_id" );

		if ( is_wp_error( $payment_intent ) ) {
			SPC()->log( 'Stripe refund error: ' . $payment_intent->get_error_message() );
			/* translators: %s is the error message */
			SPC()->notices->add_admin( 'stripe_refund_fail_' . $payment_intent_id, sprintf( __( 'Failed to connect: %s', 'sunshine-photo-cart' ), $payment_intent->get_error_message() ) );
			$order->add_log( sprintf( 'Failed to connect to Stripe to retrieve payment intent (Order ID: %s)', $order_id ) );
			return;
		}

		$refund_amount = $order->get_total_minus_refunds();

		if ( ! empty( $_POST['stripe_refund_amount'] ) && $_POST['stripe_refund_amount'] < $refund_amount ) {
			$refund_amount = sanitize_text_field( $_POST['stripe_refund_amount'] );
		}

		$refund_amount_stripe = $this->convert_amount_to_stripe( $refund_amount );

		// Don't allow refund for more than the charged amount
		if ( $refund_amount_stripe > $payment_intent['amount'] ) {
			SPC()->notices->add_admin( 'stripe_refund_fail_' . $payment_intent_id, __( 'Refund amount is higher than allowed', 'sunshine-photo-cart' ), 'error' );
			/* translators: %1$s is the maximum allowed refund amount, %2$s is the requested refund amount */
			$order->add_log( sprintf( __( 'Refund amount is higher than allowed (Total allowed: %1$s, Refund Requested: %2$s)', 'sunshine-photo-cart' ), $this->convert_amount_from_stripe( $payment_intent['amount'] ), $refund_amount ) );
			return;
		}

		$args                   = array(
			'payment_intent' => $payment_intent_id,
			'amount'         => $refund_amount_stripe,
		);
		$application_fee_amount = $order->get_meta_value( 'application_fee_amount' );
		if ( $application_fee_amount ) {
			$args['refund_application_fee'] = 'true';
		}

		$refund_response = $this->make_stripe_request( 'refunds', $args, 'POST' );

		if ( is_wp_error( $refund_response ) ) {
			/* translators: %s is the error message */
			SPC()->notices->add_admin( 'stripe_refund_fail_' . $payment_intent_id, sprintf( __( 'Could not refund payment: %s', 'sunshine-photo-cart' ), $refund_response->get_error_message() ), 'error' );
			$order->add_log( sprintf( 'Could not refund payment in Stripe: %s', $refund_response->get_error_message() ) );
			return;
		}

		$order->set_status( 'refunded' );
		$order->add_refund( $refund_amount );
		/* translators: %s is the refund amount formatted as price */
		SPC()->notices->add_admin( 'stripe_refund_success_' . $payment_intent_id, sprintf( __( 'Refund has been processed for %s', 'sunshine-photo-cart' ), sunshine_price( $refund_amount ) ) );

		if ( ! empty( $_POST['stripe_refund_notify'] ) ) {
			$order->notify( false );
			SPC()->notices->add_admin( 'stripe_refund_notify_' . $payment_id, __( 'Customer sent email about refund', 'sunshine-photo-cart' ) );
		}

	}

	public function mode( $mode, $order ) {
		if ( $order->get_payment_method() == 'stripe' ) {
			return $this->get_mode_value();
		}
		return $mode;
	}

	public function checkout_validation( $section ) {
		if ( $section == 'payment' && SPC()->cart->get_total() > 0 && SPC()->cart->get_checkout_data_item( 'payment_method' ) == 'stripe' ) {
			// Skip payment intent validation for hosted checkout - payment intent is created on redirect
			$checkout_mode = $this->get_option( 'checkout_mode' );
			if ( $checkout_mode === 'hosted' ) {
				return;
			}
			if ( empty( $_POST['stripe_payment_intent_id'] ) ) {
				SPC()->cart->add_error( __( 'Invalid payment', 'sunshine-photo-cart' ) );
			}
		}
	}

}
