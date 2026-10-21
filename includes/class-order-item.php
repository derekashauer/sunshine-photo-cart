<?php
class SPC_Order_Item extends SPC_Cart_Item {

	protected $id;
	protected $order_id;
	protected $order;
	protected $order_display_price;

	function __construct( $item, $preloaded_meta = null ) {
		global $wpdb;

		parent::__construct( $item );

		if ( is_array( $preloaded_meta ) ) {
			// Meta was bulk-loaded by the caller (see SPC_Order::get_items()) to avoid
			// one query per item. Values are already unserialized.
			foreach ( $preloaded_meta as $meta_key => $meta_value ) {
				$this->meta[ $meta_key ] = $meta_value;
			}
		} else {
			$meta = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}sunshine_order_itemmeta WHERE order_item_id=%d",
					$this->get_id()
				)
			);
			if ( $meta ) {
				foreach ( $meta as $meta_item ) {
					$this->meta[ $meta_item->meta_key ] = maybe_unserialize( $meta_item->meta_value );
				}
			}
		}

		$this->set_saved_line( $item );

	}

	/**
	 * Saved order lines keep what was charged, so nothing is worked out again from today's
	 * prices, tax or discount rules. See set_saved_line().
	 *
	 * @param array $item The saved line.
	 */
	protected function calculate_line( $item ) {
	}

	/**
	 * Use the line as it was saved. The order lines table keeps one item's price and tax and the
	 * line's discount, and a saved 0 is a real 0. The line's own totals are kept in line_totals;
	 * only orders saved before that existed work them out from the rest.
	 *
	 * @param array $item The saved line.
	 */
	protected function set_saved_line( $item ) {

		if ( isset( $item['price'] ) ) {
			$this->price = sunshine_round_amount( $item['price'] );
		}
		$this->tax      = isset( $item['tax'] ) ? sunshine_round_amount( $item['tax'] ) : 0;
		$this->discount = isset( $item['discount'] ) ? sunshine_round_amount( $item['discount'] ) : 0;

		// Calculate discount per item for consistent display.
		$this->discount_per_item = ( $this->discount > 0 && $this->qty > 0 ) ? $this->discount / $this->qty : 0;

		// Set options total from saved meta data.
		if ( ! empty( $this->meta['options'] ) ) {
			$this->options = $this->meta['options'];
			// $this->set_options_total(); // Price is already set in the cart item.
		}

		// Ensure options_total is set and is a float
		if ( ! isset( $this->options_total ) ) {
			$this->options_total = 0;
		}
		$this->options_total = (float) $this->options_total;

		$qty = (float) $this->qty;

		// Calculate subtotal before discount.
		$this->subtotal = max( 0, sunshine_round_amount( ( (float) $this->price + (float) $this->options_total ) * $qty ) );

		// Store original values before discount for display.
		$this->original_subtotal  = $this->subtotal;
		$this->original_tax_total = sunshine_round_amount( $this->tax * $qty );

		$saved = isset( $this->meta['line_totals'] ) ? $this->meta['line_totals'] : null;
		if ( is_array( $saved ) && isset( $saved['total'], $saved['tax_total'] ) ) {
			if ( isset( $saved['subtotal'] ) ) {
				$this->subtotal = (float) $saved['subtotal'];
			}
			$this->tax_total = (float) $saved['tax_total'];
			$this->total     = (float) $saved['total'];
		} else {
			// Saved before line totals were kept: the line's tax is one item's tax times the quantity.
			$this->tax_total = $this->original_tax_total;
			$this->total     = max( 0, sunshine_round_amount( $this->subtotal - $this->discount ) );

			// With prices that include tax, a line discount includes tax too, so take the tax out
			// of what was paid instead of taking the whole discount off the price before tax.
			if ( $this->discount > 0 && $this->price > 0 && $this->tax > 0 && $this->order_prices_include_tax() ) {
				$rate            = $this->tax / $this->price;
				$paid            = max( 0, sunshine_round_amount( $this->subtotal + $this->tax_total - $this->discount ) );
				$this->total     = sunshine_round_amount( $paid / ( 1 + $rate ) );
				$this->tax_total = sunshine_round_amount( $paid - $this->total );
			}
		}

		$this->taxable_total = $this->total;

	}

	/**
	 * Whether the order this line is on was placed with prices that include tax.
	 *
	 * @return bool
	 */
	protected function order_prices_include_tax() {
		$order_id = ! empty( $this->item['order_id'] ) ? intval( $this->item['order_id'] ) : 0;
		return $order_id && 'yes' === get_post_meta( $order_id, 'price_has_tax', true );
	}

	public function get_id() {
		return $this->item['order_item_id'];
	}

	public function get_order() {
		if ( ! empty( $this->item['order_id'] ) ) {
			return new SPC_Order( $this->item['order_id'] );
		}
		return false;
	}

	public function get_meta_value( $key ) {
		if ( isset( $this->meta[ $key ] ) ) {
			return $this->meta[ $key ];
		}
		return false;
	}

	public function update_meta_value( $key, $value ) {
		global $wpdb;
		$existing_value = $this->get_meta_value( $key );
		if ( $existing_value ) {
			$result = $wpdb->update(
				$wpdb->prefix . 'sunshine_order_itemmeta',
				array( 'meta_value' => $value ),
				array(
					'order_item_id' => $this->get_id(),
					'meta_key'      => sanitize_key( $key ),
				)
			);
		} else {
			$result = $wpdb->insert(
				$wpdb->prefix . 'sunshine_order_itemmeta',
				array(
					'order_item_id' => $this->get_id(),
					'meta_key'      => $key,
					'meta_value'    => $value,
				),
			);
		}
		$this->meta[ $key ] = $value;
	}

	public function get_name() {
		if ( ! empty( $this->name ) ) {
			return $this->name;
		}
		$name         = '';
		$product_name = $this->get_meta_value( 'product_name' );
		if ( $product_name ) {
			$name = $product_name;
		}
		$product_cat_name = $this->get_meta_value( 'product_cat_name' );
		if ( $product_cat_name ) {
			$separator = apply_filters( 'sunshine_product_name_separator', ' &mdash; ' );
			$name      = $product_cat_name . $separator . $name;
		}
		return $name;
	}

	public function get_image_url( $size = 'sunshine-thumbnail' ) {
		$image_url = '';
		if ( ! empty( $this->image ) ) {
			$image_url = $this->image->get_image_url( $size );
		}
		if ( empty( $image_url ) && $this->product->exists() ) {
			$image_url = $this->product->get_image_url( $size );
		}
		$image_url = apply_filters( 'sunshine_order_item_image_url', $image_url, $this );
		return $image_url;
	}

	public function get_image_name( $show = 'gallery' ) {
		$image_name = '';
		if ( ! empty( $this->meta['image_name'] ) ) {
			$image_name = $this->meta['image_name'];
			if ( $show == 'gallery' ) {
				$gallery_name = $this->get_gallery_name();
				if ( ! empty( $gallery_name ) ) {
					$image_name .= ' &mdash; ' . $gallery_name;
				}
			}
		}
		return apply_filters( 'sunshine_order_item_image_name', $image_name, $this );
	}
	/*
	public function get_image_name() {
		$image_name = '';
		if ( ! empty( $this->image ) && ! empty( $this->image->gallery ) ) {
			$image_name = $this->image->get_name( 'filename' ) . ' &mdash; <a href="' . $this->image->gallery->get_permalink() . '">' . $this->image->gallery->get_name() . '</a>';
		} elseif ( ! empty( $this->item['image_name'] ) ) {
			$image_name = $this->item['image_name'];
		}
		return $image_name;
	}
	*/

	public function get_price_level() {
		return $this->item['price_level'];
	}

	public function get_regular_price() {
		return $this->price;
	}

	public function get_price() {
		$price = (float) $this->price + (float) ( $this->options_total ?? 0 );
		return $price;
	}

	public function get_price_formatted() {
		$price = $this->get_price();
		if ( $this->discount_per_item > 0 ) {
			$price_formatted = '<s>' . $this->format_price_to_display( $price, $this->tax ) . '</s> ' . $this->format_price_to_display( $price - $this->discount_per_item, $this->tax );
		} else {
			$price_formatted = $this->format_price_to_display( $price, $this->tax );
		}
		return $price_formatted;
	}

	public function get_subtotal_formatted() {
		if ( $this->discount > 0 ) {
			$price_formatted = '<s>' . $this->format_price_to_display( $this->original_subtotal, $this->original_tax_total ) . '</s> ' . $this->format_price_to_display( $this->total, $this->tax_total );
		} else {
			$subtotal        = $this->get_subtotal();
			$price_formatted = $this->format_price_to_display( $subtotal, $this->tax_total );
		}
		return $price_formatted;
	}

	protected function format_price_to_display( $price, $tax = 0 ) {
		if ( ! isset( $this->order_display_price ) ) {
			$order_id = ! empty( $this->item['order_id'] ) ? intval( $this->item['order_id'] ) : 0;
			$snapshot = $order_id ? get_post_meta( $order_id, 'display_price', true ) : '';
			$this->order_display_price = ! empty( $snapshot ) ? $snapshot : SPC()->get_option( 'display_price' );
		}
		$suffix = SPC()->get_option( 'price_suffix' );

		if ( 'with_tax' === $this->order_display_price ) {
			$price += $tax;
		}

		$price_formatted = sunshine_price( $price );

		if ( $price > 0 && $suffix && $tax ) {
			$price_formatted .= ' <small class="sunshine--price--suffix">' . $suffix . '</small>';
		}

		return $price_formatted;
	}

	public function get_type() {
		return $this->item['type'];
	}

	function get_gallery_id() {
		if ( $this->item['gallery_id'] ) {
			return $this->item['gallery_id'];
		}
		return false;
	}

	function get_gallery_name() {
		if ( ! empty( $this->meta['gallery_name'] ) ) {
			return $this->meta['gallery_name'];
		} elseif ( ! empty( $this->gallery ) ) {
			return $this->gallery->get_name();
		}
		return false;
	}

	function get_filename() {
		if ( ! empty( $this->meta['filename'] ) ) {
			return $this->meta['filename'];
		}
		return false;
	}

	function get_file_name() {
		return $this->get_filename();
	}

	public function get_file_names() {
		$file_names = array();
		if ( ! empty( $this->meta['filename'] ) ) {
			$file_names[] = $this->meta['filename'];
		}
		if ( ! empty( $this->options['images'] ) ) {
			foreach ( $this->options['images'] as $image ) {
				$file_names[] = $image['filename'];
			}
		}
		/*
		$final_file_names = array();
		if ( ! empty( $file_names ) ) {
			foreach ( $file_names as $file_name ) {
				$info = pathinfo( $file_name );
				$final_file_names[] = $info['filename'];
			}
		}
		*/
		$file_names = apply_filters( 'sunshine_order_item_file_names', $file_names, $this );
		return array_unique( $file_names );
	}

	public function set_options_total() {
		if ( ! empty( $this->meta['options'] ) ) {
			sunshine_log( $this->meta['options'], 'options' );
			foreach ( $this->meta['options'] as $option_item ) {
				if ( ! empty( $option_item['price'] ) ) {
					sunshine_log( '$ option_item[price]: ' . $option_item['price'] );
					$this->options_total += $option_item['price'];
				}
			}
			sunshine_log( '$$$ Options total: ' . $this->options_total );
		}
	}

	public function get_options_formatted() {
		$options = $this->get_options();
		if ( ! empty( $options ) ) {
			$html = '';
			foreach ( $options as $key => $option ) {
				if ( $key == 'images' ) {
					if ( is_admin() ) {
						continue; // Don't do this in the admin, not ideal way but it works.
					}
					$html .= sunshine_get_template_html(
						'order/product-option-images',
						array(
							'images' => $option,
						)
					);
					continue;
				}
				$html .= sunshine_get_template_html(
					'order/product-option-item',
					array(
						'option' => $option,
					)
				);
			}
			return $html;
		}
		return false;
	}

	public function get_extra() {
		do_action( 'sunshine_order_item_extra', $this );
		return ''; // Return empty string instead of null
	}

	public function get_comments() {
		if ( ! empty( $this->meta['comments'] ) ) {
			return $this->meta['comments'];
		}
		return ''; // Return empty string instead of false
	}

}
