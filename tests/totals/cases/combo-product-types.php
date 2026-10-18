<?php
/**
 * Combination tests for the cart's money math.
 *
 * Instead of working out every cart by hand, these build many carts from a fixed list of
 * ingredients and check rules that must hold for every cart a customer could make.
 *
 * Ingredients: prints (taxed and not), prints with product options, prints sold at another
 * price level, packages, albums (Multi-Image Products), Sell Anything products, videos,
 * single photo downloads, full gallery downloads, session fees, prints included in a session
 * the customer already paid for, prints with a quantity discount, gift cards bought as a
 * product and WHCC prints. Mixed with discount codes, gift cards, store credit, shipping,
 * payment fees, prices with or without tax, and discount after tax.
 *
 * Each cart comes from a seed number, and a seed always builds the same cart, so any failure
 * can be run again on its own. The seed and the ingredients are in every failure line.
 *
 *   Some seeds:  SPC_COMBO_FROM=1 SPC_COMBO_TO=50 SPC_TOTALS_ONLY=combo-product-types wp eval-file .../run.php
 *   One cart:    SPC_COMBO_RECIPE='{"lines":[["print",12.99,3]],"tax":20,"incl":true}' (same command)
 *   Add SPC_COMBO_DUMP=1 to print what the cart shows.
 *
 * Every shipping method lookup adds filters that are never removed (a known bug), so a long
 * run would get slower and slower. Each test here takes off the filters it added, so all 200
 * seeds run in about a minute; batches with SPC_COMBO_FROM / SPC_COMBO_TO also work.
 *
 * The small named tests at the end are the smallest carts that broke a rule. They run with the
 * batch that starts at seed 1.
 *
 * Nothing leaves the site: WHCC calls get answers from a made-up lab, every other outgoing
 * request is blocked, and email is stopped.
 */

$t = 'SPC_Totals_Test';

$combo_seed_count = 200;
$combo_env        = function ( $name ) {
	$value = getenv( $name );
	return ( false === $value || '' === $value ) ? null : $value;
};
$combo_from       = null === $combo_env( 'SPC_COMBO_FROM' ) ? 1 : max( 1, intval( $combo_env( 'SPC_COMBO_FROM' ) ) );
$combo_to         = null === $combo_env( 'SPC_COMBO_TO' ) ? $combo_seed_count : min( $combo_seed_count, intval( $combo_env( 'SPC_COMBO_TO' ) ) );

/*
 * The ingredient lists. A seed picks from each list with a fixed hash of the seed and the slot,
 * so every seed always builds the same cart, but the choices don't move in step with each other.
 */
$combo_kinds    = array( 'print', 'untaxed print', 'print with options', 'print at another price level', 'package', 'album', 'general product', 'video', 'download', 'gallery download', 'session fee', 'included print', 'quantity discount print', 'gift card purchase', 'whcc print' );
$combo_prices   = array( 12.99, 19.99, 0.99, 45, 8.5, 33.33, 150, 7.25, 1.01, 99.95, 4.44, 250, 23.1, 61 );
$combo_qtys     = array( 1, 2, 3, 1, 5, 12, 1, 7, 2, 25, 1, 300, 3, 4 );
$combo_rates    = array( 0, 7.25, 20, 10, 8.875, 6 );
$combo_codes    = array( 'none', 'percent off the order', 'amount off the order', 'percent off one product', 'amount off one product', 'percent off the order and shipping', 'two codes', 'percent off one of each product', 'amount bigger than the order', 'two percent codes over 100' );
$combo_cards    = array( 0, 0, 0, 20, 5000, 7.77 );
$combo_credits  = array( 0, 0, 0, 10, 3000 );
$combo_shipping = array( 'none', 'flat 8.95', 'flat 6 taxed', 'flat 2.50 a line taxed', 'flat 1.25 an item' );
$combo_fees     = array( 'none', 'none', 'fee 2.50', 'fee 3%' );

// Kinds that are always bought one at a time.
$combo_single = array( 'video', 'download', 'gallery download', 'session fee', 'gift card purchase' );

// Gift card amounts a customer can type in.
$combo_card_amounts = array( 25, 50, 37.5, 100 );

// A number from 0 to $count - 1 that is always the same for this seed and slot.
$combo_pick = function ( $seed, $slot, $count ) {
	return crc32( 'spc-combo|' . $seed . '|' . $slot ) % $count;
};

/**
 * The cart a seed builds. Each line is array( kind, price, quantity, variant ). The variant
 * (0 to 5) picks how that kind is set up, for example which frame is chosen or whether a
 * quantity discount is a percent or an amount. See $combo_variant_text.
 */
$combo_recipe = function ( $seed ) use ( $combo_pick, $combo_kinds, $combo_prices, $combo_qtys, $combo_rates, $combo_codes, $combo_cards, $combo_credits, $combo_shipping, $combo_fees ) {
	$line_count = 1 + $combo_pick( $seed, 'lines', 4 );
	$lines      = array();
	for ( $k = 0; $k < $line_count; $k++ ) {
		$lines[] = array(
			$combo_kinds[ $combo_pick( $seed, 'kind' . $k, count( $combo_kinds ) ) ],
			$combo_prices[ $combo_pick( $seed, 'price' . $k, count( $combo_prices ) ) ],
			$combo_qtys[ $combo_pick( $seed, 'qty' . $k, count( $combo_qtys ) ) ],
			$combo_pick( $seed, 'variant' . $k, 6 ),
		);
	}
	return array(
		'seed'      => $seed,
		'lines'     => $lines,
		'tax'       => $combo_rates[ $combo_pick( $seed, 'tax', count( $combo_rates ) ) ],
		'incl'      => 1 === $combo_pick( $seed, 'incl', 2 ),
		'after_tax' => 0 === $combo_pick( $seed, 'after', 4 ),
		'code'      => $combo_codes[ $combo_pick( $seed, 'code', count( $combo_codes ) ) ],
		'gift_card' => $combo_cards[ $combo_pick( $seed, 'card', count( $combo_cards ) ) ],
		'credits'   => $combo_credits[ $combo_pick( $seed, 'credits', count( $combo_credits ) ) ],
		'shipping'  => $combo_shipping[ $combo_pick( $seed, 'shipping', count( $combo_shipping ) ) ],
		'fee'       => $combo_fees[ $combo_pick( $seed, 'fee', count( $combo_fees ) ) ],
		'order'     => 0 === $combo_pick( $seed, 'order', 2 ),
		'paypal'    => 0 === $combo_pick( $seed, 'paypal', 3 ),
		'remove'    => 0 === $combo_pick( $seed, 'remove', 2 ),
	);
};

// Fill in anything a hand-written recipe left out. Things bought one at a time get a quantity
// of 1, and a gift card bought as a product costs one of the amounts a customer can choose.
$combo_recipe_defaults = function ( $recipe ) use ( $combo_single, $combo_card_amounts ) {
	$gallery_download = false;
	foreach ( (array) $recipe['lines'] as $index => $line ) {
		$line = array_values( $line ) + array( '', 0, 1, 0 );
		// One full gallery download per cart; a second one becomes a print.
		if ( 'gallery download' === $line[0] ) {
			if ( $gallery_download ) {
				$line[0] = 'print';
			}
			$gallery_download = true;
		}
		if ( in_array( $line[0], $combo_single, true ) ) {
			$line[2] = 1;
		}
		if ( 'gift card purchase' === $line[0] ) {
			$line[1] = $combo_card_amounts[ intval( $line[3] ) % count( $combo_card_amounts ) ];
		}
		$recipe['lines'][ $index ] = array( (string) $line[0], (float) $line[1], (int) $line[2], (int) $line[3] );
	}
	return array_merge(
		array(
			'seed'      => 0,
			'lines'     => array(),
			'tax'       => 0,
			'incl'      => false,
			'after_tax' => false,
			'code'      => 'none',
			'gift_card' => 0,
			'credits'   => 0,
			'shipping'  => 'none',
			'fee'       => 'none',
			'order'     => true,
			'paypal'    => true,
			'remove'    => false,
		),
		$recipe
	);
};

// How a line's variant sets it up, in words.
$combo_variant_text = function ( $line ) {
	list( $kind, $price, $qty, $variant ) = $line;
	switch ( $kind ) {
		case 'print with options':
			$frames = array( 'black frame +5', 'oak frame +12.50', 'no frame +0' );
			return $frames[ $variant % 3 ] . ( $variant < 3 ? ', rush +3.25' : '' );
		case 'package':
			return 0 === $variant % 2 ? '2 prints and a file' : '2 prints';
		case 'session fee':
		case 'whcc print':
			return 0 === $variant % 2 ? 'taxed' : 'not taxed';
		case 'included print':
			return ( 0 === $variant % 3 ? $qty + 2 : max( 1, $qty - 1 ) ) . ' included';
		case 'quantity discount print':
			return 0 === $variant % 2 ? '10% off 3 or more' : min( 1, floor( $price * 50 ) / 100 ) . ' off 2 or more';
	}
	return '';
};

// The recipe in words, for test names and failure lines.
$combo_describe = function ( $recipe ) use ( $combo_variant_text ) {
	$lines = array();
	foreach ( $recipe['lines'] as $line ) {
		$variant = $combo_variant_text( $line );
		$lines[] = $line[2] . ' x ' . $line[0] . ' ' . $line[1] . ( '' !== $variant ? ' (' . $variant . ')' : '' );
	}
	$parts   = array( implode( ', ', $lines ) );
	$parts[] = $recipe['tax'] ? 'tax ' . $recipe['tax'] . '% ' . ( $recipe['incl'] ? 'included in prices' : 'added to prices' ) : 'no tax' . ( $recipe['incl'] ? ' (prices include tax)' : '' );
	if ( $recipe['after_tax'] ) {
		$parts[] = 'discount after tax';
	}
	if ( 'none' !== $recipe['code'] ) {
		$parts[] = 'code: ' . $recipe['code'];
	}
	if ( $recipe['gift_card'] ) {
		$parts[] = 'gift card ' . $recipe['gift_card'];
	}
	if ( $recipe['credits'] ) {
		$parts[] = 'credits ' . $recipe['credits'];
	}
	if ( 'none' !== $recipe['shipping'] ) {
		$parts[] = 'shipping ' . $recipe['shipping'];
	}
	if ( 'none' !== $recipe['fee'] ) {
		$parts[] = $recipe['fee'];
	}
	if ( $recipe['order'] ) {
		$parts[] = 'order placed';
	}
	if ( $recipe['paypal'] ) {
		$parts[] = 'PayPal';
	}
	if ( $recipe['remove'] && count( $recipe['lines'] ) > 1 ) {
		$parts[] = 'last line removed';
	}
	return implode( ' | ', $parts );
};

