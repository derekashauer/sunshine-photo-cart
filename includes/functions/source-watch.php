<?php
/**
 * Check the folders galleries were imported from for photos added later.
 *
 * Volume photographers fill thousands of galleries from FTP or cloud folders, and
 * photos keep arriving after the import: a late upload, or one that stopped part
 * way. With this turned on, Sunshine looks at those folders on a schedule and
 * brings in the photos it finds.
 *
 * Only galleries filled from a folder are watched (they have images_directory).
 * Galleries filled by browser upload never are.
 *
 * Kept cheap enough for a small server that watches every gallery:
 * - An FTP folder is only listed when its "last changed" time has moved, which is
 *   one quick check per folder.
 * - Cloud folders are checked by Cloud Storage through the
 *   sunshine_source_watch_check filter.
 * - Every run stops when the import batch time is used up, saves where it got
 *   to, and carries on a minute later.
 * - Finding photos and bringing them in are separate steps, so a big late upload
 *   comes in a batch at a time and the site never stalls.
 *
 * Only ever adds photos. A photo removed from the folder stays in the gallery, and
 * a photo removed from the gallery is never brought back: every file a gallery has
 * seen is remembered in its sunshine_source_seen meta.
 *
 * @package SunshinePhotoCart
 */

defined( 'ABSPATH' ) || exit;

/**
 * How often folders are checked, or empty when it is turned off.
 *
 * @return string A WordPress schedule name, or ''.
 */
function sunshine_source_watch_frequency() {
	$frequency = SPC()->get_option( 'source_watch' );
	return in_array( $frequency, array( 'hourly', 'twicedaily', 'daily' ), true ) ? $frequency : '';
}

/**
 * Keep the scheduled check in step with the setting.
 */
add_action( 'add_option_sunshine_source_watch', 'sunshine_source_watch_schedule', 10, 0 );
add_action( 'update_option_sunshine_source_watch', 'sunshine_source_watch_schedule', 10, 0 );
add_action( 'admin_init', 'sunshine_source_watch_schedule', 10, 0 );
function sunshine_source_watch_schedule() {
	$frequency = sunshine_source_watch_frequency();
	$event     = wp_get_scheduled_event( 'sunshine_source_watch' );

	if ( '' === $frequency ) {
		if ( $event ) {
			wp_clear_scheduled_hook( 'sunshine_source_watch' );
		}
		wp_clear_scheduled_hook( 'sunshine_source_watch_continue' );
		return;
	}

	// Galleries made before this moment were imported however they were, and some
	// of their photos may have been removed on purpose since. Those galleries are
	// taken as they are the first time they are checked, rather than brought up to
	// date with their folder.
	if ( ! get_option( 'sunshine_source_watch_since' ) ) {
		update_option( 'sunshine_source_watch_since', time(), false );
	}

	if ( $event && $event->schedule === $frequency ) {
		return;
	}

	wp_clear_scheduled_hook( 'sunshine_source_watch' );
	wp_schedule_event( time() + MINUTE_IN_SECONDS, $frequency, 'sunshine_source_watch' );
}

/**
 * One scheduled run: bring in photos already found, then look for more.
 */
add_action( 'sunshine_source_watch', 'sunshine_source_watch_run' );
add_action( 'sunshine_source_watch_continue', 'sunshine_source_watch_run' );
function sunshine_source_watch_run() {
	if ( '' === sunshine_source_watch_frequency() ) {
		sunshine_source_watch_schedule();
		return;
	}

	if ( ! sunshine_import_lock( 'source_watch' ) ) {
		return;
	}

	$batch = sunshine_import_batch_start();

	// Only the scheduled check starts looking through every folder again. A run
	// that carries on a minute later just finishes what is under way.
	$start = ( 'sunshine_source_watch' === current_action() );

	// Photos already found come first. Looking for more is only worth it once
	// they are in.
	$more = sunshine_source_watch_import_queue( $batch );

	if ( ! $more ) {
		$more = ! sunshine_source_watch_check_ftp( $batch, $start );

		/**
		 * Check other kinds of source folder, such as cloud buckets.
		 *
		 * Use sunshine_import_batch_has_time( $batch ) to stop in time, and
		 * sunshine_source_watch_check_gallery() to queue what a folder holds.
		 *
		 * @param bool  $more  Whether work is left for another run.
		 * @param array $batch Time used so far in this run.
		 * @param bool  $start Whether to start a new check, or only finish one under way.
		 */
		$more = (bool) apply_filters_ref_array( 'sunshine_source_watch_check', array( $more, &$batch, $start ) );

		// Photos found in this run start coming in straight away.
		if ( sunshine_import_batch_has_time( $batch ) ) {
			$more = sunshine_source_watch_import_queue( $batch ) || $more;
		} elseif ( get_option( 'sunshine_source_watch_queue' ) ) {
			$more = true;
		}
	}

	// Carry on in a minute rather than waiting for the next scheduled check.
	if ( $more && ! wp_next_scheduled( 'sunshine_source_watch_continue' ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'sunshine_source_watch_continue' );
	}
}

