<html>
<head>
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>

	<?php wp_body_open(); ?>

	<div id="sunshine--checkout--standalone">
		<h1><?php the_title(); ?></h1>
		<?php
		SPC()->notices->show();
		do_action( 'sunshine_checkout' );
		?>
	</div>

	<?php wp_footer(); ?>

</body>
</html>
