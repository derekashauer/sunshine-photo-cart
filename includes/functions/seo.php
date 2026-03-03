<?php
/**
 * Help Sunshine work with SEO plugins
 *
 * @package SunshinePhotoCart\Functions
 * @version 3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filter out sunshine galleries from being in the XML site map
 *
 * @since  3.0.16
 * @return boolean If sunshine-gallery post type should be excluded
 */
add_filter( 'wpseo_sitemap_exclude_post_type', 'sunshine_yoast_sitemap_exclude_post_type', 10, 2 );
function sunshine_yoast_sitemap_exclude_post_type( $excluded, $post_type ) {
	if ( 'sunshine-gallery' === $post_type && SPC()->get_option( 'hide_galleries' ) ) {
		return true;
	}
	return $excluded;
}

/**
 * Alter the OpenGraph image for a gallery
 */
function sunshine_yoast_alter_existing_opengraph_image( $url ) {
	global $post;
	if ( is_singular( 'sunshine-gallery' ) ) {
		$featured_image_id = SPC()->frontend->current_gallery->get_featured_image_id();
		if ( $featured_image_id ) {
			$image = wp_get_attachment_image_src( $featured_image_id, 'sunshine-large' );
			$url   = $image[0];
		}
	} elseif ( is_attachment() ) {
		if ( get_post_type( $post->post_parent ) == 'sunshine-gallery' ) {
			$image = wp_get_attachment_image_src( $post->ID, 'sunshine-large' );
			$url   = $image[0];
		}
	}
	return $url;
}
add_filter( 'wpseo_opengraph_image', 'sunshine_yoast_alter_existing_opengraph_image', 999 );

/**
 * Alter the OpenGraph image height for a single post type.
 */
add_filter( 'wpseo_opengraph_image_width', 'sunshine_yoast_change_opengraph_image_width' );
function sunshine_yoast_change_opengraph_image_width( $width ) {
	global $post;
	if ( is_singular( 'sunshine-gallery' ) ) {
		$featured_image_id = SPC()->frontend->current_gallery->get_featured_image_id();
		if ( $featured_image_id ) {
			$image = wp_get_attachment_image_src( $featured_image_id, 'sunshine-large' );
			$width = $image[1];
		}
	} elseif ( is_attachment() ) {
		if ( get_post_type( $post->post_parent ) == 'sunshine-gallery' ) {
			$image = wp_get_attachment_image_src( $post->ID, 'sunshine-large' );
			$width = $image[1];
		}
	}
	return $width;
}
/**
 * Alter the OpenGraph image height for a single post type.
 */
add_filter( 'wpseo_opengraph_image_height', 'sunshine_yoast_change_opengraph_image_height' );
function sunshine_yoast_change_opengraph_image_height( $height ) {
	global $post;
	if ( is_singular( 'sunshine-gallery' ) ) {
		$featured_image_id = SPC()->frontend->current_gallery->get_featured_image_id();
		if ( $featured_image_id ) {
			$image  = wp_get_attachment_image_src( $featured_image_id, 'sunshine-large' );
			$height = $image[2];
		}
	} elseif ( is_attachment() ) {
		if ( get_post_type( $post->post_parent ) == 'sunshine-gallery' ) {
			$image  = wp_get_attachment_image_src( $post->ID, 'sunshine-large' );
			$height = $image[2];
		}
	}
	return $height;
}


/**
 * Prevent Yoast SEO from redirecting Sunshine gallery image attachment pages
 *
 * Yoast disables media pages by default, redirecting attachment URLs.
 * Returning empty prevents the redirect for Sunshine gallery images.
 *
 * @since 3.x
 * @param string $url        The attachment redirect URL.
 * @param object $attachment The attachment post object.
 * @return string Empty string to prevent redirect, or original URL.
 */
add_filter( 'wpseo_attachment_redirect_url', 'sunshine_yoast_prevent_image_redirect', 10, 2 );
function sunshine_yoast_prevent_image_redirect( $url, $attachment ) {
	if ( ! empty( $attachment->post_parent ) && get_post_type( $attachment->post_parent ) === 'sunshine-gallery' ) {
		return '';
	}
	return $url;
}

