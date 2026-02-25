<span class="sunshine--checkout--discount-applied">
	<?php
	if ( $discount->is_auto() ) {
		echo esc_html( $discount->get_name() );
	} else {
		echo esc_html( $discount->get_code() );
		?>
	<button type="button" data-id="<?php echo esc_attr( $discount->get_code() ); ?>"><?php esc_html_e( '×', 'sunshine-photo-cart' ); ?></button>
	<?php } ?>
</span>
