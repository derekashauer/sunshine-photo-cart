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

/**
 * The word shown for tax on the cart, checkout, orders, emails and invoices.
 *
 * Each tax rate can have its own name (GST, VAT, Sales Tax). Rates without one,
 * including every rate saved before names existed, keep showing "Tax".
 *
 * @param string $name Name saved on the tax rate or order.
 * @return string
 */
function sunshine_get_tax_label( $name = '' ) {
	$name = trim( (string) $name );
	if ( '' === $name ) {
		$name = __( 'Tax', 'sunshine-photo-cart' );
	}
	return apply_filters( 'sunshine_tax_label', $name );
}

/**
 * The note shown beside the total when prices are displayed with tax included.
 *
 * @param string $amount_formatted Formatted tax amount.
 * @param string $name             Name saved on the tax rate or order.
 * @return string
 */
function sunshine_get_tax_included_text( $amount_formatted, $name = '' ) {
	$name = trim( (string) $name );
	if ( '' === $name ) {
		/* translators: %s is the tax amount */
		return sprintf( __( 'includes %s tax', 'sunshine-photo-cart' ), $amount_formatted );
	}
	/* translators: %1$s is the tax amount, %2$s is the tax name such as GST or VAT */
	return sprintf( __( 'includes %1$s %2$s', 'sunshine-photo-cart' ), $amount_formatted, $name );
}

/**
 * Suggested name for a new tax rate, based on the country it applies to.
 *
 * Only used to fill in the Name field when a store owner picks a country on a tax rate.
 * They can change it, and an empty name still shows "Tax".
 *
 * @return array Country code => suggested tax name.
 */
function sunshine_get_tax_name_suggestions() {
	$gst = __( 'GST', 'sunshine-photo-cart' );
	$vat = __( 'VAT', 'sunshine-photo-cart' );

	$suggestions = array_fill_keys( array( 'AU', 'NZ', 'SG', 'IN', 'CA' ), $gst );

	// United Kingdom and the European Union member states.
	$vat_countries = array( 'GB', 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE' );
	$suggestions  += array_fill_keys( $vat_countries, $vat );

	return apply_filters( 'sunshine_tax_name_suggestions', $suggestions );
}
