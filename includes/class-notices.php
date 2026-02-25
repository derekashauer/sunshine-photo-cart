<?php
// TODO: Admin notices: Ability to close out a notice and not show any more
class SPC_Notices {

	private $notices       = array();
	private $admin_notices = array();

	public function __construct() {
		add_action( 'init', array( $this, 'set' ) );
		add_action( 'admin_init', array( $this, 'set_admin' ), 9 );
		add_action( 'admin_notices', array( $this, 'show_admin' ) );
		add_action( 'wp_ajax_sunshine_notice_dismiss', array( $this, 'dismiss' ) );
	}

	public function set() {
		$notices = SPC()->session->get( 'notices' );
		if ( ! empty( $notices ) && is_array( $notices ) ) {
			$this->notices = $notices;
		}
	}

	public function add( $text, $type = 'success', $permanent = false ) {
		$this->notices[] = array(
			'text'      => $text,
			'type'      => $type,
			'permanent' => $permanent,
		);
		SPC()->session->set( 'notices', $this->notices );
	}

	public function get_notices_html() {
		if ( ! empty( $this->notices ) ) {
			$html = '<div id="sunshine-notices">';
			foreach ( $this->notices as $id => $notice ) {
				$html .= '<div class="sunshine-notice ' . $notice['type'] . '" id="sunshine-notice-' . esc_attr( $id ) . '">' . $notice['text'] . '</div>';
			}
			$html .= '</div>';
			return $html;
		}
		return false;
	}

	public function delete( $key ) {
		if ( ! empty( $this->notices ) && array_key_exists( $key, $this->notices ) ) {
			unset( $this->notices[ $key ] );
			SPC()->session->set( 'notices', $this->notices );
		}
	}

	public function show() {
		if ( ! empty( $this->notices ) ) {
			$notices = $this->get_notices_html();
			echo wp_kses_post( $notices );
			$this->clear();
		}
	}

	public function clear() {
		foreach ( $this->notices as $key => $notice ) {
			if ( ! $notice['permanent'] ) {
				$this->delete( $key );
			}
		}
	}

	public function set_admin() {
		$admin_notices = get_user_meta( get_current_user_id(), 'sunshine_admin_notices', true );
		if ( ! empty( $admin_notices ) && is_array( $admin_notices ) ) {
			$this->admin_notices = $admin_notices;
		}
	}

	function add_admin( $key, $text, $type = 'success', $permanent = false ) {
		if ( empty( $this->admin_notices ) || ! array_key_exists( $key, $this->admin_notices ) ) {
			$this->admin_notices[ $key ] = array(
				'text'      => $text,
				'type'      => $type,
				'permanent' => $permanent,
				'dismissed' => false,
			);
			update_user_meta( get_current_user_id(), 'sunshine_admin_notices', $this->admin_notices );
		}
	}

	public function get_admin_notices() {
		return $this->admin_notices;
	}

	public function get_admin_notices_html() {
		if ( ! empty( $this->admin_notices ) ) {
			$html = '<div id="sunshine-notices">';
			foreach ( $this->admin_notices as $key => $notice ) {
				// Skip in-app promo notices - they are rendered separately.
				if ( strpos( $key, 'in_app_promo_' ) === 0 ) {
					continue;
				}
				if ( ! $notice['dismissed'] ) {
					$html .= '<div class="notice is-dismissible sunshine-notice notice-' . esc_attr( $notice['type'] ) . '" id="sunshine-notice--' . esc_attr( $key ) . '" data-notice="' . esc_attr( $key ) . '"><p>' . $notice['text'] . '</p></div>';
				}
			}
			$html .= '</div>';
			return $html;
		}
		return false;
	}

	public function delete_admin( $key ) {
		if ( ! empty( $this->admin_notices ) && array_key_exists( $key, $this->admin_notices ) ) {
			unset( $this->admin_notices[ $key ] );
			update_user_meta( get_current_user_id(), 'sunshine_admin_notices', $this->admin_notices );
		}
	}

	public function show_admin() {
		if ( ! empty( $this->admin_notices ) ) {
			$notices = $this->get_admin_notices_html();
			echo wp_kses_post( $notices );
			$this->clear_admin();
		}
	}

	public function clear_admin() {
		foreach ( $this->admin_notices as $key => $notice ) {
			if ( ! $notice['permanent'] ) {
				$this->delete_admin( $key );
			}
		}
	}

	public function dismiss() {

		if ( empty( $_POST ) ) {
			wp_send_json_error();
		}

		$dismiss_notice_key = sanitize_text_field( $_POST['notice'] );

		// Read fresh from user meta to ensure we have the latest notices.
		$notices = get_user_meta( get_current_user_id(), 'sunshine_admin_notices', true );
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}

		// If notice exists, mark as dismissed.
		if ( isset( $notices[ $dismiss_notice_key ] ) ) {
			$notices[ $dismiss_notice_key ]['dismissed'] = true;
		} else {
			// Notice doesn't exist yet, create it as dismissed.
			$notices[ $dismiss_notice_key ] = array(
				'text'      => '',
				'type'      => 'notice',
				'permanent' => true,
				'dismissed' => true,
			);
		}

		update_user_meta( get_current_user_id(), 'sunshine_admin_notices', $notices );

		// Also update the class property.
		$this->admin_notices = $notices;

		wp_send_json_success();

	}

}
