<?php
/**
 * Advanced Shipping add-on: the rules that decide which shipping methods a customer is offered,
 * and so how much they pay for shipping.
 *
 * Order amount rules. The docs ("Setup Free Shipping With Minimum Order Amount") say the
 * Minimum and Maximum Order Amount are checked against the order subtotal, and that both ends
 * count: a $49.99 maximum keeps the flat rate up to $49.99 and hides it "once the subtotal is
 * $50 or higher", and a $50 minimum offers free shipping once the subtotal "meets" $50.
 *
 * Which subtotal: the Subtotal line the customer sees in the cart. So:
 * - quantity discounts that lower a line's price count, because they lower that line;
 * - discount codes, gift cards, store credit and shipping do not count, because they are
 *   separate lines below the subtotal (and a gift card or credit is a way to pay, not a smaller order);
 * - tax counts only when the store's prices include tax (and are shown with tax): the
 *   photographer typed the prices with tax in them, so "$50" means $50 as the customer sees it.
 *   When prices leave tax out, tax is added on top and does not count.
 *
 * Shipping methods are set up the way Sunshine > Settings > Delivery & Shipping saves them:
 * a list of instances in "shipping_methods" plus one option per setting named
 * {method}_{setting}_{instance}, with every value saved as a string from the form.
 */

$t = 'SPC_Totals_Test';

// Turn on shipping methods. $methods is instance => array( 'id' => method, setting => value ).
// The customer has picked "Ship" at checkout, so when only one rate is offered it is chosen.
$shiprules_setup = function ( $methods ) use ( $t ) {
	$list = array();
	foreach ( $methods as $instance => $settings ) {
		$method_id         = $settings['id'];
		$list[ $instance ] = array(
			'id'     => $method_id,
			'active' => true,
		);
		unset( $settings['id'] );
		foreach ( $settings as $key => $value ) {
			$t::option( $method_id . '_' . $key . '_' . $instance, $value );
		}
	}
	$t::option( 'shipping_methods', $list );
	SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
};

// The setup from the docs: $3 flat rate up to $49.99, free shipping from $50.
$shiprules_flat_or_free = function () use ( $shiprules_setup ) {
	$shiprules_setup(
		array(
			'spcshiprulesflat' => array(
				'id'         => 'flat_rate',
				'price'      => '3',
				'price_type' => 'cart',
				'order_max'  => '49.99',
			),
			'spcshiprulesfree' => array(
				'id'        => 'free',
				'order_min' => '50',
			),
		)
	);
};

// The customer picks a shipping method.
$shiprules_choose = function ( $instance ) {
	SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
	SPC()->cart->set_checkout_data_item( 'shipping_method', $instance );
};

// Which shipping methods the checkout offers right now.
$shiprules_offered = function () {
	$offered = array_keys( (array) sunshine_get_allowed_shipping_methods() );
	sort( $offered );
	return $offered;
};

// The shipping method the cart is charging for.
$shiprules_chosen = function () {
	$method = SPC()->cart->get_shipping_method();
	return $method ? $method->get_instance_id() : '';
};

$shiprules_gift_card_used = function () {
	return array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) );
};

// Prices typed with tax in them, and shown with tax.
$shiprules_prices_include_tax = function ( $percent ) use ( $t ) {
	$t::tax( $percent );
	$t::option( 'price_has_tax', 'yes' );
	$t::option( 'display_price', 'with_tax' );
};

// Prices typed without tax, tax added on top.
$shiprules_tax_on_top = function ( $percent ) use ( $t ) {
	$t::tax( $percent );
	$t::option( 'price_has_tax', 'no' );
	$t::option( 'display_price', 'without_tax' );
};

$shiprules_line_by_hash = function ( $hash, $qty ) {
	SPC()->cart->update_item_quantity_by_hash( $hash, $qty );
};

/*
 * Minimum and Maximum Order Amount, prices without tax.
 */

$t::test(
	'Shipping rules: one cent under the free shipping minimum pays the flat rate',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_offered, $shiprules_chosen ) {
		$t::add( $t::product( 49.99, array( 'taxable' => false ) ) );
		$shiprules_flat_or_free();
		$cart = $t::calculate();
		// 49.99 is under the 50 minimum and not over the 49.99 maximum.
		$t::same( array( 'spcshiprulesflat' ), $shiprules_offered(), 'only the flat rate is offered' );
		$t::same( 'spcshiprulesflat', $shiprules_chosen(), 'the flat rate is chosen' );
		$t::money( 3, $cart->get_shipping(), 'shipping' );
		$t::money( 52.99, $cart->get_total(), 'total 49.99 + 3' );
	}
);

