<?php
/**
 * Totals the customer reads after ordering: the order page in their account, the invoice and
 * the emails. The rows of each totals table are read from the real templates, and must add up
 * to the Order Total shown under them. Also: changing an order's status never changes its money.
 */

$t = 'SPC_Totals_Test';

// Same money format on every run: $1,234.56, no price suffix.
$orders_after_display_format = function () use ( $t ) {
	$t::option( 'price_suffix', '' );
	$t::option( 'currency_symbol_position', 'left' );
	$t::option( 'currency_thousands_separator', ',' );
	$t::option( 'currency_decimal_separator', '.' );
};

// The amount a customer reads in a formatted price.
$orders_after_display_amount = function ( $text ) {
	$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
	if ( ! preg_match( '/(-?)[^0-9\-]*([0-9][0-9,]*(?:\.[0-9]+)?)/', $text, $match ) ) {
		return null;
	}
	$amount = (float) str_replace( ',', '', $match[2] );
	return ( '-' === $match[1] ) ? -$amount : $amount;
};

// Each row of a totals table: its id/class, its label and the amount shown, leaving out a
// struck-through old price and the "includes $x tax" note. Rows with no amount are skipped.
$orders_after_display_rows = function ( $html, $rows_xpath ) use ( $orders_after_display_amount ) {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
	libxml_clear_errors();
	$xpath = new DOMXPath( $doc );
	$rows  = array();
	foreach ( $xpath->query( $rows_xpath ) as $tr ) {
		// The amount is in the last cell (order lines have an image and name cell before it).
		$tds = $xpath->query( './td', $tr );
		$td  = $tds->length ? $tds->item( $tds->length - 1 ) : null;
		$th  = $xpath->query( './th', $tr )->item( 0 );
		if ( ! $td ) {
			continue;
		}
		$remove = array();
		foreach ( $xpath->query( './/s | .//*[contains(@class, "tax--explain")]', $td ) as $node ) {
			$remove[] = $node;
		}
		foreach ( $remove as $node ) {
			$node->parentNode->removeChild( $node );
		}
		$amount = $orders_after_display_amount( $td->textContent );
		if ( null === $amount ) {
			continue;
		}
		$rows[] = array(
			'key'    => trim( $tr->getAttribute( 'id' ) . ' ' . $tr->getAttribute( 'class' ) ),
			'label'  => $th ? trim( preg_replace( '/\s+/', ' ', $th->textContent ) ) : '',
			'shown'  => trim( preg_replace( '/\s+/', ' ', $td->textContent ) ),
			'amount' => $amount,
		);
	}
	return $rows;
};

// The amount in the first row whose id/class contains $key.
$orders_after_display_row = function ( $rows, $key ) {
	foreach ( $rows as $row ) {
		if ( false !== strpos( $row['key'], $key ) ) {
			return $row['amount'];
		}
	}
	return null;
};

// Every row above the total, added up as the customer reads it, must equal the total.
// "Credits Applied" is taken off whether or not it is shown with a minus sign.
$orders_after_display_adds_up = function ( $rows, $label ) use ( $t ) {
	$sum   = 0;
	$total = null;
	foreach ( $rows as $row ) {
		if ( preg_match( '/(^|[\s-])total($|\s)/', $row['key'] ) ) {
			$total = $row['amount'];
			continue;
		}
		$sum += ( false !== strpos( $row['key'], 'credits' ) ) ? -abs( $row['amount'] ) : $row['amount'];
	}
	$shown = array();
	foreach ( $rows as $row ) {
		$shown[] = $row['label'] . ' ' . $row['shown'];
	}
	$t::money( $total, $sum, $label . ' rows add up to the total (' . implode( ' | ', $shown ) . ')' );
};

// Run $body with web requests and emails caught, so status changes reach nothing outside.
$orders_after_display_offline = function ( $body ) {
	$http = function () {
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $http, PHP_INT_MAX );
	add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
	try {
		$body();
	} finally {
		remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
		remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
	}
};

$orders_after_display_order_page = function ( $order ) use ( $orders_after_display_rows ) {
	$html = sunshine_get_template_html( 'order/totals', array( 'order' => sunshine_get_order( $order->get_id() ) ) );
	return $orders_after_display_rows( $html, '//table[@id="sunshine--cart--totals--items"]//tr' );
};

$orders_after_display_invoice = function ( $order ) use ( $orders_after_display_rows ) {
	$html = sunshine_get_template_html( 'invoice/admin', array( 'order' => sunshine_get_order( $order->get_id() ) ) );
	return $orders_after_display_rows( $html, '//table[@id="sunshine--invoice--order-totals"]//tr' );
};

