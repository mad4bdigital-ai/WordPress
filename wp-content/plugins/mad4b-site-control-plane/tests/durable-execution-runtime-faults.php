<?php
/**
 * Runtime fault injection for durable execution primitives on a real MariaDB schema.
 *
 * Execute with WP-CLI eval-file after schema v9 is installed. This test never
 * calls providers or external networks; it proves lease/idempotency/outbox/inbox
 * persistence and stale-worker fencing against the real database.
 */

$root = dirname( __DIR__ );
require_once $root . '/includes/class-mad4b-scp-schema.php';
require_once $root . '/includes/class-mad4b-scp-durable-execution.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL durable-execution-runtime-faults: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) {
	if ( ! $condition ) $fail( $message );
};
$error_code = static function ( $value ) {
	return is_wp_error( $value ) ? $value->get_error_code() : '';
};

$status = MAD4B_SCP_Schema::status( true );
$check( ! empty( $status['ready'] ), 'schema is not ready' );
$tables = MAD4B_SCP_Schema::tables();
global $wpdb;

// ---- Lease / fencing fault injection ----
$work_id = wp_generate_uuid4();
$aggregate_id = 'job-' . wp_generate_uuid4();
$lease_a = MAD4B_SCP_Durable_Execution::acquire_lease(
	$work_id,
	'content_job',
	$aggregate_id,
	'worker-a',
	7,
	30
);
$check( is_array( $lease_a ) && 1 === (int) $lease_a['lease_epoch'], 'initial lease acquisition failed' );

$lease_a_repeat = MAD4B_SCP_Durable_Execution::acquire_lease(
	$work_id,
	'content_job',
	$aggregate_id,
	'worker-a',
	7,
	30
);
$check( is_array( $lease_a_repeat ) && 1 === (int) $lease_a_repeat['lease_epoch'], 'same owner did not receive authoritative lease' );

$held = MAD4B_SCP_Durable_Execution::acquire_lease(
	$work_id,
	'content_job',
	$aggregate_id,
	'worker-b',
	7,
	30
);
$check( 'mad4b_lease_held' === $error_code( $held ), 'concurrent worker was not denied' );

// Force expiry to model a crashed worker whose lease was not renewed.
$expired_at = gmdate( 'Y-m-d H:i:s', time() - 120 );
$updated = $wpdb->update(
	$tables['work_leases'],
	array( 'expires_at' => $expired_at, 'heartbeat_at' => $expired_at ),
	array( 'work_id' => $work_id ),
	array( '%s', '%s' ),
	array( '%s' )
);
$check( 1 === (int) $updated, 'unable to expire disposable lease fixture' );

$expired_fence = MAD4B_SCP_Durable_Execution::assert_fencing_token( $work_id, 'worker-a', 1, 7 );
$check( 'mad4b_fence_lease_expired' === $error_code( $expired_fence ), 'expired worker still passed commit fence' );

$expired_complete = MAD4B_SCP_Durable_Execution::complete_lease( $work_id, 'worker-a', 1, 'completed' );
$check( 'mad4b_lease_complete_fenced' === $error_code( $expired_complete ), 'expired worker terminalized lease before reconciliation' );

$reclaim_unverified = MAD4B_SCP_Durable_Execution::reclaim_lease(
	$work_id,
	'worker-b',
	8,
	'reconcile:lease:verified-readback',
	30
);
$check( 'mad4b_reconciliation_unverified' === $error_code( $reclaim_unverified ), 'lease reclaim bypassed reconciliation verifier' );

$reconcile_filter = static function ( $verified, $kind, $context ) {
	if ( 'lease_reclaim' === $kind
		&& isset( $context['reconciliation_ref'] )
		&& 'reconcile:lease:verified-readback' === $context['reconciliation_ref'] ) {
		return true;
	}
	if ( 'idempotency_reclaim' === $kind
		&& isset( $context['reconciliation_ref'] )
		&& 'reconcile:idempotency:verified-readback' === $context['reconciliation_ref'] ) {
		return true;
	}
	return $verified;
};
add_filter( 'mad4b_scp_durable_reconciliation_verified', $reconcile_filter, PHP_INT_MAX, 3 );

$lease_b = MAD4B_SCP_Durable_Execution::reclaim_lease(
	$work_id,
	'worker-b',
	8,
	'reconcile:lease:verified-readback',
	30
);
$check( is_array( $lease_b ) && 2 === (int) $lease_b['lease_epoch'], 'reconciled lease did not advance fencing epoch' );

$stale_epoch = MAD4B_SCP_Durable_Execution::assert_fencing_token( $work_id, 'worker-a', 1, 7 );
$check( 'mad4b_fence_epoch_stale' === $error_code( $stale_epoch ), 'zombie epoch was not fenced' );

