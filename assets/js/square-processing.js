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

		// Prevent double submission and give the buyer clear feedback while the
		// charge is in flight. Without this, a slow charge can tempt the buyer to
		// click again or refresh, which re-tokenizes the card and trips Square's
		// "Different request parameters used for the same idempotency_key" error.
		var $sunshineSquareSubmit = jQuery( '#sunshine--checkout--submit' );
		if ( $sunshineSquareSubmit.data( 'square-processing' ) ) {
			reject( new Error( 'Payment already in progress' ) );
			return;
		}
		$sunshineSquareSubmit.data( 'square-processing', true );
		var sunshineSquareBtnText = $sunshineSquareSubmit.html();
		$sunshineSquareSubmit.prop( 'disabled', true ).html( 'Processing payment...' );

		function sunshineSquareResetButton() {
			$sunshineSquareSubmit.data( 'square-processing', false );
			$sunshineSquareSubmit.prop( 'disabled', false ).html( sunshineSquareBtnText );
		}

		// The billing address is collected at its own step, which is already completed and
		// closed by the time the payment runs, so its inputs are no longer on the page to read
		// from. Take it from the checkout data the server sends back instead, which is where
		// the address ends up whether the buyer typed one or reused their shipping address.
		function sunshineSquareAddressValue( field ) {
			if ( checkout_data[ 'billing_' + field ] ) {
				return checkout_data[ 'billing_' + field ];
			}
			if ( checkout_data[ 'shipping_' + field ] ) {
				return checkout_data[ 'shipping_' + field ];
			}
			return '';
		}

		square_billing_contact = {};

		var sunshineSquareAddress1 = sunshineSquareAddressValue( 'address1' );
		if ( sunshineSquareAddress1 ) {
			square_billing_contact.addressLines = [ sunshineSquareAddress1 ];
		}

		jQuery.each(
			{
				city: 'city',
				state: 'state',
				postalCode: 'postcode',
				country: 'country',
				firstName: 'first_name',
				lastName: 'last_name'
			},
			function( contactKey, field ) {
				var value = sunshineSquareAddressValue( field );
				if ( value ) {
					square_billing_contact[ contactKey ] = value;
				}
			}
		);
		try {
			const sunshine_square_token = await sunshine_square_tokenize(sunshine_square_card);
			const payments = window.Square.payments(spc_square_vars.application_id, spc_square_vars.location_id);
			const verificationToken = await sunshine_square_verify_buyer(payments, sunshine_square_token);
			await sunshine_square_create_payment(sunshine_square_token, verificationToken);
			// Leave the button disabled on success - the form is about to submit
			// and navigate away to the receipt page.
			resolve();
		} catch (e) {
			//sunshine_square_display_payment_results('FAILURE');
			reject(e);
			sunshineSquareResetButton();
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

