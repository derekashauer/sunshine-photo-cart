<div class="sunshine--cart-item--product-option">
	<?php
	if ( ! empty( $option['value'] ) ) {
		echo esc_html( $option['name'] ) . ': ' . esc_html( $option['value'] );
	} elseif ( ! empty( $option['name'] ) ) {
		echo esc_html( $option['name'] );
	}
	?>
</div>
