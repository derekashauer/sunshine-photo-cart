<?php

/**
 * Ensure all Sunshine intermediate sizes exist in attachment metadata.
 *
 * When an uploaded image is smaller than a registered Sunshine size (e.g., sunshine-large),
 * WordPress won't create an intermediate file for that size. This function creates a copy
 * of the original file to serve as the intermediate, so watermarks are applied to the copy
 * instead of the original.
 *
 * @param int    $attachment_id The attachment ID.
 * @param array  $metadata      The attachment metadata array (modified in place).
 * @param string $file_path     The full path to the original image file.
 * @return array The modified metadata array.
 */
function sunshine_ensure_intermediate_sizes( $attachment_id, $metadata, $file_path ) {
	if ( empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
		return $metadata;
	}

	if ( ! file_exists( $file_path ) ) {
		return $metadata;
	}

	$sizes_to_check = apply_filters( 'sunshine_image_sizes', array( 'sunshine-thumbnail', 'sunshine-large' ) );

	if ( ! is_array( $metadata['sizes'] ) ) {
		$metadata['sizes'] = array();
	}

	$file_info = pathinfo( $file_path );
	$file_type = wp_check_filetype( $file_path );
	$dir       = trailingslashit( $file_info['dirname'] );

	foreach ( $sizes_to_check as $size_name ) {
		if ( ! empty( $metadata['sizes'][ $size_name ] ) ) {
			continue;
		}

		$image_width  = $metadata['width'];
		$image_height = $metadata['height'];

		// Generate a filename following WordPress intermediate naming conventions
		$copy_name = $file_info['filename'] . '-' . $image_width . 'x' . $image_height . '.' . $file_info['extension'];
		$copy_name = wp_unique_filename( $dir, $copy_name );
		$copy_path = $dir . $copy_name;

		if ( ! copy( $file_path, $copy_path ) ) {
			SPC()->log( 'Failed to create intermediate copy for ' . $size_name . ': ' . $copy_path );
			continue;
		}

		SPC()->log( 'Created intermediate copy for ' . $size_name . ': ' . $copy_name );

		$metadata['sizes'][ $size_name ] = array(
			'file'      => $copy_name,
			'width'     => $image_width,
			'height'    => $image_height,
			'mime-type' => $file_type['type'],
		);
	}

	return $metadata;
}

function sunshine_get_image_file_name( $image_id ) {
	return get_post_meta( $image_id, 'sunshine_file_name', true );
}

function sunshine_get_image( $image ) {
	return new SPC_Image( $image );
}

