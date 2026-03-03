<?php

class SPC_Cart {

	// Cart variables
	protected $cart                   = array();
	protected $cart_items             = array();
	protected $subtotal               = 0.00;
	protected $subtotal_tax           = 0.00;
	protected $shipping               = 0.00;
	protected $shipping_tax           = 0.00;
	protected $discounts              = array();
	protected $discount_names         = array();
	protected $discount               = 0.00;
	protected $discount_taxable_total = 0.00;
	// protected $taxable     = 0.00;
	protected $tax_rate    = array();
	public $tax            = 0.00;
	protected $fees        = array();
	protected $use_credits = false;
	protected $credits     = 0;
	protected $total       = 0.00;

	// Checkout variables
	private $active_section;
	private $fields        = array();
	private $hidden_fields = array();
	private $delivery_method;
	private $shipping_method;
	private $payment_method;
	private $errors = array();
	private $data   = array();

	private $from_init = true;

	function __construct() {
		add_action( 'init', array( $this, 'setup' ), 5 );
		add_action( 'admin_init', array( $this, 'setup' ), 5 ); // So this fires during ajax requests
		add_action( 'sunshine_checkout_section_contact_process', array( $this, 'create_customer' ) );
		add_action( 'wp_ajax_nopriv_sunshine_checkout_init_order', array( $this, 'init_order' ), 1 );
		add_action( 'wp_ajax_sunshine_checkout_init_order', array( $this, 'init_order' ), 1 );
		add_action( 'wp', array( $this, 'process_payment' ), 5 );
		// add_action( 'wp', array( $this, 'order_retrieve' ), 5 );
	}

	public function add_error( $error ) {
		SPC()->notices->add( $error, 'error' );
		SPC()->log( 'Cart error: ' . $error );
		$this->errors[] = $error;
	}

	public function get_errors() {
		return $this->errors;
	}

	public function has_errors() {
		if ( ! empty( $this->get_errors() ) ) {
			return true;
		}
		return false;
	}

	public function setup( $force = false ) {

		if ( is_admin() && ! wp_doing_ajax() ) {
			return; // if in admin but not doing ajax, don't bother
		}

		$this->discount = 0;

		// Get cart to start.
		$this->get_cart_items( $force );

		// Get any session saved data for the cart
		$this->setup_checkout_data();

		// Set delivery method
		$delivery_methods = sunshine_get_delivery_methods();
		if ( empty( $this->delivery_method ) ) {
			if ( array_key_exists( 'delivery_method', $this->data ) ) {
				$this->delivery_method = sunshine_get_delivery_method_by_id( $this->data['delivery_method'] );
			} else {
				// Default to only option when there is 1
				if ( ! empty( $delivery_methods ) && count( $delivery_methods ) == 1 ) {
					$this->delivery_method = sunshine_get_delivery_method_by_id( array_key_first( $delivery_methods ) );
				}
			}
		}

		// Set shipping method.
		if ( $this->needs_shipping() && array_key_exists( 'shipping', $delivery_methods ) ) {
			$allowed_shipping_methods = sunshine_get_allowed_shipping_methods();
			if ( ! empty( $allowed_shipping_methods ) ) {
				// Check if this shipping method is still
				if ( array_key_exists( 'shipping_method', $this->data ) && is_array( $allowed_shipping_methods ) && array_key_exists( $this->data['shipping_method'], $allowed_shipping_methods ) ) {
					$this->set_shipping_method( $this->data['shipping_method'] );
				} elseif ( $this->delivery_method == 'ship' && count( $allowed_shipping_methods ) == 1 ) {
					$this->set_shipping_method( array_key_first( $allowed_shipping_methods ) );
				}
			}
		}

		// Set payment method
		if ( array_key_exists( 'payment_method', $this->data ) && SPC()->payment_methods->is_payment_method_allowed( $this->data['payment_method'] ) ) {
			$this->set_payment_method( $this->data['payment_method'] );
			$this->payment_method = sunshine_get_payment_method_by_id( $this->data['payment_method'] );
		} else {
			// Default to only option when there is 1.
			$payment_methods = sunshine_get_allowed_payment_methods();
			if ( ! empty( $payment_methods ) && count( $payment_methods ) == 1 ) {
				$this->set_payment_method( array_key_first( $payment_methods ) );
			}
		}

		if ( SPC()->get_option( 'discount_after_tax' ) ) {
			$this->set_tax();
			$this->set_discounts();
		} else {
			$this->set_discounts();
			$this->set_tax();
		}

		$this->get_total();

		// TODO: Do the rest on checkout page.
		$this->set_checkout_fields();

		// Set which section we are in
		if ( isset( $_GET['section'] ) ) {
			$this->active_section = sanitize_key( $_GET['section'] );
			// TODO: Make other sections after this one not completed?
		} elseif ( isset( $_POST['sunshine_checkout_section'] ) ) {
			// Check POST data for section (form submissions)
			$this->active_section = sanitize_key( $_POST['sunshine_checkout_section'] );
		}

		// If still no active section, go through all sections to see if any are completed and set the first one not completed to active
		if ( empty( $this->active_section ) ) {
			foreach ( $this->fields as $section_id => $section ) {
				if ( sunshine_checkout_section_completed( $section_id ) ) {
					continue;
				}
				if ( empty( $section['fields'] ) ) { // If no fields in this section, we can skip as well
					continue;
				}
				$this->active_section = $section_id;
				break;
			}
		}

	}

	// An alternate name for this to make it easier to understand elsewhere
	public function update() {
		// SPC()->session->set( 'checkout_data', $this->data );
		$this->setup( true );
	}

	public function setup_checkout_data() {
		$this->data = $this->get_checkout_data();
		if ( empty( $this->data ) ) {
			$this->data = array();
		}
	}

	public function get_cart() {
		if ( ! empty( $this->cart ) ) {
			return $this->cart;
		}
		if ( is_user_logged_in() ) {
			$cart = SPC()->customer->get_cart();
		} else {
			$cart = SPC()->session->get( 'cart' );
		}
		if ( ! empty( $cart ) ) {
			$this->cart = $cart;
		}
		return $this->cart;
	}

	public function get_cart_items( $refresh = false ) {

		$this->get_cart();

		if ( empty( $this->cart ) ) {
			return false;
		}

		// Return already set data so we don't run this more than necessary
		if ( ! empty( $this->cart_items ) && ! $refresh ) {
			return $this->cart_items;
		}

		$final_contents = array();
		foreach ( $this->cart as $key => $item ) {
			$cart_item = new SPC_Cart_Item( $item );
			if ( empty( $cart_item->get_product() ) || $cart_item->get_price() === '' ) {
				unset( $this->cart[ $key ] );
				$this->update_cart();
				SPC()->notices->add( __( 'An item has been removed from your cart because it no longer exists', 'sunshine-photo-cart' ) );
			} else {
				$final_contents[] = $cart_item;
			}
		}

		$final_contents   = (array) apply_filters( 'sunshine_cart_items', $final_contents, $this );
		$this->cart_items = $final_contents;

		return $final_contents;
	}

	public function add_item( $product_id, $image_id = 0, $gallery_id = '', $price_level = '', $options = array(), $qty = 1, $comments = '', $overwrite = false, $meta = array() ) {

		if ( empty( $price_level ) ) {
			$price_level = sunshine_get_default_price_level_id();
		}

		$product = sunshine_get_product( intval( $product_id ), $price_level );
		if ( ! $product->exists() ) {
			SPC()->log( 'Item not added to cart because product does not exist: ' . $product_id );
			return false;
		}

		if ( $product->get_price( $price_level ) === '' ) {
			SPC()->log( 'Item not added to cart because product has no price: ' . $product_id );
			return false;
		}

		$image   = '';
		$gallery = '';

		if ( $image_id ) {
			$image = sunshine_get_image( intval( $image_id ) );
			if ( ! $image->exists() ) {
				SPC()->log( 'Item not added to cart because image does not exist: ' . $image_id );
				return false;
			}
			$gallery = $image->get_gallery();
		} elseif ( $gallery_id ) {
			$gallery = sunshine_get_gallery( intval( $gallery_id ) );
		}

		// Sanity check: Make sure we can purchase this product and access this gallery it is tied to
		if ( ! $product->can_purchase() || ( $gallery && ! $gallery->can_purchase() ) ) {
			SPC()->log( 'Item not added to cart because invalid purchase permissions for product or gallery' );
			return false;
		}

		$this->get_cart();

		$item = array(
			'image_id'    => $image_id,
			'gallery_id'  => $gallery_id,
			// 'gallery_name' => $gallery->get_name(),
			'product_id'  => $product_id,
			// 'type' => $product->get_type(),
			// 'product_name' => $product->get_name(),
			'options'     => $options,
			// 'filename' => ( !empty( $image ) ) ? $image->get_file_name() : '',
			// 'image_name' => ( !empty( $image ) ) ? $image->get_name() : '',
			'price_level' => $price_level,
			'qty'         => ( $qty <= 0 ) ? 1 : $qty,
			'price'       => $product->get_price( $price_level ),
			// 'needs_shipping' => $product->needs_shipping(),
			// 'shipping' => $product->get_shipping(),
			'discount'    => 0,
			'comments'    => $comments,
			'meta'        => $meta,
			'hash'        => md5( time() . $product_id . $qty ),
		);

		// $item = wp_parse_args( $options, $options_default );

		// If object already in cart, increase quantity of existing and not add
		if ( ! empty( $this->cart ) ) {
			foreach ( $this->cart as $key => &$cart_item ) {
				if ( $item['product_id'] == $cart_item['product_id'] ) {
					// If no image ID, then this is a gallery download or a general product and we are not checking for uniqueness.
					if ( empty( $item['image_id'] ) || empty( $cart_item['image_id'] ) ) {
						continue;
					}
					// If the image IDs are different, then this is a unique cart item.
					if ( ! empty( $item['image_id'] ) && ! empty( $cart_item['image_id'] ) && $item['image_id'] != $cart_item['image_id'] ) {
						continue;
					}
					// Check options
					if ( empty( $cart_item['options'] ) ) {
						$cart_item['options'] = array();
					}
					if ( empty( $item['options'] ) ) {
						$item['options'] = array();
					}
					// If the options are different, then this is a unique cart item.
					if ( json_encode( $item['options'] ) != json_encode( $cart_item['options'] ) ) {
						continue;
					}
					// If the meta is different, then this is a unique cart item.
					if ( json_encode( $item['meta'] ) != json_encode( $cart_item['meta'] ) ) {
						continue;
					}
					// If the comments are different, then this is a unique cart item.
					if ( ! empty( $item['comments'] ) && ! empty( $cart_item['comments'] ) && $item['comments'] != $cart_item['comments'] ) {
						continue;
					}
					// If the price level is different, then this is a unique cart item.
					if ( ! empty( $item['price_level'] ) && ! empty( $cart_item['price_level'] ) && $item['price_level'] != $cart_item['price_level'] ) {
						continue;
					}
					$this->remove_item( $key ); // Remove the existing item with old quantity
					if ( ! $overwrite ) {
						$item['qty'] = $cart_item['qty'] + $item['qty'];
						$this->remove_item( $key ); // Remove the existing item with old quantity
					}
				}
			}
		}

		// Don't let qty go over max quantity
		if ( $product->get_max_qty() && $item['qty'] > $product->get_max_qty() ) {
			$item['qty'] = $product->get_max_qty();
		}

		if ( $product->get_min_qty() && $item['qty'] < $product->get_min_qty() ) {
			$item['qty'] = $product->get_min_qty();
		}

		$item = apply_filters( 'sunshine_add_to_cart_item', $item );

		// Add item data to cart contents
		if ( ! empty( $item ) && $item['qty'] > 0 ) {
			$this->cart[] = $item;
		}

		$this->update_cart();
		$this->update();

		do_action( 'sunshine_add_cart_item', $item );

		SPC()->log( 'Item added to cart: ' . json_encode( $item ) );

		return $item;

	}

