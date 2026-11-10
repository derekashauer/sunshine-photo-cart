<?php
/**
 * Sent to the customer when an order that went in the mail is marked shipped. It takes the place
 * of the Order Status Update email for that one change, so the store can say "it's on the way"
 * in its own words, with the tracking.
 */
class SPC_Email_Order_Shipped extends SPC_Email_Order_Status {

	public function init() {

		// The Order Status Update setup: its tags and the status change that sends it.
		parent::init();

		$this->id          = 'order-shipped';
		$this->class       = get_class( $this );
		$this->name        = __( 'Order Shipped', 'sunshine-photo-cart' );
		$this->description = __( 'Email sent to customer when an order that ships is marked shipped, in place of the Order Status Update email', 'sunshine-photo-cart' );
		/* translators: %1$s is the order name, %2$s is the site name */
		$this->subject = sprintf( __( '%1$s from %2$s has shipped', 'sunshine-photo-cart' ), '[order_name]', '[sitename]' );

		$this->add_search_replace(
			array(
				'carrier'         => '',
				'tracking_number' => '',
				'tracking_url'    => '',
			)
		);

	}

	/**
	 * Whether this email, not the Order Status Update email, tells the customer about the
	 * order's current status. Only for an order that went in the mail: pickup, local delivery
	 * and download-only orders are "shipped" too, but nothing was sent.
	 *
	 * @param SPC_Order $order The order.
	 * @return bool
	 */
	public static function applies_to( $order ) {

		if ( 'shipped' !== $order->get_status() || $order->is_pickup_order() || ! $order->has_shipping_address() ) {
			return false;
		}

		$shipping_method = sunshine_get_shipping_method_by_instance( $order->get_shipping_method() );
		if ( $shipping_method && 'local' === $shipping_method->get_id() ) {
			return false;
		}

		// A site can take this email out through the sunshine_emails filter.
		$emails = SPC()->emails->get_emails();
		return ! empty( $emails['order-shipped'] ) && $emails['order-shipped']->is_active();

	}

	/**
	 * The tracking a print lab add-on saved on the order, as tags for the subject and message.
	 * They describe the first package, so the carrier, number and link always belong together.
	 * The add-on's tracking block in the email lists every package.
	 *
	 * @param SPC_Order $order The order.
	 * @return array carrier, tracking_number and tracking_url.
	 */
	public static function get_tracking_tags( $order ) {
		$tags     = array(
			'carrier'         => '',
			'tracking_number' => '',
			'tracking_url'    => '',
		);
		$packages = $order->get_meta_value( 'shipment_tracking' );
		if ( empty( $packages ) || ! is_array( $packages ) ) {
			return $tags;
		}
		$package = reset( $packages );
		foreach ( array_keys( $tags ) as $key ) {
			if ( ! empty( $package[ $key ] ) && is_string( $package[ $key ] ) ) {
				// Plain, because the subject is plain text.
				$tags[ $key ] = ( 'tracking_url' === $key ) ? esc_url_raw( $package[ $key ] ) : sanitize_text_field( $package[ $key ] );
			}
		}
		return $tags;
	}

	/**
	 * A store that wrote a message for status emails before this email existed keeps it on
	 * shipped orders until it writes one here.
	 *
	 * @return string
	 */
	public function get_message() {
		$message = parent::get_message();
		return ! empty( $message ) ? $message : SPC()->get_option( 'email_order-status_message' );
	}

	/**
	 * Send it, when the order's change is to shipped and it went in the mail.
	 *
	 * @param SPC_Order $order The order.
	 */
	public function trigger( $order ) {

		if ( ! self::applies_to( $order ) || ! $this->prepare( $order ) ) {
			return;
		}

		$this->add_search_replace( self::get_tracking_tags( $order ) );
		$this->send();

	}

}