/*
 * A made-up WHCC lab. It answers the two calls adding a WHCC item to the cart makes: the
 * editor's price ("export") and what the customer built (GET editors/{id}).
 */
$combo_whcc = array(
	'run'     => 'spccombo' . wp_rand( 100000, 999999 ),
	'count'   => 0,
	'editors' => array(),
	'catalog' => array(),
);

$combo_whcc_reply = function ( $method, $endpoint, $body ) use ( &$combo_whcc ) {
	if ( 'PUT' === $method && 'oas/editors/export' === $endpoint ) {
		$items  = array();
		$orders = array();
		foreach ( (array) ( isset( $body['editors'] ) ? $body['editors'] : array() ) as $row ) {
			$editor_id = isset( $row['editorId'] ) ? $row['editorId'] : '';
			if ( empty( $combo_whcc['editors'][ $editor_id ] ) ) {
				continue;
			}
			$editor   = $combo_whcc['editors'][ $editor_id ];
			$qty      = isset( $row['quantity'] ) ? intval( $row['quantity'] ) : $editor['qty'];
			$items[]  = array(
				'editor'  => array(
					'id'                => $editor_id,
					'selectionsSummary' => $editor['summary'],
				),
				'pricing' => array(
					'markedUpPrice' => round( $editor['unit'] * $qty, 2 ),
					'quantity'      => $qty,
					'unitBasePrice' => $editor['base'],
				),
			);
			$orders[] = array(
				'EditorId'   => $editor_id,
				'ProductUID' => 1000,
				'Quantity'   => $qty,
			);
		}
		$reply = array( 'items' => $items );
		// Every WHCC item here is a photo print, and WHCC ships prints together in one package.
		if ( empty( $body['priceOnly'] ) ) {
			$reply['order'] = array(
				'Orders' => $orders ? array(
					array(
						'SequenceNumber' => 1,
						'OrderItems'     => $orders,
					),
				) : array(),
			);
		}
		return array( 200, $reply );
	}
	if ( 'GET' === $method && preg_match( '#^editors/([^/?]+)$#', $endpoint, $match ) && ! empty( $combo_whcc['editors'][ $match[1] ] ) ) {
		$editor = $combo_whcc['editors'][ $match[1] ];
		return array(
			200,
			array(
				'editorId'          => $match[1],
				'productId'         => $editor['whcc_id'],
				'selections'        => array(
					'quantity' => $editor['qty'],
					'photo'    => array( 'galleryId' => (string) $editor['photo'] ),
				),
				'selectionsSummary' => $editor['summary'],
				'productPreviews'   => array(),
			),
		);
	}
	return null;
};

// Outgoing requests a test tried to make. WHCC calls go to the lab, everything else is stopped.
$combo_blocked = array();
$combo_http    = function ( $preempt, $args, $url ) use ( &$combo_blocked, $combo_whcc_reply ) {
	$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
	$marker = 'dragdrop.design/api/v1/';
	$pos    = strpos( $url, $marker );
	if ( false !== $pos ) {
		$body  = ( isset( $args['body'] ) && is_string( $args['body'] ) ) ? json_decode( $args['body'], true ) : array();
		$reply = $combo_whcc_reply( $method, substr( $url, $pos + strlen( $marker ) ), is_array( $body ) ? $body : array() );
		if ( null !== $reply ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $reply[1] ),
				'response' => array(
					'code'    => $reply[0],
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
	}
	$combo_blocked[] = $method . ' ' . $url;
	return new WP_Error( 'spc_totals_offline', 'Outgoing requests are blocked in the totals tests' );
};

$combo_whcc_catalog = function () use ( &$combo_whcc ) {
	return array_values( $combo_whcc['catalog'] );
};

// The object that adds a WHCC item when the customer comes back from WHCC's editor.
$combo_whcc_adder = function () {
	global $wp_filter;
	if ( empty( $wp_filter['sunshine_add_to_cart_item'] ) || ! class_exists( 'SPC_WHCC_Add_to_Cart' ) ) {
		return null;
	}
	foreach ( $wp_filter['sunshine_add_to_cart_item']->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof SPC_WHCC_Add_to_Cart ) {
				return $callback['function'][0];
			}
		}
	}
	return null;
};

// The WHCC add-on remembers some things for the rest of a page load. Each add to cart is its
// own page load in real life, so forget them.
$combo_whcc_forget = function () use ( $combo_whcc_adder ) {
	$adder = $combo_whcc_adder();
	if ( $adder ) {
		foreach ( array( 'line_price', 'image_url', 'qty' ) as $property ) {
			$reflection = new ReflectionProperty( $adder, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $adder, null );
		}
	}
	if ( class_exists( 'SPC_WHCC_Shipping' ) && property_exists( 'SPC_WHCC_Shipping', 'product_category_cache' ) ) {
		$cache = new ReflectionProperty( 'SPC_WHCC_Shipping', 'product_category_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
	}
	SPC()->session->set( 'whcc_cart_split', '' );
	SPC()->session->set( 'whcc_editor_ids', '' );
};

/**
 * Run one combination test. Outgoing requests go to the lab or are stopped, email is stopped,
 * the Analytics and automated email listeners that write to shared tables on add to cart are
 * switched off, and the shipping method filters the test added are taken off at the end.
 * PHP warnings and notices raised by the code under test are collected and checked.
 */
$combo_galleries_made = array();
$combo_test           = function ( $name, $callback ) use ( $t, $combo_http, $combo_whcc_catalog, $combo_whcc_forget, &$combo_blocked ) {
	$t::test(
		$name,
		function () use ( $t, $callback, $combo_http, $combo_whcc_catalog, $combo_whcc_forget, &$combo_blocked ) {
			global $wp_filter;
			$hooks  = array( 'sunshine_shipping_methods', 'sunshine_checkout_delivery_options', 'sunshine_delivery_methods', 'sunshine_options_shipping' );
			$before = array();
			foreach ( $hooks as $hook ) {
				$before[ $hook ] = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array();
			}
			$combo_blocked = array();
			add_filter( 'pre_http_request', $combo_http, PHP_INT_MAX, 3 );
			add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
			add_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
			add_filter( 'pre_transient_sunshine_whcc_products', $combo_whcc_catalog, PHP_INT_MAX );
			$quiet     = array();
			$listeners = array(
				'sunshine_add_cart_item' => array( 'sunshine_adv_reports_add_to_cart', 'sunshine_email_queue_after_cart' ),
				'sunshine_cart_update'   => array( 'sunshine_adv_reports_cart_update' ),
			);
			foreach ( $listeners as $hook => $callbacks ) {
				foreach ( $callbacks as $hook_callback ) {
					$priority = has_action( $hook, $hook_callback );
					if ( false !== $priority ) {
						remove_action( $hook, $hook_callback, $priority );
						$quiet[] = array( $hook, $hook_callback, $priority );
					}
				}
			}
			// WHCC settings at their defaults, with a made-up key so the real one is never used.
			$t::option( 'whcc_environment', 'sandbox' );
			$t::option( 'whcc_sandbox_api_key', 'spc-totals-offline-key' );
			$t::option( 'whcc_production_api_key', 'spc-totals-offline-key' );
			$t::option( 'whcc_shipping_prices', array() );
			$t::option( 'whcc_require_signature', 'no' );
			$t::option( 'whcc_signature_threshold', '' );
			$t::option( 'whcc_mixed_cart_surcharge', '' );
			$t::option( 'whcc_show_shipping_packages', 'no' );
			$t::option( 'whcc_send_customer_emails', 'no' );
			$t::option( 'proofing', '' );
			// The WHCC rate limit counts visitors by IP address and saves the count.
			$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
			unset( $_SERVER['REMOTE_ADDR'] );
			$combo_whcc_forget();

			$warnings = array();
			set_error_handler(
				function ( $level, $message, $file, $line ) use ( &$warnings ) {
					if ( ! ( error_reporting() & $level ) ) {
						return false;
					}
					$kind       = in_array( $level, array( E_DEPRECATED, E_USER_DEPRECATED ), true ) ? 'deprecated' : 'warning';
					$warnings[] = $kind . ': ' . $message . ' (' . basename( $file ) . ':' . $line . ')';
					return true;
				}
			);
			try {
				$callback();
			} finally {
				restore_error_handler();
				remove_filter( 'pre_http_request', $combo_http, PHP_INT_MAX );
				remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
				remove_filter( 'sunshine_checkout_allow_order_notify', '__return_false', PHP_INT_MAX );
				remove_filter( 'pre_transient_sunshine_whcc_products', $combo_whcc_catalog, PHP_INT_MAX );
				foreach ( $quiet as $hook ) {
					add_action( $hook[0], $hook[1], $hook[2] );
				}
				if ( null !== $remote_addr ) {
					$_SERVER['REMOTE_ADDR'] = $remote_addr;
				}
				unset( $_GET['whcc_editor_id'] );
				$combo_whcc_forget();
				foreach ( $hooks as $hook ) {
					if ( empty( $wp_filter[ $hook ] ) ) {
						continue;
					}
					foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
						foreach ( $callbacks as $callback_id => $entry ) {
							if ( ! isset( $before[ $hook ][ $priority ][ $callback_id ] ) ) {
								remove_filter( $hook, $entry['function'], $priority );
							}
						}
					}
				}
			}
			// Warnings other agents already reported say so in the label.
			$warnings = array_values( array_unique( $warnings ) );
			$known    = '';
			foreach ( $warnings as $warning ) {
				if ( false !== strpos( $warning, 'foreach() argument must be of type array|object, string given (hooks.php:290)' ) ) {
					$known = ' [known: PHP warning when a session fee order is created for a customer with no session products]';
				}
			}
			$t::same( array(), $warnings, 'no PHP warnings, notices or deprecations' . $known );
			// Building a PayPal order always tries to reach PayPal (stopped above), so only
			// other requests count.
			$unexpected = array_values(
				array_filter(
					$combo_blocked,
					function ( $request ) {
						return false === strpos( $request, 'api-m.sandbox.paypal.com' ) && false === strpos( $request, 'api-m.paypal.com' );
					}
				)
			);
			$t::same( array(), $unexpected, 'nothing else tried to reach an outside service' );
		}
	);
};

/*
 * Things saved the way the admin screens save them.
 */

$combo_level_ids = function () {
	$ids = array();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		if ( $level ) {
			$ids[] = (int) $level->get_id();
		}
	}
	return $ids;
};

/**
 * A product saved the way the product edit screen saves it: every field on the screen is
 * stored, empty ones as ''. Prices and extra shipping are stored for every price level, blank
 * where the product is not sold. $prices is price level id => price.
 */
