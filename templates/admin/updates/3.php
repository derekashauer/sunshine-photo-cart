<div class="sunshine-install--step" id="sunshine-update-3" style="position:relative;">

	<h2 style="color: red; line-height: 1.1;">Important! Sunshine 3 is a very large update, please read:</h2>
	<p>Much of the data in Sunshine 3 has been re-organized. An update process must be run to convert existing data for you. <strong>It is recommended to make a full back up of your entire website before performing this action.</strong></p>
	<p>The update process will work in phases and requires you to keep your browser window open until it is completed. If something happens during the update, you can reload this page, restart the process and it will pick up where it left off.</p>

	<div id="reload" style="position: absolute; z-index: 10000; top:0;left:0;right:0;bottom:0; background: rgba(255,255,255,.95);display:none;">
		<div style="position: relative; top: 50%; transform:translateY(-50%);font-size: 22px; text-align: center;">
			<p style="font-size:24px; color:red;font-weight:bold;">An error occured. Try reloading the page to restart the update process.</p>
			<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-update' ) ); ?>" class="button-primary">Reload</a></p>
			<p style="color: #666;">If you are seeing this message repeatedly, <a href="https://www.sunshinephotocart.com/support" target="_blank">contact support</a></p>
		</div>
	</div>

	<p><strong>The update has been broken into steps:</strong></p>

	<div id="settings" class="sunshine3-step">
		<h3>General Settings</h3>
		<?php if ( ! SPC()->get_option( 'update_3_settings' ) ) { ?>
			<p><button id="settings-start" class="button-primary">Start Update</button></p>
		<?php } else { ?>
			<p>All settings have been updated</p>
		<?php } ?>
	</div>

	<div id="customers" class="sunshine3-step">
		<h3>Customers</h3>
		<?php if ( ! SPC()->get_option( 'update_3_customers' ) ) { ?>
			<p><button id="customers-start" class="button-primary">Start Update</button></p>
		<?php } else { ?>
			<p>All customers have been updated</p>
		<?php } ?>
	</div>

	<div id="products" class="sunshine3-step">
		<h3>Products</h3>
		<?php if ( ! SPC()->get_option( 'update_3_products' ) ) { ?>
			<p><button id="products-start" class="button-primary">Start Update</button></p>
		<?php } else { ?>
			<p>All products have been updated</p>
		<?php } ?>
	</div>

	<div id="discounts" class="sunshine3-step">
		<h3>Discounts</h3>
		<?php if ( ! SPC()->get_option( 'update_3_discounts' ) ) { ?>
			<p><button id="discounts-start" class="button-primary">Start Update</button></p>
		<?php } else { ?>
			<p>All discounts have been updated </p>
		<?php } ?>
	</div>

	<div id="emails" class="sunshine3-step">
		<h3>Emails</h3>
		<?php if ( ! SPC()->get_option( 'update_3_emails' ) ) { ?>
			<p><button id="emails-start" class="button-primary">Start Update</button></p>
		<?php } else { ?>
			<p>All emails have been updated</p>
		<?php } ?>
	</div>

	<div id="orders" class="sunshine3-step">
		<h3>Orders</h3>
		<?php if ( ! SPC()->get_option( 'update_3_orders' ) ) { ?>
			<p><button id="orders-start" class="button-primary">Start Update</button></p>
		<?php } else { ?>
			<p>All orders have been updated</p>
		<?php } ?>
	</div>

	<div id="galleries-common" class="sunshine3-step">
		<h3>Galleries Step 1</h3>
		<?php if ( ! SPC()->get_option( 'update_3_galleries_common' ) ) { ?>
			<p><button id="galleries-common-start" class="button-primary">Start Update</button></p>
		<?php } else { ?>
			<p>All common gallery data has been updated</p>
		<?php } ?>
	</div>

	<div id="galleries" class="sunshine3-step">
		<h3>Galleries Step 2</h3>
		<?php if ( ! SPC()->get_option( 'update_3_galleries' ) ) { ?>
			<p><button id="galleries-start" class="button-primary">Start Update</button></p>
		<?php } else { ?>
			<p>All galleries have been updated</p>
		<?php } ?>
	</div>

	<div id="images" class="sunshine3-step">
		<h3>Images</h3>
		<?php if ( ! SPC()->get_option( 'update_3_images' ) ) { ?>
			<p><button id="images-start" class="button-primary">Start Update</button></p>
		<?php } else { ?>
			<p>All images have been updated</p>
		<?php } ?>
	</div>

	<?php if ( isset( $_GET['force'] ) ) { ?>
		<h2>Extra update functions</h2>
		<div id="galleries-duplicate-meta" class="sunshine3-step">
			<h3>Galleries Duplicate Meta</h3>
			<?php if ( ! SPC()->get_option( 'update_3_galleries_duplicate_meta' ) ) { ?>
				<p><button id="galleries-duplicate-meta-start" class="button-primary">Start Update</button></p>
			<?php } else { ?>
				<p>All galleries have been updated <button id="galleries-duplicate-meta-start" class="button">Run again</button></p>
			<?php } ?>
		</div>
	<?php } ?>

