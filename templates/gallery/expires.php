<div class="sunshine--gallery--expires">
	<?php /* translators: %s is the gallery expiration date */ ?>
	<?php echo wp_kses_post( sprintf( __( 'This gallery expires on %s', 'sunshine-photo-cart' ), $gallery->get_expiration_date_formatted() ) ); ?>
</div>