$combo_product = function ( $type, $prices, $fields = array(), $name = '' ) use ( $t, $combo_level_ids ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-product',
			'post_status' => 'publish',
			'post_title'  => $name ? $name : 'Combo test ' . $type,
		)
	);
	if ( ! $id || is_wp_error( $id ) ) {
		throw new Exception( 'Could not make a product' );
	}
	$t::track( 'post', $id );
	$all_prices   = array();
	$all_shipping = array();
	foreach ( $combo_level_ids() as $level_id ) {
		$all_prices[ $level_id ]   = isset( $prices[ $level_id ] ) ? (string) $prices[ $level_id ] : '';
		$all_shipping[ $level_id ] = '';
	}
	$meta = array(
		'type'             => $type,
		'price'            => $all_prices,
		'taxable'          => '1',
		'disable_shipping' => '',
		'shipping'         => $all_shipping,
		'min_qty'          => '',
		'max_qty'          => '',
	);
	if ( 'download' === $type ) {
		// The Download tab, plus the Video Product checkbox the Video Sales add-on puts on it.
		$meta['gallery_download'] = '';
		$meta['subgalleries']     = '';
		$meta['dimensions']       = array(
			'w' => '',
			'h' => '',
		);
		$meta['watermark']        = '';
		$meta['free']             = '';
		$meta['order']            = '';
		$meta['video_product']    = '';
	}
	if ( 'general' === $type ) {
		// Sell Anything: blank stock means unlimited.
		$meta['stock'] = '';
	}
	if ( 'multi-image' === $type ) {
		$meta['image_count']     = '3';
		$meta['allowed_sources'] = array( 'current' );
	}
	foreach ( array_merge( $meta, $fields ) as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
};

/**
 * A public gallery with photos, saved the way the gallery edit screen saves it, with the
 * Downloads tab left off. Photos are attachments with no file, which the cart does not need.
 */
$combo_gallery = function ( $price_level, $photo_count, $with_videos = false ) use ( $t, &$combo_galleries_made ) {
	$gallery_id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-gallery',
			'post_status' => 'publish',
			'post_title'  => 'Combo test gallery',
		)
	);
	if ( ! $gallery_id || is_wp_error( $gallery_id ) ) {
		throw new Exception( 'Could not make a gallery' );
	}
	$t::track( 'post', $gallery_id );
	$combo_galleries_made[] = $gallery_id;
	$fields                 = array(
		'status'                    => 'default',
		'access_type'               => '',
		'end_date'                  => '',
		'disable_products'          => '',
		'price_level'               => (string) $price_level,
		'free_downloads'            => '',
		'free_image_download_count' => '',
		'download_users'            => array(),
		'free_download_email'       => '',
		'free_download_passcode'    => '',
		// Mark it as already announced, so nothing emails customers about a test gallery.
		'_sunshine_gallery_ready'   => time(),
	);
	foreach ( $fields as $key => $value ) {
		update_post_meta( $gallery_id, $key, $value );
	}
	$photo_ids = array();
	for ( $i = 1; $i <= $photo_count; $i++ ) {
		$photo_id = wp_insert_attachment(
			array(
				'post_title'     => 'combo-photo-' . $i,
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			),
			false,
			$gallery_id
		);
		if ( ! $photo_id || is_wp_error( $photo_id ) ) {
			throw new Exception( 'Could not make a photo' );
		}
		$t::track( 'post', $photo_id );
		add_post_meta( $photo_id, 'sunshine_file_name', 'combo-photo-' . $i . '.jpg' );
		add_post_meta( $photo_id, 'sunshine_watermark', 0 );
		if ( $with_videos ) {
			// Linked to a video the way the Video Sales upload does it.
			$video_id = wp_insert_attachment(
				array(
					'post_title'     => 'combo-clip-' . $i,
					'post_mime_type' => 'video/mp4',
					'post_status'    => 'inherit',
				),
				false,
				0
			);
			$t::track( 'post', $video_id );
			update_post_meta( $photo_id, 'sunshine_video_attachment_id', $video_id );
			update_post_meta( $photo_id, 'sunshine_video_file_path', '/tmp/combo-clip-' . $photo_id . '.mp4' );
			update_post_meta( $photo_id, 'sunshine_video_file_url', 'https://test.invalid/combo-clip-' . $photo_id . '.mp4' );
			update_post_meta( $video_id, 'sunshine_video_image_id', $photo_id );
			update_post_meta( $video_id, '_sunshine_hide_from_media_library', true );
		}
		$photo_ids[] = $photo_id;
	}
	update_post_meta( $gallery_id, 'images', $photo_ids );
	return array(
		'id'     => $gallery_id,
		'photos' => $photo_ids,
	);
};

/**
 * A product option made through the Product Options add-on's own save code, so the term meta
 * is what Products > Product Options saves. $items is item id => name for a select option.
 */
$combo_option = function ( $type, $items = array() ) use ( $t ) {
	$saved_post = $_POST;
	$_POST      = array( 'type' => $type );
	if ( ! empty( $items ) ) {
		$_POST['option_item_id']          = array_keys( $items );
		$_POST['option_item_name']        = array_values( $items );
		$_POST['option_item_description'] = array_fill( 0, count( $items ), '' );
		$_POST['option_item_image']       = array_fill( 0, count( $items ), '' );
	}
	try {
		$term = wp_insert_term( 'Combo test ' . $type . ' ' . wp_rand( 100000, 999999 ), 'sunshine-product-option' );
	} finally {
		$_POST = $saved_post;
	}
	if ( is_wp_error( $term ) ) {
		throw new Exception( 'Could not make a product option: ' . $term->get_error_message() );
	}
	$t::track( 'term', $term['term_id'], 'sunshine-product-option' );
	return (int) $term['term_id'];
};

/**
 * Shared things made once per run: galleries (at the default price level, at another price
 * level, and one whose photos have videos), product options, and the products packages hold.
 */
$combo_shared       = null;
$combo_shared_setup = function () use ( &$combo_shared, $combo_gallery, $combo_option, $combo_product ) {
	if ( null !== $combo_shared ) {
		return $combo_shared;
	}
	$default = (int) sunshine_get_default_price_level_id();
	$other   = 0;
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		if ( $level && (int) $level->get_id() !== $default ) {
			$other = (int) $level->get_id();
			break;
		}
	}
	$shared = array(
		'default' => $default,
		'other'   => $other,
		'main'    => $combo_gallery( $default, 8 ),
		'level'   => $other ? $combo_gallery( $other, 6 ) : null,
		'videos'  => $combo_gallery( $default, 6, true ),
		'select'  => 0,
		'tick'    => 0,
	);
	if ( function_exists( 'sunshine_product_options_save' ) ) {
		$shared['select'] = $combo_option(
			'select',
			array(
				'cmbsel1' => 'Black frame',
				'cmbsel2' => 'Oak frame',
				'cmbsel3' => 'No frame',
			)
		);
		$shared['tick']   = $combo_option( 'checkbox' );
	}
	// What packages hold: an 8x10 print and a digital file, sold on their own for 45 and 50.
	$shared['package_print']    = $combo_product( 'print', array( $default => '45' ), array(), 'Combo test 8x10 in a package' );
	$shared['package_download'] = $combo_product( 'download', array( $default => '50' ), array( 'taxable' => '' ), 'Combo test file in a package' );
	$combo_shared               = $shared;
	return $shared;
};

/**
 * Run one of the store's ajax handlers with these posted values and return its JSON reply.
 */
$combo_ajax = function ( $handler, $post, $nonce = 'sunshinephotocart' ) {
	$saved_post = $_POST;
	$_POST      = $post;
	if ( $nonce ) {
		$_POST['security'] = wp_create_nonce( $nonce );
	}
	$die = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $die );
	$output = '';
	ob_start();
	try {
		call_user_func( $handler );
	} catch ( SPC_Totals_Test_Done $e ) {
		// Every handler ends by sending JSON.
	} finally {
		$output = ob_get_clean();
		$_POST  = $saved_post;
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $die );
	}
	$response = json_decode( $output, true );
	return is_array( $response ) ? $response : array(
		'success' => false,
		'raw'     => substr( $output, 0, 300 ),
	);
};

// Add a product for a photo the way the add to cart window does.
$combo_add_photo = function ( $product_id, $photo_id, $qty, $options = array() ) use ( $combo_ajax ) {
	$post = array(
		'product_id' => $product_id,
		'image_id'   => $photo_id,
		'gallery_id' => sunshine_get_image( $photo_id )->get_gallery_id(),
		'qty'        => $qty,
	);
	if ( ! empty( $options ) ) {
		$post['options'] = $options;
	}
	$reply = $combo_ajax( 'sunshine_modal_add_item_to_cart', $post );
	if ( empty( $reply['success'] ) ) {
		throw new Exception( 'Could not add product ' . $product_id . ' for photo ' . $photo_id . ': ' . wp_json_encode( $reply ) );
	}
};

/**
 * Make each ingredient line and say how to add it. Returns the line's spec: what the customer
 * sees on the price tag for one, how many, whether it is shipped, and its product.
 */