</div>

<div style="display: none;" id="sunshine-update-3-complete" class="sunshine-install--step">
	<p style="font-size: 20px; font-weight: bold;">Update is complete - You can now enjoy Sunshine 3!</p>
	<ul id="sunshine-3-update-notices" style="list-style: disc; margin-left: 25px; color: blue;"></ul>
	<p>Optionally, you may clean up any old data from Sunshine 2 and other data used to perform this update process. It is recommended to verify everything looks accurate and works well before performing this clean up action as it cannot be undone. You can find this clean up in the <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sunshine-gallery&page=sunshine-tools' ) ); ?>">Tools section</a> and perform it later if you wish.</p>
	<button class="button" type="button" onclick="sunshine_update_3_cleanup();" id="sunshine-update-3-cleanup">Clean up old data</button>
</div>


<?php $nonce = wp_create_nonce( 'sunshine_update_3' ); ?>

<script>

const batch = <?php echo ( ! empty( $_GET['batch'] ) ) ? intval( $_GET['batch'] ) : 0; ?>;

/***********
 Settings
***********/
jQuery( '#settings-start' ).on( 'click', function(){
	jQuery( '#settings p' ).replaceWith( '<p><img src="images/spinner.gif" alt="" class="sunshine-processing" /></p>' );
	updateSettings();
});

function updateSettings() {
	return new Promise((resolve, reject) => {
	  jQuery.ajax({
		url: ajaxurl,
		type: 'POST',
		data: {
		  action: 'sunshine_update_3_settings_data',
		  security: '<?php echo esc_js( $nonce ); ?>',
		},
		success: (response) => {
		  jQuery( '.sunshine-processing' ).replaceWith( 'Done' );
		  resolve();
		},
		error: (jqXHR, textStatus, errorThrown) => {
			show_error();
		  reject(new Error(`Error updating settings`));
		}
	  });
	});
}

/***********
 Customers
***********/
jQuery( '#customers-start' ).on( 'click', function(){
	jQuery( '#customers p' ).replaceWith( '<p><img src="images/spinner.gif" alt="" class="sunshine-processing" /> Processing... <span class="processed"></span></p>' );
	updateCustomerData();
});

var totalCustomersProcessed = 0;

function updateCustomerData() {

	jQuery.ajax({
	  url: ajaxurl,
	  type: 'POST',
	  data: {
		action: 'sunshine_update_3_customers_update',
		batch: batch,
		security: '<?php echo esc_js( $nonce ); ?>',
	  },
	  success: (response) => {
		  if ( response.success ) {
			  console.log( response );
			  if ( response.data && response.data.updated ) {
				  console.log( 'Customer ID: ' + response.data.updated );
				  // We just updated one, so keep trying.
				  totalCustomersProcessed++;
				  jQuery( '#customers .processed' ).html( totalCustomersProcessed );
				  updateCustomerData();
			  } else {
				  // No update made, we must be done.
				  jQuery( '#customers p' ).html( 'Done!' );
			  }
		  } else {
			  // Failure somehow, stop here.
			  show_error();
		  }

	  },
	  error: (jqXHR, textStatus, errorThrown) => {
		  show_error();
	  }
	});

}

