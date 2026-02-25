<?php
class SPC_Product extends Sunshine_Data {

	protected $post_type = 'sunshine-product';
	protected $price_level; // int
	protected $price; // float
	protected $category; // int
	protected $options;
	protected $meta = array(
		'type'     => '',
		'taxable'  => '',
		'shipping' => '',
		'options'  => '',
	);

	public function __construct( $object = '', $price_level = '' ) {

		if ( is_numeric( $object ) && $object > 0 ) {
			$object = get_post( $object );
			if ( empty( $object ) || $object->post_type != $this->post_type ) {
				return false;
			}
		} elseif ( is_a( $object, 'WP_Post' ) || is_a( $object, 'SPC_Product' ) ) {
			if ( $object->post_type != $this->post_type || $object->post_status != 'publish' ) {
				return false;
			}
		}

		if ( ! empty( $object->ID ) ) {
			$this->id   = $object->ID;
			$this->data = $object;

			if ( $object->post_title ) {
				$this->name = $object->post_title;
			}

			$this->set_meta_data();

			if ( ! empty( $price_level ) ) {
				// sunshine_log( $price_level );
				$this->price_level = intval( $price_level );
			}
		}

	}

	/*
	$type = string, 'print', 'download', etc.
	*/
	public function set_type( $type ) {
		$this->meta['type'] = $type;
	}

	public function get_type() {
		return $this->meta['type'];
	}

	/*
	$price_level = int
	*/
	public function set_price_level( $price_level ) {
		$this->price_level = intval( $price_level );
	}

	public function get_price_level() {
		if ( empty( $this->price_level ) ) {
			$price_level = sunshine_get_default_price_level();
			if ( ! empty( $price_level ) ) {
				$this->price_level = $price_level->get_id();
			}
		}
		return apply_filters( 'sunshine_product_price_level', $this->price_level, $this );
	}

	public function get_prices() {
		return $this->get_meta_value( 'price' );
	}

	public function set_price( $price, $price_level_id = '' ) {
		if ( empty( $price_level_id ) ) {
			$default_price_level = sunshine_get_default_price_level();
			if ( $default_price_level ) {
				$price_level_id = $default_price_level->get_id();
			} else {
				return false; // We at minimum need to know a price level.
			}
		}
		if ( $price != '' ) {
			$price = floatval( $price );
		}
		$prices                    = $this->get_prices();
		$prices[ $price_level_id ] = $price;
		$this->update_meta_value( 'price', $prices );
	}

	public function get_price( $price_level_id = '', $options = array(), $regular = false ) {

		if ( empty( $price_level_id ) ) {
			$price_level_id = $this->price_level;
		}

		if ( empty( $price_level_id ) ) {
			$default_price_level = sunshine_get_default_price_level();
			if ( $default_price_level ) {
				$price_level_id = $default_price_level->get_id();
			} else {
				return false; // We at minimum need to know a price level.
			}
		}

		$price  = '';
		$prices = $this->get_prices();
		if ( ! empty( $prices ) && is_array( $prices ) && array_key_exists( $price_level_id, $prices ) && $prices[ $price_level_id ] != '' ) {
			$price = sunshine_sanitize_amount( $prices[ $price_level_id ] );
		}
		// sunshine_log( $prices, 'prices for ' . $this->get_name() . ': ' . $price_level_id );

		if ( ! empty( $options ) ) {
			$product_options = $this->get_options();
			if ( ! empty( $product_options ) ) {
				// Loop through avaiable options and compare to selected options to make sure nobody is gaming the pricing here.
				foreach ( $product_options as $id => $product_option ) {
					if ( array_key_exists( $id, $options ) ) {
						$selected_option      = $options[ $id ];
						$product_option_items = $product_option->get_items();
						if ( ! empty( $product_option_items ) ) {
							if ( $product_option->get_type() == 'checkbox' ) {
								$price += $product_option_items[0]['price'];
							} else {
								foreach ( $product_option_items as $item_id => $product_option_item ) {
									if ( $selected_option == $item_id ) {
										$price += $product_option_item['price'];
									}
								}
							}
						}
					}
				}
			}
		}

		if ( ! $regular ) {
			$price = apply_filters( 'sunshine_product_price', $price, $this );
		}

		return ( $price != '' ) ? floatval( $price ) : '';

	}

