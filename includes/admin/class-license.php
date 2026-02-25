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
		add_action( 'sunshine_active_addons', array( $this, 'active_addons' ) );
		add_action( 'sunshine_license_check', array( $this, 'check_license' ) );

		if ( $file ) {
			$plugin_base_name = plugin_basename( $file );
			add_action( 'in_plugin_update_message-' . $plugin_base_name, array( $this, 'update_message' ), 10, 2 );
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
					$description = __( 'Your license is not active for this URL', 'sunshine-photo-cart' );
					break;

				case 'item_name_mismatch':
					$description = __( 'This appears to be an invalid license key', 'sunshine-photo-cart' );
					break;

				case 'no_activations_left':
					$description = __( 'Your license key has reached its activation limit', 'sunshine-photo-cart' );
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

		if ( isset( $_POST[ $key ] ) ) {

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
				'sslverify' => false,
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
			} else {
				$result = true;
				SPC()->update_option( 'license_expiration_' . $this->id, $license_key );
				if ( ! $hide_notice ) {
					/* translators: %s is the product name */
					SPC()->notices->add_admin( $this->id . '_license_activated', sprintf( __( 'License for %s has been activated', 'sunshine-photo-cart' ), $this->name ) );
				}
				SPC()->update_option( 'license_' . $this->id, $license_key );
				SPC()->update_option( 'license_expiration_' . $this->id, $license_data->expires );
			}

			SPC()->update_option( 'license_status_' . $this->id, $license_data->license );

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
				'sslverify' => false,
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

		$this->check_license();

		wp_redirect( admin_url( 'admin.php?page=sunshine&section=license' ) );
		exit;

	}

	public function check_license() {

		// No license key, nothing to check.
		if ( empty( $this->get_license_key() ) ) {
			SPC()->log( 'No license key, nothing to check for ' . $this->name );
			return;
		}

		SPC()->log( 'Checking license for ' . $this->name );

		// Data to send to the API
		$api_params = array(
			'edd_action' => 'check_license',
			'license'    => $this->get_license_key(),
			'item_id'    => $this->item_id,
			'url'        => home_url(),
		);

		// Call the API
		$response = wp_remote_post(
			SUNSHINE_PHOTO_CART_STORE_URL . '?time=' . time(),
			array(
				'timeout'   => 15,
				'sslverify' => false,
				'body'      => $api_params,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {

			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();
			} else {
				$message = __( 'Unknown error occured', 'sunshine-photo-cart' );
			}

			/* translators: %1$s is the product name, %2$s is the error message */
			SPC()->notices->add_admin( $this->id . '_license_update_fail', sprintf( __( 'License for %1$s failed to be updated: %2$s', 'sunshine-photo-cart' ), $this->name, $message ) );

		} else {

			$license_data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( false === $license_data->success ) {
				SPC()->update_option( 'license_expiration_' . $this->id, '' );
			} else {
				/* translators: %s is the product name */
				SPC()->notices->add_admin( $this->id . '_license_updated', sprintf( __( 'License for %s has been updated', 'sunshine-photo-cart' ), $this->name ) );
				SPC()->update_option( 'license_expiration_' . $this->id, $license_data->expires );
			}

			SPC()->update_option( 'license_status_' . $this->id, $license_data->license );
			SPC()->update_option( 'license_data_' . $this->id, $license_data );

		}

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
		if ( $this->license_key && $this->is_expired() ) {
			$expired_addons[ $this->id ] = $this->name;
		}
		return $expired_addons;
	}

	public function active_addons( $active_addons ) {
		if ( $this->license_key && $this->is_valid() && ! $this->is_expired() ) {
			$active_addons[ $this->id ] = $this->name;
		}
		return $active_addons;
	}

}
