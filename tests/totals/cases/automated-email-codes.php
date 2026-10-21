<?php
/**
 * Discount codes made by the Automated Emails add-on.
 *
 * An automated email can carry a discount. When the email is sent, the add-on copies the chosen
 * discount into a brand new code just for that customer: same discount, used once, and (when the
 * email sets one) ending a set time after the email went out. The edit screen says: "A unique
 * coupon code will be generated based on the settings of the selected coupon and assigned to the
 * specific user being emailed", and for the expiration: "How long should the coupon code be valid
 * for after being created? This will override any expiration date settings of the selected
 * discount code".
 *
 * Emails are sent the way the hourly email task sends them (sunshine_email_process_queue), but
 * only the emails these tests queued: other tests share this site. No email leaves the site.
 */

$t = 'SPC_Totals_Test';

// Nothing here may reach an outside service or send an email. Every outgoing request and email is
// stopped, and the emails are kept so the tests can read them.
$aec_blocked = array();
$aec_offline = function ( $pre, $args, $url ) use ( &$aec_blocked ) {
	$aec_blocked[] = $url;
	return new WP_Error( 'spc_totals_offline', 'The totals tests never reach outside services' );
};
$aec_mails   = array();
$aec_keep    = function ( $args ) use ( &$aec_mails ) {
	$aec_mails[] = $args;
	return $args;
};
$aec_no      = function () {
	return false;
};
add_filter( 'pre_http_request', $aec_offline, PHP_INT_MAX, 3 );
add_filter( 'wp_mail', $aec_keep, PHP_INT_MAX );
add_filter( 'pre_wp_mail', $aec_no, PHP_INT_MAX );
add_filter( 'sunshine_checkout_allow_order_notify', $aec_no, PHP_INT_MAX );

// Remember what this file makes without a helper: products (the Analytics add-on logs a row for
// each add to cart), queued emails (the site's own automated emails queue one for every order and
// cart here), and the codes the emails make. All are deleted at the end of this file.
$aec_made_products  = array();
$aec_made_logs      = array();
$aec_made_discounts = array();
$aec_remember       = function ( $post_id, $post ) use ( &$aec_made_products, &$aec_made_logs, &$aec_made_discounts ) {
	if ( 'sunshine-product' === $post->post_type ) {
		$aec_made_products[] = (int) $post_id;
	} elseif ( 'sunshine-email-log' === $post->post_type ) {
		$aec_made_logs[] = (int) $post_id;
	} elseif ( 'sunshine-discount' === $post->post_type ) {
		$aec_made_discounts[] = (int) $post_id;
	}
};
add_action( 'wp_insert_post', $aec_remember, 10, 2 );

/**
 * A discount made the way the Discounts screen saves one. That screen saves every field: empty
 * ones as '', product lists as arrays of post IDs and dates as YYYY-MM-DD. $meta replaces any of
 * these. Returns the discount's post ID and its code. The code is not put in the cart.
 */
