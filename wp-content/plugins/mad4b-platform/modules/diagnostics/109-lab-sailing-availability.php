<?php
/** Sailing-specific shadow gate; hotel inventory still evaluated separately. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
function shared_product_sailing_departure_decision($rate_id,$checkin,$checkout){
 $property=absint(get_post_meta($rate_id,'prop_related_property_id',true));
 $mode=(string)get_post_meta($property,'prop_accommodation_schedule_mode',true);
 if($mode!=='sailing')return ['mode'=>'overnight','sailing_required'=>false];
 $sailings=get_post_meta($rate_id,'prop_sailing_departures',true);
 if(!is_array($sailings)||!$sailings)return new WP_Error('stay_sailing_schedule_missing');
 $matches=[];
 foreach($sailings as $row){
  if(!is_array($row))return new WP_Error('stay_sailing_schedule_invalid');
  $start=(string)($row['prop_departure_date']??'');$end=(string)($row['prop_return_date']??'');
  $a=DateTimeImmutable::createFromFormat('!Y-m-d',$start);$b=DateTimeImmutable::createFromFormat('!Y-m-d',$end);
  if(!$a||!$b||$a->format('Y-m-d')!==$start||$b->format('Y-m-d')!==$end||$b<=$a)return new WP_Error('stay_sailing_schedule_invalid');
  if($start===$checkin&&$end===$checkout)$matches[]=$row;
 }
 if(count($matches)!==1)return new WP_Error('stay_sailing_departure_unavailable_or_ambiguous');
 $s=$matches[0];if(!empty($s['prop_stop_sale']))return new WP_Error('stay_sailing_stop_sale');
 return ['mode'=>'sailing','departure'=>$checkin,'return'=>$checkout,'sailing_required'=>true,
  'inventory_checked'=>false,'booking_confirmed'=>false,'status'=>'sailing_schedule_only'];
}
/** Legacy combined gate, delegates sailing-date decision to its independent provider. */
function lab_sailing_shadow_check($rate_id,$checkin,$checkout,$unit_id=0,$requested_cabins=1){
 $schedule=shared_product_sailing_departure_decision($rate_id,$checkin,$checkout);
 if(is_wp_error($schedule)||empty($schedule['sailing_required']))return $schedule;
 $requested_cabins=filter_var($requested_cabins,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>99]]);
 if($requested_cabins===false||get_post_type($unit_id)!=='units')return new WP_Error('stay_sailing_unit_or_cabins_invalid');
 $allocations=get_post_meta($unit_id,'unit_sailing_allotments',true);
 if(!is_array($allocations)||!$allocations)return new WP_Error('stay_sailing_cabin_inventory_missing');
 $found=[];
 foreach($allocations as $entry){
  if(!is_array($entry))return new WP_Error('stay_sailing_cabin_inventory_invalid');
  if((int)($entry['unit_related_rate_id']??0)===(int)$rate_id && ($entry['unit_departure_date']??'')===$checkin)$found[]=$entry;
 }
 if(count($found)!==1)return new WP_Error('stay_sailing_cabin_allotment_missing_or_ambiguous');
 $a=$found[0];
 foreach(['unit_allotment','unit_sold','unit_held'] as $field){if(!isset($a[$field])||filter_var($a[$field],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false)return new WP_Error('stay_sailing_cabin_inventory_invalid');}
 $available=(int)$a['unit_allotment']-(int)$a['unit_sold']-(int)$a['unit_held'];
 if($available<$requested_cabins||!empty($a['unit_stop_sale']))return new WP_Error('stay_sailing_cabins_unavailable');
 return ['mode'=>'sailing','departure'=>$checkin,'return'=>$checkout,'sailing_required'=>true,'requested_cabins'=>$requested_cabins,'available_cabins'=>$available,'status'=>'sailing_cabin_inventory_shadow_only','checkout_ready'=>false];
}
