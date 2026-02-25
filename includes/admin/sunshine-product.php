<?php
class Sunshine_Admin_Meta_Boxes_Product extends Sunshine_Admin_Meta_Boxes {

	protected $post_type = 'sunshine-product';

	public function set_meta_boxes( $meta_boxes ) {
		$meta_boxes[ $this->post_type ] = array(
			array(
				'id'       => 'sunshine-product-options', // Unique box id
				'name'     => __( 'Product Info', 'sunshine-photo-cart' ), // Label/name
				'context'  => 'advanced', // normal/side/advanced
				'priority' => 'high', // priority
			),
		);
		return $meta_boxes;
	}

	public function set_options( $options ) {
		/*
		$price_levels = sunshine_get_price_levels();
		$price_level_fields = array();
		if ( !empty( $price_levels ) ) {
			foreach ( $price_levels as $price_level ) {
			}
		}
		*/
		$product_types              = sunshine_get_product_types( 'name' );
		$price_level_fields['1']    = array(
			'id'         => 'type',
			'name'       => __( 'Type', 'sunshine-photo-cart' ),
			'type'       => 'select',
			'default'    => 'print',
			'options'    => $product_types,
			'conditions' => array(),
		);
		$price_level_fields['1000'] = array(
			'id'      => 'price',
			'name'    => __( 'Price', 'sunshine-photo-cart' ),
			'type'    => 'price',
			'default' => '',
			'upgrade' => array(
				'addon' => 'price-levels',
				'label' => __( 'Manage more price levels to offer different pricing in across galleries for the same product', 'sunshine-photo-cart' ),
				'url'   => 'https://www.sunshinephotocart.com/addon/price-levels/',
			),
		);
		$price_level_fields['1100'] = array(
			'id'   => 'taxable',
			'name' => __( 'Taxable', 'sunshine-photo-cart' ),
			'type' => 'checkbox',
		);
		$price_level_fields['1150'] = array(
			'id'          => 'disable_shipping',
			'name'        => __( 'Disable Shipping', 'sunshine-photo-cart' ),
			'type'        => 'checkbox',
			'description' => __( 'Do not require shipping for this item at checkout', 'sunshine-photo-cart' ),
		);
		$price_level_fields['1200'] = array(
			'id'          => 'shipping',
			'name'        => __( 'Extra Shipping Cost', 'sunshine-photo-cart' ),
			'type'        => 'price',
			'description' => __( 'Additional shipping cost, intended for larger items such as canvases', 'sunshine-photo-cart' ),
			'conditions'  => array(
				array(
					'field'   => 'disable_shipping',
					'value'   => 1,
					'compare' => '==',
					'action'  => 'hide',
				),
			),
		);
		$price_level_fields['1300'] = array(
			'id'          => 'min_qty',
			'name'        => __( 'Minimum Quantity', 'sunshine-photo-cart' ),
			'type'        => 'number',
			'description' => __( 'Do not allow customer to add less than this amount to cart for each image', 'sunshine-photo-cart' ),
		);
		$price_level_fields['1400'] = array(
			'id'          => 'max_qty',
			'name'        => __( 'Max Quantity', 'sunshine-photo-cart' ),
			'type'        => 'number',
			'description' => __( 'Do not allow customer to add more than this amount to cart for each image', 'sunshine-photo-cart' ),
		);

		$options['sunshine-product-options'] = array(
			'1' => array(
				'id'     => 'general',
				'name'   => __( 'General', 'sunshine-photo-cart' ),
				'fields' => $price_level_fields,
			),
		);

		return $options;
	}

}

$sunshine_admin_meta_boxes_product = new Sunshine_Admin_Meta_Boxes_Product();

add_filter( 'manage_edit-sunshine-product_columns', 'sunshine_products_columns', 10 );
function sunshine_products_columns( $columns ) {
	unset( $columns['date'] );
	$columns['price']    = __( 'Price', 'sunshine-photo-cart' );
	$columns['category'] = __( 'Category', 'sunshine-photo-cart' );
	return $columns;
}

add_action( 'manage_sunshine-product_posts_custom_column', 'sunshine_products_columns_content', 99, 2 );
function sunshine_products_columns_content( $column, $post_id ) {
	global $post;
	$product = sunshine_get_product( $post_id );
	switch ( $column ) {
		case 'price':
			echo wp_kses_post( apply_filters( 'sunshine_admin_product_price_column', $product->get_price_formatted(), $product ) );
			break;
		case 'category':
			echo '<a href="' . esc_url( admin_url( 'term.php?taxonomy=sunshine-product-category&tag_ID=' . esc_attr( $product->get_category_id() ) . '&post_type=sunshine-product' ) ) . '">' . esc_html( $product->get_category_name() ) . '</a>';
			break;
		default:
			break;
	}
}

