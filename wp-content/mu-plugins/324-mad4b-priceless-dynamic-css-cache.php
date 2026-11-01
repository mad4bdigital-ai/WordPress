<?php
/**
 * Plugin Name: MAD4B Staging Priced-Card Dynamic CSS Cache
 * Description: Short-lived CSS-only acceleration with fresh per-request prices and Elementor output.
 * Version: 1.1.0
 */
defined('ABSPATH')||exit;
final class MAD4B_Priceless_CSS_Cache_V1 {
 private const MARKER='__MAD4B_CURRENT_DCSS_UNIQUE_ID__';
 
 public static function serve_cached_css(string $prefix, string $uid, string $fresh_html): string {
  return str_replace(self::MARKER,$uid,$prefix).$fresh_html;
 }
 public static function prepare_css_cache(string $prefix, string $uid) {
  if(!str_starts_with($prefix,'<style type="text/css">')
   ||!str_ends_with($prefix,'</style>')||strpos($prefix,$uid)===false)return false;
  return str_replace($uid,self::MARKER,$prefix);
 }
 public static function boot(){
  if(PHP_SAPI==='cli'||wp_get_environment_type()!=='staging'
   ||($_SERVER['REQUEST_METHOD']??'')!=='GET'||!empty($_SERVER['HTTP_COOKIE'])
   ||!empty($_SERVER['HTTP_AUTHORIZATION'])||!empty($_SERVER['QUERY_STRING'])
   ||!empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])||!empty($_SERVER['HTTP_X_GEO_COUNTRY'])
   ||!empty($_SERVER['HTTP_CF_IPCOUNTRY'])
   ||!empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY'])
   ||($_SERVER['HTTP_X_MAD4B_PRICELESS_CSS_CONTROL']??'')==='native-v1'
   ||is_admin()||wp_doing_ajax()||is_user_logged_in()||is_preview()
   ||!is_post_type_archive('tours-and-activities'))return;
  if(($_SERVER['HTTP_HOST']??'')!=='tours.dev.mad4b.com'
   ||(string)wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com'
   ||!function_exists('get_woocommerce_currency')||get_woocommerce_currency()!=='USD')return;
  $paths=['/tours-and-activities/'=>'en','/fr/tours-and-activities/'=>'fr',
   '/de/tours-and-activities/'=>'de','/ar/tours-and-activities/'=>'ar',
   '/es/tours-and-activities/'=>'es','/it/tours-and-activities/'=>'it'];
  $path=(string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
  $locale=apply_filters('wpml_current_language',null);
  if(!isset($paths[$path])||$paths[$path]!==$locale)return;
  // A longer stylesheet-only lifetime needs deterministic invalidation for the
  // Elementor design system as well as each listing and current tour meta.
  $kit_id=absint(get_option('elementor_active_kit'));
  if(!$kit_id||!get_post($kit_id))return;
  $kit_meta=[
   get_post_meta($kit_id,'_elementor_page_settings',true),
   get_post_meta($kit_id,'_elementor_data',true),
   get_post_modified_time('U',true,$kit_id),
   get_option('elementor_css_print_method'),
   defined('ELEMENTOR_VERSION')?ELEMENTOR_VERSION:'',
   (string)wp_get_theme()->get('Version'),
  ];
  $kit_hash=hash('sha256',wp_json_encode($kit_meta));
  $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
  if(!is_array($p)||($p['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a')return;
  $all_pins=[
   'en'=>[25102=>'c45280b5ec2e50757304421f06dc2a845303673f6459e279066823de192977c0',14962=>'c5ecf98755bc4d1a6bd907c1620acae1f3e349259feca94d312bef9b251a0cdc'],
   'fr'=>[35070=>'a7a79bd9a4180f0e9cd002b7c74ec4f1ed8a9210235ebc3d4726ee356f3a9df5',39900=>'a8d86eef31cad317c02c2631a234fe7ad4c8471b5e984536fdf965450b2b8e22'],
   'de'=>[35076=>'65cd5f109ba6ccf64ce873b60f705421604a8290d13373729f7375172dccc08e',39895=>'1427c2be6939dab2035e84bdb962065da70839994c39902cbd99b9c629232ae8'],
   'ar'=>[40683=>'e0ddd788baec161b499f5f9c23e39566adab0f91ddba93c5f56fb58709b5133c',40725=>'051e99735f3ca187056a6042b28ce04afcaf61fe11de7d9a650dbdf631c69ba6'],
   'es'=>[35084=>'00bd7d8b6be73d64e89760df9d6ff92b2f378142501f28187345dd0d87a99beb',39884=>'4090c76fb8bf9e36755ae6c879f6dbd67df5eba3900986263a18fcc78ba5f12f'],
   'it'=>[39902=>'dec17a54f1f9ffc02b99865c92aef7ecbab2a870263a02a4b4409039b671c321',39888=>'7e6513e4fe68bd374ad1f9798dca0caf234f73619a85487acc14188a4cf05db0'],
  ];
  $pins=$all_pins[$locale];
  foreach($pins as $id=>$sha){
   $listing=get_post($id);
   if(!$listing||$listing->post_type!=='jet-engine'||$listing->post_status!=='publish')return;
   $raw=get_post_meta($id,'_elementor_data',true);
   if(!is_string($raw)||!hash_equals($sha,hash('sha256',$raw)))return;
  }
  global $wp_filter;
  $h=$wp_filter['jet-engine/elementor-views/frontend/listing-content']??null;
  if(!($h instanceof WP_Hook)||count($h->callbacks[10]??[])!==1)return;
  $prev=null;
  foreach($h->callbacks[10] as $e)if($e['function'] instanceof Closure){
   $r=new ReflectionFunction($e['function']);
   if(basename((string)$r->getFileName())==='322-mad4b-empty-jet-css-fastpath.php')$prev=$e['function'];
  }
  if(!$prev)return;
  remove_filter('jet-engine/elementor-views/frontend/listing-content',$prev,10);
  add_filter('jet-engine/elementor-views/frontend/listing-content',
   static function($content,$listing_id,$inner)use($prev,$pins,$locale,$kit_hash){
    $id=(int)$listing_id;
    if(!isset($pins[$id])||!empty($inner)||!is_string($content)||$content==='')return $prev($content,$listing_id,$inner);
    $post=jet_engine()->listings->data->get_current_object();
    if(!($post instanceof WP_Post)||$post->post_type!=='tours-and-activities'||$post->post_status!=='publish')return $prev($content,$listing_id,$inner);
    $uid=jet_engine()->listings->data->get_current_object_unique_id($post);
    if(!is_string($uid)||!preg_match('/^\d{12,20}$/D',$uid))return $prev($content,$listing_id,$inner);
    // Same tour may appear in both List and Featured on one request:
    // reuse only its source META DIGEST, never price HTML or a cached product.
    static $source_meta_hashes=[];
    $post_id=(int)$post->ID;
    if(!isset($source_meta_hashes[$post_id])){
     $source_meta_hashes[$post_id]=hash('sha256',wp_json_encode(get_post_meta($post_id)));
    }
    $token=hash('sha256',wp_json_encode([
     $pins[$id],$id,$post_id,$post->post_modified_gmt,
     $source_meta_hashes[$post_id],$locale,(bool)wp_is_mobile(),$kit_hash
    ]));
    $key='css-only-'.$token;
    $cached=wp_cache_get($key,'mad4b_priceless_css_v2');
    if(is_string($cached)&&strpos($cached,self::MARKER)!==false){
     return self::serve_cached_css($cached,$uid,$content);
    }
    $result=$prev($content,$listing_id,$inner);
    if(!is_string($result)||!str_ends_with($result,$content)||strlen($result)<=strlen($content)){return $result;}
    $prefix=substr($result,0,-strlen($content));
    $canonical=self::prepare_css_cache($prefix,$uid);
    if($canonical===false)return $result;
    wp_cache_set($key,$canonical,'mad4b_priceless_css_v2',180);
    return $result;
   },10,3);

 }
}
add_action('wp',['MAD4B_Priceless_CSS_Cache_V1','boot'],102);
