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
                array_diff( array_keys( $node ), array( 'id','kind','requires','plan_sha256','sealed_plan' ) ) ||
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
        $result = array( 'contract' => self::CONTRACT,
            'ordered_node_ids' => $ordered, 'node_count' => count( $ordered ),
            'graph_sha256' => MAD4B_SCP_CSO_Scope::digest(
                array( $scope['binding_sha256'], $scope['actor_sha256'], $map ) ),
            'mutation_performed' => false, 'execution_allowed' => false,
            'recovery_policy' => 'stop_on_unknown_effect_require_independent_readback' );
        $write_plans = array();
        $executable = MAD4B_SCP_CSO_Scope::enabled( 'bulk' ) &&
            MAD4B_SCP_CSO_Scope::enabled( 'single_write' );
        foreach ( $ordered as $id ) {
            $node = $map[ $id ];
            if ( ! $executable || $node['kind'] !== 'write_intent' ||
                ! is_array( $node['sealed_plan'] ?? null ) ) {
                $executable = false; continue;
            }
            $material = MAD4B_SCP_CSO_Scope::unseal(
                $node['sealed_plan'], MAD4B_SCP_CSO_Changes::CONTRACT );
            if ( is_wp_error( $material ) || ! is_array( $material ) ||
                ( $material['contract'] ?? '' ) !== MAD4B_SCP_CSO_Changes::CONTRACT ||
                ! hash_equals( (string) $node['plan_sha256'],
                    MAD4B_SCP_CSO_Scope::digest( $material ) ) ||
                ! hash_equals( (string) ( $material['scope_fingerprint'] ?? '' ),
                    (string) $scope['binding_sha256'] ) ||
                ! hash_equals( (string) ( $material['actor_sha256'] ?? '' ),
                    (string) $scope['actor_sha256'] ) ||
                ( $material['expires_at'] ?? 0 ) <= time() )
                return MAD4B_SCP_CSO_Scope::error( 'WORKFLOW_NATIVE_PLAN_INVALID' );
            $write_plans[] = array( 'plan' => $node['sealed_plan'],
                'plan_sha256' => $node['plan_sha256'] );
        }
        $result['execution_supported'] = false;
        if ( $executable && count( $write_plans ) === count( $ordered ) ) {
            $batch = MAD4B_SCP_CSO_Bulk::plan( $write_plans, array(), 1 );
            if ( is_wp_error( $batch ) ) return $batch;
            $link = array(
                'contract' => self::CONTRACT . '.execution-link.v1',
                'graph_sha256' => $result['graph_sha256'],
                'batch_sha256' => MAD4B_SCP_CSO_Scope::digest( $batch['sealed_batch'] ),
                'scope_sha256' => MAD4B_SCP_CSO_Scope::digest( $scope ),
                'expires_at' => time() + 300 );
            $proof = MAD4B_SCP_CSO_Scope::seal( $link, self::CONTRACT );
            if ( is_wp_error( $proof ) ) return $proof;
            $result['workflow_proof'] = $proof;
            $result['executable_batch'] = $batch['sealed_batch'];
            $result['execution_supported'] = true;
            $result['requires_approved_ticket_per_node'] = true;
            $result['canary_size'] = 1;
        }
        return $result;
    }

    /**
     * Only a completely sealed, write-only DAG may run. Mixed read/approval
     * nodes remain planning-only; skipping such nodes would be unsafe.
     * Native execution is delegated to the durable per-item bulk journal.
     */
    public static function run( $plan, $governance, $max_nodes ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'workflow' ) ||
            ! MAD4B_SCP_CSO_Scope::enabled( 'bulk' ) ||
            ! MAD4B_SCP_CSO_Scope::first_party_session() ||
            ! is_array( $plan ) || empty( $plan['execution_supported'] ) ||
            ! is_array( $plan['executable_batch'] ?? null ) ||
            ! is_array( $plan['workflow_proof'] ?? null ) ||
            ! is_string( $plan['graph_sha256'] ?? null ) ||
            ! is_array( $governance ) ||
            array_diff( array_keys( $governance ),
                array( 'items', 'canary_reviewed', 'checkpoint' ) ) ||
            ! is_array( $governance['checkpoint'] ?? null ) ||
            ! is_int( $max_nodes ) || $max_nodes < 1 || $max_nodes > 5 )
            return MAD4B_SCP_CSO_Scope::error( 'WORKFLOW_RUN_NOT_ADMISSIBLE' );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $scope ) ) return $scope;
        $link = MAD4B_SCP_CSO_Scope::unseal( $plan['workflow_proof'], self::CONTRACT );
        if ( is_wp_error( $link ) || ! is_array( $link ) ||
            ( $link['contract'] ?? '' ) !== self::CONTRACT . '.execution-link.v1' ||
            ( $link['expires_at'] ?? 0 ) <= time() ||
            ! hash_equals( (string) $link['graph_sha256'],
                (string) $plan['graph_sha256'] ) ||
            ! hash_equals( (string) $link['batch_sha256'],
                MAD4B_SCP_CSO_Scope::digest( $plan['executable_batch'] ) ) ||
            ! hash_equals( (string) $link['scope_sha256'],
                MAD4B_SCP_CSO_Scope::digest( $scope ) ) )
            return MAD4B_SCP_CSO_Scope::error( 'WORKFLOW_EXECUTION_LINK_STALE' );
        $ticket_map = array( 'items' => $governance['items'] ?? array(),
            'canary_reviewed' => $governance['canary_reviewed'] ?? false );
        $result = MAD4B_SCP_CSO_Bulk_Runtime::commit(
            $plan['executable_batch'], $ticket_map,
            $governance['checkpoint'], $max_nodes );
        if ( is_wp_error( $result ) ) return $result;
        $result['workflow_graph_sha256'] = $link['graph_sha256'];
        $result['workflow_contract'] = self::CONTRACT . '.native-run.v1';
        $result['compensation_automatic'] = false;
        return $result;
    }
}
