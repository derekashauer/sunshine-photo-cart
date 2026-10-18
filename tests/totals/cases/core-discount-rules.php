<?php
/**
 * Discount code rules (Discount Codes add-on) checked in the cart: solo codes, minimum order
 * amount and quantity, required products, categories, galleries, dates, codes taken off when
 * they stop being valid, and discounts with prices that include tax, shipping and "discount
 * after tax". Every amount is worked out by hand.
 *
 * Codes are entered the way the checkout's Apply button does it, so the add-on's own checks
 * decide whether a code is accepted.
 */

$t = 'SPC_Totals_Test';

// Nothing here may reach an outside service or send an email. Creating galleries and orders
// runs other add-ons' hooks, so every outgoing request and email is stopped and noted.
$discount_rules_blocked = array();
$discount_rules_offline = function ( $pre, $args, $url ) use ( &$discount_rules_blocked ) {
	$discount_rules_blocked[] = $url;
	return new WP_Error( 'spc_totals_offline', 'The totals tests never reach outside services' );
};
$discount_rules_no_mail = function () {
	return false;
};
add_filter( 'pre_http_request', $discount_rules_offline, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', $discount_rules_no_mail, PHP_INT_MAX );

// Adding to the cart makes the Analytics add-on log a row in its own table, and for a logged in
// customer the Automated Emails add-on may queue a reminder email. Remember the products and
// queued emails this file makes, so those can be deleted at the end of this file.
$discount_rules_made_products = array();
$discount_rules_made_emails   = array();
$discount_rules_remember      = function ( $post_id, $post ) use ( &$discount_rules_made_products, &$discount_rules_made_emails ) {
	if ( 'sunshine-product' === $post->post_type ) {
		$discount_rules_made_products[] = (int) $post_id;
	} elseif ( 'sunshine-email-log' === $post->post_type ) {
		$discount_rules_made_emails[] = (int) $post_id;
	}
};
add_action( 'wp_insert_post', $discount_rules_remember, 10, 2 );

/**
 * Make a discount the way the Discounts screen saves one. That screen saves every field:
 * empty ones as '', product and gallery lists as arrays of post IDs, categories as a list of
 * term IDs (as text) and dates as YYYY-MM-DD. $meta replaces any of these.
 * The code is not put in the cart. Returns the discount's post ID and its code.
 */
$discount_rules_make = function ( $meta = array(), $name = '' ) use ( $t ) {
	$t::require_plugin( 'sunshine_get_discount_by_code', 'Discount Codes add-on' );
	$code = 'SPCRULES' . wp_rand( 100000, 999999 );
	$id   = wp_insert_post(
		array(
			'post_type'   => 'sunshine-discount',
			'post_status' => 'publish',
			'post_title'  => $name ? $name : 'Rules test ' . $code,
		)
	);
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

// What the checkout's Apply button does. True when the code was accepted.
$discount_rules_enter = function ( $code ) {
	$result = SPC()->cart->add_discount( $code );
	SPC()->cart->update();
	return ! empty( $result );
};

// The codes saved in the customer's session.
$discount_rules_codes = function () {
	return array_values( array_filter( (array) SPC()->session->get( 'discounts' ) ) );
};

$discount_rules_has_error = function ( $code ) {
	foreach ( (array) SPC()->cart->get_errors() as $error ) {
		if ( false !== strpos( $error, $code ) ) {
			return true;
		}
	}
	return false;
};

$discount_rules_remove_line = function ( $hash ) {
	foreach ( (array) SPC()->cart->get_cart() as $key => $line ) {
		if ( $line['hash'] === $hash ) {
			SPC()->cart->remove_item( $key );
		}
	}
};

// A product category, made the way the Product Categories screen makes one.
$discount_rules_category = function () use ( $t ) {
	$term = wp_insert_term( 'Rules test ' . wp_rand( 100000, 999999 ), 'sunshine-product-category' );
	if ( is_wp_error( $term ) ) {
		throw new Exception( 'Could not make a product category: ' . $term->get_error_message() );
	}
	$t::track( 'term', $term['term_id'], 'sunshine-product-category' );
	return (int) $term['term_id'];
};

$discount_rules_product_in = function ( $price, $category_id, $args = array() ) use ( $t ) {
	$product_id = $t::product( $price, $args );
	wp_set_object_terms( $product_id, $category_id, 'sunshine-product-category' );
	return $product_id;
};

// A published gallery with one photo, saved the way the gallery screen and uploader save them:
// status "default", open access, and the photo's ID in the gallery's image list.
$discount_rules_gallery = function ( $parent_id = 0 ) use ( $t ) {
	$gallery_id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-gallery',
			'post_status' => 'publish',
			'post_title'  => 'Rules test gallery',
			'post_parent' => $parent_id,
		)
	);
	$t::track( 'post', $gallery_id );
	update_post_meta( $gallery_id, 'status', 'default' );
	update_post_meta( $gallery_id, 'access_type', '' );
	$image_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'rules-test-photo',
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
$discount_rules_add_photo = function ( $product_id, $image_id, $qty = 1 ) {
	$image = sunshine_get_image( $image_id );
	$item  = SPC()->cart->add_item( $product_id, $image_id, $image->get_gallery_id(), $image->get_price_level(), '', $qty );
	if ( empty( $item ) ) {
		throw new Exception( 'Could not add photo ' . $image_id . ' to the cart' );
	}
	return $item;
};

$discount_rules_day = function ( $days_from_today ) {
	return wp_date( 'Y-m-d', time() + $days_from_today * DAY_IN_SECONDS );
};

/*
 * Solo codes: "Only allow customer to use this coupon, disallow all other coupons".
 */

$t::test(
	'Discount rules: a solo code is not added to by a second code',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		list( , $solo )  = $discount_rules_make( array( 'discount_amount' => '20', 'solo' => '1' ) );
		list( , $other ) = $discount_rules_make( array( 'discount_type' => 'amount-total', 'discount_amount' => '5' ) );
		$t::same( true, $discount_rules_enter( $solo ), 'solo code accepted' );
		$discount_rules_enter( $other );
		$cart = $t::calculate();
		// Only the solo 20%: 100 x 20%.
		$t::money( 20, $cart->get_discount(), 'discount' );
		$t::money( 80, $cart->get_total(), 'total' );
		$t::same( 1, count( $cart->get_discounts() ), 'one discount in use' );
	}
);

