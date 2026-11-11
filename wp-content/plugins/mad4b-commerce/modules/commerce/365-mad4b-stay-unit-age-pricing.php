<?php
/**
 * Plugin Name: MAD4B Accommodation Unit-Aware Age Pricing
 * Description: Source-backed per-room-night and per-cabin-night nonfinancial previews, extending the native Age Occupancy Registry.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Stay_Unit_Age_Pricing {
 const CONTRACT='mad4b.stay-unit-age-pricing.v1';
 const OPTION='mad4b_stay_unit_age_rules_v1';
 const PROFILE_OPTION='mad4b_stay_unit_age_profile_v1';
 const MARKET_OPTION='mad4b_stay_unit_age_default_market_v1';
 const STANDARD_VERSION='mad4b.stay-age-standard.v2';
 const UNITS=['per_room_night','per_cabin_night'];
 const MODES=['base_included','percent','fixed','free','on_request'];
 const SCOPES=['rate','unit','property','property_type','unit_default'];
 public static function profile(){
   $saved=get_option(self::PROFILE_OPTION,'standard');
   // Unknown profile values fail safely to manual review, never to a
   // newly invented commercial fee or a silently permitted checkout.
   return in_array($saved,['standard','custom','manual_request'],true)
      ?$saved:'manual_request';
 }
 public static function company_rules(){
   $v=get_option(self::OPTION,[]);
   return is_array($v)?$v:[];
 }
 public static function company_market(){
   $market=strtoupper((string)get_option(self::MARKET_OPTION,'GLOBAL'));
   return in_array($market,['GLOBAL','EG'],true)?$market:'GLOBAL';
 }
 public static function property_market($property){
   $property=absint($property);
   // Verified property country beats the company market default. Do not use
   // guest location, passport, nationality or store billing currency.
   if($property && get_post_type($property)==='properties'){
     $meta=strtoupper((string)get_post_meta($property,'prop_country_iso2',true));
     if(preg_match('/^[A-Z]{2}$/D',$meta))return $meta;
   }
   return self::company_market();
 }
 public static function standard_bands($market){
   // This age taxonomy is a routing/template convention, not a guarantee
   // of free accommodation or a mandated local discount.
   if($market==='EG')return [
     ['id'=>'infant','min_age'=>0,'max_age'=>1.99,'count_in_capacity'=>true],
     ['id'=>'child_u6','group'=>'child','min_age'=>2,'max_age'=>5.99,
      'count_in_capacity'=>true],
     ['id'=>'child_6_11','group'=>'child','min_age'=>6,'max_age'=>11.99,
      'count_in_capacity'=>true],
     ['id'=>'adult','min_age'=>12,'max_age'=>120,'count_in_capacity'=>true],
   ];
   return [
     ['id'=>'infant','min_age'=>0,'max_age'=>1.99,'count_in_capacity'=>true],
     ['id'=>'child','min_age'=>2,'max_age'=>17.99,'count_in_capacity'=>true],
     ['id'=>'adult','min_age'=>18,'max_age'=>120,'count_in_capacity'=>true],
   ];
 }
 public static function standard_rules($market='GLOBAL'){
   // Base-included means "room price only, minor supplement unresolved".
   // It does not assert a free child stay, bed, meal or final quote.
   $bands=$market==='EG'?['infant','child_u6','child_6_11']:['infant','child'];
   $out=[];
   foreach(self::UNITS as $unit)
     foreach($bands as $band)
       $out[]=['scope'=>'unit_default','target_id'=>0,'property_type'=>'',
         'price_unit'=>$unit,'band'=>$band,'mode'=>'base_included',
         'value'=>null,'source'=>'standard_unit_base_only_unverified',
         'standard_version'=>self::STANDARD_VERSION,'market'=>$market];
   return $out;
 }
 public static function market_template($property=0,$priceUnit=''){
   // An Egyptian hotel accommodation convention must not be blindly
   // applied to Nile-cruise cabin contracts. Cabin age terms require a
   // cruise-specific provider or company override, so use neutral global
   // age bucketing as the read-only fallback.
   return $priceUnit==='per_cabin_night'?'GLOBAL':self::property_market($property);
 }
 public static function rules($property=0,$priceUnit=''){
   $overrides=self::company_rules();
   return match(self::profile()){
     'standard'=>array_merge($overrides,self::standard_rules(
       self::market_template($property,$priceUnit))),
     'custom'=>$overrides,
     'manual_request'=>[],
   };
 }
 public static function standard_age_policy($current,$level,$context){
   if($level!=='product_type'||($context['domain']??'')!=='accommodation')
     return $current;
   $rate=absint($context['rate_id']??0);
   $unit=absint($context['unit_id']??0);
   $priceUnit=$rate?(string)get_post_meta($rate,'prop_price_unit',true):'';
   if(!$priceUnit&&$unit)$priceUnit=(string)get_post_meta($unit,'unit_price_unit',true);
   $market=self::market_template($context['product_id']??0,$priceUnit);
   // Keep the Age Registry's commercial enablement disabled unless a
   // separate owner-approved policy explicitly enables real minor pricing.
   return array_merge((array)$current,[
     'age_reference'=>'checkin','count_in_capacity'=>true,
     'bands'=>self::standard_bands($market),
   ]);
 }
 public static function validate_rules($rows){
   if(!is_array($rows)||count($rows)>100)return new WP_Error('stay_rules_count_invalid');
   $out=[];$seen=[];
   foreach($rows as $row){
     if(!is_array($row))return new WP_Error('stay_rule_malformed');
     $scope=sanitize_key((string)($row['scope']??''));
     if(!$scope)continue;
     $unit=sanitize_key((string)($row['price_unit']??''));
     $band=sanitize_key((string)($row['band']??''));
     $mode=sanitize_key((string)($row['mode']??''));
     $target=absint($row['target_id']??0);
     $kind=sanitize_key((string)($row['property_type']??''));
     if(!in_array($scope,self::SCOPES,true)||!in_array($unit,self::UNITS,true)||
        !in_array($mode,self::MODES,true)||!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$band))
       return new WP_Error('stay_rule_schema_invalid');
     if(in_array($scope,['rate','unit','property'],true)&&
        (!$target||get_post_type($target)!==(['rate'=>'property-rates','unit'=>'units','property'=>'properties'][$scope])))
       return new WP_Error('stay_rule_target_invalid');
     if($scope==='property_type' && (!$kind||!term_exists($kind,'property-types')))
       return new WP_Error('stay_rule_property_type_invalid');
     $value=null;
     if(in_array($mode,['percent','fixed'],true)){
       $raw=$row['value']??null;
       if(!is_scalar($raw)||is_bool($raw)||
          !preg_match('/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/D',(string)$raw))
         return new WP_Error('stay_rule_value_invalid');
       $value=(float)$raw;
       if($value>($mode==='percent'?200:1000000))
         return new WP_Error('stay_rule_value_exceeds_limit');
     }
     $key=implode(':',[$scope,$scope==='property_type'?$kind:
        (in_array($scope,['rate','unit','property'],true)?$target:'*'),$unit,$band]);
     if(isset($seen[$key]))return new WP_Error('stay_rule_duplicate');
     $seen[$key]=true;
     $out[]=['scope'=>$scope,'target_id'=>$target,'property_type'=>$kind,
        'price_unit'=>$unit,'band'=>$band,'mode'=>$mode,'value'=>$value];
   }
   return $out;
 }
 private static function canonical($id,$type){
   $default=(string)apply_filters('wpml_default_language','en');
   if(!has_filter('wpml_object_id')||!preg_match('/^[a-z]{2}$/D',$default))return $id;
   $mapped=absint(apply_filters('wpml_object_id',$id,$type,false,$default));
   return $mapped&&get_post_type($mapped)===$type?$mapped:$id;
 }
 public static function find_rule($rate,$unit,$property,$priceUnit,$band,$propertyTypes=[],$rules=null){
   $ids=['rate'=>[(int)$rate],'unit'=>[(int)$unit,self::canonical($unit,'units')],
     'property'=>[(int)$property,self::canonical($property,'properties')]];
   foreach(self::SCOPES as $scope){
     foreach(($rules??self::rules()) as $r){
       if(!is_array($r)||($r['scope']??'')!==$scope||
          ($r['price_unit']??'')!==$priceUnit||($r['band']??'')!==$band)continue;
       if(isset($ids[$scope])&&!in_array((int)($r['target_id']??0),$ids[$scope],true))continue;
       if($scope==='property_type'&&!in_array((string)($r['property_type']??''),$propertyTypes,true))continue;
       return $r+['rule_source'=>$scope];
     }
   }
   return null;
 }
 private static function day($v){
   if(!is_string($v)||!preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$v))return null;
   $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('UTC'));
   return $d&&$d->format('Y-m-d')===$v?$d:null;
 }
 public static function calculate(array $source,array $input,array $policy,array $rules){
   $unit=$source['price_unit']??'';
   if(!in_array($unit,self::UNITS,true))return new WP_Error('stay_price_unit_invalid');
   $amount=$source['night_price']??null;
   $currency=$source['currency']??'';
   if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0||
      !preg_match('/^[A-Z]{3}$/D',(string)$currency))
     return new WP_Error('stay_price_source_invalid');
   $checkin=self::day($input['check_in']??null);
   $checkout=self::day($input['check_out']??null);
   if(!$checkin||!$checkout)return new WP_Error('stay_date_invalid');
   $nights=(int)$checkin->diff($checkout)->format('%r%a');
   if($nights<1||$nights>60)return new WP_Error('stay_nights_invalid');
   if(!empty($source['min_nights'])&&$nights<(int)$source['min_nights'])
     return new WP_Error('stay_min_nights_not_met');
   if(!empty($source['max_nights'])&&$nights>(int)$source['max_nights'])
     return new WP_Error('stay_max_nights_exceeded');
   if(!isset($source['valid_from'],$source['valid_to']) ||
      !self::day($source['valid_from'])||!self::day($source['valid_to'])||
      $input['check_in']<$source['valid_from']||
      $checkout->modify('-1 day')->format('Y-m-d')>$source['valid_to'])
     return new WP_Error('stay_rate_date_not_covered');
   $alloc=$input['allocations']??null;
   if(!is_array($alloc)||!array_is_list($alloc)||count($alloc)<1||count($alloc)>20)
     return new WP_Error('stay_allocations_invalid');
   $capacity=(int)($source['max_occupancy']??0);
   if($capacity<1||$capacity>30)return new WP_Error('stay_unit_capacity_unverified');
   $minorCount=0;$totalAdults=0;$minors=[];
   foreach($alloc as $index=>$room){
     if(!is_array($room)||array_diff(array_keys($room),['adults','child_ages','infant_ages']))
       return new WP_Error('stay_allocation_fields_invalid');
     $adults=$room['adults']??null;
     if(!is_int($adults)||$adults<1||$adults>30)return new WP_Error('stay_room_adults_invalid');
     $totalAdults+=$adults;$capacityUsed=$adults;
     foreach(['child_ages'=>'child','infant_ages'=>'infant'] as $field=>$group){
       $ages=$room[$field]??[];
       if(!is_array($ages)||!array_is_list($ages)||count($ages)>20)
         return new WP_Error('stay_room_minor_ages_invalid');
       foreach($ages as $value){
         $age=MAD4B_Age_Occupancy_Registry::normalize_age($value);
         if(is_wp_error($age))return $age;
         $band=null;
         foreach((array)($policy['bands']??[]) as $b){
           $lo=MAD4B_Age_Occupancy_Registry::age_cents($b['min_age']??null);
           $hi=MAD4B_Age_Occupancy_Registry::age_cents($b['max_age']??null);
           if(is_wp_error($lo)||is_wp_error($hi))return new WP_Error('stay_age_band_invalid');
           $ageCents=MAD4B_Age_Occupancy_Registry::age_cents($age);
           if($ageCents>=$lo&&$ageCents<=$hi){$band=$b;break;}
         }
         if(!$band)return new WP_Error('stay_age_band_not_configured');
         $id=(string)($band['id']??'');
         $category=strtolower((string)($band['group']??$id));
         if(!str_starts_with($category,$group))
           return new WP_Error('stay_age_category_mismatch');
         $counts=$band['count_in_capacity']??($policy['count_in_capacity']??true);
         if(!is_bool($counts))return new WP_Error('stay_age_capacity_policy_invalid');
         if($counts)$capacityUsed++;
         $minors[]=['room_index'=>$index,'age'=>$age,'group'=>$group,'band'=>$id];
         $minorCount++;
       }
     }
     if($capacityUsed>$capacity)return new WP_Error('stay_unit_capacity_exceeded');
     if(!empty($source['max_adults'])&&$adults>(int)$source['max_adults'])
       return new WP_Error('stay_adult_limit_exceeded');
     if(!empty($source['max_children'])&&
        count($room['child_ages']??[])> (int)$source['max_children'])
       return new WP_Error('stay_child_limit_exceeded');
   }
   // Standard only computes a provisional unit-base preview even when
   // the Age Registry has no price grant. Custom financial supplements
   // still require explicit age-pricing enablement.
   if($minorCount && empty($policy['age_pricing_enabled']) &&
      self::profile()!=='standard')
     return new WP_Error('stay_age_pricing_not_enabled');
   $units=count($alloc);
   $base=round((float)$amount*$nights*$units,2);
   $charges=[];$minorTotal=0;$standardUnverified=false;
   foreach($minors as $m){
     $rule=self::find_rule((int)($source['rate_id']??0),
       (int)($source['unit_id']??0),(int)($source['property_id']??0),
       $unit,$m['band'],(array)($source['property_types']??[]),$rules);
     if(!$rule||($rule['mode']??'')==='on_request')
       return new WP_Error('stay_child_supplement_rule_missing');
     if($rule['mode']==='base_included')$standardUnverified=true;
     if($rule['mode']!=='base_included' && empty($policy['age_pricing_enabled']))
       return new WP_Error('stay_age_pricing_not_enabled');
     // Explicit supplement per child per night. Never multiply the base
     // room/cabin charge by the number of guests.
     $perNight=match($rule['mode']){
       'base_included'=>0.0,
       'free'=>0.0,
       'fixed'=>(float)($rule['value']??0),
       'percent'=>round((float)$amount*(float)($rule['value']??0)/100,4),
       default=>null
     };
     if($perNight===null||!is_finite($perNight)||$perNight<0)
       return new WP_Error('stay_rule_charge_invalid');
     $charge=round($perNight*$nights,2);
     $minorTotal+=$charge;
     $charges[]=$m+['mode'=>$rule['mode'],'value'=>$rule['value']??null,
       'rule_source'=>$rule['source']??$rule['scope'],
       'unit_base_coverage_only'=>$rule['mode']==='base_included',
       'minor_supplement_verified'=>$rule['mode']!=='base_included',
       'per_night'=>$perNight,'total'=>$charge];
   }
   $total=round($base+$minorTotal,2);
   $receipt=['contract'=>self::CONTRACT,'property_id'=>(int)($source['property_id']??0),
     'unit_id'=>(int)($source['unit_id']??0),'rate_id'=>(int)($source['rate_id']??0),
     'price_unit'=>$unit,'currency'=>$currency,'check_in'=>$input['check_in'],
     'check_out'=>$input['check_out'],'nights'=>$nights,'units'=>$units,
     'adults'=>$totalAdults,'minors'=>$minorCount,'capacity_each'=>$capacity,
     'base_amount'=>$base,'minor_supplements'=>round($minorTotal,2),
     'minor_lines'=>$charges,'policy_sha256'=>hash('sha256',wp_json_encode($policy)),
     'rules_sha256'=>hash('sha256',wp_json_encode($rules)),
     'profile'=>self::profile(),
     'market'=>self::property_market($source['property_id']??0),
     'age_policy_template'=>self::market_template(
       $source['property_id']??0,$unit),
     'standard_version'=>self::STANDARD_VERSION,
     'standard_base_only'=>$standardUnverified,
     'child_price_confirmed'=>!$standardUnverified];
   $receipt['sha256']=hash('sha256',wp_json_encode($receipt));
   return ['status'=>$standardUnverified?
       'nonbinding_stay_unit_base_only':'nonbinding_stay_age_preview',
     'unit_price'=>$amount,
     'price_unit'=>$unit,'currency'=>$currency,'nights'=>$nights,'unit_count'=>$units,
     'base_amount'=>$base,
     'child_supplements'=>$standardUnverified?null:round($minorTotal,2),
     'display_amount'=>$standardUnverified?$base:$total,
     'total'=>$standardUnverified?null:$total,'receipt'=>$receipt,
     'child_price_state'=>$standardUnverified?
       'property_specific_supplements_unverified':'explicit_rules_applied',
     'availability'=>'not_checked','checkout_ready'=>false,
     'pricing_components'=>['unit_nights','explicit_minor_supplements'],
     'excluded_components'=>['taxes','unpriced_extra_beds','unpriced_meals',
       'transfers','unverified_supplier_child_supplements'],
     'is_unit_base_only'=>$standardUnverified,
     'order_created'=>false,'supplier_booking_authorized'=>false];
 }
 public static function quote(array $input){
   $property=absint($input['property_id']??0);
   $unit=absint($input['unit_id']??0);
   $rate=absint($input['rate_id']??0);
   if(!$property||!$unit||!$rate||
      get_post_type($property)!=='properties'||get_post_status($property)!=='publish'||
      get_post_type($unit)!=='units'||get_post_status($unit)!=='publish'||
      get_post_type($rate)!=='property-rates'||get_post_status($rate)!=='publish')
     return new WP_Error('stay_published_source_required');
   if((int)get_post_meta($rate,'prop_related_property_id',true)!==$property||
      (int)get_post_meta($rate,'prop_related_unit_id',true)!==$unit||
      get_post_meta($unit,'unit_readiness',true)!=='validated')
     return new WP_Error('stay_unit_rate_relation_unverified');
   if(in_array(strtolower((string)get_post_meta($rate,'stop_sale',true)),['1','yes','true'],true))
     return new WP_Error('stay_rate_stop_sale');
   if(function_exists('BookingExperience\\booking_experience_stay_context')){
     $relation=BookingExperience\booking_experience_stay_context($property,$unit);
     if(is_wp_error($relation))return $relation;
   }
   $priceUnit=(string)get_post_meta($rate,'prop_price_unit',true);
   if(!in_array($priceUnit,self::UNITS,true))
     return new WP_Error('stay_rate_price_unit_unsupported');
   $types=wp_get_object_terms($property,'property-types',['fields'=>'slugs']);
   if(is_wp_error($types))return $types;
   $source=['property_id'=>$property,'unit_id'=>$unit,'rate_id'=>$rate,
     'price_unit'=>$priceUnit,'night_price'=>get_post_meta($rate,'base_amount',true),
     'currency'=>get_post_meta($rate,'currency',true),
     'valid_from'=>get_post_meta($rate,'valid_from',true),
     'valid_to'=>get_post_meta($rate,'valid_to',true),
     'max_occupancy'=>get_post_meta($unit,'unit_max_occupancy',true),
     'max_adults'=>get_post_meta($rate,'max_adults',true),
     'max_children'=>get_post_meta($rate,'max_children',true),
     'min_nights'=>get_post_meta($rate,'min_nights',true),
     'max_nights'=>get_post_meta($rate,'max_nights',true),
     'property_types'=>$types];
   $policy=MAD4B_Age_Occupancy_Registry::policy([
     'domain'=>'accommodation','product_id'=>$property,'unit_id'=>$unit,'rate_id'=>$rate]);
   if(is_wp_error($policy))return $policy;
   $result=self::calculate($source,$input,$policy['policy'],
      self::rules($property,$priceUnit));
   if(is_array($result)){
     $result['receipt']['policy_sources']=$policy['overrides'];
     $result['receipt']['rate_source']='published_property_rate';
   }
   return $result;
 }
 public static function api(){
   register_rest_route('shared-product/v1','/stay-unit-age-price-preview',[
     'methods'=>WP_REST_Server::CREATABLE,'permission_callback'=>'__return_true',
     'callback'=>static function(WP_REST_Request $req){
       $result=self::quote((array)$req->get_json_params());
       if(is_wp_error($result))return new WP_Error($result->get_error_code(),
          'Accommodation price not available for this selection',['status'=>422]);
       $response=rest_ensure_response($result);
       $response->header('Cache-Control','private, no-store, max-age=0');
       $response->header('X-LiteSpeed-Cache-Control','no-cache');
       return $response;
     },
   ]);
 }
}
add_filter('mad4b/age_occupancy/policy_level',
  ['MAD4B_Stay_Unit_Age_Pricing','standard_age_policy'],5,3);
add_action('rest_api_init',['MAD4B_Stay_Unit_Age_Pricing','api'],46);
