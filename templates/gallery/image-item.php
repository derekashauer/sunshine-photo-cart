<?php
$image_html = '<a href="' . $image->get_permalink() . '">' . $image->output( 'sunshine-thumbnail', false ) . '</a>';
$image_html = apply_filters( 'sunshine_gallery_image_html', $image_html, $image->get_id(), $image->get_image_url() );
$size_info = $image->get_size_info( 'sunshine-thumbnail' );
?>
<figure id="sunshine--image-<?php echo esc_attr( $image->get_id() ); ?>" class="<?php echo esc_attr( sunshine_image_class( $image->get_id(), array( 'sunshine--image-item', false ) ) ); ?>" <?php if ( ! empty( $size_info ) ) { ?>style="--width:<?php echo esc_attr( $size_info['width'] ); ?>;--height:<?php echo esc_attr( $size_info['height'] ); ?><?php } ?>">
	<?php do_action( 'sunshine_before_loop_image_item', $image ); ?>
	<?php echo wp_kses_post( $image_html ); ?>
	<?php if ( ! empty( SPC()->get_option( 'show_image_data' ) ) ) { ?>
		<figcaption class="sunshine--image--name"><?php echo esc_html( $image->get_name() ); ?></figcaption>
	<?php } ?>
	<?php sunshine_image_menu( $image ); ?>
	<?php sunshine_image_status( $image ); ?>
	<?php do_action( 'sunshine_image_thumbnail', $image ); ?>
	<?php do_action( 'sunshine_after_loop_image_item', $image ); ?>
</figure>
