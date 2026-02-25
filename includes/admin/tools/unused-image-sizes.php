<?php
class SPC_Tool_Unused_Images extends SPC_Tool {

	function __construct() {
		parent::__construct(
			__( 'Unused Image Sizes', 'sunshine-photo-cart' ),
			'unused-image-sizes',
			__( 'Sunshine only needs to generate two image sizes for each: thumbnail and large. Some sites, because of their theme or plugins, have a lot of image sizes generated. This tool will clean them up.', 'sunshine-photo-cart' ),
			__( 'Delete Unused Image Sizes', 'sunshine-photo-cart' )
		);

		add_action( 'wp_ajax_sunshine_delete_unused_image_sizes', array( $this, 'delete_unused_image_sizes' ) );
	}

	protected function do_process() {
		global $wpdb;

		// Count images using WP_Query for better WordPress integration.
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
		$count = $query->found_posts;
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

	function delete_unused_image_sizes() {
		global $wpdb;

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
		if ( ! empty( $o ) ) {
			$object = $o[0];

			// Get the attachment metadata
			$metadata      = wp_get_attachment_metadata( $object->ID );
			$files_removed = array();

			if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				// Loop through each image size
				foreach ( $metadata['sizes'] as $size => $info ) {
					// Check if the size name does not start with "sunshine-"
					if ( strpos( $size, 'sunshine-' ) !== 0 ) {
						// Get the file path
						$upload_dir = wp_upload_dir();
						$file_path  = $upload_dir['basedir'] . '/' . dirname( $metadata['file'] ) . '/' . $info['file'];

						// Remove the file
						if ( file_exists( $file_path ) ) {
							wp_delete_file( $file_path );
							SPC()->log( 'Unused image removed: ' . $file_path );
							$files_removed[] = $info['file'];
						}

						// Remove this size from metadata
						unset( $metadata['sizes'][ $size ] );
					}
				}

				// Update the metadata
				wp_update_attachment_metadata( $object->ID, $metadata );
			}

			if ( ! empty( $files_removed ) ) {
				$files = $object->post_title . ': ' . join( ', ', $files_removed );
				wp_send_json_success(
					array(
						'files' => $files,
					)
				);
			}
		}

		exit;
	}


}

new SPC_Tool_Unused_Images();
