<div class="sunshine--multi-image-select--image-set" id="<?php echo esc_attr( $id ); ?>">
	<div class="sunshine--multi-image-select--image-set--name"><?php echo esc_html( $name ); ?></div>
	<div class="sunshine--multi-image-select--image-set--images">
		<?php
		foreach ( $images as $image ) {
			sunshine_get_template(
				'multi-image-select/image-thumbnail',
				array(
					'image' => $image,
					'id'    => $id,
				)
			);
		}
		?>
	</div>
</div>
