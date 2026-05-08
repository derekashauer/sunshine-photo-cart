<?php
/**
 * Orphaned-image cleanup tool.
 *
 * Looks for `wp-content/uploads/sunshine/{post_id}/` folders whose post_id
 * no longer corresponds to a real post (gallery was deleted but the folder
 * lingered) and removes them along with any contained files.
 *
 * Two execution paths share the same per-folder worker (`delete_orphan_folder`):
 *
 *   - Admin UI: `do_process()` renders a progress bar + JS that fires a
 *     per-folder AJAX request against `clear_orphan` (one folder per request,
 *     legacy behavior).
 *   - REST API: `count_remaining()` + `process_batch()` let an external
 *     caller drive the cleanup in batches of `batch_size` folders per call.
 *     Default 25 because each step is cheap (file ops + a tiny DB lookup).
 */
class SPC_Tool_Orphans extends SPC_Tool {

	protected $is_chunked = true;
	protected $batch_size = 25;

	function __construct() {
		parent::__construct(
			__( 'Orphaned Images', 'sunshine-photo-cart' ),
			'orphans',
			__( 'Sometimes when deleting galleries the associated images are not fully deleted. This tool will remove those orphaned images to help reduce file storage.', 'sunshine-photo-cart' ),
			__( 'Delete orphaned images', 'sunshine-photo-cart' )
		);

		add_action( 'wp_ajax_sunshine_clear_orphan', array( $this, 'clear_orphan' ) );
	}

	function pre_process() {
		$count = $this->count_remaining();

		if ( $count ) {
			echo '<p>';
			/* translators: %s is the number of orphaned folders */
			echo esc_html( sprintf( __( 'Sunshine found %s orphaned folders of images.', 'sunshine-photo-cart' ), $count ) );
			echo ' ';
			echo '<strong style="color: red;">';
			esc_html_e( 'It is recommended to make a backup before running this tool. Images will be completely deleted from your server.', 'sunshine-photo-cart' );
			echo '</strong></p>';
			echo '</p>';
		} else {
			echo '<p><em>' . esc_html__( 'No orphans found!', 'sunshine-photo-cart' ) . '</em></p>';
			$this->button_label = '';
		}
	}

	/**
	 * REST-API path. Counts orphan folders without deleting anything.
	 */
	public function count_remaining() {
		return count( $this->find_orphan_folders() );
	}

	/**
	 * REST-API path. Deletes up to $size orphan folders. Subsequent calls
	 * pick up where this one left off because each call rescans for orphans.
	 */
	public function process_batch( $size = null, $params = array() ) {
		$size      = max( 1, (int) ( $size ?: $this->get_batch_size() ) );
		$orphans   = array_slice( $this->find_orphan_folders(), 0, $size );
		$processed = 0;
		$log       = array();
		$errors    = array();

		foreach ( $orphans as $folder ) {
			$result = $this->delete_orphan_folder( $folder );
			if ( $result['ok'] ) {
				$processed++;
				$log[] = $result['folder'];
			} else {
				$errors[] = array(
					'folder' => $result['folder'],
					'error'  => $result['error'],
				);
			}
		}

		return array(
			'processed'   => $processed,
			'remaining'   => count( $this->find_orphan_folders() ),
			// Orphan list shrinks as we delete, so the next batch always
			// starts from the front. No cursor needed.
			'next_offset' => 0,
			'log'         => $log,
			'errors'      => $errors,
		);
	}

	/**
	 * Find every numeric subfolder under uploads/sunshine/ whose post_id no
	 * longer resolves to a real post. Pure read — no side effects.
	 */
	private function find_orphan_folders() {
		$upload_dir         = wp_upload_dir();
		$parent_folder_path = $upload_dir['basedir'] . '/sunshine';
		$orphans            = array();

		if ( ! is_dir( $parent_folder_path ) ) {
			return $orphans;
		}

		$sub_folders = scandir( $parent_folder_path );
		foreach ( $sub_folders as $sub_folder ) {
			if ( '.' === $sub_folder || '..' === $sub_folder ) {
				continue;
			}
			if ( ! is_numeric( $sub_folder ) ) {
				continue;
			}
			if ( null === get_post( intval( $sub_folder ) ) ) {
				$orphans[] = $sub_folder;
			}
		}

		return $orphans;
	}

