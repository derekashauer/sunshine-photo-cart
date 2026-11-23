<?php
/**
 * Customer notes and shortcodes in text visitors type.
 *
 * Text a visitor types (order notes, addresses, search words) is printed on Sunshine pages. On
 * block themes those pages are swapped into the_content before core runs shortcodes on it, so
 * anything in square brackets in that text used to run as a shortcode for anyone who opened the
 * page, including a guest with no account. And the checkout saved a note even when the store
 * had the Notes field turned off, so a guest could always get text onto the order page.
 *
 * Orders are placed through the real checkout step (init_order) with the Test payment method,
 * which leaves them waiting for payment, so no receipt is sent.
 */

$t = 'SPC_Totals_Test';

$notes_audio = 'Hello [audio src="https://example.com/a.mp3"] end';

// Place a one-item order. $posted is the note in the submit request, $step_note is a note sent
// through the payment step of the checkout, which keeps it in the session.
$notes_order = function ( $posted, $step_note = null ) use ( $t ) {
	$t::add( $t::product( 10 ) );
	$t::calculate();
	if ( null !== $step_note ) {
		SPC()->cart->process_section(
			'payment',
			array(
				'customer_notes' => $step_note,
				'payment_method' => 'test',
			)
		);
	}
	$_POST['customer_notes'] = $posted;
	try {
		$order = $t::place_order();
	} finally {
		unset( $_POST['customer_notes'] );
	}
	return $order;
};

$t::test(
	'A note sent with the order while the Notes field is off is not saved',
	function () use ( $t, $notes_order, $notes_audio ) {
		$t::option( 'general_fields', array( 'phone' ) );
		$t::option( 'required_general_fields', array() );
		$order = $notes_order( $notes_audio );
		$t::same( '', (string) $order->get_customer_notes(), 'customer notes' );
	}
);

$t::test(
	'A note sent through a checkout step while the Notes field is off is not saved',
	function () use ( $t, $notes_order, $notes_audio ) {
		$t::option( 'general_fields', array( 'phone' ) );
		$t::option( 'required_general_fields', array() );
		$order = $notes_order( '', $notes_audio );
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
	'Brackets in a note never run as a shortcode on the swapped-in order page',
	function () use ( $t, $notes_order, $notes_audio ) {
		$t::option( 'general_fields', array( 'notes' ) );
		$t::option( 'required_general_fields', array() );
		$order = $notes_order( $notes_audio );
		$t::same( $notes_audio, $order->get_customer_notes(), 'note saved as typed' );

		// Render the order page the way a block theme gets it, then run shortcodes on it the
		// way the_content does right after.
		$before                        = SPC()->frontend->current_order;
		SPC()->frontend->current_order = $order;
		try {
			$html = do_shortcode( SPC()->frontend->the_content( '' ) );
		} finally {
			SPC()->frontend->current_order = $before;
		}
		$t::same( false, strpos( $html, 'wp-audio-shortcode' ) !== false, 'audio player made from the note' );
		$t::same( true, strpos( $html, 'Hello &#91;audio src="https://example.com/a.mp3"&#93; end' ) !== false, 'note shown as plain text' );
	}
);
