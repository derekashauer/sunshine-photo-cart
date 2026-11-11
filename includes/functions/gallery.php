<?php

function sunshine_get_gallery( $gallery ) {
	return new SPC_Gallery( $gallery );
}

// Builds the final WP_Query args for gallery queries, shared by all gallery
// fetching functions so the `sunshine_get_galleries_args` filter always applies.
function sunshine_get_galleries_query_args( $custom_args = array(), $conditional_method = 'access' ) {

	if ( SPC()->get_option( 'gallery_order' ) == 'date_new_old' ) {
		$orderby = 'date';
		$order   = 'DESC';
	} elseif ( SPC()->get_option( 'gallery_order' ) == 'date_old_new' ) {
		$orderby = 'date';
		$order   = 'ASC';
	} elseif ( SPC()->get_option( 'gallery_order' ) == 'title' ) {
		$orderby = 'title';
		$order   = 'ASC';
	} else {
		$orderby = 'menu_order';
		$order   = 'ASC';
	}
	$args = array(
		'post_type'      => 'sunshine-gallery',
		// 'post_parent' => 0,
		'orderby'        => $orderby,
		'order'          => $order,
		'posts_per_page' => -1,
		// 'update_post_meta_cache' => false,
		'post_status'    => array( 'publish' ),
	);

	$args = wp_parse_args( $custom_args, $args );
	$args = apply_filters( 'sunshine_get_galleries_args', $args, $conditional_method );

	return $args;
}

// conditional_method = access/view
// access = has permission (hides private, password, expired)
// view = can know about it's existence but not yet see (hides private, but still
// lists password protected, email gated and expired galleries)
function sunshine_get_galleries( $custom_args = array(), $conditional_method = 'access' ) {

	$args = sunshine_get_galleries_query_args( $custom_args, $conditional_method );

	$galleries = new WP_Query( $args );

	if ( $galleries->have_posts() ) {
		$final_galleries = array();
		foreach ( $galleries->posts as $gallery ) {
			$gallery = sunshine_get_gallery( $gallery );
			if ( $conditional_method === 'all' ) {
				$final_galleries[ $gallery->get_id() ] = $gallery;
			} elseif ( $conditional_method === 'view' && $gallery->can_view() ) {
				if ( $gallery->get_access_type() == 'url' && ! current_user_can( 'sunshine_manage_options' ) ) {
					continue;
				}
				$final_galleries[ $gallery->get_id() ] = $gallery;
			} elseif ( $conditional_method === 'access' && $gallery->can_access() ) {
				if ( $gallery->get_access_type() == 'url' && ! current_user_can( 'sunshine_manage_options' ) ) {
					continue;
				}
				$final_galleries[ $gallery->get_id() ] = $gallery;
			}
		}
		return $final_galleries;
	}

	return false;

}

/*
 * Fetches the raw values of only the meta keys needed for gallery visibility
 * checks, in bulk. Galleries can carry very large meta (the `images` array and
 * add-on data), so priming the full meta cache for every gallery just to decide
 * which ones a visitor may see exhausts memory on large sites.
 */
function sunshine_get_gallery_visibility_meta( $gallery_ids ) {
	global $wpdb;

	$meta_keys = apply_filters( 'sunshine_gallery_visibility_meta_keys', array( 'status', 'private_users', 'access_type', 'end_date', 'terms' ) );
	$meta      = array_fill_keys( array_map( 'intval', $gallery_ids ), array_fill_keys( $meta_keys, '' ) );

	foreach ( array_chunk( array_map( 'intval', $gallery_ids ), 500 ) as $chunk ) {
		$id_placeholders  = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
		$key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
		$rows             = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($id_placeholders) AND meta_key IN ($key_placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $chunk, $meta_keys )
			)
		);
		foreach ( $rows as $row ) {
			$meta[ (int) $row->post_id ][ $row->meta_key ] = $row->meta_value;
		}
	}

	// The bulk read above talks to the database directly, so anything hooked to
	// get_post_metadata to change a gallery's visibility would be skipped. Give
	// those hooks the same short-circuit the metadata API gives them. Nothing is
	// hooked on most sites, and then this costs nothing.
	if ( has_filter( 'get_post_metadata' ) ) {
		foreach ( $meta as $gallery_id => $values ) {
			foreach ( $meta_keys as $meta_key ) {
				$check = apply_filters( 'get_post_metadata', null, $gallery_id, $meta_key, true, 'post' );
				if ( null === $check ) {
					continue;
				}
				// A filter answering a single-value read with an array returns
				// the list of values, so the first one is the value itself.
				$meta[ $gallery_id ][ $meta_key ] = is_array( $check ) ? reset( $check ) : $check;
			}
		}
	}

	return $meta;
}