$orders_after_display_email = function ( $order, $template ) use ( $orders_after_display_rows ) {
	$html = sunshine_get_template_html( 'email/' . $template, array( 'order' => sunshine_get_order( $order->get_id() ) ) );
	return array(
		'totals' => $orders_after_display_rows( $html, '//tfoot//table//tr' ),
		'lines'  => $orders_after_display_rows( $html, '//tbody/tr[contains(@class, "order-item")]' ),
	);
};

// 100 + 10 shipping, 10% off the 100 = 10, tax 10% of (90 + 10) = 10, gift card 20.
// 100 + 10 + 10 - 20 - 10 = 90.
$t::test(
	'Order page: totals add up with tax, shipping, a discount code and a gift card',
	function () use ( $t, $orders_after_display_format, $orders_after_display_order_page, $orders_after_display_row, $orders_after_display_adds_up ) {
		$orders_after_display_format();
		$t::option( 'display_price', 'without_tax' );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$t::discount( 'percent-total', 10 );
		$t::gift_card( 20 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 90, $order->get_total(), 'order total' );

		$rows = $orders_after_display_order_page( $order );
		$t::money( 100, $orders_after_display_row( $rows, 'subtotal' ), 'subtotal shown' );
		$t::money( 10, $orders_after_display_row( $rows, 'shipping' ), 'shipping shown' );
		$t::money( 10, $orders_after_display_row( $rows, 'tax' ), 'tax shown' );
		$t::money( -20, $orders_after_display_row( $rows, 'fee' ), 'gift card shown' );
		$t::money( -10, $orders_after_display_row( $rows, 'discount' ), 'discount shown' );
		$t::money( 90, $orders_after_display_row( $rows, 'cart--total' ), 'order total shown' );
		$orders_after_display_adds_up( $rows, 'order page' );
	}
);

// Prices shown with tax: every row includes its tax and there is no tax row. The 20% discount
// takes 20 off the price and 2 off the tax, so it reads as 22: 110 - 22 = 88.
$t::test(
	'Order page: prices shown with tax and a discount code',
	function () use ( $t, $orders_after_display_format, $orders_after_display_order_page, $orders_after_display_row, $orders_after_display_adds_up ) {
		$orders_after_display_format();
		$t::option( 'display_price', 'with_tax' );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 88, $order->get_total(), 'order total' );

		$rows = $orders_after_display_order_page( $order );
		$t::money( 110, $orders_after_display_row( $rows, 'subtotal' ), 'subtotal shown with tax' );
		$t::money( -22, $orders_after_display_row( $rows, 'discount' ), 'discount shown with tax' );
		$t::money( 88, $orders_after_display_row( $rows, 'cart--total' ), 'order total shown' );
		$orders_after_display_adds_up( $rows, 'order page' );
	}
);

// Prices entered and shown with 10% tax: 110 less 10% = 99.
$t::test(
	'Order page: prices entered and shown with tax and a discount code',
	function () use ( $t, $orders_after_display_format, $orders_after_display_order_page, $orders_after_display_row, $orders_after_display_adds_up ) {
		$orders_after_display_format();
		$t::option( 'display_price', 'with_tax' );
		$t::option( 'price_has_tax', 'yes' );
		$t::tax( 10 );
		$t::add( $t::product( 110 ) );
		$t::discount( 'percent-total', 10 );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 99, $order->get_total(), 'order total' );

		$rows = $orders_after_display_order_page( $order );
		$t::money( 110, $orders_after_display_row( $rows, 'subtotal' ), 'subtotal shown' );
		$t::money( -11, $orders_after_display_row( $rows, 'discount' ), 'discount shown' );
		$t::money( 99, $orders_after_display_row( $rows, 'cart--total' ), 'order total shown' );
		$orders_after_display_adds_up( $rows, 'order page' );
	}
);

// 100 + 10 tax = 110, less 20% of 110 = 22, so 88.
$t::test(
	'Order page: discount after tax',
	function () use ( $t, $orders_after_display_format, $orders_after_display_order_page, $orders_after_display_row, $orders_after_display_adds_up ) {
		$orders_after_display_format();
		$t::option( 'display_price', 'without_tax' );
		$t::option( 'discount_after_tax', 1 );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::discount( 'percent-total', 20 );
		$t::calculate();
		$order = $t::place_order();

		$rows = $orders_after_display_order_page( $order );
		$t::money( 100, $orders_after_display_row( $rows, 'subtotal' ), 'subtotal shown' );
		$t::money( 10, $orders_after_display_row( $rows, 'tax' ), 'tax shown' );
		$t::money( -22, $orders_after_display_row( $rows, 'discount' ), 'discount shown' );
		$t::money( 88, $orders_after_display_row( $rows, 'cart--total' ), 'order total shown' );
		$orders_after_display_adds_up( $rows, 'order page' );
	}
);

