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
	 * Dispatch the async request
	 *
	 * Override to skip async request and rely on cron only for faster uploads
	 * The async request was causing synchronous processing, slowing down uploads
	 *
	 * @return array|WP_Error
	 */
	public function dispatch() {
		// Only schedule a single event for a few seconds in the future
		// This ensures it runs AFTER the current request completes, not during it
		// Scheduling for "now" (time()) causes WordPress to run it on the shutdown hook
		// during the same request, making it synchronous and defeating the purpose
		$scheduled_time = time() + 10;

		// Check if a single event is already scheduled - if so, don't schedule another
		$next_scheduled = wp_next_scheduled( $this->cron_hook_identifier );
		if ( ! $next_scheduled || $next_scheduled > $scheduled_time ) {
			wp_schedule_single_event( $scheduled_time, $this->cron_hook_identifier );
		}

		// Don't call schedule_event() here - it schedules a recurring event that would run immediately
		// The recurring event should be scheduled separately (e.g., on plugin init) and will handle
		// any missed items if single events fail. For now, we rely only on single events.

		// Return a mock success response since we're not making the async request
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

		// Apply watermark if needed
		if ( $watermark && SPC()->get_option( 'watermark_image' ) ) {
			sunshine_watermark_image( $attachment_id, $metadata );
		}

		// Trigger the after_image_process hook for compatibility with other plugins
		do_action( 'sunshine_after_image_process', $attachment_id, $file_path, $watermark );

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
