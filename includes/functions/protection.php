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
		// Each label says what was detected, not what the visitor meant to do: a key press can be an accident.
		'screenshot'  => __( 'Screenshot keys pressed', 'sunshine-photo-cart' ),
		'right_click' => __( 'Right-click on a photo', 'sunshine-photo-cart' ),
		'long_press'  => __( 'Press and hold on a photo', 'sunshine-photo-cart' ),
		'save'        => __( 'Save page keys pressed', 'sunshine-photo-cart' ),
		'print'       => __( 'Print started', 'sunshine-photo-cart' ),
		'devtools'    => __( 'Developer tools keys pressed', 'sunshine-photo-cart' ),
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
 * The galleries shown on this page: the gallery or photo being viewed, or every gallery
 * embedded with the [sunshine_gallery] shortcode.
 *
 * @return SPC_Gallery[]
 */
function sunshine_protection_galleries() {
	global $post;
	if ( ! empty( SPC()->frontend->current_gallery ) ) {
		return array( SPC()->frontend->current_gallery );
	}
	$galleries = array();
	if ( ! empty( $post->post_content ) && has_shortcode( $post->post_content, 'sunshine_gallery' ) && preg_match_all( '/' . get_shortcode_regex( array( 'sunshine_gallery' ) ) . '/', $post->post_content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$atts = shortcode_parse_atts( $match[3] );
			if ( ! empty( $atts['id'] ) && 'sunshine-gallery' === get_post_type( (int) $atts['id'] ) ) {
				$galleries[ (int) $atts['id'] ] = sunshine_get_gallery( (int) $atts['id'] );
			}
		}
	}
	return array_values( $galleries );
}

/**
 * Is photo protection on for the page being shown.
 *
 * Only pages that show photos are protected. Cart, checkout, account and order pages are left
 * alone so customers can still print them.
 *
 * @return bool
 */