// The cart and the receipt list the names of the discounts in use, so the name shown must be
// the solo code's, not the one it replaced.
$t::test(
	'Discount rules: a solo code entered after another code replaces it',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		list( , $other ) = $discount_rules_make( array( 'discount_type' => 'amount-total', 'discount_amount' => '5' ), 'Five dollars off' );
		list( , $solo )  = $discount_rules_make( array( 'discount_amount' => '20', 'solo' => '1' ), 'Solo twenty percent' );
		$discount_rules_enter( $other );
		$t::same( true, $discount_rules_enter( $solo ), 'solo code accepted' );
		$cart = $t::calculate();
		$t::money( 20, $cart->get_discount(), 'discount is only the solo 20%' );
		$t::money( 80, $cart->get_total(), 'total' );
		$t::same( array( 'Solo twenty percent' ), $cart->get_discount_names(), 'discount names shown in the cart' );
	}
);

/*
 * Minimum order amount: "enter 50.00 to require at least $50 in the cart".
 */

$t::test(
	'Discount rules: minimum order amount, one cent short is refused and the exact amount works',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		list( , $code ) = $discount_rules_make( array( 'min_amount' => '50' ) );
		$t::add( $t::product( 24.99, array( 'taxable' => false ) ), 2 );
		$cart = $t::calculate();
		// 2 x 24.99 = 49.98.
		$t::same( false, $discount_rules_enter( $code ), 'refused at 49.98' );
		$t::money( 0, SPC()->cart->get_discount(), 'no discount at 49.98' );
		$t::add( $t::product( 0.02, array( 'taxable' => false ) ) );
		// 49.98 + 0.02 = 50.00, exactly the minimum.
		$t::same( true, $discount_rules_enter( $code ), 'accepted at 50.00' );
		$cart = $t::calculate();
		$t::money( 5, $cart->get_discount(), '10% of 50' );
		$t::money( 45, $cart->get_total(), 'total' );
	}
);

