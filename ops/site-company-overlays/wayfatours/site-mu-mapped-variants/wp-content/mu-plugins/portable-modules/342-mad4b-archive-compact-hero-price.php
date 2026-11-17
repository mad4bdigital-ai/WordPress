<?php
/**
 * Plugin Name: MAD4B Staging Archive Hero and Price Authority
 * Description: Compact mobile tour archive hero; existing inquiry footer reads authoritative minimum available tour price.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Archive_Hero_Price_V1 {

 const POST_TYPE='tours-and-activities';
 const TEMPLATE=34607;
 const PRICE_ID='b512fea';
 public static function scope():bool { return MAD4B_Portable_MU_Context::enabled('342-mad4b-archive-compact-hero-price'); }
 public static function archive():bool {
  return self::scope()&&!is_admin()&&!is_preview()&&is_post_type_archive(self::POST_TYPE);
 }
 public static function patch_elementor($nodes,$document_id=0) {
  if(!self::archive()||!is_array($nodes))return $nodes;
  // Resolve WPML translations by source template, not by fixed EN/ES IDs.
  $original=(int)apply_filters('wpml_object_id',(int)$document_id,'elementor_library',true,'en');
  if($original!==self::TEMPLATE)return $nodes;
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
 public static function render_price_widget($html,$widget) {
  // Elementor/WPML/JEDV may re-apply translated dynamic editor contents after
  // builder_content_data. Gate on the exact archive widget, AFTER rendering.
  if(!self::archive() || !is_object($widget)
    || !method_exists($widget,'get_id')
    || (string)$widget->get_id()!==self::PRICE_ID
    || !method_exists($widget,'get_name')
    || (string)$widget->get_name()!=='text-editor')return $html;
  // The generic placeholder is filled only from verified, filter-aware REST.
  // A static 0$ or stale English amount must never reach public HTML.
  return '<div class="elementor-widget-container"><span class="mad4b-archive-price-value" hidden aria-live="polite"></span></div>';
 }
 public static function price_state(array $ids, callable $resolver):array {
  $best=null;$currency=null;$failed=false;
  // Source catalog may grow; don't claim a minimum from a truncated subset.
  $unique=array_values(array_unique(array_map('intval',$ids)));
  if(count($unique)>130)return ['status'=>'unavailable','reason'=>'price_scope_too_large'];
  foreach($unique as $id){
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
 public static function route_language(string $path,string $fallback='en'):string {
  $parsed=wp_parse_url($path);
  $route=is_array($parsed)?(string)($parsed['path']??''):'';
  $prefix=explode('/',trim($route,'/'))[0]??'';
  $active=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
  $langs=is_array($active)?array_keys($active):[];
  if(!$langs) {
   $settings=get_option('icl_sitepress_settings',[]);
   if(is_array($settings)&&is_array($settings['active_languages']??null))
    $langs=$settings['active_languages'];
  }
  if(!$langs)$langs=[$fallback?:'en'];
  return in_array($prefix,$langs,true)?$prefix:$fallback;
 }
 public static function scoped_tour_ids(string $path,array $base_ids):array {
  $path=trim($path);
  if($path===''||strlen($path)>1100)return [];
  $url=wp_parse_url($path);
  if(!is_array($url))return [];
  if(!empty($url['host'])||!empty($url['scheme']))return [];
  $route=(string)($url['path']??'');
  $prefix=(string)wp_parse_url(get_post_type_archive_link(self::POST_TYPE),PHP_URL_PATH);
  // REST does not inherit WPML frontend language selection from the route.
  if($prefix&&!str_starts_with($route,trailingslashit($prefix))) {
   $lang=self::route_language($route,'');
   if($lang!=='')$route=substr($route,strlen('/'.$lang));
  }
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
  $allowed=['tour-types','season','good-for','tour-styles','schudle-days-of-weak','day-times',
   'destinations','duration-options','guide-languages'];
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
 /**
  * Apply only independently verified JetSmartFilters membership constraints.
  * Never trust browser-supplied tour IDs or an arbitrary WP_Query structure.
  * Unrecognized semantic filters fail CLOSED so we cannot advertise an
  * unrelated global price while the result listing is filtered.
  */
 public static function scoped_tour_ids_by_query(array $base_ids,string $raw) {
  if($raw===''||$raw==='{}')return $base_ids;
  if(strlen($raw)>2600)return new WP_Error('mad4b_price_filter_oversized','Filter state too large');
  $query=json_decode($raw,true,12);
  if(!is_array($query)||json_last_error()!==JSON_ERROR_NONE||count($query)>18)
   return new WP_Error('mad4b_price_filter_invalid','Invalid filter state');
  $result=array_values(array_unique(array_map('intval',$base_ids)));
  $taxonomy=['destinations','tour-types','tour-styles','duration-options',
    'guide-languages','good-for','schudle-days-of-weak','day-times','season'];
  foreach($query as $key=>$value) {
   if(!is_string($key)||strlen($key)>100)return new WP_Error('mad4b_price_filter_key','Unsupported filter key');
   if(str_starts_with($key,'_sort_'))continue; // Sort changes order, never membership.
   if(str_starts_with($key,'_tax_query_')) {
    $tax=substr($key,strlen('_tax_query_'));
    if(!in_array($tax,$taxonomy,true))return new WP_Error('mad4b_price_filter_tax','Unsupported taxonomy');
    if(is_array($value)) {
     if(count($value)>16)return new WP_Error('mad4b_price_filter_tax_values','Too many filter terms');
     $parts=$value;
    }else if(is_scalar($value)&&strlen((string)$value)<=300) {
     $parts=preg_split('/[,;]+/',rawurldecode((string)$value));
    }else return new WP_Error('mad4b_price_filter_tax_values','Invalid term list');
    $terms=[];
    foreach($parts as $part){
     $term_id=trim((string)$part);
     if(!preg_match('/^[1-9][0-9]{0,9}$/D',$term_id))
      return new WP_Error('mad4b_price_filter_tax_id','Invalid taxonomy term');
     $term=get_term((int)$term_id,$tax);
     if(!$term||is_wp_error($term)||$term->taxonomy!==$tax)
      return new WP_Error('mad4b_price_filter_tax_unknown','Unknown taxonomy term');
     $terms[]=(int)$term->term_id;
     $children=get_term_children((int)$term->term_id,$tax);
     if(!is_wp_error($children))$terms=array_merge($terms,array_map('intval',$children));
    }
    if(!$terms)return [];
    $matched=wp_get_object_terms($result,$tax,['fields'=>'all_with_object_id']);
    if(is_wp_error($matched))return $matched;
    $allowed=[];
    foreach($matched as $t)if(in_array((int)$t->term_id,$terms,true))$allowed[(int)$t->object_id]=true;
    $result=array_values(array_filter($result,static fn($id)=>isset($allowed[$id])));
    if(!$result)return [];
    continue;
   }
   if($key==='_meta_query_start_price|range') {
    if(!is_string($value)||strlen($value)>80)
     return new WP_Error('mad4b_price_filter_range_invalid','Invalid range filter');
    $pieces=preg_split('/[_|,;:]+/',trim($value));
    if(count($pieces)!==2)return new WP_Error('mad4b_price_filter_range_invalid','Invalid range format');
    foreach($pieces as $piece)if(!preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,4})?$/D',$piece))
     return new WP_Error('mad4b_price_filter_range_invalid','Invalid range value');
    [$min,$max]=array_map('floatval',$pieces);
    if($min>$max)return new WP_Error('mad4b_price_filter_range_order','Invalid range order');
    // Reproduce the native JetSmartFilters price INDEX membership; the final
    // advertised amount still comes from the rate/direct-aware price evidence.
    $result=array_values(array_filter($result,static function($id)use($min,$max){
     $index=get_post_meta((int)$id,'start_price',true);
     return is_numeric($index)&&(float)$index>=$min&&(float)$index<=$max;
    }));
    if(!$result)return [];
    continue;
   }
   return new WP_Error('mad4b_price_filter_unsupported','Unsupported semantic filter');
  }
  return $result;
 }
 private static function public_price_response(array $payload) {
  $response=rest_ensure_response($payload);
  $response->header('Cache-Control','private, no-store, no-cache, max-age=0');
  $response->header('Vary','Cookie');
  return $response;
 }
 public static function translated_price_string(string $source,string $key,string $lang):string {
  $result=apply_filters('wpml_translate_single_string',$source,'MAD4B Archive CTA',$key,$lang);
  // WPML String Translation is authoritative when its translation exists.
  if(is_string($result)&&trim($result)!==''&&$result!==$source)return $result;
  // Common locale fallback is presentation copy only. Languages are STILL
  // discovered dynamically from WPML, never whitelisted by this dictionary.
  $fallback=[
   'es'=>['start_from'=>'Empezar desde','upcoming_from'=>'Próximamente desde'],
   'fr'=>['start_from'=>'À partir de','upcoming_from'=>'Prochainement à partir de'],
   'de'=>['start_from'=>'Ab','upcoming_from'=>'Demnächst ab'],
   'it'=>['start_from'=>'A partire da','upcoming_from'=>'Prossimamente da'],
   'ar'=>['start_from'=>'يبدأ من','upcoming_from'=>'قريبًا ابتداءً من'],
  ];
  return $fallback[$lang][$key]??(is_string($result)&&trim($result)!==''?$result:$source);
 }
 public static function rest():void {
  register_rest_route('mad4b/v1','/archive/tours/min-price',[
   'methods'=>'GET','permission_callback'=>'__return_true',
   'args'=>['path'=>['required'=>true,'type'=>'string'],'query'=>['required'=>false,'type'=>'string']],
   'callback'=>static function(WP_REST_Request $req){
    if(!self::scope()||!function_exists('product_navigation_eligible_post_ids')
       ||!function_exists('tour_component_price_evidence'))
      return new WP_Error('mad4b_archive_pricing_unavailable','Price provider unavailable',['status'=>503]);
    $language=self::route_language((string)$req['path'],(string)apply_filters('wpml_current_language',null)?:'en');
    $ids=product_navigation_eligible_post_ids('tours',$language?:'en');
    if(!is_array($ids))return new WP_Error('mad4b_archive_query_unavailable','Archive query unavailable',['status'=>503]);
    $scoped=self::scoped_tour_ids((string)$req['path'],$ids);
    $filtered=self::scoped_tour_ids_by_query($scoped,(string)($req['query']??''));
    if(is_wp_error($filtered))
      return self::public_price_response(['status'=>'unavailable','reason'=>'filter_scope_not_certified']);
    $quote=self::price_state($filtered,static fn($id)=>tour_component_price_evidence($id,'auto','inherited'));
    $result=['status'=>$quote['status']];
    if($quote['status']==='available'){
     $best=$quote['best'];
     $units=['per_person'=>__('per person','woocommerce'),'per_trip'=>__('per trip','woocommerce'),
      'per_day'=>__('per day','woocommerce'),'per_room'=>__('per room','woocommerce'),
      'per_vehicle'=>__('per vehicle','woocommerce'),'per_cabin'=>__('per cabin','woocommerce')];
     $result['label']=!empty($best['upcoming'])
       ?self::translated_price_string(__('Upcoming from','woocommerce'),'upcoming_from',$language)
       :self::translated_price_string(__('Start from','woocommerce'),'start_from',$language);
     $result['amount']=number_format_i18n($best['amount'],function_exists('wc_get_price_decimals')?wc_get_price_decimals():2);
     $result['currency']=$best['currency'];
     $result['unit']=$units[$best['unit']]??'';
    }
    return self::public_price_response($result);
   }
  ]);
 }
 public static function assets():void {
  if(!self::archive())return;
  $file=WP_CONTENT_DIR.'/mu-plugins/assets/mad4b-archive-compact-price.css';
  if(is_readable($file))wp_enqueue_style('mad4b-archive-compact-price',content_url('mu-plugins/assets/mad4b-archive-compact-price.css'),[],(string)filemtime($file));
  // Conversion CTA: lowest certified price of ALL currently filtered tours.
  // JSF AJAX updates the scoped query; never copy an individual card price.
  $js=WP_CONTENT_DIR.'/mu-plugins/assets/mad4b-archive-compact-price-context-v2.js';
  if(is_readable($js)) {
   wp_enqueue_script('mad4b-archive-compact-price',content_url('mu-plugins/assets/mad4b-archive-compact-price-context-v2.js'),[],(string)filemtime($js),['in_footer'=>true]);
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
  $exclude[]='mad4b-archive-compact-price-context-v2.js';
  return array_values(array_unique($exclude));
 }
 public static function boot():void {
  if(!self::scope())return;
  add_action('init',static function(){
   do_action('wpml_register_single_string','MAD4B Archive CTA','start_from','Start from');
   do_action('wpml_register_single_string','MAD4B Archive CTA','upcoming_from','Upcoming from');
  },20);
  add_filter('elementor/frontend/builder_content_data',[__CLASS__,'patch_elementor'],80,2);
  // Late final-stage seal closes WPML/JEDV rewrite races on translated templates.
  add_filter('elementor/widget/render_content',[__CLASS__,'render_price_widget'],1500,2);
  add_action('rest_api_init',[__CLASS__,'rest']);
  add_action('wp_enqueue_scripts',[__CLASS__,'assets'],90);
  add_filter('script_loader_tag',[__CLASS__,'independent_script_tag'],500,3);
  add_filter('litespeed_optimize_js_excludes',[__CLASS__,'exempt_from_combine'],95);
 }
}
add_action('init',['MAD4B_Archive_Hero_Price_V1','boot'],23);
