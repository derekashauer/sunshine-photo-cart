<?php
/**
 * Helpers for the admin-bulk-edit case files: Quick Edit and Bulk Edit on the Products and
 * Galleries lists in wp-admin (Sunshine_Admin_Quick_Bulk_Edit).
 *
 * This file only sets up helpers. The tests are in admin-bulk-edit-products.php and
 * admin-bulk-edit-galleries.php. Run them with SPC_TOTALS_ONLY=admin-bulk-edit.
 *
 * How a save is made here, step by step, the way the browser and WordPress do it:
 * 1. Sunshine's own boxes are taken from the real markup the list screen prints
 *    (render_quick_edit_box() / render_bulk_edit_box()), not typed by hand.
 * 2. For Quick Edit, the boxes are filled from the hidden values Sunshine prints in each list
 *    row (render_anchor_column()), the same way admin-quick-bulk-edit.js fills them. Bulk Edit
 *    boxes start empty ("No change"), as on screen.
 * 3. The photographer's typing is applied, then the form is sent the way a browser sends it
 *    (unchecked boxes left out, the last of two boxes with the same name wins) and turned into
 *    $_POST / $_REQUEST by PHP's own rules, with the slashes WordPress adds.
 * 4. WordPress's own handler runs: wp_ajax_inline_save() for Quick Edit and bulk_edit_posts()
 *    for Bulk Edit. They save the post, which fires save_post, where Sunshine's save() runs.
 *    WordPress's own boxes (title, date, status, categories) are written out here from the
 *    WordPress 7.1 list table, since they are not Sunshine's.
 *
 * The admin code only loads in wp-admin, so it is loaded here once and every hook it adds is
 * taken back off, so the other case files run the same as before. Sunshine's save() is hooked
 * back onto save_post (priority 20, as in wp-admin) only while one save runs.
 *
 * Nothing leaves the site: every web request is refused while a test runs.
 */

$t = 'SPC_Totals_Test';

// Web requests a test tried to send. All of them are refused.
$abe_sent = array();

$abe_http = function ( $pre, $args, $url ) use ( &$abe_sent ) {
	$abe_sent[] = $url;
	return new WP_Error( 'spc_totals_offline', 'Web requests are blocked while the totals tests run' );
};

// PHP warnings, notices and deprecations raised while the photographer saved, in this test.
$abe_warnings = array();

/**
 * One admin-bulk-edit test. Same as $t::test(), with web requests refused and the request
 * data the photographer sent cleared afterwards.
 */
$abe_test = function ( $name, $callback ) use ( $t, $abe_http, &$abe_sent, &$abe_warnings ) {
	$t::test(
		$name,
		function () use ( $t, $callback, $abe_http, &$abe_sent, &$abe_warnings ) {
			$abe_sent     = array();
			$abe_warnings = array();
			add_filter( 'pre_http_request', $abe_http, PHP_INT_MAX, 3 );
			try {
				$callback();
				$t::same( array(), $abe_warnings, 'no PHP warnings while saving' );
				$t::same( array(), $abe_sent, 'no web requests tried to leave' );
			} finally {
				remove_filter( 'pre_http_request', $abe_http, PHP_INT_MAX );
				foreach ( array( '_GET', '_POST', '_REQUEST' ) as $global ) {
					foreach ( array_keys( $GLOBALS[ $global ] ) as $key ) {
						unset( $GLOBALS[ $global ][ $key ] );
					}
				}
			}
		}
	);
};

/*
 * The admin code.
 */

