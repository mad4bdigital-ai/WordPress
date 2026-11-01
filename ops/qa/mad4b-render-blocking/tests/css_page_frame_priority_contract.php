<?php
/** Isolated safe contract for the Staging-only page CSS priority plugin. */
if(!defined('ABSPATH'))define('ABSPATH','/tmp');
if(!function_exists('add_action')) { function add_action($name,$callback,$priority=10){} }
$path=getenv('MAD4B_PRIORITY_TEST_SOURCE');
if(!$path)$path=dirname(__DIR__,4).'/wp-content/mu-plugins/334-mad4b-page-css-frame-priority.php';
if(!is_file($path))throw new RuntimeException('Missing priority source');
require_once $path;
$cls='MAD4B_Page_CSS_Frame_Priority_V1';
function pcheck($ok,$label){if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(3);}echo "PASS: $label\n";}
pcheck($cls::valid_route('/tours-and-activities/','en') && $cls::valid_route('/fr/tours-and-activities/','fr'), 'Locale-bound archive');
pcheck(!$cls::valid_route('/fr/tours-and-activities/','en') && !$cls::valid_route('/wp-admin/','en'), 'No wrong locale or admin routes');
$home=$cls::selected('/');$archive=$cls::selected('/tours-and-activities/');
pcheck(count($home)===5 && count($archive)===4,'Page-aware tier split');
pcheck(isset($home['jet-smart-filters']) && !isset($archive['jet-smart-filters']),'Visible archive filters remain critical');
pcheck(!isset($home['woocommerce-general']) && !isset($archive['woocommerce-general']), 'First-frame Woo grid remains critical');
pcheck(!isset($home['swiper']) && !isset($home['elementor-sticky']) && !isset($archive['elementor-sticky']), 'Sticky and swipe selectors are not deferred');
pcheck($cls::safe_cookie_header('','en') && $cls::safe_cookie_header('wp-wpml_current_language=en; sbjs_session=1; _lscache_vary=abc_123; _fbp=fb.1','en'),'Known readonly vary/tracking cookies accepted');
pcheck(!$cls::safe_cookie_header('wp-wpml_current_language=fr','en') && !$cls::safe_cookie_header('woocommerce_cart_hash=abc','en') && !$cls::safe_cookie_header('wordpress_logged_in_foo=abc','en'),'Cross-locale and commerce/auth cookies rejected');
pcheck(!$cls::safe_cookie_header('sbjs_session=1; sbjs_session=2','en') && !$cls::safe_cookie_header('_lscache_vary=%%%','en'),'Duplicate and unsupported vary rejected');
echo "mad4b.page-css-frame-priority.contract.v1: PASS\n";
