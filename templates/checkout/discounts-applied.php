<?php
if ( ! empty( $discounts_applied ) && is_array( $discounts_applied ) ) {
	foreach ( $discounts_applied as $discount ) {
		if ( ! empty( $discount ) ) {
			sunshine_get_template( 'checkout/discount-applied', array( 'discount' => $discount ) );
		}
	}
}
