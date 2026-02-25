<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$uninstall = get_option( 'sunshine_uninstall_delete_data' );

if ( $uninstall ) {

	global $wpdb, $current_user;

	// $galleries = $wpdb->query( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'sunshine-gallery';" );
	// sunshine_log( $galleries, 'GALLERIES DURING DELETE' );

	// Remove settings.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sunshine_%'" );
	// Remove user meta data.
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'sunshine_%'" );

	// Remove pages
	$pages = array(
		get_option( 'sunshine_page' ),
		get_option( 'sunshine_page_cart' ),
		get_option( 'sunshine_page_checkout' ),
		get_option( 'sunshine_page_account' ),
		get_option( 'sunshine_page_favorites' ),
	);
	foreach ( $pages as $page_id ) {
		if ( ! empty( $page_id ) ) {
			wp_delete_post( $page_id, true );
		}
	}

	// Get all galleries for use in deleting attachments.
	$galleries = $wpdb->query( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'sunshine-gallery';" );

	// Remove post type data.
	$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( 'sunshine-product', 'sunshine-gallery', 'sunshine-order', 'sunshine-prodcut-opt' );" );
	// Delete all meta data that is not assigned anymore.
	$wpdb->query( "DELETE meta FROM {$wpdb->postmeta} meta LEFT JOIN {$wpdb->posts} posts ON posts.ID = meta.post_id WHERE posts.ID IS NULL;" );

	// Remove taxonomy data
	foreach ( array( 'sunshine-product-category', 'sunshine-product-price-level', 'sunshine-order-status' ) as $taxonomy ) {
		$wpdb->delete(
			$wpdb->term_taxonomy,
			array(
				'taxonomy' => $taxonomy,
			)
		);
	}

	// Remove session table.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sunshine_sessions" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sunshine_order_items" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sunshine_order_itemmeta" );

	// Delete all media attachments.
	if ( !empty( $galleries ) ) {

		$gallery_ids = array();
		foreach ( $galleries as $gallery_id ) {
			$gallery_ids[] = $gallery_id;
		}

		// Build single query to delete all attachment posts
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type='attachment' AND post_parent IN ( " . join( ',', $gallery_ids ) . " );" );

		// Clear all unattached meta data query
		$wpdb->query( "DELETE meta FROM {$wpdb->postmeta} meta LEFT JOIN {$wpdb->posts} posts ON posts.ID = meta.post_id WHERE posts.ID IS NULL;" );

		// Delete files from server
		$upload_dir = wp_upload_dir();
		$folder = $upload_dir['basedir'] . '/sunshine';

		sunshine_uninstall_folder( $folder );

		// Check if the path exists and is a directory
	    if (is_dir($folderPath)) {
	        // Get a list of all the files and directories inside the folder
	        $files = array_diff(scandir($folderPath), array('.', '..'));

	        // Loop through the files and directories
	        foreach ($files as $file) {
	            // If the file is a directory, recursively call this function
	            if (is_dir("$folderPath/$file")) {
	                deleteFolder("$folderPath/$file");
	            } else {
	                // If the file is a file, delete it
	                unlink("$folderPath/$file");
	            }
	        }

	        // Once all the files have been deleted, delete the folder itself
	        rmdir($folderPath);
	    }

	}

	do_action( 'sunshine_uninstall' );

}

function sunshine_uninstall_folder($folderPath) {
    // Check if the path exists and is a directory
    if (is_dir($folderPath)) {
        // Get a list of all the files and directories inside the folder
        $files = array_diff(scandir($folderPath), array('.', '..'));

        // Loop through the files and directories
        foreach ($files as $file) {
            // If the file is a directory, recursively call this function
            if (is_dir("$folderPath/$file")) {
                sunshine_uninstall_folder("$folderPath/$file");
            } else {
                // If the file is a file, delete it
                unlink("$folderPath/$file");
            }
        }

        // Once all the files have been deleted, delete the folder itself
        rmdir($folderPath);
    }
}


/*
 TODO: Not ready yet
// Remove attachments
if ( $options['uninstall_delete_attachments'] ) {

	if ( !empty( $galleries ) ) {

		$gallery_ids = array();
		foreach ( $galleries as $gallery_id ) {
			$gallery_ids[] = $gallery_id;
		}

		// Build single query to delete all attachment posts
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type='attachment' AND post_parent IN ( " . join( ',', $gallery_ids ) . " );" );

		// Clear all unattached meta data query
		$wpdb->query( "DELETE meta FROM {$wpdb->postmeta} meta LEFT JOIN {$wpdb->posts} posts ON posts.ID = meta.post_id WHERE posts.ID IS NULL;" );

		// Delete files from server
		$upload_dir = wp_upload_dir();
		$folder = $upload_dir['basedir'] . '/sunshine/*';
		array_map( 'unlink', array_filter( (array) glob( $folder ) ) );

	}

}
*/

wp_cache_flush();
