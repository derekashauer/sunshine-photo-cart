<?php
/**
 * Gift Cards add-on: buying a gift card, and the amount rules on the gift card product.
 *
 * The gift card product has three settings (from the Gift Cards docs): Preset Amounts,
 * Allow Custom Amounts, and Maximum Amount, which is "the highest custom amount a customer
 * can enter. Only applies when custom amounts are allowed."
 *
 * These tests send the same request the Gift Cards page's form sends (the
 * [sunshine_buy_gift_card] shortcode posts to sunshine_modal_add_item_to_cart), so the add-on's
 * amount checks (sunshine_gift_card_validate_before_add) run before the item is added.
 *
 * The gift card product is saved the way the product edit screen saves it: no price at any
 * price level (the price box is hidden for gift cards), not taxable, and the three gift card
 * fields as typed.
 *
 * Nothing leaves the site: every web request and email (the gift card email too) is stopped.
 */

$t = 'SPC_Totals_Test';

$gift_card_entry_buy_warnings = array();
$gift_card_entry_buy_blocked  = array();
$gift_card_entry_buy_made     = array();
$gift_card_entry_buy_orders   = array();

// Remember what is made while this file runs: gift cards made by paid orders, products for the
// Analytics rows, and email log rows.
$gift_card_entry_buy_remember = function ( $post_id, $post ) use ( $t, &$gift_card_entry_buy_made ) {
	if ( in_array( $post->post_type, array( 'sunshine-product', 'sunshine-gift-card', 'sunshine-email-log' ), true ) ) {
		$gift_card_entry_buy_made[ $post->post_type ][] = (int) $post_id;
		if ( 'sunshine-gift-card' === $post->post_type ) {
			$t::track( 'post', $post_id );
		}
	}
};
add_action( 'wp_insert_post', $gift_card_entry_buy_remember, 10, 2 );

$gift_card_entry_buy_test = function ( $name, $body ) use ( $t, &$gift_card_entry_buy_blocked ) {
	$t::test(
		$name,
		function () use ( $t, $body, &$gift_card_entry_buy_blocked ) {
			$t::require_plugin( 'sunshine_gift_card_validate_before_add', 'Gift Cards add-on' );
			$offline = function ( $pre, $args, $url ) use ( &$gift_card_entry_buy_blocked ) {
				$gift_card_entry_buy_blocked[] = $url;
				return new WP_Error( 'spc_totals_offline', 'The totals tests never reach outside services' );
			};
			$no      = function () {
				return false;
			};
			add_filter( 'pre_http_request', $offline, PHP_INT_MAX, 3 );
			add_filter( 'pre_wp_mail', $no, PHP_INT_MAX );
			add_filter( 'sunshine_checkout_allow_order_notify', $no, PHP_INT_MAX );
			try {
				$body();
			} finally {
				remove_filter( 'pre_http_request', $offline, PHP_INT_MAX );
				remove_filter( 'pre_wp_mail', $no, PHP_INT_MAX );
				remove_filter( 'sunshine_checkout_allow_order_notify', $no, PHP_INT_MAX );
			}
		}
	);
};

$gift_card_entry_buy_watch = function ( $callback ) use ( &$gift_card_entry_buy_warnings ) {
	set_error_handler(
		function ( $errno, $errstr, $errfile, $errline ) use ( &$gift_card_entry_buy_warnings ) {
			if ( error_reporting() & $errno ) {
				$gift_card_entry_buy_warnings[] = $errstr . ' (' . basename( $errfile ) . ':' . $errline . ')';
			}
			return false;
		}
	);
	try {
		return $callback();
	} finally {
		restore_error_handler();
	}
};

/**
 * A gift card product saved from the product edit screen. $presets is the Preset Amounts text,
 * $custom the Allow Custom Amount box ('1' or ''), $max the Maximum Custom Amount as typed.
 */
