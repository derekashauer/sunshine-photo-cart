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

			// Gather watermark options.
			$quality = SPC()->get_option( 'image_quality' );
			if ( empty( $quality ) ) {
				$quality = 82;
			}

			$options = array(
				'position' => SPC()->get_option( 'watermark_position' ),
				'margin'   => ( SPC()->get_option( 'watermark_margin' ) != '' ) ? (int) SPC()->get_option( 'watermark_margin' ) : 30,
				'max_size' => SPC()->get_option( 'watermark_max_size' ),
				'quality'  => (int) $quality,
			);

			// Use Imagick when available and the file is local (not a stream wrapper like S3).
			// Imagick uses its own file I/O and cannot read PHP stream wrapper URLs.
			$use_imagick = class_exists( 'Imagick' )
				&& class_exists( 'WP_Image_Editor_Imagick' )
				&& WP_Image_Editor_Imagick::test()
				&& false === strpos( $image, '://' );

			if ( $use_imagick ) {
				$result = sunshine_apply_watermark_imagick( $image, $watermark_image, $options );
			} else {
				$result = sunshine_apply_watermark_gd( $image, $watermark_image, $options );
			}

			if ( ! $result ) {
				SPC()->log( 'Watermark failed for: ' . $image );
				return;
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

		}
	}
}

/**
 * Apply watermark using Imagick.
 *
 * Handles EXIF orientation automatically via autoOrient(), uses native
 * compositeImage() for proper alpha blending, and respects quality settings.
 */
function sunshine_apply_watermark_imagick( $image_path, $watermark_path, $options ) {
	try {
		$image = new Imagick( $image_path );

		// Fix EXIF orientation by physically rotating pixels.
		$image->autoOrient();

		$watermark = new Imagick( $watermark_path );

		$image_width  = $image->getImageWidth();
		$image_height = $image->getImageHeight();
		$wm_width     = $watermark->getImageWidth();
		$wm_height    = $watermark->getImageHeight();

		// Resize watermark if needed.
		$watermark = sunshine_resize_watermark_imagick( $watermark, $wm_width, $wm_height, $image_width, $options['max_size'] );
		$wm_width  = $watermark->getImageWidth();
		$wm_height = $watermark->getImageHeight();

		// Calculate position and apply.
		$position = $options['position'];
		$margin   = $options['margin'];

		if ( $position == 'repeat' ) {
			// Tile the watermark across the entire image.
			for ( $y = 0; $y < $image_height; $y += $wm_height ) {
				for ( $x = 0; $x < $image_width; $x += $wm_width ) {
					$image->compositeImage( $watermark, Imagick::COMPOSITE_OVER, $x, $y );
				}
			}
		} else {
			list( $x_pos, $y_pos ) = sunshine_calculate_watermark_position( $position, $margin, $image_width, $image_height, $wm_width, $wm_height );
			$image->compositeImage( $watermark, Imagick::COMPOSITE_OVER, (int) $x_pos, (int) $y_pos );
		}

		// Set quality from plugin settings.
		$image->setImageCompressionQuality( $options['quality'] );

		// Normalize EXIF orientation since pixels are now correct.
		$image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );

		// Save.
		$image->writeImage( $image_path );

		$watermark->destroy();
		$image->destroy();

		return true;
	} catch ( ImagickException $e ) {
		SPC()->log( 'Imagick watermark error: ' . $e->getMessage() );
		return false;
	}
}

/**
 * Resize watermark using Imagick if it exceeds max size or image width.
 */
function sunshine_resize_watermark_imagick( $watermark, $wm_width, $wm_height, $image_width, $max_size ) {
	$new_width  = $wm_width;
	$new_height = $wm_height;
	$resize     = false;

	// Don't let watermark be wider than the image.
	if ( $wm_width > $image_width ) {
		$resize     = true;
		$ratio      = $wm_width / $wm_height;
		$new_width  = $image_width;
		$new_height = (int) round( $new_width / $ratio );
	}

	// Apply max size percentage.
	if ( ! empty( $max_size ) && $max_size > 0 ) {
		$max_pixel_width = $image_width * ( intval( $max_size ) / 100 );
		if ( $max_pixel_width < $new_width ) {
			$resize     = true;
			$ratio      = $wm_width / $wm_height;
			$new_width  = (int) round( $max_pixel_width );
			$new_height = (int) round( $new_width / $ratio );
		}
	}

	if ( $resize ) {
		$watermark->scaleImage( $new_width, $new_height );
	}

	return $watermark;
}

