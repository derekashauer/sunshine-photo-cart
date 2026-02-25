<?php

add_action( 'sunshine_payment_methods_display', 'sunshine_payment_methods_display' );
function sunshine_payment_methods_display() {

	$id = ( isset( $_GET['id'] ) ) ? sanitize_key( $_GET['id'] ) : '';

	if ( $id ) {
		return false;
	}
	$payment_methods = sunshine_get_payment_methods();
	?>
	<table id="sunshine-payment-methods" class="sunshine-table">
		<tbody>
		<?php
		if ( ! empty( $payment_methods ) ) :
			foreach ( $payment_methods as $id => $payment_method ) {
				$payment_method_class = SPC()->payment_methods->get_payment_method_by_id( $id );
				if ( ! $payment_method_class->can_be_enabled() ) {
					continue;
				}
				?>
				<tr id="sunshine-payment-method-<?php echo esc_attr( $id ); ?>" data-id="<?php echo esc_attr( $id ); ?>">
					<td><span class="dashicons dashicons-sort"></span></td>
					<td>
						<label class="sunshine-switch">
						  <input type="checkbox" name="sunshine_payment_method_active[<?php echo esc_attr( $id ); ?>]" <?php checked( $payment_method_class->is_active(), true ); ?> />
						  <span class="sunshine-switch-slider"></span>
						</label>
					</td>
					<td>
						<strong><?php echo esc_html( $payment_method_class->get_name() ); ?></strong><br />
						<?php echo esc_html( $payment_method_class->get_description() ); ?>
					</td>
					<td class="sunshine-actions">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine&section=payment_methods&payment_method=' . esc_attr( $id ) ) ); ?>" class="button" <?php echo ( ! $payment_method_class->is_active() ? 'style="display: none;"' : '' ); ?>><?php esc_html_e( 'Configure', 'sunshine-photo-cart' ); ?></a>
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

		$( document ).on( 'change', '.sunshine-switch', function( e ){
			var toggled_id = $( this ).closest( 'tr' ).data( 'id' );
			$.ajax({
				type: 'POST',
				url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
				data: {
					action: 'sunshine_activate_payment_method',
					id: toggled_id,
					security: "<?php echo esc_js( wp_create_nonce( 'sunshine_activate_payment_methods' ) ); ?>"
				},
				success: function( data, textStatus, XMLHttpRequest ) {
					$( '#sunshine-payment-method-' + toggled_id + ' a' ).toggle();
				},
				error: function(MLHttpRequest, textStatus, errorThrown) {
					alert( '<?php echo esc_js( __( 'Sorry, there was an error with your request', 'sunshine-photo-cart' ) ); ?>' );
				}
			});
		});

		$( '#sunshine-payment-methods tbody' ).sortable({
			stop: function( event, ui ) {
				var sorted_instances = new Array();
				$( '#sunshine-payment-methods tbody tr' ).each(function(){
					var data_instance_id = $( this ).data( 'id' );
					if ( data_instance_id ) {
						sorted_instances.push( data_instance_id );
					}
				});
				$.ajax({
					type: 'POST',
					url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
					data: {
						action: 'sunshine_sort_payment_methods',
						sorted_instances: sorted_instances,
						security: "<?php echo esc_js( wp_create_nonce( 'sunshine-sort-payment-methods' ) ); ?>"
					}
				});
			}
		});

	});
	</script>
	<?php
}

add_action( 'wp_ajax_sunshine_sort_payment_methods', 'sunshine_sort_payment_methods' );
function sunshine_sort_payment_methods() {

	if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine-sort-payment-methods' ) ) {
		return false;
	}

	$payment_methods           = SPC()->payment_methods->get_payment_methods();
	$new_payment_methods_order = array();
	foreach ( $_REQUEST['sorted_instances'] as $payment_method_key ) {
		if ( array_key_exists( $payment_method_key, $payment_methods ) ) {
			$new_payment_methods_order[] = $payment_method_key;
		}
	}
	if ( ! empty( $new_payment_methods_order ) ) {
		SPC()->update_option( 'payment_methods_order', $new_payment_methods_order );
	}
	exit;

}

add_action( 'wp_ajax_sunshine_delete_payment_method', 'sunshine_delete_payment_method' );
function sunshine_delete_payment_method() {
	global $wpdb;

	if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine-delete-payment-method' ) ) {
		return false;
	}

	$id              = sanitize_text_field( $_REQUEST['instance_id'] );
	$payment_methods = sunshine_get_available_payment_methods();
	if ( array_key_exists( $id, $payment_methods ) ) {
		// Remove this from the available methods array
		unset( $payment_methods[ $id ] );
	}

	// Update the payment methods settings
	SPC()->update_option( 'payment_methods', $payment_methods );

	// Delete all settings related to this payment method
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%{$id}'" );

	echo true;
	exit;

}

add_action( 'wp_ajax_sunshine_activate_payment_method', 'sunshine_activate_payment_method' );
function sunshine_activate_payment_method() {

	if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine_activate_payment_methods' ) ) {
		return false;
	}

	$id             = sanitize_text_field( $_REQUEST['id'] );
	$current_status = SPC()->get_option( $id . '_active' );
	if ( empty( $current_status ) ) {
		$current_status = false;
	}

	SPC()->update_option( $id . '_active', ! $current_status );
	exit;

}
