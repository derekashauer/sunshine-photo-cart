<?php
/**
 * Shared setup for the Digital Downloads case files: download products, galleries with photos,
 * and adding to the cart through the same requests the gallery pages send.
 *
 * Each addon-digital-downloads file loads this with require_once, so any one of them can run
 * alone. Everything here is a closure in a variable, so all case files share one PHP process.
 */

$t = 'SPC_Totals_Test';

// Outgoing requests that a test tried to make. None may leave: they are stopped and noted.
$dl_blocked = array();
$dl_offline = function ( $pre, $args, $url ) use ( &$dl_blocked ) {
	$dl_blocked[] = $url;
	return new WP_Error( 'spc_totals_offline', 'The totals tests never reach outside services' );
};
$dl_no_mail = function () {
	return false;
};

// Galleries made by the current test. Their photo list is emptied when the test ends, so
// deleting the gallery at the end of the run does not queue a background job for its photos.
$dl_galleries = array();

/**
 * Run one Digital Downloads test with outgoing requests and email blocked. Adding to the cart
 * and downloading also write to the Analytics activity log and the automated email queue,
 * which are shared tables, so those listeners are switched off while a test runs. Neither one
 * touches the money math.
 */
$dl_test = function ( $name, $callback ) use ( $t, $dl_offline, $dl_no_mail, &$dl_galleries, &$dl_blocked ) {
	$t::test(
		$name,
		function () use ( $t, $callback, $dl_offline, $dl_no_mail, &$dl_galleries, &$dl_blocked ) {
			$t::require_plugin( 'sunshine_product_is_download', 'Digital Downloads add-on' );
			$dl_galleries = array();
			$dl_blocked   = array();
			add_filter( 'pre_http_request', $dl_offline, PHP_INT_MAX, 3 );
			add_filter( 'pre_wp_mail', $dl_no_mail, PHP_INT_MAX );
			add_filter( 'sunshine_checkout_allow_order_notify', $dl_no_mail, PHP_INT_MAX );
			$quiet = array();
			$hooks = array(
				'sunshine_add_cart_item'         => array( 'sunshine_adv_reports_add_to_cart', 'sunshine_email_queue_after_cart' ),
				'sunshine_cart_update'           => array( 'sunshine_adv_reports_cart_update' ),
				'sunshine_download_credit_image' => array( 'sunshine_customer_track_download_credit_image' ),
			);
			foreach ( $hooks as $hook => $callbacks ) {
				foreach ( $callbacks as $hook_callback ) {
					$priority = has_action( $hook, $hook_callback );
					if ( false !== $priority ) {
						remove_action( $hook, $hook_callback, $priority );
						$quiet[] = array( $hook, $hook_callback, $priority );
					}
				}
			}
			// Proofing mode turns off buying, so make sure it is off for these tests.
			$t::option( 'proofing', '' );
			try {
				$callback();
			} finally {
				remove_filter( 'pre_http_request', $dl_offline, PHP_INT_MAX );
				remove_filter( 'pre_wp_mail', $dl_no_mail, PHP_INT_MAX );
				remove_filter( 'sunshine_checkout_allow_order_notify', $dl_no_mail, PHP_INT_MAX );
				foreach ( $quiet as $hook ) {
					add_action( $hook[0], $hook[1], $hook[2] );
				}
				foreach ( $dl_galleries as $gallery_id ) {
					update_post_meta( $gallery_id, 'images', array() );
				}
				$dl_galleries = array();
				if ( ! empty( $dl_blocked ) ) {
					WP_CLI::warning( 'A Digital Downloads test tried to reach: ' . implode( ', ', array_unique( $dl_blocked ) ) );
				}
			}
		}
	);
};

/**
 * A price level that is not the store's default, or skip the test. Uses a level the store
 * already has rather than making one, because the store treats the first level by name as
 * its default and other tests running at the same time rely on that.
 */
$dl_other_level = function () use ( $t ) {
	$default = sunshine_get_default_price_level_id();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		if ( $level && $level->get_id() != $default ) {
			return (int) $level->get_id();
		}
	}
	$t::skip( 'The store has only one price level' );
};

/**
 * A product saved the way the product edit screen saves it: every field on the screen is
 * stored and a field left empty is stored as an empty string. Prices are stored for every
 * price level, blank where the product is not sold. $price is one price for the default
 * level, or price level id => price.
 */
