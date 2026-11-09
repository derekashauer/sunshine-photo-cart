<?php
class SPC_License {

	private $id;
	private $item_id;
	private $name;
	private $file;
	private $status;
	private $expiration;
	private $license_key;
	private $updater;
	private $version;
	private $requires;
	private $data;

	public function __construct( $item_id, $name, $version, $file = '', $requires = false ) {

		$this->item_id  = $item_id;
		$this->name     = $name;
		$this->version  = $version;
		$this->file     = $file;
		$this->requires = $requires;

		if ( $file ) {
			$this->id = basename( $file, '.php' );
		} else {
			$this->id = sanitize_title( $name );
		}

		$this->init();

		add_filter( 'sunshine_options_licenses', array( $this, 'default_options' ), 1 );
		// add_action( 'admin_init', array( $this, 'notice' ) );
		add_action( 'admin_init', array( $this, 'listener' ), 5 );
		add_action( 'admin_init', array( $this, 'manual_license_check' ) );
		add_action( 'sunshine_expired_addons', array( $this, 'expired_addons' ) );
		add_filter( 'sunshine_license_problem_addons', array( $this, 'problem_addons' ) );
		add_action( 'sunshine_active_addons', array( $this, 'active_addons' ) );
		add_action( 'sunshine_license_check', array( $this, 'check_license' ) );
		add_action( 'sunshine_plan_activated', array( $this, 'switch_to_plan_key' ) );

		if ( $file ) {
			$plugin_base_name = plugin_basename( $file );
			add_action( 'in_plugin_update_message-' . $plugin_base_name, array( $this, 'update_message' ), 10, 2 );

			// Here, not in init(), which stops early for visitors who can't manage the site.
			SPC_Addon_Update_Verify::watch( $file, $version, $name );
		}

	}

	function init() {

		// To support auto-updates, this needs to run during the wp_version_check cron job for privileged users.
		$doing_cron = defined( 'DOING_CRON' ) && DOING_CRON;
		if ( ! current_user_can( 'manage_options' ) && ! $doing_cron ) {
			return;
		}

		$this->data = SPC()->get_option( 'license_data_' . $this->id );

		$this->license_key = SPC()->get_option( 'license_' . $this->id );

		$this->updater = new SPC_Addon_Updater(
			SUNSHINE_PHOTO_CART_STORE_URL,
			$this->file,
			array(
				'version' => $this->version,
				'license' => $this->get_license_key(),
				'item_id' => $this->item_id,
				'author'  => 'WP Sunshine',
				'beta'    => false,
			)
		);

	}

	public function get_id() {
		return str_replace( 'sunshine-photo-cart-', '', $this->id );
	}

	public function get_name() {
		return $this->name;
	}

