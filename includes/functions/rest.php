<?php
/**
 * Keep gallery content out of the WordPress REST API for anyone who can't edit galleries.
 *
 * Galleries stay "publish" whatever their access setting (password, private, email, account,
 * expiry, Direct URL), because that setting lives in post meta. To WordPress that means every
 * gallery is public, and so are its photos (attachments take their read access from the parent)
 * and the comments on them. The gallery post type keeps show_in_rest for the block editor, so
 * these filters hide that content from the REST API instead.
 */

/**
 * SQL subquery listing the IDs of every photo that belongs to a gallery.
 *
 * @return string
 */
function sunshine_gallery_image_ids_sql() {
	global $wpdb;
	return "SELECT sunshine_images.ID FROM {$wpdb->posts} AS sunshine_images INNER JOIN {$wpdb->posts} AS sunshine_galleries ON sunshine_images.post_parent = sunshine_galleries.ID WHERE sunshine_images.post_type = 'attachment' AND sunshine_galleries.post_type = 'sunshine-gallery'";
}

/**
 * SQL condition, for a comments query, that leaves out order logs and comments on gallery photos.
 *
 * @return string
 */
function sunshine_hidden_comments_where_sql() {
	global $wpdb;
	return "{$wpdb->comments}.comment_post_ID NOT IN ( SELECT sunshine_orders.ID FROM {$wpdb->posts} AS sunshine_orders WHERE sunshine_orders.post_type = 'sunshine-order' ) AND {$wpdb->comments}.comment_post_ID NOT IN ( " . sunshine_gallery_image_ids_sql() . ' )';
}

function sunshine_rest_can_see_galleries() {
	return current_user_can( 'edit_sunshine_galleries' );
}

function sunshine_rest_is_gallery_image( $attachment_id ) {
	$parent_id = wp_get_post_parent_id( $attachment_id );
	return $parent_id && get_post_type( $parent_id ) === 'sunshine-gallery';
}

function sunshine_rest_forbidden() {
	return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'sunshine-photo-cart' ), array( 'status' => rest_authorization_required_code() ) );
}

// Gallery lists.
add_filter( 'rest_sunshine-gallery_query', 'sunshine_rest_gallery_query' );
function sunshine_rest_gallery_query( $args ) {
	if ( ! sunshine_rest_can_see_galleries() ) {
		$args['post__in'] = array( 0 );
	}
	return $args;
}

// A single gallery or gallery photo, refused before the request runs.
add_filter( 'rest_request_before_callbacks', 'sunshine_rest_single_item_access', 10, 3 );
function sunshine_rest_single_item_access( $response, $handler, $request ) {
	if ( is_wp_error( $response ) || empty( $request['id'] ) || empty( $handler['callback'] ) || ! is_array( $handler['callback'] ) ) {
		return $response;
	}
	if ( ! ( $handler['callback'][0] instanceof WP_REST_Posts_Controller ) ) {
		return $response;
	}
	$post = get_post( (int) $request['id'] );
	if ( ! $post ) {
		return $response;
	}
	if ( 'sunshine-gallery' === $post->post_type && ! current_user_can( 'edit_post', $post->ID ) ) {
		return sunshine_rest_forbidden();
	}
	if ( 'attachment' === $post->post_type && sunshine_rest_is_gallery_image( $post->ID ) && ! current_user_can( 'edit_post', $post->post_parent ) ) {
		return sunshine_rest_forbidden();
	}
	return $response;
}

// Site search.
add_filter( 'rest_post_search_query', 'sunshine_rest_search_query' );
function sunshine_rest_search_query( $args ) {
	if ( sunshine_rest_can_see_galleries() || empty( $args['post_type'] ) ) {
		return $args;
	}
	$post_types = array_diff( (array) $args['post_type'], array( 'sunshine-gallery' ) );
	if ( empty( $post_types ) ) {
		$args['post__in'] = array( 0 );
	} else {
		$args['post_type'] = array_values( $post_types );
	}
	return $args;
}

// Media lists.
add_filter( 'rest_attachment_query', 'sunshine_rest_attachment_query' );
function sunshine_rest_attachment_query( $args ) {
	if ( ! sunshine_rest_can_see_galleries() ) {
		$args['sunshine_hide_gallery_images'] = true;
	}
	return $args;
}

add_filter( 'posts_where', 'sunshine_rest_attachment_where', 10, 2 );
function sunshine_rest_attachment_where( $where, $query ) {
	global $wpdb;
	if ( $query->get( 'sunshine_hide_gallery_images' ) ) {
		$where .= " AND {$wpdb->posts}.ID NOT IN ( " . sunshine_gallery_image_ids_sql() . ' )';
	}
	return $where;
}

// Comment lists.
add_filter( 'rest_comment_query', 'sunshine_rest_comment_query' );
function sunshine_rest_comment_query( $args ) {
	if ( ! sunshine_rest_can_see_galleries() ) {
		$args['sunshine_hide_comments'] = true;
	}
	return $args;
}

add_filter( 'comments_clauses', 'sunshine_rest_comments_clauses', 10, 2 );
function sunshine_rest_comments_clauses( $clauses, $query ) {
	if ( ! empty( $query->query_vars['sunshine_hide_comments'] ) ) {
		$clauses['where'] .= ( $clauses['where'] ? ' AND ' : '' ) . sunshine_hidden_comments_where_sql();
	}
	return $clauses;
}

// A single comment.
add_filter( 'rest_prepare_comment', 'sunshine_rest_prepare_comment', 10, 2 );
function sunshine_rest_prepare_comment( $response, $comment ) {
	$post_id = (int) $comment->comment_post_ID;
	if ( get_post_type( $post_id ) === 'sunshine-order' && ! current_user_can( 'edit_post', $post_id ) ) {
		return sunshine_rest_forbidden();
	}
	if ( sunshine_rest_is_gallery_image( $post_id ) && ! current_user_can( 'edit_post', wp_get_post_parent_id( $post_id ) ) ) {
		return sunshine_rest_forbidden();
	}
	return $response;
}