/**
 * Apply watermark using GD (fallback when Imagick is not available).
 *
 * Includes explicit EXIF orientation handling since GD does not read EXIF.
 */
function sunshine_apply_watermark_gd( $image_path, $watermark_path, $options ) {
	$watermark = imagecreatefrompng( $watermark_path );

	// Detect image type and use appropriate function.
	$image_file_type = wp_check_filetype( $image_path );
	$new_image       = false;

	if ( $image_file_type['ext'] == 'jpg' || $image_file_type['ext'] == 'jpeg' ) {
		$new_image = imagecreatefromjpeg( $image_path );
	} elseif ( $image_file_type['ext'] == 'png' ) {
		$new_image = imagecreatefrompng( $image_path );
	} elseif ( $image_file_type['ext'] == 'gif' ) {
		$new_image = imagecreatefromgif( $image_path );
	} elseif ( $image_file_type['ext'] == 'webp' && function_exists( 'imagecreatefromwebp' ) ) {
		$new_image = imagecreatefromwebp( $image_path );
	}

	if ( $watermark === false || $new_image === false ) {
		SPC()->log( 'Failed to create image resources for watermarking: ' . $image_path );
		return false;
	}

	// Fix EXIF orientation since GD ignores it.
	$new_image = sunshine_fix_gd_orientation( $new_image, $image_path );

	$wm_width     = imagesx( $watermark );
	$wm_height    = imagesy( $watermark );
	$image_width  = imagesx( $new_image );
	$image_height = imagesy( $new_image );

	// Resize watermark if needed.
	$watermark = sunshine_resize_watermark_gd( $watermark, $wm_width, $wm_height, $image_width, $options['max_size'] );
	$wm_width  = imagesx( $watermark );
	$wm_height = imagesy( $watermark );

	// Calculate position and apply.
	$position = $options['position'];
	$margin   = $options['margin'];

	imagealphablending( $new_image, true );

	if ( $position == 'repeat' ) {
		imagesettile( $new_image, $watermark );
		imagefilledrectangle( $new_image, 0, 0, $image_width, $image_height, IMG_COLOR_TILED );
	} else {
		list( $x_pos, $y_pos ) = sunshine_calculate_watermark_position( $position, $margin, $image_width, $image_height, $wm_width, $wm_height );
		imagecopy( $new_image, $watermark, (int) $x_pos, (int) $y_pos, 0, 0, (int) $wm_width, (int) $wm_height );
	}

	// Save with proper quality from plugin settings.
	$result = false;
	if ( $image_file_type['ext'] == 'jpg' || $image_file_type['ext'] == 'jpeg' ) {
		$result = imagejpeg( $new_image, $image_path, $options['quality'] );
	} elseif ( $image_file_type['ext'] == 'png' ) {
		$result = imagepng( $new_image, $image_path, 9 );
	} elseif ( $image_file_type['ext'] == 'gif' ) {
		$result = imagegif( $new_image, $image_path );
	} elseif ( $image_file_type['ext'] == 'webp' && function_exists( 'imagewebp' ) ) {
		$result = imagewebp( $new_image, $image_path, $options['quality'] );
	}

	imagedestroy( $new_image );
	imagedestroy( $watermark );

	return $result;
}

/**
 * Resize watermark using GD if it exceeds max size or image width.
 */
