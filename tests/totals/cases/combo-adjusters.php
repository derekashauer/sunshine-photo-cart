<?php
/**
 * Combination tests for everything that changes what the customer pays.
 *
 * Instead of working out each cart by hand, many carts are built from a list of ingredients and
 * checked against rules that must hold whatever the mix: the parts add up to the total, nothing
 * goes below zero or into fractions of a cent, a discount or gift card never takes more than
 * what it applies to, tax is the rate on what is taxed, the saved order matches the cart, and
 * PayPal is sent numbers that add up.
 *
 * Ingredients: prices with or without tax included, discount before or after tax, a tax rate
 * (or none), one to four lines of taxed or untaxed products with odd-cent prices and quantities
 * (sometimes 300), quantity discounts, products included in a paid session, up to three discount
 * codes of every type and rule (only some products, all but one product, including shipping,
 * max quantity, "only this discount"), one or two gift cards (part of the order or more than all
 * of it), store credit, a payment fee (fixed or percent), flat rate shipping (taxed or not),
 * paying with PayPal, and placing the order.
 *
 * Each seed picks its ingredients from a fixed hash of the seed number, so seed 17 is always the
 * same cart. A failure shows the seed and its ingredients in the test name, and is labelled with
 * its cause when one is known: "[already known: ...]" for problems other tests found, "[found
 * here: ...]" for problems these combinations found (each has a hand-worked test of its smallest
 * cart at the end of this file). A failure with no label is something new. Ways to run it:
 *   SPC_TOTALS_ONLY=combo-adjusters                       all seeds (1 to 200), about a minute
 *   SPC_COMBO_FROM=201 SPC_COMBO_TO=400                   a range, to split a long run into
 *                                                         separate processes
 *   SPC_COMBO_SEEDS=17,42                                 only these seeds
 *   SPC_COMBO_SHRINK=1                                    also take ingredients away from a
 *                                                         failing cart one at a time and print
 *                                                         the smallest cart that still breaks
 *   SPC_COMBO_DEBUG=1                                     print what the cart showed each time
 *
 * Nothing here reaches an outside service or sends an email: every outgoing request is stopped
 * for as long as this file runs.
 */

$t = 'SPC_Totals_Test';

// Every helper lives in this one array, so the closures can reach each other.
$combo = array();

$combo['env'] = function ( $name, $default ) {
	$value = getenv( $name );
	return ( false === $value || '' === $value ) ? $default : $value;
};

$combo['seeds'] = array();
if ( '' !== (string) $combo['env']( 'SPC_COMBO_SEEDS', '' ) ) {
	$combo['seeds'] = array_map( 'intval', explode( ',', (string) $combo['env']( 'SPC_COMBO_SEEDS', '' ) ) );
} else {
	$combo['seeds'] = range( (int) $combo['env']( 'SPC_COMBO_FROM', 1 ), (int) $combo['env']( 'SPC_COMBO_TO', 200 ) );
}
$combo['shrink_on'] = (bool) $combo['env']( 'SPC_COMBO_SHRINK', '' );
$combo['debug_on']  = (bool) $combo['env']( 'SPC_COMBO_DEBUG', '' );
// The hand-worked tests at the end run once: in a full run, or in the batch that starts at seed 1.
$combo['hand_on'] = ( '' === (string) $combo['env']( 'SPC_COMBO_SEEDS', '' ) && (int) $combo['env']( 'SPC_COMBO_FROM', 1 ) <= 1 );

// ---------------------------------------------------------------------------------------------
// Keep this file to itself: no outside requests, no emails, and nothing left behind.
// ---------------------------------------------------------------------------------------------

$combo['blocked'] = array();
$combo['offline'] = function ( $pre, $args, $url ) use ( &$combo ) {
	$combo['blocked'][] = $url;
	return new WP_Error( 'spc_totals_offline', 'The totals tests never reach outside services' );
};
$combo['no']      = function () {
	return false;
};
add_filter( 'pre_http_request', $combo['offline'], PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', $combo['no'], PHP_INT_MAX );
add_filter( 'sunshine_checkout_allow_order_notify', $combo['no'], PHP_INT_MAX );

// Other agents test automatic discounts on this site at the same time. One of theirs must never
// land in these carts, so automatic discounts are not looked up while this file runs. Each cart
// here only uses its own codes.
$combo['auto_hook'] = has_action( 'sunshine_set_discounts', 'sunshine_discounts_set_auto' );
if ( false !== $combo['auto_hook'] ) {
	remove_action( 'sunshine_set_discounts', 'sunshine_discounts_set_auto', $combo['auto_hook'] );
}

// Adding to the cart makes the Analytics add-on log a row, and for a logged in customer the
// Automated Emails add-on may queue an email. Remember the products and queued emails made here,
// so those rows can be deleted at the end of this file.
$combo['made_products'] = array();
$combo['made_emails']   = array();
$combo['remember']      = function ( $post_id, $post ) use ( &$combo ) {
	if ( 'sunshine-product' === $post->post_type ) {
		$combo['made_products'][] = (int) $post_id;
	} elseif ( 'sunshine-email-log' === $post->post_type ) {
		$combo['made_emails'][] = (int) $post_id;
	}
};
add_action( 'wp_insert_post', $combo['remember'], 10, 2 );

// Each time Sunshine builds a shipping or delivery method object, the object adds a filter that
// is never taken off (already reported), so a long run gets slower with every cart. Take off
// the ones each cart added once it is done.
$combo['without_new_filters'] = function ( $callback ) {
	global $wp_filter;
	$hooks  = array( 'sunshine_shipping_methods', 'sunshine_checkout_delivery_options', 'sunshine_delivery_methods', 'sunshine_options_shipping' );
	$before = array();
	foreach ( $hooks as $hook ) {
		$before[ $hook ] = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array();
	}
	try {
		return $callback();
	} finally {
		foreach ( $hooks as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback_id => $entry ) {
					if ( ! isset( $before[ $hook ][ $priority ][ $callback_id ] ) ) {
						remove_filter( $hook, $entry['function'], $priority );
					}
				}
			}
		}
	}
};

// ---------------------------------------------------------------------------------------------
// Picking ingredients. Fixed for each seed: a hash of the seed number and what is being picked.
// ---------------------------------------------------------------------------------------------

$combo['hash']   = function ( $seed, $what ) {
	return crc32( 'spc-combo|' . $what . '|' . $seed );
};
$combo['pick']   = function ( $seed, $what, $choices ) use ( &$combo ) {
	$choices = array_values( $choices );
	return $choices[ $combo['hash']( $seed, $what ) % count( $choices ) ];
};
$combo['chance'] = function ( $seed, $what, $percent ) use ( &$combo ) {
	return ( $combo['hash']( $seed, $what ) % 100 ) < $percent;
};

$combo['recipe'] = function ( $seed ) use ( &$combo ) {
	$pick   = $combo['pick'];
	$chance = $combo['chance'];
	$recipe = array(
		'seed'     => $seed,
		'mode'     => $chance( $seed, 'mode', 50 ) ? 'yes' : 'no',
		'after'    => $chance( $seed, 'after', 35 ),
		'rate'     => $pick( $seed, 'rate', array( 0, 6, 7.25, 8.875, 10, 10, 20 ) ),
		'lines'    => array(),
		'session'  => null,
		'codes'    => array(),
		// Gift card balances: none, one, or (several can be used on one order) two.
		'gift'     => array_values( array_filter( array( $pick( $seed, 'gift', array( 0, 0, 0, 0, 10, 25.37, 5000 ) ), $pick( $seed, 'gift2', array( 0, 0, 0, 0, 0, 0, 15, 5000 ) ) ) ) ),
		// The customer screen saves credits as whole dollars.
		'credits'  => $pick( $seed, 'credits', array( 0, 0, 0, 0, 5, 15, 5000 ) ),
		'fee'      => $pick(
			$seed,
			'fee',
			array(
				null,
				null,
				null,
				array( 'type' => 'amount', 'amount' => 2.50 ),
				array( 'type' => 'amount', 'amount' => 1 ),
				array( 'type' => 'percent', 'amount' => 3 ),
				array( 'type' => 'percent', 'amount' => 2.9 ),
			)
		),
		'shipping' => $pick(
			$seed,
			'shipping',
			array(
				null,
				null,
				array( 'price' => 7.95, 'taxable' => false, 'type' => '' ),
				array( 'price' => 7.95, 'taxable' => true, 'type' => '' ),
				array( 'price' => 12, 'taxable' => true, 'type' => 'cart' ),
				array( 'price' => 9.99, 'taxable' => true, 'type' => 'cart' ),
				array( 'price' => 5, 'taxable' => false, 'type' => 'cart' ),
			)
		),
		'gateway'  => $chance( $seed, 'paypal', 25 ) ? 'paypal' : 'test',
		'place'    => $chance( $seed, 'place', 60 ),
	);

	$prices = array( 19.99, 0.99, 3.33, 12.49, 42.90, 100, 7.77, 0.20, 1234.56, 9.99, 24.95, 5.55 );
	$count  = $pick( $seed, 'count', array( 1, 2, 2, 3, 3, 4 ) );
	for ( $i = 0; $i < $count; $i++ ) {
		$qty       = $chance( $seed, 'big-qty-' . $i, 3 ) ? 300 : $pick( $seed, 'qty-' . $i, array( 1, 1, 2, 3, 5, 7, 12, 25 ) );
		$originals = array();
		foreach ( $recipe['lines'] as $j => $earlier ) {
			if ( null === $earlier['same_as'] ) {
				$originals[] = $j;
			}
		}
		// Sometimes a second line of a product already in the cart (lines with no photo are
		// never merged), so quantities and included products are shared between lines.
		if ( $originals && $chance( $seed, 'same-' . $i, 15 ) ) {
			$from              = $pick( $seed, 'same-from-' . $i, $originals );
			$line              = $recipe['lines'][ $from ];
			$line['same_as']   = $from;
			$line['qty']       = $qty;
			$recipe['lines'][] = $line;
			continue;
		}
		$price = $pick( $seed, 'price-' . $i, $prices );
		$tier  = null;
		if ( $chance( $seed, 'tier-' . $i, 30 ) ) {
			$type = $pick( $seed, 'tier-type-' . $i, array( 'amount', 'percent' ) );
			$tier = array(
				'qty'      => $pick( $seed, 'tier-qty-' . $i, array( 2, 3, 5 ) ),
				'type'     => $type,
				// Kept below the price: a tier bigger than the price is a known bug of its own.
				'discount' => ( 'percent' === $type )
					? $pick( $seed, 'tier-percent-' . $i, array( 5, 10, 15, 25, 33 ) )
					: max( 0.01, round( $price * $pick( $seed, 'tier-amount-' . $i, array( 0.1, 0.25, 0.4 ) ), 2 ) ),
				'per_line' => $chance( $seed, 'tier-calc-' . $i, 50 ),
			);
		}
		$recipe['lines'][] = array(
			'price'   => $price,
			'taxable' => $chance( $seed, 'taxable-' . $i, 75 ),
			'qty'     => $qty,
			'tier'    => $tier,
			'same_as' => null,
		);
	}

	if ( $chance( $seed, 'session', 15 ) ) {
		$recipe['session'] = array(
			'line' => $pick( $seed, 'session-line', array_keys( $recipe['lines'] ) ),
			'qty'  => $pick( $seed, 'session-qty', array( 1, 2, 3 ) ),
		);
	}

	$code_count = $pick( $seed, 'codes', array( 0, 1, 1, 1, 2, 2, 3 ) );
	for ( $c = 0; $c < $code_count; $c++ ) {
		$type   = $pick( $seed, 'code-type-' . $c, array( 'percent-total', 'amount-total', 'percent-product', 'amount-product' ) );
		$only   = $chance( $seed, 'code-only-' . $c, 35 ) ? $pick( $seed, 'code-only-line-' . $c, array_keys( $recipe['lines'] ) ) : null;
		$except = ( null === $only && $chance( $seed, 'code-except-' . $c, 15 ) ) ? $pick( $seed, 'code-except-line-' . $c, array_keys( $recipe['lines'] ) ) : null;
		if ( 'percent-total' === $type ) {
			$amount = $pick( $seed, 'code-amount-' . $c, array( 5, 10, 15, 33, 50, 100 ) );
		} elseif ( 'amount-total' === $type ) {
			$amount = $pick( $seed, 'code-amount-' . $c, array( 5, 12.34, 20, 1000 ) );
		} elseif ( 'percent-product' === $type ) {
			$amount = $pick( $seed, 'code-amount-' . $c, array( 10, 25, 50, 100 ) );
		} else {
			// Kept below the cheapest product it applies to: more than the product costs is a
			// known bug of its own.
			$cheapest = null;
			foreach ( $recipe['lines'] as $j => $line ) {
				if ( ( null === $only || $j === $only ) && $j !== $except ) {
					$cheapest = ( null === $cheapest ) ? $line['price'] : min( $cheapest, $line['price'] );
				}
			}
			$amount = max( 0.01, round( ( null === $cheapest ? 1 : $cheapest ) * $pick( $seed, 'code-amount-' . $c, array( 0.1, 0.25, 0.4 ) ), 2 ) );
		}
		$recipe['codes'][] = array(
			'type'     => $type,
			'amount'   => $amount,
			'only'     => $only,
			// "Disallowed Products": everything except this line's product.
			'except'   => $except,
			'shipping' => 'percent-total' === $type && $chance( $seed, 'code-shipping-' . $c, 50 ),
			'max_qty'  => ( in_array( $type, array( 'percent-product', 'amount-product' ), true ) && $chance( $seed, 'code-max-' . $c, 40 ) ) ? $pick( $seed, 'code-max-qty-' . $c, array( 1, 2, 3 ) ) : null,
			// "Only This Discount": no other code can be used with it.
			'solo'     => $chance( $seed, 'code-solo-' . $c, 10 ),
		);
	}

	return $recipe;
};

