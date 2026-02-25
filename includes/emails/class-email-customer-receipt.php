<?php
class SPC_Email_Customer_Receipt extends SPC_Email {

	function init() {

		$this->id          = 'customer-receipt';
		$this->class       = get_class( $this );
		$this->name        = __( 'Customer Order Receipt', 'sunshine-photo-cart' );
		$this->description = __( 'Email receipt sent to customer after successful order', 'sunshine-photo-cart' );
		/* translators: %1$s is the order name, %2$s is the site name */
		$this->subject = sprintf( __( 'Receipt for %1$s from %2$s', 'sunshine-photo-cart' ), '[order_name]', '[sitename]' );

		$this->add_search_replace(
			array(
				'order_id'     => '',
				'order_number' => '',
				'order_name'   => '',
				'first_name'   => '',
				'last_name'    => '',
				'status'       => '',
				'receipt_url'  => '',
			)
		);

		add_action( 'sunshine_order_notify', array( $this, 'trigger' ) );

	}

	public function trigger( $order ) {

		$customer_email_address = $order->get_email();
		if ( ! empty( $customer_email_address ) ) {

			$this->set_template( $this->id );
			$this->set_subject( $this->get_subject() );

			$this->set_recipients( $customer_email_address );

			$args = array(
				'order' => $order,
			);
			$this->set_args( $args );

			$search_replace = array(
				'order_id'     => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'order_name'   => $order->get_name(),
				'first_name'   => $order->get_customer_first_name(),
				'last_name'    => $order->get_customer_last_name(),
				'status'       => $order->get_status_name(),
				'receipt_url'  => $order->get_received_permalink(),
				'receipt_link' => '<a href="' . $order->get_received_permalink() . '">' . __( 'View order', 'sunshine-photo-cart' ) . '</a>',
				'invoice_url'  => $order->get_invoice_permalink(),
				'invoice_link' => '<a href="' . $order->get_invoice_permalink() . '">' . __( 'View invoice', 'sunshine-photo-cart' ) . '</a>',
			);
			$search_replace = apply_filters( 'sunshine_order_email_search_replace', $search_replace, $order );
			$this->add_search_replace( $search_replace );

			// Send email
			$result = $this->send();
			if ( $result ) {
				$order->add_log( 'Email sent to customer: ' . $this->name );
			}
		}

	}

}
