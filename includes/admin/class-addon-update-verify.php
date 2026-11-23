<?php
/**
 * Refuses an add-on update unless it was signed by WP Sunshine.
 *
 * Add-on updates come from the EDD store on sunshinephotocart.com. If that site were ever
 * hacked, whoever took it could hand every customer site a zip of their own as "the new
 * version" of an add-on, and sites with auto-updates on would install it with no click.
 * This class stops that.
 *
 * Each add-on release zip carries <folder>/signature.json, written by sign-plugin-zip on
 * the release machine. It holds a manifest (add-on folder, version, and a sha256 for every
 * file in the zip) and an Ed25519 signature over the manifest's exact text. The private
 * keys never touch our servers or GitHub, so a hacked server can serve files but can't
 * make a signature that passes here.
 *
 * Every add-on that registers an SPC_License with its plugin file is added here by
 * SPC_License. Add-ons only do that in wp-admin and cron runs, so any other add-on being
 * updated, such as under WP-CLI, is recognised by its plugin name. The check runs on
 * `upgrader_source_selection`: after WordPress unzips the package but before it removes
 * the installed copy. Returning a WP_Error there aborts the update and leaves the old
 * version in place. An admin uploading a zip by hand under Plugins > Add New is not
 * checked, because that admin already has full control of the site.
 *
 * It also refuses a signed version that isn't newer than the one installed, so an old
 * signed release (one with a known bug) can't be served again as "new".
 *
 * Two public keys ship: the one releases are signed with, and a backup kept offline in
 * case the first private key is ever lost. Either one passes.
 *
 * Loaded on every request (not only in wp-admin) because updates also run from WP-Cron
 * (auto-updates) and WP-CLI.
 */

defined( 'ABSPATH' ) || exit;

class SPC_Addon_Update_Verify {

	const SIGNATURE_FILE = 'signature.json';

	/**
	 * Base64 Ed25519 public keys. The private halves live only on the release machine.
	 */
	const PUBLIC_KEYS = array(
		'JO0x170Ty1gdNnBkZRSD55jv+7xecWqcbhhfYk4AEt8=', // Main release key.
		'wCSnDcnh8LBpCC5alhtOTVhanjp0G+r6afAo0PkcV3E=', // Offline backup key.
	);

	/**
	 * Add-ons to check, keyed by plugin basename (sunshine-stripe/stripe.php).
	 *
	 * @var array
	 */
	private static $addons = array();

	/**
	 * Adds an add-on to the check.
	 *
	 * @param string $file    Full path to the add-on's main file.
	 * @param string $version Installed version.
	 * @param string $name    Add-on name for the error message.
	 */
	public static function watch( $file, $version, $name ) {

		self::$addons[ plugin_basename( $file ) ] = array(
			'folder'  => basename( dirname( $file ) ),
			'version' => (string) $version,
			'name'    => $name,
		);
	}

	/**
	 * @param string|WP_Error $source        Folder the package was unzipped to.
	 * @param string          $remote_source Parent folder of $source.
	 * @param WP_Upgrader     $upgrader      Upgrader running the update.
	 * @param array           $hook_extra    Extra details; 'plugin' is set for plugin updates.
	 * @return string|WP_Error The same $source, or an error that stops the update.
	 */
	public static function check_package( $source, $remote_source = '', $upgrader = null, $hook_extra = array() ) {

		if ( is_wp_error( $source ) || empty( $hook_extra['plugin'] ) ) {
			return $source;
		}

		$addon = self::find_addon( $hook_extra['plugin'] );
		if ( ! $addon ) {
			return $source;
		}

		$problem = self::find_problem( $source, $addon['folder'], $addon['version'] );
		if ( '' === $problem ) {
			return $source;
		}

		SPC()->log( $addon['name'] . ' update refused: ' . $problem );

		return new WP_Error(
			'sunshine_unsigned_update',
			sprintf(
				/* translators: 1: add-on name, 2: why the update was refused */
				__( '%1$s refused this update because it could not confirm it came from Sunshine Photo Cart: %2$s Your current version was left in place.', 'sunshine-photo-cart' ),
				$addon['name'],
				// WordPress prints this message as HTML, and $problem can hold a file name from the zip.
				esc_html( $problem )
			)
		);
	}

