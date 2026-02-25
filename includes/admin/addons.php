<?php
/**
 * Check if a Sunshine addon is active.
 *
 * @param string $addon The addon slug (e.g., 'volume-galleries', 'cloud-storage').
 * @return bool True if the addon plugin is active, false otherwise.
 */
function is_sunshine_addon_active( $addon ) {
	// Check if the plugin is actually installed and activated in WordPress.
	// Plugin path follows pattern: sunshine-{addon}/{addon}.php
	$plugin_path = 'sunshine-' . $addon . '/' . $addon . '.php';

	// Include plugin.php if not already loaded (needed for is_plugin_active).
	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	return is_plugin_active( $plugin_path );

	// Original license-based check - commented out for later restoration.
	// if ( array_key_exists( $addon, SPC()->active_addons ) ) {
	// 	return true;
	// }
	// return false;
}

function sunshine_addon_activation( $file, $name, $item_id ) {

	$plugin_basename = plugin_basename( $file );
	$filename        = basename( $file, '.php' );
	$license_key     = sunshine_addon_get_license_key( $filename );

	if ( ! $license_key ) {
		/* translators: %s is the addon name */
		SPC()->notices->add_admin( 'sunshine_license_invalid_' . $filename, sprintf( __( 'Could not automatically retrieve the license key for %s', 'sunshine-photo-cart' ), $name ), 'error' );
		return;
	}

	SPC()->update_option( 'license_' . $filename, $license_key );

	// Data to send to the API.
	$api_params = array(
		'edd_action'  => 'activate_license',
		'license'     => $license_key,
		'item_id'     => $item_id,
		'url'         => home_url(),
		'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
	);

	// Call the API.
	$response = wp_remote_post(
		SUNSHINE_PHOTO_CART_STORE_URL,
		array(
			'timeout'   => 15,
			'sslverify' => false,
			'body'      => $api_params,
		)
	);

	// Make sure there are no errors.
	if ( is_wp_error( $response ) ) {
		/* translators: %s is the error message */
		SPC()->notices->add_admin( 'sunshine_license_no_connection', sprintf( __( 'Your licenses could not be activated because your server failed to connect to SunshinePhotoCart.com: %s', 'sunshine-photo-cart' ), $response->get_error_message() ), 'error' );
		return;
	}

	// Tell WordPress to look for updates.
	set_site_transient( 'update_plugins', null );

	$message = '';

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
		} else {
			$message = __( 'Unknown error occured', 'sunshine-photo-cart' );
		}

		/* translators: %1$s is the addon name, %2$s is the error message */
		SPC()->notices->add_admin( $item_id . '_license_activation_fail', sprintf( __( 'License for %1$s failed to be activated: %2$s', 'sunshine-photo-cart' ), $name, $message ) );

	} else {

		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( false === $license_data->success ) {
			SPC()->update_option( 'license_expiration_' . $filename, '' );
		} else {
			SPC()->update_option( 'license_expiration_' . $filename, $license_data->expires );
		}

		SPC()->update_option( 'license_status_' . $filename, $license_data->license );

	}

}

function sunshine_addon_deactivation( $file, $name, $item_id ) {

	$plugin_basename = plugin_basename( $file );
	$filename        = basename( $file, '.php' );
	$license_key     = SPC()->get_option( 'license_' . $filename );

	if ( empty( $license_key ) ) {
		return;
	}

	// Data to send to the API
	$api_params = array(
		'edd_action'  => 'deactivate_license',
		'license'     => $license_key,
		'item_id'     => $item_id,
		'url'         => home_url(),
		'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
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

		SPC()->notices->add_admin( $item_id . '_license_error', $message, 'error' );
		return;

	}

	SPC()->update_option( 'license_status_' . $filename, '' );
	SPC()->update_option( 'license_expiration_' . $filename, '' );

}

function sunshine_addon_get_license_key( $name ) {

	if ( empty( SPC()->plan ) || ! SPC()->plan->is_valid() ) {
		return false;
	}

	$pro_license_key = SPC()->plan->get_license_key();
	if ( empty( $pro_license_key ) ) {
		return false;
	}

	return $pro_license_key;

	// Get license data from sunshine website
	$url     = SUNSHINE_PHOTO_CART_STORE_URL . '/?sunshine_get_license&referrer=' . $_SERVER['SERVER_NAME'] . '&plugin=' . $name . '&license_key=' . $pro_license_key;
	$feed    = wp_remote_get( esc_url_raw( $url ), array( 'sslverify' => false ) );
	$license = '';

	if ( ! is_wp_error( $feed ) ) {
		if ( isset( $feed['body'] ) && strlen( $feed['body'] ) > 0 ) {
			$license = wp_remote_retrieve_body( $feed );
		}
	}

	return $license;

}

