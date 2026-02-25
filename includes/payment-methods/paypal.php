<?php
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use PayPalCheckoutSdk\Payments\CapturesRefundRequest;

class SPC_Payment_Method_PayPal extends SPC_Payment_Method {

	private $extra_meta_data = array();
	private $api_url;
	private $token;

	public function init() {

		$this->id                    = 'paypal';
		$this->name                  = 'PayPal';
		$this->class                 = get_class( $this );
		$this->description           = __( 'Pay with credit card or your PayPal account', 'sunshine-photo-cart' );
		$this->can_be_enabled        = true;
		$this->needs_billing_address = false;

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		// add_filter( 'sunshine_checkout_payment_method_extra', array( $this, 'buttons' ), 10, 2 );

		add_action( 'wp_ajax_sunshine_checkout_paypal_create_order', array( $this, 'create_order' ) );
		add_action( 'wp_ajax_nopriv_sunshine_checkout_paypal_create_order', array( $this, 'create_order' ) );

		/*
		add_action( 'wp_ajax_sunshine_checkout_paypal_approve', array( $this, 'approve' ) );
		add_action( 'wp_ajax_nopriv_sunshine_checkout_paypal_approve', array( $this, 'approve' ) );
		*/
		add_action( 'sunshine_checkout_process_payment_paypal', array( $this, 'process_payment' ) );

		add_filter( 'sunshine_order_transaction_url', array( $this, 'transaction_url' ) );

		add_filter( 'sunshine_admin_order_tabs', array( $this, 'admin_order_tab' ), 10, 2 );
		add_action( 'sunshine_admin_order_tab_paypal', array( $this, 'admin_order_tab_content_paypal' ) );

		add_action( 'sunshine_order_actions', array( $this, 'order_actions' ), 10, 2 );
		add_action( 'sunshine_order_actions_options', array( $this, 'order_actions_options' ) );
		add_action( 'sunshine_order_process_action_paypal_refund', array( $this, 'process_refund' ) );

		add_action( 'sunshine_checkout_validation', array( $this, 'checkout_validation' ) );

	}

