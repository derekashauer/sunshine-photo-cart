<?php
$size_info = $gallery->featured_image_size_info();
?>
<figure class="sunshine--gallery-item <?php echo esc_attr( $gallery->classes() ); ?>" id="sunshine--gallery-<?php echo esc_attr( $gallery->get_id() ); ?>"
																 <?php
																	if ( ! empty( $size_info ) ) {
																		?>
	 style="--width:<?php echo esc_attr( $size_info['width'] ); ?>;--height:<?php echo esc_attr( $size_info['height'] ); ?>"<?php } ?>>

	<?php do_action( 'sunshine_before_gallery_item', $gallery ); ?>

	<a href="<?php echo esc_url( $gallery->get_permalink() ); ?>"><?php echo wp_kses_post( $gallery->featured_image() ); ?></a>
	<h2><a href="<?php echo esc_url( $gallery->get_permalink() ); ?>"><?php echo esc_html( $gallery->get_name() ); ?></a></h2>

	<?php do_action( 'sunshine_after_gallery_item', $gallery ); ?>

</figure>
