<?php
/**
 * Wayfatours: ship-specific source-backed cruise amenities and editorial draft
 * Cabin category Unit records. Do not use this as supplier inventory or rates.
 * Run once in full WordPress runtime with WPML/JetEngine loaded.
 *
 * MAD4B_CRUISE_WEB_ENRICHMENT_MODE=plan|apply|verify
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) return;
global $wpdb;
$mode = getenv('MAD4B_CRUISE_WEB_ENRICHMENT_MODE') ?: 'plan';
if (!in_array($mode, ['plan','apply','verify'], true)) WP_CLI::error('bad_mode');
if (rtrim(home_url(), '/') !== 'https://wayfatours.com' ||
    !taxonomy_exists('amenities') || !post_type_exists('units') ||
    !has_action('wpml_set_element_language_details')) WP_CLI::error('site_taxonomy_or_wpml_not_ready');

$termPrefix = 'mad4b-cruise-';
$rootNames = ['en'=>'Cruise Amenities','es'=>'Servicios de crucero'];
$features = [
 'sun_deck'     => ['en'=>'Sun Deck','es'=>'Cubierta solárium'],
 'restaurant'   => ['en'=>'Onboard Restaurant','es'=>'Restaurante a bordo'],
 'lounge_bar'   => ['en'=>'Lounge Bar','es'=>'Bar salón'],
 'wifi'         => ['en'=>'Onboard Wi-Fi','es'=>'Wi-Fi a bordo'],
 'air_con'      => ['en'=>'Cabin Air Conditioning','es'=>'Aire acondicionado en cabinas'],
 'cabin_safe'   => ['en'=>'In-Cabin Safe','es'=>'Caja fuerte en cabina'],
 'plunge_pool'  => ['en'=>'Plunge Pool','es'=>'Piscina pequeña'],
 'spa'          => ['en'=>'Onboard Spa','es'=>'Spa a bordo'],
 'gym'          => ['en'=>'Onboard Gym','es'=>'Gimnasio a bordo'],
 'jacuzzi'      => ['en'=>'Open-Air Jacuzzi','es'=>'Jacuzzi al aire libre'],
 'minibar'      => ['en'=>'Cabin Minibar','es'=>'Minibar en cabina'],
 'swim_pool'    => ['en'=>'Sun Deck Swimming Pool','es'=>'Piscina en cubierta'],
 'massage'      => ['en'=>'Onboard Massage','es'=>'Masajes a bordo'],
 'elevator'     => ['en'=>'Onboard Elevator','es'=>'Ascensor a bordo'],
];
$ships = [
 35809 => [
  'name'=>'Storia The Dahabeya',
  'operator'=>'AD Nile Cruises / The AND Group',
  'status'=>'operator_official_source_reviewed',
  'sources'=>[
   ['url'=>'https://adnilecruises.com/cruise/storia-the-dahabeya/',
    'role'=>'operator_official_ship_amenities_cabins','direct_automated_scrape_allowed'=>false],
   ['url'=>'https://adnilecruises.com/es/cruise/storia-the-dahabeya/',
    'role'=>'operator_official_spanish_amenities','direct_automated_scrape_allowed'=>false],
  ],
  'amenities'=>['sun_deck','restaurant','lounge_bar','wifi','air_con','cabin_safe','plunge_pool','spa','gym'],
  'cabin_class_evidence'=>[
    ['key'=>'luxury_cabin','name'=>'Luxury Cabin','kind'=>'cabin','reported_count'=>6,
     'quantity_scope'=>'all_luxury_cabins_combined_including_twin_configuration',
     'source_note'=>'Operator identifies 6 cabins, Luxury Cabin and Luxury Cabin Twin; individual bed subtype counts not specified'],
    ['key'=>'polaris_suite','name'=>'Polaris Suite','kind'=>'suite','reported_count'=>1,
     'quantity_scope'=>'named_suite_category','source_note'=>'Operator lists Polaris Suite as one of two suites'],
    ['key'=>'moonlight_suite','name'=>'Moonlight Suite','kind'=>'suite','reported_count'=>1,
     'quantity_scope'=>'named_suite_category','source_note'=>'Operator lists Moonlight Suite as one of two suites'],
  ],
  'reported_total_cabins_and_suites'=>8,
  'reported_max_ship_guests'=>18,
  'research_warnings'=>[
   'Luxury Cabin Twin is a configuration within the six cabins; do not double count',
   'No contractual supplier availability, cabin maximum occupancy or sailing allotment verified'
  ],
  'taxonomy_assignment_allowed'=>true,
  'draft_unit_creation_allowed'=>true,
 ],
 35817 => [
  'name'=>'Sonesta Amirat Dahabeya II',
  'operator'=>'Sonesta Nile River Cruises',
  'status'=>'operator_official_source_reviewed',
  'sources'=>[
    ['url'=>'https://newsroom.sonesta.com/uncategorized/sonesta-announces-launch-of-sonesta-dahabeya-amirat-ii/',
     'role'=>'operator_official_2025_press_release','direct_automated_scrape_allowed'=>true],
    ['url'=>'https://www.sonesta.com/sonesta-dahabeya-amirat-ii/guest-cabins-and-suites',
     'role'=>'operator_official_accommodation_amenities','direct_automated_scrape_allowed'=>false],
    ['url'=>'https://www.sonesta.com/sonesta-dahabeya-amirat-ii/',
     'role'=>'operator_main_itinerary_4_nights','direct_automated_scrape_allowed'=>false],
    ['url'=>'https://www.sonesta.com/nile-river-cruises/sonesta-dahabeya-amirat-ii',
     'role'=>'operator_group_itinerary_7_nights_conflicts_with_main','direct_automated_scrape_allowed'=>false],
  ],
  'amenities'=>['sun_deck','restaurant','lounge_bar','wifi','air_con','cabin_safe','gym','jacuzzi','minibar','massage','elevator'],
  'cabin_class_evidence'=>[
    ['key'=>'executive_cabin','name'=>'Executive Cabin','kind'=>'cabin','reported_count'=>5,
     'quantity_scope'=>'ship_total_category_not_usable_stock','reported_area_sqm'=>25,
     'source_note'=>'Five executive/deluxe cabins per operator; 25 m2 cabin size in accommodation page'],
    ['key'=>'guest_suite','name'=>'Guest Suite','kind'=>'suite','reported_count'=>4,
     'quantity_scope'=>'all_guest_suites_combined_royal_and_executive',
     'source_note'=>'Four suites; Royal and Executive suite varieties listed but current counts per subtype not proven'],
  ],
  'reported_total_cabins_and_suites'=>9,
  'reported_max_ship_guests'=>null,
  'research_warnings'=>[
   'Sonesta official pages mention 4-night itineraries and separately seven-night itineraries; no departure program/supplier confirmed',
   'Room-category count is ship description, not real-time allotment or child occupancy'
  ],
  'taxonomy_assignment_allowed'=>true,
  'draft_unit_creation_allowed'=>true,
 ],
 35810 => [
  'name'=>'Iberotel Amara',
  'operator'=>'Jaz Hotel Group / Iberotel',
  'status'=>'historical_official_factsheet_awaits_current_ship_confirmation',
  'sources'=>[
    ['url'=>'https://partner.jazhotels.com/cruise/82',
     'role'=>'operator_iberotel_amara_asset_gallery','direct_automated_scrape_allowed'=>true],
    ['url'=>'https://partner.jazhotels.com/Images/uploads/gallery/Downloads/new/060349Iberotel%20Amara%20Factsheet.pdf',
     'role'=>'operator_archived_iberotel_amara_fact_sheet','direct_automated_scrape_allowed'=>null],
    ['url'=>'https://storage.googleapis.com/jaz-prod/strapi/060349_Iberotel_Amara_Factsheet_fe49c81407/060349_Iberotel_Amara_Factsheet_fe49c81407.pdf',
     'role'=>'newer_pdf_path_but_contents_identify_different_ship_jaz_al_qassida','direct_automated_scrape_allowed'=>null],
  ],
  'amenities'=>['sun_deck','restaurant','lounge_bar','wifi','air_con','cabin_safe','gym','minibar','swim_pool','massage'],
  'cabin_class_evidence'=>[
   ['key'=>'double_cabin','name'=>'Double Cabin','kind'=>'cabin','reported_count'=>25,
    'quantity_scope'=>'archived_factsheet_only','reported_area_sqm'=>15],
   ['key'=>'single_cabin','name'=>'Single Cabin','kind'=>'cabin','reported_count'=>2,
    'quantity_scope'=>'archived_factsheet_only','reported_area_sqm'=>15],
   ['key'=>'suite','name'=>'Suite','kind'=>'suite','reported_count'=>2,
    'quantity_scope'=>'archived_factsheet_only','reported_area_sqm'=>28],
  ],
  'reported_total_cabins_and_suites'=>29,
  'reported_max_ship_guests'=>null,
  'research_warnings'=>[
   'Recent Jaz PDF URL mentioning Iberotel Amara is internally branded Jaz Al Qassida; source conflict needs operator confirmation',
   'Archived Amara factsheet cannot be treated as current contracted cabin supply',
   'Limited Wi-Fi; minibar, massage, salon and other marked services may carry extra charge'
  ],
  'taxonomy_assignment_allowed'=>false,
  'draft_unit_creation_allowed'=>false,
 ],
];
$sourceMeta='_mad4b_cruise_public_web_amenities_evidence_v1';
$draftMeta='_mad4b_cruise_category_source_draft_v1';
$rootSlug='mad4b-cruise-amenities';
if (count($ships)!==3 || count($features)!==14) WP_CLI::error('scope_count_drift');
if (get_option('mad4b_stay_editorial_publish_v1')['catalog_activated'] !== false ||
  (int)(wp_count_posts('tour-rates')->publish??0)!==0 ||
  (int)(wp_count_posts('property-rates')->publish??0)!==0 ||
  (int)(wp_count_posts('transport-rates')->publish??0)!==0 ||
  get_option('commerce_management_company_definitions_v1',null)!==null ||
  get_option('commerce_company_policy_registry_v1',null)!==null)
 WP_CLI::error('commerce_or_governance_guard_failed');

$rawTerm = static function(string $slug) use ($wpdb): ?array {
 $r=$wpdb->get_row($wpdb->prepare(
 "SELECT t.term_id,t.name,t.slug,tt.term_taxonomy_id,tt.parent FROM {$wpdb->terms} t
 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
 WHERE tt.taxonomy='amenities' AND t.slug=%s",$slug),ARRAY_A);
 return $r ?: null;
};
$getTermTranslation = static function(int $ttId) use ($wpdb): ?array {
 $r=$wpdb->get_row($wpdb->prepare("SELECT trid,language_code,source_language_code FROM {$wpdb->prefix}icl_translations
 WHERE element_type='tax_amenities' AND element_id=%d",$ttId),ARRAY_A);
 return $r ?: null;
};
$termSlug=static fn(string $key,string $lang)=>'mad4b-cruise-'.$key.($lang==='es'?'-es':'');
$taxonomyMap=[];
foreach (['root'=>true] as $_key=>$_val) {}
foreach (['en','es'] as $lang) {
 $slug=$lang==='en'?$rootSlug:$rootSlug.'-es';
 if($mode==='verify'){
   $r=$rawTerm($slug);
   if(!$r || $r['name']!==$rootNames[$lang])WP_CLI::error('cruise_root_term_missing_'.$lang);
   $taxonomyMap['root'][$lang]=$r;
 }elseif($rawTerm($slug))WP_CLI::error('cruise_root_already_exists_'.$lang);
 foreach($features as $key=>$names){
   $slug=$termSlug($key,$lang);
   if($mode==='verify'){
    $t=$rawTerm($slug);if(!$t || $t['name']!==$names[$lang])WP_CLI::error('cruise_feature_term_missing_'.$slug);
    $taxonomyMap[$key][$lang]=$t;
   }elseif($rawTerm($slug))WP_CLI::error('cruise_feature_already_exists_'.$slug);
 }
}
foreach($ships as $id=>$ship){
 $p=get_post($id);
 if(!$p||$p->post_type!=='properties'||$p->post_status!=='publish'||$p->post_title!==$ship['name'])
  WP_CLI::error('ship_identity_or_status_changed_'.$id);
 $i=$wpdb->get_row($wpdb->prepare("SELECT language_code FROM {$wpdb->prefix}icl_translations
 WHERE element_type='post_properties' AND element_id=%d",$id),ARRAY_A);
 if(($i['language_code']??'')!=='en')WP_CLI::error('ship_not_english_'.$id);
 $type=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} r
 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id
 WHERE r.object_id=%d AND tt.taxonomy='property-types' AND tt.term_id=270",$id));
 if((int)$type!==1)WP_CLI::error('not_cruise_taxonomy_'.$id);
 $old=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} r
 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id
 WHERE r.object_id=%d AND tt.taxonomy='amenities'",$id));
 if($mode!=='verify'&&((int)$old!==0||metadata_exists('post',$id,$sourceMeta)))WP_CLI::error('source_or_amenities_already_exist_'.$id);
 $unitLinks=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default
 WHERE rel_id='223' AND parent_object_id=%d",$id));
 if($mode!=='verify'&&$unitLinks!==0)WP_CLI::error('ship_already_has_unit_relations_'.$id);
 foreach($ship['cabin_class_evidence'] as $group){
  if(!in_array($group['kind'],['cabin','suite'],true))WP_CLI::error('bad_cabin_kind_'.$id);
  $slug='mad4b-web-evidence-'.$id.'-'.$group['key'];
  $old=$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='units' AND post_name=%s",$slug));
  if($mode==='verify' && $ship['draft_unit_creation_allowed'] && !$old)WP_CLI::error('missing_draft_cabin_'.$slug);
  if($mode!=='verify' && $old) WP_CLI::error('duplicate_unit_slug_'.$slug);
 }
}
if($mode==='plan'){
 WP_CLI::line(wp_json_encode(['mode'=>$mode,'source_ship_ids'=>array_keys($ships),
  'ship_facts_status'=>array_map(static fn($s)=>$s['status'],$ships),
  'planned_amenities_en_es_terms'=>(1+count($features))*2,
  'planned_new_draft_cabin_category_units'=>5,
  'verified_ship_taxonomy_assignees'=>[35809,35817],
  'archived_iberotel_assignment_blocked'=>true,'published_rate_writes'=>0,
  'catalog_activated'=>false,'prices_or_inventory_writes'=>0],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 return;
}
if($mode==='apply'){
 if($wpdb->query('START TRANSACTION')===false)WP_CLI::error('transaction_unavailable');
 try {
  foreach(['en','es'] as $lang){
   do_action('wpml_switch_language',$lang);
   $created=wp_insert_term($rootNames[$lang],'amenities',
      ['slug'=>$lang==='en'?$rootSlug:$rootSlug.'-es']);
   if(is_wp_error($created))throw new RuntimeException('root_create_'.$lang.'_'.$created->get_error_code());
   $taxonomyMap['root'][$lang]=$rawTerm($lang==='en'?$rootSlug:$rootSlug.'-es');
   if(!$taxonomyMap['root'][$lang])throw new RuntimeException('root_readback_'.$lang);
   do_action('wpml_set_element_language_details',[
    'element_id'=>(int)$created['term_taxonomy_id'],'element_type'=>'tax_amenities',
    'trid'=>$lang==='en'?false:(int)$getTermTranslation((int)$taxonomyMap['root']['en']['term_taxonomy_id'])['trid'],
    'language_code'=>$lang,'source_language_code'=>$lang==='en'?null:'en']);
   foreach($features as $key=>$labels){
    $slug=$termSlug($key,$lang);
    $result=wp_insert_term($labels[$lang],'amenities',[
      'slug'=>$slug,'parent'=>(int)$created['term_id']]);
    if(is_wp_error($result))throw new RuntimeException('term_create_'.$slug.'_'.$result->get_error_code());
    $taxonomyMap[$key][$lang]=$rawTerm($slug);
    if(!$taxonomyMap[$key][$lang])throw new RuntimeException('term_readback_'.$slug);
    do_action('wpml_set_element_language_details',[
      'element_id'=>(int)$result['term_taxonomy_id'],'element_type'=>'tax_amenities',
      'trid'=>$lang==='en'?false:(int)$getTermTranslation((int)$taxonomyMap[$key]['en']['term_taxonomy_id'])['trid'],
      'language_code'=>$lang,'source_language_code'=>$lang==='en'?null:'en']);
   }
  }
  do_action('wpml_switch_language','en');
  foreach($ships as $id=>$ship){
   $evidence=[
    'schema'=>'mad4b.cruise-web-source-amenities.v1','ship_property_id'=>$id,
    'operator'=>$ship['operator'],'status'=>$ship['status'],
    'source_pages'=>$ship['sources'],
    'source_scraping_policy'=>'robots_respected_no_automated_direct_crawl_on_disallowed_operator_pages',
    'source_reviewed_on'=>gmdate('Y-m-d'),
    'onboard_cruise_amenities'=>$ship['amenities'],
    'cabin_category_facts'=>$ship['cabin_class_evidence'],
    'reported_total_cabins_and_suites'=>$ship['reported_total_cabins_and_suites'],
    'reported_max_ship_guests'=>$ship['reported_max_ship_guests'],
    'research_warnings'=>$ship['research_warnings'],
    'source_facts_not_live_inventory'=>true,
    'unit_room_max_occupancy_confirmed'=>false,
    'price_amount'=>null,'supplier_contract_confirmed'=>false,
    'sailing_departure_calendar_verified'=>false,
    'photos_copied_from_source'=>false,
    'cruise_amenities_taxonomy_applied'=>$ship['taxonomy_assignment_allowed'],
    'available_for_booking'=>false,
    'recorded_at'=>gmdate('c')
   ];
   if(!add_post_meta($id,$sourceMeta,$evidence,true))throw new RuntimeException('ship_source_meta_'.$id);
   if($ship['taxonomy_assignment_allowed']){
     $termIds=array_map(static fn($key)=>(int)$taxonomyMap[$key]['en']['term_id'],$ship['amenities']);
     $set=wp_set_object_terms($id,$termIds,'amenities',false);
     if(is_wp_error($set)||count($set)!==count($termIds))throw new RuntimeException('ship_amenities_terms_'.$id);
   }
   if(!$ship['draft_unit_creation_allowed'])continue;
   foreach($ship['cabin_class_evidence'] as $category){
    $slug='mad4b-web-evidence-'.$id.'-'.$category['key'];
    $pid=wp_insert_post([
     'post_type'=>'units','post_status'=>'draft','post_title'=>$ship['name'].' — '.$category['name'],
     'post_name'=>$slug,'post_author'=>0,'post_content'=>'Editorial cabin category documented on the operator website. Supplier pricing, max occupancy, departure availability, and physical allotment are not confirmed. Not bookable.'
    ],true);
    if(is_wp_error($pid)||!(int)$pid)throw new RuntimeException('unit_draft_insert_'.$slug);
    do_action('wpml_set_element_language_details',[
     'element_id'=>(int)$pid,'element_type'=>'post_units','trid'=>false,
     'language_code'=>'en','source_language_code'=>null]);
    if(!update_post_meta($pid,'unit_kind',$category['kind']) ||
      !update_post_meta($pid,'unit_readiness','pending_review') ||
      !update_post_meta($pid,'unit_available','false'))
       throw new RuntimeException('unit_basic_meta_'.$pid);
    $unitEvidence=[
      'schema'=>'mad4b.cruise-cabin-web-category.v1',
      'source_property_id'=>$id,'category_key'=>$category['key'],
      'operator_reported_category_count'=>$category['reported_count'],
      'count_is_not_selling_inventory'=>true,
      'quantity_scope'=>$category['quantity_scope'],
      'source_notes'=>$category['source_note']??null,
      'reported_area_sqm'=>$category['reported_area_sqm']??null,
      'source_url'=>$ship['sources'][0]['url'],
      'supplier_verified_max_occupancy'=>null,
      'supplier_inventory_quantity'=>null,
      'sailing_date'=>null,'rate_amount'=>null,
      'status'=>'draft_editorial_needs_supplier_contract',
      'booking_authorized'=>false,'checkout_ready'=>false
    ];
    if(!add_post_meta($pid,$draftMeta,$unitEvidence,true))throw new RuntimeException('unit_source_receipt_'.$pid);
    $r=$wpdb->insert($wpdb->prefix.'jet_rel_default',[
     'rel_id'=>'223','parent_rel'=>0,
     'parent_object_id'=>$id,'child_object_id'=>$pid
    ],['%s','%d','%d','%d']);
    if(!$r)throw new RuntimeException('jetengine_relation_insert_'.$pid);
   }
  }
  if($wpdb->query('COMMIT')===false)throw new RuntimeException('transaction_commit_failed');
 }catch(Throwable $e){
  $wpdb->query('ROLLBACK');
  WP_CLI::error('enrichment_rolled_back_'.$e->getMessage());
 }
 do_action('wpml_switch_language','en');
 foreach(array_keys($ships) as $id) {
  wp_cache_delete($id,'post_meta');
  clean_object_term_cache($id,'properties');
 }
 WP_CLI::line(wp_json_encode(['mode'=>'apply','ship_meta_records'=>count($ships),
  'taxonomy_term_pairs'=>1+count($features),'draft_cabin_categories'=>5,
  'official_current_ships_taxonomized'=>2,
  'archived_amara_unpublished_amenities'=>true,
  'published_units_created'=>0,'price_or_stock_writes'=>0,'checkout_ready'=>false],
  JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));return;
}
$errors=[];$assigned=[];$drafts=[];
foreach(array_merge(['root'=>['en'=>$rootNames['en'],'es'=>$rootNames['es']]],$features) as $key=>$entry){
 $en=$taxonomyMap[$key]['en'];$es=$taxonomyMap[$key]['es'];
 $tr1=$getTermTranslation((int)$en['term_taxonomy_id']);
 $tr2=$getTermTranslation((int)$es['term_taxonomy_id']);
 if(!$tr1||!$tr2||$tr1['language_code']!=='en'||$tr2['language_code']!=='es'||
 (int)$tr1['trid']!==(int)$tr2['trid'])$errors[]='wpml_amenity_term_'.$key;
 if($key!=='root'&&((int)$en['parent']!==(int)$taxonomyMap['root']['en']['term_id']||
                       (int)$es['parent']!==(int)$taxonomyMap['root']['es']['term_id']))
  $errors[]='term_hierarchy_'.$key;
}
foreach($ships as $id=>$ship){
 $saved=get_post_meta($id,$sourceMeta,true);
 if(!is_array($saved)||($saved['status']??'')!==$ship['status']||
   ($saved['operator']??'')!==$ship['operator']||
   ($saved['source_facts_not_live_inventory']??false)!==true||
   ($saved['available_for_booking']??true)!==false||
   ($saved['cabin_category_facts']??null)!==$ship['cabin_class_evidence'])
  $errors[]='ship_web_evidence_'.$id;
 $assignedIds=$wpdb->get_col($wpdb->prepare(
 "SELECT tt.term_id FROM {$wpdb->term_relationships} r
 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id
 WHERE r.object_id=%d AND tt.taxonomy='amenities' ORDER BY tt.term_id",$id));
 $expected=$ship['taxonomy_assignment_allowed']?
   array_map(static fn($key)=>(int)$taxonomyMap[$key]['en']['term_id'],$ship['amenities']):[];
 $assignedIds=array_map('intval',$assignedIds);sort($assignedIds);sort($expected);
 if($assignedIds!==$expected)$errors[]='ship_amenities_assignment_'.$id;
 $assigned[$id]=$assignedIds;
 $rawUnits=$wpdb->get_col($wpdb->prepare("SELECT child_object_id FROM {$wpdb->prefix}jet_rel_default
 WHERE parent_object_id=%d AND rel_id='223' ORDER BY child_object_id",$id));
 $drafts[$id]=[];
 if(count($rawUnits)!==($ship['draft_unit_creation_allowed']?count($ship['cabin_class_evidence']):0))
  $errors[]='ship_unit_relation_count_'.$id;
 foreach($rawUnits as $uid){
  $uid=(int)$uid;$data=get_post_meta($uid,$draftMeta,true);
  if(get_post_type($uid)!=='units'||get_post_status($uid)!=='draft'||
    get_post_meta($uid,'unit_available',true)!=='false'||
    get_post_meta($uid,'unit_readiness',true)!=='pending_review'||
    !is_array($data)||($data['source_property_id']??null)!==$id||
    ($data['checkout_ready']??true)!==false||
    metadata_exists('post',$uid,'unit_max_occupancy')||
    metadata_exists('post',$uid,'unit_inventory_quantity')||
    metadata_exists('post',$uid,'unit_start_price'))
      $errors[]='draft_not_safe_'.$uid;
  $drafts[$id][]=['id'=>$uid,'key'=>$data['category_key']??''];
 }
}
if(get_option('mad4b_stay_editorial_publish_v1')['catalog_activated']!==false)
 $errors[]='catalog_accidentally_active';
WP_CLI::line(wp_json_encode(['mode'=>'verify','error_count'=>count($errors),
 'errors'=>$errors,'web_ship_evidence_records'=>3,
 'cruise_amenity_term_pairs'=>15,
 'amenity_assignments_en'=>$assigned,'draft_cabin_category_ids'=>$drafts,
 'published_cabin_units_created'=>0,'commercial_ready_cabins'=>0,
 'catalog_activated'=>false,'checkout_ready'=>false],
 JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
if($errors)WP_CLI::error('cruise_amenities_source_verification_failed');
