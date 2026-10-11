<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * L0-L5 operator action projection over the existing Control Center.
 * Presents bounded next steps only; never dispatches repairs or approvals.
 */
final class MAD4B_SCP_G7_Action_Center {
    const CONTRACT = 'mad4b.feature007-g7-action-center.v1';

    public static function from_operator_snapshot( array $operator ) {
        $state = isset( $operator['state'] ) && is_string( $operator['state'] ) ? $operator['state'] : '';
        $trust = ( $operator['contract'] ?? '' ) === 'mad4b.operator-control-center.v1' &&
            in_array( $state, array( 'HEALTHY', 'DEGRADED', 'BLOCKED', 'RECOVERY_REQUIRED' ), true ) &&
            false === ( $operator['authorizing'] ?? null ) &&
            false === ( $operator['mutation_performed'] ?? null ) &&
            false === ( $operator['production_authorized'] ?? null );
        $reasons = isset( $operator['reasons'] ) && is_array( $operator['reasons'] ) ? $operator['reasons'] : array();
        $actions = isset( $operator['next_actions'] ) && is_array( $operator['next_actions'] ) ? $operator['next_actions'] : array();
        $records = array(); $seen = array();
        foreach ( array_slice( $reasons, 0, 16 ) as $reason ) {
            if ( ! is_string( $reason ) || 1 !== preg_match( '/^[a-z0-9_]{1,80}$/D', $reason ) ) { $trust = false; continue; }
            if ( isset( $seen[ $reason ] ) ) continue;
            $seen[ $reason ] = true;
            $external = in_array( $reason, array(
                'developer_lane_not_ready', 'developer_breakglass_lane_not_ready',
                'external_machine_ingress_not_reachable', 'external_machine_ingress_unbound'
            ), true );
            $priority = in_array( $reason, array( 'mutation_state_uncertain', 'recovery_required' ), true )
                ? 'RECONCILIATION_REQUIRED' : ( $external ? 'EXTERNAL_ACTION_REQUIRED' : 'APPROVAL_REQUIRED' );
            $records[] = array( 'reason' => $reason, 'state' => $priority,
                'blast_radius' => $external ? 'developer_host_only' : 'capability_local_unknown',
                'verified_repair' => false, 'requires_existing_governance' => true );
        }
        if ( count( $reasons ) > 16 || count( $actions ) > 16 ) $trust = false;
        foreach ( $actions as $action ) {
            if ( ! is_string( $action ) || 1 !== preg_match( '/^[a-z0-9_]{1,100}$/D', $action ) ) {
                $trust = false;
                break;
            }
        }
        if ( in_array( $state, array( 'BLOCKED', 'DEGRADED', 'RECOVERY_REQUIRED' ), true ) && empty( $records ) ) $trust = false;
        if ( ! $trust ) {
            $records = array( array( 'reason' => 'untrusted_or_unbounded_operator_snapshot',
                'state' => 'RECONCILIATION_REQUIRED', 'blast_radius' => 'unknown',
                'verified_repair' => false, 'requires_existing_governance' => true ) );
        } elseif ( 'RECOVERY_REQUIRED' === $state && empty( $records ) ) {
            $records[] = array( 'reason' => 'recovery_without_evidence',
                'state' => 'RECONCILIATION_REQUIRED', 'blast_radius' => 'unknown',
                'verified_repair' => false, 'requires_existing_governance' => true );
        }
        $next = array();
        foreach ( array_slice( $actions, 0, 16 ) as $act ) {
            if ( is_string( $act ) && preg_match( '/^[a-z0-9_]{1,100}$/D', $act ) ) $next[] = $act;
        }
        if ( ! $trust ) $next = array( 'recheck_exact_operator_evidence' );
        $has_reconcile = false; $has_external = false; $has_review = false;
        foreach ( $records as $record ) {
            if ( 'RECONCILIATION_REQUIRED' === $record['state'] ) $has_reconcile = true;
            if ( 'EXTERNAL_ACTION_REQUIRED' === $record['state'] ) $has_external = true;
            if ( 'APPROVAL_REQUIRED' === $record['state'] ) $has_review = true;
        }
        $summary = $has_reconcile ? 'RECONCILIATION_REQUIRED'
            : ( $has_review ? 'APPROVAL_REQUIRED' :
                ( $has_external ? 'EXTERNAL_ACTION_REQUIRED' : 'NO_ACTION_OBSERVED' ) );
        return array(
            'contract' => self::CONTRACT,
            'state' => $summary, 'trustworthy_operator_projection' => $trust,
            'autonomy_level_current' => 'L0_OBSERVE',
            'autonomy_levels' => self::levels(),
            'action_items' => $records, 'next_actions' => array_values( array_unique( $next ) ),
            'attempt_history_verified' => false, 'readback_verified' => false,
            'automatic_repaired_count' => 0, 'verified_automated_repair_count' => 0,
            'eligible_workload_denominator' => 0, 'automation_rate_percent' => null,
            'intervention_rate_percent' => null, 'mttr_seconds' => null,
            'cost_per_verified_repair' => null,
            'manual_governance_required' => ! empty( $records ),
            'dispatch_performed' => false, 'authorizing' => false,
            'production_authorized' => false, 'mutation_performed' => false
        );
    }

    public static function levels() {
        return array(
            array( 'level' => 'L0', 'role' => 'observe', 'automatic_execution' => false ),
            array( 'level' => 'L1', 'role' => 'refresh_status_projection', 'requires' => 'existing_read_permissions', 'automatic_execution' => false ),
            array( 'level' => 'L2', 'role' => 'repair_proven_owned_configuration', 'requires' => 'existing_typed_executor_exact_cas_and_human_edit_preservation', 'automatic_execution' => false ),
            array( 'level' => 'L3', 'role' => 'reversible_staging_trial', 'requires' => 'existing_authorization_and_verified_restoration', 'automatic_execution' => false ),
            array( 'level' => 'L4', 'role' => 'refresh_existing_eligible_staging_access', 'requires' => 'exact_existing_grant_inventory_no_new_authority', 'automatic_execution' => false ),
            array( 'level' => 'L5', 'role' => 'high_risk_or_new_authority', 'requires' => 'explicit_owner_review_no_automatic_promotion', 'automatic_execution' => false )
        );
    }
}
