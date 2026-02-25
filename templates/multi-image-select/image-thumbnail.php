<div class="sunshine--multi-image-select--image <?php echo esc_attr( $id ); ?>">
	<input type="checkbox" required="required" id="<?php echo esc_attr( $id ); ?>--image-<?php echo esc_attr( $image->get_id() ); ?>" name="images[]" data-option-id="images" value="<?php echo esc_attr( $image->get_id() ); ?>" />
	<label for="<?php echo esc_attr( $id ); ?>--image-<?php echo esc_attr( $image->get_id() ); ?>"><?php $image->output( 'sunshine-thumbnail' ); ?></label>
</div>
