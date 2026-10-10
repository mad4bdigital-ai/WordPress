<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exact bounded item lists, independent child authority and native CAS checkpoints. */
final class MAD4B_SCP_CSO_Bulk {
    const CONTRACT = 'mad4b.cso01.bulk-plan.v1';
    const PURPOSE = 'mad4b.cso01.bulk-plan';
    public static function plan( array $plans, array $selection = array(), $canary_size = 1 ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'bulk' ) ) return MAD4B_SCP_CSO_Changes::error( 'bulk_disabled' );
        if ( ! $plans || count( $plans ) > 100 || ! is_int( $canary_size ) || $canary_size < 1 || $canary_size > count( $plans ) || true !== MAD4B_SCP_CSO_Scope::safe_data( $selection ) ) return MAD4B_SCP_CSO_Changes::error( 'bulk_bounds' );
        $items = array(); $seen = array(); $scope = null; $expires = MAD4B_SCP_CSO_Changes::now() + 300;
        foreach ( $plans as $sealed ) {
            if ( ! is_array( $sealed ) ) return MAD4B_SCP_CSO_Changes::error( 'bulk_item_invalid' );
            $p = MAD4B_SCP_CSO_Changes::inspect( $sealed ); if ( is_wp_error( $p ) ) return $p;
            if ( null !== $scope && ! MAD4B_SCP_CSO_Changes::same( $scope, $p['scope'] ) ) return MAD4B_SCP_CSO_Changes::error( 'bulk_cross_site_denied' );
            $scope = $p['scope']; $expires = min( $expires, $p['expires_at'] ); $target = array();
            foreach ( $p['readback']['target_map'] as $output => $input ) $target[ $output ] = MAD4B_SCP_CSO_Changes::path( $p['input'], $input );
            $ref = hash_hmac( 'sha256', serialize( array( $scope, $p['ability_name'], $target ) ), wp_salt( 'auth' ) );
            if ( isset( $seen[ $ref ] ) ) return MAD4B_SCP_CSO_Changes::error( 'bulk_duplicate_target' ); $seen[ $ref ] = true;
            $items[] = array( 'operation_id' => $p['operation_id'], 'sealed_plan' => $sealed, 'plan_sha256' => $p['plan_sha256'], 'target_ref' => $ref );
        }
        $p = array( 'contract' => self::CONTRACT, 'operation_id' => wp_generate_uuid4(), 'scope' => $scope, 'items' => $items, 'canary_size' => $canary_size, 'item_budget' => count( $items ),
            'selection_sha256' => MAD4B_SCP_CSO_Scope::digest( array( 'selection' => $selection, 'targets' => array_keys( $seen ) ) ), 'expires_at' => $expires, 'authorizing' => false, 'mutation_performed' => false );
        $p['plan_sha256'] = MAD4B_SCP_CSO_Scope::digest( $p ); if ( is_wp_error( $p['plan_sha256'] ) || is_wp_error( $p['selection_sha256'] ) ) return MAD4B_SCP_CSO_Changes::error( 'bulk_digest_invalid' );
        $seal = MAD4B_SCP_CSO_Scope::seal( $p, self::PURPOSE ); if ( is_wp_error( $seal ) ) return $seal;
        return array( 'state' => 'PLANNED', 'sealed_plan' => $seal, 'operation_id' => $p['operation_id'], 'plan_sha256' => $p['plan_sha256'], 'item_count' => count( $items ), 'canary_size' => $canary_size,
            'selection_sha256' => $p['selection_sha256'], 'authorizing' => false, 'mutation_performed' => false, 'global_transaction_atomic' => false );
    }
    public static function commit( array $sealed, array $governance, $expected_checkpoint = 0, $max_items = 10 ) {
        $p = MAD4B_SCP_CSO_Scope::unseal( $sealed, self::PURPOSE ); if ( is_wp_error( $p ) ) return $p;
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'bulk' ) || ( $p['contract'] ?? '' ) !== self::CONTRACT || ( $p['expires_at'] ?? 0 ) <= MAD4B_SCP_CSO_Changes::now()
            || ! is_int( $expected_checkpoint ) || $expected_checkpoint < 0 || ! is_int( $max_items ) || $max_items < 1 || $max_items > 10
            || true !== ( $governance['user_confirmed'] ?? null ) || ( $governance['expected_plan_sha256'] ?? '' ) !== ( $p['plan_sha256'] ?? null ) || ! is_array( $governance['items'] ?? null )
            || array_diff( array_keys( $governance ), array( 'user_confirmed', 'expected_plan_sha256', 'items', 'canary_accepted_sha256' ) ) ) return MAD4B_SCP_CSO_Changes::error( 'bulk_confirmation_invalid' );
        $scope = MAD4B_SCP_CSO_Scope::assert_current( $p['scope'] ); if ( is_wp_error( $scope ) ) return $scope;
        $j = self::start( $p ); if ( is_wp_error( $j ) ) return $j;
        $states = MAD4B_SCP_CSO_Journal::nodes( $j ); $checkpoint = count( array_filter( $states, static function ( $v ) { return 'SUCCEEDED' === $v; } ) );
        if ( $checkpoint !== $expected_checkpoint ) return MAD4B_SCP_CSO_Changes::error( 'bulk_checkpoint_stale' );
        if ( 'UNCERTAIN' === $j['state'] || in_array( 'RUNNING', $states, true ) || in_array( 'UNCERTAIN', $states, true ) ) return self::result( $p, 'UNCERTAIN', $checkpoint, array(), 'reconcile_before_resume' );
        // Canary and completed item drift is checked before any next provider entry.
        for ( $i = 0; $i < $checkpoint; $i++ ) { $proof = MAD4B_SCP_CSO_Changes::verify( $p['items'][ $i ]['sealed_plan'] ); if ( is_wp_error( $proof ) || ! $proof['verified'] ) return self::result( $p, 'UNCERTAIN', $checkpoint, array(), 'completed_child_drift' ); }
        if ( 'SUCCEEDED' === $j['state'] ) return self::result( $p, 'SUCCEEDED', $checkpoint, array(), '' );
        if ( ! in_array( $j['state'], array( 'PLANNED', 'PARTIAL' ), true ) ) return MAD4B_SCP_CSO_Changes::error( 'bulk_state_denied' );
        if ( $checkpoint >= $p['canary_size'] && $checkpoint < count( $p['items'] ) && ( $governance['canary_accepted_sha256'] ?? '' ) !== self::canary( $p ) ) return self::result( $p, 'PARTIAL', $checkpoint, array(), 'canary_review_required' );
        $saved = MAD4B_SCP_CSO_Journal::transition( $p, $j['state'], 'RUNNING' ); if ( is_wp_error( $saved ) ) return $saved;
        $stop = min( count( $p['items'] ), $checkpoint + $max_items ); if ( $checkpoint < $p['canary_size'] ) $stop = min( $stop, $p['canary_size'] ); $results = array();
        for ( $i = $checkpoint; $i < $stop; ++$i ) {
            $item = $p['items'][ $i ]; $authority = $governance['items'][ $item['operation_id'] ] ?? null;
            if ( ! is_array( $authority ) ) { $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'RUNNING', 'PARTIAL', array( 'checkpoint' => $i, 'reason_code' => 'child_authority_required' ) ); return is_wp_error( $saved ) ? $saved : self::result( $p, 'PARTIAL', $i, $results, 'child_authority_required' ); }
            $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'RUNNING', 'RUNNING', array( 'node_id' => $item['operation_id'], 'node_state' => 'RUNNING', 'checkpoint' => $i ) ); if ( is_wp_error( $saved ) ) return $saved;
            try { $r = MAD4B_SCP_CSO_Changes::commit( $item['sealed_plan'], $authority ); } catch ( Throwable $e ) { $r = MAD4B_SCP_CSO_Changes::error( 'child_outcome_unknown' ); }
            $state = is_wp_error( $r ) ? 'UNCERTAIN' : ( $r['state'] ?? 'UNCERTAIN' ); $parent = 'SUCCEEDED' === $state ? 'RUNNING' : ( 'UNCERTAIN' === $state ? 'UNCERTAIN' : 'PARTIAL' );
            $results[] = array( 'target_ref' => $item['target_ref'], 'state' => $state, 'receipt_ref' => $item['operation_id'] );
            $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'RUNNING', $parent, array( 'node_id' => $item['operation_id'], 'node_state' => $state, 'receipt_ref' => $item['operation_id'], 'checkpoint' => 'SUCCEEDED' === $state ? $i + 1 : $i ) );
            if ( is_wp_error( $saved ) ) return self::result( $p, 'UNCERTAIN', $i, $results, 'checkpoint_persistence_uncertain' );
            if ( 'SUCCEEDED' !== $state ) return self::result( $p, $parent, $i, $results, 'child_not_succeeded' ); $checkpoint = $i + 1;
        }
        if ( $checkpoint < count( $p['items'] ) ) { $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'RUNNING', 'PARTIAL', array( 'checkpoint' => $checkpoint ) ); return is_wp_error( $saved ) ? $saved : self::result( $p, 'PARTIAL', $checkpoint, $results, '' ); }
        $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'RUNNING', 'VERIFYING' ); if ( is_wp_error( $saved ) ) return $saved;
        foreach ( $p['items'] as $item ) { $proof = MAD4B_SCP_CSO_Changes::verify( $item['sealed_plan'] ); if ( is_wp_error( $proof ) || ! $proof['verified'] ) { MAD4B_SCP_CSO_Journal::transition( $p, 'VERIFYING', 'UNCERTAIN' ); return self::result( $p, 'UNCERTAIN', $checkpoint, $results, 'final_readback_failed' ); } }
        $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'VERIFYING', 'SUCCEEDED', array( 'verified' => true ) ); return is_wp_error( $saved ) ? $saved : self::result( $p, 'SUCCEEDED', $checkpoint, $results, '' );
    }
    public static function start( array $p ) { $j = MAD4B_SCP_CSO_Journal::inspect( $p ); if ( is_wp_error( $j ) && $j->get_error_code() === 'mad4b_operation_not_found' ) { $created = MAD4B_SCP_CSO_Journal::begin( $p ); if ( is_wp_error( $created ) ) return $created; $j = MAD4B_SCP_CSO_Journal::inspect( $p ); } return $j; }
    private static function canary( array $p ) { return hash_hmac( 'sha256', $p['plan_sha256'] . ':' . $p['canary_size'], wp_salt( 'auth' ) ); }
    private static function result( array $p, $state, $checkpoint, array $results, $reason ) { return array( 'contract' => 'mad4b.cso01.bulk-receipt.v1', 'operation_id' => $p['operation_id'], 'state' => $state, 'checkpoint' => $checkpoint, 'item_count' => count( $p['items'] ), 'item_results' => $results, 'reason_code' => $reason, 'canary_review_sha256' => $checkpoint >= $p['canary_size'] ? self::canary( $p ) : '', 'verified' => $state === 'SUCCEEDED', 'authorizing' => false, 'blind_retry_allowed' => false, 'global_transaction_atomic' => false, 'secret_values_present' => false ); }
}