	public function update_item( $key, $item ) {
		if ( array_key_exists( $key, $this->cart ) ) {
			$this->cart[ $key ] = $item;
			SPC()->log( 'Item updated in cart: ' . json_encode( $item ) );
			$this->update_cart();
		}
	}

	public function remove_item( $key ) {
		if ( array_key_exists( $key, $this->cart ) ) {
			$item = $this->cart[ $key ];
			unset( $this->cart[ $key ] );
			SPC()->log( 'Item removed from cart: ' . json_encode( $item ) );
			$this->update_cart();
		}
	}

	public function get_item_by_hash( $hash ) {
		if ( ! empty( $this->cart ) ) {
			foreach ( $this->cart as $key => $item ) {
				if ( $item['hash'] == $hash ) {
					return $item;
				}
			}
		}
		return false;
	}

	public function update_item_by_hash( $hash, $item ) {
		if ( ! empty( $this->cart ) ) {
			foreach ( $this->cart as $key => $current_item ) {
				if ( $current_item['hash'] == $hash ) {
					$this->update_item( $key, $item );
				}
			}
		}
		return false;
	}

	public function update_cart() {
		// Set user cart meta
		if ( is_user_logged_in() ) {
			SPC()->customer->set_cart( $this->cart );
		} else {
			// Set cart session
			SPC()->session->set( 'cart', $this->cart );
		}
	}

	public function get_item_count() {
		if ( ! empty( $this->cart ) && is_array( $this->cart ) ) {
			$count = 0;
			foreach ( $this->cart as $item ) {
				if ( ! empty( $item['qty'] ) ) {
					$count += intval( $item['qty'] );
				}
			}
			return $count;
		}
		return 0;
	}

	public function get_product_count( $product_id ) {
		$count = 0;
		foreach ( $this->cart_items as $item ) {
			if ( $product_id == $item->get_product_id() ) {
				$count += $item->get_qty();
			}
		}
		return $count;
	}

	public function get_product_with_image_count( $product_id, $image_id ) {
		$count = 0;
		foreach ( $this->cart_items as $item ) {
			if ( $product_id == $item->get_product_id() && $image_id == $item->get_image_id() ) {
				$count += $item->get_qty();
			}
		}
		return $count;
	}

	public function is_empty() {
		return 0 == $this->get_item_count();
	}

	public function empty_cart() {

		SPC()->session->set( 'cart', null );

		// Remove any active discounts
		$this->remove_all_discounts();
		$this->cart       = array();
		$this->cart_items = array();
		sunshine_maybe_set_customer_cart( null );

		do_action( 'sunshine_empty_cart' );

	}

	public function set_discounts() {

		if ( $this->is_empty() ) {
			return;
		}

		do_action( 'sunshine_set_discounts' );

		$discounts = SPC()->session->get( 'discounts' );
		if ( ! empty( $discounts ) ) {
			foreach ( $discounts as $key => $discount_code ) {
				if ( empty( $discount_code ) ) {
					unset( $discounts[ $key ] );
					SPC()->session->set( 'discounts', $discounts );
					continue;
				}
				$discount = sunshine_get_discount_by_code( $discount_code );
				if ( $discount && $discount->exists() && $discount->is_valid() ) {
					if ( $discount->is_solo() ) {
						// If solo discount, unset all others, set this one, and then stop looping.
						$this->discounts   = array();
						$this->discounts[] = $discount;
						break;
					}
					$this->discounts[ $discount->get_id() ] = $discount;
					$this->add_discount_name( $discount->get_name() );
				} else {
					// Remove this discount because it is no longer valid.
					unset( $discounts[ $key ] );
					/* translators: %s is the discount code */
					$this->add_error( sprintf( __( 'Discount "%s" removed because it is no longer valid', 'sunshine-photo-cart' ), $discount_code ) );
					SPC()->log( 'Discount automatically removed because it is no longer valid: ' . $discount_code );
					SPC()->session->set( 'discounts', $discounts );
				}
			}
		}

		$this->calculate_discount();

	}

	public function add_discount_name( $name ) {
		if ( ! in_array( $name, $this->discount_names ) ) {
			$this->discount_names[] = $name;
		}
	}

	public function calculate_discount() {
		$this->discount = 0;

		// Set any line item discounts.
		$items = $this->get_cart_items();
		foreach ( $items as $item ) {
			if ( $item->get_discount_total() ) {
				// $this->discount += $item->get_discount_total();
			}
		}

		if ( ! empty( $this->discounts ) ) {
			foreach ( $this->discounts as $discount ) {
				// if ( $discount->is_allowed() ) {
					$this->discount               += $discount->get_total();
					$this->discount_taxable_total += $discount->get_total( true );
				// }
			}
		}

		$this->discount = apply_filters( 'sunshine_cart_discount', $this->discount, $items );

		return $this->discount;
	}

	public function add_discount( $code ) {
		$discount = sunshine_get_discount_by_code( $code );
		if ( $discount && $discount->is_valid() && ! $this->has_discount_code( $code ) ) {
			$this->discounts[ $discount->get_id() ] = $discount;
			$discounts                              = SPC()->session->get( 'discounts' );
			if ( ! is_array( $discounts ) ) {
				$discounts = array();
			}
			$discounts[ $discount->get_id() ] = $discount->get_code();
			SPC()->session->set( 'discounts', $discounts );
			SPC()->log( 'Discount added: ' . $discount->get_code() );
			return $discount;
		}
		return false;
	}

	public function get_discounts() {
		return $this->discounts;
	}

	public function get_discount_names() {
		if ( $this->discount_names ) {
			return $this->discount_names;
		}
		return false;
	}

	public function has_discount() {
		if ( $this->get_discount() > 0 ) {
			return true;
		}
		return false;
	}

	public function has_discount_code( $code ) {
		if ( ! empty( $this->discounts ) ) {
			foreach ( $this->discounts as $discount ) {
				if ( $discount->get_code() === $code ) {
					return true;
				}
			}
		}
		return false;
	}

	public function has_discount_codes() {
		if ( ! empty( $this->discounts ) ) {
			return true;
		}
		return false;
	}

	public function remove_discount( $code ) {
		if ( empty( $code ) ) {
			return false;
		}
		$discounts = SPC()->session->get( 'discounts' );
		if ( ! empty( $discounts ) ) {
			foreach ( $discounts as $key => $discount_code ) {
				if ( $discount_code === $code ) {
					unset( $discounts[ $key ] );
					SPC()->session->set( 'discounts', $discounts );
					do_action( 'sunshine_cart_discount_removed', $discount_code );
					SPC()->log( 'Discount removed: ' . $discount_code );
					return true;
				}
			}
		}

		return false;
	}

	public function remove_all_discounts() {
		SPC()->session->set( 'discounts', null );
		do_action( 'sunshine_cart_discounts_removed' );
		SPC()->log( 'All discounts removed' );
	}

	public function get_subtotal() {

		$this->subtotal     = 0;
		$this->subtotal_tax = 0;
		if ( ! $this->is_empty() ) {

			$cart_items = $this->get_cart_items();

			foreach ( $cart_items as $item ) {
				// When item has line-item discount, use discounted total instead of original subtotal.
				if ( $item->get_discount() > 0 ) {
					// Use the discounted base total for items with line-item discounts.
					$this->subtotal     += $item->get_total();
					$this->subtotal_tax += $item->get_tax_total();
				} else {
					// Use regular subtotal for items without line-item discounts.
					$this->subtotal     += $item->get_subtotal();
					$this->subtotal_tax += $item->get_tax_total();
				}
			}
			if ( $this->subtotal < 0 ) {
				$this->subtotal = 0.00;
			}
		}

		return round( $this->subtotal, 2 );

	}

	public function get_subtotal_tax() {
		return number_format( $this->subtotal_tax, 2, '.', '' );
	}

	public function get_subtotal_formatted() {
		$price           = $this->get_subtotal();
		$price_formatted = sunshine_get_price_to_display( $price, $this->subtotal_tax );
		return $price_formatted;
	}

	public function get_discount() {
		return number_format( $this->discount, 2, '.', '' );
	}
	public function get_discount_formatted() {
		return '-' . sunshine_price( $this->get_discount() );
	}

