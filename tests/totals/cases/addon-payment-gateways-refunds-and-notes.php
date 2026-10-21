<?php
/**
 * Authorize.net and Mercado Pago: refunds from the order screen, and the order notes written
 * when a Mercado Pago payment can't finish an order.
 *
 * - A full refund leaves the order's payment method alone. An order whose refunds take its
 *   total to 0 is not a free order, and the payment method is how the store finds the payment.
 * - Mercado Pago refunds carry an idempotency key that stays the same until a refund is
 *   recorded, so pressing Refund twice (a double click, a resent form) refunds only once.
 * - A Mercado Pago payment that names no order says so in plain words, in the store's language.
 *
 * Nothing reaches a payment service. Helpers are in addon-payment-gateways-helpers.php.
 */

$t = 'SPC_Totals_Test';

$t::test(
	'Authorize.net refund: a full refund keeps the payment method',
	function () use ( $t, $addon_gateways_use, $addon_gateways_place, $addon_gateways_authorizenet ) {
		$t::add( $t::product( 40, array( 'taxable' => false ) ) );
		$addon_gateways_use( 'authorizenet' );
		$order = $addon_gateways_place( 'authorizenet', 40 );
		$order->set_status( 'new' );
		$order->update_meta_value( 'authorizenet_last4', '1111' );
		$order->update_meta_value( 'authorizenet_exp_date', '2030-12' );
		$order->update_meta_value( 'authorizenet_transaction_id', '60000000001' );

		$requests = $addon_gateways_authorizenet(
			function ( $gateway ) use ( $order ) {
				$gateway->process_refund( $order->get_id() );
			}
		);
		$t::same( 'refundTransaction', isset( $requests[0]['transactionRequest']['transactionType'] ) ? $requests[0]['transactionRequest']['transactionType'] : null, 'refund sent' );
		$t::money( 40, isset( $requests[0]['transactionRequest']['amount'] ) ? $requests[0]['transactionRequest']['amount'] : null, 'whole total refunded' );

		clean_post_cache( $order->get_id() );
		wp_cache_delete( $order->get_id(), 'post_meta' );
		$saved = sunshine_get_order( $order->get_id() );
		$t::same( 'refunded', $saved->get_status(), 'order is Refunded' );
		$t::money( 40, $saved->get_refund_total(), 'refund recorded' );
		$t::same( 'authorizenet', $saved->get_payment_method(), 'payment method is still Authorize.net, not Free' );
	}
);

// Mercado Pago Pro with a paid $50 order and its Mercado Pago payment saved on it.
$mercadopago_paid_order = function () use ( $t, $addon_gateways_use, $addon_gateways_place ) {
	$t::require_plugin( 'sunshine_payment_method_mercadopago', 'Mercado Pago add-on' );
	$t::option( 'mercadopagopro_access_token_test', 'TEST-spctotals' );
	$addon_gateways_use( 'mercadopagopro' );
	$t::add( $t::product( 50, array( 'taxable' => false ) ) );
	$order = $addon_gateways_place( 'mercadopagopro', 50 );
	$order->set_status( 'new' );
	$order->update_meta_value( 'mercadopago_payment_id', '1234567890' );
	return sunshine_get_order( $order->get_id() );
};

