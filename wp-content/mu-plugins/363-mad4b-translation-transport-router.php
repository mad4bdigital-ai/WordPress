<?php
/**
 * Plugin Name: MAD4B Translation Transport Routing
 * Description: HTTP MCP and governed VPS stdio MCP are transport choices, never translation models or implicit write grants.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

/**
 * Operator-reviewed transport adapters are registered by code, not by
 * a ContentJob, remote request, WordPress option or client-controlled header.
 * They cannot turn a read-only VPS probe into write authorization.
 */
interface MAD4B_Translation_Transport_Adapter {
    public function transport_id(): string;
    public function preflight(array $request): array;
}

final class MAD4B_Translation_Transport_Router {
    const CONTRACT = 'mad4b.translation-transport-routing.v1';
    const MAX_ADAPTERS = 8;
    const HTTPS_ID = 'wordpress_mcp_https';
    const VPS_ID = 'vps_mcp_stdio';
    const REST_ID = 'rest_gateway';

    private static function adapters(): array {
        $raw = apply_filters('mad4b/translation/transport_adapters',[]);
        if(!is_array($raw)||count($raw)>self::MAX_ADAPTERS)return [];
        $items=[];$duplicate=[];
        foreach($raw as $a){
            if(!$a instanceof MAD4B_Translation_Transport_Adapter)continue;
            try {$id=$a->transport_id();}catch(Throwable $e){continue;}
            if(!in_array($id,[self::VPS_ID,self::REST_ID],true))continue;
            if(isset($items[$id])||isset($duplicate[$id])){
                unset($items[$id]);$duplicate[$id]=true;continue;
            }
            $items[$id]=$a;
        }
        return $items;
    }

    /**
     * Readiness is derived from an independently connected authenticated MCP
     * client *in the current request*. Neither a historical host receipt, nor
     * WP-CLI --user, nor a boolean argument from a tool call proves an active
     * ChatGPT session, a site grant or remote write eligibility.
     *
     * The VPS adapter must obtain trusted, generation-bound evidence from its
     * already-authorized broker/client runtime. This module does not mint it.
     */
    private static function vps_preflight(array $request): array {
        $adapters=self::adapters();
        if(!isset($adapters[self::VPS_ID]))
            return ['status'=>'read_only_or_unbound',
                'read_verified_in_current_request'=>false,
                'write_certified_in_current_request'=>false,
                'reason'=>'vps_client_write_authority_not_proven'];
        try {$p=$adapters[self::VPS_ID]->preflight($request);}
        catch(Throwable $e){$p=[];}
        if(!is_array($p))$p=[];
        $identity=class_exists('MAD4B_SCP_Site_Profile')
            ?MAD4B_SCP_Site_Profile::status():[];
        $id=is_array($identity)?($identity['site_uuid']??''):'';
        $read=($p['connected']??null)===true
            &&($p['authenticated_mcp_stdio']??null)===true
            &&($p['current_request_bound']??null)===true
            &&($p['read_ability_verified']??null)===true
            &&($p['site_uuid']??null)===$id
            &&is_string($p['transport_generation_sha256']??null)
            &&(bool)preg_match('/^[a-f0-9]{64}$/D',$p['transport_generation_sha256'])
            &&class_exists('MAD4B_SCP_Policy')
            &&MAD4B_SCP_Policy::can_read();
        $write=$read &&($p['write_ability_verified']??null)===true
            &&($p['exact_user_grant_verified']??null)===true
            &&($p['current_client_session_bound']??null)===true
            &&($p['same_job_authorized']??null)===true
            &&($p['source_sha256']??null)===($request['source_sha256']??'')
            &&is_string($p['write_scope_sha256']??null)
            &&(bool)preg_match('/^[a-f0-9]{64}$/D',$p['write_scope_sha256'])
            &&MAD4B_SCP_Policy::can_mutate()
            &&current_user_can('manage_options');
        return ['status'=>$write?'ready_for_exact_write':($read?'read_verified_write_held':'read_only_or_unbound'),
            'read_verified_in_current_request'=>$read,
            'write_certified_in_current_request'=>$write,
            'reason'=>$write?'current_stdio_mcp_and_exact_write_scope_proven':
                ($read?'vps_stdio_read_does_not_grant_write':'vps_client_write_authority_not_proven')];
    }

    /**
     * REST write requires evidence from the current registered WordPress REST
     * route's native permission callback. An adapter cannot self-assert an
     * authenticated REST write session: core verifies it from WP_REST_Request.
     */
    private static function rest_preflight(array $source):array {
        $adapters=self::adapters();
        if(!isset($adapters[self::REST_ID]))return [];
        try {$result=$adapters[self::REST_ID]->preflight($source);}
        catch(Throwable $e){return [];}
        if(!is_array($result)||!($result['current_rest_request']??null) instanceof WP_REST_Request
            ||!class_exists('MAD4B_SCP_Capability_Transport_Routing')
            ||($result['current_client_session_bound']??null)!==true
            ||($result['model_provider']??null)!=='chatgpt_mcp_session'
            ||($result['job_id']??null)!==($source['job_id']??null)
            ||($result['source_sha256']??null)!==($source['source_sha256']??null))return [];
        $e=MAD4B_SCP_Capability_Transport_Routing::rest_write_evidence($result['current_rest_request']);
        if(($e['rest_exact_write_verified']??false)!==true ||
          ($e['site_uuid']??null)!==(MAD4B_SCP_Site_Profile::site_uuid()))return [];
        return $e;
    }

