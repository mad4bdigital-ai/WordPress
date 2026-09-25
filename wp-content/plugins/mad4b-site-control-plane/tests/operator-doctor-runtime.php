<?php
/**
 * Real MariaDB Operator Doctor / DLQ runtime regression.
 *
 * Executes under WP-CLI with plugins/themes skipped after schema v11 migration.
 * It seeds only disposable rows and local Host Runner journal fixtures, proves
 * site-scoped readback and non-authorizing diagnostics, then cleans up.
 */

$root = dirname( __DIR__ );
require_once $root . '/includes/class-mad4b-scp-schema.php';

if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
	final class MAD4B_SCP_Site_Profile {
		public static $uuid = '11111111-2222-4333-8444-555555555555';
		public static function site_uuid() { return self::$uuid; }
	}
}

require_once $root . '/includes/class-mad4b-scp-operator-doctor.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL operator-doctor-runtime: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) {
	if ( ! $condition ) $fail( $message );
};
$contains = static function ( array $rows, $field, $expected ) {
	foreach ( $rows as $row ) {
		if ( is_array( $row ) && isset( $row[ $field ] ) && (string) $row[ $field ] === (string) $expected ) return true;
	}
	return false;
};

$schema = MAD4B_SCP_Schema::status( true );
$check( ! empty( $schema['ready'] ), 'schema is not ready' );
$tables = MAD4B_SCP_Schema::tables();
global $wpdb;

$site_uuid = MAD4B_SCP_Site_Profile::$uuid;
$other_site_uuid = '99999999-8888-4777-8666-555555555555';
$now = gmdate( 'Y-m-d H:i:s' );
$old = gmdate( 'Y-m-d H:i:s', time() - 7200 );
$expired = gmdate( 'Y-m-d H:i:s', time() - 3600 );

$stuck_job = wp_generate_uuid4();
$orphan_job = wp_generate_uuid4();
$other_job = wp_generate_uuid4();
$work_id = wp_generate_uuid4();
$pending_outbox = wp_generate_uuid4();
$dead_outbox = wp_generate_uuid4();
$other_dead_outbox = wp_generate_uuid4();
$provider_event = 'evt-' . wp_generate_uuid4();

$insert_job = static function ( $job_id, $target_site, $state, $stage, $created_at, $updated_at ) use ( $wpdb, $tables, $check ) {
	$ok = $wpdb->insert(
		$tables['content_jobs'],
		array(
			'job_id' => $job_id,
			'site_uuid' => $target_site,
			'brand_id' => 'doctor-ci',
			'subject' => 'Operator Doctor runtime fixture',
			'language' => 'en',
			'country' => 'eg',
			'content_type' => 'article',
			'state' => $state,
			'stage' => $stage,
			'job_revision' => 1,
			'created_at' => $created_at,
			'updated_at' => $updated_at,
		)
	);
	$check( false !== $ok, 'unable to insert ContentJob fixture: ' . $wpdb->last_error );
};

$insert_job( $stuck_job, $site_uuid, 'RUNNING', 'WRITING', $old, $old );
$insert_job( $orphan_job, $site_uuid, 'NEW', 'INTAKE', $now, $now );
$insert_job( $other_job, $other_site_uuid, 'RUNNING', 'WRITING', $old, $old );

$event_ok = $wpdb->insert(
	$tables['content_job_events'],
	array(
		'event_id' => wp_generate_uuid4(),
		'job_id' => $stuck_job,
		'sequence' => 1,
		'event_type' => 'CREATED',
		'new_state' => 'RUNNING',
		'new_stage' => 'WRITING',
		'entry_sha256' => hash( 'sha256', 'doctor-event:' . $stuck_job ),
		'created_at' => $old,
	)
);
$check( false !== $event_ok, 'unable to insert ContentJob event fixture: ' . $wpdb->last_error );

$other_event_ok = $wpdb->insert(
	$tables['content_job_events'],
	array(
		'event_id' => wp_generate_uuid4(),
		'job_id' => $other_job,
		'sequence' => 1,
		'event_type' => 'CREATED',
		'new_state' => 'RUNNING',
		'new_stage' => 'WRITING',
		'entry_sha256' => hash( 'sha256', 'doctor-other-event:' . $other_job ),
		'created_at' => $old,
	)
);
$check( false !== $other_event_ok, 'unable to insert cross-site event fixture: ' . $wpdb->last_error );

