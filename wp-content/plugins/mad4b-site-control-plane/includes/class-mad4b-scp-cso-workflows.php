<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Pure DAG compiler: no node may execute without a future certified executor. */
final class MAD4B_SCP_CSO_Workflows {
    const CONTRACT = 'mad4b.cso.workflow-compile.v1';

    public static function compile( $nodes ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'workflow' ) ||
            ! MAD4B_SCP_CSO_Scope::first_party_session() ||
            ! is_array( $nodes ) || count( $nodes ) < 1 ||
            count( $nodes ) > 24 ||
            true !== MAD4B_SCP_CSO_Scope::safe_data( $nodes ) ||
            true !== MAD4B_SCP_CSO_Scope::bounded( $nodes ) )
            return MAD4B_SCP_CSO_Scope::error( 'WORKFLOW_INPUT_INVALID' );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $scope ) ) return $scope;
        $map = array();
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ||
                array_diff( array_keys( $node ), array( 'id','kind','requires','plan_sha256' ) ) ||
                ! is_string( $node['id'] ?? null ) ||
                ! preg_match( '/^[a-z][a-z0-9_-]{0,39}$/D', $node['id'] ) ||
                isset( $map[ $node['id'] ] ) ||
                ! in_array( $node['kind'] ?? null, array( 'read','approval','write_intent' ), true ) ||
                ! is_array( $node['requires'] ?? null ) ||
                count( $node['requires'] ) > 8 )
                return MAD4B_SCP_CSO_Scope::error( 'WORKFLOW_NODE_INVALID' );
            if ( $node['kind'] === 'write_intent' &&
                ( ! is_string( $node['plan_sha256'] ?? null ) ||
                    ! preg_match( '/^[a-f0-9]{64}$/D', $node['plan_sha256'] ) ) )
                return MAD4B_SCP_CSO_Scope::error( 'WORKFLOW_WRITE_PLAN_UNBOUND' );
            $map[ $node['id'] ] = $node;
        }
        $in = array(); $forward = array();
        foreach ( $map as $id => $node ) {
            $requires = $node['requires'];
            if ( count( array_unique( $requires ) ) !== count( $requires ) )
                return MAD4B_SCP_CSO_Scope::error( 'WORKFLOW_DUPLICATE_DEPENDENCY' );
            $in[ $id ] = count( $requires );
            foreach ( $requires as $dep ) {
                if ( ! is_string( $dep ) || ! isset( $map[ $dep ] ) || $dep === $id )
                    return MAD4B_SCP_CSO_Scope::error( 'WORKFLOW_FOREIGN_OR_SELF_DEPENDENCY' );
                $forward[ $dep ][] = $id;
            }
        }
        $queue = array();
        foreach ( $in as $id => $degree ) if ( 0 === $degree ) $queue[] = $id;
        sort( $queue, SORT_STRING ); $ordered = array();
        while ( $queue ) {
            $id = array_shift( $queue ); $ordered[] = $id;
            foreach ( $forward[ $id ] ?? array() as $child ) {
                $in[ $child ]--;
                if ( 0 === $in[ $child ] ) $queue[] = $child;
            }
            sort( $queue, SORT_STRING );
        }
        if ( count( $ordered ) !== count( $map ) )
            return MAD4B_SCP_CSO_Scope::error( 'WORKFLOW_CYCLE_DETECTED' );
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return MAD4B_SCP_CSO_Scope::error( 'WORKFLOW_SCOPE_CHANGED' );
        return array( 'contract' => self::CONTRACT,
            'ordered_node_ids' => $ordered, 'node_count' => count( $ordered ),
            'graph_sha256' => MAD4B_SCP_CSO_Scope::digest(
                array( $scope['binding_sha256'], $scope['actor_sha256'], $map ) ),
            'mutation_performed' => false, 'execution_allowed' => false,
            'recovery_policy' => 'stop_on_unknown_effect_require_independent_readback' );
    }

    public static function run( $plan, $governance, $max_nodes ) {
        return MAD4B_SCP_CSO_Scope::error( 'WORKFLOW_EXECUTOR_NOT_CERTIFIED' );
    }
}
