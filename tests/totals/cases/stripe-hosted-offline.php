<?php
/**
 * Stripe hosted checkout, worked out without Stripe. Every request to Stripe is caught before
 * it leaves the site and answered with a made up reply. Then Stripe's own math is done again
 * from what Sunshine sent: line items times quantity, the coupon spread over the line items,
 * Stripe tax rates worked out per line after the coupon, and the shipping charge (which a
 * coupon never reduces). What Stripe would charge must be the order total.
 *
 * Sunshine sends tax to Stripe one of two ways:
 * - as a Stripe tax rate on each taxed line (normal case), or
 * - as its own "Tax" line item, when a gift card, store credit or a discount after tax is on
 *   the order. Those come off after tax, and Stripe would work out tax after the coupon.
 *
 * Runs on every normal run. The real Stripe test mode checks are in stripe-hosted.php.
 *
 * How Stripe does its math (from Stripe's docs, used to rebuild the charge below):
 * - A coupon (amount_off) is spread over all line items in proportion to their amounts. It
 *   never comes off the shipping_options charge, and it can't take more than the line items.
 * - An exclusive tax rate adds tax per line, on the line amount after its share of the coupon,
 *   rounded to the smallest unit. An inclusive tax rate adds nothing; tax is already inside.
 */

$t = 'SPC_Totals_Test';

// Stripe tax rates the made up Stripe account already has. Percent and whether it is inclusive.
$stripe_offline_saved_rates = array(
	'txr_spcoffline_saved10' => array(
		'percentage' => 10,
		'inclusive'  => false,
	),
);

/**
 * Stripe's charge for a Checkout Session, worked out from the request Sunshine sent.
 * Amounts are in Stripe units (cents, whole yen, ...).
 */
$stripe_offline_math = function ( $session, $coupons, $tax_rates ) {
	$lines    = array();
	$subtotal = 0;
	$unknown  = array();
	foreach ( (array) $session['line_items'] as $line ) {
		$amount = intval( $line['price_data']['unit_amount'] ) * intval( $line['quantity'] );
		$rates  = array();
		foreach ( isset( $line['tax_rates'] ) ? (array) $line['tax_rates'] : array() as $rate_id ) {
			if ( isset( $tax_rates[ $rate_id ] ) ) {
				$rates[] = $tax_rates[ $rate_id ];
			} else {
				$unknown[] = $rate_id;
			}
		}
		$lines[]   = array(
			'amount' => $amount,
			'rates'  => $rates,
		);
		$subtotal += $amount;
	}

	$discount = 0;
	if ( ! empty( $session['discounts'][0]['coupon'] ) && isset( $coupons[ $session['discounts'][0]['coupon'] ] ) ) {
		$discount = min( $coupons[ $session['discounts'][0]['coupon'] ], $subtotal );
	}

	// Spread the coupon over the lines in proportion to their amounts, in whole units.
	$shares     = array();
	$remainders = array();
	$given      = 0;
	foreach ( $lines as $i => $line ) {
		$exact            = $subtotal > 0 ? $discount * $line['amount'] / $subtotal : 0;
		$shares[ $i ]     = (int) floor( $exact );
		$remainders[ $i ] = $exact - $shares[ $i ];
		$given           += $shares[ $i ];
	}
	arsort( $remainders );
	foreach ( array_keys( $remainders ) as $i ) {
		if ( $given >= $discount ) {
			break;
		}
		$shares[ $i ]++;
		$given++;
	}

	$tax_added    = 0;
	$tax_included = 0;
	foreach ( $lines as $i => $line ) {
		$net = $line['amount'] - $shares[ $i ];
		foreach ( $line['rates'] as $rate ) {
			if ( $rate['inclusive'] ) {
				$tax_included += $net - round( $net / ( 1 + $rate['percentage'] / 100 ) );
			} else {
				$tax_added += round( $net * $rate['percentage'] / 100 );
			}
		}
	}

	$shipping = 0;
	if ( ! empty( $session['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'] ) ) {
		$shipping = intval( $session['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'] );
	}

	return array(
		'subtotal'      => $subtotal,
		'discount'      => $discount,
		'shipping'      => $shipping,
		'tax'           => $tax_added + $tax_included,
		// What Stripe charges before Stripe Tax adds anything: what Stripe Tax would tax.
		'before_tax'    => $subtotal - $discount + $shipping,
		'total'         => $subtotal - $discount + $shipping + $tax_added,
		'unknown_rates' => $unknown,
	);
};

/**
 * Run $callback with every outgoing request answered like Stripe would, without sending it.
 * Saving a new Stripe tax rate ID to the settings is caught too, so nothing is written.
 */