/***********
 Products
***********/
jQuery( '#products-start' ).on( 'click', function(){
	jQuery( '#products p' ).replaceWith( '<p><img src="images/spinner.gif" alt="" class="sunshine-processing" /> Processing... <span class="processed"></span></p>' );
	updateProductData();
});

var totalProductsProcessed = 0;

function updateProductData() {

	jQuery.ajax({
	  url: ajaxurl,
	  type: 'POST',
	  data: {
		action: 'sunshine_update_3_products_update',
		batch: batch,
		security: '<?php echo esc_js( $nonce ); ?>',
	  },
	  success: (response) => {
		  if ( response.success ) {
			  if ( response.data && response.data.updated ) {
				  console.log( 'Product ID: ' + response.data.updated );
				  // We just updated one, so keep trying.
				  totalProductsProcessed++;
				  jQuery( '#products .processed' ).html( totalProductsProcessed );
				  updateProductData();
			  } else {
				  // No update made, we must be done.
				  jQuery( '#products p' ).html( 'Done!' );
			  }
		  } else {
			  // Failure somehow, stop here.
			  show_error();
		  }

	  },
	  error: (jqXHR, textStatus, errorThrown) => {
		  show_error();
	  }
	});

}

/***********
 Discounts
***********/
jQuery( '#discounts-start' ).on( 'click', function(){
	jQuery( '#discounts p' ).replaceWith( '<p><img src="images/spinner.gif" alt="" class="sunshine-processing" /> Processing... <span class="processed"></span></p>' );
	updateDiscountData();
});

var totalDiscountsProcessed = 0;

function updateDiscountData() {

	jQuery.ajax({
	  url: ajaxurl,
	  type: 'POST',
	  data: {
		action: 'sunshine_update_3_discounts_update',
		batch: batch,
		security: '<?php echo esc_js( $nonce ); ?>',
	  },
	  success: (response) => {
		  if ( response.success ) {
			  if ( response.data && response.data.updated ) {
				  console.log( 'Discount ID: ' + response.data.updated );
				  // We just updated one, so keep trying.
				  totalDiscountsProcessed++;
				  jQuery( '#discounts .processed' ).html( totalDiscountsProcessed );
				  updateDiscountData();
			  } else {
				  // No update made, we must be done.
				  jQuery( '#discounts p' ).html( 'Done!' );
			  }
		  } else {
			  // Failure somehow, stop here.
			  show_error();
		  }

	  },
	  error: (jqXHR, textStatus, errorThrown) => {
		  show_error();
	  }
	});

}

/***********
 Emails
***********/
jQuery( '#emails-start' ).on( 'click', function(){
	jQuery( '#emails p' ).replaceWith( '<p><img src="images/spinner.gif" alt="" class="sunshine-processing" /> Processing... <span class="processed"></span></p>' );
	updateEmailData();
});

var totalEmailsProcessed = 0;

function updateEmailData() {

	jQuery.ajax({
	  url: ajaxurl,
	  type: 'POST',
	  data: {
		action: 'sunshine_update_3_emails_update',
		batch: batch,
		security: '<?php echo esc_js( $nonce ); ?>',
	  },
	  success: (response) => {
		  if ( response.success ) {
			  if ( response.data && response.data.updated ) {
				  console.log( 'Email ID: ' + response.data.updated );
				  // We just updated one, so keep trying.
				  totalEmailsProcessed++;
				  jQuery( '#emails .processed' ).html( totalEmailsProcessed );
				  updateEmailData();
			  } else {
				  // No update made, we must be done.
				  jQuery( '#emails p' ).html( 'Done!' );
			  }
		  } else {
			  // Failure somehow, stop here.
			  show_error();
		  }

	  },
	  error: (jqXHR, textStatus, errorThrown) => {
		  show_error();
	  }
	});

}

