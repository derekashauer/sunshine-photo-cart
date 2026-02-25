<?php
/**
 * Sunshine Shipping Method
 *
 * Sets up base class for all Shipping Methods.
 *
 * @package Sunshine\Classes
 * @since   3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * SPC_Shipping_Method.
 */
class SPC_Shipping_Method {

	/**
	 * Unique ID of this shipping method.
	 *
	 * @var string
	 */
	protected $id;

	/**
	 * Unique ID for this instance of the shipping method.
	 *
	 * @var string
	 */
	protected $instance_id;

	/**
	 * Shipping method active status.
	 *
	 * @var boolean
	 */
	protected $active;

	/**
	 * Name of the shipping method.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Description of the shipping method.
	 *
	 * @var string
	 */
	protected $description;

	/**
	 * The class name to be used.
	 *
	 * @var string
	 */
	protected $class;

	/**
	 * Can there be multiple instances of this shipping method.
	 *
	 * @var boolean
	 */
	protected $can_be_cloned = false;

	/**
	 * Shipping price.
	 *
	 * @var float
	 */
	protected $price = 0.00;

	/**
	 * Shipping tax.
	 *
	 * @var float
	 */
	protected $tax = 0.00;

	/**
	 * Constructor for the shipping method class. Registers method and options.
	 *
	 * @param integer $instance_id Unique instance ID for this shipping method.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->init();
		add_filter( 'sunshine_shipping_methods', array( $this, 'register' ) );
		if ( ! empty( $instance_id ) ) {
			$this->instance_id = $instance_id;
			add_filter( 'sunshine_checkout_delivery_options', array( $this, 'delivery_options' ) );
		}

		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			add_filter( 'sunshine_options_shipping_method_' . $this->id, array( $this, 'default_options' ), 1, 2 );
			add_filter( 'sunshine_options_shipping_method_' . $this->id, array( $this, 'options' ), 10, 2 );
		} else {
			$this->set_price();
		}

	}

	/**
	 * Default init function where various vars can be set for each class.
	 *
	 * @return void
	 */
	public function init() { }

	/**
	 * Registers this shipping method to be available.
	 *
	 * @param array $shipping_methods Existing methods.
	 * @return void
	 */
	public function register( $shipping_methods = array() ) {
		if ( ! empty( $this->id ) ) {
			$shipping_methods[ $this->id ] = array(
				'id'          => $this->id,
				'name'        => $this->name,
				'description' => $this->description,
				'class'       => $this->class,
			);
		}

		return $shipping_methods;
	}

