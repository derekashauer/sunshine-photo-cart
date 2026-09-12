<?php
function sunshine_site_health_info( $debug_info ) {
	$debug_info['sunshine-photo-cart'] = array(
		'label'  => 'Sunshine Photo Cart',
		'fields' => array(
			'gallery_url'          => array(
				'label' => 'Gallery URL',
				'value' => get_permalink( SPC()->get_option( 'page' ) ),
			),
			'admin_url'            => array(
				'label' => 'Admin URL',
				'value' => admin_url(),
			),
			'image_queue_count'    => array(
				'label' => 'Images awaiting processing',
				'value' => sunshine_get_image_queue_count(),
			),
			'image_queue_running'  => array(
				'label' => 'Image queue running',
				'value' => get_site_transient( 'spc_process_images_process_lock' ) ? 'Yes' : 'No',
			),
			'image_queue_next_run' => array(
				'label' => 'Image queue next run',
				'value' => sunshine_get_image_queue_next_run(),
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
