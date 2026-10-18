<?php
/**
 * Helpers shared by the addon-api-reports-*.php tests: orders made and read through the
 * API add-on, the Exports add-on order file, and the profit the Advanced Reports add-on
 * shows on an order.
 *
 * Nothing these tests do may reach another site: every web request is stopped before it
 * leaves and every email is stopped.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

$t = 'SPC_Totals_Test';

// Run one test with web requests and emails stopped. A blocked request fails the test.
$api_reports_test = function ( $name, $body ) use ( $t ) {
	$t::test(
		$name,
		function () use ( $t, $body ) {
			$sent    = array();
			$block   = function ( $pre, $args, $url ) use ( &$sent ) {
				$sent[] = $url;
				return new WP_Error( 'spc_totals_offline', 'Web requests are stopped by the totals tests.' );
			};
			$no_mail = function () {
				return false;
			};
			add_filter( 'pre_http_request', $block, 1, 3 );
			add_filter( 'pre_wp_mail', $no_mail, 1 );
			try {
				$body();
			} finally {
				remove_filter( 'pre_http_request', $block, 1 );
				remove_filter( 'pre_wp_mail', $no_mail, 1 );
			}
			if ( ! empty( $sent ) ) {
				$t::same( array(), $sent, 'web requests that tried to leave the site' );
			}
		}
	);
};

// One admin user for the API calls, made the first time it is needed and deleted at the end.
$api_reports_admin_id = 0;
$api_reports_admin    = function () use ( $t, &$api_reports_admin_id ) {
	if ( ! $api_reports_admin_id || ! get_userdata( $api_reports_admin_id ) ) {
		$id = wp_insert_user(
			array(
				'user_login' => 'spcapireports' . wp_rand( 100000, 999999 ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new Exception( 'Could not make the API admin: ' . $id->get_error_message() );
		}
		$t::track( 'user', $id );
		$api_reports_admin_id = $id;
	}
	return $api_reports_admin_id;
};

// Call a sunshine/v1 endpoint inside this process, as the admin, the way an API client would.
$api_reports_rest = function ( $method, $path, $body = null, $query = array() ) use ( $api_reports_admin ) {
	$previous = get_current_user_id();
	wp_set_current_user( $api_reports_admin() );
	$request = new WP_REST_Request( $method, '/sunshine/v1' . $path );
	if ( ! empty( $query ) ) {
		$request->set_query_params( $query );
	}
	if ( null !== $body ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	$response = rest_do_request( $request );
	wp_set_current_user( $previous );
	return $response;
};

$api_reports_require_api = function () use ( $t ) {
	$t::require_plugin( 'sunshine_api_init', 'API add-on' );
};

// Create an order through POST /orders and return its id. It is deleted at the end.
$api_reports_create_order = function ( $body ) use ( $t, $api_reports_rest, $api_reports_require_api ) {
	$api_reports_require_api();
	$response = $api_reports_rest( 'POST', '/orders', $body );
	$data     = $response->get_data();
	if ( ! empty( $data['id'] ) ) {
		$t::track( 'order', (int) $data['id'] );
	}
	if ( 201 !== $response->get_status() || empty( $data['id'] ) ) {
		throw new Exception( 'The API did not create the order: ' . $response->get_status() . ' ' . substr( wp_json_encode( $data ), 0, 300 ) );
	}
	return (int) $data['id'];
};

// A product with a cost, saved the way the product screen saves the Cost field (a string).
$api_reports_product = function ( $price, $cost = '', $args = array() ) use ( $t ) {
	$id = $t::product( $price, $args );
	if ( '' !== $cost ) {
		update_post_meta( $id, 'cost', (string) $cost );
	}
	return $id;
};

// Money as the order screen works it out from the saved order lines.
$api_reports_lines = function ( $order_id ) {
	$out = array(
		'total' => 0.0,
		'tax'   => 0.0,
		'count' => 0,
	);
	foreach ( (array) sunshine_get_order( $order_id )->get_items() as $item ) {
		$out['total'] += (float) $item->get_total();
		$out['tax']   += (float) $item->get_tax_total();
		$out['count']++;
	}
	return $out;
};

// A one-minute window in the 1990s, so a report for it only sees the orders a test makes there.
$api_reports_window = function () {
	$ts = gmmktime( wp_rand( 0, 23 ), wp_rand( 0, 59 ), 0, wp_rand( 1, 12 ), wp_rand( 1, 28 ), wp_rand( 1991, 1999 ) );
	return array(
		'date'   => gmdate( 'Y-m-d\TH:i:00\Z', $ts ),
		'after'  => gmdate( 'Y-m-d H:i:00', $ts ),
		'before' => gmdate( 'Y-m-d H:i:59', $ts ),
	);
};

// Run a report through GET /reports/{slug} for one window and return its data.
$api_reports_report = function ( $slug, $window, $query = array() ) use ( $api_reports_rest ) {
	$response = $api_reports_rest(
		'GET',
		'/reports/' . $slug,
		null,
		array_merge(
			array(
				'after'  => $window['after'],
				'before' => $window['before'],
			),
			$query
		)
	);
	$data     = $response->get_data();
	if ( 200 !== $response->get_status() || ! isset( $data['data'] ) ) {
		throw new Exception( 'Report ' . $slug . ' failed: ' . $response->get_status() . ' ' . substr( wp_json_encode( $data ), 0, 300 ) );
	}
	return $data['data'];
};

// Build the Exports add-on order file for just these orders, the way the Exports screen does,
// and read it back. Returns array( 'headers' => [...], 'rows' => [ [ header => value ] ], 'raw' => [ [...] ] ).
$api_reports_export = function ( $order_ids, $format = 'combined', $file_format = 'csv' ) use ( $t ) {
	$t::require_plugin( 'sunshine_get_exports', 'Exports add-on' );
	if ( ! class_exists( 'SPC_Export_Orders' ) ) {
		// The export classes load only in the admin. The add-on's REST route loads them the same way.
		$dir = dirname( ( new ReflectionFunction( 'sunshine_get_exports' ) )->getFileName() );
		require_once $dir . '/class-export.php';
		require_once $dir . '/class-export-orders.php';
		require_once $dir . '/class-export-customers.php';
		require_once $dir . '/class-export-galleries.php';
	}

	// A fresh export each time: the export keeps the list of orders it found.
	$export = new SPC_Export_Orders();
	remove_filter( 'sunshine_exports', array( $export, 'register_export' ) );
	remove_action( 'sunshine_export_options_' . $export->get_id(), array( $export, 'export_options' ) );
	$export->set_file_format( $file_format );

	$saved_post = $_POST;
	$_POST      = array(
		'export_format' => $format,
		'filename'      => 'spc-totals-' . wp_generate_password( 8, false ) . '.' . $file_format,
	);
	$only       = function ( $args ) use ( $order_ids ) {
		$args['post__in'] = array_map( 'intval', (array) $order_ids );
		return $args;
	};
	add_filter( 'sunshine_get_order_args', $only );

	$fh = fopen( 'php://temp', 'w+' );
	try {
		$export->write_file_header( $fh );
		$total     = (int) $export->get_total();
		$processed = 0;
		$guard     = 0;
		while ( $processed < $total && $guard++ < 50 ) {
			$_POST['starting_from'] = $processed;
			$wrote                  = (int) $export->process_rows( $fh, array( 'starting_from' => $processed ) );
			if ( $wrote <= 0 ) {
				break;
			}
			$processed += $wrote;
		}
		$export->write_file_footer( $fh );
		$headers = array_values( $export->get_headers() );

		rewind( $fh );
		$raw = array();
		if ( 'xml' === $file_format ) {
			$xml = simplexml_load_string( stream_get_contents( $fh ) );
			foreach ( $xml->row as $row ) {
				$cells = array();
				foreach ( $row->children() as $cell ) {
					$cells[] = (string) $cell;
				}
				$raw[] = $cells;
			}
		} else {
			fgetcsv( $fh, 0, ',', '"', '\\' ); // Header row.
			while ( false !== ( $cells = fgetcsv( $fh, 0, ',', '"', '\\' ) ) ) {
				if ( array( null ) !== $cells ) {
					$raw[] = $cells;
				}
			}
		}
	} finally {
		fclose( $fh );
		remove_filter( 'sunshine_get_order_args', $only );
		if ( 'columns' === $format ) {
			// The columns format keeps its product column count in a transient for the run.
			$key = new ReflectionMethod( $export, 'get_max_items_transient_key' );
			$key->setAccessible( true );
			delete_transient( $key->invoke( $export ) );
		}
		$_POST = $saved_post;
	}

	$rows = array();
	foreach ( $raw as $cells ) {
		$row = array();
		foreach ( $headers as $i => $header ) {
			$row[ $header ] = isset( $cells[ $i ] ) ? $cells[ $i ] : null;
		}
		$rows[] = $row;
	}
	return array(
		'headers' => $headers,
		'rows'    => $rows,
		'raw'     => $raw,
	);
};

// The Profit row the Advanced Reports add-on adds to the totals on the admin order screen.
$api_reports_profit_shown = function ( $order_id ) use ( $t ) {
	$t::require_plugin( 'sunshine_adv_report_admin_order_totals', 'Advanced Reports add-on' );
	$t::option( 'price_suffix', '' );
	$t::option( 'currency_symbol_position', 'left' );
	$t::option( 'currency_thousands_separator', ',' );
	$t::option( 'currency_decimal_separator', '.' );
	ob_start();
	do_action( 'sunshine_admin_order_totals', sunshine_get_order( $order_id ) );
	$html = ob_get_clean();
	if ( ! preg_match( '#<th>\s*Profit\s*</th>\s*<td>(.*?)</td>#s', $html, $match ) ) {
		return null;
	}
	$text = html_entity_decode( wp_strip_all_tags( $match[1] ), ENT_QUOTES, 'UTF-8' );
	if ( ! preg_match( '/(-?)[^0-9\-]*([0-9][0-9,]*(?:\.[0-9]+)?)/', $text, $amount ) ) {
		return null;
	}
	$value = (float) str_replace( ',', '', $amount[2] );
	return ( '-' === $amount[1] ) ? -$value : $value;
};
