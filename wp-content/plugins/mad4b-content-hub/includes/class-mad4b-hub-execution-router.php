<?php
defined('ABSPATH') || exit;

/**
 * ChatGPT-client-led orchestration with optional, explicitly selected
 * background AI. Executor choice does NOT change Autonomous/Assisted/Strict
 * content review, native WordPress authorization or publication authority.
 */
final class MAD4B_Hub_Execution_Router {
    const CONTRACT = 'mad4b.hub.execution-router.v1';
    const OPTION = 'mad4b_hub_execution_router_v1';
    const DEFAULT_MODE = 'chatgpt_client';
    const MODES = array('chatgpt_client','background_ai');

    public static function boot() {
        add_action('rest_api_init',array(__CLASS__,'routes'),43);
        add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),46);
    }
    public static function readable() {
        return class_exists('MAD4B_Content_Hub') && MAD4B_Content_Hub::permission();
    }
    public static function writable() {
        return self::readable() && class_exists('MAD4B_SCP_Policy')
            && MAD4B_SCP_Policy::can_mutate();
    }
    private static function error($name,$status=409) {
        return new WP_Error('mad4b_hub_executor_'.$name,$name,
            array('status'=>$status,'contract'=>self::CONTRACT));
    }
    private static function state() {
        $v=get_option(self::OPTION,array());
        if(!is_array($v))$v=array();
        return array(
            'default_mode'=>self::DEFAULT_MODE, // Immutable default.
            'background_ai_enabled'=>($v['background_ai_enabled']??false)===true,
            'jobs'=>is_array($v['jobs']??null)?$v['jobs']:array(),
            'revision'=>max(0,(int)($v['revision']??0)),
        );
    }
    public static function background_available() {
        // An empty filter registry must never pretend to be a model runner.
        return function_exists('has_filter')
            && has_filter('mad4b_hub_background_ai_dispatch')!==false;
    }
    private static function job($job_id) {
        if(!is_string($job_id) ||
            !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$job_id))
            return self::error('job_id_invalid',400);
        $scope=MAD4B_SCP_Operational_Integrity::capture();
        if(is_wp_error($scope))return $scope;
        $site=(string)($scope['scope']['site_uuid']??'');
        $brand=(string)($scope['scope']['brand_ref']??'');
        $r=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$job_id));
        if(is_wp_error($r))return $r;
        $job=$r['job']??array();
        if(!is_array($job)||($job['site_uuid']??'')!==$site
            ||($job['brand_id']??'')!==$brand)
            return self::error('job_scope_mismatch',403);
        return $job;
    }
    private static function selected($job,$settings) {
        $item=$settings['jobs'][$job['job_id']]??null;
        if(!is_array($item)||($item['site_uuid']??'')!==$job['site_uuid']
            ||($item['brand_id']??'')!==$job['brand_id'])
            return self::DEFAULT_MODE;
        $mode=(string)($item['mode']??'');
        return in_array($mode,self::MODES,true)?$mode:self::DEFAULT_MODE;
    }

    public static function status($input=array()) {
        if(!self::readable())return self::error('read_permission_denied',403);
        $settings=self::state();
        $job_id=is_array($input)?(string)($input['job_id']??''):'';
        $job=null;
        if($job_id!==''){
            $job=self::job($job_id);
            if(is_wp_error($job))return $job;
        }
        $mode=$job?self::selected($job,$settings):self::DEFAULT_MODE;
        $output=array(
            'contract'=>self::CONTRACT,'default_executor'=>self::DEFAULT_MODE,
            'selected_executor'=>$mode,'supported_executors'=>self::MODES,
            'background_ai_enabled'=>$settings['background_ai_enabled'],
            'background_ai_runner_registered'=>self::background_available(),
            'background_ai_ready'=>$settings['background_ai_enabled'] && self::background_available(),
            'review_mode_independent'=>true,
            'default_content_review_mode'=>'autonomous',
            'execution_principal'=>'verified_wordpress_user_default',
            'chatgpt_client_uses_connected_mcp'=>true,
            'current_request_verified_chatgpt_client'=>
                class_exists('MAD4B_SCP_OAuth_Resource_Bridge')
                && method_exists('MAD4B_SCP_OAuth_Resource_Bridge','verified_bearer_client_is')
                && MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is(
                    'https://chatgpt.com/oauth/client.json'),
            'chatgpt_client_server_background_execution'=>false,
            'background_ai_requires_explicit_opt_in'=>true,
            'settings_revision'=>$settings['revision'],
            'mutation_performed'=>false,
        );
        if($job){
            $output['job_id']=$job_id;
            $output['job_stage']=$job['stage'];
            $output['job_state']=$job['state'];
            $output['job_revision']=(int)$job['job_revision'];
            $output['content_review_mode']=(string)($job['automation_level']??'');
        }
        return $output;
    }

    /** Enable/disable the optional worker, never change the system default. */
    public static function configure($input) {
        if(!self::writable())return self::error('write_permission_denied',403);
        if(!is_array($input)||!array_key_exists('background_ai_enabled',$input)
            ||!is_bool($input['background_ai_enabled']))
            return self::error('configuration_invalid',400);
        $s=self::state();
        $s['background_ai_enabled']=$input['background_ai_enabled'];
        $s['revision']++;
        if(!update_option(self::OPTION,$s,false)
            && get_option(self::OPTION)!==$s)
            return self::error('configuration_save_failed');
        return self::status();
    }

    /** A job can explicitly opt in; no silent background executor fallback. */
    public static function select($input) {
        if(!self::writable())return self::error('write_permission_denied',403);
        if(!is_array($input))return self::error('input_invalid',400);
        $id=(string)($input['job_id']??'');
        $mode=sanitize_key((string)($input['executor_mode']??''));
        if(!in_array($mode,self::MODES,true))
            return self::error('mode_invalid',400);
        $job=self::job($id);
        if(is_wp_error($job))return $job;
        if(in_array((string)$job['state'],array('CANCELLED','FAILED','COMPLETED'),true))
            return self::error('terminal_job');
        $s=self::state();
        if($mode==='background_ai'
            && !$s['background_ai_enabled'])
            return self::error('background_ai_not_enabled');
        if($mode==='background_ai'
            && !self::background_available())
            return self::error('background_ai_runner_missing',503);
        $prior=self::selected($job,$s);
        if($prior!==$mode){
            $s['jobs'][$id]=array(
                'mode'=>$mode,'site_uuid'=>$job['site_uuid'],
                'brand_id'=>$job['brand_id'],
                'selected_by_wp_user'=>get_current_user_id(),
                'updated_at'=>gmdate('c'),
            );
            $s['revision']++;
            if(!update_option(self::OPTION,$s,false)
                && get_option(self::OPTION)!==$s)
                return self::error('selection_save_failed');
        }
        $r=self::status(array('job_id'=>$id));
        if(is_wp_error($r))return $r;
        $r['changed']=$prior!==$mode;
        return $r;
    }

    /** Client handoff is a *plan*, not a fake model execution. */
    public static function handoff($input) {
        if(!self::readable())return self::error('read_permission_denied',403);
        if(!is_array($input))return self::error('input_invalid',400);
        $operation_key=(string)($input['operation_key']??'');
        $progress=MAD4B_Hub_Workflow_Continuity::status(
            array('operation_key'=>$operation_key));
        if(is_wp_error($progress))return $progress;
        $job=self::job((string)($progress['job_id']??''));
        if(is_wp_error($job))return $job;
        $selection=self::status(array('job_id'=>$job['job_id']));
        if(is_wp_error($selection))return $selection;
        $stage=(string)$job['stage'];
        $mode=$selection['selected_executor'];
        $next=$stage==='INFORMATION_GAIN'?'mad4b/research-keyword-record'
            :(in_array($stage,array('SCRAPING','COMPETITOR_ANALYSIS'),true)
                ?'mad4b/control-workflow-baseline':'');
        $role=$stage==='INFORMATION_GAIN'?'researcher'
            :(in_array($stage,array('WRITING','DRAFTING'),true)?'writer':'recovery');
        $route=class_exists('MAD4B_SCP_Market_Content_Exchange')
            ?MAD4B_SCP_Market_Content_Exchange::assistant_route(array('role'=>$role))
            :null;
        $skill=!is_wp_error($route) && is_array($route)
            ?(string)($route['selected_for_research_or_draft']['skill_name']??''):'';
        return array(
            'contract'=>self::CONTRACT,'job_id'=>$job['job_id'],
            'job_revision'=>(int)$job['job_revision'],
            'job_stage'=>$stage,'executor_mode'=>$mode,
            'content_review_mode'=>(string)($job['automation_level']??''),
            'state'=>$mode==='chatgpt_client'
                ?($next?'client_action_required':'stage_requires_planner')
                :($selection['background_ai_ready']
                    ?'background_dispatch_required':'background_runner_unavailable'),
            'next_ability'=>$mode==='chatgpt_client'
                ?$next:($selection['background_ai_ready']?'mad4b/hub-executor-dispatch':''),
            'next_input'=>array('job_id'=>$job['job_id']),
            'next_input_needs_client_enrichment'=> $mode==='chatgpt_client'
                && $stage==='INFORMATION_GAIN',
            'suggested_skill'=>$skill,'skill_executed'=>false,
            'client_execution_required'=>$mode==='chatgpt_client',
            'background_execution_available'=>$selection['background_ai_ready'],
            'backend_model_called'=>false,'publication_performed'=>false,
            'artifact_written'=>false,'mutation_performed'=>false,
            'handoff_instructions'=>$mode==='chatgpt_client'
                ?'ChatGPT Client inspects source Artifacts and invokes the next authenticated MCP Ability with evidence-based input. The server has not called an AI model.'
                :'Background execution is opt-in and requires a registered worker and explicit dispatch. No silent fallback or invented execution.',
        );
    }

    /** Only a real installed background worker can accept actual dispatch. */
    public static function dispatch($input) {
        if(!self::writable())return self::error('write_permission_denied',403);
        if(!is_array($input))return self::error('input_invalid',400);
        $id=(string)($input['job_id']??'');
        $job=self::job($id);
        if(is_wp_error($job))return $job;
        $s=self::state();
        if(!$s['background_ai_enabled'] ||
            self::selected($job,$s)!=='background_ai')
            return self::error('background_ai_not_selected');
        if(!self::background_available())
            return self::error('background_ai_runner_missing',503);
        $planned=array('contract'=>self::CONTRACT,'job_id'=>$id,
            'job_revision'=>(int)$job['job_revision'],
            'scope_site_uuid'=>$job['site_uuid'],
            'scope_brand_id'=>$job['brand_id'],
            'requested_by_wp_user'=>get_current_user_id(),
            'idempotency_key'=>hash('sha256',$id.'|'.$job['job_revision'].'|background_ai'),
        );
        $receipt=apply_filters('mad4b_hub_background_ai_dispatch',null,$planned);
        if(!is_array($receipt)
            ||!in_array((string)($receipt['status']??''),array('queued','running'),true)
            ||(string)($receipt['job_id']??'')!==$id
            ||!is_string($receipt['execution_id']??null)
            ||strlen($receipt['execution_id'])<8)
            return self::error('background_runner_did_not_accept',503);
        return array('contract'=>self::CONTRACT,'executor_mode'=>'background_ai',
            'job_id'=>$id,'job_revision'=>(int)$job['job_revision'],
            'status'=>$receipt['status'],
            'execution_id'=>$receipt['execution_id'],
            'execution_receipt_from_registered_runner'=>true,
            'publication_performed'=>false);
    }

    public static function routes() {
        $base='mad4b-hub/v1';
        register_rest_route($base,'/execution/status',array(
            'methods'=>'GET','callback'=>static function($r){return self::status($r->get_params());},
            'permission_callback'=>array(__CLASS__,'readable')));
        foreach(array('configure'=>'configure','select'=>'select',
            'handoff'=>'handoff','dispatch'=>'dispatch') as $name=>$method){
            register_rest_route($base,'/execution/'.$name,array(
                'methods'=>'POST','callback'=>static function($r)use($method){
                    return self::$method($r->get_json_params());
                },'permission_callback'=>in_array($name,array('configure','select','dispatch'),true)
                    ?array(__CLASS__,'writable'):array(__CLASS__,'readable')));
        }
    }
    public static function abilities() {
        if(!function_exists('wp_register_ability'))return;
        foreach(array(
            'mad4b/hub-executor-status'=>'status',
            'mad4b/hub-executor-configure'=>'configure',
            'mad4b/hub-executor-select'=>'select',
            'mad4b/hub-executor-handoff'=>'handoff',
            'mad4b/hub-executor-dispatch'=>'dispatch') as $name=>$method){
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            $schema=array('type'=>'object','properties'=>array(
                'job_id'=>array('type'=>'string'),
                'operation_key'=>array('type'=>'string'),
                'executor_mode'=>array('type'=>'string','enum'=>self::MODES),
                'background_ai_enabled'=>array('type'=>'boolean')),
                'additionalProperties'=>false);
            if($method==='configure')$schema['required']=array('background_ai_enabled');
            if($method==='select')$schema['required']=array('job_id','executor_mode');
            if($method==='handoff')$schema['required']=array('operation_key');
            if($method==='dispatch')$schema['required']=array('job_id');
            $read=in_array($method,array('status','handoff'),true);
            wp_register_ability($name,array(
                'label'=>'MAD4B Hub Executor '.$method,
                'description'=>'Choose ChatGPT Client default or explicitly opt in to a verified Background AI worker.',
                'category'=>$read?'mad4b-read':'mad4b-write',
                'execute_callback'=>array(__CLASS__,$method),
                'permission_callback'=>array(__CLASS__,$read?'readable':'writable'),
                'input_schema'=>$schema,
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mcp'=>array('type'=>'tool','public'=>false),
                    'annotations'=>array('readonly'=>$read,'destructive'=>false,
                        'idempotent'=>$method!=='dispatch')),
            ));
        }
    }
}
