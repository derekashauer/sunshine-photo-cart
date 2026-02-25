<div id="sunshine--checkout--discounts">
	<form method="post" id="sunshine--checkout--discount-form">
		<input type="text" name="discount" placeholder="<?php esc_attr_e( 'Discount Code or Gift Card', 'sunshine-photo-cart' ); ?>" />
		<button type="submit" class="button button-alt sunshine--button-alt"><?php esc_html_e( 'Apply', 'sunshine-photo-cart' ); ?></button>
	</form>
	<div id="sunshine--checkout--discounts-applied">
		<?php sunshine_get_template( 'checkout/discounts-applied', array( 'discounts_applied' => $discounts_applied ) ); ?>
	</div>
	<?php do_action( 'sunshine_after_discount_form' ); ?>
</div>
