<?php
// Trying to make Sunshine compatible with other plugins in unique situations.

// Polylang — translate configured Sunshine page IDs to the current language so
// page detection, URL generation, and theme content rendering work on
// multilingual sites that create separate posts per translation.
if ( function_exists( 'pll_get_post' ) ) {

	add_filter( 'sunshine_get_page', 'sunshine_polylang_get_page', 10, 2 );
	function sunshine_polylang_get_page( $page_id, $page ) {
		$translated = pll_get_post( $page_id );
		return $translated ? $translated : $page_id;
	}

	add_filter( 'sunshine_get_page_for_post_id', 'sunshine_polylang_get_page_for_post_id', 10, 3 );
	function sunshine_polylang_get_page_for_post_id( $matched, $post_id, $pages ) {
		if ( $matched || ! function_exists( 'pll_get_post_translations' ) ) {
			return $matched;
		}
		foreach ( $pages as $configured_id ) {
			if ( empty( $configured_id ) ) {
				continue;
			}
			$translations = pll_get_post_translations( $configured_id );
			if ( is_array( $translations ) && in_array( (int) $post_id, array_map( 'intval', $translations ), true ) ) {
				return (int) $configured_id;
			}
		}
		return $matched;
	}

	add_filter( 'sunshine_page_translation_ids', 'sunshine_polylang_page_translation_ids', 10, 2 );
	function sunshine_polylang_page_translation_ids( $ids, $page_id ) {
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return $ids;
		}
		$translations = pll_get_post_translations( $page_id );
		if ( is_array( $translations ) ) {
			$ids = array_merge( $ids, array_map( 'intval', array_values( $translations ) ) );
		}
		return array_values( array_unique( $ids ) );
	}
}