$aec_discount = function ( $meta = array(), $name = '' ) use ( $t ) {
	$t::require_plugin( 'sunshine_get_discount_by_code', 'Discount Codes add-on' );
	$code = 'SPCAEC' . wp_rand( 100000, 999999 );
	$id   = wp_insert_post(
		array(
			'post_type'   => 'sunshine-discount',
			'post_status' => 'publish',
			'post_title'  => $name ? $name : 'Thank you ' . $code,
		)
	);
	$t::track( 'post', $id );
	$meta = array_merge(
		array(
			'auto'                  => '',
			'code'                  => $code,
			'discount_type'         => 'percent-total',
			'discount_amount'       => '15',
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

// A published gallery with one photo, saved the way the gallery screen and uploader save them.
$aec_gallery = function () use ( $t ) {
	$gallery_id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-gallery',
			'post_status' => 'publish',
			'post_title'  => 'Automated email codes test gallery',
		)
	);
	$t::track( 'post', $gallery_id );
	update_post_meta( $gallery_id, 'status', 'default' );
	update_post_meta( $gallery_id, 'access_type', '' );
	$image_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'automated-email-codes-photo',
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

// Every email here is limited to this file's own gallery, so orders placed by other tests on the
// site never queue it.
list( $aec_gallery_id, $aec_photo_id ) = $aec_gallery();

/**
 * An automated email with a discount, saved the way its edit screen saves it: the trigger, the
 * gallery list as post IDs, an empty price level list, "Time" and "Discount Expiration" as
 * duration fields ( time + interval ), the recipients as a list, and the chosen discount's ID as
 * text. A "Discount Expiration" left blank is saved as time '' with the first interval in the
 * list, 'month'. "Time" is 0 hours, so the email goes out on the next run of the email task.
 */
$aec_email = function ( $discount_id, $discount_time = array( 'time' => '', 'interval' => 'month' ), $meta = array() ) use ( $t, $aec_gallery_id ) {
	$t::require_plugin( 'sunshine_email_create_discount', 'Automated Emails add-on' );
	$t::require_plugin( 'sunshine_get_discount_by_code', 'Discount Codes add-on' );
	$id = wp_insert_post(
		array(
			'post_type'    => 'sunshine-email',
			'post_status'  => 'publish',
			'post_title'   => 'A thank you from your photographer',
			'post_content' => '<p>Thanks for your order. Use code [discount_code] on your next one.</p>',
		)
	);
	$t::track( 'post', $id );
	$meta = array_merge(
		array(
			'trigger'       => 'order',
			'galleries'     => array( (int) $aec_gallery_id ),
			'price_levels'  => '',
			'time'          => array(
				'time'     => '0',
				'interval' => 'hour',
			),
			'recipients'    => array( 'all' ),
			'transactional' => '',
			'discount'      => (string) $discount_id,
			'discount_time' => $discount_time,
		),
		$meta
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
};

/**
 * Send queued emails the way the hourly email task does, but only the ones listed: other tests
 * on this site may have their own emails waiting. Returns what each email was sent with.
 */
$aec_run = function ( $log_ids ) use ( $t ) {
	global $wp_filter;
	// The unsubscribe link needs a secret. Give it one in memory so nothing is saved.
	$t::option( 'email_unsubscribe_secret', 'spc-totals-test-secret' );
	// Each run of the email task hooks up a new sender. Put the hooks back afterwards, so the
	// next run does not send every email twice.
	$hooks = array( 'sunshine_automated_email_send', 'sunshine_options_email_automated-email' );
	$saved = array();
	foreach ( $hooks as $hook ) {
		$saved[ $hook ] = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
	}
	$sent  = array();
	$only  = function ( $query ) use ( $log_ids ) {
		if ( 'sunshine-email-log' === $query->get( 'post_type' ) && 'queue' === $query->get( 'meta_value' ) ) {
			$query->set( 'post__in', array_map( 'intval', $log_ids ) );
		}
	};
	$catch = function ( $data ) use ( &$sent ) {
		$sent[] = $data;
	};
	add_action( 'pre_get_posts', $only );
	add_action( 'sunshine_automated_email_send', $catch, 1 );
	try {
		sunshine_email_process_queue();
	} finally {
		remove_action( 'pre_get_posts', $only );
		foreach ( $saved as $hook => $before ) {
			if ( null === $before ) {
				unset( $wp_filter[ $hook ] );
			} else {
				$wp_filter[ $hook ] = $before;
			}
		}
	}
	return $sent;
};

/**
 * Queue the email for one customer, the way the "After user registers" trigger does, and send
 * it. Returns the code the email carried, the new discount's post ID, the queued email's ID and
 * the email itself.
 */
$aec_send = function ( $email_id, $user_id = 0 ) use ( $aec_run, &$aec_mails ) {
	$address = 'aec' . wp_rand( 100000, 999999 ) . '@example.com';
	$log_id  = sunshine_email_new_queue( $address, $email_id, $user_id ? array( 'user_id' => $user_id ) : array() );
	if ( empty( $log_id ) ) {
		throw new Exception( 'The email was not queued' );
	}
	$sent = $aec_run( array( $log_id ) );
	if ( 1 !== count( $sent ) ) {
		throw new Exception( 'Expected one email to go out, ' . count( $sent ) . ' did' );
	}
	$code     = (string) $sent[0]['discount_code'];
	$discount = $code ? sunshine_get_discount_by_code( $code ) : false;
	$mail     = array();
	foreach ( $aec_mails as $one ) {
		if ( in_array( $address, (array) $one['to'], true ) ) {
			$mail = $one;
		}
	}
	return array(
		'code'    => $code,
		'id'      => $discount ? (int) $discount->get_id() : 0,
		'log'     => (int) $log_id,
		'message' => isset( $mail['message'] ) ? (string) $mail['message'] : '',
	);
};

// What the checkout's Apply button does. True when the code was accepted.
$aec_enter = function ( $code ) {
	$result = SPC()->cart->add_discount( $code );
	SPC()->cart->update();
	return ! empty( $result );
};

$aec_codes = function () {
	return array_values( array_filter( (array) SPC()->session->get( 'discounts' ) ) );
};

// A new visit by the same shopper: no codes entered yet, same cart.
$aec_fresh_codes = function () use ( $t ) {
	SPC()->session->set( 'discounts', '' );
	return $t::new_request();
};

$aec_log_in = function ( $customer_id ) {
	wp_set_current_user( $customer_id );
	SPC()->customer = new SPC_Customer( $customer_id );
	SPC()->session->set( 'discounts', '' );
	SPC()->cart = new SPC_Cart();
};

// Finish an order the way a completed payment does. This is the step that counts discount uses.
$aec_finish = function ( $order ) {
	SPC()->cart->post_process_order( $order );
	return sunshine_get_order( $order->get_id() );
};

$aec_uses = function ( $discount_id ) {
	return (int) get_post_meta( $discount_id, 'use_count', true );
};

$aec_day = function ( $days_from_today ) {
	return wp_date( 'Y-m-d', time() + $days_from_today * DAY_IN_SECONDS );
};

// What the cart shows right before the customer places the order.
$aec_snapshot = function () {
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

$aec_matches = function ( $order, $seen ) use ( $t ) {
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

// Add a photo to the cart the way the gallery's add to cart button does.
$aec_add_photo = function ( $product_id, $image_id, $qty = 1 ) {
	$image = sunshine_get_image( $image_id );
	$item  = SPC()->cart->add_item( $product_id, $image_id, $image->get_gallery_id(), $image->get_price_level(), '', $qty );
	if ( empty( $item ) ) {
		throw new Exception( 'Could not add photo ' . $image_id . ' to the cart' );
	}
	return $item;
};

/*
 * The new code itself.
 */

$t::test(
	'Emailed code: a new code with the same discount as the one chosen on the email',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter ) {
		$customer_id                     = $t::customer();
		list( $parent_id, $parent_code ) = $aec_discount( array(), 'Fifteen percent thank you' );
		$made                            = $aec_send( $aec_email( $parent_id ), $customer_id );
		$t::same( true, '' !== $made['code'] && $made['id'] > 0, 'a code was made' );
		if ( ! $made['id'] ) {
			return;
		}
		$t::same( false, $parent_code === $made['code'], 'the code is not the chosen discount\'s code' );
		$t::same( 'Fifteen percent thank you', get_the_title( $made['id'] ), 'same name, so the cart and receipt show it' );
		$t::same( $parent_id, (int) wp_get_post_parent_id( $made['id'] ), 'kept under the chosen discount' );
		$t::same( (string) $parent_id, (string) get_post_meta( $made['id'], 'original', true ), 'remembers the chosen discount' );
		$t::same( 'percent-total', get_post_meta( $made['id'], 'discount_type', true ), 'discount type' );
		$t::same( '15', (string) get_post_meta( $made['id'], 'discount_amount', true ), 'discount amount' );
		$t::same( '1', (string) get_post_meta( $made['id'], 'max_uses', true ), 'one use in total' );
		$t::same( $made['code'], get_post_meta( $made['log'], 'discount_code', true ), 'code saved on the email log' );
		$t::same( true, false !== strpos( $made['message'], $made['code'] ), 'the code is in the email the customer gets' );
		$t::add( $t::product( 80, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $aec_enter( $made['code'] ), 'the customer\'s code is accepted' );
		$cart = $t::calculate();
		// 15% of 80.
		$t::money( 12, $cart->get_discount(), 'discount' );
		$t::money( 68, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Emailed code: every email gets its own code, and each one works',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter, $aec_log_in, $aec_fresh_codes ) {
		list( $parent_id ) = $aec_discount();
		$email_id          = $aec_email( $parent_id );
		$first             = $t::customer();
		$second            = $t::customer();
		$one               = $aec_send( $email_id, $first );
		$two               = $aec_send( $email_id, $second );
		$t::same( true, $one['id'] > 0 && $two['id'] > 0, 'both emails made a code' );
		$t::same( false, $one['code'] === $two['code'], 'the two codes are different' );
		$t::same( false, $one['id'] === $two['id'], 'two separate discounts' );
		// A logged in customer's cart is their own, so each one fills theirs.
		$product = $t::product( 80, array( 'taxable' => false ) );
		$aec_log_in( $second );
		$t::add( $product );
		$t::calculate();
		$t::same( true, $aec_enter( $two['code'] ), 'second customer\'s code accepted' );
		$t::money( 12, $t::calculate()->get_discount(), 'second customer: 15% of 80' );
		$aec_log_in( $first );
		$t::add( $product );
		$aec_fresh_codes();
		$t::same( true, $aec_enter( $one['code'] ), 'first customer\'s code accepted' );
		$cart = $t::calculate();
		$t::money( 12, $cart->get_discount(), 'first customer: 15% of 80' );
		$t::money( 68, $cart->get_total(), 'total' );
	}
);

// The hourly email task sends everything that is due in one go. Each customer must get the code
// made for them, never the one made for the customer before.
$t::test(
	'Emailed code: two emails sent in the same run each carry their own code',
	function () use ( $t, $aec_discount, $aec_email, $aec_run, &$aec_mails ) {
		list( $parent_id ) = $aec_discount();
		$email_id          = $aec_email( $parent_id );
		$addresses         = array( 'aecfirst' . wp_rand( 100000, 999999 ) . '@example.com', 'aecsecond' . wp_rand( 100000, 999999 ) . '@example.com' );
		$logs              = array();
		foreach ( $addresses as $address ) {
			$logs[] = sunshine_email_new_queue( $address, $email_id, array( 'user_id' => $t::customer() ) );
		}
		$sent = $aec_run( $logs );
		$t::same( 2, count( $sent ), 'two emails went out' );
		if ( 2 !== count( $sent ) ) {
			return;
		}
		$t::same( false, $sent[0]['discount_code'] === $sent[1]['discount_code'], 'two different codes' );
		foreach ( $sent as $one ) {
			$message = '';
			foreach ( $aec_mails as $mail ) {
				if ( in_array( $one['email_address'], (array) $mail['to'], true ) ) {
					$message = (string) $mail['message'];
				}
			}
			$other = ( $one === $sent[0] ) ? $sent[1]['discount_code'] : $sent[0]['discount_code'];
			$t::same( true, false !== strpos( $message, $one['discount_code'] ), 'the email has its own code' );
			$t::same( false, false !== strpos( $message, $other ), 'the email does not have the other customer\'s code' );
			$t::same( $one['discount_code'], get_post_meta( $one['email'], 'discount_code', true ), 'its own code on its email log' );
		}
	}
);

// The chosen discount allows 100 uses, but the code made for one customer is used once.
$t::test(
	'Emailed code: works for one order, then is refused for everyone',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter, $aec_finish, $aec_uses, $aec_fresh_codes ) {
		list( $parent_id, $parent_code ) = $aec_discount( array( 'max_uses' => '100' ) );
		$customer_id                     = $t::customer();
		$made                            = $aec_send( $aec_email( $parent_id ), $customer_id );
		$product                         = $t::product( 80, array( 'taxable' => false ) );
		$t::add( $product );
		$t::calculate();
		$t::same( true, $aec_enter( $made['code'] ), 'code accepted' );
		$order = $t::place_order();
		// 80 - 15% of 80.
		$t::money( 12, $order->get_discount(), 'discount on the order' );
		$t::money( 68, $order->get_total(), 'order total' );
		$aec_finish( $order );
		$t::same( 1, $aec_uses( $made['id'] ), 'use counted on the emailed code' );
		$t::same( 0, $aec_uses( $parent_id ), 'the chosen discount is not counted' );
		$aec_fresh_codes();
		$t::same( false, $aec_enter( $made['code'] ), 'the same customer again: refused' );
		$t::customer();
		$t::add( $product );
		$aec_fresh_codes();
		$t::same( false, $aec_enter( $made['code'] ), 'another customer: refused' );
		$t::money( 0, SPC()->cart->get_discount(), 'no discount' );
		$t::same( true, $aec_enter( $parent_code ), 'the chosen discount still works for others' );
	}
);

// Automated emails also go to guests who gave their email address, and their code works the same.
$t::test(
	'Emailed code: a guest\'s code works at checkout, placed as an order',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter ) {
		list( $parent_id ) = $aec_discount();
		$made              = $aec_send( $aec_email( $parent_id ) );
		$t::same( true, $made['id'] > 0, 'a code was made' );
		$t::add( $t::product( 80, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $aec_enter( $made['code'] ), 'code accepted' );
		$order = $t::place_order();
		// 15% of 80.
		$t::money( 12, $order->get_discount(), 'discount on the order' );
		$t::money( 68, $order->get_total(), 'order total' );
	}
);

/*
 * When the code ends. "Discount Expiration" on the email: "How long should the coupon code be
 * valid for after being created? This will override any expiration date settings of the
 * selected discount code".
 */

$t::test(
	'Emailed code: ends the number of days set on the email',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_day ) {
		list( $parent_id ) = $aec_discount();
		$email_id          = $aec_email(
			$parent_id,
			array(
				'time'     => '7',
				'interval' => 'day',
			)
		);
		$made              = $aec_send( $email_id, $t::customer() );
		// Sent today, good for 7 days: the last day it works is 7 days from today.
		$t::same( $aec_day( 7 ), (string) get_post_meta( $made['id'], 'end_date', true ), 'end date of the new code' );
	}
);

// Photographers reuse last season's code as the model for a thank you email. The email's own
// expiration replaces that code's old end date, so the customer's new code works.
$t::test(
	'Emailed code: the email\'s expiration replaces an old end date on the chosen discount',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter, $aec_day ) {
		list( $parent_id ) = $aec_discount( array( 'end_date' => $aec_day( -10 ) ) );
		$email_id          = $aec_email(
			$parent_id,
			array(
				'time'     => '14',
				'interval' => 'day',
			)
		);
		$made              = $aec_send( $email_id, $t::customer() );
		$t::same( $aec_day( 14 ), (string) get_post_meta( $made['id'], 'end_date', true ), 'end date of the new code' );
		$t::add( $t::product( 80, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $aec_enter( $made['code'] ), 'the code from today\'s email is accepted' );
		$cart = $t::calculate();
		// 15% of 80.
		$t::money( 12, $cart->get_discount(), 'discount' );
		$t::money( 68, $cart->get_total(), 'total' );
	}
);

// A blank "Discount Expiration" is saved as time '' and interval 'month'. That must not give the
// code an end date: it keeps whatever the chosen discount has.
$t::test(
	'Emailed code: no expiration on the email keeps the chosen discount\'s end date',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter, $aec_day ) {
		$customer_id      = $t::customer();
		list( $open_id )  = $aec_discount();
		list( $dated_id ) = $aec_discount( array( 'end_date' => $aec_day( 20 ) ) );
		$open             = $aec_send( $aec_email( $open_id ), $customer_id );
		$dated            = $aec_send( $aec_email( $dated_id ), $customer_id );
		$t::same( '', (string) get_post_meta( $open['id'], 'end_date', true ), 'no end date when the chosen discount has none' );
		$t::same( $aec_day( 20 ), (string) get_post_meta( $dated['id'], 'end_date', true ), 'the chosen discount\'s end date is kept' );
		$t::add( $t::product( 80, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $aec_enter( $open['code'] ), 'code accepted' );
		$cart = $t::calculate();
		$t::money( 12, $cart->get_discount(), 'discount, 15% of 80' );
		$t::money( 68, $cart->get_total(), 'total' );
	}
);

/*
 * The new code starts fresh. The add-on skips the chosen discount's use count when it copies it,
 * so the code's own history must start empty too.
 */

$t::test(
	'Emailed code: starts with no uses, even when the chosen discount is used up',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter, $aec_finish, $aec_uses, $aec_fresh_codes ) {
		list( $parent_id, $parent_code ) = $aec_discount( array( 'max_uses' => '1' ) );
		$product                         = $t::product( 80, array( 'taxable' => false ) );
		$t::customer();
		$t::add( $product );
		$t::calculate();
		$t::same( true, $aec_enter( $parent_code ), 'the chosen discount is used once' );
		$aec_finish( $t::place_order() );
		$t::same( 1, $aec_uses( $parent_id ), 'the chosen discount is used up' );
		$customer_id = $t::customer();
		$made        = $aec_send( $aec_email( $parent_id ), $customer_id );
		$t::same( 0, $aec_uses( $made['id'] ), 'the new code has no uses' );
		$t::add( $product );
		$aec_fresh_codes();
		$t::same( true, $aec_enter( $made['code'] ), 'the new code is accepted' );
		$cart = $t::calculate();
		$t::money( 12, $cart->get_discount(), 'discount, 15% of 80' );
		$t::money( 68, $cart->get_total(), 'total' );
	}
);

// The chosen discount allows one use per person. A customer who used it once gets a thank you
// email with their own new code. That code has never been used, so it must work for them.
$t::test(
	'Emailed code: a customer who used the chosen discount before can still use their new code',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter, $aec_finish, $aec_fresh_codes ) {
		list( $parent_id, $parent_code ) = $aec_discount( array( 'max_uses_per_person' => '1' ) );
		$customer_id                     = $t::customer();
		$t::add( $t::product( 80, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $aec_enter( $parent_code ), 'the chosen discount is used once' );
		$aec_finish( $t::place_order() );
		$made = $aec_send( $aec_email( $parent_id ), $customer_id );
		$t::same( array(), get_post_meta( $made['id'], 'used_by' ), 'the new code has no one on its used list' );
		$aec_fresh_codes();
		$t::same( true, $aec_enter( $made['code'] ), 'the new code is accepted' );
		$cart = $t::calculate();
		$t::money( 12, $cart->get_discount(), 'discount, 15% of 80' );
		$t::money( 68, $cart->get_total(), 'total' );
	}
);

/*
 * The new code at checkout, for each kind of discount.
 */

$t::test(
	'Emailed code: amount off the order, never more than the order',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter ) {
		list( $parent_id ) = $aec_discount(
			array(
				'discount_type'   => 'amount-total',
				'discount_amount' => '20',
			)
		);
		$made              = $aec_send( $aec_email( $parent_id ), $t::customer() );
		$t::add( $t::product( 15, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $aec_enter( $made['code'] ), 'code accepted' );
		$cart = $t::calculate();
		// $20 off a $15 order: only 15 comes off.
		$t::money( 15, $cart->get_discount(), 'discount capped at the order' );
		$t::money( 0, $cart->get_total(), 'total' );
		$t::add( $t::product( 35, array( 'taxable' => false ) ) );
		$cart = $t::calculate();
		// 15 + 35 = 50, less 20.
		$t::money( 20, $cart->get_discount(), 'discount once the order is bigger' );
		$t::money( 30, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Emailed code: amount off each chosen product keeps the product list',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter ) {
		$t::tax( 10 );
		$chosen            = $t::product( 10 );
		$other             = $t::product( 10 );
		list( $parent_id ) = $aec_discount(
			array(
				'discount_type'    => 'amount-product',
				'discount_amount'  => '2',
				'allowed_products' => array( $chosen ),
			)
		);
		$made              = $aec_send( $aec_email( $parent_id ), $t::customer() );
		$t::same( array( $chosen ), get_post_meta( $made['id'], 'allowed_products', true ), 'product list on the new code' );
		$t::add( $chosen, 3 );
		$t::add( $other );
		$t::calculate();
		$t::same( true, $aec_enter( $made['code'] ), 'code accepted' );
		$cart = $t::calculate();
		// $2 off each of the 3 chosen prints. Tax on 40 - 6 = 34 is 3.40.
		$t::money( 6, $cart->get_discount(), 'discount' );
		$t::money( 3.40, $cart->get_tax(), 'tax' );
		$t::money( 37.40, $cart->get_total(), '34 + 3.40' );
	}
);

$t::test(
	'Emailed code: keeps the minimum order amount',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter ) {
		list( $parent_id ) = $aec_discount(
			array(
				'discount_amount' => '10',
				'min_amount'      => '50',
			)
		);
		$made              = $aec_send( $aec_email( $parent_id ), $t::customer() );
		$t::add( $t::product( 24.99, array( 'taxable' => false ) ), 2 );
		$t::calculate();
		// 2 x 24.99 = 49.98, one cent short.
		$t::same( false, $aec_enter( $made['code'] ), 'refused at 49.98' );
		$t::add( $t::product( 0.02, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $aec_enter( $made['code'] ), 'accepted at 50.00' );
		$cart = $t::calculate();
		$t::money( 5, $cart->get_discount(), '10% of 50' );
		$t::money( 45, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Emailed code: a "only this discount" choice makes a code that replaces other codes',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter ) {
		list( $parent_id ) = $aec_discount(
			array(
				'discount_amount' => '20',
				'solo'            => '1',
			)
		);
		list( , $other )   = $aec_discount(
			array(
				'discount_type'   => 'amount-total',
				'discount_amount' => '5',
			)
		);
		$made              = $aec_send( $aec_email( $parent_id ), $t::customer() );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $aec_enter( $other ), 'another code entered first' );
		$t::same( true, $aec_enter( $made['code'] ), 'emailed code accepted' );
		$cart = $t::calculate();
		// Only the 20%, not 20% plus $5.
		$t::money( 20, $cart->get_discount(), 'discount' );
		$t::money( 80, $cart->get_total(), 'total' );
	}
);

// A flat rate, plus a Free method set to "Via Discount" so it is only offered while a code with
// "Free Shipping" is in the cart. Saved the way the Shipping settings save them.
$t::test(
	'Emailed code: a discount with free shipping gives free shipping, placed as an order',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter, $aec_snapshot, $aec_matches ) {
		$t::option(
			'shipping_methods',
			array(
				'spcaecflat' => array(
					'id'     => 'flat_rate',
					'active' => true,
				),
				'spcaecfree' => array(
					'id'     => 'free',
					'active' => true,
				),
			)
		);
		$t::option( 'flat_rate_price_spcaecflat', '10' );
		$t::option( 'flat_rate_price_type_spcaecflat', 'cart' );
		$t::option( 'flat_rate_taxable_spcaecflat', '' );
		$t::option( 'free_discount_spcaecfree', '1' );
		SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
		list( $parent_id ) = $aec_discount(
			array(
				'discount_amount' => '10',
				'free_shipping'   => '1',
			)
		);
		$made              = $aec_send( $aec_email( $parent_id ), $t::customer() );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( false, array_key_exists( 'spcaecfree', (array) sunshine_get_allowed_shipping_methods() ), 'free shipping not offered before the code' );
		$t::same( true, $aec_enter( $made['code'] ), 'code accepted' );
		$t::same( true, array_key_exists( 'spcaecfree', (array) sunshine_get_allowed_shipping_methods() ), 'free shipping offered with the code' );
		SPC()->cart->set_checkout_data_item( 'shipping_method', 'spcaecfree' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 10% of 50, and no shipping.
		$t::money( 5, $cart->get_discount(), 'discount' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 45, $cart->get_total(), 'total' );
		$seen  = $aec_snapshot();
		$order = $t::place_order();
		$aec_matches( $order, $seen );
		$t::same( 'spcaecfree', $order->get_shipping_method(), 'free shipping saved on the order' );
	}
);

$t::test(
	'Emailed code: to the cent, an uneven percent on many prints',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter ) {
		$t::tax( 7.25 );
		list( $parent_id ) = $aec_discount( array( 'discount_amount' => '12.5' ) );
		$made              = $aec_send( $aec_email( $parent_id ), $t::customer() );
		$t::add( $t::product( 0.79 ), 250 );
		$t::calculate();
		$t::same( true, $aec_enter( $made['code'] ), 'code accepted' );
		$cart = $t::calculate();
		// 250 x 0.79 = 197.50. 12.5% of that = 24.6875, so 24.69. Tax on 172.81 x 7.25% = 12.5287, so 12.53.
		$t::money( 24.69, $cart->get_discount(), 'discount' );
		$t::money( 12.53, $cart->get_tax(), 'tax' );
		$t::money( 185.34, $cart->get_total(), '172.81 + 12.53' );
	}
);

/*
 * Placed orders with the new code.
 */

$t::test(
	'Emailed code: with tax and taxed shipping, placed as an order',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter, $aec_snapshot, $aec_matches, $aec_finish, $aec_uses ) {
		$t::tax( 10 );
		list( $parent_id ) = $aec_discount();
		$made              = $aec_send( $aec_email( $parent_id ), $t::customer() );
		$t::add( $t::product( 80 ) );
		$t::flat_shipping( 10, true );
		$t::calculate();
		$t::same( true, $aec_enter( $made['code'] ), 'code accepted' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 15% of 80 = 12. Tax on 68 = 6.80, plus 1.00 on the shipping. 68 + 10 + 7.80.
		$t::money( 12, $cart->get_discount(), 'discount' );
		$t::money( 7.80, $cart->get_tax(), 'tax' );
		$t::money( 85.80, $cart->get_total(), 'total' );
		$seen  = $aec_snapshot();
		$order = $t::place_order();
		$aec_matches( $order, $seen );
		$t::same( array( $made['code'] ), array_values( array_filter( (array) $order->get_discounts() ) ), 'the emailed code is saved on the order' );
		$aec_finish( $order );
		$t::same( 1, $aec_uses( $made['id'] ), 'use counted on the emailed code' );
	}
);

$t::test(
	'Emailed code: prices include tax, placed as an order',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter, $aec_snapshot, $aec_matches ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		list( $parent_id ) = $aec_discount();
		$made              = $aec_send( $aec_email( $parent_id ), $t::customer() );
		$t::add( $t::product( 110 ) );
		$t::flat_shipping( 11, true );
		$t::calculate();
		$t::same( true, $aec_enter( $made['code'] ), 'code accepted' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 15% of the 110 the customer sees = 16.50. The product's 93.50 holds 8.50 tax, the
		// shipping's 11 holds 1.00. 110 - 16.50 + 11.
		$t::money( 16.50, $cart->get_discount(), 'discount' );
		$t::money( 9.50, $cart->get_tax(), 'tax included' );
		$t::money( 104.50, $cart->get_total(), 'total' );
		$seen  = $aec_snapshot();
		$order = $t::place_order();
		$aec_matches( $order, $seen );
	}
);

$t::test(
	'Emailed code: discount after tax, placed as an order',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter, $aec_snapshot, $aec_matches ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		list( $parent_id ) = $aec_discount();
		$made              = $aec_send( $aec_email( $parent_id ), $t::customer() );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		$t::same( true, $aec_enter( $made['code'] ), 'code accepted' );
		$t::checkout_address();
		$cart = $t::calculate();
		// Tax on the full 100 = 10. Then 15% of 110 = 16.50 comes off.
		$t::money( 10, $cart->get_tax(), 'tax' );
		$t::money( 16.50, $cart->get_discount(), 'discount' );
		$t::money( 93.50, $cart->get_total(), '110 - 16.50' );
		$seen  = $aec_snapshot();
		$order = $t::place_order();
		$aec_matches( $order, $seen );
	}
);

$t::test(
	'Emailed code: with a gift card, placed as an order',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter, $aec_snapshot, $aec_matches ) {
		list( $parent_id ) = $aec_discount();
		$made              = $aec_send( $aec_email( $parent_id ), $t::customer() );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::gift_card( 50 );
		$t::calculate();
		$t::same( true, $aec_enter( $made['code'] ), 'code accepted' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 100 - 15 = 85. The card pays 50 of it.
		$t::money( 15, $cart->get_discount(), 'discount' );
		$t::money( 50, array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) ), 'taken from the card' );
		$t::money( 35, $cart->get_total(), 'left to pay' );
		$seen  = $aec_snapshot();
		$order = $t::place_order();
		$aec_matches( $order, $seen );
		$fees = (array) $order->get_fees();
		$t::money( -50, isset( $fees['gift_card'] ) ? $fees['gift_card']['amount'] : 0, 'gift card on the order' );
	}
);

