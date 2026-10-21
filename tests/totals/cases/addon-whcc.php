<?php
/**
 * WHCC add-on: the cart math.
 *
 * WHCC sets the price of its products. The photographer picks a markup percent per price level,
 * the markup goes to WHCC's editor when it opens, and the cart charges what WHCC says the
 * finished item costs after the markup. Tax, discount codes, gift cards and store credit then
 * treat the item like any other product at that price.
 *
 * Shipping is WHCC's drop ship. WHCC ships some things as separate packages (each card design,
 * album and book, and each product group) and charges for every package. The photographer can
 * set their own price per group and method; with none set, the customer pays WHCC's cost from
 * WHCC's price sheet (WHCC-Drop-Ship-Sunshine_Photo_12-2025 in the add-on). Alaska, Hawaii and
 * signature fees are passed on at WHCC's cost, for every package.
 *
 * Setup is in addon-whcc-helpers.php. Nothing here reaches WHCC: a made-up WHCC answers.
 */

require_once __DIR__ . '/addon-whcc-helpers.php';

$t = 'SPC_Totals_Test';

/*
 * Item prices
 */

$whcc_test(
	'the cart charges what the WHCC editor quoted',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_line ) {
		$product = $whcc_product( 'print', null, false );
		// 3 prints. WHCC's cost is 6.00 each; with the 50% markup WHCC quotes 9.00 each, 27.00.
		$editor = $whcc_editor( $product, 3, 9.00, 6.00 );
		$item   = $whcc_add_from_editor( $editor );
		$t::same( true, ! empty( $item ), 'the item is in the cart' );
		$cart = $t::calculate();
		$line = $whcc_line( $editor );
		$t::same( 3, $line ? $line->get_qty() : null, 'quantity from the editor' );
		$t::money( 9.00, $line ? $line->get_price() : null, 'price for one' );
		$t::money( 27.00, $line ? $line->get_total() : null, 'line total' );
		$t::money( 6.00, $line ? $line->get_meta_value( 'whcc_unit_cost' ) : null, "WHCC's cost for one is kept for profit reports" );
		$t::money( 27.00, $cart->get_subtotal(), 'subtotal' );
		$t::money( 27.00, $cart->get_total(), 'total' );
	}
);

// The add-on finds the store product for the editor by its WHCC product id. With the Video
// Sales add-on on as well (as on many stores), it must still find that product and nothing
// else: the product decides tax, the shipping group and what is sent to WHCC.
$whcc_test(
	'with Video Sales on, the item added is the WHCC product the customer built',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_line, $whcc_ship_setup, $whcc_ship_with ) {
		$t::require_plugin( 'sunshine_video_sales_filter_product_args', 'Video Sales add-on' );
		$t::tax( 10 );
		$album  = $whcc_product( 'album' );
		$editor = $whcc_editor( $album, 1, 400.00, 250.00 );
		$item   = $whcc_add_from_editor( $editor );
		$t::same( $album, $item ? (int) $item['product_id'] : null, 'the line is the WHCC album product' );
		$whcc_ship_setup();
		$whcc_ship_with( 'one_day_priority' );
		$cart = $t::calculate();
		$t::money( 40.00, $cart->get_tax(), 'tax uses the album product: 10% of 400.00' );
		// Albums priority one-day: (7.95 + 6.95) + 18%.
		$t::money( 17.58, $cart->get_shipping(), 'album shipping' );
	},
	true
);

// The editor shows the customer WHCC's price for the whole line. The cart must charge that,
// not a rounded price for one item times the quantity.
$whcc_test(
	'a line costs exactly what WHCC quoted, even when it does not divide evenly',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_line ) {
		$cards   = $whcc_product( 'card', null, false );
		$wallets = $whcc_product( 'print', null, false );
		// 3 cards for 10.00 (3.333... each).
		$card_editor = $whcc_editor( $cards, 3, 0, 2.50, array( 'lines' => array( 3 => 10.00 ) ) );
		// 250 wallet prints for 92.47 (0.36988 each).
		$wallet_editor = $whcc_editor( $wallets, 250, 0, 0.25, array( 'lines' => array( 250 => 92.47 ) ) );
		$whcc_add_from_editor( $card_editor );
		$whcc_add_from_editor( $wallet_editor );
		$cart = $t::calculate();
		$card_line   = $whcc_line( $card_editor );
		$wallet_line = $whcc_line( $wallet_editor );
		$t::money( 10.00, $card_line ? $card_line->get_total() : null, '3 cards' );
		$t::money( 92.47, $wallet_line ? $wallet_line->get_total() : null, '250 wallets' );
		// 10.00 + 92.47.
		$t::money( 102.47, $cart->get_total(), 'total' );
	}
);

