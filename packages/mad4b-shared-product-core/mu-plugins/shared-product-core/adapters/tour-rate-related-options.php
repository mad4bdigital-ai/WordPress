<?php
/** Lab read-only related choices: JetEngine metadata remains authoritative. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_related_post_ids($value,$type,$limit=80){
 if(is_string($value))$value=maybe_unserialize($value);
 if(is_scalar($value)&&$value!=='')$value=[$value];
 if(!is_array($value)||count($value)>$limit)return [];
 $ids=[];foreach($value as $v){
  $id=is_scalar($v)&&ctype_digit((string)$v)?absint($v):0;
  if($id && get_post_type($id)===$type && get_post_status($id)==='publish')$ids[$id]=$id;
 }
 return array_values($ids);
}
function shared_product_related_option_details($ids){
 return array_map(static function($id){return ['id'=>(int)$id,'name'=>get_the_title($id),'url'=>get_permalink($id),'image'=>get_the_post_thumbnail_url($id,'medium')?:''];},$ids);
}
function shared_product_rate_related_options($rate_id){
 $rate_id=absint($rate_id);
 if(get_post_type($rate_id)!=='tour-rates'||get_post_status($rate_id)!=='publish')return new WP_Error('invalid_rate');
 $context=shared_product_tour_context($rate_id);if(is_wp_error($context))return $context;
 $tour=(int)$context['tour_id'];
 $properties=(string)get_post_meta($rate_id,'has_properties',true)==='1'?shared_product_related_post_ids(get_post_meta($rate_id,'related_properties_id',true),'properties'):[];
 if(function_exists('service_channel_allowed')&&!service_channel_allowed('properties','tour_component'))$properties=[];
 $extras=shared_product_allowed_optional_tour_ids($rate_id);if(is_wp_error($extras))return $extras;
 $trans=[];
 foreach(['vehicle'=>['tour_vehicles_transport_status','vehicles_transport_services'], 'train'=>['tour_train_transport_status','train_transport_services'],'air'=>['tour_air_transport_status','air_transport_services']] as $mode=>$keys){
  if(function_exists('service_channel_allowed')&&!service_channel_allowed('transportations','tour_component'))continue;
  if((string)get_post_meta($tour,$keys[0],true)==='1'){
   $trans[$mode]=shared_product_related_option_details(shared_product_related_post_ids(get_post_meta($tour,$keys[1],true),'transportations'));
   if(function_exists('service_channel_allowed')&&!service_channel_allowed('transportations','standalone'))
    foreach($trans[$mode] as &$entry)$entry['url']='';
   unset($entry);
  }
 }
 $property_details=shared_product_related_option_details($properties);
 if(function_exists('service_channel_allowed')&&!service_channel_allowed('properties','standalone'))
  foreach($property_details as &$entry)$entry['url']='';
 unset($entry);
 return ['rate_id'=>$rate_id,'properties'=>$property_details,
  'optional_tours'=>shared_product_related_option_details($extras),'transportation'=>$trans,
  'status'=>'related_options_only_inventory_and_prices_not_confirmed'];
}
add_filter('shared_product_tour_rate_search_results',static function($result){
 foreach($result['results'] as &$item){$options=shared_product_rate_related_options($item['rate_id']);$item['related_options']=is_wp_error($options)?['status'=>'unavailable','error'=>$options->get_error_code()]:$options;}
 unset($item);return $result;
},20,1);

/** Faceted visual choices. Every selected option must belong to at least one surviving rate. */
add_filter('shared_product_tour_rate_search_results',static function($result,$tour_id,$choices){
 $selected=['property_id'=>absint($choices['property_id']??0),'transport_id'=>absint($choices['transport_id']??0),'extra_tour_id'=>absint($choices['extra_tour_id']??0)];
 $all=['properties'=>[],'transportations'=>[],'extras'=>[]];$match=[];
 foreach($result['results'] as $item){
  $rel=$item['related_options']??[];
  $hotels=$rel['properties']??[];$extras=$rel['optional_tours']??[];
  $transport=[];foreach(($rel['transportation']??[]) as $rows)foreach($rows as $row)$transport[]=$row;
  $eligible=true;
  foreach([['property_id',$hotels],['transport_id',$transport],['extra_tour_id',$extras]] as [$key,$rows])if($selected[$key]&&!in_array($selected[$key],array_column($rows,'id'),true))$eligible=false;
  if(!$eligible)continue;$match[]=$item;
  foreach([['properties',$hotels],['transportations',$transport],['extras',$extras]] as [$key,$rows])foreach($rows as $row)$all[$key][$row['id']]=$row;
 }
 $result['results']=$match;$result['count']=count($match);
 $result['visual_options']=array_map('array_values',$all);
 $result['selected_options']=$selected;
 return $result;
},30,3);
