<?php
/**
 * Stores that write money with a decimal comma: 12,50 for twelve and a half, and 1.250,00 for
 * one thousand two hundred fifty (Sunshine > Settings > General: Decimal separator "," and
 * Thousands separator ".").
 *
 * Product prices, the WHCC markup box, and the boxes that are number boxes (discount amounts and
 * minimums, payment fees, quantity discount tiers). A browser always sends a number box with a
 * dot (2.5), whatever the store's separators are, so those are checked to keep working.
 * Shipping prices and Extra Shipping Cost are in decimal-comma-shipping.php. Option prices and
 * the Price Levels screen are in core-products-options.php and core-products.php.
 */

$t = 'SPC_Totals_Test';

// Every test here runs in a store that writes money with a decimal comma.
$decimal_comma_prod_test = function ( $name, $callback ) use ( $t ) {
	$t::test(
		'Decimal comma: ' . $name,
		function () use ( $t, $callback ) {
			$t::option( 'currency', 'EUR' );
			$t::option( 'currency_thousands_separator', '.' );
			$t::option( 'currency_decimal_separator', ',' );
			$callback();
		}
	);
};

// The product edit screen's meta box code, made without its constructor so it hooks nothing.
// Only its own save and display steps are used.
$decimal_comma_prod_box = function () {
	return ( new ReflectionClass( 'Sunshine_Admin_Meta_Boxes' ) )->newInstanceWithoutConstructor();
};

// Save one field of the product edit screen the way save_meta_boxes() does: the field type's
// clean-up (sanitize), then the field's _validate filter, then save. $posted is what the
// browser sends for the field.
$decimal_comma_prod_save = function ( $product_id, $field_id, $type, $posted ) use ( $decimal_comma_prod_box ) {
	$value = $decimal_comma_prod_box()->sanitize( $posted, $type );
	$value = apply_filters( 'sunshine_meta_' . $field_id . '_validate', $value );
	delete_post_meta( $product_id, $field_id );
	update_post_meta( $product_id, $field_id, $value );
	return $value;
};

// A price field posts one box for every price level. Only the default level is typed here.
$decimal_comma_prod_levels = function ( $typed ) {
	$posted = array();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		if ( $level ) {
			$posted[ $level->get_id() ] = '';
		}
	}
	$posted[ sunshine_get_default_price_level_id() ] = $typed;
	return $posted;
};