	/**
	 * Details of the add-on being updated.
	 *
	 * @param string $plugin Plugin basename.
	 * @return array|false Folder, version and name, or false when it isn't an add-on.
	 */
	private static function find_addon( $plugin ) {

		if ( isset( self::$addons[ $plugin ] ) ) {
			return self::$addons[ $plugin ];
		}

		// Core has the add-ons' name pattern too, but it comes from WordPress.org, unsigned.
		$file = WP_PLUGIN_DIR . '/' . $plugin;
		if ( plugin_basename( SUNSHINE_PHOTO_CART_FILE ) === $plugin || ! is_readable( $file ) ) {
			return false;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( $file, false, false );
		if ( 0 !== strpos( $data['Name'], 'Sunshine Photo Cart - ' ) || ! in_array( $data['Author'], array( 'WP Sunshine', 'Sunshine Photo Cart' ), true ) ) {
			return false;
		}

		return array(
			'folder'  => dirname( $plugin ),
			'version' => (string) $data['Version'],
			'name'    => $data['Name'],
		);
	}

	/**
	 * Checks an unzipped package folder.
	 *
	 * @param string $source  Folder the package was unzipped to.
	 * @param string $folder  The add-on's folder name, which is the slug signed into the manifest.
	 * @param string $version Installed version.
	 * @return string Empty when the package is fine, otherwise what is wrong.
	 */
	public static function find_problem( $source, $folder, $version ) {
		global $wp_filesystem;

		// WordPress unzips updates through $wp_filesystem. On a host that updates over FTP or
		// SSH, $source is a path on that connection, which PHP can't open itself, so every
		// read here goes through $wp_filesystem too. Without it nothing can be checked.
		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			return __( 'the update files could not be read.', 'sunshine-photo-cart' );
		}

		$source = trailingslashit( $source );

		if ( ! $wp_filesystem->is_file( $source . self::SIGNATURE_FILE ) ) {
			return __( 'the update has no signature.', 'sunshine-photo-cart' );
		}

		$signed = json_decode( (string) $wp_filesystem->get_contents( $source . self::SIGNATURE_FILE ), true );
		if ( ! is_array( $signed ) || empty( $signed['manifest'] ) || empty( $signed['signature'] ) || ! is_string( $signed['manifest'] ) || ! is_string( $signed['signature'] ) ) {
			return __( 'the signature file is damaged.', 'sunshine-photo-cart' );
		}

		if ( ! self::signature_matches( $signed['manifest'], $signed['signature'] ) ) {
			return __( 'the signature does not match.', 'sunshine-photo-cart' );
		}

		$manifest = json_decode( $signed['manifest'], true );
		if ( ! is_array( $manifest ) || empty( $manifest['version'] ) || empty( $manifest['files'] ) || ! is_array( $manifest['files'] ) ) {
			return __( 'the signed file list is damaged.', 'sunshine-photo-cart' );
		}

		if ( ( $manifest['plugin'] ?? '' ) !== $folder ) {
			return __( 'the signature is for a different plugin.', 'sunshine-photo-cart' );
		}

		if ( ! version_compare( (string) $manifest['version'], $version, '>' ) ) {
			return sprintf(
				/* translators: 1: signed version, 2: installed version */
				__( 'the signed version (%1$s) is not newer than the installed version (%2$s).', 'sunshine-photo-cart' ),
				$manifest['version'],
				$version
			);
		}

		$found = self::list_files( $wp_filesystem, $source );
		if ( false === $found ) {
			return __( 'the update files could not be read.', 'sunshine-photo-cart' );
		}
		unset( $found[ self::SIGNATURE_FILE ] );

		foreach ( array_keys( $found ) as $path ) {
			if ( ! isset( $manifest['files'][ $path ] ) ) {
				/* translators: %s: file path */
				return sprintf( __( 'the update has a file that was not signed (%s).', 'sunshine-photo-cart' ), $path );
			}
			$contents = $wp_filesystem->get_contents( $source . $path );
			if ( false === $contents ) {
				/* translators: %s: file path */
				return sprintf( __( 'a file could not be read (%s).', 'sunshine-photo-cart' ), $path );
			}
			if ( ! hash_equals( (string) $manifest['files'][ $path ], hash( 'sha256', $contents ) ) ) {
				/* translators: %s: file path */
				return sprintf( __( 'a file was changed after it was signed (%s).', 'sunshine-photo-cart' ), $path );
			}
		}

		foreach ( array_keys( $manifest['files'] ) as $path ) {
			if ( ! isset( $found[ $path ] ) ) {
				/* translators: %s: file path */
				return sprintf( __( 'a signed file is missing (%s).', 'sunshine-photo-cart' ), $path );
			}
		}

		return '';
	}

	/**
	 * @param string $manifest  The manifest text exactly as it was signed.
	 * @param string $signature Base64 signature.
	 * @return bool True when either public key verifies the signature.
	 */
	private static function signature_matches( $manifest, $signature ) {

		$signature = base64_decode( $signature, true );
		if ( false === $signature || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			return false;
		}

		foreach ( self::PUBLIC_KEYS as $public_key ) {
			try {
				if ( sodium_crypto_sign_verify_detached( $signature, $manifest, base64_decode( $public_key ) ) ) {
					return true;
				}
			} catch ( Throwable $e ) {
				continue;
			}
		}

		return false;
	}

	/**
	 * Every file under $dir, hidden ones too, keyed by its path inside the package with
	 * forward slashes. Anything that isn't a folder counts as a file, so it gets checked.
	 *
	 * @param WP_Filesystem_Base $filesystem The filesystem WordPress unzipped the update with.
	 * @param string             $dir        Folder with a trailing slash.
	 * @param string             $prefix     Path of $dir inside the package.
	 * @return array|false False when any folder can't be listed, so no file goes unchecked.
	 */
	private static function list_files( $filesystem, $dir, $prefix = '' ) {

		$entries = $filesystem->dirlist( $dir, true, false );
		if ( ! is_array( $entries ) ) {
			return false;
		}

		$files = array();
		foreach ( $entries as $entry ) {
			$name = (string) $entry['name'];

			if ( 'd' === $entry['type'] ) {
				$inside = self::list_files( $filesystem, $dir . $name . '/', $prefix . $name . '/' );
				if ( false === $inside ) {
					return false;
				}
				$files += $inside;
			} else {
				$files[ $prefix . $name ] = true;
			}
		}

		return $files;
	}
}

add_filter( 'upgrader_source_selection', array( 'SPC_Addon_Update_Verify', 'check_package' ), 10, 4 );