// WHCC prices by quantity, so a new quantity in the cart gets WHCC's price for that quantity.
$whcc_test(
	'changing the quantity in the cart gets WHCC\'s price for the new quantity',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_line, $whcc_update_cart ) {
		$product = $whcc_product( 'print', null, false );
		// 9.00 each, but 5 cost 40.00 (8.00 each).
		$editor = $whcc_editor( $product, 3, 9.00, 6.00, array( 'lines' => array( 5 => 40.00 ) ) );
		$whcc_add_from_editor( $editor );
		$cart = $whcc_update_cart( array( $editor => 5 ) );
		$line = $whcc_line( $editor );
		$t::same( 5, $line ? $line->get_qty() : null, 'quantity' );
		$t::money( 8.00, $line ? $line->get_price() : null, 'price for one' );
		$t::money( 40.00, $cart->get_total(), 'total' );

		$cart = $t::new_request();
		$t::money( 40.00, $cart->get_total(), 'total on the next page load' );
	}
);

$whcc_test(
	'if WHCC cannot be reached, the cart keeps the price it had',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_line, $whcc_update_cart ) {
		$product = $whcc_product( 'print', null, false );
		$editor  = $whcc_editor( $product, 3, 9.00, 6.00 );
		$whcc_add_from_editor( $editor );
		$whcc_lab['fail_export'] = true;
		$cart                    = $whcc_update_cart();
		$line                    = $whcc_line( $editor );
		$t::money( 27.00, $cart->get_total(), 'total is still 3 x 9.00' );
		$t::money( 6.00, $line ? $line->get_meta_value( 'whcc_unit_cost' ) : null, "WHCC's cost is kept" );
	}
);

// Shipping is priced on every cart and checkout page, and needs WHCC's package split. When WHCC
// is slow or down, the site waits a short time once, then stops asking for that same cart.
$whcc_test(
	'if WHCC cannot be reached for the package split, later pages don\'t wait on it again',
	function () use ( $t, &$whcc_lab, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_sent ) {
		$product = $whcc_product( 'card', null, false );
		$editor  = $whcc_editor( $product, 2, 3.00, 1.50 );
		$whcc_add_from_editor( $editor );
		SPC()->session->set( 'whcc_cart_split', '' );
		$whcc_lab['fail_export'] = true;

		$timeouts = array();
		$grab     = function ( $pre, $args, $url ) use ( &$timeouts ) {
			if ( false !== strpos( $url, 'oas/editors/export' ) ) {
				$timeouts[] = isset( $args['timeout'] ) ? $args['timeout'] : null;
			}
			return $pre;
		};
		// A new page load: what the add-on remembers for one page load is gone.
		$next_page = function () {
			$lookups = new ReflectionProperty( 'SPC_WHCC_Shipping', 'split_lookups' );
			$lookups->setAccessible( true );
			$lookups->setValue( null, array() );
		};
		add_filter( 'pre_http_request', $grab, 10, 3 );
		try {
			$before = count( $whcc_sent( 'PUT', 'oas/editors/export' ) );
			$groups = SPC_WHCC_Shipping::get_cart_sub_order_groups();
			$t::same( array( 'everything_else' ), $groups, 'falls back to the product groups' );
			$t::same( $before + 1, count( $whcc_sent( 'PUT', 'oas/editors/export' ) ), 'WHCC asked once' );
			$t::same( array( 10 ), $timeouts, 'waits 10 seconds at most' );

			$next_page();
			SPC_WHCC_Shipping::get_cart_sub_order_groups();
			$t::same( $before + 1, count( $whcc_sent( 'PUT', 'oas/editors/export' ) ), 'not asked again on the next page for the same cart' );

			// The cart changes, so WHCC is asked about the new one.
			foreach ( (array) SPC()->cart->get_cart() as $key => $item ) {
				SPC()->cart->update_item_quantity( $key, 3 );
			}
			$whcc_lab['fail_export'] = false;
			$t::new_request();
			$next_page();
			SPC_WHCC_Shipping::get_cart_sub_order_groups();
			$t::same( $before + 2, count( $whcc_sent( 'PUT', 'oas/editors/export' ) ), 'asked again once the cart changed' );
		} finally {
			remove_filter( 'pre_http_request', $grab, 10 );
		}
	}
);

