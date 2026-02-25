<?php
function sunshine_get_tools() {
	$tools = apply_filters( 'sunshine_tools', array() );
	return $tools;
}

function sunshine_get_tool( $key ) {
	$tools = sunshine_get_tools();
	if ( empty( $tools ) ) {
		return false;
	}
	foreach ( $tools as $tool_key => $tool ) {
		if ( $key == $tool_key ) {
			return $tool;
		}
	}
}

function sunshine_tools_page() {
	global $wpdb;

	if ( ! current_user_can( 'sunshine_manage_options' ) ) {
		return;
	}

	if ( isset( $_GET['tool'] ) ) {
	}
	?>
	<div class="wrap">

	<?php
	if ( isset( $_GET['tool'] ) ) {
		$tool_key = sanitize_text_field( wp_unslash( $_GET['tool'] ) );
		$tool     = sunshine_get_tool( $tool_key );
		if ( $tool ) {
			echo '<h2><a href="' . esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-tools' ) ) . '">' . esc_html__( 'Tools', 'sunshine-photo-cart' ) . '</a> > ' . esc_html( $tool->get_name() ) . '</h2>';
			$tool->process();
		} else {
			esc_html_e( 'Invalid tool', 'sunshine-photo-cart' );
		}
	} else {
			echo '<h2>' . esc_html__( 'Tools', 'sunshine-photo-cart' ) . '</h2>';
			$tools = sunshine_get_tools();
		if ( ! empty( $tools ) ) {
			foreach ( $tools as $tool ) {
				?>
					<div class="sunshine-tool">
						<h3><?php echo esc_html( $tool->get_name() ); ?></h3>
					<?php if ( $tool->get_description() ) { ?>
							<p><?php echo wp_kses_post( $tool->get_description() ); ?></p>
						<?php } ?>
						<div class="sunshine-tool-preprocess"><?php $tool->pre_process(); ?></div>
					<?php if ( $tool->get_button_label() ) { ?>
							<p><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-tools&tool=' . esc_attr( $tool->get_key() ) ), 'sunshine_tool_' . esc_attr( $tool->get_key() ) ) ); ?>" class="button button-primary"><?php echo esc_html( $tool->get_button_label() ); ?></a></p>
						<?php } ?>
					</div>
					<?php
			}
		}
	}
	?>

	</div>
	<?php
}