	public function default_options( $fields ) {

		$plan = self::get_valid_plan();

		// With a valid plan, only that plan's key is shown.
		if ( $this->is_replaced_by_other_plan() ) {
			return $fields;
		}

		// Add-ons using the plan key are listed under the plan instead of getting their own key field.
		if ( $this->is_covered_by_plan() ) {
			$key = 'planzcovered'; // Sorts right after the plan field.
			if ( empty( $fields[ $key ] ) ) {
				$fields[ $key ] = array(
					'name'             => __( 'Add-ons', 'sunshine-photo-cart' ),
					'id'               => 'license_covered_addons',
					'type'             => 'html',
					'addon_names'      => array(),
					'hide_system_info' => true,
				);
			}
			$fields[ $key ]['addon_names'][] = $this->name;
			natcasesort( $fields[ $key ]['addon_names'] );
			$fields[ $key ]['html'] = '<p>' . sprintf(
				/* translators: %s is the plan name, like Sunshine Photo Cart Pro */
				esc_html__( 'These add-ons use your %s license key:', 'sunshine-photo-cart' ),
				esc_html( $plan->get_name() )
			) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $fields[ $key ]['addon_names'] ) ) . '</li></ul>';
			return $fields;
		}

		$status      = $this->get_status();
		$expiration  = $this->get_expiration();
		$license_key = $this->get_license_key();

		$description = '';

		if ( $license_key ) {

			switch ( $status ) {

				case 'expired':
					$description = sprintf(
						/* translators: the license key expiration date */
						__( 'Your license key expired on %s.', 'sunshine-photo-cart' ),
						date_i18n( get_option( 'date_format' ), strtotime( $expiration, current_time( 'timestamp' ) ) )
					);
					break;

				case 'disabled':
				case 'revoked':
					$description = __( 'Your license key has been disabled', 'sunshine-photo-cart' );
					break;

				case 'missing':
					$description = __( 'Invalid license', 'sunshine-photo-cart' );
					break;

				case 'invalid':
					$description = __( 'Invalid license key', 'sunshine-photo-cart' );
					break;

				case 'site_inactive':
				case 'no_activations_left':
					$stored_url   = SPC()->get_option( 'license_url_' . $this->id );
					$current_url  = home_url();
					$licenses_url = 'https://www.sunshinephotocart.com/account/licenses/';
					if ( $stored_url && untrailingslashit( $stored_url ) !== untrailingslashit( $current_url ) ) {
						$description = sprintf(
							/* translators: %1$s is the previous site URL, %2$s is the current site URL, %3$s is the URL to the sunshinephotocart.com account licenses page */
							__( 'Your license was activated on %1$s but this site is now %2$s. To use the license here, <a href="%3$s" target="_blank">log in to your account</a>, click <strong>Manage Sites</strong> next to this license, deactivate the old URL, then return here and click Activate again.', 'sunshine-photo-cart' ),
							'<code>' . esc_html( $stored_url ) . '</code>',
							'<code>' . esc_html( $current_url ) . '</code>',
							esc_url( $licenses_url )
						);
					} else {
						$description = sprintf(
							/* translators: %s is the URL to the sunshinephotocart.com account licenses page */
							__( 'This license appears to be activated on a different site. <a href="%s" target="_blank">Log in to your account</a>, click <strong>Manage Sites</strong> next to this license, deactivate the old URL, then return here and click Activate again.', 'sunshine-photo-cart' ),
							esc_url( $licenses_url )
						);
					}
					break;

				case 'item_name_mismatch':
					$description = __( 'This appears to be an invalid license key', 'sunshine-photo-cart' );
					break;

				case 'valid':
					if ( $expiration == 'lifetime' ) {
						$description = __( 'You have a special lifetime license that never expires!', 'sunshine-photo-cart' );
					} elseif ( $expiration ) {
						$description = sprintf(
							/* translators: the license key expiration date */
							__( 'Expires on %s', 'sunshine-photo-cart' ),
							date_i18n( get_option( 'date_format' ), strtotime( $expiration, current_time( 'timestamp' ) ) )
						);
					}
					break;

			}
		}

		// if ( empty( SPC()->plan ) || ! SPC()->plan->get_license_key() || ( SPC()->plan->get_license_key() && in_array( $this->get_id(), array( 'pro', 'plus', 'basic' ) ) ) ) {
			$key = ( $this->requires ) ? 'plan' : 'xaddon';

			$fields[ $key . $this->id ] = array(
				'name'             => $this->name,
				'id'               => 'license_' . $this->id,
				'type'             => 'license',
				'addon'            => $this->id,
				'status'           => $status,
				'disabled'         => ( $this->is_valid() ) ? 'valid' : 'invalid',
				'description'      => $description,
				'hide_system_info' => true,
			);
			// }

			return $fields;

	}

	public function get_license_key() {
		return $this->license_key;
	}

	public function get_status() {
		return SPC()->get_option( 'license_status_' . $this->id );
	}

	public function is_valid() {
		$valid = ( 'valid' == $this->get_status() ) ? true : false;
		return $valid;
	}

	public function get_expiration() {
		return SPC()->get_option( 'license_expiration_' . $this->id );
	}

	public function listener() {

		$key = 'sunshine_license_' . $this->id;

		// admin_init also runs for logged-out visitors on admin-ajax.php and admin-post.php.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST[ $key ] ) ) {

			// The license key is submitted with the Licenses settings form.
			if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'sunshine_license-options' ) ) {
				return;
			}

			$license_key = sanitize_text_field( $_POST[ $key ] );

			if ( $this->is_valid() || empty( $license_key ) ) {
				return;
			}

			$license_key = sanitize_text_field( $_POST[ $key ] );

			// Let's try activating this license!
			$this->activate( $license_key );

		} elseif ( isset( $_GET['sunshine_addon_deactivate'] ) && wp_verify_nonce( $_GET['sunshine_addon_deactivate'], 'sunshine_addon_deactivate_' . $this->id ) ) {

			if ( $this->get_status() != 'valid' ) {
				return;
			}

			$this->deactivate();

			wp_safe_redirect( admin_url( 'admin.php?page=sunshine&section=license' ) );
			exit();

		}

	}

	public function notice() {

		// Ignore for Sunshine Plans when there is no license key entered.
		if ( $this->requires ) {
			return;
		}

		if ( ! $this->is_valid() ) {
			if ( ! SPC()->is_pro() && ( empty( $_GET['page'] ) || $_GET['page'] != 'sunshine-install' ) ) {
				/* translators: %1$s is the product name, %2$s is the URL to the licenses page */
				SPC()->notices->add_admin( $this->id . '_no_license', sprintf( __( 'You do not have an active license for %1$s, please enter and activate your current license on the <a href="%2$s">Licenses page</a>', 'sunshine-photo-cart' ), $this->name, admin_url( 'admin.php?page=sunshine&section=license' ) ), 'error' );
			}
		}

	}

	public function activate( $license_key = '', $hide_notice = false ) {

		if ( empty( $license_key ) ) {
			$license_key = $this->get_license_key();
		}

		// Data to send to the API
		$api_params = array(
			'edd_action'  => 'activate_license',
			'license'     => $license_key,
			'item_id'     => $this->item_id,
			// 'item_name'   => rawurlencode( $this->name ),
			'url'         => home_url(),
			'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
		);

		// Call the API
		$response = wp_remote_post(
			SUNSHINE_PHOTO_CART_STORE_URL . '?time=' . time(),
			array(
				'timeout'   => 15,
				'body'      => $api_params,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {

			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();
			} else {
				$message = __( 'Unknown error occured', 'sunshine-photo-cart' );
			}

			if ( ! $hide_notice ) {
				/* translators: %1$s is the product name, %2$s is the error message */
				SPC()->notices->add_admin( $this->id . '_license_activation_fail', sprintf( __( 'License for %1$s failed to be activated: %2$s', 'sunshine-photo-cart' ), $this->name, $message ) );
			}

			return false;

		} else {

			$license_data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( false === $license_data->success ) {
				$result = false;
				SPC()->update_option( 'license_expiration_' . $this->id, '' );
				// EDD returns failure-specific codes (e.g. no_activations_left) in `error`, not `license`.
				$status_code = ! empty( $license_data->error ) ? $license_data->error : $license_data->license;
			} else {
				$result = true;
				SPC()->update_option( 'license_expiration_' . $this->id, $license_key );
				if ( ! $hide_notice ) {
					/* translators: %s is the product name */
					SPC()->notices->add_admin( $this->id . '_license_activated', sprintf( __( 'License for %s has been activated', 'sunshine-photo-cart' ), $this->name ) );
				}
				SPC()->update_option( 'license_' . $this->id, $license_key );
				$this->license_key = $license_key;
				SPC()->update_option( 'license_expiration_' . $this->id, $license_data->expires );
				SPC()->update_option( 'license_url_' . $this->id, home_url() );
				$status_code = $license_data->license;
			}

			SPC()->update_option( 'license_status_' . $this->id, $status_code );

			if ( $result && $this->requires ) {
				do_action( 'sunshine_plan_activated', $this );
			}

			return $result;

		}

	}

	public function deactivate() {

		// Data to send to the API
		$api_params = array(
			'edd_action' => 'deactivate_license',
			'license'    => $this->get_license_key(),
			'item_name'  => urlencode( $this->name ),
			'url'        => home_url(),
		);

		// Call the custom API.
		$response = wp_remote_post(
			SUNSHINE_PHOTO_CART_STORE_URL,
			array(
				'timeout'   => 15,
				'body'      => $api_params,
			)
		);

		// make sure the response came back okay
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {

			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();
			} else {
				$message = __( 'An error occurred, please try again.', 'sunshine-photo-cart' );
			}

			if ( ! $hide_notice ) {
				SPC()->notices->add_admin( $this->id . '_license_error', $message, 'error' );
			}
			return;

		}

		// decode the license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		// $license_data->license will be either "deactivated" or "failed"

		SPC()->update_option( 'license_status_' . $this->id, '' );
		SPC()->update_option( 'license_expiration_' . $this->id, '' );
		SPC()->update_option( 'license_url_' . $this->id, '' );

		if ( 'deactivated' === $license_data->license ) {
			/* translators: %s is the product name */
			SPC()->notices->add_admin( $this->id . '_license_deactivated', sprintf( __( 'License for %s has been deactivated', 'sunshine-photo-cart' ), $this->name ) );
		} else {
			/* translators: %1$s is the product name, %2$s is the URL to support */
			SPC()->notices->add_admin( $this->id . '_license_deactivated', sprintf( __( 'License for %1$s could not be deactivated, please <a href="%2$s" target="_blank">contact support</a>', 'sunshine-photo-cart' ), $this->name, 'https://www.sunshinephotocart.com/support-ticket' ), 'error' );
		}

	}

	function update_message( $plugin_data, $response ) {

		// bail ealry if has key
		if ( $this->is_valid() ) {
			return;
		}

		// display message
		/* translators: %1$s is the URL to licenses page, %2$s is the URL to account on SunshinePhotoCart.com */
		echo '<br /><span style="color:red;font-weight:bold;">' . sprintf( esc_html__( 'To enable updates, please enter your license key on the <a href="%1$s">Licenses</a> page. You can get your license key from <a href="%2$s" target="_blank">your account on SunshinePhotoCart.com</a>.', 'sunshine-photo-cart' ), esc_url( admin_url( 'admin.php?page=sunshine&section=license' ) ), esc_url( 'https://www.sunshinephotocart.com/account/licenses' ) ) . '</span>';

	}

	public function manual_license_check() {

		if ( ! isset( $_GET['check_license'] ) ) {
			return;
		}

		if ( $_GET['check_license'] != $this->id ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) || empty( $_GET['sunshine_check_license'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['sunshine_check_license'] ) ), 'sunshine_check_license_' . $this->id ) ) {
			return;
		}

		$this->check_license();

		wp_redirect( admin_url( 'admin.php?page=sunshine&section=license' ) );
		exit;

	}

	public function check_license() {

		if ( $this->switch_to_plan_key() ) {
			return;
		}

		// No license key, nothing to check.
		if ( empty( $this->get_license_key() ) ) {
			SPC()->log( 'No license key, nothing to check for ' . $this->name );
			return;
		}

		SPC()->log( 'Checking license for ' . $this->name );

		$license_data = $this->request_license_data( $this->get_license_key() );

		if ( is_wp_error( $license_data ) ) {

			$message = $license_data->get_error_message();

			SPC()->log( 'License check failed for ' . $this->name . ': ' . $message );

			/* translators: %1$s is the product name, %2$s is the error message */
			SPC()->notices->add_admin( $this->id . '_license_update_fail', sprintf( __( 'License for %1$s failed to be updated: %2$s', 'sunshine-photo-cart' ), $this->name, $message ) );

		} else {

			if ( false !== $license_data->success ) {
				/* translators: %s is the product name */
				SPC()->notices->add_admin( $this->id . '_license_updated', sprintf( __( 'License for %s has been updated', 'sunshine-photo-cart' ), $this->name ) );
			}

			$this->save_license_data( $license_data );

		}

	}

	/**
	 * Ask the store about a license key for this product on this site.
	 *
	 * @param string $license_key The license key to check.
	 * @return object|WP_Error The store's license data, or an error when the check did not get through.
	 */
	private function request_license_data( $license_key ) {

		$response = wp_remote_post(
			SUNSHINE_PHOTO_CART_STORE_URL . '?time=' . time(),
			array(
				'timeout' => 15,
				'body'    => array(
					'edd_action' => 'check_license',
					'license'    => $license_key,
					'item_id'    => $this->item_id,
					'url'        => home_url(),
				),
			)
		);

		// A 200 that isn't license data (a firewall challenge or maintenance page) is a
		// failed check too. Treating it as a success would wipe the saved license status.
		$license_data = null;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$license_data = json_decode( wp_remote_retrieve_body( $response ) );
		}

		if ( ! is_object( $license_data ) || ! isset( $license_data->license ) ) {
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			return new WP_Error( 'sunshine_license_check_failed', __( 'Unknown error occured', 'sunshine-photo-cart' ) );
		}

		return $license_data;

	}

	/**
	 * Save what the store said about this license.
	 *
	 * @param object $license_data The store's license data.
	 */
	private function save_license_data( $license_data ) {
		SPC()->update_option( 'license_expiration_' . $this->id, ( false === $license_data->success ) ? '' : $license_data->expires );
		SPC()->update_option( 'license_status_' . $this->id, $license_data->license );
		SPC()->update_option( 'license_data_' . $this->id, $license_data );
	}

	/**
	 * Product names as a short list for the admin bar: the first three, then "and more".
	 *
	 * @param array $names Product names.
	 * @return string
	 */
	public static function names_for_notice( $names ) {
		if ( count( $names ) > 3 ) {
			/* translators: %s is a list of the first three add-on names */
			return sprintf( __( '%s and more', 'sunshine-photo-cart' ), join( ', ', array_slice( $names, 0, 3 ) ) );
		}
		return join( ', ', $names );
	}

	/**
	 * The plan (Pro, Plus or Basic) with a valid license on this site, if any.
	 *
	 * @return SPC_License|false
	 */
	public static function get_valid_plan() {
		if ( empty( SPC()->plans ) ) {
			return false;
		}
		foreach ( SPC()->plans as $plan ) {
			if ( $plan->is_valid() ) {
				return $plan;
			}
		}
		return false;
	}

	/**
	 * Whether this add-on uses the key of the site's valid plan.
	 *
	 * @return bool
	 */
	public function is_covered_by_plan() {
		if ( $this->requires || ! $this->get_license_key() || ! $this->is_valid() ) {
			return false;
		}
		$plan = self::get_valid_plan();
		return $plan && $this->get_license_key() === $plan->get_license_key();
	}

	/**
	 * Switch this add-on to the site's plan key when the plan covers it.
	 *
	 * Sites set up before they had a plan can still hold a separate key for each
	 * add-on. Those keys expire on their own schedule, so once one stops working the
	 * plan key replaces it, after the store confirms the plan covers this add-on.
	 * A key that still works (a lifetime key, say) is left alone.
	 *
	 * @return bool True when this add-on was switched to the plan key.
	 */
	public function switch_to_plan_key() {

		if ( $this->requires || ( $this->is_valid() && ! $this->is_expired() ) ) {
			return false;
		}

		$plan = self::get_valid_plan();
		if ( ! $plan || $plan->is_expired() || ! $plan->get_license_key() || $plan->get_license_key() === $this->get_license_key() ) {
			return false;
		}

		$license_data = $this->request_license_data( $plan->get_license_key() );
		if ( is_wp_error( $license_data ) || 'valid' !== $license_data->license ) {
			return false;
		}

		$this->license_key = $plan->get_license_key();
		SPC()->update_option( 'license_' . $this->id, $this->license_key );
		SPC()->update_option( 'license_url_' . $this->id, home_url() );
		$this->save_license_data( $license_data );

		SPC()->log( $this->name . ' now uses the ' . $plan->get_name() . ' license key' );

		return true;

	}

	public function is_expired() {
		$expiration = $this->get_expiration();
		if ( $expiration == 'lifetime' ) {
			return false;
		}
		if ( empty( $expiration ) ) {
			return true;
		}
		return ( strtotime( $expiration ) < time() ) ? true : false;
	}

	public function is_trial() {
		if ( empty( $this->data ) ) {
			return false;
		}
		if ( isset( $this->data->is_trial ) && $this->data->is_trial ) {
			return true;
		}
		return false;
	}

	public function is_trialing() {
		if ( $this->is_trial() && ! $this->is_expired() ) {
			return true;
		}
		return false;
	}

	public function is_trial_expired() {
		if ( $this->is_trial() && isset( $this->data->trial_expires ) && strtotime( $this->data->trial_expires ) < time() ) {
			return true;
		}
		return false;
	}

	public function expired_addons( $expired_addons ) {
		if ( $this->license_key && ! $this->is_replaced_by_other_plan() && $this->is_expired() && in_array( $this->get_status(), array( 'expired', 'valid' ), true ) ) {
			$expired_addons[ $this->id ] = $this->name;
		}
		return $expired_addons;
	}

	/**
	 * Add this product when its saved key was turned down for a reason other than
	 * its end date (wrong key, wrong site, disabled).
	 *
	 * @param array $problem_addons Product names keyed by ID.
	 * @return array
	 */
	public function problem_addons( $problem_addons ) {
		// No status means the key was deactivated on purpose, or never checked.
		if ( $this->license_key && ! $this->is_replaced_by_other_plan() && ! in_array( $this->get_status(), array( '', false, 'valid', 'expired' ), true ) ) {
			$problem_addons[ $this->id ] = $this->name;
		}
		return $problem_addons;
	}

	/**
	 * Whether this is a plan the site no longer uses, because a different plan is valid.
	 *
	 * @return bool
	 */
	private function is_replaced_by_other_plan() {
		if ( ! $this->requires ) {
			return false;
		}
		$plan = self::get_valid_plan();
		return $plan && $plan !== $this;
	}

	public function active_addons( $active_addons ) {
		if ( $this->license_key && $this->is_valid() && ! $this->is_expired() ) {
			$active_addons[ $this->id ] = $this->name;
		}
		return $active_addons;
	}

}
