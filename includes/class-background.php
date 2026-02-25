<?php
class SPC_Background_Processing {


	protected $delete_gallery_images;
	protected $process_images;

	/**
	 * Example_Background_Processing constructor.
	 */
	public function __construct() {
		add_action( 'sunshine_before_init', array( $this, 'init' ), 1 );
		add_action( 'before_delete_post', array( $this, 'delete_gallery_images' ), 10, 2 );

		// Also initialize on init hook as fallback for AJAX requests
		add_action( 'init', array( $this, 'init' ), 1 );
	}

	/**
	 * Init
	 */
	public function init() {
		// Only initialize delete_gallery_images once
		if ( ! $this->delete_gallery_images ) {
			require_once SUNSHINE_PHOTO_CART_PATH . 'includes/background/delete-gallery-images.php';
			$this->delete_gallery_images = new SPC_Background_Delete_Gallery_Images();
		}

		// Initialize process_images if option is enabled and not already initialized
		if ( ! $this->process_images && SPC()->get_option( 'delay_image_processing' ) ) {
			require_once SUNSHINE_PHOTO_CART_PATH . 'includes/background/process-images.php';
			$this->process_images = new SPC_Background_Process_Images();
		}
	}


	/**
	 * Get process images instance
	 *
	 * @return SPC_Background_Process_Images|null
	 */
	public function get_process_images() {
		if ( ! $this->process_images && SPC()->get_option( 'delay_image_processing' ) ) {
			require_once SUNSHINE_PHOTO_CART_PATH . 'includes/background/process-images.php';
			$this->process_images = new SPC_Background_Process_Images();
		}
		return $this->process_images;
	}

	public function delete_gallery_images( $post_id, $post ) {

		if ( $post->post_type != 'sunshine-gallery' || ! SPC()->get_option( 'delete_images' ) ) {
			return;
		}

		$gallery = sunshine_get_gallery( $post );

		// Delete the source FTP folder if it exists.
		if ( SPC()->get_option( 'delete_images_folder' ) && $gallery->get_image_directory() ) {
			$folder_path = sunshine_get_import_directory() . '/' . $gallery->get_image_directory();
			if ( is_dir( $folder_path ) ) {
				$files = array_merge( glob( $folder_path . '/.*' ), glob( $folder_path . '/*' ) );
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						wp_delete_file( $file );
					}
				}
				rmdir( $folder_path );
			}
		}

		$image_ids = $gallery->get_image_ids();
		if ( empty( $image_ids ) ) {
			return;
		}

		SPC()->log( $gallery->get_name() . ' images are being queued for deletion in background' );

		// $this->delete_gallery_images->data( $image_ids );
		foreach ( $image_ids as $image_id ) {
			$this->delete_gallery_images->push_to_queue( $image_id );
		}

		// $this->delete_gallery_images->data( array( 'gallery_id' => $post_id ) );

		$this->delete_gallery_images->save()->dispatch();

	}

}

$GLOBALS['sunshine_background_processing'] = new SPC_Background_Processing();
