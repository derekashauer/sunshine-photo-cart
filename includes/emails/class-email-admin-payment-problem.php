<?php
/**
 * Tells the store owner when a payment can't be accepted for an order, or an order is marked
 * Failed. Off until the store owner turns it on. Goes to its own recipients, or the site admin
 * email when none are set.
 */
class SPC_Email_Admin_Payment_Problem extends SPC_Email {

	function init() {

		$this->id          = 'admin-payment-problem';
		$this->class       = get_class( $this );
		$this->name        = __( 'Admin Payment Problem', 'sunshine-photo-cart' );
		$this->description = __( 'Email sent to admins when a payment can\'t be accepted for an order or an order is marked Failed. Sent to the recipients below, or the site admin email when left empty.', 'sunshine-photo-cart' );
		/* translators: %1$s is the order name, %2$s is the site name */
		$this->subject           = sprintf( __( 'Payment problem with %1$s from %2$s', 'sunshine-photo-cart' ), '[order_name]', '[sitename]' );
		$this->custom_recipients = true;
		$this->active_by_default = false;

		$this->add_search_replace(
			array(
				'order_id'     => '',
				'order_number' => '',
				'order_name'   => '',
				'first_name'   => '',
				'last_name'    => '',
				'status'       => '',
				'reason'       => '',
			)
		);

		add_action( 'sunshine_order_payment_problem', array( $this, 'payment_problem' ), 10, 3 );
		add_action( 'sunshine_order_status_change', array( $this, 'status_change' ), 10, 3 );

	}

	/**
	 * A payment method refused a payment for the order. Gateways can send the same notice more
	 * than once (webhook retries, scheduled checks), so each reason is only emailed once per order.
	 */
	public function payment_problem( $order, $reason, $payment_method_id = '' ) {
		if ( ! $this->is_active() || empty( $order ) ) {
			return;
		}
		$sent = (array) $order->get_meta_value( 'payment_problem_emailed' );
		$key  = md5( (string) $reason );
		if ( in_array( $key, $sent, true ) ) {
			return;
		}
		if ( $this->trigger( $order, $reason, $payment_method_id ) ) {
			$sent[] = $key;
			$order->update_meta_value( 'payment_problem_emailed', $sent );
		}
	}

	/**
	 * The order's status changed to Failed.
	 */
	public function status_change( $new_status, $order, $old_status = '' ) {
		if ( 'failed' !== $new_status || 'failed' === $old_status || ! $this->is_active() ) {
			return;
		}
		$this->trigger( $order, __( 'The order was marked Failed.', 'sunshine-photo-cart' ) );
	}

	public function trigger( $order, $reason, $payment_method_id = '' ) {

		if ( empty( $payment_method_id ) ) {
			$payment_method_id = $order->get_payment_method();
		}
		$payment_method_name = $order->get_payment_method_name();
		if ( empty( $payment_method_name ) && $payment_method_id ) {
			$payment_method = sunshine_get_payment_method_by_id( $payment_method_id );
			if ( $payment_method ) {
				$payment_method_name = $payment_method->get_name();
			}
		}

		// The recipients set for this email are added when it sends. With none set, the site
		// admin email gets it, so turning it on never sends to nobody.
		$this->clear_recipients();
		if ( empty( $this->get_default_recipients() ) ) {
			$this->set_recipients( get_bloginfo( 'admin_email' ) );
		}

		$default_subject = $this->subject;
		$subject         = $this->get_subject();
		if ( empty( $subject ) ) {
			$subject = $default_subject;
		}
		if ( 'test' === $order->get_meta_value( 'mode' ) ) {
			/* translators: %s is the email subject */
			$subject = sprintf( __( '%s (test mode)', 'sunshine-photo-cart' ), $subject );
		}

		$this->set_template( $this->id );
		$this->set_subject( $subject );
		$this->set_reply_to( $order->get_email() );

		$this->add_args(
			array(
				'order'               => $order,
				'reason'              => $reason,
				'payment_method_name' => $payment_method_name,
			)
		);

		$this->add_search_replace(
			array(
				'order_id'     => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'order_name'   => $order->get_name(),
				'first_name'   => $order->get_customer_first_name(),
				'last_name'    => $order->get_customer_last_name(),
				'status'       => $order->get_status_name(),
				'reason'       => $reason,
			)
		);

		$result        = $this->send();
		$this->subject = $default_subject;
		if ( $result ) {
			$order->add_log( 'Email sent to admin: ' . $this->name );
		}
		return (bool) $result;

	}

}