// 100 + 10 shipping + 11 tax = 121, then 21 refunded: the total shown is what is left.
$t::test(
	'Order page: refund row and the total after the refund',
	function () use ( $t, $orders_after_display_format, $orders_after_display_order_page, $orders_after_display_row, $orders_after_display_adds_up, $orders_after_display_invoice ) {
		$orders_after_display_format();
		$t::option( 'display_price', 'without_tax' );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::flat_shipping( 10, true );
		$t::calculate();
		$order = $t::place_order();
		$order->add_refund( 21 );

		$rows = $orders_after_display_order_page( $order );
		$t::money( -21, $orders_after_display_row( $rows, 'refunds' ), 'refund shown' );
		$t::money( 100, $orders_after_display_row( $rows, 'cart--total' ), 'order total shown (121 - 21)' );
		$orders_after_display_adds_up( $rows, 'order page' );

		$rows = $orders_after_display_invoice( $order );
		$t::money( -21, $orders_after_display_row( $rows, 'refunds' ), 'invoice refund shown' );
		$orders_after_display_adds_up( $rows, 'invoice' );
	}
);

// 100 + 10 tax + 2 card fee = 112, less 20 in credits = 92.
$t::test(
	'Invoice and receipt email: credits and a payment fee',
	function () use ( $t, $orders_after_display_format, $orders_after_display_invoice, $orders_after_display_email, $orders_after_display_order_page, $orders_after_display_row, $orders_after_display_adds_up ) {
		$orders_after_display_format();
		$t::option( 'display_price', 'without_tax' );
		$customer_id = $t::customer();
		update_user_meta( $customer_id, 'sunshine_credits', '20' );
		SPC()->customer = new SPC_Customer( $customer_id );
		SPC()->cart->set_checkout_data_item( 'use_credits', 1 );
		$t::option( 'test_fee', 'amount' );
		$t::option( 'test_fee_amount', '2' );
		$t::option( 'test_fee_name', 'Card fee' );
		$t::tax( 10 );
		$t::add( $t::product( 100 ) );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 92, $order->get_total(), 'order total' );

		$invoice = $orders_after_display_invoice( $order );
		$t::money( 2, $orders_after_display_row( $invoice, 'fee' ), 'invoice card fee shown' );
		$t::money( 92, $orders_after_display_row( $invoice, 'sunshine-total' ), 'invoice total shown' );
		$orders_after_display_adds_up( $invoice, 'invoice' );

		$email = $orders_after_display_email( $order, 'customer-receipt' );
		$t::money( 92, $orders_after_display_row( $email['totals'], 'order-total' ), 'email total shown' );
		$orders_after_display_adds_up( $email['totals'], 'receipt email' );

		// The order page shows credits as money off, like discounts and refunds. The invoice and
		// email should read the same way.
		$t::money( -20, $orders_after_display_row( $orders_after_display_order_page( $order ), 'credits' ), 'order page credits shown' );
		$t::money( -20, $orders_after_display_row( $invoice, 'credits' ), 'invoice credits shown as money off' );
		$t::money( -20, $orders_after_display_row( $email['totals'], 'credits' ), 'receipt email credits shown as money off' );
	}
);

// Prices shown with tax: 3 prints at 19.99 with 7.25% tax is 59.97 + 4.35 = 64.32.
// Shipping 8.95 + 0.65 tax = 9.60. Total 73.92.
$t::test(
	'Receipt email: lines and totals when prices are shown with tax',
	function () use ( $t, $orders_after_display_format, $orders_after_display_email, $orders_after_display_row, $orders_after_display_adds_up ) {
		$orders_after_display_format();
		$t::option( 'display_price', 'with_tax' );
		$t::tax( 7.25 );
		$t::add( $t::product( 19.99 ), 3 );
		$t::flat_shipping( 8.95, true );
		$t::calculate();
		$order = $t::place_order();
		$t::money( 73.92, $order->get_total(), 'order total' );

		$email = $orders_after_display_email( $order, 'customer-receipt' );
		$t::money( 64.32, isset( $email['lines'][0] ) ? $email['lines'][0]['amount'] : null, 'line shown' );
		$t::money( 64.32, $orders_after_display_row( $email['totals'], 'order-subtotal' ), 'subtotal shown' );
		$t::money( 9.60, $orders_after_display_row( $email['totals'], 'order-shipping' ), 'shipping shown' );
		$t::money( 73.92, $orders_after_display_row( $email['totals'], 'order-total' ), 'total shown' );
		$orders_after_display_adds_up( $email['totals'], 'receipt email' );
	}
);