// Three lines whose prices add up to exactly 50.00, so a sum like 49.9999999 must still count as 50.
$t::test(
	'Shipping rules: exactly the minimum gets free shipping',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_offered, $shiprules_chosen ) {
		$t::add( $t::product( 19.99, array( 'taxable' => false ) ), 2 );
		$t::add( $t::product( 10.02, array( 'taxable' => false ) ) );
		$shiprules_flat_or_free();
		$cart = $t::calculate();
		// 19.99 x 2 + 10.02 = 50.00: meets the minimum, over the 49.99 maximum.
		$t::money( 50, $cart->get_subtotal(), 'subtotal' );
		$t::same( array( 'spcshiprulesfree' ), $shiprules_offered(), 'only free shipping is offered' );
		$t::same( 'spcshiprulesfree', $shiprules_chosen(), 'free shipping is chosen' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 50, $cart->get_total(), 'total' );
	}
);

// Prices leave tax out, so the customer sees a 47.00 subtotal and tax added below it.
$t::test(
	'Shipping rules: tax added on top does not count toward the minimum',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_tax_on_top, $shiprules_offered ) {
		$shiprules_tax_on_top( 10 );
		$t::add( $t::product( 47 ) );
		$shiprules_flat_or_free();
		$cart = $t::calculate();
		// Subtotal 47 is under 50, even though 47 + 4.70 tax = 51.70.
		$t::same( array( 'spcshiprulesflat' ), $shiprules_offered(), 'only the flat rate is offered' );
		$t::money( 3, $cart->get_shipping(), 'shipping' );
		$t::money( 4.70, $cart->get_tax(), 'tax on 47' );
		$t::money( 54.70, $cart->get_total(), 'total 47 + 3 + 4.70' );
	}
);

/*
 * Prices that include tax. The customer sees and pays the prices as typed, so the 50 minimum
 * must be compared with those prices, not with what is left after the tax is taken out.
 */

$t::test(
	'Shipping rules: prices include tax, a cart showing 55.00 gets free shipping',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_prices_include_tax, $shiprules_offered ) {
		$shiprules_prices_include_tax( 20 );
		$t::add( $t::product( 27.50 ), 2 );
		$shiprules_flat_or_free();
		$cart = $t::calculate();
		// The customer sees 2 x 27.50 = 55.00, over the 50 minimum and the 49.99 maximum.
		$t::same( array( 'spcshiprulesfree' ), $shiprules_offered(), 'only free shipping is offered' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 55, $cart->get_total(), 'total is the 55.00 shown' );
	}
);

$t::test(
	'Shipping rules: prices include tax, a cart showing exactly 50.00 gets free shipping',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_prices_include_tax, $shiprules_offered ) {
		$shiprules_prices_include_tax( 20 );
		$t::add( $t::product( 25 ), 2 );
		$shiprules_flat_or_free();
		$cart = $t::calculate();
		// 2 x 25.00 = 50.00 shown.
		$t::same( array( 'spcshiprulesfree' ), $shiprules_offered(), 'only free shipping is offered' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 50, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Shipping rules: prices include tax, a cart showing 49.99 pays the flat rate',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_prices_include_tax, $shiprules_offered ) {
		$shiprules_prices_include_tax( 20 );
		$t::add( $t::product( 49.99 ) );
		$shiprules_flat_or_free();
		$cart = $t::calculate();
		$t::same( array( 'spcshiprulesflat' ), $shiprules_offered(), 'only the flat rate is offered' );
		// The flat rate is not taxed, so it stays 3.
		$t::money( 3, $cart->get_shipping(), 'shipping' );
		$t::money( 52.99, $cart->get_total(), 'total 49.99 + 3' );
	}
);

// An untaxed item has no tax taken out of it, so only the taxed item shrank before.
$t::test(
	'Shipping rules: prices include tax, taxed and untaxed items showing 50.00 get free shipping',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_prices_include_tax, $shiprules_offered ) {
		$shiprules_prices_include_tax( 20 );
		$t::add( $t::product( 30 ) );
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$shiprules_flat_or_free();
		$cart = $t::calculate();
		// 30.00 + 20.00 = 50.00 shown.
		$t::same( array( 'spcshiprulesfree' ), $shiprules_offered(), 'only free shipping is offered' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 50, $cart->get_total(), 'total' );
	}
);

