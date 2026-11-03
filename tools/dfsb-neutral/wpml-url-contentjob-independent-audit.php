<?php
if(!defined('ABSPATH'))throw new RuntimeException('wp_required');
global $wpdb;
$site='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
$registry=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
$issues=[];
foreach($registry as $id=>$row) {
 if(!is_array($row)||!str_starts_with((string)($row['code']??''),'dfsb_wpml_'))continue;
 $issues[$id]=$row;
}
$tables=MAD4B_SCP_Schema::tables();
$t=$tables['content_jobs'];
$jobs=(array)$wpdb->get_results($wpdb->prepare(
  "SELECT job_id,subject,state,stage,content_type,language,country,site_uuid,brand_id,tenant_id,job_revision,created_by_user,created_by_nhi FROM `{$t}` WHERE site_uuid=%s AND subject LIKE %s LIMIT 400",
 $site,'%wpml-url-issue-ref:%'),ARRAY_A);
$errors=[];$seen=[];$states=[];$priorities=[];$classes=[];$linked=0;$uniqueMarkers=[];
foreach($jobs as $job){
 $matched=preg_match('/wpml-url-issue-ref:([a-f0-9]{64})/D',(string)$job['subject'],$m);
 if(!$matched){$errors[]='job_marker_absent';continue;}
 $marker=$m[1];$uniqueMarkers[$marker]=($uniqueMarkers[$marker]??0)+1;
 if(!isset($issues[$marker]))$errors[]='foreign_issue_marker';
}
foreach($issues as $id=>$row) {
 $jobid=(string)($row['content_job_id']??'');
 if($jobid===''){$errors[]='missing_link_'.$id;continue;}
 $linked++;
 if(isset($seen[$jobid]))$errors[]='duplicate_linked_job_id';
 $seen[$jobid]=true;
 $matches=array_values(array_filter($jobs,static function($x)use($jobid){return $x['job_id']===$jobid;}));
 if(count($matches)!==1){$errors[]='missing_or_duplicate_native_'.$id;continue;}
 $job=$matches[0];
 if(!str_contains($job['subject'],'wpml-url-issue-ref:'.$id))$errors[]='marker_identity_changed';
 if(($row['content_job_request']['status']??'')!=='LINKED')$errors[]='intake_not_linked';
 if($job['state']!=='QUEUED' || $job['stage']!=='INTAKE')$errors[]='job_not_queued';
 if($job['content_type']!=='translation' || $job['country']!=='global')$errors[]='job_type_or_market_invalid';
 if($job['language']!==($row['content_job_request']['language']??''))$errors[]='job_locale_drift';
 if($job['site_uuid']!==$site)$errors[]='cross_site_job';
 $priority=(string)($row['content_job_request']['priority']??'missing');$priorities[$priority]=($priorities[$priority]??0)+1;
 $states[$job['state']]=($states[$job['state']]??0)+1;
 $kind=(string)($row['content_job_request']['content_type']??'unknown');
 $classes[$kind]=($classes[$kind]??0)+1;
}
foreach($uniqueMarkers as $marker=>$n){if($n!==1)$errors[]='duplicate_subject_marker_'.$marker;}
$result=[
 'contract'=>'mad4b.wpml-url-contentjob-independent-audit.v1',
 'site_uuid'=>$site,'environment'=>wp_get_environment_type(),
 'wpml_issue_count'=>count($issues),'linked'=>$linked,'unique_job_ids'=>count($seen),
 'native_marker_jobs'=>count($jobs),'unique_markers'=>count($uniqueMarkers),
 'states'=>$states,'priorities'=>$priorities,'intake_types'=>$classes,
 'errors_count'=>count($errors),'errors'=>array_slice($errors,0,14),
 'pass'=>count($issues)===178 && $linked===178 && count($jobs)===178 && count($seen)===178 && !$errors,
 'mutation_performed'=>false,'publication_performed'=>false
];
echo wp_json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;
if(!$result['pass'])throw new RuntimeException('independent_audit_failed');
