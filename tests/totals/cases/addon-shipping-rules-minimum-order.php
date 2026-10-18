<?php
/**
 * Minimum Order add-on: "Minimum amount a customer must have in cart before they can
 * checkout", and a per product "Minimum Quantity (Cart)".
 *
 * The add-on checks this when the checkout page loads, and sends the customer back to the
 * cart with an error. These tests run that same check for the checkout page.
 *
 * Which amount counts: the Subtotal line the customer sees in the cart, the same rule the
 * Advanced Shipping order amounts use (see addon-shipping-rules.php). Quantity discounts lower
 * it. Discount codes, gift cards, store credit and shipping do not. Tax counts only when the
 * store's prices include tax, because then the prices the customer sees have tax in them.
 *
 * The Minimum Order setting is a number field with no decimal step, so the settings screen only
 * takes whole amounts like 50.
 */

$t = 'SPC_Totals_Test';

// Run the add-on's checkout page check. Returns where the customer was sent (empty when they
// may stay on the checkout page) and the error messages it showed.
$shiprules_min_try_checkout = function () {
	global $wp_query;
	$had_query   = isset( $wp_query->query );
	$saved_query = $had_query ? $wp_query->query : null;
	if ( ! is_array( $saved_query ) ) {
		$wp_query->query = array();
	}

	$on_checkout = function ( $is_page, $page ) {
		return ( 'checkout' === $page ) ? true : $is_page;
	};
	// Stop the redirect before it sends headers and exits.
	$stop = function ( $location ) {
		throw new Exception( 'spcshiprules-redirect:' . $location );
	};
	add_filter( 'is_sunshine_page', $on_checkout, 10, 2 );
	add_filter( 'wp_redirect', $stop, -1000 );

	SPC()->notices->clear();
	$redirect = '';
	try {
		sunshine_minimum_order_checkout();
	} catch ( Exception $e ) {
		if ( 0 !== strpos( $e->getMessage(), 'spcshiprules-redirect:' ) ) {
			throw $e;
		}
		$redirect = substr( $e->getMessage(), strlen( 'spcshiprules-redirect:' ) );
		if ( '' === $redirect ) {
			$redirect = '(empty)';
		}
	} finally {
		remove_filter( 'is_sunshine_page', $on_checkout, 10 );
		remove_filter( 'wp_redirect', $stop, -1000 );
		if ( $had_query ) {
			$wp_query->query = $saved_query;
		} else {
			unset( $wp_query->query );
		}
	}

	$errors = array();
	foreach ( (array) SPC()->session->get( 'notices' ) as $notice ) {
		if ( is_array( $notice ) && 'error' === $notice['type'] ) {
			$errors[] = wp_strip_all_tags( $notice['text'] );
		}
	}
	SPC()->notices->clear();

	return array(
		'redirect' => $redirect,
		'errors'   => $errors,
	);
};

// True when the check let the customer stay on the checkout page.
$shiprules_min_can_check_out = function () use ( $shiprules_min_try_checkout ) {
	$result = $shiprules_min_try_checkout();
	return '' === $result['redirect'];
};

$shiprules_min_prices_include_tax = function ( $percent ) use ( $t ) {
	$t::tax( $percent );
	$t::option( 'price_has_tax', 'yes' );
	$t::option( 'display_price', 'with_tax' );
};

$t::test(
	'Minimum order: a cart one cent under the minimum is sent back to the cart',
	function () use ( $t, $shiprules_min_try_checkout ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$t::option( 'minimum_order', '50' );
		$t::add( $t::product( 49.99, array( 'taxable' => false ) ) );
		$t::calculate();
		$result = $shiprules_min_try_checkout();
		$cart_url = sunshine_get_page_permalink( 'cart' );
		$t::same( true, '' !== $result['redirect'], 'customer is sent away from checkout at 49.99' );
		if ( $cart_url ) {
			$t::same( $cart_url, $result['redirect'], 'customer is sent to the cart page' );
		}
		$t::same( 1, count( $result['errors'] ), 'one error is shown' );
		$t::same( true, ! empty( $result['errors'] ) && false !== strpos( $result['errors'][0], wp_strip_all_tags( sunshine_price( 50 ) ) ), 'the error names the 50 minimum' );
	}
);

