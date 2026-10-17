<?php
/**
 * Cart lines are found by their hash when the customer changes or removes one.
 */

$t = 'SPC_Totals_Test';

// Two lines of the same product added in the same second used to get the same hash,
// so changing the quantity of one changed the other.
$t::test(
	'Changing one of two identical lines leaves the other alone',
	function () use ( $t ) {
		$product_id = $t::product( 10, array( 'taxable' => false ) );
		$first      = $t::add( $product_id, 1 );
		$second     = $t::add( $product_id, 1 );
		$t::same( true, $first['hash'] !== $second['hash'], 'each line has its own hash' );
		SPC()->cart->update_item_quantity_by_hash( $second['hash'], 3 );
		$cart = $t::new_request();
		$t::money( 40, $cart->get_total(), 'total for 1 + 3' );
	}
);

// Carts saved before line hashes were unique can still hold lines that share one.
$t::test(
	'A saved cart with lines that share a hash gets unique hashes when it loads',
	function () use ( $t ) {
		$product_id = $t::product( 10, array( 'taxable' => false ) );
		$line       = array(
			'image_id'    => 0,
			'gallery_id'  => '',
			'product_id'  => $product_id,
			'options'     => array(),
			'price_level' => sunshine_get_default_price_level_id(),
			'qty'         => 1,
			'price'       => '10',
			'discount'    => 0,
			'comments'    => '',
			'meta'        => array(),
			'hash'        => 'samehash',
		);
		SPC()->session->set( 'cart', array( $line, $line, $line ) );
		$cart   = $t::new_request();
		$hashes = wp_list_pluck( (array) SPC()->session->get( 'cart' ), 'hash' );
		$t::same( 3, count( array_unique( $hashes ) ), 'three different hashes, saved' );
		$t::same( 'samehash', $hashes[0], 'the first line keeps its hash' );
		$t::money( 30, $cart->get_total(), 'total' );
	}
);
