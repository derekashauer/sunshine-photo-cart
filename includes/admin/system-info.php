<?php
function sunshine_site_health_info( $debug_info ) {
	$debug_info['sunshine-photo-cart'] = array(
		'label'  => 'Sunshine Photo Cart',
		'fields' => array(
			'gallery_url' => array(
				'label' => 'Gallery URL',
				'value' => get_permalink( SPC()->get_option( 'page' ) ),
			),
			'admin_url'   => array(
				'label' => 'Admin URL',
				'value' => admin_url(),
			),
		),
	);

	$fields = sunshine_get_settings_fields();
	foreach ( $fields as $section ) :
		foreach ( $section['fields'] as $field ) {
			if ( empty( $field['id'] ) ) {
				$field['id'] = $field['name'];
			}

			if ( $field['type'] == 'header' ) {
				$name  = strtoupper( $field['name'] );
				$value = '================================';
			} else {
				$name  = $field['name'];
				$value = SPC()->get_option( $field['id'] );
				if ( is_array( $value ) ) {
					$values = $value;
					$value  = '';
					foreach ( $values as $k => $v ) {
						$value .= $k . ': ' . maybe_serialize( $v ) . ', ';
					}
				}
			}
			if ( empty( $name ) ) {
				continue;
			}
			if ( ! empty( $field['hide_system_info'] ) || str_contains( $field['id'], 'key' ) ) {
				if ( $value = '' ) {
					$value = 'NULL';
				} else {
					$value = 'Hidden, but present';
				}
			}

			$debug_info['sunshine-photo-cart']['fields'][ $field['id'] ] = array(
				'label' => $name,
				'value' => substr( $value, 0, 500 ),
			);
		}
	endforeach;

	return $debug_info;
}
add_filter( 'debug_information', 'sunshine_site_health_info' );

// Define the custom test function
function sunshine_site_health_test( $tests ) {
	$tests['direct']['sunshine_photo_cart_memory'] = array(
		'label' => __( 'Sunshine Photo Cart Memory Availability', 'sunshine-photo-cart' ),
		'test'  => 'sunshine_memory_test',
	);
	return $tests;
}
add_filter( 'site_status_tests', 'sunshine_site_health_test' );

// Define the function that will run your test
function sunshine_memory_test() {

	// Get WordPress memory limit
	$wp_memory_limit = wp_convert_hr_to_bytes( WP_MEMORY_LIMIT ) / ( 1024 * 1024 );

	// Get PHP memory limit
	$php_memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) / ( 1024 * 1024 );

	$result = '';

	// Compare memory limits
	if ( $wp_memory_limit < $php_memory_limit ) {
		$result = array(
			'label'       => __( 'Sunshine Photo Cart Recommended Memory Limit', 'sunshine-photo-cart' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Performance', 'sunshine-photo-cart' ),
				'color' => 'orange',
			),
			/* translators: %1$s is the WordPress memory limit, %2$s is the PHP memory limit */
			'description' => '<p>' . sprintf( __( 'Your current WordPress memory limit is set to %1$sM, but your web server allows up to %2$sM. It is recommended to increase the WordPress memory limit to match or exceed the PHP memory limit for optimal performance and speed to ensure your uploads and applying watermarks go as fast as possible.', 'sunshine-photo-cart' ), $wp_memory_limit, $php_memory_limit ) . '</p>',
			'actions'     => sprintf(
				'<p><a href="%s" target="_blank">%s</a></p>',
				esc_url( 'https://www.sunshinephotocart.com/docs/increasing-memory-limit-wordpress' ),
				__( 'Learn how to increase the WordPress memory limit', 'sunshine-photo-cart' )
			),
			'test'        => 'sunshine_photo_cart_memory',
		);
	} elseif ( $php_memory_limit < 256 ) {
			$result = array(
				'label'       => __( 'Sunshine Photo Cart Recommended Memory Limit', 'sunshine-photo-cart' ),
				'status'      => 'recommended',
				'badge'       => array(
					'label' => __( 'Performance', 'sunshine-photo-cart' ),
					'color' => 'orange',
				),
				/* translators: %s is the PHP memory limit */
				'description' => '<p>' . sprintf( __( 'Your current PHP memory limit is %sM. If you are seeing issues with slow uploading images or errors, it is recommended to ask your web host if your available memory can be increased. A minimum of 256M is recommended, but as high as possible is best.', 'sunshine-photo-cart' ), $php_memory_limit ) . '</p>',
				'actions'     => sprintf(
					'<p><a href="%s" target="_blank">%s</a></p>',
					esc_url( 'https://www.sunshinephotocart.com/docs/increasing-memory-limit-wordpress' ),
					__( 'Learn how to increase the WordPress memory limit', 'sunshine-photo-cart' )
				),
				'test'        => 'sunshine_photo_cart_memory',
			);
	}
	return $result;

}

