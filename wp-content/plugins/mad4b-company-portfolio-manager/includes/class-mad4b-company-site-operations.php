<?php
/**
 * MAD4B Company Management: bounded company-site operational processing.
 * Central creates explicit, exact-target requests; original host-local WP-CLI
 * performs independent identity/binding checks and owns the actual action.
 * This is not a generic remote shell, OAuth replacement, grant, or 313 receipt.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Site_Operations_V1 {
    const CONTRACT = 'mad4b.company.site-operations.v1';
    const STORE = 'mad4b_company_site_operations_v1';
    const OPERATIONS = [
        'site.binding.inspect' => 'Inspect exact Site Profile and deployment binding',
        'site.readiness.run' => 'Run existing authorized Content Readiness scan',
        'site.runtime.inspect' => 'Inspect Control Plane and Readiness operational capabilities',
        'site.content.inspect' => 'Inspect exact-site Source Loop, Brand Core, and governed publication blockers',
        'site.presentation.inspect' => 'Inspect Presentation Integrity browser evidence'
    ];
    const MAX_ITEMS = 100;
    const AGE = 7200;
    private static function central():bool {
        return class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
            && MAD4B_Company_Portfolio_Manager_V1::staging()
            && class_exists('MAD4B_Company_Environment_Registry_V2',false);
    }
    private static function cli():bool {
        return self::central() && PHP_SAPI==='cli' && defined('WP_CLI') && WP_CLI
            && class_exists('WP_CLI',false) && get_current_user_id()===0
            && !WP_CLI::get_config('user');
    }
    public static function owner():bool {
        return self::central() && current_user_can('manage_options')
            && current_user_can('activate_plugins');
    }
    private static function deny(string $reason):WP_Error {
        return new WP_Error('mad4b_company_site_operations_'.$reason,
            'Exact Company Management site operation denied: '.$reason,['status'=>409]);
    }
    private static function target(string $company,string $environment):array {
        if(!self::central() || !preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
            || !in_array($environment,['staging','production'],true))return [];
        $t=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
        if(!is_array($t)||($t['company_id']??'')!==$company
            ||($t['environment']??'')!==$environment
            ||!wp_is_uuid((string)($t['site_uuid']??'')))return [];
        $url=untrailingslashit((string)($t['canonical_origin']??''));
        if(!preg_match('#^https://[a-z0-9.-]+$#i',$url)
            ||wp_parse_url($url,PHP_URL_HOST)===false)return [];
        return $t;
    }
    public static function plan(string $company,string $environment,string $operation):array {
        $base=['contract'=>self::CONTRACT.'.plan',
            'company_code'=>$company,'environment'=>$environment,'operation'=>$operation,
            'operation_registered'=>isset(self::OPERATIONS[$operation]),
            'central_execution_authorized'=>false,
            'requires_independent_host_local_wpcli_identity'=>true,
            'requires_original_site_policy'=>true,'generic_cli_allowed'=>false,
            'site_profile_mutation_allowed'=>false,'oauth_required_for_host_local'=>false,
            'credentials_returned'=>false,'effect_executed'=>false];
        if(!isset(self::OPERATIONS[$operation]))return $base+['state'=>'OPERATION_UNSUPPORTED'];
        $target=self::target($company,$environment);
        if(!$target)return $base+['state'=>'COMPANY_ENVIRONMENT_TARGET_UNVERIFIED'];
        $uuid=(string)$target['site_uuid'];$origin=untrailingslashit((string)$target['canonical_origin']);
        $identity=[self::CONTRACT,$company,$environment,$uuid,$origin,$operation];
        $hash=hash('sha256',implode("\n",$identity));
        $remote_candidate=!empty($target['write_authorized']);
        return $base+[
            'state'=>'ENROLLED_TARGET_REQUIRES_INDEPENDENT_HOST_LOCAL_READBACK',
            'plan_sha256'=>$hash,'site_uuid'=>$uuid,'canonical_origin'=>$origin,
            'label'=>self::OPERATIONS[$operation],
            'company_connection_state'=>(string)($target['connection_state']??'UNKNOWN'),
            'central_execution_authorized'=>false, // host-local route is not a signed central remote grant
            'remote_registry_candidate'=>$remote_candidate,
            'host_local_operation_eligible_for_preflight'=>true,
            'native_worker_operation'=>'mad4b company-site-ops',
            'reconciler'=>'Company Management -> exact site WP-CLI -> original local operation -> receipt',
            'receipt_claim'=>'original_site_owned_readback_not_original_313',
            'routing_policy'=>class_exists('MAD4B_Company_Central_Preferred_Router_V1',false)
                ? MAD4B_Company_Central_Preferred_Router_V1::routing_policy(
                    $company,$environment,$operation)
                : [
                    'state'=>'ROUTER_NOT_LOADED',
                    'preferred_lane'=>null,
                    'candidate_lanes'=>['vps_client_mcp_cross_ssh','company_manager_connected_api'],
                    'default_authorization'=>'company_manager_oauth',
                    'remote_write_requires_original_signed_grant'=>true,
                    'fallback'=>'pre_dispatch_only_same_target_no_replay_after_attempt'
                  ],
            'default_grant_authorization'=>'company_manager_oauth',
            'oauth_route_is_not_original_313_write_grant'=>true,
            'transport_choice_is_not_original_mutation_authority'=>true
        ];
    }
    private static function lock() {
        global $wpdb;
        $lock='mad4b_company_site_ops_queue_'.get_current_blog_id();
        return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$lock))===1?$lock:null;
    }
    private static function unlock(?string $key):void {
        if($key){global $wpdb;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
    }
    private static function rows():array {
        $r=get_option(self::STORE,[]);return is_array($r)?$r:[];
    }
    public static function request(string $company,string $environment,string $operation,string $expected_sha,string $actor):array {
        if(!$actor || (!self::cli()&&!self::owner()))return ['state'=>'NOT_AUTHORIZED'];
        $plan=self::plan($company,$environment,$operation);
        if(!isset($plan['plan_sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$expected_sha)
            ||!hash_equals($plan['plan_sha256'],$expected_sha))return ['state'=>'STALE_OR_UNVERIFIED_PLAN'];
        $lock=self::lock();if(!$lock)return ['state'=>'QUEUE_LOCK_BUSY'];
        try {
            $rows=self::rows();$now=time();
            foreach($rows as $id=>$r)if(($r['state']??'')==='QUEUED'
                &&($r['company_code']??'')===$company
                &&($r['environment']??'')===$environment
                &&($r['operation']??'')===$operation
                &&($r['expires_epoch']??0)>$now)
                return ['state'=>'ALREADY_QUEUED','request_id'=>$id,'plan_sha256'=>$expected_sha];
            if(count($rows)>=self::MAX_ITEMS){
                uasort($rows,static fn($a,$b)=>($a['requested_epoch']??0)<=>($b['requested_epoch']??0));
                foreach($rows as $id=>$r)if(count($rows)>=self::MAX_ITEMS
                    &&($r['state']??'')!=='QUEUED')unset($rows[$id]);
                if(count($rows)>=self::MAX_ITEMS)return ['state'=>'QUEUE_CAPACITY_REACHED'];
            }
            $id=wp_generate_uuid4();
            $rows[$id]=[
                'request_id'=>$id,'contract'=>self::CONTRACT.'.request',
                'company_code'=>$company,'environment'=>$environment,'operation'=>$operation,
                'site_uuid'=>$plan['site_uuid'],'canonical_origin'=>$plan['canonical_origin'],
                'plan_sha256'=>$plan['plan_sha256'],'state'=>'QUEUED',
                'requested_epoch'=>$now,'expires_epoch'=>$now+self::AGE,
                'actor'=>$actor,'created_at'=>gmdate('c'),
                'authority_granted'=>false,'original_313_certified'=>false
            ];
            if(!update_option(self::STORE,$rows,false)&&get_option(self::STORE)!==$rows)
                return ['state'=>'OPTION_WRITE_FAILED'];
            if(class_exists('MAD4B_SCP_Audit',false))
                MAD4B_SCP_Audit::record('mad4b/company-site-operation-requested',
                    ['request_id'=>$id,'company_code'=>$company,'environment'=>$environment,
                     'site_uuid'=>$plan['site_uuid'],'operation'=>$operation,
                     'plan_sha256'=>$expected_sha,'actor'=>$actor,
                     'authority_granted'=>false],'intent');
            return ['state'=>'QUEUED','request_id'=>$id,
                'plan_sha256'=>$expected_sha,'site_uuid'=>$plan['site_uuid'],
                'receipt_state'=>'AWAITING_INDEPENDENT_HOST_READBACK'];
        } finally {self::unlock($lock);}
    }
    public static function pending():array {
        if(!self::cli())return ['contract'=>self::CONTRACT.'.pending','authorized'=>false,'items'=>[]];
        $rows=[];$now=time();
        foreach(self::rows() as $r)if(($r['state']??'')==='QUEUED'
            &&(int)($r['expires_epoch']??0)>$now) {
            $rows[]=$r;if(count($rows)>=10)break;
        }
        return ['contract'=>self::CONTRACT.'.pending','authorized'=>true,
                'items'=>$rows,'count'=>count($rows)];
    }
    private static function receipt_diagnostic(array $candidate):?array {
        // Advisory-only status label, not a signed privilege or proof. Evidence
        // digest and the immutable host receipt remain the authoritative source.
        if(array_keys($candidate)!==['route','finding','site_binding_verified','presentation_fresh','policy_blockers_count'])return null;
        if(!in_array($candidate['route'],['local_wpcli','vps_client_mcp_cross_ssh','unavailable'],true))return null;
        if(!in_array($candidate['finding'],[
            'BINDING_VERIFIED','BINDING_UNVERIFIED','PRESENTATION_VERIFIED','RUNTIME_INSPECTED',
            'PRESENTATION_EVIDENCE_NOT_VERIFIED','READINESS_COMPLETED',
            'READINESS_NOT_DUE','ORIGINAL_SIGNED_REMOTE_WRITE_REQUIRED',
            'REMOTE_SITE_POLICY_BLOCKED','REMOTE_SITE_PROFILE_UNREADY',
            'REMOTE_ROUTE_UNAVAILABLE','EXECUTION_FAILED','STATUS_UNVERIFIED'
        ],true))return null;
        foreach(['site_binding_verified','presentation_fresh'] as $key)
            if(!is_bool($candidate[$key])&&$candidate[$key]!==null)return null;
        if(!is_int($candidate['policy_blockers_count'])||$candidate['policy_blockers_count']<0
            ||$candidate['policy_blockers_count']>100)return null;
        return $candidate;
    }
    public static function receipt(string $request_id,string $expected_sha,string $state,
                                  string $result_hash,string $result_contract,array $diagnostic=[]):array {
        if(!self::cli()||!wp_is_uuid($request_id)||!preg_match('/^[a-f0-9]{64}$/D',$expected_sha)
            ||!preg_match('/^[a-f0-9]{64}$/D',$result_hash)
            ||!in_array($state,['COMPLETED','NOT_DUE','BLOCKED','NOT_LOCALLY_BOUND','FAILED'],true)
            ||!in_array($result_contract,[
                'mad4b.cli-readiness.v1.run','mad4b.cli-readiness.v1.plan',
                'mad4b.company-site.binding-readback.v1',
                'mad4b.company-site.remote-binding-readback.v1',
                'mad4b.company-site.remote-readiness-plan.v1',
                'mad4b.company-site.remote-readiness-run.v1',
                'mad4b.company-site.runtime-readback.v1',
                'mad4b.company-site.content-readback.v1',
                'mad4b.company-site.remote-content-readback.v1',
                'mad4b.company-site.remote-presentation-readback.v1',
                'mad4b.presentation-browser-evidence.v1',
                'mad4b.company-site.runner-error.v1'],true))return ['state'=>'RECEIPT_REJECTED'];
        $lock=self::lock();if(!$lock)return ['state'=>'QUEUE_LOCK_BUSY'];
        try{
            $all=self::rows();$r=$all[$request_id]??null;
            if(!is_array($r)||($r['state']??'')!=='QUEUED'
                ||!hash_equals((string)($r['plan_sha256']??''),$expected_sha)
                ||(int)($r['expires_epoch']??0)<time())return ['state'=>'STALE_REQUEST'];
            $allowed_receipts=[
                'site.binding.inspect'=>[
                    'mad4b.company-site.binding-readback.v1'=>['COMPLETED','BLOCKED'],
                    'mad4b.company-site.remote-binding-readback.v1'=>['COMPLETED','BLOCKED']],
                'site.readiness.run'=>[
                    'mad4b.cli-readiness.v1.run'=>['COMPLETED','NOT_DUE'],
                    'mad4b.cli-readiness.v1.plan'=>['NOT_DUE','BLOCKED'],
                    'mad4b.company-site.remote-readiness-plan.v1'=>['NOT_DUE','BLOCKED'],
                    'mad4b.company-site.remote-readiness-run.v1'=>['COMPLETED']],
                'site.runtime.inspect'=>['mad4b.company-site.runtime-readback.v1'=>['COMPLETED']],
                'site.content.inspect'=>[
                    'mad4b.company-site.content-readback.v1'=>['COMPLETED','BLOCKED'],
                    'mad4b.company-site.remote-content-readback.v1'=>['COMPLETED','BLOCKED']],
                'site.presentation.inspect'=>[
                    'mad4b.presentation-browser-evidence.v1'=>['COMPLETED','BLOCKED'],
                    'mad4b.company-site.remote-presentation-readback.v1'=>['COMPLETED','BLOCKED']],
            ];
            $source_allowed=$allowed_receipts[(string)$r['operation']][$result_contract]??[];
            if($result_contract==='mad4b.company-site.runner-error.v1')
                $source_allowed=['BLOCKED','NOT_LOCALLY_BOUND','FAILED'];
            if(!in_array($state,$source_allowed,true))return ['state'=>'RESULT_CONTRACT_OPERATION_MISMATCH'];
            $plan=self::plan((string)$r['company_code'],(string)$r['environment'],(string)$r['operation']);
            if(($plan['plan_sha256']??'')!==$expected_sha
                ||($plan['site_uuid']??'')!==$r['site_uuid']
                ||($plan['canonical_origin']??'')!==$r['canonical_origin'])return ['state'=>'TARGET_DRIFT'];
            $r['state']=$state;$r['finished_at']=gmdate('c');
            $r['evidence_sha256']=$result_hash;$r['result_contract']=$result_contract;
            $r['host_local_only']=!str_starts_with($result_contract,'mad4b.company-site.remote-');
            $r['original_313_certified']=false;
            if($diagnostic){
                $safe=self::receipt_diagnostic($diagnostic);
                if($safe===null)return ['state'=>'RECEIPT_DIAGNOSTIC_INVALID'];
                $r['diagnostic']=$safe;
                if($safe['route']==='vps_client_mcp_cross_ssh')$r['host_local_only']=false;
            }
            $all[$request_id]=$r;
            if(!update_option(self::STORE,$all,false)&&get_option(self::STORE)!==$all)
                return ['state'=>'OPTION_WRITE_FAILED'];
            if(class_exists('MAD4B_SCP_Audit',false))
                MAD4B_SCP_Audit::record('mad4b/company-site-operation-receipt',
                    ['request_id'=>$request_id,'site_uuid'=>$r['site_uuid'],
                    'operation'=>$r['operation'],'result_state'=>$state,
                    'result_contract'=>$result_contract,'evidence_sha256'=>$result_hash,
                    'original_313_certified'=>false],'ok');
            return ['state'=>$state,'request_id'=>$request_id,'evidence_sha256'=>$result_hash,
                    'original_313_certified'=>false];
        }finally{self::unlock($lock);}
    }
    public static function status(string $request_id):array {
        if(!self::cli()&&!self::owner())return ['state'=>'NOT_AUTHORIZED'];
        $row=self::rows()[$request_id]??null;
        if(!is_array($row))return ['state'=>'NOT_FOUND'];
        if(($row['state']??'')==='QUEUED'&&($row['expires_epoch']??0)<time())
            $row['state']='EXPIRED';
        return $row;
    }
    private static function cli_diagnostic(string $encoded):array {
        if($encoded==='')return [];
        if(strlen($encoded)>700||!preg_match('/^[A-Za-z0-9+\/=]+$/D',$encoded))return ['invalid'=>true];
        $decoded=base64_decode($encoded,true);
        if(!is_string($decoded)||strlen($decoded)>600)return ['invalid'=>true];
        $value=json_decode($decoded,true);
        return is_array($value)?$value:['invalid'=>true];
    }
    public static function cli_command(array $args,array $opts):void {
        if(!self::cli())WP_CLI::error('Central native site operator context required.');
        $op=$args[0]??'';
        $allowed=['company','environment','operation','expected-plan','request-id','state','evidence-sha','result-contract','diagnostic-b64'];
        if(count($args)!==1||array_diff(array_keys($opts),$allowed))WP_CLI::error('Unsupported site operation arguments');
        $v=match($op){
            'plan'=>self::plan((string)($opts['company']??''),(string)($opts['environment']??''),(string)($opts['operation']??'')),
            'request'=>self::request((string)($opts['company']??''),(string)($opts['environment']??''),(string)($opts['operation']??''),(string)($opts['expected-plan']??''),'native_host_operator'),
            'pending'=>self::pending(),
            'status'=>self::status((string)($opts['request-id']??'')),
            'receipt'=>self::receipt((string)($opts['request-id']??''),(string)($opts['expected-plan']??''),(string)($opts['state']??''),(string)($opts['evidence-sha']??''),(string)($opts['result-contract']??''),
                self::cli_diagnostic((string)($opts['diagnostic-b64']??''))),
            default=>['state'=>'OPERATION_UNSUPPORTED']
        };
        WP_CLI::line(wp_json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        if(in_array($v['state']??'',['OPERATION_UNSUPPORTED','RECEIPT_REJECTED','STALE_REQUEST','TARGET_DRIFT','STALE_OR_UNVERIFIED_PLAN','RESULT_CONTRACT_OPERATION_MISMATCH','RECEIPT_DIAGNOSTIC_INVALID','NOT_AUTHORIZED','QUEUE_LOCK_BUSY','OPTION_WRITE_FAILED','QUEUE_CAPACITY_REACHED','COMPANY_ENVIRONMENT_TARGET_UNVERIFIED'],true))
            WP_CLI::error('Company site operation rejected');
    }
    public static function menu():void {
        if(!self::central()||!current_user_can('manage_options'))return;
        add_submenu_page('mad4b-company-brand-readiness','Company Site Operations',
          'Site Operations','manage_options','mad4b-company-site-operations',[__CLASS__,'page']);
    }
    public static function page():void {
        MAD4B_Company_Site_Operations_Dashboard_V1::render();
    }
    public static function admin_request():void {
        if(!self::owner())wp_die('Central company operator required', '', ['response'=>403]);
        $company=sanitize_key((string)($_POST['company']??''));
        $environment=sanitize_key((string)($_POST['environment']??''));
        $operation=sanitize_text_field((string)($_POST['operation']??''));
        $sha=(string)($_POST['expected_plan']??'');
        check_admin_referer('mad4b_company_site_ops_'.$sha);
        $r=self::request($company,$environment,$operation,$sha,'central_admin_'.get_current_user_id());
        $url=add_query_arg(['page'=>'mad4b-company-site-operations','company'=>$company,
            'environment'=>$environment,'request_state'=>$r['state']??'UNKNOWN',
            'request_id'=>(string)($r['request_id']??'')],admin_url('admin.php'));
        wp_safe_redirect($url);exit;
    }
    public static function rest():void {
        // Loaded lazily for genuine REST route registration; the UI module is
        // not parsed for ordinary visitor requests.
        require_once __DIR__.'/class-mad4b-company-site-operations-dashboard.php';
        $ns='mad4b-company-management/v1';
        register_rest_route($ns,'/site-operations/plan',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'owner'],
            'callback'=>static fn($r)=>rest_ensure_response(self::plan(
                sanitize_key((string)$r['company']),sanitize_key((string)$r['environment']),
                (string)$r['operation']))]);
        register_rest_route($ns,'/site-operations/status/(?P<request_id>[a-f0-9-]{36})',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'owner'],
            'callback'=>static fn($r)=>rest_ensure_response(self::status((string)$r['request_id']))]);
        register_rest_route($ns,'/site-operations/overview',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'owner'],
            'callback'=>static fn($r)=>rest_ensure_response(MAD4B_Company_Site_Operations_Dashboard_V1::overview(
                (string)$r['company'],(string)$r['environment']))]);
        register_rest_route($ns,'/site-operations/requests',[
            'methods'=>'POST',
            'permission_callback'=>static fn($r)=>MAD4B_Company_Portfolio_Manager_V1::rest_write_permission($r),
            'callback'=>static function($r){
                $body=(array)$r->get_json_params();
                return rest_ensure_response(self::request(
                    sanitize_key((string)($body['company']??'')),
                    sanitize_key((string)($body['environment']??'')),
                    (string)($body['operation']??''),
                    (string)($body['expected_plan_sha256']??''),
                    'central_rest_admin_'.get_current_user_id()
                ));
            }]);
    }
    public static function abilities():void {
        if(!self::central() || !function_exists('wp_register_ability'))return;
        $defs=[
            'mad4b/company-site-operation-plan'=>[
                'required'=>['company','environment','operation'],
                'props'=>['company'=>['type'=>'string'],'environment'=>['type'=>'string'],
                          'operation'=>['type'=>'string']],
                'callback'=>static fn($v)=>self::plan((string)($v['company']??''),
                    (string)($v['environment']??''),(string)($v['operation']??''))],
            'mad4b/company-site-operation-status'=>[
                'required'=>['request_id'],
                'props'=>['request_id'=>['type'=>'string']],
                'callback'=>static fn($v)=>self::status((string)($v['request_id']??''))]
        ];
        foreach($defs as $id=>$d){
            if(function_exists('wp_has_ability') && wp_has_ability($id))continue;
            wp_register_ability($id,[
                'label'=>str_replace('-',' ',str_replace('mad4b/','',$id)),
                'description'=>'Read original Company Management target plan and host-local operation receipt, without granting any host write.',
                'category'=>'mad4b-read',
                'permission_callback'=>static fn()=>self::owner(),
                'execute_callback'=>$d['callback'],
                'input_schema'=>['type'=>'object','properties'=>$d['props'],'required'=>$d['required'],'additionalProperties'=>false],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>['public'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                    'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
            ]);
        }
    }
    public static function boot():void {
        // REST_REQUEST is defined after plugins_loaded by WordPress; testing it
        // here would silently disable the dashboard's authenticated API.
        // Register only callbacks on public requests, not an active scan or IO.
        if(!self::central())return;
        add_action('rest_api_init',[__CLASS__,'rest']);
        add_action('wp_abilities_api_init',[__CLASS__,'abilities'],39);
        if(is_admin()){
            require_once __DIR__.'/class-mad4b-company-site-operations-dashboard.php';
            add_action('admin_menu',[__CLASS__,'menu'],50);
            add_action('admin_enqueue_scripts',['MAD4B_Company_Site_Operations_Dashboard_V1','assets'],25);
            add_action('admin_post_mad4b_company_site_ops_request',[__CLASS__,'admin_request']);
        }
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI',false)){
            require_once __DIR__.'/class-mad4b-company-site-operations-dashboard.php';
            WP_CLI::add_command('mad4b company-site-ops',[__CLASS__,'cli_command']);
        }
    }}
