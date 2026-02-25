window.sunshinePaymentGateways['stripe'] = true;

var sunshine_stripe = Stripe( spc_stripe_vars.publishable_key, { stripeAccount: spc_stripe_vars.account_id, locale: 'auto' } );

var sunshine_stripe_options = {};
var sunshine_stripe_elements;
var sunshine_stripe_payment_element;
var sunshine_stripe_element_mounted = false;
var sunshine_stripe_payment_intent_created = false;

jQuery( document ).on( 'sunshine_checkout_payment_change', async function ( event, payment_method ) {

	if ( payment_method == 'stripe' ) {

		jQuery( '#sunshine-stripe-payment' ).show();

		// Reset state when Stripe is selected
		sunshine_stripe_element_mounted = false;
		sunshine_stripe_payment_intent_created = false;
		
		// Setup payment intent.
		var testDelay = new URLSearchParams( window.location.search ).get( 'test_delay' );
		jQuery.ajax({
			url: spc_stripe_vars.ajax_url,
			type: 'POST',
			data: {
				action: 'sunshine_stripe_create_payment_intent',
				test_delay: testDelay // Testing delay parameter
			},
			timeout: 15000, // 15 second timeout for payment intent creation
		}).then(function(result) {

			sunshine_stripe_options.clientSecret = result.data.client_secret;
			
			// Always create new elements instance for fresh state
			if (sunshine_stripe_elements) {
				try {
					sunshine_stripe_elements.destroy();
				} catch (error) {
					// Silent cleanup of old elements
				}
			}
			
			sunshine_stripe_elements = sunshine_stripe.elements( sunshine_stripe_options );

			// Always create new payment element for fresh state
			if (sunshine_stripe_payment_element) {
				try {
					sunshine_stripe_payment_element.destroy();
				} catch (error) {
					// Silent cleanup of old payment element
				}
			}

		const payment_options = {
			layout: {
				type: spc_stripe_vars.layout,
			}
		};
		
		try {
			sunshine_stripe_payment_element = sunshine_stripe_elements.create( 'payment', payment_options );
			
			// Listen for when the element is fully ready and interactive
			sunshine_stripe_payment_element.on('ready', function() {
				// Remove loading message only when Stripe form is actually ready
				jQuery('#sunshine-stripe-payment-loading').remove();
				console.log('Stripe payment element is ready');
			});
			
			// Fallback: Remove loading message after 10 seconds if ready event never fires
			setTimeout(function() {
				if (jQuery('#sunshine-stripe-payment-loading').length) {
					console.warn('Stripe ready event did not fire, removing loading message anyway');
					jQuery('#sunshine-stripe-payment-loading').remove();
				}
			}, 10000);
			
			// Mount the element
			sunshine_stripe_payment_element.mount( '#sunshine-stripe-payment-fields' );
			sunshine_stripe_element_mounted = true;
			sunshine_stripe_payment_intent_created = true;
			
		} catch (error) {
			sunshine_stripe_element_mounted = false;
			// Remove loading message on error
			jQuery('#sunshine-stripe-payment-loading').remove();
		}

	}).catch(function(error) {
		sunshine_stripe_payment_intent_created = false;
		console.error('Stripe payment intent creation failed:', error);
		// Remove loading message
		jQuery('#sunshine-stripe-payment-loading').remove();
		// Show user-friendly error message
		if (jQuery('#sunshine-stripe-payment-errors').length) {
			jQuery('#sunshine-stripe-payment-errors').html('<div style="background:red;padding:15px;color:#FFF;margin:10px 0;">Payment form initialization failed. Please refresh the page and try again.</div>');
		}
	});

	} else {
		jQuery( '#sunshine-stripe-payment' ).hide();
		// Don't unmount here - let the checkout reload handle it
	}

});

jQuery( document ).on( 'click', '.sunshine--checkout--section-edit', function() {
	if ( sunshine_stripe_payment_element && sunshine_stripe_element_mounted ) {
		try {
			sunshine_stripe_payment_element.unmount();
			sunshine_stripe_element_mounted = false;
		} catch (error) {
			// Silent unmounting
		}
	}
});

// Handle checkout reload to reset Stripe state
jQuery( document ).on( 'sunshine_reload_checkout', function( event, data ) {
	
	// Reset Stripe state when checkout is reloaded
	sunshine_stripe_element_mounted = false;
	sunshine_stripe_payment_intent_created = false;
	
	// Clear references to old elements
	if (sunshine_stripe_elements) {
		sunshine_stripe_elements = null;
	}
	if (sunshine_stripe_payment_element) {
		sunshine_stripe_payment_element = null;
	}
});

