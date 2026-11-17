<?php
class SPC_License {

	// The plans, lowest first, with how far up each one is.
	const PLAN_RANKS = array(
		'basic' => 1,
		'plus'  => 2,
		'pro'   => 3,
	);

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

	/**
	 * Whether this license's key and data were loaded. They are only loaded for users who can
	 * manage options, and in cron, so nobody else is told an add-on has no license.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Every license on the site, keyed by ID, for the System Info report.
	 *
	 * @var SPC_License[]
	 */
	private static $all = array();

	/**
	 * True while one check runs for every license, so each one doesn't add its own notice.
	 *
	 * @var bool
	 */
	private static $quiet = false;

	// A check that worked longer ago than this is worth telling the site owner about.
	const STALE_AFTER = 14 * DAY_IN_SECONDS;

	// From this day (site time zone), an add-on without an active license is turned off. Until then it only warns.
	const STOP_START = '2027-02-01 00:00:00';

	// How long an add-on keeps working after its license runs out, so a late renewal or a card retry has time.
	const STOP_GRACE = 14 * DAY_IN_SECONDS;

	// How long an add-on with no working license key keeps working, so there is time to enter one.
	const STOP_SETUP = 14 * DAY_IN_SECONDS;

	// When checks have not reached the store for this long, an add-on past its stop date is turned off anyway.
	const STOP_UNREACHABLE = 30 * DAY_IN_SECONDS;

	// While a license's end date is this close, or passed less than STOP_DAILY_AFTER ago, it is checked every day.
	const STOP_DAILY_BEFORE = 3 * DAY_IN_SECONDS;
	const STOP_DAILY_AFTER  = 60 * DAY_IN_SECONDS;

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

		self::$all[ $this->id ] = $this;

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

		$this->loaded = true;

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

		// With a valid plan, lower plans are hidden. Higher plans stay, so there is a place to enter an
		// upgraded key, unless they hold an expired key from before the site moved down a plan.
		if ( $this->is_replaced_by_other_plan() && ( self::plan_rank( $this ) < self::plan_rank( $plan ) || 'expired' === $this->get_status() ) ) {
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
					if ( $expiration ) {
						$description = sprintf(
							/* translators: the license key expiration date */
							__( 'Your license key expired on %s.', 'sunshine-photo-cart' ),
							date_i18n( get_option( 'date_format' ), strtotime( $expiration, current_time( 'timestamp' ) ) )
						);
					} else {
						$description = __( 'Your license key has expired.', 'sunshine-photo-cart' );
					}
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

			// Right after the status, as in "Your license key expired on ... Packages will stop working on ...".
			$stop = $this->stop_description();
			if ( $stop ) {
				$description .= ( $description ? ' ' : '' ) . esc_html( $stop );
			}

			$description .= $this->check_description();
		} else {
			$description = esc_html( $this->stop_description() );
		}

		// if ( empty( SPC()->plan ) || ! SPC()->plan->get_license_key() || ( SPC()->plan->get_license_key() && in_array( $this->get_id(), array( 'pro', 'plus', 'basic' ) ) ) ) {
			$key = ( $this->requires ) ? 'plan' : 'xaddon';

			$fields[ $key . $this->id ] = array(
				'name'             => $this->name,
				'id'               => 'license_' . $this->id,
				'type'             => 'license',
				'addon'            => $this->id,
				'status'           => $status,
				// Deactivating an add-on on a plan key would take the site off the whole plan.
				'can_deactivate'   => $this->requires || ! self::is_plan_key( $license_key ),
				'disabled'         => ( $this->is_valid() ) ? 'valid' : 'invalid',
				'description'      => $description,
				'hide_system_info' => true,
			);
			// }

