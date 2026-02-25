<?php
class SPC_Product_Option extends Sunshine_Data {

	protected $post_type = 'sunshine-product';
	protected $taxonomy  = 'sunshine-product-option';
	protected $name;
	protected $description;
	protected $product_id;
	protected $required = false;
	protected $price_level_id;
	protected $items = array();
	protected $meta  = array(
		'image'   => '',
		'options' => '',
	);

	public function __construct( $object, $product_id = '', $price_level_id = '' ) {

		if ( is_numeric( $object ) && $object > 0 ) {
			$object = get_term_by( 'id', $object, $this->taxonomy );
			if ( empty( $object ) || $object->taxonomy != $object->taxonomy ) {
				return false; }
		} elseif ( is_a( $object, 'WP_Term' ) ) {
			if ( $object->taxonomy != $this->taxonomy ) {
				return false; }
		} else {
			$object = get_term_by( 'slug', $object, $this->taxonomy );
			if ( empty( $object ) || $object->taxonomy != $object->taxonomy ) {
				return false; }
		}

		if ( ! empty( $object->term_id ) ) {
			$this->id             = $object->term_id;
			$this->data           = $object;
			$this->name           = $object->name;
			$this->description    = $object->description;
			$this->product_id     = $product_id;
			$this->price_level_id = $price_level_id;
			$this->set_meta_data();
		}

	}

	public function get_name() {
		return apply_filters( 'sunshine_product_option_name', $this->name, $this );
	}

	public function get_options() {
		return $this->get_meta_value( 'options' );
	}

	public function get_type() {
		return $this->get_meta_value( 'type' );
	}

	public function get_description() {
		return $this->description;
	}

	public function get_items() {

		if ( ! empty( $this->items ) ) {
			return $this->items;
		}

		if ( empty( $this->price_level_id ) ) {
			$default_price_level = sunshine_get_default_price_level();
			if ( $default_price_level ) {
				$this->price_level_id = $default_price_level->get_id();
			} else {
				return false; // We at minimum need to know a price level
			}
		}

		$type = $this->get_type();

		$product_option_prices = get_post_meta( $this->product_id, 'options', true ); // Option price info from product
		// sunshine_log( $product_option_prices, 'prices for ' . $this->product_id );
		if ( ! array_key_exists( $this->get_id(), $product_option_prices ) ) {
			return false;
		}
		$product_option_prices_for_this_option = $product_option_prices[ $this->get_id() ];

		if ( $type == 'checkbox' || $type == 'text' ) {
			$items[] = array(
				'name'  => $this->get_name(),
				'price' => $product_option_prices_for_this_option[ $this->price_level_id ],
				'image' => $this->get_meta_value( 'image' ),
			);
		} else {
			$items            = array();
			$possible_options = $this->get_options(); // Options for this one
			if ( ! empty( $possible_options ) && ! empty( $product_option_prices_for_this_option ) ) {
				foreach ( $possible_options as $possible_option ) {
					foreach ( $product_option_prices_for_this_option['items'] as $id => $product_option_price_item ) {
						if ( is_array( $product_option_price_item ) && $id == $possible_option['id'] && array_key_exists( $this->price_level_id, $product_option_price_item ) && $product_option_price_item[ $this->price_level_id ] !== '' ) {
							$items[ $id ] = array(
								'name'  => $possible_option['name'],
								'price' => $product_option_price_item[ $this->price_level_id ],
								'image' => $possible_option['image'],
							);
						}
					}
				}
			}
		}

		$this->items = $items;

		return $items;

	}

	public function get_item_name( $id ) {
		if ( $this->get_type() == 'checkbox' ) {
			return $this->get_name();
		} elseif ( $this->get_type() == 'text' ) {
			return $id;
		}
		$options = $this->get_options();
		if ( ! empty( $options ) ) {
			foreach ( $options as $option ) {
				if ( $option['id'] == $id && ! empty( $option['name'] ) ) {
					return $option['name'];
				}
			}
		}
		return false;
	}

	public function get_item_image_id( $id ) {
		$options = $this->get_options();
		if ( ! empty( $options ) ) {
			foreach ( $options as $option ) {
				if ( $option['id'] == $id && ! empty( $option['image'] ) ) {
					return $option['image'];
				}
			}
		}
	}
	public function get_item_image_url( $id, $size = 'thumbnail' ) {
		$item_image_id = $this->get_item_image_id( $id );
		if ( $item_image_id ) {
			return wp_get_attachment_image_url( $item_image_id, $size );
		}
		return false;
	}
	public function get_item_image_html( $id, $size = 'thumbnail', $echo = false ) {
		if ( ! $this->get_item_image_id( $id ) ) {
			return false;
		}
		$output = '<img src="' . esc_url( $this->get_item_image_url( $id, $size ) ) . '" alt="' . esc_attr( $this->get_item_name( $id ) ) . '" />';
		if ( $echo ) {
			echo wp_kses_post( $output );
			return;
		}
		return $output;
	}

	public function get_item_description( $id ) {
		$options = $this->get_options();
		if ( ! empty( $options ) ) {
			foreach ( $options as $option ) {
				if ( $option['id'] == $id && ! empty( $option['description'] ) ) {
					return $option['description'];
				}
			}
		}
		return false;
	}


}