$combo_make_line = function ( $index, $line, $recipe, &$context ) use ( $t, $combo_product, $combo_add_photo, $combo_ajax, &$combo_whcc, $combo_whcc_adder, $combo_whcc_forget ) {
	list( $kind, $price, $qty, $variant ) = $line;
	$shared  = $context['shared'];
	$default = $shared['default'];
	$photo   = $shared['main']['photos'][ $index % count( $shared['main']['photos'] ) ];
	$spec    = array(
		'kind'     => $kind,
		'tag'      => (float) $price,
		'qty'      => (int) $qty,
		'ships'    => true,
		'product'  => 0,
		'discount' => null, // The line discount a customer expects, when the line has one.
	);

	switch ( $kind ) {
		case 'print':
		case 'untaxed print':
			$spec['product'] = $combo_product( 'print', array( $default => $price ), array( 'taxable' => 'print' === $kind ? '1' : '' ) );
			$spec['add']     = function () use ( $combo_add_photo, $spec, $photo ) {
				$combo_add_photo( $spec['product'], $photo, $spec['qty'] );
			};
			break;

		case 'print with options':
			if ( empty( $shared['select'] ) ) {
				$t::skip( 'Product Options add-on is not active' );
			}
			$item_prices     = array(
				'cmbsel1' => 5,
				'cmbsel2' => 12.5,
				'cmbsel3' => 0,
			);
			$chosen          = array_keys( $item_prices )[ $variant % 3 ];
			$ticked          = $variant < 3;
			$spec['product'] = $combo_product(
				'print',
				array( $default => $price ),
				array(
					'options' => array(
						$shared['select'] => array(
							'items' => array(
								'cmbsel1' => array( $default => '5' ),
								'cmbsel2' => array( $default => '12.50' ),
								'cmbsel3' => array( $default => '0' ),
							),
						),
						$shared['tick']   => array( $default => '3.25' ),
					),
				)
			);
			$options         = array( $shared['select'] => $chosen );
			if ( $ticked ) {
				$options[ $shared['tick'] ] = '1';
			}
			// The price tag is the print plus the chosen frame, plus the checkbox when ticked.
			$spec['tag'] = round( $price + $item_prices[ $chosen ] + ( $ticked ? 3.25 : 0 ), 2 );
			$spec['add'] = function () use ( $combo_add_photo, $spec, $photo, $options ) {
				$combo_add_photo( $spec['product'], $photo, $spec['qty'], $options );
			};
			break;

		case 'print at another price level':
			$level_price = round( $price * 1.5 + 1, 2 );
			$prices      = array( $default => $price );
			if ( $shared['other'] ) {
				$prices[ $shared['other'] ] = $level_price;
				$photo                      = $shared['level']['photos'][ $index % count( $shared['level']['photos'] ) ];
				$spec['tag']                = $level_price;
			}
			$spec['product'] = $combo_product( 'print', $prices );
			$spec['add']     = function () use ( $combo_add_photo, $spec, $photo ) {
				$combo_add_photo( $spec['product'], $photo, $spec['qty'] );
			};
			break;

		case 'package':
			$t::require_plugin( 'sunshine_packages_init', 'Packages add-on' );
			// Variants 0, 2 and 4: the package also holds a digital file.
			$rows = array(
				array(
					'product' => (string) $shared['package_print'],
					'qty'     => '2',
				),
			);
			if ( 0 === $variant % 2 ) {
				$rows[] = array(
					'product' => (string) $shared['package_download'],
					'qty'     => '1',
				);
			}
			$spec['product'] = $combo_product(
				'package',
				array( $default => $price ),
				array(
					'package'         => $rows,
					'allowed_sources' => '',
				),
				'Combo test package'
			);
			$spec['add']     = function () use ( $spec ) {
				if ( ! SPC()->cart->add_item( $spec['product'], 0, '', '', array(), $spec['qty'] ) ) {
					throw new Exception( 'Could not add the package' );
				}
			};
			break;

		case 'album':
			$t::require_plugin( 'sunshine_register_multi_image_type', 'Multi-Image Products add-on' );
			$spec['product'] = $combo_product( 'multi-image', array( $default => $price ) );
			$photos          = array_slice( $shared['main']['photos'], 0, 3 );
			$gallery_id      = $shared['main']['id'];
			$spec['add']     = function () use ( $combo_ajax, $spec, $photos, $gallery_id ) {
				// From the gallery's store page: no single photo, the 3 chosen photos as the option.
				$reply = $combo_ajax(
					'sunshine_modal_add_item_to_cart',
					array(
						'product_id' => $spec['product'],
						'gallery_id' => $gallery_id,
						'qty'        => $spec['qty'],
						'options'    => array( 'images' => implode( ',', $photos ) ),
					)
				);
				if ( empty( $reply['success'] ) ) {
					throw new Exception( 'Could not add the album: ' . wp_json_encode( $reply ) );
				}
			};
			break;

		case 'general product':
			$t::require_plugin( 'sunshine_general_product_max_qty', 'Sell Anything add-on' );
			$spec['product'] = $combo_product( 'general', array( $default => $price ) );
			$spec['add']     = function () use ( $combo_ajax, $spec, $default ) {
				// From the store, which sends the price level.
				$reply = $combo_ajax(
					'sunshine_modal_add_item_to_cart',
					array(
						'product_id'  => $spec['product'],
						'price_level' => $default,
						'qty'         => $spec['qty'],
					)
				);
				if ( empty( $reply['success'] ) ) {
					throw new Exception( 'Could not add the general product: ' . wp_json_encode( $reply ) );
				}
			};
			break;

		case 'video':
			$t::require_plugin( 'sunshine_video_sales_cart_item_max_qty', 'Video Sales add-on' );
			$spec['ships']   = false;
			$spec['product'] = $combo_product( 'download', array( $default => $price ), array( 'video_product' => '1' ) );
			$video_photo     = $shared['videos']['photos'][ $index % count( $shared['videos']['photos'] ) ];
			$spec['add']     = function () use ( $combo_add_photo, $spec, $video_photo ) {
				$combo_add_photo( $spec['product'], $video_photo, 1 );
			};
			break;

		case 'download':
			$t::require_plugin( 'sunshine_product_is_download', 'Digital Downloads add-on' );
			$spec['ships']   = false;
			$spec['product'] = $combo_product( 'download', array( $default => $price ) );
			$spec['add']     = function () use ( $combo_add_photo, $spec, $photo ) {
				$combo_add_photo( $spec['product'], $photo, 1 );
			};
			break;

		case 'gallery download':
			$t::require_plugin( 'sunshine_product_is_download', 'Digital Downloads add-on' );
			$spec['ships']   = false;
			$spec['product'] = $combo_product( 'download', array( $default => $price ), array( 'gallery_download' => '1' ), 'Combo test full gallery' );
			$gallery_id      = $shared['main']['id'];
			$spec['add']     = function () use ( $combo_ajax, $spec, $gallery_id ) {
				// The full gallery download window's button sends no nonce.
				$reply = $combo_ajax(
					'sunshine_modal_gallery_add_to_cart',
					array(
						'gallery_id' => $gallery_id,
						'product_id' => $spec['product'],
					),
					''
				);
				if ( empty( $reply['success'] ) ) {
					throw new Exception( 'Could not add the full gallery download: ' . wp_json_encode( $reply ) );
				}
			};
			break;

		case 'session fee':
			$t::require_plugin( 'sunshine_session_fees_cart_item_discount', 'Session Fees add-on' );
			$context['needs_customer'] = true;
			$spec['ships']             = false;
			// The session includes two 8x10 prints, 10 in store credit and 5 download credits,
			// saved as its Session Fees tab saves them. Variants 0, 2 and 4 are taxed.
			$spec['product'] = $combo_product(
				'session-fee',
				array( $default => $price ),
				array(
					'taxable'          => 0 === $variant % 2 ? '1' : '',
					'require_session'  => '',
					'session_products' => array(
						array(
							'product' => (string) $shared['package_print'],
							'qty'     => '2',
						),
						array(
							'product' => 'credits',
							'qty'     => '10',
						),
						array(
							'product' => 'download_credits',
							'qty'     => '5',
						),
					),
				),
				'Combo test session'
			);
			$spec['add']     = function () use ( $spec, $default ) {
				// The way the session fees page adds it (no date picked).
				$product = sunshine_get_product( $spec['product'] );
				$meta    = array();
				if ( $product->get_included_products() ) {
					$meta['session_products'] = $product->get_included_products();
				}
				$item = SPC()->cart->add_item( $spec['product'], '', '', $default, '', 1, '', false, $meta );
				if ( empty( $item ) ) {
					throw new Exception( 'Could not add the session fee' );
				}
				SPC()->cart->update_item_by_hash( $item['hash'], $item );
			};
			break;

		case 'included print':
			$t::require_plugin( 'sunshine_session_fees_cart_item_discount', 'Session Fees add-on' );
			$context['needs_customer'] = true;
			$spec['product']           = $combo_product( 'print', array( $default => $price ) );
			// The customer's paid session includes some of these: fewer than they buy, or more.
			$included                  = 0 === $variant % 3 ? $qty + 2 : max( 1, $qty - 1 );
			$context['included'][]     = array(
				'product'  => (string) $spec['product'],
				'qty'      => (string) $included,
				'order_id' => 0,
			);
			// The included ones are free: the price of each, for as many as are included.
			$spec['discount'] = round( $spec['tag'] * min( $included, $qty ), 2 );
			$spec['add']      = function () use ( $combo_add_photo, $spec, $photo ) {
				$combo_add_photo( $spec['product'], $photo, $spec['qty'] );
			};
			break;

		case 'quantity discount print':
			$t::require_plugin( 'sunshine_cart_item_qty_discount', 'Quantity Discounts add-on' );
			// Saved the way the product screen saves the tiers: the text typed in.
			if ( 0 === $variant % 2 ) {
				$tier     = array(
					'qty'      => '3',
					'discount' => '10',
					'type'     => 'percent',
				);
				$per_item = $price * 0.10;
			} else {
				$off      = min( 1, floor( $price * 50 ) / 100 );
				$tier     = array(
					'qty'      => '2',
					'discount' => (string) $off,
					'type'     => 'amount',
				);
				$per_item = $off;
			}
			$spec['product'] = $combo_product( 'print', array( $default => $price ), array( 'qty_discount' => array( 'items' => array( $tier ) ) ) );
			// Off each one once the cart has enough of them, rounded to the cent for the line.
			$spec['discount'] = $qty >= intval( $tier['qty'] ) ? round( $per_item * $qty, 2 ) : 0;
			$spec['add']      = function () use ( $combo_add_photo, $spec, $photo ) {
				$combo_add_photo( $spec['product'], $photo, $spec['qty'] );
			};
			break;

		case 'gift card purchase':
			$t::require_plugin( 'sunshine_gift_card_add_to_cart_item', 'Gift Cards add-on' );
			$spec['ships'] = false;
			// The gift card product the add-on makes: no price of its own, preset amounts,
			// custom amounts up to 1000, and not taxed.
			$spec['product'] = $combo_product(
				'gift-card',
				array( $default => '' ),
				array(
					'taxable'                  => '',
					'gift_card_preset_amounts' => '25,50,100,200,500',
					'gift_card_allow_custom'   => '1',
					'gift_card_max_amount'     => '1000',
				),
				'Combo test gift card'
			);
			$amount          = $spec['tag'];
			$spec['add']     = function () use ( $spec, $amount ) {
				// The Gift Cards page sends the amount and the recipient in the form post.
				$_POST['gift_card_amount']          = (string) $amount;
				$_POST['gift_card_delivery']        = 'immediate';
				$_POST['gift_card_recipient_name']  = 'Combo Gift';
				$_POST['gift_card_recipient_email'] = 'combo-gift@example.com';
				try {
					$item = SPC()->cart->add_item( $spec['product'], 0, '', sunshine_get_default_price_level_id(), '', 1 );
				} finally {
					unset( $_POST['gift_card_amount'], $_POST['gift_card_delivery'], $_POST['gift_card_recipient_name'], $_POST['gift_card_recipient_email'] );
				}
				if ( empty( $item ) ) {
					throw new Exception( 'Could not add the gift card' );
				}
			};
			break;

		case 'whcc print':
			$t::require_plugin( 'sunshine_whcc_init', 'WHCC add-on' );
			if ( ! $combo_whcc_adder() ) {
				$t::skip( 'WHCC add-on did not load' );
			}
			$context['whcc'] = true;
			$combo_whcc['count']++;
			$whcc_id                           = $combo_whcc['run'] . 'print' . $combo_whcc['count'];
			$combo_whcc['catalog'][ $whcc_id ] = array(
				'_id'      => $whcc_id,
				'name'     => 'Photo Print',
				'category' => array( 'name' => 'Photo Prints' ),
			);
			// A WHCC product: the chosen WHCC product, a markup for the default price level,
			// and a price of 0 there because WHCC sets the real price.
			$spec['product'] = $combo_product(
				'whcc',
				array( $default => 0 ),
				array(
					'whcc_product' => $whcc_id,
					'whcc_markup'  => array( $default => '50' ),
					'taxable'      => 0 === $variant % 2 ? '1' : '',
				),
				'Combo test WHCC print'
			);
			// WHCC quotes the line at the price for one times the quantity.
			$editor_id                            = 'spccombo' . wp_rand( 1000000, 9999999 ) . $combo_whcc['count'];
			$combo_whcc['editors'][ $editor_id ] = array(
				'whcc_id' => $whcc_id,
				'qty'     => $qty,
				'unit'    => (float) $price,
				'base'    => round( $price / 1.5, 2 ),
				'photo'   => $photo,
				'summary' => array(
					array(
						'name'        => 'size',
						'description' => '8x10',
					),
				),
			);
			$spec['add'] = function () use ( $combo_whcc_adder, $combo_whcc_forget, $editor_id ) {
				$adder = $combo_whcc_adder();
				sunshine_whcc_register_session_editor( $editor_id );
				$_GET['whcc_editor_id'] = $editor_id;
				// It ends by redirecting back; stop there instead of exiting.
				$redirect = function () {
					throw new Exception( 'spc-combo-whcc-redirect' );
				};
				add_filter( 'wp_redirect', $redirect, -1000 );
				// With Video Sales on, the WHCC item is matched to the wrong product (known), so
				// that lookup filter is off while the item is added.
				$video_priority = has_filter( 'sunshine_get_product_args', 'sunshine_video_sales_filter_product_args' );
				if ( false !== $video_priority ) {
					remove_filter( 'sunshine_get_product_args', 'sunshine_video_sales_filter_product_args', $video_priority );
				}
				try {
					$adder->add_to_cart();
				} catch ( Exception $e ) {
					if ( 'spc-combo-whcc-redirect' !== $e->getMessage() ) {
						throw $e;
					}
				} finally {
					remove_filter( 'wp_redirect', $redirect, -1000 );
					if ( false !== $video_priority ) {
						add_filter( 'sunshine_get_product_args', 'sunshine_video_sales_filter_product_args', $video_priority, 2 );
					}
					unset( $_GET['whcc_editor_id'] );
					$combo_whcc_forget();
				}
				foreach ( (array) SPC()->cart->get_cart() as $item ) {
					if ( ! empty( $item['meta']['whcc_editor_id'] ) && $item['meta']['whcc_editor_id'] === $editor_id ) {
						return;
					}
				}
				throw new Exception( 'The WHCC item was not added: ' . wp_json_encode( SPC()->notices->get_notices() ) );
			};
			break;

		default:
			throw new Exception( 'Unknown ingredient ' . $kind );
	}
	return $spec;
};