$gift_card_entry_buy_product = function ( $presets, $custom, $max ) use ( $t ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-product',
			'post_status' => 'publish',
			'post_title'  => 'Gift card entry test card',
		)
	);
	if ( ! $id || is_wp_error( $id ) ) {
		throw new Exception( 'Could not make the gift card product' );
	}
	$t::track( 'post', $id );
	$prices   = array();
	$shipping = array();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		if ( $level ) {
			$prices[ $level->get_id() ]   = '';
			$shipping[ $level->get_id() ] = '';
		}
	}
	$meta = array(
		'type'                     => 'gift-card',
		'price'                    => $prices,
		'taxable'                  => '',
		'disable_shipping'         => '',
		'shipping'                 => $shipping,
		'min_qty'                  => '',
		'max_qty'                  => '',
		'gift_card_preset_amounts' => $presets,
		'gift_card_allow_custom'   => $custom,
		'gift_card_max_amount'     => $max,
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
};

/**
 * The Gift Cards page form, sent the way its script sends it: every field in the form, the
 * chosen amount in gift_card_amount, and the store's security code. $choice is a preset amount
 * or 'other' for the custom amount box.
 */
$gift_card_entry_buy_send = function ( $product_id, $amount, $choice = null, $extra = array() ) use ( $gift_card_entry_buy_watch ) {
	$post = array_merge(
		array(
			'product_id'                => $product_id,
			'price_level'               => sunshine_get_default_price_level_id(),
			'gift_card_amount_type'     => null === $choice ? (string) $amount : $choice,
			'gift_card_custom_amount'   => 'other' === $choice ? (string) $amount : '',
			'gift_card_amount'          => (string) $amount,
			'gift_card_delivery'        => 'immediate',
			'gift_card_send_date'       => '',
			'gift_card_recipient_name'  => 'Gift Test',
			'gift_card_recipient_email' => 'gift-entry-test@example.com',
			'gift_card_message'         => '',
			'action'                    => 'sunshine_modal_add_item_to_cart',
			'security'                  => wp_create_nonce( 'sunshinephotocart' ),
		),
		$extra
	);
	$saved_post = $_POST;
	$_POST      = $post;
	$ajax       = '__return_true';
	$die        = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'wp_doing_ajax', $ajax );
	add_filter( 'wp_die_ajax_handler', $die );
	$output = '';
	ob_start();
	try {
		$gift_card_entry_buy_watch(
			function () {
				do_action( ( is_user_logged_in() ? 'wp_ajax_' : 'wp_ajax_nopriv_' ) . 'sunshine_modal_add_item_to_cart' );
			}
		);
	} catch ( SPC_Totals_Test_Done $e ) {
		// Every handler ends by sending JSON.
	} finally {
		$output = ob_get_clean();
		$_POST  = $saved_post;
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );
	}
	$response = json_decode( $output, true );
	if ( ! is_array( $response ) ) {
		$response = array(
			'success' => false,
			'raw'     => substr( $output, 0, 300 ),
		);
	}
	return $response;
};

$gift_card_entry_buy_ok = function ( $response ) {
	return ! empty( $response['success'] );
};

$gift_card_entry_buy_lines = function () {
	$items = SPC()->cart->get_cart_items();
	return $items ? array_values( $items ) : array();
};

// Run a page handler that ends by redirecting, and stop at the redirect instead of exiting.
$gift_card_entry_buy_redirected = function ( $callback ) use ( $gift_card_entry_buy_watch ) {
	$stop = function ( $location ) {
		throw new Exception( 'gce-redirect:' . $location );
	};
	add_filter( 'wp_redirect', $stop, -1000 );
	$location = '';
	try {
		$gift_card_entry_buy_watch( $callback );
	} catch ( Exception $e ) {
		if ( 0 !== strpos( $e->getMessage(), 'gce-redirect:' ) ) {
			throw $e;
		}
		$location = substr( $e->getMessage(), strlen( 'gce-redirect:' ) );
	} finally {
		remove_filter( 'wp_redirect', $stop, -1000 );
	}
	return $location;
};

