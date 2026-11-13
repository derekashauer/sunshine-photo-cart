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

/**
 * Groups for the Tools screen, in the order they are listed.
 *
 * Tools pick one by setting their $category property to a key from this list.
 * Add-ons add their own groups with the sunshine_tool_categories filter.
 *
 * @return array Group key => label.
 */
function sunshine_get_tool_categories() {
	$categories = array(
		'images'  => __( 'Images', 'sunshine-photo-cart' ),
		'cleanup' => __( 'Clean Up', 'sunshine-photo-cart' ),
		'orders'  => __( 'Orders', 'sunshine-photo-cart' ),
		'setup'   => __( 'Setup', 'sunshine-photo-cart' ),
	);
	return apply_filters( 'sunshine_tool_categories', $categories );
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
			echo '<h1><a href="' . esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-tools' ) ) . '">' . esc_html__( 'Tools', 'sunshine-photo-cart' ) . '</a> > ' . esc_html( $tool->get_name() ) . '</h1>';
			$tool->process();
		} else {
			esc_html_e( 'Invalid tool', 'sunshine-photo-cart' );
		}
	} else {
		echo '<h1>' . esc_html__( 'Tools', 'sunshine-photo-cart' ) . '</h1>';

		// List tools grouped by category, in category order; tools without a known category go last.
		$categories = sunshine_get_tool_categories();
		$grouped    = array_fill_keys( array_keys( $categories ), array() );
		$other      = array();
		foreach ( sunshine_get_tools() as $tool ) {
			if ( isset( $grouped[ $tool->get_category() ] ) ) {
				$grouped[ $tool->get_category() ][] = $tool;
			} else {
				$other[] = $tool;
			}
		}
		$grouped = array_filter( $grouped );
		$tools   = array_merge( array_merge( array(), ...array_values( $grouped ) ), $other );

		if ( ! empty( $tools ) ) {
			if ( ! empty( $grouped ) ) {
				?>
			<p class="sunshine--filters" data-boxes="#sunshine--tools">
				<a href="#all" data-category="all" class="active"><?php esc_html_e( 'All', 'sunshine-photo-cart' ); ?></a>
				<?php foreach ( array_keys( $grouped ) as $key ) { ?>
					<a href="#<?php echo esc_attr( $key ); ?>" data-category="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $categories[ $key ] ); ?></a>
				<?php } ?>
			</p>
				<?php
			}
			?>
			<ul id="sunshine--tools" class="sunshine--boxes">
				<?php
				foreach ( $tools as $tool ) {
					ob_start();
					$tool->pre_process(); // Runs before the button check, since a tool clears its button when there is nothing to do.
					$pre_process = ob_get_clean();
					?>
					<li class="sunshine--box" data-category="<?php echo esc_attr( $tool->get_category() ); ?>">
						<div class="sunshine--box--content">
							<h2><?php echo esc_html( $tool->get_name() ); ?></h2>
							<?php if ( $tool->get_description() ) { ?>
								<p><?php echo wp_kses_post( $tool->get_description() ); ?></p>
							<?php } ?>
							<?php echo $pre_process; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each tool escapes its own output. ?>
						</div>
						<?php if ( $tool->get_button_label() ) { ?>
							<div class="sunshine--box--actions">
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-tools&tool=' . esc_attr( $tool->get_key() ) ), 'sunshine_tool_' . esc_attr( $tool->get_key() ) ) ); ?>" class="button button-primary"><?php echo esc_html( $tool->get_button_label() ); ?></a>
							</div>
						<?php } ?>
					</li>
					<?php
				}
				?>
			</ul>
			<?php
		}
	}
	?>

	</div>
	<?php
}
