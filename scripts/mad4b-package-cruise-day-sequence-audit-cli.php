<?php
/**
 * Editorial-only WPML cruise day-by-day bank order compatibility audit.
 * Matches generic native program day order against each actual package itinerary;
 * full supplier itinerary and vessel/cabin readiness are NOT certified.
 * MAD4B_CRUISE_DAY_PARITY_MODE=plan|apply|verify
 */
defined('ABSPATH')||exit;
if(!defined('WP_CLI')||!WP_CLI)return;
global $wpdb;
$mode=getenv('MAD4B_CRUISE_DAY_PARITY_MODE')?:'plan';
if(!in_array($mode,['plan','apply','verify'],true))WP_CLI::error('mode_unrecognized');
if(rtrim(home_url(),'/')!=='https://wayfatours.com')WP_CLI::error('site_origin_incorrect');
$pairs=[57562=>57563,60437=>60611,60439=>60609,60441=>60607,60443=>60601,60623=>60661,60629=>60655];
$meta='_mad4b_cruise_program_day_sequence_source_audit_v1';
$bindings='_mad4b_package_stay_verified_room_program_links_v1';
$source='_mad4b_package_accommodation_source_plan_v1';
$termPair=[2348=>2445,2349=>2449];
if(count($pairs)!==7||
 get_option('mad4b_stay_editorial_publish_v1')['catalog_activated']!==false||
 (int)(wp_count_posts('tour-rates')->publish??0)!==0||
 (int)(wp_count_posts('property-rates')->publish??0)!==0||
 (int)(wp_count_posts('transport-rates')->publish??0)!==0)
 WP_CLI::error('commercial_scope_changed');
if(!class_exists('MAD4B_Cruise_Program_Context_V1')||
 !class_exists('MAD4B_Cruise_Program_Composer_V1'))
 WP_CLI::error('program_resolver_unavailable');
