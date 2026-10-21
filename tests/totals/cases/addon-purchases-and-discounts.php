<?php
/**
 * Buying things that change money later, and line discounts in depth.
 *
 * - Gift Cards add-on: buying a gift card (preset, custom, more than one), never taxed, and what
 *   discount codes, other gift cards, store credit and payment fees do to it.
 * - Session Fees add-on: buying a session, and products a paid session includes for free.
 * - Quantity Discounts add-on: tiers, per line or whole cart, both tax modes, with discount codes,
 *   gift cards and store credit, and together with Session Fees on the same product.
 *
 * Helpers are in addon-purchases-and-discounts-helpers.php. Placed orders are in
 * addon-purchases-and-discounts-orders.php.
 */

$t = 'SPC_Totals_Test';

$purchases_offline_on();

/*
 * Buying gift cards.
 */

$t::test(
	'Gift card purchase: preset and custom amounts are the price, with no tax',
	function () use ( $t, $purchases_gift_card_product, $purchases_add_gift_card, $purchases_lines ) {
		$t::tax( 10 );
		$card = $purchases_gift_card_product();
		$purchases_add_gift_card( $card, 50 );
		$purchases_add_gift_card( $card, '37.50' );
		$cart  = $t::calculate();
		$lines = $purchases_lines( $cart );
		$t::same( 2, count( $lines ), 'two gift card lines' );
		$t::money( 50, $lines[0]->get_total(), 'preset card line' );
		$t::money( 37.50, $lines[1]->get_total(), 'custom card line' );
		$t::money( 0, $lines[0]->get_tax_total() + $lines[1]->get_tax_total(), 'no tax on the lines' );
		$t::money( 87.50, $cart->get_subtotal(), 'subtotal' );
		$t::money( 0, $cart->get_tax(), 'no tax' );
		$t::money( 87.50, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Gift card purchase: next to a taxed print, only the print is taxed',
	function () use ( $t, $purchases_gift_card_product, $purchases_add_gift_card ) {
		$t::tax( 10 );
		$t::add( $t::product( 20 ) );
		$purchases_add_gift_card( $purchases_gift_card_product(), 50 );
		$cart = $t::calculate();
		$t::money( 2, $cart->get_tax(), 'tax on the 20 print' );
		$t::money( 72, $cart->get_total(), '20 + 2 tax + 50 card' );
	}
);

$t::test(
	'Gift card purchase: next to a taxed print, prices include tax',
	function () use ( $t, $purchases_gift_card_product, $purchases_add_gift_card ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 22 ) );
		$purchases_add_gift_card( $purchases_gift_card_product(), 50 );
		$cart = $t::calculate();
		$t::money( 2, $cart->get_tax(), 'tax included in the 22 print' );
		$t::money( 72, $cart->get_total(), '22 print + 50 card' );
	}
);

// Quick edit and bulk edit on the product list can tick Taxable on every product, the gift card
// too, and the gift card's own product screen hides the box so it can't be unticked there.
// The help doc says no tax is ever charged on a gift card, and the add-on means it: it sets
// taxable off on the cart line and filters sunshine_product_is_taxable.
$t::test(
	'Gift card purchase: never taxed, even when the product is marked Taxable',
	function () use ( $t, $purchases_gift_card_product, $purchases_add_gift_card ) {
		$t::tax( 10 );
		$purchases_add_gift_card( $purchases_gift_card_product( '1' ), 50 );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_tax(), 'no tax' );
		$t::money( 50, $cart->get_total(), 'total is the card amount' );

		$t::option( 'price_has_tax', 'yes' );
		$cart = $t::new_request();
		$t::money( 0, $cart->get_tax(), 'no tax when prices include tax' );
		$t::money( 50, $cart->get_total(), 'total when prices include tax' );
	}
);

