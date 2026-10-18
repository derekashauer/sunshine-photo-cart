<?php
/**
 * Tax rates by location: which rate a customer gets depends on the address the order goes to.
 *
 * Rates are matched top to bottom and the first match wins. Shipped orders use the shipping
 * address, pickup orders use the store's own address, and download orders use the billing
 * address. Before there is any address, the store address can be used as an estimate.
 * See .docs/setting-up-taxes.md and .docs/which-address-is-used-for-tax.md.
 */

$t = 'SPC_Totals_Test';

// One row of the tax rate table, saved the way Sunshine > Settings > Taxes saves it:
// every field is a string, "all" is the "All countries" choice, an empty state is "Any state".
$tax_locations_rate = function ( $country, $state, $postcode, $rate, $name = '' ) {
	return array(
		'country'  => $country,
		'state'    => $state,
		'postcode' => $postcode,
		'rate'     => (string) $rate,
		'name'     => $name,
	);
};

$tax_locations_rates = function ( $rates ) use ( $t ) {
	$t::option( 'taxes_enabled', 1 );
	$t::option( 'tax_rates', $rates );
};

// Fill in a shipping_ or billing_ address the way the checkout form saves it.
$tax_locations_address = function ( $prefix, $country, $state, $postcode ) {
	$fields = array(
		'first_name' => 'Totals',
		'last_name'  => 'Test',
		'address1'   => '1 Main St',
		'city'       => 'Somewhere',
		'state'      => $state,
		'postcode'   => $postcode,
		'country'    => $country,
	);
	foreach ( $fields as $key => $value ) {
		SPC()->cart->set_checkout_data_item( $prefix . $key, $value );
	}
};

// The store's own address from Sunshine > Settings > General.
$tax_locations_store = function ( $country, $state, $postcode ) use ( $t ) {
	$t::option( 'country', $country );
	$t::option( 'state', $state );
	$t::option( 'postcode', $postcode );
};

// A store that ships for a flat $10 (not taxed), and this order is going to the given address.
$tax_locations_ship_to = function ( $country, $state, $postcode ) use ( $t, $tax_locations_address ) {
	$t::flat_shipping( 10, false );
	$tax_locations_address( 'shipping_', $country, $state, $postcode );
};

// A store that offers both shipping (flat rate) and one pickup location, without choosing either.
$tax_locations_ship_or_pickup = function ( $flat_price, $flat_taxable, $pickup_price, $pickup_taxable ) use ( $t ) {
	$t::option(
		'shipping_methods',
		array(
			'spctestflat'   => array(
				'id'     => 'flat_rate',
				'active' => 1,
			),
			'spctestpickup' => array(
				'id'     => 'pickup',
				'active' => 1,
			),
		)
	);
	$t::option( 'flat_rate_price_spctestflat', (string) $flat_price );
	$t::option( 'flat_rate_price_type_spctestflat', '' );
	$t::option( 'flat_rate_taxable_spctestflat', $flat_taxable ? 1 : '' );
	$t::option( 'pickup_price_spctestpickup', (string) $pickup_price );
	$t::option( 'pickup_taxable_spctestpickup', $pickup_taxable ? 1 : '' );
};

// The Delivery Method step saves the pickup location itself as the choice.
$tax_locations_choose_pickup = function () {
	SPC()->cart->set_checkout_data_item( 'delivery_method', 'spctestpickup' );
};

/*
 * Matching a rate to an address.
 */

$t::test(
	'Tax location: a country rate taxes that country and no other',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates( array( $tax_locations_rate( 'CA', '', '', 5, 'GST' ) ) );
		$t::add( $t::product( 100 ) );
		$tax_locations_ship_to( 'CA', 'ON', 'M5V 2T6' );
		$cart = $t::calculate();
		// 100 x 5%.
		$t::money( 5, $cart->get_tax(), 'tax in Canada' );
		$t::money( 115, $cart->get_total(), 'total in Canada (100 + 10 shipping + 5)' );
		$t::same( 'GST', $cart->get_tax_label(), 'tax name in Canada' );

		$tax_locations_ship_to( 'US', 'CO', '80202' );
		$cart = $t::new_request();
		$t::money( 0, $cart->get_tax(), 'no tax in the US' );
		$t::money( 110, $cart->get_total(), 'total in the US' );
	}
);

$t::test(
	'Tax location: a state rate for that state, the country rate for the rest',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
				$tax_locations_rate( 'US', '', '', 6 ),
			)
		);
		$t::add( $t::product( 100 ) );
		$tax_locations_ship_to( 'US', 'CO', '80301' );
		$cart = $t::calculate();
		$t::money( 2.90, $cart->get_tax(), 'Colorado: 100 x 2.9%' );

		$tax_locations_ship_to( 'US', 'TX', '75001' );
		$cart = $t::new_request();
		$t::money( 6, $cart->get_tax(), 'Texas: 100 x 6%' );
		$t::money( 116, $cart->get_total(), 'Texas total' );
	}
);

