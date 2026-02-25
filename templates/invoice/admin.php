<html <?php language_attributes(); ?>>
<head>

	<?php /* translators: %s is the order name */ ?>
	<title><?php echo esc_html( apply_filters( 'sunshine_invoice_title', sprintf( __( 'Invoice for %s', 'sunshine-photo-cart' ), $order->get_name() ), $order ) ); ?></title>
	<style type="text/css">

		body, html { margin: 0; padding: 0; }
		body, div, h1, h2, p, td, th { font-family: sans-serif; font-size: 16px; text-align: left; }
		table { margin: 0; padding: 0; border-spacing: 0; border-collapse: separate; }
		table th, table td { vertical-align: top; }

		#sunshine--invoice { max-width: 960px; margin: 0 auto; }

		#sunshine--invoice--actions { text-align: center; padding: 20px 0; background: #EFEFEF; margin: 0 0 30px 0; }
		#sunshine--invoice--actions button { display: inline-block; border-radius: 3px; border: 1px solid #CCC; padding: 5px 15px; font-size: 12px; background: #FFF; text-decoration: none; color: #000; }
		#sunshine--invoice--actions button:hover { border-color: #000; }

		#sunshine--invoice--pageheader { width: 100%; }
		#sunshine--invoice--logo { font-size: 26px; font-weight: bold; }
		#sunshine--invoice--logo img { margin: 0 0 5px 0; max-height: 50px; width: auto;}
		#sunshine--invoice--title { font-size: 26px; font-weight: bold; text-transform: uppercase; text-align: right; }

		#sunshine--invoice--header { padding: 20px 0; width: 100%; }
		#sunshine--invoice--address { font-size: 16px; }
		#sunshine--invoice--basics { text-align: right; }
		#sunshine--invoice--basics table { width: auto; }
		#sunshine--invoice--basics table th { padding: 0 15px 5px 0; font-size: 14px; }
		#sunshine--invoice--basics table td { padding: 0 0 5px 0; font-size: 14px; }

		#sunshine--invoice--order-status { text-align: center; background: #EFEFEF; padding: 10px; font-weight: bold; }

		#sunshine--invoice--data { padding: 20px 0; }
		#sunshine--invoice--data--general, #sunshine--invoice--data--shipping { padding: 0 50px 0 0; }
		#sunshine--invoice--data--general table { width: auto; }
		#sunshine--invoice--data--general table th { padding: 0 15px 5px 0; }
		#sunshine--invoice--data--general table td { padding: 0 0 5px 0; }

		#sunshine--invoice--cart-items { width: 100%; padding: 0 0 30px 0; }
		#sunshine--invoice--cart-items thead th { background: #f1f1f1; padding: 5px; font-weight: normal; font-size: 10; text-transform: uppercase; color: #999; }
		#sunshine--invoice--cart-items tbody td { padding: 10px 5px; border-bottom: 1px solid #f1f1f1; }
		.sunshine--cart-item--product-name { font-weight: bold; }
		.sunshine--cart-item--image { width: 75px; }
		.sunshine--cart-item--image img { display: block; width: 75px; height: auto; }
		.sunshine--cart-item--qty,
		.sunshine--cart-item--price,
		.sunshine--cart-item--total { width: 10%; }
		.sunshine--cart-item--filenames { margin-top: 10px; }
		.sunshine--cart-item--filenames,
		.sunshine--order-item--extra--item--content { font-size: 13px; color: #666; }

		#sunshine--invoice--order-totals { margin-left: auto; }
		#sunshine--invoice--order-totals th { padding: 0 15px 5px 0; font-size: 14px; text-align: right; }
		#sunshine--invoice--order-totals td { padding: 0 0 5px 0; font-size: 14px; text-align: right; }

		@media print {
			#sunshine--invoice--actions { display: none; }
		}

		<?php
		$css = SPC()->get_option( 'css' );
		if ( $css ) {
			echo esc_html( wp_strip_all_tags( $css ) );
		}
		?>

	</style>

	<script src="<?php echo esc_url( SUNSHINE_PHOTO_CART_URL . 'assets/js/html2pdf.bundle.min.js' ); ?>"></script> <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
	<script>
		function pdf() {
			html2pdf( document.getElementById('sunshine--invoice'), {
				margin: .5,
				<?php /* translators: %s is the order number */ ?>
				filename: "<?php echo esc_js( sanitize_title( apply_filters( 'sunshine_invoice_file', sprintf( __( 'invoice-%s', 'sunshine-photo-cart' ), $order->get_order_number() ), $order ) ) ); ?>.pdf",
				image: { type: 'jpeg', quality: 0.98 },
				html2canvas: { scale: 2 },
				jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' }
			} );
		}
	</script>

</head>
<body>

<div id="sunshine--invoice">

<div id="sunshine--invoice--actions" data-html2canvas-ignore>
	<button onclick="pdf()"><?php esc_html_e( 'Download PDF', 'sunshine-photo-cart' ); ?></button>
</div>

<table id="sunshine--invoice--pageheader">
	<tr>
		<td id="sunshine--invoice--logo">
		<?php
		if ( SPC()->get_option( 'invoice_logo' ) > 0 ) {
			echo '<img src="' . esc_url( wp_get_attachment_url( SPC()->get_option( 'invoice_logo' ) ) ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" />';
		} else {
			bloginfo( 'name' );
		}
		?>
		</td>
		<td id="sunshine--invoice--title">
			<?php /* translators: %s is the order number */ ?>
			<?php echo esc_html( apply_filters( 'sunshine_invoice_title', sprintf( __( 'Invoice #%s', 'sunshine-photo-cart' ), $order->get_order_number() ), $order ) ); ?>
		</td>
	</tr>
</table>

<?php do_action( 'sunshine_invoice_after_page_header', $order ); ?>

<table id="sunshine--invoice--header">
	<tr>
		<td id="sunshine--invoice--address" width="50%">
			<?php
			$address = array(
				'address1' => SPC()->get_option( 'address1' ),
				'address2' => SPC()->get_option( 'address2' ),
				'city'     => SPC()->get_option( 'city' ),
				'state'    => SPC()->get_option( 'state' ),
				'postcode' => SPC()->get_option( 'postcode' ),
				'country'  => SPC()->get_option( 'country' ),
			);
			echo wp_kses_post( SPC()->countries->get_formatted_address( $address ) );
			do_action( 'sunshine_invoice_after_address', $order );
			?>
		</td>
		<td id="sunshine--invoice--basics" width="50%">
			<table align="right">
				<tr>
					<th><?php esc_html_e( 'Date', 'sunshine-photo-cart' ); ?></th>
					<td><?php echo wp_kses_post( $order->get_date( get_option( 'date_format' ) ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Total', 'sunshine-photo-cart' ); ?></th>
					<td><?php echo wp_kses_post( $order->get_total_formatted() ); ?></td>
				</tr>
			</table>
			<?php do_action( 'sunshine_invoice_after_basics', $order ); ?>
		</td>
	</tr>
</table>

<?php do_action( 'sunshine_invoice_after_header', $order ); ?>

<div id="sunshine--invoice--order-status"><?php echo esc_html( $order->get_status_name() ); ?>: <?php echo wp_kses_post( $order->get_status_description() ); ?></div>

<?php do_action( 'sunshine_invoice_after_order_status', $order ); ?>

<table id="sunshine--invoice--data">
	<tr>
		<td id="sunshine--invoice--data--general" width="33%">
			<table>
				<tr>
					<th><?php esc_html_e( 'Customer', 'sunshine-photo-cart' ); ?></th>
					<td><?php echo wp_kses_post( $order->get_customer_name() ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Email', 'sunshine-photo-cart' ); ?></th>
					<td><?php echo wp_kses_post( $order->get_email() ); ?></td>
				</tr>
				<?php if ( $order->get_phone() ) { ?>
				<tr>
					<th><?php esc_html_e( 'Phone', 'sunshine-photo-cart' ); ?></th>
					<td><?php echo wp_kses_post( $order->get_phone() ); ?></td>
				</tr>
				<?php } ?>
				<tr>
					<th><?php esc_html_e( 'Payment Method', 'sunshine-photo-cart' ); ?></th>
					<td><?php echo wp_kses_post( $order->get_payment_method_name() ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Shipping Method', 'sunshine-photo-cart' ); ?></th>
					<td>
						<?php if ( $order->get_shipping_method_name() ) { ?>
							<?php echo wp_kses_post( $order->get_shipping_method_name() ); ?>
						<?php } else { ?>
							<?php echo wp_kses_post( $order->get_delivery_method_name() ); ?>
						<?php } ?>
					</td>
				</tr>
				<?php if ( $order->get_vat() ) { ?>
					<tr>
						<th><?php echo ( SPC()->get_option( 'vat_label' ) ) ? esc_html( SPC()->get_option( 'vat_label' ) ) : esc_html__( 'EU VAT Number', 'sunshine-photo-cart' ); ?></th>
						<td><?php echo wp_kses_post( $order->get_vat() ); ?></td>
					</tr>
				<?php } ?>
			</table>
		</td>
		<?php if ( $order->has_shipping_address() ) { ?>
			<td id="sunshine--invoice--data--shipping" width="33%"><strong><?php esc_html_e( 'Shipping Address', 'sunshine-photo-cart' ); ?></strong><br /><?php echo wp_kses_post( $order->get_shipping_address_formatted() ); ?></td>
		<?php } ?>
		<?php if ( $order->has_billing_address() ) { ?>
			<td id="sunshine--invoice--data--billing" width="33%"><strong><?php esc_html_e( 'Billing Address', 'sunshine-photo-cart' ); ?></strong><br /><?php echo wp_kses_post( $order->get_billing_address_formatted() ); ?></td>
		<?php } ?>
	</tr>
</table>

<?php if ( ! empty( $order->get_customer_notes() ) ) { ?>
	<div id="sunshine--invoice--notes">
		<strong><?php esc_html_e( 'Customer Notes', 'sunshine-photo-cart' ); ?></strong><br />
		<?php echo wp_kses_post( $order->get_customer_notes() ); ?>
	</div>
<?php } ?>

<?php do_action( 'sunshine_invoice_after_data', $order ); ?>

<?php $cart = $order->get_cart(); ?>
<table id="sunshine--invoice--cart-items">
<thead>
	<tr>
		<th class="sunshine--cart-item--image"><?php esc_html_e( 'Image', 'sunshine-photo-cart' ); ?></th>
		<th class="sunshine--cart-item--name"><?php esc_html_e( 'Product', 'sunshine-photo-cart' ); ?></th>
		<th class="sunshine--cart-item--qty"><?php esc_html_e( 'Qty', 'sunshine-photo-cart' ); ?></th>
		<th class="sunshine--cart-item--price"><?php esc_html_e( 'Item Price', 'sunshine-photo-cart' ); ?></th>
		<th class="sunshine--cart-item--total"><?php esc_html_e( 'Item Total', 'sunshine-photo-cart' ); ?></th>
	</tr>
</thead>
<tbody>
<?php foreach ( $cart as $cart_item ) { ?>
	<tr class="sunshine--cart-item <?php echo esc_attr( $cart_item->classes() ); ?>">
		<td class="sunshine--cart-item--image" data-label="<?php esc_attr_e( 'Image', 'sunshine-photo-cart' ); ?>">
			<?php echo wp_kses_post( $cart_item->get_image_html( '', true, array( 'width' => '50' ) ) ); ?>
		</td>
		<td class="sunshine--cart-item--name" data-label="<?php esc_attr_e( 'Product', 'sunshine-photo-cart' ); ?>">
			<div class="sunshine--cart-item--product-name"><?php echo wp_kses_post( $cart_item->get_name() ); ?></div>
			<div class="sunshine--cart-item--product-options"><?php echo wp_kses_post( $cart_item->get_options_formatted() ); ?></div>
			<div class="sunshine--cart-item--gallery"><?php echo wp_kses_post( $cart_item->get_gallery_hierarchy() ); ?></div>
			<div class="sunshine--cart-item--image-name"><?php echo wp_kses_post( $cart_item->get_image_name( '' ) ); ?></div>
			<div class="sunshine--cart-item--comments"><?php echo wp_kses_post( $cart_item->get_comments() ); ?></div>
			<div class="sunshine--cart-item--extra"><?php echo wp_kses_post( $cart_item->get_extra() ); ?></div>
			<?php if ( current_user_can( 'sunshine_manage_options' ) && $cart_item->get_file_names() ) { ?>
				<div class="sunshine--cart-item--filenames">
					<?php esc_html_e( 'Filenames', 'sunshine-photo-cart' ); ?>:<br />
					<?php echo wp_kses_post( join( '<br />', $cart_item->get_file_names() ) ); ?></div>
			<?php } ?>
		</td>
		<td class="sunshine--cart-item--qty" data-label="<?php esc_attr_e( 'Qty', 'sunshine-photo-cart' ); ?>">
			<?php echo wp_kses_post( $cart_item->get_qty() ); ?>
		</td>
		<td class="sunshine--cart-item--price" data-label="<?php esc_attr_e( 'Price', 'sunshine-photo-cart' ); ?>">
			<?php echo wp_kses_post( $cart_item->get_price_formatted() ); ?>
		</td>
		<td class="sunshine--cart-item--total" data-label="<?php esc_attr_e( 'Total', 'sunshine-photo-cart' ); ?>">
			<?php echo wp_kses_post( $cart_item->get_subtotal_formatted() ); ?>
		</td>
	</tr>
<?php } ?>
</tbody>
</table>

<?php do_action( 'sunshine_invoice_after_cart_items', $order ); ?>

<table id="sunshine--invoice--order-totals">
	<tr class="sunshine-subtotal">
		<th><?php esc_html_e( 'Subtotal', 'sunshine-photo-cart' ); ?></th>
		<td><?php echo wp_kses_post( $order->get_subtotal_formatted() ); ?></td>
	</tr>
	<?php if ( ! empty( $order->get_shipping_method() ) ) { ?>
	<tr class="sunshine-shipping">
		<?php /* translators: %s is the shipping method name */ ?>
		<th><?php echo wp_kses_post( sprintf( __( 'Shipping via %s', 'sunshine-photo-cart' ), $order->get_shipping_method_name() ) ); ?></th>
		<td><?php echo wp_kses_post( $order->get_shipping_formatted() ); ?></td>
	</tr>
	<?php } ?>
	<?php if ( $order->has_discount() ) { ?>
	<tr class="sunshine-discount">
		<th><?php esc_html_e( 'Discounts', 'sunshine-photo-cart' ); ?></th>
		<td><?php echo wp_kses_post( $order->get_discount_formatted() ); ?></td>
	</tr>
	<?php } ?>
	<?php if ( $order->get_tax() ) { ?>
	<tr class="sunshine-tax">
		<th><?php esc_html_e( 'Tax', 'sunshine-photo-cart' ); ?></th>
		<td><?php echo wp_kses_post( $order->get_tax_formatted() ); ?></td>
	</tr>
	<?php } ?>
	<?php if ( $order->get_fees() ) { ?>
		<?php foreach ( $order->get_fees() as $fee ) { ?>
			<tr class="sunshine--cart--fee">
				<th><?php echo esc_html( $fee['name'] ); ?></th>
				<td><?php echo wp_kses_post( sunshine_price( $fee['amount'] ) ); ?></td>
			</tr>
		<?php } ?>
	<?php } ?>
	<?php if ( $order->get_credits() > 0 ) { ?>
	<tr class="sunshine-credits">
		<th><?php esc_html_e( 'Credits Applied', 'sunshine-photo-cart' ); ?></th>
		<td><?php echo wp_kses_post( $order->get_credits_formatted() ); ?></td>
	</tr>
	<?php } ?>
	<?php if ( $order->get_refunds() ) { ?>
	<tr class="sunshine-refunds">
		<th><?php esc_html_e( 'Refunds', 'sunshine-photo-cart' ); ?></th>
		<td><?php echo wp_kses_post( $order->get_refund_total_formatted() ); ?></td>
	</tr>
	<?php } ?>
	
	<tr class="sunshine-total">
		<th><?php esc_html_e( 'Order Total', 'sunshine-photo-cart' ); ?></th>
		<td><?php echo wp_kses_post( $order->get_total_formatted() ); ?></td>
	</tr>
</table>

<?php do_action( 'sunshine_invoice_after_order_totals', $order ); ?>

</div>

</body>
</html>
