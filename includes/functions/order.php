<?php
function sunshine_get_orders( $args = array(), $mode = 'live' ) {

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

	$orders = get_posts( $args );
	return array_map( 'sunshine_get_order', $orders );

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
