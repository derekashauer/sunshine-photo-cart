<?php
class SPC_Background_Migrate_Keywords extends SPC_Background_Process {

	/**
	 * @var string
	 */
	protected $action = 'migrate_keywords';

	/**
	 * Task
	 *
	 * Extract keywords from _wp_attachment_metadata and save
	 * to dedicated sunshine_keywords post meta for faster search.
	 *
	 * @param int $attachment_id Queue item (attachment ID)
	 *
	 * @return false
	 */
	protected function task( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $metadata['image_meta']['keywords'] ) && is_array( $metadata['image_meta']['keywords'] ) ) {
			$keywords_string = implode( ', ', $metadata['image_meta']['keywords'] );
			update_post_meta( $attachment_id, 'sunshine_keywords', $keywords_string );
		}

		return false;
	}

	/**
	 * Complete
	 */
	protected function complete() {
		parent::complete();
		update_option( 'sunshine_keywords_migrated', time(), false );
		SPC()->log( 'Keyword migration to sunshine_keywords meta completed' );
	}

}
