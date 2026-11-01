<?php

define( 'ABSPATH', __DIR__ . '/' );

function add_action( $hook, $callback, $priority = 10 ) {}
function sanitize_key( $value ) {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value );
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-operator-doctor.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL operator-doctor-contract: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };

$healthy = MAD4B_SCP_Operator_Doctor::classify_snapshot( array(
	'contract' => 'mad4b.operator-doctor-snapshot.v1',
	'site_uuid' => '11111111-2222-4333-8444-555555555555',
	'observed_at' => '2026-09-25T00:00:00Z',
	'stuck_job_count' => 0,
	'expired_active_lease_count' => 0,
	'overdue_outbox_count' => 0,
	'stale_inbox_count' => 0,
	'dead_lettered_outbox_count' => 0,
	'orphan_job_count' => 0,
	'host_runner_uncertain_count' => 0,
	'host_runner_recovery_required_count' => 0,
	'host_runner_dead_lettered_count' => 0,
) );
$check( true === $healthy['healthy'], 'empty snapshot was not healthy' );
$check( 0 === $healthy['finding_count'], 'empty snapshot produced findings' );
$check( false === $healthy['doctor_executes_repairs'], 'Doctor became an implicit repair executor' );
$check( false === $healthy['mutation_performed'], 'Doctor reported mutation' );
$check( false === $healthy['authorizing'], 'Doctor became authorizing' );

$degraded = MAD4B_SCP_Operator_Doctor::classify_snapshot( array(
	'contract' => 'mad4b.operator-doctor-snapshot.v1',
	'site_uuid' => '11111111-2222-4333-8444-555555555555',
	'observed_at' => '2026-09-25T00:00:00Z',
	'stuck_job_count' => 3,
	'expired_active_lease_count' => 2,
	'overdue_outbox_count' => 4,
	'stale_inbox_count' => 1,
	'dead_lettered_outbox_count' => 5,
	'orphan_job_count' => 2,
	'host_runner_uncertain_count' => 1,
	'host_runner_recovery_required_count' => 1,
	'host_runner_dead_lettered_count' => 2,
) );
$check( false === $degraded['healthy'], 'degraded snapshot was reported healthy' );
$check( 9 === $degraded['finding_count'], 'degraded snapshot finding count mismatch' );
$check( 'critical' === $degraded['findings'][0]['severity'], 'critical finding did not sort first' );
$check( 'host-runner-uncertain-mutations' === $degraded['findings'][0]['finding_id'], 'uncertain mutation was not the highest-severity finding' );
$severity_order = array( 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4 );
$previous_rank = -1;
foreach ( $degraded['findings'] as $finding ) {
	$rank = isset( $severity_order[ $finding['severity'] ] ) ? $severity_order[ $finding['severity'] ] : 99;
	$check( $rank >= $previous_rank, 'findings were not monotonically severity ordered' );
	$previous_rank = $rank;
}

$ids = array_column( $degraded['findings'], 'finding_id' );
foreach ( array(
	'expired-active-leases',
	'stuck-content-jobs',
	'overdue-outbox',
	'stale-inbox',
	'dead-letter-growth',
	'orphan-content-jobs',
	'host-runner-uncertain-mutations',
	'host-runner-recovery-required',
	'host-runner-dead-letter',
) as $id ) {
	$check( in_array( $id, $ids, true ), 'missing Doctor finding: ' . $id );
}
foreach ( $degraded['findings'] as $finding ) {
	$plan = $finding['repair_plan'];
	$check( 'mad4b.repair-plan.v1' === $plan['contract'], 'repair plan contract mismatch' );
	$check( false === $plan['executable'], 'Doctor emitted executable repair plan' );
	$check( true === $plan['preview_only'], 'Doctor repair plan lost preview-only status' );
	$check( true === $plan['requires_fresh_readback'], 'Doctor repair plan omitted fresh readback' );
	$check( true === $plan['requires_current_policy'], 'Doctor repair plan omitted current policy' );
	$check( true === $plan['requires_authority_if_applied'], 'Doctor repair plan omitted future authority' );
	$check( false === $plan['arbitrary_shell'], 'Doctor repair plan exposed arbitrary shell' );
	$check( ! empty( $plan['semantic_operations'] ), 'Doctor repair plan has no semantic operation IDs' );
}