$t::test(
	'Minimum order: a cart of exactly the minimum can check out',
	function () use ( $t, $shiprules_min_try_checkout ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$t::option( 'minimum_order', '50' );
		// 19.99 x 2 + 10.02 = 50.00.
		$t::add( $t::product( 19.99, array( 'taxable' => false ) ), 2 );
		$t::add( $t::product( 10.02, array( 'taxable' => false ) ) );
		$t::calculate();
		$result = $shiprules_min_try_checkout();
		$t::same( '', $result['redirect'], 'customer stays on checkout at 50.00' );
		$t::same( array(), $result['errors'], 'no error is shown' );
	}
);

$t::test(
	'Minimum order: with no minimum set, any cart can check out',
	function () use ( $t, $shiprules_min_can_check_out ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$t::option( 'minimum_order', '' );
		$t::add( $t::product( 0.50, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $shiprules_min_can_check_out(), 'a 0.50 cart can check out' );
	}
);

// Prices leave tax out: the cart shows a 46.00 subtotal with 4.60 tax below it.
$t::test(
	'Minimum order: tax added on top does not count',
	function () use ( $t, $shiprules_min_can_check_out ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$t::tax( 10 );
		$t::option( 'display_price', 'without_tax' );
		$t::option( 'minimum_order', '50' );
		$t::add( $t::product( 46 ) );
		$cart = $t::calculate();
		$t::money( 50.60, $cart->get_total(), 'total 46 + 4.60 tax' );
		$t::same( false, $shiprules_min_can_check_out(), 'a 46.00 subtotal is under the 50 minimum' );
	}
);

$t::test(
	'Minimum order: prices include tax, a cart showing 55.00 can check out',
	function () use ( $t, $shiprules_min_can_check_out, $shiprules_min_prices_include_tax ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$shiprules_min_prices_include_tax( 20 );
		$t::option( 'minimum_order', '50' );
		$t::add( $t::product( 27.50 ), 2 );
		$cart = $t::calculate();
		// The customer sees and pays 2 x 27.50 = 55.00.
		$t::money( 55, $cart->get_total(), 'total' );
		$t::same( true, $shiprules_min_can_check_out(), 'a 55.00 cart can check out' );
	}
);

$t::test(
	'Minimum order: prices include tax, a cart showing exactly 50.00 can check out',
	function () use ( $t, $shiprules_min_can_check_out, $shiprules_min_prices_include_tax ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$shiprules_min_prices_include_tax( 20 );
		$t::option( 'minimum_order', '50' );
		$t::add( $t::product( 25 ), 2 );
		$cart = $t::calculate();
		$t::money( 50, $cart->get_total(), 'total' );
		$t::same( true, $shiprules_min_can_check_out(), 'a 50.00 cart can check out' );
	}
);

$t::test(
	'Minimum order: prices include tax, a cart showing 49.99 is sent back',
	function () use ( $t, $shiprules_min_can_check_out, $shiprules_min_prices_include_tax ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$shiprules_min_prices_include_tax( 20 );
		$t::option( 'minimum_order', '50' );
		$t::add( $t::product( 49.99 ) );
		$t::calculate();
		$t::same( false, $shiprules_min_can_check_out(), 'a 49.99 cart cannot check out' );
	}
);

$t::test(
	'Minimum order: shipping does not count',
	function () use ( $t, $shiprules_min_can_check_out ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$t::option( 'minimum_order', '50' );
		$t::add( $t::product( 45, array( 'taxable' => false ) ) );
		$t::flat_shipping( 10 );
		$cart = $t::calculate();
		$t::money( 55, $cart->get_total(), 'total 45 + 10 shipping' );
		$t::same( false, $shiprules_min_can_check_out(), 'a 45.00 subtotal is under the 50 minimum' );
	}
);

// Assumption: a discount code shows as its own line under the subtotal, so it does not take
// the cart under the minimum ("amount a customer must have in cart").
$t::test(
	'Minimum order: a discount code does not count against the minimum',
	function () use ( $t, $shiprules_min_can_check_out ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$t::option( 'minimum_order', '50' );
		$t::add( $t::product( 60, array( 'taxable' => false ) ) );
		$t::discount( 'percent-total', 20 );
		$cart = $t::calculate();
		$t::money( 48, $cart->get_total(), 'total 60 - 12' );
		$t::same( true, $shiprules_min_can_check_out(), 'a 60.00 cart with a code can check out' );
	}
);

$t::test(
	'Minimum order: a gift card does not count against the minimum',
	function () use ( $t, $shiprules_min_can_check_out ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$t::option( 'minimum_order', '50' );
		$t::add( $t::product( 60, array( 'taxable' => false ) ) );
		$t::gift_card( 50 );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_total(), 'left to pay 60 - 50' );
		$t::same( true, $shiprules_min_can_check_out(), 'a 60.00 cart paid mostly by gift card can check out' );
	}
);

