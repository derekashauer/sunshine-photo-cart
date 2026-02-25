<?php
class SPC_Dashboard_Widget {

	function __construct() {

		add_action( 'wp_dashboard_setup', array( $this, 'init' ) );
		add_action( 'wp_ajax_sunshine_dashboard_calculate_stats', array( $this, 'calculate_stats' ) );
		add_action( 'sunshine_order_create', array( $this, 'recalculate_stats' ) );

	}

	function init() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( isset( $_GET['sunshine_dashboard_clear_stats'] ) ) {
			$this->recalculate_stats();
			wp_safe_redirect( admin_url( 'index.php' ) );
			exit;
		}
		wp_add_dashboard_widget( 'sunshine-dashboard', __( 'Sunshine Photo Cart Sales Summary', 'sunshine-photo-cart' ), array( $this, 'sales' ), null, null, 'normal', 'high' );
		if ( $this->needs_setup() ) {
			wp_add_dashboard_widget( 'sunshine-dashboard-setup', __( 'Sunshine Photo Cart Setup', 'sunshine-photo-cart' ), array( $this, 'setup' ), null, null, 'side', 'high' );
		}
	}

	function sales() {
		// echo _n( 'sale', 'sales', $result->orders, 'sunshine-photo-cart' );
		?>
		<div id="sunshine-dashboard-widget-sales" class="sunshine-loading">
			<div class="sunshine-dashboard-widget-sales--group" id="sunshine-this-month">
				<h3><?php esc_html_e( 'Current month sales', 'sunshine-photo-cart' ); ?></h3>
				<p>
					<span class="total">&mdash;</span>
					<span class="count">&mdash;</span>
				</p>
			</div>
			<div class="sunshine-dashboard-widget-sales--group" id="sunshine-last-month">
				<h3><?php esc_html_e( 'Last month sales', 'sunshine-photo-cart' ); ?></h3>
				<p>
					<span class="total">&mdash;</span>
					<span class="count">&mdash;</span>
				</p>
			</div>
			<div class="sunshine-dashboard-widget-sales--group" id="sunshine-lifetime">
				<h3><?php esc_html_e( 'Lifetime sales', 'sunshine-photo-cart' ); ?></h3>
				<p>
					<span class="total">&mdash;</span>
					<span class="count">&mdash;</span>
				</p>
			</div>
			<div class="sunshine-dashboard-widget-sales--group" id="sunshine-new">
				<h3><?php esc_html_e( 'New Orders', 'sunshine-photo-cart' ); ?></h3>
				<p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-order&sunshine-order-status=new' ) ); ?>"><span class="total">&mdash;</span></a>
				</p>
			</div>
		</div>
		<script>
		jQuery( document ).ready(function($) {

			var data = {
				'action': 'sunshine_dashboard_calculate_stats',
			};

			// since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
			$.post( ajaxurl, data, function(response) {
				$( '#sunshine-this-month span.total' ).html( response.this_month.total );
				$( '#sunshine-this-month span.count' ).html( response.this_month.count );
				$( '#sunshine-last-month span.total' ).html( response.last_month.total );
				$( '#sunshine-last-month span.count' ).html( response.last_month.count );
				$( '#sunshine-lifetime span.total' ).html( response.lifetime.total );
				$( '#sunshine-lifetime span.count' ).html( response.lifetime.count );
				$( '#sunshine-new span.total' ).html( response.new.count );
				if ( response.new.count > 0 ) {
					$( '#sunshine-new a' ).show();
				}
				$( '#sunshine-dashboard-widget-sales' ).removeClass( 'sunshine-loading' );
			});
		});
		</script>

		<?php
		$orders = sunshine_get_orders(
			array(
				'nopaging'       => false,
				'posts_per_page' => apply_filters( 'sunshine_dashboard_recent_orders_count', 10 ),
			)
		);
		if ( ! empty( $orders ) ) {
			?>
		<div id="sunshine-dashboard-widget-recent">
			<h3><?php esc_html_e( 'Recent Orders', 'sunshine-photo-cart' ); ?></h3>
			<table id="sunshine-orders-table">
				<?php foreach ( $orders as $order ) { ?>
				<tr>
					<td><a href="<?php echo esc_url( admin_url( 'post.php?action=edit&post=' . $order->get_id() ) ); ?>"><?php echo esc_html( $order->get_name() ); ?></a></td>
					<td><?php echo esc_html( $order->get_date() ); ?></td>
					<td><?php echo esc_html( $order->get_status_name() ); ?></td>
					<td><?php echo wp_kses_post( sunshine_price( $order->get_total() ) ); ?></td>
				</tr>
				<?php } ?>
			</table>
		</div>
		<?php } ?>
		<?php
	}

	function calculate_stats() {
		global $wpdb;

		$data = get_transient( 'sunshine-dashboard-sales' );

		if ( empty( $data ) ) {

			$paid_statuses = sunshine_order_statuses_paid();
			// Prepare the placeholders for the terms in the SQL query
			$term_placeholders = array_fill( 0, count( $paid_statuses ), '%s' );
			$term_placeholders = implode( ',', $term_placeholders );

			// Get the current month's start and end dates
			$current_month_start = date( 'Y-m-01 00:00:00' );
			$current_month_end   = date( 'Y-m-t 23:59:59' );

			// Prepare the SQL query
			$query = $wpdb->prepare(
				"
			SELECT SUM(pm.meta_value) AS order_total, COUNT(p.ID) AS order_count
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			WHERE p.post_type = 'sunshine-order'
			AND pm.meta_key = 'total'
			AND pm2.meta_key = 'mode' AND pm2.meta_value = 'live'
			AND tt.taxonomy = 'sunshine-order-status'
			AND t.slug IN ( $term_placeholders )
		    AND p.post_date >= %s
		    AND p.post_date <= %s
			AND p.post_status = 'publish'
		",
				array_merge( $paid_statuses, array( $current_month_start, $current_month_end ) )
			);

			// Retrieve the results
			$this_month = $wpdb->get_row( $query );

			$last_month_start = date( 'Y-m-01 00:00:00', strtotime( '-1 month' ) );
			$last_month_end   = date( 'Y-m-t 23:59:59', strtotime( '-1 month' ) );

			// Prepare the SQL query
			$query = $wpdb->prepare(
				"
			SELECT SUM(pm.meta_value) AS order_total, COUNT(p.ID) AS order_count
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			WHERE p.post_type = 'sunshine-order'
			AND pm.meta_key = 'total'
			AND pm2.meta_key = 'mode' AND pm2.meta_value = 'live'
			AND tt.taxonomy = 'sunshine-order-status'
			AND t.slug IN ( $term_placeholders )
			AND p.post_date >= %s
			AND p.post_date <= %s
		",
				array_merge( $paid_statuses, array( $last_month_start, $last_month_end ) )
			);

			// Retrieve the results
			$last_month = $wpdb->get_row( $query );

			$query = $wpdb->prepare(
				"
				SELECT SUM(pm.meta_value) AS order_total, COUNT(p.ID) AS order_count
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
				INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				WHERE p.post_type = 'sunshine-order'
				AND pm.meta_key = 'total'
				AND pm2.meta_key = 'mode' AND pm2.meta_value = 'live'
				AND tt.taxonomy = 'sunshine-order-status'
				AND t.slug IN ( $term_placeholders )
			",
				$paid_statuses
			);

			// Retrieve the results
			$lifetime = $wpdb->get_row( $query );

			$new_orders = $wpdb->get_row(
				"SELECT COUNT(*) as order_count FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->term_relationships} AS tax_rel ON (p.ID = tax_rel.object_id)
				LEFT JOIN {$wpdb->term_taxonomy} AS term_tax ON (tax_rel.term_taxonomy_id = term_tax.term_taxonomy_id)
				LEFT JOIN {$wpdb->terms} AS terms ON (terms.term_id = term_tax.term_id)
				INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
	            WHERE p.post_type = 'sunshine-order'
				AND p.post_status = 'publish'
				AND pm2.meta_key = 'mode' AND pm2.meta_value = 'live'
				AND terms.name = 'new'
        		AND term_tax.taxonomy = 'sunshine-order-status'"
			);

			$data = array(
				'this_month' => array(
					'count' => $this_month->order_count,
					'total' => sunshine_price( $this_month->order_total, true ),
				),
				'last_month' => array(
					'count' => $last_month->order_count,
					'total' => sunshine_price( $last_month->order_total, true ),
				),
				'lifetime'   => array(
					'count' => $lifetime->order_count,
					'total' => sunshine_price( $lifetime->order_total, true ),
				),
				'new'        => array(
					'count' => $new_orders->order_count,
				),
			);

			set_transient( 'sunshine-dashboard-sales', $data, DAY_IN_SECONDS );

		}

		wp_send_json( $data );

	}

	function recalculate_stats() {
		delete_transient( 'sunshine-dashboard-sales' );
	}

	function needs_setup() {
		if ( ! SPC()->get_option( 'address1' ) ) {
			return true;
		} elseif ( empty( sunshine_get_products() ) ) {
			return true;
		} elseif ( empty( sunshine_get_active_payment_methods() ) ) {
			return true;
		} elseif ( empty( sunshine_get_active_shipping_methods() ) ) {
			return true;
		} elseif ( ! SPC()->get_option( 'logo' ) ) {
			return true;
		}
		return false;
	}

	function setup() {
		?>
		<div id="sunshine-dashboard-widget-setup">
			<ol>

				<?php if ( ! SPC()->get_option( 'address1' ) ) { ?>
					<li>
						<div>
							<p>Start configuring your store including address, pages, URLs, and more...</p>
							<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine' ) ); ?>">See settings</a></p>
						</div>
					</li>
				<?php } else { ?>
					<li class="completed"><p>Store Configuration</p></li>
				<?php } ?>

				<?php if ( empty( sunshine_get_active_payment_methods() ) ) { ?>
					<li>
						<div>
							<p>Configure payment methods to start receiving money</p>
							<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine&section=payment_methods' ) ); ?>">Select payment methods</a></p>
						</div>
					</li>
				<?php } else { ?>
					<li class="completed"><p>Payment methods</p></li>
				<?php } ?>

				<?php if ( empty( sunshine_get_active_shipping_methods() ) ) { ?>
					<li>
						<div>
							<p>Configure shipping methods to get orders to customers</p>
							<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine&section=shipping_methods' ) ); ?>">Setup shipping methods</a></p>
						</div>
					</li>
				<?php } else { ?>
					<li class="completed"><p>Shipping methods</p></li>
				<?php } ?>

				<?php if ( wp_count_posts( 'sunshine-product' )->publish <= 0 ) { ?>
					<li>
						<div>
							<p>Create products and set prices to start selling</p>
							<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-product' ) ); ?>">Add products</a></p>
						</div>
					</li>
				<?php } else { ?>
					<li class="completed"><p>Products</p></li>
				<?php } ?>

				<?php if ( ! SPC()->get_option( 'logo' ) ) { ?>
					<li>
						<div>
							<p>Customize the look of Sunshine with your logo and other options</p>
							<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine&section=display' ) ); ?>">Configure display options</a></p>
						</div>
					</li>
				<?php } else { ?>
					<li class="completed"><p>Customization</p></li>
				<?php } ?>

				<?php if ( ! SPC()->is_pro() ) { ?>
					<li>
						<div>
							<p>Upgrade for more features to help increase revenue</p>
							<p><a href="https://www.sunshinephotocart.com/upgrade/?utm_source=plugin&utm_medium=link&utm_campaign=dashboardwidget" target="_blank">Learn more about Pro</a></p>
						</div>
					</li>
				<?php } ?>

			</ol>
		</div>
		<?php
	}

}

$spc_dashboard_widget = new SPC_Dashboard_Widget();