// The ingredients in plain words, for test names and failure messages.
$combo['describe'] = function ( $r ) {
	$parts   = array();
	$parts[] = ( 'yes' === $r['mode'] ? 'prices include tax' : 'prices without tax' ) . ', discount ' . ( $r['after'] ? 'after' : 'before' ) . ' tax, ' . ( $r['rate'] ? 'tax ' . $r['rate'] . '%' : 'taxes off' );
	$lines   = array();
	foreach ( $r['lines'] as $i => $l ) {
		$text = 'L' . ( $i + 1 ) . ' ' . $l['price'] . ' x' . $l['qty'] . ( $l['taxable'] ? ' taxed' : ' untaxed' );
		if ( null !== $l['same_as'] ) {
			$text .= ' (same product as L' . ( $l['same_as'] + 1 ) . ')';
		} elseif ( $l['tier'] ) {
			$text .= ' [qty discount at ' . $l['tier']['qty'] . '+: ' . ( 'percent' === $l['tier']['type'] ? $l['tier']['discount'] . '%' : $l['tier']['discount'] . ' off each' ) . ( $l['tier']['per_line'] ? ', per line' : ', whole cart' ) . ']';
		}
		$lines[] = $text;
	}
	$parts[] = implode( '; ', $lines );
	if ( $r['session'] ) {
		$parts[] = 'session includes ' . $r['session']['qty'] . ' of L' . ( $r['session']['line'] + 1 );
	}
	foreach ( $r['codes'] as $c => $code ) {
		$parts[] = 'code ' . ( $c + 1 ) . ': ' . $code['type'] . ' ' . $code['amount']
			. ( null !== $code['only'] ? ' only L' . ( $code['only'] + 1 ) . ' product' : '' )
			. ( null !== $code['except'] ? ' except L' . ( $code['except'] + 1 ) . ' product' : '' )
			. ( $code['shipping'] ? ' incl. shipping' : '' )
			. ( $code['max_qty'] ? ' max qty ' . $code['max_qty'] : '' )
			. ( $code['solo'] ? ' solo' : '' );
	}
	if ( $r['gift'] ) {
		$parts[] = ( count( $r['gift'] ) > 1 ? 'gift cards ' : 'gift card ' ) . implode( ' + ', $r['gift'] );
	}
	if ( $r['credits'] ) {
		$parts[] = 'credit ' . $r['credits'];
	}
	if ( $r['fee'] ) {
		$parts[] = $r['gateway'] . ' fee ' . ( 'percent' === $r['fee']['type'] ? $r['fee']['amount'] . '%' : $r['fee']['amount'] );
	}
	if ( $r['shipping'] ) {
		$parts[] = 'flat shipping ' . $r['shipping']['price'] . ( $r['shipping']['taxable'] ? ' taxed' : ' untaxed' ) . ( '' === $r['shipping']['type'] ? ', no price type' : ', price type ' . $r['shipping']['type'] );
	}
	$parts[] = ( 'paypal' === $r['gateway'] ? 'PayPal' : 'Test payment' ) . ( $r['place'] ? ', order placed' : '' );
	return implode( ' | ', $parts );
};

// ---------------------------------------------------------------------------------------------
// Building the cart the way the admin screens and the checkout would.
// ---------------------------------------------------------------------------------------------

$combo['build'] = function ( $r ) use ( $t ) {
	$built = array(
		'products' => array(),
		'codes'    => array(),
		'customer' => 0,
	);

	// Store credit and session products are kept on the customer, so those carts need one.
	$needs_customer = $r['session'] || $r['credits'];
	if ( $needs_customer ) {
		$built['customer'] = $t::customer();
	}

	if ( $r['rate'] ) {
		$t::tax( $r['rate'] );
	}
	$t::option( 'price_has_tax', $r['mode'] );
	$t::option( 'discount_after_tax', $r['after'] ? '1' : '' );

	foreach ( $r['lines'] as $i => $line ) {
		if ( null !== $line['same_as'] ) {
			$built['products'][ $i ] = $built['products'][ $line['same_as'] ];
			continue;
		}
		$args = array(
			'name'    => 'Combo test product',
			'taxable' => $line['taxable'],
		);
		if ( $line['tier'] ) {
			// What the Quantity Discounts box on the product screen saves: strings, and "calc"
			// only when "Calculate quantities per line item" is ticked.
			$rule = array(
				'items' => array(
					array(
						'qty'      => (string) $line['tier']['qty'],
						'discount' => (string) $line['tier']['discount'],
						'type'     => $line['tier']['type'],
					),
				),
			);
			if ( $line['tier']['per_line'] ) {
				$rule['calc'] = '1';
			}
			$args['qty_discount'] = $rule;
		}
		$built['products'][ $i ] = $t::product( $line['price'], $args );
	}

	if ( $r['session'] ) {
		// What a paid session gives the customer: the product and how many are included.
		update_user_meta(
			$built['customer'],
			'sunshine_session_products',
			array(
				array(
					'product'  => (string) $built['products'][ $r['session']['line'] ],
					'qty'      => (string) $r['session']['qty'],
					'order_id' => 0,
				),
			)
		);
	}
	if ( $r['credits'] ) {
		update_user_meta( $built['customer'], 'sunshine_credits', (int) $r['credits'] );
	}
	if ( $needs_customer ) {
		SPC()->customer = new SPC_Customer( $built['customer'] );
	}

	$t::checkout_address();
	if ( $r['credits'] ) {
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
	}

	if ( $r['shipping'] ) {
		// What Settings > Delivery & Shipping saves for one flat rate. With Advanced Shipping a
		// price type is saved, without it there is none.
		$instance = 'spccomboflat';
		$t::option(
			'shipping_methods',
			array(
				$instance => array(
					'id'     => 'flat_rate',
					'active' => 1,
				),
			)
		);
		$t::option( 'flat_rate_price_' . $instance, (string) $r['shipping']['price'] );
		$t::option( 'flat_rate_price_type_' . $instance, $r['shipping']['type'] );
		$t::option( 'flat_rate_taxable_' . $instance, $r['shipping']['taxable'] ? '1' : '' );
		SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
		SPC()->cart->set_checkout_data_item( 'shipping_method', $instance );
	}

	$gateway = $r['gateway'];
	$t::option( $gateway . '_fee', $r['fee'] ? $r['fee']['type'] : 'none' );
	$t::option( $gateway . '_fee_amount', $r['fee'] ? (string) $r['fee']['amount'] : '' );
	$t::option( $gateway . '_fee_name', 'Card fee' );
	SPC()->cart->set_checkout_data_item( 'payment_method', $gateway );

	foreach ( $r['lines'] as $i => $line ) {
		$t::add( $built['products'][ $i ], $line['qty'] );
	}

	foreach ( $r['codes'] as $c => $code ) {
		// What the Discounts screen saves: every field, empty ones as '', product lists as arrays.
		$meta                     = array(
			'auto'                  => '',
			'apply_to_shipping'     => $code['shipping'] ? '1' : '',
			'free_shipping'         => '',
			'start_date'            => '',
			'end_date'              => '',
			'solo'                  => $code['solo'] ? '1' : '',
			'required_products'     => array(),
			'allowed_products'      => ( null !== $code['only'] ) ? array( (int) $built['products'][ $code['only'] ] ) : array(),
			'disallowed_products'   => ( null !== $code['except'] ) ? array( (int) $built['products'][ $code['except'] ] ) : array(),
			'allowed_categories'    => '',
			'disallowed_categories' => '',
			'allowed_galleries'     => array(),
			'disallowed_galleries'  => array(),
			'min_amount'            => '',
			'min_quantity'          => '',
			'max_uses'              => '',
			'max_uses_per_person'   => '',
			'max_product_quantity'  => $code['max_qty'] ? (string) $code['max_qty'] : '',
		);
		$built['codes'][ $c ] = $t::discount( $code['type'], $code['amount'], $meta );
	}

	foreach ( $r['gift'] as $balance ) {
		$t::gift_card( $balance );
	}

	return $built;
};

