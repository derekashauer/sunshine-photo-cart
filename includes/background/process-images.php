<?php
declare(strict_types=1);

/**
 * Background process for deferred image processing
 */
class SPC_Background_Process_Images extends SPC_Background_Process {

	/**
	 * @var string
	 */
	protected $action = 'process_images';

	/**
	 * Cron interval in minutes (reduced for faster processing)
	 *
	 * @var int
	 */
	protected $cron_interval = 1;

	/**
	 * How long a single run may work for, in seconds.
	 *
	 * The library default is 20, which on a site with cron firing once a minute
	 * means the queue works for 20 seconds and idles for 40.
	 *
	 * @var int
	 */
	protected $run_time_limit = 60;

	/**
	 * Lock duration. Must be longer than the run budget, or a second run can start
	 * on top of one that is still going.
	 *
	 * @var int
	 */
	protected $queue_lock_time = 120;

	/**
	 * Dispatch the next run.
	 *
	 * Two different situations end up here and they need different handling:
	 *
	 * - Called from the upload request. Doing the work now would make the upload
	 *   itself wait, so all we do is make sure cron will pick it up shortly.
	 * - Called from handle() when a run finishes with items still queued. We are
	 *   already in a background request, so fire the async loopback and let the
	 *   next batch start immediately instead of waiting for the next cron tick.
	 *
	 * Either way schedule_event() runs, so there is a recurring healthcheck that
	 * can restart the queue if a one-off event is ever lost. Losing it is not
	 * hypothetical: when the `cron` option fails to save, the event is silently
	 * dropped and nothing else would ever pick the queue back up.
	 *
	 * @return array|WP_Error
	 */
	public function dispatch() {
		// Order matters. wp_schedule_single_event() refuses to schedule when the same
		// hook is already due within ten minutes, so the one-off has to be booked before
		// the recurring backstop or it would be silently dropped and the queue would not
		// start for up to a minute after an upload.
		$scheduled_time = time() + 10;
		$next_scheduled = wp_next_scheduled( $this->cron_hook_identifier );
		if ( ! $next_scheduled || $next_scheduled > $scheduled_time ) {
			wp_schedule_single_event( $scheduled_time, $this->cron_hook_identifier );
		}

		$this->schedule_event();

		// Only chain straight into the next batch when we are already running in the
		// background. From an upload request this would make the upload synchronous,
		// which is the whole reason this method was overridden in the first place.
		$in_background = $this->is_cron_request()
			|| ( wp_doing_ajax() && ! empty( $_REQUEST['action'] ) && $_REQUEST['action'] === $this->identifier );

		if ( $in_background ) {
			return parent::dispatch();
		}

		return array( 'response' => array( 'code' => 200 ) );
	}

	/**
	 * Override save to ensure data is properly stored
	 *
	 * @return $this
	 */
	public function save() {
		$key = $this->generate_key();

		if ( ! empty( $this->data ) ) {
			update_site_option( $key, $this->data );
		}

		return $this;
	}

	/**
	 * Task
	 *
	 * Process a single image: generate intermediate sizes and apply watermarks
	 *
	 * @param array $item Queue item containing attachment_id, file_path, and watermark flag
	 *
	 * @return false|array
	 */
	protected function task( $item ) {
		if ( ! is_array( $item ) || empty( $item['attachment_id'] ) ) {
			return false;
		}

		$attachment = get_post( (int) $item['attachment_id'] );
		$gallery_id = ( $attachment && $attachment->post_parent ) ? (int) $attachment->post_parent : 0;

		// process_item() has a lot of early exits. Wrapping it means the gallery's
		// pending count comes down however the image finished, including the failure
		// paths — otherwise one dropped image would leave a gallery permanently
		// "still processing" and it would never be reported ready.
		$result = $this->process_item( $item );

		// A non-false return means the item stays queued for another pass, so it is not
		// finished yet. Only count it down once it actually leaves the queue.
		if ( $gallery_id && false === $result ) {
			sunshine_gallery_processing_done( $gallery_id );
		}

		return $result;
	}

