<?php
defined('ABSPATH') || exit;
/** Source-backed Firecrawl intake for exact, signed Native Origin ContentJobs. */
final class MAD4B_SCP_Origin_External_Research {
 const CONTRACT='mad4b.origin-external-research.v1';
 public static function process_one($issue_id, array $issue, array $scope) {
  if(!class_exists('MAD4B_Native_Origin_Content_Jobs_V1',false) ||
     !MAD4B_Native_Origin_Content_Jobs_V1::is_admitted() ||
     !class_exists('MAD4B_SCP_Firecrawl_Provider') ||
     !class_exists('MAD4B_SCP_Research_Groups'))
   return new WP_Error('mad4b_origin_external_unauthorized');
  $req=(array)($issue['content_job_request']??[]);
  $site=(string)($scope['site_uuid']??'');
  $id=absint($req['source_post_id']??0);
  $canonical=absint($req['canonical_post_id']??0);
  $token=(string)($req['deduplication_key']??'');
  $job_id=(string)($issue['content_job_id']??'');
  if(($issue['status']??'')!=='open' || ($issue['code']??'')!=='origin_missing' ||
     ($req['status']??'')!=='LINKED'||($req['site_uuid']??'')!==$site||
     !$id||!$canonical||!preg_match('/^[a-f0-9]{64}$/D',$token)||
     get_post_status($id)!=='publish'||get_post_status($canonical)!=='publish'||
     trim((string)get_post_meta($id,'tour_origin_address',true))!=='')
   return new WP_Error('mad4b_origin_external_source_ineligible');
  $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
  if(is_wp_error($job))return $job;
  $j=$job['job'];
  if(($j['state']??'')!=='WAITING_REVIEW'||($j['stage']??'')!=='FACT_QA'||
     ($j['site_uuid']??'')!==$site||
     ($j['created_by_nhi']??'')!==MAD4B_Native_Origin_Content_Jobs_V1::actor()['id']||
     !str_contains((string)($j['subject']??''),'origin-gap-ref:'.$token))
   return new WP_Error('mad4b_origin_external_job_ineligible');
  $terms=wp_get_object_terms($id,'destinations',['fields'=>'ids']);
  if(is_wp_error($terms))return $terms;
  $terms=array_map('intval',(array)$terms);sort($terms,SORT_NUMERIC);
  $fresh=hash('sha256',wp_json_encode([
    'canonical_id'=>$canonical,'origin_term'=>get_post_meta($id,'tour_origin_location_term',true),
    'destination_terms'=>$terms],JSON_UNESCAPED_SLASHES));
  if(!hash_equals((string)($req['source_sha256']??''),$fresh))
   return new WP_Error('mad4b_origin_external_stale_source');
  $group=MAD4B_SCP_Research_Groups::group('origin:tour:'.$canonical,$site,'en');
  if(is_wp_error($group))return $group;
  $records=MAD4B_SCP_Research_Groups::records($group['id']);
  if(is_wp_error($records))return $records;
  foreach($records as $record)
   if(($record['provider_id']??'')==='firecrawl')return ['state'=>'ALREADY_CAPTURED'];
  $capture=MAD4B_SCP_Firecrawl_Provider::scrape_native_origin_tour($id);
  if(is_wp_error($capture))return $capture;
  $data=[
    'contract'=>self::CONTRACT,'job_id'=>$job_id,'issue_id'=>$issue_id,
    'post_id'=>$id,'canonical_post_id'=>$canonical,
    'site_uuid'=>$site,'source_sha256'=>$fresh,
    'fetched_source_sha256'=>$capture['source_sha256'],
    'provider'=>'firecrawl','source_type'=>'own_published_tour_scraped',
    'markdown_excerpt'=>mb_substr($capture['markdown'],0,15000),
    'captured_at'=>$capture['captured_at'],'http_status'=>$capture['http_status'],
    'source_independently_verified'=>false,'exact_pickup_verified'=>false,
    'maps_verified'=>false,'writeback_authorized'=>false
  ];
  $record=MAD4B_SCP_Research_Groups::add_record(
   $group['id'],'firecrawl','en',$capture['source_url'],$data);
  if(is_wp_error($record))return $record;
  return ['contract'=>self::CONTRACT,'state'=>'CAPTURED',
    'source_url'=>$capture['source_url'],'source_sha256'=>$capture['source_sha256'],
    'record_uid'=>$record['record_uid'],'http_status'=>$capture['http_status'],
    'exact_pickup_verified'=>false];
 }
}
