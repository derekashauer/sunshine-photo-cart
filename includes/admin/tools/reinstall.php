<?php
class SPC_Tool_Reinstall extends SPC_Tool {

	function __construct() {
		parent::__construct(
			__( 'Install process', 'sunshine-photo-cart' ),
			'reinstall',
			__( 'Run the initial install process which sets the default pages, settings and permissions. This will not reset your settings, but add things that may be missing.', 'sunshine-photo-cart' ),
			__( 'Run install process', 'sunshine-photo-cart' )
		);
	}

	protected function do_process() {
		maybe_sunshine_create_custom_tables();
		sunshine_base_install();
		echo '<p>' . esc_html__( 'Re-install process successfully run', 'sunshine-photo-cart' ) . '</p>';
	}

}

$SPC_Tool_Reinstall = new SPC_Tool_Reinstall();
