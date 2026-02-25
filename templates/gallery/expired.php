<div id="sunshine--gallery--expired">
	<?php /* translators: %1$s is the gallery name and %2$s is the gallery expiration date */ ?>
	<?php echo wp_kses_post( sprintf( __( 'The gallery %1$s expired on %2$s and is no longer accessible', 'sunshine-photo-cart' ), $gallery->get_name(), $gallery->get_expiration_date_formatted() ) ); ?>
</div>