$t::test(
	'Emailed code: with store credit, placed as an order',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter, $aec_snapshot, $aec_matches ) {
		list( $parent_id ) = $aec_discount();
		$customer_id       = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 20 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$made = $aec_send( $aec_email( $parent_id ), $customer_id );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$t::calculate();
		$t::same( true, $aec_enter( $made['code'] ), 'code accepted' );
		$t::checkout_address();
		$cart = $t::calculate();
		// 100 - 15 - 20 credit.
		$t::money( 15, $cart->get_discount(), 'discount' );
		$t::money( 20, $cart->get_credits_applied(), 'credit used' );
		$t::money( 65, $cart->get_total(), 'total' );
		$seen  = $aec_snapshot();
		$order = $t::place_order();
		$aec_matches( $order, $seen );
		$t::money( 20, $order->get_credits(), 'credit on the order' );
	}
);

// The main use: a customer orders from the gallery, the "After user submits order" email goes
// out with a code, and the code takes 20% off their next order.
$t::test(
	'Emailed code: the email sent after an order gives 20% off the next order',
	function () use ( $t, $aec_discount, $aec_email, $aec_run, $aec_enter, $aec_finish, $aec_add_photo, $aec_photo_id, $aec_fresh_codes ) {
		$t::option( 'proofing', false );
		list( $parent_id ) = $aec_discount( array( 'discount_amount' => '20' ) );
		$email_id          = $aec_email(
			$parent_id,
			array(
				'time'     => '30',
				'interval' => 'day',
			)
		);
		$t::customer();
		$print = $t::product( 50, array( 'taxable' => false ) );
		$aec_add_photo( $print, $aec_photo_id );
		$t::calculate();
		$first = $aec_finish( $t::place_order() );
		$t::money( 50, $first->get_total(), 'first order total' );
		// What the checkout runs once the order is paid.
		sunshine_email_queue_after_order( $first, array() );
		$logs = get_posts(
			array(
				'post_type'   => 'sunshine-email-log',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_query'  => array(
					array(
						'key'   => 'email',
						'value' => $email_id,
					),
					array(
						'key'   => 'order_id',
						'value' => $first->get_id(),
					),
				),
			)
		);
		$t::same( 1, count( $logs ), 'the thank you email is queued for the order' );
		if ( 1 !== count( $logs ) ) {
			return;
		}
		$sent = $aec_run( $logs );
		$code = isset( $sent[0]['discount_code'] ) ? (string) $sent[0]['discount_code'] : '';
		$t::same( true, '' !== $code, 'the email carried a code' );
		$aec_fresh_codes();
		$t::same( true, $aec_enter( $code ), 'the code is accepted on the next order' );
		$cart = $t::calculate();
		// 20% of 50.
		$t::money( 10, $cart->get_discount(), 'discount' );
		$t::money( 40, $cart->get_total(), 'total' );
		$order = $t::place_order();
		$t::money( 10, $order->get_discount(), 'discount on the order' );
		$t::money( 40, $order->get_total(), 'order total' );
	}
);