/*
 * Discount codes, line discounts, gift cards and store credit.
 */

// Assumption: the docs say "subtotal", and the cart shows a discount code as its own line
// under the subtotal, so a code does not take the cart back under the minimum.
$t::test(
	'Shipping rules: a discount code does not take away free shipping',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_offered ) {
		$t::add( $t::product( 55, array( 'taxable' => false ) ) );
		$shiprules_flat_or_free();
		$t::discount( 'percent-total', 20 );
		$cart = $t::calculate();
		$t::same( array( 'spcshiprulesfree' ), $shiprules_offered(), 'only free shipping is offered' );
		$t::money( 11, $cart->get_discount(), '20% of 55' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 44, $cart->get_total(), 'total 55 - 11' );
	}
);

// Free shipping set to "Via Discount" and a 50 minimum: the customer needs both the code and
// a 50.00 subtotal ("free shipping with this code on orders over $50").
$t::test(
	'Shipping rules: free shipping by code also needs the minimum',
	function () use ( $t, $shiprules_setup, $shiprules_choose, $shiprules_offered, $shiprules_chosen, $shiprules_line_by_hash ) {
		$line = $t::add( $t::product( 40, array( 'taxable' => false ) ) );
		$shiprules_setup(
			array(
				'spcshiprulesflat' => array(
					'id'         => 'flat_rate',
					'price'      => '6',
					'price_type' => 'cart',
				),
				'spcshiprulesfree' => array(
					'id'        => 'free',
					'discount'  => '1',
					'order_min' => '50',
				),
			)
		);
		$t::discount( '', '', array( 'free_shipping' => '1' ) );
		$shiprules_choose( 'spcshiprulesfree' );
		$cart = $t::calculate();
		$t::same( array( 'spcshiprulesflat' ), $shiprules_offered(), 'at 40 with the code: only the flat rate' );
		$t::money( 6, $cart->get_shipping(), 'shipping at 40' );
		$t::money( 46, $cart->get_total(), 'total at 40' );

		$shiprules_line_by_hash( $line['hash'], 2 );
		$shiprules_choose( 'spcshiprulesfree' );
		$cart = $t::new_request();
		$t::same( array( 'spcshiprulesflat', 'spcshiprulesfree' ), $shiprules_offered(), 'at 80 with the code: both' );
		$t::same( 'spcshiprulesfree', $shiprules_chosen(), 'free shipping is chosen' );
		$t::money( 0, $cart->get_shipping(), 'shipping at 80' );
		$t::money( 80, $cart->get_total(), 'total at 80' );
	}
);

$t::test(
	'Shipping rules: discount after tax does not change which rate is offered',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_tax_on_top, $shiprules_offered ) {
		$shiprules_tax_on_top( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 55 ) );
		$shiprules_flat_or_free();
		$t::discount( 'percent-total', 20 );
		$cart = $t::calculate();
		$t::same( array( 'spcshiprulesfree' ), $shiprules_offered(), 'only free shipping is offered' );
		// Tax 10% of 55 = 5.50, then 20% off 60.50 = 12.10.
		$t::money( 5.50, $cart->get_tax(), 'tax' );
		$t::money( 12.10, $cart->get_discount(), 'discount' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 48.40, $cart->get_total(), 'total 60.50 - 12.10' );
	}
);

// A quantity discount lowers the line itself, so the subtotal the customer sees is lower.
$t::test(
	'Shipping rules: a quantity discount lowers the amount that counts',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_offered ) {
		$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
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
		$shiprules_flat_or_free();
		$cart = $t::calculate();
		// 10 x 6 = 60, less 20% = 48 shown as the subtotal: under 50.
		$t::money( 48, $cart->get_subtotal(), 'subtotal' );
		$t::same( array( 'spcshiprulesflat' ), $shiprules_offered(), 'only the flat rate is offered' );
		$t::money( 3, $cart->get_shipping(), 'shipping' );
		$t::money( 51, $cart->get_total(), 'total 48 + 3' );
	}
);

// A gift card is a way to pay. It does not make the order smaller.
$t::test(
	'Shipping rules: a gift card does not take away free shipping',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_offered, $shiprules_gift_card_used ) {
		$t::add( $t::product( 60, array( 'taxable' => false ) ) );
		$shiprules_flat_or_free();
		$t::gift_card( 40 );
		$cart = $t::calculate();
		$t::same( array( 'spcshiprulesfree' ), $shiprules_offered(), 'only free shipping is offered' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 40, $shiprules_gift_card_used(), 'taken from the card' );
		$t::money( 20, $cart->get_total(), 'left to pay 60 - 40' );
	}
);

