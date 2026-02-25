<h1>Setup Guide</h1>
<p class="sunshine-install--tagline">The following important areas need to each be configured to complete your setup of Sunshine Photo Cart and start accepting orders.</p>
<p style="margin-top: 40px;"><a href="https://www.sunshinephotocart.com/docs/setup-guide/" class="button large" target="_blank"><i class="sunshine--icon--page"></i> Read detailed setup guide with videos</a></p>

<div class="sunshine-install--step" id="sunshine-install--guide">
	<ol>

		<?php if ( ! SPC()->get_option( 'address1' ) ) { ?>
			<li>
				<div>
					<p>Start configuring your store including address, pages, URLs, and more...</p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine' ) ); ?>" class="button">See settings</a>
				</div>
			</li>
		<?php } else { ?>
			<li class="completed">Store Configuration</li>
		<?php } ?>

		<?php if ( empty( sunshine_get_active_payment_methods() ) ) { ?>
			<li>
				<div>
					<p>Configure payment methods to start receiving money</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine&section=payment_methods' ) ); ?>" class="button">Select payment methods</a>
				</div>
			</li>
		<?php } else { ?>
			<li class="completed">Payment methods</li>
		<?php } ?>

		<?php if ( empty( sunshine_get_active_shipping_methods() ) ) { ?>
			<li>
				<div>
					<p>Configure shipping methods to get orders to customers</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine&section=shipping_methods' ) ); ?>" class="button">Setup shipping methods</a>
				</div>
			</li>
		<?php } else { ?>
			<li class="completed">Shipping methods</li>
		<?php } ?>

		<?php if ( wp_count_posts( 'sunshine-product' )->publish <= 0 ) { ?>
			<li>
				<div>
					<p>Create products and set prices to start selling</p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-product' ) ); ?>" class="button">Add products</a>
				</div>
			</li>
		<?php } else { ?>
			<li class="completed">Products</li>
		<?php } ?>

		<?php if ( ! SPC()->get_option( 'logo' ) ) { ?>
			<li>
				<div>
					<p>Customize the look of Sunshine with your logo and other options</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=sunshine&section=display' ) ); ?>" class="button">Configure display & branding options</a>
				</div>
			</li>
		<?php } else { ?>
			<li class="completed">Branding</li>
		<?php } ?>

		<?php if ( ! SPC()->is_pro() ) { ?>
			<li>
				<div>
					<p>Upgrade for more features to help increase revenue</p>
					<a href="https://www.sunshinephotocart.com/upgrade/?utm_source=plugin&utm_medium=link&utm_campaign=onboarding" class="button-primary alt" target="_blank">Learn more about Pro</a>
				</div>
			</li>
		<?php } ?>

	</ol>
</div>