$stripe_offline_run = function ( $callback ) use ( $stripe_offline_math, $stripe_offline_saved_rates ) {
	$calls        = array();
	$coupons      = array();
	$tax_rates    = $stripe_offline_saved_rates;
	$sessions     = array();
	$cache_writes = array();

	$http = function ( $pre, $args, $url ) use ( &$calls, &$coupons, &$tax_rates, &$sessions, $stripe_offline_math ) {
		$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
		$body   = array();
		if ( ! empty( $args['body'] ) ) {
			if ( is_string( $args['body'] ) ) {
				parse_str( $args['body'], $body );
			} else {
				$body = (array) $args['body'];
			}
		}
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $query_args );
			$body = array_merge( $query_args, $body );
		}
		$path    = preg_replace( '#^/v1/#', '', (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$host    = (string) wp_parse_url( $url, PHP_URL_HOST );
		$calls[] = array(
			'method' => $method,
			'host'   => $host,
			'path'   => $path,
			'body'   => $body,
		);

		$reply = array( 'error' => array( 'message' => 'Blocked by the totals tests' ) );
		if ( 'api.stripe.com' === $host ) {
			$email = SPC()->cart->get_checkout_data_item( 'email' );
			if ( 'customers' === $path && 'GET' === $method ) {
				$reply = array(
					'object' => 'list',
					'data'   => array(),
				);
			} elseif ( 'customers' === $path ) {
				$reply = array(
					'id'    => 'cus_spcoffline',
					'email' => $email,
				);
			} elseif ( 0 === strpos( $path, 'customers/' ) ) {
				$reply = array(
					'id'    => substr( $path, 10 ),
					'email' => $email,
				);
			} elseif ( 'tax_rates' === $path && 'POST' === $method ) {
				$id               = 'txr_spcoffline' . count( $calls );
				$tax_rates[ $id ] = array(
					'percentage' => floatval( $body['percentage'] ),
					'inclusive'  => 'true' === $body['inclusive'],
				);
				$reply            = array(
					'id'         => $id,
					'percentage' => floatval( $body['percentage'] ),
					'inclusive'  => 'true' === $body['inclusive'],
				);
			} elseif ( preg_match( '#^coupons/(.+)$#', $path, $match ) ) {
				$coupon_id = $match[1];
				if ( 'DELETE' === $method ) {
					unset( $coupons[ $coupon_id ] );
					$reply = array(
						'id'      => $coupon_id,
						'deleted' => true,
					);
				} elseif ( isset( $coupons[ $coupon_id ] ) ) {
					$reply = array(
						'id'         => $coupon_id,
						'amount_off' => $coupons[ $coupon_id ],
						'currency'   => strtolower( SPC()->get_option( 'currency' ) ),
					);
				} else {
					$reply = array(
						'error' => array(
							'code'    => 'resource_missing',
							'message' => 'No such coupon',
						),
					);
				}
			} elseif ( 'coupons' === $path && 'POST' === $method ) {
				$coupons[ $body['id'] ] = intval( $body['amount_off'] );
				$reply                  = array(
					'id'         => $body['id'],
					'amount_off' => intval( $body['amount_off'] ),
					'currency'   => $body['currency'],
				);
			} elseif ( 'checkout/sessions' === $path && 'POST' === $method ) {
				$math = $stripe_offline_math( $body, $coupons, $tax_rates );
				if ( ! empty( $math['unknown_rates'] ) ) {
					$reply = array( 'error' => array( 'message' => 'No such tax rate: ' . implode( ', ', $math['unknown_rates'] ) ) );
				} else {
					$sessions[] = array(
						'body' => $body,
						'math' => $math,
					);
					$reply      = array(
						'id'            => 'cs_spcoffline' . count( $calls ),
						'url'           => 'https://checkout.stripe.com/c/pay/cs_spcoffline',
						'currency'      => strtolower( $body['line_items'][0]['price_data']['currency'] ),
						'amount_total'  => $math['total'],
						'total_details' => array(
							'amount_tax'      => empty( $body['automatic_tax'] ) ? $math['tax'] : 0,
							'amount_discount' => $math['discount'],
							'amount_shipping' => $math['shipping'],
						),
					);
				}
			}
		}

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $reply ),
			'response' => array(
				'code'    => isset( $reply['error'] ) ? 400 : 200,
				'message' => isset( $reply['error'] ) ? 'Bad Request' : 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};

	// Sunshine saves the IDs of tax rates it makes. Keep the saved value as it was.
	$keep_cache = function ( $value, $old_value ) use ( &$cache_writes ) {
		$cache_writes[] = $value;
		return $old_value;
	};

	add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
	add_filter( 'pre_update_option_sunshine_stripe_stripe_tax_rate_ids', $keep_cache, PHP_INT_MAX, 2 );
	try {
		$result = $callback();
	} finally {
		remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
		remove_filter( 'pre_update_option_sunshine_stripe_stripe_tax_rate_ids', $keep_cache, PHP_INT_MAX );
	}
	return array(
		'calls'        => $calls,
		'sessions'     => $sessions,
		'cache_writes' => $cache_writes,
		'result'       => $result,
	);
};

// The customer picked Stripe with the hosted Stripe page. Every Stripe setting comes from this
// test only (a made up account and key), never from the site's settings.
$stripe_offline_use = function ( $fee_type = 'none', $fee_amount = '' ) use ( $t ) {
	$t::option( 'stripe_active', 1 );
	$t::option( 'stripe_mode', 'test' );
	$t::option( 'stripe_account_id_test', 'acct_spcoffline' );
	$t::option( 'stripe_secret_key_test', 'sk_test_spcoffline' );
	$t::option( 'stripe_publishable_key_test', 'pk_test_spcoffline' );
	$t::option( 'stripe_checkout_mode', 'hosted' );
	$t::option( 'stripe_tax_mode', 'sunshine' );
	$t::option( 'stripe_statement_descriptor', '' );
	$t::option( 'stripe_stripe_tax_rate_ids', array() );
	$t::option( 'stripe_fee', $fee_type );
	$t::option( 'stripe_fee_amount', (string) $fee_amount );
	$t::option( 'stripe_fee_name', 'Card fee' );
	// A guest already has a Stripe customer, so no customer look-ups are needed.
	SPC()->session->set( 'stripe_customer_id', is_user_logged_in() ? '' : 'cus_spcoffline' );
	$t::checkout_address();
	SPC()->cart->set_checkout_data_item( 'payment_method', 'stripe' );
};