function sunshine_addons_page() {

	$addons = sunshine_get_addon_data( true ); // TODO: Do not force this.
	add_thickbox();
	$current_plan = ( ! empty( SPC()->plan ) && SPC()->plan->is_valid() ) ? SPC()->plan->get_id() : 'free';
	?>
	<div class="wrap">
		<h1>Add-ons</h1>
		<?php
		$categories = array();
		foreach ( $addons as $addon ) {
			if ( ! empty( $addon['category'] ) ) {
				$categories[ $addon['category'] ] = $addon['category_name'];
			}
		}
		if ( ! empty( $categories ) ) {
			?>
		<p id="sunshine--addons--categories">
			<a href="#all" data-category="all" class="active">All</a>
			<?php foreach ( $categories as $key => $name ) { ?>
				<a href="#<?php echo esc_attr( $key ); ?>" data-category="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $name ); ?></a>
			<?php } ?>
		</p>
		<script>
		jQuery( '#sunshine--addons--categories a' ).on( 'click', function(){
			jQuery( '#sunshine--addons--categories a' ).removeClass( 'active' );
			jQuery( this ).addClass( 'active' );
			var category = jQuery( this ).data( 'category' );
			if ( category == 'all' ) {
				jQuery( '#sunshine--addons li' ).show();
			} else {
				jQuery( '#sunshine--addons li' ).hide();
				jQuery( '#sunshine--addons li.' + category ).show();
			}
		});
		</script>
		<?php } ?>
		<ul id="sunshine--addons" class="<?php echo esc_attr( $current_plan ); ?>">
			<?php foreach ( $addons as $addon ) { ?>
				<li class="<?php echo esc_attr( $addon['plan'] ); ?> <?php echo ( ! empty( $addon['category'] ) ) ? esc_attr( $addon['category'] ) : ''; ?>">
					<?php
					if (
						$current_plan == 'free'
						|| ( $current_plan == 'basic' && ( $addon['plan'] == 'plus' || $addon['plan'] == 'pro' ) )
						|| ( $current_plan == 'plus' && $addon['plan'] == 'pro' )
					) {
						/* translators: %s is the plan name */
						echo '<div class="sunshine--addon--needs-upgrade" data-addon="' . esc_attr( $addon['slug'] ) . '">' . esc_html( sprintf( __( 'Upgrade to %s', 'sunshine-photo-cart' ), $addon['plan'] ) ) . '</div>';
					}
					?>
					<div class="sunshine--addon--content">
						<h2>
							<img src="<?php echo esc_url( $addon['image'] ); ?>" alt="<?php echo esc_attr( $addon['title'] ); ?>" />
							<?php echo esc_html( $addon['title'] ); ?>
						 </h2>
						<p><?php echo esc_html( $addon['excerpt'] ); ?></p>
					</div>
					<div class="sunshine--addon--actions">
						<label class="sunshine-switch">
							  <input type="checkbox" name="addon" value="<?php echo esc_attr( $addon['slug'] ); ?>" class="<?php echo esc_attr( $addon['plan'] ); ?>" <?php checked( 1, is_sunshine_addon_active( $addon['slug'] ) ); ?> />
							  <span class="sunshine-switch-slider"></span>
						</label>
						<a href="<?php echo esc_url( $addon['url'] ); ?>?utm_source=plugin&utm_medium=link&utm_campaign=addons-list-more" target="_blank" class="button">Learn more</a>
					</div>

					<div class="sunshine--addons--upgrade-modal" id="sunshine--addon--upgrade-modal--<?php echo esc_attr( $addon['slug'] ); ?>">
						<div class="sunshine--addons--upgrade-modal--overlay"></div>
						<div class="sunshine--addons--upgrade-modal--main">
							<div class="sunshine--addons--upgrade-modal--header">
								<div class="sunshine--addons--upgrade-modal--header--title">
									Upgrade to get <span><?php echo esc_html( $addon['title'] ); ?></span>
								</div>
							</div>
							<div class="sunshine--addons--upgrade-modal--content">
								<div class="sunshine--addons--upgrade-modal--content--title">
									<strong>GO PRO</strong> for only <span>$279</span>
								</div>
								<div class="sunshine--addons--upgrade-modal--content--description">
									Get access to every single add-on, including <?php echo esc_html( $addon['title'] ); ?>, for huge savings compared to buying individually
								</div>
								<a href="https://www.sunshinephotocart.com/checkout?edd_action=add_to_cart&download_id=44&utm_source=plugin&utm_medium=link&utm_campaign=addons-list-modal" target="_blank" class="button-primary large">Upgrade to PRO</a><br /><br />
								<a href="https://www.sunshinephotocart.com/upgrade/?utm_source=plugin&utm_medium=link&utm_campaign=addons-list-modal" target="_blank">Learn more about Pro</a> |
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine&section=license&license_reminder' ) ); ?>">I already have a license</a>
								<div class="sunshine--addons--upgrade-modal--content--divider">
									OR
								</div>
								<a href="https://www.sunshinephotocart.com/checkout?edd_action=add_to_cart&download_id=<?php echo esc_attr( $addon['id'] ); ?>&utm_source=plugin&utm_medium=link&utm_campaign=addons-list-modal" target="_blank" class="button-alt">Buy <?php echo esc_html( $addon['title'] ); ?> for $<?php echo esc_html( str_replace( '.00', '', $addon['price'] ) ); ?></a>
								<br /><br />
								<a href="#" class="sunshine--addons--upgrade-modal--close">Nevermind, I do not want to upgrade</a>
							</div>
						</div>
					</div>

				</li>
			<?php } ?>
		</ul>
	</div>

	<?php
}

