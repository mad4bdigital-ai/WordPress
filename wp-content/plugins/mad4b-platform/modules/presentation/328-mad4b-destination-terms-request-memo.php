<?php
/**
 * Plugin Name: MAD4B Staging Nested Destination Term Request Memo
 * Description: Avoid repeating identical JetEngine destination link rendering within one public archive request.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Destination_Term_Request_Memo_V1 {
 private const HOST='tours.dev.mad4b.com';
 private const SITE_UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 private const TEMPLATE=48562;
 private const JSON_BYTES=1233;
 private const JSON_SHA256='3e4944b150f9e21facc1e6f680f687306c7873c5f121bcbafef25e88aa2197d4';
 private static $fragments=[];
 private static $stats=['native'=>0,'reused'=>0];

 private static function eligible(): bool {
  if(PHP_SAPI==='cli'||wp_get_environment_type()!=='staging'
   ||is_admin()||wp_doing_ajax()||is_user_logged_in()||is_preview()
   ||!is_post_type_archive('tours-and-activities'))return false;
  if(($_SERVER['REQUEST_METHOD']??'')!=='GET'
   ||!empty($_SERVER['QUERY_STRING'])||!empty($_SERVER['HTTP_COOKIE'])
   ||!empty($_SERVER['HTTP_AUTHORIZATION'])||!empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
   ||!empty($_SERVER['HTTP_X_GEO_COUNTRY'])||!empty($_SERVER['HTTP_CF_IPCOUNTRY'])
   ||!empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY'])
   ||($_SERVER['HTTP_X_MAD4B_DEST_MEMO_CONTROL']??'')==='native-v1')return false;
  if(($_SERVER['HTTP_HOST']??'')!==self::HOST
   ||(string)wp_parse_url(home_url(),PHP_URL_HOST)!==self::HOST)return false;
  $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
  if(!is_array($p)||($p['site_uuid']??'')!==self::SITE_UUID)return false;
  if(!function_exists('get_woocommerce_currency')||get_woocommerce_currency()!=='USD')return false;
  $paths=[
   '/tours-and-activities/'=>'en','/fr/tours-and-activities/'=>'fr',
   '/de/tours-and-activities/'=>'de','/ar/tours-and-activities/'=>'ar',
   '/es/tours-and-activities/'=>'es','/it/tours-and-activities/'=>'it',
  ];
  $path=(string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
  return isset($paths[$path])&&apply_filters('wpml_current_language',null)===$paths[$path];
 }

 private static function pinned(): bool {
  $p=get_post(self::TEMPLATE);
  if(!$p||$p->post_type!=='jet-engine'||$p->post_status!=='publish')return false;
  $raw=get_post_meta(self::TEMPLATE,'_elementor_data',true);
  return is_string($raw)&&strlen($raw)===self::JSON_BYTES
   &&hash_equals(self::JSON_SHA256,hash('sha256',$raw));
 }

 public static function content($content,$listing_id,callable $original,string $locale) {
  if((int)$listing_id!==self::TEMPLATE)return $original($content,$listing_id);
  $term=jet_engine()->listings->data->get_current_object();
  if(!($term instanceof WP_Term)||$term->taxonomy!=='destinations'||$term->term_id<1){
   return $original($content,$listing_id);
  }
  // No persistent storage. The pinned template contains a single dynamic term link;
  // tours, money, customer sessions and pricing components are always rendered natively.
  $fingerprint=hash('sha256',wp_json_encode([
   $locale,(bool)wp_is_mobile(),(int)$term->term_id,(int)$term->term_taxonomy_id,
   (string)$term->name,(string)$term->slug,(string)$term->description,
   (int)$term->parent,(int)$term->count,get_term_meta($term->term_id)
  ]));
  if(isset(self::$fragments[$fingerprint])){
   self::$stats['reused']++;
   // The first native render already registered dynamic fonts/scripts;
   // CSS enqueue is idempotent and preserves Elementor stylesheet handles.
   if(class_exists('Elementor\\Core\\Files\\CSS\\Post')){
    \Elementor\Core\Files\CSS\Post::create(self::TEMPLATE)->enqueue();
   }
   return self::$fragments[$fingerprint];
  }
  self::$stats['native']++;
  $html=$original($content,$listing_id);
  if(is_string($html)&&$html!=='')self::$fragments[$fingerprint]=$html;
  return $html;
 }

 public static function stats(): array { return self::$stats; }

 public static function activate(): void {
  if(!self::eligible()||!self::pinned())return;
  global $wp_filter;
  $hook=$wp_filter['jet-engine/listing/content/elementor']??null;
  if(!($hook instanceof WP_Hook)||count($hook->callbacks[10]??[])!==1)return;
  $original=null;
  foreach($hook->callbacks[10] as $entry){
   $candidate=$entry['function'];
   if(is_array($candidate)&&is_object($candidate[0])
    &&get_class($candidate[0])==='Jet_Engine_Elementor_Frontend'
    &&$candidate[1]==='get_listing_content_cb')$original=$candidate;
   elseif($candidate instanceof Closure){
    $reflect=new ReflectionFunction($candidate);
    if(basename((string)$reflect->getFileName())==='323-mad4b-nav-term-fragment-cache.php')$original=$candidate;
   }
  }
  if(!$original)return;
  remove_filter('jet-engine/listing/content/elementor',$original,10);
  $lang=(string)apply_filters('wpml_current_language',null);
  add_filter('jet-engine/listing/content/elementor',
   static function($html,$id)use($original,$lang){return self::content($html,$id,$original,$lang);},10,2);

 }
}
add_action('wp',['MAD4B_Destination_Term_Request_Memo_V1','activate'],103);
