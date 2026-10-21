<?php

class SPC_Cart_Item {

	protected $item = array();
	protected $object_id;
	protected $image_id;
	protected $image;
	protected $image_url;
	protected $type;
	protected $name = '';
	protected $product_id;
	public $product;
	protected $category_id;
	public $category;
	protected $gallery_id;
	public $gallery;
	protected $qty = 0;
	protected $price_level;
	protected $price         = 0.00;
	protected $options       = array();
	protected $options_total = 0.00;
	protected $discount      = 0.00;
	protected $taxable       = false;
	protected $tax           = 0.00;
	protected $taxable_total = 0.00;
	protected $tax_total     = 0.00;
	protected $tax_rate      = false;
	protected $subtotal      = 0.00;
	protected $total         = 0.00;
	protected $comments      = '';
	protected $meta          = array();
	protected $hash;

	// Store original values before discount for display.
	protected $original_subtotal  = 0.00;
	protected $original_tax_total = 0.00;
	protected $discount_per_item  = 0.00;

	function __construct( $item = array() ) {

		if ( ! empty( $item ) && ! is_array( $item ) ) {
			return;
		}

		$this->item = $item;

		if ( ! empty( $item['price_level'] ) ) {
			$this->price_level = $item['price_level'];
		} else {
			$this->price_level = sunshine_get_default_price_level_id();
		}

		if ( ! empty( $item['object_id'] ) ) {
			$this->object_id = $item['object_id'];
		}

		if ( ! empty( $item['image_id'] ) ) {
			$this->image_id = $item['image_id'];
			$image          = sunshine_get_image( $item['image_id'] );
			if ( $image->exists() ) {
				$this->image   = $image;
				$this->gallery = $this->image->gallery;
				if ( $this->gallery ) {
					$this->gallery_id = $this->image->gallery->get_id();
				}
			}
		}

		if ( ! empty( $item['image_url'] ) ) {
			$this->image_url = $item['image_url'];
		}

		if ( ! empty( $item['product_id'] ) && ! empty( $item['price_level'] ) ) {
			$this->product_id = $item['product_id'];
			$product          = sunshine_get_product( $item['product_id'], $item['price_level'] );
			if ( $product->exists() ) {
				$this->product = $product;
				$this->name    = $product->get_display_name();
				$this->type    = $product->get_type();
				// For gift certificates, use values from the item array (set by filter) instead of product values
				if ( $this->type === 'gift-certificate' ) {
					$this->taxable = ( ! empty( $item['taxable'] ) ) ? (bool) $item['taxable'] : false;
					if ( ! empty( $item['price'] ) ) {
						$this->price = floatval( $item['price'] );
					} else {
						$this->price = 0;
					}
				} else {
					$this->taxable = $product->is_taxable();
					$this->price   = $product->get_regular_price();
				}
				$this->set_options_total();
			}
		}

		// A photo always belongs to its own gallery. The gallery saved with the line comes from the
		// add to cart request, so it only counts for lines without a photo.
		if ( empty( $this->image ) && ! empty( $item['gallery_id'] ) ) {
			$this->gallery_id = $item['gallery_id'];
			$this->gallery    = sunshine_get_gallery( $item['gallery_id'] );
		}

		if ( ! empty( $item['qty'] ) ) {
			$this->qty = intval( $item['qty'] );
		}

		if ( ! empty( $item['price'] ) ) {
			$this->price = floatval( $item['price'] );
		}

		if ( ! empty( $item['comments'] ) ) {
			$this->comments = $item['comments'];
		}
		$this->comments = apply_filters( 'sunshine_cart_item_comments', $this->comments, $item );

		if ( ! empty( $item['hash'] ) ) {
			$this->hash = $item['hash'];
		}

		if ( ! empty( $item['meta'] ) ) {
			$this->meta = $item['meta'];
		}

		$this->calculate_line( $item );

	}

