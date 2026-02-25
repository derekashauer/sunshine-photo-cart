<div id="sunshine-install--business" class="sunshine-install--step">
	<form method="post" action="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install&step=business' ) ); ?>">
		<?php echo wp_nonce_field( 'sunshine_install_business', 'sunshine_install_business' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<h2>Your Business Information</h2>
		<p>This information is used on all customer invoices and receipts</p>
		<div id="sunshine-install--business--address">
			<p><label>Address Line 1</label> <input type="text" name="address1" /></label></p>
			<p><label>Address Line 2</label> <input type="text" name="address2" /></label></p>
			<p><label>City</label> <input type="text" name="city" /></label></p>
			<p><label>State / Province</label> <input type="text" name="state" /></label></p>
			<p><label>Zip / Postal Code</label> <input type="text" name="postcode" /></label></p>
			<p>
				<label>Country</label>
				<select name="country">
					<option value=""></option>
					<?php
					$countries = SPC()->countries->get_countries();
					foreach ( $countries as $key => $name ) {
						echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $name ) . '</option>';
					}
					?>
				</select>
				</label>
			</p>
		</div>

		<h2>Logo</h2>
		<p>Used for your branding in emails and other areas</p>
		<?php
		$logo_id = get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$logo = wp_get_attachment_image_src( $logo_id, 'medium' );
		}
		?>
		<p>
			<label for="image-upload" class="custom-media-upload">
				<input id="image-upload" type="button" value="Select or Upload Image" class="button" /> <button id="remove-image" style="
				<?php
				if ( empty( $logo ) ) {
					?>
					display: none;<?php } ?>" class="button delete">Remove Image</button>
				<input id="image-id" type="hidden" name="logo" value="<?php echo esc_attr( $logo_id ); ?>" />
				<br /><img id="image-preview" src="
				<?php
				if ( ! empty( $logo ) ) {
					echo esc_url( $logo[0] ); }
				?>
				" style="
<?php
if ( empty( $logo ) ) {
	?>
					display: none;<?php } ?> max-height: 70px; width: auto;"/>
			</label>
		</p>
		<script>
		jQuery(document).ready(function($) {
			$('#image-upload').click(function(e) {
				e.preventDefault();
				var mediaUploader;

				if (mediaUploader) {
					mediaUploader.open();
					return;
				}

				mediaUploader = wp.media.frames.file_frame = wp.media({
					title: 'Choose Image',
					button: {
						text: 'Choose Image'
					},
					multiple: false
				});

				mediaUploader.on('select', function() {
					var attachment = mediaUploader.state().get('selection').first().toJSON();
					$('#image-id').val(attachment.id);
					$('#image-preview').attr('src', attachment.url).show();
					$('#image-upload').hide();
					$('#remove-image').show();
				});

				mediaUploader.open();
			});

			$('#remove-image').click(function(e) {
				e.preventDefault();
				$('#image-id').val('');
				$('#image-preview').attr('src', '').hide();
				$(this).hide();
				$('#image-upload').show();
			});
		});
		</script>

		<h2>What areas of photography do you specialize in?</h2>
		<ul id="sunshine-install--niches">
			<li><label><input type="checkbox" name="niches[]" value="family" />Family</label></li>
			<li><label><input type="checkbox" name="niches[]" value="newborn" />Newborn</label></li>
			<li><label><input type="checkbox" name="niches[]" value="wedding" />Wedding</label></li>
			<li><label><input type="checkbox" name="niches[]" value="school" />School</label></li>
			<li><label><input type="checkbox" name="niches[]" value="sports" />Sports</label></li>
			<li><label><input type="checkbox" name="niches[]" value="portraits" />Portraits</label></li>
			<li><label><input type="checkbox" name="niches[]" value="animals" />Animals</label></li>
			<li><label><input type="checkbox" name="niches[]" value="events" />Events</label></li>
			<li><label><input type="checkbox" name="niches[]" value="real-estate" />Real Estate</label></li>
		</ul>

		<div class="sunshine-install--step--actions">
			<p><button class="button-primary large" type="submit">Save & Continue</button></p>
			<p style="font-size:16px;"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install&step=license' ) ); ?>" class="sunshine-install--continue">Skip this step</a></p>
		</div>

	</form>

</div>