// Everything the customer sees, read from the cart's own getters.
$combo['snapshot'] = function () {
	$cart  = SPC()->cart;
	$lines = array();
	foreach ( (array) $cart->get_cart_items() as $line ) {
		$lines[] = array(
			'product'  => (int) $line->get_product_id(),
			'qty'      => (int) $line->get_qty(),
			'total'    => (float) $line->get_total(),
			'tax'      => (float) $line->get_tax_total(),
			'discount' => (float) $line->get_discount_total(),
		);
	}
	$fees = array();
	foreach ( (array) $cart->get_fees() as $id => $fee ) {
		$fees[ $id ] = (float) $fee['amount'];
	}
	$codes = array();
	foreach ( (array) $cart->get_discounts() as $discount ) {
		$codes[ $discount->get_code() ] = array(
			'amount' => (float) $discount->get_total(),
			'taxed'  => (float) $discount->get_total( true ),
		);
	}
	return array(
		'subtotal'     => (float) $cart->get_subtotal(),
		'subtotal_tax' => (float) $cart->get_subtotal_tax(),
		'discount'     => (float) $cart->get_discount(),
		'discount_tax' => (float) $cart->get_discount_tax(),
		'tax'          => (float) $cart->get_tax(),
		'shipping'     => (float) $cart->get_shipping(),
		'shipping_tax' => (float) $cart->get_shipping_tax(),
		'fees'         => $fees,
		'credits'      => (float) $cart->get_credits_applied(),
		'gift'         => (float) array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) ),
		'total'        => (float) $cart->get_total(),
		'codes'        => $codes,
		'lines'        => $lines,
	);
};

// ---------------------------------------------------------------------------------------------
// Small number helpers.
// ---------------------------------------------------------------------------------------------

$combo['num']   = function ( $x ) {
	$text = rtrim( rtrim( sprintf( '%.4f', (float) $x ), '0' ), '.' );
	return ( '' === $text || '-0' === $text ) ? '0' : $text;
};
$combo['eq']    = function ( $a, $b ) {
	return abs( round( (float) $a, 2 ) - round( (float) $b, 2 ) ) < 0.001;
};
$combo['near']  = function ( $a, $b, $tolerance ) {
	return abs( (float) $a - (float) $b ) <= $tolerance + 0.000001;
};
$combo['cents'] = function ( $x ) {
	return abs( (float) $x * 100 - round( (float) $x * 100 ) ) < 0.0001;
};

// Whether a discount code may apply to a line: "Allowed Products" and "Disallowed Products".
$combo['code_applies'] = function ( $built, $code, $i ) {
	$product = $built['products'][ $i ];
	if ( null !== $code['only'] && $product !== $built['products'][ $code['only'] ] ) {
		return false;
	}
	if ( null !== $code['except'] && $product === $built['products'][ $code['except'] ] ) {
		return false;
	}
	return true;
};
$combo['applies_anywhere'] = function ( $r, $built, $code ) use ( &$combo ) {
	foreach ( array_keys( $r['lines'] ) as $i ) {
		if ( $combo['code_applies']( $built, $code, $i ) ) {
			return true;
		}
	}
	return false;
};

// The codes the cart should use, in the order they were entered. A code with nothing in the cart
// it may apply to is refused. The first "only this discount" code is used alone.
$combo['active_codes'] = function ( $r, $built ) use ( &$combo ) {
	$active = array();
	foreach ( $r['codes'] as $c => $code ) {
		if ( ! $combo['applies_anywhere']( $r, $built, $code ) ) {
			continue;
		}
		if ( $code['solo'] ) {
			return array( $c => $code );
		}
		$active[ $c ] = $code;
	}
	return $active;
};

// Where two snapshots differ, in words. Empty when they match to the cent. A fee of 0.00 is the
// same money as no fee at all.
$combo['diff'] = function ( $a, $b, $path = '' ) use ( &$combo ) {
	$out = array();
	foreach ( array_unique( array_merge( array_keys( (array) $a ), array_keys( (array) $b ) ) ) as $key ) {
		$name = ( '' === $path ) ? (string) $key : $path . '.' . $key;
		if ( 'fees' === $path ) {
			$fee_a = isset( $a[ $key ] ) ? $a[ $key ] : 0;
			$fee_b = isset( $b[ $key ] ) ? $b[ $key ] : 0;
			if ( ! $combo['eq']( $fee_a, $fee_b ) ) {
				$out[] = $name . ' ' . $combo['num']( $fee_a ) . ' then ' . $combo['num']( $fee_b );
			}
			continue;
		}
		if ( ! array_key_exists( $key, (array) $a ) || ! array_key_exists( $key, (array) $b ) ) {
			$out[] = $name . ' is only there once';
			continue;
		}
		if ( is_array( $a[ $key ] ) || is_array( $b[ $key ] ) ) {
			$inner = $combo['diff']( (array) $a[ $key ], (array) $b[ $key ], $name );
			if ( '' !== $inner ) {
				$out[] = $inner;
			}
			continue;
		}
		if ( ! $combo['eq']( $a[ $key ], $b[ $key ] ) ) {
			$out[] = $name . ' ' . $combo['num']( $a[ $key ] ) . ' then ' . $combo['num']( $b[ $key ] );
		}
	}
	return implode( ', ', $out );
};

// ---------------------------------------------------------------------------------------------
// The rules. Each gives '' when it holds, or what went wrong. When the check itself knows the
// cause, it gives cause => what went wrong instead.
// ---------------------------------------------------------------------------------------------

$combo['rules'] = array(
	'error'          => 'The cart can be worked out and the order placed',
	'whole_cents'    => 'Every amount is a whole number of cents and none is below zero',
	'total_parts'    => 'Total = subtotal - discount + tax + shipping + fees - credits',
	'never_below'    => 'The parts never add up to less than zero',
	'shelf_prices'   => 'With prices including tax, the total is the price tags minus discounts, plus shipping and fees',
	'lines'          => 'Each line is its price x quantity minus its line discount, and the lines add up to the subtotal',
	'lines_shown'    => 'The line totals shown, each rounded to the cent, add up to the subtotal',
	'line_discount'  => 'A line discount is never below zero or more than the line',
	'codes'          => 'Each discount code takes the right amount and never more than what it applies to',
	'discount_total' => 'All the discount codes together never take more than what they apply to',
	'tax_bounds'     => 'Tax is never below zero and never more than the taxed amount times the rate',
	'tax_rate'       => 'Tax is the rate on what is taxed, after discounts when the discount is before tax',
	'shipping'       => 'Shipping is what was entered, with its tax on top or inside it',
	'gift_card'      => 'A gift card pays what it can and never more than the order costs',
	'credits'        => 'Store credit pays what it can and never more than what is left to pay',
	'payment_fee'    => 'A payment fee is its fixed amount, or its percent of what is left to pay',
	'nothing_left'   => 'When gift cards and credit cover the whole order, nothing is left to pay',
	'same_request'   => 'Working the cart out again in the same request gives the same numbers',
	'next_request'   => 'Working the cart out again on the next page load gives the same numbers',
	'paypal'         => 'What PayPal is sent adds up to the cart total and has nothing below zero',
	'order_totals'   => 'The saved order has the cart totals',
	'order_lines'    => 'The saved order has the cart lines',
	'php_notices'    => 'No PHP warnings, notices or deprecations',
);

