<div id="sunshine-upgrade">
	<div id="sunshine-upgrade--header">
		<div id="sunshine-upgrade--header--content">
			<h2>Make more money <em>with our add-ons!</em></h2>
			<p>With our professional add-ons, you can grow your photography business and profits — <strong>cover the cost with just one customer!</strong></p>
			<p>
				<a href="https://www.sunshinephotocart.com/upgrade/?utm_source=plugin&utm_medium=link&utm_campaign=onboarding" class="button-primary large" target"_blank" style="margin-top: 15px;">Go Pro!</a>
				<br />
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install&step=updates' ) ); ?>" style="font-size: 14px;">Not now, I will check this out later</a>
			</p>
		</div>
	</div>
	<ul id="sunshine-upgrade--list">
		<?php
		$addons       = sunshine_get_addon_data( true ); // TODO: Do not force this.
		$addons_total = 0;
		foreach ( $addons as $addon ) {
			$addons_total += $addon['price'];
			?>
		<li>
			<a href="<?php echo esc_url( $addon['url'] ); ?>?utm_source=plugin&utm_medium=link&utm_campaign=onboarding" target="_blank">
				<h3>
					<img src="<?php echo esc_url( $addon['image'] ); ?>" alt="<?php echo esc_attr( $addon['title'] ); ?>" />
					<?php echo esc_html( $addon['title'] ); ?>
				</h3>
				<p><?php echo esc_html( $addon['excerpt'] ); ?></p>
			</a>
		</li>
		<?php } ?>
	</ul>

	<div id="sunshine-upgrade--cta">
		<p>
			Save <?php echo esc_html( round( 100 - ( ( 249 / $addons_total ) * 100 ) ) ); ?>% and get every add-on for <em>only $249!</em><br />
			<a href="https://www.sunshinephotocart.com/upgrade/?utm_source=plugin&utm_medium=link&utm_campaign=onboarding" class="button-primary large" target"_blank" style="margin-top: 15px;">Go Pro!</a>
		</p>
		<ul>
			<li>
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 22 24">
					<g transform="translate(-34.133 -25.6)">
					  <g transform="translate(34.133 25.6)">
						<path d="M45.133,25.6a51.219,51.219,0,0,0-9.389,1.037l0,0A2,2,0,0,0,34.133,28.6v6c0,10.4,10.021,14.744,10.021,14.744a1.992,1.992,0,0,0,1.953,0h0S56.133,45,56.133,34.6v-6a2,2,0,0,0-1.611-1.963A51.219,51.219,0,0,0,45.133,25.6Zm6,6a1,1,0,0,1,.707,1.707L44.28,40.868a1,1,0,0,1-1.414,0l-3.453-3.453A1,1,0,0,1,40.827,36l2.746,2.746,6.854-6.854a1,1,0,0,1,.707-.293Z" transform="translate(-34.133 -25.6)" fill="#ffb600"/>
					  </g>
					</g>
				  </svg>
				  14-day money back guarantee
			</li>
			<li>
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24.96 24">
					<g transform="translate(-17.067 -25.6)">
					  <g transform="translate(17.067 25.6)">
						<path d="M27.627,25.6c-5.832,0-10.56,4.3-10.56,9.6a9.174,9.174,0,0,0,3.259,6.924,5.341,5.341,0,0,1-1.987,2.706l0,0a.479.479,0,0,0,.272.917,7.653,7.653,0,0,0,4.7-1.792,11.4,11.4,0,0,0,4.314.844c5.832,0,10.56-4.3,10.56-9.6s-4.728-9.6-10.56-9.6Zm-3.84,6.72h7.68a.96.96,0,1,1,0,1.92h-7.68a.96.96,0,1,1,0-1.92Zm0,3.84h5.76a.96.96,0,0,1,0,1.92h-5.76a.96.96,0,0,1,0-1.92Zm16.106,1.119A12.153,12.153,0,0,1,28.9,46.66a8.238,8.238,0,0,0,5.443,1.98,8.608,8.608,0,0,0,2.865-.489,7.414,7.414,0,0,0,4.237,1.438.479.479,0,0,0,.264-.919,5.6,5.6,0,0,1-1.781-2.149,6.107,6.107,0,0,0-.039-9.242Z" transform="translate(-17.067 -25.6)" fill="#ffb600"/>
					  </g>
					</g>
				  </svg>
				  1-on-1 customer support
			</li>
			<li>
				<svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" viewBox="0 0 48 48">
				<path d="M 8.5 6 C 6.0324991 6 4 8.0324991 4 10.5 L 4 30.5 C 4 32.967501 6.0324991 35 8.5 35 L 17 35 L 17 40 L 13.5 40 A 1.50015 1.50015 0 1 0 13.5 43 L 34.5 43 A 1.50015 1.50015 0 1 0 34.5 40 L 31 40 L 31 35 L 39.5 35 C 41.967501 35 44 32.967501 44 30.5 L 44 10.5 C 44 8.0324991 41.967501 6 39.5 6 L 8.5 6 z M 8.5 9 L 39.5 9 C 40.346499 9 41 9.6535009 41 10.5 L 41 30.5 C 41 31.346499 40.346499 32 39.5 32 L 8.5 32 C 7.6535009 32 7 31.346499 7 30.5 L 7 10.5 C 7 9.6535009 7.6535009 9 8.5 9 z M 24 12 C 21.794 12 20 13.794 20 16 C 20 18.206 21.794 20 24 20 C 26.206 20 28 18.206 28 16 C 28 13.794 26.206 12 24 12 z M 18.75 22 C 17.783 22 17 22.783 17 23.75 L 17 24.917969 C 17 27.172969 20.134 29 24 29 C 27.866 29 31 27.172969 31 24.917969 L 31 23.75 C 31 22.783 30.217 22 29.25 22 L 18.75 22 z M 20 35 L 28 35 L 28 40 L 20 40 L 20 35 z"></path>
			</svg>
				  15-min onboarding video call
			</li>
		</ul>
	</div>
</div>

<p style="margin-top: 50px; text-align: center;"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-install&step=updates' ) ); ?>" class="button">No thanks, I do not want a better client gallery experience</a></p>
