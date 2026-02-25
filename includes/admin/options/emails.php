<?php
add_action( 'sunshine_emails_display', 'sunshine_emails_display' );
function sunshine_emails_display() {

	$id = ( isset( $_GET['id'] ) ) ? sanitize_key( $_GET['id'] ) : '';

	if ( $id ) {
		return false;
	}
	$emails = SPC()->emails->get_emails();
	?>
	<table id="sunshine-emails" class="sunshine-table">
		<tbody>
		<?php
		if ( ! empty( $emails ) ) :
			foreach ( $emails as $id => $email ) {
				$email_class = SPC()->emails->get_email_by_id( $id );
				?>
				<tr id="sunshine-email-<?php echo esc_attr( $id ); ?>">
					<td>
						<label class="sunshine-switch">
						  <input type="checkbox" name="sunshine_email_<?php echo esc_attr( $id ); ?>_active" value="1" <?php checked( $email_class->is_active(), true ); ?> data-id="<?php echo esc_attr( $id ); ?>" />
						  <span class="sunshine-switch-slider"></span>
						</label>
					</td>
					<td>
						<strong><?php echo esc_html( $email_class->get_name() ); ?></strong><br />
						<?php echo esc_html( $email_class->get_description() ); ?>
						<?php
						if ( $email_class->get_recipients() ) {
							echo '<br /><em>' . esc_html( join( ', ', $email_class->get_recipients() ) ) . '</em>'; }
						?>
					</td>
					<td class="sunshine-actions">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine&section=email&email=' . esc_attr( $id ) ) ); ?>" class="button"><?php esc_html_e( 'Configure', 'sunshine-photo-cart' ); ?></a>
					</td>
				</tr>
				<?php
			}
		endif;
		?>
		</tbody>
	</table>

	<script>
	jQuery( document ).ready(function($){

		$( document ).on( 'change', '.sunshine-switch input', function( e ){
			var toggled_id = $( this ).data( 'id' );
			var value = $( this ).prop( 'checked' );
			$.ajax({
				type: 'POST',
				url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
				data: {
					action: 'sunshine_active_emails',
					id: toggled_id,
					value: $( this ).prop( 'checked' ),
					security: "<?php echo esc_js( wp_create_nonce( 'sunshine-active-emails' ) ); ?>"
				},
				success: function( data, textStatus, XMLHttpRequest ) {
					$( '#sunshine-emails-' + toggled_id + ' a' ).toggle();
				},
				error: function(MLHttpRequest, textStatus, errorThrown) {
					alert( '<?php echo esc_js( __( 'Sorry, there was an error with your request', 'sunshine-photo-cart' ) ); ?>' );
				}
			});
		});


	});
	</script>

<?php }

add_action( 'wp_ajax_sunshine_active_emails', 'sunshine_active_emails_toggle' );
function sunshine_active_emails_toggle() {

	if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine-active-emails' ) ) {
		return false;
	}

	sunshine_log( 'sunshine_active_emails_toggle: ' . print_r( $_REQUEST, true ) );

	$id    = sanitize_text_field( $_REQUEST['id'] );
	$value = isset( $_REQUEST['value'] ) ? sanitize_text_field( $_REQUEST['value'] ) : '';
	// Convert string "false", "0", or empty to boolean false, otherwise boolean true.
	$active = ( 'false' === $value || '0' === $value || '' === $value ) ? false : true;
	sunshine_log( 'sunshine_active_emails_toggle: ' . $id . ' - ' . $value . ' -> ' . ( $active ? 'true' : 'false' ) );
	SPC()->update_option( 'email_' . $id . '_active', $active );
	exit;

}

?>