if ( ! class_exists( 'Plugin_Upgrader', false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}

class Sunshine_Installer_Skin extends Plugin_Installer_Skin {
	public function header() {}

	public function footer() {}

	public function before() {}

	public function after() {}

	public function feedback( $string, ...$args ) {}
}

add_action( 'wp_ajax_sunshine_addon_toggle', 'sunshine_addon_toggle' );
function sunshine_addon_toggle() {
	$result = false;
	if ( isset( $_REQUEST['addon_security'] ) && wp_verify_nonce( $_REQUEST['addon_security'], 'sunshine_addon_toggle' ) && current_user_can( 'sunshine_manage_options' ) ) {
		$addon            = sanitize_text_field( $_REQUEST['addon'] );
		$currently_active = is_sunshine_addon_active( $addon );
		$status           = 'inactive';
		$reason           = '';
		if ( $currently_active ) {
			$deactivate = deactivate_plugins( 'sunshine-' . $addon . '/' . $addon . '.php' );
			if ( ! is_wp_error( $deactivate ) ) {
				$status = 'inactive';
			}
		} else {

			// Does the plugin exist?
			$valid_plugin = validate_plugin( 'sunshine-' . $addon . '/' . $addon . '.php' );
			if ( is_wp_error( $valid_plugin ) ) {

				$addons_data = sunshine_get_addon_data( true );
				foreach ( $addons_data as $addon_item ) {
					if ( $addon == $addon_item['slug'] ) {
						break;
					}
				}

				$skin_args = array(
					'type'   => 'web',
					'title'  => $addon_item['title'],
					'plugin' => '',
					'api'    => null,
					'extra'  => null,
				);

				$skin = new Sunshine_Installer_Skin( $skin_args );

				$upgrader = new Plugin_Upgrader( $skin );

				$install = $upgrader->install( $addon_item['file'] );

				if ( $install ) {
					// echo 'Files installed!';
					$status = 'installed';
				} else {
					$status = 'failed';
					$reason = __( 'Could not get download file', 'sunshine-photo-cart' );
				}
			}
			// echo 'Activating addon...';
			$activate = activate_plugins( 'sunshine-' . $addon . '/' . $addon . '.php' );
			if ( ! is_wp_error( $activate ) ) {
				$status = 'active';
			}
		}
		wp_send_json_success(
			array(
				'status' => $status,
				'reason' => $reason,
			)
		);
	}
}

add_action( 'sunshine_addon_check', 'sunshine_get_addon_data', 20 );
function sunshine_get_addon_data( $force = false ) {

	$addons = get_transient( 'sunshine_addons_data' );

	if ( $force || empty( $addons ) ) {
		$url = SUNSHINE_PHOTO_CART_STORE_URL . '/?sunshine_addons_feed&referrer=' . $_SERVER['SERVER_NAME'] . '&time=' . time();
		if ( ! empty( SPC()->plan ) ) {
			$license_key = SPC()->plan->get_license_key();
			if ( ! empty( $license_key ) ) {
				$url = add_query_arg( 'license_key', $license_key, $url );
			}
		}
		$feed = wp_remote_get( esc_url_raw( $url ), array( 'sslverify' => false ) );

		if ( ! is_wp_error( $feed ) ) {
			if ( isset( $feed['body'] ) && strlen( $feed['body'] ) > 0 ) {
				$plugins           = array();
				$remote_data_items = json_decode( wp_remote_retrieve_body( $feed ) );
				if ( ! empty( $remote_data_items ) ) {
					$addons = array();
					foreach ( $remote_data_items as $remote_data_item ) {
						// if ( empty( $addon->file ) ) continue;
						$addons[] = array(
							'id'            => $remote_data_item->id,
							'title'         => $remote_data_item->title,
							'slug'          => $remote_data_item->slug,
							'file'          => $remote_data_item->file,
							'url'           => $remote_data_item->url,
							'plan'          => $remote_data_item->plan,
							'excerpt'       => $remote_data_item->excerpt,
							'price'         => $remote_data_item->price,
							'image'         => $remote_data_item->image,
							'category'      => $remote_data_item->category,
							'category_name' => $remote_data_item->category_name,
						);
					}
				}
				set_transient( 'sunshine_addons_data', $addons, DAY_IN_SECONDS * 3 );
			}
		}
	}

	return $addons;

}