/**
 * A discount code saved the way the Discounts screen saves it (every field, empty ones as ''),
 * then entered at checkout.
 */
$combo_code = function ( $meta ) use ( $t ) {
	$t::require_plugin( 'sunshine_get_discount_by_code', 'Discount Codes add-on' );
	$code = 'SPCCOMBO' . wp_rand( 100000, 999999 );
	$id   = wp_insert_post(
		array(
			'post_type'   => 'sunshine-discount',
			'post_status' => 'publish',
			'post_title'  => 'Combo test ' . $code,
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
	$codes   = array_filter( (array) SPC()->session->get( 'discounts' ) );
	$codes[] = $code;
	SPC()->session->set( 'discounts', array_values( $codes ) );
};

/**
 * Build the recipe's cart: products, customer, lines, codes, gift card, credits, shipping and
 * payment fee. Returns what the test needs to check it.
 */
$combo_build = function ( $recipe ) use ( $t, $combo_shared_setup, $combo_make_line, $combo_code ) {
	$context = array(
		'shared'           => $combo_shared_setup(),
		'needs_customer'   => $recipe['credits'] > 0,
		'included'         => array(),
		'whcc'             => false,
	);

	if ( $recipe['tax'] ) {
		$t::tax( $recipe['tax'] );
	}
	$t::option( 'price_has_tax', $recipe['incl'] ? 'yes' : 'no' );
	$t::option( 'discount_after_tax', $recipe['after_tax'] ? 1 : '' );

	$specs = array();
	foreach ( $recipe['lines'] as $index => $line ) {
		$specs[] = $combo_make_line( $index, $line, $recipe, $context );
	}

	$customer_id = 0;
	if ( $context['needs_customer'] ) {
		$customer_id = $t::customer();
		if ( $context['included'] ) {
			// Saved the way a paid session saves what it includes.
			update_user_meta( $customer_id, 'sunshine_session_products', $context['included'] );
		}
		if ( $recipe['credits'] ) {
			// Store credit typed on the customer's admin screen.
			update_user_meta( $customer_id, 'sunshine_credits', (string) $recipe['credits'] );
		}
		SPC()->customer = new SPC_Customer( $customer_id );
	}

	foreach ( $specs as $spec ) {
		$spec['add']();
	}

	$first = $specs[0];
	switch ( $recipe['code'] ) {
		case 'percent off the order':
			$combo_code(
				array(
					'discount_type'   => 'percent-total',
					'discount_amount' => '15',
				)
			);
			break;
		case 'amount off the order':
			$combo_code(
				array(
					'discount_type'   => 'amount-total',
					'discount_amount' => '10',
				)
			);
			break;
		case 'percent off one product':
			$combo_code(
				array(
					'discount_type'    => 'percent-product',
					'discount_amount'  => '25',
					'allowed_products' => array( $first['product'] ),
				)
			);
			break;
		case 'amount off one product':
			// Half the price of one, so it never takes more than the product costs.
			$combo_code(
				array(
					'discount_type'    => 'amount-product',
					'discount_amount'  => (string) max( 0.01, floor( $first['tag'] * 50 ) / 100 ),
					'allowed_products' => array( $first['product'] ),
				)
			);
			break;
		case 'percent off the order and shipping':
			$combo_code(
				array(
					'discount_type'     => 'percent-total',
					'discount_amount'   => '10',
					'apply_to_shipping' => '1',
				)
			);
			break;
		case 'two codes':
			$combo_code(
				array(
					'discount_type'   => 'percent-total',
					'discount_amount' => '20',
				)
			);
			$combo_code(
				array(
					'discount_type'   => 'amount-total',
					'discount_amount' => '5',
				)
			);
			break;
		case 'percent off one of each product':
			$combo_code(
				array(
					'discount_type'        => 'percent-product',
					'discount_amount'      => '50',
					'max_product_quantity' => '1',
				)
			);
			break;
		case 'amount bigger than the order':
			$combo_code(
				array(
					'discount_type'   => 'amount-total',
					'discount_amount' => '1000',
				)
			);
			break;
		case 'two percent codes over 100':
			$combo_code(
				array(
					'discount_type'   => 'percent-total',
					'discount_amount' => '60',
				)
			);
			$combo_code(
				array(
					'discount_type'   => 'percent-total',
					'discount_amount' => '50',
				)
			);
			break;
	}

	if ( $recipe['gift_card'] ) {
		$t::gift_card( $recipe['gift_card'] );
	}
	if ( $recipe['credits'] ) {
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
	}

	// Shipping. A cart with WHCC items ships with WHCC's own method.
	$shipping_taxable = false;
	if ( 'none' !== $recipe['shipping'] ) {
		$settings         = array(
			'flat 8.95'              => array( '8.95', 'cart', '' ),
			'flat 6 taxed'           => array( '6', 'cart', '1' ),
			'flat 2.50 a line taxed' => array( '2.50', 'line', '1' ),
			'flat 1.25 an item'      => array( '1.25', 'qty', '' ),
		);
		$setting          = $settings[ $recipe['shipping'] ];
		$shipping_taxable = '1' === $setting[2];
		if ( $context['whcc'] ) {
			$t::option(
				'shipping_methods',
				array(
					'spccombowhcc' => array(
						'id'     => 'whcc_lowest_cost',
						'active' => 1,
					),
				)
			);
			$t::option( 'whcc_lowest_cost_taxable_spccombowhcc', $setting[2] );
			SPC()->cart->set_checkout_data_item( 'shipping_method', 'spccombowhcc' );
		} else {
			$t::option(
				'shipping_methods',
				array(
					'spccomboflat' => array(
						'id'     => 'flat_rate',
						'active' => 1,
					),
				)
			);
			$t::option( 'flat_rate_price_spccomboflat', $setting[0] );
			$t::option( 'flat_rate_price_type_spccomboflat', $setting[1] );
			$t::option( 'flat_rate_taxable_spccomboflat', $setting[2] );
			SPC()->cart->set_checkout_data_item( 'shipping_method', 'spccomboflat' );
		}
		SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
	}

	// Payment method fee on the Test payment method.
	$t::option( 'test_fee', 'none' === $recipe['fee'] ? '' : ( 'fee 3%' === $recipe['fee'] ? 'percent' : 'amount' ) );
	$t::option( 'test_fee_amount', 'fee 3%' === $recipe['fee'] ? '3' : '2.50' );
	$t::option( 'test_fee_name', 'Card fee' );
	SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );

	$t::checkout_address();

	return array(
		'specs'            => $specs,
		'customer'         => $customer_id,
		'shipping_taxable' => $shipping_taxable,
		'whcc'             => $context['whcc'],
	);
};

/*
 * Reading the cart and checking the rules.
 */

$combo_lines = function () {
	$items = SPC()->cart->get_cart_items();
	return $items ? array_values( $items ) : array();
};

// Everything the cart shows, read the way the cart page and checkout read it.
$combo_snapshot = function () use ( $combo_lines ) {
	$cart  = SPC()->cart;
	$lines = array();
	foreach ( $combo_lines() as $line ) {
		$lines[] = array(
			'qty'      => (int) $line->get_qty(),
			'price'    => (float) $line->get_price(),
			'total'    => (float) $line->get_total(),
			'tax'      => (float) $line->get_tax_total(),
			'discount' => (float) $line->get_discount_total(),
			'taxable'  => (bool) $line->is_taxable(),
		);
	}
	// A fee of 0 is the same as no fee.
	$fees = array();
	foreach ( (array) $cart->get_fees() as $id => $fee ) {
		if ( abs( (float) $fee['amount'] ) >= 0.005 ) {
			$fees[ $id ] = (float) $fee['amount'];
		}
	}
	// The totals the gift card and store credit are sized from are read before the final total.
	$snap          = array(
		'subtotal'      => (float) $cart->get_subtotal(),
		'subtotal_tax'  => (float) $cart->get_subtotal_tax(),
		'discount'      => (float) $cart->get_discount(),
		'tax'           => (float) $cart->get_tax(),
		'shipping'      => (float) $cart->get_shipping(),
		'shipping_tax'  => (float) $cart->get_shipping_tax(),
		'fees'          => $fees,
		'gift_card'     => array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) ),
		'before_card'   => (float) $cart->get_total( array( 'fees', 'credits' ) ),
		'before_credit' => (float) $cart->get_total( array( 'credits' ) ),
		'credits'       => (float) $cart->get_credits_applied(),
		'lines'         => $lines,
	);
	$snap['total'] = (float) $cart->get_total();
	return $snap;
};

