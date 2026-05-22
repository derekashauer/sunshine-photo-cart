<?php
/**
 * Image-regeneration tool.
 *
 * Heavy per-image work: read original (often 10+ MB), build resized
 * variants, apply watermarks, write back. Stays one-image-per-batch via
 * `$batch_size = 1` to avoid blowing PHP memory on shared hosts.
 *
 * Both the admin UI's per-image AJAX handler and the API's
 * `process_batch()` route their work through the same shared worker
 * (`regenerate_one()`).
 */
class SPC_Tool_Regenerate extends SPC_Tool {

	private $remove_image_sizes = false;

	protected $is_chunked = true;
	protected $batch_size = 1;

	function __construct() {
		parent::__construct(
			__( 'Regenerate Images', 'sunshine-photo-cart' ),
			'regenerate-images',
			__( 'If you have changed thumbnail size, digital download size or watermark settings, you need to regenerate images.', 'sunshine-photo-cart' ),
			__( 'Regenerate Images', 'sunshine-photo-cart' )
		);

		add_action( 'wp_ajax_sunshine_regenerate_image', array( $this, 'regenerate_image' ) );

		add_filter( 'post_row_actions', array( $this, 'regenerate_gallery_images_link_row' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'regenerate_gallery_images_link_row' ), 10, 2 );

	}

