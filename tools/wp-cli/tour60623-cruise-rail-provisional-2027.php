<?php
/**
 * MAD4B Tour 60623: Source-backed cruise candidates and 2027 sleeper train review.
 * Writes only isolated editorial/Operations candidate receipts to exact Tour
 * translations and Train 86/87 Posts; no supplier confirmation, rates or checkout.
 */
defined('ABSPATH')||exit;
$site=home_url();
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 throw new RuntimeException('unexpected_company');
if(!current_user_can('manage_options'))throw new RuntimeException('admin_required');
$source=$site==='https://tours.dev.mad4b.com';
$tours=$source?
 ['en'=>60623,'es'=>60661,'fr'=>60637,'de'=>60649,'it'=>60677,'ar'=>64945]:
 ['en'=>60623,'es'=>60661];
$family=MAD4B_Cruise_Program_Context_V1::family(60623);
$tourmeta='_mad4b_60623_cruise_rail_candidates_2027_v1';
$trainmeta='_mad4b_60623_train_2027_ops_reference_v1';
foreach($tours as $lang=>$id){
 if((int)($family[$lang]??0)!==$id||get_post_type($id)!=='tours-and-activities'||
   get_post_status($id)!=='publish')
  throw new RuntimeException('tour_family_or_status_changed_'.$lang);
 $original=(string)apply_filters('wpml_current_language','en');
 do_action('wpml_switch_language',$lang);
 try {$days=Shared_Product_Program_Schedule::schedule_decision($id);}
 finally {do_action('wpml_switch_language',$original);}
 if(($days['weekdays']??[])!==[1,3])
  throw new RuntimeException('weekday_preimage_mismatch_'.$id);
 if($lang==='en'){
  $levels=wp_get_post_terms($id,'package_levels',['fields'=>'ids']);
  if(is_wp_error($levels)||array_diff([2353,2354],array_map('intval',$levels)))
   throw new RuntimeException('tour_not_Silver_Gold');
 }
 if(get_post_meta($id,$tourmeta,true))
  throw new RuntimeException('candidate_receipt_already_exists_'.$id);
}
if((int)get_post_meta(60623,'tour_cruise_property_id',true)!==0)
 throw new RuntimeException('tour_cruise_property_override_exists');
if(get_post_meta(60623,'train_transport_services',true)!=='')
 throw new RuntimeException('native_train_services_already_assigned');
$units=[78032=>78050,35927=>71765,35972=>71761];
$names=[];
foreach($units as $prop=>$unit){
 if(get_post_type($prop)!=='properties'||get_post_type($unit)!=='units')
  throw new RuntimeException('property_unit_not_found_'.$prop);
 $detail=get_post_meta($unit,'_mad4b_cruise_category_source_draft_v1',true);
 if((int)($detail['source_property_id']??0)!==$prop||
    get_post_status($unit)!=='draft'||
    (string)get_post_meta($unit,'unit_available',true)!=='false'||
    !empty($detail['supplier_contract_confirmed'])||
    !empty($detail['sailing_allotments']))
  throw new RuntimeException('cabin_contract_or_identity_preimage_changed_'.$unit);
 $names[$prop]=['property_id'=>$prop,'property_name'=>html_entity_decode(
    wp_strip_all_tags(get_the_title($prop)),ENT_QUOTES|ENT_HTML5,'UTF-8'),
    'property_type'=>'properties',
    'unit_id'=>$unit,'unit_name'=>html_entity_decode(
    wp_strip_all_tags(get_the_title($unit)),ENT_QUOTES|ENT_HTML5,'UTF-8'),
    'unit_type'=>'units',
    'unit_kind'=>(string)get_post_meta($unit,'unit_kind',true),
    'unit_status'=>get_post_status($unit),
    'inventory_confirmed'=>false,
    'cabin_category_source_url'=>(string)($detail['official_source_url']??$detail['source_url']??'')];
}
$bashteelStationId=$source?68930:68922;
if(get_post_type($bashteelStationId)!=='stations'||
   !str_contains((string)get_the_title($bashteelStationId),'Upper Egypt Railway Station'))
 throw new RuntimeException('site_specific_bashteel_station_not_verified');