$combo['check_cart'] = function ( $r, $built, $s ) use ( &$combo ) {
	$num   = $combo['num'];
	$eq    = $combo['eq'];
	$near  = $combo['near'];
	$out   = array();
	$yes   = ( 'yes' === $r['mode'] );
	$rate  = $r['rate'] ? $r['rate'] / 100 : 0;
	$fees  = array_sum( $s['fees'] );
	$fee   = isset( $s['fees']['payment_method'] ) ? $s['fees']['payment_method'] : 0;
	$lines = $s['lines'];

	if ( count( $lines ) !== count( $r['lines'] ) ) {
		$out['lines'] = count( $r['lines'] ) . ' lines added but the cart has ' . count( $lines );
		return $out;
	}

	// What the order costs before gift cards, credit and fees.
	$cost = $yes
		? $s['subtotal'] + $s['subtotal_tax'] - $s['discount'] + $s['shipping'] + $s['shipping_tax']
		: $s['subtotal'] - $s['discount'] + $s['tax'] + $s['shipping'];

	// A line with a fraction of a cent (a percent quantity discount) can put adding-up rules off
	// by one cent. A difference of a cent or less there is put down to that cause; anything
	// bigger is left unexplained.
	$fraction = false;
	foreach ( $lines as $l ) {
		$fraction = $fraction || ! $combo['cents']( $l['total'] ) || ! $combo['cents']( $l['tax'] ) || ! $combo['cents']( $l['discount'] );
	}
	$by_cause = function ( $problem, $a, $b ) use ( $fraction ) {
		if ( '' === $problem ) {
			return '';
		}
		return ( $fraction && abs( (float) $a - (float) $b ) <= 0.0101 ) ? array( 'fraction_lines' => $problem ) : $problem;
	};

	// Whole cents, nothing below zero.
	$bad = array();
	foreach ( array( 'subtotal', 'subtotal_tax', 'discount', 'discount_tax', 'tax', 'shipping', 'shipping_tax', 'credits', 'gift', 'total' ) as $key ) {
		if ( ! $combo['cents']( $s[ $key ] ) || $s[ $key ] < 0 ) {
			$bad[] = $key . ' ' . $num( $s[ $key ] );
		}
	}
	foreach ( $s['fees'] as $id => $amount ) {
		if ( ! $combo['cents']( $amount ) || ( 'gift_card' !== $id && $amount < 0 ) ) {
			$bad[] = 'fee ' . $id . ' ' . $num( $amount );
		}
	}
	$out['whole_cents'] = implode( ', ', $bad );

	// The total is its parts.
	$parts              = $cost + $fees - $s['credits'];
	$out['total_parts'] = $by_cause( $eq( max( 0, $parts ), $s['total'] ) ? '' : 'the parts add up to ' . $num( $parts ) . ' but the total is ' . $num( $s['total'] ), max( 0, $parts ), $s['total'] );
	$out['never_below'] = ( $parts > -0.005 ) ? '' : 'the parts add up to ' . $num( $parts ) . ' (subtotal ' . $num( $yes ? $s['subtotal'] + $s['subtotal_tax'] : $s['subtotal'] ) . ', discount ' . $num( $s['discount'] ) . ', tax ' . $num( $s['tax'] ) . ', shipping ' . $num( $s['shipping'] + ( $yes ? $s['shipping_tax'] : 0 ) ) . ', fees ' . $num( $fees ) . ', credits ' . $num( $s['credits'] ) . '), so the total was pushed up to ' . $num( $s['total'] );

	// Lines.
	$problems   = array();
	$sum        = 0;
	$shown      = 0;
	$line_probs = array();
	foreach ( $r['lines'] as $i => $line ) {
		$l     = $lines[ $i ];
		$gross = $line['price'] * $line['qty'];
		$paid  = $l['total'] + ( $yes ? $l['tax'] : 0 );
		if ( $l['qty'] !== (int) $line['qty'] ) {
			$problems[] = 'L' . ( $i + 1 ) . ' quantity ' . $l['qty'] . ', added ' . $line['qty'];
		}
		if ( ! $near( $gross - $l['discount'], $paid, 0.006 ) ) {
			$problems[] = 'L' . ( $i + 1 ) . ' is ' . $num( $paid ) . ', expected ' . $num( $gross ) . ' - ' . $num( $l['discount'] ) . ' line discount';
		}
		$sum   += $paid;
		$shown += round( $l['total'], 2 );
		if ( $l['discount'] < -0.0001 || $l['discount'] > $gross + 0.006 || $l['total'] < -0.0001 || $l['tax'] < -0.0001 ) {
			$line_probs[] = 'L' . ( $i + 1 ) . ' price ' . $num( $gross ) . ', line discount ' . $num( $l['discount'] ) . ', total ' . $num( $l['total'] ) . ', tax ' . $num( $l['tax'] );
		}
	}
	$subtotal     = $yes ? $s['subtotal'] + $s['subtotal_tax'] : $s['subtotal'];
	$out['lines'] = implode( '; ', $problems );
	if ( ! $eq( round( $sum, 2 ), $subtotal ) ) {
		$sum_problem = $by_cause( 'lines add up to ' . $num( $sum ) . ' but the subtotal is ' . $num( $subtotal ), $sum, $subtotal );
		if ( '' === $out['lines'] ) {
			$out['lines'] = $sum_problem;
		} elseif ( is_array( $sum_problem ) ) {
			$out['lines'] = array(
				''               => $out['lines'],
				'fraction_lines' => $sum_problem['fraction_lines'],
			);
		} else {
			$out['lines'] .= '; ' . $sum_problem;
		}
	}
	$out['line_discount'] = implode( '; ', $line_probs );
	if ( ! $yes ) {
		$out['lines_shown'] = $by_cause( $eq( $shown, $s['subtotal'] ) ? '' : 'lines shown add up to ' . $num( $shown ) . ' but the subtotal is ' . $num( $s['subtotal'] ), $shown, $s['subtotal'] );
	}

	// Prices include tax: the customer pays the price tags.
	if ( $yes ) {
		$shelf = 0;
		foreach ( $r['lines'] as $i => $line ) {
			$shelf += $line['price'] * $line['qty'] - $lines[ $i ]['discount'];
		}
		$shipping_entered    = $r['shipping'] ? $r['shipping']['price'] : 0;
		$expected            = max( 0, round( $shelf - $s['discount'] + $shipping_entered + $fees - $s['credits'], 2 ) );
		$out['shelf_prices'] = $eq( $expected, $s['total'] ) ? '' : 'price tags ' . $num( $shelf ) . ' - discount ' . $num( $s['discount'] ) . ' + shipping ' . $num( $shipping_entered ) . ' + fees ' . $num( $fees ) . ' - credits ' . $num( $s['credits'] ) . ' = ' . $num( $expected ) . ', but the total is ' . $num( $s['total'] );
	}

	// Each discount code against what it applies to.
	$problems      = array();
	$code_sum      = 0;
	$taxed_sum     = 0;
	$ship_codes    = false;
	$active        = $combo['active_codes']( $r, $built );
	$out['_active'] = array_keys( $active );
	foreach ( $r['codes'] as $c => $code ) {
		$code_string = $built['codes'][ $c ];
		if ( ! isset( $active[ $c ] ) ) {
			if ( isset( $s['codes'][ $code_string ] ) ) {
				$problems[] = 'code ' . ( $c + 1 ) . ' is used, but ' . ( $code['except'] !== null && ! $combo['applies_anywhere']( $r, $built, $code ) ? 'every product in the cart is excluded from it' : 'an "only this discount" code is in the cart' );
			}
			continue;
		}
		if ( ! isset( $s['codes'][ $code_string ] ) ) {
			$problems[] = 'code ' . ( $c + 1 ) . ' is not in the cart';
			continue;
		}
		$amount     = $s['codes'][ $code_string ]['amount'];
		$taxed      = $s['codes'][ $code_string ]['taxed'];
		$code_sum  += $amount;
		$taxed_sum += $taxed;
		$by_product = array();
		$per_line   = 0;
		foreach ( $r['lines'] as $i => $line ) {
			if ( ! $combo['code_applies']( $built, $code, $i ) ) {
				continue;
			}
			$product = $built['products'][ $i ];
			if ( ! isset( $by_product[ $product ] ) ) {
				$by_product[ $product ] = array(
					'value' => 0,
					'qty'   => 0,
				);
			}
			// Discounts come off what the customer pays for the product: with its tax when
			// prices include tax or the discount is after tax.
			$value                            = $lines[ $i ]['total'] + ( ( $yes || $r['after'] ) ? $lines[ $i ]['tax'] : 0 );
			$by_product[ $product ]['value'] += $value;
			$by_product[ $product ]['qty']   += $lines[ $i ]['qty'];
			// Amount off each product: each item can lose at most what it costs, so an item that
			// is already free (included in a session) loses nothing.
			$per_line += min( $code['amount'] * $lines[ $i ]['qty'], $value );
		}
		$base     = 0;
		$qty      = 0;
		$per_item = 0;
		foreach ( $by_product as $product_part ) {
			$value = $product_part['value'];
			$count = $product_part['qty'];
			if ( $code['max_qty'] && $count > $code['max_qty'] ) {
				$value = $value / $count * $code['max_qty'];
				$count = $code['max_qty'];
			}
			$base     += $value;
			$qty      += $count;
			$per_item += min( $code['amount'] * $count, $value );
		}
		if ( ! $code['max_qty'] ) {
			$per_item = $per_line;
		}
		$ship    = ( 'percent-total' === $code['type'] && $code['shipping'] ) ? $s['shipping'] + $s['shipping_tax'] : 0;
		$percent = $code['amount'] / 100;
		$name    = 'code ' . ( $c + 1 ) . ' (' . $code['type'] . ' ' . $code['amount'] . ')';
		if ( $ship ) {
			$ship_codes = true;
		}
		if ( 'percent-total' === $code['type'] ) {
			// At least the percent of the products, at most the percent of products and shipping.
			if ( $amount < $percent * $base - 0.006 || $amount > $percent * ( $base + $ship ) + 0.006 ) {
				$problems[] = $name . ' took ' . $num( $amount ) . ', expected ' . $num( $percent * $base ) . ( $ship ? ' to ' . $num( $percent * ( $base + $ship ) ) : '' ) . ' (' . $code['amount'] . '% of ' . $num( $base ) . ( $ship ? ' + shipping ' . $num( $ship ) : '' ) . ')';
			}
		} elseif ( 'amount-total' === $code['type'] ) {
			if ( ! $near( min( $code['amount'], $base ), $amount, 0.006 ) ) {
				$problems[] = $name . ' took ' . $num( $amount ) . ', expected ' . $num( min( $code['amount'], $base ) ) . ' (it applies to ' . $num( $base ) . ')';
			}
		} elseif ( 'percent-product' === $code['type'] ) {
			if ( ! $near( $percent * $base, $amount, 0.006 ) ) {
				$problems[] = $name . ' took ' . $num( $amount ) . ', expected ' . $code['amount'] . '% of ' . $num( $base ) . ' = ' . $num( $percent * $base );
			}
		} elseif ( ! $near( $per_item, $amount, 0.006 ) ) {
			$problems[] = $name . ' took ' . $num( $amount ) . ', expected ' . $num( $per_item ) . ': ' . $code['amount'] . ' off each of ' . $qty . ' items, but never more than an item costs';
			if ( $amount > $per_item + 0.006 ) {
				$out['_amount_product_over'] = true;
			}
		}
		if ( $amount < -0.0001 || $taxed < -0.0001 || $taxed > $amount + 0.006 ) {
			$problems[] = $name . ' took ' . $num( $amount ) . ' of which ' . $num( $taxed ) . ' off taxed items';
		}
	}
	if ( ! $eq( $code_sum, $s['discount'] ) ) {
		$problems[] = 'codes add up to ' . $num( $code_sum ) . ' but the cart discount is ' . $num( $s['discount'] );
	}
	$out['codes'] = implode( '; ', $problems );

	$all = 0;
	foreach ( $lines as $l ) {
		$all += $l['total'] + ( ( $yes || $r['after'] ) ? $l['tax'] : 0 );
	}
	$limit                 = $all + ( $ship_codes ? $s['shipping'] + $s['shipping_tax'] : 0 );
	$out['discount_total'] = ( $s['discount'] <= $limit + 0.006 ) ? '' : 'discount codes took ' . $num( $s['discount'] ) . ' but there is only ' . $num( $limit ) . ' they apply to (products ' . $num( $all ) . ( $ship_codes ? ' + shipping ' . $num( $s['shipping'] + $s['shipping_tax'] ) : '' ) . ')';

	// Tax.
	$ship_taxed = $r['shipping'] && $r['shipping']['taxable'];
	if ( ! $rate ) {
		$line_tax = 0;
		foreach ( $lines as $l ) {
			$line_tax += $l['tax'];
		}
		$out['tax_bounds'] = ( $eq( 0, $s['tax'] ) && $eq( 0, $line_tax ) && $eq( 0, $s['shipping_tax'] ) ) ? '' : 'taxes are off but tax is ' . $num( $s['tax'] ) . ', line tax ' . $num( $line_tax ) . ', shipping tax ' . $num( $s['shipping_tax'] );
	} else {
		$gross     = 0;
		$net       = 0;
		$taxed_qty = 0;
		$taxed_n   = 0;
		foreach ( $r['lines'] as $i => $line ) {
			if ( ! $line['taxable'] ) {
				continue;
			}
			$gross     += $line['price'] * $line['qty'];
			$net       += $lines[ $i ]['total'] + ( $yes ? $lines[ $i ]['tax'] : 0 );
			$taxed_qty += $line['qty'];
			++$taxed_n;
		}
		if ( $yes ) {
			// Prices hold their tax: the tax is rate / (1 + rate) of what is paid. Each item's tax
			// is taken out of its own price, so allow half a cent for every taxed item.
			$part      = $rate / ( 1 + $rate );
			$gross    += $ship_taxed ? $r['shipping']['price'] : 0;
			$expected  = ( $r['after'] ? $part * $net : $part * max( 0, $net - $taxed_sum ) ) + $s['shipping_tax'];
			$tolerance = 0.005 * ( $taxed_qty + 2 );
			$most      = $part * $gross;
			$what      = 'taxed items ' . $num( $net ) . ( $r['after'] ? '' : ' - taxed part of the discount ' . $num( $taxed_sum ) ) . ' hold ' . $num( $expected - $s['shipping_tax'] ) . ' tax, + shipping tax ' . $num( $s['shipping_tax'] );
		} else {
			// Each line's tax is rounded on its own, so allow half a cent a line.
			$gross    += $ship_taxed ? $s['shipping'] : 0;
			$net      += $ship_taxed ? $s['shipping'] : 0;
			$expected  = $r['after'] ? $rate * $net : $rate * max( 0, $net - $taxed_sum );
			$tolerance = 0.005 * ( $taxed_n + 2 );
			$most      = $rate * $gross;
			$what      = $r['rate'] . '% of taxed ' . $num( $net ) . ( $r['after'] ? '' : ' - taxed part of the discount ' . $num( $taxed_sum ) ) . ' = ' . $num( $expected );
		}
		$out['tax_bounds'] = ( $s['tax'] >= 0 && $s['tax'] <= $most + max( 0.01, $tolerance ) ) ? '' : 'tax ' . $num( $s['tax'] ) . ' is outside 0 to ' . $num( $most ) . ' (the rate on everything taxed, before any discount)';
		$out['tax_rate']   = $near( $expected, $s['tax'], $tolerance ) ? '' : 'tax ' . $num( $s['tax'] ) . ', expected about ' . $num( $expected ) . ': ' . $what;
	}

	// Shipping.
	if ( ! $r['shipping'] ) {
		$out['shipping'] = ( $eq( 0, $s['shipping'] ) && $eq( 0, $s['shipping_tax'] ) ) ? '' : 'no shipping chosen but shipping is ' . $num( $s['shipping'] ) . ' + tax ' . $num( $s['shipping_tax'] );
	} else {
		$entered = $r['shipping']['price'];
		$taxed   = $ship_taxed && $rate;
		if ( $yes ) {
			$ok              = $eq( $entered, $s['shipping'] + $s['shipping_tax'] ) && ( $taxed || $eq( 0, $s['shipping_tax'] ) );
			$out['shipping'] = $ok ? '' : 'shipping ' . $num( $s['shipping'] ) . ' + tax ' . $num( $s['shipping_tax'] ) . ' = ' . $num( $s['shipping'] + $s['shipping_tax'] ) . ', but ' . $num( $entered ) . ' was entered (tax included)';
		} else {
			$tax_expected    = $taxed ? round( $entered * $rate, 2 ) : 0;
			$ok              = $eq( $entered, $s['shipping'] ) && $eq( $tax_expected, $s['shipping_tax'] );
			$out['shipping'] = $ok ? '' : 'shipping ' . $num( $s['shipping'] ) . ' + tax ' . $num( $s['shipping_tax'] ) . ', expected ' . $num( $entered ) . ' + tax ' . $num( $tax_expected );
		}
	}

	// Gift card: as much of the balance as the order costs, never more.
	$gift_fee     = isset( $s['fees']['gift_card'] ) ? $s['fees']['gift_card'] : 0;
	$gift_balance = array_sum( $r['gift'] );
	if ( $r['gift'] ) {
		$expected         = min( $gift_balance, max( 0, $cost ) );
		$ok               = $eq( $expected, $s['gift'] ) && $eq( -$s['gift'], $gift_fee ) && $s['gift'] <= $gift_balance + 0.001;
		$out['gift_card'] = $ok ? '' : 'gift cards took ' . $num( $s['gift'] ) . ' (fee ' . $num( $gift_fee ) . '), expected ' . $num( $expected ) . ': the smaller of their balance ' . $num( $gift_balance ) . ' and the order cost ' . $num( $cost );
		if ( $eq( -$s['gift'], $gift_fee ) ) {
			$out['gift_card'] = $by_cause( $out['gift_card'], $expected, $s['gift'] );
		}
	} else {
		$out['gift_card'] = ( $eq( 0, $s['gift'] ) && $eq( 0, $gift_fee ) ) ? '' : 'no gift card but ' . $num( $s['gift'] ) . ' was taken';
	}

	// Store credit: as much as is left after the gift card, payment fee included.
	if ( $r['credits'] ) {
		$left           = max( 0, $cost - $s['gift'] + $fee );
		$expected       = min( $r['credits'], $left );
		$out['credits'] = $by_cause( $near( $expected, $s['credits'], 0.006 ) ? '' : 'credit used ' . $num( $s['credits'] ) . ', expected ' . $num( $expected ) . ': the smaller of the balance ' . $r['credits'] . ' and what is left ' . $num( $left ), $expected, $s['credits'] );
	} else {
		$out['credits'] = $eq( 0, $s['credits'] ) ? '' : 'no credit but ' . $num( $s['credits'] ) . ' was used';
	}

	// Payment fee.
	if ( $r['fee'] ) {
		if ( ! isset( $s['fees']['payment_method'] ) ) {
			$out['payment_fee'] = 'no payment fee in the cart';
		} elseif ( 'amount' === $r['fee']['type'] ) {
			$out['payment_fee'] = $eq( $r['fee']['amount'], $fee ) ? '' : 'fee ' . $num( $fee ) . ', expected the fixed ' . $num( $r['fee']['amount'] );
		} else {
			$left               = max( 0, $s['total'] - $fee );
			$expected           = round( $r['fee']['amount'] / 100 * $left, 2 );
			$out['payment_fee'] = $near( $expected, $fee, 0.006 ) ? '' : 'fee ' . $num( $fee ) . ', expected ' . $r['fee']['amount'] . '% of what is left to pay ' . $num( $left ) . ' = ' . $num( $expected );
		}
	} else {
		$out['payment_fee'] = isset( $s['fees']['payment_method'] ) ? 'no fee set up but ' . $num( $fee ) . ' was added' : '';
	}

	// Gift card balance and credit that cover everything leave nothing to pay.
	$cover = $gift_balance + $r['credits'];
	if ( $cover ) {
		$fixed               = ( $r['fee'] && 'amount' === $r['fee']['type'] ) ? $r['fee']['amount'] : 0;
		$covers_all          = $cover >= max( 0, $cost ) + $fixed - 0.0001;
		$out['nothing_left'] = ( ! $covers_all || $eq( 0, $s['total'] ) ) ? '' : 'gift cards ' . $num( $gift_balance ) . ' + credit ' . $num( $r['credits'] ) . ' cover the order ' . $num( $cost ) . ( $fixed ? ' + fixed fee ' . $num( $fixed ) : '' ) . ', but ' . $num( $s['total'] ) . ' is left to pay (gift card took ' . $num( $s['gift'] ) . ', credit ' . $num( $s['credits'] ) . ')';
	}

	return $out;
};

