<?php
/**
 * Payment gateways that send the customer to the gateway's own page to pay: Paystack, QuickPay,
 * PayFast, Systempay and Mercado Pago (Checkout Pro). The amount the gateway is asked to charge
 * must be the order total, in the unit the gateway expects. Helpers are in
 * addon-payment-gateways-helpers.php. Nothing is sent to any gateway.
 */

$t = 'SPC_Totals_Test';

/*
 * Paystack takes the amount in the smallest unit of the currency (kobo, cents), as a whole
 * number: 73.92 is sent as 7392.
 */

// Start a Paystack payment for the order. Returns the request body as Paystack would get it.
$addon_gateways_paystack_start = function ( $order ) use ( $t, $addon_gateways_run, $addon_gateways_find, $addon_gateways_wire ) {
	$t::require_plugin( 'sunshine_payment_method_paystack', 'Paystack add-on' );
	$paystack = sunshine_get_payment_method_by_id( 'paystack' );
	$run      = $addon_gateways_run(
		function () use ( $paystack, $order ) {
			$paystack->redirect_to_payment( $order );
		},
		function ( $request ) {
			if ( '/transaction/initialize' === $request['path'] ) {
				return array(
					200,
					array(
						'status' => true,
						'data'   => array(
							'authorization_url' => 'https://checkout.paystack.com/spctotals',
							'access_code'       => 'spctotals',
							'reference'         => 'spctotalsref',
						),
					),
				);
			}
			return null;
		}
	);
	$t::same( 'https://checkout.paystack.com/spctotals', $run['redirect'], 'customer is sent to Paystack' );
	return $addon_gateways_wire( $addon_gateways_find( $run['sent'], '#^/transaction/initialize$#' ) );
};

$t::test(
	'Paystack: amount is the order total in cents (odd cents, taxed shipping)',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_odd, $addon_gateways_place, $addon_gateways_paystack_start ) {
		$addon_gateways_cart_odd();
		$addon_gateways_use( 'paystack' );
		$order = $addon_gateways_place( 'paystack', 73.92 );
		$sent  = $addon_gateways_paystack_start( $order );
		$t::same( '7392', isset( $sent['amount'] ) ? $sent['amount'] : null, 'amount in cents' );
		$t::same( 'USD', isset( $sent['currency'] ) ? $sent['currency'] : null, 'currency' );
	}
);

$t::test(
	'Paystack: discount code, gift card and taxed shipping',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_card, $addon_gateways_place, $addon_gateways_paystack_start ) {
		$addon_gateways_cart_card();
		$addon_gateways_use( 'paystack' );
		$order = $addon_gateways_place( 'paystack', 69 );
		$sent  = $addon_gateways_paystack_start( $order );
		$t::same( '6900', isset( $sent['amount'] ) ? $sent['amount'] : null, 'amount in cents' );
	}
);

// The fee is 3% of what is left after store credit: 3% of (100 - 20) = 2.40, so 82.40.
$t::test(
	'Paystack: store credit and a percent payment fee',
	function () use ( $t, $addon_gateways_use, $addon_gateways_credits, $addon_gateways_place, $addon_gateways_paystack_start ) {
		$addon_gateways_credits( 20 );
		$t::add( $t::product( 100, array( 'taxable' => false ) ) );
		$addon_gateways_use( 'paystack', 'percent', 3 );
		$order = $addon_gateways_place( 'paystack', 82.40 );
		$sent  = $addon_gateways_paystack_start( $order );
		$t::same( '8240', isset( $sent['amount'] ) ? $sent['amount'] : null, 'amount in cents' );
	}
);

// $110 with 10% tax inside the price, 10% off: the customer pays 99.
$t::test(
	'Paystack: prices include tax with a discount code',
	function () use ( $t, $addon_gateways_use, $addon_gateways_place, $addon_gateways_paystack_start ) {
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$addon_gateways_use( 'paystack' );
		$order = $addon_gateways_place( 'paystack', 99 );
		$sent  = $addon_gateways_paystack_start( $order );
		$t::same( '9900', isset( $sent['amount'] ) ? $sent['amount'] : null, 'amount in cents' );
	}
);

