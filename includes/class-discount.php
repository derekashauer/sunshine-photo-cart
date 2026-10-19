<?php
class SPC_Discount extends Sunshine_Data {

	protected $post_type = 'sunshine-discount';

	// The code's product, category and gallery rules, read once.
	protected $rules;
	protected $meta      = array(
		'code'                  => '',
		'auto'                  => '',
		'discount_type'         => '',
		'discount_amount'       => '',
		'apply_to_shipping'     => '',
		'free_shipping'         => '',
		'start_date'            => '',
		'end_date'              => '',
		'max_product_quantity'  => '',
		'solo'                  => '',
		'allowed_products'      => '',
		'disallowed_products'   => '',
		'allowed_categories'    => '',
		'disallowed_categories' => '',
		'allowed_galleries'     => '',
		'disallowed_galleries'  => '',
		'min_amount'            => '',
		'min_quantity'          => '',
		'max_uses'              => '',
		'max_uses_per_person'   => '',
		'use_count'             => '',
		'used_by'               => '',
		'required_products'     => '',
	);

	public function __construct( $object = '', $price_level = '' ) {

		if ( is_numeric( $object ) && $object > 0 ) {
			$object = get_post( $object );
			if ( empty( $object ) || $object->post_type != $this->post_type || $object->post_status != 'publish' ) {
				return false; }
		} elseif ( is_a( $object, 'WP_Post' ) ) {
			if ( $object->post_type != $this->post_type || $object->post_status != 'publish' ) {
				return false; }
		} elseif ( ! empty( $object ) ) {
			$args      = array(
				'post_type'  => $this->post_type,
				'meta_key'   => 'code',
				'meta_value' => $object,
			);
			$discounts = get_posts( $args );
			if ( ! empty( $discounts ) ) {
				$object = $discounts[0];
			}
		}

		if ( ! empty( $object->ID ) ) {

			$this->id   = $object->ID;
			$this->data = $object;

			if ( $object->post_title ) {
				$this->name = $object->post_title;
			}

			$this->set_meta_data();

		}

	}

	public function is_auto() {
		return apply_filters( 'sunshine_discount_auto', $this->meta['auto'], $this );
	}

	public function get_code() {
		return $this->meta['code'];
	}

	public function get_type() {
		return apply_filters( 'sunshine_discount_type', $this->meta['discount_type'], $this );
	}

	public function get_amount() {
		return apply_filters( 'sunshine_discount_amount', floatval( $this->meta['discount_amount'] ), $this );
	}

	public function get_min_amount() {
		return apply_filters( 'sunshine_discount_min_amount', floatval( $this->meta['min_amount'] ), $this );
	}

	public function get_min_quantity() {
		return apply_filters( 'sunshine_discount_min_quantity', intval( $this->meta['min_quantity'] ), $this );
	}

	public function is_solo() {
		return apply_filters( 'sunshine_discount_solo', ( $this->meta['solo'] == 1 ) ? true : false, $this );
	}

	public function apply_to_shipping() {
		return apply_filters( 'sunshine_discount_apply_to_shipping', ( $this->meta['apply_to_shipping'] == 1 ) ? true : false, $this );
	}

	public function enables_free_shipping() {
		return apply_filters( 'sunshine_discount_free_shipping', $this->meta['free_shipping'], $this );
	}

	public function get_start_date() {
		return apply_filters( 'sunshine_discount_start_date', $this->meta['start_date'], $this );
	}

	public function get_end_date() {
		return apply_filters( 'sunshine_discount_end_date', $this->meta['end_date'], $this );
	}

	public function get_required_products() {
		return apply_filters( 'sunshine_discount_required_products', $this->meta['required_products'], $this );
	}

	public function get_allowed_products() {
		return apply_filters( 'sunshine_discount_allowed_products', $this->meta['allowed_products'], $this );
	}

	public function get_disallowed_products() {
		return apply_filters( 'sunshine_discount_disallowed_products', $this->meta['disallowed_products'], $this );
	}

	public function get_allowed_categories() {
		return apply_filters( 'sunshine_discount_allowed_categories', $this->meta['allowed_categories'], $this );
	}

	public function get_disallowed_categories() {
		return apply_filters( 'sunshine_discount_disallowed_categories', $this->meta['disallowed_categories'], $this );
	}