function sunshine_resize_watermark_gd( $watermark, $wm_width, $wm_height, $image_width, $max_size ) {
	$new_width  = $wm_width;
	$new_height = $wm_height;
	$resize     = false;

	// Don't let watermark be wider than the image.
	if ( $wm_width > $image_width ) {
		$resize     = true;
		$ratio      = $wm_width / $wm_height;
		$new_width  = $image_width;
		$new_height = (int) round( $new_width / $ratio );
	}

	// Apply max size percentage.
	if ( ! empty( $max_size ) && $max_size > 0 ) {
		$max_pixel_width = $image_width * ( intval( $max_size ) / 100 );
		if ( $max_pixel_width < $new_width ) {
			$resize     = true;
			$ratio      = $wm_width / $wm_height;
			$new_width  = (int) round( $max_pixel_width );
			$new_height = (int) round( $new_width / $ratio );
		}
	}

	if ( $resize ) {
		$new_watermark = imagecreatetruecolor( $new_width, $new_height );
		imagealphablending( $new_watermark, false );
		imagesavealpha( $new_watermark, true );
		imagecopyresampled( $new_watermark, $watermark, 0, 0, 0, 0, $new_width, $new_height, $wm_width, $wm_height );
		imagedestroy( $watermark );
		return $new_watermark;
	}

	return $watermark;
}

/**
 * Calculate watermark x/y position based on position setting.
 */
function sunshine_calculate_watermark_position( $position, $margin, $image_width, $image_height, $wm_width, $wm_height ) {
	if ( $position == 'topleft' ) {
		$x_pos = $margin;
		$y_pos = $margin;
	} elseif ( $position == 'topright' ) {
		$x_pos = $image_width - $wm_width - $margin;
		$y_pos = $margin;
	} elseif ( $position == 'bottomleft' ) {
		$x_pos = $margin;
		$y_pos = $image_height - $wm_height - $margin;
	} elseif ( $position == 'bottomright' ) {
		$x_pos = $image_width - $wm_width - $margin;
		$y_pos = $image_height - $wm_height - $margin;
	} else {
		// Center.
		$x_pos = ( $image_width / 2 ) - ( $wm_width / 2 );
		$y_pos = ( $image_height / 2 ) - ( $wm_height / 2 );
	}

	return array( $x_pos, $y_pos );
}

/**
 * Fix EXIF orientation for a GD image resource.
 *
 * GD does not read EXIF orientation when loading images. This function reads
 * the EXIF Orientation tag and applies the corresponding rotation/flip so
 * the pixel data matches the intended display orientation.
 *
 * Only applies to JPEG files (EXIF is a JPEG-only format).
 * Gracefully returns the original image if the EXIF extension is unavailable.
 */
function sunshine_fix_gd_orientation( $gd_image, $file_path ) {
	if ( ! function_exists( 'exif_read_data' ) ) {
		return $gd_image;
	}

	$image_file_type = wp_check_filetype( $file_path );
	if ( ! in_array( $image_file_type['ext'], array( 'jpg', 'jpeg' ), true ) ) {
		return $gd_image;
	}

	$exif = @exif_read_data( $file_path, 'IFD0' );
	if ( empty( $exif['Orientation'] ) || $exif['Orientation'] == 1 ) {
		return $gd_image;
	}

	$orientation = (int) $exif['Orientation'];

	switch ( $orientation ) {
		case 2:
			imageflip( $gd_image, IMG_FLIP_HORIZONTAL );
			break;
		case 3:
			$gd_image = imagerotate( $gd_image, 180, 0 );
			break;
		case 4:
			imageflip( $gd_image, IMG_FLIP_VERTICAL );
			break;
		case 5:
			$gd_image = imagerotate( $gd_image, 270, 0 );
			imageflip( $gd_image, IMG_FLIP_HORIZONTAL );
			break;
		case 6:
			$gd_image = imagerotate( $gd_image, 270, 0 );
			break;
		case 7:
			$gd_image = imagerotate( $gd_image, 90, 0 );
			imageflip( $gd_image, IMG_FLIP_HORIZONTAL );
			break;
		case 8:
			$gd_image = imagerotate( $gd_image, 90, 0 );
			break;
	}

	return $gd_image;
}

// Add the watermark.
add_action( 'sunshine_after_image_process', 'sunshine_watermark_media_upload', 10, 3 );
function sunshine_watermark_media_upload( $attachment_id, $file_path, $watermark = true ) {
	if ( ! SPC()->get_option( 'watermark_image' ) || $watermark === 0 || $watermark === '0' || $watermark === false ) {
		SPC()->log( 'Not watermarking ' . $attachment_id );
		return;
	}
	sunshine_watermark_image( $attachment_id );
}