	public function get_tax_rate() {

		/*
		// We could have changed something about the cart and need to find tax rate again, this is not a good idea.
		// We have already determined that there are no matching tax rates, so just return without recalculating again.
		if ( $this->tax_rate === false ) {
			return false;
		}

		// We have already determined tax rate so let's return it immediately.
		if ( ! empty( $this->tax_rate ) ) {
			return $this->tax_rate;
		}
		*/

		$taxes_enabled = SPC()->get_option( 'taxes_enabled' );
		if ( $this->is_empty() || ! $taxes_enabled ) {
			$this->tax_rate = false;
			return false;
		}

		$tax_rates = sunshine_get_tax_rates();
		if ( empty( $tax_rates ) || ! is_array( $tax_rates ) ) {
			$this->tax_rate = false;
			return false;
		}

		/*
		// Which address is used for taxes
		$tax_basis = SPC()->get_option( 'tax_basis' );
		if ( $tax_basis == 'billing' ) {
			$prefix = 'billing_';
		} else {
			$prefix = 'shipping_';
		}
		*/
		$prefix = 'shipping_';

		// Match address to tax rate
		$customer_country  = $this->get_checkout_data_item( $prefix . 'country' );
		$customer_state    = $this->get_checkout_data_item( $prefix . 'state' );
		$customer_postcode = $this->get_checkout_data_item( $prefix . 'postcode' );

		if ( empty( $customer_country ) && empty( $customer_state ) && empty( $customer_postcode ) && 'store' == SPC()->get_option( 'tax_default_location' ) ) {
			$customer_country  = SPC()->get_option( 'country' );
			$customer_state    = SPC()->get_option( 'state' );
			$customer_postcode = SPC()->get_option( 'postcode' );
		}

		/*
		if ( empty( $customer_country ) && empty( $customer_state ) && empty( $customer_postcode ) ) {
			$this->tax_rate = false;
			return false;
		}
		*/

		foreach ( $tax_rates as $tax_rate ) {

			if ( empty( $tax_rate['rate'] ) ) {
				continue;
			}

			/*
			if ( $this->get_delivery_method_id() == 'pickup' || $this->get_delivery_method_id() == 'download' ) {
				// Always get first tax rate when we do pickup or download delivery method.
				$this->tax_rate = $tax_rate;
				break;
			} else
			*/
			if ( ! empty( $tax_rate['postcode'] ) ) {
				$postcodes = explode( ',', str_replace( ' ', '', $tax_rate['postcode'] ) );
				if ( in_array( $customer_postcode, $postcodes ) ) {
					if ( ! empty( $tax_rate['state'] ) && $customer_state == $tax_rate['state'] ) {
						if ( ! empty( $tax_rate['country'] ) && $customer_country == $tax_rate['country'] ) {
							$this->tax_rate = $tax_rate;
							break;
						}
					}
				}
			} elseif ( ! empty( $tax_rate['state'] ) ) {
				if ( ! empty( $tax_rate['country'] ) && $customer_country == $tax_rate['country'] && ! empty( $tax_rate['state'] ) && $customer_state == $tax_rate['state'] ) {
					$this->tax_rate = $tax_rate;
					break;
				}
			} elseif ( ! empty( $tax_rate['country'] ) ) {
				if ( $customer_country == $tax_rate['country'] || $tax_rate['country'] == 'all' ) {
					$this->tax_rate = $tax_rate;
					break;
				}
			}
		}

		if ( ! $this->tax_rate ) {
			$this->tax_rate = false;
			return false;
		}

		// Convert to percentage.
		$this->tax_rate['rate'] = floatval( $this->tax_rate['rate'] ) / 100;

		return $this->tax_rate;

	}

	public function set_tax() {

		$this->tax      = 0;
		$this->tax_rate = $this->get_tax_rate();

		if ( ! $this->tax_rate ) {
			return;
		}

		$price_has_tax = SPC()->get_option( 'price_has_tax' );

		// When prices include tax, just sum up all the pre-calculated tax amounts.
		if ( 'yes' === $price_has_tax ) {

			// Sum item taxes.
			$contents = $this->get_cart_items();
			foreach ( $contents as $item ) {
				$this->tax += $item->get_tax_total();
			}

			// Add shipping tax.
			if ( $this->shipping_method && $this->shipping_method->is_taxable() ) {
				$this->tax += $this->shipping_method->get_tax();
			}
		} else {
			// When prices don't include tax, calculate tax on taxable amounts.
			$contents      = $this->get_cart_items();
			$taxable_total = 0;

			foreach ( $contents as $item ) {
				$this->tax += $item->get_tax_total();
			}

			// Is selected shipping taxable?
			$this->shipping_tax = 0;
			if ( $this->shipping_method && $this->shipping_method->is_taxable() ) {
				$taxable_total += $this->shipping_method->get_price();
			}

			if ( $this->discount ) {
				$taxable_total -= $this->discount_taxable_total;
			}

			if ( $taxable_total < 0 ) {
				$taxable_total = 0;
			}

			$taxable_total = apply_filters( 'sunshine_cart_taxable_total', $taxable_total, $this->tax_rate );

			if ( $taxable_total ) {
				$this->tax += $taxable_total * $this->tax_rate['rate'];
				$this->tax  = round( $this->tax, 2 );
			}
		}

		$this->tax = apply_filters( 'sunshine_cart_tax', $this->tax, $this->tax_rate );
		if ( empty( $this->tax ) ) {
			$this->tax = 0;
		}

		return $this->tax;

	}

	public function get_tax() {
		if ( $this->tax > 0.01 ) {
			return number_format( round( $this->tax, 2 ), 2, '.', '' );
		}
		return 0;
	}

	public function get_tax_formatted() {
		return sunshine_price( $this->get_tax() );
	}

	public function get_credits() {
		if ( ! $this->use_credits() ) {
			return 0;
		}
		return SPC()->customer->get_credits();
	}

	public function get_credits_applied() {
		if ( ! $this->use_credits() ) {
			return 0;
		}
		$total_credits               = SPC()->customer->get_credits();
		$order_total_without_credits = $this->get_total( array( 'credits' ) );
		$credits_applied             = $total_credits;
		if ( $total_credits > $order_total_without_credits ) {
			$credits_applied = $order_total_without_credits;
		}
		$this->credits = $credits_applied;
		return $this->credits;
	}
	public function get_credits_applied_formatted() {
		return sunshine_price( $this->get_credits_applied() );
	}

	public function use_credits() {
		return $this->get_checkout_data_item( 'use_credits' );
	}

	public function set_use_credits( $value ) {
		$this->use_credits = false;
		if ( ! empty( $value ) ) {
			$this->use_credits = true;
		}
		$this->set_checkout_data_item( 'use_credits', $this->use_credits );
		$this->update();
	}

	public function get_delivery_method() {
		return $this->delivery_method;
	}

	public function set_delivery_method( $method ) {
		if ( is_string( $method ) ) {
			// Let's see if this string is in the available instances
			$active_methods = sunshine_get_delivery_methods();
			if ( array_key_exists( $method, $active_methods ) ) {
				$this->delivery_method = sunshine_get_delivery_method_by_id( $method );
				$this->set_checkout_data_item( 'delivery_method', $method );
			}
		} else {
			$this->delivery_method = $method;
		}
		$this->set_checkout_data_item( 'delivery_method', $this->delivery_method->get_id() );
		$this->set_checkout_data_item( 'shipping_method', '' );
		$this->shipping_method = '';
	}

	public function get_delivery_method_id() {
		if ( $this->delivery_method ) {
			return $this->delivery_method->get_id();
		}
		return false;
	}

	// Must pass instance string or full object of shipping class
	public function set_shipping_method( $method ) {
		if ( is_string( $method ) ) {
			// Let's see if this string is in the available instances.
			$allowed_methods = sunshine_get_allowed_shipping_methods();
			if ( array_key_exists( $method, $allowed_methods ) ) {
				$this->shipping_method = sunshine_get_shipping_method_by_instance( $method );
			} else {
				$this->shipping_method = array_shift( $allowed_methods );
			}
		} else {
			$this->shipping_method = $method;
		}
		$this->set_checkout_data_item( 'shipping_method', $this->shipping_method->get_instance_id() );
	}

	public function get_payment_method() {
		return $this->payment_method;
	}

	public function set_payment_method( $method ) {
		if ( is_string( $method ) ) {
			// Let's see if this string is in the available instances
			$allowed_methods = sunshine_get_allowed_payment_methods();
			if ( array_key_exists( $method, $allowed_methods ) ) {
				$this->payment_method = sunshine_get_payment_method_by_id( $method );
			}
		} else {
			$this->payment_method = $method;
		}
		$this->set_checkout_data_item( 'payment_method', $this->payment_method->get_id() );
		$this->set_checkout_fields();
		$fee = $this->payment_method->get_fee();
		if ( ! empty( $fee ) ) {
			$this->add_fee( 'payment_method', $fee['amount'], $fee['name'] );
		} else {
			$this->remove_fee( 'payment_method' );
		}
		do_action( 'sunshine_set_payment_method', $this->payment_method );
	}

	public function needs_account() {

		$needs_account = false;

		if ( is_user_logged_in() ) {
			// Already logged in, don't need to create an account.
			$needs_account = true;
		} elseif ( ! SPC()->get_option( 'allow_guest_checkout' ) && ! SPC()->get_option( 'disable_signup' ) ) {
			$needs_account = true;
		} elseif ( ! SPC()->cart->is_empty() ) {
			foreach ( SPC()->cart->get_cart_items() as $item ) {
				if ( $item->product->needs_account() ) {
					$needs_account = true;
					break;
				}
			}
		}

		return apply_filters( 'sunshine_cart_needs_account', $needs_account );

	}

	public function needs_delivery() {

		$needs_delivery = false;

		if ( ! SPC()->cart->is_empty() ) {
			foreach ( SPC()->cart->get_cart_items() as $item ) {
				if ( $item->product->needs_delivery() ) {
					$needs_delivery = true;
					break;
				}
			}
		}

		return apply_filters( 'sunshine_cart_needs_delivery', $needs_delivery );
	}