	public function get_allowed_galleries() {

		$galleries = $this->meta['allowed_galleries'];
		if ( ! empty( $galleries ) && is_array( $galleries ) ) {
			$descendants = array();
			foreach ( $galleries as $gallery_id ) {
				$descendants = array_merge( sunshine_get_gallery_descendant_ids( $gallery_id ), $descendants );
			}
			$galleries = array_merge( $galleries, $descendants );
			$galleries = array_unique( $galleries );
			return apply_filters( 'sunshine_discount_allowed_galleries', $galleries, $this );
		}
		return false;
	}

	public function get_disallowed_galleries() {

		$galleries = $this->meta['disallowed_galleries'];
		if ( ! empty( $galleries ) && is_array( $galleries ) ) {
			$descendants = array();
			foreach ( $galleries as $gallery_id ) {
				$descendants = array_merge( sunshine_get_gallery_descendant_ids( $gallery_id ), $descendants );
			}
			$galleries = array_merge( $galleries, $descendants );
			$galleries = array_unique( $galleries );
			return apply_filters( 'sunshine_discount_disallowed_galleries', $galleries, $this );
		}
		return false;
	}

	public function max_product_quantity() {
		return apply_filters( 'sunshine_discount_max_product_quantity', $this->meta['max_product_quantity'], $this );
	}

	public function get_use_count() {
		return apply_filters( 'sunshine_discount_use_count', intval( $this->meta['use_count'] ), $this );
	}

	public function get_use_count_by( $value ) {
		// Each use is stored as its own row, so read them all. Loaded meta only keeps the last one.
		$count = 0;
		foreach ( (array) get_post_meta( $this->get_id(), 'used_by', false ) as $used_by ) {
			if ( is_scalar( $used_by ) && strtolower( (string) $used_by ) === strtolower( (string) $value ) ) {
				$count++;
			}
		}
		return $count;
	}

	public function get_max_uses() {
		return apply_filters( 'sunshine_discount_max_uses', intval( $this->meta['max_uses'] ), $this );
	}

	public function get_max_uses_per_person() {
		return apply_filters( 'sunshine_discount_max_uses_per_person', intval( $this->meta['max_uses_per_person'] ), $this );
	}

	/**
	 * Whether a discount code may take money off this cart line at all, before the code's own
	 * product, category and gallery rules. Gift card purchases never are: a card bought at a
	 * discount can still be spent at its full value.
	 *
	 * @param SPC_Cart_Item $item The cart line.
	 * @return bool
	 */
	public function can_discount_item( $item ) {
		$product      = $item->get_product();
		$discountable = ! ( $product && 'gift-card' === $product->get_type() );
		return (bool) apply_filters( 'sunshine_discount_can_discount_item', $discountable, $item, $this );
	}

	// Determines if this coupon is valid with the current cart vs discount settings
	public function is_valid() {

		// Check start/end date
		$start_date = $this->get_start_date();
		if ( $start_date && current_time( 'Y-m-d' ) < $start_date ) {
			return false;
		}

		$end_date = $this->get_end_date();
		if ( $end_date && current_time( 'Y-m-d' ) > $end_date ) {
			return false;
		}

		$max_uses = $this->get_max_uses();
		if ( $max_uses && $this->get_use_count() >= $max_uses ) {
			return false;
		}

		// Counted by account and by the email used at checkout, so guests are limited too.
		$max_uses_per_person = $this->get_max_uses_per_person();
		if ( $max_uses_per_person ) {
			if ( is_user_logged_in() && $this->get_use_count_by( get_current_user_id() ) >= $max_uses_per_person ) {
				return false;
			}
			$email = ! empty( SPC()->cart ) ? SPC()->cart->get_checkout_data_item( 'email' ) : '';
			if ( empty( $email ) && is_user_logged_in() ) {
				$email = wp_get_current_user()->user_email;
			}
			if ( $email && $this->get_use_count_by( $email ) >= $max_uses_per_person ) {
				return false;
			}
		}

		$min_amount = $this->get_min_amount();
		if ( $min_amount && SPC()->cart->get_subtotal() < $min_amount ) {
			return false;
		}

		$min_quantity = $this->get_min_quantity();
		if ( $min_quantity && SPC()->cart->get_item_count() < $min_quantity ) {
			return false;
		}

		$cart = SPC()->cart->get_cart_items();
		if ( ! empty( $cart ) ) {

			$required_products = $this->get_required_products();

			$discount_allowed = array();

			if ( ! empty( $required_products ) && is_array( $required_products ) ) {
				$allowed = 0;
				foreach ( $cart as $key => $item ) {
					if ( in_array( $item->get_product_id(), $required_products ) ) {
						$allowed = 1;
					}
				}
				if ( ! $allowed ) {
					return false;
				}
			}

			foreach ( $cart as $key => $item ) {
				$discount_allowed[ $key ] = $this->applies_to_item( $item ) ? 1 : 0;
			}

			// If any item in cart allows the discount, then it is valid.
			// Doesn't mean it will get applied to every coupon, that is done below in get_total.
			if ( in_array( 1, $discount_allowed ) ) {
				return true;
			}
		}

		return false;
	}

