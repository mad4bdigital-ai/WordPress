<?php
/**
 * Plugin Name: MAD4B Lifecycle Orchestrator
 * Description: Central staging-only event and manual runtime coordination; never grants provider write permissions.
 * Version: 0.3.1-native-receipt-staging
 * Author: MAD4B
 */
defined('ABSPATH') || exit;

final class MAD4B_Lifecycle_Orchestrator {
    const CONTRACT='mad4b.lifecycle-orchestrator.v1';
    const CRON='mad4b_lifecycle_reconcile_event_v1';
    const PREFIX='mad4b_lifecycle_event_v1_';
    const GOALS=['seo','media','content','company'];
    const STATES=['READY','RUNNING','RECOVERING','PARTIAL','BLOCKED_AUTHORITY','BLOCKED_DEPENDENCY','NEEDS_RECONCILIATION','VERIFIED'];
    const MAX_EVENT_BYTES=6500;

    public static function policy($input=[]) {
        if(!self::can_read())return self::err('read_denied');
        $resolved=self::resolve($input);
        if(is_wp_error($resolved))return $resolved;
        return MAD4B_Unified_Governed_Lifecycle_Policy::plan($resolved);
    }
    public static function scoped() {
        return wp_get_environment_type()==='staging'
            && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com'
            && getenv('MAD4B_CENTRAL_HUB_ENABLED')==='1';
    }
    public static function can_read() {
        return self::scoped() && current_user_can('manage_options');
    }
    public static function can_write() {
        return self::can_read() && class_exists('MAD4B_SCP_Policy')
            && MAD4B_SCP_Policy::can_mutate();
    }
    private static function err($code) {
        return new WP_Error('mad4b_lifecycle_'.$code,$code,['status'=>409]);
    }
    private static function hash($v) {
        return hash('sha256',wp_json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    private static function key($id) {return self::PREFIX.$id;}
    private static function clean(array $input) {
        $goal=sanitize_key((string)($input['goal']??''));
        $kind=sanitize_key((string)($input['target_kind']??''));
        $id=absint($input['target_id']??0);
        $job=strtolower(trim((string)($input['job_id']??'')));
        $company=(string)($input['company_code']??'');
        $manager=(string)($input['manager_id']??'');
        $representation=(string)($input['representation_mode']??'');
        $event=sanitize_key((string)($input['event_type']??'manual'));
        $nonce=(string)($input['event_key']??'');
        // Fail closed on malformed source keys rather than silently sanitizing
        // them into a new replay identity.
        if(!in_array($goal,self::GOALS,true)
           || !in_array($kind,['post','term','attachment','company','content_job'],true)
           || !$id && !in_array($kind,['company','content_job'],true)
           || !preg_match('/^[a-z][a-z0-9_-]{2,63}$/D',$event)
           || strlen($nonce)>120 || ($nonce!==''&&!preg_match('/^[a-zA-Z0-9._:-]+$/D',$nonce))
           || ($job!==''&&!wp_is_uuid($job))
           || ($company!==''&&!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company))
           || ($manager!==''&&!wp_is_uuid($manager))
           || !in_array($representation,['','central_admin_proxy'],true)) return self::err('invalid_event');
        if($representation==='central_admin_proxy' && $kind!=='company')
            return self::err('representation_scope_mismatch');
        if($kind==='company' && ($company==='' ||
           ($manager==='' && $representation!=='central_admin_proxy')))
            return self::err('company_scope_required');
        if($kind==='content_job' && $job==='')return self::err('job_identity_required');
        return ['goal'=>$goal,'target_kind'=>$kind,'target_id'=>$id,'job_id'=>$job,
            'company_code'=>$company,'manager_id'=>$manager,
            'representation_mode'=>$representation,
            'event_type'=>$event,'event_key'=>$nonce];
    }
    private static function object_scope($data,$trusted_reconcile=false) {
        $id=$data['target_id'];$kind=$data['target_kind'];
        if($kind==='post'||$kind==='attachment'){
            if(!$id||!get_post($id))return self::err('post_missing');
            if($kind==='attachment'&&get_post_type($id)!=='attachment')return self::err('attachment_invalid');
            if($kind==='post'&&in_array(get_post_type($id),['attachment','revision'],true))return self::err('post_invalid');
        }elseif($kind==='term'){
            if(!$id||is_wp_error(get_term($id))||!get_term($id))return self::err('term_missing');
        }
        if($kind==='company'){
            if(!class_exists('MAD4B_Company_Portfolio_Manager_V1'))
                return self::err('company_registry_unavailable');
            $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding($data['company_code']);
            if(is_wp_error($binding))return $binding;
            if(($data['representation_mode']??'')==='central_admin_proxy') {
                // The represented company is a binding, not a substituted WP
                // user. The originating admin remains the real principal.
                $actor=$trusted_reconcile
                    ?absint($data['actor_user_id']??0):get_current_user_id();
                if(!$actor || !user_can($actor,'manage_options') ||
                   !user_can($actor,'activate_plugins') ||
                   (!$trusted_reconcile && get_current_user_id()!==$actor))
                    return self::err('platform_admin_authority_required');
            } else {
                $manager=MAD4B_Company_Portfolio_Manager_V1::status($data['manager_id'],$data['company_code']);
                if(is_wp_error($manager))return $manager;
            }
        }
        if($kind==='content_job'&&!class_exists('MAD4B_SCP_Content_Jobs'))
            return self::err('content_job_provider_missing');
        if($data['job_id']!=='' && class_exists('MAD4B_SCP_Content_Jobs')){
            $record=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$data['job_id']]);
            if(is_wp_error($record))return $record;
            $job=$record['job']??null;
            if(!is_array($job))return self::err('job_unavailable');
            // Binding to a brand comes from the canonical ContentJob, never caller input.
            if(class_exists('MAD4B_SCP_Context_Authority')){
                $profile=MAD4B_SCP_Context_Authority::profile();
                if(!empty($profile['brand_id'])&&!empty($job['brand_id'])
                   && $job['brand_id']!==$profile['brand_id'])
                    return self::err('brand_mismatch');
            }
        }
        return true;
    }
    private static function abilities_for($goal) {
        $all=[
            'seo'=>[['mad4b/seo-cycle-plan','mad4b/seo-cycle-evaluate','mad4b/seo-image-plan'],
                    ['seo/update-meta'],'rank_math'],
            'media'=>[['media/get','mad4b/seo-image-plan','mad4b/seo-render-audit'],
                    ['media/update-metadata'],'media'],
            'content'=>[['mad4b/content-job-get','mad4b/hub-dynamic-runtime-resolve'],
                    ['mad4b/content-apply-bundle'],'content'],
            'company'=>[['mad4b/company-profile-read','mad4b/company-strategy-status'],
                    ['mad4b/company-strategy-apply'],'company'],
        ];
        return $all[$goal];
    }
    private static function capability_present($name) {
        return function_exists('wp_has_ability') && wp_has_ability($name);
    }
    public static function resolve($input=[],$trusted_reconcile=false) {
        if(!self::scoped())return self::err('staging_scope');
        $data=self::clean(is_array($input)?$input:[]);
        if(is_wp_error($data))return $data;
        if(($data['representation_mode']??'')==='central_admin_proxy')
            $data['actor_user_id']=$trusted_reconcile
                ?absint($input['actor_user_id']??0):get_current_user_id();
        $scope=self::object_scope($data,$trusted_reconcile);
        if(is_wp_error($scope))return $scope;
        [$reads,$writes,$provider]=self::abilities_for($data['goal']);
        $available=[];$missing=[];$write=[];
        foreach($reads as $ability){
            if(self::capability_present($ability))$available[]=$ability;
            else $missing[]=$ability;
        }
        foreach($writes as $ability)$write[$ability]=self::capability_present($ability);
        $authority=[];
        if($data['goal']==='seo' && class_exists('MAD4B_SCP_Provider_Compatibility_Certification')){
            $s=MAD4B_SCP_Provider_Compatibility_Certification::ability_status('rank_math','seo/update-meta');
            if(is_array($s)&&!empty($s)&&empty($s['write_eligible'])){
                $authority[]='provider_write_not_certified';
                if(!empty($s['artifact_authority_required']))$authority[]='provider_artifact_authority_missing';
            }
        }
        foreach($write as $ability=>$registered)if(!$registered)$authority[]='write_ability_unregistered:'.$ability;
        // A background worker never acquires the user's write identity.
        // Delegated execution must go through the originating native Ability.
        // Discovery alone cannot establish mutation readiness. The provider
        // must supply positive write certification before READY is considered.
        $write_certified=false;
        if($data['goal']==='seo' && isset($s) && is_array($s))
            $write_certified=!empty($s['write_eligible']);
        $state=$authority?'BLOCKED_AUTHORITY':
            (!empty($available)&&empty($missing)&&$write_certified?'READY':'PARTIAL');
        $suggestions=[];
        // Registry discovery is advisory. It cannot make a missing or uncertified
        // write capability executable; exact provider preflight always wins.
        if(function_exists('wp_get_abilities')){
            foreach(wp_get_abilities() as $name=>$ability) {
                if(!is_string($name)||count($suggestions)>=12)break;
                if((bool)preg_match('/(^|[\/\-_])'.preg_quote($data['goal'],'/').'/i',$name)
                    && !in_array($name,array_merge($reads,$writes),true))
                    $suggestions[]=$name;
            }
        }
        $context=[
            'contract'=>self::CONTRACT.'.resolution.v1',
            'site'=>home_url('/'),'environment'=>wp_get_environment_type(),
            'company_scope'=>'site_enrollment','goal'=>$data['goal'],
            'target_kind'=>$data['target_kind'],'target_id'=>$data['target_id'],
            'content_job_id'=>$data['job_id'],
            'company_code'=>$data['company_code'],
            'representation_mode'=>$data['representation_mode'],
            'administrator_principal_user_id'=>$data['actor_user_id']??0,
            'company_ownership_transferred'=>false,
            'representation_is_not_provider_grant'=>true,
            'read_abilities'=>$available,'missing_read_abilities'=>$missing,
            'dynamically_discovered_candidates'=>$suggestions,
            'write_abilities_registered'=>$write,'provider_family'=>$provider,
            'authority_blockers'=>array_values(array_unique($authority)),
            'state'=>$state,'recovery_route'=>'',
            'write_identity_required_at_execution'=>true,
            'provider_write_certified'=>$write_certified,
            'readiness_is_not_execution_authority'=>true,
            'mutation_authorized'=>false,'auto_write_performed'=>false,
            'handoff_owner'=>$data['goal']==='content'?'MAD4B_Hub_Dynamic_Runtime_Resolver':
                ($data['goal']==='company'?'MAD4B_Company_Operations':'native_wordpress_ability'),
            'source_of_truth'=>'existing_provider_adapters_and_content_hub_queue'];
        if(class_exists('MAD4B_SCP_Recovery_Route_Resolver') && $authority
           && class_exists('MAD4B_SCP_Policy') && MAD4B_SCP_Policy::can_read()){
            // Only route names: do not execute a fallback mutation.
            $context['recovery_route']='read_only_governed_provider_recertification';
        }
        $context['resolution_sha256']=self::hash($context);
        $graph=MAD4B_Unified_Governed_Lifecycle_Policy::plan($context);
        if(!is_wp_error($graph)){
            $context['workflow_state']=$graph['state'];
            $context['independent_ready_steps']=$graph['ready_independent_steps'];
            $context['pending_steps']=$graph['pending_steps'];
            $context['dependency_graph']=$graph;
            // Preserve the legacy state for old clients while independent
            // read tasks proceed under an isolated write-authority blocker.
        }
        return $context;
    }
    public static function record($input=[],$internal=false,$source_receipt=null) {
        if(!self::scoped())return self::err('staging_scope');
        if(!$internal&&!self::can_write())return self::err('write_permission');
        $event=self::clean(is_array($input)?$input:[]);
        if(is_wp_error($event))return $event;
        if(($event['representation_mode']??'')==='central_admin_proxy'){
            // Internal WordPress hooks cannot forge the current administrator.
            if(!current_user_can('manage_options') || !current_user_can('activate_plugins'))
                return self::err('platform_admin_authority_required');
            $event['actor_user_id']=get_current_user_id();
        }
        $scope=self::object_scope($event);
        if(is_wp_error($scope))return $scope;
        // Event-key is stable per source revision; replays read one durable row.
        $identity=[
            'site'=>home_url('/'),'goal'=>$event['goal'],
            'kind'=>$event['target_kind'],'target'=>$event['target_id'],
            'job'=>$event['job_id'],'company'=>$event['company_code'],
            'manager'=>$event['manager_id'],
            'representation'=>$event['representation_mode'],
            'administrator_actor'=>$event['actor_user_id']??0,
            'type'=>$event['event_type'],
            'revision'=>$event['event_key'],
        ];
        $id=self::hash($identity);$key=self::key($id);
        $exists=get_option($key,false);
        if(is_array($exists)){
            if(($exists['event_id']??'')!==$id)return self::err('event_integrity');
            return ['contract'=>self::CONTRACT.'.record.v1','event_id'=>$id,
                'idempotent_replay'=>true,'state'=>$exists['state'],'scheduled'=>!empty($exists['scheduled']),
                'mutation_performed'=>false];
        }
        // Only authenticated native producer hooks may attach a source receipt.
        // REST / manual record cannot invent a completed ContentJob.
        $trusted_receipt=$internal && is_array($source_receipt)
            && ($source_receipt['source']??'')==='content_job_transition'
            && ($event['target_kind']==='content_job')
            ? [
                'source'=>'content_job_transition',
                'revision'=>(int)($source_receipt['revision']??0),
                'state'=>(string)($source_receipt['state']??''),
                'stage'=>(string)($source_receipt['stage']??''),
              ] : null;
        $row=['contract'=>self::CONTRACT.'.event.v1','event_id'=>$id,'event'=>$event,
            'identity_sha256'=>self::hash($identity),
            'state'=>'RECOVERING','attempts'=>0,
            'created_at'=>gmdate('c'),'updated_at'=>gmdate('c'),
            'retry_after'=>0,'last_error'=>'','scheduled'=>false,
            'resolution'=>null,'write_executed'=>false,
            'source_receipt'=>$trusted_receipt,'verification_scope'=>''];
        if(strlen(wp_json_encode($row))>self::MAX_EVENT_BYTES)return self::err('event_oversize');
        if(!add_option($key,$row,'',false)){
            $fresh=get_option($key,false);
            if(!is_array($fresh)||($fresh['event_id']??'')!==$id)
                return self::err('concurrent_event_conflict');
            return ['contract'=>self::CONTRACT.'.record.v1','event_id'=>$id,
                'idempotent_replay'=>true,'state'=>$fresh['state'],'mutation_performed'=>false];
        }
        $scheduled=wp_schedule_single_event(time()+20,self::CRON,[$id]);
        if($scheduled){
            $row['scheduled']=true;
            update_option($key,$row,false);
        }
        return ['contract'=>self::CONTRACT.'.record.v1','event_id'=>$id,
            'idempotent_replay'=>false,'state'=>'RECOVERING','scheduled'=>(bool)$scheduled,
            'mutation_performed'=>true,'provider_mutation_performed'=>false];
    }
    private static function source_verified(array $row) {
        $receipt=$row['source_receipt']??null;
        if(!is_array($receipt)||($receipt['source']??'')!=='content_job_transition'
           ||($receipt['state']??'')!=='COMPLETED'||(int)($receipt['revision']??0)<2)
            return null;
        $job_id=(string)($row['event']['job_id']??'');
        if(!wp_is_uuid($job_id)||!class_exists('MAD4B_SCP_Content_Jobs'))
            return null;
        $actual=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
        if(is_wp_error($actual)||!is_array($actual['job']??null))return null;
        $job=$actual['job'];
        if((int)($job['job_revision']??0)!==$receipt['revision']
           ||(string)($job['state']??'')!=='COMPLETED'
           ||(string)($job['stage']??'')!==$receipt['stage'])return null;
        return [
            'contract'=>self::CONTRACT.'.source-receipt.v1',
            'scope'=>'content_job_terminal_transition_only',
            'job_id'=>$job_id,'job_revision'=>$receipt['revision'],
            'state'=>'COMPLETED','stage'=>$receipt['stage'],
            'source_of_truth'=>'MAD4B_SCP_Content_Jobs::get_job',
            'post_publication_verified'=>false,
            'provider_seo_write_verified'=>false,
            'content_job_transition_verified'=>true,
            'readback_sha256'=>self::hash([$job_id,$receipt['revision'],$receipt['stage'],'COMPLETED']),
        ];
    }
    public static function reconcile($id,$refresh=false) {
        if(!self::scoped()||!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id))return self::err('invalid_event_id');
        // One native MySQL advisory lock per source event prevents two Cron /
        // manual reconciliations from overwriting a newer VERIFIED receipt.
        global $wpdb;
        $mutex='mad4b_lifecycle_'.substr($id,0,32);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$mutex))!==1)
            return self::err('reconciliation_busy');
        try {
        $key=self::key($id);$row=get_option($key,false);
        if(!is_array($row)||($row['event_id']??'')!==$id)return self::err('event_missing');
        if(!in_array($row['state']??'',self::STATES,true))return self::err('state_invalid');
        // Reconciliation of an uncertain write is not ordinary readiness
        // resolution. Preserve the state until the original provider supplies
        // exact before/after evidence; never let Cron overwrite it.
        if(in_array($row['state'],['VERIFIED','NEEDS_RECONCILIATION','RUNNING'],true))
            return self::status(['event_id'=>$id],true);
        if(!$refresh&&in_array($row['state'],['BLOCKED_AUTHORITY','READY'],true))
            return self::status(['event_id'=>$id],true);
        if(!$refresh&&(int)$row['attempts']>=3)return self::status(['event_id'=>$id],true);
        if(!$refresh&&(int)($row['retry_after']??0)>time())
            return self::status(['event_id'=>$id],true);
        $row['attempts']=min(3,(int)$row['attempts']+1);
        $resolved=self::resolve($row['event'],true);
        if(is_wp_error($resolved)){
            $code=$resolved->get_error_code();
            $classification=MAD4B_Unified_Governed_Lifecycle_Policy::classify_error($resolved);
            $row['state']=$classification['state'];
            $row['last_error']=$code;
            $row['recovery_action']=$classification['action'];
            $row['retry_after']=in_array($row['state'],['RECOVERING','BLOCKED_DEPENDENCY'],true)
                ?time()+min(3600,30*(2**min(5,$row['attempts']))):0;
        }else{
            // Persist only bounded status and hashes. The full dependency graph
            // is computed on demand, never duplicated into each event journal.
            $compact=$resolved;
            unset($compact['dependency_graph']);
            $row['resolution']=$compact;
            $row['state']=$resolved['state'];
            $row['last_error']='';
            $row['retry_after']=0;
            $verified=self::source_verified($row);
            if(is_array($verified)){
                $row['state']='VERIFIED';
                $row['verification_scope']=$verified['scope'];
                $row['verification']=$verified;
            }
        }
        $row['updated_at']=gmdate('c');
        if(!update_option($key,$row,false))return self::err('journal_update_failed');
        $fresh=get_option($key,false);
        if(!is_array($fresh)||($fresh['state']??'')!==$row['state'])
            return self::err('journal_readback_failed');
        if($row['state']==='RECOVERING'&&$row['attempts']<3)
            wp_schedule_single_event($row['retry_after'],self::CRON,[$id]);
        // Provider readiness can be rechecked twice on bounded backoff.
        // Never auto-retry a rejected mutation or fake certification.
        if($row['state']==='BLOCKED_AUTHORITY'&&$row['attempts']<3) {
            $next=time()+min(3600,300*(2**max(0,$row['attempts']-1)));
            if(!wp_next_scheduled(self::CRON,[$id]))
                wp_schedule_single_event($next,self::CRON,[$id]);
        }
        return self::status(['event_id'=>$id],true);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$mutex));
        }
    }
    public static function status($input=[],$internal=false) {
        if(!$internal&&!self::can_read())return self::err('read_denied');
        $id=(string)($input['event_id']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$id))return self::err('event_id_invalid');
        $row=get_option(self::key($id),false);
        if(!is_array($row)||($row['event_id']??'')!==$id)return self::err('event_missing');
        return ['contract'=>self::CONTRACT.'.status.v1',
            'event_id'=>$id,'state'=>$row['state'],'goal'=>$row['event']['goal'],
            'target_kind'=>$row['event']['target_kind'],'target_id'=>$row['event']['target_id'],
            'company_code'=>$row['event']['company_code']??'',
            'representation_mode'=>$row['event']['representation_mode']??'',
            'administrator_actor_user_id'=>$row['event']['actor_user_id']??0,
            'attempts'=>$row['attempts'],'retry_after'=>$row['retry_after'],
            'last_error'=>$row['last_error'],
            'recovery_action'=>$row['recovery_action']??'',
            'workflow_state'=>$row['resolution']['workflow_state']??$row['state'],
            'independent_ready_steps'=>$row['resolution']['independent_ready_steps']??[],
            'pending_steps'=>$row['resolution']['pending_steps']??[],
            'resolution'=>$row['resolution'],
            'verification_scope'=>$row['verification_scope']??'',
            'verification'=>$row['verification']??null,
            'provider_mutation_performed'=>false,
            'verified'=>($row['state']==='VERIFIED'&&is_array($row['verification']??null)),
            'manual_handoff_required'=>in_array($row['state'],['READY','BLOCKED_AUTHORITY'],true),
            'does_not_mint_approvals'=>true];
    }
    public static function manual_reconcile($input=[]) {
        if(!self::can_write())return self::err('write_permission');
        if(!is_array($input))return self::err('input_invalid');
        return self::reconcile((string)($input['event_id']??''),true);
    }
    public static function cron($id) {self::reconcile($id,true);}
    public static function content_job_event($payload,$event_type) {
        if(!self::scoped()||!is_array($payload))return;
        $job=(string)($payload['job_id']??'');
        $revision=(int)($payload['job_revision']??0);
        if(!wp_is_uuid($job)||$revision<1)return;
        // Read back the canonical post-commit job before accepting a hook.
        $canonical=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job]);
        if(is_wp_error($canonical)||!is_array($canonical['job']??null))return;
        $data=$canonical['job'];
        if((int)($data['job_revision']??0)!==$revision
           ||(string)($data['state']??'')!==(string)($payload['state']??'')
           ||(string)($data['stage']??'')!==(string)($payload['stage']??''))return;
        $event=['goal'=>'content','target_kind'=>'content_job','target_id'=>0,
            'job_id'=>$job,'event_type'=>$event_type,
            'event_key'=>'rev-'.$revision.'-'.strtolower((string)$data['state'])];
        $receipt=['source'=>'content_job_transition','revision'=>$revision,
            'state'=>(string)$data['state'],'stage'=>(string)$data['stage']];
        $result=self::record($event,true,$receipt);
        // For final ContentJob transitions, verify exact source readback now;
        // no claim about external publication or SEO score.
        if(!is_wp_error($result) && $receipt['state']==='COMPLETED')
            self::reconcile($result['event_id'],true);
    }
    public static function content_job_created($payload) {
        self::content_job_event($payload,'content_job_created');
    }
    public static function content_job_transitioned($payload) {
        self::content_job_event($payload,'content_job_transitioned');
    }
    public static function hub_task_finished($payload) {
        if(!self::scoped()||!is_array($payload)||!wp_is_uuid((string)($payload['content_job_id']??'')))return;
        $job=(string)$payload['content_job_id'];
        $record=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job]);
        if(is_wp_error($record)||!is_array($record['job']??null))return;
        $task=(string)($payload['task_id']??'');
        if(!wp_is_uuid($task)||!class_exists('MAD4B_Hub_Bulk_Jobs_Queue'))return;
        $queue=MAD4B_Hub_Bulk_Jobs_Queue::list_queue(['job_id'=>$job]);
        if(is_wp_error($queue)||!is_array($queue['batches']??null))return;
        $found=false;
        foreach($queue['batches'] as $batch) {
            foreach(($batch['tasks']??[]) as $live) {
                if(($live['task_id']??'')!==$task)continue;
                if(($live['content_job_id']??'')!==$job ||
                   ($live['state']??'')!==($payload['state']??'') ||
                   ($live['stage']??'')!==($payload['stage']??''))return;
                $found=true;break 2;
            }
        }
        if(!$found)return; // A forged task-finished action is not evidence.
        $event=['goal'=>'content','target_kind'=>'content_job','target_id'=>0,
            'job_id'=>$job,'event_type'=>'hub_task_finished',
            'event_key'=>substr(hash('sha256',$task.'|'.($payload['state']??'')),0,48)];
        self::record($event,true); // Task completion does not prove publication.
    }
    public static function company_transition($payload) {
        if(!self::scoped()||!is_array($payload))return;
        $company=(string)($payload['company_code']??'');
        $manager=(string)($payload['manager_id']??'');
        $revision=(int)($payload['revision']??0);
        $state=(string)($payload['state']??'');
        if(!wp_is_uuid($manager)||$revision<1)return;
        if(!class_exists('MAD4B_Company_Portfolio_Manager_V1'))return;
        $live=MAD4B_Company_Portfolio_Manager_V1::status($manager,$company);
        if(is_wp_error($live)||($live['revision']??null)!==$revision
           ||($live['state']??'')!==$state
           ||empty($live['binding_current']))return;
        $key='rev-'.$revision.'-'.$state.'-'.substr((string)$live['binding_sha256'],0,20);
        self::record(['goal'=>'company','target_kind'=>'company',
            'target_id'=>0,'manager_id'=>$manager,'company_code'=>$company,
            'event_type'=>'company_transition','event_key'=>$key],true);
    }
    public static function trigger_post($id,$post,$updated) {
        if(!self::scoped()||wp_is_post_revision($id)||!is_object($post) ||
           !in_array($post->post_type,['post','page','tours-and-activities','product'],true))
            return;
        if(!in_array($post->post_status,['publish','draft','pending','future'],true))return;
        $rev=hash('sha256',(string)$post->post_modified_gmt.'|'.$post->post_status.'|'.
            $post->post_title.'|'.hash('sha256',(string)$post->post_content));
        self::record(['goal'=>'seo','target_kind'=>'post','target_id'=>(int)$id,
            'event_type'=>$updated?'post_updated':'post_created','event_key'=>$rev],true);
    }
    public static function trigger_term($id,$ttid,$taxonomy) {
        if(!self::scoped())return;
        $term=get_term($id,$taxonomy);if(!$term||is_wp_error($term))return;
        $revision=self::hash([$taxonomy,$term->name,$term->description]);
        self::record(['goal'=>'seo','target_kind'=>'term','target_id'=>(int)$id,
            'event_type'=>'term_edited','event_key'=>$revision],true);
    }
    public static function trigger_media($id) {
        if(!self::scoped()||!wp_attachment_is_image($id))return;
        $p=get_post($id);if(!$p)return;
        $revision=self::hash([$p->post_modified_gmt,get_post_meta($id,'_wp_attachment_image_alt',true)]);
        self::record(['goal'=>'media','target_kind'=>'attachment','target_id'=>(int)$id,
            'event_type'=>'media_added','event_key'=>$revision],true);
    }
    public static function trigger_media_meta($meta_id,$post_id,$key,$value) {
        if(!self::scoped())return;
        if($key==='_wp_attachment_image_alt') {
            self::trigger_media((int)$post_id);
            return;
        }
        if(!in_array($key,['rank_math_title','rank_math_description','rank_math_focus_keyword'],true))
            return;
        $post=get_post($post_id);
        if(!$post||in_array($post->post_type,['attachment','revision'],true))return;
        // The value only contributes a digest and is never saved in the event.
        $revision=self::hash([$key,$value,$post->post_modified_gmt]);
        self::record(['goal'=>'seo','target_kind'=>'post','target_id'=>(int)$post_id,
            'event_type'=>'seo_meta_updated','event_key'=>$revision],true);
    }
    public static function provider_readiness_changed($event_id) {
        if(!self::scoped()||!is_string($event_id)||
           !preg_match('/^[a-f0-9]{64}$/D',$event_id))return;
        $record=get_option(self::key($event_id),false);
        if(!is_array($record)||($record['event_id']??'')!==$event_id)return;
        self::reconcile($event_id,true);
    }
    public static function external_event($event) {
        if(!self::scoped()||!is_array($event))return;
        self::record($event,true);
    }
    public static function routes() {
        $base='mad4b/lifecycle/v1';
        foreach(['resolve'=>['resolve','GET'],'record'=>['record','POST'],
            'status'=>['status','GET'],'reconcile'=>['manual_reconcile','POST'],
            'policy'=>['policy','GET']] as $route=>$def){
            register_rest_route($base,'/'.$route,[
                'methods'=>$def[1],
                'permission_callback'=>[__CLASS__,in_array($route,['record','reconcile'],true)?'can_write':'can_read'],
                'callback'=>static function($req)use($def){
                    return rest_ensure_response(call_user_func(
                        [__CLASS__,$def[0]],$def[1]==='POST'?(array)$req->get_json_params():$req->get_params()));
                }]);
        }
    }
    public static function abilities() {
        if(!function_exists('wp_register_ability'))return;
        foreach(['mad4b/lifecycle-resolve'=>['resolve',true],
            'mad4b/lifecycle-record'=>['record',false],
            'mad4b/lifecycle-status'=>['status',true],
            'mad4b/lifecycle-reconcile'=>['manual_reconcile',false],
            'mad4b/lifecycle-policy-plan'=>['policy',true]] as $name=>$def){
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,[
                'label'=>$name,'description'=>'Central non-authorizing Staging runtime orchestration.',
                'category'=>$def[1]?'mad4b-read':'mad4b-admin',
                'execute_callback'=>[__CLASS__,$def[0]],
                'permission_callback'=>[__CLASS__,$def[1]?'can_read':'can_write'],
                'input_schema'=>['type'=>'object','properties'=>[
                    'goal'=>['type'=>'string'],'target_kind'=>['type'=>'string'],
                    'target_id'=>['type'=>'integer'],'job_id'=>['type'=>'string'],
                    'company_code'=>['type'=>'string'],'manager_id'=>['type'=>'string'],
                    'representation_mode'=>['type'=>'string'],
                    'event_type'=>['type'=>'string'],'event_key'=>['type'=>'string'],
                    'event_id'=>['type'=>'string']],
                    'additionalProperties'=>false],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>['public'=>false,'show_in_rest'=>false,
                    'mcp'=>['public'=>false,'type'=>'tool','surface'=>$def[1]?'read':'admin'],
                    'annotations'=>['readonly'=>$def[1],'destructive'=>false,'idempotent'=>true]]]);
        }
    }
    public static function boot() {
        if(!self::scoped())return;
        add_action('wp_abilities_api_init',[__CLASS__,'abilities'],98);
        add_action('rest_api_init',[__CLASS__,'routes'],98);
        add_action('admin_menu',
            ['MAD4B_Unified_Governed_Lifecycle_Policy','admin_menu'],98);
        add_action(self::CRON,[__CLASS__,'cron'],10,1);
        add_action('save_post',[__CLASS__,'trigger_post'],35,3);
        add_action('edited_term',[__CLASS__,'trigger_term'],35,3);
        add_action('add_attachment',[__CLASS__,'trigger_media'],35,1);
        add_action('updated_post_meta',[__CLASS__,'trigger_media_meta'],70,4);
        add_action('added_post_meta',[__CLASS__,'trigger_media_meta'],70,4);
        add_action('mad4b_scp_content_job_created',[__CLASS__,'content_job_created'],35,1);
        add_action('mad4b_scp_content_job_transitioned',[__CLASS__,'content_job_transitioned'],35,1);
        add_action('mad4b_hub_bulk_task_finished',[__CLASS__,'hub_task_finished'],35,1);
        add_action('mad4b_company_portfolio_transitioned',[__CLASS__,'company_transition'],35,1);
        add_action('mad4b_lifecycle_event',[__CLASS__,'external_event'],35,1);
        add_action('mad4b_lifecycle_provider_readiness_changed',
            [__CLASS__,'provider_readiness_changed'],35,1);
    }
}
require_once __DIR__ . '/class-mad4b-unified-governed-lifecycle-policy.php';
require_once __DIR__ . '/class-mad4b-lifecycle-native-receipt.php';
add_action('plugins_loaded',['MAD4B_Lifecycle_Native_Receipt','boot'],121);
add_action('plugins_loaded',['MAD4B_Lifecycle_Orchestrator','boot'],120);
