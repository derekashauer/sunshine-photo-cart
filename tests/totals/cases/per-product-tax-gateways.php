<?php
/**
 * What PayPal, Square and Stripe are sent when some products have a different tax rate, using
 * the snippet from the doc "Charge a different tax rate on some products"
 * (.docs/different-tax-rate-per-product.md). The normal rate is 20% and the snippet's rate is 5%.
 *
 * Nothing reaches any payment service: every outgoing request is caught before it leaves the
 * site and answered with a made up reply.
 *
 * - PayPal is sent each item's price without tax and one tax total for the order.
 * - Square is sent an itemized order (lines, shipping, taxes). Square works out its own total;
 *   when it doesn't match the order, Sunshine cancels it and charges without the item list.
 * - Stripe's hosted page gets line items and Stripe tax rates. The doc warns that this mode
 *   sends one tax rate for the whole order, so Stripe's receipt and tax reports won't match.
 */

$t = 'SPC_Totals_Test';

// Option 2 from the doc, exactly as published, with the test's product IDs in place of
// 123, 456, 789.
$ppt_gw_snippet_products = function ( $different_rate_products ) {
	return function ( $tax_rate, $cart_item ) use ( $different_rate_products ) {
		if ( ! empty( $tax_rate ) && in_array( $cart_item->get_product_id(), $different_rate_products ) ) {
			$tax_rate['rate'] = 0.05; // 5% rate.
		}
		return $tax_rate;
	};
};

// Every request is stopped here first. The payment helpers below answer the ones they expect
// with a made up reply at a later priority; anything else gets this error and never leaves.
$ppt_gw_offline = function () {
	return new WP_Error( 'spc_totals_offline', 'Outgoing requests are blocked while the totals tests run.' );
};

// Snippets added by the current test. They come off when the test ends, even if it fails.
$ppt_gw_snippets = array();

$ppt_gw_use = function ( $snippet ) use ( &$ppt_gw_snippets ) {
	add_filter( 'sunshine_cart_item_tax_rate', $snippet, 10, 2 );
	$ppt_gw_snippets[] = $snippet;
};

$ppt_gw_test = function ( $name, $callback ) use ( $t, $ppt_gw_offline, &$ppt_gw_snippets ) {
	$t::test(
		$name,
		function () use ( $callback, $ppt_gw_offline, &$ppt_gw_snippets ) {
			$ppt_gw_snippets = array();
			add_filter( 'pre_http_request', $ppt_gw_offline, 1, 3 );
			try {
				$callback();
			} finally {
				foreach ( $ppt_gw_snippets as $snippet ) {
					remove_filter( 'sunshine_cart_item_tax_rate', $snippet, 10 );
				}
				$ppt_gw_snippets = array();
				remove_filter( 'pre_http_request', $ppt_gw_offline, 1 );
				SPC()->session->set( 'stripe_customer_id', '' );
			}
		}
	);
};

// A cart with a 5% print (listed in the snippet) and a 20% print.
$ppt_gw_cart = function ( $listed_price, $other_price ) use ( $t, $ppt_gw_use, $ppt_gw_snippet_products ) {
	$t::tax( 20 );
	$listed = $t::product( $listed_price );
	$other  = $t::product( $other_price );
	$ppt_gw_use( $ppt_gw_snippet_products( array( $listed ) ) );
	$t::add( $listed );
	$t::add( $other );
};

/*
 * PayPal. (Helpers copied from core-gateways-paypal.php.)
 */

