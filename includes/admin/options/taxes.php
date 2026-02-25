<?php

add_action( 'sunshine_taxes_display', 'sunshine_taxes_display' );
function sunshine_taxes_display() {
	$tax_rates = sunshine_get_tax_rates();
	?>
	<p id="sunshine-new-tax-rate">
		<a class="button"><?php esc_html_e( 'Add tax rate', 'sunshine-photo-cart' ); ?></a>
	</p>
	<table id="sunshine-tax-rates" class="wps-table">
		<thead>
			<th><?php esc_html_e( 'Country', 'sunshine-photo-cart' ); ?></th>
			<th><?php esc_html_e( 'State/Province', 'sunshine-photo-cart' ); ?></th>
			<th><?php esc_html_e( 'Zip/Postal Code(s)', 'sunshine-photo-cart' ); ?></th>
			<th><?php esc_html_e( 'Tax Rate', 'sunshine-photo-cart' ); ?></th>
			<th></th>
		</thead>
		<tbody>
		<?php
		$i = 0;
		if ( ! empty( $tax_rates ) && is_array( $tax_rates ) ) {
			foreach ( $tax_rates as $instance_id => $tax_rate ) {
				?>
				<tr id="sunshine-tax-rate-<?php echo esc_attr( $i ); ?>" data-instance="<?php echo esc_attr( $i ); ?>">
					<td class="tax-country">
						<select name="sunshine_tax_rates[<?php echo esc_attr( $i ); ?>][country]">
							<option value="all"><?php esc_html_e( 'All countries', 'sunshine-photo-cart' ); ?></option>
							<?php foreach ( SPC()->countries->get_countries() as $code => $name ) { ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $tax_rate['country'] ); ?>><?php echo wp_kses_post( $name ); ?></option>
							<?php } ?>
						</select>
					</td>
					<td class="tax-state">
						<?php
						$states = SPC()->countries->get_states( $tax_rate['country'] );
						if ( $states ) {
							?>
						<select name="sunshine_tax_rates[<?php echo esc_attr( $i ); ?>][state]">
							<option value=""><?php esc_html_e( 'Any state/province', 'sunshine-photo-cart' ); ?></option>
							<?php foreach ( $states as $code => $name ) { ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $tax_rate['state'] ); ?>><?php echo wp_kses_post( $name ); ?></option>
							<?php } ?>
						</select>
						<?php } else { ?>
							<input type="text" name="sunshine_tax_rates[<?php echo esc_attr( $i ); ?>][state]" value="<?php echo esc_attr( $tax_rate['state'] ); ?>" />
						<?php } ?>
					</td>
					<td class="tax-postcode"><input type="text" name="sunshine_tax_rates[<?php echo esc_attr( $i ); ?>][postcode]" value="<?php echo ( ! empty( $tax_rate['postcode'] ) ) ? esc_attr( $tax_rate['postcode'] ) : ''; ?>" /></td>
					<td class="tax-rate"><input type="number" name="sunshine_tax_rates[<?php echo esc_attr( $i ); ?>][rate]" size="6" step=".001" min="0" max="100" value="<?php echo esc_attr( $tax_rate['rate'] ); ?>" />%</td>
					<td class="tax-actions">
						<a href="#" class="button delete"><?php esc_html_e( 'Delete', 'sunshine-photo-cart' ); ?></a>
					</td>
					<td class="tax-sort"><span class="dashicons dashicons-sort"></span></td>
				</tr>
				<?php
				$i++;
			}
		} else {
			$i = 0;
			?>
			<tr id="sunshine-tax-rate-<?php echo esc_attr( $i ); ?>" data-instance="<?php echo esc_attr( $i ); ?>">
				<td class="tax-country">
					<select name="sunshine_tax_rates[<?php echo esc_attr( $i ); ?>][country]">
						<option value="all"><?php esc_html_e( 'All countries', 'sunshine-photo-cart' ); ?></option>
						<?php foreach ( SPC()->countries->get_countries() as $code => $name ) { ?>
							<option value="<?php echo esc_attr( $code ); ?>"><?php echo wp_kses_post( $name ); ?></option>
						<?php } ?>
					</select>
				</td>
				<td class="tax-state"><input type="text" name="sunshine_tax_rates[<?php echo esc_attr( $i ); ?>][state]" value="" /></td>
				<td class="tax-postcode"><input type="text" name="sunshine_tax_rates[<?php echo esc_attr( $i ); ?>][postcode]" value="" /></td>
				<td class="tax-rate"><input type="number" name="sunshine_tax_rates[<?php echo esc_attr( $i ); ?>][rate]" step=".001" min="0" max="100" size="6" value="" />%</td>
				<td class="wps-actions">
					<a href="#" class="button delete"><?php esc_html_e( 'Delete', 'sunshine-photo-cart' ); ?></a>
				</td>
				<td><span class="dashicons dashicons-sort"></span></td>
			</tr>
			<?php
			$i++;
		}
		?>
		</tbody>
	</table>

	<script>
	jQuery( document ).ready(function($){

		var sunshine_next_instance = <?php echo intval( $i ); ?>;

		$( document ).on( 'click', '#sunshine-new-tax-rate a', function( e ){

			var new_row = '<tr id="sunshine-tax-rate-' + sunshine_next_instance + '" data-instance="' + sunshine_next_instance + '">' +
							'<td class="tax-country">' +
								'<select name="sunshine_tax_rates[' + sunshine_next_instance + '][country]">' +
									'<option value="all"><?php echo esc_js( __( 'All countries', 'sunshine-photo-cart' ) ); ?></option>' +
									<?php foreach ( SPC()->countries->get_countries() as $code => $name ) { ?>
										'<option value="<?php echo esc_js( $code ); ?>"><?php echo esc_js( $name ); ?></option>' +
									<?php } ?>
								'</select>' +
							'</td>' +
							'<td class="tax-state"><input type="text" name="sunshine_tax_rates[' + sunshine_next_instance + '][state]" value="" /></td>' +
							'<td class="tax-postcode"><input type="text" name="sunshine_tax_rates[' + sunshine_next_instance + '][postcode]" value="" /></td>' +
							'<td class="tax-rate"><input type="number" name="sunshine_tax_rates[' + sunshine_next_instance + '][rate]" step=".001" min="0" max="100" size="6" value="" />%</td>' +
							'<td class="wps-actions">' +
								'<a href="#" class="button delete"><?php echo esc_js( __( 'Delete', 'sunshine-photo-cart' ) ); ?></a>' +
							'</td>' +
							'<td><span class="dashicons dashicons-sort"></span></td>' +
						'</tr>';

			$( '#sunshine-tax-rates tbody' ).append( new_row );
			sunshine_next_instance++;
			return false;
		});

		$( '#sunshine-tax-rates tbody' ).sortable({
			stop: function( event, ui ) {
				var sorted_instances = new Array();
				$( '#sunshine-tax-rates tbody tr' ).each(function(){
					var data_instance_id = $( this ).data( 'instance' );
					sorted_instances.push( data_instance_id );
				});
				$.ajax({
					type: 'POST',
					url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
					data: {
						action: 'sunshine_sort_tax_rates',
						sorted_instances: sorted_instances,
						security: "<?php echo esc_js( wp_create_nonce( 'sunshine-sort-tax-rates' ) ); ?>"
					}
				});
			}
		});

		$( document ).on( 'click', 'a.delete', function( e ){
			var data_instance_id = $( this ).closest( 'tr' ).data( 'instance' );
			$.ajax({
				type: 'POST',
				url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
				data: {
					action: 'sunshine_delete_tax_rate',
					instance_id: data_instance_id,
					security: "<?php echo esc_js( wp_create_nonce( 'sunshine-delete-tax-rate' ) ); ?>"
				},
				success: function( data, textStatus, XMLHttpRequest ) {
					if ( data ) {
						$( '#sunshine-tax-rate-' + data_instance_id ).fadeOut( 400, function() {
							$( this ).remove();
						});
						$( '#sunshine-tax-rate tbody' ).sortable( 'refresh' );
					}
				},
			});
			return false;
		});

		$( document ).on( 'change', '.tax-country select', function( e ){
			var data_instance_id = $( this ).closest( 'tr' ).data( 'instance' );
			var selected_country = $( 'option:selected', this ).val();
			$.ajax({
				type: 'POST',
				url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
				data: {
					action: 'sunshine_show_tax_states',
					country: selected_country,
					security: "<?php echo esc_js( wp_create_nonce( 'sunshine-show-tax-states' ) ); ?>"
				},
				success: function( data, textStatus, XMLHttpRequest ) {
					$( '#sunshine-tax-rate-' + data_instance_id + ' td.tax-state' ).html( '' );
					if ( data ) {
						$( '#sunshine-tax-rate-' + data_instance_id + ' td.tax-state' ).append( '<select name="sunshine_tax_rates[' + data_instance_id + '][state]"><option value=""><?php echo esc_js( 'Any state/province', 'sunshine-photo-cart' ); ?></select>' );
						var states = JSON.parse( data );
						$.each( states, function( index, value ){
							$( '#sunshine-tax-rate-' + data_instance_id + ' td.tax-state select' ).append( '<option value="' + index + '">' + value + '</option>' );
						});
					} else {
						$( '#sunshine-tax-rate-' + data_instance_id + ' td.tax-state' ).append( '<input type="text" name="sunshine_tax_rates[' + data_instance_id + '][state]" />' );
					}
				},
			});
			return false;
		});


	});
	</script>
	<?php

}

