<?php
defined('ABSPATH') || exit;
/**
 * Staging-scoped, bounded WP-CLI runner for MAD4B Hub Bulk Queue.
 * No model/server providers are implicitly enabled by the runner.
 */
final class MAD4B_Hub_Bulk_VPS_Runner {
    const CONTRACT='mad4b.hub.bulk-vps-runner.v1';
    const EXECUTOR='vps_runner';

    public static function boot(){
        add_filter('mad4b_hub_bulk_authorized_executor',array(__CLASS__,'authorized'),10,2);
        if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('mad4b hub bulk run',array(__CLASS__,'cli_run'));
            WP_CLI::add_command('mad4b hub bulk status',array(__CLASS__,'cli_status'));
        }
    }
    public static function authorized($is_allowed,$executor){
        return $is_allowed || ($executor===self::EXECUTOR && self::permitted());
    }
    private static function permitted(){
        // VPS transport authority and the explicit WordPress application actor
        // are separate facts. Reuse the native WP-CLI verification rather than
        // trusting merely a --user flag or a site-environment label.
        if(!defined('WP_CLI')||!WP_CLI||PHP_SAPI!=='cli'
          ||!class_exists('MAD4B_Hub_CLI_Fallback'))return false;
        $identity=MAD4B_Hub_CLI_Fallback::preflight('queue-list');
        return is_array($identity)
          &&($identity['authenticated_via']??'')==='verified_wordpress_wpcli'
          &&(int)($identity['actor_user_id']??0)===get_current_user_id()
          &&MAD4B_Hub_Bulk_Jobs_Queue::writable()
          &&current_user_can('activate_plugins');
    }
    private static function fail($code){
        return new WP_Error('mad4b_hub_bulk_runner_'.$code,$code);
    }
    /** Whitelisted diagnostic executors; other stages need installed adapters. */
    private static function dispatch($task,$heartbeat){
        $operation=(string)$task['operation'];
        if($operation==='runtime_resolve') {
            $plan=MAD4B_Hub_Dynamic_Runtime_Resolver::resolve(array(
              'stage'=>$task['stage'],'job_id'=>$task['content_job_id'],
              'target_ref'=>$task['target_ref'],
              'provider'=>$task['payload']['provider']??'',
              'capability_query'=>$task['payload']['capability_query']??'',
              'stage_input'=>$task['payload']['stage_input']??array(),
              'stage_ability'=>$task['payload']['stage_ability']??''));
            if(is_wp_error($plan))return $plan;
            return array('verified'=>true,'operation'=>'runtime_resolve',
              'stage'=>$task['stage'],'result_kind'=>'runtime_plan_verified',
              'resolution_sha256'=>$plan['resolution_sha256'],
              'candidate_ability_count'=>$plan['registered_ability_count'],
              'next_ability'=>$plan['next_registered_ability'],
              'execution_performed'=>false,'mutation_performed'=>false);
        }
        if($operation==='ai_context_preflight'){
            return MAD4B_Hub_AI_Guarded_Preflight::receipt($task);
        }
        if($operation==='ai_guard_stage'){
            return MAD4B_Hub_AI_Queue_Pipeline::stage_receipt($task);
        }
        if($operation==='site_health'){
            $db=class_exists('MAD4B_SCP_Schema') && MAD4B_SCP_Schema::critical_ready();
            if(!$db)return self::fail('critical_schema_unavailable');
            return array('verified'=>true,'operation'=>'site_health',
              'site_origin'=>rtrim(home_url(),'/'),'stage'=>$task['stage'],
              'result_kind'=>'diagnostic_only','schema_ready'=>true);
        }
        if($operation==='workflow_inspect'){
            if(empty($task['content_job_id']))return self::fail('job_id_required');
            $result=MAD4B_Hub_Workflow_Operations::inspect(
                array('job_id'=>$task['content_job_id']));
            if(is_wp_error($result))return $result;
            if(($result['job_id']??'')!==$task['content_job_id'])
                return self::fail('inspect_identity_mismatch');
            return array('verified'=>true,'operation'=>'workflow_inspect',
              'stage'=>$task['stage'],'job_id'=>$result['job_id'],
              'current_job_stage'=>$result['job_stage'],'job_revision'=>$result['job_revision'],
              'next_step'=>$result['next_ability_or_step'],
              'result_kind'=>'readback_only','mutation_performed'=>false);
        }
        if($operation==='content_job_read'){
            if(empty($task['content_job_id']))return self::fail('job_id_required');
            $res=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$task['content_job_id']));
            if(is_wp_error($res))return $res;
            if(($res['job']['job_id']??'')!==$task['content_job_id'])return self::fail('job_identity_mismatch');
            return array('verified'=>true,'operation'=>'content_job_read',
              'stage'=>$task['stage'],'job_id'=>$res['job']['job_id'],
              'job_revision'=>(int)$res['job']['job_revision'],
              'result_kind'=>'readback_only','mutation_performed'=>false);
        }
        if($operation==='source_sync_verify'){
            $post_id=absint($task['payload']['post_id']??0);
            if(!$post_id||$task['target_ref']!=='post:'.$post_id)
                return self::fail('source_post_identity_required');
            $source=MAD4B_Hub_Source_Reader::content_candidate($post_id);
            if(is_wp_error($source))return $source;
            if(($source['candidate']['source_id']??0)!==$post_id
               ||empty($source['source_version']))
                return self::fail('source_candidate_identity_invalid');
            return array('verified'=>true,'operation'=>'source_sync_verify',
              'stage'=>$task['stage'],'source_id'=>$post_id,
              'source_version_sha256'=>$source['source_version'],
              'content_id'=>$source['global_content_id'],
              'language'=>$source['candidate']['language'],
              'result_kind'=>'source_projection_verified','mutation_performed'=>false);
        }
        if($operation==='stage_work') {
            $id=(string)($task['payload']['stage_adapter_id']??'');
            if($id===''||MAD4B_Hub_Dynamic_Runtime_Resolver::server_adapter($task['stage'],$id,
                array_merge((array)$task['payload'],array(
                  'content_job_id'=>$task['content_job_id'],
                  'target_ref'=>$task['target_ref'])))===null)
                return null;
        }
        if(!has_filter('mad4b_hub_bulk_stage_dispatch'))
            return null; // No implicit executor for research, writing, pricing, publishing, etc.
        $result=apply_filters('mad4b_hub_bulk_stage_dispatch',null,$task,$heartbeat);
        if($result===null)return null;
        if(is_wp_error($result))return $result;
        if(!is_array($result)||empty($result['verified'])
           ||empty($result['evidence_ref']) ||($result['stage']??'')!==$task['stage'])
            return self::fail('stage_executor_receipt_invalid');
        // A separate readback verifier is required to accept mutating work.
        if(apply_filters('mad4b_hub_bulk_verify_stage_receipt',false,$result,$task)!==true)
            return self::fail('stage_executor_readback_not_verified');
        return $result;
    }
    public static function tick($max=2,$batch_id='',$executor='vps_runner',$recover=true){
        if(!self::permitted())return self::fail('verified_wpcli_queue_authority_required');
        if(!in_array($executor,array('vps_runner','action_scheduler'),true))
            return self::fail('executor_not_allowed');
        $max=max(1,min(4,(int)$max));
        // Bounded recovery for post-commit events lost to interrupted HTTP/CLI workers.
        if($recover&&class_exists('MAD4B_Hub_Trigger_Bus')) {
            $recovered=MAD4B_Hub_Trigger_Bus::sweep(20);
            if(is_wp_error($recovered))return $recovered;
        }
        $start=microtime(true);$events=array();
        for($i=0;$i<$max&&microtime(true)-$start<50;$i++){
            $claim=MAD4B_Hub_Bulk_Jobs_Queue::claim(array(
                'batch_id'=>$batch_id,'executor_id'=>$executor,'lease_seconds'=>180));
            if(is_wp_error($claim))return $claim;
            if(empty($claim['claimed']))break;
            $task=array(
              'task_id'=>$claim['task_id'],'task_key'=>$claim['task_key'],
              'batch_id'=>$claim['batch_id'],'content_job_id'=>$claim['content_job_id'],
              'target_ref'=>$claim['target_ref'],'stage'=>$claim['stage'],
              'operation'=>$claim['operation'],'payload'=>$claim['payload']);
            $beat=static function($seconds=180)use($claim,$executor){
                return MAD4B_Hub_Bulk_Jobs_Queue::heartbeat(array(
                    'task_id'=>$claim['task_id'],'executor_id'=>$executor,
                    'lease_token'=>$claim['lease_token'],'lease_seconds'=>$seconds));
            };
            $event=array('task_id'=>$task['task_id'],'stage'=>$task['stage'],
                'operation'=>$task['operation']);
            try{
                $alive=$beat();
                if(is_wp_error($alive)) {
                    $event['state']='LEASE_LOST';
                    $events[]=$event;continue;
                }
                $result=self::dispatch($task,$beat);
                if($result===null){
                    $park=MAD4B_Hub_Bulk_Jobs_Queue::park(array(
                        'task_id'=>$claim['task_id'],'executor_id'=>$executor,
                        'lease_token'=>$claim['lease_token']));
                    $event['state']=is_wp_error($park)?'PARK_ERROR':'WAITING_EXECUTOR';
                    if(is_wp_error($park))$event['error']=$park->get_error_code();
                    $events[]=$event;continue;
                }
                $success=!is_wp_error($result)&&is_array($result)&&!empty($result['verified']);
                $ack=MAD4B_Hub_Bulk_Jobs_Queue::finish(array(
                    'task_id'=>$claim['task_id'],'executor_id'=>$executor,
                    'lease_token'=>$claim['lease_token'],'success'=>$success,
                    'result'=>$success?$result:array(),
                    'error_code'=>is_wp_error($result)?$result->get_error_code():'receipt_not_verified'
                ));
                $event['state']=is_wp_error($ack)?'ACK_ERROR':$ack['state'];
                if(is_wp_error($ack))$event['error']=$ack->get_error_code();
            }catch(Throwable $e){
                // Do not claim a rollback or blindly replay a possibly-mutating stage.
                $ack=MAD4B_Hub_Bulk_Jobs_Queue::finish(array(
                    'task_id'=>$claim['task_id'],'executor_id'=>$executor,
                    'lease_token'=>$claim['lease_token'],'success'=>false,
                    'error_code'=>'executor_exception'));
                $event['state']=is_wp_error($ack)?'NEEDS_RECONCILIATION':$ack['state'];
                $event['error']='executor_exception';
            }
            $events[]=$event;
        }
        return array('contract'=>self::CONTRACT,'executor'=>$executor,
            'processed'=>count($events),'events'=>$events,
            'elapsed_seconds'=>round(microtime(true)-$start,3));
    }
    public static function cli_run($args,$assoc){
        if(!self::permitted())WP_CLI::error('verified_wpcli_queue_authority_required');
        if($args)WP_CLI::error('No positional arguments allowed');
        $limit=isset($assoc['limit'])?(int)$assoc['limit']:2;
        $batch=isset($assoc['batch-id'])?(string)$assoc['batch-id']:'';
        if($limit<1||$limit>4)WP_CLI::error('limit must be 1 to 4');
        $result=self::tick($limit,$batch);
        if(is_wp_error($result))WP_CLI::error($result->get_error_code());
        WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));
    }
    public static function cli_status($args,$assoc){
        if(!self::permitted())WP_CLI::error('staging_wpcli_authority_required');
        $result=MAD4B_Hub_Bulk_Jobs_Queue::list_queue(array());
        if(is_wp_error($result))WP_CLI::error($result->get_error_code());
        foreach($result['batches'] as &$batch)unset($batch['tasks']);
        unset($batch);
        WP_CLI::line(wp_json_encode(array('contract'=>self::CONTRACT,
          'installed'=>true,'stages'=>MAD4B_Hub_Bulk_Jobs_Queue::stages(),
          'registered_executor'=>'vps_runner',
          'wordpress_queue_execution_allowed'=>self::permitted(),
          'queue_authorization_source'=>'verified_wpcli_actor_and_native_queue_policy',
          'site_environment'=>wp_get_environment_type(),
          'claim_scope'=>'vps_runner_assigned_tasks_only',
          'mcp_client_task_claim_allowed'=>false,
          'queue_authorization_does_not_override_assignment'=>true,
          'enabled_execution_modes'=>array(
            'site_health','workflow_inspect','content_job_read','source_sync_verify','runtime_resolve','verified_stage_adapter'),
          'queue'=>$result),JSON_UNESCAPED_SLASHES));
    }
}