$trains=[
 86=>['id'=>54552,'from_id'=>$bashteelStationId,'to_id'=>54258,'from'=>'Cairo Upper Egypt (Bashtil)',
   'via'=>'Giza 20:15','to'=>'Aswan','departure_time'=>'19:45','arrival_time'=>'09:25',
   'departure_station'=>'Cairo Upper Egypt','departure_train_day_offset'=>1,
   'arrival_train_day_offset'=>2,'duration_hours'=>13.67],
 87=>['id'=>54553,'from_id'=>54258,'to_id'=>$bashteelStationId,'from'=>'Luxor (boarding en route)',
   'via'=>'Luxor 20:35; Giza 06:05 next day','to'=>'Cairo Upper Egypt (Bashtil)',
   'departure_time'=>'17:15','arrival_time'=>'06:25',
   'departure_station'=>'Aswan (train origin)','departure_train_day_offset'=>5,
   'arrival_train_day_offset'=>6,'duration_hours'=>null]
];
foreach($trains as $number=>$row){
 $id=$row['id'];
 if(get_post_type($id)!=='transportations'||get_post_status($id)!=='publish'||
   (int)get_post_meta($id,'trans_train_number',true)!==$number||
   (int)get_post_meta($id,'trans_from_station_id',true)!==$row['from_id']||
   (int)get_post_meta($id,'trans_to_station_id',true)!==$row['to_id']||
   (string)get_post_meta($id,'trans_departure_time',true)!==$row['departure_time']||
   (string)get_post_meta($id,'trans_arrival_time',true)!==$row['arrival_time'])
  throw new RuntimeException('train_timetable_catalog_preimage_changed_'.$id);
 if(get_post_meta($id,$trainmeta,true))
  throw new RuntimeException('train_review_already_exists_'.$id);
 $trains[$number]['name']=html_entity_decode(
   wp_strip_all_tags(get_the_title($id)),ENT_QUOTES|ENT_HTML5,'UTF-8');
 $trains[$number]['type']='transportations';
 $trains[$number]['published_2026_pattern_confirmed']=true;
 $trains[$number]['official_2027_dated_schedule_verified']=false;
 $trains[$number]['seat_or_cabin_availability_verified']=false;
 $trains[$number]['supplier_rate_verified']=false;
 $trains[$number]['existing_status_is_service_listing_not_live_inventory']=true;
}
$recorded=gmdate('c');
$candidates=[
 'monday'=>[
  'departure_day_iso'=>1,'tour_departure_name'=>'Monday',
  'cruise_embark_weekday_iso'=>3,'cruise_embark_name'=>'Wednesday',
  'route'=>'Aswan to Luxor','duration_nights'=>3,'program_id'=>2348,
  'level_candidates'=>[
   'silver'=>array_merge($names[78032],[
     'tier_id'=>2353,'tier_name'=>'Silver','candidate_role'=>'unpriced_budget_tier_editorial_candidate',
     'sailing_pattern_status'=>'wednesday_3_night_researched_2027_specific_unconfirmed',
     'reference'=>'https://princess-cruise.net/blue-shadow-nile-cruise/blue-shadow-i/',
     'tier_supplier_cost_approved'=>false]),
   'gold'=>array_merge($names[35927],[
     'tier_id'=>2354,'tier_name'=>'Gold','candidate_role'=>'primary_Gold_editorial_candidate',
     'sailing_pattern_status'=>'wednesday_3_night_2026_27_agency_reference',
     'reference'=>'https://www.goldengatestravel.com/nile-cruises/concerto-ii-nile-cruise',
     'ship_official_reference'=>'https://extensiongroup.com.eg/concerto-ii',
     'tier_supplier_cost_approved'=>false])
  ],
  'dated_operator_calendar_reference'=>'https://staging.sonesta.com/sonesta-star-goddess-nile-cruise-ship/sail-dates',
  'dated_operator_calendar_alt_ship'=>['id'=>35969,'name'=>'M/S Sonesta Star Goddess'],
  'sailing_calendar_confirms_route_day_not_reserved_inventory'=>true
 ],
 'wednesday'=>[
  'departure_day_iso'=>3,'tour_departure_name'=>'Wednesday',
  'cruise_embark_weekday_iso'=>5,'cruise_embark_name'=>'Friday',
  'route'=>'Aswan to Luxor','duration_nights'=>3,'program_id'=>2348,
  'level_candidates'=>[
   'silver'=>[
    'tier_id'=>2353,'tier_name'=>'Silver','property_id'=>null,
    'property_name'=>'JAZ Gloria','property_type'=>'properties',
    'unit_id'=>null,'unit_name'=>'JAZ Gloria — Standard Cabin',
    'unit_type'=>'units','unit_status'=>'not_created_in_catalog',
    'candidate_role'=>'external_verified_cabin_category_requires_catalog_and_tier_pricing',
    'reference'=>'https://www.jazcruises.com/cruise-ships/58-jaz-gloria',
    'sailing_pattern_status'=>'friday_3_night_official_weekly_pattern_no_inventory',
    'inventory_confirmed'=>false,'tier_supplier_cost_approved'=>false],
   'gold'=>array_merge($names[35972],[
     'tier_id'=>2354,'tier_name'=>'Gold','candidate_role'=>'Gold_premium_upgrade_requires_margin_review',
     'sailing_pattern_status'=>'2027_dated_official_sailing_published',
     'reference'=>'https://staging.sonesta.com/sonesta-st-george-1-nile-cruise-ship/sail-dates',
     'tier_supplier_cost_approved'=>false])
  ],
  'dated_operator_calendar_reference'=>'https://staging.sonesta.com/sonesta-st-george-1-nile-cruise-ship/sail-dates',
  'sailing_calendar_confirms_route_day_not_reserved_inventory'=>true
 ]
];
$examples=[
 '2027-01-11'=>['tour_start'=>'2027-01-11','tour_start_weekday'=>'Monday',
  'train86_departure'=>'2027-01-12','train86_arrival_aswan'=>'2027-01-13',
  'cruise_embark'=>'2027-01-13','cruise_disembark'=>'2027-01-16',
  'train87_board_at_luxor'=>'2027-01-16','train87_arrive_cairo'=>'2027-01-17',
  'gold_proposed_vessel'=>35927,'silver_proposed_vessel'=>78032,
  'sailing_dated_evidence'=>'Sonesta Star Goddess Wednesday official 2027 sail date'],
 '2027-01-13'=>['tour_start'=>'2027-01-13','tour_start_weekday'=>'Wednesday',
  'train86_departure'=>'2027-01-14','train86_arrival_aswan'=>'2027-01-15',
  'cruise_embark'=>'2027-01-15','cruise_disembark'=>'2027-01-18',
  'train87_board_at_luxor'=>'2027-01-18','train87_arrive_cairo'=>'2027-01-19',
  'gold_proposed_vessel'=>35972,'silver_proposed_vessel'=>null,
  'sailing_dated_evidence'=>'Sonesta St George 1 Friday official 2027 sail date']
];
$base=[
 'contract'=>'mad4b.tour-60623-supplier-candidates.2027.v1',
 'recorded_utc'=>$recorded,'tour_id_en'=>60623,
 'tour_name'=>'Egypt Budget Tour: Cairo to Luxor Nile Cruise Discovery – 8 Days',
 'type'=>'tours-and-activities','tour_level_ids'=>[2353,2354],
 'cruise_stage_key'=>'cruise-nile_cruise-03','source_plan_program_id'=>2348,
 'program_route'=>'Aswan to Luxor','program_nights'=>3,
 'source'=>'public_operator_and_competitor_research',
 'sailing_assignments_by_departure_weekday'=>$candidates,
 'sample_2027_itineraries'=>$examples,
 'supplier_invoice_and_contract_confirmed'=>false,
 'ship_booking_confirmed'=>false,'ship_inventory_confirmed'=>false,
 'assigned_bookable_unit_id'=>null,'approved_price'=>null,
 'native_tour_rate_written'=>false,'native_selected_cruise_property_written'=>false,
 'checkout_authorized'=>false,'form6_price_overridden'=>false,
 'status'=>'editorial_operations_candidate_only_requires_real_date_supplier_confirmation',
 'notes'=>'Never use these candidates as a confirmed ship or sellable cabin. Each customer date needs signed vessel cabin availability, offer, class, package level and departure cut-off confirmation.'
];
$trainBase=[
 'contract'=>'mad4b.tour-60623-abela-2027-route-review.v1',
 'recorded_utc'=>$recorded,'tour_id_en'=>60623,
 'train_operator'=>'Abela Trains','service_ids'=>[54552,54553],
 'source_timetable_year'=>2026,'requested_operating_year'=>2027,
 'sources'=>[
  'https://www.seat61.com/cairo-to-luxor-and-aswan-by-sleeper-train.htm',
  'https://egypttrains.com/trains/86?lang=en',
  'https://egypttrains.com/trains/87?lang=en',
  'https://abelatrains.com/'],
 'train_services'=>$trains,
 'sample_departure_legs'=>$examples,
 '2027_time_exactly_confirmed_by_abela'=>false,
 '2027_booking_inventory_checked'=>false,
 'customer_passenger_list_known'=>false,
 'actual_two_berth_compartment_assigned'=>false,
 'booking_reference'=>null,'fare_verified'=>false,
 'ticket_stock_not_inferred_from_trans_available_status'=>true,
 'must_check'=>[
  'Abela official 2027 date-specific timetable at real booking',
  '2-adult sharing or separate occupancy and nationality',
  '2 berths on train 86 Day 2 and train 87 Day 6',
  'Aswan 09:25 expected arrival vs actual ship embark cut-off',
  'Luxor cruise disembark Day 6 to train 87 20:35 boarding',
  'booking references and supplier invoice before payment confirmation'],
 'status'=>'2026_operating_baseline_2027_train_berths_pending',
 'checkout_authorized'=>false,'native_transport_rates_written'=>false
];
$apply=getenv('MAD4B_APPLY_TOUR60623_SUPPLIER_CANDIDATES')==='yes';
$out=['site'=>$site,'dry_run'=>!$apply,'target_tours'=>$tours,
 'candidate_ship_cabins'=>$candidates,
 'train_services'=>array_map(fn($x)=>[
  'id'=>$x['id'],'name'=>$x['name'],'type'=>$x['type'],
  'departure_time'=>$x['departure_time'],'arrival_time'=>$x['arrival_time'],
  'seats_confirmed'=>$x['seat_or_cabin_availability_verified']],$trains),
 '2027_samples'=>$examples,
 'catalog_booking_assigned'=>false,'source_cost_approved'=>false,'records_written'=>0];
