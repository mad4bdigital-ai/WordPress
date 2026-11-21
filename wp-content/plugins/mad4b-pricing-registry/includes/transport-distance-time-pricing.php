<?php
/**
 * MAD4B Self Transport: draft-only route cost and reference calibration.
 * No Transport Rate, checkout, fleet availability or CPT publication writes.
 */
defined('ABSPATH') || exit;
final class MAD4B_Transport_Distance_Time_V1 {
 const OPTION='mad4b_transport_distance_time_v1';
 const HISTORY='mad4b_transport_distance_time_history_v1';
 const AJAX='mad4b_transport_distance_time_v1';
 const SHA='c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd';
 const VEHICLES=['limousine','luxury_7_seats','h1','hiace','coaster','bus'];
 const SERVICES=['transfer','half_day','full_day','sightseeing','overnight'];
 const ROADS=['city','highway','desert'];
 public static function permitted():bool {
  return class_exists('MAD4B_Transport_Self_Pricing_Controls_V1')
   && MAD4B_Transport_Self_Pricing_Controls_V1::permitted();
 }
 public static function base_profile():array {
  return ['fixed'=>0.0,'per_km'=>0.0,'per_drive_min'=>0.0,
   'per_wait_min'=>0.0,'overnight'=>0.0,'min_fare'=>0.0,
   'deadhead_pct'=>0.0,'source'=>'manual_draft','samples'=>0];
 }
 public static function defaults():array {
  $profiles=[];$marks=[];
  foreach(self::VEHICLES as $v){
   $profiles[$v]=self::base_profile();
   $marks[$v]=['mode'=>'inherit','pct'=>0.0];
  }
  $services=[];foreach(self::SERVICES as $v)
   $services[$v]=['mode'=>'inherit','pct'=>0.0];
  return ['revision'=>0,'mode'=>'simulation_only','currency'=>'EGP',
   'currency_evidence'=>'owner_confirmed_not_printed_in_pdf',
   'source_sha256'=>self::SHA,'role'=>'self_cost',
   // Call-out BASE is an embedded minimum, NEVER a surcharge over km/min.
   // Activation defaults off until a company policy explicitly enables it.
   'profiles'=>$profiles,'dispatch_policy'=>[
     'enabled'=>false,
     'application'=>'included_minimum_not_additive',
     'cancellation'=>'non_refundable_after_confirmed_assignment'
   ],'global_markup_pct'=>0.0,
   'vehicle_markups'=>$marks,'service_markups'=>$services,
   'route_markups'=>[],
   'road'=>['city'=>['circuity'=>1.35,'speed'=>28,'factor'=>1],
    'highway'=>['circuity'=>1.25,'speed'=>68,'factor'=>1],
    'desert'=>['circuity'=>1.32,'speed'=>55,'factor'=>1]],
   'night_factor'=>1.0,'peak_factor'=>1.0,
   'discount_pct'=>0.0,'time_share'=>0.25,
   'updated_at'=>null,'updated_by'=>null];
 }
 public static function current():array {
  $out=self::defaults();$prior=get_option(self::OPTION,[]);
  if(is_array($prior)&&($prior['source_sha256']??null)===self::SHA)
   foreach($out as $key=>$v)
    if(array_key_exists($key,$prior))$out[$key]=$prior[$key];
  $out['vehicle_profile_owner']='legacy_company_option';
  if(class_exists('MAD4B_Transport_Vehicle_Term_Profiles_V1')){
   $terms=MAD4B_Transport_Vehicle_Term_Profiles_V1::profiles();
   if(is_wp_error($terms)){
    $out['vehicle_profile_owner']='invalid_term_meta_no_fallback';
    $out['vehicle_profile_error']=$terms->get_error_code();
   }elseif(is_array($terms)){
    foreach($terms as $key=>$item){
     $out['profiles'][$key]=$item['profile'];
     $out['vehicle_term_profiles'][$key]=[
      'term_id'=>$item['term_id'],'edit_url'=>$item['edit_url'],
      'dispatch_mode'=>$item['dispatch_mode']
     ];
    }
    $out['vehicle_profile_owner']='jetengine_taxonomy_term_meta';
   }
  }
  return $out;
 }
 private static function num($v,float $lo,float $hi) {
  if(!is_scalar($v)||is_bool($v)||!is_numeric($v)||
    !is_finite((float)$v)||(float)$v<$lo||(float)$v>$hi)
   return new WP_Error('transport_model_numeric_invalid');
  return round((float)$v,4);
 }
 private static function rule($r) {
  if(!is_array($r)||array_keys($r)!==['mode','pct']||
    !in_array($r['mode'],['inherit','override','adjustment'],true))
   return new WP_Error('transport_model_rule_invalid');
  $pct=self::num($r['pct'],-75,200);
  return is_wp_error($pct)?$pct:['mode'=>$r['mode'],'pct'=>$pct];
 }
 public static function validate(array $raw) {
  $needed=['profiles','dispatch_policy','global_markup_pct','vehicle_markups',
   'service_markups','route_markups','road','night_factor',
   'peak_factor','discount_pct','time_share'];
  if(array_keys($raw)!==$needed)return new WP_Error('transport_model_schema_invalid');
  $out=[];
  if(!is_array($raw['profiles'])||
     array_keys($raw['profiles'])!==self::VEHICLES)
   return new WP_Error('transport_model_profile_set_invalid');
  $out['profiles']=[];
  $limits=['fixed'=>[0,200000],'per_km'=>[0,10000],
   'per_drive_min'=>[0,1000],'per_wait_min'=>[0,1000],
   'overnight'=>[0,100000],'min_fare'=>[0,200000],
   'deadhead_pct'=>[0,200]];
  foreach(self::VEHICLES as $vehicle){
   $p=$raw['profiles'][$vehicle];
   if(!is_array($p)||array_keys($p)!==array_keys(self::base_profile()))
    return new WP_Error('transport_model_profile_shape');
   $clean=[];
   foreach($limits as $field=>[$lo,$hi]){
    $n=self::num($p[$field],$lo,$hi);
    if(is_wp_error($n))return $n;
    $clean[$field]=$n;
   }
   if(!in_array($p['source'],['manual_draft','pdf_proposal'],true))
    return new WP_Error('transport_model_profile_basis');
   $count=filter_var($p['samples'],FILTER_VALIDATE_INT,
     ['options'=>['min_range'=>0,'max_range'=>51]]);
   if($count===false)return new WP_Error('transport_model_profile_samples');
   $clean['source']=$p['source'];$clean['samples']=$count;
   $out['profiles'][$vehicle]=$clean;
  }
  $dispatch=$raw['dispatch_policy'];
  if(!is_array($dispatch)||
     array_keys($dispatch)!==['enabled','application','cancellation']||
     !is_bool($dispatch['enabled'])||
     $dispatch['application']!=='included_minimum_not_additive'||
     $dispatch['cancellation']!=='non_refundable_after_confirmed_assignment')
   return new WP_Error('transport_model_dispatch_policy_invalid');
  $out['dispatch_policy']=$dispatch;
  foreach(['global_markup_pct'=>[0,200],'night_factor'=>[0.5,3],
    'peak_factor'=>[0.5,3],'discount_pct'=>[0,80],
    'time_share'=>[0.05,0.45]] as $key=>[$lo,$hi]){
   $n=self::num($raw[$key],$lo,$hi);
   if(is_wp_error($n))return $n;
   $out[$key]=$n;
  }
  foreach(['vehicle_markups'=>self::VEHICLES,
     'service_markups'=>self::SERVICES] as $key=>$keys){
   if(!is_array($raw[$key])||array_keys($raw[$key])!==$keys)
    return new WP_Error('transport_model_rule_dimensions');
   $out[$key]=[];
   foreach($keys as $name){
    $r=self::rule($raw[$key][$name]);
    if(is_wp_error($r))return $r;
    $out[$key][$name]=$r;
   }
  }
  if(!is_array($raw['route_markups'])||
    count($raw['route_markups'])>60)
   return new WP_Error('transport_model_routes_invalid');
  $out['route_markups']=[];
  foreach($raw['route_markups'] as $route=>$rule){
   if(!is_string($route)||
      !preg_match('/^[a-z0-9_-]+:[a-z0-9_-]+$/D',$route)||
      strlen($route)>120)
    return new WP_Error('transport_model_route_key');
   $r=self::rule($rule);
   if(is_wp_error($r))return $r;
   $out['route_markups'][$route]=$r;
  }
  if(!is_array($raw['road'])||array_keys($raw['road'])!==self::ROADS)
   return new WP_Error('transport_model_road_invalid');
  $out['road']=[];
  foreach(self::ROADS as $kind){
   $r=$raw['road'][$kind];
   if(!is_array($r)||array_keys($r)!==['circuity','speed','factor'])
    return new WP_Error('transport_model_road_shape');
   $out['road'][$kind]=[];
   foreach(['circuity'=>[1,2.5],'speed'=>[5,120],
    'factor'=>[0.5,3]] as $name=>[$lo,$hi]){
    $v=self::num($r[$name],$lo,$hi);
    if(is_wp_error($v))return $v;
    $out['road'][$kind][$name]=$v;
   }
  }
  // Canonical deterministic order expected by the admin UI and test.
  return ['profiles'=>$out['profiles'],
   'dispatch_policy'=>$out['dispatch_policy'],
   'global_markup_pct'=>$out['global_markup_pct'],
   'vehicle_markups'=>$out['vehicle_markups'],
   'service_markups'=>$out['service_markups'],
   'route_markups'=>$out['route_markups'],'road'=>$out['road'],
   'night_factor'=>$out['night_factor'],'peak_factor'=>$out['peak_factor'],
   'discount_pct'=>$out['discount_pct'],'time_share'=>$out['time_share']];
 }
 public static function save(array $raw,int $expected) {
  if(!self::permitted())return new WP_Error('transport_model_forbidden');
  $v=self::validate($raw);
  if(is_wp_error($v))return $v;
  // After migration, native Term Meta is sole owner of vehicle unit costs.
  // Do not silently write changed vehicle costs to the obsolete option.
  $actual=self::current();
  if(($actual['vehicle_profile_owner']??'')==='invalid_term_meta_no_fallback')
   return new WP_Error('vehicle_term_profile_invalid');
  if(($actual['vehicle_profile_owner']??'')==='jetengine_taxonomy_term_meta'&&
     $v['profiles']!==$actual['profiles'])
   return new WP_Error('vehicle_profiles_are_taxonomy_owned');
  $lock=self::OPTION.'_lock';
  if(!add_option($lock,time(),'',false)){
   $at=get_option($lock,0);
   if(!is_numeric($at)||time()-(int)$at<90||
      !delete_option($lock)||!add_option($lock,time(),'',false))
    return new WP_Error('transport_model_concurrent_write');
  }
  try {
   $old=self::current();
   if((int)$old['revision']!==$expected)
    return new WP_Error('transport_model_revision_conflict');
   $updated=array_merge($old,$v,
    ['revision'=>$expected+1,'mode'=>'simulation_only',
     'role'=>'self_cost','currency'=>'EGP',
     'updated_by'=>get_current_user_id(),'updated_at'=>gmdate('c')]);
   // The option retains a rollback shadow, never authoritative Term IDs/URLs.
   unset($updated['vehicle_profile_owner'],$updated['vehicle_profile_error'],
     $updated['vehicle_term_profiles']);
   update_option(self::OPTION,$updated,false);
   $read=self::current();
   if($read['revision']!==$expected+1||
     $read['profiles']!==$updated['profiles'])
    return new WP_Error('transport_model_readback_failed');
   $history=get_option(self::HISTORY,[]);
   if(!is_array($history))$history=[];
   $history[]=['at'=>gmdate('c'),'actor'=>get_current_user_id(),
    'from'=>$expected,'to'=>$expected+1,
    'before_hash'=>hash('sha256',wp_json_encode($old)),
    'after_hash'=>hash('sha256',wp_json_encode($updated)),
    'checkout_authorized'=>false];
   update_option(self::HISTORY,array_slice($history,-40),false);
   return ['status'=>'saved_simulation_only','policy'=>$read,
    'checkout_authorized'=>false];
  }finally {delete_option($lock);}
 }
 private static function coords(int $id):?array {
  $term=get_term($id,'destinations');
  if(!$term instanceof WP_Term)return null;
  $v=(string)get_term_meta($id,'google_location',true);
  if(!preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/D',$v,$m))
   return null;
  return self::valid_coords((float)$m[1],(float)$m[2])?
   [(float)$m[1],(float)$m[2]]:null;
 }
 private static function valid_coords(float $lat,float $lon):bool {
  return is_finite($lat)&&is_finite($lon)&&
   $lat>=-90&&$lat<=90&&$lon>=-180&&$lon<=180;
 }
 private static function haversine(array $a,array $b):float {
  $deltaLat=deg2rad($b[0]-$a[0]);
  $deltaLon=deg2rad($b[1]-$a[1]);
  $h=sin($deltaLat/2)**2+cos(deg2rad($a[0]))*
   cos(deg2rad($b[0]))*sin($deltaLon/2)**2;
  return 6371.0088*2*asin(min(1.0,sqrt(max(0.0,$h))));
 }
 /**
  * Native Destination centroid is *not* a verified pickup/dropoff point.
  * 1.25 etc are adjustable planning multipliers, not measured road routes.
  */
 public static function route(array $request,array $policy) {
  $road=(string)($request['road']??'highway');
  if(!in_array($road,self::ROADS,true))
   return new WP_Error('transport_route_type_invalid');
  $points=[];$basis=[];
  foreach(['from','to'] as $side){
   $id=filter_var($request[$side.'_term_id']??0,FILTER_VALIDATE_INT,
    ['options'=>['min_range'=>0]]);
   if($id===false)return new WP_Error('transport_route_term_invalid');
   $supplied=$request[$side.'_lat_lon']??null;
   if($supplied!==null && $supplied!==''){
    if(!is_array($supplied)||count($supplied)!==2)
     return new WP_Error('transport_route_coordinates_shape');
    foreach($supplied as $part)
     if(!is_scalar($part)||!is_numeric($part))
      return new WP_Error('transport_route_coordinates_invalid');
    $c=array_map('floatval',$supplied);
    if(!self::valid_coords($c[0],$c[1]))
     return new WP_Error('transport_route_coordinates_invalid');
    $points[$side]=$c;$basis[$side]='operator_supplied_coordinates';
   }else{
    $points[$side]=$id?self::coords($id):null;
    $basis[$side]='native_destination_centroid';
   }
  }
  if(!$points['from']||!$points['to'])return null;
  $straight=self::haversine($points['from'],$points['to']);
  if($straight<1||$straight>4000)return null;
  $assumption=$policy['road'][$road];
  $km=round($straight*$assumption['circuity'],1);
  $minutes=(int)ceil($km/$assumption['speed']*60);
  return ['km'=>$km,'minutes'=>$minutes,
   'straight_km'=>round($straight,1),
   'method'=>'geodesic_x_road_factor_not_navigation',
   'confidence'=>'low_estimate','geometry_verified'=>false,
   'assumptions'=>$assumption,'coordinate_sources'=>$basis];
 }
 private static function effective_markup(array $policy,string $vehicle,
   string $service,string $routeKey,int $tourRate,int $transportRate=0):array {
  $base=$policy['global_markup_pct'];$source='global';$adjust=[];
  $rules=['vehicle'=>$policy['vehicle_markups'][$vehicle],
   'service'=>$policy['service_markups'][$service]];
  if(isset($policy['route_markups'][$routeKey]))
   $rules['route']=$policy['route_markups'][$routeKey];
  foreach($rules as $name=>$rule){
   if($rule['mode']==='override'){
    $base=$rule['pct'];$source=$name;
   }elseif($rule['mode']==='adjustment')
    $adjust[$name]=$rule['pct'];
  }
  // Native Transport Rate overrides its inherited base before Tour Rate.
  if($transportRate>0&&get_post_type($transportRate)==='transport-rates'){
   $candidate=get_post_meta($transportRate,'trans_cost_markup_override_pct',true);
   if($candidate!==''&&is_scalar($candidate)&&is_numeric($candidate)&&
      (float)$candidate>=0&&(float)$candidate<=200){
    $base=(float)$candidate;$source='transport_rate';
   }
  }
  // Tour Rate is authoritative only when editing the exact Rate. No
  // browser-submitted override can become authoritative.
  if($tourRate>0&&get_post_type($tourRate)==='tour-rates'&&
    current_user_can('edit_post',$tourRate)){
   $raw=get_post_meta($tourRate,'transport_markup_override_pct',true);
   if($raw!==''&&is_numeric($raw)&&(float)$raw>=0&&(float)$raw<=200){
    $base=(float)$raw;$source='tour_rate';
   }
  }
  return ['effective_pct'=>max(-75,min(300,$base+array_sum($adjust))),
   'base_rule'=>$source,'base_pct'=>$base,
   'explicit_adjustments'=>$adjust];
 }
 public static function calculate(array $input) {
  $policy=self::current();
  if(($policy['vehicle_profile_owner']??'')==='invalid_term_meta_no_fallback')
   return new WP_Error('vehicle_term_profile_invalid');
  $context=null;
  if(class_exists('MAD4B_Transport_Native_Cost_Inheritance_V1')){
   $context=MAD4B_Transport_Native_Cost_Inheritance_V1::resolve($input,$policy);
   if(is_wp_error($context))return $context;
   if(is_array($context)){
    $input['vehicle']=$context['vehicle']; // authoritative native Rate category
    foreach(['from_term_id','to_term_id'] as $key)
     if(empty($input[$key])&&!empty($context['geometry_terms'][$key]))
      $input[$key]=$context['geometry_terms'][$key];
   }
  }
  $vehicle=sanitize_key((string)($input['vehicle']??''));
  $service=sanitize_key((string)($input['service']??'transfer'));
  $road=sanitize_key((string)($input['road']??'highway'));
  if(!in_array($vehicle,self::VEHICLES,true)||
   !in_array($service,self::SERVICES,true)||
   !in_array($road,self::ROADS,true))
   return new WP_Error('transport_quote_dimension_invalid');
  $geometry=self::route($input,$policy);
  if(is_wp_error($geometry))return $geometry;
  $manualKm=$input['km']??'';
  $manualMins=$input['drive_min']??'';
  if(($manualKm==='')!==($manualMins===''))
   return new WP_Error('transport_quote_distance_time_pair_required');
  $manual=$manualKm!=='';
  if($manual){
   $km=self::num($manualKm,0.5,5000);
   $mins=self::num($manualMins,1,20000);
  }elseif($geometry){
   $km=$geometry['km'];$mins=$geometry['minutes'];
  }else return new WP_Error('transport_quote_route_unresolved');
  if(is_wp_error($km))return $km;
  if(is_wp_error($mins))return $mins;
  $values=[];
  foreach(['wait_min'=>[0,10080],'overnight_units'=>[0,30],
    'tolls'=>[0,200000],'parking'=>[0,200000],
    'extras'=>[0,200000]] as $key=>[$lo,$hi]){
   $v=self::num($input[$key]??0,$lo,$hi);
   if(is_wp_error($v))return $v;
   $values[$key]=$v;
  }
  if(!is_bool($input['night']??false)||
    !is_bool($input['peak']??false))
   return new WP_Error('transport_quote_boolean_invalid');
  $profile=is_array($context)?$context['profile']:$policy['profiles'][$vehicle];
  $dispatchEnabled=$policy['dispatch_policy']['enabled'];
  if(is_array($context)&&array_key_exists('dispatch_enabled',$context))
   $dispatchEnabled=$context['dispatch_enabled'];
  if($profile['per_km']<=0||$profile['per_drive_min']<=0)
   return new WP_Error('transport_quote_profile_not_calibrated');
  $deadheadKm=$km*$profile['deadhead_pct']/100;
  $parts=[
   // Vehicle BASE stored in existing 'fixed' cost profile, not duplicated.
   'fixed'=>0.0,
   'distance'=>round(($km+$deadheadKm)*$profile['per_km'],2),
   'driving_time'=>round($mins*$profile['per_drive_min'],2),
   'waiting'=>round($values['wait_min']*$profile['per_wait_min'],2),
   'overnight'=>round($values['overnight_units']*$profile['overnight'],2),
   'tolls'=>$values['tolls'],'parking'=>$values['parking'],
   'extras'=>$values['extras']
  ];
  $movement=($parts['distance']+$parts['driving_time'])*
   $policy['road'][$road]['factor'];
  $dispatchBase=$dispatchEnabled?(float)$profile['fixed']:0.0;
  // The BASE is covered by the existing movement cost. Only the
  // difference, if movement is smaller, lifts it to the minimum.
  $dispatchFloor=max(0.0,$dispatchBase-$movement);
  $dispatchCovered=min($dispatchBase,$movement);
  $nightFactor=$input['night']?$policy['night_factor']:1.0;
  $peakFactor=$input['peak']?$policy['peak_factor']:1.0;
  $operating=($movement+$dispatchFloor+$parts['waiting']+
    $parts['overnight'])*$nightFactor*$peakFactor;
  $cost=round($operating+$parts['tolls']+$parts['parking']+
    $parts['extras'],2);
  // Route markup is derived from the selected, resolved native path when
  // such a path exists. Prevent unrelated browser-provided route keys from
  // hijacking the applicable route's company pricing policy.
  $routeKey=is_array($context)?'':(string)($input['route_key']??'');
  if($routeKey===''){
   $from=get_term(absint($input['from_term_id']??0),'destinations');
   $to=get_term(absint($input['to_term_id']??0),'destinations');
   if($from instanceof WP_Term&&$to instanceof WP_Term)
    $routeKey=$from->slug.':'.$to->slug;
  }
  // Display-only route labels, never proof of a road or supplier point.
  $originLabel=sanitize_text_field((string)($input['from_label']??''));
  $destLabel=sanitize_text_field((string)($input['to_label']??''));
  if(mb_strlen($originLabel)>100||mb_strlen($destLabel)>100)
   return new WP_Error('transport_quote_label_too_long');
  $markup=self::effective_markup($policy,$vehicle,$service,
    $routeKey,is_array($context)?$context['tour_rate_id']:0,
    is_array($context)?$context['transport_rate_id']:0);
  $beforeMin=$cost*(1+$markup['effective_pct']/100);
  $afterMin=max($profile['min_fare'],$beforeMin);
  $scenario=round(max($profile['min_fare'],
    $afterMin*(1-$policy['discount_pct']/100)),2);
  return [
   'contract'=>'mad4b.transport-draft-cost-estimate.v1',
   'status'=>'draft_estimate_not_commercial','currency'=>'EGP',
   'currency_basis'=>'owner_confirmed_not_pdf_text',
   'vehicle'=>$vehicle,'service'=>$service,'road'=>$road,
   'operator_origin_label'=>$originLabel,'operator_destination_label'=>$destLabel,
   'distance_km'=>$km,'drive_minutes'=>$mins,
   'deadhead_km'=>round($deadheadKm,2),
   'distance_provenance'=>$manual?'operator_manual_unverified':
     'native_city_centroid_estimation_unverified',
   'geometry'=>$geometry,'line_items'=>$parts,
   'dispatch_base'=>[
     'enabled'=>$dispatchEnabled,'vehicle_base_egp'=>$dispatchBase,
     'covered_by_movement_egp'=>round($dispatchCovered,2),
     'floor_difference_egp'=>round($dispatchFloor,2),
     'billed_additional_surcharge_egp'=>0.0,
     'application'=>'included_minimum_not_additive',
     'cancellation_policy'=>'non_refundable_after_confirmed_assignment',
     'cancellation_retention_auto_applied'=>false,
     'requires_booking_terms_and_supplier_assignment'=>true
   ],
   'road_cost_factor'=>$policy['road'][$road]['factor'],
   'night_factor'=>$nightFactor,'peak_factor'=>$peakFactor,
   'estimated_operating_cost_egp'=>$cost,
   'markup'=>$markup,'discount_pct'=>$policy['discount_pct'],
   'minimum_fare_egp'=>$profile['min_fare'],
   'indicative_selling_scenario_egp'=>$scenario,
   'profile_basis'=>$profile['source'],
   'native_inheritance'=>$context,
   'inheritance_verified'=>is_array($context),
   'cost_profile_updated_post_meta'=>false,
   'tax_included'=>false,'source_price_role'=>'self_cost',
   'owner_confirmed_pdf_cost_basis'=>true,
   'marginal_km_min_not_independently_measured'=>true,
   'routing_provider_verified'=>false,'availability_verified'=>false,
   'supplier_capacity_verified'=>false,'native_transport_rate_approved'=>false,
   'checkout_authorized'=>false
  ];
 }
 private static function median(array $values):float {
  if(!$values)return 0;
  sort($values,SORT_NUMERIC);$n=count($values);$mid=intdiv($n,2);
  return $n%2?$values[$mid]:($values[$mid-1]+$values[$mid])/2;
 }
 /**
  * Reference-price calibration proposal, NOT identification of actual fuel,
  * labour or wear cost. One observation cannot independently identify two
  * coefficients; the model fixes a disclosed time-share assumption.
  */
 public static function calibrate():array {
  $activeProfile=self::current();
  if(($activeProfile['vehicle_profile_owner']??'')==='invalid_term_meta_no_fallback')
   return ['error'=>'vehicle_term_profile_invalid','saved_to_pricing'=>false,
    'checkout_authorized'=>false];
  $source=MAD4B_Transport_Self_Pricing_Controls_V1::source();
  $routes=require dirname(__DIR__).'/data/transport-pdf-2026-route-evidence.php';
  $policy=self::current();
  if(!$source||($routes['source_sha256']??'')!==self::SHA)
   return ['error'=>'transport_pdf_evidence_invalid'];
  $observed=[];
  foreach($source['rows'] as $r){
   $e=$routes['entries'][$r['source_key']]??null;
   if(!$e||$r['service_kind']!=='intercity_transfer'||
    $e['route_shape']!=='one_way_city'||count($e['dest_slugs'])!==1)
    continue;
   $a=get_term_by('slug',$e['origin_slug'],'destinations');
   $b=get_term_by('slug',$e['dest_slugs'][0],'destinations');
   if(!$a||!$b)continue;
   $geo=self::route(['road'=>'highway',
    'from_term_id'=>$a->term_id,'to_term_id'=>$b->term_id],$policy);
   if(is_wp_error($geo)||!$geo||$geo['km']<25)continue;
   foreach($r['vehicle_rates_unverified'] as $vehicle=>$amount){
    if(!in_array($vehicle,self::VEHICLES,true)||!is_numeric($amount)||
      (float)$amount<=0)continue;
    $disc=$r['city']==='Cairo'?0.0:5.0;
    $net=round((float)$amount*(1-$disc/100),2);
    $observed[$vehicle][]=[
     'source_key'=>$r['source_key'],'raw_pdf_egp'=>(float)$amount,
     'supplier_discount_pct'=>$disc,'reference_after_discount_egp'=>$net,
     'approx_km'=>$geo['km'],'approx_drive_min'=>$geo['minutes'],
     'origin_slug'=>$e['origin_slug'],'destination_slug'=>$e['dest_slugs'][0],
     'route_confidence'=>'low_not_road_verified'
    ];
   }
  }
  $share=$policy['time_share'];$byVehicle=[];
  foreach(self::VEHICLES as $vehicle){
   $samples=$observed[$vehicle]??[];$ratios=[];
   foreach($samples as $s){
    $exposure=(1-$share)*$s['approx_km']+
       $share*$s['approx_drive_min']; // 1 km/min normalizing reference
    $ratios[]=$s['reference_after_discount_egp']/$exposure;
   }
   $median=self::median($ratios);
   $used=0;$deviation=[];$details=[];
   foreach($samples as $i=>$s){
    $outlier=$median>0&&abs($ratios[$i]/$median-1)>0.35;
    $pred=$median*((1-$share)*$s['approx_km']+
     $share*$s['approx_drive_min']);
    $diff=round(100*($pred/$s['reference_after_discount_egp']-1),1);
    if(!$outlier){$used++;$deviation[]=abs($diff);}
    $details[]=$s+['estimated_by_fit_egp'=>round($pred,2),
     'difference_pct'=>$diff,
     'decision'=>$outlier?'excluded_outlier_review':'provisional_sample'];
   }
   $enough=count($samples)>=4&&$used>=3;
   $p=self::base_profile();
   if($enough){
    $p['per_km']=round($median*(1-$share),2);
    $p['per_drive_min']=round($median*$share,2);
    $p['source']='pdf_proposal';$p['samples']=$used;
   }
   $byVehicle[$vehicle]=[
    'status'=>$enough?'proposal_not_approved':'insufficient_examples',
    'samples'=>count($samples),'accepted'=>$used,
    'excluded'=>count($samples)-$used,
    'median_absolute_deviation_pct'=>round(self::median($deviation),1),
    'proposal'=>$enough?$p:null,'evidence'=>$details,
    'confidence'=>'low_nonindependent_assumed_geometry'
   ];
  }
  return [
   'contract'=>'mad4b.transport-reference-calibration.v1',
   'source_sha256'=>self::SHA,'reference_currency'=>'EGP',
   'currency_basis'=>'owner_confirmation_not_pdf_text',
   'source_role'=>'actual_company_cost',
   'sample_scope'=>'one_way_intercity_clear_destination_only',
   'excluded'=>['multiple_destinations','round_trips',
    'half_and_full_day','sightseeing','overnight','missing_map_points',
    'Luxor:7_anomalous_vehicle_prices'],
   'distance_basis'=>'native_city_centroids_with_road_circuity',
   'time_allocation_share'=>$share,
   'coefficient_identifiability'=>'assumed_split_not_regression_proof',
   'note'=>'PDF amounts are confirmed company costs. Their decomposition into EGP/km and EGP/min is a planning estimate requiring real road and time evidence.',
   'profiles'=>$byVehicle,'saved_to_pricing'=>false,
   'checkout_authorized'=>false
  ];
 }
 public static function catalog():array {
  $slugs=['cairo','aswan-gov','luxor','hurghada','marsa-alam',
   'abu-simbel','edfu-city','kom-ombo-city'];
  $terms=[];
  foreach($slugs as $slug){
   $t=get_term_by('slug',$slug,'destinations');
   if(!$t)continue;
   $terms[]=['id'=>(int)$t->term_id,'name'=>$t->name,
    'slug'=>$slug,'has_coordinates'=>self::coords((int)$t->term_id)!==null];
  }
  return ['policy'=>self::current(),
   'calibration'=>self::calibrate(),'terms'=>$terms,
   'languages'=>array_keys((array)apply_filters('wpml_active_languages',null,['skip_missing'=>0])),
   'native_context'=>class_exists('MAD4B_Transport_Native_Cost_Inheritance_V1')?
    MAD4B_Transport_Native_Cost_Inheritance_V1::catalog_options():[],
   'mode'=>'simulation_only','no_checkout'=>true];
 }
 public static function ajax():void {
  if(!self::permitted())wp_send_json_error(['code'=>'forbidden'],403);
  check_ajax_referer(self::AJAX,'nonce');
  $mode=sanitize_key((string)($_POST['mode']??''));
  if($mode==='catalog')$result=self::catalog();
  elseif($mode==='quote'){
   $json=is_string($_POST['payload']??null)?
    wp_unslash($_POST['payload']):'';
   if(strlen($json)>6500)wp_send_json_error(['code'=>'payload_too_long'],400);
   $raw=json_decode($json,true);
   $result=is_array($raw)?self::calculate($raw):
    new WP_Error('transport_quote_invalid_json');
  }elseif($mode==='eligibility'){
   $json=is_string($_POST['payload']??null)?wp_unslash($_POST['payload']):'';
   if(strlen($json)>6500)wp_send_json_error(['code'=>'payload_too_long'],400);
   $raw=json_decode($json,true);
   $result=is_array($raw)&&is_array($raw['stage']??null)&&
    class_exists('MAD4B_Transport_Package_Workflow_V1')?
     MAD4B_Transport_Package_Workflow_V1::candidates(
       $raw['stage'],sanitize_key((string)($raw['lang']??'en'))):
     new WP_Error('transport_eligibility_input_invalid');
  }elseif($mode==='bridge'){
   $json=is_string($_POST['payload']??null)?
    wp_unslash($_POST['payload']):'';
   if(strlen($json)>26000)wp_send_json_error(['code'=>'payload_too_long'],400);
   $raw=json_decode($json,true);
   $result=is_array($raw)&&class_exists('MAD4B_Transport_Cost_Bridge_V1')?
    MAD4B_Transport_Cost_Bridge_V1::evaluate($raw):
    new WP_Error('transport_bridge_invalid_json_or_unavailable');
  }elseif($mode==='save'){
   $json=is_string($_POST['policy']??null)?
    wp_unslash($_POST['policy']):'';
   if(strlen($json)>18000)wp_send_json_error(['code'=>'policy_too_long'],400);
   $raw=json_decode($json,true);
   $expected=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT,
    ['options'=>['min_range'=>0]]);
   $result=is_array($raw)&&$expected!==false&&$expected!==null?
    self::save($raw,(int)$expected):
    new WP_Error('transport_model_save_input_invalid');
  }else $result=new WP_Error('transport_model_action_unknown');
  if(is_wp_error($result))
   wp_send_json_error(['code'=>$result->get_error_code()],400);
  wp_send_json_success($result);
 }
 public static function assets():void {
  if(!is_admin()||!self::permitted())return;
  $page=sanitize_key((string)($_GET['page']??''));
  if(!in_array($page,['dynamic-pricing-controls',
    'etg-dynamic-pricing-controls'],true))return;
  $dir=dirname(__DIR__).'/assets/';
  if(!is_file($dir.'transport-distance-time.js')||
    !is_file($dir.'transport-distance-time.css')||
    !is_file($dir.'transport-distance-time-panel.html'))return;
  $main=dirname(__DIR__).'/mad4b-pricing-registry.php';
  wp_enqueue_style('mad4b-transport-distance-time',
   set_url_scheme(plugins_url('assets/transport-distance-time.css',$main),'https'),
   [],(string)filemtime($dir.'transport-distance-time.css'));
  wp_enqueue_script('mad4b-transport-distance-time',
   set_url_scheme(plugins_url('assets/transport-distance-time.js',$main),'https'),
   ['mad4b-transport-self-controls'],
   (string)filemtime($dir.'transport-distance-time.js'),true);
  wp_add_inline_script('mad4b-transport-distance-time',
   'window.MAD4BTransportDistanceTime='.
   wp_json_encode(['url'=>admin_url('admin-ajax.php'),
     'nonce'=>wp_create_nonce(self::AJAX),'action'=>self::AJAX,
     'title'=>'EGP · Internal simulation · Not for checkout',
    'template'=>file_get_contents($dir.'transport-distance-time-panel.html')],
     JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';','before');
 }
 public static function boot():void {
  add_action('wp_ajax_'.self::AJAX,[__CLASS__,'ajax']);
  add_action('admin_enqueue_scripts',[__CLASS__,'assets'],105);
 }
}
MAD4B_Transport_Distance_Time_V1::boot();
