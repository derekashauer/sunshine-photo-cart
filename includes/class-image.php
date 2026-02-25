<?php

class SPC_Image extends Sunshine_Data {

	protected $post_type = 'attachment';
	protected $name;
	public $gallery;

	public function __construct( $object ) {

		if ( is_numeric( $object ) ) {
			if ( $object > 0 ) {
				$post = get_post( $object );
				if ( empty( $post ) || $post->post_type != $this->post_type ) {
					return false;
				}
				$this->id   = $post->ID;
				$this->data = $post;
				if ( $post->post_title ) {
					$this->name = $post->post_title;
				}
			}
		} elseif ( is_a( $object, 'WP_Post' ) || is_a( $object, 'SPC_Image' ) ) {
			if ( $object->post_type != $this->post_type ) {
				return false;
			}
			$this->data = $object;
			$this->id   = $object->ID;
			if ( $object->post_title ) {
				$this->name = $object->post_title;
			}
		}

		if ( ! empty( $this->data->post_parent ) ) {
			$this->gallery = sunshine_get_gallery( $this->data->post_parent );
		}

		if ( $this->id > 0 ) {
			$this->set_meta_data();
		}

	}

	public function get_name( $show = '' ) {
		if ( ! $show ) {
			$show = SPC()->get_option( 'show_image_data' );
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			$show = 'filename';
		}
		if ( empty( $show ) || $show == '' ) {
			$name = '';
		} elseif ( $show == 'filename' ) {
			$name = $this->get_file_name();
		} elseif ( $show == 'title' ) {
			$name = $this->name;
		} elseif ( $show == 'caption' ) {
			$name = $this->get_caption();
		}
		return apply_filters( 'sunshine_image_name', $name, $this );
	}

	/**
	 * Get the image caption (post_excerpt from EXIF metadata).
	 *
	 * @return string The image caption or empty string.
	 */
	public function get_caption() {
		return $this->get_data_value( 'post_excerpt' ) ?: '';
	}

	public function get_file_name() {
		$file_name = $this->get_meta_value( 'sunshine_file_name' );
		if ( ! empty( $file_name ) ) {
			$info      = pathinfo( $file_name );
			$file_name = $info['filename'];
		} else {
			$file_path = get_attached_file( $this->get_id() );
			$info      = pathinfo( $file_path );
			$file_name = $info['filename'];
		}
		return $file_name;
	}

	public function get_filename() {
		return $this->get_file_name();
	}

	public function get_gallery() {
		if ( ! empty( $this->gallery ) ) {
			return $this->gallery;
		}
		if ( ! empty( $this->data->post_parent ) ) {
			$this->gallery = sunshine_get_gallery( $this->data->post_parent );
			return $this->gallery;
		}
		return false;
	}

	public function get_gallery_id() {
		if ( ! empty( $this->gallery ) ) {
			return $this->gallery->get_id();
		}
		$this->get_gallery();
		if ( ! empty( $this->gallery ) ) {
			return $this->gallery->get_id();
		}
		return false;
	}

	public function get_gallery_name() {
		if ( ! empty( $this->gallery ) ) {
			return $this->gallery->get_name();
		}
		$this->get_gallery();
		if ( ! empty( $this->gallery ) ) {
			return $this->gallery->get_name();
		}
		return false;
	}

	public function get_image_url( $size = 'sunshine-thumbnail' ) {
		return wp_get_attachment_image_url( $this->get_id(), $size );
	}

	public function get_permalink() {
		if ( empty( $this->gallery ) ) {
			$this->get_gallery();
		}
		if ( $this->gallery ) {
			// return trailingslashit( trailingslashit( $this->gallery->get_permalink() ) . $this->data->post_name );
		}
		return get_permalink( $this->get_id() );
	}

	public function output( $size = 'sunshine-thumbnail', $echo = true ) {
		if ( empty( $size ) ) {
			$size = 'sunshine-thumbnail';
		}
		$output = '<img src="' . esc_url( $this->get_image_url( $size ) ) . '" loading="lazy" class="sunshine--image--' . esc_attr( $this->get_id() ) . '" alt="' . esc_attr( $this->get_name() ) . '" />';
		/*
		$output = wp_get_attachment_image(
			$this->get_id(),
			$size,
			'',
			array(
				'class' => 'sunshine--image--' . esc_attr( $this->get_id() ),
				'alt' => $this->get_name(),
			),
		);
		*/
		$output = apply_filters( 'sunshine_image_output', $output, $this, $size );
		if ( $echo ) {
			echo wp_kses_post( $output );
			return;
		}
		return $output;
	}

