<?php
class SPC_Email_Order_Comment extends SPC_Email {

	function init() {

		$this->id          = 'order-comment';
		$this->class       = get_class( $this );
		$this->name        = __( 'Order Comment', 'sunshine-photo-cart' );
		$this->description = __( 'Email sent to customer when comment added to order', 'sunshine-photo-cart' );
		/* translators: %1$s is the order name, %2$s is the site name */
		$this->subject = sprintf( __( 'A comment has been made on %1$s from %2$s', 'sunshine-photo-cart' ), '[order_name]', '[sitename]' );

		$this->add_search_replace(
			array(
				'order_id'   => '',
				'order_name' => '',
				'first_name' => '',
				'last_name'  => '',
				'status'     => '',
				'comment'    => '',
			)
		);

		add_action( 'sunshine_order_add_comment', array( $this, 'trigger' ), 10, 2 );

	}

	public function trigger( $comment, $order_id ) {

		$order = sunshine_get_order( $order_id );

		$customer_email_address = $order->get_email();
		if ( ! empty( $customer_email_address ) ) {

			$this->set_template( $this->id );
			$this->set_subject( $this->get_subject() );

			$this->add_recipient( $customer_email_address );

			$args = array(
				'order'   => $order,
				'comment' => $comment->comment_content,
			);
			$this->add_args( $args );

			$search_replace = array(
				'order_id'   => $order->get_id(),
				'order_name' => $order->get_name(),
				'first_name' => $order->get_customer_first_name(),
				'last_name'  => $order->get_customer_last_name(),
				'status'     => $order->get_status_name(),
			);
			$this->add_search_replace( $search_replace );

			// Send email
			$result = $this->send();

		}

	}

}
