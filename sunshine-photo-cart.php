<?php
/**
 * Plugin Name: Sunshine Photo Cart - Free Client Photo Galleries for Photographers
 * Plugin URI: https://www.sunshinephotocart.com
 * Description: Client Gallery Photo Cart & Photo Proofing Plugin for Professional Photographers using WordPress
 * Author: WP Sunshine
 * Author URI: https://www.wpsunshine.com
 * Version: 3.6.6
 * Text Domain: sunshine-photo-cart
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SUNSHINE_PHOTO_CART_PATH', plugin_dir_path( __FILE__ ) );
define( 'SUNSHINE_PHOTO_CART_URL', plugin_dir_url( __FILE__ ) );
define( 'SUNSHINE_PHOTO_CART_FILE', __FILE__ );
define( 'SUNSHINE_PHOTO_CART_VERSION', '3.6.6' );
define( 'SUNSHINE_PHOTO_CART_STORE_URL', 'https://license.sunshinephotocart.com' );

if ( ! class_exists( 'Sunshine_Photo_Cart', false ) ) {
	include_once SUNSHINE_PHOTO_CART_PATH . '/includes/class-sunshinephotocart.php';
}

require_once SUNSHINE_PHOTO_CART_PATH . '/includes/admin/install.php';
register_activation_hook( __FILE__, 'sunshine_activation' );
register_deactivation_hook( __FILE__, 'sunshine_deactivation' );

function SPC() {
	return Sunshine_Photo_Cart::instance();
}
SPC();

add_action( 'after_setup_theme', 'sunshine_load_textdomain', 99 );
function sunshine_load_textdomain() {
	$result = load_plugin_textdomain( 'sunshine-photo-cart', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_filter( 'load_textdomain_mofile', 'sunshine_load_own_translations', 10, 2 );
function sunshine_load_own_translations( $mofile, $domain ) {
	if ( 'sunshine-photo-cart' === $domain && false !== strpos( $mofile, WP_LANG_DIR . '/plugins/' ) ) {
		$locale = apply_filters( 'plugin_locale', determine_locale(), $domain );
		if ( $locale == 'en_US' ) {
			return $mofile; // Return default en_US version.
		}
		$custom_mofile = SUNSHINE_PHOTO_CART_PATH . 'languages/' . $domain . '-' . $locale . '.mo';

		if ( file_exists( $custom_mofile ) ) {
			return $custom_mofile;
		} else {
			// Get the main language code
			$main_language = explode( '_', $locale )[0];
			$language_dir  = SUNSHINE_PHOTO_CART_PATH . 'languages/';
			$files         = glob( $language_dir . $domain . '-' . $main_language . '*.mo' );
			if ( ! empty( $files ) ) {
				return $files[0]; // Return the first matching file
			}
		}
	}
	return $mofile;
}