// The gift card forms always send a quantity of 1, but nothing stops a quantity of 2 (custom code,
// a theme's quantity box, or the cart page's quantity box). Two 50 cards cost 100.
$t::test(
	'Gift card purchase: 2 of the same card cost twice the amount',
	function () use ( $t, $purchases_gift_card_product, $purchases_add_gift_card, $purchases_lines ) {
		$purchases_add_gift_card( $purchases_gift_card_product(), 50, 2 );
		$cart  = $t::calculate();
		$lines = $purchases_lines( $cart );
		$t::same( 2, $lines[0]->get_qty(), 'quantity' );
		$t::money( 100, $lines[0]->get_subtotal(), 'line subtotal 2 x 50' );
		$t::money( 100, $lines[0]->get_total(), 'line total 2 x 50' );
		$t::money( 100, $cart->get_subtotal(), 'cart subtotal' );
		$t::money( 100, $cart->get_total(), 'cart total' );

		// Gift cards are never taxed, so turning on tax and tax-inclusive prices changes nothing.
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$cart = $t::new_request();
		$t::money( 100, $cart->get_total(), 'cart total when prices include tax' );
	}
);

// The cart page sends only the new quantity, not the card amount.
$t::test(
	'Gift card purchase: changing the quantity on the cart page keeps the card',
	function () use ( $t, $purchases_gift_card_product, $purchases_add_gift_card, $purchases_lines ) {
		$purchases_add_gift_card( $purchases_gift_card_product(), 50 );
		$t::calculate();
		$key = array_key_first( (array) SPC()->cart->get_cart() );
		SPC()->cart->update_item_quantity( $key, 2 );
		$cart  = $t::new_request();
		$lines = $purchases_lines( $cart );
		$t::same( 1, count( $lines ), 'the gift card is still in the cart' );
		if ( $lines ) {
			$t::same( 2, $lines[0]->get_qty(), 'quantity is now 2' );
			$t::money( 100, $lines[0]->get_total(), 'line total 2 x 50' );
		}
		$t::money( 100, $cart->get_total(), 'cart total' );
	}
);

// Assumed rule (no doc covers it): a discount code does not take money off a gift card purchase.
// A gift card is money; selling a 100 card for 90 loses the photographer 10, and the customer
// could stack codes by buying cards. Most stores work this way.
$t::test(
	'Gift card purchase: a percent discount code does not lower the price of a gift card',
	function () use ( $t, $purchases_gift_card_product, $purchases_add_gift_card ) {
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$purchases_add_gift_card( $purchases_gift_card_product(), 100 );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		$t::money( 5, $cart->get_discount(), '10% of the 50 print only' );
		$t::money( 145, $cart->get_total(), '45 print + 100 card' );
	}
);

// Same assumed rule. A fixed amount off is capped at what it can apply to, which is only the print.
$t::test(
	'Gift card purchase: a fixed amount code is not spent on the gift card',
	function () use ( $t, $purchases_gift_card_product, $purchases_add_gift_card ) {
		$t::add( $t::product( 10, array( 'taxable' => false ) ) );
		$purchases_add_gift_card( $purchases_gift_card_product(), 100 );
		$t::discount( 'amount-total', 20 );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_discount(), 'capped at the 10 print' );
		$t::money( 100, $cart->get_total(), 'the card still costs 100' );
	}
);

// 50 card - 15 from another gift card = 35, then 20 of store credit = 15 left to pay.
$t::test(
	'Gift card purchase: paid with another gift card and store credit',
	function () use ( $t, $purchases_gift_card_product, $purchases_add_gift_card, $purchases_credits, $purchases_gift_card_applied ) {
		$customer_id = $t::customer();
		$purchases_credits( $customer_id, 20 );
		$purchases_add_gift_card( $purchases_gift_card_product(), 50 );
		$t::gift_card( 15 );
		$cart = $t::calculate();
		$t::money( 15, $purchases_gift_card_applied(), 'taken from the other card' );
		$t::money( 20, $cart->get_credits_applied(), 'store credit used' );
		$t::money( 15, $cart->get_total(), 'left to pay' );
	}
);

$t::test(
	'Gift card purchase: percent payment fee',
	function () use ( $t, $purchases_gift_card_product, $purchases_add_gift_card ) {
		$purchases_add_gift_card( $purchases_gift_card_product(), 50 );
		$t::option( 'test_fee', 'percent' );
		$t::option( 'test_fee_amount', '3' );
		$t::option( 'test_fee_name', 'Card fee' );
		SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
		$cart = $t::calculate();
		$t::money( 51.50, $cart->get_total(), '50 + 3% of 50' );
	}
);

