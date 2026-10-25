<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Local-only machine identity for an already delegated AI-only review lane.
 * No administrator Application Password, no implicit Context approval, no grant minting.
 */
final class MAD4B_SCP_AI_Only_Local_Activation {
    const CONTRACT = 'mad4b.ai-only-local-activation.v1';
    const REVIEW_ABILITY = 'mad4b/context-ai-review';
    const LOCAL_REVIEW_ROUTE = '/mad4b-local-ai/v1/context-review';
    const LOCAL_DOMAIN_ROUTE = '/mad4b-local-ai/v1/domain-review';
    const USER_META = '_mad4b_ai_only_local_service_site';
    const MAX_LIFETIME = 86400;
    const AUDIT_OPTION = 'mad4b_scp_ai_only_local_activation_audit_v1';

    private static function path() {
        if ( ! defined('MAD4B_LOCAL_CLIENT_VAULT_PATH') ) return '';
        $base = constant('MAD4B_LOCAL_CLIENT_VAULT_PATH');
        if ( ! is_string($base) || '/' !== substr($base,0,1) ) return '';
        $dir = dirname($base);
        $real = realpath($dir);
        $web = realpath(ABSPATH);
        if ( ! $real || !$web || 0 === strpos($real.'/', $web.'/')
             || !is_dir($real) || !is_writable($real) || (fileperms($real)&0022) !== 0 ) return '';
        return $real.'/ai-only-review.json';
    }
    private static function policy_agent() {
        if ( ! class_exists('MAD4B_SCP_Context_Authority')
             || !class_exists('MAD4B_SCP_Agent_Registry')
             || !class_exists('MAD4B_SCP_Local_Client')
             || ! MAD4B_SCP_Local_Client::bound()
             || wp_get_environment_type() !== 'local' ) return new WP_Error('mad4b_ai_only_local_scope_denied','Local Site Profile binding required.');
        $policy=MAD4B_SCP_Context_Authority::review_policy();
        $status=MAD4B_SCP_Context_Authority::ai_review_policy_status();
        if ( ($policy['mode']??'') !== 'ai_only' || empty($status['ready']) || empty($status['exact_grant_ready']) )
            return new WP_Error('mad4b_ai_only_policy_not_ready','AI-only delegation and exact grant are required.');
        $agent=MAD4B_SCP_Agent_Registry::get_agent_by_public_id((string)$policy['ai_agent_public_id']);
        if ( !is_array($agent) || ($agent['status']??'') !== 'enabled' )
            return new WP_Error('mad4b_ai_only_agent_unavailable','Delegated Agent unavailable.');
        $grant=MAD4B_SCP_Agent_Registry::exact_grant((int)$agent['id'],'mad4b-write',self::REVIEW_ABILITY,'core');
        if ( !is_array($grant) || ($grant['effect']??'') !== 'allow' ) return new WP_Error('mad4b_ai_only_grant_missing','Exact grant unavailable.');
        return $agent;
    }
    private static function audit($action,$origin,$service_user_id) {
        $items=get_option(self::AUDIT_OPTION,array());
        if(!is_array($items))$items=array();
        $row=array('event'=>(string)$action,'origin'=>(string)$origin,
            'service_user_id'=>(int)$service_user_id,'at'=>time(),
            'site_fingerprint'=>hash('sha256',(string)MAD4B_SCP_Site_Profile::site_uuid()));
        $items[]= $row;
        $items=array_slice($items,-30);
        update_option(self::AUDIT_OPTION,$items,false);
        $read=get_option(self::AUDIT_OPTION,array());
        return is_array($read) && end($read)===$row;
    }
    private static function service_login() {
        return 'mad4b_ai_local_' . substr(hash('sha256',MAD4B_SCP_Site_Profile::site_uuid()),0,12);
    }
    private static function record() {
        $path=self::path();
        if (!$path || !is_file($path) || is_link($path) || !is_readable($path)
            || filesize($path)>8192 || (fileperms($path)&0077)!==0) return null;
        $row=json_decode((string)file_get_contents($path),true);
        return is_array($row)?$row:null;
    }
    /** Never return the bearer secret, login or path to the WordPress UI. */
    public static function status() {
        $row=self::record();
        $agent=self::policy_agent();
        $valid=is_array($row) && !is_wp_error($agent)
            && hash_equals((string)MAD4B_SCP_Site_Profile::site_uuid(),(string)($row['site_uuid']??''))
            && hash_equals((string)$agent['public_id'],(string)($row['agent_public_id']??''))
            && (int)($row['expires_at']??0)>time();
        if ($valid) {
            $user=get_userdata((int)($row['user_id']??0));
            $app=$user && class_exists('WP_Application_Passwords')
                ? WP_Application_Passwords::get_user_application_password((int)$user->ID,(string)($row['uuid']??'')) : null;
            $subject=$user ? MAD4B_SCP_Agent_Registry::subject_binding('wp_app_password',hash('sha256',
                implode(chr(0),array('wp_app_password',MAD4B_SCP_Site_Profile::site_uuid(),(int)$user->ID,(string)($row['uuid']??''))))) : null;
            $valid=$user && $user->user_login===self::service_login()
                && (array)$user->roles===array('subscriber')
                && (string)get_user_meta($user->ID,self::USER_META,true)===(string)MAD4B_SCP_Site_Profile::site_uuid()
                && is_array($app) && is_array($subject) && $subject['status']==='enabled'
                && (int)($subject['agent_id']??0)===(int)$agent['id'];
        }
        return array('contract'=>self::CONTRACT,'mode'=>'ai_only','local_only'=>true,
            'identity_ready'=>(bool)$valid,'review_execution_confirmed'=>false,
            'manual_application_password_required'=>false,
            'review_decision_automated'=>false,'secrets_exposed'=>false,
            'reason'=>$valid?'credential_prepared_for_authentic_agent':'awaiting_local_ai_activation');
    }
    private static function operator_allowed() {
        // Trusted host operator may reconcile an existing, previously authorized
        // Local Lab policy. This path cannot run from HTTP or Staging.
        if ( PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid()!==0
            || getenv('MAD4B_LOCAL_AI_HOST_RECONCILE')!=='1' ) return false;
        $policy=MAD4B_SCP_Context_Authority::review_policy();
        $owner_id=(int)($policy['updated_by_wp_user_id']??0);
        $owner=$owner_id>0?get_userdata($owner_id):false;
        return $owner && user_can($owner,'manage_options')
            && MAD4B_SCP_Site_Profile::user_is_enrolled($owner_id);
    }
    public static function activate($origin='policy_save') {
        $agent=self::policy_agent();
        if (is_wp_error($agent)) return $agent;
        if ( !defined('MAD4B_LOCAL_CLIENT_PROVISION_ENABLED') || true!==constant('MAD4B_LOCAL_CLIENT_PROVISION_ENABLED') )
            return new WP_Error('mad4b_ai_only_local_activation_disabled','Local activation not enabled.');
        $admin = 'policy_save'===$origin
            && is_user_logged_in() && current_user_can('manage_options')
            && MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id())
            && MAD4B_SCP_Policy::can_connect_user(get_current_user_id());
        if (!$admin && !('host_operator'===$origin && self::operator_allowed()))
            return new WP_Error('mad4b_ai_only_activation_authorization_required','Verified administrator policy action or trusted local host reconciliation required.');
        $path=self::path();
        if (!$path) return new WP_Error('mad4b_ai_only_vault_unavailable','Secure local vault unavailable.');
        if (file_exists($path)) {
            $prior=self::record();
            if(is_array($prior) && time()>=(int)($prior['expires_at']??0))self::expire();
        }
        if (file_exists($path)) {
            $status=self::status();
            return !empty($status['identity_ready'])?$status:
                new WP_Error('mad4b_ai_only_existing_vault_invalid','A stale vault needs governed revocation before rotation.');
        }
        $login=self::service_login();
        $user=get_user_by('login',$login);
        if(!$user) {
            $user_id=wp_insert_user(array('user_login'=>$login,'user_pass'=>wp_generate_password(64,true,true),
                'role'=>'subscriber','display_name'=>'MAD4B Local AI-only Review Agent'));
            if(is_wp_error($user_id)) return $user_id;
            $user=get_userdata((int)$user_id);
            update_user_meta((int)$user_id,self::USER_META,MAD4B_SCP_Site_Profile::site_uuid());
        }
        if(!$user || $user->user_login!==$login || (array)$user->roles!==array('subscriber')
           || (string)get_user_meta((int)$user->ID,self::USER_META,true)!==(string)MAD4B_SCP_Site_Profile::site_uuid())
            return new WP_Error('mad4b_ai_only_service_user_conflict','Local subscriber service identity conflicts with existing WordPress user.');
        if(!class_exists('WP_Application_Passwords') || !wp_is_application_passwords_available_for_user($user))
            return new WP_Error('mad4b_ai_only_passwords_disabled','Local service Application Password provider unavailable.');
        $issued=WP_Application_Passwords::create_new_application_password((int)$user->ID,array('name'=>'MAD4B AI-only review (local; 24h)'));
        if(is_wp_error($issued)) return $issued;
        $uuid=(string)($issued[1]['uuid']??'');
        if(!$uuid || !is_string($issued[0]??null)) return new WP_Error('mad4b_ai_only_issue_invalid','Credential issuance metadata missing.');
        $site=(string)MAD4B_SCP_Site_Profile::site_uuid();
        $fingerprint=hash('sha256',implode(chr(0),array('wp_app_password',$site,(int)$user->ID,$uuid)));
        $payload=array('contract'=>self::CONTRACT,'login'=>$user->user_login,'password'=>$issued[0],
            'user_id'=>(int)$user->ID,'uuid'=>$uuid,'site_uuid'=>$site,
            'agent_public_id'=>(string)$agent['public_id'],'issued_at'=>time(),'expires_at'=>time()+self::MAX_LIFETIME);
        $serialized=wp_json_encode($payload);
        $mask=umask(0077);
        $h=@fopen($path,'x');
        umask($mask);
        $ok=$h && is_string($serialized) && strlen($serialized)===fwrite($h,$serialized) && fflush($h);
        if($h) fclose($h);
        if($ok) { @chmod($path,0600);clearstatcache(true,$path);$ok=(fileperms($path)&0077)===0; }
        if(!$ok) {
            if(is_file($path)) @unlink($path);
            WP_Application_Passwords::delete_application_password((int)$user->ID,$uuid);
            return new WP_Error('mad4b_ai_only_vault_write_failed','Secure local service vault write failed.');
        }
        $bound=MAD4B_SCP_Agent_Registry::bind_subject((string)$agent['public_id'],'wp_app_password',
            $fingerprint,'AI-only Local Lab review (subscriber)');
        if($bound!==true) {
            WP_Application_Passwords::delete_application_password((int)$user->ID,$uuid);
            @unlink($path);
            return new WP_Error('mad4b_ai_only_subject_binding_failed','Local Agent identity binding failed; issued credential revoked.');
        }
        if(!self::audit('credential_provisioned',$origin,(int)$user->ID)) {
            WP_Application_Passwords::delete_application_password((int)$user->ID,$uuid);
            @unlink($path);
            return new WP_Error('mad4b_ai_only_activation_audit_failed','Credential revoked because local audit was not durably recorded.');
        }
        if(function_exists('wp_schedule_single_event'))
            wp_schedule_single_event((int)$payload['expires_at'],'mad4b_ai_only_local_expire');
        return self::status();
    }
    /** Admission to this credential's REST scope never broadens a WordPress account role. */
    public static function matches_credential($user,$item) {
        if(wp_get_environment_type()!=='local' || !($user instanceof WP_User) || empty($item['uuid']))return false;
        $r=self::record();if(!is_array($r)||time()>=(int)($r['expires_at']??0))return false;
        return (int)$r['user_id']===(int)$user->ID
            && hash_equals((string)$r['uuid'],(string)$item['uuid'])
            && (string)$r['site_uuid']===(string)MAD4B_SCP_Site_Profile::site_uuid()
            && !is_wp_error(self::policy_agent());
    }
    public static function restrict_service_rest($error,$user,$item) {
        // WordPress provides a mutable WP_Error through an ACTION, not a filter.
        if(!($error instanceof WP_Error) || !($user instanceof WP_User) || !is_array($item))return;
        $record=self::record();
        if(!is_array($record) || empty($item['uuid'])
           || (int)($record['user_id']??0)!==(int)$user->ID
           || !hash_equals((string)($record['uuid']??''),(string)$item['uuid']))return;
        if(!self::matches_credential($user,$item)) {
            $error->add('mad4b_ai_only_service_revoked','Managed local review credential expired or was disabled.');
            return;
        }
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        $path=(string)parse_url($uri,PHP_URL_PATH);
        $route=isset($_GET['rest_route'])?(string)$_GET['rest_route']:'';
        if(!preg_match('#/wp-json/mad4b-local-ai/v1/(?:context|domain)-review/?$#D',$path)
           && !in_array('/'.trim($route,'/'),array(self::LOCAL_REVIEW_ROUTE,self::LOCAL_DOMAIN_ROUTE),true))
            $error->add('mad4b_ai_only_service_rest_scope_denied',
                'Managed AI-only credential is limited to its local review endpoint.');
    }
    /** Prevent unrelated REST access regardless of when WP lazily authenticates Basic. */
    /** Authenticate through WordPress itself before the OAuth bridge pre-dispatch. */
    public static function restrict_rest_dispatch($result,$server,$request) {
        if(wp_get_environment_type()!=='local' || !is_object($request) || !method_exists($request,'get_route'))return $result;
        $route='/'.trim((string)$request->get_route(),'/');
        if($route==='/mcp/mad4b-chatgpt'
           && preg_match('/^Basic [A-Za-z0-9+\/=]+$/D',trim((string)$request->get_header('authorization')))
           && function_exists('wp_validate_application_password')) {
            $verified_id=wp_validate_application_password(null);
            if(is_int($verified_id) && $verified_id>0 && (int)get_current_user_id()!==$verified_id)
                wp_set_current_user($verified_id);
        }
        $user=wp_get_current_user();
        if(!($user instanceof WP_User) || $user->user_login!==self::service_login())return $result;
        if((string)get_user_meta((int)$user->ID,self::USER_META,true)!==(string)MAD4B_SCP_Site_Profile::site_uuid())
            return new WP_Error('mad4b_ai_only_identity_untrusted','Managed local reviewer identity metadata mismatch.',array('status'=>403));
        if(!in_array($route,array(self::LOCAL_REVIEW_ROUTE,self::LOCAL_DOMAIN_ROUTE),true))
            return new WP_Error('mad4b_ai_only_service_mcp_only','Local reviewer credentials may access only the governed review routes.',array('status'=>403));
        return $result;
    }
    /** Bounded local-only path; invokes the existing exact Agent governance method. */
    public static function register_review_route() {
        if(wp_get_environment_type()!=='local' || !class_exists('MAD4B_SCP_Local_Client')
           || !MAD4B_SCP_Local_Client::bound())return;
        register_rest_route('mad4b-local-ai/v1','/context-review',array(
            'methods'=>'GET, POST',
            'callback'=>array(__CLASS__,'execute_local_review'),
            'permission_callback'=>array(__CLASS__,'review_permission'),
            'args'=>array(),
        ));
        register_rest_route('mad4b-local-ai/v1','/domain-review',array(
            'methods'=>'POST',
            'callback'=>array(__CLASS__,'execute_local_domain_review'),
            'permission_callback'=>array(__CLASS__,'review_permission'),
            'args'=>array(),
        ));
    }
    public static function execute_local_domain_review($request) {
        if(!is_object($request) || !method_exists($request,'get_json_params'))
            return new WP_Error('mad4b_owner_review_input_required','Structured owner review required.',array('status'=>400));
        $body=$request->get_json_params();
        if(!is_array($body) || array_diff(array_keys($body),
            array('domain','item','decision','review_note','source_binding')))
            return new WP_Error('mad4b_owner_review_input_invalid','Only exact scoped review inputs allowed.',array('status'=>400));
        $domain=(string)($body['domain']??'');
        $owner=class_exists('MAD4B_SCP_AI_Native_Owner_Review')
            ?MAD4B_SCP_AI_Native_Owner_Review::owner($domain):'';
        if($owner==='' ||!class_exists($owner)||!method_exists($owner,'ai_original_review_submit'))
            return new WP_Error('mad4b_owner_review_owner_unavailable','Native domain owner unavailable.',array('status'=>403));
        $receipt=call_user_func(array($owner,'ai_original_review_submit'),
            $body['item']??null,$body['decision']??null,
            $body['review_note']??null,$body['source_binding']??null);
        if(is_wp_error($receipt))return $receipt;
        $verified=call_user_func(array($owner,'ai_original_review_verify_receipt'),
            $body['item'],$receipt,$body['source_binding']);
        if($verified!==true)return new WP_Error('mad4b_owner_review_receipt_unverified',
            'Original owner receipt not independently verified.',array('status'=>409));
        return array('contract'=>'mad4b.ai-native-owner-rest.v1',
            'domain'=>$domain,'receipt'=>$receipt,
            'owner_receipt_verified'=>true,
            'publication_authorized'=>false,'production_mutation'=>false);
    }

    public static function review_permission($request) {
        if(wp_get_environment_type()!=='local')return new WP_Error('mad4b_ai_local_scope_denied','Local only.',array('status'=>403));
        $user=wp_get_current_user();
        $row=self::record();
        if(!is_array($row) || !($user instanceof WP_User) || (int)$user->ID!==(int)$row['user_id']
           || $user->user_login!==self::service_login()
           || empty(self::status()['identity_ready']))return new WP_Error('mad4b_ai_only_auth_required','Verified local service identity required.',array('status'=>403));
        $identity=MAD4B_SCP_Identity_Context::current();
        if(is_wp_error($identity) || empty($identity['authenticated'])
           || ($identity['auth_method']??'')!=='wp_application_password')
            return new WP_Error('mad4b_ai_only_authenticated_agent_required','Genuine WordPress Application Password authentication required.',array('status'=>403));
        $agent=MAD4B_SCP_Agent_Registry::resolve_agent($identity);
        $policy=self::policy_agent();
        if(is_wp_error($agent) || is_wp_error($policy) || !hash_equals((string)$policy['public_id'],(string)$agent['public_id']))
            return new WP_Error('mad4b_ai_only_exact_agent_required','Authenticated subject is not the delegated Agent.',array('status'=>403));
        return true;
    }
    public static function execute_local_review($request) {
        // The authorised Agent can pull outstanding work without an operator
        // opening WordPress admin or sending credentials manually.
        if(method_exists($request,'get_method') && strtoupper((string)$request->get_method())==='GET') {
            if(!class_exists('MAD4B_SCP_AI_Review_Lifecycle'))
                return new WP_Error('mad4b_ai_lifecycle_runtime_missing','Shared AI review lifecycle is unavailable.',array('status'=>503));
            $asset_id=method_exists($request,'get_param')?$request->get_param('asset_id'):null;
            if(null!==$asset_id && ''!==(string)$asset_id)
                return MAD4B_SCP_AI_Review_Lifecycle::exact_media_evidence($asset_id);
            return MAD4B_SCP_AI_Review_Lifecycle::brand_context_inbox();
        }
        $input=method_exists($request,'get_json_params')?$request->get_json_params():null;
        if(!is_array($input))return new WP_Error('mad4b_ai_local_review_input_invalid','Structured review input required.',array('status'=>400));
        $allowed=array('asset_id','decision','review_note','expected_content_hash','expected_registry_revision','expected_authority_manifest_fingerprint');
        foreach(array_keys($input) as $key)if(!in_array($key,$allowed,true))
            return new WP_Error('mad4b_ai_local_review_field_denied','Unknown or governance-mutating field.',array('status'=>400));
        if(!isset($input['asset_id'],$input['decision'],$input['expected_content_hash'],
           $input['expected_registry_revision'],$input['expected_authority_manifest_fingerprint']))
            return new WP_Error('mad4b_ai_local_review_cas_required','Exact content, registry and authority binding are required.',array('status'=>400));
        // The original AI review method re-verifies identity, grant and CAS,
        // persists audit and confirms exact content hash. No side-door mutation.
        $result=MAD4B_SCP_Context_Authority::review_asset_by_agent($input);
        if(is_wp_error($result)) {
            $code=(string)$result->get_error_code();
            $data=$result->get_error_data();
            $data=is_array($data)?$data:array();
            if(in_array($code,array('mad4b_context_review_content_drift',
                'mad4b_context_review_registry_drift','mad4b_context_review_authority_drift'),true))
                $data['status']=409;
            elseif(in_array($code,array('mad4b_context_ai_review_oauth_required',
                'mad4b_context_ai_review_agent_mismatch','mad4b_context_ai_review_exact_grant_missing',
                'mad4b_context_ai_review_mode_disabled','mad4b_context_human_review_disabled'),true))
                $data['status']=403;
            $result->add_data($data,$code);
        }
        if(!is_wp_error($result) && class_exists('MAD4B_SCP_AI_Review_Lifecycle')) {
            $tracking=MAD4B_SCP_AI_Review_Lifecycle::record_review_result(
                (string)$input['asset_id'],(string)$input['expected_content_hash'],(string)$input['decision']);
            if(is_array($result))$result['ai_review_lifecycle_tracking']=is_wp_error($tracking)
                ?array('recorded'=>false,'error_code'=>$tracking->get_error_code()):$tracking;
        }
        return $result;
    }
    public static function expire() {
        $r=self::record();
        if(!is_array($r) || time()<(int)($r['expires_at']??0))return;
        WP_Application_Passwords::delete_application_password((int)$r['user_id'],(string)$r['uuid']);
        @unlink(self::path());
        self::audit('credential_expired','scheduled_revoke',(int)$r['user_id']);
    }
    public static function boot() {
        add_action('wp_authenticate_application_password_errors',array(__CLASS__,'restrict_service_rest'),10,3);
        add_action('mad4b_ai_only_local_expire',array(__CLASS__,'expire'));
        add_filter('rest_pre_dispatch',array(__CLASS__,'restrict_rest_dispatch'),0,3);
        add_action('rest_api_init',array(__CLASS__,'register_review_route'),11);
    }
}
MAD4B_SCP_AI_Only_Local_Activation::boot();
