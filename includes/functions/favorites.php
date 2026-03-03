<?php

/**
 * Session-based guest favorites helpers.
 */
function sunshine_get_session_favorite_ids() {
	$favorites = SPC()->session->get( 'favorites' );
	if ( ! empty( $favorites ) && is_array( $favorites ) ) {
		return array_map( 'intval', $favorites );
	}
	return array();
}

function sunshine_add_session_favorite( $image_id ) {
	$favorites = sunshine_get_session_favorite_ids();
	$image_id  = intval( $image_id );
	if ( ! in_array( $image_id, $favorites, true ) ) {
		$favorites[] = $image_id;
		SPC()->session->set( 'favorites', $favorites );
		do_action( 'sunshine_add_favorite', $image_id );
	}
	return count( $favorites );
}

function sunshine_delete_session_favorite( $image_id ) {
	$favorites = sunshine_get_session_favorite_ids();
	$key       = array_search( intval( $image_id ), $favorites, true );
	if ( $key !== false ) {
		unset( $favorites[ $key ] );
		$favorites = array_values( $favorites );
		SPC()->session->set( 'favorites', $favorites );
		do_action( 'sunshine_delete_favorite', $image_id );
	}
	return count( $favorites );
}

function sunshine_has_session_favorite( $image_id ) {
	return in_array( intval( $image_id ), sunshine_get_session_favorite_ids(), true );
}

function sunshine_clear_session_favorites() {
	SPC()->session->delete( 'favorites' );
}

add_filter( 'sunshine_account_require_login_message', 'sunshine_account_require_login_message_favorites', 10, 2 );
function sunshine_account_require_login_message_favorites( $message, $vars ) {

	if ( ! empty( $vars['after'] ) && $vars['after'] == 'sunshine_add_favorite' ) {
		$message = __( 'You will need an account to remember your favorites the next time you visit', 'sunshine-photo-cart' );
	}

	return $message;

}

add_action( 'sunshine_after_login', 'sunshine_after_login_add_to_favorites' );
add_action( 'sunshine_after_signup', 'sunshine_after_login_add_to_favorites' );
function sunshine_after_login_add_to_favorites( $user_id ) {
	// Single pending favorite (from modal flow).
	$image_id = SPC()->session->get( 'add_to_favorites' );
	if ( $image_id ) {
		SPC()->customer->add_favorite( intval( $image_id ) );
		SPC()->session->delete( 'add_to_favorites' );
	}

	// Also check for pending favorite from guest choice flow.
	$pending = SPC()->session->get( 'pending_favorite' );
	if ( $pending ) {
		SPC()->customer->add_favorite( intval( $pending ) );
		SPC()->session->delete( 'pending_favorite' );
	}

	// Merge all session-based guest favorites into account.
	$session_favorites = sunshine_get_session_favorite_ids();
	if ( ! empty( $session_favorites ) ) {
		foreach ( $session_favorites as $fav_id ) {
			SPC()->customer->add_favorite( $fav_id );
		}
		sunshine_clear_session_favorites();
		SPC()->session->delete( 'guest_favorites_mode' );
		SPC()->notices->add( __( 'Your selections have been saved to your account', 'sunshine-photo-cart' ) );
		SPC()->log( 'Merged ' . count( $session_favorites ) . ' session favorites into account for user ' . $user_id );
	}
}

add_action( 'sunshine_modal_display_guest_favorites', 'sunshine_modal_display_guest_favorites' );
function sunshine_modal_display_guest_favorites() {
	if ( ! empty( $_POST['image_id'] ) ) {
		SPC()->session->set( 'pending_favorite', intval( $_POST['image_id'] ) );
	}
	$result = array( 'html' => sunshine_get_template_html( 'favorites/guest-choice' ) );
	wp_send_json_success( $result );
}

