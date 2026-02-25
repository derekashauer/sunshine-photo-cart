<?php

function sunshine_get_payment_methods() {
	$payment_methods = SPC()->payment_methods->get_payment_methods();

	$payment_methods_order = SPC()->get_option( 'payment_methods_order' );
	if ( $payment_methods_order ) {
		$sorted_array = array_merge( array_flip( $payment_methods_order ), $payment_methods );
		$payment_methods = array_intersect_key( $sorted_array, $payment_methods );
	}

	return $payment_methods;
}

function sunshine_get_active_payment_methods() {
	$payment_methods = sunshine_get_payment_methods();
	if ( empty( $payment_methods ) || ! is_array( $payment_methods ) ) {
		return false;
	}
	$final_payment_methods = array();
	foreach ( $payment_methods as $id => $payment_method ) {
		$payment_method_class = sunshine_get_payment_method_by_id( $id );
		if ( $payment_method_class->is_active() ) {
			$final_payment_methods[ $id ] = $payment_method_class;
		}
	}
	return $final_payment_methods;
}

function sunshine_get_allowed_payment_methods() {
	$payment_methods = sunshine_get_payment_methods();
	if ( empty( $payment_methods ) || ! is_array( $payment_methods ) ) {
		return false;
	}
	$final_payment_methods = array();
	foreach ( $payment_methods as $id => $payment_method ) {
		$payment_method_class = sunshine_get_payment_method_by_id( $id );
		if ( $payment_method_class->is_active() && $payment_method_class->is_allowed() ) {
			$final_payment_methods[ $id ] = $payment_method_class;
		}
	}
	return apply_filters( 'sunshine_allowed_payment_methods', $final_payment_methods );
}

function sunshine_get_payment_method_by_id( $id, $instance_id = 0 ) {
	$payment_methods = sunshine_get_payment_methods();
	if ( array_key_exists( $id, $payment_methods ) ) {
		return $payment_methods[ $id ];
		/*
		$payment_method = $payment_methods[ $id ];
		$class = $payment_method['class'];
		if ( class_exists( $class ) ) {
			return new $class();
		}
		*/
	}
	return false;
}

function sunshine_get_selected_payment_method() {
	$id = SPC()->cart->get_checkout_data_item( 'payment_method' );
	if ( $id ) {
		$allowed_payment_methods = sunshine_get_active_payment_methods();
		if ( array_key_exists( $id, $allowed_payment_methods ) ) {
			return sunshine_get_payment_method_by_id( $id );
		}
	}
	return false;
}