// Assumption: when prices include tax and are shown with tax, "at least $50 in the cart" means
// the $55 the customer sees, not the $45.84 left after taking the tax out.
$t::test(
	'Discount rules: minimum order amount when prices include tax',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$t::tax( 20 );
		$t::option( 'price_has_tax', 'yes' );
		$t::option( 'display_price', 'with_tax' );
		list( , $code ) = $discount_rules_make( array( 'min_amount' => '50' ) );
		$t::add( $t::product( 27.50 ), 2 );
		$t::calculate();
		// The customer sees 2 x 27.50 = 55.00.
		$t::same( true, $discount_rules_enter( $code ), 'accepted on a 55.00 cart' );
		$cart = $t::calculate();
		// 10% of 55 = 5.50, leaving 49.50, which holds 49.50 - 49.50 / 1.2 = 8.25 tax.
		$t::money( 5.50, $cart->get_discount(), 'discount' );
		$t::money( 8.25, $cart->get_tax(), 'tax included' );
		$t::money( 49.50, $cart->get_total(), 'total' );
	}
);

// A gift card pays for the order; it does not make the order smaller.
$t::test(
	'Discount rules: a gift card does not count against the minimum order amount',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		list( , $code ) = $discount_rules_make( array( 'min_amount' => '50' ) );
		$t::add( $t::product( 60, array( 'taxable' => false ) ) );
		$t::gift_card( 30 );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		$t::money( 6, $cart->get_discount(), '10% of 60' );
		$t::money( 30, array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) ), 'taken from the card' );
		// 60 - 6 - 30.
		$t::money( 24, $cart->get_total(), 'left to pay' );
	}
);

$t::test(
	'Discount rules: store credit does not count against the minimum order amount',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		list( , $code ) = $discount_rules_make( array( 'min_amount' => '50' ) );
		$t::add( $t::product( 60, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		$t::money( 6, $cart->get_discount(), '10% of 60' );
		$t::money( 20, $cart->get_credits_applied(), 'credit used' );
		// 60 - 6 - 20.
		$t::money( 34, $cart->get_total(), 'total' );
	}
);

/*
 * Minimum quantity: "Minimum total quantity of items in cart required for this discount".
 */

$t::test(
	'Discount rules: minimum quantity counts every item, and the code goes when the cart drops below it',
	function () use ( $t, $discount_rules_make, $discount_rules_enter, $discount_rules_codes ) {
		list( , $code ) = $discount_rules_make( array( 'min_quantity' => '5' ) );
		$t::add( $t::product( 10, array( 'taxable' => false ) ), 2 );
		$line = $t::add( $t::product( 5, array( 'taxable' => false ) ), 2 );
		$t::calculate();
		$t::same( false, $discount_rules_enter( $code ), 'refused with 4 items' );
		SPC()->cart->update_item_quantity_by_hash( $line['hash'], 3 );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'accepted with 5 items' );
		$cart = $t::calculate();
		// 10% of ( 2 x 10 + 3 x 5 = 35 ).
		$t::money( 3.50, $cart->get_discount(), 'discount with 5 items' );
		$t::money( 31.50, $cart->get_total(), 'total with 5 items' );
		SPC()->cart->update_item_quantity_by_hash( $line['hash'], 2 );
		$cart = $t::new_request();
		$t::money( 0, $cart->get_discount(), 'no discount back at 4 items' );
		$t::money( 30, $cart->get_total(), 'total back at 4 items' );
		$t::same( false, in_array( $code, $discount_rules_codes(), true ), 'code taken off the cart' );
	}
);

/*
 * Required products: "These products must be in cart before any discounts can be applied".
 */

