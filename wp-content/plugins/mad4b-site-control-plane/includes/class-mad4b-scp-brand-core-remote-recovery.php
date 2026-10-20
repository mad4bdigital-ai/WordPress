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
    const ALTERNATIVES_ABILITY = 'mad4b/brand-core-recovery-alternatives';
    const WORDPRESS_MODE_ABILITY = 'mad4b/brand-core-wordpress-mode';
    const SOURCE_PREFLIGHT_ABILITY = 'mad4b/context-source-onboarding-preflight';
    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        if ( ! class_exists( 'MAD4B_SCP_Recovery_Alternatives', false ) )
            require_once __DIR__ . '/class-mad4b-scp-recovery-alternatives.php';
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' ) ) return;
        $definitions = array(
            self::SOURCE_PREFLIGHT_ABILITY => array(
                'label' => 'Preflight Governed Google Drive Source Onboarding and Recovery',
                'callback' => 'source_onboarding_preflight',
                'properties' => array(
                    'external_root_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ),
                    'source_mode' => array( 'type' => 'string', 'enum' => array( 'governed', 'task_attachment' ) ),
                    'write_policy' => array( 'type' => 'string', 'enum' => array( 'read_only', 'repair_only', 'managed' ) ),
                    'recursive' => array( 'type' => 'boolean' ),
                ),
                'required' => array(),
            ),
            self::WORDPRESS_MODE_ABILITY => array(
                'label' => 'WordPress-only Brand Core Recovery and File Review',
                'callback' => 'wordpress_mode',
                'properties' => array(
                    'source_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'include_provider_scan' => array( 'type' => 'boolean', 'default' => false ),
                    'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 30 ),
                ),
                'required' => array(),
            ),
            self::STATUS_ABILITY => array(
                'label' => 'Diagnose Full Brand Core Remote Recovery',
                'callback' => 'status',
                'properties' => array(
                    'source_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'include_provider_scan' => array( 'type' => 'boolean', 'default' => false ),
                    'include_host_challenge' => array( 'type' => 'boolean', 'default' => false ),
                    'include_operational_diagnostics' => array( 'type' => 'boolean', 'default' => false ),
                    'candidate_sha' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{40}$' ),
                ),
                'required' => array(),
            ),
            self::ALTERNATIVES_ABILITY => array(
                'label' => 'Discover Governed Alternative Recovery Routes',
                'callback' => 'alternatives',
                'properties' => array( 'domain' => array( 'type' => 'string',
                    'enum' => array( 'all', 'host_runner', 'database_recovery', 'candidate_delivery', 'wordpress_native' ) ) ),
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
                    'include_provider_scan' => array( 'type' => 'boolean', 'default' => false ),
                    'include_host_challenge' => array( 'type' => 'boolean', 'default' => false ),
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

    /**
     * One bounded read-only entry point shared by wp-admin and MCP.
     * Before site binding, never inspect or infer ownership from raw
     * quarantine records. The selected folder is a claim, not proof.
     * No ticket, Drive mutation, registry write or implicit approval.
     */
    public static function source_onboarding_preflight( $input = array() ) {
        if ( ! self::can_read() )
            return self::denial( 'permission_required' );
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'external_root_id', 'source_mode', 'write_policy', 'recursive' ) ) )
            return self::denial( 'unexpected_parameter' );
        $folder = trim( (string) ( $input['external_root_id'] ?? '' ) );
        if ( '' !== $folder &&
            ( strlen( $folder ) > 255 || 'root' === strtolower( $folder )
              || 1 !== preg_match( '/^[A-Za-z0-9_.-]+$/D', $folder ) ) )
            return self::denial( 'invalid_folder_identity' );
        $mode = (string) ( $input['source_mode'] ?? 'governed' );
        $policy = (string) ( $input['write_policy'] ?? 'read_only' );
        if ( ! in_array( $mode, array( 'governed', 'task_attachment' ), true )
            || ! in_array( $policy, array( 'read_only', 'repair_only', 'managed' ), true )
            || ( 'task_attachment' === $mode && 'read_only' !== $policy )
            || ( array_key_exists( 'recursive', $input ) && ! is_bool( $input['recursive'] ) ) )
            return self::denial( 'invalid_source_policy' );
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! class_exists( 'MAD4B_SCP_Context_Authority' ) )
            return self::denial( 'site_or_context_unavailable' );
        $before = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $before ) || empty( $before['configured'] )
            || 'staging' !== (string) ( $before['environment'] ?? '' )
            || empty( $before['origin_match'] )
            || empty( $before['environment_match'] )
            || empty( $before['authority_ready'] ) )
            return self::denial( 'exact_staging_site_required' );
        $profile = MAD4B_SCP_Context_Authority::profile();
        $census = MAD4B_SCP_Context_Authority::legacy_reconciliation_census();
        if ( is_wp_error( $census ) ) {
            if ( 'mad4b_integrity_scope_blocked' !== $census->get_error_code() ) return $census;
            $detail = $census->get_error_data();
            $reason = is_array( $detail ) ? ( $detail['reason_code'] ?? '' ) : '';
            if ( ! is_string( $reason ) || ! preg_match( '/^[A-Z][A-Z0-9_]{0,79}$/D', $reason ) ) $reason = 'DEPLOYMENT_SCOPE_UNRESOLVED';
            // Scope failures other than the existing unbound-site census
            // cannot reveal or count legacy records. Unknown is not zero.
            return array(
                'contract' => 'mad4b.context-source-onboarding-preflight.v1',
                'state' => 'OPERATIONAL_SCOPE_RECOVERY_REQUIRED',
                'reason_code' => $reason,
                'next_ability' => 'mad4b/deployment-mode-status',
                'operational_scope_verified' => false,
                'quarantined_source_count' => null, 'quarantined_asset_count' => null,
                'quarantine_counts_verified' => false,
                'folder_selected' => '' !== $folder,
                'folder_identity_sha256' => '' !== $folder ? hash( 'sha256', $folder ) : '',
                'write_policy_requested' => $policy,
                'new_source_write_eligible' => false, 'new_source_authorized' => false,
                'source_registry_modified' => false, 'legacy_ownership_inferred' => false,
                'production_authorized' => false, 'read_only' => true, 'mutation_performed' => false,
            );
        }
        if ( ! is_array( $census ) ) return self::denial( 'census_unavailable' );
        $binding = class_exists( 'MAD4B_SCP_Deployment_Binding_Native' )
            ? MAD4B_SCP_Deployment_Binding_Native::status() : array();
        $binding_state = is_array( $binding )
            ? (string) ( $binding['state'] ?? 'UNKNOWN' ) : 'UNKNOWN';
        $connected = class_exists( 'MAD4B_SCP_Google_Drive_Context' )
            ? MAD4B_SCP_Google_Drive_Context::connection_status() : array();
        $connected = is_array( $connected ) ? $connected : array();
        $safe_google = ! empty( $connected['connected'] )
            && empty( $connected['revocation_pending'] )
            && empty( $connected['token_unreadable'] );
        $scope_verified = 'TRUSTED_SCOPE_VERIFIED' === (string) ( $census['state'] ?? '' )
            && true === ( $census['operational_scope_verified'] ?? false )
            && ! empty( $before['deployment_binding_bound'] )
            && ! empty( $before['deployment_binding_configured'] )
            && ! empty( $before['deployment_binding_match'] );
        $quarantined = (int) ( $census['source_quarantine_count'] ?? 0 )
            + (int) ( $census['asset_quarantine_count'] ?? 0 );
        $status = 'READY_FOR_GOVERNED_SOURCE_PLAN';
        $next = 'context/governed-source-upsert';
        $needs_ticket = true;
        if ( ! $scope_verified ) {
            $status = 'DEPLOYMENT_BINDING_RECOVERY_REQUIRED';
            $next = 'READY_FOR_OWNER_APPROVAL' === $binding_state
                ? 'mad4b/deployment-binding-native-plan'
                : 'mad4b/deployment-binding-native-status';
        } elseif ( empty( $profile ) ) {
            $status = 'BRAND_PROFILE_REQUIRED';
            $next = 'context/brand-profile-save';
        } elseif ( $quarantined > 0 ) {
            $status = 'LEGACY_SOURCE_RECONCILIATION_REQUIRED';
            $next = 'context/legacy-owner-transfer-discover';
        } elseif ( ! $safe_google || empty( $connected['read_available'] ) ) {
            $status = 'GOOGLE_CONNECTION_RECOVERY_REQUIRED';
            $next = 'context/runtime-readiness';
        } elseif ( 'managed' === $policy && empty( $connected['write_available'] ) ) {
            $status = 'GOOGLE_WRITE_CONSENT_REQUIRED';
            $next = 'context/provider-capabilities';
        } elseif ( '' === $folder ) {
            $status = 'FOLDER_SELECTION_REQUIRED';
            $next = self::SOURCE_PREFLIGHT_ABILITY;
            $needs_ticket = false;
        } else {
            // Existing authorized source: no duplicate creation or ownership
            // migration. Only the existing per-source policy may be reviewed.
            foreach ( MAD4B_SCP_Context_Authority::sources() as $source ) {
                if ( ! is_array( $source ) ) continue;
                if ( hash_equals( $folder, (string) ( $source['external_root_id'] ?? '' ) )
                    && $mode === (string) ( $source['mode'] ?? '' ) ) {
                    $status = 'EXISTING_SOURCE_POLICY_REVIEW_REQUIRED';
                    $next = 'context/brand-core-coverage';
                    break;
                }
            }
        }
        $after = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $after )
            || (string) ( $before['profile_digest'] ?? '' )
                !== (string) ( $after['profile_digest'] ?? '' ) )
            return self::denial( 'site_profile_drift_replan' );
        return array(
            'contract' => 'mad4b.context-source-onboarding-preflight.v1',
            'state' => $status, 'next_ability' => $next,
            'site_uuid' => (string) ( $before['site_uuid'] ?? '' ),
            'profile_revision' => (int) ( $before['revision'] ?? 0 ),
            'deployment_binding_state' => $binding_state,
            'operational_scope_verified' => $scope_verified,
            'quarantined_source_count' => (int) ( $census['source_quarantine_count'] ?? 0 ),
            'quarantined_asset_count' => (int) ( $census['asset_quarantine_count'] ?? 0 ),
            'folder_selected' => '' !== $folder,
            'folder_identity_sha256' => '' !== $folder ? hash( 'sha256', $folder ) : '',
            'folder_membership_verified' => false,
            'write_policy_requested' => $policy,
            'write_permission_available' => ! empty( $connected['write_available'] ),
            'new_source_write_eligible' => 'READY_FOR_GOVERNED_SOURCE_PLAN' === $status,
            'new_source_authorized' => false,
            'owner_approval_required_for_mutation' => $needs_ticket,
            'legacy_ownership_inferred' => false,
            'quarantine_mutated' => false,
            'source_registry_modified' => false,
            'production_authorized' => false,
            'read_only' => true, 'mutation_performed' => false,
        );
    }

    /**
     * Host-free functional workflow, NOT a Host identity, DB crash or release
     * certificate. Existing Context and governed owner approvals are reused.
     */
    public static function wordpress_mode( $input = array() ) {
        if ( ! self::can_read() ) return self::denial( 'permission_required' );
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'source_id', 'limit', 'include_provider_scan' ) ) )
            return self::denial( 'unexpected_parameter' );
        $limit = $input['limit'] ?? 15;
        if ( ! is_int( $limit ) || $limit < 1 || $limit > 30 )
            return self::denial( 'invalid_limit' );
        $site = class_exists( 'MAD4B_SCP_Site_Profile' )
            ? MAD4B_SCP_Site_Profile::status() : array();
        if ( ! is_array( $site ) || 'staging' !== ( $site['environment'] ?? '' )
            || empty( $site['origin_match'] ) || empty( $site['environment_match'] )
            || empty( $site['site_uuid'] ) || empty( $site['profile_digest'] ) )
            return self::denial( 'not_exact_staging' );
        if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) )
            return self::denial( 'context_unavailable' );
        $census = MAD4B_SCP_Context_Authority::legacy_reconciliation_census();
        $coverage = MAD4B_SCP_Context_Authority::brand_core_coverage();
        $discovery = MAD4B_SCP_Context_Authority::legacy_owner_transfer_discover( array() );
        if ( is_wp_error( $census ) ) return $census;
        if ( is_wp_error( $discovery ) ) return $discovery;
        if ( ! is_array( $census ) || ! is_array( $coverage ) || ! is_array( $discovery ) )
            return self::denial( 'context_snapshot_unavailable' );
        $candidates = is_array( $discovery['eligible_unbound_candidates'] ?? null )
            ? $discovery['eligible_unbound_candidates'] : array();
        $ids = array();
        foreach ( $candidates as $item )
            if ( is_array( $item ) && preg_match( '/^[a-f0-9]{64}$/D',
                (string) ( $item['source_id'] ?? '' ) ) )
                $ids[] = (string) $item['source_id'];
        $selected = (string) ( $input['source_id'] ?? '' );
        if ( '' === $selected && 1 === count( $ids ) ) $selected = $ids[0];
        if ( '' !== $selected && ! in_array( $selected, $ids, true ) )
            return self::denial( 'selected_source_not_eligible' );
        $binding_unverified = 'UNVERIFIED_DEPLOYMENT_BINDING' === (string) ( $census['state'] ?? '' )
            || true !== ( $census['operational_scope_verified'] ?? false );
        $binding = class_exists( 'MAD4B_SCP_Deployment_Binding_Native' )
            ? MAD4B_SCP_Deployment_Binding_Native::status() : array();
        $binding_state = is_array( $binding ) ? (string) ( $binding['state'] ?? '' ) : '';
        $binding_next = 'READY_FOR_OWNER_APPROVAL' === $binding_state
            ? 'mad4b/deployment-binding-native-plan'
            : ( 'ALREADY_HAS_PROFILE_BINDING' === $binding_state
                ? 'context/legacy-reconciliation-census'
                : 'mad4b/deployment-binding-native-status' );
        $scope = array( 'selected_source_id' => $selected,
            'candidate_count' => count( $ids ),
            'unclassified_quarantined_sources' => (int) ( $discovery['foreign_or_conflicted_records'] ?? 0 ),
            'scope_verified' => false,
            'owner_approval_required' => true, 'file_ids_supplied_by_user' => false,
            'next_ability' => $binding_unverified
                ? $binding_next : 'mad4b/selected-folder-scope-proof' );
        if ( ! $binding_unverified && '' !== $selected && ! empty( $input['include_provider_scan'] ) ) {
            if ( ! class_exists( 'MAD4B_SCP_Runtime_Obstacle_Resolution' ) )
                return self::denial( 'folder_proof_unavailable' );
            $proof = MAD4B_SCP_Runtime_Obstacle_Resolution::selected_folder_proof(
                array( 'source_id' => $selected ) );
            if ( is_wp_error( $proof ) )
                $scope['provider_error_code'] = $proof->get_error_code();
            else {
                $scope['scope_verified'] = true === ( $proof['scope_verified'] ?? false );
                $scope['proof_sha256'] = (string) ( $proof['scope_proof_sha256'] ?? '' );
                $scope['matched_file_count'] = (int) ( $proof['matched_file_count'] ?? 0 );
            }
        }
        // The queue excludes quarantined/foreign records by design.
        $queue = MAD4B_SCP_Context_Authority::review_queue();
        if ( ! is_array( $queue ) || ! is_array( $queue['items'] ?? null ) )
            return self::denial( 'review_queue_unavailable' );
        $files = array(); $total = count( $queue['items'] );
        foreach ( array_slice( $queue['items'], 0, $limit ) as $item ) {
            if ( ! is_array( $item ) ) continue;
            $id = (string) ( $item['asset_id'] ?? '' );
            if ( ! preg_match( '/^[a-f0-9]{64}$/D', $id ) ) continue;
            $plan = MAD4B_SCP_Context_Authority::remote_owner_review_plan(
                array( 'asset_id' => $id ) );
            $ok = is_array( $plan );
            $files[] = array( 'asset_id' => $id,
                'source_id' => (string) ( $item['source_id'] ?? '' ),
                'title' => (string) ( $item['title'] ?? '' ),
                'category' => (string) ( $item['category'] ?? '' ),
                'required' => ! empty( $item['required'] ),
                'content_hash' => (string) ( $item['content_hash'] ?? '' ),
                'review_status' => (string) ( $item['review_status'] ?? '' ),
                'plan_ready' => $ok,
                'exact_plan_sha256' => $ok ? (string) $plan['plan_sha256'] : '',
                'blocker' => is_wp_error( $plan ) ? $plan->get_error_code() : '',
                'next_read' => 'context/remote-owner-review-plan',
                'next_governed_write' => $ok ? 'context/remote-owner-review-apply' : '',
                'separate_human_approved_ticket_required' => true );
        }
        // A second observation prevents a mixed provider/registry snapshot.
        $after = MAD4B_SCP_Context_Authority::legacy_reconciliation_census();
        $after_coverage = MAD4B_SCP_Context_Authority::brand_core_coverage();
        $after_site = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $after ) || ! is_array( $after_coverage )
            || wp_json_encode( $census ) !== wp_json_encode( $after )
            || wp_json_encode( $coverage ) !== wp_json_encode( $after_coverage )
            || ( $after_site['profile_digest'] ?? '' ) !== $site['profile_digest'] )
            return self::denial( 'snapshot_drift_replan' );
        $quarantine = (int) ( $census['source_quarantine_count'] ?? 0 )
            + (int) ( $census['asset_quarantine_count'] ?? 0 );
        $native = class_exists( 'MAD4B_SCP_Native_Recovery' )
            ? MAD4B_SCP_Native_Recovery::status() : array();
        return array(
            'contract' => 'mad4b.wordpress-only-brand-core.v1',
            'state' => $binding_unverified ? 'WORDPRESS_NATIVE_DEPLOYMENT_BINDING_REQUIRED'
                : ( $quarantine > 0 ? 'OWNER_TRANSFER_BLOCKED'
                : ( ! empty( $coverage['ready'] )
                    ? 'BRAND_CORE_READY' : 'FILE_APPROVAL_OR_SCAN_REQUIRED' ) ),
            'site_uuid' => (string) $site['site_uuid'],
            'profile_digest' => (string) $site['profile_digest'],
            'source' => $scope,
            'quarantine_count' => $quarantine,
            'native_scratch_state' => is_array( $native )
                ? (string) ( $native['state'] ?? 'UNKNOWN' ) : 'UNAVAILABLE',
            'native_scratch_remaining_seconds' => is_array( $native )
                ? (int) ( $native['remaining_seconds'] ?? 0 ) : 0,
            'native_scratch_next_ability' => is_array( $native )
                ? (string) ( $native['next_ability'] ?? '' ) : '',
            'binding_lifecycle' => array(
                'state' => $binding_state,
                'eligible' => is_array( $binding ) && true === ( $binding['eligible'] ?? false ),
                'next_ability' => $binding_next,
                'separate_human_owner_ticket_required' => true,
                'new_request_readback_required' => true,
                'site_uuid' => (string) $site['site_uuid'],
                'cross_site_reuse_allowed' => false,
                'independent_host_attestation_certified' => false ),
            'workflow' => array(
                'native_binding_status' => 'mad4b/deployment-binding-native-status',
                'native_binding_plan' => 'mad4b/deployment-binding-native-plan',
                'native_binding_apply' => 'mad4b/deployment-binding-native-apply',
                'folder' => 'mad4b/selected-folder-scope-proof',
                'native_shadow_plan' => 'mad4b/native-recovery-plan',
                'native_shadow_start' => 'mad4b/native-recovery-start',
                'native_shadow_readback' => 'mad4b/native-recovery-readback',
                'native_shadow_cleanup' => 'mad4b/native-recovery-cleanup',
                'owner_transfer_plan' => 'context/legacy-owner-transfer-plan',
                'owner_transfer_write' => 'context/legacy-owner-transfer-apply',
                'functional_transfer_plan' => 'context/native-owner-transfer-plan',
                'functional_transfer_write' => 'context/native-owner-transfer-apply',
                'functional_transfer_readback' => 'context/native-owner-transfer-readback',
                'owner_transfer_readback' => 'context/legacy-owner-transfer-readback',
                'content_scan' => 'context/source-scan-plan',
                'content_scan_write' => 'context/source-scan-apply',
                'file_review_plan' => 'context/remote-owner-review-plan',
                'file_review_write' => 'context/remote-owner-review-apply',
                'coverage' => 'context/brand-core-coverage' ),
            'files' => $files, 'file_count' => $total,
            'page_size' => count( $files ), 'paging_required' => $total > count( $files ),
            'missing_categories' => (array) ( $coverage['missing_required_context_sets'] ?? array() ),
            'functional_wordpress_only_mode' => true,
            'native_binding_requires_separate_owner_approval' => $binding_unverified,
            'native_binding_is_not_host_attestation' => true,
            'separate_host_runner_for_native_scratch' => false,
            'legacy_ownership_transfer_requires_existing_host_and_rollback_gate' => $quarantine > 0,
            'alternative_wordpress_functional_transfer_available_after_native_shadow' => true,
            'functional_transfer_requires_separate_human_ticket' => true,
            'functional_transfer_is_not_crash_or_rollback_certification' => true,
            'automatic_owner_transfer' => false, 'automatic_file_approval' => false,
            'native_crash_recovery_certified' => false,
            'release_or_publication_authorized' => false,
            'production_authorized' => false,
            'read_only' => true, 'mutation_performed' => false );
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
                'read' => 'mad4b/host-doctor',
                'preflight' => 'mad4b/host-operation-plan',
                'next' => 'mad4b/host-operation-apply',
                'readback' => 'mad4b/host-operation-receipt',
                'executor' => 'enrolled_signed_host_runner', 'operator_role' => 'host_owner',
                'approval' => true,
            ),
            'durable_db_recovery' => array(
                'read' => 'mad4b/host-operation-capabilities',
                'next' => '', 'readback' => '',
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
                'read' => 'context/review-queue',
                'preflight' => 'context/remote-owner-review-plan',
                'next' => 'context/remote-owner-review-apply',
                'readback' => 'context/brand-core-coverage',
                'executor' => 'governed_context_owner_oauth_with_separate_human_ticket',
                'operator_role' => 'brand_owner',
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
                'include_host_challenge', 'include_operational_diagnostics', 'candidate_sha' ), true ) )
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
            'evidence_drift' => false,
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
        // Independent second read after provider I/O. No two mixed WordPress
        // snapshots may produce a usable approval or recovery handoff.
        if ( class_exists( 'MAD4B_SCP_Context_Authority' ) ) {
            $census_again = MAD4B_SCP_Context_Authority::legacy_reconciliation_census();
            $coverage_again = MAD4B_SCP_Context_Authority::brand_core_coverage();
            if ( ! is_array( $census_again ) || ! is_array( $coverage_again )
                || wp_json_encode( $facts['census'] ) !== wp_json_encode( $census_again )
                || wp_json_encode( $facts['brand_coverage'] ) !== wp_json_encode( $coverage_again ) )
                $facts['evidence_drift'] = true;
        }
        $site_again = class_exists( 'MAD4B_SCP_Site_Profile' )
            ? MAD4B_SCP_Site_Profile::status() : array();
        foreach ( array( 'site_uuid', 'environment', 'canonical_origin',
            'profile_digest', 'revision', 'origin_match', 'environment_match' ) as $key ) {
            if ( ( $site[ $key ] ?? null ) !== ( $site_again[ $key ] ?? null ) )
                $facts['evidence_drift'] = true;
        }
        $available = array();
        if ( function_exists( 'wp_has_ability' ) ) {
            foreach ( self::stages() as $stage ) {
                foreach ( array( 'read', 'preflight', 'next', 'readback' ) as $slot ) {
                    $name = (string) ( $stage[ $slot ] ?? '' );
                    if ( '' !== $name && ! array_key_exists( $name, $available ) )
                        $available[ $name ] = (bool) wp_has_ability( $name );
                }
            }
        }
        $report = self::reduce( $facts, $available );
        if ( ! empty( $input['include_operational_diagnostics'] ) ) {
            // Opt-in read-only inspection: no source scan, ticket minting, Google
            // refresh or permission mutation. Missing evidence remains blocked.
            require_once __DIR__ . '/class-mad4b-scp-brand-authority-closure.php';
            $google = class_exists( 'MAD4B_SCP_Google_Drive_Context' )
                ? MAD4B_SCP_Google_Drive_Context::public_connection_status() : array();
            $write = class_exists( 'MAD4B_SCP_Staging_Write_Authority' )
                ? MAD4B_SCP_Staging_Write_Authority::reconciliation_plan() : array();
            $context = class_exists( 'MAD4B_SCP_Context_Authority' )
                ? MAD4B_SCP_Context_Authority::status() : array();
            $report['operational_diagnostics'] = MAD4B_SCP_Brand_Authority_Closure::summarize( array(
                'exact_staging' => $staging && ! $facts['evidence_drift'],
                'google' => is_array( $google ) ? $google : array(),
                'write' => is_array( $write ) ? $write : array(),
                'context' => is_array( $context ) ? $context : array(),
                'brand' => $facts['brand_coverage'],
            ) );
            // Read-only static certification snapshot of the exact governed
            // Context source-scan Ability. No write-lane authorization,
            // Circuit Breaker reservation or provider operation is attempted.
            $report['operational_diagnostics']['source_scan_transport'] =
                class_exists( 'MAD4B_SCP_Provider_Transport_Eligibility' )
                ? MAD4B_SCP_Provider_Transport_Eligibility::inspect_target_certification(
                    'write', 'context/source-scan-apply'
                ) : array(
                    'reason_code' => 'provider_inspection_unavailable',
                    'transport_eligible' => null,
                    'authorizing' => false,
                    'mutation_performed' => false,
                );
        }
        $report['alternative_routes'] = MAD4B_SCP_Recovery_Alternatives::discover( 'all' );
        return $report;
    }

    /** Pure evidence reducer: capability discovery never becomes approval. */
    public static function reduce( array $facts, array $abilities ) {
        $census = is_array( $facts['census'] ?? null ) ? $facts['census'] : array();
        $proof = is_array( $facts['proof'] ?? null ) ? $facts['proof'] : array();
        $brand = is_array( $facts['brand_coverage'] ?? null ) ? $facts['brand_coverage'] : array();
        $selected = is_array( $facts['selected_head'] ?? null ) ? $facts['selected_head'] : array();
        $site_ready = true === ( $facts['site_ready'] ?? false );
        $evidence_drift = true === ( $facts['evidence_drift'] ?? false );
        $has_census = isset( $census['source_quarantine_count'], $census['asset_quarantine_count'] )
            && is_int( $census['source_quarantine_count'] )
            && is_int( $census['asset_quarantine_count'] )
            && $census['source_quarantine_count'] >= 0
            && $census['asset_quarantine_count'] >= 0
            && false !== ( $census['operational_scope_verified'] ?? null )
            && 1 === preg_match( '/^[a-f0-9]{64}$/D',
                (string) ( $census['scope_fingerprint'] ?? '' ) )
            && 'UNVERIFIED_DEPLOYMENT_BINDING' !== ( $census['state'] ?? '' );
        $has_census = $has_census && ! $evidence_drift;
        $has_proof = $has_census && ! $evidence_drift
            && true === ( $proof['folder_membership_verified'] ?? false )
            && false === ( $proof['migration_authorized'] ?? null )
            && 1 === preg_match( '/^[a-f0-9]{64}$/D',
                (string) ( $proof['provider_proof_sha256'] ?? '' ) )
            && '' !== (string) ( $facts['source_id'] ?? '' )
            && hash_equals( (string) $facts['source_id'], (string) ( $proof['source_id'] ?? '' ) );
        $host = ! $evidence_drift
            && true === ( $facts['host_verified'] ?? false )
            && true === ( $facts['host_challenge_attempted'] ?? false );
        $release = ! $evidence_drift && ! empty( $selected['eligible'] )
            && empty( $selected['blockers'] )
            && (string) ( $selected['source']['resolved_sha'] ?? '' )
                === (string) ( $facts['candidate_sha'] ?? '' );
        $approved_brand = $has_census
            && 0 === $census['source_quarantine_count']
            && 0 === $census['asset_quarantine_count']
            && true === ( $brand['ready'] ?? false )
            && empty( $brand['conflicting_required_context_sets'] )
            && isset( $brand['coverage']['brand_strategy']['ready'],
                $brand['coverage']['tone_of_voice']['ready'],
                $brand['coverage']['editorial_guidelines']['ready'] )
            && ! empty( $brand['coverage']['brand_strategy']['ready'] )
            && ! empty( $brand['coverage']['tone_of_voice']['ready'] )
            && ! empty( $brand['coverage']['editorial_guidelines']['ready'] );
        // Transfer of unbound legacy ownership is required only when
        // original quarantined records still exist (or census is not
        // independently trustworthy). An already clean first-party Context
        // source must not deadlock forever on a nonexistent transfer.
        $legacy_transfer_required = ! $has_census
            || (int) ( $census['source_quarantine_count'] ?? 0 ) > 0
            || (int) ( $census['asset_quarantine_count'] ?? 0 ) > 0;
        $dependencies = array(
            'source_inventory' => array(),
            'original_file_identity' => array( 'source_inventory' ),
            'owner_attestation' => array( 'original_file_identity', 'host_identity',
                'durable_db_recovery' ),
            'host_identity' => array( 'source_inventory' ),
            'durable_db_recovery' => array( 'host_identity' ),
            'certified_package' => array(),
            'brand_approval' => $legacy_transfer_required
                ? array( 'source_inventory', 'owner_attestation', 'certified_package' )
                : array( 'source_inventory', 'certified_package' ),
            'draft_acceptance' => array( 'brand_approval', 'certified_package' ),
        );
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
            $preflight = (string) ( $spec['preflight'] ?? $read );
            $next = $spec['next'];
            $read_registered = '' !== $read && ! empty( $abilities[ $read ] );
            $preflight_registered = '' !== $preflight && ! empty( $abilities[ $preflight ] );
            $next_registered = '' !== $next && ! empty( $abilities[ $next ] );
            $external = in_array( $id, array( 'host_identity', 'durable_db_recovery' ), true );
            $missing_executor = '' === $next && $external && ! $verified[ $id ];
            $stage_blockers = array();
            $waiting_on = array();
            foreach ( $dependencies[ $id ] as $dependency ) {
                if ( empty( $verified[ $dependency ] ) ) $waiting_on[] = $dependency;
            }
            if ( $waiting_on ) $stage_blockers[] = 'upstream_evidence_required';
            if ( $evidence_drift ) $stage_blockers[] = 'cross_read_snapshot_drift';
            if ( ! $site_ready ) $stage_blockers[] = 'exact_staging_required';
            if ( ! $read_registered && '' !== $read ) $stage_blockers[] = 'remote_read_ability_not_mounted';
            if ( ! $preflight_registered && '' !== $preflight ) $stage_blockers[] = 'remote_preflight_ability_not_mounted';
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
            $state = $evidence_drift ? 'SNAPSHOT_DRIFT_REPLAN_REQUIRED'
                : ( $verified[ $id ] ? 'EVIDENCE_OBSERVED'
                : ( 'host_identity' === $id ? 'SIGNED_HOST_PROOF_REQUIRED'
                    : ( $missing_executor ? 'REMOTE_EXECUTOR_INTEGRATION_REQUIRED'
                    : ( ! $next_registered ? 'REMOTE_ADAPTER_NOT_MOUNTED'
                        : ( $waiting_on ? 'UPSTREAM_EVIDENCE_REQUIRED'
                            : 'GOVERNED_REMOTE_STEP_AVAILABLE' ) ) ) ) );
            $stages[ $id ] = array(
                'stage_id' => $id, 'state' => $state, 'evidence_observed' => $verified[ $id ],
                'read_ability' => $read, 'preflight_ability' => $preflight,
                'apply_ability' => $next,
                'readback_ability' => $spec['readback'],
                'read_registered' => $read_registered,
                'preflight_registered' => $preflight_registered, 'apply_registered' => $next_registered,
                'executor' => $spec['executor'], 'operator_role' => $spec['operator_role'],
                'separate_approval_required' => $spec['approval'],
                'upstream_stages' => $dependencies[ $id ],
                'waiting_on_upstream' => $waiting_on,
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
            'cross_read_snapshot_drift' => $evidence_drift,
            'selected_plan_sha256' => (string) ( $selected['plan_sha256'] ?? '' ),
            'stages' => $stages,
        );
        $sha = hash( 'sha256', wp_json_encode( $basis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        // Functional Brand recovery is distinct from Disaster Recovery. The
        // original eight-stage Host/crash assessment remains backwards
        // compatible and MUST NOT mark MariaDB crash recovery as certified.
        $functional_state = ! $has_census ? 'SITE_BINDING_OR_CENSUS_REQUIRED'
            : ( $legacy_transfer_required
                ? ( $has_proof ? 'NATIVE_SHADOW_AND_OWNER_APPROVAL_REQUIRED'
                    : 'ORIGINAL_FOLDER_PROOF_REQUIRED' )
                : ( $approved_brand ? 'BRAND_CORE_COVERAGE_READY'
                    : 'FRESH_SCAN_AND_FILE_APPROVAL_REQUIRED' ) );
        $functional = array(
            'contract' => 'mad4b.brand-core-functional-lifecycle.v1',
            'state' => $evidence_drift ? 'SNAPSHOT_DRIFT_REPLAN_REQUIRED'
                : $functional_state,
            'site_scope_verified' => $has_census,
            'original_folder_proven' => $has_proof,
            'legacy_transfer_required' => $legacy_transfer_required,
            'brand_core_ready' => $approved_brand,
            'required_owner_ability' => 'context/native-owner-transfer-apply',
            'functional_readback_ability' => 'context/native-owner-transfer-readback',
            'next_ability' => ! $has_census
                ? 'mad4b/deployment-binding-native-status'
                : ( $legacy_transfer_required
                    ? ( $has_proof ? 'mad4b/native-recovery-plan'
                        : 'context/legacy-owner-transfer-evidence' )
                    : ( $approved_brand ? 'context/brand-core-coverage'
                        : 'context/source-scan-plan' ) ),
            'separate_human_approval_required' => true,
            'ready_for_publication' => false,
            'mariadb_crash_certified' => false, 'rollback_certified' => false,
            'release_certified' => false, 'production_authorized' => false );
        return array(
            'functional_lifecycle' => $functional,
            'disaster_recovery' => array(
                'state' => $evidence_drift ? 'SNAPSHOT_DRIFT_REPLAN_REQUIRED'
                    : ( $blockers ? 'REMOTE_RECOVERY_INCOMPLETE'
                        : 'REMOTE_RECOVERY_READBACK_COMPLETE' ),
                'mariadb_crash_certified' => false,
                'independent_host_required' => true,
                'release_certified' => false ),
            'contract' => self::CONTRACT, 'state' => $evidence_drift ? 'SNAPSHOT_DRIFT_REPLAN_REQUIRED'
                : ( $blockers ? 'REMOTE_RECOVERY_INCOMPLETE' : 'REMOTE_RECOVERY_READBACK_COMPLETE' ),
            'plan_sha256' => $sha, 'source_commit_sha' => $basis['source_commit_sha'],
            'candidate_sha' => $basis['candidate_sha'],
            'stages' => $stages, 'remaining_stages' => array_keys( $blockers ),
            'blockers_by_stage' => $blockers, 'remote_orchestration_available' => true,
            'full_operational_acceptance' => false,
            'production_mutation_authorized' => false, 'breakglass_authorized' => false,
            'approval_issued' => false, 'mutation_performed' => false, 'read_only' => true,
        );
    }

    public static function alternatives( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input ) )
            return self::denial( 'permission_required' );
        if ( array_diff( array_keys( $input ), array( 'domain' ) ) )
            return self::denial( 'unexpected_parameter' );
        $site = class_exists( 'MAD4B_SCP_Site_Profile' )
            ? MAD4B_SCP_Site_Profile::status() : array();
        if ( ! is_array( $site ) || empty( $site['origin_match'] )
            || empty( $site['environment_match'] )
            || 'staging' !== (string) ( $site['environment'] ?? '' ) )
            return self::denial( 'not_exact_staging' );
        if ( ! class_exists( 'MAD4B_SCP_Recovery_Alternatives', false ) )
            require_once __DIR__ . '/class-mad4b-scp-recovery-alternatives.php';
        return MAD4B_SCP_Recovery_Alternatives::discover(
            (string) ( $input['domain'] ?? 'all' ) );
    }

    public static function prepare( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input ) ) return self::denial( 'permission_required' );
        foreach ( array_keys( $input ) as $key ) {
            if ( ! in_array( $key, array( 'stage_id', 'expected_plan_sha256',
                'source_id', 'candidate_sha', 'include_provider_scan',
                'include_host_challenge' ), true ) )
                return self::denial( 'unexpected_parameter' );
        }
        $stage_id = (string) ( $input['stage_id'] ?? '' );
        $sha = (string) ( $input['expected_plan_sha256'] ?? '' );
        if ( ! isset( self::stages()[ $stage_id ] ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) ) return self::denial( 'invalid_stage_or_plan' );
        $snapshot = self::status( array(
            'source_id' => (string) ( $input['source_id'] ?? '' ),
            'candidate_sha' => (string) ( $input['candidate_sha'] ?? '' ),
            'include_provider_scan' => ! empty( $input['include_provider_scan'] ),
            'include_host_challenge' => ! empty( $input['include_host_challenge'] ),
            // Repeat the independent reads; no caller-supplied PASS,
            // cached Host nonce or provider inventory is accepted.
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
                ? $stage['read_ability'] : $stage['preflight_ability'],
            'effectful_ability_requires_separate_governance' => $stage['apply_ability'],
            'ready_for_dispatch' => false, 'approval_issued' => false,
            'mutation_performed' => false, 'read_only' => true,
        );
    }
}
