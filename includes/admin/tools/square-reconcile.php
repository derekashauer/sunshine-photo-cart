<?php
/**
 * Reconcile pending Square orders against Square's API.
 *
 * Finds orders stuck in `pending` status that have a stored `square_payment_id`,
 * asks Square for the current state of each payment, and finalizes any whose
 * payment is reported as COMPLETED. Handles the historical backlog left behind
 * when the synchronous AJAX -> form-post flow failed after Square had already
 * charged the customer.
 */
class SPC_Tool_Square_Reconcile extends SPC_Tool {

	protected $is_chunked = true;
	protected $batch_size = 10;

	function __construct() {
		parent::__construct(
			__( 'Reconcile Pending Square Orders', 'sunshine-photo-cart' ),
			'square_reconcile',
			__( 'When Square charges a customer but their browser does not finish the order (network drop, closed tab, etc.), the order can get stuck in pending while the charge succeeds at Square. This tool finds pending Square orders, asks Square if the payment completed, and finalizes any that did.', 'sunshine-photo-cart' ),
			__( 'Reconcile pending Square orders', 'sunshine-photo-cart' )
		);

		add_action( 'wp_ajax_sunshine_square_reconcile_one', array( $this, 'reconcile_one' ) );
	}

	function pre_process() {
		$count = $this->count_remaining();
		if ( $count ) {
			echo '<p>';
			/* translators: %s is the number of pending Square orders found */
			echo esc_html( sprintf( __( 'Sunshine found %s pending order(s) with a Square payment id to check.', 'sunshine-photo-cart' ), $count ) );
			echo '</p>';
		} else {
			echo '<p><em>' . esc_html__( 'No pending Square orders to reconcile.', 'sunshine-photo-cart' ) . '</em></p>';
			$this->button_label = '';
		}
	}

	public function count_remaining() {
		return count( $this->find_candidates() );
	}

	public function process_batch( $size = null, $params = array() ) {
		$size       = max( 1, (int) ( $size ?: $this->get_batch_size() ) );
		$candidates = array_slice( $this->find_candidates(), 0, $size );
		$processed  = 0;
		$log        = array();
		$errors     = array();

		$square = sunshine_get_payment_method_by_id( 'square' );
		if ( ! $square ) {
			return array(
				'processed'   => 0,
				'remaining'   => 0,
				'next_offset' => 0,
				'log'         => array(),
				'errors'      => array( array( 'order' => '', 'error' => 'Square payment method not available' ) ),
			);
		}

		foreach ( $candidates as $order_id ) {
			$result = $this->reconcile_one_order( $square, $order_id );
			if ( ! empty( $result['ok'] ) ) {
				++$processed;
				$log[] = $result['summary'];
			} else {
				$errors[] = array(
					'order' => $result['order'],
					'error' => $result['error'],
				);
			}
		}

		return array(
			'processed'   => $processed,
			'remaining'   => count( $this->find_candidates() ),
			// Candidates that finalize or get marked failed drop out of the
			// pending query for the next call. still_pending entries stay in
			// the set but the 30s throttle inside reconcile_order is bypassed
			// here (force=true), so the next batch genuinely re-checks them.
			'next_offset' => 0,
			'log'         => $log,
			'errors'      => $errors,
		);
	}

