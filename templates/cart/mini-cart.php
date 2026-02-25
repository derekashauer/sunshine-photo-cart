<div class="sunshine--mini-cart" aria-live="polite">
<?php
if ( ! SPC()->cart->is_empty() ) {
	?>
	<a href="<?php echo esc_url( sunshine_get_page_permalink( 'cart' ) ); ?>" aria-label="<?php esc_html_e( 'View cart', 'sunshine-photo-cart' ); ?>">
		<span class="sunshine--mini-cart--label"><?php esc_html_e( 'Cart', 'sunshine-photo-cart' ); ?>:</span> 
		<?php /* translators: %s is the number of items */ ?>
		<span class="sunshine--mini-cart--quantity"><?php echo wp_kses_post( sprintf( _n( '%s item', '%s items', SPC()->cart->get_item_count(), 'sunshine-photo-cart' ), '<span class="sunshine--mini-cart--quantity--count">' . SPC()->cart->get_item_count() . '</span>' ) ); ?></span> 
		<span class="sunshine--mini-cart--separator">&middot;</span> 
		<span class="sunshine--mini-cart--total"><?php echo wp_kses_post( SPC()->cart->get_subtotal_formatted() ); ?></span>
	</a>
	<?php
} else {
	echo '<div class="sunshine--mini-cart--empty">' . esc_html__( 'Your cart is empty', 'sunshine-photo-cart' ) . '</div>';
}
?>
</div>
