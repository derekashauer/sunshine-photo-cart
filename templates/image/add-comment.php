<form method="post" action="" id="sunshine--image--comments--add-form">
	<?php wp_nonce_field( 'sunshine_image_comment_nonce', 'sunshine_image_comment_nonce' ); ?>
	<input type="hidden" name="sunshine_image_id" value="<?php echo esc_attr( $image->get_id() ); ?>" />
	<div id="sunshine--image--comments--add--title">Add Comment</div>
	<?php if ( ! is_user_logged_in() ) { ?>
		<div class="sunshine--image--comments--add--field">
			<label for="sunshine-comment-name"><?php esc_html_e( 'Name', 'sunshine-photo-cart' ); ?></label>
			<input type="text" name="sunshine_comment_name" id="sunshine-comment-name" value="<?php echo esc_attr( SPC()->customer->get_display_name() ); ?>" required="required" />
		</div>
		<div class="sunshine--image--comments--add--field">
			<label for="sunshine-comment-email"><?php esc_html_e( 'E-mail address', 'sunshine-photo-cart' ); ?></label>
			<input type="email" name="sunshine_comment_email" id="sunshine-comment-email" value="<?php echo esc_attr( SPC()->customer->get_email() ); ?>" required="required" />
		</div>
	<?php } ?>
	<div class="sunshine--image--comments--add--field">
		<label for="sunshine-comment-content"><?php esc_html_e( 'Comments', 'sunshine-photo-cart' ); ?></label>
		<textarea name="sunshine_comment_content" id="sunshine-comment-content" required="required"></textarea>
	</div>
	<div class="sunshine--image--comments--add--submit">
		<button type="submit" class="sunshine--button"><?php esc_html_e( 'Submit Comment', 'sunshine-photo-cart' ); ?></button>
	</div>
</form>
