<form method="post" action="<?php echo esc_url( sunshine_get_account_endpoint_url( 'addresses' ) ); ?>" class="sunshine--form--fields" id="sunshine--addresses">
	<?php
	foreach ( $fields as $id => $field ) {
		sunshine_form_field( $field['id'], $field );
	}
	?>
</form>
