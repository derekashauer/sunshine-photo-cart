<?php
/******************
COMMON FUNCTIONS
 ******************/
/**
 * Log errors to debug file
 *
 * @since 1.0
 * @param mixed $message String or array to be written to log file
 * @return void
 */
function sunshine_log( $message = '', $pre = '' ) {
	if ( WP_DEBUG === true ) {
		/*
		$backtrace = debug_backtrace();
		error_log( print_r( $backtrace, 1 ) );
		return;
		//error_log( '*** ' . basename( $backtrace[0]['file'] ) . ', Line ' . $backtrace[0]['line'] );
		$i = '';
		foreach ( $backtrace as $b ) {
			$i .= '*';
			error_log( $i . ': ' . basename( $b['file'] ) . ', Line ' . $b['line'] );
		}
		*/
		if ( $pre ) {
			error_log( $pre );
		}
		if ( empty( $message ) ) {
			$message = 'EMPTY';
		}
		if ( is_array( $message ) || is_object( $message ) ) {
			error_log( print_r( $message, true ) );
		} else {
			error_log( $message );
		}
	}
}

/**
 * Display variables nicely formatted
 *
 * @since 1.0
 * @param mixed $var String or array
 * @return void
 */
function sunshine_dump_var( $var, $echo = true ) {
	if ( $echo ) {
		echo '<pre>';
		print_r( $var );
		echo '</pre>';
	} else {
		$content  = '<pre>';
		$content .= print_r( $var, true );
		$content .= '</pre>';
		return $content;
	}
}

/******************************
	SUNSHINE PAGES
 ******************************/
function is_sunshine_page( $page ) {
	global $post;

	if ( empty( $post ) ) {
		return false;
	}

	if ( ! empty( $page ) && SPC()->get_page( $page ) == $post->ID ) {
		if ( $page === 'checkout' && isset( $_GET['order_key'] ) ) {
			// Special situation for checkout as the receipt page is technically still checkout page but with endpoint.
			return false;
		}
		return true;
	}

	return false;

}

function is_sunshine( $from = '' ) {
	global $post;
	$return = '';

	if ( empty( $post ) ) {
		return false;
	}

	if ( defined( 'IS_SUNSHINE' ) ) {
		return IS_SUNSHINE;
	}

	if ( ( $GLOBALS['pagenow'] === 'wp-login.php' && isset( $_GET['sunshine-photo-cart'] ) && $_GET['sunshine-photo-cart'] == 1 ) || ( isset( $_POST['sunshine-photo-cart'] ) && $_POST['sunshine-photo-cart'] == 1 ) ) {
		$return = 'sunshine-photo-cart';
	}

	if ( SPC()->get_page( $post->ID ) ) {
		$return = 'SUNSHINE-PAGE';
	}
	if ( get_post_type( $post ) == 'sunshine-gallery' ) {
		$return = 'SUNSHINE-GALLERY';
	}
	if ( is_post_type_archive( 'sunshine-gallery' ) ) {
		$return = 'SUNSHINE-GALLERY-ARCHIVE';
	}
	if ( ! empty( $post ) && $post->post_parent > 0 && get_post_type( $post->post_parent ) == 'sunshine-gallery' ) {
		$return = 'SUNSHINE-IMAGE';
	}
	if ( get_post_type( $post ) == 'sunshine-order' ) {
		$return = 'SUNSHINE-ORDER';
	}

	// Check for default Sunshine shortcodes
	$default_shortcodes = array( 'sunshine_galleries', 'sunshine_gallery', 'sunshine_gallery_password', 'sunshine_search' );

	// Allow plugins to register additional shortcodes that should be considered Sunshine pages
	$sunshine_shortcodes = apply_filters( 'sunshine_shortcodes', $default_shortcodes );

	// Check if any Sunshine shortcode is present
	$has_shortcode = false;
	foreach ( $sunshine_shortcodes as $shortcode ) {
		if ( has_shortcode( $post->post_content, $shortcode ) ) {
			$has_shortcode = true;
			break;
		}
	}

	if ( $has_shortcode ) {
		$return = 'SUNSHINE-SHORTCODE';
	}

	$return = apply_filters( 'is_sunshine', $return );

	if ( $return ) {
		if ( ! defined( 'IS_SUNSHINE' ) ) {
			define( 'IS_SUNSHINE', $return );
		}
		return $return;
	} else {
		return false;
	}
}

