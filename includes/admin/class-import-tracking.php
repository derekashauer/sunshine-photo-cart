<?php
/**
 * Tools > Import > Sunshine tracking numbers: add tracking to many orders from a CSV file, such as
 * one exported from a shipping app. The columns can be named anything; Sunshine guesses which is
 * which and the photographer can change it before anything is saved.
 *
 * @package Sunshine_Photo_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * The tracking importer.
 */
class SPC_Import_Tracking {

	/**
	 * Importer id, used in the screen's address.
	 */
	const ID = 'sunshine-tracking';

	/**
	 * The fields a row can fill, as field => label.
	 *
	 * @return array
	 */
	public static function get_fields() {
		return array(
			'order'           => __( 'Order number', 'sunshine-photo-cart' ),
			'tracking_number' => __( 'Tracking number', 'sunshine-photo-cart' ),
			'carrier'         => __( 'Carrier', 'sunshine-photo-cart' ),
			'tracking_url'    => __( 'Tracking link', 'sunshine-photo-cart' ),
			'email'           => __( 'Customer email', 'sunshine-photo-cart' ),
		);
	}

	/**
	 * Hook in.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * Add the importer to Tools > Import.
	 */
	public function register() {
		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/import.php';
		register_importer(
			self::ID,
			__( 'Sunshine tracking numbers', 'sunshine-photo-cart' ),
			__( 'Add tracking to many Sunshine Photo Cart orders at once from a CSV file, like one exported from your shipping app.', 'sunshine-photo-cart' ),
			array( $this, 'screen' )
		);
	}

	/**
	 * The importer's three steps: upload, check, import.
	 */
	public function screen() {
		echo '<div class="wrap" id="sunshine-import-tracking">';
		echo '<h1>' . esc_html__( 'Import tracking numbers', 'sunshine-photo-cart' ) . '</h1>';

		if ( ! current_user_can( 'edit_sunshine_orders' ) ) {
			echo '<p>' . esc_html__( 'You need to be able to edit orders to import tracking.', 'sunshine-photo-cart' ) . '</p></div>';
			return;
		}

		$step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- each step checks its own nonce.
		if ( 'check' === $step ) {
			$this->check_step();
		} elseif ( 'import' === $step ) {
			$this->import_step();
		} else {
			$this->upload_step();
		}

		echo '</div>';
	}

	/**
	 * Step 1: pick the file.
	 */
	private function upload_step() {
		echo '<p>' . esc_html__( 'Upload a CSV file with an order number and a tracking number on each row, like the one your shipping app exports. The columns can have any names. You\'ll see what will change before anything is saved.', 'sunshine-photo-cart' ) . '</p>';
		echo '<p>' . esc_html__( 'A file with no order numbers works too, when it has the customer\'s email and that customer has one order waiting to ship.', 'sunshine-photo-cart' ) . '</p>';
		wp_import_upload_form( admin_url( 'admin.php?import=' . self::ID . '&step=check' ) );
	}

