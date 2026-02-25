<?php
function sunshine_reports_page() {
	global $wpdb;

	// Get the very first order. Will determine if we have any orders at all and what dates to limit stuff to
	$init_order = sunshine_get_orders(
		array(
			'nopaging'       => false,
			'posts_per_page' => 1,
			'order'          => 'ASC',
		),
	);
	$init_date  = ( ! empty( $init_order ) ) ? $init_order[0]->get_date( 'Y-m-d' ) : date( 'Y-m-d', SPC()->get_option( 'install_time' ) );

	$available_reports = array(
		'orders'    => __( 'Orders', 'sunshine-photo-cart' ),
		'products'  => __( 'Products', 'sunshine-photo-cart' ),
		'galleries' => __( 'Galleries', 'sunshine-photo-cart' ),
		'images'    => __( 'Images', 'sunshine-photo-cart' ),
		'customers' => __( 'Customers', 'sunshine-photo-cart' ),
		'profits'   => __( 'Profits', 'sunshine-photo-cart' ),
		'tax'       => __( 'Tax', 'sunshine-photo-cart' ),
	);
	$available_reports = apply_filters( 'sunshine_reports', $available_reports );

	$report = 'orders';
	if ( ! empty( $_GET['report'] ) ) {
		$selected_report = esc_attr( $_GET['report'] );
		if ( array_key_exists( $selected_report, $available_reports ) ) {
			$report = $selected_report;
		}
	}

	$durations        = apply_filters(
		'sunshine_reports_date_formats',
		array(
			'day'   => array(
				'label'  => __( 'Day', 'sunshine-photo-cart' ),
				'after'  => date( 'Y-m-d' ),
				'before' => date( 'Y-m-d' ),
			),
			'week'  => array(
				'label'  => __( 'Week', 'sunshine-photo-cart' ),
				'after'  => date( 'Y-m-d', strtotime( '-7 days' ) ),
				'before' => date( 'Y-m-d' ),
			),
			'month' => array(
				'label'  => __( 'Month', 'sunshine-photo-cart' ),
				'after'  => date( 'Y-m-d', strtotime( '-1 months' ) ),
				'before' => date( 'Y-m-d' ),
			),
			'year'  => array(
				'label'  => __( 'Year', 'sunshine-photo-cart' ),
				'active' => '',
				'after'  => date( 'Y-m-d', strtotime( '-1 years' ) ),
				'before' => date( 'Y-m-d' ),
			),
			'all'   => array(
				'label'  => __( 'All time', 'sunshine-photo-cart' ),
				'after'  => $init_date,
				'before' => date( 'Y-m-d' ),
			),
		)
	);
	$current_duration = ( isset( $_GET['duration'] ) ) ? sanitize_text_field( $_GET['duration'] ) : 'month';
	$current_after    = ( isset( $_GET['after'] ) ) ? sanitize_text_field( $_GET['after'] ) : $durations[ $current_duration ]['after'];
	$current_before   = ( isset( $_GET['before'] ) ) ? sanitize_text_field( $_GET['before'] ) : $durations[ $current_duration ]['before'];

	if ( isset( $_GET['after'] ) && isset( $_GET['before'] ) ) {
		if ( $_GET['after'] == $_GET['before'] ) {
			$current_duration = 'day';
		} else {
			$current_duration = 'custom';
		}
	}

	// Append time for more specific
	$current_after  .= ' 00:00:00';
	$current_before .= ' 23:59:59';
	?>

	<div class="wrap">

		<h1></h1>

		<div id="sunshine-reports-header">
			<div id="sunshine-reports-header--title">
				<h1><?php esc_html_e( 'Reports', 'sunshine-photo-cart' ); ?></h1>
			</div>
			<div id="sunshine-reports-header--filter">
				<div id="sunshine-reports-header--filter--dates">
					<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
						<input type="hidden" name="post_type" value="sunshine-gallery" />
						<input type="hidden" name="page" value="sunshine-reports" />
						<input type="hidden" name="report" value="<?php echo esc_attr( $report ); ?>" />
						<input type="hidden" name="duration" value="custom" />
							<input type="date" name="after" value="<?php echo esc_attr( date( 'Y-m-d', strtotime( $current_after ) ) ); ?>" min="<?php echo esc_attr( $init_date ); ?>" max="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>" />
						<input type="date" name="before" value="<?php echo esc_attr( date( 'Y-m-d', strtotime( $current_before ) ) ); ?>" min="<?php echo esc_attr( $init_date ); ?>" max="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>" />
						<input type="submit" value="<?php esc_attr_e( 'Filter', 'sunshine-photo-cart' ); ?>" class="button" />
					</form>
				</div>
				<nav>
					<?php
					foreach ( $durations as $key => $duration ) {
						$class = ( $key == $current_duration ) ? 'active' : '';
						echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-reports&duration=' . esc_attr( $key ) . '&report=' . esc_attr( $report ) ) ) . '">' . esc_html( $duration['label'] ) . '</a>';
					}
					?>
				</nav>
			</div>
		</div>

		<div id="sunshine-page--main">

			<?php if ( count( $available_reports ) > 1 ) { ?>
				<nav id="sunshine-page--nav">
					<ul>
					<?php foreach ( $available_reports as $key => $label ) { ?>
						<?php
						$url = add_query_arg( $_GET );
						$url = add_query_arg( 'report', $key, $url );
						$url = remove_query_arg( 'refresh', $url );
						?>
						<li id="sunshine-page--nav--<?php echo esc_attr( $key ); ?>" <?php echo ( $key === $report ) ? ' class="sunshine-page--nav--active"' : ''; ?>><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a></li>
					<?php } ?>
					</ul>
				</nav>
			<?php } ?>

			<div id="sunshine-page--content">
				<?php do_action( 'sunshine_report_' . $report, $current_duration, $current_after, $current_before ); ?>
				<p align="right"><a href="<?php echo esc_url( add_query_arg( 'refresh', 1 ) ); ?>"><?php esc_html_e( 'Refresh data', 'sunshine-photo-cart' ); ?></a></p>
			</div>

		</div>

	</div>
	<?php
}