$gift_card_entry_buy_place_and_finish = function () use ( $t, &$gift_card_entry_buy_orders, $gift_card_entry_buy_watch ) {
	SPC()->cart->setup( true );
	$order                        = $gift_card_entry_buy_watch(
		function () use ( $t ) {
			return $t::place_order();
		}
	);
	$gift_card_entry_buy_orders[] = $order->get_id();
	$placed_total                 = (float) $order->get_total();
	SPC()->cart                   = new SPC_Cart();
	SPC()->cart->setup( true );
	$finished = $gift_card_entry_buy_watch(
		function () {
			return SPC()->cart->process_order();
		}
	);
	if ( ! $finished ) {
		throw new Exception( 'The order was placed but not finished: ' . wp_json_encode( SPC()->cart->get_errors() ) );
	}
	return array( sunshine_get_order( $order->get_id() ), $placed_total );
};

/*
 * Preset amounts.
 */

$gift_card_entry_buy_test(
	'Buying a gift card: a preset amount is added at that amount, with no tax',
	function () use ( $t, $gift_card_entry_buy_product, $gift_card_entry_buy_send, $gift_card_entry_buy_ok, $gift_card_entry_buy_lines ) {
		$t::tax( 10 );
		$product  = $gift_card_entry_buy_product( '25,50,100', '', '' );
		$response = $gift_card_entry_buy_send( $product, 50 );
		$t::same( true, $gift_card_entry_buy_ok( $response ), 'added to the cart' );
		$cart  = $t::new_request();
		$lines = $gift_card_entry_buy_lines();
		$t::same( 1, count( $lines ), 'one line' );
		$t::money( 50, $lines ? $lines[0]->get_price() : 0, 'line price' );
		// Gift cards are never taxed (docs: "No tax is calculated on gift card purchases").
		$t::money( 0, $cart->get_tax(), 'tax' );
		$t::money( 50, $cart->get_total(), 'total' );
	}
);

$gift_card_entry_buy_test(
	'Buying a gift card: prices include tax, a preset amount costs exactly that amount',
	function () use ( $t, $gift_card_entry_buy_product, $gift_card_entry_buy_send, $gift_card_entry_buy_ok ) {
		$t::option( 'price_has_tax', 'yes' );
		$t::tax( 10 );
		$product  = $gift_card_entry_buy_product( '25,50,100', '', '' );
		$response = $gift_card_entry_buy_send( $product, 100 );
		$t::same( true, $gift_card_entry_buy_ok( $response ), 'added to the cart' );
		$cart = $t::new_request();
		$t::money( 0, $cart->get_tax(), 'no tax inside the price' );
		$t::money( 100, $cart->get_total(), 'total' );
	}
);

// Presets typed with cents. The form's radio button sends the amount as PHP prints it (12.5).
$gift_card_entry_buy_test(
	'Buying a gift card: a preset amount with cents',
	function () use ( $t, $gift_card_entry_buy_product, $gift_card_entry_buy_send, $gift_card_entry_buy_ok ) {
		$product  = $gift_card_entry_buy_product( '12.50, 25, 50', '', '' );
		$response = $gift_card_entry_buy_send( $product, '12.5' );
		$t::same( true, $gift_card_entry_buy_ok( $response ), 'added to the cart' );
		$cart = $t::new_request();
		$t::money( 12.50, $cart->get_total(), 'total' );
	}
);

$gift_card_entry_buy_test(
	'Buying a gift card: an amount that is not a preset is refused when custom amounts are off',
	function () use ( $t, $gift_card_entry_buy_product, $gift_card_entry_buy_send, $gift_card_entry_buy_ok ) {
		$product  = $gift_card_entry_buy_product( '25,50,100', '', '' );
		$response = $gift_card_entry_buy_send( $product, 75 );
		$t::same( false, $gift_card_entry_buy_ok( $response ), 'refused' );
		$cart = $t::new_request();
		$t::same( true, $cart->is_empty(), 'cart stays empty' );
		$t::money( 0, $cart->get_total(), 'total' );
	}
);

