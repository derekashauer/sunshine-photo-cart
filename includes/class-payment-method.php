<?php
class SPC_Payment_Method {

	public $id;
	protected $active;
	protected $name;
	protected $description;
	protected $class;
	protected $can_be_enabled        = true;
	protected $needs_billing_address = false;

	// Gateways that collect an application fee name the add-on that removes it.
	protected $fee_addon_slug = '';
	protected $fee_addon_plan = 'plus';
	protected $fee_addon_name = '';

	public function __construct() {
		$this->init();
		// add_filter( 'sunshine_payment_methods', array( $this, 'register' ) );

		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			add_filter( 'sunshine_options_payment_method_' . $this->id, array( $this, 'default_options' ), 1 );
			add_filter( 'sunshine_options_payment_method_' . $this->id, array( $this, 'options' ), 10 );
		}

		add_filter( 'sunshine_create_order_status', array( $this, 'create_order_status' ), 10, 2 );
		add_filter( 'sunshine_order_transaction_url', array( $this, 'get_transaction_url' ) );
		add_filter( 'sunshine_checkout_create_order_mode', array( $this, 'mode' ), 10, 2 );
		add_filter( 'sunshine_checkout_needs_billing_address', array( $this, 'checkout_needs_billing_address' ), 10, 2 );