/*
 * Which discount the code is copied from.
 */

// Assumption: a code made for one emailed customer is never an automatic discount for everyone.
// The photographer's automatic discount still applies on its own; the copy must not add to it.
$t::test(
	'Emailed code: copied from an automatic discount, it is not applied to other shoppers',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_codes ) {
		$product = $t::product( 100, array( 'taxable' => false ) );
		// Automatic discounts here need this test's own product, so they never land in another
		// test's cart, and they are deleted as soon as the test ends.
		list( $parent_id ) = $aec_discount(
			array(
				'auto'              => '1',
				'code'              => wp_generate_password( 8, false ),
				'discount_amount'   => '10',
				'required_products' => array( $product ),
			)
		);
		$made              = array( 'id' => 0 );
		try {
			$made = $aec_send( $aec_email( $parent_id ), $t::customer() );
			$t::same( '', (string) get_post_meta( $made['id'], 'auto', true ), 'the new code is not automatic' );
			// Someone who never got the email.
			$t::customer();
			$t::add( $product );
			$t::calculate();
			$cart = $t::new_request();
			$t::same( false, in_array( $made['code'], $aec_codes(), true ), 'the emailed code is not in their cart' );
			// Only the photographer's automatic 10% of 100.
			$t::money( 10, $cart->get_discount(), 'discount' );
			$t::money( 90, $cart->get_total(), 'total' );
		} finally {
			wp_delete_post( $parent_id, true );
			if ( $made['id'] ) {
				wp_delete_post( $made['id'], true );
			}
		}
	}
);

