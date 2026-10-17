<?php
/**
 * Helpers for the cart totals tests.
 *
 * The tests build real carts through SPC()->cart, so they run inside WordPress with WP-CLI.
 * Use a throwaway test site: products, discounts, gift cards and a test customer are created
 * while the tests run and deleted at the end. Store settings are changed only in memory.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

class SPC_Totals_Test {

	private static $option_filters = array();
	private static $created_posts  = array();
	private static $created_users  = array();
	private static $passed         = 0;
	private static $failed         = array();
	private static $skipped        = array();
	private static $current        = '';

	/**
	 * Run one named test. A test that throws counts as failed and does not stop the others.
	 */
	public static function test( $name, $callback ) {
		self::$current = $name;
		self::reset();
		try {
			$callback();
		} catch ( SPC_Totals_Test_Skip $e ) {
			self::$skipped[] = $name . ': ' . $e->getMessage();
		} catch ( Throwable $e ) {
			self::$failed[] = $name . ': threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
		}
		self::reset();
	}

	public static function skip( $reason ) {
		throw new SPC_Totals_Test_Skip( $reason );
	}

	public static function require_plugin( $function_name, $label ) {
		if ( ! function_exists( $function_name ) ) {
			self::skip( $label . ' is not active' );
		}
	}

	/**
	 * Compare money to the cent.
	 */
	public static function money( $expected, $actual, $label ) {
		if ( abs( round( (float) $expected, 2 ) - round( (float) $actual, 2 ) ) < 0.001 ) {
			self::$passed++;
			return;
		}
		self::$failed[] = self::$current . ' > ' . $label . ': expected ' . number_format( (float) $expected, 2 ) . ', got ' . var_export( $actual, true );
	}

	public static function same( $expected, $actual, $label ) {
		if ( $expected === $actual ) {
			self::$passed++;
			return;
		}
		self::$failed[] = self::$current . ' > ' . $label . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true );
	}

	/**
	 * Set a Sunshine option for this test only. Nothing is written to the database.
	 */
	public static function option( $key, $value ) {
		$hook = 'pre_option_sunshine_' . $key;
		if ( isset( self::$option_filters[ $hook ] ) ) {
			remove_filter( $hook, self::$option_filters[ $hook ] );
		}
		// pre_option treats false as "not set", so an "off" value is passed as an empty string.
		$callback                       = function () use ( $value ) {
			return ( false === $value ) ? '' : $value;
		};
		self::$option_filters[ $hook ] = $callback;
		add_filter( $hook, $callback );
	}

	/**
	 * Every test starts from the same store: US store, no taxes, no shipping methods,
	 * no discounts, prices without tax, discounts before tax, and an empty guest cart.
	 */
	public static function reset() {
		foreach ( self::$option_filters as $hook => $callback ) {
			remove_filter( $hook, $callback );
		}
		self::$option_filters = array();

		self::option( 'country', 'US' );
		self::option( 'state', '' );
		self::option( 'postcode', '' );
		self::option( 'tax_default_location', 'store' );
		self::option( 'taxes_enabled', '' );
		self::option( 'tax_rates', array() );
		self::option( 'price_has_tax', 'no' );
		self::option( 'discount_after_tax', '' );
		self::option( 'shipping_methods', array() );

		wp_set_current_user( 0 );
		SPC()->customer = new SPC_Customer( 0 );

		foreach ( array( 'cart', 'discounts', 'checkout_data', 'checkout_sections_completed', 'gift_cards', 'gift_cards_applied', 'gift_card_amounts', 'use_credits' ) as $key ) {
			SPC()->session->set( $key, '' );
		}
		SPC()->cart = new SPC_Cart();
	}

	/**
	 * Turn on one tax rate that applies to every US address.
	 */
	public static function tax( $percent ) {
		self::option( 'taxes_enabled', 1 );
		self::option(
			'tax_rates',
			array(
				array(
					'country'  => 'US',
					'state'    => '',
					'postcode' => '',
					'rate'     => (string) $percent,
				),
			)
		);
	}

	/**
	 * Offer one flat rate shipping method and choose it.
	 */
	public static function flat_shipping( $price, $taxable = false ) {
		$instance = 'spctestflat';
		self::option( 'shipping_methods', array( $instance => array( 'id' => 'flat_rate', 'active' => 1 ) ) );
		self::option( 'flat_rate_price_' . $instance, (string) $price );
		self::option( 'flat_rate_price_type_' . $instance, '' );
		self::option( 'flat_rate_taxable_' . $instance, $taxable ? 1 : '' );
		SPC()->cart->set_checkout_data_item( 'delivery_method', 'shipping' );
		SPC()->cart->set_checkout_data_item( 'shipping_method', $instance );
	}

	public static function product( $price, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'name'         => 'Totals test product',
				'type'         => 'print',
				'taxable'      => true,
				'qty_discount' => '',
			)
		);
		$id   = wp_insert_post(
			array(
				'post_type'   => 'sunshine-product',
				'post_status' => 'publish',
				'post_title'  => $args['name'],
			)
		);
		self::$created_posts[] = $id;
		update_post_meta( $id, 'type', $args['type'] );
		update_post_meta( $id, 'price', array( sunshine_get_default_price_level_id() => (string) $price ) );
		update_post_meta( $id, 'taxable', $args['taxable'] ? 1 : '' );
		if ( ! empty( $args['qty_discount'] ) ) {
			update_post_meta( $id, 'qty_discount', $args['qty_discount'] );
		}
		return $id;
	}

	/**
	 * Create a discount code and put it in the cart.
	 */
	public static function discount( $type, $amount, $args = array() ) {
		self::require_plugin( 'sunshine_get_discount_by_code', 'Discount Codes add-on' );
		$code = 'SPCTEST' . wp_rand( 100000, 999999 );
		$id   = wp_insert_post(
			array(
				'post_type'   => 'sunshine-discount',
				'post_status' => 'publish',
				'post_title'  => $code,
			)
		);
		self::$created_posts[] = $id;
		$meta                  = wp_parse_args(
			$args,
			array(
				'code'            => $code,
				'discount_type'   => $type,
				'discount_amount' => (string) $amount,
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		$discounts   = (array) SPC()->session->get( 'discounts' );
		$discounts[] = $code;
		SPC()->session->set( 'discounts', array_values( array_filter( $discounts ) ) );
		return $code;
	}

	/**
	 * Create a gift card and put it in the cart.
	 */
	public static function gift_card( $balance ) {
		self::require_plugin( 'sunshine_get_gift_card_by_code', 'Gift Cards add-on' );
		$code = 'SPCTESTGC' . wp_rand( 100000, 999999 );
		$id   = wp_insert_post(
			array(
				'post_type'   => 'sunshine-gift-card',
				'post_status' => 'publish',
				'post_title'  => $code,
			)
		);
		self::$created_posts[] = $id;
		update_post_meta( $id, 'code', $code );
		update_post_meta( $id, 'balance', $balance );
		update_post_meta( $id, 'original_amount', $balance );
		$cards   = (array) SPC()->session->get( 'gift_cards' );
		$cards[] = $code;
		SPC()->session->set( 'gift_cards', array_values( array_filter( $cards ) ) );
		return $id;
	}

	/**
	 * Log in as a new customer, for features that keep data on the customer.
	 */
	public static function customer() {
		$id                    = wp_insert_user(
			array(
				'user_login' => 'spctotals' . wp_rand( 100000, 999999 ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'sunshine_customer',
			)
		);
		self::$created_users[] = $id;
		wp_set_current_user( $id );
		SPC()->customer = new SPC_Customer( $id );
		SPC()->cart     = new SPC_Cart();
		return $id;
	}

	public static function add( $product_id, $qty = 1 ) {
		$item = SPC()->cart->add_item( $product_id, 0, '', '', array(), $qty );
		if ( empty( $item ) ) {
			throw new Exception( 'Could not add product ' . $product_id . ' to the cart' );
		}
		return $item;
	}

	/**
	 * Work out the cart the way a page load does.
	 */
	public static function calculate() {
		SPC()->cart->setup( true );
		return SPC()->cart;
	}

	/**
	 * A new request: the cart is built again from what is saved in the session.
	 */
	public static function new_request() {
		SPC()->cart = new SPC_Cart();
		return self::calculate();
	}

	public static function cleanup() {
		self::reset();
		foreach ( self::$created_posts as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( self::$created_users as $id ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $id );
		}
		self::$created_posts = array();
		self::$created_users = array();
	}

	public static function report() {
		foreach ( self::$skipped as $line ) {
			WP_CLI::log( 'SKIP ' . $line );
		}
		foreach ( self::$failed as $line ) {
			WP_CLI::log( 'FAIL ' . $line );
		}
		WP_CLI::log( sprintf( '%d checks passed, %d failed, %d tests skipped', self::$passed, count( self::$failed ), count( self::$skipped ) ) );
		return empty( self::$failed );
	}
}

class SPC_Totals_Test_Skip extends Exception {}
