<?php

function sunshine_get_tax_rates() {
	return apply_filters( 'sunshine_tax_rates', SPC()->get_option( 'tax_rates' ) );
}

function sunshine_tax_rates_need_address() {
	if ( ! SPC()->get_option( 'taxes_enabled' ) ) {
		return false;
	}

	$tax_rates = sunshine_get_tax_rates();
	if ( empty( $tax_rates ) || ! is_array( $tax_rates ) ) {
		return false;
	}

	$has_location_rate = false;
	foreach ( $tax_rates as $tax_rate ) {
		if ( ! empty( $tax_rate['country'] ) && $tax_rate['country'] !== 'all' ) {
			$has_location_rate = true;
			break;
		}
	}

	if ( ! $has_location_rate ) {
		return false;
	}

	// Only require address if the cart has taxable items.
	$cart_items = SPC()->cart->get_cart_items();
	if ( ! empty( $cart_items ) ) {
		foreach ( $cart_items as $item ) {
			if ( $item->is_taxable() ) {
				return true;
			}
		}
	}

	return false;
}
