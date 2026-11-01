<?php
/**
 * Plugin Name: MAD4B Nationality Server First Paint (Staging)
 * Description: Suppresses only the redundant anonymous first-paint nationality read.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Nationality_Server_First_Paint_V1 {
 private const SITE_UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 private const EXPECTED_JS_SHA256='5b3d64e4f81fcd3b4c856ceb7910dae30675bea5c2cc07103abde27cdfd4d33f';
 private const PATHS=['/'=>'en','/fr/'=>'fr','/de/'=>'de','/ar/'=>'ar','/es/'=>'es','/it/'=>'it'];
 public static function eligibility():bool {
  if(PHP_SAPI==='cli'||wp_get_environment_type()!=='staging'
   ||($_SERVER['HTTP_HOST']??'')!=='tours.dev.mad4b.com'
   ||(string)wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com'
   ||($_SERVER['REQUEST_METHOD']??'')!=='GET'
   ||!empty($_SERVER['QUERY_STRING'])||!empty($_SERVER['HTTP_COOKIE'])
   ||!empty($_SERVER['HTTP_AUTHORIZATION'])||!empty($_SERVER['PHP_AUTH_USER'])
   ||!empty($_SERVER['HTTP_X_WP_NONCE'])||!empty($_SERVER['HTTP_CF_IPCOUNTRY'])
   ||!empty($_SERVER['HTTP_X_GEO_COUNTRY'])||!empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY'])
   ||!empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])||!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
   ||($_SERVER['HTTP_X_MAD4B_NATIONALITY_CONTROL']??'')==='native-v1'
   ||is_admin()||wp_doing_ajax()||is_user_logged_in()||is_preview()||!is_front_page())return false;
  $path=(string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
  if(!isset(self::PATHS[$path])||apply_filters('wpml_current_language',null)!==self::PATHS[$path])return false;
  $profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
  if(!is_array($profile)||($profile['site_uuid']??'')!==self::SITE_UUID)return false;
  if(get_option('woocommerce_default_customer_address')!=='base'
   ||get_option('woocommerce_currency')!=='USD'
   ||!function_exists('get_woocommerce_currency')||get_woocommerce_currency()!=='USD')return false;
  $script=WP_PLUGIN_DIR.'/etg-traveller-nationality-switcher/assets/nationality.js';
  if(!is_readable($script)||!hash_equals(self::EXPECTED_JS_SHA256,(string)hash_file('sha256',$script)))return false;
  return true;
 }
 public static function enqueue_boot_hint():void {
  if(!self::eligibility()||!wp_script_is('travel-booking-nationality-switcher','enqueued'))return;
  // Force a new asset URL after this narrow source update (stale CDN/LS copies must not be reused).
  $scripts=wp_scripts();
  $handle=$scripts->registered['travel-booking-nationality-switcher']??null;
  if(!$handle||!str_contains((string)$handle->src,'etg-traveller-nationality-switcher/assets/nationality.js'))return;
  $handle->ver='0.3.3-mad4b-wpml-country-names';
  // Insert before the native registered asset; search and saves remain unchanged.
  wp_add_inline_script('travel-booking-nationality-switcher',
   'window.travelBookingNationalityServerFirstPaint=true;','before');
 }
}
add_action('wp_footer',['MAD4B_Nationality_Server_First_Paint_V1','enqueue_boot_hint'],5);