$t::test(
	'Discount rules: a required product must be in the cart, and the code goes with it',
	function () use ( $t, $discount_rules_make, $discount_rules_enter, $discount_rules_codes, $discount_rules_remove_line ) {
		$required = $t::product( 20, array( 'taxable' => false ) );
		$other    = $t::product( 70, array( 'taxable' => false ) );
		list( , $code ) = $discount_rules_make( array( 'discount_amount' => '20', 'required_products' => array( $required ) ) );
		$t::add( $other );
		$t::calculate();
		$t::same( false, $discount_rules_enter( $code ), 'refused without the required product' );
		$line = $t::add( $required );
		$t::same( true, $discount_rules_enter( $code ), 'accepted with the required product' );
		$cart = $t::calculate();
		// The whole order gets it: 20% of ( 20 + 70 ).
		$t::money( 18, $cart->get_discount(), 'discount' );
		$t::money( 72, $cart->get_total(), 'total' );
		$discount_rules_remove_line( $line['hash'] );
		$cart = $t::new_request();
		$t::money( 0, $cart->get_discount(), 'no discount once the required product is gone' );
		$t::money( 70, $cart->get_total(), 'total' );
		$t::same( false, in_array( $code, $discount_rules_codes(), true ), 'code taken off the cart' );
	}
);

/*
 * Allowed and disallowed products.
 */

$t::test(
	'Discount rules: a fixed amount for chosen products is refused without them and capped at what they cost',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$t::tax( 10 );
		$allowed = $t::product( 8 );
		$other   = $t::product( 50 );
		list( , $code ) = $discount_rules_make(
			array(
				'discount_type'    => 'amount-total',
				'discount_amount'  => '10',
				'allowed_products' => array( $allowed ),
			)
		);
		$t::add( $other );
		$t::calculate();
		$t::same( false, $discount_rules_enter( $code ), 'refused when nothing in the cart can get it' );
		$t::add( $allowed );
		$t::same( true, $discount_rules_enter( $code ), 'accepted once the product is in the cart' );
		$cart = $t::calculate();
		// $10 off, but the allowed product only costs 8. Tax is then on the 50 left: 5.00.
		$t::money( 8, $cart->get_discount(), 'discount capped at the allowed product' );
		$t::money( 5, $cart->get_tax(), 'tax' );
		$t::money( 55, $cart->get_total(), '58 - 8 + 5' );
	}
);

$t::test(
	'Discount rules: disallowed products are left out of a percent off the order',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$t::tax( 10 );
		$excluded = $t::product( 50 );
		$t::add( $excluded );
		$t::add( $t::product( 50 ) );
		list( , $code ) = $discount_rules_make( array( 'discount_amount' => '20', 'disallowed_products' => array( $excluded ) ) );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		// 20% of the other 50 only. Tax on ( 50 + 40 ) = 9.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 9, $cart->get_tax(), 'tax' );
		$t::money( 99, $cart->get_total(), '100 - 10 + 9' );
	}
);

/*
 * Product categories.
 */