$wrong_worker = MAD4B_SCP_Durable_Execution::assert_fencing_token( $work_id, 'worker-a', 2, 8 );
$check( 'mad4b_fence_worker_mismatch' === $error_code( $wrong_worker ), 'old worker matched reclaimed epoch' );

$ahead_epoch = MAD4B_SCP_Durable_Execution::assert_fencing_token( $work_id, 'worker-b', 3, 8 );
$check( 'mad4b_fence_epoch_unknown' === $error_code( $ahead_epoch ), 'unknown future epoch was accepted' );

$wrong_revision = MAD4B_SCP_Durable_Execution::assert_fencing_token( $work_id, 'worker-b', 2, 7 );
$check( 'mad4b_fence_revision_mismatch' === $error_code( $wrong_revision ), 'wrong aggregate revision was accepted' );

$valid_fence = MAD4B_SCP_Durable_Execution::assert_fencing_token( $work_id, 'worker-b', 2, 8 );
$check( is_array( $valid_fence ) && 2 === (int) $valid_fence['lease_epoch'], 'authoritative reclaimed lease did not pass fence' );

$stale_heartbeat = MAD4B_SCP_Durable_Execution::heartbeat( $work_id, 'worker-a', 1, 30 );
$check( 'mad4b_lease_heartbeat_fenced' === $error_code( $stale_heartbeat ), 'zombie worker renewed stale lease' );

$stale_complete = MAD4B_SCP_Durable_Execution::complete_lease( $work_id, 'worker-a', 1, 'completed' );
$check( 'mad4b_lease_complete_fenced' === $error_code( $stale_complete ), 'zombie worker completed reclaimed lease' );

$complete = MAD4B_SCP_Durable_Execution::complete_lease( $work_id, 'worker-b', 2, 'completed' );
$check( true === $complete, 'authoritative worker could not complete lease' );

$terminal_fence = MAD4B_SCP_Durable_Execution::assert_fencing_token( $work_id, 'worker-b', 2, 8 );
$check( 'mad4b_fence_lease_inactive' === $error_code( $terminal_fence ), 'terminal lease still passed commit fence' );

$terminal_reclaim = MAD4B_SCP_Durable_Execution::reclaim_lease(
	$work_id,
	'worker-c',
	9,
	'reconcile:lease:verified-readback',
	30
);
$check( 'mad4b_lease_terminal_reclaim_denied' === $error_code( $terminal_reclaim ), 'terminal lease was resurrected' );

// ---- Idempotency crash/reconciliation semantics ----
$scope = MAD4B_SCP_Durable_Execution::scope_key(
	'site-' . wp_generate_uuid4(),
	'workflow.execute',
	'execution.start',
	$aggregate_id
);
$idempotency_key = 'ci-idempotency-' . wp_generate_uuid4();
$request_sha = hash( 'sha256', 'request:' . $idempotency_key );
$claim_a = MAD4B_SCP_Durable_Execution::begin_idempotency( $scope, $idempotency_key, $request_sha, 3600 );
$check( is_array( $claim_a ) && ! empty( $claim_a['claimed'] ) && 1 === (int) $claim_a['claim_epoch'], 'initial idempotency claim failed' );

$pending_duplicate = MAD4B_SCP_Durable_Execution::begin_idempotency( $scope, $idempotency_key, $request_sha, 3600 );
$check( 'mad4b_idempotency_in_progress' === $error_code( $pending_duplicate ), 'pending duplicate did not fail closed' );

$hash_conflict = MAD4B_SCP_Durable_Execution::begin_idempotency(
	$scope,
	$idempotency_key,
	hash( 'sha256', 'different-request' ),
	3600
);
$check( 'mad4b_idempotency_hash_conflict' === $error_code( $hash_conflict ), 'same idempotency key accepted different request hash' );

$id_expired = gmdate( 'Y-m-d H:i:s', time() - 120 );
$id_update = $wpdb->update(
	$tables['idempotency'],
	array( 'expires_at' => $id_expired ),
	array( 'scope_key' => $scope, 'idempotency_key' => $idempotency_key ),
	array( '%s' ),
	array( '%s', '%s' )
);
$check( 1 === (int) $id_update, 'unable to expire disposable idempotency fixture' );

$expired_duplicate = MAD4B_SCP_Durable_Execution::begin_idempotency( $scope, $idempotency_key, $request_sha, 3600 );
$check( 'mad4b_idempotency_reconciliation_required' === $error_code( $expired_duplicate ), 'expired pending idempotency was blindly reclaimed' );