$t::test(
	'Shipping rules: a gift card pays for the taxed flat rate too',
	function () use ( $t, $shiprules_setup, $shiprules_tax_on_top, $shiprules_offered, $shiprules_gift_card_used ) {
		$shiprules_tax_on_top( 10 );
		$t::add( $t::product( 45 ) );
		$shiprules_setup(
			array(
				'spcshiprulesflat' => array(
					'id'         => 'flat_rate',
					'price'      => '3',
					'price_type' => 'cart',
					'taxable'    => '1',
					'order_max'  => '49.99',
				),
				'spcshiprulesfree' => array(
					'id'        => 'free',
					'order_min' => '50',
				),
			)
		);
		$t::gift_card( 100 );
		$cart = $t::calculate();
		$t::same( array( 'spcshiprulesflat' ), $shiprules_offered(), 'only the flat rate is offered' );
		$t::money( 3, $cart->get_shipping(), 'shipping' );
		// Tax 4.50 on the item + 0.30 on shipping. 45 + 3 + 4.80 = 52.80.
		$t::money( 4.80, $cart->get_tax(), 'tax' );
		$t::money( 52.80, $shiprules_gift_card_used(), 'taken from the card' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

$t::test(
	'Shipping rules: store credit does not take away free shipping',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_offered ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 30 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::add( $t::product( 60, array( 'taxable' => false ) ) );
		$shiprules_flat_or_free();
		$cart = $t::calculate();
		$t::same( array( 'spcshiprulesfree' ), $shiprules_offered(), 'only free shipping is offered' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 30, $cart->get_credits_applied(), 'credit used' );
		$t::money( 30, $cart->get_total(), 'total 60 - 30' );
	}
);

/*
 * The cart changes after a shipping method was chosen.
 */

$t::test(
	'Shipping rules: free shipping is taken away when the cart drops under the minimum',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_choose, $shiprules_chosen, $shiprules_line_by_hash ) {
		$line = $t::add( $t::product( 30, array( 'taxable' => false ) ), 2 );
		$shiprules_flat_or_free();
		$shiprules_choose( 'spcshiprulesfree' );
		$cart = $t::calculate();
		$t::same( 'spcshiprulesfree', $shiprules_chosen(), 'free shipping is chosen at 60' );
		$t::money( 0, $cart->get_shipping(), 'free at 60' );

		$shiprules_line_by_hash( $line['hash'], 1 );
		$cart = $t::new_request();
		$t::same( 'spcshiprulesflat', $shiprules_chosen(), 'the flat rate takes over at 30' );
		$t::money( 3, $cart->get_shipping(), 'shipping at 30' );
		$t::money( 33, $cart->get_total(), 'total 30 + 3' );
	}
);

$t::test(
	'Shipping rules: the flat rate goes away when the cart grows past the maximum',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_choose, $shiprules_chosen, $shiprules_line_by_hash ) {
		$line = $t::add( $t::product( 30, array( 'taxable' => false ) ) );
		$shiprules_flat_or_free();
		$shiprules_choose( 'spcshiprulesflat' );
		$cart = $t::calculate();
		$t::money( 3, $cart->get_shipping(), 'flat rate at 30' );

		$shiprules_line_by_hash( $line['hash'], 2 );
		$cart = $t::new_request();
		$t::same( 'spcshiprulesfree', $shiprules_chosen(), 'free shipping takes over at 60' );
		$t::money( 0, $cart->get_shipping(), 'shipping at 60' );
		$t::money( 60, $cart->get_total(), 'total' );
	}
);