// Load the product and gallery edit screen code and the Quick/Bulk Edit code, then take off every
// hook they added. Returns a Quick/Bulk Edit object made without its constructor (no hooks).
$abe_admin = function () {
	static $qbe = null;
	if ( null !== $qbe ) {
		return $qbe;
	}
	global $wp_filter;
	$before = array();
	foreach ( $wp_filter as $hook => $hook_object ) {
		foreach ( $hook_object->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback ) {
				$before[ $hook ][ $priority ][ $id ] = true;
			}
		}
	}
	if ( empty( $GLOBALS['sunshine_admin_meta_boxes_product'] ) ) {
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/sunshine-product.php';
	}
	if ( empty( $GLOBALS['sunshine_admin_meta_boxes_gallery'] ) ) {
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/sunshine-gallery.php';
	}
	if ( ! class_exists( 'Sunshine_Admin_Quick_Bulk_Edit' ) ) {
		include_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/class-admin-quick-bulk-edit.php';
	}
	// WordPress's own Quick Edit and Bulk Edit handlers. These files only define functions.
	if ( ! function_exists( 'wp_ajax_inline_save' ) ) {
		require_once ABSPATH . 'wp-admin/includes/ajax-actions.php';
	}
	if ( ! function_exists( 'bulk_edit_posts' ) ) {
		require_once ABSPATH . 'wp-admin/includes/post.php';
	}
	foreach ( $wp_filter as $hook => $hook_object ) {
		foreach ( $hook_object->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback ) {
				if ( ! isset( $before[ $hook ][ $priority ][ $id ] ) ) {
					remove_filter( $hook, $callback['function'], $priority );
				}
			}
		}
	}
	$qbe = ( new ReflectionClass( 'Sunshine_Admin_Quick_Bulk_Edit' ) )->newInstanceWithoutConstructor();
	return $qbe;
};

// The photographer's login: an administrator account, made once and deleted at the end of the run.
$abe_photographer_id = array( 'id' => 0 );

