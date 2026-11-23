<?php
/**
 * Customer notes and shortcodes in text visitors type.
 *
 * Text a visitor types (order notes, addresses, search words) is printed on Sunshine pages. On
 * block themes those pages are swapped into the_content, and if the swap ran before core's
 * do_shortcode, anything in square brackets in that text would run as a shortcode for anyone
 * who opens the page, including a guest with no account. And the checkout used to save a note
 * even when the store had the Notes field turned off, so a guest could always get text onto the
 * order page.
 *
 * Orders are placed through the real checkout step (init_order) with the Test payment method,
 * which leaves them waiting for payment, so no receipt is sent.
 */

$t = 'SPC_Totals_Test';

// Place a one-item order with this note in the request, the way the checkout's submit sends it.
$notes_order = function ( $note ) use ( $t ) {
	$t::add( $t::product( 10 ) );
	$t::calculate();
	$_POST['customer_notes'] = $note;
	try {
		$order = $t::place_order();
	} finally {
		unset( $_POST['customer_notes'] );
	}
	return $order;
};

$t::test(
	'A note sent while the Notes field is off is not saved on the order',
	function () use ( $t, $notes_order ) {
		$t::option( 'general_fields', array( 'phone' ) );
		$t::option( 'required_general_fields', array() );
		$order = $notes_order( 'Hello [audio src="https://example.com/a.mp3"]' );
		$t::same( '', (string) $order->get_customer_notes(), 'customer notes' );
	}
);

$t::test(
	'A note sent while the Notes field is on is saved as typed',
	function () use ( $t, $notes_order ) {
		$t::option( 'general_fields', array( 'notes' ) );
		$t::option( 'required_general_fields', array() );
		$order = $notes_order( 'Please crop tight' );
		$t::same( 'Please crop tight', $order->get_customer_notes(), 'customer notes' );
	}
);

$t::test(
	'Sunshine pages swapped into the content run after shortcodes, so visitor text never runs as one',
	function () use ( $t ) {
		$swap = has_filter( 'the_content', array( SPC()->frontend, 'the_content' ) );
		if ( false === $swap ) {
			$t::skip( 'This site uses full Sunshine templates (classic theme), so nothing is swapped into the content' );
		}
		$shortcodes = has_filter( 'the_content', 'do_shortcode' );
		$t::same( true, $swap > $shortcodes, 'swap runs after do_shortcode (swap ' . $swap . ', shortcodes ' . $shortcodes . ')' );
	}
);
