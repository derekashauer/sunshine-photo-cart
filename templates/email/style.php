p, div, li, h1, h2, h3, h4, td, th, input, select, textarea, button { font: normal 18px/1.48 "Helvetica Neue", Helvetica, Arial, sans-serif; color: #777777; }
body { padding: 0; margin: 0; }
a { color: #000; text-decoration: none; }
a:hover { color: #666; }
h1, h2, h3 { line-height: 1.1; }
strong { color: #333; }

body { background-color: #FFF !important; text-align: left; }
#wrapper {
	margin: 0 auto;
	max-width: 600px;
	padding: 40px 1%;
	-webkit-text-size-adjust: none !important;
	width: 98%;
}

.button { display: inline-block; vertical-align: middle; border-radius: 3px; background: #333; color: #FFF; padding: 10px 25px; }
.button:hover { background: #000; }

table {
  border-collapse: collapse;
  vertical-align: top;
}

#logo { font-size: 30px; color: #000; font-weight: normal; padding: 0 0 20px 0; }
#logo img { max-width: 600px; max-height: 100px; width: auto; height: auto; }

#header td { padding: 15px 0; }
#header td h1 { font-size: 30px; font-weight: normal; color: #000; }

#main { width: 100%; }

#content { text-align: left; }
#content h2 { font-size: 24px; color: #000; }
#content h3 { font-size: 22px; color: #000; margin-top: 25px; }

#signature { margin-top: 50px; }

/* Order Receipt */
#order-status { background: #EFEFEF; font-size: 15px; padding: 5px 15px; border-radius: 3px; }

#order-details,
#sunshine--order--instructions { border-top: 1px solid #EFEFEF; border-bottom: 1px solid #EFEFEF; padding: 20px 0; margin: 20px 0; }

#order-actions { margin: 30px 0; }

#order-cart { margin-top: 80px; }
#order-cart > table { width: 100%; }
.order-item { border-bottom: 1px solid #EFEFEF; }
.order-item td { padding-top: 15px; padding-bottom: 15px; }
.order-item--image { width: 90px; }
.order-item--image img { width: 75px; height: auto; }
.order-item--name { font-weight: bold; color: #333; }
.order-item--comments { font-size: 15px; color: #666; }
.order-item--total { text-align: right; padding-left: 30px; font-weight: bold; color: #333; }

#order-cart table tfoot { margin: 20px 0 0 0; }
#order-cart table tfoot > tr > td { text-align: right; }
#order-cart table tfoot table { float: right; }
#order-cart table tfoot th,
#order-cart table tfoot td { padding-top: 10px; padding-bottom: 10px; }
#order-cart table tfoot th { color: #666; padding-right: 30px; }
#order-cart table tfoot td { font-weight: bold; text-align: right; }
#order-cart table tfoot #order-total th,
#order-cart table tfoot #order-total td { color: #000; font-size: 20px; border-top: 2px solid #efefef; }
#order-cart table tfoot #order-total td.spacer { border: none; }

#order-cart table tfoot #order-total td .sunshine--cart--total--tax--explain { font-size: 15px; font-weight: normal; display: block; font-style: italic; }

.sunshine--order--item--download { border: 1px solid #CCC; background: #FFF; padding: 4px 15px; text-decoration: none; font-size: 14px; color: #333; text-transform: uppercase; line-height: 1; }
.sunshine--order--item--download:hover { border-color: #FFF; background: #333; color: #FFF; }

#order-customer,
#order-files { clear: both; margin: 80px 0 0 0; }
#order-customer td { width: 50%; }
#order-customer h4 { font-weight: bold; margin: 0; }

#photo-list { margin: 0 0 40px 0; }
#photo-list td { text-align: center; padding: 0 3px 15px 3px; }
#photo-list td img { max-width: 100%; height: auto; }
#photo-list td span.image-name { color: #777; font-size: 12px; display: block; text-align: center; padding: 8px 0; }

#email-summary #wrapper { max-width: 400px; margin: 0 auto; }
#sunshine-summary-data { border: 1px solid #EFEFEF; border-radius: 5px; padding: 20px; margin: 30px 0 0 0; }
#sunshine-summary-data table { width: 100%; }
#sunshine-summary-data td { padding: 15px; text-align: center; width: 50%; vertical-align: top; }
#sunshine-summary-data td h3 { font-weight: bold; font-size: 14px; text-transform: uppercase; color: #BEBEBE; margin: 0 0 5px 0; }
#sunshine-summary-data td p { font-weight: bold; font-size: 26px; color: #000; margin: 0; }
#dyk { margin-top: 30px; background: #FEF8F1; border-radius: 5px; padding: 20px 30px; }
#dyk h2 { font-size: 14px; margin: 0 0 5px 0; font-weight: bold; color: #FF8500; }
#dyk h3 { font-size; 16px; margin: 0 0 10px 0; font-weight: bold; }
#dyk p { font-size: 15px; }
#dyk p a.button { font-size: 14px; padding: 5px 10px; }

#celebrate { background: url(<?php echo esc_url( SUNSHINE_PHOTO_CART_URL . 'assets/images/confetti.gif' ); ?>) center / contain no-repeat; padding: 40px 20px; text-align: center; border: 1px solid #EFEFEF; border-radius: 5px; }
#celebrate h2 { font-size: 17px; font-weight: bold; }
#celebrate p { font-size: 18px; }
#celebrate p a.button { font-size: 14px; padding: 5px 10px; }

@media only screen and (max-width: 640px) {

}
