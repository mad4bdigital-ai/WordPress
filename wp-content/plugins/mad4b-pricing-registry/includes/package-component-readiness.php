<?php
/**
 * Read-only package readiness board over the EXISTING native stage guard.
 * It never approves supplier availability, creates Rates or saves prices.
 */
defined('ABSPATH') || exit;

final class MAD4B_Package_Component_Readiness_V1 {
 public const CONTRACT='mad4b.package-component-readiness.v1';
 private const TOURS=[60623,60627,60629,60443,60441,60439,60437,57562];
 private const TIERS=[2353=>'Silver',2354=>'Gold',2356=>'Diamond'];

 private static function window($from,$to){
  if(!is_string($from)||!is_string($to)||strlen($from)!==10||strlen($to)!==10)
   return new WP_Error('package_readiness_date_window_required');
  $one=DateTimeImmutable::createFromFormat('!Y-m-d',$from,new DateTimeZone('UTC'));
  $two=DateTimeImmutable::createFromFormat('!Y-m-d',$to,new DateTimeZone('UTC'));
  if(!$one||!$two||$one->format('Y-m-d')!==$from||$two->format('Y-m-d')!==$to||
     $two<$one||$one->diff($two)->days>730)
   return new WP_Error('package_readiness_date_window_invalid');
  return [$from,$to];
 }
 public static function report($from,$to){
  if(!class_exists('MAD4B_Package_Stage_Rate_Guard_V1'))
   return new WP_Error('package_readiness_source_guard_unavailable');
  if(!current_user_can('manage_options'))
   return new WP_Error('package_readiness_forbidden');
  $window=self::window($from,$to);
  if(is_wp_error($window))return $window;
  [$from,$to]=$window;
  $quoteIndex=class_exists('MAD4B_Supplier_Sheet_Reference_V1')?
   MAD4B_Supplier_Sheet_Reference_V1::countsByProperty('winter_2026_27'):[];
  if(is_wp_error($quoteIndex))return $quoteIndex;
  $out=['contract'=>self::CONTRACT,'site'=>home_url('/'),
   'travel_from'=>$from,'travel_to'=>$to,
   'levels'=>self::TIERS,'tour_count'=>count(self::TOURS),
   'tours'=>[],'totals'=>['stage_count'=>0,'hotel_resort_count'=>0,
     'cruise_count'=>0,'sleeper_train_count'=>0,'tour_rates_active'=>0,
     'transport_assignments'=>0,'price_ready_tier_reviews'=>0,
     'reviewed_tier_combinations'=>0,'unpriced_stages'=>0],
   'commercial_approval_granted'=>false,'checkout_authorized'=>false,
   'rate_created'=>false,'write_performed'=>false];
  foreach(self::TOURS as $tour){
   if(get_post_type($tour)!=='tours-and-activities')continue;
   $plan=get_post_meta($tour,'_mad4b_package_accommodation_source_plan_v1',true);
   $bindings=get_post_meta($tour,'_mad4b_package_stay_verified_room_program_links_v1',true);
   $stages=is_array($plan)?(array)($plan['stages']??[]):[];
   $types=['hotel_resort'=>0,'cruise'=>0,'sleeper_train'=>0];
   foreach($stages as $stage){
    $kind=(string)($stage['lodging_kind']??'');
    if(in_array($kind,['hotel','resort'],true)){$types['hotel_resort']++;$out['totals']['hotel_resort_count']++;}
    elseif($kind==='cruise'){$types['cruise']++;$out['totals']['cruise_count']++;}
    elseif($kind==='sleeper_train'){$types['sleeper_train']++;$out['totals']['sleeper_train_count']++;}
   }
   $out['totals']['stage_count']+=count($stages);
   foreach((array)($bindings['bindings']??[]) as $saved){
    $receipt=is_array($saved)?($saved['supplier_cost_receipt']??null):null;
    if(!is_array($receipt)||($receipt['status']??'')!=='approved')
     $out['totals']['unpriced_stages']++;
   }
   $rates=get_posts(['post_type'=>'tour-rates','post_status'=>['publish','draft'],
      'posts_per_page'=>-1,'fields'=>'ids','suppress_filters'=>true,
      'meta_key'=>'related_tour_id','meta_value'=>(string)$tour]);
   $assigned=0;
   foreach($rates as $rid){
    $simple=get_post_meta($rid,'related_transportations_id',true);
    $multi=get_post_meta($rid,'_mad4b_transport_stages_v1',true);
    if((is_array($simple)&&$simple)||(is_string($simple)&&trim($simple)!=='')||
       (is_array($multi)&&$multi))$assigned++;
   }
   $out['totals']['tour_rates_active']+=count($rates);
   $out['totals']['transport_assignments']+=$assigned;
   $byStage=[];
   foreach((array)($bindings['bindings']??[]) as $stageBinding)
    if(is_array($stageBinding)&&!empty($stageBinding['stage_key']))
     $byStage[(string)$stageBinding['stage_key']]=$stageBinding;
   $levels=[];
   foreach(self::TIERS as $tier=>$name){
    $review=MAD4B_Package_Stage_Rate_Guard_V1::review($tour,$tier,$from,$to);
    $blockers=(array)($review['blockers']??[]);
    $rows=[];
    foreach((array)($review['stages']??[]) as $s){
     $unitCandidateDraft=0;$unitCandidatePublished=0;$unitIdsSeen=[];
     $sourceStage=$byStage[(string)$s['key']]??[];
     foreach((array)($sourceStage['room_candidate_refs'][(string)$tier]??[]) as $ref){
      if(!is_array($ref)||!in_array((int)($ref['property_id_en']??0),
          (array)($s['eligible_property_ids']??[]),true))continue;
      foreach((array)($ref['unit_ids_en']??[]) as $rawUnit){
       $uid=absint($rawUnit);
       if(!$uid||isset($unitIdsSeen[$uid])||get_post_type($uid)!=='units')continue;
       $unitIdsSeen[$uid]=true;
       if(get_post_status($uid)==='draft'&&get_post_meta($uid,'unit_available',true)==='false')
        $unitCandidateDraft++;
       elseif(get_post_status($uid)==='publish'&&get_post_meta($uid,'unit_available',true)==='false')
        $unitCandidatePublished++;
      }
     }
     $sheetQuoteMatches=0;$sheetQuoteProperties=0;
     foreach((array)($s['eligible_property_ids']??[]) as $property){
      $qCount=(int)($quoteIndex[(int)$property]['quotation_count']??0);
      if($qCount>0){$sheetQuoteMatches+=$qCount;$sheetQuoteProperties++;}
     }
     $rows[]=['key'=>$s['key'],'kind'=>$s['kind'],'destination'=>$s['destination'],
      'sheet_winter_quote_properties'=>$sheetQuoteProperties,
      'sheet_winter_quote_values'=>$sheetQuoteMatches,
      'sheet_price_valid_for_dates'=>false,
      'unbookable_draft_unit_candidates'=>$unitCandidateDraft,
      'published_but_unavailable_unit_candidates'=>$unitCandidatePublished,
      'nights'=>(int)$s['nights'],'eligible_properties'=>count((array)($s['eligible_property_ids']??[])),
      'season_excluded_properties'=>count((array)($s['season_rejected_property_ids']??[])),
      'season_excluded_property_ids'=>array_values((array)($s['season_rejected_property_ids']??[])),
      'selected_property_id'=>(int)($s['selected_property_id']??0),
      'selected_units_count'=>count((array)($s['selected_unit_ids']??[])),
      'issues'=>array_values((array)($s['blocked']??[]))];
    }
    $ready=!empty($review['ready'])&&empty($blockers);
    $levels[$name]=['tier_id'=>$tier,'price_ready'=>$ready,
     'blocking_count'=>count($blockers),'blocking_codes'=>$blockers,
     'stages'=>$rows,'source_digest'=>$review['source_digest']??null];
    $out['totals']['reviewed_tier_combinations']++;
    if($ready)$out['totals']['price_ready_tier_reviews']++;
   }
   $nightSum=array_sum(array_map(static fn($s)=>(int)($s['nights']??0),$stages));
   $validTopology=count($stages)>0 && is_array($bindings) &&
     count($bindings['bindings']??[])===count($stages) &&
     $nightSum===(int)($plan['tour_nights']??-1) &&
     $nightSum===(int)($plan['tour_days']??0)-1;
   $out['tours'][]=[
    'tour_id'=>$tour,'title'=>html_entity_decode(wp_strip_all_tags(get_the_title($tour)),ENT_QUOTES,'UTF-8'),
    'edit_link'=>get_edit_post_link($tour,'')?:null,
    'topology_ready'=>$validTopology,'stage_count'=>count($stages),
    'types'=>$types,'saved_active_rates_count'=>count($rates),
    'transport_assigned_rates_count'=>$assigned,
    'guide_cost_verified'=>false,'final_component_cost_verified'=>false,
    'levels'=>$levels
   ];
  }
  return $out;
 }
}