$t::test(
	'Discount rules: allowed categories, only those products get the discount',
	function () use ( $t, $discount_rules_make, $discount_rules_enter, $discount_rules_category, $discount_rules_product_in ) {
		$t::tax( 10 );
		$canvas = $discount_rules_category();
		$prints = $discount_rules_category();
		$t::add( $discount_rules_product_in( 80, $canvas ) );
		$t::add( $discount_rules_product_in( 20, $prints ) );
		list( , $code ) = $discount_rules_make( array( 'discount_amount' => '25', 'allowed_categories' => array( (string) $canvas ) ) );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		// 25% of the 80 canvas only. Tax on ( 60 + 20 ) = 8.
		$t::money( 20, $cart->get_discount(), 'discount' );
		$t::money( 8, $cart->get_tax(), 'tax' );
		$t::money( 88, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Discount rules: disallowed categories are left out, and a cart of only those is refused',
	function () use ( $t, $discount_rules_make, $discount_rules_enter, $discount_rules_category, $discount_rules_product_in ) {
		$t::tax( 10 );
		$canvas = $discount_rules_category();
		$prints = $discount_rules_category();
		list( , $code ) = $discount_rules_make( array( 'discount_amount' => '25', 'disallowed_categories' => array( (string) $canvas ) ) );
		$t::add( $discount_rules_product_in( 80, $canvas ) );
		$t::calculate();
		$t::same( false, $discount_rules_enter( $code ), 'refused with only a disallowed product' );
		$t::add( $discount_rules_product_in( 20, $prints ) );
		$t::same( true, $discount_rules_enter( $code ), 'accepted once another product is in the cart' );
		$cart = $t::calculate();
		// 25% of the 20 print only. Tax on ( 80 + 15 ) = 9.50.
		$t::money( 5, $cart->get_discount(), 'discount' );
		$t::money( 9.50, $cart->get_tax(), 'tax' );
		$t::money( 104.50, $cart->get_total(), 'total' );
	}
);

/*
 * Galleries: "Child galleries of selected galleries will automatically be included".
 */

$t::test(
	'Discount rules: allowed galleries include their child galleries and nothing else',
	function () use ( $t, $discount_rules_make, $discount_rules_enter, $discount_rules_gallery, $discount_rules_add_photo ) {
		$t::option( 'proofing', false );
		list( $parent_id )     = $discount_rules_gallery();
		list( , $child_photo ) = $discount_rules_gallery( $parent_id );
		list( , $other_photo ) = $discount_rules_gallery();
		$print                 = $t::product( 40, array( 'taxable' => false ) );
		$discount_rules_add_photo( $print, $child_photo );
		$discount_rules_add_photo( $print, $other_photo );
		// A product that is not a photo, so it is not from any gallery.
		$t::add( $t::product( 10, array( 'taxable' => false ) ) );
		list( , $code ) = $discount_rules_make( array( 'discount_amount' => '50', 'allowed_galleries' => array( $parent_id ) ) );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		// Half off the photo from the child gallery only: 50% of 40.
		$t::money( 20, $cart->get_discount(), 'discount' );
		$t::money( 70, $cart->get_total(), '90 - 20' );
	}
);

$t::test(
	'Discount rules: disallowed galleries leave their photos out',
	function () use ( $t, $discount_rules_make, $discount_rules_enter, $discount_rules_gallery, $discount_rules_add_photo ) {
		$t::option( 'proofing', false );
		list( , $wedding_photo )              = $discount_rules_gallery();
		list( $excluded_id, $excluded_photo ) = $discount_rules_gallery();
		$print                                = $t::product( 40, array( 'taxable' => false ) );
		$discount_rules_add_photo( $print, $wedding_photo );
		$discount_rules_add_photo( $print, $excluded_photo );
		$t::add( $t::product( 10, array( 'taxable' => false ) ) );
		list( , $code ) = $discount_rules_make( array( 'discount_amount' => '50', 'disallowed_galleries' => array( $excluded_id ) ) );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		// Half off everything not from the excluded gallery: 50% of ( 40 + 10 ).
		$t::money( 25, $cart->get_discount(), 'discount' );
		$t::money( 65, $cart->get_total(), '90 - 25' );
	}
);

/*
 * Start and end dates. Both days count: a code that ends today still works today.
 */

$t::test(
	'Discount rules: dates, before the start or after the end is refused, and both days count',
	function () use ( $t, $discount_rules_make, $discount_rules_enter, $discount_rules_day ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		list( , $not_started ) = $discount_rules_make( array( 'start_date' => $discount_rules_day( 1 ) ) );
		list( , $ended )       = $discount_rules_make( array( 'end_date' => $discount_rules_day( -1 ) ) );
		list( , $today )       = $discount_rules_make( array( 'start_date' => $discount_rules_day( 0 ), 'end_date' => $discount_rules_day( 0 ) ) );
		$t::same( false, $discount_rules_enter( $not_started ), 'starts tomorrow: refused' );
		$t::same( false, $discount_rules_enter( $ended ), 'ended yesterday: refused' );
		$t::same( true, $discount_rules_enter( $today ), 'starts and ends today: accepted' );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 90, $cart->get_total(), 'total' );
	}
);

/*
 * Codes already in the cart are checked again on every page load.
 */

$t::test(
	'Discount rules: a code that ends while it is in the cart is taken off',
	function () use ( $t, $discount_rules_make, $discount_rules_enter, $discount_rules_codes, $discount_rules_has_error, $discount_rules_day ) {
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		list( $id, $code ) = $discount_rules_make( array( 'end_date' => $discount_rules_day( 1 ) ) );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		$t::money( 99, $cart->get_total(), 'total with the code: 100 - 10 + 9' );
		// The end date passes while the customer is still shopping.
		update_post_meta( $id, 'end_date', $discount_rules_day( -1 ) );
		$cart = $t::new_request();
		$t::money( 0, $cart->get_discount(), 'discount' );
		$t::money( 10, $cart->get_tax(), 'tax on the full 100' );
		$t::money( 110, $cart->get_total(), 'total' );
		$t::same( false, in_array( $code, $discount_rules_codes(), true ), 'code taken off the cart' );
		$t::same( true, $discount_rules_has_error( $code ), 'customer is told the code was removed' );
	}
);

$t::test(
	'Discount rules: a code the photographer deletes is taken off the cart',
	function () use ( $t, $discount_rules_make, $discount_rules_enter, $discount_rules_codes ) {
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		list( $id, $code ) = $discount_rules_make();
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		wp_trash_post( $id );
		$cart = $t::new_request();
		$t::money( 0, $cart->get_discount(), 'discount' );
		$t::money( 100, $cart->get_total(), 'total' );
		$t::same( false, in_array( $code, $discount_rules_codes(), true ), 'code taken off the cart' );
	}
);

/*
 * Amount off each product.
 */

// $5 off each print can make a $3 print free, but the extra $2 must not come off other things.
$t::test(
	'Discount rules: amount off each product never takes more than the product costs',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$t::tax( 10 );
		$small = $t::product( 3 );
		$t::add( $small, 2 );
		$t::add( $t::product( 20 ) );
		list( , $code ) = $discount_rules_make(
			array(
				'discount_type'    => 'amount-product',
				'discount_amount'  => '5',
				'allowed_products' => array( $small ),
			)
		);
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		// The two $3 prints become free: 2 x 3. Tax is then on the 20 left: 2.00.
		$t::money( 6, $cart->get_discount(), 'discount' );
		$t::money( 2, $cart->get_tax(), 'tax' );
		$t::money( 22, $cart->get_total(), '26 - 6 + 2' );
	}
);

