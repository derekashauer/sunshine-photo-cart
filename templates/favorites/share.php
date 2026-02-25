<form method="post" action="" id="sunshine--favorites--share">
	<?php wp_nonce_field( 'sunshine_favorites_share', 'sunshine_favorites_share' ); ?>
	<div id="sunshine--favorites--share--title" class="sunshine--modal--title"><?php esc_html_e( 'Share Your Favorites', 'sunshine-photo-cart' ); ?></div>
	<div class="sunshine--form--field">
		<label for="sunshine-favorites-share-message"><?php esc_html_e( 'Recipients', 'sunshine-photo-cart' ); ?></label>
		<input type="checkbox" name="sunshine_favorites_share_recipients[]" value="admin" checked="checked" /> <?php bloginfo( 'name' ); ?><br />
		<input type="checkbox" name="sunshine_favorites_share_recipients[]" value="custom" /> <?php esc_html_e( 'Custom recipients', 'sunshine-photo-cart' ); ?>
	</div>
	<div class="sunshine--form--field" id="sunshine-favorites-share-custom-recipient" style="display: none;">
		<label for="sunshine-favorites-share-custom-recipient-email"><?php esc_html_e( 'Enter email addresses separated by commas', 'sunshine-photo-cart' ); ?></label>
		<input type="text" name="sunshine_favorites_share_custom_recipient_email" id="sunshine-favorites-share-custom-recipient-email" />
		<div class="sunshine--favorites--share--disclaimer">
			<p><?php esc_html_e( 'Notice: A unique URL is generated and emailed to your recipients. Your selected favorite images will be accessible via this URL to anyone who has it.', 'sunshine-photo-cart' ); ?></p>
		</div>
	</div>

	<div class="sunshine--form--field">
		<label for="sunshine-favorites-share-note"><?php esc_html_e( 'Custom Note', 'sunshine-photo-cart' ); ?></label>
		<textarea name="sunshine_favorites_share_note" id="sunshine-favorites-note"></textarea>
	</div>

	<div class="sunshine--form--field sunshine--form--field-submit">
		<button type="submit" class="button sunshine--button"><?php esc_html_e( 'Share Favorites', 'sunshine-photo-cart' ); ?></button>
	</div>
</form>
