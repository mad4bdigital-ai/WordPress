<?php
/**
 * Plugin Name: MAD4B Start Your Company — Governed Runtime
 * Description: Reusable company create/repair stage planner and bounded remote-job handoff (central Staging only).
 * Version: 0.3.0
 */
defined('ABSPATH') || exit;

/** A provider-registered semantic job type. Never accepts a shell command, SQL or PHP. */
final class MAD4B_SCP_Company_Provisioning_Work_Operations {
    const JOB = 'company.stage.execute';
    const CONTRACT = 'mad4b.company-stage-job.v1';
    const EXECUTORS = 'mad4b_company_vps_executor_registry_v1';
    public static function definitions(array $registered) {
        $registered[self::JOB] = array(
            'executor'=>'enrolled_vps_mcp_client',
            'authority_surface'=>'mad4b-admin',
            'production_policy'=>'deny_without_exact_tenant_stage_authority',
            'validate_payload'=>array(__CLASS__,'validate_payload')
        );
        return $registered;
    }
    public static function validate_payload($payload) {
        if (!is_array($payload) || array_diff(array_keys($payload),
            array('contract','company_code','site_uuid','origin','mode','stage',
                  'plan_sha256','stage_revision','preserve_existing','mutation_authorized'))) return false;
        if (($payload['contract']??'') !== self::CONTRACT ||
            !in_array($payload['mode']??'',array('create','repair_existing'),true) ||
            !isset(MAD4B_Start_Company_Runtime::STAGES[$payload['stage']??'']) ||
            !preg_match('/^[a-f0-9]{64}$/D',(string)($payload['plan_sha256']??'')) ||
            !preg_match('/^[a-f0-9]{64}$/D',(string)($payload['stage_revision']??'')) ||
            !empty($payload['mutation_authorized']) ||
            !is_bool($payload['preserve_existing']??null) ||
            !wp_is_uuid((string)($payload['site_uuid']??''))) return false;
        $code=(string)($payload['company_code']??'');
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code)) return false;
        $registry=get_option(MAD4B_Start_Company_Runtime::REGISTRY,array());
        $row=is_array($registry)?($registry[$code]??null):null;
        return is_array($row) && !empty($row['host_binding_verified'])
            && hash_equals((string)($row['company_site_uuid']??''),(string)$payload['site_uuid'])
            && hash_equals((string)($row['canonical_origin']??''),(string)($payload['origin']??''))
            && $payload['preserve_existing'] === ('repair_existing'===$payload['mode']);
    }
    public static function executor_status($company) {
        $all=get_option(self::EXECUTORS,array());
        $row=is_array($all)?($all[$company['company_code']]??null):null;
        $now=time();$seen=is_array($row)?strtotime((string)($row['verified_at']??'')):false;
        $eligible=is_array($row) && ($row['provider']??'')==='vps_mcp_client'
          && ($row['site_uuid']??'')===$company['site_uuid']
          && ($row['authority_profile']??'')==='company_stage_v1'
          && !empty($row['bound_to_exact_company'])
          && ($row['health']??'')==='ready'
          && is_string($row['executor_id']??null) && preg_match('/^[a-z0-9_-]{4,64}$/D',$row['executor_id'])
          && $seen && $now >= $seen && $now-$seen<=900;
        return array('registered'=>(bool)$eligible,'executor'=>'enrolled_vps_mcp_client',
            'reason'=>$eligible?'ready':'verified_company_executor_missing_or_stale');
    }
    public static function build_identity() {
        if (!class_exists('MAD4B_SCP_Live_Acceptance_Observer')
          || !method_exists('MAD4B_SCP_Live_Acceptance_Observer','build_provenance_status')) return false;
        $p=MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
        if (!is_array($p) || empty($p['manifest_valid']) || empty($p['runtime_manifest_match'])
            || !empty($p['stale']) || !empty($p['provenance_mismatch'])) return false;
        $identity=array();
        foreach(array('source_commit_sha'=>40,'build_fingerprint'=>64,'package_manifest_digest'=>64) as $key=>$len) {
            $value=(string)($p[$key]??'');
            if(!preg_match('/^[a-f0-9]{'.$len.'}$/D',$value)) return false;
            $identity[$key]=$value;
        }
        return $identity;
    }
}

