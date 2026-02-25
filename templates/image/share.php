<div id="sunshine--image--share--url">
	<input type="text" id="image_url" name="image_url" value="<?php echo esc_attr( $image->get_permalink() ); ?>" />
	<button><?php esc_html_e( 'Copy', 'sunshine-photo-cart' ); ?></button>
</div>
<script>
jQuery( 'body' ).on( 'click', '#sunshine--image--share--url button', function(){
	var copyText = document.getElementById( "image_url" );
	copyText.select();
	document.execCommand( "copy" );
	jQuery( this ).html( '<?php echo esc_js( __( 'Copied!', 'sunshine-photo-cart' ) ); ?>' );
});
</script>

<div id="sunshine--image--share--services">
	<a href="http://www.facebook.com/sharer.php?u=<?php echo urlencode( $image->get_permalink() ); ?>" target="_blank" id="sunshine--facebook">Facebook</a>
	<a href="https://twitter.com/intent/tweet?url=<?php echo urlencode( $image->get_permalink() ); ?>" target="_blank" id="sunshine--twitter">Twitter</a>
	<a href="http://pinterest.com/pin/create/button/?url=<?php echo urlencode( $image->get_permalink() ); ?>&media=<?php echo esc_url( $image->get_image_url( 'full' ) ); ?>&description=<?php echo esc_html( $image->get_name() ); ?>" target="_blank" id="sunshine--pinterest">Pinterest</a>
	<a href="https://wa.me/?text=<?php echo urlencode( $image->get_permalink() ); ?>" target="_blank" id="sunshine--whatsapp">WhatsApp</a>
</div>
