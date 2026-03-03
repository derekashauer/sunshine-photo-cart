<?php
function sunshine_get_settings_fields() {
	/* GENERAL */

	$general_fields         = array();
	$general_fields['1000'] = array(
		'id'   => 'address',
		'name' => __( 'Address', 'sunshine-photo-cart' ),
		'type' => 'header',
	);

	$general_fields['1100'] = array(
		'id'   => 'address1',
		'name' => __( 'Your Address Line 1', 'sunshine-photo-cart' ),
		'type' => 'text',
	);
	$general_fields['1200'] = array(
		'id'   => 'address2',
		'name' => __( 'Address Line 2', 'sunshine-photo-cart' ),
		'type' => 'text',
	);
	$general_fields['1300'] = array(
		'id'   => 'city',
		'name' => __( 'City', 'sunshine-photo-cart' ),
		'type' => 'text',
	);
	$general_fields['1400'] = array(
		'id'   => 'state',
		'name' => __( 'State / Province', 'sunshine-photo-cart' ),
		'type' => 'text',
	);
	$general_fields['1500'] = array(
		'id'   => 'postcode',
		'name' => __( 'Zip / Postcode', 'sunshine-photo-cart' ),
		'type' => 'text',
	);
	$general_fields['1600'] = array(
		'id'      => 'country',
		'name'    => __( 'Country', 'sunshine-photo-cart' ),
		'type'    => 'select',
		'select2' => true,
		'options' => SPC()->countries->get_countries(),
	);

	$general_fields['3000'] = array(
		'id'   => 'currency_formatting',
		'name' => __( 'Currency Formatting', 'sunshine-photo-cart' ),
		'type' => 'header',
	);

	$currencies = sunshine_get_currencies();

	$general_fields['3100'] = array(
		'name'    => __( 'Currency', 'sunshine-photo-cart' ),
		'id'      => 'currency',
		'type'    => 'select',
		'select2' => true,
		'options' => $currencies,
	);
	$general_fields['3200'] = array(
		'name'    => __( 'Currency symbol position', 'sunshine-photo-cart' ),
		'id'      => 'currency_symbol_position',
		'type'    => 'select',
		'options' => array(
			'left'        => __( 'Left', 'sunshine-photo-cart' ),
			'right'       => __( 'Right', 'sunshine-photo-cart' ),
			'left_space'  => __( 'Left space', 'sunshine-photo-cart' ),
			'right_space' => __( 'Right space', 'sunshine-photo-cart' ),
		),
	);
	$general_fields['3300'] = array(
		'name' => __( 'Thousands separator', 'sunshine-photo-cart' ),
		'id'   => 'currency_thousands_separator',
		'type' => 'text',
		'css'  => 'width: 50px;',
	);
	$general_fields['3400'] = array(
		'name' => __( 'Decimal separator', 'sunshine-photo-cart' ),
		'id'   => 'currency_decimal_separator',
		'type' => 'text',
		'css'  => 'width: 50px;',
	);
	$general_fields['3500'] = array(
		'name' => __( 'Number of decimals', 'sunshine-photo-cart' ),
		'id'   => 'currency_decimals',
		'type' => 'number',
		'css'  => 'width: 50px;',
	);
	$general_fields['3600'] = array(
		'name'        => __( 'Show currency code', 'sunshine-photo-cart' ),
		'id'          => 'currency_show_code',
		'type'        => 'checkbox',
		'description' => __( 'Display the 3-character currency code (e.g. USD, CAD, AUD) alongside the currency symbol', 'sunshine-photo-cart' ),
	);

	$general_fields['4000'] = array(
		'id'            => 'accounts',
		'name'          => __( 'Accounts', 'sunshine-photo-cart' ),
		'type'          => 'header',
		'documentation' => 'https://www.sunshinephotocart.com/docs/customer-account-settings/',
	);
	$general_fields['4100'] = array(
		'name'        => __( 'Disable Signup', 'sunshine-photo-cart' ),
		'id'          => 'disable_signup',
		'type'        => 'checkbox',
		'description' => __( 'Disable sign up throughout Sunshine', 'sunshine-photo-cart' ),
	);
	$general_fields['4200'] = array(
		'name'        => __( 'Password Optional for Signup', 'sunshine-photo-cart' ),
		'id'          => 'signup_password_optional',
		'type'        => 'checkbox',
		'description' => __( 'Make password optional when signing up (otherwise password will automatically be created for them)', 'sunshine-photo-cart' ),
		'conditions'  => array(
			array(
				'compare' => '==',
				'value'   => '1',
				'field'   => 'disable_signup',
				'action'  => 'hide',
			),
		),
	);
	$general_fields['4300'] = array(
		'name'        => __( 'Name Optional for Signup', 'sunshine-photo-cart' ),
		'id'          => 'signup_name_optional',
		'type'        => 'checkbox',
		'description' => __( 'Make name optional when signing up', 'sunshine-photo-cart' ),
		'conditions'  => array(
			array(
				'compare' => '==',
				'value'   => '1',
				'field'   => 'disable_signup',
				'action'  => 'hide',
			),
		),
	);
	$general_fields['4400'] = array(
		'name' => __( 'Require account to see products', 'sunshine-photo-cart' ),
		'id'   => 'products_require_account',
		'type' => 'checkbox',
	);

	$general_fields['5000'] = array(
		'id'   => 'data',
		'name' => __( 'Data & Logging', 'sunshine-photo-cart' ),
		'type' => 'header',
	);
	$general_fields['5100'] = array(
		'name'        => __( 'Delete data on uninstall', 'sunshine-photo-cart' ),
		'id'          => 'uninstall_delete_data',
		'type'        => 'checkbox',
		'description' => __( 'Delete all Galleries, Products, Orders, and settings data will be removed when Sunshine is uninstalled. WARNING! This will delete all images uploaded to your galleries as well!', 'sunshine-photo-cart' ),
	);

	$log_desc = '';
	if ( SPC()->get_option( 'enable_log' ) ) {
		$download_url = wp_nonce_url( admin_url( 'admin-post.php?action=sunshine_download_log' ), 'sunshine_download_log' );
		$log_desc     = '<br /><br /><a href="' . esc_url( $download_url ) . '" class="button-primary">' . __( 'Download log', 'sunshine-photo-cart' ) . '</a> ';
		$clear_url    = admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine' );
		$clear_url    = wp_nonce_url( $clear_url, 'sunshine_clear_log', 'sunshine_clear_log' );
		$log_desc    .= '<a href="' . esc_url( $clear_url ) . '" class="button delete">' . __( 'Clear log', 'sunshine-photo-cart' ) . '</a>';
	}
	$general_fields['5200'] = array(
		'name'        => __( 'Enable logging', 'sunshine-photo-cart' ),
		'id'          => 'enable_log',
		'type'        => 'checkbox',
		'description' => __( 'Enable logging of all events within Sunshine to help with debugging. Disable when not needed.', 'sunshine-photo-cart' ) . ' ' . $log_desc,
	);
	$error_log_desc         = '';
	if ( SPC()->get_option( 'enable_error_log' ) ) {
		$download_error_url = wp_nonce_url( admin_url( 'admin-post.php?action=sunshine_download_error_log' ), 'sunshine_download_error_log' );
		$error_log_desc     = '<br /><br /><a href="' . esc_url( $download_error_url ) . '" class="button-primary">' . __( 'Download error log', 'sunshine-photo-cart' ) . '</a> ';
		$clear_error_url    = admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine' );
		$clear_error_url    = wp_nonce_url( $clear_error_url, 'sunshine_clear_error_log', 'sunshine_clear_error_log' );
		$error_log_desc    .= '<a href="' . esc_url( $clear_error_url ) . '" class="button delete">' . __( 'Clear error log', 'sunshine-photo-cart' ) . '</a>';
	}
	$general_fields['5205'] = array(
		'name'        => __( 'Enable PHP error logging', 'sunshine-photo-cart' ),
		'id'          => 'enable_error_log',
		'type'        => 'checkbox',
		'description' => __( 'Capture PHP notices, warnings, and fatal errors to a separate log file. Disable when not needed.', 'sunshine-photo-cart' ) . ' ' . $error_log_desc,
	);

	$general_fields['5210'] = array(
		'name'        => __( 'Hide promos', 'sunshine-photo-cart' ),
		'id'          => 'promos_hide',
		'type'        => 'checkbox',
		'description' => __( 'Disable promos throughout Sunshine Photo Cart', 'sunshine-photo-cart' ),
	);

	$general_fields = apply_filters( 'sunshine_options_general', $general_fields );
	if ( ! empty( $general_fields ) ) {
		ksort( $general_fields );
	}
	$settings[] = array(
		'id'     => 'general',
		'title'  => __( 'General', 'sunshine-photo-cart' ),
		'fields' => $general_fields,
	// 'icon' => SUNSHINE_PHOTO_CART_PATH . 'assets/images/icons/settings.svg'
	);

	/* PAGES */
	$pages_fields         = array();
	$pages_fields['1000'] = array(
		'id'            => 'pages',
		'name'          => __( 'Page Options', 'sunshine-photo-cart' ),
		'description'   => __( 'The following pages need selecting so that Sunshine knows where they are. These pages should have been created upon installation, if not you will need to create them.', 'sunshine-photo-cart' ) . ' <a href="https://www.sunshinephotocart.com/docs/shortcodes/" target="_blank">' . __( 'See required shortcodes', 'sunshine-photo-cart' ) . '</a>',
		'type'          => 'header',
		'documentation' => 'https://www.sunshinephotocart.com/docs/sunshine-pages/',
	);
	/*
	$pages_fields['1100'] = array(
		'name' => __( 'Use Shortcode', 'sunshine-photo-cart' ),
		'id'   => 'use_shortcode',
		'type' => 'checkbox',
		'description' => __( 'By default Sunshine is automatically shown on the below pages. However, if you are using the Block Editor or a 3rd party Page Builder you may want to be more specific about where Sunshine appears by using the shortcode [sunshine]. Check this option, select the new page in the dropdown above, <em>and</em> use the shortcode on each of the pages below.','sunshine-photo-cart' ),
		'options' => array( 1 )
	);
	*/
	$pages_fields['1200'] = array(
		'id'   => 'page',
		'name' => __( 'Main Galleries Page', 'sunshine-photo-cart' ),
		'type' => 'single_select_page',
	);
	$pages_fields['1300'] = array(
		'id'   => 'page_cart',
		'name' => __( 'Cart', 'sunshine-photo-cart' ),
		'type' => 'single_select_page',
	);
	$pages_fields['1400'] = array(
		'id'   => 'page_checkout',
		'name' => __( 'Checkout', 'sunshine-photo-cart' ),
		'type' => 'single_select_page',
	);
	$pages_fields['1500'] = array(
		'id'   => 'page_account',
		'name' => __( 'Account', 'sunshine-photo-cart' ),
		'type' => 'single_select_page',
	);
	$pages_fields['1600'] = array(
		'id'   => 'page_favorites',
		'name' => __( 'Favorites', 'sunshine-photo-cart' ),
		'type' => 'single_select_page',
	);
	$pages_fields['1700'] = array(
		'id'   => 'page_terms',
		'name' => __( 'Terms & Conditions', 'sunshine-photo-cart' ),
		'type' => 'single_select_page',
	);

	$pages_fields['2000'] = array(
		'id'            => 'urls',
		'name'          => __( 'URLs', 'sunshine-photo-cart' ),
		'type'          => 'header',
		'documentation' => 'https://www.sunshinephotocart.com/docs/how-to-customize-the-url-for-galleries/',
	);
	$pages_fields['2100'] = array(
		'name'        => __( 'Gallery', 'sunshine-photo-cart' ),
		'id'          => 'endpoint_gallery',
		'type'        => 'text',
		'callback'    => 'sanitize_title_with_dashes',
		'description' => 'Current gallery URL example: <pre style="display: inline;">' . trailingslashit( sunshine_get_page_permalink( 'home' ) ) . '<strong>' . SPC()->get_option( 'endpoint_gallery', 'gallery' ) . '</strong>/gallery-slug</pre>', // TODO: Use JS to make this dynamic as user types
		'required'    => true,
		'default'     => 'gallery',
	);
	$pages_fields['2200'] = array(
		'name'        => __( 'Order Received', 'sunshine-photo-cart' ),
		'id'          => 'endpoint_order_received',
		'type'        => 'text',
		'callback'    => 'sanitize_title_with_dashes',
		'description' => 'Current order URL example: <pre style="display: inline;">' . trailingslashit( sunshine_get_page_permalink( 'checkout' ) ) . '<strong>' . SPC()->get_option( 'endpoint_order_received', 'receipt' ) . '</strong>/42</pre>',
		'required'    => true,
		'default'     => 'order-received',
	);
	$pages_fields['2300'] = array(
		'name'        => __( 'Store', 'sunshine-photo-cart' ),
		'id'          => 'endpoint_store',
		'type'        => 'text',
		'callback'    => 'sanitize_title_with_dashes',
		'description' => 'Current order URL example: <pre style="display: inline;">' . get_bloginfo( 'url' ) . '/' . SPC()->get_option( 'endpoint_gallery' ) . '/gallery-slug/<strong>' . SPC()->get_option( 'endpoint_store', 'store' ) . '</strong></pre>',
		'required'    => true,
		'default'     => 'store',
	);

	$pages_fields['3000'] = array(
		'id'            => 'endpoints',
		'name'          => __( 'Account Endpoints', 'sunshine-photo-cart' ),
		'description'   => __( 'Endpoints are appended to your page URLs to handle specific actions on the account page.', 'sunshine-photo-cart' ),
		'type'          => 'header',
		'documentation' => 'https://www.sunshinephotocart.com/docs/how-to-customize-the-url-for-galleries/',
	);
	$pages_fields['3100'] = array(
		'id'       => 'account_orders_endpoint',
		'name'     => __( 'Orders', 'sunshine-photo-cart' ),
		'type'     => 'text',
		'callback' => 'sanitize_title_with_dashes',
		'required' => true,
		'default'  => 'my-orders',
	);
	$pages_fields['3150'] = array(
		'id'       => 'account_view_order_endpoint',
		'name'     => __( 'View Order', 'sunshine-photo-cart' ),
		'type'     => 'text',
		'callback' => 'sanitize_title_with_dashes',
		'required' => true,
		'default'  => 'view-order',
	);
	$pages_fields['3200'] = array(
		'id'       => 'account_addresses_endpoint',
		'name'     => __( 'Addresses', 'sunshine-photo-cart' ),
		'type'     => 'text',
		'callback' => 'sanitize_title_with_dashes',
		'required' => true,
		'default'  => 'my-addresses',
	);
	$pages_fields['3250'] = array(
		'id'       => 'account_galleries_endpoint',
		'name'     => __( 'Galleries', 'sunshine-photo-cart' ),
		'type'     => 'text',
		'callback' => 'sanitize_title_with_dashes',
		'required' => true,
		'default'  => 'my-galleries',
	);
	$pages_fields['3300'] = array(
		'id'       => 'account_edit_endpoint',
		'name'     => __( 'Account Details', 'sunshine-photo-cart' ),
		'type'     => 'text',
		'callback' => 'sanitize_title_with_dashes',
		'required' => true,
		'default'  => 'my-details',
	);
	$pages_fields['3400'] = array(
		'id'       => 'account_login_endpoint',
		'name'     => __( 'Login', 'sunshine-photo-cart' ),
		'type'     => 'text',
		'callback' => 'sanitize_title_with_dashes',
		'required' => true,
		'default'  => 'login',
	);
	$pages_fields['3500'] = array(
		'id'       => 'account_reset_password_endpoint',
		'name'     => __( 'Reset Password', 'sunshine-photo-cart' ),
		'type'     => 'text',
		'callback' => 'sanitize_title_with_dashes',
		'required' => true,
		'default'  => 'reset-password',
	);
	$pages_fields['3600'] = array(
		'id'       => 'account_logout_endpoint',
		'name'     => __( 'Logout', 'sunshine-photo-cart' ),
		'type'     => 'text',
		'callback' => 'sanitize_title_with_dashes',
		'required' => true,
		'default'  => 'logout',
	);

	$page_fields = apply_filters( 'sunshine_options_pages', $pages_fields );
	if ( ! empty( $page_fields ) ) {
		ksort( $page_fields );
	}
	$settings[] = array(
		'id'     => 'pages',
		'title'  => __( 'Pages & URLs', 'sunshine-photo-cart' ),
		'fields' => $page_fields,
		'icon'   => SUNSHINE_PHOTO_CART_PATH . 'assets/images/icons/pages.svg',
	);

	/* GALLERIES */
	$galleries_fields         = array();
	$galleries_fields['1000'] = array(
		'id'            => 'admin_options',
		'name'          => __( 'Administration Options', 'sunshine-photo-cart' ),
		'type'          => 'header',
		'documentation' => 'https://www.sunshinephotocart.com/docs/gallery-administration-options/',
	);
	$galleries_fields['1100'] = array(
		'name'        => __( 'Remove images', 'sunshine-photo-cart' ),
		'id'          => 'delete_images',
		'type'        => 'checkbox',
		'description' => __( 'When a gallery is permanently deleted, remove all associated attachments and image files from the servers', 'sunshine-photo-cart' ),
	);
	$galleries_fields['1200'] = array(
		'name'        => __( 'Delete FTP folder', 'sunshine-photo-cart' ),
		'id'          => 'delete_images_folder',
		'type'        => 'checkbox',
		'description' => __( 'This will remove the folder and images added via FTP, if this was used to create the gallery', 'sunshine-photo-cart' ),
	);
	$galleries_fields['1300'] = array(
		'name'        => __( 'Show images in Media Library', 'sunshine-photo-cart' ),
		'id'          => 'show_media_library',
		'type'        => 'checkbox',
		'description' => __( 'By default Sunshine hides images uploaded to Sunshine galleries in the Media Library, enabling this option will show them instead. Use at your own risk.', 'sunshine-photo-cart' ),
	);
	$galleries_fields['1350'] = array(
		'name'          => __( 'Delay image processing', 'sunshine-photo-cart' ),
		'id'            => 'delay_image_processing',
		'type'          => 'checkbox',
		'description'   => __( 'When enabled, intermediate image sizes (thumbnails and large) and watermarks will be generated in the background after upload, allowing faster initial uploads. Images may not be immediately available in all sizes until processing completes.', 'sunshine-photo-cart' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/delayed-image-processing/',
	);
	/*
	Can't get it to work with regenerate, so no go yet
	$galleries_fields['1400'] = array(
	'name'        => __( 'Create unique file names for images', 'sunshine-photo-cart' ),
	'id'          => 'unique_filenames',
	'type'        => 'checkbox',
	'description' => __( 'For an added layer of protection, Sunshine can create unique file names for all images in your galleries to help prevent people from guessing other image sizes they should not have access to.', 'sunshine-photo-cart' ),
	);
	*/

	$galleries_fields['1500'] = array(
		'id'   => 'gallery_features',
		'name' => __( 'Features', 'sunshine-photo-cart' ),
		'type' => 'header',
	);
	$galleries_fields['1510'] = array(
		'name'          => __( 'Image Theft Prevention', 'sunshine-photo-cart' ),
		'id'            => 'disable_right_click',
		'type'          => 'checkbox',
		'description'   => __( 'Enabling this option will disable the right click menu and also not allow images to be dragged/dropped to the desktop. NOT a 100% effective method, but should stop most people. Is NOT used for admin users.', 'sunshine-photo-cart' ),
		'options'       => array( 1 ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/image-protection/',
	);
	$galleries_fields['1520'] = array(
		'name'          => __( 'Proofing Only', 'sunshine-photo-cart' ),
		'id'            => 'proofing',
		'type'          => 'checkbox',
		'description'   => __( 'This will remove all aspects of purchasing abilities throughout the site, leaving just image viewing and adding to favorites', 'sunshine-photo-cart' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/proofing-only-mode/',
		'options'       => array( 1 ),
	);
	$galleries_fields['1530'] = array(
		'name'        => __( 'Allow product comments', 'sunshine-photo-cart' ),
		'id'          => 'product_comments',
		'type'        => 'checkbox',
		'description' => __( 'Allow users to enter comments when adding an item to cart', 'sunshine-photo-cart' ),
		'options'     => array( 1 ),
	);
	$galleries_fields['1540'] = array(
		'name'          => __( 'Allow Store', 'sunshine-photo-cart' ),
		'id'            => 'allow_store',
		'type'          => 'checkbox',
		'description'   => __( 'Galleries come with a Store where products are shown first and customers add images to cart.', 'sunshine-photo-cart' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/gallery-store-view/',
		'options'       => array( 1 ),
	);
	$galleries_fields['1550'] = array(
		'name'        => __( 'Allow Gallery Sharing', 'sunshine-photo-cart' ),
		'id'          => 'allow_gallery_sharing',
		'type'        => 'checkbox',
		'description' => __( 'Allow users to share galleries', 'sunshine-photo-cart' ),
	);
	$galleries_fields['1560'] = array(
		'name'        => __( 'Allow Image Sharing', 'sunshine-photo-cart' ),
		'id'          => 'allow_image_sharing',
		'type'        => 'checkbox',
		'description' => __( 'Allow users to share images within a gallery and favorites', 'sunshine-photo-cart' ),
	);
	$galleries_fields['1570'] = array(
		'name'          => __( 'Use Secure File Names', 'sunshine-photo-cart' ),
		'id'            => 'use_secure_file_names',
		'type'          => 'checkbox',
		'description'   => __( 'Add random strings to image file names for added security', 'sunshine-photo-cart' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/image-protection/',
	);
	$galleries_fields['1580'] = array(
		'name'        => __( 'Enable Open Graph', 'sunshine-photo-cart' ),
		'id'          => 'open_graph',
		'type'        => 'checkbox',
		'description' => __( 'Add Open Graph meta tags to the site for galleries and image pages (your SEO plugin may already be doing this)', 'sunshine-photo-cart' ),
	);
	$galleries_fields['1590'] = array(
		'name'        => __( 'Hide galleries from search engines', 'sunshine-photo-cart' ),
		'id'          => 'hide_galleries',
		'type'        => 'checkbox',
		'description' => __( 'Enabling this option will attempt to block search engine bots from crawling and indexing galleries and images', 'sunshine-photo-cart' ),
	);

	$galleries_fields['2000'] = array(
		'id'   => 'display_options',
		'name' => __( 'Display Options', 'sunshine-photo-cart' ),
		'type' => 'header',
	);
	$galleries_fields['2150'] = array(
		'name'          => __( 'Gallery Order', 'sunshine-photo-cart' ),
		'id'            => 'gallery_order',
		'type'          => 'select',
		'documentation' => 'https://www.sunshinephotocart.com/docs/ordering-your-galleries/',
		'options'       => array(
			'menu_order'   => __( 'Custom ordering', 'sunshine-photo-cart' ),
			'date_new_old' => __( 'Gallery Creation Date (New to Old)', 'sunshine-photo-cart' ),
			'date_old_new' => __( 'Gallery Creation Date (Old to New)', 'sunshine-photo-cart' ),
			'title'        => __( 'Alphabetical', 'sunshine-photo-cart' ),
		),
	);
	$galleries_fields['2200'] = array(
		'name'        => __( 'Image Order', 'sunshine-photo-cart' ),
		'id'          => 'image_order',
		'type'        => 'select',
		'options'     => array(
			'menu_order'          => __( 'Custom ordering', 'sunshine-photo-cart' ),
			'shoot_order_reverse' => __( 'Order images shot (New to Old)', 'sunshine-photo-cart' ),
			'shoot_order'         => __( 'Order images shot (Old to New)', 'sunshine-photo-cart' ),
			'date_new_old'        => __( 'Image Upload Date (New to Old)', 'sunshine-photo-cart' ),
			'date_old_new'        => __( 'Image Upload Date (Old to New)', 'sunshine-photo-cart' ),
			'title'               => __( 'Alphabetical', 'sunshine-photo-cart' ),
		),
		'description' => __( 'If selecting "Order images shot" images must have proper metadata', 'sunshine-photo-cart' ) . ', <a href="https://www.sunshinephotocart.com/docs/image-ordering/#timestamp" target="_blank">' . __( 'learn more', 'sunshine-photo-cart' ) . '</a>',
	);

	$galleries_fields['2210'] = array(
		'name'          => __( 'Fallback Featured Image', 'sunshine-photo-cart' ),
		'id'            => 'fallback_featured_image',
		'type'          => 'image',
		'description'   => __( 'Upload a custom thumbnail image to be used when no specific featured image is set', 'sunshine-photo-cart' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/default-featured-images/',
	);
	$galleries_fields['2215'] = array(
		'name'          => __( 'Password Protected Featured Image', 'sunshine-photo-cart' ),
		'id'            => 'password_featured_image',
		'type'          => 'image',
		'description'   => __( 'Upload a custom thumbnail image to be used when a gallery is password protected', 'sunshine-photo-cart' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/default-featured-images/',
	);

	// Galleries Display Settings
	$galleries_fields['2220'] = array(
		'name' => '<strong>' . __( 'Galleries List Display', 'sunshine-photo-cart' ) . '</strong>',
		'id'   => 'galleries_display_header',
		'type' => 'header',
	);
	$galleries_fields['2225'] = array(
		'name'        => __( 'Galleries Layout', 'sunshine-photo-cart' ),
		'id'          => 'gallery_layout',
		'type'        => 'radio_images',
		'description' => __( 'Cropping option not recommended for justified or masonry', 'sunshine-photo-cart' ),
		'options'     => array(
			'standard'  => array(
				'label' => __( 'Standard', 'sunshine-photo-cart' ),
				'image' => SUNSHINE_PHOTO_CART_URL . 'assets/images/standard.svg',
			),
			'justified' => array(
				'label' => __( 'Justified', 'sunshine-photo-cart' ),
				'image' => SUNSHINE_PHOTO_CART_URL . 'assets/images/justified.svg',
			),
			'masonry'   => array(
				'label' => __( 'Masonry', 'sunshine-photo-cart' ),
				'image' => SUNSHINE_PHOTO_CART_URL . 'assets/images/masonry.svg',
			),
		),
	);
	$galleries_fields['2230'] = array(
		'name'    => __( 'Galleries Columns', 'sunshine-photo-cart' ),
		'id'      => 'galleries_columns',
		'type'    => 'select',
		'options' => array(
			2 => 2,
			3 => 3,
			4 => 4,
			5 => 5,
		),
	);
	$galleries_fields['2235'] = array(
		'name'          => __( 'Galleries Per Page', 'sunshine-photo-cart' ),
		'id'            => 'galleries_per_page',
		'type'          => 'number',
		'css'           => 'width: 50px;',
		'description'   => __( 'Set to 0 for unlimited galleries (no pagination)', 'sunshine-photo-cart' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/gallery-pagination/',
	);
	$galleries_fields['2240'] = array(
		'name'    => __( 'Galleries Pagination Style', 'sunshine-photo-cart' ),
		'id'      => 'galleries_pagination',
		'type'    => 'radio',
		'options' => array(
			'numbers' => __( 'Numbered pagination', 'sunshine-photo-cart' ),
			'button'  => __( 'Load more button', 'sunshine-photo-cart' ),
			'auto'    => __( 'Automatic infinite scrolling', 'sunshine-photo-cart' ),
		),
	);

	// Images Display Settings
	$galleries_fields['2245'] = array(
		'name' => '<strong>' . __( 'Images Display', 'sunshine-photo-cart' ) . '</strong>',
		'id'   => 'images_display_header',
		'type' => 'header',
	);
	$galleries_fields['2250'] = array(
		'name'        => __( 'Images Layout', 'sunshine-photo-cart' ),
		'id'          => 'image_layout',
		'type'        => 'radio_images',
		'description' => __( 'Cropping option not recommended for justified or masonry', 'sunshine-photo-cart' ),
		'options'     => array(
			'standard'  => array(
				'label' => __( 'Standard', 'sunshine-photo-cart' ),
				'image' => SUNSHINE_PHOTO_CART_URL . 'assets/images/standard.svg',
			),
			'justified' => array(
				'label' => __( 'Justified', 'sunshine-photo-cart' ),
				'image' => SUNSHINE_PHOTO_CART_URL . 'assets/images/justified.svg',
			),
			'masonry'   => array(
				'label' => __( 'Masonry', 'sunshine-photo-cart' ),
				'image' => SUNSHINE_PHOTO_CART_URL . 'assets/images/masonry.svg',
			),
		),
	);
	$galleries_fields['2255'] = array(
		'name'    => __( 'Images Columns', 'sunshine-photo-cart' ),
		'id'      => 'columns',
		'type'    => 'select',
		'options' => array(
			2 => 2,
			3 => 3,
			4 => 4,
			5 => 5,
		),
	);
	$galleries_fields['2260'] = array(
		'name' => __( 'Images Per Page', 'sunshine-photo-cart' ),
		'id'   => 'per_page',
		'type' => 'number',
		'css'  => 'width: 50px;',
	);
	$galleries_fields['2265'] = array(
		'name'    => __( 'Images Pagination Style', 'sunshine-photo-cart' ),
		'id'      => 'pagination',
		'type'    => 'radio',
		'options' => array(
			'numbers' => __( 'Numbered pagination', 'sunshine-photo-cart' ),
			'button'  => __( 'Load more button', 'sunshine-photo-cart' ),
			'auto'    => __( 'Automatic infinite scrolling', 'sunshine-photo-cart' ),
		),
	);
	$galleries_fields['2270'] = array(
		'name'        => __( 'Thumbnail Content', 'sunshine-photo-cart' ),
		'id'          => 'show_image_data',
		'description' => __( 'What to show below image thumbnails', 'sunshine-photo-cart' ),
		'type'        => 'select',
		'options'     => array(
			''         => __( 'Nothing', 'sunshine-photo-cart' ),
			'filename' => __( 'Filename', 'sunshine-photo-cart' ),
			'title'    => __( 'Title (Images MUST have EXIF field "Title")', 'sunshine-photo-cart' ),
			'caption'  => __( 'Caption', 'sunshine-photo-cart' ),
		),
	);
	$galleries_fields['2275'] = array(
		'name'        => __( 'Single Image Caption', 'sunshine-photo-cart' ),
		'id'          => 'show_caption_single',
		'type'        => 'checkbox',
		'description' => __( 'Show the image caption (from EXIF metadata) below the image on single image pages', 'sunshine-photo-cart' ),
	);

	$galleries_fields['3000'] = array(
		'id'            => 'images',
		'name'          => __( 'Image Sizing', 'sunshine-photo-cart' ),
		'type'          => 'header',
		/* translators: %s is the URL to help documentation */
		'description'   => sprintf( __( 'Making changes to your image sizes does not immediately affect your galleries and you will need to regenerate all images. <a href="%s" target="_blank">Please see this help article</a>', 'sunshine-photo-cart' ), 'http://www.sunshinephotocart.com/docs/thumbnails-not-cropping/' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/how-to-change-the-thumbnail-image-size/',
	);
	$galleries_fields['3100'] = array(
		'name' => __( 'Thumbnail Size', 'sunshine-photo-cart' ),
		'id'   => 'thumbnail_size',
		'type' => 'dimensions',
	);
	$galleries_fields['3200'] = array(
		'name'        => __( 'Thumbnail Crop', 'sunshine-photo-cart' ),
		'id'          => 'thumbnail_crop',
		'description' => __( 'Crop image to this exact size. Note: Cropped images not recommended for Justified or Masonry layouts', 'sunshine-photo-cart' ),
		'tip'         => __( 'Should images be cropped to the exact dimensions of your thumbnail width / height', 'sunshine-photo-cart' ),
		'type'        => 'checkbox',
		'options'     => array( 1 ),
	);
	$galleries_fields['3300'] = array(
		'name'         => __( 'Large Size', 'sunshine-photo-cart' ),
		'id'           => 'large_size',
		'type'         => 'dimensions',
		'requirements' => array(
			array(
				'type'     => '>',
				'field'    => 'thumbnail_size',
				'property' => 'each',
				'message'  => __( 'Large size must be larger than thumbnail size (both width and height)', 'sunshine-photo-cart' ),
			),
		),
	);
	$galleries_fields['3400'] = array(
		'name'        => __( 'Image Quality', 'sunshine-photo-cart' ),
		'id'          => 'image_quality',
		'description' => __( 'Quality that generated images are saved from 1-100. The higher the quality the larger the file size. If left empty, the default is 82.', 'sunshine-photo-cart' ),
		'type'        => 'number',
		'min'         => 1,
		'max'         => 100,
	);

	$galleries_fields['4000'] = array(
		'id'            => 'watermark',
		'name'          => __( 'Watermark', 'sunshine-photo-cart' ),
		'type'          => 'header',
		'documentation' => 'https://www.sunshinephotocart.com/docs/add-watermark-image/',
	);
	$galleries_fields['4100'] = array(
		'name'        => __( 'Watermark Image', 'sunshine-photo-cart' ),
		'id'          => 'watermark_image',
		'type'        => 'image',
		'description' => __( 'Must be a PNG image, ideally with transparency', 'sunshine-photo-cart' ),
		'media_type'  => 'image/png',
	);
	$galleries_fields['4200'] = array(
		'name'        => __( 'Max Size', 'sunshine-photo-cart' ),
		'id'          => 'watermark_max_size',
		'type'        => 'number',
		'max'         => 100,
		'min'         => 1,
		'description' => __( 'From 1-100, a max percent size of the image', 'sunshine-photo-cart' ),
	);
	$galleries_fields['4300'] = array(
		'name'    => __( 'Position', 'sunshine-photo-cart' ),
		'id'      => 'watermark_position',
		'type'    => 'select',
		'options' => array(
			'center'      => __( 'Center', 'sunshine-photo-cart' ),
			'repeat'      => __( 'Repeat', 'sunshine-photo-cart' ),
			'topleft'     => __( 'Top Left', 'sunshine-photo-cart' ),
			'topright'    => __( 'Top Right', 'sunshine-photo-cart' ),
			'bottomleft'  => __( 'Bottom Left', 'sunshine-photo-cart' ),
			'bottomright' => __( 'Bottom Right', 'sunshine-photo-cart' ),
		),
	);
	$galleries_fields['4400'] = array(
		'name' => __( 'Margin from edge', 'sunshine-photo-cart' ),
		'id'   => 'watermark_margin',
		'type' => 'number',
		'min'  => 0,
	);
	$galleries_fields['4500'] = array(
		'name' => __( 'Watermark Thumbnails', 'sunshine-photo-cart' ),
		'id'   => 'watermark_thumbnail',
		'type' => 'checkbox',
	);

	$galleries_fields['5000'] = array(
		'id'            => 'favorites_selections',
		'name'          => __( 'Favorites / Selections', 'sunshine-photo-cart' ),
		'type'          => 'header',
		'documentation' => 'https://www.sunshinephotocart.com/docs/favorites-selection-tray/',
	);
	$galleries_fields['5050'] = array(
		'name'        => __( 'Enable Favorites', 'sunshine-photo-cart' ),
		'id'          => 'enable_favorites',
		'type'        => 'checkbox',
		'description' => __( 'Allow users to save images to their favorites', 'sunshine-photo-cart' ),
	);
	$galleries_fields['5100'] = array(
		'name'          => __( 'Enable Selection Tray', 'sunshine-photo-cart' ),
		'id'            => 'enable_selection_tray',
		'type'          => 'checkbox',
		'description'   => __( 'Show users their selected favorites in a persistent popup while viewing galleries', 'sunshine-photo-cart' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/favorites-selection-tray/',
	);
	$galleries_fields['5200'] = array(
		'name'        => __( 'Position', 'sunshine-photo-cart' ),
		'id'          => 'selection_tray_position',
		'type'        => 'radio',
		'options'     => array(
			'bottom_right' => __( 'Float bottom right', 'sunshine-photo-cart' ),
			'bottom_left'  => __( 'Float bottom left', 'sunshine-photo-cart' ),
		),
		'conditions'  => array(
			array(
				'compare' => '==',
				'value'   => '1',
				'field'   => 'enable_selection_tray',
				'action'  => 'show',
			),
		),
		'description' => __( 'Choose where the selection tray appears on the page', 'sunshine-photo-cart' ),
	);

	$galleries_fields = apply_filters( 'sunshine_options_galleries', $galleries_fields );
	if ( ! empty( $galleries_fields ) ) {
		ksort( $galleries_fields );
	}
	$settings[] = array(
		'id'     => 'galleries',
		'title'  => __( 'Galleries', 'sunshine-photo-cart' ),
		'fields' => $galleries_fields,
		'icon'   => SUNSHINE_PHOTO_CART_PATH . 'assets/images/icons/galleries.svg',
	);

	$tax_fields         = array();
	$tax_fields['1000'] = array(
		'id'            => 'taxes_enabled',
		'name'          => __( 'Enable Taxes', 'sunshine-photo-cart' ),
		'type'          => 'checkbox',
		'documentation' => 'https://www.sunshinephotocart.com/docs/setting-up-taxes/',
	);
	$tax_fields['1100'] = array(
		'id'          => 'tax_rates',
		'name'        => __( 'Tax Rates', 'sunshine-photo-cart' ),
		'type'        => 'taxes',
		'description' => __( 'Be as specific as you need. Order for most priority. You can include multiple zip/postal codes with commas.', 'sunshine-photo-cart' ),
		'conditions'  => array(
			array(
				'compare' => '==',
				'value'   => '1',
				'field'   => 'taxes_enabled',
				'action'  => 'show',
			),
		),
	);
	$tax_fields['1200'] = array(
		'id'          => 'tax_default_location',
		'name'        => __( 'Default customer location', 'sunshine-photo-cart' ),
		'description' => __( 'If customer does not have address entered yet, what location is used to determine the default location', 'sunshine-photo-cart' ),
		'type'        => 'select',
		'options'     => array(
			'none'  => __( 'No location by default', 'sunshine-photo-cart' ),
			'store' => __( 'My business location', 'sunshine-photo-cart' ),
		),
		'conditions'  => array(
			array(
				'compare' => '==',
				'value'   => '1',
				'field'   => 'taxes_enabled',
				'action'  => 'show',
			),
		),
	);
	/*
	$tax_fields['1300'] = array(
	'id'          => 'tax_basis',
	'name'        => __( 'Calculate tax based on', 'sunshine-photo-cart' ),
	'description' => __( 'Which address is used to determine if tax is calculated', 'sunshine-photo-cart' ),
	'type'        => 'select',
	'options'     => array(
	'shipping' => __( 'Shipping Address', 'sunshine-photo-cart' ),
	'billing'  => __( 'Billing Address', 'sunshine-photo-cart' ),
	),
	'conditions'  => array(
	array(
		'compare' => '==',
		'value'   => '1',
		'field'   => 'taxes_enabled',
		'action'  => 'show',
	),
	),
	);
	*/
	$tax_fields['1400'] = array(
		'name'    => __( 'Display prices', 'sunshine-photo-cart' ),
		'id'      => 'display_price',
		'type'    => 'radio',
		'options' => array(
			'without_tax' => 'Excluding tax',
			'with_tax'    => 'Including tax',
		),
	);
	$tax_fields['1500'] = array(
		'name'    => __( 'Prices entered with tax', 'sunshine-photo-cart' ),
		'id'      => 'price_has_tax',
		'type'    => 'radio',
		'options' => array(
			'no'  => 'No, prices do not have tax included',
			'yes' => 'Yes, prices do have tax included',
		),
	);
	$tax_fields['1600'] = array(
		'name'        => __( 'Price display suffix', 'sunshine-photo-cart' ),
		'id'          => 'price_suffix',
		'type'        => 'text',
		'description' => __( 'This shows after a price', 'sunshine-photo-cart' ),
	);

	$tax_fields = apply_filters( 'sunshine_options_taxes', $tax_fields );
	if ( ! empty( $tax_fields ) ) {
		ksort( $tax_fields );
	}
	$settings[] = array(
		'id'     => 'taxes',
		'title'  => __( 'Taxes', 'sunshine-photo-cart' ),
		'fields' => $tax_fields,
		'icon'   => SUNSHINE_PHOTO_CART_PATH . 'assets/images/icons/taxes.svg',
	);

	$checkout_fields        = array();
	$checkout_fields['100'] = array(
		'name'          => __( 'Distraction Free Checkout', 'sunshine-photo-cart' ),
		'id'            => 'checkout_standalone',
		'type'          => 'checkbox',
		'description'   => __( 'Remove site header/footer and let user focus only on the checkout experience', 'sunshine-photo-cart' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/distraction-free-checkout/',
	);

	$checkout_fields['1100'] = array(
		'name'        => __( 'Allow Guest Checkout', 'sunshine-photo-cart' ),
		'id'          => 'allow_guest_checkout',
		'type'        => 'checkbox',
		'description' => __( 'Allow users to checkout as a guest (do not require a user account)', 'sunshine-photo-cart' ),
	);
	$checkout_fields['1150'] = array(
		'name'        => __( 'Always collect an address', 'sunshine-photo-cart' ),
		'id'          => 'require_address',
		'type'        => 'checkbox',
		'description' => __( 'There could be instances where no address is needed at checkout, this option forces an address field even in those situations', 'sunshine-photo-cart' ),
	);

	$checkout_fields['1200'] = array(
		'name'        => __( 'Allowed Countries', 'sunshine-photo-cart' ),
		'description' => __( 'Which countries users can select at checkout. If empty, all countries are allowed.', 'sunshine-photo-cart' ),
		'id'          => 'allowed_countries',
		'type'        => 'select',
		'select2'     => true,
		'multiple'    => true,
		'options'     => SPC()->countries->get_countries(),
	);
	$checkout_fields['1300'] = array(
		'name'          => __( 'Google Maps API Key', 'sunshine-photo-cart' ),
		'id'            => 'google_maps_api_key',
		'type'          => 'text',
		/* translators: %s is the URL to help documentation */
		'description'   => sprintf( __( 'Enter a Google API key to enable address autocomplete at checkout, <a href="%s" target="_blank">learn more here</a>', 'sunshine-photo-cart' ), 'https://www.sunshinephotocart.com/docs/address-autocomplete' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/google-maps-api-key/',
	);

	$checkout_fields['1500'] = array(
		'id'          => 'orders',
		'name'        => __( 'Orders', 'sunshine-photo-cart' ),
		'type'        => 'header',
		'description' => '',
	);
	$checkout_fields['1501'] = array(
		'name'          => __( 'Next Order Number', 'sunshine-photo-cart' ),
		'id'            => 'next_order_number',
		'type'          => 'number',
		'description'   => __( 'Leave blank to use default order numbering, otherwise set the next order number to be used', 'sunshine-photo-cart' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/order-numbers/',
	);
	$checkout_fields['1502'] = array(
		'name'          => __( 'Disable Invoice', 'sunshine-photo-cart' ),
		'id'            => 'disable_invoice',
		'type'          => 'checkbox',
		'description'   => __( 'Disable invoices with orders', 'sunshine-photo-cart' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/order-invoices/',
	);

	$checkout_fields['2000'] = array(
		'id'          => 'display_fields',
		'name'        => __( 'Display Fields', 'sunshine-photo-cart' ),
		'type'        => 'header',
		'description' => '',
	);
	$checkout_fields['2300'] = array(
		'name'    => __( 'Other Fields', 'sunshine-photo-cart' ),
		'id'      => 'general_fields',
		'type'    => 'checkbox_multi',
		'options' => array(
			'phone' => __( 'Phone', 'sunshine-photo-cart' ),
			'notes' => __( 'Notes', 'sunshine-photo-cart' ),
			'vat'   => __( 'VAT Number', 'sunshine-photo-cart' ),
		),
	);
	$checkout_fields['2301'] = array(
		'name'    => __( 'Required Other Fields', 'sunshine-photo-cart' ),
		'id'      => 'required_general_fields',
		'type'    => 'checkbox_multi',
		'options' => array(
			'phone' => __( 'Phone', 'sunshine-photo-cart' ),
			'notes' => __( 'Notes', 'sunshine-photo-cart' ),
			'vat'   => __( 'VAT Number', 'sunshine-photo-cart' ),
		),
	);
	$checkout_fields['2302'] = array(
		'name' => __( 'VAT Label', 'sunshine-photo-cart' ),
		'id'   => 'vat_label',
		'type' => 'text',
	);

	$checkout_fields = apply_filters( 'sunshine_options_checkout', $checkout_fields );
	if ( ! empty( $checkout_fields ) ) {
		ksort( $checkout_fields );
	}
	$settings[] = array(
		'id'     => 'checkout',
		'title'  => __( 'Checkout', 'sunshine-photo-cart' ),
		'fields' => $checkout_fields,
		'icon'   => SUNSHINE_PHOTO_CART_PATH . 'assets/images/icons/checkout.svg',
	);

	/* PAYMENTS */

	$payment_fields = array();

	$payment_methods = SPC()->payment_methods->get_payment_methods();
	if ( empty( $_GET['payment_method'] ) ) {
		$payment_fields['1'] = array(
			'name'    => '', // __( 'Available payment methods', 'sunshine-photo-cart' ),
			'id'      => 'payment_methods_wrapper',
			'type'    => 'payment_methods',
			'options' => $payment_methods,
		);
	}

	if ( ! empty( $payment_methods ) ) {
		foreach ( $payment_methods as $id => $payment_method ) {
			$payment_method_fields = apply_filters( 'sunshine_options_payment_method_' . $id, array() );
			if ( ! empty( $payment_method_fields ) ) {
				foreach ( $payment_method_fields as &$field ) {
					if ( empty( $_GET['payment_method'] ) || ( isset( $_GET['payment_method'] ) && $_GET['payment_method'] != $id ) ) {
						$field['class'] = ( ! empty( $field['class'] ) ) ? $field['class'] . ' hidden' : 'hidden';
					}
				}
			}
			$payment_fields = array_merge( $payment_fields, $payment_method_fields );
		}
	}

	$payment_fields = apply_filters( 'sunshine_options_payment_methods', $payment_fields );
	if ( ! empty( $payment_fields ) ) {
		ksort( $payment_fields );
	}
	$settings[] = array(
		'id'     => 'payment_methods',
		'title'  => __( 'Payments', 'sunshine-photo-cart' ),
		'fields' => $payment_fields,
		'icon'   => SUNSHINE_PHOTO_CART_PATH . 'assets/images/icons/payment.svg',
	);

	/* SHIPPING */

	$shipping_fields = array();

	$available_shipping_methods = sunshine_get_available_shipping_methods();

	if ( empty( $_GET['instance_id'] ) ) {
		$shipping_fields['1'] = array(
			'name'    => '', // __( 'Available shipping methods', 'sunshine-photo-cart' ),
			'id'      => 'shipping_methods_wrapper',
			'type'    => 'shipping_methods',
			'options' => $available_shipping_methods,
		);
	}

	$shipping_fields = apply_filters( 'sunshine_options_shipping', $shipping_fields );

	if ( ! empty( $available_shipping_methods ) && is_array( $available_shipping_methods ) ) {
		foreach ( $available_shipping_methods as $instance_id => $shipping_method ) {
			$shipping_method_fields = apply_filters( 'sunshine_options_shipping_method_' . $shipping_method['id'], array(), $instance_id );
			if ( ! empty( $shipping_method_fields ) ) {
				foreach ( $shipping_method_fields as &$field ) {
					if ( empty( $_GET['instance_id'] ) || ( isset( $_GET['instance_id'] ) && $_GET['instance_id'] != $instance_id ) ) {
						$field['class'] = ( ! empty( $field['class'] ) ) ? $field['class'] . ' hidden' : 'hidden';
					}
				}
			}
			$shipping_fields = array_merge( $shipping_fields, $shipping_method_fields );
		}
	}

	if ( ! empty( $shipping_fields ) ) {
		ksort( $shipping_fields );
	}
	$settings[] = array(
		'id'     => 'shipping_methods',
		'title'  => __( 'Delivery & Shipping', 'sunshine-photo-cart' ),
		'fields' => $shipping_fields,
		'icon'   => SUNSHINE_PHOTO_CART_PATH . 'assets/images/icons/shipping.svg',
	);

	$design_fields    = array();
	$design_fields[0] = array(
		'name'        => __( 'Design Elements', 'sunshine-photo-cart' ),
		'type'        => 'header',
		'description' => '',
	);
	$design_fields[1] = array(
		'name'          => __( 'Pages Theme', 'sunshine-photo-cart' ),
		'id'            => 'theme',
		'type'          => 'select',
		'options'       => array(
			'theme'   => __( 'My WordPress Theme', 'sunshine-photo-cart' ),
			'classic' => __( 'Classic', 'sunshine-photo-cart' ),
			'cover'   => __( 'Cover', 'sunshine-photo-cart' ),
		),
		'description'   => __( 'What should be used for your general theme when viewing Sunshine pages', 'sunshine-photo-cart' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/how-to-customize-the-look-and-feel-of-sunshine-theme/',
	);
	$design_fields[2] = array(
		'name'        => __( 'Gallery Theme', 'sunshine-photo-cart' ),
		'id'          => 'theme_gallery',
		'type'        => 'select',
		'options'     => array(
			'theme'   => __( 'My WordPress Theme', 'sunshine-photo-cart' ),
			'classic' => __( 'Classic', 'sunshine-photo-cart' ),
			'cover'   => __( 'Cover', 'sunshine-photo-cart' ),
		),
		'description' => __( 'Allow galleries to use a different theme layout from the main pages', 'sunshine-photo-cart' ),
	);

	$design_fields[20] = array(
		'name'        => __( 'Alternate integration', 'sunshine-photo-cart' ),
		'id'          => 'page_builder',
		'type'        => 'checkbox',
		'description' => __( 'Sunshine tries to blend into your theme seamlessly. If you are seeing weird layout issues, try this as an alternate method if integrating.', 'sunshine-photo-cart' ),
	);
	$design_fields[50] = array(
		'name' => __( 'Logo', 'sunshine-photo-cart' ),
		'id'   => 'logo',
		'type' => 'image',
	);

	$design_fields[100] = array(
		'id'         => 'classic',
		'name'       => __( 'Classic Theme Options', 'sunshine-photo-cart' ),
		'type'       => 'header',
		'conditions' => array(
			array(
				'compare' => '==',
				'value'   => 'classic',
				'field'   => 'theme',
				'action'  => 'show',
			),
			array(
				'compare' => '==',
				'value'   => 'classic',
				'field'   => 'theme_gallery',
				'action'  => 'show',
			),
		),
	);
	$design_fields[101] = array(
		'id'         => 'classic_menu_background_color',
		'name'       => __( 'Menu Background', 'sunshine-photo-cart' ),
		'type'       => 'color',
		'conditions' => array(
			array(
				'compare' => '==',
				'value'   => 'classic',
				'field'   => 'theme',
				'action'  => 'show',
			),
			array(
				'compare' => '==',
				'value'   => 'classic',
				'field'   => 'theme_gallery',
				'action'  => 'show',
			),
		),
	);
	$design_fields[102] = array(
		'id'         => 'classic_menu_links_color',
		'name'       => __( 'Menu Links', 'sunshine-photo-cart' ),
		'type'       => 'color',
		'conditions' => array(
			array(
				'compare' => '==',
				'value'   => 'classic',
				'field'   => 'theme',
				'action'  => 'show',
			),
			array(
				'compare' => '==',
				'value'   => 'classic',
				'field'   => 'theme_gallery',
				'action'  => 'show',
			),
		),
	);
	$design_fields[103] = array(
		'id'         => 'classic_main_background_color',
		'name'       => __( 'Main Background', 'sunshine-photo-cart' ),
		'type'       => 'color',
		'conditions' => array(
			array(
				'compare' => '==',
				'value'   => 'classic',
				'field'   => 'theme',
				'action'  => 'show',
			),
			array(
				'compare' => '==',
				'value'   => 'classic',
				'field'   => 'theme_gallery',
				'action'  => 'show',
			),
		),
	);
	$design_fields[104] = array(
		'id'         => 'classic_main_text_color',
		'name'       => __( 'Main Text', 'sunshine-photo-cart' ),
		'type'       => 'color',
		'conditions' => array(
			array(
				'compare' => '==',
				'value'   => 'classic',
				'field'   => 'theme',
				'action'  => 'show',
			),
			array(
				'compare' => '==',
				'value'   => 'classic',
				'field'   => 'theme_gallery',
				'action'  => 'show',
			),
		),
	);
	$design_fields[105] = array(
		'id'         => 'classic_main_links_color',
		'name'       => __( 'Main Links', 'sunshine-photo-cart' ),
		'type'       => 'color',
		'conditions' => array(
			array(
				'compare' => '==',
				'value'   => 'classic',
				'field'   => 'theme',
				'action'  => 'show',
			),
			array(
				'compare' => '==',
				'value'   => 'classic',
				'field'   => 'theme_gallery',
				'action'  => 'show',
			),
		),
	);
	$design_fields[106] = array(
		'id'         => 'classic_password',
		'name'       => __( 'Gallery Password Box', 'sunshine-photo-cart' ),
		'type'       => 'checkbox',
		'conditions' => array(
			array(
				'compare' => '==',
				'value'   => 'classic',
				'field'   => 'theme',
				'action'  => 'show',
			),
		),
	);
	$design_fields[107] = array(
		'id'         => 'classic_search',
		'name'       => __( 'Gallery Search Box', 'sunshine-photo-cart' ),
		'type'       => 'checkbox',
		'conditions' => array(
			array(
				'compare' => '==',
				'value'   => 'classic',
				'field'   => 'theme',
				'action'  => 'show',
			),
		),
	);

	$design_fields[2000] = array(
		'name'        => __( 'Miscellaneous Elements', 'sunshine-photo-cart' ),
		'type'        => 'header',
		'description' => '',
	);
	$design_fields[2100] = array(
		'name'        => __( 'Auto-include Sunshine main menu', 'sunshine-photo-cart' ),
		'id'            => 'main_menu',
		'type'          => 'checkbox',
		'description'   => __( 'Automatically have the Sunshine Main Menu appear above the Sunshine content', 'sunshine-photo-cart' ),
		'documentation' => 'https://www.sunshinephotocart.com/docs/sunshine-main-menu/',
	);
	$design_fields[2200] = array(
		'name'        => __( 'Hide link to main galleries page', 'sunshine-photo-cart' ),
		'id'          => 'hide_galleries_link',
		'type'        => 'checkbox',
		'description' => __( 'Hide the link to your main galleries page in any Sunshine menus. Helpful if you want users to stick to just a single gallery.', 'sunshine-photo-cart' ),
	);

	$design_fields[3000] = array(
		'name'        => __( 'Customizations', 'sunshine-photo-cart' ),
		'type'        => 'header',
		'description' => '',
	);
	$design_fields[3100] = array(
		'name'          => __( 'Custom CSS for frontend', 'sunshine-photo-cart' ),
		'id'            => 'css',
		'type'          => 'textarea',
		'documentation' => 'https://www.sunshinephotocart.com/docs/custom-css/',
	);
	$design_fields[3150] = array(
		'name' => __( 'Custom CSS for emails', 'sunshine-photo-cart' ),
		'id'   => 'css_email',
		'type' => 'textarea',
	);
	$design_fields[3200] = array(
		'name'        => __( 'Before Sunshine', 'sunshine-photo-cart' ),
		'id'          => 'before',
		'type'        => 'wysiwyg',
		'description' => __( 'Shown before every Sunshine associated page', 'sunshine-photo-cart' ),
	);
	$design_fields[3300] = array(
		'name'        => __( 'After Sunshine', 'sunshine-photo-cart' ),
		'id'          => 'after',
		'type'        => 'wysiwyg',
		'description' => __( 'Shown after every Sunshine associated page', 'sunshine-photo-cart' ),
	);

	$design_fields[4000] = array(
		'name'          => __( 'Invoices', 'sunshine-photo-cart' ),
		'type'          => 'header',
		'description'   => '',
		'documentation' => 'https://www.sunshinephotocart.com/docs/order-invoices/',
	);

	$design_fields[4100] = array(
		'name'        => __( 'Logo', 'sunshine-photo-cart' ),
		'id'          => 'invoice_logo',
		'type'        => 'image',
		'description' => __( 'Will be shown on a white background', 'sunshine-photo-cart' ),
	);
	$design_fields[4200] = array(
		'name'        => __( 'Extra Content', 'sunshine-photo-cart' ),
		'id'          => 'invoice',
		'type'        => 'wysiwyg',
		'description' => __( 'Extra content to be shown on all invoices', 'sunshine-photo-cart' ),
	);

	$design_fields = apply_filters( 'sunshine_options_design', $design_fields );
	if ( ! empty( $design_fields ) ) {
		ksort( $design_fields );
	}
	$settings[] = array(
		'id'     => 'display',
		'title'  => __( 'Design', 'sunshine-photo-cart' ),
		'fields' => $design_fields,
	);

	/* Email Settings */
	$email_fields = array();

	$email_field_class = '';
	if ( ! empty( $_GET['email'] ) ) {
		$email_field_class = 'hidden';
	}

	$email_fields[1]  = array(
		'name'        => __( 'Email Notifications', 'sunshine-photo-cart' ),
		'class'       => $email_field_class,
		'type'        => 'header',
		'description' => __( 'Email notifications sent from Sunshine Photo Cart are listed below. Click on an email to configure it.', 'sunshine-photo-cart' ) . ' Learn more about how to <a href="https://www.sunshinephotocart.com/docs/not-receiving-emails/"target="_blank">improve your email deliverability</a> and make sure you and your customers always receive emails.',
	);
	$email_fields[2]  = array(
		'name'  => '',
		'id'    => 'emails',
		'type'  => 'emails',
		'class' => $email_field_class,
	);
	$email_fields[10] = array(
		'name'        => __( 'Email Sender', 'sunshine-photo-cart' ),
		'class'       => $email_field_class,
		'type'        => 'header',
		'description' => '',
	);
	$email_fields[20] = array(
		'name'        => __( 'From Name', 'sunshine-photo-cart' ),
		'description' => __( 'When emails are sent to customers, what name should they come from', 'sunshine-photo-cart' ),
		'id'          => 'from_name',
		'type'        => 'text',
		'class'       => $email_field_class,
	);
	$email_fields[30] = array(
		'name'        => __( 'From Email', 'sunshine-photo-cart' ),
		'description' => __( 'When emails are sent to customers, what email address should they come from', 'sunshine-photo-cart' ),
		'id'          => 'from_email',
		'type'        => 'text',
		'class'       => $email_field_class,
	);
	$email_fields[40] = array(
		'name'        => __( 'Email Template', 'sunshine-photo-cart' ),
		'class'       => $email_field_class,
		'type'        => 'header',
		'description' => '',
	);
	$email_fields[50] = array(
		'name'        => __( 'Email logo', 'sunshine-photo-cart' ),
		'description' => __( 'Logo to use in header of email', 'sunshine-photo-cart' ),
		'id'          => 'email_logo',
		'type'        => 'image',
		'class'       => $email_field_class,
	);
	$email_fields[60] = array(
		'name'        => __( 'Signature', 'sunshine-photo-cart' ),
		'description' => __( 'Included in footer of every email', 'sunshine-photo-cart' ),
		'id'          => 'email_signature',
		'type'        => 'wysiwyg',
		'class'       => $email_field_class,
	);

	if ( ! empty( $email_fields ) ) {
		ksort( $email_fields );
	}

	$emails = SPC()->emails->get_emails();
	if ( ! empty( $emails ) ) {
		foreach ( $emails as $id => $email ) {
			$email_item_fields = apply_filters( 'sunshine_options_email_' . $id, array() );
			if ( ! empty( $email_item_fields ) ) {
				foreach ( $email_item_fields as &$field ) {
					if ( empty( $_GET['email'] ) || ( isset( $_GET['email'] ) && $_GET['email'] != $id ) ) {
						$field['class'] = ( ! empty( $field['class'] ) ) ? $field['class'] . ' hidden' : 'hidden';
					}
				}
			}
			$email_fields = array_merge( $email_fields, $email_item_fields );
		}
	}

	$email_fields = apply_filters( 'sunshine_options_email', $email_fields );
	$settings[]   = array(
		'id'     => 'email',
		'title'  => __( 'Email', 'sunshine-photo-cart' ),
		'fields' => $email_fields,
		'icon'   => SUNSHINE_PHOTO_CART_PATH . 'assets/images/icons/email.svg',
	);

	$integration_fields          = array();
	$integration_fields['10000'] = array(
		'name'        => 'Light Blue',
		'type'        => 'header',
		'description' => 'Send order data from Sunshine into your Light Blue account <a href="https://www.sunshinephotocart.com/addon/light-blue/?utm" target="_blank">Learn more</a>',
		'class'       => 'promo--lightblue',
	);
	$integration_fields['20000'] = array(
		'name'        => 'MailChimp',
		'type'        => 'header',
		'description' => 'Send any collected email addresses and order data to your MailChimp account <a href="https://www.sunshinephotocart.com/addon/mailchimp/?utm" target="_blank">Learn more</a>',
		'class'       => 'promo--mailchimp',
	);
	$integration_fields['30000'] = array(
		'name'        => 'Campaign Monitor',
		'type'        => 'header',
		'description' => 'Send any collected email addresses and order data to your Campaign Monitor account <a href="https://www.sunshinephotocart.com/addon/campaign-monitor/?utm" target="_blank">Learn more</a>',
		'class'       => 'promo--campaign-monitor',
	);

	$integration_fields = apply_filters( 'sunshine_options_integrations', $integration_fields );
	if ( ! empty( $integration_fields ) ) {
		ksort( $integration_fields );
	}
	$settings[] = array(
		'id'     => 'integrations',
		'title'  => __( 'Integrations', 'sunshine-photo-cart' ),
		'fields' => $integration_fields,
	);

	$settings = apply_filters( 'sunshine_options_extra', $settings );

	$license_fields = array();
	/*
	$license_fields[0] = array(
	'name'        => __( 'Bundle Plan', 'sunshine-photo-cart' ),
	'id'          => 'plan',
	'type'        => 'radio',
	'options'     => array(
		'pro' => __( 'Pro', 'sunshine-photo-cart' ),
		'plus' => __( 'Plus', 'sunshine-photo-cart' ),
		'basic' => __( 'Basic', 'sunshine-photo-cart' ),
	),
	);
	*/
	$license_fields = apply_filters( 'sunshine_options_licenses', $license_fields );
	if ( ! empty( $license_fields ) ) {
		ksort( $license_fields );
	}

	$description = '';
	if ( isset( $_GET['license_reminder'] ) ) {
		$description = '<div style="border: 1px solid #CCC; padding: 5px 20px;">' . __( 'Please enter your license key in order to enable your add-ons', 'sunshine-photo-cart' ) . '</div>';
	}
	$settings[] = array(
		'id'          => 'license',
		'title'       => __( 'Licenses', 'sunshine-photo-cart' ),
		'fields'      => $license_fields,
		'description' => $description,
	);

	/*
	$license_fields = array();
	$license_fields[1000] = array( 'name' => __( 'Sunshine Photo Cart License', 'sunshine-photo-cart' ), 'type' => 'header', 'description' => '' );
	$license_fields = apply_filters( 'sunshine_options_licenses_primary', $license_fields );
	$addon_license_options = apply_filters( 'sunshine_options_licenses', array() );
	if ( !empty( $addon_license_options ) ) {
	$license_fields[] = array( 'name' => __( 'Add-on Sunshine Licenses', 'sunshine-photo-cart' ), 'type' => 'header', 'description' => '' );
	$license_fields = array_merge( $license_fields, $addon_license_options );
	}

	$license_fields = apply_filters( 'sunshine_options_licenses', $license_fields );
	ksort( $license_fields );
	$settings[] = array(
	'id' => 'licenses',
	'title' => __( 'Licenses', 'sunshine-photo-cart' ),
	'fields' => $license_fields,
	'icon' => SUNSHINE_PHOTO_CART_PATH . 'assets/images/icons/licenses.svg'
	);
	*/

	/* PRIVACY */
	$privacy_fields = array();

	$privacy_fields['1000'] = array(
		'id'            => 'privacy_erasure_header',
		'name'          => __( 'Account Erasure Requests', 'sunshine-photo-cart' ),
		'type'          => 'header',
		'documentation' => 'https://www.sunshinephotocart.com/docs/privacy-data-retention/',
	);
	$privacy_fields['1100'] = array(
		'id'          => 'privacy_erasure_remove_order_data',
		'name'        => __( 'Remove personal data from orders', 'sunshine-photo-cart' ),
		'type'        => 'checkbox',
		'description' => __( 'When handling an account erasure request, should personal data within orders be removed? If disabled, order personal data will be retained.', 'sunshine-photo-cart' ),
	);

	$privacy_fields['2000'] = array(
		'id'   => 'privacy_bulk_header',
		'name' => __( 'Personal Data Removal', 'sunshine-photo-cart' ),
		'type' => 'header',
	);
	$privacy_fields['2100'] = array(
		'id'          => 'privacy_allow_bulk_remove',
		'name'        => __( 'Allow bulk removal of personal data', 'sunshine-photo-cart' ),
		'type'        => 'checkbox',
		'description' => __( 'Adds an option to the orders screen for removing personal data in bulk. Note that removing personal data cannot be undone.', 'sunshine-photo-cart' ),
	);

	$privacy_fields['3000'] = array(
		'id'          => 'privacy_retention_header',
		'name'        => __( 'Personal Data Retention', 'sunshine-photo-cart' ),
		'type'        => 'header',
		'description' => __( 'Choose how long to retain personal data when it\'s no longer needed for processing. Leave the following options blank to retain this data indefinitely.', 'sunshine-photo-cart' ),
	);
	$privacy_fields['3100'] = array(
		'id'   => 'privacy_retain_inactive_accounts',
		'name' => __( 'Retain inactive customer accounts', 'sunshine-photo-cart' ),
		'type' => 'duration',
	);
	$privacy_fields['3200'] = array(
		'id'   => 'privacy_retain_orders',
		'name' => __( 'Retain completed orders', 'sunshine-photo-cart' ),
		'type' => 'duration',
	);

	$privacy_fields = apply_filters( 'sunshine_options_privacy', $privacy_fields );
	if ( ! empty( $privacy_fields ) ) {
		ksort( $privacy_fields );
	}
	$settings[] = array(
		'id'     => 'privacy',
		'title'  => __( 'Privacy', 'sunshine-photo-cart' ),
		'fields' => $privacy_fields,
	);

	$settings = apply_filters( 'sunshine_options', $settings );

	$data_fields   = array();
	$data_fields[] = array(
		'id'   => 'tracking_allow',
		'name' => __( 'Allow Tracking', 'sunshine-photo-cart' ),
		'type' => 'checkbox',
	);
	$data_fields[] = array(
		'id'      => 'tracking_niches',
		'name'    => __( 'Types of Photography', 'sunshine-photo-cart' ),
		'type'    => 'checkbox_multi',
		'options' => array(
			'family'      => __( 'Family', 'sunshine-photo-cart' ),
			'newborn'     => __( 'Newborn', 'sunshine-photo-cart' ),
			'wedding'     => __( 'Wedding', 'sunshine-photo-cart' ),
			'school'      => __( 'School', 'sunshine-photo-cart' ),
			'Sports'      => __( 'Sports', 'sunshine-photo-cart' ),
			'portraits'   => __( 'Portraits', 'sunshine-photo-cart' ),
			'animals'     => __( 'Animals', 'sunshine-photo-cart' ),
			'events'      => __( 'Events', 'sunshine-photo-cart' ),
			'real-estate' => __( 'Real Estate', 'sunshine-photo-cart' ),
		),
	);

	$settings[] = array(
		'id'          => 'data',
		'title'       => __( 'Data', 'sunshine-photo-cart' ),
		'fields'      => $data_fields,
		'description' => __( 'This data can help provide useful information to Sunshine Photo Cart and help guide new features and growth strategies. Your participation is extremely appreciated!', 'sunshine-photo-cart' ),
	);

	return $settings;

}