$t::test(
	'Tax location: an exact zip rate listed first wins over its state rate',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '80202', 8.81, 'Denver tax' ),
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
			)
		);
		$t::add( $t::product( 100 ) );
		$tax_locations_ship_to( 'US', 'CO', '80202' );
		$cart = $t::calculate();
		$t::money( 8.81, $cart->get_tax(), 'zip 80202: 100 x 8.81%' );
		$t::same( 'Denver tax', $cart->get_tax_label(), 'tax name for 80202' );

		$tax_locations_ship_to( 'US', 'CO', '80301' );
		$cart = $t::new_request();
		$t::money( 2.90, $cart->get_tax(), 'zip 80301 gets the state rate' );
		$t::same( 'Tax', $cart->get_tax_label(), 'state rate has no name' );
	}
);

$t::test(
	'Tax location: one rate can list several zip codes',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '80202, 80203,80204', 8.81 ),
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
			)
		);
		$t::add( $t::product( 100 ) );
		foreach ( array(
			'80203' => 8.81,
			'80204' => 8.81,
			'80205' => 2.90,
		) as $zip => $tax ) {
			$tax_locations_ship_to( 'US', 'CO', $zip );
			$cart = $t::new_request();
			$t::money( $tax, $cart->get_tax(), 'tax for zip ' . $zip );
		}
	}
);

$t::test(
	'Tax location: a wildcard zip matches every code that starts with it',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '802*', 8.81 ),
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
			)
		);
		$t::add( $t::product( 100 ) );
		// The docs say a wildcard also catches ZIP+4 codes.
		foreach ( array(
			'80210'      => 8.81,
			'80210-1234' => 8.81,
			'80301'      => 2.90,
		) as $zip => $tax ) {
			$tax_locations_ship_to( 'US', 'CO', $zip );
			$cart = $t::new_request();
			$t::money( $tax, $cart->get_tax(), 'tax for zip ' . $zip );
		}
	}
);

$t::test(
	'Tax location: a zip range includes both ends and nothing outside it',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '80200...80299', 8.81 ),
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
			)
		);
		$t::add( $t::product( 100 ) );
		foreach ( array(
			'80199' => 2.90,
			'80200' => 8.81,
			'80250' => 8.81,
			'80299' => 8.81,
			'80300' => 2.90,
		) as $zip => $tax ) {
			$tax_locations_ship_to( 'US', 'CO', $zip );
			$cart = $t::new_request();
			$t::money( $tax, $cart->get_tax(), 'tax for zip ' . $zip );
		}
	}
);

// Assumption: a US ZIP+4 code belongs to its 5 digit ZIP. The docs say a range matches
// "numeric codes", but a customer who types 80250-1234 lives in 80250, which is inside the
// range. Without this the city rate is skipped and only the lower state rate is charged.
$t::test(
	'Tax location: a ZIP+4 code inside a zip range gets the range rate',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '80200...80299', 8.81 ),
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
			)
		);
		$t::add( $t::product( 100 ) );
		$tax_locations_ship_to( 'US', 'CO', '80250-1234' );
		$cart = $t::calculate();
		$t::money( 8.81, $cart->get_tax(), '80250-1234 is in 80200...80299' );
	}
);

// The docs: "Rates are matched from top to bottom, the first matching rate wins."
$t::test(
	'Tax location: the first matching rate wins, top to bottom',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', '', '', 6 ),
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
			)
		);
		$t::add( $t::product( 100 ) );
		$tax_locations_ship_to( 'US', 'CO', '80202' );
		$cart = $t::calculate();
		$t::money( 6, $cart->get_tax(), 'the country rate above the Colorado rate is used' );
	}
);

// The settings screen says "Leave empty to match the whole state or country", and the State
// field is optional with "Any state/province" as its first choice. So a rate of
// US + any state + 80* should catch every US address whose zip starts with 80.
$t::test(
	'Tax location: a zip rate with "Any state" matches on country and zip',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', '', '80*', 4 ),
				$tax_locations_rate( 'US', '', '', 6 ),
			)
		);
		$t::add( $t::product( 100 ) );
		$tax_locations_ship_to( 'US', 'CO', '80202' );
		$cart = $t::calculate();
		$t::money( 4, $cart->get_tax(), '100 x 4% for zip 80202' );
	}
);

