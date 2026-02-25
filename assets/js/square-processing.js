window.sunshinePaymentGateways['square'] = true;

var square_billing_contact = {};

async function sunshine_square_init_card( payments ) {
	const card = await payments.card();
	await card.attach( '#sunshine-square-payment-fields' );
	return card;
}

async function sunshine_square_verify_buyer(payments, token) {
	const verificationDetails = {
		amount: spc_square_vars.total,
		billingContact: square_billing_contact,
		currencyCode: spc_square_vars.currency,
		intent: 'CHARGE'
	};

	console.log( 'Square verify buyer', verificationDetails );

	const verificationResults = await payments.verifyBuyer(
		token,
		verificationDetails
	);
	console.log( 'Square verified buyer', verificationResults );
	return verificationResults.token;
}

// Call this function to send a payment token, buyer name, and other details
// to the project server code so that a payment can be created with
// Payments API
async function sunshine_square_create_payment(token, verificationToken) {
	return new Promise((resolve, reject) => {
		sunshine_checkout_updating();
		const data = {
			'action': 'sunshine_square_init_order',
			'source_id': token,
			'verification_token': verificationToken,
			'security': spc_square_vars.security,
		}

		jQuery.ajax({
			type: 'POST',
			url: spc_square_vars.ajax_url,
			data: data,
			success: function(result, textStatus, XMLHttpRequest) {
				console.log( 'square create payment result', result );
				if (result.success) {
					jQuery('#sunshine--checkout form').append('<input type="hidden" name="square_payment_id" value="' + result.data.payment_id + '" />');
					resolve(result);
				} else {
					jQuery('#sunshine-square-payment-errors').html('');
					console.log( 'square create payment error shown', result.data.reasons );
					jQuery('#sunshine-square-payment-errors').prepend('<div style="background: red; color: #FFF; padding: 7px 12px;">' + result.data.reasons + '</div>');
					sunshine_checkout_updating_done();
					reject(new Error(result.data.reasons));
				}
			},
			error: function(MLHttpRequest, textStatus, errorThrown) {
				sunshine_checkout_updating_done();
				reject(new Error('Sorry, there was an error with the attempt to process with Square'));
			}
		});
	});
}

// This function tokenizes a payment method.
// The 'error' thrown from this async function denotes a failed tokenization,
// which is due to buyer error (such as an expired card). It is up to the
// developer to handle the error and provide the buyer the chance to fix
// their mistakes.
async function sunshine_square_tokenize( paymentMethod ) {
	const tokenResult = await paymentMethod.tokenize();
	if ( tokenResult.status === 'OK' ) {
		return tokenResult.token;
	} else {
		let errorMessage = `Tokenization failed - status: ${tokenResult.status}`;
		if ( tokenResult.errors ) {
			errorMessage += ` and errors: ${JSON.stringify(
				tokenResult.errors
			)}`;
		}
		throw new Error( errorMessage );
	}
}

// Helper method for displaying the Payment Status on the screen.
// status is either SUCCESS or FAILURE;
function sunshine_square_display_payment_results( status ) {
	jQuery( '#sunshine-square-payment-errors' ).hide();
	if ( status === 'FAILURE' ) {
		jQuery( '#sunshine-square-payment-errors' ).html( status );
		jQuery( '#sunshine-square-payment-errors' ).show();
	}
}

var sunshine_square_card;
jQuery( document ).on( 'sunshine_checkout_payment_change', async function ( event, payment_method ) {
	if ( ! window.Square ) {
		throw new Error( 'Square.js failed to load properly' );
	}

	// Show Square or not
	if ( payment_method == 'square' ) {
		jQuery( '#sunshine-square-payment' ).show();

		if ( sunshine_square_card && jQuery( '#sunshine-square-payment-fields' ).html() ) {
			return;
		}

		const sunshine_square_payments = window.Square.payments( spc_square_vars.application_id, spc_square_vars.location_id );
		try {
			sunshine_square_card = await sunshine_square_init_card( sunshine_square_payments );
		} catch (e) {
			return;
		}

	} else {
		jQuery( '#sunshine-square-payment' ).hide();
	}

});

jQuery( document ).on( 'sunshine_payment_processing', async function( event, data ) {
    const { payment_method, resolve, reject, checkout_data } = data;
	if ( payment_method === 'square' ) {

		if ( jQuery( '#sunshine--checkout form input[name="billing_first_name"]' ).is( ':visible' ) ) {
			square_billing_contact = {
				firstName: jQuery( '#sunshine--checkout form input[name="billing_first_name"]' ).val(),
				lastName: jQuery( '#sunshine--checkout form input[name="billing_last_name"]' ).val(),
				addressLines: [jQuery( '#sunshine--checkout form input[name="billing_address1"]' ).val()],
				city: jQuery( '#sunshine--checkout form input[name="billing_city"]' ).val(),
				state: jQuery( '#sunshine--checkout form input[name="billing_state"]' ).val(),
				postalCode: jQuery( '#sunshine--checkout form input[name="billing_postcode"]' ).val(),
				country: jQuery( '#sunshine--checkout form select[name="billing_country"]' ).val()
			}
		} else {
			if ( checkout_data.shipping_address1 ) {
				square_billing_contact.addressLines = [checkout_data.shipping_address1];
			} else if ( checkout_data.customer_address1 ) {
				square_billing_contact.addressLines = [checkout_data.customer_address1];
			}
			if ( checkout_data.shipping_city ) {
				square_billing_contact.city = checkout_data.shipping_city;
			} else if ( checkout_data.customer_city ) {
				square_billing_contact.city = checkout_data.customer_city;
			}
			if ( checkout_data.shipping_state ) {
				square_billing_contact.state = checkout_data.shipping_state;
			} else if ( checkout_data.customer_state ) {
				square_billing_contact.state = checkout_data.customer_state;
			}
			if ( checkout_data.shipping_postcode ) {
				square_billing_contact.postalCode = checkout_data.shipping_postcode;
			} else if ( checkout_data.customer_postcode ) {
				square_billing_contact.postalCode = checkout_data.customer_postcode;
			}
			if ( checkout_data.shipping_country ) {
				square_billing_contact.country = checkout_data.shipping_country;
			} else if ( checkout_data.customer_country ) {
				square_billing_contact.country = checkout_data.customer_country;
			}
			if ( checkout_data.shipping_first_name ) {
				square_billing_contact.firstName = checkout_data.shipping_first_name;
			} else if ( checkout_data.customer_first_name ) {
				square_billing_contact.firstName = data.customer_first_name;
			}
			if ( checkout_data.shipping_last_name ) {
				square_billing_contact.lastName = checkout_data.shipping_last_name;
			} else if ( checkout_data.customer_last_name ) {
				square_billing_contact.lastName = checkout_data.customer_last_name;
			}
		}
		try {
			const sunshine_square_token = await sunshine_square_tokenize(sunshine_square_card);
			const payments = window.Square.payments(spc_square_vars.application_id, spc_square_vars.location_id);
			const verificationToken = await sunshine_square_verify_buyer(payments, sunshine_square_token);
			await sunshine_square_create_payment(sunshine_square_token, verificationToken);
			resolve();
		} catch (e) {
			//sunshine_square_display_payment_results('FAILURE');
			reject(e);
			sunshine_checkout_updating_done();
		}
	}
});

// ON reload checkout, get the order total.
jQuery( document ).on( 'sunshine_reload_checkout', function( event, data ) {
	if ( data.total ) {
		spc_square_vars.total = data.total;
	}
});