// A refund typed in on the order screen goes to Paystack in whole cents, and the order keeps a
// record of exactly what was refunded.
$t::test(
	'Paystack: part refund to the cent',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_odd, $addon_gateways_place, $addon_gateways_order_action, $addon_gateways_find, $addon_gateways_wire ) {
		$t::require_plugin( 'sunshine_payment_method_paystack', 'Paystack add-on' );
		$addon_gateways_cart_odd();
		$addon_gateways_use( 'paystack' );
		$order = $addon_gateways_place( 'paystack', 73.92 );
		$order->update_meta_value( 'paystack_transaction_id', '4099260516' );
		$paystack = sunshine_get_payment_method_by_id( 'paystack' );
		$run      = $addon_gateways_order_action(
			function () use ( $paystack, $order ) {
				$paystack->process_refund( $order->get_id() );
			},
			array( 'paystack_refund_amount' => '19.99' ),
			function ( $request ) {
				return '/refund' === $request['path'] ? array( 200, array( 'status' => true ) ) : null;
			}
		);
		$sent = $addon_gateways_wire( $addon_gateways_find( $run['sent'], '#^/refund$#' ) );
		$t::same( '1999', isset( $sent['amount'] ) ? $sent['amount'] : null, 'refund in cents' );
		$t::money( 19.99, sunshine_get_order( $order->get_id() )->get_refund_total(), 'refund on the order' );
	}
);

/*
 * QuickPay makes a payment, then a payment link for the amount. Amounts are in the smallest
 * unit of the currency, as a whole number (ISO 4217): cents for USD, whole yen for JPY.
 */

// Start a QuickPay payment. Returns array( payment request, link request ) as QuickPay gets them.
$addon_gateways_quickpay_start = function ( $order ) use ( $t, $addon_gateways_run, $addon_gateways_find, $addon_gateways_wire ) {
	$t::require_plugin( 'sunshine_payment_method_quickpay', 'QuickPay add-on' );
	$quickpay = sunshine_get_payment_method_by_id( 'quickpay' );
	$run      = $addon_gateways_run(
		function () use ( $quickpay, $order ) {
			$quickpay->redirect_to_payment( $order );
		},
		function ( $request ) {
			if ( '/payments' === $request['path'] && 'POST' === $request['method'] ) {
				return array( 201, array( 'id' => 90210 ) );
			}
			if ( '/payments/90210/link' === $request['path'] ) {
				return array( 200, array( 'url' => 'https://payment.quickpay.net/spctotals' ) );
			}
			return null;
		}
	);
	$t::same( 'https://payment.quickpay.net/spctotals', $run['redirect'], 'customer is sent to QuickPay' );
	return array(
		$addon_gateways_wire( $addon_gateways_find( $run['sent'], '#^/payments$#', 'POST' ) ),
		$addon_gateways_wire( $addon_gateways_find( $run['sent'], '#^/payments/90210/link$#' ) ),
	);
};

$t::test(
	'QuickPay: payment link is the order total (discount, gift card, shipping)',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_card, $addon_gateways_place, $addon_gateways_quickpay_start ) {
		$addon_gateways_cart_card();
		$addon_gateways_use( 'quickpay' );
		$order = $addon_gateways_place( 'quickpay', 69 );
		list( $payment, $link ) = $addon_gateways_quickpay_start( $order );
		$t::same( 'USD', isset( $payment['currency'] ) ? $payment['currency'] : null, 'currency' );
		$t::same( (string) $order->get_id(), isset( $payment['order_id'] ) ? $payment['order_id'] : null, 'order number' );
		$t::same( '6900', isset( $link['amount'] ) ? $link['amount'] : null, 'amount in cents' );
	}
);

