<?php
defined('ABSPATH') || exit;

/**
 * Transport-neutral authority resolution. Transport authentication is evidence,
 * not a grant. All mutations continue through the existing Agent Registry,
 * policy, exact grant, approval ticket and audit execution boundary.
 */
final class MAD4B_SCP_Execution_Authority {
    const CONTRACT = 'mad4b.execution-authority.v1';

    public static function resolve( $operation = 'read' ) {
        $operation = sanitize_key( (string) $operation );
        if ( ! in_array( $operation, array( 'read', 'plan', 'apply' ), true ) )
            return new WP_Error( 'mad4b_actor_operation_invalid', 'Unknown authority operation.' );
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! MAD4B_SCP_Site_Profile::configured()
            || ! MAD4B_SCP_Site_Profile::origin_enrolled()
            || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() )
            return new WP_Error( 'mad4b_actor_site_not_enrolled', 'Exact enrolled Site Profile is required.' );
        $site = (string) MAD4B_SCP_Site_Profile::site_uuid();
        $environment = sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() );
        if ( '' === $site || ! in_array( $environment, array('staging', 'development', 'local', 'production'), true) )
            return new WP_Error( 'mad4b_actor_site_invalid', 'Site identity is missing.' );
        if ( class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
            && MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) {
            $uid = get_current_user_id();
            $identity = class_exists('MAD4B_SCP_Identity_Context')
                ? MAD4B_SCP_Identity_Context::current() : null;
            if ( $uid < 1 || ! current_user_can('manage_options')
                || ! MAD4B_SCP_Site_Profile::user_is_enrolled($uid)
                || !is_array($identity) || empty($identity['authenticated'])
                || 'oauth' !== ($identity['subject_type'] ?? '')
                || 'oauth2_bearer' !== ($identity['auth_method'] ?? '')
                || empty($identity['subject_fingerprint'])
                || empty($identity['client_fingerprint']) )
                return new WP_Error('mad4b_actor_oauth_identity_invalid', 'Verified enrolled normal OAuth subject required.');
            return self::actor($site, $uid, $environment, 'oauth_mcp', $identity);
        }
        // A WP-CLI --user flag does not authenticate a host operator.
        // Only the privileged registered host runner may use this lane.
        if ( ! defined('WP_CLI') || true !== WP_CLI || 'cli' !== PHP_SAPI )
            return new WP_Error('mad4b_actor_transport_unverified', 'Verified transport required.');
        if ( ! function_exists('posix_geteuid') || 0 !== posix_geteuid()
            || 0 !== get_current_user_id() || isset($_SERVER['HTTP_AUTHORIZATION']) )
            return new WP_Error('mad4b_actor_host_operator_unverified', 'Local CLI must run as the privileged host executor without --user impersonation.');
        if ( ! class_exists('MAD4B_SCP_CLI_Execution_Context') )
            return new WP_Error('mad4b_actor_host_binding_unavailable', 'Registered host context is unavailable.');
        $facts = MAD4B_SCP_CLI_Execution_Context::resolve('authority.'.$operation, 'apply' === $operation);
        // The native context resolver may fail with WP_Error. Never index an
        // object as an array before proving its type, including on PHP 7.4.
        if ( ! is_array($facts) )
            return new WP_Error('mad4b_actor_host_binding_unverified', 'Host executor has no verified exact site and operation binding.',
                array('blockers'=>is_wp_error($facts)?array($facts->get_error_code()):array()));
        $ready = 'apply' === $operation ? !empty($facts['local_operation_authorized']) : !empty($facts['read_context_verified']);
        // Host identity is the pinned site/environment/origin and the
        // pre-existing Agent Registry NHI subject. A WordPress owner ID
        // must not become a substitute authentication credential.
        $profile_origin = rtrim( (string) MAD4B_SCP_Site_Profile::site_origin(), '/' );
        if ( !$ready || empty($facts['host_site_binding_verified'])
            || !empty($facts['blockers'])
            || ( 'apply' === $operation && !empty($facts['production_mutation_authorized'])
                && 'production' !== $environment )
            || $site !== (string)($facts['site_uuid'] ?? '')
            || $environment !== (string)($facts['environment'] ?? '')
            || '' === $profile_origin
            || !hash_equals($profile_origin, rtrim((string)($facts['canonical_origin']??''),'/')) )
            return new WP_Error('mad4b_actor_host_binding_unverified', 'Host executor has no verified exact site and operation binding.',
                array('blockers'=>(array)($facts['blockers']??array())));
        $fingerprint = hash('sha256', "mad4b.host-operator.v1\0"
            .$site."\0".$environment."\0".$profile_origin);
        $identity = array('authenticated'=>true,
            'subject_type'=>'host_operator',
            'subject_fingerprint'=>$fingerprint);
        if (!class_exists('MAD4B_SCP_Agent_Registry'))
            return new WP_Error('mad4b_actor_registry_unavailable',
                'Original Agent Registry is required to prove the Host NHI.');
        $agent = MAD4B_SCP_Agent_Registry::resolve_agent($identity);
        if (is_wp_error($agent)) {
            // Verified host diagnostics may inspect the missing NHI binding.
            // They never claim that the absent subject can perform a write.
            if ('apply' === $operation)
                return 'mad4b_nhi_subject_unbound' === $agent->get_error_code()
                    ? new WP_Error('mad4b_actor_registry_binding_required',
                        'Host execution requires the original registered NHI subject.')
                    : $agent;
            if ('mad4b_nhi_subject_unbound' !== $agent->get_error_code())
                return $agent;
            $actor = self::actor($site,0,$environment,'host_cli',$identity);
            $actor['registry_bound']=false;
            return $actor;
        }
        // Existing host NHI binding is exact and independently revocable.
        // Registry row's wp_user_id may exist for historic attribution,
        // but it is never used for Host authentication or capability checks.
        if (!is_array($agent)
            || (int)($agent['id']??0) < 1
            || 'enabled' !== (string)($agent['status']??'')
            || $environment !== (string)($agent['environment']??'')
            || !preg_match('/^[a-f0-9-]{36}$/D',(string)($agent['public_id']??'')))
            return new WP_Error('mad4b_actor_registry_binding_conflict',
                'Registered Host NHI is disabled, incomplete or has environment drift.');
        $actor = self::actor($site,0,$environment,'host_cli',$identity);
        $actor['registry_bound']=true;
        $actor['registered_host_agent_id']=(int)$agent['id'];
        $actor['registered_host_agent_public_id']=(string)$agent['public_id'];
        return $actor;
    }

    private static function actor($site,$uid,$environment,$transport,$identity) {
        return array(
            'contract'=>self::CONTRACT,
            'canonical_principal'=> 'host_cli' === $transport
                ? hash('sha256',"mad4b.host-principal.v1\0".$site."\0".$environment
                    ."\0".(string)$identity['subject_fingerprint'])
                : hash('sha256',"mad4b.canonical-principal.v1\0".$site."\0".$uid),
            'site_uuid'=>$site,
            'wp_user_id'=>(int)$uid,
            'environment'=>$environment,
            'transport'=>$transport,
            'subject_type'=>(string)$identity['subject_type'],
            'subject_fingerprint'=>(string)$identity['subject_fingerprint'],
            'authenticated'=>true,
            'grants_created'=>false,
            'execution_authorized'=>false,
            'mutation_performed'=>false,
        );
    }

    public static function require_access($operation='read',$environments=array('staging')) {
        $actor=self::resolve($operation);
        if(is_wp_error($actor)) return $actor;
        if(!in_array($actor['environment'],$environments,true))
            return new WP_Error('mad4b_actor_environment_denied','Operation is outside the approved environmental policy.');
        if(class_exists('MAD4B_SCP_Governed_Runtime_Gates')
            && MAD4B_SCP_Governed_Runtime_Gates::raw_sql_breakglass_enabled())
            return new WP_Error('mad4b_actor_breakglass_conflict','Generic raw SQL Breakglass must remain disabled.');
        if(class_exists('MAD4B_SCP_Policy') && MAD4B_SCP_Policy::can_breakglass())
            return new WP_Error('mad4b_actor_breakglass_conflict','Breakglass is not part of normal authority convergence.');
        return $actor;
    }
}