// The email sent when the photographer changes the order status lists the same lines.
$t::test(
	'Order status email: line totals when prices are shown with tax',
	function () use ( $t, $orders_after_display_format, $orders_after_display_email, $orders_after_display_row ) {
		$orders_after_display_format();
		$t::option( 'display_price', 'with_tax' );
		$t::tax( 7.25 );
		$t::add( $t::product( 19.99 ), 3 );
		$t::calculate();
		$order = $t::place_order();

		$email = $orders_after_display_email( $order, 'order-status' );
		$t::money( 64.32, isset( $email['lines'][0] ) ? $email['lines'][0]['amount'] : null, 'line shown (59.97 + 4.35 tax)' );
		$t::money( 64.32, $orders_after_display_row( $email['totals'], 'order-total' ), 'total shown' );
	}
);

// Moving an order through every status, the way the photographer does on the order screen,
// never changes what it cost.
$t::test(
	'Status changes do not change the order money',
	function () use ( $t, $orders_after_display_offline ) {
		$t::tax( 10 );
		$t::add( $t::product( 45 ), 2 );
		$t::add( $t::product( 12.50, array( 'taxable' => false ) ), 3 );
		$t::flat_shipping( 10, true );
		$t::discount( 'percent-total', 10 );
		$t::calculate();
		$order = $t::place_order();

		$snapshot = function () use ( $order ) {
			$saved = sunshine_get_order( $order->get_id() );
			$lines = array();
			foreach ( (array) $saved->get_items() as $item ) {
				$lines[] = array( $item->get_qty(), $item->get_price(), $item->get_discount(), $item->get_tax_total(), $item->get_total() );
			}
			return array(
				'subtotal' => $saved->get_subtotal(),
				'discount' => $saved->get_discount(),
				'shipping' => $saved->get_shipping(),
				'tax'      => $saved->get_tax(),
				'total'    => $saved->get_total(),
				'lines'    => $lines,
			);
		};
		$before = $snapshot();
		// 90 + 37.50 = 127.50, less 10% = 12.75. Tax 10% of (90 - 9) + 1 shipping = 9.10.
		$t::money( 133.85, $before['total'], 'order total (127.50 - 12.75 + 10 + 9.10)' );

		foreach ( array( 'new', 'processing', 'shipped', 'cancelled', 'refunded', 'pending' ) as $status ) {
			$orders_after_display_offline(
				function () use ( $order, $status ) {
					sunshine_get_order( $order->get_id() )->set_status( $status );
				}
			);
			$after = $snapshot();
			$t::same( $status, sunshine_get_order( $order->get_id() )->get_status(), 'status is ' . $status );
			foreach ( array( 'subtotal', 'discount', 'shipping', 'tax', 'total' ) as $key ) {
				$t::money( $before[ $key ], $after[ $key ], $key . ' after ' . $status );
			}
			$t::same( wp_json_encode( $before['lines'] ), wp_json_encode( $after['lines'] ), 'lines after ' . $status );
		}
	}
);

// Every Sunshine email is run through the bundled Emogrifier to inline its CSS. Its old way of
// keeping accented letters and symbols (mb_convert_encoding to 'HTML-ENTITIES') is deprecated
// on PHP 8.2 and will stop working, so it must not be used, and the letters must still come out.
$t::test(
	'Emails: putting the CSS inline keeps accented letters and symbols, with no PHP deprecation',
	function () use ( $t ) {
		if ( ! class_exists( 'Sunshine_Emogrifier' ) ) {
			include_once SUNSHINE_PHOTO_CART_PATH . 'includes/class-emogrifier.php';
		}
		$notices = array();
		set_error_handler(
			function ( $level, $message, $file, $line ) use ( &$notices ) {
				$notices[] = $message . ' (' . basename( $file ) . ':' . $line . ')';
				return true;
			}
		);
		try {
			$emogrifier = new Sunshine_Emogrifier( '<html><body><p class="note">Café 5,00 € 日本 &amp; 😀</p></body></html>', '.note{color:red}' );
			$html       = $emogrifier->emogrify();
		} finally {
			restore_error_handler();
		}
		$t::same( array(), $notices, 'PHP warnings and deprecations' );
		$t::same( true, false !== strpos( $html, 'style="color: red;"' ), 'CSS put inline' );
		$t::same( 'Café 5,00 € 日本 & 😀', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ), 'text reads the same' );
	}
);
