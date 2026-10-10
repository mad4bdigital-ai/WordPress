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
    const REQUEST = 'mad4b/standalone-build-request';
    const OPERATION = 'standalone_source_build';
    const CONFIRMATION = 'QUEUE EXACT STAGING SOURCE BUILD';
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
        self::register_request_ability();
    }

    /** Explicit opt-in work admission. Never executes a build inside WordPress. */
    public static function register_request_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ||
            ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::REQUEST ) ) ) return;
        wp_register_ability( self::REQUEST, array(
            'label' => 'Request Trusted Standalone Build',
            'description' => 'Queue one owner-approved exact-head build for the enrolled Staging runner.',
            'category' => 'mad4b-admin',
            'execute_callback' => array( __CLASS__, 'request' ),
            'permission_callback' => array( __CLASS__, 'can_request' ),
            'input_schema' => array(
                'type' => 'object', 'additionalProperties' => false,
                'required' => array( 'expected_head', 'expected_plan_sha256', 'confirmation' ),
                'properties' => array(
                    'expected_head' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{40}
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
 ),
                    'profile' => array( 'type' => 'string',
                        'enum' => array( 'build-only', 'local-checks' ) ),
                    'expected_plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}
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
 ),
                    'confirmation' => array( 'type' => 'string',
                        'enum' => array( self::CONFIRMATION ) ),
                ),
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin',
                    'non_authorizing' => false, 'production_mutation_allowed' => false ),
                'annotations' => array( 'readonly' => false, 'destructive' => false,
                    'idempotent' => true ) ),
        ) );
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
    /** Register only one fixed semantic work kind, not general remote execution. */
    public static function work_definitions( $registered ) {
        if ( ! isset( $registered[ self::OPERATION ] ) ) {
            $registered[ self::OPERATION ] = array(
                'executor' => 'enrolled_standalone_build_runner',
                'authority_surface' => 'mad4b-enrollment',
                'production_policy' => 'deny',
                'validate_payload' => array( __CLASS__, 'valid_job_payload' ),
            );
        }
        return $registered;
    }

    public static function valid_job_payload( $payload ) {
        if ( ! is_array( $payload ) || array_diff( array_keys( $payload ),
            array( 'contract', 'expected_head', 'profile', 'plan_sha256',
                'site_uuid', 'profile_digest', 'origin' ) ) ||
            ( $payload['contract'] ?? '' ) !== self::CONTRACT . '.job.v1' ||
            ! preg_match( '/^[a-f0-9]{40}$/D', (string) ( $payload['expected_head'] ?? '' ) ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $payload['plan_sha256'] ?? '' ) ) ||
            ! in_array( $payload['profile'] ?? '', array( 'build-only', 'local-checks' ), true ) )
            return false;
        foreach ( array( 'site_uuid', 'profile_digest', 'origin' ) as $key ) {
            if ( ! is_string( $payload[ $key ] ?? null ) || '' === $payload[ $key ] ||
                strlen( $payload[ $key ] ) > 256 ) return false;
        }
        return true;
    }

    private static function staging_ready() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
            ! function_exists( 'wp_get_environment_type' ) ||
            'staging' !== wp_get_environment_type() ) return false;
        $p = MAD4B_SCP_Site_Profile::status();
        return is_array( $p ) && ( $p['environment'] ?? '' ) === 'staging' &&
            ( $p['configured_environment'] ?? '' ) === 'staging' &&
            ! empty( $p['authority_ready'] ) && ! empty( $p['origin_match'] ) &&
            ! empty( $p['site_uuid'] ) && ! empty( $p['profile_digest'] ) &&
            ! empty( $p['canonical_origin'] );
    }

    public static function can_request( $input = null ) {
        if ( ! self::staging_ready() || ! function_exists( 'current_user_can' ) ||
            ! current_user_can( 'manage_options' ) ||
            ! MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() ) ||
            ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_mutate() )
            return new WP_Error( 'mad4b_build_staging_owner_required',
                'Enrolled owner and real Staging Write authority are required.' );
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope(
                MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ||
            ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is(
                MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) ||
            ! class_exists( 'MAD4B_SCP_Authorization' ) )
            return new WP_Error( 'mad4b_build_owner_step_up_required',
                'Enrolled ChatGPT owner OAuth authority Step-Up is required.' );
        return MAD4B_SCP_Authorization::authorize_mutation(
            self::REQUEST, 'mad4b-admin', 'core', is_array( $input ) ? $input : array() );
    }

    private static function installed_identity() {
        if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ) return false;
        $p = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
        if ( ! is_array( $p ) || empty( $p['manifest_valid'] ) ||
            empty( $p['runtime_manifest_match'] ) || ! empty( $p['stale'] ) ||
            ! empty( $p['provenance_mismatch'] ) ) return false;
        $id = array(
            'source_commit_sha' => (string) ( $p['source_commit_sha'] ?? '' ),
            'build_fingerprint' => (string) ( $p['build_fingerprint'] ?? '' ),
            'package_manifest_digest' => (string) ( $p['package_manifest_digest'] ?? '' ),
        );
        foreach ( $id as $key => $value ) {
            $length = 'source_commit_sha' === $key ? 40 : 64;
            if ( ! preg_match( '/^[a-f0-9]{' . $length . '}$/D', $value ) ) return false;
        }
        return $id;
    }

    public static function request( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'expected_head', 'profile', 'expected_plan_sha256', 'confirmation' ) ) ||
            ! hash_equals( self::CONFIRMATION, (string) ( $input['confirmation'] ?? '' ) ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D',
                (string) ( $input['expected_plan_sha256'] ?? '' ) ) )
            return new WP_Error( 'mad4b_build_request_invalid', 'Exact approved request is required.' );
        $auth = self::can_request( $input );
        if ( is_wp_error( $auth ) || true !== $auth ) return $auth;
        $plan = self::plan( array( 'expected_head' => $input['expected_head'] ?? '',
            'profile' => $input['profile'] ?? 'build-only' ) );
        if ( is_wp_error( $plan ) ||
            ! hash_equals( (string) ( $plan['plan_sha256'] ?? '' ),
                $input['expected_plan_sha256'] ) )
            return new WP_Error( 'mad4b_build_plan_changed', 'Exact build plan is stale.' );
        $installed = self::installed_identity();
        if ( false === $installed || ! class_exists( 'MAD4B_SCP_Remote_Work_Queue' ) )
            return new WP_Error( 'mad4b_build_install_provenance_unavailable',
                'Installed plugin provenance or queue is unavailable.' );
        $site = $plan['site_binding'];
        $payload = array( 'contract' => self::CONTRACT . '.job.v1',
            'expected_head' => $plan['source_commit_sha'],
            'profile' => $plan['build_profile'],
            'plan_sha256' => $plan['plan_sha256'],
            'site_uuid' => $site['site_uuid'],
            'profile_digest' => $site['profile_digest'],
            'origin' => $site['origin'] );
        $result = MAD4B_SCP_Remote_Work_Queue::enqueue(
            self::OPERATION, $payload, $installed, 3600 );
        if ( is_wp_error( $result ) ) return $result;
        return array( 'contract' => self::CONTRACT . '.request.v1',
            'state' => $result['state'], 'job_id' => $result['job']['job_id'],
            'expected_head' => $payload['expected_head'],
            'worker_required' => true, 'worker_executed' => false,
            'build_state' => 'QUEUED_UNVERIFIED',
            'release_certified' => false, 'production_authorized' => false );
    }

    /**
     * Trust anchor: a pinned offline Ed25519 public key, never a key supplied
     * by MCP inputs or the queue. This only acknowledges BUILT_UNVERIFIED.
     */
    public static function complete_signed_job( $input, $job ) {
        if ( ! is_array( $input ) || ! is_array( $job ) ||
            ( $job['operation_id'] ?? '' ) !== self::OPERATION ||
            ! self::staging_ready() ||
            ! class_exists( 'MAD4B_SCP_Remote_Work_Queue' ) )
            return new WP_Error( 'mad4b_build_completion_unavailable',
                'Current Staging and matching semantic job are required.' );
        if ( ! defined( 'MAD4B_SCP_STANDALONE_BUILDER_PUBLIC_KEY_B64' ) ||
            ! defined( 'MAD4B_SCP_STANDALONE_BUILDER_EXECUTOR_ID' ) ||
            ! function_exists( 'sodium_crypto_sign_verify_detached' ) )
            return new WP_Error( 'mad4b_build_runner_not_enrolled',
                'Pinned public key and executor ID are required.' );
        $executor = (string) constant( 'MAD4B_SCP_STANDALONE_BUILDER_EXECUTOR_ID' );
        if ( '' === $executor || strlen( $executor ) > 64 ||
            ( $input['executor_id'] ?? '' ) !== $executor ||
            ( $job['executor_id'] ?? '' ) !== $executor )
            return new WP_Error( 'mad4b_build_runner_identity_mismatch',
                'Enrolled executor does not own the active lease.' );
        if ( ( $job['status'] ?? '' ) !== 'claimed' ||
            ( $job['provider_checkpoint'] ?? '' ) !== 'provider_returned' ||
            ! empty( $job['cancel_requested_at'] ) ||
            time() > (int) ( $job['lease_expires_at_epoch'] ?? 0 ) )
            return new WP_Error( 'mad4b_build_lease_not_current',
                'Valid uncancelled completed-provider lease is required.' );
        $r = $input['build_receipt'] ?? null;
        if ( ! is_array( $r ) || array_diff( array_keys( $r ),
            array( 'claims_b64', 'signature_b64' ) ) ||
            ! is_string( $r['claims_b64'] ?? null ) ||
            ! is_string( $r['signature_b64'] ?? null ) ||
            strlen( $r['claims_b64'] ) > 8192 ||
            strlen( $r['signature_b64'] ) > 128 )
            return new WP_Error( 'mad4b_build_receipt_invalid', 'Bounded signed receipt is required.' );
        $key = base64_decode( (string) constant( 'MAD4B_SCP_STANDALONE_BUILDER_PUBLIC_KEY_B64' ), true );
        $raw = base64_decode( $r['claims_b64'], true );
        $sig = base64_decode( $r['signature_b64'], true );
        if ( ! is_string( $key ) || strlen( $key ) !== 32 ||
            ! is_string( $raw ) || strlen( $raw ) > 4096 ||
            ! is_string( $sig ) || strlen( $sig ) !== 64 ||
            ! sodium_crypto_sign_verify_detached( $sig, $raw, $key ) )
            return new WP_Error( 'mad4b_build_receipt_signature_invalid',
                'Runner proof is not signed by the pinned key.' );
        $c = json_decode( $raw, true );
        $payload = $job['payload'] ?? array();
        $site = self::identity();
        if ( ! is_array( $c ) ||
            array_diff( array_keys( $c ), array(
                'contract', 'job_id', 'executor_id', 'claim_generation',
                'target_source_sha', 'plan_sha256', 'profile',
                'site_uuid', 'profile_digest', 'origin',
                'archive_sha256', 'build_fingerprint', 'package_manifest_digest',
                'build_state', 'issued_at_epoch', 'expires_at_epoch',
                'production_authorized' ) ) ||
            ( $c['contract'] ?? '' ) !== self::CONTRACT . '.receipt.v1' ||
            ( $c['job_id'] ?? '' ) !== ( $job['job_id'] ?? '' ) ||
            ( $c['executor_id'] ?? '' ) !== $executor ||
            ! is_int( $c['claim_generation'] ?? null ) ||
            $c['claim_generation'] !== (int) ( $job['claim_generation'] ?? 0 ) ||
            ( $c['target_source_sha'] ?? '' ) !== ( $payload['expected_head'] ?? '' ) ||
            ( $c['plan_sha256'] ?? '' ) !== ( $payload['plan_sha256'] ?? '' ) ||
            ( $c['profile'] ?? '' ) !== ( $payload['profile'] ?? '' ) ||
            ( $c['site_uuid'] ?? '' ) !== $site['site_uuid'] ||
            ( $c['profile_digest'] ?? '' ) !== $site['profile_digest'] ||
            ( $c['origin'] ?? '' ) !== $site['origin'] ||
            ( $c['build_state'] ?? '' ) !== 'BUILT_UNVERIFIED' ||
            ! array_key_exists( 'production_authorized', $c ) ||
            false !== $c['production_authorized'] )
            return new WP_Error( 'mad4b_build_receipt_binding_mismatch',
                'Signature payload does not match current job, claim or site.' );
        $now = time();
        if ( ! is_int( $c['issued_at_epoch'] ?? null ) ||
            ! is_int( $c['expires_at_epoch'] ?? null ) ||
            $c['issued_at_epoch'] > $now + 60 ||
            $c['expires_at_epoch'] < $now ||
            $c['expires_at_epoch'] > $c['issued_at_epoch'] + 900 )
            return new WP_Error( 'mad4b_build_receipt_expired', 'Receipt freshness window invalid.' );
        foreach ( array( 'archive_sha256', 'build_fingerprint',
            'package_manifest_digest' ) as $field ) {
            if ( ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $c[ $field ] ?? '' ) ) )
                return new WP_Error( 'mad4b_build_hash_invalid',
                    'Signed build identity must contain three exact SHA-256 digests.' );
        }
        $receipt_sha = hash( 'sha256', $raw );
        return MAD4B_SCP_Remote_Work_Queue::complete(
            (string) $input['job_id'], $executor, (string) $input['lease_token'],
            array( 'verification' => 'pinned_ed25519_job_bound_runner_receipt',
                'source_commit_sha' => $c['target_source_sha'],
                'archive_sha256' => $c['archive_sha256'],
                'build_fingerprint' => $c['build_fingerprint'],
                'package_manifest_digest' => $c['package_manifest_digest'],
                'build_state' => 'BUILT_UNVERIFIED',
                'evidence_sha256' => $receipt_sha,
                'provider_execution_ref' => 'standalone_build:' . substr( $receipt_sha, 0, 32 ),
                'provider_effect_state' => 'applied',
                'postcondition_verified' => true,
                'release_certified' => false, 'staging_certified' => false,
                'production_authorized' => false ) );
    }

}
