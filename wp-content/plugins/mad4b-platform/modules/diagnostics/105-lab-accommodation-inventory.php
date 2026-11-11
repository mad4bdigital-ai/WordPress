<?php
/** Local-only, read-only inventory evaluation, not reservation authority. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
function etg_lab_stay_inventory_check($unit_id,$checkin,$checkout,$rooms=1){
 $unit_id=absint($unit_id);$rooms=filter_var($rooms,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>99]]);
 if(get_post_type($unit_id)!=='units'||$rooms===false)return new WP_Error('stay_inventory_invalid_input');
 $from=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$checkin);$to=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$checkout);
 if(!$from||!$to||$from->format('Y-m-d')!==$checkin||$to->format('Y-m-d')!==$checkout||$to<=$from||$from->diff($to)->days>365)return new WP_Error('stay_inventory_invalid_dates');
 $ledger=get_post_meta($unit_id,'unit_inventory_calendar',true);
 if(!is_array($ledger))return new WP_Error('stay_inventory_calendar_missing');
 // JetEngine repeater storage is item-keyed; normalize to date-keyed rows without trusting duplicates.
 if($ledger && !isset($ledger[$from->format('Y-m-d')])){
  $normalized=[];
  foreach($ledger as $record){
   if(!is_array($record)||!isset($record['unit_inventory_date']))return new WP_Error('stay_inventory_record_invalid');
   $day=(string)$record['unit_inventory_date'];$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
   if(!$dt||$dt->format('Y-m-d')!==$day||isset($normalized[$day]))return new WP_Error('stay_inventory_duplicate_or_invalid_date');
   $normalized[$day]=['allotment'=>$record['unit_allotment']??null,'sold'=>$record['unit_sold']??null,'held'=>$record['unit_held']??null,'stop_sale'=>$record['unit_stop_sale']??false];
  }
  $ledger=$normalized;
 }

 $dates=[];foreach(new DatePeriod($from,new DateInterval('P1D'),$to) as $date){
  $day=$date->format('Y-m-d');$row=$ledger[$day]??null;
  if(!is_array($row))return new WP_Error('stay_inventory_day_missing',$day);
  foreach(['allotment','sold','held'] as $field){if(!isset($row[$field])||filter_var($row[$field],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false)return new WP_Error('stay_inventory_day_invalid',$day);}
  $available=(int)$row['allotment']-(int)$row['sold']-(int)$row['held'];
  if(!empty($row['stop_sale'])||$available<$rooms)return new WP_Error('stay_inventory_unavailable',$day);
  $dates[]=['date'=>$day,'available'=>$available];
 }
 return ['unit_id'=>$unit_id,'requested_rooms'=>$rooms,'nights'=>count($dates),'days'=>$dates,'status'=>'shadow_availability_only_no_inventory_lock','checkout_ready'=>false];
}
