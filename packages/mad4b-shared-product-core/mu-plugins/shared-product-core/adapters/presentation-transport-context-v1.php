<?php
/**
 * Unified PRESENTATION context for native transportation Listings v1.
 *
 * Maps existing Elementor/JetEngine/JetSmartFilters bindings to strictly
 * read-only vehicle/train/air display eligibility. Does not price, reserve,
 * open sales, change source queries, or grant WordPress permissions.
 */
defined('ABSPATH') || exit;
if (function_exists('wp_get_environment_type') && wp_get_environment_type()==='local'
 && defined('SHARED_PRODUCT_LAB_ONLY') && SHARED_PRODUCT_LAB_ONLY) {
final class Shared_Product_Presentation_Transport_Context_v1 {
 private const VERSION='1.0.0';
 private const CATEGORY=[
  'vehicles'=>['slot'=>'tour.transport.vehicles','source'=>'vehicles_transport_services',
    'enabled'=>'tour_vehicles_transport_status','mode_term'=>568,
    'query_id'=>258,'provider_id'=>'tours_related_transportations_vehicles',
    'widget_id'=>'65f7ea2'],
  'train'=>['slot'=>'tour.transport.train','source'=>'train_transport_services',
    'enabled'=>'tour_train_transport_status','mode_term'=>578,
    'query_id'=>259,'provider_id'=>'tours_related_transportations_train',
    'widget_id'=>'b86dac4'],
  'air'=>['slot'=>'tour.transport.air','source'=>'air_transport_services',
    'enabled'=>'tour_air_transport_status','mode_term'=>594,
    'query_id'=>260,'provider_id'=>'tours_related_transportations_air',
    'widget_id'=>'cc64ad7'],
 ];
 public static function categories():array {return self::CATEGORY;}
 public static function binding(string $category):array {
  if(!isset(self::CATEGORY[$category]))return ['ok'=>false,'code'=>'UNKNOWN_PRESENTATION_TRANSPORT_SLOT'];
  static $cache=[];
  if(isset($cache[$category]))return $cache[$category];
  $c=self::CATEGORY[$category];
  if(get_post_type(45152)!=='elementor_library' || get_post_type(40299)!=='jet-engine')
    return ['ok'=>false,'code'=>'NATIVE_TEMPLATES_UNAVAILABLE'];
  $tree=json_decode((string)get_post_meta(45152,'_elementor_data',true),true);
  if(!is_array($tree))return ['ok'=>false,'code'=>'INVALID_NATIVE_TEMPLATE'];
  $found=0;
  $scan=static function(array $nodes)use(&$scan,$c,$category,&$found):void{
   foreach($nodes as $n){
    $s=(array)($n['settings']??[]);
    if(($n['widgetType']??'')==='jet-listing-grid'
     &&($n['id']??'')===$c['widget_id']
     &&(int)($s['lisitng_id']??0)===40299
     &&(int)($s['custom_query_id']??0)===$c['query_id']
     &&(string)($s['_element_id']??'')===$c['provider_id']
     &&in_array('booking-component-'.$category,
       preg_split('/\s+/',(string)($s['_css_classes']??'')),true))$found++;
    if(is_array($n['elements']??null))$scan($n['elements']);
   }
  };
  $scan($tree);
  if($found!==1)return ['ok'=>false,'code'=>'NATIVE_TRANSPORT_BINDING_MISMATCH'];
  // The shared listing must have a native JetEngine Dynamic Visibility branch
  // for this specific taxonomy parent. A valid query with no branch renders
  // a card wrapper without its content (the regression this contract fixes).
  $listing=json_decode((string)get_post_meta(40299,'_elementor_data',true),true);
  if(!is_array($listing))return ['ok'=>false,'code'=>'INVALID_LISTING_STRUCTURE'];
  $matching=0;
  $check=static function(array $nodes)use(&$check,&$matching,$c):void{
   foreach($nodes as $n){
    if(($n['elType']??'')==='container'){
     $settings=(array)($n['settings']??[]);
     if(($settings['jedv_enabled']??'')==='yes'){
      foreach((array)($settings['jedv_conditions']??[]) as $rule){
       if(($rule['jedv_condition']??'')==='post-has-terms'
         &&($rule['terms_taxonomy']??'')==='transportation-types'
         &&(int)($rule['jedv_value']??0)===$c['mode_term'])$matching++;
      }
     }
    }
    if(is_array($n['elements']??null))$check($n['elements']);
   }
  };
  $check($listing);
  if($matching!==1)return ['ok'=>false,'code'=>'NATIVE_TRANSPORT_CONDITIONAL_BRANCH_MISMATCH'];
  $cache[$category]=['ok'=>true,'code'=>'NATIVE_TRANSPORT_BINDING_VERIFIED',
    'slot'=>$c['slot'],'category'=>$category,'template_id'=>45152,
    'listing_id'=>40299,'widget_id'=>$c['widget_id'],'query_id'=>$c['query_id'],
    'provider_id'=>'jet-engine/'.$c['provider_id'],'taxonomy'=>'transportation-types',
    'mode_term_id'=>$c['mode_term'],'read_only'=>true];
  return $cache[$category];
 }
 public static function candidate(int $post_id,string $category):array {
  if(!isset(self::CATEGORY[$category]))return ['ok'=>false,'code'=>'UNKNOWN_CATEGORY'];
  $c=self::CATEGORY[$category];
  if($post_id<=0||get_post_type($post_id)!=='transportations'
     ||get_post_status($post_id)!=='publish')
    return ['ok'=>false,'code'=>'INVALID_PUBLISHED_TRANSPORT'];
  if((string)get_post_meta($post_id,'trans_available_status',true)!=='1')
    return ['ok'=>false,'code'=>'TRANSPORT_NOT_AVAILABLE_FOR_DISCOVERY'];
  $mode=(int)get_post_meta($post_id,'trans_mode',true);
  if($mode!==$c['mode_term'])
    return ['ok'=>false,'code'=>'TRANSPORT_META_MODE_MISMATCH'];
  if(!has_term($mode,'transportation-types',$post_id))
    return ['ok'=>false,'code'=>'TRANSPORT_TAXONOMY_MODE_MISMATCH'];
  $subtype=(int)get_post_meta($post_id,'trans_type',true);
  $term=$subtype?get_term($subtype,'transportation-types'):false;
  if(!$term instanceof WP_Term || (int)$term->parent!==$mode
     ||!has_term($subtype,'transportation-types',$post_id))
    return ['ok'=>false,'code'=>'TRANSPORT_SUBTYPE_MISMATCH'];
  return ['ok'=>true,'code'=>'TRANSPORT_PRESENTATION_ELIGIBLE','post_id'=>$post_id,
   'mode_term_id'=>$mode,'subtype_term_id'=>$subtype,'category'=>$category,
   'selectable_only_after_rate'=>true,'price_verified'=>false,
   'inventory_verified'=>false,'booking_authorized'=>false];
 }
 public static function resolve(int $tour,string $category,array $linked):array {
  $binding=self::binding($category);
  $result=['schema_version'=>self::VERSION,'read_only'=>true,
    'tour_id'=>$tour,'category'=>$category,
    'status'=>'unavailable','ids'=>[],'rejections'=>[],
    'binding'=>$binding,'booking_authorized'=>false,
    'price_authority'=>'existing_pricing_registry'];
  if(empty($binding['ok']))return $result;
  if($tour<=0||get_post_type($tour)!=='tours-and-activities'
    ||get_post_status($tour)!=='publish')return $result;
  $c=self::CATEGORY[$category];
  if((string)get_post_meta($tour,$c['enabled'],true)!=='1'){
   $result['status']='disabled';return $result;
  }
  if(function_exists('service_channel_allowed')
    &&!service_channel_allowed('transportations','tour_component')){
   $result['status']='service_channel_disabled';return $result;
  }
  if(count($linked)>80){$result['status']='limit_exceeded';return $result;}
  $authorized=array_map('absint',(array)get_post_meta($tour,$c['source'],true));
  foreach($linked as $value){
   if(!is_int($value) && !(is_string($value)&&ctype_digit($value))){
    $result['rejections'][]='invalid_identifier';continue;
   }
   $id=(int)$value;
   if($id<1||!in_array($id,$authorized,true)){
    $result['rejections'][]='not_related_to_tour';continue;
   }
   $check=self::candidate($id,$category);
   if(empty($check['ok'])){
    $result['rejections'][(string)$id]=$check['code'];continue;
   }
   if(function_exists('native_component_selection_public')
      &&!native_component_selection_public($id,'transportations')){
    $result['rejections'][(string)$id]='PRESENTATION_VISIBILITY_DENIED';continue;
   }
   $result['ids'][$id]=$id;
  }
  $result['ids']=array_values($result['ids']);
  $result['status']=$result['ids']?'ready':'empty';
  return $result;
 }
}
}