// 100 + 10 tax - 22 discount (20% of 110) = 88, plus a fixed 2.00 fee = 90.
$t::test(
	'QuickPay: discount after tax and a fixed payment fee',
	function () use ( $t, $addon_gateways_use, $addon_gateways_place, $addon_gateways_quickpay_start ) {
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$addon_gateways_use( 'quickpay', 'amount', 2 );
		$order = $addon_gateways_place( 'quickpay', 90 );
		list( , $link ) = $addon_gateways_quickpay_start( $order );
		$t::same( '9000', isset( $link['amount'] ) ? $link['amount'] : null, 'amount in cents' );
	}
);

// Yen has no smaller unit. 2000 yen + 200 tax = 2200 yen is sent as 2200, not 220000.
$t::test(
	'QuickPay: yen is sent in whole yen',
	function () use ( $t, $addon_gateways_use, $addon_gateways_place, $addon_gateways_quickpay_start ) {
		$t::option( 'currency', 'JPY' );
		$t::option( 'country', 'JP' );
		$t::tax( 10 );
		$t::add( $t::product( 1000 ), 2 );
		$addon_gateways_use( 'quickpay' );
		$order = $addon_gateways_place( 'quickpay', 2200 );
		list( $payment, $link ) = $addon_gateways_quickpay_start( $order );
		$t::same( 'JPY', isset( $payment['currency'] ) ? $payment['currency'] : null, 'currency' );
		$t::same( '2200', isset( $link['amount'] ) ? $link['amount'] : null, 'amount in yen' );
	}
);

// Capture or refund part of a QuickPay payment from the order screen. Returns what QuickPay got.
$addon_gateways_quickpay_action = function ( $order, $action, $amount ) use ( $addon_gateways_order_action, $addon_gateways_find, $addon_gateways_wire ) {
	$quickpay = sunshine_get_payment_method_by_id( 'quickpay' );
	$run      = $addon_gateways_order_action(
		function () use ( $quickpay, $order, $action ) {
			if ( 'capture' === $action ) {
				$quickpay->process_capture( $order->get_id() );
			} else {
				$quickpay->process_refund( $order->get_id() );
			}
		},
		array( 'quickpay_' . $action . '_amount' => $amount ),
		function ( $request ) {
			return preg_match( '#^/payments/90210/(capture|refund)$#', $request['path'] ) ? array( 202, array( 'id' => 90210 ) ) : null;
		}
	);
	return $addon_gateways_wire( $addon_gateways_find( $run['sent'], '#^/payments/90210/' . $action . '$#' ) );
};

$t::test(
	'QuickPay: part capture to the cent',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_odd, $addon_gateways_place, $addon_gateways_quickpay_action ) {
		$t::require_plugin( 'sunshine_payment_method_quickpay', 'QuickPay add-on' );
		$addon_gateways_cart_odd();
		$addon_gateways_use( 'quickpay' );
		$order = $addon_gateways_place( 'quickpay', 73.92 );
		$order->update_meta_value( 'quickpay_payment_id', '90210' );
		$sent = $addon_gateways_quickpay_action( $order, 'capture', '19.99' );
		$t::same( '1999', isset( $sent['amount'] ) ? $sent['amount'] : null, 'capture in cents' );
		$t::money( 19.99, sunshine_get_order( $order->get_id() )->get_meta_value( 'quickpay_captured' ), 'captured so far' );
	}
);

// QuickPay lets a payment be captured in parts. The order keeps how much has been captured so
// far, so the order screen knows whether there is still money to capture: 10.00 then 5.50 is
// 15.50 captured, not 5.50.
$t::test(
	'QuickPay: two part captures add up',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_odd, $addon_gateways_place, $addon_gateways_quickpay_action ) {
		$t::require_plugin( 'sunshine_payment_method_quickpay', 'QuickPay add-on' );
		$addon_gateways_cart_odd();
		$addon_gateways_use( 'quickpay' );
		$order = $addon_gateways_place( 'quickpay', 73.92 );
		$order->update_meta_value( 'quickpay_payment_id', '90210' );
		$first  = $addon_gateways_quickpay_action( $order, 'capture', '10.00' );
		$second = $addon_gateways_quickpay_action( $order, 'capture', '5.50' );
		$t::same( '1000', isset( $first['amount'] ) ? $first['amount'] : null, 'first capture in cents' );
		$t::same( '550', isset( $second['amount'] ) ? $second['amount'] : null, 'second capture in cents' );
		$t::money( 15.50, sunshine_get_order( $order->get_id() )->get_meta_value( 'quickpay_captured' ), 'captured so far' );
	}
);

