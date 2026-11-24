<?php
/**
 * Plugin Name: MAD4B Property First / Standard Base Rate V1
 * Version: 1.0.0
 * Description: Read-only Hotel/Cruise Property-first Standard Rate preview, no Unit or cabin admission required for quote.
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Property_Standard_Base_V1',false)){
final class MAD4B_Property_Standard_Base_V1 {
 const CONTRACT='mad4b.property-first-standard-base.v1';
 const OPTION='etg-dynamic-pricing-controls_property_standard_rate_mode';
 const MODE='property_first_standard';
 const FACTOR=1.0;
 const PAGE='etg-dynamic-pricing-controls';

 public static function mode():string {
  $saved=get_option(self::OPTION,'');
  return $saved==='unit_first'?'unit_first':self::MODE;
 }
 private static function valid_date(string $s):bool {
  if(!preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$s))return false;
  $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s,new DateTimeZone('Africa/Cairo'));
  return $d instanceof DateTimeImmutable && $d->format('Y-m-d')===$s;
 }
 private static function available_public(int $id):bool {
  return get_post_type($id)==='properties' && get_post_status($id)==='publish';
 }
 private static function name(int $id):string {
  return html_entity_decode(wp_strip_all_tags((string)get_the_title($id)),ENT_QUOTES|ENT_HTML5,'UTF-8');
 }
 private static function is_cruise(int $id):bool {
  if(class_exists('MAD4B_Property_Cruise_Runs_V1',false))
   return MAD4B_Property_Cruise_Runs_V1::isCruise($id);
  return has_term(['cruise','nile-cruise'],'property-types',$id);
 }
 private static function rate_selection(int $property,string $date,int $nights,int $adults,int $children) {
  $empty=['rate'=>null,'source'=>null,'error'=>null,'diagnostics'=>null];
  if(self::is_cruise($property)){
   if(!class_exists('MAD4B_Cruise_Price_Source_Fallback_V1',false))
    return array_replace($empty,['error'=>'cruise_provider_unavailable']);
   // This canonical path considers Property Rate even when Unit ID is 0.
   $result=MAD4B_Cruise_Price_Source_Fallback_V1::preview($property,$date,$nights,$adults+$children,0);
   $chosen=$result['selling_reference']??null;
   if(!is_array($chosen) || ($chosen['source']??null)!=='property_rate')
     return array_replace($empty,[
      'source'=>$chosen['source']??null,
      'diagnostics'=>$result['warnings']??[],
      'error'=>'no_eligible_standard_property_rate'
     ]);
   return ['rate'=>$chosen,'source'=>'property_rate','error'=>null,
     'diagnostics'=>$result['warnings']??[]];
  }
  if(!class_exists('MAD4B_Stay_Layered_Pricing_V1',false))
   return array_replace($empty,['error'=>'hotel_provider_unavailable']);
  // Unit 0 is deliberate. Native layered engine validates Property Rate,
  // all nights, board, occupants and explicit sell vs supplier-cost roles.
  $result=MAD4B_Stay_Layered_Pricing_V1::preview([
   'property_id'=>$property,'unit_id'=>0,'checkin'=>$date,
   'nights'=>$nights,'adults'=>$adults,'children'=>$children
  ]);
  if(is_wp_error($result))return array_replace($empty,[
   'error'=>$result->get_error_code()
  ]);
  $chosen=$result['price']??null;
  if(!is_array($chosen)||($chosen['source']??null)!=='property_rate')
   return array_replace($empty,[
    'source'=>$chosen['source']??null,
    'error'=>'no_eligible_standard_property_rate',
    'diagnostics'=>['price_status'=>$result['price_status']??null,
      'meal_base'=>$result['meal_base']??null]
   ]);
  return ['rate'=>$chosen,'source'=>'property_rate','error'=>null,
   'diagnostics'=>['meal_base'=>$result['meal_base']??null]];
 }
 public static function preview(int $property,string $date,int $nights=1,int $adults=2,int $children=0):array {
  $result=[
    'contract'=>self::CONTRACT,
    'id'=>$property,
    'name'=>get_post_type($property)==='properties'?self::name($property):null,
    'type'=>'properties','entity_kind'=>'post',
    'property_kind'=>self::is_cruise($property)?'cruise':'hotel_or_resort',
    'rate_id'=>null,'rate_name'=>null,'rate_type'=>'property-rates',
    'mode'=>self::mode(),
    'base_room_category'=>'Standard',
    'base_weight'=>self::FACTOR,
    'unit_id'=>null,'unit_required_for_preview'=>false,
    'fallback_priority'=>['explicit_valid_unit_rate_when_unit_selected',
      'property_standard_rate_without_unit','other_explicit_direct_sale_reference'],
    'date'=>$date,'nights'=>$nights,'adults'=>$adults,'children'=>$children,
    'amount'=>null,'currency'=>null,'price_unit'=>null,
    'status'=>'unpriced','source'=>null,
    'supplier_cost_approved'=>false,'date_specific_inventory_verified'=>false,
    'checkout_ready'=>false,'order_created'=>false,'read_only'=>true,
    'mutation_performed'=>false
  ];
  if(!self::available_public($property))
   return array_replace($result,['status'=>'property_not_published']);
  if(!self::valid_date($date)||$nights<1||$nights>30||
     $adults<1||$adults>50||$children<0||$children>50)
   return array_replace($result,['status'=>'invalid_date_or_occupancy']);
  if(self::mode()!=='property_first_standard')
   return array_replace($result,['status'=>'unit_first_policy_selected']);
  $check=self::rate_selection($property,$date,$nights,$adults,$children);
  if(!is_array($check['rate']??null))return array_replace($result,[
    'status'=>'standard_rate_required',
    'source'=>$check['source'],
    'reason'=>$check['error']??'no_eligible_standard_property_rate',
    'diagnostics'=>$check['diagnostics']??null
  ]);
  $r=$check['rate'];
  $rateId=(int)($r['id']??0);
  if(get_post_type($rateId)!=='property-rates'||
     get_post_status($rateId)!=='publish'||
     (int)get_post_meta($rateId,'prop_related_property_id',true)!==$property||
     (int)get_post_meta($rateId,'prop_related_unit_id',true)!==0||
     (string)get_post_meta($rateId,'mad4b_rate_scope_v1',true)!=='property'||
     (string)get_post_meta($rateId,'mad4b_rate_price_kind_v1',true)==='cost'||
     (string)get_post_meta($rateId,'stop_sale',true)!=='0'){
    return array_replace($result,['status'=>'standard_rate_verification_failed']);
  }
  $amount=$r['amount']??null;
  $currency=strtoupper((string)($r['currency']??''));
  $unit=(string)($r['price_unit']??'');
  if(!is_numeric($amount)||(float)$amount<=0||
     !preg_match('/^[A-Z]{3}$/D',$currency)||
     !in_array($unit,['per_person','per_room_night','per_cabin_night','per_person_night','per_stay'],true))
   return array_replace($result,['status'=>'standard_rate_terms_invalid']);
  return array_replace($result,[
   'rate_id'=>$rateId,'rate_name'=>self::name($rateId),
   'status'=>'standard_retail_reference_available',
   'source'=>'native_published_property_rate',
   'amount'=>(float)$amount,
   'currency'=>$currency,'price_unit'=>$unit,
   'display_base_amount'=>(string)$amount.' '.$currency.' / '.$unit,
   'meal_policy'=>$check['diagnostics']['meal_base']??null,
   'price_already_retail'=>true,
   'extra_standard_multiplier_applied'=>false,
   'rate_not_rewritten'=>true,
   'quote_for_component_only'=>true,
   // Property price is not a total Tour price, not original supplier cost.
   'supplier_cost_approved'=>false,'date_specific_inventory_verified'=>false,
   'checkout_ready'=>false
  ]);
 }

 public static function hotel_alternatives_for_revised_cruise_itinerary():array {
  $origin=home_url();
  $aswan=$origin==='https://tours.dev.mad4b.com'?71495:
    ($origin==='https://wayfatours.com'?71980:0);
  $ids=['Aswan'=>[$aswan],'Luxor'=>[36142,36293]];
  $result=[];
  foreach($ids as $destination=>$properties){
   foreach($properties as $id){
    if(!$id||!self::available_public($id))continue;
    if(!has_term('hotel','property-types',$id))continue;
    $city=wp_get_post_terms($id,'destinations');
    if(is_wp_error($city))continue;
    $expected=$destination==='Aswan'?['aswan-city','aswan-gov']:['luxor'];
    $slugs=array_map(static fn($t)=>$t->slug,$city);
    if(!array_intersect($expected,$slugs))continue;
    $result[$destination][]=[
     'id'=>$id,'name'=>self::name($id),'type'=>'properties',
     'destination'=>$destination,
     'candidate_kind'=>'hotel_alternative_revised_itinerary_only',
     'equivalent_to_three_night_cruise'=>false,
     'requires_itinerary_change'=>true,'supplier_inventory_verified'=>false
    ];
   }
  }
  return $result;
 }

 public static function tour_hotel_stages(int $tour):array {
  if(get_post_type($tour)!=='tours-and-activities')return [];
  $original=(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,
    (string)apply_filters('wpml_default_language','en'));
  if($original>0)$tour=$original;
  $plan=get_post_meta($tour,'_mad4b_package_accommodation_source_plan_v1',true);
  if(!is_array($plan)||
     !in_array((string)($plan['source_type']??''),[
      'verified_cross_site_structure_no_price_authority',
      'published_tour_itinerary_plus_includes_plus_accomodation_policy'
     ],true))return [];
  $out=[];
  foreach((array)($plan['stages']??[]) as $row){
   if(($row['lodging_kind']??null)!=='hotel'||
      empty($row['candidate_property_ids_confirmed'])||
      !isset($row['hotel_alternatives_or_by_tier'])||
      !empty($row['booking_authorized']))continue;
   $candidates=[];
   foreach((array)$row['hotel_alternatives_or_by_tier'] as $tier=>$ids) {
    foreach((array)$ids as $property) {
     $property=absint($property);
     if(!$property||!self::available_public($property)||!has_term('hotel','property-types',$property))
      continue;
     $candidates[(string)$tier][]=[
      'id'=>$property,'name'=>self::name($property),'type'=>'properties',
      'tier_id'=>(int)$tier,'rate_source'=>'property_standard_then_explicit_unit',
      'property_only_selectable'=>true
     ];
    }
   }
   if($candidates)$out[]=[
     'stage_key'=>(string)($row['stage_key']??''),
     'nights'=>(int)($row['nights']??0),
     'day_offset'=>(int)($row['day_offset']??0),
     'destination'=>(string)($row['destination_key']??''),
     'tier_properties'=>$candidates
   ];
  }
  return $out;
 }

 public static function admin_fields($fields,$page){
  if(!is_array($fields)||($page->slug??'')!==self::PAGE)return $fields;
  if(in_array('property_standard_rate_mode',array_column($fields,'name'),true))return $fields;
  $fields[]=[
   'name'=>'property_standard_rate_mode',
   'title'=>'Accommodation source priority · Property First / Standard Base Rate',
   'description'=>'Applies to hotel/cruise selling-price previews only. Standard=1.00, existing Unit Rate can override when specifically selected. Supplier cost Rate, inventory and native Booking approval remain separate.',
   'type'=>'select','object_type'=>'field','options_source'=>'manual',
   'default_val'=>self::MODE,
   'options'=>[
    ['key'=>self::MODE,'value'=>'Property First — Standard base (no Unit required for preview)'],
    ['key'=>'unit_first','value'=>'Require Unit-first source for new previews']
   ]
  ];
  return $fields;
 }
 public static function add_property_metabox():void {
  if(!current_user_can('edit_posts'))return;
  add_meta_box('mad4b-standard-base-property-v1',
   'Standard base selling Rate (Property First)',
   [self::class,'render_property_box'],
   'properties','side','default');
 }
 public static function render_property_box($post):void {
  $today=current_time('Y-m-d');
  $price=self::preview((int)$post->ID,$today,1,2,0);
  echo '<p>Category: <strong>Standard</strong> (weight 1.00). Property Rate first when no Unit chosen.</p>';
  echo '<p><strong>Preview date:</strong> '.esc_html($today).'</p>';
  echo '<p>Status: '.esc_html((string)$price['status']).'</p>';
  if($price['rate_id'])
   echo '<p>Rate: <a href="'.esc_url(admin_url('post.php?post='.$price['rate_id'].'&action=edit')).'">'.
     esc_html($price['rate_id'].' — '.$price['rate_name']).'</a></p>';
  if($price['amount']!==null)
   echo '<p><strong>'.esc_html($price['display_base_amount']).'</strong></p>';
  echo '<p>Supplier cost and live inventory are not confirmed by this reference.</p>';
  echo '<p><a href="'.esc_url(admin_url('admin.php?page=etg-dynamic-pricing-controls')).'">Dynamic Pricing Controls</a></p>';
 }
 public static function boot():void {
  add_filter('jet-engine/options-pages/raw-fields',[self::class,'admin_fields'],48,2);
  add_action('add_meta_boxes_properties',[self::class,'add_property_metabox'],44);
 }
}
add_action('init',[MAD4B_Property_Standard_Base_V1::class,'boot'],18);
}