/*
 * Markup per price level. The markup is sent to WHCC when the editor opens, so it decides
 * the price the customer pays. A gallery's photos are sold at the gallery's price level.
 */

$whcc_test(
	'each price level has its own markup',
	function () use ( $t, $whcc_product, $whcc_other_level ) {
		$default = sunshine_get_default_price_level_id();
		$other   = $whcc_other_level();
		$product = $whcc_product(
			'print',
			array(
				$default => '35',
				$other   => '20',
			)
		);
		$t::same( 35.0, sunshine_get_product( $product, $default )->get_markup(), 'default price level' );
		$t::same( 20.0, sunshine_get_product( $product, $other )->get_markup(), 'other price level' );
		$t::same( 35.0, sunshine_get_product( $product )->get_markup(), 'no price level given uses the default' );

		$zero = $whcc_product( 'print', array( $default => '0' ) );
		$t::same( 0.0, sunshine_get_product( $zero, $default )->get_markup(), 'a markup of 0 is kept as 0' );
	}
);

// Opening a print: the store loads the product at the photo's price level (core's product
// details step), so the gallery's markup goes to WHCC.
$whcc_test(
	'a print opened from a gallery sends that gallery\'s markup to WHCC',
	function () use ( $t, $whcc_product, $whcc_other_level, $whcc_gallery, $whcc_sent ) {
		$default = sunshine_get_default_price_level_id();
		$other   = $whcc_other_level();
		$product = $whcc_product(
			'print',
			array(
				$default => '35',
				$other   => '20',
			)
		);
		$gallery = $whcc_gallery( $other );
		$image   = sunshine_get_image( $gallery['photo'] );
		$result  = sunshine_whcc_product_details( array( 'html' => '' ), sunshine_get_product( $product, $image->get_price_level() ), $image );
		$t::same( true, ! empty( $result['url'] ), 'the editor opens' );
		$opened = $whcc_sent( 'POST', 'editors' );
		$t::money( 20, isset( $opened[0]['settings']['client']['markupAmount'] ) ? $opened[0]['settings']['client']['markupAmount'] : null, 'markup sent to WHCC' );
	}
);

// Cards, albums and books open the editor after the customer picks a design. That step must
// use the gallery's price level too, or a customer in a 20% gallery pays the 35% markup.
$whcc_test(
	'a card design opened from a gallery sends that gallery\'s markup to WHCC',
	function () use ( $t, $whcc_product, $whcc_other_level, $whcc_gallery, $whcc_sent, $whcc_ajax ) {
		$default = sunshine_get_default_price_level_id();
		$other   = $whcc_other_level();
		$product = $whcc_product(
			'card',
			array(
				$default => '35',
				$other   => '20',
			)
		);
		$gallery            = $whcc_gallery( $other );
		$_POST['security']   = wp_create_nonce( 'sunshinephotocart' );
		$_POST['design_id']  = 'spcdesign1';
		$_POST['product_id'] = (string) $product;
		$_POST['image_id']   = (string) $gallery['photo'];
		$reply               = $whcc_ajax( 'sunshine_whcc_get_editor_url' );
		$t::same( true, ! empty( $reply['success'] ), 'the editor opens' );
		$opened = $whcc_sent( 'POST', 'editors' );
		$t::money( 20, isset( $opened[0]['settings']['client']['markupAmount'] ) ? $opened[0]['settings']['client']['markupAmount'] : null, 'markup sent to WHCC' );
	}
);

