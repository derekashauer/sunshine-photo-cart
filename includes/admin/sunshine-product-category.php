<?php
// Admin area to create default product options and pricing
add_action( 'sunshine-product-category_add_form_fields', 'sunshine_product_category_add_form_fields' );
function sunshine_product_category_add_form_fields( $taxonomy ) {
	wp_enqueue_media();
	?>
		<div class="form-field">
			<label><?php esc_html_e( 'Image', 'sunshine-photo-cart' ); ?></label>
			<button id="image-upload" class="button" style="display: inline-block;"><?php esc_html_e( 'Upload Image', 'sunshine-photo-cart' ); ?></button>
			<div id="image"></div>
			<input type="hidden" name="image" value="" />
			<button id="image-delete" class="button" style="display: none;"><?php esc_html_e( 'Remove image', 'sunshine-photo-cart' ); ?></button>
		</div>
		<script>
		jQuery(document).ready(function($) {

			var file_frame;

			$.fn.uploadMediaFile = function( button, preview_media ) {

				event.preventDefault();
				file_frame = wp.media.frames.file_frame = wp.media({
					title: '<?php echo esc_js( __( 'Select image to upload', 'sunshine-photo-cart' ) ); ?>',
					button: {
						text: '<?php echo esc_js( __( 'Use this image', 'sunshine-photo-cart' ) ); ?>',
					},
					multiple: false
				});

				// When an image is selected, run a callback.
				file_frame.on( 'select', function() {
					attachment = file_frame.state().get( 'selection' ).first().toJSON();
					$( 'input[name="image"]' ).val( attachment.id );
					$( '#image-upload' ).hide();
					$( '#image-delete' ).show();
					var preview_image_container = $( '#image' );
					if ( !$( 'img', preview_image_container ).length ) {
						var img = $( '<img />' ).appendTo( preview_image_container );
					}
					$( 'img', preview_image_container ).attr( 'src', attachment.sizes.thumbnail.url );
				});

				// Finally, open the modal
				file_frame.open();
			}

			$( '#image-upload' ).click(function() {
				$.fn.uploadMediaFile( jQuery(this), true );
			});

			$( '#image-delete' ).click(function() {
				$( 'input[type="image"]' ).val( '' );
				$( '#image img' ).remove();
				$( '#image-delete' ).hide();
				$( '#image-upload' ).show();
				return false;
			});

			let numberOfTags = 0;
			let newNumberOfTags = 0;

			// when there are some terms are already created
			if( ! $( '#the-list' ).children( 'tr' ).first().hasClass( 'no-items' ) ) {
				numberOfTags = $( '#the-list' ).children( 'tr' ).length;
			}

			// Clear image after adding
			$( document ).ajaxComplete( function( event, xhr, settings ){
				newNumberOfTags = $( '#the-list' ).children('tr').length;
				if( parseInt( newNumberOfTags ) > parseInt( numberOfTags ) ) {
					// refresh the actual number of tags variable
					numberOfTags = newNumberOfTags;
					// empty custom fields right here
					$( 'input[type="image"]' ).val( '' );
					$( '#image img' ).remove();
					$( '#image-delete' ).hide();
					$( '#image-upload' ).show();
				}
			});

		});
		</script>

	<?php
}