	/**
	 * Work out the line's money: its line discount (sunshine_cart_item_discount), its tax rate
	 * (sunshine_cart_item_tax_rate), then SPC_Totals_Calculator::line() does the math.
	 *
	 * @param array $item The cart line as saved in the cart.
	 */
	protected function calculate_line( $item ) {

		if ( ! empty( $item['discount'] ) ) {
			$this->discount = floatval( $item['discount'] );
		} else {
			$discount = apply_filters( 'sunshine_cart_item_discount', $this->discount, $this );
			if ( $discount ) {
				$this->discount = floatval( $discount );
			}
		}

		// A line saved with its tax already worked out keeps it.
		if ( ! empty( $item['tax'] ) ) {
			$this->set_total();
			if ( ! empty( $item['subtotal'] ) ) {
				$this->subtotal = $item['subtotal'];
			}
			$this->tax                = $item['tax'];
			$this->original_subtotal  = $this->subtotal;
			$this->original_tax_total = 0;
			if ( $this->discount > 0 && $this->qty > 0 ) {
				$this->discount_per_item = $this->discount / $this->qty;
			}
		} else {
			// Lets custom code charge a different rate on some items, e.g. a reduced VAT rate on
			// prints. With no cart-wide rate the cart charges no tax.
			$this->tax_rate = apply_filters( 'sunshine_cart_item_tax_rate', SPC()->cart->get_tax_rate(), $this );

			$line = SPC_Totals_Calculator::line(
				array(
					'price'         => $this->price,
					'options_total' => $this->options_total,
					'qty'           => $this->qty,
					'discount'      => $this->discount,
					'taxable'       => $this->taxable,
					'tax_rate'      => $this->tax_rate,
				)
			);
			$this->set_line( $line );

			if ( ! $line['rate'] && ! empty( $item['subtotal'] ) ) {
				$this->subtotal = $item['subtotal'];
			}
		}

		// Some add-ons set the line's total themselves, like a gift card bought for an amount.
		if ( ! empty( $item['total'] ) ) {
			$this->total = $item['total'];
		}

	}

	/**
	 * Take the numbers SPC_Totals_Calculator::line() worked out for this line.
	 *
	 * @param array $line The line's numbers.
	 */
	protected function set_line( $line ) {
		$this->price              = $line['price'];
		$this->options_total      = $line['options_total'];
		$this->tax                = $line['tax'];
		$this->subtotal           = $line['subtotal'];
		$this->discount           = $line['discount'];
		$this->discount_per_item  = $line['discount_per_item'];
		$this->taxable_total      = $line['taxable_total'];
		$this->tax_total          = $line['tax_total'];
		$this->total              = $line['total'];
		$this->original_subtotal  = $line['original_subtotal'];
		$this->original_tax_total = $line['original_tax_total'];
	}

	/**
	 * The tax rate this line was taxed at (after sunshine_cart_item_tax_rate), or false.
	 *
	 * @return array|false
	 */
	public function get_tax_rate() {
		return ( ! empty( $this->tax_rate['rate'] ) ) ? $this->tax_rate : false;
	}

	public function get_item_data() {
		return $this->item;
	}

	public function get_options_total() {
		return $this->options_total;
	}

	public function set_options_total() {

		if ( ! empty( $this->product ) && ! empty( $this->item['options'] ) ) {
			$this->options = $this->item['options'];
			foreach ( $this->options as $option_id => $id ) {
				if ( '' === $id || null === $id || array() === $id ) {
					continue; // Left empty, so not chosen: a text option is only charged when text is entered.
				}
				$this->options_total += floatval( $this->product->get_option_item_price( $option_id, $id, $this->price_level ) );
			}
		}

	}

	/**
	 * Whether every option chosen on this line is still sold at the line's price level.
	 *
	 * @return bool
	 */
	public function options_are_offered() {
		if ( empty( $this->product ) || empty( $this->item['options'] ) || ! is_array( $this->item['options'] ) ) {
			return true;
		}
		foreach ( $this->item['options'] as $option_id => $value ) {
			if ( ! $this->product->option_is_offered( $option_id, $value, $this->price_level ) ) {
				return false;
			}
		}
		return true;
	}

	public function set_total() {
		$this->subtotal = max( 0, ( floatval( $this->price ) + floatval( $this->options_total ) ) * $this->qty );
		$this->total    = max( 0, floatval( $this->subtotal - $this->discount ) );
	}

	public function get_image_id() {
		return $this->image_id;
	}

	public function get_image() {
		return $this->image;
	}

	public function get_gallery_id() {
		return $this->gallery_id;
	}

	public function get_gallery() {
		return $this->gallery;
	}

	public function get_gallery_name() {
		if ( ! empty( $this->gallery ) ) {
			return $this->gallery->get_name();
		}
		return false;
	}

	public function get_gallery_hierarchy( $separator = '>' ) {

		$result  = '';
		$gallery = $this->get_gallery();
		if ( empty( $gallery ) ) {
			return ''; // Return empty string instead of false
		}
		if ( $gallery->exists() && $gallery->get_parent_gallery_id() > 0 ) {
			$ancestors      = get_ancestors( $gallery->get_id(), 'sunshine-gallery', 'post_type' );
			$ancestor_items = array( $gallery->get_name() );
			foreach ( $ancestors as $ancestor_id ) {
				$ancestor_items[] = get_the_title( $ancestor_id );
			}
			$ancestor_items = array_reverse( $ancestor_items );
			$result         = join( ' ' . $separator . ' ', $ancestor_items );
		} else {
			$result = $gallery->get_name();
		}

		return $result;

	}