jQuery( document ).on( 'sunshine_payment_processing', function( event, data ) {
	const { payment_method, resolve, reject } = data;

	if ( payment_method === 'stripe' ) {

		// Prevent double submission - check if already processing
		var $submitBtn = jQuery('#sunshine--checkout--submit');
		if ($submitBtn.data('stripe-processing')) {
			console.log('Stripe payment already in progress, ignoring duplicate submission');
			reject(new Error('Payment already in progress'));
			return;
		}

		// Mark as processing and disable button to prevent duplicate clicks
		$submitBtn.data('stripe-processing', true);
		var originalButtonText = $submitBtn.html();
		$submitBtn.prop('disabled', true).html('Processing payment...');

		// Helper function to reset button state on error
		function resetButtonState() {
			$submitBtn.data('stripe-processing', false);
			$submitBtn.prop('disabled', false).html(originalButtonText);
		}

		// Validate Stripe elements state before proceeding
		if (!sunshine_stripe_elements) {
			jQuery( '#sunshine-stripe-payment-errors' ).html( '<div style="background:red;padding:15px;color:#FFF;margin:10px 0;">' + spc_stripe_vars.strings.elements_not_available + '</div>' );
			sunshine_checkout_updating_done();
			resetButtonState();
			reject( new Error( 'Stripe elements not available' ) );
			return;
		}

		if (!sunshine_stripe_payment_element) {
			jQuery( '#sunshine-stripe-payment-errors' ).html( '<div style="background:red;padding:15px;color:#FFF;margin:10px 0;">' + spc_stripe_vars.strings.payment_element_not_available + '</div>' );
			sunshine_checkout_updating_done();
			resetButtonState();
			reject( new Error( 'Stripe payment element not available' ) );
			return;
		}

		if (!sunshine_stripe_element_mounted) {
			jQuery( '#sunshine-stripe-payment-errors' ).html( '<div style="background:red;padding:15px;color:#FFF;margin:10px 0;">' + spc_stripe_vars.strings.payment_element_not_mounted + '</div>' );
			sunshine_checkout_updating_done();
			resetButtonState();
			reject( new Error( 'Stripe payment element not mounted' ) );
			return;
		}

		if (!sunshine_stripe_payment_intent_created) {
			jQuery( '#sunshine-stripe-payment-errors' ).html( '<div style="background:red;padding:15px;color:#FFF;margin:10px 0;">' + spc_stripe_vars.strings.payment_intent_not_created + '</div>' );
			sunshine_checkout_updating_done();
			resetButtonState();
			reject( new Error( 'Stripe payment intent not created' ) );
			return;
		}

		jQuery( '#sunshine-stripe-payment-errors' ).html( '' );

		// Do Stripe-specific processing
		const paymentTimeout = setTimeout(() => {
			jQuery('#sunshine-stripe-payment-errors').html('<div style="background:red;padding:15px;color:#FFF;margin:10px 0;">Payment processing is taking longer than expected. Please wait or refresh the page and try again.</div>');
			sunshine_checkout_updating_done();
			resetButtonState();
			reject(new Error('Payment confirmation timeout'));
		}, 15000); // 15 second timeout for payment confirmation

		sunshine_stripe.confirmPayment({
			elements: sunshine_stripe_elements,
			confirmParams: {
				return_url: spc_stripe_vars.return_url,
			},
			redirect: 'if_required'
		})
		.then(function(result) {
			// Clear the timeout since we got a response
			clearTimeout(paymentTimeout);

			// Send ajax request with the result of this confirmpayment solely to log it.
			jQuery.ajax({
				url: spc_stripe_vars.ajax_url,
				type: 'POST',
				data: {
					action: 'sunshine_stripe_log_payment',
					result: result
				},
				timeout: 15000 // 15 second timeout for logging
			});

			// Handle explicit errors
			if ( result.error ) {
				jQuery( '#sunshine-stripe-payment-errors').html( '<div style="background:red;padding:15px;color:#FFF;margin:10px 0;">' + result.error.message + '</div>' );
				sunshine_checkout_updating_done();
				resetButtonState();
				reject( result );
				return;
			}

			// Ensure we have a paymentIntent
			if ( ! result.paymentIntent ) {
				jQuery( '#sunshine-stripe-payment-errors' ).html( '<div style="background:red;padding:15px;color:#FFF;margin:10px 0;">' + spc_stripe_vars.strings.payment_not_processed + '</div>' );
				sunshine_checkout_updating_done();
				resetButtonState();
				reject( new Error( 'No payment intent returned' ) );
				return;
			}

			var status = result.paymentIntent.status;

		// ONLY resolve on true success
		if ( status === 'succeeded' ) {
			// Add payment intent ID to form before submission
			var $form = jQuery( '#sunshine--checkout--form' );
			if ( $form.length ) {
				// Remove any existing stripe_payment_intent_id fields (in case of retry)
				$form.find( 'input[name="stripe_payment_intent_id"]' ).remove();
				// Add the payment intent ID
				$form.prepend( '<input type="hidden" name="stripe_payment_intent_id" value="' + result.paymentIntent.id + '" />' );
				console.log( 'Added payment_intent_id to form: ' + result.paymentIntent.id );
				$form.submit();
			} else {
				console.error( 'Form #sunshine--checkout--form not found!' );
			}
			resolve( result );
		} else {
				// Everything else is treated as an error
				let errorMessage = spc_stripe_vars.strings.payment_did_not_succeed;
				
				// Try to get a specific error message
				if ( result.paymentIntent.last_payment_error && result.paymentIntent.last_payment_error.message ) {
					errorMessage = result.paymentIntent.last_payment_error.message;
				}

				jQuery( '#sunshine-stripe-payment-errors' ).html( '<div style="background:red;padding:15px;color:#FFF;margin:10px 0;">' + errorMessage + '</div>' );
				sunshine_checkout_updating_done();
				reject( result );
			}
		})
		.catch(function(error) {
			// Clear the timeout since we got an error
			clearTimeout(paymentTimeout);
			jQuery( '#sunshine-stripe-payment-errors' ).html( '<div style="background:red;padding:15px;color:#FFF;margin:10px 0;">' + spc_stripe_vars.strings.payment_processing_failed + error.message + '</div>' );
			sunshine_checkout_updating_done();
			reject( error );
		});

	}
});
