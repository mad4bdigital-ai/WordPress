<?php
/**
 * Plugin Name: MAD4B Staging Archive Hero and Price Authority
 * Description: Compact mobile tour archive hero; existing inquiry footer reads authoritative minimum available tour price.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Archive_Hero_Price_V1 {
 const HOST='tours.dev.mad4b.com';
 const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 const POST_TYPE='tours-and-activities';
 const TEMPLATE=34607;
 const PRICE_ID='b512fea';
 public static function scope():bool {
  if(wp_get_environment_type()!=='staging' ||
    strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))!==self::HOST)return false;
  $profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
  return is_array($profile)&&($profile['site_uuid']??'')===self::UUID;
 }
 public static function archive():bool {
  return self::scope()&&!is_admin()&&!is_preview()&&is_post_type_archive(self::POST_TYPE);
 }
 public static function patch_elementor($nodes,$document_id=0) {
  if(!self::archive()||!is_array($nodes) || (int)$document_id!==self::TEMPLATE)return $nodes;
  $walk=static function($parts)use(&$walk){
   foreach($parts as &$item) {
    if(!is_array($item))continue;
    if(($item['id']??'')===MAD4B_Archive_Hero_Price_V1::PRICE_ID
      &&($item['widgetType']??'')==='text-editor'){
     $settings=$item['settings']??[];
     unset($settings['__dynamic__']['editor'],$settings['jedv_conditions']);
     $settings['jedv_enabled']='';
     $settings['editor']='<span class="mad4b-archive-price-value" hidden aria-live="polite"></span>';
     $item['settings']=$settings;
    }
    if(!empty($item['elements'])&&is_array($item['elements']))
      $item['elements']=$walk($item['elements']);
   }
   unset($item);return $parts;
  };
  return $walk($nodes);
 }
 public static function price_state(array $ids, callable $resolver):array {
  $best=null;$currency=null;$failed=false;
  foreach(array_slice(array_unique(array_map('intval',$ids)),0,130) as $id){
   if($id<=0)continue;
   $quote=$resolver($id);
   if(is_wp_error($quote)){
    if(in_array($quote->get_error_code(),['component_price_fx_unavailable'],true))$failed=true;
    continue;
   }
   if(!is_array($quote)||!isset($quote['amount'],$quote['currency']))continue;
   $number=$quote['amount'];$code=(string)$quote['currency'];
   if(!is_numeric($number)||!is_finite((float)$number)||(float)$number<=0
      ||!preg_match('/^[A-Z]{3}$/D',$code))continue;
   if($currency!==null && $currency!==$code){$failed=true;continue;}
   $currency=$code;
   $amount=(float)$number;
   if($best===null||$amount<$best['amount'])$best=[
    'amount'=>$amount,'currency'=>$code,'unit'=>(string)($quote['unit']??''),
    'upcoming'=>!empty($quote['upcoming']),'tour_id'=>$id
   ];
  }
  if($failed)return ['status'=>'unavailable','reason'=>'price_evidence_inconsistent'];
  return $best?['status'=>'available','best'=>$best]:['status'=>'on_request'];
 }
 public static function scoped_tour_ids(string $path,array $base_ids):array {
  $path=trim($path);
  if($path===''||strlen($path)>1100)return [];
  $url=wp_parse_url($path);
  if(!is_array($url))return [];
  if(!empty($url['host'])||!empty($url['scheme']))return [];
  $route=(string)($url['path']??'');
  $prefix=(string)wp_parse_url(get_post_type_archive_link(self::POST_TYPE),PHP_URL_PATH);
  if(!$prefix||!str_starts_with($route,trailingslashit($prefix)))return [];
  $suffix=substr($route,strlen(trailingslashit($prefix)));
  $facet='';
  if($suffix===''){
   if(!empty($url['query'])){
    parse_str((string)$url['query'],$qs);
    if(!empty($qs['jsf'])){
     if(!is_string($qs['jsf'])||$qs['jsf']!=='jet-engine:tours_query_archive'
       ||empty($qs['tax'])||!is_string($qs['tax']))return [];
     $facet=$qs['tax'];
    }else return [];
   }
  }else if(str_starts_with($suffix,'jsf/jet-engine:tours_query_archive/tax/')){
   $facet=substr($suffix,strlen('jsf/jet-engine:tours_query_archive/tax/'));
   $facet=rtrim($facet,'/');
  }else return []; // Unknown pretty SEO routes never leak unfiltered min.
  if($facet==='')return $base_ids;
  $allowed=['tour-types','season','good-for','tour-styles','schudle-days-of-weak','day-times'];
  $grouped=[];
  foreach(explode('+',$facet) as $part){
   if(!str_contains($part,':'))return [];
   [$tax,$value]=explode(':',$part,2);
   $tax=rawurldecode($tax);$value=rawurldecode($value);
   if(!in_array($tax,$allowed,true)||!preg_match('/^[a-z0-9_-]{1,90}$/D',$value))return [];
   $term=get_term_by('slug',$value,$tax);
   if(!$term){$term=is_numeric($value)?get_term((int)$value,$tax):null;}
   if(!$term||is_wp_error($term)||$term->taxonomy!==$tax)return [];
   $grouped[$tax][]=(int)$term->term_id;
   if(count($grouped)>6)return [];
  }
  $result=array_values(array_unique(array_map('intval',$base_ids)));
  foreach($grouped as $tax=>$term_ids) {
   $all=$term_ids;
   foreach($term_ids as $tid) {
    $children=get_term_children($tid,$tax);
    if(!is_wp_error($children))$all=array_merge($all,array_map('intval',$children));
   }
   $matches=wp_get_object_terms($result,$tax,['fields'=>'all_with_object_id']);
   if(is_wp_error($matches))return [];
   $permitted=[];
   foreach($matches as $term)if(in_array((int)$term->term_id,$all,true))
     $permitted[(int)$term->object_id]=true;
   $result=array_values(array_filter($result,static fn($id)=>isset($permitted[$id])));
   if(!$result)break;
  }
  return $result;
 }
 public static function rest():void {
  register_rest_route('mad4b/v1','/archive/tours/min-price',[
   'methods'=>'GET','permission_callback'=>'__return_true',
   'args'=>['path'=>['required'=>true,'type'=>'string']],
   'callback'=>static function(WP_REST_Request $req){
    if(!self::scope()||!function_exists('product_navigation_eligible_post_ids')
       ||!function_exists('tour_component_price_evidence'))
      return new WP_Error('mad4b_archive_pricing_unavailable','Price provider unavailable',['status'=>503]);
    $language=(string)apply_filters('wpml_current_language',null);
    $ids=product_navigation_eligible_post_ids('tours',$language?:'en');
    if(!is_array($ids))return new WP_Error('mad4b_archive_query_unavailable','Archive query unavailable',['status'=>503]);
    $scoped=self::scoped_tour_ids((string)$req['path'],$ids);
    $quote=self::price_state($scoped,static fn($id)=>tour_component_price_evidence($id,'auto','inherited'));
    $result=['status'=>$quote['status']];
    if($quote['status']==='available'){
     $best=$quote['best'];
     $units=['per_person'=>__('per person','woocommerce'),'per_trip'=>__('per trip','woocommerce'),
      'per_day'=>__('per day','woocommerce'),'per_room'=>__('per room','woocommerce'),
      'per_vehicle'=>__('per vehicle','woocommerce'),'per_cabin'=>__('per cabin','woocommerce')];
     $result['label']=!empty($best['upcoming'])?__('Upcoming from','woocommerce'):__('Start from','woocommerce');
     $result['amount']=number_format_i18n($best['amount'],function_exists('wc_get_price_decimals')?wc_get_price_decimals():2);
     $result['currency']=$best['currency'];
     $result['unit']=$units[$best['unit']]??'';
    }
    $response=rest_ensure_response($result);
    $response->header('Cache-Control','private, no-store, no-cache, max-age=0');
    $response->header('Vary','Cookie');
    return $response;
   }
  ]);
 }
 public static function assets():void {
  if(!self::archive())return;
  $file=WP_CONTENT_DIR.'/mu-plugins/assets/mad4b-archive-compact-price.css';
  if(is_readable($file))wp_enqueue_style('mad4b-archive-compact-price',content_url('mu-plugins/assets/mad4b-archive-compact-price.css'),[],(string)filemtime($file));
  $js=WP_CONTENT_DIR.'/mu-plugins/assets/mad4b-archive-compact-price.js';
  if(is_readable($js)) {
   wp_enqueue_script('mad4b-archive-compact-price',content_url('mu-plugins/assets/mad4b-archive-compact-price.js'),[],(string)filemtime($js),['in_footer'=>true]);
   wp_add_inline_script('mad4b-archive-compact-price','window.MAD4B_ARCHIVE_MIN_PRICE_ENDPOINT='.wp_json_encode(rest_url('mad4b/v1/archive/tours/min-price')).';','before');
  }
 }
 public static function independent_script_tag(string $tag,string $handle,string $src):string {
  if($handle!=='mad4b-archive-compact-price'||!self::archive())return $tag;
  // No dependency on LS optimized JS, jQuery or its aggregate. Elementor's
  // separate scripts may fail, but the public price must still fail closed.
  return str_replace('id="mad4b-archive-compact-price-js"',
   'data-no-optimize="1" data-no-defer="1" data-cfasync="false" id="mad4b-archive-compact-price-js"', $tag);
 }
 public static function exempt_from_combine($exclude) {
  if(!self::scope()||!is_array($exclude))return $exclude;
  $exclude[]='mad4b-archive-compact-price.js';
  return array_values(array_unique($exclude));
 }
 public static function boot():void {
  if(!self::scope())return;
  add_filter('elementor/frontend/builder_content_data',[__CLASS__,'patch_elementor'],80,2);
  add_action('rest_api_init',[__CLASS__,'rest']);
  add_action('wp_enqueue_scripts',[__CLASS__,'assets'],90);
  add_filter('script_loader_tag',[__CLASS__,'independent_script_tag'],500,3);
  add_filter('litespeed_optimize_js_excludes',[__CLASS__,'exempt_from_combine'],95);
 }
}
add_action('init',['MAD4B_Archive_Hero_Price_V1','boot'],23);
