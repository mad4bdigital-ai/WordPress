<?php
/**
 * Plugin Name: MAD4B Critical Front Scripts · ChatbotX + Top Cities
 * Description: Site-scoped late ChatbotX bootstrap, immediate native Swiper, and first-paint CSS stability.
 * Version: 0.1.0
 */
defined('ABSPATH')||exit;
final class MAD4B_Critical_Front_Scripts_V1 {
 const HOST='tours.dev.mad4b.com';
 const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 private static $rendered=false;
 public static function scope():bool{
  if(wp_get_environment_type()!=='staging'||strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))!==self::HOST)return false;
  $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
  return is_array($p)&&($p['site_uuid']??'')===self::UUID;
 }
 public static function frontend():bool{
  return self::scope()&&!is_admin()&&!wp_doing_ajax()&&!is_preview();
 }
 public static function chatbot_ready():bool{
  if(!self::frontend()||!class_exists('Customer_Messaging_Bridge',false)||!Customer_Messaging_Bridge::ready())return false;
  // Order/payment flows should never lose synchronous access to support.
  foreach(['is_checkout','is_cart','is_account_page'] as $fn)if(function_exists($fn)&&$fn())return false;
  return true;
 }
 public static function chatbot_bootstrap():void {
  if(!self::chatbot_ready())return;
  // The original WP footer tag is async and requests ChatbotX immediately.
  // Only replace it when our local loader exists; never emit both.
  $file=WP_CONTENT_DIR.'/mu-plugins/assets/mad4b-chatbotx-delay.js';
  if(!is_readable($file))return;
  remove_action('wp_footer',['Customer_Messaging_Bridge','render_footer'],99);
  $s=Customer_Messaging_Bridge::settings();
  $ids=['workspaceId'=>(string)($s['workspace_id']??''),
        'webchatId'=>(string)($s['webchat_id']??''),
        'brandColor'=>(string)($s['brand_color']??'#2563eb')];
  if(!preg_match('/^[0-9]{1,20}$/D',$ids['workspaceId'])||!preg_match('/^[0-9]{1,20}$/D',$ids['webchatId']))return;
  wp_enqueue_script('mad4b-chatbotx-delay',content_url('mu-plugins/assets/mad4b-chatbotx-delay.js'),[],(string)filemtime($file),['in_footer'=>true,'strategy'=>'defer']);
  wp_add_inline_script('mad4b-chatbotx-delay','window.MAD4B_CHATBOTX_DELAY='.wp_json_encode([
   'origin'=>'https://chatbotx.mad4b.com/chat-widget/plugin.js',
   'settings'=>$ids,
   'desktopMs'=>3200,'mobileMs'=>5000
  ],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';','before');
 }
 public static function native_swiper():void {
  if(!self::frontend()||!is_front_page())return;
  global $wp_scripts;
  if(!($wp_scripts instanceof WP_Scripts))return;
  // Ordering-sensitive: Elementor's Swiper plus Jet gallery Swiper both have
  // inline/queue dependents. Keep them parser-ordered on the home page.
  foreach(['swiper','jet-swiper','mad4b-local-swiper-a11y'] as $h){
   if(!isset($wp_scripts->registered[$h]))continue;
   unset($wp_scripts->registered[$h]->extra['strategy']);
  }
 }
 public static function script_tag(string $tag,string $handle,string $src):string {
  if(!self::frontend()||!is_front_page()||!in_array($handle,['swiper','jet-swiper','mad4b-local-swiper-a11y'],true))return $tag;
  if(!str_contains($src,home_url())&&!str_contains($src,'/wp-content/'))return $tag;
  return (string)preg_replace('/\s(?:defer|async)(?:=(?:"[^"]*"|\x27[^\x27]*\x27|[^\s>]+))?(?=[\s>])/i','',$tag);
 }
 public static function swiper_native_excludes($excludes){
  if(!self::frontend()||!is_front_page()||!is_array($excludes))return $excludes;
  return array_values(array_unique(array_merge($excludes,[
   '/elementor/assets/lib/swiper/v8/swiper.min.js',
   '/jet-woo-product-gallery/assets/lib/swiper/swiper.min.js',
   'swiper.min.js','swiper.js'
  ])));
 }
 public static function critical_css():void {
  if(!self::frontend()||!is_front_page())return;
  // Nested Elementor shortcode templates have visibility:hidden on headings
  // whose entrance animation never receives an init event.
  echo '<style id="mad4b-home-topcities-critical-css">';
  echo '.elementor-25909 .elementor-element-2215bc5.elementor-invisible{visibility:visible!important;opacity:1!important;animation:none!important}';
  echo '.elementor-element-e991bc9[data-mad4b-yt-id]{background-color:#d6c8b1!important;background-position:center!important;background-repeat:no-repeat!important;background-size:cover!important;}';
  echo '</style>'."\n";
 }
 public static function poster_preload():void {
  if(!self::frontend()||!is_front_page()||!class_exists('MAD4B_Delayed_Hero_YT',false)
   ||MAD4B_Delayed_Hero_YT::mode()==='native')return;
  $url=MAD4B_Delayed_Hero_YT::poster();
  if(!$url)return;
  $parts=wp_parse_url($url);
  if(!is_array($parts)||strtolower((string)($parts['host']??''))!==self::HOST)return;
  $path=(string)($parts['path']??'');
  if(!preg_match('#^/wp-content/uploads/[A-Za-z0-9/_~.\-]+\.(?:jpg|jpeg|png|webp|avif)$#iD',$path))return;
  $raw=ABSPATH.ltrim($path,'/');$file=is_file($raw.'.webp')?$raw.'.webp':$raw;
  if(!is_file($file))return;
  $href=home_url('/').ltrim($path,'/').(str_ends_with($file,'.webp')&& !str_ends_with($path,'.webp')?'.webp':'');
  echo '<link rel="preload" as="image" fetchpriority="high" href="'.esc_url($href).'" data-mad4b-hero-poster="true">'."\n";
 }
 public static function boot():void {
  if(!self::scope())return;
  add_action('wp_enqueue_scripts',[__CLASS__,'chatbot_bootstrap'],90);
  add_action('wp_enqueue_scripts',[__CLASS__,'native_swiper'],99);
  add_filter('script_loader_tag',[__CLASS__,'script_tag'],900,3);
  add_filter('litespeed_optimize_js_excludes',[__CLASS__,'swiper_native_excludes'],100);
  add_action('wp_head',[__CLASS__,'poster_preload'],1);
  add_action('wp_head',[__CLASS__,'critical_css'],3);
 }
}
add_action('init',['MAD4B_Critical_Front_Scripts_V1','boot'],25);