	/**
	 * Pending orders paid via Square that have a square_payment_id to check.
	 */
	private function find_candidates() {
		$query = new WP_Query(
			array(
				'post_type'      => 'sunshine-order',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => 'sunshine-order-status',
						'field'    => 'slug',
						'terms'    => 'pending',
					),
				),
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => 'payment_method',
						'value' => 'square',
					),
					array(
						'key'     => 'square_payment_id',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);
		return $query->posts ? $query->posts : array();
	}

	/**
	 * Worker: reconcile one order. Used by both the admin AJAX handler and the
	 * REST batch path.
	 */
	private function reconcile_one_order( $square, $order_id ) {
		$order = sunshine_get_order( $order_id );
		if ( ! $order || ! $order->exists() ) {
			return array( 'ok' => false, 'order' => '#' . intval( $order_id ), 'error' => 'Order not found' );
		}

		$result = $square->reconcile_order( $order, array( 'force' => true ) );
		$action = isset( $result['action'] ) ? $result['action'] : 'unknown';

		switch ( $action ) {
			case 'finalized':
				return array(
					'ok'      => true,
					'order'   => $order->get_name(),
					/* translators: %s: order name */
					'summary' => sprintf( __( '%s: finalized', 'sunshine-photo-cart' ), $order->get_name() ),
				);
			case 'already_paid':
				return array(
					'ok'      => true,
					'order'   => $order->get_name(),
					/* translators: %s: order name */
					'summary' => sprintf( __( '%s: already paid', 'sunshine-photo-cart' ), $order->get_name() ),
				);
			case 'marked_failed':
				$reason = ! empty( $result['error'] ) ? $result['error'] : '';
				return array(
					'ok'      => true,
					'order'   => $order->get_name(),
					/* translators: %1$s: order name, %2$s: failure reason reported by Square */
					'summary' => sprintf( __( '%1$s: marked failed (Square: %2$s)', 'sunshine-photo-cart' ), $order->get_name(), $reason ?: 'declined' ),
				);
			case 'still_pending':
				$status = ! empty( $result['error'] ) ? $result['error'] : 'PENDING';
				return array(
					'ok'      => true,
					'order'   => $order->get_name(),
					/* translators: %1$s: order name, %2$s: Square payment status */
					'summary' => sprintf( __( '%1$s: still pending at Square (%2$s)', 'sunshine-photo-cart' ), $order->get_name(), $status ),
				);
			case 'in_progress':
				return array(
					'ok'      => true,
					'order'   => $order->get_name(),
					/* translators: %s: order name */
					'summary' => sprintf( __( '%s: another reconcile already running, skipped', 'sunshine-photo-cart' ), $order->get_name() ),
				);
			default:
				return array(
					'ok'    => false,
					'order' => $order->get_name(),
					'error' => ! empty( $result['error'] ) ? $result['error'] : $action,
				);
		}
	}

	protected function do_process() {
		$count = $this->count_remaining();
		?>
		<div id="progress-bar" style="background: #000; height: 30px; position: relative;">
			<div id="percentage" style="height: 30px; background-color: green; width: 0%;"></div>
			<div id="processed" style="position: absolute; top: 0; left: 0; width: 100%; color: #FFF; text-align: center; font-size: 18px; height: 30px; line-height: 30px;">
				<span id="processed-count">0</span> / <span id="processed-total"><?php echo esc_html( $count ); ?></span>
			</div>
		</div>
		<p align="center" id="abort"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine-tools' ) ); ?>"><?php esc_html_e( 'Abort', 'sunshine-photo-cart' ); ?></a></p>
		<ol id="results"></ol>
		<script type="text/javascript">
		jQuery( document ).ready(function($) {
			var processed = 0;
			var total = <?php echo esc_js( $count ); ?>;
			function sunshine_square_reconcile_next() {
				var data = {
					'action': 'sunshine_square_reconcile_one',
					'security': "<?php echo esc_js( wp_create_nonce( 'sunshine_square_reconcile_one' ) ); ?>"
				};
				$.post( ajaxurl, data, function(response) {
					if ( response.success && response.data && response.data.done ) {
						$( '#abort' ).hide();
						return;
					}
					processed++;
					$( '#processed-count' ).html( processed );
					var percent = total ? Math.round( ( processed / total ) * 100 ) : 100;
					$( '#percentage' ).css( 'width', percent+'%' );
					if ( response.success ) {
						$( '#results' ).append( '<li>' + response.data.summary + '</li>' );
					} else {
						var msg = ( response.data && response.data.error ) ? response.data.error : 'error';
						var ord = ( response.data && response.data.order ) ? response.data.order : '';
						$( '#results' ).append( '<li style="color: red;">' + ord + ': ' + msg + '</li>' );
					}
					if ( processed >= total ) {
						$( '#abort' ).hide();
						return;
					}
					sunshine_square_reconcile_next();
				}).fail( function() {
					$( '#results' ).append( '<li style="color: red;"><?php echo esc_js( __( 'Request failed', 'sunshine-photo-cart' ) ); ?></li>' );
				});
			}
			if ( total > 0 ) {
				sunshine_square_reconcile_next();
			}
		});
		</script>
		<?php
	}

	/**
	 * Admin AJAX handler — runs one reconcile per request. The JS chains calls
	 * until the candidate set is empty.
	 */
	function reconcile_one() {
		if ( ! isset( $_REQUEST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['security'] ) ), 'sunshine_square_reconcile_one' ) ) {
			wp_send_json_error( array( 'error' => 'Security check failed' ) );
		}
		if ( ! current_user_can( 'sunshine_manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'Permission denied' ) );
		}

		$candidates = $this->find_candidates();
		if ( empty( $candidates ) ) {
			wp_send_json_success( array( 'done' => true ) );
		}

		$square = sunshine_get_payment_method_by_id( 'square' );
		if ( ! $square ) {
			wp_send_json_error( array( 'order' => '', 'error' => 'Square payment method not available' ) );
		}

		$result = $this->reconcile_one_order( $square, $candidates[0] );
		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( array( 'order' => $result['order'], 'summary' => $result['summary'] ) );
		}
		wp_send_json_error( array( 'order' => $result['order'], 'error' => $result['error'] ) );
	}
}

$spc_tool_square_reconcile = new SPC_Tool_Square_Reconcile();
