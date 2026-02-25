<?php
class SPC_Product_Category extends Sunshine_Data {

	protected $post_type = 'sunshine-product';
	protected $taxonomy  = 'sunshine-product-category';
	protected $name;
	protected $key;

	public function __construct( $object ) {

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
			$this->id   = $object->term_id;
			$this->data = $object;
			$this->name = $object->name;
			$this->key  = $object->slug;
			$this->set_meta_data();
		}

	}

	public function get_name() {
		return apply_filters( 'sunshine_category_name', $this->name, $this );
	}

	public function get_description() {
		return $this->data->description;
	}

	public function get_key() {
		return $this->key;
	}

	public function has_image() {
		return ( $this->get_image_id() > 0 ) ? true : false;
	}
	public function get_image_id() {
		return $this->get_meta_value( 'image' );
	}
	public function get_image_url( $size = 'medium' ) {
		$image_id = $this->get_image_id();
		if ( $image_id ) {
			$image = wp_get_attachment_image_src( $image_id, $size );
			if ( $image ) {
				return $image[0];
			}
		}
		return false;
	}
	public function get_image_html( $size = 'medium', $echo = true ) {
		if ( ! $this->has_image() ) {
			return false;
		}
		$output = '<img src="' . esc_url( $this->get_image_url( $size ) ) . '" alt="' . esc_attr( $this->get_name() ) . '" />';
		if ( $echo ) {
			echo wp_kses_post( $output );
			return;
		}
		return $output;
	}


}
