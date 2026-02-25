<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
		<title><?php bloginfo( 'name' ); ?></title>
	</head>
	<body <?php echo is_rtl() ? 'rightmargin' : 'leftmargin'; ?>="0" marginwidth="0" topmargin="0" marginheight="0" offset="0" id="email-<?php echo esc_attr( $template ); ?>">
		<div id="wrapper" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
			<table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%">
				<tr>
					<td id="logo">
						<?php
						if ( $attachment_id = SPC()->get_option( 'email_logo' ) ) {
							echo '<img src="' . esc_url( wp_get_attachment_image_url( $attachment_id, 'medium' ) ) . '" alt="' . esc_attr( get_bloginfo( 'name', 'display' ) ) . '" />';
						} else {
							bloginfo( 'name' );
						}
						?>
					</td>
				</tr>
				<tr>
					<td>

						<table border="0" cellpadding="0" cellspacing="0" id="main">
							<?php if ( ! empty( $heading ) ) { ?>
							<tr>
								<td align="center" valign="top">
									<!-- Header -->
									<table border="0" cellpadding="0" cellspacing="0" width="100%" id="header">
										<tr>
											<td>
												<h1><?php echo esc_html( $heading ); ?></h1>
											</td>
										</tr>
									</table>
									<!-- End Header -->
								</td>
							</tr>
							<?php } ?>
							<tr>
								<td align="center" valign="top" id="content">

									<?php do_action( 'sunshine_before_email_content' ); ?>
