<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * QA32C: explicit, read-only Staging provenance recovery preflight.
 *
 * An old but intact package may cite a Git commit that is no longer resolvable
 * in the authoritative repository. This classifier NEVER treats that orphaned
 * source as a verified ancestor and NEVER authorizes a plugin replacement.
 *
 * A future effectful executor needs a separately reviewed, registered owner
 * OAuth step-up + exact one-use authorization receipt and the existing
 * backup/maintenance/readback/rollback machinery. A PR approval is not a
 * site-specific update approval.
 */
final class MAD4B_SCP_Orphan_Source_Recovery {
    const CONTRACT = 'mad4b.control-plane-orphan-source-recovery-plan.v1';
    const ABILITY = 'mad4b/control-plane-orphan-source-recovery-plan';

    public static function boot() {
        if ( function_exists( 'add_action' ) ) {
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 35 );
        }
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Plan Staging Orphaned Control Plane Recovery',
            'description' => 'Read-only, exact-origin and immutable-source recovery feasibility without bypassing native lineage.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'plan' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
                ),
                'required' => array( 'reason' ),
                'additionalProperties' => false,
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false,
                'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    /** Pure admission policy, separately covered with no WordPress runtime. */
    public static function evaluate( array $signals ) {
        $blockers = array();
        if ( 'staging' !== ( $signals['environment'] ?? '' ) ) $blockers[] = 'exact_staging_required';
        if ( true !== ( $signals['wordpress_environment_explicit'] ?? false ) ) $blockers[] = 'explicit_wordpress_environment_required';
        if ( true !== ( $signals['profile_authority_ready'] ?? false )
            || 'exact' !== ( $signals['profile_binding_state'] ?? '' )
            || true !== ( $signals['origin_match'] ?? false )
            || true !== ( $signals['deployment_binding_match'] ?? false ) ) $blockers[] = 'exact_enrolled_site_identity_required';
        if ( ! is_string( $signals['site_uuid'] ?? null )
            || ! preg_match( '/^[a-f0-9-]{36}$/D', $signals['site_uuid'] ) ) $blockers[] = 'site_identity_missing';
        if ( ! is_string( $signals['site_origin'] ?? null )
            || ! preg_match( '#^https://[^/]+$#D', $signals['site_origin'] ) ) $blockers[] = 'canonical_https_origin_required';
        if ( true !== ( $signals['installed_runtime_manifest_match'] ?? false ) ) $blockers[] = 'installed_package_integrity_unverified';
        if ( 'installed_source_unresolvable' !== ( $signals['lineage'] ?? '' ) ) $blockers[] = 'orphan_lineage_not_independently_proven';
        foreach ( array( 'installed_sha', 'target_sha' ) as $field ) {
            if ( ! is_string( $signals[ $field ] ?? null )
                || ! preg_match( '/^[a-f0-9]{40}$/D', $signals[ $field ] ) ) $blockers[] = $field . '_invalid';
        }
        foreach ( array( 'installed_build_fingerprint', 'installed_manifest_digest',
            'target_archive_sha256', 'target_build_fingerprint', 'target_manifest_digest' ) as $field ) {
            if ( ! is_string( $signals[ $field ] ?? null )
                || ! preg_match( '/^[a-f0-9]{64}$/D', $signals[ $field ] ) ) $blockers[] = $field . '_invalid';
        }
        if ( ! empty( $signals['installed_sha'] ) && ! empty( $signals['target_sha'] )
            && hash_equals( (string) $signals['installed_sha'], (string) $signals['target_sha'] ) ) $blockers[] = 'already_on_target';
        if ( true !== ( $signals['release_verdict_success'] ?? false )
            || true !== ( $signals['release_root_trust_verified'] ?? false )
            || true !== ( $signals['published_from_master'] ?? false ) ) $blockers[] = 'immutable_release_authority_missing';
        if ( empty( $signals['installed_version'] ) || empty( $signals['target_version'] )
            || version_compare( (string) $signals['installed_version'], (string) $signals['target_version'], '>' ) ) $blockers[] = 'release_version_behind_or_unknown';
        $blockers = array_values( array_unique( $blockers ) );
        sort( $blockers, SORT_STRING );
        return array(
            'contract' => self::CONTRACT . '.admission.v1',
            'preflight_ready' => empty( $blockers ),
            'blockers' => $blockers,
            'recovery_kind' => 'intact_local_package_with_unresolvable_git_source',
            'normal_lineage_enforcement_preserved' => true,
            'auto_update_allowed' => false,
            'effectful_recovery_executor_registered' => false,
            'owner_oauth_step_up_required' => true,
            'same_site_one_use_approval_required' => true,
            'maintenance_lease_backup_readback_rollback_required' => true,
            'production_allowed' => false,
            'execution_allowed' => false,
            'mutation_performed' => false,
        );
    }

    /** Live, bounded read-only evidence projected from canonical site sources. */
    public static function plan( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $reason = isset( $input['reason'] ) && is_string( $input['reason'] )
            ? trim( $input['reason'] ) : '';
        if ( strlen( $reason ) < 3 || strlen( $reason ) > 500 ) {
            return new WP_Error( 'mad4b_orphan_plan_reason_required', 'A bounded recovery reason is required.' );
        }
        if ( ! class_exists( 'MAD4B_SCP_Self_Update' )
            || ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ) {
            return new WP_Error( 'mad4b_orphan_plan_source_missing', 'Canonical site evidence providers are unavailable.' );
        }
        $site = MAD4B_SCP_Site_Profile::status();
        $native = MAD4B_SCP_Self_Update::native_plan( array( 'reason' => $reason ) );
        if ( is_wp_error( $native ) ) return $native;
        $installed = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
        $current = is_array( $native['current'] ?? null ) ? $native['current'] : array();
        $target = is_array( $native['target'] ?? null ) ? $native['target'] : array();
        // A mismatch between current identity and independently hashed on-disk
        // provenance must never be interpreted as an intact orphan source.
        $installed_match = ! empty( $installed['runtime_manifest_match'] );
        foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $key ) {
            $installed_match = $installed_match && ! empty( $current[ $key ] )
                && ! empty( $installed[ $key ] )
                && hash_equals( (string) $current[ $key ], (string) $installed[ $key ] );
        }
        $signals = array(
            'environment' => (string) ( $site['environment'] ?? '' ),
            'wordpress_environment_explicit' => $site['wordpress_environment_explicit'] ?? false,
            'profile_authority_ready' => $site['authority_ready'] ?? false,
            'profile_binding_state' => (string) ( $site['binding_state'] ?? '' ),
            'origin_match' => $site['origin_match'] ?? false,
            'deployment_binding_match' => $site['deployment_binding_match'] ?? false,
            'site_uuid' => (string) ( $site['site_uuid'] ?? '' ),
            'site_origin' => (string) ( $site['canonical_origin'] ?? '' ),
            'installed_runtime_manifest_match' => $installed_match,
            'lineage' => (string) ( $native['release_source_relationship'] ?? '' ),
            'installed_sha' => (string) ( $current['source_commit_sha'] ?? '' ),
            'installed_version' => (string) ( $current['version'] ?? '' ),
            'installed_build_fingerprint' => (string) ( $current['build_fingerprint'] ?? '' ),
            'installed_manifest_digest' => (string) ( $current['package_manifest_digest'] ?? '' ),
            'target_sha' => (string) ( $target['source_commit_sha'] ?? '' ),
            'target_version' => (string) ( $target['version'] ?? '' ),
            'target_archive_sha256' => (string) ( $target['archive_sha256'] ?? '' ),
            'target_build_fingerprint' => (string) ( $target['build_fingerprint'] ?? '' ),
            'target_manifest_digest' => (string) ( $target['package_manifest_digest'] ?? '' ),
            'release_verdict_success' => $target['release_verdict_success'] ?? false,
            'release_root_trust_verified' => $target['release_root_trust_verified'] ?? false,
            'published_from_master' => $target['published_from_master'] ?? false,
        );
        $admission = self::evaluate( $signals );
        $basis = array(
            'contract' => self::CONTRACT,
            'site_uuid' => $signals['site_uuid'],
            'site_origin' => $signals['site_origin'],
            'installed_source_sha' => $signals['installed_sha'],
            'installed_build_fingerprint' => $signals['installed_build_fingerprint'],
            'installed_manifest_digest' => $signals['installed_manifest_digest'],
            'target_source_sha' => $signals['target_sha'],
            'target_archive_sha256' => $signals['target_archive_sha256'],
            'target_build_fingerprint' => $signals['target_build_fingerprint'],
            'target_manifest_digest' => $signals['target_manifest_digest'],
            'original_native_plan_sha256' => (string) ( $native['plan_sha256'] ?? '' ),
            'reason' => $reason,
            'admission' => $admission,
        );
        $basis['plan_sha256'] = hash( 'sha256', wp_json_encode( $basis ) );
        $basis['next_action'] = empty( $admission['blockers'] )
            ? 'certify_independent_owner_oauth_one_use_recovery_executor'
            : 'resolve_exact_preflight_blockers_without_mutating_site';
        $basis['read_only'] = true;
        $basis['authorizing'] = false;
        $basis['mutation_performed'] = false;
        return $basis;
    }
}