// Tiered shipping, the way the docs describe it: "charge more for shipping the more the
// customer has in cart". Each tier starts one cent after the last one ends, like the tiers
// that Sunshine 3 made from the old Tiered Shipping add-on.
$t::test(
	'Shipping rules: tiered rates charge the right rate on each side of every edge',
	function () use ( $t, $shiprules_setup, $shiprules_chosen ) {
		$shiprules_setup(
			array(
				'spcshiprulestier1' => array(
					'id'         => 'flat_rate',
					'price'      => '5',
					'price_type' => 'cart',
					'order_min'  => '',
					'order_max'  => '49.99',
				),
				'spcshiprulestier2' => array(
					'id'         => 'flat_rate',
					'price'      => '8',
					'price_type' => 'cart',
					'order_min'  => '50',
					'order_max'  => '99.99',
				),
				'spcshiprulestier3' => array(
					'id'         => 'flat_rate',
					'price'      => '12',
					'price_type' => 'cart',
					'order_min'  => '100',
					'order_max'  => '',
				),
			)
		);
		$expected = array(
			'0.01'   => array( 'spcshiprulestier1', 5 ),
			'49.99'  => array( 'spcshiprulestier1', 5 ),
			'50.00'  => array( 'spcshiprulestier2', 8 ),
			'99.99'  => array( 'spcshiprulestier2', 8 ),
			'100.00' => array( 'spcshiprulestier3', 12 ),
			'2500'   => array( 'spcshiprulestier3', 12 ),
		);
		foreach ( $expected as $amount => $tier ) {
			SPC()->cart->empty_cart();
			$t::add( $t::product( $amount, array( 'taxable' => false ) ) );
			$cart = $t::new_request();
			$t::same( $tier[0], $shiprules_chosen(), 'rate chosen at ' . $amount );
			$t::money( $tier[1], $cart->get_shipping(), 'shipping at ' . $amount );
			$t::money( (float) $amount + $tier[1], $cart->get_total(), 'total at ' . $amount );
		}
	}
);

// A large quantity of a cheap item: 1,000 x 0.05 = 50.00 exactly.
$t::test(
	'Shipping rules: a large quantity of a cheap print reaches the minimum exactly',
	function () use ( $t, $shiprules_flat_or_free, $shiprules_offered ) {
		$t::add( $t::product( 0.05, array( 'taxable' => false ) ), 1000 );
		$shiprules_flat_or_free();
		$cart = $t::calculate();
		$t::same( array( 'spcshiprulesfree' ), $shiprules_offered(), 'only free shipping is offered' );
		$t::money( 50, $cart->get_total(), 'total' );
	}
);

/*
 * Hide others: "If multiple flat rate options are available, only show this one if it is the
 * highest priced". Changelog 3.0.4: "only show a single shipping option (most expensive)".
 */

$shiprules_two_solo_rates = function () use ( $shiprules_setup ) {
	$shiprules_setup(
		array(
			'spcshiprulesbox'  => array(
				'id'         => 'flat_rate',
				'price'      => '5',
				'price_type' => 'cart',
				'solo'       => '1',
			),
			'spcshiprulesitem' => array(
				'id'         => 'flat_rate',
				'price'      => '2',
				'price_type' => 'qty',
				'solo'       => '1',
			),
		)
	);
};

$t::test(
	'Hide others: the rate that costs the most for this cart is the only one offered',
	function () use ( $t, $shiprules_two_solo_rates, $shiprules_offered, $shiprules_chosen, $shiprules_line_by_hash ) {
		$line = $t::add( $t::product( 10, array( 'taxable' => false ) ), 4 );
		$shiprules_two_solo_rates();
		$cart = $t::calculate();
		// Per item: 4 x 2 = 8, more than the 5 for the box.
		$t::same( array( 'spcshiprulesitem' ), $shiprules_offered(), 'only the per item rate is offered with 4 items' );
		$t::same( 'spcshiprulesitem', $shiprules_chosen(), 'per item rate is chosen' );
		$t::money( 8, $cart->get_shipping(), 'shipping with 4 items' );
		$t::money( 48, $cart->get_total(), 'total 40 + 8' );

		$shiprules_line_by_hash( $line['hash'], 2 );
		$cart = $t::new_request();
		// Per item: 2 x 2 = 4, less than the 5 for the box.
		$t::same( array( 'spcshiprulesbox' ), $shiprules_offered(), 'only the box rate is offered with 2 items' );
		$t::money( 5, $cart->get_shipping(), 'shipping with 2 items' );
		$t::money( 25, $cart->get_total(), 'total 20 + 5' );
	}
);

// Changelog 3.0.10: "Hide others" no longer removes Pickup locations.
$t::test(
	'Hide others: pickup is still offered next to the highest rate',
	function () use ( $t, $shiprules_setup, $shiprules_offered ) {
		$t::add( $t::product( 10, array( 'taxable' => false ) ) );
		$shiprules_setup(
			array(
				'spcshiprulescheap'  => array(
					'id'         => 'flat_rate',
					'price'      => '5',
					'price_type' => 'cart',
					'solo'       => '1',
				),
				'spcshiprulesdear'   => array(
					'id'         => 'flat_rate',
					'price'      => '10',
					'price_type' => 'cart',
					'solo'       => '1',
				),
				'spcshiprulespickup' => array(
					'id'       => 'pickup',
					'price'    => '0',
					'location' => '1 Studio Lane',
				),
			)
		);
		$t::calculate();
		$t::same( array( 'spcshiprulesdear', 'spcshiprulespickup' ), $shiprules_offered(), 'the 10 rate and pickup are offered' );
		SPC()->cart->set_delivery_method( 'spcshiprulespickup' );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_shipping(), 'pickup is free' );
		$t::money( 10, $cart->get_total(), 'total' );
	}
);