// ---------------------------------------------------------------------------------------------
// PayPal: build the order PayPal would be sent, without sending it.
// ---------------------------------------------------------------------------------------------

$combo['paypal_args'] = function () {
	$paypal = sunshine_get_payment_method_by_id( 'paypal' );
	if ( ! $paypal ) {
		throw new Exception( 'PayPal payment method not available' );
	}
	$sent       = null;
	$capture    = function ( $args ) use ( &$sent ) {
		$sent = $args;
		return $args;
	};
	$block_http = function () {
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	$die        = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'sunshine_paypal_order_args', $capture, 999 );
	add_filter( 'pre_http_request', $block_http, PHP_INT_MAX );
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $die );
	$_REQUEST['security'] = wp_create_nonce( 'sunshine_checkout_paypal_create_order' );
	ob_start();
	try {
		$paypal->create_order();
	} catch ( SPC_Totals_Test_Done $e ) {
		// create_order() always ends by sending a JSON response.
	} finally {
		ob_end_clean();
		unset( $_REQUEST['security'] );
		remove_filter( 'sunshine_paypal_order_args', $capture, 999 );
		remove_filter( 'pre_http_request', $block_http, PHP_INT_MAX );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $die );
		SPC()->session->set( 'paypal_order_id', '' );
	}
	if ( empty( $sent ) ) {
		throw new Exception( 'No PayPal order was built' );
	}
	return $sent;
};

// PayPal's own rules: amount = item_total + tax_total + shipping - discount, item_total is the
// items added up, nothing below zero, at most 2 decimals. And the amount is the cart total.
$combo['check_paypal'] = function ( $args, $s ) use ( &$combo ) {
	$num       = $combo['num'];
	$problems  = array();
	$unit      = $args['purchase_units'][0];
	$breakdown = $unit['amount']['breakdown'];
	$values    = array( 'amount' => $unit['amount']['value'] );
	$items_sum = 0;
	foreach ( $unit['items'] as $i => $item ) {
		$values[ 'item ' . ( $i + 1 ) ] = $item['unit_amount']['value'];
		$items_sum                     += (float) $item['unit_amount']['value'] * (int) $item['quantity'];
	}
	foreach ( $breakdown as $key => $part ) {
		$values[ $key ] = $part['value'];
	}
	foreach ( $values as $key => $value ) {
		if ( (float) $value < 0 ) {
			$problems[] = $key . ' is below zero (' . $value . ')';
		}
		if ( ! preg_match( '/^\d+(\.\d{1,2})?$/', (string) $value ) ) {
			$problems[] = $key . ' is not a plain amount with at most 2 decimals (' . $value . ')';
		}
	}
	if ( ! $combo['eq']( $items_sum, $breakdown['item_total']['value'] ) ) {
		$problems[] = 'items add up to ' . $num( $items_sum ) . ' but item_total is ' . $breakdown['item_total']['value'];
	}
	$sum = (float) $breakdown['item_total']['value'] + (float) $breakdown['tax_total']['value'] + (float) $breakdown['shipping']['value'] - (float) $breakdown['discount']['value'];
	if ( ! $combo['eq']( $sum, $unit['amount']['value'] ) ) {
		$problems[] = 'item_total ' . $breakdown['item_total']['value'] . ' + tax ' . $breakdown['tax_total']['value'] . ' + shipping ' . $breakdown['shipping']['value'] . ' - discount ' . $breakdown['discount']['value'] . ' = ' . $num( $sum ) . ' but the amount is ' . $unit['amount']['value'];
	}
	if ( ! $combo['eq']( $s['total'], $unit['amount']['value'] ) ) {
		$problems[] = 'PayPal amount ' . $unit['amount']['value'] . ' but the cart total is ' . $num( $s['total'] );
	}
	return implode( '; ', $problems );
};

// ---------------------------------------------------------------------------------------------
// The saved order against the cart the customer saw.
// ---------------------------------------------------------------------------------------------

