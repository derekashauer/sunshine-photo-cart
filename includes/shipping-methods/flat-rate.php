<?php

class SPC_Shipping_Method_Flat_Rate extends SPC_Shipping_Method {

	public function init() {
		$this->id            = 'flat_rate';
		$this->name          = __( 'Flat Rate', 'sunshine-photo-cart' );
		$this->class         = 'SPC_Shipping_Method_Flat_Rate';
		$this->description   = '';
		$this->can_be_cloned = true;
	}

	public function set_price() {

		if ( empty( $this->instance_id ) ) {
			return;
		}

		$price_type = SPC()->get_option( $this->id . '_price_type_' . $this->instance_id );
		if ( empty( $price_type ) ) {
			parent::set_price();
			return;
		}

		$price_has_tax = SPC()->get_option( 'price_has_tax' );
		$tax_rate      = SPC()->cart->get_tax_rate();

		// Get base shipping price and extract tax if needed.
		$price = floatval( SPC()->get_option( $this->id . '_price_' . $this->instance_id ) );
		if ( 'yes' === $price_has_tax && $this->is_taxable() && $tax_rate ) {
			// Extract tax from configured price.
			$price = round( $price / ( $tax_rate['rate'] + 1 ), 2 );
		}

		// Calculate product shipping costs and extract tax if needed.
		$product_shipping = 0;
		$cart_items       = SPC()->cart->get_cart_items();
		if ( ! empty( $cart_items ) ) {
			foreach ( $cart_items as $item ) {
				$item_shipping = floatval( $item->product->get_shipping() );
				if ( $item_shipping ) {
					// Extract tax from product shipping if needed.
					if ( 'yes' === $price_has_tax && $this->is_taxable() && $tax_rate ) {
						$item_shipping = round( $item_shipping / ( $tax_rate['rate'] + 1 ), 2 );
					}
					$product_shipping += $item_shipping * $item->get_qty();
				}
			}
		}

		// Build total price based on price_type.
		if ( $price_type == 'cart' ) {
			$this->price = $price + $product_shipping;
		} elseif ( ! empty( $cart_items ) ) {
			foreach ( $cart_items as $item ) {
				if ( $price_type == 'line' ) {
					$this->price += $price;
				} elseif ( $price_type == 'qty' ) {
					$this->price += $price * $item->get_qty();
				}
			}
			$this->price += $product_shipping;
		}

		// Calculate tax on the total base price.
		if ( $this->price && $this->is_taxable() && $tax_rate ) {
			$this->tax = round( $this->price * $tax_rate['rate'], 2 );
		}

	}

	public function is_allowed() {

		if ( empty( $this->instance_id ) ) {
			return false;
		}

		$allowed = apply_filters( 'sunshine_shipping_flat_rate_allowed', true, $this );

		return $allowed;

	}


}

$sunshine_shipping_flat_rate = new SPC_Shipping_Method_Flat_Rate();
