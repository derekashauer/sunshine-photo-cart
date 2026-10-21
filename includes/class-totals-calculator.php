<?php
/**
 * The one place a cart's money is worked out.
 *
 * Steps always run in this order, and each one is where the add-on hooks for it run:
 *
 * 1. Lines: price x quantity, options and line discounts (the sunshine_cart_item_discount and
 *    sunshine_cart_item_tax_rate filters run while each SPC_Cart_Item is built, then line()).
 * 2. Order discounts: discount codes (sunshine_set_discounts, then sunshine_cart_discount).
 * 3. Shipping and tax (sunshine_cart_taxable_total, sunshine_cart_tax).
 * 4. After-tax payments: gift cards (sunshine_cart_after_discounts_and_tax).
 * 5. Payment method fee.
 * 6. Store credit and the total (sunshine_get_cart_total).
 *
 * SPC_Cart runs the steps and keeps one of these; its getters read the numbers from here.
 * Every amount is rounded to the currency's smallest unit as soon as it is worked out, and
 * totals are sums of rounded parts, so what the customer sees always adds up.
 */
class SPC_Totals_Calculator {

	protected $price_has_tax      = false;
	protected $discount_after_tax = false;

	// The cart-wide tax rate as SPC_Cart::get_tax_rate() returns it ('rate' is a fraction), or false.
	protected $tax_rate = false;

	// Each cart line: the item and the numbers the later steps need from it.
	protected $lines = array();

	// Each discount code's share, by discount id.
	protected $codes = array();

	protected $shipping = array(
		'price'   => 0.0,
		'tax'     => 0.0,
		'taxable' => false,
	);

	protected $totals = array(
		'subtotal'               => 0.0,
		'subtotal_tax'           => 0.0,
		'discount'               => 0.0,
		'discount_taxable_total' => 0.0,
		'discount_tax'           => 0.0,
		'shipping'               => 0.0,
		'shipping_tax'           => 0.0,
		'tax'                    => 0.0,
	);

	/**
	 * @param array|false $tax_rate           Cart-wide tax rate, as SPC_Cart::get_tax_rate() returns it.
	 * @param bool|null   $price_has_tax      Prices include tax. Defaults to the store setting.
	 * @param bool|null   $discount_after_tax Discounts come off after tax. Defaults to the store setting.
	 */
	public function __construct( $tax_rate = false, $price_has_tax = null, $discount_after_tax = null ) {
		$this->tax_rate           = ( ! empty( $tax_rate['rate'] ) ) ? $tax_rate : false;
		$this->price_has_tax      = ( null === $price_has_tax ) ? self::prices_include_tax() : (bool) $price_has_tax;
		$this->discount_after_tax = ( null === $discount_after_tax ) ? (bool) SPC()->get_option( 'discount_after_tax' ) : (bool) $discount_after_tax;
	}

	public static function prices_include_tax() {
		return 'yes' === SPC()->get_option( 'price_has_tax' );
	}

	public static function round( $amount ) {
		return sunshine_round_amount( $amount );
	}

	/*
	 * Step 1: one line.
	 */