$combo_is_cents = function ( $amount ) {
	return abs( $amount * 100 - round( $amount * 100 ) ) < 0.0001;
};

/**
 * The rules every cart must follow, checked on what the cart shows ($snap).
 */
$combo_check_cart = function ( $recipe, $built, $snap ) use ( $t, $combo_is_cents ) {
	$rate  = $recipe['tax'] / 100;
	$incl  = $recipe['incl'];
	$after = $recipe['after_tax'];

	// Every amount is a whole number of cents.
	$amounts = array(
		'subtotal'     => $snap['subtotal'],
		'discount'     => $snap['discount'],
		'tax'          => $snap['tax'],
		'shipping'     => $snap['shipping'],
		'shipping tax' => $snap['shipping_tax'],
		'credits'      => $snap['credits'],
		'total'        => $snap['total'],
	);
	foreach ( $snap['fees'] as $id => $amount ) {
		$amounts[ 'fee ' . $id ] = $amount;
	}
	foreach ( $snap['lines'] as $i => $line ) {
		$name                          = 'line ' . ( $i + 1 ) . ' (' . ( isset( $built['specs'][ $i ] ) ? $built['specs'][ $i ]['kind'] : 'not added by the test' ) . ')';
		$amounts[ $name . ' total' ]    = $line['total'];
		$amounts[ $name . ' tax' ]      = $line['tax'];
		$amounts[ $name . ' discount' ] = $line['discount'];
	}
	$not_cents = array();
	foreach ( $amounts as $name => $amount ) {
		if ( ! $combo_is_cents( $amount ) ) {
			$not_cents[ $name ] = $amount;
		}
	}
	$t::same( array(), $not_cents, 'every amount is a whole number of cents' );

	// One cart line for each ingredient, with the quantity the customer chose.
	$t::same( count( $built['specs'] ), count( $snap['lines'] ), 'one cart line for each thing added' );

	$fees_total     = array_sum( $snap['fees'] );
	$line_discounts = 0;
	$tags           = 0;
	$taxable_net    = 0; // Taxable line totals, before tax.
	$taxable_gross  = 0; // Taxable line totals with their tax.
	$ships          = false;
	foreach ( $snap['lines'] as $i => $line ) {
		if ( empty( $built['specs'][ $i ] ) ) {
			continue;
		}
		$spec = $built['specs'][ $i ];
		$name = 'line ' . ( $i + 1 ) . ' (' . $spec['kind'] . ')';
		$t::same( $spec['qty'], $line['qty'], $name . ' quantity' );
		// What the customer saw on the price tag, times how many. With prices that include tax
		// the line's tax is part of that.
		$paid = $line['total'] + $line['discount'] + ( $incl ? $line['tax'] : 0 );
		$t::money( round( $spec['tag'] * $spec['qty'], 2 ), $paid, $name . ' costs the price tag times the quantity before its own discount' );
		$t::same( true, $line['total'] >= -0.001, $name . ' total is not below zero (' . $line['total'] . ')' );
		$t::same( true, $line['discount'] <= $spec['tag'] * $spec['qty'] + 0.005, $name . ' discount (' . $line['discount'] . ') is not more than the line costs' );
		if ( null !== $spec['discount'] ) {
			$t::money( $spec['discount'], $line['discount'], $name . ' line discount' );
		}
		$t::same( true, $line['tax'] >= -0.001, $name . ' tax is not below zero (' . $line['tax'] . ')' );
		// A line's tax is the rate on what the line costs (or the tax inside it when prices
		// include tax), to the cent. Untaxed lines have none.
		if ( ! $line['taxable'] || ! $rate ) {
			$t::money( 0, $line['tax'], $name . ' has no tax' );
		} elseif ( $incl ) {
			$inside = ( $line['total'] + $line['tax'] ) * $rate / ( 1 + $rate );
			$t::same( true, abs( $line['tax'] - $inside ) <= 0.011, $name . ' tax (' . $line['tax'] . ') is the tax inside what the line costs (' . round( $inside, 4 ) . ')' );
		} else {
			$t::same( true, abs( $line['tax'] - $line['total'] * $rate ) <= 0.011, $name . ' tax (' . $line['tax'] . ') is the rate on the line total (' . round( $line['total'] * $rate, 4 ) . ')' );
		}
		$line_discounts += $line['discount'];
		$tags           += $spec['tag'] * $spec['qty'];
		if ( $line['taxable'] ) {
			$taxable_net   += $line['total'];
			$taxable_gross += $line['total'] + $line['tax'];
		}
		$ships = $ships || $spec['ships'];
	}

	// The total is the cart's own parts added up, and never below zero.
	if ( $incl ) {
		$items = 0;
		foreach ( $snap['lines'] as $line ) {
			$items += $line['total'] + $line['tax'];
		}
		$sum = $items - $snap['discount'] + $snap['shipping'] + $snap['shipping_tax'] + $fees_total - $snap['credits'];
	} else {
		$sum = $snap['subtotal'] - $snap['discount'] + $snap['tax'] + $snap['shipping'] + $fees_total - $snap['credits'];
	}
	$t::same( true, $snap['total'] >= 0, 'total is not below zero' );
	$t::same( true, $sum > -0.005, 'the parts add up to zero or more, so nothing was cut off to stop the total going below zero (they add up to ' . round( $sum, 2 ) . ')' );
	$t::money( max( 0, $sum ), $snap['total'], 'total = subtotal - discount + tax + shipping + fees - credits' );

	// With prices that include tax, the customer pays the price tags, less discounts, plus
	// shipping and fees.
	if ( $incl ) {
		$t::money( max( 0, $tags - $line_discounts - $snap['discount'] + $snap['shipping'] + $snap['shipping_tax'] + $fees_total - $snap['credits'] ), $snap['total'], 'prices include tax: total is the price tags less discounts, plus shipping and fees' );
	}

	// A discount code never takes more than what it applies to: the items (with their tax when
	// the discount comes after tax or prices include tax), and shipping when the code says so.
	$with_tax     = $incl || $after;
	$eligible     = $snap['subtotal'] + ( $with_tax ? $snap['subtotal_tax'] : 0 );
	$all_shipping = $snap['shipping'] + ( $with_tax ? $snap['shipping_tax'] : 0 );
	$to_shipping  = 'percent off the order and shipping' === $recipe['code'];
	$t::same( true, $snap['discount'] <= $eligible + ( $to_shipping ? $all_shipping : 0 ) + 0.005, 'discount (' . $snap['discount'] . ') is not more than what it applies to (' . round( $eligible + ( $to_shipping ? $all_shipping : 0 ), 2 ) . ')' );

	// What each code should take off, worked out from what the lines cost. A code applies to
	// what the customer pays for each line after its own discount (with its tax when prices
	// include tax or the discount comes after tax), never to buying a gift card, and never to
	// more than that.
	$known      = array();
	$base       = 0;
	$first      = 0;
	$one_each   = 0;
	$line_disc  = false;
	foreach ( $snap['lines'] as $i => $line ) {
		if ( empty( $built['specs'][ $i ] ) ) {
			continue;
		}
		$paid = $line['total'] + ( $with_tax ? $line['tax'] : 0 );
		if ( 'gift card purchase' === $built['specs'][ $i ]['kind'] ) {
			$known[] = 'Discount codes take money off gift card purchases';
			continue;
		}
		$base     += $paid;
		$one_each += $line['qty'] ? $paid / $line['qty'] : 0;
		$line_disc = $line_disc || $line['discount'] > 0;
		if ( 0 === $i ) {
			$first = $paid;
		}
	}
	$expected = null;
	switch ( $recipe['code'] ) {
		case 'none':
			$expected = 0;
			$known    = array();
			break;
		case 'percent off the order':
			$expected = round( $base * 0.15, 2 );
			break;
		case 'amount off the order':
			$expected = min( 10, $base );
			break;
		case 'percent off one product':
			$expected = round( $first * 0.25, 2 );
			break;
		case 'amount off one product':
			$each     = max( 0.01, floor( $built['specs'][0]['tag'] * 50 ) / 100 );
			$expected = min( $first, round( $each * $built['specs'][0]['qty'], 2 ) );
			if ( round( $each * $built['specs'][0]['qty'], 2 ) > $first + 0.005 ) {
				$known[] = 'Amount off each product can take more than the product costs';
			}
			break;
		case 'percent off the order and shipping':
			$expected = round( ( $base + $snap['shipping'] + ( $with_tax ? $snap['shipping_tax'] : 0 ) ) * 0.10, 2 );
			if ( $incl && $snap['shipping_tax'] > 0 ) {
				$known[] = 'Discount that includes shipping takes the percent off shipping before tax when prices include tax';
			}
			break;
		case 'two codes':
			$expected = min( $base, round( $base * 0.20, 2 ) + min( 5, $base ) );
			break;
		case 'percent off one of each product':
			// Half off one of each product. A line with its own discount has no one price to take
			// half of, so those carts are not checked here.
			$expected = $line_disc ? null : round( $one_each * 0.5, 2 );
			break;
		case 'amount bigger than the order':
		case 'two percent codes over 100':
			$expected = $base;
			break;
	}
	if ( null !== $expected ) {
		$known_text = $known ? ' [known: ' . implode( '; ', array_unique( $known ) ) . ']' : '';
		$t::money( $expected, $snap['discount'], 'discount code takes off what it should' . $known_text );
	}

	// Tax is never below zero, and never more than the taxable amount times the rate.
	$t::same( true, $snap['tax'] >= 0, 'tax is not below zero' );
	$lines_count = max( 1, count( $snap['lines'] ) );
	$slack       = 0.01 * ( $lines_count + 1 );
	if ( ! $rate ) {
		$t::money( 0, $snap['tax'], 'no tax when taxes are off' );
	} elseif ( $incl ) {
		$ship_gross = $built['shipping_taxable'] ? $snap['shipping'] + $snap['shipping_tax'] : 0;
		$most       = ( $taxable_gross + $ship_gross ) * $rate / ( 1 + $rate );
		$least      = $after ? $most : max( 0, $taxable_gross + $ship_gross - $snap['discount'] ) * $rate / ( 1 + $rate );
		$t::same( true, $snap['tax'] <= $most + $slack, 'tax (' . $snap['tax'] . ') is not more than the tax inside the taxable prices (' . round( $most, 2 ) . ')' );
		$t::same( true, $snap['tax'] >= $least - $slack, 'tax (' . $snap['tax'] . ') is not less than the tax inside what is paid for taxable things (' . round( $least, 2 ) . ')' );
	} else {
		$ship_net = $built['shipping_taxable'] ? $snap['shipping'] : 0;
		$most     = ( $taxable_net + $ship_net ) * $rate;
		$least    = $after ? $most : max( 0, $taxable_net + $ship_net - $snap['discount'] ) * $rate;
		$t::same( true, $snap['tax'] <= $most + $slack, 'tax (' . $snap['tax'] . ') is not more than the taxable amount times the rate (' . round( $most, 2 ) . ')' );
		$t::same( true, $snap['tax'] >= $least - $slack, 'tax (' . $snap['tax'] . ') is not less than the taxable amount after discounts times the rate (' . round( $least, 2 ) . ')' );
	}

	// Shipping is only charged when something is shipped.
	if ( ! $ships ) {
		$t::money( 0, $snap['shipping'] + $snap['shipping_tax'], 'no shipping when nothing in the cart is shipped' );
	}

	// A gift card pays what the order costs and never more, and never more than its balance.
	$card_fee = isset( $snap['fees']['gift_card'] ) ? $snap['fees']['gift_card'] : 0;
	if ( $recipe['gift_card'] ) {
		$t::money( min( $recipe['gift_card'], max( 0, $snap['before_card'] ) ), $snap['gift_card'], 'gift card pays the order cost or its balance, whichever is less' );
		$t::money( -$snap['gift_card'], $card_fee, 'gift card is taken off the total' );
		// The Gift Cards docs: "If the gift card covers the full amount, no additional payment
		// is needed." A card fee is for paying by card, so it can't be what is left to pay.
		if ( $recipe['gift_card'] >= $snap['before_card'] ) {
			$t::money( 0, $snap['total'], 'a gift card that covers the order leaves nothing to pay' );
		}
	} else {
		$t::money( 0, $card_fee, 'no gift card, nothing taken off' );
	}

	// Store credit is used up to what is left to pay.
	if ( $recipe['credits'] ) {
		$t::money( min( $recipe['credits'], max( 0, $snap['before_credit'] ) ), $snap['credits'], 'credits used are the order cost or the credit balance, whichever is less' );
	} else {
		$t::money( 0, $snap['credits'], 'no credits used' );
	}

	// The payment fee: a fixed amount, or a percent of what is left to pay.
	$fee = isset( $snap['fees']['payment_method'] ) ? $snap['fees']['payment_method'] : 0;
	if ( 'fee 3%' === $recipe['fee'] ) {
		$t::same( true, abs( $fee - 0.03 * ( $snap['total'] - $fee ) ) <= 0.011, 'payment fee (' . $fee . ') is 3% of the rest of the total (' . round( $snap['total'] - $fee, 2 ) . ')' );
	} elseif ( 'fee 2.50' === $recipe['fee'] ) {
		// A card fee is for paying by card, so it is only charged when something is left to pay.
		$t::money( $snap['total'] - $fee > 0.005 ? 2.50 : 0, $fee, 'fixed payment fee, only when something is left to pay (total ' . $snap['total'] . ')' );
	} else {
		$t::money( 0, $fee, 'no payment fee' );
	}
};

