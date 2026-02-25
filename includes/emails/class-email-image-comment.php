<?php
class SPC_Email_Image_Comment extends SPC_Email {

	function init() {

		$this->id          = 'image-comment';
		$this->class       = get_class( $this );
		$this->name        = __( 'Image Comment (Admin)', 'sunshine-photo-cart' );
		$this->description = __( 'Comment notification to admin', 'sunshine-photo-cart' );
		/* translators: %1$s is the customer name, %2$s is the site name */
		$this->subject           = sprintf( __( 'A new comment by %1$s on %2$s', 'sunshine-photo-cart' ), '[name]', '[sitename]' );
		$this->custom_recipients = true;

		$this->add_search_replace(
			array(
				'name'         => '',
				'image_name'   => '',
				'gallery_name' => '',
			)
		);

		add_action( 'sunshine_add_comment', array( $this, 'trigger' ), 10, 2 );

	}

	public function trigger( $comment, $image ) {

		$this->set_template( $this->id );
		$this->set_subject( $this->get_subject() );

		$args = array(
			'comment' => $comment,
			'image'   => $image,
		);
		$this->add_args( $args );

		$search_replace = array(
			'name' => $comment->comment_author,
		);
		$this->add_search_replace( $search_replace );

		// Send email
		$result = $this->send();

	}

}