// Countries like the UK have no state list, so a postcode rate there never has a state.
// The docs use CB23* as their example of a postcode wildcard. Postcodes are matched without
// spaces and in any letter case.
$t::test(
	'Tax location: a postcode rate in a country without states',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates( array( $tax_locations_rate( 'GB', '', 'CB23*', 20, 'VAT' ) ) );
		$t::add( $t::product( 100 ) );
		$tax_locations_ship_to( 'GB', '', 'cb23 4ab' );
		$cart = $t::calculate();
		$t::money( 20, $cart->get_tax(), '100 x 20% for CB23 4AB' );
		$t::same( 'VAT', $cart->get_tax_label(), 'tax name' );
	}
);

$t::test(
	'Tax location: an "All countries" rate catches everyone the rates above miss',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
				$tax_locations_rate( 'all', '', '', 10 ),
			)
		);
		$t::add( $t::product( 100 ) );
		$tax_locations_ship_to( 'FR', '', '75001' );
		$cart = $t::calculate();
		$t::money( 10, $cart->get_tax(), 'France gets the catch-all 10%' );

		$tax_locations_ship_to( 'US', 'CO', '80202' );
		$cart = $t::new_request();
		$t::money( 2.90, $cart->get_tax(), 'Colorado still gets its own rate' );
	}
);

$t::test(
	'Tax location: no matching rate means no tax and the plain "Tax" name',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates( array( $tax_locations_rate( 'US', 'CO', '', 2.9, 'CO tax' ) ) );
		$t::add( $t::product( 100 ) );
		$tax_locations_ship_to( 'US', 'TX', '75001' );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_tax(), 'tax' );
		$t::money( 110, $cart->get_total(), 'total' );
		$t::same( '', $cart->get_tax_name(), 'no tax name' );
		$t::same( 'Tax', $cart->get_tax_label(), 'label' );
	}
);

// A store with a catch-all rate exempts a place (Oregon has no sales tax) by adding a 0% row
// above it. The settings field allows 0 (min="0"). That row matches first, so no tax.
$t::test(
	'Tax location: a 0% rate for a tax-free place is used, not skipped',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'OR', '', 0 ),
				$tax_locations_rate( 'US', '', '', 6 ),
			)
		);
		$t::add( $t::product( 100 ) );
		$tax_locations_ship_to( 'US', 'OR', '97201' );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_tax(), 'Oregon pays no tax' );
		$t::money( 110, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Tax location: a rate with three decimals is rounded to the cent',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates( array( $tax_locations_rate( 'US', 'NY', '', 8.875 ) ) );
		$t::add( $t::product( 19.99 ), 3 );
		$tax_locations_ship_to( 'US', 'NY', '10001' );
		$cart = $t::calculate();
		// 59.97 x 8.875% = 5.3223.
		$t::money( 5.32, $cart->get_tax(), 'tax' );
		$t::money( 75.29, $cart->get_total(), '59.97 + 10 + 5.32' );
	}
);

$t::test(
	'Tax location: a large quantity at a location rate',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates( array( $tax_locations_rate( 'US', 'NY', '', 8.875 ) ) );
		$t::add( $t::product( 1.99 ), 250 );
		$tax_locations_ship_to( 'US', 'NY', '10001' );
		$cart = $t::calculate();
		// 250 x 1.99 = 497.50, x 8.875% = 44.1531.
		$t::money( 44.15, $cart->get_tax(), 'tax' );
		$t::money( 551.65, $cart->get_total(), '497.50 + 10 + 44.15' );
	}
);

$t::test(
	'Tax location: products that are not taxable are never taxed',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates( array( $tax_locations_rate( 'US', 'CO', '', 2.9 ) ) );
		$t::add( $t::product( 50 ) );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$tax_locations_ship_to( 'US', 'CO', '80202' );
		$cart = $t::calculate();
		// Only the taxable 50: 50 x 2.9% = 1.45.
		$t::money( 1.45, $cart->get_tax(), 'tax' );
		$t::money( 111.45, $cart->get_total(), '100 + 10 + 1.45' );
	}
);

/*
 * Which address is used.
 */