// A photographer can sell a WHCC product only in galleries that use one price level, by
// leaving the markup blank for the others. Coming back from the editor in such a gallery
// must still put the item in the cart, at that gallery's price level.
$whcc_test(
	'a product sold only at a gallery\'s price level can be added from that gallery',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_other_level, $whcc_gallery ) {
		$other   = $whcc_other_level();
		$product = $whcc_product( 'print', array( $other => '20' ), false );
		$gallery = $whcc_gallery( $other );
		// 2 prints, 12.00 each after the 20% markup.
		$editor = $whcc_editor( $product, 2, 12.00, 10.00, array( 'photo' => $gallery['photo'] ) );
		$item   = $whcc_add_from_editor( $editor );
		$t::same( true, ! empty( $item ), 'the item is in the cart' );
		if ( $item ) {
			$t::same( $other, (int) $item['price_level'], "the line uses the gallery's price level" );
			$cart = $t::calculate();
			$t::money( 24.00, $cart->get_total(), 'total' );
		}
	}
);

/*
 * Tax
 */

$whcc_test(
	'tax on a taxed WHCC item next to an untaxed one',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor ) {
		$t::tax( 10 );
		$taxed   = $whcc_product( 'print' );
		$untaxed = $whcc_product( 'wrap', null, false );
		$whcc_add_from_editor( $whcc_editor( $taxed, 3, 9.00, 6.00 ) );
		$whcc_add_from_editor( $whcc_editor( $untaxed, 1, 20.00, 12.00 ) );
		$cart = $t::calculate();
		// 10% of the taxed 27.00. The 20.00 canvas is not taxed.
		$t::money( 2.70, $cart->get_tax(), 'tax' );
		$t::money( 49.70, $cart->get_total(), '27.00 + 20.00 + 2.70' );
	}
);

// With prices that include tax, the WHCC price is the price the customer pays, tax inside it.
$whcc_test(
	'prices include tax',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$product = $whcc_product( 'print' );
		$whcc_add_from_editor( $whcc_editor( $product, 3, 11.00, 6.00 ) );
		$cart = $t::calculate();
		// 33.00 with tax inside: 30.00 + 3.00 tax.
		$t::money( 3.00, $cart->get_tax(), 'tax inside the price' );
		$t::money( 33.00, $cart->get_total(), 'total is what WHCC quoted' );
	}
);

/*
 * Shipping
 */

