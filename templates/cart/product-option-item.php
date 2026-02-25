<div class="sunshine--cart-item--product-option">
	<?php
	if ( $option->get_type() == 'checkbox' ) {
		echo esc_html( $option->get_name() );
	} else {
		echo esc_html( $option->get_name() ) . ': ' . esc_html( $option->get_item_name( $option_item_id ) );
	}
	?>
</div>