remove_filter( 'mad4b_scp_durable_reconciliation_verified', $reconcile_filter, PHP_INT_MAX );
$reclaim_id_unverified = MAD4B_SCP_Durable_Execution::reclaim_idempotency(
	$scope,
	$idempotency_key,
	$request_sha,
	'reconcile:idempotency:verified-readback',
	3600
);
$check( 'mad4b_reconciliation_unverified' === $error_code( $reclaim_id_unverified ), 'idempotency reclaim bypassed reconciliation verifier' );
add_filter( 'mad4b_scp_durable_reconciliation_verified', $reconcile_filter, PHP_INT_MAX, 3 );

$claim_b = MAD4B_SCP_Durable_Execution::reclaim_idempotency(
	$scope,
	$idempotency_key,
	$request_sha,
	'reconcile:idempotency:verified-readback',
	3600
);
$check( is_array( $claim_b ) && ! empty( $claim_b['reclaimed'] ) && 2 === (int) $claim_b['claim_epoch'], 'idempotency reclaim did not advance claim epoch' );

$stale_id_complete = MAD4B_SCP_Durable_Execution::complete_idempotency( $claim_a, array( 'stale' => true ) );
$check( 'mad4b_idempotency_complete_conflict' === $error_code( $stale_id_complete ), 'stale idempotency claimant completed reclaimed work' );

$result = array( 'provider_execution_ref' => 'exec-' . wp_generate_uuid4(), 'state' => 'verified' );
$id_complete = MAD4B_SCP_Durable_Execution::complete_idempotency( $claim_b, $result );
$check( is_array( $id_complete ) && ! empty( $id_complete['completed'] ), 'reclaimed idempotency claim could not complete' );

$replayed = MAD4B_SCP_Durable_Execution::begin_idempotency( $scope, $idempotency_key, $request_sha, 3600 );
$check( is_array( $replayed ) && ! empty( $replayed['replayed'] ) && $result === $replayed['result'], 'completed idempotency did not replay exact durable result' );

// ---- Outbox/inbox effect-once boundaries ----
$job_id = wp_generate_uuid4();
$provider_id = 'ci-provider';
$outbox_record = array(
	'job_id' => $job_id,
	'expected_job_revision' => 1,
	'provider_id' => $provider_id,
	'capability_id' => 'workflow.execute',
	'workflow_plan_sha256' => hash( 'sha256', 'plan:' . $job_id ),
	'idempotency_key' => 'provider-idem-' . wp_generate_uuid4(),
	'request_sha256' => hash( 'sha256', 'provider-request:' . $job_id ),
	'payload' => array( 'safe' => true ),
);
$outbox_a = MAD4B_SCP_Durable_Execution::enqueue_outbox( $outbox_record );
$check( is_array( $outbox_a ) && ! empty( $outbox_a['outbox_id'] ), 'outbox enqueue failed' );
$outbox_dup = MAD4B_SCP_Durable_Execution::enqueue_outbox( $outbox_record );
$check( is_array( $outbox_dup ) && hash_equals( (string) $outbox_a['outbox_id'], (string) $outbox_dup['outbox_id'] ), 'exact outbox retry did not reuse durable record' );

$outbox_conflict_record = $outbox_record;
$outbox_conflict_record['request_sha256'] = hash( 'sha256', 'different-provider-request' );
$outbox_conflict = MAD4B_SCP_Durable_Execution::enqueue_outbox( $outbox_conflict_record );
$check( 'mad4b_outbox_idempotency_conflict' === $error_code( $outbox_conflict ), 'outbox accepted conflicting logical request under same idempotency key' );

$event_id = 'evt-' . wp_generate_uuid4();
$payload_sha = hash( 'sha256', 'event:' . $event_id );
$inbox_a = MAD4B_SCP_Durable_Execution::accept_inbox( $provider_id, $event_id, $job_id, $payload_sha, 'provider-exec-1' );
$check( is_array( $inbox_a ) && empty( $inbox_a['duplicate'] ), 'first provider event was not accepted' );
$inbox_dup = MAD4B_SCP_Durable_Execution::accept_inbox( $provider_id, $event_id, $job_id, $payload_sha, 'provider-exec-1' );
$check( is_array( $inbox_dup ) && ! empty( $inbox_dup['duplicate'] ), 'duplicate provider event was not deduplicated' );

$inbox_conflict = MAD4B_SCP_Durable_Execution::accept_inbox(
	$provider_id,
	$event_id,
	$job_id,
	hash( 'sha256', 'different-event-payload' ),
	'provider-exec-1'
);
$check( 'mad4b_inbox_event_conflict' === $error_code( $inbox_conflict ), 'duplicate provider event accepted conflicting payload' );

remove_filter( 'mad4b_scp_durable_reconciliation_verified', $reconcile_filter, PHP_INT_MAX );

echo "mad4b.durable-execution.runtime-faults.v1: PASS\n";