$whcc_test(
	'shipping: lowest cost with no price set is WHCC\'s 7.95',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_offered ) {
		$product = $whcc_product( 'print', null, false );
		$whcc_add_from_editor( $whcc_editor( $product, 3, 9.00, 6.00 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::same( array( 'lowest_cost', 'expedited', 'one_day_standard', 'one_day_priority' ), $whcc_offered(), 'every method is offered for prints' );
		$t::money( 7.95, $cart->get_shipping(), 'shipping' );
		$t::money( 34.95, $cart->get_total(), '27.00 + 7.95' );
	}
);

$whcc_test(
	'shipping: the photographer\'s own price',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		// Saved by the method's settings page as numbers.
		$t::option( 'whcc_shipping_prices', array( 'everything_else' => array( 'lowest_cost' => 12.0 ) ) );
		$product = $whcc_product( 'print', null, false );
		$whcc_add_from_editor( $whcc_editor( $product, 3, 9.00, 6.00 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::money( 12.00, $cart->get_shipping(), 'shipping' );
		$t::money( 39.00, $cart->get_total(), '27.00 + 12.00' );
	}
);

$whcc_test(
	'shipping: free when the photographer sets 0',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$t::option( 'whcc_shipping_prices', array( 'everything_else' => array( 'lowest_cost' => 0.0 ) ) );
		$product = $whcc_product( 'print', null, false );
		$whcc_add_from_editor( $whcc_editor( $product, 3, 9.00, 6.00 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 27.00, $cart->get_total(), 'total' );
	}
);

// WHCC ships wall art and other products as separate packages and charges for both.
$whcc_test(
	'shipping: wall art and prints are two packages',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$print = $whcc_product( 'print', null, false );
		$wrap  = $whcc_product( 'wrap', null, false );
		$whcc_add_from_editor( $whcc_editor( $print, 3, 9.00, 6.00 ) );
		$whcc_add_from_editor( $whcc_editor( $wrap, 1, 80.00, 50.00 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		// 7.95 for each package.
		$t::money( 15.90, $cart->get_shipping(), 'lowest cost' );

		SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcwhccexpedited' );
		$cart = $t::new_request();
		// Wall art expedited: 7.95 + 15% = 9.14. Everything else expedited: 11.50.
		$t::money( 20.64, $cart->get_shipping(), 'expedited' );
		$t::money( 127.64, $cart->get_total(), '27.00 + 80.00 + 20.64' );
	}
);

// Each card design is its own WHCC package (WHCC's shipment grouping sheet).
$whcc_test(
	'shipping: two card designs are two packages after the cart is updated',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_update_cart ) {
		$cards = $whcc_product( 'card', null, false );
		$whcc_add_from_editor( $whcc_editor( $cards, 25, 2.00, 1.20 ) );
		$whcc_add_from_editor( $whcc_editor( $cards, 25, 2.00, 1.20 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$cart = $whcc_update_cart();
		$t::money( 15.90, $cart->get_shipping(), '7.95 for each design' );
		$t::money( 115.90, $cart->get_total(), '50.00 + 50.00 + 15.90' );
	}
);

// The usual way to buy: finish two designs in the editor and go to checkout, without
// pressing Update on the cart page. WHCC still ships and charges two packages.
$whcc_test(
	'shipping: two card designs straight from the editor are two packages',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$cards = $whcc_product( 'card', null, false );
		$whcc_add_from_editor( $whcc_editor( $cards, 25, 2.00, 1.20 ) );
		$whcc_add_from_editor( $whcc_editor( $cards, 25, 2.00, 1.20 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::money( 15.90, $cart->get_shipping(), '7.95 for each design' );
	}
);

// Albums only ship Lowest Cost or Priority One-Day (WHCC's price sheet).
$whcc_test(
	'shipping: an album only offers lowest cost and priority one-day',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_offered ) {
		$album = $whcc_product( 'album', null, false );
		$whcc_add_from_editor( $whcc_editor( $album, 1, 400.00, 250.00 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'expedited' );
		$cart = $t::calculate();
		$t::same( array( 'lowest_cost', 'one_day_priority' ), $whcc_offered(), 'methods offered' );
		$t::same( true, 'spcwhccexpedited' !== (string) SPC()->cart->get_checkout_data_item( 'shipping_method' ), 'expedited is not kept as the choice' );

		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::new_request();
		$t::money( 7.95, $cart->get_shipping(), 'lowest cost' );

		$whcc_ship_with( 'one_day_priority' );
		$cart = $t::new_request();
		// (7.95 + 6.95) + 18% = 17.58.
		$t::money( 17.58, $cart->get_shipping(), 'priority one-day' );
	}
);

$whcc_test(
	'shipping: Alaska adds 5.00 to each package',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$print = $whcc_product( 'print', null, false );
		$wrap  = $whcc_product( 'wrap', null, false );
		$whcc_add_from_editor( $whcc_editor( $print, 3, 9.00, 6.00 ) );
		$whcc_add_from_editor( $whcc_editor( $wrap, 1, 80.00, 50.00 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost', 'AK' );
		$cart = $t::calculate();
		// (7.95 + 5.00) x 2 packages.
		$t::money( 25.90, $cart->get_shipping(), 'shipping' );
	}
);

$whcc_test(
	'shipping: Hawaii adds 6.00 to priority one-day',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$print = $whcc_product( 'print', null, false );
		$whcc_add_from_editor( $whcc_editor( $print, 3, 9.00, 6.00 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'one_day_priority', 'HI' );
		$cart = $t::calculate();
		// 17.58 + 6.00, WHCC's price sheet says 23.58.
		$t::money( 23.58, $cart->get_shipping(), 'shipping' );
	}
);

$whcc_test(
	'shipping: signature required on every order',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$t::option( 'whcc_require_signature', 'yes' );
		$print = $whcc_product( 'print', null, false );
		$whcc_add_from_editor( $whcc_editor( $print, 3, 9.00, 6.00 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		// 7.95 + 10.00 signature (no percent on lowest cost).
		$t::money( 17.95, $cart->get_shipping(), 'lowest cost' );

		$whcc_ship_with( 'one_day_priority' );
		$cart = $t::new_request();
		// (7.95 + 6.95 + 10.00) + 18% = 29.38, as on WHCC's price sheet.
		$t::money( 29.38, $cart->get_shipping(), 'priority one-day' );
	}
);

$whcc_test(
	'shipping: signature once the order reaches the set amount',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$t::option( 'whcc_signature_threshold', '50' );
		$print = $whcc_product( 'print', null, false );
		$whcc_add_from_editor( $whcc_editor( $print, 3, 9.00, 6.00 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::money( 7.95, $cart->get_shipping(), 'a 27.00 order has no signature' );

		$whcc_add_from_editor( $whcc_editor( $print, 3, 9.00, 6.00 ) );
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::new_request();
		$t::money( 17.95, $cart->get_shipping(), 'a 54.00 order adds the 10.00 signature' );
	}
);

// PO boxes, military and US territory addresses ship by mail, which is Lowest Cost only.
$whcc_test(
	'shipping: PO boxes and military addresses only get lowest cost',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_offered ) {
		$print = $whcc_product( 'print', null, false );
		$whcc_add_from_editor( $whcc_editor( $print, 3, 9.00, 6.00 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost', 'CO', 'PO Box 123' );
		$cart = $t::calculate();
		$t::same( array( 'lowest_cost' ), $whcc_offered(), 'PO box' );
		$t::money( 7.95, $cart->get_shipping(), 'PO box shipping' );

		$whcc_ship_with( 'lowest_cost', 'AE', 'Unit 2050 Box 4190' );
		$cart = $t::new_request();
		$t::same( array( 'lowest_cost' ), $whcc_offered(), 'military address' );
		$t::money( 7.95, $cart->get_shipping(), 'military shipping' );
	}
);

$whcc_test(
	'shipping: WHCC drop ship is not offered outside the US',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_offered ) {
		$print = $whcc_product( 'print', null, false );
		$whcc_add_from_editor( $whcc_editor( $print, 3, 9.00, 6.00 ) );
		$whcc_ship_setup();
		$whcc_ship_with( 'lowest_cost', 'ON', '1 Main St', 'CA' );
		$cart = $t::calculate();
		$t::same( array(), $whcc_offered(), 'no WHCC method for Canada' );
		$t::money( 0, $cart->get_shipping(), 'no WHCC shipping is charged' );
	}
);

// WHCC ships its items and the photographer ships their own, so the photographer's set
// surcharge is added to WHCC's shipping. Only WHCC methods are offered.
$whcc_test(
	'shipping: a cart with the photographer\'s own print adds the surcharge',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with, $whcc_offered ) {
		$t::option( 'whcc_mixed_cart_surcharge', '6.00' );
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 ) );
		$t::add( $t::product( 20, array( 'taxable' => false ) ) );
		$whcc_ship_setup(
			array( 'lowest_cost' ),
			false,
			array(
				'spcwhccflat' => array(
					'id'         => 'flat_rate',
					'price'      => '5',
					'price_type' => '',
				),
			)
		);
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::same( array( 'lowest_cost' ), $whcc_offered(), 'only WHCC shipping is offered' );
		// 7.95 + 6.00.
		$t::money( 13.95, $cart->get_shipping(), 'shipping' );
		$t::money( 20.00, $cart->get_cart_items()[1]->get_total(), 'the own print keeps its price' );
		$t::money( 60.95, $cart->get_total(), '27.00 + 20.00 + 13.95' );
	}
);

// A download is not shipped, so it is not a reason for the photographer to ship anything.
$whcc_test(
	'shipping: a download in the cart adds no surcharge',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$t::require_plugin( 'sunshine_product_is_download', 'Digital Downloads add-on' );
		$t::option( 'whcc_mixed_cart_surcharge', '6.00' );
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 ) );
		$t::add( $t::product( 15, array( 'type' => 'download', 'taxable' => false ) ) );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::money( 7.95, $cart->get_shipping(), 'shipping' );
		$t::money( 49.95, $cart->get_total(), '27.00 + 15.00 + 7.95' );
	}
);

$whcc_test(
	'shipping: taxed shipping',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$t::tax( 10 );
		$t::option( 'whcc_shipping_prices', array( 'everything_else' => array( 'lowest_cost' => 10.0 ) ) );
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print' ), 3, 9.00, 6.00 ) );
		$whcc_ship_setup( array( 'lowest_cost' ), true );
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		$t::money( 10.00, $cart->get_shipping(), 'shipping' );
		$t::money( 1.00, $cart->get_shipping_tax(), 'tax on shipping' );
		$t::money( 3.70, $cart->get_tax(), '2.70 on the prints + 1.00 on shipping' );
		$t::money( 40.70, $cart->get_total(), '27.00 + 10.00 + 3.70' );
	}
);

// With prices that include tax, the shipping price set is what the customer pays.
$whcc_test(
	'shipping: taxed shipping when prices include tax',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::option( 'whcc_shipping_prices', array( 'everything_else' => array( 'lowest_cost' => 11.0 ) ) );
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print' ), 3, 11.00, 6.00 ) );
		$whcc_ship_setup( array( 'lowest_cost' ), true );
		$whcc_ship_with( 'lowest_cost' );
		$cart = $t::calculate();
		// 11.00 with tax inside: 10.00 + 1.00.
		$t::money( 10.00, $cart->get_shipping(), 'shipping before tax' );
		$t::money( 1.00, $cart->get_shipping_tax(), 'tax inside shipping' );
		$t::money( 44.00, $cart->get_total(), '33.00 + 11.00' );
	}
);