function sunshine_get_page_url( $page ) {
	return get_permalink( SPC()->get_page( $page ) );
}

/**
 * Change letter to number for file size
 *
 * @since 1.0
 * @param string $v string value
 * @return string
 */
function sunshine_let_to_num( $v ) {
	$l   = substr( $v, -1 );
	$ret = substr( $v, 0, -1 );
	switch ( strtoupper( $l ) ) {
		case 'P':
			$ret *= 1024;
		case 'T':
			$ret *= 1024;
		case 'G':
			$ret *= 1024;
		case 'M':
			$ret *= 1024;
		case 'K':
			$ret *= 1024;
			break;
	}
	return $ret;
}

/**
 * Change letter to number for file size
 *
 * @since 2.4
 * @param string $needle string value
 * @param string $haystack array
 * @return boolean
 */
function sunshine_in_array_r( $needle, $haystack, $strict = false ) {
	foreach ( $haystack as $item ) {
		if ( ( $strict ? $item === $needle : $item == $needle ) || ( is_array( $item ) && sunshine_in_array_r( $needle, $item, $strict ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Sort an array by a specific key/column
 *
 * @since 1.8
 * @return array
 */
function sunshine_array_sort_by_column( &$arr, $col, $dir = SORT_ASC ) {
	$sort_col = array();
	if ( empty( $arr ) ) {
		return;
	}
	foreach ( $arr as $key => $row ) {
		$sort_col[ $key ] = $row[ $col ];
	}
	array_multisort( $sort_col, $dir, $arr );
}

function sunshine_get_currencies() {
	return apply_filters(
		'sunshine_currencies',
		array(
			'AED' => __( 'United Arab Emirates dirham', 'sunshine-photo-cart' ),
			'AFN' => __( 'Afghan afghani', 'sunshine-photo-cart' ),
			'ALL' => __( 'Albanian lek', 'sunshine-photo-cart' ),
			'AMD' => __( 'Armenian dram', 'sunshine-photo-cart' ),
			'ANG' => __( 'Netherlands Antillean guilder', 'sunshine-photo-cart' ),
			'AOA' => __( 'Angolan kwanza', 'sunshine-photo-cart' ),
			'ARS' => __( 'Argentine peso', 'sunshine-photo-cart' ),
			'AUD' => __( 'Australian dollar', 'sunshine-photo-cart' ),
			'AWG' => __( 'Aruban florin', 'sunshine-photo-cart' ),
			'AZN' => __( 'Azerbaijani manat', 'sunshine-photo-cart' ),
			'BAM' => __( 'Bosnia and Herzegovina convertible mark', 'sunshine-photo-cart' ),
			'BBD' => __( 'Barbadian dollar', 'sunshine-photo-cart' ),
			'BDT' => __( 'Bangladeshi taka', 'sunshine-photo-cart' ),
			'BGN' => __( 'Bulgarian lev', 'sunshine-photo-cart' ),
			'BHD' => __( 'Bahraini dinar', 'sunshine-photo-cart' ),
			'BIF' => __( 'Burundian franc', 'sunshine-photo-cart' ),
			'BMD' => __( 'Bermudian dollar', 'sunshine-photo-cart' ),
			'BND' => __( 'Brunei dollar', 'sunshine-photo-cart' ),
			'BOB' => __( 'Bolivian boliviano', 'sunshine-photo-cart' ),
			'BRL' => __( 'Brazilian real', 'sunshine-photo-cart' ),
			'BSD' => __( 'Bahamian dollar', 'sunshine-photo-cart' ),
			'BTC' => __( 'Bitcoin', 'sunshine-photo-cart' ),
			'BTN' => __( 'Bhutanese ngultrum', 'sunshine-photo-cart' ),
			'BWP' => __( 'Botswana pula', 'sunshine-photo-cart' ),
			'BYR' => __( 'Belarusian ruble (old)', 'sunshine-photo-cart' ),
			'BYN' => __( 'Belarusian ruble', 'sunshine-photo-cart' ),
			'BZD' => __( 'Belize dollar', 'sunshine-photo-cart' ),
			'CAD' => __( 'Canadian dollar', 'sunshine-photo-cart' ),
			'CDF' => __( 'Congolese franc', 'sunshine-photo-cart' ),
			'CHF' => __( 'Swiss franc', 'sunshine-photo-cart' ),
			'CLP' => __( 'Chilean peso', 'sunshine-photo-cart' ),
			'CNY' => __( 'Chinese yuan', 'sunshine-photo-cart' ),
			'COP' => __( 'Colombian peso', 'sunshine-photo-cart' ),
			'CRC' => __( 'Costa Rican col&oacute;n', 'sunshine-photo-cart' ),
			'CUC' => __( 'Cuban convertible peso', 'sunshine-photo-cart' ),
			'CUP' => __( 'Cuban peso', 'sunshine-photo-cart' ),
			'CVE' => __( 'Cape Verdean escudo', 'sunshine-photo-cart' ),
			'CZK' => __( 'Czech koruna', 'sunshine-photo-cart' ),
			'DJF' => __( 'Djiboutian franc', 'sunshine-photo-cart' ),
			'DKK' => __( 'Danish krone', 'sunshine-photo-cart' ),
			'DOP' => __( 'Dominican peso', 'sunshine-photo-cart' ),
			'DZD' => __( 'Algerian dinar', 'sunshine-photo-cart' ),
			'EGP' => __( 'Egyptian pound', 'sunshine-photo-cart' ),
			'ERN' => __( 'Eritrean nakfa', 'sunshine-photo-cart' ),
			'ETB' => __( 'Ethiopian birr', 'sunshine-photo-cart' ),
			'EUR' => __( 'Euro', 'sunshine-photo-cart' ),
			'FJD' => __( 'Fijian dollar', 'sunshine-photo-cart' ),
			'FKP' => __( 'Falkland Islands pound', 'sunshine-photo-cart' ),
			'GBP' => __( 'Pound sterling', 'sunshine-photo-cart' ),
			'GEL' => __( 'Georgian lari', 'sunshine-photo-cart' ),
			'GGP' => __( 'Guernsey pound', 'sunshine-photo-cart' ),
			'GHS' => __( 'Ghana cedi', 'sunshine-photo-cart' ),
			'GIP' => __( 'Gibraltar pound', 'sunshine-photo-cart' ),
			'GMD' => __( 'Gambian dalasi', 'sunshine-photo-cart' ),
			'GNF' => __( 'Guinean franc', 'sunshine-photo-cart' ),
			'GTQ' => __( 'Guatemalan quetzal', 'sunshine-photo-cart' ),
			'GYD' => __( 'Guyanese dollar', 'sunshine-photo-cart' ),
			'HKD' => __( 'Hong Kong dollar', 'sunshine-photo-cart' ),
			'HNL' => __( 'Honduran lempira', 'sunshine-photo-cart' ),
			'HRK' => __( 'Croatian kuna', 'sunshine-photo-cart' ),
			'HTG' => __( 'Haitian gourde', 'sunshine-photo-cart' ),
			'HUF' => __( 'Hungarian forint', 'sunshine-photo-cart' ),
			'IDR' => __( 'Indonesian rupiah', 'sunshine-photo-cart' ),
			'ILS' => __( 'Israeli new shekel', 'sunshine-photo-cart' ),
			'IMP' => __( 'Manx pound', 'sunshine-photo-cart' ),
			'INR' => __( 'Indian rupee', 'sunshine-photo-cart' ),
			'IQD' => __( 'Iraqi dinar', 'sunshine-photo-cart' ),
			'IRR' => __( 'Iranian rial', 'sunshine-photo-cart' ),
			'IRT' => __( 'Iranian toman', 'sunshine-photo-cart' ),
			'ISK' => __( 'Icelandic kr&oacute;na', 'sunshine-photo-cart' ),
			'JEP' => __( 'Jersey pound', 'sunshine-photo-cart' ),
			'JMD' => __( 'Jamaican dollar', 'sunshine-photo-cart' ),
			'JOD' => __( 'Jordanian dinar', 'sunshine-photo-cart' ),
			'JPY' => __( 'Japanese yen', 'sunshine-photo-cart' ),
			'KES' => __( 'Kenyan shilling', 'sunshine-photo-cart' ),
			'KGS' => __( 'Kyrgyzstani som', 'sunshine-photo-cart' ),
			'KHR' => __( 'Cambodian riel', 'sunshine-photo-cart' ),
			'KMF' => __( 'Comorian franc', 'sunshine-photo-cart' ),
			'KPW' => __( 'North Korean won', 'sunshine-photo-cart' ),
			'KRW' => __( 'South Korean won', 'sunshine-photo-cart' ),
			'KWD' => __( 'Kuwaiti dinar', 'sunshine-photo-cart' ),
			'KYD' => __( 'Cayman Islands dollar', 'sunshine-photo-cart' ),
			'KZT' => __( 'Kazakhstani tenge', 'sunshine-photo-cart' ),
			'LAK' => __( 'Lao kip', 'sunshine-photo-cart' ),
			'LBP' => __( 'Lebanese pound', 'sunshine-photo-cart' ),
			'LKR' => __( 'Sri Lankan rupee', 'sunshine-photo-cart' ),
			'LRD' => __( 'Liberian dollar', 'sunshine-photo-cart' ),
			'LSL' => __( 'Lesotho loti', 'sunshine-photo-cart' ),
			'LYD' => __( 'Libyan dinar', 'sunshine-photo-cart' ),
			'MAD' => __( 'Moroccan dirham', 'sunshine-photo-cart' ),
			'MDL' => __( 'Moldovan leu', 'sunshine-photo-cart' ),
			'MGA' => __( 'Malagasy ariary', 'sunshine-photo-cart' ),
			'MKD' => __( 'Macedonian denar', 'sunshine-photo-cart' ),
			'MMK' => __( 'Burmese kyat', 'sunshine-photo-cart' ),
			'MNT' => __( 'Mongolian t&ouml;gr&ouml;g', 'sunshine-photo-cart' ),
			'MOP' => __( 'Macanese pataca', 'sunshine-photo-cart' ),
			'MRU' => __( 'Mauritanian ouguiya', 'sunshine-photo-cart' ),
			'MUR' => __( 'Mauritian rupee', 'sunshine-photo-cart' ),
			'MVR' => __( 'Maldivian rufiyaa', 'sunshine-photo-cart' ),
			'MWK' => __( 'Malawian kwacha', 'sunshine-photo-cart' ),
			'MXN' => __( 'Mexican peso', 'sunshine-photo-cart' ),
			'MYR' => __( 'Malaysian ringgit', 'sunshine-photo-cart' ),
			'MZN' => __( 'Mozambican metical', 'sunshine-photo-cart' ),
			'NAD' => __( 'Namibian dollar', 'sunshine-photo-cart' ),
			'NGN' => __( 'Nigerian naira', 'sunshine-photo-cart' ),
			'NIO' => __( 'Nicaraguan c&oacute;rdoba', 'sunshine-photo-cart' ),
			'NOK' => __( 'Norwegian krone', 'sunshine-photo-cart' ),
			'NPR' => __( 'Nepalese rupee', 'sunshine-photo-cart' ),
			'NZD' => __( 'New Zealand dollar', 'sunshine-photo-cart' ),
			'OMR' => __( 'Omani rial', 'sunshine-photo-cart' ),
			'PAB' => __( 'Panamanian balboa', 'sunshine-photo-cart' ),
			'PEN' => __( 'Sol', 'sunshine-photo-cart' ),
			'PGK' => __( 'Papua New Guinean kina', 'sunshine-photo-cart' ),
			'PHP' => __( 'Philippine peso', 'sunshine-photo-cart' ),
			'PKR' => __( 'Pakistani rupee', 'sunshine-photo-cart' ),
			'PLN' => __( 'Polish z&#x142;oty', 'sunshine-photo-cart' ),
			'PRB' => __( 'Transnistrian ruble', 'sunshine-photo-cart' ),
			'PYG' => __( 'Paraguayan guaran&iacute;', 'sunshine-photo-cart' ),
			'QAR' => __( 'Qatari riyal', 'sunshine-photo-cart' ),
			'RON' => __( 'Romanian leu', 'sunshine-photo-cart' ),
			'RSD' => __( 'Serbian dinar', 'sunshine-photo-cart' ),
			'RUB' => __( 'Russian ruble', 'sunshine-photo-cart' ),
			'RWF' => __( 'Rwandan franc', 'sunshine-photo-cart' ),
			'SAR' => __( 'Saudi riyal', 'sunshine-photo-cart' ),
			'SBD' => __( 'Solomon Islands dollar', 'sunshine-photo-cart' ),
			'SCR' => __( 'Seychellois rupee', 'sunshine-photo-cart' ),
			'SDG' => __( 'Sudanese pound', 'sunshine-photo-cart' ),
			'SEK' => __( 'Swedish krona', 'sunshine-photo-cart' ),
			'SGD' => __( 'Singapore dollar', 'sunshine-photo-cart' ),
			'SHP' => __( 'Saint Helena pound', 'sunshine-photo-cart' ),
			'SLL' => __( 'Sierra Leonean leone', 'sunshine-photo-cart' ),
			'SOS' => __( 'Somali shilling', 'sunshine-photo-cart' ),
			'SRD' => __( 'Surinamese dollar', 'sunshine-photo-cart' ),
			'SSP' => __( 'South Sudanese pound', 'sunshine-photo-cart' ),
			'STN' => __( 'S&atilde;o Tom&eacute; and Pr&iacute;ncipe dobra', 'sunshine-photo-cart' ),
			'SYP' => __( 'Syrian pound', 'sunshine-photo-cart' ),
			'SZL' => __( 'Swazi lilangeni', 'sunshine-photo-cart' ),
			'THB' => __( 'Thai baht', 'sunshine-photo-cart' ),
			'TJS' => __( 'Tajikistani somoni', 'sunshine-photo-cart' ),
			'TMT' => __( 'Turkmenistan manat', 'sunshine-photo-cart' ),
			'TND' => __( 'Tunisian dinar', 'sunshine-photo-cart' ),
			'TOP' => __( 'Tongan pa&#x2bb;anga', 'sunshine-photo-cart' ),
			'TRY' => __( 'Turkish lira', 'sunshine-photo-cart' ),
			'TTD' => __( 'Trinidad and Tobago dollar', 'sunshine-photo-cart' ),
			'TWD' => __( 'New Taiwan dollar', 'sunshine-photo-cart' ),
			'TZS' => __( 'Tanzanian shilling', 'sunshine-photo-cart' ),
			'UAH' => __( 'Ukrainian hryvnia', 'sunshine-photo-cart' ),
			'UGX' => __( 'Ugandan shilling', 'sunshine-photo-cart' ),
			'USD' => __( 'United States (US) dollar', 'sunshine-photo-cart' ),
			'UYU' => __( 'Uruguayan peso', 'sunshine-photo-cart' ),
			'UZS' => __( 'Uzbekistani som', 'sunshine-photo-cart' ),
			'VEF' => __( 'Venezuelan bol&iacute;var (2008–2018)', 'sunshine-photo-cart' ),
			'VES' => __( 'Venezuelan bol&iacute;var', 'sunshine-photo-cart' ),
			'VND' => __( 'Vietnamese &#x111;&#x1ed3;ng', 'sunshine-photo-cart' ),
			'VUV' => __( 'Vanuatu vatu', 'sunshine-photo-cart' ),
			'WST' => __( 'Samoan t&#x101;l&#x101;', 'sunshine-photo-cart' ),
			'XAF' => __( 'Central African CFA franc', 'sunshine-photo-cart' ),
			'XCD' => __( 'East Caribbean dollar', 'sunshine-photo-cart' ),
			'XOF' => __( 'West African CFA franc', 'sunshine-photo-cart' ),
			'XPF' => __( 'CFP franc', 'sunshine-photo-cart' ),
			'YER' => __( 'Yemeni rial', 'sunshine-photo-cart' ),
			'ZAR' => __( 'South African rand', 'sunshine-photo-cart' ),
			'ZMW' => __( 'Zambian kwacha', 'sunshine-photo-cart' ),
		)
	);
}

/**
 * Get all images from a folder
 *
 * @since 1.0
 * @return array of file names
 */
function sunshine_get_images_in_folder( $folder ) {
	$images = glob( $folder . '/*.[jJ][pP][gG]' );
	$images = apply_filters( 'sunshine_images_in_folder', $images, $folder );
	$i      = 0;
	if ( $images ) {
		// ProPhoto hack because they regenerate the Featured Image every time a new PP Theme is activated and save it in our folder
		foreach ( $images as &$image ) {
			if ( strpos( $image, '(pp_' ) !== false ) {
				unset( $images[ $i ] );
			}
			$i++;
		}
	}
	return $images;
}

/**
 * Count how many images are in a folder
 *
 * @since 1.0
 * @return number
 */
function sunshine_image_folder_count( $folder ) {
	return count( sunshine_get_images_in_folder( $folder ) );
}

/**********************
IMAGE PAGE
 ***********************/

/**
 * Allow file extensions
 *
 * @since 1.8
 * @return array
 */
function sunshine_allowed_file_extensions() {
	$extensions = array( 'jpg', 'jpeg', 'png' );
	return apply_filters( 'sunshine_allowed_file_extensions', $extensions );
}



/**********************
ADMIN TOOLBAR
*/
add_action( 'wp_before_admin_bar_render', 'sunshine_customize_admin_toolbar' );
function sunshine_customize_admin_toolbar() {
	global $wp_admin_bar, $sunshine;
	if ( ! is_admin() && is_sunshine_page( 'home' ) && ! empty( SPC()->frontend->is_gallery() ) ) {
		$wp_admin_bar->add_menu(
			array(
				'id'     => 'edit',
				'parent' => false,
				'title'  => __( 'Edit Gallery', 'sunshine-photo-cart' ),
				'href'   => admin_url( 'post.php?post=' . SPC()->frontend->current_gallery->get_id() . '&action=edit' ),
				'class'  => 'ab-item',
			)
		);

	}
}

/**********************
CUSTOM IMAGE UPLOAD LOCATION
 ***********************/
function sunshine_doing_upload( $gallery_id ) {
	if ( ! defined( 'SUNSHINE_UPLOAD' ) ) {
		define( 'SUNSHINE_UPLOAD', intval( $gallery_id ) );
	}
	add_filter( 'upload_dir', 'sunshine_custom_upload_dir' );
	set_time_limit( 600 );
}

function sunshine_custom_upload_dir( $param ) {
	if ( ! empty( SUNSHINE_UPLOAD ) && 'sunshine-gallery' == get_post_type( SUNSHINE_UPLOAD ) ) {
		$custom_directory = '/sunshine/' . SUNSHINE_UPLOAD;
		$param['path']    = $param['basedir'] . $custom_directory;
		$param['url']     = $param['baseurl'] . $custom_directory;
	}
	return $param;
}

/**********************
PROPHOTO 4/5 retina workaround
*/
add_action( 'the_content', 'sunshine_prevent_prophoto_retina_sunshine', 5000 );
function sunshine_prevent_prophoto_retina_sunshine( $content ) {
	$theme = wp_get_theme();
	if ( 'ProPhoto' != $theme->name || ! is_sunshine() ) {
		return $content;
	}
	$pattern = "/(<a[^>]+href=(?:\"|')([^'\"]+)(?:\"|')[^>]*>)?(?:[ \t\n]+)?(<img[^>]*>)(?:[ \t\n]+)?(<\/a>)?/i";
	preg_match( $pattern, $content, $matches );
	if ( empty( $matches ) ) {
		return $content;
	}
	// prevent p5 retina-zation by fooling it to think it already has a `data-src-2x` attr
	return str_replace( ' src=', ' data-prevent-data-src-2x="no" src=', $content );
}


function sunshine_core_order_statuses() {
	return array( 'new', 'cancelled', 'pending', 'processing', 'refunded', 'pickup', 'shipped', 'failed' );
}

/*
WORKAROUND for WordPress core bug that does not check proper capabilities
for attachments of private custom post types
WP will *always* check for 'read_private_posts' when it should check for 'read_private_sunshine_galleries'
This will give a user the 'read_private_posts' capability when posting to a private sunshine gallery
*/
add_filter( 'user_has_cap', 'sunshine_user_has_cap', 9996, 4 );
function sunshine_user_has_cap( $allcaps, $caps, $args, $user ) {
	if ( isset( $_POST ) && ! empty( $_POST['comment'] ) && ! is_admin() ) {
		$image_id = intval( $_POST['comment_post_ID'] );
		// Get gallery ID
		$gallery_id = wp_get_post_parent_id( $image_id );
		// If sunshine gallery and set to private, add 'read_post' to capabilities
		if ( get_post_type( $gallery_id ) == 'sunshine-gallery' && get_post_status( $gallery_id ) == 'private' ) {
			$allcaps['read_private_posts'] = 1;
			// $allcaps['unfiltered_html'] = 1;
		}
	}
	return $allcaps;
}

/* Prevent order comments/log from appearing in various places */
add_action( 'pre_get_comments', 'sunshine_hide_comments', 10 );
function sunshine_hide_comments( $query ) {

	$sunshine_comment_types = array(
		'sunshine_order_log',
		'sunshine_order_comment',
	);
	if ( isset( $query->query_vars['type'] ) && in_array( $query->query_vars['type'], $sunshine_comment_types ) ) {
		return;
	}
	$types = isset( $query->query_vars['type__not_in'] ) ? $query->query_vars['type__not_in'] : array();
	if ( ! is_array( $types ) ) {
		$types = array( $types );
	}
	$query->query_vars['type__not_in'] = array_merge( $types, $sunshine_comment_types );

}

function sunshine_image_placeholder_url() {
	return apply_filters( 'sunshine_image_placeholder_url', SUNSHINE_PHOTO_CART_URL . '/assets/images/missing-image.png' );
}

function sunshine_image_placeholder_html( $args = array() ) {
	$atts = '';
	if ( ! empty( $args ) ) {
		foreach ( $args as $key => $value ) {
			$atts .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
		}
	}
	return '<img src="' . sunshine_image_placeholder_url() . '" alt="" ' . $atts . ' />';
}

// Enable attachment pages for WP 6.4.
add_action( 'pre_option_wp_attachment_pages_enabled', '__return_true' );

function sunshine_random_string( $length = 10 ) {
	$characters        = '0123456789abcdefghijklmnopqrstuvwxyz';
	$characters_length = strlen( $characters );
	$random_string     = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$random_string .= $characters[ rand( 0, $characters_length - 1 ) ];
	}
	return $random_string;
}

// Make .htaccess file which protects all the download files.
function sunshine_create_htaccess( $force = false ) {

	$upload_dir = wp_upload_dir();
	$file       = $upload_dir['basedir'] . '/sunshine/.htaccess';

	// Check if the URL we are protecting is still accurate, delete if not. May have moved domains from staging to live.
	$url          = get_bloginfo( 'url' );
	$url          = str_replace( array( 'http://', 'https://', 'www.' ), '', $url );
	$existing_url = get_option( 'sunshine_download_htaccess_url' );
	if ( $url != $existing_url || $force ) {
		@unlink( $file );
	}

	if ( ! file_exists( $file ) ) {
		SPC()->log( 'New htaccess file created' );
		$escaped_url = preg_replace( '/\./', '\.', $url );
		$data        = "RewriteEngine on

# Block direct access to log files
RewriteRule \.(txt|log)$ - [F,L]

# Allow intermediate-sized images without restrictions
RewriteCond %{REQUEST_URI} ^/.*-\d+x\d+\.(jpg|png|gif)$ [NC]
RewriteRule \.(jpg|png|gif)$ - [L]

# Block hotlinking from external referrers for full-size images
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?$escaped_url [NC]
RewriteRule \.(jpg|png|gif)$ - [F,L]

# Allow access to full-sized images only if they exist
RewriteCond %{REQUEST_FILENAME} -s
RewriteRule \.(jpg)$ - [L]";
		file_put_contents( $file, $data );
		update_option( 'sunshine_download_htaccess_url', $url, false );
	}

}

add_action( 'sunshine_daily', 'sunshine_create_htaccess' );

function sunshine_get_import_directory() {
	$upload_dir = wp_upload_dir();
	return apply_filters( 'sunshine_import_directory', $upload_dir['basedir'] . '/sunshine/upload' );
}
