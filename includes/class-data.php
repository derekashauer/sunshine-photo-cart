<?php
abstract class Sunshine_Data {

	protected $id = 0;
	protected $name;
	protected $data = array();
	protected $meta = array();
	protected $post_type;
	protected $taxonomy;
	// When true, meta is read per-key on demand (through the WordPress meta cache)
	// instead of copying and unserializing every meta row into the object up front.
	protected $lazy_meta = false;
	protected $meta_fully_loaded = false;
	protected $prefetched_meta_keys = array();
	// Meta keys given a value at runtime, so stored values do not overwrite them.
	// Keys merely declared with a default in a subclass are not included.
	protected $runtime_meta_keys = array();

	public function __get( $key ) {
		if ( array_key_exists( $key, $this->meta ) ) {
			return maybe_unserialize( $this->meta[ $key ] );
		} else {
			return get_post_meta( $this->id, $key, true );
		}
		return null;
	}

	public function show_data() {
		echo '<pre>';
		var_dump( $this->data );
		echo '</pre>';
	}

	public function get_data() {
		return array_merge( array( 'id' => $this->get_id() ), (array) $this->data, array( 'meta_data' => $this->get_meta_data() ) );
	}

	public function get_data_value( $key ) {
		if ( isset( $this->data->$key ) ) {
			return $this->data->$key;
		}
		return false;
	}

	public function get_meta_data() {
		if ( empty( $this->meta ) || ( $this->lazy_meta && ! $this->meta_fully_loaded ) ) {
			$this->set_meta_data();
		}
		return $this->meta;
	}

	// Seed specific meta values (raw, as stored) fetched in bulk elsewhere, so
	// permission checks on large sets of galleries do not trigger a database
	// lookup per object. Keys registered here that have no value are remembered
	// as empty so reads of them can skip the database entirely.
	public function prefetch_meta( $meta ) {
		foreach ( (array) $meta as $key => $value ) {
			if ( ! in_array( $key, $this->prefetched_meta_keys, true ) ) {
				$this->prefetched_meta_keys[] = $key;
			}
			if ( ! empty( $value ) && ! in_array( $key, $this->runtime_meta_keys, true ) ) {
				$this->meta[ $key ]        = $value;
				$this->runtime_meta_keys[] = $key;
			}
		}
	}

	public function update_meta_data() {
		foreach ( $this->meta as $key => $value ) {
			update_post_meta( $this->id, $key, $value );
		}
	}

	public function set_meta_data() {
		if ( ! empty( $this->taxonomy ) ) {
			$meta = get_term_meta( $this->id );
		} else {
			$meta = get_post_meta( $this->id );
		}
		$this->meta_fully_loaded = true;
		if ( empty( $meta ) ) {
			return;
		}
		foreach ( $meta as $key => $value ) {
			if ( in_array( $key, $this->runtime_meta_keys, true ) ) {
				// Values set at runtime win over stored values.
				continue;
			}
			if ( ! empty( $value ) ) {
				if ( count( $value ) == 1 ) {
					$this->meta[ $key ] = maybe_unserialize( $value[0] );
				} else {
					$this->meta[ $key ] = end( $value );
					/*
					$this->meta[ $key ] = array();
					foreach ( $meta[ $key ] as $item ) {
						$this->meta[ $key ][] = maybe_unserialize( $item );
					}
					*/
				}
			}
		}
	}

	public function get_id() {
		return $this->id;
	}
	public function set_id( $id ) {
		$this->id = (int) $id;
	}

	public function set_name( $name ) {
		$this->name = $name;
	}

	public function get_name() {
		return sunshine_decode_text( $this->name );
	}

