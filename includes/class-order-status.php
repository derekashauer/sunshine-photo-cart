<?php
class SPC_Order_Status extends Sunshine_Data {

	protected $post_type = 'sunshine-order';
	protected $taxonomy  = 'sunshine-order-status';
	protected $name;
	protected $description;
	protected $count;

	public function __construct( $object, $price_level = '' ) {

		if ( is_numeric( $object ) ) {
			$this->id = $object;
			if ( $object > 0 ) {
				$term = get_term_by( 'id', $object, $this->taxonomy );
				if ( empty( $term ) || $term->taxonomy != $this->taxonomy ) {
					return false; }
				$this->id   = $term->term_id;
				$this->data = $term;
			}
		} elseif ( is_a( $object, 'WP_Term' ) ) {
			if ( $object->taxonomy != $this->taxonomy ) {
				return false; }
			$this->data = $object;
			$this->id   = $object->term_id;
		} elseif ( ! empty( $object ) ) {
			$term = get_term_by( 'slug', $object, $this->taxonomy );
			if ( empty( $term ) || $term->taxonomy != $this->taxonomy ) {
				return false; }
			$this->id   = $term->term_id;
			$this->data = $term;
		}

		if ( ! empty( $this->data ) ) {
			$this->name        = $this->data->name;
			$this->description = $this->data->description;
			$this->count       = $this->data->count;
		}

		if ( $this->id > 0 ) {
			$this->set_meta_data();
		}

	}

	public function get_name() {
		return apply_filters( 'sunshine_order_status_name', $this->name, $this );
	}

	public function get_description() {
		return apply_filters( 'sunshine_order_status_description', $this->description, $this );
	}

	public function get_key() {
		return $this->data->slug;
	}

	public function get_count() {
		return $this->count;
	}

}