$combo['check_order'] = function ( $r, $order, $s ) use ( &$combo ) {
	$num    = $combo['num'];
	$totals = array();
	$saved  = array(
		'subtotal'     => $order->get_subtotal(),
		'subtotal_tax' => $order->get_subtotal_tax(),
		'discount'     => $order->get_discount(),
		'discount_tax' => $order->get_discount_tax(),
		'tax'          => $order->get_tax(),
		'shipping'     => $order->get_shipping(),
		'shipping_tax' => $order->get_shipping_tax(),
		'credits'      => $order->get_credits(),
		'total'        => $order->get_total(),
	);
	foreach ( $saved as $key => $value ) {
		if ( ! $combo['eq']( $s[ $key ], $value ) ) {
			$totals[] = $key . ' ' . $num( $value ) . ', cart ' . $num( $s[ $key ] );
		}
	}
	$order_fees = array();
	foreach ( (array) $order->get_fees() as $id => $fee ) {
		$order_fees[ $id ] = (float) $fee['amount'];
	}
	foreach ( array_unique( array_merge( array_keys( $order_fees ), array_keys( $s['fees'] ) ) ) as $id ) {
		$a = isset( $order_fees[ $id ] ) ? $order_fees[ $id ] : 0;
		$b = isset( $s['fees'][ $id ] ) ? $s['fees'][ $id ] : 0;
		if ( ! $combo['eq']( $a, $b ) ) {
			$totals[] = 'fee ' . $id . ' ' . $num( $a ) . ', cart ' . $num( $b );
		}
	}

	// Each line difference is put down to its cause, so a new problem is not hidden among known
	// ones. Cause '' means nothing explains it.
	$lines = array();
	$items = array_values( (array) $order->get_items() );
	if ( count( $items ) !== count( $s['lines'] ) ) {
		$lines[''][] = count( $items ) . ' order lines, cart has ' . count( $s['lines'] );
	}
	foreach ( $s['lines'] as $i => $l ) {
		if ( empty( $items[ $i ] ) ) {
			continue;
		}
		$item  = $items[ $i ];
		$saved = array(
			'qty'      => (float) $item->get_qty(),
			'total'    => (float) $item->get_total(),
			'tax'      => (float) $item->get_tax_total(),
			'discount' => (float) $item->get_discount_total(),
		);
		$wrong = array();
		foreach ( $saved as $key => $value ) {
			if ( ! $combo['eq']( $l[ $key ], $value ) ) {
				$wrong[ $key ] = 'L' . ( $i + 1 ) . ' ' . $key . ' ' . $num( $value ) . ', cart ' . $num( $l[ $key ] );
			}
		}
		if ( ! $wrong ) {
			continue;
		}
		$cause = '';
		if ( isset( $wrong['qty'] ) && $l['qty'] > 255 && 255 === (int) $saved['qty'] ) {
			// The quantity column holds at most 255. Its total and tax follow from that.
			$cause = 'qty_255';
		} elseif ( 'yes' === $r['mode'] && $l['discount'] > 0 && ! isset( $wrong['qty'] ) && ! isset( $wrong['discount'] ) ) {
			// Prices include tax and the line has a line discount: the order line keeps the tax
			// of the full price and takes the whole discount off the price before tax.
			$cause = 'yes_line_split';
		} elseif ( 'no' === $r['mode'] && array_keys( $wrong ) === array( 'tax' ) && $l['qty'] > 0 && $combo['eq']( round( $l['tax'] / $l['qty'], 2 ) * $l['qty'], $saved['tax'] ) ) {
			$cause = 'per_print';
		}
		$lines[ $cause ][] = implode( '; ', $wrong );
	}
	foreach ( $lines as $cause => $list ) {
		$lines[ $cause ] = implode( '; ', $list );
	}
	return array( implode( '; ', $totals ), $lines );
};

// ---------------------------------------------------------------------------------------------
// One cart from start to finish. Returns rule => '' or what went wrong. A rule that does not
// apply to this cart is left out.
// ---------------------------------------------------------------------------------------------

$combo['evaluate'] = function ( $r ) use ( $t, &$combo ) {
	$results  = array();
	$notices  = array();
	$previous = null;
	$previous = set_error_handler(
		function ( $errno, $errstr, $errfile, $errline ) use ( &$notices, &$previous ) {
			if ( error_reporting() & $errno ) {
				$notices[] = $errstr . ' (' . basename( $errfile ) . ':' . $errline . ')';
			}
			return $previous ? call_user_func( $previous, $errno, $errstr, $errfile, $errline ) : false;
		}
	);
	try {
		$combo['without_new_filters'](
			function () use ( $t, $r, &$combo, &$results ) {
				$built = $combo['build']( $r );
				$t::calculate();
				$first = $combo['snapshot']();
				SPC()->cart->setup( true );
				$again = $combo['snapshot']();
				$t::new_request();
				$next = $combo['snapshot']();

				if ( $combo['debug_on'] ) {
					WP_CLI::log( 'seed ' . $r['seed'] . ' first: ' . wp_json_encode( $first ) );
					WP_CLI::log( 'seed ' . $r['seed'] . ' again: ' . wp_json_encode( $again ) );
					WP_CLI::log( 'seed ' . $r['seed'] . ' next:  ' . wp_json_encode( $next ) );
				}

				$results                 = $combo['check_cart']( $r, $built, $first );
				$results['same_request'] = $combo['diff']( $first, $again );
				$results['next_request'] = $combo['diff']( $first, $next );
				$results['_first']       = $first;

				// Then the customer picks the payment method and the checkout shows the cart again.
				// With nothing to pay the checkout hides the payment methods and has saved "free",
				// so PayPal is never offered and the order goes in with what the checkout saved.
				$results['_saved_method'] = (string) SPC()->cart->get_checkout_data_item( 'payment_method' );
				$nothing_to_pay           = $combo['eq']( 0, $next['total'] );
				$method                   = $nothing_to_pay ? $results['_saved_method'] : $r['gateway'];
				$method                   = ( '' !== $method ) ? $method : 'free';
				SPC()->cart->set_checkout_data_item( 'payment_method', $method );
				$t::new_request();
				$seen = $combo['snapshot']();

				$args = null;
				if ( 'paypal' === $method ) {
					$args              = $combo['paypal_args']();
					$results['paypal'] = $combo['check_paypal']( $args, $seen );
				}
				if ( $r['place'] ) {
					$order = $t::place_order( $method );
					list( $results['order_totals'], $results['order_lines'] ) = $combo['check_order']( $r, $order, $seen );
					if ( $args && ! $combo['eq']( $order->get_total(), $args['purchase_units'][0]['amount']['value'] ) ) {
						$results['paypal'] = trim( $results['paypal'] . '; PayPal amount ' . $args['purchase_units'][0]['amount']['value'] . ' but the placed order total is ' . $combo['num']( $order->get_total() ), '; ' );
					}
				}
			}
		);
		$results['error'] = '';
	} catch ( Throwable $e ) {
		$results['error'] = get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
	} finally {
		restore_error_handler();
	}
	$results['php_notices'] = implode( '; ', array_unique( $notices ) );
	return $results;
};

// ---------------------------------------------------------------------------------------------
// Causes. A rule that breaks is still a failure, but it is labelled with what causes it: a
// problem other tests already found ("already known"), or one these combinations found and
// shrank to a small cart of its own (tested by hand at the end of this file). A failure with no
// label is something new.
// ---------------------------------------------------------------------------------------------

$combo['causes'] = array(
	'per_print'      => array( 'known', 'Saved order lines round tax per print, so a line\'s tax can be off by a few cents' ),
	'flat_type'      => array( 'known', 'Flat rate with a saved Price Type charges the wrong shipping when prices include tax' ),
	'amount_product' => array( 'known', 'Amount off each product can take more than the product costs, and the extra comes off other items' ),
	'stacked_codes'  => array( 'found', 'Two discount codes together can take off more than the products cost' ),
	'qty_255'        => array( 'found', 'An order line with more than 255 of one item is saved as 255' ),
	'yes_line_split' => array( 'found', 'Prices include tax: a saved order line with a line discount keeps the tax of the full price' ),
	'fixed_fee_gift' => array( 'found', 'A fixed payment fee is still charged when a gift card pays for the whole order' ),
	'zero_fee'       => array( 'found', 'Once the total reaches zero the checkout saves the Free payment method and keeps it, so the payment fee comes and goes between page loads' ),
	'fraction_lines' => array( 'found', 'Percent quantity discounts leave fractions of a cent on lines, so the lines, subtotal and total can be a cent apart' ),
	'ship_tax_floor' => array( 'found', 'A discount that includes taxed shipping can\'t bring the tax below the shipping\'s full tax' ),
);

// The discount codes in use took more than everything they apply to: all of one product, or
// more than all the products (and shipping, when a code includes it).
$combo['over_discount'] = function ( $r, $s, $active ) {
	$yes  = ( 'yes' === $r['mode'] );
	$all  = 0;
	$ship = false;
	foreach ( $s['lines'] as $l ) {
		$all += $l['total'] + ( ( $yes || $r['after'] ) ? $l['tax'] : 0 );
	}
	foreach ( $active as $c ) {
		$ship = $ship || ( 'percent-total' === $r['codes'][ $c ]['type'] && $r['codes'][ $c ]['shipping'] );
	}
	return $s['discount'] > $all + ( $ship ? $s['shipping'] + $s['shipping_tax'] : 0 ) + 0.006;
};

// The taxed items, the part of the discount the cart takes off taxed things (shipping included
// when a code includes taxed shipping), and that part without the shipping. When the part
// without shipping is bigger than the taxed items, some product got more than 100% off.
$combo['taxed_share_over'] = function ( $r, $s, $active ) {
	$yes      = ( 'yes' === $r['mode'] );
	$items    = 0;
	$share    = 0;
	$shipping = 0;
	foreach ( $r['lines'] as $i => $line ) {
		if ( $line['taxable'] ) {
			$items += $s['lines'][ $i ]['total'] + ( $yes ? $s['lines'][ $i ]['tax'] : 0 );
		}
	}
	foreach ( $s['codes'] as $code ) {
		$share += $code['taxed'];
	}
	if ( $r['shipping'] && $r['shipping']['taxable'] ) {
		foreach ( $active as $c ) {
			if ( 'percent-total' === $r['codes'][ $c ]['type'] && $r['codes'][ $c ]['shipping'] ) {
				$shipping += $r['codes'][ $c ]['amount'] / 100 * $s['shipping'];
			}
		}
	}
	return array( $items, $share, $share - $shipping );
};