	public function get_price_formatted( $price_level = '', $empty = '&mdash;' ) {
		$price = $this->get_price( $price_level );
		if ( $price != '' ) {

			$price_formatted = '';

			$regular_price = $this->get_price( $price_level, '', true );
			if ( $regular_price != $price ) {
				$price_formatted .= '<s>' . sunshine_price( $regular_price ) . '</s> ';
			}

			// Display with tax if needed.
			if ( SPC()->get_option( 'price_has_tax' ) === 'no' && SPC()->get_option( 'display_price' ) === 'with_tax' && $this->is_taxable() ) {
				$tax_rate = SPC()->cart->get_tax_rate();
				if ( ! empty( $tax_rate ) ) {
					$price = round( $price * ( 1 + $tax_rate['rate'] ), 2 );
				}
			}

			$price_formatted .= sunshine_price( $price );

			// Do the suffix
			if ( SPC()->get_option( 'price_suffix' ) ) {
				$price_formatted .= ' <small class="sunshine--price--suffix">' . SPC()->get_option( 'price_suffix' ) . '</small>';
			}

			return apply_filters( 'sunshine_product_price_formatted', $price_formatted, $this );

		}

		return $empty;
	}

	public function get_regular_price( $price_level_id = '', $options = array() ) {
		return $this->get_price( $price_level_id, $options, true );
	}

	public function get_regular_price_with_tax( $price_level_id = '', $options = array() ) {
		$price = $this->get_price( $price_level_id, $options, true );
		if ( ! $this->is_taxable() ) {
			return $price;
		}
		$tax_rate = SPC()->cart->get_tax_rate();
		// Only do if we have a price, product is taxable and we have a matched tax rate.
		if ( $price && ! empty( $tax_rate ) ) {
			$price_has_tax = SPC()->get_option( 'price_has_tax' );
			if ( 'yes' !== $price_has_tax ) {
				// Take out tax from current price and lower price adjusting for tax amount.
				$price = $price * ( $tax_rate['rate'] + 1 );
			}
		}
		return $price;
	}

	public function has_image() {
		return ( $this->get_image_id() > 0 ) ? true : false;
	}
	public function get_image_id() {
		return get_post_thumbnail_id( $this->get_id() );
	}
	public function get_image_url( $size = 'thumbnail' ) {
		$product_image_id = $this->get_image_id();
		if ( $product_image_id ) {
			return wp_get_attachment_image_url( $product_image_id, $size );
		}
		return false;
	}
	public function get_image_html( $size = 'thumbnail', $echo = true ) {
		if ( ! $this->has_image() ) {
			return false;
		}
		$output_safe = '<img src="' . esc_url( $this->get_image_url( $size ) ) . '" alt="' . esc_attr( $this->get_name() ) . '" />';
		if ( $echo ) {
			echo wp_kses_post( $output_safe );
			return;
		}
		return $output_safe;
	}

	/*
	$category = int
	*/
	public function set_category( $category ) {
		if ( is_a( $category, 'SPC_Product_Category' ) ) {
			$this->category = $category;
		} else {
			$this->category = new SPC_Product_Category( $category );
		}
	}

	public function get_category() {
		if ( empty( $this->category ) ) {
			$categories = wp_get_object_terms( $this->get_id(), 'sunshine-product-category' );
			if ( ! empty( $categories ) ) {
				$this->category = new SPC_Product_Category( $categories[0] );
			}
		}
		return $this->category;
	}

	public function get_category_name() {
		if ( empty( $this->category ) ) {
			$this->get_category();
		}
		if ( ! empty( $this->category ) ) {
			return $this->category->get_name();
		}
		return false;
	}