$t::test(
	'Tax address: a shipped order is taxed where it goes, not where the buyer lives',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address ) {
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
				$tax_locations_rate( 'US', 'CA', '', 7.25 ),
			)
		);
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$tax_locations_address( 'shipping_', 'US', 'CO', '80202' );
		SPC()->cart->set_checkout_data_item( 'shipping_as_billing', '' );
		$tax_locations_address( 'billing_', 'US', 'CA', '90001' );
		$cart = $t::calculate();
		// ( 100 + 10 shipping ) x 2.9% Colorado rate.
		$t::money( 3.19, $cart->get_tax(), 'tax' );
		$t::money( 113.19, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Tax address: a download-only order uses the billing address',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address ) {
		$t::require_plugin( 'sunshine_get_download_product', 'Digital Downloads add-on' );
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
				$tax_locations_rate( 'US', 'CA', '', 7.25 ),
			)
		);
		// The store also ships prints, but this cart only has a download.
		$t::option(
			'shipping_methods',
			array(
				'spctestflat' => array(
					'id'     => 'flat_rate',
					'active' => 1,
				),
			)
		);
		$t::option( 'flat_rate_price_spctestflat', '10' );
		$t::add( $t::product( 20, array( 'type' => 'download' ) ) );
		$tax_locations_address( 'billing_', 'US', 'CA', '90001' );
		$cart = $t::calculate();
		// 20 x 7.25%, no shipping.
		$t::money( 1.45, $cart->get_tax(), 'tax' );
		$t::money( 0, $cart->get_shipping(), 'shipping' );
		$t::money( 21.45, $cart->get_total(), 'total' );
	}
);

// The customer had a print and a download, entered a shipping address for the print, then
// removed the print. Nothing is shipped any more, so the download is taxed at the billing
// address, as the docs say. The old shipping address is still in the checkout session.
$t::test(
	'Tax address: removing the only print makes the order use the billing address',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address ) {
		$t::require_plugin( 'sunshine_get_download_product', 'Digital Downloads add-on' );
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
				$tax_locations_rate( 'US', 'CA', '', 7.25 ),
			)
		);
		$print = $t::product( 100 );
		$t::add( $print );
		$t::add( $t::product( 20, array( 'type' => 'download' ) ) );
		$t::flat_shipping( 10, false );
		$tax_locations_address( 'shipping_', 'US', 'CO', '80202' );
		SPC()->cart->set_checkout_data_item( 'shipping_as_billing', '' );
		$tax_locations_address( 'billing_', 'US', 'CA', '90001' );
		$cart = $t::calculate();
		// Shipped, so Colorado: ( 100 + 20 ) x 2.9%.
		$t::money( 3.48, $cart->get_tax(), 'tax while the print is in the cart' );

		foreach ( $cart->get_cart() as $key => $line ) {
			if ( (int) $line['product_id'] === (int) $print ) {
				$cart->remove_item( $key );
			}
		}
		$cart = $t::new_request();
		// Only the download is left: 20 x 7.25% California.
		$t::money( 1.45, $cart->get_tax(), 'tax once only the download is left' );
		$t::money( 21.45, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Tax address: a pickup order is taxed at the store, wherever the customer lives',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address, $tax_locations_store, $tax_locations_ship_or_pickup, $tax_locations_choose_pickup ) {
		$tax_locations_store( 'US', 'NY', '10001' );
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'NY', '', 8.875, 'NY tax' ),
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
			)
		);
		$tax_locations_ship_or_pickup( 10, false, 0, false );
		$t::add( $t::product( 200 ) );
		$tax_locations_choose_pickup();
		$tax_locations_address( 'billing_', 'US', 'CO', '80202' );
		$cart = $t::calculate();
		// 200 x 8.875% New York.
		$t::money( 17.75, $cart->get_tax(), 'tax' );
		$t::money( 0, $cart->get_shipping(), 'no shipping' );
		$t::money( 217.75, $cart->get_total(), 'total' );
		$t::same( 'NY tax', $cart->get_tax_label(), 'tax name' );
	}
);

$t::test(
	'Tax address: a taxable pickup fee is taxed at the store rate too',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address, $tax_locations_store, $tax_locations_ship_or_pickup, $tax_locations_choose_pickup ) {
		$tax_locations_store( 'US', 'CO', '80202' );
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
				$tax_locations_rate( 'US', 'CA', '', 7.25 ),
			)
		);
		$tax_locations_ship_or_pickup( 10, false, 10, true );
		$t::add( $t::product( 100 ) );
		$tax_locations_choose_pickup();
		$tax_locations_address( 'billing_', 'US', 'CA', '90001' );
		$cart = $t::calculate();
		// ( 100 + 10 pickup fee ) x 2.9% Colorado.
		$t::money( 10, $cart->get_shipping(), 'pickup fee' );
		$t::money( 3.19, $cart->get_tax(), 'tax' );
		$t::money( 113.19, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Tax address: pickup pays no tax when no rate covers the store',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address, $tax_locations_store, $tax_locations_ship_or_pickup, $tax_locations_choose_pickup ) {
		$tax_locations_store( 'US', 'OR', '97201' );
		$tax_locations_rates( array( $tax_locations_rate( 'US', 'CO', '', 2.9 ) ) );
		$tax_locations_ship_or_pickup( 10, false, 0, false );
		$t::add( $t::product( 100 ) );
		$tax_locations_choose_pickup();
		$tax_locations_address( 'billing_', 'US', 'CO', '80202' );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_tax(), 'the Colorado customer pays the Oregon store rate, which is none' );
		$t::money( 100, $cart->get_total(), 'total' );
	}
);