$t::test(
	'QuickPay: full refund is the order total',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_odd, $addon_gateways_place, $addon_gateways_quickpay_action ) {
		$t::require_plugin( 'sunshine_payment_method_quickpay', 'QuickPay add-on' );
		$addon_gateways_cart_odd();
		$addon_gateways_use( 'quickpay' );
		$order = $addon_gateways_place( 'quickpay', 73.92 );
		$order->update_meta_value( 'quickpay_payment_id', '90210' );
		// Nothing typed in: the order screen refunds everything not yet refunded.
		$sent = $addon_gateways_quickpay_action( $order, 'refund', '' );
		$t::same( '7392', isset( $sent['amount'] ) ? $sent['amount'] : null, 'refund in cents' );
		$t::money( 73.92, sunshine_get_order( $order->get_id() )->get_refund_total(), 'refund on the order' );
	}
);

/*
 * PayFast is a form posted from the customer's browser. The amount is in rand with decimals,
 * and a signature (md5 of the fields) proves the fields were not changed on the way.
 */

// Build the PayFast form for the order. Returns its fields, signature included.
$addon_gateways_payfast_form = function ( $order ) use ( $t, $addon_gateways_run ) {
	$t::require_plugin( 'sunshine_payment_method_payfast', 'PayFast add-on' );
	$payfast = sunshine_get_payment_method_by_id( 'payfast' );
	$fields  = null;
	$grab    = function ( $args ) use ( &$fields ) {
		$fields = $args;
		throw new SPC_Totals_Test_Done();
	};
	add_filter( 'sunshine_payfast_args', $grab, PHP_INT_MAX );
	try {
		$addon_gateways_run(
			function () use ( $payfast, $order ) {
				$payfast->redirect_to_payment( $order );
			}
		);
	} finally {
		remove_filter( 'sunshine_payfast_args', $grab, PHP_INT_MAX );
	}
	if ( empty( $fields ) ) {
		throw new Exception( 'No PayFast form was built' );
	}
	return $fields;
};

// PayFast's signature: every field that isn't blank, in form order, as key=urlencoded value
// joined with &, then &passphrase=... only when the store has a passphrase set, then md5.
$addon_gateways_payfast_signature = function ( $fields, $passphrase ) {
	$pairs = array();
	foreach ( $fields as $key => $value ) {
		if ( 'signature' !== $key && '' !== (string) $value ) {
			$pairs[] = $key . '=' . urlencode( trim( (string) $value ) );
		}
	}
	$text = implode( '&', $pairs );
	if ( '' !== (string) $passphrase ) {
		$text .= '&passphrase=' . urlencode( trim( $passphrase ) );
	}
	return md5( $text );
};

$addon_gateways_payfast_use = function ( $passphrase = 'sunshine totals' ) use ( $t ) {
	$t::option( 'currency', 'ZAR' );
	$t::option( 'payfast_merchant_id_test', '10000100' );
	$t::option( 'payfast_merchant_key_test', '46f0cd694581a' );
	$t::option( 'payfast_passphrase_test', $passphrase );
};

$t::test(
	'PayFast: amount is the order total (discount, gift card, taxed shipping)',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_card, $addon_gateways_place, $addon_gateways_payfast_use, $addon_gateways_payfast_form ) {
		$addon_gateways_payfast_use();
		$addon_gateways_cart_card();
		$addon_gateways_use( 'payfast' );
		$order  = $addon_gateways_place( 'payfast', 69 );
		$fields = $addon_gateways_payfast_form( $order );
		$t::money( 69, $fields['amount'], 'amount' );
		$t::same( (string) $order->get_id(), (string) $fields['m_payment_id'], 'order number' );
	}
);

