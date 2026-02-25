<div id="sunshine--image--content">
	<?php sunshine_image_menu(); ?>
	<div id="sunshine--image--content--display"><?php $image->output( apply_filters( 'sunshine_image_large_size', 'sunshine-large' ) ); ?></div>
	<?php do_action( 'sunshine_after_image', $image ); ?>
</div>
