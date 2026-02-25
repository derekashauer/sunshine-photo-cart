<div class="sunshine--order--comment">
	<div class="sunshine--order--comment--data">
		<span class="sunshine--order--comment--author">
			<?php
			if ( $comment->comment_author ) {
				echo esc_html( $comment->comment_author );
			} elseif ( $comment->user_id ) {
				$user = get_user_by( 'id', $comment->user_id );
				echo esc_html( $user->display_name );
			}
			?>
			@
			<?php echo esc_html( $comment->comment_date ); ?>
		</span>
		<span>
	</div>
	<div class="sunshine--order--comment--content">
		<?php echo wp_kses_post( wpautop( $comment->comment_content ) ); ?>
	</div>
</div>
