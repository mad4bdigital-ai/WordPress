<?php
/**
 * Bounded first-party Source Plan for 8 EN / 8 ES Package Tours.
 * No Tour Rates, pricing, itinerary, hotel terms, or booking-state writes.
 * The native Rate stage repeater belongs to tour-rates, not the Tour post.
 * Modes: plan | apply | verify.
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)return;
global $wpdb;
$mode=getenv('MAD4B_PACKAGE_STAY_SOURCE_MODE')?:'plan';
if(!in_array($mode,['plan','apply','verify'],true))WP_CLI::error('invalid_mode');
if(rtrim(home_url(),'/')!=='https://wayfatours.com')WP_CLI::error('wrong_site');
if(!function_exists('shared_product_accommodation_assignment'))WP_CLI::error('native_resolver_missing');
$pair=[57562=>57563,60437=>60611,60439=>60609,60441=>60607,60443=>60601,60623=>60661,60627=>60657,60629=>60655];
$levels=[2353=>['es'=>2991,'count'=>5],2354=>['es'=>2992,'count'=>6],2356=>['es'=>2994,'count'=>7]];
$cohort=[70732=>71709,36475=>71710,36316=>71711,36197=>71712,36209=>71713,
 36479=>71714,36473=>71715,36474=>71716,36477=>71717,36140=>71718,70733=>71719,
 36355=>71720,70734=>71721,70735=>71722,70736=>71723,70737=>71724,70738=>71725,70739=>71726];
$meta='_mad4b_package_accommodation_source_plan_v1';
/* Read-only, manually established nights based on the exact current original
 * published EN Itinerary + Includes and EN/ES Policies as of 2026-11-20.
 * A tuple is [stage_kind, location_key, first_night_day, consecutive_nights, board].
 * The first_night_day is 1-based, not native Rate's 0-based day_offset.
 * Do not reinterpret old Accommodation Plan A/B amounts as a sell price.
 */
$planned=[
 57562=>[['hotel','cairo',1,3,'bb'],['cruise','nile_cruise',4,3,'fb'],['hotel','cairo',7,1,'bb']],
 60437=>[['hotel','cairo',1,2,'bb'],['hotel','aswan',3,1,'bb'],['cruise','nile_cruise',4,3,'fb'],['hotel','cairo',7,1,'bb']],
 60439=>[['hotel','cairo',1,2,'bb'],['cruise','nile_cruise',3,3,'fb'],['hotel','cairo',6,1,'bb']],
 60441=>[['hotel','cairo',1,3,'bb'],['cruise','nile_cruise',4,4,'fb'],['hotel','cairo',8,2,'bb']],
 60443=>[['hotel','cairo',1,2,'bb'],['cruise','nile_cruise',3,4,'fb'],['hotel','cairo',7,1,'bb']],
 60623=>[['hotel','cairo',1,1,'bb'],['sleeper_train','train',2,1,'hb'],['cruise','nile_cruise',3,3,'fb'],['sleeper_train','train',6,1,'hb'],['hotel','cairo',7,1,'bb']],
 60627=>[['hotel','cairo',1,2,'bb'],['hotel','luxor',3,1,'bb'],['hotel','cairo',4,1,'bb']],
 60629=>[['hotel','cairo',1,3,'bb'],['cruise','nile_cruise',4,4,'fb'],['resort','sharm',8,3,'ai'],['hotel','cairo',11,1,'bb']]
];
$days=[57562=>8,60437=>8,60439=>7,60441=>10,60443=>8,60623=>8,60627=>5,60629=>12];
$counts=[
 57562=>['hotel'=>4,'cruise'=>3],
 60437=>['hotel'=>4,'cruise'=>3],
 60439=>['hotel'=>3,'cruise'=>3],
 60441=>['hotel'=>5,'cruise'=>4],
 60443=>['hotel'=>3,'cruise'=>4],
 60623=>['hotel'=>2,'cruise'=>3,'sleeper_train'=>2],
 60627=>['hotel'=>4],
 60629=>['hotel'=>4,'cruise'=>4,'resort'=>3]
];
$destinations=[
 'cairo'=>['en'=>123,'es'=>139],
 'aswan'=>['en'=>122,'es'=>138],
 'luxor'=>['en'=>125,'es'=>141],
 'sharm'=>['en'=>710,'es'=>1685],
 'nile_cruise'=>['en'=>0,'es'=>0],
 'train'=>['en'=>0,'es'=>0]
];
$sourceFields=['itinerary_item','includes','excludes','accomodation'];
$ids=array_merge(array_keys($pair),array_values($pair));
if(count($ids)!==16||count($cohort)!==18||count($planned)!==8)WP_CLI::error('scope_drift');
$option=get_option('mad4b_stay_editorial_publish_v1',[]);
if(!is_array($option)||($option['catalog_activated']??true)!==false||
 (int)(wp_count_posts('property-rates')->publish??0)!==0||
 (int)(wp_count_posts('tour-rates')->publish??0)!==0)
 WP_CLI::error('commercial_safety_guard_failed');