// What the customer pays (no price set by the photographer) and what WHCC charges, for every
// group, method and place, against the totals on WHCC's price sheet.
$whcc_test(
	'shipping costs match WHCC\'s price sheet',
	function () use ( $t, $whcc_price_sheet ) {
		$sheet = array(
			// group, method, state, signature, price sheet total.
			array( 'albums', 'lowest_cost', 'CO', false, 7.95 ),
			array( 'albums', 'one_day_priority', 'CO', false, 17.58 ),
			array( 'albums', 'one_day_priority', 'AK', false, 22.58 ),
			array( 'albums', 'one_day_priority', 'HI', false, 23.58 ),
			array( 'albums', 'one_day_priority', 'CO', true, 29.38 ),
			array( 'books', 'one_day_priority', 'CO', true, 29.38 ),
			array( 'books', 'one_day_standard', 'CO', true, 24.53 ),
			array( 'everything_else', 'lowest_cost', 'CO', false, 7.95 ),
			array( 'everything_else', 'expedited', 'CO', false, 11.50 ),
			array( 'everything_else', 'expedited', 'AK', false, 16.50 ),
			array( 'everything_else', 'expedited', 'HI', false, 17.50 ),
			array( 'everything_else', 'one_day_standard', 'CO', false, 13.33 ),
			array( 'everything_else', 'one_day_standard', 'AK', false, 18.33 ),
			array( 'everything_else', 'one_day_standard', 'HI', false, 19.33 ),
			array( 'everything_else', 'one_day_priority', 'CO', false, 17.58 ),
			array( 'everything_else', 'one_day_priority', 'AK', false, 22.58 ),
			array( 'everything_else', 'one_day_priority', 'HI', false, 23.58 ),
			array( 'wall_art', 'lowest_cost', 'CO', false, 7.95 ),
			array( 'wall_art', 'expedited', 'CO', false, 9.14 ),
			array( 'wall_art', 'expedited', 'AK', false, 14.14 ),
			array( 'wall_art', 'expedited', 'HI', false, 15.14 ),
			array( 'wall_art', 'one_day_standard', 'CO', false, 14.52 ),
			array( 'wall_art', 'one_day_standard', 'AK', false, 19.52 ),
			array( 'wall_art', 'one_day_standard', 'HI', false, 20.52 ),
			array( 'wall_art', 'one_day_priority', 'CO', false, 19.07 ),
			array( 'wall_art', 'one_day_priority', 'AK', false, 24.07 ),
			array( 'wall_art', 'one_day_priority', 'HI', false, 25.07 ),
		);
		foreach ( $sheet as $row ) {
			$label  = $row[0] . ' ' . $row[1] . ' to ' . $row[2] . ( $row[3] ? ' with signature' : '' );
			$prices = $whcc_price_sheet( $row[1], $row[0], $row[2], $row[3] );
			$t::money( $row[4], $prices['customer'], $label . ', customer pays' );
			$t::money( $row[4], $prices['lab'], $label . ', WHCC charges' );
		}
	}
);

