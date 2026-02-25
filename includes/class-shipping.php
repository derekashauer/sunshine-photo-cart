<?php

final class Sunshine_Shipping {

	protected static $_instance = null;
	private $enabled            = true;
	private $shipping_methods   = array();

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		do_action( 'sunshine_shipping_init' );
	}

	// id, name, class
	public function get_shipping_methods() {
		$methods = apply_filters( 'sunshine_shipping_methods', array() );
		return $methods;
	}

	// Unique key = Sunshine_Shipping_Class
	public function get_available_shipping_methods( $classes = false ) {
		$available_shipping_methods = SPC()->get_option( 'shipping_methods' );
		if ( empty( $available_shipping_methods ) ) {
			return false;
		}
		return $available_shipping_methods;
		/*
		if ( !$classes ) {
			return $available_shipping_methods;
		}
		// Return with the class instances
		$shipping_methods = array();
		foreach ( $available_shipping_methods as $key => $available_shipping_method ) {
			$shipping_method = $this->get_shipping_method_by_id( $available_shipping_method['id'] );
			if ( $shipping_method ) {
				$shipping_methods[ $key ] = $shipping_method;
			}
		}
		return $shipping_methods;
		*/
	}

	// Unique key = id
	public function update_available_shipping_methods( $shipping_methods ) {
		SPC()->update_option( 'shipping_methods', $shipping_methods );
	}

	// of the available shipping methods, which ones are set as active
	public function get_active_shipping_methods() {
		$enabled_shipping_methods   = array();
		$available_shipping_methods = $this->get_available_shipping_methods();
		foreach ( $available_shipping_methods as $available_shipping_method ) {
			if ( $available_shipping_method->is_active() ) {
				$enabled_shipping_methods[] = $available_shipping_method;
			}
		}
		return $enabled_shipping_methods;
	}

	public function get_shipping_method_by_id( $id ) {
		$shipping_methods = $this->get_shipping_methods();
		if ( array_key_exists( $id, $shipping_methods ) ) {
			$shipping_method = $shipping_methods[ $id ];
			$class           = $shipping_method['class'];
			if ( class_exists( $class ) ) {
				return new $class();
			}
		}
		return false;
	}


}