/**
 * Every watched gallery, a chunk at a time.
 *
 * @param int $after_id Start after this gallery.
 * @param int $limit    How many to return.
 * @return array Keyed by gallery id: directory, type (ftp or cloud), location, state, created.
 */
function sunshine_source_watch_galleries( $after_id = 0, $limit = 200 ) {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_date_gmt FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'images_directory' AND m.meta_value <> ''
			 WHERE p.post_type = 'sunshine-gallery' AND p.post_status NOT IN ( 'trash', 'auto-draft' ) AND p.ID > %d
			 ORDER BY p.ID ASC LIMIT %d",
			$after_id,
			$limit
		)
	);

	if ( empty( $rows ) ) {
		return array();
	}

	$galleries = array();
	foreach ( $rows as $row ) {
		$galleries[ (int) $row->ID ] = array(
			'directory' => '',
			'type'      => 'ftp',
			'location'  => array(),
			'state'     => array(),
			'end_date'  => '',
			'created'   => (int) strtotime( $row->post_date_gmt . ' UTC' ),
		);
	}

	// Everything else needed, in one query. Bulk Galleries copies a template
	// gallery's meta onto each new gallery after recording its folder, so a
	// gallery can have a second images_directory row. The first one is its own.
	$ids  = implode( ',', array_keys( $galleries ) );
	$meta = $wpdb->get_results(
		"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
		 WHERE post_id IN ($ids) AND meta_key IN ( 'images_directory', 'sunshine_source_location', 'end_date', 'sunshine_source_watch' )
		 ORDER BY meta_id ASC"
	);

	$found = array();
	foreach ( $meta as $row ) {
		$id = (int) $row->post_id;
		if ( isset( $found[ $id ][ $row->meta_key ] ) ) {
			continue;
		}
		$found[ $id ][ $row->meta_key ] = true;

		switch ( $row->meta_key ) {
			case 'images_directory':
				$galleries[ $id ]['directory'] = (string) $row->meta_value;
				break;
			case 'sunshine_source_location':
				$location                     = maybe_unserialize( $row->meta_value );
				$galleries[ $id ]['location'] = is_array( $location ) ? $location : array();
				if ( ! empty( $location['type'] ) && 'cloud' === $location['type'] ) {
					$galleries[ $id ]['type'] = 'cloud';
				}
				break;
			case 'end_date':
				$galleries[ $id ]['end_date'] = $row->meta_value;
				break;
			case 'sunshine_source_watch':
				$state                     = maybe_unserialize( $row->meta_value );
				$galleries[ $id ]['state'] = is_array( $state ) ? $state : array();
				break;
		}
	}

	// Customers can't see an expired gallery, so there is no reason to fill it.
	$now = current_time( 'timestamp' );
	foreach ( $galleries as $id => $gallery ) {
		if ( is_numeric( $gallery['end_date'] ) && $gallery['end_date'] < $now ) {
			$galleries[ $id ]['expired'] = true;
		}
	}

	return $galleries;
}

/**
 * Look at FTP folders whose "last changed" time has moved.
 *
 * @param array $batch Time used so far in this run.
 * @param bool  $start Whether to start a new check, or only finish one under way.
 * @return bool Whether every watched FTP folder was looked at.
 */