function sunshine_get_images( $args = array() ) {
	global $wpdb;

	$final_images = array();
	$args         = wp_parse_args(
		$args,
		array(
			'post_type' => 'attachment',
			// 'post_status' => 'any',
			'meta_key'  => 'sunshine_file_name',
			'nopaging'  => 1,
		)
	);
	$images       = get_posts( $args );
	// $query = new WP_Query( $args );
	// sunshine_log( $query->request );
	if ( ! empty( $images ) ) {
		foreach ( $images as $image ) {
			$final_images[ $image->ID ] = sunshine_get_image( $image->ID );
		}
	}

	// Searching for images if we have a search term.
	if ( ! empty( $args['s'] ) ) {

		$post_parent = ( ! empty( $args['post_parent__in'] ) ) ? "AND {$wpdb->prefix}posts.post_parent IN (" . join( ',', $args['post_parent__in'] ) . ')' : '';

		$query = "
			SELECT {$wpdb->prefix}posts.*
			FROM {$wpdb->prefix}posts
			INNER JOIN {$wpdb->prefix}postmeta ON ( {$wpdb->prefix}posts.ID = {$wpdb->prefix}postmeta.post_id )
			INNER JOIN {$wpdb->prefix}postmeta AS sunshine_meta ON ( {$wpdb->prefix}posts.ID = sunshine_meta.post_id AND sunshine_meta.meta_key = 'sunshine_file_name' )
			WHERE 1=1
			{$post_parent}
			AND {$wpdb->prefix}posts.post_type = 'attachment'
			AND (
				({$wpdb->prefix}posts.post_title LIKE %s)
				OR ({$wpdb->prefix}postmeta.meta_value LIKE %s)
				OR ({$wpdb->prefix}posts.post_excerpt LIKE %s)
				OR ({$wpdb->prefix}posts.post_content LIKE %s)
			)
			OR (
				({$wpdb->prefix}postmeta.meta_key = 'sunshine_file_name' AND {$wpdb->prefix}postmeta.meta_value LIKE %s)
				OR
				({$wpdb->prefix}postmeta.meta_key = '_wp_attachment_metadata' AND {$wpdb->prefix}postmeta.meta_value LIKE %s)
			)
			AND (
				{$wpdb->prefix}posts.post_type = 'attachment'
				AND ({$wpdb->prefix}posts.post_status = 'publish' OR {$wpdb->prefix}posts.post_status = 'private')
			)
			AND {$wpdb->prefix}posts.ID > 0
			GROUP BY {$wpdb->prefix}posts.ID
			ORDER BY {$wpdb->prefix}posts.post_title LIKE %s DESC, {$wpdb->prefix}posts.post_date DESC
		";

		// Preparing the SQL statement
		$query = $wpdb->prepare(
			$query,
			"%{$args['s']}%",
			"%{$args['s']}%",
			"%{$args['s']}%",
			"%{$args['s']}%",
			"%{$args['s']}%",
			"%\\\"{$args['s']}\\\"%",
			"%{$args['s']}%"
		);

		// Running the query
		$results = $wpdb->get_results( $query );
		if ( ! empty( $results ) ) {
			foreach ( $results as $result ) {
				$final_images[ $result->ID ] = sunshine_get_image( $result->ID );
			}
		}
	}

	if ( ! empty( $final_images ) ) {
		foreach ( $final_images as $key => $image ) {
			if ( empty( $image->gallery ) || ! $image->gallery->can_access() || ! empty( $image->gallery->get_access_type( true ) ) ) {
				unset( $final_images[ $key ] );
			}
		}
		return $final_images;
	}

	return false;

}

add_filter( 'posts_where', 'sunshine_search_where', 10, 2 );
function sunshine_search_where( $where, $wp_query_obj ) {
	global $pagenow, $wpdb;

	if ( ! empty( $wp_query_obj->query_vars['sunshine_search'] ) ) {
		$where = preg_replace(
			'/\(\s*' . $wpdb->posts . ".post_title\s+LIKE\s*(\'[^\']+\')\s*\)/",
			'(' . $wpdb->posts . '.post_title LIKE $1) OR (' . $wpdb->postmeta . '.meta_value LIKE $1)',
			$where
		);
	}

	return $where;
}

add_action( 'wp_ajax_nopriv_sunshine_get_image_data', 'sunshine_get_image_data' );
add_action( 'wp_ajax_sunshine_get_image_data', 'sunshine_get_image_data' );
function sunshine_get_image_data() {

	check_ajax_referer( 'sunshinephotocart', 'security' );

	if ( empty( $_POST['image_id'] ) ) {
		wp_send_json_error();
	}

	$image = sunshine_get_image( intval( $_POST['image_id'] ) );
	if ( empty( $image ) ) {
		wp_send_json_error();
	}

	// Verify user has access to the image's gallery.
	if ( ! $image->can_access() ) {
		wp_send_json_error( array( 'reason' => __( 'Access denied', 'sunshine-photo-cart' ) ) );
	}

	wp_send_json_success(
		array(
			'id'  => $image->get_id(),
			'url' => $image->get_image_url(),
		)
	);
	exit;

}

/**
 * Display caption under single image view when enabled.
 */
add_action( 'sunshine_after_image', 'sunshine_show_caption_single_image' );
function sunshine_show_caption_single_image( $image ) {
	if ( ! SPC()->get_option( 'show_caption_single' ) ) {
		return;
	}

	$caption = $image->get_caption();
	if ( ! empty( $caption ) ) {
		echo '<div class="sunshine--image--caption">' . esc_html( $caption ) . '</div>';
	}
}