// 73.92 + 3% fee (2.2176 -> 2.22) = 76.14.
$t::test(
	'PayFast: odd cents and a percent payment fee',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_odd, $addon_gateways_place, $addon_gateways_payfast_use, $addon_gateways_payfast_form ) {
		$addon_gateways_payfast_use();
		$addon_gateways_cart_odd();
		$addon_gateways_use( 'payfast', 'percent', 3 );
		$order  = $addon_gateways_place( 'payfast', 76.14 );
		$fields = $addon_gateways_payfast_form( $order );
		$t::money( 76.14, $fields['amount'], 'amount' );
	}
);

// $110 with tax inside, 10% off = 99, minus 20 store credit = 79.
$t::test(
	'PayFast: prices include tax with a discount code and store credit',
	function () use ( $t, $addon_gateways_use, $addon_gateways_credits, $addon_gateways_place, $addon_gateways_payfast_use, $addon_gateways_payfast_form ) {
		$addon_gateways_payfast_use();
		$addon_gateways_credits( 20 );
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$addon_gateways_use( 'payfast' );
		$order  = $addon_gateways_place( 'payfast', 79 );
		$fields = $addon_gateways_payfast_form( $order );
		$t::money( 79, $fields['amount'], 'amount' );
	}
);

// PayFast refuses a payment whose signature doesn't match the fields, so the amount never gets
// charged. A passphrase is optional in PayFast; it only goes into the signature when one is set.
$t::test(
	'PayFast: signature matches the amount sent, with and without a passphrase',
	function () use ( $t, $addon_gateways_use, $addon_gateways_place, $addon_gateways_payfast_use, $addon_gateways_payfast_form, $addon_gateways_payfast_signature ) {
		$addon_gateways_payfast_use( 'sunshine totals' );
		$t::add( $t::product( 49.95, array( 'taxable' => false ) ) );
		$addon_gateways_use( 'payfast' );
		$order  = $addon_gateways_place( 'payfast', 49.95 );
		$fields = $addon_gateways_payfast_form( $order );
		$t::same( $addon_gateways_payfast_signature( $fields, 'sunshine totals' ), $fields['signature'], 'signature with a passphrase' );

		$addon_gateways_payfast_use( '' );
		$fields = $addon_gateways_payfast_form( $order );
		$t::same( $addon_gateways_payfast_signature( $fields, '' ), $fields['signature'], 'signature with no passphrase' );
	}
);

/*
 * Systempay is a form posted from the customer's browser. vads_amount is in the smallest unit
 * of the currency (cents for euro and dollar, whole yen for yen), as a whole number, and the
 * currency is its ISO 4217 number (840 for USD, 392 for JPY, 978 for EUR).
 */

// The Systempay form fields for the order.
$addon_gateways_systempay_form = function ( $order ) use ( $t ) {
	$systempay = sunshine_get_payment_method_by_id( 'systempay' );
	if ( ! $systempay || ! class_exists( 'SPC_Payment_Method_SystemPay' ) ) {
		$t::skip( 'Systempay add-on is not active' );
	}
	$build = new ReflectionMethod( $systempay, 'get_payment_args' );
	$build->setAccessible( true );
	return $build->invoke( $systempay, $order );
};

$t::test(
	'Systempay: amount is the order total in cents (discount, gift card, shipping)',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_card, $addon_gateways_place, $addon_gateways_systempay_form ) {
		$addon_gateways_cart_card();
		$addon_gateways_use( 'systempay' );
		$order  = $addon_gateways_place( 'systempay', 69 );
		$fields = $addon_gateways_systempay_form( $order );
		$t::same( '6900', (string) $fields['vads_amount'], 'amount in cents' );
		$t::same( '840', (string) $fields['vads_currency'], 'currency number for USD' );
	}
);

