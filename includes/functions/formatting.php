<?php

// TODO: This should return the price but limited to float based on locale settings.
function sunshine_price( $value, $force_0 = false ) {
	if ( $value == '' ) {
		$value = 0;
	}
	/*
	$free_label = apply_filters( 'sunshine_price_free_label', __( 'Free', 'sunshine-photo-cart' ) );
	if ( $value == 0 && ! $force_0 && $free_label ) {
		return '<span class="sunshine-amount">' . $free_label . '</span>';
	}
	*/
	return '<span class="sunshine-price">' . sunshine_currency_filter( sunshine_format_amount( $value ) ) . '</span>';
}

function sunshine_sanitize_amount( $amount ) {
	$is_negative   = false;
	$thousands_sep = SPC()->get_option( 'currency_thousands_separator' );
	$decimal_sep   = SPC()->get_option( 'currency_decimal_separator' );

	// Sanitize the amount.
	if ( $decimal_sep == ',' && false !== ( $found = strpos( $amount, $decimal_sep ) ) ) {
		if ( ( $thousands_sep == '.' || $thousands_sep == ' ' ) && false !== ( $found = strpos( $amount, $thousands_sep ) ) ) {
			$amount = str_replace( $thousands_sep, '', $amount );
		} elseif ( empty( $thousands_sep ) && false !== ( $found = strpos( $amount, '.' ) ) ) {
			$amount = str_replace( '.', '', $amount );
		}
		$amount = str_replace( $decimal_sep, '.', $amount );
	} elseif ( $thousands_sep == ',' && false !== ( $found = strpos( $amount, $thousands_sep ) ) ) {
		$amount = str_replace( $thousands_sep, '', $amount );
	}

	if ( $amount < 0 ) {
		$is_negative = true;
	}

	$amount = preg_replace( '/[^0-9\.]/', '', $amount );

	$decimals = apply_filters( 'sunshine_sanitize_amount_decimals', 2, $amount );
	$amount   = number_format( (float) $amount, $decimals, '.', '' );

	if ( $is_negative ) {
		$amount *= -1;
	}

	return apply_filters( 'sunshine_sanitize_amount', $amount );
}

/**
 * Returns a nicely formatted amount.
 *
 * @since 1.0
 *
 * @param string $amount   Price amount to format
 * @param string $decimals Whether or not to use decimals.  Useful when set to false for non-currency numbers.
 *
 * @return string $amount Newly formatted amount or Price Not Available
 */
function sunshine_format_amount( $amount, $decimals = true ) {

	if ( is_null( $amount ) ) {
		return;
	}

	$thousands_sep = SPC()->get_option( 'currency_thousands_separator' );
	$decimal_sep   = SPC()->get_option( 'currency_decimal_separator' );

	// Format the amount.
	if ( $decimal_sep == ',' && false !== ( $sep_found = strpos( $amount, $decimal_sep ) ) ) {
		$whole  = substr( $amount, 0, $sep_found );
		$part   = substr( $amount, $sep_found + 1, ( strlen( $amount ) - 1 ) );
		$amount = $whole . '.' . $part;
	}

	// Strip , from the amount (if set as the thousands separator).
	if ( $thousands_sep == ',' && false !== ( $found = strpos( $amount, $thousands_sep ) ) ) {
		$amount = str_replace( ',', '', $amount );
	}

	// Strip ' ' from the amount (if set as the thousands separator).
	if ( $thousands_sep == ' ' && false !== ( $found = strpos( $amount, $thousands_sep ) ) ) {
		$amount = str_replace( ' ', '', $amount );
	}

	if ( empty( $amount ) ) {
		$amount = 0;
	}

	$decimals  = apply_filters( 'sunshine_format_amount_decimals', $decimals ? 2 : 0, $amount );
	$formatted = number_format( floatval( $amount ), $decimals, $decimal_sep, $thousands_sep );

	$formatted = str_replace( $decimal_sep . '00', '', $formatted ); // Don't use trailing .00, yuck.

	return apply_filters( 'sunshine_format_amount', $formatted, $amount, $decimals, $decimal_sep, $thousands_sep );
}