// The docs: "Default Customer Location" is only about the estimate before an address exists,
// and is separate from pickup orders, where the store address is the real answer.
$t::test(
	'Tax address: pickup uses the store address even with "No location by default"',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address, $tax_locations_store, $tax_locations_ship_or_pickup, $tax_locations_choose_pickup ) {
		$tax_locations_store( 'US', 'NY', '10001' );
		$t::option( 'tax_default_location', 'none' );
		$tax_locations_rates( array( $tax_locations_rate( 'US', 'NY', '', 8.875 ) ) );
		$tax_locations_ship_or_pickup( 10, false, 0, false );
		$t::add( $t::product( 200 ) );
		$tax_locations_choose_pickup();
		$tax_locations_address( 'billing_', 'US', 'CA', '90001' );
		$cart = $t::calculate();
		// 200 x 8.875% New York.
		$t::money( 17.75, $cart->get_tax(), 'tax' );
	}
);

// The store's state in Sunshine > Settings > General is a free text box, so owners type
// "Colorado" (the Photo Cart dev site has exactly that), while tax rates store the state
// code "CO" from a list. Pickup orders are taxed at the store, so they should still get the
// Colorado rate. Assumption: the state name and its code mean the same place.
$t::test(
	'Tax address: pickup at a store whose state is typed out in full',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address, $tax_locations_store, $tax_locations_ship_or_pickup, $tax_locations_choose_pickup ) {
		$tax_locations_store( 'US', 'Colorado', '80202' );
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
				$tax_locations_rate( 'US', 'CA', '', 7.25 ),
			)
		);
		$tax_locations_ship_or_pickup( 10, false, 0, false );
		$t::add( $t::product( 100 ) );
		$tax_locations_choose_pickup();
		$tax_locations_address( 'billing_', 'US', 'CA', '90001' );
		$cart = $t::calculate();
		$t::money( 2.90, $cart->get_tax(), '100 x 2.9% Colorado' );
	}
);

// Done the way the checkout's Delivery Method and Shipping Method choices do it.
$t::test(
	'Tax address: switching from Ship to Pickup moves the tax to the store rate, and back',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address, $tax_locations_store, $tax_locations_ship_or_pickup ) {
		$tax_locations_store( 'US', 'NY', '10001' );
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'NY', '', 8.875 ),
				$tax_locations_rate( 'US', 'CA', '', 7.25 ),
			)
		);
		$tax_locations_ship_or_pickup( 10, false, 0, false );
		$t::add( $t::product( 200 ) );
		SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
		SPC()->cart->set_checkout_data_item( 'shipping_method', 'spctestflat' );
		$tax_locations_address( 'shipping_', 'US', 'CA', '90001' );
		$tax_locations_address( 'billing_', 'US', 'CA', '90001' );
		$cart = $t::calculate();
		// Shipped to California: 200 x 7.25%.
		$t::money( 14.50, $cart->get_tax(), 'shipped: tax' );
		$t::money( 224.50, $cart->get_total(), 'shipped: 200 + 10 + 14.50' );

		$cart->set_delivery_method( 'spctestpickup' );
		$cart->update();
		// Picked up in New York: 200 x 8.875%.
		$t::money( 17.75, $cart->get_tax(), 'pickup: tax' );
		$t::money( 217.75, $cart->get_total(), 'pickup: 200 + 17.75' );

		$cart->set_delivery_method( 'shipping' );
		$cart->update();
		$cart->set_shipping_method( 'spctestflat' );
		$cart->update();
		$t::money( 14.50, $cart->get_tax(), 'shipped again: tax' );
		$t::money( 224.50, $cart->get_total(), 'shipped again: total' );
	}
);

/*
 * Before the customer has entered an address.
 */