/*
 * Custom amounts and the maximum.
 */

$gift_card_entry_buy_test(
	'Buying a gift card: a custom amount is accepted when custom amounts are on',
	function () use ( $t, $gift_card_entry_buy_product, $gift_card_entry_buy_send, $gift_card_entry_buy_ok ) {
		$product  = $gift_card_entry_buy_product( '25,50,100', '1', '1000' );
		$response = $gift_card_entry_buy_send( $product, 75, 'other' );
		$t::same( true, $gift_card_entry_buy_ok( $response ), 'added to the cart' );
		$cart = $t::new_request();
		$t::money( 75, $cart->get_total(), 'total' );
	}
);

$gift_card_entry_buy_test(
	'Buying a gift card: a custom amount over the maximum is refused, the maximum itself is fine',
	function () use ( $t, $gift_card_entry_buy_product, $gift_card_entry_buy_send, $gift_card_entry_buy_ok ) {
		$product = $gift_card_entry_buy_product( '25,50,100', '1', '300' );
		$t::same( false, $gift_card_entry_buy_ok( $gift_card_entry_buy_send( $product, '300.01', 'other' ) ), 'one cent over is refused' );
		$t::same( false, $gift_card_entry_buy_ok( $gift_card_entry_buy_send( $product, 5000, 'other' ) ), 'far over is refused' );
		$cart = $t::new_request();
		$t::same( true, $cart->is_empty(), 'nothing added' );
		$t::same( true, $gift_card_entry_buy_ok( $gift_card_entry_buy_send( $product, 300, 'other' ) ), 'exactly the maximum is accepted' );
		$cart = $t::new_request();
		$t::money( 300, $cart->get_total(), 'total' );
	}
);

// The docs say the maximum "only applies when custom amounts are allowed" and is "the highest
// custom amount a customer can enter". A preset the photographer chose is not a custom amount.
$gift_card_entry_buy_test(
	'Buying a gift card: a preset amount above the custom maximum can still be bought',
	function () use ( $t, $gift_card_entry_buy_product, $gift_card_entry_buy_send, $gift_card_entry_buy_ok ) {
		$product  = $gift_card_entry_buy_product( '25,50,500', '1', '200' );
		$response = $gift_card_entry_buy_send( $product, 500 );
		$t::same( true, $gift_card_entry_buy_ok( $response ), 'the 500 preset is added' );
		$cart = $t::new_request();
		$t::money( 500, $cart->get_total(), 'total' );
	}
);

$gift_card_entry_buy_test(
	'Buying a gift card: with no maximum set, a large custom amount is accepted',
	function () use ( $t, $gift_card_entry_buy_product, $gift_card_entry_buy_send, $gift_card_entry_buy_ok ) {
		$product  = $gift_card_entry_buy_product( '25,50,100', '1', '' );
		$response = $gift_card_entry_buy_send( $product, 2500, 'other' );
		$t::same( true, $gift_card_entry_buy_ok( $response ), 'added to the cart' );
		$cart = $t::new_request();
		$t::money( 2500, $cart->get_total(), 'total' );
	}
);

$gift_card_entry_buy_test(
	'Buying a gift card: a zero, negative or empty amount is refused',
	function () use ( $t, $gift_card_entry_buy_product, $gift_card_entry_buy_send, $gift_card_entry_buy_ok ) {
		$product = $gift_card_entry_buy_product( '25,50,100', '1', '1000' );
		foreach ( array( '0', '-50', '', 'abc' ) as $amount ) {
			$t::same( false, $gift_card_entry_buy_ok( $gift_card_entry_buy_send( $product, $amount, 'other' ) ), 'amount "' . $amount . '" refused' );
		}
		$cart = $t::new_request();
		$t::same( true, $cart->is_empty(), 'nothing added' );
	}
);

/*
 * The card that is made when the order is paid.
 */