		if ( is_admin() && $this->fee_addon_slug ) {
			add_action( 'sunshine_admin_order_totals', array( $this, 'admin_order_application_fee' ), 5 );
			add_action( 'admin_notices', array( $this, 'admin_application_fee_notice' ) );
		}
	}

	public function init() { }

	/*
	public function register( $payment_methods = array() ) {
		if ( !empty( $this->id ) && !empty( $this->class ) ) {
			$payment_methods[] = $this->class;
		}
		return $payment_methods;
	}
	*/

	// Every payment method will at least have these options
	public function default_options( $fields ) {
		$fields[10] = array(
			'id'          => $this->id . '_header',
			'name'        => $this->name,
			'type'        => 'header',
			'description' => '',
		);
		$fields[20] = array(
			'name'        => __( 'Name', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_name',
			'type'        => 'text',
			'description' => __( 'Name displayed on the checkout page to the customer', 'sunshine-photo-cart' ),
			'placeholder' => $this->name,
		);
		$fields[30] = array(
			'name'        => __( 'Description', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_description',
			'type'        => 'text',
			'description' => __( 'Description displayed on the checkout page to the customer', 'sunshine-photo-cart' ),
			'placeholder' => $this->description,
		);
		$fields[40] = array(
			'name'          => __( 'Fees', 'sunshine-photo-cart' ),
			'id'            => $this->id . '_fee',
			'type'          => 'radio',
			'description'   => __( 'Fees added to the order for using this payment method', 'sunshine-photo-cart' ),
			'options'       => array(
				'none'    => __( 'No added fees', 'sunshine-photo-cart' ),
				'percent' => __( 'Percentage of total order', 'sunshine-photo-cart' ),
				'amount'  => __( 'Fixed amount', 'sunshine-photo-cart' ),
			),
			'documentation' => 'https://www.sunshinephotocart.com/docs/payment-gateway-fees/',
		);
		$fields[41] = array(
			'name'        => __( 'Fee Name', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_fee_name',
			'type'        => 'text',
			'description' => __( 'What description shown to customer at checkout', 'sunshine-photo-cart' ),
			'conditions'  => array(
				array(
					'compare' => '==',
					'value'   => 'none',
					'field'   => $this->id . '_fee',
					'action'  => 'hide',
				),
			),
		);
		$fields[42] = array(
			'name'       => __( 'Fee Amount', 'sunshine-photo-cart' ),
			'id'         => $this->id . '_fee_amount',
			'type'       => 'number',
			'step'       => '.01',
			'conditions' => array(
				array(
					'compare' => '==',
					'value'   => 'none',
					'field'   => $this->id . '_fee',
					'action'  => 'hide',
				),
			),
		);
		return $fields;
	}

	public function create_order_status( $status, $order ) {
		return $status;
	}

	public function options( $options ) {
		return $options;
	}

	public function get_option( $key ) {
		return SPC()->get_option( $this->id . '_' . $key );
	}

	public function update_option( $key, $value ) {
		return SPC()->update_option( $this->id . '_' . $key, $value );
	}

	public function set_id( $id ) {
		$this->id = sanitize_key( $id );
	}

	public function get_id() {
		return $this->id;
	}

	public function set_name( $name ) {
		$this->name = sanitize_text_field( $name );
	}

	public function get_name() {
		$custom_name = $this->get_option( 'name' );
		if ( ! empty( $custom_name ) ) {
			return $custom_name;
		}
		return $this->name;
	}

	public function set_description( $description ) {
		$this->description = esc_html( $description );
	}

	public function get_description() {
		$custom_description = $this->get_option( 'description' );
		if ( ! empty( $custom_description ) ) {
			return $custom_description;
		}
		return $this->description;
	}

	public function is_active() {
		$active = $this->get_option( 'active' );
		if ( ! empty( $active ) ) {
			return true;
		}
		return false;
	}

	private function get_mode() {
		return $this->get_option( 'mode' );
	}

	public function get_mode_value() {
		return ( $this->get_mode() == 'live' ) ? 'live' : 'test';
	}

	public function is_allowed() {
		return $this->is_active();
	}

	public function can_be_enabled() {
		return $this->can_be_enabled;
	}

	public function needs_billing_address() {
		return $this->needs_billing_address;
	}

	/**
	 * Tell the checkout to collect a billing address when this payment method needs one.
	 *
	 * A payment method only has to set $needs_billing_address to true -- the checkout never
	 * needs to know which ones those are, so an add-on gateway can declare it the same way
	 * the built in ones do.
	 *
	 * The customer does not choose a payment method until the last step, which is after the
	 * billing address would be asked for, so any available method needing one means it is
	 * collected for all of them.
	 *
	 * @param bool     $needs_billing_address Whether the checkout already needs one.
	 * @param SPC_Cart $cart                  The cart being checked out.
	 * @return bool
	 */
	public function checkout_needs_billing_address( $needs_billing_address, $cart = null ) {

		if ( $needs_billing_address || ! $this->needs_billing_address() ) {
			return $needs_billing_address;
		}

		// Checked against the allowed list rather than is_allowed() directly, so a method a
		// site has filtered out of checkout does not still ask for an address.
		if ( ! array_key_exists( $this->id, (array) sunshine_get_allowed_payment_methods() ) ) {
			return $needs_billing_address;
		}

		// Nothing to pay means no payment method is used, so none of them need an address.
		if ( $cart && $cart->get_total() <= 0 ) {
			return $needs_billing_address;
		}

		return true;
	}

	public function get_transaction_id( $order ) {
		return false;
	}

	public function get_transaction_url( $order ) {
		return false;
	}

	public function mode( $mode, $order ) {
		return $mode;
	}

	public function get_fields() {
		return false;
	}

	public function get_submit_label() {
		/* translators: %s is the order total amount formatted as price */
		return sprintf( __( 'Submit Order & Pay %s', 'sunshine-photo-cart' ), '<span class="sunshine-total">' . SPC()->cart->get_total_formatted() . '</span>' );
	}

	public function get_fee() {
		$fee      = array();
		$fee_type = $this->get_option( 'fee' );
		if ( $fee_type && $fee_type != 'none' ) {
			$fee_amount = $this->get_option( 'fee_amount' );
			if ( ! empty( $fee_amount ) ) {
				if ( $fee_type == 'percent' ) {
					$cart_total = SPC()->cart->get_total( array( 'fees' ) );
					$amount     = ( $fee_amount / 100 ) * $cart_total;
				} elseif ( $fee_type == 'amount' ) {
					$amount = $fee_amount;
				}
				$name = $this->get_option( 'fee_name' );
				if ( empty( $name ) ) {
					/* translators: %s is the payment method name */
					$name = sprintf( __( '%s fee', 'sunshine-photo-cart' ), $this->get_name() );
				}
				$fee = array(
					'amount' => $amount,
					'name'   => $name,
				);
			}
		}
		return $fee;
	}

	/**
	 * The application fee percentage this gateway is configured to collect.
	 *
	 * @return float
	 */
	public function get_application_fee_percent() {
		return 0;
	}

	/**
	 * The application fee percentage actually being collected, after any
	 * country restrictions are applied. Gateways override this.
	 *
	 * @return float
	 */
	public function get_effective_application_fee_percent() {
		return $this->get_application_fee_percent();
	}

	/**
	 * The application fee recorded against a single order.
	 *
	 * @param SPC_Order $order The order.
	 * @return float
	 */
	public function get_order_application_fee( $order ) {
		return 0;
	}

	/**
	 * Whether the customer's license already includes the add-on that removes the fee.
	 *
	 * @return bool
	 */
	public function fee_addon_included_in_plan() {
		if ( ! $this->fee_addon_slug || ! function_exists( 'sunshine_plan_covers_addon' ) ) {
			return false;
		}
		return sunshine_plan_covers_addon( $this->fee_addon_plan );
	}

	/**
	 * Whether the fee-removing add-on is installed and turned on.
	 *
	 * @return bool
	 */
	public function fee_addon_is_active() {
		if ( ! $this->fee_addon_slug || ! function_exists( 'is_sunshine_addon_active' ) ) {
			return false;
		}
		return is_sunshine_addon_active( $this->fee_addon_slug );
	}

	/**
	 * Where to send someone to stop the fee. People whose license already covers
	 * the add-on go to the Add-ons screen; everyone else goes to the sales page.
	 *
	 * @return string
	 */
	public function get_fee_addon_url() {
		if ( $this->fee_addon_included_in_plan() ) {
			return admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-addons' );
		}
		return 'https://www.sunshinephotocart.com/addon/' . $this->fee_addon_slug . '/?utm_source=plugin&utm_medium=link&utm_campaign=' . $this->fee_addon_slug;
	}

	/**
	 * Short sentence telling someone how to stop the fee, worded for whether
	 * their license already covers the add-on.
	 *
	 * @return string
	 */
	public function get_fee_addon_message() {
		$name = $this->fee_addon_name ? $this->fee_addon_name : $this->get_name();
		if ( $this->fee_addon_included_in_plan() ) {
			/* translators: %s is the add-on name, such as "Stripe Pro" */
			$text = sprintf( __( 'Your license already includes the %s add-on, which removes this fee. It just needs to be turned on.', 'sunshine-photo-cart' ), $name );
			$link = __( 'Turn it on', 'sunshine-photo-cart' );
		} else {
			/* translators: %s is the add-on name, such as "Stripe Pro" */
			$text = sprintf( __( 'The %s add-on removes this fee.', 'sunshine-photo-cart' ), $name );
			$link = __( 'Learn more', 'sunshine-photo-cart' );
		}
		$target = $this->fee_addon_included_in_plan() ? '' : ' target="_blank"';
		return $text . ' <a href="' . esc_url( $this->get_fee_addon_url() ) . '"' . $target . '>' . esc_html( $link ) . '</a>';
	}

	/**
	 * Show the application fee on the main order screen, not only inside the
	 * gateway's own tab, so it is visible alongside the other order totals.
	 *
	 * @param SPC_Order $order The order.
	 * @return void
	 */
	public function admin_order_application_fee( $order ) {

		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$amount = $this->get_order_application_fee( $order );
		if ( ! $amount ) {
			return;
		}

		echo '<tr class="sunshine--order--application-fee">';
		echo '<th>' . esc_html__( 'Sunshine Photo Cart fee', 'sunshine-photo-cart' ) . '</th>';
		echo '<td>' . wp_kses_post( sunshine_price( $amount ) ) . '<br /><span class="description">' . wp_kses_post( $this->get_fee_addon_message() ) . '</span></td>';
		echo '</tr>';

	}

	/**
	 * Warn when a fee is being collected on every order but the customer's
	 * license already covers the add-on that removes it. Without this the fee
	 * is only visible inside an individual order.
	 *
	 * @return void
	 */
	public function admin_application_fee_notice() {

		if ( ! current_user_can( 'sunshine_manage_options' ) ) {
			return;
		}

		if ( ! $this->is_active() || $this->fee_addon_is_active() ) {
			return;
		}

		if ( $this->get_effective_application_fee_percent() <= 0 || ! $this->fee_addon_included_in_plan() ) {
			return;
		}

		$name = $this->fee_addon_name ? $this->fee_addon_name : $this->get_name();

		echo '<div class="notice notice-warning">';
		echo '<p><strong>' . sprintf(
			/* translators: 1: fee percentage, 2: payment method name, such as "Stripe" */
			esc_html__( 'Sunshine Photo Cart is taking a %1$s%% fee on every %2$s order.', 'sunshine-photo-cart' ),
			esc_html( $this->get_effective_application_fee_percent() ),
			esc_html( $this->get_name() )
		) . '</strong> ';
		echo sprintf(
			/* translators: %s is the add-on name, such as "Stripe Pro" */
			esc_html__( 'Your license already includes the %s add-on, which removes this fee, but it is not turned on yet.', 'sunshine-photo-cart' ),
			esc_html( $name )
		);
		echo ' <a href="' . esc_url( $this->get_fee_addon_url() ) . '">' . esc_html__( 'Turn it on', 'sunshine-photo-cart' ) . '</a></p>';
		echo '</div>';

	}

}
