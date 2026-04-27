<?php
class SPC_Gallery extends Sunshine_Data {

	protected $post_type = 'sunshine-gallery';
	protected $name;
	private $parent_gallery_id;

	public function __construct( $object ) {

		if ( is_numeric( $object ) && $object > 0 ) {
			$object = get_post( $object );
			if ( empty( $object ) || $object->post_type != $this->post_type ) {
				return false;
			}
		} elseif ( is_object( $object ) ) {
			if ( empty( $object->post_type ) || $object->post_type != $this->post_type ) {
				return false;
			}
		}

		if ( ! empty( $object->ID ) ) {
			$this->id                = $object->ID;
			$this->data              = $object;
			$this->name              = $this->data->post_title;
			$this->parent_gallery_id = $this->data->post_parent;
			$this->set_meta_data();
		}

	}

	public function get_content() {
		return $this->data->post_content;
	}

	public function get_parent_gallery_id() {
		return $this->parent_gallery_id;
	}

	public function get_image_directory() {
		return $this->get_meta_value( 'images_directory' );
	}

	public function can_purchase() {
		if ( $this->products_disabled() || $this->is_expired() || SPC()->get_option( 'proofing' ) || ! $this->has_images() || ! $this->can_access() ) {
			return false;
		}
		return true;
	}

	// Can the user even view or see that this gallery exists.
	public function can_view( $parent = false ) {

		if ( current_user_can( 'sunshine_manage_options' ) ) {
			return true;
		}

		if ( $this->get_status() == 'private' ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}
			$allowed_users = $this->get_private_users( $parent );
			if ( empty( $allowed_users ) || ! is_array( $allowed_users ) || ! in_array( get_current_user_id(), $allowed_users ) ) {
				return false;
			}
		}

