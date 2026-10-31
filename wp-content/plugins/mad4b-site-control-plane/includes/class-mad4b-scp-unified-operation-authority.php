<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Transport-independent operation policy. Authentication happens FIRST at
 * a trusted source adapter; this class is not an authentication provider.
 *
 * The assess_trusted_boundary() inputs may ONLY originate in server-side
 * verified OAuth/MCP, SSH/WP-CLI host attestation or Broker 312 code.
 * NEVER expose it as a REST/MCP ability accepting caller-supplied identities,
 * booleans, grants or commands.
 *
 * No execution, approval, option writes or grant creation happens here.
 */
final class MAD4B_SCP_Unified_Operation_Authority {
    const CONTRACT = 'mad4b.unified-operation-authority-decision.v1';
    const CHANNEL_OAUTH = 'oauth_mcp';
    const CHANNEL_SSH = 'vps_ssh_wpcli';
    const CHANNEL_BROKER = 'signed_broker';

    private static function hash_ok( $value, $n ) {
        return is_string( $value ) &&
            ( 1 === preg_match( '/^[a-f0-9]{' . (int) $n . '}$/D', $value ) );
    }

    private static function uuid_ok( $value ) {
        return is_string( $value ) &&
            1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $value );
    }

    private static function stable_equal( $a, $b ) {
        return is_string( $a ) && is_string( $b ) && '' !== $a &&
            '' !== $b && hash_equals( $a, $b );
    }

    /**
     * This is a deterministic decision helper for trusted server adapters.
     * Callers cannot make a proof valid by asserting authenticated=true.
     * A real adapter must independently produce identity AND authorization
     * in its own transport boundary before calling this policy.
     *
     * @param array $principal Server-verified normalized Identity Context and bound agent.
     * @param array $operation Target derived by the trusted dispatcher, not client JSON.
     * @param array $authority Exact, server-refreshed, deny-first grant from MAD4B registry / Broker 312.
     */
    public static function assess_trusted_boundary( $principal, $operation, $authority ) {
        $deny = array();
        if ( ! is_array( $principal ) || ! is_array( $operation ) || ! is_array( $authority ) ) {
            return array( 'contract' => self::CONTRACT, 'eligible' => false,
                'blockers' => array( 'trusted_boundary_inputs_missing' ),
                'execution_authorized' => false, 'mutation_performed' => false );
        }
        $channel = (string) ( $principal['auth_channel'] ?? '' );
        $providers = array(
            self::CHANNEL_OAUTH => 'identity_context_agent_registry',
            self::CHANNEL_SSH => 'host_verified_ssh_wpcli_identity',
            self::CHANNEL_BROKER => 'central_authority_verifier_312',
        );
        if ( ! isset( $providers[$channel] ) ||
            ! self::stable_equal( $providers[$channel], $principal['verified_by'] ?? '' ) ||
            true !== ( $principal['transport_verified'] ?? null ) )
            $deny[] = 'transport_identity_unverified';

        if ( true !== ( $principal['authenticated'] ?? null ) ||
            ! self::hash_ok( $principal['subject_fingerprint'] ?? null, 64 ) ||
            ! self::uuid_ok( $principal['agent_public_id'] ?? null ) ||
            ! self::uuid_ok( $principal['site_uuid'] ?? null ) )
            $deny[] = 'authenticated_subject_or_agent_unbound';

        $environment = (string) ( $operation['target_environment'] ?? '' );
        $ability = (string) ( $operation['ability_name'] ?? '' );
        $provider = (string) ( $operation['provider'] ?? '' );
        $server_id = (string) ( $operation['server_id'] ?? '' );
        if ( ! in_array( $environment, array( 'staging', 'production' ), true ) ||
            ! self::uuid_ok( $operation['site_uuid'] ?? null ) ||
            ! self::hash_ok( $operation['command_sha256'] ?? null, 64 ) ||
            ! preg_match( '/^mad4b\/[a-z0-9][a-z0-9._-]{1,127}$/D', $ability ) ||
            ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/D', $provider ) ||
            ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,127}$/D', $server_id ) )
            $deny[] = 'operation_identity_invalid';
        if ( ! self::stable_equal( $operation['site_uuid'] ?? '', $principal['site_uuid'] ?? '' ) ||
            ! self::stable_equal( $environment, $principal['target_environment'] ?? '' ) )
            $deny[] = 'company_or_environment_mismatch';

        if ( true !== ( $authority['verified_by_registry'] ?? null ) ||
            ( $authority['effect'] ?? '' ) !== 'allow' ||
            ( $authority['status'] ?? '' ) !== 'enabled' ||
            ! self::stable_equal( $authority['agent_public_id'] ?? '', $principal['agent_public_id'] ?? '' ) ||
            ! self::stable_equal( $authority['site_uuid'] ?? '', $operation['site_uuid'] ?? '' ) ||
            ! self::stable_equal( $authority['environment'] ?? '', $environment ) ||
            ! self::stable_equal( $authority['ability_name'] ?? '', $ability ) ||
            ! self::stable_equal( $authority['provider'] ?? '', $provider ) ||
            ! self::stable_equal( $authority['server_id'] ?? '', $server_id ) ||
            ! self::stable_equal( $authority['command_sha256'] ?? '', $operation['command_sha256'] ?? '' ) )
            $deny[] = 'exact_active_grant_not_verified';

        $now = time();
        // Standing grants are first-class. They may be indefinite when the
        // existing authority store explicitly marks them standing and checks
        // revocation on EACH execution, rather than forcing repeated approvals.
        $valid_from = $authority['valid_from'] ?? null;
        $valid_until = $authority['valid_until'] ?? null;
        $standing = true === ( $authority['standing_grant'] ?? null );
        if ( ! is_int( $valid_from ) || $valid_from > $now ||
            ! ( ( is_int( $valid_until ) && $valid_until >= $now &&
                  $valid_until >= $valid_from ) ||
                ( null === $valid_until && $standing ) ) )
            $deny[] = 'grant_expired_or_unbounded';

        if ( true !== ( $authority['company_delegation_verified'] ?? null ) ||
            true !== ( $authority['revocation_checked'] ?? null ) ||
            true !== ( $authority['deny_override_checked'] ?? null ) )
            $deny[] = 'delegation_or_deny_first_state_unverified';

        // Identical Production and Staging capability surface, but a distinct
        // target/environment-specific grant and current environment risk gate.
        if ( 'production' === $environment &&
            true !== ( $authority['production_runtime_gate_verified'] ?? null ) )
            $deny[] = 'production_runtime_authority_not_verified';

        $deny = array_values( array_unique( $deny ) );
        sort( $deny, SORT_STRING );
        return array(
            'contract' => self::CONTRACT,
            'auth_channel' => $channel,
            'source_proof' => (string) ( $principal['verified_by'] ?? '' ),
            'target_environment' => $environment,
            'target_site_uuid' => (string) ( $operation['site_uuid'] ?? '' ),
            'ability_name' => $ability,
            'company_staging_required' => false,
            'package_certificate_required' => false,
            'same_policy_across_auth_channels' => true,
            'eligible' => empty( $deny ),
            'blockers' => $deny,
            // This decision alone is NOT a Broker-issued dispatch token.
            'execution_authorized' => false,
            'dispatch_eligible' => false,
            'mutation_performed' => false,
        );
    }

    /**
     * Reuse the existing server-resolved Identity Context and deny-first agent
     * registry across authenticated transports. Never infer SSH proof merely
     * because a WP-CLI command supplies --user.
     */
    public static function current_registered_identity_plan( $operation ) {
        if ( ! is_array( $operation ) || ! class_exists( 'MAD4B_SCP_Identity_Context' ) ||
            ! class_exists( 'MAD4B_SCP_Agent_Registry' ) )
            return new WP_Error( 'mad4b_unified_authority_backend_missing', 'Existing identity registry is unavailable.' );
        $identity = MAD4B_SCP_Identity_Context::current();
        if ( is_wp_error( $identity ) || empty( $identity['authenticated'] ) )
            return new WP_Error( 'mad4b_unified_authority_unauthenticated', 'An authenticated server-derived identity is required.' );
        $agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
        if ( is_wp_error( $agent ) ) return $agent;

        // Existing exact_grant() remains the mandatory deny-first, environment-
        // scoped registry lookup. This method is for read-only assessment;
        // the mutation gate is not replaced or bypassed.
        $grant = MAD4B_SCP_Agent_Registry::exact_grant(
            (int) $agent['id'],
            (string) ( $operation['server_id'] ?? '' ),
            (string) ( $operation['ability_name'] ?? '' ),
            (string) ( $operation['provider'] ?? '' )
        );
        if ( is_wp_error( $grant ) ) return $grant;
        return array(
            'contract' => self::CONTRACT,
            'identity_source' => 'current_server_verified_identity_context',
            'authenticated' => true,
            'agent_public_id' => (string) ( $agent['public_id'] ?? '' ),
            'subject_fingerprint' => (string) ( $identity['subject_fingerprint'] ?? '' ),
            'grant_lookup' => 'existing_deny_first_exact_grant',
            'exact_grant_found' => true,
            'runtime_mutation_policy_still_required' => true,
            'production_mutation_authorized' => false,
            'execution_authorized' => false,
            'mutation_performed' => false,
        );
    }
}