$stripe_offline_forget = function () {
	SPC()->session->set( 'stripe_customer_id', '' );
};

// Place the order and make the Stripe page for it, the way "Continue to payment" does.
$stripe_offline_checkout = function () use ( $t, $stripe_offline_run ) {
	$stripe = sunshine_get_payment_method_by_id( 'stripe' );
	$run    = $stripe_offline_run(
		function () use ( $t, $stripe ) {
			$cart       = $t::calculate();
			$cart_total = (float) $cart->get_total();
			$order      = $t::place_order( 'stripe' );
			$create     = new ReflectionMethod( $stripe, 'create_checkout_session' );
			$create->setAccessible( true );
			return array(
				'order'      => $order,
				'cart_total' => $cart_total,
				'reply'      => $create->invoke( $stripe, $order ),
			);
		}
	);

	$coupon     = null;
	$made_rates = array();
	foreach ( $run['calls'] as $call ) {
		if ( 'coupons' === $call['path'] && 'POST' === $call['method'] ) {
			$coupon = $call['body'];
		} elseif ( 'tax_rates' === $call['path'] && 'POST' === $call['method'] ) {
			$made_rates[] = $call['body'];
		}
	}
	$session = end( $run['sessions'] );
	return array(
		'order'        => $run['result']['order'],
		'cart_total'   => $run['result']['cart_total'],
		'reply'        => $run['result']['reply'],
		'session'      => $session ? $session['body'] : null,
		'stripe'       => $session ? $session['math'] : null,
		'coupon'       => $coupon,
		'made_rates'   => $made_rates,
		'cache_writes' => $run['cache_writes'],
	);
};

// The Stripe page was made, and Stripe charges exactly the order total.
$stripe_offline_charges = function ( $r, $expected, $units = 100 ) use ( $t ) {
	$made = is_array( $r['reply'] ) && ! empty( $r['reply']['id'] );
	$t::same( true, $made, 'Stripe page made' . ( is_wp_error( $r['reply'] ) ? ' (' . $r['reply']->get_error_message() . ')' : '' ) );
	$t::money( $expected, $r['cart_total'], 'cart total' );
	$t::money( $expected, $r['order']->get_total(), 'order total' );
	$t::money( $expected, $r['stripe'] ? $r['stripe']['total'] / $units : null, 'Stripe charges the order total' );
};

// The line items sent to Stripe, simplified: name, unit amount, quantity, tax rate IDs.
$stripe_offline_lines = function ( $r ) {
	$lines = array();
	foreach ( $r['session'] ? (array) $r['session']['line_items'] : array() as $line ) {
		$lines[] = array(
			'name'      => $line['price_data']['product_data']['name'],
			'unit'      => intval( $line['price_data']['unit_amount'] ),
			'qty'       => intval( $line['quantity'] ),
			'tax_rates' => isset( $line['tax_rates'] ) ? array_values( (array) $line['tax_rates'] ) : array(),
		);
	}
	return $lines;
};

$stripe_offline_tax_line = function ( $r ) use ( $stripe_offline_lines ) {
	foreach ( $stripe_offline_lines( $r ) as $line ) {
		if ( $r['order']->get_tax_label() === $line['name'] && empty( $line['tax_rates'] ) ) {
			return $line;
		}
	}
	return null;
};

$stripe_offline_lines_with_rates = function ( $r ) use ( $stripe_offline_lines ) {
	return count(
		array_filter(
			$stripe_offline_lines( $r ),
			function ( $line ) {
				return ! empty( $line['tax_rates'] );
			}
		)
	);
};

/*
 * Tax sent as Stripe tax rates (no gift card, credit or discount after tax).
 */

$t::test(
	'Stripe hosted offline: taxed print, tax sent as a Stripe tax rate',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_lines, $stripe_offline_tax_line ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 100 + 10% tax = 110. Stripe: 10000 cents with a 10% rate on top, 1000 tax.
		$stripe_offline_charges( $r, 110 );
		$t::same( 1, count( $r['made_rates'] ), 'one Stripe tax rate made' );
		$t::same( '10.0000', $r['made_rates'] ? $r['made_rates'][0]['percentage'] : null, 'tax rate percent' );
		$t::same( 'false', $r['made_rates'] ? $r['made_rates'][0]['inclusive'] : null, 'tax is added on top' );
		$lines = $stripe_offline_lines( $r );
		$t::same( 10000, $lines ? $lines[0]['unit'] : null, 'price each in cents' );
		$t::same( 1, $lines ? count( $lines[0]['tax_rates'] ) : null, 'the print carries the tax rate' );
		$t::same( null, $stripe_offline_tax_line( $r ), 'no separate tax line' );
		$t::same( null, $r['coupon'], 'no coupon' );
		$t::money( 10, $r['stripe'] ? $r['stripe']['tax'] / 100 : null, 'tax Stripe reports is the order tax' );
		$stripe_offline_forget();
	}
);

