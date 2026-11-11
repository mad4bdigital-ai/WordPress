<?php
defined('ABSPATH') || exit;
/**
 * Cross-batch semantic single-flight admission. Feature-flagged, default OFF.
 * Never grants authority, claims/execut es work, or auto-merges legacy rows.
 */
final class MAD4B_Hub_Governed_Single_Flight {
 const CONTRACT='mad4b.hub.semantic-single-flight.v1';
 static function certified_only(){
  return getenv('MAD4B_HUB_SEMANTIC_FLIGHT_MODE')==='certified_only';
 }
 static function enabled(){
  return in_array(getenv('MAD4B_HUB_SEMANTIC_FLIGHT_MODE'),
       ['enforce','certified_only'],true)
    && wp_get_environment_type()==='staging'
    && rtrim(home_url(),'/')==='https://tours.dev.mad4b.com';
 }
 /** Scoped first rollout never changes admission or claim for unproven lanes. */
 static function applies_to(array $item){
  if(!self::certified_only())return true;
  $job=(string)($item['job_id']??$item['content_job_id']??'');
  $op=(string)($item['operation']??'');
  return $job!==''&&class_exists('MAD4B_Hub_Original_Context_Evidence')
    &&isset(MAD4B_Hub_Original_Context_Evidence::OPERATIONS[$op]);
 }
 static function tables(){
  global $wpdb;
  return [$wpdb->prefix.'mad4b_hub_semantic_flights',
          $wpdb->prefix.'mad4b_hub_semantic_triggers'];
 }
 static function error($reason,$status=409){
  return new WP_Error('mad4b_hub_flight_'.$reason,$reason,
     ['status'=>$status,'contract'=>self::CONTRACT]);
 }
 static function install(){
  if(!class_exists('MAD4B_Content_Hub')||!MAD4B_Content_Hub::permission()
     ||!class_exists('MAD4B_SCP_Policy')||!MAD4B_SCP_Policy::can_mutate())
     return self::error('not_authorized',403);
  require_once ABSPATH.'wp-admin/includes/upgrade.php';
  global $wpdb;[$flights,$triggers]=self::tables();
  $collate=$wpdb->get_charset_collate();
  dbDelta("CREATE TABLE $flights (
   work_hash char(64) NOT NULL,
   slot_hash char(64) NOT NULL,
   job_id char(36) NOT NULL,
   site_uuid char(36) NOT NULL,
   tenant_id varchar(191) NOT NULL,
   brand_id varchar(191) NOT NULL,
   job_revision bigint(20) unsigned NOT NULL,
   workflow_type varchar(40) NOT NULL,
   source_company varchar(191) NOT NULL DEFAULT '',
   source_record_id varchar(191) NOT NULL DEFAULT '',
   leader_task_id char(36) NOT NULL,
   admitted_at datetime NOT NULL,
   PRIMARY KEY  (work_hash),
   UNIQUE KEY slot_hash (slot_hash),
   KEY job_revision (job_id,job_revision)
  ) ENGINE=InnoDB $collate;");
  dbDelta("CREATE TABLE $triggers (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   work_hash char(64) NOT NULL,
   trigger_hash char(64) NOT NULL,
   trigger_source varchar(32) NOT NULL,
   received_at datetime NOT NULL,
   PRIMARY KEY  (id),
   UNIQUE KEY flight_trigger (work_hash,trigger_hash)
  ) ENGINE=InnoDB $collate;");
  if(!class_exists('MAD4B_Hub_Semantic_Legacy_Cutover'))
   return self::error('legacy_cutover_provider_unavailable',503);
  $legacy=MAD4B_Hub_Semantic_Legacy_Cutover::install();
  if(is_wp_error($legacy)||$legacy!==true)
   return self::error('legacy_cutover_schema_unavailable',503);
  return ['contract'=>self::CONTRACT,'schema_ready'=>self::ready()];
 }
 static function ready(){
  if(!class_exists('MAD4B_Hub_Semantic_Legacy_Cutover')
     ||!MAD4B_Hub_Semantic_Legacy_Cutover::locked()
     ||!MAD4B_Hub_Semantic_Legacy_Cutover::schema())return false;
  global $wpdb;[$f,$t]=self::tables();
  foreach([$f,$t] as $table){
   if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table)return false;
   $engine=$wpdb->get_var($wpdb->prepare(
     'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
   if(strtoupper((string)$engine)!=='INNODB')return false;
  }
  $primary=$wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
     AND TABLE_NAME=%s AND INDEX_NAME=%s AND NON_UNIQUE=0',$f,'PRIMARY'));
  $slotUnique=$wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
     AND TABLE_NAME=%s AND INDEX_NAME=%s AND NON_UNIQUE=0',$f,'slot_hash'));
  return (int)$primary>=1 && (int)$slotUnique>=1;
 }
 private static function canonical($value){
  if(!is_array($value))return $value;
  if(!array_is_list($value))ksort($value,SORT_STRING);
  foreach($value as $key=>$item)$value[$key]=self::canonical($item);
  return $value;
 }
 private static function hash($value){
  return hash('sha256',wp_json_encode(self::canonical($value),
    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 }
 static function plan(array $item,$claim_only=false){
  $jobid=(string)($item['job_id']??'');
  if($jobid===''){
   if(!class_exists('MAD4B_Hub_Unlinked_Task_Context'))
    return self::error('unlinked_context_provider_missing',503);
   $p=$item['payload']??null;
   if(!is_array($p))return self::error('unlinked_payload_missing',409);
   $o=$p['workflow_origin']??null;
   if(!is_array($o))return self::error('legacy_unlinked_origin_requires_review',409);
   $plan=MAD4B_Hub_Unlinked_Task_Context::plan($item,$o,$p);
   if(is_wp_error($plan))return $plan;
   $trigger=(string)($p['trigger_event_id']??'');
   $batch=(string)($item['_batch_key']??'');
   if(strlen($trigger)>128 || (!$claim_only && $trigger==='' && ($batch==='' || strlen($batch)>191)))
    return self::error('unlinked_trigger_receipt_missing',409);
   $trigger_id=$trigger!==''?$trigger:($claim_only?'claim_readback_only':$batch);
   $plan['trigger_hash']=self::hash(['source'=>$p['trigger_source']??'direct_intake','receipt'=>$trigger_id]);
   $plan['trigger_source']=(string)($p['trigger_source']??'direct_intake');
   return $plan;
  }
  if(!class_exists('MAD4B_SCP_Content_Jobs'))
   return self::error('original_content_job_unavailable',503);
  $original=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$jobid]);
  if(is_wp_error($original))return $original;
  $job=$original['job']??null;
  if(!is_array($job) || ($job['job_id']??'')!==$jobid)
   return self::error('job_scope_invalid',403);
  if(!in_array((string)($job['state']??''),['NEW','QUEUED','RUNNING'],true)
     ||($job['stage']??'')!==($item['stage']??''))
   return self::error('stale_job_stage',409);
  $p=$item['payload']??null;
  if(!is_array($p))return self::error('payload_invalid',422);
  $origin=$p['workflow_origin']??null;
  if(!is_array($origin)||($origin['contract']??'')!=='mad4b.hub.workflow-origin.v1')
   return self::error('origin_not_verified',409);
  $digest=(string)($origin['origin_sha256']??'');
  unset($origin['origin_sha256']);
  if(!preg_match('/^[a-f0-9]{64}$/D',$digest)
     ||!hash_equals($digest,hash('sha256',wp_json_encode($origin,JSON_UNESCAPED_SLASHES)))
     ||($origin['workflow_type']??'')!=='content_production'
     ||($origin['source_record_id']??'')!==$jobid
     ||($origin['source_verified']??false)!==true
     ||(int)($origin['job_revision']??-1)!==(int)($job['job_revision']??-2))
   return self::error('origin_scope_or_revision_mismatch',409);
  if(($origin['source_site_uuid']??'')!==$job['site_uuid']
     ||($origin['source_language']??'')!==$job['language'])
   return self::error('original_site_or_language_mismatch',409);
  if(!isset($p['content_job_revision'])
     ||(int)$p['content_job_revision']!==(int)$job['job_revision'])
   return self::error('bound_revision_missing_or_stale',409);
  foreach(['source_snapshot_sha256','policy_snapshot_sha256','capability_snapshot_sha256'] as $key){
   if(!is_string($p[$key]??null)
      ||!preg_match('/^[a-f0-9]{64}$/D',$p[$key]))
    return self::error('context_'.$key.'_missing',409);
  }
  foreach(['output_contract','effect_type'] as $key){
   if(!is_string($p[$key]??null)||$p[$key]===''
      ||strlen($p[$key])>128)return self::error('context_'.$key.'_missing',409);
  }
  // A structurally valid SHA supplied by an intake client is not source proof.
  if(!class_exists('MAD4B_Hub_Original_Context_Evidence'))
   return self::error('original_evidence_provider_missing',503);
  $cert=MAD4B_Hub_Original_Context_Evidence::verify($item,$job);
  if(is_wp_error($cert)||$cert!==true)
   return is_wp_error($cert)?$cert:self::error('original_evidence_invalid',409);
  if(in_array((string)($item['stage']??''),['PRICING','PUBLISHING','MEDIA_RIGHTS',
       'CANONICAL_RELEASE','COMPANY_DISTRIBUTION','WPML_TRANSLATION'],true)
     ||!in_array((string)$p['effect_type'],['read_only','research_artifact'],true))
   return self::error('high_impact_requires_original_review',409);
  $semantic=[
   'site_uuid'=>$job['site_uuid'],'tenant_id'=>$job['tenant_id'],
   'brand_id'=>$job['brand_id'],'job_id'=>$jobid,
   'job_revision'=>(int)$job['job_revision'],
   'language'=>$job['language'],'market'=>$job['country'],
   'content_type'=>$job['content_type'],
   'stage'=>$item['stage'],'operation'=>$item['operation'],
   'target_ref'=>$item['target_ref'],
   'source_snapshot_sha256'=>$p['source_snapshot_sha256'],
   'policy_snapshot_sha256'=>$p['policy_snapshot_sha256'],
   'capability_snapshot_sha256'=>$p['capability_snapshot_sha256'],
   'output_contract'=>$p['output_contract'],'effect_type'=>$p['effect_type'],
   'stage_input'=>$p['stage_input']??[],
   'requested_step'=>$p['requested_step']??'',
   'preferred_ability'=>$p['preferred_ability']??'',
   'executor_lane'=>$origin['executor_lane']??'',
   'assigned_executor'=>$p['assigned_executor']??'mcp_client',
   'stage_adapter_id'=>$p['stage_adapter_id']??'',
   'stage_ability'=>$p['stage_ability']??'',
   'capability_query'=>$p['capability_query']??'',
   'provider'=>$p['provider']??'',
   'model_version'=>$p['model_version']??'',
   'prompt_version'=>$p['prompt_version']??'',
   'dependencies'=>$item['depends_on']??[],
  ];
  $slot=['site_uuid'=>$job['site_uuid'],'tenant_id'=>$job['tenant_id'],
    'brand_id'=>$job['brand_id'],'job_id'=>$jobid,
    'job_revision'=>(int)$job['job_revision'],
    'stage'=>$item['stage'],'operation'=>$item['operation'],
    'target_ref'=>$item['target_ref']];
  $trigger=$p['trigger_event_id']??'';
  if(!is_string($trigger)||strlen($trigger)>128)
   return self::error('trigger_invalid',409);
  $batch_key=(string)($item['_batch_key']??'');
  if(!$claim_only && $trigger==='' && ($batch_key==='' || strlen($batch_key)>191))
    return self::error('trigger_without_event_requires_batch_identity',409);
  $trigger_identity=$trigger!==''?$trigger:($claim_only?'claim_readback_only':$batch_key);
  return ['eligible'=>true,'work_hash'=>self::hash($semantic),
    'slot_hash'=>self::hash($slot),
    'job'=>$job,'workflow_type'=>'content_production',
    'scope'=>['job_id'=>$jobid,'site_uuid'=>$job['site_uuid'],
      'tenant_id'=>$job['tenant_id'],'brand_id'=>$job['brand_id'],
      'job_revision'=>(int)$job['job_revision'],
      'source_company'=>'','source_record_id'=>$jobid],
    'trigger_hash'=>self::hash([
      'source'=>$p['trigger_source']??'direct_intake','receipt'=>$trigger_identity]),
    'trigger_source'=>(string)($p['trigger_source']??'direct_intake')];
 }
 /** Must run inside the original enqueue transaction before inserting task row.
  * The DB PRIMARY KEY protects concurrent batches without TOCTOU.
  */
 static function reserve(array $item,$taskid,$batchid){
  if(!self::applies_to($item))return ['decision'=>'ORIGINAL_UNCERTIFIED_ROUTE_UNCHANGED'];
  global $wpdb;[$flights,$triggers]=self::tables();
  $plan=self::plan($item);
  if(is_wp_error($plan))return $plan;
  if(!$plan['eligible'])return ['decision'=>'UNSCOPED_OTHER_WORKFLOW'];
  $taskTable=$wpdb->prefix.'mad4b_hub_bulk_tasks';
  $scope=$plan['scope'];
  $occupied=$wpdb->get_row($wpdb->prepare(
    "SELECT work_hash,leader_task_id FROM $flights WHERE slot_hash=%s FOR UPDATE",
    $plan['slot_hash']),ARRAY_A);
  if(is_array($occupied)){
    return hash_equals((string)$occupied['work_hash'],$plan['work_hash'])
      ?self::error('existing_single_flight_requires_follower_handoff',409)
      :self::error('overlapping_iteration_requires_review',409);
  }
  // Legacy active tasks are not assigned speculative equivalence. Pause new
  // admissions until an original provider supplies historical proof.
  $legacy=(int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM $taskTable q
      WHERE q.content_job_id=%s AND q.stage=%s AND q.operation=%s AND q.target_ref=%s
     AND q.state IN ('QUEUED','RETRY','WAITING_EXECUTOR','WAITING_EXTERNAL',
                   'RUNNING','NEEDS_RECONCILIATION','CANCEL_REQUESTED')
     AND NOT EXISTS (SELECT 1 FROM $flights f WHERE f.leader_task_id=q.task_id)",
    $item['job_id'],$item['stage'],$item['operation'],$item['target_ref']));
  if($legacy>0)return self::error('legacy_active_work_requires_reconciliation',409);
  $other=(int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM $taskTable q
      INNER JOIN $flights f ON f.leader_task_id=q.task_id
      WHERE q.content_job_id=%s AND q.stage=%s AND q.operation=%s AND q.target_ref=%s
        AND f.work_hash<>%s
        AND q.state IN ('QUEUED','RETRY','WAITING_EXECUTOR','WAITING_EXTERNAL',
                        'RUNNING','NEEDS_RECONCILIATION','CANCEL_REQUESTED')",
    $item['job_id'],$item['stage'],$item['operation'],$item['target_ref'],
    $plan['work_hash']));
  if($other>0)return self::error('overlapping_iteration_requires_review',409);
  $ok=$wpdb->query($wpdb->prepare(
    "INSERT IGNORE INTO $flights
     (work_hash,slot_hash,job_id,site_uuid,tenant_id,brand_id,job_revision,
      workflow_type,source_company,source_record_id,leader_task_id,admitted_at)
     VALUES (%s,%s,%s,%s,%s,%s,%d,%s,%s,%s,%s,%s)",
    $plan['work_hash'],$plan['slot_hash'],$scope['job_id'],$scope['site_uuid'],$scope['tenant_id'],
    $scope['brand_id'],(int)$scope['job_revision'],$plan['workflow_type'],
    $scope['source_company'],$scope['source_record_id'],$taskid,gmdate('Y-m-d H:i:s')));
  if($ok!==1){
   if($ok===0){
    $collision=$wpdb->get_row($wpdb->prepare(
      "SELECT work_hash FROM $flights WHERE slot_hash=%s FOR UPDATE",
      $plan['slot_hash']),ARRAY_A);
    return is_array($collision)&&hash_equals((string)$collision['work_hash'],$plan['work_hash'])
       ?self::error('existing_single_flight_requires_follower_handoff',409)
       :self::error('overlapping_iteration_requires_review',409);
   }
   return self::error('atomic_reservation_failed',500);
  }
  $event=$wpdb->insert($triggers,[
    'work_hash'=>$plan['work_hash'],'trigger_hash'=>$plan['trigger_hash'],
    'trigger_source'=>$plan['trigger_source'],'received_at'=>gmdate('Y-m-d H:i:s')]);
  if($event!==1)return self::error('trigger_receipt_insert_failed',500);
  return ['decision'=>'ADMITTED_SINGLE_LEADER','work_hash'=>$plan['work_hash']];
 }

 /** Fail closed: only the original exact leader reserved by admission may claim. */
 static function claim_eligible(array $row){
  if(!self::enabled()||!self::applies_to($row))return true;
  if(!self::ready())return false;
  $payload=json_decode((string)($row['payload_json']??''),true);
  if(!is_array($payload))return false;
  $plan=self::plan(['job_id'=>$row['content_job_id']??'',
    'stage'=>$row['stage']??'','operation'=>$row['operation']??'',
    'target_ref'=>$row['target_ref']??'','payload'=>$payload,
    'depends_on'=>json_decode((string)($row['dependencies_json']??'[]'),true)],true);
  if(!is_wp_error($plan)&&($plan['eligible']??false)){
    global $wpdb;[$f,]=self::tables();
    $leader=$wpdb->get_var($wpdb->prepare(
       "SELECT leader_task_id FROM $f WHERE work_hash=%s AND slot_hash=%s",
        $plan['work_hash'],$plan['slot_hash']));
    if(is_string($leader) && hash_equals($leader,(string)($row['task_id']??'')))
      return true;
  }
  // Snapshot-only continuity: do NOT infer semantic equivalence or issue
  // original permissions. Executor, lease and ContentJob guards remain active.
  return class_exists('MAD4B_Hub_Semantic_Legacy_Cutover')
       && MAD4B_Hub_Semantic_Legacy_Cutover::claim_allowed($row);
 }
 /** Record an observed follower trigger, not another executable task.
  * Call only AFTER original enqueue transaction has been rolled back.
  */
 static function record_duplicate_trigger(array $item){
  $plan=self::plan($item);
  if(is_wp_error($plan)||!($plan['eligible']??false))
   return is_wp_error($plan)?$plan:self::error('follower_not_eligible',409);
  global $wpdb;[$f,$t]=self::tables();
  if(false===$wpdb->query('START TRANSACTION'))return self::error('follower_tx_failed',503);
  $flight=$wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $f WHERE work_hash=%s AND slot_hash=%s FOR UPDATE",
      $plan['work_hash'],$plan['slot_hash']),ARRAY_A);
  $scope=$plan['scope'];
  if(!$flight || ($flight['job_id']??'')!==$scope['job_id']
    ||($flight['site_uuid']??'')!==$scope['site_uuid']
    ||($flight['tenant_id']??'')!==$scope['tenant_id']
    ||($flight['brand_id']??'')!==$scope['brand_id']
    ||(int)($flight['job_revision']??-1)!==(int)$scope['job_revision']
    ||($flight['workflow_type']??'')!==$plan['workflow_type']
    ||($flight['source_company']??'')!==$scope['source_company']
    ||($flight['source_record_id']??'')!==$scope['source_record_id']){
    $wpdb->query('ROLLBACK');return self::error('follower_scope_or_flight_invalid',409);
  }
  $written=$wpdb->query($wpdb->prepare(
    "INSERT IGNORE INTO $t
     (work_hash,trigger_hash,trigger_source,received_at) VALUES (%s,%s,%s,%s)",
    $plan['work_hash'],$plan['trigger_hash'],$plan['trigger_source'],gmdate('Y-m-d H:i:s')));
  if($written!==0 && $written!==1){
   $wpdb->query('ROLLBACK');return self::error('follower_receipt_failed',500);
  }
  if(false===$wpdb->query('COMMIT'))return self::error('follower_commit_uncertain',503);
  return ['contract'=>self::CONTRACT,'decision'=>'DUPLICATE_TRIGGER_RECORDED',
     'leader_task_id'=>$flight['leader_task_id'],
     'work_hash'=>$plan['work_hash'],'trigger_newly_recorded'=>$written===1,
     'execution_performed'=>false,'permission_extended'=>false];
 }

}
