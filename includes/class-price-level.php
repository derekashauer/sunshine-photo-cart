<?php
class SPC_Price_Level extends Sunshine_Data {

	protected $post_type = 'sunshine-product';
	protected $taxonomy  = 'sunshine-product-price-level';
	protected $name;

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
			$this->set_meta_data();
		}

	}

	public function get_name() {
		return apply_filters( 'sunshine_price_level_name', $this->name, $this );
	}

}