add_action( 'sunshine_report_orders', 'sunshine_report_orders_display', 10, 3 );
function sunshine_report_orders_display( $current_duration, $current_after, $current_before ) {

	$transient_key = 'sunshine_reports_orders' . md5( $current_after . $current_before );
	$data          = get_transient( $transient_key );

	if ( ! $data || isset( $_GET['refresh'] ) ) {

		$args        = array(
			'status' => sunshine_order_statuses_paid(),
			'after'  => array(
				'year'  => date( 'Y', strtotime( $current_after ) ),
				'month' => date( 'n', strtotime( $current_after ) ),
				'day'   => date( 'j', strtotime( $current_after ) ),
			),
			'before' => array(
				'year'  => date( 'Y', strtotime( $current_before ) ),
				'month' => date( 'n', strtotime( $current_before ) ),
				'day'   => date( 'j', strtotime( $current_before ) ),
			),
		);
		$paid_orders = sunshine_get_orders( $args );

		$args                 = array(
			'status' => sunshine_order_statuses_needs_payment(),
			'after'  => array(
				'year'  => date( 'Y', strtotime( $current_after ) ),
				'month' => date( 'n', strtotime( $current_after ) ),
				'day'   => date( 'j', strtotime( $current_after ) ),
			),
			'before' => array(
				'year'  => date( 'Y', strtotime( $current_before ) ),
				'month' => date( 'n', strtotime( $current_before ) ),
				'day'   => date( 'j', strtotime( $current_before ) ),
			),
		);
		$needs_payment_orders = sunshine_get_orders( $args );

		$paid_labels    = $paid_values = array();
		$paid_count     = $paid_total = $needs_payment_count = $needs_payment_total = 0;
		$key_format     = 'Y-m-d';
		$display_format = 'M j';

		switch ( $current_duration ) {
			case 'day':
				$interval       = DateInterval::createFromDateString( '1 hour' );
				$key_format     = 'Y-m-d-H';
				$display_format = 'D gA';
				break;
			case 'all':
				$diff = date_diff( date_create( $current_after ), date_create( $current_before ) );
				if ( $diff->days > 365 ) {
					$interval       = DateInterval::createFromDateString( '1 month' );
					$key_format     = 'Y-m';
					$display_format = 'M Y';
				} else {
					$interval = DateInterval::createFromDateString( '1 day' );
				}
				break;
			default:
				$interval = DateInterval::createFromDateString( '1 day' );
				break;
		}

		$period = new DatePeriod( new DateTime( $current_after ), $interval, new DateTime( $current_before ) );
		foreach ( $period as $dt ) {
			$paid_labels[ $dt->format( $key_format ) ] = $dt->format( $display_format );
			$paid_values[ $dt->format( $key_format ) ] = 0;
		}

		if ( ! empty( $paid_orders ) ) {

			foreach ( $paid_orders as $order ) {
				$paid_values[ $order->get_date( $key_format ) ] += $order->get_total();
				$paid_total                                     += $order->get_total();
			}

			$paid_count = count( $paid_orders );

			ksort( $paid_labels );
			ksort( $paid_values );

		}

		$needs_payment_count = 0;
		$needs_payment_total = 0;

		if ( ! empty( $needs_payment_orders ) ) {

			foreach ( $needs_payment_orders as $order ) {
				$needs_payment_total += $order->get_total();
			}

			$needs_payment_count = count( $needs_payment_orders );

		}

		$data = array(
			'paid_total'          => $paid_total,
			'paid_count'          => $paid_count,
			'needs_payment_count' => $needs_payment_count,
			'needs_payment_total' => $needs_payment_total,
			'paid_labels'         => $paid_labels,
			'paid_values'         => $paid_values,
		);

		set_transient( $transient_key, $data, HOUR_IN_SECONDS );

	}
	?>

	<?php if ( ! SPC()->is_pro() && $data['paid_total'] > 250 ) { ?>
		<div id="celebrate">
			<h2>Good job money maker!</h2>
			<p>You have received enough sales in this period to pay for Sunshine Photo Cart Pro!</p>
			<p><a href="https://www.sunshinephotocart.com/upgrade/?utm_source=plugin&utm_medium=link&utm_campaign=reports" class="sunshine-button" target="_blank">Get Pro!</a></p>
		</div>
	<?php } ?>

	<div id="sunshine-reports--stats">

		<div clas="sunshine-report--stat">
			<h3><?php esc_html_e( 'Total Received', 'sunshine-photo-cart' ); ?></h3>
			<p><?php echo wp_kses_post( sunshine_price( $data['paid_total'], true ) ); ?></p>
		</div>
		<div clas="sunshine-report--stat">
			<h3><?php esc_html_e( 'Completed Orders', 'sunshine-photo-cart' ); ?></h3>
			<p><?php echo esc_html( $data['paid_count'] ); ?></p>
		</div>
		<div clas="sunshine-report--stat">
			<h3><?php esc_html_e( 'Average Order', 'sunshine-photo-cart' ); ?></h3>
			<p><?php echo wp_kses_post( ( $data['paid_count'] ) ? sunshine_price( $data['paid_total'] / $data['paid_count'], true ) : sunshine_price( 0, true ) ); ?></p>
		</div>
		<?php if ( $data['needs_payment_count'] ) { ?>
			<div clas="sunshine-report--stat">
				<h3><?php esc_html_e( 'Total Unpaid Orders', 'sunshine-photo-cart' ); ?></h3>
				<p><?php echo esc_html( $data['needs_payment_count'] ); ?> (<?php echo wp_kses_post( sunshine_price( $data['needs_payment_total'], true ) ); ?>)</p>
			</div>
		<?php } ?>

	</div>

	<div id="sunshine-reports--chart">

		<canvas id="sunshine-chart" width="100%" height="600"></canvas>
		<script>
		const ctx = document.getElementById('sunshine-chart').getContext('2d');
		const myChart = new Chart(ctx, {
			type: 'line',
			data: {
				labels: <?php echo json_encode( array_values( $data['paid_labels'] ) ); ?>,
				datasets: [{
					lineTension: 0.4,
					//label: '# of Votes',
					borderColor: "#FF8500",
					backgroundColor: "rgba(255,133,0,.5)",
					fill: true,
					data: <?php echo json_encode( array_values( $data['paid_values'] ) ); ?>,
				}]
			},
			options: {
				plugins: {
					legend: {
						display: false
					},
					tooltip: {
						mode: 'index',
						intersect: false,
						callbacks: {
							label: function(context) {
								let label = context.dataset.label || '';
								if ( label ) {
									label += ': ';
								}
								if ( context.parsed.y !== null ) {
									label += new Intl.NumberFormat( 'en-US', { style: 'currency', currency: '<?php echo esc_js( SPC()->get_option( 'currency' ) ); ?>' } ).format( context.parsed.y );
								}
								return label;
							}
						}
					}
				},
				hover: {
					mode: 'nearest',
					intersect: false
				},
				maintainAspectRatio: false,
				scales: {
					y: {
						beginAtZero: true,
						ticks: {
							callback: function(value, index, ticks) {
								return new Intl.NumberFormat('en-US', { style: 'currency', currency: '<?php echo esc_js( SPC()->get_option( 'currency' ) ); ?>' }).format(value);
							}
						}
					},
					x: {
						grid: {
							drawBorder: false,
							lineWidth: 0,
						},
						ticks: {
							autoSkip: true,
							maxTicksLimit: 15
						}
					}
				}
			}
		});
		</script>

	</div>

	<?php
}