function sunshine_source_watch_check_ftp( &$batch, $start = true ) {
	$cursor = (int) get_option( 'sunshine_source_watch_ftp_cursor', 0 );
	if ( ! $start && 0 === $cursor ) {
		return true;
	}

	while ( true ) {
		$galleries = sunshine_source_watch_galleries( $cursor );

		if ( empty( $galleries ) ) {
			update_option( 'sunshine_source_watch_ftp_cursor', 0, false );
			return true;
		}

		foreach ( $galleries as $gallery_id => $gallery ) {
			$cursor = $gallery_id;

			if ( 'ftp' !== $gallery['type'] || ! empty( $gallery['expired'] ) ) {
				continue;
			}

			sunshine_source_watch_check_ftp_gallery( $gallery_id, $gallery );

			if ( ! sunshine_import_batch_has_time( $batch ) ) {
				update_option( 'sunshine_source_watch_ftp_cursor', $cursor, false );
				return false;
			}
		}
	}
}

/**
 * Where a gallery's FTP folder is on disk.
 *
 * The gallery screen saves it relative to the import folder, Bulk Galleries
 * saves the whole path. Either way it has to be inside the import folder.
 *
 * @param string $directory Saved images_directory.
 * @return string|false Folder, or false when it is gone or outside the import folder.
 */
function sunshine_source_watch_ftp_folder( $directory ) {
	$base      = untrailingslashit( wp_normalize_path( sunshine_get_import_directory() ) );
	$directory = wp_normalize_path( (string) $directory );

	if ( 0 === strpos( $directory, $base . '/' ) ) {
		$directory = substr( $directory, strlen( $base ) + 1 );
	}

	if ( '' === trim( $directory, '/' ) ) {
		return false;
	}

	return sunshine_validate_import_subdirectory( $directory );
}

/**
 * Photos sitting directly in a folder.
 *
 * @param string $folder Folder.
 * @return array Full paths.
 */
function sunshine_source_watch_folder_files( $folder ) {
	$names = @scandir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a folder removed between checks is expected.
	if ( empty( $names ) ) {
		return array();
	}

	$extensions = array_map( 'strtolower', sunshine_allowed_file_extensions() );
	$files      = array();

	foreach ( $names as $name ) {
		// Hidden files include the ._ copies Macs leave next to every photo.
		if ( '.' === $name[0] ) {
			continue;
		}
		if ( in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), $extensions, true ) ) {
			$files[] = $folder . '/' . $name;
		}
	}

	return $files;
}

/**
 * Check one gallery's FTP folder.
 *
 * @param int   $gallery_id Gallery.
 * @param array $gallery    From sunshine_source_watch_galleries().
 */