/** Bounded typed inert DAGs. Conditions, waits and provider events confer no authority. */
final class MAD4B_SCP_CSO_Workflows {
    const CONTRACT = 'mad4b.cso01.workflow.v1';
    const PURPOSE = 'mad4b.cso01.workflow';
    public static function compile( array $nodes ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'workflow' ) ) return MAD4B_SCP_CSO_Changes::error( 'workflow_disabled' );
        if ( ! $nodes || count( $nodes ) > 32 ) return MAD4B_SCP_CSO_Changes::error( 'workflow_bounds' );
        $scope = MAD4B_SCP_CSO_Scope::current(); if ( is_wp_error( $scope ) ) return $scope;
        $indexed = array(); $changes = array(); $edges = 0; $expires = MAD4B_SCP_CSO_Changes::now() + 300;
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) || ! self::id( $node['id'] ?? null ) || isset( $indexed[ $node['id'] ] ) || ! in_array( $node['type'] ?? '', array( 'read', 'change', 'wait', 'branch' ), true ) || ! is_array( $node['depends_on'] ?? null ) || count( $node['depends_on'] ) > 16 ) return MAD4B_SCP_CSO_Changes::error( 'workflow_node_invalid' );
            $extras = array( 'read' => array( 'ability_name', 'input', 'bindings' ), 'change' => array( 'sealed_plan' ), 'wait' => array( 'until' ), 'branch' => array( 'condition' ) );
            if ( array_diff( array_keys( $node ), array_merge( array( 'id', 'type', 'depends_on', 'when' ), $extras[ $node['type'] ] ) ) ) return MAD4B_SCP_CSO_Changes::error( 'workflow_untyped_field_denied' );
            $deps = array(); foreach ( $node['depends_on'] as $dep ) { if ( ! self::id( $dep ) || isset( $deps[ $dep ] ) ) return MAD4B_SCP_CSO_Changes::error( 'workflow_dependency_invalid' ); $deps[ $dep ] = true; }
            $edges += count( $deps ); if ( $edges > 64 ) return MAD4B_SCP_CSO_Changes::error( 'workflow_edges_exceeded' );
            $c = array( 'id' => $node['id'], 'type' => $node['type'], 'depends_on' => array_keys( $deps ) );
            if ( 'change' === $c['type'] ) {
                if ( ! is_array( $node['sealed_plan'] ?? null ) ) return MAD4B_SCP_CSO_Changes::error( 'workflow_exact_change_required' );
                $p = MAD4B_SCP_CSO_Changes::inspect( $node['sealed_plan'] ); if ( is_wp_error( $p ) ) return $p;
                if ( isset( $changes[ $p['operation_id'] ] ) || ! MAD4B_SCP_CSO_Changes::same( $scope, $p['scope'] ) ) return MAD4B_SCP_CSO_Changes::error( 'workflow_change_reuse_or_scope_drift' );
                $changes[ $p['operation_id'] ] = true; $c['sealed_plan'] = $node['sealed_plan']; $c['plan_sha256'] = $p['plan_sha256']; $expires = min( $expires, $p['expires_at'] );
            } elseif ( 'read' === $c['type'] ) {
                if ( ! is_string( $node['ability_name'] ?? null ) || ! is_array( $node['input'] ?? null ) || true !== MAD4B_SCP_CSO_Scope::safe_data( $node['input'] ) ) return MAD4B_SCP_CSO_Changes::error( 'workflow_read_invalid' );
                $d = MAD4B_SCP_CSO_Registry::describe( $node['ability_name'], $node['input'] ); if ( is_wp_error( $d ) ) return $d;
                if ( true !== $d['read_only'] || 'read' !== $d['lane'] ) return MAD4B_SCP_CSO_Changes::error( 'workflow_read_uncertified' );
                $c += array( 'ability_name' => $node['ability_name'], 'input' => $node['input'], 'schema_sha256' => $d['schema_sha256'], 'adapter_sha256' => $d['adapter_sha256'], 'capability_binding' => $d['capability_binding'], 'input_schema' => $d['input_schema'], 'output_schema' => $d['output_schema'], 'bindings' => $node['bindings'] ?? array() );
                if ( ! is_array( $c['bindings'] ) || count( $c['bindings'] ) > 16 ) return MAD4B_SCP_CSO_Changes::error( 'workflow_binding_bounds' );
            } elseif ( 'wait' === $c['type'] ) {
                if ( ! is_int( $node['until'] ?? null ) || $node['until'] < MAD4B_SCP_CSO_Changes::now() ) return MAD4B_SCP_CSO_Changes::error( 'workflow_wait_invalid' ); $c['until'] = $node['until'];
            } else { if ( ! self::condition( $node['condition'] ?? null, $c['depends_on'], true ) ) return MAD4B_SCP_CSO_Changes::error( 'workflow_condition_invalid' ); $c['condition'] = $node['condition']; }
            if ( isset( $node['when'] ) ) { if ( ! self::condition( $node['when'], $c['depends_on'], false ) ) return MAD4B_SCP_CSO_Changes::error( 'workflow_branch_invalid' ); $c['when'] = $node['when']; }
            $indexed[ $c['id'] ] = $c;
        }
        foreach ( $indexed as $c ) {
            foreach ( $c['depends_on'] as $dep ) if ( ! isset( $indexed[ $dep ] ) || $dep === $c['id'] ) return MAD4B_SCP_CSO_Changes::error( 'workflow_unbound_dependency' );
            if ( 'wait' === $c['type'] && $c['until'] >= $expires ) return MAD4B_SCP_CSO_Changes::error( 'workflow_wait_expiry' );
            if ( isset( $c['when'] ) && 'branch' !== $indexed[ $c['when']['node'] ]['type'] ) return MAD4B_SCP_CSO_Changes::error( 'workflow_branch_type' );
            if ( 'branch' === $c['type'] ) { $source = $indexed[ $c['condition']['node'] ]; $schema = self::schema_path( $source['output_schema'] ?? null, $c['condition']['path'] ); if ( 'read' !== $source['type'] || ( $schema['type'] ?? '' ) !== 'boolean' ) return MAD4B_SCP_CSO_Changes::error( 'workflow_condition_type' ); }
            foreach ( $c['bindings'] ?? array() as $parameter => $ref ) {
                if ( ! MAD4B_SCP_CSO_Changes::is_path( $parameter ) || false !== strpos( $parameter, '.' ) || ! is_array( $ref ) || array_diff( array_keys( $ref ), array( 'node', 'path' ) ) || ! is_string( $ref['node'] ?? null ) || ! in_array( $ref['node'], $c['depends_on'], true ) || ! MAD4B_SCP_CSO_Changes::is_path( $ref['path'] ?? null ) || 'read' !== ( $indexed[ $ref['node'] ]['type'] ?? '' ) ) return MAD4B_SCP_CSO_Changes::error( 'workflow_unbound_output' );
                $source = self::schema_path( $indexed[ $ref['node'] ]['output_schema'], $ref['path'] ); $target = $c['input_schema']['properties'][ $parameter ] ?? null;
                if ( ! is_array( $source ) || ! is_array( $target ) || ( $source['type'] ?? null ) !== ( $target['type'] ?? null ) ) return MAD4B_SCP_CSO_Changes::error( 'workflow_output_type' );
            }
        }
        $order = array(); $pending = $indexed;
        while ( $pending ) { $progress = false; foreach ( $pending as $id => $c ) if ( ! array_diff( $c['depends_on'], $order ) ) { $order[] = $id; unset( $pending[ $id ] ); $progress = true; } if ( ! $progress ) return MAD4B_SCP_CSO_Changes::error( 'workflow_cycle' ); }
        $p = array( 'contract' => self::CONTRACT, 'operation_id' => wp_generate_uuid4(), 'scope' => $scope, 'nodes' => $indexed, 'order' => $order, 'expires_at' => $expires, 'authorizing' => false, 'mutation_performed' => false, 'automatic_retry_allowed' => false );
        $p['plan_sha256'] = MAD4B_SCP_CSO_Scope::digest( $p ); if ( is_wp_error( $p['plan_sha256'] ) ) return $p['plan_sha256'];
        $seal = MAD4B_SCP_CSO_Scope::seal( $p, self::PURPOSE ); if ( is_wp_error( $seal ) ) return $seal;
        return array( 'state' => 'PLANNED', 'sealed_workflow' => $seal, 'operation_id' => $p['operation_id'], 'plan_sha256' => $p['plan_sha256'], 'node_count' => count( $indexed ), 'order' => $order, 'authorizing' => false, 'mutation_performed' => false, 'global_transaction_atomic' => false );
    }
    public static function run( array $sealed, array $governance = array(), $max_nodes = 8 ) {
        $p = MAD4B_SCP_CSO_Scope::unseal( $sealed, self::PURPOSE ); if ( is_wp_error( $p ) ) return $p;
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'workflow' ) || ( $p['contract'] ?? '' ) !== self::CONTRACT || ( $p['expires_at'] ?? 0 ) <= MAD4B_SCP_CSO_Changes::now() || ! is_int( $max_nodes ) || $max_nodes < 1 || $max_nodes > 16 ) return MAD4B_SCP_CSO_Changes::error( 'workflow_expired_or_bounds' );
        $scope = MAD4B_SCP_CSO_Scope::assert_current( $p['scope'] ); if ( is_wp_error( $scope ) ) return $scope;
        $j = MAD4B_SCP_CSO_Bulk::start( $p ); if ( is_wp_error( $j ) ) return $j; $states = MAD4B_SCP_CSO_Journal::nodes( $j );
        if ( $j['state'] === 'UNCERTAIN' || in_array( 'RUNNING', $states, true ) || in_array( 'UNCERTAIN', $states, true ) ) return self::result( $p, 'UNCERTAIN', $states, 'reconcile_before_wake' );
        if ( ! in_array( $j['state'], array( 'PLANNED', 'PARTIAL', 'SUCCEEDED' ), true ) ) return MAD4B_SCP_CSO_Changes::error( 'workflow_state_denied' );
        $completed = $j['state'] === 'SUCCEEDED'; if ( ! $completed ) { $saved = MAD4B_SCP_CSO_Journal::transition( $p, $j['state'], 'RUNNING' ); if ( is_wp_error( $saved ) ) return $saved; }
        $outputs = array(); $new_nodes = 0;
        foreach ( $p['order'] as $id ) {
            $c = $p['nodes'][ $id ]; $done = in_array( $states[ $id ] ?? '', array( 'SUCCEEDED', 'SKIPPED' ), true );
            if ( 'change' === $c['type'] && $done ) { if ( $states[ $id ] === 'SKIPPED' ) continue; $proof = MAD4B_SCP_CSO_Changes::verify( $c['sealed_plan'] ); if ( is_wp_error( $proof ) || ! $proof['verified'] ) return $completed ? self::result( $p, 'UNCERTAIN', $states, 'completed_child_drift' ) : self::stop( $p, $states, 'UNCERTAIN', 'completed_child_drift' ); continue; }
            if ( ! $done && ++$new_nodes > $max_nodes ) return self::stop( $p, $states, 'PARTIAL', 'node_budget_checkpoint' );
            if ( 'wait' === $c['type'] && MAD4B_SCP_CSO_Changes::now() < $c['until'] ) return self::stop( $p, $states, 'PARTIAL', 'await_wake' );
            if ( isset( $c['when'] ) ) {
                $branch = $outputs[ $c['when']['node'] ] ?? null; if ( ! is_bool( $branch ) ) return $completed ? self::result( $p, 'UNCERTAIN', $states, 'branch_observation_missing' ) : self::stop( $p, $states, 'UNCERTAIN', 'branch_observation_missing' );
                if ( $branch !== $c['when']['equals'] ) { $states[ $id ] = 'SKIPPED'; if ( ! $completed ) { $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'RUNNING', 'RUNNING', array( 'node_id' => $id, 'node_state' => 'SKIPPED' ) ); if ( is_wp_error( $saved ) ) return $saved; } continue; }
            }
            if ( $completed && 'change' === $c['type'] ) return self::result( $p, 'UNCERTAIN', $states, 'branch_drift_requires_new_plan' );
            // No inherited node authority. A missing envelope stops before child provider entry.
            if ( 'change' === $c['type'] && ! is_array( $governance[ $id ] ?? null ) ) { $states[ $id ] = 'DENIED'; $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'RUNNING', 'PARTIAL', array( 'node_id' => $id, 'node_state' => 'DENIED', 'reason_code' => 'step_authority_required' ) ); return is_wp_error( $saved ) ? $saved : self::result( $p, 'PARTIAL', $states, 'step_authority_required' ); }
            if ( ! $completed ) { $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'RUNNING', 'RUNNING', array( 'node_id' => $id, 'node_state' => 'RUNNING' ) ); if ( is_wp_error( $saved ) ) return $saved; } $ref = '';
            if ( 'change' === $c['type'] ) { try { $r = MAD4B_SCP_CSO_Changes::commit( $c['sealed_plan'], $governance[ $id ] ); } catch ( Throwable $e ) { $r = MAD4B_SCP_CSO_Changes::error( 'workflow_child_unknown' ); } $state = is_wp_error( $r ) ? 'UNCERTAIN' : ( $r['state'] ?? 'UNCERTAIN' ); if ( ! is_wp_error( $r ) ) $ref = $r['operation_id'] ?? ''; }
            elseif ( 'read' === $c['type'] ) {
                $input = $c['input']; foreach ( $c['bindings'] as $parameter => $binding ) { $input[ $parameter ] = MAD4B_SCP_CSO_Changes::path( $outputs[ $binding['node'] ] ?? null, $binding['path'], $exists ); if ( ! $exists ) return $completed ? self::result( $p, 'UNCERTAIN', $states, 'read_binding_missing' ) : self::stop( $p, $states, 'UNCERTAIN', 'read_binding_missing' ); }
                $d = MAD4B_SCP_CSO_Registry::describe( $c['ability_name'], $input );
                if ( is_wp_error( $d ) || $d['schema_sha256'] !== $c['schema_sha256'] || $d['adapter_sha256'] !== $c['adapter_sha256'] || ! MAD4B_SCP_CSO_Changes::same( $d['capability_binding'], $c['capability_binding'] ) ) return $completed ? self::result( $p, 'UNCERTAIN', $states, 'read_descriptor_drift' ) : self::stop( $p, $states, 'UNCERTAIN', 'read_descriptor_drift' );
                $read = MAD4B_SCP_CSO_Registry::read( $c['ability_name'], $input, $d['preparation'] ); $state = is_wp_error( $read ) ? 'FAILED' : 'SUCCEEDED'; if ( $state === 'SUCCEEDED' ) $outputs[ $id ] = $read['result'];
            } elseif ( 'branch' === $c['type'] ) { $v = MAD4B_SCP_CSO_Changes::path( $outputs[ $c['condition']['node'] ] ?? null, $c['condition']['path'], $exists ); if ( ! $exists || ! is_bool( $v ) ) return $completed ? self::result( $p, 'UNCERTAIN', $states, 'branch_type_drift' ) : self::stop( $p, $states, 'UNCERTAIN', 'branch_type_drift' ); $outputs[ $id ] = $v === $c['condition']['equals']; $state = 'SUCCEEDED'; }
            else { $state = 'SUCCEEDED'; }
            $states[ $id ] = $state;
            if ( $completed ) { if ( $state !== 'SUCCEEDED' ) return self::result( $p, 'UNCERTAIN', $states, 'current_read_permission_denied' ); continue; }
            $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'RUNNING', $state === 'SUCCEEDED' ? 'RUNNING' : ( $state === 'UNCERTAIN' ? 'UNCERTAIN' : 'PARTIAL' ), array( 'node_id' => $id, 'node_state' => $state, 'receipt_ref' => $ref ) );
            if ( is_wp_error( $saved ) ) return self::result( $p, 'UNCERTAIN', $states, 'node_checkpoint_uncertain' ); if ( $state !== 'SUCCEEDED' ) return self::result( $p, $state === 'UNCERTAIN' ? 'UNCERTAIN' : 'PARTIAL', $states, 'node_not_succeeded' );
        }
        if ( $completed ) return self::result( $p, 'SUCCEEDED', $states, '' );
        $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'RUNNING', 'VERIFYING' ); if ( is_wp_error( $saved ) ) return $saved;
        $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'VERIFYING', 'SUCCEEDED', array( 'verified' => true ) ); return is_wp_error( $saved ) ? $saved : self::result( $p, 'SUCCEEDED', $states, '' );
    }
    private static function id( $v ) { return is_string( $v ) && 1 === preg_match( '/^[a-z][a-z0-9_-]{0,47}$/D', $v ); }
    private static function condition( $c, array $deps, $path ) { return is_array( $c ) && ! array_diff( array_keys( $c ), $path ? array( 'node', 'path', 'equals' ) : array( 'node', 'equals' ) ) && is_string( $c['node'] ?? null ) && in_array( $c['node'], $deps, true ) && is_bool( $c['equals'] ?? null ) && ( ! $path || MAD4B_SCP_CSO_Changes::is_path( $c['path'] ?? null ) ); }
    private static function schema_path( $s, $path ) { foreach ( explode( '.', $path ) as $part ) { if ( ! is_array( $s ) || ( $s['type'] ?? '' ) !== 'object' || ! isset( $s['properties'][ $part ] ) ) return null; $s = $s['properties'][ $part ]; } return $s; }
    private static function stop( array $p, array $states, $state, $reason ) { $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'RUNNING', $state, array( 'reason_code' => $reason ) ); return is_wp_error( $saved ) ? $saved : self::result( $p, $state, $states, $reason ); }
    private static function result( array $p, $state, array $states, $reason ) { return array( 'contract' => 'mad4b.cso01.workflow-receipt.v1', 'operation_id' => $p['operation_id'], 'state' => $state, 'nodes' => $states, 'reason_code' => $reason, 'verified' => $state === 'SUCCEEDED', 'authorizing' => false, 'blind_retry_allowed' => false, 'global_transaction_atomic' => false, 'outputs_disclosed' => false, 'secret_values_present' => false ); }
}