	public function get_product_id() {
		return $this->product_id;
	}

	public function get_product() {
		return $this->product;
	}

	public function get_category_id() {
		$product = $this->get_product();
		if ( $product && $product->get_category_id() ) {
			return $product->get_category_id();
		}
		return false;
	}

	public function get_type() {
		return $this->type;
	}

	public function get_image_url( $size = 'sunshine-thumbnail' ) {
		if ( ! empty( $this->image_url ) ) {
			return $this->image_url;
		}
		$image_url = '';
		if ( ! empty( $this->image ) ) {
			$image_url = $this->image->get_image_url( $size );
		}
		if ( empty( $image_url ) && $this->product->exists() ) {
			$image_url = $this->product->get_image_url( $size );
		}
		$image_url = apply_filters( 'sunshine_cart_item_image_url', $image_url, $this );
		return $image_url;
	}

	public function get_image_html( $size = 'sunshine-thumbnail', $use_placeholder = true, $args = array() ) {
		$image_url  = $this->get_image_url( $size );
		$image_name = strip_tags( $this->get_image_name() );
		$atts       = '';
		if ( ! empty( $args ) ) {
			foreach ( $args as $key => $value ) {
				$atts .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
			}
		}
		if ( $image_url ) {
			return '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $image_name ) . '" ' . $atts . ' />';
		} elseif ( $use_placeholder ) {
			return sunshine_image_placeholder_html( $args );
		}
	}

