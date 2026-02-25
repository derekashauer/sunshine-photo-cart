<?php
add_filter( 'sunshine_admin_meta_sunshine-product', 'sunshine_product_promos', 10 );
function sunshine_product_promos( $options ) {

	if ( SPC()->get_option( 'promos_hide' ) && SPC()->has_plan() ) {
		return $options;
	}

	if ( ! is_sunshine_addon_active( 'product-options' ) ) {
		$options['sunshine-product-options'][100] = array(
			'id'     => 'product-options',
			'name'   => __( 'Options', 'sunshine-photo-cart' ),
			'fields' => array(
				array(
					'id'          => 'options_promo',
					'type'        => 'promo',
					'url'         => 'https://www.sunshinephotocart.com/addon/product-options/',
					'description' => sunshine_get_template_html( 'admin/promo/product-options' ),
				),
			),
		);
	}

	if ( ! is_sunshine_addon_active( 'digital-downloads' ) ) {
		$options['sunshine-product-options'][450] = array(
			'id'     => 'download',
			'name'   => __( 'Download', 'sunshine-photo-cart' ),
			'fields' => array(
				array(
					'id'          => 'download_promo',
					'type'        => 'promo',
					'url'         => 'https://www.sunshinephotocart.com/addon/digital-downloads/',
					'description' => sunshine_get_template_html( 'admin/promo/digital-downloads' ),
				),
			),
		);
	}

	if ( ! is_sunshine_addon_active( 'multi-image-products' ) ) {
		$options['sunshine-product-options'][300] = array(
			'id'     => 'multi-image',
			'name'   => __( 'Multi-Image', 'sunshine-photo-cart' ),
			'fields' => array(
				array(
					'id'          => 'multi_image_promo',
					'type'        => 'promo',
					'url'         => 'https://www.sunshinephotocart.com/addon/multi-image-products/',
					'description' => sunshine_get_template_html( 'admin/promo/multi-image-products' ),
				),
			),
		);
	}

	if ( ! is_sunshine_addon_active( 'packages' ) ) {
		$options['sunshine-product-options'][500] = array(
			'id'     => 'package',
			'name'   => __( 'Package', 'sunshine-photo-cart' ),
			'fields' => array(
				array(
					'id'          => 'package_promo',
					'type'        => 'promo',
					'url'         => 'https://www.sunshinephotocart.com/addon/packages/',
					'description' => sunshine_get_template_html( 'admin/promo/packages' ),
				),
			),
		);
	}

	if ( ! is_sunshine_addon_active( 'quantity-discounts' ) ) {
		$options['sunshine-product-options'][550] = array(
			'id'     => 'quantity-discounts',
			'name'   => __( 'Quantity Discounts', 'sunshine-photo-cart' ),
			'fields' => array(
				array(
					'id'          => 'quantity_discounts_promo',
					'type'        => 'promo',
					'url'         => 'https://www.sunshinephotocart.com/addon/tiered-pricing/',
					'description' => sunshine_get_template_html( 'admin/promo/quantity-discounts' ),
				),
			),
		);
	}

	return $options;

}

add_filter( 'sunshine_admin_meta_sunshine-gallery', 'sunshine_gallery_promos', 10 );
function sunshine_gallery_promos( $options ) {

	if ( SPC()->get_option( 'promos_hide' ) && SPC()->has_plan() ) {
		return $options;
	}

	if ( ! is_sunshine_addon_active( 'bulk-galleries' ) ) {
		$options['sunshine-gallery-options'][0]['fields'][1000] = array(
			'id'          => 'volume_galleries_promo',
			'description' => sunshine_get_template_html( 'admin/promo/volume-galleries' ),
			'url'         => 'https://www.sunshinephotocart.com/addon/bulk-galleries/',
			'type'        => 'promo',
		);
	}

	if ( ! is_sunshine_addon_active( 'price-levels' ) ) {
		$options['sunshine-gallery-options'][2000]['fields'][] = array(
			'id'          => 'price_levels_promo',
			'description' => sunshine_get_template_html( 'admin/promo/price-levels' ),
			'url'         => 'https://www.sunshinephotocart.com/addon/price-levels/',
			'type'        => 'promo',
		);
	}

	if ( ! is_sunshine_addon_active( 'cloud-storage' ) ) {
		$options['sunshine-gallery-options'][0]['fields'][1001] = array(
			'id'          => 'cloud_storage_promo',
			'description' => sunshine_get_template_html( 'admin/promo/cloud-storage' ),
			'url'         => 'https://www.sunshinephotocart.com/addon/cloud-storage/',
			'type'        => 'promo',
		);
	}

	if ( ! is_sunshine_addon_active( 'session-fees' ) ) {
		$options['sunshine-gallery-options'][2000]['fields'][] = array(
			'id'          => 'session_fees_promo',
			'description' => sunshine_get_template_html( 'admin/promo/session-fees' ),
			'url'         => 'https://www.sunshinephotocart.com/addon/session-fees/',
			'type'        => 'promo',
		);
	}

	if ( ! is_sunshine_addon_active( 'messaging' ) ) {
		$options['sunshine-gallery-options'][0]['fields'][1002] = array(
			'id'          => 'messaging_promo',
			'description' => sunshine_get_template_html( 'admin/promo/messaging' ),
			'url'         => 'https://www.sunshinephotocart.com/addon/messaging/',
			'type'        => 'promo',
		);
	}

	return $options;

}

