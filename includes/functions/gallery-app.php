<?php
/**
 * Gallery home screen app: lets clients add a gallery to their phone's home screen,
 * where it opens full screen with its own icon.
 *
 * @package SunshinePhotoCart\Functions
 * @version 3.8.3
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the current page is a gallery that can be added to the home screen.
 *
 * @return SPC_Gallery|false The gallery, or false.
 */
function sunshine_gallery_app_current_gallery() {
	static $result = null;
	if ( null !== $result ) {
		return $result;
	}
	$gallery = false;
	if ( SPC()->get_option( 'gallery_app' ) && ! empty( SPC()->frontend ) && SPC()->frontend->is_gallery() ) {
		$gallery = SPC()->frontend->current_gallery;
		if ( empty( $gallery ) || ! $gallery->can_access() ) {
			$gallery = false;
		}
	}
	// Only remember the answer once WordPress knows which page this is.
	if ( did_action( 'wp' ) ) {
		$result = $gallery;
	}
	return $gallery;
}

/**
 * The image a gallery's app icon is made from: the gallery's own App Icon (or one
 * set on a parent gallery), then the gallery's featured image.
 *
 * The password placeholder image is skipped: only people who can open the gallery
 * get its app, and browsers fetch the manifest without the visitor's session.
 *
 * @param SPC_Gallery $gallery The gallery.
 * @return int Attachment ID, or 0.
 */
function sunshine_gallery_app_icon_source_id( $gallery ) {
	$icon_id = intval( $gallery->get_meta_value( 'app_icon', true ) );
	if ( $icon_id && wp_attachment_is_image( $icon_id ) ) {
		return $icon_id;
	}
	return intval( $gallery->get_featured_image_id( true ) );
}

/**
 * Square icons for a gallery's app, made once from the source image and saved in
 * uploads/sunshine/app-icons/.
 *
 * Gallery photos use their sunshine-large size (watermarked when watermarks are on),
 * never the original.
 *
 * @param SPC_Gallery $gallery The gallery.
 * @return array Icons keyed by pixel size: array( 'url' => ..., 'size' => ... ).
 */
function sunshine_gallery_app_icons( $gallery ) {

	static $cache = array();
	if ( isset( $cache[ $gallery->get_id() ] ) ) {
		return $cache[ $gallery->get_id() ];
	}

	$attachment_id = sunshine_gallery_app_icon_source_id( $gallery );
	$source        = $attachment_id ? wp_get_attachment_image_src( $attachment_id, 'sunshine-large' ) : false;
	if ( empty( $source[0] ) ) {
		$cache[ $gallery->get_id() ] = array();
		return array();
	}

	$upload_dir  = wp_upload_dir();
	$folder      = $upload_dir['basedir'] . '/sunshine/app-icons';
	$folder_url  = $upload_dir['baseurl'] . '/sunshine/app-icons';
	$source_url  = strtok( $source[0], '?' ); // Cloud storage signs URLs differently on every request.
	$source_file = '';
	if ( 0 === strpos( $source_url, $upload_dir['baseurl'] ) ) {
		$source_file = $upload_dir['basedir'] . substr( $source_url, strlen( $upload_dir['baseurl'] ) );
	}
	$local = $source_file && file_exists( $source_file );

	// New icons are made when the image file, its sizes or its watermark change.
	$fingerprint = $source_url . wp_json_encode( wp_get_attachment_metadata( $attachment_id ) ) . ( $local ? filemtime( $source_file ) . filesize( $source_file ) : '' );
	$key         = $attachment_id . '-' . substr( wp_hash( $fingerprint ), 0, 10 );
	$sizes       = array_map( 'intval', apply_filters( 'sunshine_gallery_app_icon_sizes', array( 180, 192, 512 ) ) );
	rsort( $sizes );

	$icons   = array();
	$missing = array();
	foreach ( $sizes as $size ) {
		$file = $folder . '/' . $key . '-' . $size . '.png';
		if ( file_exists( $file ) ) {
			$icons[ $size ] = array(
				'url'  => $folder_url . '/' . basename( $file ),
				'size' => $size,
			);
		} else {
			$missing[] = $size;
		}
	}

	// Don't try again on every page view when making the icons just failed.
	$failed_key = 'sunshine_app_icon_failed_' . $key;
	if ( empty( $missing ) || get_transient( $failed_key ) ) {
		ksort( $icons );
		$cache[ $gallery->get_id() ] = $icons;
		return $icons;
	}

	// The image lives on cloud storage: download it.
	$temp_file = '';
	if ( ! $local ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$temp_file = download_url( $source[0], 15 );
		if ( is_wp_error( $temp_file ) ) {
			SPC()->log( 'Could not download image for gallery app icon: ' . $temp_file->get_error_message() );
			set_transient( $failed_key, 1, HOUR_IN_SECONDS );
			$cache[ $gallery->get_id() ] = $icons;
			return $icons;
		}
		$source_file = $temp_file;
	}

	$editor = wp_get_image_editor( $source_file );
	if ( is_wp_error( $editor ) ) {
		SPC()->log( 'Could not open image for gallery app icon: ' . $editor->get_error_message() );
		set_transient( $failed_key, 1, HOUR_IN_SECONDS );
	} else {
		wp_mkdir_p( $folder );
		// Crop a square from the middle at the largest size, then make each smaller size from it.
		$dimensions = $editor->get_size();
		$square     = min( $dimensions['width'], $dimensions['height'] );
		$editor->crop( intval( ( $dimensions['width'] - $square ) / 2 ), intval( ( $dimensions['height'] - $square ) / 2 ), $square, $square, $sizes[0], $sizes[0] );
		foreach ( $sizes as $size ) {
			if ( $size !== $sizes[0] ) {
				$editor->resize( $size, $size, true );
			}
			if ( ! in_array( $size, $missing, true ) ) {
				continue;
			}
			$file  = $folder . '/' . $key . '-' . $size . '.png';
			$saved = $editor->save( $file, 'image/png' );
			if ( is_wp_error( $saved ) ) {
				SPC()->log( 'Could not save gallery app icon: ' . $saved->get_error_message() );
				set_transient( $failed_key, 1, HOUR_IN_SECONDS );
				continue;
			}
			$icons[ $size ] = array(
				'url'  => $folder_url . '/' . basename( $file ),
				'size' => $size,
			);
		}
		// Icons made from an older version of this image are no longer used.
		sunshine_gallery_app_delete_icons( $attachment_id, $key );
	}

	if ( $temp_file ) {
		wp_delete_file( $temp_file );
	}

	ksort( $icons );
	$cache[ $gallery->get_id() ] = $icons;
	return $icons;

}

