<?php
/**
 * Unified, evidence-only operator workflow advisor for MAD4B.
 * Same classification on Staging/Production and on MCP/CLI.
 * This module never grants privileges, executes an operation, or approves a ticket.
 */
defined( 'ABSPATH' ) || exit;
final class MAD4B_Unified_Execution_Plan {
    const CONTRACT = 'mad4b.unified-execution-plan.v1';
    const ABILITY = 'mad4b/execution-unified-plan';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 60 );
        if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
            WP_CLI::add_command( 'mad4b execution plan', array( __CLASS__, 'cli_plan' ) );
        }
    }
    public static function register() {
        if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( self::ABILITY ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Unified Execution Plan',
            'description' => 'One read-only explanation of existing operation impact, approval, mount and governed write readiness, shared by MCP and WP-CLI. No grants, execution or policy override.',
            'category' => 'mad4b-read',
            'permission_callback' => array( __CLASS__, 'can_read' ),
            'execute_callback' => array( __CLASS__, 'plan' ),
            'input_schema' => array( 'type' => 'object', 'properties' => array(
                'ability_name' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 191 ),
                'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
            ), 'required' => array( 'ability_name' ), 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read', 'production_mutation_allowed' => false ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }
    public static function can_read() {
        return current_user_can( 'manage_options' );
    }
    public static function plan( $input = array() ) {
        if ( ! self::can_read() && ! ( defined( 'WP_CLI' ) && WP_CLI && PHP_SAPI === 'cli' && 0 === get_current_user_id() ) ) {
            return new WP_Error( 'mad4b_unified_plan_admin_required', 'Enrolled administrator or local read-only WP-CLI required.' );
        }
        $input = is_array( $input ) ? $input : array();
        $ability = isset( $input['ability_name'] ) ? trim( (string) $input['ability_name'] ) : '';
        if ( ! preg_match( '#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#', $ability ) ) {
            return new WP_Error( 'mad4b_unified_plan_ability_invalid', 'Registered exact Ability name required.' );
        }
        if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $ability )
            || ! class_exists( 'MAD4B_SCP_Impact_Policy' ) || ! class_exists( 'MAD4B_SCP_Servers' )
            || ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ) {
            return new WP_Error( 'mad4b_unified_plan_ability_or_runtime_missing', 'Registered Ability and governing runtime required.' );
        }
        $provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
        $mounted = is_string( $provider ) && '' !== $provider;
        $operation_input = array();
        if ( isset( $input['post_id'] ) && (int) $input['post_id'] > 0 ) {
            $operation_input['post_id'] = (int) $input['post_id'];
        }
        $classification = MAD4B_SCP_Impact_Policy::classify( $ability, $mounted ? $provider : 'core', $operation_input );
        $type = (string) ( $classification['operation_type'] ?? '' );
        $impact = (string) ( $classification['impact'] ?? 'high' );
        $sensitive = in_array( $type, array( 'certified_package', 'system_admin', 'exceptional', 'development_source', 'development_promotion', 'governance_decision' ), true )
            || in_array( $ability, array( 'mad4b/database-raw-query', 'mad4b/mutation-undo' ), true )
            || 'exceptional' === $impact || 0 === strpos( $ability, 'mad4b/developer-' );
        $tier = $sensitive ? 'sensitive' : ( ! empty( $classification['approval_required'] ) || 'high' === $impact ? 'impactful' : 'routine' );
        // These are workflow suggestions, not authorization decisions.
        $flow = 'routine' === $tier ? 'existing_grant_then_direct_governed_dispatch'
            : ( 'impactful' === $tier ? 'original_one_time_approval_then_governed_dispatch'
            : 'explicit_change_review_backup_and_original_ticket' );
        $status = MAD4B_SCP_Staging_Write_Authority::current_status();
        $effective = MAD4B_SCP_Staging_Write_Authority::effective();
        $reconciliation = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
        $candidate = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
        $build = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' )
            ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
        $build_ready = is_array( $build ) && ! empty( $build['manifest_present'] )
            && ! empty( $build['manifest_valid'] ) && ! empty( $build['runtime_manifest_match'] )
            && empty( $build['stale'] ) && empty( $build['provenance_mismatch'] );
        // Selective Build Verification replaces only the global package-file
        // check for supported draft writes; it never replaces actual grants.
        $build_scope = 'full'; $build_dependency_blockers = array();
        if ( class_exists( 'MAD4B_SCP_Scoped_Build_Verification' )
            && MAD4B_SCP_Scoped_Build_Verification::governs_content_write( $ability ) ) {
            $proof = MAD4B_SCP_Scoped_Build_Verification::verify( $ability, $operation_input );
            $build_scope = is_array( $proof ) ? (string) ( $proof['scope'] ?? 'full' ) : 'full';
            $build_ready = is_array( $proof ) && ! empty( $proof['verification_ready'] );
            $build_dependency_blockers = is_array( $proof ) && isset( $proof['blockers'] )
                ? (array) $proof['blockers'] : array( 'scoped_build_verification_unavailable' );
        }
        $missing_grants = is_array( $reconciliation )
            ? (int) ( $reconciliation['exact_grants_missing_count'] ?? 0 ) : -1;
        $existing_grants = is_array( $reconciliation )
            ? (int) ( $reconciliation['exact_grants_existing'] ?? 0 ) : -1;
        $candidate_match = is_array( $candidate ) && ! empty( $candidate['match'] );
        $profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
        $environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_environment() : 'unknown';
        $gate = 'production' !== $environment || ( class_exists( 'MAD4B_SCP_Governed_Runtime_Gates' )
            && MAD4B_SCP_Governed_Runtime_Gates::production_mutation_enabled() );
        $blockers = array();
        if ( ! $mounted ) $blockers[] = 'ability_not_mounted_on_governed_write';
        if ( empty( $profile['authority_ready'] ) || empty( $profile['write_enabled'] ) ) $blockers[] = 'exact_profile_or_write_setting_not_ready';
        if ( ! $build_ready ) $blockers[] = 'scoped' === $build_scope
            ? 'scoped_material_provenance_not_current' : 'build_provenance_not_current';
        if ( ! $candidate_match ) $blockers[] = 'candidate_binding_not_reconciled';
        if ( $missing_grants !== 0 ) $blockers[] = 'exact_grants_not_current';
        if ( ! $gate ) $blockers[] = 'production_mutation_gate_disabled';
        if ( ! $effective ) $blockers[] = 'effective_write_authority_not_ready';
        $next = array();
        if ( ! $build_ready ) $next[] = 'scoped' === $build_scope
            ? 'reconcile_exact_material_dependencies' : 'reconcile_source_and_certify_current_build';
        if ( ! $candidate_match ) $next[] = 'bind_exact_current_package_candidate';
        if ( $missing_grants !== 0 ) $next[] = 'reconcile_source_reviewed_exact_grants';
        if ( ! $gate ) $next[] = 'enable_approved_production_mutation_gate';
        return array(
            'contract' => self::CONTRACT,
            'ability' => $ability, 'environment' => $environment,
            'provider' => $mounted ? $provider : '', 'tier' => $tier, 'workflow' => $flow,
            'original_impact' => $impact, 'operation_type' => $type,
            'original_approval_required' => ! empty( $classification['approval_required'] ),
            'original_classification_sha256' => (string) ( $classification['classification_sha256'] ?? '' ),
            'readiness' => array( 'ability_registered' => true, 'write_mounted' => $mounted,
                'governed_authority_effective' => $effective, 'production_gate_ready' => $gate,
                'build_provenance_current' => $build_ready, 'build_verification_scope' => $build_scope,
                'material_dependency_blockers' => $build_dependency_blockers,
                'candidate_binding_match' => $candidate_match,
                'exact_grants_existing' => $existing_grants, 'exact_grants_missing' => $missing_grants ),
            'blockers' => array_values( array_unique( $blockers ) ), 'next_steps' => $next,
            'execution_ready_to_prepare' => $mounted && $gate && $effective && empty( $blockers ),
            'execution_authorized' => false, 'approval_granted' => false,
            'grant_mutation_performed' => false, 'mutation_performed' => false,
            'note' => 'Planning never creates permission. Real execution still checks enrolled actor, exact grant, target, approval, provenance and audit.',
        );
    }
    public static function cli_plan( $args, $assoc ) {
        if ( $args || ! is_array( $assoc ) || array_diff( array_keys( $assoc ), array( 'ability', 'post-id' ) )
            || empty( $assoc['ability'] ) ) {
            WP_CLI::error( 'Usage: wp mad4b execution plan --ability=namespace/name [--post-id=123].' );
        }
        $input = array( 'ability_name' => $assoc['ability'] );
        if ( isset( $assoc['post-id'] ) ) $input['post_id'] = absint( $assoc['post-id'] );
        $result = self::plan( $input );
        if ( is_wp_error( $result ) ) WP_CLI::error( $result->get_error_code() );
        WP_CLI::line( wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) );
    }
}
MAD4B_Unified_Execution_Plan::boot();