$abe_photographer = function () use ( $t, &$abe_photographer_id ) {
	if ( $abe_photographer_id['id'] && get_userdata( $abe_photographer_id['id'] ) ) {
		return $abe_photographer_id['id'];
	}
	$id = wp_insert_user(
		array(
			'user_login' => 'spcbulkedit' . wp_rand( 100000, 999999 ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'administrator',
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new Exception( 'Could not make the photographer account: ' . $id->get_error_message() );
	}
	$t::track( 'user', $id );
	$abe_photographer_id['id'] = $id;
	return $id;
};

// Session values the customer's checkout keeps. The photographer has a session of their own.
$abe_session_keys = array( 'cart', 'discounts', 'checkout_data', 'checkout_sections_completed', 'gift_cards', 'gift_cards_applied', 'gift_card_amounts', 'use_credits', 'checkout_order_id', 'paypal_order_id' );

/**
 * Run $callback as the photographer in wp-admin. PHP warnings it raises are kept for the test to
 * check. The customer's login, session and cart are put back afterwards.
 */
$abe_as_photographer = function ( $callback ) use ( $abe_photographer, $abe_session_keys, &$abe_warnings ) {
	$user_id = get_current_user_id();
	$saved   = array();
	foreach ( $abe_session_keys as $key ) {
		$saved[ $key ] = SPC()->session->get( $key );
		SPC()->session->set( $key, '' );
	}
	wp_set_current_user( $abe_photographer() );
	SPC()->customer = new SPC_Customer( get_current_user_id() );
	SPC()->cart     = new SPC_Cart();
	set_error_handler(
		function ( $errno, $errstr, $errfile, $errline ) use ( &$abe_warnings ) {
			if ( error_reporting() & $errno ) {
				$abe_warnings[] = $errstr . ' (' . basename( $errfile ) . ':' . $errline . ')';
			}
			return false;
		}
	);
	try {
		return $callback();
	} finally {
		restore_error_handler();
		foreach ( $saved as $key => $value ) {
			SPC()->session->set( $key, $value );
		}
		wp_set_current_user( $user_id );
		SPC()->customer = new SPC_Customer( $user_id );
		SPC()->cart     = new SPC_Cart();
	}
};

/*
 * Store data, saved the way the full edit screens save it.
 */

/**
 * A new price level. Named to sort after the store's own levels, because the store
 * treats the first level by name as its default and other tests rely on that.
 */
$abe_level = function ( $label ) use ( $t ) {
	// A price level created while these are posted copies prices onto every product in the store.
	$saved_post = $_POST;
	unset( $_POST['price_level'], $_POST['percent'] );
	$term  = wp_insert_term( 'Zz Totals bulk ' . $label . ' ' . wp_rand( 100000, 999999 ), 'sunshine-product-price-level' );
	$_POST = $saved_post;
	if ( is_wp_error( $term ) ) {
		throw new Exception( 'Could not create a price level: ' . $term->get_error_message() );
	}
	$t::track( 'term', $term['term_id'], 'sunshine-product-price-level' );
	return (int) $term['term_id'];
};

// A price field posts one box for every price level in the store. Empty for the levels not typed.
$abe_all_levels = function ( $typed ) {
	$all = array();
	foreach ( (array) sunshine_get_price_levels() as $level ) {
		if ( $level ) {
			$all[ $level->get_id() ] = '';
		}
	}
	foreach ( (array) $typed as $level_id => $value ) {
		$all[ $level_id ] = (string) $value;
	}
	return $all;
};

/**
 * A product saved the way the product edit screen saves it (Sunshine_Admin_Meta_Boxes::
 * save_meta_boxes()): every core field of the Product Info box goes through the field type's
 * clean-up and its _validate filter, and a field that was not sent is saved empty.
 * $fields: price and shipping are level => typed text; taxable and disable_shipping are true or
 * false (a ticked box sends "1", an unticked box sends nothing); type, min_qty and max_qty as typed.
 */
$abe_product = function ( $fields, $name = 'Bulk edit test product' ) use ( $t, $abe_admin, $abe_all_levels ) {
	$abe_admin();
	$box = $GLOBALS['sunshine_admin_meta_boxes_product'];
	$id  = wp_insert_post(
		array(
			'post_type'   => 'sunshine-product',
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);
	if ( ! $id || is_wp_error( $id ) ) {
		throw new Exception( 'Could not create a product' );
	}
	$t::track( 'post', $id );
	$fields = wp_parse_args(
		$fields,
		array(
			'type'             => 'print',
			'price'            => array(),
			'taxable'          => true,
			'disable_shipping' => false,
			'shipping'         => array(),
			'min_qty'          => '',
			'max_qty'          => '',
		)
	);
	$posted = array(
		'type'     => $fields['type'],
		'price'    => $abe_all_levels( $fields['price'] ),
		'shipping' => $abe_all_levels( $fields['shipping'] ),
		'min_qty'  => (string) $fields['min_qty'],
		'max_qty'  => (string) $fields['max_qty'],
	);
	if ( $fields['taxable'] ) {
		$posted['taxable'] = '1';
	}
	if ( $fields['disable_shipping'] ) {
		$posted['disable_shipping'] = '1';
	}
	foreach ( $box->set_options( array() ) as $tabs ) {
		foreach ( $tabs as $tab ) {
			foreach ( $tab['fields'] as $field ) {
				$value = array_key_exists( $field['id'], $posted ) ? $posted[ $field['id'] ] : '';
				$value = $box->sanitize( $value, $field['type'] );
				$value = apply_filters( 'sunshine_meta_' . $field['id'] . '_validate', $value );
				delete_post_meta( $id, $field['id'] );
				update_post_meta( $id, $field['id'], $value );
			}
		}
	}
	return $id;
};

/**
 * A published gallery that anyone can buy from, saved the way the gallery edit screen saves it.
 */
$abe_gallery = function ( $price_level = '', $name = 'Bulk edit test gallery' ) use ( $t ) {
	$t::option( 'proofing', '' );
	$gallery_id = wp_insert_post(
		array(
			'post_type'   => 'sunshine-gallery',
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);
	if ( ! $gallery_id || is_wp_error( $gallery_id ) ) {
		throw new Exception( 'Could not create a gallery' );
	}
	$t::track( 'post', $gallery_id );
	update_post_meta( $gallery_id, 'status', 'default' );
	update_post_meta( $gallery_id, 'access_type', '' );
	update_post_meta( $gallery_id, 'disable_products', '' );
	update_post_meta( $gallery_id, 'price_level', (string) $price_level );
	return $gallery_id;
};

/**
 * A photo in a gallery, like an upload: an attachment whose parent is the gallery, listed in
 * the gallery's images.
 */
$abe_photo = function ( $gallery_id ) use ( $t ) {
	$name     = 'IMG_' . wp_rand( 1000, 9999 );
	$image_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $name,
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		'',
		$gallery_id
	);
	if ( ! $image_id || is_wp_error( $image_id ) ) {
		throw new Exception( 'Could not create a photo' );
	}
	$t::track( 'post', $image_id );
	add_post_meta( $image_id, 'sunshine_file_name', $name . '.jpg' );
	add_post_meta( $image_id, 'sunshine_watermark', 0 );
	sunshine_get_gallery( $gallery_id )->add_image_id( $image_id );
	return $image_id;
};

/**
 * Add a product for a photo the way the store's add to cart window does, and return its reply.
 * The price level is worked out by the store from the photo's gallery.
 */
$abe_add_photo = function ( $product_id, $image_id, $qty = 1 ) {
	$image      = sunshine_get_image( $image_id );
	$saved_post = $_POST;
	$_POST      = array(
		'product_id' => $product_id,
		'image_id'   => $image_id,
		'gallery_id' => $image->get_gallery_id(),
		'qty'        => $qty,
		'security'   => wp_create_nonce( 'sunshinephotocart' ),
	);
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
		sunshine_modal_add_item_to_cart();
	} catch ( SPC_Totals_Test_Done $e ) {
		// The handler ends by sending JSON.
	} finally {
		$output = ob_get_clean();
		$_POST  = $saved_post;
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );
	}
	$response = json_decode( $output, true );
	return is_array( $response ) ? $response : array(
		'success' => false,
		'raw'     => substr( $output, 0, 300 ),
	);
};

// A store that writes money with a decimal comma: 12,50 and 1.250,00.
$abe_comma_store = function () use ( $t ) {
	$t::option( 'currency', 'EUR' );
	$t::option( 'currency_thousands_separator', '.' );
	$t::option( 'currency_decimal_separator', ',' );
};

// One saved price box of a product: $field is price or shipping.
$abe_saved = function ( $product_id, $field, $level_id ) {
	$saved = get_post_meta( $product_id, $field, true );
	return ( is_array( $saved ) && array_key_exists( $level_id, $saved ) ) ? $saved[ $level_id ] : null;
};

/*
 * The Quick Edit and Bulk Edit forms.
 */

// Every form box in a piece of markup, in page order: inputs with their value and ticked state,
// selects with their options and which are chosen (the first, when none is marked, as a browser does).
$abe_controls = function ( $html ) {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
	libxml_clear_errors();
	$xpath    = new DOMXPath( $dom );
	$controls = array();
	foreach ( $xpath->query( '//input|//select|//textarea' ) as $node ) {
		$name = $node->getAttribute( 'name' );
		if ( '' === $name ) {
			continue;
		}
		if ( 'select' === $node->nodeName ) {
			$options  = array();
			$selected = array();
			foreach ( $xpath->query( './/option', $node ) as $option ) {
				$value     = $option->hasAttribute( 'value' ) ? $option->getAttribute( 'value' ) : $option->textContent;
				$options[] = $value;
				if ( $option->hasAttribute( 'selected' ) ) {
					$selected[] = $value;
				}
			}
			$multiple = $node->hasAttribute( 'multiple' );
			if ( ! $selected && ! $multiple && $options ) {
				$selected = array( $options[0] );
			}
			$controls[] = array(
				'kind'     => 'select',
				'name'     => $name,
				'multiple' => $multiple,
				'options'  => $options,
				'selected' => $selected,
			);
		} else {
			$type       = 'textarea' === $node->nodeName ? 'textarea' : strtolower( $node->getAttribute( 'type' ) ? $node->getAttribute( 'type' ) : 'text' );
			$controls[] = array(
				'kind'    => 'input',
				'type'    => $type,
				'name'    => $name,
				'value'   => 'textarea' === $type ? $node->textContent : $node->getAttribute( 'value' ),
				'checked' => $node->hasAttribute( 'checked' ),
			);
		}
	}
	return $controls;
};

// Set one box the way jQuery's .val() and .prop( 'checked' ) do. A number box drops a value
// that is not a number, and a select with no matching option ends up with nothing chosen.
$abe_set = function ( &$controls, $name, $value ) {
	$found = false;
	foreach ( $controls as $i => $control ) {
		if ( $control['name'] === $name && 'input' === $control['kind'] && 'checkbox' === $control['type'] ) {
			$controls[ $i ]['checked'] = (bool) $value;
			$found                     = true;
		}
	}
	if ( $found ) {
		return true;
	}
	foreach ( $controls as $i => $control ) {
		if ( $control['name'] !== $name ) {
			continue;
		}
		$found = true;
		if ( 'select' === $control['kind'] ) {
			$values                     = array_map( 'strval', (array) $value );
			$controls[ $i ]['selected'] = array_values( array_intersect( $control['options'], $values ) );
			if ( $control['multiple'] ) {
				// Customers picked in the search box are added as options.
				$controls[ $i ]['options']  = array_values( array_unique( array_merge( $control['options'], $values ) ) );
				$controls[ $i ]['selected'] = $values;
			}
		} elseif ( 'number' === $control['type'] && '' !== (string) $value && ! is_numeric( $value ) ) {
			$controls[ $i ]['value'] = '';
		} else {
			$controls[ $i ]['value'] = (string) $value;
		}
	}
	return $found;
};

// Fill the Quick Edit boxes from the hidden values Sunshine prints in the post's list row, the
// way populateFromRow() / populateField() in admin-quick-bulk-edit.js do.
$abe_fill_from_row = function ( &$controls, $post_id ) use ( $abe_admin, $abe_set ) {
	ob_start();
	$abe_admin()->render_anchor_column( Sunshine_Admin_Quick_Bulk_Edit::ANCHOR_COLUMN, $post_id );
	$html = ob_get_clean();
	if ( ! preg_match_all( '/<span ([^>]*)><\/span>/', $html, $spans ) ) {
		return;
	}
	foreach ( $spans[1] as $attributes ) {
		preg_match_all( '/(data-[a-z]+)="([^"]*)"/', $attributes, $pairs, PREG_SET_ORDER );
		$data = array();
		foreach ( $pairs as $pair ) {
			$data[ $pair[1] ] = html_entity_decode( $pair[2], ENT_QUOTES, 'UTF-8' );
		}
		if ( empty( $data['data-field'] ) ) {
			continue;
		}
		if ( isset( $data['data-users'] ) ) {
			$users = json_decode( $data['data-users'], true );
			$abe_set( $controls, $data['data-field'] . '[]', wp_list_pluck( (array) $users, 'id' ) );
			continue;
		}
		$value = isset( $data['data-value'] ) ? $data['data-value'] : '';
		$is_checkbox = false;
		foreach ( $controls as $control ) {
			if ( $control['name'] === $data['data-field'] && 'input' === $control['kind'] && 'checkbox' === $control['type'] ) {
				$is_checkbox = true;
			}
		}
		$abe_set( $controls, $data['data-field'], $is_checkbox ? ( '1' === $value ) : $value );
	}
};

// What the browser sends for these boxes: name => value pairs in page order. An unticked box and
// a select with nothing chosen send nothing.
$abe_pairs = function ( $controls ) {
	$pairs = array();
	foreach ( $controls as $control ) {
		if ( 'select' === $control['kind'] ) {
			foreach ( $control['selected'] as $value ) {
				$pairs[] = array( $control['name'], $value );
			}
		} elseif ( in_array( $control['type'], array( 'checkbox', 'radio' ), true ) ) {
			if ( $control['checked'] ) {
				$pairs[] = array( $control['name'], '' === $control['value'] ? 'on' : $control['value'] );
			}
		} elseif ( ! in_array( $control['type'], array( 'submit', 'button', 'reset', 'image', 'file' ), true ) ) {
			$pairs[] = array( $control['name'], $control['value'] );
		}
	}
	return $pairs;
};

// Turn the sent pairs into what PHP gives WordPress: arrays for names like price[12], the last
// value for a name sent twice, and the slashes WordPress adds to all request data.
$abe_request = function ( $pairs ) {
	$parts = array();
	foreach ( $pairs as $pair ) {
		$parts[] = urlencode( $pair[0] ) . '=' . urlencode( (string) $pair[1] );
	}
	$data = array();
	parse_str( implode( '&', $parts ), $data );
	return wp_slash( $data );
};

// Sunshine's boxes in the Quick Edit or Bulk Edit panel for a post type, as printed.
$abe_sunshine_boxes = function ( $mode, $post_type ) use ( $abe_admin ) {
	$qbe = $abe_admin();
	ob_start();
	if ( 'quick' === $mode ) {
		$qbe->render_quick_edit_box( Sunshine_Admin_Quick_Bulk_Edit::ANCHOR_COLUMN, $post_type );
	} else {
		$qbe->render_bulk_edit_box( Sunshine_Admin_Quick_Bulk_Edit::ANCHOR_COLUMN, $post_type );
	}
	return ob_get_clean();
};

// Apply what the photographer typed: name => value. A tick box takes true or false.
$abe_type_in = function ( &$controls, $typed ) use ( $abe_set ) {
	foreach ( $typed as $name => $value ) {
		if ( ! $abe_set( $controls, $name, $value ) ) {
			throw new Exception( 'The form has no box named ' . $name );
		}
	}
};

// Run Sunshine's save() on save_post, as wp-admin hooks it, while $callback runs.
$abe_with_save_hook = function ( $callback ) use ( $abe_admin ) {
	$save = array( $abe_admin(), 'save' );
	add_action( 'save_post', $save, 20, 2 );
	try {
		return $callback();
	} finally {
		remove_action( 'save_post', $save, 20 );
	}
};

/**
 * Quick Edit one product or gallery and press Update. $typed is what the photographer changes in
 * Sunshine's boxes (name => value; tick boxes take true or false). $title changes the title, as
 * WordPress's own Title box. Returns the raw reply of the ajax request.
 */
$abe_quick_edit = function ( $post_id, $typed = array(), $title = null ) use ( $abe_as_photographer, $abe_sunshine_boxes, $abe_controls, $abe_fill_from_row, $abe_type_in, $abe_pairs, $abe_request, $abe_with_save_hook ) {
	return $abe_as_photographer(
		function () use ( $post_id, $typed, $title, $abe_sunshine_boxes, $abe_controls, $abe_fill_from_row, $abe_type_in, $abe_pairs, $abe_request, $abe_with_save_hook ) {
			$post = get_post( $post_id );
			$time = strtotime( $post->post_date );

			// WordPress's own Quick Edit boxes, filled from the row the way inline-edit-post.js does.
			$pairs = array(
				array( 'post_title', null === $title ? $post->post_title : $title ),
				array( 'post_name', $post->post_name ),
				array( 'mm', gmdate( 'm', $time ) ),
				array( 'jj', gmdate( 'd', $time ) ),
				array( 'aa', gmdate( 'Y', $time ) ),
				array( 'hh', gmdate( 'H', $time ) ),
				array( 'mn', gmdate( 'i', $time ) ),
				array( 'ss', gmdate( 's', $time ) ),
				array( 'post_password', $post->post_password ),
			);
			if ( is_post_type_hierarchical( $post->post_type ) ) {
				$pairs[] = array( 'post_parent', (string) $post->post_parent );
			}
			$pairs[] = array( 'menu_order', (string) $post->menu_order );
			foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
				if ( $taxonomy->hierarchical && $taxonomy->show_in_quick_edit ) {
					$pairs[] = array( 'tax_input[' . $taxonomy->name . '][]', '0' );
					foreach ( wp_get_object_terms( $post_id, $taxonomy->name, array( 'fields' => 'ids' ) ) as $term_id ) {
						$pairs[] = array( 'tax_input[' . $taxonomy->name . '][]', (string) $term_id );
					}
				}
			}
			$pairs[] = array( '_status', $post->post_status );

			// Sunshine's boxes, from the real markup, filled from the row, then typed in.
			$controls = $abe_controls( $abe_sunshine_boxes( 'quick', $post->post_type ) );
			$abe_fill_from_row( $controls, $post_id );
			$abe_type_in( $controls, $typed );
			$pairs = array_merge( $pairs, $abe_pairs( $controls ) );

			// The rest of the panel, then what inline-edit-post.js adds when it sends the form.
			$pairs[] = array( '_inline_edit', wp_create_nonce( 'inlineeditnonce' ) );
			$pairs[] = array( 'screen', 'edit-' . $post->post_type );
			$pairs[] = array( 'post_view', 'list' );
			$pairs[] = array( 'action', 'inline-save' );
			$pairs[] = array( 'post_type', $post->post_type );
			$pairs[] = array( 'post_ID', (string) $post_id );
			$pairs[] = array( 'edit_date', 'true' );
			$pairs[] = array( 'post_status', $post->post_status );

			$_POST    = $abe_request( $pairs );
			$_GET     = array();
			$_REQUEST = $_POST;

			$ajax = '__return_true';
			$die  = function () {
				return function () {
					throw new SPC_Totals_Test_Done();
				};
			};
			add_filter( 'wp_doing_ajax', $ajax );
			add_filter( 'wp_die_ajax_handler', $die );
			ob_start();
			try {
				$abe_with_save_hook( 'wp_ajax_inline_save' );
			} catch ( SPC_Totals_Test_Done $e ) {
				// wp_ajax_inline_save() ends with wp_die() after printing the updated row.
			} finally {
				$output = ob_get_clean();
				remove_filter( 'wp_doing_ajax', $ajax );
				remove_filter( 'wp_die_ajax_handler', $die );
				$_POST    = array();
				$_REQUEST = array();
			}
			clean_post_cache( $post_id );
			return $output;
		}
	);
};

