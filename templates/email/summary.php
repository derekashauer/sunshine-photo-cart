<h2><?php esc_html_e( 'Your Sunshine Photo Cart Weekly Summary', 'sunshine-photo-cart' ); ?></h2>
<p>
	<?php echo esc_html( $start_date ); ?> - <?php echo esc_html( $end_date ); ?>
</p>
<p>
	<a href="<?php echo esc_url( bloginfo( 'url' ) ); ?>"><?php echo esc_html( bloginfo( 'url' ) ); ?></a>
</p>

<?php if ( $paid_total > 279 ) { ?>
	<div id="celebrate">
		<h2>Good job money maker!</h2>
		<?php if ( ! SPC()->is_pro() ) { ?>
			<p>You have received enough sales in one week to pay for Sunshine Photo Cart Pro!</p>
			<p><a href="https://www.sunshinephotocart.com/upgrade/?utm_source=plugin&utm_medium=email&utm_campaign=summary" class="button">Get Pro!</a></p>
		<?php } else { ?>
			<p>You paid for your Sunshine Pro license in one week!</p>
		<?php } ?>
	</div>
<?php } ?>

<div id="sunshine-summary-data">

	<table>
	<tr>
		<td>
			<h3><?php esc_html_e( 'Total Received', 'sunshine-photo-cart' ); ?></h3>
			<p><?php echo wp_kses_post( sunshine_price( $paid_total, true ) ); ?></p>
		</td>
		<td>
			<h3><?php esc_html_e( 'Orders', 'sunshine-photo-cart' ); ?></h3>
			<p><?php echo esc_html( $paid_count ); ?></p>
		</td>
	</tr>
	<tr>
		<td>
			<h3><?php esc_html_e( 'Avg Order', 'sunshine-photo-cart' ); ?></h3>
			<p><?php echo wp_kses_post( sunshine_price( $avg_order, true ) ); ?></p>
		</td>
		<td>
			<h3><?php esc_html_e( 'New Customers', 'sunshine-photo-cart' ); ?></h3>
			<p><?php echo esc_html( $customers ); ?></p>
		</td>
	</tr>
	<?php if ( $galleries > 0 ) { ?>
	<tr>
		<td>
			<h3><?php esc_html_e( 'New Galleries', 'sunshine-photo-cart' ); ?></h3>
			<p><?php echo esc_html( $galleries ); ?></p>
		</td>
		<td>
			<h3><?php esc_html_e( 'New Images', 'sunshine-photo-cart' ); ?></h3>
			<p><?php echo esc_html( $images ); ?></p>
		</td>
	</tr>
	<?php } ?>
	</table>

	<p align="center"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-reports' ) ); ?>" class="button"><?php esc_html_e( 'View All Reports', 'sunshine-photo-cart' ); ?></a></p>

</div>

<?php if ( $dyk ) { ?>
<div id="dyk">
	<h2>Pro Tips from Sunshine Photo Cart</h2>
	<h3><?php echo esc_html( $dyk['title'] ); ?></h3>
	<p><?php echo wp_kses_post( $dyk['content'] ); ?></p>
	<?php if ( ! empty( $dyk['button_text'] ) && ! empty( $dyk['button_link'] ) ) { ?>
		<p><a href="<?php echo esc_url( $dyk['button_link'] ); ?>" class="button"><?php echo esc_html( $dyk['button_text'] ); ?></a></p>
	<?php } ?>
</div>
<?php } ?>
