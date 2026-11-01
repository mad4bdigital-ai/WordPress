<?php
defined('ABSPATH') || exit;

/**
 * MAD4B Hub Bulk Queue v1.
 * Durable, site-scoped, stage-agnostic orchestration work items.
 * Claiming a task never executes a provider, Ability, publication or pricing mutation.
 * The authorized ChatGPT Client or registered executor must perform and acknowledge work.
 */
final class MAD4B_Hub_Bulk_Jobs_Queue {
    const CONTRACT='mad4b.hub.bulk-jobs-queue.v1';
    const MAX_ITEMS=75;
    const MAX_LEASE=900;
    const DEFAULT_LEASE=180;

    public static function boot() {
        add_action('rest_api_init',array(__CLASS__,'routes'),48);
        add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),48);
        add_action('admin_menu',array(__CLASS__,'menu'),24);
    }
    public static function readable() {
        return class_exists('MAD4B_Content_Hub') && MAD4B_Content_Hub::permission();
    }
    public static function writable() {
        return self::readable() && class_exists('MAD4B_SCP_Policy') && MAD4B_SCP_Policy::can_mutate();
    }
    private static function fail($code,$http=409) {
        return new WP_Error('mad4b_bulk_'.$code,$code,array('status'=>$http,'contract'=>self::CONTRACT));
    }
    private static function tables() {
        global $wpdb;
        return array($wpdb->prefix.'mad4b_hub_bulk_batches',$wpdb->prefix.'mad4b_hub_bulk_tasks');
    }
    private static function ready() {
        global $wpdb;
        list($b,$t)=self::tables();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($b)))===$b
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))===$t;
    }
    public static function install() {
        if(!self::writable()) return self::fail('permission',403);
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        list($b,$t)=self::tables();
        $collate=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $b (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
batch_id char(36) NOT NULL,
batch_key varchar(191) NOT NULL,
request_hash char(64) NOT NULL,
state varchar(24) NOT NULL DEFAULT 'ACTIVE',
max_concurrency tinyint unsigned NOT NULL DEFAULT 2,
item_count smallint unsigned NOT NULL DEFAULT 0,
created_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY batch_id (batch_id),
UNIQUE KEY batch_key (batch_key),
KEY state_created (state,created_at)
) ENGINE=InnoDB $collate;");
        dbDelta("CREATE TABLE $t (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
task_id char(36) NOT NULL,
batch_id char(36) NOT NULL,
task_key varchar(120) NOT NULL,
content_job_id char(36) NOT NULL DEFAULT '',
target_ref varchar(191) NOT NULL DEFAULT '',
stage varchar(64) NOT NULL,
operation varchar(128) NOT NULL,
priority tinyint unsigned NOT NULL DEFAULT 5,
state varchar(32) NOT NULL DEFAULT 'QUEUED',
dependencies_json longtext NOT NULL,
payload_json longtext NOT NULL,
result_json longtext NULL,
max_attempts tinyint unsigned NOT NULL DEFAULT 3,
attempts tinyint unsigned NOT NULL DEFAULT 0,
available_at datetime NOT NULL,
lease_owner varchar(100) NOT NULL DEFAULT '',
lease_hash char(64) NOT NULL DEFAULT '',
lease_expires_at datetime NULL,
last_error varchar(500) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY task_id (task_id),
UNIQUE KEY batch_task (batch_id,task_key),
KEY ready_tasks (state,available_at,priority),
KEY batch_state (batch_id,state),
KEY content_job_stage (content_job_id,stage)
) ENGINE=InnoDB $collate;");
        return array('contract'=>self::CONTRACT,'installed'=>self::ready(),'tables'=>array($b,$t));
    }
    public static function stages() {
        $core=class_exists('MAD4B_SCP_Content_Jobs') ? MAD4B_SCP_Content_Jobs::stages():array();
        $extended=array('SOURCE_DISCOVERY','PROVIDER_RESEARCH','SOURCE_INGESTION','ENTITY_RESOLUTION','CONTENT_ENRICHMENT','AVAILABILITY',
           'CANONICAL_MERGE','CANONICAL_RELEASE','MEDIA_RIGHTS','PRICING','RATE_GENERATION',
           'WPML_TRANSLATION','JETENGINE_SYNC','COMPANY_DISTRIBUTION','CHECKOUT_QA',
           'SEO_CONTENT_GRAPH','ANALYTICS_FEEDBACK','POST_PUBLISH_MONITORING','TEMPLATE_PROVISIONING','RECONCILIATION','INTEGRATION_QA',
           'CONTEXT_ASSEMBLY','PROMPT_ENGINEERING','PROMPT_INJECTION_DEFENSE','OUTPUT_CONTRACT_QA');
        return array_values(array_unique(array_merge($core,$extended)));
    }
    private static function id($id) {
        return is_string($id) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id);
    }
    private static function normalized($input) {
        if(!is_array($input))return self::fail('invalid_input',400);
        $key=trim((string)($input['batch_key']??''));
        if(!preg_match('/^[A-Za-z0-9._:-]{8,120}$/D',$key))return self::fail('invalid_batch_key',400);
        $items=$input['items']??null;
        if(!is_array($items)||!count($items)||count($items)>self::MAX_ITEMS || array_values($items)!==$items)
            return self::fail('invalid_items',400);
        $max=(int)($input['max_concurrency']??2);
        if($max<1||$max>8)return self::fail('invalid_concurrency',400);
        $norm=array();$keys=array();
        foreach($items as $i=>$item) {
            if(!is_array($item))return self::fail('invalid_item_'.$i,400);
            $k=(string)($item['task_key']??'');
            $s=strtoupper(trim((string)($item['stage']??'')));
            $op=strtolower(trim((string)($item['operation']??'stage_work')));
            $job=strtolower(trim((string)($item['job_id']??'')));
            $target=trim((string)($item['target_ref']??''));
            $deps=$item['depends_on']??array();
            $payload=$item['payload']??array();
            if(!preg_match('/^[A-Za-z0-9._:-]{1,120}$/D',$k)||isset($keys[$k]))
                return self::fail('duplicate_or_invalid_task_key',400);
            if(!in_array($s,self::stages(),true))return self::fail('unsupported_stage_'.$i,400);
            if(!preg_match('/^[a-z][a-z0-9._:-]{2,127}$/D',$op))return self::fail('invalid_operation',400);
            if($job!==''&&!self::id($job))return self::fail('invalid_job_id',400);
            if($job===''&&($target===''||strlen($target)>191))
                return self::fail('missing_target_ref',400);
            if($target!=='' && strlen($target)>191)return self::fail('invalid_target_ref',400);
            if(!is_array($deps)||count($deps)>20||array_values($deps)!==$deps)return self::fail('invalid_dependencies',400);
            foreach($deps as $d)if(!is_string($d)||!preg_match('/^[A-Za-z0-9._:-]{1,120}$/D',$d)||$d===$k)
                return self::fail('invalid_dependency',400);
            $deps=array_values(array_unique($deps));
            if(!is_array($payload)||strlen(wp_json_encode($payload))>16000)return self::fail('invalid_payload',400);
            if(isset($payload['assigned_executor']) &&
               !in_array($payload['assigned_executor'],array('mcp_client','vps_runner','action_scheduler'),true))
                return self::fail('invalid_task_executor',400);
            if(($payload['assigned_executor']??'mcp_client')==='action_scheduler') {
                if(!in_array($op,array('runtime_resolve','site_health','workflow_inspect',
                       'content_job_read','source_sync_verify'),true))
                    return self::fail('action_scheduler_operation_not_allowlisted',403);
            }
            if(($payload['assigned_executor']??'mcp_client')==='vps_runner') {
                $safe=in_array($op,array('source_sync_verify','source_sync_run_queued','canonical_gap_scan',
                    'site_health','workflow_inspect','content_job_read','runtime_resolve','ai_context_preflight','ai_guard_stage'),true);
                $approved=$op==='stage_work' &&
                    class_exists('MAD4B_Hub_Dynamic_Runtime_Resolver') &&
                    MAD4B_Hub_Dynamic_Runtime_Resolver::server_adapter(
                        $s,(string)($payload['stage_adapter_id']??''),
                        array_merge($payload,['target_ref'=>$target,'content_job_id'=>$job]))!==null &&
                    (string)($payload['stage_adapter_id']??'')!=='';
                if($op==='ai_guard_stage')$safe=$safe
                     &&class_exists('MAD4B_Hub_AI_Queue_Pipeline')
                     &&in_array($s,MAD4B_Hub_AI_Queue_Pipeline::STAGES,true)
                     &&$job!=='' && ($payload['contract']??'')===MAD4B_Hub_AI_Queue_Pipeline::CONTRACT
                     &&($payload['content_job_id']??'')===$job
                     &&($payload['stage']??'')===$s
                     &&($payload['model_execution_authorized']??true)===false;
                if(!$safe&&!$approved)return self::fail('vps_operation_not_allowlisted',403);
            }
            $pri=(int)($item['priority']??5);$tries=(int)($item['max_attempts']??3);
            if($pri<1||$pri>9||$tries<1||$tries>5)return self::fail('invalid_policy',400);
            $keys[$k]=true;
            $norm[]=array('task_key'=>$k,'stage'=>$s,'operation'=>$op,
                'job_id'=>$job,'target_ref'=>$target,'depends_on'=>$deps,
                'payload'=>$payload,'priority'=>$pri,'max_attempts'=>$tries);
        }
        foreach($norm as $item)foreach($item['depends_on'] as $d)
            if(!isset($keys[$d]))return self::fail('dependency_not_in_batch',400);
        $done=array();$remaining=$norm;
        while($remaining) {
            $progress=false;
            foreach($remaining as $i=>$item) {
                if(array_diff($item['depends_on'],array_keys($done)))continue;
                $done[$item['task_key']]=true;unset($remaining[$i]);$progress=true;
            }
            if(!$progress)return self::fail('dependency_cycle',400);
        }
        return array('batch_key'=>$key,'max_concurrency'=>$max,'items'=>$norm);
    }
    public static function enqueue($input) {
        if(!self::writable())return self::fail('permission',403);
        if(!self::ready())return self::fail('schema_missing',503);
        $n=self::normalized($input);if(is_wp_error($n))return $n;
        // The only queue-ingress boundary: bind a source-derived Workflow Origin
        // before calculating the idempotency hash or writing any batch/task.
        $n=MAD4B_Hub_Workflow_Origin::normalize_batch($n);
        if(is_wp_error($n))return $n;
        foreach($n['items'] as $it) {
            if(!$it['job_id'])continue;
            $r=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$it['job_id']));
            if(is_wp_error($r))return $r;
            if(empty($r['job']['job_id'])||$r['job']['job_id']!==$it['job_id'])
                return self::fail('out_of_scope_job',403);
        }
        global $wpdb;list($b,$t)=self::tables();
        // A disabled candidate performs no extra DB access or writes.
        $semanticFlight=class_exists('MAD4B_Hub_Governed_Single_Flight')
            && MAD4B_Hub_Governed_Single_Flight::enabled();
        if($semanticFlight && !MAD4B_Hub_Governed_Single_Flight::ready())
            return self::fail('semantic_flight_storage_unavailable',503);
        if($semanticFlight){
            if(!class_exists('MAD4B_Hub_Original_Context_Evidence'))
                return self::fail('original_evidence_provider_missing',503);
            $n=MAD4B_Hub_Original_Context_Evidence::bind_batch($n);
            if(is_wp_error($n))return $n;
        }
        $hash=hash('sha256',wp_json_encode($n));
        $now=gmdate('Y-m-d H:i:s');
        $batch=wp_generate_uuid4();
        if(false===$wpdb->query('START TRANSACTION'))
            return self::fail('transaction_unavailable',503);
        $inserted=$wpdb->insert($b,array('batch_id'=>$batch,'batch_key'=>$n['batch_key'],
            'request_hash'=>$hash,'max_concurrency'=>$n['max_concurrency'],
            'item_count'=>count($n['items']),'created_by'=>get_current_user_id(),
            'created_at'=>$now,'updated_at'=>$now));
        if($inserted!==1) {
            $wpdb->query('ROLLBACK');
            $prior=$wpdb->get_row($wpdb->prepare("SELECT batch_id,request_hash FROM $b WHERE batch_key=%s",$n['batch_key']),ARRAY_A);
            if(!$prior)return self::fail('batch_insert_failed',500);
            if(!hash_equals($prior['request_hash'],$hash))return self::fail('idempotency_conflict',409);
            $result=self::list_queue(array('batch_id'=>$prior['batch_id']));
            if(!is_wp_error($result))$result['replayed']=true;
            return $result;
        }
        foreach($n['items'] as $it) {
            $it=MAD4B_Hub_Queue_Lifecycle::prepare_enqueue($it);
            if(is_wp_error($it)){$wpdb->query('ROLLBACK');return $it;}
            $route=MAD4B_Hub_Workflow_Origin::executor_guard($it,
                (string)$it['payload']['assigned_executor']);
            if(is_wp_error($route)){$wpdb->query('ROLLBACK');return $route;}
            $taskId=wp_generate_uuid4();
            if($semanticFlight && MAD4B_Hub_Governed_Single_Flight::applies_to($it)){
                // Verified normalized batch identity is receipt-only, not semantic.
                $it['_batch_key']=$n['batch_key'];
                $admit=MAD4B_Hub_Governed_Single_Flight::reserve($it,$taskId,$batch);
                if(is_wp_error($admit)){
                    $wpdb->query('ROLLBACK');
                    if($admit->get_error_code()==='mad4b_hub_flight_existing_single_flight_requires_follower_handoff'){
                        // A multi-item Batch or unverified predecessor DAG must not be
                        // partially accepted by recording only one follower trigger.
                        if(count($n['items'])!==1 || !empty($it['depends_on']))
                            return self::fail('follower_dependency_or_batch_review_required',409);
                        // Link the follow-up trigger after rollback; never create a second task.
                        $receipt=MAD4B_Hub_Governed_Single_Flight::record_duplicate_trigger($it);
                        if(is_wp_error($receipt))return $receipt;
                        return new WP_Error('mad4b_bulk_single_flight_follower',
                          'Same semantic work is owned by an existing leader.',
                          ['status'=>409,'receipt'=>$receipt,'execution_performed'=>false]);
                    }
                    return $admit;
                }
            }
            $row=array('task_id'=>$taskId,'batch_id'=>$batch,
                'task_key'=>$it['task_key'],'content_job_id'=>$it['job_id'],
                'target_ref'=>$it['target_ref'],'stage'=>$it['stage'],
                'operation'=>$it['operation'],'priority'=>$it['priority'],
                'state'=>$it['initial_state'],
                'dependencies_json'=>wp_json_encode($it['depends_on']),
                'payload_json'=>wp_json_encode($it['payload']),'max_attempts'=>$it['max_attempts'],
                'available_at'=>$now,'created_at'=>$now,'updated_at'=>$now);
            if($wpdb->insert($t,$row)!==1) {
                $wpdb->query('ROLLBACK');
                return self::fail('task_insert_failed',500);
            }
        }
        $wpdb->query('COMMIT');
        return self::list_queue(array('batch_id'=>$batch));
    }
    /** Per-Job cancellation, review and stage fence; does not affect other jobs in a batch. */
    public static function sync_job_state($job){
        if(!self::writable())return self::fail('permission',403);
        if(!self::ready())return self::fail('schema_missing',503);
        if(!is_array($job)||!self::id((string)($job['job_id']??'')))
            return self::fail('invalid_job_sync',400);
        $id=(string)$job['job_id'];$stage=(string)$job['stage'];
        $state=(string)$job['state'];
        $r=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$id));
        if(is_wp_error($r))return $r;
        if(($r['job']['stage']??'')!==$stage||($r['job']['state']??'')!==$state
           ||(int)($r['job']['job_revision']??0)!==(int)$job['job_revision'])
            return self::fail('job_sync_stale',409);
        global $wpdb;list($b,$t)=self::tables();$now=gmdate('Y-m-d H:i:s');
        $pending="'QUEUED','RETRY','WAITING_JOB','WAITING_EXECUTOR','WAITING_EXTERNAL'";
        $wpdb->query('START TRANSACTION');
        if(in_array($state,array('COMPLETED','CANCELLED','FAILED'),true)){
            $wpdb->query($wpdb->prepare("UPDATE $t SET state='CANCELLED',updated_at=%s WHERE content_job_id=%s AND state IN ($pending)",$now,$id));
            $wpdb->query($wpdb->prepare("UPDATE $t SET state='CANCEL_REQUESTED',updated_at=%s WHERE content_job_id=%s AND state='RUNNING'",$now,$id));
        }elseif(in_array($state,array('BLOCKED','WAITING_REVIEW'),true)){
            $wpdb->query($wpdb->prepare("UPDATE $t SET state='WAITING_JOB',updated_at=%s WHERE content_job_id=%s AND state IN ('QUEUED','RETRY')",$now,$id));
        }else{
            // Keep future-stage tasks in the Queue; fence only stages already passed.
            $sequence=MAD4B_SCP_Content_Jobs::stages();
            $order=array_flip($sequence);
            $rank=$order[$stage]??-1;
            $tasks=$wpdb->get_results($wpdb->prepare(
                "SELECT id,stage,state FROM $t WHERE content_job_id=%s AND state IN ($pending)",
                $id),ARRAY_A);
            $phase_map=array('PRICING'=>'FINAL_QA',
                'WPML_TRANSLATION'=>'PUBLISHING',
                'COMPANY_DISTRIBUTION'=>'POST_PUBLISH');
            foreach((array)$tasks as $task) {
                $taskstage=$task['stage'];
                $taskrank=$order[$phase_map[$taskstage]??$taskstage]??-1;
                $live_ok=$taskstage===$stage ||
                    (class_exists('MAD4B_Hub_Six_Stage_Adapters') &&
                     MAD4B_Hub_Six_Stage_Adapters::job_stage_allowed($taskstage,$job));
                if($live_ok&&$task['state']==='WAITING_JOB'){
                    $wpdb->update($t,array('state'=>'QUEUED','updated_at'=>$now),
                      array('id'=>$task['id'],'state'=>'WAITING_JOB'));
                }elseif(!$live_ok && $taskrank>=0 && $rank>=0 && $taskrank<$rank){
                    $wpdb->update($t,array('state'=>'CANCELLED','updated_at'=>$now),
                      array('id'=>$task['id'],'state'=>$task['state']));
                }
            }
        }
        if(false===$wpdb->query('COMMIT'))return self::fail('job_sync_commit_failed',500);
        return array('contract'=>self::CONTRACT,'job_id'=>$id,'state'=>$state,'stage'=>$stage,'synchronized'=>true);
    }

    public static function list_queue($input=array()) {
        if(!self::readable())return self::fail('permission',403);
        if(!self::ready())return self::fail('schema_missing',503);
        global $wpdb;list($b,$t)=self::tables();
        $batch=(string)($input['batch_id']??'');
        if($batch!==''&&!self::id($batch))return self::fail('invalid_batch_id',400);
        $jobId=strtolower(trim((string)($input['job_id']??'')));
        if($jobId!==''&&!self::id($jobId))return self::fail('invalid_job_id',400);
        if($jobId!==''){
            $job=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$jobId));
            if(is_wp_error($job))return $job;
        }
        $batches=$batch!==''?
            ($jobId!==''?
              $wpdb->get_results($wpdb->prepare("SELECT b.* FROM $b b WHERE b.batch_id=%s
                AND EXISTS(SELECT 1 FROM $t q WHERE q.batch_id=b.batch_id AND q.content_job_id=%s)",
                $batch,$jobId),ARRAY_A):
              $wpdb->get_results($wpdb->prepare("SELECT * FROM $b WHERE batch_id=%s",$batch),ARRAY_A)):
            ($jobId!==''?
              $wpdb->get_results($wpdb->prepare("SELECT b.* FROM $b b WHERE EXISTS
                 (SELECT 1 FROM $t q WHERE q.batch_id=b.batch_id AND q.content_job_id=%s)
                 ORDER BY b.id DESC LIMIT 50",$jobId),ARRAY_A):
              $wpdb->get_results("SELECT * FROM $b ORDER BY id DESC LIMIT 25",ARRAY_A));
        $out=array();
        foreach((array)$batches as $row) {
            $id=$row['batch_id'];
            $columns="task_id,task_key,content_job_id,target_ref,stage,operation,priority,state,attempts,max_attempts,dependencies_json,payload_json,available_at,lease_owner,lease_expires_at,last_error,updated_at";
            $tasks=$jobId!==''?
                $wpdb->get_results($wpdb->prepare("SELECT $columns FROM $t WHERE batch_id=%s AND content_job_id=%s ORDER BY priority DESC,id ASC LIMIT 100",$id,$jobId),ARRAY_A):
                $wpdb->get_results($wpdb->prepare("SELECT $columns FROM $t WHERE batch_id=%s ORDER BY priority DESC,id ASC LIMIT 100",$id),ARRAY_A);
            $counts=array();
            $tasks=is_array($tasks)?$tasks:array();
            foreach($tasks as &$task) {
                $task['depends_on']=json_decode($task['dependencies_json'],true)?:array();
                $payload=json_decode((string)$task['payload_json'],true);
                $task['assigned_executor']=is_array($payload)
                    ?(string)($payload['assigned_executor']??'mcp_client'):'';
                $origin=is_array($payload)?($payload['workflow_origin']??null):null;
                $valid=false;
                if(is_array($origin) && ($origin['contract']??'')==='mad4b.hub.workflow-origin.v1'){
                    $digest=(string)($origin['origin_sha256']??'');
                    unset($origin['origin_sha256']);
                    $valid=preg_match('/^[a-f0-9]{64}$/D',$digest)===1
                        &&hash_equals($digest,hash('sha256',wp_json_encode($origin,JSON_UNESCAPED_SLASHES)));
                }
                $task['workflow_origin']=$valid?array(
                  'status'=>'source_bound_at_enqueue',
                  'workflow_type'=>$origin['workflow_type'],
                  'origin_type'=>$origin['origin_type'],
                  'source_record_id'=>$origin['source_record_id'],
                  'company_id'=>$origin['company_id'],
                  'trigger_event_type'=>$origin['trigger_event_type'],
                  'required_capability'=>$origin['required_capability'],
                  'executor_lane'=>$origin['executor_lane'],
                  'source_verified'=>$origin['source_verified']
                ):array('status'=>'legacy_origin_not_recorded_at_enqueue');
                unset($task['dependencies_json'],$task['payload_json']);
                $counts[$task['state']]=($counts[$task['state']]??0)+1;
            }
            unset($task);
            $out[]=array('batch_id'=>$id,'batch_key'=>$row['batch_key'],'state'=>$row['state'],
                'max_concurrency'=>(int)$row['max_concurrency'],
                'item_count'=>(int)$row['item_count'],'counts'=>$counts,'tasks'=>$tasks,
                'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']);
        }
        return array('contract'=>self::CONTRACT,'batches'=>$out,'count'=>count($out),
           'linked_content_job_id'=>$jobId,'mode'=>'mcp_client_claim_and_ack',
           'automatic_execution_performed'=>false);
    }
    public static function claim($input) {
        if(!self::writable())return self::fail('permission',403);
        if(!self::ready())return self::fail('schema_missing',503);
        $owner=(string)($input['executor_id']??'mcp_client');
        $batch=(string)($input['batch_id']??'');
        $exact_task=(string)($input['task_id']??'');
        $lease=(int)($input['lease_seconds']??self::DEFAULT_LEASE);
        // Readback-only provider_bridge is a distinct WordPress executor.
        // It must request one exact eligible task, never claim a broad queue.
        if($owner==='provider_bridge'&&(!self::id($exact_task)||!self::id($batch)))
            return self::fail('bridge_exact_task_and_batch_required',403);
        if(!preg_match('/^[a-z][a-z0-9._:-]{2,99}$/D',$owner) ||
           ($batch!==''&&!self::id($batch))||$lease<60||$lease>self::MAX_LEASE)
            return self::fail('invalid_claim',400);
        // A registered external executor must be enabled explicitly; client remains default.
        if(!in_array($owner,array('mcp_client','chatgpt_client'),true)&&!apply_filters('mad4b_hub_bulk_authorized_executor',false,$owner))
            return self::fail('executor_not_enabled',403);
        global $wpdb;list($b,$t)=self::tables();
        // Global per-site mutex serializes capacity checks across ALL batches.
        // Acquired before row locks to avoid lock inversion / double-claims.
        $lock='mad4b_hub_adaptive_claim_'.md5(DB_NAME.'|'.$wpdb->prefix);
        $acquired=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,1)',$lock));
        if($acquired!==1)return self::fail('global_claim_busy',429);
        $txOpen=false;
        try {
        $budget=MAD4B_Hub_Adaptive_Concurrency::status(true);
        if((int)($budget['effective']??0)<1){
            return ['contract'=>self::CONTRACT,'claimed'=>false,
                'reason'=>'adaptive_backpressure','effective_global_limit'=>0,
                'adaptive_reason'=>(string)($budget['reason']??'unavailable')];
        }
        $now=gmdate('Y-m-d H:i:s');
        $wpdb->query('START TRANSACTION');
        $txOpen=true;
        // Expired leases might have produced side-effects. Never blindly replay them.
        $wpdb->query($wpdb->prepare("UPDATE $t SET state='NEEDS_RECONCILIATION',updated_at=%s WHERE state IN ('RUNNING','CANCEL_REQUESTED') AND lease_expires_at < %s",$now,$now));
        // Only active leases consume current runtime capacity. Tasks requiring
        // reconciliation stay unreplayed and remain blocked by batch-level logic.
        $active=(int)$wpdb->get_var(
           "SELECT COUNT(*) FROM $t WHERE state IN ('RUNNING','CANCEL_REQUESTED')");
        $effective=(int)($budget['effective']??0);
        if($effective<=0 || $active>=$effective){
            $wpdb->query('COMMIT');$txOpen=false;
            return ['contract'=>self::CONTRACT,'claimed'=>false,
              'reason'=>$effective<=0?'adaptive_backpressure':'global_capacity_reached',
              'effective_global_limit'=>$effective,'active_count'=>$active,
              'adaptive_reason'=>(string)($budget['reason']??'unavailable')];
        }
        $where=$batch!==''?$wpdb->prepare(' AND b.batch_id=%s',$batch):'';
        // The authenticated client can claim directly from WAITING_EXECUTOR.
        // Do not activate an unbounded number of tasks on mere client arrival.
        $claim_states=in_array($owner,['mcp_client','chatgpt_client','provider_bridge'],true)
           ?"'QUEUED','RETRY','WAITING_EXECUTOR'":"'QUEUED','RETRY'";
        // Filter executor in SQL before limiting candidates; otherwise many queued
        // MCP tasks can starve the VPS/Action Scheduler executor indefinitely.
        $expected_owner=in_array($owner,['chatgpt_client','provider_bridge'],true)
          ?'mcp_client':$owner;
        if($exact_task!==''){
          if(!self::id($exact_task))return self::fail('task_id_invalid',400);
          $where.=$wpdb->prepare(' AND q.task_id=%s',$exact_task);
        }
        $where.=$wpdb->prepare(
            " AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(q.payload_json, '$.assigned_executor')), 'mcp_client')=%s",
            $expected_owner);
        $candidates=$wpdb->get_results($wpdb->prepare(
            "SELECT q.*,b.max_concurrency FROM $t q INNER JOIN $b b ON b.batch_id=q.batch_id
            WHERE b.state='ACTIVE' AND q.state IN ($claim_states)
            AND q.available_at <= %s $where ORDER BY q.priority DESC,q.id ASC LIMIT 35 FOR UPDATE",
            $now),ARRAY_A);
        foreach((array)$candidates as $task) {
            // A VPS specialist must never claim an MCP-client task.
            $instructions=json_decode((string)$task['payload_json'],true);
            if(!is_array($instructions))continue;
            $assigned=(string)($instructions['assigned_executor']??'mcp_client');
            if(!preg_match('/^[a-z][a-z0-9._:-]{2,99}$/D',$assigned))continue;
            if($assigned!==$owner && !($owner==='chatgpt_client' && $assigned==='mcp_client')
              && !($owner==='provider_bridge' && $assigned==='mcp_client'))continue;
            if($owner==='provider_bridge'){
               if(!class_exists('MAD4B_Hub_Provider_Receipt_Bridge'))continue;
               $bridge_plan=MAD4B_Hub_Provider_Receipt_Bridge::plan(['task_id'=>$task['task_id']]);
               if(is_wp_error($bridge_plan)||($bridge_plan['state']??'')!=='READY_READBACK')continue;
            }
            if(MAD4B_Hub_Workflow_Origin::executor_guard($task,$assigned)!==true)continue;
            if(MAD4B_Hub_Workflow_Origin::claim_source_guard($task)!==true)continue;
            if($semanticFlightEnabled=(
              class_exists('MAD4B_Hub_Governed_Single_Flight')
              &&MAD4B_Hub_Governed_Single_Flight::enabled())){
              if(!MAD4B_Hub_Governed_Single_Flight::claim_eligible($task))continue;
            }
            // Fail closed if the originating Job is now terminal, blocked, or on another stage.
            if(!empty($task['content_job_id'])){
                $live=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$task['content_job_id']));
                $allowed=!is_wp_error($live)
                   &&in_array($live['job']['state']??'',array('NEW','QUEUED','RUNNING'),true)
                   &&(($live['job']['stage']??'')===$task['stage']
                      ||(class_exists('MAD4B_Hub_Six_Stage_Adapters')
                         &&MAD4B_Hub_Six_Stage_Adapters::job_stage_allowed(
                            $task['stage'],$live['job']))
                      ||(class_exists('MAD4B_Hub_AI_Queue_Pipeline')
                         &&$task['operation']==='ai_guard_stage'
                         &&MAD4B_Hub_AI_Queue_Pipeline::job_allowed(
                           $live['job'],$task['stage'])));
                if(!$allowed)continue;
            }
            // Serialize capacity decisions against the locked batch record.
            $control=$wpdb->get_row($wpdb->prepare("SELECT * FROM $b WHERE batch_id=%s FOR UPDATE",$task['batch_id']),ARRAY_A);
            if(!$control||$control['state']!=='ACTIVE')continue;
            $running=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE batch_id=%s AND state IN ('RUNNING','CANCEL_REQUESTED','NEEDS_RECONCILIATION')",$task['batch_id']));
            if($running>=(int)$control['max_concurrency'])continue;
            $deps=json_decode($task['dependencies_json'],true)?:array();
            if($deps) {
                $rows=$wpdb->get_results($wpdb->prepare("SELECT task_key,state FROM $t WHERE batch_id=%s",$task['batch_id']),ARRAY_A);
                $states=array_column((array)$rows,'state','task_key');
                $okay=true;
                foreach($deps as $d)if(($states[$d]??'')!=='COMPLETED'){$okay=false;break;}
                if(!$okay)continue;
            }
            $token=bin2hex(random_bytes(24));
            $until=gmdate('Y-m-d H:i:s',time()+$lease);
            $updated=$wpdb->update($t,array('state'=>'RUNNING','attempts'=>(int)$task['attempts']+1,
                'lease_owner'=>$owner,'lease_hash'=>hash('sha256',$token),'lease_expires_at'=>$until,
                'updated_at'=>$now),array('id'=>$task['id'],'state'=>$task['state']));
            if($updated!==1)continue;
            $wpdb->query('COMMIT');$txOpen=false;
            return array('contract'=>self::CONTRACT,'claimed'=>true,
                'task_id'=>$task['task_id'],'batch_id'=>$task['batch_id'],
                'task_key'=>$task['task_key'],'content_job_id'=>$task['content_job_id'],
                'target_ref'=>$task['target_ref'],'stage'=>$task['stage'],
                'operation'=>$task['operation'],'payload'=>json_decode($task['payload_json'],true),
                'attempt'=>(int)$task['attempts']+1,'lease_owner'=>$owner,
                'lease_token'=>$token,'lease_expires_at'=>$until,
                'execution_performed'=>false,'requires_stage_specific_ability'=>true);
        }
        $wpdb->query('COMMIT');$txOpen=false;
        return array('contract'=>self::CONTRACT,'claimed'=>false,'reason'=>'no_runnable_task');
        } finally {
            if($txOpen)$wpdb->query('ROLLBACK');
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    /** Read-back evidence required before ANY executor can acknowledge success. */
    private static function verify_result(array $task,array $result) {
        if(($result['verified']??false)!==true
           ||($result['stage']??'')!==$task['stage']
           ||($result['operation']??'')!==$task['operation'])
            return self::fail('result_receipt_invalid',409);
        $op=$task['operation'];
        if($op==='site_health') {
            return ($result['result_kind']??'')==='diagnostic_only'
                &&class_exists('MAD4B_SCP_Schema')&&MAD4B_SCP_Schema::critical_ready();
        }
        if($op==='workflow_inspect') {
            if(!$task['content_job_id']||($result['job_id']??'')!==$task['content_job_id'])
                return self::fail('result_job_scope_invalid',409);
            $recheck=MAD4B_Hub_Workflow_Operations::inspect(
              array('job_id'=>$task['content_job_id']));
            if(is_wp_error($recheck))return $recheck;
            return ($result['result_kind']??'')==='readback_only'
              &&(int)($result['job_revision']??0)===(int)($recheck['job_revision']??-1);
        }
        if($op==='content_job_read') {
            $recheck=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$task['content_job_id']));
            if(is_wp_error($recheck))return $recheck;
            return ($result['result_kind']??'')==='readback_only'
              &&($result['job_id']??'')===$task['content_job_id']
              &&(int)($result['job_revision']??0)===(int)($recheck['job']['job_revision']??-1);
        }
        if($op==='runtime_resolve') {
            $context=json_decode((string)$task['payload_json'],true);
            if(!is_array($context))return self::fail('invalid_resolution_payload',409);
            $plan=MAD4B_Hub_Dynamic_Runtime_Resolver::resolve(array(
              'stage'=>$task['stage'],'job_id'=>$task['content_job_id'],
              'target_ref'=>$task['target_ref'],
              'provider'=>$context['provider']??'',
              'capability_query'=>$context['capability_query']??'',
              'stage_input'=>$context['stage_input']??array(),
              'stage_ability'=>$context['stage_ability']??''));
            if(is_wp_error($plan))return $plan;
            return ($result['result_kind']??'')==='runtime_plan_verified'
                &&($result['resolution_sha256']??'')===$plan['resolution_sha256'];
        }
        if($op==='ai_context_preflight') {
            return class_exists('MAD4B_Hub_AI_Guarded_Preflight')
              && MAD4B_Hub_AI_Guarded_Preflight::verify($task,$result);
        }
        if($op==='ai_guard_stage') {
            return class_exists('MAD4B_Hub_AI_Queue_Pipeline')
                && MAD4B_Hub_AI_Queue_Pipeline::verify($task,$result);
        }
        if($op==='source_sync_verify') {
            $id=(int)($result['source_id']??0);
            if($id<1||$task['target_ref']!=='post:'.$id)return self::fail('source_scope_invalid',409);
            $source=MAD4B_Hub_Source_Reader::content_candidate($id);
            if(is_wp_error($source))return $source;
            return ($result['result_kind']??'')==='source_projection_verified'
                &&($source['candidate']['source_id']??0)===$id
                &&hash_equals((string)$source['source_version'],(string)($result['source_version_sha256']??''));
        }
        if(empty($result['evidence_ref']))return self::fail('stage_evidence_missing',409);
        // Explicit stage adapter verifier is the only route to ack a mutating stage.
        return apply_filters('mad4b_hub_bulk_verify_stage_receipt',false,$result,$task)===true;
    }
    public static function finish($input) {
        if(!self::writable())return self::fail('permission',403);
        if(!self::ready())return self::fail('schema_missing',503);
        $taskid=(string)($input['task_id']??'');
        $owner=(string)($input['executor_id']??'');
        $token=(string)($input['lease_token']??'');
        $success=$input['success']??null;
        $result=$input['result']??array();
        $message=substr((string)($input['error_code']??'stage_work_failed'),0,500);
        if(!self::id($taskid)||!preg_match('/^[a-z][a-z0-9._:-]{2,99}$/D',$owner)||
           !preg_match('/^[a-f0-9]{48}$/D',$token)||!is_bool($success)||
           !is_array($result)||strlen(wp_json_encode($result))>16000)
            return self::fail('invalid_finish',400);
        global $wpdb;list($b,$t)=self::tables();
        $now=gmdate('Y-m-d H:i:s');
        $wpdb->query('START TRANSACTION');
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE task_id=%s FOR UPDATE",$taskid),ARRAY_A);
        if(!$task||$task['state']!=='RUNNING'||$task['lease_owner']!==$owner||
           !hash_equals($task['lease_hash'],hash('sha256',$token))||
           !$task['lease_expires_at']||$task['lease_expires_at']<$now) {
            $wpdb->query('ROLLBACK');
            return self::fail('lease_missing_stale_or_cancelled',409);
        }
        // Never retry a mutation whose effects have not been independently reconciled.
        $safe_retry=in_array($task['operation'],array('workflow_inspect','site_health','content_job_read','runtime_resolve','ai_context_preflight','ai_guard_stage'),true);
        if($success) {
            $verified=self::verify_result($task,$result);
            if(is_wp_error($verified)||$verified!==true) {
                $wpdb->query('ROLLBACK');
                return is_wp_error($verified)?$verified:self::fail('result_readback_unverified',409);
            }
        }
        $terminal=($success&&($result['completion_state']??'')==='pending')?'WAITING_EXTERNAL':($success?'COMPLETED':(
            $message==='executor_not_registered'?'WAITING_EXECUTOR':
            (!$safe_retry?'NEEDS_RECONCILIATION':(
                ((int)$task['attempts'] >= (int)$task['max_attempts'])?'DEAD_LETTER':'RETRY'
            ))
        ));
        $delay=$success?0:min(3600,30*(2**max(0,(int)$task['attempts']-1)));
        $updated=$wpdb->update($t,array('state'=>$terminal,'result_json'=>$success?wp_json_encode($result):null,
           'last_error'=>$terminal==='WAITING_EXTERNAL'?'external_completion_pending':($success?'':$message),
           'available_at'=>gmdate('Y-m-d H:i:s',time()+$delay),
           'lease_owner'=>'','lease_hash'=>'','lease_expires_at'=>null,'updated_at'=>$now),
           array('id'=>$task['id'],'state'=>'RUNNING'));
        if($updated!==1){$wpdb->query('ROLLBACK');return self::fail('finish_write_failed',500);}
        $wpdb->query('COMMIT');
        if(class_exists('MAD4B_Hub_Queue_Lifecycle')&&in_array($terminal,['COMPLETED','CANCELLED','DEAD_LETTER'],true))
            MAD4B_Hub_Queue_Lifecycle::close_batch($task['batch_id'],true);
        do_action('mad4b_hub_bulk_task_finished',array(
            'task_id'=>$taskid,'content_job_id'=>$task['content_job_id'],
            'stage'=>$task['stage'],'operation'=>$task['operation'],
            'state'=>$terminal,'verified_success'=>$terminal==='COMPLETED'));
        return array('contract'=>self::CONTRACT,'task_id'=>$taskid,
          'state'=>$terminal,'attempts'=>(int)$task['attempts'],
          'retry_after_seconds'=>$delay,'acknowledged'=>true);
    }
    /** Authenticated lease heartbeat. A cancelled or expired lease is not extendable. */
    public static function heartbeat($input) {
        if(!self::writable())return self::fail('permission',403);
        if(!self::ready())return self::fail('schema_missing',503);
        $taskid=(string)($input['task_id']??'');
        $owner=(string)($input['executor_id']??'');
        $token=(string)($input['lease_token']??'');
        $seconds=(int)($input['lease_seconds']??self::DEFAULT_LEASE);
        if(!self::id($taskid)||!preg_match('/^[a-z][a-z0-9._:-]{2,99}$/D',$owner)
           ||!preg_match('/^[a-f0-9]{48}$/D',$token)||$seconds<60||$seconds>self::MAX_LEASE)
            return self::fail('invalid_heartbeat',400);
        global $wpdb;list($b,$t)=self::tables();$now=gmdate('Y-m-d H:i:s');
        $wpdb->query('START TRANSACTION');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE task_id=%s FOR UPDATE",$taskid),ARRAY_A);
        if(!$row||$row['lease_owner']!==$owner||!hash_equals($row['lease_hash'],hash('sha256',$token))
           ||!$row['lease_expires_at']||$row['lease_expires_at']<=$now) {
            $wpdb->query('ROLLBACK');return self::fail('heartbeat_lease_invalid',409);
        }
        if($row['state']!=='RUNNING') {
            $wpdb->query('ROLLBACK');
            return self::fail($row['state']==='CANCEL_REQUESTED'?'cancellation_requested':'heartbeat_not_running',409);
        }
        $until=gmdate('Y-m-d H:i:s',time()+$seconds);
        $ok=$wpdb->update($t,array('lease_expires_at'=>$until,'updated_at'=>$now),
            array('id'=>$row['id'],'state'=>'RUNNING'));
        // MySQL reports zero affected rows when the heartbeat is repeated within the same second.
        if($ok===false){$wpdb->query('ROLLBACK');return self::fail('heartbeat_write_failed',500);}
        $wpdb->query('COMMIT');
        return array('contract'=>self::CONTRACT,'task_id'=>$taskid,'lease_extended'=>true,
            'lease_expires_at'=>$until);
    }

    /** Lease-bound parking: an absent stage executor is NOT a completed task. */
    public static function park($input) {
        if(!self::writable())return self::fail('permission',403);
        if(!self::ready())return self::fail('schema_missing',503);
        $taskid=(string)($input['task_id']??'');
        $owner=(string)($input['executor_id']??'');
        $token=(string)($input['lease_token']??'');
        if(!self::id($taskid)||!preg_match('/^[a-z][a-z0-9._:-]{2,99}$/D',$owner)
           ||!preg_match('/^[a-f0-9]{48}$/D',$token))return self::fail('invalid_park',400);
        global $wpdb;list($b,$t)=self::tables();$now=gmdate('Y-m-d H:i:s');
        $wpdb->query('START TRANSACTION');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE task_id=%s FOR UPDATE",$taskid),ARRAY_A);
        if(!$row||$row['state']!=='RUNNING'||$row['lease_owner']!==$owner
           ||!hash_equals($row['lease_hash'],hash('sha256',$token))
           ||!$row['lease_expires_at']||$row['lease_expires_at']<=$now) {
            $wpdb->query('ROLLBACK');return self::fail('park_lease_invalid',409);
        }
        $ok=$wpdb->update($t,array('state'=>'WAITING_EXECUTOR','last_error'=>'stage_executor_not_registered',
           'lease_owner'=>'','lease_hash'=>'','lease_expires_at'=>null,'updated_at'=>$now),
           array('id'=>$row['id'],'state'=>'RUNNING'));
        if($ok!==1){$wpdb->query('ROLLBACK');return self::fail('park_write_failed',500);}
        $wpdb->query('COMMIT');
        return array('contract'=>self::CONTRACT,'task_id'=>$taskid,'state'=>'WAITING_EXECUTOR',
           'execution_performed'=>false);
    }

    /** Manual reconciliation. Requeue requires positive evidence of no side effects. */
    public static function reconcile($input) {
        if(!self::writable())return self::fail('permission',403);
        if(!self::ready())return self::fail('schema_missing',503);
        $id=(string)($input['task_id']??'');
        $action=strtoupper((string)($input['action']??''));
        $reference=trim((string)($input['verification_ref']??''));
        if(!self::id($id)||!in_array($action,array('REQUEUE','CANCEL','DEAD_LETTER'),true)
           ||strlen($reference)>191)return self::fail('invalid_reconciliation',400);
        if($action==='REQUEUE'&&(($input['confirmed_no_side_effects']??false)!==true
           ||strlen($reference)<8))return self::fail('verification_required',409);
        global $wpdb;list($b,$t)=self::tables();$now=gmdate('Y-m-d H:i:s');
        $wpdb->query('START TRANSACTION');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE task_id=%s FOR UPDATE",$id),ARRAY_A);
        if(!$row||!in_array($row['state'],array('WAITING_EXECUTOR','WAITING_EXTERNAL','NEEDS_RECONCILIATION','DEAD_LETTER'),true)) {
            $wpdb->query('ROLLBACK');return self::fail('task_not_reconcilable',409);
        }
        $state=$action==='REQUEUE'?'QUEUED':($action==='CANCEL'?'CANCELLED':'DEAD_LETTER');
        $update=array('state'=>$state,'updated_at'=>$now,
           'last_error'=>substr('manual_'.$action.':'.$reference,0,500),
           'available_at'=>$now,'lease_owner'=>'','lease_hash'=>'','lease_expires_at'=>null);
        if($action==='REQUEUE')$update['attempts']=0;
        $ok=$wpdb->update($t,$update,array('id'=>$row['id'],'state'=>$row['state']));
        if($ok!==1){$wpdb->query('ROLLBACK');return self::fail('reconciliation_failed',500);}
        // A failed batch is reopenable only through a verified REQUEUE,
        // never through an unaudited general Resume.
        if($state==='QUEUED'){
            $control=$wpdb->get_row($wpdb->prepare(
              "SELECT state FROM $b WHERE batch_id=%s FOR UPDATE",$row['batch_id']),ARRAY_A);
            if($control && $control['state']==='FAILED'){
                $opened=$wpdb->update($b,['state'=>'ACTIVE','updated_at'=>$now],
                  ['batch_id'=>$row['batch_id'],'state'=>'FAILED']);
                if($opened!==1){$wpdb->query('ROLLBACK');return self::fail('verified_reopen_conflict',409);}
            }
        }
        $wpdb->query('COMMIT');
        if(in_array($state,['CANCELLED','DEAD_LETTER'],true) &&
           class_exists('MAD4B_Hub_Queue_Lifecycle'))
            MAD4B_Hub_Queue_Lifecycle::close_batch($row['batch_id'],true);
        return array('contract'=>self::CONTRACT,'task_id'=>$id,'state'=>$state,
            'verification_ref'=>$reference,'reconciled'=>true);
    }

    /** Explicit cancellation acknowledgment; stale leases cannot complete work. */
    public static function acknowledge_cancellation($input) {
        if(!self::writable())return self::fail('permission',403);
        if(!self::ready())return self::fail('schema_missing',503);
        $id=(string)($input['task_id']??'');
        $owner=(string)($input['executor_id']??'');
        $token=(string)($input['lease_token']??'');
        if(!self::id($id)||!preg_match('/^[a-z][a-z0-9._:-]{2,99}$/D',$owner)
           ||!preg_match('/^[a-f0-9]{48}$/D',$token))return self::fail('invalid_ack',400);
        global $wpdb;list($b,$t)=self::tables();
        $wpdb->query('START TRANSACTION');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE task_id=%s FOR UPDATE",$id),ARRAY_A);
        if(!$row||$row['state']!=='CANCEL_REQUESTED'||$row['lease_owner']!==$owner
           ||!hash_equals($row['lease_hash'],hash('sha256',$token))) {
            $wpdb->query('ROLLBACK');return self::fail('cancel_ack_not_allowed',409);
        }
        $ok=$wpdb->update($t,array('state'=>'CANCELLED','lease_owner'=>'','lease_hash'=>'',
            'lease_expires_at'=>null,'updated_at'=>gmdate('Y-m-d H:i:s')),
            array('id'=>$row['id'],'state'=>'CANCEL_REQUESTED'));
        if($ok!==1){$wpdb->query('ROLLBACK');return self::fail('cancel_ack_write_failed',500);}
        $wpdb->query('COMMIT');
        if(class_exists('MAD4B_Hub_Queue_Lifecycle'))
            MAD4B_Hub_Queue_Lifecycle::close_batch($row['batch_id'],true);
        return array('contract'=>self::CONTRACT,'task_id'=>$id,'state'=>'CANCELLED','acknowledged'=>true);
    }

    public static function batch_control($input) {
        if(!self::writable())return self::fail('permission',403);
        if(!self::ready())return self::fail('schema_missing',503);
        $batch=(string)($input['batch_id']??'');
        $action=strtoupper((string)($input['action']??''));
        if(!self::id($batch)||!in_array($action,array('PAUSE','RESUME','CANCEL'),true))
            return self::fail('invalid_batch_control',400);
        global $wpdb;list($b,$t)=self::tables();
        $now=gmdate('Y-m-d H:i:s');
        $wpdb->query('START TRANSACTION');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $b WHERE batch_id=%s FOR UPDATE",$batch),ARRAY_A);
        if(!$row){$wpdb->query('ROLLBACK');return self::fail('batch_not_found',404);}
        if(!in_array($row['state'],['ACTIVE','PAUSED'],true)){
            $wpdb->query('ROLLBACK');return self::fail('batch_not_controllable',409);
        }
        $state=$action==='PAUSE'?'PAUSED':($action==='RESUME'?'ACTIVE':'CANCELLED');
        $wpdb->update($b,array('state'=>$state,'updated_at'=>$now),array('batch_id'=>$batch));
        if($action==='CANCEL') {
            // Parked work with zero attempts has not been claimed/executed.
            // Never erase WAITING_EXTERNAL/NEEDS_RECONCILIATION, whose side effects may be unknown.
            $wpdb->query($wpdb->prepare("UPDATE $t SET state='CANCELLED',updated_at=%s WHERE batch_id=%s AND (state IN ('QUEUED','RETRY') OR (state IN ('WAITING_EXECUTOR','WAITING_JOB') AND attempts=0))",$now,$batch));
            $wpdb->query($wpdb->prepare("UPDATE $t SET state='CANCEL_REQUESTED',updated_at=%s WHERE batch_id=%s AND state='RUNNING'",$now,$batch));
        }
        $wpdb->query('COMMIT');
        return self::list_queue(array('batch_id'=>$batch));
    }
    public static function routes() {
        $base='mad4b-hub/v1';
        foreach(array(
            '/control-workflow/bulk/queue'=>array('GET','list_queue',false),
            '/control-workflow/bulk/enqueue'=>array('POST','enqueue',true),
            '/control-workflow/bulk/claim'=>array('POST','claim',true),
            '/control-workflow/bulk/finish'=>array('POST','finish',true),
            '/control-workflow/bulk/batch-control'=>array('POST','batch_control',true),
            '/control-workflow/bulk/heartbeat'=>array('POST','heartbeat',true),
            '/control-workflow/bulk/park'=>array('POST','park',true),
            '/control-workflow/bulk/reconcile'=>array('POST','reconcile',true),
            '/control-workflow/bulk/cancel-ack'=>array('POST','acknowledge_cancellation',true)
        ) as $path=>$def) {
            register_rest_route($base,$path,array(
                'methods'=>$def[0],
                'callback'=>static function($request)use($def){return call_user_func(array(__CLASS__,$def[1]),$request->get_params());},
                'permission_callback'=>array(__CLASS__,$def[2]?'writable':'readable')
            ));
        }
    }
    public static function abilities() {
        if(!function_exists('wp_register_ability'))return;
        foreach(array('list_queue'=>false,'enqueue'=>true,'claim'=>true,'finish'=>true,'batch_control'=>true,'heartbeat'=>true,'park'=>true,
            'reconcile'=>true,'acknowledge_cancellation'=>true) as $method=>$write) {
            $name='mad4b/hub-bulk-'.$method;
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,array(
                'label'=>'MAD4B Hub Bulk Queue '.str_replace('_',' ',$method),
                'description'=>'Durable scoped stage queue: no provider execution on claim.',
                'category'=>$write?'mad4b-write':'mad4b-read',
                'execute_callback'=>array(__CLASS__,$method),
                'permission_callback'=>array(__CLASS__,$write?'writable':'readable'),
                'input_schema'=>array('type'=>'object','properties'=>array(
                    'batch_key'=>array('type'=>'string'),'batch_id'=>array('type'=>'string'),
                    'items'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true)),
                    'max_concurrency'=>array('type'=>'integer'),
                    'executor_id'=>array('type'=>'string'),'lease_seconds'=>array('type'=>'integer'),
                    'task_id'=>array('type'=>'string'),'lease_token'=>array('type'=>'string'),
                    'success'=>array('type'=>'boolean'),'result'=>array('type'=>'object'),
                    'error_code'=>array('type'=>'string'),'action'=>array('type'=>'string'),
                    'verification_ref'=>array('type'=>'string'),
                    'confirmed_no_side_effects'=>array('type'=>'boolean')
                ),'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>$write?'write':'read'),
                    'annotations'=>array('readonly'=>!$write,'destructive'=>$write,'idempotent'=>!$write))
            ));
        }
    }
    public static function menu() {
        if(!self::readable())return;
        add_submenu_page('mad4b-shared-content-hub','Bulk Jobs Queue','Bulk Jobs Queue',
          'manage_options','mad4b-hub-bulk-queue',array(__CLASS__,'page'));
    }
    public static function page() {
        if(!self::readable())wp_die('Forbidden');
        echo '<div class="wrap"><h1>Content Hub — Bulk Jobs Queue</h1>';
        echo '<p>All workflow stages; persistent batches, priority, dependencies, bounded concurrency, leased client execution, retries and reconciliation. Claim does not execute work.</p>';
        if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['mad4b_bulk_submit'])) {
            if(!self::writable() || !check_admin_referer('mad4b_hub_bulk_enqueue'))wp_die('Forbidden');
            $json=json_decode(wp_unslash((string)($_POST['bulk_json']??'')),true);
            $response=self::enqueue($json);
            echo '<div class="notice '.(is_wp_error($response)?'notice-error':'notice-success').'"><p>'.
              esc_html(is_wp_error($response)?$response->get_error_code():'Batch saved').'</p></div>';
        }
        echo '<details><summary>Create bulk batch (JSON)</summary><form method="post">';
        wp_nonce_field('mad4b_hub_bulk_enqueue');
        echo '<textarea name="bulk_json" rows="12" style="width:100%;font-family:monospace" placeholder="Batch JSON with batch_key, max_concurrency and items"></textarea>';
        echo '<p><button class="button button-primary" name="mad4b_bulk_submit" value="1">Enqueue batch</button></p></form></details>';
        $r=self::list_queue();
        if(is_wp_error($r)){echo '<p>'.esc_html($r->get_error_code()).'</p></div>';return;}
        echo '<h2>Recent batches</h2><table class="widefat striped"><thead><tr><th>Batch</th><th>State</th><th>Tasks by state</th><th>Concurrency</th></tr></thead><tbody>';
        foreach($r['batches'] as $batch) {
            $parts=array();
            foreach($batch['counts'] as $s=>$v)$parts[]=$s.': '.$v;
            echo '<tr><td><code>'.esc_html($batch['batch_key']).'</code><small> ('.esc_html($batch['batch_id']).')</small></td><td>'.
             esc_html($batch['state']).'</td><td>'.esc_html(implode(' · ',$parts)).'</td><td>'.
             esc_html((string)$batch['max_concurrency']).'</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