function sunshine_currency_filter( $price = '', $currency = '' ) {

	if ( empty( $currency ) ) {
		$currency = SPC()->get_option( 'currency' );
	}

	$position  = SPC()->get_option( 'currency_symbol_position' );
	$show_code = SPC()->get_option( 'currency_show_code' );

	$negative = is_numeric( $price ) && $price < 0;

	if ( $negative ) {
		$price = substr( $price, 1 ); // Remove proceeding "-" -
	}

	$symbol_html = '<span class="sunshine-currency-symbol">' . sunshine_currency_symbol( $currency ) . '</span>';
	$price_html  = '<span class="sunshine-amount">' . $price . '</span>';
	$code_html   = $show_code ? '<span class="sunshine-currency-code">' . $currency . '</span>' : '';

	if ( $position == 'left' ) :
		switch ( $currency ) :
			case 'GBP':
			case 'BRL':
			case 'EUR':
			case 'USD':
			case 'AUD':
			case 'CAD':
			case 'HKD':
			case 'MXN':
			case 'NZD':
			case 'SGD':
			case 'JPY':
				$formatted = $symbol_html . $price_html;
				break;
			default:
				$formatted = $symbol_html . ' ' . $price_html;
				break;
		endswitch;
		if ( $show_code ) {
			$formatted .= ' ' . $code_html;
		}
		$formatted = apply_filters( 'sunshine_' . strtolower( $currency ) . '_currency_filter_before', $formatted, $currency, $price );
	else :
		switch ( $currency ) :
			case 'GBP':
			case 'BRL':
			case 'EUR':
			case 'USD':
			case 'AUD':
			case 'CAD':
			case 'HKD':
			case 'MXN':
			case 'SGD':
			case 'JPY':
				$formatted = $price_html . $symbol_html;
				break;
			default:
				$formatted = $price_html . ' ' . $symbol_html;
				break;
		endswitch;
		if ( $show_code ) {
			$formatted .= ' ' . $code_html;
		}
		$formatted = apply_filters( 'sunshine_' . strtolower( $currency ) . '_currency_filter_after', $formatted, $currency, $price );
	endif;

	if ( $negative ) {
		// Prepend the mins sign before the currency sign
		$formatted = '-' . $formatted;
	}

	return $formatted;
}

/**
 * Set the number of decimal places per currency
 *
 * @since 1.4.2
 * @param int $decimals Number of decimal places
 * @return int $decimals
 */
function sunshine_currency_decimal_filter( $decimals = 2 ) {

	$currency = sunshine_get_currency();

	switch ( $currency ) {
		case 'RIAL':
		case 'JPY':
		case 'TWD':
		case 'HUF':
			$decimals = 0;
			break;
	}

	return apply_filters( 'sunshine_currency_decimal_count', $decimals, $currency );
}
add_filter( 'sunshine_sanitize_amount_decimals', 'sunshine_currency_decimal_filter' );
add_filter( 'sunshine_format_amount_decimals', 'sunshine_currency_decimal_filter' );

function sunshine_get_currency() {
	$currency = SPC()->get_option( 'currency' );
	return apply_filters( 'sunshine_currency', $currency );
}

