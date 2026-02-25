<?php
class SPC_Tool_Sessions extends SPC_Tool {

	function __construct() {
		parent::__construct(
			__( 'Clear Sesssions', 'sunshine-photo-cart' ),
			'clear-sessions',
			__( 'Sunshine stores information about user carts in sessions which are saved to the database. If your database is too big, you can clear all the session data here.', 'sunshine-photo-cart' ),
			__( 'Clear sessions', 'sunshine-photo-cart' )
		);
	}

	function pre_process() {
		global $wpdb;
		$session_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sunshine_sessions" );
		if ( $session_count ) {
			echo '<p>You currently have <strong>' . esc_html( $session_count ) . ' ' . esc_html( _n( 'session', 'sessions', $session_count, 'sunshine-photo-cart' ) ) . '</strong></p>';
		} else {
			echo '<p><em>' . esc_html__( 'No sessions found!', 'sunshine-photo-cart' ) . '</em></p>';
			$this->button_label = '';
		}

	}

	protected function do_process() {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}sunshine_sessions" );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE %s",
				'sunshine_session_%'
			)
		);
		echo '<p style="color: green;">' . esc_html__( 'Sessions cleared', 'sunshine-photo-cart' ) . '</p>';
	}

}

$spc_tool_sessions = new SPC_Tool_Sessions();