$t::test(
	'Minimum order: store credit does not count against the minimum',
	function () use ( $t, $shiprules_min_can_check_out ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 40 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::option( 'minimum_order', '50' );
		$t::add( $t::product( 60, array( 'taxable' => false ) ) );
		$cart = $t::calculate();
		$t::money( 20, $cart->get_total(), 'total 60 - 40 credit' );
		$t::same( true, $shiprules_min_can_check_out(), 'a 60.00 cart paid partly by credit can check out' );
	}
);

// A quantity discount lowers the line price itself, so the subtotal shown is 48.
$t::test(
	'Minimum order: a quantity discount lowers the amount that counts',
	function () use ( $t, $shiprules_min_can_check_out ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::option( 'minimum_order', '50' );
		$rule = array(
			'calc'  => '',
			'items' => array(
				array(
					'qty'      => '10',
					'discount' => '20',
					'type'     => 'percent',
				),
			),
		);
		$t::add( $t::product( 6, array( 'taxable' => false, 'qty_discount' => $rule ) ), 10 );
		$cart = $t::calculate();
		// 10 x 6 = 60, less 20% = 48.
		$t::money( 48, $cart->get_subtotal(), 'subtotal' );
		$t::same( false, $shiprules_min_can_check_out(), 'a 48.00 subtotal is under the 50 minimum' );
	}
);

/*
 * Minimum Quantity (Cart) on a product: "Do not allow customer to have less than this quantity
 * for a product in their cart".
 */

$t::test(
	'Minimum quantity: two of a product that needs three is sent back to the cart',
	function () use ( $t, $shiprules_min_try_checkout ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$t::option( 'minimum_order', '' );
		$wallet = $t::product( 4, array( 'taxable' => false, 'name' => 'Wallet prints' ) );
		update_post_meta( $wallet, 'min_qty_cart', '3' );
		$t::add( $wallet, 2 );
		$t::calculate();
		$result = $shiprules_min_try_checkout();
		$t::same( true, '' !== $result['redirect'], 'customer is sent away from checkout with 2' );
		$t::same( true, ! empty( $result['errors'] ) && false !== strpos( $result['errors'][0], 'Wallet prints' ), 'the error names the product' );
	}
);

// Photos of the same product are separate lines, so the quantity is counted across lines.
$t::test(
	'Minimum quantity: lines of the same product are added together',
	function () use ( $t, $shiprules_min_can_check_out ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$t::option( 'minimum_order', '' );
		$wallet = $t::product( 4, array( 'taxable' => false ) );
		update_post_meta( $wallet, 'min_qty_cart', '3' );
		$t::add( $wallet, 2 );
		$t::add( $wallet, 1 );
		$t::calculate();
		$t::same( 2, count( SPC()->cart->get_cart() ), 'two lines' );
		$t::same( true, $shiprules_min_can_check_out(), '2 + 1 = 3 can check out' );
	}
);

// The check only runs when the checkout page loads. If the cart drops under the minimum after
// that (for example from the cart page in another tab), placing the order must still be refused.
$t::test(
	'Minimum order: an order under the minimum cannot be placed',
	function () use ( $t, $shiprules_min_can_check_out ) {
		$t::require_plugin( 'sunshine_minimum_order_checkout', 'Minimum Order add-on' );
		$t::option( 'minimum_order', '50' );
		$line = $t::add( $t::product( 30, array( 'taxable' => false ) ), 2 );
		$t::calculate();
		$t::same( true, $shiprules_min_can_check_out(), 'a 60.00 cart can open the checkout page' );

		SPC()->cart->update_item_quantity_by_hash( $line['hash'], 1 );
		$t::new_request();
		$placed = null;
		try {
			$placed = $t::place_order();
		} catch ( Exception $e ) {
			if ( false === strpos( $e->getMessage(), 'Order was not placed' ) || false !== strpos( $e->getMessage(), 'Please complete' ) ) {
				throw $e;
			}
		}
		$t::same( true, null === $placed, 'a 30.00 order is refused under the 50 minimum' . ( $placed ? ' (order ' . $placed->get_id() . ' was placed for ' . $placed->get_total() . ')' : '' ) );
	}
);
