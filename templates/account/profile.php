<form method="post" action="<?php echo esc_url( sunshine_get_account_endpoint_url( 'profile' ) ); ?>" class="sunshine--form--fields">
	<?php
	foreach ( $fields as $id => $field ) {
		sunshine_form_field( $field['id'], $field );
	}
	?>
</form>