// The settings page shows WHCC's cost in each Your Price box. Clearing a box means "no price
// of my own", which the add-on treats as WHCC's cost. It must not become free shipping.
$whcc_test(
	'shipping prices: a cleared price box falls back to WHCC\'s cost',
	function () use ( $t ) {
		// The add-on only loads its settings code in wp-admin.
		if ( ! function_exists( 'sunshine_whcc_save_shipping_settings' ) ) {
			$whcc_main = new ReflectionFunction( 'sunshine_whcc_init' );
			require_once dirname( $whcc_main->getFileName() ) . '/admin/shipping-settings.php';
		}
		$t::customer();
		$can = function ( $allcaps ) {
			$allcaps['sunshine_manage_options'] = true;
			return $allcaps;
		};
		$saved   = null;
		$capture = function ( $value, $old_value ) use ( &$saved ) {
			$saved = $value;
			return $old_value; // Nothing is written to the database.
		};
		add_filter( 'user_has_cap', $can );
		add_filter( 'pre_update_option_sunshine_whcc_shipping_prices', $capture, 10, 2 );
		$_POST['option_page']         = 'sunshine_shipping_methods';
		$_POST['_wpnonce']            = wp_create_nonce( 'sunshine_shipping_methods-options' );
		$_POST['whcc_shipping_rules'] = array( 'everything_else' => array( 'lowest_cost' => '' ) );
		try {
			sunshine_whcc_save_shipping_settings();
		} finally {
			remove_filter( 'user_has_cap', $can );
			remove_filter( 'pre_update_option_sunshine_whcc_shipping_prices', $capture, 10 );
			unset( $_POST['option_page'], $_POST['_wpnonce'], $_POST['whcc_shipping_rules'] );
		}
		$t::same( true, is_array( $saved ), 'the settings page saves the prices' );
		$t::option( 'whcc_shipping_prices', is_array( $saved ) ? $saved : array() );
		$t::money( 7.95, SPC_WHCC_Shipping::calculate_customer_price( 'lowest_cost', 'everything_else', 'CO' ), "customer pays WHCC's 7.95" );
	}
);