// The numbers from core-orders.php: tax 4.35 on the prints and 0.65 on shipping.
$t::test(
	'Stripe hosted offline: odd tax rate, untaxed item and taxed shipping',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_lines ) {
		$t::tax( 7.25 );
		$t::add( $t::product( 19.99 ), 3 );
		$t::add( $t::product( 4.50, array( 'taxable' => false ) ), 2 );
		$t::flat_shipping( 8.95, true );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 59.97 taxed + 9.00 not taxed + 8.95 shipping taxed. Tax 7.25% of 59.97 = 4.35, of
		// 8.95 = 0.65. Total 82.92.
		$stripe_offline_charges( $r, 82.92 );
		$t::same( '7.2500', $r['made_rates'] ? $r['made_rates'][0]['percentage'] : null, 'tax rate percent' );
		$lines = $stripe_offline_lines( $r );
		$t::same( 3, count( $lines ), 'two prints and the taxed shipping are line items' );
		$t::same( array(), isset( $lines[1] ) ? $lines[1]['tax_rates'] : null, 'untaxed print has no tax rate' );
		$t::same( 895, isset( $lines[2] ) ? $lines[2]['unit'] : null, 'taxed shipping is a line item' );
		$t::same( 1, isset( $lines[2] ) ? count( $lines[2]['tax_rates'] ) : null, 'taxed shipping carries the tax rate' );
		$t::same( false, isset( $r['session']['shipping_options'] ), 'no separate Stripe shipping charge' );
		$t::money( 5.00, $r['stripe'] ? $r['stripe']['tax'] / 100 : null, 'tax Stripe reports is the order tax' );
		$stripe_offline_forget();
	}
);

$t::test(
	'Stripe hosted offline: shipping that is not taxed goes in Stripe\'s shipping charge',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_lines ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, false );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 100 + 10 tax + 10 shipping = 120.
		$stripe_offline_charges( $r, 120 );
		$t::same( 1, count( $stripe_offline_lines( $r ) ), 'only the print is a line item' );
		$t::same( '1000', isset( $r['session']['shipping_options'][0] ) ? $r['session']['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'] : null, 'shipping in cents' );
		$stripe_offline_forget();
	}
);

$t::test(
	'Stripe hosted offline: untaxed print with taxed shipping',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_lines ) {
		$t::tax( 10 );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::flat_shipping( 10, true );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 100 not taxed + 10 shipping + 1 tax on the shipping = 111.
		$stripe_offline_charges( $r, 111 );
		$lines = $stripe_offline_lines( $r );
		$t::same( array(), $lines ? $lines[0]['tax_rates'] : null, 'print has no tax rate' );
		$stripe_offline_forget();
	}
);

// Sunshine remembers the Stripe tax rates it made, one per Stripe account, mode, percent and
// tax-included setting, so it doesn't make a new one on every order.
$t::test(
	'Stripe hosted offline: a saved Stripe tax rate is used again',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_lines ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$stripe_offline_use();
		$t::option(
			'stripe_stripe_tax_rate_ids',
			array(
				// Another Stripe account's rate does not exist in this account.
				'test|acct_spcoffline_other|10.0000|0' => 'txr_spcoffline_other_account',
				'test|acct_spcoffline|10.0000|0'       => 'txr_spcoffline_saved10',
			)
		);
		$r = $stripe_offline_checkout();
		$stripe_offline_charges( $r, 110 );
		$t::same( 0, count( $r['made_rates'] ), 'no new tax rate made' );
		$lines = $stripe_offline_lines( $r );
		$t::same( array( 'txr_spcoffline_saved10' ), $lines ? $lines[0]['tax_rates'] : null, 'this account\'s saved rate' );
		$t::same( 0, count( $r['cache_writes'] ), 'nothing new saved' );
		$stripe_offline_forget();
	}
);

// A saved rate that adds tax on top must not be used when prices already include tax, or
// Stripe would add the tax a second time.
$t::test(
	'Stripe hosted offline: prices include tax never use a saved add-on-top rate',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_lines ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$stripe_offline_use();
		$t::option( 'stripe_stripe_tax_rate_ids', array( 'test|acct_spcoffline|10.0000|0' => 'txr_spcoffline_saved10' ) );
		$r = $stripe_offline_checkout();
		// 110 with 10 tax inside.
		$stripe_offline_charges( $r, 110 );
		$t::same( 'true', $r['made_rates'] ? $r['made_rates'][0]['inclusive'] : null, 'a tax-included rate is made' );
		$lines = $stripe_offline_lines( $r );
		$t::same( 11000, $lines ? $lines[0]['unit'] : null, 'price each includes its tax' );
		$t::same( false, $lines && in_array( 'txr_spcoffline_saved10', $lines[0]['tax_rates'], true ), 'the add-on-top rate is not used' );
		$t::money( 10, $r['stripe'] ? $r['stripe']['tax'] / 100 : null, 'tax Stripe reports is the order tax' );
		$stripe_offline_forget();
	}
);

$t::test(
	'Stripe hosted offline: percent card fee',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_lines ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$stripe_offline_use( 'percent', 3 );
		$r = $stripe_offline_checkout();
		// 110 + 3% of 110 = 3.30. Total 113.30. The fee is a line with no tax.
		$stripe_offline_charges( $r, 113.30 );
		$lines = $stripe_offline_lines( $r );
		$t::same( 330, isset( $lines[1] ) ? $lines[1]['unit'] : null, 'fee line in cents' );
		$t::same( array(), isset( $lines[1] ) ? $lines[1]['tax_rates'] : null, 'fee is not taxed' );
		$stripe_offline_forget();
	}
);

/*
 * Discount codes before tax, with tax sent as Stripe tax rates. Stripe works out tax after the
 * coupon, which is right for a discount before tax, as long as the coupon lands where the
 * discount did.
 */