	public function options( $options ) {

		$options[10]['description'] = 'Learn how to <a href="https://www.sunshinephotocart.com/docs/paypal" target="_blank">get your API connection Client ID and Secret</a>';

		$options[] = array(
			'name'    => __( 'Mode', 'sunshine-photo-cart' ),
			'id'      => $this->id . '_mode',
			'type'    => 'radio',
			'options' => array(
				'live'    => __( 'Live', 'sunshine-photo-cart' ),
				'sandbox' => __( 'Sandbox', 'sunshine-photo-cart' ),
			),
			'default' => 'live',
		);
		$options[] = array(
			'name'             => __( 'Live Client ID', 'sunshine-photo-cart' ),
			'id'               => $this->id . '_client_id',
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
			'name'             => __( 'Live Secret', 'sunshine-photo-cart' ),
			'id'               => $this->id . '_secret',
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
			'name'             => __( 'Sandbox Client ID', 'sunshine-photo-cart' ),
			'id'               => $this->id . '_client_id_sandbox',
			'type'             => 'text',
			'conditions'       => array(
				array(
					'field'   => $this->id . '_mode',
					'compare' => '==',
					'value'   => 'sandbox',
					'action'  => 'show',
				),
			),
			'callback'         => array( $this, 'reset_token' ),
			'hide_system_info' => true,
		);
		$options[] = array(
			'name'             => __( 'Sandbox Live Secret', 'sunshine-photo-cart' ),
			'id'               => $this->id . '_secret_sandbox',
			'type'             => 'text',
			'conditions'       => array(
				array(
					'field'   => $this->id . '_mode',
					'compare' => '==',
					'value'   => 'sandbox',
					'action'  => 'show',
				),
			),
			'hide_system_info' => true,
		);
		$options[] = array(
			'name'        => __( 'Allow Venmo', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_allow_venmo',
			'type'        => 'checkbox',
			'description' => __( 'Venmo button will appear when available to the customer', 'sunshine-photo-cart' ),
		);
		$options[] = array(
			'name'        => __( 'Button Layout', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_style_layout',
			'type'        => 'select',
			'options'     => array(
				'vertical'   => __( 'Vertical', 'sunshine-photo-cart' ),
				'horizontal' => __( 'Horizontal', 'sunshine-photo-cart' ),
			),
			'default'     => 'horizontal',
			'description' => '<a href="https://developer.paypal.com/sdk/js/reference/#link-layout" target="_blank">' . __( 'See details', 'sunshine-photo-cart' ) . '</a>',
		);
		$options[] = array(
			'name'        => __( 'Color', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_style_color',
			'type'        => 'select',
			'options'     => array(
				'gold'   => __( 'Gold', 'sunshine-photo-cart' ),
				'blue'   => __( 'Blue', 'sunshine-photo-cart' ),
				'silver' => __( 'Silver', 'sunshine-photo-cart' ),
				'white'  => __( 'White', 'sunshine-photo-cart' ),
				'black'  => __( 'Black', 'sunshine-photo-cart' ),
			),
			'default'     => 'gold',
			'description' => '<a href="https://developer.paypal.com/sdk/js/reference/#link-color" target="_blank">' . __( 'See details', 'sunshine-photo-cart' ) . '</a>',
		);
		$options[] = array(
			'name'        => __( 'Button Shape', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_style_shape',
			'type'        => 'select',
			'options'     => array(
				'rect' => __( 'Rectangle', 'sunshine-photo-cart' ),
				'pill' => __( 'Pill', 'sunshine-photo-cart' ),
			),
			'default'     => 'rect',
			'description' => '<a href="https://developer.paypal.com/sdk/js/reference/#link-shape" target="_blank">' . __( 'See details', 'sunshine-photo-cart' ) . '</a>',
		);
		$options[] = array(
			'name'        => __( 'Label', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_style_label',
			'type'        => 'select',
			'options'     => array(
				'paypal'      => __( 'PayPal', 'sunshine-photo-cart' ),
				'checkout'    => __( 'Checkout', 'sunshine-photo-cart' ),
				'buynow'      => __( 'Buy Now', 'sunshine-photo-cart' ),
				'pay'         => __( 'Pay', 'sunshine-photo-cart' ),
				'installment' => __( 'Installment', 'sunshine-photo-cart' ),
			),
			'default'     => 'paypal',
			'description' => '<a href="https://developer.paypal.com/sdk/js/reference/#link-label" target="_blank">' . __( 'See details', 'sunshine-photo-cart' ) . '</a>',
		);
		$options[] = array(
			'name'        => __( 'Tagline', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_style_tagline',
			'type'        => 'select',
			'options'     => array(
				'true'  => __( 'Show', 'sunshine-photo-cart' ),
				'false' => __( 'Hide', 'sunshine-photo-cart' ),
			),
			'default'     => 'true',
			'conditions'  => array(
				array(
					'field'   => $this->id . '_style_layout',
					'compare' => '==',
					'value'   => 'horizontal',
					'action'  => 'show',
				),
			),
			'description' => '<a href="https://developer.paypal.com/sdk/js/reference/#link-tagline" target="_blank">' . __( 'See details', 'sunshine-photo-cart' ) . '</a>',
		);
		$options[] = array(
			'name'        => __( 'Hide funding sources', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_disable_funding_sources',
			'type'        => 'select',
			'select2'     => true,
			'multiple'    => true,
			'options'     => array(
				'card'        => __( 'Credit or debit cards', 'sunshine-photo-cart' ),
				'credit'      => __( 'PayPal Credit (US, UK)', 'sunshine-photo-cart' ),
				'paylater'    => __( 'Pay Later (US, UK), Pay in 4 (AU), 4X PayPal (France), Später Bezahlen (Germany)', 'sunshine-photo-cart' ),
				'bancontact'  => 'Bancontact',
				'blik'        => 'BLIK',
				'eps'         => 'eps',
				'giropay'     => 'giropay',
				'ideal'       => 'iDEAL',
				'mercadopago' => 'Mercado Pago',
				'mybank'      => 'MyBank',
				'p24'         => 'Przelewy24',
				'sepa'        => 'SEPA-Lastschrift',
				'sofort'      => 'Sofort',
				'venmo'       => 'Venmo',
			),
			'default'     => 'true',
			/* translators: %s is the URL to PayPal documentation */
			'description' => sprintf( __( 'By default, all possible funding sources will be shown. This setting can disable funding sources such as Credit Cards, Pay Later, Venmo, or other <a href="%s" target="_blank">Alternative Payment Methods</a>', 'sunshine-photo-cart' ), 'https://developer.paypal.com/docs/checkout/apm/' ),
		);

		return $options;
	}

	public function get_option( $key ) {
		return SPC()->get_option( $this->id . '_' . $key );
	}

	public function create_order_status( $status, $order ) {
		if ( $order->get_payment_method() == $this->id ) {
			SPC()->log( 'Setting order status to new for PayPal payment' );
			return 'new'; // Straight to new.
		}
		return $status;
	}

	public function get_client_id() {
		$client_id = ( $this->get_mode_value() == 'live' ) ? SPC()->get_option( $this->id . '_client_id' ) : SPC()->get_option( $this->id . '_client_id_sandbox' );
		$client_id = str_replace( ' ', '', $client_id );
		return $client_id;
	}

	public function get_secret() {
		$secret = ( $this->get_mode_value() == 'live' ) ? SPC()->get_option( $this->id . '_secret' ) : SPC()->get_option( $this->id . '_secret_sandbox' );
		$secret = str_replace( ' ', '', $secret );
		return $secret;
	}

	public function is_active() {
		$active = SPC()->get_option( $this->id . '_active' );
		if ( ! empty( $active ) ) {
			return true;
		}
		return false;
	}

	public function setup() {

		if ( $this->get_mode_value() == 'live' ) {
			$this->api_url = 'https://api-m.paypal.com/v2/';
		} else {
			$this->api_url = 'https://api-m.sandbox.paypal.com/v2/';
		}

		$this->token = $this->generate_access_token();

	}

	public function enqueue_scripts() {
		if ( is_sunshine_page( 'checkout' ) && $this->get_client_id() && $this->get_secret() ) {
			$url = 'https://www.paypal.com/sdk/js';
			$url = add_query_arg( 'client-id', trim( $this->get_client_id() ), $url );
			$url = add_query_arg( 'currency', SPC()->get_option( 'currency' ), $url );
			$url = add_query_arg( 'intent', 'capture', $url ); // Force capture intent which may help with popup behavior
			$url = add_query_arg( 'components', 'buttons', $url ); // Exclude hosted-fields component

			// Handle enabled funding sources.
			if ( SPC()->get_option( 'paypal_allow_venmo' ) ) {
				$url = add_query_arg( 'enable-funding', 'venmo', $url );
			}

			// Handle disabled funding sources.
			$disabled_funding_sources = SPC()->get_option( 'paypal_disable_funding_sources' );
			if ( empty( $disabled_funding_sources ) ) {
				$disabled_funding_sources = array();
			}
			// Force disable card funding to prevent inline forms - only allow popup methods
			if ( ! in_array( 'card', $disabled_funding_sources ) ) {
				$disabled_funding_sources[] = 'card';
			}
			if ( ! empty( $disabled_funding_sources ) && is_array( $disabled_funding_sources ) ) {
				$url = add_query_arg( 'disable-funding', implode( ',', $disabled_funding_sources ), $url );
			}
			wp_enqueue_script( 'sunshine-paypal-checkout', $url, '', null );
		}
	}

	public function get_fields() {

		ob_start();

		if ( $this->get_mode_value() == 'sandbox' ) {
			echo '<div class="sunshine--payment--test">' . esc_html__( 'This will be processed as a test payment and no real money will be exchanged', 'sunshine-photo-cart' ) . '</div>';
		}

		?>
		<div id="sunshine--checkout--paypal-buttons"></div>
		<script>
			jQuery( document ).ready(function($){
				$( '#sunshine--checkout--paypal-buttons' ).hide(); // Hide it by default

				// Show PayPal buttons when selected as the option
				$( document ).on( 'change', 'input[name="payment_method"]', function(){
					var sunshine_paypal_selected_payment_method = $( 'input[name="payment_method"]:checked' ).val();
					if ( sunshine_paypal_selected_payment_method == 'paypal' ) {
						sunshine_render_paypal_buttons();
						$( '#sunshine--form--field--sunshine--checkout--submit' ).hide();
						$( '#sunshine--checkout--paypal-buttons' ).show();
					} else {
						$( '#sunshine--form--field--sunshine--checkout--submit' ).show();
						$( '#sunshine--checkout--paypal-buttons' ).hide();
					}
				});

				// Check if PayPal selected on load
				var sunshine_paypal_selected_payment_method = $( 'input[name="payment_method"]:checked' ).val();
				if ( sunshine_paypal_selected_payment_method == 'paypal' ) {
					sunshine_render_paypal_buttons();
					$( '#sunshine--form--field--sunshine--checkout--submit' ).hide();
					$( '#sunshine--checkout--paypal-buttons' ).show();
				} else {
					$( '#sunshine--form--field--sunshine--checkout--submit' ).show();
					$( '#sunshine--checkout--paypal-buttons' ).hide();
				}

			});

			function sunshine_render_paypal_buttons() {

				jQuery( '#sunshine--checkout--paypal-buttons' ).html( '' );

				paypal.Buttons({

					style: {
						layout: '<?php echo esc_js( $this->get_option( 'style_layout' ) ); ?>',
						color:  '<?php echo esc_js( $this->get_option( 'style_color' ) ); ?>',
						shape:  '<?php echo esc_js( $this->get_option( 'style_shape' ) ); ?>',
						label:  '<?php echo esc_js( $this->get_option( 'style_label' ) ); ?>',
					<?php if ( $this->get_option( 'style_layout' ) == 'horizontal' ) { ?>
							tagline:  <?php echo esc_js( $this->get_option( 'style_tagline' ) ); ?>
						<?php } ?>
					},
					// Call your server to set up the transaction
					createOrder: function(data, actions) {
						jQuery( '#sunshine--checkout--paypal-errors' ).remove();
						sunshine_checkout_updating();
						var data = new FormData();
						data.append( 'action', 'sunshine_checkout_paypal_create_order' );
						data.append( 'security', '<?php echo esc_js( wp_create_nonce( 'sunshine_checkout_paypal_create_order' ) ); ?>' );
						return fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						  method: 'post',
						  body: data
					  }).then(function(result) {
						  return result.json();
					  }).then(function ( result ) {
							return result.data.order_id; // the data is the order object returned from the api call, its not the BrainTree.Response object
						 });
					},
					// Call your server to finalize the transaction
					onApprove: function(result, actions) {
						sunshine_checkout_updating();
						jQuery( '#sunshine--checkout form' ).append( '<input type="hidden" name="paypal_order_id" value="' + result.orderID + '" />' );
						jQuery( '#sunshine--checkout form' ).append( '<input type="hidden" name="paypal_payer_id" value="' + result.payerID + '" />' );
						jQuery( '#sunshine--checkout form' ).append( '<input type="hidden" name="paypal_payment_source" value="' + result.paymentSource + '" />' );
						jQuery( '#sunshine--form--field--sunshine--checkout--submit' ).show();
						jQuery( '#sunshine--form--field--sunshine--checkout--submit button' ).trigger( 'click' );
					},
					onCancel: function(data) {
						sunshine_checkout_updating_done();
					},
					onError: function( err ) {
						jQuery( '#sunshine--checkout--paypal-buttons' ).before( '<div id="sunshine--checkout--paypal-errors"><?php echo esc_js( __( 'Could not connect to PayPal', 'sunshine-photo-cart' ) ); ?></div>' );
						sunshine_checkout_updating_done();
					}

				}).render( '#sunshine--checkout--paypal-buttons' );

			}

		</script>

		<?php
		$output = ob_get_contents();
		ob_end_clean();
		return $output;
	}

	private function generate_access_token() {

		$token = get_transient( 'sunshine_paypal_token_' . $this->get_mode_value() );

		if ( empty( $token ) ) {

			if ( $this->get_mode_value() == 'live' ) {
				$api_url = 'https://api-m.paypal.com';
			} else {
				$api_url = 'https://api-m.sandbox.paypal.com';
			}

			if ( empty( $this->get_client_id() ) || empty( $this->get_secret() ) ) {
				SPC()->log( 'PayPal Client ID or Secret unavailable to get token' );
				return false;
			}

			$response = wp_remote_post(
				$api_url . '/v1/oauth2/token',
				array(
					'headers'    => array(
						'Content-Type'  => 'application/x-www-form-urlencoded',
						'Authorization' => sprintf( 'Basic %s', base64_encode( sprintf( '%s:%s', $this->get_client_id(), $this->get_secret() ) ) ),
						'timeout'       => 15,
					),
					'body'       => array(
						'grant_type' => 'client_credentials',
					),
					'user-agent' => 'Sunshine Photo Cart/' . SUNSHINE_PHOTO_CART_VERSION . '; ' . get_bloginfo( 'name' ),
				)
			);

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $body ) || empty( $body->access_token ) ) {
				SPC()->log( 'Could not get PayPal access token' );
				SPC()->log( $body );
				return false;
			}

			$token = $body->access_token;

			set_transient( 'sunshine_paypal_token_' . $this->get_mode_value(), $body->access_token, ( $body->expires_in - ( 30 * MINUTE_IN_SECONDS ) ) );

		}

		return $token;

	}

	public function reset_token( $value ) {
		delete_transient( 'sunshine_paypal_token_live' );
		delete_transient( 'sunshine_paypal_token_sandbox' );
		return $value;
	}

	private function make_request( $endpoint, $body = array(), $headers = array(), $method = 'POST' ) {

		$this->setup();

		$api_url = $this->api_url . $endpoint;

		$headers = wp_parse_args(
			$headers,
			array(
				'Content-Type'  => 'application/json',
				'Authorization' => sprintf( 'Bearer %s', $this->token ),
			)
		);

		$request_args = array(
			'method'     => $method,
			'timeout'    => 15,
			'headers'    => $headers,
			'user-agent' => 'Sunshine Photo Cart/' . SUNSHINE_PHOTO_CART_VERSION . '; ' . get_bloginfo( 'name' ),
		);

		if ( ! empty( $body ) ) {
			$request_args['body'] = json_encode( $body );
		}

		$response = wp_remote_request( $api_url, $request_args );
		if ( is_wp_error( $response ) ) {
			SPC()->log( 'PayPal error (' . $endpoint . '): ' . $response->get_error_message() );
			return false;
		}

		return json_decode( wp_remote_retrieve_body( $response ) );

	}

	public function create_order() {

		check_ajax_referer( 'sunshine_checkout_paypal_create_order', 'security' );

		$items = array();
		foreach ( SPC()->cart->get_cart_items() as $cart_item ) {
			$items[] = array(
				'name'        => $cart_item->get_name_raw(),
				'unit_amount' => array(
					'value'         => (string) round( $cart_item->get_price(), 2 ),
					'currency_code' => SPC()->get_option( 'currency' ),
				),
				'quantity'    => $cart_item->get_qty(),
			);
		}

		$fees = SPC()->cart->get_fees();
		if ( ! empty( $fees ) ) {
			foreach ( $fees as $fee ) {
				$items[] = array(
					'name'        => $fee['name'],
					'unit_amount' => array(
						'value'         => (string) round( $fee['amount'], 2 ),
						'currency_code' => SPC()->get_option( 'currency' ),
					),
					'quantity'    => 1,
				);
			}
		}

		$order_args = array(
			'intent'              => 'CAPTURE',
			'purchase_units'      => array(
				array(
					'amount' => array(
						'value'         => (string) SPC()->cart->get_total(),
						'currency_code' => SPC()->get_option( 'currency' ),
						'breakdown'     => array(
							'item_total' => array(
								'value'         => (string) round( SPC()->cart->get_subtotal() + SPC()->cart->get_fees_total(), 2 ),
								'currency_code' => SPC()->get_option( 'currency' ),
							),
							'tax_total'  => array(
								'value'         => (string) round( SPC()->cart->get_tax(), 2 ),
								'currency_code' => SPC()->get_option( 'currency' ),
							),
							'shipping'   => array(
								'value'         => (string) round( SPC()->cart->get_shipping(), 2 ),
								'currency_code' => SPC()->get_option( 'currency' ),
							),
							'discount'   => array(
								'value'         => (string) round( SPC()->cart->get_discount() + SPC()->cart->get_credits_applied(), 2 ),
								'currency_code' => SPC()->get_option( 'currency' ),
							),
						),
					),
					'items'  => $items,
				),
			),
			'application_context' => array(
				'brand_name' => get_bloginfo( 'name' ),
				// 'shipping_preference' => ( !empty( $shipping ) ) ? 'SET_PROVIDED_ADDRESS' : '',
			),
		);

		$shipping = array();
		if ( SPC()->cart->needs_shipping() ) {
			$order_args['purchase_units'][0]['shipping']              = array(
				'name'    => array(
					'full_name' => SPC()->cart->get_checkout_data_item( 'shipping_first_name' ) . ' ' . SPC()->cart->get_checkout_data_item( 'shipping_last_name' ),
				),
				'address' => array(
					'address_line_1' => SPC()->cart->get_checkout_data_item( 'shipping_address1' ),
					'address_line_2' => SPC()->cart->get_checkout_data_item( 'shipping_address2' ),
					'admin_area_2'   => SPC()->cart->get_checkout_data_item( 'shipping_city' ),
					'postal_code'    => SPC()->cart->get_checkout_data_item( 'shipping_postcode' ),
					'country_code'   => SPC()->cart->get_checkout_data_item( 'shipping_country' ),
				),
				'type'    => 'SHIPPING',
			);
			$order_args['application_context']['shipping_preference'] = 'SET_PROVIDED_ADDRESS';
		}

		$order_args = apply_filters( 'sunshine_paypal_order_args', $order_args );

		SPC()->log( $order_args );

		$order = $this->make_request( 'checkout/orders', $order_args );
		if ( ! empty( $order->id ) ) {
			wp_send_json_success( array( 'order_id' => $order->id ) );
		}

		// sunshine_log( $order );
		// SPC()->notices->add( __( 'Error connecting to PayPal', 'sunshine-photo-cart' ), 'error' );

		SPC()->log( 'Error creating order for PayPal: ' . print_r( $order, 1 ) );
		wp_send_json_error();

	}

	public function process_payment( $order ) {

		if ( ! isset( $_POST['paypal_order_id'] ) ) {
			return false;
		}

		$paypal_order_id = sanitize_text_field( $_POST['paypal_order_id'] );

		SPC()->log( 'Processing PayPal payment for PayPal Order ID ' . $paypal_order_id );

		$this->setup();

		$endpoint = 'checkout/orders/' . $paypal_order_id . '/capture';
		$capture  = $this->make_request( $endpoint, '', '', 'POST' );
		if ( empty( $capture->status ) || $capture->status != 'COMPLETED' ) {
			$error = __( 'Failed to capture PayPal payment', 'sunshine-photo-cart' );
			if ( ! empty( $capture->details ) ) {
				$error .= ': ' . $capture->details[0]->description;
			}
			SPC()->cart->add_error( $error );
			SPC()->log( $error );
			return;
		}

		$order->update_meta_value( 'paypal_capture_id', $capture->purchase_units[0]->payments->captures[0]->id );
		$order->update_meta_value( 'paypal_order_id', $paypal_order_id );
		$order->update_meta_value( 'paypal_payer_id', sanitize_text_field( $_POST['paypal_payer_id'] ) );
		$order->update_meta_value( 'paypal_payment_source', sanitize_text_field( $_POST['paypal_payment_source'] ) );
		/* translators: %s is the payment method name */
		$note = sprintf( __( 'Payment processed by %s', 'sunshine-photo-cart' ), $this->name );
		$order->add_log( $note );

		$payment_breakdown_data = $capture->purchase_units[0]->payments->captures[0]->seller_receivable_breakdown;
		if ( ! empty( $payment_breakdown_data ) ) {
			foreach ( $payment_breakdown_data as $key => $data ) {
				$order->update_meta_value( $key, $data->value );
			}
		}

	}

	public function get_transaction_id( $order ) {
		if ( $order->get_payment_method() == 'paypal' ) {
			return $order->get_meta_value( 'paypal_order_id' );
		}
		return false;
	}

	public function get_capture_id( $order ) {
		return $order->get_meta_value( 'paypal_capture_id' );
	}

	public function get_transaction_url( $order ) {
		if ( $order->get_payment_method() == 'paypal' ) {
			$capture_id = $order->get_meta_value( 'paypal_capture_id' );
			if ( $capture_id ) {
				$mode             = $order->get_meta_value( 'mode' );
				$transaction_url  = ( $mode == 'test' || $mode == 'sandbox' ) ? 'https://www.sandbox.paypal.com/activity/payment/' : 'https://www.paypal.com/activity/payment/';
				$transaction_url .= $capture_id;
				return $transaction_url;
			}
		}
		return false;
	}

	public function admin_order_tab( $tabs, $order ) {
		if ( $order->get_payment_method() == $this->id ) {
			$tabs['paypal'] = __( 'PayPal', 'sunshine-photo-cart' );
		}
		return $tabs;
	}

	public function admin_order_tab_content_paypal( $order ) {
		echo '<table class="sunshine-data">';
		if ( $order->get_meta_value( 'paypal_order_id' ) ) {
			echo '<tr><th>' . esc_html__( 'Transaction ID', 'sunshine-photo-cart' ) . '</th><td>' . esc_html( $order->get_meta_value( 'paypal_order_id' ) ) . '</td></tr>';
		}
		if ( $order->get_meta_value( 'paypal_fee' ) ) {
			echo '<tr><th>' . esc_html__( 'Transaction fees', 'sunshine-photo-cart' ) . '</th><td>' . wp_kses_post( sunshine_price( $order->get_meta_value( 'paypal_fee' ), true ) ) . '</td></tr>';
		}
		if ( $order->get_meta_value( 'net_amount' ) ) {
			echo '<tr><th>' . esc_html__( 'Net amount', 'sunshine-photo-cart' ) . '</th><td>' . wp_kses_post( sunshine_price( $order->get_meta_value( 'net_amount' ), true ) ) . '</td></tr>';
		}
		if ( $order->get_meta_value( 'paypal_payment_source' ) ) {
			echo '<tr><th>' . esc_html__( 'Payment Source', 'sunshine-photo-cart' ) . '</th><td>' . esc_html( $order->get_meta_value( 'paypal_payment_source' ) ) . '</td></tr>';
		}
		if ( $this->get_capture_id( $order ) ) {
			echo '<tr><th>' . esc_html__( 'Capture ID', 'sunshine-photo-cart' ) . '</th><td>' . esc_html( $this->get_capture_id( $order ) ) . '</td></tr>';
		}
		echo '</table>';
	}

	function order_actions( $actions, $post_id ) {
		$order = new SPC_Order( $post_id );
		if ( $order->get_payment_method() == $this->id ) {
			$actions['paypal_refund'] = __( 'Refund payment in PayPal', 'sunshine-photo-cart' );
		}
		return $actions;
	}

	function order_actions_options( $order ) {
		?>
		<div id="paypal-refund-order-actions" style="display: none;">
			<p><label><input type="checkbox" name="paypal_refund_notify" value="yes" checked="checked" /> <?php esc_html_e( 'Notify customer via email', 'sunshine-photo-cart' ); ?></label></p>
			<p><label><input type="checkbox" name="paypal_refund_full" value="yes" checked="checked" /> <?php esc_html_e( 'Full refund', 'sunshine-photo-cart' ); ?></label></p>
			<p id="paypal-refund-amount" style="display: none;"><label><input type="number" name="paypal_refund_amount" step=".01" size="6" style="width:100px" max="<?php echo esc_attr( $order->get_total() ); ?>" value="<?php echo esc_attr( $order->get_total() ); ?>" /> <?php esc_html_e( 'Amount to refund', 'sunshine-photo-cart' ); ?></label></p>
		</div>
		<script>
			jQuery( 'select[name="sunshine_order_action"]' ).on( 'change', function(){
				let selected_action = jQuery( 'option:selected', this ).val();
				if ( selected_action == 'paypal_refund' ) {
					jQuery( '#paypal-refund-order-actions' ).show();
				} else {
					jQuery( '#paypal-refund-order-actions' ).hide();
				}
			});
			jQuery( 'input[name="paypal_refund_full"]' ).on( 'change', function(){
				if ( !jQuery(this).prop( "checked" ) ) {
					jQuery( '#paypal-refund-amount' ).show();
				} else {
					jQuery( '#paypal-refund-amount' ).hide();
				}
			});
		</script>
		<?php
	}

	function process_refund( $order_id ) {

		$order      = new SPC_Order( $order_id );
		$capture_id = $this->get_capture_id( $order );
		if ( ! empty( $capture_id ) ) {

			$this->setup();

			$refund_amount = $order->get_total();

			if ( ! empty( $_POST['paypal_refund_amount'] ) && $_POST['paypal_refund_amount'] < $refund_amount ) {
				$refund_amount = sanitize_text_field( $_POST['paypal_refund_amount'] );
			}

			$body    = array(
				'amount' =>
					array(
						'value'         => $refund_amount,
						'currency_code' => $order->get_currency(),
					),
			);
			$request = $this->make_request( 'payments/captures/' . $capture_id . '/refund', $body );

			if ( ! empty( $request->status ) && $request->status == 'PENDING' ) {
				/* translators: %s is the order ID */
				$order->add_log( sprintf( __( 'Order %s refund is pending in PayPal', 'sunshine-photo-cart' ), $order_id ) );
				SPC()->notices->add_admin( 'paypal_refund_' . $capture_id, __( 'PayPal refund is pending', 'sunshine-photo-cart' ), 'error' );
				$order->set_status( 'refunded' );
				$order->add_refund( $refund_amount );
			} elseif ( ! empty( $request->status ) && $request->status == 'COMPLETED' ) {
				/* translators: %s is the refund amount formatted as price */
				$order->add_log( sprintf( __( 'Refund has been processed for %s', 'sunshine-photo-cart' ), sunshine_price( $refund_amount ) ) );
				/* translators: %s is the refund amount formatted as price */
				SPC()->notices->add_admin( 'paypal_refund_' . $capture_id, sprintf( __( 'Refund has been processed for %s', 'sunshine-photo-cart' ), sunshine_price( $refund_amount ) ) );
				$order->set_status( 'refunded' );
				$order->add_refund( $refund_amount );
			} else {
				SPC()->notices->add_admin( 'paypal_refund_' . $capture_id, __( 'PayPal failed to refund', 'sunshine-photo-cart' ), 'error' );
				/* translators: %s is the order ID */
				$order->add_log( sprintf( __( 'Order %s failed refund in PayPal', 'sunshine-photo-cart' ), $order_id ) );
			}
		}
	}

	public function mode( $mode, $order ) {
		if ( $order->get_payment_method() == 'paypal' ) {
			return $this->get_mode_value();
		}
		return $mode;
	}

	public function checkout_validation( $section ) {
		if ( $section == 'payment' && SPC()->cart->get_total() > 0 && SPC()->cart->get_checkout_data_item( 'payment_method' ) == $this->id ) {
			if ( empty( $_POST['paypal_order_id'] ) ) {
				SPC()->cart->add_error( __( 'Invalid payment', 'sunshine-photo-cart' ) );
			}
		}
	}

}