$t::test(
	'Systempay: odd cents are sent as whole cents',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_odd, $addon_gateways_place, $addon_gateways_systempay_form ) {
		$addon_gateways_cart_odd();
		$addon_gateways_use( 'systempay' );
		$order  = $addon_gateways_place( 'systempay', 73.92 );
		$fields = $addon_gateways_systempay_form( $order );
		$t::same( '7392', (string) $fields['vads_amount'], 'amount in cents' );
	}
);

// Systempay's product lines use the same unit as vads_amount (the smallest unit of the
// currency). The one line Sunshine sends is the whole order, so it should match vads_amount.
$t::test(
	'Systempay: the order line is in cents like the total',
	function () use ( $t, $addon_gateways_use, $addon_gateways_cart_card, $addon_gateways_place, $addon_gateways_systempay_form ) {
		$addon_gateways_cart_card();
		$addon_gateways_use( 'systempay' );
		$order  = $addon_gateways_place( 'systempay', 69 );
		$fields = $addon_gateways_systempay_form( $order );
		$t::same( '1', (string) $fields['vads_product_qty0'], 'one line' );
		$t::same( '6900', (string) $fields['vads_product_amount0'], 'line amount in cents' );
	}
);

// $110 with tax inside = 110, 10% off = 99, plus a 3% fee (2.97) = 101.97.
$t::test(
	'Systempay: prices include tax with a discount code and a percent fee',
	function () use ( $t, $addon_gateways_use, $addon_gateways_place, $addon_gateways_systempay_form ) {
		$t::option( 'currency', 'EUR' );
		$t::tax( 10 );
		$t::option( 'price_has_tax', 'yes' );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$addon_gateways_use( 'systempay', 'percent', 3 );
		$order  = $addon_gateways_place( 'systempay', 101.97 );
		$fields = $addon_gateways_systempay_form( $order );
		$t::same( '10197', (string) $fields['vads_amount'], 'amount in cents' );
		$t::same( '978', (string) $fields['vads_currency'], 'currency number for EUR' );
	}
);

// 2000 yen + 200 tax = 2200 yen, sent as 2200, not 220000.
$t::test(
	'Systempay: yen is sent in whole yen',
	function () use ( $t, $addon_gateways_use, $addon_gateways_place, $addon_gateways_systempay_form ) {
		$t::option( 'currency', 'JPY' );
		$t::option( 'country', 'JP' );
		$t::tax( 10 );
		$t::add( $t::product( 1000 ), 2 );
		$addon_gateways_use( 'systempay' );
		$order  = $addon_gateways_place( 'systempay', 2200 );
		$fields = $addon_gateways_systempay_form( $order );
		$t::same( '2200', (string) $fields['vads_amount'], 'amount in yen' );
		$t::same( '392', (string) $fields['vads_currency'], 'currency number for JPY' );
	}
);

/*
 * Mercado Pago (Checkout Pro) sends the customer to Mercado Pago with a "preference": one line
 * for the whole order, priced at the order total.
 */