	// Deprecated, only using is_valid().
	public function is_allowed() {
		return $this->is_valid();
		/*
		if ( ! $this->is_valid() ) {
			return false;
		}
		*/

		return true;

	}

	/**
	 * Whether this code may take money off a cart line: the line can be discounted at all, and it
	 * passes the code's product, category and gallery rules. A product in several categories
	 * counts for any of them.
	 *
	 * @param SPC_Cart_Item $item The cart line.
	 * @return bool
	 */
	public function applies_to_item( $item ) {

		if ( ! $this->can_discount_item( $item ) ) {
			return false;
		}

		if ( ! isset( $this->rules ) ) {
			$this->rules = array(
				'allowed_products'      => $this->get_allowed_products(),
				'disallowed_products'   => $this->get_disallowed_products(),
				'allowed_categories'    => $this->get_allowed_categories(),
				'disallowed_categories' => $this->get_disallowed_categories(),
				'allowed_galleries'     => $this->get_allowed_galleries(),
				'disallowed_galleries'  => $this->get_disallowed_galleries(),
			);
		}
		$rules = $this->rules;

		if ( ! empty( $rules['allowed_products'] ) && ! in_array( $item->get_product_id(), (array) $rules['allowed_products'] ) ) {
			return false;
		}

		if ( ! empty( $rules['disallowed_products'] ) && $item->get_product_id() && in_array( $item->get_product_id(), (array) $rules['disallowed_products'] ) ) {
			return false;
		}

		if ( ! empty( $rules['allowed_categories'] ) || ! empty( $rules['disallowed_categories'] ) ) {
			$product      = $item->get_product();
			$category_ids = ( $product && method_exists( $product, 'get_category_ids' ) ) ? $product->get_category_ids() : array();
			if ( ! empty( $rules['allowed_categories'] ) && ! array_intersect( $category_ids, (array) $rules['allowed_categories'] ) ) {
				return false;
			}
			if ( ! empty( $rules['disallowed_categories'] ) && array_intersect( $category_ids, (array) $rules['disallowed_categories'] ) ) {
				return false;
			}
		}

		if ( ! empty( $rules['allowed_galleries'] ) && ( empty( $item->get_gallery_id() ) || ! in_array( $item->get_gallery_id(), $rules['allowed_galleries'] ) ) ) {
			return false;
		}

		if ( ! empty( $rules['disallowed_galleries'] ) && in_array( $item->get_gallery_id(), $rules['disallowed_galleries'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * What this code takes off the cart. The cart's SPC_Totals_Calculator works this out with the
	 * other codes, so codes together never take more than they apply to.
	 *
	 * @param bool $tax Only the part that comes off taxed items (and taxed shipping).
	 * @return float|false
	 */
	public function get_total( $tax = false ) {

		if ( empty( SPC()->cart ) || SPC()->cart->is_empty() || empty( $this->get_amount() ) ) {
			return false;
		}

		$code = SPC()->cart->get_discount_breakdown( $this->get_id() );
		if ( null === $code ) {
			// Not one of the cart's codes: what it would take off on its own.
			$calculator = new SPC_Totals_Calculator( SPC()->cart->get_tax_rate() );
			$calculator->set_lines( SPC()->cart->get_cart_items(), SPC()->cart->get_shipping_for_totals() );
			$code = $calculator->code_parts( $this );
		}

		$amount = $tax ? $code['taxed'] : $code['amount'];
		return ( $amount > 0 ) ? (float) $amount : false;

	}

	public function increment_use_count() {
		$use_count = $this->get_use_count();
		$use_count++;
		$this->update_meta_value( 'use_count', $use_count );
	}

	public function increment_use_count_by( $value ) {
		$this->add_meta_value( 'used_by', $value );
	}


}
