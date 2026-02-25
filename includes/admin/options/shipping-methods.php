<?php

add_action( 'sunshine_shipping_methods_display', 'sunshine_shipping_methods_display' );
function sunshine_shipping_methods_display() {

	$instance_id = ( isset( $_GET['instance_id'] ) ) ? sanitize_key( $_GET['instance_id'] ) : '';

	if ( $instance_id ) {
		return false;
	}
	$shipping_methods           = sunshine_get_shipping_methods();
	$available_shipping_methods = sunshine_get_available_shipping_methods();
	?>
	<p id="sunshine-new-shipping-method">
		<select name="new_shipping_method">
			<option value=""><?php esc_html_e( 'Select shipping method', 'sunshine-photo-cart' ); ?></option>
			<?php
			foreach ( $shipping_methods as $id => $shipping_method ) {
				$shipping_method_class = sunshine_get_shipping_method_by_id( $shipping_method['id'] );
				echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $shipping_method_class->get_name() ) . '</option>';
			}
			?>
		</select>
		<a class="button"><?php esc_html_e( 'Add shipping method', 'sunshine-photo-cart' ); ?></a>
	</p>

	<table id="sunshine-shipping-methods" class="sunshine-table">
		<tbody>
		<?php
		if ( ! empty( $available_shipping_methods ) ) :
			foreach ( $available_shipping_methods as $instance_id => $shipping_method ) {
				$this_shipping_method = sunshine_get_shipping_method_by_instance( $instance_id );
				?>
				<tr id="sunshine-shipping-method-<?php echo esc_attr( $instance_id ); ?>" data-instance="<?php echo esc_attr( $instance_id ); ?>">
					<td><span class="dashicons dashicons-sort"></span></td>
					<td>
						<label class="sunshine-switch">
						  <input type="checkbox" name="sunshine_shipping_method_active[<?php echo esc_attr( $instance_id ); ?>]" <?php checked( $shipping_method['active'], true ); ?> />
						  <span class="sunshine-switch-slider"></span>
						</label>
					</td>
					<td><strong><?php echo esc_html( $this_shipping_method->get_name() ); ?></strong></td>
					<td class="sunshine-actions">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine&section=shipping_methods&shipping_method=' . esc_attr( $shipping_method['id'] ) . '&instance_id=' . esc_attr( $instance_id ) ) ); ?>" class="button"><?php esc_html_e( 'Configure', 'sunshine-photo-cart' ); ?></a>
						<?php
						if ( $this_shipping_method->can_be_cloned() ) {
							?>
							<a href="#" class="button delete"><?php esc_html_e( 'Delete', 'sunshine-photo-cart' ); ?></a><?php } ?>
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

		//$( '.wps-settings-shipping_methods th' ).remove();

		$( document ).on( 'click', '#sunshine-new-shipping-method a', function( e ){

			var new_shipping_method = $( 'select[name="new_shipping_method"] option:selected' ).val();
			var new_shipping_method_label = $( 'select[name="new_shipping_method"] option:selected' ).html();

			if ( new_shipping_method ) {
				$.ajax({
					type: 'POST',
					url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
					data: {
						action: 'sunshine_add_shipping_method',
						shipping_method: new_shipping_method,
						security: "<?php echo esc_js( wp_create_nonce( 'sunshine-add-shipping-method' ) ); ?>"
					},
					success: function( data, textStatus, XMLHttpRequest ) {
						var result = $.parseJSON( data );
						if ( result ) {
							$( '#sunshine-shipping-methods' ).append( '<tr id="sunshine-shipping-method-' + result.instance_id + '" data-instance="' + result.instance_id + '"><td><span class="dashicons dashicons-sort"></span></td><td><label class="sunshine-switch"><input type="checkbox" name="sunshine_shipping_method_enabled[' + result.key + ']" checked="checked" /><span class="sunshine-switch-slider"></span></label></td><td><strong>' + result.name + '</strong></td><td><a href="admin.php?page=sunshine&section=shipping_methods&shipping_method=' + result.id + '&instance_id=' + result.instance_id + '" class="button"><?php echo esc_js( __( 'Configure', 'sunshine-photo-cart' ) ); ?></a> <a href="#" class="button delete"><?php echo esc_js( __( 'Delete', 'sunshine-photo-cart' ) ); ?></a></td></tr>' );
							$( '#sunshine-shipping-methods tbody' ).sortable( 'refresh' );
						}
					},
					error: function(MLHttpRequest, textStatus, errorThrown) {
						alert( '<?php echo esc_js( __( 'Sorry, there was an error with your request', 'sunshine-photo-cart' ) ); ?>' );
					}
				});
			}

			return false;
		});

		$( '#sunshine-shipping-methods tbody' ).sortable({
			stop: function( event, ui ) {
				var sorted_instances = new Array();
				$( '#sunshine-shipping-methods tbody tr' ).each(function(){
					var data_instance_id = $( this ).data( 'instance' );
					if ( data_instance_id ) {
						sorted_instances.push( data_instance_id );
					}
				});
				$.ajax({
					type: 'POST',
					url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
					data: {
						action: 'sunshine_sort_shipping_methods',
						sorted_instances: sorted_instances,
						security: "<?php echo esc_js( wp_create_nonce( 'sunshine-sort-shipping-methods' ) ); ?>"
					}
				});
			}
		});

		$( document ).on( 'click', 'a.delete', function( e ){
			var data_instance_id = $( this ).closest( 'tr' ).data( 'instance' );
			if ( data_instance_id ) {
				$.ajax({
					type: 'POST',
					url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
					data: {
						action: 'sunshine_delete_shipping_method',
						instance_id: data_instance_id,
						security: "<?php echo esc_js( wp_create_nonce( 'sunshine-delete-shipping-method' ) ); ?>"
					},
					success: function( data, textStatus, XMLHttpRequest ) {
						console.log( data );
						if ( data ) {
							$( '#sunshine-shipping-method-' + data_instance_id ).fadeOut( 400, function() {
								$( this ).remove();
							});
							$( '#sunshine-shipping-methods tbody' ).sortable( 'refresh' );
						}
					},
				});
			}
			return false;
		});

		$( document ).on( 'change', '.sunshine-switch', function( e ){
			$.ajax({
				type: 'POST',
				url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
				data: {
					action: 'sunshine_active_shipping_methods',
					instance_id: $( this ).closest( 'tr' ).data( 'instance' ),
					security: "<?php echo esc_js( wp_create_nonce( 'sunshine-active-shipping-methods' ) ); ?>"
				}
			});
		});


	});
	</script>
	<?php
}

