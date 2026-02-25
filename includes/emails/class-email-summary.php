<?php
class SPC_Email_Summary extends SPC_Email {

	function init() {

		$this->id          = 'summary';
		$this->class       = get_class( $this );
		$this->name        = __( 'Summary', 'sunshine-photo-cart' );
		$this->description = __( 'Weekly email sent with summary of Sunshine sales and stats', 'sunshine-photo-cart' );
		/* translators: %s is the site name */
		$this->subject           = sprintf( __( '%s summary', 'sunshine-photo-cart' ), '[sitename]' );
		$this->custom_recipients = true;

		add_action( 'sunshine_send_summary', array( $this, 'trigger' ) );

	}

	public function trigger() {

		// Get order data.
		$current_after = strtotime( '-7 days' );
		$args          = array(
			'status' => sunshine_order_statuses_paid(),
			'after'  => array(
				'year'  => gmdate( 'Y', $current_after ),
				'month' => gmdate( 'n', $current_after ),
				'day'   => gmdate( 'j', $current_after ),
			),
		);
		$paid_orders   = sunshine_get_orders( $args );
		if ( empty( $paid_orders ) ) {
			// return; // Don't send this email if we have no orders.
		}

		$paid_total = 0;
		$paid_count = 0;
		if ( ! empty( $paid_orders ) ) {
			foreach ( $paid_orders as $order ) {
				$paid_total += $order->get_total();
			}
			$paid_count = count( $paid_orders );
		}

		// Get customers data.
		$args      = array(
			'date_query' => array(
				'after' => array(
					'year'  => gmdate( 'Y', $current_after ),
					'month' => gmdate( 'n', $current_after ),
					'day'   => gmdate( 'j', $current_after ),
				),
			),
		);
		$customers = sunshine_get_customers( $args );

		// Get gallery data.
		$args      = array(
			'meta_query' => '',
			'date_query' => array(
				'after' => array(
					'year'  => gmdate( 'Y', $current_after ),
					'month' => gmdate( 'n', $current_after ),
					'day'   => gmdate( 'j', $current_after ),
				),
			),
		);
		$galleries = sunshine_get_galleries( $args, 'all' );

		$images = 0;
		if ( ! empty( $galleries ) ) {
			foreach ( $galleries as $gallery ) {
				$images += count( $gallery->get_image_ids() );
			}
		}

		// Did You Know information (cached for 1 week).
		$dyk       = '';
		$dyk_items = get_transient( 'sunshine_dyk_items' );
		if ( false === $dyk_items ) {
			$request = wp_safe_remote_get( SUNSHINE_PHOTO_CART_STORE_URL . '/wp-content/didyouknow.json' );
			if ( ! is_wp_error( $request ) && 200 === wp_remote_retrieve_response_code( $request ) ) {
				$request_body = wp_remote_retrieve_body( $request );
				$dyk_items    = json_decode( $request_body, true );
				if ( ! empty( $dyk_items ) ) {
					set_transient( 'sunshine_dyk_items', $dyk_items, WEEK_IN_SECONDS );
				}
			}
		}
		if ( ! empty( $dyk_items ) ) {
			$key = array_rand( $dyk_items );
			$dyk = $dyk_items[ $key ];
		}

		$args = array(
			'start_date' => gmdate( get_option( 'date_format' ), $current_after ),
			'end_date'   => gmdate( get_option( 'date_format' ), time() ),
			'paid_total' => $paid_total,
			'paid_count' => $paid_count,
			'avg_order'  => ( $paid_total ) ? round( $paid_total / $paid_count, 2 ) : 0,
			'customers'  => ( $customers ) ? count( $customers ) : 0,
			'galleries'  => ( $galleries ) ? count( $galleries ) : 0,
			'images'     => ( $images ) ? $images : 0,
			'dyk'        => $dyk,
		);
		$this->add_args( $args );

		$this->set_template( $this->id );
		$this->set_subject( $this->get_subject() );

		$result = $this->send();

	}

}
