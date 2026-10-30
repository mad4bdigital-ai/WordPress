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
            array('contract','company_code','site_uuid','origin','mode','stage','action',
                  'plan_sha256','stage_revision','run_id','preserve_existing','mutation_authorized'))) return false;
        if (($payload['contract']??'') !== self::CONTRACT ||
            !in_array($payload['mode']??'',array('create','repair_existing'),true) ||
            !isset(MAD4B_Start_Company_Runtime::STAGES[$payload['stage']??'']) ||
            ($payload['action']??'') !== (MAD4B_Start_Company_Runtime::ACTIONS[$payload['stage']??'']??null) ||
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
            'executor_id'=>$eligible?(string)$row['executor_id']:null,
            'reason'=>$eligible?'ready':'verified_company_executor_missing_or_stale');
    }
    // Provider admission is not restricted to discovery. This exact-stage
    // fence allows installation/configuration once its own prerequisites,
    // authenticated company run and registered executor have been verified.
    public static function provider_entry_allowed($job) {
        if (!is_array($job) || ($job['operation_id']??'')!==self::JOB) return false;
        $payload=$job['payload']??null;
        if (!self::validate_payload($payload)) return false;
        $company=array('company_code'=>$payload['company_code'],'site_uuid'=>$payload['site_uuid']);
        $executor=self::executor_status($company);
        if (empty($executor['registered']) ||
            !hash_equals((string)$executor['executor_id'],(string)($job['executor_id']??'')) ||
            ($job['status']??'')!=='claimed' ||
            (int)($job['lease_expires_at_epoch']??0)<=time() ||
            !empty($job['cancel_requested_at']))return false;
        return MAD4B_Start_Company_Runtime::stage_job_authorized($payload);
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
    const SNAPSHOTS='mad4b_start_company_snapshots_v3';
    const RUNS='mad4b_company_lifecycle_authorized_runs_v1';
    const REGISTRY='mad4b_tourism_company_site_registry_v1';
    const BOOTSTRAP='mad4b_company_bootstrap_registry_v1';
    const RECEIPTS='mad4b_company_lifecycle_receipt_index_v1';
    const READINESS='mad4b_company_readiness_workflow_status_v1';
    const HOOK='mad4b_start_company_recheck';
    // Fixed semantic actions: the VPS Client resolves these through its
    // registered company executor catalog, never an arbitrary shell command.
    const ACTIONS=array(
      'identity'=>'company.identity.verify',
      'snapshot'=>'company.snapshot.collect',
      'backup'=>'company.backup.ensure',
      'plugins'=>'company.plugins.converge',
      'mu_providers'=>'company.mu-providers.converge',
      'configuration'=>'company.configuration.converge',
      'queries'=>'company.queries.reconcile',
      'access'=>'company.access.activate',
      'pricing'=>'company.pricing.converge',
      'templates'=>'company.templates.qualify',
      'forms'=>'company.forms.converge',
      'content_hub'=>'company.content-hub.distribute',
      'commercial_smoke'=>'company.commercial.accept'
    );
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
        add_action('mad4b/company/created',array(__CLASS__,'created_event'),10,1);
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
        return self::compute_plan($c,$mode);
    }
    private static function compute_plan($c,$mode) {
        $verified=array();foreach(self::STAGES as $id=>$spec)$verified[$id]=self::receipt_ok($c,$id);
        $rows=array();
        foreach(self::STAGES as $id=>$spec){
            $missing=array_values(array_filter($spec['deps'],static function($dep)use($verified){return !$verified[$dep];}));
            // Each stage job has an independent plan digest. A change to
            // the owner's access profile cannot invalidate plugin/config work.
            $revision=self::stage_revision($c,$id);
            $dep_receipts=array();
            foreach($spec['deps'] as $dep)$dep_receipts[$dep]=array(
              'verified'=>$verified[$dep],'revision'=>self::stage_revision($c,$dep));
            $execution_sha=hash('sha256',wp_json_encode(array(
              'company'=>$c,'mode'=>$mode,'stage'=>$id,'revision'=>$revision,
              'dependencies'=>$dep_receipts),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            $rows[$id]=array('group'=>$spec['group'],'state'=>$verified[$id]?'VERIFIED':($missing?'WAITING_DEPENDENCY':'READY'),
              'missing_dependencies'=>$missing,'stage_revision'=>$revision,
              'execution_plan_sha256'=>$execution_sha,'action'=>self::ACTIONS[$id],
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
    // Run authority comes from an authenticated company operator, not a
    // public job payload. It persists across completed stages and is checked
    // again before each executor entry or automatic resume.
    public static function authorized_run($c,$mode) {
        $all=get_option(self::RUNS,array());
        $row=is_array($all)?($all[$c['company_code']]??null):null;
        if(!is_array($row) || ($row['mode']??'')!==$mode ||
           ($row['site_uuid']??'')!==$c['site_uuid'] ||
           ($row['origin']??'')!==$c['origin'] ||
           (int)($row['expires_at']??0)<=time() ||
           (int)($row['authorized_by']??0)<=0)return false;
        $user=function_exists('get_userdata')?get_userdata((int)$row['authorized_by']):false;
        if(!$user || !function_exists('user_can'))return false;
        return ((user_can($user,'manage_options') && user_can($user,'activate_plugins')) ||
          (user_can($user,'mad4b_manage_company_operations') &&
           user_can($user,'mad4b_propose_company_content'))) ? $row : false;
    }
    public static function stage_job_authorized($payload) {
        if(!is_array($payload))return false;
        $c=self::company((string)($payload['company_code']??''));
        if(is_wp_error($c) ||
          $c['site_uuid']!==($payload['site_uuid']??null) ||
          $c['origin']!==($payload['origin']??null))return false;
        $authorized=self::authorized_run($c,(string)($payload['mode']??''));
        if(!is_array($authorized))return false;
        if(isset($payload['run_id']) &&
          !hash_equals((string)($authorized['run_id']??''),(string)$payload['run_id']))return false;
        $plan=self::compute_plan($c,$payload['mode']);
        $stage=(string)($payload['stage']??'');
        $row=$plan['stages'][$stage]??null;
        if(!is_array($row)||$row['state']!=='READY' ||
          !hash_equals($row['execution_plan_sha256'],(string)($payload['plan_sha256']??'')) ||
          !hash_equals($row['stage_revision'],(string)($payload['stage_revision']??'')))return false;
        $all=get_option(MAD4B_SCP_Company_Provisioning_Work_Operations::EXECUTORS,array());
        $executor=is_array($all)?($all[$c['company_code']]??null):null;
        // A generic VPS shell is not sufficient: the enrolled executor must
        // advertise the exact fixed action for this stage.
        return is_array($executor) &&
          is_array($executor['supported_actions']??null) &&
          in_array(self::ACTIONS[$stage],$executor['supported_actions'],true);
    }
    private static function authorize_run($c,$mode,$expected_plan_sha256) {
        if(!get_current_user_id())return new WP_Error('mad4b_company_operator_missing','Authenticated operator required',array('status'=>403));
        $plan=self::compute_plan($c,$mode);
        if(!hash_equals($plan['plan_sha256'],(string)$expected_plan_sha256))
            return new WP_Error('mad4b_company_plan_changed','Recheck exact company plan',array('status'=>409));
        // Repeated Resume or automatic events retain the same durable run.
        $active=self::authorized_run($c,$mode);
        if(is_array($active))return true;
        $all=get_option(self::RUNS,array());if(!is_array($all))return new WP_Error('mad4b_company_run_store_invalid','Run store invalid',array('status'=>503));
        $all[$c['company_code']]=array('company_code'=>$c['company_code'],
          'run_id'=>hash('sha256',$c['site_uuid'].'|'.$mode.'|'.wp_generate_uuid4()),
          'site_uuid'=>$c['site_uuid'],'origin'=>$c['origin'],'mode'=>$mode,
          'initial_plan_sha256'=>$plan['plan_sha256'],
          'authorized_by'=>get_current_user_id(),'created_at'=>gmdate('c'),
          'expires_at'=>time()+DAY_IN_SECONDS,'preserve_existing'=>$mode==='repair_existing');
        update_option(self::RUNS,$all,false);
        $check=get_option(self::RUNS,array());
        if(!is_array($check)||($check[$c['company_code']]['initial_plan_sha256']??'')!==$plan['plan_sha256'])
            return new WP_Error('mad4b_company_run_store_failed','Run consent readback failed',array('status'=>503));
        return true;
    }
    private static function enqueue_ready($c,$mode,$stage='') {
        if(!self::authorized_run($c,$mode))
            return new WP_Error('mad4b_company_run_authority_missing','Start Your Company run has no active operator authority',array('status'=>403));
        $plan=self::compute_plan($c,$mode);
        if($stage==='' ){
            foreach($plan['stages'] as $key=>$record) {
                if($record['state']==='READY'){$stage=$key;break;}
            }
            if($stage==='')return array('state'=>'NO_READY_STAGE','mutation_performed'=>false);
        }
        if(!isset($plan['stages'][$stage]))return new WP_Error('mad4b_company_stage_invalid','Stage does not exist',array('status'=>422));
        $s=$plan['stages'][$stage];
        if($s['state']==='VERIFIED')return array('state'=>'ALREADY_VERIFIED','mutation_performed'=>false);
        if($s['state']!=='READY')return array('state'=>'WAITING_DEPENDENCY','missing'=>$s['missing_dependencies'],'mutation_performed'=>false);
        if(empty($plan['executor']['registered']))
            return new WP_Error('mad4b_company_executor_unregistered','Verified VPS MCP executor required',array('status'=>503));
        $all=get_option(MAD4B_SCP_Company_Provisioning_Work_Operations::EXECUTORS,array());
        $executor=$all[$c['company_code']]??array();
        if(!is_array($executor['supported_actions']??null) ||
          !in_array(self::ACTIONS[$stage],$executor['supported_actions'],true))
            return new WP_Error('mad4b_company_executor_action_missing','Exact company stage action is not enrolled',array('status'=>503));
        if(!class_exists('MAD4B_SCP_Remote_Work_Queue'))return new WP_Error('mad4b_company_queue_unavailable','Remote queue unavailable',array('status'=>503));
        $identity=MAD4B_SCP_Company_Provisioning_Work_Operations::build_identity();
        if(!is_array($identity))return new WP_Error('mad4b_company_build_identity_missing','Exact installed runtime identity required',array('status'=>503));
        $payload=array('contract'=>MAD4B_SCP_Company_Provisioning_Work_Operations::CONTRACT,
          'company_code'=>$c['company_code'],'site_uuid'=>$c['site_uuid'],'origin'=>$c['origin'],
          'mode'=>$plan['mode'],'stage'=>$stage,'action'=>self::ACTIONS[$stage],
          'run_id'=>(string)(self::authorized_run($c,$mode)['run_id']??''),
          'plan_sha256'=>$s['execution_plan_sha256'],'stage_revision'=>$s['stage_revision'],
          'preserve_existing'=>$plan['mode']==='repair_existing','mutation_authorized'=>false);
        if(!MAD4B_SCP_Company_Provisioning_Work_Operations::validate_payload($payload) ||
          !self::stage_job_authorized($payload))
            return new WP_Error('mad4b_company_stage_admission_denied','Stage cannot enter registered execution lane',array('status'=>409));
        $queued=MAD4B_SCP_Remote_Work_Queue::enqueue(MAD4B_SCP_Company_Provisioning_Work_Operations::JOB,$payload,$identity,3600);
        if(is_wp_error($queued))return $queued;
        return array('contract'=>'mad4b.start-your-company.queued.v2','state'=>$queued['state'],
          'company'=>$c['company_code'],'stage'=>$stage,'action'=>self::ACTIONS[$stage],
          'job_id'=>$queued['job']['job_id'],'executor_executed'=>false);
    }
    public static function run($req) {
        $auth=self::guard($req);if(is_wp_error($auth))return $auth;
        if(!((current_user_can('manage_options')&&current_user_can('activate_plugins')) ||
          (current_user_can('mad4b_manage_company_operations')&&current_user_can('mad4b_propose_company_content'))))
          return new WP_Error('mad4b_company_run_grant_missing','Company workflow capability required',array('status'=>403));
        $c=self::company($req->get_param('company'));if(is_wp_error($c))return $c;
        $input=$req->get_json_params();if(!is_array($input))$input=$req->get_params();
        $mode=(string)($input['mode']??'repair_existing');
        if(!in_array($mode,array('create','repair_existing'),true) ||
          !preg_match('/^[a-f0-9]{64}$/D',(string)($input['expected_plan_sha256']??'')))
          return new WP_Error('mad4b_company_run_invalid','Exact run mode and plan hash required',array('status'=>422));
        $ok=self::authorize_run($c,$mode,$input['expected_plan_sha256']);
        if(is_wp_error($ok))return $ok;
        return self::enqueue_ready($c,$mode);
    }
    public static function resume($req) {
        $auth=self::guard($req);if(is_wp_error($auth))return $auth;
        if(!((current_user_can('manage_options') && current_user_can('activate_plugins')) ||
          (current_user_can('mad4b_manage_company_operations') && current_user_can('mad4b_propose_company_content'))))
          return new WP_Error('mad4b_company_queue_grant_missing','Company queue capability required',array('status'=>403));
        $c=self::company($req->get_param('company'));if(is_wp_error($c))return $c;
        $input=$req->get_json_params();if(!is_array($input))$input=$req->get_params();
        $stage=(string)($input['stage']??'');
        $mode=(string)($input['mode']??'repair_existing');
        if(!isset(self::STAGES[$stage]) ||
          !in_array($mode,array('create','repair_existing'),true) ||
          !preg_match('/^[a-f0-9]{64}$/D',(string)($input['expected_plan_sha256']??'')))
          return new WP_Error('mad4b_company_stage_invalid','Exact stage and plan required',array('status'=>422));
        $ok=self::authorize_run($c,$mode,$input['expected_plan_sha256']);
        if(is_wp_error($ok))return $ok;
        return self::enqueue_ready($c,$mode,$stage);
    }
    /**
     * Single authoritative lifecycle result for create and repair_existing.
     * Source definitions and Coolify candidate resources never imply success.
     * Only independent readback-backed stage receipts can close this outcome.
     */
    public static function status($req){
        $auth=self::guard($req);if(is_wp_error($auth))return $auth;
        // Without an explicit mode show the current authorized lifecycle,
        // rather than silently changing an in-progress create to repair.
        if(!$req->get_param('mode')){
            $bound=self::company($req->get_param('company'));
            if(!is_wp_error($bound)){
                $stored=get_option(self::RUNS,array());
                $r=is_array($stored)?($stored[$bound['company_code']]??null):null;
                if(is_array($r) &&
                  ($r['site_uuid']??'')===$bound['site_uuid'] &&
                  ($r['origin']??'')===$bound['origin'] &&
                  in_array(($r['mode']??''),array('create','repair_existing'),true))
                  $req->set_param('mode',$r['mode']);
            }
        }
        $plan=self::plan($req);if(is_wp_error($plan))return $plan;
        $c=$plan['company'];
        $code=$c['company_code'];
        $snapshots=get_option(self::SNAPSHOTS,array());
        $snapshot=is_array($snapshots)?($snapshots[$code]??null):null;
        $recheck=is_array($snapshot) &&
          ($snapshot['company']['site_uuid']??'')===$c['site_uuid'] &&
          ($snapshot['plan_sha256']??'')===$plan['plan_sha256'];
        $runs=get_option(self::RUNS,array());
        $run=is_array($runs)?($runs[$code]??null):null;
        if(!is_array($run)||($run['site_uuid']??'')!==$c['site_uuid'] ||
          ($run['origin']??'')!==$c['origin'])$run=null;
        $runId=is_array($run)?($run['run_id']??null):null;

        $jobs=array();
        $queueReadable=false;
        if(class_exists('MAD4B_SCP_Remote_Work_Queue',false) &&
          method_exists('MAD4B_SCP_Remote_Work_Queue','list_jobs')){
            $listing=MAD4B_SCP_Remote_Work_Queue::list_jobs(
              MAD4B_SCP_Company_Provisioning_Work_Operations::JOB);
            if(is_array($listing)&&is_array($listing['items']??null)){
                $queueReadable=true;
                foreach($listing['items'] as $job){
                    if(!is_array($job)||!is_array($job['payload']??null))continue;
                    $p=$job['payload'];
                    if(($p['company_code']??'')!==$code ||
                      ($p['site_uuid']??'')!==$c['site_uuid'] ||
                      ($p['origin']??'')!==$c['origin'] ||
                      ($p['mode']??'')!==$plan['mode'] ||
                      !isset(self::STAGES[$p['stage']??'']))continue;
                    if(isset($p['run_id']) && $runId!==null &&
                      !hash_equals((string)$runId,(string)$p['run_id']))continue;
                    $jobs[]=array(
                      'id'=>(string)($job['job_id']??''),
                      'stage'=>(string)$p['stage'],
                      'status'=>(string)($job['effective_status']??$job['status']??'unknown'),
                      'reconciliation_required'=>!empty($job['reconciliation_required']),
                      'provider_side_effect_possible'=>!empty($job['provider_side_effect_possible'])
                    );
                }
            }
        }
        $hasActive=false;$hasReconciliation=false;
        foreach($jobs as $job){
            // A stale failed job cannot override a newer independently
            // verified stage receipt; historical jobs stay visible.
            if(($plan['stages'][$job['stage']]['state']??'')==='VERIFIED')continue;
            if(in_array($job['status'],array('pending','claimed','running'),true))$hasActive=true;
            if($job['reconciliation_required'] ||
              in_array($job['status'],array('reconciling','failed','expired','cancel_requested'),true))
              $hasReconciliation=true;
        }
        $verified=array();$ready=array();$waiting=array();
        foreach($plan['stages'] as $stage=>$row){
            if($row['state']==='VERIFIED')$verified[]=$stage;
            elseif($row['state']==='READY')$ready[]=$stage;
            else $waiting[$stage]=$row['missing_dependencies'];
        }
        $complete=count($verified)===count(self::STAGES);
        if($complete)$state='VERIFIED_COMPLETE';
        elseif($hasReconciliation)$state='NEEDS_RECONCILIATION';
        elseif($hasActive)$state='IN_PROGRESS';
        elseif(!$queueReadable)$state='QUEUE_OBSERVATION_UNAVAILABLE';
        elseif($ready && empty($plan['executor']['registered']))$state='WAITING_EXECUTOR';
        elseif($ready && !self::authorized_run($c,$plan['mode']))$state='AWAITING_RUN';
        elseif($ready)$state='READY_TO_CONTINUE';
        else $state='WAITING_DEPENDENCIES';

        // Legacy readiness is observational only; it cannot set completion.
        $audits=get_option(self::READINESS,array());
        $audit=is_array($audits)?($audits[$code]??null):null;
        $auditBound=is_array($audit) && ($audit['site_uuid']??'')===$c['site_uuid'];
        $evidence=array('stages'=>$verified,'jobs'=>$jobs,'plan_sha256'=>$plan['plan_sha256'],
          'run_id'=>$runId);
        return array(
          'contract'=>'mad4b.start-your-company.outcome.v1',
          'company'=>$c,'mode'=>$plan['mode'],'run_id'=>$runId,
          'overall_state'=>$state,'successful'=>$complete,
          'current_ready_stages'=>$ready,
          'verified_stages'=>$verified,
          'waiting_dependencies'=>$waiting,
          'stage_count'=>count(self::STAGES),'verified_count'=>count($verified),
          'readiness'=>$plan['readiness'],
          'executor'=>$plan['executor'],
          'jobs'=>$jobs,'job_observation_available'=>$queueReadable,
          'recheck_receipt_matched'=>$recheck,
          'snapshot_observed_at'=>$recheck?($snapshot['observed_at']??null):null,
          'legacy_readiness_observation'=>$auditBound?array(
             'result'=>(string)($audit['result']??'UNKNOWN'),
             'generated_utc'=>$audit['generated_utc']??null):null,
          'infrastructure_ownership'=>'UNVERIFIED_BY_NATIVE_RUNTIME',
          'coolify_candidate_is_not_active_runtime'=>true,
          'outcome_sha256'=>hash('sha256',wp_json_encode($evidence,
             JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
          'mutation_performed'=>false,
          'plan'=>$plan
        );
    }
    public static function routes() {
        register_rest_route('mad4b/v1',self::PATH.'/status',array('methods'=>'GET','callback'=>array(__CLASS__,'status'),'permission_callback'=>array(__CLASS__,'guard')));
        register_rest_route('mad4b/v1',self::PATH.'/plan',array('methods'=>'GET','callback'=>array(__CLASS__,'plan'),'permission_callback'=>array(__CLASS__,'guard')));
        register_rest_route('mad4b/v1',self::PATH.'/resume',array('methods'=>'POST','callback'=>array(__CLASS__,'resume'),'permission_callback'=>array(__CLASS__,'guard')));
        register_rest_route('mad4b/v1',self::PATH.'/run',array('methods'=>'POST','callback'=>array(__CLASS__,'run'),'permission_callback'=>array(__CLASS__,'guard')));
    }
    public static function abilities() {
        if(!self::central()||!function_exists('wp_register_ability'))return;
        foreach(array(
           'mad4b/start-your-company-plan'=>array('read'=>true,'method'=>'plan'),
           'mad4b/start-your-company-resume'=>array('read'=>false,'method'=>'resume'),
           'mad4b/start-your-company-run'=>array('read'=>false,'method'=>'run')
        ) as $name=>$spec) {
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            $props=array('company'=>array('type'=>'string'),'mode'=>array('type'=>'string','enum'=>array('create','repair_existing')));
            $required=array('company');
            if(!$spec['read']) {
                $props['expected_plan_sha256']=array('type'=>'string','pattern'=>'^[a-f0-9]{64}$');
                $required=array('company','expected_plan_sha256');
                if($spec['method']==='resume') {
                    $props['stage']=array('type'=>'string');
                    $required[]='stage';
                }
            }
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
    public static function created_event($payload) {
        self::company_event($payload);
        // Native authenticated company creation auto-starts the complete
        // workflow; it does not require approvals for each plugin.
        if(!self::central() ||
          !((current_user_can('manage_options')&&current_user_can('activate_plugins')) ||
            (current_user_can('mad4b_manage_company_operations')&&current_user_can('mad4b_propose_company_content'))))return;
        $code=is_array($payload)?($payload['company_code']??''):$payload;
        $c=self::company($code);if(is_wp_error($c))return;
        $r=new WP_REST_Request('GET','/');$r->set_param('company',$code);
        if(is_wp_error(self::guard($r)))return;
        $plan=self::compute_plan($c,'create');
        $auth=self::authorize_run($c,'create',$plan['plan_sha256']);
        if(is_wp_error($auth))return;
        $result=self::enqueue_ready($c,'create');
        if(is_wp_error($result))do_action('mad4b/company/stage_blocked',$c,$result->get_error_code());
    }
    public static function company_event($payload){
        $code=is_array($payload)?($payload['company_code']??''):$payload;
        if(!self::central()||!is_string($code)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))return;
        $c=self::company($code);if(is_wp_error($c))return;
        if(!wp_next_scheduled(self::HOOK,array($code)))wp_schedule_single_event(time()+20,self::HOOK,array($code));
    }
    public static function option_event($name,$old,$new){
        if(!in_array($name,array(self::REGISTRY,self::BOOTSTRAP,self::RECEIPTS),true)||!is_array($new))return;
        $prev=is_array($old)?$old:array();
        foreach($new as $code=>$row) {
            if(!isset($prev[$code])) {
                if($name===self::REGISTRY)self::created_event($code);
                else self::company_event($code);
            } elseif($row!==$prev[$code])self::company_event($code);
        }
    }
    public static function recheck_event($code){
        // Cron only computes and persists an unprivileged, read-only plan.
        if(!self::central())return;
        $c=self::company($code);if(is_wp_error($c))return;
        $result=self::compute_plan($c,'repair_existing');
        if(!is_array($result))return;
        $all=get_option(self::SNAPSHOTS,array());if(!is_array($all))return;
        $result['observed_at']=gmdate('c');
        $all[$c['company_code']]=$result;
        update_option(self::SNAPSHOTS,$all,false);
        $check=get_option(self::SNAPSHOTS,array());
        if(is_array($check) && ($check[$c['company_code']]['plan_sha256']??'')===$result['plan_sha256']) {
          do_action('mad4b/company/recheck_observed',$c);
          $run=get_option(self::RUNS,array());
          $row=is_array($run)?($run[$c['company_code']]??null):null;
          if(is_array($row) && self::authorized_run($c,(string)($row['mode']??''))) {
              $out=self::enqueue_ready($c,$row['mode']);
              if(is_wp_error($out))do_action('mad4b/company/stage_blocked',$c,$out->get_error_code());
          }
        }
    }
}
MAD4B_Start_Company_Runtime::boot();
