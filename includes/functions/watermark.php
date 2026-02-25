<?php
function sunshine_watermark_image( $attachment_id, $metadata = array(), $passed_image_size = '' ) {
	$attachment = get_post( $attachment_id );

	if ( wp_attachment_is( 'image', $attachment_id ) ) {

		$watermark_image     = get_attached_file( SPC()->get_option( 'watermark_image' ) );
		$watermark_file_type = wp_check_filetype( $watermark_image );

		if ( file_exists( $watermark_image ) && $watermark_file_type['ext'] == 'png' ) {

			SPC()->log( 'Watermark image found: ' . $watermark_image );

			$image = get_attached_file( $attachment_id );
			if ( ! empty( $passed_image_size ) ) {
				$image_size = $passed_image_size;
			}
			if ( empty( $image_size ) ) {
				$image_size = apply_filters( 'sunshine_image_size', 'sunshine-large' );
			}
			if ( empty( $metadata ) ) {
				$metadata = wp_get_attachment_metadata( $attachment_id );
			}
			$image_basename = basename( $image );
			$image_path     = str_replace( $image_basename, '', $image );
			if ( $image_size != 'full' && ! empty( $image ) && ! empty( $metadata ) && ! empty( $metadata['sizes'][ $image_size ]['file'] ) ) {
				$image = $image_path . $metadata['sizes'][ $image_size ]['file'];
			}

			if ( ! file_exists( $image ) ) {
				SPC()->log( 'File does not exist during watermarking: ' . $image . ' (looking for size: ' . $image_size . ')' );
				return;
			}

			$watermark = imagecreatefrompng( $watermark_image );

			// Detect image type and use appropriate function
			$image_file_type = wp_check_filetype( $image );
			$new_image       = false;

			if ( $image_file_type['ext'] == 'jpg' || $image_file_type['ext'] == 'jpeg' ) {
				$new_image = imagecreatefromjpeg( $image );
			} elseif ( $image_file_type['ext'] == 'png' ) {
				$new_image = imagecreatefrompng( $image );
			} elseif ( $image_file_type['ext'] == 'gif' ) {
				$new_image = imagecreatefromgif( $image );
			} elseif ( $image_file_type['ext'] == 'webp' && function_exists( 'imagecreatefromwebp' ) ) {
				$new_image = imagecreatefromwebp( $image );
			}

			// Check if watermark and new image were created successfully
			if ( $watermark === false || $new_image === false ) {
				SPC()->log( 'Failed to create image resources for watermarking: ' . $image );
				return;
			}

			$margin           = ( SPC()->get_option( 'watermark_margin' ) != '' ) ? SPC()->get_option( 'watermark_margin' ) : 30;
			$watermark_width  = imagesx( $watermark );
			$watermark_height = imagesy( $watermark );
			$new_image_width  = imagesx( $new_image );
			$new_image_height = imagesy( $new_image );

			$resize_watermark = false;
			$watermark_ratio  = $watermark_width / $watermark_height;

			// Make watermark size no larger than image being applied to
			if ( $watermark_width > $new_image_width ) {
				$resize_watermark     = true;
				$new_watermark_width  = $new_image_width;
				$new_watermark_height = $new_watermark_width / $watermark_ratio;
			}

			$max_size = SPC()->get_option( 'watermark_max_size' );
			if ( ! empty( $max_size ) && $max_size > 0 ) {
				$max_watermark_size_percent = intval( $max_size ) / 100;
				$new_watermark_width        = $watermark_width;
				$new_watermark_height       = $watermark_height;
				if ( ( $new_image_width * $max_watermark_size_percent ) < $new_watermark_width ) {
					$resize_watermark     = true;
					$new_watermark_width  = $new_image_width * $max_watermark_size_percent;
					$new_watermark_height = $new_watermark_width / $watermark_ratio;
				}
			}

			if ( $resize_watermark ) {
				$new_watermark_width  = round( $new_watermark_width );
				$new_watermark_height = round( $new_watermark_height );
				$new_watermark        = imagecreatetruecolor( $new_watermark_width, $new_watermark_height );
				imagealphablending( $new_watermark, false );
				imagesavealpha( $new_watermark, true );
				imagecopyresampled( $new_watermark, $watermark, 0, 0, 0, 0, $new_watermark_width, $new_watermark_height, $watermark_width, $watermark_height );
				$watermark        = $new_watermark;
				$watermark_width  = $new_watermark_width;
				$watermark_height = $new_watermark_height;
			}

			$position = SPC()->get_option( 'watermark_position' );
			if ( $position == 'topleft' ) {
				$x_pos = $margin;
				$y_pos = $margin;
			} elseif ( $position == 'topright' ) {
				$x_pos = $new_image_width - $watermark_width - $margin;
				$y_pos = $margin;
			} elseif ( $position == 'bottomleft' ) {
				$x_pos = $margin;
				$y_pos = $new_image_height - $watermark_height - $margin;
			} elseif ( $position == 'bottomright' ) {
				$x_pos = $new_image_width - $watermark_width - $margin;
				$y_pos = $new_image_height - $watermark_height - $margin;
			} else {
				$x_pos = ( $new_image_width / 2 ) - ( $watermark_width / 2 );
				$y_pos = ( $new_image_height / 2 ) - ( $watermark_height / 2 );
			}

			if ( $position == 'repeat' ) {
				imagesettile( $new_image, $watermark );
				imagefilledrectangle( $new_image, 0, 0, $new_image_width, $new_image_height, IMG_COLOR_TILED );
			} else {
				imagecopy( $new_image, $watermark, (int) $x_pos, (int) $y_pos, 0, 0, (int) $watermark_width, (int) $watermark_height );
			}

			$result = false;
			if ( $image_file_type['ext'] == 'jpg' || $image_file_type['ext'] == 'jpeg' ) {
				$result = imagejpeg( $new_image, $image, 100 );
			} elseif ( $image_file_type['ext'] == 'png' ) {
				$result = imagepng( $new_image, $image, 0 );
			} elseif ( $image_file_type['ext'] == 'gif' ) {
				$result = imagegif( $new_image, $image );
			} elseif ( $image_file_type['ext'] == 'webp' && function_exists( 'imagewebp' ) ) {
				$result = imagewebp( $new_image, $image, 100 );
			}

			SPC()->log( 'Watermarked image: ' . $image );

			// Watermark the thumbnail if needed. Resizing down the large image which was already watermarked.
			if ( SPC()->get_option( 'watermark_thumbnail' ) && empty( $passed_image_size ) ) {
				$image_editor = wp_get_image_editor( $image );
				if ( is_wp_error( $image_editor ) ) {
					return;
				}
				$image_editor->resize( sunshine_get_thumbnail_dimension( 'w' ), sunshine_get_thumbnail_dimension( 'h' ), SPC()->get_option( 'thumbnail_crop' ) );
				$thumb_path = '';
				if ( isset( $metadata['sizes']['sunshine-thumbnail']['file'] ) ) {
					$thumb_path = $image_path . $metadata['sizes']['sunshine-thumbnail']['file'];
				}
				$image_editor->save( $thumb_path );
				SPC()->log( 'Watermarking thumbnail: ' . $thumb_path );
			}

			imagedestroy( $new_image );
			imagedestroy( $watermark );
			unset( $image, $watermark, $new_image, $new_watermark ); // Unset variables

		}
	}
}

// Add the watermark
add_action( 'sunshine_after_image_process', 'sunshine_watermark_media_upload', 10, 3 );
function sunshine_watermark_media_upload( $attachment_id, $file_path, $watermark = true ) {
	if ( ! SPC()->get_option( 'watermark_image' ) || $watermark === 0 || $watermark === '0' || $watermark === false ) {
		SPC()->log( 'Not watermarking ' . $attachment_id );
		return;
	}
	sunshine_watermark_image( $attachment_id );
}