/*
 * Prices that include tax, with shipping. The customer sees $110 for the product and $11 for
 * taxed shipping (both include 10% tax).
 */

// "Apply to Shipping": the percent comes off the shipping the customer pays, tax included,
// just like it does for the products.
$t::test(
	'Discount rules: prices include tax, percent off including taxed shipping',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::flat_shipping( 11, true );
		list( , $code ) = $discount_rules_make( array( 'apply_to_shipping' => '1' ) );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		// 10% of ( 110 + 11 ) = 12.10. The 108.90 left includes 108.90 - 108.90 / 1.1 = 9.90 tax.
		$t::money( 12.10, $cart->get_discount(), 'discount' );
		$t::money( 9.90, $cart->get_tax(), 'tax included' );
		$t::money( 108.90, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Discount rules: prices include tax, percent off including shipping that is not taxed',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::flat_shipping( 10, false );
		list( , $code ) = $discount_rules_make( array( 'apply_to_shipping' => '1' ) );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		// 10% of ( 110 + 10 ) = 12. The product keeps 99, which holds 9 tax.
		$t::money( 12, $cart->get_discount(), 'discount' );
		$t::money( 9, $cart->get_tax(), 'tax included' );
		$t::money( 108, $cart->get_total(), 'total' );
	}
);

// A fixed amount off the order is taken off the products, not the shipping.
$t::test(
	'Discount rules: prices include tax, fixed amount off with taxed shipping',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::flat_shipping( 11, true );
		list( , $code ) = $discount_rules_make( array( 'discount_type' => 'amount-total', 'discount_amount' => '10' ) );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		// The product drops to 100, holding 100 - 90.91 = 9.09 tax, plus the shipping's 1.00.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 10.09, $cart->get_tax(), 'tax included' );
		$t::money( 111, $cart->get_total(), '110 + 11 - 10' );
	}
);

/*
 * "Apply discounts after taxes applied": tax is worked out on the full price, then the
 * discount comes off the total.
 */

