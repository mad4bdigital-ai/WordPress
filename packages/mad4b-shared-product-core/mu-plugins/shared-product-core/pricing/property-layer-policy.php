<?php
/** Accommodation composition decision: independent property layer for complex stays. Lab only. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_property_layer_plan($rate_id,$selection){
 if(!is_array($selection)||get_post_type($rate_id)!=='tour-rates')return new WP_Error('invalid_property_plan');
 $linked=shared_product_rate_related_options($rate_id);if(is_wp_error($linked))return $linked;
 $ids=array_column($linked['properties'],'id');
 $stays=$selection['stays']??[];
 if(!is_array($stays)||count($stays)>20)return new WP_Error('invalid_stays');
 if(!$stays)return ['mode'=>'none','rate_id'=>$rate_id,'stays'=>[],'checkout_authority'=>false];
 $rooms_total=0;$hotels=[];$complex=count($stays)>1;
 foreach($stays as $row){
  if(!is_array($row))return new WP_Error('invalid_stay_row');
  $hotel=absint($row['property_id']??0);$rooms=filter_var($row['rooms']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>99]]);
  $nights=filter_var($row['nights']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>60]]);
  if(!$hotel||!in_array($hotel,$ids,true)||$rooms===false||$nights===false)return new WP_Error('unlinked_or_invalid_stay');
  $rooms_total+=$rooms;$hotels[$hotel]=true;
  if($rooms>1||$nights>1||!empty($row['room_type'])||!empty($row['board']))$complex=true;
 }
 if(count($hotels)>1)$complex=true;
 return ['mode'=>$complex?'property_overlay':'simple_rate_property','rate_id'=>(int)$rate_id,'stays'=>$stays,'rooms_total'=>$rooms_total,
 'price_status'=>'requires_property_unit_quote','availability_status'=>'requires_property_inventory','checkout_authority'=>false];
}

/** OR alternatives belong to one Tour Rate package tier; selection is exclusive. */
function shared_product_package_accommodation_options($rate_id,$requested_package_id=null){
 $rate_id=absint($rate_id);
 if(get_post_type($rate_id)!=='tour-rates'||get_post_status($rate_id)!=='publish')return new WP_Error('invalid_rate');
 $raw=get_post_meta($rate_id,'related_package_term_id',true);
 $package_id=($raw!==''&&$raw!==null)?absint($raw):0;
 $source=$package_id?'rate_explicit':'none';
 $tour_id=absint(get_post_meta($rate_id,'related_tour_id',true));
 $tour_packages=($tour_id&&get_post_type($tour_id)==='tours-and-activities')?wp_get_object_terms($tour_id,'package_levels',['fields'=>'ids']):[];
 if(is_wp_error($tour_packages))return $tour_packages;
 $tour_packages=array_values(array_unique(array_map('absint',$tour_packages)));
 if($package_id && $tour_packages && !in_array($package_id,$tour_packages,true))return new WP_Error('rate_package_not_on_tour');
 if(!$package_id && count($tour_packages)===1){$package_id=$tour_packages[0];$source='tour_taxonomy';}
 if(!$package_id && count($tour_packages)>1)return new WP_Error('ambiguous_tour_package_level');
 if($requested_package_id!==null){
  $requested=absint($requested_package_id);
  if(!$requested||!$package_id||$requested!==$package_id)return new WP_Error('accommodation_package_mismatch');
 }
 $related=shared_product_rate_related_options($rate_id);if(is_wp_error($related))return $related;
 return ['rate_id'=>$rate_id,'package_term_id'=>$package_id?:null,'package_status'=>$package_id?'assigned':'legacy_unassigned','package_source'=>$source,
  'relationship'=>'OR','max_selected'=>1,'options'=>$related['properties'],'checkout_authority'=>false];
}
function shared_product_select_package_accommodation($rate_id,$property_id,$package_id=null){
 $options=shared_product_package_accommodation_options($rate_id,$package_id);
 if(is_wp_error($options))return $options;
 $property_id=absint($property_id);
 if(!$property_id||!in_array($property_id,array_column($options['options'],'id'),true))return new WP_Error('property_not_in_package_options');
 return ['rate_id'=>absint($rate_id),'package_term_id'=>$options['package_term_id'],'selected_property_id'=>$property_id,
  'relationship'=>'OR','selected_count'=>1,'checkout_authority'=>false];
}