add_action( 'sunshine_report_tax', 'sunshine_report_tax_display', 10, 3 );
function sunshine_report_tax_display( $current_duration, $current_after, $current_before ) {

	$transient_key = 'sunshine_reports_tax' . md5( $current_after . $current_before );
	$data          = get_transient( $transient_key );

	if ( ! $data || isset( $_GET['refresh'] ) ) {

		$args        = array(
			'status' => sunshine_order_statuses_paid(),
			'after'  => array(
				'year'  => date( 'Y', strtotime( $current_after ) ),
				'month' => date( 'n', strtotime( $current_after ) ),
				'day'   => date( 'j', strtotime( $current_after ) ),
			),
			'before' => array(
				'year'  => date( 'Y', strtotime( $current_before ) ),
				'month' => date( 'n', strtotime( $current_before ) ),
				'day'   => date( 'j', strtotime( $current_before ) ),
			),
		);
		$paid_orders = sunshine_get_orders( $args );

		$tax = 0;
		if ( ! empty( $paid_orders ) ) {
			foreach ( $paid_orders as $order ) {
				$tax += $order->get_tax();
			}
		}

		$data = array(
			'order_count' => count( $paid_orders ),
			'tax'         => $tax,
		);

		set_transient( $transient_key, $data, HOUR_IN_SECONDS );

	}

	?>

	<div id="sunshine-reports--stats">

		<div clas="sunshine-report--stat">
			<h3><?php esc_html_e( 'Tax Collected', 'sunshine-photo-cart' ); ?></h3>
			<p><?php echo wp_kses_post( sunshine_price( $data['tax'], true ) ); ?></p>
		</div>
		<div clas="sunshine-report--stat">
			<h3><?php esc_html_e( 'Completed Orders', 'sunshine-photo-cart' ); ?></h3>
			<p><?php echo esc_html( $data['order_count'] ); ?></p>
		</div>
	</div>

	<?php
}


