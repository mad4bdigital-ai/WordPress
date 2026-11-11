<?php
/**
 * Plugin Name: MAD4B Translation Campaign Hold
 * Description: Exact ContentJob ID hold across Hub queue claims and ContentJobs transitions, without altering job/alert/queue state.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Translation_Campaign_Hold {
 const CONTRACT='mad4b.hub.translation-campaign-hold.v1';
 const OPTION='mad4b_hub_translation_campaign_hold_v1';
 const SITE_UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 private static $cache_loaded=false;
 private static $cache=null;
 public static function policy():?array {
  if(self::$cache_loaded)return self::$cache;
  self::$cache_loaded=true;
  if(wp_get_environment_type()!=='staging'||untrailingslashit((string)get_option('home'))!=='https://tours.dev.mad4b.com')return null;
  $x=get_option(self::OPTION,null);
  if(!is_array($x)||($x['contract']??'')!==self::CONTRACT||($x['site_uuid']??'')!==self::SITE_UUID||
     ($x['mode']??'')!=='notification_only'||($x['resume_requires']??'')!=='explicit_authorized_decision'||
     !array_key_exists('resume_on',$x)||$x['resume_on']!==null||!is_array($x['jobs']??null)||count($x['jobs'])!==571||
     !preg_match('/^[a-f0-9]{64}$/D',(string)($x['jobs_sha256']??''))||
     !hash_equals((string)$x['jobs_sha256'],hash('sha256',wp_json_encode($x['jobs'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))))return null;
  self::$cache=$x;return $x;
 }
 public static function blocked_job_id(string $jobId):bool {
  $p=self::policy();return $p!==null && wp_is_uuid($jobId) && isset($p['jobs'][$jobId]);
 }
 public static function blocked_job(array $job):bool {
  if(($job['content_type']??'')!=='translation'||($job['site_uuid']??'')!==self::SITE_UUID)return false;
  $id=(string)($job['job_id']??'');$p=self::policy();$expected=$p['jobs'][$id]??null;
  return is_array($expected)&&($expected['language']??'')===($job['language']??'');
 }
 public static function transition_denied(array $job,string $newState,string $newStage,string $artifactId):bool {
  return self::blocked_job($job)&&($job['state']!==$newState||$job['stage']!==$newStage||$artifactId!=='');
 }
 public static function status():array {
  $p=self::policy();$out=['contract'=>self::CONTRACT,'site_uuid'=>self::SITE_UUID,'state'=>$p?'POLICY_ACTIVE':'NOT_CONFIGURED',
   'mode'=>$p?'notification_only':'none','protected_translation_jobs'=>0,'core_job_states_mutated'=>false,
   'queue_tasks_mutated'=>false,'alerts_closed'=>false,'resume_on'=>null,'coverage_mismatches'=>[]];
  if(!$p)return $out;
  $out['protected_translation_jobs']=count($p['jobs']);$out['original_42_included']=(int)($p['original_42_included']??0);
  $out['remaining_not_under_existing_42']=$out['protected_translation_jobs']-$out['original_42_included'];
  $out['jobs_sha256']=$p['jobs_sha256'];$out['reason_code']=$p['reason_code']??'';
  global $wpdb;$table=$wpdb->prefix.'mad4b_content_jobs';
  $ids=array_keys($p['jobs']);$batchSize=150;$status=[];$seen=0;
  foreach(array_chunk($ids,$batchSize) as $part){
   $holders=implode(',',array_fill(0,count($part),'%s'));
   $rows=$wpdb->get_results($wpdb->prepare("SELECT job_id,site_uuid,content_type,language,state,stage,job_revision FROM {$table} WHERE job_id IN ({$holders})",$part),ARRAY_A);
   foreach((array)$rows as $j){$seen++;$key=$j['state'].'/'.$j['stage'];$status[$key]=($status[$key]??0)+1;
    $exp=$p['jobs'][$j['job_id']]??null;
    if(!is_array($exp)||$j['site_uuid']!==self::SITE_UUID||$j['content_type']!=='translation'||$j['language']!==$exp['language']||
      $j['state']!==$exp['original_state']||$j['stage']!==$exp['original_stage']||(int)$j['job_revision']!==(int)$exp['revision'])
      $out['coverage_mismatches'][]=['job_id'=>$j['job_id'],'code'=>'ORIGINAL_JOB_STATE_REVISION_OR_SCOPE_CHANGED'];
   }
  }
  if($seen!==571)$out['coverage_mismatches'][]=['code'=>'SOURCE_JOB_COUNT_DRIFT','observed'=>$seen];
  $out['original_job_states']=$status;$out['observed_source_rows']=$seen;
  $queuePolicy=get_option('mad4b_hub_translation_campaign_schedule_hold_v1',[]);
  $out['queue_hold_configured']=is_array($queuePolicy)&&($queuePolicy['contract']??'')==='mad4b.hub-native-queue-notification-hold.v1'
    &&($queuePolicy['site_uuid']??'')===self::SITE_UUID && ($queuePolicy['queue_available_at']??'')==='2099-12-31 23:59:59';
  $out['queue_available_at_is_indefinite_sentinel']=true;
  $out['queue_resume_requires_explicit_action']=true;
  if($out['queue_hold_configured']){
    $q=$wpdb->prefix.'mad4b_hub_bulk_tasks';$b=$wpdb->prefix.'mad4b_hub_bulk_batches';
    $counts=['stage_work'=>0,'runtime_resolve'=>0];
    foreach(array_chunk($ids,140) as $part){
      $holders=implode(',',array_fill(0,count($part),'%s'));
      $args=array_merge($part,[$queuePolicy['queue_available_at']]);
      $sql=$wpdb->prepare("SELECT q.operation,COUNT(*) n FROM {$q} q JOIN {$b} b ON b.batch_id=q.batch_id
        WHERE b.state='ACTIVE' AND q.content_job_id IN ({$holders}) AND q.available_at=%s
        AND ((q.operation='stage_work' AND q.state='WAITING_EXECUTOR') OR
             (q.operation='runtime_resolve' AND q.state='QUEUED')) GROUP BY q.operation",$args);
      foreach((array)$wpdb->get_results($sql,ARRAY_A) as $g)if(isset($counts[$g['operation']]))$counts[$g['operation']]+=(int)$g['n'];
    }
    $out['queue_task_rows_held']=$counts['stage_work']+$counts['runtime_resolve'];
    $out['queue_rows_by_lane']=$counts;
    if($counts['stage_work']!==571||$counts['runtime_resolve']!==509)$out['coverage_mismatches'][]=['code'=>'HUB_QUEUE_HOLD_ROWS_CHANGED'];
  }else $out['coverage_mismatches'][]=['code'=>'HUB_QUEUE_HOLD_NOT_ACTIVE'];
  if($out['coverage_mismatches'])$out['state']='NEEDS_RECONCILIATION';
  return $out;
 }
 public static function cli(array $args):void {
  if(($args[0]??'status')!=='status')WP_CLI::error('Read-only status only: explicitly authorized policy update required to resume.');
  WP_CLI::line(wp_json_encode(self::status(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
 public static function notice():void {
  if(!current_user_can('manage_options')||(string)($_GET['page']??'')!=='mad4b-content-readiness-alerts')return;
  $p=self::policy();if(!$p)return;
  echo '<div class="notice notice-info"><p><strong>MAD4B Hub Governance:</strong> '.esc_html((string)count($p['jobs'])).
   ' original translation ContentJobs (including the 42 previously deferred) are intentionally held in Notification Only. Research, localization assessment and pricing reviews remain independent; no job is cancelled, published, or resumed.</p></div>';
 }
}
add_action('init',static function(){if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b translation-campaign-hold',[MAD4B_Hub_Translation_Campaign_Hold::class,'cli']);},21);
add_action('admin_notices',[MAD4B_Hub_Translation_Campaign_Hold::class,'notice']);