	public function needs_shipping() {

		if ( empty( $this->cart ) ) {
			// $this->setup();
		}

		$needs_shipping = false;

		// If there are no shipping methods setup, then we absolutely don't need it.
		$allowed_shipping_methods = sunshine_get_allowed_shipping_methods();
		if ( empty( $allowed_shipping_methods ) || count( $allowed_shipping_methods ) == 0 ) {
			return false;
		}

		if ( ! SPC()->cart->is_empty() ) {
			foreach ( SPC()->cart->get_cart_items() as $item ) {
				if ( $item->product->needs_shipping() ) {
					$needs_shipping = true;
					break;
				}
			}
		}

		if ( $needs_shipping && ! empty( $this->delivery_method ) ) {
			$needs_shipping = $this->delivery_method->needs_shipping();
		}

		return apply_filters( 'sunshine_cart_needs_shipping', $needs_shipping );
	}

	public function get_shipping_method() {
		return $this->shipping_method;
	}

	public function get_shipping() {
		if ( ! $this->shipping_method ) {
			return 0;
		}
		return number_format( round( $this->shipping_method->get_price(), 2 ), 2, '.', '' );
	}

	public function get_shipping_tax() {
		if ( ! $this->shipping_method ) {
			return 0;
		}
		return number_format( round( $this->shipping_method->get_tax(), 2 ), 2, '.', '' );
	}

	public function get_shipping_formatted() {
		if ( empty( $this->shipping_method ) ) {
			return;
		}
		$price           = $this->get_shipping();
		$price_formatted = sunshine_get_price_to_display( $price, $this->get_shipping_tax() );
		return $price_formatted;
	}

	public function add_fee( $id, $amount, $name ) {
		$this->fees[ $id ] = array(
			'amount' => number_format( round( $amount, 2 ), 2, '.', '' ),
			'name'   => $name,
		);
	}

	public function remove_fee( $id ) {
		unset( $this->fees[ $id ] );
	}

	public function get_fees() {
		return $this->fees;
	}

	public function get_fees_total() {
		if ( ! empty( $this->fees ) ) {
			$total_fees = 0;
			foreach ( $this->fees as $fee ) {
				$total_fees += $fee['amount'];
			}
			return number_format( round( $total_fees, 2 ), 2, '.', '' );
		}
		return 0;
	}

	public function get_total( $exclude = array() ) {
		$total = 0;

		$totals = array(
			'subtotal'  => 0,
			'discounts' => 0,
			'shipping'  => 0,
			'tax'       => 0,
			'fees'      => 0,
		);

		// When prices include tax, calculate total differently.
		$price_has_tax = SPC()->get_option( 'price_has_tax' );

		if ( 'yes' === $price_has_tax && ! in_array( 'subtotal', $exclude ) ) {
			// For prices with tax, sum item totals (which already have line-item discounts applied).
			$cart_items  = $this->get_cart_items();
			$items_total = 0;

			if ( ! empty( $cart_items ) ) {
				foreach ( $cart_items as $item ) {
					$items_total += $item->get_total() + $item->get_tax_total();
				}
			}

			$totals['subtotal'] = $items_total;

			// Apply cart-level discounts (discount codes).
			// Note: Line-item discounts are already reflected in the subtotal.
			if ( ! in_array( 'discounts', $exclude ) ) {
				$cart_level_discounts = (float) $this->get_discount();
				if ( $cart_level_discounts > 0 ) {
					$totals['discounts'] = -$cart_level_discounts;
				}
			}

			// When prices include tax, add shipping with its tax.
			if ( ! in_array( 'shipping', $exclude ) ) {
				$shipping           = (float) $this->get_shipping();
				$shipping_tax       = (float) $this->get_shipping_tax();
				$totals['shipping'] = $shipping + $shipping_tax;
			}
		} else {
			// Logic for prices without tax.
			if ( ! in_array( 'subtotal', $exclude ) ) {
				$subtotal           = (float) $this->get_subtotal();
				$totals['subtotal'] = $subtotal;
			}

			// Apply cart-level discounts (discount codes).
			// Note: Line-item discounts are already reflected in the subtotal.
			if ( ! in_array( 'discounts', $exclude ) ) {
				$cart_level_discounts = (float) $this->get_discount();
				if ( $cart_level_discounts > 0 ) {
					$totals['discounts'] = -$cart_level_discounts;
				}
			}

			if ( ! in_array( 'tax', $exclude ) ) {
				$tax           = (float) $this->get_tax();
				$totals['tax'] = $tax;
			}

			// When prices don't include tax, add shipping separately.
			if ( ! in_array( 'shipping', $exclude ) ) {
				$shipping           = (float) $this->get_shipping();
				$totals['shipping'] = $shipping;
			}
		}

		if ( ! in_array( 'fees', $exclude ) ) {
			$fees           = (float) $this->get_fees_total();
			$totals['fees'] = $fees;
		}

		if ( ! in_array( 'credits', $exclude ) ) {
			$credits = (float) $this->get_credits_applied();
			if ( $credits > 0 ) {
				$totals['credits'] = -$credits;
			}
		}

		$total = array_sum( $totals );

		$total = max( $total, 0 );

		$this->total = apply_filters( 'sunshine_get_cart_total', $total, $this );
		$this->total = number_format( round( $this->total, 2 ), 2, '.', '' );

		return $this->total;
	}

	public function get_total_formatted() {
		/*
		$total = $this->get_total( array( 'tax' ) );
		if ( $total > 0 ) {
			$total += $this->get_tax();
		}
		*/
		$total_formatted = sunshine_get_price_to_display( $this->get_total() );
		return $total_formatted;
	}

	public function update_item_quantity( $key, $qty ) {
		if ( array_key_exists( $key, $this->cart ) ) {
			if ( ! empty( $this->cart[ $key ]['product_id'] ) ) {
				$product = sunshine_get_product( $this->cart[ $key ]['product_id'] );
				if ( $product->exists() ) {
					$max_qty = $product->get_max_qty();
					if ( $max_qty && $qty > $max_qty ) {
						$qty = $max_qty;
					}
					$min_qty = $product->get_min_qty();
					if ( $min_qty && $qty < $min_qty ) {
						$qty = $min_qty;
					}
				}
			}
			$this->cart[ $key ]['qty'] = intval( $qty );
			$this->cart[ $key ]        = apply_filters( 'sunshine_add_to_cart_item', $this->cart[ $key ] );
			$this->update_cart();
		}
	}

	public function update_item_quantity_by_hash( $hash, $qty ) {
		if ( ! empty( $this->cart ) ) {
			foreach ( $this->cart as $key => $current_item ) {
				if ( $current_item['hash'] == $hash ) {
					$this->update_item_quantity( $key, $qty );
				}
			}
		}
		return false;
	}

	public function update_item_price( $key, $price ) {
		if ( array_key_exists( $key, $this->cart ) ) {
			$this->cart[ $key ]['price'] = floatval( $price );
			$this->update_cart();
		}
	}

	public function update_item_price_by_hash( $hash, $price ) {
		if ( ! empty( $this->cart ) ) {
			foreach ( $this->cart as $key => $current_item ) {
				if ( $current_item['hash'] == $hash ) {
					$this->update_item_price( $key, $price );
				}
			}
		}
		return false;
	}

	public function update_item_meta( $key, $meta_key, $value ) {
		if ( array_key_exists( $key, $this->cart ) ) {
			$this->cart[ $key ]['meta'][ $meta_key ] = $value;
			$this->update_cart();
		}
	}

	public function update_item_meta_by_hash( $hash, $meta_key, $value ) {
		if ( ! empty( $this->cart ) ) {
			foreach ( $this->cart as $key => $current_item ) {
				if ( $current_item['hash'] == $hash ) {
					$this->update_item_meta( $key, $meta_key, $value );
				}
			}
		}
		return false;
	}

	public function has_image( $image_id ) {
		if ( ! $this->is_empty() ) {
			foreach ( $this->cart as $item ) {
				if ( isset( $item['image_id'] ) && $item['image_id'] == $image_id ) {
					return true;
				}
			}
		}
		return false;
	}

	public function has_product( $product_id ) {
		if ( ! $this->is_empty() ) {
			foreach ( $this->cart as $item ) {
				if ( isset( $item['product_id'] ) && $item['product_id'] == $product_id ) {
					return true;
				}
			}
		}
		return false;
	}


	/*
	*
	* CHECKOUT
	*
	*/