$dl_save_product = function ( $type, $price, $fields = array(), $name = '' ) use ( $t ) {
	$prices = is_array( $price ) ? $price : array( sunshine_get_default_price_level_id() => $price );
	$id     = wp_insert_post(
		array(
			'post_type'   => 'sunshine-product',
			'post_status' => 'publish',
			'post_title'  => $name ? $name : 'Downloads test ' . $type,
		)
	);
	if ( ! $id || is_wp_error( $id ) ) {
		throw new Exception( 'Could not make a product' );
	}
	$t::track( 'post', $id );
	$all_prices   = array();
	$all_shipping = array();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		$all_prices[ $level->get_id() ]   = isset( $prices[ $level->get_id() ] ) ? (string) $prices[ $level->get_id() ] : '';
		$all_shipping[ $level->get_id() ] = '';
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
	foreach ( array_merge( $meta, $fields ) as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
};

// A download of one photo. $fields changes any saved field, e.g. 'taxable' => ''.
$dl_product = function ( $price, $fields = array() ) use ( $dl_save_product ) {
	return $dl_save_product( 'download', $price, $fields, 'Downloads test digital file' );
};

// The "Entire gallery download" product: one zip of every photo in the gallery.
$dl_gallery_product = function ( $price, $fields = array() ) use ( $dl_save_product ) {
	return $dl_save_product( 'download', $price, array_merge( array( 'gallery_download' => '1' ), $fields ), 'Downloads test full gallery' );
};

$dl_print = function ( $price, $fields = array() ) use ( $dl_save_product ) {
	return $dl_save_product( 'print', $price, $fields, 'Downloads test 8x10 print' );
};

/**
 * A published gallery anyone can buy from, with photos, saved the way the gallery edit screen
 * saves it, including the Downloads tab with nothing turned on. $meta changes any field.
 * Returns array( 'id' => gallery id, 'photos' => photo ids ).
 */
$dl_gallery = function ( $photo_count = 3, $price_level = '', $meta = array() ) use ( $t, &$dl_galleries ) {
	$gallery_id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-gallery',
			'post_status' => 'publish',
			'post_title'  => 'Downloads test gallery',
		)
	);
	if ( ! $gallery_id || is_wp_error( $gallery_id ) ) {
		throw new Exception( 'Could not make a gallery' );
	}
	$t::track( 'post', $gallery_id );
	$dl_galleries[] = $gallery_id;
	$fields         = array(
		'status'                    => 'default',
		'access_type'               => '',
		'end_date'                  => '',
		'disable_products'          => '',
		'price_level'               => (string) $price_level,
		// Downloads tab: no free downloads, no limits.
		'free_downloads'            => '',
		'free_image_download_count' => '',
		'download_users'            => array(),
		'free_download_email'       => '',
		'free_download_passcode'    => '',
		// Mark it as already announced, so nothing emails customers about a test gallery.
		'_sunshine_gallery_ready'   => time(),
	);
	foreach ( array_merge( $fields, $meta ) as $key => $value ) {
		update_post_meta( $gallery_id, $key, $value );
	}
	$photo_ids = array();
	for ( $i = 1; $i <= $photo_count; $i++ ) {
		$photo_id = wp_insert_attachment(
			array(
				'post_title'     => 'Downloads photo ' . $i,
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
		update_post_meta( $photo_id, 'sunshine_file_name', 'downloads-photo-' . $i . '.jpg' );
		update_post_meta( $photo_id, 'sunshine_watermark', 0 );
		$photo_ids[] = $photo_id;
	}
	update_post_meta( $gallery_id, 'images', $photo_ids );
	return array(
		'id'     => $gallery_id,
		'photos' => $photo_ids,
	);
};

/**
 * Run one of the store's ajax handlers with these posted values and return its JSON reply.
 */
$dl_ajax = function ( $handler, $post, $nonce = 'sunshinephotocart' ) {
	$saved_post = $_POST;
	$_POST      = $post;
	if ( $nonce ) {
		$_POST['security'] = wp_create_nonce( $nonce );
	}
	$ajax = '__return_true';
	$die  = function () {
		return function () {
			throw new SPC_Totals_Test_Done();
		};
	};
	add_filter( 'wp_doing_ajax', $ajax );
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

// "Add to cart" from a photo, the way the photo's add to cart window sends it.
$dl_add_photo = function ( $product_id, $photo_id, $qty = 1 ) use ( $dl_ajax ) {
	return $dl_ajax(
		'sunshine_modal_add_item_to_cart',
		array(
			'product_id' => $product_id,
			'image_id'   => $photo_id,
			'gallery_id' => sunshine_get_image( $photo_id )->get_gallery_id(),
			'qty'        => $qty,
		)
	);
};

// The "Add to cart" button in a gallery's full gallery download window. It sends no nonce.
$dl_add_gallery = function ( $product_id, $gallery_id ) use ( $dl_ajax ) {
	return $dl_ajax(
		'sunshine_modal_gallery_add_to_cart',
		array(
			'gallery_id' => $gallery_id,
			'product_id' => $product_id,
		),
		''
	);
};

// The cart lines right now, as a plain list.
$dl_lines = function () {
	$items = SPC()->cart->get_cart_items();
	return $items ? array_values( $items ) : array();
};

/**
 * Log in as a new customer and give them download credits, saved the way the Download Credits
 * box on the admin customer screen saves them.
 */
$dl_customer_with_credits = function ( $credits ) use ( $t ) {
	$customer_id = $t::customer();
	sunshine_download_customer_credits_save( array( 'download_credits' => (string) $credits ), SPC()->customer );
	SPC()->customer = new SPC_Customer( $customer_id );
	return $customer_id;
};

$dl_download_credits = function ( $customer_id ) {
	return (int) get_user_meta( $customer_id, SPC()->prefix . 'download_credits', true );
};

/**
 * Run some code and return the PHP warnings, notices and deprecations it raised.
 */
$dl_warnings = function ( $callback ) {
	$warnings = array();
	set_error_handler(
		function ( $errno, $errstr, $errfile, $errline ) use ( &$warnings ) {
			if ( error_reporting() & $errno ) {
				$warnings[] = $errstr . ' (' . basename( $errfile ) . ':' . $errline . ')';
			}
			return false;
		}
	);
	try {
		$callback();
	} finally {
		restore_error_handler();
	}
	return $warnings;
};
