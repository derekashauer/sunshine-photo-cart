<?php
class SPC_Background_Delete_Gallery_Images extends SPC_Background_Process {

	/**
	 * @var string
	 */
	protected $action = 'delete_gallery_images';

	/**
	 * Task
	 *
	 * Override this method to perform any actions required on each
	 * queue item. Return the modified item for further processing
	 * in the next pass through. Or, return false to remove the
	 * item from the queue.
	 *
	 * @param mixed $item Queue item to iterate over
	 *
	 * @return mixed
	 */
	protected function task( $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
		SPC()->log( 'Attachment ' . $attachment_id . ' has been deleted from gallery deletion' );
		return false;
	}

	/**
	 * Complete
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 */
	protected function complete() {

		parent::complete();

		// Delete the holding folder as well.

		$upload_dir  = wp_upload_dir();
		$folder_path = $upload_dir['basedir'] . '/sunshine';
		$dir         = opendir( $folder_path );

		// Loop through each item in the directory
		while ( ( $sub_folder = readdir( $dir ) ) !== false ) {
			// Skip '.' and '..' directories
			if ( $sub_folder !== '.' && $sub_folder !== '..' ) {
				$sub_folder_path = $folder_path . DIRECTORY_SEPARATOR . $sub_folder;

				// Check if it's a directory
				if ( is_dir( $sub_folder_path ) && ctype_digit( $sub_folder ) ) {
					// Check if the subfolder is now empty and delete it
					if ( count( scandir( $sub_folder_path ) ) === 2 ) { // 2 means only '.' and '..' exist
						rmdir( $sub_folder_path );
					}
				}
			}
		}

		// Close the directory
		closedir( $dir );

	}

}