// Assumption: once the photographer moves the discount to the trash, emails stop handing out new
// codes for it. The email's edit screen already shows no discount chosen at that point.
$t::test(
	'Emailed code: no working code is made from a discount in the trash',
	function () use ( $t, $aec_discount, $aec_email, $aec_send, $aec_enter ) {
		list( $parent_id ) = $aec_discount();
		$email_id          = $aec_email( $parent_id );
		wp_trash_post( $parent_id );
		$made = $aec_send( $email_id, $t::customer() );
		$t::same( '', $made['code'], 'no code in the email' );
		if ( '' !== $made['code'] ) {
			$t::add( $t::product( 80, array( 'taxable' => false ) ) );
			$t::calculate();
			$t::same( false, $aec_enter( $made['code'] ), 'the code from the trashed discount is refused' );
		}
	}
);

remove_filter( 'pre_http_request', $aec_offline, PHP_INT_MAX );
remove_filter( 'wp_mail', $aec_keep, PHP_INT_MAX );
remove_filter( 'pre_wp_mail', $aec_no, PHP_INT_MAX );
remove_filter( 'sunshine_checkout_allow_order_notify', $aec_no, PHP_INT_MAX );
remove_action( 'wp_insert_post', $aec_remember, 10 );
foreach ( array_unique( $aec_made_logs ) as $aec_log_id ) {
	// Emails queued for later also set a WordPress task to publish them on time.
	wp_clear_scheduled_hook( 'publish_future_post', array( $aec_log_id ) );
	wp_delete_post( $aec_log_id, true );
}
foreach ( array_unique( $aec_made_discounts ) as $aec_discount_id ) {
	wp_delete_post( $aec_discount_id, true );
}
if ( ! empty( $aec_made_products ) ) {
	global $wpdb;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sunshine_actions' ) ) ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_actions WHERE action_type = 'add_to_cart' AND action_object_id IN (" . implode( ',', array_map( 'intval', array_unique( $aec_made_products ) ) ) . ')' );
	}
}
if ( ! empty( $aec_blocked ) ) {
	WP_CLI::log( 'automated-email-codes blocked outgoing requests to: ' . implode( ', ', array_unique( $aec_blocked ) ) );
}
