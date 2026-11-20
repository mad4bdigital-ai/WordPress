<?php
/**
 * Official Sonesta Sun/Nile Goddess source-to-editorial enrichment.
 * Strict two-ship WPML/JetEngine/commerce guard, Draft Cabin Class only.
 * MAD4B_CRUISE_WEB_BATCH3_MODE=plan|apply|verify
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)return;
global $wpdb;
$mode=getenv('MAD4B_CRUISE_WEB_BATCH3_MODE')?:'plan';
if(!in_array($mode,['plan','apply','verify'],true))WP_CLI::error('invalid_mode');
if(rtrim(home_url(),'/')!=='https://wayfatours.com'
 ||!taxonomy_exists('amenities')||!post_type_exists('units')
 ||!has_action('wpml_set_element_language_details'))
 WP_CLI::error('wrong_site_or_missing_native_components');
$meta='_mad4b_cruise_public_web_amenities_evidence_v1';
$unitMeta='_mad4b_cruise_category_source_draft_v1';
$contract='mad4b.cruise-operator-web-intake.v3';
$ships=[
 35952=>[
  'title'=>'Sun Goddess','official_name'=>'Sonesta Sun Goddess Nile Cruise',
  'operator'=>'Sonesta Nile River Cruises',
  'primary'=>'https://www.sonesta.com/nile-river-cruises/sonesta-sun-goddess-cruise-ship',
  'accommodation'=>'https://www.sonesta.com/sonesta-sun-goddess-nile-cruise-ship/guest-cabins-and-suites',
  'status'=>'official_operator_source_verified_category_not_sailing',
  'categories'=>[
   ['presidential_suite','Presidential Suite','suite',26,40],
   ['family_suite','Family Suite','suite',2,60],
   ['executive_suite','Executive Suite','suite',1,60],
   ['royal_suite','Royal Suite','suite',1,60],
  ],
  'amenities'=>['sun_deck','restaurant','lounge_bar','wifi','air_con',
    'cabin_safe','spa','gym','jacuzzi','minibar','swim_pool','massage'],
  'other_amenities_not_taxonomized'=>[
   'Private balcony','Music system','TV and movies','In-suite dining',
   'Private lounge','Full board meals','Nightly shows'
  ],
  'published_recurring_programs'=>[
   ['nights'=>3,'start'=>'Aswan','end'=>'Luxor','published_weekday'=>'Friday','native_term_en'=>2348],
   ['nights'=>4,'start'=>'Luxor','end'=>'Aswan','published_weekday'=>'Monday','native_term_en'=>2349],
   ['nights'=>7,'start'=>'Aswan','end'=>'Aswan','published_weekday'=>'Friday','native_term_en'=>2350],
   ['nights'=>7,'start'=>'Luxor','end'=>'Luxor','published_weekday'=>'Monday','native_term_en'=>2350]
  ],
  'source_warnings'=>[
   'All thirty accommodations are Suites: operator count 26+2+1+1, not additional regular cabins.',
   'Official regular weekday labels are not date-specific, saleable sailing seats or confirmed Package sightseeing overlays.',
   'Source room dimensions are category area, not maximum occupancy.'
  ],
 ],
 35906=>[
  'title'=>'M/S Nile Goddess','official_name'=>'Sonesta Nile Goddess Cruise',
  'operator'=>'Sonesta Nile River Cruises',
  'primary'=>'https://www.sonesta.com/nile-river-cruises/sonesta-nile-goddess-cruise-ship',
  'accommodation'=>'https://www.sonesta.com/sonesta-nile-goddess-cruise-ship/guest-cabins-and-suites',
  'status'=>'official_operator_source_verified_category_not_sailing',
  'categories'=>[
   ['deluxe_cabin','Deluxe Cabin','cabin',47,null],
   ['senator_suite','Senator Suite','suite',2,null],
   ['presidential_suite','Presidential Suite','suite',4,null],
  ],
  'amenities'=>['sun_deck','restaurant','lounge_bar','wifi','air_con',
    'cabin_safe','spa','gym','minibar','swim_pool','massage','elevator'],
  'other_amenities_not_taxonomized'=>[
   'Panoramic cabin windows','Connecting cabin option','Sun-deck barbecue',
   'Sauna','Hot tub','Conference room','Nightly entertainment',
   'Guided sightseeing','In-room dining until midnight'
  ],
  'published_recurring_programs'=>[
   ['nights'=>3,'start'=>'Aswan','end'=>'Luxor','published_weekday'=>'Friday','native_term_en'=>2348],
   ['nights'=>4,'start'=>'Luxor','end'=>'Aswan','published_weekday'=>'Monday','native_term_en'=>2349],
   ['nights'=>7,'start'=>'Aswan','end'=>'Aswan','published_weekday'=>'Friday','native_term_en'=>2350],
   ['nights'=>7,'start'=>'Luxor','end'=>'Luxor','published_weekday'=>'Monday','native_term_en'=>2350]
  ],
  'source_warnings'=>[
   'Operator individual-vessel accommodation page reports 47 Deluxe, 2 Senator, 4 Presidential = 53 combined; fleet summary incorrectly implies 47 cabins + 2 suites.',
   'Free/paid onboard Wi-Fi statements conflict across operator pages; existence confirmed, included price not confirmed.',
   'Operator recurring Friday/Monday departures do not confirm specific 2026 or 2027 dates or active inventory.'
  ]
 ],
];
$termSlugs=[
 'sun_deck'=>'mad4b-cruise-sun_deck','restaurant'=>'mad4b-cruise-restaurant',
 'lounge_bar'=>'mad4b-cruise-lounge_bar','wifi'=>'mad4b-cruise-wifi',
 'air_con'=>'mad4b-cruise-air_con','cabin_safe'=>'mad4b-cruise-cabin_safe',
 'spa'=>'mad4b-cruise-spa','gym'=>'mad4b-cruise-gym',
 'jacuzzi'=>'mad4b-cruise-jacuzzi','minibar'=>'mad4b-cruise-minibar',
 'swim_pool'=>'mad4b-cruise-swim_pool','massage'=>'mad4b-cruise-massage',
 'elevator'=>'mad4b-cruise-elevator'
];
if(count($ships)!==2||count($termSlugs)!==13)WP_CLI::error('configured_scope_changed');
if(get_option('mad4b_stay_editorial_publish_v1')['catalog_activated']!==false
 ||(int)(wp_count_posts('tour-rates')->publish??0)!==0
 ||(int)(wp_count_posts('property-rates')->publish??0)!==0
 ||(int)(wp_count_posts('transport-rates')->publish??0)!==0
 ||get_option('commerce_management_company_definitions_v1',null)!==null
 ||get_option('commerce_company_policy_registry_v1',null)!==null)
 WP_CLI::error('sale_or_company_policy_state_changed');
$rawTerm=static function(string $slug)use($wpdb):?array{
 $x=$wpdb->get_row($wpdb->prepare("SELECT t.term_id,t.name,tt.parent,tt.term_taxonomy_id,
 i.language_code,i.trid FROM {$wpdb->terms} t
 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id AND tt.taxonomy='amenities'
 LEFT JOIN {$wpdb->prefix}icl_translations i ON i.element_type='tax_amenities' AND i.element_id=tt.term_taxonomy_id
 WHERE t.slug=%s",$slug),ARRAY_A);
 return $x?:null;
};
$rootEn=$rawTerm('mad4b-cruise-amenities');$rootEs=$rawTerm('mad4b-cruise-amenities-es');
if(!$rootEn||!$rootEs||$rootEn['language_code']!=='en'||$rootEs['language_code']!=='es'
 ||(int)$rootEn['trid']!==(int)$rootEs['trid'])WP_CLI::error('amenities_parent_language_invalid');
$refs=[];
foreach($termSlugs as $key=>$slug){
 $en=$rawTerm($slug);$es=$rawTerm($slug.'-es');
 if(!$en||!$es||(int)$en['parent']!==(int)$rootEn['term_id']
 ||(int)$es['parent']!==(int)$rootEs['term_id']
 ||$en['language_code']!=='en'||$es['language_code']!=='es'
 ||(int)$en['trid']!==(int)$es['trid'])WP_CLI::error('amenity_translation_drift_'.$key);
 $refs[$key]=(int)$en['term_id'];
}
$totalTypes=0;$totalAssigned=0;
foreach($ships as $id=>$s){
 $p=get_post($id);
 if(!$p||$p->post_type!=='properties'||$p->post_status!=='publish'||$p->post_title!==$s['title'])
 WP_CLI::error('ship_identity_not_authorized_'.$id);
 $lang=$wpdb->get_var($wpdb->prepare("SELECT language_code FROM {$wpdb->prefix}icl_translations
 WHERE element_type='post_properties' AND element_id=%d",$id));
 if($lang!=='en')WP_CLI::error('ship_language_not_english_'.$id);
 $kind=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} x JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=x.term_taxonomy_id
 WHERE x.object_id=%d AND tt.taxonomy='property-types' AND tt.term_id=270",$id));
 if($kind!==1)WP_CLI::error('ship_not_cruise_taxonomy_'.$id);
 $currentAmenities=$wpdb->get_col($wpdb->prepare("SELECT tt.term_id FROM {$wpdb->term_relationships} x JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=x.term_taxonomy_id
 WHERE x.object_id=%d AND tt.taxonomy='amenities'",$id));
 $currentUnits=$wpdb->get_col($wpdb->prepare("SELECT child_object_id FROM {$wpdb->prefix}jet_rel_default
 WHERE rel_id='223' AND parent_object_id=%d",$id));
 if($mode!=='verify'&&(metadata_exists('post',$id,$meta)||$currentAmenities||$currentUnits))
 WP_CLI::error('existing_source_amenity_or_units_'.$id);
 $tot=0;
 foreach($s['categories'] as $c){
  if(!in_array($c[2],['suite','cabin'],true)||$c[3]<1)WP_CLI::error('bad_category_source_'.$id);
  $tot+=$c[3];$totalTypes++;
  $slug='mad4b-sonesta-v3-'.$id.'-'.$c[0];
  $exists=$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='units' AND post_name=%s",$slug));
  if($mode==='verify'&&!$exists)WP_CLI::error('created_category_missing_'.$slug);
  if($mode!=='verify'&&$exists)WP_CLI::error('category_slug_preexisting_'.$slug);
 }
 if($tot!==($id===35952?30:53))WP_CLI::error('operator_reported_categories_inconsistent_'.$id);
 foreach($s['amenities'] as $key)if(!isset($refs[$key]))WP_CLI::error('amenity_not_listed_'.$key);
 $totalAssigned+=count($s['amenities']);
}
if($totalTypes!==7||$totalAssigned!==24)WP_CLI::error('expected_category_or_amenity_counts_changed');
if($mode==='plan'){
 WP_CLI::line(wp_json_encode(['mode'=>'plan','ship_ids'=>array_keys($ships),
  'new_draft_unit_categories'=>$totalTypes,'new_native_amenity_terms'=>0,
  'existing_amenity_assignments'=>$totalAssigned,'operator_published_programs'=>8,
  'source_reported_ship_categories'=>[35952=>30,35906=>53],
  'booking_or_prices_authorized'=>false,'catalog_activated'=>false],
 JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));return;
}
if($mode==='apply'){
 if($wpdb->query('START TRANSACTION')===false)WP_CLI::error('database_transaction_not_started');
 try{
  do_action('wpml_switch_language','en');
  foreach($ships as $id=>$s){
   $source=[
    'schema'=>$contract,'ship_property_id'=>$id,'official_ship_name'=>$s['official_name'],
    'operator'=>$s['operator'],'operator_sources'=>[
      ['url'=>$s['primary'],'type'=>'operator_current_cruise_page','robots_direct_crawl_allowed'=>false],
      ['url'=>$s['accommodation'],'type'=>'operator_cabin_types_page','robots_direct_crawl_allowed'=>false]],
    'source_review_method'=>'indexed_official_operator_pages_not_disallowed_direct_crawler',
    'reviewed_on'=>gmdate('Y-m-d'),'public_cabin_category_facts'=>array_map(
      static fn($c)=>['key'=>$c[0],'name'=>$c[1],'kind'=>$c[2],
        'operator_reported_count'=>$c[3],'reported_area_sqm'=>$c[4],
        'counts_not_supplier_inventory'=>true],$s['categories']),
    'cruise_amenities_taxonomy_codes'=>$s['amenities'],
    'additional_operator_amenity_facts_not_taxonomized'=>$s['other_amenities_not_taxonomized'],
    'published_recurring_itinerary_patterns'=>$s['published_recurring_programs'],
    'source_warnings'=>$s['source_warnings'],
    'date_specific_sailings_confirmed'=>false,
    'package_operator_excursion_day_sequence_confirmed'=>false,
    'physical_allotment_verified'=>false,
    'maximum_occupancy_per_cabin_verified'=>false,
    'supplier_contract_confirmed'=>false,'price_amount'=>null,
    'source_photo_rights_approved'=>false,'photos_copied'=>false,
    'available_for_booking'=>false,'catalog_activated'=>false,'recorded_at'=>gmdate('c')
   ];
   if(!add_post_meta($id,$meta,$source,true))throw new RuntimeException('source_save_failed_'.$id);
   $terms=array_map(static fn($c)=>$refs[$c],$s['amenities']);
   $set=wp_set_object_terms($id,$terms,'amenities',false);
   if(is_wp_error($set)||count($set)!==count($terms))throw new RuntimeException('amenity_native_assignment_failed_'.$id);
   foreach($s['categories'] as $c){
    [$key,$label,$kind,$count,$area]=$c;
    $slug='mad4b-sonesta-v3-'.$id.'-'.$key;
    $new=wp_insert_post([
     'post_type'=>'units','post_status'=>'draft','post_author'=>0,
     'post_title'=>$s['official_name'].' — '.$label,'post_name'=>$slug,
     'post_content'=>'Source verified at cabin-category level only. Not a physical supplier allotment, confirmed sailing, approved maximum occupancy or price.'
    ],true);
    if(is_wp_error($new)||!(int)$new)throw new RuntimeException('draft_insert_failed_'.$slug);
    do_action('wpml_set_element_language_details',[
     'element_id'=>(int)$new,'element_type'=>'post_units','trid'=>false,
     'language_code'=>'en','source_language_code'=>null]);
    if(!update_post_meta($new,'unit_kind',$kind)
     ||!update_post_meta($new,'unit_readiness','pending_review')
     ||!update_post_meta($new,'unit_available','false'))
       throw new RuntimeException('unit_noncommerce_guard_missing_'.$new);
    $unitSource=[
     'schema'=>'mad4b.cruise-cabin-web-category.v3',
     'source_property_id'=>$id,'category_key'=>$key,
     'operator_reported_category_count'=>$count,'operator_reported_area_sqm'=>$area,
     'reported_count_not_sellable_inventory'=>true,
     'source_url'=>$s['accommodation'],'source_operator'=>$s['operator'],
     'source_weekly_sailing_not_live_inventory'=>true,
     'supplier_verified_max_occupancy'=>null,'inventory_quantity'=>null,
     'sailing_allotments'=>null,'sailing_date'=>null,'price_amount'=>null,
     'supplier_contract_confirmed'=>false,'unit_available'=>false,
     'checkout_ready'=>false,'status'=>'draft_operator_cabin_class_unpriced'
    ];
    if(!add_post_meta($new,$unitMeta,$unitSource,true))throw new RuntimeException('cabin_evidence_missing_'.$new);
    $related=$wpdb->insert($wpdb->prefix.'jet_rel_default',
      ['rel_id'=>'223','parent_rel'=>0,'parent_object_id'=>$id,'child_object_id'=>$new],
      ['%s','%d','%d','%d']);
    if($related!==1)throw new RuntimeException('jetengine_223_link_failed_'.$new);
   }
  }
  if($wpdb->query('COMMIT')===false)throw new RuntimeException('transaction_commit_failed');
 }catch(Throwable $e){
  $wpdb->query('ROLLBACK');
  WP_CLI::error('batch_rolled_back_'.$e->getMessage());
 }
 do_action('wpml_switch_language','en');
 foreach($ships as $id=>$s){wp_cache_delete($id,'post_meta');clean_object_term_cache($id,'properties');}
 WP_CLI::line(wp_json_encode(['mode'=>'apply','ship_source_receipts'=>2,
  'new_draft_cabin_categories'=>7,'new_native_amenity_terms'=>0,'native_amenity_assignments'=>24,
  'new_published_units'=>0,'native_tour_rates_created'=>0,
  'catalog_activated'=>false,'checkout_ready'=>false],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 return;
}
$errors=[];$created=[];$assigned=[];
foreach($ships as $id=>$s){
 $receipt=get_post_meta($id,$meta,true);
 if(!is_array($receipt)||($receipt['schema']??null)!==$contract
  ||($receipt['available_for_booking']??true)!==false
  ||($receipt['date_specific_sailings_confirmed']??true)!==false
  ||($receipt['physical_allotment_verified']??true)!==false
  ||($receipt['operator_sources'][0]['url']??'')!==$s['primary'])
    $errors[]='operator_receipt_'.$id;
 $have=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT tt.term_id
 FROM {$wpdb->term_relationships} x JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=x.term_taxonomy_id AND tt.taxonomy='amenities'
 WHERE x.object_id=%d ORDER BY tt.term_id",$id)));
 $want=array_map(static fn($key)=>$refs[$key],$s['amenities']);sort($have);sort($want);
 if($have!==$want)$errors[]='ship_amenity_assignment_'.$id;
 $assigned[$id]=count($have);
 $links=$wpdb->get_col($wpdb->prepare("SELECT child_object_id FROM {$wpdb->prefix}jet_rel_default
 WHERE rel_id='223' AND parent_object_id=%d ORDER BY child_object_id",$id));
 if(count($links)!==count($s['categories']))$errors[]='ship_unit_categories_count_'.$id;
 $created[$id]=[];
 foreach($links as $uid){
  $uid=(int)$uid;$source=get_post_meta($uid,$unitMeta,true);
  $language=$wpdb->get_var($wpdb->prepare("SELECT language_code FROM {$wpdb->prefix}icl_translations
  WHERE element_type='post_units' AND element_id=%d",$uid));
  if(get_post_type($uid)!=='units'||get_post_status($uid)!=='draft'||$language!=='en'
   ||get_post_meta($uid,'unit_readiness',true)!=='pending_review'
   ||get_post_meta($uid,'unit_available',true)!=='false'
   ||!is_array($source)||($source['source_property_id']??null)!==$id
   ||($source['checkout_ready']??true)!==false
   ||metadata_exists('post',$uid,'unit_max_occupancy')
   ||metadata_exists('post',$uid,'unit_inventory_quantity')
   ||metadata_exists('post',$uid,'unit_start_price'))
     $errors[]='draft_cabin_not_safe_'.$uid;
  $created[$id][]=['id'=>$uid,'key'=>$source['category_key']??''];
 }
}
WP_CLI::line(wp_json_encode(['mode'=>'verify','errors'=>$errors,'error_count'=>count($errors),
 'ship_source_evidence_records'=>2,'draft_cabin_categories'=>$created,
 'amenity_assignment_counts'=>$assigned,'new_amenity_terms'=>0,
 'published_units_created'=>0,'commerce_ready_cabins'=>0,
 'catalog_activated'=>false,'checkout_ready'=>false],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
if($errors)WP_CLI::error('source_bounded_verify_failed');