$combo['cause'] = function ( $r, $rule, $problem, $results ) use ( &$combo ) {
	$s      = isset( $results['_first'] ) ? $results['_first'] : array();
	$active = isset( $results['_active'] ) ? $results['_active'] : array_keys( $r['codes'] );
	$saved  = isset( $results['_saved_method'] ) ? $results['_saved_method'] : '';
	$yes    = ( 'yes' === $r['mode'] );
	$fixed  = $r['fee'] && 'amount' === $r['fee']['type'];
	if ( 'php_notices' === $rule || 'error' === $rule || empty( $s ) ) {
		return '';
	}
	if ( in_array( $rule, array( 'shipping', 'shelf_prices' ), true ) && $yes && $r['rate'] && $r['shipping'] && $r['shipping']['taxable'] && 'cart' === $r['shipping']['type'] ) {
		return 'flat_type';
	}
	$over = $combo['over_discount']( $r, $s, $active );
	if ( ! empty( $results['_amount_product_over'] ) && ( $over || 'codes' === $rule ) ) {
		return 'amount_product';
	}
	list( $taxed_items, $taxed_share, $items_share ) = $combo['taxed_share_over']( $r, $s, $active );
	$tax_rule = in_array( $rule, array( 'tax_rate', 'tax_bounds' ), true );
	if ( count( $active ) > 1 && ( $over || ( $tax_rule && $items_share > $taxed_items + 0.006 ) ) ) {
		return 'stacked_codes';
	}
	if ( $tax_rule && $r['rate'] && ! $r['after'] && $taxed_share > $taxed_items + 0.006 && $items_share <= $taxed_items + 0.006 ) {
		return 'ship_tax_floor';
	}
	if ( 'codes' === $rule ) {
		$only_amount_product = true;
		foreach ( explode( '; ', $problem ) as $part ) {
			$only_amount_product = $only_amount_product && ( false !== strpos( $part, 'amount-product' ) );
		}
		if ( $only_amount_product ) {
			return 'amount_product';
		}
	}
	if ( 'nothing_left' === $rule && $r['gift'] && $fixed ) {
		return 'fixed_fee_gift';
	}
	// The checkout switched to the Free payment method at some point, and only the payment fee
	// and what follows from it (credit used, total) changed.
	if ( in_array( $rule, array( 'same_request', 'next_request', 'order_totals' ), true ) && $r['fee'] && 'free' === $saved
		&& ! preg_replace( '/(fees\.payment_method|fee payment_method|credits|total)[^,;]*[,;]?\s*/', '', $problem ) ) {
		return 'zero_fee';
	}
	return '';
};

$combo['tag'] = function ( $cause ) use ( &$combo ) {
	if ( '' === $cause || empty( $combo['causes'][ $cause ] ) ) {
		return '';
	}
	list( $kind, $title ) = $combo['causes'][ $cause ];
	return ( 'known' === $kind ? '[already known: ' : '[found here: ' ) . $title . '] ';
};

// ---------------------------------------------------------------------------------------------
// Shrinking: take ingredients away one at a time and keep each change that still breaks the
// same rule, to find the smallest cart that shows the problem.
// ---------------------------------------------------------------------------------------------

$combo['variants'] = function ( $r ) {
	$out = array();
	foreach ( $r['codes'] as $c => $code ) {
		$v = $r;
		array_splice( $v['codes'], $c, 1 );
		$out[] = $v;
		foreach ( array( 'max_qty' => null, 'shipping' => false, 'only' => null, 'except' => null, 'solo' => false ) as $key => $plain ) {
			if ( $plain !== $code[ $key ] ) {
				$v                       = $r;
				$v['codes'][ $c ][ $key ] = $plain;
				$out[]                   = $v;
			}
		}
	}
	if ( count( $r['gift'] ) > 1 ) {
		$v = $r;
		array_pop( $v['gift'] );
		$out[] = $v;
	}
	foreach ( array( 'gift' => array(), 'credits' => 0, 'fee' => null, 'shipping' => null, 'session' => null, 'gateway' => 'test', 'place' => false, 'after' => false, 'mode' => 'no' ) as $key => $plain ) {
		if ( $plain !== $r[ $key ] ) {
			$v         = $r;
			$v[ $key ] = $plain;
			$out[]     = $v;
		}
	}
	if ( $r['rate'] && 10 != $r['rate'] ) {
		$v         = $r;
		$v['rate'] = 10;
		$out[]     = $v;
	}
	foreach ( $r['lines'] as $i => $line ) {
		if ( count( $r['lines'] ) > 1 ) {
			$v = $r;
			foreach ( $v['lines'] as $j => $other ) {
				if ( $other['same_as'] === $i ) {
					$v['lines'][ $j ]['same_as'] = null;
				} elseif ( null !== $other['same_as'] && $other['same_as'] > $i ) {
					--$v['lines'][ $j ]['same_as'];
				}
			}
			array_splice( $v['lines'], $i, 1 );
			if ( $v['session'] ) {
				if ( $v['session']['line'] === $i ) {
					$v['session'] = null;
				} elseif ( $v['session']['line'] > $i ) {
					--$v['session']['line'];
				}
			}
			foreach ( $v['codes'] as $c => $code ) {
				foreach ( array( 'only', 'except' ) as $key ) {
					if ( $code[ $key ] === $i ) {
						$v['codes'][ $c ][ $key ] = null;
					} elseif ( null !== $code[ $key ] && $code[ $key ] > $i ) {
						--$v['codes'][ $c ][ $key ];
					}
				}
			}
			$out[] = $v;
		}
		if ( null !== $line['same_as'] ) {
			$v                            = $r;
			$v['lines'][ $i ]['same_as'] = null;
			$out[]                        = $v;
		}
		if ( $line['tier'] && null === $line['same_as'] ) {
			$v = $r;
			foreach ( $v['lines'] as $j => $other ) {
				if ( $j === $i || $other['same_as'] === $i ) {
					$v['lines'][ $j ]['tier'] = null;
				}
			}
			$out[] = $v;
		}
		if ( 1 !== $line['qty'] ) {
			$v                        = $r;
			$v['lines'][ $i ]['qty'] = 1;
			$out[]                    = $v;
		}
		if ( 10 != $line['price'] && null === $line['same_as'] ) {
			$v = $r;
			foreach ( $v['lines'] as $j => $other ) {
				if ( $j === $i || $other['same_as'] === $i ) {
					$v['lines'][ $j ]['price'] = 10;
				}
			}
			$out[] = $v;
		}
	}
	return $out;
};

// Whether a cart still breaks a rule for the same cause.
$combo['breaks'] = function ( $r, $results, $rule, $cause ) use ( &$combo ) {
	if ( empty( $results[ $rule ] ) ) {
		return false;
	}
	if ( is_array( $results[ $rule ] ) ) {
		return isset( $results[ $rule ][ $cause ] );
	}
	return $combo['cause']( $r, $rule, (string) $results[ $rule ], $results ) === $cause;
};

$combo['shrink'] = function ( $r, $rule, $cause ) use ( $t, &$combo ) {
	$tries   = 0;
	$changed = true;
	while ( $changed && $tries < 120 ) {
		$changed = false;
		foreach ( $combo['variants']( $r ) as $candidate ) {
			if ( ++$tries > 120 ) {
				break;
			}
			$t::reset();
			$results = $combo['evaluate']( $candidate );
			if ( $combo['breaks']( $candidate, $results, $rule, $cause ) ) {
				$r       = $candidate;
				$changed = true;
				break;
			}
		}
	}
	$t::reset();
	return $r;
};

// ---------------------------------------------------------------------------------------------
// Run every seed.
// ---------------------------------------------------------------------------------------------

$combo['tally'] = array();
$combo['mix']   = array();
foreach ( $combo['seeds'] as $combo_seed ) {
	$combo_recipe = $combo['recipe']( $combo_seed );
	// How many carts had each ingredient, printed at the end.
	foreach (
		array(
			'orders placed'      => $combo_recipe['place'],
			'PayPal'             => 'paypal' === $combo_recipe['gateway'],
			'prices include tax' => 'yes' === $combo_recipe['mode'],
			'discount after tax' => $combo_recipe['after'],
			'taxes on'           => (bool) $combo_recipe['rate'],
			'discount codes'     => (bool) $combo_recipe['codes'],
			'two or more codes'  => count( $combo_recipe['codes'] ) > 1,
			'gift cards'         => (bool) $combo_recipe['gift'],
			'store credit'       => (bool) $combo_recipe['credits'],
			'payment fee'        => (bool) $combo_recipe['fee'],
			'shipping'           => (bool) $combo_recipe['shipping'],
			'session products'   => (bool) $combo_recipe['session'],
			'quantity discounts' => (bool) array_filter( wp_list_pluck( $combo_recipe['lines'], 'tier' ) ),
		) as $combo_label => $combo_has
	) {
		$combo['mix'][ $combo_label ] = ( isset( $combo['mix'][ $combo_label ] ) ? $combo['mix'][ $combo_label ] : 0 ) + ( $combo_has ? 1 : 0 );
	}
	$t::test(
		'Combo seed ' . $combo_seed . ' (' . $combo['describe']( $combo_recipe ) . ')',
		function () use ( $t, $combo_recipe, &$combo ) {
			$results = $combo['evaluate']( $combo_recipe );
			foreach ( $combo['rules'] as $rule => $name ) {
				if ( ! array_key_exists( $rule, $results ) ) {
					continue;
				}
				// A rule gives one problem, or (order lines) one problem for each cause.
				$problems = $results[ $rule ];
				if ( ! is_array( $problems ) ) {
					$problems = ( '' === (string) $problems ) ? array() : array( $combo['cause']( $combo_recipe, $rule, (string) $problems, $results ) => (string) $problems );
				}
				if ( ! $problems ) {
					$t::same( 'ok', 'ok', $name );
					continue;
				}
				foreach ( $problems as $cause => $problem ) {
					$t::same( 'ok', $problem, $combo['tag']( $cause ) . $name );
					$combo['tally'][ $rule ][ (string) $cause ][] = $combo_recipe['seed'];
					if ( $combo['shrink_on'] && ( '' === $cause || 'found' === $combo['causes'][ $cause ][0] ) && 'php_notices' !== $rule ) {
						$smallest = $combo['shrink']( $combo_recipe, $rule, $cause );
						$t::reset();
						$again = $combo['evaluate']( $smallest );
						$shown = isset( $again[ $rule ] ) ? $again[ $rule ] : '';
						WP_CLI::log( 'SMALLEST seed ' . $combo_recipe['seed'] . ' [' . $name . ']: ' . $combo['describe']( $smallest ) . ' => ' . ( is_array( $shown ) ? wp_json_encode( $shown ) : $shown ) );
					}
				}
			}
		}
	);
}

$combo_mix = array();
foreach ( $combo['mix'] as $combo_label => $combo_count ) {
	$combo_mix[] = $combo_label . ' ' . $combo_count;
}
WP_CLI::log( 'combo-adjusters: ' . count( $combo['seeds'] ) . ' carts. With ' . implode( ', ', $combo_mix ) . '.' );

// Which rules broke for which seeds, so a long run can be read at a glance.
foreach ( $combo['tally'] as $combo_rule => $combo_counts ) {
	foreach ( $combo_counts as $combo_cause => $combo_list ) {
		WP_CLI::log( 'combo-adjusters: ' . $combo['tag']( $combo_cause ) . $combo['rules'][ $combo_rule ] . ' broke for ' . count( $combo_list ) . ' seeds: ' . implode( ',', $combo_list ) );
	}
}

// ---------------------------------------------------------------------------------------------
// The smallest carts the combinations shrank each new problem to, worked out by hand. These fail
// until the problem is fixed.
// ---------------------------------------------------------------------------------------------