	public function get_category_id() {
		if ( empty( $this->category ) ) {
			$this->get_category();
		}
		if ( ! empty( $this->category ) ) {
			return $this->category->get_id();
		}
		return false;
	}

	public function get_description() {
		if ( ! empty( $this->data->post_content ) ) {
			return apply_filters( 'the_content', $this->data->post_content );
		}
		return false;
	}

	public function get_content() {
		return $this->get_description();
	}

	public function get_excerpt() {
		return get_the_excerpt( $this->get_id() );
	}

	// category + name
	public function get_display_name() {
		$separator    = apply_filters( 'sunshine_product_name_separator', ' &mdash; ' );
		$display_name = '';
		if ( $this->get_category() ) {
			$display_name = '<span class="sunshine--product--category">' . $this->get_category_name() . '</span> <span class="sunshine--product--separator">' . $separator . '</span> ';
		}
		$display_name .= '<span class="sunshine--product--name">' . $this->get_name() . '</span>';
		return apply_filters( 'sunshine_product_display_name', $display_name, $this );
	}

	public function get_shipping() {
		if ( ! empty( $this->meta['shipping'] ) && ! empty( $this->price_level ) && array_key_exists( $this->price_level, $this->meta['shipping'] ) ) {
			return apply_filters( 'sunshine_product_shipping', $this->meta['shipping'][ $this->price_level ], $this );
		}
		return false;
	}

	public function set_shipping( $shipping ) {
		$this->shipping = floatval( $shipping );
	}

	public function needs_shipping() {
		$needs_shipping = ( $this->get_meta_value( 'disable_shipping' ) ) ? false : true;
		$needs_shipping = apply_filters( 'sunshine_product_' . $this->product_type . '_needs_shipping', $needs_shipping, $this );
		return $needs_shipping;
	}

	public function needs_delivery() {
		$needs_delivery = apply_filters( 'sunshine_product_' . $this->product_type . '_needs_delivery', true, $this );
		return $needs_delivery;
	}

	public function needs_account() {
		return false;
	}

	public function can_purchase() {
		$can_purchase = true;
		/*
		if ( ! SPC()->cart->is_empty() ) {
			$max_qty = $this->get_max_qty();
			if ( $max_qty ) {
				$product_qty = SPC()->cart->get_product_count( $this->get_id() );
				if ( $product_qty >= $max_qty ) {
					return false;
				}
			}
		}
		*/
		$can_purchase = apply_filters( 'sunshine_product_can_purchase', $can_purchase, $this );
		return $can_purchase;
	}

	public function is_taxable() {
		return ( ! empty( $this->meta['taxable'] ) ? true : false );
	}

	public function set_taxable( $taxable ) {
		$this->taxable = ( $taxable ) ? true : false;
	}

	public function classes() {
		$classes   = array();
		$classes[] = 'sunshine--product';
		$classes[] = 'sunshine--product-' . $this->get_id();
		$classes[] = 'sunshine--product-' . $this->get_type();
		$classes   = apply_filters( 'sunshine_product_class', $classes, $this );
		echo esc_attr( join( ' ', $classes ) );
	}

	public function get_options( $price_level_id = '' ) {

		if ( ! empty( $this->options ) ) { // If we have got this already, don't redo it all again
			return $this->options;
		}

		if ( empty( $price_level_id ) ) {
			$price_level_id = $this->price_level;
		}

		$options = $this->get_meta_value( 'options' );
		if ( empty( $options ) ) {
			return false;
		}

		$available_options = array();

		// Loop through each option, check if available for this price level, add
		foreach ( $options as $option_id => $option_data ) {
			if ( ! empty( $option_data['items'] ) ) {
				foreach ( $option_data['items'] as $option_item_id => $option_items ) {
					if ( array_key_exists( $option_id, $available_options ) ) {
						continue;
					}
					if ( isset( $option_items[ $price_level_id ] ) && $option_items[ $price_level_id ] !== '' ) {
						$available_options[ $option_id ] = new SPC_Product_Option( $option_id, $this->get_id(), $price_level_id );
					}
				}
			} else { // Single checkbox or text field
				if ( isset( $option_data[ $price_level_id ] ) && $option_data[ $price_level_id ] !== '' ) {
					$available_options[ $option_id ] = new SPC_Product_Option( $option_id, $this->get_id(), $price_level_id );
				}
			}
		}

		$this->options = $available_options;

		return $available_options;

	}

