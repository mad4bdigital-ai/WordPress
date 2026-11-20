<?php
/**
 * MAD4B Wayfatours new-Rate hotel component discovery admission.
 *
 * Repairs only source-backed opt-in and multilingual classifications, not Rates,
 * stay stages, price, inventory, stock, unit readiness, or checkout.
 *
 * MAD4B_HOTEL_COMPONENT_MODE=plan|apply|verify wp eval-file ...
 */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI) return;
global $wpdb;
$mode=getenv('MAD4B_HOTEL_COMPONENT_MODE')?:'plan';
if(!in_array($mode,['plan','apply','verify'],true)) WP_CLI::error('unsupported_mode');
if(rtrim(home_url(),'/')!=='https://wayfatours.com')WP_CLI::error('wrong_site');
if(!function_exists('shared_product_accommodation_assignment')||!function_exists('mad4b_rate_authoring_enabled')||!mad4b_rate_authoring_enabled())WP_CLI::error('native_discovery_provider_unavailable');
$propertyPairs=[
 70732=>71709, 36475=>71710, 36316=>71711, 36197=>71712, 36209=>71713,
 36479=>71714, 36473=>71715, 36474=>71716, 36477=>71717, 36140=>71718, 70733=>71719,
 36355=>71720, 70734=>71721, 70735=>71722, 70736=>71723, 70737=>71724, 70738=>71725, 70739=>71726,
];
$tourPairs=[57562=>57563,60437=>60611,60439=>60609,60441=>60607,60443=>60601,60623=>60661,60627=>60657,60629=>60655];
$expectedTierGroups=[2353=>5,2354=>6,2356=>7];
$metaKey='_mad4b_hotel_package_component_discovery_v1';
$tourKey='_mad4b_package_hotel_candidate_discovery_v1';
$sourceName='Wayfatours self fallback catalogue — 18 hotels';
function mad4b_hotel_disc_raw_terms(int $id,string $tax):array{
 global $wpdb;
 return array_map('intval',$wpdb->get_col($wpdb->prepare(
  "SELECT tt.term_id FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=%d AND tt.taxonomy=%s ORDER BY tt.term_id",$id,$tax)));
}
function mad4b_hotel_disc_add_terms(int $id,string $tax,array $terms):array{
 global $wpdb;
 $new=[];
 foreach($terms as $term){
  $term=(int)$term;
  $tt=(int)$wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s AND term_id=%d",$tax,$term));
  if(!$tt)WP_CLI::error('missing_verified_term_'.$tax.'_'.$term);
  if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id=%d AND term_taxonomy_id=%d",$id,$tt))===1)continue;
  if(!$wpdb->insert($wpdb->term_relationships,['object_id'=>$id,'term_taxonomy_id'=>$tt,'term_order'=>0],['%d','%d','%d']))
   WP_CLI::error('term_relation_insert_failed_'.$id.'_'.$term);
  $new[]=['id'=>$id,'taxonomy'=>$tax,'term_id'=>$term,'term_taxonomy_id'=>$tt];
 }
 return $new;
}
function mad4b_hotel_disc_term_to_es(int $term,string $tax):int{
 global $wpdb;
 $r=$wpdb->get_row($wpdb->prepare(
 "SELECT d.element_id AS en_id,s.element_id AS es_id FROM {$wpdb->prefix}icl_translations d JOIN {$wpdb->prefix}icl_translations s ON s.trid=d.trid AND s.element_type=d.element_type
 WHERE d.element_id=%d AND d.element_type=%s AND d.language_code='en' AND s.language_code='es' LIMIT 1",$term,'tax_'.$tax),ARRAY_A);
 if(!$r||(int)$r['en_id']!==$term)WP_CLI::error('missing_wpml_tax_translation_'.$tax.'_'.$term);
 $es=(int)$r['es_id'];
 if(!(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s AND term_id=%d",$tax,$es)))
  WP_CLI::error('invalid_es_tax_translation_'.$es);
 return $es;
}
function mad4b_hotel_disc_valid_post_pair(int $en,int $es,string $type):bool{
 global $wpdb;
 $a=get_post($en);$b=get_post($es);
 if(!$a||!$b||$a->post_type!==$type||$b->post_type!==$type||$a->post_status!=='publish'||$b->post_status!=='publish')return false;
 $et='post_'.$type;
 $r=$wpdb->get_results($wpdb->prepare("SELECT language_code,trid,element_id FROM {$wpdb->prefix}icl_translations WHERE element_type=%s AND element_id IN (%d,%d)",$et,$en,$es),ARRAY_A);
 return count($r)===2 && (int)$r[0]['trid']===(int)$r[1]['trid'] &&
  in_array(['en',$en],array_map(static fn($x)=>[$x['language_code'],(int)$x['element_id']],$r),true) &&
  in_array(['es',$es],array_map(static fn($x)=>[$x['language_code'],(int)$x['element_id']],$r),true);
}
function mad4b_hotel_disc_candidates(int $tour,int $tier):array{
 $v=shared_product_accommodation_assignment($tour,0,$tier);
 if(is_wp_error($v))return ['error'=>$v->get_error_code()];
 return ['status'=>$v['status'],'groups'=>array_map(static function($g){
  $ids=array_values(array_unique(array_map('intval',$g['property_ids']??[])));
  sort($ids,SORT_NUMERIC);
  return ['destination'=>(int)$g['destination_term_id'],'candidate_property_ids'=>$ids,
   'candidates'=>count($ids),'classification_review_required'=>count(array_filter(
    $g['property_receipts']??[],static fn($x)=>!empty($x['qualification_review_required'])||($x['classification_status']??'')==='unclassified'))];
 },$v['groups']??[]),'source_rate_id'=>(int)($v['source_rate_id']??-1),
 'unit_price_required'=>$v['unit_price_required']??null,'checkout_ready'=>$v['checkout_ready']??null,
 'not_a_stage_plan'=>($v['status']??'')==='needs_stage_plan'];
}
if(count($propertyPairs)!==18||count($tourPairs)!==8)WP_CLI::error('scope_changed');
$editorial=get_option('mad4b_stay_editorial_publish_v1',[]);
$pricing=get_option('mad4b_company_component_pricing_defaults_v1',[]);
$stock=get_option('mad4b_stay_inventory_sales_policy_v1',[]);
if(!is_array($editorial)||($editorial['catalog_activated']??true)!==false||
 (int)(wp_count_posts('property-rates')->publish??0)!==0 ||
 !is_array($pricing)||!empty($pricing['accommodation']['checkout_enabled'])||
 !is_array($stock)||($stock['checkout_ready']??true)!==false)
 WP_CLI::error('commercial_safety_preflight');
if(function_exists('service_channel_allowed')&&!service_channel_allowed('properties','tour_component'))
 WP_CLI::error('tour_component_channel_denied');
$sourceTotals=[];$propertyPlan=[];$tourPlan=[];
foreach($propertyPairs as $en=>$es){
 if(!mad4b_hotel_disc_valid_post_pair($en,$es,'properties'))WP_CLI::error('property_wpml_scope_'.$en);
 if(get_post_field('post_password',$en)!==''||get_post_field('post_password',$es)!=='')WP_CLI::error('password_protected_property_'.$en);
 $tiers=mad4b_hotel_disc_raw_terms($en,'package_levels');
 if(count($tiers)!==1||!isset($expectedTierGroups[$tiers[0]]))WP_CLI::error('invalid_property_package_'.$en);
 $tier=$tiers[0];$sourceTotals[$tier]=($sourceTotals[$tier]??0)+1;
 $esTier=mad4b_hotel_disc_raw_terms($es,'package_levels');
 if($esTier!==[mad4b_hotel_disc_term_to_es($tier,'package_levels')])WP_CLI::error('es_package_mismatch_'.$en);
 $linked=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default r JOIN {$wpdb->posts} p ON p.ID=r.child_object_id WHERE r.rel_id='223' AND r.parent_object_id=%d AND p.post_status='publish' AND p.post_type='units'",$en));
 if(!$linked)WP_CLI::error('published_unit_link_missing_'.$en);
 $expectedTax=[];
 foreach(['destinations','stars','property-types'] as $tax){
  $ids=mad4b_hotel_disc_raw_terms($en,$tax);
  $expectedTax[$tax]=array_values(array_unique(array_map(static fn($t)=>mad4b_hotel_disc_term_to_es($t,$tax),$ids)));
 }
 if(!$expectedTax['destinations']||!$expectedTax['property-types'])WP_CLI::error('source_hotel_classification_missing_'.$en);
 $optEn=(string)get_post_meta($en,'prop_use_in_tour_rate_generation',true);
 $optEs=(string)get_post_meta($es,'prop_use_in_tour_rate_generation',true);
 if(!in_array($optEn,['','0','1'],true)||!in_array($optEs,['','0','1'],true))WP_CLI::error('unexpected_optin_value_'.$en);
 $missingEs=[];$foreignEs=[];$originalEsTax=[];
 foreach($expectedTax as $tax=>$expected){
  $raw=mad4b_hotel_disc_raw_terms($es,$tax);
  $originalEsTax[$tax]=$raw;
  $sourceIds=mad4b_hotel_disc_raw_terms($en,$tax);
  foreach($expected as $t)if(!in_array($t,$raw,true))$missingEs[$tax][]=$t;
  // One legacy WPML source-language link may be removed only if it belongs
  // to this property's own canonical EN terms, not some unrelated taxonomy.
  foreach($raw as $t){
   if(in_array($t,$expected,true))continue;
   if(!in_array($t,$sourceIds,true))WP_CLI::error('unexpected_es_term_'.$es.'_'.$tax.'_'.$t);
   $foreignEs[$tax][]=$t;
  }
 }
 $propertyPlan[$en]=['en'=>$en,'es'=>$es,'tier'=>$tier,'unit_count'=>$linked,'before_en_optin'=>$optEn,'before_es_optin'=>$optEs,
  'expected_es_tax'=>$expectedTax,'missing_es_tax'=>$missingEs,
  'original_es_tax'=>$originalEsTax,'foreign_source_terms_on_es'=>$foreignEs,
  'missing_source_stars'=>count(mad4b_hotel_disc_raw_terms($en,'stars'))!==1,
  'old_receipt_exists'=>metadata_exists('post',$en,$metaKey)||metadata_exists('post',$es,$metaKey)];
}
foreach($expectedTierGroups as $tier=>$n)if(($sourceTotals[$tier]??0)!==$n)WP_CLI::error('property_tier_count_drift_'.$tier);
foreach($tourPairs as $en=>$es){
 if(!mad4b_hotel_disc_valid_post_pair($en,$es,'tours-and-activities'))WP_CLI::error('tour_wpml_scope_'.$en);
 $days=get_post_meta($en,'itinerary_item',true);
 if(!is_array($days)||count($days)<4||count($days)>20)WP_CLI::error('tour_itinerary_unknown_'.$en);
 $txt='';
 foreach($days as $d)$txt.=' '.wp_strip_all_tags(html_entity_decode(($d['title']??'').' '.($d['description']??''),ENT_QUOTES|ENT_HTML5,'UTF-8'));
 if(!preg_match('/Cairo/i',$txt)||!preg_match('/Pyramids|Giza Plateau|Sphinx/i',$txt)||
  !preg_match('/overnight|your hotel|hotel in Cairo|hotel where you will/i',$txt))
  WP_CLI::error('tour_cairo_giza_stay_evidence_missing_'.$en);
 $addEn=array_values(array_diff([123,560],mad4b_hotel_disc_raw_terms($en,'destinations')));
 $addEs=array_values(array_diff([139,648],mad4b_hotel_disc_raw_terms($es,'destinations')));
 $tiers=mad4b_hotel_disc_raw_terms($en,'package_levels');
 if(!array_intersect($tiers,array_keys($expectedTierGroups)))WP_CLI::error('tour_has_no_gold_silver_diamond_'.$en);
 $tourPlan[$en]=['en'=>$en,'es'=>$es,'add_en_cairo_giza'=>$addEn,'add_es_cairo_giza'=>$addEs,
  'existing_tiers'=>$tiers,'old_receipt_exists'=>metadata_exists('post',$en,$tourKey)||metadata_exists('post',$es,$tourKey),
  'source_geometry_kind'=>'editorial_cairo_giza_itinerary_evidence_no_overnights_generated'];
}
if($mode==='plan'){
 WP_CLI::line(wp_json_encode(['mode'=>'plan','site'=>home_url(),'source'=>'fresh_dynamic_optin_no_rate_ids',
  'property_count'=>count($propertyPlan),'package_tour_count'=>count($tourPlan),
  'property_tier_counts'=>$sourceTotals,'properties'=>$propertyPlan,'tours'=>$tourPlan,
  'will_change_rates'=>false,'will_create_stages'=>false,
  'will_activate_prices'=>false,'catalog_activated'=>false],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));return;
}
if($mode==='apply'){
 foreach($propertyPlan as $row)if($row['old_receipt_exists'])WP_CLI::error('property_already_applied_'.$row['en']);
 foreach($tourPlan as $row)if($row['old_receipt_exists'])WP_CLI::error('tour_already_applied_'.$row['en']);
 $timestamp=gmdate('c');$newTerms=[];$removedTerms=[];$optinChanges=[];
 foreach($propertyPlan as $en=>$row){
  foreach([$en,$row['es']] as $id){
   $before=(string)get_post_meta($id,'prop_use_in_tour_rate_generation',true);
   if($before!=='1'){
    update_post_meta($id,'prop_use_in_tour_rate_generation','1');
    $optinChanges[]=['id'=>$id,'from'=>$before,'to'=>'1'];
   }
  }
  foreach($row['foreign_source_terms_on_es'] as $tax=>$sourceTerms)foreach($sourceTerms as $t){
   $tt=(int)$wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s AND term_id=%d",$tax,$t));
   if(!$tt||(int)$wpdb->delete($wpdb->term_relationships,['object_id'=>$row['es'],'term_taxonomy_id'=>$tt],['%d','%d'])!==1)
    WP_CLI::error('foreign_language_term_removal_failed_'.$row['es'].'_'.$t);
   $removedTerms[]=['id'=>$row['es'],'taxonomy'=>$tax,'term_id'=>$t,'term_taxonomy_id'=>$tt];
  }
  foreach($row['missing_es_tax'] as $tax=>$terms)
   $newTerms=array_merge($newTerms,mad4b_hotel_disc_add_terms($row['es'],$tax,$terms));
  $receipt=['schema'=>'mad4b.stay-component-discovery.v1','property_id'=>$en,
    'es_property_id'=>$row['es'],'eligible_for'=>'new_tour_rate_auto_discovery',
    'source'=>'native_shared_product_accommodation_assignment','price_required'=>false,
    'rate_id_source'=>'none','supplier_routing'=>['external_contract','self_fallback'],
    'pricing_source_confirmed'=>false,'supplier_rates_created'=>false,'inventory_confirmed'=>false,
    'checkout_ready'=>false,'editorial_only'=>true,'catalog_activated'=>false,
    'original_opt_in'=>[$en=>$row['before_en_optin'],$row['es']=>$row['before_es_optin']],
    'original_es_terms'=>$row['original_es_tax'],
    'source_tier'=>$row['tier'],'unit_count'=>$row['unit_count'],'missing_star_review'=>$row['missing_source_stars'],
    'source_field_state'=>$row['expected_es_tax'],'applied_at'=>$timestamp];
  // Only the exact opt-in and translated native taxonomy assignments drive discovery.
  foreach([$en,$row['es']] as $id) update_post_meta($id,$metaKey,$receipt);
 }
 foreach($tourPlan as $en=>$row){
  $newTerms=array_merge($newTerms,mad4b_hotel_disc_add_terms($en,'destinations',$row['add_en_cairo_giza']));
  $newTerms=array_merge($newTerms,mad4b_hotel_disc_add_terms($row['es'],'destinations',$row['add_es_cairo_giza']));
  $receipt=['schema'=>'mad4b.package-hotel-discovery.v1','source_itinerary_tour_id'=>$en,
   'localized_tour_id'=>$row['es'],'fresh_candidates_only'=>true,
   'old_manual_rates_used'=>false,'source_rate_id'=>0,
   'source'=> 'original_published_tour_itinerary','city_scope'=>['Cairo','Giza'],
   'source_classifications'=>['en'=>[123,560],'es'=>[139,648]],
   'source_rate_prices_inherited'=>false,'nights_inferred'=>false,'stages_created'=>false,
   'checkout_ready'=>false,'published_rates_created'=>false,
   'added_en_destinations'=>$row['add_en_cairo_giza'],'added_es_destinations'=>$row['add_es_cairo_giza'],
   'approved_as_discovery_only'=>true,'applied_at'=>$timestamp];
  update_post_meta($en,$tourKey,$receipt);update_post_meta($row['es'],$tourKey,$receipt);
 }
 $recount=[];
 foreach(array_merge($newTerms,$removedTerms) as $changed)$recount[$changed['taxonomy']][(int)$changed['term_taxonomy_id']]=(int)$changed['term_taxonomy_id'];
 foreach($recount as $taxonomy=>$ttIds)wp_update_term_count_now(array_values($ttIds),$taxonomy);
 foreach($propertyPlan as $en=>$row){
  clean_object_term_cache((int)$en,'properties');clean_object_term_cache((int)$row['es'],'properties');
  wp_cache_delete((int)$en,'post_meta');wp_cache_delete((int)$row['es'],'post_meta');
 }
 foreach($tourPlan as $en=>$row){
  clean_object_term_cache((int)$en,'tours-and-activities');clean_object_term_cache((int)$row['es'],'tours-and-activities');
 }
 WP_CLI::line(wp_json_encode(['mode'=>'apply','new_term_relations'=>$newTerms,'removed_wrong_language_terms'=>$removedTerms,'optin_changes'=>$optinChanges,
  'property_count'=>count($propertyPlan),'tour_count'=>count($tourPlan),'rate_mutations'=>0,
  'commercial_ready'=>false,'catalog_activated'=>false],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 return;
}
$issues=[];$observed=[];$tourResults=[];$cohortIds=array_map('intval',array_keys($propertyPairs));
foreach($propertyPlan as $en=>$row){
 if(get_post_meta($en,'prop_use_in_tour_rate_generation',true)!=='1'||
    get_post_meta($row['es'],'prop_use_in_tour_rate_generation',true)!=='1')$issues[]='optin_'.$en;
 foreach($row['expected_es_tax'] as $tax=>$terms){
  $actual=mad4b_hotel_disc_raw_terms($row['es'],$tax);sort($actual,SORT_NUMERIC);sort($terms,SORT_NUMERIC);
  if($actual!==$terms)$issues[]='es_taxonomy_'.$en.'_'.$tax;
 }
 foreach([$en,$row['es']] as $id){
  $receipt=get_post_meta($id,$metaKey,true);
  if(!is_array($receipt)||($receipt['schema']??'')!=='mad4b.stay-component-discovery.v1'||
   ($receipt['checkout_ready']??true)!==false||($receipt['rate_id_source']??'not_zero')!=='none')
   $issues[]='receipt_'.$id;
 }
 $observed[]=['property'=>$en,'es'=>$row['es'],'tier'=>$row['tier'],
  'unit_count'=>$row['unit_count'],'has_source_stars'=>!$row['missing_source_stars']];
}
foreach($tourPlan as $en=>$row){
 foreach([$en,$row['es']] as $id){
  $terms=mad4b_hotel_disc_raw_terms($id,'destinations');
  $must=$id===$en?[123,560]:[139,648];
  if(array_diff($must,$terms))$issues[]='tour_missing_cairo_giza_'.$id;
  $receipt=get_post_meta($id,$tourKey,true);
  if(!is_array($receipt)||($receipt['fresh_candidates_only']??false)!==true||
    ($receipt['old_manual_rates_used']??true)!==false)$issues[]='tour_receipt_'.$id;
 }
 $matrix=[];
 foreach([$en,$row['es']] as $tour){
  $matrix[$tour]=[];
  foreach($expectedTierGroups as $tier=>$expectedN){
   $localizedTier=$tour===$en?$tier:mad4b_hotel_disc_term_to_es($tier,'package_levels');
   $r=mad4b_hotel_disc_candidates($tour,$localizedTier);
   $ids=[];
   foreach($r['groups']??[] as $g)if(in_array((int)$g['destination'],[123,560],true))$ids=array_merge($ids,$g['candidate_property_ids']);
   $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);
   $expectedIds=$tour===$en?array_keys(array_filter($propertyPlan,static fn($x)=>$x['tier']===$tier)):
    array_values(array_map(static fn($v)=>$v['es'],array_filter($propertyPlan,static fn($x)=>$x['tier']===$tier)));
   $expectedIds=array_map('intval',$expectedIds);sort($expectedIds,SORT_NUMERIC);
   if($ids!==$expectedIds)$issues[]='tier_discovery_mismatch_'.$tour.'_'.$tier;
   if(($r['source_rate_id']??-1)!==0||($r['unit_price_required']??true)!==false||
      ($r['checkout_ready']??true)!==false)$issues[]='pricing_guard_'.$tour.'_'.$tier;
   $matrix[$tour][(string)$tier]=['status'=>$r['status']??null,'candidates'=>count($ids),
     'property_ids'=>$ids,'expected_count'=>$expectedN,'source_rate_id'=>$r['source_rate_id']??null,
     'unit_price_required'=>$r['unit_price_required']??null,'checkout_ready'=>$r['checkout_ready']??null];
  }
 }
 $tourResults[]=['en_tour'=>$en,'es_tour'=>$row['es'],'tiers'=>$matrix];
}
if((int)(wp_count_posts('property-rates')->publish??0)!==0||
 (int)(wp_count_posts('tour-rates')->publish??0)!==0||
 get_option('mad4b_stay_editorial_publish_v1')['catalog_activated']!==false)$issues[]='commercial_guard_changed';
WP_CLI::line(wp_json_encode(['mode'=>'verify','errors'=>$issues,'error_count'=>count($issues),
 'eligible_hotels'=>count($observed),'hotel_tier_counts'=>$sourceTotals,
 'package_tour_pairs'=>count($tourPlan),'total_tier_cases'=>count($tourResults)*2*count($expectedTierGroups),
 'properties'=>$observed,'tour_results'=>$tourResults,
 'tour_rates_published'=>(int)(wp_count_posts('tour-rates')->publish??0),
 'property_rates_published'=>(int)(wp_count_posts('property-rates')->publish??0),
 'catalog_activated'=>false],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
if($issues)WP_CLI::error('fresh_hotel_discovery_verification_failed');