$gift_card_entry_buy_test(
	'Buying a gift card: the card made by the paid order is worth what the buyer paid for it',
	function () use ( $t, $gift_card_entry_buy_product, $gift_card_entry_buy_send, $gift_card_entry_buy_ok, $gift_card_entry_buy_place_and_finish ) {
		$t::tax( 10 );
		$product = $gift_card_entry_buy_product( '25,50,100', '1', '1000' );
		$t::add( $t::product( 20 ) );
		$t::same( true, $gift_card_entry_buy_ok( $gift_card_entry_buy_send( $product, 80, 'other' ) ), 'gift card added' );
		$cart = $t::new_request();
		// 20 print + 2 tax + 80 gift card (not taxed) = 102.
		$t::money( 2, $cart->get_tax(), 'tax only on the print' );
		$t::money( 102, $cart->get_total(), 'total' );
		list( $order, $placed ) = $gift_card_entry_buy_place_and_finish();
		$t::money( 102, $placed, 'order total' );
		$t::same( true, $order->is_paid(), 'order is paid' );
		$card_id = 0;
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta_value( 'gift_card_id' ) ) {
				$card_id = (int) $item->get_meta_value( 'gift_card_id' );
			}
		}
		$t::same( true, $card_id > 0, 'a gift card was made' );
		if ( $card_id ) {
			$t::money( 80, get_post_meta( $card_id, 'balance', true ), 'new card balance' );
			$t::money( 80, get_post_meta( $card_id, 'original_amount', true ), 'new card original amount' );
			$t::same( $order->get_id(), (int) get_post_meta( $card_id, 'purchase_order_id', true ), 'card points to the order' );
		}
	}
);

// A customer can pay for a new gift card with a card they already have. The old card pays
// part, the customer pays the rest, and the new card is worth the full amount bought.
$gift_card_entry_buy_test(
	'Buying a gift card: paying for it partly with another gift card',
	function () use ( $t, $gift_card_entry_buy_product, $gift_card_entry_buy_send, $gift_card_entry_buy_ok, $gift_card_entry_buy_place_and_finish, $gift_card_entry_buy_watch ) {
		$product = $gift_card_entry_buy_product( '25,50,100', '', '' );
		$t::same( true, $gift_card_entry_buy_ok( $gift_card_entry_buy_send( $product, 50 ) ), 'gift card added' );
		$t::new_request();
		// An existing card from the Gift Cards admin screen, typed into the checkout's code box.
		$old_code = sunshine_generate_gift_card_code();
		$old_card = wp_insert_post(
			array(
				'post_type'   => 'sunshine-gift-card',
				'post_status' => 'publish',
				'post_title'  => 'Gift card entry test',
			)
		);
		$t::track( 'post', $old_card );
		foreach ( array( 'code' => $old_code, 'original_amount' => '30', 'balance' => '30', 'purchase_order_id' => '', 'recipient_email' => '' ) as $key => $value ) {
			update_post_meta( $old_card, $key, $value );
		}
		$saved_post = $_POST;
		$_POST      = array(
			'action'        => 'sunshine_checkout_discount_code',
			'discount_code' => $old_code,
			'security'      => wp_create_nonce( 'sunshine-checkout-discount-code' ),
		);
		$ajax       = '__return_true';
		$die        = function () {
			return function () {
				throw new SPC_Totals_Test_Done();
			};
		};
		add_filter( 'wp_doing_ajax', $ajax );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			$gift_card_entry_buy_watch(
				function () {
					do_action( 'wp_ajax_nopriv_sunshine_checkout_discount_code' );
				}
			);
		} catch ( SPC_Totals_Test_Done $e ) {
			// The handler ends by sending JSON.
		} finally {
			$reply = json_decode( ob_get_clean(), true );
			$_POST = $saved_post;
			remove_filter( 'wp_doing_ajax', $ajax );
			remove_filter( 'wp_die_ajax_handler', $die );
		}
		$t::same( true, ! empty( $reply['success'] ), 'old card accepted' );
		$cart = $t::new_request();
		// 50 card bought. The old card pays 30, the customer pays 20.
		$t::money( 20, $cart->get_total(), 'left to pay' );
		list( $order, $placed ) = $gift_card_entry_buy_place_and_finish();
		$t::money( 20, $placed, 'order total' );
		$t::money( 0, get_post_meta( $old_card, 'balance', true ), 'old card used up' );
		$new_card = 0;
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta_value( 'gift_card_id' ) ) {
				$new_card = (int) $item->get_meta_value( 'gift_card_id' );
			}
		}
		$t::same( true, $new_card > 0, 'a new gift card was made' );
		$t::money( 50, $new_card ? get_post_meta( $new_card, 'balance', true ) : 0, 'new card is worth the 50 bought' );
	}
);