if($apply){
 global $wpdb;$wpdb->query('START TRANSACTION');
 try{
  foreach($tours as $lang=>$id){
   $m=$base;$m['wpml_language']=$lang;
   $n=$trainBase;$n['wpml_language']=$lang;
   if(!add_post_meta($id,$tourmeta,$m,true) ||
      !add_post_meta($id,$trainmeta,$n,true))
    throw new RuntimeException('tour_metadata_save_failed_'.$lang);
   $out['records_written']+=2;
  }
  foreach($trains as $number=>$train){
   $n=['contract'=>'mad4b.abela-2027-provisional-service-review.v1',
    'recorded_utc'=>$recorded,'train_number'=>$number,
    'service_id'=>$train['id'],'service_name'=>$train['name'],
    'type'=>'transportations','purpose_tour_id'=>60623,
    'source_timetable_year'=>2026,
    '2027_booking_inventory_checked'=>false,
    '2027_exact_service_confirmation'=>false,
    'arrival_and_departure_catalog_fields_preserved'=>true,
    'quote_price_approved'=>false,'payment_authorized'=>false,
    'reference_urls'=>$trainBase['sources'],
    'sample_2027_tour_departures'=>array_keys($examples),
    'status'=>'date_specific_rail_supplier_confirmation_required'];
   if(!add_post_meta($train['id'],$trainmeta,$n,true))
    throw new RuntimeException('train_metadata_save_failed_'.$train['id']);
   $out['records_written']++;
  }
  if($wpdb->query('COMMIT')===false)throw new RuntimeException('transaction_commit_failed');
 }catch(Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
}
foreach($tours as $lang=>$id){
 if($apply){
  $r=get_post_meta($id,$tourmeta,true);
  $t=get_post_meta($id,$trainmeta,true);
  if(!is_array($r)||!is_array($t)||
     $r['sailing_assignments_by_departure_weekday']['wednesday']['level_candidates']['gold']['unit_id']!==71761||
     $r['sailing_assignments_by_departure_weekday']['monday']['level_candidates']['gold']['unit_id']!==71765||
     $r['approved_price']!==null||$r['ship_inventory_confirmed']!==false||
     $t['2027_booking_inventory_checked']!==false||
     (int)get_post_meta($id,'tour_cruise_property_id',true)!==0)
   throw new RuntimeException('afterwrite_readback_mismatch_'.$lang);
 }
}
if($apply)$out['readback_validated']=true;
echo wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