// Two looks at the cart must show the same numbers.
$combo_same_snapshot = function ( $first, $second, $label ) use ( $t ) {
	foreach ( array( 'subtotal', 'discount', 'tax', 'shipping', 'shipping_tax', 'gift_card', 'credits', 'total' ) as $key ) {
		$t::money( $first[ $key ], $second[ $key ], $label . ': ' . str_replace( '_', ' ', $key ) );
	}
	$t::same( $first['fees'], $second['fees'], $label . ': fees' );
	$t::same( count( $first['lines'] ), count( $second['lines'] ), $label . ': number of lines' );
	foreach ( $first['lines'] as $i => $line ) {
		if ( empty( $second['lines'][ $i ] ) ) {
			continue;
		}
		foreach ( array( 'qty', 'total', 'tax', 'discount' ) as $key ) {
			$t::money( $line[ $key ], $second['lines'][ $i ][ $key ], $label . ': line ' . ( $i + 1 ) . ' ' . $key );
		}
	}
};

/**
 * Build the PayPal order for the cart and return what would be sent. Nothing reaches PayPal.
 */
$combo_paypal_args = function () {
	$paypal = sunshine_get_payment_method_by_id( 'paypal' );
	if ( ! $paypal ) {
		return null;
	}
	$sent    = null;
	$capture = function ( $args ) use ( &$sent ) {
		$sent = $args;
		return $args;
	};
	$die     = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'sunshine_paypal_order_args', $capture, 999 );
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
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $die );
	}
	return $sent;
};

// PayPal refuses an order unless amount = item_total + tax_total + shipping - discount, the
// items add up to item_total, and no item is below zero.
$combo_check_paypal = function ( $args, $snap ) use ( $t ) {
	if ( empty( $args['purchase_units'][0] ) ) {
		$t::same( true, false, 'PayPal: an order was built' );
		return;
	}
	$unit      = $args['purchase_units'][0];
	$breakdown = $unit['amount']['breakdown'];
	$items     = 0;
	$negative  = array();
	foreach ( $unit['items'] as $item ) {
		$items += (float) $item['unit_amount']['value'] * (int) $item['quantity'];
		if ( (float) $item['unit_amount']['value'] < 0 ) {
			$negative[] = $item['name'] . ' ' . $item['unit_amount']['value'];
		}
	}
	$t::same( array(), $negative, 'PayPal: no items below zero' );
	$t::money( $items, $breakdown['item_total']['value'], 'PayPal: items add up to item_total' );
	$t::money( $snap['total'], $unit['amount']['value'], 'PayPal: amount is the cart total' );
	$sum = (float) $breakdown['item_total']['value'] + (float) $breakdown['tax_total']['value'] + (float) $breakdown['shipping']['value'] - (float) $breakdown['discount']['value'];
	$t::money( $unit['amount']['value'], $sum, 'PayPal: item_total + tax_total + shipping - discount = amount' );
	$t::same( true, (float) $breakdown['discount']['value'] >= 0, 'PayPal: discount is not below zero' );
};

/**
 * The saved order must match what the cart showed: the totals, the fees, and every line.
 * When a line's tax is off only because the order saves tax for one item and multiplies it
 * (a known bug), the label says so.
 */
$combo_check_order = function ( $order, $snap ) use ( $t ) {
	$t::money( $snap['subtotal'], $order->get_subtotal(), 'order subtotal matches the cart' );
	$t::money( $snap['discount'], $order->get_discount(), 'order discount matches the cart' );
	$t::money( $snap['shipping'], $order->get_shipping(), 'order shipping matches the cart' );
	$t::money( $snap['tax'], $order->get_tax(), 'order tax matches the cart' );
	$t::money( $snap['credits'], $order->get_credits(), 'order credits match the cart' );
	$t::money( $snap['total'], $order->get_total(), 'order total matches the cart' );
	$fees = array();
	foreach ( (array) $order->get_fees() as $id => $fee ) {
		if ( abs( (float) $fee['amount'] ) >= 0.005 ) {
			$fees[ $id ] = round( (float) $fee['amount'], 2 );
		}
	}
	$seen_fees = array();
	foreach ( $snap['fees'] as $id => $amount ) {
		$seen_fees[ $id ] = round( (float) $amount, 2 );
	}
	$t::same( $seen_fees, $fees, 'order fees match the cart' );

	// Older installs saved line quantities in a column that stops at 255. The code now widens it
	// on the next version update, which a test site already on this version has not had.
	global $wpdb;
	$qty_column = $wpdb->get_var( "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$wpdb->prefix}sunshine_order_items' AND COLUMN_NAME = 'qty'" );

	$items = array_values( (array) $order->get_items() );
	$t::same( count( $snap['lines'] ), count( $items ), 'number of order lines' );
	foreach ( $snap['lines'] as $i => $line ) {
		if ( empty( $items[ $i ] ) ) {
			continue;
		}
		$item = $items[ $i ];
		$name = 'order line ' . ( $i + 1 );
		if ( 'tinyint' === $qty_column && $line['qty'] > 255 ) {
			$name .= ' [test site: order items table still stops at 255, widened on the next version update]';
		}
		$t::same( $line['qty'], (int) $item->get_qty(), $name . ' quantity' );
		$t::money( $line['price'], $item->get_price(), $name . ' price' );
		$t::money( $line['total'], $item->get_total(), $name . ' total' );
		$t::money( $line['discount'], $item->get_discount_total(), $name . ' discount' );
		$known = '';
		if ( $line['qty'] > 1 && abs( round( $line['tax'], 2 ) - round( $item->get_tax_total(), 2 ) ) > 0.001 && abs( round( round( $line['tax'] / $line['qty'], 2 ) * $line['qty'], 2 ) - round( $item->get_tax_total(), 2 ) ) < 0.001 ) {
			$known = ' [known: saved order lines round tax per print]';
		}
		$t::money( $line['tax'], $item->get_tax_total(), $name . ' tax' . $known );
	}
};

/**
 * One combination: build it, check the cart, check it again in the same request and on the
 * next page load, check what PayPal would get, and place the order.
 */
$combo_run = function ( $recipe ) use ( $t, $combo_build, $combo_snapshot, $combo_check_cart, $combo_same_snapshot, $combo_paypal_args, $combo_check_paypal, $combo_check_order, $combo_env ) {
	$built = $combo_build( $recipe );
	$t::calculate();
	$snap = $combo_snapshot();
	// SPC_COMBO_DUMP=1 prints what the cart shows, for looking into one cart.
	if ( null !== $combo_env( 'SPC_COMBO_DUMP' ) ) {
		WP_CLI::log( wp_json_encode( $snap ) );
	}
	$combo_check_cart( $recipe, $built, $snap );

	$t::calculate();
	$combo_same_snapshot( $snap, $combo_snapshot(), 'worked out again in the same request' );
	$t::new_request();
	$again = $combo_snapshot();
	$combo_same_snapshot( $snap, $again, 'next page load' );

	// The customer presses Update on the cart page without changing anything.
	do_action( 'sunshine_cart_update' );
	$t::new_request();
	$combo_same_snapshot( $snap, $combo_snapshot(), 'after pressing Update on the cart page' );

	if ( $recipe['paypal'] ) {
		$args = $combo_paypal_args();
		if ( null !== $args ) {
			$combo_check_paypal( $args, $again );
		}
	}

	if ( $recipe['order'] ) {
		$t::new_request();
		$seen  = $combo_snapshot();
		$order = $t::place_order();
		$combo_check_order( $order, $seen );
	}

	// The customer takes the last line out of the cart, the way its remove link does. The rules
	// must still hold for what is left.
	if ( $recipe['remove'] && count( $built['specs'] ) > 1 ) {
		$t::new_request();
		$items = SPC()->cart->get_cart();
		end( $items );
		SPC()->cart->remove_item( key( $items ) );
		SPC()->cart->update_cart();
		$t::new_request();
		array_pop( $built['specs'] );
		$combo_check_cart( $recipe, $built, $combo_snapshot() );
	}
};

