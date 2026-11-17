<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only handoff from verified central release to existing native Broker.
 *
 * This component does NOT execute Shell or authorize a Production write.
 * Native authority verifier 312 and remote readback 313 are external contracts.
 * Do not infer their implementation/API from module IDs alone.
 */
final class MAD4B_SCP_Central_Operator_Handoff {
    const CONTRACT = 'mad4b.central-native-operator-handoff-plan.v1';
    const ABILITY  = 'mad4b/central-native-operator-handoff-plan';

    public static function boot() {
        // One policy grammar for OAuth/MCP, host-attested SSH/WP-CLI and
        // Broker 312. This is not a shortcut around the transport verifier.
        require_once __DIR__ . '/class-mad4b-scp-unified-operation-authority.php';
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 40 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ||
            ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Plan Central Release Native Operator Handoff',
            'description' => 'Read-only contract for existing Broker verifier and readback; no SSH execution or Production authorization.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'plan' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array(
                'type' => 'object', 'additionalProperties' => false,
                'required' => array( 'release_sha256' ),
                'properties' => array(
                    'release_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                ),
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    public static function plan( $input = array() ) {
        if ( ! is_array( $input ) || array_keys( $input ) !== array( 'release_sha256' ) ||
            ! is_string( $input['release_sha256'] ) ||
            1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['release_sha256'] ) )
            return new WP_Error( 'mad4b_operator_handoff_input_invalid', 'Only exact artifact SHA-256 is accepted.' );
        // This executes only the company's live, read-only compatibility plan.
        // It independently fetches and validates central evidence server-side.
        $company = MAD4B_SCP_Central_Company_Rollout::plan( $input );
        if ( is_wp_error( $company ) ) return $company;
        $receipt = MAD4B_SCP_Central_Release_Registry::verified_readback( $input['release_sha256'] );
        return self::describe( $company, $receipt );
    }

    /**
     * Pure metadata only: caller-supplied objects cannot authorize execution.
     * Actual Broker 312 must bind authenticated operator/claim/grant/delegation
     * and command digest. The remote executor and module 313 must read back
     * source/target and verify the postcondition before declaring success.
     */
    public static function describe( $company, $receipt ) {
        $blockers = array();
        if ( ! is_array( $company ) || ! is_array( $receipt ) ||
            ( $company['contract'] ?? '' ) !== MAD4B_SCP_Central_Company_Rollout::CONTRACT ||
            true !== ( $company['central_release_readback_verified'] ?? null ) ||
            true !== ( $receipt['registry_readback_verified'] ?? null ) )
            $blockers[] = 'unverified_central_and_company_evidence';

        if ( ! is_array( $company ) || ! empty( $company['blockers'] ) ||
            true !== ( $company['ready_for_separate_owner_authorization'] ?? null ) )
            $blockers[] = 'company_compatibility_not_eligible';

        $artifact = is_array( $company ) ? (string) ( $company['central_release_sha256'] ?? '' ) : '';
        $receipt_hash = is_array( $receipt ) ? (string) ( $receipt['artifact_sha256'] ?? '' ) : '';
        $commit = is_array( $receipt ) ? (string) ( $receipt['source_commit'] ?? '' ) : '';
        $manifest = is_array( $receipt ) ? (string) ( $receipt['file_manifest_sha256'] ?? '' ) : '';
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $artifact ) ||
            ! hash_equals( $artifact, $receipt_hash ) ||
            ! preg_match( '/^[a-f0-9]{40}$/D', $commit ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $manifest ) )
            $blockers[] = 'immutable_release_identity_mismatch';

        $uuid = is_array( $company ) ? (string) ( $company['company_site_uuid'] ?? '' ) : '';
        $origin = is_array( $company ) ? (string) ( $company['company_origin'] ?? '' ) : '';
        if ( ! preg_match( '/^[a-f0-9-]{36}$/iD', $uuid ) ||
            ! filter_var( $origin, FILTER_VALIDATE_URL ) ||
            'https' !== strtolower( (string) parse_url( $origin, PHP_URL_SCHEME ) ) ||
            ( $company['company_environment'] ?? '' ) !== 'production' )
            $blockers[] = 'target_company_identity_invalid';

        if ( ( $receipt['rollout_scope'] ?? '' ) !== 'company_production' ||
            ! in_array( $receipt['requirements']['deployment_role'] ?? '', array( 'company_adapter', 'company_plugin' ), true ) )
            $blockers[] = 'non_distributable_release_scope';
        $plugin = (string) ( $receipt['requirements']['target_plugin_file'] ?? '' );
        if ( ! preg_match( '#^[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+\\.php$#D', $plugin ) ||
            false !== strpos( $plugin, '..' ) ||
            $plugin === 'mad4b-content-hub/mad4b-content-hub.php' )
            $blockers[] = 'target_plugin_not_distributable';

        $blockers = array_values( array_unique( $blockers ) );
        sort( $blockers, SORT_STRING );
        return array(
            'contract' => self::CONTRACT,
            'transport' => 'native_ssh_via_registered_broker',
            'broker_authority_contract' => 'central_authority_verifier_312',
            'remote_readback_contract' => 'remote_readback_313',
            'operation_policy_contract' => 'mad4b.unified-operation-authority-decision.v1',
            'accepted_verified_identity_channels' => array( 'oauth_mcp', 'vps_ssh_wpcli', 'signed_broker' ),
            'transport_authentication_is_authorization' => false,
            'operation_policy_decision_verified' => false,
            'broker_target_separation_required' => true,
            'broker_target_separation_verified' => false,
            'source' => array(
                'artifact_sha256' => $artifact,
                'manifest_sha256' => $manifest,
                'source_commit' => $commit,
                'source_type' => (string) ( $receipt['source_type'] ?? '' ),
                'deployment_role' => (string) ( $receipt['requirements']['deployment_role'] ?? '' ),
            ),
            'target' => array(
                'site_uuid' => $uuid, 'origin' => $origin,
                'environment' => 'production', 'plugin_file' => $plugin,
            ),
            'company_staging_required' => false,
            'package_certificate_required' => false,
            'preflight_eligible' => empty( $blockers ),
            'blockers' => $blockers,
            // Authority verifier, command digest, target host, persistent
            // grant and postcondition are intentionally NOT guessed here.
            'broker_signed_preflight_verified' => false,
            'claim_and_delegation_verified' => false,
            'grant_verified' => false,
            'ssh_target_resolved' => false,
            'command_digest_verified' => false,
            'remote_executor_registered' => false,
            'remote_readback_verified' => false,
            'dispatch_eligible' => false,
            'production_mutation_authorized' => false,
            'read_only' => true,
            'mutation_performed' => false,
            'next_step' => empty( $blockers )
                ? 'Use the existing Broker to verify a real grant/claim/target/command and an approved Native SSH executor; require remote readback 313.'
                : 'Resolve trusted central release and company compatibility first.',
        );
    }
}