// WPML — same idea as Polylang, using the wpml_object_id filter API.
if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {

	add_filter( 'sunshine_get_page', 'sunshine_wpml_get_page', 10, 2 );
	function sunshine_wpml_get_page( $page_id, $page ) {
		return apply_filters( 'wpml_object_id', $page_id, 'page', true );
	}

	add_filter( 'sunshine_get_page_for_post_id', 'sunshine_wpml_get_page_for_post_id', 10, 3 );
	function sunshine_wpml_get_page_for_post_id( $matched, $post_id, $pages ) {
		if ( $matched ) {
			return $matched;
		}
		$languages = apply_filters( 'wpml_active_languages', array() );
		if ( ! is_array( $languages ) ) {
			return $matched;
		}
		foreach ( $pages as $configured_id ) {
			if ( empty( $configured_id ) ) {
				continue;
			}
			foreach ( $languages as $lang ) {
				$code = is_array( $lang ) && isset( $lang['language_code'] ) ? $lang['language_code'] : null;
				if ( ! $code ) {
					continue;
				}
				$translated = apply_filters( 'wpml_object_id', $configured_id, 'page', false, $code );
				if ( $translated && (int) $translated === (int) $post_id ) {
					return (int) $configured_id;
				}
			}
		}
		return $matched;
	}

	add_filter( 'sunshine_page_translation_ids', 'sunshine_wpml_page_translation_ids', 10, 2 );
	function sunshine_wpml_page_translation_ids( $ids, $page_id ) {
		$languages = apply_filters( 'wpml_active_languages', array() );
		if ( ! is_array( $languages ) ) {
			return $ids;
		}
		foreach ( $languages as $lang ) {
			$code = is_array( $lang ) && isset( $lang['language_code'] ) ? $lang['language_code'] : null;
			if ( ! $code ) {
				continue;
			}
			$translated = apply_filters( 'wpml_object_id', $page_id, 'page', false, $code );
			if ( $translated ) {
				$ids[] = (int) $translated;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}

add_filter( 'jetpack_photon_skip_for_url', 'sunshine_photon_skip_for_url', 9, 4 );
function sunshine_photon_skip_for_url( $skip, $url, $args, $scheme ) {
	if ( str_contains( $url, 'uploads/sunshine' ) ) {
		SPC()->log( 'Skip URL for Jetpack photon for Sunshine image: ' . $url );
		return true;
	}
	return $skip;
}

add_filter( 'photon_validate_image_url', 'sunshine_photon_validate_image_url', 9, 3 );
function sunshine_photon_validate_image_url( $valid, $url, $parsed_url ) {
	if ( str_contains( $url, 'uploads/sunshine' ) ) {
		SPC()->log( 'Bypassing Jetpack photon image url validation for Sunshine image: ' . $url );
		return false;
	}
	return $valid;
}

add_filter( 'jetpack_photon_skip_image', 'sunshine_photon_skip_image', 9, 3 );
function sunshine_photon_skip_image( $valid, $url, $tag ) {
	if ( str_contains( $url, 'uploads/sunshine' ) ) {
		SPC()->log( 'Skip image for Jetpack photon for Sunshine image: ' . $url );
		return true;
	}
	return $valid;
}

// EWWW bypass optimizer if in a sunshine folder.
add_filter( 'ewww_image_optimizer_bypass', 'sunshine_ewww_image_optimizer_bypass', 10, 2 );
function sunshine_ewww_image_optimizer_bypass( $bypass, $file ) {
	if ( str_contains( $file, 'uploads/sunshine/' ) ) {
		SPC()->log( 'Bypassing EWWW optimizer for Sunshine image: ' . $file );
		return true;
	}
	return $bypass;
}

add_filter( 'ewww_image_optimizer_resize_dimensions', 'sunshine_ewww_image_optimizer_resize_dimensions', 10, 2 );
function sunshine_ewww_image_optimizer_resize_dimensions( $size, $file ) {
	if ( str_contains( $file, 'uploads/sunshine/' ) ) {
		SPC()->log( 'Bypassing EWWW optimizer resizing for Sunshine image: ' . $file );
		return array( 0, 0 );
	}
	return $size;
}

add_action( 'sunshine_after_image_process', 'sunshine_ewww_image_optimizer_editor_overwrite', 1 );
function sunshine_ewww_image_optimizer_editor_overwrite( $attachment_id ) {
	if ( ! defined( 'EWWWIO_EDITOR_OVERWRITE' ) ) {
		define( 'EWWWIO_EDITOR_OVERWRITE', true );
	}
}

// Prevent EWWW from doing background optimization for Sunshine images.
// EWWW's multi-stage background pipeline (background_media → background_image → background_attachment_update)
// creates timing issues with cloud storage offloading. Forcing synchronous processing means our existing
// bypass filters handle everything cleanly within the same request.
add_filter( 'ewww_image_optimizer_background_optimization', 'sunshine_ewww_disable_background_for_sunshine', 10 );
function sunshine_ewww_disable_background_for_sunshine( $defer ) {
	if ( ! empty( $GLOBALS['sunshine_current_upload_path'] ) && str_contains( $GLOBALS['sunshine_current_upload_path'], 'uploads/sunshine/' ) ) {
		return false;
	}
	return $defer;
}

// Imagify - bypass optimizer if in a sunshine folder.
add_filter( 'imagify_auto_optimize_attachment', 'sunshine_imagify_auto_optimize_attachment', 10, 2 );
function sunshine_imagify_auto_optimize_attachment( $optimize, $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	if ( $file && str_contains( $file, 'uploads/sunshine/' ) ) {
		SPC()->log( 'Bypassing Imagify optimizer for Sunshine image: ' . $file );
		return false;
	}
	return $optimize;
}

// ShortPixel - bypass optimizer if in a sunshine folder.
add_filter( 'shortpixel/media/uploadhook', 'sunshine_shortpixel_media_uploadhook', 10, 4 );
function sunshine_shortpixel_media_uploadhook( $handle, $media_item, $meta, $id ) {
	$file = get_attached_file( $id );
	if ( $file && str_contains( $file, 'uploads/sunshine/' ) ) {
		SPC()->log( 'Bypassing ShortPixel optimizer for Sunshine image: ' . $file );
		return false;
	}
	return $handle;
}

// Image Optimization (Elementor) - No bypass hooks available.
// This plugin uses wp_generate_attachment_metadata filter at priority 10.
// We hook into the same filter at priority 9 (before theirs) to remove their hook for sunshine images.
add_filter( 'wp_generate_attachment_metadata', 'sunshine_image_optimization_elementor_bypass', 9, 2 );
function sunshine_image_optimization_elementor_bypass( $metadata, $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	if ( $file && str_contains( $file, 'uploads/sunshine/' ) ) {
		// Remove Image Optimization (Elementor) hook for this upload.
		global $wp_filter;
		if ( isset( $wp_filter['wp_generate_attachment_metadata'] ) ) {
			foreach ( $wp_filter['wp_generate_attachment_metadata']->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $id => $callback ) {
					if ( is_array( $callback['function'] ) && is_object( $callback['function'][0] ) ) {
						$class_name = get_class( $callback['function'][0] );
						if ( str_contains( $class_name, 'Upload_Optimization' ) ) {
							unset( $wp_filter['wp_generate_attachment_metadata']->callbacks[ $priority ][ $id ] );
							SPC()->log( 'Bypassing Image Optimization (Elementor) for Sunshine image: ' . $file );
						}
					}
				}
			}
		}
	}
	return $metadata;
}
