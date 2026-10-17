<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Non-authorizing view of the already governed Operation Registry pipeline.
 * It never invents a dependency, evaluates an untrusted condition, executes a
 * planner, creates a grant, or interprets a missing approval as success.
 */
final class MAD4B_SCP_Capability_Dependency_Planner {
    const CONTRACT = 'mad4b.capability-dependency-plan.v1';
    const MAX_STAGES = 64;

    public static function inspect( array $input ) {
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_read() )
            return new WP_Error( 'mad4b_dependency_read_denied', 'Governed read authority is required.' );
        if ( ! class_exists( 'MAD4B_SCP_Operation_Registry' ) )
            return new WP_Error( 'mad4b_dependency_registry_unavailable', 'Canonical operation registry is unavailable.' );
        $operation = isset( $input['operation'] ) && is_string( $input['operation'] )
            ? trim( $input['operation'] ) : '';
        $target_kind = isset( $input['target_kind'] ) && is_string( $input['target_kind'] )
            ? trim( $input['target_kind'] ) : '';
        if ( '' === $operation || strlen( $operation ) > 120 ||
            ! preg_match( '/^[a-z][a-z0-9._-]{1,119}$/D', $operation ) ||
            ( '' !== $target_kind && ! preg_match( '/^[a-z0-9._-]{1,64}$/D', $target_kind ) ) )
            return new WP_Error( 'mad4b_dependency_operation_invalid', 'An exact registered operation identity is required.' );
        $found = MAD4B_SCP_Operation_Registry::discover( array(
            'operation' => $operation, 'target_kind' => $target_kind ) );
        if ( is_wp_error( $found ) ) return $found;
        $row = isset( $found['operation'] ) && is_array( $found['operation'] )
            ? $found['operation'] : array();
        $profile_id = isset( $row['pipeline_profile'] ) ? (string) $row['pipeline_profile'] : '';
        $stages = MAD4B_SCP_Operation_Registry::pipeline_profile( $profile_id );
        if ( is_wp_error( $stages ) ) return $stages;
        if ( ! is_array( $stages ) || ! $stages || count( $stages ) > self::MAX_STAGES )
            return new WP_Error( 'mad4b_dependency_pipeline_size_invalid', 'Canonical pipeline exceeds the safe planning boundary.' );
        $nodes = array(); $edges = array(); $missing = array(); $unresolved = array();
        $last_id = null; $seen = array();
        foreach ( $stages as $position => $stage ) {
            if ( ! is_array( $stage ) ) return new WP_Error( 'mad4b_dependency_stage_invalid', 'Canonical pipeline contains an invalid stage.' );
            $id = isset( $stage['id'] ) ? (string) $stage['id'] : '';
            $type = isset( $stage['type'] ) ? (string) $stage['type'] : '';
            if ( '' === $id || isset( $seen[$id] ) ) return new WP_Error( 'mad4b_dependency_stage_duplicate', 'Canonical pipeline has an invalid stage identity.' );
            $seen[$id] = true;
            $binding = MAD4B_SCP_Operation_Registry::stage_binding( $id, $row );
            if ( is_wp_error( $binding ) ) return $binding;
            $binding_type = isset( $binding['binding_type'] ) ? (string) $binding['binding_type'] : '';
            $ability = isset( $binding['ability'] ) ? (string) $binding['ability'] : '';
            $required = ! isset( $stage['required'] ) || true === $stage['required'];
            $condition = isset( $stage['when'] ) ? (string) $stage['when'] : '';
            $present = '' !== $ability && function_exists( 'wp_has_ability' )
                ? (bool) wp_has_ability( $ability ) : null;
            if ( $required && '' !== $ability && false === $present ) $missing[] = $ability;
            if ( '' !== $condition ) $unresolved[] = $id . ':conditional_input_required';
            if ( 'plan_bound_executor' === $binding_type ) $unresolved[] = $id . ':exact_plan_executor_required';
            if ( in_array( $binding_type, array( 'policy_boundary', 'executor_owned_verification' ), true ) )
                $unresolved[] = $id . ':independent_boundary_verification_required';
            $nodes[] = array(
                'stage_id' => $id, 'position' => (int) $position,
                'stage_type' => $type, 'required' => $required,
                'condition' => $condition, 'condition_evaluated' => false,
                'binding_type' => $binding_type, 'registered_ability' => $ability,
                'ability_registration_observed' => $present,
                'execution_ready' => false, 'independent_readback_required' => 'verification' === $type,
                'separate_governance_required' => in_array( $type, array( 'authorization', 'executor' ), true ),
            );
            if ( null !== $last_id ) $edges[] = array( 'from' => $last_id, 'to' => $id,
                'kind' => 'pipeline_precedence', 'runtime_condition_not_evaluated' => true );
            $last_id = $id;
        }
        $missing = array_values( array_unique( $missing ) );
        $unresolved = array_values( array_unique( $unresolved ) );
        $identity = hash( 'sha256', wp_json_encode( array(
            'operation_id' => (string) ( $row['id'] ?? '' ),
            'profile_id' => $profile_id, 'nodes' => $nodes, 'edges' => $edges ) ) );
        return array(
            'contract' => self::CONTRACT, 'source' => MAD4B_SCP_Operation_Registry::CONTRACT,
            'operation_id' => (string) ( $row['id'] ?? '' ),
            'pipeline_profile' => $profile_id,
            'graph_sha256' => $identity, 'nodes' => $nodes, 'edges' => $edges,
            'missing_registered_abilities' => $missing,
            'unresolved_dependencies' => $unresolved,
            'state' => $missing ? 'REGISTERED_DEPENDENCIES_MISSING' : 'GRAPH_PLANNED_NEEDS_LIVE_ADMISSION',
            'planner_ability' => (string) ( $found['planner'] ?? '' ),
            'executor_ability' => (string) ( $found['executor'] ?? '' ),
            'dependency_descriptors_are_not_authority' => true,
            'execution_ready' => false, 'approval_issued' => false, 'automatic_execution' => false,
            'requires_live_plan_authorization_execution_readback' => true,
            'read_only' => true, 'mutation_performed' => false, 'authority_effect' => 'none',
        );
    }
}