	public function get_image_name( $show = '' ) {
		$image_name = '';
		if ( ! empty( $this->item['image_name'] ) ) {
			$image_name = $this->item['image_name'];
		} elseif ( ! empty( $this->image ) && ! empty( $this->image->gallery ) ) {
			$image_name = $this->image->get_name( $show ) . ' &mdash; <a href="' . $this->image->gallery->get_permalink() . '">' . $this->image->gallery->get_name() . '</a>';
		}
		$image_name = apply_filters( 'sunshine_cart_item_image_name', $image_name, $this );
		return $image_name;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_name_raw() {
		return strip_tags( html_entity_decode( $this->get_name() ) );
	}

	public function get_qty() {
		return $this->qty;
	}

	public function can_edit_qty() {
		return apply_filters( 'sunshine_cart_item_can_edit_qty', true, $this );
	}

	public function max_qty() {
		return apply_filters( 'sunshine_cart_item_max_qty', null, $this );
	}

	public function get_max_qty() {
		$max_qty = null;
		$product = $this->get_product();
		if ( $product->exists() ) {
			$product_max_qty = $product->get_max_qty();
			if ( $product_max_qty ) {
				$max_qty = $product_max_qty;
			}
		}
		return apply_filters( 'sunshine_cart_item_max_qty', $max_qty, $this );
	}

	public function get_min_qty() {
		$min_qty = null;
		$product = $this->get_product();
		if ( $product->exists() ) {
			$product_min_qty = $product->get_min_qty();
			if ( $product_min_qty ) {
				$min_qty = $product_min_qty;
			}
		}
		return apply_filters( 'sunshine_cart_item_min_qty', $min_qty, $this );
	}

	public function get_price() {
		$price = (float) $this->price + (float) ( $this->options_total ?? 0 );
		return $price;
	}

	public function get_price_formatted() {
		$price = $this->get_price();
		if ( $this->discount_per_item > 0 ) {
			$price_formatted = '<s>' . sunshine_get_price_to_display( $price, $this->tax ) . '</s> ' . sunshine_get_price_to_display( $price - $this->discount_per_item, $this->tax );
		} else {
			$price_formatted = sunshine_get_price_to_display( $price, $this->tax );
		}
		return $price_formatted;
	}

	public function get_regular_price() {
		return $this->price;
	}

	public function get_regular_price_formatted() {
		$price           = $this->get_price();
		$price_formatted = sunshine_get_price_to_display( $price, $this->tax );
		return $price_formatted;
	}

	public function is_taxable() {
		return $this->taxable;
	}

	public function get_tax() {
		return $this->tax;
		/*
		// This gets calculated earlier on setup, don't need to do every time
		if ( ! $this->is_taxable() ) {
			return 0;
		}
		$matched_tax_rate = SPC()->cart->get_tax_rate();
		if ( ! $matched_tax_rate ) {
			return 0;
		}
		$this->tax = $this->get_subtotal() * $this->get_qty() * $matched_tax_rate['rate'];
		return $this->tax;
		*/
	}

	public function get_tax_total() {
		return $this->tax_total;
	}

	public function get_taxable_total() {
		return $this->taxable_total;
	}

	public function get_discount() {
		return $this->discount;
	}

	public function get_discount_total() {
		// return $this->discount * $this->qty;
		return $this->get_discount();
	}

	public function get_discount_per_item() {
		return $this->discount_per_item;
	}

	public function get_comments() {
		return $this->comments ? $this->comments : '';
	}

	public function get_shipping() {
		return $this->shipping;
	}

	public function get_subtotal() {
		return $this->subtotal;
	}

	public function get_subtotal_formatted() {
		if ( $this->discount > 0 ) {
			// Show original price with strikethrough, then discounted price.
			$price_formatted = '<s>' . sunshine_get_price_to_display( $this->original_subtotal, $this->original_tax_total ) . '</s> ' . sunshine_get_price_to_display( $this->total, $this->tax_total );
		} else {
			// No discount, show regular price.
			$subtotal        = $this->get_subtotal();
			$price_formatted = sunshine_get_price_to_display( $subtotal, $this->tax_total );
		}
		return $price_formatted;
	}

	public function get_regular_subtotal_formatted() {
		$subtotal           = $this->get_subtotal();
		$subtotal_formatted = sunshine_get_price_to_display( $subtotal, $this->tax_total );
		return $subtotal_formatted;
	}


	public function get_total() {
		return $this->total;
	}

	public function get_total_formatted() {
		$total_formatted = sunshine_get_price_to_display( $this->total, $this->tax );
		if ( $this->discount > 0 ) {
			$total_formatted = '<s>' . sunshine_price( $this->subtotal ) . '</s> ' . $total_formatted;
		}
		return $total_formatted;
	}

	public function get_hash() {
		return $this->hash;
	}

	public function get_file_names() {

		$file_names = array();

		if ( $this->get_type() == 'download' ) {
			return $file_names;
		}

		if ( ! empty( $this->image ) ) {
			$image_file_name = $this->image->get_file_name();
			if ( $image_file_name ) {
				$file_names[] = $image_file_name;
			}
		}
		if ( ! empty( $this->item['options']['images'] ) ) {
			foreach ( $this->item['options']['images'] as $image_id ) {
				$file_names[] = sunshine_get_image_file_name( $image_id );
			}
		}

		return $file_names;

	}

	public function get_remove_url() {
		$url = sunshine_get_page_permalink( 'cart' );
		$url = add_query_arg( 'delete_cart_item', $this->get_hash(), $url );
		$url = add_query_arg( 'nonce', wp_create_nonce( 'sunshine_delete_cart_item' ), $url );
		return $url;
	}

	public function classes() {
		if ( ! empty( $this->product ) && is_a( $this->product, 'SPC_Product' ) ) {
			return $this->product->classes();
		}
		return false;
	}

	public function get_options() {
		return $this->options;
	}

	public function get_option( $key ) {
		if ( ! empty( $this->options[ $key ] ) ) {
			return $this->options[ $key ];
		}
		return false;
	}

	public function update_option( $key, $value ) {
		$this->options[ $key ] = $value;
		SPC()->cart->update_cart();
	}

	public function get_extra() {
		do_action( 'sunshine_cart_item_extra', $this );
		return ''; // Return empty string instead of null
	}

	public function get_options_formatted() {
		$options = $this->get_options();
		if ( ! empty( $options ) ) {
			$html = '';
			foreach ( $options as $option_id => $value ) {
				$option = new SPC_Product_Option( $option_id );
				if ( ! $option->get_id() ) {
					continue;
				}
				if ( $option_id == 'images' ) {
					$html .= sunshine_get_template_html(
						'cart/product-option-images',
						array(
							'item'   => $this,
							'option' => $option,
							'images' => $value,
						)
					);
				}
				if ( ! is_numeric( $option_id ) ) {
					continue;
				}
				$html .= sunshine_get_template_html(
					'cart/product-option-item',
					array(
						'item'           => $this,
						'option'         => $option,
						'option_item_id' => $value,
					)
				);
			}
			return $html;
		}
		return false;
	}

	public function get_meta_value( $key ) {
		if ( ! empty( $this->meta[ $key ] ) ) {
			return $this->meta[ $key ];
		}
		return false;
	}

	public function update_meta_value( $key, $value ) {
		$this->meta[ $key ] = $value;
		$cart               = SPC()->cart->get_cart();
		foreach ( $cart as $item ) {
			if ( $item['hash'] == $this->get_hash() ) {
				SPC()->cart->update_meta_value_by_hash( $item['hash'], $key, $value );
				break;
			}
		}
	}

}
