<?php
/**
 * Unused-image-sizes cleanup tool.
 *
 * For each Sunshine attachment, removes any non-Sunshine intermediate-size
 * variants (e.g. theme- or plugin-generated thumbnails Sunshine never uses).
 *
 * Per-image work is light — DB metadata read, a few `unlink()`s, metadata
 * update. Default batch size is 25.
 *
 * The admin UI's per-image AJAX handler and the API's `process_batch()`
 * route through the same shared worker (`process_one()`).
 */
class SPC_Tool_Unused_Images extends SPC_Tool {

	protected $is_chunked = true;
	protected $batch_size = 25;

	function __construct() {
		parent::__construct(
			__( 'Unused Image Sizes', 'sunshine-photo-cart' ),
			'unused-image-sizes',
			__( 'Sunshine only needs to generate two image sizes for each: thumbnail and large. Some sites, because of their theme or plugins, have a lot of image sizes generated. This tool will clean them up.', 'sunshine-photo-cart' ),
			__( 'Delete Unused Image Sizes', 'sunshine-photo-cart' )
		);

		add_action( 'wp_ajax_sunshine_delete_unused_image_sizes', array( $this, 'delete_unused_image_sizes' ) );
	}

	public function count_remaining() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'sunshine_file_name',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Process up to $size attachments starting at the caller's `offset`.
	 *
	 * Each call rescans the same total set; the caller advances `offset` to
	 * walk through the queue. (We can't filter to "only attachments that
	 * still have unused sizes" because that information is per-image
	 * metadata, not a queryable column.)
	 */
	public function process_batch( $size = null, $params = array() ) {
		$size   = max( 1, (int) ( $size ?: $this->get_batch_size() ) );
		$offset = isset( $params['offset'] ) ? max( 0, (int) $params['offset'] ) : 0;

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => $size,
				'offset'         => $offset,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'sunshine_file_name',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$processed = 0;
		$log       = array();

		foreach ( (array) $attachments as $attachment_id ) {
			$result = $this->process_one( $attachment_id );
			$processed++;
			if ( ! empty( $result['files'] ) ) {
				$log[] = array(
					'image_id' => $attachment_id,
					'files'    => $result['files'],
				);
			}
		}

		$total = $this->count_remaining();

		return array(
			'processed'   => $processed,
			'remaining'   => max( 0, $total - ( $offset + count( (array) $attachments ) ) ),
			'next_offset' => $offset + count( (array) $attachments ),
			'log'         => $log,
			'errors'      => array(),
		);
	}

	/**
	 * Per-attachment worker. Strips every non-`sunshine-*` intermediate
	 * size's file + metadata entry. Used by both transports.
	 *
	 * Visibility note: `protected` so external callers can't bypass the
	 * cap check the AJAX handler and `process_batch()` enforce.
	 *
	 * @return array{files:string[]} Files removed (basenames only).
	 */
	protected function process_one( $attachment_id ) {
		$object = get_post( $attachment_id );
		if ( ! $object ) {
			return array( 'files' => array() );
		}

		$metadata      = wp_get_attachment_metadata( $object->ID );
		$files_removed = array();

		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size => $info ) {
				if ( strpos( $size, 'sunshine-' ) !== 0 ) {
					$upload_dir = wp_upload_dir();
					$file_path  = $upload_dir['basedir'] . '/' . dirname( $metadata['file'] ) . '/' . $info['file'];

					if ( file_exists( $file_path ) ) {
						wp_delete_file( $file_path );
						SPC()->log( 'Unused image removed: ' . $file_path );
						$files_removed[] = $info['file'];
					}

					unset( $metadata['sizes'][ $size ] );
				}
			}

			wp_update_attachment_metadata( $object->ID, $metadata );
		}

		return array( 'files' => $files_removed );
	}

	protected function do_process() {
		$count = $this->count_remaining();
		?>
		<h3>Checking all images in galleries</h3>
		<p>This tool is checking every image that has been uploaded to a gallery. Any unused images found and removed will be listed below.</p>
		<div id="progress-bar" style="background: #000; height: 30px; position: relative;">
			<div id="percentage" style="height: 30px; background-color: green; width: 0%;"></div>
			<div id="processed" style="position: absolute; top: 0; left: 0; width: 100%; color: #FFF; text-align: center; font-size: 18px; height: 30px; line-height: 30px;">
				<span id="processed-count">0</span> / <span id="processed-total"><?php echo esc_html( $count ); ?></span>
			</div>
		</div>
		<p align="center" id="abort"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine-tools' ) ); ?>" class="button"><?php esc_html_e( 'Abort', 'sunshine-photo-cart' ); ?></a></p>
		<p align="center" id="return" style="display:none;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine-tools' ) ); ?>" class="button"><?php esc_html_e( 'Return to tools', 'sunshine-photo-cart' ); ?></a></p>
		<ol id="results"></ol>
		<script type="text/javascript">
		jQuery( document ).ready(function($) {
			var processed = 0;
			var total = <?php echo esc_js( $count ); ?>;
			var percent = 0;
			var has_unused = false;
			function sunshine_delete_unused( item_number ) {
				var data = {
					'action': 'sunshine_delete_unused_image_sizes',
					'item_number': item_number,
					'security': "<?php echo esc_js( wp_create_nonce( 'sunshine_delete_unused_image_sizes' ) ); ?>"
				};
				$.postq( 'sunshinedeleteunused', ajaxurl, data, function(response) {
					processed++;
					if ( processed >= total ) {
						$( '#abort' ).hide();
						$( '#return' ).show();
						if ( ! has_unused ) {
							$( '#return' ).after( '<p>No images were removed</p>' );
						}
					}
					$( '#processed-count' ).html( processed );
					percent = Math.round( ( processed / total ) * 100);
					$( '#percentage' ).css( 'width', percent+'%' );
					if ( response.success ) {
						has_unused = true;
						$( '#results' ).append( '<li>' + response.data.files + '</li>' );
					}
				}).fail( function( jqXHR ) {
					if ( jqXHR.status == 500 || jqXHR.status == 0 ){
						$( '#results' ).append( '<li><strong><?php esc_js( __( 'Cannot process image, likely out of memory', 'sunshine-photo-cart' ) ); ?></strong></li>' );
					}
				});
			}
			for (i = 0; i < total; i++) {
				sunshine_delete_unused( i );
			}
		});
		</script>

		<?php
	}

	/**
	 * Admin AJAX handler — preserved 1:1 with the existing JS contract.
	 * Routes per-image work through `process_one()` (shared with the
	 * REST batch path).
	 */
	function delete_unused_image_sizes() {
		if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine_delete_unused_image_sizes' ) || ! current_user_can( 'sunshine_manage_options' ) ) {
			wp_send_json_error();
		}

		$o = get_posts(
			array(
				'post_type'      => 'attachment',
				'posts_per_page' => 1,
				'offset'         => intval( $_POST['item_number'] ),
				'post_status'    => 'any',
				'meta_query'     => array(
					array(
						'key'     => 'sunshine_file_name',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		if ( empty( $o ) ) {
			exit;
		}

		$result = $this->process_one( $o[0]->ID );

		if ( ! empty( $result['files'] ) ) {
			$files = $o[0]->post_title . ': ' . join( ', ', $result['files'] );
			wp_send_json_success( array( 'files' => $files ) );
		}

		exit;
	}


}

new SPC_Tool_Unused_Images();
