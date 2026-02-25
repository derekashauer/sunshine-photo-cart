<div id="sunshine--order--details">
	<div id="sunshine--order--data">
		<div class="sunshine--order--data--item">
			<div class="sunshine--order--data--label"><?php esc_html_e( 'Customer', 'sunshine-photo-cart' ); ?></div>
			<div class="sunshine--order--data--value"><?php echo esc_html( $order->get_customer_name() ); ?></div>
		</div>
		<div class="sunshine--order--data--item">
			<div class="sunshine--order--data--label"><?php esc_html_e( 'Email', 'sunshine-photo-cart' ); ?></div>
			<div class="sunshine--order--data--value"><?php echo esc_html( $order->get_email() ); ?></div>
		</div>
		<?php if ( $order->get_phone() ) { ?>
			<div class="sunshine--order--data--item">
				<div class="sunshine--order--data--label"><?php esc_html_e( 'Phone', 'sunshine-photo-cart' ); ?></div>
				<div class="sunshine--order--data--value"><?php echo esc_html( $order->get_phone() ); ?></div>
			</div>
		<?php } ?>
		<div class="sunshine--order--data--item">
			<div class="sunshine--order--data--label"><?php esc_html_e( 'Date', 'sunshine-photo-cart' ); ?></div>
			<div class="sunshine--order--data--value"><?php echo esc_html( $order->get_date() ); ?></div>
		</div>
		<div class="sunshine--order--data--item">
			<div class="sunshine--order--data--label"><?php esc_html_e( 'Payment Method', 'sunshine-photo-cart' ); ?></div>
			<div class="sunshine--order--data--value"><?php echo esc_html( $order->get_payment_method_name() ); ?></div>
		</div>
		<?php if ( $order->get_delivery_method_name() ) { ?>
			<div class="sunshine--order--data--item">
				<div class="sunshine--order--data--label"><?php esc_html_e( 'Delivery', 'sunshine-photo-cart' ); ?></div>
				<div class="sunshine--order--data--value">
					<?php if ( $order->get_shipping_method_name() ) { ?>
						<?php echo esc_html( $order->get_shipping_method_name() ); ?>
					<?php } else { ?>
						<?php echo esc_html( $order->get_delivery_method_name() ); ?>
					<?php } ?>
				</div>
			</div>
		<?php } ?>
		<?php if ( $order->get_vat() ) { ?>
			<div class="sunshine--order--data--item">
				<div class="sunshine--order--data--label"><?php echo ( SPC()->get_option( 'vat_label' ) ) ? esc_html( SPC()->get_option( 'vat_label' ) ) : esc_html__( 'EU VAT Number', 'sunshine-photo-cart' ); ?></div>
				<div class="sunshine--order--data--value"><?php echo esc_html( $order->get_vat() ); ?></div>
			</div>
		<?php } ?>
	</div>
	<?php if ( $order->has_shipping_address() ) { ?>
	<div id="sunshine--order--shipping">
		<h3><?php esc_html_e( 'Shipping', 'sunshine-photo-cart' ); ?></h3>
			<address><?php echo wp_kses_post( $order->get_shipping_address_formatted() ); ?></address>
	</div>
	<?php } ?>
	<?php if ( $order->has_billing_address() ) { ?>
	<div id="sunshine--order--billing">
		<h3><?php esc_html_e( 'Billing', 'sunshine-photo-cart' ); ?></h3>
		<address><?php echo wp_kses_post( $order->get_billing_address_formatted() ); ?></address>
	</div>
	<?php } ?>

	<?php if ( $order->get_customer_notes() ) { ?>
		<div id="sunshine--order--notes">
			<h3><?php esc_html_e( 'Notes', 'sunshine-photo-cart' ); ?></h3>
			<?php echo wp_kses_post( $order->get_customer_notes() ); ?>
		</div>
	<?php } ?>

</div>
