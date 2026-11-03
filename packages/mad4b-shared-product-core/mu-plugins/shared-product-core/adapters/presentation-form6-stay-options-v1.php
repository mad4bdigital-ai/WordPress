<?php
/** Local Lab only: optional, source-backed room and meal choices. */
defined('ABSPATH')||exit;
if(wp_get_environment_type()!=='local'||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_form6_stay_stage($tour,$rate,$key,$property){
 if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish'||
    get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish'||
    (int)get_post_meta($rate,'related_tour_id',true)!==$tour||
    get_post_type($property)!=='properties'||get_post_status($property)!=='publish')
   return new WP_Error('stay_source_unavailable');
 $stages=get_post_meta($rate,'_booking_experience_stay_stages_v1',true);
 if(!is_array($stages))return new WP_Error('stay_stage_missing');
 foreach($stages as $stage)
  if(($stage['key']??null)===$key&&in_array($property,array_map('intval',$stage['properties']??[]),true))return $stage;
 return new WP_Error('stay_not_related_to_rate');
}
function shared_form6_stay_catalog($tour,$rate,$key,$property){
 $stage=shared_form6_stay_stage($tour,$rate,$key,$property);
 if(is_wp_error($stage))return $stage;
 global $wpdb;
 $query="SELECT p.ID FROM {$wpdb->posts} p
 JOIN {$wpdb->postmeta} m ON m.post_id=p.ID
 WHERE p.post_type='property-rates' AND p.post_status='publish'
 AND m.meta_key='prop_related_property_id' AND m.meta_value=%s
 ORDER BY p.ID LIMIT 150";
 $ids=array_map('intval',$wpdb->get_col($wpdb->prepare($query,(string)$property)));
 $units=[];
 foreach($ids as $priceId){
  if(get_post_meta($priceId,'prop_price_unit',true)!=='per_room_night')continue;
  $unitId=(int)get_post_meta($priceId,'prop_related_unit_id',true);
  $boardId=(int)get_post_meta($priceId,'prop_board_type',true);
  $unit=get_post($unitId);$board=get_term($boardId,'board-type');
  if(!$unit||$unit->post_type!=='units'||$unit->post_status!=='publish'||
     get_post_meta($unitId,'unit_readiness',true)!=='validated'||
     !$board||is_wp_error($board))continue;
  $currency=(string)get_post_meta($priceId,'currency',true);
  $amount=get_post_meta($priceId,'base_amount',true);
  $from=(string)get_post_meta($priceId,'valid_from',true);
  $to=(string)get_post_meta($priceId,'valid_to',true);
  if(!preg_match('/^[A-Z]{3}$/D',$currency)||!is_numeric($amount)||(float)$amount<=0||
     !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$from)||
     !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$to)||$from>$to)continue;
  if(!isset($units[$unitId]))$units[$unitId]=[
   'id'=>$unitId,'name'=>wp_strip_all_tags($unit->post_title),
   'capacity'=>max(1,(int)get_post_meta($unitId,'unit_max_occupancy',true)), 'boards'=>[]];
  $units[$unitId]['boards'][]=[
   'id'=>$boardId,'name'=>html_entity_decode($board->name,ENT_QUOTES,'UTF-8'),
   'rate_id'=>(int)$priceId,'valid_from'=>$from,'valid_to'=>$to,
   'currency'=>$currency,'amount'=>(float)$amount];
 }
 return ['tour_id'=>$tour,'rate_id'=>$rate,'stage_key'=>$key,'property_id'=>$property,
  'stage_label'=>(string)($stage['label']??$key),
  'price_policy'=>(string)($stage['price_policy']??'inquiry'),
  'day_offset'=>(int)($stage['day_offset']??0),'nights'=>(int)($stage['nights']??0),
  'units'=>array_values($units)];
}
add_action('rest_api_init', static function() {
 register_rest_route('shared-product/v1','/tour-form-stay-options',[
  'methods'=>WP_REST_Server::READABLE,'permission_callback'=>'__return_true',
  'callback'=>static function(WP_REST_Request $request){
   $tour=absint($request->get_param('tour_id'));
   $rate=absint($request->get_param('rate_id'));
   $property=absint($request->get_param('property_id'));
   $key=(string)$request->get_param('stage_key');
   if(!preg_match('/^[a-z0-9_-]{2,45}$/D',$key))
    return new WP_Error('invalid_stage','Stay options unavailable',['status'=>422]);
   $data=shared_form6_stay_catalog($tour,$rate,$key,$property);
   if(is_wp_error($data)) return $data;
   do_action('litespeed_control_set_nocache','Local QA room and board choices');
   $response=rest_ensure_response($data);
   $response->header('Cache-Control','private, no-store, max-age=0');
   $response->header('X-LiteSpeed-Cache-Control','no-cache');
   return $response;
  }
 ]);
});
/**
 * Review-only source rate projection. Missing rooms, board, calendar coverage
 * and inquiry stages are nonblocking; they do not contribute zero to a sale.
 */
