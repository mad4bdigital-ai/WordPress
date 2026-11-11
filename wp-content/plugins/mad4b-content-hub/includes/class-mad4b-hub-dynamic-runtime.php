<?php
defined('ABSPATH') || exit;
/**
 * Capability-driven runtime resolution: intake -> opt-in -> handoff ->
 * executor-bound task -> evidence readback. Discovering a registered
 * WordPress Ability NEVER grants execution or bypasses source permissions.
 */
final class MAD4B_Hub_Dynamic_Runtime_Resolver {
    const CONTRACT='mad4b.hub.dynamic-runtime.v1';
    const CAP=14;
    private static $catalog=null;

    public static function boot(){
        add_action('rest_api_init',array(__CLASS__,'routes'),49);
        add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),49);
        add_action('admin_menu',array(__CLASS__,'menu'),26);
    }
    public static function readable(){
        return class_exists('MAD4B_Content_Hub') && MAD4B_Content_Hub::permission();
    }
    public static function writable(){
        return self::readable() && MAD4B_SCP_Policy::can_mutate();
    }
    private static function err($code,$status=400){
        return new WP_Error('mad4b_dynamic_'.$code,$code,
            array('status'=>$status,'contract'=>self::CONTRACT));
    }
    private static function valid_id($id) {
        return is_string($id) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id);
    }
    /** Hints are discovery-only; abilities must exist in the live registry. */
    private static function hints(){
        $h=array(
          'INTAKE'=>array('content-job-create','aci01-intake-preview','provider-connection-discover'),
          'SITE_DISCOVERY'=>array('hub-source-sync-plan','site-profile','source-hub-status'),
          'SOURCE_DISCOVERY'=>array('hub-source-sync-plan','hub-source-providers','source-hub-status'),
          'KNOWLEDGE_DISPATCH'=>array('knowledge','assistant-route','provider-plan'),
          'KEYWORD_RESEARCH'=>array('research-keyword','research-provider-plan'),
           'SERP_RESEARCH'=>array('research-serp-record','research-serp','search'),
          'COMPETITOR_SELECTION'=>array('research-competitor','competitor-research-plan'),
          'SCRAPING'=>array('research-firecrawl','research-scrape'),
          'COMPETITOR_ANALYSIS'=>array('research-coverage','competitor'),
          'INFORMATION_GAIN'=>array('research-information-gain','hub-workflow-inspect'),
          'BLUEPRINT'=>array('blueprint','content-job'),
          'BLUEPRINT_QA'=>array('blueprint-qa','qa-bundle'),
           'WRITING'=>array('draft-append','writer','draft','content'),
          'FACT_QA'=>array('qa-bundle','fact'),
          'EDITORIAL_QA'=>array('qa-bundle','editorial'),
          'MEDIA'=>array('media','asset'),
          'SEO'=>array('seo','rank-math'),
          'FINAL_QA'=>array('qa-bundle','release-assess'),
          'DRAFT'=>array('draft','content'),
          'SCHEDULING'=>array('schedule','calendar'),
           'PUBLISHING'=>array('hub-canonical-release','publish','release'),
          'POST_PUBLISH'=>array('post-publish','seo'),
          'PROVIDER_RESEARCH'=>array('research-provider','provider-contract'),
          'SOURCE_INGESTION'=>array('control-research-to-hub','source-sync','hub-source'),
          'ENTITY_RESOLUTION'=>array('entity','reconciliation'),
          'CONTENT_ENRICHMENT'=>array('content','enrich'),
          'AVAILABILITY'=>array('availability','rate'),
          'CANONICAL_MERGE'=>array('hub-canonical','hub-source'),
          'CANONICAL_RELEASE'=>array('hub-canonical-release'),
          'MEDIA_RIGHTS'=>array('media','rights'),
           'PRICING'=>array('hub-native-rate-generator-preview','pricing','price'),
          'RATE_GENERATION'=>array('rate','pricing'),
           'WPML_TRANSLATION'=>array('translation-set-post-language','translation','wpml'),
          'JETENGINE_SYNC'=>array('jetengine','consumer','sync'),
           'COMPANY_DISTRIBUTION'=>array('hub-source-sync-request','distribution','company','shared-content'),
          'CHECKOUT_QA'=>array('checkout','qa'),
          'SEO_CONTENT_GRAPH'=>array('seo','content-graph'),
          'ANALYTICS_FEEDBACK'=>array('analytics','feedback'),
          'POST_PUBLISH_MONITORING'=>array('post-publish','monitor'),
          'TEMPLATE_PROVISIONING'=>array('template','provision'),
          'RECONCILIATION'=>array('reconciliation','sync'),
          'INTEGRATION_QA'=>array('integration','qa')
        );
        $h=apply_filters('mad4b_hub_dynamic_stage_hints',$h);
        return is_array($h)?$h:array();
    }
    private static function catalog(){
        if(self::$catalog!==null)return self::$catalog;
        $all=function_exists('wp_get_abilities')?wp_get_abilities():array();
        $catalog=array();
        foreach($all as $name=>$ability) {
            if(!is_string($name)||!str_contains($name,'/')
                ||!is_object($ability)||!method_exists($ability,'get_meta'))continue;
            $meta=$ability->get_meta();
            $annotations=is_array($meta)?($meta['annotations']??array()):array();
            $declarations=is_array($meta)?($meta['mad4b_hub_stages']??array()):array();
            $catalog[$name]=array(
              'name'=>$name,'provider_namespace'=>explode('/',$name,2)[0],
              'label'=>method_exists($ability,'get_label')?(string)$ability->get_label():$name,
              'read_only'=>is_array($annotations)&&($annotations['readonly']??false)===true,
              'declared_stages'=>is_array($declarations)?$declarations:array(),
              'required_input'=>array_values((array)($ability->get_input_schema()['required']??array()))
            );
        }
        self::$catalog=$catalog;
        return $catalog;
    }
    private static function stage_matches($stage,$provider='',$query=''){
        $hints=self::hints();
        $patterns=array_values(array_filter((array)($hints[$stage]??array()),'is_string'));
        $candidates=array();
        foreach(self::catalog() as $name=>$ability) {
            if($provider!=='' && $ability['provider_namespace']!==$provider)continue;
            $query_match=$query!=='' && (str_contains(strtolower($name),$query) || str_contains(strtolower($ability['label']),$query));
            if($query!=='' && !$query_match)continue;
            $declared=in_array($stage,$ability['declared_stages'],true);
            $match=false;
            foreach($patterns as $fragment){
                if(strlen($fragment)>=3 && str_contains($name,$fragment)) {$match=true;break;}
            }
            if(!$declared&&!$match&&!$query_match)continue;
            $score=$declared?100:($query_match?50:($match?10:0));
            if(str_contains($name,'-plan')||str_contains($name,'-status'))$score+=2;
            if(!$ability['read_only'])$score-=1;
            $candidates[]=array(
              'ability'=>$name,'provider_namespace'=>$ability['provider_namespace'],
              'label'=>mb_substr($ability['label'],0,100),
              'discovery'=>$declared?'explicit_stage_metadata':'name_hint',
              'read_only'=>$ability['read_only'],
              'required_input'=>$ability['required_input'],
              'execution_authorized'=>false,
              '_score'=>$score
            );
        }
        usort($candidates,static function($a,$b){
            return ($b['_score']<=>$a['_score'])?:strcmp($a['ability'],$b['ability']);
        });
        $out=array();
        foreach(array_slice($candidates,0,self::CAP) as $item){
            unset($item['_score']);$out[]=$item;
        }
        return array('candidates'=>$out,'matched_count'=>count($candidates));
    }
    /**
     * Trusted installed plugins opt into automatic server execution by registering
     * an exact stage/Ability manifest AND dispatch + independent readback hooks.
     * No certificate ceremony; missing dependencies simply make the route unavailable.
     */
    public static function server_adapter($stage,$exact_id='',$context=array()){
        if(!self::readable())return null;
        if(!has_filter('mad4b_hub_bulk_stage_dispatch')
            ||!has_filter('mad4b_hub_bulk_verify_stage_receipt'))return null;
        $manifest=apply_filters('mad4b_hub_dynamic_executor_manifests',array());
        if(!is_array($manifest))return null;
        $found=array();
        foreach($manifest as $m){
            if(!is_array($m) || ($m['enabled']??false)!==true ||($m['ready']??false)!==true)continue;
            $id=(string)($m['id']??'');
            $name=(string)($m['ability']??'');
            $stages=$m['stages']??array();
            if(!preg_match('/^[a-z][a-z0-9._:-]{3,90}$/D',$id)
                ||($exact_id!==''&&$id!==$exact_id)
                ||!is_array($stages)||!in_array($stage,$stages,true)
                ||!preg_match('/^[a-z][a-z0-9._-]{1,79}\/[a-z][a-z0-9._-]{1,99}$/D',$name)
                ||!function_exists('wp_get_ability')||!wp_get_ability($name)
                ||($m['executor']??'')!=='vps_runner')continue;
            if(str_starts_with($id,'dynamic.') &&
               (!class_exists('MAD4B_Hub_Universal_VPS_V1') ||
                !MAD4B_Hub_Universal_VPS_V1::route_ready(
                  $stage,$id,(array)$context)))continue;
            if(str_starts_with($id,'native.') &&
               (!class_exists('MAD4B_Hub_Six_Stage_Adapters') ||
                !MAD4B_Hub_Six_Stage_Adapters::preflight($stage,$name,(array)$context)))
                continue;
            $found[]=array('id'=>$id,'ability'=>$name,'executor'=>'vps_runner',
                'priority'=>max(1,min(100,(int)($m['priority']??50))));
        }
        if(!$found)return null;
        usort($found,static fn($a,$b)=>($b['priority']<=>$a['priority'])?:strcmp($a['id'],$b['id']));
        return $found[0];
    }
    public static function resolve($input=array()){
        if(!self::readable())return self::err('read_denied',403);
        if(!is_array($input))return self::err('invalid_input');
        $stage=strtoupper(trim((string)($input['stage']??'')));
        $jobid=strtolower(trim((string)($input['job_id']??'')));
        $target=trim((string)($input['target_ref']??''));
        $provider=strtolower(trim((string)($input['provider']??'')));
        $query=strtolower(trim((string)($input['capability_query']??'')));
        if($provider!==''&&!preg_match('/^[a-z][a-z0-9._-]{1,79}$/D',$provider))return self::err('invalid_provider');
        if($query!==''&&!preg_match('/^[a-z0-9][a-z0-9 ._+\/-]{2,79}$/D',$query))return self::err('invalid_capability_query');
        if(!in_array($stage,MAD4B_Hub_Bulk_Jobs_Queue::stages(),true))
            return self::err('invalid_stage');
        if($jobid!==''&&!self::valid_id($jobid))return self::err('invalid_job_id');
        if($jobid===''&&($target===''||strlen($target)>191))return self::err('target_ref_required');
        $job=null;
        if($jobid!==''){
            $r=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$jobid));
            if(is_wp_error($r))return $r;
            $job=$r['job']??array();
            if(($job['job_id']??'')!==$jobid)return self::err('job_scope_mismatch',403);
        }
        $c=self::stage_matches($stage,$provider,$query);
        $mode='mcp_client';$ready=false;
        if($jobid!=='') {
            $status=MAD4B_Hub_Execution_Router::status(array('job_id'=>$jobid));
            if(is_wp_error($status))return $status;
            $mode=(string)$status['selected_executor'];
            $ready=($status['background_ai_ready']??false)===true;
        }
        $context=$input;
        $context['content_job_id']=$jobid;
        $adapter=self::server_adapter($stage,'',$context);
        $recommendation=$mode==='background_ai'
          ?($ready?'background_ai_explicit_dispatch':'background_runner_unavailable')
          :($adapter?'vps_runner_registered_adapter':'mcp_client_handoff');
        $followup=class_exists('MAD4B_Hub_Workflow_Operations')&&$jobid!==''?
             MAD4B_Hub_Workflow_Operations::inspect(array('job_id'=>$jobid)):null;
        // Only reuse the job continuation when this task targets its actual stage.
        $next=($job!==null && ($job['stage']??'')===$stage && is_array($followup))
          ?(string)($followup['next_ability_or_step']??''):'';
        if($next!==''&&!function_exists('wp_get_ability'))$next='';
        // Native ContentJob creation is the authoritative first action for new intake.
        // Registration only advertises a handoff; creation still uses the original Ability.
        if($next==='' && $stage==='INTAKE' && $jobid==='' && $provider==='' && $query===''
           && function_exists('wp_get_ability') && wp_get_ability('mad4b/content-job-create'))
            $next='mad4b/content-job-create';
        if($next==='' && is_array($adapter) && !empty($adapter['ability'])
            &&wp_get_ability($adapter['ability']))
            $next=$adapter['ability'];
        $exists=$next!==''&&wp_get_ability($next)!==null;
        $out=array(
          'contract'=>self::CONTRACT,'stage'=>$stage,'job_id'=>$jobid,
          'target_ref'=>$target,'provider'=>$provider,'capability_query'=>$query,
          'discovery_scope'=>'all_registered_ability_namespaces',
          'job_current_stage'=>$job['stage']??'',
          'job_current_state'=>$job['state']??'','job_revision'=>(int)($job['job_revision']??0),
          'registered_ability_count'=>$c['matched_count'],
          'candidate_abilities'=>$c['candidates'],
          'next_registered_ability'=>$exists?$next:'',
          'next_step'=>$next!==''?$next:'stage_adapter_handoff_required',
          'execution_mode'=>$mode,'recommended_route'=>$recommendation,
          'executor'=>$adapter&&$mode!=='background_ai'?'vps_runner':'mcp_client',
          'server_adapter'=>$adapter,
          'vps_automatic_execution_authorized'=>$adapter!==null&&$mode!=='background_ai',
          'background_ai_ready'=>$ready,
          'lifecycle'=>array('intake','capability_resolution','provider_opt_in_if_needed',
            'handoff','stage_executor','verified_receipt','next_stage'),
          'status'=>count($c['candidates'])?'registered_candidates_found':'stage_adapter_missing',
          'provider_calls_performed'=>false,'mutation_performed'=>false,
          'capability_discovery_is_not_execute_authority'=>true
        );
        $fingerprint=array($stage,$jobid,$target,$provider,$query,
          hash('sha256',wp_json_encode(array(
            'stage_ability'=>$input['stage_ability']??'',
            'stage_input'=>$input['stage_input']??array()))),
          $out['job_revision'],$out['job_current_stage'],
          $out['next_step'],$out['execution_mode'],array_column($c['candidates'],'ability'),
          $adapter?$adapter['id']:'');
        $out['resolution_sha256']=hash('sha256',wp_json_encode($fingerprint));
        return $out;
    }
    /** Intake creates durable resolve + handoff tasks, without executing stage mutations. */
    public static function intake($input=array()){
        if(!self::writable())return self::err('write_denied',403);
        if(!is_array($input)||!is_array($input['items']??null))return self::err('invalid_intake');
        $items=$input['items'];
        if(!count($items)||count($items)>25)return self::err('intake_batch_bounds');
        $entries=array();$plans=array();$keys=array();
        foreach($items as $index=>$item){
            if(!is_array($item))return self::err('invalid_intake_item');
            $k=(string)($item['task_key']??'');
            if(!preg_match('/^[A-Za-z0-9._:-]{1,95}$/D',$k)||isset($keys[$k]))
                return self::err('duplicate_or_invalid_task_key');
            $jobid=strtolower(trim((string)($item['job_id']??'')));
            if(!self::valid_id($jobid))return self::err('content_job_required',422);
            $jobRecord=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$jobid));
            if(is_wp_error($jobRecord))return $jobRecord;
            if(in_array($jobRecord['job']['state']??'',array('COMPLETED','CANCELLED'),true))
                return self::err('content_job_terminal',409);
            $keys[$k]=true;
        }
        foreach($items as $item){
            $key=(string)$item['task_key'];
            $stage=strtoupper((string)($item['stage']??''));
            $jobid=strtolower(trim((string)($item['job_id']??'')));
            $target=(string)($item['target_ref']??'');
            $stageInput=$item['stage_input']??array();
            $stageAbility=(string)($item['stage_ability']??'');
            if(!is_array($stageInput)
               ||strlen(wp_json_encode($stageInput))>14000
               ||strlen($stageAbility)>150)return self::err('stage_input_invalid',400);
            $resolved=self::resolve(array('stage'=>$stage,'job_id'=>$jobid,'target_ref'=>$target,
              'provider'=>$item['provider']??'','capability_query'=>$item['capability_query']??'',
              'stage_input'=>$stageInput,'stage_ability'=>$stageAbility));
            if(is_wp_error($resolved))return $resolved;
            $deps=$item['depends_on']??array();
            if(!is_array($deps))return self::err('invalid_deps');
            foreach($deps as $dep)if(!is_string($dep)||!isset($keys[$dep]))return self::err('unknown_dependency');
            $priority=(int)($item['priority']??5);
            $preference=$item['executor_preferences']??($input['executor_preferences']??null);
            if($preference!==null&&!is_array($preference))return self::err('executor_preferences_invalid',400);
            $jobRecord=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$jobid));
            if(is_wp_error($jobRecord))return $jobRecord;
            $jobRevision=(int)($jobRecord['job']['job_revision']??0);
            $base=array('job_id'=>$jobid,'target_ref'=>$target,'stage'=>$stage,'priority'=>$priority);
            $resolve_executor='vps_runner';
            if($preference!==null){
                $resolver_route=MAD4B_Hub_Executor_Selection::plan(array(
                    'task'=>array('stage'=>$stage,'operation'=>'runtime_resolve','payload'=>array()),
                    'preferences'=>$preference));
                if(is_wp_error($resolver_route)||empty($resolver_route['selected_executor']))
                    return self::err('no_resolver_executor',409);
                $resolve_executor=$resolver_route['selected_executor'];
            }
            $entries[]=array_merge($base,array('task_key'=>'r-'.$key,'operation'=>'runtime_resolve',
              'depends_on'=>array_map(static fn($d)=>'e-'.$d,$deps),
              'payload'=>array('assigned_executor'=>$resolve_executor,
                'resolution_stage'=>$stage,'source_task_key'=>$key,
                'content_job_id'=>$jobid,'content_job_revision'=>$jobRevision,
                'trigger_source'=>(string)($input['trigger_source']??'manual'),
                'trigger_event_type'=>(string)($input['trigger_event_type']??''),
                'trigger_event_id'=>(string)($input['trigger_event_id']??''),
                'provider'=>$resolved['provider'],'capability_query'=>$resolved['capability_query'],
                'stage_input'=>$stageInput,'stage_ability'=>$stageAbility)));
            $server=($resolved['vps_automatic_execution_authorized']??false)===true;
            $work_executor=$server?'vps_runner':'mcp_client';
            if($preference!==null){
                $work_route=MAD4B_Hub_Executor_Selection::plan(array(
                  'task'=>array('stage'=>$stage,'operation'=>'stage_work','payload'=>array(
                     'stage_adapter_id'=>$server?($resolved['server_adapter']['id']??''):'',
                     'stage_input'=>$stageInput,'stage_ability'=>$stageAbility,
                     'content_job_id'=>$jobid)),
                  'preferences'=>$preference));
                if(is_wp_error($work_route)||empty($work_route['selected_executor']))
                    return self::err('no_work_executor',409);
                $work_executor=$work_route['selected_executor'];
            }
            $entries[]=array_merge($base,array('task_key'=>'e-'.$key,'operation'=>'stage_work',
              'depends_on'=>array('r-'.$key),
              'payload'=>array('assigned_executor'=>$work_executor,
                'stage_adapter_id'=>$server?($resolved['server_adapter']['id']??''):'',
                'resolution_task'=>'r-'.$key,'requested_step'=>'execute_with_verified_receipt',
                'content_job_id'=>$jobid,'content_job_revision'=>$jobRevision,
                'trigger_source'=>(string)($input['trigger_source']??'manual'),
                'trigger_event_type'=>(string)($input['trigger_event_type']??''),
                'trigger_event_id'=>(string)($input['trigger_event_id']??''),
                'preferred_ability'=>$resolved['next_registered_ability'],
                'capability_snapshot_sha256'=>$resolved['resolution_sha256'],
                'provider'=>$resolved['provider'],'capability_query'=>$resolved['capability_query'],
                'stage_input'=>$stageInput,'stage_ability'=>$stageAbility)));
            $plans[]=array('task_key'=>$key,'stage'=>$stage,
              'content_job_id'=>$jobid,'content_job_revision'=>$jobRevision,
              'resolver_task'=>'r-'.$key,'handoff_task'=>'e-'.$key,
              'resolver_executor'=>$resolve_executor,
              'next_ability'=>$resolved['next_registered_ability'],
              'suggested_ability'=>$resolved['candidate_abilities'][0]['ability']??'',
              'execution_mode'=>$resolved['execution_mode'],
              'assigned_executor'=>$work_executor,
              'stage_adapter_id'=>$server?($resolved['server_adapter']['id']??''):'');
        }
        $queue=MAD4B_Hub_Bulk_Jobs_Queue::enqueue(array(
          'batch_key'=>(string)($input['batch_key']??''),
          'max_concurrency'=>(int)($input['max_concurrency']??2),'items'=>$entries));
        if(is_wp_error($queue))return $queue;
        return array('contract'=>self::CONTRACT,
          'batch_id'=>$queue['batches'][0]['batch_id']??'',
          'replayed'=>(bool)($queue['replayed']??false),
          'stage_items'=>count($items),'queued_tasks'=>count($entries),
          'plans'=>$plans,'state'=>'queued_for_resolution_then_client_handoff',
          'execution_performed'=>false,'authorization_granted'=>false);
    }
    /** Explicit optional model executor selection through its original authority gate. */
    public static function opt_in($input=array()){
        if(!self::writable())return self::err('write_denied',403);
        $job=(string)($input['job_id']??'');
        $mode=(string)($input['executor_mode']??'');
        if(!self::valid_id($job)||!in_array($mode,array('chatgpt_client','background_ai'),true))
            return self::err('invalid_opt_in');
        $r=MAD4B_Hub_Execution_Router::select(array('job_id'=>$job,'executor_mode'=>$mode));
        if(is_wp_error($r))return $r;
        return array('contract'=>self::CONTRACT,'job_id'=>$job,
          'selected_executor'=>$r['selected_executor'],'changed'=>(bool)($r['changed']??false),
          'background_ai_ready'=>(bool)$r['background_ai_ready'],'execution_performed'=>false);
    }
    public static function handoff($input=array()){
        if(!is_array($input)||!self::valid_id(strtolower((string)($input['job_id']??''))))
            return self::err('content_job_required_for_handoff',422);
        $r=self::resolve($input);
        if(is_wp_error($r))return $r;
        return array('contract'=>self::CONTRACT,'job_id'=>$r['job_id'],'stage'=>$r['stage'],
          'target_ref'=>$r['target_ref'],'executor'=>$r['recommended_route'],
          'ability'=>$r['next_registered_ability'],
          'suggested_registered_ability'=>$r['candidate_abilities'][0]['ability']??'',
          'suggestion_is_not_execution_authority'=>true,
          'ability_candidates'=>$r['candidate_abilities'],
          'input_needed'=> $r['next_registered_ability'] ?
             (wp_get_ability($r['next_registered_ability'])->get_input_schema()['required']??array()) : array(),
          'resolution_sha256'=>$r['resolution_sha256'],
          'action_required'=>'invoke_original_authorized_ability_and_submit_verified_queue_receipt',
          'executed'=>false,'mutation_performed'=>false);
    }
    public static function status($input=array()){
        if(!self::readable())return self::err('read_denied',403);
        $batch=(string)($input['batch_id']??'');
        $jobId=strtolower(trim((string)($input['job_id']??'')));
        if($jobId!=='' && !self::valid_id($jobId))return self::err('invalid_job_id');
        $r=MAD4B_Hub_Bulk_Jobs_Queue::list_queue(
            array_filter(array('batch_id'=>$batch,'job_id'=>$jobId)));
        if(is_wp_error($r))return $r;
        $brief=array();
        foreach($r['batches'] as $item){
            $brief[]=array('batch_id'=>$item['batch_id'],'batch_key'=>$item['batch_key'],
              'state'=>$item['state'],'counts'=>$item['counts'],
              'task_total'=>$item['item_count']);
        }
        return array('contract'=>self::CONTRACT,'scope'=>'authorized_current_hub',
          'available_stages'=>count(MAD4B_Hub_Bulk_Jobs_Queue::stages()),
          'registered_mad4b_abilities'=>count(self::catalog()),
          'batches'=>$brief,'count'=>count($brief),
          'content_job_id'=>$jobId,'job_scoped'=>$jobId!=='',
          'available_actions'=>array('intake','resolve','opt_in','handoff','verify'),
          'execution_performed'=>false,'mutation_performed'=>false);
    }
    public static function verify($input=array()){
        if(!self::readable())return self::err('read_denied',403);
        $id=(string)($input['batch_id']??'');
        if(!self::valid_id($id))return self::err('invalid_batch_id',400);
        $r=MAD4B_Hub_Bulk_Jobs_Queue::list_queue(array('batch_id'=>$id));
        if(is_wp_error($r))return $r;
        if(empty($r['batches']))return self::err('batch_not_found',404);
        $batch=$r['batches'][0];
        $resolvers=array();$execution=array();$pending=array();
        $jobIds=array();$allBound=count($batch['tasks'])>0;
        foreach($batch['tasks'] as $task){
            $taskJob=(string)($task['content_job_id']??'');
            if(!self::valid_id($taskJob))$allBound=false;
            else $jobIds[$taskJob]=true;
            if(str_starts_with($task['task_key'],'r-'))$resolvers[$task['task_key']]=$task['state'];
            elseif(str_starts_with($task['task_key'],'e-'))$execution[$task['task_key']]=$task['state'];
            if($task['state']!=='COMPLETED')$pending[]=array(
                'task_key'=>$task['task_key'],'stage'=>$task['stage'],
                'state'=>$task['state']);
        }
        foreach(array_keys($jobIds) as $taskJob){
            $check=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$taskJob));
            if(is_wp_error($check))$allBound=false;
        }
        $resolver_complete=count($resolvers)>0 &&
          count(array_filter($resolvers,static fn($s)=>$s==='COMPLETED'))===count($resolvers);
        $business_complete=count($execution)>0 &&
          count(array_filter($execution,static fn($s)=>$s==='COMPLETED'))===count($execution);
        return array('contract'=>self::CONTRACT,'batch_id'=>$id,
          'batch_state'=>$batch['state'],'resolver_tasks'=>$resolvers,
          'linked_content_job_ids'=>array_keys($jobIds),
          'all_tasks_content_job_bound'=>$allBound,
          'execution_tasks'=>$execution,'pending'=>$pending,
          'resolution_complete'=>$resolver_complete,'all_execution_tasks_acknowledged'=>$business_complete,
          'queue_fully_completed'=>$business_complete&&$resolver_complete&&count($pending)===0&&$allBound,
          'canonical_release_verified'=>false,'company_distribution_verified'=>false,
          'read_only_check'=>true,'mutation_performed'=>false);
    }
    public static function menu(){
        if(!self::readable())return;
        add_submenu_page('mad4b-shared-content-hub','Dynamic Runtime',
          'Dynamic Runtime','manage_options','mad4b-hub-dynamic-runtime',array(__CLASS__,'page'));
    }
    public static function page(){
        if(!self::readable())wp_die('Forbidden');
        $stages=MAD4B_Hub_Bulk_Jobs_Queue::stages();
        echo '<div class="wrap"><h1>MAD4B — Dynamic Runtime Resolver</h1>';
        echo '<p>Native WordPress Ability discovery. Staging queue resolves all workflow stages and routes execution through original permissions. Registration is not execution permission.</p>';
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=mad4b-hub-bulk-queue')).'">Open Bulk Jobs Queue</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>Stage</th><th>Discovered Abilities</th><th>Candidate</th><th>Execution policy</th></tr></thead><tbody>';
        foreach($stages as $stage){
            $m=self::stage_matches($stage);
            $first=$m['candidates'][0]['ability']??'adapter not discovered';
            echo '<tr><td><code>'.esc_html($stage).'</code></td><td>'.
              esc_html((string)$m['matched_count']).'</td><td><code>'.esc_html($first).
              '</code></td><td>Original Ability permission + verified receipt</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    public static function routes(){
        $paths=array(
          '/control-workflow/runtime/resolve'=>array('GET','resolve',false),
          '/control-workflow/runtime/handoff'=>array('GET','handoff',false),
          '/control-workflow/runtime/intake'=>array('POST','intake',true),
          '/control-workflow/runtime/opt-in'=>array('POST','opt_in',true),
          '/control-workflow/runtime/status'=>array('GET','status',false),
          '/control-workflow/runtime/verify'=>array('GET','verify',false)
        );
        foreach($paths as $path=>$info){
            register_rest_route('mad4b-hub/v1',$path,array(
              'methods'=>$info[0],
              'callback'=>static function($req)use($info){
                  return call_user_func(array(__CLASS__,$info[1]),$req->get_params());
              },
              'permission_callback'=>array(__CLASS__,$info[2]?'writable':'readable')
            ));
        }
    }
    public static function abilities(){
        if(!function_exists('wp_register_ability'))return;
        foreach(array('resolve'=>false,'handoff'=>false,'intake'=>true,'opt_in'=>true,'status'=>false,'verify'=>false) as $method=>$write){
            $name='mad4b/hub-runtime-'.str_replace('_','-',$method);
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,array(
              'label'=>'MAD4B Hub dynamic runtime '.str_replace('_',' ',$method),
              'description'=>'Site-scoped capability resolution and durable workflow routing. No invented execution.',
              'category'=>$write?'mad4b-write':'mad4b-read',
              'execute_callback'=>array(__CLASS__,$method),
              'permission_callback'=>array(__CLASS__,$write?'writable':'readable'),
              'input_schema'=>array('type'=>'object','properties'=>array(
                'stage'=>array('type'=>'string'),
                'job_id'=>array('type'=>'string'),
                'target_ref'=>array('type'=>'string'),
                'provider'=>array('type'=>'string'),
                'capability_query'=>array('type'=>'string'),
                'batch_key'=>array('type'=>'string'),
                'max_concurrency'=>array('type'=>'integer'),
                'executor_preferences'=>array('type'=>'array','items'=>array('type'=>'string')),
                'stage_ability'=>array('type'=>'string'),
                'stage_input'=>array('type'=>'object','additionalProperties'=>true),
                'batch_id'=>array('type'=>'string'),
                'executor_mode'=>array('type'=>'string'),
                'items'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true))
              ),'required'=>$method==='resolve'||$method==='handoff'?array('stage'):($method==='intake'?array('batch_key','items'):($method==='verify'?array('batch_id'):($method==='opt_in'?array('job_id','executor_mode'):array()))),
               'additionalProperties'=>false),
              'output_schema'=>array('type'=>'object','additionalProperties'=>true),
              'meta'=>array('public'=>false,'show_in_rest'=>false,
                'mcp'=>array('public'=>false,'type'=>'tool','surface'=>$write?'write':'read'),
                'annotations'=>array('readonly'=>!$write,'destructive'=>$write,'idempotent'=>!$write))
            ));
        }
    }
}
