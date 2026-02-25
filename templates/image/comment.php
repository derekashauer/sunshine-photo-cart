<div class="sunshine--image--comment">
	<div class="sunshine--image--comment--author"><?php echo esc_html( $comment->comment_author ); ?></div>
	<div class="sunshine--image--comment--date"><?php echo esc_html( get_comment_date( '', $comment ) ); ?></div>
	<div class="sunshine--image--comment--content"><?php echo wp_kses_post( $comment->comment_content ); ?></div>
	<?php if ( ! $comment->comment_approved ) { ?>
		<div class="sunshine--image--comment--approval"><?php esc_html_e( 'Comment awaiting approval', 'sunshine-photo-cart' ); ?></div>
	<?php } ?>
</div>