/***********
 Orders
***********/
jQuery( '#orders-start' ).on( 'click', function(){
	jQuery( '#orders p' ).replaceWith( '<p><img src="images/spinner.gif" alt="" class="sunshine-processing" /> Processing... <span class="processed"></span></p>' );
	updateOrderData();
});

var totalOrdersProcessed = 0;

function updateOrderData() {

	jQuery.ajax({
	  url: ajaxurl,
	  type: 'POST',
	  data: {
		action: 'sunshine_update_3_orders_update',
		batch: batch,
		security: '<?php echo esc_js( $nonce ); ?>',
	  },
	  success: (response) => {
		  if ( response.success ) {
			  if ( response.data && response.data.updated ) {
				  console.log( 'Order ID: ' + response.data.updated );
				  // We just updated one, so keep trying.
				  totalOrdersProcessed++;
				  jQuery( '#orders .processed' ).html( totalOrdersProcessed );
				  updateOrderData();
			  } else {
				  // No update made, we must be done.
				  jQuery( '#orders p' ).html( 'Done!' );
			  }
		  } else {
			  // Failure somehow, stop here.
			  show_error();
		  }

	  },
	  error: (jqXHR, textStatus, errorThrown) => {
		  show_error();
	  }
	});

}

/***********
 Galleries Common
***********/
jQuery( '#galleries-common-start' ).on( 'click', function(){
	jQuery( '#galleries-common p' ).replaceWith( '<p><img src="images/spinner.gif" alt="" class="sunshine-processing" /> Processing... <span class="processed"></span></p>' );
	updateGalleryCommonData();
});

function updateGalleryCommonData() {

	jQuery.ajax({
	  url: ajaxurl,
	  type: 'POST',
	  data: {
		action: 'sunshine_update_3_galleries_common_update',
		batch: batch,
		security: '<?php echo esc_js( $nonce ); ?>',
	  },
	  success: (response) => {
		  if ( response.success ) {
			  if ( response.data && response.data.updated ) {
				  console.log( 'Common data: ' + response.data.updated );
				  // We just updated one, so keep trying.
				  jQuery( '#galleries-common .processed' ).html( response.data.current + ' of ' + response.data.total );
				  updateGalleryCommonData();
			  } else {
				  // No update made, we must be done.
				  jQuery( '#galleries-common p' ).html( 'Done!' );
			  }
		  } else {
			  // Failure somehow, stop here.
			  show_error();
		  }

	  },
	  error: (jqXHR, textStatus, errorThrown) => {
		  show_error();
	  }
	});

}


/***********
 Galleries
***********/
jQuery( '#galleries-start' ).on( 'click', function(){
	jQuery( '#galleries p' ).replaceWith( '<p><img src="images/spinner.gif" alt="" class="sunshine-processing" /> Processing... <span class="processed"></span></p>' );
	updateGalleriesData();
});

var totalGalleriesProcessed = 0;

function updateGalleriesData() {

	jQuery.ajax({
	  url: ajaxurl,
	  type: 'POST',
	  data: {
		action: 'sunshine_update_3_galleries_update',
		batch: batch,
		security: '<?php echo esc_js( $nonce ); ?>',
	  },
	  success: (response) => {
		  if ( response.success ) {
			  if ( response.data && response.data.updated ) {
				  console.log( 'Order ID: ' + response.data.updated );
				  // We just updated one, so keep trying.
				  totalGalleriesProcessed++;
				  jQuery( '#galleries .processed' ).html( totalGalleriesProcessed );
				  updateGalleriesData();
			  } else {
				  // No update made, we must be done.
				  jQuery( '#galleries p' ).html( 'Done!' );
			  }
		  } else {
			  // Failure somehow, stop here.
			  show_error();
		  }

	  },
	  error: (jqXHR, textStatus, errorThrown) => {
		  show_error();
	  }
	});

}


/***********
 Images
***********/
jQuery( '#images-start' ).on( 'click', function(){
	jQuery( '#images p' ).replaceWith( '<p><img src="images/spinner.gif" alt="" class="sunshine-processing" /> Processing... <span class="processed"></span></p>' );
	updateImagesData();
});