$lease_ok = $wpdb->insert(
	$tables['work_leases'],
	array(
		'work_id' => $work_id,
		'aggregate_type' => 'content_job',
		'aggregate_id' => $stuck_job,
		'worker_id' => 'doctor-ci-worker',
		'lease_epoch' => 1,
		'expected_aggregate_revision' => 1,
		'status' => 'active',
		'acquired_at' => $old,
		'heartbeat_at' => $expired,
		'expires_at' => $expired,
		'created_at' => $old,
		'updated_at' => $old,
	)
);
$check( false !== $lease_ok, 'unable to insert expired lease fixture: ' . $wpdb->last_error );

$insert_outbox = static function ( $outbox_id, $job_id, $status, $available_at, $suffix ) use ( $wpdb, $tables, $check, $old ) {
	$ok = $wpdb->insert(
		$tables['outbox'],
		array(
			'outbox_id' => $outbox_id,
			'job_id' => $job_id,
			'expected_job_revision' => 1,
			'provider_id' => 'doctor-provider',
			'capability_id' => 'doctor.capability',
			'workflow_plan_sha256' => hash( 'sha256', 'doctor-plan:' . $suffix ),
			'idempotency_key' => 'doctor-idem-' . $suffix,
			'request_sha256' => hash( 'sha256', 'doctor-request:' . $suffix ),
			'status' => $status,
			'attempts' => 'dead_lettered' === $status ? 5 : 1,
			'last_error_class' => 'dead_lettered' === $status ? 'provider_timeout' : '',
			'available_at' => $available_at,
			'created_at' => $old,
			'updated_at' => $old,
		)
	);
	$check( false !== $ok, 'unable to insert outbox fixture: ' . $wpdb->last_error );
};

$insert_outbox( $pending_outbox, $stuck_job, 'pending', $old, 'pending-' . $stuck_job );
$insert_outbox( $dead_outbox, $stuck_job, 'dead_lettered', $old, 'dead-' . $stuck_job );
$insert_outbox( $other_dead_outbox, $other_job, 'dead_lettered', $old, 'other-' . $other_job );

$inbox_ok = $wpdb->insert(
	$tables['inbox'],
	array(
		'provider_id' => 'doctor-provider',
		'provider_event_id' => $provider_event,
		'job_id' => $stuck_job,
		'payload_sha256' => hash( 'sha256', 'doctor-inbox:' . $provider_event ),
		'status' => 'accepted',
		'provider_execution_ref' => 'doctor-provider-ref',
		'received_at' => $old,
		'processed_at' => null,
	)
);
$check( false !== $inbox_ok, 'unable to insert stale inbox fixture: ' . $wpdb->last_error );

$journal_root = WP_CONTENT_DIR . '/mad4b-runner/journals';
$check( wp_mkdir_p( $journal_root ), 'unable to create disposable Host Runner journal root' );

$journal_jobs = array(
	'MUTATED_BUT_EVIDENCE_UNCERTAIN' => wp_generate_uuid4(),
	'RECOVERY_REQUIRED' => wp_generate_uuid4(),
	'DEAD_LETTERED' => wp_generate_uuid4(),
);
$journal_paths = array();
foreach ( $journal_jobs as $state => $job_id ) {
	$row = array(
		'contract' => 'mad4b.host-runner-mutation-journal.v1',
		'job_id' => $job_id,
		'operation_id' => 'workspace.file.replace',
		'state' => $state,
		'plan_sha256' => hash( 'sha256', 'doctor-journal:' . $state . ':' . $job_id ),
		'terminal' => true,
		'blind_retry_allowed' => false,
		'completed_at' => gmdate( 'c' ),
	);
	$path = $journal_root . '/' . $job_id . '.json';
	$bytes = file_put_contents( $path, wp_json_encode( $row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	$check( false !== $bytes && $bytes > 1, 'unable to write Host Runner journal fixture' );
	$journal_paths[] = $path;
}

$fixture_before = array(
	'jobs' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['content_jobs']} WHERE job_id IN (%s,%s,%s)", $stuck_job, $orphan_job, $other_job ) ),
	'leases' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['work_leases']} WHERE work_id=%s", $work_id ) ),
	'outbox' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['outbox']} WHERE outbox_id IN (%s,%s,%s)", $pending_outbox, $dead_outbox, $other_dead_outbox ) ),
	'inbox' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['inbox']} WHERE provider_event_id=%s", $provider_event ) ),
);

$doctor = MAD4B_SCP_Operator_Doctor::doctor( array( 'stale_seconds' => 60, 'limit' => 100 ) );
$check( is_array( $doctor ), 'Doctor did not return an array' );
$check( false === $doctor['healthy'], 'Doctor reported seeded degraded runtime as healthy' );
$check( false === $doctor['doctor_executes_repairs'], 'Doctor became an implicit repair executor' );
$check( false === $doctor['mutation_performed'], 'Doctor reported mutation' );
$check( false === $doctor['authorizing'], 'Doctor became authorizing' );