$t::test(
	'Tax before an address: the store location is used as an estimate',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_store ) {
		$tax_locations_store( 'US', 'CO', '80202' );
		$t::option( 'tax_default_location', 'store' );
		$tax_locations_rates( array( $tax_locations_rate( 'US', 'CO', '', 2.9 ) ) );
		$t::add( $t::product( 100 ) );
		$cart = $t::calculate();
		$t::money( 2.90, $cart->get_tax(), 'tax' );
		$t::money( 102.90, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Tax before an address: "No location by default" shows no tax',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_store ) {
		$tax_locations_store( 'US', 'CO', '80202' );
		$t::option( 'tax_default_location', 'none' );
		$tax_locations_rates( array( $tax_locations_rate( 'US', 'CO', '', 2.9 ) ) );
		$t::add( $t::product( 100 ) );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_tax(), 'tax' );
		$t::money( 100, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Tax before an address: the address the customer enters replaces the store estimate',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_store, $tax_locations_address ) {
		$tax_locations_store( 'US', 'CO', '80202' );
		$t::option( 'tax_default_location', 'store' );
		$tax_locations_rates( array( $tax_locations_rate( 'US', 'CO', '', 2.9 ) ) );
		$t::add( $t::product( 100 ) );
		$cart = $t::calculate();
		$t::money( 2.90, $cart->get_tax(), 'estimate from the store' );

		// A store with no shipping set up only asks for a billing address.
		$tax_locations_address( 'billing_', 'US', 'TX', '75001' );
		$cart = $t::new_request();
		$t::money( 0, $cart->get_tax(), 'Texas has no rate, so no tax' );
		$t::money( 100, $cart->get_total(), 'total' );
	}
);

// A returning customer's saved shipping address is a better guess than the store's own
// address. The checkout pre-fills from it, and the tax estimate uses it too.
$t::test(
	'Tax before an address: a returning customer sees tax for their saved address',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_store ) {
		$tax_locations_store( 'US', 'CO', '80202' );
		$t::option( 'tax_default_location', 'store' );
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
				$tax_locations_rate( 'US', 'CA', '', 7.25 ),
			)
		);
		$t::customer();
		// Saved on the profile by an earlier order (init_order() copies checkout fields there).
		SPC()->customer->update_meta( 'shipping_country', 'US' );
		SPC()->customer->update_meta( 'shipping_state', 'CA' );
		SPC()->customer->update_meta( 'shipping_postcode', '90001' );
		$t::flat_shipping( 10, false );
		$t::add( $t::product( 100 ) );
		$cart = $t::calculate();
		$t::money( 7.25, $cart->get_tax(), '100 x 7.25% California' );
	}
);

/*
 * Changing the address during checkout.
 */

$t::test(
	'Tax change: the tax follows a new shipping address in the same visit',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address ) {
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 2.9, 'CO tax' ),
				$tax_locations_rate( 'US', 'CA', '', 7.25, 'CA tax' ),
			)
		);
		$t::add( $t::product( 80 ) );
		$t::flat_shipping( 20, true );
		$tax_locations_address( 'shipping_', 'US', 'CO', '80202' );
		$cart = $t::calculate();
		// ( 80 + 20 ) x 2.9%.
		$t::money( 2.90, $cart->get_tax(), 'Colorado tax' );
		$t::money( 102.90, $cart->get_total(), 'Colorado total' );
		$t::same( 'CO tax', $cart->get_tax_label(), 'Colorado tax name' );

		$tax_locations_address( 'shipping_', 'US', 'CA', '90001' );
		$cart->setup( true );
		// ( 80 + 20 ) x 7.25%.
		$t::money( 7.25, $cart->get_tax(), 'California tax' );
		$t::money( 107.25, $cart->get_total(), 'California total' );
		$t::same( 'CA tax', $cart->get_tax_label(), 'California tax name' );

		$tax_locations_address( 'shipping_', 'US', 'TX', '75001' );
		$cart->setup( true );
		$t::money( 0, $cart->get_tax(), 'Texas has no rate' );
		$t::money( 100, $cart->get_total(), 'Texas total' );
		$t::same( 'Tax', $cart->get_tax_label(), 'no name once nothing matches' );
	}
);

// The checkout's Shipping Address step saves the fields and works the cart out again
// (process_section() then update()), and that is what the order summary shows next.
$t::test(
	'Tax change: completing the Shipping Address step works the tax out again',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate ) {
		$t::option( 'tax_default_location', 'none' );
		$tax_locations_rates( array( $tax_locations_rate( 'US', 'CA', '', 7.25 ) ) );
		$t::add( $t::product( 80 ) );
		$t::flat_shipping( 20, true );
		$cart = $t::calculate();
		$t::money( 0, $cart->get_tax(), 'no tax before the address' );

		$posted = array(
			'shipping_first_name' => 'Totals',
			'shipping_last_name'  => 'Test',
			'shipping_country'    => 'US',
			'shipping_address1'   => '1 Main St',
			'shipping_address2'   => '',
			'shipping_city'       => 'Los Angeles',
			'shipping_state'      => 'CA',
			'shipping_postcode'   => '90001',
		);
		$next = $cart->process_section( 'shipping', $posted );
		$t::same( array(), $cart->get_errors(), 'the step saved without errors' );
		$t::same( true, is_string( $next ), 'checkout moved on to the next step' );
		// ( 80 + 20 ) x 7.25%.
		$t::money( 7.25, $cart->get_tax(), 'tax once the address is in' );
		$t::money( 107.25, $cart->get_total(), 'total' );
	}
);

