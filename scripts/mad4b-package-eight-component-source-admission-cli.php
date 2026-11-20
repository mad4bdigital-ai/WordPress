<?php
/**
 * Exact 8 Package Tour EN/ES component-source discovery, editorial only.
 * Dynamic raw source candidates are not qualified Tour Rate Components.
 *
 * MAD4B_PACKAGE_COMPONENT_MODE=plan|apply|verify
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)return;
global $wpdb;
$mode=getenv('MAD4B_PACKAGE_COMPONENT_MODE')?:'plan';
if(!in_array($mode,['plan','apply','verify'],true))WP_CLI::error('invalid_mode');
if(rtrim(home_url(),'/')!=='https://wayfatours.com')WP_CLI::error('site_identity_mismatch');
$pairs=[57562=>57563,60437=>60611,60439=>60609,60441=>60607,
 60443=>60601,60623=>60661,60627=>60657,60629=>60655];
$metaKey='_mad4b_package_stay_component_discovery_v1';
$sourceKey='_mad4b_package_accommodation_source_plan_v1';
if(count($pairs)!==8 || get_option('mad4b_stay_editorial_publish_v1')['catalog_activated']!==false
 ||(int)(wp_count_posts('tour-rates')->publish??0)!==0
 ||(int)(wp_count_posts('property-rates')->publish??0)!==0
 ||(int)(wp_count_posts('transport-rates')->publish??0)!==0)
 WP_CLI::error('commercial_scope_changed');
if(!class_exists('Commerce\\Composition\\CommerceTenantBindingAudit'))WP_CLI::error('policy_binding_auditor_missing');
$audit=\Commerce\Composition\CommerceTenantBindingAudit::inspect();
if(($audit['classification']??null)!=='stale_or_incomplete_site_policy' ||
   ($audit['site_profile_authority_healthy']??false)!==true ||
   ($audit['policy_resolution_available']??true)!==false ||
   ($audit['automatic_rebinding_forbidden']??false)!==true ||
   (int)($audit['company_definition_revision']??-1)!==0 ||
   (int)($audit['policy_business_revision']??-1)!==0 ||
   get_option('commerce_management_company_definitions_v1',null)!==null ||
   get_option('commerce_company_policy_registry_v1',null)!==null)
 WP_CLI::error('company_governance_state_changed');
function mad4b_stay_source_published_en(int $id,string $type):bool{
 global $wpdb;
 $p=get_post($id);
 if(!$p||$p->post_type!==$type||$p->post_status!=='publish')return false;
 $lang=$wpdb->get_var($wpdb->prepare("SELECT language_code FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type=%s",$id,'post_'.$type));
 return $lang==='en';
}
function mad4b_stay_source_candidates(int $typeTerm,int $cityTerm):array{
 global $wpdb;
 $ids=$wpdb->get_col($wpdb->prepare(
  "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
  JOIN {$wpdb->term_relationships} rtype ON rtype.object_id=p.ID
  JOIN {$wpdb->term_taxonomy} ttype ON ttype.term_taxonomy_id=rtype.term_taxonomy_id AND ttype.taxonomy='property-types' AND ttype.term_id=%d
  JOIN {$wpdb->term_relationships} rcity ON rcity.object_id=p.ID
  JOIN {$wpdb->term_taxonomy} tcity ON tcity.term_taxonomy_id=rcity.term_taxonomy_id AND tcity.taxonomy='destinations' AND tcity.term_id=%d
  WHERE p.post_type='properties' AND p.post_status='publish' ORDER BY p.ID",$typeTerm,$cityTerm));
 $result=[];
 foreach($ids as $p){
  $id=(int)$p;
  if(!mad4b_stay_source_published_en($id,'properties'))continue;
  $unitCount=(int)$wpdb->get_var($wpdb->prepare(
   "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default r
    JOIN {$wpdb->posts} u ON u.ID=r.child_object_id
    WHERE r.rel_id='223' AND r.parent_object_id=%d AND u.post_type='units' AND u.post_status='publish'",$id));
  $result[]=['id'=>$id,'units'=>$unitCount];
 }
 return $result;
}
function mad4b_stay_catalog_receipt(array $candidates,int $maxRefs=20):array{
 $ids=array_map('intval',array_column($candidates,'id'));
 sort($ids,SORT_NUMERIC);
 $withUnits=count(array_filter($candidates,static fn($row)=>(int)$row['units']>0));
 return ['published_en_properties'=>count($ids),
  'published_with_linked_units'=>$withUnits,
  'sample_property_ids'=>array_slice($ids,0,$maxRefs),
  'all_ids_sha256'=>hash('sha256',implode(',',$ids)),
  'scope'=>'editorial_source_only_not_supplier_approved',
  'qualified_property_ids'=>[],
  'inventory_check_required'=>true,
  'unit_occupancy_check_required'=>true,
  'supplier_price_required_for_booking'=>true];
}
function mad4b_stay_train(int $serviceId,int $from,int $via,int $to):array{
 global $wpdb;
 $p=get_post($serviceId);
 if(!$p||$p->post_type!=='transportations'||$p->post_status!=='publish'
 ||get_post_meta($serviceId,'trans_type',true)!=='579'
 ||(int)get_post_meta($serviceId,'trans_from_station_id',true)!==$from
 ||(int)get_post_meta($serviceId,'trans_to_station_id',true)!==$to
 ||get_post_meta($serviceId,'trans_use_in_tour_rate_generation',true)!=='0'
 ||get_post_meta($serviceId,'trans_start_price',true)!==''
 ||get_post_meta($serviceId,'trans_start_price_quote',true)!=='')
  WP_CLI::error('sleeper_source_drift_'.$serviceId);
 $stops=get_post_meta($serviceId,'trans_train_stops',true);
 if(!is_array($stops)||count($stops)<10)WP_CLI::error('sleeper_route_stops_missing_'.$serviceId);
 $rows=[];foreach($stops as $r){$station=(int)($r['station_id']??0);
   $rows[]=['station'=>$station,'order'=>(int)($r['sort_num']??-1)];}
 usort($rows,static fn($a,$b)=>$a['order']<=>$b['order']);
 $byStation=[];foreach($rows as $i=>$r)$byStation[$r['station']]=$i;
 if(!isset($byStation[$from],$byStation[$via],$byStation[$to])
 ||!($byStation[$from]<$byStation[$via] && $byStation[$via]<$byStation[$to]))
 WP_CLI::error('sleeper_direction_route_invalid_'.$serviceId);
 return ['service_id'=>$serviceId,'post_type'=>'transportations',
 'line_id'=>(int)get_post_meta($serviceId,'trans_train_line_id',true),
 'train_number'=>get_post_meta($serviceId,'trans_train_number',true),
 'from_station_id'=>$from,'via_luxor_station_id'=>$via,'to_station_id'=>$to,
 'route_stations_in_order'=>true,'sleeping_train_native_type_confirmed'=>true,
 'provider_generation_enabled'=>false,'source_rate_id'=>0,'price_amount'=>null,
 'service_inventory_confirmed'=>false,'current_departure_date_verified'=>false,
 'bookable_component_id'=>null,'admission'=>'route_reference_only',
 'required'=>'supplier_cabin_fare_departure_date_luxor_stop_and_child_policy'];
}
$regions=[
 'aswan_hotel'=>mad4b_stay_catalog_receipt(mad4b_stay_source_candidates(262,122)),
 'luxor_hotel'=>mad4b_stay_catalog_receipt(mad4b_stay_source_candidates(262,125)),
 'sharm_hotel'=>mad4b_stay_catalog_receipt(mad4b_stay_source_candidates(262,710)),
 'sharm_resort'=>mad4b_stay_catalog_receipt(mad4b_stay_source_candidates(264,710)),
 'aswan_nile_cruise'=>mad4b_stay_catalog_receipt(mad4b_stay_source_candidates(270,122)),
 'luxor_nile_cruise'=>mad4b_stay_catalog_receipt(mad4b_stay_source_candidates(270,125))
];
if($regions['aswan_hotel']['published_en_properties']!==8||
 $regions['luxor_hotel']['published_en_properties']!==11||
 $regions['sharm_hotel']['published_en_properties']!==43||
 $regions['sharm_resort']['published_en_properties']!==0||
 $regions['aswan_nile_cruise']['published_en_properties']!==221||
 $regions['luxor_nile_cruise']['published_en_properties']!==36)
 WP_CLI::error('catalog_source_cardinality_changed');
foreach($regions as $kind=>$r)if($r['published_with_linked_units']!==0)
 WP_CLI::error('units_became_available_requalify_'.$kind);
$trainOut=mad4b_stay_train(54552,54231,54257,54258);
$trainBack=mad4b_stay_train(54553,54258,54257,54231);
$trainSources=['outbound'=>$trainOut,'return'=>$trainBack];
$profileReport=['site_profile_authority_healthy'=>true,
 'company_definition_revision'=>0,'policy_business_revision'=>0,
 'classification'=>'stale_or_incomplete_site_policy',
 'missing_options'=>['commerce_management_company_definitions_v1','commerce_company_policy_registry_v1'],
 'automatic_rebinding_forbidden'=>true,
 'company_policy_bootstrap_performed'=>false,
 'company_policy_registry_fabricated'=>false,
 'commercial_operations_authorized'=>false,
 'required_approval'=>'site_bound_company_definitions_and_valid_signed_business_policy_via_authorized_management'];
$out=[];$counter=['en'=>0,'es'=>0];$stageChecks=0;$stageKinds=[];
foreach($pairs as $en=>$es){
 foreach(['en'=>$en,'es'=>$es] as $lang=>$tour){
  $p=get_post($tour);
  $wpml=$wpdb->get_row($wpdb->prepare("SELECT trid,language_code FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type='post_tours-and-activities'",$tour),ARRAY_A);
  if(!$p||$p->post_type!=='tours-and-activities'||$p->post_status!=='publish'
    ||!$wpml||$wpml['language_code']!==$lang)WP_CLI::error('tour_id_or_language_'.$tour);
  $plan=get_post_meta($tour,'_mad4b_package_accommodation_source_plan_v1',true);
  if(!is_array($plan)||
   ($plan['schema']??'')!=='mad4b.package-accommodation-source-plan.v1'||
   ($plan['source_review_status']??'')!=='draft_geometry_verified'||
   ($plan['original_tour_id']??0)!==$en ||
   ($plan['localized_tour_id']??0)!==$tour ||
   ($plan['old_manual_rates_used']??true)!==false ||
   ($plan['checkout_ready']??true)!==false ||
   ($plan['native_rate_stages_written']??true)!==false)
   WP_CLI::error('source_plan_not_approved_'.$tour);
  $stageReview=[];$counts=[];
  foreach($plan['stages'] as $stage){
   if(!is_array($stage)||($stage['price_status']??'')!=='pending_quote'||
    ($stage['booking_authorized']??true)!==false||
    ($stage['native_rate_stage_export_allowed']??true)!==false)
     WP_CLI::error('stage_price_or_booking_guard_'.$tour);
   $kind=$stage['lodging_kind'];$loc=$stage['destination_key'];$key=$stage['stage_key'];
   $counts[$kind]=($counts[$kind]??0)+1;
   $ref=['stage_key'=>$key,'lodging_kind'=>$kind,'destination_key'=>$loc,
     'source_day_start'=>$stage['source_day_start'],'nights'=>$stage['nights'],
     'price_status'=>'pending_quote','price_amount'=>null,'rate_price_inherited'=>false,
     'checkout_ready'=>false,'supplier_booking_authorized'=>false,
     'component_qualification'=>'blocked_pending_supplier_and_company_policy',
     'qualified_component_ids'=>[],'source_catalog'=>null,'route_reference'=>null,
     'localized_bookable_component_id'=>null,'requires_supplier_contract'=>true];
   if($kind==='hotel'&&$loc==='cairo'){
     $choices=$stage['hotel_alternatives_or_by_tier'];
     if(!is_array($choices)||count($choices)!==3||
       count($choices[(string)($lang==='en'?2353:2991)]??[])!==5||
       count($choices[(string)($lang==='en'?2354:2992)]??[])!==6||
       count($choices[(string)($lang==='en'?2356:2994)]??[])!==7)
        WP_CLI::error('cairo_native_candidate_drift_'.$tour.'_'.$key);
     $ref['component_qualification']='native_hotel_discovered_unpriced';
     $ref['candidate_or_tiers']=$choices;
     $ref['needs_unit_occupancy_and_commercial_readiness']=true;
   }elseif($kind==='hotel'&&$loc==='aswan'){
     $ref['source_catalog']=$regions['aswan_hotel'];
     $ref['component_qualification']='published_aswan_hotels_no_linked_units';
   }elseif($kind==='hotel'&&$loc==='luxor'){
     $ref['source_catalog']=$regions['luxor_hotel'];
     $ref['component_qualification']='published_luxor_hotels_no_linked_units';
   }elseif($kind==='resort'&&$loc==='sharm'){
     $ref['source_catalog']=['qualified_resort_type'=>$regions['sharm_resort'],
       'hotel_records_for_review'=>$regions['sharm_hotel']];
     $ref['component_qualification']='no_sharm_resort_units_or_resort_taxonomy_admitted';
   }elseif($kind==='cruise'&&$loc==='nile_cruise'){
     $ref['source_catalog']=['aswan'=>$regions['aswan_nile_cruise'],'luxor'=>$regions['luxor_nile_cruise']];
     $ref['component_qualification']='published_ships_no_validated_sailing_cabin_units';
     $ref['required_program']=['night_count'=>(int)$stage['nights'],
       'direction'=>'not_verified','ship'=>'not_verified','cabin_type'=>'not_verified'];
   }elseif($kind==='sleeper_train'&&$loc==='train'&&$en===60623){
     $offset=(int)$stage['source_day_start'];
     if($offset!==2&&$offset!==6)WP_CLI::error('unexpected_sleeper_day_'.$tour);
     $ref['route_reference']=$offset===2?$trainSources['outbound']:$trainSources['return'];
     $ref['component_qualification']='train_sleeping_route_reference_only';
     $ref['transport_service_language']='en';
     $ref['language_mismatch_for_es']=$lang==='es';
   }else WP_CLI::error('unsupported_stage_kind_'.$tour.'_'.$key);
   $stageReview[]=$ref;
   $stageChecks++;
   $stageKinds[$kind]=($stageKinds[$kind]??0)+1;
  }
  if(count($stageReview)!==count($plan['stages']))WP_CLI::error('stage_count_drift_'.$tour);
  $receipt=['schema'=>'mad4b.package-stay-component-source-admission.v1',
    'tour_id'=>$tour,'source_en_tour_id'=>$en,'wpml_language'=>$lang,
    'source_plan_sha256'=>hash('sha256',wp_json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
    'source_stage_count'=>count($plan['stages']),
    'old_manual_tour_rates_used'=>false,'old_property_or_transport_rates_used'=>false,
    'native_tour_rate_stages_written'=>false,
    'default_currency'=>'unpriced','rate_amount'=>null,
    'supplier_source_precedence'=>'contract_then_self_preview_fallback',
    'source_candidate_stage_reviews'=>$stageReview,
    'company_policy_binding_audit'=>$profileReport,
    'qualification_scope'=>'review_and_preview_only',
    'all_non_cairo_stages_unqualified'=>true,
    'checkout_ready'=>false,'catalog_activated'=>false,
    'new_tour_rate_ids_created'=>[]];
  if($mode==='apply'){
    if(metadata_exists('post',$tour,$metaKey))WP_CLI::error('candidate_review_preexists_'.$tour);
    $receipt['recorded_at']=gmdate('c');
    if(!add_post_meta($tour,$metaKey,$receipt,true))WP_CLI::error('recording_failed_'.$tour);
  }elseif($mode==='verify'){
    $saved=get_post_meta($tour,$metaKey,true);
    if(!is_array($saved))WP_CLI::error('admission_review_missing_'.$tour);
    unset($saved['recorded_at']);
    if($saved!==$receipt)WP_CLI::error('candidate_review_drift_'.$tour);
  }else{
    if(metadata_exists('post',$tour,$metaKey))WP_CLI::error('plan_already_recorded_'.$tour);
  }
  $counter[$lang]++;
  $out[]=['id'=>$tour,'lang'=>$lang,'stages'=>count($stageReview),
    'train_source_refs'=>array_values(array_filter(array_map(
      static fn($x)=>$x['route_reference']['service_id']??null,$stageReview))),
    'stages_with_catalog_source'=>count(array_filter($stageReview,static fn($x)=>$x['source_catalog']!==null)),
    'sell_ready_components'=>0];
 }
}
if($counter!==['en'=>8,'es'=>8]||$stageChecks!==56)WP_CLI::error('unexpected_coverage');
WP_CLI::line(wp_json_encode(['mode'=>$mode,'site'=>home_url(),'tour_pairs'=>8,
 'localized_source_reviews'=>16,'localized_stage_reviews'=>56,
 'stage_kinds'=>$stageKinds,'regional_catalog'=>$regions,
 'sleeper_train_sources'=>$trainSources,
 'policy_binding'=>$profileReport,
 'created_tour_rates'=>0,'published_tour_rates'=>0,
 'pricing_or_inventory_writes'=>0,'catalog_activated'=>false,
 'reviews'=>$out],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
