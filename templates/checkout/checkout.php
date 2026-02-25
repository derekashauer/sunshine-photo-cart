<div id="sunshine--checkout">
	<?php do_action( 'sunshine_checkout_start_form' ); ?>

	<div id="sunshine--checkout--main">
		<div id="sunshine--checkout--steps">
			<form method="post" action="<?php echo esc_url( sunshine_get_page_permalink( 'checkout' ) ); ?>" class="sunshine--form" id="sunshine--checkout--form">
				<?php sunshine_get_template( 'checkout/steps' ); ?>
			</form>
		</div>
		<div id="sunshine--checkout--summary">
			<?php sunshine_get_template( 'checkout/summary' ); ?>
		</div>
	</div>

	<?php do_action( 'sunshine_checkout_end_form' ); ?>

</div>
