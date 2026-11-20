<?php
/** Exact-scope audited duplicate correction on 10-day Package EN/ES. */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)return;
global $wpdb;
$mode=getenv('MAD4B_PACKAGE_DUPLICATE_MODE')?:'plan';
if(!in_array($mode,['plan','apply','verify'],true))WP_CLI::error('invalid_mode');
if(rtrim(home_url(),'/')!=='https://wayfatours.com')WP_CLI::error('site_scope');
$targets=['en'=>60441,'es'=>60607];
$planKey='_mad4b_package_accommodation_source_plan_v1';
$auditKey='_mad4b_package_itinerary_duplicate_remediation_v1';
$expectedTitles=['en'=>'Day 5: Luxor West Bank','es'=>'Día 5: Luxor, Cisjordania'];
if(get_option('mad4b_stay_editorial_publish_v1')['catalog_activated']!==false||
   (int)(wp_count_posts('tour-rates')->publish??0)!==0||
   (int)(wp_count_posts('property-rates')->publish??0)!==0)WP_CLI::error('commercial_scope');
$payload=[];$trid=null;
foreach($targets as $lang=>$id){
 $p=get_post($id);
 $tr=$wpdb->get_row($wpdb->prepare("SELECT trid,language_code FROM {$wpdb->prefix}icl_translations WHERE element_type='post_tours-and-activities' AND element_id=%d",$id),ARRAY_A);
 if(!$p||$p->post_type!=='tours-and-activities'||$p->post_status!=='publish'||
   !$tr||$tr['language_code']!==$lang) WP_CLI::error('tour_wpml_'.$id);
 if($trid===null)$trid=(int)$tr['trid'];
 if($trid!==(int)$tr['trid']||$trid!==1218296)WP_CLI::error('wrong_trid_'.$id);
 $live=[];
 foreach(['itinerary_item','includes',$planKey] as $key){
  $records=$wpdb->get_results($wpdb->prepare("SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s",$id,$key),ARRAY_A);
  if(count($records)!==1) WP_CLI::error('exact_meta_row_count_'.$id.'_'.$key);
  $live[$key]=['meta_id'=>(int)$records[0]['meta_id'],'raw'=>$records[0]['meta_value'],'value'=>maybe_unserialize($records[0]['meta_value'])];
 }
 $oldIt=$live['itinerary_item']['value'];
 $oldIn=$live['includes']['value'];
 $oldPlan=$live[$planKey]['value'];
 if(!is_array($oldIt)||!is_array($oldIn)||!is_array($oldPlan))WP_CLI::error('record_not_array_'.$id);
 if(($oldPlan['original_tour_id']??0)!==60441 || ($oldPlan['localized_tour_id']??0)!==$id||
    ($oldPlan['language']??'')!==$lang || ($oldPlan['tour_days']??0)!==10||
    ($oldPlan['tour_nights']??0)!==9 || count($oldPlan['stages']??[])!==3||
    ($oldPlan['source_review_status']??'')==='commercial_ready' ||
    ($oldPlan['checkout_ready']??true)!==false ||
    ($oldPlan['new_tour_rate_ids_created']??'not_empty')!==[]||
    ($oldPlan['native_rate_stages_written']??true)!==false)WP_CLI::error('saved_source_plan_guard_'.$id);
 $existingAudit=get_post_meta($id,$auditKey,true);
 if($mode==='verify'){
  if(count($oldIt)!==10||count($oldIn)!==13)WP_CLI::error('clean_counts_changed_'.$id);
  if(!is_array($existingAudit)||($existingAudit['status']??null)!=='verified_duplicate_removed'||
    ($existingAudit['previous_itinerary_rows']??0)!==11||
    ($existingAudit['current_itinerary_rows']??0)!==10||
    ($existingAudit['previous_includes_rows']??0)!==14||
    ($existingAudit['current_includes_rows']??0)!==13)WP_CLI::error('audit_missing_'.$id);
  if(($oldPlan['source_review_status']??'')!=='draft_geometry_verified'||
    ($oldPlan['source_itinerary_record_count']??0)!==10||
    ($oldPlan['virtual_itinerary_deduplicated_for_proposal_only']??true)!==false||
    in_array('duplicate_exact_day_5_in_both_languages_blocks_native_rate_export',$oldPlan['source_issues']??[],true))WP_CLI::error('source_plan_not_requalified_'.$id);
  foreach(['itinerary_item','includes'] as $key){
   $hash=hash('sha256',wp_json_encode($live[$key]['value'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
   if(($oldPlan['source_sha256'][$key]??null)!==$hash||
     ($existingAudit['after_sha256'][$key]??null)!==$hash)WP_CLI::error('new_source_hash_mismatch_'.$id.'_'.$key);
  }
  if($oldIt['item-4']['title']!==$expectedTitles[$lang]||$oldIt['item-5']['title']===$expectedTitles[$lang])WP_CLI::error('day5_not_unique_'.$id);
  $payload[$id]=['itinerary_rows'=>10,'includes_rows'=>13,'source_status'=>$oldPlan['source_review_status'],'corrected'=>true];
  continue;
 }
 if(metadata_exists('post',$id,$auditKey))WP_CLI::error('already_remediated_'.$id);
 if(count($oldIt)!==11||count($oldIn)!==14)WP_CLI::error('source_rows_changed_'.$id);
 foreach(['itinerary_item'=>$oldIt,'includes'=>$oldIn] as $name=>$rows){
  if(array_keys($rows)!==array_map(static fn($i)=>'item-'.$i,range(0,count($rows)-1)))
   WP_CLI::error('unexpected_repeater_keys_'.$id.'_'.$name);
  if($rows['item-4']!==$rows['item-5'])WP_CLI::error('not_exact_duplicate_'.$id.'_'.$name);
 }
 if(($oldIt['item-4']['title']??null)!==$expectedTitles[$lang])WP_CLI::error('duplicate_day_title_changed_'.$id);
 $included=(string)($oldIn['item-4']['included_item']??'');
 if($lang==='en'&&$included!=='Accommodation for 5 nights in Cairo hotel including daily breakfast')WP_CLI::error('expected_include_en_changed');
 if($lang==='es'&&$included!=='Alojamiento por 5 noches en hotel de El Cairo, incluido desayuno diario.')WP_CLI::error('expected_include_es_changed');
 foreach(['itinerary_item','includes'] as $key){
  $hash=hash('sha256',wp_json_encode($live[$key]['value'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  if(($oldPlan['source_sha256'][$key]??null)!==$hash)WP_CLI::error('preexisting_source_hash_drift_'.$id.'_'.$key);
 }
 if(($oldPlan['source_review_status']??'')!=='blocked_duplicate_itinerary'||
   ($oldPlan['source_itinerary_record_count']??0)!==11||
   ($oldPlan['virtual_itinerary_deduplicated_for_proposal_only']??false)!==true||
   !in_array('duplicate_exact_day_5_in_both_languages_blocks_native_rate_export',$oldPlan['source_issues']??[],true))
  WP_CLI::error('source_plan_prior_status_changed_'.$id);
 $make=static function($rows){
  $values=array_values($rows);
  array_splice($values,5,1);
  $out=[];foreach($values as $index=>$row)$out['item-'.$index]=$row;
  return $out;
 };
 $newIt=$make($oldIt);$newIn=$make($oldIn);
 if(count($newIt)!==10||count($newIn)!==13)WP_CLI::error('computed_rows_failed_'.$id);
 $newPlan=$oldPlan;
 $newPlan['source_itinerary_record_count']=10;
 $newPlan['virtual_itinerary_deduplicated_for_proposal_only']=false;
 $newPlan['source_review_status']='draft_geometry_verified';
 $newPlan['source_issues']=array_values(array_filter($newPlan['source_issues'],static fn($x)=>$x!=='duplicate_exact_day_5_in_both_languages_blocks_native_rate_export'));
 $hashBefore=[];$hashAfter=[];
 foreach(['itinerary_item'=>$newIt,'includes'=>$newIn] as $key=>$arr){
  $hashBefore[$key]=$oldPlan['source_sha256'][$key];
  $hashAfter[$key]=hash('sha256',wp_json_encode($arr,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  $newPlan['source_sha256'][$key]=$hashAfter[$key];
 }
 $audit=['schema'=>'mad4b.package-content-exact-duplicate-removal.v1',
  'status'=>'verified_duplicate_removed','tour_id'=>$id,'language'=>$lang,
  'wpml_trid'=>$trid,'approval'=>'owner_requested_remove_day5_duplicate_and_continue',
  'itinerary_deleted_key'=>'item-5','includes_deleted_key'=>'item-5',
  'itinerary_kept_key'=>'item-4','includes_kept_key'=>'item-4',
  'previous_itinerary_rows'=>11,'current_itinerary_rows'=>10,
  'previous_includes_rows'=>14,'current_includes_rows'=>13,
  'before_sha256'=>$hashBefore,'after_sha256'=>$hashAfter,
  'source_plan_previous_status'=>'blocked_duplicate_itinerary',
  'source_plan_new_status'=>'draft_geometry_verified',
  'legacy_rate_edit_count'=>0,'price_change_count'=>0,'checkout_authorized'=>false];
 $payload[$id]=['lang'=>$lang,'old'=>$live,'new'=>['itinerary_item'=>$newIt,'includes'=>$newIn,$planKey=>$newPlan],
  'audit'=>$audit,'before_hashes'=>$hashBefore,'after_hashes'=>$hashAfter];
}
if($mode==='plan'){
 $out=[];foreach($payload as $id=>$p)$out[$id]=['old_itinerary_rows'=>11,'new_itinerary_rows'=>10,
  'old_includes_rows'=>14,'new_includes_rows'=>13,'previous_plan'=>'blocked_duplicate_itinerary',
  'next_plan'=>'draft_geometry_verified','before_hashes'=>$p['before_hashes'],'after_hashes'=>$p['after_hashes']];
 WP_CLI::line(wp_json_encode(['mode'=>'plan','reviewed'=>$out,'source_plan_stages_unchanged'=>true,
  'post_status_unchanged'=>true,'prices_unchanged'=>true,'rate_edits'=>0],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 return;
}
if($mode==='apply'){
 $started=$wpdb->query('START TRANSACTION');
 if($started===false)WP_CLI::error('transaction_unavailable');
 $error='';
 foreach($payload as $id=>$p){
  foreach($p['new'] as $key=>$after){
   $record=$p['old'][$key];
   $sql=$wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE meta_id=%d AND post_id=%d AND meta_value=%s",
     maybe_serialize($after),$record['meta_id'],$id,$record['raw']);
   if((int)$wpdb->query($sql)!==1){$error='guarded_write_failed_'.$id.'_'.$key;break;}
  }
  if($error)break;
  $audit=$p['audit'];$audit['recorded_at']=gmdate('c');
  $sql=$wpdb->prepare("INSERT INTO {$wpdb->postmeta} (post_id,meta_key,meta_value) VALUES (%d,%s,%s)",
    $id,$auditKey,maybe_serialize($audit));
  if((int)$wpdb->query($sql)!==1){$error='audit_write_failed_'.$id;break;}
 }
 if($error){$wpdb->query('ROLLBACK');WP_CLI::error($error);}
 if($wpdb->query('COMMIT')===false)WP_CLI::error('commit_failed_check_state');
 foreach($targets as $id){
  wp_cache_delete($id,'post_meta');
  clean_post_cache($id);
 }
 WP_CLI::line(wp_json_encode(['mode'=>'apply','itinerary_duplicate_rows_removed'=>2,
  'includes_duplicate_rows_removed'=>2,'source_plans_requalified'=>2,'rate_edits'=>0,
  'tour_status_writes'=>0,'source_plan_stage_changes'=>0,'catalog_activated'=>false],
  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 return;
}
WP_CLI::line(wp_json_encode(['mode'=>'verify','verified_tours'=>$payload,'errors'=>[],'error_count'=>0,
 'rate_edits'=>0,'catalog_activated'=>false],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