	/**
	 * Every shipping method will at least have these options
	 *
	 * @param array  $fields Array of existing option fields data.
	 * @param string $instance_id Unique Instance ID for this method.
	 * @return void
	 */
	public function default_options( $fields, $instance_id ) {
		$fields['1']  = array(
			'id'          => $this->id . '_header_' . $instance_id,
			'name'        => $this->name . ' <span style="float: right; font-size: 14px; font-weight: normal; color: #999;">' . __( 'Instance ID', 'sunshine-photo-cart' ) . ': ' . $instance_id . '</span>',
			'type'        => 'header',
			'description' => '',
		);
		$fields['10'] = array(
			'name'        => __( 'Name', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_name_' . $instance_id,
			'type'        => 'text',
			'description' => __( 'Name displayed on the checkout page to the customer', 'sunshine-photo-cart' ),
			'placeholder' => $this->name,
		);
		$fields['20'] = array(
			'name'        => __( 'Description', 'sunshine-photo-cart' ),
			'id'          => $this->id . '_description_' . $instance_id,
			'type'        => 'text',
			'description' => __( 'Description displayed on the checkout page to the customer', 'sunshine-photo-cart' ),
			'placeholder' => $this->description,
		);
		$fields['30'] = array(
			'name' => __( 'Taxable', 'sunshine-photo-cart' ),
			'id'   => $this->id . '_taxable_' . $instance_id,
			'type' => 'checkbox',
		);
		$fields['40'] = array(
			'name' => __( 'Price', 'sunshine-photo-cart' ),
			'id'   => $this->id . '_price_' . $instance_id,
			'type' => 'text',
		);
		$fields       = apply_filters( 'sunshine_shipping_options_default', $fields, $this->id, $instance_id );
		return $fields;
	}

	/**
	 * Set up the initial Shipping delivery option.
	 *
	 * @param array $options Existing delivery options.
	 * @return void
	 */
	public function delivery_options( $options ) {
		$options['shipping'] = __( 'Shipping or delivery', 'sunshine-photo-cart' );
		return $options;
	}

	/**
	 * Default options for this shipping method.
	 *
	 * @param array  $options Existing options.
	 * @param string $instance_id Unique instance ID.
	 * @return void
	 */
	public function options( $options, $instance_id ) {
		return $options;
	}

	/**
	 * Sets the ID for this shipping method.
	 *
	 * @param string $id
	 * @return void
	 */
	private function set_id( $id ) {
		$this->id = sanitize_title( $id );
	}

	/**
	 * Returns ID for this shipping method.
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Returns instance ID for this shipping method.
	 *
	 * @return string
	 */
	public function get_instance_id() {
		return $this->instance_id;
	}

	/**
	 * Sets the name for this shipping method.
	 *
	 * @param string $name
	 * @return void
	 */
	public function set_name( $name ) {
		$this->name = sanitize_text_field( $name );
	}

	/**
	 * Gets name of the shipping method
	 *
	 * @return string
	 */
	public function get_name() {
		// If instance_id is set, get custom name.
		if ( ! empty( $this->instance_id ) ) {
			$custom_name = SPC()->get_option( $this->id . '_name_' . $this->instance_id );
			if ( ! empty( $custom_name ) ) {
				return $custom_name;
			}
		}
		return $this->name;

	}

	/**
	 * Sets description for shipping method.
	 *
	 * @param string $description
	 * @return void
	 */
	public function set_description( $description ) {
		$this->description = esc_html( $description );
	}

	/**
	 * Returns description for shipping method.
	 *
	 * @return string
	 */
	public function get_description() {
		// If instance_id is set, get custom desc.
		if ( ! empty( $this->instance_id ) ) {
			$custom_name = SPC()->get_option( $this->id . '_description_' . $this->instance_id );
			if ( ! empty( $custom_name ) ) {
				return $custom_name;
			}
		}
		return $this->description;
	}

	/**
	 * Returns clonability.
	 *
	 * @return boolean
	 */
	public function can_be_cloned() {
		return $this->can_be_cloned;
	}

	/**
	 * Returns price for shipping method.
	 *
	 * @return float|boolean
	 */
	public function get_price() {
		return $this->price;
	}

	/**
	 * Returns nicely formatted price for shipping method.
	 *
	 * @return string
	 */
	public function get_price_formatted() {
		if ( $this->price !== '' && is_numeric( $this->price ) ) {
			return sunshine_get_price_to_display( $this->price, $this->tax );
		}
		return false;
	}

	/**
	 * Returns if shipping method is taxable.
	 *
	 * @return boolean
	 */
	public function is_taxable() {
		if ( ! empty( $this->instance_id ) ) {
			return SPC()->get_option( $this->id . '_taxable_' . $this->instance_id );
		}
		return false;
	}

	public function set_price() {

		if ( ! empty( $this->instance_id ) ) {
			$this->price = floatval( SPC()->get_option( $this->id . '_price_' . $this->instance_id ) );

			// When prices include tax, extract base price first from the configured price.
			$price_has_tax = SPC()->get_option( 'price_has_tax' );
			if ( 'yes' === $price_has_tax && $this->is_taxable() ) {
				$tax_rate = SPC()->cart->get_tax_rate();
				if ( $tax_rate ) {
					// Extract tax from the base flat rate price.
					$base_price  = round( $this->price / ( $tax_rate['rate'] + 1 ), 2 );
					$this->tax   = round( $this->price - $base_price, 2 );
					$this->price = $base_price;
				}
			}

			// Add product shipping costs.
			if ( ! SPC()->cart->is_empty() ) {
				foreach ( SPC()->cart->get_cart_items() as $item ) {
					$product_shipping = floatval( $item->product->get_shipping() );
					if ( $product_shipping ) {
						// If prices include tax, extract base from product shipping too.
						if ( 'yes' === $price_has_tax && $this->is_taxable() && ! empty( $tax_rate ) ) {
							$product_shipping_base = round( $product_shipping / ( $tax_rate['rate'] + 1 ), 2 );
							$product_shipping_tax  = round( $product_shipping - $product_shipping_base, 2 );
							$this->price          += ( $product_shipping_base * $item->get_qty() );
							$this->tax            += ( $product_shipping_tax * $item->get_qty() );
						} else {
							$this->price += ( $product_shipping * $item->get_qty() );
						}
					}
				}
			}

			// Calculate tax if prices don't include tax.
			if ( 'yes' !== $price_has_tax && $this->price && $this->is_taxable() ) {
				$tax_rate = SPC()->cart->get_tax_rate();
				if ( $tax_rate ) {
					$this->tax = round( $this->price * $tax_rate['rate'], 2 );
				}
			}
		}

	}

	/**
	 * Calculate tax for shipping price.
	 * Called by child classes to ensure consistent tax handling.
	 */
	public function calculate_tax() {
		if ( ! $this->price || ! $this->is_taxable() ) {
			return;
		}

		$tax_rate = SPC()->cart->get_tax_rate();
		if ( ! $tax_rate ) {
			return;
		}

		$price_has_tax = SPC()->get_option( 'price_has_tax' );
		if ( 'yes' === $price_has_tax ) {
			// Extract tax from price.
			$base_price  = round( $this->price / ( $tax_rate['rate'] + 1 ), 2 );
			$this->tax   = round( $this->price - $base_price, 2 );
			$this->price = $base_price;
		} else {
			// Calculate tax on price.
			$this->tax = round( $this->price * $tax_rate['rate'], 2 );
		}
	}

	/**
	 * Returns shipping tax amount.
	 *
	 * @return float
	 */
	public function get_tax() {
		return $this->tax;
	}

	/**
	 * Is shipping method active.
	 *
	 * @return boolean
	 */
	public function is_active() {
		if ( ! empty( $this->instance_id ) ) {
			$active_shipping_methods = sunshine_get_active_shipping_methods();
			if ( array_key_exists( $this->instance_id, $active_shipping_methods ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is shipping method allowed. Has filter to allow addons to alter if shipping method can be shown on frontend checkout.
	 *
	 * @return boolean
	 */
	public function is_allowed() {
		return apply_filters( 'sunshine_shipping_method_allowed', true, $this->id, $this->instance_id );
	}


}