	public function get_meta_value( $key, $check_ancestors = false ) {
		if ( empty( $this->meta ) && ! $this->lazy_meta ) {
			$this->set_meta_data();
		}
		if ( array_key_exists( $key, $this->meta ) && ! empty( $this->meta[ $key ] ) ) {
			return maybe_unserialize( $this->meta[ $key ] );
		}
		if ( ! $check_ancestors && in_array( $key, $this->prefetched_meta_keys, true ) ) {
			// Key was prefetched in bulk and has no value; skip the per-object lookup.
			return false;
		}
		if ( ! empty( $this->taxonomy ) ) {
			$value = get_term_meta( $this->id, $key, true );
		} else {
			$value = get_post_meta( $this->id, $key, true );
		}
		if ( empty( $value ) && empty( $this->taxonomy ) && $check_ancestors ) {
			$ancestors = get_post_ancestors( $this->get_id() );
			if ( ! empty( $ancestors ) ) {
				foreach ( $ancestors as $ancestor_id ) {
					$value = get_post_meta( $ancestor_id, $key, true );
					if ( $value ) {
						break;
					}
				}
			}
		}
		if ( ! empty( $value ) ) {
			if ( $this->lazy_meta && ! $check_ancestors ) {
				// Cache for future reads; an ancestor's value must not be cached as our own.
				$this->meta[ $key ] = $value;
			}
			return maybe_unserialize( $value );
		}
		return false;
	}

	public function add_meta_value( $key, $value ) {

		if ( is_serialized( $value ) ) {
			return false;
		}

		$this->meta[ $key ] = $value;
		if ( ! in_array( $key, $this->runtime_meta_keys, true ) ) {
			$this->runtime_meta_keys[] = $key;
		}

		if ( $this->get_id() > 0 ) {
			if ( ! empty( $this->taxonomy ) ) {
				$value = add_term_meta( $this->get_id(), $key, $value );
			} else {
				$value = add_post_meta( $this->get_id(), $key, $value );
			}
		}

	}

	public function update_meta_value( $key, $value ) {

		if ( is_serialized( $value ) ) {
			return false;
		}

		$this->meta[ $key ] = $value;
		if ( ! in_array( $key, $this->runtime_meta_keys, true ) ) {
			$this->runtime_meta_keys[] = $key;
		}

		if ( $this->get_id() > 0 ) {
			if ( ! empty( $this->taxonomy ) ) {
				$value = update_term_meta( $this->get_id(), $key, $value );
			} else {
				$value = update_post_meta( $this->get_id(), $key, $value );
			}
		}

	}

	public function exists() {
		if ( ! empty( $this->get_id() ) ) {
			$post = get_post( $this->get_id() );
			if ( $post ) {
				return true;
			}
		}
		return false;
	}

	public function save() {

		if ( $this->get_id() > 0 ) {
			$result = $this->update( $this );
		} else {
			$result = $this->create( $this );
		}

		return $result;

	}

	public function update() {
		if ( ! empty( $this->taxonomy ) ) {
			// Term
			if ( ! empty( $this->meta ) ) {
				do_action( 'sunshine_pre_update_' . $this->taxonomy, $this );
				foreach ( $this->meta as $key => $value ) {
					update_term_meta( $this->id, $key, $value );
				}
				return true;
			}
		} else {
			do_action( 'sunshine_pre_update_' . $this->post_type, $this );
			// Post type
			$args = array(
				'ID'         => $this->get_id(),
				'meta_input' => $this->meta,
			);
			return wp_update_post( $args );
		}

	}

	public function create() {
		$args = array(
			'post_title'  => $this->name,
			'post_type'   => $this->post_type,
			'meta_input'  => $this->meta,
			'post_status' => 'publish',
		);
		do_action( 'sunshine_pre_update_' . $this->post_type, $this );
		return wp_insert_post( $args );
	}

	public function delete( $force_delete = false ) {
		if ( ! empty( $this->taxonomy ) ) {
			// Delete the term
			do_action( 'sunshine_pre_delete_' . $this->taxonomy, $this );
			wp_delete_term( $this->get_id(), $this->taxonomy );
		} else {
			// Post type
			if ( $this->get_id() ) {
				do_action( 'sunshine_pre_delete_' . $this->post_type, $this );
				wp_delete_post( $this->get_id(), $force_delete );
			}
		}
	}

}
