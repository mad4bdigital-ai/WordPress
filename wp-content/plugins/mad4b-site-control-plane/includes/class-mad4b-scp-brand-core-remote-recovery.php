<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Non-authorizing, remote-first coordination for the existing Brand Core
 * recovery operations. This never calls a write executor, trusts operator
 * supplied receipts, manages Host keys, or weakens an existing release gate.
 */
final class MAD4B_SCP_Brand_Core_Remote_Recovery {
    const CONTRACT = 'mad4b.brand-core-remote-recovery.v1';
    const STATUS_ABILITY = 'mad4b/brand-core-remote-recovery-status';
    const PREPARE_ABILITY = 'mad4b/brand-core-remote-recovery-prepare';
    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' ) ) return;
        $definitions = array(
            self::STATUS_ABILITY => array(
                'label' => 'Diagnose Full Brand Core Remote Recovery',
                'callback' => 'status',
                'properties' => array(
                    'source_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'include_provider_scan' => array( 'type' => 'boolean', 'default' => false ),
                    'include_host_challenge' => array( 'type' => 'boolean', 'default' => false ),
                    'candidate_sha' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{40}$' ),
                ),
                'required' => array(),
            ),
            self::PREPARE_ABILITY => array(
                'label' => 'Prepare One Verified Remote Brand Recovery Step',
                'callback' => 'prepare',
                'properties' => array(
                    'stage_id' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,63}$' ),
                    'expected_plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'source_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'candidate_sha' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{40}$' ),
                ),
                'required' => array( 'stage_id', 'expected_plan_sha256' ),
            ),
        );
        foreach ( $definitions as $ability => $definition ) {
            if ( wp_has_ability( $ability ) ) continue;
            wp_register_ability( $ability, array(
                'label' => $definition['label'],
                'description' => 'Read-only exact-site, stage-bound remote recovery and handoff. Mutations require a separately approved, certified native Ability.',
                'category' => 'mad4b-read',
                'execute_callback' => array( __CLASS__, $definition['callback'] ),
                'permission_callback' => array( __CLASS__, 'can_read' ),
                'input_schema' => array( 'type' => 'object',
                    'properties' => $definition['properties'], 'required' => $definition['required'],
                    'additionalProperties' => false ),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array(
                    'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read', 'non_authorizing' => true ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
                ),
            ) );
        }
    }

    public static function can_read() {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' )
            && class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_read();
    }

    /** Static, explicitly authorized semantic endpoints; never user-defined URLs. */
    public static function stages() {
        return array(
            'source_inventory' => array(
                'read' => 'context/legacy-reconciliation-census',
                'next' => 'context/legacy-owner-transfer-discover',
                'readback' => 'context/status', 'executor' => 'wordpress_native',
                'operator_role' => 'site_administrator', 'approval' => false,
            ),
            'original_file_identity' => array(
                'read' => 'context/legacy-owner-transfer-evidence',
                'next' => 'context/legacy-owner-transfer-evidence',
                'readback' => 'context/legacy-owner-transfer-plan', 'executor' => 'governed_google_drive',
                'operator_role' => 'site_administrator', 'approval' => false,
            ),
            'owner_attestation' => array(
                'read' => 'context/legacy-owner-transfer-plan',
                'next' => 'context/legacy-owner-transfer-apply',
                'readback' => 'context/legacy-owner-transfer-readback', 'executor' => 'wordpress_registry_cas',
                'operator_role' => 'brand_owner', 'approval' => true,
            ),
            'host_identity' => array(
                'read' => 'hostinger-reach/status',
                'next' => '', 'readback' => 'mad4b/hostinger-reach/status',
                'executor' => 'enrolled_signed_host_runner', 'operator_role' => 'host_owner',
                'approval' => true,
            ),
            'durable_db_recovery' => array(
                'read' => '', 'next' => '', 'readback' => '',
                'executor' => 'independent_host_mariadb_crash_runner',
                'operator_role' => 'host_owner', 'approval' => true,
            ),
            'certified_package' => array(
                'read' => 'mad4b/control-plane-selected-head-plan',
                'next' => 'mad4b/control-plane-selected-head-apply',
                'readback' => 'mad4b/build-provenance-status',
                'executor' => 'signed_github_release_plus_staging_updater',
                'operator_role' => 'release_owner', 'approval' => true,
            ),
            'brand_approval' => array(
                'read' => 'context/brand-core-coverage',
                'next' => 'context/source-scan-plan', 'readback' => 'context/brand-core-coverage',
                'executor' => 'governed_context_review', 'operator_role' => 'brand_reviewer',
                'approval' => true,
            ),
            'draft_acceptance' => array(
                'read' => 'mad4b/content-orchestration-plan',
                'next' => 'mad4b/content-apply-bundle',
                'readback' => 'mad4b/content-bundle-readback',
                'executor' => 'wordpress_native_typed_content',
                'operator_role' => 'content_owner', 'approval' => true,
            ),
        );
    }

    private static function denial( $code ) {
        return new WP_Error( 'mad4b_brand_remote_' . $code,
            'Exact Staging, administrator authority and a fresh verified recovery plan are required.' );
    }

    /** Only facts read by WordPress itself, not caller-supplied success flags. */
    public static function status( $input = array() ) {
        if ( ! self::can_read() ) return self::denial( 'permission_required' );
        if ( ! is_array( $input ) ) return self::denial( 'invalid_input' );
        foreach ( array_keys( $input ) as $key ) {
            if ( ! in_array( $key, array( 'source_id', 'include_provider_scan',
                'include_host_challenge', 'candidate_sha' ), true ) )
                return self::denial( 'unexpected_parameter' );
        }
        $source_id = isset( $input['source_id'] ) ? (string) $input['source_id'] : '';
        $candidate = isset( $input['candidate_sha'] ) ? (string) $input['candidate_sha'] : '';
        if ( ( '' !== $source_id && ! preg_match( '/^[a-f0-9]{64}$/D', $source_id ) )
            || ( '' !== $candidate && ! preg_match( '/^[a-f0-9]{40}$/D', $candidate ) ) )
            return self::denial( 'invalid_exact_identity' );

        $site = class_exists( 'MAD4B_SCP_Site_Profile' )
            ? MAD4B_SCP_Site_Profile::status() : array();
        $staging = is_array( $site ) && ! empty( $site['origin_match'] )
            && ! empty( $site['environment_match'] )
            && 'staging' === (string) ( $site['environment'] ?? '' );
        if ( ! $staging ) return self::denial( 'not_exact_staging' );

        $facts = array(
            'site_ready' => $staging,
            'installed_sha' => '',
            'census' => array(), 'proof' => array(), 'brand_coverage' => array(),
            'host_verified' => false, 'host_challenge_attempted' => false,
            'selected_head' => array(),
            'source_id' => $source_id, 'candidate_sha' => $candidate,
        );
        if ( class_exists( 'MAD4B_SCP_Self_Update' )
            && method_exists( 'MAD4B_SCP_Self_Update', 'current_identity' ) ) {
            $installed = MAD4B_SCP_Self_Update::current_identity();
            if ( is_array( $installed ) ) $facts['installed_sha'] =
                (string) ( $installed['source_commit_sha'] ?? '' );
        }
        if ( class_exists( 'MAD4B_SCP_Context_Authority' ) ) {
            $census = MAD4B_SCP_Context_Authority::legacy_reconciliation_census();
            if ( is_array( $census ) ) $facts['census'] = $census;
            $coverage = MAD4B_SCP_Context_Authority::brand_core_coverage();
            if ( is_array( $coverage ) ) $facts['brand_coverage'] = $coverage;
            if ( $source_id && ! empty( $input['include_provider_scan'] )
                && method_exists( 'MAD4B_SCP_Context_Authority', 'legacy_owner_transfer_evidence' ) ) {
                $proof = MAD4B_SCP_Context_Authority::legacy_owner_transfer_evidence(
                    array( 'source_id' => $source_id ) );
                if ( is_array( $proof ) ) $facts['proof'] = $proof;
            }
        }
        if ( ! empty( $input['include_host_challenge'] ) ) {
            $facts['host_challenge_attempted'] = true;
            if ( class_exists( 'MAD4B_SCP_Host_Identity_Live' ) ) {
                $host = MAD4B_SCP_Host_Identity_Live::observe( $site );
                $facts['host_verified'] = is_array( $host ) && true === ( $host['verified'] ?? false );
            }
        }
        if ( $candidate && class_exists( 'MAD4B_SCP_Selected_Head_Update' ) ) {
            $release = MAD4B_SCP_Selected_Head_Update::plan( array(
                'candidate_source' => array( 'repository' => 'mad4bdigital-ai/WordPress',
                    'type' => 'commit', 'reference' => $candidate ),
                'reason' => 'Brand Core exact-head Staging recovery readiness audit',
            ) );
            if ( is_array( $release ) ) $facts['selected_head'] = $release;
        }
        $available = array();
        if ( function_exists( 'wp_has_ability' ) ) {
            foreach ( self::stages() as $stage ) {
                foreach ( array( 'read', 'next', 'readback' ) as $slot ) {
                    $name = (string) $stage[ $slot ];
                    if ( '' !== $name && ! array_key_exists( $name, $available ) )
                        $available[ $name ] = (bool) wp_has_ability( $name );
                }
            }
        }
        return self::reduce( $facts, $available );
    }

    /** Pure evidence reducer: capability discovery never becomes approval. */
    public static function reduce( array $facts, array $abilities ) {
        $census = is_array( $facts['census'] ?? null ) ? $facts['census'] : array();
        $proof = is_array( $facts['proof'] ?? null ) ? $facts['proof'] : array();
        $brand = is_array( $facts['brand_coverage'] ?? null ) ? $facts['brand_coverage'] : array();
        $selected = is_array( $facts['selected_head'] ?? null ) ? $facts['selected_head'] : array();
        $site_ready = true === ( $facts['site_ready'] ?? false );
        $has_census = isset( $census['source_quarantine_count'], $census['asset_quarantine_count'] )
            && is_int( $census['source_quarantine_count'] )
            && is_int( $census['asset_quarantine_count'] )
            && $census['source_quarantine_count'] >= 0
            && $census['asset_quarantine_count'] >= 0;
        $has_proof = true === ( $proof['folder_membership_verified'] ?? false )
            && false === ( $proof['migration_authorized'] ?? null )
            && 1 === preg_match( '/^[a-f0-9]{64}$/D',
                (string) ( $proof['provider_proof_sha256'] ?? '' ) )
            && '' !== (string) ( $facts['source_id'] ?? '' )
            && hash_equals( (string) $facts['source_id'], (string) ( $proof['source_id'] ?? '' ) );
        $host = true === ( $facts['host_verified'] ?? false )
            && true === ( $facts['host_challenge_attempted'] ?? false );
        $release = ! empty( $selected['eligible'] )
            && empty( $selected['blockers'] )
            && (string) ( $selected['source']['resolved_sha'] ?? '' )
                === (string) ( $facts['candidate_sha'] ?? '' );
        $approved_brand = true === ( $brand['ready'] ?? false )
            && empty( $brand['conflicting_required_context_sets'] )
            && isset( $brand['coverage']['brand_strategy']['ready'],
                $brand['coverage']['tone_of_voice']['ready'],
                $brand['coverage']['editorial_guidelines']['ready'] )
            && ! empty( $brand['coverage']['brand_strategy']['ready'] )
            && ! empty( $brand['coverage']['tone_of_voice']['ready'] )
            && ! empty( $brand['coverage']['editorial_guidelines']['ready'] );
        $verified = array(
            'source_inventory' => $has_census,
            'original_file_identity' => $has_proof,
            // Permission to transfer is never inferred from a Drive listing.
            'owner_attestation' => false,
            'host_identity' => $host,
            // No in-process MariaDB simulation or caller JSON can certify crash recovery.
            'durable_db_recovery' => false,
            // Release plan eligibility is only a preflight, not installed readback.
            'certified_package' => false,
            'brand_approval' => $approved_brand,
            // Requires an independent persisted native CPT readback receipt.
            'draft_acceptance' => false,
        );
        $stages = array();
        $blockers = array();
        foreach ( self::stages() as $id => $spec ) {
            $read = $spec['read'];
            $next = $spec['next'];
            $read_registered = '' !== $read && ! empty( $abilities[ $read ] );
            $next_registered = '' !== $next && ! empty( $abilities[ $next ] );
            $external = in_array( $id, array( 'host_identity', 'durable_db_recovery' ), true );
            $missing_executor = '' === $next && $external && ! $verified[ $id ];
            $stage_blockers = array();
            if ( ! $site_ready ) $stage_blockers[] = 'exact_staging_required';
            if ( ! $read_registered && '' !== $read ) $stage_blockers[] = 'remote_read_ability_not_mounted';
            if ( ! $next_registered && '' !== $next ) $stage_blockers[] = 'remote_executor_not_mounted';
            if ( $missing_executor ) $stage_blockers[] = 'independent_signed_host_executor_required';
            if ( 'source_inventory' === $id && ! $has_census ) $stage_blockers[] = 'fresh_census_required';
            if ( 'original_file_identity' === $id && ! $has_proof ) $stage_blockers[] = 'original_folder_12_file_proof_required';
            if ( 'owner_attestation' === $id ) $stage_blockers[] = 'separate_owner_consent_and_rollback_certification_required';
            if ( 'host_identity' === $id && ! $host ) $stage_blockers[] = 'fresh_nonce_signed_host_proof_required';
            if ( 'durable_db_recovery' === $id ) $stage_blockers[] = 'real_cross_request_db_crash_rollback_receipt_required';
            if ( 'certified_package' === $id && ! $release ) $stage_blockers[] = 'exact_head_repository_certification_required';
            if ( 'certified_package' === $id ) $stage_blockers[] = 'installed_head_and_rollback_readback_required';
            if ( 'brand_approval' === $id && ! $approved_brand ) $stage_blockers[] = 'exact_three_category_review_required';
            if ( 'draft_acceptance' === $id ) $stage_blockers[] = 'separate_draft_apply_and_cpt_readback_required';
            $stage_blockers = array_values( array_unique( $stage_blockers ) );
            $state = $verified[ $id ] ? 'EVIDENCE_OBSERVED'
                : ( $missing_executor ? 'REMOTE_EXECUTOR_INTEGRATION_REQUIRED'
                    : ( ! $next_registered ? 'REMOTE_ADAPTER_NOT_MOUNTED' : 'GOVERNED_REMOTE_STEP_AVAILABLE' ) );
            $stages[ $id ] = array(
                'stage_id' => $id, 'state' => $state, 'evidence_observed' => $verified[ $id ],
                'read_ability' => $read, 'apply_ability' => $next,
                'readback_ability' => $spec['readback'],
                'read_registered' => $read_registered, 'apply_registered' => $next_registered,
                'executor' => $spec['executor'], 'operator_role' => $spec['operator_role'],
                'separate_approval_required' => $spec['approval'],
                'remote_interface_declared' => $next_registered,
                'end_to_end_remote_execution_certified' => false,
                'automatic_apply_allowed' => false, 'blockers' => $stage_blockers,
            );
            if ( ! $verified[ $id ] ) $blockers[ $id ] = $stage_blockers;
        }
        $basis = array( 'contract' => self::CONTRACT,
            'site_ready' => $site_ready,
            'source_commit_sha' => (string) ( $facts['installed_sha'] ?? '' ),
            'source_id' => (string) ( $facts['source_id'] ?? '' ),
            'candidate_sha' => (string) ( $facts['candidate_sha'] ?? '' ),
            'census_sha256' => hash( 'sha256', wp_json_encode( $census ) ),
            'proof_sha256' => $has_proof ? $proof['provider_proof_sha256'] : '',
            'coverage_sha256' => hash( 'sha256', wp_json_encode( $brand ) ),
            'host_nonce_verified' => $host,
            'selected_plan_sha256' => (string) ( $selected['plan_sha256'] ?? '' ),
            'stages' => $stages,
        );
        $sha = hash( 'sha256', wp_json_encode( $basis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        return array(
            'contract' => self::CONTRACT, 'state' => $blockers ? 'REMOTE_RECOVERY_INCOMPLETE' : 'REMOTE_RECOVERY_READBACK_COMPLETE',
            'plan_sha256' => $sha, 'source_commit_sha' => $basis['source_commit_sha'],
            'candidate_sha' => $basis['candidate_sha'],
            'stages' => $stages, 'remaining_stages' => array_keys( $blockers ),
            'blockers_by_stage' => $blockers, 'remote_orchestration_available' => true,
            'full_operational_acceptance' => false,
            'production_mutation_authorized' => false, 'breakglass_authorized' => false,
            'approval_issued' => false, 'mutation_performed' => false, 'read_only' => true,
        );
    }

    public static function prepare( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input ) ) return self::denial( 'permission_required' );
        $stage_id = (string) ( $input['stage_id'] ?? '' );
        $sha = (string) ( $input['expected_plan_sha256'] ?? '' );
        if ( ! isset( self::stages()[ $stage_id ] ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) ) return self::denial( 'invalid_stage_or_plan' );
        $snapshot = self::status( array(
            'source_id' => (string) ( $input['source_id'] ?? '' ),
            'candidate_sha' => (string) ( $input['candidate_sha'] ?? '' ),
            // A second observation must never silently rescan Drive, nor use
            // a prior observation's cached success to authorize execution.
        ) );
        if ( is_wp_error( $snapshot ) ) return $snapshot;
        if ( ! hash_equals( $sha, (string) $snapshot['plan_sha256'] ) )
            return self::denial( 'snapshot_changed_replan_required' );
        $stage = $snapshot['stages'][ $stage_id ];
        return array(
            'contract' => 'mad4b.brand-core-remote-recovery-handoff.v1',
            'state' => 'SEPARATE_GOVERNED_EXECUTION_REQUIRED',
            'stage' => $stage,
            'plan_sha256' => $sha,
            'separate_exact_approval_required' => $stage['separate_approval_required'],
            'next_action' => $stage['read_ability'] && ! $stage['evidence_observed']
                ? $stage['read_ability'] : $stage['apply_ability'],
            'ready_for_dispatch' => false, 'approval_issued' => false,
            'mutation_performed' => false, 'read_only' => true,
        );
    }
}
