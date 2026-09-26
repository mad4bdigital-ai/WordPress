<?php
/**
 * Real MariaDB ContentJob aggregate lifecycle regression.
 *
 * Runs under WP-CLI with plugins skipped and supplies only bounded Site Profile
 * and Audit stubs so the domain service itself is exercised against schema v9.
 */

$root = dirname( __DIR__ );
require_once $root . '/includes/class-mad4b-scp-schema.php';

if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
	final class MAD4B_SCP_Site_Profile {
		public static $uuid = '11111111-2222-4333-8444-555555555555';
		public static function site_uuid() { return self::$uuid; }
	}
}
if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) {
	final class MAD4B_SCP_Audit {
		public static $events = array();
		public static $commits = 0;
		public static $rollbacks = 0;
		public static function record( $ability, $summary, $status, $mutation ) {
			self::$events[] = compact( 'ability', 'summary', 'status', 'mutation' );
			return array( 'recorded' => true );
		}
		public static function transaction_committed() { ++self::$commits; }
		public static function transaction_rolled_back() { ++self::$rollbacks; }
	}
}

require_once $root . '/includes/class-mad4b-scp-content-jobs.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL content-job-domain-runtime: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) {
	if ( ! $condition ) $fail( $message );
};
$error_code = static function ( $value ) {
	return is_wp_error( $value ) ? $value->get_error_code() : '';
};

$schema = MAD4B_SCP_Schema::status( true );
$check( ! empty( $schema['ready'] ), 'schema is not ready' );

$create = MAD4B_SCP_Content_Jobs::create_job( array(
	'brand_id' => 'brand-ci',
	'subject' => 'Durable ContentJob runtime aggregate fixture',
	'primary_keyword' => 'content intelligence',
	'language' => 'en',
	'country' => 'eg',
	'content_type' => 'article',
	'writer_profile_id' => '',
	'writer_profile_version' => '',
	'research_depth' => 'standard',
	'automation_level' => 'review_gated',
	'target_post_type' => 'post',
	'desired_publish_at' => '',
	'reason' => 'create runtime aggregate fixture',
) );
$check( is_array( $create ) && isset( $create['job'] ), 'create_job failed' );
$job = $create['job'];
$check( 'NEW' === $job['state'] && 'INTAKE' === $job['stage'], 'new job state/stage mismatch' );
$check( 1 === (int) $job['job_revision'], 'new job revision is not 1' );
$job_id = (string) $job['job_id'];
$check( 1 === preg_match( '/^[a-f0-9-]{36}$/', $job_id ), 'job id is not canonical UUID' );

$events = MAD4B_SCP_Content_Jobs::get_events( array( 'job_id' => $job_id, 'limit' => 100 ) );
$check( is_array( $events ) && 1 === (int) $events['count'], 'create did not append exactly one event' );
$first = $events['items'][0];
$check( 1 === (int) $first['sequence'], 'first event sequence mismatch' );
$check( '' === (string) $first['previous_entry_sha256'], 'first event unexpectedly has previous hash' );
$check( 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $first['entry_sha256'] ), 'first event hash invalid' );

$queued = MAD4B_SCP_Content_Jobs::transition_job( array(
	'job_id' => $job_id,
	'expected_revision' => 1,
	'state' => 'QUEUED',
	'stage' => 'INTAKE',
	'reason' => 'queue durable runtime fixture',
	'plan_sha256' => hash( 'sha256', 'queue-plan:' . $job_id ),
	'artifact_id' => '',
) );
$check( is_array( $queued ) && 2 === (int) $queued['job']['job_revision'], 'NEW→QUEUED transition failed' );

$stale = MAD4B_SCP_Content_Jobs::transition_job( array(
	'job_id' => $job_id,
	'expected_revision' => 1,
	'state' => 'RUNNING',
	'stage' => 'SITE_DISCOVERY',
	'reason' => 'stale writer must be rejected',
) );
$check( 'mad4b_content_job_revision_conflict' === $error_code( $stale ), 'stale revision was not rejected' );