/**
 * Key that lets the manifest be fetched only by visitors who could see the gallery page.
 *
 * @param int $gallery_id Gallery ID.
 * @return string
 */
function sunshine_gallery_app_manifest_key( $gallery_id ) {
	return substr( wp_hash( 'sunshine_gallery_app_' . $gallery_id ), 0, 12 );
}

/**
 * Tags phones read to save the gallery to the home screen.
 */
add_action( 'wp_head', 'sunshine_gallery_app_head', 3 );
function sunshine_gallery_app_head() {

	$gallery = sunshine_gallery_app_current_gallery();
	if ( ! $gallery ) {
		return;
	}

	$manifest_url = add_query_arg(
		array(
			'sunshine_app_manifest' => $gallery->get_id(),
			'key'                   => sunshine_gallery_app_manifest_key( $gallery->get_id() ),
		),
		home_url( '/' )
	);
	$name         = wp_strip_all_tags( $gallery->get_name() );
	$icons        = sunshine_gallery_app_icons( $gallery );

	echo '<link rel="manifest" href="' . esc_url( $manifest_url ) . '" />' . "\n";
	echo '<meta name="mobile-web-app-capable" content="yes" />' . "\n";
	echo '<meta name="apple-mobile-web-app-capable" content="yes" />' . "\n";
	echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( $name ) . '" />' . "\n";
	if ( ! empty( $icons[180] ) ) {
		echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url( $icons[180]['url'] ) . '" />' . "\n";
	}

}

/**
 * Leave out the site icon's home screen icon on gallery pages, so phones use the gallery's.
 */
add_filter( 'site_icon_meta_tags', 'sunshine_gallery_app_site_icon_meta_tags' );
function sunshine_gallery_app_site_icon_meta_tags( $tags ) {
	$gallery = sunshine_gallery_app_current_gallery();
	// Only when the gallery has its own icon to use instead.
	if ( ! $gallery ) {
		return $tags;
	}
	$icons = sunshine_gallery_app_icons( $gallery );
	if ( empty( $icons[180] ) ) {
		return $tags;
	}
	return array_filter(
		$tags,
		function ( $tag ) {
			return false === strpos( $tag, 'apple-touch-icon' );
		}
	);
}

/**
 * Serve a gallery's web app manifest.
 */
