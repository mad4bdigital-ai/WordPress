<?php
/**
 * Five source-confirmed boats: official/operator indexed amenity and category intake.
 * Only private source meta, native Amenities terms, and never-bookable Draft Units.
 * MAD4B_CRUISE_WEB_BATCH2_MODE=plan|apply|verify
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)return;
global $wpdb;
$mode=getenv('MAD4B_CRUISE_WEB_BATCH2_MODE')?:'plan';
if(!in_array($mode,['plan','apply','verify'],true))WP_CLI::error('invalid_mode');
if(rtrim(home_url(),'/')!=='https://wayfatours.com'||
 !taxonomy_exists('amenities')||!post_type_exists('units')||!has_action('wpml_set_element_language_details'))
 WP_CLI::error('wrong_site_wpml_or_jetengine');
$evidenceMeta='_mad4b_cruise_public_web_amenities_evidence_v1';
$unitMeta='_mad4b_cruise_category_source_draft_v1';
$sourceKey='mad4b.cruise-operator-web-intake.v2';
$ships=[
 35957=>[
  'title'=>'Sonesta Dahabiya Amirat','official_name'=>'Sonesta Dahabeya Amirat',
  'operator'=>'Sonesta Nile River Cruises',
  'url'=>'https://www.sonesta.com/nile-river-cruises/sonesta-dahabeya-amirat',
  'secondary'=>'https://www.sonesta.com/sonesta-dahabeya-amirat/guest-cabins-and-suites',
  'robots_direct_scrape_allowed'=>false,
  'amenities'=>['sun_deck','restaurant','lounge_bar','wifi','air_con','cabin_safe','jacuzzi','minibar'],
  'categories'=>[
    ['deluxe_cabin','Deluxe Cabin','cabin',5],
    ['elegant_suite','Elegant Suite','suite',2]
  ],
  'warnings'=>['Sonesta Dahabeya Amirat and Amirat II are different vessels with different cabin totals.']
 ],
 35969=>[
  'title'=>'M/S Sonesta Star Goddess','official_name'=>'Sonesta Star Goddess Nile Cruise',
  'operator'=>'Sonesta Nile River Cruises',
  'url'=>'https://www.sonesta.com/nile-river-cruises/sonesta-star-goddess-nile-cruise-ship',
  'secondary'=>'https://www.sonesta.com/sonesta-star-goddess-nile-cruise-ship/guest-cabins-and-suites',
  'robots_direct_scrape_allowed'=>false,
  'amenities'=>['sun_deck','restaurant','lounge_bar','wifi','air_con','cabin_safe','spa','gym','minibar'],
  'categories'=>[
    ['beethoven_suite','Beethoven Suite','suite',27],
    ['mozart_suite','Mozart Suite','suite',4],
    ['chopin_suite','Chopin Suite','suite',2]
  ],
  'warnings'=>['Official reported suite sizes 40/35/52 sqm respectively; no maximum travelers or booking allotment established.']
 ],
 35971=>[
  'title'=>'M/S Sonesta Moon Goddess','official_name'=>'Sonesta Moon Goddess Nile Cruise',
  'operator'=>'Sonesta Nile River Cruises',
  'url'=>'https://www.sonesta.com/nile-river-cruises/sonesta-moon-goddess-nile-cruise-ship',
  'secondary'=>'https://www.sonesta.com/sonesta-moon-goddess-nile-cruise-ship/guest-cabins-and-suites',
  'robots_direct_scrape_allowed'=>false,
  'amenities'=>['sun_deck','restaurant','lounge_bar','wifi','air_con','cabin_safe','spa','gym','jacuzzi','minibar','swim_pool','massage'],
  'categories'=>[
    ['deluxe_cabin','Deluxe Cabin','cabin',49],
    ['presidential_suite','Presidential Suite','suite',4]
  ],
  'warnings'=>['49 regular cabins and 4 suites; no cabin number or specific sailing allotment verified.']
 ],
 35972=>[
  'title'=>'M/S Sonesta St. George Nile Cruise','official_name'=>'Sonesta St. George 1 Nile Cruise',
  'operator'=>'Sonesta Nile River Cruises',
  'url'=>'https://www.sonesta.com/nile-river-cruises/sonesta-st-george-1-nile-cruise-ship',
  'secondary'=>'https://www.sonesta.com/sonesta-st-george-1-nile-cruise-ship',
  'robots_direct_scrape_allowed'=>false,
  'amenities'=>['sun_deck','restaurant','lounge_bar','wifi','air_con','cabin_safe','spa','gym','jacuzzi','minibar','swim_pool','massage'],
  'categories'=>[
    ['deluxe_cabin','Deluxe Cabin','cabin',47],
    ['presidential_suite','Presidential Suite','suite',6],
    ['elite_suite','Elite Suite','suite',3],
    ['royal_suite','Royal Suite','suite',1]
  ],
  'warnings'=>['The name St. George I identifies the Sonesta cruise vessel, not Sonesta St George Hotel in Luxor.']
 ],
 35927=>[
  'title'=>'MS Concerto II Nile Cruise','official_name'=>'M/S Concerto II',
  'operator'=>'Extension Group',
  'url'=>'https://extensiongroup.com.eg/concerto-ii',
  'secondary'=>'https://extensiongroup.com.eg/',
  'robots_direct_scrape_allowed'=>true,
  'amenities'=>['sun_deck','restaurant','lounge_bar','air_con','cabin_safe','gym','swim_pool','massage','minibar'],
  'categories'=>[
    ['double_cabin','Double Cabin','cabin',61],
    ['single_cabin','Single Cabin','cabin',2],
    ['presidential_suite','Presidential Suite','suite',2],
    ['junior_suite','Junior Suite','suite',2]
  ],
  'warnings'=>['Official site technical specs cite 67 total cabins including suites. Concerto I, Concerto II, Concerto III and Concerto Plus must never be conflated.','Site says Internet, but Wi-Fi capability not confirmed; excluded Wi-Fi amenity.']
 ],
];
$termSlugs=[
 'sun_deck'=>'mad4b-cruise-sun_deck',
 'restaurant'=>'mad4b-cruise-restaurant',
 'lounge_bar'=>'mad4b-cruise-lounge_bar',
 'wifi'=>'mad4b-cruise-wifi',
 'air_con'=>'mad4b-cruise-air_con',
 'cabin_safe'=>'mad4b-cruise-cabin_safe',
 'plunge_pool'=>'mad4b-cruise-plunge_pool',
 'spa'=>'mad4b-cruise-spa',
 'gym'=>'mad4b-cruise-gym',
 'jacuzzi'=>'mad4b-cruise-jacuzzi',
 'minibar'=>'mad4b-cruise-minibar',
 'swim_pool'=>'mad4b-cruise-swim_pool',
 'massage'=>'mad4b-cruise-massage',
 'elevator'=>'mad4b-cruise-elevator',
];
if(count($ships)!==5||count($termSlugs)!==14)WP_CLI::error('batch_identity_configuration');
if(get_option('mad4b_stay_editorial_publish_v1')['catalog_activated']!==false||
 (int)(wp_count_posts('tour-rates')->publish??0)!==0||
 (int)(wp_count_posts('property-rates')->publish??0)!==0||
 (int)(wp_count_posts('transport-rates')->publish??0)!==0||
 get_option('commerce_management_company_definitions_v1',null)!==null||
 get_option('commerce_company_policy_registry_v1',null)!==null)
 WP_CLI::error('commercial_or_policy_guard_changed');
$termByRawSlug=static function(string $slug)use($wpdb):?array{
 $row=$wpdb->get_row($wpdb->prepare("SELECT t.term_id,t.name,t.slug,tt.term_taxonomy_id,tt.parent,
 i.trid,i.language_code FROM {$wpdb->terms} t
 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id AND tt.taxonomy='amenities'
 LEFT JOIN {$wpdb->prefix}icl_translations i ON i.element_type='tax_amenities' AND i.element_id=tt.term_taxonomy_id
 WHERE t.slug=%s",$slug),ARRAY_A);
 return $row?:null;
};
$rootEn=$termByRawSlug('mad4b-cruise-amenities');
$rootEs=$termByRawSlug('mad4b-cruise-amenities-es');
if(!$rootEn||!$rootEs||$rootEn['language_code']!=='en'||$rootEs['language_code']!=='es'||
 (int)$rootEn['trid']!==(int)$rootEs['trid'])
 WP_CLI::error('cruise_amenities_root_missing');
$refs=[];
foreach($termSlugs as $key=>$slug){
 $t=$termByRawSlug($slug);
 $es=$termByRawSlug($slug.'-es');
 if(!$t||!$es||(int)$t['parent']!==(int)$rootEn['term_id']||
  (int)$es['parent']!==(int)$rootEs['term_id'])WP_CLI::error('existing_amenity_translation_drift_'.$key);
 if($t['language_code']!=='en'||$es['language_code']!=='es'||
 (int)$t['trid']!==(int)$es['trid'])WP_CLI::error('cruise_taxonomy_wpml_pair_drift_'.$key);
 $refs[$key]=(int)$t['term_id'];
}
$totalCategories=0;$totalTerms=0;
foreach($ships as $id=>$ship){
 $p=get_post($id);
 if(!$p||$p->post_type!=='properties'||$p->post_status!=='publish'||$p->post_title!==$ship['title'])
 WP_CLI::error('exact_cruise_property_identity_changed_'.$id);
 $lang=$wpdb->get_var($wpdb->prepare("SELECT language_code FROM {$wpdb->prefix}icl_translations WHERE element_type='post_properties' AND element_id=%d",$id));
 if($lang!=='en')WP_CLI::error('unexpected_ship_language_'.$id);
 $cruiseType=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id AND tt.taxonomy='property-types' AND tt.term_id=270 WHERE r.object_id=%d",$id));
 if($cruiseType!==1)WP_CLI::error('not_cruise_type_'.$id);
 $old=$wpdb->get_results($wpdb->prepare("SELECT tt.term_id FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id AND tt.taxonomy='amenities' WHERE r.object_id=%d",$id),ARRAY_A);
 $links=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id='223' AND parent_object_id=%d",$id));
 if($mode!=='verify'&&(count($old)!==0||$links!==0||metadata_exists('post',$id,$evidenceMeta)))
  WP_CLI::error('already_populated_ship_guard_'.$id);
 $expect=[];
 foreach($ship['amenities'] as $code){
  if(!isset($refs[$code]))WP_CLI::error('unknown_term_'.$code);
  $expect[]=$refs[$code];
 }
 $totalTerms+=count($expect);
 foreach($ship['categories'] as $c){
  if(!in_array($c[2],['cabin','suite'],true)||$c[3]<1)WP_CLI::error('invalid_cabin_description_'.$id);
  $slug='mad4b-official-batch2-'.$id.'-'.$c[0];
  $existing=$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='units' AND post_name=%s",$slug));
  if($mode==='verify'&&!$existing)WP_CLI::error('draft_missing_'.$slug);
  if($mode!=='verify'&&$existing)WP_CLI::error('already_existing_cabin_slug_'.$slug);
  $totalCategories++;
 }
}
if($totalCategories!==15||$totalTerms!==50)WP_CLI::error('expected_batch_cardinality_changed');
if($mode==='plan'){
 WP_CLI::line(wp_json_encode(['mode'=>$mode,'source_properties'=>array_keys($ships),
 'official_source_evidence_records'=>count($ships),'new_draft_categories'=>$totalCategories,
 'existing_wpml_amenity_terms_reused'=>count($refs),'ship_amenity_assignments'=>$totalTerms,
 'published_new_units'=>0,'commercial_rates_created'=>0,'catalog_activated'=>false],
 JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));return;
}
if($mode==='apply'){
 if($wpdb->query('START TRANSACTION')===false)WP_CLI::error('transaction_not_started');
 try{
  do_action('wpml_switch_language','en');
  foreach($ships as $id=>$ship){
   $receipt=[
    'schema'=>$sourceKey,
    'ship_property_id'=>$id,
    'official_ship_name'=>$ship['official_name'],
    'operator'=>$ship['operator'],
    'operator_sources'=>[
      ['url'=>$ship['url'],'role'=>'primary_official_operator_ship_page','direct_robot_crawl_allowed'=>$ship['robots_direct_scrape_allowed']],
      ['url'=>$ship['secondary'],'role'=>'secondary_official_operator_page','direct_robot_crawl_allowed'=>$ship['robots_direct_scrape_allowed']]
    ],
    'reviewed_on'=>gmdate('Y-m-d'),
    'source_review_type'=>'official_page_index_or_permitted_direct_html_no_image_download',
    'taxonomized_cruise_amenities'=>$ship['amenities'],
    'source_cabin_categories'=>array_map(static fn($c)=>[
     'key'=>$c[0],'category'=>$c[1],'unit_kind'=>$c[2],
     'reported_ship_category_count'=>$c[3],'count_is_not_inventory'=>true
    ],$ship['categories']),
    'source_warnings'=>$ship['warnings'],
    'actual_sailing_departure_verified'=>false,'operator_approved_package_excursions'=>false,
    'supplier_contract_confirmed'=>false,
    'supplier_approved_room_capacity'=>false,'photos_copied'=>false,
    'available_for_booking'=>false,'catalog_activated'=>false,
    'recorded_at'=>gmdate('c')
   ];
   if(!add_post_meta($id,$evidenceMeta,$receipt,true))throw new RuntimeException('source_metadata_'.$id);
   $termIds=array_map(static fn($code)=>$refs[$code],$ship['amenities']);
   $written=wp_set_object_terms($id,$termIds,'amenities',false);
   if(is_wp_error($written)||count($written)!==count($termIds))
     throw new RuntimeException('amenity_terms_'.$id);
   foreach($ship['categories'] as $c){
    [$key,$label,$kind,$reported]=$c;
    $slug='mad4b-official-batch2-'.$id.'-'.$key;
    $pid=wp_insert_post([
      'post_type'=>'units','post_status'=>'draft','post_author'=>0,
      'post_title'=>$ship['official_name'].' — '.$label,
      'post_name'=>$slug,
      'post_content'=>'Operator-public cabin category for editorial sourcing only. Requires signed supplier tariff, verified capacity, dates and allotment before booking.'
    ],true);
    if(is_wp_error($pid)||!(int)$pid)throw new RuntimeException('draft_unit_insert_'.$slug);
    do_action('wpml_set_element_language_details',[
      'element_id'=>(int)$pid,'element_type'=>'post_units','trid'=>false,
      'language_code'=>'en','source_language_code'=>null
    ]);
    if(!update_post_meta($pid,'unit_kind',$kind)||
       !update_post_meta($pid,'unit_readiness','pending_review')||
       !update_post_meta($pid,'unit_available','false'))
      throw new RuntimeException('draft_unit_profile_'.$pid);
    $cabinReceipt=[
      'schema'=>'mad4b.cruise-cabin-web-category.v2',
      'source_property_id'=>$id,'category_key'=>$key,
      'reported_cabin_count'=>$reported,'reported_count_not_sellable_inventory'=>true,
      'unit_kind'=>$kind,'official_source_url'=>$ship['url'],
      'supplier_verified_max_occupancy'=>null,'inventory_quantity'=>null,
      'sailing_allotments'=>null,'sailing_date'=>null,
      'price_amount'=>null,'supplier_contract_confirmed'=>false,
      'unit_available'=>false,'checkout_ready'=>false,
      'status'=>'draft_operator_source_not_supplier_admitted'
    ];
    if(!add_post_meta($pid,$unitMeta,$cabinReceipt,true))throw new RuntimeException('draft_cabin_receipt_'.$pid);
    $n=$wpdb->insert($wpdb->prefix.'jet_rel_default',[
      'rel_id'=>'223','parent_rel'=>0,'parent_object_id'=>$id,'child_object_id'=>$pid
    ],['%s','%d','%d','%d']);
    if($n!==1)throw new RuntimeException('jetengine_link_failed_'.$pid);
   }
  }
  if($wpdb->query('COMMIT')===false)throw new RuntimeException('transaction_commit_error');
 }catch(Throwable $e){
  $wpdb->query('ROLLBACK');
  WP_CLI::error('all_changes_rolled_back_'.$e->getMessage());
 }
 do_action('wpml_switch_language','en');
 foreach($ships as $id=>$ship){
  wp_cache_delete($id,'post_meta');
  clean_object_term_cache($id,'properties');
 }
 WP_CLI::line(wp_json_encode(['mode'=>'apply','ships'=>count($ships),
  'draft_units_created'=>$totalCategories,'amenity_assignments'=>$totalTerms,
  'new_taxonomy_terms'=>0,'commercial_rate_writes'=>0,
  'published_new_units'=>0,'catalog_activated'=>false],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 return;
}
$errors=[];$new=[];$termCounts=[];
foreach($ships as $id=>$ship){
 $receipt=get_post_meta($id,$evidenceMeta,true);
 if(!is_array($receipt)||($receipt['schema']??'')!==$sourceKey||
   ($receipt['source_cabin_categories']??null)!==array_map(static fn($c)=>[
    'key'=>$c[0],'category'=>$c[1],'unit_kind'=>$c[2],
    'reported_ship_category_count'=>$c[3],'count_is_not_inventory'=>true
   ],$ship['categories']) ||
   ($receipt['available_for_booking']??true)!==false)
   $errors[]='source_receipt_'.$id;
 $assigned=array_map('intval',$wpdb->get_col($wpdb->prepare(
 "SELECT tt.term_id FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id AND tt.taxonomy='amenities' WHERE r.object_id=%d ORDER BY tt.term_id",$id)));
 $expected=array_map(static fn($name)=>$refs[$name],$ship['amenities']);
 sort($assigned);sort($expected);
 if($assigned!==$expected)$errors[]='amenities_assignment_'.$id;
 $termCounts[$id]=count($assigned);
 $units=$wpdb->get_col($wpdb->prepare("SELECT child_object_id FROM {$wpdb->prefix}jet_rel_default
 WHERE rel_id='223' AND parent_object_id=%d ORDER BY child_object_id",$id));
 if(count($units)!==count($ship['categories']))$errors[]='unit_relation_count_'.$id;
 $new[$id]=[];
 foreach($units as $pid){
  $pid=(int)$pid;$c=get_post_meta($pid,$unitMeta,true);
  $lang=$wpdb->get_var($wpdb->prepare("SELECT language_code FROM {$wpdb->prefix}icl_translations
  WHERE element_type='post_units' AND element_id=%d",$pid));
  if(get_post_status($pid)!=='draft'||$lang!=='en'||
   get_post_meta($pid,'unit_readiness',true)!=='pending_review'||
   get_post_meta($pid,'unit_available',true)!=='false'||
   !is_array($c)||($c['source_property_id']??null)!==$id||
   ($c['checkout_ready']??true)!==false||
   metadata_exists('post',$pid,'unit_max_occupancy')||
   metadata_exists('post',$pid,'unit_inventory_quantity')||
   metadata_exists('post',$pid,'unit_start_price'))
   $errors[]='draft_not_safe_'.$pid;
  $new[$id][]=['id'=>$pid,'category'=>$c['category_key']??null];
 }
}
WP_CLI::line(wp_json_encode(['mode'=>'verify','error_count'=>count($errors),
 'errors'=>$errors,'new_draft_cabin_units'=>$new,
 'amenity_assignment_count'=>$termCounts,
 'new_amenity_terms_created'=>0,'published_new_units'=>0,
 'commercial_ready_units'=>0,'catalog_activated'=>false],
 JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
if($errors)WP_CLI::error('verification_failed');
