<?php
/**
 * Photo protection: the notice shown when someone tries to screenshot, save or print photos,
 * the partial blur on touch screens, and the record of every attempt.
 *
 * None of this can truly stop a screenshot. It tells visitors the photos are protected and
 * gives the photographer a record of who tried.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The kinds of attempts that are recorded, with the label shown in the log.
 *
 * @return array
 */
function sunshine_get_protection_attempt_types() {
	return array(
		'screenshot'  => __( 'Screenshot keys', 'sunshine-photo-cart' ),
		'right_click' => __( 'Right-click on a photo', 'sunshine-photo-cart' ),
		'long_press'  => __( 'Pressed and held a photo', 'sunshine-photo-cart' ),
		'save'        => __( 'Save page keys', 'sunshine-photo-cart' ),
		'print'       => __( 'Print', 'sunshine-photo-cart' ),
		'devtools'    => __( 'Browser developer tools keys', 'sunshine-photo-cart' ),
	);
}

/**
 * The starter text for the protection notice, used until the photographer writes their own.
 *
 * @return string
 */
function sunshine_get_protection_notice_default_text() {
	/* translators: %s is the photographer's business (site) name */
	$text = '<p>' . sprintf( __( 'These photos are copyrighted by %s. Taking screenshots of them, saving them or printing them isn\'t allowed.', 'sunshine-photo-cart' ), get_bloginfo( 'name' ) ) . '</p>';
	if ( ! SPC()->get_option( 'proofing' ) ) {
		$text .= '<p>' . __( 'To get your own copy, order a print or a digital download.', 'sunshine-photo-cart' ) . '</p>';
	}
	return $text;
}

/**
 * The notice shown when someone tries to screenshot, save or print photos.
 *
 * @return string
 */
function sunshine_get_protection_notice_text() {
	$text = SPC()->get_option( 'protection_notice' );
	if ( empty( $text ) ) {
		$text = sunshine_get_protection_notice_default_text();
	}
	return $text;
}

/**
 * The starter text for the note that explains the blur on touch screens.
 *
 * @return string
 */
function sunshine_get_protection_banner_default_text() {
	return __( 'Part of each photo is blurred to protect it from screenshots. On a photo\'s own page, tap the photo to move the blur.', 'sunshine-photo-cart' );
}

/**
 * The note that explains the blur on touch screens.
 *
 * @return string
 */
function sunshine_get_protection_banner_text() {
	$text = SPC()->get_option( 'protection_blur_banner_text' );
	if ( empty( $text ) ) {
		$text = sunshine_get_protection_banner_default_text();
	}
	return $text;
}

/**
 * How much of each photo is blurred on touch screens, as a percent.
 *
 * @return int
 */
function sunshine_get_protection_blur_amount() {
	$amount = (int) SPC()->get_option( 'protection_blur_amount' );
	return in_array( $amount, array( 35, 50, 65 ), true ) ? $amount : 50;
}

/**
 * The shape of the blur on touch screens: radial, vertical or diagonal.
 *
 * @return string
 */
function sunshine_get_protection_blur_type() {
	$type = SPC()->get_option( 'protection_blur_type' );
	return in_array( $type, array( 'radial', 'vertical', 'diagonal' ), true ) ? $type : 'radial';
}

/**
 * Is photo protection on for the page being shown.
 *
 * Order pages are left out so customers can still print their receipts.
 *
 * @return bool
 */
function sunshine_protection_is_active() {
	if ( ! is_sunshine() || SPC()->frontend->is_order() ) {
		return false;
	}
	if ( current_user_can( 'sunshine_manage_options' ) ) {
		return false;
	}
	if ( ! empty( SPC()->frontend->current_gallery ) ) {
		return SPC()->frontend->current_gallery->is_protected();
	}
	return (bool) SPC()->get_option( 'disable_right_click' );
}

add_action( 'wp_enqueue_scripts', 'sunshine_protection_enqueue_scripts', 20 );
/**
 * Load the protection script and styles where protection is on.
 */