// Exact-scope Doctor output is not an alternative cross-tenant read API.
$identity = '11111111-2222-4333-8444-555555555555';
$scope = array( 'site_uuid' => $identity, 'brand_ref' => 'brand-one', 'tenant_ref' => 'tenant-one' );
$sample = array(
	array( 'job_id' => 'visible-own-job', 'brand_id' => 'brand-one', 'tenant_id' => 'tenant-one' ),
	array( 'job_id' => 'doctor-redaction-foreign-secret', 'outbox_id' => 'foreign-outbox', 'provider_event_id' => 'foreign-event', 'brand_id' => 'brand-one', 'tenant_id' => 'tenant-two' ),
	array( 'job_id' => 'legacy-job-secret', 'work_id' => 'legacy-lease', 'brand_id' => 'brand-one', 'tenant_id' => '' ),
);
$scoped = MAD4B_SCP_Operator_Doctor::scope_job_rows( $sample, $scope, $identity );
$check( 3 === count( $scoped ), 'Doctor lost site-wide incident counts' );
$check( 'visible-own-job' === $scoped[0]['job_id'] && false === $scoped[0]['cross_scope_details_redacted'], 'Doctor redacted authorized scope' );
foreach ( array( 1, 2 ) as $i ) {
	$row = $scoped[$i];
	$check( true === $row['cross_scope_details_redacted'] && false === $row['automatic_repair_allowed'], 'Doctor exposed foreign/legacy scope' );
	foreach ( array( 'job_id', 'work_id', 'outbox_id', 'provider_event_id', 'brand_id', 'tenant_id' ) as $key ) {
		$check( ! array_key_exists( $key, $row ), 'Doctor exposed forbidden identifier: ' . $key );
	}
}
$unresolved = MAD4B_SCP_Operator_Doctor::scope_job_rows( $sample, array(), $identity );
foreach ( $unresolved as $row ) {
	$check( 'current_scope_unresolved' === $row['scope_relation'] && true === $row['cross_scope_details_redacted'], 'Doctor must fail closed without a trusted scope' );
	$check( ! isset( $row['job_id'] ) && ! isset( $row['outbox_id'] ), 'Doctor leaked details when scope was unverified' );
}
$wrong_site = MAD4B_SCP_Operator_Doctor::scope_job_rows( $sample, array( 'site_uuid' => '99999999-8888-4777-8666-555555555555', 'brand_ref' => 'brand-one', 'tenant_ref' => 'tenant-one' ), $identity );
$check( true === $wrong_site[0]['cross_scope_details_redacted'] && ! isset( $wrong_site[0]['job_id'] ), 'Doctor accepted a different site scope' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-operator-doctor.php' );
$main = file_get_contents( dirname( __DIR__ ) . '/mad4b-site-control-plane.php' );
$servers = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php' );
$core_catalog = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-server-core-catalog.php' );

foreach ( array(
	"'mad4b/operator-doctor'",
	"'mad4b/operator-dead-letter-status'",
	"'readonly' => true",
	"'destructive' => false",
	"'idempotent' => true",
	"'replay_available' => false",
	"'site_uuid_via_content_job_join_and_fixed_host_runner_evidence_root'",
	"o.status='dead_lettered'",
	"content_job_events",
	"MUTATED_BUT_EVIDENCE_UNCERTAIN",
	"RECOVERY_REQUIRED",
	"DEAD_LETTERED",
	"mad4b-runner",
	"journals",
	"blind_retry_allowed",
) as $marker ) {
	$check( false !== strpos( $source, $marker ), 'Doctor contract marker missing: ' . $marker );
}
$check( false !== strpos( $main, 'class-mad4b-scp-operator-doctor.php' ), 'Doctor runtime is not loaded' );
$check( false !== strpos( $servers, "'mad4b-read' === \$server_id && class_exists( 'MAD4B_SCP_Operator_Doctor' )" ), 'Doctor tools must be gated to the read plane' );
$check( false !== strpos( $servers, "wp_has_ability( \$doctor_ability )" ), 'Doctor tools must be registered before mounting' );
foreach ( array( 'mad4b/operator-doctor', 'mad4b/operator-dead-letter-status' ) as $ability ) {
	$check( false !== strpos( $core_catalog, "'" . $ability . "'" ), 'Doctor ability not declared in read-plane core catalog: ' . $ability );
}
foreach ( array(
	'$wpdb->insert',
	'$wpdb->update',
	'$wpdb->delete',
	'wp_insert_post(',
	'wp_update_post(',
	'update_option(',
	'delete_option(',
	'shell_exec(',
	'proc_open(',
	'passthru(',
	'system(',
) as $forbidden ) {
	$check( false === strpos( $source, $forbidden ), 'Doctor contains forbidden mutation primitive: ' . $forbidden );
}

echo "PASS: mad4b.operator-doctor.v1\n";