function sunshine_protection_is_active() {
	if ( ! is_sunshine() || current_user_can( 'sunshine_manage_options' ) ) {
		return false;
	}
	// A page showing several galleries is protected when any of them is.
	$galleries = sunshine_protection_galleries();
	if ( $galleries ) {
		foreach ( $galleries as $gallery ) {
			if ( $gallery->is_protected() ) {
				return true;
			}
		}
		return false;
	}
	// Pages showing photos from many galleries follow the site setting, including a gallery list
	// or search placed on any page with a shortcode.
	global $post;
	$content = ! empty( $post->post_content ) ? $post->post_content : '';
	if ( is_sunshine_page( 'home' ) || is_sunshine_page( 'favorites' ) || SPC()->frontend->is_search() || has_shortcode( $content, 'sunshine_galleries' ) || has_shortcode( $content, 'sunshine_search' ) ) {
		return (bool) SPC()->get_option( 'disable_right_click' );
	}
	return false;
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
	$galleries = sunshine_protection_galleries();
	// With several galleries on the page, each record takes its gallery from the photo.
	$gallery  = ( 1 === count( $galleries ) ) ? $galleries[0] : null;
	$blur     = SPC()->get_option( 'protection_blur' );
	wp_localize_script(
		'sunshine-photo-cart-protection',
		'sunshine_protection',
		array(
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'security'   => wp_create_nonce( 'sunshinephotocart' ),
			'gallery_id' => $gallery ? $gallery->get_id() : 0,
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
				<?php // Shown by the script once the attempt is really saved. ?>
				<p id="sunshine--protection-notice--recorded"><?php esc_html_e( 'This has been recorded. If it was an accident, there\'s nothing to worry about.', 'sunshine-photo-cart' ); ?></p>
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
	if ( $gallery_id && ( 'sunshine-gallery' !== get_post_type( $gallery_id ) || ! sunshine_get_gallery( $gallery_id )->is_protected() ) ) {
		wp_send_json_error();
	}

	// A photo is only kept when it belongs to that gallery.
	$image_id = isset( $_POST['image_id'] ) ? absint( $_POST['image_id'] ) : 0;
	if ( $image_id ) {
		$image_gallery = (int) wp_get_post_parent_id( $image_id );
		if ( 'attachment' !== get_post_type( $image_id ) || ( $gallery_id && $image_gallery !== $gallery_id ) ) {
			$image_id = 0;
		} elseif ( ! $gallery_id && 'sunshine-gallery' === get_post_type( $image_gallery ) && sunshine_get_gallery( $image_gallery )->is_protected() ) {
			// Pages like favorites, or a page with several galleries, show photos from many
			// galleries, so the photo says which one.
			$gallery_id = $image_gallery;
		}
	}

	// Without a protected gallery, only pages that follow the site setting can record.
	if ( ! $gallery_id && ! SPC()->get_option( 'disable_right_click' ) ) {
		wp_send_json_error();
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
		'ip'         => '',
		'type'       => $type,
		'time'       => time(),
	);
	// The IP address is only kept for guests nobody can name, since an account or email already says who it was.
	if ( ! $attempt['user_id'] && ! $attempt['email'] && SPC()->get_option( 'protection_log_ip' ) && isset( $_SERVER['REMOTE_ADDR'] ) ) {
		$attempt['ip'] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}
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
 *                    saved under the account that has it now), or all => true lists every record;
 *                    limit and offset page through it.
 * @return array Each record has log_id, gallery_id, image_id, user_id, email, ip, type and time.
 */
function sunshine_get_protection_attempts( $args = array() ) {
	global $wpdb;

	$where = sunshine_protection_attempts_where( $args );
	if ( ! $where ) {
		return array();
	}
	$args = wp_parse_args(
		$args,
		array(
			'limit'  => 0,
			'offset' => 0,
		)
	);
	$sql  = "SELECT * FROM {$wpdb->prefix}sunshine_protection_log WHERE {$where} ORDER BY time DESC, log_id DESC";
	if ( $args['limit'] ) {
		$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
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
 * The WHERE clause for looking up attempts. Empty when nothing narrows the list and every record
 * wasn't asked for, so a missing ID never lists everyone's records by mistake.
 *
 * @param array $args See sunshine_get_protection_attempts().
 * @return string
 */
function sunshine_protection_attempts_where( $args ) {
	if ( ! empty( $args['all'] ) ) {
		return '1=1';
	}
	return sunshine_person_records_where( $args );
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
 * A link to edit the site's privacy policy, or to set one up when there isn't one.
 *
 * @return string HTML.
 */
function sunshine_protection_privacy_policy_link() {
	$policy_id = (int) get_option( 'wp_page_for_privacy_policy' );
	$url       = ( $policy_id && 'publish' === get_post_status( $policy_id ) ) ? get_edit_post_link( $policy_id ) : admin_url( 'options-privacy.php' );
	return '<a href="' . esc_url( $url ) . '">' . esc_html__( 'your privacy policy', 'sunshine-photo-cart' ) . '</a>';
}

add_action( 'admin_init', 'sunshine_protection_privacy_policy_content' );
/**
 * Suggested privacy policy wording for recorded attempts, shown in WordPress's privacy policy guide.
 */
function sunshine_protection_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) || ! SPC()->get_option( 'protection_log' ) ) {
		return;
	}
	$text = '<p class="privacy-policy-tutorial">' . __( 'Sunshine Photo Cart is recording attempts to screenshot, save or print protected photos. Add something like this to your privacy policy, and fill in how people can contact you.', 'sunshine-photo-cart' ) . '</p>';
	if ( SPC()->get_option( 'protection_log_ip' ) ) {
		$what = __( 'In protected galleries, if you use a screenshot, save or print shortcut, or right-click a photo, we record which gallery and photo it was, what kind of action it was, the date and time, and your account or the email address you gave to view the gallery. If we don\'t have either, we record your IP address instead.', 'sunshine-photo-cart' );
	} else {
		$what = __( 'In protected galleries, if you use a screenshot, save or print shortcut, or right-click a photo, we record which gallery and photo it was, what kind of action it was, the date and time, and your account or the email address you gave to view the gallery.', 'sunshine-photo-cart' );
	}
	$text     .= '<p>' . $what . ' ' . __( 'We do this to protect the copyright in our photos. Our legal basis is our legitimate interest in protecting our work. Only we see these records, and we don\'t share them unless we need to for a copyright claim.', 'sunshine-photo-cart' ) . '</p>';
	$retention = sunshine_get_protection_log_retention_text();
	if ( $retention ) {
		/* translators: %s is how long records are kept, like "12 months" */
		$text .= '<p>' . sprintf( __( 'We keep these records for %s, then delete them automatically, unless we need them for an open dispute.', 'sunshine-photo-cart' ), $retention ) . '</p>';
	}
	$text .= '<p>' . __( 'You can ask to see or delete these records, or object to this recording, by contacting us. Some of these actions can happen by accident, so we treat a record as a reason to look into something, not as proof.', 'sunshine-photo-cart' ) . '</p>';
	wp_add_privacy_policy_content( 'Sunshine Photo Cart: ' . __( 'Image Protection', 'sunshine-photo-cart' ), wp_kses_post( $text ) );
}

/**
 * How long recorded attempts are kept, like "12 months", or empty when they're kept until deleted by hand.
 *
 * @return string
 */
function sunshine_get_protection_log_retention_text() {
	$value  = SPC()->get_option( 'privacy_retain_protection_log' );
	$number = ( is_array( $value ) && ! empty( $value['number'] ) ) ? absint( $value['number'] ) : 0;
	if ( ! $number ) {
		return '';
	}
	switch ( isset( $value['unit'] ) ? $value['unit'] : 'months' ) {
		case 'days':
			/* translators: %d is a number of days */
			return sprintf( _n( '%d day', '%d days', $number, 'sunshine-photo-cart' ), $number );
		case 'weeks':
			/* translators: %d is a number of weeks */
			return sprintf( _n( '%d week', '%d weeks', $number, 'sunshine-photo-cart' ), $number );
		case 'years':
			/* translators: %d is a number of years */
			return sprintf( _n( '%d year', '%d years', $number, 'sunshine-photo-cart' ), $number );
		default:
			/* translators: %d is a number of months */
			return sprintf( _n( '%d month', '%d months', $number, 'sunshine-photo-cart' ), $number );
	}
}

add_action( 'add_option_sunshine_protection_log', 'sunshine_protection_log_default_retention', 10, 2 );
add_action( 'update_option_sunshine_protection_log', 'sunshine_protection_log_default_retention', 10, 2 );
/**
 * When recording is turned on and no keep time is set, keep records for 12 months.
 *
 * @param mixed $old_value The value before, or the option name when the option is first added.
 * @param mixed $value     The new value.
 */
function sunshine_protection_log_default_retention( $old_value, $value ) {
	if ( 'sunshine_protection_log' === $old_value ) {
		$old_value = false;
	}
	if ( empty( $value ) || ! empty( $old_value ) ) {
		return;
	}
	$retention = SPC()->get_option( 'privacy_retain_protection_log' );
	if ( empty( $retention['number'] ) ) {
		SPC()->update_option(
			'privacy_retain_protection_log',
			array(
				'number' => 12,
				'unit'   => 'months',
			)
		);
	}
}

/**
 * The line added to the gallery terms while attempts are recorded, so visitors know before
 * they agree. Empty when recording is off.
 *
 * @return string HTML.
 */
function sunshine_get_protection_terms_text() {
	if ( ! SPC()->get_option( 'protection_log' ) ) {
		return '';
	}
	/* translators: %s is the photographer's business (site) name */
	$text = sprintf( __( 'If you use a screenshot, save or print shortcut, or right-click a photo, %s may keep a record of it with your account or email, the gallery and photo, and the time, to protect their copyright.', 'sunshine-photo-cart' ), get_bloginfo( 'name' ) );
	$url  = get_privacy_policy_url();
	if ( $url ) {
		/* translators: %s is a link to the site's privacy policy */
		$text .= ' ' . sprintf( __( 'See the %s for details.', 'sunshine-photo-cart' ), '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html__( 'privacy policy', 'sunshine-photo-cart' ) . '</a>' );
	}
	return '<p>' . $text . '</p>';
}

/**
 * The blur preview on the settings screen. Clicking it moves the blur, like a tap on a phone.
 *
 * @return string HTML.
 */
function sunshine_protection_blur_preview_html() {
	// The settings fields are built on every admin request, so only look up a photo where the preview shows.
	if ( ! is_admin() || wp_doing_ajax() || ! isset( $_GET['page'] ) || 'sunshine' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the screen.
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
