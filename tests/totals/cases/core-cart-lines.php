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