$company=class_exists('Commerce\\Composition\\CompanyCommercePolicy')?
  \Commerce\Composition\CompanyCommercePolicy::context():new WP_Error('company_resolver_unavailable');
$companyStatus=is_wp_error($company)?$company->get_error_code():'available';
if($companyStatus!=='policy_company_binding_invalid')WP_CLI::error('company_policy_state_changed_'.$companyStatus);
function stay_source_site_pair($en,$es){
 global $wpdb;
 foreach(['en'=>$en,'es'=>$es] as $lang=>$id){
  $p=get_post($id);
  if(!$p||$p->post_type!=='tours-and-activities'||$p->post_status!=='publish'||get_post_meta($id,'tour_mode',true)!=='tour')
   WP_CLI::error('tour_scope_'.$id);
  $r=$wpdb->get_row($wpdb->prepare("SELECT trid,language_code FROM {$wpdb->prefix}icl_translations WHERE element_type='post_tours-and-activities' AND element_id=%d",$id),ARRAY_A);
  if(!$r||$r['language_code']!==$lang)WP_CLI::error('tour_locale_'.$id);
  $trid[$lang]=(int)$r['trid'];
 }
 if($trid['en']!==$trid['es'])WP_CLI::error('tour_trid_pair_'.$en);
}
function stay_source_night_proof($en,$es,$expectedDays,$stages,$typeCounts){
 $all=[];
 foreach(['en'=>$en,'es'=>$es] as $lang=>$id){
  $raw=get_post_meta($id,'itinerary_item',true);
  if(!is_array($raw))WP_CLI::error('missing_itinerary_'.$id);
  $rows=array_values($raw);$n=count($rows);
  if($n!==$expectedDays)WP_CLI::error('itinerary_count_changed_'.$id);
  if($en===60441){
   $day5=$rows[4]['title']??'';
   if($day5!==($lang==='en'?'Day 5: Luxor West Bank':'Día 5: Luxor, Cisjordania')
     ||($rows[5]['title']??'')===$day5)WP_CLI::error('duplicate_day5_still_present_'.$id);
   $included=get_post_meta($id,'includes',true);
   if(!is_array($included)||count($included)!==13||
     ($included['item-4']??null)===($included['item-5']??null))
      WP_CLI::error('duplicate_cairo_includes_still_present_'.$id);
   $audit=get_post_meta($id,'_mad4b_package_itinerary_duplicate_remediation_v1',true);
   if(!is_array($audit)||($audit['status']??'')!=='verified_duplicate_removed'||
     ($audit['previous_itinerary_rows']??0)!==11||($audit['current_itinerary_rows']??0)!==10||
     ($audit['previous_includes_rows']??0)!==14||($audit['current_includes_rows']??0)!==13)
      WP_CLI::error('duplicate_remediation_receipt_missing_'.$id);
  }
  $all[$lang]=['actual_rows'=>$n,'virtual_days'=>count($rows),'duplicated_day_5'=>false];
 }
 $sum=[];$nightIndices=[];
 foreach($stages as $stage){
  [$kind,$place,$day,$nights,$board]=$stage;
  if(!in_array($kind,['hotel','resort','cruise','sleeper_train'],true)||$day<1||$nights<1)
   WP_CLI::error('stage_invalid_'.$en);
  if(!in_array($board,['bb','fb','hb','ai'],true))WP_CLI::error('board_invalid_'.$en);
  $sum[$kind]=($sum[$kind]??0)+$nights;
  for($night=$day;$night<$day+$nights;$night++){
   if(isset($nightIndices[$night]))WP_CLI::error('night_overlaps_'.$en.'_'.$night);
   $nightIndices[$night]=$kind;
  }
 }
 ksort($sum);ksort($typeCounts);
 if($sum!==$typeCounts||count($nightIndices)!==$expectedDays-1
   ||array_keys($nightIndices)!==range(1,$expectedDays-1))
  WP_CLI::error('nights_do_not_balance_'.$en);
 return ['itinerary'=>$all,'nights'=>$sum,'total'=>array_sum($sum)];
}
function stay_source_hashes($id,$fields){
 $v=[];
 foreach($fields as $f)$v[$f]=hash('sha256',wp_json_encode(get_post_meta($id,$f,true),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 return $v;
}
function stay_source_cairo_candidates($id,$lang,$levels,$cohort){
 $maps=[];
 foreach($levels as $enTier=>$cfg){
  $tier=$lang==='en'?$enTier:$cfg['es'];
  $a=shared_product_accommodation_assignment($id,0,$tier);
  if(is_wp_error($a))WP_CLI::error('native_candidate_error_'.$id.'_'.$tier.'_'.$a->get_error_code());
  if(($a['status']??'')!=='needs_stage_plan'||(int)($a['source_rate_id']??-1)!==0||
   ($a['unit_price_required']??true)!==false||($a['checkout_ready']??true)!==false)
   WP_CLI::error('native_component_contract_changed_'.$id.'_'.$tier);
  $ids=[];
  foreach($a['groups']??[] as $g)if(in_array((int)($g['destination_term_id']??0),[123,560],true))
   foreach($g['property_ids']??[] as $p)$ids[]=(int)$p;
  $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);
  $expected=[];
  foreach($cohort as $en=>$es){
   $propertyTier=wp_get_object_terms($en,'package_levels',['fields'=>'ids']);
   if(is_wp_error($propertyTier)||!in_array($enTier,array_map('intval',$propertyTier),true))continue;
   $expected[]=$lang==='en'?$en:$es;
  }
  sort($expected,SORT_NUMERIC);
  if(count($ids)!==$cfg['count']||$ids!==$expected)WP_CLI::error('native_cairo_candidates_not_exact_'.$id.'_'.$tier);
  $maps[(string)$tier]=$ids;
 }
 return $maps;
}
$plannedOut=[];$totalStages=0;$flagged=0;
foreach($pair as $en=>$es){
 stay_source_site_pair($en,$es);
 $proof=stay_source_night_proof($en,$es,$days[$en],$planned[$en],$counts[$en]);
 $statuses=[];
 if($en===60437)$statuses[]='aswan_hotel_not_in_approved_cairo_18_cohort';
 if($en===60627)$statuses[]='luxor_hotel_not_in_approved_cairo_18_cohort';
 if($en===60623)$statuses[]='train_and_cruise_require_nonhotel_component_provider';
 if($en===60629)$statuses=array_merge($statuses,[
  'spanish_only_accommodation_plan_a_b_preserve_separately',
  'red_sea_room_inventory_outside_approved_cairo_18',
  'sharm_itinerary_versus_hurghada_named_es_resorts_requires_supplier_review']);
 if(in_array($en,[57562,60437,60439,60441,60443,60623,60629],true))
  $statuses[]='cruise_cabin_and_vessel_not_yet_bound';
 foreach(['en'=>$en,'es'=>$es] as $lang=>$tourId){
  $source=stay_source_hashes($tourId,$sourceFields);
  $cairoChoices=stay_source_cairo_candidates($tourId,$lang,$levels,$cohort);
  $segments=[];
  foreach($planned[$en] as $i=>$entry){
   [$kind,$place,$day,$nightCount,$board]=$entry;
   $localDest=$destinations[$place][$lang]??-1;
   if($localDest<0||($localDest>0&&!(get_term($localDest,'destinations') instanceof WP_Term)))
    WP_CLI::error('destination_term_not_present_'.$tourId.'_'.$place);
   $isCairo=$kind==='hotel'&&$place==='cairo';
   $alternatives=$isCairo?$cairoChoices:[];
   $segments[]=[
    'stage_key'=>sprintf('%s-%s-%02d',$kind,$place,$i+1),
    'source_day_start'=>$day,'day_offset'=>$day-1,'nights'=>$nightCount,
    'traveler_group'=>'all','lodging_kind'=>$kind,'destination_key'=>$place,
    'destination_term_id'=>$localDest,'board'=>$board,
    'hotel_alternatives_or_by_tier'=>$alternatives,
    'candidate_provider'=>$isCairo?'shared_product_accommodation_assignment':null,
    'candidate_property_ids_confirmed'=>$isCairo,
    'component_resolution'=>$isCairo?'hotel_or_candidates_only':'requires_separate_supplier_or_component',
    'price_status'=>'pending_quote','price_amount'=>null,
    'room_unit_id'=>null,'supplier_inventory_confirmed'=>false,
    'booking_authorized'=>false,
    'native_rate_stage_export_allowed'=>false
   ];
  }
  $block=false;
  $entry=[
   'schema'=>'mad4b.package-accommodation-source-plan.v1',
   'source_type'=>'published_tour_itinerary_plus_includes_plus_accomodation_policy',
   'original_tour_id'=>$en,'localized_tour_id'=>$tourId,'language'=>$lang,
   'linked_translated_tour_id'=>$lang==='en'?$es:$en,
   'origin'=>'new_dynamic_formation_not_manual_rates',
   'old_manual_rates_read'=>false,'old_manual_rates_used'=>false,
   'tour_days'=>$days[$en],'tour_nights'=>$proof['total'],
   'night_summary'=>$proof['nights'],
   'source_itinerary_record_count'=>$proof['itinerary'][$lang]['actual_rows'],
   'virtual_itinerary_deduplicated_for_proposal_only'=>$proof['itinerary'][$lang]['duplicated_day_5'],
   'source_sha256'=>$source,
   'stages'=>$segments,'stages_count'=>count($segments),
   'candidate_levels'=>array_keys($cairoChoices),
   'source_issues'=>$statuses,
   'source_review_status'=>$block?'blocked_duplicate_itinerary':'draft_geometry_verified',
   'requires_policy_company_binding_recovery'=>true,
   'company_policy_error'=>$companyStatus,
   'new_tour_rate_ids_created'=>[],
   'native_rate_stages_written'=>false,
   'price_computed'=>false,'price_status'=>'pending_quote',
   'supplier_reservation_created'=>false,
   'catalog_activated'=>false,'checkout_ready'=>false,
  ];
  if($mode==='apply'){
   if(metadata_exists('post',$tourId,$meta))WP_CLI::error('existing_source_plan_must_not_overwrite_'.$tourId);
   $entry['recorded_at']=gmdate('c');
   if(!add_post_meta($tourId,$meta,$entry,true))WP_CLI::error('source_plan_write_failed_'.$tourId);
  }elseif($mode==='verify'){
   $saved=get_post_meta($tourId,$meta,true);
   if(!is_array($saved))WP_CLI::error('source_plan_missing_'.$tourId);
   unset($saved['recorded_at']);
   if($saved!==$entry)WP_CLI::error('source_plan_live_mismatch_'.$tourId);
  }
  $plannedOut[]=['id'=>$tourId,'lang'=>$lang,'stages'=>count($segments),
   'status'=>$entry['source_review_status'],'nights'=>$proof['nights'],'issues'=>$statuses,
   'cairo_night_groups'=>count(array_filter($segments,static fn($r)=>$r['candidate_provider']!==null))];
  $totalStages+=count($segments);
  if($block)$flagged++;
 }
}
if($mode==='plan'){
 foreach($ids as $id)if(metadata_exists('post',$id,$meta))WP_CLI::error('existing_plan_in_plan_mode_'.$id);
}
$out=['mode'=>$mode,'tours'=>count($pair),'localized_tours'=>count($ids),
 'stages_across_locales'=>$totalStages,'blocked_locale_plans'=>$flagged,
 'new_tour_rates_created'=>0,'old_tour_rates_touched'=>0,
 'company_policy_binding'=>$companyStatus,
 'catalog_activated'=>false,'checkout_ready'=>false,
 'plans'=>$plannedOut];
WP_CLI::line(wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