add_action( 'sunshine-product-category_edit_form_fields', 'sunshine_product_category_edit_form_fields', 10, 2 );
function sunshine_product_category_edit_form_fields( $term, $taxonomy ) {
	wp_enqueue_media();
	$image_id = get_term_meta( $term->term_id, 'image', true );
	?>
		<tr class="form-field sunshine-product-category-image">
			<th scope="row"><label><?php esc_html_e( 'Image', 'sunshine-photo-cart' ); ?></label></th>
			<td>
				<button id="image-upload" class="button" style="display: <?php echo ( ! empty( $image_id ) ) ? 'none' : 'inline-block'; ?>;"><?php esc_html_e( 'Upload Image', 'sunshine-photo-cart' ); ?></button>
				<div id="image">
				<?php
				if ( $image_id ) {
					echo wp_get_attachment_image( $image_id, 'thumbnail' );
				}
				?>
				</div>
				<input type="hidden" name="image" value="<?php echo esc_attr( $image_id ); ?>" />
				<button id="image-delete" class="button" style="display: <?php echo ( ! empty( $image_id ) ) ? 'inline-block' : 'none'; ?>;"><?php esc_html_e( 'Remove image', 'sunshine-photo-cart' ); ?></button>
			</td>
		</tr>

		<script>
		jQuery(document).ready(function($) {

			var file_frame;

			$( document ).on( "click", "#image-upload", function( event ){
				event.preventDefault();
				file_frame = wp.media.frames.file_frame = wp.media({
					title: '<?php echo esc_js( __( 'Select image to upload', 'sunshine-photo-cart' ) ); ?>',
					button: {
						text: '<?php echo esc_js( __( 'Use this image', 'sunshine-photo-cart' ) ); ?>',
					},
					multiple: false
				});
				file_frame.on( 'select', function() {
					attachment = file_frame.state().get( 'selection' ).first().toJSON();
					$( 'input[name="image"]' ).val( attachment.id );
					$( '#image-upload' ).hide();
					$( '#image-delete' ).show();
					var preview_image_container = $( '#image' );
					if ( !$( 'img', preview_image_container ).length ) {
						var img = $( '<img />' ).appendTo( preview_image_container );
					}
					$( 'img', preview_image_container ).attr( 'src', attachment.sizes.thumbnail.url );
				});
				file_frame.open();
			});

			$( '#image-delete' ).click(function() {
				$( 'input[name="image"]' ).val( '' );
				$( '#image img' ).remove();
				$( '#image-delete' ).hide();
				$( '#image-upload' ).show();
				return false;
			});


		});
		</script>

	<?php
}


add_action( 'created_sunshine-product-category', 'sunshine_product_category_save' );
add_action( 'edited_sunshine-product-category', 'sunshine_product_category_save' );
function sunshine_product_category_save( $term_id ) {

	if ( isset( $_POST['image'] ) ) {
		update_term_meta( $term_id, 'image', intval( $_POST['image'] ) );
	}

}

add_filter( 'manage_edit-sunshine-product-category_columns', 'sunshine_product_category_columns' );
function sunshine_product_category_columns( $columns ) {
	$new_columns = array(
		'cb'          => '<input type="checkbox" />',
		'name'        => __( 'Name', 'sunshine-photo-cart' ),
		'description' => __( 'Description', 'sunshine-photo-cart' ),
		'image'       => __( 'Image', 'sunshine-photo-cart' ),
		'posts'       => __( 'Products', 'sunshine-photo-cart' ),
	);
	return $new_columns;
}

add_filter( 'manage_sunshine-product-category_custom_column', 'sunshine_product_category_columns_content', 10, 3 );
function sunshine_product_category_columns_content( $output, $column, $term_id ) {
	switch ( $column ) {
		case 'image':
			if ( get_term_meta( $term_id, 'default', true ) ) {
				$output .= '<div class="sunshine--default-term"></div>';
			}
			$image_id = get_term_meta( $term_id, 'image', true );
			if ( $image_id ) {
				$output .= wp_get_attachment_image( $image_id, 'thumbnail' );
			} else {
				$output .= '&mdash;';
			}
			break;
	}
	return $output;
}

// Prevent default term from being deleted.
add_filter( 'sunshine-product-category_row_actions', 'sunshine_product_category_row_actions', 10, 2 );
function sunshine_product_category_row_actions( $actions, $term ) {

	if ( get_term_meta( $term->term_id, 'default', true ) ) {
		unset( $actions['delete'] );
	}

	return $actions;
}