/*
 * Filters a list of published gallery IDs down to the ones the current user can
 * see, without constructing full gallery objects or loading full gallery meta.
 * The real SPC_Gallery::can_view()/can_access() checks run against lightweight
 * objects seeded with only the meta those checks read. Callers can retain URL
 * galleries or use the standard metadata API when their previous behavior
 * requires it.
 */
function sunshine_filter_gallery_ids_by_visibility( $gallery_ids, $conditional_method = 'access', $exclude_url = true, $use_optimized_query = true ) {

	if ( empty( $gallery_ids ) ) {
		return array();
	}

	$gallery_ids = array_map( 'intval', $gallery_ids );

	if ( $conditional_method === 'all' || current_user_can( 'sunshine_manage_options' ) ) {
		// Admins pass every can_view/can_access check and are exempt from the
		// URL-access exclusion, so no per-gallery meta is needed at all.
		return $gallery_ids;
	}

	$visibility_meta = $use_optimized_query ? sunshine_get_gallery_visibility_meta( $gallery_ids ) : array();

	// can_access() also checks each gallery's parents, which needs every gallery's post row.
	// Load them all in one query rather than one per gallery (no meta or terms, those stay light).
	if ( $conditional_method === 'access' ) {
		_prime_post_caches( $gallery_ids, false, false );
	}

	$visible = array();
	foreach ( $gallery_ids as $gallery_id ) {
		if ( $use_optimized_query ) {
			$gallery = new SPC_Gallery(
				(object) array(
					'ID'          => $gallery_id,
					'post_type'   => 'sunshine-gallery',
					'post_status' => 'publish',
					'post_parent' => 0,
					'post_title'  => '',
				)
			);
			$gallery->prefetch_meta( $visibility_meta[ $gallery_id ] );
		} else {
			// Use the standard metadata API so get_post_metadata filters and other
			// dynamic visibility customizations retain their previous behavior.
			$gallery = sunshine_get_gallery( $gallery_id );
		}
		if ( empty( $gallery ) || ! $gallery->get_id() ) {
			continue;
		}
		if ( $exclude_url && $gallery->get_access_type() == 'url' ) {
			continue;
		}
		if ( $conditional_method === 'view' && ! $gallery->can_view() ) {
			continue;
		}
		if ( $conditional_method === 'access' && ! $gallery->can_access() ) {
			continue;
		}
		$visible[] = $gallery_id;
	}

	return $visible;
}

function sunshine_galleries_use_optimized_query( $args, $conditional_method = 'access' ) {
	return (bool) apply_filters( 'sunshine_galleries_optimized_query', true, $args, $conditional_method );
}

/*
 * Returns the ordered IDs of galleries matching the args that the current user
 * is allowed to see, without loading full post or meta data. Returns false when
 * the query cannot be answered this way (custom post statuses, or a site opted
 * out via the filter) so callers can fall back to sunshine_get_galleries().
 */