/*
 * Discount codes, gift cards and store credit
 */

$whcc_test(
	'a percent discount code',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$t::tax( 10 );
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print' ), 3, 9.00, 6.00 ) );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::discount( 'percent-total', 10 );
		$cart = $t::calculate();
		$t::money( 2.70, $cart->get_discount(), '10% of 27.00' );
		$t::money( 2.43, $cart->get_tax(), '10% of 24.30' );
		$t::money( 7.95, $cart->get_shipping(), 'shipping' );
		$t::money( 34.68, $cart->get_total(), '24.30 + 2.43 + 7.95' );
	}
);

$whcc_test(
	'a discount code that includes shipping',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$t::option( 'whcc_shipping_prices', array( 'everything_else' => array( 'lowest_cost' => 13.0 ) ) );
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 ) );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::discount( 'percent-total', 10, array( 'apply_to_shipping' => 1 ) );
		$cart = $t::calculate();
		// 10% of 27.00 + 10% of 13.00.
		$t::money( 4.00, $cart->get_discount(), 'discount' );
		$t::money( 36.00, $cart->get_total(), '40.00 - 4.00' );
	}
);

$whcc_test(
	'a discount taken after tax',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print' ), 3, 9.00, 6.00 ) );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::discount( 'percent-total', 20 );
		$cart = $t::calculate();
		// 27.00 + 2.70 tax = 29.70, less 20% = 23.76, then 7.95 shipping.
		$t::money( 5.94, $cart->get_discount(), '20% of 29.70' );
		$t::money( 31.71, $cart->get_total(), '23.76 + 7.95' );
	}
);

$whcc_test(
	'a gift card pays for the items and WHCC shipping',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 ) );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		$t::gift_card( 50 );
		$cart = $t::calculate();
		$t::money( 34.95, array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) ), 'taken from the card: 27.00 + 7.95' );
		$t::money( 0, $cart->get_total(), 'left to pay' );
	}
);

$whcc_test(
	'store credit',
	function () use ( $t, $whcc_product, $whcc_editor, $whcc_add_from_editor, $whcc_ship_setup, $whcc_ship_with ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		$whcc_add_from_editor( $whcc_editor( $whcc_product( 'print', null, false ), 3, 9.00, 6.00 ) );
		$whcc_ship_setup( array( 'lowest_cost' ) );
		$whcc_ship_with( 'lowest_cost' );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$cart = $t::calculate();
		$t::money( 20, $cart->get_credits_applied(), 'credit used' );
		$t::money( 14.95, $cart->get_total(), '34.95 - 20.00' );
	}
);