function sunshine_system_info_page() {
	global $sunshine;
	?>
<div class="wrap">
		<h2>System Information</h2>
		<p>Use the information below when submitting tickets or questions via <a href="http://www.sunshinephotocart.com/support" target="_blank">Sunshine Support</a>.</p>

<textarea id="sunshine-system-info" readonly="readonly" style="font-family: 'courier new', monospace; margin: 10px 0 0 0; width: 900px; height: 400px;" title="To copy the system info, click below then press Ctrl + C (PC) or Cmd + C (Mac).">

### Begin System Info ###

Home Page:                <?php echo esc_url( site_url() ) . "\n"; ?>
Gallery URL:              <?php echo esc_url( get_permalink( SPC()->get_option( 'page' ) ) ) . "\n"; ?>
Admin:                 	  <?php echo esc_url( admin_url() ) . "\n"; ?>

WordPress Version:        <?php echo esc_html( get_bloginfo( 'version' ) ) . "\n"; ?>

PHP Version:              <?php echo esc_html( PHP_VERSION ) . "\n"; ?>
PHP Memory Limit:         <?php echo esc_html( ini_get( 'memory_limit' ) ) . "\n"; ?>
WordPress Memory Limit:   <?php echo esc_html( ( sunshine_let_to_num( WP_MEMORY_LIMIT ) / ( 1024 * 1024 ) ) . 'M' ); ?><?php echo "\n"; ?>
ImageMagick:
	<?php
	echo ( extension_loaded( 'imagick' ) ) ? 'Yes' : 'No';
	echo "\n";
	?>
Image Quality:            <?php echo esc_html( apply_filters( 'jpeg_quality', 60 ) ); ?>
	<?php do_action( 'sunshine_sunshine_info' ); ?>


ACTIVE PLUGINS:

	<?php
	$plugins        = get_plugins();
	$active_plugins = get_option( 'active_plugins', array() );

	foreach ( $plugins as $plugin_path => $plugin ) :

		// If the plugin isn't active, don't show it.
		if ( ! in_array( $plugin_path, $active_plugins ) ) {
			continue;
		}
		?>
		<?php echo esc_html( $plugin['Name'] ); ?>: <?php echo esc_html( $plugin['Version'] ); ?>

<?php endforeach; ?>

CURRENT THEME:

	<?php
	if ( get_bloginfo( 'version' ) < '3.4' ) {
		$theme_data = get_theme_data( get_stylesheet_directory() . '/style.css' );
		echo esc_html( $theme_data['Name'] ) . ': ' . esc_html( $theme_data['Version'] );
	} else {
		$theme_data = wp_get_theme();
		echo esc_html( $theme_data->Name ) . ': ' . esc_html( $theme_data->Version );
	}
	?>


SUNSHINE SETTINGS:

	<?php
	$fields = sunshine_get_settings_fields();
	foreach ( $fields as $section ) :
		foreach ( $section['fields'] as $field ) {
			if ( $field['type'] == 'header' || strpos( 'token', $field['id'] ) !== false || strpos( 'key', $field['id'] ) !== false ) {
				continue; // Exclude some more sensitive items
			}
			$value = SPC()->get_option( $field['id'] );
			if ( is_array( $value ) ) {
				$values = $value;
				$value  = '';
				foreach ( $values as $k => $v ) {
					$value .= $k . ': ' . maybe_serialize( $v ) . '|';
				}
			}
			echo esc_html( $field['name'] ) . ': ' . esc_html( $value ) . "\r\n";
		}
endforeach;
	?>

IMAGE SIZES:

	<?php
	global $_wp_additional_image_sizes;
	foreach ( $_wp_additional_image_sizes as $name => $image_size ) {
		$crop = ( $image_size['crop'] ) ? 'cropped' : 'not cropped';
		?>
		<?php echo esc_html( $name ) . ': ' . esc_html( $image_size['width'] ) . 'x' . esc_html( $image_size['height'] ) . ' (' . esc_html( $crop ) . ')'; ?>

<?php } ?>

### End System Info ###
</textarea>

	</div>
	<p><button class="button button-primary" onclick="sunshine_copy_system_info()"><?php esc_html_e( 'Copy system info to clipboard', 'sunshine-photo-cart' ); ?></button></p>
	<script>
	function sunshine_copy_system_info() {
		var copyText = document.getElementById( "sunshine-system-info" );
		copyText.select();
		document.execCommand( "copy" );
		jQuery( '.button-primary' ).after( '<span class="copied" style="display: inline-block; margin-left: 20px; font-size: 16px; color: green; font-weight: bold;">Copied!</div>' );
		jQuery( '.copied' ).delay( 3000 ).fadeOut();
	}
	</script>
	<?php

}
