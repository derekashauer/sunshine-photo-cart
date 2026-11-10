<?php
/**
 * Which images get Sunshine's gallery sizes (sunshine-thumbnail, sunshine-large).
 *
 * The business rule: gallery photos always need them, because that's what customers see.
 * Images that belong to a shop product, page or post never show at those sizes, so making them
 * only fills the server (one store had hundreds of MB of them on its shop photos). An image that
 * is picked as a gallery's featured image does show at them, wherever it came from.
 */

$t = 'SPC_Totals_Test';

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/**
 * A new image, 2000 x 1500 so every size has room to be made, added to the Media Library under
 * a parent post (0 for none), the way an upload from that post's edit screen is.
 */
$sizes_upload = function ( $parent_id ) use ( $t ) {
	$file  = wp_tempnam( 'spc-sizes.jpg' );
	$image = imagecreatetruecolor( 2000, 1500 );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 120, 160, 200 ) );
	imagejpeg( $image, $file );
	imagedestroy( $image );

	$upload = array(
		'name'     => 'spc-sizes-' . wp_rand( 100000, 999999 ) . '.jpg',
		'tmp_name' => $file,
	);
	$id     = media_handle_sideload( $upload, $parent_id );
	if ( is_wp_error( $id ) ) {
		throw new Exception( 'Could not add the image: ' . $id->get_error_message() );
	}
	$t::track( 'post', $id );
	return $id;
};

$sizes_post = function ( $post_type ) use ( $t ) {
	$id = wp_insert_post(
		array(
			'post_type'   => $post_type,
			'post_title'  => 'Totals sizes ' . $post_type,
			'post_status' => 'publish',
		)
	);
	$t::track( 'post', $id );
	return $id;
};

// Which of these sizes the image has, both in its metadata and as a file on disk.
$sizes_has = function ( $attachment_id, $size_names ) {
	$metadata = wp_get_attachment_metadata( $attachment_id );
	$dir      = dirname( get_attached_file( $attachment_id ) );
	$has      = array();
	foreach ( $size_names as $size_name ) {
		$file = $metadata['sizes'][ $size_name ]['file'] ?? '';
		if ( $file && file_exists( $dir . '/' . $file ) ) {
			$has[] = $size_name;
		}
	}
	return $has;
};

$sunshine_sizes = array( 'sunshine-thumbnail', 'sunshine-large' );

$t::test(
	'An image added to a page gets WordPress sizes but not Sunshine gallery sizes',
	function () use ( $t, $sizes_upload, $sizes_post, $sizes_has, $sunshine_sizes ) {
		$image_id = $sizes_upload( $sizes_post( 'page' ) );
		$t::same( array(), $sizes_has( $image_id, $sunshine_sizes ), 'Sunshine sizes on a page image' );
		$t::same( array( 'medium', 'large' ), $sizes_has( $image_id, array( 'medium', 'large' ) ), 'WordPress sizes on a page image' );
	}
);

$t::test(
	'Gallery photos and unattached images keep Sunshine gallery sizes',
	function () use ( $t, $sizes_upload, $sizes_post, $sizes_has, $sunshine_sizes ) {
		$gallery_image = $sizes_upload( $sizes_post( 'sunshine-gallery' ) );
		$t::same( $sunshine_sizes, $sizes_has( $gallery_image, $sunshine_sizes ), 'Sunshine sizes on a gallery photo' );

		// Unattached images can still be picked for a gallery or a Sunshine setting later.
		$loose_image = $sizes_upload( 0 );
		$t::same( $sunshine_sizes, $sizes_has( $loose_image, $sunshine_sizes ), 'Sunshine sizes on an unattached image' );
	}
);

$t::test(
	'Sizes WordPress makes later for a page image, after a timed-out upload, skip Sunshine sizes too',
	function () use ( $t, $sizes_upload, $sizes_post, $sizes_has, $sunshine_sizes ) {
		$image_id = $sizes_upload( $sizes_post( 'page' ) );
		$metadata = wp_get_attachment_metadata( $image_id );
		$metadata['sizes'] = array();
		wp_update_attachment_metadata( $image_id, $metadata );

		wp_update_image_subsizes( $image_id );
		$t::same( array(), $sizes_has( $image_id, $sunshine_sizes ), 'Sunshine sizes after the retry' );
		$t::same( array( 'medium' ), $sizes_has( $image_id, array( 'medium' ) ), 'WordPress sizes after the retry' );
	}
);

$t::test(
	'A page image picked as a gallery featured image gets Sunshine sizes, and keeps them when regenerated',
	function () use ( $t, $sizes_upload, $sizes_post, $sizes_has, $sunshine_sizes ) {
		$image_id   = $sizes_upload( $sizes_post( 'page' ) );
		$gallery_id = $sizes_post( 'sunshine-gallery' );

		set_post_thumbnail( $gallery_id, $image_id );
		$t::same( $sunshine_sizes, $sizes_has( $image_id, $sunshine_sizes ), 'Sunshine sizes once featured' );

		// Regenerating thumbnails (WP-CLI or a plugin) makes every size again from scratch.
		wp_update_attachment_metadata( $image_id, wp_create_image_subsizes( get_attached_file( $image_id ), $image_id ) );
		$t::same( $sunshine_sizes, $sizes_has( $image_id, $sunshine_sizes ), 'Sunshine sizes after regenerating' );
	}
);

$t::test(
	'Picking a gallery photo that is still waiting to be processed as featured does not make unwatermarked sizes',
	function () use ( $t, $sizes_upload, $sizes_post, $sizes_has, $sunshine_sizes ) {
		$gallery_id = $sizes_post( 'sunshine-gallery' );
		$image_id   = $sizes_upload( $gallery_id );

		// With Delay image processing on, a new photo has no sizes until the background job makes them.
		$metadata          = wp_get_attachment_metadata( $image_id );
		$waiting           = $metadata;
		$waiting['sizes']  = array();
		wp_update_attachment_metadata( $image_id, $waiting );

		set_post_thumbnail( $gallery_id, $image_id );
		$t::same( array(), array_keys( wp_get_attachment_metadata( $image_id )['sizes'] ), 'Sizes made outside processing' );

		// Put the sizes back so deleting the photo at the end also deletes their files.
		wp_update_attachment_metadata( $image_id, $metadata );
	}
);

$t::test(
	'An add-on size named like a WordPress size is never taken off page images',
	function () use ( $t, $sizes_upload, $sizes_post, $sizes_has ) {
		// A Digital Download product named "Medium" registers a size called "medium".
		$add_medium = function ( $sizes ) {
			$sizes[] = 'medium';
			return $sizes;
		};
		add_filter( 'sunshine_gallery_only_image_sizes', $add_medium );
		try {
			$image_id = $sizes_upload( $sizes_post( 'page' ) );
		} finally {
			remove_filter( 'sunshine_gallery_only_image_sizes', $add_medium );
		}
		$t::same( array( 'medium' ), $sizes_has( $image_id, array( 'medium' ) ), 'WordPress medium size on a page image' );
	}
);