// Build the PayPal order for the current cart and return what would be sent to PayPal.
$ppt_gw_paypal_args = function () use ( $t ) {
	$paypal = sunshine_get_payment_method_by_id( 'paypal' );
	if ( ! $paypal ) {
		$t::skip( 'PayPal payment method not available' );
	}
	$sent    = null;
	$capture = function ( $args ) use ( &$sent ) {
		$sent = $args;
		return $args;
	};
	// Every request gets a made up empty answer, so nothing ever leaves the site.
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

// PayPal's rules (the breakdown adds up, items add up to item_total) and the hand-worked numbers.
// $items is the expected price each of every item, in cart order.
$ppt_gw_paypal_check = function ( $args, $expected, $items ) use ( $t ) {
	$unit      = $args['purchase_units'][0];
	$breakdown = $unit['amount']['breakdown'];
	$items_sum = 0;
	$sent      = array();
	foreach ( $unit['items'] as $item ) {
		$items_sum += (float) $item['unit_amount']['value'] * (int) $item['quantity'];
		$sent[]     = round( (float) $item['unit_amount']['value'], 2 );
	}
	$t::same( $items, $sent, 'price each sent for every item, without tax' );
	$t::money( $items_sum, $breakdown['item_total']['value'], 'item total is the items added up' );
	$sum = (float) $breakdown['item_total']['value'] + (float) $breakdown['tax_total']['value'] + (float) $breakdown['shipping']['value'] - (float) $breakdown['discount']['value'];
	$t::money( $unit['amount']['value'], $sum, 'breakdown adds up to the amount' );
	$t::money( $expected['amount'], $unit['amount']['value'], 'amount charged' );
	foreach ( array( 'item_total', 'tax_total', 'shipping', 'discount' ) as $key ) {
		if ( isset( $expected[ $key ] ) ) {
			$t::money( $expected[ $key ], $breakdown[ $key ]['value'], $key );
		}
	}
};

$ppt_gw_paypal_use = function () use ( $t ) {
	$t::option( 'paypal_fee', 'none' );
	$t::option( 'paypal_fee_amount', '' );
	$t::option( 'paypal_fee_name', 'PayPal fee' );
	SPC()->cart->set_checkout_data_item( 'payment_method', 'paypal' );
};

// Prints 100 at 5% and 100 at 20%, shipping 10 at the normal 20%.
// Tax 5 + 20 + 2 = 27. Amount 200 + 10 + 27 = 237.
$ppt_gw_test(
	'Per-product tax PayPal: each item without tax, tax total with each rate',
	function () use ( $t, $ppt_gw_cart, $ppt_gw_paypal_use, $ppt_gw_paypal_args, $ppt_gw_paypal_check ) {
		$ppt_gw_cart( 100, 100 );
		$t::flat_shipping( 10, true );
		$ppt_gw_paypal_use();
		$t::checkout_address();
		$t::calculate();
		$args = $ppt_gw_paypal_args();
		$ppt_gw_paypal_check(
			$args,
			array(
				'amount'     => 237,
				'item_total' => 200,
				'tax_total'  => 27,
				'shipping'   => 10,
				'discount'   => 0,
			),
			array( 100.0, 100.0 )
		);
		$order = $t::place_order( 'paypal' );
		$t::money( $order->get_total(), $args['purchase_units'][0]['amount']['value'], 'PayPal amount is the placed order total' );
	}
);

// Prices include tax. 105 has 5% inside, so it is 100 + 5. 120 has 20% inside, so 100 + 20.
// PayPal gets each without its own tax: 100 and 100. Tax 25, amount 225.
$ppt_gw_test(
	'Per-product tax PayPal: prices include tax, each item without its own rate\'s tax',
	function () use ( $t, $ppt_gw_cart, $ppt_gw_paypal_use, $ppt_gw_paypal_args, $ppt_gw_paypal_check ) {
		$t::option( 'price_has_tax', 'yes' );
		$ppt_gw_cart( 105, 120 );
		$ppt_gw_paypal_use();
		$t::checkout_address();
		$t::calculate();
		$args = $ppt_gw_paypal_args();
		$ppt_gw_paypal_check(
			$args,
			array(
				'amount'     => 225,
				'item_total' => 200,
				'tax_total'  => 25,
				'discount'   => 0,
			),
			array( 100.0, 100.0 )
		);
		$order = $t::place_order( 'paypal' );
		$t::money( $order->get_total(), $args['purchase_units'][0]['amount']['value'], 'PayPal amount is the placed order total' );
	}
);

// A 50 gift card pays part: 225 - 50 = 175. The card goes in PayPal's discount.
$ppt_gw_test(
	'Per-product tax PayPal: gift card',
	function () use ( $t, $ppt_gw_cart, $ppt_gw_paypal_use, $ppt_gw_paypal_args, $ppt_gw_paypal_check ) {
		$ppt_gw_cart( 100, 100 );
		$t::gift_card( 50 );
		$ppt_gw_paypal_use();
		$t::checkout_address();
		$t::calculate();
		$ppt_gw_paypal_check(
			$ppt_gw_paypal_args(),
			array(
				'amount'     => 175,
				'item_total' => 200,
				'tax_total'  => 25,
				'discount'   => 50,
			),
			array( 100.0, 100.0 )
		);
	}
);

/*
 * Square. (Helpers copied from core-gateways-square.php, with the tax Square works out added.)
 */

// What Square works out for an itemized order, in cents: the total and the tax.
// Each line is price x quantity; an ORDER discount is shared over the lines by price; ADDITIVE
// tax is added on top of each taxed line, INCLUSIVE tax is already inside and adds nothing.
$ppt_gw_square_math = function ( $square_order ) {
	$taxes = array();
	foreach ( (array) ( isset( $square_order['taxes'] ) ? $square_order['taxes'] : array() ) as $tax ) {
		$taxes[ $tax['uid'] ] = $tax;
	}
	$tax_total = 0;
	$tax_on    = function ( $amount, $applied ) use ( $taxes, &$tax_total ) {
		$added = 0;
		foreach ( (array) $applied as $applied_tax ) {
			$uid = $applied_tax['tax_uid'];
			if ( ! isset( $taxes[ $uid ] ) ) {
				continue;
			}
			$percent = floatval( $taxes[ $uid ]['percentage'] ) / 100;
			if ( 'ADDITIVE' === $taxes[ $uid ]['type'] ) {
				$tax        = (int) round( $amount * $percent );
				$added     += $tax;
				$tax_total += $tax;
			} else {
				$tax_total += $amount - (int) round( $amount / ( 1 + $percent ) );
			}
		}
		return $added;
	};

	$lines = array();
	foreach ( $square_order['line_items'] as $line ) {
		$lines[] = array(
			'gross' => intval( $line['base_price_money']['amount'] ) * intval( $line['quantity'] ),
			'taxes' => isset( $line['applied_taxes'] ) ? $line['applied_taxes'] : array(),
		);
	}
	$discount = 0;
	foreach ( (array) ( isset( $square_order['discounts'] ) ? $square_order['discounts'] : array() ) as $order_discount ) {
		$discount += intval( $order_discount['amount_money']['amount'] );
	}
	$gross = array_sum( wp_list_pluck( $lines, 'gross' ) );
	$left  = $discount;
	$last  = count( $lines ) - 1;
	foreach ( $lines as $i => $line ) {
		$share              = ( $i === $last || ! $gross ) ? $left : (int) round( $discount * $line['gross'] / $gross );
		$left              -= $share;
		$lines[ $i ]['net'] = $line['gross'] - $share;
	}
	$total = 0;
	foreach ( $lines as $line ) {
		$total += $line['net'] + $tax_on( $line['net'], $line['taxes'] );
	}
	foreach ( (array) ( isset( $square_order['service_charges'] ) ? $square_order['service_charges'] : array() ) as $charge ) {
		$amount = intval( $charge['amount_money']['amount'] );
		$total += $amount;
		if ( ! empty( $charge['taxable'] ) ) {
			$total += $tax_on( $amount, isset( $charge['applied_taxes'] ) ? $charge['applied_taxes'] : array() );
		}
	}
	return array(
		'total' => $total,
		'tax'   => $tax_total,
	);
};

/**
 * Place the order with Square and send the card payment, the way the checkout page does once
 * the customer enters a card. Returns the order and what would have been sent to Square:
 * 'square_order' (the itemized order) and 'payment' (the charge).
 */
$ppt_gw_square_pay = function () use ( $t, $ppt_gw_square_math ) {
	$square = sunshine_get_payment_method_by_id( 'square' );
	if ( ! $square || ! $square->is_allowed() ) {
		$t::skip( 'Square is not connected on this site' );
	}
	$t::option( 'square_fee', 'none' );
	$t::option( 'square_fee_amount', '' );
	$t::option( 'square_fee_name', 'Card fee' );
	SPC()->cart->set_checkout_data_item( 'payment_method', 'square' );
	$t::calculate();
	$order = $t::place_order( 'square' );

	$sent = array();
	$http = function ( $pre, $args, $url ) use ( &$sent, $ppt_gw_square_math ) {
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$body   = json_decode( isset( $args['body'] ) ? (string) $args['body'] : '', true );
		$sent[] = array(
			'path' => $path,
			'body' => $body,
		);
		$reply  = array(
			'errors' => array(
				array(
					'category' => 'API_ERROR',
					'code'     => 'BLOCKED_BY_TEST',
					'detail'   => 'Blocked by the totals tests',
				),
			),
		);
		if ( '/v2/orders' === $path && ! empty( $body['order'] ) ) {
			// Square answers with the order and the total it worked out.
			$math  = $ppt_gw_square_math( $body['order'] );
			$reply = array(
				'order' => array(
					'id'          => 'sq_order_spctest',
					'version'     => 1,
					'total_money' => array(
						'amount'   => $math['total'],
						'currency' => $body['order']['line_items'][0]['base_price_money']['currency'],
					),
				),
			);
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $reply ),
			'response' => array(
				'code'    => isset( $reply['errors'] ) ? 400 : 200,
				'message' => isset( $reply['errors'] ) ? 'Bad Request' : 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	$die  = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $die );
	$_POST['security']  = wp_create_nonce( 'sunshine_square' );
	$_POST['source_id'] = 'cnon:card-nonce-ok';
	ob_start();
	try {
		$square->init_order();
	} catch ( SPC_Totals_Test_Done $e ) {
		// init_order() always ends by sending a JSON response.
	} finally {
		ob_end_clean();
		unset( $_POST['security'], $_POST['source_id'] );
		remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $die );
	}

	$found = array(
		'order'        => $order,
		'square_order' => null,
		'payment'      => null,
	);
	foreach ( $sent as $request ) {
		if ( '/v2/orders' === $request['path'] && ! empty( $request['body']['order']['line_items'] ) ) {
			$found['square_order'] = $request['body']['order'];
		} elseif ( '/v2/payments' === $request['path'] ) {
			$found['payment'] = $request['body'];
		}
	}
	if ( empty( $found['square_order'] ) || empty( $found['payment'] ) ) {
		throw new Exception( 'Square order or payment was not sent: ' . wp_json_encode( wp_list_pluck( $sent, 'path' ) ) );
	}
	return $found;
};

// Square's itemized order comes to the order total with the order's tax, the payment is the
// order total, and the payment is tied to the itemized order (so Square shows what was bought).
$ppt_gw_square_check = function ( $sent, $total, $tax ) use ( $t, $ppt_gw_square_math ) {
	$math = $ppt_gw_square_math( $sent['square_order'] );
	$t::money( $total, $sent['order']->get_total(), 'order total' );
	$t::money( $tax, $sent['order']->get_tax(), 'order tax' );
	$t::money( $total, $math['total'] / 100, 'Square order adds up to the order total' );
	$t::money( $tax, $math['tax'] / 100, 'tax Square works out is the order tax' );
	$t::money( $total, intval( $sent['payment']['amount_money']['amount'] ) / 100, 'amount Square charges' );
	$t::same( 'sq_order_spctest', isset( $sent['payment']['order_id'] ) ? $sent['payment']['order_id'] : null, 'payment is tied to the itemized Square order' );
};

// Prints 100 at 5% and 100 at 20%: tax 25, total 225. Square must come to 225 with 25 tax.
$ppt_gw_test(
	'Per-product tax Square: itemized order has each product\'s own tax',
	function () use ( $t, $ppt_gw_cart, $ppt_gw_square_pay, $ppt_gw_square_check ) {
		$ppt_gw_cart( 100, 100 );
		$ppt_gw_square_check( $ppt_gw_square_pay(), 225, 25 );
	}
);

// Prices include tax: 105 (5 tax inside) and 120 (20 tax inside). The customer pays 225 and
// the tax inside is 25. Square must show 25 tax, not tax worked out at 20% on both prints.
$ppt_gw_test(
	'Per-product tax Square: prices include tax',
	function () use ( $t, $ppt_gw_cart, $ppt_gw_square_pay, $ppt_gw_square_check ) {
		$t::option( 'price_has_tax', 'yes' );
		$ppt_gw_cart( 105, 120 );
		$ppt_gw_square_check( $ppt_gw_square_pay(), 225, 25 );
	}
);

/*
 * Stripe's hosted page. (Helpers copied from stripe-hosted-offline.php.)
 */

/**
 * Stripe's charge for a Checkout Session, worked out from the request Sunshine sent, in cents:
 * line items times quantity, the coupon spread over the lines, exclusive tax rates added per
 * line after the coupon, inclusive ones already inside.
 */
$ppt_gw_stripe_math = function ( $session, $coupons, $tax_rates ) {
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
		'tax'           => $tax_added + $tax_included,
		'total'         => $subtotal - $discount + $shipping + $tax_added,
		'unknown_rates' => $unknown,
	);
};

