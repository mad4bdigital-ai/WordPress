<?php
/**
 * Native room Unit links and reusable Nile program Stage references.
 * No native Rate writes, new Units or artificial cruise cabins.
 * MAD4B_STAY_ROOM_PROGRAM_MODE=plan|apply|verify
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)return;
global $wpdb;
$mode=getenv('MAD4B_STAY_ROOM_PROGRAM_MODE')?:'plan';
if(!in_array($mode,['plan','apply','verify'],true))WP_CLI::error('bad_mode');
if(rtrim(home_url(),'/')!=='https://wayfatours.com')WP_CLI::error('wrong_site');
if(!class_exists('MAD4B_Cruise_Program_Context_V1') ||
 !class_exists('MAD4B_Cruise_Program_Composer_V1'))WP_CLI::error('native_cruise_program_provider_missing');
$pairs=[57562=>57563,60437=>60611,60439=>60609,60441=>60607,60443=>60601,60623=>60661,60627=>60657,60629=>60655];
$hotels=[70732=>71709,36475=>71710,36316=>71711,36197=>71712,36209=>71713,
36479=>71714,36473=>71715,36474=>71716,36477=>71717,36140=>71718,70733=>71719,
36355=>71720,70734=>71721,70735=>71722,70736=>71723,70737=>71724,70738=>71725,70739=>71726];
$tiers=[2353=>2991,2354=>2992,2356=>2994];
$meta='_mad4b_package_stay_verified_room_program_links_v1';
$source='_mad4b_package_accommodation_source_plan_v1';
$prior='_mad4b_package_stay_component_discovery_v1';
if(count($pairs)!==8||count($hotels)!==18||
 get_option('mad4b_stay_editorial_publish_v1')['catalog_activated']!==false ||
 (int)(wp_count_posts('tour-rates')->publish??0)!==0||
 (int)(wp_count_posts('property-rates')->publish??0)!==0||
 (int)(wp_count_posts('transport-rates')->publish??0)!==0)
 WP_CLI::error('commercial_safety_gate');
$allRoomIds=[];$withCapacity=0;$maps=[];
foreach($hotels as $en=>$es){
 if(get_post_type($en)!=='properties'||get_post_status($en)!=='publish'||
 get_post_type($es)!=='properties'||get_post_status($es)!=='publish'||
 get_post_meta($en,'prop_use_in_tour_rate_generation',true)!=='1'||
 get_post_meta($es,'prop_use_in_tour_rate_generation',true)!=='1')WP_CLI::error('hotel_scope_changed_'.$en);
 $wpml=$wpdb->get_row($wpdb->prepare("SELECT trid,language_code FROM {$wpdb->prefix}icl_translations WHERE element_type='post_properties' AND element_id=%d",$en),ARRAY_A);
 $wpmlEs=$wpdb->get_row($wpdb->prepare("SELECT trid,language_code FROM {$wpdb->prefix}icl_translations WHERE element_type='post_properties' AND element_id=%d",$es),ARRAY_A);
 if(!$wpml||!$wpmlEs||$wpml['language_code']!=='en'||$wpmlEs['language_code']!=='es'||
 (int)$wpml['trid']!==(int)$wpmlEs['trid'])WP_CLI::error('hotel_language_pair_drift_'.$en);
 $unitIds=$wpdb->get_col($wpdb->prepare(
  "SELECT DISTINCT u.ID FROM {$wpdb->prefix}jet_rel_default r
   JOIN {$wpdb->posts} u ON u.ID=r.child_object_id
   WHERE r.rel_id='223' AND r.parent_object_id=%d
     AND u.post_type='units' AND u.post_status='publish' ORDER BY u.ID",$en));
 $list=[];$countKnown=0;$kinds=[];
 foreach($unitIds as $raw){
  $unit=(int)$raw;
  $unitLang=$wpdb->get_var($wpdb->prepare("SELECT language_code FROM {$wpdb->prefix}icl_translations WHERE element_type='post_units' AND element_id=%d",$unit));
  if($unitLang!=='en'||!in_array(get_post_meta($unit,'unit_kind',true),['room','suite','other'],true)||
    get_post_meta($unit,'unit_readiness',true)!=='pending_review'||
    get_post_meta($unit,'unit_available',true)!=='false'||
    !is_array(get_post_meta($unit,'_mad4b_room_verified_specs_v2',true))||
    !is_array(get_post_meta($unit,'_mad4b_stay_editorial_published_v1',true)))
    WP_CLI::error('unit_source_contract_drift_'.$unit);
  if(isset($allRoomIds[$unit]))WP_CLI::error('unit_linked_to_multiple_properties_'.$unit);
  $allRoomIds[$unit]=$en;
  $occ=get_post_meta($unit,'unit_max_occupancy',true);
  $known=is_string($occ)&&preg_match('/^[1-9][0-9]*$/D',$occ);
  $countKnown+=(int)$known;$withCapacity+=(int)$known;
  $unitKind=get_post_meta($unit,'unit_kind',true);$kinds[$unitKind]=($kinds[$unitKind]??0)+1;
  $list[]=$unit;
 }
 if(!$list)WP_CLI::error('hotel_has_no_published_unit_'.$en);
 $maps[$en]=['property_id_en'=>$en,'property_id_es'=>$es,'unit_ids_en'=>$list,
  'editorial_unit_count'=>count($list),'unit_kind_counts'=>$kinds,'units_with_recorded_max_occupancy'=>$countKnown,
  'units_pending_review'=>count($list),'commercially_approved_unit_ids'=>[],
  'unit_language'=>'en','es_unit_translation_id'=>null,
  'source_relation_id'=>223,'room_assignment_mode'=>'candidate_or_not_reservation',
  'needs_supplier_capacity_inventory_and_price'=>true];
}
if(count($allRoomIds)!==134 || $withCapacity!==56)WP_CLI::error('room_count_or_capacity_fingerprint_changed');
$cruiseSegments=[2348=>['nights'=>3,'days'=>4,'from'=>'Aswan','to'=>'Luxor','es'=>2445],
 2349=>['nights'=>4,'days'=>5,'from'=>'Luxor','to'=>'Aswan','es'=>2449]];
foreach($cruiseSegments as $pid=>$cfg){
 $program=MAD4B_Cruise_Program_Context_V1::program($pid);
 $days=MAD4B_Cruise_Program_Composer_V1::day_rows($pid);
 if(!$program||$program['itinerary_status']!=='day_by_day'||
 $program['day_count']!==$cfg['days']||$program['expected_days']!==$cfg['days']||
 $days['warnings']!==[]||count($days['days'])!==$cfg['days']||
 array_column($days['days'],'day')!==range(1,$cfg['days'])||
 stripos($days['days'][0]['title'],$cfg['from'])===false||
 stripos(end($days['days'])['title'],'Departure')===false||
 (int)apply_filters('wpml_object_id',$pid,'programs',false,'es')!==$cfg['es'])
 WP_CLI::error('native_program_term_source_not_ready_'.$pid);
}
$combined=MAD4B_Cruise_Program_Context_V1::program(2350);
$variants=['luxor_roundtrip','aswan_roundtrip','luxor_to_aswan_leisure','aswan_to_luxor_leisure'];
if(!$combined||$combined['itinerary_status']!=='combined_route_variant_required'||
 ($combined['composition']['status']??'')!=='ready')WP_CLI::error('combined_program_not_composable');
foreach($variants as $v){
 $preview=MAD4B_Cruise_Program_Composer_V1::compose(2350,$v);
 if($preview['status']!=='preview_requires_supplier_confirmation'||
 (int)$preview['day_count']!==8||
 ($preview['supplier_confirmation_required']??false)!==true)
 WP_CLI::error('combined_route_variant_not_qualified_'.$v);
}
$programProof=['three_night_program_term_en'=>2348,'three_night_term_es'=>2445,
 'four_night_program_term_en'=>2349,'four_night_term_es'=>2449,
 'seven_night_program_term_en'=>2350,'seven_night_term_es'=>2453,
 'three_four_day_by_day_verified'=>true,
 'seven_night_composition_variants'=>$variants,
 'seven_night_requires_explicit_variant'=>true,
 'commercial_sailing_confirmation'=>false,'supplier_ship_cabin_or_rates_confirmed'=>false];
$recorded=0;$cruiseStages=0;$hotelStages=0;$nonCairoUnqualified=0;$programCount=[2348=>0,2349=>0];
$recap=[];$idsUsed=[];
foreach($pairs as $en=>$es){
 foreach(['en'=>$en,'es'=>$es] as $lang=>$tour){
  $p=get_post($tour);
  if(!$p||$p->post_type!=='tours-and-activities'||$p->post_status!=='publish')WP_CLI::error('tour_identity_'.$tour);
  $plan=get_post_meta($tour,$source,true);
  $admission=get_post_meta($tour,$prior,true);
  if(!is_array($plan)||!is_array($admission)||
   $plan['schema']!=='mad4b.package-accommodation-source-plan.v1'||
   $plan['source_review_status']!=='draft_geometry_verified'||
   $plan['localized_tour_id']!==$tour||
   $admission['schema']!=='mad4b.package-stay-component-source-admission.v1'||
   $admission['source_plan_sha256']!==hash('sha256',wp_json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))||
   $admission['source_stage_count']!==count($plan['stages'])||
   $admission['checkout_ready']!==false)
   WP_CLI::error('parent_stage_plan_changed_'.$tour);
  if(count($plan['stages'])!==count($admission['source_candidate_stage_reviews']))WP_CLI::error('stage_parent_mismatch_'.$tour);
  $binding=[];
  foreach($plan['stages'] as $idx=>$stage){
   $kind=$stage['lodging_kind'];$region=$stage['destination_key'];$nights=(int)$stage['nights'];
   $sref=$admission['source_candidate_stage_reviews'][$idx];
   if(($sref['stage_key']??'')!==$stage['stage_key']||
     $stage['price_status']!=='pending_quote'||
     ($stage['checkout_ready']??null)===true||
     $sref['price_amount']!==null||
     $sref['qualified_component_ids']!==[])
    WP_CLI::error('parent_stage_or_price_mismatch_'.$tour.'_'.$idx);
   $row=['stage_key'=>$stage['stage_key'],'lodging_kind'=>$kind,
    'nights'=>$nights,'source_day_start'=>(int)$stage['source_day_start'],
    'price_status'=>'pending_quote','price'=>null,
    'native_rate_stage_written'=>false,
    'bookable_unit_ids'=>[],'sailing_date'=>null,
    'selected_vessel_id'=>null,'selected_cabin_unit_id'=>null,
    'selected_property_id'=>null,'room_candidate_refs'=>null,
    'cruise_program'=>null,'commercial_readiness'=>'blocked'];
   if($kind==='hotel'&&$region==='cairo'){
    $choices=$stage['hotel_alternatives_or_by_tier'];
    $byTier=[];
    foreach($tiers as $enTier=>$esTier){
     $localTier=(string)($lang==='en'?$enTier:$esTier);
     if(!isset($choices[$localTier]))WP_CLI::error('tier_options_missing_'.$tour);
     $propertyRecords=[];
     foreach($choices[$localTier] as $localProp){
      $pid=array_search((int)$localProp,$lang==='en'?
       array_combine(array_keys($hotels),array_keys($hotels)):$hotels,true);
      if($pid===false||!isset($maps[$pid]))WP_CLI::error('property_option_missing_units_'.$tour.'_'.$localProp);
      $propertyRecords[]=$maps[$pid];
     }
     if(count($propertyRecords)!==($enTier===2353?5:($enTier===2354?6:7)))
      WP_CLI::error('stage_tier_count_changed_'.$tour);
     $byTier[$localTier]=$propertyRecords;
    }
    $row['room_candidate_refs']=$byTier;
    $row['commercial_readiness']='editorial_rooms_linked_not_saleable';
    $row['needs_es_unit_translation']=$lang==='es';
    $hotelStages++;
   }elseif($kind==='cruise'&&$region==='nile_cruise'){
    if(!in_array($nights,[3,4],true))WP_CLI::error('unsupported_cruise_nights_'.$tour);
    $termId=$nights===3?2348:2349;
    $cfg=$cruiseSegments[$termId];
    $it=get_post_meta($tour,'itinerary_item',true);
    if(!is_array($it)||count($it)!==(int)$plan['tour_days'])WP_CLI::error('day_source_changed_'.$tour);
    $rows=array_values($it);
    $s=(int)$stage['source_day_start'];
    $first=wp_strip_all_tags(html_entity_decode(($rows[$s-1]['title']??'').' '.($rows[$s-1]['description']??''),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    $last=wp_strip_all_tags(html_entity_decode(($rows[$s+$nights-1]['title']??'').' '.($rows[$s+$nights-1]['description']??''),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    $lastNight=wp_strip_all_tags(html_entity_decode(($rows[$s+$nights-2]['title']??'').' '.($rows[$s+$nights-2]['description']??''),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    $startKeys=$lang==='es'?($cfg['from']==='Aswan'?['Asuán','Aswan']:['Luxor','Lúxor']):[$cfg['from']];
    $endKeys=$lang==='es'?($cfg['to']==='Aswan'?['Asuán','Aswan']:['Luxor','Lúxor']):[$cfg['to']];
    $contains=static function($hay,$needles){foreach($needles as $needle)if(mb_stripos($hay,$needle)!==false)return true;return false;};
    if(!$contains($first,$startKeys)||(!$contains($last,$endKeys)&&!$contains($lastNight,$endKeys)))
     WP_CLI::error('cruise_itinerary_direction_needs_review_'.$tour.'_'.$termId);
    $row['cruise_program']=[
     'program_term_id_en'=>$termId,'program_term_id_local'=>$lang==='en'?$termId:$cfg['es'],
     'native_program_day_count'=>$cfg['days'],'nights'=>$nights,
     'route_from'=>$cfg['from'],'route_to'=>$cfg['to'],
     'source_first_and_last_days_direction_checked'=>$contains($last,$endKeys),
     'source_last_cruise_night_used_when_checkout_day_has_no_city'=>
        !$contains($last,$endKeys)&&$contains($lastNight,$endKeys),
     'program_days_authority'=>'native_programs_taxonomy',
     'visit_sequence_parity'=>'requires_operator_content_comparison',
     'vessel_id'=>null,'cabin_unit_id'=>null,'ship_inventory_verified'=>false,
     'departure_date'=>null,'explicit_supplier_sailing_confirmed'=>false,
     'status'=>'verified_source_program_duration_direction_only'];
    $row['commercial_readiness']='native_cruise_program_matched_sailings_and_cabins_missing';
    $cruiseStages++;$programCount[$termId]++;
   }else{
    $row['commercial_readiness']='no_validated_units_for_non_cairo_component';
    $nonCairoUnqualified++;
   }
   $binding[]=$row;
  }
  $rec=['schema'=>'mad4b.package-stay-unit-program-bindings.v1',
   'tour_id'=>$tour,'wpml_language'=>$lang,'original_en_tour_id'=>$en,
   'source_plan_sha256'=>hash('sha256',wp_json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
   'component_admission_sha256'=>hash('sha256',wp_json_encode($admission,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
   'stage_count'=>count($binding),'bindings'=>$binding,
   'editorial_room_unit_source_count'=>count($allRoomIds),
   'unit_source_lang'=>'en','translated_unit_posts_created'=>false,
   'cruise_program_proof'=>$programProof,
   'unassigned_ship_or_cruise_cabins'=>true,
   'pricing_source'=>'pending_quote','prices_generated'=>false,
   'old_manual_rate_id_used'=>false,'new_tour_rate_ids_created'=>[],
   'published_rate_ids_created'=>[],'catalog_activated'=>false,
   'checkout_ready'=>false];
  if($mode==='apply'){
   if(metadata_exists('post',$tour,$meta))WP_CLI::error('unit_program_binding_exists_'.$tour);
   $rec['recorded_at']=gmdate('c');
   if(!add_post_meta($tour,$meta,$rec,true))WP_CLI::error('unit_program_receipt_write_failed_'.$tour);
  }elseif($mode==='verify'){
   $existing=get_post_meta($tour,$meta,true);
   if(!is_array($existing))WP_CLI::error('unit_program_receipt_missing_'.$tour);
   unset($existing['recorded_at']);
   if($existing!==$rec)WP_CLI::error('unit_program_binding_content_mismatch_'.$tour);
  }else{
   if(metadata_exists('post',$tour,$meta))WP_CLI::error('receipt_already_created_'.$tour);
  }
  $idsUsed[]=$tour;$recorded++;
  $recap[]=['id'=>$tour,'stages'=>count($binding),
   'cruise_terms'=>array_values(array_filter(array_map(static fn($r)=>$r['cruise_program']['program_term_id_local']??null,$binding)))];
 }
}
if($recorded!==16||$hotelStages!==32||$cruiseStages!==14||
 $programCount!==[2348=>8,2349=>6] || $nonCairoUnqualified!==10)WP_CLI::error('stage_type_totals_drift');
WP_CLI::line(wp_json_encode(['mode'=>$mode,'site'=>home_url(),
 'tour_locale_bindings'=>$recorded,'stage_bindings'=>56,
 'cairo_hotel_stages'=>$hotelStages,'cruise_stages'=>$cruiseStages,
 'other_unqualified_stages'=>$nonCairoUnqualified,
 'native_three_night_program_refs'=>$programCount[2348],
 'native_four_night_program_refs'=>$programCount[2349],
 'editorial_room_units'=>count($allRoomIds),'rooms_with_known_max_occupancy'=>$withCapacity,
 'units_commercially_approved'=>0,'cruise_cabins_commercially_approved'=>0,
 'cruise_program_7night'=>$programProof,
 'price_writes'=>0,'tour_rate_writes'=>0,'company_policy_writes'=>0,
 'catalog_activated'=>false,'checkout_ready'=>false,'plans'=>$recap],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
