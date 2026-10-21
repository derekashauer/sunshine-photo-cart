<?php
/**
 * Quick Edit and Bulk Edit on the Galleries list (Sunshine > Galleries).
 *
 * The money settings here are the gallery's Price Level (which prices its photos are sold at) and
 * Disable Products. Bulk Edit should only change the boxes the photographer fills in, on every
 * ticked gallery. Quick Edit saves every box again, filled with what is saved.
 */

require_once __DIR__ . '/admin-bulk-edit-helpers.php';

$t = 'SPC_Totals_Test';

// A product sold at the default level for 10 and at the test's own level for 25.
$abe_gal_product = function ( $level ) use ( $abe_product ) {
	return $abe_product(
		array(
			'price'   => array(
				sunshine_get_default_price_level_id() => '10',
				$level                                => '25',
			),
			'taxable' => false,
		)
	);
};

// Add the product for a new photo in each gallery, one each. Returns how many were added.
$abe_gal_buy = function ( $product_id, $gallery_ids ) use ( $abe_photo, $abe_add_photo ) {
	$added = 0;
	foreach ( $gallery_ids as $gallery_id ) {
		$reply = $abe_add_photo( $product_id, $abe_photo( $gallery_id ), 1 );
		if ( ! empty( $reply['success'] ) ) {
			$added++;
		}
	}
	return $added;
};

$abe_test(
	'Bulk Edit galleries: every box left on "No change" keeps each gallery\'s price level',
	function () use ( $t, $abe_level, $abe_gallery, $abe_bulk_edit, $abe_gal_product, $abe_gal_buy ) {
		$default  = sunshine_get_default_price_level_id();
		$level    = $abe_level( 'gallery keep' );
		$print    = $abe_gal_product( $level );
		$family   = $abe_gallery( $default );
		$wedding  = $abe_gallery( $level );

		$done = $abe_bulk_edit( array( $family, $wedding ) );
		$t::same( array( $family, $wedding ), $done['updated'], 'both galleries saved' );
		$t::same( (string) $default, get_post_meta( $family, 'price_level', true ), 'family gallery keeps its level' );
		$t::same( (string) $level, get_post_meta( $wedding, 'price_level', true ), 'wedding gallery keeps its level' );
		$t::same( 'default', get_post_meta( $wedding, 'status', true ), 'gallery type kept' );
		$t::same( '', get_post_meta( $wedding, 'disable_products', true ), 'products still on' );

		$t::same( 2, $abe_gal_buy( $print, array( $family, $wedding ) ), 'both photos added' );
		$cart = $t::calculate();
		// 10 at the default level + 25 at the wedding level.
		$t::money( 35, $cart->get_total(), 'total' );
	}
);

$abe_test(
	'Bulk Edit galleries: Price Level changes what photos cost in the ticked galleries only',
	function () use ( $t, $abe_level, $abe_gallery, $abe_bulk_edit, $abe_gal_product, $abe_gal_buy ) {
		$default = sunshine_get_default_price_level_id();
		$level   = $abe_level( 'gallery set' );
		$print   = $abe_gal_product( $level );
		$first   = $abe_gallery( $default );
		$second  = $abe_gallery( '' );
		$other   = $abe_gallery( $default );

		$abe_bulk_edit( array( $first, $second ), array( 'price_level' => (string) $level ) );

		$t::same( 3, $abe_gal_buy( $print, array( $first, $second, $other ) ), 'three photos added' );
		$cart = $t::calculate();
		// 25 + 25 in the two ticked galleries, 10 in the gallery that was not ticked.
		$t::money( 60, $cart->get_total(), 'total' );
		$order = $t::place_order();
		$t::money( 60, $order->get_total(), 'order total' );
	}
);

$abe_test(
	'Quick Edit gallery: changing only the title keeps its price level, choosing a level changes prices',
	function () use ( $t, $abe_level, $abe_gallery, $abe_quick_edit, $abe_gal_product, $abe_gal_buy ) {
		$default = sunshine_get_default_price_level_id();
		$level   = $abe_level( 'gallery quick' );
		$print   = $abe_gal_product( $level );
		$wedding = $abe_gallery( $level );
		$family  = $abe_gallery( $default );

		$abe_quick_edit( $wedding, array(), 'Smith Wedding' );
		$abe_quick_edit( $family, array( 'price_level' => (string) $level ) );
		$t::same( (string) $level, get_post_meta( $wedding, 'price_level', true ), 'title-only edit keeps the level' );
		$t::same( (string) $level, get_post_meta( $family, 'price_level', true ), 'new level saved' );

		$t::same( 2, $abe_gal_buy( $print, array( $wedding, $family ) ), 'both photos added' );
		$cart = $t::calculate();
		// Both galleries now sell at 25.
		$t::money( 50, $cart->get_total(), 'total' );
	}
);

// A gallery saved with no price level uses the store's default level. Quick Edit has no empty
// choice in its Price Level list, so the list shows nothing chosen and sends nothing.
$abe_test(
	'Quick Edit gallery with no price level keeps using the default level',
	function () use ( $t, $abe_level, $abe_gallery, $abe_quick_edit, $abe_gal_product, $abe_gal_buy ) {
		$level   = $abe_level( 'gallery none' );
		$print   = $abe_gal_product( $level );
		$gallery = $abe_gallery( '' );

		$abe_quick_edit( $gallery, array(), 'Spring Minis' );
		$t::same( '', get_post_meta( $gallery, 'price_level', true ), 'still no price level' );
		$t::same( 1, $abe_gal_buy( $print, array( $gallery ) ), 'photo added' );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_total(), 'default level price' );
	}
);

$abe_test(
	'Bulk Edit galleries: Disable Products set to Yes stops sales in the ticked galleries only',
	function () use ( $t, $abe_level, $abe_gallery, $abe_bulk_edit, $abe_gal_product, $abe_gal_buy ) {
		$default = sunshine_get_default_price_level_id();
		$level   = $abe_level( 'gallery off' );
		$print   = $abe_gal_product( $level );
		$closed  = $abe_gallery( $level );
		$open    = $abe_gallery( $default );

		$abe_bulk_edit( array( $closed ), array( 'disable_products' => '1' ) );
		$t::same( 0, $abe_gal_buy( $print, array( $closed ) ), 'not sold in the ticked gallery' );
		$t::same( 1, $abe_gal_buy( $print, array( $open ) ), 'still sold in the other gallery' );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_total(), 'total' );

		$abe_bulk_edit( array( $closed ), array( 'disable_products' => '0' ) );
		$t::same( 1, $abe_gal_buy( $print, array( $closed ) ), 'sold again after No' );
		$cart = $t::new_request();
		$t::money( 35, $cart->get_total(), 'total 10 + 25' );
	}
);