// 100 + 10 tax - 22 discount after tax = 88, minus 20 store credit = 68.
$t::test(
	'Mercado Pago Pro: preference is the order total (discount after tax, store credit)',
	function () use ( $t, $addon_gateways_use, $addon_gateways_credits, $addon_gateways_place, $addon_gateways_run, $addon_gateways_find, $addon_gateways_wire ) {
		$t::require_plugin( 'sunshine_payment_method_mercadopago', 'Mercado Pago add-on' );
		$addon_gateways_credits( 20 );
		$t::tax( 10 );
		$t::option( 'discount_after_tax', 1 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$t::option( 'mercadopagopro_access_token_test', 'TEST-spctotals' );
		$addon_gateways_use( 'mercadopagopro' );
		$order = $addon_gateways_place( 'mercadopagopro', 68 );

		$gateway = sunshine_get_payment_method_by_id( 'mercadopagopro' );
		$run     = $addon_gateways_run(
			function () use ( $gateway, $order ) {
				$gateway->process_payment( $order );
			},
			function ( $request ) {
				if ( '/checkout/preferences' === $request['path'] ) {
					return array(
						201,
						array(
							'id'         => 'pref-spctotals',
							'init_point' => 'https://www.mercadopago.com/checkout/v1/redirect?pref_id=spctotals',
						),
					);
				}
				return null;
			}
		);
		$preference = $addon_gateways_wire( $addon_gateways_find( $run['sent'], '#^/checkout/preferences$#' ) );
		$items      = isset( $preference['items'] ) ? $preference['items'] : array();
		$t::same( 1, count( $items ), 'one line' );
		$t::money( 68, isset( $items[0]['unit_price'] ) ? $items[0]['unit_price'] : null, 'line price' );
		$t::same( 1, isset( $items[0]['quantity'] ) ? $items[0]['quantity'] : null, 'line quantity' );
		$t::same( 'USD', isset( $items[0]['currency_id'] ) ? $items[0]['currency_id'] : null, 'currency' );
		$t::same( (string) $order->get_id(), isset( $preference['external_reference'] ) ? (string) $preference['external_reference'] : null, 'order number' );
	}
);

// A Mercado Pago account only takes its own country's currency. Asked for BRL by an Argentine
// account, Mercado Pago switches the payment to ARS at the same number, so a BRL 150 order would
// be paid with ARS 150. The customer must not be sent to pay that.
$t::test(
	'Mercado Pago Pro: an account that switches the currency does not send the customer to pay',
	function () use ( $t, $addon_gateways_use, $addon_gateways_place, $addon_gateways_run ) {
		$t::require_plugin( 'sunshine_payment_method_mercadopago', 'Mercado Pago add-on' );
		$t::option( 'currency', 'BRL' );
		$t::add( $t::product( 150 ) );
		$t::option( 'mercadopagopro_access_token_test', 'TEST-spctotals' );
		$addon_gateways_use( 'mercadopagopro' );
		$order   = $addon_gateways_place( 'mercadopagopro', 150 );
		$gateway = sunshine_get_payment_method_by_id( 'mercadopagopro' );
		$clear   = function () {
			$errors = new ReflectionProperty( SPC()->cart, 'errors' );
			$errors->setAccessible( true );
			$errors->setValue( SPC()->cart, array() );
		};

		$reply = function ( $currency ) {
			return function ( $request ) use ( $currency ) {
				if ( '/checkout/preferences' !== $request['path'] ) {
					return null;
				}
				$sent = json_decode( $request['body'], true );
				$sent['items'][0]['currency_id'] = $currency;
				return array(
					201,
					array(
						'id'         => 'pref-spctotals',
						'init_point' => 'https://www.mercadopago.com/checkout/v1/redirect?pref_id=spctotals',
						'items'      => $sent['items'],
					),
				);
			};
		};

		$clear();
		$run = $addon_gateways_run(
			function () use ( $gateway, $order ) {
				$gateway->process_payment( $order );
			},
			$reply( 'ARS' )
		);
		$t::same( null, $run['redirect'], 'customer is not sent to pay in ARS' );
		$errors = implode( ' ', (array) SPC()->cart->get_errors() );
		$t::same( true, false !== strpos( $errors, 'BRL' ), 'customer is told Mercado Pago cannot take BRL (got: ' . $errors . ')' );
		$log = wp_json_encode( sunshine_get_order( $order->get_id() )->get_log() );
		$t::same( true, false !== strpos( $log, 'only takes payments in ARS' ), 'order note says which currency the account takes' );
		$t::same( 'pending', sunshine_get_order( $order->get_id() )->get_status(), 'order stays unpaid' );

		$clear();
		$run = $addon_gateways_run(
			function () use ( $gateway, $order ) {
				$gateway->process_payment( $order );
			},
			$reply( 'BRL' )
		);
		$t::same( true, ! empty( $run['redirect'] ) && false !== strpos( $run['redirect'], 'mercadopago.com' ), 'same currency still goes to Mercado Pago' );
		$clear();
	}
);
