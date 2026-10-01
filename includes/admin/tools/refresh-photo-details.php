<?php
/**
 * Refresh Photo Details tool.
 *
 * Reads the keywords, title, caption and photo date back out of each photo's
 * original file and saves them the same way a new upload does. It exists for
 * photos that got into a gallery without those details ever being read, such
 * as galleries imported with pre-made thumbnails before imports read them, so
 * gallery search can find those photos by keyword without deleting and
 * re-importing a gallery that may already have orders.
 *
 * It only ever runs on one gallery (and its sub-galleries), started from the
 * button below the images on the gallery edit screen. A site-wide run would download every original from cloud
 * storage one at a time, which on a large site could take days.
 *
 * The photo files are never changed. Only the stored details are rewritten.
 */
class SPC_Tool_Refresh_Photo_Details extends SPC_Tool {

	protected $is_chunked = true;
	protected $batch_size = 1;

	function __construct() {
		parent::__construct(
			__( 'Refresh Photo Details', 'sunshine-photo-cart' ),
			'refresh-photo-details',
			__( 'Reads the keywords, title, caption and photo date from your original files again, so gallery search can find photos by keyword. Use this on galleries imported before Sunshine read those details. Your photos and thumbnails are not changed. To run it, edit a gallery and use the "Refresh photo details" button below its images.', 'sunshine-photo-cart' )
		);

		add_action( 'wp_ajax_sunshine_refresh_photo_details', array( $this, 'refresh_photo_details' ) );
	}

	/**
	 * Every photo in the gallery and all of its sub-galleries.
	 */
	private function get_gallery_image_ids( $gallery_id ) {
		$gallery_id = intval( $gallery_id );
		if ( empty( $gallery_id ) ) {
			return array();
		}

		$gallery_ids = array_merge( array( $gallery_id ), sunshine_get_gallery_descendant_ids( $gallery_id, 'any' ) );
		$image_ids   = array();
		foreach ( $gallery_ids as $id ) {
			$gallery = sunshine_get_gallery( $id );
			if ( ! $gallery ) {
				continue;
			}
			$ids = $gallery->get_image_ids();
			if ( is_array( $ids ) ) {
				$image_ids = array_merge( $image_ids, $ids );
			}
		}

		return array_values( array_unique( array_map( 'intval', $image_ids ) ) );
	}

	/**
	 * REST-API path: photos in the given gallery. Without a gallery there is
	 * nothing to do.
	 */
	public function count_remaining( $gallery_id = 0 ) {
		return count( $this->get_gallery_image_ids( $gallery_id ) );
	}

