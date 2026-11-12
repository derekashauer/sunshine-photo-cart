<?php
/**
 * Tracking for each package, in the Order Status Update email and the receipt.
 *
 * @package Sunshine_Photo_Cart
 * @var array $tracking Packages from SPC_Order::get_tracking().
 */

$count = count( $tracking );
?>
<div id="order-tracking">
	<h3><?php esc_html_e( 'Tracking', 'sunshine-photo-cart' ); ?></h3>
	<?php foreach ( $tracking as $index => $package ) { ?>
		<p>
			<?php if ( $count > 1 ) { ?>
				<?php /* translators: %1$d: package number, %2$d: number of packages */ ?>
				<strong><?php echo esc_html( sprintf( __( 'Package %1$d of %2$d', 'sunshine-photo-cart' ), $index + 1, $count ) ); ?></strong><br />
			<?php } ?>
			<?php if ( ! empty( $package['carrier'] ) ) { ?>
				<?php /* translators: %s: carrier name, like UPS */ ?>
				<?php echo esc_html( sprintf( __( 'Carrier: %s', 'sunshine-photo-cart' ), $package['carrier'] ) ); ?><br />
			<?php } ?>
			<?php if ( ! empty( $package['tracking_number'] ) ) { ?>
				<?php /* translators: %s: tracking number */ ?>
				<?php echo esc_html( sprintf( __( 'Tracking number: %s', 'sunshine-photo-cart' ), $package['tracking_number'] ) ); ?><br />
			<?php } ?>
			<?php if ( ! empty( $package['tracking_url'] ) ) { ?>
				<a href="<?php echo esc_url( $package['tracking_url'] ); ?>"><?php esc_html_e( 'Track package', 'sunshine-photo-cart' ); ?></a>
			<?php } ?>
		</p>
	<?php } ?>
</div>