	public function get_comments() {
		$comments = get_comments( 'post_id=' . $this->get_id() . '&status=approve&order=ASC' );
		return $comments;
	}

	public function get_comment_count() {
		return count( $this->get_comments() );
	}

	public function can_view() {
		if ( empty( $this->gallery ) ) {
			$this->get_gallery();
		}
		$can_view = true;
		if ( $this->gallery ) {
			$can_view = $this->gallery->can_view();
		}
		if ( ! $can_view && SPC()->session->get( 'favorite_key' ) ) {
			$favorite_image_ids = sunshine_get_favorites_by_key( SPC()->session->get( 'favorite_key' ) );
			if ( ! empty( $favorite_image_ids ) && in_array( $this->get_id(), $favorite_image_ids ) ) {
				return true;
			}
		}
		return $can_view;
	}

	public function can_access() {
		if ( empty( $this->gallery ) ) {
			$this->get_gallery();
		}
		if ( $this->gallery ) {
			return $this->gallery->can_access();
		}
		return false;
	}

	public function can_purchase() {
		$can_purchase = true;
		if ( empty( $this->gallery ) ) {
			$this->get_gallery();
		}
		if ( $this->gallery ) {
			$can_purchase = $this->gallery->can_purchase();
			if ( $can_purchase ) {
				$can_purchase = ! ( ! empty( $this->meta['sunshine_disable_purchase'] ) );
			}
		}
		$can_purchase = apply_filters( 'sunshine_image_can_purchase', $can_purchase, $this );
		return $can_purchase;
	}

	public function is_favorite() {
		if ( is_user_logged_in() ) {
			$favorites = SPC()->customer->get_favorite_ids();
			if ( ! empty( $favorites ) && in_array( $this->get_id(), $favorites ) ) {
				return true;
			}
		}
		return false;
	}

	public function in_cart() {
		if ( ! SPC()->cart->is_empty() ) {
			foreach ( SPC()->cart->get_cart() as $item ) {
				if ( isset( $item['object_id'] ) && $item['object_id'] == $this->get_id() ) {
					return true;
				}
			}
		}
		return false;
	}

	public function products_disabled() {
		$gallery = $this->get_gallery();
		if ( $gallery && $gallery->products_disabled() ) {
			return true;
		}
		return false;
	}

	public function allow_comments() {
		$gallery = $this->get_gallery();
		if ( $gallery && $gallery->allow_comments() ) {
			return true;
		}
		return false;
	}

	public function comments_require_approval() {
		$gallery = $this->get_gallery();
		if ( $gallery && $gallery->comments_require_approval() ) {
			return true;
		}
		return false;
	}

	public function allow_image_sharing() {
		$gallery = $this->get_gallery();
		if ( $gallery && $gallery->allow_image_sharing() ) {
			return true;
		}
		return false;
	}

	public function allow_favorites() {
		$gallery = $this->get_gallery();
		if ( $gallery && $gallery->allow_favorites() ) {
			return true;
		}
		return false;
	}

	public function get_price_level() {
		if ( ! empty( $this->meta['sunshine_price_level'] ) ) {
			return $this->meta['sunshine_price_level'];
		}
		$gallery = $this->get_gallery();
		if ( $gallery ) {
			return $gallery->get_price_level();
		}
		return false;
	}

	public function get_size_info( $size = 'sunshine-large' ) {
		$size_info = image_get_intermediate_size( $this->get_id(), $size );
		if ( empty( $size_info ) ) {
			$size_info = image_get_intermediate_size( $this->get_id(), 'full' );
		}
		if ( empty( $size_info ) ) {
			$size_info = array(
				'width'  => sunshine_get_thumbnail_dimension( 'w' ),
				'height' => sunshine_get_thumbnail_dimension( 'h' ),
			);
		}
		return $size_info;
	}

	public function get_image_created_timestamp() {
		if ( ! empty( $this->meta['created_timestamp'] ) ) {
			return $this->meta['created_timestamp'];
		}
		return false;
	}

}