$programProof=[];
foreach($termPair as $term=>$spanish){
 $p=MAD4B_Cruise_Program_Context_V1::program($term);
 $days=MAD4B_Cruise_Program_Composer_V1::day_rows($term);
 $count=$term===2348?4:5;
 if(!$p||$p['itinerary_status']!=='day_by_day'||(int)$p['day_count']!==$count||
 count($days['days'])!==$count||$days['warnings']!==[]||
 (int)apply_filters('wpml_object_id',$term,'programs',false,'es')!==$spanish)
  WP_CLI::error('native_program_term_changed_'.$term);
 $check=$term===2348?[2,3]:[0,1];
 if(!str_contains($days['days'][$check[0]]['title'],'East Bank')||
 !str_contains($days['days'][$check[1]]['title'],'West Bank'))
  WP_CLI::error('native_program_bank_day_changed_'.$term);
 $programProof[$term]=[
  'en_term_id'=>$term,'es_term_id'=>$spanish,
  'program_day_count'=>$count,
  'native_program_term_meta_sha256'=>hash('sha256',wp_json_encode(get_term_meta($term),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
  'expected_primary_bank_days'=>['east'=>$check[0]+1,'west'=>$check[1]+1]
 ];
}
function mad4b_cruise_bank_flags(array $row):array{
 $raw=(string)($row['title']??'').' '.(string)($row['description']??'');
 $normalized=mb_strtolower(remove_accents(wp_strip_all_tags(html_entity_decode($raw,ENT_QUOTES|ENT_HTML5,'UTF-8'))),'UTF-8');
 $keywords=[
 'east'=>['east bank','eastern bank','temple of karnak','karnak temple','karnak','luxor temple','templo de luxor','orilla este','ribera oriental','orilla oriental','luxor este'],
 'west'=>['west bank','western bank','valley of the kings','hatshepsut','colossi of memnon','orilla oeste','ribera occidental','cisjordania','valle de los reyes','colosos de memnon','templo de hatshepsut']
 ];
 $out=[];
 foreach($keywords as $bank=>$terms){
  $hits=[];foreach($terms as $keyword)if(mb_strpos($normalized,$keyword)!==false)$hits[]=$keyword;
  $out[$bank]=array_values(array_unique($hits));
 }
 return $out;
}
$countPerStatus=[];$rows=[];$counts=[];
foreach($pairs as $en=>$es){
 $pairStatus=null;$lastEn=null;
 foreach(['en'=>$en,'es'=>$es] as $lang=>$id){
  $post=get_post($id);
  $translation=$wpdb->get_row($wpdb->prepare("SELECT language_code,trid FROM {$wpdb->prefix}icl_translations WHERE element_type='post_tours-and-activities' AND element_id=%d",$id),ARRAY_A);
  if(!$post||$post->post_type!=='tours-and-activities'||$post->post_status!=='publish'||
   !$translation||$translation['language_code']!==$lang)
    WP_CLI::error('tour_site_identity_changed_'.$id);
  $plan=get_post_meta($id,$source,true);
  $receipt=get_post_meta($id,$bindings,true);
  $itinerary=get_post_meta($id,'itinerary_item',true);
  if(!is_array($plan)||!is_array($receipt)||!is_array($itinerary)||
    ($plan['source_review_status']??'')!=='draft_geometry_verified'||
    ($plan['localized_tour_id']??null)!==$id||
    ($receipt['schema']??null)!=='mad4b.package-stay-unit-program-bindings.v1'||
    ($receipt['source_plan_sha256']??null)!==hash('sha256',wp_json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))||
    count($itinerary)!==(int)$plan['tour_days']||
    ($receipt['checkout_ready']??true)!==false)
   WP_CLI::error('source_plan_or_bindings_drift_'.$id);
  $cruiseRows=[];
  foreach($receipt['bindings'] as $binding)if(($binding['lodging_kind']??'')==='cruise')$cruiseRows[]=$binding;
  if(count($cruiseRows)!==1)WP_CLI::error('expected_single_cruise_stage_'.$id);
  $b=$cruiseRows[0];
  $n=(int)$b['nights'];$program=$n===3?2348:($n===4?2349:0);
  if(!$program||(int)($b['cruise_program']['program_term_id_en']??0)!==$program||
    (int)($b['cruise_program']['program_term_id_local']??0)!==($lang==='en'?$program:$termPair[$program])||
    ($b['cruise_program']['explicit_supplier_sailing_confirmed']??true)!==false)
   WP_CLI::error('native_program_binding_wrong_'.$id);
  $firstDay=(int)$b['source_day_start'];
  $eastOffset=$program===2348?2:0;
  $westOffset=$program===2348?3:1;
  $eastDay=$firstDay+$eastOffset;
  $westDay=$firstDay+$westOffset;
  if($eastDay<=0||$westDay>count($itinerary))WP_CLI::error('day_position_invalid_'.$id);
  $current=array_values($itinerary);
  $eastFlags=mad4b_cruise_bank_flags($current[$eastDay-1]);
  $westFlags=mad4b_cruise_bank_flags($current[$westDay-1]);
  $eastCorrect=($eastFlags['east']!==[]&&$eastFlags['west']===[]);
  $westCorrect=($westFlags['west']!==[]&&$westFlags['east']===[]);
  $matches=$eastCorrect&&$westCorrect;
  $status=$matches?'primary_bank_sequence_matches_requires_operator_confirmation':
     'primary_bank_sequence_differs_operator_review_required';
  if($lang==='en'){$pairStatus=$status;$lastEn=(int)$translation['trid'];}
  elseif($pairStatus!==$status||(int)$translation['trid']!==$lastEn)
   WP_CLI::error('wpml_program_bank_review_disagrees_'.$en);
  $sourceHashes=[
   'tour_itinerary_sha256'=>hash('sha256',wp_json_encode($itinerary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
   'source_plan_sha256'=>hash('sha256',wp_json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
   'unit_program_binding_sha256'=>hash('sha256',wp_json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
   'native_program_meta_sha256'=>$programProof[$program]['native_program_term_meta_sha256']
  ];
  $record=[
   'schema'=>'mad4b.cruise-program-day-sequence-source-review.v1',
   'tour_id'=>$id,'en_tour_id'=>$en,'language'=>$lang,
   'wpml_trid'=>(int)$translation['trid'],
   'tour_cruise_stage_key'=>$b['stage_key'],
   'program_term_id_en'=>$program,'program_term_id_local'=>$lang==='en'?$program:$termPair[$program],
   'program_route'=>$b['cruise_program']['route_from'].'-'.$b['cruise_program']['route_to'],
   'program_nights'=>$n,
   'comparison_scope'=>'primary_luxor_east_west_bank_day_order_only',
   'expected_primary_east_bank_itinerary_day'=>$eastDay,
   'expected_primary_west_bank_itinerary_day'=>$westDay,
   'observed_east_bank_day'=>['day'=>$eastDay,'east_bank_detected'=>$eastFlags['east']!==[],
    'west_bank_detected'=>$eastFlags['west']!==[],'terms'=>$eastFlags],
   'observed_west_bank_day'=>['day'=>$westDay,'east_bank_detected'=>$westFlags['east']!==[],
    'west_bank_detected'=>$westFlags['west']!==[],'terms'=>$westFlags],
   'primary_bank_sequence_matches'=>$matches,
   'status'=>$status,
   'operator_supplier_excursion_sequence_confirmed'=>false,
   'native_program_exact_tour_itinerary_parity_confirmed'=>false,
   'supplier_sailing_calendar_verified'=>false,'vessel_selected'=>false,
   'commercial_cabin_units_verified'=>false,
   'auto_export_native_rate_stage_permitted'=>false,'checkout_ready'=>false,
   'price_amount'=>null,'price_status'=>'pending_quote',
   'source_hashes'=>$sourceHashes,'catalog_activated'=>false];
  if($mode==='apply'){
   if(metadata_exists('post',$id,$meta))WP_CLI::error('review_already_exists_'.$id);
   $record['recorded_at']=gmdate('c');
   if(!add_post_meta($id,$meta,$record,true))WP_CLI::error('audit_write_failed_'.$id);
  }elseif($mode==='verify'){
   $existing=get_post_meta($id,$meta,true);
   if(!is_array($existing))WP_CLI::error('audit_missing_'.$id);
   unset($existing['recorded_at']);
   if($existing!==$record)WP_CLI::error('audit_changed_or_source_drift_'.$id);
  }else{
   if(metadata_exists('post',$id,$meta))WP_CLI::error('audit_preexists_'.$id);
  }
  $countPerStatus[$status]=($countPerStatus[$status]??0)+1;
  $rows[]=['tour'=>$id,'lang'=>$lang,'program'=>$program,'east_day'=>$eastDay,
   'west_day'=>$westDay,'east_in_expected_day'=>$eastCorrect,
   'west_in_expected_day'=>$westCorrect,'status'=>$status];
 }
}
$matches=$countPerStatus['primary_bank_sequence_matches_requires_operator_confirmation']??0;
$differs=$countPerStatus['primary_bank_sequence_differs_operator_review_required']??0;
if($matches!==4||$differs!==10||count($rows)!==14)WP_CLI::error('source_bank_parity_counts_changed');
WP_CLI::line(wp_json_encode([
 'mode'=>$mode,'tour_pairs'=>7,'localized_audit_records'=>count($rows),
 'primary_day_order_matches_localized'=>$matches,'primary_day_order_mismatches_localized'=>$differs,
 'primary_day_order_matches_en'=>2,'primary_day_order_mismatches_en'=>5,
 'commercial_unit_or_supplier_approval'=>false,'new_tour_rates_created'=>0,
 'published_tour_rates'=>0,'catalog_activated'=>false,'audit_rows'=>$rows
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