	public function option_is_required( $option_id ) {

		$options = $this->get_meta_value( 'options' );
		if ( empty( $options ) ) {
			return false;
		}

		foreach ( $options as $id => $option ) {
			if ( $option_id == $id && array_key_exists( 'required', $option ) ) {
				return true;
			}
		}
		return false;

	}

	/*
	public function get_option_prices( $price_level_id ) {

		if ( !empty( $this->options ) ) { // If we have got this already, don't redo it all again
			return $this->options;
		}

		if ( empty( $price_level_id ) ) {
			$price_level_id = $this->price_level;
		}

		$options = $this->get_meta_value( 'options' );
		if ( empty( $options ) ) {
			return false;
		}

		sunshine_log( $options );

		$option_prices = array();
		foreach ( $options as $option ) {

		}

	}
	*/

	public function get_option_item_price( $this_option_id, $this_option_item_id, $this_price_level_id = '' ) {

		if ( empty( $price_level_id ) ) {
			$price_level_id = $this->price_level;
		}

		$options = $this->get_meta_value( 'options' );
		if ( ! empty( $options ) && array_key_exists( $this_option_id, $options ) ) {
			if ( array_key_exists( 'items', $options[ $this_option_id ] ) && array_key_exists( $this_option_item_id, $options[ $this_option_id ]['items'] ) && array_key_exists( $this_price_level_id, $options[ $this_option_id ]['items'][ $this_option_item_id ] ) ) { // Multi
				return floatval( $options[ $this_option_id ]['items'][ $this_option_item_id ][ $this_price_level_id ] );
			} elseif ( array_key_exists( $price_level_id, $options[ $this_option_id ] ) ) {
				return floatval( $options[ $this_option_id ][ $this_price_level_id ] );
			}
		}
		return 0;

	}

	public function get_qty_discounts() {
		$qty_discounts = $this->get_meta_value( 'qty_discount' );
		if ( ! empty( $qty_discounts['items'] ) ) {
			return $qty_discounts;
		}
		return false;
	}

	public function allow_store_image_select() {
		return apply_filters( 'sunshine_product_allow_store_image_select', true, $this );
	}

	public function get_max_qty() {
		return apply_filters( 'sunshine_product_max_qty', $this->get_meta_value( 'max_qty' ), $this );
	}

	public function get_min_qty() {
		return apply_filters( 'sunshine_product_min_qty', $this->get_meta_value( 'min_qty' ), $this );
	}

	public function create() {

		if ( empty( $this->get_name() ) ) {
			// At least need a name
			return false;
		}

		$product_id = wp_insert_post(
			array(
				'post_title'     => $this->name,
				'post_status'    => 'publish',
				'post_type'      => $this->post_type,
				'comment_status' => 'closed',
				'meta_input'     => $this->meta,
			)
		);

		// Set price
		if ( ! empty( $this->price ) ) {
			if ( empty( $this->price_level ) ) {
				$this->price_level = sunshine_get_default_price_level();
			}
			update_post_meta( $product_id, 'price_' . $this->price_level->get_id(), $this->price );
		}

		// Set category
		if ( ! empty( $this->category ) ) {
			wp_set_object_terms( $product_id, $this->category->get_id(), 'sunshine-product-category' );
		}

		SPC()->log( 'Product created: ' . $this->name );

		return $product_id;

	}

}