var totalImagesProcessed = 0;

function updateImagesData() {


	jQuery.ajax({
	  url: ajaxurl,
	  type: 'POST',
	  data: {
		action: 'sunshine_update_3_images_update',
		batch: batch,
		security: '<?php echo esc_js( $nonce ); ?>',
	  },
	  success: (response) => {
		  if ( response.success ) {
			  if ( response.data && response.data.updated && response.data.updated >= batch ) {
				  console.log( 'Images updated: ' + response.data.updated );
				  // We just updated one, so keep trying.
				  totalImagesProcessed += response.data.updated;
				  console.log( totalImagesProcessed );
				  jQuery( '#images .processed' ).html( totalImagesProcessed );
				  updateImagesData();
			  } else {
				  // No update made, we must be done.
				  jQuery( '#images p' ).html( 'Done!' );
				  updateComplete();
			  }
		  } else {
			  // Failure somehow, stop here.
			  show_error();
		  }
	  },
	  error: (jqXHR, textStatus, errorThrown) => {
		  show_error();
	  }
	});

}

/***********
 Galleries Duplicate Meta
***********/
jQuery( '#galleries-duplicate-meta-start' ).on( 'click', function(){
	jQuery( '#galleries-duplicate-meta p' ).replaceWith( '<p><img src="images/spinner.gif" alt="" class="sunshine-processing" /> Processing... <span class="processed"></span></p>' );
	updateGalleriesMetaData();
});

var totalGalleriesMetaProcessed = 0;

function updateGalleriesMetaData() {

	jQuery.ajax({
	  url: ajaxurl,
	  type: 'POST',
	  data: {
		action: 'sunshine_update_3_galleries_duplicate_meta',
		batch: batch,
		security: '<?php echo esc_js( $nonce ); ?>',
	  },
	  success: (response) => {
		  if ( response.success ) {
			  if ( response.data && response.data.updated ) {
				  console.log( 'Gallery ID: ' + response.data.updated );
				  // We just updated one, so keep trying.
				  totalGalleriesMetaProcessed++;
				  jQuery( '#galleries-duplicate-meta .processed' ).html( totalGalleriesMetaProcessed );
				  updateGalleriesMetaData();
			  } else {
				  // No update made, we must be done.
				  jQuery( '#galleries-duplicate-meta p' ).html( 'Done!' );
			  }
		  } else {
			  // Failure somehow, stop here.
			  show_error();
		  }

	  },
	  error: (jqXHR, textStatus, errorThrown) => {
		  show_error();
	  }
	});

}


function updateComplete() {
	jQuery.ajax({
	  url: ajaxurl,
	  type: 'POST',
	  data: {
		action: 'sunshine_update_3_complete',
		security: '<?php echo esc_js( $nonce ); ?>',
	  },
	  success: (response) => {
		jQuery( '#sunshine-update-3' ).hide();
		  jQuery( '#sunshine-update-3-complete' ).show();
		$notices = jQuery( '#sunshine-3-update-notices' );
		if ( response.notices ) {
			jQuery.each(response.notices, function(index, item) {
			  $notices.append( '<li>' + item + '</li>' );
			});
		}
	  },
	  error: (jqXHR, textStatus, errorThrown) => {
		  show_error();
	  }
	});
}

function sunshine_update_3_cleanup() {
	jQuery.ajax({
	  url: ajaxurl,
	  type: 'POST',
	  data: {
		action: 'sunshine_update_3_cleanup',
		security: '<?php echo esc_js( $nonce ); ?>',
	  },
	  success: (response) => {
		jQuery( '#sunshine-update-3-start' ).replaceWith( '<strong>Clean up complete</strong>' );
	  },
	  error: (jqXHR, textStatus, errorThrown) => {
		reject(new Error(`Error updating settings`));
	  }
	});
}


function show_error() {
	jQuery( '#reload' ).show();
}

</script>

<style>img.sunshine-processing { vertical-align: middle; height: 16px; margin-left: 10px; }</style>
