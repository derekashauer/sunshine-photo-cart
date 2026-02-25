<form method="post" action="<?php echo esc_url( sunshine_get_page_permalink( 'cart' ) ); ?>" id="sunshine-cart">

<input type="hidden" name="sunshine_update_cart" value="1" />
<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'sunshine_update_cart' ) ); ?>" />

<?php // do_action( 'sunshine_before_cart_items' ); ?>

<?php sunshine_get_template( 'cart/items', array( 'cart_items' => SPC()->cart->get_cart_items() ) ); ?>

<?php // do_action( 'sunshine_after_cart_items' ); ?>

<div id="sunshine--cart--update-button">
	<input type="submit" disabled value="<?php esc_attr_e( 'Update Cart', 'sunshine-photo-cart' ); ?>" class="sunshine--button-alt button button-alt" />
</div>

<?php
$last_viewed_gallery = SPC()->session->get( 'last_gallery' );
if ( $last_viewed_gallery ) {
	$return_gallery = sunshine_get_gallery( SPC()->session->get( 'last_gallery' ) );
	/* translators: %s is the gallery name */
	echo '<div id="sunshine--cart--gallery-return"><a href="' . esc_url( $return_gallery->get_permalink() ) . '">' . esc_html( sprintf( __( 'Return to %s', 'sunshine-photo-cart' ), $return_gallery->get_name() ) ) . '</a></div>';
}
?>

<?php do_action( 'sunshine_after_cart_form' ); ?>

<div id="sunshine--cart--totals">
	<?php sunshine_get_template( 'cart/totals' ); ?>
	<p id="sunshine--cart--checkout-button"><a href="<?php echo esc_url( sunshine_get_page_permalink( 'checkout' ) ); ?>" class="sunshine--button button"><?php esc_html_e( 'Continue to checkout', 'sunshine-photo-cart' ); ?> &rarr;</a></p>
</div>

</form>
