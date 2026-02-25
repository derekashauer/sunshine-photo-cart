<?php if ( isset( $_GET['license'] ) ) { ?>
<div class="sunshine-install--license-activated" style="background:green;padding: 5px 20px; text-align: center; color: #FFF; border-radius: 5px; max-width: 750px; margin: 0 auto -50px auto;">
	<p>Your license key has been activated</p>
</div>
<?php } ?>

<div id="sunshine-install--data" class="sunshine-install--step">
	<form method="post" action="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install&step=data' ) ); ?>">
		<?php echo wp_nonce_field( 'sunshine_install_data', 'sunshine_install_data' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<h2>Analytics & Data</h2>
		<p>By opting-in, you allow some basic data about how you use Sunshine Photo Cart to help improve the plugin for you and others.  If you skip this step, that's okay! Sunshine Photo Cart will still be set up for you no problem.</p>
		<p>If you agree, the information will be sent to and stored on sunshinephotocart.com solely for research purposes and will not be sold or given to any 3rd party.</p>
		<p style="font-weight: bold;">No image files, their metadata, or personally identifiable information about your customers will be tracked in any way.</p>
		<p>Sites that opt in may be featured in our Examples back with a back link by manual review or notified if there are any suggestions for improvement to your usage of the plugin.</p>
		<p><a href="https://www.sunshinephotocart.com/usage-tracking/" target="_blank">Learn more about what is tracked</a></p>

		<div class="sunshine-install--step--actions">
			<p><button class="button-primary large" type="submit">Accept & Continue</button></p>
			<p style="font-size:16px;"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install&step=updates' ) ); ?>" class="sunshine-install--continue">Skip this step</a></p>
		</div>

	</form>

</div>
