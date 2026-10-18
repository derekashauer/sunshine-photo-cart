<?php
/**
 * Discount Codes add-on, part two: automatic discounts, free shipping codes, how many times a
 * code can be used, and what is saved when an order is placed and paid.
 *
 * Orders are placed through the real checkout step with the Test payment method, then finished
 * the way a completed payment finishes them (SPC_Cart::post_process_order), which is the step
 * that counts discount uses.
 */

$t = 'SPC_Totals_Test';

// Nothing here may reach an outside service or send an email. Finishing an order runs other
// add-ons' hooks and would send receipts, so every outgoing request, email and receipt is
// stopped, and any request that was tried is noted.
$discount_rules_orders_blocked = array();
$discount_rules_orders_offline = function ( $pre, $args, $url ) use ( &$discount_rules_orders_blocked ) {
	$discount_rules_orders_blocked[] = $url;
	return new WP_Error( 'spc_totals_offline', 'The totals tests never reach outside services' );
};
$discount_rules_orders_no = function () {
	return false;
};
add_filter( 'pre_http_request', $discount_rules_orders_offline, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', $discount_rules_orders_no, PHP_INT_MAX );
add_filter( 'sunshine_checkout_allow_order_notify', $discount_rules_orders_no, PHP_INT_MAX );

// Adding to the cart makes the Analytics add-on log a row in its own table, and for a logged in
// customer the Automated Emails add-on may queue a reminder email. Remember the products and
// queued emails this file makes, so those can be deleted at the end of this file.
$discount_rules_orders_made_products = array();
$discount_rules_orders_made_emails   = array();
$discount_rules_orders_remember      = function ( $post_id, $post ) use ( &$discount_rules_orders_made_products, &$discount_rules_orders_made_emails ) {
	if ( 'sunshine-product' === $post->post_type ) {
		$discount_rules_orders_made_products[] = (int) $post_id;
	} elseif ( 'sunshine-email-log' === $post->post_type ) {
		$discount_rules_orders_made_emails[] = (int) $post_id;
	}
};
add_action( 'wp_insert_post', $discount_rules_orders_remember, 10, 2 );

/**
 * Make a discount the way the Discounts screen saves one. That screen saves every field:
 * empty ones as '', product and gallery lists as arrays of post IDs and dates as YYYY-MM-DD.
 * $meta replaces any of these. The code is not put in the cart.
 * Returns the discount's post ID and its code.
 */
$discount_rules_orders_make = function ( $meta = array(), $name = '', $post_date = '' ) use ( $t ) {
	$t::require_plugin( 'sunshine_get_discount_by_code', 'Discount Codes add-on' );
	$code = 'SPCRULES' . wp_rand( 100000, 999999 );
	$post = array(
		'post_type'   => 'sunshine-discount',
		'post_status' => 'publish',
		'post_title'  => $name ? $name : 'Rules test ' . $code,
	);
	if ( $post_date ) {
		$post['post_date'] = $post_date;
	}
	$id = wp_insert_post( $post );
	$t::track( 'post', $id );
	$meta = array_merge(
		array(
			'auto'                  => '',
			'code'                  => $code,
			'discount_type'         => 'percent-total',
			'discount_amount'       => '10',
			'apply_to_shipping'     => '',
			'free_shipping'         => '',
			'start_date'            => '',
			'end_date'              => '',
			'solo'                  => '',
			'required_products'     => array(),
			'allowed_products'      => array(),
			'disallowed_products'   => array(),
			'allowed_categories'    => '',
			'disallowed_categories' => '',
			'allowed_galleries'     => array(),
			'disallowed_galleries'  => array(),
			'min_amount'            => '',
			'min_quantity'          => '',
			'max_uses'              => '',
			'max_uses_per_person'   => '',
			'max_product_quantity'  => '',
		),
		$meta
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return array( $id, $meta['code'] );
};

// An automatic discount. The Discounts screen hides the code field for these and saves a random
// 8 character code instead. Each one here also needs one of its test's own products in the cart,
// so it can never land in another cart on the site while it exists, and each test deletes its
// automatic discounts when it ends.
$discount_rules_orders_auto = function ( $product_id, $meta = array(), $name = '', $post_date = '' ) use ( $discount_rules_orders_make ) {
	$meta = array_merge(
		array(
			'auto'              => '1',
			'code'              => wp_generate_password( 8, false ),
			'required_products' => array( $product_id ),
		),
		$meta
	);
	return $discount_rules_orders_make( $meta, $name, $post_date );
};

$discount_rules_orders_drop = function ( $ids ) {
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
};

// What the checkout's Apply button does. True when the code was accepted.
$discount_rules_orders_enter = function ( $code ) {
	$result = SPC()->cart->add_discount( $code );
	SPC()->cart->update();
	return ! empty( $result );
};

$discount_rules_orders_codes = function () {
	return array_values( array_filter( (array) SPC()->session->get( 'discounts' ) ) );
};

// Finish an order the way a completed payment does, at checkout or from a payment webhook.
$discount_rules_orders_finish = function ( $order ) {
	SPC()->cart->post_process_order( $order );
	return sunshine_get_order( $order->get_id() );
};

$discount_rules_orders_uses = function ( $discount_id ) {
	return (int) get_post_meta( $discount_id, 'use_count', true );
};

$discount_rules_orders_log_in = function ( $customer_id ) {
	wp_set_current_user( $customer_id );
	SPC()->customer = new SPC_Customer( $customer_id );
	SPC()->session->set( 'discounts', '' );
	SPC()->cart = new SPC_Cart();
};

// A flat rate and a Free method set to "Via Discount", saved the way the Shipping settings
// save them, with the order going by shipping.
$discount_rules_orders_shipping = function ( $flat_price, $taxable = false ) use ( $t ) {
	$t::option(
		'shipping_methods',
		array(
			'spcrulesflat' => array( 'id' => 'flat_rate', 'active' => true ),
			'spcrulesfree' => array( 'id' => 'free', 'active' => true ),
		)
	);
	$t::option( 'flat_rate_price_spcrulesflat', (string) $flat_price );
	$t::option( 'flat_rate_price_type_spcrulesflat', 'cart' );
	$t::option( 'flat_rate_taxable_spcrulesflat', $taxable ? '1' : '' );
	$t::option( 'free_discount_spcrulesfree', '1' );
	SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
};

$discount_rules_orders_choose = function ( $instance ) {
	SPC()->cart->set_checkout_data_item( 'shipping_method', $instance );
};

$discount_rules_orders_offered = function ( $instance ) {
	return array_key_exists( $instance, (array) sunshine_get_allowed_shipping_methods() );
};

$discount_rules_orders_chosen = function () {
	$method = SPC()->cart->get_shipping_method();
	return $method ? $method->get_instance_id() : '';
};

// What the cart shows right before the customer places the order.
$discount_rules_orders_snapshot = function () {
	$cart  = SPC()->cart;
	$lines = array();
	foreach ( (array) $cart->get_cart_items() as $line ) {
		$lines[] = array(
			'total' => (float) $line->get_total(),
			'tax'   => (float) $line->get_tax_total(),
		);
	}
	return array(
		'subtotal' => (float) $cart->get_subtotal(),
		'discount' => (float) $cart->get_discount(),
		'shipping' => (float) $cart->get_shipping(),
		'tax'      => (float) $cart->get_tax(),
		'total'    => (float) $cart->get_total(),
		'lines'    => $lines,
	);
};

$discount_rules_orders_matches = function ( $order, $seen ) use ( $t ) {
	$t::money( $seen['subtotal'], $order->get_subtotal(), 'order subtotal' );
	$t::money( $seen['discount'], $order->get_discount(), 'order discount' );
	$t::money( $seen['shipping'], $order->get_shipping(), 'order shipping' );
	$t::money( $seen['tax'], $order->get_tax(), 'order tax' );
	$t::money( $seen['total'], $order->get_total(), 'order total' );
	$items = array_values( (array) $order->get_items() );
	$t::same( count( $seen['lines'] ), count( $items ), 'number of order lines' );
	foreach ( $seen['lines'] as $i => $line ) {
		if ( empty( $items[ $i ] ) ) {
			continue;
		}
		$t::money( $line['total'], $items[ $i ]->get_total(), 'line ' . ( $i + 1 ) . ' total' );
		$t::money( $line['tax'], $items[ $i ]->get_tax_total(), 'line ' . ( $i + 1 ) . ' tax' );
	}
};

/*
 * Automatic discounts: "the discount is applied for them at checkout and they cannot remove it".
 */

$t::test(
	'Discount rules: an automatic discount applies with no code, and only once',
	function () use ( $t, $discount_rules_orders_auto, $discount_rules_orders_drop, $discount_rules_orders_codes ) {
		$product           = $t::product( 100, array( 'taxable' => false ) );
		list( $id, $code ) = $discount_rules_orders_auto( $product );
		try {
			$t::add( $product );
			$t::calculate();
			$t::calculate();
			$cart = $t::new_request();
			$t::money( 10, $cart->get_discount(), 'discount' );
			$t::money( 90, $cart->get_total(), 'total' );
			$t::same( array( $code ), $discount_rules_orders_codes(), 'the automatic code is in the cart once' );
		} finally {
			$discount_rules_orders_drop( array( $id ) );
		}
	}
);

$t::test(
	'Discount rules: the customer cannot remove an automatic discount',
	function () use ( $t, $discount_rules_orders_auto, $discount_rules_orders_drop ) {
		$product           = $t::product( 100, array( 'taxable' => false ) );
		list( $id, $code ) = $discount_rules_orders_auto( $product );
		try {
			$t::add( $product );
			$t::calculate();
			// What the x next to the discount does.
			SPC()->cart->remove_discount( $code );
			SPC()->cart->update();
			$cart = $t::new_request();
			$t::money( 10, $cart->get_discount(), 'discount' );
			$t::money( 90, $cart->get_total(), 'total' );
		} finally {
			$discount_rules_orders_drop( array( $id ) );
		}
	}
);

$t::test(
	'Discount rules: an automatic discount with a minimum comes and goes as the cart changes',
	function () use ( $t, $discount_rules_orders_auto, $discount_rules_orders_drop ) {
		$product    = $t::product( 20, array( 'taxable' => false ) );
		list( $id ) = $discount_rules_orders_auto( $product, array( 'min_amount' => '50' ) );
		try {
			$line = $t::add( $product, 2 );
			$cart = $t::calculate();
			$t::money( 0, $cart->get_discount(), 'no discount at 40' );
			SPC()->cart->update_item_quantity_by_hash( $line['hash'], 3 );
			$cart = $t::calculate();
			$t::money( 6, $cart->get_discount(), '10% of 60' );
			$t::money( 54, $cart->get_total(), 'total at 60' );
			SPC()->cart->update_item_quantity_by_hash( $line['hash'], 2 );
			$cart = $t::new_request();
			$t::money( 0, $cart->get_discount(), 'no discount back at 40' );
			$t::money( 40, $cart->get_total(), 'total back at 40' );
			SPC()->cart->update_item_quantity_by_hash( $line['hash'], 3 );
			$cart = $t::new_request();
			$t::money( 6, $cart->get_discount(), 'discount is back at 60' );
		} finally {
			$discount_rules_orders_drop( array( $id ) );
		}
	}
);

// Photographers add automatic discounts for one-off sales over time and leave the old ones in
// place after they end. An always-on discount made before them must keep working.
$t::test(
	'Discount rules: an older automatic discount still applies when newer ones have ended',
	function () use ( $t, $discount_rules_orders_auto, $discount_rules_orders_drop ) {
		$product = $t::product( 100, array( 'taxable' => false ) );
		$ids     = array();
		$made    = $discount_rules_orders_auto( $product, array(), 'Always on 10% off', wp_date( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) );
		$ids[]   = $made[0];
		try {
			for ( $i = 1; $i <= 5; $i++ ) {
				$made  = $discount_rules_orders_auto(
					$product,
					array(
						'discount_amount' => '50',
						'end_date'        => wp_date( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ),
					),
					'Ended sale ' . $i,
					wp_date( 'Y-m-d H:i:s', time() - ( 60 - $i ) * DAY_IN_SECONDS )
				);
				$ids[] = $made[0];
			}
			$t::add( $product );
			$t::calculate();
			$cart = $t::new_request();
			$t::money( 10, $cart->get_discount(), 'the always-on 10% applies' );
			$t::money( 90, $cart->get_total(), 'total' );
		} finally {
			$discount_rules_orders_drop( $ids );
		}
	}
);

$t::test(
	'Discount rules: a solo code entered by the customer replaces an automatic discount',
	function () use ( $t, $discount_rules_orders_auto, $discount_rules_orders_drop, $discount_rules_orders_make, $discount_rules_orders_enter ) {
		$product        = $t::product( 100, array( 'taxable' => false ) );
		list( $id )     = $discount_rules_orders_auto( $product );
		list( , $solo ) = $discount_rules_orders_make( array( 'discount_amount' => '25', 'solo' => '1' ) );
		try {
			$t::add( $product );
			$t::calculate();
			$t::same( true, $discount_rules_orders_enter( $solo ), 'solo code accepted' );
			$cart = $t::new_request();
			$t::money( 25, $cart->get_discount(), 'only the solo 25%' );
			$t::money( 75, $cart->get_total(), 'total' );
		} finally {
			$discount_rules_orders_drop( array( $id ) );
		}
	}
);

/*
 * Free shipping codes. The Free method is set to "Via Discount", so it is only offered while a
 * code with "Free Shipping" is in the cart. When it is not offered, the flat rate is the only
 * method left and the cart picks it.
 */

$t::test(
	'Discount rules: free shipping "via discount" is only offered with a free shipping code',
	function () use ( $t, $discount_rules_orders_make, $discount_rules_orders_enter, $discount_rules_orders_shipping, $discount_rules_orders_choose, $discount_rules_orders_offered, $discount_rules_orders_chosen ) {
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$discount_rules_orders_shipping( 10 );
		$discount_rules_orders_choose( 'spcrulesfree' );
		$cart = $t::calculate();
		$t::same( false, $discount_rules_orders_offered( 'spcrulesfree' ), 'free shipping not offered without the code' );
		$t::same( 'spcrulesflat', $discount_rules_orders_chosen(), 'flat rate used instead' );
		$t::money( 60, $cart->get_total(), '50 + 10 shipping' );
		// A free shipping only code: no discount type or amount, as the free shipping guide says.
		list( , $code ) = $discount_rules_orders_make( array( 'discount_type' => '', 'discount_amount' => '', 'free_shipping' => '1' ) );
		$t::same( true, $discount_rules_orders_enter( $code ), 'code accepted' );
		$t::same( true, $discount_rules_orders_offered( 'spcrulesfree' ), 'free shipping offered with the code' );
		$discount_rules_orders_choose( 'spcrulesfree' );
		$cart = $t::calculate();
		$t::same( 'spcrulesfree', $discount_rules_orders_chosen(), 'free shipping chosen' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 0, $cart->get_discount(), 'nothing else taken off' );
		$t::money( 50, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Discount rules: a free shipping code with 10% off, placed as an order',
	function () use ( $t, $discount_rules_orders_make, $discount_rules_orders_enter, $discount_rules_orders_shipping, $discount_rules_orders_choose, $discount_rules_orders_snapshot, $discount_rules_orders_matches ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$discount_rules_orders_shipping( 10, true );
		list( , $code ) = $discount_rules_orders_make( array( 'free_shipping' => '1' ) );
		$t::calculate();
		$t::same( true, $discount_rules_orders_enter( $code ), 'code accepted' );
		$discount_rules_orders_choose( 'spcrulesfree' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 10% of 100, free shipping, tax on 90.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 9, $cart->get_tax(), 'tax' );
		$t::money( 99, $cart->get_total(), 'total' );
		$seen  = $discount_rules_orders_snapshot();
		$order = $t::place_order();
		$discount_rules_orders_matches( $order, $seen );
		$t::same( 'spcrulesfree', $order->get_shipping_method(), 'free shipping saved on the order' );
		$t::same( array( $code ), array_values( array_filter( (array) $order->get_discounts() ) ), 'code saved on the order' );
	}
);

$t::test(
	'Discount rules: free shipping goes away when the code stops being valid',
	function () use ( $t, $discount_rules_orders_make, $discount_rules_orders_enter, $discount_rules_orders_shipping, $discount_rules_orders_choose, $discount_rules_orders_chosen, $discount_rules_orders_codes ) {
		$line = $t::add( $t::product( 30, array( 'taxable' => false ) ), 2 );
		$discount_rules_orders_shipping( 10 );
		list( , $code ) = $discount_rules_orders_make( array( 'discount_type' => '', 'discount_amount' => '', 'free_shipping' => '1', 'min_amount' => '50' ) );
		$t::calculate();
		$t::same( true, $discount_rules_orders_enter( $code ), 'code accepted at 60' );
		$discount_rules_orders_choose( 'spcrulesfree' );
		$cart = $t::calculate();
		$t::money( 60, $cart->get_total(), 'total with free shipping' );
		// The customer drops to one print, under the code's 50 minimum.
		SPC()->cart->update_item_quantity_by_hash( $line['hash'], 1 );
		$cart = $t::new_request();
		$t::same( false, in_array( $code, $discount_rules_orders_codes(), true ), 'code taken off the cart' );
		$t::same( 'spcrulesflat', $discount_rules_orders_chosen(), 'back on the flat rate' );
		$t::money( 10, $cart->get_shipping(), 'shipping' );
		$t::money( 40, $cart->get_total(), '30 + 10' );
	}
);

$t::test(
	'Discount rules: automatic free shipping on orders of 75 or more',
	function () use ( $t, $discount_rules_orders_auto, $discount_rules_orders_drop, $discount_rules_orders_shipping, $discount_rules_orders_choose, $discount_rules_orders_chosen, $discount_rules_orders_offered ) {
		$product    = $t::product( 40, array( 'taxable' => false ) );
		list( $id ) = $discount_rules_orders_auto(
			$product,
			array(
				'discount_type'   => '',
				'discount_amount' => '',
				'free_shipping'   => '1',
				'min_amount'      => '75',
			)
		);
		try {
			$discount_rules_orders_shipping( 10 );
			$line = $t::add( $product, 2 );
			$t::calculate();
			$t::same( true, $discount_rules_orders_offered( 'spcrulesfree' ), 'free shipping offered at 80' );
			$discount_rules_orders_choose( 'spcrulesfree' );
			$cart = $t::new_request();
			$t::same( 'spcrulesfree', $discount_rules_orders_chosen(), 'free shipping chosen' );
			$t::money( 80, $cart->get_total(), 'total at 80, no shipping' );
			SPC()->cart->update_item_quantity_by_hash( $line['hash'], 1 );
			$cart = $t::new_request();
			$t::same( 'spcrulesflat', $discount_rules_orders_chosen(), 'back on the flat rate at 40' );
			$t::money( 50, $cart->get_total(), '40 + 10' );
		} finally {
			$discount_rules_orders_drop( array( $id ) );
		}
	}
);

/*
 * Use limits. "Max uses" counts every customer; "Max uses per person" counts one logged in
 * customer. Uses are counted when the order is paid.
 */

$t::test(
	'Discount rules: a code with one use left stops working once it is used',
	function () use ( $t, $discount_rules_orders_make, $discount_rules_orders_enter, $discount_rules_orders_finish, $discount_rules_orders_uses ) {
		list( $id, $code ) = $discount_rules_orders_make( array( 'max_uses' => '1' ) );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $discount_rules_orders_enter( $code ), 'first customer: accepted' );
		$order = $t::place_order();
		$t::money( 5, $order->get_discount(), 'discount on the order' );
		$discount_rules_orders_finish( $order );
		$t::same( 1, $discount_rules_orders_uses( $id ), 'use counted' );
		// The next customer tries the same code.
		SPC()->session->set( 'discounts', '' );
		$t::new_request();
		$t::same( false, $discount_rules_orders_enter( $code ), 'next customer: refused' );
		$t::money( 0, SPC()->cart->get_discount(), 'no discount' );
	}
);

$t::test(
	'Discount rules: max uses per person of 1, the same customer cannot use it again',
	function () use ( $t, $discount_rules_orders_make, $discount_rules_orders_enter, $discount_rules_orders_finish ) {
		$t::customer();
		list( , $code ) = $discount_rules_orders_make( array( 'max_uses_per_person' => '1' ) );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $discount_rules_orders_enter( $code ), 'first use accepted' );
		$discount_rules_orders_finish( $t::place_order() );
		$cart = $t::new_request();
		$t::money( 0, $cart->get_discount(), 'code dropped on the next order' );
		$t::same( false, $discount_rules_orders_enter( $code ), 'entering it again is refused' );
	}
);

$t::test(
	'Discount rules: max uses per person of 2, a third use is refused',
	function () use ( $t, $discount_rules_orders_make, $discount_rules_orders_enter, $discount_rules_orders_finish, $discount_rules_orders_uses ) {
		$t::customer();
		list( $id, $code ) = $discount_rules_orders_make( array( 'max_uses_per_person' => '2' ) );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $discount_rules_orders_enter( $code ), 'first use accepted' );
		$discount_rules_orders_finish( $t::place_order() );
		$cart = $t::new_request();
		$t::money( 5, $cart->get_discount(), 'second order still gets it' );
		$discount_rules_orders_finish( $t::place_order() );
		$t::same( 2, $discount_rules_orders_uses( $id ), 'two uses counted' );
		$cart = $t::new_request();
		$t::money( 0, $cart->get_discount(), 'no discount on a third order' );
		// Typing it in again must not work either. Take it off first, so the check is not just
		// "this code is already in the cart".
		SPC()->cart->remove_discount( $code );
		SPC()->cart->update();
		$t::same( false, $discount_rules_orders_enter( $code ), 'third use refused' );
	}
);

$t::test(
	'Discount rules: max uses per person, another customer using the code does not reset it',
	function () use ( $t, $discount_rules_orders_make, $discount_rules_orders_enter, $discount_rules_orders_finish, $discount_rules_orders_log_in ) {
		$product        = $t::product( 50, array( 'taxable' => false ) );
		list( , $code ) = $discount_rules_orders_make( array( 'max_uses_per_person' => '1' ) );
		$first          = $t::customer();
		$t::add( $product );
		$t::calculate();
		$t::same( true, $discount_rules_orders_enter( $code ), 'first customer: accepted' );
		$discount_rules_orders_finish( $t::place_order() );
		$t::customer();
		SPC()->session->set( 'discounts', '' );
		$t::add( $product );
		$t::calculate();
		$t::same( true, $discount_rules_orders_enter( $code ), 'second customer: accepted' );
		$discount_rules_orders_finish( $t::place_order() );
		// The first customer comes back.
		$discount_rules_orders_log_in( $first );
		$t::calculate();
		$t::same( false, $discount_rules_orders_enter( $code ), 'first customer: still refused' );
		$t::money( 0, SPC()->cart->get_discount(), 'no discount for the first customer' );
	}
);

/*
 * Placed orders.
 */

// The customer goes to pay, comes back, takes the code off and places the order again. The
// same unpaid order is saved again, so it must lose the code too, or the code is counted as
// used and shown on the receipt.
$t::test(
	'Discount rules: a code taken off before placing the order again is not kept on the order',
	function () use ( $t, $discount_rules_orders_make, $discount_rules_orders_enter, $discount_rules_orders_finish, $discount_rules_orders_uses ) {
		list( $id, $code ) = $discount_rules_orders_make();
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $discount_rules_orders_enter( $code ), 'code accepted' );
		$first = $t::place_order();
		$t::money( 5, $first->get_discount(), 'first try has the discount' );
		SPC()->cart->remove_discount( $code );
		SPC()->cart->update();
		$t::new_request();
		$order = $t::place_order();
		$t::same( $first->get_id(), $order->get_id(), 'the same unpaid order is used again' );
		$t::money( 0, $order->get_discount(), 'no discount on the order' );
		$t::money( 50, $order->get_total(), 'total' );
		$t::same( array(), array_values( array_filter( (array) $order->get_discounts() ) ), 'no codes on the order' );
		$t::same( array(), array_values( array_filter( (array) $order->get_discount_names() ) ), 'no discount names on the order' );
		$discount_rules_orders_finish( $order );
		$t::same( 0, $discount_rules_orders_uses( $id ), 'the removed code is not counted as used' );
	}
);