add_action( 'sunshine_report_products', 'sunshine_report_promo_display', 10, 3 );
add_action( 'sunshine_report_galleries', 'sunshine_report_promo_display', 10, 3 );
add_action( 'sunshine_report_images', 'sunshine_report_promo_display', 10, 3 );
add_action( 'sunshine_report_customers', 'sunshine_report_promo_display', 10, 3 );
add_action( 'sunshine_report_profits', 'sunshine_report_promo_display', 10, 3 );
function sunshine_report_promo_display( $duration, $after, $before ) {
	if ( is_sunshine_addon_active( 'analytics' ) ) {
		return;
	}
	?>
<div style="background: #FFF; padding: 30px 40px; box-shadow: 0 0 5px 0 rgba(0,0,0,.1); border-radius: 5px;">
	<p style="font-size: 22px;"><strong>Get the Advanced Reports & Customer Activity Add-on!</strong></p>
	<p style="font-size: 18px;">Upgrade to get more advanced reports and analytics to help learn what is working most with your customers and increase overall profits:</p>
	<ul style="font-size: 18px;" class="sunshine-check-list">
		<li><strong>Top selling products:</strong> Increase pricing on your top selling products</li>
		<li><strong>Top selling galleries:</strong> What types of galleries are making you the most, focus getting more clients in that area</li>
		<li><strong>Top selling images:</strong> Learn which poses or styles are most often purchased and include those in more sessions</li>
		<li><strong>Top purchasing customers:</strong> See who your ideal customer is to get more like them</li>
		<li><strong>Profits:</strong> Learn what you actually make from each order after the cost of your products</li>
	</ul>
	<p style="font-size: 18px;"><strong>ALSO!</strong> See each of your customers' exact journies in your galleries by viewing when exactly they viewed galleries and images, added images to favorites or to cart, shared an image on social media, and make a purchase!</p>
	<?php if ( SPC()->is_pro() ) { ?>
		<p align="center"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-addons' ) ); ?>" class="button-primary">See your add-ons</a></p>
	<?php } else { ?>
		<p align="center"><a href="https://www.sunshinephotocart.com/addon/analytics/" target="_blank" class="button-primary">View details</a></p>
	<?php } ?>
</div>
	<?php
}