function shared_form6_review_stays($tour,$rate,$travelDate,$adults,$selected){
 if(!is_array($selected)||!array_is_list($selected)||count($selected)>20)
  return new WP_Error('stay_selection_invalid');
 $date=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$travelDate,new DateTimeZone('UTC'));
 if(!$date||$date->format('Y-m-d')!==$travelDate)return new WP_Error('stay_date_invalid');
 $rows=[];$sums=[];$pending=0;$seen=[];
 foreach($selected as $item){
  if(!is_array($item)||array_diff(array_keys($item),
    ['stage_key','property_id','unit_id','board_id','rooms']))return new WP_Error('stay_fields_invalid');
  $key=$item['stage_key']??null;$prop=$item['property_id']??null;
  if(!is_string($key)||!preg_match('/^[a-z0-9_-]{2,45}$/D',$key)||
    !is_int($prop)||isset($seen[$key]))return new WP_Error('stay_identity_invalid');
  $seen[$key]=true;
  $catalog=shared_form6_stay_catalog($tour,$rate,$key,$prop);
  if(is_wp_error($catalog))return $catalog;
  $row=['stage_key'=>$key,'property_id'=>$prop,'price_policy'=>$catalog['price_policy'],
    'review_required'=>true,'price_status'=>'needs_quote'];
  $unit=(int)($item['unit_id']??0);
  $board=(int)($item['board_id']??0);
  $rooms=(int)($item['rooms']??0);
  $unitFound=null;$boardFound=null;
  foreach($catalog['units'] as $entry){
   if($entry['id']!==$unit)continue;
   $unitFound=$entry;
   foreach($entry['boards'] as $candidate){
    if($candidate['id']===$board){$boardFound=$candidate;break;}
   }
  }
  if($unit>0&&$unitFound)$row['unit_id']=$unit;
  if($board>0&&$boardFound)$row['board_id']=$board;
  if($rooms>0&&$rooms<=50&&$unitFound&&$rooms*$unitFound['capacity']>=$adults)
   $row['rooms']=$rooms;
  if($catalog['price_policy']==='included'){
   $row['price_status']='included_no_surcharge';
   // Included does not mean availability or room type is certified.
   $row['review_required']=true;
  }elseif($catalog['price_policy']==='inquiry'){
   $row['price_status']='supplier_quote_required';
  }elseif($catalog['price_policy']==='additional'&&$boardFound&&isset($row['rooms'])){
   $start=$date->modify('+'.$catalog['day_offset'].' days');
   $last=$start->modify('+'.max(0,$catalog['nights']-1).' days');
   $rId=$boardFound['rate_id'];
   $stop=in_array(strtolower((string)get_post_meta($rId,'stop_sale',true)),
     ['1','yes','true'],true);
   if($catalog['nights']>0&&!$stop&&
      $boardFound['valid_from']<=$start->format('Y-m-d')&&
      $boardFound['valid_to']>=$last->format('Y-m-d')){
    $row['price_status']='priced_additional';
    $row['rate_id']=$rId;
    $row['currency']=$boardFound['currency'];
    $row['priced_amount']=round($boardFound['amount']*$catalog['nights']*$rooms,2);
    $sums[$row['currency']]=($sums[$row['currency']]??0)+$row['priced_amount'];
   }
  }
  if($row['price_status']==='needs_quote'||$row['price_status']==='supplier_quote_required')
   $pending++;
  $rows[]=$row;
 }
 return ['rows'=>$rows,'source_totals'=>$sums,'pending_count'=>$pending];
}