	public function set_checkout_fields() {

		$general_fields          = SPC()->get_option( 'general_fields' );
		$required_general_fields = SPC()->get_option( 'required_general_fields' );
		if ( empty( $required_general_fields ) ) {
			$required_general_fields = array();
		}

		// Get allowed shipping methods from the start so delivery method section works.
		$allowed_shipping_methods = sunshine_get_allowed_shipping_methods();
		$shipping_methods         = array();
		if ( $allowed_shipping_methods ) {
			foreach ( $allowed_shipping_methods as $instance_id => $allowed_shipping_method ) {
				$this_shipping_method = sunshine_get_shipping_method_by_instance( $instance_id );
				$label                = $this_shipping_method->get_name();
				$price_html           = '';
				$price                = $this_shipping_method->get_price();
				$price_html           = '<span class="sunshine--checkout--shipping-method--price" data-price="' . esc_attr( $price ) . '">' . sunshine_get_price_to_display( $price, $this_shipping_method->get_tax() ) . '</span>';
				$description_html     = '';
				$description          = $this_shipping_method->get_description();
				if ( $description ) {
					$description_html = '<span class="sunshine--checkout--shipping-method--description">' . $description . '</span>';
				}
				$shipping_methods[ $instance_id ] = $label . $price_html . $description_html;
			}
		}

		$needs_account = $this->needs_account();

		$fields['contact'] = array(
			'name'   => __( 'Contact Information', 'sunshine-photo-cart' ),
			'fields' => array(
				array(
					'id'           => 'first_name',
					'type'         => 'text',
					'name'         => __( 'First Name', 'sunshine-photo-cart' ),
					'required'     => true,
					'default'      => ( $this->get_checkout_data_item( 'first_name' ) ) ? $this->get_checkout_data_item( 'first_name' ) : SPC()->customer->get_first_name(),
					'autocomplete' => 'given-name',
					'size'         => 'half',
				),
				array(
					'id'           => 'last_name',
					'type'         => 'text',
					'name'         => __( 'Last Name', 'sunshine-photo-cart' ),
					'required'     => true,
					'default'      => ( $this->get_checkout_data_item( 'last_name' ) ) ? $this->get_checkout_data_item( 'last_name' ) : SPC()->customer->get_last_name(),
					'autocomplete' => 'family-name',
					'size'         => 'half',
				),
				array(
					'id'           => 'email',
					'type'         => 'email',
					'name'         => __( 'Email', 'sunshine-photo-cart' ),
					'required'     => true,
					'default'      => ( $this->get_checkout_data_item( 'email' ) ) ? $this->get_checkout_data_item( 'email' ) : SPC()->customer->get_email(),
					'autocomplete' => 'email',
				),
				/*
				array(
					'id'          => 'create_account',
					'type'        => 'checkbox',
					'name'        => __( 'Create an account', 'sunshine-photo-cart' ),
					'description' => __( 'Create an account for easier access', 'sunshine-photo-cart' ),
					'visible'     => ! is_user_logged_in() && SPC()->get_option( 'allow_guest_checkout' ),
				),
				*/
				array(
					'id'           => 'password',
					'type'         => 'password',
					'name'         => __( 'Password', 'sunshine-photo-cart' ),
					'required'     => ( ! is_user_logged_in() && $needs_account && ! SPC()->get_option( 'disable_signup' ) ) ? true : false,
					'description'  => ( ! $needs_account ) ? __( 'Optionally set a password', 'sunshine-photo-cart' ) : '',
					'visible'      => ( ! is_user_logged_in() && ! SPC()->get_option( 'disable_signup' ) ) ? true : false,
					'autocomplete' => 'new-password',
					'default'      => null,
				),
				array(
					'id'           => 'phone',
					'type'         => 'tel',
					'name'         => __( 'Phone', 'sunshine-photo-cart' ),
					'required'     => ( in_array( 'phone', $required_general_fields ) ),
					'default'      => ( $this->get_checkout_data_item( 'phone' ) ) ? $this->get_checkout_data_item( 'phone' ) : SPC()->customer->get_phone(),
					'autocomplete' => 'tel',
					'visible'      => ( ( is_array( $general_fields ) && in_array( 'phone', $general_fields ) ) || ( is_array( $required_general_fields ) && in_array( 'phone', $required_general_fields ) ) ),
				),
				array(
					'id'       => 'vat',
					'type'     => 'text',
					'name'     => ( SPC()->get_option( 'vat_label' ) ) ? SPC()->get_option( 'vat_label' ) : __( 'EU VAT Number', 'sunshine-photo-cart' ),
					'required' => ( in_array( 'vat', $required_general_fields ) ),
					'default'  => $this->get_checkout_data_item( 'vat' ),
					'visible'  => ( ( is_array( $general_fields ) && in_array( 'vat', $general_fields ) ) || ( is_array( $required_general_fields ) && in_array( 'vat', $required_general_fields ) ) ),
				),
			),
		);

		if ( sunshine_checkout_section_completed( 'contact' ) ) {
			$fields['contact']['summary'] = $this->get_checkout_data_item( 'first_name' ) . ' ' . $this->get_checkout_data_item( 'last_name' ) . ', ' . $this->get_checkout_data_item( 'email' );
		}

		$fields['contact'] = apply_filters( 'sunshine_checkout_section_contact', $fields['contact'] );

		if ( $this->needs_delivery() ) {
			$delivery_methods = sunshine_get_delivery_methods();
			if ( ! empty( $delivery_methods['shipping'] ) && ! SPC()->cart->is_empty() ) {
				$cart_needs_shipping = false;
				foreach ( SPC()->cart->get_cart_items() as $item ) {
					if ( $item->product->needs_shipping() ) {
						$cart_needs_shipping = true;
						break;
					}
				}
				if ( ! $cart_needs_shipping ) {
					unset( $delivery_methods['shipping'] );
				}
			}

			$delivery_fields = array();
			if ( ! empty( $delivery_methods ) && is_array( $delivery_methods ) ) {
				if ( count( $delivery_methods ) == 1 && ! array_key_exists( 'pickup', $delivery_methods ) ) {
					$delivery_method_id                     = array_key_first( $delivery_methods );
					$this->hidden_fields['delivery_method'] = $delivery_methods[ $delivery_method_id ]['id'];
				} else {
					$delivery_methods_options = array();
					foreach ( $delivery_methods as $delivery_method ) {
						$delivery_method = sunshine_get_delivery_method_by_id( $delivery_method['id'] );
						$label           = $delivery_method->get_display_name();
						$description     = $delivery_method->get_description();
						if ( ! empty( $description ) ) {
							$label .= '<div class="sunshine--checkout--delivery-method--description">' . $description . '</div>';
						}
						$delivery_methods_options[ $delivery_method->get_id() ] = $label;
					}

					$delivery_fields = array(
						array(
							'id'       => 'delivery_method',
							'type'     => 'radio',
							// 'name' => __( 'Choose method of delivery', 'sunshine-photo-cart' ),
							'required' => true,
							'options'  => $delivery_methods_options,
							'default'  => ( ! $this->delivery_method ) ? array_key_first( $delivery_methods ) : $this->delivery_method->get_id(),
						),
					);
				}
			}

			if ( ! empty( $delivery_fields ) ) {
				$fields['delivery'] = array(
					'active' => true,
					'name'   => __( 'Delivery Method', 'sunshine-photo-cart' ),
					'fields' => $delivery_fields,
				);

				if ( sunshine_checkout_section_completed( 'delivery' ) && ! empty( $this->delivery_method ) ) {
					$fields['delivery']['summary'] = $this->delivery_method->get_name();
				}

				$fields['delivery'] = apply_filters( 'sunshine_checkout_section_delivery', $fields['delivery'] );
			}
		}

		if ( $this->needs_shipping() ) {

			$default_country = SPC()->customer->get_shipping_country();
			if ( $this->get_checkout_data_item( 'shipping_country' ) ) {
				$default_country = $this->get_checkout_data_item( 'shipping_country' );
			}

			$shipping_fields               = SPC()->countries->get_address_fields( $default_country, 'shipping_' );
			$shipping_fields[0]['default'] = ( is_user_logged_in() ) ? SPC()->customer->get_first_name() : $this->get_checkout_data_item( 'first_name' );
			$shipping_fields[1]['default'] = ( is_user_logged_in() ) ? SPC()->customer->get_last_name() : $this->get_checkout_data_item( 'last_name' );

			$fields['shipping'] = array(
				'active' => false,
				'name'   => __( 'Shipping Address', 'sunshine-photo-cart' ),
				'fields' => $shipping_fields,
			);

			if ( sunshine_checkout_section_completed( 'shipping' ) ) {
				$fields['shipping']['summary'] = SPC()->countries->get_formatted_address(
					array(
						'address1' => $this->get_checkout_data_item( 'shipping_address1' ),
						'address2' => $this->get_checkout_data_item( 'shipping_address2' ),
						'city'     => $this->get_checkout_data_item( 'shipping_city' ),
						'state'    => $this->get_checkout_data_item( 'shipping_state' ),
						'postcode' => $this->get_checkout_data_item( 'shipping_postcode' ),
						'country'  => $this->get_checkout_data_item( 'shipping_country' ),
					),
					', '
				);
			}

			$fields['shipping'] = apply_filters( 'sunshine_checkout_section_shipping', $fields['shipping'] );

			if ( ! empty( $shipping_methods ) ) {

				$default_shipping = '';
				if ( count( $allowed_shipping_methods ) == 1 ) {
					$default = array_key_first( $allowed_shipping_methods );
				} elseif ( ! empty( $this->shipping_method ) ) {
					$default = $this->shipping_method->get_instance_id();
				}

				$fields['shipping_method'] = array(
					'active' => false,
					'name'   => __( 'Shipping Method', 'sunshine-photo-cart' ),
					'fields' => array(
						array(
							'id'       => 'shipping_method',
							'type'     => 'radio',
							// 'name' => __( 'Shipping Method', 'sunshine-photo-cart' ),
							'options'  => $shipping_methods,
							'default'  => $default_shipping,
							'required' => true,
						),
					),
				);
				if ( sunshine_checkout_section_completed( 'shipping_method' ) && ! empty( $this->shipping_method ) ) {
					$fields['shipping_method']['summary'] = $this->shipping_method->get_name();
				}
			}
		} elseif ( SPC()->get_option( 'require_address' ) ) {

			$default_country   = SPC()->customer->get_shipping_country();
			$fields['address'] = array(
				'active' => false,
				'name'   => __( 'Address', 'sunshine-photo-cart' ),
				'fields' => SPC()->countries->get_address_fields( $default_country, 'customer_' ),
			);

			if ( sunshine_checkout_section_completed( 'address' ) ) {
				$fields['address']['summary'] = SPC()->countries->get_formatted_address(
					array(
						'address1' => $this->get_checkout_data_item( 'customer_address1' ),
						'address2' => $this->get_checkout_data_item( 'customer_address2' ),
						'city'     => $this->get_checkout_data_item( 'customer_city' ),
						'state'    => $this->get_checkout_data_item( 'customer_state' ),
						'postcode' => $this->get_checkout_data_item( 'customer_postcode' ),
						'country'  => $this->get_checkout_data_item( 'customer_country' ),
					),
					', '
				);
			}

			$fields['address'] = apply_filters( 'sunshine_checkout_section_address', $fields['address'] );

		} else {
			if ( ! empty( $fields['delivery']['fields'][0]['options']['shipping'] ) ) {
				// unset( $fields['delivery']['fields'][0]['options']['shipping'] );
			}
		}

		$order_total = $this->get_total();

		$payment_methods             = sunshine_get_allowed_payment_methods();
		$payment_methods_options     = $needs_billing = array();
		$payment_methods_field_extra = '';
		if ( ! empty( $payment_methods ) && is_array( $payment_methods ) ) {
			foreach ( $payment_methods as $id => $payment_method_class ) {
				$payment_methods_options[ $id ] = array(
					'label'       => $payment_method_class->get_name(),
					'description' => $payment_method_class->get_description(),
				);
				$this_payment_method_fields     = $payment_method_class->get_fields();
				if ( $this_payment_method_fields ) {
					// $payment_methods_field_extra .= '<div class="sunshine--checkout--payment-method--extra" id="sunshine--checkout--payment-method--extra--' . esc_attr( $id ) . '">' . $this_payment_method_fields . '</div>';
					$payment_methods_options[ $id ]['description'] .= '<div class="sunshine--checkout--payment-method--extra" id="sunshine--checkout--payment-method--extra--' . esc_attr( $id ) . '">' . $this_payment_method_fields . '</div>';
				}
				if ( $payment_method_class->needs_billing_address() ) {
					$needs_billing[] = $id;
				}
			}
		}

		$payment_methods_fields = array();

		$payment_methods_fields[] = array(
			'id'       => 'customer_notes',
			'type'     => 'textarea',
			'name'     => __( 'Notes', 'sunshine-photo-cart' ),
			'default'  => $this->get_checkout_data_item( 'customer_notes' ),
			'visible'  => ( ( is_array( $general_fields ) && in_array( 'notes', $general_fields ) ) || ( is_array( $required_general_fields ) && in_array( 'notes', $required_general_fields ) ) ),
			'required' => ( in_array( 'notes', $required_general_fields ) ),
		);

		$payment_methods_fields[] = array(
			'id'       => 'payment_method',
			'type'     => 'radio',
			'required' => ( $order_total > 0 ) ? true : false,
			// 'name' => __( 'Select Payment Method', 'sunshine-photo-cart' ),
			'options'  => $payment_methods_options,
			'visible'  => ( $order_total > 0 ) ? true : false,
			'default'  => 0,
			// 'default' => ( count( $payment_methods_options ) == 1 ) ? key( $payment_methods_options ) : ''
		);

		if ( ! empty( $payment_methods_field_extra ) ) {
			$payment_methods_fields[] = array(
				'id'   => 'payment_method_extra',
				'type' => 'html',
				'html' => $payment_methods_field_extra,
			);
		}

		$fields['payment'] = array(
			'active' => false,
			'name'   => __( 'Payment', 'sunshine-photo-cart' ),
			'fields' => $payment_methods_fields,
		);

		$billing_fields = array();
		if ( ! empty( $needs_billing ) && $order_total > 0 ) {

			if ( $this->needs_shipping() ) {
				$fields['payment']['fields'][] = array(
					'id'         => 'shipping_as_billing',
					'type'       => 'checkbox',
					'name'       => __( 'Use shipping address as billing address', 'sunshine-photo-cart' ),
					'default'    => 'yes',
					'conditions' => array(
						array(
							'field'         => 'payment_method',
							'compare'       => '==',
							'value'         => $needs_billing, // TODO: Figure out how to show these fields based on payment method
							'action'        => 'show',
							'action_target' => '#sunshine--form--field--shipping_as_billing',
						),
					),
				);
			}

			$shipping_as_billing_condition = array();
			if ( $this->needs_shipping() ) {
				$shipping_as_billing_condition = array(
					'field'   => 'shipping_as_billing',
					'compare' => '!=',
					'value'   => 'yes',
					'action'  => 'show',
				);
			}

			$fields['payment']['fields'][] = array(
				'id'         => 'billing_header',
				'type'       => 'legend',
				'name'       => __( 'Billing Address', 'sunshine-photo-cart' ),
				'conditions' => array(
					array(
						'field'   => 'payment_method',
						'compare' => 'IN',
						'value'   => $needs_billing,
						'action'  => 'show',
					),
					$shipping_as_billing_condition,
				),
			);

			$default_country = SPC()->customer->get_shipping_country();
			if ( $this->get_checkout_data_item( 'shipping_country' ) ) {
				$default_country = $this->get_checkout_data_item( 'shipping_country' );
			}
			$billing_address_fields = SPC()->countries->get_address_fields( $default_country, 'billing_' );
			foreach ( $billing_address_fields as $billing_address_field ) {

				$billing_address_field['conditions'] = array(
					array(
						'field'   => 'payment_method',
						'compare' => 'IN',
						'value'   => $needs_billing,
						'action'  => 'show',
					),
					$shipping_as_billing_condition,
				);
				$fields['payment']['fields'][]       = $billing_address_field;
			}
		}

		$credits = SPC()->customer->get_credits();
		if ( $credits && $this->get_total( array( 'credits' ) ) ) {
			$credits_field = array(
				'id'      => 'use_credits',
				'type'    => 'checkbox',
				/* translators: %s is the available credit amount formatted as price */
				'name'    => sprintf( __( 'Use my credits (%s available)', 'sunshine-photo-cart' ), sunshine_price( $credits ) ),
				'default' => $this->get_checkout_data_item( 'use_credits' ),
			);
			array_unshift( $fields['payment']['fields'], $credits_field );
		}

		if ( $order_total > 0 ) {
			/* translators: %s is the order total amount formatted as price */
			$submit_label = sprintf( __( 'Submit Order & Pay %s', 'sunshine-photo-cart' ), '<span class="sunshine-total">' . SPC()->cart->get_total_formatted() . '</span>' );
		} else {
			$submit_label = __( 'Submit Order for Free', 'sunshine-photo-cart' );
			$this->set_checkout_data_item( 'payment_method', 'free' );
			if ( $this->credits == 0 ) {
				$fields['payment']['name'] = '';
			}
		}
		$submit_label                  = apply_filters( 'sunshine_checkout_submit_label', $submit_label );
		$fields['payment']['fields'][] = array(
			'id'   => 'sunshine--checkout--submit',
			'type' => 'submit',
			'name' => $submit_label,
		);
		if ( SPC()->get_option( 'page_terms' ) ) {
			$fields['payment']['fields'][] = array(
				'id'   => 'terms',
				'type' => 'html',
				/* translators: %1$s is the URL to the terms page, %2$s is the title of the terms page */
				'html' => sprintf( __( 'By submitting this order, you agree to our <a href="%1$s" target="_blank">%2$s</a>', 'sunshine-photo-cart' ), get_permalink( SPC()->get_option( 'page_terms' ) ), get_the_title( SPC()->get_option( 'page_terms' ) ) ),
			);
		}

		$fields['payment'] = apply_filters( 'sunshine_checkout_section_payment', $fields['payment'] );

		$this->fields = apply_filters( 'sunshine_checkout_fields', $fields );

	}

