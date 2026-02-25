<?php
/**
 * Handles fetching and displaying in-app promotional notices from sunshinephotocart.com.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for managing in-app promos in Sunshine Photo Cart.
 */
class Sunshine_In_App_Promos {

	/**
	 * Transient key for storing promos.
	 *
	 * @var string
	 */
	private $transient_key = 'sunshine_in_app_promos';

	/**
	 * API URL to fetch promos from.
	 *
	 * @var string
	 */
	private $api_url = 'https://www.sunshinephotocart.com/wp-json/sunshine/v1/promos';

	/**
	 * Whether a remote promo was displayed.
	 *
	 * @var bool
	 */
	private $promo_displayed = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Nothing to initialize - display() is called directly from class-admin.php.
	}

	/**
	 * Display promos if available.
	 *
	 * @return bool Whether a promo was displayed.
	 */
	public function display() {
		// Check if we should show promos at all.
		if ( ! $this->should_show_promos() ) {
			return false;
		}

		// Get promos (from cache or API).
		$promos = $this->get_promos();

		if ( empty( $promos ) ) {
			return false;
		}

		// Get the highest priority promo (first in the array, already sorted).
		$promo = $promos[0];

		// Check if this promo has been dismissed by the user.
		$notice_key = 'in_app_promo_' . $promo['id'];
		$notices    = get_user_meta( get_current_user_id(), 'sunshine_admin_notices', true );

		if ( is_array( $notices ) && isset( $notices[ $notice_key ] ) && $notices[ $notice_key ]['dismissed'] ) {
			return false;
		}

		// Display the promo.
		$this->render_promo( $promo );
		$this->promo_displayed = true;

		return true;
	}

	/**
	 * Check if promos should be shown.
	 *
	 * @return bool
	 */
	public function should_show_promos() {
		// Don't show if promos_hide is enabled AND user has a plan.
		if ( SPC()->get_option( 'promos_hide' ) && SPC()->has_plan() ) {
			return false;
		}

		// Don't show on install wizard, update page, or plugin update screen.
		$screen = get_current_screen();
		if ( $screen ) {
			// Install wizard and update pages.
			if ( in_array( $screen->base, array( 'sunshine-gallery_page_sunshine-install', 'sunshine-gallery_page_sunshine-update' ), true ) ) {
				return false;
			}

			// Post-plugin update screen (update.php?action=upgrade-plugin).
			if ( 'update' === $screen->base ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get promos from cache or fetch from API.
	 *
	 * @return array Array of promos.
	 */
	public function get_promos() {
		// Force refresh if hidden GET parameter is set (for testing).
		// Usage: ?sunshine_refresh_promos=1
		if ( isset( $_GET['sunshine_refresh_promos'] ) && current_user_can( 'manage_options' ) ) {
			delete_transient( $this->transient_key );
			$this->clear_dismissed_promos();
			return $this->fetch_promos();
		}

		// Try to get from transient first.
		$cached = get_transient( $this->transient_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// Fetch from API.
		return $this->fetch_promos();
	}

	/**
	 * Fetch promos from the remote API.
	 *
	 * @return array Array of promos.
	 */
	private function fetch_promos() {
		$plan = $this->get_user_plan_type();
		$url  = add_query_arg(
			array(
				'plan' => $plan,
				't'    => time(), // Cache-busting parameter.
			),
			$this->api_url
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'sslverify'   => false,
				'redirection' => 5,
				'httpversion' => '1.1',
				'headers'     => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				),
			)
		);

		// Handle errors gracefully.
		if ( is_wp_error( $response ) ) {
			SPC()->log( 'In-App Promos: Failed to fetch - ' . $response->get_error_message() );
			set_transient( $this->transient_key, array(), HOUR_IN_SECONDS );
			return array();
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			SPC()->log( 'In-App Promos: API returned status ' . $status_code );
			set_transient( $this->transient_key, array(), HOUR_IN_SECONDS );
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['promos'] ) ) {
			SPC()->log( 'In-App Promos: Invalid API response format' );
			set_transient( $this->transient_key, array(), HOUR_IN_SECONDS );
			return array();
		}

		$promos      = $data['promos'];
		$cache_until = isset( $data['cache_until'] ) ? $data['cache_until'] : null;

		// Calculate transient expiration.
		$expiration = DAY_IN_SECONDS; // Default to 24 hours.
		if ( $cache_until ) {
			$cache_time = strtotime( $cache_until );
			if ( $cache_time && $cache_time > time() ) {
				$expiration = $cache_time - time();
				// Cap at 7 days maximum.
				$expiration = min( $expiration, 7 * DAY_IN_SECONDS );
			}
		}

		// Store in transient.
		set_transient( $this->transient_key, $promos, $expiration );

		return $promos;
	}

	/**
	 * Get the current user's plan type.
	 *
	 * @return string 'pro', 'plus', or 'free'
	 */
	public function get_user_plan_type() {
		if ( SPC()->is_pro() ) {
			return 'pro';
		}
		if ( SPC()->has_plan() ) {
			return 'plus'; // Plus or Basic.
		}
		return 'free';
	}

	/**
	 * Render a promo notice.
	 *
	 * @param array $promo Promo data.
	 */
	private function render_promo( $promo ) {
		$notice_key = 'in_app_promo_' . $promo['id'];

		?>
		<div class="notice sunshine-in-app-promo is-dismissible" id="sunshine-notice--<?php echo esc_attr( $notice_key ); ?>" data-notice="<?php echo esc_attr( $notice_key ); ?>">
			<?php if ( ! empty( $promo['image'] ) ) : ?>
				<div class="sunshine-in-app-promo-image">
					<img src="<?php echo esc_url( $promo['image'] ); ?>" alt="" />
				</div>
			<?php endif; ?>
			<div class="sunshine-in-app-promo-content">
				<?php if ( ! empty( $promo['title'] ) ) : ?>
					<h3 class="sunshine-in-app-promo-title"><?php echo esc_html( $promo['title'] ); ?></h3>
				<?php endif; ?>
				<?php if ( ! empty( $promo['content'] ) ) : ?>
					<div class="sunshine-in-app-promo-text"><?php echo wp_kses( nl2br( $promo['content'] ), array( 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'br' => array() ) ); ?></div>
				<?php endif; ?>
				<p class="sunshine-in-app-promo-cta">
					<?php if ( ! empty( $promo['url'] ) ) : ?>
						<a href="<?php echo esc_url( $promo['url'] ); ?>" target="_blank" class="button button-primary"><?php esc_html_e( 'Learn More', 'sunshine-photo-cart' ); ?></a>
					<?php endif; ?>
					<button type="button" class="button notice-dismiss-button"><?php esc_html_e( 'Dismiss', 'sunshine-photo-cart' ); ?></button>
				</p>
			</div>
			<button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'sunshine-photo-cart' ); ?></span></button>
		</div>
		<?php

		// Register this promo in the notices system for dismissal tracking.
		$this->register_promo_notice( $notice_key );
	}

	/**
	 * Register a promo notice for dismissal tracking.
	 *
	 * @param string $notice_key The notice key.
	 */
	private function register_promo_notice( $notice_key ) {
		$notices = get_user_meta( get_current_user_id(), 'sunshine_admin_notices', true );

		if ( ! is_array( $notices ) ) {
			$notices = array();
		}

		// Only add if not already exists.
		if ( ! isset( $notices[ $notice_key ] ) ) {
			$notices[ $notice_key ] = array(
				'text'      => '', // We render custom HTML, so this is empty.
				'type'      => 'notice',
				'permanent' => true,
				'dismissed' => false,
			);
			update_user_meta( get_current_user_id(), 'sunshine_admin_notices', $notices );
		}
	}

	/**
	 * Clear the promos cache.
	 * Useful for testing or when promos need to be refreshed.
	 */
	public static function clear_cache() {
		delete_transient( 'sunshine_in_app_promos' );
	}

	/**
	 * Clear dismissed state for all in-app promos.
	 * Used for testing when refresh parameter is set.
	 */
	private function clear_dismissed_promos() {
		$notices = get_user_meta( get_current_user_id(), 'sunshine_admin_notices', true );

		if ( ! is_array( $notices ) ) {
			return;
		}

		$updated = false;
		foreach ( $notices as $key => $notice ) {
			// Only clear in-app promo dismissals.
			if ( strpos( $key, 'in_app_promo_' ) === 0 && ! empty( $notice['dismissed'] ) ) {
				$notices[ $key ]['dismissed'] = false;
				$updated = true;
			}
		}

		if ( $updated ) {
			update_user_meta( get_current_user_id(), 'sunshine_admin_notices', $notices );
		}
	}

	/**
	 * Check if a promo was displayed.
	 *
	 * @return bool
	 */
	public function was_promo_displayed() {
		return $this->promo_displayed;
	}

}