// Photographers clear out old codes (there is a bulk delete tool for it). A customer who was
// already paying with one must still get a finished order.
$t::test(
	'Discount rules: an order still finishes when its code was deleted while the customer was paying',
	function () use ( $t, $discount_rules_orders_make, $discount_rules_orders_enter, $discount_rules_orders_finish ) {
		list( $id, $code ) = $discount_rules_orders_make();
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $discount_rules_orders_enter( $code ), 'code accepted' );
		$order = $t::place_order();
		wp_trash_post( $id );
		try {
			$order = $discount_rules_orders_finish( $order );
		} catch ( Throwable $e ) {
			$t::same( 'finished', 'threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')', 'finishing the order' );
			return;
		}
		$t::same( true, (bool) get_post_meta( $order->get_id(), 'post_processed', true ), 'order finished' );
		$t::money( 5, $order->get_discount(), 'discount kept on the order' );
		$t::money( 45, $order->get_total(), 'total' );
	}
);

$t::test(
	'Discount rules: a solo code entered after another code, placed as an order',
	function () use ( $t, $discount_rules_orders_make, $discount_rules_orders_enter, $discount_rules_orders_snapshot, $discount_rules_orders_matches ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		list( , $other ) = $discount_rules_orders_make( array( 'discount_type' => 'amount-total', 'discount_amount' => '5' ), 'Five dollars off' );
		list( , $solo )  = $discount_rules_orders_make( array( 'discount_amount' => '20', 'solo' => '1' ), 'Solo twenty percent' );
		$t::calculate();
		$discount_rules_orders_enter( $other );
		$t::same( true, $discount_rules_orders_enter( $solo ), 'solo code accepted' );
		$t::checkout_address();
		$t::calculate();
		$seen  = $discount_rules_orders_snapshot();
		$order = $t::place_order();
		$discount_rules_orders_matches( $order, $seen );
		$t::money( 20, $order->get_discount(), 'only the solo 20%' );
		$t::same( array( $solo ), array_values( array_filter( (array) $order->get_discounts() ) ), 'only the solo code on the order' );
		$t::same( array( 'Solo twenty percent' ), array_values( (array) $order->get_discount_names() ), 'discount names on the order and receipt' );
	}
);