function sunshine_protection_enqueue_scripts() {
	if ( ! sunshine_protection_is_active() ) {
		return;
	}

	// Loaded even when Sunshine's own CSS is turned off, since the notice and blur don't work without it.
	wp_enqueue_style( 'sunshine-photo-cart-protection', SUNSHINE_PHOTO_CART_URL . 'assets/css/protection.css', '', SUNSHINE_PHOTO_CART_VERSION );
	wp_enqueue_script( 'sunshine-photo-cart-protection', SUNSHINE_PHOTO_CART_URL . 'assets/js/protection.js', array(), SUNSHINE_PHOTO_CART_VERSION, true );

	$frontend = SPC()->frontend;
	$blur     = SPC()->get_option( 'protection_blur' );
	wp_localize_script(
		'sunshine-photo-cart-protection',
		'sunshine_protection',
		array(
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'security'   => wp_create_nonce( 'sunshinephotocart' ),
			'gallery_id' => ! empty( $frontend->current_gallery ) ? $frontend->current_gallery->get_id() : 0,
			'image_id'   => ! empty( $frontend->current_image ) ? $frontend->current_image->get_id() : 0,
			'record'     => SPC()->get_option( 'protection_log' ) ? 1 : 0,
			'focus_blur' => SPC()->get_option( 'protection_focus_blur' ) ? 1 : 0,
			'blur'       => array(
				// 'all' blurs gallery thumbnails and photo pages, 'image' only photo pages.
				'grid'   => ( 'all' === $blur ) ? 1 : 0,
				'single' => ( 'all' === $blur || 'image' === $blur ) ? 1 : 0,
				'type'   => sunshine_get_protection_blur_type(),
				'amount' => sunshine_get_protection_blur_amount(),
				'banner' => SPC()->get_option( 'protection_blur_banner' ) ? 1 : 0,
			),
		)
	);
}

add_action( 'wp_footer', 'sunshine_protection_footer_markup' );
/**
 * The notice and the blur note. Both stay hidden until the script needs them, and the
 * notice is also what gets printed in place of the page.
 */
function sunshine_protection_footer_markup() {
	if ( ! sunshine_protection_is_active() ) {
		return;
	}
	?>
	<div id="sunshine--protection-notice" role="dialog" aria-modal="true" aria-labelledby="sunshine--protection-notice--text">
		<div id="sunshine--protection-notice--box">
			<div id="sunshine--protection-notice--text"><?php echo wp_kses_post( wpautop( sunshine_get_protection_notice_text() ) ); ?></div>
			<?php if ( SPC()->get_option( 'protection_log' ) ) { ?>
				<p id="sunshine--protection-notice--recorded"><?php esc_html_e( 'This attempt has been recorded.', 'sunshine-photo-cart' ); ?></p>
			<?php } ?>
			<button type="button" id="sunshine--protection-notice--close"><?php esc_html_e( 'I understand', 'sunshine-photo-cart' ); ?></button>
		</div>
	</div>
	<?php if ( SPC()->get_option( 'protection_blur' ) && SPC()->get_option( 'protection_blur_banner' ) ) { ?>
		<div id="sunshine--protection-banner">
			<div id="sunshine--protection-banner--text"><?php echo wp_kses_post( wpautop( sunshine_get_protection_banner_text() ) ); ?></div>
			<button type="button" id="sunshine--protection-banner--close" aria-label="<?php esc_attr_e( 'Close', 'sunshine-photo-cart' ); ?>">&times;</button>
		</div>
	<?php } ?>
	<?php
}

add_action( 'wp_ajax_sunshine_protection_attempt', 'sunshine_ajax_protection_attempt' );
add_action( 'wp_ajax_nopriv_sunshine_protection_attempt', 'sunshine_ajax_protection_attempt' );
/**
 * Record an attempt reported by the protection script.
 */
