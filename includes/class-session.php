<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPC_Session {

	private $session_id;
	protected $data = array();
	protected $expiration;
	protected $enabled = false;
	protected $started = false;
	protected $dirty   = false;

	public function __construct() {

		add_action( 'sunshine_session_garbage_collection', array( $this, 'cleanup' ) );

		if ( ! defined( 'SUNSHINE_SESSION_COOKIE' ) ) {
			define( 'SUNSHINE_SESSION_COOKIE', 'sunshine3_session' );
		}

		if ( ! $this->should_start_session() ) {
			return;
		}

		$this->enabled = true;

		add_action( 'shutdown', array( $this, 'write_data' ), 0 );
		add_action( 'template_redirect', array( $this, 'maybe_destroy_empty_session' ), 999 );

		if ( isset( $_COOKIE[ SUNSHINE_SESSION_COOKIE ] ) ) {

			$cookie        = stripslashes( $_COOKIE[ SUNSHINE_SESSION_COOKIE ] );
			$cookie_crumbs = explode( '||', $cookie );

			if ( $this->is_valid_md5( $cookie_crumbs[0] ) ) {

				$this->session_id = $cookie_crumbs[0];
				$this->expiration = ( isset( $cookie_crumbs[1] ) ) ? (int) $cookie_crumbs[1] : 0;
				$this->started    = true;

				$this->read_data();
				$this->maybe_refresh_cookie();

			} else {

				// Cookie is malformed or was tampered with, start over with a fresh ID
				$this->session_id = $this->generate_id();
				$this->started    = true;
				$this->set_expiration();
				$this->set_cookie();

			}

			return;

		}

		// No cookie yet. Wait until something actually needs to be remembered before
		// creating one, so pages with no Sunshine activity stay cacheable.
		if ( ! apply_filters( 'sunshine_session_lazy_cookie', true ) ) {
			$this->start();
		}

	}

	/**
	 * Create the session and send the cookie. Called the first time something is stored.
	 *
	 * @return bool True if a session exists after this call.
	 */
	protected function start() {

		if ( $this->started ) {
			return true;
		}

		if ( ! $this->enabled ) {
			return false;
		}

		// Too late to send a cookie, so keep this request's data in memory only
		if ( headers_sent() ) {
			return false;
		}

		$this->session_id = $this->generate_id();
		$this->started    = true;
		$this->set_expiration();
		$this->set_cookie();

		return true;

	}

	public function get_id() {
		// Asking for the ID means the caller intends to use it as an identity key,
		// so make sure a real session exists behind it
		if ( ! $this->started ) {
			$this->start();
		}
		// Some add-ons store data against the raw ID instead of in this session.
		if ( $this->started && ! isset( $this->data['_identity'] ) ) {
			$this->data['_identity'] = '1';
			$this->dirty             = true;
		}
		return $this->session_id;
	}

	protected function get_lifetime() {
		return (int) apply_filters( 'sunshine_session_expiration', DAY_IN_SECONDS * 7 );
	}

	protected function set_expiration() {
		$this->expiration = time() + $this->get_lifetime();
	}

	/**
	 * Send a fresh cookie only once the existing one is past the halfway point of its
	 * life. Re-sending it on every request adds a Set-Cookie header to every page, which
	 * stops hosts and caching plugins from serving those pages from cache.
	 */
	protected function maybe_refresh_cookie() {

		if ( headers_sent() ) {
			return;
		}

		if ( $this->expiration > time() + ( $this->get_lifetime() / 2 ) ) {
			return;
		}

		$this->set_expiration();
		$this->set_cookie();

		// The stored row needs the new expiration too
		$this->dirty = true;

	}

	protected function set_cookie() {

		$value = $this->session_id . '||' . $this->expiration;

		// Nothing changed, so do not add a Set-Cookie header the browser does not need
		if ( isset( $_COOKIE[ SUNSHINE_SESSION_COOKIE ] ) && $_COOKIE[ SUNSHINE_SESSION_COOKIE ] === $value ) {
			return;
		}

		@setcookie(
			SUNSHINE_SESSION_COOKIE,
			$value,
			array(
				'expires'  => $this->expiration,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				// Only flag it secure when the site itself is https, not just this one
				// request. Behind a proxy that reports SSL inconsistently, a secure cookie
				// would not be sent back on a plain http request and the cart would vanish.
				'secure'   => is_ssl() && 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
				'httponly' => true,
			)
		);

	}

	/**
	 * Does the session hold anything worth keeping a cookie for?
	 *
	 * Values are cleared by storing an empty string rather than removing the key, and
	 * empty arrays are stored as "[]", so the keys alone do not tell us anything.
	 */
	protected function has_data() {

		foreach ( $this->data as $value ) {
			if ( ! empty( $value ) && '[]' !== $value && '{}' !== $value ) {
				return true;
			}
		}

		return false;

	}

	/**
	 * Drop the session and expire the cookie.
	 */
	protected function destroy() {

		global $wpdb;

		if ( ! empty( $this->session_id ) ) {
			$wpdb->delete( "{$wpdb->prefix}sunshine_sessions", array( 'session_id' => $this->session_id ) );
		}

		@setcookie( SUNSHINE_SESSION_COOKIE, '', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
		unset( $_COOKIE[ SUNSHINE_SESSION_COOKIE ] );

		$this->data       = array();
		$this->session_id = null;
		$this->started    = false;
		$this->dirty      = false;

	}

	/**
	 * Expire the cookie when the session turns out to be holding nothing.
	 *
	 * Without this, a visitor who empties their cart, or who is still carrying a cookie
	 * from before, keeps sending it on every request and keeps their pages out of the
	 * host's cache until it expires on its own.
	 */
	public function maybe_destroy_empty_session() {

		if ( ! $this->started || headers_sent() || is_user_logged_in() ) {
			return;
		}

		// The cookie was created during this request, so data is still on its way
		if ( ! isset( $_COOKIE[ SUNSHINE_SESSION_COOKIE ] ) ) {
			return;
		}

		if ( ! apply_filters( 'sunshine_session_destroy_when_empty', true ) ) {
			return;
		}

		if ( $this->has_data() ) {
			return;
		}

		// Cart items may not have been stored in the session yet
		if ( ! empty( SPC()->cart ) && ! SPC()->cart->is_empty() ) {
			return;
		}

		$this->destroy();

	}

	protected function generate_id() {
		require_once ABSPATH . 'wp-includes/class-phpass.php';
		$hasher = new PasswordHash( 8, false );
		return md5( $hasher->get_random_bytes( 32 ) );
	}

	protected function is_valid_md5( $md5 = '' ) {
		return preg_match( '/^[a-f0-9]{32}$/', $md5 );
	}

	protected function read_data() {
		global $wpdb;

		if ( null === $wpdb ) {
			return false;
		}

		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}sunshine_sessions WHERE session_id = %s",
				$this->session_id
			),
			ARRAY_A
		);

		if ( null === $session || $session['data'] == '' ) {
			return false;
		}

		$this->data = maybe_unserialize( $session['data'] );
		return $this->data;
	}

	public function write_data() {
		global $wpdb;

		// No cookie was ever sent, so there is nothing to read this back with later
		if ( ! $this->started || empty( $this->session_id ) ) {
			return false;
		}

		if ( empty( $this->data ) ) {
			return false;
		}

		// Nothing changed since it was read, so leave the existing row alone
		if ( ! $this->dirty ) {
			return false;
		}

		$session = array(
			'session_id' => $this->session_id,
			'data'       => ( ! empty( $this->data ) ) ? maybe_serialize( $this->data ) : '',
			'expiration' => $this->expiration,
		);
		$result  = $wpdb->replace( "{$wpdb->prefix}sunshine_sessions", $session );
		if ( false === $result ) {
			SPC()->log( 'Session write failed for session ' . $this->session_id . ': ' . $wpdb->last_error );
		}

	}

	public function json_out() {
		return json_encode( $this->data );
	}

	public function json_in( $data ) {
		$array = json_decode( $data );

		if ( is_array( $array ) ) {
			$this->data  = $array;
			$this->dirty = true;
			return true;
		}

		return false;
	}

	public function regenerate_id( $delete_old = false ) {
		global $wpdb;
		if ( $delete_old && ! empty( $this->session_id ) ) {
			$wpdb->delete( "{$wpdb->prefix}sunshine_sessions", array( 'session_id' => $this->session_id ) );
		}
		$this->session_id = $this->generate_id();
		$this->started    = true;
		$this->dirty      = true;
		$this->set_expiration();
		$this->set_cookie();
	}

	public function reset() {
		$this->data  = array();
		$this->dirty = true;
	}

	public function get( $key ) {

		$key    = sanitize_key( $key );
		$return = false;

		if ( isset( $this->data[ $key ] ) && ! empty( $this->data[ $key ] ) ) {

			if ( is_numeric( $this->data[ $key ] ) ) {
				$return = $this->data[ $key ];
			} else {

				$maybe_json = json_decode( $this->data[ $key ] );

				// Since json_last_error is PHP 5.3+, we have to rely on a `null` value for failing to parse JSON.
				if ( is_null( $maybe_json ) ) {
					$is_serialized = is_serialized( $this->data[ $key ] );
					if ( $is_serialized ) {
						$value = @unserialize( $this->data[ $key ] );
						$this->set( $key, (array) $value );
						$return = $value;
					} else {
						$return = $this->data[ $key ];
					}
				} else {
					$return = json_decode( $this->data[ $key ], true );
				}
			}
		}

		return $return;
	}

	/**
	 * Is this value worth creating a session for?
	 *
	 * Plenty of calls store an empty value on purpose to clear something out, and those
	 * should never be the reason a visitor gets a cookie. Checked against the raw value
	 * because an empty array encodes to the string "[]", which is not empty.
	 */
	protected function is_meaningful( $value ) {

		if ( is_array( $value ) || is_object( $value ) ) {
			return ! empty( (array) $value );
		}

		return ( '' !== $value && null !== $value && false !== $value );

	}

	public function set( $key, $value ) {

		$key = sanitize_key( $key );

		if ( $this->is_meaningful( $value ) ) {
			$this->start();
		}

		if ( is_array( $value ) ) {
			$stored_value = wp_json_encode( $value );
		} else {
			$stored_value = sanitize_text_field( $value );
		}

		if ( array_key_exists( $key, $this->data ) && $this->data[ $key ] === $stored_value ) {
			return $stored_value;
		}

		$this->data[ $key ] = $stored_value;
		$this->dirty = true;

		return $this->data[ $key ];

	}

	public function delete( $key ) {

		$key = sanitize_key( $key );
		if ( array_key_exists( $key, $this->data ) ) {
			unset( $this->data[ $key ] );
			$this->dirty = true;
		}

	}

	public function should_start_session() {

		$start_session = true;

		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {

			$blacklist = $this->get_blacklist();
			$uri       = ltrim( $_SERVER['REQUEST_URI'], '/' );
			$uri       = untrailingslashit( $uri );

			if ( in_array( $uri, $blacklist ) ) {
				$start_session = false;
			}

			if ( false !== strpos( $uri, 'feed=' ) ) {
				$start_session = false;
			}

			/*
			if( is_admin() && false === strpos( $uri, 'wp-admin/admin-ajax.php' ) ) {
				// We do not want to start sessions in the admin unless we're processing an ajax request
				$start_session = false;
			}
			*/

			if ( false !== strpos( $uri, 'wp_scrape_key' ) ) {
				// Starting sessions while saving the file editor can break the save process, so don't start
				$start_session = false;
			}
		}

		return apply_filters( 'sunshine_start_session', $start_session );

	}

	public function get_blacklist() {

		$blacklist = apply_filters(
			'sunshine_session_start_uri_blacklist',
			array(
				'feed',
				'feed/rss',
				'feed/rss2',
				'feed/rdf',
				'feed/atom',
				'comments/feed',
			)
		);

		// Look to see if WordPress is in a sub folder or this is a network site that uses sub folders
		$folder = str_replace( network_home_url(), '', get_site_url() );

		if ( ! empty( $folder ) ) {
			foreach ( $blacklist as $path ) {
				$blacklist[] = $folder . '/' . $path;
			}
		}

		return $blacklist;
	}

	public function maybe_start_session() {

		if ( ! $this->should_start_session() ) {
			return;
		}

		if ( ! session_id() && ! headers_sent() ) {
			session_start();
		}
	}

	public function cleanup() {
		global $wpdb;

		if ( defined( 'WP_INSTALLING' ) ) {
			return;
		}

		SPC()->log( 'Cleaning up sessions' );

		$now    = current_time( 'timestamp' );
		$result = $wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_sessions WHERE expiration <= '{$now}'" );
		SPC()->log( 'Deleted ' . $result . ' sessions' );

		// Allow other plugins to hook in to the garbage collection process.
		do_action( 'sunshine_session_cleanup' );

	}

	public function get_by_id( $id ) {
		global $wpdb;
		$result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sunshine_sessions WHERE session_id = %s", $id ) );
		if ( $result ) {
			return maybe_unserialize( $result->data );
		}
		return false;
	}

}
