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
    const CONTRACT='mad4b.central-capability-transport-routing.v1';
    const HTTP='wordpress_mcp_https';
    const VPS='vps_mcp_stdio';
    const REST='rest_gateway';
    const ALLOWED_EFFECTS=['read','write'];

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
        $http=$httpRead && (!$write || ($state['http_write_authenticated']??null)===true);
        $vps=$vpsRead && (!$write || ($state['vps_exact_write_verified']??null)===true);
        $rest=(!$write && ($state['rest_read_authenticated']??null)===true);
        // Registry-backed local stdio is a separate transport; a successful
        // read is not enough to dispatch any site mutation over this route.
        $selected=$http?self::HTTP:($vps?self::VPS:($rest?self::REST:null));
        return ['contract'=>self::CONTRACT,'effect'=>$effect,
            'status'=>$selected?'READY':'HOLD_NO_VERIFIED_TRANSPORT',
            'selected_transport'=>$selected,
            'ranked_channels'=>[
                ['id'=>self::HTTP,'priority'=>0,'status'=>$http?'ready':'unverified'],
                ['id'=>self::VPS,'priority'=>1,'status'=>$vps?'ready':
                    (($state['vps_read_verified']??false)===true && $write?'read_only_write_denied':'unverified')],
                ['id'=>self::REST,'priority'=>2,'status'=>$rest?'ready_read_only':
                    ($write?'not_a_write_fallback':'unverified')]
            ],
            'retry_allowed'=>$selected!==null && ($outcome==='not_started'||$outcome==='confirmed_not_started'),
            'vps_read_grants_write'=>false,
            'rest_discovery_grants_write'=>false,
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
            'historical_vps_read_is_authority'=>false,
            'vps_write_ready_from_readonly_portfolio_probe'=>false,
            'authority_created'=>false];
    }

    /** Common projection for all MAD4B features and client integrations. */
    public static function manifest():array {
        return ['contract'=>self::CONTRACT,'site_independent'=>true,
            'provider_transport_independent'=>true,
            'order'=>[self::HTTP,self::VPS,self::REST],
            'read_channels'=>[self::HTTP,self::VPS,self::REST],
            'write_channels'=>[self::HTTP,self::VPS],
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
                'rest_read'=>'authenticated_rest_gateway'
            ],
            'production_not_auto_authorized'=>true,
            'authority_created'=>false,'execution_performed'=>false];
    }
}
