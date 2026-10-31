<?php
/**
 * Plugin Name: MAD4B Lifecycle Orchestrator
 * Description: Central staging-only event and manual runtime coordination; never grants provider write permissions.
 * Version: 0.1.0-staging
 * Author: MAD4B
 */
defined('ABSPATH') || exit;

final class MAD4B_Lifecycle_Orchestrator {
    const CONTRACT='mad4b.lifecycle-orchestrator.v1';
    const CRON='mad4b_lifecycle_reconcile_event_v1';
    const PREFIX='mad4b_lifecycle_event_v1_';
    const GOALS=['seo','media','content','company'];
    const STATES=['READY','RECOVERING','PARTIAL','BLOCKED_AUTHORITY','VERIFIED'];
    const MAX_EVENT_BYTES=6500;

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
        $event=sanitize_key((string)($input['event_type']??'manual'));
        $nonce=(string)($input['event_key']??'');
        // Fail closed on malformed source keys rather than silently sanitizing
        // them into a new replay identity.
        if(!in_array($goal,self::GOALS,true)
           || !in_array($kind,['post','term','attachment','company','content_job'],true)
           || !$id && !in_array($kind,['company','content_job'],true)
           || !preg_match('/^[a-z][a-z0-9_-]{2,63}$/D',$event)
           || strlen($nonce)>120 || ($nonce!==''&&!preg_match('/^[a-zA-Z0-9._:-]+$/D',$nonce))
           || ($job!==''&&!wp_is_uuid($job))) return self::err('invalid_event');
        if(in_array($kind,['company','content_job'],true)&&$job==='' && $nonce==='')
            return self::err('identity_required');
        return ['goal'=>$goal,'target_kind'=>$kind,'target_id'=>$id,'job_id'=>$job,
            'event_type'=>$event,'event_key'=>$nonce];
    }
    private static function object_scope($data) {
        $id=$data['target_id'];$kind=$data['target_kind'];
        if($kind==='post'||$kind==='attachment'){
            if(!$id||!get_post($id))return self::err('post_missing');
            if($kind==='attachment'&&get_post_type($id)!=='attachment')return self::err('attachment_invalid');
            if($kind==='post'&&in_array(get_post_type($id),['attachment','revision'],true))return self::err('post_invalid');
        }elseif($kind==='term'){
            if(!$id||is_wp_error(get_term($id))||!get_term($id))return self::err('term_missing');
        }
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
    public static function resolve($input=[]) {
        if(!self::scoped())return self::err('staging_scope');
        $data=self::clean(is_array($input)?$input:[]);
        if(is_wp_error($data))return $data;
        $scope=self::object_scope($data);
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
        $state=$authority?'BLOCKED_AUTHORITY':($available&&empty($missing)?'READY':'PARTIAL');
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
            'read_abilities'=>$available,'missing_read_abilities'=>$missing,
            'dynamically_discovered_candidates'=>$suggestions,
            'write_abilities_registered'=>$write,'provider_family'=>$provider,
            'authority_blockers'=>array_values(array_unique($authority)),
            'state'=>$state,'recovery_route'=>'',
            'write_identity_required_at_execution'=>true,
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
        return $context;
    }
    public static function record($input=[],$internal=false) {
        if(!self::scoped())return self::err('staging_scope');
        if(!$internal&&!self::can_write())return self::err('write_permission');
        $event=self::clean(is_array($input)?$input:[]);
        if(is_wp_error($event))return $event;
        $scope=self::object_scope($event);
        if(is_wp_error($scope))return $scope;
        // Event-key is stable per source revision; replays read one durable row.
        $identity=[
            'site'=>home_url('/'),'goal'=>$event['goal'],
            'kind'=>$event['target_kind'],'target'=>$event['target_id'],
            'job'=>$event['job_id'],'type'=>$event['event_type'],
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
        $row=['contract'=>self::CONTRACT.'.event.v1','event_id'=>$id,'event'=>$event,
            'identity_sha256'=>self::hash($identity),
            'state'=>'RECOVERING','attempts'=>0,
            'created_at'=>gmdate('c'),'updated_at'=>gmdate('c'),
            'retry_after'=>0,'last_error'=>'','scheduled'=>false,
            'resolution'=>null,'write_executed'=>false];
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
    public static function reconcile($id) {
        if(!self::scoped()||!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id))return self::err('invalid_event_id');
        $key=self::key($id);$row=get_option($key,false);
        if(!is_array($row)||($row['event_id']??'')!==$id)return self::err('event_missing');
        if(!in_array($row['state']??'',self::STATES,true))return self::err('state_invalid');
        if(in_array($row['state'],['VERIFIED','BLOCKED_AUTHORITY','READY'],true))
            return self::status(['event_id'=>$id],true);
        if((int)$row['attempts']>=3)return self::status(['event_id'=>$id],true);
        if((int)($row['retry_after']??0)>time())
            return self::status(['event_id'=>$id],true);
        $row['attempts']++;
        $resolved=self::resolve($row['event']);
        if(is_wp_error($resolved)){
            $code=$resolved->get_error_code();
            $authority=(bool)preg_match('/(authority|permission|scope|brand|denied|certif)/i',$code);
            $row['state']=$authority?'BLOCKED_AUTHORITY':'RECOVERING';
            $row['last_error']=$code;
            // Delay is bounded; no mutation is replayed.
            $row['retry_after']=time()+min(3600,30*(2**min(5,$row['attempts'])));
        }else{
            $row['resolution']=$resolved;
            $row['state']=$resolved['state'];
            $row['last_error']='';
            $row['retry_after']=0;
        }
        $row['updated_at']=gmdate('c');
        if(!update_option($key,$row,false))return self::err('journal_update_failed');
        $fresh=get_option($key,false);
        if(!is_array($fresh)||($fresh['state']??'')!==$row['state'])
            return self::err('journal_readback_failed');
        if($row['state']==='RECOVERING'&&$row['attempts']<3)
            wp_schedule_single_event($row['retry_after'],self::CRON,[$id]);
        return self::status(['event_id'=>$id],true);
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
            'attempts'=>$row['attempts'],'retry_after'=>$row['retry_after'],
            'last_error'=>$row['last_error'],'resolution'=>$row['resolution'],
            'provider_mutation_performed'=>false,'verified'=>false,
            'manual_handoff_required'=>in_array($row['state'],['READY','BLOCKED_AUTHORITY'],true),
            'does_not_mint_approvals'=>true];
    }
    public static function manual_reconcile($input=[]) {
        if(!self::can_write())return self::err('write_permission');
        if(!is_array($input))return self::err('input_invalid');
        return self::reconcile((string)($input['event_id']??''));
    }
    public static function cron($id) {self::reconcile($id);}
    public static function trigger_post($id,$post,$updated) {
        if(!self::scoped()||wp_is_post_revision($id)||!is_object($post) ||
           !in_array($post->post_type,['post','page','tours-and-activities','product'],true))
            return;
        if(!in_array($post->post_status,['publish','draft','pending','future'],true))return;
        $rev=hash('sha256',(string)$post->post_modified_gmt.'|'.$post->post_status.'|'.$post->post_title);
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
        if(!self::scoped()||$key!=='_wp_attachment_image_alt')return;
        self::trigger_media((int)$post_id);
    }
    public static function external_event($event) {
        if(!self::scoped()||!is_array($event))return;
        self::record($event,true);
    }
    public static function routes() {
        $base='mad4b/lifecycle/v1';
        foreach(['resolve'=>['resolve','GET'],'record'=>['record','POST'],
            'status'=>['status','GET'],'reconcile'=>['manual_reconcile','POST']] as $route=>$def){
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
            'mad4b/lifecycle-reconcile'=>['manual_reconcile',false]] as $name=>$def){
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,[
                'label'=>$name,'description'=>'Central non-authorizing Staging runtime orchestration.',
                'category'=>$def[1]?'mad4b-read':'mad4b-admin',
                'execute_callback'=>[__CLASS__,$def[0]],
                'permission_callback'=>[__CLASS__,$def[1]?'can_read':'can_write'],
                'input_schema'=>['type'=>'object','properties'=>[
                    'goal'=>['type'=>'string'],'target_kind'=>['type'=>'string'],
                    'target_id'=>['type'=>'integer'],'job_id'=>['type'=>'string'],
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
        add_action(self::CRON,[__CLASS__,'cron'],10,1);
        add_action('save_post',[__CLASS__,'trigger_post'],35,3);
        add_action('edited_term',[__CLASS__,'trigger_term'],35,3);
        add_action('add_attachment',[__CLASS__,'trigger_media'],35,1);
        add_action('updated_post_meta',[__CLASS__,'trigger_media_meta'],70,4);
        add_action('added_post_meta',[__CLASS__,'trigger_media_meta'],70,4);
        add_action('mad4b_lifecycle_event',[__CLASS__,'external_event'],35,1);
    }
}
add_action('plugins_loaded',['MAD4B_Lifecycle_Orchestrator','boot'],120);
