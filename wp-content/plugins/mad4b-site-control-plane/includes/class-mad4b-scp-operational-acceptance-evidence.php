<?php
/**
 * Bounded, read-only evidence collector for end-to-end Brand Core operations.
 * All observations are collected from canonical current WordPress services.
 * This does not accept supplied receipts, execute a mutation, or silently
 * substitute functional WP tests for external Host/DB/release certification.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Operational_Acceptance_Evidence {
    const CONTRACT = 'mad4b.operational-acceptance-evidence.v1';
    const ABILITY = 'mad4b/operational-acceptance-evidence';
    const MAX_STAGES = 8;

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
    }
    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' )
            || ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Collect and Verify Operational Acceptance Evidence',
            'description' => 'Read-only Staging evidence census, verified original Drive membership, Host challenge, package readiness, Brand coverage and next governed operations. No write approval or self-issued certificate.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'collect' ),
            'permission_callback' => array( __CLASS__, 'can_read' ),
            'input_schema' => array( 'type' => 'object',
                'additionalProperties' => false,
                'properties' => array(
                    'source_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'candidate_sha' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{40}$' ),
                    'include_provider_scan' => array( 'type' => 'boolean', 'default' => false ),
                    'include_host_challenge' => array( 'type' => 'boolean', 'default' => false ),
                    'expected_snapshot_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                ) ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }
    public static function can_read() {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' )
            && class_exists( 'MAD4B_SCP_Policy' )
            && MAD4B_SCP_Policy::can_read();
    }
    private static function denied( $code, $message ) {
        return new WP_Error( 'mad4b_operational_evidence_' . $code, $message );
    }
    private static function valid_sha( $value, $length ) {
        return is_string( $value )
            && 1 === preg_match( '/^[a-f0-9]{' . (int) $length . '}$/D', $value );
    }
    public static function site_snapshot() {
        $site = class_exists( 'MAD4B_SCP_Site_Profile' )
            ? MAD4B_SCP_Site_Profile::status() : array();
        if ( ! is_array( $site ) || 'staging' !== (string) ( $site['configured_environment'] ?? '' )
            || 'staging' !== (string) ( $site['wordpress_environment'] ?? '' )
            || empty( $site['authority_ready'] ) || empty( $site['origin_match'] )
            || empty( $site['environment_match'] )
            || ! preg_match( '/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',
                (string) ( $site['site_uuid'] ?? '' ) )
            || ! self::valid_sha( (string) ( $site['profile_digest'] ?? '' ), 64 ) )
            return self::denied( 'exact_staging_required', 'Exact enrolled Staging profile is required; Production is denied.' );
        return array(
            'site_uuid' => (string) $site['site_uuid'],
            'profile_digest' => (string) $site['profile_digest'],
            'revision' => (int) ( $site['revision'] ?? 0 ),
            'origin' => (string) ( $site['canonical_origin'] ?? '' ),
        );
    }
    public static function paths() {
        return array(
            'source_inventory' => array( 'read' => 'context/legacy-reconciliation-census',
                'preflight' => 'context/legacy-owner-transfer-discover',
                'execute' => '', 'readback' => 'context/legacy-reconciliation-census', 'approval' => false ),
            'original_file_identity' => array( 'read' => 'context/legacy-owner-transfer-evidence',
                'preflight' => 'context/legacy-owner-transfer-plan', 'execute' => '',
                'readback' => 'context/legacy-owner-transfer-evidence', 'approval' => false ),
            'owner_attestation' => array( 'read' => 'context/legacy-owner-transfer-readback',
                'preflight' => 'context/legacy-owner-transfer-plan',
                'execute' => 'context/legacy-owner-transfer-apply',
                'readback' => 'context/legacy-owner-transfer-readback', 'approval' => true ),
            'host_identity' => array( 'read' => 'mad4b/host-doctor',
                'preflight' => 'mad4b/host-operation-plan',
                'execute' => 'mad4b/host-operation-apply',
                'readback' => 'mad4b/host-operation-receipt', 'approval' => true ),
            'durable_db_recovery' => array( 'read' => 'mad4b/host-operation-capabilities',
                'preflight' => '', 'execute' => '',
                'readback' => '', 'approval' => true ),
            'certified_package' => array( 'read' => 'mad4b/build-provenance-status',
                'preflight' => 'mad4b/control-plane-selected-head-plan',
                'execute' => 'mad4b/control-plane-selected-head-apply',
                'readback' => 'mad4b/control-plane-update-status', 'approval' => true ),
            'brand_approval' => array( 'read' => 'context/brand-core-coverage',
                'preflight' => 'context/brand-core-convergence-plan',
                'execute' => 'context/brand-profile-save',
                'readback' => 'context/brand-core-coverage', 'approval' => true ),
            'draft_acceptance' => array( 'read' => 'mad4b/content-bundle-readback',
                'preflight' => 'mad4b/content-orchestration-plan',
                'execute' => 'mad4b/content-apply-bundle',
                'readback' => 'mad4b/content-bundle-readback', 'approval' => true ),
        );
    }
    private static function digest( $value ) {
        return hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }
    private static function error_code( $value ) {
        return is_wp_error( $value ) ? $value->get_error_code() : '';
    }
    private static function mounted( $name ) {
        return '' !== $name && function_exists( 'wp_has_ability' ) && (bool) wp_has_ability( $name );
    }
    /**
     * Pure reducer; only trusted WordPress-owned observations reach this
     * function in production. Never deserialize caller-provided receipts.
     */
    public static function reduce( array $facts, array $mounts ) {
        $site = is_array( $facts['site'] ?? null ) ? $facts['site'] : array();
        $census = is_array( $facts['census'] ?? null ) ? $facts['census'] : array();
        $provider = is_array( $facts['provider'] ?? null ) ? $facts['provider'] : array();
        $transfer = is_array( $facts['transfer'] ?? null ) ? $facts['transfer'] : array();
        $coverage = is_array( $facts['coverage'] ?? null ) ? $facts['coverage'] : array();
        $native = is_array( $facts['native'] ?? null ) ? $facts['native'] : array();
        $candidate = (string) ( $facts['candidate_sha'] ?? '' );
        $source_id = (string) ( $facts['source_id'] ?? '' );
        $installed = (string) ( $facts['installed_sha'] ?? '' );
        $drift = true === ( $facts['snapshot_drift'] ?? false );
        $census_ok = isset( $census['source_quarantine_count'], $census['asset_quarantine_count'],
            $census['scope_fingerprint'] )
            && is_int( $census['source_quarantine_count'] )
            && is_int( $census['asset_quarantine_count'] )
            && $census['source_quarantine_count'] >= 0 && $census['asset_quarantine_count'] >= 0
            && ! $drift;
        $quarantine_clear = $census_ok && 0 === $census['source_quarantine_count']
            && 0 === $census['asset_quarantine_count'];
        $provider_ok = ! $drift && '' !== $source_id
            && true === ( $facts['provider_scan_requested'] ?? false )
            && true === ( $provider['folder_membership_verified'] ?? false )
            && false === ( $provider['migration_authorized'] ?? null )
            && self::valid_sha( (string) ( $provider['provider_proof_sha256'] ?? '' ), 64 )
            && hash_equals( $source_id, (string) ( $provider['source_id'] ?? '' ) )
            && hash_equals( (string) ( $provider['plan_sha256'] ?? '' ),
                (string) ( $facts['original_plan_sha256'] ?? '' ) )
            && (int) ( $provider['matched_file_count'] ?? -1 ) > 0;
        $transfer_ok = ! $drift && '' !== $source_id
            && true === ( $transfer['ready_for_fresh_source_scan'] ?? false )
            && true === ( $transfer['existing_source_verified'] ?? false )
            && (int) ( $transfer['asset_count'] ?? 0 ) > 0
            && (int) $transfer['asset_count'] === (int) ( $transfer['unreviewed_asset_count'] ?? -1 )
            && 0 === (int) ( $transfer['prior_review_evidence_still_present_count'] ?? -1 );
        $brand_ok = $quarantine_clear && true === ( $coverage['ready'] ?? false )
            && empty( $coverage['missing_required_context_sets'] )
            && empty( $coverage['conflicting_required_context_sets'] );
        foreach ( array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines' ) as $category ) {
            if ( true !== ( $coverage['coverage'][ $category ]['ready'] ?? false )
                || (int) ( $coverage['coverage'][ $category ]['eligible_asset_count'] ?? 0 ) < 1 )
                $brand_ok = false;
        }
        // An installed SHA match is a useful observation, never self-signed
        // package provenance, rollback or independent CI acceptance.
        $installed_match = self::valid_sha( $candidate, 40 )
            && self::valid_sha( $installed, 40 ) && hash_equals( $candidate, $installed );
        $host = ! $drift && true === ( $facts['host_challenge_requested'] ?? false )
            && true === ( $facts['host_verified'] ?? false );
        $observed = array(
            'source_inventory' => $census_ok,
            'original_file_identity' => $provider_ok,
            'owner_attestation' => false,
            'host_identity' => $host,
            'durable_db_recovery' => false,
            'certified_package' => false,
            'brand_approval' => $brand_ok && ! $drift,
            'draft_acceptance' => false,
        );
        $extras = array(
            'transfer_postcondition_verified' => $transfer_ok,
            'installed_candidate_sha_matches' => $installed_match,
            'native_scratch_active' => true === ( $native['active_job'] ?? false ),
            'native_scratch_state' => (string) ( $native['state'] ?? 'unavailable' ),
            'separate_owner_attestation_verified' => false,
            'independent_db_crash_certificate_verified' => false,
            'package_signer_and_rollback_certificate_verified' => false,
            'draft_content_receipt_verified' => false,
            'legal_rights_verified' => false,
        );
        $issues = array();
        $rows = array();
        foreach ( self::paths() as $id => $path ) {
            $verified = true === $observed[ $id ];
            $missing = array();
            if ( $drift ) $missing[] = 'snapshot_changed_during_collection';
            if ( 'source_inventory' === $id && ! $census_ok )
                $missing[] = 'site_census_unavailable_or_stale';
            if ( 'original_file_identity' === $id && ! $provider_ok )
                $missing[] = $source_id ? 'full_original_provider_scan_required' : 'source_id_required';
            if ( 'owner_attestation' === $id )
                $missing[] = 'separate_owner_consent_and_exact_approval_receipt_required';
            if ( 'host_identity' === $id && ! $host )
                $missing[] = 'enrolled_host_identity_nonce_signature_missing';
            if ( 'durable_db_recovery' === $id )
                $missing[] = 'independent_real_mariadb_crash_rollback_executor_and_signed_receipt_missing';
            if ( 'certified_package' === $id )
                $missing[] = $installed_match
                    ? 'independent_signed_package_ci_and_rollback_evidence_missing'
                    : 'exact_head_certified_artifact_install_and_readback_missing';
            if ( 'brand_approval' === $id && ! $brand_ok )
                $missing[] = 'quarantine_clear_and_three_reviewed_context_categories_required';
            if ( 'draft_acceptance' === $id )
                $missing[] = 'independent_persisted_draft_readback_receipt_required';
            if ( '' === $path['preflight'] && '' === $path['execute'] )
                $missing[] = 'external_certified_executor_integration_required';
            $mount = array();
            foreach ( array( 'read', 'preflight', 'execute', 'readback' ) as $k ) {
                $ability = $path[ $k ];
                $mount[ $k ] = array( 'ability' => $ability,
                    'mounted' => '' !== $ability && true === ( $mounts[ $ability ] ?? false ) );
            }
            $next = $verified ? $path['readback']
                : ( $mount['read']['mounted'] ? $path['read'] : $path['preflight'] );
            $row = array(
                'stage_id' => $id, 'state' => $drift ? 'SNAPSHOT_DRIFT_REPLAN'
                    : ( $verified ? 'EVIDENCE_OBSERVED_NOT_FULL_CERTIFICATION'
                        : 'MISSING_OR_UNCERTIFIED_EVIDENCE' ),
                'evidence_observed' => $verified,
                'readiness' => $mount,
                'next_read_or_plan_ability' => $next,
                'effectful_ability' => $path['execute'],
                'requires_separate_approval_for_write' => (bool) $path['approval'],
                'write_approval_issued' => false, 'write_executed' => false,
                'independent_certificate_accepted' => false,
                'missing_evidence' => $missing,
                'supported_remote_preflight' => $mount['read']['mounted'] || $mount['preflight']['mounted'],
                'automatic_write_allowed' => false,
            );
            $rows[ $id ] = $row;
            if ( ! $verified ) $issues[] = $id;
        }
        $basis = array(
            'contract' => self::CONTRACT, 'site' => $site,
            'source_id' => $source_id, 'candidate_sha' => $candidate,
            'installed_sha' => $installed,
            'census_digest' => self::digest( $census ),
            'provider_digest' => $provider_ok ? $provider['provider_proof_sha256'] : '',
            'transfer_digest' => self::digest( $transfer ),
            'coverage_digest' => self::digest( $coverage ),
            'host_verified' => $host,
            'snapshot_drift' => $drift,
            'rows' => $rows,
        );
        return array( 'contract' => self::CONTRACT,
            'state' => $drift ? 'SNAPSHOT_DRIFT_REPLAN'
                : ( $issues ? 'OPERATIONAL_ACCEPTANCE_INCOMPLETE' : 'ALL_LOCAL_EVIDENCE_COLLECTED' ),
            'snapshot_sha256' => self::digest( $basis ),
            'source_id' => $source_id, 'candidate_sha' => $candidate,
            'installed_sha' => $installed, 'stage_count' => count( $rows ),
            'observed_count' => count( $rows ) - count( $issues ),
            'stages' => $rows, 'remaining_stages' => $issues,
            'supplemental_observations' => $extras,
            'external_acceptance_candidates' => array(
                'generic_suite' => 'mad4b/acceptance-result',
                'browser' => 'mad4b/browser-acceptance-result',
                'package_provenance' => 'mad4b/build-provenance-status',
                'fallbacks' => 'mad4b/brand-core-recovery-alternatives',
            ),
            'full_operational_acceptance' => false,
            'owner_transfer_authorized' => false, 'production_mutation_authorized' => false,
            'breakglass_authorized' => false, 'read_only' => true,
            'mutation_performed' => false );
    }

    public static function collect( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input ) )
            return self::denied( 'admin_read_required', 'Administrator read access is required.' );
        foreach ( array_keys( $input ) as $key ) {
            if ( ! in_array( $key, array( 'source_id', 'candidate_sha',
                'include_provider_scan', 'include_host_challenge',
                'expected_snapshot_sha256' ), true ) )
                return self::denied( 'unexpected_parameter', 'No arbitrary receipt, SQL, URL or callback is accepted.' );
        }
        foreach ( array( 'include_provider_scan', 'include_host_challenge' ) as $field )
            if ( isset( $input[ $field ] ) && ! is_bool( $input[ $field ] ) )
                return self::denied( 'invalid_boolean', 'Provider and Host checks must be explicit booleans.' );
        $source = (string) ( $input['source_id'] ?? '' );
        $candidate = (string) ( $input['candidate_sha'] ?? '' );
        $expected = (string) ( $input['expected_snapshot_sha256'] ?? '' );
        if ( ( '' !== $source && ! self::valid_sha( $source, 64 ) )
            || ( '' !== $candidate && ! self::valid_sha( $candidate, 40 ) )
            || ( '' !== $expected && ! self::valid_sha( $expected, 64 ) ) )
            return self::denied( 'invalid_identity', 'Exact source, candidate and snapshot hashes must be canonical.');
        if ( ! empty( $input['include_provider_scan'] ) && '' === $source )
            return self::denied( 'source_required_for_scan', 'An original source ID is needed for provider scanning.');
        $site = self::site_snapshot();
        if ( is_wp_error( $site ) ) return $site;
        if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) )
            return self::denied( 'context_unavailable', 'Native Context Authority is unavailable.');
        $census = MAD4B_SCP_Context_Authority::legacy_reconciliation_census();
        $coverage = MAD4B_SCP_Context_Authority::brand_core_coverage();
        if ( is_wp_error( $census ) || ! is_array( $census ) )
            return self::denied( 'census_unavailable', self::error_code( $census ) ?: 'Census failed.');
        if ( is_wp_error( $coverage ) || ! is_array( $coverage ) )
            return self::denied( 'coverage_unavailable', self::error_code( $coverage ) ?: 'Coverage failed.');
        $original_plan = array(); $provider = array(); $transfer = array();
        if ( '' !== $source ) {
            $transfer_read = MAD4B_SCP_Context_Authority::legacy_owner_transfer_readback( array( 'source_id' => $source ) );
            if ( is_array( $transfer_read ) ) $transfer = $transfer_read;
            if ( ! empty( $input['include_provider_scan'] ) ) {
                $plan = MAD4B_SCP_Context_Authority::legacy_owner_transfer_plan( array( 'source_id' => $source ) );
                if ( is_array( $plan ) ) {
                    $original_plan = $plan;
                    $evidence = MAD4B_SCP_Context_Authority::legacy_owner_transfer_evidence( array( 'source_id' => $source ) );
                    if ( is_array( $evidence ) ) $provider = $evidence;
                }
            }
        }
        $host = false;
        if ( ! empty( $input['include_host_challenge'] )
            && class_exists( 'MAD4B_SCP_Host_Identity_Live' ) ) {
            $site_status = MAD4B_SCP_Site_Profile::status();
            $live = MAD4B_SCP_Host_Identity_Live::observe( $site_status );
            $host = is_array( $live ) && true === ( $live['verified'] ?? false );
        }
        $native = class_exists( 'MAD4B_SCP_Native_Recovery' )
            ? MAD4B_SCP_Native_Recovery::status() : array();
        $installed = '';
        if ( class_exists( 'MAD4B_SCP_Self_Update' )
            && method_exists( 'MAD4B_SCP_Self_Update', 'current_identity' ) ) {
            $identity = MAD4B_SCP_Self_Update::current_identity();
            if ( is_array( $identity ) ) $installed = (string) ( $identity['source_commit_sha'] ?? '' );
        }
        // A second authoritative read fences source/coverage changes during
        // remote Drive or Host I/O. It never authorizes later writes.
        $census_again = MAD4B_SCP_Context_Authority::legacy_reconciliation_census();
        $coverage_again = MAD4B_SCP_Context_Authority::brand_core_coverage();
        $site_again = self::site_snapshot();
        $drift = ! is_array( $census_again ) || ! is_array( $coverage_again )
            || is_wp_error( $site_again )
            || self::digest( $census ) !== self::digest( $census_again )
            || self::digest( $coverage ) !== self::digest( $coverage_again )
            || ( is_array( $site_again ) && $site !== $site_again );
        if ( $drift ) $provider = array();
        $mounts = array();
        foreach ( self::paths() as $path )
            foreach ( $path as $key => $value )
                if ( in_array( $key, array( 'read', 'preflight', 'execute', 'readback' ), true )
                    && '' !== $value && ! array_key_exists( $value, $mounts ) )
                    $mounts[ $value ] = self::mounted( $value );
        $facts = array(
            'site' => $site, 'source_id' => $source, 'candidate_sha' => $candidate,
            'installed_sha' => $installed, 'census' => $census,
            'coverage' => $coverage, 'provider' => $provider, 'transfer' => $transfer,
            'provider_scan_requested' => ! empty( $input['include_provider_scan'] ),
            'host_challenge_requested' => ! empty( $input['include_host_challenge'] ),
            'host_verified' => $host, 'native' => $native, 'snapshot_drift' => $drift,
            'original_plan_sha256' => (string) ( $original_plan['plan_sha256'] ?? '' ),
        );
        $report = self::reduce( $facts, $mounts );
        if ( '' !== $expected && ! hash_equals( $expected, $report['snapshot_sha256'] ) )
            return self::denied( 'snapshot_changed_replan', 'Evidence changed since the supplied snapshot. Collect a new exact plan.');
        return $report;
    }
}