add_filter( 'months_dropdown_results', 'sunshine_product_date_dropdown', 10, 2 );
function sunshine_product_date_dropdown( $months, $post_type ) {
	if ( 'sunshine-product' === $post_type ) {
		return array();
	}
	return $months;
}

add_action( 'restrict_manage_posts', 'sunshine_product_filter_by_category' );
function sunshine_product_filter_by_category() {
	if ( isset( $_GET['post_type'] ) && post_type_exists( $_GET['post_type'] ) && in_array( strtolower( $_GET['post_type'] ), array( 'sunshine-product' ) ) ) {
		$categories = sunshine_get_product_categories();
		if ( ! empty( $categories ) ) {
			?>
		<select name="category">
			<option value=""><?php esc_html_e( 'All categories', 'sunshine-photo-cart' ); ?></option>
			<?php foreach ( $categories as $category ) { ?>
				<option value="<?php echo esc_attr( $category->get_id() ); ?>" 
										  <?php
											if ( ! empty( $_GET['category'] ) ) {
												selected( $_GET['category'], $category->get_id() ); }
											?>
				><?php echo esc_html( $category->get_name() ); ?></option>
			<?php } ?>
		</select>
			<?php
		}

		$types = sunshine_get_product_types();
		if ( ! empty( $types ) ) {
			?>
		<select name="type">
			<option value=""><?php esc_html_e( 'All types', 'sunshine-photo-cart' ); ?></option>
			<?php foreach ( $types as $key => $type ) { ?>
				<option value="<?php echo esc_attr( $key ); ?>" 
										  <?php
											if ( ! empty( $_GET['type'] ) ) {
												selected( $_GET['type'], $key ); }
											?>
				><?php echo esc_html( $type['name'] ); ?></option>
			<?php } ?>
		</select>
			<?php
		}
	}
}

add_filter( 'parse_query', 'sunshine_product_parse_query_category' );
function sunshine_product_parse_query_category( $query ) {
	global $pagenow;
	if ( ! empty( $_GET['category'] ) && $pagenow == 'edit.php' && isset( $query->query_vars['post_type'] ) && $query->query_vars['post_type'] == 'sunshine-product' ) {
		$query->query_vars['tax_query'] = array(
			array(
				'taxonomy' => 'sunshine-product-category',
				'terms'    => intval( $_GET['category'] ),
			),
		);
	}
	if ( ! empty( $_GET['type'] ) && $pagenow == 'edit.php' && isset( $query->query_vars['post_type'] ) && $query->query_vars['post_type'] == 'sunshine-product' ) {
		$query->query_vars['meta_query'] = array(
			array(
				'key'   => 'type',
				'value' => sanitize_text_field( $_GET['type'] ),
			),
		);
	}

}

add_action( 'save_post_sunshine-product', 'sunshine_product_default_category' );
function sunshine_product_default_category( $post_id ) {
	if ( empty( $_POST ) ) {
		return;
	}
	$categories = wp_get_object_terms( $post_id, 'sunshine-product-category' );
	if ( empty( $categories ) ) {
		$default_category = sunshine_get_default_product_category();
		if ( ! empty( $default_category ) ) {
			$default_cat = apply_filters( 'sunshine_set_default_product_category', $default_category->get_id(), $post_id );
			if ( $default_cat ) {
				wp_set_object_terms( $post_id, $default_cat, 'sunshine-product-category' );
			}
		}
	}
}

add_action( 'wp', 'sunshine_admin_product_check' );
function sunshine_admin_product_check() {
	global $pagenow;

	if ( empty( $_GET['s'] ) && $pagenow == 'edit.php' && isset( $_GET['post_type'] ) && $_GET['post_type'] == 'sunshine-product' ) {
		$query = new WP_Query(
			array(
				'post_type'   => 'sunshine-product',
				'post_status' => array( 'any', 'trash' ),
			)
		);
		if ( $query->found_posts == 0 ) {
			echo '<style>.wrap { display: none; }</style>';
			add_thickbox();
			add_action( 'admin_notices', 'sunshine_admin_no_products' );
		}
	}
}

add_filter( 'enter_title_here', 'sunshine_product_enter_title_here', 10, 2 );
function sunshine_product_enter_title_here( $title, $post ) {
	if ( 'sunshine-product' === $post->post_type ) {
		return __( 'Add product name', 'sunshine-photo-cart' );
	}
	return $title;
}

function sunshine_admin_no_products() {
	sunshine_get_template( 'admin/no-products' );
}

add_filter( 'post_row_actions', 'sunshine_row_actions', 10, 2 );
add_filter( 'page_row_actions', 'sunshine_row_actions', 10, 2 );
function sunshine_row_actions( $actions, $post ) {
	if ( 'sunshine-product' === $post->post_type || 'sunshine-gallery' === $post->post_type ) {
		return array_merge( array( 'id' => '<span class="sunshine--id">#' . $post->ID . '</span>' ), $actions );
	}
	return $actions;
}