$t::test(
	'Tax change: a gift card covers the new total after the address changes',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address ) {
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
				$tax_locations_rate( 'US', 'CA', '', 7.25 ),
			)
		);
		$t::add( $t::product( 80 ) );
		$t::flat_shipping( 20, true );
		$t::gift_card( 500 );
		$tax_locations_address( 'shipping_', 'US', 'CO', '80202' );
		$cart = $t::calculate();
		$taken = array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) );
		$t::money( 102.90, $taken, 'card pays the Colorado total' );
		$t::money( 0, $cart->get_total(), 'nothing left to pay' );

		$tax_locations_address( 'shipping_', 'US', 'CA', '90001' );
		$cart = $t::new_request();
		$taken = array_sum( array_map( 'floatval', (array) SPC()->session->get( 'gift_card_amounts' ) ) );
		$t::money( 7.25, $cart->get_tax(), 'California tax' );
		$t::money( 107.25, $taken, 'card pays the California total' );
		$t::money( 0, $cart->get_total(), 'nothing left to pay' );
	}
);

// Store credit is a fixed amount, so it stays the same and only what is left to pay moves.
$t::test(
	'Tax change: store credit stays the same and the total follows the address',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address ) {
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', 50 );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
				$tax_locations_rate( 'US', 'CA', '', 7.25 ),
			)
		);
		$t::add( $t::product( 80 ) );
		$t::flat_shipping( 20, true );
		$tax_locations_address( 'shipping_', 'US', 'CO', '80202' );
		$cart = $t::calculate();
		$t::money( 50, $cart->get_credits_applied(), 'Colorado: credit used' );
		$t::money( 52.90, $cart->get_total(), 'Colorado: 102.90 - 50' );

		$tax_locations_address( 'shipping_', 'US', 'CA', '90001' );
		$cart = $t::new_request();
		$t::money( 50, $cart->get_credits_applied(), 'California: credit used' );
		$t::money( 57.25, $cart->get_total(), 'California: 107.25 - 50' );
	}
);

$t::test(
	'Tax change: a percent payment fee follows the new tax',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address ) {
		$t::option( 'test_fee', 'percent' );
		$t::option( 'test_fee_amount', '3' );
		$t::option( 'test_fee_name', 'Card fee' );
		SPC()->cart->set_checkout_data_item( 'payment_method', 'test' );
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
				$tax_locations_rate( 'US', 'CA', '', 7.25 ),
			)
		);
		$t::add( $t::product( 80 ) );
		$t::flat_shipping( 20, true );
		$tax_locations_address( 'shipping_', 'US', 'CO', '80202' );
		$cart = $t::calculate();
		// 102.90 + 3% of 102.90 (3.087, so 3.09).
		$t::money( 105.99, $cart->get_total(), 'Colorado total' );

		$tax_locations_address( 'shipping_', 'US', 'CA', '90001' );
		$cart = $t::new_request();
		// 107.25 + 3% of 107.25 (3.2175, so 3.22).
		$t::money( 110.47, $cart->get_total(), 'California total' );
	}
);

// With "discount after tax" the discount is a percent of the price with tax, as in core-tax.
$t::test(
	'Tax change: discount after tax follows a new address in the same visit',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address ) {
		$t::option( 'discount_after_tax', 1 );
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 2.9 ),
				$tax_locations_rate( 'US', 'CA', '', 7.25 ),
			)
		);
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$tax_locations_address( 'billing_', 'US', 'CA', '90001' );
		$cart = $t::calculate();
		// Tax 7.25 on the full 100. Discount 20% of 107.25 = 21.45. Total 85.80.
		$t::money( 7.25, $cart->get_tax(), 'California tax' );
		$t::money( 21.45, $cart->get_discount(), 'California discount' );
		$t::money( 85.80, $cart->get_total(), 'California total' );

		$tax_locations_address( 'billing_', 'US', 'CO', '80202' );
		$cart->setup( true );
		// Tax 2.90. Discount 20% of 102.90 = 20.58. Total 82.32.
		$t::money( 2.90, $cart->get_tax(), 'Colorado tax' );
		$t::money( 20.58, $cart->get_discount(), 'Colorado discount' );
		$t::money( 82.32, $cart->get_total(), 'Colorado total' );
	}
);

/*
 * Tax names.
 */

