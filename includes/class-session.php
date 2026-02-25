<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPC_Session {

	private $session_id;
	protected $data = array();
	protected $expiration;

	public function __construct() {

		add_action( 'sunshine_session_garbage_collection', array( $this, 'cleanup' ) );

		if ( ! $this->should_start_session() ) {
			return;
		}

		add_action( 'shutdown', array( $this, 'write_data' ), 0 );

		if ( ! defined( 'SUNSHINE_SESSION_COOKIE' ) ) {
			define( 'SUNSHINE_SESSION_COOKIE', 'sunshine3_session' );
		}

		if ( isset( $_COOKIE[ SUNSHINE_SESSION_COOKIE ] ) ) {
			$cookie        = stripslashes( $_COOKIE[ SUNSHINE_SESSION_COOKIE ] );
			$cookie_crumbs = explode( '||', $cookie );

			if ( $this->is_valid_md5( $cookie_crumbs[0] ) ) {

				$this->session_id = $cookie_crumbs[0];

			} else {

				$this->regenerate_id( true );

			}

			$this->expiration = $cookie_crumbs[1];

			// Update the session expiration if we're past the variant time
		} else {
			$this->session_id = $this->generate_id();
		}

		$this->read_data();
		$this->set_expiration();
		$this->set_cookie();

	}

	public function get_id() {
		return $this->session_id;
	}

	protected function set_expiration() {
		$this->expiration = time() + (int) apply_filters( 'sunshine_session_expiration', DAY_IN_SECONDS * 7 );
	}

	protected function set_cookie() {
		@setcookie( SUNSHINE_SESSION_COOKIE, $this->session_id . '||' . $this->expiration, $this->expiration, COOKIEPATH, COOKIE_DOMAIN );
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

		if ( empty( $this->data ) ) {
			return false;
		}

		$session = array(
			'session_id' => $this->session_id,
			'data'       => ( ! empty( $this->data ) ) ? maybe_serialize( $this->data ) : '',
			'expiration' => $this->expiration,
		);
		$result  = $wpdb->replace( "{$wpdb->prefix}sunshine_sessions", $session );

	}

	public function json_out() {
		return json_encode( $this->data );
	}

	public function json_in( $data ) {
		$array = json_decode( $data );

		if ( is_array( $array ) ) {
			$this->data = $array;
			return true;
		}

		return false;
	}

	public function regenerate_id( $delete_old = false ) {
		global $wpdb;
		if ( $delete_old ) {
			$wpdb->delete( "{$wpdb->prefix}sunshine_sessions", array( 'session_id' => $this->session_id ) );
		}
		$this->session_id = $this->generate_id();
		$this->set_cookie();
	}

	public function reset() {
		$this->data = array();
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

	public function set( $key, $value ) {

		$key = sanitize_key( $key );

		if ( is_array( $value ) ) {
			$this->data[ $key ] = wp_json_encode( $value );
		} else {
			$this->data[ $key ] = sanitize_text_field( $value );
		}

		return $this->data[ $key ];

	}

	public function delete( $key ) {

		$key = sanitize_key( $key );
		if ( array_key_exists( $key, $this->data ) ) {
			unset( $this->data[ $key ] );
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
