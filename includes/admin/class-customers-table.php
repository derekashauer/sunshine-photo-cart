<?php
/**
 * Customers Table Class
 *
 * @package  SunshinePhotoCart/classes
 * @since      3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * SPC_Customers_Table Class
 *
 * Renders the Customer table
 */
class SPC_Customers_Table extends WP_List_Table {

	/**
	 * @var array Array of SPC_Customers
	 */
	private $customers = array();

	/**
	 * Get things started
	 *
	 * @since 1.5
	 * @see WP_List_Table::__construct()
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'customer',
				'plural'   => 'customers',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		$table_columns = array(
			'cb'           => '<input type="checkbox" />', // to display the checkbox.
			'name'         => __( 'Name', 'sunshine-photo-cart' ),
			'email'        => __( 'Email', 'sunshine-photo-cart' ),
			'favorites'    => __( 'Favorites', 'sunshine-photo-cart' ),
			'orders'       => __( 'Orders', 'sunshine-photo-cart' ),
			'order_totals' => __( 'Order Totals', 'sunshine-photo-cart' ),
			'login'        => __( 'Latest Login', 'sunshine-photo-cart' ),
		);
		$table_columns = apply_filters( 'sunshine_customers_table_columns', $table_columns );
		return $table_columns;
	}

	public function no_items() {
		esc_html_e( 'No customers found', 'sunshine-photo-cart' );
	}

	function prepare_items() {

		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		$args = array();
		if ( isset( $_GET['s'] ) ) {
			$search                 = sanitize_text_field( $_GET['s'] );
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'ID', 'user_login', 'user_email', 'user_url', 'user_nicename', 'display_name' );

		}
		if ( isset( $_GET['orderby'] ) ) {
			switch ( $_GET['orderby'] ) {
				case 'name':
					$args['orderby']  = 'meta_value';
					$args['meta_key'] = 'first_name';
					break;
				case 'favorites':
					$args['orderby']  = 'meta_value_num';
					$args['meta_key'] = 'sunshine_favorites_count';
					break;
				case 'orders':
					$args['orderby']  = 'meta_value_num';
					$args['meta_key'] = 'sunshine_order_count';
					break;
				case 'order_totals':
					$args['orderby']  = 'meta_value_num';
					$args['meta_key'] = 'sunshine_order_totals';
					break;
				case 'login':
					$args['orderby']  = 'meta_value_num';
					$args['meta_key'] = 'sunshine_last_login';
					break;
			}
			$args['order'] = sanitize_text_field( $_GET['order'] );
		}
		$args['role__in'] = array( sunshine_get_customer_role(), 'administrator' );
		$this->items      = sunshine_get_customers( $args );

		// Separate search on just meta data.
		if ( isset( $_GET['s'] ) ) {
			$meta_search_customers = sunshine_get_customers(
				array(
					'role__in'   => array( sunshine_get_customer_role(), 'administrator' ),
					'meta_query' => array(
						'relation' => 'OR',
						array(
							'key'     => 'first_name',
							'value'   => $search,
							'compare' => 'LIKE',
						),
						array(
							'key'     => 'last_name',
							'value'   => $search,
							'compare' => 'LIKE',
						),
					),
				)
			);
			if ( ! empty( $meta_search_customers ) ) {
				$this->items = array_replace( $this->items, $meta_search_customers );
			}
		}

		/* pagination */
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$total_items  = 0;
		if ( ! empty( $this->items ) ) {
			$total_items = count( $this->items );
			$this->items = array_slice( $this->items, ( ( $current_page - 1 ) * $per_page ), $per_page );
			$this->set_pagination_args(
				array(
					'total_items' => $total_items, // total number of items
					'per_page'    => $per_page, // items to show on a page
					'total_pages' => ceil( $total_items / $per_page ), // use ceil to round up
				)
			);
		}
	}

	function column_cb( $customer ) {
		return sprintf(
			'<input type="checkbox" name="element[]" value="%s" />',
			$customer->get_id()
		);
	}

	function column_default( $customer, $column_name ) {
		switch ( $column_name ) {
			case 'name':
				  echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-customers&customer=' . $customer->get_id() ) ) . '">' . esc_html( $customer->get_name( '—' ) ) . '</a>';
				break;
			case 'email':
				echo '<a href="mailto:' . esc_html( $customer->get_email() ) . '">' . esc_html( $customer->get_email() ) . '</a>';
				break;
			case 'favorites':
				$favorite_count = $customer->get_favorite_count();
				if ( $favorite_count > 0 ) {
					echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-customers&tab=favorites&customer=' . $customer->get_id() ) ) . '#favorites">' . esc_html( $favorite_count ) . '</a>';
				} else {
					echo 0;
				}
				break;
			case 'orders':
				$order_count = $customer->get_order_count();
				if ( $order_count > 0 ) {
					echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-customers&tab=orders&customer=' . $customer->get_id() ) ) . '#orders">' . esc_html( $order_count ) . '</a>';
				} else {
					echo 0;
				}
				break;
			case 'order_totals':
				$order_totals = $customer->get_order_totals();
				if ( $order_totals > 0 ) {
					echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-customers&customer=' . $customer->get_id() ) ) . '#orders">' . wp_kses_post( sunshine_price( $order_totals ) ) . '</a>';
				} else {
					echo 0;
				}
				break;
			case 'login':
				$last_login = $customer->get_last_login();
				if ( $last_login ) {
					$date_time_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
					echo esc_html( gmdate( $date_time_format, $last_login ) );
				} else {
					echo '-';
				}
				break;
			case 'activity':
				do_action( 'sunshine_customers_table_column_activity', $customer );
				break;
			default:
				do_action( 'sunshine_customers_table_column_' . $column_name, $customer );
				break;
		}
	}

	protected function get_sortable_columns() {
		$sortable_columns = array(
			'name'         => array( 'name', true ),
			'favorites'    => array( 'favorites', true ),
			'orders'       => array( 'orders', true ),
			'order_totals' => array( 'order_totals', true ),
			'login'        => array( 'login', true ),
		);
		$sortable_columns = apply_filters( 'sunshine_customers_table_sortable_columns', $sortable_columns );
		return $sortable_columns;
	}

}