	/**
	 * Worker: delete one orphan folder and any attachments inside.
	 * Used by both the admin AJAX handler and the REST batch path.
	 *
	 * @param string $folder Numeric folder name under uploads/sunshine/.
	 * @return array{ok:bool,folder:string,error?:string}
	 */
	private function delete_orphan_folder( $folder ) {
		$upload_dir         = wp_upload_dir();
		$parent_folder_path = $upload_dir['basedir'] . '/sunshine';
		$sub_folder_path    = $parent_folder_path . '/' . $folder;

		if ( ! is_dir( $sub_folder_path ) ) {
			return array( 'ok' => false, 'folder' => $sub_folder_path, 'error' => 'directory_missing' );
		}

		$files = array_diff( scandir( $sub_folder_path ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$file_path = $sub_folder_path . '/' . $file;
			@unlink( $file_path );
			$attachment_id = attachment_url_to_postid( $file_path );
			if ( $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}
		@rmdir( $sub_folder_path );

		return array( 'ok' => true, 'folder' => $sub_folder_path );
	}

	protected function do_process() {
		$count = $this->count_remaining();
		?>
		<div id="progress-bar" style="background: #000; height: 30px; position: relative;">
			<div id="percentage" style="height: 30px; background-color: green; width: 0%;"></div>
			<div id="processed" style="position: absolute; top: 0; left: 0; width: 100%; color: #FFF; text-align: center; font-size: 18px; height: 30px; line-height: 30px;">
				<span id="processed-count">0</span> / <span id="processed-total"><?php echo esc_html( $count ); ?></span>
			</div>
		</div>
		<p align="center" id="abort"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine-tools' ) ); ?>"><?php esc_html_e( 'Abort', 'sunshine-photo-cart' ); ?></a></p>
		<ol id="results"></ol>
		<script type="text/javascript">
		jQuery( document ).ready(function($) {
			var processed = 0;
			var total = <?php echo esc_js( $count ); ?>;
			var percent = 0;
			function sunshine_clear_orphan( item_number ) {
				var data = {
					'action': 'sunshine_clear_orphan',
					'item_number': item_number,
					'security': "<?php echo esc_js( wp_create_nonce( 'sunshine_clear_orphan' ) ); ?>"
				};
				$.postq( 'sunshineclearorphan', ajaxurl, data, function(response) {
					processed++;
					if ( processed >= total ) {
						$( '#abort' ).hide();
						$( '#return' ).show();
					}
					$( '#processed-count' ).html( processed );
					percent = Math.round( ( processed / total ) * 100);
					$( '#percentage' ).css( 'width', percent+'%' );
					if ( !response.success ) {
						$( '#results' ).append( '<li style="color: red;">' + response.data.file + ': ' + response.data.error + '</li>' );
					} else {
						$( '#results' ).append( '<li>"' + response.data.folder + '" removed</li>' );
					}
				}).fail( function( jqXHR ) {
					if ( jqXHR.status == 500 || jqXHR.status == 0 ){
						$( '#results' ).append( '<li><strong><?php esc_js( __( 'Cannot process image, likely out of memory', 'sunshine-photo-cart' ) ); ?></strong></li>' );
					}
				});
			}
			for (i = 1; i <= total; i++) {
				sunshine_clear_orphan( i );
			}
		});
		</script>

		<?php
	}

	/**
	 * Admin AJAX handler — preserved as-is for back-compat with the existing
	 * progress-bar JS. Routes the per-folder call through the same worker
	 * the REST batch path uses.
	 */
	function clear_orphan() {
		if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine_clear_orphan' ) || ! current_user_can( 'sunshine_manage_options' ) ) {
			wp_send_json_error();
		}

		$orphans = $this->find_orphan_folders();
		if ( empty( $orphans ) ) {
			wp_send_json_success( array( 'folder' => '' ) );
			exit;
		}

		$result = $this->delete_orphan_folder( $orphans[0] );
		if ( $result['ok'] ) {
			wp_send_json_success( array( 'folder' => $result['folder'] ) );
		} else {
			wp_send_json_error( array( 'file' => $result['folder'], 'error' => $result['error'] ) );
		}
		exit;
	}


}

$spc_tool_orphans = new SPC_Tool_Orphans();
