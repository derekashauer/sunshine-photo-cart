<?php
function sunshine_get_orders( $args = array(), $mode = 'live' ) {

	$orders = get_posts( sunshine_get_order_query_args( $args, $mode ) );
	return array_map( 'sunshine_get_order', $orders );

}

/**
 * Order IDs only, without building an SPC_Order for each one.
 *
 * Every SPC_Order loads and unserializes all of its post meta on construction,
 * so anything that only needs to count orders or drive a secondary query should
 * use this instead of sunshine_get_orders(). Takes the same arguments.
 */
function sunshine_get_order_ids( $args = array(), $mode = 'live' ) {

	$args           = sunshine_get_order_query_args( $args, $mode );
	$args['fields'] = 'ids';

	return array_map( 'intval', get_posts( $args ) );

}

/**
 * Translate the friendly sunshine_get_orders() arguments into WP_Query arguments.
 */
function sunshine_get_order_query_args( $args = array(), $mode = 'live' ) {

	$defaults = array(
		'nopaging'   => ( empty( $args['posts_per_page'] ) ) ? true : false,
		'date_query' => array( 'inclusive' => true ),
		'meta_query' => array(
			'mode' => array(
				'key'   => 'mode',
				'value' => $mode,
			),
		),
	);

	if ( $mode == 'all' ) {
		// Remove meta query for mode as we don't care what type it is.
		unset( $defaults['meta_query'] );
	}

	$args = wp_parse_args( $args, $defaults );

	$args['post_type'] = 'sunshine-order'; // Make sure we always get this post type

	if ( ! empty( $args['status'] ) ) {
		$args['tax_query'] = array(
			array(
				'taxonomy' => 'sunshine-order-status',
				'field'    => 'slug',
				'terms'    => $args['status'],
				'operator' => 'IN',
			),
		);
		unset( $args['status'] );
	}

	if ( ! empty( $args['year'] ) ) {
		$args['date_query']['year'] = $args['year'];
		unset( $args['year'] );
	}
	if ( ! empty( $args['month'] ) ) {
		$args['date_query']['month'] = $args['month'];
		unset( $args['month'] );
	}

	if ( ! empty( $args['before'] ) ) {
		if ( is_array( $args['before'] ) ) {
			$args['date_query']['inclusive'] = true;
			$args['date_query']['before']    = array(
				'year'  => ( ! empty( $args['before']['year'] ) ? $args['before']['year'] : '' ),
				'month' => ( ! empty( $args['before']['month'] ) ? $args['before']['month'] : '' ),
				'day'   => ( ! empty( $args['before']['day'] ) ? $args['before']['day'] : '' ),
			);
		}
		unset( $args['before'] );
	}

	if ( ! empty( $args['after'] ) ) {
		if ( is_array( $args['after'] ) ) {
			$args['date_query']['inclusive'] = true;
			$args['date_query']['after']     = array(
				'year'  => ( ! empty( $args['after']['year'] ) ? $args['after']['year'] : '' ),
				'month' => ( ! empty( $args['after']['month'] ) ? $args['after']['month'] : '' ),
				'day'   => ( ! empty( $args['after']['day'] ) ? $args['after']['day'] : '' ),
			);
		}
		unset( $args['after'] );
	}

	$args = apply_filters( 'sunshine_get_order_args', $args );

	return $args;

}

function sunshine_get_order( $order_id = 0 ) {
	return new SPC_Order( $order_id );
}

function sunshine_get_order_statuses( $format = 'key' ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'sunshine-order-status',
			'hide_empty' => false,
		)
	);
	if ( ! empty( $terms ) ) {
		$statuses = array();
		foreach ( $terms as $term ) {
			if ( $format == 'key' ) {
				$statuses[ $term->slug ] = $term->slug;
			} elseif ( $format == 'object' ) {
				$statuses[ $term->slug ] = new SPC_Order_Status( $term );
			}
		}
		return $statuses;
	}
	return false;
}

function sunshine_order_status_is_valid( $status ) {
	$statuses = sunshine_get_order_statuses();
	if ( ! empty( $statuses ) && is_array( $statuses ) && array_key_exists( $status, $statuses ) ) {
		return true;
	}
	return false;
}


function sunshine_get_order_status_by_id( $id ) {
	$statuses = sunshine_get_order_statuses( 'object' );
	if ( array_key_exists( $id, $statuses ) ) {
		return $statuses[ $id ];
	}
	return false;
}

function sunshine_order_statuses_needs_payment() {
	return apply_filters( 'sunshine_order_statuses_needs_payment', array( 'pending' ) );
}


function sunshine_order_statuses_paid() {
	return apply_filters( 'sunshine_order_statuses_paid', array( 'new', 'processing', 'pickup', 'shipped' ) );
}

function sunshine_order_is_paid( $status ) {
	$paid_statuses = sunshine_order_statuses_paid();
	if ( in_array( $status, $paid_statuses ) ) {
		return true;
	}
	return false;
}

function sunshine_order_statuses_completed() {
	return apply_filters( 'sunshine_order_statuses_paid', array( 'pickup', 'shipped' ) );
}

/**
 * Sums refund amounts across a set of orders.
 *
 * Refunds are stored on each order as the `refunds` postmeta — a serialized
 * array of refund records (amount, reason, date, tx_id). Pulls all refund
 * records for the given orders in one query and sums their amounts.
 *
 * Returns 0 for orders that have never been refunded. Used by the admin
 * dashboard, tracking telemetry, and any other revenue calculation that
 * needs to report NET revenue (gross paid minus refunds returned).
 *
 * @param int[] $order_ids
 *
 * @return float Total refunded amount across the given orders.
 */