$t::test(
	'Stripe hosted offline: percent discount with taxed shipping',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$t::discount( 'percent-total', 20 );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 100 - 20 = 80, + 10 shipping, tax on 90 = 9. Total 99.
		$stripe_offline_charges( $r, 99 );
		$t::same( '2000', $r['coupon'] ? $r['coupon']['amount_off'] : null, 'coupon in cents' );
		$t::money( 9, $r['stripe'] ? $r['stripe']['tax'] / 100 : null, 'tax Stripe reports is the order tax' );
		$stripe_offline_forget();
	}
);

// The discount is only on the taxed print. Stripe spreads the coupon over both prints, so it
// takes half the discount off the untaxed print and charges more tax.
$t::test(
	'Stripe hosted offline: discount on a taxed product next to an untaxed one',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$t::tax( 10 );
		$a = $t::product( 50 );
		$b = $t::product( 50, array( 'taxable' => false ) );
		$t::add( $a );
		$t::add( $b );
		$t::discount( 'percent-product', 10, array( 'allowed_products' => array( $a ) ) );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 10% off the taxed 50 = 5. Tax on 45 = 4.50. 100 - 5 + 4.50 = 99.50.
		$stripe_offline_charges( $r, 99.50 );
		$t::money( 4.50, $r['stripe'] ? $r['stripe']['tax'] / 100 : null, 'tax Stripe charges' );
		$stripe_offline_forget();
	}
);

// The other way round: the discount is only on the untaxed print, so the tax stays 5.00.
$t::test(
	'Stripe hosted offline: discount on an untaxed product next to a taxed one',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$t::tax( 10 );
		$a = $t::product( 50 );
		$b = $t::product( 50, array( 'taxable' => false ) );
		$t::add( $a );
		$t::add( $b );
		$t::discount( 'amount-product', 5, array( 'allowed_products' => array( $b ) ) );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 5 off the untaxed print. Tax 10% of 50 = 5. 100 - 5 + 5 = 100.
		$stripe_offline_charges( $r, 100 );
		$t::money( 5, $r['stripe'] ? $r['stripe']['tax'] / 100 : null, 'tax Stripe charges' );
		$stripe_offline_forget();
	}
);

// The discount does not include shipping, but taxed shipping is a Stripe line item, so Stripe
// spreads part of the coupon onto it and charges less shipping tax.
$t::test(
	'Stripe hosted offline: discount with an untaxed print and taxed shipping',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$t::tax( 10 );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::flat_shipping( 10, true );
		$t::discount( 'percent-total', 20 );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 100 - 20 = 80 not taxed. Shipping 10 + 1.00 tax. Total 91.
		$stripe_offline_charges( $r, 91 );
		$t::money( 1, $r['stripe'] ? $r['stripe']['tax'] / 100 : null, 'tax Stripe charges on the shipping' );
		$stripe_offline_forget();
	}
);

// The card fee line also takes a share of the coupon, so less of it comes off the taxed print.
$t::test(
	'Stripe hosted offline: discount with a card fee',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 10 );
		$stripe_offline_use( 'percent', 3 );
		$r = $stripe_offline_checkout();
		// 100 - 10 = 90, tax 9, = 99. Fee 3% of 99 = 2.97. Total 101.97.
		$stripe_offline_charges( $r, 101.97 );
		$t::money( 9, $r['stripe'] ? $r['stripe']['tax'] / 100 : null, 'tax Stripe charges' );
		$stripe_offline_forget();
	}
);

// A discount that includes shipping: the shipping part of the coupon has to come off the
// shipping, but a Stripe coupon never touches Stripe's shipping charge.
$t::test(
	'Stripe hosted offline: discount that includes shipping that is not taxed',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, false );
		$t::discount( 'percent-total', 10, array( 'apply_to_shipping' => 1 ) );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 10 off the print, 1 off shipping. Tax on 90 = 9. 100 - 11 + 9 + 10 = 108.
		$stripe_offline_charges( $r, 108 );
		$t::money( 9, $r['stripe'] ? $r['stripe']['tax'] / 100 : null, 'tax Stripe charges' );
		$stripe_offline_forget();
	}
);

// Half off everything, shipping included, where shipping costs more than the print.
$t::test(
	'Stripe hosted offline: discount that includes shipping is bigger than the items',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$t::add( $t::product( 5, array( 'taxable' => false ) ) );
		$t::flat_shipping( 10, false );
		$t::discount( 'percent-total', 50, array( 'apply_to_shipping' => 1 ) );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 50% of 5 + 50% of 10 = 7.50 off 15. Total 7.50.
		$stripe_offline_charges( $r, 7.50 );
		$t::same( '750', $r['coupon'] ? $r['coupon']['amount_off'] : null, 'coupon in cents' );
		$stripe_offline_forget();
	}
);

// Rounding: Sunshine works out tax on what is left after the discount (22.47 x 7.25% = 1.63).
// Stripe rounds the tax on each line after its share of the coupon.
$t::test(
	'Stripe hosted offline: fixed discount over three taxed prints, tax rounds to the cent',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$t::tax( 7.25 );
		$t::add( $t::product( 19.99 ) );
		$t::add( $t::product( 4.99 ) );
		$t::add( $t::product( 2.49 ) );
		$t::discount( 'amount-total', 5 );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 27.47 - 5 = 22.47. Tax 7.25% of 22.47 = 1.629, so 1.63. Total 24.10.
		$stripe_offline_charges( $r, 24.10 );
		$stripe_offline_forget();
	}
);