/**
 * Tick these posts on the list screen, choose Bulk actions > Edit, change Sunshine's boxes and
 * press Update. $typed is name => value for Sunshine's boxes; boxes not named stay on
 * "No change" (empty). Returns what bulk_edit_posts() reports.
 */
$abe_bulk_edit = function ( $post_ids, $typed = array() ) use ( $abe_as_photographer, $abe_sunshine_boxes, $abe_controls, $abe_type_in, $abe_pairs, $abe_request, $abe_with_save_hook ) {
	return $abe_as_photographer(
		function () use ( $post_ids, $typed, $abe_sunshine_boxes, $abe_controls, $abe_type_in, $abe_pairs, $abe_request, $abe_with_save_hook ) {
			$post_type = get_post_type( reset( $post_ids ) );

			// The list screen's form sends its filters first (the bulk edit panel is moved into the
			// table, below them). The Products list has its own Category and Type filters.
			$pairs = array(
				array( 's', '' ),
				array( 'post_status', 'all' ),
				array( 'post_type', $post_type ),
				array( '_wpnonce', wp_create_nonce( 'bulk-posts' ) ),
				array( '_wp_http_referer', '/wp-admin/edit.php?post_type=' . $post_type ),
				array( 'action', 'edit' ),
			);
			if ( 'sunshine-product' === $post_type ) {
				$pairs[] = array( 'category', '' );
				$pairs[] = array( 'type', '' );
			}
			$pairs[] = array( 'paged', '1' );

			// The bulk edit panel: WordPress's boxes left on "No change", then Sunshine's.
			foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
				if ( $taxonomy->hierarchical && $taxonomy->show_in_quick_edit ) {
					$pairs[] = array( 'tax_input[' . $taxonomy->name . '][]', '0' );
				}
			}
			$pairs[]  = array( '_status', '-1' );
			$controls = $abe_controls( $abe_sunshine_boxes( 'bulk', $post_type ) );
			$abe_type_in( $controls, $typed );
			$pairs   = array_merge( $pairs, $abe_pairs( $controls ) );
			$pairs[] = array( 'post_view', 'list' );
			$pairs[] = array( 'screen', 'edit-' . $post_type );
			$pairs[] = array( 'bulk_edit', 'Update' );
			foreach ( $post_ids as $post_id ) {
				$pairs[] = array( 'post[]', (string) $post_id );
			}
			$pairs[] = array( 'action2', '-1' );

			// The list screen's form is sent with GET. wp-admin/edit.php passes $_REQUEST on.
			$_GET     = $abe_request( $pairs );
			$_POST    = array();
			$_REQUEST = $_GET;
			try {
				$done = $abe_with_save_hook(
					function () {
						return bulk_edit_posts( $_REQUEST );
					}
				);
			} finally {
				$_GET     = array();
				$_REQUEST = array();
			}
			foreach ( $post_ids as $post_id ) {
				clean_post_cache( $post_id );
			}
			return $done;
		}
	);
};