/*
 * The seeds.
 */

$combo_custom = $combo_env( 'SPC_COMBO_RECIPE' );
if ( null !== $combo_custom ) {
	$combo_recipe_one = $combo_recipe_defaults( (array) json_decode( $combo_custom, true ) );
	$combo_test(
		'Combo by hand: ' . $combo_describe( $combo_recipe_one ),
		function () use ( $combo_run, $combo_recipe_one ) {
			$combo_run( $combo_recipe_one );
		}
	);
} else {
	for ( $combo_seed = $combo_from; $combo_seed <= $combo_to; $combo_seed++ ) {
		$combo_recipe_seed = $combo_recipe_defaults( $combo_recipe( $combo_seed ) );
		$combo_test(
			'Combo seed ' . $combo_seed . ': ' . $combo_describe( $combo_recipe_seed ),
			function () use ( $combo_run, $combo_recipe_seed ) {
				$combo_run( $combo_recipe_seed );
			}
		);
	}
}

/*
 * The smallest carts that broke a rule, worked out by hand. They run with the batch that
 * starts at seed 1.
 */
if ( null === $combo_custom && 1 === $combo_from ) {

	// Each code is worked out on the full price and then they are added up, so two codes can
	// come to more than the items cost. The extra then comes off the shipping.
	$combo_test(
		'Smallest: two percent codes never take more than the items cost',
		function () use ( $t, $combo_build, $combo_recipe_defaults ) {
			$combo_build(
				$combo_recipe_defaults(
					array(
						'lines'    => array( array( 'untaxed print', 100, 1 ) ),
						'code'     => 'two percent codes over 100',
						'shipping' => 'flat 8.95',
					)
				)
			);
			$cart = $t::calculate();
			// 60% + 50% of the 100 print is 110, but there is only 100 to take off.
			$t::money( 100, $cart->get_discount(), 'discount is the whole print and no more' );
			// 100 - 100 + 8.95. Neither code applies to shipping.
			$t::money( 8.95, $cart->get_total(), 'the customer still pays for shipping' );
		}
	);

	$combo_test(
		'Smallest: a percent code and an amount code never take more than the item costs',
		function () use ( $t, $combo_build, $combo_recipe_defaults ) {
			$combo_build(
				$combo_recipe_defaults(
					array(
						'lines' => array( array( 'video', 4.44, 1 ) ),
						'code'  => 'two codes',
					)
				)
			);
			$cart = $t::calculate();
			// 20% of 4.44 is 0.89, and 5 off would be 5.89 in all, but the video costs 4.44.
			$t::money( 4.44, $cart->get_discount(), 'discount is the price of the video and no more' );
			$t::money( 0, $cart->get_total(), 'nothing to pay' );
		}
	);

	// 300 wallet prints is a normal order. The order line must keep all 300. Older installs
	// saved quantities in a column that stops at 255; the code widens it on the next version
	// update, so this fails only on a site whose table has not been updated yet.
	$combo_test(
		'Smallest: an order line keeps a quantity of 300',
		function () use ( $t, $combo_build, $combo_recipe_defaults ) {
			$combo_build( $combo_recipe_defaults( array( 'lines' => array( array( 'untaxed print', 1, 300 ) ) ) ) );
			$t::calculate();
			$order = $t::place_order();
			$items = array_values( (array) $order->get_items() );
			$t::same( 300, $items ? (int) $items[0]->get_qty() : null, 'order line quantity' );
			// 300 x 1.00.
			$t::money( 300, $items ? $items[0]->get_total() : null, 'order line total' );
			$t::money( 300, $order->get_total(), 'order total' );
		}
	);

	// The customer's paid session includes 2 of the 3 prints, so they pay for 1: 12.00 with
	// 20% tax inside it, which is 10.00 plus 2.00 tax.
	$combo_test(
		'Smallest: prices include tax, an order line with included session prints keeps its tax',
		function () use ( $t, $combo_build, $combo_recipe_defaults ) {
			$combo_build(
				$combo_recipe_defaults(
					array(
						'lines' => array( array( 'included print', 12, 3, 1 ) ),
						'tax'   => 20,
						'incl'  => true,
					)
				)
			);
			$cart  = $t::calculate();
			$lines = array_values( (array) $cart->get_cart_items() );
			$t::money( 24, $lines ? $lines[0]->get_discount_total() : null, 'cart line discount is 2 included prints' );
			$t::money( 10, $lines ? $lines[0]->get_total() : null, 'cart line total' );
			$t::money( 2, $lines ? $lines[0]->get_tax_total() : null, 'cart line tax' );
			$order = $t::place_order();
			$items = array_values( (array) $order->get_items() );
			$t::money( 10, $items ? $items[0]->get_total() : null, 'order line total' );
			$t::money( 2, $items ? $items[0]->get_tax_total() : null, 'order line tax' );
			$t::money( 2, $order->get_tax(), 'order tax' );
		}
	);

	// 10% off 3 or more: 1.20 off each 12.00 print. The customer pays 32.40, and the 20% tax
	// inside that is 32.40 / 6 = 5.40, leaving 27.00.
	$combo_test(
		'Smallest: prices include tax, an order line with a quantity discount keeps its tax',
		function () use ( $t, $combo_build, $combo_recipe_defaults ) {
			$combo_build(
				$combo_recipe_defaults(
					array(
						'lines' => array( array( 'quantity discount print', 12, 3, 0 ) ),
						'tax'   => 20,
						'incl'  => true,
					)
				)
			);
			$cart  = $t::calculate();
			$lines = array_values( (array) $cart->get_cart_items() );
			$t::money( 3.60, $lines ? $lines[0]->get_discount_total() : null, 'cart line discount' );
			$t::money( 27, $lines ? $lines[0]->get_total() : null, 'cart line total' );
			$t::money( 5.40, $lines ? $lines[0]->get_tax_total() : null, 'cart line tax' );
			$order = $t::place_order();
			$items = array_values( (array) $order->get_items() );
			$t::money( 27, $items ? $items[0]->get_total() : null, 'order line total' );
			$t::money( 5.40, $items ? $items[0]->get_tax_total() : null, 'order line tax' );
		}
	);

	// The tax inside 300 x 19.99 = 5,997.00 at 6% is 5,997.00 - 5,997.00 / 1.06 = 339.45.
	// Working it out for one print (19.99 - 18.86 = 1.13) and multiplying by 300 gives 339.00.
	$combo_test(
		'Smallest: prices include tax, the tax on 300 prints is the tax inside what is paid',
		function () use ( $t, $combo_build, $combo_recipe_defaults ) {
			$combo_build(
				$combo_recipe_defaults(
					array(
						'lines' => array( array( 'print', 19.99, 300 ) ),
						'tax'   => 6,
						'incl'  => true,
					)
				)
			);
			$cart = $t::calculate();
			$t::money( 5997, $cart->get_total(), 'total is the price tags' );
			$t::money( 339.45, $cart->get_tax(), 'tax inside 5,997.00' );
		}
	);

	// 10% off 3 or more: 3 x 12.99 = 38.97, less 3.897, which is 3.90 to the cent. Two such
	// lines are 35.07 each, and the subtotal is what the two lines show.
	$combo_test(
		'Smallest: a percent quantity discount is rounded to the cent',
		function () use ( $t, $combo_build, $combo_recipe_defaults ) {
			$combo_build(
				$combo_recipe_defaults(
					array(
						'lines' => array(
							array( 'quantity discount print', 12.99, 3, 0 ),
							array( 'quantity discount print', 12.99, 3, 0 ),
						),
					)
				)
			);
			$cart  = $t::calculate();
			$lines = array_values( (array) $cart->get_cart_items() );
			foreach ( $lines as $i => $line ) {
				$discount = (float) $line->get_discount_total();
				$t::same( true, abs( $discount - 3.90 ) < 0.0001, 'line ' . ( $i + 1 ) . ' discount is 3.90 to the cent (got ' . $discount . ')' );
				$t::money( 35.07, $line->get_total(), 'line ' . ( $i + 1 ) . ' total' );
			}
			$t::money( 70.14, $cart->get_subtotal(), 'subtotal is the two lines added up' );
			$t::money( 70.14, $cart->get_total(), 'total' );
		}
	);

	// The Gift Cards docs say a card that covers the order needs no other payment. A fixed card
	// fee is for paying by card, so it can't be what is left to pay.
	$combo_test(
		'Smallest: a gift card that covers the order leaves no card fee to pay',
		function () use ( $t, $combo_build, $combo_recipe_defaults ) {
			$combo_build(
				$combo_recipe_defaults(
					array(
						'lines'     => array( array( 'untaxed print', 50, 1 ) ),
						'gift_card' => 100,
						'fee'       => 'fee 2.50',
					)
				)
			);
			$cart = $t::calculate();
			$t::money( 50, array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) ), 'taken from the card' );
			$t::money( 0, $cart->get_total(), 'nothing left to pay' );
		}
	);

	// The credits docs: when credits cover the order the payment methods are hidden and the
	// order goes through with no other payment. So no card fee, and every page load agrees.
	$combo_test(
		'Smallest: credits that cover the order use the same amount on every page load',
		function () use ( $t, $combo_build, $combo_recipe_defaults ) {
			$combo_build(
				$combo_recipe_defaults(
					array(
						'lines'   => array( array( 'untaxed print', 50, 1 ) ),
						'credits' => 100,
						'fee'     => 'fee 2.50',
					)
				)
			);
			$cart = $t::calculate();
			$t::money( 50, $cart->get_credits_applied(), 'credits used, first look' );
			$t::money( 0, $cart->get_fees_total(), 'no card fee, first look' );
			$t::money( 0, $cart->get_total(), 'nothing to pay, first look' );
			$cart = $t::new_request();
			$t::money( 50, $cart->get_credits_applied(), 'credits used, next page load' );
			$t::money( 0, $cart->get_fees_total(), 'no card fee, next page load' );
			$t::money( 0, $cart->get_total(), 'nothing to pay, next page load' );
		}
	);
}

// Empty the shared galleries' photo lists, so deleting them at the end of the run does not
// queue a background job for their photos.
foreach ( $combo_galleries_made as $combo_gallery_id ) {
	update_post_meta( $combo_gallery_id, 'images', array() );
}