/**
 * Prevent Rank Math from redirecting Sunshine gallery image attachment pages
 *
 * Rank Math redirects attachments to parent posts by default.
 * Returning empty prevents the redirect for Sunshine gallery images.
 *
 * @since 3.x
 * @param string $redirect The calculated redirect URL.
 * @return string Empty string to prevent redirect, or original URL.
 */
add_filter( 'rank_math/frontend/attachment/redirect_url', 'sunshine_rank_math_prevent_image_redirect' );
function sunshine_rank_math_prevent_image_redirect( $redirect ) {
	if ( is_attachment() ) {
		$attachment = get_queried_object();
		if ( ! empty( $attachment->post_parent ) && get_post_type( $attachment->post_parent ) === 'sunshine-gallery' ) {
			return '';
		}
	}
	return $redirect;
}

/**
 * Function to query and get IDs of galleries to be excluded from XML sitemaps
 *
 * @since  3.0.0
 * @return array IDs of galleries to be excluded
 */
function sunshine_get_seo_sitemap_exclude_ids() {
	$exclude_galleries = array();
	$args              = array(
		'meta_query' => array(
			'relation' => 'OR',
			array(
				'key'     => 'status',
				'value'   => 'default',
				'compare' => '!=',
			),
			array(
				'key'   => 'access_type',
				'value' => 'url',
			),
		),
	);
	$galleries         = sunshine_get_galleries( $args, 'all' );
	if ( ! empty( $galleries ) ) {
		foreach ( $galleries as $gallery ) {
			$exclude_galleries[] = $gallery->get_id();
		}
	}
	return $exclude_galleries;
}


/**
 * Hide necessary galleries from Yoast SEO XML Sitemap
 *
 * @since  3.0.0
 * @return array IDs of galleries to be excluded
 */
function sunshine_wpseo_exclude_galleries() {
	return sunshine_get_seo_sitemap_exclude_ids();
}
add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', 'sunshine_wpseo_exclude_galleries' );

/**
 * Hide necessary galleries from All in One SEO XML Sitemap
 *
 * @since  3.0.0
 * @param array  $ids IDs to be excluded.
 * @param string $type Type of sitemap.
 * @return array IDs of galleries to be excluded
 */
function sunshine_aioseo_sitemap_exclude_posts( $ids, $type ) {
	$ids = array_merge( $ids, sunshine_get_seo_sitemap_exclude_ids() );
	return $ids;
}
add_filter( 'aioseo_sitemap_exclude_posts', 'sunshine_aioseo_sitemap_exclude_posts', 10, 2 );

/**
 * Hide necessary galleries from Rank Math XML Sitemap
 *
 * @since  3.0.0
 * @param string  $url URL String.
 * @param string  $type URL type. Can be user, post or term.
 * @param WP_Post $object Post object.
 * @return bool|string Return false if it is to be excluded, or URL if it is OK
 */
function sunshine_rank_math_entry( $url, $type, $object ) {
	if ( 'sunshine-gallery' == $object->post_type ) {
		$gallery = sunshine_get_gallery( $object );
		if ( $gallery->get_status() != 'default' || $gallery->get_access_type() == 'url' ) {
			return false;
		}
	}
	return $url;
}
add_filter( 'rank_math/sitemap/entry', 'sunshine_rank_math_entry', 10, 3 );

/**
 * Hide necessary galleries from SEO Press XML Sitemap
 *
 * @since 3.0.0
 * @param string  $url URL String.
 * @param WP_Post $post Post object.
 * @return bool|string Return false if it is to be excluded, or URL if it is OK
 */
function sunshine_seopress_sitemaps_single_url( $url, $post ) {
	if ( 'sunshine-gallery' == $post->post_type ) {
		$gallery = sunshine_get_gallery( $post );
		if ( 'default' != $gallery->get_status() || 'url' == $gallery->get_access_type() ) {
			return false;
		}
	}
	return $url;
}
add_filter( 'seopress_sitemaps_single_url', 'sunshine_seopress_sitemaps_single_url', 10, 2 );