add_action( 'wp_ajax_sunshine_add_to_favorites', 'sunshine_add_to_favorites' );
add_action( 'wp_ajax_nopriv_sunshine_add_to_favorites', 'sunshine_add_to_favorites' );
function sunshine_add_to_favorites() {
	sunshine_modal_check_security();

	$image_id = isset( $_POST['image_id'] ) ? intval( $_POST['image_id'] ) : 0;
	if ( ! $image_id ) {
		wp_send_json_error( __( 'Invalid image', 'sunshine-photo-cart' ) );
	}

	$image = sunshine_get_image( $image_id );
	if ( ! $image->exists() || ! $image->can_view() ) {
		wp_send_json_error( __( 'Could not add image to favorites', 'sunshine-photo-cart' ) );
	}

	$src = wp_get_attachment_image_url( $image_id, 'sunshine-thumbnail' );

	if ( is_user_logged_in() ) {
		if ( SPC()->customer->has_favorite( $image_id ) ) {
			SPC()->customer->delete_favorite( $image_id );
			$action = 'DELETE';
			SPC()->log( $image->get_name() . ' in ' . $image->get_gallery()->get_name() . ' removed from favorites by ' . SPC()->customer->get_id() );
		} else {
			SPC()->customer->add_favorite( $image_id );
			$action = 'ADD';
			SPC()->log( $image->get_name() . ' in ' . $image->get_gallery()->get_name() . ' added to favorites by ' . SPC()->customer->get_id() );
		}
		wp_send_json_success(
			array(
				'action'   => $action,
				'count'    => SPC()->customer->get_favorite_count(),
				'image_id' => $image_id,
				'src'      => $src,
			)
		);
	}

	// Guest user.
	if ( ! SPC()->get_option( 'enable_guest_favorites' ) || ! SPC()->session->get( 'guest_favorites_mode' ) ) {
		SPC()->session->set( 'pending_favorite', $image_id );
		wp_send_json_success(
			array(
				'action'   => 'REQUIRE_GUEST_CHOICE',
				'image_id' => $image_id,
			)
		);
	}

	// Guest mode active — toggle via session.
	if ( sunshine_has_session_favorite( $image_id ) ) {
		$count  = sunshine_delete_session_favorite( $image_id );
		$action = 'DELETE';
	} else {
		$count  = sunshine_add_session_favorite( $image_id );
		$action = 'ADD';
	}
	wp_send_json_success(
		array(
			'action'   => $action,
			'count'    => $count,
			'image_id' => $image_id,
			'src'      => $src,
		)
	);
}

add_action( 'wp_ajax_nopriv_sunshine_guest_favorites_mode', 'sunshine_guest_favorites_mode' );
function sunshine_guest_favorites_mode() {
	sunshine_modal_check_security();

	if ( ! SPC()->get_option( 'enable_guest_favorites' ) ) {
		wp_send_json_error( __( 'Guest favorites are not enabled', 'sunshine-photo-cart' ) );
	}

	SPC()->session->set( 'guest_favorites_mode', 1 );

	$pending  = SPC()->session->get( 'pending_favorite' );
	$count    = 0;
	$src      = '';
	$image_id = 0;

	if ( $pending ) {
		$image_id = intval( $pending );
		$count    = sunshine_add_session_favorite( $image_id );
		$src      = wp_get_attachment_image_url( $image_id, 'sunshine-thumbnail' );
		SPC()->session->delete( 'pending_favorite' );
	}

	wp_send_json_success(
		array(
			'action'   => 'ADD',
			'count'    => $count,
			'image_id' => $image_id,
			'src'      => $src,
		)
	);
}

// add_action( 'before_delete_post', 'sunshine_cleanup_favorites' );
function sunshine_cleanup_favorites( $post_id ) {
	global $wpdb, $post_type;
	if ( $post_type != 'sunshine-gallery' ) {
		return;
	}
	$image_ids = array();
	$args   = array(
		'post_type'   => 'attachment',
		'post_parent' => $post_id,
		'nopaging'    => true,
	);
	$images = get_posts( $args );
	foreach ( $images as $image ) {
		$image_ids[] = $image->ID;
	}
	if ( ! empty( $image_ids ) ) {
		$image_ids     = array_map( 'intval', $image_ids );
		$placeholders  = implode( ', ', array_fill( 0, count( $image_ids ), '%d' ) );
		$prepared_args = array_merge( array( 'sunshine_favorite' ), $image_ids );
		$query         = $wpdb->prepare(
			"DELETE FROM $wpdb->usermeta
			WHERE meta_key = %s
			AND meta_value IN ($placeholders)",
			$prepared_args
		);
		$wpdb->query( $query );
	}
}

add_action( 'init', 'sunshine_clear_favorites', 100 );
function sunshine_clear_favorites() {
	global $sunshine;
	if ( isset( $_GET['clear_favorites'] ) && wp_verify_nonce( $_GET['clear_favorites'], 'sunshine_clear_favorites' ) ) {
		SPC()->customer->clear_favorites();
		SPC()->notices->add( __( 'Favorites cleared', 'sunshine-photo-cart' ) );
		SPC()->log( 'Favorites cleared' );
		wp_safe_redirect( sunshine_get_page_permalink( 'favorites' ) );
		exit;
	}
}

add_filter( 'user_row_actions', 'sunshine_user_favorites_link_row', 5, 2 );
function sunshine_user_favorites_link_row( $actions, $user ) {
	if ( current_user_can( 'sunshine_manage_options', $user->ID ) && in_array( 'sunshine_customer', $user->roles ) ) {
		$actions['sunshine_customer'] = '<a href="' . admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-customers&customer=' . $user->ID ) . '">' . __( 'Customer Profile', 'sunshine-photo-cart' ) . '</a>';
	}
	return $actions;
}

