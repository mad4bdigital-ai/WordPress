<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Narrow operator-grant enrollment. Delegates to the canonical Developer
 * Authority plan/apply engine; it never writes grants directly.
 *
 * This operation remains unavailable until an exact certified build passes
 * the existing provenance gate. The stored rollout flag is written only after
 * the canonical OAuth-bound plan and apply both succeed.
 */
final class MAD4B_SCP_Operator_Grant_Convergence {
    const CONTRACT = 'mad4b.operator-grant-convergence.v1';
    const ABILITY = 'mad4b/operator-grants-converge';
    const CONFIRM = 'APPROVE FIVE STAGING OPERATOR GRANTS';
    const CANONICAL_CONFIRM = 'PROVISION MAD4B DEVELOPER AGENT';

    private static $scoped = false;
    private static $busy = false;

    private static $abilities = array(
        'mad4b/operator-session-open',
        'mad4b/operator-session-status',
        'mad4b/operator-session-close',
        'mad4b/operator-operation-plan',
        'mad4b/operator-operation-submit',
    );

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 20 );
    }

    public static function scoped_rollout_active() { return self::$scoped; }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ||
             ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        $properties = array(
            'action' => array( 'type' => 'string', 'enum' => array( 'plan', 'apply' ) ),
            'expected_plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
            'expected_source_commit_sha' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{40}$' ),
            'expected_site_uuid' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
            'expected_environment' => array( 'type' => 'string', 'enum' => array( 'staging' ) ),
            'expected_profile_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
            'expected_profile_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
            'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRM ) ),
        );
        wp_register_ability( self::ABILITY, array(
            'label' => 'Converge Five Operator Developer Grants',
            'description' => 'Plan or explicitly authorize only the five Staging Operator grants with canonical Developer Authority.',
            'category' => 'mad4b-developer-governance',
            'execute_callback' => array( __CLASS__, 'execute' ),
            'permission_callback' => array( __CLASS__, 'can_access' ),
            'input_schema' => array( 'type' => 'object', 'properties' => $properties,
                'required' => array( 'action' ), 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array(
                    'public' => false, 'type' => 'tool', 'surface' => 'enrollment',
                    'chatgpt_direct_step_up' => true,
                    'exact_chatgpt_client_required' => true,
                    'generic_remote_admin' => false,
                    'governed_enrollment_broker' => true,
                    'broker_dispatch_policy' => 'operator_exact_grants_v1',
                    'owner_intent_only' => true,
                    'production_mutation_allowed' => false,
                    'production_allowed' => false,
                    'operator_grants_only' => true,
                ),
                'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
            ),
        ) );
    }

    public static function can_access( $input = null ) {
        if ( ! class_exists( 'MAD4B_SCP_Developer_Authority' ) ) {
            return new WP_Error( 'mad4b_operator_grants_authority_missing', 'Canonical Developer Authority unavailable.' );
        }
        $access = MAD4B_SCP_Developer_Authority::can_access( $input );
        if ( is_wp_error( $access ) || true !== $access ) return $access;
        if ( 'staging' !== wp_get_environment_type() ||
             'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) {
            return new WP_Error( 'mad4b_operator_grants_staging_only', 'Exact Staging environment required.' );
        }
        $identity = MAD4B_SCP_Identity_Context::current();
        if ( is_wp_error( $identity ) || ! is_array( $identity ) ||
             ! in_array( 'mad4b:authority:step-up', $identity['token_scopes'] ?? array(), true ) ) {
            return new WP_Error( 'mad4b_operator_grants_step_up_required', 'Enrolled OAuth step-up scope required.' );
        }
        return true;
    }

    private static function original_authority_ready() {
        $previous = MAD4B_SCP_Developer_Authority::status();
        $normal = is_array( $previous ) ? ( $previous['normal_authority'] ?? array() ) : array();
        if ( ! is_array( $normal ) || empty( $normal['ready'] ) ||
             9 !== (int) ( $normal['tool_count'] ?? 0 ) ||
             9 !== (int) ( $normal['exact_grant_count'] ?? 0 ) ||
             ! empty( $normal['blockers'] ) ) {
            return new WP_Error( 'mad4b_operator_grants_baseline_not_ready',
                'The nine legacy Developer grants must be exactly healthy before adding Operator grants.' );
        }
        return $previous;
    }

    public static function execute( $input ) {
        $access = self::can_access( $input );
        if ( is_wp_error( $access ) ) return $access;
        if ( self::$busy || ! is_array( $input ) ) {
            return new WP_Error( 'mad4b_operator_grants_request_invalid', 'Nested or invalid grant request.' );
        }
        $action = (string) ( $input['action'] ?? '' );
        if ( ! in_array( $action, array( 'plan', 'apply' ), true ) ) {
            return new WP_Error( 'mad4b_operator_grants_action_invalid', 'Only plan/apply are admitted.' );
        }
        if ( '1' === (string) get_option( 'mad4b_scp_operator_grant_rollout_enabled', '0' ) ) {
            $status = MAD4B_SCP_Developer_Authority::status();
            $normal = $status['normal_authority'] ?? array();
            return array(
                'contract' => self::CONTRACT,
                'state' => ( ! empty( $normal['ready'] ) && 14 === (int) ( $normal['exact_grant_count'] ?? 0 ) )
                    ? 'already_current' : 'rollout_requires_reconciliation',
                'authority' => array( 'ready' => ! empty( $normal['ready'] ),
                    'tool_count' => $normal['tool_count'] ?? 0,
                    'exact_grant_count' => $normal['exact_grant_count'] ?? 0 ),
                'mutation_performed' => false, 'production_mutation' => false,
            );
        }
        $baseline = self::original_authority_ready();
        if ( is_wp_error( $baseline ) ) return $baseline;

        self::$busy = true;
        self::$scoped = true;
        try {
            $plan = MAD4B_SCP_Developer_Authority::plan();
            if ( is_wp_error( $plan ) ) return $plan;
            if ( 14 !== (int) ( $plan['tool_count'] ?? 0 ) ||
                 empty( $plan['ready_to_apply'] ) ||
                 ! empty( $plan['blockers'] ) ) {
                return new WP_Error( 'mad4b_operator_grants_canonical_plan_blocked',
                    'Current certified build or exact Developer plan is not ready.' );
            }
            $missing = array();
            foreach ( $plan['rows'] ?? array() as $row ) {
                if ( empty( $row['exact_grant_present'] ) ) $missing[] = (string) $row['ability'];
            }
            sort( $missing, SORT_STRING );
            $allowed = self::$abilities;
            sort( $allowed, SORT_STRING );
            if ( $missing !== $allowed ) {
                return new WP_Error( 'mad4b_operator_grants_scope_drift',
                    'Canonical plan must add exactly the five new Operator abilities.' );
            }
            $response = array(
                'contract' => self::CONTRACT,
                'state' => 'review_exact_five_grants',
                'target_environment' => 'staging',
                'server_id' => 'mad4b-developer',
                'new_grants' => $missing,
                'ready_to_apply' => true,
                'canonical_plan_sha256' => (string) $plan['plan_sha256'],
                'source_commit_sha' => (string) $plan['source_commit_sha'],
                'site_uuid' => (string) $plan['site_uuid'],
                'profile_revision' => (int) $plan['site_profile_revision'],
                'profile_digest' => (string) $plan['site_profile_digest'],
                'required_confirmation' => self::CONFIRM,
                'mutation_performed' => false, 'production_mutation' => false,
            );
            if ( 'plan' === $action ) return $response;
            if ( self::CONFIRM !== (string) ( $input['confirmation'] ?? '' ) ||
                 ! hash_equals( $response['canonical_plan_sha256'],
                    (string) ( $input['expected_plan_sha256'] ?? '' ) ) ) {
                return new WP_Error( 'mad4b_operator_grants_confirmation_or_plan_missing',
                    'Exact current plan SHA and operator grant confirmation required.' );
            }
            $canonical = array(
                'expected_plan_sha256' => $input['expected_plan_sha256'],
                'expected_source_commit_sha' => $input['expected_source_commit_sha'] ?? '',
                'expected_site_uuid' => $input['expected_site_uuid'] ?? '',
                'expected_environment' => $input['expected_environment'] ?? '',
                'expected_profile_revision' => $input['expected_profile_revision'] ?? 0,
                'expected_profile_digest' => $input['expected_profile_digest'] ?? '',
                'confirmation' => self::CANONICAL_CONFIRM,
            );
            $result = MAD4B_SCP_Developer_Authority::apply( $canonical );
            if ( is_wp_error( $result ) ) return $result;
            if ( 5 !== (int) ( $result['created_grant_count'] ?? -1 ) ||
                 empty( $result['completion_audit_recorded'] ) ||
                 empty( $result['authority']['ready'] ) ) {
                return new WP_Error( 'mad4b_operator_grants_postcondition_unknown',
                    'Canonical Developer Authority returned an unverifiable result; reconcile before proceeding.' );
            }
            $saved = update_option( 'mad4b_scp_operator_grant_rollout_enabled', '1', false );
            if ( ! $saved && '1' !== (string) get_option( 'mad4b_scp_operator_grant_rollout_enabled', '0' ) ) {
                return new WP_Error( 'mad4b_operator_grants_rollout_option_failed',
                    'Grants were created but rollout flag could not be persisted; reconcile exact site before retry.' );
            }
            $current = MAD4B_SCP_Developer_Authority::status();
            $normal = $current['normal_authority'] ?? array();
            if ( empty( $normal['ready'] ) || (int) ( $normal['exact_grant_count'] ?? 0 ) !== 14 ) {
                return new WP_Error( 'mad4b_operator_grants_readback_failed',
                    'Exact 14/14 Developer authority readback failed; do not execute Operator jobs.' );
            }
            return array(
                'contract' => self::CONTRACT,
                'state' => 'operator_exact_grants_ready',
                'created_grant_count' => 5,
                'normal_developer_exact_grants' => 14,
                'completion_audit_recorded' => true,
                'operator_execution_enabled' => defined( 'MAD4B_MCP_OPERATOR_EXECUTION_ENABLED' )
                    && true === constant( 'MAD4B_MCP_OPERATOR_EXECUTION_ENABLED' ),
                'mutation_performed' => true, 'production_mutation' => false,
            );
        } finally {
            self::$scoped = false;
            self::$busy = false;
        }
    }
}
MAD4B_SCP_Operator_Grant_Convergence::boot();