function sunshine_ajax_protection_attempt() {
	if ( ! check_ajax_referer( 'sunshinephotocart', 'security', false ) ) {
		wp_send_json_error();
	}
	if ( current_user_can( 'sunshine_manage_options' ) || ! SPC()->get_option( 'protection_log' ) ) {
		wp_send_json_error();
	}

	$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
	if ( ! array_key_exists( $type, sunshine_get_protection_attempt_types() ) ) {
		wp_send_json_error();
	}

	// Only galleries that really have protection on get records, so the log can't be filled with made-up galleries.
	$gallery_id = isset( $_POST['gallery_id'] ) ? absint( $_POST['gallery_id'] ) : 0;
	if ( $gallery_id ) {
		if ( 'sunshine-gallery' !== get_post_type( $gallery_id ) || ! sunshine_get_gallery( $gallery_id )->is_protected() ) {
			wp_send_json_error();
		}
	} elseif ( ! SPC()->get_option( 'disable_right_click' ) ) {
		wp_send_json_error();
	}

	// A photo is only kept when it belongs to that gallery.
	$image_id = isset( $_POST['image_id'] ) ? absint( $_POST['image_id'] ) : 0;
	if ( $image_id ) {
		$image_gallery = (int) wp_get_post_parent_id( $image_id );
		if ( 'attachment' !== get_post_type( $image_id ) || ( $gallery_id && $image_gallery !== $gallery_id ) ) {
			$image_id = 0;
		} elseif ( ! $gallery_id && 'sunshine-gallery' === get_post_type( $image_gallery ) && sunshine_get_gallery( $image_gallery )->is_protected() ) {
			// Pages like favorites show photos from many galleries, so the photo says which one.
			$gallery_id = $image_gallery;
		}
	}

	if ( sunshine_is_rate_limited( 'protection_attempt', 60 ) ) {
		wp_send_json_error();
	}
	sunshine_add_rate_limit_attempt( 'protection_attempt', HOUR_IN_SECONDS );

	sunshine_record_protection_attempt( $type, $gallery_id, $image_id );
	wp_send_json_success();
}

/**
 * Save one attempt to screenshot, save or print photos.
 *
 * @param string $type       One of sunshine_get_protection_attempt_types().
 * @param int    $gallery_id The gallery, or 0 on pages outside a gallery.
 * @param int    $image_id   The photo, or 0 when it isn't known.
 */
