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
) );
$check( false === $degraded['healthy'], 'degraded snapshot was reported healthy' );
$check( 5 === $degraded['finding_count'], 'degraded snapshot finding count mismatch' );
$check( 'high' === $degraded['findings'][0]['severity'], 'findings were not severity ordered' );

$ids = array_column( $degraded['findings'], 'finding_id' );
foreach ( array( 'expired-active-leases', 'stuck-content-jobs', 'overdue-outbox', 'stale-inbox', 'dead-letter-growth' ) as $id ) {
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

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-operator-doctor.php' );
$main = file_get_contents( dirname( __DIR__ ) . '/mad4b-site-control-plane.php' );
$servers = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php' );

foreach ( array(
	"'mad4b/operator-doctor'",
	"'mad4b/operator-dead-letter-status'",
	"'readonly' => true",
	"'destructive' => false",
	"'idempotent' => true",
	"'replay_available' => false",
	"'site_uuid_via_content_job_join'",
	"o.status='dead_lettered'",
) as $marker ) {
	$check( false !== strpos( $source, $marker ), 'Doctor contract marker missing: ' . $marker );
}
$check( false !== strpos( $main, 'class-mad4b-scp-operator-doctor.php' ), 'Doctor runtime is not loaded' );
foreach ( array( 'mad4b/operator-doctor', 'mad4b/operator-dead-letter-status' ) as $ability ) {
	$check( false !== strpos( $servers, "'" . $ability . "'" ), 'Doctor ability not mounted on read plane: ' . $ability );
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

echo "mad4b.operator-doctor.v1: PASS\n";
