<?php
/**
 * Session Fees: an order paid before Session Fees saved what it gave on the order.
 *
 * Those orders already gave the customer their store credit and download credits. When the
 * photographer moves one from one paid status to another (New to Shipped), it must not give
 * them again. Orders with included prints are spotted by the prints' order ID, but a session
 * that includes only credits leaves nothing like that behind.
 *
 * Helpers are in admin-order-changes-helpers.php.
 */

$t = 'SPC_Totals_Test';

$admin_order_changes_test(
	'Session Fees: an older paid order moved to another paid status doesn\'t give its credits again',
	function () use ( $t, $admin_order_changes_checkout, $admin_order_changes_save_status, $admin_order_changes_session, $admin_order_changes_credits ) {
		$customer_id            = $t::customer();
		list( $session, $date ) = $admin_order_changes_session( 100 );
		// Only store credit and download credits, no prints.
		update_post_meta(
			$session,
			'session_products',
			array(
				array(
					'product' => 'credits',
					'qty'     => '25',
				),
				array(
					'product' => 'download_credits',
					'qty'     => '3',
				),
			)
		);
		$t::add( $session );
		$order = $admin_order_changes_checkout();
		$t::same( true, $order->is_paid(), 'order is paid' );
		$t::money( 25, $admin_order_changes_credits( $customer_id ), '25.00 store credit once paid' );
		$t::same( 3, (int) get_user_meta( $customer_id, 'sunshine_download_credits', true ), '3 download credits once paid' );

		// The way an order paid before the update looks: nothing saved about what it gave.
		delete_post_meta( $order->get_id(), 'session_fees_granted' );

		$admin_order_changes_save_status( $order->get_id(), 'shipped' );
		$t::same( 'shipped', sunshine_get_order( $order->get_id() )->get_status(), 'order is Shipped' );
		$t::money( 25, $admin_order_changes_credits( $customer_id ), 'still 25.00 store credit' );
		$t::same( 3, (int) get_user_meta( $customer_id, 'sunshine_download_credits', true ), 'still 3 download credits' );

		// What it gave is now saved on the order, so cancelling takes it back and paying again
		// gives it again, once.
		$admin_order_changes_save_status( $order->get_id(), 'cancelled' );
		$t::money( 0, $admin_order_changes_credits( $customer_id ), 'store credit taken back on cancel' );
		$t::same( 0, (int) get_user_meta( $customer_id, 'sunshine_download_credits', true ), 'download credits taken back on cancel' );
		$admin_order_changes_save_status( $order->get_id(), 'new' );
		$t::money( 25, $admin_order_changes_credits( $customer_id ), '25.00 store credit after paying again' );
		$t::same( 3, (int) get_user_meta( $customer_id, 'sunshine_download_credits', true ), '3 download credits after paying again' );
	}
);