    /**
     * Pure policy: tested without network or authority. Never infer a write
     * grant from a successful read-only MCP connection.
     */
    public static function choose(bool $http_authenticated,array $vps,array $rest=[]):array {
        $vpsWrite=($vps['write_certified_in_current_request']??null)===true;
        $vpsRead=($vps['read_verified_in_current_request']??null)===true;
        // Delegate route selection to the same central policy used by
        // Capability Gateway, Hybrid MCP, Broker and future features.
        if(!class_exists('MAD4B_SCP_Capability_Transport_Routing'))
            return ['preferred_model_provider'=>'chatgpt_mcp_session',
                'selected_transport'=>null,'transport_state'=>'HOLD_CENTRAL_POLICY_MISSING',
                'vps_read_grants_write'=>false,'no_implicit_translation_queue'=>true];
        $central=MAD4B_SCP_Capability_Transport_Routing::choose('write',[
            'http_read_authenticated'=>$http_authenticated,
            'http_write_authenticated'=>$http_authenticated,
            'vps_read_verified'=>$vpsRead,
            'vps_exact_write_verified'=>$vpsWrite,
            'rest_read_authenticated'=>($rest['rest_read_authenticated']??false)===true,
            'rest_exact_write_verified'=>($rest['rest_exact_write_verified']??false)===true,
            'rest_site_identity_verified'=>($rest['rest_site_identity_verified']??false)===true,
            'rest_operation_permission_verified'=>($rest['rest_operation_permission_verified']??false)===true,
            'previous_attempt_outcome'=>'not_started'
        ]);
        $selected=$central['selected_transport']??null;
        return [
            'central_policy'=>$central,
            'preferred_model_provider'=>'chatgpt_mcp_session',
            'selected_transport'=>$selected,
            'transport_state'=>$selected?'READY':'HOLD_MCP_TRANSPORT',
            'channels'=>[
                ['id'=>self::HTTPS_ID,'priority'=>0,
                    'mode'=>'streamable_http_oauth',
                    'status'=>$http_authenticated?'authenticated_for_current_request':'no_authenticated_current_request'],
                ['id'=>self::VPS_ID,'priority'=>1,
                    'mode'=>'local_stdio_mcp',
                    'status'=>$vpsWrite?'ready_for_exact_write':
                        ($vpsRead?'read_only_write_held':'write_not_certified_or_client_unbound')],
                ['id'=>self::REST_ID,'priority'=>2,'mode'=>'governed_native_rest',
                    'status'=>($rest['rest_exact_write_verified']??false)===true?
                    'ready_for_exact_write':'no_registered_authenticated_write_proof']
            ],
            'safe_fallback'=>['http_unavailable_before_write'=>'vps_only_after_exact_current_write_authorization',
                'vps_write_unavailable'=>'registered_authenticated_rest_write_or_connected_consented_api_or_hold',
                'unknown_or_partial_write'=>'hold_for_reconciliation_never_retry'],
            'client_session_required'=>true,
            'vps_read_grants_write'=>false,
            'no_implicit_translation_queue'=>true
        ];
    }

    public static function plan(array $request,bool $http_authenticated):array {
        $site=class_exists('MAD4B_SCP_Site_Profile')?
            MAD4B_SCP_Site_Profile::status():[];
        $vps=self::vps_preflight($request);
        $rest=self::rest_preflight($request);
        $chosen=self::choose($http_authenticated,$vps,$rest);
        return [
            'contract'=>self::CONTRACT.'.plan',
            'site_uuid'=>$site['site_uuid']??null,
            'issue_id'=>$request['issue_id']??null,
            'job_id'=>$request['job_id']??null,
            'source_sha256'=>$request['source_sha256']??null,
            'routing'=>$chosen,
            'vps_preflight'=>$vps,
            'rest_preflight'=>$rest,
            'http_request_authenticated'=>$http_authenticated,
            'vps_historical_read_is_not_write_proof'=>true,
            'no_authority_created'=>true,
            'translation_performed'=>false
        ];
    }

    public static function cli($args,$flags) {
        $op=$args[0]??'';
        if($op!=='fixture')WP_CLI::error('Usage: wp mad4b translation-transport fixture');
        $ready=['read_verified_in_current_request'=>true,'write_certified_in_current_request'=>true];
        $read=['read_verified_in_current_request'=>true,'write_certified_in_current_request'=>false];
        $a=self::choose(true,$ready);
        $b=self::choose(false,$ready);
        $c=self::choose(false,$read);
        $d=self::choose(false,[]);
        $proof=['rest_read_authenticated'=>true,'rest_exact_write_verified'=>true,
          'rest_site_identity_verified'=>true,'rest_operation_permission_verified'=>true];
        $restOk=self::choose(false,[],$proof);
        $restMissing=self::choose(false,[],[
          'rest_read_authenticated'=>true,'rest_exact_write_verified'=>true]);
        $pass=$restOk['selected_transport']===self::REST_ID
            &&$restMissing['selected_transport']===null
            &&$a['selected_transport']===self::HTTPS_ID
            &&$b['selected_transport']===self::VPS_ID
            &&$c['selected_transport']===null
            &&$d['selected_transport']===null
            &&$c['vps_read_grants_write']===false;
        WP_CLI::line(wp_json_encode(['pass'=>$pass,
            'http_preferred'=>$a['selected_transport'],
            'vps_write_fallback'=>$b['selected_transport'],
            'vps_read_only'=>$c['transport_state'],
            'no_transport'=>$d['transport_state'],
            'rest_exact_write'=>$restOk['selected_transport'],
            'rest_without_scope'=>$restMissing['transport_state'],
            'default_queue'=>false]));
        if(!$pass)WP_CLI::error('transport_fixture_failed');
    }
}
if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b translation-transport',[MAD4B_Translation_Transport_Router::class,'cli']);