$t::test(
	'Discount rules: prices include tax and discount after tax with taxed shipping, placed as an order',
	function () use ( $t, $discount_rules_orders_make, $discount_rules_orders_enter, $discount_rules_orders_snapshot, $discount_rules_orders_matches ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 110 ) );
		$t::flat_shipping( 11, true );
		list( , $code ) = $discount_rules_orders_make( array( 'apply_to_shipping' => '1' ) );
		$t::calculate();
		$t::same( true, $discount_rules_orders_enter( $code ), 'code accepted' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 10% of ( 110 + 11 ). Tax stays what the full prices hold: 10 + 1.
		$t::money( 12.10, $cart->get_discount(), 'discount' );
		$t::money( 11, $cart->get_tax(), 'tax' );
		$t::money( 108.90, $cart->get_total(), 'total' );
		$seen  = $discount_rules_orders_snapshot();
		$order = $t::place_order();
		$discount_rules_orders_matches( $order, $seen );
	}
);

remove_filter( 'pre_http_request', $discount_rules_orders_offline, PHP_INT_MAX );
remove_filter( 'pre_wp_mail', $discount_rules_orders_no, PHP_INT_MAX );
remove_filter( 'sunshine_checkout_allow_order_notify', $discount_rules_orders_no, PHP_INT_MAX );
remove_action( 'wp_insert_post', $discount_rules_orders_remember, 10 );
foreach ( array_unique( $discount_rules_orders_made_emails ) as $discount_rules_orders_email_id ) {
	wp_delete_post( $discount_rules_orders_email_id, true );
}
if ( ! empty( $discount_rules_orders_made_products ) ) {
	global $wpdb;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sunshine_actions' ) ) ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_actions WHERE action_type = 'add_to_cart' AND action_object_id IN (" . implode( ',', array_map( 'intval', array_unique( $discount_rules_orders_made_products ) ) ) . ')' );
	}
}
if ( ! empty( $discount_rules_orders_blocked ) ) {
	WP_CLI::log( 'core-discount-rules-orders blocked outgoing requests to: ' . implode( ', ', array_unique( $discount_rules_orders_blocked ) ) );
}
