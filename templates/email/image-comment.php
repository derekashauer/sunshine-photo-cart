<div style="text-align: center;">
	<?php echo wp_kses_post( $image->output() ); ?>
	<div><?php echo esc_html( $image->get_name() ); ?></div>
</div>

<p><strong><?php echo esc_html( $comment->comment_author ); ?></strong> @ <?php echo esc_html( $comment->comment_date ); ?></p>
<?php echo wp_kses_post( wpautop( $comment->comment_content ) ); ?>

<p align="center">
	<a href="<?php echo esc_url( admin_url( 'edit-comments.php?p=' . $comment->comment_post_ID ) ); ?>" class="button"><?php esc_html_e( 'Manage comments on this image', 'sunshine-photo-cart' ); ?></a>
</p>