	function regenerate_gallery_images_link_row( $actions, $post ) {
		if ( $post->post_type == 'sunshine-gallery' ) {
			$actions['regenerate'] = '<a href="' . wp_nonce_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-tools&tool=regenerate-images&sunshine_gallery=' . $post->ID ), 'sunshine_tool_' . $this->get_key() ) . '">' . __( 'Regenerate Images', 'sunshine-photo-cart' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * REST-API path: total images that need regenerating.
	 *
	 * Optional $gallery_id narrows to a single gallery; without it, every
	 * Sunshine-uploaded attachment is counted.
	 */
	public function count_remaining( $gallery_id = 0 ) {
		if ( ! empty( $gallery_id ) ) {
			$gallery = sunshine_get_gallery( intval( $gallery_id ) );
			return $gallery ? (int) $gallery->get_image_count() : 0;
		}
		$args  = array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => 'sunshine_file_name',
			'no_found_rows'  => false,
		);
		$query = new WP_Query( $args );
		return (int) $query->found_posts;
	}

	/**
	 * REST-API path: regenerate one image (or up to $size — capped at
	 * $batch_size). Caller passes an `offset` cursor that points at the
	 * Nth image in the queue. Per-image work is delegated to
	 * `regenerate_one()` so this method and the admin AJAX handler call
	 * the same code.
	 *
	 * Optional $params:
	 *   gallery_id       int    Restrict to a single gallery.
	 *   apply_watermark  '0|1'  Force-on watermark for previously skipped images.
	 *   offset           int    Index into the work queue to start from.
	 */
	public function process_batch( $size = null, $params = array() ) {
		$size            = min( $this->get_batch_size(), max( 1, (int) ( $size ?: $this->get_batch_size() ) ) );
		$offset          = isset( $params['offset'] ) ? max( 0, (int) $params['offset'] ) : 0;
		$gallery_id      = isset( $params['gallery_id'] ) ? intval( $params['gallery_id'] ) : 0;
		$apply_watermark = isset( $params['apply_watermark'] ) ? sanitize_text_field( $params['apply_watermark'] ) : '';

		$image_ids = $this->collect_image_ids( $gallery_id, $offset, $size );

		$processed = 0;
		$log       = array();
		$errors    = array();

		foreach ( $image_ids as $image_id ) {
			set_time_limit( 600 );
			$result = $this->regenerate_one( $image_id, $apply_watermark );
			if ( $result['ok'] ) {
				$processed++;
				$log[] = array(
					'image_id' => $image_id,
					'file'     => $result['file'],
				);
			} else {
				$errors[] = array(
					'image_id' => $image_id,
					'file'     => $result['file'],
					'error'    => $result['error'],
				);
			}
		}

		$total = $this->count_remaining( $gallery_id );

		return array(
			'processed'   => $processed,
			'remaining'   => max( 0, $total - ( $offset + count( $image_ids ) ) ),
			'next_offset' => $offset + count( $image_ids ),
			'log'         => $log,
			'errors'      => $errors,
		);
	}

	/**
	 * Resolve the slice of image IDs at [offset, offset+size) — either from
	 * a specific gallery's image list or from the global Sunshine attachment
	 * query.
	 */
	private function collect_image_ids( $gallery_id, $offset, $size ) {
		if ( ! empty( $gallery_id ) ) {
			$gallery = sunshine_get_gallery( $gallery_id );
			if ( ! $gallery ) {
				return array();
			}
			$all = $gallery->get_image_ids();
			if ( ! is_array( $all ) ) {
				return array();
			}
			return array_slice( $all, $offset, $size );
		}
		$query = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'offset'         => $offset,
			'posts_per_page' => $size,
			'fields'         => 'ids',
			'meta_key'       => 'sunshine_file_name',
		) );
		return (array) $query->posts;
	}

	/**
	 * Per-image worker. Called by both the admin AJAX handler and the API
	 * batch path so behavior stays identical between the two transports.
	 *
	 * Visibility note: this is `protected` so external code can't call it
	 * without going through one of the cap-checked wrappers
	 * (`regenerate_image()` for the admin AJAX path, `process_batch()`
	 * for the REST path). Subclasses can still override.
	 *
	 * Returns ['ok' => bool, 'file' => string, 'image_id' => int, 'error' => string|null].
	 */
	protected function regenerate_one( $image_id, $apply_watermark = '' ) {
		global $intermediate_image_sizes;

		// wp_generate_attachment_metadata, wp_handle_upload, etc. live in
		// wp-admin/includes/ — autoloaded for admin requests but absent when
		// the API or CLI drives this worker. Load them on demand.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'wp_read_image_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$image = sunshine_get_image( $image_id );
		if ( empty( $image ) ) {
			return array( 'ok' => false, 'image_id' => (int) $image_id, 'file' => '', 'error' => 'image_not_found' );
		}

		if ( function_exists( 'wp_get_original_image_path' ) ) {
			$file_path = wp_get_original_image_path( $image_id );
		} else {
			$file_path = get_attached_file( $image_id );
		}
		if ( is_wp_error( $file_path ) ) {
			SPC()->log( 'Could not find original file to regenerate from, image ID: ' . $image_id );
			return array(
				'ok'       => false,
				'image_id' => (int) $image_id,
				'file'     => $image->get_name(),
				'error'    => __( 'Could not find original file to regenerate from', 'sunshine-photo-cart' ),
			);
		}

		SPC()->log( 'Regenerating image: ' . $image_id );

		$upload_info              = wp_upload_dir();
		$upload_dir               = $upload_info['basedir'];
		$downloaded_from_cloud    = false;
		$sunshine_cloud_offloaded = false;

		// Sunshine Cloud Storage offload: pull the file local for processing.
		$cloud_storage_key = get_post_meta( $image_id, 'sunshine_cloud_storage_key', true );
		if ( ! empty( $cloud_storage_key ) && ( ! file_exists( $file_path ) || substr( $file_path, 0, 2 ) == 's3' ) ) {
			$sunshine_cloud_offloaded = true;

			if ( class_exists( 'Sunshine_Cloud_Storage_Client' ) ) {
				$client = Sunshine_Cloud_Storage_Client::instance();

				$local_path = $upload_dir . '/sunshine/' . $image->get_gallery_id() . '/' . basename( $cloud_storage_key );

				$local_dir = dirname( $local_path );
				if ( ! file_exists( $local_dir ) ) {
					wp_mkdir_p( $local_dir );
				}

				SPC()->log( 'Cloud Storage: Downloading original file for regeneration: ' . $cloud_storage_key );

				if ( $client->download_file( $cloud_storage_key, $local_path ) ) {
					$file_path             = $local_path;
					$downloaded_from_cloud = true;
					SPC()->log( 'Cloud Storage: Successfully downloaded file for regeneration: ' . $local_path );
				} else {
					SPC()->log( 'Cloud Storage: Failed to download file for regeneration: ' . $cloud_storage_key );
					return array(
						'ok'       => false,
						'image_id' => (int) $image_id,
						'file'     => $image->get_name(),
						'error'    => __( 'Could not download file from cloud storage for regeneration', 'sunshine-photo-cart' ),
					);
				}
			} else {
				SPC()->log( 'Cloud Storage: Client class not available for regeneration' );
				return array(
					'ok'       => false,
					'image_id' => (int) $image_id,
					'file'     => $image->get_name(),
					'error'    => __( 'Cloud Storage addon is required to regenerate this offloaded image', 'sunshine-photo-cart' ),
				);
			}
		} elseif ( substr( $file_path, 0, 2 ) == 's3' && function_exists( 'as3cf_get_attachment_url' ) ) {
			// WP Offload Media: fetch the original locally for processing.
			$remote_url = as3cf_get_attachment_url( $image_id );
			$orig_image = file_get_contents( $remote_url );

			$file_path = $upload_dir . '/sunshine/' . $image->get_gallery_id() . '/' . basename( $file_path );

			$local_dir = dirname( $file_path );
			if ( ! file_exists( $local_dir ) ) {
				wp_mkdir_p( $local_dir );
			}

			file_put_contents( $file_path, $orig_image );
			$downloaded_from_cloud = true;
		}

		// After the cloud-pull branch above we should have a usable local file.
		// If we still don't, the attachment is in a half-broken state (no cloud
		// key but local file deleted) — report it as an error rather than
		// silently producing bad output further down the pipeline.
		if ( ! file_exists( $file_path ) ) {
			SPC()->log( 'Regenerate: original file missing for image ' . $image_id . ' (' . $file_path . ')' );
			return array(
				'ok'       => false,
				'image_id' => (int) $image_id,
				'file'     => $image->get_name(),
				'error'    => __( 'Original file is missing locally and no cloud-storage key is set.', 'sunshine-photo-cart' ),
			);
		}

		// Wipe existing intermediate sizes so they get rebuilt.
		if ( ! $downloaded_from_cloud && file_exists( $file_path ) ) {
			$directory = dirname( $file_path );
			$file_info = pathinfo( $file_path );
			$filename  = $file_info['filename'];
			$extension = isset( $file_info['extension'] ) ? $file_info['extension'] : '';

			if ( ! empty( $extension ) ) {
				$pattern      = $directory . '/' . $filename . '-*x*.' . $extension;
				$extra_images = glob( $pattern );
				foreach ( $extra_images as $extra_image ) {
					wp_delete_file( $extra_image );
				}
			}
		}

		if ( $downloaded_from_cloud ) {
			update_attached_file( $image_id, $file_path );
		}

		$created_timestamp = '';
		if ( file_exists( $file_path ) && is_readable( $file_path ) ) {
			$exif_data = @exif_read_data( $file_path, 'EXIF', true );

			if ( ! empty( $exif_data['EXIF']['DateTimeOriginal'] ) ) {
				$photo_time = $exif_data['EXIF']['DateTimeOriginal'];
				$timestamp  = strtotime( str_replace( ':', '-', substr( $photo_time, 0, 10 ) ) . substr( $photo_time, 10 ) );
				if ( $timestamp ) {
					$created_timestamp          = $timestamp;
					$readable_created_timestamp = gmdate( 'Y-m-d H:i:s', $created_timestamp );
					SPC()->log( 'Found EXIF DateTimeOriginal for ' . basename( $file_path ) . ': ' . $readable_created_timestamp );
				}
			}
		}

		$new_metadata = wp_generate_attachment_metadata( $image_id, $file_path );

		$new_metadata = sunshine_ensure_intermediate_sizes( $image_id, $new_metadata, $file_path );

		$image_meta   = isset( $new_metadata['image_meta'] ) ? $new_metadata['image_meta'] : array();
		if ( empty( $created_timestamp ) && ! empty( $image_meta['created_timestamp'] ) ) {
			$created_timestamp = $image_meta['created_timestamp'];
		}
		if ( ! empty( $created_timestamp ) ) {
			update_post_meta( $image_id, 'created_timestamp', $created_timestamp );
		}

		// Apply the watermark policy.
		if ( '1' === $apply_watermark ) {
			$watermark = 1;
			update_post_meta( $image_id, 'sunshine_watermark', 1 );
		} else {
			$watermark = get_post_meta( $image_id, 'sunshine_watermark', true );
			if ( $watermark === '' ) {
				$watermark = 1;
			}
		}

		do_action( 'sunshine_regenerate_image', $image_id );
		do_action( 'sunshine_after_image_process', $image_id, $file_path, $watermark );
		wp_update_attachment_metadata( $image_id, $new_metadata );

		return array(
			'ok'       => true,
			'image_id' => (int) $image_id,
			'file'     => $image->get_name(),
			'error'    => null,
		);
	}

	protected function do_process() {
		global $wpdb;

		$gallery_id               = ( isset( $_GET['sunshine_gallery'] ) ) ? intval( wp_unslash( $_GET['sunshine_gallery'] ) ) : '';
		$watermark_image          = SPC()->get_option( 'watermark_image' );
		$apply_watermark          = isset( $_GET['apply_watermark'] ) ? sanitize_text_field( wp_unslash( $_GET['apply_watermark'] ) ) : null;
		$images_without_watermark = 0;

		// Watermark prompt: count images that have watermark explicitly disabled.
		if ( ! empty( $watermark_image ) && null === $apply_watermark ) {
			if ( ! empty( $gallery_id ) ) {
				$gallery   = sunshine_get_gallery( $gallery_id );
				$image_ids = $gallery->get_image_ids();
				if ( ! empty( $image_ids ) && is_array( $image_ids ) ) {
					foreach ( $image_ids as $image_id ) {
						$watermark_meta = get_post_meta( $image_id, 'sunshine_watermark', true );
						if ( 0 === $watermark_meta || '0' === $watermark_meta || false === $watermark_meta ) {
							$images_without_watermark++;
						}
					}
				}
			} else {
				$args_no_watermark        = array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'nopaging'    => true,
					'meta_query'  => array(
						'relation' => 'AND',
						array(
							'key'     => 'sunshine_file_name',
							'compare' => 'EXISTS',
						),
						array(
							'key'     => 'sunshine_watermark',
							'value'   => array( '0', 0 ),
							'compare' => 'IN',
						),
					),
				);
				$query_no_watermark       = new WP_Query( $args_no_watermark );
				$images_without_watermark = $query_no_watermark->found_posts;
			}
		}

		if ( ! empty( $watermark_image ) && null === $apply_watermark && $images_without_watermark > 0 ) {
			$this->show_watermark_options( $images_without_watermark, $gallery_id );
			return;
		}

		if ( ! empty( $gallery_id ) ) {
			$gallery = sunshine_get_gallery( $gallery_id );
			/* translators: %s is the gallery title */
			$title = sprintf( __( 'Regenerating images for "%s"', 'sunshine-photo-cart' ), $gallery->get_name() );
			$count = $gallery->get_image_count();
		} else {
			$title = __( 'Regenerating images', 'sunshine-photo-cart' );
			$count = $this->count_remaining();
		}

		?>
		<h3><?php echo esc_html( $title ); ?>...</h3>
		<div id="sunshine-progress-bar" style="">
			<div id="sunshine-percentage" style=""></div>
			<div id="sunshine-processed" style="">
				<span id="sunshine-processed-count">0</span> / <span id="processed-total"><?php echo esc_html( $count ); ?></span>
			</div>
		</div>
		<p align="center" id="abort"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine-tools' ) ); ?>"><?php esc_html_e( 'Abort', 'sunshine-photo-cart' ); ?></a></p>
		<ul id="results"></ul>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			var processed = 0;
			var total = <?php echo esc_js( $count ); ?>;
			var percent = 0;
			function sunshine_regenerate_image( item_number ) {
				var data = {
					'action': 'sunshine_regenerate_image',
					'gallery': '<?php echo esc_js( $gallery_id ); ?>',
					'item_number': item_number,
					'security': "<?php echo esc_js( wp_create_nonce( 'sunshine_regenerate_image' ) ); ?>",
					'apply_watermark': "<?php echo esc_js( $apply_watermark ); ?>"
				};
				$.postq( 'sunshineimageregenerate', ajaxurl, data, function(response) {
					if ( response.error ) {
						$( '#results' ).prepend( '<li><a href="post.php?action=edit&post=' + response.image_id + '" style="color: red;">' + response.file + '</a>: ' + response.error + '</li>' );
					} else {
						$( '#results' ).prepend( '<li><a href="post.php?action=edit&post=' + response.image_id + '">' + response.file + '</a></li>' );
					}
				}).fail( function( jqXHR ) {
					if ( jqXHR.status == 500 || jqXHR.status == 0 ){
						$( '#results' ).prepend( '<li><strong style="color: red;"><?php echo esc_js( __( 'Image did not fully upload because it is too large for your server to handle. Thumbnails and watermarks may not have been applied. Recommend increasing available memory.', 'sunshine-photo-cart' ) ); ?></strong></li>' );
					}
				}).always(function(){
					processed++;
					if ( processed >= total ) {
						$( '#abort' ).hide();
						$( '#sunshine-progress-bar' ).addClass( 'done' )
						$( '#sunshine-processed' ).html( '<?php echo esc_js( __( 'Done!', 'sunshine-photo-cart' ) ); ?>' );

					}
					$( '#sunshine-processed-count' ).html( processed );
					percent = Math.round( ( processed / total ) * 100 );
					$( '#sunshine-percentage' ).css( 'width', percent + '%' );
				});
			}
			for (i = 0; i < total; i++) {
				sunshine_regenerate_image( i );
			}
		});
		</script>

		<?php

	}

	/**
	 * Show watermark options before regeneration.
	 */
	private function show_watermark_options( $images_without_watermark, $gallery_id = '' ) {
		$base_url = admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-tools&tool=regenerate-images' );
		$base_url = wp_nonce_url( $base_url, 'sunshine_tool_' . $this->get_key() );

		if ( ! empty( $gallery_id ) ) {
			$base_url = add_query_arg( 'sunshine_gallery', $gallery_id, $base_url );
		}

		$apply_watermark_url = add_query_arg( 'apply_watermark', '1', $base_url );
		$keep_settings_url   = add_query_arg( 'apply_watermark', '0', $base_url );

		?>
		<div class="sunshine-watermark-notice" style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #dba617; padding: 12px; margin: 20px 0;">
			<h3 style="margin-top: 0;"><?php esc_html_e( 'Watermark Settings Detected', 'sunshine-photo-cart' ); ?></h3>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d is the number of images without watermark */
						_n(
							'You have %d image that does not have watermark enabled, but you have a watermark set in your general settings.',
							'You have %d images that do not have watermark enabled, but you have a watermark set in your general settings.',
							$images_without_watermark,
							'sunshine-photo-cart'
						),
						$images_without_watermark
					)
				);
				?>
			</p>
			<p><?php esc_html_e( 'How would you like to proceed?', 'sunshine-photo-cart' ); ?></p>
			<p>
				<a href="<?php echo esc_url( $apply_watermark_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Apply watermark to all images', 'sunshine-photo-cart' ); ?>
				</a>
				<a href="<?php echo esc_url( $keep_settings_url ); ?>" class="button">
					<?php esc_html_e( 'Keep current image watermark settings', 'sunshine-photo-cart' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Admin AJAX endpoint — preserved 1:1 with the existing JS contract.
	 * Routes per-image work through `regenerate_one()` (shared with the
	 * REST batch path).
	 */
	function regenerate_image() {
		if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine_regenerate_image' ) || ! current_user_can( 'sunshine_manage_options' ) ) {
			wp_send_json_error();
		}

		set_time_limit( 600 );

		$item_number     = intval( $_POST['item_number'] );
		$apply_watermark = isset( $_POST['apply_watermark'] ) ? sanitize_text_field( wp_unslash( $_POST['apply_watermark'] ) ) : '';

		if ( ! empty( $_POST['gallery'] ) ) {
			$gallery   = sunshine_get_gallery( intval( $_POST['gallery'] ) );
			$image_ids = $gallery->get_image_ids();
			$image_id  = $image_ids[ $item_number ];
		} else {
			$args     = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'offset'         => $item_number,
				'posts_per_page' => 1,
				'meta_key'       => 'sunshine_file_name',
			);
			$query    = new WP_Query( $args );
			$image_id = $query->posts[0]->ID;
		}

		$result = $this->regenerate_one( $image_id, $apply_watermark );

		if ( $result['ok'] ) {
			wp_send_json(
				array(
					'status'   => 'success',
					'file'     => $result['file'],
					'image_id' => $result['image_id'],
				)
			);
			return;
		}

		wp_send_json(
			array(
				'status'   => 'error',
				'file'     => $result['file'],
				'image_id' => $result['image_id'],
				'error'    => $result['error'],
			)
		);
	}

	public function remove_image_sizes( $sizes ) {
		if ( $this->remove_image_sizes ) {
			foreach ( $sizes as $key => $size ) {
				if ( strpos( $size, 'sunshine-' ) === false ) {
					unset( $sizes[ $key ] );
				}
			}
		}
		return $sizes;
	}

}

$spc_tool_regenerate = new SPC_Tool_Regenerate();
