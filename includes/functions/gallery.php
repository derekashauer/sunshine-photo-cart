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
// view = can know about it's existence but not yet see (hides private, expired)
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

	$meta_keys = apply_filters( 'sunshine_gallery_visibility_meta_keys', array( 'status', 'private_users', 'access_type', 'end_date' ) );
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

	return $meta;
}

/*
 * Filters a list of published gallery IDs down to the ones the current user can
 * see, without constructing full gallery objects or loading full gallery meta.
 * The real SPC_Gallery::can_view()/can_access() checks run against lightweight
 * objects seeded with only the meta those checks read.
 */
function sunshine_filter_gallery_ids_by_visibility( $gallery_ids, $conditional_method = 'access' ) {

	if ( empty( $gallery_ids ) ) {
		return array();
	}

	$gallery_ids = array_map( 'intval', $gallery_ids );

	if ( $conditional_method === 'all' || current_user_can( 'sunshine_manage_options' ) ) {
		// Admins pass every can_view/can_access check and are exempt from the
		// URL-access exclusion, so no per-gallery meta is needed at all.
		return $gallery_ids;
	}

	$visibility_meta = sunshine_get_gallery_visibility_meta( $gallery_ids );

	$visible = array();
	foreach ( $gallery_ids as $gallery_id ) {
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
		if ( $gallery->get_access_type() == 'url' ) {
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

/*
 * Returns the ordered IDs of galleries matching the args that the current user
 * is allowed to see, without loading full post or meta data. Returns false when
 * the query cannot be answered this way (custom post statuses, or a site opted
 * out via the filter) so callers can fall back to sunshine_get_galleries().
 */
function sunshine_get_visible_gallery_ids( $custom_args = array(), $conditional_method = 'access' ) {

	$args = sunshine_get_galleries_query_args( $custom_args, $conditional_method );

	if ( ! apply_filters( 'sunshine_galleries_optimized_query', true, $args, $conditional_method ) ) {
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

function sunshine_get_galleries_count( $custom_args = array(), $conditional_method = 'access' ) {
	$visible_ids = sunshine_get_visible_gallery_ids( $custom_args, $conditional_method );
	if ( $visible_ids !== false ) {
		return count( $visible_ids );
	}
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

function sunshine_get_gallery_descendant_ids( $gallery_id, $status = 'publish' ) {
	// Optimized to fetch only IDs instead of full post objects.
	$children  = array();
	$galleries = get_posts(
		array(
			'post_type'      => 'sunshine-gallery',
			'post_parent'    => $gallery_id,
			'post_status'    => $status,
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	);

	if ( ! empty( $galleries ) ) {
		$children = $galleries;
		// Recursively get grandchildren, great-grandchildren, etc.
		foreach ( $galleries as $child_id ) {
			$grandchildren = sunshine_get_gallery_descendant_ids( $child_id, $status );
			if ( ! empty( $grandchildren ) ) {
				$children = array_merge( $children, $grandchildren );
			}
		}
	}

	return $children;
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