// Prices include tax: the 10.00 rate is taxed (8.33 + 1.67 tax), the 9.00 rate is not.
// The customer pays 10.00 or 9.00, and the photographer typed 10 and 9, so the 10.00 rate
// is the highest priced either way.
$t::test(
	'Hide others: prices include tax, the highest price is the one the customer pays',
	function () use ( $t, $shiprules_setup, $shiprules_prices_include_tax, $shiprules_offered ) {
		$shiprules_prices_include_tax( 20 );
		$t::add( $t::product( 30 ) );
		$shiprules_setup(
			array(
				'spcshiprulestaxed'   => array(
					'id'         => 'flat_rate',
					'price'      => '10',
					'price_type' => 'cart',
					'taxable'    => '1',
					'solo'       => '1',
				),
				'spcshiprulesuntaxed' => array(
					'id'         => 'flat_rate',
					'price'      => '9',
					'price_type' => 'cart',
					'taxable'    => '',
					'solo'       => '1',
				),
			)
		);
		$cart = $t::calculate();
		$t::same( array( 'spcshiprulestaxed' ), $shiprules_offered(), 'only the 10.00 rate is offered' );
		$t::money( 10, (float) $cart->get_shipping() + (float) $cart->get_shipping_tax(), 'shipping paid' );
		$t::money( 40, $cart->get_total(), 'total 30 + 10' );
	}
);

/*
 * Products: "Only make this shipping option available when cart contains only the selected
 * products. Leave empty for all products." (the setting's own description).
 */

$shiprules_envelope_or_box = function ( $small_print ) use ( $shiprules_setup ) {
	$shiprules_setup(
		array(
			'spcshiprulesenvelope' => array(
				'id'               => 'flat_rate',
				'price'            => '2',
				'price_type'       => 'cart',
				'allowed_products' => array( (string) $small_print ),
			),
			'spcshiprulesbox'      => array(
				'id'         => 'flat_rate',
				'price'      => '8',
				'price_type' => 'cart',
			),
		)
	);
};

$t::test(
	'Products rule: the envelope rate is offered for a cart of only small prints',
	function () use ( $t, $shiprules_envelope_or_box, $shiprules_choose, $shiprules_offered, $shiprules_chosen ) {
		$small = $t::product( 5, array( 'taxable' => false ) );
		$t::add( $small, 3 );
		$shiprules_envelope_or_box( $small );
		$shiprules_choose( 'spcshiprulesenvelope' );
		$cart = $t::calculate();
		$t::same( array( 'spcshiprulesbox', 'spcshiprulesenvelope' ), $shiprules_offered(), 'both rates are offered' );
		$t::same( 'spcshiprulesenvelope', $shiprules_chosen(), 'envelope is chosen' );
		$t::money( 2, $cart->get_shipping(), 'shipping' );
		$t::money( 17, $cart->get_total(), 'total 15 + 2' );
	}
);

// Assumption: the setting says "contains only the selected products", so a canvas in the cart
// must not ship at the 2.00 envelope rate. The docs only say "contains specific products".
$t::test(
	'Products rule: the envelope rate is not offered once a canvas is in the cart',
	function () use ( $t, $shiprules_envelope_or_box, $shiprules_choose, $shiprules_offered ) {
		$small  = $t::product( 5, array( 'taxable' => false ) );
		$canvas = $t::product( 100, array( 'taxable' => false ) );
		$t::add( $canvas );
		$shiprules_envelope_or_box( $small );
		$shiprules_choose( 'spcshiprulesenvelope' );
		$cart = $t::calculate();
		$t::same( array( 'spcshiprulesbox' ), $shiprules_offered(), 'only the box rate with just a canvas' );
		$t::money( 8, $cart->get_shipping(), 'box rate with just a canvas' );

		$t::add( $small );
		$shiprules_choose( 'spcshiprulesenvelope' );
		$cart = $t::new_request();
		$t::same( array( 'spcshiprulesbox' ), $shiprules_offered(), 'only the box rate with a canvas and a small print' );
		$t::money( 8, $cart->get_shipping(), 'box rate with a canvas and a small print' );
		$t::money( 113, $cart->get_total(), 'total 105 + 8' );
	}
);