function sunshine_currency_symbol() {

	switch ( SPC()->get_option( 'currency' ) ) {
		case 'AED':
			$currency_symbol = 'د.إ';
			break;
		case 'AUD':
		case 'ARS':
		case 'CAD':
		case 'CLP':
		case 'COP':
		case 'HKD':
		case 'MXN':
		case 'NZD':
		case 'SGD':
		case 'USD':
			$currency_symbol = '&#36;';
			break;
		case 'BDT':
			$currency_symbol = '&#2547;&nbsp;';
			break;
		case 'BGN':
			$currency_symbol = '&#1083;&#1074;.';
			break;
		case 'BRL':
			$currency_symbol = '&#82;&#36;';
			break;
		case 'CHF':
			$currency_symbol = '&#67;&#72;&#70;';
			break;
		case 'CNY':
		case 'JPY':
		case 'RMB':
			$currency_symbol = '&yen;';
			break;
		case 'CZK':
			$currency_symbol = '&#75;&#269;';
			break;
		case 'DKK':
			$currency_symbol = 'DKK';
			break;
		case 'DOP':
			$currency_symbol = 'RD&#36;';
			break;
		case 'EGP':
			$currency_symbol = 'EGP';
			break;
		case 'EUR':
			$currency_symbol = '&euro;';
			break;
		case 'GBP':
			$currency_symbol = '&pound;';
			break;
		case 'HRK':
			$currency_symbol = 'Kn';
			break;
		case 'HUF':
			$currency_symbol = '&#70;&#116;';
			break;
		case 'IDR':
			$currency_symbol = 'Rp';
			break;
		case 'ILS':
			$currency_symbol = '&#8362;';
			break;
		case 'INR':
			$currency_symbol = 'Rs.';
			break;
		case 'ISK':
			$currency_symbol = 'Kr.';
			break;
		case 'KIP':
			$currency_symbol = '&#8365;';
			break;
		case 'KRW':
			$currency_symbol = '&#8361;';
			break;
		case 'MYR':
			$currency_symbol = '&#82;&#77;';
			break;
		case 'NGN':
			$currency_symbol = '&#8358;';
			break;
		case 'NOK':
			$currency_symbol = '&#107;&#114;';
			break;
		case 'NPR':
			$currency_symbol = 'Rs.';
			break;
		case 'PHP':
			$currency_symbol = '&#8369;';
			break;
		case 'PLN':
			$currency_symbol = '&#122;&#322;';
			break;
		case 'PYG':
			$currency_symbol = '&#8370;';
			break;
		case 'RON':
			$currency_symbol = 'lei';
			break;
		case 'RUB':
			$currency_symbol = '&#1088;&#1091;&#1073;.';
			break;
		case 'SEK':
			$currency_symbol = '&#107;&#114;';
			break;
		case 'THB':
			$currency_symbol = '&#3647;';
			break;
		case 'TRY':
			$currency_symbol = '&#8378;';
			break;
		case 'TWD':
			$currency_symbol = '&#78;&#84;&#36;';
			break;
		case 'UAH':
			$currency_symbol = '&#8372;';
			break;
		case 'VND':
			$currency_symbol = '&#8363;';
			break;
		case 'ZAR':
			$currency_symbol = '&#82;';
			break;
		default:
			$currency_symbol = '';
			break;
	}
	return apply_filters( 'sunshine_currency_symbol', $currency_symbol );
}

function sunshine_get_price_to_display( $price, $tax = 0, $force_suffix = false ) {
	// $price_has_tax = SPC()->get_option( 'price_has_tax' );
	$display_price = SPC()->get_option( 'display_price' );
	$suffix        = SPC()->get_option( 'price_suffix' );

	if ( 'with_tax' === $display_price ) {
		$price += $tax;
	}

	$price_formatted = sunshine_price( $price );

	if ( ( $price > 0 && $suffix && $tax ) || $force_suffix ) {
		$price_formatted .= ' <small class="sunshine--price--suffix">' . $suffix . '</small>';
	}

	return $price_formatted;
}

function sunshine_address_formatted( $data, $format = 'multiline' ) {
	$address = '';
	if ( $format == 'line' ) {
		foreach ( $data as $key => $el ) {
			if ( empty( $el ) ) {
				unset( $data[ $key ] );
			}
		}
		$address = join( ', ', $data );
	} elseif ( $format == 'multiline' ) { // Default US multiline format
		$address = $data['address1'] . '<br />';
		if ( isset( $data['address2'] ) ) {
			$address .= $data['address1'] . '<br />';
		}
		$address .= $data['city'] . ', ' . $data['state'] . ' ' . $data['zip'];
		if ( isset( $data['country'] ) ) {
			$address .= '<br />' . $data['country'];
		}
	} else { // Custom format passed as %key%
		$address = $format;
		foreach ( $data as $key => $value ) {
			$address = str_replace( '%' . $key . '%', $value, $address );
		}
	}
	return $address;
}