function sunshine_get_visible_gallery_ids( $custom_args = array(), $conditional_method = 'access' ) {

	$args = sunshine_get_galleries_query_args( $custom_args, $conditional_method );

	if ( ! sunshine_galleries_use_optimized_query( $args, $conditional_method ) ) {
		return false;
	}

	// The lightweight visibility check assumes published galleries; anything
	// else must construct real objects so post_status is checked properly.
	$post_status = isset( $args['post_status'] ) ? array_values( (array) $args['post_status'] ) : array( 'publish' );
	if ( $post_status !== array( 'publish' ) ) {
		return false;
	}
	if ( ! empty( $args['fields'] ) ) {
		return false;
	}

	$id_args = array_merge(
		$args,
		array(
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	$query = new WP_Query( $id_args );

	return sunshine_filter_gallery_ids_by_visibility( $query->posts, $conditional_method );
}

/*
 * Fetches one page of galleries plus the total count, applying the per-page
 * limit before gallery objects are constructed. Use this instead of slicing the
 * result of sunshine_get_galleries() so memory stays constant regardless of how
 * many galleries exist. Returns array( 'galleries' => ..., 'total' => ..., 'pages' => ... ).
 * $per_page of 0 means no pagination (all galleries are returned).
 */
function sunshine_get_galleries_paginated( $custom_args = array(), $conditional_method = 'access', $page = 1, $per_page = 0 ) {

	$page        = max( 1, (int) $page );
	$per_page    = (int) $per_page;
	$visible_ids = sunshine_get_visible_gallery_ids( $custom_args, $conditional_method );

	if ( $visible_ids === false ) {
		// Fall back to the full fetch for queries the optimized path cannot serve.
		$galleries = sunshine_get_galleries( $custom_args, $conditional_method );
		$galleries = ! empty( $galleries ) ? $galleries : array();
		$total     = count( $galleries );
		if ( $per_page > 0 ) {
			$galleries = array_slice( $galleries, ( $page - 1 ) * $per_page, $per_page, true );
		}
		return array(
			'galleries' => $galleries,
			'total'     => $total,
			'pages'     => ( $per_page > 0 ) ? (int) ceil( $total / $per_page ) : 1,
		);
	}

	$total    = count( $visible_ids );
	$page_ids = ( $per_page > 0 ) ? array_slice( $visible_ids, ( $page - 1 ) * $per_page, $per_page ) : $visible_ids;

	$galleries = array();
	if ( ! empty( $page_ids ) ) {
		_prime_post_caches( $page_ids, true, true );
		foreach ( $page_ids as $gallery_id ) {
			$gallery_post = get_post( $gallery_id );
			if ( $gallery_post ) {
				$galleries[ $gallery_id ] = sunshine_get_gallery( $gallery_post );
			}
		}
	}

	return array(
		'galleries' => $galleries,
		'total'     => $total,
		'pages'     => ( $per_page > 0 ) ? (int) ceil( $total / $per_page ) : 1,
	);
}

// Kept for themes and add-ons. Core counts galleries through the 'total' that
// sunshine_get_galleries_paginated() returns, which does not build an object
// per gallery.
function sunshine_get_galleries_count( $custom_args = array(), $conditional_method = 'access' ) {
	$galleries = sunshine_get_galleries( $custom_args, $conditional_method );
	if ( $galleries ) {
		return count( $galleries );
	}
	return 0;
}

function sunshine_get_gallery_descendants( $gallery_id, $status = 'publish' ) {
	$children  = array();
	$galleries = get_posts(
		array(
			'numberposts'      => -1,
			'post_status'      => $status,
			'post_type'        => 'sunshine-gallery',
			'post_parent'      => $gallery_id,
			'suppress_filters' => false,
		)
	);
	// now grab the grand children.
	foreach ( $galleries as $child ) {
		$gchildren = sunshine_get_gallery_descendants( $child->ID, $status );
		if ( ! empty( $gchildren ) ) {
			$children = array_merge( $children, $gchildren );
		}
	}
	$children = array_merge( $children, $galleries );
	return $children;
}

/**
 * Every gallery's child IDs, keyed by parent gallery ID.
 *
 * One query fetches every gallery's ID and parent, then the tree is walked in
 * PHP — the same strategy core's get_page_children() uses — instead of running
 * one query per gallery node, which does not scale on sites with thousands of
 * sub-galleries.
 *
 * The map covers the whole site, so it is built once per request and reused.
 * Discounts and product sources ask for the descendants of several galleries in
 * a row, and each of those would otherwise refetch every gallery on the site.
 *
 * @param string|array $status Post status to include.
 * @param bool         $flush  Throw the cached map away instead of returning it.
 * @return array Arrays of child gallery IDs, keyed by parent gallery ID.
 */
function sunshine_get_gallery_parent_map( $status = 'publish', $flush = false ) {
	static $maps = array();

	if ( $flush ) {
		$maps = array();
		return array();
	}

	$key = is_array( $status ) ? implode( ',', $status ) : (string) $status;

	if ( isset( $maps[ $key ] ) ) {
		return $maps[ $key ];
	}

	$relationships = get_posts(
		array(
			'post_type'      => 'sunshine-gallery',
			'post_status'    => $status,
			'fields'         => 'id=>parent',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	);

	// 'id=>parent' returns an array of parent IDs keyed by gallery ID, never post
	// objects. WP_Query keys that array by the plain ID when the query runs
	// against the database, but by 'post_parent:<id>' when the same query is
	// served from the object cache, so strip the prefix before using the key.
	$children_by_parent = array();
	foreach ( $relationships as $child_id => $parent_id ) {
		if ( ! is_numeric( $parent_id ) ) {
			continue;
		}
		$child_id = (int) str_replace( 'post_parent:', '', (string) $child_id );
		if ( ! $child_id ) {
			continue;
		}
		$children_by_parent[ (int) $parent_id ][] = $child_id;
	}

	$maps[ $key ] = $children_by_parent;

	return $maps[ $key ];
}

/**
 * Adding, changing or removing a gallery makes the cached parent map stale.
 */
add_action( 'save_post_sunshine-gallery', 'sunshine_flush_gallery_parent_map' );
add_action( 'deleted_post', 'sunshine_flush_gallery_parent_map', 10, 2 );
function sunshine_flush_gallery_parent_map( $post_id = 0, $post = null ) {
	if ( $post && 'sunshine-gallery' !== $post->post_type ) {
		return;
	}
	sunshine_get_gallery_parent_map( 'publish', true );
}

function sunshine_get_gallery_descendant_ids( $gallery_id, $status = 'publish' ) {
	$children_by_parent = sunshine_get_gallery_parent_map( $status );

	if ( empty( $children_by_parent ) ) {
		return array();
	}

	// Breadth-first walk down from the requested gallery. Collecting into keys
	// guards against corrupted parent pointers ever looping.
	$children = array();
	$queue    = array( (int) $gallery_id );
	while ( ! empty( $queue ) ) {
		$parent_id = array_shift( $queue );
		if ( empty( $children_by_parent[ $parent_id ] ) ) {
			continue;
		}
		foreach ( $children_by_parent[ $parent_id ] as $child_id ) {
			if ( isset( $children[ $child_id ] ) ) {
				continue;
			}
			$children[ $child_id ] = true;
			$queue[]               = $child_id;
		}
	}

	return array_keys( $children );
}

function sunshine_get_image_dimensions( $size = 'thumbnail' ) {
	return SPC()->get_option( $size . '_size' );
}
function sunshine_get_thumbnail_dimension( $side = 'w' ) {
	$dimensions = sunshine_get_image_dimensions( 'thumbnail' );
	if ( ! empty( $dimensions ) && ! empty( $dimensions[ $side ] ) ) {
		return $dimensions[ $side ];
	}
	if ( $side == 'w' ) {
		return 400;
	} elseif ( $side == 'h' ) {
		return 300;
	}
	return false;
}

function sunshine_get_large_dimension( $side = 'w' ) {
	$dimensions = sunshine_get_image_dimensions( 'large' );
	if ( ! empty( $dimensions ) && ! empty( $dimensions[ $side ] ) ) {
		return $dimensions[ $side ];
	}
	if ( $side == 'w' ) {
		return 400;
	} elseif ( $side == 'h' ) {
		return 300;
	}
	return false;
}

/**********************
GALLERY READY STATE
 ***********************/

/**
 * Meta key holding when an image was last added to a gallery.
 */
const SUNSHINE_GALLERY_LAST_IMAGE_META = '_sunshine_last_image_added';

/**
 * Meta key marking that sunshine_gallery_ready has already fired for a gallery.
 */
const SUNSHINE_GALLERY_READY_META = '_sunshine_gallery_ready';

/**
 * How long a gallery must go without a new image before it counts as finished.
 *
 * Uploads arrive one request per photo, so the queue legitimately empties between
 * images. Without a quiet period a gallery would be declared ready after the first
 * photo of a hundred.
 *
 * @return int Seconds.
 */
function sunshine_gallery_ready_quiet_period() {
	return (int) apply_filters( 'sunshine_gallery_ready_quiet_period', 5 * MINUTE_IN_SECONDS );
}

/**
 * Whether any of a gallery's images are still queued for processing.
 *
 * Derived from the images themselves rather than a counter kept on the gallery.
 * A counter has to be incremented and decremented in matching pairs, and drifts
 * permanently the moment one decrement is missed; this cannot get out of step
 * with reality.
 *
 * @param int $gallery_id Gallery ID.
 * @return bool
 */
function sunshine_gallery_has_images_processing( $gallery_id ) {
	$pending = get_posts(
		array(
			'post_type'        => 'attachment',
			'post_parent'      => (int) $gallery_id,
			'post_status'      => 'inherit',
			'fields'           => 'ids',
			'posts_per_page'   => 1,
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => array(
				array(
					'key'   => SUNSHINE_IMAGE_PROCESSING_META,
					'value' => '1',
				),
			),
		)
	);

	return ! empty( $pending );
}

/**
 * Record that an image was added to a gallery.
 *
 * Called for every image however it arrives — admin upload, FTP import, Bulk
 * Galleries, the API — because they all go through sunshine_insert_gallery_image().
 * Adding an image means the gallery is not finished, so any earlier "ready" is
 * cleared and the quiet period starts again.
 *
 * @param int $gallery_id Gallery ID.
 */
function sunshine_gallery_image_added( $gallery_id ) {
	$gallery_id = (int) $gallery_id;
	if ( ! $gallery_id ) {
		return;
	}

	delete_post_meta( $gallery_id, SUNSHINE_GALLERY_READY_META );
	update_post_meta( $gallery_id, SUNSHINE_GALLERY_LAST_IMAGE_META, time() );

	// Scheduled here rather than on install so it exists whenever there is actually
	// something to check, including on sites that upgraded without the event.
	if ( ! wp_next_scheduled( 'sunshine_gallery_ready_check' ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'sunshine_gallery_ready_interval', 'sunshine_gallery_ready_check' );
	}
}

/**
 * Schedule used by the gallery ready check.
 */
add_filter( 'cron_schedules', 'sunshine_gallery_ready_cron_schedule' );
function sunshine_gallery_ready_cron_schedule( $schedules ) {
	$schedules['sunshine_gallery_ready_interval'] = array(
		'interval' => MINUTE_IN_SECONDS,
		'display'  => __( 'Every minute', 'sunshine-photo-cart' ),
	);

	return $schedules;
}

/**
 * Whether a gallery is finished and safe to send people to.
 *
 * All four have to hold:
 *
 * - it is published,
 * - it actually has images,
 * - nothing is still queued for background processing,
 * - and no new image has arrived for a while, so the upload is genuinely over.
 *
 * That last one is what stops a fifty photo upload being declared ready after the
 * first photo, since each photo arrives in its own request and the queue empties in
 * between. Cloud storage offloading deliberately is not part of this: images are
 * served locally until they reach the bucket, so it makes no difference to a visitor.
 *
 * @param int $gallery_id Gallery ID.
 * @return bool
 */
function sunshine_gallery_is_ready( $gallery_id ) {
	$gallery_id = (int) $gallery_id;

	if ( ! $gallery_id || 'sunshine-gallery' !== get_post_type( $gallery_id ) ) {
		return false;
	}

	if ( 'publish' !== get_post_status( $gallery_id ) ) {
		return false;
	}

	if ( sunshine_gallery_has_images_processing( $gallery_id ) ) {
		return false;
	}

	$gallery = sunshine_get_gallery( $gallery_id );
	if ( ! $gallery || ! $gallery->get_image_count() ) {
		return false;
	}

	$last_added = (int) get_post_meta( $gallery_id, SUNSHINE_GALLERY_LAST_IMAGE_META, true );
	if ( $last_added && ( time() - $last_added ) < sunshine_gallery_ready_quiet_period() ) {
		return false;
	}

	return true;
}

/**
 * Fire `sunshine_gallery_ready` once a gallery is finished.
 *
 * Safe to call as often as you like; it only fires once per gallery, and fires again
 * only if more images are added later.
 *
 * @param int $gallery_id Gallery ID.
 * @return bool Whether the hook fired on this call.
 */
function sunshine_maybe_gallery_ready( $gallery_id ) {
	$gallery_id = (int) $gallery_id;

	if ( get_post_meta( $gallery_id, SUNSHINE_GALLERY_READY_META, true ) ) {
		return false;
	}

	if ( ! sunshine_gallery_is_ready( $gallery_id ) ) {
		return false;
	}

	update_post_meta( $gallery_id, SUNSHINE_GALLERY_READY_META, time() );
	delete_post_meta( $gallery_id, SUNSHINE_GALLERY_LAST_IMAGE_META );

	/**
	 * Fires once, when a gallery is published and all of its images have finished
	 * processing.
	 *
	 * @param int $gallery_id Gallery ID.
	 */
	do_action( 'sunshine_gallery_ready', $gallery_id );

	return true;
}

/**
 * Publishing a gallery is one of the moments it can become ready.
 *
 * Usually it is not: a gallery is normally published before its photos are uploaded,
 * so sunshine_gallery_is_ready() will say no and the check below picks it up later.
 */
add_action( 'transition_post_status', 'sunshine_gallery_check_ready_on_publish', 10, 3 );
function sunshine_gallery_check_ready_on_publish( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || $new_status === $old_status ) {
		return;
	}

	if ( ! $post || 'sunshine-gallery' !== $post->post_type ) {
		return;
	}

	sunshine_maybe_gallery_ready( $post->ID );
}

/**
 * Recurring check for galleries that have finished uploading.
 *
 * The quiet period means readiness cannot be decided at the moment the last image
 * finishes — nothing knows yet whether another is about to arrive. So galleries that
 * have had an image added are re-checked here until they settle.
 */
add_action( 'sunshine_gallery_ready_check', 'sunshine_check_galleries_ready' );
function sunshine_check_galleries_ready() {
	$gallery_ids = get_posts(
		array(
			'post_type'        => 'sunshine-gallery',
			'post_status'      => 'publish',
			'posts_per_page'   => 50,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'meta_query'       => array(
				array(
					'key'     => SUNSHINE_GALLERY_LAST_IMAGE_META,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => SUNSHINE_GALLERY_READY_META,
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);

	if ( empty( $gallery_ids ) ) {
		// Nothing waiting, so stop checking. Adding an image schedules it again.
		wp_clear_scheduled_hook( 'sunshine_gallery_ready_check' );
		return;
	}

	foreach ( $gallery_ids as $gallery_id ) {
		if ( sunshine_maybe_gallery_ready( $gallery_id ) ) {
			continue;
		}

		// A gallery with no images left can never be reported ready, so nothing
		// would ever remove its marker and this check would keep running every
		// minute for good. Drop the marker and let the gallery go.
		$gallery = sunshine_get_gallery( $gallery_id );
		if ( ! $gallery || ! $gallery->get_image_count() ) {
			delete_post_meta( $gallery_id, SUNSHINE_GALLERY_LAST_IMAGE_META );
		}
	}
}

/**
 * The starter text for gallery terms, used until the photographer writes their own.
 *
 * @return string
 */
function sunshine_get_gallery_terms_default_text() {
	$business = get_bloginfo( 'name' );
	/* translators: %s is the photographer's business (site) name */
	$text  = '<p>' . sprintf( __( 'The photos in this gallery are copyrighted by %s and are shown as proofs for viewing and ordering only.', 'sunshine-photo-cart' ), $business ) . '</p>';
	$text .= '<p>' . __( 'You may not download, screenshot, copy, share or print these proofs, or remove, crop out or edit away any watermark, including with AI tools. Doing so is a copyright violation and may break the law.', 'sunshine-photo-cart' ) . '</p>';
	$text .= '<p>' . __( 'Clean, full-quality versions are available when you order them.', 'sunshine-photo-cart' ) . '</p>';
	return $text;
}

/**
 * The gallery terms visitors agree to.
 *
 * @return string
 */
function sunshine_get_gallery_terms_text() {
	$text = SPC()->get_option( 'gallery_terms_text' );
	if ( empty( $text ) ) {
		$text = sunshine_get_gallery_terms_default_text();
	}
	return $text;
}

/**
 * A short ID for the current wording of the terms. Changing the wording changes it, so everyone is asked again.
 *
 * @return string
 */
function sunshine_get_gallery_terms_version() {
	// Tags become spaces so a paragraph break and a line break read the same.
	$words = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( preg_replace( '/<[^>]*>/', ' ', sunshine_get_gallery_terms_text() ) ) ) );
	return substr( md5( $words ), 0, 12 );
}

/**
 * Whether guests are asked for an email address when they agree to the terms.
 *
 * @return bool
 */
function sunshine_gallery_terms_ask_email() {
	return ! SPC()->get_option( 'gallery_terms_no_email' );
}

/**
 * The email address Sunshine already knows for the current visitor, if any.
 *
 * @return string
 */
function sunshine_get_known_visitor_email() {
	if ( is_user_logged_in() ) {
		return wp_get_current_user()->user_email;
	}
	$email = SPC()->session->get( 'email' );
	return is_email( $email ) ? $email : '';
}

/**
 * Has the current visitor agreed to the current version of the gallery terms.
 *
 * @return bool
 */
function sunshine_has_agreed_to_gallery_terms() {
	$version = sunshine_get_gallery_terms_version();
	// The session copy only counts for the same person, so another customer logging in on this browser is asked again.
	$session_agreement = SPC()->session->get( 'gallery_terms' );
	if ( is_array( $session_agreement ) && isset( $session_agreement['version'], $session_agreement['user_id'] ) && $session_agreement['version'] === $version && get_current_user_id() === (int) $session_agreement['user_id'] ) {
		return true;
	}
	if ( is_user_logged_in() ) {
		$agreements = sunshine_get_gallery_terms_agreements(
			array(
				'user_id' => get_current_user_id(),
				'version' => $version,
				'limit'   => 1,
			)
		);
		if ( $agreements ) {
			return true;
		}
	}
	return false;
}

/**
 * Gallery terms agreement records, newest first.
 *
 * @param array $args gallery_id, user_id, email and version narrow the list (an email also
 *                    finds rows saved under the account that has it now); limit caps it.
 * @return array Each record has agreement_id, gallery_id, user_id, email, version and time.
 */
function sunshine_get_gallery_terms_agreements( $args = array() ) {
	global $wpdb;

	$args  = wp_parse_args(
		$args,
		array(
			'gallery_id' => 0,
			'user_id'    => 0,
			'email'      => '',
			'version'    => '',
			'limit'      => 0,
		)
	);
	$where = array();
	if ( $args['gallery_id'] ) {
		$where[] = $wpdb->prepare( 'gallery_id = %d', $args['gallery_id'] );
	}
	if ( $args['user_id'] ) {
		$where[] = $wpdb->prepare( 'user_id = %d', $args['user_id'] );
	}
	if ( $args['email'] ) {
		$user    = get_user_by( 'email', $args['email'] );
		$where[] = $user ? $wpdb->prepare( '( email = %s OR user_id = %d )', $args['email'], $user->ID ) : $wpdb->prepare( 'email = %s', $args['email'] );
	}
	if ( $args['version'] ) {
		$where[] = $wpdb->prepare( 'version = %s', $args['version'] );
	}
	if ( ! $where ) {
		return array();
	}

	$sql = "SELECT * FROM {$wpdb->prefix}sunshine_terms_agreements WHERE " . implode( ' AND ', $where ) . ' ORDER BY time DESC, agreement_id DESC';
	if ( $args['limit'] ) {
		$sql .= $wpdb->prepare( ' LIMIT %d', $args['limit'] );
	}
	$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- each part is prepared above.

	return is_array( $rows ) ? $rows : array();
}

/**
 * Remove a gallery's terms agreements when the gallery is deleted, as its other data is.
 *
 * @param int $post_id The post being deleted.
 */
function sunshine_delete_gallery_terms_agreements( $post_id ) {
	global $wpdb;
	if ( 'sunshine-gallery' !== get_post_type( $post_id ) ) {
		return;
	}
	$wpdb->delete( $wpdb->prefix . 'sunshine_terms_agreements', array( 'gallery_id' => $post_id ), array( '%d' ) );
}
add_action( 'before_delete_post', 'sunshine_delete_gallery_terms_agreements' );

/**
 * Remember that the current visitor agreed to the gallery terms, and keep a record when we know who they are.
 *
 * @param SPC_Gallery $gallery The gallery they were entering.
 * @param string      $email   The email they gave, if any.
 */
function sunshine_record_gallery_terms_agreement( $gallery, $email = '' ) {
	$version = sunshine_get_gallery_terms_version();
	SPC()->session->set(
		'gallery_terms',
		array(
			'version' => $version,
			'user_id' => get_current_user_id(),
		)
	);

	$user_id = get_current_user_id();
	if ( empty( $email ) ) {
		$email = sunshine_get_known_visitor_email();
	}
	if ( ! $user_id && empty( $email ) ) {
		// No way to tell who this was, so a record would prove nothing.
		return;
	}

	// The starter text is rebuilt from translations and the site name, so save it once people start agreeing to it.
	if ( ! SPC()->get_option( 'gallery_terms_text' ) ) {
		SPC()->update_option( 'gallery_terms_text', sunshine_get_gallery_terms_text() );
	}

	// Keep the exact wording of each version, so a record always shows what was agreed to.
	$versions = get_option( 'sunshine_gallery_terms_versions', array() );
	if ( ! is_array( $versions ) ) {
		$versions = array();
	}
	if ( ! isset( $versions[ $version ] ) ) {
		$versions[ $version ] = array(
			'text' => sunshine_get_gallery_terms_text(),
			'time' => time(),
		);
		update_option( 'sunshine_gallery_terms_versions', $versions, false );
	}

	global $wpdb;
	$agreement = array(
		'gallery_id' => $gallery->get_id(),
		'user_id'    => $user_id,
		'email'      => $email,
		'version'    => $version,
		'time'       => time(),
	);
	$table     = $wpdb->prefix . 'sunshine_terms_agreements';
	$exists    = $wpdb->get_var( $wpdb->prepare( "SELECT agreement_id FROM {$table} WHERE gallery_id = %d AND user_id = %d AND email = %s AND version = %s LIMIT 1", $agreement['gallery_id'], $user_id, $email, $version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $exists && false === $wpdb->insert( $table, $agreement ) ) {
		// Right after an update the table can be missing until an admin opens wp-admin, so make it now.
		maybe_sunshine_create_custom_tables();
		$wpdb->insert( $table, $agreement );
	}
	SPC()->log( 'Gallery terms agreed for ' . $gallery->get_name() . ( $email ? ': ' . $email : '' ) );
	do_action( 'sunshine_gallery_terms_agreed', $agreement, $gallery );
}

/**
 * What the current visitor still has to fill in before entering a gallery.
 *
 * @param SPC_Gallery $gallery    The gallery.
 * @param bool        $skip_email Skip the email gate (a favorited image was already reached once).
 * @return array password, email and terms, each true when needed. All false means nothing is needed.
 */
function sunshine_get_gallery_access_fields( $gallery, $skip_email = false ) {
	$terms = $gallery->terms_required();
	$email = ! $skip_email && $gallery->email_required();
	if ( $terms && sunshine_gallery_terms_ask_email() && ! sunshine_get_known_visitor_email() ) {
		$email = true;
	}
	return array(
		'password' => $gallery->password_required(),
		'email'    => $email,
		'terms'    => $terms,
	);
}