$t::test(
	'Tax name: each place shows the name of the rate it matched',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_ship_to ) {
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'CA', 'ON', '', 13, 'HST' ),
				$tax_locations_rate( 'CA', '', '', 5, 'GST' ),
			)
		);
		$t::add( $t::product( 100 ) );
		$tax_locations_ship_to( 'CA', 'ON', 'M5V 2T6' );
		$cart = $t::calculate();
		$t::money( 13, $cart->get_tax(), 'Ontario: 100 x 13%' );
		$t::same( 'HST', $cart->get_tax_label(), 'Ontario name' );

		$tax_locations_ship_to( 'CA', 'BC', 'V6B 1A1' );
		$cart = $t::new_request();
		$t::money( 5, $cart->get_tax(), 'British Columbia: 100 x 5%' );
		$t::same( 'GST', $cart->get_tax_label(), 'British Columbia name' );
	}
);

/*
 * Prices entered with tax included.
 */

// With prices that include tax, the customer pays the price on the shelf. Where they are only
// changes how much of that price is tax. (That is how the cart already treats a single rate.)
$t::test(
	'Prices include tax: the total stays the shelf price, only the tax inside it changes',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address ) {
		$t::option( 'price_has_tax', 'yes' );
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 10 ),
				$tax_locations_rate( 'US', 'CA', '', 20 ),
			)
		);
		$t::add( $t::product( 110 ) );
		$tax_locations_address( 'billing_', 'US', 'CO', '80202' );
		$cart = $t::calculate();
		// 110 / 1.10 = 100, so 10 is tax.
		$t::money( 10, $cart->get_tax(), '10% place: tax' );
		$t::money( 110, $cart->get_total(), '10% place: total' );

		$tax_locations_address( 'billing_', 'US', 'CA', '90001' );
		$cart = $t::new_request();
		// 110 / 1.20 = 91.67, so 18.33 is tax.
		$t::money( 18.33, $cart->get_tax(), '20% place: tax' );
		$t::money( 110, $cart->get_total(), '20% place: total' );

		$tax_locations_address( 'billing_', 'US', 'TX', '75001' );
		$cart = $t::new_request();
		$t::money( 0, $cart->get_tax(), 'no rate: tax' );
		$t::money( 110, $cart->get_total(), 'no rate: total' );
	}
);

$t::test(
	'Prices include tax: discount code and taxable shipping in two places',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_address ) {
		$t::option( 'price_has_tax', 'yes' );
		$tax_locations_rates(
			array(
				$tax_locations_rate( 'US', 'CO', '', 10 ),
				$tax_locations_rate( 'US', 'CA', '', 20 ),
			)
		);
		$t::add( $t::product( 110 ) );
		$t::flat_shipping( 11, true );
		$t::discount( 'percent-total', 10 );
		$tax_locations_address( 'shipping_', 'US', 'CO', '80202' );
		$cart = $t::calculate();
		// Items 110 - 11 discount = 99, of which 99 / 1.10 = 90 is price and 9 is tax.
		// Shipping 11 is 10 + 1 tax. Total 99 + 11 = 110.
		$t::money( 11, $cart->get_discount(), '10% place: discount' );
		$t::money( 10, $cart->get_tax(), '10% place: tax 9 + 1' );
		$t::money( 110, $cart->get_total(), '10% place: total' );

		$tax_locations_address( 'shipping_', 'US', 'CA', '90001' );
		$cart = $t::new_request();
		// Items 99 / 1.20 = 82.50, so 16.50 tax. Shipping 11 / 1.20 = 9.17, so 1.83 tax.
		$t::money( 11, $cart->get_discount(), '20% place: discount' );
		$t::money( 18.33, $cart->get_tax(), '20% place: tax 16.50 + 1.83' );
		$t::money( 110, $cart->get_total(), '20% place: total' );
	}
);

/*
 * Prices shown in galleries.
 */

// "Display prices: Including tax" shows prices with tax added. With "My business location"
// as the default, a visitor who has not added anything yet should already see the price with
// the store's tax in it, not a lower price that jumps up once something is in the cart.
$t::test(
	'Display: prices shown with tax include the store rate before anything is in the cart',
	function () use ( $t, $tax_locations_rates, $tax_locations_rate, $tax_locations_store ) {
		$tax_locations_store( 'US', 'CO', '80202' );
		$t::option( 'tax_default_location', 'store' );
		$t::option( 'display_price', 'with_tax' );
		$t::option( 'price_suffix', '' );
		$tax_locations_rates( array( $tax_locations_rate( 'US', 'CO', '', 10 ) ) );
		$product_id = $t::product( 100 );
		$t::calculate();
		// 100 + 10% = 110.
		$t::same( sunshine_price( 110 ), sunshine_get_product( $product_id )->get_price_formatted(), 'price shown with an empty cart' );

		$t::add( $t::product( 5 ) );
		$t::calculate();
		$t::same( sunshine_price( 110 ), sunshine_get_product( $product_id )->get_price_formatted(), 'price shown once the cart has something in it' );
	}
);