/*
 * Gift cards, store credit and discounts after tax: the tax goes as its own line item and no
 * Stripe tax rates are used. The coupon is then just money off.
 */

$t::test(
	'Stripe hosted offline: gift card pays part, tax goes as its own line',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_tax_line, $stripe_offline_lines_with_rates ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$t::gift_card( 30 );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 100 + 10 shipping + 11 tax = 121, - 30 gift card = 91.
		$stripe_offline_charges( $r, 91 );
		$t::same( 0, count( $r['made_rates'] ), 'no Stripe tax rate made' );
		$t::same( 0, $stripe_offline_lines_with_rates( $r ), 'no line carries a tax rate' );
		$tax_line = $stripe_offline_tax_line( $r );
		$t::same( 1100, $tax_line ? $tax_line['unit'] : null, 'tax line in cents' );
		$t::same( '3000', $r['coupon'] ? $r['coupon']['amount_off'] : null, 'gift card goes in the coupon' );
		$stripe_offline_forget();
	}
);

$t::test(
	'Stripe hosted offline: gift card, discount code and card fee',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 10 );
		$t::gift_card( 20 );
		$stripe_offline_use( 'percent', 3 );
		$r = $stripe_offline_checkout();
		// 100 - 10 = 90, tax 9, = 99. Gift card 20 leaves 79. Fee 3% of 79 = 2.37. Total 81.37.
		$stripe_offline_charges( $r, 81.37 );
		$t::same( '3000', $r['coupon'] ? $r['coupon']['amount_off'] : null, 'coupon is the discount and the gift card' );
		$stripe_offline_forget();
	}
);

$t::test(
	'Stripe hosted offline: store credit',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_tax_line ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$stripe_offline_use();
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$r = $stripe_offline_checkout();
		// 100 + 10 tax - 20 credit = 90.
		$stripe_offline_charges( $r, 90 );
		$tax_line = $stripe_offline_tax_line( $r );
		$t::same( 1000, $tax_line ? $tax_line['unit'] : null, 'tax line in cents' );
		$t::same( '2000', $r['coupon'] ? $r['coupon']['amount_off'] : null, 'credit goes in the coupon' );
		$stripe_offline_forget();
	}
);

$t::test(
	'Stripe hosted offline: discount after tax',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_tax_line ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, false );
		$t::discount( 'percent-total', 20 );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 100 + 10 tax = 110, - 20% of 110 = 22, + 10 shipping = 98.
		$stripe_offline_charges( $r, 98 );
		$tax_line = $stripe_offline_tax_line( $r );
		$t::same( 1000, $tax_line ? $tax_line['unit'] : null, 'full tax as its own line' );
		$t::same( '2200', $r['coupon'] ? $r['coupon']['amount_off'] : null, 'coupon in cents' );
		$stripe_offline_forget();
	}
);

// The customer goes back from the Stripe page, adds a gift card and continues again. The same
// order is used, so the old coupon (made for the old amount) must be replaced.
$t::test(
	'Stripe hosted offline: coming back and adding a gift card replaces the coupon',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_run ) {
		global $wpdb;
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$stripe_offline_use();
		$stripe    = sunshine_get_payment_method_by_id( 'stripe' );
		$old_items = array();
		$run       = $stripe_offline_run(
			function () use ( $t, $stripe, &$old_items, $wpdb ) {
				$create = new ReflectionMethod( $stripe, 'create_checkout_session' );
				$create->setAccessible( true );
				$t::calculate();
				$first     = $t::place_order( 'stripe' );
				$old_items = $wpdb->get_col( $wpdb->prepare( "SELECT order_item_id FROM {$wpdb->prefix}sunshine_order_items WHERE order_id = %d", $first->get_id() ) );
				$create->invoke( $stripe, $first );
				$t::gift_card( 30 );
				$t::new_request();
				$second = $t::place_order( 'stripe' );
				return array(
					'first'  => $first,
					'second' => $second,
					'reply'  => $create->invoke( $stripe, $second ),
				);
			}
		);
		// Saving the order again replaces its lines; remove the old lines' details too.
		foreach ( $old_items as $item_id ) {
			$wpdb->delete( $wpdb->prefix . 'sunshine_order_itemmeta', array( 'order_item_id' => $item_id ) );
		}
		$order = $run['result']['second'];
		$t::same( $run['result']['first']->get_id(), $order->get_id(), 'same order both times' );
		// 100 - 20 = 80, tax 8 = 88. Then the gift card: 88 - 30 = 58.
		$t::money( 58, $order->get_total(), 'order total' );
		$last = end( $run['sessions'] );
		$t::same( 2, count( $run['sessions'] ), 'two Stripe pages made' );
		$t::money( 58, $last ? $last['math']['total'] / 100 : null, 'second Stripe page charges the new total' );
		$deleted = array_filter(
			$run['calls'],
			function ( $call ) {
				return 'DELETE' === $call['method'] && 0 === strpos( $call['path'], 'coupons/' );
			}
		);
		$t::same( 1, count( $deleted ), 'old coupon deleted' );
		$stripe_offline_forget();
	}
);

/*
 * Prices include tax.
 */

$t::test(
	'Stripe hosted offline: prices include tax with a discount code',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 110 - 11 = 99, with 9 tax inside.
		$stripe_offline_charges( $r, 99 );
		$t::money( 9, $r['stripe'] ? $r['stripe']['tax'] / 100 : null, 'tax Stripe reports is the order tax' );
		$stripe_offline_forget();
	}
);

