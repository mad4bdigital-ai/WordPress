<?php
/**
 * Plugin Name: MAD4B Translation Provider Routing
 * Description: Active ChatGPT MCP first, authorized synchronous external providers second. No implicit queue.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;

/**
 * Extend translation providers by registering an audited, site-scoped
 * adapter via the mad4b/translation/synchronous_adapters WordPress filter.
 * Implementations own secrets, provider consent, paid budgets and retry safety.
 */
interface MAD4B_Translation_Synchronous_Adapter {
    public function provider_id(): string;
    public function priority(): int;
    public function preflight(array $job): array;
    public function translate(array $job): array;
}

final class MAD4B_Translation_Provider_Router {
    const CONTRACT = 'mad4b.translation-provider-routing.v1';
    const MAX_ADAPTERS = 12;
    public static function boot(): void {
        add_action('wp_abilities_api_init',[__CLASS__,'register_abilities'],47);
        if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('mad4b translation-route',[__CLASS__,'cli']);
        }
    }
    private static function denied(string $code) {
        return new WP_Error('mad4b_translation_route_'.$code,$code);
    }
    private static function scope(bool $write=false) {
        $s=class_exists('MAD4B_SCP_Site_Profile')?MAD4B_SCP_Site_Profile::status():[];
        if(!is_array($s)||empty($s['site_uuid'])||empty($s['origin_match'])
            || empty($s['authority_ready'])||empty($s['deployment_binding_match'])
            || !class_exists('MAD4B_SCP_Policy') || !MAD4B_SCP_Policy::can_read()
            || get_current_user_id()<1) return self::denied('site_or_read_authority_missing');
        if($write && (!MAD4B_SCP_Policy::can_mutate()
            || !current_user_can('manage_options')))return self::denied('write_authority_missing');
        return ['site_uuid'=>$s['site_uuid'],'environment'=>wp_get_environment_type(),
            'user_id'=>get_current_user_id()];
    }
    /**
     * Never trust mcp_session_active supplied by a ContentJob, client payload,
     * WordPress option or synthetic user flag. Only current authenticated
     * WordPress request context can indicate a live MCP invocation.
     */
    public static function active_mcp_session():bool {
        return class_exists('MAD4B_SCP_MCP_Request_Scope',false)
            && MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()
            && class_exists('MAD4B_SCP_Policy')
            && MAD4B_SCP_Policy::can_read()
            && get_current_user_id()>0
            && MAD4B_SCP_Policy::can_connect_user(get_current_user_id());
    }
    private static function adapters():array {
        $incoming=apply_filters('mad4b/translation/synchronous_adapters',[]);
        if(!is_array($incoming)||count($incoming)>self::MAX_ADAPTERS)
            return [];
        $out=[];$conflicts=[];
        foreach($incoming as $a) {
            if(!$a instanceof MAD4B_Translation_Synchronous_Adapter)continue;
            try {$id=$a->provider_id();$priority=$a->priority();}
            catch(Throwable $e){continue;}
            if(!is_string($id)||!preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$id)
                ||$id==='chatgpt_mcp_session'||!is_int($priority)||$priority<1||$priority>100)
                continue;
            if(isset($out[$id])||isset($conflicts[$id])){
                unset($out[$id]);$conflicts[$id]=true;continue;
            }
            $out[$id]=$a;
        }
        uasort($out,static function($a,$b){
            return $a->priority()<=>$b->priority()
                ?:strcmp($a->provider_id(),$b->provider_id());
        });
        return $out;
    }
    private static function request(string $issue_id) {
        if(!preg_match('/^[a-f0-9]{64}$/D',$issue_id))
            return self::denied('issue_id_invalid');
        if(!class_exists('MAD4B_SCP_Content_Readiness_Alerts')
            ||!class_exists('MAD4B_SCP_Content_Jobs'))return self::denied('contentjob_unavailable');
        $ledger=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]);
        $issue=$ledger[$issue_id]??null;
        if(!is_array($issue)||($issue['id']??'')!==$issue_id
            ||($issue['status']??'')!=='open'
            ||($issue['source']??'')!=='dynamic-filter-seo-bridge'
            ||($issue['content_job_request']['deduplication_key']??'')!==$issue_id
            ||empty($issue['content_job_id'])
            ||!preg_match('/^dfsb_wpml_(term_missing|route_slug|base_slug_unverified|cpt_base_unverified)_([a-z0-9_-]+)_([0-9]+)_([a-z]{2})$/D',
                (string)$issue['code'],$parts))
            return self::denied('unlinked_or_invalid_issue');
        $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$issue['content_job_id']]);
        if(is_wp_error($job)||($job['job']['content_type']??'')!=='translation'
            ||($job['job']['language']??'')!==$parts[4]
            ||!in_array($job['job']['state']??'',[
                'QUEUED','RUNNING','WAITING_REVIEW','BLOCKED'],true)
            ||!str_contains((string)($job['job']['subject']??''),'wpml-url-issue-ref:'.$issue_id))
            return self::denied('native_job_binding_invalid');
        $kind=$parts[1];$taxonomy=$parts[2];$source_id=(int)$parts[3];
        $source=[];
        if(in_array($kind,['term_missing','route_slug'],true)){
            global $wpdb;
            $source=$wpdb->get_row($wpdb->prepare(
                "SELECT t.name,t.slug,tt.parent,tt.description FROM {$wpdb->terms} t
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
                 WHERE t.term_id=%d AND tt.taxonomy=%s LIMIT 1",$source_id,$taxonomy),ARRAY_A);
            if(!is_array($source))return self::denied('source_term_absent');
            $source=['name'=>(string)$source['name'],'slug'=>(string)$source['slug'],
                'description'=>wp_strip_all_tags((string)$source['description']),
                'parent'=>(int)$source['parent']];
        } else {
            $obj=$kind==='cpt_base_unverified'?get_post_type_object($taxonomy):get_taxonomy($taxonomy);
            if(!$obj)return self::denied('source_rewrite_absent');
            $source=['rewrite_base'=>is_array($obj->rewrite)?($obj->rewrite['slug']??''):null];
        }
        $context=['contract'=>self::CONTRACT.'.request','issue_id'=>$issue_id,
            'job_id'=>$job['job']['job_id'],'job_revision'=>(int)$job['job']['job_revision'],
            'job_state'=>$job['job']['state'],
            'kind'=>$kind,'subject'=>$taxonomy,'source_id'=>$source_id,
            'locale'=>$parts[4],'source'=>$source];
        // Stable intent across job lifecycle revisions; revision is a separate
        // compare-and-swap fence, not part of a billable request identity.
        $identity=$context;
        unset($identity['job_state'],$identity['job_revision']);
        $context['source_sha256']=hash('sha256',wp_json_encode($identity,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $context['idempotency_key']='mad4b-translation-v1:'.$issue_id.':'.$context['source_sha256'];
        return $context;
    }
    /**
     * Deterministic pure routing policy. Status values originate only from
     * server-side adapter preflight; never from a public client request.
     */
    public static function choose(bool $mcp, array $providers):array {
        usort($providers,static function($a,$b){
            return ($a['priority']??100)<=>($b['priority']??100)
                ?:strcmp((string)($a['provider_id']??''),(string)($b['provider_id']??''));
        });
        $order=[['provider_id'=>'chatgpt_mcp_session','priority'=>0,
            'mode'=>'active_client_session','status'=>$mcp?'ready':'not_in_current_request']];
        $candidate=$mcp?'chatgpt_mcp_session':null;
        $pendingReason='';
        foreach($providers as $p){
            $order[]=$p;
            if($candidate===null && ($p['status']??'')==='ready')$candidate=$p['provider_id'];
            if($candidate===null && !$pendingReason)$pendingReason='no_connected_authorized_synchronous_provider';
        }
        return ['selected_provider'=>$candidate,'selection_state'=>$candidate?'READY':'HOLD_PROVIDER_UNAVAILABLE',
            'order'=>$order,'no_implicit_background_queue'=>true,
            'fallback_only_for'=>['not_connected','unavailable_before_execution','no_session'],
            'never_fallback_for'=>['timeout_unknown','quality_rejected','authorization_denied',
                'partial_write','source_changed','quota_uncertain'],
            'next_action'=>$candidate===null?'connect_or_invoke_active_mcp':
                ($candidate==='chatgpt_mcp_session'?'translate_in_active_chatgpt_and_submit_governed_wpml_writeback':
                'execute_registered_synchronous_adapter')];
    }
    private static function external_status(array $request):array {
        $status=[];
        foreach(self::adapters() as $id=>$adapter){
            try {$p=$adapter->preflight($request);}
            catch(Throwable $e){$p=[];}
            $ready=is_array($p) && ($p['connected']??null)===true
                &&($p['authorized']??null)===true
                &&($p['synchronous']??null)===true
                &&($p['site_uuid']??null)===MAD4B_SCP_Site_Profile::site_uuid()
                &&($p['budget_allowed']??null)===true
                &&($p['idempotency_enforced']??null)===true
                &&($p['language_supported']??null)===true;
            $status[]=['provider_id'=>$id,'priority'=>$adapter->priority(),'mode'=>'external_api',
                'status'=>$ready?'ready':'not_authorized_or_unavailable'];
        }
        return $status;
    }
    public static function plan($input=[]) {
        $scope=self::scope();if(is_wp_error($scope))return $scope;
        if(!is_array($input)||array_diff(array_keys($input),['issue_id']))
            return self::denied('invalid_plan_input');
        $request=self::request((string)($input['issue_id']??''));
        if(is_wp_error($request))return $request;
        $selection=self::choose(self::active_mcp_session(),self::external_status($request));
        return ['contract'=>self::CONTRACT.'.plan','site_uuid'=>$scope['site_uuid'],
            'environment'=>$scope['environment'],'request'=>$request,'routing'=>$selection,
            'model_invoked'=>false,'translation_written'=>false,'network_performed'=>false];
    }
    public static function run($input=[]) {
        $scope=self::scope(true);if(is_wp_error($scope))return $scope;
        $plan=self::plan($input);if(is_wp_error($plan))return $plan;
        $route=$plan['routing'];$request=$plan['request'];
        if($route['selected_provider']==='chatgpt_mcp_session')
            return ['contract'=>self::CONTRACT.'.dispatch','status'=>'ACTIVE_CLIENT_HANDOFF',
                'job_id'=>$request['job_id'],'issue_id'=>$request['issue_id'],
                'source_sha256'=>$request['source_sha256'],'request'=>$request,
                'writer_required'=>'MAD4B governed WPML writeback; revalidate source and job revision',
                'model_invoked_by_wordpress'=>false,'background_queued'=>false];
        if(!$route['selected_provider'])
            return ['contract'=>self::CONTRACT.'.dispatch','status'=>'HOLD_PROVIDER_UNAVAILABLE',
                'issue_id'=>$request['issue_id'],'background_queued'=>false,'paid_api_called'=>false];
        $attempts=[];
        global $wpdb;
        $lock='mad4b_translation_api_'.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)
            return self::denied('api_execution_in_flight');
        try {
        foreach(self::adapters() as $id=>$adapter){
            $ready=self::external_status($request);
            $found=null;foreach($ready as $item)if($item['provider_id']===$id){$found=$item;break;}
            if(($found['status']??'')!=='ready')continue;
            // Atomic single-intent claim in native WP options before any
            // billable network operation. Uncertain results stay HELD and
            // can never cause blind retry on a second provider.
            $attemptKey='mad4b_translation_attempt_'.hash('sha256',
                $request['idempotency_key'].'|'.$id);
            if(!add_option($attemptKey,['state'=>'CLAIMED','provider'=>$id,
                'site_uuid'=>$scope['site_uuid'],'job_id'=>$request['job_id'],
                'source_sha256'=>$request['source_sha256'],
                'at'=>gmdate('c')],'',false))
                return self::denied('existing_api_attempt_requires_reconciliation');
            try {$out=$adapter->translate($request);}
            catch(Throwable $e){
                update_option($attemptKey,['state'=>'UNKNOWN','provider'=>$id,
                    'at'=>gmdate('c')],false);
                return self::denied('external_result_uncertain_reconciliation_required');
            }
            if(is_wp_error($out)){
                // A network timeout is not proof that provider usage or a write
                // did not happen. Never trigger another billable attempt.
                if(in_array($out->get_error_code(),['provider_unavailable_before_execution'],true)){
                    update_option($attemptKey,['state'=>'NOT_STARTED','provider'=>$id,
                        'at'=>gmdate('c')],false);
                    $attempts[]=['provider_id'=>$id,'status'=>'unavailable_before_execution'];continue;
                }
                update_option($attemptKey,['state'=>'HELD','provider'=>$id,
                    'error_code'=>$out->get_error_code(),'at'=>gmdate('c')],false);
                return self::denied('external_execution_held_'.$out->get_error_code());
            }
            if(!is_array($out)||($out['source_sha256']??'')!==$request['source_sha256']
                ||($out['language']??'')!==$request['locale']
                ||!is_string($out['text']??null)||mb_strlen(trim($out['text']))<20)
            {
                update_option($attemptKey,['state'=>'INVALID_RESULT','provider'=>$id,
                    'at'=>gmdate('c')],false);
                return self::denied('provider_payload_invalid_no_retry');
            }
            update_option($attemptKey,['state'=>'PROPOSAL_RECEIVED','provider'=>$id,
                'result_sha256'=>hash('sha256',wp_json_encode($out,
                    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
                'at'=>gmdate('c')],false);
            return ['contract'=>self::CONTRACT.'.dispatch','status'=>'TRANSLATION_PROPOSAL_RECEIVED',
                'provider_id'=>$id,'issue_id'=>$request['issue_id'],
                'source_sha256'=>$request['source_sha256'],
                'proposal'=>['language'=>$out['language'],'text'=>$out['text']],
                'needs_governed_wpml_writeback'=>true,'needs_editorial_qa'=>true,
                'auto_published'=>false,'background_queued'=>false];
        }
        return ['contract'=>self::CONTRACT.'.dispatch','status'=>'HOLD_PROVIDER_UNAVAILABLE',
            'attempts'=>$attempts,'auto_published'=>false,'background_queued'=>false];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    public static function register_abilities():void {
        if(!function_exists('wp_register_ability'))return;
        foreach(['mad4b/translation-provider-plan'=>['plan',false],
                  'mad4b/translation-provider-dispatch'=>['run',true]] as $name=>$entry){
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,[
                'label'=>$entry[1]?'Dispatch synchronous translation provider':'Plan translation provider priority and fallback',
                'description'=>'Active authenticated ChatGPT MCP first; connected/authorized synchronous API adapter fallback. Never autoqueue or publish.',
                'category'=>$entry[1]?'mad4b-write':'mad4b-read',
                'execute_callback'=>[__CLASS__,$entry[0]],
                'permission_callback'=>static function()use($entry){
                    return !is_wp_error(self::scope($entry[1]));
                },
                'input_schema'=>['type'=>'object','properties'=>[
                    'issue_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']
                ],'required'=>['issue_id'],'additionalProperties'=>false],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>['public'=>false,'show_in_rest'=>false,
                    'mcp'=>['public'=>false,'type'=>'tool','surface'=>$entry[1]?'write':'read'],
                    'annotations'=>['readonly'=>!$entry[1],'idempotent'=>true,'destructive'=>false]],
            ]);
        }
    }
    public static function cli($args,$flags):void {
        if(($args[0]??'')==='fixture'){
            $one=self::choose(true,[['provider_id'=>'sample_api','priority'=>10,'mode'=>'external_api','status'=>'ready']]);
            $two=self::choose(false,[['provider_id'=>'sample_api','priority'=>10,'mode'=>'external_api','status'=>'ready']]);
            $three=self::choose(false,[['provider_id'=>'sample_api','priority'=>10,'mode'=>'external_api','status'=>'not_authorized_or_unavailable']]);
            $pass=$one['selected_provider']==='chatgpt_mcp_session'
                &&$two['selected_provider']==='sample_api'
                &&$three['selected_provider']===null
                &&$three['selection_state']==='HOLD_PROVIDER_UNAVAILABLE'
                &&$one['no_implicit_background_queue']===true;
            WP_CLI::line(wp_json_encode(['pass'=>$pass,'mcp_primary'=>$one['selected_provider'],
                'external_fallback'=>$two['selected_provider'],'unavailable'=>$three['selection_state'],
                'queue_default'=>false],JSON_UNESCAPED_SLASHES));
            if(!$pass)WP_CLI::error('routing_fixture_failed');
            return;
        }
        if(($args[0]??'')!=='plan'||empty($flags['issue_id']))
            WP_CLI::error('Usage: wp mad4b translation-route fixture|plan --issue_id=<hash>');
        $p=self::plan(['issue_id'=>$flags['issue_id']]);
        if(is_wp_error($p))WP_CLI::error($p->get_error_code());
        WP_CLI::line(wp_json_encode($p,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
}
MAD4B_Translation_Provider_Router::boot();
