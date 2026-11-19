<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exact first-party VPS-local host identity gates; no transport authority grant. */
trait MAD4B_SCP_Servers_VPS_Local_Identity_Trait {
	/**
	 * Local-only Registered Operator. No OAuth dependency and no Broker
	 * signature mandate in this mode, but a verified WP-CLI actor, exact site
	 * binding and the existing WordPress Ability permissions are mandatory.
	 * This does not grant remote access or activate Production execution.
	 */
	/** Explicit local STDIO command, not a generic CLI or HTTP request. */
	private static function vps_local_stdio_requested() {
		if ( ! defined( 'WP_CLI' ) || true !== WP_CLI
			|| 'cli' !== PHP_SAPI
			|| ! isset( $_SERVER['argv'] ) || ! is_array( $_SERVER['argv'] ) )
			return false;
		$args = $_SERVER['argv'];
		for ( $i = 0; $i + 1 < count( $args ); $i++ ) {
			if ( 'mcp-adapter' !== (string) $args[$i]
				|| 'serve' !== (string) $args[$i+1] ) continue;
			for ( $j = $i + 2; $j < count( $args ); $j++ ) {
				$arg = (string) $args[$j];
				if ( '--server=mad4b-vps-local' === $arg ) return true;
				if ( '--server' === $arg && isset( $args[$j+1] )
					&& 'mad4b-vps-local' === (string) $args[$j+1] ) return true;
			}
		}
		return false;
	}

	/**
     * Native read uses existing pinned CLI host/site proof, not WP identity.
     * This check cannot enqueue, create a grant, or authorize writes.
     */
    private static function vps_local_read_verified() {
        if ( ! self::vps_local_stdio_requested()
            || ! function_exists('posix_geteuid') || 0 !== posix_geteuid()
            || 0 !== get_current_user_id()
            || isset($_SERVER['HTTP_AUTHORIZATION'])
            || ! class_exists('MAD4B_SCP_CLI_Execution_Context')
            || ! class_exists('MAD4B_SCP_Capability_Transport_Routing')
            || ! class_exists('MAD4B_Hub_Universal_VPS_V1')
            || ! class_exists('MAD4B_SCP_Site_Profile')
            || 'registered_operator' !==
                MAD4B_SCP_Capability_Transport_Routing::mode_for_transport(
                    MAD4B_SCP_Capability_Transport_Routing::VPS )
            || ! MAD4B_Hub_Universal_VPS_V1::scoped() ) return false;
        $facts = MAD4B_SCP_CLI_Execution_Context::resolve('authority.read',false);
        $profile = MAD4B_SCP_Site_Profile::status();
        // The original enrolled Site Profile owns environment identity, not
        // an inherited fixed 'staging' assumption. A production CLI can read
        // only after its *independent* site/host proof is verified; the
        // separate registered Host apply/write gate is not relaxed here.
        return is_array($facts) && is_array($profile)
            && !empty($facts['read_context_verified'])
            && !empty($facts['host_site_binding_verified'])
            && empty($facts['blockers'])
            && !empty($profile['configured'])
            && !empty($profile['authority_ready'])
            && !empty($profile['same_origin_clone_protection'])
            && !empty($profile['deployment_binding_match'])
            && !empty($profile['environment_match'])
            && !empty($profile['origin_match'])
            && empty($profile['mutation_pending_audit'])
            && is_string($facts['environment']??null)
            && '' !== $facts['environment']
            && hash_equals((string)$profile['configured_environment'],
                $facts['environment'])
            && hash_equals((string)$profile['environment'],
                $facts['environment'])
            && hash_equals((string)MAD4B_SCP_Site_Profile::site_uuid(),
                (string)($facts['site_uuid']??''))
            && hash_equals((string)$profile['canonical_origin'],
                (string)($facts['canonical_origin']??''))
            && hash_equals((string)$profile['canonical_origin'],
                untrailingslashit(home_url('/')));
    }