// Shipping of 11 with its tax inside is 10 + 1 tax. Stripe needs the 11, since the tax rate
// says the tax is already inside the amount.
$t::test(
	'Stripe hosted offline: prices include tax with taxed shipping',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::flat_shipping( 11, true );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 110 + 11 = 121, with 11 tax inside.
		$stripe_offline_charges( $r, 121 );
		$t::money( 11, $r['stripe'] ? $r['stripe']['tax'] / 100 : null, 'tax Stripe reports is the order tax' );
		$stripe_offline_forget();
	}
);

// The same order with a gift card sends the tax as its own line, which adds up.
$t::test(
	'Stripe hosted offline: prices include tax with taxed shipping and a gift card',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::flat_shipping( 11, true );
		$t::gift_card( 21 );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 121 - 21 gift card = 100.
		$stripe_offline_charges( $r, 100 );
		$stripe_offline_forget();
	}
);

// The discount code's 11 includes 1 of tax. With a gift card the tax goes as its own line,
// worked out after the discount (9), so the prints must be sent at their price without tax
// before the discount (100) and the coupon must be 11 + 20.
$t::test(
	'Stripe hosted offline: prices include tax with a discount code and a gift card',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$t::gift_card( 20 );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 110 - 11 = 99 (9 tax inside), - 20 gift card = 79.
		$stripe_offline_charges( $r, 79 );
		$stripe_offline_forget();
	}
);

$t::test(
	'Stripe hosted offline: prices include tax with a discount code and store credit',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$stripe_offline_use();
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$r = $stripe_offline_checkout();
		// 110 - 11 = 99, - 20 credit = 79.
		$stripe_offline_charges( $r, 79 );
		$stripe_offline_forget();
	}
);

$t::test(
	'Stripe hosted offline: prices include tax, odd prices and a gift card',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_lines ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 12.99 ), 3 );
		$t::gift_card( 10 );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 3 x 12.99 = 38.97 with tax inside, - 10 gift card = 28.97.
		$stripe_offline_charges( $r, 28.97 );
		$stripe_offline_forget();
	}
);

/*
 * Lines with their own discount.
 */

// Two of three prints are included in the customer's session, so the line is 10.00 for 3.
// Stripe takes a whole number of cents each, and 10.00 / 3 doesn't split evenly.
$t::test(
	'Stripe hosted offline: line discount that does not split evenly per item',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_lines ) {
		$t::require_plugin( 'sunshine_session_fees_cart_item_discount', 'Session Fees add-on' );
		$customer_id = $t::customer();
		$product_id  = $t::product( 10, array( 'taxable' => false ) );
		update_user_meta( $customer_id, 'sunshine_session_products', array( array( 'product' => $product_id, 'qty' => 2 ) ) );
		$t::add( $product_id, 3 );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 3 x 10 = 30, 2 included free = 10.00.
		$stripe_offline_charges( $r, 10 );
		$stripe_offline_forget();
	}
);

$t::test(
	'Stripe hosted offline: prices include tax, line discount that does not split evenly',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges ) {
		$t::require_plugin( 'sunshine_session_fees_cart_item_discount', 'Session Fees add-on' );
		$customer_id = $t::customer();
		$product_id  = $t::product( 11 );
		update_user_meta( $customer_id, 'sunshine_session_products', array( array( 'product' => $product_id, 'qty' => 2 ) ) );
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $product_id, 3 );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 3 x 11 = 33 with tax inside, 2 included free = 11.00.
		$stripe_offline_charges( $r, 11 );
		$stripe_offline_forget();
	}
);

/*
 * Other currencies. Stripe takes whole yen, and thousandths of a dinar.
 */

$t::test(
	'Stripe hosted offline: yen with tax rate, discount and shipping',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_lines ) {
		$t::option( 'currency', 'JPY' );
		$t::option( 'country', 'JP' );
		$t::tax( 10 );
		$t::add( $t::product( 1000 ), 3 );
		$t::flat_shipping( 500, false );
		$t::discount( 'percent-total', 15 );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 3000 - 450 = 2550, tax 255, + 500 shipping = 3305 yen.
		$stripe_offline_charges( $r, 3305, 1 );
		$lines = $stripe_offline_lines( $r );
		$t::same( 1000, $lines ? $lines[0]['unit'] : null, 'price each in yen' );
		$t::same( '450', $r['coupon'] ? $r['coupon']['amount_off'] : null, 'coupon in yen' );
		$t::same( 'jpy', $r['coupon'] ? $r['coupon']['currency'] : null, 'coupon currency' );
		$stripe_offline_forget();
	}
);

$t::test(
	'Stripe hosted offline: yen with a gift card',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_tax_line ) {
		$t::option( 'currency', 'JPY' );
		$t::option( 'country', 'JP' );
		$t::tax( 10 );
		$t::add( $t::product( 1000 ), 2 );
		$t::gift_card( 700 );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 2000 + 200 tax - 700 gift card = 1500 yen.
		$stripe_offline_charges( $r, 1500, 1 );
		$tax_line = $stripe_offline_tax_line( $r );
		$t::same( 200, $tax_line ? $tax_line['unit'] : null, 'tax line in yen' );
		$stripe_offline_forget();
	}
);

