<?php

function sunshine_get_tax_rates() {
	return apply_filters( 'sunshine_tax_rates', SPC()->get_option( 'tax_rates' ) );
}