add_action( 'wp_ajax_sunshine_sort_tax_rates', 'sunshine_sort_tax_rates' );
function sunshine_sort_tax_rates() {

	if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine-sort-tax-rates' ) ) {
		return false;
	}

	$tax_rates           = sunshine_get_tax_rates();
	$new_tax_rates_order = array();
	foreach ( $_REQUEST['sorted_instances'] as $tax_rate_key ) {
		if ( array_key_exists( $tax_rate_key, $tax_rates ) ) {
			$new_tax_rates_order[ $tax_rate_key ] = $tax_rates[ $tax_rate_key ];
		}
	}
	if ( ! empty( $new_tax_rates_order ) ) {
		SPC()->update_option( 'tax_rates', $new_tax_rates_order );
	}
	exit;

}

add_action( 'wp_ajax_sunshine_delete_tax_rate', 'sunshine_delete_tax_rate' );
function sunshine_delete_tax_rate() {
	global $wpdb;

	if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine-delete-tax-rate' ) ) {
		return false;
	}

	$instance_id = sanitize_text_field( $_REQUEST['instance_id'] );
	$tax_rates   = sunshine_get_tax_rates();
	if ( array_key_exists( $instance_id, $tax_rates ) ) {
		// Remove this from the available methods array
		unset( $tax_rates[ $instance_id ] );
	}

	// Update the shipping methods settings
	SPC()->update_option( 'tax_rates', $tax_rates );

	echo true;
	exit;

}
add_action( 'wp_ajax_sunshine_show_tax_states', 'sunshine_show_tax_states' );
function sunshine_show_tax_states() {

	if ( ! wp_verify_nonce( $_REQUEST['security'], 'sunshine-show-tax-states' ) ) {
		return false;
	}

	$states = SPC()->countries->get_states( $_REQUEST['country'] );
	if ( ! empty( $states ) ) {
		echo json_encode( $states );
	}
	exit;

}