	/**
	 * Process a single image: generate intermediate sizes and apply watermarks.
	 *
	 * @param array $item Queue item containing attachment_id, file_path, and watermark flag.
	 *
	 * @return false|array
	 */
	protected function process_item( $item ) {

		$attachment_id = absint( $item['attachment_id'] );

		SPC()->log( 'Background Process: Processing attachment ' . $attachment_id );

		// Include WordPress image functions (needed for cron context)
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Disable big_image_size_threshold for Sunshine gallery images during background processing
		// This prevents WordPress from creating -scaled versions, ensuring full resolution is preserved
		add_filter( 'big_image_size_threshold', array( $this, 'disable_big_image_threshold' ), 999, 4 );

		$file_path = isset( $item['file_path'] ) ? $item['file_path'] : get_attached_file( $attachment_id );
		$watermark = isset( $item['watermark'] ) ? (bool) $item['watermark'] : true;

		// Verify attachment exists
		if ( ! get_post( $attachment_id ) ) {
			return false;
		}

		// Always use WordPress's get_attached_file() for security - it validates the path
		$validated_path = get_attached_file( $attachment_id );
		if ( $validated_path ) {
			$file_path = $validated_path;
		} elseif ( ! $file_path ) {
			return false;
		}

		// If file doesn't exist locally, check if it's on S3 and download it
		if ( ! file_exists( $file_path ) ) {
			// Check if file is on S3 via WP Offload Media
			if ( function_exists( 'as3cf_get_attachment_url' ) ) {
				$s3_url = as3cf_get_attachment_url( $attachment_id );
				if ( $s3_url && ! is_wp_error( $s3_url ) ) {
					// Ensure directory exists
					$file_dir = dirname( $file_path );
					if ( ! is_dir( $file_dir ) ) {
						wp_mkdir_p( $file_dir );
					}

					// Download file from S3
					$downloaded = download_url( $s3_url );
					if ( ! is_wp_error( $downloaded ) ) {
						// Move downloaded file to correct location
						$renamed = @rename( $downloaded, $file_path );
						if ( ! $renamed ) {
							if ( file_exists( $downloaded ) ) {
								@unlink( $downloaded );
							}
							return false;
						}
					} else {
						return false;
					}
				} else {
					return false;
				}
			} else {
				return false;
			}
		}

		// Get gallery ID from attachment parent
		$attachment = get_post( $attachment_id );
		$gallery_id = 0;
		if ( $attachment && $attachment->post_parent ) {
			$gallery_id = $attachment->post_parent;
			// Set up upload directory filter so intermediate sizes are created in correct location
			sunshine_doing_upload( $gallery_id );
		}

		// Fix EXIF orientation on the original before generating intermediates.
		// WordPress depends on PHP's exif extension for orientation detection,
		// but Imagick can read EXIF natively for more reliable handling.
		if ( sunshine_fix_image_orientation( $file_path ) ) {
			SPC()->log( 'Background Process: Fixed EXIF orientation for attachment ' . $attachment_id );
		}

		// Add filter to limit image sizes to only Sunshine sizes during background processing
		// This ensures we only generate sunshine-thumbnail and sunshine-large, not all theme sizes
		add_filter( 'intermediate_image_sizes', array( $this, 'filter_background_image_sizes' ), 99999 );

		// Generate intermediate sizes
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );

		// Remove the filters after generating metadata
		remove_filter( 'intermediate_image_sizes', array( $this, 'filter_background_image_sizes' ), 99999 );
		remove_filter( 'big_image_size_threshold', array( $this, 'disable_big_image_threshold' ), 999 );

		if ( is_wp_error( $metadata ) ) {
			return false;
		}

		// Ensure intermediate sizes exist even when the image is smaller than the target size.
		// This creates copies of the original so watermarks are applied to copies, not the original.
		$metadata = sunshine_ensure_intermediate_sizes( $attachment_id, $metadata, $file_path );

