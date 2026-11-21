<?php
/**
 * Plugin Name: MAD4B Tourism Company Workflow REST v1
 * Description: A guarded orchestration contract for company provisioning, profile, hosted content, domains and release.
 * Version: 1.0.0
 * No GitHub / SSH dependency. No secrets in REST payloads, logs, options or metadata.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tourism_Workflow_REST {
    const VERSION='mad4b.tourism-company-workflow.v1';
    const NS='mad4b/v1';
    const PREFIX='/tourism-launch';
    const META='_mad4b_company_launch_v1';
    const JOURNAL='_mad4b_workflow_journal_v1';
    const INTENTS='_mad4b_workflow_intents_v1';
    const COOLIFY='https://coolify.infra.mad4b.com/api/v1';
    const TOKEN='/run/secrets/mad4b-company-providers/coolify-token';
    const CATALOG='/run/mad4b-company-hub-catalog.json';
    const INTEGRITY='/run/mad4b-company-source-integrity.json';
    const HUB_GRANTS='/run/mad4b-company-content-grants.json';
    const LANG=array('en','ar','fr','de','it','es');
    const MONEY=array('USD','EUR','GBP','EGP','AED','SAR');
    const PROJECT='rriplb5dqyqni8dd8myjhuf0';
    const ENV='yimmtvynzq5wwsa1r9styguz';
    const QA_ENV='tkh2jej7xytnaopan2towhur';
    const SERVER='g41fdbqsxq6c4hokbqlzqjdn';
    const STATES=array('intake_received','owner_approved','resource_reserved','profile_pending',
        'tenant_enrolled','content_trial','domain_pending','launch_review','launched');
    private static $ready=false;

    public static function boot(){
        if(self::$ready) return;
        self::$ready=true;
        add_action('rest_api_init',array(__CLASS__,'routes'),45);
    }
    private static function error($code,$message,$status=409){
        return new WP_Error($code,$message,array('status'=>$status));
    }
    private static function reply($value,$status=200){
        $r=new WP_REST_Response($value,$status);
        $r->header('Cache-Control','private, no-store');
        return $r;
    }
    /** Explicit internal synthetic fixture never changes the public intake target. */
    private static function target_environment_uuid(array $x):string {
        $row=(array)($x['record']??[]);
        $code=(string)($x['data']['company_code']??'');
        if(wp_get_environment_type()==='staging'
          && ($row['origin']??'')==='vps_synthetic_qa_fixture'
          && ($row['target_environment']??'')==='staging'
          && ($row['qa_fixture_contract']??'')==='mad4b.internal-qa-reservation.v1'
          && preg_match('/^qa-[a-z0-9-]{4,40}$/D',$code)===1)
            return self::QA_ENV;
        return self::ENV;
    }
    public static function read_permission(){
        return current_user_can('manage_options') && current_user_can('activate_plugins')?
            true:self::error('mad4b_workflow_owner_required','Owner administrator required.',403);
    }
    public static function write_permission(){
        $allowed=self::read_permission();
        if(is_wp_error($allowed))return $allowed;
        // Independent WP-CLI administrator access for the enrolled QA principal
        // on the exact central Staging origin. No browser cookie/REST nonce is
        // required for a trusted local WP-CLI invocation with --user=25.
        // This branch is never available to an HTTP request.
        if(defined('WP_CLI') && WP_CLI && (!defined('REST_REQUEST') || !REST_REQUEST)
            && wp_get_environment_type()==='staging'
            && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com'
            && defined('DB_NAME') && DB_NAME==='etg_commerce_staging'
            && get_current_user_id()===25
            && class_exists('MAD4B_SCP_Site_Profile',false)) {
            $qa=wp_get_current_user();
            if($qa instanceof WP_User
                && $qa->user_login==='lab_jetengine_qa'
                && (array)$qa->roles===array('administrator')
                && MAD4B_SCP_Site_Profile::user_is_enrolled(25)
                && MAD4B_SCP_Site_Profile::origin_enrolled()
                && MAD4B_SCP_Site_Profile::site_urls_match_enrollment())
                return true;
        }
        // Require human owner browser session, not unattended app passwords or
        // an AI principal implicitly inheriting WordPress administrator status.
        $cookie=defined('LOGGED_IN_COOKIE')?(string)($_COOKIE[LOGGED_IN_COOKIE]??''):'';
        $id=$cookie!==''?wp_validate_auth_cookie($cookie,'logged_in'):false;
        $nonce=(string)($_SERVER['HTTP_X_WP_NONCE']??'');
        $session=function_exists('wp_get_session_token')?(string)wp_get_session_token():'';
        if(!$id || (int)$id!==get_current_user_id() || !$session || !$nonce ||
            !wp_verify_nonce($nonce,'wp_rest'))
            return self::error('mad4b_workflow_browser_owner_required',
                'Verified owner browser session and fresh REST nonce required.',403);
        return true;
    }
    public static function routes(){
        $routes=array(
          array('GET','/workflow','contract','public_permission'),
          array('GET','/providers','providers','read_permission'),
          array('GET','/applications/(?P<id>[1-9][0-9]{0,9})/workflow','workflow','read_permission'),
          array('GET','/applications/(?P<id>[1-9][0-9]{0,9})/events','events','read_permission'),
          array('GET','/applications/(?P<id>[1-9][0-9]{0,9})/source/plan','source_plan','read_permission'),
          array('GET','/applications/(?P<id>[1-9][0-9]{0,9})/coolify/plan','coolify_plan','read_permission'),
          array('POST','/applications/(?P<id>[1-9][0-9]{0,9})/coolify/reserve','coolify_reserve','write_permission'),
          array('GET','/applications/(?P<id>[1-9][0-9]{0,9})/coolify/readback','coolify_readback','read_permission'),
          array('GET','/applications/(?P<id>[1-9][0-9]{0,9})/bootstrap/plan','bootstrap_plan','read_permission'),
          array('POST','/applications/(?P<id>[1-9][0-9]{0,9})/bootstrap/queue','bootstrap_queue','write_permission'),
          array('GET','/applications/(?P<id>[1-9][0-9]{0,9})/bootstrap/readback','bootstrap_readback','read_permission'),
          array('GET','/applications/(?P<id>[1-9][0-9]{0,9})/site-profile','site_profile','read_permission'),
          array('POST','/applications/(?P<id>[1-9][0-9]{0,9})/site-profile/verify','site_profile_verify','write_permission'),
          array('GET','/hub/catalog','hub_catalog','read_permission'),
          array('GET','/applications/(?P<id>[1-9][0-9]{0,9})/hub/selection','hub_selection','read_permission'),
          array('POST','/applications/(?P<id>[1-9][0-9]{0,9})/hub/selection','hub_save','write_permission'),
          array('GET','/applications/(?P<id>[1-9][0-9]{0,9})/cloudflare/plan','cloudflare_plan','read_permission'),
          array('POST','/applications/(?P<id>[1-9][0-9]{0,9})/cloudflare/zone-enroll','cloudflare_zone_enroll','write_permission'),
          array('POST','/applications/(?P<id>[1-9][0-9]{0,9})/cloudflare/commit','cloudflare_commit','write_permission'),
          array('GET','/applications/(?P<id>[1-9][0-9]{0,9})/cloudflare/readback','cloudflare_readback','read_permission'),
          array('GET','/applications/(?P<id>[1-9][0-9]{0,9})/release/plan','release_plan','read_permission'),
          array('POST','/applications/(?P<id>[1-9][0-9]{0,9})/release/approve','release_approve','write_permission'),
          array('POST','/applications/(?P<id>[1-9][0-9]{0,9})/release/commit','release_commit','write_permission'),
        );
        foreach($routes as $item){
            register_rest_route(self::NS,self::PREFIX.$item[1],array(
                'methods'=>$item[0],
                'callback'=>($item[2]==='coolify_reserve' ? array('MAD4B_Central_VPS_Broker_V1','reserve_company_service') : array(__CLASS__,$item[2])),
                'permission_callback'=>array(__CLASS__,$item[3]),
            ));
        }
    }
    public static function public_permission(){return true;}
    public static function contract(){
        return self::reply(array(
            'contract'=>self::VERSION,
            'required_intake'=>array('brand_name','email','phone_e164','consent'),
            'optional_intake'=>array('company_code','logo_url','colors','domain_mode','domain'),
            'stages'=>array('intake','owner_review','source_certification',
                'coolify_reservation','bootstrap_private','native_site_profile',
                'hosted_content_selection','cloudflare_domain','tls_readback',
                'legal_and_commercial_admission','owner_go_live'),
            'provider_adapters'=>array('coolify'=>'compose_service_private_reservation_via_original_broker',
                'cloudflare'=>'existing_company_domains_plan_commit_readback',
                'site_profile'=>'native_v2_only','content_hub'=>'hosted_preview_no_uploads'),
            'access'=>'reads_owner_admin;writes_owner_browser_cookie_and_wp_rest_nonce',
            'idempotency'=>'stable request idempotency_key + request fingerprint; remote unknown requires readback',
            'production_launch_from_intake'=>false,'github_in_runtime'=>false,
        ));
    }
    private static function current($request){
        $id=(int)$request->get_param('id');
        $post=get_post($id);
        if(!$post || $post->post_type!=='mad4b_company_launch'||$post->post_status!=='private')
            return self::error('mad4b_workflow_application_unknown','Company request not found.',404);
        $record=get_post_meta($id,self::META,true);
        if(!is_array($record)||($record['contract']??'')!=='mad4b.company-launch.vps.v1' ||
            !in_array($record['state']??'',self::STATES,true) ||
            !preg_match('/^[a-z][a-z0-9-]{2,47}$/D',(string)($record['data']['company_code']??'')) )
            return self::error('mad4b_workflow_record_invalid','Company request identity invalid.',409);
        return array('id'=>$id,'post'=>$post,'record'=>$record,'data'=>$record['data']);
    }
    private static function setup(){
        $s=get_option('mad4b_tourism_launch_setup_v1',array());
        return is_array($s)?$s:array();
    }
    private static function token(){
        if(!is_file(self::TOKEN)||is_link(self::TOKEN)||!is_readable(self::TOKEN))return '';
        $s=trim((string)file_get_contents(self::TOKEN));
        return strlen($s)>=20 && strlen($s)<=512 &&
            preg_match('/^[A-Za-z0-9._~+\/|=-]{20,512}$/D',$s)?$s:'';
    }
    private static function proof(){
        $p=WPMU_PLUGIN_DIR.'/mad4b-company-launch-source-proof.json';
        if(!is_file($p)||is_link($p)||!is_readable($p))return array();
        $r=json_decode((string)file_get_contents($p),true);
        return is_array($r)&&($r['contract']??'')==='mad4b.provisioning-source-proof.v1'?$r:array();
    }
    private static function read_coolify($path){
        $token=self::token();
        if($token===''||!preg_match('~^/(?:teams/current|projects|servers|applications/[a-zA-Z0-9-]{8,65}|services/[a-zA-Z0-9-]{8,65})$~D',$path))
            return self::error('mad4b_workflow_coolify_scope','Provider request outside allowed read contract.',503);
        $response=wp_safe_remote_get(self::COOLIFY.$path,array('timeout'=>13,'redirection'=>0,
            'headers'=>array('Authorization'=>'Bearer '.$token,'Accept'=>'application/json'),
            'limit_response_size'=>40000));
        if(is_wp_error($response))
            return self::error('mad4b_workflow_coolify_unreachable','Provider readback unavailable.',503);
        $code=(int)wp_remote_retrieve_response_code($response);
        $body=json_decode((string)wp_remote_retrieve_body($response),true);
        if($code!==200||!is_array($body))
            return self::error('mad4b_workflow_coolify_unverified','Coolify response not verified.',502);
        return $body;
    }
    private static function provider_checks(){
        $s=self::setup();$c=$s['checks']??array();
        return array(
            'coolify_auth'=>($c['coolify']['status']??'')==='verified' && self::token()!=='',
            'cloudflare_token'=>($c['cloudflare']['status']??'')==='verified',
            'cloudflare_zone'=>($c['cloudflare']['zone_access_status']??'')==='verified',
            'cloudflare_dns_read'=>($c['cloudflare']['dns_read_status']??'')==='verified',
            'cloudflare_zone_id_valid'=>preg_match('/^[a-f0-9]{32}$/D',(string)($s['zone_id']??''))===1,
        );
    }
    public static function providers(){
        $p=self::provider_checks();
        return self::reply(array('contract'=>self::VERSION,'checks'=>$p,
            'dns_write_authorized'=>false,
            'coolify_create_authorized'=>false,
            'token_values_exposed'=>false,
            'note'=>'Read access does not certify provider mutation permissions or launch.'));
    }
    private static function host_catalog(){
        if(!is_file(self::CATALOG)||is_link(self::CATALOG)||
            !is_readable(self::CATALOG)||filesize(self::CATALOG)>700000)return null;
        $c=json_decode((string)file_get_contents(self::CATALOG),true);
        if(!is_array($c)||($c['contract']??'')!=='mad4b.content-hub.hosted-trial-discovery.v1' ||
           ($c['distribution_status']??'')!=='NOT_AUTHORIZED_FOR_TENANT_PUBLICATION' ||
           ($c['source_host']??'')!=='tours.dev.mad4b.com' ||
           !is_array($c['items']??null)||count($c['items'])>300)return null;
        $digest=hash('sha256',wp_json_encode($c['items'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        if(!hash_equals((string)($c['catalog_sha256']??''),$digest))return null;
        return $c;
    }
    public static function hub_catalog(){
        $c=self::host_catalog();
        if(!$c)return self::error('mad4b_workflow_hub_unavailable','Trusted Hub preview not available.',503);
        return self::reply(array('contract'=>$c['contract'],'items'=>$c['items'],
            'languages'=>self::LANG,'currencies'=>self::MONEY,
            'catalog_sha256'=>$c['catalog_sha256'],'source_media_copied'=>false,
            'publication_authorized'=>false,'exchange_rates_supplied'=>false));
    }
    private static function registry($company){
        $r=get_option('mad4b_tourism_company_site_registry_v1',array());
        return is_array($r)?($r[$company]??null):null;
    }
    private static function linked_profile($x){
        $p=self::registry($x['data']['company_code']);
        if(!is_array($p)||empty($p['host_binding_verified']) ||
           ($p['company_code']??'')!==$x['data']['company_code'] ||
           !wp_is_uuid((string)($p['native_site_profile_uuid']??'')) ||
           !wp_is_uuid((string)($p['company_site_uuid']??'')) ||
           !preg_match('/^[a-f0-9]{64}$/D',(string)($p['native_profile_digest']??'')) )
            return null;
        // Existing sites must NOT be adopted as new resources by matching domain
        // or name alone; the request needs an independently enrolled exact identity.
        $registered=(string)($x['record']['target_site_uuid']??'');
        if($registered!=='' && $registered!==$p['company_site_uuid'])return null;
        return $p;
    }
    private static function source_checks(){
        // This is an automatically measured source integrity contract, NOT
        // a release certificate or a second owner approval.
        $proof=self::proof();
        $f=self::INTEGRITY;
        $m=null;
        if(is_file($f)&&!is_link($f)&&is_readable($f)&&filesize($f)<12000 &&
           fileowner($f)===0 && (fileperms($f)&0022)===0){
            $m=json_decode((string)file_get_contents($f),true);
        }
        $ok=is_array($m) &&
            ($m['contract']??'')==='mad4b.tourism-source-integrity.v1' &&
            ($m['release_id']??'')===($proof['release_id']??null) &&
            ($m['source_sha256']??'')===($proof['source_sha256']??null) &&
            !empty($proof['code_only']) && !empty($m['code_only']) &&
            empty($m['includes_wp_config']) && empty($m['includes_uploads']) &&
            preg_match('/^[a-f0-9]{64}$/D',(string)($m['source_sha256']??''))===1 &&
            preg_match('/^sha256:[a-f0-9]{64}$/D',(string)($m['image_digest']??''))===1 &&
            ($m['file_count']??null)===($proof['file_count']??null);
        $settings=self::setup();
        return array(
            'source_integrity_verified'=>$ok,
            'code_only_no_uploads'=>$ok,
            'source_release_admitted'=>$ok,
            'source_certificate_required'=>false,
            'separate_owner_approval_required'=>false,
            'privacy_policy_published'=>get_privacy_policy_url()!=='',
            'license_review_recorded'=>!empty($settings['license_evidence']),
            'license_certification_external'=>false,
            'image_registry_distribution_certified'=>false,
            'tenant_database_blueprint_certified'=>false
        );
    }
    public static function technical_source_status(){
        $s=self::source_checks();
        return array(
            'source_integrity_verified'=>!empty($s['source_integrity_verified']),
            'code_only_no_uploads'=>!empty($s['code_only_no_uploads']),
            'separate_source_certificate_required'=>false,
            'separate_owner_approval_required'=>false
        );
    }
    public static function source_plan($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        $p=self::proof();$checks=self::source_checks();
        $technical=$checks['source_integrity_verified'] &&
            $checks['code_only_no_uploads'];
        return self::reply(array('request_id'=>$x['id'],
            'release_id'=>$p['release_id']??null,
            'release_sha256'=>$p['source_sha256']??null,
            'origin'=>'tours.dev.mad4b.com',
            'checks'=>$checks,
            'blockers'=>$technical?array():array('source_integrity_verified'),
            'advisory_only'=>array('licenses_are_checked_before_premium_activation',
                'fresh_tenant_database_must_be_verified_by_vps_worker'),
            'new_tenant_admitted'=>$technical,
            'source_certificate_required'=>false,
            'owner_second_approval_required'=>false,
            'source_uploads_included'=>false,'github_dependency'=>false));
    }
    private static function coolify_intent($x){
        $p=self::proof();
        $source=self::source_checks();
        $providers=self::provider_checks();
        $state=$x['record']['state']??'';
        $targetEnvironment=self::target_environment_uuid($x);
        // A successfully registered request can proceed directly. The old
        // owner_approved state remains readable for backward compatibility.
        $checks=array(
            'application_received'=>in_array($state,array('intake_received',
                'owner_approved'),true),
            'source_integrity_verified'=>$source['source_integrity_verified'],
            'code_only_no_uploads'=>$source['code_only_no_uploads'],
            'coolify_authenticated'=>$providers['coolify_auth'],
            'image_release_tag_valid'=>preg_match('/^wplab-code-[a-f0-9]{20}$/D',
                (string)($p['release_id']??''))===1,
            'audit_storage_ready'=>class_exists('MAD4B_SCP_Audit',false)
                && !empty(MAD4B_SCP_Audit::storage_status()['ready']),
            'isolated_company_code'=>preg_match('/^[a-z][a-z0-9-]{2,47}$/D',
                $x['data']['company_code'])===1,
            'required_company_data'=>!empty($x['data']['brand_name']) &&
                is_email((string)($x['data']['email']??'')) &&
                preg_match('/^\+[1-9][0-9]{7,14}$/D',(string)($x['data']['phone_e164']??''))===1,
            'no_existing_enrolled_company'=>self::registry($x['data']['company_code'])===null,
            'original_reserved_site_uuid'=>wp_is_uuid((string)($x['record']['reserved_target_site_uuid']??'')),
            'no_manager_service_reservation'=>class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
              && MAD4B_Company_Portfolio_Manager_V1::coolify_service_reservation($x['data']['company_code'])===false,
        );
        $plan=array('contract'=>self::VERSION,'application_id'=>$x['id'],
            'company_code'=>$x['data']['company_code'],
            'project_uuid'=>self::PROJECT,'environment_uuid'=>$targetEnvironment,
            'server_uuid'=>self::SERVER,'resource_type'=>'private_compose_service',
            'reserved_target_site_uuid'=>$x['record']['reserved_target_site_uuid']??null,
            'service_roles'=>array('nginx','php','db'),
            'redis_mode'=>'central_managed_shared_service',
            'image_release_id'=>$p['release_id']??null,
            'source_sha256'=>$p['source_sha256']??null,
            'autogenerate_domain'=>false,'instant_deploy'=>false,
            'database_created'=>false,'db_isolation_required'=>true,
            'no_public_deployment'=>true,
            'source_certificate_required'=>false,
            'separate_owner_approval_required'=>false,
            'checks'=>$checks,
            'blockers'=>array_keys(array_filter($checks,static function($v){return !$v;})));
        $plan['eligible_private_reservation']=count($plan['blockers'])===0 &&
            in_array($state,array('intake_received','owner_approved'),true) &&
            empty($x['record']['coolify_resource_uuid']);
        $plan['plan_sha256']=hash_hmac('sha256',wp_json_encode(array(
            $x['id'],$x['record']['version'],$plan['source_sha256'],
            self::PROJECT,$targetEnvironment,self::SERVER,$x['data']['company_code'],(string)($x['record']['reserved_target_site_uuid']??''),
            $plan['eligible_private_reservation'])),wp_salt('auth'));
        return $plan;
    }
    public static function coolify_plan($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        $p=self::coolify_intent($x);
        if($p['checks']['coolify_authenticated']){
            $team=self::read_coolify('/teams/current');
            $p['provider_team_authenticated']=!is_wp_error($team)&&isset($team['id']);
        }else $p['provider_team_authenticated']=false;
        if(!$p['provider_team_authenticated']) {
            $p['blockers'][]='coolify_team_auth_readback';
            $p['eligible_private_reservation']=false;
        }
        return self::reply($p);
    }
    private static function lock($id){
        global $wpdb;
        $key='mad4b:tourism-workflow:'.$id;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$key))===1;
    }
    private static function unlock($id){
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)','mad4b:tourism-workflow:'.$id));
    }
    private static function audit($x,$action,$summary){
        if(!class_exists('MAD4B_SCP_Audit') ||
            empty(MAD4B_SCP_Audit::storage_status()['ready']))
            return self::error('mad4b_workflow_audit_unavailable','Governed audit storage unavailable.',503);
        $a=MAD4B_SCP_Audit::record('mad4b/tourism-workflow-'.$action,array_merge(
            array('application_id'=>$x['id'],'company_code'=>$x['data']['company_code'],
                  'actor_id'=>get_current_user_id(),'contract'=>self::VERSION),
            $summary),'ok');
        return is_wp_error($a)?$a:true;
    }
    private static function mutate($x,$next,$action,$summary){
        if(!self::lock($x['id']))return self::error('mad4b_workflow_locked','Another workflow job is running.',423);
        try {
            $current=get_post_meta($x['id'],self::META,true);
            if($current!==$x['record'])return self::error('mad4b_workflow_revision_conflict','Refresh request before mutating.',409);
            $receipt=self::audit($x,$action,$summary);
            if(is_wp_error($receipt))return $receipt;
            $next['version']=(int)$current['version']+1;
            $next['updated_at']=gmdate('c');
            if(!update_post_meta($x['id'],self::META,$next,$current))
                return self::error('mad4b_workflow_cas_failed','Concurrent update; refresh status.',409);
            $events=get_post_meta($x['id'],self::JOURNAL,true);
            if(!is_array($events))$events=array();
            $events[]=array('event'=>$action,'version'=>$next['version'],
                'at'=>$next['updated_at'],'actor_id'=>get_current_user_id(),
                'summary'=>$summary);
            if(count($events)>150)$events=array_slice($events,-150);
            update_post_meta($x['id'],self::JOURNAL,$events);
            return $next;
        }finally{self::unlock($x['id']);}
    }
    public static function events($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        $history=get_post_meta($x['id'],self::JOURNAL,true);
        return self::reply(array('application_id'=>$x['id'],
            'events'=>is_array($history)?$history:array(),
            'authoritative_audit'=>'MAD4B_SCP_Audit',
            'no_secret_values'=>true));
    }
    public static function workflow($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        $source=self::source_checks();$provider=self::provider_checks();
        $p=self::linked_profile($x);
        $cat=self::host_catalog();
        $w=$x['record'];
        $selected=$w['hub_selection']??null;
        return self::reply(array(
            'contract'=>self::VERSION,'request_id'=>$x['id'],
            'state'=>$w['state'],'revision'=>$w['version'],
            'company_code'=>$x['data']['company_code'],
            'source_certified'=>!in_array(false,$source,true),
            'source_blockers'=>array_keys(array_filter($source,static function($v){return !$v;})),
            'provider_checks'=>$provider,
            'coolify_resource_uuid'=>$w['coolify_resource_uuid']??null,
            'site_profile_registry_verified'=>$p!==null,
            'company_site_uuid'=>$p['company_site_uuid']??null,
            'native_site_profile_uuid'=>$p['native_site_profile_uuid']??null,
            'content_hub_available'=>$cat!==null,
            'content_selection_saved'=>is_array($selected),
            'cloudflare_stage'=>'managed_by_existing_mad4b_company_domains_adapter',
            'public_live'=>!empty($w['public_live']),
            'commercial_approved'=>false,
            'workflow_routes'=>array('source/plan','coolify/plan','coolify/reserve',
                'coolify/readback','bootstrap/plan','bootstrap/queue','bootstrap/readback',
                'site-profile','site-profile/verify','hub/selection',
                'cloudflare/plan','cloudflare/zone-enroll','cloudflare/commit','cloudflare/readback',
                'release/plan','release/approve','release/commit','events'),
            'next_action'=>$w['state']==='intake_received'?'owner_review':
              (!in_array(false,$source,true)?'coolify_private_plan':'source_certification'),
            'runtime_depends_on_github'=>false
        ));
    }
    /** Private, non-deployed reservation only. Runtime configuration belongs to the native host executor. */
    public static function private_service_compose(string $company,string $site_uuid,string $tag) {
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
           || !wp_is_uuid($site_uuid) || !preg_match('/^[a-f0-9]{20}$/D',$tag))
            return new WP_Error('mad4b_private_service_scope_invalid');
        $name='mad4b-'.$company.'-'.substr($site_uuid,0,8);
        $image='mad4b/tourism-wplab-source:'.$tag;
        return sprintf(<<<'YAML'
name: %s
services:
  nginx:
    image: nginx:1.28-alpine
    depends_on:
      - php
    expose:
      - "8080"
    networks:
      - private
  php:
    image: %s
    expose:
      - "9000"
    networks:
      - private
  db:
    image: mariadb:11.8.9
    volumes:
      - tenant_db:/var/lib/mysql
    networks:
      - private
networks:
  private: {}
volumes:
  tenant_db: {}

YAML
        ,$name,$image);
    }
    public static function coolify_reserve($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        $plan=self::coolify_intent($x);
        if(!$plan['eligible_private_reservation'])
            return self::error('mad4b_workflow_coolify_not_admitted',
                'Source or isolated tenant provisioning contract is not certified.',409);
        $body=(array)$req->get_json_params();
        $company=$x['data']['company_code'];
        if(!is_string($body['expected_plan_sha256']??null) ||
           !hash_equals($plan['plan_sha256'],$body['expected_plan_sha256']))
            return self::error('mad4b_workflow_coolify_plan_mismatch',
                'Fresh plan digest required before execution.',409);
        $key=(string)($body['idempotency_key']??'');
        if(!preg_match('/^[A-Za-z0-9_-]{12,80}$/D',$key))
            return self::error('mad4b_workflow_idempotency_key_invalid',
                'Stable client intent idempotency_key required.',422);
        // All deterministic admission checks occur before recording a
        // company-wide remote-outcome-unknown intent. A local failure must
        // not permanently block the company when no HTTP create was sent.
        $token=self::token();
        if($token==='')
            return self::error('mad4b_workflow_coolify_token_unavailable',
                'Coolify provider credential unavailable before remote create.',503);
        $release=(string)($plan['image_release_id']??'');
        if(!preg_match('/^wplab-code-[a-f0-9]{20}$/D',$release))
            return self::error('mad4b_workflow_source_tag_invalid',
                'Source image release tag invalid before remote create.',409);
        $version=substr($release,strlen('wplab-code-'));
        $site_uuid=(string)($x['record']['reserved_target_site_uuid']??'');
        $compose=self::private_service_compose($company,$site_uuid,$version);
        if(is_wp_error($compose))
            return self::error('mad4b_workflow_service_blueprint_invalid',
                'Compose Service blueprint invalid before remote create.',409);
        if(!class_exists('MAD4B_SCP_Audit',false)
            || empty(MAD4B_SCP_Audit::storage_status()['ready']))
            return self::error('mad4b_workflow_audit_unavailable',
                'Audit storage unavailable before remote create.',503);
        // A valid token may belong to the wrong Coolify team/project.
        // Provider reads must confirm exact project and usable server before
        // submitting ANY create request.
        $team=self::read_coolify('/teams/current');
        $projects=self::read_coolify('/projects');
        $servers=self::read_coolify('/servers');
        if(is_wp_error($team)||is_wp_error($projects)||is_wp_error($servers) ||
           !isset($team['id']) ||
           !is_array($projects)||!is_array($servers)||
           !in_array(self::PROJECT,array_column($projects,'uuid'),true) ||
           !in_array(self::SERVER,array_column($servers,'uuid'),true))
            return self::error('mad4b_workflow_coolify_target_unverified',
                'Coolify team, project or server identity has not been verified.',409);
        $fingerprint=hash('sha256',wp_json_encode(array($plan['plan_sha256'],
            $company,$plan['source_sha256'],self::PROJECT,self::SERVER,self::target_environment_uuid($x))));
        // Provider POST uses an atomic request-identity reservation. The remote
        // result is UNKNOWN from the moment the intent is reserved, so a timeout
        // can never automatically create a duplicate application on retry.
        $intent='mad4b_coolify_intent_'.hash('sha256',$key);
        if(!self::lock($x['id']))
            return self::error('mad4b_workflow_request_busy','Company provisioner busy.',423);
        try {
            $latest=get_post_meta($x['id'],self::META,true);
            if(!is_array($latest) || $latest!==$x['record'] ||
               !in_array(($latest['state']??''),array('intake_received','owner_approved'),true) ||
               !empty($latest['coolify_resource_uuid']) ||
               self::registry($company)!==null) {
                return self::error('mad4b_workflow_reservation_race',
                    'Company request changed; refresh provider plan.',409);
            }
            // An application-specific key alone is insufficient. Reserve
            // exactly one remote-outcome slot across ALL requests for this
            // company before the first provider POST. Never retry unknown.
            $companyIntent='mad4b_coolify_company_intent_v1_'.$company;
            if(!add_option($companyIntent,array(
                'fingerprint'=>$fingerprint,'application_id'=>$x['id'],
                'site_uuid'=>$x['record']['reserved_target_site_uuid'],
                'state'=>'remote_outcome_unknown','resource_type'=>'service',
                'no_automatic_retry'=>true),'',false))
                return self::error('mad4b_workflow_company_service_already_pending',
                    'A company-wide Coolify Service intent exists; reconcile provider before any new request.',409);
            if(!add_option($intent,array(
                'fingerprint'=>$fingerprint,'application_id'=>$x['id'],
                'state'=>'remote_outcome_unknown','owner_id'=>get_current_user_id(),
                'created_at'=>gmdate('c')),'',false)){
                $old=get_option($intent,array());
                return self::error('mad4b_workflow_coolify_reconcile_required',
                    ($old['fingerprint']??'')===$fingerprint?
                     'Provider intent already submitted; use Coolify readback, never retry blind.':
                     'Idempotency key reused with a different request.',409);
            }
            $audit=self::audit($x,'coolify-private-reservation-intent',
                array('intent_sha256'=>hash('sha256',$key),
                    'source_sha256'=>$plan['source_sha256'],
                    'no_public_deploy'=>true));
            if(is_wp_error($audit))return $audit;
            // All source image, credential, audit and Compose validity checks
            // already passed before reserving a global company intent.
            $payload=array(
                'project_uuid'=>self::PROJECT,'environment_uuid'=>self::target_environment_uuid($x),
                'server_uuid'=>self::SERVER,
                'name'=>'mad4b-'.$company.'-'.substr($site_uuid,0,8),
                'description'=>'MAD4B original Broker private Compose Service reservation; never start before native bootstrap and independent readback.',
                'docker_compose_raw'=>base64_encode($compose),
                'connect_to_docker_network'=>false,
                'redact_all_env_values_in_logs'=>true,
                'instant_deploy'=>false,
            );
            $response=wp_safe_remote_request(self::COOLIFY.'/services',array(
                'method'=>'POST','timeout'=>22,'redirection'=>0,
                'headers'=>array('Authorization'=>'Bearer '.$token,
                    'Content-Type'=>'application/json','Accept'=>'application/json'),
                'body'=>wp_json_encode($payload),
                'limit_response_size'=>12000));
            if(is_wp_error($response))
                return self::error('mad4b_workflow_coolify_unknown',
                    'Provider outcome unknown. Manual readback required; do not retry.',502);
            $code=(int)wp_remote_retrieve_response_code($response);
            $result=json_decode((string)wp_remote_retrieve_body($response),true);
            if($code!==201 || !is_array($result) ||
               !preg_match('/^[A-Za-z0-9-]{8,65}$/D',(string)($result['uuid']??'')))
                return self::error('mad4b_workflow_coolify_unverified',
                    'Provider did not return a verified reservation; reconcile before retry.',502);
            $uuid=$result['uuid'];
            $candidate=self::read_coolify('/services/'.$uuid);
            if(is_wp_error($candidate) ||
                ($candidate['uuid']??'')!==$uuid ||
                ($candidate['name']??'')!=='mad4b-'.$company.'-'.substr($site_uuid,0,8) ||
                !in_array((string)($candidate['status']??''),['exited','stopped'],true))
                return self::error('mad4b_workflow_coolify_readback_failed',
                    'Reservation created but readback is not verified. Reconcile provider state.',502);
            $previous=get_post_meta($x['id'],self::META,true);
            if($previous!==$x['record'] ||
               !empty($previous['coolify_resource_uuid']))
                return self::error('mad4b_workflow_coolify_state_conflict',
                    'Reservation exists; request changed. Manually reconcile.',409);
            $origin='https://'.(string)$x['data']['requested_domain'];
            $manager=MAD4B_Company_Portfolio_Manager_V1::record_coolify_service_reservation([
                'company_code'=>$company,'environment'=>(self::target_environment_uuid($x)===self::QA_ENV?'staging':'production'),
                'site_uuid'=>$site_uuid,'canonical_origin'=>$origin,
                'coolify_resource_uuid'=>$uuid,'application_id'=>$x['id']]);
            if(is_wp_error($manager))return self::error('mad4b_workflow_manager_receipt_unconfirmed',
                'Remote Service exists but exact Company Manager receipt was not saved; do not retry create.',503);
            $next=$previous;
            $next['coolify_resource_uuid']=$uuid;
            $next['state']='resource_reserved';
            $next['coolify_resource_type']='service';
            $next['coolify_service_site_uuid']=$site_uuid;
            $next['version']=(int)$previous['version']+1;
            $next['updated_at']=gmdate('c');
            if(!update_post_meta($x['id'],self::META,$next,$previous))
                return self::error('mad4b_workflow_coolify_persist_failed',
                    'Remote resource reserved but local write failed; reconcile manually.',503);
            update_option($intent,array('fingerprint'=>$fingerprint,
                'application_id'=>$x['id'],'state'=>'readback_verified',
                'uuid'=>$uuid,'owner_id'=>get_current_user_id()),false);
            update_option($companyIntent,array(
                'fingerprint'=>$fingerprint,'application_id'=>$x['id'],
                'site_uuid'=>$site_uuid,'state'=>'readback_verified',
                'resource_type'=>'service','uuid'=>$uuid,
                'no_automatic_retry'=>true),false);
            self::audit($x,'coolify-private-reserved',array('resource_uuid'=>$uuid));
            return self::reply(array('state'=>'RESOURCE_RESERVED_PRIVATE',
                'resource_type'=>'service','site_uuid'=>$site_uuid,
                'target_environment_uuid'=>self::target_environment_uuid($x),
                'company_manager_receipt_persisted'=>true,
                'broker_entrypoint'=>'MAD4B_Central_VPS_Broker_V1::reserve_company_service',
                'resource_uuid'=>$uuid,'public_live'=>false,
                'database_bootstrapped'=>false,'site_profile_enrolled'=>false),201);
        } finally {self::unlock($x['id']);}
    }
    public static function coolify_readback($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        $uuid=(string)($x['record']['coolify_resource_uuid']??'');
        if(!preg_match('/^[A-Za-z0-9-]{8,65}$/D',$uuid))
            return self::reply(array('state'=>'NOT_RESERVED','resource_uuid'=>null,
                'provider_status_verified'=>false,'public_live'=>false));
        $legacy=($x['record']['coolify_resource_type']??'application')!=='service';
        $resource=self::read_coolify(($legacy?'/applications/':'/services/').$uuid);
        if(is_wp_error($resource))return $resource;
        $saved=MAD4B_Company_Portfolio_Manager_V1::coolify_service_reservation($x['data']['company_code']);
        $match=($resource['uuid']??'')===$uuid &&
            ($resource['name']??'')==='mad4b-'.$x['data']['company_code'].'-'.substr((string)($x['record']['reserved_target_site_uuid']??''),0,8)
            && is_array($saved)
            && ($saved['coolify_resource_uuid']??'')===$uuid
            && ($saved['site_uuid']??'')===($x['record']['reserved_target_site_uuid']??'')
            && ($saved['application_id']??null)===$x['id'];
        return self::reply(array('state'=>$legacy?'LEGACY_APPLICATION_REQUIRES_COMPOSE_SERVICE_MIGRATION':
                ($match?'RESERVED_MATCHED':'PROVIDER_RESOURCE_MISMATCH'),
            'resource_uuid'=>$uuid,'resource_type'=>'service',
            'site_uuid'=>$x['record']['reserved_target_site_uuid']??null,
            'company_manager_receipt_verified'=>$match,'provider_status_verified'=>$match,
            'provider_runtime_status'=>$resource['status']??'unknown',
            'tenant_bootstrap_verified'=>false,'public_live'=>false));
    }
    public static function bootstrap_plan($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        $src=self::source_checks();$w=$x['record'];
        // Source integrity and licensing evidence are distinct. A missing
        // optional legal attachment cannot invalidate a technically valid code
        // artifact; runtime image, DB isolation and worker attestation are hard
        // requirements for actually starting a new tenant.
        $checks=array(
            'resource_reserved'=>!empty($w['coolify_resource_uuid']),
            'source_admitted'=>!empty($src['source_integrity_verified'])
                && !empty($src['code_only_no_uploads'])
                && !empty($src['source_release_admitted']),
            'image_registry_distribution_certified'=>
                !empty($src['image_registry_distribution_certified']),
            'tenant_database_blueprint_certified'=>
                !empty($src['tenant_database_blueprint_certified']),
            'runtime_worker_attested'=>false,
            'new_empty_tenant_db_required'=>true,
            'no_imports_from_demo'=>true,
            'native_site_agent_required'=>true,
            'hosting_writes_still_disabled'=>true);
        return self::reply(array('contract'=>self::VERSION,
            'state'=>!empty($w['coolify_resource_uuid'])?'RESOURCE_RESERVED':'NO_RESOURCE',
            'company_code'=>$x['data']['company_code'],
            'resource_uuid'=>$w['coolify_resource_uuid']??null,
            'requested_origin'=>'https://'.$x['data']['default_bootstrap_domain'],
            'bootstrap_image_source'=>'pinned_tours_dev_container_artifact',
            'requirements'=>$checks,
            'optional_evidence'=>array(
                'privacy_policy_published'=>!empty($src['privacy_policy_published']),
                'license_review_recorded'=>!empty($src['license_review_recorded']),
                'license_certification_external'=>!empty($src['license_certification_external'])),
            'blockers'=>array_keys(array_filter($checks,static function($value){return $value===false;})),
            'wp_cli_worker'=>'host_authorized_whitelisted_jobs_only',
            'bootstrap_job_eligible'=>false,
            'database_cloned'=>false,'public_live'=>false));
    }
    public static function bootstrap_queue($req){
        // Only the exact private QA fixture can enter the native clone queue.
        // The separately authorized VPS worker must read the immutable job and
        // validate local artifacts before any filesystem or database action.
        $x=self::current($req);if(is_wp_error($x))return $x;
        if(!class_exists('MAD4B_Generic_Native_Clone_V1',false))
            return self::error('mad4b_native_clone_worker_unavailable','Native clone module missing.',503);
        $company=(string)$x['data']['company_code'];
        $env=(string)($x['record']['target_environment']??'production');
        $body=$req->get_json_params();
        if(!is_array($body) || array_diff(array_keys($body),[
          'expected_plan_sha256','source_export_sha256']))
            return self::error('mad4b_native_clone_queue_payload_invalid','Exact queue evidence required.',422);
        $result=MAD4B_Generic_Native_Clone_V1::queue($company,$env,(int)$x['id'],$body);
        $success=($result['state']??'')==='PRIVATE_QA_CLONE_WORKER_QUEUED';
        return self::reply($result,$success?202:409);
    }
    public static function bootstrap_readback($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        $p=self::linked_profile($x);
        $env=(string)($x['record']['target_environment']??'production');
        $job=class_exists('MAD4B_Generic_Native_Clone_V1',false)
          ?MAD4B_Generic_Native_Clone_V1::job_status(
            (string)$x['data']['company_code'],$env,(int)$x['id']):[];
        return self::reply(array('contract'=>self::VERSION,
            'company_code'=>$x['data']['company_code'],
            'resource_uuid'=>$x['record']['coolify_resource_uuid']??null,
            'native_site_profile_registered'=>is_array($p),
            'site_uuid'=>$p['company_site_uuid']??null,
            'native_profile_digest'=>$p['native_profile_digest']??null,
            'native_clone_job_state'=>$job['state']??'UNAVAILABLE',
            'native_clone_job_id'=>$job['job_id']??null,
            'native_clone_job_queued'=>$job['job_queued']??false,
            'private_bootstrap_certified'=>false,
            'database_isolated_verified'=>false,
            'public_live'=>false));
    }
    public static function site_profile($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        $p=self::linked_profile($x);
        return self::reply(array('application_id'=>$x['id'],
            'registered'=>is_array($p),'company_code'=>$x['data']['company_code'],
            'company_site_uuid'=>$p['company_site_uuid']??null,
            'native_site_profile_uuid'=>$p['native_site_profile_uuid']??null,
            'native_profile_revision'=>$p['native_profile_revision']??null,
            'host_binding_verified'=>!empty($p['host_binding_verified']),
            'resource_uuid_bound'=>!empty($x['record']['coolify_resource_uuid']),
            'site_profile_ready'=>is_array($p)&&
                !empty($x['record']['coolify_resource_uuid']) &&
                ($x['record']['target_site_uuid']??'')===($p['company_site_uuid']??''),
            'next'=>'Use native Site Profile v2 enrollment and independently audited central registry readback.',
            'public_live'=>false));
    }
    public static function site_profile_verify($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        if(!in_array($x['record']['state'],array('resource_reserved','profile_pending'),true) ||
           empty($x['record']['coolify_resource_uuid']))
            return self::error('mad4b_workflow_site_profile_resource_required',
                'A reserved company-specific Coolify resource is required first.',409);
        $p=self::linked_profile($x);
        if(!$p || ($p['provider_resource_uuid']??'')!==$x['record']['coolify_resource_uuid']
           || ($p['company_site_uuid']??'')!==($x['record']['reserved_target_site_uuid']??''))
            return self::error('mad4b_workflow_profile_registry_unverified',
                'No independently audited site-to-Coolify registry binding.',409);
        $body=(array)$req->get_json_params();
        if(($body['confirmation']??'')!=='VERIFY NATIVE PROFILE '.$x['data']['company_code'] ||
           ($body['expected_native_profile_digest']??'')!==$p['native_profile_digest'])
            return self::error('mad4b_workflow_profile_owner_mismatch',
                'Exact readback digest and owner confirmation required.',409);
        $next=$x['record'];
        $next['state']='tenant_enrolled';
        $next['target_site_uuid']=$p['company_site_uuid'];
        $next['native_profile_digest']=$p['native_profile_digest'];
        $result=self::mutate($x,$next,'native-site-profile-verified',array(
            'native_site_profile_uuid'=>$p['native_site_profile_uuid'],
            'native_revision'=>$p['native_profile_revision'],
            'binding_verified'=>true));
        if(is_wp_error($result))return $result;
        return self::reply(array('state'=>'NATIVE_SITE_PROFILE_VERIFIED',
            'site_uuid'=>$p['company_site_uuid'],'public_live'=>false));
    }
    public static function hub_selection($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        $value=$x['record']['hub_selection']??null;
        $c=self::host_catalog();
        return self::reply(array('selection'=>$value,'catalog_available'=>$c!==null,
            'catalog_sha256'=>$c['catalog_sha256']??null,
            'remote_content_published'=>false,'source_media_copied'=>false));
    }
    public static function hub_save($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        if(!in_array($x['record']['state'],array('tenant_enrolled','content_trial'),true) ||
           empty($x['record']['native_profile_digest']))
            return self::error('mad4b_workflow_hub_requires_site_profile',
                'Native Site Profile must be verified before choosing hosted content.',409);
        $c=self::host_catalog();
        if(!$c)return self::error('mad4b_workflow_hub_source_unavailable','Trusted content source unavailable.',503);
        $input=(array)$req->get_json_params();
        $t=$input['tours']??null;$l=$input['languages']??null;$m=$input['currencies']??null;
        if(!is_array($t)||!is_array($l)||!is_array($m)||
            count($t)<1||count($t)>12||count($l)<1||count($l)>6||
            count($m)<1||count($m)>6||count(array_unique($t))!==count($t)||
            count(array_unique($l))!==count($l)||count(array_unique($m))!==count($m))
            return self::error('mad4b_workflow_hub_selection_invalid',
                'Choose 1–12 tours and at least one language and currency without duplicates.',422);
        $refs=array_column($c['items'],'hub_ref');
        if(count(array_diff($t,$refs))||count(array_diff($l,self::LANG))||
            count(array_diff($m,self::MONEY)))
            return self::error('mad4b_workflow_hub_selection_not_in_catalog',
                'Tour, language or currency not in signed source catalog.',422);
        $next=$x['record'];
        $next['state']='content_trial';
        $next['hub_selection']=array(
            'contract'=>'mad4b.hosted-content-company-selection.v1',
            'catalog_sha256'=>$c['catalog_sha256'],
            'tours'=>array_values($t),'languages'=>array_values($l),
            'currencies'=>array_values($m),
            'source_media_copied'=>false,'post_imported'=>false,
            'content_publication_approved'=>false,
            'fx_rates_available'=>false,'booking_enabled'=>false);
        $saved=self::mutate($x,$next,'hosted-hub-selection-saved',array(
            'tour_count'=>count($t),'languages'=>$l,'currencies'=>$m,
            'catalog_sha256'=>$c['catalog_sha256']));
        if(is_wp_error($saved))return $saved;
        return self::reply(array('state'=>'HOSTED_CONTENT_SELECTED',
            'selection'=>$next['hub_selection'],'public_live'=>false));
    }
    private static function domain_input($x){
        $d=$x['data'];
        $mode=$d['domain_mode']??'subdomain';
        $host=($mode==='custom_domain')?($d['requested_domain']??''):
            ($d['company_code'].'.mad4b.com');
        return array('company_code'=>$d['company_code'],
            'mode'=>$mode,'hostname'=>$host);
    }
    private static function adapter($method,$x,$body=array()){
        if(!class_exists('MAD4B_SCP_Company_Domain_Cloudflare'))
            return self::error('mad4b_workflow_cloudflare_adapter_missing',
                'Original MAD4B DNS adapter is unavailable.',503);
        $request=new WP_REST_Request($method==='commit'?'POST':'GET',
            '/mad4b/v1/company-domains/'.($method==='commit'?'dns/commit':$method));
        $fields=array_merge(self::domain_input($x),$body);
        foreach($fields as $key=>$val)$request->set_param($key,$val);
        if($method==='commit'){
            $request->set_body(wp_json_encode($fields));
            $request->set_header('Content-Type','application/json');
        }
        if($method==='plan')return MAD4B_SCP_Company_Domain_Cloudflare::plan($request);
        if($method==='readback')return MAD4B_SCP_Company_Domain_Cloudflare::readback($request);
        return MAD4B_SCP_Company_Domain_Cloudflare::commit($request);
    }
    public static function cloudflare_plan($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        if(!in_array($x['record']['state'],array('content_trial','domain_pending','launch_review'),true))
            return self::reply(array('state'=>'BLOCKED',
                'blockers'=>array('native_profile_and_hub_selection_required'),
                'dns_authorized'=>false));
        $r=self::adapter('plan',$x);
        return $r;
    }
    public static function cloudflare_zone_enroll($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        $d=self::domain_input($x);
        if($d['mode']!=='custom_domain' ||
           !in_array($x['record']['state'],array('content_trial','domain_pending'),true) ||
           empty($x['record']['coolify_resource_uuid']) ||
           empty($x['record']['native_profile_digest']) ||
           !is_array($x['record']['hub_selection']??null))
            return self::error('mad4b_workflow_custom_zone_stage_denied',
                'Owner-approved company, resource, Site Profile and custom-domain intent required.',409);
        $entry=self::linked_profile($x);
        if(!$entry ||
            ($entry['provider_resource_uuid']??null)!==$x['record']['coolify_resource_uuid'] ||
            !in_array($d['hostname'],(array)($entry['approved_custom_domains']??array()),true))
            return self::error('mad4b_workflow_custom_zone_ownership_denied',
                'Custom-domain ownership not independently enrolled for this company.',403);
        $body=(array)$req->get_json_params();
        foreach($d as $field=>$v){
            if(isset($body[$field]) && $body[$field]!==$v)
                return self::error('mad4b_workflow_custom_zone_scope_mismatch',
                    'Submitted domain differs from the enrolled company.',403);
        }
        $args=array_merge($body,$d);
        $forward=new WP_REST_Request('POST','/mad4b/v1/company-domains/zones/enroll');
        $forward->set_body(wp_json_encode($args));
        $forward->set_header('Content-Type','application/json');
        return MAD4B_SCP_Company_Domain_Cloudflare::enroll($forward);
    }
    public static function cloudflare_commit($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        $state=$x['record']['state'];
        if(!in_array($state,array('content_trial','domain_pending'),true) ||
           empty($x['record']['coolify_resource_uuid']) ||
           empty($x['record']['native_profile_digest']) ||
           !is_array($x['record']['hub_selection']??null))
            return self::error('mad4b_workflow_domain_sequence_required',
                'Private provider resource, Site Profile and hosted content must be verified first.',409);
        $company=self::linked_profile($x);
        if(!$company || ($company['provider_resource_uuid']??null)!==$x['record']['coolify_resource_uuid'])
            return self::error('mad4b_workflow_domain_registry_unbound',
                'Company does not have an approved production provider identity.',409);
        // Underlying adapter requires a second, exact DNS plan and idempotency
        // key, independently scoped company approval, and owner browser session.
        $body=(array)$req->get_json_params();
        foreach(array('company_code','hostname','mode') as $f){
            if(isset($body[$f]) && $body[$f]!==self::domain_input($x)[$f])
                return self::error('mad4b_workflow_domain_scope_mismatch',
                    'Domain arguments must match the company application.',403);
        }
        $out=self::adapter('commit',$x,$body);
        if(is_wp_error($out))return $out;
        // DNS commit result is not launch proof. A later readback verifies TLS.
        return $out;
    }
    public static function cloudflare_readback($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        if(!in_array($x['record']['state'],array('content_trial','domain_pending','launch_review'),true))
            return self::reply(array('state'=>'BLOCKED',
                'blockers'=>array('company_profile_and_content_not_prepared'),
                'public_tls_ready'=>false,'public_live'=>false));
        return self::adapter('readback',$x);
    }
    private static function company_content_grant($x,$registry){
        // A company-registry flag alone is insufficient. Publication is
        // authorized only when a separately controlled, read-only Hub grant
        // matches this exact tenant, current catalog and selected tours.
        $f=self::HUB_GRANTS;
        if(!is_file($f)||is_link($f)||!is_readable($f)||filesize($f)>16000 ||
           fileowner($f)!==0 || (fileperms($f)&0022)!==0 ||
           !is_array($registry)) return false;
        $data=json_decode((string)file_get_contents($f),true);
        $code=$x['data']['company_code'];
        $entry=is_array($data)?($data['companies'][$code]??null):null;
        $selection=$x['record']['hub_selection']??null;
        $c=self::host_catalog();
        return is_array($entry) && is_array($selection) && is_array($c) &&
            ($data['contract']??'')==='mad4b.content-hub-distribution-grants.v1' &&
            !empty($registry['content_distribution_approved']) &&
            ($entry['company_code']??'')===$code &&
            ($entry['company_site_uuid']??'')===($registry['company_site_uuid']??null) &&
            ($entry['native_profile_digest']??'')===($registry['native_profile_digest']??null) &&
            ($entry['catalog_sha256']??'')===$c['catalog_sha256'] &&
            ($entry['catalog_sha256']??'')===($selection['catalog_sha256']??'') &&
            ($entry['tours']??null)===$selection['tours'] &&
            !empty($entry['distribution_approved']) &&
            preg_match('/^[a-f0-9]{64}$/D',(string)($entry['audit_receipt_sha256']??''))===1 &&
            ($entry['audit_receipt_sha256']??'')===($registry['signed_publication_receipt_sha256']??null) &&
            !empty($entry['expires_at']) && strtotime((string)$entry['expires_at'])>time();
    }
    private static function release_checks($x,$mode){
        $s=self::setup();$src=self::source_checks();
        $providers=self::provider_checks();
        $p=self::linked_profile($x);
        $record=$x['record'];
        $company=$x['data'];
        $selected=$record['hub_selection']??null;
        $profile_ok=is_array($p) &&
            !empty($record['target_site_uuid']) &&
            ($record['target_site_uuid']===$p['company_site_uuid']) &&
            ($record['native_profile_digest']??'')===($p['native_profile_digest']??'');
        $domain=self::domain_input($x);
        $host=$domain['hostname'];
        $tls=false;
        $coolify_public_route=false;
        $admin_https_ready=false;
        $login_https_ready=false;
        // A public company origin never includes Docker's internal service
        // port (8080 or otherwise). The original Coolify resource, not an
        // arbitrary request parameter, must own this HTTPS router.
        $public=class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
          ? MAD4B_Company_Portfolio_Manager_V1::public_origin('https://'.$host):'';
        $validPublic=($public!=='' && $public==='https://'.$host);
        $uuid=(string)($record['coolify_resource_uuid']??'');
        if($profile_ok && $validPublic &&
           preg_match('/^[A-Za-z0-9-]{8,65}$/D',$uuid)) {
            $legacy=($record['coolify_resource_type']??'application')!=='service';
            $resource=self::read_coolify(($legacy?'/applications/':'/services/').$uuid);
            if(is_array($resource)
                &&($resource['uuid']??'')===$uuid
                &&($resource['name']??'')==='mad4b-'.$company['company_code'].'-'.substr((string)($record['reserved_target_site_uuid']??''),0,8)) {
                $registered=$resource['domains']??'';
                $entries=is_array($registered)?$registered:explode(',',(string)$registered);
                $entries=array_filter(array_map('trim',array_filter($entries,'is_string')));
                $status=(string)($resource['status']??'');
                $coolify_public_route=in_array($public,$entries,true)
                    && str_starts_with($status,'running')
                    && !str_contains($status,'unhealthy');
            }
        }
        if($coolify_public_route) {
            // DNS resolution alone is not proof: homepage must return HTTPS
            // 200 on the exact public origin, without cross-host redirects.
            $r=wp_safe_remote_get($public.'/',array(
                'timeout'=>6,'redirection'=>0,'limit_response_size'=>256));
            $tls=!is_wp_error($r)&& (int)wp_remote_retrieve_response_code($r)===200;
            if($tls) {
                $login=wp_safe_remote_get($public.'/wp-login.php',array(
                    'timeout'=>5,'redirection'=>0,'limit_response_size'=>256));
                $login_https_ready=!is_wp_error($login)
                    &&(int)wp_remote_retrieve_response_code($login)===200;
                $admin=wp_safe_remote_get($public.'/wp-admin/',array(
                    'timeout'=>5,'redirection'=>0,'limit_response_size'=>256));
                $redirect=!is_wp_error($admin)
                  ?wp_remote_retrieve_header($admin,'location'):'';
                $parts=is_string($redirect)?wp_parse_url($redirect):false;
                $admin_https_ready=!is_wp_error($admin)
                  && (int)wp_remote_retrieve_response_code($admin)===302
                  && is_array($parts)
                  && ($parts['scheme']??'')==='https'
                  && ($parts['host']??'')===$host
                  && !isset($parts['port'])
                  && ($parts['path']??'')==='/wp-login.php';
            }
        }
        $checks=array(
            'company_application_registered'=>in_array($record['state'],self::STATES,true),
            'source_release_accepted'=>$src['source_release_admitted'],
            'premium_licenses_certified'=>$src['license_certification_external'],
            'private_coolify_resource_bound'=>!empty($record['coolify_resource_uuid']),
            'coolify_managed_public_domain_bound'=>$coolify_public_route,
            'public_https_wp_admin_redirect_verified'=>$admin_https_ready,
            'public_https_wp_login_verified'=>$login_https_ready,
            'native_site_profile_bound'=>$profile_ok,
            'hosted_tours_selected'=>is_array($selected) && count($selected['tours']??array())>0,
            'remote_content_publication_approved'=>self::company_content_grant($x,$p),
            'cloudflare_zone_authorized'=>$providers['cloudflare_zone']&&$providers['cloudflare_dns_read'],
            'trusted_tls_200'=>$tls,
            'privacy_notice_published'=>$src['privacy_policy_published'],
            'verified_company_email'=>!empty($company['email_verified']),
            'verified_company_phone'=>!empty($company['phone_verified']),
            'company_policy_approved'=>!empty($company['company_terms_approved']),
            'commercial_payment_certified'=>!empty($s['commercial_booking_approved']),
        );
        if($mode==='public_demo'){
            // Payment certification is not required for a no-checkout demo,
            // but consent, source licensing and the company content grant are.
            unset($checks['commercial_payment_certified']);
            unset($checks['company_policy_approved']);
        }
        return $checks;
    }
    public static function release_plan($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        $mode=(string)($req->get_param('mode')??'public_demo');
        if(!in_array($mode,array('public_demo','commercial'),true))
            return self::error('mad4b_workflow_release_mode_invalid','Choose public_demo or commercial.',422);
        $checks=self::release_checks($x,$mode);
        $blockers=array_keys(array_filter($checks,static function($v){return !$v;}));
        $data=array('contract'=>self::VERSION,
            'application_id'=>$x['id'],'company_code'=>$x['data']['company_code'],
            'release_mode'=>$mode,'request_version'=>$x['record']['version'],
            'checks'=>$checks,'blockers'=>$blockers,
            'eligible_owner_approval'=>count($blockers)===0,
            'public_live'=>!empty($x['record']['public_live']),
            'commercial_checkout_enabled'=>false,
            'separate_release_commit_required'=>true);
        $data['plan_sha256']=hash_hmac('sha256',wp_json_encode(array(
            $x['id'],$x['record']['version'],$x['data']['company_code'],
            $mode,$checks,$blockers)),wp_salt('auth'));
        return self::reply($data);
    }
    public static function release_commit($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        if($x['record']['state']!=='launch_review' ||
           empty($x['record']['release_approval']) ||
           !empty($x['record']['public_live']))
            return self::error('mad4b_workflow_release_not_approved',
                'An exact, unpublished owner-reviewed launch is required.',409);
        $body=(array)$req->get_json_params();
        $mode=(string)($x['record']['release_approval']['mode']??'');
        if(($body['mode']??'')!==$mode ||
           ($body['confirmation']??'')!=='GO LIVE '.$mode.' FOR '.$x['data']['company_code'])
            return self::error('mad4b_workflow_launch_confirmation_mismatch',
                'Exact owner launch confirmation required.',409);
        $now=self::release_checks($x,$mode);
        if(in_array(false,$now,true))
            return self::error('mad4b_workflow_launch_gates_changed',
                'Mandatory source, domain, TLS, legal or payment certification is incomplete.',409);
        if(($x['record']['release_approval']['actor_id']??0)<=0)
            return self::error('mad4b_workflow_launch_missing_owner',
                'No authoritative owner approval receipt.',409);
        // Mark live only AFTER verified public HTTPS and licensed content
        // gates, never on a successful provider POST alone.
        $next=$x['record'];
        $next['public_live']=true;
        $next['state']='launched';
        $next['launched_at']=gmdate('c');
        $next['launch_mode']=$mode;
        $saved=self::mutate($x,$next,'owner-public-launch-certified',array(
            'mode'=>$mode,'tls_verified'=>true,'commercial'=>$mode==='commercial'));
        if(is_wp_error($saved))return $saved;
        return self::reply(array('state'=>'LAUNCHED_VERIFIED',
            'company_code'=>$x['data']['company_code'],'mode'=>$mode,
            'public_live'=>true,'external_provider_mutated'=>false));
    }
    public static function release_approve($req){
        $x=self::current($req);if(is_wp_error($x))return $x;
        $body=(array)$req->get_json_params();
        $mode=(string)($body['mode']??'public_demo');
        if(!in_array($mode,array('public_demo','commercial'),true))
            return self::error('mad4b_workflow_release_invalid','Invalid release mode.',422);
        $planReq=new WP_REST_Request('GET','/mad4b/v1/tourism-launch/applications/'.$x['id'].'/release/plan');
        $planReq->set_param('id',$x['id']);$planReq->set_param('mode',$mode);
        $plan=self::release_plan($planReq);
        if(is_wp_error($plan))return $plan;
        $p=$plan->get_data();
        if(!$p['eligible_owner_approval'])
            return self::error('mad4b_workflow_release_not_certified',
                'Commercial or public-demo readiness gates are not fulfilled.',409);
        if(($body['confirmation']??'')!=='APPROVE '.$mode.' FOR '.$x['data']['company_code'] ||
            !is_string($body['expected_plan_sha256']??null) ||
            !hash_equals($p['plan_sha256'],$body['expected_plan_sha256']))
            return self::error('mad4b_workflow_release_owner_mismatch',
                'Owner must confirm the exact release plan.',409);
        $next=$x['record'];$next['state']='launch_review';
        $next['release_approval']=array('mode'=>$mode,'actor_id'=>get_current_user_id(),
            'plan_sha256'=>$p['plan_sha256'],'approved_at'=>gmdate('c'),
            'requires_separate_public_activation'=>true);
        $saved=self::mutate($x,$next,'owner-release-plan-approved',array(
            'mode'=>$mode,'no_public_activation'=>true));
        if(is_wp_error($saved))return $saved;
        return self::reply(array('state'=>'RELEASE_APPROVED_PENDING_EXECUTION',
            'mode'=>$mode,'public_live'=>false,
            'payment_activated'=>false,'requires_separate_public_activation'=>true));
    }
}
require_once __DIR__.'/../services/342-mad4b-generic-native-clone.php';
MAD4B_Tourism_Workflow_REST::boot();