	/**
	 * Step 2: say what each row will do, and let the photographer fix which column is which.
	 */
	private function check_step() {
		if ( isset( $_POST['file_id'] ) ) {
			check_admin_referer( 'sunshine_import_tracking' );
			$file_id = intval( $_POST['file_id'] );
		} else {
			check_admin_referer( 'import-upload' );
			$upload = wp_import_handle_upload();
			if ( isset( $upload['error'] ) ) {
				$this->error( $upload['error'] );
				return;
			}
			$file_id = intval( $upload['id'] );
		}

		$csv = $this->read( $file_id );
		if ( is_wp_error( $csv ) ) {
			$this->error( $csv->get_error_message() );
			return;
		}
		$columns = isset( $_POST['columns'] ) && is_array( $_POST['columns'] ) ? $this->clean_columns( wp_unslash( $_POST['columns'] ), $csv['headers'] ) : $this->guess_columns( $csv['headers'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_columns() keeps only column numbers.
		$plan    = $this->plan( $csv['rows'], $columns );
		$ready   = count( array_filter( wp_list_pluck( $plan, 'package' ) ) );
		$orders  = count( array_unique( wp_list_pluck( array_filter( $plan, array( $this, 'is_ready' ) ), 'order_id' ) ) );
		$action  = admin_url( 'admin.php?import=' . self::ID . '&step=' );
		?>
		<style>
			#sunshine-import-tracking .sunshine-import-columns th { width: 160px; text-align: left; font-weight: normal; padding: 6px 10px 6px 0; }
			#sunshine-import-tracking .sunshine-import-columns td { padding: 6px 0; }
			#sunshine-import-tracking .sunshine-import-columns select { min-width: 280px; }
			#sunshine-import-tracking table.widefat { max-width: 1100px; margin: 10px 0 20px; }
			#sunshine-import-tracking .sunshine-import-skip { color: #b32d2e; }
			#sunshine-import-tracking .sunshine-import-note { color: #646970; }
		</style>
		<form method="post" action="<?php echo esc_url( $action . 'check' ); ?>" id="sunshine-import-tracking-form">
			<?php wp_nonce_field( 'sunshine_import_tracking' ); ?>
			<input type="hidden" name="file_id" value="<?php echo esc_attr( $file_id ); ?>" />

			<h2><?php esc_html_e( 'Which column is which', 'sunshine-photo-cart' ); ?></h2>
			<p><?php esc_html_e( 'Sunshine picked these from your column names. Change any that are wrong.', 'sunshine-photo-cart' ); ?></p>
			<table class="sunshine-import-columns">
				<?php foreach ( self::get_fields() as $field => $label ) { ?>
					<tr>
						<th><label for="sunshine-import-column-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<select name="columns[<?php echo esc_attr( $field ); ?>]" id="sunshine-import-column-<?php echo esc_attr( $field ); ?>" onchange="this.form.submit();">
								<option value=""><?php esc_html_e( 'Not in this file', 'sunshine-photo-cart' ); ?></option>
								<?php foreach ( $csv['headers'] as $index => $header ) { ?>
									<option value="<?php echo esc_attr( $index ); ?>" <?php selected( $columns[ $field ], $index ); ?>><?php echo esc_html( $this->column_label( $csv, $index ) ); ?></option>
								<?php } ?>
							</select>
						</td>
					</tr>
				<?php } ?>
			</table>
			<noscript><p><button type="submit" class="button"><?php esc_html_e( 'Check again', 'sunshine-photo-cart' ); ?></button></p></noscript>
		</form>

		<h2><?php esc_html_e( 'What will happen', 'sunshine-photo-cart' ); ?></h2>
		<p>
			<?php
			/* translators: %d: number of tracking numbers */
			$numbers = sprintf( _n( '%d tracking number will be added', '%d tracking numbers will be added', $ready, 'sunshine-photo-cart' ), $ready );
			/* translators: %d: number of orders */
			$to = sprintf( _n( 'to %d order.', 'to %d orders.', $orders, 'sunshine-photo-cart' ), $orders );
			/* translators: %d: number of rows in the file */
			$rows = sprintf( _n( 'The file has %d row.', 'The file has %d rows.', count( $plan ), 'sunshine-photo-cart' ), count( $plan ) );
			/* translators: %1$s: "3 tracking numbers will be added", %2$s: "to 2 orders.", %3$s: "The file has 5 rows." */
			echo esc_html( sprintf( __( '%1$s %2$s %3$s', 'sunshine-photo-cart' ), $numbers, $to, $rows ) );
			?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Row', 'sunshine-photo-cart' ); ?></th>
					<th><?php esc_html_e( 'Order', 'sunshine-photo-cart' ); ?></th>
					<th><?php esc_html_e( 'Carrier', 'sunshine-photo-cart' ); ?></th>
					<th><?php esc_html_e( 'Tracking number', 'sunshine-photo-cart' ); ?></th>
					<th><?php esc_html_e( 'Result', 'sunshine-photo-cart' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $plan as $row ) { ?>
					<tr>
						<td><?php echo esc_html( $row['line'] ); ?></td>
						<td>
							<?php
							if ( $row['order_id'] ) {
								echo '<a href="' . esc_url( get_edit_post_link( $row['order_id'] ) ) . '" target="_blank">' . esc_html( $row['order_name'] ) . '</a>';
								if ( $row['customer'] ) {
									echo '<br><span class="sunshine-import-note">' . esc_html( $row['customer'] ) . '</span>';
								}
							} else {
								echo esc_html( $row['order_text'] );
							}
							?>
						</td>
						<td><?php echo esc_html( $row['carrier'] ); ?></td>
						<td><?php echo esc_html( $row['tracking_number'] ); ?></td>
						<td class="<?php echo $row['package'] ? '' : 'sunshine-import-skip'; ?>"><?php echo esc_html( $row['result'] ); ?></td>
					</tr>
				<?php } ?>
			</tbody>
		</table>

		<?php if ( $ready ) { ?>
			<form method="post" action="<?php echo esc_url( $action . 'import' ); ?>">
				<?php wp_nonce_field( 'sunshine_import_tracking' ); ?>
				<input type="hidden" name="file_id" value="<?php echo esc_attr( $file_id ); ?>" />
				<?php foreach ( $columns as $field => $index ) { ?>
					<input type="hidden" name="columns[<?php echo esc_attr( $field ); ?>]" value="<?php echo esc_attr( $index ); ?>" />
				<?php } ?>
				<p><label><input type="checkbox" name="mark_shipped" value="1" checked /> <?php esc_html_e( 'Mark these orders Shipped/Completed', 'sunshine-photo-cart' ); ?></label></p>
				<p><label><input type="checkbox" name="notify" value="1" checked /> <?php esc_html_e( 'Email each customer their tracking (the Order Status Update email)', 'sunshine-photo-cart' ); ?></label></p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import tracking', 'sunshine-photo-cart' ); ?></button></p>
			</form>
		<?php } else { ?>
			<p><?php esc_html_e( 'Nothing in this file can be added yet. Check the columns above, or fix the file and upload it again.', 'sunshine-photo-cart' ); ?></p>
		<?php } ?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?import=' . self::ID ) ); ?>"><?php esc_html_e( 'Upload a different file', 'sunshine-photo-cart' ); ?></a></p>
		<?php
	}

	/**
	 * Step 3: add the tracking. The file is read and checked again, so nothing from the check
	 * screen is trusted.
	 */
	private function import_step() {
		check_admin_referer( 'sunshine_import_tracking' );
		$file_id = isset( $_POST['file_id'] ) ? intval( $_POST['file_id'] ) : 0;
		$csv     = $this->read( $file_id );
		if ( is_wp_error( $csv ) ) {
			$this->error( $csv->get_error_message() );
			return;
		}
		$columns = isset( $_POST['columns'] ) && is_array( $_POST['columns'] ) ? $this->clean_columns( wp_unslash( $_POST['columns'] ), $csv['headers'] ) : $this->guess_columns( $csv['headers'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_columns() keeps only column numbers.
		$plan    = $this->plan( $csv['rows'], $columns );
		$ship    = ! empty( $_POST['mark_shipped'] );
		$notify  = ! empty( $_POST['notify'] );

		$added   = 0;
		$touched = array();
		foreach ( array_filter( $plan, array( $this, 'is_ready' ) ) as $row ) {
			$order = new SPC_Order( $row['order_id'] );
			if ( ! $order->add_tracking( $row['package'] ) ) {
				continue; // Two rows in the file with the same number.
			}
			/* translators: %1$s: carrier, %2$s: tracking number */
			$order->add_log( sprintf( __( 'Tracking added from an import: %1$s %2$s', 'sunshine-photo-cart' ), $row['package']['carrier'], $row['package']['tracking_number'] ) );
			++$added;
			$touched[ $row['order_id'] ] = true;
		}

		$shipped = 0;
		$emailed = 0;
		foreach ( array_keys( $touched ) as $order_id ) {
			$order = new SPC_Order( $order_id );
			if ( $ship && 'shipped' !== $order->get_status() ) {
				$order->set_status( 'shipped', __( 'Marked Shipped/Completed by a tracking import', 'sunshine-photo-cart' ), true );
				++$shipped;
			}
			if ( $notify ) {
				do_action( 'sunshine_admin_order_status_update', $order );
				/* translators: %s is the customer email address */
				$order->add_log( sprintf( __( 'Order status notification email sent to %s', 'sunshine-photo-cart' ), $order->get_email() ) );
				++$emailed;
			}
		}

		wp_import_cleanup( $file_id );

		echo '<div class="notice notice-success inline"><p>';
		/* translators: %d: number of tracking numbers */
		$numbers = sprintf( _n( 'Added %d tracking number', 'Added %d tracking numbers', $added, 'sunshine-photo-cart' ), $added );
		/* translators: %d: number of orders */
		$to = sprintf( _n( 'to %d order.', 'to %d orders.', count( $touched ), 'sunshine-photo-cart' ), count( $touched ) );
		/* translators: %1$s: "Added 3 tracking numbers", %2$s: "to 2 orders." */
		echo esc_html( sprintf( __( '%1$s %2$s', 'sunshine-photo-cart' ), $numbers, $to ) );
		if ( $shipped ) {
			/* translators: %d: number of orders */
			echo ' ' . esc_html( sprintf( _n( '%d marked Shipped/Completed.', '%d marked Shipped/Completed.', $shipped, 'sunshine-photo-cart' ), $shipped ) );
		}
		if ( $emailed ) {
			/* translators: %d: number of customers emailed */
			echo ' ' . esc_html( sprintf( _n( '%d customer emailed.', '%d customers emailed.', $emailed, 'sunshine-photo-cart' ), $emailed ) );
		}
		echo '</p></div>';

		$skipped = array_filter(
			$plan,
			function ( $row ) {
				return empty( $row['package'] );
			}
		);
		if ( $skipped ) {
			echo '<h2>' . esc_html__( 'Rows that were skipped', 'sunshine-photo-cart' ) . '</h2><ul>';
			foreach ( $skipped as $row ) {
				/* translators: %1$d: row number in the file, %2$s: why it was skipped */
				echo '<li>' . esc_html( sprintf( __( 'Row %1$d: %2$s', 'sunshine-photo-cart' ), $row['line'], $row['result'] ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=sunshine-order' ) ) . '">' . esc_html__( 'Go to orders', 'sunshine-photo-cart' ) . '</a></p>';
	}

	/**
	 * Read the uploaded file: its header row and the rows under it. Commas, semicolons and tabs
	 * all work, and a spreadsheet's byte-order mark is dropped.
	 *
	 * @param int $file_id Attachment id from wp_import_handle_upload().
	 * @return array|WP_Error headers and rows, each row with its line number.
	 */
	private function read( $file_id ) {
		$path = $file_id ? get_attached_file( $file_id ) : '';
		if ( ! $path || ! is_readable( $path ) ) {
			return new WP_Error( 'missing', __( 'The uploaded file can\'t be found. Please upload it again.', 'sunshine-photo-cart' ) );
		}
		$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local upload.
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		$first     = strtok( $text, "\n" );
		$delimiter = ',';
		foreach ( array( "\t", ';' ) as $other ) {
			if ( substr_count( (string) $first, $other ) > substr_count( (string) $first, $delimiter ) ) {
				$delimiter = $other;
			}
		}

		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $handle, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );
		$headers = null;
		$rows    = array();
		$line    = 0;
		while ( ( $row = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$line;
			if ( array( null ) === $row || '' === implode( '', array_map( 'trim', $row ) ) ) {
				continue;
			}
			if ( null === $headers ) {
				$headers = array_map( 'trim', $row );
				continue;
			}
			$rows[] = array(
				'line'   => $line,
				'values' => array_map( 'trim', $row ),
			);
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( empty( $headers ) || empty( $rows ) ) {
			return new WP_Error( 'empty', __( 'This file has no rows under its column names. It needs a first row naming the columns, then one row per package.', 'sunshine-photo-cart' ) );
		}
		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * Guess each field's column from the column names, so most shipping app exports work as they
	 * come. Each column is used for one field at most.
	 *
	 * @param array $headers Column names.
	 * @return array field => column index, or '' when there's no match.
	 */
	public function guess_columns( $headers ) {
		$names = array();
		foreach ( $headers as $index => $header ) {
			$names[ $index ] = trim( preg_replace( '/[^a-z0-9#]+/', ' ', strtolower( $header ) ) );
		}
		// For each field, word lists to look for, best first. Every word in a list must be there.
		$rules   = array(
			'tracking_url'    => array( array( 'tracking', 'url' ), array( 'tracking', 'link' ), array( 'url' ), array( 'link' ) ),
			'tracking_number' => array( array( 'tracking', 'number' ), array( 'tracking', '#' ), array( 'tracking', 'no' ), array( 'tracking', 'code' ), array( 'tracking', 'id' ), array( 'tracking' ), array( 'waybill' ), array( 'airbill' ) ),
			'order'           => array( array( 'order', 'number' ), array( 'order', '#' ), array( 'order', 'no' ), array( 'order', 'id' ), array( 'order' ), array( 'reference' ), array( 'ref' ) ),
			'email'           => array( array( 'email' ), array( 'e', 'mail' ) ),
			'carrier'         => array( array( 'carrier' ), array( 'courier' ), array( 'provider' ), array( 'shipping', 'service' ), array( 'service' ) ),
		);
		$columns = array_fill_keys( array_keys( self::get_fields() ), '' );
		$used    = array();
		foreach ( $rules as $field => $lists ) {
			foreach ( $lists as $words ) {
				foreach ( $names as $index => $name ) {
					if ( isset( $used[ $index ] ) ) {
						continue;
					}
					$parts = explode( ' ', str_replace( '#', ' # ', $name ) );
					if ( ! array_diff( $words, $parts ) ) {
						$columns[ $field ] = $index;
						$used[ $index ]    = true;
						continue 3;
					}
				}
			}
		}
		return $columns;
	}

	/**
	 * Keep only real column numbers from a posted choice.
	 *
	 * @param array $posted  field => column index.
	 * @param array $headers Column names.
	 * @return array
	 */
	private function clean_columns( $posted, $headers ) {
		$columns = array_fill_keys( array_keys( self::get_fields() ), '' );
		foreach ( $columns as $field => $value ) {
			if ( isset( $posted[ $field ] ) && '' !== $posted[ $field ] && isset( $headers[ intval( $posted[ $field ] ) ] ) ) {
				$columns[ $field ] = intval( $posted[ $field ] );
			}
		}
		return $columns;
	}

	/**
	 * What each row will do: the order it matched and the package to add, or why it's skipped.
	 *
	 * @param array $rows    Rows from read().
	 * @param array $columns field => column index.
	 * @return array One entry per row.
	 */
	public function plan( $rows, $columns ) {
		$plan = array();
		$seen = array();
		foreach ( $rows as $row ) {
			$value = function ( $field ) use ( $row, $columns ) {
				return ( '' !== $columns[ $field ] && isset( $row['values'][ $columns[ $field ] ] ) ) ? $row['values'][ $columns[ $field ] ] : '';
			};

			$number = sanitize_text_field( $value( 'tracking_number' ) );
			$url    = esc_url_raw( $value( 'tracking_url' ) );
			$text   = sanitize_text_field( $value( 'carrier' ) );
			$known  = sunshine_guess_tracking_carrier( $text, $number );
			$entry  = array(
				'line'            => $row['line'],
				'order_text'      => sanitize_text_field( $value( 'order' ) ? $value( 'order' ) : $value( 'email' ) ),
				'order_id'        => 0,
				'order_name'      => '',
				'customer'        => '',
				'carrier'         => $known ? $known : $text,
				'tracking_number' => $number,
				'package'         => array(),
				'result'          => '',
			);

			$found = $this->find_order( $value( 'order' ), $value( 'email' ) );
			if ( is_string( $found ) ) {
				$entry['result'] = $found;
				$plan[]          = $entry;
				continue;
			}
			$entry['order_id']   = $found->get_id();
			$entry['order_name'] = $found->get_name();
			$entry['customer']   = $found->get_customer_name();

			$key = $found->get_id() . '|' . strtoupper( preg_replace( '/\s+/', '', $number ) );
			if ( '' === $number ) {
				$entry['result'] = __( 'Skipped: no tracking number', 'sunshine-photo-cart' );
			} elseif ( ! sunshine_order_can_show_tracking( $found ) ) {
				/* translators: %s: order status, like Cancelled */
				$entry['result'] = sprintf( __( 'Skipped: the order is %s', 'sunshine-photo-cart' ), $found->get_status_name() );
			} elseif ( $found->is_pickup_order() ) {
				$entry['result'] = __( 'Skipped: the order is for pickup', 'sunshine-photo-cart' );
			} elseif ( $found->has_tracking_number( $number ) ) {
				$entry['result'] = __( 'Skipped: the order already has this tracking number', 'sunshine-photo-cart' );
			} elseif ( isset( $seen[ $key ] ) ) {
				/* translators: %d: row number in the file */
				$entry['result'] = sprintf( __( 'Skipped: the same as row %d', 'sunshine-photo-cart' ), $seen[ $key ] );
			} else {
				$seen[ $key ]     = $row['line'];
				$entry['package'] = array(
					'carrier'         => $entry['carrier'],
					'tracking_number' => $number,
					'tracking_url'    => $url,
				);
				if ( $url || $known ) {
					$entry['result'] = __( 'Add', 'sunshine-photo-cart' );
				} else {
					$entry['result'] = __( 'Add, with no tracking link: the carrier isn\'t known and the file has no link', 'sunshine-photo-cart' );
				}
			}
			$plan[] = $entry;
		}
		return $plan;
	}

	/**
	 * The order a row is for: by its order number, or by the customer's email when that customer
	 * has exactly one order waiting to ship.
	 *
	 * @param string $order_text Order number as written in the file, like "#1234" or "Order 1234".
	 * @param string $email      Customer email, can be empty.
	 * @return SPC_Order|string The order, or why none was found.
	 */
	private function find_order( $order_text, $email ) {
		// A row that names an order is only ever matched by that number, never by email.
		if ( '' !== trim( $order_text ) ) {
			$order = preg_match( '/\d+/', $order_text, $match ) ? sunshine_get_order_by_number( $match[0] ) : false;
			return $order ? $order : __( 'Skipped: no order with this number', 'sunshine-photo-cart' );
		}
		$email = sanitize_email( $email );
		if ( ! $email ) {
			return __( 'Skipped: no order number or customer email', 'sunshine-photo-cart' );
		}
		$ids = sunshine_get_order_ids(
			array(
				'status'     => array( 'new', 'processing' ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
					array(
						'key'   => 'email',
						'value' => $email,
					),
				),
			),
			'all'
		);
		if ( 1 === count( $ids ) ) {
			return new SPC_Order( $ids[0] );
		}
		if ( empty( $ids ) ) {
			return __( 'Skipped: this customer has no order waiting to ship', 'sunshine-photo-cart' );
		}
		/* translators: %d: number of orders */
		return sprintf( __( 'Skipped: this customer has %d orders waiting to ship, so the file needs the order number', 'sunshine-photo-cart' ), count( $ids ) );
	}

	/**
	 * Whether a planned row will add a package.
	 *
	 * @param array $row One entry from plan().
	 * @return bool
	 */
	public function is_ready( $row ) {
		return ! empty( $row['package'] );
	}

	/**
	 * A column's name with the first row's value beside it, so a column named only "Number" can
	 * still be recognized.
	 *
	 * @param array $csv   From read().
	 * @param int   $index Column index.
	 * @return string
	 */
	private function column_label( $csv, $index ) {
		$name   = '' !== $csv['headers'][ $index ] ? $csv['headers'][ $index ] : __( '(no name)', 'sunshine-photo-cart' );
		$sample = isset( $csv['rows'][0]['values'][ $index ] ) ? $csv['rows'][0]['values'][ $index ] : '';
		if ( '' === $sample ) {
			return $name;
		}
		if ( strlen( $sample ) > 30 ) {
			$sample = substr( $sample, 0, 30 ) . '...';
		}
		/* translators: %1$s: column name, %2$s: the value in that column on the first row */
		return sprintf( __( '%1$s (like "%2$s")', 'sunshine-photo-cart' ), $name, $sample );
	}

	/**
	 * Show a problem with a link back to the start.
	 *
	 * @param string $message The problem.
	 */
	private function error( $message ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html( $message ) . '</p></div>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?import=' . self::ID ) ) . '">' . esc_html__( 'Upload a file', 'sunshine-photo-cart' ) . '</a></p>';
	}
}

new SPC_Import_Tracking();
