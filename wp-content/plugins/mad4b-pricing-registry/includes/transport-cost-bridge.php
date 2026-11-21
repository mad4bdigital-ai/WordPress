<?php
/**
 * MAD4B Transport Cost Bridge.
 * Explicit, read-only calculation from native Transportation → optional
 * Transport Rate → linked Tour Rate. No copied prices or checkout authority.
 */
defined('ABSPATH') || exit;
final class MAD4B_Transport_Cost_Bridge_V1 {
 const CONTRACT='mad4b.transport-cost-bridge.v1';
 const CONTEXTS=['planning','package','standalone'];
 const LINE_FIELDS=[
  'transportation_id','transport_rate_id','vehicle','service','road',
  'from_term_id','to_term_id','from_lat_lon','to_lat_lon',
  'from_label','to_label','km','drive_min','wait_min','overnight_units',
  'tolls','parking','extras','night','peak','quantity','stage_key'
 ];
 private static function positive_id($value):int {
  if(!is_int($value) && !(is_string($value)&&preg_match('/^[1-9][0-9]*$/D',$value)))return 0;
  $id=(int)$value;return $id>0&&$id<=PHP_INT_MAX?$id:0;
 }
 private static function quantity($raw) {
  if(!is_int($raw)&&!(is_string($raw)&&preg_match('/^[1-9][0-9]*$/D',$raw)))
   return new WP_Error('transport_bridge_quantity_invalid');
  $n=(int)$raw;
  return $n>=1&&$n<=20?$n:new WP_Error('transport_bridge_quantity_out_of_range');
 }
 private static function allowed_source(int $id):bool {
  return get_post_type($id)==='transportations' &&
   in_array(get_post_status($id),['publish','draft'],true) &&
   get_post_meta($id,'_mad4b_transfer_pdf_2026_provider_id',true)==='self' &&
   get_post_meta($id,'_mad4b_transfer_pdf_2026_sha256',true)===
    MAD4B_Transport_Distance_Time_V1::SHA;
 }
 private static function verified_rate(int $id,int $source):bool {
  return get_post_type($id)==='transport-rates' &&
   in_array(get_post_status($id),['draft','pending','publish'],true) &&
   (int)get_post_meta($id,'source_post_id',true)===$source;
 }
 public static function evaluate(array $request) {
  if(!MAD4B_Transport_Distance_Time_V1::permitted())
   return new WP_Error('transport_bridge_forbidden');
  if(array_diff(array_keys($request),['context','tour_rate_id','travel_date','lines']))
   return new WP_Error('transport_bridge_fields_unknown');
  $context=(string)($request['context']??'');
  if(!in_array($context,self::CONTEXTS,true))
   return new WP_Error('transport_bridge_context_invalid');
  $tourRaw=$request['tour_rate_id']??0;
  if($tourRaw===null||$tourRaw==='')$tourRaw=0;
  $tour=$tourRaw===0?0:self::positive_id($tourRaw);
  if($tourRaw!==0&&$tour===0)return new WP_Error('transport_bridge_tour_id_invalid');
  if($context==='package'&&!$tour)return new WP_Error('transport_bridge_package_tour_required');
  if($context==='standalone'&&$tour)return new WP_Error('transport_bridge_standalone_not_tour');
  if($tour&&(get_post_type($tour)!=='tour-rates'||
   !in_array(get_post_status($tour),['publish','draft','pending'],true)||
   !current_user_can('edit_post',$tour)))
   return new WP_Error('transport_bridge_tour_not_editable');
  $travelDate=(string)($request['travel_date']??'');
  if($travelDate!==''){
   $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$travelDate,new DateTimeZone('UTC'));
   if(!$dt||$dt->format('Y-m-d')!==$travelDate)
    return new WP_Error('transport_bridge_date_invalid');
  }
  $lines=$request['lines']??null;
  if(!is_array($lines)||!array_is_list($lines)||!$lines||count($lines)>30)
   return new WP_Error('transport_bridge_lines_invalid');
  $result=[];$seen=[];$totalCost=0.0;$totalScenario=0.0;$totalReferenceCost=0.0;$pending=0;$allRatesPublished=true;
  foreach($lines as $index=>$line){
   if(!is_array($line)||array_diff(array_keys($line),self::LINE_FIELDS))
    return new WP_Error('transport_bridge_line_fields_invalid');
   $source=self::positive_id($line['transportation_id']??0);
   if(!$source||!self::allowed_source($source))
    return new WP_Error('transport_bridge_verified_source_required');
   $vehicle=sanitize_key((string)($line['vehicle']??''));
   if(!in_array($vehicle,MAD4B_Transport_Distance_Time_V1::VEHICLES,true))
    return new WP_Error('transport_bridge_vehicle_required');
   $rateRaw=$line['transport_rate_id']??0;
   if($rateRaw===null||$rateRaw==='')$rateRaw=0;
   $rate=$rateRaw===0?0:self::positive_id($rateRaw);
   if($rateRaw!==0&&$rate===0)return new WP_Error('transport_bridge_rate_id_invalid');
   if($rate&&!self::verified_rate($rate,$source))
    return new WP_Error('transport_bridge_rate_source_invalid');
   if($context==='standalone'&&!$rate)
    return new WP_Error('transport_bridge_standalone_rate_required');
   if(!$rate||get_post_status($rate)!=='publish')$allRatesPublished=false;
   $qty=self::quantity($line['quantity']??1);
   if(is_wp_error($qty))return $qty;
   $stage=(string)($line['stage_key']??'');
   if($stage!==''&&!preg_match('/^[a-z0-9_-]{2,60}$/D',$stage))
    return new WP_Error('transport_bridge_stage_key_invalid');
   $key=$stage!==''?$stage:'source_'.$source;
   // Explicit selection: never double-count an alternative or repeated line.
   if(isset($seen[$key]))return new WP_Error('transport_bridge_duplicate_selected_stage');
   $seen[$key]=true;
   $calc=$line;
   unset($calc['quantity'],$calc['stage_key']);
   $calc['transportation_id']=$source;
   $calc['transport_rate_id']=$rate;
   // Planning mode does not pretend an unsaved Tour Rate relation exists.
   $calc['tour_rate_id']=$context==='package'?$tour:0;
   $calc['vehicle']=$vehicle;
   $calc['service']=$line['service']??'transfer';
   $calc['road']=$line['road']??'highway';
   $calc['night']=$line['night']??false;
   $calc['peak']=$line['peak']??false;
   $quote=MAD4B_Transport_Distance_Time_V1::calculate($calc);
   if(is_wp_error($quote))return new WP_Error(
    'transport_bridge_line_'.($index+1).'_'.$quote->get_error_code());
   if(empty($quote['inheritance_verified'])||!is_array($quote['native_inheritance'])||
      (int)($quote['native_inheritance']['transportation_id']??0)!==$source ||
      ($quote['source_price_role']??'')!=='self_cost' ||
      !empty($quote['checkout_authorized']))
    return new WP_Error('transport_bridge_source_provenance_invalid');
   $referenceUnit=(float)$quote['native_inheritance']['source_actual_cost_egp'];
   if(!is_finite($referenceUnit)||$referenceUnit<=0)
    return new WP_Error('transport_bridge_reference_cost_unverified');
   $referenceLine=round($referenceUnit*$qty,2);
   $totalReferenceCost=round($totalReferenceCost+$referenceLine,2);
   $cost=round((float)$quote['estimated_operating_cost_egp']*$qty,2);
   $scenario=round((float)$quote['indicative_selling_scenario_egp']*$qty,2);
   $totalCost=round($totalCost+$cost,2);
   $totalScenario=round($totalScenario+$scenario,2);
   $isAssigned=$context==='package';
   if(!$isAssigned)$pending++;
   $result[]=[
    'stage_key'=>$key,'transportation_id'=>$source,'transport_rate_id'=>$rate?:null,
    'tour_rate_id'=>$isAssigned?$tour:null,'quantity_vehicles'=>$qty,
    'vehicle'=>$vehicle,'source_key'=>$quote['native_inheritance']['source_pdf_key'],
    'source_actual_cost_reference_egp'=>$referenceUnit,
    'pdf_reference_line_cost_egp'=>$referenceLine,
    'pdf_reference_less_model_variance_egp'=>round($referenceLine-$cost,2),
    'pdf_reference_cost_basis'=>'self_source_original_pdf_2026_cost_not_date_confirmed',
    'modeled_unit_cost_egp'=>$quote['estimated_operating_cost_egp'],
    'modeled_line_cost_egp'=>$cost,
    'indicative_line_scenario_egp'=>$scenario,
    'markup_pct'=>$quote['markup']['effective_pct'],
    'markup_rule'=>$quote['markup']['base_rule'],
    'profile_field_sources'=>$quote['native_inheritance']['cost_field_sources'],
    'distance_evidence'=>$quote['distance_provenance'],
    'base_minimum_nonadditive'=>$quote['dispatch_base']['application']==='included_minimum_not_additive' &&
        (float)$quote['dispatch_base']['billed_additional_surcharge_egp']===0.0,
    'native_tour_relation_verified'=>$isAssigned,
    'optional_rate_status'=>$rate?get_post_status($rate):null,
    'road_distance_verified'=>false,'supplier_capacity_verified'=>false,
    'commercially_approved'=>false
   ];
  }
  return [
   'contract'=>self::CONTRACT,'context'=>$context,'currency'=>'EGP',
   'travel_date_context_only'=>$travelDate?:null,
   'source_price_role'=>'self_cost','rate_creation_required'=>false,
   'transport_rate_optional_for_packages'=>true,
   'selected_components_count'=>count($result),'explicit_selection_only'=>true,
   'alternatives_auto_aggregated'=>false,
   'quantity_basis'=>'vehicles_not_people',
   'preferred_package_cost_basis'=>'original_pdf_self_cost_reference_until_supplier_quote_verified',
   'reference_cost_not_travel_date_confirmed'=>true,
   'total_pdf_reference_company_cost_egp'=>$totalReferenceCost,
   'total_pdf_reference_less_modeled_variance_egp'=>round($totalReferenceCost-$totalCost,2),
   'total_modeled_operating_cost_egp'=>$totalCost,
   'total_indicative_scenario_egp'=>$totalScenario,
   'lines'=>$result,
   'tour_rate_id'=>$tour?:null,
   'package_relation_verified'=>$context==='package',
   'assignment_pending_count'=>$pending,
   'all_transport_rates_published'=>$allRatesPublished,
   'taxes_verified'=>false,'supplier_availability_verified'=>false,
   'route_and_pickup_verified'=>false,'company_commerce_policy_verified'=>false,
   'checkout_authorized'=>false,'rate_published'=>false,
   'write_performed'=>false,
   'receipt_sha256'=>hash('sha256',wp_json_encode([
     'site'=>home_url(),'context'=>$context,'tour_rate_id'=>$tour,
     'date'=>$travelDate,'lines'=>$result,
     'cost'=>$totalCost,'scenario'=>$totalScenario
   ])),
  ];
 }
}