$combo['hand_test'] = function ( $name, $callback ) use ( $t, &$combo ) {
	if ( $combo['hand_on'] ) {
		$t::test( $name, $callback );
	}
};

// The Test payment method with a fee, chosen at checkout.
$combo['use_fee'] = function ( $type, $amount ) use ( $t ) {
	$t::option( 'test_fee', $type );
	$t::option( 'test_fee_amount', (string) $amount );
	$t::option( 'test_fee_name', 'Card fee' );
	SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
};

$combo['use_credit'] = function ( $amount ) use ( $t ) {
	$customer_id = $t::customer();
	update_user_meta( $customer_id, 'sunshine_credits', $amount );
	SPC()->customer = new SPC_Customer( $customer_id );
	SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
	return $customer_id;
};

$combo['fee_amount'] = function ( $cart ) {
	$fees = $cart->get_fees();
	return isset( $fees['payment_method'] ) ? (float) $fees['payment_method']['amount'] : 0;
};

$combo['qty_rule'] = function ( $qty, $discount, $type, $per_line = false ) {
	$rule = array(
		'items' => array(
			array(
				'qty'      => (string) $qty,
				'discount' => (string) $discount,
				'type'     => $type,
			),
		),
	);
	if ( $per_line ) {
		$rule['calc'] = '1';
	}
	return $rule;
};

$combo['hand_test'](
	'Combo, smallest cart: two discount codes together never take more than the products cost',
	function () use ( $t, &$combo ) {
		$combo['without_new_filters'](
			function () use ( $t ) {
				$t::tax( 10 );
				$t::add( $t::product( 50 ) );
				$t::flat_shipping( 10, false );
				$t::discount( 'amount-total', 40 );
				$t::discount( 'percent-total', 25 );
				$cart = $t::calculate();
				// 40 off, plus 25% of 50 = 12.50 off, makes 52.50, but there is only 50 of products
				// to take it from. The codes take 50 at most, no product is left to tax, and the
				// customer still pays the 10 shipping.
				$t::money( 50, $cart->get_discount(), 'discount is capped at the products' );
				$t::money( 0, $cart->get_tax(), 'tax' );
				$t::money( 10, $cart->get_total(), 'total is the shipping' );
			}
		);
	}
);

$combo['hand_test'](
	'Combo, smallest cart: an order with 300 of one print keeps all 300 on the order line',
	function () use ( $t ) {
		$t::add( $t::product( 0.20, array( 'taxable' => false ) ), 300 );
		$t::calculate();
		$order = $t::place_order();
		$items = array_values( (array) $order->get_items() );
		$t::same( 300, isset( $items[0] ) ? (int) $items[0]->get_qty() : 0, 'quantity on the order line' );
		// 300 x 0.20 = 60.
		$t::money( 60, isset( $items[0] ) ? $items[0]->get_total() : 0, 'order line total' );
		$t::money( 60, $order->get_total(), 'order total' );
	}
);

$combo['hand_test'](
	'Combo, smallest cart: prices include tax, an order line with a quantity discount has the tax the cart showed',
	function () use ( $t, &$combo ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 11, array( 'qty_discount' => $combo['qty_rule']( 5, 1, 'amount' ) ) ), 5 );
		$cart  = $t::calculate();
		$lines = array_values( (array) $cart->get_cart_items() );
		// 5 x 11 = 55, 1 off each = 50 paid. 50 holds 50 / 1.1 = 45.45 before tax and 4.55 tax.
		$t::money( 45.45, $lines[0]->get_total(), 'cart line before tax' );
		$t::money( 4.55, $lines[0]->get_tax_total(), 'cart line tax' );
		$order = $t::place_order();
		$items = array_values( (array) $order->get_items() );
		$t::money( 45.45, $items[0]->get_total(), 'order line before tax' );
		$t::money( 4.55, $items[0]->get_tax_total(), 'order line tax' );
		$t::money( 4.55, $order->get_tax(), 'order tax' );
	}
);

$combo['hand_test'](
	'Combo, smallest cart: prices include tax, a print included in a session has no tax on the order line',
	function () use ( $t ) {
		$t::require_plugin( 'sunshine_session_fees_cart_item_discount', 'Session Fees add-on' );
		$customer_id = $t::customer();
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$product_id = $t::product( 11 );
		update_user_meta(
			$customer_id,
			'sunshine_session_products',
			array(
				array(
					'product'  => (string) $product_id,
					'qty'      => '1',
					'order_id' => 0,
				),
			)
		);
		$t::add( $product_id, 1 );
		$cart = $t::calculate();
		// The print is included in the session, so nothing is paid for it and it holds no tax.
		$t::money( 0, $cart->get_total(), 'cart total' );
		$t::money( 0, $cart->get_tax(), 'cart tax' );
		$order = $t::place_order();
		$items = array_values( (array) $order->get_items() );
		$t::money( 0, $items[0]->get_total(), 'order line before tax' );
		$t::money( 0, $items[0]->get_tax_total(), 'order line tax' );
	}
);

$combo['hand_test'](
	'Combo, smallest cart: a gift card that pays for everything leaves nothing to pay, even with a fixed payment fee',
	function () use ( $t, &$combo ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::gift_card( 500 );
		$combo['use_fee']( 'amount', 2.50 );
		$cart  = $t::calculate();
		$taken = array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) );
		// The card covers the 100 order. With nothing left to pay there is no card payment to
		// charge a card fee on, the same as when store credit pays for everything. (Whether the
		// card also pays the fee or the fee goes is left open: either way the customer owes nothing.)
		$t::money( 0, $cart->get_total(), 'left to pay' );
		$t::same( true, $taken <= 102.50 + 0.001, 'the card pays at most the order and the fee (took ' . $taken . ')' );
		$cart = $t::new_request();
		$t::money( 0, $cart->get_total(), 'left to pay on the next page load' );
	}
);

$combo['hand_test'](
	'Combo, smallest cart: store credit that pays for everything gives the same fee and credit on every page load',
	function () use ( $t, &$combo ) {
		$combo['use_credit']( 500 );
		$t::tax( 10 );
		$combo['use_fee']( 'amount', 1 );
		$t::add( $t::product( 10 ) );
		$cart         = $t::calculate();
		$first_fee    = $combo['fee_amount']( $cart );
		$first_credit = (float) $cart->get_credits_applied();
		$cart         = $t::new_request();
		// 10 + 1 tax = 11, all paid with credit. Nothing is paid by card, so there is no card fee,
		// on the first page load as on the next.
		$t::money( 0, $first_fee, 'card fee, first page load' );
		$t::money( 11, $first_credit, 'credit used, first page load' );
		$t::money( 0, $combo['fee_amount']( $cart ), 'card fee, next page load' );
		$t::money( 11, $cart->get_credits_applied(), 'credit used, next page load' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

$combo['hand_test'](
	'Combo, smallest cart: the chosen payment method and its fee stay after store credit stops covering the order',
	function () use ( $t, &$combo ) {
		$combo['use_credit']( 15 );
		$combo['use_fee']( 'amount', 1 );
		// The first print costs less than the credit, so for a moment there is nothing to pay.
		$t::add( $t::product( 10, array( 'taxable' => false ) ) );
		$t::add( $t::product( 10, array( 'taxable' => false ) ) );
		$cart = $t::new_request();
		// 20 + 1 card fee = 21, 15 paid with credit, 6 left to pay by card.
		$t::same( 'test', $cart->get_checkout_data_item( 'payment_method' ), 'payment method the customer chose' );
		$t::money( 1, $combo['fee_amount']( $cart ), 'card fee' );
		$t::money( 15, $cart->get_credits_applied(), 'credit used' );
		$t::money( 6, $cart->get_total(), 'left to pay' );
	}
);

// Rounding each line's discount to the cent is assumed here, as every other amount is; the point
// is that the customer never sees lines that add up to a different subtotal.
$combo['hand_test'](
	'Combo, smallest cart: with a percent quantity discount the line totals shown add up to the subtotal',
	function () use ( $t, &$combo ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$rule = $combo['qty_rule']( 2, 5, 'percent' );
		$t::add( $t::product( 19.99, array( 'taxable' => false, 'qty_discount' => $rule ) ), 12 );
		$t::add( $t::product( 19.99, array( 'taxable' => false, 'qty_discount' => $rule ) ), 12 );
		$cart  = $t::calculate();
		$shown = 0;
		foreach ( (array) $cart->get_cart_items() as $line ) {
			$shown += round( $line->get_total(), 2 );
		}
		// Each line: 12 x 19.99 = 239.88, 5% off = 11.994, so 11.99 off and 227.89 a line.
		$t::money( $shown, $cart->get_subtotal(), 'subtotal is the lines shown, added up' );
		$t::money( 455.78, $cart->get_subtotal(), 'subtotal 2 x 227.89' );
		$t::money( 455.78, $cart->get_total(), 'total' );
	}
);

$combo['hand_test'](
	'Combo, smallest cart: a discount that includes taxed shipping lowers the tax on the shipping too',
	function () use ( $t, &$combo ) {
		$combo['without_new_filters'](
			function () use ( $t ) {
				$t::tax( 10 );
				$t::add( $t::product( 10 ) );
				$t::flat_shipping( 30, true );
				$t::discount( 'percent-total', 50, array( 'apply_to_shipping' => '1' ) );
				$cart = $t::calculate();
				// 50% off 10 + 30 shipping = 20 off. The 20 left is all taxed: 2.00 tax.
				// Total 10 + 30 - 20 + 2 = 22.
				$t::money( 20, $cart->get_discount(), 'discount' );
				$t::money( 2, $cart->get_tax(), 'tax' );
				$t::money( 22, $cart->get_total(), 'total' );
			}
		);
	}
);

// Put everything back the way it was.
remove_filter( 'pre_http_request', $combo['offline'], PHP_INT_MAX );
remove_filter( 'pre_wp_mail', $combo['no'], PHP_INT_MAX );
remove_filter( 'sunshine_checkout_allow_order_notify', $combo['no'], PHP_INT_MAX );
remove_action( 'wp_insert_post', $combo['remember'], 10 );
if ( false !== $combo['auto_hook'] ) {
	add_action( 'sunshine_set_discounts', 'sunshine_discounts_set_auto', $combo['auto_hook'] );
}
foreach ( array_unique( $combo['made_emails'] ) as $combo_email_id ) {
	wp_delete_post( $combo_email_id, true );
}
if ( ! empty( $combo['made_products'] ) ) {
	global $wpdb;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sunshine_actions' ) ) ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_actions WHERE action_type = 'add_to_cart' AND action_object_id IN (" . implode( ',', array_map( 'intval', array_unique( $combo['made_products'] ) ) ) . ')' );
	}
}
if ( ! empty( $combo['blocked'] ) ) {
	WP_CLI::log( 'combo-adjusters blocked outgoing requests to: ' . implode( ', ', array_unique( $combo['blocked'] ) ) );
}