$illegal = MAD4B_SCP_Content_Jobs::transition_job( array(
	'job_id' => $job_id,
	'expected_revision' => 2,
	'state' => 'COMPLETED',
	'stage' => 'FINAL_QA',
	'reason' => 'illegal lifecycle jump must fail',
) );
$check( 'mad4b_content_job_state_transition_denied' === $error_code( $illegal ), 'illegal QUEUED→COMPLETED jump was accepted' );

$running = MAD4B_SCP_Content_Jobs::transition_job( array(
	'job_id' => $job_id,
	'expected_revision' => 2,
	'state' => 'RUNNING',
	'stage' => 'SITE_DISCOVERY',
	'reason' => 'start site discovery',
) );
$check(
	is_array( $running )
		&& 3 === (int) $running['job']['job_revision']
		&& 'RUNNING' === $running['job']['state']
		&& 'SITE_DISCOVERY' === $running['job']['stage'],
	'QUEUED→RUNNING transition failed'
);

$stage_advance = MAD4B_SCP_Content_Jobs::transition_job( array(
	'job_id' => $job_id,
	'expected_revision' => 3,
	'state' => 'RUNNING',
	'stage' => 'KNOWLEDGE_DISPATCH',
	'reason' => 'advance stage without changing lifecycle state',
	'artifact_id' => 'artifact-context-pack-1',
) );
$check(
	is_array( $stage_advance )
		&& 4 === (int) $stage_advance['job']['job_revision']
		&& 'artifact-context-pack-1' === $stage_advance['job']['current_artifact_id'],
	'stage-only transition or artifact binding failed'
);

$cancelled = MAD4B_SCP_Content_Jobs::cancel_job( array(
	'job_id' => $job_id,
	'expected_revision' => 4,
	'reason' => 'cancel disposable runtime fixture',
) );
$check(
	is_array( $cancelled )
		&& 5 === (int) $cancelled['job']['job_revision']
		&& 'CANCELLED' === $cancelled['job']['state']
		&& ! empty( $cancelled['job']['cancelled_at'] ),
	'cancellation failed'
);

$terminal = MAD4B_SCP_Content_Jobs::transition_job( array(
	'job_id' => $job_id,
	'expected_revision' => 5,
	'state' => 'QUEUED',
	'stage' => 'INTAKE',
	'reason' => 'terminal history must remain immutable',
) );
$check( 'mad4b_content_job_terminal_immutable' === $error_code( $terminal ), 'cancelled job was resurrected' );

$events = MAD4B_SCP_Content_Jobs::get_events( array( 'job_id' => $job_id, 'limit' => 100 ) );
$check( 5 === (int) $events['count'], 'event count does not match successful revisions' );
$previous = '';
foreach ( $events['items'] as $index => $event ) {
	$expected_sequence = $index + 1;
	$check( $expected_sequence === (int) $event['sequence'], 'event sequence is not contiguous' );
	$check( hash_equals( $previous, (string) $event['previous_entry_sha256'] ), 'event hash chain is broken' );
	$entry = (string) $event['entry_sha256'];
	$check( 1 === preg_match( '/^[a-f0-9]{64}$/', $entry ), 'event entry hash invalid' );
	$previous = $entry;
}

// Site-bound lookup must fail closed when the same job id is queried under another site.
MAD4B_SCP_Site_Profile::$uuid = '99999999-8888-4777-8666-555555555555';
$cross_site = MAD4B_SCP_Content_Jobs::get_job( array( 'job_id' => $job_id ) );
$check( 'mad4b_content_job_missing' === $error_code( $cross_site ), 'cross-site ContentJob lookup leaked aggregate' );
MAD4B_SCP_Site_Profile::$uuid = '11111111-2222-4333-8444-555555555555';

$visible = MAD4B_SCP_Content_Jobs::list_jobs( array( 'state' => 'CANCELLED', 'stage' => '', 'limit' => 50 ) );
$check( is_array( $visible ) && 1 === (int) $visible['count'], 'site-bound job list did not return cancelled fixture' );
$check( MAD4B_SCP_Audit::$commits >= 5, 'successful aggregate mutations did not commit audit transactions' );
$check( MAD4B_SCP_Audit::$rollbacks >= 3, 'failed/stale/terminal transitions did not rollback transactions' );

echo "mad4b.content-job.runtime-domain.v1: PASS\n";