add_action( 'wp_ajax_sunshine_add_shipping_method', 'sunshine_add_shipping_method' );
function sunshine_add_shipping_method() {

	if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine-add-shipping-method' ) ) {
		return false;
	}

	$new_shipping_method              = sanitize_text_field( $_REQUEST['shipping_method'] );
	$shipping_methods                 = sunshine_get_available_shipping_methods();
	$instance_id                      = wp_hash( $new_shipping_method . current_time( 'timestamp' ) );
	$shipping_methods[ $instance_id ] = array(
		'id'     => $new_shipping_method,
		'active' => true,
	);
	SPC()->update_option( 'shipping_methods', $shipping_methods );
	$return = sunshine_get_shipping_method_by_id( $new_shipping_method );
	echo json_encode(
		array(
			'instance_id' => $instance_id,
			'id'          => $return->get_id(),
			'name'        => $return->get_name(),
			'description' => $return->get_description(),
		)
	);
	exit;

}

add_action( 'wp_ajax_sunshine_sort_shipping_methods', 'sunshine_sort_shipping_methods' );
function sunshine_sort_shipping_methods() {

	if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine-sort-shipping-methods' ) ) {
		return false;
	}

	$shipping_methods           = sunshine_get_available_shipping_methods();
	$new_shipping_methods_order = array();
	foreach ( $_REQUEST['sorted_instances'] as $shipping_method_key ) {
		if ( array_key_exists( $shipping_method_key, $shipping_methods ) ) {
			$new_shipping_methods_order[ $shipping_method_key ] = $shipping_methods[ $shipping_method_key ];
		}
	}
	if ( ! empty( $new_shipping_methods_order ) ) {
		SPC()->update_option( 'shipping_methods', $new_shipping_methods_order );
	}
	exit;

}

add_action( 'wp_ajax_sunshine_delete_shipping_method', 'sunshine_delete_shipping_method' );
function sunshine_delete_shipping_method() {
	global $wpdb;

	if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine-delete-shipping-method' ) ) {
		return false;
	}

	$instance_id      = sanitize_text_field( $_REQUEST['instance_id'] );
	$shipping_methods = sunshine_get_available_shipping_methods();
	if ( array_key_exists( $instance_id, $shipping_methods ) ) {
		// Remove this from the available methods array
		unset( $shipping_methods[ $instance_id ] );
	}

	// Update the shipping methods settings
	SPC()->update_option( 'shipping_methods', $shipping_methods );

	// Delete all settings related to this shipping method
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%{$instance_id}'" );

	echo true;
	exit;

}

add_action( 'wp_ajax_sunshine_active_shipping_methods', 'sunshine_active_shipping_methods' );
function sunshine_active_shipping_methods() {

	if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine-active-shipping-methods' ) ) {
		return false;
	}

	$instance_id      = sanitize_text_field( $_REQUEST['instance_id'] );
	$shipping_methods = sunshine_get_available_shipping_methods();
	if ( array_key_exists( $instance_id, $shipping_methods ) ) {
		$shipping_methods[ $instance_id ]['active'] = ! $shipping_methods[ $instance_id ]['active'];
	}
	SPC()->update_option( 'shipping_methods', $shipping_methods );
	exit;

}