/*
 * Include and Exclude Galleries. "Child galleries are included automatically."
 */

// A published gallery with one photo, saved the way the gallery screen and uploader save them.
$shiprules_gallery = function ( $parent_id = 0 ) use ( $t ) {
	$gallery_id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-gallery',
			'post_status' => 'publish',
			'post_title'  => 'Shipping rules test gallery',
			'post_parent' => $parent_id,
		)
	);
	$t::track( 'post', $gallery_id );
	update_post_meta( $gallery_id, 'status', 'default' );
	update_post_meta( $gallery_id, 'access_type', '' );
	$image_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'shipping-rules-test-photo',
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		false,
		$gallery_id
	);
	$t::track( 'post', $image_id );
	sunshine_get_gallery( $gallery_id )->add_image_id( $image_id );
	return array( $gallery_id, $image_id );
};

// Add a photo to the cart the way the gallery's add to cart button does.
$shiprules_add_photo = function ( $product_id, $image_id, $qty = 1 ) {
	$image = sunshine_get_image( $image_id );
	$item  = SPC()->cart->add_item( $product_id, $image_id, $image->get_gallery_id(), $image->get_price_level(), '', $qty );
	if ( empty( $item ) ) {
		throw new Exception( 'Could not add photo ' . $image_id . ' to the cart' );
	}
	return $item;
};

$t::test(
	'Galleries rule: a rate for a parent gallery covers photos from its child gallery',
	function () use ( $t, $shiprules_setup, $shiprules_gallery, $shiprules_add_photo, $shiprules_choose, $shiprules_offered ) {
		$t::option( 'proofing', false );
		list( $school_id )     = $shiprules_gallery();
		list( , $class_photo ) = $shiprules_gallery( $school_id );
		$print                 = $t::product( 20, array( 'taxable' => false ) );
		$shiprules_add_photo( $print, $class_photo );
		$shiprules_setup(
			array(
				'spcshiprulesschool' => array(
					'id'                => 'flat_rate',
					'price'             => '1',
					'price_type'        => 'cart',
					'allowed_galleries' => array( (string) $school_id ),
				),
				'spcshiprulesbox'    => array(
					'id'         => 'flat_rate',
					'price'      => '8',
					'price_type' => 'cart',
				),
			)
		);
		$shiprules_choose( 'spcshiprulesschool' );
		$cart = $t::calculate();
		$t::same( array( 'spcshiprulesbox', 'spcshiprulesschool' ), $shiprules_offered(), 'school rate is offered' );
		$t::money( 1, $cart->get_shipping(), 'school rate' );
		$t::money( 21, $cart->get_total(), 'total 20 + 1' );
	}
);

$t::test(
	'Galleries rule: a rate is hidden when a photo comes from an excluded gallery or its child',
	function () use ( $t, $shiprules_setup, $shiprules_gallery, $shiprules_add_photo, $shiprules_choose, $shiprules_offered ) {
		$t::option( 'proofing', false );
		list( $school_id )       = $shiprules_gallery();
		list( , $class_photo )   = $shiprules_gallery( $school_id );
		list( , $wedding_photo ) = $shiprules_gallery();
		$print                   = $t::product( 20, array( 'taxable' => false ) );
		$shiprules_add_photo( $print, $wedding_photo );
		$shiprules_setup(
			array(
				'spcshiprulesmail' => array(
					'id'                 => 'flat_rate',
					'price'              => '4',
					'price_type'         => 'cart',
					'excluded_galleries' => array( (string) $school_id ),
				),
				'spcshiprulesbox'  => array(
					'id'         => 'flat_rate',
					'price'      => '8',
					'price_type' => 'cart',
				),
			)
		);
		$shiprules_choose( 'spcshiprulesmail' );
		$cart = $t::calculate();
		$t::same( array( 'spcshiprulesbox', 'spcshiprulesmail' ), $shiprules_offered(), 'both offered with only the wedding photo' );
		$t::money( 4, $cart->get_shipping(), 'mail rate with only the wedding photo' );

		$shiprules_add_photo( $print, $class_photo );
		$shiprules_choose( 'spcshiprulesmail' );
		$cart = $t::new_request();
		$t::same( array( 'spcshiprulesbox' ), $shiprules_offered(), 'mail rate hidden once a school photo is in' );
		$t::money( 8, $cart->get_shipping(), 'box rate' );
		$t::money( 48, $cart->get_total(), 'total 40 + 8' );
	}
);

