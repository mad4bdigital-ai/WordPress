<?php
/**
 * A read-only MCP handoff for the independent source/package builder.
 *
 * WordPress is not a build host. It does not run Python, PHP CLI, git,
 * shell, GitHub Actions or external host jobs through this interface.
 * No source SHA, WordPress Site Profile or discovered screen grants release
 * installation, publication or elevated permissions.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Standalone_Build_Control {
    const CONTRACT = 'mad4b.standalone-build-control.v1';
    const DISCOVER = 'mad4b/standalone-build-discover';
    const PLAN = 'mad4b/standalone-build-plan';
    const SCRIPT = 'tools/mad4b_standalone_build.py';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $schema = array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(),
        );
        $plan = $schema;
        $plan['required'] = array( 'expected_head' );
        $plan['properties'] = array(
            'expected_head' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{40}$' ),
            'profile' => array( 'type' => 'string',
                'enum' => array( 'build-only', 'local-checks' ) ),
        );
        foreach ( array(
            array( self::DISCOVER, 'Discover Standalone Build Capability', 'discover', $schema ),
            array( self::PLAN, 'Plan Standalone Exact-HEAD Package Build', 'plan', $plan ),
        ) as $row ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $row[0] ) ) continue;
            wp_register_ability( $row[0], array(
                'label' => $row[1],
                'description' => 'Source-bound read-only handoff to a separately authorized trusted build runner. No commands run on WordPress.',
                'category' => 'mad4b-read',
                'execute_callback' => array( __CLASS__, $row[2] ),
                'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
                'input_schema' => $row[3],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read',
                        'non_authorizing' => true ),
                    'annotations' => array( 'readonly' => true,
                        'destructive' => false, 'idempotent' => true ) ),
            ) );
        }
    }

    private static function identity() {
        $profile = class_exists( 'MAD4B_SCP_Site_Profile' )
            ? MAD4B_SCP_Site_Profile::status() : array();
        $profile = is_array( $profile ) ? $profile : array();
        return array(
            'site_uuid' => (string) ( $profile['site_uuid'] ?? '' ),
            'profile_digest' => (string) ( $profile['profile_digest'] ?? '' ),
            'environment' => (string) ( $profile['environment'] ?? '' ),
            'origin' => (string) ( $profile['canonical_origin'] ?? '' ),
        );
    }

    public static function discover( $input = array() ) {
        if ( ! is_array( $input ) || $input )
            return new WP_Error( 'mad4b_standalone_build_discovery_invalid',
                'Standalone build discovery takes no arguments.' );
        return array(
            'contract' => self::CONTRACT,
            'build_entrypoint' => self::SCRIPT,
            'native_evidence_entrypoint' => 'tools/mad4b_standalone_evidence.py',
            'supported_profiles' => array( 'build-only', 'local-checks' ),
            'canonical_builder' => 'wp-content/plugins/mad4b-site-control-plane/tests/build-deterministic-control-plane-package.py',
            'adapter_source' => 'certified-provider-profiles.json + runtime-release-policy.json',
            'exact_clean_git_checkout_required' => true,
            'trusted_runner_required' => true,
            'ci_required_for_build' => false,
            'ci_evidence_optional_for_independent_staging_review' => true,
            'mcp_dispatch_implemented' => false,
            'automatic_execution_enabled' => false,
            'default_release_state' => 'BUILT_UNVERIFIED',
            'certification_owned_by' => 'existing_governed_ci_or_signed_staging_attestation',
            'source_site_binding' => self::identity(),
            'production_authorized' => false,
            'execution_performed' => false,
            'read_only' => true,
        );
    }

    public static function plan( $input = array() ) {
        if ( ! is_array( $input ) ||
            array_diff( array_keys( $input ), array( 'expected_head', 'profile' ) ) )
            return new WP_Error( 'mad4b_standalone_build_plan_input_invalid',
                'Only exact source SHA and bounded test profile are supported.' );
        $sha = $input['expected_head'] ?? '';
        $profile = $input['profile'] ?? 'build-only';
        if ( ! is_string( $sha ) ||
            ! preg_match( '/^[a-f0-9]{40}$/D', $sha ) ||
            ! in_array( $profile, array( 'build-only', 'local-checks' ), true ) )
            return new WP_Error( 'mad4b_standalone_build_plan_invalid',
                'Exact lowercase source SHA and approved profile are required.' );
        $site = self::identity();
        $binding = array(
            'contract' => self::CONTRACT,
            'source_commit_sha' => $sha,
            'profile' => $profile,
            'site_uuid' => $site['site_uuid'],
            'profile_digest' => $site['profile_digest'],
            'origin' => $site['origin'],
        );
        return array(
            'contract' => self::CONTRACT . '.plan.v1',
            'plan_sha256' => hash( 'sha256', (string) wp_json_encode( $binding ) ),
            'source_commit_sha' => $sha,
            'source_checkout_verified' => false,
            'source_verification_owner' => 'trusted_external_builder',
            'build_entrypoint' => self::SCRIPT,
            'build_profile' => $profile,
            'native_evidence_entrypoint' => 'tools/mad4b_standalone_evidence.py',
            'site_binding' => $site,
            'inputs_required_on_trusted_runner' => array(
                'repo_root', 'expected_head', 'certified_adapter_archive',
                'output_dir' ),
            'expected_outputs' => array(
                'zip', 'CANONICAL-PACKAGE-RECEIPT.json',
                'MAD4B-BUILD-PROVENANCE.json',
                'BUILD-FINGERPRINT.txt', 'PACKAGE-MANIFEST-DIGEST.txt',
                'STANDALONE-BUILD-REPORT.json' ),
            'native_evidence_outputs' => array( 'GATE-RESULTS.json',
                'NATIVE-TEST-EVIDENCE-BUNDLE.json' ),
            'next_state' => 'external_trusted_runner_required',
            'builder_status' => 'NOT_RUN',
            'mcp_execution_available' => false,
            'release_certified' => false,
            'github_ci_certified' => false,
            'staging_certified' => false,
            'production_authorized' => false,
            'mutation_performed' => false,
            'read_only' => true,
        );
    }
}
