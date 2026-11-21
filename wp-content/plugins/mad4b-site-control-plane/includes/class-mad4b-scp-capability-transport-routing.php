<?php
/**
 * MAD4B central capability transport policy.
 *
 * Provider (ChatGPT, other AI API, search provider, etc.) and transport
 * (HTTP MCP, local VPS stdio MCP, REST gateway) are independent decisions.
 * This class never grants authority, executes a target Ability or retries
 * an uncertain write. All users share one code path, not per-feature rules.
 */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Capability_Transport_Routing {
    const CONTRACT='mad4b.central-capability-transport-routing.v2';
    const HTTP='wordpress_mcp_https';
    const VPS='vps_mcp_stdio';
    const REST='rest_gateway';
    const ALLOWED_EFFECTS=['read','write'];
    const EXECUTION_PREFERENCE_CONTRACT='mad4b.execution-preferences.v1';
    const EXECUTION_PREFERENCE_OPTION='mad4b_execution_transport_preferences_v1';
    const EXECUTION_MODES=['registered_operator','signed_broker','manual_approval','read_only'];

    /**
     * Site UUID-scoped policy preferences. Stored values never authenticate
     * operators, mint grants, or prove that a transport is usable.
     * Every company uses the same default; a local site stores only its
     * explicit overrides. No source-code change is needed to add a company.
     */
    public static function execution_preferences():array {
        $raw=function_exists('get_option')
            ?get_option(self::EXECUTION_PREFERENCE_OPTION,[]):[];
        $value=[
            'contract'=>self::EXECUTION_PREFERENCE_CONTRACT,
            'revision'=>0,
            'default_mode'=>'registered_operator',
            'site_overrides'=>[]
        ];
        if (!is_array($raw) || !$raw) return $value;
        if (($raw['contract']??'')!==self::EXECUTION_PREFERENCE_CONTRACT) {
            $value['invalid_configuration']=true;
            $value['default_mode']='read_only';
            return $value;
        }
        $value['revision']=max(0,(int)($raw['revision']??0));
        $default=(string)($raw['default_mode']??'');
        $value['default_mode']=in_array($default,self::EXECUTION_MODES,true)
            ?$default:'read_only';
        $items=is_array($raw['site_overrides']??null)?$raw['site_overrides']:[];
        foreach($items as $uuid=>$channels) {
            if (!is_string($uuid) || !preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',
                $uuid) || !is_array($channels)) continue;
            foreach([self::HTTP,self::VPS,self::REST] as $channel) {
                if (!array_key_exists($channel,$channels))continue;
                $mode=(string)$channels[$channel];
                // Malformed explicit overrides never relax a write policy.
                $value['site_overrides'][$uuid][$channel]=
                    in_array($mode,self::EXECUTION_MODES,true)?$mode:'read_only';
            }
        }
        return $value;
    }

    private static function enrolled_site_uuid():string {
        if(!class_exists('MAD4B_SCP_Site_Profile',false))return '';
        $profile=MAD4B_SCP_Site_Profile::status();
        if(!is_array($profile)||empty($profile['origin_match'])
            ||empty($profile['deployment_binding_match'])
            ||empty($profile['site_uuid']))return '';
        return (string)$profile['site_uuid'];
    }

    public static function mode_for_transport(string $transport):string {
        if(!in_array($transport,[self::HTTP,self::VPS,self::REST],true))
            return 'read_only';
        $p=self::execution_preferences();
        if(!empty($p['invalid_configuration']))return 'read_only';
        $site=self::enrolled_site_uuid();
        if($site==='')return 'read_only';
        return $p['site_overrides'][$site][$transport]??$p['default_mode'];
    }

    /**
     * Bounded Staging/site-local preference change. Called from a
     * WordPress-authenticated admin action or authorized host WP-CLI only.
     * This changes proof-routing preferences, NOT executable permissions.
     */
    public static function save_for_current_site(string $transport,string $mode,int $expected_revision) {
        if(!in_array($transport,[self::HTTP,self::VPS,self::REST],true)
            ||!in_array($mode,self::EXECUTION_MODES,true))
            return new WP_Error('mad4b_execution_preference_invalid','Unknown policy mode/transport.');
        if(!function_exists('current_user_can')||!current_user_can('manage_options'))
            return new WP_Error('mad4b_execution_preference_forbidden','Administrator privilege required.');
        $site=self::enrolled_site_uuid();
        if($site==='')return new WP_Error('mad4b_execution_preference_site_unbound','Exact enrolled Site Profile is required.');
        $existing=self::execution_preferences();
        if(!empty($existing['invalid_configuration']))
            return new WP_Error('mad4b_execution_preference_corrupt','Stored preferences require recovery.');
        if($existing['revision']!==$expected_revision)
            return new WP_Error('mad4b_execution_preference_revision_conflict','Refresh revision before saving.');
        $explicit=$existing['site_overrides'][$site][$transport]??null;
        $previous=$explicit??$existing['default_mode'];
        // Selecting the inherited default is still a deliberate site override.
        // Only an identical explicit override is a persistence no-op.
        if($explicit===$mode)return [
            'saved'=>true,'changed'=>false,'revision'=>$existing['revision'],
            'site_uuid'=>$site,'transport'=>$transport,'mode'=>$mode
        ];
        $existing['site_overrides'][$site][$transport]=$mode;
        $existing['revision']++;
        $existing['updated_at']=time();
        $existing['updated_by_wp_user_id']=get_current_user_id();
        $prior=get_option(self::EXECUTION_PREFERENCE_OPTION,[]);
        // Historical receipts stay bounded, without credentials or raw commands.
        $history=is_array($prior)&&is_array($prior['history']??null)
            ?array_slice($prior['history'],-19):[];
        $history[]=[
            'revision'=>$existing['revision'],'site_uuid'=>$site,
            'transport'=>$transport,'from'=>$previous,'to'=>$mode,
            'actor_wp_user_id'=>get_current_user_id(),'at'=>$existing['updated_at']
        ];
        $existing['history']=$history;
        update_option(self::EXECUTION_PREFERENCE_OPTION,$existing,false);
        $saved=get_option(self::EXECUTION_PREFERENCE_OPTION,[]);
        if(!is_array($saved) || (int)($saved['revision']??-1)!==$existing['revision']
            || ($saved['site_overrides'][$site][$transport]??null)!==$mode)
            return new WP_Error('mad4b_execution_preference_readback_failed','WordPress storage readback failed.');
        return [
            'saved'=>true,'changed'=>true,'revision'=>$existing['revision'],
            'site_uuid'=>$site,'transport'=>$transport,'mode'=>$mode,
            'original_broker_proof_required'=>$mode==='signed_broker',
            'write_granted'=>false
        ];
    }

    /**
     * These are ADDITIONAL requirements on top of an already authenticated
     * and exact-authorized write transport. A preference cannot turn an
     * unverified local stdio probe into executable authority.
     */
    private static function policy_evidence_ok(string $transport,array $facts):bool {
        $mode=self::mode_for_transport($transport);
        if($mode==='read_only')return false;
        if($mode==='registered_operator')return true;
        $prefix=$transport===self::VPS?'vps':
            ($transport===self::REST?'rest':'http');
        if($mode==='signed_broker')
            return ($facts[$prefix.'_signed_broker_verified']??null)===true;
        if($mode==='manual_approval')
            return ($facts[$prefix.'_manual_approval_verified']??null)===true;
        return false;
    }

    /** Pure bounded policy. Only code-controlled callers may supply evidence.
     * Client hints, WP-CLI --user and historical receipts are never proof.
     */
    public static function choose(string $effect,array $state):array {
        if(!in_array($effect,self::ALLOWED_EFFECTS,true))
            return ['contract'=>self::CONTRACT,'status'=>'HOLD_INVALID_EFFECT',
              'selected_transport'=>null,'retry_allowed'=>false];
        $outcome=$state['previous_attempt_outcome']??'not_started';
        if(!in_array($outcome,['not_started','confirmed_not_started',
            'unknown','partial','committed'],true))$outcome='unknown';
        if(in_array($outcome,['unknown','partial','committed'],true))
            return ['contract'=>self::CONTRACT,'effect'=>$effect,
                'status'=>$outcome==='committed'?'REQUIRE_ORIGINAL_WRITE_READBACK':'HOLD_UNCERTAIN_PREVIOUS_ATTEMPT',
                'selected_transport'=>null,'retry_allowed'=>false,
                'reason'=>'never_failover_after_possible_side_effect',
                'authority_created'=>false,'model_invoked'=>false];
        $write=$effect==='write';
        $httpRead=($state['http_read_authenticated']??null)===true;
        $vpsRead=($state['vps_read_verified']??null)===true;
        // Write authorization is additive, never a substitute for the
        // authenticated underlying transport and exact site read identity.
        $http=$httpRead && (!$write ||
            (($state['http_write_authenticated']??null)===true
              &&self::policy_evidence_ok(self::HTTP,$state)));
        $vps=$vpsRead && (!$write ||
            (($state['vps_exact_write_verified']??null)===true
              &&self::policy_evidence_ok(self::VPS,$state)));
        $restRead=($state['rest_read_authenticated']??null)===true;
        // REST is a full local/remote execution transport. Its write proof
        // must be independently bound to the exact registered REST operation,
        // authenticated target-site identity, and current mutation policy.
        // Plain REST discovery or a logged-in WordPress user is insufficient.
        $rest=$restRead && (!$write || (
            ($state['rest_exact_write_verified']??null)===true
            &&($state['rest_site_identity_verified']??null)===true
            &&($state['rest_operation_permission_verified']??null)===true
            &&self::policy_evidence_ok(self::REST,$state)
        ));
        // Registry-backed local stdio is a separate transport; a successful
        // read is not enough to dispatch any site mutation over this route.
        $selected=$http?self::HTTP:($vps?self::VPS:($rest?self::REST:null));
        $policy_modes=[
            self::HTTP=>self::mode_for_transport(self::HTTP),
            self::VPS=>self::mode_for_transport(self::VPS),
            self::REST=>self::mode_for_transport(self::REST)
        ];
        return ['contract'=>self::CONTRACT,'effect'=>$effect,
            'status'=>$selected?'READY':'HOLD_NO_VERIFIED_TRANSPORT',
            'selected_transport'=>$selected,
            'execution_preference_contract'=>self::EXECUTION_PREFERENCE_CONTRACT,
            'execution_preference_modes'=>$policy_modes,
            'preference_alone_grants_write'=>false,
            'ranked_channels'=>[
                ['id'=>self::HTTP,'priority'=>0,'status'=>$http?'ready':'unverified'],
                ['id'=>self::VPS,'priority'=>1,'status'=>$vps?'ready':
                    (($state['vps_read_verified']??false)===true && $write?'read_only_write_denied':'unverified')],
                ['id'=>self::REST,'priority'=>2,'status'=>$rest?
                    ($write?'ready_for_exact_write':'ready_read'):
                    (($state['rest_read_authenticated']??false)===true && $write
                        ?'read_verified_write_scope_missing':'unverified')]
            ],
            'retry_allowed'=>$selected!==null && ($outcome==='not_started'||$outcome==='confirmed_not_started'),
            'vps_read_grants_write'=>false,
            'rest_discovery_grants_write'=>false,
            'rest_write_requires_exact_endpoint_authority'=>true,
            'client_hint_grants_authority'=>false,
            'implicit_background_queue'=>false,
            'unknown_result_requires_reconciliation'=>true,
            'authority_created'=>false,'model_invoked'=>false];
    }

    /** Passive current-request snapshot; no network, no grant minting. */
    public static function current_request():array {
        $site=class_exists('MAD4B_SCP_Site_Profile',false)?
            MAD4B_SCP_Site_Profile::status():[];
        $bound=is_array($site)&&!empty($site['site_uuid'])
            &&!empty($site['origin_match'])&&!empty($site['deployment_binding_match'])
            &&!empty($site['authority_ready']);
        $mcp=$bound && class_exists('MAD4B_SCP_MCP_Request_Scope',false)
            &&MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()
            &&get_current_user_id()>0
            &&class_exists('MAD4B_SCP_Policy',false)
            &&MAD4B_SCP_Policy::can_read()
            &&MAD4B_SCP_Policy::can_connect_user(get_current_user_id());
        return ['contract'=>self::CONTRACT.'.current',
            'site_uuid'=>$site['site_uuid']??null,
            'site_binding_verified'=>$bound,
            'http_mcp_authenticated_current_request'=>$mcp,
            'http_write_authenticated_current_request'=>$mcp
                &&current_user_can('manage_options')
                &&MAD4B_SCP_Policy::can_mutate(),
            // Only a site-exact certified VPS adapter can supply write proof
            // at execution time. A host log or this passive report cannot.
            'vps_current_request_read_verified'=>false,
            'vps_current_request_write_verified'=>false,
            'rest_current_request_read_verified'=>false,
            'rest_current_request_write_verified'=>false,
            'historical_vps_read_is_authority'=>false,
            'vps_write_ready_from_readonly_portfolio_probe'=>false,
            'authority_created'=>false,
            'execution_preference_modes'=>[
                self::HTTP=>self::mode_for_transport(self::HTTP),
                self::VPS=>self::mode_for_transport(self::VPS),
                self::REST=>self::mode_for_transport(self::REST)
            ]];
    }

    /**
     * Bind one already-registered native REST write route to real WordPress
     * authentication, Site Profile, and the route's ORIGINAL permission
     * callback fetched from WordPress' own route registry. It never
     * executes the route itself or creates a grant. The registered route
     * still owns payload validation, revision and execution readback.
     */
    public static function rest_write_evidence($request):array {
        $denied=['rest_read_authenticated'=>false,
            'rest_exact_write_verified'=>false,
            'rest_site_identity_verified'=>false,
            'rest_operation_permission_verified'=>false,
            'reason'=>'rest_write_proof_unavailable'];
        if(!defined('REST_REQUEST') || REST_REQUEST!==true
            || !($request instanceof WP_REST_Request)
            || !in_array(strtoupper($request->get_method()),['POST','PUT','PATCH','DELETE'],true)
            || strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!==strtoupper($request->get_method())
            || !class_exists('MAD4B_SCP_Policy',false)
            || !class_exists('MAD4B_SCP_Site_Profile',false)
            || get_current_user_id()<1
            || !MAD4B_SCP_Policy::can_read()
            || !MAD4B_SCP_Policy::can_mutate())
            return $denied;
        $profile=MAD4B_SCP_Site_Profile::status();
        if(!is_array($profile)||empty($profile['site_uuid'])
            || empty($profile['origin_match'])||empty($profile['authority_ready'])
            || empty($profile['deployment_binding_match']))
            return $denied;
        // A WordPress CLI --user flag cannot pass any of these request
        // authentication methods, even when it has administrator privileges.
        $identity=class_exists('MAD4B_SCP_Identity_Context',false)
            ?MAD4B_SCP_Identity_Context::current():null;
        if(is_wp_error($identity))return $denied;
        if(!is_array($identity))$identity=[];
        $method=(string)($identity['auth_method']??'');
        $type=(string)($identity['subject_type']??'');
        $authorization=(string)$request->get_header('authorization');
        $isBearer=preg_match('/^Bearer\s+/i',$authorization)===1;
        $isBasic=preg_match('/^Basic\s+/i',$authorization)===1;
        if($authorization!=='' && !$isBearer && !$isBasic)return $denied;
        if($isBearer) {
            $authenticated=!empty($identity['authenticated'])
                &&(int)($identity['wp_user_id']??0)===get_current_user_id()
                &&$method==='oauth2_bearer'
                &&$type==='oauth'
                &&class_exists('MAD4B_SCP_OAuth_Resource_Bridge',false)
                &&MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active();
        } elseif($isBasic || ($authorization==='' &&
                 $method==='wp_application_password' && $type==='wp_app_password')) {
            // Upstream proxies can forward WordPress's authenticated
            // application-password identity without the original raw
            // Authorization header. Never require or inspect its secret.
            $authenticated=!empty($identity['authenticated'])
                &&(int)($identity['wp_user_id']??0)===get_current_user_id()
                &&$method==='wp_application_password' && $type==='wp_app_password';
        } else {
            // Local browser REST uses the standard WordPress REST nonce;
            // it does not need to pretend to be an OAuth subject.
            $nonce=$request->get_header('x-wp-nonce');
            $authenticated=is_user_logged_in()
                &&is_string($nonce)&&$nonce!==''
                &&wp_verify_nonce($nonce,'wp_rest')!==false;
        }
        if(!$authenticated)return $denied;
        // Resolve the permission callback from WordPress' actual registered
        // REST route, never a caller-supplied callback or claimed operation.
        $route=$request->get_route();
        $matches=[];
        $registry=rest_get_server()->get_routes();
        foreach($registry as $pattern=>$handlers){
            if(!is_string($pattern) || !is_array($handlers))continue;
            if(@preg_match('~^'.$pattern.'$~D',$route)!==1)continue;
            foreach($handlers as $handler){
                if(!is_array($handler)||empty($handler['methods'])
                    ||empty($handler['permission_callback'])
                    ||!is_callable($handler['permission_callback']))continue;
                $raw=$handler['methods'];
                $allowed=is_array($raw)?array_keys(array_filter($raw)):
                    preg_split('/[|, ]+/',(string)$raw);
                if(!in_array(strtoupper($request->get_method()),
                    array_map('strtoupper',$allowed),true))continue;
                if($handler['permission_callback']==='__return_true')continue;
                $matches[]=$handler['permission_callback'];
            }
        }
        if(count($matches)!==1)return $denied;
        try {$permitted=call_user_func($matches[0],$request);}
        catch(Throwable $e){return $denied;}
        if($permitted!==true)return $denied;
        return ['rest_read_authenticated'=>true,
            'rest_exact_write_verified'=>true,
            'rest_site_identity_verified'=>true,
            'rest_operation_permission_verified'=>true,
            'site_uuid'=>$profile['site_uuid'],
            'reason'=>'registered_endpoint_permission_and_authentication_verified',
            'authority_created'=>false];
    }

    /** Common projection for all MAD4B features and client integrations. */
    public static function manifest():array {
        return ['contract'=>self::CONTRACT,'site_independent'=>true,
            'provider_transport_independent'=>true,
            'order'=>[self::HTTP,self::VPS,self::REST],
            'read_channels'=>[self::HTTP,self::VPS,self::REST],
            'write_channels'=>[self::HTTP,self::VPS,self::REST],
            'rest_write_authority'=>[
                'authenticated_local_wordpress_nonce_or_site_application_password_or_verified_oauth',
                'target_site_uuid_and_environment',
                'exact_registered_rest_route_permission',
                'current_user_site_mutation_policy',
                'original_endpoint_validation_and_revision',
                'mutation_receipt_and_readback'
            ],
            'api_model_provider_is_not_transport'=>true,
            'vps_write_requires'=>[
                'authenticated_live_stdio_client','same_site_uuid',
                'current_source_and_job','exact_write_ability_grant',
                'bound_user_and_operation','site_write_policy','fresh_readback'
            ],
            'fallback_only_before_side_effect'=>true,
            'unknown_result_means_hold_not_retry'=>true,
            'no_implicit_background_queue'=>true,
            'capability_gateway_sources'=>[
                'mcp_http'=>'live_oauth_mcp_request',
                'mcp_stdio'=>'registered_vps_mcp_client',
                'rest_read'=>'authenticated_rest_gateway',
                'rest_write'=>'governed_native_rest_endpoint_exact_operation'
            ],
            'production_not_auto_authorized'=>true,
            'execution_preference_contract'=>self::EXECUTION_PREFERENCE_CONTRACT,
            'execution_preference_modes'=>[
                self::HTTP=>self::mode_for_transport(self::HTTP),
                self::VPS=>self::mode_for_transport(self::VPS),
                self::REST=>self::mode_for_transport(self::REST)
            ],
            'supported_proof_modes'=>self::EXECUTION_MODES,
            'broker_proof_optional_for_registered_operator'=>true,
            'preference_alone_grants_write'=>false,
            'authority_created'=>false,'execution_performed'=>false];
    }
}
