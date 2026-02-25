<?php

function sunshine_get_delivery_methods() {
	$delivery_methods = apply_filters( 'sunshine_delivery_methods', array() );
	return $delivery_methods;
}

function sunshine_get_delivery_method_by_id( $id ) {
	$delivery_methods = sunshine_get_delivery_methods();
	if ( array_key_exists( $id, $delivery_methods ) ) {
		$delivery_method = $delivery_methods[ $id ];
		$class           = $delivery_method['class'];
		if ( class_exists( $class ) ) {
			return new $class();
		}
	}
	return false;
}

function sunshine_get_selected_delivery_method() {
	$id = SPC()->cart->get_checkout_data_item( 'delivery_method' );
	if ( $id ) {
		$available_delivery_methods = sunshine_get_delivery_methods();
		if ( array_key_exists( $instance, $available_delivery_methods ) ) {
			return sunshine_get_delivery_method_by_id( $id );
		}
	}
	return false;
}