function sunshine_record_protection_attempt( $type, $gallery_id, $image_id = 0 ) {
	global $wpdb;

	$attempt = array(
		'gallery_id' => (int) $gallery_id,
		'image_id'   => (int) $image_id,
		'user_id'    => get_current_user_id(),
		'email'      => sunshine_get_known_visitor_email(),
		'ip'         => ( SPC()->get_option( 'protection_log_ip' ) && isset( $_SERVER['REMOTE_ADDR'] ) ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		'type'       => $type,
		'time'       => time(),
	);
	$table   = $wpdb->prefix . 'sunshine_protection_log';
	if ( false === $wpdb->insert( $table, $attempt ) ) {
		// Right after an update the table can be missing until an admin opens wp-admin, so make it now.
		maybe_sunshine_create_custom_tables();
		$wpdb->insert( $table, $attempt );
	}
	do_action( 'sunshine_protection_attempt', $attempt );
}

/**
 * Recorded attempts, newest first.
 *
 * @param array $args gallery_id, user_id and email narrow the list (an email also finds rows
 *                    saved under the account that has it now); limit caps it.
 * @return array Each record has log_id, gallery_id, image_id, user_id, email, ip, type and time.
 */
function sunshine_get_protection_attempts( $args = array() ) {
	global $wpdb;

	$where = sunshine_protection_attempts_where( $args );
	if ( ! $where ) {
		return array();
	}
	$args = wp_parse_args( $args, array( 'limit' => 0 ) );
	$sql  = "SELECT * FROM {$wpdb->prefix}sunshine_protection_log WHERE {$where} ORDER BY time DESC, log_id DESC";
	if ( $args['limit'] ) {
		$sql .= $wpdb->prepare( ' LIMIT %d', $args['limit'] );
	}
	$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- each part is prepared.

	return is_array( $rows ) ? $rows : array();
}

/**
 * How many attempts were recorded.
 *
 * @param array $args Same as sunshine_get_protection_attempts(), without limit.
 * @return int
 */
function sunshine_count_protection_attempts( $args = array() ) {
	global $wpdb;

	$where = sunshine_protection_attempts_where( $args );
	if ( ! $where ) {
		return 0;
	}
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sunshine_protection_log WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- each part is prepared.
}

/**
 * The prepared WHERE clause shared by the attempt lookups.
 *
 * @param array $args gallery_id, user_id and email.
 * @return string Empty when nothing narrows the list.
 */
function sunshine_protection_attempts_where( $args ) {
	global $wpdb;

	$args  = wp_parse_args(
		$args,
		array(
			'gallery_id' => 0,
			'user_id'    => 0,
			'email'      => '',
		)
	);
	$where = array();
	if ( $args['gallery_id'] ) {
		$where[] = $wpdb->prepare( 'gallery_id = %d', $args['gallery_id'] );
	}
	if ( $args['user_id'] ) {
		$where[] = $wpdb->prepare( 'user_id = %d', $args['user_id'] );
	}
	if ( $args['email'] ) {
		$user    = get_user_by( 'email', $args['email'] );
		$where[] = $user ? $wpdb->prepare( '( email = %s OR user_id = %d )', $args['email'], $user->ID ) : $wpdb->prepare( 'email = %s', $args['email'] );
	}
	return implode( ' AND ', $where );
}

add_action( 'before_delete_post', 'sunshine_delete_gallery_protection_attempts' );
/**
 * Remove a gallery's recorded attempts when the gallery is deleted, as its other data is.
 *
 * @param int $post_id The post being deleted.
 */
function sunshine_delete_gallery_protection_attempts( $post_id ) {
	global $wpdb;
	if ( 'sunshine-gallery' !== get_post_type( $post_id ) ) {
		return;
	}
	$wpdb->delete( $wpdb->prefix . 'sunshine_protection_log', array( 'gallery_id' => $post_id ), array( '%d' ) );
}

/**
 * A reminder to mention recorded attempts in the site's privacy policy, linking to it when there is one.
 *
 * @return string HTML.
 */
function sunshine_protection_privacy_policy_reminder() {
	$policy_id = (int) get_option( 'wp_page_for_privacy_policy' );
	if ( $policy_id && 'publish' === get_post_status( $policy_id ) ) {
		return sprintf(
			/* translators: %s is a link to edit the site's privacy policy */
			__( 'Mention this in your %s. Suggested wording is in the WordPress privacy policy guide.', 'sunshine-photo-cart' ),
			'<a href="' . esc_url( get_edit_post_link( $policy_id ) ) . '">' . esc_html__( 'privacy policy', 'sunshine-photo-cart' ) . '</a>'
		);
	}
	return sprintf(
		/* translators: %s is a link to the WordPress privacy settings */
		__( 'If you have a privacy policy, mention this in it. You can set one up in %s.', 'sunshine-photo-cart' ),
		'<a href="' . esc_url( admin_url( 'options-privacy.php' ) ) . '">' . esc_html__( 'Settings > Privacy', 'sunshine-photo-cart' ) . '</a>'
	);
}

add_action( 'admin_init', 'sunshine_protection_privacy_policy_content' );
/**
 * Suggested privacy policy wording for recorded attempts, shown in WordPress's privacy policy guide.
 */
function sunshine_protection_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) || ! SPC()->get_option( 'protection_log' ) ) {
		return;
	}
	$text = '<p class="privacy-policy-tutorial">' . __( 'Sunshine Photo Cart is recording attempts to screenshot, save or print protected photos. Add something like this to your privacy policy.', 'sunshine-photo-cart' ) . '</p>';
	if ( SPC()->get_option( 'protection_log_ip' ) ) {
		$what = __( 'When you try to take a screenshot of, save or print photos in a protected gallery, we record which gallery and photo it was, the date and time, your account or the email address you gave to see the gallery, and your IP address.', 'sunshine-photo-cart' );
	} else {
		$what = __( 'When you try to take a screenshot of, save or print photos in a protected gallery, we record which gallery and photo it was, the date and time, and your account or the email address you gave to see the gallery.', 'sunshine-photo-cart' );
	}
	$text .= '<p>' . $what . ' ' . __( 'We keep these records to protect the copyright of our photos.', 'sunshine-photo-cart' ) . '</p>';
	wp_add_privacy_policy_content( 'Sunshine Photo Cart: ' . __( 'Image Protection', 'sunshine-photo-cart' ), wp_kses_post( $text ) );
}

