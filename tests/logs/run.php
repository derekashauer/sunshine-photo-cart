<?php
/**
 * Log tests: email addresses are masked right before any log entry is written.
 *
 * Photographers send their log files to support, and those files hold customer emails. Each
 * address must be mostly hidden, keeping only a few letters as a hint of whose it is. Text
 * that only looks like an email, such as a retina file name, must stay readable.
 *
 * Run on a test site, never a live store:
 *   wp eval-file wp-content/plugins/sunshine-photo-cart/tests/logs/run.php
 *
 * Writes only to temporary files; the site's real log files and settings are not touched.
 *
 * @package SunshinePhotoCart
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Run these tests on a test site.' );
}

$GLOBALS['spc_log_t'] = array(
	'passes'   => 0,
	'failures' => 0,
);
/**
 * Record one check and print it.
 *
 * @param string $what   What is checked.
 * @param bool   $ok     Whether it passed.
 * @param string $detail What was found, shown on failure.
 */
function spc_log_check( $what, $ok, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['spc_log_t']['passes'];
		WP_CLI::log( "  ok    $what" );
	} else {
		++$GLOBALS['spc_log_t']['failures'];
		WP_CLI::log( "  FAIL  $what" . ( $detail ? " ($detail)" : '' ) );
	}
}

WP_CLI::log( 'Masking one address' );
spc_log_check( 'keeps first and last letter, first letter of the domain and the ending', 'd***k@w***.com' === sunshine_mask_email( 'derek@wpsunshine.com' ), sunshine_mask_email( 'derek@wpsunshine.com' ) );
spc_log_check( 'a two-letter name shows only its first letter', 'j***@x***.io' === sunshine_mask_email( 'jo@x.io' ), sunshine_mask_email( 'jo@x.io' ) );
spc_log_check( 'a long domain keeps only its last part', 'm***@m***.uk' === sunshine_mask_email( 'mary@mail.studio.co.uk' ), sunshine_mask_email( 'mary@mail.studio.co.uk' ) );
spc_log_check( 'a short name shows only its first letter, not two of three', 'b***@x***.io' === sunshine_mask_email( 'bob@x.io' ), sunshine_mask_email( 'bob@x.io' ) );
spc_log_check( 'the length is hidden', 'a***d@e***.com' === sunshine_mask_email( 'ab1234567890cd@example.com' ) );

WP_CLI::log( 'Masking text' );
$line = 'WARNING: Stripe customer email mismatch! Expected: anna@example.com, Got: brian@example.com';
spc_log_check( 'both addresses in one line are hidden', 'WARNING: Stripe customer email mismatch! Expected: a***@e***.com, Got: b***n@e***.com' === sunshine_mask_emails( $line ), sunshine_mask_emails( $line ) );
spc_log_check( 'accented letters are hidden too', 'Sent to a***é@e***.de' === sunshine_mask_emails( 'Sent to andré@exämple.de' ), sunshine_mask_emails( 'Sent to andré@exämple.de' ) );
spc_log_check( 'an apostrophe does not leave the start of the name showing', 'Sent to o***n@e***.com' === sunshine_mask_emails( "Sent to o'brien@example.com" ), sunshine_mask_emails( "Sent to o'brien@example.com" ) );
spc_log_check( 'a web-encoded address in a link is hidden', 'redirect=/?email=d***k@e***.com&step=2' === sunshine_mask_emails( 'redirect=/?email=derek%40example.com&step=2' ), sunshine_mask_emails( 'redirect=/?email=derek%40example.com&step=2' ) );
$retina = 'Imported IMG_1234@2x.jpg and logo@3x.png';
spc_log_check( 'retina file names stay readable', sunshine_mask_emails( $retina ) === $retina, sunshine_mask_emails( $retina ) );
$broken = "Payload \xff\xfe sent to derek@example.com";
spc_log_check( 'text that is not valid UTF-8 is still masked, not lost', "Payload \xff\xfe sent to d***k@e***.com" === sunshine_mask_emails( $broken ), (string) sunshine_mask_emails( $broken ) );
$plain = 'Order 123 paid via https://example.com/checkout/?step=2 in /var/www/a.php';
spc_log_check( 'text without an email is left alone', sunshine_mask_emails( $plain ) === $plain );
$once = sunshine_mask_emails( $line );
spc_log_check( 'masking twice changes nothing more', sunshine_mask_emails( $once ) === $once );
spc_log_check( 'non-strings pass through', 42 === sunshine_mask_emails( 42 ) );

// Point the logs at temporary files and turn the debug log on for this run only.
$real_log             = SPC()->log_file;
$real_error_log       = SPC()->error_log_file;
$tmp_log              = wp_tempnam( 'spc-log-test' );
$tmp_error_log        = wp_tempnam( 'spc-error-log-test' );
SPC()->log_file       = $tmp_log;
SPC()->error_log_file = $tmp_error_log;
$enable_log           = function () {
	return 1;
};
add_filter( 'pre_option_' . SPC()->prefix . 'enable_log', $enable_log );

WP_CLI::log( 'Writing to the debug log' );
SPC()->log( 'Email sent to customer@photostudio.com: Your order' );
SPC()->log(
	array(
		'email' => 'nested@photostudio.com',
		'name'  => 'Pat',
	)
);
$written = file_get_contents( $tmp_log );
spc_log_check( 'a text entry is written masked', false !== strpos( $written, 'Email sent to c***r@p***.com: Your order' ), trim( $written ) );
spc_log_check( 'an array entry is written masked', false !== strpos( $written, 'n***d@p***.com' ) );
spc_log_check( 'no full address reaches the file', false === strpos( $written, 'customer@photostudio.com' ) && false === strpos( $written, 'nested@photostudio.com' ) );

WP_CLI::log( 'Writing to the error log' );
SPC()->php_error_handler( E_USER_WARNING, 'Could not send to errors@photostudio.com', __FILE__, __LINE__ );
$error_text = file_get_contents( $tmp_error_log );
spc_log_check( 'a PHP warning is written masked', false !== strpos( $error_text, 'Could not send to e***s@p***.com' ) && false === strpos( $error_text, 'errors@photostudio.com' ), trim( $error_text ) );

// Put everything back.
remove_filter( 'pre_option_' . SPC()->prefix . 'enable_log', $enable_log );
SPC()->log_file       = $real_log;
SPC()->error_log_file = $real_error_log;
wp_delete_file( $tmp_log );
wp_delete_file( $tmp_error_log );

$t = $GLOBALS['spc_log_t'];
WP_CLI::log( '' );
if ( $t['failures'] ) {
	WP_CLI::error( "{$t['failures']} failed, {$t['passes']} passed." );
}
WP_CLI::success( "All {$t['passes']} passed." );
