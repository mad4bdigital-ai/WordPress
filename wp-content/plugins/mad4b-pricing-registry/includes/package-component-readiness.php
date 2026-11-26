<?php
/**
 * Read-only package readiness board over the EXISTING native stage guard.
 * It never approves supplier availability, creates Rates or saves prices.
 */
defined('ABSPATH') || exit;

final class MAD4B_Package_Component_Readiness_V1 {
 public const CONTRACT='mad4b.package-component-readiness.v1';
 /** Discover local package IDs and canonical native tier terms. No tenant ID or URL is used. */
 public static function catalog():array {
  if(!current_user_can('manage_options')||!class_exists('MAD4B_Package_Stage_Rate_Guard_V1'))return ['tours'=>[],'tiers'=>[]];
  $guard=MAD4B_Package_Stage_Rate_Guard_V1::class;
  $tours=[];
  foreach(get_posts(['post_type'=>'tours-and-activities','post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1,'suppress_filters'=>true,'meta_key'=>$guard::SOURCE,'orderby'=>'ID','order'=>'ASC']) as $raw){
   $id=$guard::canonicalTour((int)$raw);
   if($id>0&&get_post_status($id)==='publish'&&current_user_can('edit_post',$id)&&$guard::applies($id))$tours[$id]=$id;
  }
  $tiers=[];$terms=get_terms(['taxonomy'=>'package_levels','hide_empty'=>false]);
  if(!is_wp_error($terms))foreach((array)$terms as $term){
   if(!$term instanceof WP_Term)continue;
   $id=$guard::canonicalTier((int)$term->term_id);if($id<1||isset($tiers[$id]))continue;
   $canonical=get_term($id,'package_levels');if(!$canonical instanceof WP_Term)continue;
   $name=trim((string)$canonical->name);if($name==='')continue;
   $tiers[$id]=$name;
  }
  // Keep distinct native tier identities even if labels happen to collide.
  $counts=array_count_values($tiers);foreach($tiers as $id=>$name)if($counts[$name]>1)$tiers[$id]=$name.' (#'.$id.')';
  ksort($tours,SORT_NUMERIC);ksort($tiers,SORT_NUMERIC);
  return ['tours'=>array_values($tours),'tiers'=>$tiers];
 }

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

 /** Diagnostic only: locate published catalogue Properties with correct
  * geography/type but without approved level or exact city. Do not assign. */
 private static function unassignedCandidates(int $tour,int $tier,array $sourceStage,
   array $quotePolicies):array {
  $scope=MAD4B_Package_Stage_Rate_Guard_V1::stageScope($tour,$sourceStage);
  if(($scope['status']??'')!=='mapped'||empty($scope['accepted_term_ids']))
   return ['status'=>'stage_destination_unmapped','candidates'=>[],'total'=>0];
  $all=get_posts(['post_type'=>'properties','post_status'=>'publish',
   'fields'=>'ids','posts_per_page'=>150,'suppress_filters'=>true,
   'orderby'=>'title','order'=>'ASC','no_found_rows'=>true,
   'tax_query'=>['relation'=>'AND',
      ['taxonomy'=>'destinations','field'=>'term_id','terms'=>$scope['accepted_term_ids']],
      ['taxonomy'=>'property-types','field'=>'slug','terms'=>['hotel','resort']]]]);
  $default=apply_filters('wpml_default_language',null);
  $found=[];
  foreach($all as $pid){
   $pid=(int)$pid;
   $language=apply_filters('wpml_element_language_code',null,
      ['element_id'=>$pid,'element_type'=>'post_properties']);
   if(is_string($default)&&is_string($language)&&$language!==$default)continue;
   $status=MAD4B_Package_Stage_Rate_Guard_V1::propertyEligibility($pid,$tier,$scope,
      (string)($sourceStage['lodging_kind']??''));
   if(!in_array((string)($status['reason']??''),
        ['property_tier_mismatch','aswan_city_location_unverified'],true))continue;
   $quoted=false;
   foreach($quotePolicies as $p)
    if(array_key_exists($pid,(array)($p['index']??[])))$quoted=true;
   $found[]=['id'=>$pid,'name'=>wp_strip_all_tags(get_the_title($pid)),
    'reason'=>$status['reason'],'supplier_quote_reference'=>$quoted,
    'qualified'=>false,'assigned'=>false];
  }
  usort($found,static fn($a,$b)=>
   ((int)$b['supplier_quote_reference']<=>(int)$a['supplier_quote_reference'])?:
   strcasecmp($a['name'],$b['name']));
  return ['status'=>'source_catalogue_review_only',
   'total'=>count($found),'candidates'=>array_slice($found,0,8),
   'not_authorized_to_assign'=>true,'checkout_authorized'=>false];
 }
 /**
  * Read-only internal What-if scenario built over EXISTING Stage Guard and
  * draft Property-wide supplier quote evidence. Not a customer Quote,
  * Cost Receipt, Price Resolver, or checkout source.
  */
 public static function scenario(array $input){
  // The source rates store original English board/type term IDs.
  // WPML changes get_term() under a translated admin locale. Read the
  // original supplier quote identity in source language and restore UI.
  $language=apply_filters('wpml_current_language',null);
  $default=apply_filters('wpml_default_language',null);
  if(is_string($language)&&$language!==''&&is_string($default)&&
     $default!==''&&$language!==$default){
    do_action('wpml_switch_language',$default);
    try{return self::scenarioInSourceLocale($input);}
    finally{do_action('wpml_switch_language',$language);}
  }
  return self::scenarioInSourceLocale($input);
 }
 private static function scenarioInSourceLocale(array $input){
  if(!current_user_can('manage_options'))
   return new WP_Error('package_scenario_access_denied');
  if(array_diff(array_keys($input),
    ['tour_id','tier_id','stage_key','property_id','travel_date',
     'adults','children','assume_per_person_per_night']))
   return new WP_Error('package_scenario_unknown_input');
  $tour=filter_var($input['tour_id']??null,FILTER_VALIDATE_INT);
  $tier=filter_var($input['tier_id']??null,FILTER_VALIDATE_INT);
  $property=filter_var($input['property_id']??null,FILTER_VALIDATE_INT);
  $adults=filter_var($input['adults']??null,FILTER_VALIDATE_INT);
  $children=filter_var($input['children']??0,FILTER_VALIDATE_INT);
  $assume=$input['assume_per_person_per_night']??false;
  if(!in_array($assume,[true,false,'1','0',1,0],true))
   return new WP_Error('package_scenario_assumption_invalid');
  $assume=filter_var($assume,FILTER_VALIDATE_BOOLEAN);
  $date=$input['travel_date']??null;
  $key=$input['stage_key']??null;
  $catalog=self::catalog();
  if(!in_array($tour,$catalog['tours'],true)||!isset($catalog['tiers'][(int)$tier])||
     !$property||$property<1||!in_array($adults,[2,3],true)||
     $children!==0 || !is_string($date)||!is_string($key)||
     !preg_match('/^[a-z0-9][a-z0-9_-]{2,75}$/D',$key))
   return new WP_Error('package_scenario_selection_invalid');
  $period=self::window($date,$date);
  if(is_wp_error($period))return $period;
  if(!class_exists('MAD4B_Stay_Layered_Pricing_V1',false))
   return new WP_Error('package_scenario_rate_provider_unavailable');
  $review=MAD4B_Package_Stage_Rate_Guard_V1::review(
    (int)$tour,(int)$tier,$date,$date);
  if(is_wp_error($review))return $review;
  $stage=null;
  foreach((array)($review['stages']??[]) as $s)
   if(($s['key']??'')===$key){$stage=$s;break;}
  if(!is_array($stage)||!in_array($stage['kind']??null,['hotel','resort'],true))
   return new WP_Error('package_scenario_hotel_stage_missing');
  if(!in_array((int)$property,array_map('intval',
      (array)($stage['eligible_property_ids']??[])),true))
   return new WP_Error('package_scenario_property_not_eligible');
  $geometryBlocked=array_intersect((array)($stage['blocked']??[]),
    ['stage_day_night_sequence_invalid','stage_geometry_binding_mismatch',
     'stage_itinerary_destination_conflict','stage_destination_unmapped']);
  if($geometryBlocked)
   return new WP_Error('package_scenario_stage_geometry_unresolved');
  $nights=(int)($stage['nights']??0);
  $offset=(int)($stage['day_start']??0)-1;
  if($nights<1||$nights>45||$offset<0||$offset>45)
   return new WP_Error('package_scenario_nights_invalid');
  $departure=DateTimeImmutable::createFromFormat('!Y-m-d',$date,
    new DateTimeZone('UTC'));
  $checkin=$departure->modify('+'.$offset.' days');
  $checkout=$checkin->modify('+'.$nights.' days');
  $lastNight=$checkout->modify('-1 day');
  $first=$checkin->format('Y-m-d');
  $last=$lastNight->format('Y-m-d');
  $rates=get_posts(['post_type'=>'property-rates','post_status'=>'draft',
    'fields'=>'ids','posts_per_page'=>100,'suppress_filters'=>true,
    'meta_query'=>['relation'=>'AND',
      ['key'=>'prop_related_property_id','value'=>(string)$property,'compare'=>'='],
      ['key'=>'_mad4b_supplier_sheet_import_key_v1','compare'=>'EXISTS']]]);
  $matches=[];$discards=[];
  foreach($rates as $id){
   $id=(int)$id;
   $scope=(string)get_post_meta($id,'mad4b_rate_scope_v1',true);
   $unit=(int)get_post_meta($id,'prop_related_unit_id',true);
   $occupancy=(string)get_post_meta($id,
     '_mad4b_supplier_sheet_occupancy_v1',true);
   $board=(int)get_post_meta($id,'prop_board_type',true);
   $term=$board?get_term($board,'board-type'):null;
   $source=get_post_meta($id,'_mad4b_supplier_sheet_proposal_v1',true);
   $window=MAD4B_Stay_Layered_Pricing_V1::effectiveRateWindow($id);
   if(!is_array($source)||$scope!=='property'||$unit!==0||
      (string)get_post_meta($id,'mad4b_rate_price_kind_v1',true)!=='cost'||
      (string)get_post_meta($id,'stop_sale',true)!=='1'||
      (string)get_post_meta($id,'_mad4b_supplier_sheet_quote_unapproved_v1',true)!=='1'||
      !is_array($window)||empty($window['valid'])||
      $window['start']>$first||$window['end']<$last||
      !($term instanceof WP_Term)||$term->slug!=='bed-breakfast'||
      (string)get_post_meta($id,'currency',true)!=='USD'||
      (string)get_post_meta($id,'prop_price_unit',true)!=='per_person'||
      (float)get_post_meta($id,'base_amount',true)<=0||
      (string)$source['source_cell']==='')
      continue;
   if($occupancy!==($adults===2?'double':'triple'))continue;
   if(!empty($source['supplier_contract_verified'])||
      !empty($source['nightly_basis_verified'])||
      !empty($source['cost_receipt_approved'])||
      !empty($source['commercial_activation_allowed']))
    return new WP_Error('package_scenario_source_status_inconsistent');
   $matches[]=['rate_id'=>$id,'source_cell'=>(string)$source['source_cell'],
     'source_amount'=>(float)get_post_meta($id,'base_amount',true),
     'currency'=>'USD','meal_plan'=>'BB','occupancy'=>$occupancy,
     'price_basis_recorded'=>'per_person',
     'source_period'=>(string)($source['source_season']??''),
     'source_meal_plan'=>(string)($source['meal_plan']??''),
     'per_night_basis_verified'=>false,
     'calendar_source'=>$window['source'],
     'calendar_from'=>$window['start'],
     'calendar_to'=>$window['end'],
     'status'=>'draft_stop_sale_reference'];
  }
  if(count($matches)>1)
   return new WP_Error('package_scenario_multiple_rate_candidates');
  $selected=$matches[0]??null;
  $flags=[];
  if(!$selected)$flags[]='no_matching_source_rate_for_stay_and_occupancy';
  if((int)($stage['selected_property_id']??0)!==(int)$property)
   $flags[]='hotel_candidate_not_selected_in_package';
  $flags[]='supplier_contract_not_verified';
  $flags[]='per_person_per_night_not_confirmed';
  $flags[]='unit_inventory_and_allotment_not_verified';
  $flags[]='mandatory_and_optional_extras_not_approved';
  $season='none';
  $noteCells=[];
  if($selected){
   $season=(string)get_post_meta((int)$selected['rate_id'],
     '_mad4b_supplier_sheet_season_slug_v1',true);
   $season=$season==='summer'?'summer':'winter_2026_27';
   if(!class_exists('MAD4B_Supplier_Sheet_Reference_V1',false))
    return new WP_Error('package_scenario_source_ledger_missing');
   $baseCatalog=MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels',$season);
   if(is_wp_error($baseCatalog))return $baseCatalog;
   // A saved editorial Draft might have been manually edited. Reject
   // stale or tampered amounts rather than claiming a sheet price.
   $sourceMatches=0;
   foreach((array)($baseCatalog['rows']??[]) as $sourceRow){
    if((int)($sourceRow['property_id']??0)!==(int)$property)continue;
    foreach((array)($sourceRow['tariffs']??[]) as $tariff){
     if(($tariff['cell_ref']??'')===$selected['source_cell']&&
        ($tariff['occupancy']??'')===$selected['occupancy']&&
        ($tariff['meal']??'')==='BB'&&
        ($tariff['currency']??'')==='USD'&&
        ($tariff['basis']??'')==='per_person'&&
        (float)($tariff['amount']??0)===$selected['source_amount'])
      $sourceMatches++;
    }
   }
   if($sourceMatches!==1)
    return new WP_Error('package_scenario_saved_quote_source_drift');
   foreach([$baseCatalog,'peak'] as $periodItem){
    $periodName=is_string($periodItem)?$periodItem:$season;
    $catalog=is_string($periodItem)?
     MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels',$periodItem):
     $periodItem;
    if(is_wp_error($catalog))return $catalog;
    foreach((array)($catalog['rows']??[]) as $sourceRow){
     if((int)($sourceRow['property_id']??0)!==(int)$property)continue;
     foreach((array)($sourceRow['source_note_cells']??[]) as $note){
      $reviewFlags=(array)($note['review_flags']??[]);
      if($periodName==='peak'||$reviewFlags){
       $noteCells[]=['source_cell'=>(string)($note['cell']??''),
        'period'=>$periodName,'review_flags'=>$reviewFlags,
        'date_review_blockers'=>(array)($note['date_evidence']['review_blockers']??[])];
      }
     }
    }
   }
  }
  if($noteCells)$flags[]='supplier_season_or_peak_notes_need_review';
  $flags=array_values(array_unique($flags));
  // This is an opt-in arithmetic WHAT-IF only. It must never be passed to
  // official Rate generator, supplier Cost Receipt or customer checkout.
  $raw=$selected?(float)$selected['source_amount']:null;
  $hypothetical=$assume&&$selected?
   round($raw*$adults*$nights,2):null;
  return ['contract'=>'mad4b.package-stay-reference-scenario.v1',
   'source'=>'existing_native_package_stage_guard_and_property_rate',
   'tour_id'=>(int)$tour,'tier_id'=>(int)$tier,'stage_key'=>$key,
   'property_id'=>(int)$property,'property_title'=>wp_strip_all_tags(get_the_title($property)),
   'travel_date'=>$date,'first_overnight'=>$first,'last_overnight'=>$last,
   'checkout_date'=>$checkout->format('Y-m-d'),'nights'=>$nights,
   'adults'=>$adults,'children'=>0,'season'=>$season,
   'source_rate'=>$selected,'supplier_note_evidence'=>$noteCells,
   'hypothetical_per_person_per_night_assumption'=>$assume,
   'hypothetical_total_usd'=>$hypothetical,
   'hypothetical_formula'=>$hypothetical!==null?
       'unverified_per_person_source_amount * adults * nights':null,
   'completion_status'=>$selected?'reference_only_not_commercially_approved':'no_matching_rate',
   'missing_commercial_evidence'=>$flags,
   'customer_quote_authorized'=>false,'supplier_cost_receipt_approved'=>false,
   'property_rate_published'=>false,'inventory_confirmed'=>false,
   'checkout_authorized'=>false,'write_performed'=>false];
 }
 /** Native property-wide supplier quotation approval-gap queue, READ ONLY. */
 public static function supplierEvidenceQueue(){
  if(!current_user_can('manage_options'))
   return new WP_Error('supplier_evidence_queue_forbidden');
  $ids=get_posts(['post_type'=>'property-rates',
    'post_status'=>['draft','publish','private','pending'],
    'fields'=>'ids','posts_per_page'=>150,'suppress_filters'=>true,
    'meta_query'=>[['key'=>'_mad4b_supplier_sheet_import_key_v1','compare'=>'EXISTS']]]);
  if(count($ids)>=150)return new WP_Error('supplier_evidence_queue_limit');
  $groups=[];
  foreach($ids as $id){
   $id=(int)$id;$pid=(int)get_post_meta($id,'prop_related_property_id',true);
   $e=get_post_meta($id,'_mad4b_supplier_sheet_proposal_v1',true);
   if($pid<1||!is_array($e)||
      get_post_status($id)!=='draft'||
      (string)get_post_meta($id,'stop_sale',true)!=='1')
    return new WP_Error('supplier_evidence_queue_source_drift');
   if(!isset($groups[$pid]))$groups[$pid]=[
     'property_id'=>$pid,'property'=>html_entity_decode(
       wp_strip_all_tags(get_the_title($pid)),ENT_QUOTES,'UTF-8'),
     'rates'=>[],'note_cells'=>[],
     'missing'=>['supplier_contract','price_per_night_basis',
       'dated_unit_inventory','peak_gala_meals',
       'verified_net_cost_receipt']];
   $w=MAD4B_Stay_Layered_Pricing_V1::effectiveRateWindow($id);
   $groups[$pid]['rates'][]=[
     'id'=>$id,'cell'=>(string)($e['source_cell']??''),
     'occupancy'=>(string)get_post_meta($id,'_mad4b_supplier_sheet_occupancy_v1',true),
     'season'=>(string)get_post_meta($id,'_mad4b_supplier_sheet_season_slug_v1',true),
     'amount'=>(string)get_post_meta($id,'base_amount',true),
     'calendar'=>!empty($w['valid'])?$w['start'].' / '.$w['end']:'not verified',
     'edit_url'=>get_edit_post_link($id,'')?:null,
     'status'=>'draft_stop_sale_no_cost_authority'];
  }
  if($groups){
   foreach(['summer','winter_2026_27','peak'] as $season){
    $c=MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels',$season);
    if(is_wp_error($c))return $c;
    foreach($c['rows'] as $item){
     $pid=(int)($item['property_id']??0);
     if(!isset($groups[$pid]))continue;
     foreach((array)($item['source_note_cells']??[]) as $note)
      if($season==='peak'||!empty($note['review_flags']))
       $groups[$pid]['note_cells'][]=[
         'cell'=>(string)($note['cell']??''),'season'=>$season,
         'categories'=>(array)($note['review_flags']??[])];
    }
   }
  }
  ksort($groups,SORT_NUMERIC);
  return ['contract'=>'mad4b.supplier-evidence-gaps.v1',
   'site'=>home_url(),'rates'=>count($ids),'properties'=>count($groups),
   'approved_cost_receipts'=>0,'rows'=>array_values($groups),
   'commercial_approval_granted'=>false,'checkout_authorized'=>false,
   'write_performed'=>false];
 }
 /**
  * Context only, NEVER included in Guard eligibility or source Rate pricing.
  * The original Aswan-city hotel source drafts have independent WP IDs per
  * company and do not satisfy publication / stock / supplier approval gates.
  */
 private static function pendingAswanCityEditorialDrafts(int $tour,int $tier,string $stageKey):array {
  $original=apply_filters('wpml_current_language',null);
  $default=apply_filters('wpml_default_language',null);
  if(is_string($original)&&$original!==''&&is_string($default)&&
     $default!==''&&$original!==$default){
    do_action('wpml_switch_language',$default);
    try{return self::pendingAswanCityDraftsInSourceLocale($tour,$tier,$stageKey);}
    finally{do_action('wpml_switch_language',$original);}
  }
  return self::pendingAswanCityDraftsInSourceLocale($tour,$tier,$stageKey);
 }
 private static function pendingAswanCityDraftsInSourceLocale(int $tour,int $tier,string $stageKey):array {
  if($tour!==60437||$stageKey!=='hotel-aswan-02'||
     !in_array($tier,[2354,2356],true))return [];
  $originIds=[71495,71501];
  $site=parse_url(home_url('/'),PHP_URL_HOST);
  if(!in_array($site,['tours.dev.mad4b.com','wayfatours.com'],true))return [];
  $items=[];
  foreach($originIds as $sourceId){
   if($site==='tours.dev.mad4b.com'){
    $target=(int)$sourceId;
   }else{
    $ids=get_posts(['post_type'=>'properties','post_status'=>['draft','publish'],
      'fields'=>'ids','posts_per_page'=>3,'suppress_filters'=>true,
      'meta_query'=>[['key'=>'_mad4b_company_source_aswan_property_id_v1',
                      'value'=>(string)$sourceId,'compare'=>'=']]]);
    if(count($ids)!==1)continue;
    $target=(int)$ids[0];
   }
   if(get_post_type($target)!=='properties')continue;
   $source=get_post_meta($target,'_mad4b_property_official_site_source_v1',true);
   if(!is_array($source)||empty($source['overview_url']))continue;
   $levels=wp_get_object_terms($target,'package_levels',['fields'=>'slugs']);
   if(is_wp_error($levels))continue;
   $wanted=$tier===2354?'gold-package':'diamond-package';
   if(!in_array($wanted,(array)$levels,true))continue;
   $types=wp_get_object_terms($target,'property-types',['fields'=>'slugs']);
   $location=wp_get_object_terms($target,'destinations',['fields'=>'slugs']);
   if(is_wp_error($types)||is_wp_error($location))continue;
   $status=get_post_status($target);
   $reasons=[];
   if($status!=='publish')$reasons[]='draft_not_published';
   if(!in_array('aswan-city',$location,true))$reasons[]='city_location_not_assigned';
   if(!in_array('hotel',(array)$types,true))$reasons[]='stage_is_hotel_and_property_is_resort';
   if(!$source['commercial_tier_approved'])$reasons[]='commercial_tier_not_approved';
   if(!get_post_thumbnail_id($target))$reasons[]='no_cleared_featured_image';
   if($sourceId===71501)$reasons[]='island_transfer_not_verified';
   $reasons[]='supplier_cost_receipt_missing';
   $reasons[]='unit_stock_and_occupancy_missing';
   $items[]=['id'=>$target,'canonical_source_id'=>$sourceId,
     'name'=>html_entity_decode(wp_strip_all_tags(get_the_title($target)),ENT_QUOTES,'UTF-8'),
     'post_status'=>$status,'official_source_url'=>(string)$source['overview_url'],
     'edit_url'=>get_edit_post_link($target,'')?:null,
     'proposed_tier'=>$wanted,'stage_property_kind'=>'hotel',
     'property_kind'=>in_array('hotel',(array)$types,true)?'hotel':'resort',
     'reasons'=>$reasons,'qualified'=>false,'assigned'=>false,
     'cost_approved'=>false,'unit_authorized'=>false,'checkout_authorized'=>false];
  }
  return $items;
 }
 public static function report($from,$to){
  if(!class_exists('MAD4B_Package_Stage_Rate_Guard_V1'))
   return new WP_Error('package_readiness_source_guard_unavailable');
  if(!current_user_can('manage_options'))
   return new WP_Error('package_readiness_forbidden');
  $window=self::window($from,$to);
  if(is_wp_error($window))return $window;
  [$from,$to]=$window;
  // Never use Winter supplier quotes for a Summer or mixed-season stage.
  // Quoted validity is still unapproved; this is scope matching only.
  $quotePolicies=[];
  if(class_exists('MAD4B_Supplier_Sheet_Reference_V1')){
   foreach(['summer','winter_2026_27'] as $season){
    $index=MAD4B_Supplier_Sheet_Reference_V1::countsByProperty($season);
    $catalog=MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels',$season);
    if(is_wp_error($index))return $index;
    if(is_wp_error($catalog))return $catalog;
    $base=$catalog['base_season']??[];
    if(!is_string($base['window_start']??null)||
       !is_string($base['window_end']??null))
     return new WP_Error('package_readiness_supplier_season_missing');
    $quotePolicies[$season]=['index'=>$index,
      'start'=>$base['window_start'],'end'=>$base['window_end']];
   }
  }
  $catalog=self::catalog();
  $out=['contract'=>self::CONTRACT,'site'=>home_url('/'),
   'travel_from'=>$from,'travel_to'=>$to,
   'levels'=>$catalog['tiers'],'tour_count'=>count($catalog['tours']),
   'tours'=>[],'totals'=>['stage_count'=>0,'hotel_resort_count'=>0,
     'cruise_count'=>0,'sleeper_train_count'=>0,'tour_rates_active'=>0,
     'transport_assignments'=>0,'price_ready_tier_reviews'=>0,
     'reviewed_tier_combinations'=>0,'unpriced_stages'=>0],
   'commercial_approval_granted'=>false,'checkout_authorized'=>false,
   'rate_created'=>false,'write_performed'=>false];
  foreach($catalog['tours'] as $tour){
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
   $bySourceStage=[];
   foreach($stages as $original)
    if(is_array($original)&&!empty($original['stage_key']))
     $bySourceStage[(string)$original['stage_key']]=$original;
   $byStage=[];
   foreach((array)($bindings['bindings']??[]) as $stageBinding)
    if(is_array($stageBinding)&&!empty($stageBinding['stage_key']))
     $byStage[(string)$stageBinding['stage_key']]=$stageBinding;
   $levels=[];
   foreach($catalog['tiers'] as $tier=>$name){
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
     $sheetQuoteMatches=0;$sheetQuoteProperties=0;$quotePeriod=null;
     $scope='not_hotel_or_resort';
     if(in_array((string)$s['kind'],['hotel','resort'],true)){
      $scope='out_of_sheet_season_or_cross_season';
      $offset=max(0,(int)($s['day_start']??0)-1);
      $nightCount=(int)($s['nights']??0);
      $baseFrom=DateTimeImmutable::createFromFormat('!Y-m-d',$from,new DateTimeZone('UTC'));
      $baseTo=DateTimeImmutable::createFromFormat('!Y-m-d',$to,new DateTimeZone('UTC'));
      if($baseFrom&&$baseTo&&$nightCount>=1&&$nightCount<=40){
       $stageFirst=$baseFrom->modify('+'.$offset.' days')->format('Y-m-d');
       $stageLast=$baseTo->modify('+'.($offset+$nightCount-1).' days')->format('Y-m-d');
       foreach($quotePolicies as $season=>$period){
        if($period['start']<=$stageFirst&&$stageLast<=$period['end']){
         $quotePeriod=$season;$scope='base_sheet_period_match_unapproved';break;
        }
       }
      }
     }
     if($quotePeriod!==null){
      $index=$quotePolicies[$quotePeriod]['index'];
      foreach((array)($s['eligible_property_ids']??[]) as $property){
       $qCount=(int)($index[(int)$property]['quotation_count']??0);
       if($qCount>0){$sheetQuoteMatches+=$qCount;$sheetQuoteProperties++;}
      }
     }
     $missingCandidate=in_array('stage_destination_has_no_candidate_property',
       (array)($s['blocked']??[]),true)&&
       in_array((string)($s['kind']??''),['hotel','resort'],true)&&
       empty($s['season_rejected_property_ids']);
     $candidateDiagnostics=$missingCandidate?
       self::unassignedCandidates($tour,(int)$tier,
         (array)($bySourceStage[(string)$s['key']]??[]),$quotePolicies):null;
     $rows[]=['key'=>$s['key'],'kind'=>$s['kind'],'destination'=>$s['destination'],
      'unassigned_property_diagnostics'=>$candidateDiagnostics,
      'aswan_editorial_draft_review'=>self::pendingAswanCityEditorialDrafts(
        (int)$tour,(int)$tier,(string)$s['key']),
      'sheet_quote_period'=>$quotePeriod,'sheet_quote_scope'=>$scope,
      'sheet_quote_properties'=>$sheetQuoteProperties,
      'sheet_quote_values'=>$sheetQuoteMatches,
      // Deprecated aliases remain truthful and only represent Winter.
      'sheet_winter_quote_properties'=>$quotePeriod==='winter_2026_27'?$sheetQuoteProperties:0,
      'sheet_winter_quote_values'=>$quotePeriod==='winter_2026_27'?$sheetQuoteMatches:0,
      'sheet_price_valid_for_dates'=>false,
      'unbookable_draft_unit_candidates'=>$unitCandidateDraft,
      'published_but_unavailable_unit_candidates'=>$unitCandidatePublished,
      'nights'=>(int)$s['nights'],'eligible_properties'=>count((array)($s['eligible_property_ids']??[])),
      'season_excluded_properties'=>count((array)($s['season_rejected_property_ids']??[])),
      'season_excluded_property_ids'=>array_values((array)($s['season_rejected_property_ids']??[])),
      'selected_property_id'=>(int)($s['selected_property_id']??0),
      'hotel_candidate_options'=>in_array((string)($s['kind']??''),['hotel','resort'],true)
       ?array_map(static fn($id)=>['id'=>(int)$id,
           'name'=>html_entity_decode(wp_strip_all_tags(get_the_title((int)$id)),ENT_QUOTES,'UTF-8')],
         array_values((array)($s['eligible_property_ids']??[]))):[],
      'selected_units_count'=>count((array)($s['selected_unit_ids']??[])),
      'cost_source_priority'=>(array)($s['cost_source_priority']??(
         in_array((string)($s['kind']??''),['hotel','resort'],true)
          ?['unit_rate','property_rate']:[])),
      'cost_rate_scope'=>$s['cost_rate_scope']??null,
      'cost_rate_id'=>$s['cost_rate_id']??null,
      'cost_reference_status'=>$s['cost_reference_status']??'not_approved',
      'cost_reference_checkout_authorized'=>false,
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