	/**
	 * Work out one cart line.
	 *
	 * The line discount can never be below zero or more than the line, and is rounded to the
	 * cent like every other amount. When prices include tax, the tax is taken out of the whole
	 * line (not one item times the quantity), so the line adds up to what the customer saw.
	 *
	 * @param array $args price (one item, as entered), options_total (as entered), qty,
	 *                    discount (the whole line's discount, as entered), taxable,
	 *                    tax_rate (array or false), price_has_tax (bool, defaults to the setting).
	 * @return array The line: price, options_total and tax are for one item (before tax when prices
	 *               include tax); subtotal, discount, taxable_total, tax_total and total are the line.
	 */
	public static function line( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'price'         => 0,
				'options_total' => 0,
				'qty'           => 0,
				'discount'      => 0,
				'taxable'       => false,
				'tax_rate'      => false,
				'price_has_tax' => null,
			)
		);

		$price         = (float) $args['price'];
		$options_total = (float) $args['options_total'];
		$qty           = (int) $args['qty'];
		$price_has_tax = ( null === $args['price_has_tax'] ) ? self::prices_include_tax() : (bool) $args['price_has_tax'];
		$rate          = ( ! empty( $args['tax_rate']['rate'] ) ) ? (float) $args['tax_rate']['rate'] : 0;

		$gross    = self::round( ( $price + $options_total ) * $qty );
		$discount = min( max( 0, self::round( (float) $args['discount'] ) ), max( 0, $gross ) );

		$line = array(
			'price'              => $price,
			'options_total'      => $options_total,
			'tax'                => 0.0,
			'subtotal'           => max( 0, $gross ),
			'discount'           => $discount,
			'discount_per_item'  => ( $discount > 0 && $qty > 0 ) ? $discount / $qty : 0,
			'taxable_total'      => 0.0,
			'tax_total'          => 0.0,
			'total'              => max( 0, self::round( $gross - $discount ) ),
			'original_subtotal'  => max( 0, $gross ),
			'original_tax_total' => 0.0,
			'rate'               => 0,
		);

		// Only taxed when the product has a price, is taxable and a tax rate matched.
		if ( ! $price || empty( $args['taxable'] ) || ! $rate ) {
			return $line;
		}

		$line['rate'] = $rate;
		$paid         = self::round( $gross - $discount );

		if ( $price_has_tax ) {
			// One item's price before tax, for showing a price and for payment services that
			// want a price for one item. Its tax is the exact rest, so price + tax is the tag.
			$line['price']         = self::round( $price / ( 1 + $rate ) );
			$line['options_total'] = self::round( $options_total / ( 1 + $rate ) );
			$line['tax']           = self::round( ( $price - $line['price'] ) + ( $options_total - $line['options_total'] ) );

			// The line's tax comes out of the whole line, after its discount.
			$line['subtotal']          = self::round( $gross / ( 1 + $rate ) );
			$line['taxable_total']     = self::round( $paid / ( 1 + $rate ) );
			$line['tax_total']         = self::round( $paid - $line['taxable_total'] );
			$line['total']             = $line['taxable_total'];
			$line['original_subtotal'] = $gross;
		} else {
			$line['original_tax_total'] = self::round( $gross * $rate );
			$line['taxable_total']      = $paid;
			$line['tax_total']          = self::round( $paid * $rate );
			$line['tax']                = ( $qty > 0 ) ? self::round( $line['tax_total'] / $qty ) : 0;
			$line['total']              = $paid;
		}

		return $line;
	}

	/*
	 * Step 1, for the whole cart: what the later steps need to know about each line.
	 */

	/**
	 * @param SPC_Cart_Item[] $items    The cart lines, already worked out.
	 * @param array           $shipping price, tax and taxable for the chosen shipping method.
	 */
	public function set_lines( $items, $shipping = array() ) {
		$this->lines = array();
		$this->codes = array();

		$subtotal     = 0;
		$subtotal_tax = 0;
		foreach ( (array) $items as $item ) {
			$rate = method_exists( $item, 'get_tax_rate' ) ? $item->get_tax_rate() : false;
			// A line without a line discount counts at its subtotal, which is the same thing except
			// where an add-on set the total itself (a gift card bought for an amount).
			$total = ( $item->get_discount() > 0 ) ? (float) $item->get_total() : (float) $item->get_subtotal();
			$tax   = (float) $item->get_tax_total();

			$this->lines[] = array(
				'item'    => $item,
				'taxable' => (bool) $item->is_taxable(),
				'rate'    => ( ! empty( $rate['rate'] ) ) ? (float) $rate['rate'] : 0,
				'total'   => $total,
				'tax'     => $tax,
				// What a discount code can take off this line: with its tax when prices include
				// tax or discounts come off after tax.
				'value'   => self::round( $total + ( ( $this->price_has_tax || $this->discount_after_tax ) ? $tax : 0 ) ),
			);

			$subtotal     += $total;
			$subtotal_tax += $tax;
		}

		$this->totals['subtotal']     = max( 0, self::round( $subtotal ) );
		$this->totals['subtotal_tax'] = self::round( $subtotal_tax );

		$this->set_shipping( $shipping );
	}

	public function set_shipping( $shipping ) {
		$shipping       = wp_parse_args( (array) $shipping, $this->shipping );
		$this->shipping = array(
			'price'   => self::round( $shipping['price'] ),
			'tax'     => self::round( $shipping['tax'] ),
			'taxable' => (bool) $shipping['taxable'],
		);

		$this->totals['shipping']     = $this->shipping['price'];
		$this->totals['shipping_tax'] = $this->shipping['tax'];
	}

	/**
	 * What a code covering shipping can take off it: with its tax when prices include tax or
	 * discounts come off after tax, the same rule as the item lines.
	 */
	protected function shipping_value() {
		return self::round( $this->shipping['price'] + ( ( $this->price_has_tax || $this->discount_after_tax ) ? $this->shipping['tax'] : 0 ) );
	}

	/*
	 * Step 2: discount codes.
	 */

	/**
	 * What one code takes off, on its own, before it is held to what is left after the codes
	 * before it. Lines it may not apply to are left out.
	 *
	 * @param SPC_Discount $discount The code.
	 * @return array amount and taxed (the part off taxed things), each split into items and
	 *               shipping, plus shares: what comes off each line, for codes on products.
	 */
	public function code_parts( $discount ) {
		$parts = array(
			'amount'         => 0.0,
			'taxed'          => 0.0,
			'items'          => 0.0,
			'items_taxed'    => 0.0,
			'shipping'       => 0.0,
			'shipping_taxed' => 0.0,
			'shares'         => array(),
			'on_products'    => false,
		);

		$amount_setting = (float) $discount->get_amount();
		if ( empty( $amount_setting ) || empty( $this->lines ) ) {
			return $parts;
		}

		$type    = $discount->get_type();
		$max_qty = (int) $discount->max_product_quantity();

		$all     = 0;
		$taxed   = 0;
		$product = array();
		foreach ( $this->lines as $i => $line ) {
			if ( ! $discount->applies_to_item( $line['item'] ) ) {
				continue;
			}
			$all += $line['value'];
			if ( $line['taxable'] ) {
				$taxed += $line['value'];
			}
			$product_id = (int) $line['item']->get_product_id();
			if ( ! isset( $product[ $product_id ] ) ) {
				$product[ $product_id ] = array(
					'value' => 0,
					'qty'   => 0,
					'lines' => array(),
				);
			}
			$product[ $product_id ]['value'] += $line['value'];
			$product[ $product_id ]['qty']   += (int) $line['item']->get_qty();
			$product[ $product_id ]['lines'][] = $i;
		}

		// Nothing to take off, unless the code also covers shipping: a percent off the order and
		// shipping still takes its percent off the shipping when the lines it covers cost nothing
		// (all included prints, say).
		$covers_shipping = ( 'percent-total' === $type && $discount->apply_to_shipping() );
		if ( empty( $product ) || ( $all <= 0 && ! $covers_shipping ) ) {
			return $parts;
		}

		$items       = 0;
		$items_taxed = 0;
		$ship_exact  = 0;
		$shares      = array();

		if ( 'percent-total' === $type ) {

			$percent     = $amount_setting / 100;
			$items       = $all * $percent;
			$items_taxed = $taxed * $percent;
			if ( $discount->apply_to_shipping() ) {
				$ship_exact = $this->shipping_value() * $percent;
			}
			foreach ( $product as $group ) {
				foreach ( $group['lines'] as $i ) {
					$shares[ $i ] = $this->lines[ $i ]['value'] * $percent;
				}
			}
		} elseif ( 'amount-total' === $type ) {

			// Never more than what the code applies to. The taxed part is its share of that.
			$items       = min( $amount_setting, $all );
			$items_taxed = $items * $taxed / $all;
			foreach ( $product as $group ) {
				foreach ( $group['lines'] as $i ) {
					$shares[ $i ] = $items * $this->lines[ $i ]['value'] / $all;
				}
			}
		} elseif ( 'percent-product' === $type || 'amount-product' === $type ) {

			$parts['on_products'] = true;
			foreach ( $product as $group ) {
				if ( $group['value'] <= 0 ) {
					continue;
				}
				// "Maximum quantity" limits how many of each product the code is for.
				$count = ( $max_qty > 0 && $group['qty'] > $max_qty ) ? $max_qty : $group['qty'];
				$scale = ( $group['qty'] > 0 ) ? $count / $group['qty'] : 0;

				if ( 'percent-product' === $type ) {
					foreach ( $group['lines'] as $i ) {
						$shares[ $i ] = $this->lines[ $i ]['value'] * $scale * $amount_setting / 100;
					}
				} elseif ( $max_qty > 0 ) {
					// Each item can lose at most what it costs.
					$off = min( $amount_setting * $count, $group['value'] * $scale );
					foreach ( $group['lines'] as $i ) {
						$shares[ $i ] = $off * $this->lines[ $i ]['value'] / $group['value'];
					}
				} else {
					foreach ( $group['lines'] as $i ) {
						$shares[ $i ] = min( $amount_setting * (int) $this->lines[ $i ]['item']->get_qty(), $this->lines[ $i ]['value'] );
					}
				}
			}
			foreach ( $shares as $i => $share ) {
				$items += $share;
				if ( $this->lines[ $i ]['taxable'] ) {
					$items_taxed += $share;
				}
			}
		}

		// The code's whole amount is rounded once; the shipping part is rounded and the items
		// get the rest, so the two parts always add up to the amount.
		$taxed_ship              = $this->shipping['taxable'] ? $ship_exact : 0;
		$parts['shipping']       = self::round( $ship_exact );
		$parts['shipping_taxed'] = $this->shipping['taxable'] ? $parts['shipping'] : 0;
		$parts['items']          = min( max( 0, self::round( self::round( $items + $ship_exact ) - $parts['shipping'] ) ), self::round( $all ) );
		$parts['items_taxed']    = min( max( 0, self::round( self::round( $items_taxed + $taxed_ship ) - $parts['shipping_taxed'] ) ), $parts['items'] );
		$parts['shares']         = $shares;
		$parts['amount']         = self::round( $parts['items'] + $parts['shipping'] );
		$parts['taxed']          = self::round( $parts['items_taxed'] + $parts['shipping_taxed'] );

		return $parts;
	}

	/**
	 * Work out every code, in the order they were entered. Together they can never take more
	 * than the lines they apply to, plus the shipping when a code covers shipping, and so never
	 * eat into tax or (unless a code covers it) shipping.
	 *
	 * @param SPC_Discount[] $discounts The codes in the cart.
	 * @return float The codes added up.
	 */
	public function calculate_discounts( $discounts ) {
		$this->codes = array();

		$parts_by_code = array();
		$covered       = array();
		$covers_ship   = false;
		foreach ( (array) $discounts as $key => $discount ) {
			if ( ! is_a( $discount, 'SPC_Discount' ) ) {
				continue;
			}
			$parts                 = $this->code_parts( $discount );
			$parts_by_code[ $key ] = array( $discount, $parts );
			foreach ( $this->lines as $i => $line ) {
				if ( ! isset( $covered[ $i ] ) && $discount->applies_to_item( $line['item'] ) ) {
					$covered[ $i ] = true;
				}
			}
			$covers_ship = $covers_ship || $parts['shipping'] > 0;
		}

		// What all the codes together may take.
		$items_left = 0;
		$taxed_left = 0;
		foreach ( array_keys( $covered ) as $i ) {
			$items_left += $this->lines[ $i ]['value'];
			if ( $this->lines[ $i ]['taxable'] ) {
				$taxed_left += $this->lines[ $i ]['value'];
			}
		}
		$items_left = self::round( $items_left );
		$taxed_left = self::round( $taxed_left );
		$ship_left  = $covers_ship ? $this->shipping_value() : 0;

		$discount_total = 0;
		$taxed_total    = 0;
		foreach ( $parts_by_code as $key => $code ) {
			list( $discount, $parts ) = $code;

			$items = min( $parts['items'], $items_left );
			$scale = ( $parts['items'] > 0 ) ? $items / $parts['items'] : 0;
			$ship  = min( $parts['shipping'], $ship_left );

			$items_taxed = min( self::round( $parts['items_taxed'] * $scale ), $items, $taxed_left );
			$ship_taxed  = ( $parts['shipping_taxed'] > 0 ) ? $ship : 0;

			$items_left = self::round( $items_left - $items );
			$taxed_left = self::round( $taxed_left - $items_taxed );
			$ship_left  = self::round( $ship_left - $ship );

			$shares = array();
			foreach ( $parts['shares'] as $i => $share ) {
				$shares[ $i ] = $share * $scale;
			}

			$this->codes[ $discount->get_id() ] = array(
				'amount'         => self::round( $items + $ship ),
				'taxed'          => self::round( $items_taxed + $ship_taxed ),
				'items'          => $items,
				'items_taxed'    => $items_taxed,
				'shipping'       => $ship,
				'shipping_taxed' => $ship_taxed,
				'shares'         => $shares,
				'on_products'    => $parts['on_products'],
			);

			$discount_total += $items + $ship;
			$taxed_total    += $items_taxed + $ship_taxed;
		}

		$this->totals['discount']               = self::round( $discount_total );
		$this->totals['discount_taxable_total'] = self::round( $taxed_total );

		return $this->totals['discount'];
	}

	/**
	 * What one code took off this cart, or null if it was not worked out with the others.
	 *
	 * @param int $discount_id Discount id.
	 * @return array|null amount and taxed, among others.
	 */
	public function get_code( $discount_id ) {
		return isset( $this->codes[ $discount_id ] ) ? $this->codes[ $discount_id ] : null;
	}

	/**
	 * Set the order discount when an add-on changed it (sunshine_cart_discount).
	 */
	public function set_discount( $amount ) {
		$this->totals['discount'] = max( 0, self::round( $amount ) );
	}

	/*
	 * Step 3: tax.
	 */

	/**
	 * Tax on the items and the shipping, less what discount codes took off taxed things.
	 * Discounts after tax leave the tax alone.
	 *
	 * @return float The tax, before the sunshine_cart_tax filter.
	 */
	public function calculate_tax() {
		$this->totals['tax']          = 0.0;
		$this->totals['discount_tax'] = 0.0;

		if ( ! $this->tax_rate ) {
			return 0.0;
		}

		$rate = (float) $this->tax_rate['rate'];

		// Tax on the items and shipping with no codes: each line's tax added up.
		$items_tax_before = 0;
		$all_at_cart_rate = true;
		$taxed_base       = 0;
		foreach ( $this->lines as $line ) {
			$items_tax_before += $line['tax'];
			if ( ! $line['taxable'] ) {
				continue;
			}
			if ( $line['rate'] ) {
				$taxed_base += $line['total'] + ( $this->price_has_tax ? $line['tax'] : 0 );
			}
			if ( $line['value'] > 0 && abs( $line['rate'] - $rate ) > 0.0000001 ) {
				$all_at_cart_rate = false;
			}
		}
		$items_tax_before = self::round( $items_tax_before );

		$off_items = 0;
		$off_ship  = 0;
		if ( ! $this->discount_after_tax ) {
			foreach ( $this->codes as $code ) {
				$off_items += $code['items_taxed'];
				$off_ship  += $code['shipping_taxed'];
			}
		}

		$items_tax = $items_tax_before;
		if ( $off_items > 0 ) {
			if ( $all_at_cart_rate ) {
				// Worked out once on what is left, so it is only ever rounded once.
				$left      = max( 0, self::round( $taxed_base - $off_items ) );
				$items_tax = $this->price_has_tax ? self::round( $left - self::round( $left / ( 1 + $rate ) ) ) : self::round( $left * $rate );
			} else {
				// Some products have their own rate (sunshine_cart_item_tax_rate). A code on
				// products takes tax off at each product's rate. A code on the whole order takes it
				// off at the store's rate, as the "different tax rate per product" doc says.
				$off_tax = 0;
				foreach ( $this->codes as $code ) {
					if ( $code['on_products'] ) {
						foreach ( $code['shares'] as $i => $share ) {
							if ( $this->lines[ $i ]['taxable'] && $this->lines[ $i ]['rate'] ) {
								$off_tax += $this->tax_in( $share, $this->lines[ $i ]['rate'] );
							}
						}
					} else {
						$off_tax += $this->tax_in( $code['items_taxed'], $rate );
					}
				}
				$items_tax = max( 0, self::round( $items_tax_before - $off_tax ) );
			}
		}

		// Shipping. It always uses the store's rate.
		$ship_tax_before = 0;
		$ship_tax        = 0;
		if ( $this->price_has_tax ) {
			if ( $this->shipping['taxable'] ) {
				$ship_tax_before = $this->shipping['tax'];
				$ship_tax        = $ship_tax_before;
				if ( $off_ship > 0 ) {
					$left     = max( 0, self::round( $this->shipping['price'] + $this->shipping['tax'] - $off_ship ) );
					$ship_tax = self::round( $left - self::round( $left / ( 1 + $rate ) ) );
				}
			}
		} else {
			$ship_taxable = 0;
			if ( $this->shipping['taxable'] ) {
				$ship_taxable    = max( 0, self::round( $this->shipping['price'] - $off_ship ) );
				$ship_tax_before = self::round( $this->shipping['price'] * $rate );
			}
			$ship_taxable = apply_filters( 'sunshine_cart_taxable_total', $ship_taxable, $this->tax_rate );
			if ( $ship_taxable ) {
				$ship_tax = self::round( $ship_taxable * $rate );
			}
		}

		$this->totals['tax'] = self::round( $items_tax + $ship_tax );

		// The tax the codes took off, so a discount shown with its tax matches the totals.
		if ( ! $this->discount_after_tax && $this->totals['discount'] > 0 ) {
			$this->totals['discount_tax'] = max( 0, self::round( $items_tax_before + $ship_tax_before - $this->totals['tax'] ) );
		}

		return $this->totals['tax'];
	}

	/**
	 * The tax inside (prices include tax) or on top of (they don't) an amount.
	 */
	protected function tax_in( $amount, $rate ) {
		if ( $this->price_has_tax ) {
			return self::round( $amount - self::round( $amount / ( 1 + $rate ) ) );
		}
		return self::round( $amount * $rate );
	}

	/**
	 * Set the tax when an add-on changed it (sunshine_cart_tax).
	 */
	public function set_tax( $amount ) {
		$this->totals['tax'] = empty( $amount ) ? 0.0 : self::round( $amount );
	}

	/*
	 * Steps 4 to 6: what is left to pay.
	 */

	/**
	 * What the order costs before fees and store credit, with each part left out on request.
	 *
	 * @param array $exclude Any of subtotal, discounts, shipping, tax.
	 * @return array The parts, keyed, that add up to it.
	 */
	public function get_parts( $exclude = array() ) {
		$parts = array(
			'subtotal'  => 0,
			'discounts' => 0,
			'shipping'  => 0,
			'tax'       => 0,
		);

		if ( ! in_array( 'subtotal', $exclude, true ) ) {
			if ( $this->price_has_tax ) {
				// What the customer pays for the items: their price tags, less line discounts.
				$items = 0;
				foreach ( $this->lines as $line ) {
					$items += $line['total'] + $line['tax'];
				}
				$parts['subtotal'] = self::round( $items );
			} else {
				$parts['subtotal'] = $this->totals['subtotal'];
			}
		}

		if ( ! in_array( 'discounts', $exclude, true ) && $this->totals['discount'] > 0 ) {
			$parts['discounts'] = -$this->totals['discount'];
		}

		if ( ! in_array( 'shipping', $exclude, true ) ) {
			$parts['shipping'] = $this->totals['shipping'] + ( $this->price_has_tax ? $this->totals['shipping_tax'] : 0 );
		}

		// Prices that include tax already hold it.
		if ( ! $this->price_has_tax && ! in_array( 'tax', $exclude, true ) ) {
			$parts['tax'] = $this->totals['tax'];
		}

		return $parts;
	}

	/**
	 * Store credit used: as much as there is, never more than is left to pay.
	 *
	 * @param float $balance The customer's credit.
	 * @param float $left    What is left to pay before credit.
	 */
	public static function credits_applied( $balance, $left ) {
		return max( 0, min( self::round( $balance ), self::round( $left ) ) );
	}

	/**
	 * Everything added up, never below zero.
	 */
	public static function add_up( $parts ) {
		return max( 0, self::round( array_sum( $parts ) ) );
	}

	public function get( $key ) {
		return isset( $this->totals[ $key ] ) ? $this->totals[ $key ] : 0.0;
	}
}