/*
 * Allowed Countries, States/Provinces.
 */

$t::test(
	'Countries rule: a rate for one state is dropped when the address moves to another state',
	function () use ( $t, $shiprules_setup, $shiprules_choose, $shiprules_offered, $shiprules_chosen ) {
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$shiprules_setup(
			array(
				'spcshiprulescolorado' => array(
					'id'                => 'flat_rate',
					'price'             => '5',
					'price_type'        => 'cart',
					'allowed_countries' => array( 'US|CO' ),
				),
				'spcshiprulesusa'      => array(
					'id'                => 'flat_rate',
					'price'             => '10',
					'price_type'        => 'cart',
					'allowed_countries' => array( 'US' ),
				),
			)
		);
		$t::checkout_address();
		$shiprules_choose( 'spcshiprulescolorado' );
		$cart = $t::calculate();
		$t::same( array( 'spcshiprulescolorado', 'spcshiprulesusa' ), $shiprules_offered(), 'both rates in Colorado' );
		$t::money( 5, $cart->get_shipping(), 'Colorado rate' );

		SPC()->cart->set_checkout_data_item( 'shipping_state', 'CA' );
		$cart = $t::new_request();
		$t::same( array( 'spcshiprulesusa' ), $shiprules_offered(), 'only the US rate in California' );
		$t::same( 'spcshiprulesusa', $shiprules_chosen(), 'US rate is chosen' );
		$t::money( 10, $cart->get_shipping(), 'US rate' );
		$t::money( 30, $cart->get_total(), 'total 20 + 10' );
	}
);

// Changelog 3.0.10: country and state limits do not apply to Pickup.
$t::test(
	'Countries rule: pickup is offered whatever the address says',
	function () use ( $t, $shiprules_setup, $shiprules_offered ) {
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$shiprules_setup(
			array(
				'spcshiprulesbox'    => array(
					'id'         => 'flat_rate',
					'price'      => '8',
					'price_type' => 'cart',
				),
				'spcshiprulespickup' => array(
					'id'                => 'pickup',
					'price'             => '2',
					'location'          => '1 Studio Lane',
					'allowed_countries' => array( 'CA' ),
				),
			)
		);
		$t::checkout_address();
		$t::calculate();
		$t::same( array( 'spcshiprulesbox', 'spcshiprulespickup' ), $shiprules_offered(), 'pickup offered to a US address' );
		SPC()->cart->set_delivery_method( 'spcshiprulespickup' );
		$cart = $t::calculate();
		$t::money( 2, $cart->get_shipping(), 'pickup fee' );
		$t::money( 22, $cart->get_total(), 'total 20 + 2' );
	}
);

// The docs list order amount limits for Local Delivery and Pickup too.
$t::test(
	'Shipping rules: local delivery and pickup follow their order amount limits',
	function () use ( $t, $shiprules_setup, $shiprules_offered, $shiprules_choose, $shiprules_line_by_hash ) {
		$line = $t::add( $t::product( 40, array( 'taxable' => false ) ) );
		$shiprules_setup(
			array(
				'spcshiprulesbox'    => array(
					'id'         => 'flat_rate',
					'price'      => '10',
					'price_type' => 'cart',
				),
				'spcshipruleslocal'  => array(
					'id'        => 'local',
					'price'     => '5',
					'order_min' => '50',
				),
				'spcshiprulespickup' => array(
					'id'        => 'pickup',
					'price'     => '0',
					'location'  => '1 Studio Lane',
					'order_max' => '50',
				),
			)
		);
		$t::checkout_address();
		$t::calculate();
		$t::same( array( 'spcshiprulesbox', 'spcshiprulespickup' ), $shiprules_offered(), 'at 40: no local delivery' );

		$shiprules_line_by_hash( $line['hash'], 2 );
		$shiprules_choose( 'spcshipruleslocal' );
		$cart = $t::new_request();
		$t::same( array( 'spcshiprulesbox', 'spcshipruleslocal' ), $shiprules_offered(), 'at 80: local delivery, no pickup' );
		$t::money( 5, $cart->get_shipping(), 'local delivery' );
		$t::money( 85, $cart->get_total(), 'total 80 + 5' );
	}
);
