<?php
/**
 * The list of print labs at the top of the Print Labs settings tab.
 *
 * @package SunshinePhotoCart
 */

add_action( 'sunshine_print_labs_display', 'sunshine_print_labs_display' );
/**
 * Show each active print lab with a link to its settings.
 *
 * @param array $field The settings field; 'options' holds the labs.
 */
function sunshine_print_labs_display( $field ) {
	$print_labs = $field['options'];
	?>
	<table id="sunshine-print-labs" class="sunshine-table">
		<tbody>
		<?php foreach ( $print_labs as $id => $lab ) { ?>
			<tr id="sunshine-print-lab-<?php echo esc_attr( $id ); ?>">
				<td>
					<strong><?php echo esc_html( ! empty( $lab['name'] ) ? $lab['name'] : $id ); ?></strong>
					<?php if ( ! empty( $lab['description'] ) ) { ?>
						<br /><?php echo esc_html( $lab['description'] ); ?>
					<?php } ?>
				</td>
				<td class="sunshine-actions">
					<a href="<?php echo esc_url( sunshine_get_print_lab_settings_url( $id ) ); ?>" class="button"><?php esc_html_e( 'Configure', 'sunshine-photo-cart' ); ?></a>
				</td>
			</tr>
		<?php } ?>
		</tbody>
	</table>
	<?php
}
