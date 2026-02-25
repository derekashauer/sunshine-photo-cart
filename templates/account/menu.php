<div id="sunshine--account--menu">
	<nav>
		<ul>
		<?php foreach ( sunshine_get_account_menu_items() as $key => $item ) : ?>
			<li class="sunshine--account--menu--item--<?php echo esc_attr( $key ); ?>">
				<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
			</li>
		<?php endforeach; ?>
		</ul>
	</nav>
</div>