			return $fields;

	}

	/**
	 * Lines for the Licenses screen: the site the key was activated for, when it was last
	 * checked, and a warning when checks have not got through for a while.
	 *
	 * @return string HTML.
	 */
	private function check_description() {

		$lines = array();

		// The "activated on a different site" messages already name the address.
		$url = SPC()->get_option( 'license_url_' . $this->id );
		if ( $url && ! in_array( $this->get_status(), array( 'site_inactive', 'no_activations_left' ), true ) ) {
			/* translators: %s is a site address, like www.example.com */
			$lines[] = sprintf( esc_html__( 'Activated for %s', 'sunshine-photo-cart' ), '<code>' . esc_html( untrailingslashit( preg_replace( '#^https?://#', '', $url ) ) ) . '</code>' );
		}

		$check = $this->get_last_check();
		if ( $check ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			if ( $this->is_check_stale() ) {
				$lines[] = '<strong>' . sprintf(
					/* translators: %1$s is a date, %2$s is an error message */
					esc_html__( 'This site has not been able to reach sunshinephotocart.com to check this license since %1$s, so the status above may be out of date. The last try said: %2$s', 'sunshine-photo-cart' ),
					esc_html( wp_date( $format, $check['last_ok'] ? $check['last_ok'] : $check['first_fail'] ) ),
					esc_html( $check['error'] )
				) . '</strong>';
			} elseif ( $check['last_ok'] ) {
				// The last check that got through, not a try that didn't.
				/* translators: %s is a date and time */
				$lines[] = sprintf( esc_html__( 'Last checked %s', 'sunshine-photo-cart' ), esc_html( wp_date( $format, $check['last_ok'] ) ) );
			}
		}

		return $lines ? '<br>' . implode( '<br>', $lines ) : '';

	}

	public function get_license_key() {
		return $this->license_key;
	}

	/**
	 * The license status. An add-on that follows a plan has the plan's status.
	 *
	 * @return string
	 */
	public function get_status() {
		$plan_id = $this->get_followed_plan_id();
		return SPC()->get_option( 'license_status_' . ( $plan_id ? $plan_id : $this->id ) );
	}

	public function is_valid() {
		$valid = ( 'valid' == $this->get_status() ) ? true : false;
		return $valid;
	}

	/**
	 * The license end date. An add-on that follows a plan has the plan's end date.
	 *
	 * @return string
	 */
	public function get_expiration() {
		$plan_id = $this->get_followed_plan_id();
		return SPC()->get_option( 'license_expiration_' . ( $plan_id ? $plan_id : $this->id ) );
	}

	/**
	 * The plan this add-on follows, like sunshine-photo-cart-pro, or blank.
	 *
	 * An add-on on a plan's key, once the store has confirmed the plan covers it, has no
	 * status or end date of its own: it reads the plan's. Its own saved copy could go
	 * stale when its check didn't get through, and then say it expired while the plan
	 * was renewed. This reads saved options, not SPC()->plans, because the list of active
	 * add-ons is built before the plans are.
	 *
	 * Its own saved status is "valid" or "expired" while the store says the plan covers it
	 * (the plan's key just expired with the plan). Any other answer means the plan doesn't
	 * cover it, and it keeps its own. A key from an expired plan the site has moved off of
	 * isn't followed either: the add-on keeps its own until the next check moves it to the
	 * plan in use.
	 *
	 * @return string
	 */
	private function get_followed_plan_id() {
		if ( $this->requires || ! $this->license_key || ! in_array( SPC()->get_option( 'license_status_' . $this->id ), array( 'valid', 'expired' ), true ) ) {
			return '';
		}
		$plan_id = self::plan_id_for_key( $this->license_key );
		if ( ! $plan_id || 'valid' === SPC()->get_option( 'license_status_' . $plan_id ) ) {
			return $plan_id;
		}
		foreach ( array_keys( self::PLAN_RANKS ) as $plan ) {
			if ( 'valid' === SPC()->get_option( 'license_status_sunshine-photo-cart-' . $plan ) ) {
				return '';
			}
		}
		return $plan_id;
	}

	/**
	 * The ID of the plan that uses a key, like sunshine-photo-cart-pro, or blank.
	 *
	 * @param string $license_key The license key.
	 * @return string
	 */
	private static function plan_id_for_key( $license_key ) {
		if ( empty( $license_key ) ) {
			return '';
		}
		foreach ( array_keys( self::PLAN_RANKS ) as $plan ) {
			if ( SPC()->get_option( 'license_sunshine-photo-cart-' . $plan ) === $license_key ) {
				return 'sunshine-photo-cart-' . $plan;
			}
		}
		return '';
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

			// Every key is sent with the form, so only activate the one whose button was pressed, or a key just typed in.
			$pressed = isset( $_POST['sunshine_license_activate'] ) ? sanitize_text_field( wp_unslash( $_POST['sunshine_license_activate'] ) ) : '';
			if ( $pressed !== $this->id && $license_key === $this->get_license_key() ) {
				return;
			}

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

		$license_data = self::api_request( 'activate_license', $license_key, $this->item_id );

		self::record_check( $this->id, $license_data );

		if ( is_wp_error( $license_data ) ) {

			if ( ! $hide_notice ) {
				/* translators: %1$s is the product name, %2$s is the error message */
				SPC()->notices->add_admin( $this->id . '_license_activation_fail', sprintf( __( 'License for %1$s failed to be activated: %2$s', 'sunshine-photo-cart' ), $this->name, $license_data->get_error_message() ) );
			}

			return false;

		} else {

			$result = self::save_activation( $this->id, $license_key, $license_data );
			if ( $result ) {
				$this->license_key = $license_key;
				if ( ! $hide_notice ) {
					/* translators: %s is the product name */
					SPC()->notices->add_admin( $this->id . '_license_activated', sprintf( __( 'License for %s has been activated', 'sunshine-photo-cart' ), $this->name ) );
				}
			}

			if ( $result && $this->requires ) {
				do_action( 'sunshine_plan_activated', $this );
			}

			return $result;

		}

	}

	public function deactivate() {

		// An add-on on a plan key only stops using it here. Telling the store would take the site off the whole plan.
		if ( ! $this->requires && self::is_plan_key( $this->get_license_key() ) ) {
			SPC()->update_option( 'license_status_' . $this->id, '' );
			SPC()->update_option( 'license_expiration_' . $this->id, '' );
			SPC()->update_option( 'license_url_' . $this->id, '' );
			/* translators: %s is the product name */
			SPC()->notices->add_admin( $this->id . '_license_deactivated', sprintf( __( 'License for %s has been deactivated', 'sunshine-photo-cart' ), $this->name ) );
			return;
		}

		$license_data = self::api_request( 'deactivate_license', $this->get_license_key(), $this->item_id );

		if ( is_wp_error( $license_data ) ) {
			SPC()->notices->add_admin( $this->id . '_license_error', $license_data->get_error_message(), 'error' );
			return;
		}

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

		if ( $this->requires ) {
			// A plan's Refresh checks every license on the site, so add-ons on its key are refreshed too.
			self::check_all();
		} else {
			$this->check_license();
		}

		wp_redirect( admin_url( 'admin.php?page=sunshine&section=license' ) );
		exit;

	}

	/**
	 * The Licenses part of the Sunshine Photo Cart section in System Info, so a support
	 * ticket shows what the site has saved and whether its checks get through. Shows the
	 * last 4 characters of a key, never the whole key. Times are UTC.
	 *
	 * @return array Site Health fields, keyed by ID.
	 */
	public static function system_info_fields() {

		$time   = function ( $timestamp ) {
			return $timestamp ? gmdate( 'Y-m-d H:i', $timestamp ) : 'never';
		};
		$plan   = self::get_valid_plan();
		$next   = wp_next_scheduled( 'sunshine_license_check' );
		$fields = array(
			'license_info_header'   => array(
				'label' => 'LICENSES',
				'value' => '================================',
			),
			'license_info_plan'     => array(
				'label' => 'Plan in use',
				'value' => $plan ? $plan->get_name() : 'None',
			),
			'license_info_site'     => array(
				'label' => 'This site',
				'value' => home_url(),
			),
			'license_info_schedule' => array(
				'label' => 'Weekly license check',
				'value' => ( $next ? 'next ' . $time( $next ) : 'not scheduled' ) . ', WP-Cron ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'turned off (DISABLE_WP_CRON)' : 'on' ),
			),
		);

		foreach ( self::$all as $license ) {

			$key = $license->get_license_key();
			if ( ! $key ) {
				$value = 'No key';
			} else {
				$plan_id = self::plan_id_for_key( $key );
				$parts   = array(
					'status: ' . ( $license->get_status() ? $license->get_status() : 'none' ),
					'expires: ' . ( $license->get_expiration() ? $license->get_expiration() : 'none' ),
					'key: ...' . substr( $key, -4 ),
					'item ID: ' . $license->item_id,
				);
				if ( ! $license->requires ) {
					$parts[] = 'uses: ' . ( $plan_id ? str_replace( 'sunshine-photo-cart-', '', $plan_id ) . ' plan key' : 'own key' );
					if ( $plan_id ) {
						// The add-on's own saved copy, which a plan key add-on doesn't use while it follows the plan.
						$parts[] = ( $license->get_followed_plan_id() ? 'follows the plan, own copy: ' : 'does not follow the plan, own: ' ) . ( SPC()->get_option( 'license_status_' . $license->id ) ? SPC()->get_option( 'license_status_' . $license->id ) : 'none' ) . ' / ' . ( SPC()->get_option( 'license_expiration_' . $license->id ) ? SPC()->get_option( 'license_expiration_' . $license->id ) : 'no date' );
					}
				}
				if ( $license->is_trial() ) {
					$parts[] = 'trial';
				}
				$url = SPC()->get_option( 'license_url_' . $license->id );
				if ( $url ) {
					$parts[] = 'activated for: ' . $url . ( untrailingslashit( $url ) !== untrailingslashit( home_url() ) ? ' (not this site\'s address)' : '' );
				}
				$check = $license->get_last_check();
				if ( $check ) {
					$parts[] = 'last check: ' . $time( $check['time'] ) . ' ' . ( $check['ok'] ? 'ok' : 'failed: ' . $check['error'] );
					if ( ! $check['ok'] ) {
						$parts[] = 'last ok: ' . $time( $check['last_ok'] );
						$parts[] = 'failing since: ' . $time( $check['first_fail'] ?? 0 );
					}
				} else {
					$parts[] = 'last check: not recorded yet';
				}
				$value = implode( ', ', $parts );
			}

			// Whether core turned it off, or when it will.
			$phase = $license->get_stop_phase();
			if ( 'off' === $phase ) {
				$value .= ', turned off: ' . $time( $license->get_switched_off_time() );
			} elseif ( $phase ) {
				$value .= ', stops: ' . $time( $license->get_stop_time() ) . ( 'due' === $phase ? ' (due, not turned off yet)' : '' );
			}

			$fields[ 'license_info_' . $license->id ] = array(
				'label' => $license->get_name(),
				'value' => $value,
			);
		}
		return $fields;

	}

	/**
	 * Check every license on the site now, with one notice for the lot.
	 */
	public static function check_all() {

		$started = self::now();

		// Plans first: add-ons on a plan key move to it once it is valid. When a check can't
		// reach the store the rest would wait out the same timeout, one after another, so stop.
		self::$quiet = true;
		$failed      = array();
		foreach ( self::plans_first() as $license ) {
			if ( ! $license->get_license_key() ) {
				continue;
			}
			$license->check_license();
			$check = $license->get_last_check();
			if ( $check && ! $check['ok'] && $check['time'] >= $started ) {
				$failed[] = $license->get_name();
				break;
			}
		}
		self::$quiet = false;

		if ( $failed ) {
			/* translators: %s is a list of product names */
			SPC()->notices->add_admin( 'sunshine_license_check_all', sprintf( __( 'Could not reach sunshinephotocart.com to check the license for %s. Try again in a few minutes.', 'sunshine-photo-cart' ), self::names_for_notice( $failed ) ), 'error' );
		} else {
			SPC()->notices->add_admin( 'sunshine_license_check_all', __( 'Your licenses have been checked', 'sunshine-photo-cart' ) );
		}

	}

	/**
	 * Every license on the site, plans first.
	 *
	 * @return SPC_License[]
	 */
	private static function plans_first() {
		$licenses = self::$all;
		uasort(
			$licenses,
			function ( $a, $b ) {
				return (int) ! $a->requires - (int) ! $b->requires;
			}
		);
		return $licenses;
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

		self::record_check( $this->id, $license_data );

		if ( is_wp_error( $license_data ) ) {

			$message = $license_data->get_error_message();

			SPC()->log( 'License check failed for ' . $this->name . ': ' . $message );

			if ( ! self::$quiet ) {
				/* translators: %1$s is the product name, %2$s is the error message */
				SPC()->notices->add_admin( $this->id . '_license_update_fail', sprintf( __( 'License for %1$s failed to be updated: %2$s', 'sunshine-photo-cart' ), $this->name, $message ) );
			}
		} else {

			if ( false !== $license_data->success && ! self::$quiet ) {
				/* translators: %s is the product name */
				SPC()->notices->add_admin( $this->id . '_license_updated', sprintf( __( 'License for %s has been updated', 'sunshine-photo-cart' ), $this->name ) );
			}

			$was_valid = $this->is_valid() && ! $this->is_expired();
			$this->save_license_data( $license_data );

			// The saved status looked fine before this check, so try the plan key now rather than next week.
			if ( 'valid' !== $license_data->license ) {
				$this->switch_to_plan_key();
			} elseif ( $this->requires && ! $was_valid ) {
				// A plan that just became valid again, like after a renewal. Add-ons check before
				// plans do, so they move to its key now rather than at next week's check.
				do_action( 'sunshine_plan_activated', $this );
			}

		}

	}

	/**
	 * Ask the store about a license key for this product on this site.
	 *
	 * @param string $license_key The license key to check.
	 * @return object|WP_Error The store's license data, or an error when the check did not get through.
	 */
	private function request_license_data( $license_key ) {
		return self::api_request( 'check_license', $license_key, $this->item_id );
	}

	/**
	 * Send a license request to the store for this site.
	 *
	 * @param string $action      The store action: activate_license, deactivate_license or check_license.
	 * @param string $license_key The license key.
	 * @param int    $item_id     The store product ID.
	 * @return object|WP_Error The store's license data, or an error when the request did not get through.
	 */
	public static function api_request( $action, $license_key, $item_id ) {

		$response = wp_remote_post(
			SUNSHINE_PHOTO_CART_STORE_URL . '?time=' . time(),
			array(
				'timeout' => 15,
				'body'    => array(
					'edd_action'  => $action,
					'license'     => $license_key,
					'item_id'     => $item_id,
					'url'         => home_url(),
					'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
				),
			)
		);

		// A 200 that isn't license data (a firewall challenge or maintenance page) is a
		// failed request too. Treating it as a success would wipe or fake the license status.
		$license_data = null;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$license_data = json_decode( wp_remote_retrieve_body( $response ) );
		}

		if ( ! is_object( $license_data ) || ! isset( $license_data->license ) ) {
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			return new WP_Error( 'sunshine_license_request_failed', __( 'Unknown error occured', 'sunshine-photo-cart' ) );
		}

		return $license_data;

	}

	/**
	 * The end date the store sent, or blank when it sent none.
	 *
	 * The store sends the end date even when it turns down an expired key, so the
	 * Licenses screen can say when it expired.
	 *
	 * @param object $license_data The store's license data.
	 * @return string
	 */
	public static function expiration_from( $license_data ) {
		return ( ! empty( $license_data->expires ) && is_string( $license_data->expires ) ) ? $license_data->expires : '';
	}

	/**
	 * Whether a key is one of the site's plan keys (Pro, Plus or Basic).
	 *
	 * One activation of a plan key covers every add-on on the site, so deactivating
	 * any add-on with that key would take the site off the whole plan.
	 *
	 * @param string $license_key The license key.
	 * @return bool
	 */
	public static function is_plan_key( $license_key ) {
		return '' !== self::plan_id_for_key( $license_key );
	}

	/**
	 * Save what the store said when a key was activated.
	 *
	 * @param string $id           The product's license ID, like advanced-shipping.
	 * @param string $license_key  The key that was activated.
	 * @param object $license_data The store's license data.
	 * @return bool True when the store activated the key.
	 */
	public static function save_activation( $id, $license_key, $license_data ) {
		$activated = false !== $license_data->success;
		SPC()->update_option( 'license_' . $id, $license_key );
		if ( $activated ) {
			// Only on success, so a failed try on a moved site keeps the old address for the "activated on" message.
			SPC()->update_option( 'license_url_' . $id, home_url() );
		}
		SPC()->update_option( 'license_expiration_' . $id, self::expiration_from( $license_data ) );
		// EDD returns failure-specific codes (e.g. no_activations_left) in `error`, not `license`.
		SPC()->update_option( 'license_status_' . $id, ( ! $activated && ! empty( $license_data->error ) ) ? $license_data->error : $license_data->license );
		return $activated;
	}

	/**
	 * Save what the store said about this license.
	 *
	 * @param object $license_data The store's license data.
	 */
	private function save_license_data( $license_data ) {
		SPC()->update_option( 'license_expiration_' . $this->id, self::expiration_from( $license_data ) );
		SPC()->update_option( 'license_status_' . $this->id, $license_data->license );
		SPC()->update_option( 'license_data_' . $this->id, $license_data );
	}

	/**
	 * Save when a license was last checked with the store and whether the check got through.
	 *
	 * A check that doesn't get through keeps the old status and end date, so without this
	 * nobody can tell the saved ones might be out of date.
	 *
	 * @param string          $id     The product's license ID, like advanced-shipping.
	 * @param object|WP_Error $result The store's license data, or the error from the request.
	 */
	public static function record_check( $id, $result ) {
		$previous = SPC()->get_option( 'license_check_' . $id );
		$previous = is_array( $previous ) ? $previous : array();
		$failed   = is_wp_error( $result );
		$check    = array(
			'time'       => self::now(),
			'ok'         => ! $failed,
			'error'      => $failed ? $result->get_error_message() : '',
			'last_ok'    => $failed ? ( $previous['last_ok'] ?? 0 ) : self::now(),
			// When this run of failed checks started, so the first failure is never counted as weeks of them.
			'first_fail' => $failed ? ( ! empty( $previous['first_fail'] ) && empty( $previous['ok'] ) ? $previous['first_fail'] : self::now() ) : 0,
		);
		SPC()->update_option( 'license_check_' . $id, $check );
	}

	/**
	 * The last check with the store: time, ok, error and last_ok (when one last got through), or false.
	 *
	 * @return array|false
	 */
	public function get_last_check() {
		$check = SPC()->get_option( 'license_check_' . $this->id );
		return is_array( $check ) ? $check : false;
	}

	/**
	 * Whether checks with the store have not got through for a while, so the saved
	 * status and end date may be out of date. One failed check is not enough: the next
	 * weekly one usually gets through.
	 *
	 * @return bool
	 */
	public function is_check_stale() {
		$check = $this->get_last_check();
		return $check && ! $check['ok'] && ! empty( $check['first_fail'] ) && $check['first_fail'] < self::now() - self::STALE_AFTER;
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
	 * An add-on bought on its own whose key still works (a lifetime key, say) is left
	 * alone. One still on an older plan's key, from before the site upgraded, moves over.
	 *
	 * @return bool True when this add-on was switched to the plan key.
	 */
	public function switch_to_plan_key() {

		if ( $this->requires || ( $this->is_valid() && ! $this->is_expired() && ! self::is_plan_key( $this->get_license_key() ) ) ) {
			return false;
		}

		$plan = self::get_valid_plan();
		if ( ! $plan || $plan->is_expired() || ! $plan->get_license_key() || $plan->get_license_key() === $this->get_license_key() ) {
			return false;
		}

		$license_data = $this->request_license_data( $plan->get_license_key() );
		if ( is_wp_error( $license_data ) ) {
			// Recorded, so a try that didn't get through is never taken as the store turning the key down.
			self::record_check( $this->id, $license_data );
			return false;
		}
		if ( 'valid' !== $license_data->license ) {
			return false;
		}

		$this->license_key = $plan->get_license_key();
		SPC()->update_option( 'license_' . $this->id, $this->license_key );
		SPC()->update_option( 'license_url_' . $this->id, home_url() );
		$this->save_license_data( $license_data );
		self::record_check( $this->id, $license_data );

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
		return ( strtotime( $expiration ) < self::now() ) ? true : false;
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

	/**
	 * Add this product when its license has expired.
	 *
	 * An add-on following a plan is left out: the plan is listed for it. So is a license whose
	 * last check didn't get through, as its saved end date may be out of date; it is listed
	 * as a problem instead, which links to the Licenses screen with the reason.
	 *
	 * @param array $expired_addons Product names keyed by ID.
	 * @return array
	 */
	public function expired_addons( $expired_addons ) {
		if ( $this->license_key && ! $this->is_replaced_by_other_plan() && ( $this->requires || ! $this->get_followed_plan_id() ) && ! $this->is_check_stale() && $this->is_expired() && in_array( $this->get_status(), array( 'expired', 'valid' ), true ) ) {
			$expired_addons[ $this->id ] = $this->name;
		}
		return $expired_addons;
	}

	/**
	 * Add this product when its saved key was turned down for a reason other than
	 * its end date (wrong key, wrong site, disabled), or its last check didn't get through.
	 *
	 * An add-on following a plan is left out: the plan is listed for it.
	 *
	 * @param array $problem_addons Product names keyed by ID.
	 * @return array
	 */
	public function problem_addons( $problem_addons ) {
		if ( ! $this->license_key || $this->is_replaced_by_other_plan() || $this->get_followed_plan_id() ) {
			return $problem_addons;
		}
		// No status means the key was deactivated on purpose, or never checked.
		$status = $this->get_status();
		if ( in_array( $status, array( '', false ), true ) ) {
			return $problem_addons;
		}
		if ( $this->is_check_stale() || ! in_array( $status, array( 'valid', 'expired' ), true ) ) {
			$problem_addons[ $this->id ] = $this->name;
		}
		return $problem_addons;
	}

	/**
	 * How far up the plans this one is: Basic 1, Plus 2, Pro 3, and 0 for an add-on.
	 *
	 * @param SPC_License $license A plan license.
	 * @return int
	 */
	private static function plan_rank( $license ) {
		return isset( self::PLAN_RANKS[ $license->requires ] ) ? self::PLAN_RANKS[ $license->requires ] : 0;
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

	/*
	 * Add-ons without an active license stop working.
	 *
	 * From STOP_START on, core turns off (deactivates) an add-on whose license ran out more than
	 * STOP_GRACE ago, or that has had no working key for STOP_SETUP. Before that it only warns.
	 * Turning the plugin off works for every add-on version, old ones included, since none of its
	 * code loads. Core remembers what it turned off and turns it back on once the license works
	 * again; an add-on the site owner turned off stays off. Nothing is turned off unless the store
	 * confirmed the license in the last two days, or has been unreachable for STOP_UNREACHABLE.
	 * All of this runs in wp-admin and WP-Cron only, never while a visitor loads a page.
	 */

	/**
	 * The time used for every license date. Tests move it with the sunshine_license_now filter.
	 *
	 * @return int
	 */
	public static function now() {
		return (int) apply_filters( 'sunshine_license_now', time() );
	}

	/**
	 * When add-ons without an active license start being turned off.
	 *
	 * @return int
	 */
	public static function stop_start() {
		return ( new DateTimeImmutable( self::STOP_START, wp_timezone() ) )->getTimestamp();
	}

	/**
	 * Hooks for turning add-ons off and on, and for telling the site owner. Called once, after the plans are set up.
	 */
	public static function init_stops() {
		self::register_switched_off();
		add_action( 'admin_init', array( __CLASS__, 'admin_enforce' ), 20 );
		add_action( 'sunshine_license_check', array( __CLASS__, 'cron_enforce' ), 99 );
		add_action( 'sunshine_license_enforce', array( __CLASS__, 'cron_enforce' ) );
		add_action( 'sunshine_daily', array( __CLASS__, 'daily_check' ) );
		foreach ( self::$all as $license ) {
			if ( $license->is_addon() ) {
				add_action( 'after_plugin_row_' . plugin_basename( $license->file ), array( $license, 'plugin_row' ) );
			}
		}
		add_action( 'admin_notices', array( __CLASS__, 'stop_admin_notice' ) );
	}

	/**
	 * Whether this is an add-on plugin (not a plan).
	 *
	 * @return bool
	 */
	private function is_addon() {
		return ! $this->requires && $this->file;
	}

	/**
	 * Why this add-on will stop working: "expired" (its license or its plan's ran out), "unlicensed"
	 * (no key, or a key the store turned down for this site), or blank when it won't.
	 *
	 * @return string
	 */
	public function get_stop_reason() {
		if ( ! $this->is_addon() || ! $this->loaded || 'lifetime' === $this->get_expiration() ) {
			return '';
		}
		if ( ! $this->get_license_key() ) {
			return 'unlicensed';
		}
		$status = $this->get_status();
		if ( 'valid' === $status ) {
			// No end date saved yet: the next check fills it in.
			return ( $this->get_expiration() && $this->is_expired() ) ? 'expired' : '';
		}
		return ( 'expired' === $status ) ? 'expired' : 'unlicensed';
	}

	/**
	 * When this add-on stops working, or 0 when it won't.
	 *
	 * @return int
	 */
	public function get_stop_time() {
		$reason = $this->get_stop_reason();
		if ( ! $reason ) {
			return 0;
		}
		if ( 'expired' === $reason ) {
			$ran_out = $this->get_expiration() ? strtotime( $this->get_expiration() ) : 0;
			$stop    = $ran_out ? $ran_out + self::STOP_GRACE : 0;
		} else {
			$since = (int) SPC()->get_option( 'license_unlicensed_since_' . $this->id );
			$stop  = ( $since ? $since : self::now() ) + self::STOP_SETUP;
		}
		return max( $stop, self::stop_start() );
	}

	/**
	 * Where this add-on is: "warn" (will stop), "due" (should have stopped, not turned off yet),
	 * "off" (core turned it off), or blank.
	 *
	 * @return string
	 */
	public function get_stop_phase() {
		if ( ! $this->get_stop_time() ) {
			return '';
		}
		if ( $this->get_switched_off_time() ) {
			return 'off';
		}
		return ( $this->get_stop_time() <= self::now() ) ? 'due' : 'warn';
	}

	/**
	 * The add-ons core turned off, keyed by license ID.
	 *
	 * @return array
	 */
	private static function get_switched_off() {
		$records = SPC()->get_option( 'license_switched_off' );
		return is_array( $records ) ? $records : array();
	}

	/**
	 * When core turned this add-on off, or 0.
	 *
	 * @return int
	 */
	public function get_switched_off_time() {
		$records = self::get_switched_off();
		return isset( $records[ $this->id ] ) ? (int) $records[ $this->id ]['time'] : 0;
	}

	/**
	 * Set up the licenses of add-ons core turned off, so they stay on the Licenses screen, are
	 * checked, and can be turned back on. Records for add-ons that were deleted are dropped.
	 */
	private static function register_switched_off() {
		$records = self::get_switched_off();
		$kept    = $records;
		foreach ( $records as $id => $record ) {
			$file = WP_PLUGIN_DIR . '/' . $record['plugin'];
			if ( ! file_exists( $file ) ) {
				unset( $kept[ $id ] );
				continue;
			}
			// Turned back on by hand: the add-on set up its own license.
			if ( ! isset( self::$all[ $id ] ) ) {
				// The file's own version: it may have been updated while it was off.
				$header = get_file_data( $file, array( 'Version' => 'Version' ) );
				new SPC_License( $record['item_id'], $record['name'], $header['Version'] ? $header['Version'] : $record['version'], $file );
			}
		}
		if ( $kept !== $records ) {
			SPC()->update_option( 'license_switched_off', $kept );
		}
	}

	/**
	 * Whether the store has had its say in the last two days: it answered, or that try failed
	 * too and it has not been reachable for STOP_UNREACHABLE. An old failed try isn't enough,
	 * since the store may be back and the license renewed. An add-on with no key needs no answer.
	 *
	 * @return bool
	 */
	private function is_stop_confirmed() {
		if ( ! $this->get_license_key() ) {
			return true;
		}
		$plan_id = $this->get_followed_plan_id();
		$license = ( $plan_id && isset( self::$all[ $plan_id ] ) ) ? self::$all[ $plan_id ] : $this;
		$check   = $license->get_last_check();
		if ( ! $check || $check['time'] < self::now() - 2 * DAY_IN_SECONDS ) {
			return false;
		}
		return $check['ok'] || ( ! empty( $check['first_fail'] ) && $check['first_fail'] < self::now() - self::STOP_UNREACHABLE );
	}

	/**
	 * Ask the store about this add-on now (or its plan, for an add-on on the plan key), unless that was tried in the last hour.
	 */
	private function refresh_for_stop() {
		$plan_id = $this->get_followed_plan_id();
		$license = ( $plan_id && isset( self::$all[ $plan_id ] ) ) ? self::$all[ $plan_id ] : $this;
		$check   = $license->get_last_check();
		if ( $check && $check['time'] > self::now() - HOUR_IN_SECONDS ) {
			return;
		}
		$license->check_license();
	}

	/**
	 * Remember when this add-on started having no working license, for the STOP_SETUP window.
	 */
	private function track_unlicensed() {
		$key   = 'license_unlicensed_since_' . $this->id;
		$since = SPC()->get_option( $key );
		if ( 'unlicensed' === $this->get_stop_reason() ) {
			if ( ! $since ) {
				SPC()->update_option( $key, self::now() );
			}
		} elseif ( $since ) {
			SPC()->update_option( $key, '' );
		}
	}

	/**
	 * Turn this add-on's plugin off and remember that core did it.
	 */
	private function switch_off() {
		$plugin  = plugin_basename( $this->file );
		$network = is_multisite() && is_plugin_active_for_network( $plugin );
		// Silent: the add-on's own deactivation step would take its key off the store.
		deactivate_plugins( $plugin, true, $network );
		$records = self::get_switched_off();
		if ( empty( $records[ $this->id ] ) ) {
			$records[ $this->id ] = array(
				'plugin'  => $plugin,
				'item_id' => $this->item_id,
				'name'    => $this->name,
				'version' => $this->version,
				'time'    => self::now(),
				'network' => $network,
			);
			SPC()->update_option( 'license_switched_off', $records );
		}
		SPC()->log( $this->name . ' was turned off because it has no active license' );
	}

	/**
	 * Turn back on an add-on core turned off, now that its license works.
	 *
	 * @param array $record What was saved when it was turned off.
	 */
	private function switch_back_on( $record ) {
		if ( ! is_plugin_active( $record['plugin'] ) ) {
			// Silent: the add-on's own activation step would ask the store to activate the key again.
			$result = activate_plugin( $record['plugin'], '', ! empty( $record['network'] ), true );
			// WordPress can return an error after turning it on (it printed output), so check.
			if ( ! is_plugin_active( $record['plugin'] ) ) {
				// Kept, so the next run tries again.
				SPC()->log( $this->name . ' could not be turned back on: ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'unknown error' ) );
				return;
			}
		}
		$records = self::get_switched_off();
		unset( $records[ $this->id ] );
		SPC()->update_option( 'license_switched_off', $records );
		SPC()->log( $this->name . ' was turned back on because its license is active again' );
	}

	/**
	 * Turn add-ons off and back on as their licenses say.
	 *
	 * @param bool $can_ask_store Whether a store check may be made. False on a page load in wp-admin.
	 * @return bool True when an add-on is due to stop but needs a store check first.
	 */
	public static function enforce( $can_ask_store = true ) {

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$records     = self::get_switched_off();
		$needs_store = false;
		$store_down  = false;
		self::$quiet = true;

		foreach ( self::plans_first() as $license ) {
			if ( ! $license->is_addon() ) {
				continue;
			}
			$license->track_unlicensed();
			$record = $records[ $license->id ] ?? null;
			$stop   = $license->get_stop_time();

			if ( ! $stop ) {
				if ( $record ) {
					$license->switch_back_on( $record );
				}
				continue;
			}
			if ( $stop > self::now() ) {
				continue;
			}
			$plugin = plugin_basename( $license->file );
			if ( ! is_plugin_active( $plugin ) ) {
				// Already off: turned off by core, or by the site owner.
				continue;
			}
			// One site's licenses don't decide for every site on a network.
			if ( is_multisite() && is_plugin_active_for_network( $plugin ) ) {
				continue;
			}
			// With a record, it was turned back on by hand while its license still doesn't work: off
			// again, the store already had its say. Without one, the store gets a last word first. An
			// add-on with no key tries the site's plan key, in case the weekly check never got to it.
			$no_key_plan = ! $license->get_license_key() && self::get_valid_plan();
			if ( ! $record && ( $no_key_plan || ! $license->is_stop_confirmed() ) ) {
				if ( ! $can_ask_store || $store_down ) {
					$needs_store = true;
					continue;
				}
				$started = self::now();
				if ( $no_key_plan ) {
					$license->switch_to_plan_key();
				} else {
					$license->refresh_for_stop();
				}
				// The store didn't answer: the rest would each wait out the same timeout, so stop asking this run.
				$check   = $license->get_last_check();
				$plan_id = $license->get_followed_plan_id();
				if ( $plan_id && isset( self::$all[ $plan_id ] ) ) {
					$check = self::$all[ $plan_id ]->get_last_check();
				}
				if ( $check && ! $check['ok'] && $check['time'] >= $started ) {
					$store_down = true;
					if ( $no_key_plan ) {
						continue;
					}
				}
				$stop = $license->get_stop_time();
				if ( ! $stop || $stop > self::now() || ! $license->is_stop_confirmed() ) {
					continue;
				}
			}
			$license->switch_off();
			if ( $record && is_admin() && ! wp_doing_cron() ) {
				/* translators: %s is the add-on name */
				SPC()->notices->add_admin( 'license_stop_again_' . $license->id, sprintf( __( '%s was turned off again because it does not have an active license. Renew or activate its license on the Licenses screen to turn it back on.', 'sunshine-photo-cart' ), $license->get_name() ), 'error' );
				// WordPress would also say "Plugin activated." on this page, which is no longer true.
				unset( $_GET['activate'] );
			}
		}

		self::$quiet = false;
		return $needs_store;

	}

	/**
	 * On wp-admin page loads: turn add-ons off and on from what the site already knows. When one
	 * needs a store check first, that runs in the background (inline, at most hourly, where
	 * WP-Cron is turned off), so the page never waits on the store.
	 */
	public static function admin_enforce() {
		if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
			return;
		}
		if ( ! self::enforce( false ) || get_transient( 'sunshine_license_enforce_wait' ) ) {
			return;
		}
		set_transient( 'sunshine_license_enforce_wait', 1, HOUR_IN_SECONDS );
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			self::enforce( true );
		} else {
			wp_schedule_single_event( time(), 'sunshine_license_enforce' );
		}
	}

	/**
	 * In WP-Cron: turn add-ons off and on, asking the store when needed.
	 */
	public static function cron_enforce() {
		self::enforce( true );
	}

	/**
	 * Daily: check the licenses whose end date is near or recently passed, so a renewal or a
	 * failed payment is seen within a day instead of a week. Then turn add-ons off and on.
	 */
	public static function daily_check() {
		self::$quiet = true;
		foreach ( self::plans_first() as $license ) {
			$expiration = $license->get_expiration();
			// Add-ons on a plan key have the plan's date: checking the plan covers them.
			if ( ! $license->get_license_key() || ! $expiration || 'lifetime' === $expiration || $license->get_followed_plan_id() ) {
				continue;
			}
			$end = strtotime( $expiration );
			if ( $end > self::now() + self::STOP_DAILY_BEFORE || $end < self::now() - self::STOP_DAILY_AFTER ) {
				continue;
			}
			$check = $license->get_last_check();
			if ( $check && $check['time'] > self::now() - 12 * HOUR_IN_SECONDS ) {
				continue;
			}
			$started = self::now();
			$license->check_license();
			$check = $license->get_last_check();
			if ( $check && ! $check['ok'] && $check['time'] >= $started ) {
				// The store can't be reached: don't wait out the timeout for every license.
				break;
			}
		}
		self::$quiet = false;
		self::enforce( true );
	}

	/**
	 * Add-ons that will stop, should have stopped, or were turned off, grouped the way they are
	 * named in messages: by phase, reason, the plan they follow and the date. Turned-off first.
	 *
	 * @return array[] Each: phase, reason, plan (plan name or blank), date, ran_out (end date or blank), names.
	 */
	public static function get_stop_groups() {
		$groups = array();
		foreach ( self::$all as $license ) {
			$phase = $license->get_stop_phase();
			if ( ! $phase ) {
				continue;
			}
			$plan_id = $license->get_followed_plan_id();
			$reason  = $license->get_stop_reason();
			$date    = ( 'off' === $phase ) ? $license->get_switched_off_time() : $license->get_stop_time();
			$key     = $phase . '|' . $reason . '|' . $plan_id . '|' . wp_date( 'Y-m-d', $date );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'phase'   => $phase,
					'reason'  => $reason,
					'plan'    => ( $plan_id && isset( self::$all[ $plan_id ] ) ) ? self::$all[ $plan_id ]->get_name() : '',
					'date'    => $date,
					'ran_out' => ( 'expired' === $reason ) ? $license->get_expiration() : '',
					'names'   => array(),
				);
			}
			$groups[ $key ]['names'][] = $license->get_name();
		}
		$rank = array(
			'off'  => 0,
			'due'  => 1,
			'warn' => 2,
		);
		uasort(
			$groups,
			function ( $a, $b ) use ( $rank ) {
				$by_phase = $rank[ $a['phase'] ] - $rank[ $b['phase'] ];
				return $by_phase ? $by_phase : $a['date'] - $b['date'];
			}
		);
		foreach ( $groups as &$group ) {
			natcasesort( $group['names'] );
			$group['names'] = array_values( $group['names'] );
		}
		return array_values( $groups );
	}

	/**
	 * A date for a stop message, in the site's date format.
	 *
	 * @param int|string $date A timestamp, or a date the store sent.
	 * @return string
	 */
	private static function stop_date( $date ) {
		$timestamp = is_numeric( $date ) ? (int) $date : strtotime( (string) $date );
		return $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : '';
	}

	/**
	 * The renewal page in the customer's account.
	 *
	 * @return string
	 */
	private static function renew_url() {
		return 'https://www.sunshinephotocart.com/account/licenses/?utm_source=plugin&utm_medium=link&utm_campaign=expired-license';
	}

	/**
	 * The one-line message for the bar at the top of Sunshine's admin screens.
	 *
	 * @param array $group A group from get_stop_groups().
	 * @return string Plain text.
	 */
	public static function stop_headline( $group ) {
		$names = self::names_for_notice( $group['names'] );
		$count = count( $group['names'] );
		$date  = self::stop_date( $group['date'] );

		if ( 'expired' === $group['reason'] && $group['plan'] ) {
			if ( 'off' === $group['phase'] ) {
				/* translators: %1$s is a plan name, like Sunshine Photo Cart Pro, %2$s is a date */
				return sprintf( __( 'Your license for %1$s expired, so its add-ons stopped working on %2$s', 'sunshine-photo-cart' ), $group['plan'], $date );
			}
			if ( 'due' === $group['phase'] ) {
				/* translators: %s is a plan name, like Sunshine Photo Cart Pro */
				return sprintf( __( 'Your license for %s has expired, so its add-ons are about to stop working', 'sunshine-photo-cart' ), $group['plan'] );
			}
			/* translators: %1$s is a plan name, like Sunshine Photo Cart Pro, %2$s is a date */
			return sprintf( __( 'Your license for %1$s has expired, so its add-ons will stop working on %2$s', 'sunshine-photo-cart' ), $group['plan'], $date );
		}

		if ( 'expired' === $group['reason'] ) {
			if ( 'off' === $group['phase'] ) {
				/* translators: %1$s is a list of add-on names, %2$s is a date */
				return sprintf( _n( 'Your license for %1$s expired, so it stopped working on %2$s', 'Your licenses for %1$s expired, so they stopped working on %2$s', $count, 'sunshine-photo-cart' ), $names, $date );
			}
			if ( 'due' === $group['phase'] ) {
				/* translators: %s is a list of add-on names */
				return sprintf( _n( 'Your license for %s has expired, so it is about to stop working', 'Your licenses for %s have expired, so they are about to stop working', $count, 'sunshine-photo-cart' ), $names );
			}
			/* translators: %1$s is a list of add-on names, %2$s is a date */
			return sprintf( _n( 'Your license for %1$s has expired, so it will stop working on %2$s', 'Your licenses for %1$s have expired, so they will stop working on %2$s', $count, 'sunshine-photo-cart' ), $names, $date );
		}

		if ( 'off' === $group['phase'] ) {
			/* translators: %1$s is a list of add-on names, %2$s is a date */
			return sprintf( _n( '%1$s does not have an active license, so it stopped working on %2$s', '%1$s do not have an active license, so they stopped working on %2$s', $count, 'sunshine-photo-cart' ), $names, $date );
		}
		if ( 'due' === $group['phase'] ) {
			/* translators: %s is a list of add-on names */
			return sprintf( _n( '%s does not have an active license, so it is about to stop working', '%s do not have an active license, so they are about to stop working', $count, 'sunshine-photo-cart' ), $names );
		}
		/* translators: %1$s is a list of add-on names, %2$s is a date */
		return sprintf( _n( '%1$s does not have an active license, so it will stop working on %2$s', '%1$s do not have an active license, so they will stop working on %2$s', $count, 'sunshine-photo-cart' ), $names, $date );
	}

	/**
	 * The full sentence for the notice on the Dashboard and Plugins screens.
	 *
	 * @param array $group A group from get_stop_groups().
	 * @return string Plain text.
	 */
	public static function stop_sentence( $group ) {
		$names = wp_sprintf_l( '%l', $group['names'] );
		$count = count( $group['names'] );
		$date  = self::stop_date( $group['date'] );

		if ( 'expired' === $group['reason'] && $group['plan'] ) {
			if ( 'off' === $group['phase'] ) {
				/* translators: %1$s is a date, %2$s is a plan name, like Sunshine Photo Cart Pro, %3$s is a list of add-on names */
				return sprintf( __( 'These add-ons were turned off on %1$s because your %2$s license expired: %3$s.', 'sunshine-photo-cart' ), $date, $group['plan'], $names );
			}
			$ran_out = self::stop_date( $group['ran_out'] );
			if ( 'due' === $group['phase'] && $ran_out ) {
				/* translators: %1$s is a plan name, like Sunshine Photo Cart Pro, %2$s is a date, %3$s is a list of add-on names */
				return sprintf( __( 'Your %1$s license expired on %2$s, so these add-ons are about to stop working: %3$s.', 'sunshine-photo-cart' ), $group['plan'], $ran_out, $names );
			}
			if ( 'due' === $group['phase'] ) {
				/* translators: %1$s is a plan name, like Sunshine Photo Cart Pro, %2$s is a list of add-on names */
				return sprintf( __( 'Your %1$s license has expired, so these add-ons are about to stop working: %2$s.', 'sunshine-photo-cart' ), $group['plan'], $names );
			}
			if ( $ran_out ) {
				/* translators: %1$s is a plan name, like Sunshine Photo Cart Pro, %2$s is the date it expired, %3$s is the date the add-ons stop, %4$s is a list of add-on names */
				return sprintf( __( 'Your %1$s license expired on %2$s, so these add-ons will stop working on %3$s: %4$s.', 'sunshine-photo-cart' ), $group['plan'], $ran_out, $date, $names );
			}
			/* translators: %1$s is a plan name, like Sunshine Photo Cart Pro, %2$s is the date the add-ons stop, %3$s is a list of add-on names */
			return sprintf( __( 'Your %1$s license has expired, so these add-ons will stop working on %2$s: %3$s.', 'sunshine-photo-cart' ), $group['plan'], $date, $names );
		}

		if ( 'expired' === $group['reason'] ) {
			if ( 'off' === $group['phase'] ) {
				/* translators: %1$s is a list of add-on names, %2$s is a date */
				return sprintf( _n( '%1$s was turned off on %2$s because its license expired.', '%1$s were turned off on %2$s because their licenses expired.', $count, 'sunshine-photo-cart' ), $names, $date );
			}
			if ( 'due' === $group['phase'] ) {
				/* translators: %s is a list of add-on names */
				return sprintf( _n( 'The license for %s has expired, so it is about to stop working.', 'The licenses for %s have expired, so they are about to stop working.', $count, 'sunshine-photo-cart' ), $names );
			}
			/* translators: %1$s is a list of add-on names, %2$s is a date */
			return sprintf( _n( 'The license for %1$s has expired, so it will stop working on %2$s.', 'The licenses for %1$s have expired, so they will stop working on %2$s.', $count, 'sunshine-photo-cart' ), $names, $date );
		}

		if ( 'off' === $group['phase'] ) {
			/* translators: %1$s is a list of add-on names, %2$s is a date */
			return sprintf( _n( '%1$s was turned off on %2$s because it does not have an active license.', '%1$s were turned off on %2$s because they do not have an active license.', $count, 'sunshine-photo-cart' ), $names, $date );
		}
		if ( 'due' === $group['phase'] ) {
			/* translators: %s is a list of add-on names */
			return sprintf( _n( '%s does not have an active license, so it is about to stop working.', '%s do not have an active license, so they are about to stop working.', $count, 'sunshine-photo-cart' ), $names );
		}
		/* translators: %1$s is a list of add-on names, %2$s is a date */
		return sprintf( _n( '%1$s does not have an active license, so it will stop working on %2$s.', '%1$s do not have an active license, so they will stop working on %2$s.', $count, 'sunshine-photo-cart' ), $names, $date );
	}

	/**
	 * The bar at the top of Sunshine's admin screens, for the most urgent group, or false.
	 *
	 * @return array|false Text, button label and button URL.
	 */
	public static function get_header_notice() {
		$groups = self::get_stop_groups();
		if ( ! $groups ) {
			return false;
		}
		$group = $groups[0];
		return array(
			'text'   => self::stop_headline( $group ),
			'button' => ( 'expired' === $group['reason'] ) ? __( 'Renew now', 'sunshine-photo-cart' ) : __( 'View licenses', 'sunshine-photo-cart' ),
			'url'    => ( 'expired' === $group['reason'] ) ? self::renew_url() : admin_url( 'admin.php?page=sunshine&section=license' ),
		);
	}

	/**
	 * The notice on the Dashboard and Plugins screens. Its dismiss key is made from what it says
	 * (each phase, date and add-on), so closing the warning doesn't hide the notice that the
	 * add-ons were turned off, or a warning about a different add-on.
	 */
	public static function stop_admin_notice() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$groups = self::get_stop_groups();
		if ( ! $groups ) {
			return;
		}
		$key     = 'license_stop_' . $groups[0]['phase'] . '_' . md5( wp_json_encode( $groups ) );
		$notices = SPC()->notices->get_admin_notices();
		if ( ! empty( $notices[ $key ]['dismissed'] ) ) {
			return;
		}

		$sentences = array();
		// Before the first stop date, say why this is new.
		if ( self::now() < self::stop_start() ) {
			/* translators: %s is a date */
			$sentences[] = sprintf( __( 'Starting %s, add-ons stop working when their license has expired.', 'sunshine-photo-cart' ), self::stop_date( self::stop_start() ) );
		}
		$renew = false;
		foreach ( $groups as $group ) {
			$sentences[] = self::stop_sentence( $group );
			$renew       = $renew || 'expired' === $group['reason'];
		}
		$sentences[] = __( 'Your settings and orders are kept, and renewing turns them back on.', 'sunshine-photo-cart' );

		echo '<div class="notice notice-warning is-dismissible sunshine-notice" data-notice="' . esc_attr( $key ) . '">';
		echo '<p><strong>' . esc_html__( 'Sunshine Photo Cart:', 'sunshine-photo-cart' ) . '</strong> ' . esc_html( implode( ' ', $sentences ) ) . '</p>';
		echo '<p>';
		if ( $renew ) {
			echo '<a href="' . esc_url( self::renew_url() ) . '" target="_blank" class="button button-primary">' . esc_html__( 'Renew now', 'sunshine-photo-cart' ) . '</a> ';
		}
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=sunshine&section=license' ) ) . '" class="button">' . esc_html__( 'View licenses', 'sunshine-photo-cart' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * A line under this add-on's row on the Plugins screen.
	 *
	 * @param string $plugin_file The plugin's basename.
	 */
	public function plugin_row( $plugin_file ) {
		$phase = $this->get_stop_phase();
		if ( ! $phase ) {
			return;
		}
		$name = $this->get_name();
		$date = self::stop_date( 'off' === $phase ? $this->get_switched_off_time() : $this->get_stop_time() );
		if ( 'expired' === $this->get_stop_reason() ) {
			$link = '<a href="' . esc_url( self::renew_url() ) . '" target="_blank">' . esc_html__( 'Renew your license', 'sunshine-photo-cart' ) . '</a>';
			$ran  = self::stop_date( $this->get_expiration() );
			if ( 'off' === $phase ) {
				/* translators: %1$s is the add-on name, %2$s is a date, %3$s is a "Renew your license" link */
				$text = sprintf( esc_html__( '%1$s was turned off on %2$s because its license expired. Your settings are kept. %3$s to turn it back on.', 'sunshine-photo-cart' ), esc_html( $name ), esc_html( $date ), $link );
			} elseif ( 'due' === $phase && $ran ) {
				/* translators: %1$s is the add-on name, %2$s is the date its license expired, %3$s is a "Renew your license" link */
				$text = sprintf( esc_html__( 'The license for %1$s expired on %2$s, so it is about to stop working. %3$s to keep it running.', 'sunshine-photo-cart' ), esc_html( $name ), esc_html( $ran ), $link );
			} elseif ( 'due' === $phase ) {
				/* translators: %1$s is the add-on name, %2$s is a "Renew your license" link */
				$text = sprintf( esc_html__( 'The license for %1$s has expired, so it is about to stop working. %2$s to keep it running.', 'sunshine-photo-cart' ), esc_html( $name ), $link );
			} elseif ( $ran ) {
				/* translators: %1$s is the add-on name, %2$s is the date its license expired, %3$s is the date it stops, %4$s is a "Renew your license" link */
				$text = sprintf( esc_html__( 'The license for %1$s expired on %2$s, so it will stop working on %3$s. %4$s to keep it running.', 'sunshine-photo-cart' ), esc_html( $name ), esc_html( $ran ), esc_html( $date ), $link );
			} else {
				/* translators: %1$s is the add-on name, %2$s is the date it stops, %3$s is a "Renew your license" link */
				$text = sprintf( esc_html__( 'The license for %1$s has expired, so it will stop working on %2$s. %3$s to keep it running.', 'sunshine-photo-cart' ), esc_html( $name ), esc_html( $date ), $link );
			}
		} else {
			$link = '<a href="' . esc_url( admin_url( 'admin.php?page=sunshine&section=license' ) ) . '">' . esc_html__( 'Enter your license key', 'sunshine-photo-cart' ) . '</a>';
			if ( 'off' === $phase ) {
				/* translators: %1$s is the add-on name, %2$s is a date, %3$s is an "Enter your license key" link */
				$text = sprintf( esc_html__( '%1$s was turned off on %2$s because it does not have an active license. %3$s to turn it back on.', 'sunshine-photo-cart' ), esc_html( $name ), esc_html( $date ), $link );
			} elseif ( 'due' === $phase ) {
				/* translators: %1$s is the add-on name, %2$s is an "Enter your license key" link */
				$text = sprintf( esc_html__( '%1$s does not have an active license, so it is about to stop working. %2$s to keep it running.', 'sunshine-photo-cart' ), esc_html( $name ), $link );
			} else {
				/* translators: %1$s is the add-on name, %2$s is a date, %3$s is an "Enter your license key" link */
				$text = sprintf( esc_html__( '%1$s does not have an active license, so it will stop working on %2$s. %3$s to keep it running.', 'sunshine-photo-cart' ), esc_html( $name ), esc_html( $date ), $link );
			}
		}
		global $wp_list_table;
		$active  = is_plugin_active( $plugin_file );
		$columns = ( $wp_list_table && method_exists( $wp_list_table, 'get_column_count' ) ) ? $wp_list_table->get_column_count() : 3;
		echo '<tr class="plugin-update-tr' . ( $active ? ' active' : '' ) . '"><td colspan="' . (int) $columns . '" class="plugin-update colspanchange"><div class="update-message notice inline notice-error notice-alt"><p>' . wp_kses_post( $text ) . '</p></div></td></tr>';
	}

	/**
	 * The stop line for this license on the Licenses screen: for an add-on, when it stops or was
	 * turned off; for a plan, the same for the add-ons on its key.
	 *
	 * @return string Plain text, or blank.
	 */
	private function stop_description() {
		if ( $this->requires ) {
			foreach ( self::get_stop_groups() as $group ) {
				if ( $group['plan'] !== $this->name ) {
					continue;
				}
				if ( 'off' === $group['phase'] ) {
					/* translators: %s is a date */
					return sprintf( __( 'The add-ons below were turned off on %s. Your settings and orders are kept, and renewing turns them back on.', 'sunshine-photo-cart' ), self::stop_date( $group['date'] ) );
				}
				if ( 'due' === $group['phase'] ) {
					return __( 'The add-ons below are about to stop working. Your settings and orders are kept, and renewing turns them back on.', 'sunshine-photo-cart' );
				}
				if ( self::stop_start() === $group['date'] ) {
					/* translators: %s is a date */
					return sprintf( __( 'Starting %s, add-ons with an expired license stop working, so the add-ons below will stop on that date. Your settings and orders are kept, and renewing turns them back on.', 'sunshine-photo-cart' ), self::stop_date( $group['date'] ) );
				}
				/* translators: %s is a date */
				return sprintf( __( 'The add-ons below will stop working on %s. Your settings and orders are kept, and renewing turns them back on.', 'sunshine-photo-cart' ), self::stop_date( $group['date'] ) );
			}
			return '';
		}

		$phase = $this->get_stop_phase();
		if ( 'off' === $phase ) {
			/* translators: %1$s is the add-on name, %2$s is a date */
			return sprintf( __( '%1$s was turned off on %2$s. Renewing turns it back on.', 'sunshine-photo-cart' ), $this->name, self::stop_date( $this->get_switched_off_time() ) );
		}
		if ( 'due' === $phase ) {
			/* translators: %s is the add-on name */
			return sprintf( __( '%s is about to stop working.', 'sunshine-photo-cart' ), $this->name );
		}
		if ( 'warn' === $phase ) {
			/* translators: %1$s is the add-on name, %2$s is a date */
			return sprintf( __( '%1$s will stop working on %2$s.', 'sunshine-photo-cart' ), $this->name, self::stop_date( $this->get_stop_time() ) );
		}
		return '';
	}

}