		return true;

	}

	// Just because they can view it does not mean they can access or see the images in it.
	public function can_access( $parent = false ) {

		if ( $this->get_data_value( 'post_status' ) != 'publish' ) {
			return false;
		}

		if ( ! $this->can_view( $parent ) ) {
			return false;
		}

		if ( $this->password_required( $parent ) ) {
			return false;
		}

		if ( $this->email_required() ) {
			return false;
		}

		if ( $this->is_expired() ) {
			return false;
		}

		if ( $this->get_access_type() == 'account' && ! is_user_logged_in() ) {
			return false;
		}

		return true;

	}

	public function get_private_users( $parent = false ) {
		return $this->get_meta_value( 'private_users', $parent );
	}

	public function get_price_level( $parent = false ) {
		$price_level = $this->get_meta_value( 'price_level', $parent );
		if ( empty( $price_level ) ) {
			$price_level = sunshine_get_default_price_level_id();
		}
		return $price_level;
	}

	public function products_disabled( $parent = false ) {
		return $this->get_meta_value( 'disable_products', $parent );
	}

	public function get_featured_image_id() {

		if ( $this->password_required() && SPC()->get_option( 'password_featured_image' ) ) {
			return SPC()->get_option( 'password_featured_image' );
		} elseif ( has_post_thumbnail( $this->get_id() ) ) {
			return get_post_thumbnail_id( $this->get_id() );
		} elseif ( SPC()->get_option( 'fallback_featured_image' ) ) {
			return SPC()->get_option( 'fallback_featured_image' );
		} else {
			$image_ids = $this->get_image_ids();
			if ( ! empty( $image_ids ) ) {
				return reset( $image_ids );
			}

			// Check sub galleries for featured image now.
			$child_galleries = $this->get_child_galleries();
			if ( ! empty( $child_galleries ) ) {
				foreach ( $child_galleries as $child_gallery ) {
					$child_featured_image_id = $child_gallery->get_featured_image_id();
					if ( $child_featured_image_id ) {
						return $child_featured_image_id;
					}
				}
			}
		}

		return false;

	}

	public function get_featured_image_url( $size = 'sunshine-thumbnail' ) {
		$featured_image_id = $this->get_featured_image_id();
		SPC()->log( 'Featured image ID: ' . $featured_image_id );
		if ( $featured_image_id ) {
			return wp_get_attachment_image_url( $featured_image_id, $size );
		}
		return false;
	}

	public function featured_image( $size = 'sunshine-thumbnail', $echo = 1 ) {

		$featured_image_id = $this->get_featured_image_id();
		if ( $featured_image_id ) {
			$image = sunshine_get_image( $featured_image_id );
		}

		if ( ! empty( $image ) ) {
			if ( $echo ) {
				$image->output( $size, true );
			} else {
				return $image->output( $size, false );
			}
		}

		return false;

	}

	public function featured_image_size_info( $size = 'sunshine-thumbnail' ) {
		$size_info = image_get_intermediate_size( $this->get_featured_image_id(), $size );
		if ( empty( $size_info ) ) {
			$size_info = image_get_intermediate_size( $this->get_featured_image_id(), 'full' );
		}
		if ( empty( $size_info ) ) {
			$size_info = array(
				'width'  => sunshine_get_thumbnail_dimension( 'w' ),
				'height' => sunshine_get_thumbnail_dimension( 'h' ),
			);
		}
		return $size_info;
	}

	public function get_child_galleries( $conditional_method = 'view' ) {

		if ( $this->get_id() == 0 ) {
			return false;
		}

		$child_galleries = sunshine_get_galleries(
			array(
				'post_parent' => $this->get_id(),
			),
			$conditional_method,
		);

		return $child_galleries;

	}

	/*
	public function get_image_ids() {
		global $wpdb;
		// TODO: Somehow get these in orders
		$image_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = {$this->get_id()}" );
		if ( empty( $image_ids ) ) {
			return array();
		}
		return $image_ids;
	}
	*/
	public function get_image_ids() {
		// Force fresh read from database to avoid stale cache issues when multiple uploads happen quickly
		// Clear WordPress meta cache to ensure we get the latest value
		wp_cache_delete( $this->id, 'post_meta' );
		// Clear object's cached meta to force reload
		if ( isset( $this->meta['images'] ) ) {
			unset( $this->meta['images'] );
		}
		$image_ids = $this->get_meta_value( 'images' );
		if ( empty( $image_ids ) ) {
			$image_ids = array();
		}
		$image_ids = apply_filters( 'sunshine_gallery_image_ids', $image_ids, $this );
		if ( ! empty( $image_ids ) ) {
			$image_ids = array_unique( $image_ids );
			$image_ids = array_values( $image_ids );
		}
		return $image_ids;
	}

	public function add_image_id( $image_id ) {
		$image_ids   = $this->get_image_ids();
		$image_ids[] = $image_id;
		$this->set_image_ids( $image_ids );
		return $image_ids;
	}

	public function set_image_ids( $image_ids ) {
		if ( is_array( $image_ids ) ) {
			$image_ids = array_unique( $image_ids );
		}
		$this->update_meta_value( 'images', $image_ids );
		// Clear WordPress meta cache to ensure fresh reads for new gallery instances
		wp_cache_delete( $this->id, 'post_meta' );
	}

	public function get_image_count() {
		$image_ids = $this->get_image_ids();
		if ( ! empty( $image_ids ) && is_array( $image_ids ) ) {
			return count( $image_ids );
		}
		return 0;
	}

	public function has_images() {
		return ( $this->get_image_count() > 0 ) ? true : false;
	}

	public function get_images( $custom_args = array(), $get_ids = false ) {

		$image_ids = $this->get_image_ids();
		if ( empty( $image_ids ) ) {
			return false;
		}

		$posts_per_page = sunshine_gallery_images_per_page();

		$args = array(
			'post_type'      => 'attachment',
			'posts_per_page' => sunshine_gallery_images_per_page(),
			// 'post_mime_type' => 'image',
			'post__in'       => $image_ids,
			'no_found_rows'  => true,
		// 'post_parent' => $this->get_id()
		);

		$order = SPC()->get_option( 'image_order' );

		if ( $order == 'shoot_order' ) {
			$args['meta_key'] = 'created_timestamp';
			$args['orderby']  = 'meta_value_num menu_order';
			$args['order']    = 'ASC';
		} elseif ( $order == 'shoot_order_reverse' ) {
			$args['meta_key'] = 'created_timestamp';
			$args['orderby']  = 'meta_value_num menu_order';
			$args['order']    = 'DESC';
		} elseif ( $order == 'date_new_old' ) {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		} elseif ( $order == 'date_old_new' ) {
			$args['orderby'] = 'date';
			$args['order']   = 'ASC';
		} elseif ( $order == 'title' ) {
			$args['orderby'] = 'title menu_order';
			$args['order']   = 'ASC';
		} else {
			$args['orderby'] = 'post__in';
			// $args['order'] = 'ASC';
		}

		$args = wp_parse_args( $custom_args, $args );

		if ( isset( $_GET['pagination'] ) ) {
			$args['offset'] = $args['posts_per_page'] * ( intval( $_GET['pagination'] ) - 1 );
		}

		$args = apply_filters( 'sunshine_gallery_get_images_args', $args, $this->get_id() );

		$images = get_posts( $args );
		if ( ! empty( $images ) ) {
			$final_images = array();
			foreach ( $images as $image ) {
				if ( $get_ids ) {
					$final_images[] = $image->ID;
				} else {
					$final_images[] = sunshine_get_image( $image );
				}
			}
			return $final_images;
		}
		return false;
	}

	public function delete_image( $image_id ) {
		$image_ids = $this->get_image_ids();
		if ( ! in_array( $image_id, $image_ids ) ) {
			return false;
		}
		if ( wp_delete_attachment( $image_id, true ) ) {
			// TODO: What is the function for this to get the array key from value?!
			foreach ( $image_ids as $key => $item_image_id ) {
				if ( $image_id == $item_image_id ) {
					unset( $image_ids[ $key ] );
					break;
				}
			}
			$this->set_image_ids( $image_ids );
			return true;
		}
		return false;
	}

	public function classes( $echo = true ) {
		$classes = array();
		if ( $this->password_required() ) {
			$classes[] = 'sunshine--password-required';
		}
		$classes = apply_filters( 'sunshine_gallery_classes', $classes, $this );
		if ( $echo ) {
			echo esc_html( join( ' ', $classes ) );
			return;
		}
		return $classes;
	}

	public function get_permalink() {
		return get_permalink( $this->get_id() );
	}

	public function get_ancestors() {
		$ancestors = get_ancestors( $this->get_id(), $this->post_type );
		if ( empty( $ancestors ) ) {
			return array();
		}
		return $ancestors;
	}

	public function get_ancestors_formatted( $link = true, $context = 'public' ) {
		if ( $this->get_parent_gallery_id() > 0 ) {
			$ancestors      = $this->get_ancestors();
			$url            = ( $context === 'admin' ) ? admin_url( 'post.php?action=edit&post=' . $this->get_id() ) : $this->get_permalink( $this->get_id() );
			$ancestor_links = array( $link ? '<a href="' . $url . '">' . esc_html( $this->get_name() ) . '</a>' : esc_html( $this->get_name() ) );
			foreach ( $ancestors as $ancestor_id ) {
				$ancestor_url     = ( $context === 'admin' ) ? admin_url( 'post.php?action=edit&post=' . $ancestor_id ) : $this->get_permalink( $ancestor_id );
				$ancestor_links[] = $link ? '<a href="' . $ancestor_url . '">' . esc_html( get_the_title( $ancestor_id ) ) . '</a>' : esc_html( get_the_title( $ancestor_id ) );
			}
			$ancestor_links = array_reverse( $ancestor_links );
			return join( ' > ', $ancestor_links );
		}
		return false;
	}

	public function get_parent_gallery() {
		if ( $this->data->post_parent ) {
			return sunshine_get_gallery( $this->data->post_parent );
		}
		return false;
	}

	public function get_children_galleries() {
		return $this->get_child_galleries();
	}

	public function has_children_galleries() {
		return ( $this->get_children_galleries() ) ? true : false;
	}

	public function get_expiration_date() {
		$exp_date = $this->get_meta_value( 'end_date' );
		if ( is_numeric( $exp_date ) ) {
			return $exp_date;
		}
		return false;
	}

	public function get_expiration_date_formatted( $format = '' ) {
		if ( empty( $format ) ) {
			$format = get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' );
		}
		$end_date = $this->get_expiration_date();
		if ( $end_date ) {
			return date_i18n( $format, $end_date );
		}
		return false;
	}

	public function is_expired() {

		if ( current_user_can( 'sunshine_manage_options' ) ) {
			// Never expired for admin users
			return false;
		}

		$end_date = $this->get_expiration_date();
		if ( $end_date && $end_date < current_time( 'timestamp' ) ) {
			return true;
		}
		return false;

	}

	public function allow_comments() {
		return $this->image_comments;
	}

	public function comments_require_approval() {
		return $this->image_comments_approval;
	}

	public function password_required( $parent = false ) {
		if ( current_user_can( 'sunshine_manage_options' ) ) {
			return false;
		}
		if ( $this->get_status() == 'password' ) {
			// Is password in our session data?
			$passwords = SPC()->session->get( 'gallery_passwords' );
			if ( empty( $passwords ) || ! is_array( $passwords ) || ! in_array( $this->get_id(), $passwords ) ) {
				return true;
			}
		}
			return false;
	}

	public function get_status( $parent = false ) {
		return $this->get_meta_value( 'status' );
	}

	public function get_access_type( $parent = false ) {
		return $this->get_meta_value( 'access_type', $parent );
	}

	public function get_password() {
		return $this->get_meta_value( 'password' );
	}

	public function get_password_hint() {
		return $this->get_meta_value( 'password_hint' );
	}

	public function email_required() {
		if ( current_user_can( 'sunshine_manage_options' ) ) {
			return false;
		}
		if ( $this->get_access_type() == 'email' ) {
			$gallery_emails = SPC()->session->get( 'gallery_emails' );
			if ( ! is_array( $gallery_emails ) ) {
				return true;
			}
			if ( in_array( $this->get_id(), $gallery_emails ) ) {
				return false;
			}
			return true;
		}
		return false;
	}

	public function get_emails() {
		return maybe_unserialize( $this->get_meta_value( 'emails' ) );
	}

	public function add_email( $email ) {
		if ( ! is_email( $email ) ) {
			return;
		}
		$emails = $this->get_emails();
		if ( empty( $emails ) || ! is_array( $emails ) ) {
			$emails = array();
		}
		$emails[] = $email;
		$this->update_meta_value( 'emails', $emails );
	}

	public function allow_favorites() {
		return ! $this->disable_favorites;
	}

	public function allow_gallery_sharing() {
		return $this->allow_gallery_sharing;
	}

	public function allow_image_sharing() {
		return $this->allow_image_sharing;
	}

	public function get_store_url() {
		return trailingslashit( $this->get_permalink() ) . SPC()->get_option( 'endpoint_store', 'store' );
	}

}
