<div id="sunshine-install--updates" class="sunshine-install--step">
	<form method="post" action="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install&step=updates' ) ); ?>">
		<?php echo wp_nonce_field( 'sunshine_install_updates', 'sunshine_install_updates' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<h2>Stay up-to-date with Sunshine Photo Cart</h2>
		<p>Join our email newsletter to receive important updates, new features, photo selling tips and how to make the most of Sunshine Photo Cart (you can always unsubscribe).</p>
		<p id="sunshine-install--updates--fields">
			<?php $current_user = wp_get_current_user(); ?>
			<label>
				First name<br />
				<input type="text" required name="first_name" value="<?php echo esc_attr( $current_user->first_name ); ?>" placeholder="First name" />
			</label>
			<label>
				Email
				<input type="email" required name="email" value="<?php echo esc_attr( $current_user->user_email ); ?>" placeholder="Email" />
			</label>
		</p>

		<p style="margin-top: 40px;">You can also follow Sunshine Photo Cart on:</p>
		<ul>
			<li><a href="https://www.facebook.com/sunshinephotocart" target="_blank"><span class="dashicons dashicons-facebook"></span> Facebook Page</a> Get updates and latest news</li>
			<li><a href="https://www.facebook.com/groups/258307436912052" target="_blank"><span class="dashicons dashicons-facebook"></span> Facebook Group</a> Talk with fellow photographers</li>
			<li><a href="https://www.youtube.com/@wpsunshine" target="_blank"><span class="dashicons dashicons-youtube"></span> YouTube</a> How-to videos and guides</li>
		</ul>

		<div class="sunshine-install--step--actions">
			<p><button class="button-primary large" type="submit">Join & Continue</button></p>
			<p style="font-size:16px;"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install&step=guide' ) ); ?>" class="sunshine-install--continue">Skip step</a></p>
		</div>

	</form>

</div>
