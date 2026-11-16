<?php

/*
Returns array key => array( 'id' => X, 'active' => 1/0 )
*/
function sunshine_get_shipping_methods() {
	$shipping_methods = apply_filters( 'sunshine_shipping_methods', array() );
	return $shipping_methods;
}

function sunshine_get_available_shipping_methods() {
	$shipping_methods = SPC()->get_option( 'shipping_methods' );
	if ( empty( $shipping_methods ) ) {
		return array();
	}
	$shipping_methods = apply_filters( 'sunshine_available_shipping_methods', $shipping_methods );
	// validate we have good shipping methods.
	if ( ! empty( $shipping_methods ) ) {
		$base_shipping_methods = sunshine_get_shipping_methods();
		foreach ( $shipping_methods as $key => $shipping_method ) {
			if ( ! array_key_exists( $shipping_method['id'], $base_shipping_methods ) ) {
				unset( $shipping_methods[ $key ] );
			}
		}
	}
	return $shipping_methods;
}

function sunshine_get_active_shipping_methods() {
	$shipping_methods = sunshine_get_available_shipping_methods();
	if ( empty( $shipping_methods ) || ! is_array( $shipping_methods ) ) {
		return false;
	}
	$final_shipping_methods = array();
	foreach ( $shipping_methods as $instance => $shipping_method ) {
		if ( ! empty( $shipping_method['active'] ) ) {
			$final_shipping_methods[ $instance ] = $shipping_method;
		}
	}
	$final_shipping_methods = apply_filters( 'sunshine_active_shipping_methods', $final_shipping_methods );
	return $final_shipping_methods;
}

function sunshine_get_allowed_shipping_methods() {
	$shipping_methods = sunshine_get_active_shipping_methods();
	if ( empty( $shipping_methods ) || ! is_array( $shipping_methods ) ) {
		return array();
	}
	$final_shipping_methods = array();
	foreach ( $shipping_methods as $instance => $shipping_method ) {
		$this_shipping_method = sunshine_get_shipping_method_by_instance( $instance );
		if ( $this_shipping_method && $this_shipping_method->is_allowed() ) {
			$final_shipping_methods[ $instance ] = $shipping_method;
		}
	}
	$final_shipping_methods = apply_filters( 'sunshine_allowed_shipping_methods', $final_shipping_methods );
	return $final_shipping_methods;
}

/**
 * The allowed shipping methods that ship to an address: the rates on the Shipping Method step.
 * Pickup locations are left out, since checkout offers them as their own delivery methods.
 *
 * @since 3.8.3
 * @param array|null $allowed_shipping_methods What sunshine_get_allowed_shipping_methods() returned,
 *                                             when the caller already has it. Worked out if not.
 * @return array Instance ID => shipping method object.
 */
function sunshine_get_allowed_ship_to_address_methods( $allowed_shipping_methods = null ) {
	if ( null === $allowed_shipping_methods ) {
		$allowed_shipping_methods = sunshine_get_allowed_shipping_methods();
	}
	$ship_methods = array();
	foreach ( array_keys( (array) $allowed_shipping_methods ) as $instance_id ) {
		$method = sunshine_get_shipping_method_by_instance( $instance_id );
		if ( $method && $method->needs_shipping_address() ) {
			$ship_methods[ $instance_id ] = $method;
		}
	}
	return $ship_methods;
}

function sunshine_get_shipping_method_by_id( $id, $instance_id = 0 ) {
	$shipping_methods = sunshine_get_shipping_methods();
	if ( ! empty( $shipping_methods ) && is_array( $shipping_methods ) && array_key_exists( $id, $shipping_methods ) ) {
		$shipping_method = $shipping_methods[ $id ];
		$class           = $shipping_method['class'];
		if ( class_exists( $class ) ) {
			// TODO: Are shipping classes getting called repeatedly because of this here? Make like payment methods
			return new $class( $instance_id );
		}
	}
	return false;
}

function sunshine_get_shipping_method_by_instance( $instance_id ) {
	$shipping_methods = sunshine_get_available_shipping_methods();
	if ( ! empty( $shipping_methods ) && is_array( $shipping_methods ) && array_key_exists( $instance_id, $shipping_methods ) ) {
		$shipping_method_basic = $shipping_methods[ $instance_id ];
		if ( ! empty( $shipping_method_basic['id'] ) ) {
			return sunshine_get_shipping_method_by_id( $shipping_method_basic['id'], $instance_id );
		}
	}
	return false;
}

function sunshine_get_selected_shipping_method() {
	$instance = SPC()->cart->get_checkout_data_item( 'shipping_method' );
	if ( $instance ) {
		$allowed_shipping_methods = sunshine_get_allowed_shipping_methods();
		if ( array_key_exists( $instance, $allowed_shipping_methods ) ) {
			return sunshine_get_shipping_method_by_instance( $instance );
		}
	}
	return false;
}
