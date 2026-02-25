<?php
class SPC_Tool_Orphans extends SPC_Tool {

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
		global $wpdb;

		/*
		$sql   = "SELECT COUNT(*) as total FROM {$wpdb->posts} AS p
			INNER JOIN {$wpdb->postmeta} AS pm
				ON p.ID = pm.post_id AND pm.meta_key = 'sunshine_file_name'
			WHERE p.post_parent = 0 AND p.post_type = 'attachment'
			AND pm.meta_value != ''
			ORDER BY p.ID DESC";
		$count = $wpdb->get_row( $sql )->total;
		*/
		$count = 0;

		// Define the path to the parent folder
		$upload_dir         = wp_upload_dir();
		$parent_folder_path = $upload_dir['basedir'] . '/sunshine';

		// Initialize an empty array to hold folder names that don't have a matching post ID
		$orphan_folders = array();

		// Scan the parent folder and get all the sub-folders
		$sub_folders = scandir( $parent_folder_path );

		// Loop through each sub-folder
		foreach ( $sub_folders as $sub_folder ) {
			// Skip the special entries '.' and '..'
			if ( $sub_folder == '.' || $sub_folder == '..' ) {
				continue;
			}

			// Check if the folder name is numeric
			if ( is_numeric( $sub_folder ) ) {
				// Convert the folder name to an integer
				$post_id = intval( $sub_folder );

				// Check if a post with this ID exists
				$post = get_post( $post_id );

				// If the post doesn't exist, add the folder name to the array
				if ( null === $post ) {
					$orphan_folders[] = $sub_folder;
					$count++;
				}
			}
		}

		if ( $count ) {
			echo '<p>';
			/* translators: %s is the number of orphaned folders */
			echo esc_html( sprintf( __( 'Sunshine found %s orphaned folders of images.', 'sunshine-photo-cart' ), $count ) );
			echo ' ';
			/* translators: %s is the site name */
			echo '<strong style="color: red;">';
			esc_html_e( 'It is recommended to make a backup before running this tool. Images will be completely deleted from your server.', 'sunshine-photo-cart' );
			echo '</strong></p>';
			echo '</p>';
		} else {
			echo '<p><em>' . esc_html__( 'No orphans found!', 'sunshine-photo-cart' ) . '</em></p>';
			$this->button_label = '';
		}
	}

	protected function do_process() {
		global $wpdb;

		$upload_dir         = wp_upload_dir();
		$parent_folder_path = $upload_dir['basedir'] . '/sunshine';

		$count = 0;

		// Initialize an empty array to hold folder names that don't have a matching post ID
		$orphan_folders = array();

		// Scan the parent folder and get all the sub-folders
		$sub_folders = scandir( $parent_folder_path );

		// Loop through each sub-folder
		foreach ( $sub_folders as $sub_folder ) {
			// Skip the special entries '.' and '..'
			if ( $sub_folder == '.' || $sub_folder == '..' ) {
				continue;
			}

			// Check if the folder name is numeric
			if ( is_numeric( $sub_folder ) ) {
				// Convert the folder name to an integer
				$post_id = intval( $sub_folder );

				// Check if a post with this ID exists
				$post = get_post( $post_id );

				// If the post doesn't exist, add the folder name to the array
				if ( null === $post ) {
					$orphan_folders[] = $sub_folder;
					$count++;
				}
			}
		}

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

	function clear_orphan() {
		global $wpdb;

		if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine_clear_orphan' ) || ! current_user_can( 'sunshine_manage_options' ) ) {
			wp_send_json_error();
		}

		$upload_dir         = wp_upload_dir();
		$parent_folder_path = $upload_dir['basedir'] . '/sunshine';

		// Scan the parent folder and get all the sub-folders
		$sub_folders = scandir( $parent_folder_path );
		// Loop through each sub-folder
		foreach ( $sub_folders as $sub_folder ) {
			// Skip the special entries '.' and '..'
			if ( $sub_folder == '.' || $sub_folder == '..' ) {
				continue;
			}

			// Check if the folder name is numeric
			if ( is_numeric( $sub_folder ) ) {
				// Convert the folder name to an integer (the post ID)
				$post_id = intval( $sub_folder );

				// Check if a post with this ID exists
				$post = get_post( $post_id );

				// If the post doesn't exist, delete files and the folder
				if ( null === $post ) {
					// Full path to the sub-folder
					$sub_folder_path = $parent_folder_path . '/' . $sub_folder;

					// Get all files in the sub-folder
					$files = array_diff( scandir( $sub_folder_path ), array( '.', '..' ) );

					// Loop through each file and delete it
					foreach ( $files as $file ) {
						unlink( $sub_folder_path . '/' . $file );

						// Get attachment ID using file path
						$attachment_id = attachment_url_to_postid( $sub_folder_path . '/' . $file );

						// If found, delete the attachment post
						if ( $attachment_id ) {
							wp_delete_attachment( $attachment_id, true );
						}
					}

					// Remove the folder itself
					rmdir( $sub_folder_path );

					wp_send_json_success(
						array(
							'folder' => $sub_folder_path,
						)
					);
					exit;

				}
			}
		}
		exit;
	}


}

$spc_tool_orphans = new SPC_Tool_Orphans();