		// Handle secure file names if enabled
		if ( ! function_exists( 'as3cf_get_attachment_url' ) && SPC()->get_option( 'use_secure_file_names' ) ) {
			$file_name = basename( $file_path );
			$info      = pathinfo( $file_name );
			// Get the random string from the main file name if it exists
			$random_string = '';
			if ( preg_match( '/-([a-zA-Z0-9]{24})\./', $file_name, $matches ) ) {
				$random_string = $matches[1];
			}

			if ( ! empty( $random_string ) && ! empty( $metadata['sizes'] ) ) {
				$upload_dir = wp_upload_dir();
				$base_dir   = dirname( $file_path );
				foreach ( $metadata['sizes'] as $size => &$size_data ) {
					$size_info          = pathinfo( $size_data['file'] );
					$size_random_string = wp_generate_password( 24, false );
					$size_data['file']  = str_replace( $random_string, $size_random_string, $size_data['file'] );

					// Rename the intermediate file on the server
					// The file should be in the same directory as the main file
					$original_size_path = trailingslashit( $base_dir ) . $size_info['basename'];
					$new_size_path      = trailingslashit( $base_dir ) . $size_data['file'];
					if ( file_exists( $original_size_path ) ) {
						rename( $original_size_path, $new_size_path );
					}
				}
			}
		}

		// Update attachment metadata BEFORE watermarking so watermark function can find the files
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Set global path marker so EWWW background optimization bypass can detect sunshine images
		$GLOBALS['sunshine_current_upload_path'] = $file_path;

		// Trigger the after_image_process hook which handles:
		// - Priority 1: EWWWIO_EDITOR_OVERWRITE constant (plugin-compat.php)
		// - Priority 10: Watermarking via sunshine_watermark_media_upload (watermark.php)
		// - Priority 20: Cloud storage offloading (cloud-storage hooks.php)
		do_action( 'sunshine_after_image_process', $attachment_id, $file_path, $watermark );

		unset( $GLOBALS['sunshine_current_upload_path'] );

		// If WP Offload Media is active, update metadata again to trigger upload of new intermediate sizes
		if ( function_exists( 'as3cf_get_attachment_url' ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return false; // Remove from queue
	}

	/**
	 * Filter image sizes during background processing to only allow Sunshine sizes
	 *
	 * @param array $sizes Array of image size names
	 * @return array Filtered array with only Sunshine sizes
	 */
	public function filter_background_image_sizes( $sizes ) {
		$sunshine_sizes = array( 'sunshine-thumbnail', 'sunshine-large' );
		$sunshine_sizes = apply_filters( 'sunshine_image_sizes', $sunshine_sizes );
		return $sunshine_sizes;
	}

	/**
	 * Disable big_image_size_threshold for Sunshine gallery images
	 * This prevents WordPress from creating -scaled versions during background processing
	 *
	 * @param int    $threshold      The threshold value in pixels.
	 * @param array  $imagesize      Indexed array of the image width and height.
	 * @param string $file           Full path to the image file.
	 * @param int    $attachment_id  Attachment ID.
	 * @return int|false The threshold or false to disable scaling
	 */
	public function disable_big_image_threshold( $threshold, $imagesize, $file, $attachment_id ) {
		// Check if this attachment belongs to a Sunshine gallery
		$attachment_parent_id = wp_get_post_parent_id( $attachment_id );
		if ( $attachment_parent_id && 'sunshine-gallery' === get_post_type( $attachment_parent_id ) ) {
			SPC()->log( 'Background Process: Disabling big_image_size_threshold for Sunshine gallery image #' . $attachment_id );
			return false;
		}

		// Also check if it has sunshine_file_name meta (alternative check)
		$sunshine_file_name = get_post_meta( $attachment_id, 'sunshine_file_name', true );
		if ( ! empty( $sunshine_file_name ) ) {
			SPC()->log( 'Background Process: Disabling big_image_size_threshold for Sunshine image #' . $attachment_id . ' (via meta)' );
			return false;
		}

		return $threshold;
	}

}