$t::test(
	'Mercado Pago refund: the same refund sent twice has the same idempotency key',
	function () use ( $t, $mercadopago_paid_order, $addon_gateways_order_action ) {
		$order = $mercadopago_paid_order();
		$keys  = array();
		$grab  = function ( $pre, $args, $url ) use ( &$keys ) {
			if ( false !== strpos( $url, '/refunds' ) ) {
				$keys[] = isset( $args['headers']['X-Idempotency-Key'] ) ? $args['headers']['X-Idempotency-Key'] : '';
			}
			return $pre;
		};
		$refund = function ( $succeeds ) use ( $order, $addon_gateways_order_action ) {
			$addon_gateways_order_action(
				function () use ( $order ) {
					sunshine_get_payment_method_by_id( 'mercadopagopro' )->process_refund( $order->get_id() );
				},
				array( 'mercadopago_refund_amount' => '10' ),
				function ( $request ) use ( $succeeds ) {
					if ( 'api.mercadopago.com' !== $request['host'] ) {
						return null;
					}
					return $succeeds ? array( 201, array( 'id' => 555 ) ) : array( 500, array( 'message' => 'Try again' ) );
				}
			);
		};
		add_filter( 'pre_http_request', $grab, 10, 3 );
		try {
			$refund( false ); // Mercado Pago didn't answer, so the photographer presses Refund again.
			$refund( true );
			$refund( true ); // A second $10 refund, asked for on purpose.
		} finally {
			remove_filter( 'pre_http_request', $grab, 10 );
		}
		$t::same( 3, count( $keys ), 'three refund requests' );
		$t::same( true, '' !== $keys[0] && $keys[0] === $keys[1], 'the resent refund has the same key, so Mercado Pago makes it once' );
		$t::same( true, $keys[1] !== $keys[2], 'a new refund after one was recorded gets a new key' );
		clean_post_cache( $order->get_id() );
		wp_cache_delete( $order->get_id(), 'post_meta' );
		$t::money( 20, sunshine_get_order( $order->get_id() )->get_refund_total(), 'two refunds recorded' );
	}
);

// Run Mercado Pago Pro's check of a payment for the order, the way its return link and webhook
// do, with the payment Mercado Pago would report. Returns the order's notes about it.
$mercadopago_check_payment = function ( $order, $payment ) {
	$gateway = sunshine_get_payment_method_by_id( 'mercadopagopro' );
	$check   = new ReflectionMethod( $gateway, 'complete_order_payment' );
	$check->setAccessible( true );
	$finished = $check->invoke( $gateway, $order, $payment['id'], $payment );
	$notes    = array();
	foreach ( get_comments(
		array(
			'post_id' => $order->get_id(),
			'type'    => 'sunshine_order_log',
		)
	) as $comment ) {
		if ( false !== strpos( $comment->comment_content, 'Mercado Pago payment' ) ) {
			$notes[] = $comment->comment_content;
		}
	}
	return array( $finished, implode( "\n", $notes ) );
};

$t::test(
	'Mercado Pago Pro: a payment that names no order says so on the order',
	function () use ( $t, $addon_gateways_use, $addon_gateways_place, $mercadopago_check_payment ) {
		$t::require_plugin( 'sunshine_payment_method_mercadopago', 'Mercado Pago add-on' );
		$addon_gateways_use( 'mercadopagopro' );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$order = $addon_gateways_place( 'mercadopagopro', 50 );
		list( $finished, $notes ) = $mercadopago_check_payment(
			$order,
			array(
				'id'                 => 777,
				'status'             => 'approved',
				'currency_id'        => SPC()->get_option( 'currency' ),
				'transaction_amount' => 50,
			)
		);
		$t::same( false, $finished, 'order not finished' );
		$t::same( true, false !== strpos( $notes, 'the payment does not name an order' ), 'note says the payment names no order' );
		$t::same( false, strpos( $notes, 'order none' ), 'no made-up order "none"' );
	}
);

// The reason goes into a translated note and the store owner's email, so it is translated too.
$t::test(
	'Mercado Pago Pro: why a payment was refused is in the store\'s language',
	function () use ( $t, $addon_gateways_use, $addon_gateways_place, $mercadopago_check_payment ) {
		$t::require_plugin( 'sunshine_payment_method_mercadopago', 'Mercado Pago add-on' );
		$addon_gateways_use( 'mercadopagopro' );
		$t::add( $t::product( 50, array( 'taxable' => false ) ) );
		$order     = $addon_gateways_place( 'mercadopagopro', 50 );
		$translate = function ( $translation, $text, $domain ) {
			if ( 'sunshine-photo-cart' === $domain && 'amount is %1$s, order total is %2$s' === $text ) {
				return 'montant %1$s, total %2$s';
			}
			return $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );
		try {
			list( $finished, $notes ) = $mercadopago_check_payment(
				$order,
				array(
					'id'                 => 778,
					'status'             => 'approved',
					'external_reference' => (string) $order->get_id(),
					'currency_id'        => SPC()->get_option( 'currency' ),
					'transaction_amount' => 1,
				)
			);
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}
		$t::same( false, $finished, 'order not finished' );
		$t::same( true, false !== strpos( $notes, 'montant 1, total 50' ), 'the reason is translated' );
	}
);