add_action( 'show_user_profile', 'sunshine_admin_user_show_favorites' );
add_action( 'edit_user_profile', 'sunshine_admin_user_show_favorites' );
function sunshine_admin_user_show_favorites( $user ) {
	if ( current_user_can( 'manage_options' ) ) {
		$favorites = get_user_meta( $user->ID, 'sunshine_favorite' );
		if ( $favorites ) {
			echo '<h3 id="sunshine--favorites">' . esc_html__( 'Sunshine Favorites', 'sunshine-photo-cart' ) . ' (' . count( $favorites ) . ')</h3>';
			?>
				<p><a href="#sunshine--favorites-file-list" id="sunshine--favorites-file-list-link"><?php esc_html_e( 'Image File List', 'sunshine-photo-cart' ); ?></a></p>
				<div id="sunshine--favorites-file-list" style="display: none;">
				<?php
				foreach ( $favorites as $image_id ) {
					$image_file_list[ $image_id ] = get_post_meta( $image_id, 'sunshine_file_name', true );
				}
				foreach ( $image_file_list as &$file ) {
					$file = str_replace( array( '.jpg', '.JPG' ), '', $file );
				}
				?>
					<textarea rows="4" cols="50" onclick="this.focus();this.select()" readonly="readonly"><?php echo esc_textarea( join( ', ', $image_file_list ) ); ?></textarea>
					<p><?php esc_html_e( 'Copy and paste the file names above into Lightroom\'s search feature (Library filter) to quickly find and create a new collection to make processing this order easier. Make sure you are using the "Contains" (and not "Contains All") search parameter.', 'sunshine-photo-cart' ); ?></p>
				</div>
				<script>
				jQuery(document).ready(function($){
					$('#sunshine--favorites-file-list-link').click(function(){
						$('#sunshine--favorites-file-list').slideToggle();
						return false;
					});
				});
				</script>

			<?php
			echo '<ul>';
			foreach ( $favorites as $favorite ) {
				$attachment = get_post( $favorite );
				$image      = wp_get_attachment_image_src( $attachment->ID, 'sunshine-thumbnail' );
				$url        = get_permalink( $attachment->ID );
				?>
			<li style="list-style: none; float: left; margin: 0 20px 20px 0;">
				<a href="<?php echo esc_url( $url ); ?>"><img src="<?php echo esc_url( $image[0] ); ?>" height="100" alt="" /></a><br />
				<?php echo esc_html( get_the_title( $attachment->ID ) ); ?>
			</li>
				<?php
			}
			echo '</ul><br clear="all" />';
		}
	}

}


add_action( 'wp', 'sunshine_favorites_check_availability' );
function sunshine_favorites_check_availability() {
	if ( empty( SPC()->customer ) || empty( SPC()->customer->get_favorite_ids() ) || ! is_sunshine_page( 'favorites' ) ) {
		return;
	}
	$removed_items = false;
	foreach ( SPC()->customer->get_favorite_ids() as $favorite_id ) {
		$image = get_post( $favorite_id );
		if ( ! $image ) {
			SPC()->customer->delete_favorite( $favorite_id );
			$removed_items = true;
		}
	}
	if ( $removed_items ) {
		SPC()->notices->add( __( 'Images in your favorites have been removed because they are no longer available', 'sunshine-photo-cart' ) );
		wp_safe_redirect( sunshine_get_page_permalink( 'favorites' ) );
		exit;
	}
}

add_action( 'sunshine_modal_display_share_favorites', 'sunshine_modal_share_favorites' );
function sunshine_modal_share_favorites() {

	$images = SPC()->customer->get_favorites();
	$result = array( 'html' => sunshine_get_template_html( 'favorites/share', array( 'images' => $images ) ) );
	wp_send_json_success( $result );

}

add_action( 'wp_ajax_sunshine_modal_favorites_share_process', 'sunshine_modal_favorites_share_process' );
function sunshine_modal_favorites_share_process() {

	sunshine_modal_check_security( 'sunshine_favorites_share' );

	do_action( 'sunshine_favorites_share', $_POST );

	SPC()->log( 'Favorites shared' );

	wp_send_json_success();

}

add_action( 'sunshine_add_favorite', 'sunshine_add_favorite' );
function sunshine_add_favorite( $image_id ) {
	$favorite_count = get_post_meta( $image_id, 'favorite_count', true );
	$favorite_count++;
	update_post_meta( $image_id, 'favorite_count', $favorite_count );
}

add_action( 'sunshine_delete_favorite', 'sunshine_delete_favorite' );
function sunshine_delete_favorite( $image_id ) {
	$favorite_count = get_post_meta( $image_id, 'favorite_count', true );
	$favorite_count--;
	update_post_meta( $image_id, 'favorite_count', $favorite_count );
}

function sunshine_get_favorites_by_key( $key, $ids = true ) {

	$args  = array(
		'meta_key'   => 'sunshine_favorite_key',
		'meta_value' => $key,
	);
	$users = get_users( $args );
	if ( ! empty( $users ) ) {
		$customer = new SPC_Customer( $users[0]->ID );
		if ( $ids ) {
			$images = $customer->get_favorite_ids();
		} else {
			$images = $customer->get_favorites();
		}
		return $images;
	}

}