$snapshot = $doctor['snapshot'];
$check( $contains( $snapshot['stuck_jobs'], 'job_id', $stuck_job ), 'stuck ContentJob fixture was not detected' );
$check( ! $contains( $snapshot['stuck_jobs'], 'job_id', $other_job ), 'cross-site stuck ContentJob leaked into Doctor' );
$check( $contains( $snapshot['orphan_jobs'], 'job_id', $orphan_job ), 'orphan ContentJob fixture was not detected' );
$check( ! $contains( $snapshot['orphan_jobs'], 'job_id', $other_job ), 'cross-site orphan ContentJob leaked into Doctor' );
$check( $contains( $snapshot['expired_active_leases'], 'work_id', $work_id ), 'expired active lease fixture was not detected' );
$check( $contains( $snapshot['overdue_outbox'], 'outbox_id', $pending_outbox ), 'overdue outbox fixture was not detected' );
$check( $contains( $snapshot['dead_lettered_outbox'], 'outbox_id', $dead_outbox ), 'provider DLQ fixture was not detected' );
$check( ! $contains( $snapshot['dead_lettered_outbox'], 'outbox_id', $other_dead_outbox ), 'cross-site provider DLQ leaked into Doctor' );
$check( $contains( $snapshot['stale_inbox'], 'provider_event_id', $provider_event ), 'stale inbox fixture was not detected' );
$check( (int) $snapshot['host_runner_uncertain_count'] >= 1, 'Host Runner uncertain mutation was not detected' );
$check( (int) $snapshot['host_runner_recovery_required_count'] >= 1, 'Host Runner recovery-required state was not detected' );
$check( (int) $snapshot['host_runner_dead_lettered_count'] >= 1, 'Host Runner dead-letter state was not detected' );

$finding_ids = array_column( $doctor['findings'], 'finding_id' );
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
) as $finding_id ) {
	$check( in_array( $finding_id, $finding_ids, true ), 'Doctor finding missing: ' . $finding_id );
}

$dlq = MAD4B_SCP_Operator_Doctor::dead_letter_status( array( 'limit' => 100 ) );
$check( is_array( $dlq ), 'DLQ status did not return an array' );
$check( false === $dlq['replay_available'], 'DLQ unexpectedly enabled replay' );
$check( true === $dlq['original_items_preserved'], 'DLQ did not preserve original items' );
$check( false === $dlq['mutation_performed'], 'DLQ status reported mutation' );
$check( false === $dlq['authorizing'], 'DLQ status became authorizing' );
$check( $contains( $dlq['items']['provider_outbox'], 'outbox_id', $dead_outbox ), 'provider DLQ item missing from DLQ status' );
$check( ! $contains( $dlq['items']['provider_outbox'], 'outbox_id', $other_dead_outbox ), 'cross-site provider DLQ leaked into DLQ status' );
$check( $contains( $dlq['items']['host_runner'], 'job_id', $journal_jobs['DEAD_LETTERED'] ), 'Host Runner DLQ item missing from DLQ status' );

$fixture_after = array(
	'jobs' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['content_jobs']} WHERE job_id IN (%s,%s,%s)", $stuck_job, $orphan_job, $other_job ) ),
	'leases' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['work_leases']} WHERE work_id=%s", $work_id ) ),
	'outbox' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['outbox']} WHERE outbox_id IN (%s,%s,%s)", $pending_outbox, $dead_outbox, $other_dead_outbox ) ),
	'inbox' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['inbox']} WHERE provider_event_id=%s", $provider_event ) ),
);
$check( $fixture_before === $fixture_after, 'Doctor/DLQ readback mutated durable fixture rows' );

foreach ( $journal_paths as $path ) {
	if ( is_file( $path ) ) unlink( $path );
}
$wpdb->delete( $tables['inbox'], array( 'provider_event_id' => $provider_event ), array( '%s' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['outbox']} WHERE outbox_id IN (%s,%s,%s)", $pending_outbox, $dead_outbox, $other_dead_outbox ) );
$wpdb->delete( $tables['work_leases'], array( 'work_id' => $work_id ), array( '%s' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['content_job_events']} WHERE job_id IN (%s,%s,%s)", $stuck_job, $orphan_job, $other_job ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['content_jobs']} WHERE job_id IN (%s,%s,%s)", $stuck_job, $orphan_job, $other_job ) );

echo "mad4b.operator-doctor.runtime.v1: PASS\n";
