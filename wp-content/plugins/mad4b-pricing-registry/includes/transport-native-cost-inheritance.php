<?php
/**
 * Resolves original JetEngine Transportation → Transport Rate → Tour Rate
 * inheritance for admin-only simulation. Never copies prices into post meta,
 * approves inventory, or exposes a checkout endpoint.
 */
defined('ABSPATH') || exit;
final class MAD4B_Transport_Native_Cost_Inheritance_V1 {
 const SOURCE_ROWS='trans_vehicle_cost_overrides_v1';
 const RATE_MODE='trans_cost_model_mode';
 const METRICS=['fixed','per_km','per_drive_min','per_wait_min',
  'overnight','min_fare','deadhead_pct'];
 const LIMITS=[
  'fixed'=>200000,'per_km'=>10000,'per_drive_min'=>1000,
  'per_wait_min'=>1000,'overnight'=>100000,'min_fare'=>200000,
  'deadhead_pct'=>200
 ];
 private static function valid_amount($value,string $key){
  if(!is_scalar($value)||is_bool($value)||!is_numeric($value)||
    !is_finite((float)$value)||(float)$value<0||
    (float)$value>self::LIMITS[$key])
   return new WP_Error('transport_cost_override_amount_invalid');
  return round((float)$value,4);
 }
 private static function normalized_ids($raw):array {
  if(is_string($raw)) {
   if($raw===''||$raw==='[]')return [];
   $parsed=json_decode($raw,true);
   if(is_array($parsed))$raw=$parsed;
   elseif(ctype_digit($raw))$raw=[(int)$raw];
   else $raw=preg_split('/[,|]/',$raw);
  }
  if(!is_array($raw))return [];
  $ids=[];foreach($raw as $x){
   if(is_scalar($x)&&ctype_digit((string)$x)&&(int)$x>0)
    $ids[]=(int)$x;
  }
  return array_values(array_unique($ids));
 }
 private static function has_tour_relation(int $tourRate,int $source):bool {
  $simple=self::normalized_ids(get_post_meta($tourRate,
   'related_transportations_id',true));
  if(in_array($source,$simple,true))return true;
  $stages=get_post_meta($tourRate,'_mad4b_transport_stages_v1',true);
  if(is_array($stages)){
   foreach($stages as $row)if(is_array($row)){
    $linked=self::normalized_ids($row['transport_ids']??[]);
    if(in_array($source,$linked,true))return true;
   }
  }
  // Language translated source posts can reference the same immutable
  // PDF operation, but a tour cannot silently link to a different service.
  $key=(string)get_post_meta($source,'_mad4b_transfer_pdf_2026_source_key',true);
  if($key==='')return false;
  foreach(array_merge($simple,
   is_array($stages)?array_merge([],...array_map(static function($r){
    return is_array($r)?self::normalized_ids($r['transport_ids']??[]):[];
   },$stages)):[]) as $id){
   if(get_post_type((int)$id)==='transportations'&&
      get_post_meta((int)$id,'_mad4b_transfer_pdf_2026_source_key',true)===$key)
    return true;
  }
  return false;
 }
 private static function apply_overrides(array &$profile,array $data,
   array &$provenance,string $level) {
  foreach(self::METRICS as $metric){
   if(!array_key_exists($metric,$data))continue;
   $raw=$data[$metric];
   if($raw===''||$raw===null)continue;
   $value=self::valid_amount($raw,$metric);
   if(is_wp_error($value))return $value;
   $profile[$metric]=$value;
   $provenance[$metric]=$level;
  }
  return true;
 }
 private static function source_rows(int $source,array &$profile,
    array &$provenance,string $vehicle,bool &$dispatchEnabled,
    string &$dispatchPolicySource) {
  $rows=get_post_meta($source,self::SOURCE_ROWS,true);
  if($rows===''||$rows===null||$rows===[])return true;
  if(!is_array($rows)||count($rows)>12)
   return new WP_Error('transport_source_override_schema_invalid');
  $seen=[];
  foreach($rows as $row){
   if(!is_array($row))return new WP_Error('transport_source_override_row_invalid');
   $key=(string)($row['vehicle_key']??'');
   if(!in_array($key,MAD4B_Transport_Distance_Time_V1::VEHICLES,true)||
     isset($seen[$key]))return new WP_Error('transport_source_override_vehicle_invalid');
   $seen[$key]=true;
   if($key!==$vehicle)continue;
   $activation=(string)($row['dispatch_mode']??'inherit');
   if(!in_array($activation,['inherit','enabled','disabled'],true))
    return new WP_Error('transport_source_dispatch_mode_invalid');
   if($activation!=='inherit'){
    $dispatchEnabled=$activation==='enabled';
    $dispatchPolicySource='transportation_vehicle_row';
   }
   $mode=(string)($row['mode']??'inherit');
   if(!in_array($mode,['inherit','override'],true))
    return new WP_Error('transport_source_override_mode_invalid');
   if($mode==='override'){
    $result=self::apply_overrides($profile,$row,$provenance,'transportation_post_meta');
    if(is_wp_error($result))return $result;
   }
  }
  return true;
 }
 private static function rate_fields(int $rate,array &$profile,array &$provenance,
    bool &$dispatchEnabled,string &$dispatchPolicySource) {
  $activation=(string)get_post_meta($rate,'trans_dispatch_mode',true);
  if($activation==='')$activation='inherit';
  if(!in_array($activation,['inherit','enabled','disabled'],true))
   return new WP_Error('transport_rate_dispatch_mode_invalid');
  if($activation!=='inherit'){
   $dispatchEnabled=$activation==='enabled';
   $dispatchPolicySource='native_transport_rate';
  }
  $mode=(string)get_post_meta($rate,self::RATE_MODE,true);
  if($mode===''||$mode==='inherit')return true;
  if($mode!=='override')return new WP_Error('transport_rate_override_mode_invalid');
  $data=[];
  foreach(self::METRICS as $metric)
   $data[$metric]=get_post_meta($rate,'trans_cost_'.$metric.'_override',true);
  return self::apply_overrides($profile,$data,$provenance,'native_transport_rate');
 }
 public static function resolve(array $input,array $policy) {
  $source=absint($input['transportation_id']??0);
  $rate=absint($input['transport_rate_id']??0);
  $tour=absint($input['tour_rate_id']??0);
  if(!$source&&!$rate&&!$tour)return null;
  $vehicle=sanitize_key((string)($input['vehicle']??''));
  $linked=[];
  if($rate){
   if(get_post_type($rate)!=='transport-rates'||
      !in_array(get_post_status($rate),['draft','pending','publish'],true))
    return new WP_Error('transport_rate_identity_invalid');
   $bound=(int)get_post_meta($rate,'source_post_id',true);
   if($source&&$source!==$bound)
    return new WP_Error('transport_rate_source_mismatch');
   $source=$bound;
   $v=sanitize_key((string)get_post_meta($rate,'trans_vehicle_category_key',true));
   if(!in_array($v,MAD4B_Transport_Distance_Time_V1::VEHICLES,true))
    return new WP_Error('transport_rate_vehicle_unassigned');
   if($vehicle&&$vehicle!==$v)return new WP_Error('transport_vehicle_mismatch');
   $vehicle=$v;
  }
  if(!$source||get_post_type($source)!=='transportations'||
     !in_array(get_post_status($source),['draft','pending','publish'],true))
   return new WP_Error('transportation_source_invalid');
  if(get_post_meta($source,'_mad4b_transfer_pdf_2026_provider_id',true)!=='self'||
     get_post_meta($source,'_mad4b_transfer_pdf_2026_sha256',true)!==
       MAD4B_Transport_Distance_Time_V1::SHA)
   return new WP_Error('transportation_self_source_not_verified');
  if(!in_array($vehicle,MAD4B_Transport_Distance_Time_V1::VEHICLES,true))
   return new WP_Error('transportation_vehicle_selection_required');
  $sourceRates=get_post_meta($source,'_mad4b_transfer_pdf_2026_raw_vehicles',true);
  if(!is_array($sourceRates)||!array_key_exists($vehicle,$sourceRates)||
      !is_numeric($sourceRates[$vehicle])||(float)$sourceRates[$vehicle]<=0)
   return new WP_Error('transportation_vehicle_not_in_source');
  if($tour){
   if(get_post_type($tour)!=='tour-rates'||
      !in_array(get_post_status($tour),['draft','pending','publish'],true))
    return new WP_Error('tour_rate_identity_invalid');
   if(!self::has_tour_relation($tour,$source))
    return new WP_Error('tour_rate_transport_not_selected');
  }
  $profile=$policy['profiles'][$vehicle]??null;
  if(!is_array($profile))return new WP_Error('transportation_cost_profile_not_found');
  $provenance=array_fill_keys(self::METRICS,'company_vehicle_profile');
  $dispatchEnabled=(bool)($policy['dispatch_policy']['enabled']??false);
  $dispatchPolicySource='company_global_policy';
  $resolved=self::source_rows($source,$profile,$provenance,$vehicle,
   $dispatchEnabled,$dispatchPolicySource);
  if(is_wp_error($resolved))return $resolved;
  if($rate){
   $resolved=self::rate_fields($rate,$profile,$provenance,
    $dispatchEnabled,$dispatchPolicySource);
   if(is_wp_error($resolved))return $resolved;
  }
  $terms=[];
  if($rate){
   $terms['from_term_id']=absint(get_post_meta($rate,'trans_from_term_id',true));
   $terms['to_term_id']=absint(get_post_meta($rate,'trans_to_term_id',true));
  }
  if(empty($terms['from_term_id']))
   $terms['from_term_id']=absint(get_post_meta($source,'_mad4b_pdf_route_origin_term_id_v1',true));
  if(empty($terms['to_term_id']))
   $terms['to_term_id']=absint(get_post_meta($source,'_mad4b_pdf_route_destination_term_id_v1',true));
  $known=(float)$sourceRates[$vehicle];
  $discount=get_post_meta($source,'_mad4b_transfer_pdf_2026_city',true)==='Cairo'?0.0:5.0;
  $anchor=round($known*(1-$discount/100),2);
  $rateCost=$rate?get_post_meta($rate,'trans_cost_amount',true):'';
  $rateCurrency=$rate?(string)get_post_meta($rate,'currency',true):'';
  $rateRole=$rate?(string)get_post_meta($rate,'trans_price_role',true):'';
  if($rate){
   $rawMarkup=get_post_meta($rate,'trans_cost_markup_override_pct',true);
   if($rawMarkup!==''&&$rawMarkup!==null&&
      (!is_scalar($rawMarkup)||!is_numeric($rawMarkup)||
       (float)$rawMarkup<0||(float)$rawMarkup>200))
    return new WP_Error('transport_rate_markup_override_invalid');
  }
  return [
   'transportation_id'=>$source,'transport_rate_id'=>$rate,
   'tour_rate_id'=>$tour,'vehicle'=>$vehicle,
   'profile'=>$profile,'cost_field_sources'=>$provenance,
   'dispatch_enabled'=>$dispatchEnabled,
   'dispatch_policy_source'=>$dispatchPolicySource,
   'source_pdf_key'=>get_post_meta($source,'_mad4b_transfer_pdf_2026_source_key',true),
   'source_actual_cost_egp'=>$anchor,
   'source_raw_pdf_cost_egp'=>$known,
   'source_supplier_discount_pct'=>$discount,
   'native_rate_cost_reference_egp'=>is_numeric($rateCost)&&$rateCurrency==='EGP'&&
     in_array($rateRole,['self_cost','actual_company_cost'],true)?
     round((float)$rateCost,2):null,
   'geometry_terms'=>$terms,
   'existing_native_operational_fields'=>[
    'included_km_allowance'=>get_post_meta($source,'trans_included_km',true),
    'estimated_route_duration_raw'=>get_post_meta($source,
       'trans_route_estimated_duration',true),
    'extra_km_customer_price'=>$rate?
       get_post_meta($rate,'trans_extra_km_price_base',true):null,
    'overtime_customer_price_hour'=>$rate?
       get_post_meta($rate,'trans_overtime_price_per_hour_base',true):null
   ],
   'price_policy'=>$tour?
     (string)get_post_meta($tour,'transport_price_policy',true):null,
   'not_a_commercial_quote'=>true
  ];
 }
 public static function catalog_options():array {
  if(!MAD4B_Transport_Distance_Time_V1::permitted())return [];
  $sources=get_posts(['post_type'=>'transportations',
   'post_status'=>['publish','draft'],'posts_per_page'=>80,'fields'=>'ids',
   'suppress_filters'=>true,
   'meta_query'=>[
    ['key'=>'_mad4b_transfer_pdf_2026_sha256',
     'value'=>MAD4B_Transport_Distance_Time_V1::SHA],
    ['key'=>'_mad4b_transfer_pdf_2026_editorial_language','value'=>'en']
   ]]);
  $sourceOptions=[];
  foreach($sources as $id)$sourceOptions[]=[
   'id'=>(int)$id,'title'=>get_the_title($id),
   'source_key'=>(string)get_post_meta($id,
     '_mad4b_transfer_pdf_2026_source_key',true)
  ];
  $rateOptions=[];
  $rates=get_posts(['post_type'=>'transport-rates',
   'post_status'=>['publish','draft','pending'],
   'posts_per_page'=>200,'fields'=>'ids','suppress_filters'=>true]);
  foreach($rates as $id){
   $bound=(int)get_post_meta($id,'source_post_id',true);
   $vehicle=(string)get_post_meta($id,'trans_vehicle_category_key',true);
   if(get_post_meta($bound,'_mad4b_transfer_pdf_2026_sha256',true)!==
      MAD4B_Transport_Distance_Time_V1::SHA ||
      !in_array($vehicle,MAD4B_Transport_Distance_Time_V1::VEHICLES,true))
    continue;
   $rateOptions[]=['id'=>(int)$id,'title'=>get_the_title($id),
    'source_id'=>$bound,'vehicle'=>$vehicle];
  }
  return ['sources'=>$sourceOptions,'native_rates'=>$rateOptions,
    'tour_rate_override_field'=>'transport_markup_override_pct'];
 }
}