// Email settings promos.
add_filter( 'sunshine_options_email', 'sunshine_email_settings_promos', 100 );
function sunshine_email_settings_promos( $fields ) {

	if ( SPC()->get_option( 'promos_hide' ) && SPC()->has_plan() ) {
		return $fields;
	}

	if ( ! is_sunshine_addon_active( 'automated-email-marketing' ) ) {
		$fields['9000'] = array(
			'id'          => 'automated_email_promo',
			'type'        => 'promo',
			'url'         => 'https://www.sunshinephotocart.com/addon/automated-email-marketing/',
			'description' => sunshine_get_template_html( 'admin/promo/automated-email-marketing' ),
		);
	}

	return $fields;

}

// Checkout settings promos.
add_filter( 'sunshine_options_checkout', 'sunshine_checkout_settings_promos', 100 );
function sunshine_checkout_settings_promos( $fields ) {

	if ( SPC()->get_option( 'promos_hide' ) && SPC()->has_plan() ) {
		return $fields;
	}

	if ( ! is_sunshine_addon_active( 'discounts' ) ) {
		$fields['9000'] = array(
			'id'          => 'discounts_promo',
			'type'        => 'promo',
			'url'         => 'https://www.sunshinephotocart.com/addon/discount-codes/',
			'description' => sunshine_get_template_html( 'admin/promo/discounts' ),
		);
	}

	if ( ! is_sunshine_addon_active( 'gift-cards' ) ) {
		$fields['9001'] = array(
			'id'          => 'gift_cards_promo',
			'type'        => 'promo',
			'url'         => 'https://www.sunshinephotocart.com/addon/gift-cards/',
			'description' => sunshine_get_template_html( 'admin/promo/gift-cards' ),
		);
	}

	return $fields;

}

// Shipping settings promos.
add_filter( 'sunshine_options_shipping', 'sunshine_shipping_settings_promos', 100 );
function sunshine_shipping_settings_promos( $fields ) {

	if ( SPC()->get_option( 'promos_hide' ) && SPC()->has_plan() ) {
		return $fields;
	}

	if ( ! is_sunshine_addon_active( 'advanced-shipping' ) ) {
		$fields['9000'] = array(
			'id'          => 'advanced_shipping_promo',
			'type'        => 'promo',
			'url'         => 'https://www.sunshinephotocart.com/addon/advanced-shipping/',
			'description' => sunshine_get_template_html( 'admin/promo/advanced-shipping' ),
		);
	}

	return $fields;

}

// Galleries settings promos.
add_filter( 'sunshine_options_galleries', 'sunshine_galleries_settings_promos', 100 );
function sunshine_galleries_settings_promos( $fields ) {

	if ( SPC()->get_option( 'promos_hide' ) && SPC()->has_plan() ) {
		return $fields;
	}

	if ( ! is_sunshine_addon_active( 'lightbox' ) ) {
		$fields['9000'] = array(
			'id'          => 'lightbox_promo',
			'type'        => 'promo',
			'url'         => 'https://www.sunshinephotocart.com/addon/lightbox/',
			'description' => sunshine_get_template_html( 'admin/promo/lightbox' ),
		);
	}

	return $fields;

}

// Payment settings promos.
add_filter( 'sunshine_options_payment_methods', 'sunshine_payment_settings_promos', 100 );
function sunshine_payment_settings_promos( $fields ) {

	if ( SPC()->get_option( 'promos_hide' ) && SPC()->has_plan() ) {
		return $fields;
	}

	$fields['9000'] = array(
		'id'          => 'payment_gateways_promo',
		'type'        => 'promo',
		'url'         => 'https://www.sunshinephotocart.com/payment-gateways/',
		'description' => sunshine_get_template_html( 'admin/promo/payment-gateways' ),
	);

	return $fields;

}

// Cloud Storage settings tab promo.
// The Cloud Storage addon unhooks this and adds its own settings tab.
add_filter( 'sunshine_options_extra', 'sunshine_cloud_storage_promo_tab', 5 );
function sunshine_cloud_storage_promo_tab( $options ) {

	if ( SPC()->get_option( 'promos_hide' ) && SPC()->has_plan() ) {
		return $options;
	}

	$options[] = array(
		'id'     => 'cloud_storage',
		'title'  => __( 'Cloud Storage', 'sunshine-photo-cart' ),
		'fields' => array(
			array(
				'id'          => 'cloud_storage_promo',
				'type'        => 'promo',
				'url'         => 'https://www.sunshinephotocart.com/addon/cloud-storage/',
				'description' => sunshine_get_template_html( 'admin/promo/cloud-storage-page' ),
			),
		),
	);

	return $options;

}
