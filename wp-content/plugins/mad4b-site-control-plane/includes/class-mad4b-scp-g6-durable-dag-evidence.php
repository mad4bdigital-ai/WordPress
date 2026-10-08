<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g6-contracts.php';

/**
 * Read-only verification of *server-owned* durable idempotency records.
 * Durable completion does not, by itself, prove an external postcondition.
 * Never accepts caller-provided "completed" flags or receipt objects.
 */
final class MAD4B_SCP_G6_Durable_DAG_Evidence {
    const CONTRACT = 'mad4b.g6-durable-dag-evidence.v1';

    public static function inspect( array $plan, $node_id ) {
        $owner = MAD4B_SCP_G6_Contracts::owner();
        if ( is_wp_error( $owner ) ) return $owner;
        if ( ! MAD4B_SCP_G6_Contracts::assert_digest( $plan, 'plan_sha256', MAD4B_SCP_G6_Operation_Compiler::CONTRACT ) )
            return MAD4B_SCP_G6_Contracts::error( 'dag_plan_digest', 'The exact compiled plan is not intact.' );
        if ( ! isset( $plan['owner_user_id'] ) || (int) $plan['owner_user_id'] !== $owner )
            return MAD4B_SCP_G6_Contracts::error( 'dag_plan_owner', 'The plan is not owned by this administrator.' );
        if ( ! is_string( $node_id ) || ! isset( $plan['nodes'][ $node_id ] ) || ! isset( $plan['binding'], $plan['binding']['profile_digest'] ) )
            return MAD4B_SCP_G6_Contracts::error( 'dag_node', 'The requested node or binding is unavailable.' );
        $current = MAD4B_SCP_G6_Contracts::binding( $plan['binding']['profile_digest'] );
        if ( is_wp_error( $current ) ) return $current;
        if ( ! hash_equals( MAD4B_SCP_G6_Contracts::digest( $current ), MAD4B_SCP_G6_Contracts::digest( $plan['binding'] ) ) )
            return MAD4B_SCP_G6_Contracts::error( 'dag_generation_changed', 'Site/generation/restore epoch changed; replan and reapprove.' );
        if ( ! class_exists( 'MAD4B_SCP_Content_Jobs' ) || ! class_exists( 'MAD4B_SCP_Durable_Execution' )
            || ! class_exists( 'MAD4B_SCP_Schema' ) )
            return MAD4B_SCP_G6_Contracts::error( 'dag_runtime_missing', 'Required durable storage services are unavailable.' );
        $job = MAD4B_SCP_Content_Jobs::get_job( array( 'job_id' => $plan['job_id'] ) );
        if ( is_wp_error( $job ) ) return $job;
        $job = isset( $job['job'] ) ? $job['job'] : $job;
        if ( ! is_array( $job ) || ! isset( $job['job_revision'], $job['state'], $job['job_id'] )
            || (int) $job['job_revision'] !== (int) $plan['job_revision'] || $job['job_id'] !== $plan['job_id']
            || 'RUNNING' !== $job['state'] || ! isset( $job['site_uuid'] ) || $job['site_uuid'] !== $current['site_uuid'] )
            return MAD4B_SCP_G6_Contracts::error( 'dag_job_changed', 'ContentJob is stale, no longer running or belongs to another site.' );
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) )
            return MAD4B_SCP_G6_Contracts::error( 'dag_database_missing', 'Authoritative durable database reader is unavailable.' );
        $tables = MAD4B_SCP_Schema::tables();
        if ( ! is_array( $tables ) || ! isset( $tables['idempotency'] ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $tables['idempotency'] ) )
            return MAD4B_SCP_G6_Contracts::error( 'dag_table', 'Certified durable table identity is unavailable.' );
        $step = $plan['nodes'][ $node_id ];
        $dependencies = isset( $step['depends_on'] ) ? $step['depends_on'] : array();
        if ( ! is_array( $dependencies ) || count( $dependencies ) > 32 )
            return MAD4B_SCP_G6_Contracts::error( 'dag_dependencies', 'Dependency list exceeds approved bounds.' );
        if ( ! $dependencies )
            return MAD4B_SCP_G6_Contracts::error( 'dag_dependencies_missing', 'No completed dependency can be certified for a root node.' );
        $observed = array();
        foreach ( $dependencies as $dep_id ) {
            if ( ! is_string( $dep_id ) || ! isset( $plan['nodes'][ $dep_id ] ) )
                return MAD4B_SCP_G6_Contracts::error( 'dag_dependency_missing', 'The approved dependency node is unavailable.' );
            $dep = $plan['nodes'][ $dep_id ];
            if ( ! isset( $dep['capability_id'], $dep['typed_input'], $dep['step_sha256'] ) )
                return MAD4B_SCP_G6_Contracts::error( 'dag_dependency_corrupt', 'Dependency execution identity is incomplete.' );
            $scope = MAD4B_SCP_Durable_Execution::scope_key(
                $current['site_uuid'], $dep['capability_id'], 'compiled_step', $plan['job_id'] . ':' . $dep_id
            );
            if ( is_wp_error( $scope ) ) return $scope;
            $key = $plan['plan_sha256'] . ':' . $dep_id;
            if ( strlen( $key ) > 191 ) return MAD4B_SCP_G6_Contracts::error( 'dag_idempotency_key', 'Dependency identity exceeds the durable key bound.' );
            $request_sha256 = MAD4B_SCP_G6_Contracts::digest( $dep['typed_input'] );
            $wpdb->last_error = '';
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT scope_key,idempotency_key,request_sha256,claim_epoch,status,result_sha256,result_json,expires_at FROM {$tables['idempotency']} WHERE scope_key=%s AND idempotency_key=%s LIMIT 1",
                $scope, $key
            ), ARRAY_A );
            if ( ! empty( $wpdb->last_error ) )
                return MAD4B_SCP_G6_Contracts::error( 'dag_read_uncertain', 'Authoritative idempotency readback failed.' );
            if ( ! is_array( $row ) || ! isset( $row['scope_key'], $row['idempotency_key'], $row['request_sha256'],
                $row['claim_epoch'], $row['status'], $row['result_sha256'], $row['result_json'], $row['expires_at'] )
                || ! hash_equals( $scope, (string) $row['scope_key'] )
                || ! hash_equals( $key, (string) $row['idempotency_key'] )
                || ! hash_equals( $request_sha256, (string) $row['request_sha256'] )
                || 'completed' !== $row['status'] || (int) $row['claim_epoch'] < 1
                || ! MAD4B_SCP_G6_Contracts::sha( $row['result_sha256'] )
                || ! is_string( $row['result_json'] ) || strlen( $row['result_json'] ) > 262144
                || ! hash_equals( hash( 'sha256', $row['result_json'] ), $row['result_sha256'] )
                || ! is_string( $row['expires_at'] ) || ! strtotime( $row['expires_at'] )
                || strtotime( $row['expires_at'] ) <= time() )
                return MAD4B_SCP_G6_Contracts::error( 'dag_durable_completion_missing', 'Dependency lacks exact current completed durable evidence.' );
            $observed[] = array(
                'node_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( $dep_id ),
                'step_sha256' => $dep['step_sha256'],
                'durable_result_sha256' => $row['result_sha256'],
                'claim_epoch' => (int) $row['claim_epoch'],
                'durable_completion_observed' => true,
                'provider_postcondition_verified' => false,
            );
        }
        $result = array( 'contract' => self::CONTRACT, 'plan_sha256' => $plan['plan_sha256'],
            'node_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( $node_id ),
            'job_revision' => $plan['job_revision'], 'binding_sha256' => MAD4B_SCP_G6_Contracts::digest( $current ),
            'dependencies' => $observed, 'durable_records_integrity_verified' => true,
            'provider_postconditions_verified' => false, 'dependency_dispatch_admitted' => false,
            'fresh_child_approval_required' => true, 'raw_provider_result_exposed' => false,
            'authorizing' => false );
        $result['evidence_sha256'] = MAD4B_SCP_G6_Contracts::digest( $result );
        return $result;
    }
}