final class MAD4B_Start_Company_Runtime {
    const REGISTRY='mad4b_tourism_company_site_registry_v1';
    const BOOTSTRAP='mad4b_company_bootstrap_registry_v1';
    const RECEIPTS='mad4b_company_lifecycle_receipt_index_v1';
    const READINESS='mad4b_company_readiness_workflow_status_v1';
    const HOOK='mad4b_start_company_recheck';
    const STAGES=array(
      'identity'=>array('group'=>'infrastructure','deps'=>array()),
      'snapshot'=>array('group'=>'infrastructure','deps'=>array('identity')),
      'backup'=>array('group'=>'infrastructure','deps'=>array('snapshot')),
      'plugins'=>array('group'=>'infrastructure','deps'=>array('backup')),
      'mu_providers'=>array('group'=>'infrastructure','deps'=>array('plugins')),
      'configuration'=>array('group'=>'infrastructure','deps'=>array('mu_providers')),
      'queries'=>array('group'=>'infrastructure','deps'=>array('configuration')),
      'access'=>array('group'=>'access','deps'=>array('identity')),
      'pricing'=>array('group'=>'commercial','deps'=>array('configuration','mu_providers')),
      'templates'=>array('group'=>'commercial','deps'=>array('queries','pricing')),
      'forms'=>array('group'=>'commercial','deps'=>array('plugins')),
      'content_hub'=>array('group'=>'commercial','deps'=>array('identity')),
      'commercial_smoke'=>array('group'=>'commercial','deps'=>array('templates','forms','content_hub','pricing','access'))
    );
    const PATH='/start-your-company/companies/(?P<company>[a-z][a-z0-9-]{2,47})';
    public static function boot(){
        add_action('rest_api_init',array(__CLASS__,'routes'),94);
        add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),94);
        add_action(self::HOOK,array(__CLASS__,'recheck_event'),10,1);
        add_action('mad4b/company/created',array(__CLASS__,'company_event'),10,1);
        add_action('mad4b/company/provider_changed',array(__CLASS__,'company_event'),10,1);
        add_action('mad4b/company/content_selection_changed',array(__CLASS__,'company_event'),10,1);
        add_action('updated_option',array(__CLASS__,'option_event'),10,3);
    }
    private static function central(){
        return defined('DB_NAME') && DB_NAME==='etg_commerce_staging'
           && wp_parse_url(home_url(),PHP_URL_HOST)==='tours.dev.mad4b.com';
    }
    public static function guard($r){
        if (!self::central() || !class_exists('MAD4B_Company_Readiness_Governance'))
            return new WP_Error('mad4b_company_runtime_unavailable','Central scoped guard unavailable',array('status'=>503));
        return MAD4B_Company_Readiness_Governance::guard($r);
    }
    public static function company($code) {
        $code=sanitize_key((string)$code);
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))return new WP_Error('mad4b_company_invalid','Invalid company',array('status'=>422));
        $all=get_option(self::REGISTRY,array());$row=is_array($all)?($all[$code]??null):null;
        if(!is_array($row)||empty($row['host_binding_verified'])||!wp_is_uuid((string)($row['company_site_uuid']??'')))
            return new WP_Error('mad4b_company_binding_missing','Company must complete intake and enrollment',array('status'=>409));
        $origin=(string)($row['canonical_origin']??'');
        if(!preg_match('~^https://[a-zA-Z0-9.-]+/?$~D',$origin))
            return new WP_Error('mad4b_company_origin_invalid','Verified company origin required',array('status'=>409));
        return array('company_code'=>$code,'site_uuid'=>$row['company_site_uuid'],'origin'=>$origin);
    }
    private static function stage_revision($c,$stage) {
        $all=get_option(self::BOOTSTRAP,array());$owner=is_array($all)?($all[$c['company_code']]??array()):array();
        $fields=array($c['company_code'],$c['site_uuid'],$c['origin'],$stage);
        if($stage==='access')$fields[]=(int)($owner['revision']??0);
        return hash('sha256',wp_json_encode($fields,JSON_UNESCAPED_SLASHES));
    }
    private static function receipt_ok($c,$stage) {
        $all=get_option(self::RECEIPTS,array());$rec=$all[$c['company_code']][$stage]??null;
        if(!is_array($rec) || !class_exists('MAD4B_SCP_Operation_Journal')
          || empty($rec['independent_readback']) || empty($rec['verified'])
          || ($rec['site_uuid']??'')!==$c['site_uuid'] || ($rec['stage']??'')!==$stage
          || ($rec['stage_revision']??'')!==self::stage_revision($c,$stage)
          || !preg_match('/^[a-f0-9]{64}$/D',(string)($rec['operation_binding_sha256']??'')))return false;
        $status=MAD4B_SCP_Operation_Journal::status((string)($rec['operation_id']??''));
        return is_array($status) && ($status['lifecycle_state']??'')==='completed'
           && ($status['terminal_outcome']??'')==='success'
           && hash_equals((string)$status['operation_binding_sha256'],(string)$rec['operation_binding_sha256']);
    }
    public static function plan($req){
        $auth=self::guard($req);if(is_wp_error($auth))return $auth;
        $c=self::company($req->get_param('company'));if(is_wp_error($c))return $c;
        $mode=(string)($req->get_param('mode')??'repair_existing');
        if(!in_array($mode,array('create','repair_existing'),true))
            return new WP_Error('mad4b_mode_invalid','Unknown company mode',array('status'=>422));
        $verified=array();foreach(self::STAGES as $id=>$spec)$verified[$id]=self::receipt_ok($c,$id);
        $rows=array();
        foreach(self::STAGES as $id=>$spec){
            $missing=array_values(array_filter($spec['deps'],static function($dep)use($verified){return !$verified[$dep];}));
            $rows[$id]=array('group'=>$spec['group'],'state'=>$verified[$id]?'VERIFIED':($missing?'WAITING_DEPENDENCY':'READY'),
              'missing_dependencies'=>$missing,'stage_revision'=>self::stage_revision($c,$id),
              'executor'=>'enrolled_vps_mcp_client','mutation_authorized'=>false);
        }
        $base=array('company'=>$c,'mode'=>$mode,'stages'=>$rows);
        $sha=hash('sha256',wp_json_encode($base,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $groups=array();
        foreach(array('infrastructure','access','commercial') as $g) {
            $entries=array_filter($rows,static function($r)use($g){return $r['group']===$g;});
            $groups[$g.'_ready']=count($entries)>0 && count(array_filter($entries,static function($r){return $r['state']!=='VERIFIED';}))===0;
        }
        return array('contract'=>'mad4b.start-your-company.plan.v3','company'=>$c,'mode'=>$mode,
          'plan_sha256'=>$sha,'stages'=>$rows,'readiness'=>$groups,
          'preserve_existing_users'=>true,'preserve_existing_options'=>true,
          'preserve_existing_media'=>true,'preserve_existing_orders'=>true,
          'executor'=>MAD4B_SCP_Company_Provisioning_Work_Operations::executor_status($c),
          'commercial_distribution_authorized'=>false,'mutation_performed'=>false);
    }
    public static function resume($req) {
        $auth=self::guard($req);if(is_wp_error($auth))return $auth;
        // Central queue writes require company-scoped operator grants; tenant writes remain a separate authorization.
        if(!((current_user_can('mad4b_manage_company_operations') && current_user_can('mad4b_propose_company_content')) ||
            (current_user_can('manage_options') && current_user_can('activate_plugins'))))
            return new WP_Error('mad4b_company_queue_grant_missing','Company queue capability required',array('status'=>403));
        $c=self::company($req->get_param('company'));if(is_wp_error($c))return $c;
        $input=$req->get_json_params();if(!is_array($input))$input=$req->get_params();
        if(!is_array($input)||!isset(self::STAGES[$input['stage']??''])
           ||!preg_match('/^[a-f0-9]{64}$/D',(string)($input['expected_plan_sha256']??'')))
            return new WP_Error('mad4b_company_stage_invalid','Exact stage and plan hash required',array('status'=>422));
        $planReq=new WP_REST_Request('GET','/');
        $planReq->set_param('company',$c['company_code']);
        $planReq->set_param('mode',$input['mode']??'repair_existing');
        $plan=self::plan($planReq);if(is_wp_error($plan))return $plan;
        if(!hash_equals($plan['plan_sha256'],$input['expected_plan_sha256']))
            return new WP_Error('mad4b_company_plan_changed','Plan changed; recheck first',array('status'=>409));
        $stage=$input['stage'];$s=$plan['stages'][$stage];
        if($s['state']==='VERIFIED')return array('state'=>'ALREADY_VERIFIED','mutation_performed'=>false);
        if($s['state']!=='READY')return array('state'=>'WAITING_DEPENDENCY','missing'=>$s['missing_dependencies'],'mutation_performed'=>false);
        if(empty($plan['executor']['registered']))
            return new WP_Error('mad4b_company_executor_unregistered','Verified VPS MCP Client registration required',array('status'=>503));
        if(!class_exists('MAD4B_SCP_Remote_Work_Queue'))
            return new WP_Error('mad4b_company_queue_unavailable','Central remote queue unavailable',array('status'=>503));
        $identity=MAD4B_SCP_Company_Provisioning_Work_Operations::build_identity();
        if(!is_array($identity))
            return new WP_Error('mad4b_company_build_identity_missing','Exact Control Plane runtime build identity required',array('status'=>503));
        $payload=array('contract'=>MAD4B_SCP_Company_Provisioning_Work_Operations::CONTRACT,
          'company_code'=>$c['company_code'],'site_uuid'=>$c['site_uuid'],'origin'=>$c['origin'],
          'mode'=>$plan['mode'],'stage'=>$stage,'plan_sha256'=>$plan['plan_sha256'],
          'stage_revision'=>$s['stage_revision'],'preserve_existing'=>$plan['mode']==='repair_existing',
          'mutation_authorized'=>false);
        if(!MAD4B_SCP_Company_Provisioning_Work_Operations::validate_payload($payload))
            return new WP_Error('mad4b_company_payload_invalid','Company job is not bound to its Site Profile',array('status'=>409));
        $queued=MAD4B_SCP_Remote_Work_Queue::enqueue(MAD4B_SCP_Company_Provisioning_Work_Operations::JOB,$payload,$identity,3600);
        if(is_wp_error($queued))return $queued;
        return array('contract'=>'mad4b.start-your-company.queued.v1','state'=>$queued['state'],
          'company'=>$c['company_code'],'stage'=>$stage,'job_id'=>$queued['job']['job_id'],
          'executor_executed'=>false,'production_mutation_authorized'=>false);
    }
    public static function routes() {
        register_rest_route('mad4b/v1',self::PATH.'/plan',array('methods'=>'GET','callback'=>array(__CLASS__,'plan'),'permission_callback'=>array(__CLASS__,'guard')));
        register_rest_route('mad4b/v1',self::PATH.'/resume',array('methods'=>'POST','callback'=>array(__CLASS__,'resume'),'permission_callback'=>array(__CLASS__,'guard')));
    }
    public static function abilities() {
        if(!self::central()||!function_exists('wp_register_ability'))return;
        foreach(array(
           'mad4b/start-your-company-plan'=>array('read'=>true,'method'=>'plan'),
           'mad4b/start-your-company-resume'=>array('read'=>false,'method'=>'resume')
        ) as $name=>$spec) {
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            $props=array('company'=>array('type'=>'string'),'mode'=>array('type'=>'string','enum'=>array('create','repair_existing')));
            $required=array('company');
            if(!$spec['read']) { $props['stage']=array('type'=>'string');$props['expected_plan_sha256']=array('type'=>'string','pattern'=>'^[a-f0-9]{64}$');$required=array('company','stage','expected_plan_sha256'); }
            wp_register_ability($name,array(
              'label'=>$spec['read']?'Plan Company Lifecycle':'Queue Exact Company Stage',
              'description'=>$spec['read']?'Read company-scoped stage DAG':'Queue an exact verified company stage; cannot execute tenant mutation',
              'category'=>$spec['read']?'mad4b-read':'mad4b-write',
              'input_schema'=>array('type'=>'object','properties'=>$props,'required'=>$required,'additionalProperties'=>false),
              'output_schema'=>array('type'=>'object','additionalProperties'=>true),
              'permission_callback'=>static function($input){$r=new WP_REST_Request('GET','/');$r->set_param('company',$input['company']??'');return self::guard($r);},
              'execute_callback'=>static function($input)use($spec){$r=new WP_REST_Request($spec['read']?'GET':'POST','/');foreach($input as $k=>$v)$r->set_param($k,$v);
                if(!$spec['read']){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($input));}
                return call_user_func(array(__CLASS__,$spec['method']),$r);},
              'meta'=>array('show_in_rest'=>false,'public'=>false,
                'annotations'=>array('readonly'=>$spec['read'],'destructive'=>false,'idempotent'=>true),
                'mcp'=>array('public'=>false,'type'=>'tool','surface'=>$spec['read']?'read':'write'))
            ));
        }
    }
    public static function company_event($payload){
        $code=is_array($payload)?($payload['company_code']??''):$payload;
        if(!self::central()||!is_string($code)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))return;
        $c=self::company($code);if(is_wp_error($c))return;
        if(!wp_next_scheduled(self::HOOK,array($code)))wp_schedule_single_event(time()+20,self::HOOK,array($code));
    }
    public static function option_event($name,$old,$new){
        if(!in_array($name,array(self::REGISTRY,self::BOOTSTRAP),true)||!is_array($new))return;
        foreach($new as $code=>$row)if(!isset($old[$code])||$row!==$old[$code])self::company_event($code);
    }
    public static function recheck_event($code){
        // Automatic events only refresh read-only plans; never queue tenant mutations.
        $r=new WP_REST_Request('GET','/');$r->set_param('company',$code);
        // Cron runs without user; use no grant or impersonation here.
        $c=self::company($code);
        if(!is_wp_error($c))do_action('mad4b/company/recheck_requested',$c);
    }
}
MAD4B_Start_Company_Runtime::boot();