function sunshine_source_watch_check_ftp_gallery( $gallery_id, $gallery ) {
	$state  = $gallery['state'];
	$folder = sunshine_source_watch_ftp_folder( $gallery['directory'] );

	if ( false === $folder ) {
		// Said once, not on every check.
		if ( empty( $state['missing'] ) ) {
			SPC()->log( 'Source watch: the folder for gallery ' . $gallery_id . ' (' . $gallery['directory'] . ') is not there, so it is not being checked' );
			update_post_meta( $gallery_id, 'sunshine_source_watch', array( 'missing' => 1 ) );
		}
		return;
	}

	// Read before listing, so a photo arriving while the folder is listed moves
	// the time again and the folder is looked at next time too.
	clearstatcache( true, $folder );
	$read_at = time();
	$mtime   = (int) @filemtime( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( ! empty( $state['mtime'] ) && (int) $state['mtime'] === $mtime && empty( $state['missing'] ) ) {
		return;
	}

	$files = sunshine_source_watch_folder_files( $folder );

	// The time only counts whole seconds. A photo added later in the same second
	// it was read would leave it unchanged, so a time from that second isn't
	// trusted and the folder is listed again next time.
	if ( sunshine_source_watch_check_gallery( $gallery_id, $files, 'ftp', $gallery ) && $mtime !== $read_at ) {
		update_post_meta( $gallery_id, 'sunshine_source_watch', array( 'mtime' => $mtime ) );
	}
}

/**
 * The name a file is remembered by.
 *
 * FTP photos are matched by file name, as the gallery screen's import does.
 * Cloud photos by their exact file in the bucket.
 *
 * @param string $file   Path, or cloud://key.
 * @param string $source ftp or cloud.
 * @return string
 */
function sunshine_source_watch_file_key( $file, $source ) {
	if ( 'cloud' === $source ) {
		return ltrim( preg_replace( '#^[a-z0-9._-]+://#i', '', (string) $file ), '/' );
	}
	// Stored the way WordPress names the copy, so "photo 1.jpg" matches the photo-1.jpg it became.
	return strtolower( sanitize_file_name( basename( (string) $file ) ) );
}

/**
 * What each of these galleries already holds.
 *
 * One query for every gallery asked about. Two ways of recognising a photo:
 * the bucket file it was imported from, which is exact, and its file name.
 *
 * @param int[] $gallery_ids Galleries.
 * @return array Per gallery: 'sources' and 'names', both keyed for lookup.
 */
function sunshine_source_existing_files( $gallery_ids ) {
	global $wpdb;

	$gallery_ids = array_filter( array_map( 'intval', (array) $gallery_ids ) );
	if ( empty( $gallery_ids ) ) {
		return array();
	}

	$in   = implode( ',', $gallery_ids );
	$rows = $wpdb->get_results(
		"SELECT a.post_parent AS gallery_id, m.meta_key, m.meta_value
		 FROM {$wpdb->posts} a
		 INNER JOIN {$wpdb->postmeta} m ON m.post_id = a.ID AND m.meta_key IN ( '_sunshine_cloud_import_source', 'sunshine_file_name' )
		 WHERE a.post_type = 'attachment' AND a.post_parent IN ($in)"
	);

	$index = array();

	foreach ( (array) $rows as $row ) {
		$gallery_id = (int) $row->gallery_id;
		if ( ! isset( $index[ $gallery_id ] ) ) {
			$index[ $gallery_id ] = array(
				'sources' => array(),
				'names'   => array(),
			);
		}
		if ( '_sunshine_cloud_import_source' === $row->meta_key ) {
			$index[ $gallery_id ]['sources'][ ltrim( (string) $row->meta_value, '/' ) ] = true;
		} else {
			$index[ $gallery_id ]['names'][ strtolower( basename( (string) $row->meta_value ) ) ] = true;
		}
	}

	return $index;
}

/**
 * Whether a gallery already holds a file.
 *
 * @param string $key    From sunshine_source_watch_file_key().
 * @param string $source ftp or cloud.
 * @param array  $held   One gallery's entry from sunshine_source_existing_files().
 * @return bool
 */
function sunshine_source_watch_is_held( $key, $source, $held ) {
	if ( 'cloud' === $source && ! empty( $held['sources'] ) ) {
		return isset( $held['sources'][ $key ] );
	}
	return isset( $held['names'][ sunshine_source_watch_file_key( $key, 'ftp' ) ] );
}

/**
 * Whether photos went into a gallery in the last few minutes.
 *
 * An import that is still running adds a photo every few seconds. Checking its
 * folder at the same time would bring the same photos in twice, so the gallery
 * is left until it has been quiet for a while.
 *
 * @param int   $gallery_id Gallery.
 * @param array $gallery    From sunshine_source_watch_galleries().
 * @return bool
 */
function sunshine_source_watch_recently_filled( $gallery_id, $gallery ) {
	global $wpdb;

	$quiet = 10 * MINUTE_IN_SECONDS;

	if ( ! empty( $gallery['created'] ) && $gallery['created'] > time() - $quiet ) {
		return true;
	}

	$last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(post_date_gmt) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'attachment'", $gallery_id ) );

	return $last && strtotime( $last . ' UTC' ) > time() - $quiet;
}

/**
 * Queue the photos in a folder that its gallery doesn't have yet.
 *
 * Used for FTP folders here and for cloud folders by Cloud Storage.
 *
 * @param int    $gallery_id Gallery.
 * @param array  $files      Every photo in the folder: paths, or cloud://keys.
 * @param string $source     ftp or cloud.
 * @param array  $gallery    From sunshine_source_watch_galleries().
 * @return bool Whether the folder is done with. False means look again next time.
 */
function sunshine_source_watch_check_gallery( $gallery_id, $files, $source, $gallery ) {
	if ( sunshine_source_watch_recently_filled( $gallery_id, $gallery ) ) {
		return false;
	}

	$keys = array();
	foreach ( (array) $files as $file ) {
		$keys[ $file ] = sunshine_source_watch_file_key( $file, $source );
	}

	$seen = get_post_meta( $gallery_id, 'sunshine_source_seen', true );

	if ( ! is_array( $seen ) ) {
		$seen = array();
	}

	// First look at a gallery from before watching started: take it as it is. Its
	// own marker, because photos deleted from it before now are already on the list.
	if ( $gallery['created'] < (int) get_option( 'sunshine_source_watch_since' ) && ! get_post_meta( $gallery_id, 'sunshine_source_baseline', true ) ) {
		update_post_meta( $gallery_id, 'sunshine_source_seen', array_values( array_unique( array_merge( $seen, array_values( $keys ) ) ) ) );
		update_post_meta( $gallery_id, 'sunshine_source_baseline', time() );
		return true;
	}

	$seen    = array_fill_keys( $seen, true );
	$index   = sunshine_source_existing_files( array( $gallery_id ) );
	$held    = isset( $index[ $gallery_id ] ) ? $index[ $gallery_id ] : array();
	$queued  = sunshine_source_watch_queued_keys( $gallery_id );
	$cutoff  = time() - 10 * MINUTE_IN_SECONDS;
	$waiting = false;
	$new     = array();

	foreach ( $keys as $file => $key ) {
		if ( isset( $seen[ $key ] ) || isset( $queued[ $key ] ) || sunshine_source_watch_is_held( $key, $source, $held ) ) {
			continue;
		}

		// A file changed in the last few minutes may still be uploading.
		if ( 'ftp' === $source && (int) @filemtime( $file ) > $cutoff ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$waiting = true;
			continue;
		}

		$new[] = array(
			'gallery_id' => (int) $gallery_id,
			'file'       => $file,
			'source'     => $source,
			'key'        => $key,
		);
	}

	if ( empty( $new ) ) {
		return ! $waiting;
	}

	/**
	 * The photos about to be queued for a gallery.
	 *
	 * Bulk Galleries uses this to leave out pre-made sizes and attach them to
	 * their photo as 'sizes'.
	 *
	 * @param array  $new        Items: gallery_id, file, source, key.
	 * @param array  $files      Every photo in the folder.
	 * @param int    $gallery_id Gallery.
	 */
	$items = (array) apply_filters( 'sunshine_source_watch_new_items', $new, $files, $gallery_id );

	// Files left out are remembered, so they aren't looked at again.
	$dropped = array_diff( wp_list_pluck( $new, 'key' ), wp_list_pluck( $items, 'key' ) );
	if ( $dropped ) {
		sunshine_source_watch_remember( $gallery_id, $dropped );
	}

	if ( $items ) {
		sunshine_source_watch_queue_add( $items );
		SPC()->log( 'Source watch: found ' . count( $items ) . ' new photo(s) for gallery ' . $gallery_id );
	}

	return ! $waiting;
}

/**
 * Add photos to the list waiting to be brought in.
 *
 * @param array $items Items: gallery_id, file, source, key.
 */
function sunshine_source_watch_queue_add( $items ) {
	$queue = get_option( 'sunshine_source_watch_queue', array() );
	$queue = is_array( $queue ) ? $queue : array();

	foreach ( $items as $item ) {
		$queue[ $item['gallery_id'] . '|' . $item['key'] ] = $item;
	}

	update_option( 'sunshine_source_watch_queue', $queue, false );
}

/**
 * Files of a gallery already waiting to be brought in.
 *
 * @param int $gallery_id Gallery.
 * @return array Keys, as keys.
 */
function sunshine_source_watch_queued_keys( $gallery_id ) {
	$queued = array();
	foreach ( (array) get_option( 'sunshine_source_watch_queue', array() ) as $item ) {
		if ( (int) $item['gallery_id'] === (int) $gallery_id ) {
			$queued[ $item['key'] ] = true;
		}
	}
	return $queued;
}

/**
 * Remember files, so they are never brought into the gallery again.
 *
 * @param int   $gallery_id Gallery.
 * @param array $keys       From sunshine_source_watch_file_key().
 */
function sunshine_source_watch_remember( $gallery_id, $keys ) {
	$seen = get_post_meta( $gallery_id, 'sunshine_source_seen', true );
	$seen = is_array( $seen ) ? $seen : array();
	$all  = array_values( array_unique( array_merge( $seen, array_values( (array) $keys ) ) ) );

	if ( count( $all ) !== count( $seen ) ) {
		update_post_meta( $gallery_id, 'sunshine_source_seen', $all );
	}
}

/**
 * A photo deleted from a watched gallery is never brought back from its folder.
 */
add_action( 'delete_attachment', 'sunshine_source_watch_photo_deleted' );
function sunshine_source_watch_photo_deleted( $attachment_id ) {
	$gallery_id = wp_get_post_parent_id( $attachment_id );
	if ( ! $gallery_id || 'sunshine-gallery' !== get_post_type( $gallery_id ) || 'trash' === get_post_status( $gallery_id ) ) {
		return;
	}
	if ( '' === (string) get_post_meta( $gallery_id, 'images_directory', true ) ) {
		return;
	}

	$keys   = array();
	$source = (string) get_post_meta( $attachment_id, '_sunshine_cloud_import_source', true );
	$name   = (string) get_post_meta( $attachment_id, 'sunshine_file_name', true );
	if ( '' !== $source ) {
		$keys[] = sunshine_source_watch_file_key( $source, 'cloud' );
	}
	if ( '' !== $name ) {
		$keys[] = sunshine_source_watch_file_key( $name, 'ftp' );
	}

	if ( $keys ) {
		sunshine_source_watch_remember( $gallery_id, $keys );
	}
}

/**
 * Bring in queued photos until the run's time is up.
 *
 * @param array $batch Time used so far in this run.
 * @return bool Whether photos are still waiting.
 */
function sunshine_source_watch_import_queue( &$batch ) {
	$queue = get_option( 'sunshine_source_watch_queue', array() );
	if ( empty( $queue ) || ! is_array( $queue ) ) {
		return false;
	}

	// The function that adds a photo to a gallery lives with the admin screens.
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once SUNSHINE_PHOTO_CART_PATH . 'includes/admin/sunshine-gallery.php';

	$held    = array();
	$handled = array();
	$added   = array();

	// Keep full size photos as they are and use the Image Quality setting, like
	// every other import into a gallery.
	add_filter( 'big_image_size_threshold', '__return_false', 999 );
	$image_quality = (int) SPC()->get_option( 'image_quality' );
	$set_quality   = function( $quality ) use ( $image_quality ) {
		return $image_quality;
	};
	if ( $image_quality ) {
		add_filter( 'jpeg_quality', $set_quality, 999 );
	}

	foreach ( $queue as $queue_key => $item ) {
		unset( $queue[ $queue_key ] );

		$gallery_id = (int) $item['gallery_id'];

		// The gallery may have been trashed or expired since its photos were queued.
		// Not remembered, and its folder is looked at in full again, so they are found
		// if it comes back.
		$end_date = get_post_meta( $gallery_id, 'end_date', true );
		if ( 'sunshine-gallery' !== get_post_type( $gallery_id ) || 'trash' === get_post_status( $gallery_id ) || ( is_numeric( $end_date ) && $end_date < current_time( 'timestamp' ) ) ) {
			delete_post_meta( $gallery_id, 'sunshine_source_watch' );
			continue;
		}

		$handled[ $gallery_id ][] = $item['key'];

		// Looked at again just before bringing it in, in case an import put it in
		// the gallery since it was queued.
		if ( ! isset( $held[ $gallery_id ] ) ) {
			$index               = sunshine_source_existing_files( array( $gallery_id ) );
			$held[ $gallery_id ] = isset( $index[ $gallery_id ] ) ? $index[ $gallery_id ] : array(
				'sources' => array(),
				'names'   => array(),
			);
		}
		if ( sunshine_source_watch_is_held( $item['key'], $item['source'], $held[ $gallery_id ] ) ) {
			continue;
		}

		$image_id = sunshine_source_watch_import_item( $item );

		if ( is_wp_error( $image_id ) || empty( $image_id ) ) {
			$message = is_wp_error( $image_id ) ? $image_id->get_error_message() : 'unknown error';
			SPC()->log( 'Source watch: could not add ' . $item['file'] . ' to gallery ' . $gallery_id . ': ' . $message );
		} else {
			$held[ $gallery_id ]['names'][ sunshine_source_watch_file_key( $item['key'], 'ftp' ) ] = true;
			if ( 'cloud' === $item['source'] ) {
				$held[ $gallery_id ]['sources'][ $item['key'] ] = true;
			}
			$added[ $gallery_id ] = isset( $added[ $gallery_id ] ) ? $added[ $gallery_id ] + 1 : 1;
		}

		if ( ! sunshine_import_batch_has_time( $batch ) ) {
			break;
		}
	}

	remove_filter( 'big_image_size_threshold', '__return_false', 999 );
	remove_filter( 'jpeg_quality', $set_quality, 999 );

	// Saved once at the end. If this request dies part way, the photos it already
	// brought in are found in their gallery next time and skipped.
	update_option( 'sunshine_source_watch_queue', $queue, false );

	// A photo is remembered whether it made it in or not, so one that can't be
	// added isn't tried on every check.
	foreach ( $handled as $gallery_id => $keys ) {
		sunshine_source_watch_remember( $gallery_id, $keys );
	}

	foreach ( $added as $gallery_id => $count ) {
		$stats = get_post_meta( $gallery_id, 'sunshine_source_watch_added', true );
		$stats = is_array( $stats ) ? $stats : array( 'count' => 0 );
		update_post_meta(
			$gallery_id,
			'sunshine_source_watch_added',
			array(
				'count' => (int) $stats['count'] + $count,
				'last'  => time(),
			)
		);
		SPC()->log( 'Source watch: added ' . $count . ' photo(s) to gallery ' . $gallery_id );
	}

	return ! empty( $queue );
}

/**
 * Bring one queued photo into its gallery.
 *
 * @param array $item Queued item: gallery_id, file, source, key, and maybe sizes.
 * @return int|WP_Error|false New image id.
 */
function sunshine_source_watch_import_item( $item ) {
	$gallery_id = (int) $item['gallery_id'];

	sunshine_doing_upload( $gallery_id );

	/**
	 * Bring in a photo Sunshine can't copy itself, such as one in a cloud bucket.
	 *
	 * @param int|WP_Error|null $image_id Null to leave it to Sunshine, otherwise the new image id or an error.
	 * @param array             $item     Queued item: gallery_id, file, source, key, and maybe sizes.
	 */
	$image_id = apply_filters( 'sunshine_source_watch_import', null, $item );

	if ( null === $image_id ) {
		if ( 'ftp' !== $item['source'] ) {
			return new WP_Error( 'no_importer', 'Nothing is set up to bring in ' . $item['source'] . ' photos' );
		}
		if ( ! is_file( $item['file'] ) ) {
			return new WP_Error( 'missing', 'The file is no longer in the folder' );
		}

		$names  = array();
		$result = sunshine_gallery_import_file( $item['file'], $gallery_id, 1, $names );
		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'import_failed', isset( $result['error'] ) ? $result['error'] : '' );
		}
		$image_id = (int) $result['image_id'];
	}

	// Same as every other FTP import: never delete the source of a photo that did
	// not make it in.
	if ( ! is_wp_error( $image_id ) && $image_id && 'ftp' === $item['source'] && SPC()->get_option( 'delete_images_folder' ) ) {
		$paths = array_merge( array( $item['file'] ), ! empty( $item['sizes'] ) ? array_values( (array) $item['sizes'] ) : array() );
		foreach ( $paths as $path ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	return $image_id;
}