/*
 * Buying a session.
 */

// A session fee needs no shipping, so a shipping method chosen earlier is dropped.
$t::test(
	'Session fee: taxed, and no shipping charged',
	function () use ( $t, $purchases_session_product, $purchases_add_session ) {
		$t::customer();
		$t::tax( 10 );
		$t::flat_shipping( 10, true );
		$purchases_add_session( $purchases_session_product( 150, array(), true ) );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_shipping(), 'no shipping' );
		$t::money( 15, $cart->get_tax(), 'tax on 150' );
		$t::money( 165, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Session fee: prices include tax',
	function () use ( $t, $purchases_session_product, $purchases_add_session ) {
		$t::customer();
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$purchases_add_session( $purchases_session_product( 165, array(), true ) );
		$cart = $t::calculate();
		$t::money( 15, $cart->get_tax(), 'tax included in 165' );
		$t::money( 165, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Session fee: a discount code takes money off the session',
	function () use ( $t, $purchases_session_product, $purchases_add_session ) {
		$t::customer();
		$t::tax( 10 );
		$purchases_add_session( $purchases_session_product( 150, array(), true ) );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		$t::money( 15, $cart->get_discount(), '10% of 150' );
		$t::money( 13.50, $cart->get_tax(), 'tax on 135' );
		$t::money( 148.50, $cart->get_total(), 'total' );
	}
);

/*
 * Products a paid session includes. The customer's session includes 2 of a print, and they put
 * 3 of it in the cart: 2 are free and 1 is paid for.
 */

$t::test(
	'Included products: 2 of 3 prints free, tax on the one paid for',
	function () use ( $t, $purchases_give_included, $purchases_line_discounts ) {
		$customer_id = $t::customer();
		$t::tax( 10 );
		$print = $t::product( 10 );
		$purchases_give_included( $customer_id, array( array( $print, 2 ) ) );
		$t::add( $print, 3 );
		$cart = $t::calculate();
		$t::money( 20, $purchases_line_discounts( $cart ), '2 free prints' );
		$t::money( 1, $cart->get_tax(), 'tax on the 10 paid for' );
		$t::money( 11, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Included products: 2 of 3 prints free, prices include tax',
	function () use ( $t, $purchases_give_included, $purchases_line_discounts ) {
		$customer_id = $t::customer();
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$print = $t::product( 11 );
		$purchases_give_included( $customer_id, array( array( $print, 2 ) ) );
		$t::add( $print, 3 );
		$cart = $t::calculate();
		$t::money( 22, $purchases_line_discounts( $cart ), '2 free prints at 11' );
		$t::money( 1, $cart->get_tax(), 'tax included in the 11 paid for' );
		$t::money( 11, $cart->get_total(), 'total' );
	}
);

// The code applies to the 10 still paid for, and the gift card pays the rest.
$t::test(
	'Included products: with a discount code and a gift card',
	function () use ( $t, $purchases_give_included, $purchases_gift_card_applied ) {
		$customer_id = $t::customer();
		$print       = $t::product( 10, array( 'taxable' => false ) );
		$purchases_give_included( $customer_id, array( array( $print, 2 ) ) );
		$t::add( $print, 3 );
		$t::discount( 'percent-total', 10 );
		$t::gift_card( 50 );
		$cart = $t::calculate();
		$t::money( 1, $cart->get_discount(), '10% of the 10 paid for' );
		$t::money( 9, $purchases_gift_card_applied(), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

/*
 * Quantity discounts.
 */

// The worked example in the "How to set quantity discounts" help doc: a 9.00 photo with five tiers.
// The doc rounds 15 photos to "29": 15 x (9 - 7.07) = 28.95.
$t::test(
	'Quantity discount: the price tiers from the help doc',
	function () use ( $t, $purchases_qty_rule, $purchases_lines ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$rule     = $purchases_qty_rule(
			array(
				array( 3, '4.00' ),
				array( 5, '5.40' ),
				array( 10, '6.60' ),
				array( 15, '7.07' ),
				array( 20, '7.30' ),
			)
		);
		$expected = array(
			1  => 9,     // No tier yet.
			3  => 15,    // 3 x (9 - 4.00).
			4  => 20,    // 4 x (9 - 4.00).
			5  => 18,    // 5 x (9 - 5.40).
			10 => 24,    // 10 x (9 - 6.60).
			15 => 28.95, // 15 x (9 - 7.07).
			20 => 34,    // 20 x (9 - 7.30).
		);
		// One product per quantity, so each line is counted on its own.
		foreach ( $expected as $qty => $line_total ) {
			$t::add( $t::product( 9, array( 'taxable' => false, 'qty_discount' => $rule ) ), $qty );
		}
		$cart  = $t::calculate();
		$lines = $purchases_lines( $cart );
		$i     = 0;
		foreach ( $expected as $qty => $line_total ) {
			$t::money( $line_total, isset( $lines[ $i ] ) ? $lines[ $i ]->get_total() : null, $qty . ' photos' );
			$i++;
		}
		$t::money( 148.95, $cart->get_total(), 'cart total' );
	}
);

// 15% off 2.99 is 0.4485 each. 11 x 2.99 = 32.89, less 4.9335 = 27.9565, so 27.96.
// Tax 8% of 27.9565 = 2.2365, so 2.24. Total 30.20.
$t::test(
	'Quantity discount: percent tier rounds to the cent',
	function () use ( $t, $purchases_qty_rule, $purchases_line_discounts ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::tax( 8 );
		$t::add( $t::product( 2.99, array( 'qty_discount' => $purchases_qty_rule( array( array( 10, '15', 'percent' ) ) ) ) ), 11 );
		$cart = $t::calculate();
		$t::money( 4.93, $purchases_line_discounts( $cart ), 'line discount' );
		$t::money( 27.96, $cart->get_subtotal(), 'subtotal' );
		$t::money( 2.24, $cart->get_tax(), 'tax' );
		$t::money( 30.20, $cart->get_total(), 'total' );
	}
);

// The help doc says a product can mix fixed and percent tiers: 2 off at 3 or more, 25% off at 6 or more.
$t::test(
	'Quantity discount: fixed and percent tiers on one product',
	function () use ( $t, $purchases_qty_rule, $purchases_lines ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$rule = $purchases_qty_rule(
			array(
				array( 3, '2' ),
				array( 6, '25', 'percent' ),
			)
		);
		$t::add( $t::product( 20, array( 'taxable' => false, 'qty_discount' => $rule ) ), 4 );
		$t::add( $t::product( 20, array( 'taxable' => false, 'qty_discount' => $rule ) ), 7 );
		$cart  = $t::calculate();
		$lines = $purchases_lines( $cart );
		$t::money( 72, $lines[0]->get_total(), '4 x (20 - 2)' );
		$t::money( 105, $lines[1]->get_total(), '7 x 15 (25% off)' );
		$t::money( 177, $cart->get_total(), 'total' );
	}
);

// Lines with no photo are never merged, so each add is its own line, like two different photos.
$t::test(
	'Quantity discount: whole cart counts every line, per line counts each line alone',
	function () use ( $t, $purchases_qty_rule, $purchases_lines ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$whole_cart = $t::product( 10, array( 'taxable' => false, 'qty_discount' => $purchases_qty_rule( array( array( 5, '1' ) ) ) ) );
		$per_line   = $t::product( 10, array( 'taxable' => false, 'qty_discount' => $purchases_qty_rule( array( array( 5, '1' ) ), true ) ) );
		$t::add( $whole_cart, 3 );
		$t::add( $whole_cart, 3 );
		$t::add( $per_line, 3 );
		$t::add( $per_line, 5 );
		$cart  = $t::calculate();
		$lines = $purchases_lines( $cart );
		$t::money( 27, $lines[0]->get_total(), 'whole cart: 6 in the cart, so 1 off each of these 3' );
		$t::money( 27, $lines[1]->get_total(), 'whole cart: and 1 off each of the other 3' );
		$t::money( 30, $lines[2]->get_total(), 'per line: 3 on this line is not enough' );
		$t::money( 45, $lines[3]->get_total(), 'per line: 5 on this line, 1 off each' );
		$t::money( 129, $cart->get_total(), 'total' );
	}
);

// A tier set to 12 off a 10 print (easy to do when the price changes later) can make the print
// free, but never less than free, and it must not lower the tax on the rest of the cart.
$t::test(
	'Quantity discount: a tier worth more than the price makes the print free, never less',
	function () use ( $t, $purchases_qty_rule, $purchases_lines ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::tax( 10 );
		$t::add( $t::product( 10, array( 'qty_discount' => $purchases_qty_rule( array( array( 5, '12' ) ) ) ) ), 5 );
		$t::add( $t::product( 100 ) );
		$cart  = $t::calculate();
		$lines = $purchases_lines( $cart );
		$t::money( 0, $lines[0]->get_total(), 'the 5 prints are free' );
		$t::money( 50, $lines[0]->get_discount_total(), 'line discount is at most the 50 line' );
		$t::money( 0, $lines[0]->get_tax_total(), 'no tax on the free line' );
		$t::money( 10, $cart->get_tax(), 'tax on the 100 product only' );
		$t::money( 110, $cart->get_total(), 'total' );
	}
);

// 5 x (11 - 1) = 50 with tax inside: 50 / 1.1 = 45.45, so 4.55 tax.
// Then 10% off: 45, with 45 - 40.91 = 4.09 tax inside.
$t::test(
	'Quantity discount: prices include tax, then a discount code',
	function () use ( $t, $purchases_qty_rule ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 11, array( 'qty_discount' => $purchases_qty_rule( array( array( 5, '1' ) ) ) ) ), 5 );
		$cart = $t::calculate();
		$t::money( 4.55, $cart->get_tax(), 'tax included' );
		$t::money( 50, $cart->get_total(), 'total' );

		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		$t::money( 5, $cart->get_discount(), '10% of 50' );
		$t::money( 4.09, $cart->get_tax(), 'tax included after the code' );
		$t::money( 45, $cart->get_total(), 'total after the code' );
	}
);

// 10 x (10 - 2) = 80. 10% code off 80 = 8. Tax 10% of 72 = 7.20.
$t::test(
	'Quantity discount: with a percent discount code',
	function () use ( $t, $purchases_qty_rule ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::tax( 10 );
		$t::add( $t::product( 10, array( 'qty_discount' => $purchases_qty_rule( array( array( 10, '2' ) ) ) ) ), 10 );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		$t::money( 8, $cart->get_discount(), '10% of the discounted 80' );
		$t::money( 7.20, $cart->get_tax(), 'tax on 72' );
		$t::money( 79.20, $cart->get_total(), 'total' );
	}
);

// 80 + 8 tax = 88, then 10% off after tax = 8.80.
$t::test(
	'Quantity discount: with a discount code taken after tax',
	function () use ( $t, $purchases_qty_rule ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 10, array( 'qty_discount' => $purchases_qty_rule( array( array( 10, '2' ) ) ) ) ), 10 );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		$t::money( 8, $cart->get_tax(), 'tax on 80' );
		$t::money( 8.80, $cart->get_discount(), '10% of 88' );
		$t::money( 79.20, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Quantity discount: with an amount off each product code',
	function () use ( $t, $purchases_qty_rule ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$t::add( $t::product( 10, array( 'taxable' => false, 'qty_discount' => $purchases_qty_rule( array( array( 10, '2' ) ) ) ) ), 10 );
		$t::discount( 'amount-product', 1 );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_discount(), '1 off each of 10' );
		$t::money( 70, $cart->get_total(), '80 - 10' );
	}
);

// 80 + 8 tax = 88. The gift card pays 50, store credit pays 20, 18 is left.
$t::test(
	'Quantity discount: with a gift card and store credit',
	function () use ( $t, $purchases_qty_rule, $purchases_credits, $purchases_gift_card_applied ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$customer_id = $t::customer();
		$purchases_credits( $customer_id, 20 );
		$t::tax( 10 );
		$t::add( $t::product( 10, array( 'qty_discount' => $purchases_qty_rule( array( array( 10, '2' ) ) ) ) ), 10 );
		$t::gift_card( 50 );
		$cart = $t::calculate();
		$t::money( 50, $purchases_gift_card_applied(), 'taken from the card' );
		$t::money( 20, $cart->get_credits_applied(), 'store credit used' );
		$t::money( 18, $cart->get_total(), 'left to pay' );
	}
);

/*
 * Included products and a quantity discount on the same print. Both add-ons discount the cart
 * line through the sunshine_cart_item_discount filter.
 *
 * Rule used: the tier counts every print in the cart ("5 or more in cart"), the included prints
 * are free, and the prints still paid for get the tier price. 5 prints at 10, 2 included,
 * 2 off each at 5 or more: 2 free (20) + 3 x 2 off (6) = 26 off, 24 to pay.
 */

$t::test(
	'Included products and a quantity discount: fixed tier',
	function () use ( $t, $purchases_qty_rule, $purchases_give_included, $purchases_line_discounts ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$customer_id = $t::customer();
		$print       = $t::product( 10, array( 'taxable' => false, 'qty_discount' => $purchases_qty_rule( array( array( 5, '2' ) ) ) ) );
		$purchases_give_included( $customer_id, array( array( $print, 2 ) ) );
		$t::add( $print, 5 );
		$cart = $t::calculate();
		$t::money( 26, $purchases_line_discounts( $cart ), '2 free + 2 off each of the other 3' );
		$t::money( 24, $cart->get_total(), 'total' );
	}
);

// 20% off a 10 print is 2 off, so the same 26 off.
$t::test(
	'Included products and a quantity discount: percent tier',
	function () use ( $t, $purchases_qty_rule, $purchases_give_included, $purchases_line_discounts ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$customer_id = $t::customer();
		$print       = $t::product( 10, array( 'taxable' => false, 'qty_discount' => $purchases_qty_rule( array( array( 5, '20', 'percent' ) ) ) ) );
		$purchases_give_included( $customer_id, array( array( $print, 2 ) ) );
		$t::add( $print, 5 );
		$cart = $t::calculate();
		$t::money( 26, $purchases_line_discounts( $cart ), '2 free + 20% off the other 3' );
		$t::money( 24, $cart->get_total(), 'total' );
	}
);

// When every print on the line is included, the line is free. The two discounts must not add up
// to more than the line (50 free + 10 tier discount would be 60 off a 50 line).
$t::test(
	'Included products and a quantity discount: the whole line included',
	function () use ( $t, $purchases_qty_rule, $purchases_give_included, $purchases_line_discounts ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$customer_id = $t::customer();
		$print       = $t::product( 10, array( 'taxable' => false, 'qty_discount' => $purchases_qty_rule( array( array( 5, '2' ) ) ) ) );
		$purchases_give_included( $customer_id, array( array( $print, 5 ) ) );
		$t::add( $print, 5 );
		$cart = $t::calculate();
		$t::money( 50, $purchases_line_discounts( $cart ), 'all 5 free, nothing more' );
		$t::money( 0, $cart->get_total(), 'total' );
	}
);

// 24 to pay, 10% tax 2.40.
$t::test(
	'Included products and a quantity discount: taxed',
	function () use ( $t, $purchases_qty_rule, $purchases_give_included ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
		$customer_id = $t::customer();
		$t::tax( 10 );
		$print = $t::product( 10, array( 'qty_discount' => $purchases_qty_rule( array( array( 5, '2' ) ) ) ) );
		$purchases_give_included( $customer_id, array( array( $print, 2 ) ) );
		$t::add( $print, 5 );
		$cart = $t::calculate();
		$t::money( 2.40, $cart->get_tax(), 'tax on 24' );
		$t::money( 26.40, $cart->get_total(), 'total' );
	}
);

$purchases_offline_off();