    /** Host access requires the existing, registry-bound non-impersonating actor. */
	private static function vps_local_actor_verified() {
		// Resolving a host actor may consult the request identity filter.
		// Recursive calls must deny, not re-enter the same host lookup.
		static $checking = false;
		if ( $checking ) return false;
		$checking = true;
		try {
		if ( ! self::vps_local_stdio_requested()
			|| ! function_exists( 'posix_geteuid' ) || 0 !== posix_geteuid()
			|| 0 !== get_current_user_id()
			|| ! class_exists( 'MAD4B_SCP_Execution_Authority' )
			|| ! class_exists( 'MAD4B_SCP_Capability_Transport_Routing' )
			|| ! class_exists( 'MAD4B_Hub_Universal_VPS_V1' )
			|| ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return false;
		if ( 'registered_operator' !==
			MAD4B_SCP_Capability_Transport_Routing::mode_for_transport(
				MAD4B_SCP_Capability_Transport_Routing::VPS ) ) return false;
		if ( ! MAD4B_Hub_Universal_VPS_V1::scoped() ) return false;
		$actor = MAD4B_SCP_Execution_Authority::resolve( 'apply' );
		$profile = MAD4B_SCP_Site_Profile::status();
		// An otherwise trusted Staging Host NHI must not be carried over
		// to an enrolled Production site by a stale CLI/registry assertion.
		// Real Host write admission requires exact current Site Profile proof.
		if ( ! is_array($profile)
			|| empty($profile['configured'])
			|| empty($profile['authority_ready'])
			|| empty($profile['same_origin_clone_protection'])
			|| empty($profile['deployment_binding_match'])
			|| empty($profile['environment_match'])
			|| empty($profile['origin_match'])
			|| !empty($profile['mutation_pending_audit']) ) return false;
		if ( ! is_array( $actor ) || empty( $actor['authenticated'] )
			|| empty( $actor['registry_bound'] )
			|| ( $actor['transport'] ?? '' ) !== 'host_cli'
			|| ( $actor['subject_type'] ?? '' ) !== 'host_operator'
			|| ( $actor['environment'] ?? '' ) !== 'staging'
			|| (string)($profile['configured_environment']??'') !== (string)$actor['environment']
			|| (string)($profile['environment']??'') !== (string)$actor['environment']
			|| (string)($profile['site_uuid']??'') !== (string)($actor['site_uuid']??'')
			|| ! is_string( $actor['subject_fingerprint'] ?? null )
			|| ! preg_match( '/^[a-f0-9]{64}$/D', $actor['subject_fingerprint'] )
			|| ! MAD4B_SCP_Site_Profile::origin_enrolled()
			|| ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
			|| ! hash_equals( (string) MAD4B_SCP_Site_Profile::site_uuid(),
				(string) ( $actor['site_uuid'] ?? '' ) ) ) return false;
		// Registered Host NHI is independent of WordPress user identity.
		// The original exact Agent Registry and per-Ability grant/approval
		// execution boundary still re-validate this subject before mutation.
		return 0 === (int) ( $actor['wp_user_id'] ?? -1 )
			&& (int) ( $actor['registered_host_agent_id'] ?? 0 ) > 0
			&& 1 === preg_match( '/^[a-f0-9-]{36}$/D',
				(string) ( $actor['registered_host_agent_public_id'] ?? '' ) );

		} finally {
			$checking = false;
		}
	}

	/** The subject is reused, never generated or self-enrolled on MCP requests. */
	public static function vps_local_identity_context( $context ) {
		if ( ! is_array( $context ) || ! empty( $context['authenticated'] )
			|| ! self::vps_local_actor_verified() ) return $context;
		$actor = MAD4B_SCP_Execution_Authority::resolve( 'apply' );
		if ( ! is_array( $actor ) || empty( $actor['registry_bound'] ) ) return $context;
		$context['authenticated'] = true;
		$context['subject_type'] = 'host_operator';
		$context['subject_fingerprint'] = (string) $actor['subject_fingerprint'];
		$context['auth_method'] = 'host_cli';
		// An NHI is never promoted to an authenticated WordPress account.
		$context['wp_user_id'] = 0;
		$context['origin'] = 'vps_local_stdio';
		$context['token_scopes'] = array();
		return $context;
	}
    /** Write requires the pre-existing exact Agent Registry apply binding. */
    public static function can_vps_local_write_transport() {
        return self::vps_local_actor_verified() === true;
    }

	public static function can_vps_local_transport( $request = null ) {
		if ( ! self::vps_local_read_verified() && ! self::vps_local_actor_verified() )
			return new WP_Error( 'mad4b_vps_local_identity_required',
				'Exact registered Host Operator and Staging site required.',
				array( 'status' => 403 ) );
		return true;
	}
}
