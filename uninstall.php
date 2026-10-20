<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$uninstall = get_option( 'sunshine_uninstall_delete_data' );

if ( $uninstall ) {

	global $wpdb, $current_user;

	// $galleries = $wpdb->query( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'sunshine-gallery';" );
	// sunshine_log( $galleries, 'GALLERIES DURING DELETE' );

	// Other WP Sunshine plugins also start their settings with "sunshine_", so leave theirs alone.
	$keep_prefixes = array( 'sunshine_analytics', 'sunshine_consent', 'sunshine_utm' );
	$keep_options  = '';
	$keep_usermeta = '';
	foreach ( $keep_prefixes as $keep_prefix ) {
		$keep_options  .= $wpdb->prepare( ' AND option_name NOT LIKE %s', $wpdb->esc_like( $keep_prefix ) . '%' );
		$keep_usermeta .= $wpdb->prepare( ' AND meta_key NOT LIKE %s', $wpdb->esc_like( $keep_prefix ) . '%' );
	}

	// Remove pages. Their IDs are settings, so this has to happen before the settings go.
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

	// Remove settings.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'sunshine_' ) . '%' ) . $keep_options );
	// Remove user meta data.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'sunshine_' ) . '%' ) . $keep_usermeta );

	// Get all galleries for use in deleting attachments.
	$galleries = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'sunshine-gallery';" );

	// Remove post type data.
	$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( 'sunshine-product', 'sunshine-gallery', 'sunshine-order', 'sunshine-prodcut-opt' );" );
	// Delete all meta data that is not assigned anymore.
	$wpdb->query( "DELETE meta FROM {$wpdb->postmeta} meta LEFT JOIN {$wpdb->posts} posts ON posts.ID = meta.post_id WHERE posts.ID IS NULL;" );

	// Remove taxonomy data
	foreach ( array( 'sunshine-product-category', 'sunshine-product-price-level', 'sunshine-order-status' ) as $taxonomy ) {
		delete_option( $taxonomy . '_children' );
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
			$gallery_ids[] = intval( $gallery_id );
		}

		// Build single query to delete all attachment posts
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type='attachment' AND post_parent IN ( " . join( ',', $gallery_ids ) . " );" );

		// Clear all unattached meta data query
		$wpdb->query( "DELETE meta FROM {$wpdb->postmeta} meta LEFT JOIN {$wpdb->posts} posts ON posts.ID = meta.post_id WHERE posts.ID IS NULL;" );

		// Delete files from server
		$upload_dir = wp_upload_dir();
		$folder = $upload_dir['basedir'] . '/sunshine';

		sunshine_uninstall_folder( $folder );

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