	/**
	 * REST-API path. Requires $params['gallery_id']; `offset` is the cursor.
	 */
	public function process_batch( $size = null, $params = array() ) {
		$size       = min( $this->get_batch_size(), max( 1, (int) ( $size ?: $this->get_batch_size() ) ) );
		$offset     = isset( $params['offset'] ) ? max( 0, (int) $params['offset'] ) : 0;
		$gallery_id = isset( $params['gallery_id'] ) ? intval( $params['gallery_id'] ) : 0;

		$all       = $this->get_gallery_image_ids( $gallery_id );
		$image_ids = array_slice( $all, $offset, $size );

		$processed = 0;
		$log       = array();
		$errors    = array();

		foreach ( $image_ids as $image_id ) {
			set_time_limit( 600 );
			$result = $this->refresh_one( $image_id );
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

		return array(
			'processed'   => $processed,
			'remaining'   => max( 0, count( $all ) - ( $offset + count( $image_ids ) ) ),
			'next_offset' => $offset + count( $image_ids ),
			'log'         => $log,
			'errors'      => $errors,
		);
	}

	/**
	 * Per-photo worker, shared by the admin AJAX handler and the API path.
	 *
	 * Reads the original rather than a thumbnail: thumbnails Sunshine makes
	 * itself lose the file's keywords, only pre-made ones keep them.
	 *
	 * Returns ['ok' => bool, 'file' => string, 'image_id' => int, 'keywords' => string, 'error' => string|null].
	 */
	protected function refresh_one( $image_id ) {
		if ( ! function_exists( 'wp_read_image_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$image = sunshine_get_image( $image_id );
		if ( empty( $image ) ) {
			return array( 'ok' => false, 'image_id' => (int) $image_id, 'file' => '', 'keywords' => '', 'error' => __( 'Photo not found', 'sunshine-photo-cart' ) );
		}

		if ( function_exists( 'wp_get_original_image_path' ) ) {
			$file_path = wp_get_original_image_path( $image_id );
		} else {
			$file_path = get_attached_file( $image_id );
		}

		// Photos that only live in cloud storage: pull the original down to a
		// temp file just long enough to read it.
		$temp_file         = '';
		$cloud_storage_key = get_post_meta( $image_id, 'sunshine_cloud_storage_key', true );
		if ( ( empty( $file_path ) || ! file_exists( $file_path ) ) && ! empty( $cloud_storage_key ) && class_exists( 'Sunshine_Cloud_Storage_Client' ) ) {
			$temp_file = wp_tempnam( basename( $cloud_storage_key ) );
			if ( ! $temp_file || ! Sunshine_Cloud_Storage_Client::instance()->download_file( $cloud_storage_key, $temp_file ) ) {
				if ( $temp_file ) {
					wp_delete_file( $temp_file );
				}
				SPC()->log( 'Refresh Photo Details: could not download ' . $cloud_storage_key . ' for image ' . $image_id );
				return array(
					'ok'       => false,
					'image_id' => (int) $image_id,
					'file'     => $image->get_name(),
					'keywords' => '',
					'error'    => __( 'Could not download the original file from cloud storage', 'sunshine-photo-cart' ),
				);
			}
			$file_path = $temp_file;
		}

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			return array(
				'ok'       => false,
				'image_id' => (int) $image_id,
				'file'     => $image->get_name(),
				'keywords' => '',
				'error'    => __( 'Could not find the original file', 'sunshine-photo-cart' ),
			);
		}

		$image_meta = wp_read_image_metadata( $file_path );

		if ( $temp_file ) {
			wp_delete_file( $temp_file );
		}

		if ( ! is_array( $image_meta ) ) {
			return array(
				'ok'       => false,
				'image_id' => (int) $image_id,
				'file'     => $image->get_name(),
				'keywords' => '',
				'error'    => __( 'Could not read details from the original file', 'sunshine-photo-cart' ),
			);
		}

		$metadata = wp_get_attachment_metadata( $image_id );
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}
		$metadata = sunshine_apply_image_meta( $image_id, $image_meta, $metadata );
		wp_update_attachment_metadata( $image_id, $metadata );

		SPC()->log( 'Refresh Photo Details: refreshed image ' . $image_id );

		return array(
			'ok'       => true,
			'image_id' => (int) $image_id,
			'file'     => get_the_title( $image_id ),
			'keywords' => ! empty( $image_meta['keywords'] ) && is_array( $image_meta['keywords'] ) ? implode( ', ', $image_meta['keywords'] ) : '',
			'error'    => null,
		);
	}

	protected function do_process() {
		$gallery_id = ( isset( $_GET['sunshine_gallery'] ) ) ? intval( wp_unslash( $_GET['sunshine_gallery'] ) ) : 0;
		$gallery    = $gallery_id ? sunshine_get_gallery( $gallery_id ) : false;

		if ( ! $gallery ) {
			echo '<p>' . esc_html__( 'To run this, edit a gallery and use the "Refresh photo details" button below its images.', 'sunshine-photo-cart' ) . '</p>';
			return;
		}

		$count = $this->count_remaining( $gallery_id );
		/* translators: %s is the gallery title */
		$title = sprintf( __( 'Refreshing photo details for "%s"', 'sunshine-photo-cart' ), $gallery->get_name() );
		?>
		<h3><?php echo esc_html( $title ); ?>...</h3>
		<div id="sunshine-progress-bar" style="">
			<div id="sunshine-percentage" style=""></div>
			<div id="sunshine-processed" style="">
				<span id="sunshine-processed-count">0</span> / <span id="processed-total"><?php echo esc_html( $count ); ?></span>
			</div>
		</div>
		<p align="center" id="abort"><a href="<?php echo esc_url( get_edit_post_link( $gallery_id, 'raw' ) ); ?>"><?php esc_html_e( 'Abort', 'sunshine-photo-cart' ); ?></a></p>
		<ul id="results"></ul>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			var processed = 0;
			var total = <?php echo esc_js( $count ); ?>;
			var percent = 0;
			if ( total === 0 ) {
				$( '#abort' ).hide();
				$( '#sunshine-progress-bar' ).addClass( 'done' );
				$( '#sunshine-processed' ).html( '<?php echo esc_js( __( 'Done!', 'sunshine-photo-cart' ) ); ?>' );
				return;
			}
			function sunshine_refresh_photo_details( item_number ) {
				var data = {
					'action': 'sunshine_refresh_photo_details',
					'gallery': '<?php echo esc_js( $gallery_id ); ?>',
					'item_number': item_number,
					'security': "<?php echo esc_js( wp_create_nonce( 'sunshine_refresh_photo_details' ) ); ?>"
				};
				$.postq( 'sunshinerefreshphotodetails', ajaxurl, data, function(response) {
					var $link = $( '<a>' ).attr( 'href', 'post.php?action=edit&post=' + response.image_id ).text( response.file );
					var $li = $( '<li>' ).append( $link );
					if ( response.error ) {
						$link.css( 'color', 'red' );
						$li.append( document.createTextNode( ': ' + response.error ) );
					} else if ( response.keywords ) {
						$li.append( document.createTextNode( ': ' + response.keywords ) );
					}
					$( '#results' ).prepend( $li );
				}).always(function(){
					processed++;
					if ( processed >= total ) {
						$( '#abort' ).hide();
						$( '#sunshine-progress-bar' ).addClass( 'done' );
						$( '#sunshine-processed' ).html( '<?php echo esc_js( __( 'Done!', 'sunshine-photo-cart' ) ); ?>' );
					}
					$( '#sunshine-processed-count' ).html( processed );
					percent = Math.round( ( processed / total ) * 100 );
					$( '#sunshine-percentage' ).css( 'width', percent + '%' );
				});
			}
			for (i = 0; i < total; i++) {
				sunshine_refresh_photo_details( i );
			}
		});
		</script>
		<?php
	}

	/**
	 * Admin AJAX endpoint, one photo per request.
	 */
	function refresh_photo_details() {
		if ( ! isset( $_REQUEST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['security'] ) ), 'sunshine_refresh_photo_details' ) || ! current_user_can( 'sunshine_manage_options' ) ) {
			wp_send_json_error();
		}

		set_time_limit( 600 );

		$item_number = isset( $_POST['item_number'] ) ? intval( $_POST['item_number'] ) : 0;
		$gallery_id  = ( ! empty( $_POST['gallery'] ) ) ? intval( wp_unslash( $_POST['gallery'] ) ) : 0;

		$image_ids = array_slice( $this->get_gallery_image_ids( $gallery_id ), $item_number, 1 );
		if ( empty( $image_ids ) ) {
			wp_send_json(
				array(
					'status'   => 'error',
					'file'     => '',
					'image_id' => 0,
					'error'    => __( 'No photo found at this position. It may have been deleted since this run started.', 'sunshine-photo-cart' ),
				)
			);
		}

		$result = $this->refresh_one( (int) reset( $image_ids ) );

		wp_send_json(
			array(
				'status'   => $result['ok'] ? 'success' : 'error',
				'file'     => $result['file'],
				'image_id' => $result['image_id'],
				'keywords' => $result['keywords'],
				'error'    => $result['error'],
			)
		);
	}

}

$spc_tool_refresh_photo_details = new SPC_Tool_Refresh_Photo_Details();
