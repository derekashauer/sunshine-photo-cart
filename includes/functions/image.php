<?php

/**
 * Fix EXIF orientation on an image file by physically rotating the pixels.
 *
 * WordPress relies on PHP's exif extension for orientation detection during
 * sub-size generation. When the exif extension is unavailable, sub-sizes are
 * generated with incorrect pixel orientation. This function uses Imagick's
 * native EXIF reading (which does not require PHP's exif extension) as the
 * primary method, falling back to WordPress's image editor.
 *
 * Should be called on the original file BEFORE wp_generate_attachment_metadata()
 * so all intermediate sizes are generated from correctly-oriented pixels.
 *
 * @param string $file_path Full path to the image file.
 * @return bool True if orientation was fixed, false if no fix was needed or possible.
 */
function sunshine_fix_image_orientation( $file_path ) {
	$file_type = wp_check_filetype( $file_path );
	if ( ! in_array( $file_type['ext'], array( 'jpg', 'jpeg' ), true ) ) {
		return false;
	}

	// Quick check: read EXIF orientation from the file header without
	// loading/decoding the full image. This is fast and adds negligible
	// overhead for the 99% of images that don't need rotation.
	$orientation = 0;
	if ( function_exists( 'exif_read_data' ) ) {
		$exif = @exif_read_data( $file_path, 'IFD0' );
		if ( ! empty( $exif['Orientation'] ) ) {
			$orientation = (int) $exif['Orientation'];
		}
	} elseif ( class_exists( 'Imagick' ) ) {
		// No PHP exif extension — use Imagick to read orientation only.
		try {
			$probe       = new Imagick( $file_path );
			$orientation = $probe->getImageOrientation();
			$probe->destroy();
		} catch ( Exception $e ) {
			return false;
		}
	}

	// No rotation needed.
	if ( $orientation <= 1 ) {
		return false;
	}

	// Orientation needs fixing — now load and rotate.
	if ( class_exists( 'Imagick' ) ) {
		try {
			$image = new Imagick( $file_path );
			$image->autoOrient();
			$image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
			$quality = $image->getImageCompressionQuality();
			$image->setImageCompressionQuality( $quality ?: 100 );
			$image->writeImage( $file_path );
			$image->destroy();
			return true;
		} catch ( Exception $e ) {
			// Fall through to WordPress editor.
		}
	}

	// Fall back to WordPress image editor.
	$editor = wp_get_image_editor( $file_path );
	if ( is_wp_error( $editor ) ) {
		return false;
	}

	$rotated = $editor->maybe_exif_rotate();
	if ( true === $rotated ) {
		$saved = $editor->save( $file_path );
		return ! is_wp_error( $saved );
	}

	return false;
}

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

		$post_parent = '';
		if ( ! empty( $args['post_parent__in'] ) ) {
			$parent_ids  = implode( ',', array_map( 'intval', $args['post_parent__in'] ) );
			$post_parent = "AND p.post_parent IN ({$parent_ids})";
		}

		$query = "
			SELECT p.*
			FROM {$wpdb->prefix}posts AS p
			INNER JOIN {$wpdb->prefix}postmeta AS sunshine_meta
				ON ( p.ID = sunshine_meta.post_id AND sunshine_meta.meta_key = 'sunshine_file_name' )
			WHERE p.post_type = 'attachment'
			AND ( p.post_status = 'inherit' OR p.post_status = 'publish' OR p.post_status = 'private' )
			{$post_parent}
			AND (
				p.post_title LIKE %s
				OR p.post_excerpt LIKE %s
				OR p.post_content LIKE %s
				OR sunshine_meta.meta_value LIKE %s
				OR EXISTS (
					SELECT 1 FROM {$wpdb->prefix}postmeta
					WHERE post_id = p.ID
					AND meta_key = 'sunshine_keywords'
					AND meta_value LIKE %s
				)
				OR EXISTS (
					SELECT 1 FROM {$wpdb->prefix}postmeta
					WHERE post_id = p.ID
					AND meta_key = '_wp_attachment_metadata'
					AND meta_value LIKE %s
				)
			)
			GROUP BY p.ID
			ORDER BY p.post_title LIKE %s DESC, p.post_date DESC
		";

		// Preparing the SQL statement
		$query = $wpdb->prepare(
			$query,
			"%{$args['s']}%",
			"%{$args['s']}%",
			"%{$args['s']}%",
			"%{$args['s']}%",
			"%{$args['s']}%",
			"%{$args['s']}%",
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

/**
 * Get the server-side secret used to sign lab-file URLs.
 *
 * Generated once and stored (autoloaded). Never sent anywhere — only the
 * server can mint or verify a signed lab-file URL.
 *
 * @return string
 */
function sunshine_get_file_signing_secret() {
	$secret = SPC()->get_option( 'file_signing_secret' );
	if ( empty( $secret ) ) {
		$secret = bin2hex( random_bytes( 32 ) );
		SPC()->update_option( 'file_signing_secret', $secret, true );
	}
	return $secret;
}

/**
 * Build a signed, time-limited URL that lets an external service (e.g. a print
 * lab) fetch the full-resolution original of an attachment.
 *
 * The URL is served through PHP, so it bypasses the referer-based hotlink
 * .htaccess without weakening it. The signature covers the attachment ID and
 * expiry, so it can't be altered to reach a different file or extend its life.
 *
 * @param int      $attachment_id The attachment ID.
 * @param int|null $ttl           Seconds the URL stays valid (default 30 days).
 * @return string The signed URL, or '' if the attachment ID is invalid.
 */
function sunshine_get_lab_file_url( $attachment_id, $ttl = null ) {
	$attachment_id = intval( $attachment_id );
	if ( ! $attachment_id ) {
		return '';
	}

	if ( is_null( $ttl ) ) {
		$ttl = 30 * DAY_IN_SECONDS;
	}
	$ttl     = intval( apply_filters( 'sunshine_lab_file_url_ttl', $ttl, $attachment_id ) );
	$expires = time() + $ttl;

	$sig = hash_hmac( 'sha256', $attachment_id . '|' . $expires, sunshine_get_file_signing_secret() );

	return add_query_arg(
		array(
			'sunshine_lab_file' => $attachment_id,
			'expires'           => $expires,
			'sig'               => $sig,
		),
		home_url( '/' )
	);
}

/**
 * Serve a full-resolution original in response to a valid signed lab-file URL.
 */
add_action( 'wp', 'sunshine_handle_lab_file' );
function sunshine_handle_lab_file() {

	if ( empty( $_GET['sunshine_lab_file'] ) ) {
		return;
	}

	$attachment_id = intval( $_GET['sunshine_lab_file'] );
	$expires       = isset( $_GET['expires'] ) ? intval( $_GET['expires'] ) : 0;
	$sig           = isset( $_GET['sig'] ) ? (string) $_GET['sig'] : '';

	// Expired links are Gone.
	if ( ! $expires || time() > $expires ) {
		status_header( 410 );
		exit;
	}

	// Verify the signature in constant time.
	$expected = hash_hmac( 'sha256', $attachment_id . '|' . $expires, sunshine_get_file_signing_secret() );
	if ( empty( $sig ) || ! hash_equals( $expected, $sig ) ) {
		status_header( 403 );
		exit;
	}

	if ( 'attachment' !== get_post_type( $attachment_id ) ) {
		status_header( 404 );
		exit;
	}

	// If the original has been offloaded to cloud storage, redirect to the
	// provider's freshly-signed URL (the cloud-storage addon filters this).
	$local_path = get_attached_file( $attachment_id );
	if ( empty( $local_path ) || ! file_exists( $local_path ) ) {
		$remote_url = wp_get_attachment_url( $attachment_id );
		if ( $remote_url ) {
			wp_redirect( esc_url_raw( $remote_url ) );
			exit;
		}
		status_header( 404 );
		exit;
	}

	$mime = get_post_mime_type( $attachment_id );
	if ( ! $mime ) {
		$filetype = wp_check_filetype( $local_path );
		$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';
	}

	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	nocache_headers();
	header( 'Content-Type: ' . $mime );
	header( 'Content-Disposition: inline; filename="' . basename( $local_path ) . '"' );
	header( 'Content-Length: ' . filesize( $local_path ) );
	readfile( $local_path );
	exit;
}