$t::test(
	'Stripe hosted offline: Kuwaiti dinar is sent in thousandths',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_lines ) {
		$t::option( 'currency', 'KWD' );
		$t::tax( 5 );
		$t::add( $t::product( 12.50 ), 2 );
		$stripe_offline_use();
		$r = $stripe_offline_checkout();
		// 25.00 + 5% tax 1.25 = 26.25 dinar, 26250 thousandths.
		$stripe_offline_charges( $r, 26.25, 1000 );
		$lines = $stripe_offline_lines( $r );
		$t::same( 12500, $lines ? $lines[0]['unit'] : null, 'price each in thousandths' );
		$stripe_offline_forget();
	}
);

/*
 * Platform fee (Sunshine without Stripe Pro takes 5% of what Stripe charges).
 */

$stripe_offline_platform_fee = function () {
	return 5;
};

$t::test(
	'Stripe hosted offline: platform fee is 5% of what Stripe charges, with a gift card',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_platform_fee ) {
		add_filter( 'sunshine_stripe_application_fee_percent', $stripe_offline_platform_fee, 999 );
		try {
			$t::add( $t::product( 100, array( 'taxable' => false ) ) );
			$t::gift_card( 30 );
			$stripe_offline_use();
			$r = $stripe_offline_checkout();
			// 100 - 30 gift card = 70 charged. 5% of 70 = 3.50.
			$stripe_offline_charges( $r, 70 );
			$t::same( '350', isset( $r['session']['payment_intent_data']['application_fee_amount'] ) ? $r['session']['payment_intent_data']['application_fee_amount'] : null, 'platform fee in cents' );
		} finally {
			remove_filter( 'sunshine_stripe_application_fee_percent', $stripe_offline_platform_fee, 999 );
			$stripe_offline_forget();
		}
	}
);

$t::test(
	'Stripe hosted offline: no platform fee in Mexico',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_charges, $stripe_offline_platform_fee ) {
		add_filter( 'sunshine_stripe_application_fee_percent', $stripe_offline_platform_fee, 999 );
		try {
			$t::option( 'country', 'MX' );
			$t::add( $t::product( 100, array( 'taxable' => false ) ) );
			$stripe_offline_use();
			$r = $stripe_offline_checkout();
			$stripe_offline_charges( $r, 100 );
			$t::same( false, isset( $r['session']['payment_intent_data']['application_fee_amount'] ), 'no platform fee' );
		} finally {
			remove_filter( 'sunshine_stripe_application_fee_percent', $stripe_offline_platform_fee, 999 );
			$stripe_offline_forget();
		}
	}
);

/*
 * Stripe Tax: Stripe works out the tax once the customer gives an address on the Stripe page.
 * Sunshine's own taxes are off. What Stripe charges before its tax must be the order total.
 */

$t::test(
	'Stripe hosted offline: Stripe Tax, prices and shipping sent before tax',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout, $stripe_offline_lines ) {
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, false );
		$stripe_offline_use();
		$t::option( 'stripe_tax_mode', 'stripe' );
		$r = $stripe_offline_checkout();
		// 100 + 10 shipping = 110 before Stripe adds tax.
		$t::same( true, is_array( $r['reply'] ) && ! empty( $r['reply']['id'] ), 'Stripe page made' );
		$t::money( 110, $r['order']->get_total(), 'order total' );
		$t::money( 110, $r['stripe'] ? $r['stripe']['before_tax'] / 100 : null, 'Stripe charges the order total before its tax' );
		$t::same( 'true', isset( $r['session']['automatic_tax']['enabled'] ) ? $r['session']['automatic_tax']['enabled'] : null, 'Stripe Tax is on' );
		$t::same( 'exclusive', isset( $r['session']['line_items'][0]['price_data']['tax_behavior'] ) ? $r['session']['line_items'][0]['price_data']['tax_behavior'] : null, 'price is before tax' );
		$t::same( 'exclusive', isset( $r['session']['shipping_options'][0]['shipping_rate_data']['tax_behavior'] ) ? $r['session']['shipping_options'][0]['shipping_rate_data']['tax_behavior'] : null, 'shipping is before tax' );
		$t::same( 0, count( $r['made_rates'] ), 'no Sunshine tax rates' );
		$t::same( 1, count( $stripe_offline_lines( $r ) ), 'no tax line' );
		$t::same( 'stripe', $r['order']->get_meta_value( 'stripe_tax_mode' ), 'order remembers Stripe Tax is used' );
		$stripe_offline_forget();
	}
);

// A gift card is a way of paying, not a price cut, so it should not lower the amount that is
// taxed (Sunshine's own tax works this way). With Stripe Tax it goes in the coupon, and Stripe
// Tax works out tax on what is left after the coupon.
$t::test(
	'Stripe hosted offline: Stripe Tax, a gift card does not lower what is taxed',
	function () use ( $t, $stripe_offline_use, $stripe_offline_forget, $stripe_offline_checkout ) {
		$t::add( $t::product( 100 ) );
		$t::gift_card( 30 );
		$stripe_offline_use();
		$t::option( 'stripe_tax_mode', 'stripe' );
		$r = $stripe_offline_checkout();
		// 100 - 30 gift card = 70 to pay before tax, but tax is owed on the full 100.
		$t::money( 70, $r['order']->get_total(), 'order total before tax' );
		$t::money( 100, $r['stripe'] ? $r['stripe']['before_tax'] / 100 : null, 'amount Stripe Tax taxes' );
		$stripe_offline_forget();
	}
);