add_action( 'template_redirect', 'sunshine_gallery_app_manifest', 1 );
function sunshine_gallery_app_manifest() {

	if ( ! isset( $_GET['sunshine_app_manifest'] ) ) {
		return;
	}

	$gallery_id = intval( $_GET['sunshine_app_manifest'] );
	$key        = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	$gallery    = sunshine_get_gallery( $gallery_id );

	if ( ! SPC()->get_option( 'gallery_app' ) || empty( $gallery ) || 'sunshine-gallery' !== get_post_type( $gallery_id ) || 'publish' !== get_post_status( $gallery_id ) || ! hash_equals( sunshine_gallery_app_manifest_key( $gallery_id ), $key ) ) {
		status_header( 404 );
		exit;
	}

	$name     = html_entity_decode( wp_strip_all_tags( $gallery->get_name() ), ENT_QUOTES, 'UTF-8' );
	$manifest = array(
		'id'               => $gallery->get_permalink(),
		'name'             => $name,
		'short_name'       => $name,
		'start_url'        => $gallery->get_permalink(),
		// The whole site, so the cart and checkout stay inside the app.
		'scope'            => home_url( '/' ),
		'display'          => 'standalone',
		'background_color' => '#ffffff',
		'theme_color'      => '#ffffff',
		'icons'            => array(),
	);
	foreach ( sunshine_gallery_app_icons( $gallery ) as $size => $icon ) {
		if ( $size < 192 ) {
			continue;
		}
		$manifest['icons'][] = array(
			'src'   => $icon['url'],
			'sizes' => $size . 'x' . $size,
			'type'  => 'image/png',
		);
	}
	$manifest = apply_filters( 'sunshine_gallery_app_manifest', $manifest, $gallery );

	status_header( 200 );
	header( 'Content-Type: application/manifest+json; charset=utf-8' );
	header( 'Cache-Control: public, max-age=3600' );
	echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;

}

/**
 * Remove an image's app icons when the image is deleted.
 */
add_action( 'delete_attachment', 'sunshine_gallery_app_delete_icons' );
function sunshine_gallery_app_delete_icons( $attachment_id, $keep_key = '' ) {
	$upload_dir = wp_upload_dir();
	$files      = glob( $upload_dir['basedir'] . '/sunshine/app-icons/' . intval( $attachment_id ) . '-*.png' );
	if ( $files ) {
		foreach ( $files as $file ) {
			if ( $keep_key && 0 === strpos( basename( $file ), $keep_key . '-' ) ) {
				continue;
			}
			wp_delete_file( $file );
		}
	}
}

/**
 * Prompt on phones and tablets to add the gallery to the home screen.
 */
add_action( 'wp_enqueue_scripts', 'sunshine_gallery_app_enqueue', 20 );
function sunshine_gallery_app_enqueue() {
	if ( ! sunshine_gallery_app_current_gallery() ) {
		return;
	}
	wp_enqueue_script( 'sunshine-photo-cart-gallery-app', SUNSHINE_PHOTO_CART_URL . 'assets/js/gallery-app.js', array(), SUNSHINE_PHOTO_CART_VERSION, true );
}

add_action( 'wp_footer', 'sunshine_gallery_app_prompt' );
function sunshine_gallery_app_prompt() {
	$gallery = sunshine_gallery_app_current_gallery();
	if ( ! $gallery ) {
		return;
	}
	$icons = sunshine_gallery_app_icons( $gallery );
	?>
	<div id="sunshine--app-prompt" data-gallery="<?php echo esc_attr( $gallery->get_id() ); ?>" hidden>
		<?php if ( ! empty( $icons[192] ) ) { ?>
			<img id="sunshine--app-prompt--icon" src="<?php echo esc_url( $icons[192]['url'] ); ?>" alt="" width="48" height="48" />
		<?php } ?>
		<div id="sunshine--app-prompt--text">
			<strong><?php esc_html_e( 'Add this gallery to your home screen', 'sunshine-photo-cart' ); ?></strong>
			<span id="sunshine--app-prompt--steps"><?php esc_html_e( 'Tap Share, then Add to Home Screen.', 'sunshine-photo-cart' ); ?></span>
		</div>
		<button type="button" id="sunshine--app-prompt--add"><?php esc_html_e( 'Add', 'sunshine-photo-cart' ); ?></button>
		<button type="button" id="sunshine--app-prompt--close" aria-label="<?php esc_attr_e( 'Close', 'sunshine-photo-cart' ); ?>">&times;</button>
	</div>
	<?php
}