function sunshine_get_orders_refund_total( array $order_ids ) {
	$order_ids = array_values( array_filter( array_map( 'intval', $order_ids ) ) );
	if ( empty( $order_ids ) ) {
		return 0.0;
	}

	global $wpdb;
	$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
	$rows         = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key = 'refunds' AND post_id IN ({$placeholders})",
			$order_ids
		)
	);

	$total = 0.0;
	foreach ( (array) $rows as $row ) {
		$refunds = maybe_unserialize( $row->meta_value );
		if ( ! is_array( $refunds ) ) {
			continue;
		}
		foreach ( $refunds as $refund ) {
			if ( is_array( $refund ) && isset( $refund['amount'] ) ) {
				$total += (float) $refund['amount'];
			}
		}
	}
	return $total;
}

function sunshine_order_is_completed( $status ) {
	$completed_statuses = sunshine_order_statuses_completed();
	if ( in_array( $status, $completed_statuses ) ) {
		return true;
	}
	return false;
}

function sunshine_generate_order_key( $key = '' ) {
	if ( '' === $key ) {
		$key = wp_generate_password( 13, false );
	}

	return 'spc_' . apply_filters( 'sunshine_generate_order_key', 'order_' . $key );
}

/**
 * Give back the store credit an order used when the order is refunded or cancelled.
 *
 * Credit is taken when an order is finished (post_processed), so only finished orders give
 * any back, and only once, even when the status changes between Refunded and Cancelled.
 *
 * @param string    $status The new order status.
 * @param SPC_Order $order  The order.
 */
function sunshine_order_return_credits( $status, $order ) {
	if ( ! in_array( $status, array( 'refunded', 'cancelled' ), true ) ) {
		return;
	}
	$credits = (float) $order->get_credits();
	if ( $credits <= 0 || ! $order->get_meta_value( 'post_processed' ) ) {
		return;
	}
	$customer = $order->get_customer();
	if ( empty( $customer ) ) {
		return;
	}
	// Unique meta: if two requests change the status at once, only one can add it.
	if ( ! add_post_meta( $order->get_id(), 'credits_returned', current_time( 'timestamp' ), true ) ) {
		return;
	}
	$customer->increase_credits( $credits );
	/* translators: %s is the store credit amount given back to the customer */
	$order->add_log( sprintf( __( '%s of store credit given back to the customer', 'sunshine-photo-cart' ), sunshine_price( $credits ) ) );
}
add_action( 'sunshine_order_status_change', 'sunshine_order_return_credits', 10, 2 );

/**
 * Whether an order counts toward sales totals (dashboard, weekly Summary email).
 *
 * Paid orders always count. A Refunded order counts too while money is left on it
 * after its refunds, because some payment methods mark an order Refunded even when
 * only part of it was given back.
 *
 * @param string $status               Order status slug.
 * @param float  $total_after_refunds  Order total less its refunds.
 *
 * @return bool
 */
function sunshine_order_counts_in_sales( $status, $total_after_refunds ) {
	if ( sunshine_order_is_paid( $status ) ) {
		return true;
	}
	return ( 'refunded' === $status && $total_after_refunds > 0 );
}

/**
 * The statuses an order can have and still count toward sales totals. Use with
 * sunshine_order_counts_in_sales() to leave out Refunded orders with nothing left.
 *
 * @return string[]
 */
function sunshine_order_statuses_sales() {
	return array_unique( array_merge( sunshine_order_statuses_paid(), array( 'refunded' ) ) );
}

/**
 * Forget the dashboard's saved sales numbers, so the next look at the dashboard
 * works them out again. Runs whenever an order is made, changes status or gets
 * a refund, wherever that happens (checkout, payment webhooks, admin).
 */
function sunshine_clear_dashboard_stats() {
	delete_transient( 'sunshine-dashboard-sales' );
}
add_action( 'sunshine_order_create', 'sunshine_clear_dashboard_stats' );
add_action( 'sunshine_order_status_change', 'sunshine_clear_dashboard_stats' );
add_action( 'sunshine_order_refund', 'sunshine_clear_dashboard_stats' );

/**
 * Remove an order's lines and their meta when the order itself is deleted.
 *
 * Runs wherever the order is deleted (admin, payment cancel links, emptying the
 * trash on a schedule, the REST API), not only in the admin. The line tables have no
 * foreign keys, so the meta rows have to be removed here too.
 *
 * @param int $post_id Post being deleted.
 */
function sunshine_delete_order_lines( $post_id ) {
	global $wpdb;
	if ( 'sunshine-order' !== get_post_type( $post_id ) ) {
		return;
	}
	$item_ids = $wpdb->get_col( $wpdb->prepare( "SELECT order_item_id FROM {$wpdb->prefix}sunshine_order_items WHERE order_id = %d", $post_id ) );
	if ( empty( $item_ids ) ) {
		return;
	}
	$placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}sunshine_order_itemmeta WHERE order_item_id IN ({$placeholders})", $item_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->delete( $wpdb->prefix . 'sunshine_order_items', array( 'order_id' => $post_id ), array( '%d' ) );
}
add_action( 'before_delete_post', 'sunshine_delete_order_lines' );