/**
 * Place the order and make the Stripe page for it, the way "Continue to payment" does, with
 * every Stripe request answered by a made up reply. Saving a new Stripe tax rate ID to the
 * settings is caught too, so nothing is written.
 */
$ppt_gw_stripe_checkout = function () use ( $t, $ppt_gw_stripe_math ) {
	$stripe = sunshine_get_payment_method_by_id( 'stripe' );
	if ( ! $stripe ) {
		$t::skip( 'Stripe payment method not available' );
	}
	// A made up Stripe account, set for this test only, with the hosted Stripe page.
	$t::option( 'stripe_active', 1 );
	$t::option( 'stripe_mode', 'test' );
	$t::option( 'stripe_account_id_test', 'acct_spcoffline' );
	$t::option( 'stripe_secret_key_test', 'sk_test_spcoffline' );
	$t::option( 'stripe_publishable_key_test', 'pk_test_spcoffline' );
	$t::option( 'stripe_checkout_mode', 'hosted' );
	$t::option( 'stripe_tax_mode', 'sunshine' );
	$t::option( 'stripe_statement_descriptor', '' );
	$t::option( 'stripe_stripe_tax_rate_ids', array() );
	$t::option( 'stripe_fee', 'none' );
	$t::option( 'stripe_fee_amount', '' );
	$t::option( 'stripe_fee_name', 'Card fee' );
	// A guest already has a Stripe customer, so no customer look-ups are needed.
	SPC()->session->set( 'stripe_customer_id', 'cus_spcoffline' );
	$t::checkout_address();
	SPC()->cart->set_checkout_data_item( 'payment_method', 'stripe' );

	$coupons   = array();
	$tax_rates = array();
	$sessions  = array();
	$made      = array();
	$http      = function ( $pre, $args, $url ) use ( &$coupons, &$tax_rates, &$sessions, &$made, $ppt_gw_stripe_math ) {
		$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
		$body   = array();
		if ( ! empty( $args['body'] ) ) {
			if ( is_string( $args['body'] ) ) {
				parse_str( $args['body'], $body );
			} else {
				$body = (array) $args['body'];
			}
		}
		$path  = preg_replace( '#^/v1/#', '', (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		$reply = array( 'error' => array( 'message' => 'Blocked by the totals tests' ) );
		if ( 'api.stripe.com' === $host ) {
			if ( 'tax_rates' === $path && 'POST' === $method ) {
				$id               = 'txr_spcppt' . count( $tax_rates );
				$tax_rates[ $id ] = array(
					'percentage' => floatval( $body['percentage'] ),
					'inclusive'  => 'true' === $body['inclusive'],
				);
				$made[]           = $body;
				$reply            = array(
					'id'         => $id,
					'percentage' => floatval( $body['percentage'] ),
					'inclusive'  => 'true' === $body['inclusive'],
				);
			} elseif ( 'coupons' === $path && 'POST' === $method ) {
				$coupons[ $body['id'] ] = intval( $body['amount_off'] );
				$reply                  = array(
					'id'         => $body['id'],
					'amount_off' => intval( $body['amount_off'] ),
					'currency'   => $body['currency'],
				);
			} elseif ( 'checkout/sessions' === $path && 'POST' === $method ) {
				$math = $ppt_gw_stripe_math( $body, $coupons, $tax_rates );
				if ( empty( $math['unknown_rates'] ) ) {
					$sessions[] = array(
						'body' => $body,
						'math' => $math,
					);
					$reply      = array(
						'id'           => 'cs_spcppt' . count( $sessions ),
						'url'          => 'https://checkout.stripe.com/c/pay/cs_spcppt',
						'currency'     => strtolower( $body['line_items'][0]['price_data']['currency'] ),
						'amount_total' => $math['total'],
					);
				}
			} elseif ( 0 === strpos( $path, 'customers' ) ) {
				$reply = array( 'id' => 'cus_spcoffline' );
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
	$keep_cache = function ( $value, $old_value ) {
		return $old_value;
	};
	add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
	add_filter( 'pre_update_option_sunshine_stripe_stripe_tax_rate_ids', $keep_cache, PHP_INT_MAX, 2 );
	try {
		$t::calculate();
		$order  = $t::place_order( 'stripe' );
		$create = new ReflectionMethod( $stripe, 'create_checkout_session' );
		$create->setAccessible( true );
		$reply = $create->invoke( $stripe, $order );
	} finally {
		remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
		remove_filter( 'pre_update_option_sunshine_stripe_stripe_tax_rate_ids', $keep_cache, PHP_INT_MAX );
	}
	$session = end( $sessions );
	return array(
		'order'      => $order,
		'reply'      => $reply,
		'stripe'     => $session ? $session['math'] : null,
		'made_rates' => $made,
	);
};

// Prints 100 at 5% and 100 at 20%: the order is 225. The doc warns Stripe's receipt and tax
// reports won't match in this mode, but the customer must still be charged the order total.
$ppt_gw_test(
	'Per-product tax Stripe hosted: the customer is charged the order total',
	function () use ( $t, $ppt_gw_cart, $ppt_gw_stripe_checkout ) {
		$ppt_gw_cart( 100, 100 );
		$r = $ppt_gw_stripe_checkout();
		$t::same( true, is_array( $r['reply'] ) && ! empty( $r['reply']['id'] ), 'Stripe page made' );
		$t::money( 225, $r['order']->get_total(), 'order total' );
		$t::money( 225, $r['stripe'] ? $r['stripe']['total'] / 100 : null, 'Stripe charges the order total' );
	}
);

// Prices include tax: 105 and 120, the customer pays 225. Stripe's tax rate is inclusive here,
// so it adds nothing on top. (Its tax report will be off, as the doc warns, so that is not
// checked.)
$ppt_gw_test(
	'Per-product tax Stripe hosted: prices include tax, the customer is charged the order total',
	function () use ( $t, $ppt_gw_cart, $ppt_gw_stripe_checkout ) {
		$t::option( 'price_has_tax', 'yes' );
		$ppt_gw_cart( 105, 120 );
		$r = $ppt_gw_stripe_checkout();
		$t::same( true, is_array( $r['reply'] ) && ! empty( $r['reply']['id'] ), 'Stripe page made' );
		$t::money( 225, $r['order']->get_total(), 'order total' );
		$t::money( 225, $r['stripe'] ? $r['stripe']['total'] / 100 : null, 'Stripe charges the order total' );
	}
);