// What the product edit screen shows in a price field's boxes right now, as the browser would
// send it back when the photographer clicks Update without touching it.
$decimal_comma_prod_shown = function ( $product_id, $field_id ) use ( $decimal_comma_prod_box ) {
	global $post;
	$saved_post = $post;
	$post       = get_post( $product_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	ob_start();
	try {
		$decimal_comma_prod_box()->display_field(
			array(
				'id'   => $field_id,
				'name' => 'Price',
				'type' => 'price',
			)
		);
	} finally {
		$html = ob_get_clean();
		$post = $saved_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
	$shown = array();
	preg_match_all( '/name="' . preg_quote( $field_id, '/' ) . '\[(\d+)\]"[^>]*value="([^"]*)"/', $html, $matches, PREG_SET_ORDER );
	foreach ( $matches as $match ) {
		$shown[ $match[1] ] = html_entity_decode( $match[2], ENT_QUOTES );
	}
	return $shown;
};

/*
 * Product prices typed on the product edit screen.
 */

$decimal_comma_prod_test(
	'product price typed as 12,50, saved twice',
	function () use ( $t, $decimal_comma_prod_save, $decimal_comma_prod_levels, $decimal_comma_prod_shown ) {
		$t::tax( 20 );
		$print = $t::product( 1 );
		$decimal_comma_prod_save( $print, 'price', 'price', $decimal_comma_prod_levels( '12,50' ) );
		// The photographer opens the product again and clicks Update without changing anything.
		$decimal_comma_prod_save( $print, 'price', 'price', $decimal_comma_prod_shown( $print, 'price' ) );
		$t::money( 12.50, sunshine_get_product( $print )->get_price(), 'saved price' );
		$t::add( $print, 3 );
		$cart = $t::calculate();
		// 3 x 12.50 = 37.50, tax 20% = 7.50.
		$t::money( 37.50, $cart->get_subtotal(), 'subtotal' );
		$t::money( 7.50, $cart->get_tax(), 'tax' );
		$t::money( 45, $cart->get_total(), 'total' );
	}
);

$decimal_comma_prod_test(
	'product price typed as 1.250,00',
	function () use ( $t, $decimal_comma_prod_save, $decimal_comma_prod_levels ) {
		$t::tax( 20 );
		$canvas = $t::product( 1 );
		$decimal_comma_prod_save( $canvas, 'price', 'price', $decimal_comma_prod_levels( '1.250,00' ) );
		$t::add( $canvas );
		$cart = $t::calculate();
		$t::money( 1250, $cart->get_subtotal(), 'subtotal' );
		$t::money( 250, $cart->get_tax(), 'tax' );
		$t::money( 1500, $cart->get_total(), 'total' );
	}
);

// Some prices are saved by code as a plain number with a dot: SPC_Product::set_price(), which
// the Price Levels add-on uses for its price screen and for "copy prices at a percent", and the
// API. The product edit screen shows that number as it is (12.5). Clicking Update must not
// change the price: the screen's clean-up takes out the store's thousands separator, which is
// a dot here, so 12.5 would be saved as 125.
$decimal_comma_prod_test(
	'a price saved by code as 12.5 is kept when the product is saved again',
	function () use ( $t, $decimal_comma_prod_save, $decimal_comma_prod_shown ) {
		$print = $t::product( 10, array( 'taxable' => false ) );
		sunshine_get_product( $print )->set_price( 12.5 );
		$shown = $decimal_comma_prod_shown( $print, 'price' );
		$decimal_comma_prod_save( $print, 'price', 'price', $shown );
		$t::money( 12.50, sunshine_get_product( $print )->get_price(), 'saved price' );
		$t::add( $print, 2 );
		$cart = $t::calculate();
		$t::money( 25, $cart->get_total(), '2 x 12.50' );
	}
);

// Prices that include tax, typed with a comma. 14,40 includes 2,40 of 20% tax.
$decimal_comma_prod_test(
	'order placed with a price typed as 14,40 that includes tax',
	function () use ( $t, $decimal_comma_prod_save, $decimal_comma_prod_levels ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$print = $t::product( 1 );
		$decimal_comma_prod_save( $print, 'price', 'price', $decimal_comma_prod_levels( '14,40' ) );
		$t::add( $print, 3 );
		$t::checkout_address();
		$cart = $t::calculate();
		// 3 x 14.40 = 43.20, of which 3 x 2.40 = 7.20 is tax.
		$t::money( 7.20, $cart->get_tax(), 'cart tax' );
		$t::money( 43.20, $cart->get_total(), 'cart total' );
		$order = $t::place_order();
		$t::money( 7.20, $order->get_tax(), 'order tax' );
		$t::money( 43.20, $order->get_total(), 'order total' );
	}
);

/*
 * WHCC markup. The Markup box on a WHCC product is a text box with one box per price level and
 * a "%" after it. The markup is sent to WHCC when its editor opens (create_editor() takes
 * get_markup()), and WHCC prices the item with it.
 */

$decimal_comma_prod_test(
	'WHCC markup typed as 12,5 percent',
	function () use ( $t, $decimal_comma_prod_save, $decimal_comma_prod_levels ) {
		$t::require_plugin( 'sunshine_get_whcc_product', 'WHCC add-on' );
		// Nothing here should reach WHCC. Stop any request before it leaves.
		$blocked = array();
		$block   = function ( $pre, $args, $url ) use ( &$blocked ) {
			$blocked[] = $url;
			return new WP_Error( 'spc_totals_blocked', 'Blocked by the totals tests' );
		};
		add_filter( 'pre_http_request', $block, PHP_INT_MAX, 3 );
		try {
			$album = $t::product( 0 );
			update_post_meta( $album, 'type', 'whcc' );
			update_post_meta( $album, 'whcc_product', 'spcdcwhcc' . wp_rand( 100000, 999999 ) );
			// The box has its own field type, "whcc_markup", which the meta box saves as posted.
			$decimal_comma_prod_save( $album, 'whcc_markup', 'whcc_markup', $decimal_comma_prod_levels( '12,5' ) );
			$t::same( 12.5, sunshine_get_product( $album, sunshine_get_default_price_level_id() )->get_markup(), 'markup 12,5 %' );

			$print = $t::product( 0 );
			update_post_meta( $print, 'type', 'whcc' );
			update_post_meta( $print, 'whcc_product', 'spcdcwhcc' . wp_rand( 100000, 999999 ) );
			$decimal_comma_prod_save( $print, 'whcc_markup', 'whcc_markup', $decimal_comma_prod_levels( '35' ) );
			$t::same( 35.0, sunshine_get_product( $print, sunshine_get_default_price_level_id() )->get_markup(), 'markup 35 %' );
		} finally {
			remove_filter( 'pre_http_request', $block, PHP_INT_MAX );
		}
		$t::same( array(), $blocked, 'no request tried to leave' );
	}
);

/*
 * Number boxes: the browser sends them with a dot, so they work the same in every store.
 */

// Discount Amount and "Minimum purchase amount" are number boxes with 2 decimals.
// A code that does not qualify is taken out of the cart with a message, so each case has its
// own cart.
$decimal_comma_prod_test(
	'discount code of 2,50 off, cart exactly at the 20,50 minimum',
	function () use ( $t ) {
		$t::add( $t::product( 20.50, array( 'taxable' => false ) ) );
		$t::discount( 'amount-total', '2.50', array( 'min_amount' => '20.50' ) );
		$cart = $t::calculate();
		$t::money( 2.50, $cart->get_discount(), 'discount' );
		$t::money( 18, $cart->get_total(), 'total 20.50 - 2.50' );
	}
);

$decimal_comma_prod_test(
	'discount code with a 20,50 minimum, cart one cent under',
	function () use ( $t ) {
		$t::add( $t::product( 20.49, array( 'taxable' => false ) ) );
		$t::discount( 'amount-total', '2.50', array( 'min_amount' => '20.50' ) );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_discount(), 'no discount' );
		$t::money( 20.49, $cart->get_total(), 'total' );
	}
);

$decimal_comma_prod_test(
	'fixed payment fee of 1,50',
	function () use ( $t ) {
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$t::option( 'test_fee', 'amount' );
		$t::option( 'test_fee_amount', '1.50' );
		$t::option( 'test_fee_name', 'Card fee' );
		SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
		$cart = $t::calculate();
		$t::money( 21.50, $cart->get_total(), 'total 20 + 1.50' );
	}
);

// The quantity discount box is a number box with 2 decimals. Saved as the form posts it: an
// "items" list of qty, discount and type ("amount" is money off each item).
$decimal_comma_prod_test(
	'quantity discount of 0,50 off each print',
	function () use ( $t ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$rule  = array(
			'items' => array(
				array(
					'qty'      => '5',
					'discount' => '0.50',
					'type'     => 'amount',
				),
			),
		);
		$print = $t::product( 4, array( 'taxable' => false, 'qty_discount' => $rule ) );
		$t::add( $print, 6 );
		$cart = $t::calculate();
		// 6 x 4 = 24, less 6 x 0.50 = 3.
		$t::money( 21, $cart->get_total(), 'total' );
	}
);

/*
 * How amounts are read and shown.
 */

$decimal_comma_prod_test(
	'amounts are read and shown with the store separators',
	function () use ( $t ) {
		$t::same( '1.234,50', sunshine_format_amount( 1234.5 ), '1234.5 is shown as 1.234,50' );
		$t::same( '4,95', sunshine_format_amount( 4.95 ), '4.95 is shown as 4,95' );
		$t::same( '12', sunshine_format_amount( 12 ), 'whole amounts drop ,00' );
		$t::money( 1234.56, sunshine_sanitize_amount( '1.234,56' ), '1.234,56 is read as 1234.56' );
		$t::money( 12.50, sunshine_sanitize_amount( '12,5' ), '12,5 is read as 12.50' );
		// Stores in France and others put a space between thousands.
		$t::option( 'currency_thousands_separator', ' ' );
		$t::money( 1234.56, sunshine_sanitize_amount( '1 234,56' ), '1 234,56 is read as 1234.56' );
		$t::same( '1 234,50', sunshine_format_amount( 1234.5 ), '1234.5 is shown as 1 234,50' );
	}
);

// A gift card row in the cart, checkout and order shows its amount with sunshine_price() of a
// number below zero. Other rows that take money off (discount, credits) show "-" then the price.
// A store with a dot shows the gift card row the same way, "-€25.50", so a store with a comma
// should show "-€25,50", not "€-25,50".
$decimal_comma_prod_test(
	'an amount below zero puts the minus sign before the euro sign',
	function () use ( $t ) {
		$t::option( 'currency_symbol_position', 'left' );
		$shown = function ( $amount ) {
			return html_entity_decode( wp_strip_all_tags( sunshine_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		};
		$t::same( '-€25,50', $shown( -25.5 ), 'gift card of 25,50' );
		$t::same( '-€1.250', $shown( -1250 ), 'gift card of 1.250' );
		// The same amounts in a store that writes 25.50.
		$t::option( 'currency_thousands_separator', ',' );
		$t::option( 'currency_decimal_separator', '.' );
		$t::same( '-€25.50', $shown( -25.5 ), 'dot store: gift card of 25.50' );
		$t::same( '-€1,250.50', $shown( -1250.5 ), 'dot store: gift card of 1,250.50' );
	}
);
