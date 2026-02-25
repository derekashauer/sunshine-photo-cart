<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<!DOCTYPE html>
<html>
<head>
	<title><?php echo esc_html( wp_title() ); ?></title>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>

<?php if ( SPC()->frontend->is_gallery() && ! SPC()->frontend->is_store() ) { ?>
	<div id="sunshine--cover">
		<div id="sunshine--cover--image"><?php SPC()->frontend->current_gallery->featured_image( 'sunshine-cover' ); ?></div>
		<div id="sunshine--cover--content">
			<div id="sunshine--cover--content--title"><?php echo esc_html( SPC()->frontend->current_gallery->get_name() ); ?></div>
			<a href="#sunshine" class="sunshine--button"><?php esc_html_e( 'View gallery', 'sunshine-photo-cart' ); ?></a>
		</div>
	</div>
<?php } ?>

<header id="sunshine--header">

	<div id="sunshine--logo">
		<?php
		if ( $attachment_id = SPC()->get_option( 'logo' ) ) {
			echo '<img src="' . esc_url( wp_get_attachment_image_url( $attachment_id, 'large' ) ) . '" alt="' . esc_attr( get_bloginfo( 'name', 'display' ) ) . '" />';
		} else {
			bloginfo( 'name' );
		}
		?>
	</div>

	<?php sunshine_main_menu(); ?>

</header>

<main id="sunshine" class="<?php sunshine_classes(); ?>">

	<?php sunshine_action_menu(); ?>
