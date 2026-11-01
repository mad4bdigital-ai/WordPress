<?php
/**
 * MAD4B Control Plane — Tourism Company Launch v1.
 * Additive intake + owner-governed Coolify handoff.
 * External DNS uses the existing company-domain Cloudflare module.
 * PHP 7.4; does not make chat recommendations into execution authority.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Tourism_Company_Launch {
    const NS = 'mad4b/v1';
    const ROOT = '/tourism-company';
    const TYPE = 'mad4b_tourism_application';
    const VERSION = 'mad4b.tourism-company-launch.v1';
    const DEMO_COLORS = array('primary'=>'#173A4C','secondary'=>'#235E6D','accent'=>'#BB9253');
    private static $booted = false;

    public static function boot() {
        if (self::$booted) return;
        self::$booted=true;
        add_action('init', array(__CLASS__,'register_type'), 8);
        add_action('rest_api_init',array(__CLASS__,'routes'),42);
        add_shortcode('mad4b_start_tourism_company',array(__CLASS__,'shortcode'));
    }
    public static function register_type() {
        register_post_type(self::TYPE,array(
            'public'=>false,'show_ui'=>false,'show_in_rest'=>false,
            'exclude_from_search'=>true,'supports'=>array('title'),
            'capability_type'=>'post','map_meta_cap'=>true,
        ));
    }
    private static function error($code,$message,$status=422) {
        return new WP_Error($code,$message,array('status'=>$status));
    }
    private static function reply($data,$code=200) {
        if (is_wp_error($data)) return $data;
        $r=new WP_REST_Response($data,$code);
        $r->header('Cache-Control','private, no-store, max-age=0');
        return $r;
    }
    public static function routes() {
        $routes=array(
          '/schema'=>array('GET','schema','public_read'),
          '/applications'=>array('POST','intake','public_intake'),
          '/requests/(?P<id>[1-9][0-9]{0,9})'=>array('GET','status','owner_read'),
          '/requests/(?P<id>[1-9][0-9]{0,9})/plan'=>array('GET','plan','owner_read'),
          '/requests/(?P<id>[1-9][0-9]{0,9})/approve'=>array('POST','approve','owner_write'),
          '/requests/(?P<id>[1-9][0-9]{0,9})/coolify/commit'=>array('POST','coolify_commit','owner_write'),
          '/requests/(?P<id>[1-9][0-9]{0,9})/coolify/start'=>array('POST','coolify_start','owner_write'),
          '/requests/(?P<id>[1-9][0-9]{0,9})/coolify/readback'=>array('GET','coolify_readback','owner_read'),
        );
        foreach($routes as $url=>$entry) {
            register_rest_route(self::NS,self::ROOT.$url,array(
                'methods'=>$entry[0],
                'callback'=>array(__CLASS__,$entry[1]),
                'permission_callback'=>array(__CLASS__,$entry[2]),
            ));
        }
    }
    public static function public_read() { return true; }
    public static function public_intake() { return true; }
    public static function owner_read() {
        return current_user_can('manage_options') ? true :
            self::error('mad4b_launch_owner_required','Company launch owner authorization required.',403);
    }
    public static function owner_write() {
        if (!current_user_can('manage_options') || !current_user_can('activate_plugins')) {
            return self::error('mad4b_launch_owner_required','Owner administrator permissions required.',403);
        }
        $cookie=defined('LOGGED_IN_COOKIE')?(string)($_COOKIE[LOGGED_IN_COOKIE]??''):'';
        $uid=$cookie!==''?wp_validate_auth_cookie($cookie,'logged_in'):false;
        $session=function_exists('wp_get_session_token')?(string)wp_get_session_token():'';
        $nonce=(string)($_SERVER['HTTP_X_WP_NONCE']??'');
        if (!$uid || (int)$uid!==get_current_user_id() || $session==='' ||
            $nonce==='' || !wp_verify_nonce($nonce,'wp_rest')) {
            return self::error('mad4b_launch_browser_approval_required',
                'Verified owner cookie/session and REST nonce required.',403);
        }
        return true;
    }
    public static function brand_palette($values) {
        if ($values===null || $values===array()) $values=self::DEMO_COLORS;
        if (!is_array($values)) return self::error('mad4b_palette_invalid','Colors must be an object.');
        $out=array();
        foreach(array('primary','secondary','accent') as $name) {
            $hex=strtoupper(trim((string)($values[$name]??'')));
            if (!preg_match('/^#[A-F0-9]{6}$/D',$hex)) {
                return self::error('mad4b_palette_invalid','Primary, secondary and accent must be 6-digit hexadecimal colors.');
            }
            $out[$name]=$hex;
        }
        $text=isset($values['text'])?strtoupper(trim((string)$values['text'])):$out['primary'];
        if (!preg_match('/^#[A-F0-9]{6}$/D',$text)) {
            return self::error('mad4b_palette_text_invalid','Text color must be hexadecimal.');
        }
        $out['text']=$text;
        if (count(array_unique(array($out['primary'],$out['secondary'],$out['accent'])))!==3) {
            return self::error('mad4b_palette_duplicate_roles','Primary, secondary and accent must be distinguishable.');
        }
        return $out;
    }
    public static function validate_intake($data) {
        if(!is_array($data)) return self::error('mad4b_launch_invalid_payload','JSON object required.');
        $code=strtolower(trim((string)($data['company_code']??'')));
        $brand=trim((string)($data['brand_name']??''));
        $email=trim((string)($data['email']??''));
        $phone=trim((string)($data['phone_e164']??''));
        $logo=trim((string)($data['logo_url']??''));
        $domain_mode=(string)($data['domain_mode']??'');
        $domain=strtolower(trim((string)($data['domain']??'')));
        if ($code==='') {
            $latin=function_exists('remove_accents')?remove_accents($brand):$brand;
            $candidate=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i','-',$latin),'-'));
            $code=preg_match('/^[a-z][a-z0-9-]{2,47}[a-z0-9]$/D',$candidate) ?
                $candidate : 'tour-'.substr(hash('sha256',$brand.'|'.$email),0,12);
        }
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code) ||
            !preg_match('/^[a-z0-9]$/D',substr($code,-1))) {
            return self::error('mad4b_launch_company_code_invalid','Company code must be URL-safe.');
        }
        if (mb_strlen($brand)<3 || mb_strlen($brand)>90 || preg_match('/[<>\x00-\x1F]/u',$brand)) {
            return self::error('mad4b_launch_brand_invalid','Brand name required (3–90 printable characters).');
        }
        if (!is_email($email) || strlen($email)>254) {
            return self::error('mad4b_launch_email_invalid','A valid company contact email is required.');
        }
        if (!preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone)) {
            return self::error('mad4b_launch_phone_invalid','Phone must be in international E.164 format.');
        }
        // Optional logo is a reference, never server-fetched. Blank means neutral
        // demo visual, NOT another tenant's logo or legal brand.
        if ($logo!=='' && (strlen($logo)>700 || !filter_var($logo,FILTER_VALIDATE_URL) ||
            parse_url($logo,PHP_URL_SCHEME)!=='https' ||
            !is_string(parse_url($logo,PHP_URL_HOST)) ||
            filter_var(parse_url($logo,PHP_URL_HOST),FILTER_VALIDATE_IP)!==false ||
            strpos($logo,'@')!==false || preg_match('/[\\x00-\\x20]/',$logo))) {
            return self::error('mad4b_launch_logo_invalid','Optional logo must be a secure HTTPS URL for later review.');
        }
        $palette=self::brand_palette($data['colors']??null);
        if (is_wp_error($palette)) return $palette;
        if ($domain_mode==='') $domain_mode='subdomain';
        if (!in_array($domain_mode,array('subdomain','custom_domain'),true)) {
            return self::error('mad4b_launch_domain_mode_invalid','Choose a managed subdomain or a customer-owned domain.');
        }
        if ($domain_mode==='subdomain') $domain=$code.'.mad4b.com';
        if (!class_exists('MAD4B_SCP_Company_Domain_Cloudflare') ||
            !MAD4B_SCP_Company_Domain_Cloudflare::valid_host($domain)) {
            return self::error('mad4b_launch_domain_invalid','Valid DNS host required.');
        }
        if ($domain_mode==='custom_domain' &&
            ($domain==='mad4b.com' || substr($domain,-10)==='.mad4b.com')) {
            return self::error('mad4b_launch_custom_domain_scope','Choose subdomain mode for MAD4B hosts.');
        }
        if (($data['consent']??false)!==true) {
            return self::error('mad4b_launch_consent_required','Applicant consent to process request details is required.');
        }
        return array(
            'company_code'=>$code,'brand_name'=>$brand,'email'=>strtolower($email),
            'phone_e164'=>$phone,'logo_url'=>$logo,'colors'=>$palette,
            'domain_mode'=>$domain_mode,'domain'=>$domain,
            'field_sources'=>array(
                'brand_name'=>'company_input','email'=>'company_input','phone_e164'=>'company_input',
                'logo_url'=>$logo===''?'neutral_demo_placeholder':'company_input_unverified',
                'colors'=>empty($data['colors'])?'demo_design_default':'company_input',
                'domain'=>empty($data['domain_mode'])?'generated_subdomain':'company_choice',
                'company_code'=>empty($data['company_code'])?'derived_from_brand':'company_input',
            ),
            'customization_open_after_launch'=>true,
            'ai_suggestions_accepted'=>false,'logo_ownership_verified'=>false,
            'email_verified'=>false,'phone_verified'=>false,
            'business_terms_approved'=>false,
        );
    }
    public static function schema() {
        return self::reply(array(
            'contract'=>self::VERSION,'title'=>'Start Your Tourism Company',
            'required'=>array('brand_name','email','phone_e164','consent'),
            'optional'=>array('company_code','logo_url','domain_mode','domain for custom_domain','colors.primary','colors.secondary','colors.accent','colors.text'),
            'defaults'=>array('logo'=>'neutral_demo_placeholder','domain_mode'=>'subdomain','colors'=>self::DEMO_COLORS),
            'inheritance_policy'=>'Demo styles only. No foreign logo, contact, financial, provider or customer data copied.',
            'domain_modes'=>array('subdomain','custom_domain'),
            'public_intake_auto_deploy'=>false,
            'logo_upload_method'=>'HTTPS reference requires owner media validation; upload is not yet supported by this endpoint.',
            'mcp_guidance'=>array(
                'mode'=>'read_only_advisory',
                'inputs'=>array('business_type','target_countries','brand_tone','preferred_colors'),
                'outputs'=>array('brand_names','suggested_primary','suggested_secondary','suggested_accent','taglines','logo_brief'),
                'policy'=>'MCP/ChatbotX may propose; applicant must accept and owner must approve. No write authority for recommendations.',
                'external_chatbotx_transport_connected'=>false,
            ),
            'stages'=>array('intake','identity_validation','owner_review','resource_reserved',
                'site_profile_prepared','service_started_private','site_bootstrap','target_site_profile_enrollment','kit_admission','cloudflare_zone_dns',
                'trusted_tls','content_and_commerce_readiness','owner_public_approval'),
        ));
    }
    public static function intake($request) {
        $values=(array)$request->get_json_params();
        if (!empty($values['website'])) return self::error('mad4b_launch_spam_rejected','Intake rejected.',429);
        $validated=self::validate_intake($values);
        if (is_wp_error($validated)) return $validated;
        $key='mad4b_launch_rate_'.hash_hmac('sha256',
            (string)($_SERVER['REMOTE_ADDR']??'unknown'),wp_salt('auth'));
        $hits=(int)get_transient($key);
        if ($hits>=3) return self::error('mad4b_launch_rate_limited','Too many requests.',429);
        set_transient($key,$hits+1,DAY_IN_SECONDS);
        $id=wp_insert_post(array(
            'post_type'=>self::TYPE,'post_status'=>'private',
            'post_title'=>'Application '.$validated['company_code'],
            'post_content'=>'','post_author'=>0,'comment_status'=>'closed'
        ),true);
        if (is_wp_error($id)) return self::error('mad4b_launch_intake_unavailable','Could not submit application.',503);
        if (!update_post_meta($id,'_mad4b_launch_intake',$validated) ||
            !update_post_meta($id,'_mad4b_launch_state','intake')) {
            wp_delete_post($id,true);
            return self::error('mad4b_launch_intake_unavailable','Application could not be persisted.',503);
        }
        return self::reply(array('contract'=>self::VERSION,
            'request_ref'=>'TC-'.(int)$id,'state'=>'intake',
            'owner_review_required'=>true,'deployment_started'=>false),202);
    }
    private static function request_post($request) {
        $id=(int)$request->get_param('id');
        $post=get_post($id);
        if (!$post || $post->post_type!==self::TYPE || $post->post_status!=='private') {
            return self::error('mad4b_launch_request_missing','Launch request not found.',404);
        }
        return $post;
    }
    private static function current($request) {
        $post=self::request_post($request);
        if (is_wp_error($post)) return $post;
        $data=get_post_meta($post->ID,'_mad4b_launch_intake',true);
        if (!is_array($data)) return self::error('mad4b_launch_data_missing','Application data invalid.',409);
        return array('post'=>$post,'data'=>$data,'state'=>(string)get_post_meta($post->ID,'_mad4b_launch_state',true),
            'coolify_uuid'=>(string)get_post_meta($post->ID,'_mad4b_coolify_service_uuid',true));
    }
    public static function status($request) {
        $x=self::current($request);
        if (is_wp_error($x)) return $x;
        return self::reply(array('contract'=>self::VERSION,'request_id'=>$x['post']->ID,
            'state'=>$x['state'],'application'=>$x['data'],
            'coolify_resource_uuid'=>$x['coolify_uuid'],
            'public_live'=>false,'next_actions'=>self::next_actions($x['state'])));
    }
    public static function next_actions($state) {
        $map=array(
          'intake'=>array('review_company_name_email_phone','owner_review'),
          'owner_approved'=>array('coolify_reserve_resource'),
          'resource_reserved'=>array('prepare_site_profile','owner_private_start','verify_new_database_isolation'),
          'service_started_private'=>array('bootstrap_wordpress','verify_unique_site_uuid_and_kit'),
          'site_bootstrap'=>array('enroll_target_site_profile_v2','kit_admission','cloudflare_domain_plan'),
          'cloudflare_zone_dns'=>array('dns_provider_readback','trusted_https_probe'),
          'trusted_tls'=>array('brand_content_review','commerce_readiness','separate_owner_public_approval')
        );
        return $map[$state]??array('manual_review_required');
    }
    private static function plan_data($x) {
        $v=$x['data'];
        $valid=self::validate_intake(array_merge($v,array('consent'=>true)));
        $preconditions=array(
            'required_brand_email_phone'=>!is_wp_error($valid),
            'domain_mode_valid'=>in_array($v['domain_mode'],array('subdomain','custom_domain'),true),
        );
        $customization=array(
            'logo'=>$v['field_sources']['logo_url']??'neutral_demo_placeholder',
            'colors'=>$v['field_sources']['colors']??'demo_design_default',
            'domain'=>$v['field_sources']['domain']??'generated_subdomain',
            'email_verification_pending'=>empty($v['email_verified']),
            'phone_verification_pending'=>empty($v['phone_verified']),
            'business_terms_review_pending'=>empty($v['business_terms_approved']),
        );
        $payload=array(
            'contract'=>self::VERSION,'id'=>$x['post']->ID,'state'=>$x['state'],
            'brand'=>$v['brand_name'],'domain'=>$v['domain'],'colors'=>$v['colors'],
            'checks'=>$preconditions,'deferred_customization'=>$customization,
            'coolify_configured'=>self::coolify_config()['configured'],
            'service_existing'=>$x['coolify_uuid']!=='',
        );
        $payload['plan_sha256']=hash_hmac('sha256',wp_json_encode($payload),wp_salt('auth'));
        $payload['eligible_owner_approval']=!in_array(false,$preconditions,true) && $x['state']==='intake';
        $payload['owner_confirmation']='APPROVE TOURISM COMPANY '.$v['company_code'];
        $payload['public_live']=false;
        return $payload;
    }
    public static function plan($request) {
        $x=self::current($request);
        return is_wp_error($x)?$x:self::reply(self::plan_data($x));
    }
    private static function owner_plan_match($data,$plan,$confirmation) {
        return isset($data['expected_plan_sha256']) &&
            is_string($data['expected_plan_sha256']) &&
            hash_equals($plan['plan_sha256'],$data['expected_plan_sha256']) &&
            ($data['confirmation']??'')===$confirmation;
    }
    public static function approve($request) {
        $x=self::current($request);
        if (is_wp_error($x)) return $x;
        $plan=self::plan_data($x);
        $input=(array)$request->get_json_params();
        if (!$plan['eligible_owner_approval'] ||
            !self::owner_plan_match($input,$plan,$plan['owner_confirmation'])) {
            return self::error('mad4b_launch_review_blocked',
                'All brand, logo, contact and business review gates must pass with fresh owner approval.',409);
        }
        if (!update_post_meta($x['post']->ID,'_mad4b_launch_state','owner_approved','intake')) {
            return self::error('mad4b_launch_stale_review','State changed; refresh plan.',409);
        }
        return self::reply(array('state'=>'owner_approved','public_live'=>false));
    }
    private static function coolify_config() {
        $path=(string)getenv('MAD4B_COOLIFY_TOKEN_FILE');
        $token='';
        if ($path!=='' && preg_match('~^/run/secrets/[a-zA-Z0-9_.-]+$~D',$path) &&
            strpos($path,'..')===false && is_readable($path) && is_file($path)) {
            $token=trim((string)file_get_contents($path));
        }
        $origin=(string)getenv('MAD4B_COOLIFY_API_BASE');
        $host=(string)getenv('MAD4B_COOLIFY_ALLOWED_API_HOST');
        $u=parse_url($origin);
        $valid=is_array($u) && isset($u['host'],$u['scheme']) &&
            $u['host']===$host && $host!=='' &&
            ($u['scheme']==='https' || ($u['scheme']==='http' && $host==='coolify')) &&
            ($u['path']??'')==='/api/v1' && !isset($u['query'],$u['fragment'],$u['user'],$u['pass']);
        $project=(string)getenv('MAD4B_COOLIFY_TOURISM_PROJECT_UUID');
        $server=(string)getenv('MAD4B_COOLIFY_TOURISM_SERVER_UUID');
        $environment=(string)getenv('MAD4B_COOLIFY_TOURISM_ENVIRONMENT');
        $ready=$valid && $token!=='' && strlen($token)<=2048 &&
            preg_match('/^[a-z0-9]{16,48}$/D',$project) &&
            preg_match('/^[a-z0-9]{16,48}$/D',$server) &&
            $environment==='production';
        return array('configured'=>(bool)$ready,'token'=>$token,'base'=>$origin,
            'project_uuid'=>$project,'server_uuid'=>$server,'environment'=>$environment);
    }
    private static function api($method,$path,$body=null) {
        $cfg=self::coolify_config();
        if (!$cfg['configured'] || !preg_match('~^/services(?:/[a-zA-Z0-9-]{8,65}(?:/start)?)?$~D',$path)) {
            return self::error('mad4b_launch_coolify_unconfigured','Coolify account/endpoint/token is not authorized.',503);
        }
        $args=array('method'=>$method,'timeout'=>18,'redirection'=>0,'sslverify'=>true,
            'headers'=>array('Authorization'=>'Bearer '.$cfg['token'],'Accept'=>'application/json'));
        if ($body!==null) {
            $args['headers']['Content-Type']='application/json';
            $args['body']=wp_json_encode($body);
        }
        $response=wp_remote_request($cfg['base'].$path,$args);
        if (is_wp_error($response)) return self::error('mad4b_launch_coolify_outcome_unknown',
            'Remote outcome unknown; reconcile before retry.',502);
        $code=(int)wp_remote_retrieve_response_code($response);
        $raw=(string)wp_remote_retrieve_body($response);
        $doc=strlen($raw)<=120000?json_decode($raw,true):null;
        if ($code<200 || $code>=300 || !is_array($doc)) return self::error('mad4b_launch_coolify_rejected',
            'Provider operation rejected or returned invalid result.',502);
        return $doc;
    }
    public static function coolify_commit($request) {
        $x=self::current($request);
        if (is_wp_error($x)) return $x;
        if ($x['state']!=='owner_approved' || $x['coolify_uuid']!=='') {
            return self::error('mad4b_launch_coolify_stale','Company resource already exists or approval incomplete.',409);
        }
        $plan=self::plan_data($x);
        $data=(array)$request->get_json_params();
        $confirmation='RESERVE COOLIFY FOR '.$x['data']['company_code'];
        if (!self::owner_plan_match($data,$plan,$confirmation)) {
            return self::error('mad4b_launch_coolify_confirmation','Exact fresh approval required.',409);
        }
        $cfg=self::coolify_config();
        if (!$cfg['configured']) return self::error('mad4b_launch_coolify_unconfigured','Coolify token and topology not connected.',503);
        $key=(string)($data['idempotency_key']??'');
        if (!preg_match('/^[a-zA-Z0-9_-]{12,80}$/D',$key)) {
            return self::error('mad4b_launch_idempotency_required','Stable intent key required.',422);
        }
        $intent=array('hash'=>hash('sha256',$key.'|'.$plan['plan_sha256']),
            'state'=>'provider_outcome_unknown','when'=>time());
        if (!add_post_meta($x['post']->ID,'_mad4b_coolify_create_intent',$intent,true)) {
            return self::error('mad4b_launch_reconcile_required','Prior request exists; provider readback required before retry.',409);
        }
        $name=$x['data']['company_code'].'-production';
        // Coolify's maintained WordPress + MariaDB one-click template owns
        // fresh DB credentials, rather than importing another tenant's data.
        $created=self::api('POST','/services',array(
            'type'=>'wordpress-with-mariadb','name'=>$name,
            'description'=>'MAD4B governed new tourism company - '.$x['post']->ID,
            'project_uuid'=>$cfg['project_uuid'],'server_uuid'=>$cfg['server_uuid'],
            'environment_name'=>$cfg['environment'],'instant_deploy'=>false,
            'force_domain_override'=>false,'redact_all_env_values_in_logs'=>true,
        ));
        if (is_wp_error($created)) return $created;
        $uuid=(string)($created['uuid']??'');
        if (!preg_match('/^[a-zA-Z0-9-]{8,65}$/D',$uuid)) {
            return self::error('mad4b_launch_coolify_unverified_resource',
                'Coolify result cannot be safely reconciled; do not replay creation.',502);
        }
        update_post_meta($x['post']->ID,'_mad4b_coolify_service_uuid',$uuid);
        update_post_meta($x['post']->ID,'_mad4b_launch_state','resource_reserved');
        return self::reply(array('contract'=>self::VERSION,'state'=>'resource_reserved',
            'coolify_resource_uuid'=>$uuid,'service_started'=>false,'public_live'=>false),201);
    }
    public static function coolify_start($request) {
        $x=self::current($request);
        if (is_wp_error($x)) return $x;
        $v=$x['data'];
        if ($x['state']!=='resource_reserved' || !preg_match('/^[a-zA-Z0-9-]{8,65}$/D',$x['coolify_uuid'])) {
            return self::error('mad4b_launch_start_blocked','Resource must first be reserved and reviewed.',409);
        }
        $plan=self::plan_data($x);
        if (!self::owner_plan_match((array)$request->get_json_params(),$plan,
            'START PRIVATE COOLIFY FOR '.$v['company_code'])) {
            return self::error('mad4b_launch_start_confirmation','Exact start approval required.',409);
        }
        $actual=self::api('GET','/services/'.$x['coolify_uuid']);
        if (is_wp_error($actual)) return $actual;
        if (($actual['uuid']??'')!==$x['coolify_uuid'] ||
            ($actual['name']??'')!==$v['company_code'].'-production') {
            return self::error('mad4b_launch_provider_identity_mismatch','Resource is not the intended company service.',409);
        }
        // Public domains are explicitly forbidden until WP/bootstrap/TLS gates.
        if (!empty($actual['domains'])) {
            return self::error('mad4b_launch_public_domain_premature','Coolify resource already has public domains.',409);
        }
        if (!add_post_meta($x['post']->ID,'_mad4b_coolify_start_intent',
            array('state'=>'provider_outcome_unknown','at'=>time()),true)) {
            return self::error('mad4b_launch_start_reconcile','Start outcome must be reconciled.',409);
        }
        $started=self::api('POST','/services/'.$x['coolify_uuid'].'/start');
        if (is_wp_error($started)) return $started;
        update_post_meta($x['post']->ID,'_mad4b_launch_state','service_started_private');
        return self::reply(array('state'=>'service_started_private',
            'next_actions'=>self::next_actions('service_started_private'),
            'public_live'=>false),202);
    }
    public static function coolify_readback($request) {
        $x=self::current($request);
        if (is_wp_error($x)) return $x;
        if ($x['coolify_uuid']==='') return self::reply(array('state'=>'not_created','public_live'=>false));
        $service=self::api('GET','/services/'.$x['coolify_uuid']);
        if (is_wp_error($service)) return $service;
        if (($service['uuid']??'')!==$x['coolify_uuid']) return self::error(
            'mad4b_launch_coolify_identity_conflict','Coolify resource UUID mismatch.',409);
        return self::reply(array('contract'=>self::VERSION,
            'coolify_resource_uuid'=>$x['coolify_uuid'],
            'resource_name'=>$service['name']??null,
            'resource_matches_company'=>($service['name']??'')===$x['data']['company_code'].'-production',
            'state'=>$x['state'],'requires_site_bootstrap'=>true,
            'requires_kit_governance'=>true,'requires_cloudflare_readback'=>true,
            'requires_trusted_tls'=>true,'requires_business_launch_approval'=>true,
            'public_live'=>false));
    }

    /** A public form only creates an intake. Suggestions are advisory, not code. */
    public static function shortcode() {
        $schema=rest_url(self::NS.self::ROOT.'/applications');
        $id='mad4b-tourism-start-'.wp_generate_uuid4();
        $fields=array(
            array('Brand name','brand_name','text'),
            array('Company slug (optional)','company_code','text'),
            array('Logo HTTPS URL (optional)','logo_url','url'),
            array('Business email','email','email'),
            array('Phone (+country code)','phone_e164','tel'),
        );
        $html='<form class="mad4b-tourism-company-start" id="'.esc_attr($id).'">';
        $html.='<h2>'.esc_html__('Start Your Tourism Company','mad4b-site-control-plane').'</h2>';
        foreach($fields as $f) $html.='<label>'.esc_html($f[0]).'<input'.(in_array($f[1],array('brand_name','email','phone_e164'),true)?' required':'').' type="'.esc_attr($f[2]).
            '" name="'.esc_attr($f[1]).'" maxlength="700"></label>';
        $html.='<label>Website domain mode<select name="domain_mode"><option value="subdomain">MAD4B subdomain</option><option value="custom_domain">My own domain</option></select></label>';
        $html.='<label>Custom domain (optional)<input name="domain" type="text"></label>';
        foreach(array('primary','secondary','accent') as $color) {
            $html.='<label>'.esc_html(ucfirst($color)).' <input name="color_'.$color.
                '" type="color" value="'.esc_attr(self::DEMO_COLORS[$color]).'"></label>';
        }
        $html.='<label><input name="consent" type="checkbox" required> I agree to be contacted about this company application.</label>';
        $html.='<label style="display:none" aria-hidden="true">Leave blank<input name="website" tabindex="-1" autocomplete="off"></label>';
        $html.='<button type="submit">Request company setup</button><p role="status" aria-live="polite"></p></form>';
        $js='(function(){const e=document.getElementById('.wp_json_encode($id).');if(!e)return;'.
            'e.addEventListener("submit",async function(ev){ev.preventDefault();const b=e.querySelector("button");b.disabled=true;'.
            'const f=new FormData(e);const v={};for(const [k,x] of f.entries())v[k]=x;'.
            'const data={brand_name:v.brand_name,company_code:v.company_code,logo_url:v.logo_url,email:v.email,'.
            'phone_e164:v.phone_e164,domain_mode:v.domain_mode,domain:v.domain,consent:f.has("consent"),website:v.website,'.
            'colors:{primary:v.color_primary,secondary:v.color_secondary,accent:v.color_accent}};'.
            'try{const r=await fetch('.wp_json_encode($schema).',{method:"POST",headers:{"Content-Type":"application/json"},'.
            'body:JSON.stringify(data),credentials:"omit"});const a=await r.json();'.
            'e.querySelector("[role=status]").textContent=r.ok?"Request submitted: "+a.request_ref:("Request not accepted: "+(a.message||"Check fields"));'.
            '}catch(err){e.querySelector("[role=status]").textContent="Connection unavailable. Try again later.";}finally{b.disabled=false;}});})();';
        return $html.'<script>'.$js.'</script>';
    }
}
