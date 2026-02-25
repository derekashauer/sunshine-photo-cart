<?php
class SPC_Email_Order_Status extends SPC_Email {

	function init() {

		$this->id          = 'order-status';
		$this->class       = get_class( $this );
		$this->name        = __( 'Order Status Update', 'sunshine-photo-cart' );
		$this->description = __( 'Email sent to customer when order status is updated', 'sunshine-photo-cart' );
		/* translators: %1$s is the order name, %2$s is the site name */
		$this->subject           = sprintf( __( 'Update on %1$s from %2$s', 'sunshine-photo-cart' ), '[order_name]', '[sitename]' );
		$this->custom_recipients = false;

		$this->add_search_replace(
			array(
				'order_id'      => '',
				'order_number'  => '',
				'order_name'    => '',
				'first_name'    => '',
				'last_name'     => '',
				'status'        => '',
				'gallery_names' => '',
			)
		);

		add_action( 'sunshine_admin_order_status_update', array( $this, 'trigger' ) );

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
			$this->add_args( $args );

			$galleries     = $order->get_galleries();
			$gallery_names = array();
			if ( ! empty( $galleries ) ) {
				foreach ( $galleries as $gallery_id ) {
					$gallery_names[] = get_the_title( $gallery_id );
				}
			}

			$search_replace = array(
				'order_id'      => $order->get_id(),
				'order_number'  => $order->get_order_number(),
				'order_name'    => $order->get_name(),
				'first_name'    => $order->get_customer_first_name(),
				'last_name'     => $order->get_customer_last_name(),
				'status'        => $order->get_status_name(),
				'gallery_names' => join( ', ', $gallery_names ),
			);
			$this->add_search_replace( $search_replace );

			// Send email
			$result = $this->send();

		}

	}

}