	public function show_checkout_fields() {

		if ( empty( $this->fields ) ) {
			return;
		}

		// If no specific section set in URL, get the first one
		if ( empty( $this->active_section ) ) {
			$this->active_section = key( $this->fields );
		}

		$i = 1;
		foreach ( $this->fields as $section_id => $section ) {

			if ( empty( $section['fields'] ) ) {
				continue;
			}

			$classes = array();
			if ( $section_id == $this->active_section ) {
				$classes[] = 'sunshine--checkout--section-active';
			}
			$summary = '';
			if ( sunshine_checkout_section_completed( $section_id ) && $section_id != $this->active_section ) {
				$classes[] = 'sunshine--checkout--section-completed';
				if ( ! empty( $section['summary'] ) ) {
					$summary = '<div class="sunshine--checkout--section-summary">' . $section['summary'] . '</div>';
				}
			}
			echo '<fieldset id="sunshine--checkout--' . esc_attr( $section_id ) . '" class="' . esc_attr( join( ' ', $classes ) ) . '">';
			/* translators: %s is the section name */
			echo '<legend>' . esc_html( $section['name'] ) . '<button href="' . esc_url( add_query_arg( 'section', $section_id, sunshine_get_page_permalink( 'checkout' ) ) ) . '" class="sunshine--checkout--section-edit" data-section="' . esc_attr( $section_id ) . '" aria-label="' . esc_attr( sprintf( __( 'Edit %s', 'sunshine-photo-cart' ), $section['name'] ) ) . '">' . esc_html__( 'Edit', 'sunshine-photo-cart' ) . '</button></legend>';
			echo wp_kses_post( $summary );
			if ( $section_id == $this->active_section ) {
				echo '<input type="hidden" name="sunshine_checkout_section" value="' . esc_attr( $section_id ) . '" />';
				echo '<div class="sunshine--form--fields">';
				foreach ( $section['fields'] as $id => $field ) {
					if ( empty( $field['id'] ) ) {
						continue;
					}
					// Allow 3rd party content before submit button (e.g., captcha).
					if ( $field['id'] === 'sunshine--checkout--submit' ) {
						do_action( 'sunshine_checkout_before_submit' );
					}
					$this->show_checkout_field( $field['id'], $field );
				}
				echo '</div>';
				if ( $section_id != array_key_last( $this->fields ) ) {
					echo '<div id="sunshine--checkout--' . esc_attr( $section_id ) . '-button-step" class="sunshine--checkout--section-button">';
					echo '<button type="submit" class="sunshine--button button" data-section="' . esc_attr( $section_id ) . '">' . esc_html__( 'Next Step', 'sunshine-photo-cart' ) . '</button>';
					echo '</div>';
				}
			}
			echo '</fieldset>';
			$i++;

		}

		do_action( 'sunshine_checkout_after_' . $section_id );

		wp_nonce_field( 'sunshine_checkout', 'sunshine_checkout' );

	}


	public function get_checkout_field_html( $id, $field ) {

		if ( isset( $field['visible'] ) && ! $field['visible'] ) {
			return;
		}

		$value = '';

		if ( ! empty( $field['default'] ) ) {
			$value = $field['default'];
		}

		$checkout_data_value = $this->get_checkout_data_item( $id );
		if ( ! empty( $checkout_data_value ) && $id != 'payment_method' ) {
			$value = $checkout_data_value;
		}

		// Fallback to user's stored data
		if ( empty( $value ) && ( $id != 'payment_method' && $id != 'shipping_method' && $id != 'use_credits' ) ) {
			$value = SPC()->customer->get_meta( $id );
		}

		return sunshine_form_field( $id, $field, $value, false );

	}

