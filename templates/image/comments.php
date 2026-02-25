<div id="sunshine--image--comments">
	<div id="sunshine--image--comments--header">
		<div id="sunshine--image--comments--header--image">
			<?php $image->output(); ?>
			<span><?php echo esc_html( $image->get_name() ); ?></span>
		</div>
	</div>

	<div id="sunshine--image--comments--content">
		<div id="sunshine--image--comments--list">
			<?php
			if ( $comments ) {
				foreach ( $comments as $comment ) {
					echo sunshine_get_template_html( 'image/comment', array( 'comment' => $comment ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
			?>
		</div>
		<div id="sunshine--image--comments--add">
			<?php echo sunshine_get_template_html( 'image/add-comment', array( 'image' => $image ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</div>
</div>