/*
 * Other ways into the cart.
 */

// The amount rules only run on the Gift Cards page's request. The add to cart link
// (?sunshine_action=add_to_cart&product_id=...) adds the product too, and the add-on's own
// filter takes the amount from the posted form. A form posted to that link must follow the
// same rules: here a 5,000 card from a product that only sells 25, 50 or 100.
$gift_card_entry_buy_test(
	'Buying a gift card: the add to cart link follows the same amount rules',
	function () use ( $t, $gift_card_entry_buy_product, $gift_card_entry_buy_redirected ) {
		$product   = $gift_card_entry_buy_product( '25,50,100', '', '' );
		$saved_get = $_GET;
		$saved_pst = $_POST;
		$had_uri   = isset( $_SERVER['REQUEST_URI'] );
		$saved_uri = $had_uri ? $_SERVER['REQUEST_URI'] : null;
		$_GET      = array(
			'sunshine_action' => 'add_to_cart',
			'product_id'      => $product,
		);
		$_POST     = array(
			'gift_card_amount'   => '5000',
			'gift_card_delivery' => 'immediate',
		);
		$_SERVER['REQUEST_URI'] = '/?' . http_build_query( $_GET );
		try {
			$gift_card_entry_buy_redirected( 'sunshine_add_to_cart_url' );
		} finally {
			$_GET  = $saved_get;
			$_POST = $saved_pst;
			if ( $had_uri ) {
				$_SERVER['REQUEST_URI'] = $saved_uri;
			} else {
				unset( $_SERVER['REQUEST_URI'] );
			}
		}
		$cart = $t::new_request();
		$t::same( true, $cart->is_empty(), 'a 5,000 card is not added' );
		$t::money( 0, $cart->get_total(), 'total' );
	}
);

remove_action( 'wp_insert_post', $gift_card_entry_buy_remember, 10 );
foreach ( isset( $gift_card_entry_buy_made['sunshine-email-log'] ) ? array_unique( $gift_card_entry_buy_made['sunshine-email-log'] ) : array() as $gift_card_entry_buy_email_id ) {
	wp_delete_post( $gift_card_entry_buy_email_id, true );
}
global $wpdb;
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sunshine_actions' ) ) ) {
	if ( ! empty( $gift_card_entry_buy_made['sunshine-product'] ) ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_actions WHERE action_type = 'add_to_cart' AND action_object_id IN (" . implode( ',', array_map( 'intval', array_unique( $gift_card_entry_buy_made['sunshine-product'] ) ) ) . ')' );
	}
	if ( ! empty( $gift_card_entry_buy_orders ) ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_actions WHERE action_type = 'order' AND action_object_id IN (" . implode( ',', array_map( 'intval', array_unique( $gift_card_entry_buy_orders ) ) ) . ')' );
	}
}
foreach ( array_unique( $gift_card_entry_buy_blocked ) as $gift_card_entry_buy_url ) {
	WP_CLI::log( 'BLOCKED REQUEST (gift-card-entry-buy) ' . $gift_card_entry_buy_url );
}
foreach ( array_unique( $gift_card_entry_buy_warnings ) as $gift_card_entry_buy_warning ) {
	WP_CLI::log( 'PHP WARNING (gift-card-entry-buy) ' . $gift_card_entry_buy_warning );
}
