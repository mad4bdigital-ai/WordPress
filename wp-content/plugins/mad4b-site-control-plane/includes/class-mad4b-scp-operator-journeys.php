<?php
/**
 * Read-only task-first experience map for the entire MAD4B control plane.
 * Tool existence is never mistaken for grant, operational readiness or
 * completion; no arbitrary user-selected ability is invoked.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Operator_Journeys {
    const ABILITY = 'mad4b/operator-journeys';
    const CONTRACT = 'mad4b.operator-journeys.v1';
    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
    }
    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' )
            || ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Operator Journeys: From Task To Verified Result',
            'description' => 'Find WordPress and MCP tasks by goal; show prerequisites, read-plan-approve-run-readback stages and missing UI affordances. No execution.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'discover' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array( 'type' => 'object', 'additionalProperties' => false,
                'properties' => array(
                    'journey' => array( 'type' => 'string', 'maxLength' => 64 ),
                    'only_incomplete' => array( 'type' => 'boolean' ),
                ) ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }
    /** Stable, deliberately modest journey recipes; future plugins discover
     * additional operations via the canonical operation-discover API. */
    public static function recipes() {
        return array(
            'setup_and_permissions' => array(
                'label' => 'Connect site and confirm permissions', 'risk' => 'high',
                'steps' => array(
                    array( 'site', 'mad4b/site-profile-status', 'read' ),
                    array( 'binding_status', 'mad4b/deployment-binding-setup-status', 'read' ),
                    array( 'native_binding_status', 'mad4b/deployment-binding-native-status', 'read' ),
                    array( 'native_binding_plan', 'mad4b/deployment-binding-native-plan', 'read' ),
                    array( 'native_binding_apply', 'mad4b/deployment-binding-native-apply', 'write' ),
                    array( 'binding_plan', 'mad4b/deployment-binding-setup-plan', 'read' ),
                    array( 'binding_apply', 'mad4b/deployment-binding-setup-apply', 'write' ),
                    array( 'binding_verify', 'mad4b/deployment-mode-status', 'read' ),
                    array( 'readiness', 'mad4b/write-authority-status', 'read' ),
                    array( 'plan', 'mad4b/staging-write-grant-reconciliation-plan', 'read' ),
                    array( 'execute', 'mad4b/staging-write-grant-reconcile', 'write' ),
                    array( 'verify', 'mad4b/write-authority-status', 'read' ),
                ),
                'caution' => 'Deployment binding is a one-time setup if missing; the Host secret stays outside the WordPress database. No step creates or reveals it. The Site Profile bind requires exact owner approval, and available permissions never imply write authority.',
            ),
            'discover_and_automate' => array(
                'label' => 'Find a remote operation and its executor', 'risk' => 'varies',
                'steps' => array(
                    array( 'dynamic_discover', 'mad4b/operation-route-discover', 'read' ),
                    array( 'dynamic_route_plan', 'mad4b/operation-route-plan', 'read' ),
                    array( 'discover', 'mad4b/operation-discover', 'read' ),
                    array( 'requirements', 'mad4b/progressive-requirements-plan', 'read' ),
                    array( 'prepare', 'mad4b/operational-remediation-prepare', 'read' ),
                    array( 'verify', 'mad4b/remote-operation-parity-status', 'read' ),
                ),
                'caution' => 'Automatic discovery and route selection are read-only. A client must use the fresh schema-pinned governed dispatcher and exact independent owner approval for every mutation; failed writes require readback, never blind retry.',
            ),
            'forms_and_integration' => array(
                'label' => 'Find a form or inspect a plugin integration', 'risk' => 'read_only',
                'steps' => array(
                    array( 'discover', 'cso/form-catalog', 'read' ),
                    array( 'explain', 'cso/form-schema', 'read' ),
                    array( 'validate', 'cso/form-validate', 'read' ),
                    array( 'search', 'cso/integration-search', 'read' ),
                ),
                'caution' => 'CSO01 forms validate read inputs only. They do not save arbitrary plugin settings, passwords or content.',
            ),
            'brand_core' => array(
                'label' => 'Recover and approve Brand Core with WordPress-only tooling', 'risk' => 'high',
                'steps' => array(
                    array( 'native_inventory', 'mad4b/brand-core-wordpress-mode', 'read' ),
                    array( 'folder_proof', 'mad4b/selected-folder-scope-proof', 'read' ),
                    array( 'scratch_plan', 'mad4b/native-recovery-plan', 'read' ),
                    array( 'scratch_start', 'mad4b/native-recovery-start', 'write' ),
                    array( 'scratch_readback', 'mad4b/native-recovery-readback', 'read' ),
                    array( 'ownership_plan', 'context/legacy-owner-transfer-plan', 'read' ),
                    array( 'functional_owner_plan', 'context/native-owner-transfer-plan', 'read' ),
                    array( 'functional_owner_apply', 'context/native-owner-transfer-apply', 'write' ),
                    array( 'ownership_apply', 'context/legacy-owner-transfer-apply', 'write' ),
                    array( 'ownership_readback', 'context/legacy-owner-transfer-readback', 'read' ),
                    array( 'scan_plan', 'context/source-scan-plan', 'read' ),
                    array( 'scan_apply', 'context/source-scan-apply', 'write' ),
                    array( 'file_queue', 'context/review-queue', 'read' ),
                    array( 'file_review_plan', 'context/remote-owner-review-plan', 'read' ),
                    array( 'file_review_apply', 'context/remote-owner-review-apply', 'write' ),
                    array( 'review_readback', 'context/review-queue', 'read' ),
                    array( 'coverage', 'context/brand-core-coverage', 'read' ),
                ),
                'caution' => 'Two distinct ownership policies: native functional Staging transfer requires exact original Drive provider proof, independently observed WordPress shadow and its own human-approved ticket; it does not certify Host, crash recovery or backup restore. Host-certified transfer retains original disaster gate. File approval needs another ticket per asset. Discovery never constitutes consent.',
            ),
            'content_and_drafts' => array(
                'label' => 'Create and verify a governed content draft', 'risk' => 'high',
                'steps' => array(
                    array( 'discover', 'mad4b/content-job-list', 'read' ),
                    array( 'plan', 'mad4b/draft-plan', 'read' ),
                    array( 'execute', 'mad4b/content-apply-bundle', 'write' ),
                    array( 'verify', 'mad4b/draft-verify', 'read' ),
                ),
                'caution' => 'Saving a draft is not approval to publish or re-use unlicensed supplier content.',
            ),
            'workflows' => array(
                'label' => 'Inspect and plan automation workflows', 'risk' => 'varies',
                'steps' => array(
                    array( 'discover', 'mad4b/workflow-provider-status', 'read' ),
                    array( 'plan', 'mad4b/workflow-plan', 'read' ),
                    array( 'compile', 'mad4b/workflow-compile', 'read' ),
                ),
                'caution' => 'Compiled workflow plans are not executed work. Use each certified provider executor and a receipt.',
            ),
            'plugin_upgrades' => array(
                'label' => 'Safely update a plugin or selected Git HEAD', 'risk' => 'high',
                'steps' => array(
                    array( 'status', 'mad4b/control-plane-update-status', 'read' ),
                    array( 'plan', 'mad4b/control-plane-selected-head-plan', 'read' ),
                    array( 'execute', 'mad4b/control-plane-selected-head-apply', 'write' ),
                    array( 'verify', 'mad4b/control-plane-update-status', 'read' ),
                ),
                'caution' => 'A plan is not a certified ZIP. Preserve immutable SHA, signer, backup, rollback and installed version readback.',
            ),
            'wordpress_native_recovery' => array(
                'label' => 'Resume Host-free WordPress recovery rehearsal', 'risk' => 'bounded_staging_write',
                'steps' => array(
                    array( 'resume', 'mad4b/native-recovery-status', 'read' ),
                    array( 'plan', 'mad4b/native-recovery-plan', 'read' ),
                    array( 'start', 'mad4b/native-recovery-start', 'write' ),
                    array( 'readback', 'mad4b/native-recovery-readback', 'read' ),
                    array( 'cleanup', 'mad4b/native-recovery-cleanup', 'write' ),
                ),
                'caution' => 'Tests a private scratch option. This does not transfer ownership or prove MariaDB crash/Host recovery.',
            ),
            'diagnose_and_recover' => array(
                'label' => 'Diagnose an operation and find safe alternatives', 'risk' => 'read_only',
                'steps' => array(
                    array( 'diagnose', 'mad4b/session-safe-diagnostics', 'read' ),
                    array( 'work_items', 'mad4b/operational-remediation-status', 'read' ),
                    array( 'collect_evidence', 'mad4b/operational-acceptance-evidence', 'read' ),
                    array( 'alternatives', 'mad4b/brand-core-recovery-alternatives', 'read' ),
                    array( 'verify', 'mad4b/acceptance-result', 'read' ),
                ),
                'caution' => 'Healthy runtime is not release acceptance. Do not turn diagnostic signals into operation approval.',
            ),
        );
    }
    public static function reduce( array $mounts, $journey = '', $only_incomplete = false ) {
        $recipes = self::recipes();
        if ( '' !== $journey && ! isset( $recipes[ $journey ] ) )
            return new WP_Error( 'mad4b_operator_journey_unknown', 'Select an exact listed operator journey.' );
        $items = array();
        foreach ( $recipes as $id => $recipe ) {
            if ( '' !== $journey && $journey !== $id ) continue;
            $steps = array(); $missing = array();
            foreach ( $recipe['steps'] as $i => $step ) {
                $mounted = true === ( $mounts[ $step[1] ] ?? false );
                $steps[] = array(
                    'index' => $i + 1, 'label' => $step[0], 'ability_name' => $step[1],
                    'type' => $step[2], 'mounted' => $mounted,
                    'approved' => false, 'executed' => false, 'verified' => false,
                    'next_action' => $mounted ? 'inspect_exact_ability_schema' : 'integrate_or_enroll_ability',
                );
                if ( ! $mounted ) $missing[] = $step[1];
            }
            if ( $only_incomplete && ! $missing ) continue;
            $items[ $id ] = array( 'id' => $id, 'label' => $recipe['label'],
                'risk' => $recipe['risk'], 'steps' => $steps,
                'all_steps_mounted' => ! $missing,
                'missing_abilities' => array_values( array_unique( $missing ) ),
                'readiness_state' => $missing ? 'INTEGRATION_MISSING'
                    : 'REGISTERED_NOT_OPERATIONALLY_VERIFIED',
                'user_guidance' => $recipe['caution'],
                'one_click_execution_supported' => false,
                'explicit_owner_approval_required_for_writes' => true,
                'live_acceptance_verified' => false );
        }
        return array( 'contract' => self::CONTRACT, 'journeys' => $items,
            'total' => count( $items ), 'source' => 'wordpress_registered_abilities',
            'execution_authorized' => false, 'workflow_completed' => false,
            'read_only' => true, 'production_mutation_authorized' => false );
    }
    public static function discover( $input = array() ) {
        if ( ! is_array( $input ) ||
            array_diff( array_keys( $input ), array( 'journey', 'only_incomplete' ) ) )
            return new WP_Error( 'mad4b_operator_journey_input_invalid', 'Only exact journey filters are accepted.' );
        if ( isset( $input['journey'] ) && ! is_string( $input['journey'] ) )
            return new WP_Error( 'mad4b_operator_journey_input_invalid', 'Journey must be one known text identifier.' );
        if ( isset( $input['only_incomplete'] ) && ! is_bool( $input['only_incomplete'] ) )
            return new WP_Error( 'mad4b_operator_journey_input_invalid', 'Incomplete filter must be boolean.' );
        $id = (string) ( $input['journey'] ?? '' );
        if ( ! preg_match( '/^[a-z_]{0,64}$/D', $id ) )
            return new WP_Error( 'mad4b_operator_journey_input_invalid', 'Journey selection is not valid.' );
        $mounts = array();
        foreach ( self::recipes() as $recipe )
            foreach ( $recipe['steps'] as $step )
                if ( ! array_key_exists( $step[1], $mounts ) )
                    $mounts[ $step[1] ] = function_exists( 'wp_has_ability' )
                        && wp_has_ability( $step[1] );
        return self::reduce( $mounts, $id, ! empty( $input['only_incomplete'] ) );
    }
}