$t::test(
	'Discount rules: prices include tax and discount after tax, percent off including taxed shipping',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 110 ) );
		$t::flat_shipping( 11, true );
		list( , $code ) = $discount_rules_make( array( 'apply_to_shipping' => '1' ) );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		// 10% of ( 110 + 11 ) = 12.10. Tax stays what the full prices hold: 10 + 1.
		$t::money( 12.10, $cart->get_discount(), 'discount' );
		$t::money( 11, $cart->get_tax(), 'tax' );
		$t::money( 108.90, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Discount rules: prices include tax and discount after tax, fixed amount bigger than the products',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 110 ) );
		$t::flat_shipping( 11, true );
		list( , $code ) = $discount_rules_make( array( 'discount_type' => 'amount-total', 'discount_amount' => '150' ) );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		// $150 off, but the product only costs 110. The shipping is still paid.
		$t::money( 110, $cart->get_discount(), 'discount capped at the product' );
		$t::money( 11, $cart->get_total(), 'shipping left to pay' );
	}
);

$t::test(
	'Discount rules: discount after tax, percent off including taxed shipping and its tax',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		list( , $code ) = $discount_rules_make( array( 'apply_to_shipping' => '1' ) );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		// Tax on the full prices: 10 + 1. Then 10% of everything: ( 100 + 10 + 10 + 1 ) x 10%.
		$t::money( 11, $cart->get_tax(), 'tax' );
		$t::money( 12.10, $cart->get_discount(), 'discount' );
		$t::money( 108.90, $cart->get_total(), '121 - 12.10' );
	}
);

/*
 * To the cent.
 */

// The customer pays 8.875% tax on what the print costs after the discount, rounded once.
$t::test(
	'Discount rules: to the cent, tax on a discounted print is worked out on the discounted price',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$t::tax( 8.875 );
		$t::add( $t::product( 12.99 ) );
		list( , $code ) = $discount_rules_make();
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		// 10% of 12.99 = 1.299, so 1.30. Tax on 11.69 x 8.875% = 1.0375, so 1.04.
		$t::money( 1.30, $cart->get_discount(), 'discount' );
		$t::money( 1.04, $cart->get_tax(), 'tax' );
		$t::money( 12.73, $cart->get_total(), '11.69 + 1.04' );
	}
);

$t::test(
	'Discount rules: to the cent, a large quantity with an uneven percent',
	function () use ( $t, $discount_rules_make, $discount_rules_enter ) {
		$t::tax( 7.25 );
		$t::add( $t::product( 0.79 ), 250 );
		list( , $code ) = $discount_rules_make( array( 'discount_amount' => '12.5' ) );
		$t::calculate();
		$t::same( true, $discount_rules_enter( $code ), 'code accepted' );
		$cart = $t::calculate();
		// 250 x 0.79 = 197.50. 12.5% of that = 24.6875, so 24.69. Tax on 172.81 x 7.25% = 12.5287, so 12.53.
		$t::money( 24.69, $cart->get_discount(), 'discount' );
		$t::money( 12.53, $cart->get_tax(), 'tax' );
		$t::money( 185.34, $cart->get_total(), '172.81 + 12.53' );
	}
);

remove_filter( 'pre_http_request', $discount_rules_offline, PHP_INT_MAX );
remove_filter( 'pre_wp_mail', $discount_rules_no_mail, PHP_INT_MAX );
remove_action( 'wp_insert_post', $discount_rules_remember, 10 );
foreach ( array_unique( $discount_rules_made_emails ) as $discount_rules_email_id ) {
	wp_delete_post( $discount_rules_email_id, true );
}
if ( ! empty( $discount_rules_made_products ) ) {
	global $wpdb;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sunshine_actions' ) ) ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_actions WHERE action_type = 'add_to_cart' AND action_object_id IN (" . implode( ',', array_map( 'intval', array_unique( $discount_rules_made_products ) ) ) . ')' );
	}
}
if ( ! empty( $discount_rules_blocked ) ) {
	WP_CLI::log( 'core-discount-rules blocked outgoing requests to: ' . implode( ', ', array_unique( $discount_rules_blocked ) ) );
}