/**
 * The blur preview on the settings screen. Clicking it moves the blur, like a tap on a phone.
 *
 * @return string HTML.
 */
function sunshine_protection_blur_preview_html() {
	if ( ! is_admin() ) {
		return '';
	}
	// A photo from one of the newest galleries, so the preview shows the photographer's own work.
	$src       = '';
	$galleries = get_posts(
		array(
			'post_type'      => 'sunshine-gallery',
			'post_status'    => 'any',
			'posts_per_page' => 5,
			'fields'         => 'ids',
		)
	);
	foreach ( $galleries as $gallery_id ) {
		$photo = get_children(
			array(
				'post_parent'    => $gallery_id,
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'numberposts'    => 1,
				'fields'         => 'ids',
			)
		);
		if ( $photo ) {
			$src = wp_get_attachment_image_url( reset( $photo ), 'sunshine-thumbnail' );
			break;
		}
	}
	$html  = '<div id="sunshine-protection-preview"><div id="sunshine-protection-preview--photo">';
	$html .= $src ? '<img src="' . esc_url( $src ) . '" alt="" />' : '<div id="sunshine-protection-preview--placeholder"></div>';
	$html .= '</div><p class="description">' . esc_html__( 'Click the preview to move the blur, the way visitors tap on a phone.', 'sunshine-photo-cart' ) . '</p></div>';
	return $html;
}

add_action( 'admin_enqueue_scripts', 'sunshine_protection_admin_enqueue_scripts' );
/**
 * Load the blur preview on the settings screen. It uses the same script and styles as the site,
 * so the preview always matches what visitors see.
 */
function sunshine_protection_admin_enqueue_scripts() {
	if ( ! isset( $_GET['page'] ) || 'sunshine' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the screen.
		return;
	}
	wp_enqueue_style( 'sunshine-photo-cart-protection', SUNSHINE_PHOTO_CART_URL . 'assets/css/protection.css', '', SUNSHINE_PHOTO_CART_VERSION );
	wp_enqueue_script( 'sunshine-photo-cart-protection', SUNSHINE_PHOTO_CART_URL . 'assets/js/protection.js', array(), SUNSHINE_PHOTO_CART_VERSION, true );
	wp_add_inline_script(
		'sunshine-photo-cart-protection',
		"( function () {
			var photo = document.getElementById( 'sunshine-protection-preview--photo' );
			if ( ! photo || ! window.sunshineProtection ) {
				return;
			}
			function type() {
				var checked = document.querySelector( 'input[name=\"sunshine_protection_blur_type\"]:checked' );
				return checked ? checked.value : 'radial';
			}
			function amount() {
				var select = document.querySelector( 'select[name=\"sunshine_protection_blur_amount\"]' );
				return select ? parseInt( select.value, 10 ) : 50;
			}
			function reset() {
				var start = window.sunshineProtection.blurStart( type() );
				photo.style.setProperty( '--sunshine-blur-mask', window.sunshineProtection.blurMask( type(), amount(), start[0], start[1] ) );
			}
			document.querySelectorAll( 'input[name=\"sunshine_protection_blur_type\"], select[name=\"sunshine_protection_blur_amount\"]' ).forEach( function ( field ) {
				field.addEventListener( 'change', reset );
			} );
			photo.addEventListener( 'click', function ( event ) {
				window.sunshineProtection.moveBlur( photo, event, type(), amount() );
			} );
			reset();
		} )();"
	);
}
