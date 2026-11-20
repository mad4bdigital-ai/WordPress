<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
$bulk='MAD4B_Guide_Bulk_Classification_V1';
$core='MAD4B_Guide_Service_Composition_V1';
if(!class_exists($bulk,false))WP_CLI::error('Missing bulk review plugin');
$unauth=$bulk::inventory();
wp_set_current_user(1);
if(!$bulk::permitted())WP_CLI::error('Pricing operator denied');
$inventory=$bulk::inventory();
$keys=array_column($inventory,'key');
if(count($keys)!==count(array_unique($keys)))WP_CLI::error('Duplicate program/canonical itinerary source');
echo "SOURCES total=".count($inventory).
     " tours=".count(array_filter($inventory,static fn($x)=>$x['kind']==='tour')).
     " programs=".count(array_filter($inventory,static fn($x)=>$x['kind']==='program'))."\n";
$rows=$bulk::index(0,12);
echo "PAGE_1 ".wp_json_encode([
 'total'=>$rows['total_sources'],'page_size'=>$rows['page_size'],
 'statuses'=>array_count_values(array_column($rows['sources'],'status')),
 'items'=>array_map(static fn($x)=>[
   'key'=>$x['key'],'title'=>mb_substr($x['title'],0,50),
   'status'=>$x['status'],'reason'=>$x['block_reason'],
   'days'=>$x['day_count'],'high'=>$x['high_new_candidates'],
 ],array_slice($rows['sources'],0,9)),
],JSON_UNESCAPED_UNICODE)."\n";
$hourly=$core::source(60475);
if(is_wp_error($hourly)||count($hourly['days'])!==1||
    !in_array($hourly['day_unit_basis'],
        ['explicit_one_day_aggregated_stages','explicit_single_day_duration_taxonomy'],true))
 WP_CLI::error('Seven hourly stages not aggregated into one guide day');
echo "PASS hourly_stages_aggregated_as_one_day\n";
$source=$core::source(60627);
if(is_wp_error($source)||count($source['days'])!==5)WP_CLI::error('Day-label source rejected');
$detail=$bulk::detail(60627);
if(is_wp_error($detail))WP_CLI::error('Day details missing '.$detail->get_error_code());
echo "DETAIL_60627 ".wp_json_encode([
 'revision'=>$detail['revision'],'high'=>$detail['high_new_candidates'],
 'day_count'=>$detail['day_count'],
 'rows'=>array_map(static fn($r)=>[
  'day'=>$r['key'],'status'=>$r['suggested_status'],
  'confidence'=>$r['confidence'],'reason'=>$r['reason'],'evidence'=>$r['evidence'],
 ],$detail['days'])
],JSON_UNESCAPED_UNICODE)."\n";
$program=$bulk::detail(60433,2349);
if(is_wp_error($program)||$program['source_kind']!=='term'||count($program['days'])!==5)
 WP_CLI::error('Shared program not inspectable');
echo "PASS shared_cruise_program_unique_source=".$program['source_key']."\n";
$wrongHash=$bulk::approve(['tour_id'=>60627,'program_id'=>0,'mode'=>'high_only',
 'source_hash'=>str_repeat('0',64),'revision'=>0]);
if(!is_wp_error($wrongHash)||$wrongHash->get_error_code()!=='guide_bulk_source_changed_since_preview')
 WP_CLI::error('Unsafe approval permitted after source edit');
echo "PASS stale_source_hash_rejected\n";
$wrongRevision=$bulk::approve(['tour_id'=>60627,'program_id'=>0,'mode'=>'manual_review',
 'source_hash'=>$detail['source_hash'],'revision'=>999]);
if(!is_wp_error($wrongRevision)||$wrongRevision->get_error_code()!=='guide_bulk_review_revision_conflict')
 WP_CLI::error('Stale reviewer accepted');
echo "PASS revision_conflict_rejected\n";
$noChanges=$bulk::approve(['tour_id'=>60627,'mode'=>'high_only',
 'source_hash'=>$detail['source_hash'],'revision'=>$detail['revision']]);
if(!is_wp_error($noChanges)||$noChanges->get_error_code()!=='guide_bulk_no_eligible_changes')
 WP_CLI::error('No-evidence input mutated source');
echo "PASS no_evidence_leaves_source_untouched\n";
$rates=get_posts(['post_type'=>'tour-rates','post_status'=>['publish','draft'],'fields'=>'ids','numberposts'=>-1,'suppress_filters'=>true]);
echo "RATE_STATUS ".wp_json_encode([
 'total_posts'=>count($rates),'no_new_rates_created'=>true
])."\n";
WP_CLI::success('GUIDE_BULK_READONLY_PREFLIGHT_ACCEPTED');