	public function show_checkout_field( $id, $field ) {
		// Everything has been fully escaped by the previous functions used to build this massive HTML string.
		echo $this->get_checkout_field_html( $id, $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// This only happens on ajax call.
	public function process_section( $section, $data ) {

		SPC()->log( 'Processing checkout section: ' . $section );

		$this->active_section = sanitize_key( $section );

		// Validate all fields in this section
		if ( empty( $this->fields[ $this->active_section ] ) || empty( $this->fields[ $this->active_section ]['fields'] ) ) {
			SPC()->log( 'Invalid checkout section' );
			SPC()->notices->add( __( 'Invalid checkout section', 'sunshine-photo-cart' ) );
			return;
		}

		$errors = array();

		foreach ( $this->fields[ $this->active_section ]['fields'] as $field ) {

			if ( empty( $field['type'] ) || $field['type'] == 'submit' || $field['type'] == 'legend' ) {
				continue;
			}

			$value = ! empty( $data[ $field['id'] ] ) ? $data[ $field['id'] ] : '';

			if ( is_serialized( $value ) ) {
				// Do now allow serialized data to be entered at checkout.
				$this->add_error( __( 'Field contains invalid data', 'sunshine-photo-cart' ) );
				$value = '';
			}

			// Save this value to checkout session data (even if invalid, so when we reload form it can prepopulate still)
			if ( $field['type'] != 'password' ) {
				$this->set_checkout_data_item( $field['id'], $value );
			}

			// Check conditional state, is this even showing to the user to truly make it required?
			if ( ! empty( $field['conditions'] ) ) {
				foreach ( $field['conditions'] as $condition ) {
					if ( empty( $condition['field'] ) || empty( $condition['value'] ) || empty( $condition['compare'] ) || empty( $condition['action'] ) ) {
						continue;
					}
					$comparison_field       = $this->get_checkout_field( $condition['field'] );
					$comparison_field_value = ! empty( $data[ $condition['field'] ] ) ? $data[ $condition['field'] ] : '';
					$comparison_state       = sunshine_value_comparison( $comparison_field_value, $condition['value'], $condition['compare'] );

					if ( ( $comparison_state && $condition['action'] == 'show' ) || ( ! $comparison_state && $condition['action'] == 'hide' ) ) {
						// This field is shown and thus subject to additional validation so it it go through
					} else {
						// Field not being shown so don't validate, go to next field
						continue 2;
					}
				}
			}

			if ( isset( $field['required'] ) && $field['required'] && empty( $value ) ) {
				SPC()->log( 'Required field is empty: ' . $field['name'] );
				/* translators: %s is the field name */
				$this->add_error( sprintf( __( '%s is required', 'sunshine-photo-cart' ), $field['name'] ) );
				continue;
			}

			if ( empty( $value ) ) { // No need to try and validate any further if nothing passed
				continue;
			}

			switch ( $field['type'] ) {
				case 'email':
					if ( ! is_email( $value ) ) {
						SPC()->log( 'Invalid email' );
						$this->add_error( __( 'Invalid email', 'sunshine-photo-cart' ) );
					}
					break;
			}

			// Let hooks take a stab at validation if we still have no errors yet.
			if ( empty( $this->errors[ $field['id'] ] ) ) {
				$error = apply_filters( 'sunshine_validate_' . $field['type'], false, $value, $field );
				if ( $error ) {
					SPC()->log( 'Validation error: ' . $error );
					$this->errors[ $field['id'] ] = $error;
				}
			}
		}

		// This is where other add-ons can hook in to check stuff.
		do_action( 'sunshine_checkout_validation', $this->active_section, $data );

		/*
		if ( $section == 'payment' ) {
			$this->process_payment();
		}
		*/

		if ( $this->has_errors() ) {
			SPC()->log( 'Checkout has errors and cannot process section: ' . json_encode( $this->get_errors() ) );
			return false;
		}

		// Set section to completed via session.
		end( $this->fields );
		$checkout_sections_completed = SPC()->session->get( 'checkout_sections_completed' );
		if ( ! $checkout_sections_completed ) {
			$checkout_sections_completed = array();
		}

		if ( $this->active_section != key( $this->fields ) && ! sunshine_checkout_section_completed( $this->active_section ) ) {
			$checkout_sections_completed[] = $this->active_section;
			SPC()->session->set( 'checkout_sections_completed', $checkout_sections_completed );
			SPC()->log( 'Checkout section completed: ' . $this->active_section );
			SPC()->log( 'All checkout sections completed: ' . json_encode( $checkout_sections_completed ) );
		}

		$next_section = '';
		$sections     = array_keys( $this->fields ); // Get all section keys.

		foreach ( $sections as $section ) {
			if ( ! in_array( $section, $checkout_sections_completed ) ) {
				$next_section = $section;
				break;
			}
		}

		$this->update();

		do_action( 'sunshine_checkout_section_' . $this->active_section . '_process', $data );

		return $next_section;

	}

	public function process_payment() {

		if ( ! isset( $_POST['sunshine_checkout'] ) || $this->active_section != 'payment' ) {
			return false;
		}

		if ( ! wp_verify_nonce( $_POST['sunshine_checkout'], 'sunshine_checkout' ) ) {
			SPC()->log( 'Invalid checkout nonce' );
			return false;
		}

		SPC()->log( 'Processing payment' );

		if ( ! empty( $_POST['payment_method'] ) ) {
			SPC()->log( 'Processing payment for ' . $_POST['payment_method'] );
			$this->process_section( 'payment', $_POST );
		}

		// If we have any errors then we do not process payment.
		if ( $this->has_errors() ) {
			SPC()->log( 'Checkout has errors and cannot process payment: ' . json_encode( $this->get_errors() ) );
			return false;
		}

		$order = $this->process_order();
		if ( $order ) {
			$url = apply_filters( 'sunshine_checkout_redirect', $order->get_received_permalink(), $order );
			SPC()->log( 'Checkout created new order and is redirecting' );
			wp_safe_redirect( $url );
			exit;
		}

	}

	public function get_checkout_field( $field_id ) {
		foreach ( $this->fields as $section_id => $section ) {
			foreach ( $section['fields'] as $field ) {
				if ( $field['id'] == $field_id ) {
					return $field;
				}
			}
		}
		return false;
	}

	public function set_checkout_data_item( $key, $value = '' ) {
		$data                         = $this->get_checkout_data();
		$data[ sanitize_key( $key ) ] = sanitize_text_field( $value );
		SPC()->session->set( 'checkout_data', $data );
	}

	public function get_checkout_data() {
		$data = SPC()->session->get( 'checkout_data' );
		if ( empty( $data ) ) {
			$data = array();
		}
		return $data;
	}

	public function get_checkout_data_item( $key ) {
		$data = $this->get_checkout_data();
		if ( ! empty( $data[ $key ] ) ) {
			return $data[ $key ];
		}
		// If user is logged in, let's look for their customer info as a fallback.
		if ( is_user_logged_in() ) {
			$value = false;
			switch ( $key ) {
				case 'first_name':
					$value = SPC()->customer->get_first_name();
					break;
				case 'last_name':
					$value = SPC()->customer->get_last_name();
					break;
				case 'email':
					$value = SPC()->customer->get_email();
					break;
				case 'phone':
					$value = SPC()->customer->get_phone();
					break;
				case 'shipping_address1':
					$value = SPC()->customer->get_shipping_address1();
					break;
				case 'shipping_address2':
					$value = SPC()->customer->get_shipping_address2();
					break;
				case 'shipping_state':
					$value = SPC()->customer->get_shipping_state();
					break;
				case 'shipping_postcode':
					$value = SPC()->customer->get_shipping_postcode();
					break;
				case 'shipping_country':
					$value = SPC()->customer->get_shipping_country();
					break;
			}
			return $value;
		}
		return false;
	}

	public function get_checkout_fields() {
		return $this->fields;
	}

	public function init_order() {

		if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( $_POST['security'], 'sunshine-checkout-init-order' ) ) {
			wp_send_json_error();
		}

		$payment_method = ! empty( $_POST['payment_method'] ) ? sanitize_text_field( $_POST['payment_method'] ) : '';

		$order_id = SPC()->session->get( 'checkout_order_id' );
		if ( $order_id ) {
			$order = sunshine_get_order( $order_id );
			if ( $order && $order->exists() && ! $order->is_paid() ) {
				SPC()->log( 'Order already exists during init order so using existing order to update: ' . $order_id );
			} elseif ( $order && $order->exists() && $order->get_status() != 'pending' ) {
				SPC()->log( 'Order is not the expected status of pending so something went wrong during checkout process: ' . $order->get_status() );
				$url = $order->get_received_permalink();
				$url = add_query_arg( 'sunshine_order_retrieve', 1, $url );
				$this->add_error( __( 'Error encountered during checkout process and your order has already been marked as paid.', 'sunshine-photo-cart' ) . ' <a href="' . $url . '">' . __( 'View the order', 'sunshine-photo-cart' ) . '</a>' );
				SPC()->session->set( 'checkout_order_id', '' );
				wp_send_json_error();
			}
		} else {
			SPC()->log( 'Initializing new order...' );
			$order = new SPC_Order();
			SPC()->session->set( 'checkout_order_id', '' );
		}

		if ( SPC()->cart->get_total() > 0 && empty( $payment_method ) ) {
			SPC()->log( 'No payment method selected and order has a subtotal so adding error' );
			$this->add_error( __( 'No payment method selected', 'sunshine-photo-cart' ) );
			wp_send_json_error();
		}

		if ( is_user_logged_in() ) {
			$order->update_meta_value( 'customer_id', get_current_user_id() );
		}

		if ( ! empty( $_POST['customer_notes'] ) ) {
			$order->update_meta_value( 'customer_notes', sanitize_textarea_field( $_POST['customer_notes'] ) );
		}

		// Setting all various meta data including delivery method, shipping method, payment method.
		$data = $this->get_checkout_data();

		// If billing is not different, then assign all shipping data to billing.
		if ( ! empty( $data['shipping_as_billing'] ) && 'yes' == $data['shipping_as_billing'] ) {
			foreach ( $data as $key => $value ) {
				if ( strpos( $key, 'shipping_' ) !== false && $key != 'shipping_as_billing' ) {
					$billing_key          = str_replace( 'shipping_', 'billing_', $key );
					$data[ $billing_key ] = $value;
				}
			}
		} elseif ( isset( $data['customer_address1'] ) ) {
			foreach ( $data as $key => $value ) {
				if ( strpos( $key, 'customer_' ) !== false ) {
					$billing_key = str_replace( 'customer_', 'billing_', $key );
					unset( $data[ 'customer_' . $key ] ); // remove the customer_ stuff from data.
					$data[ $billing_key ] = $value;
				}
			}
		}

		$order->set_cart( $this->get_cart() );
		$order->set_subtotal( $this->get_subtotal() );
		$order->set_subtotal_tax( $this->get_subtotal_tax() );
		$order->set_shipping( $this->get_shipping() );
		$order->set_shipping_tax( $this->get_shipping_tax() );
		$order->set_tax( $this->get_tax() );
		$order->set_discount( $this->get_discount() );
		$order->set_discount_names( $this->get_discount_names() );
		$order->set_discounts( $this->get_discounts() );
		$order->set_credits( $this->get_credits_applied() );
		$order->set_total( $this->get_total() );
		$order->set_price_has_tax( SPC()->get_option( 'price_has_tax' ) );

		$fees = $this->get_fees();
		$order->update_fees( $fees );

		if ( ! empty( $this->delivery_method ) ) {
			$order->set_delivery_method( $this->delivery_method->get_id() );
		}
		if ( ! empty( $this->shipping_method ) ) {
			$order->set_shipping_method( $this->shipping_method->get_instance_id() );
		}
		if ( ! empty( $this->payment_method ) ) {
			$order->set_payment_method( $this->payment_method->get_id() );
		}

		$order->set_mode( apply_filters( 'sunshine_checkout_create_order_mode', 'live', $order ) );

		foreach ( $data as $key => $value ) {
			$order->update_meta_value( $key, $value );
			if ( $key !== 'customer_notes' ) {
				SPC()->customer->update_meta( $key, $value );
			}
		}

		$order_result = $order->save();

		SPC()->log( 'Saving order: ' . $order->get_id() );

		if ( $order_result && $order->get_id() > 0 ) {

			SPC()->log( 'Order initialized and saved: ' . $order->get_id() );

			do_action( 'sunshine_checkout_init_order_success', $order );

			SPC()->session->set( 'checkout_order_id', $order->get_id() );

			$order_data = array(
				'checkout_data' => SPC()->cart->get_checkout_data(),
				'order_id'      => $order->get_id(),
			);

			SPC()->log( 'Successfully initialized order and returning order data' );

			wp_send_json_success( $order_data );
		}

		$this->add_error( __( 'Failed to initialize order', 'sunshine-photo-cart' ) );
		SPC()->log( 'Failed to initialize order and returning error' );

		wp_send_json_error();
	}

	public function process_order() {

		$order_id = SPC()->session->get( 'checkout_order_id' );
		if ( ! $order_id ) {
			SPC()->log( 'Could not process order - no order ID' );
			$this->add_error( __( 'Something happened while trying to process your order. Please contact the site owner for more details.', 'sunshine-photo-cart' ) );
			return false;
		}

		SPC()->log( 'Processing order: ' . $order_id );

		$order = sunshine_get_order( $order_id );

		// CRITICAL: Check if order is already paid to prevent double processing
		// This can happen if form is submitted multiple times or user refreshes during processing.
		if ( $order->is_paid() ) {
			SPC()->log( 'Order ' . $order_id . ' is already paid, skipping processing and returning existing order' );
			return $order;
		}

		$data = $this->get_checkout_data();

		/*
		foreach ( $data as $key => $value ) {
			$order->update_meta_value( $key, $value );
			SPC()->customer->update_meta( $key, $value );
		}
		*/

		// do_action( 'sunshine_checkout_process_payment', $this );
		if ( $this->get_checkout_data_item( 'payment_method' ) ) {
			SPC()->log( 'Processing payment action: ' . $this->get_checkout_data_item( 'payment_method' ) );
			do_action( 'sunshine_checkout_process_payment_' . $this->get_checkout_data_item( 'payment_method' ), $order );
		} elseif ( $order->get_total() > 0 ) {
			$this->add_error( __( 'No payment method', 'sunshine-photo-cart' ) );
		}

		// Recheck for errors after attempting to process payment.
		if ( $this->has_errors() ) {
			SPC()->log( 'Payment processing errors: ' . json_encode( $this->get_errors() ) );
			return false;
		}

		$do_post_process = apply_filters( 'sunshine_checkout_post_process_order', true, $order, $data );
		if ( $do_post_process ) {
			SPC()->log( 'Doing post process in process_order' );
			$this->post_process_order( $order );
		} else {
			SPC()->log( 'Not doing post process in process_order' );
		}

		SPC()->log( 'Doing sunshine_checkout_create_order' );
		do_action( 'sunshine_checkout_create_order', $order, $data );

		if ( apply_filters( 'sunshine_order_clear_cart', true, $order ) ) {
			// Clear checkout data from session data.
			SPC()->session->set( 'checkout_data', '' );
			SPC()->session->set( 'checkout_sections_completed', '' );
			SPC()->cart->empty_cart();
			SPC()->log( 'Cleared cart' );
		}

		SPC()->log( 'Setting checkout order id to empty' );
		SPC()->session->set( 'checkout_order_id', '' );

		return $order;

	}

	public function post_process_order( $order ) {

		SPC()->log( 'Post processing order: ' . $order->get_id() );

		// Set order status.
		$order_status = '';
		$order_status = apply_filters( 'sunshine_create_order_status', $order_status, $order );
		if ( ! empty( $order_status ) ) {
			$order->set_status( $order_status, 'Checkout is setting status to ' . $order_status );
		}

		if ( $order->get_credits() > 0 ) {
			SPC()->customer->decrease_credits( $order->get_credits() );
			SPC()->log( 'Decreasing customer credits by ' . $order->get_credits() );
		}

		$customer = $order->get_customer();
		if ( $customer ) {
			SPC()->log( 'Recalculating customer stats' );
			$customer->recalculate_stats();
		}

		// Do discount use counts.
		if ( $order->get_discounts() ) {
			SPC()->log( 'Processing discount usage from order: ' . $order->get_id() );
			foreach ( $order->get_discounts() as $discount_code ) {
				$discount = sunshine_get_discount_by_code( $discount_code );
				$discount->increment_use_count();
				$discount->increment_use_count_by( $order->get_email() );
				if ( is_user_logged_in() ) {
					$discount->increment_use_count_by( get_current_user_id() );
				}
			}
		}

		if ( apply_filters( 'sunshine_checkout_allow_order_notify', true, $order ) ) {
			SPC()->log( 'Notifying order: ' . $order->get_id() );
			do_action( 'sunshine_order_notify', $order );
		}

		SPC()->session->set( 'checkout_order_id', '' );

	}

	public function get_active_section() {
		return $this->active_section;
	}

	public function create_customer( $data ) {
		if ( ! is_user_logged_in() && ! empty( $data['email'] ) ) {

			// Get user by email address.
			$user = get_user_by( 'email', $data['email'] );
			if ( $user ) {
				SPC()->log( 'User account already exists with that email address' );
				$this->add_error( __( 'User account already exists with that email address - please login to continue', 'sunshine-photo-cart' ) );
				wp_send_json_error();
			}

			if ( ! empty( $data['password'] ) ) {

				SPC()->log( 'Creating customer at checkout' );

				$args    = array(
					'user_login' => $data['email'],
					'user_email' => $data['email'],
					'user_pass'  => $data['password'],
					'first_name' => $data['first_name'],
					'last_name'  => $data['last_name'],
					'role'       => sunshine_get_customer_role(),
				);
				$user_id = wp_insert_user( $args );
				if ( is_wp_error( $user_id ) ) {
					SPC()->log( 'Error creating user: ' . $user_id->get_error_message() );
					$this->add_error( $user_id->get_error_message() );
					wp_send_json_error();
				}

				$creds = array(
					'user_login'    => $data['email'],
					'user_password' => $data['password'],
					'remember'      => true,
				);
				$login = wp_signon( $creds, is_ssl() );
				if ( is_wp_error( $login ) ) {
					SPC()->log( 'Error logging in user: ' . $login->get_error_message() );
					$this->add_error( $login->get_error_message() );
					wp_send_json_error();
				}

				$customer       = new SPC_Customer( $user_id );
				SPC()->customer = $customer;

				do_action( 'sunshine_after_signup', $customer, $data['email'] );

				SPC()->session->set( 'checkout_refresh', true );

			}
		}
	}

	public function order_retrieve() {

		// If doing ajax, don't bother.
		if ( wp_doing_ajax() ) {
			return;
		}

		// If we are getting the order let's clear the checkout order id so we don't keep showing this issue.
		if ( isset( $_GET['sunshine_order_retrieve'] ) ) {
			SPC()->session->set( 'checkout_order_id', '' );
			return;
		}

		$checkout_order_id = SPC()->session->get( 'checkout_order_id' );
		if ( ! empty( $checkout_order_id ) ) {
			SPC()->log( 'We found a checkout order id on a page load...' );
			$order = sunshine_get_order( $checkout_order_id );
			if ( $order->exists() && $order->is_paid() ) {
				// We have an issue here. An order is paid but still in the session.
				$url = $order->get_received_permalink();
				$url = add_query_arg( 'sunshine_order_retrieve', 1, $url );
				$this->add_error( __( 'It appears your previous checkout has already been processed.', 'sunshine-photo-cart' ) . ' <a href="' . $url . '">' . __( 'View the order', 'sunshine-photo-cart' ) . '</a>' );
			} else {
				SPC()->log( 'but order is not yet marked as paid' );
			}
		}

	}
}
