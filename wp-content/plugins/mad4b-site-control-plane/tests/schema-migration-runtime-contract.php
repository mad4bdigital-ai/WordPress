<?php
/**
 * Runtime acceptance for the declared Feature 007 Schema v11 migration.
 *
 * This harness executes the real MAD4B_SCP_Schema migration service against a
 * bounded WordPress-like DB/options fixture. It verifies migration lifecycle
 * semantics without touching a real WordPress database.
 */

define( 'ABSPATH', '/tmp/mad4b-schema-migration-runtime/' );
if ( ! defined( 'ARRAY_A' ) ) define( 'ARRAY_A', 'ARRAY_A' );

@mkdir( ABSPATH . 'wp-admin/includes', 0775, true );
if ( ! is_file( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
	file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', "<?php\n" );
}

$GLOBALS['mad4b_schema_options'] = array();
$GLOBALS['mad4b_schema_dbdelta_calls'] = 0;
$GLOBALS['mad4b_schema_fail_option'] = '';
$GLOBALS['mad4b_schema_fail_final_receipt'] = false;

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = null ) {
		$this->code = (string) $code;
		$this->message = (string) $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['mad4b_schema_options'] )
		? $GLOBALS['mad4b_schema_options'][ $name ]
		: $default;
}
function update_option( $name, $value, $autoload = null ) {
	if ( (string) $name === (string) $GLOBALS['mad4b_schema_fail_option'] ) return false;
	if ( MAD4B_SCP_Schema::MIGRATION_RECEIPT_OPTION === (string) $name
		&& ! empty( $GLOBALS['mad4b_schema_fail_final_receipt'] )
		&& is_array( $value )
		&& ! empty( $value['readiness_finalized'] ) ) {
		return false;
	}
	$GLOBALS['mad4b_schema_options'][ $name ] = $value;
	return true;
}
function dbDelta( $statement ) {
	$GLOBALS['mad4b_schema_dbdelta_calls']++;
	return array();
}

final class MAD4B_Test_Schema_WPDB {
	public $prefix = 'wp_';
	public $missing_table = '';

	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
	public function prepare( $query, ...$args ) {
		if ( empty( $args ) ) return $query;
		$value = str_replace( "'", "''", (string) $args[0] );
		return preg_replace( '/%s/', "'" . $value . "'", $query, 1 );
	}
	public function get_var( $query ) {
		if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", (string) $query, $m ) ) {
			return $this->missing_table === $m[1] ? null : $m[1];
		}
		return null;
	}
	public function get_col( $query, $column = 0 ) {
		return array(
			'candidate_binding_contract', 'candidate_sha', 'build_fingerprint', 'binding_environment',
			'binding_host', 'site_uuid', 'site_profile_revision', 'site_profile_digest', 'bound_at',
			'job_id', 'state', 'stage', 'current_artifact_id', 'job_revision', 'updated_at',
			'event_id', 'sequence', 'event_type', 'plan_sha256', 'artifact_id', 'entry_sha256', 'created_at',
			'work_id', 'aggregate_type', 'aggregate_id', 'worker_id', 'lease_epoch',
			'expected_aggregate_revision', 'status', 'heartbeat_at', 'expires_at', 'reconciliation_ref',
			'scope_key', 'idempotency_key', 'request_sha256', 'claim_epoch', 'result_sha256',
			'outbox_id', 'expected_job_revision', 'provider_id', 'capability_id',
			'workflow_plan_sha256', 'attempts', 'available_at',
			'provider_event_id', 'payload_sha256', 'provider_execution_ref', 'result_ref', 'received_at',
			'artifact_id', 'artifact_type', 'version', 'content_sha256', 'payload_json',
			'producer_stage', 'supersedes_artifact_id',
			'edge_id', 'from_artifact_id', 'to_artifact_id', 'relation', 'invalidated', 'reason_code',
		);
	}
	public function get_results( $query, $output = null ) {
		$rows = array();
		foreach ( array(
			'job_id', 'event_id', 'job_sequence', 'work_id', 'scope_idempotency',
			'outbox_id', 'provider_idempotency', 'provider_event',
			'artifact_id', 'job_type_version', 'edge_id', 'artifact_relation',
		) as $name ) {
			$rows[] = array( 'Key_name' => $name, 'Non_unique' => 0 );
		}
		return $rows;
	}
	public function query( $query ) { return true; }
}
$GLOBALS['wpdb'] = new MAD4B_Test_Schema_WPDB();

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-schema.php';

function mad4b_schema_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL schema-migration-runtime: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}
function mad4b_schema_reset_cache() {
	$reflection = new ReflectionClass( 'MAD4B_SCP_Schema' );
	foreach ( array( 'critical_ready_cache', 'physical_status_cache' ) as $name ) {
		$property = $reflection->getProperty( $name );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}
}
function mad4b_schema_reset_fixture( $version ) {
	$GLOBALS['mad4b_schema_options'] = array( MAD4B_SCP_Schema::OPTION => (int) $version );
	$GLOBALS['mad4b_schema_fail_option'] = '';
	$GLOBALS['mad4b_schema_fail_final_receipt'] = false;
	$GLOBALS['wpdb']->missing_table = '';
	mad4b_schema_reset_cache();
}
function mad4b_schema_receipt() {
	return get_option( MAD4B_SCP_Schema::MIGRATION_RECEIPT_OPTION, array() );
}
function mad4b_schema_assert_receipt( $from, $run_type ) {
	$receipt = mad4b_schema_receipt();
	mad4b_schema_assert( is_array( $receipt ), 'Migration receipt must be an array.', $receipt );
	mad4b_schema_assert( 'mad4b.schema-migration-receipt.v1' === ( $receipt['contract'] ?? '' ), 'Receipt contract mismatch.', $receipt );
	mad4b_schema_assert( MAD4B_SCP_Schema::MIGRATION_ID === ( $receipt['migration_id'] ?? '' ), 'Receipt migration identity mismatch.', $receipt );
	mad4b_schema_assert( (int) $from === (int) ( $receipt['from_version'] ?? -1 ), 'Receipt must preserve original from_version.', $receipt );
	mad4b_schema_assert( MAD4B_SCP_Schema::VERSION === (int) ( $receipt['to_version'] ?? 0 ), 'Receipt target version mismatch.', $receipt );
	mad4b_schema_assert( $run_type === ( $receipt['run_type'] ?? '' ), 'Receipt run type mismatch.', $receipt );
	mad4b_schema_assert( ! empty( $receipt['physical_verified'] ) && ! empty( $receipt['readiness_finalized'] ), 'Receipt must be physically verified and finalized.', $receipt );
	mad4b_schema_assert( empty( $receipt['destructive'] ) && empty( $receipt['authority_widened'] ), 'Migration receipt must remain additive/non-authorizing.', $receipt );
	mad4b_schema_assert( 64 === strlen( (string) ( $receipt['contract_sha256'] ?? '' ) ), 'Receipt contract digest missing.', $receipt );
	mad4b_schema_assert( 64 === strlen( (string) ( $receipt['target_integrity_token'] ?? '' ) ), 'Receipt target integrity token missing.', $receipt );
	mad4b_schema_assert( 64 === strlen( (string) ( $receipt['physical_integrity_sha256'] ?? '' ) ), 'Receipt physical-integrity digest missing.', $receipt );
}

// v6 -> v10 exact additive upgrade.
mad4b_schema_reset_fixture( 6 );
$before_calls = $GLOBALS['mad4b_schema_dbdelta_calls'];
$upgrade = MAD4B_SCP_Schema::install_or_upgrade();
mad4b_schema_assert( true === $upgrade, 'Supported v6 upgrade must succeed.', $upgrade );
mad4b_schema_assert( MAD4B_SCP_Schema::VERSION === (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ), 'Schema version must finalize to v10.' );
mad4b_schema_assert( MAD4B_SCP_Schema::is_ready(), 'Schema must be ready only after exact receipt/token persistence.' );
mad4b_schema_assert_receipt( 6, 'upgrade' );
mad4b_schema_assert( $GLOBALS['mad4b_schema_dbdelta_calls'] > $before_calls, 'Initial upgrade must execute dbDelta.' );

// Healthy repeated migration is a no-op and does not repeat DDL.
$healthy_calls = $GLOBALS['mad4b_schema_dbdelta_calls'];
$repeat = MAD4B_SCP_Schema::install_or_upgrade();
mad4b_schema_assert( true === $repeat, 'Healthy repeated migration must succeed.' );
mad4b_schema_assert( $healthy_calls === $GLOBALS['mad4b_schema_dbdelta_calls'], 'Healthy finalized v10 must not repeat dbDelta.' );

// A partial final-receipt failure must keep readiness false and preserve v6 origin on retry.
mad4b_schema_reset_fixture( 6 );
$GLOBALS['mad4b_schema_fail_final_receipt'] = true;
$partial = MAD4B_SCP_Schema::install_or_upgrade();
mad4b_schema_assert( is_wp_error( $partial ) && 'mad4b_schema_migration_final_receipt_failed' === $partial->get_error_code(), 'Final-receipt persistence failure must fail closed.', $partial );
mad4b_schema_assert( ! MAD4B_SCP_Schema::is_ready(), 'Schema cannot be ready with a non-finalized receipt.' );
$partial_receipt = mad4b_schema_receipt();
mad4b_schema_assert( 6 === (int) ( $partial_receipt['from_version'] ?? -1 ) && empty( $partial_receipt['readiness_finalized'] ), 'Partial receipt must preserve original upgrade origin.', $partial_receipt );
$GLOBALS['mad4b_schema_fail_final_receipt'] = false;
mad4b_schema_reset_cache();
$retry = MAD4B_SCP_Schema::install_or_upgrade();
mad4b_schema_assert( true === $retry, 'Retry after final-receipt failure must converge idempotently.', $retry );
mad4b_schema_assert_receipt( 6, 'upgrade' );

// Physical verification failure must not advance readiness markers.
mad4b_schema_reset_fixture( 6 );
$GLOBALS['wpdb']->missing_table = 'wp_mad4b_execution_inbox';
$physical_fail = MAD4B_SCP_Schema::install_or_upgrade();
mad4b_schema_assert( is_wp_error( $physical_fail ) && 'mad4b_governance_schema_unavailable' === $physical_fail->get_error_code(), 'Missing durable table must fail physical verification.', $physical_fail );
$physical_fail_data = $physical_fail->get_error_data();
mad4b_schema_assert( is_array( $physical_fail_data ), 'Physical failure must expose bounded migration diagnostics.', $physical_fail_data );
mad4b_schema_assert( 6 === (int) ( $physical_fail_data['from_version'] ?? -1 ) && 10 === (int) ( $physical_fail_data['target_version'] ?? 0 ), 'Physical failure diagnostics must preserve migration origin and target.', $physical_fail_data );
mad4b_schema_assert( isset( $physical_fail_data['physical_integrity'] ) && is_array( $physical_fail_data['physical_integrity'] ), 'Physical failure diagnostics must include deep integrity status.', $physical_fail_data );
mad4b_schema_assert( in_array( 'inbox', $physical_fail_data['physical_integrity']['missing_tables'] ?? array(), true ), 'Physical failure diagnostics must identify the missing durable table.', $physical_fail_data );
mad4b_schema_assert( isset( $physical_fail_data['dbdelta_diagnostics'] ) && is_array( $physical_fail_data['dbdelta_diagnostics'] ) && count( $physical_fail_data['dbdelta_diagnostics'] ) >= 15, 'Physical failure diagnostics must preserve per-table dbDelta evidence.', $physical_fail_data );
mad4b_schema_assert( 6 === (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ), 'Physical failure must not advance schema version.' );
mad4b_schema_assert( '' === (string) get_option( MAD4B_SCP_Schema::INTEGRITY_OPTION, '' ), 'Physical failure must not persist integrity readiness.' );
mad4b_schema_assert( array() === mad4b_schema_receipt(), 'Physical failure must not issue a migration receipt.' );

// First receipt persistence failure must not advance version/token.
mad4b_schema_reset_fixture( 6 );
$GLOBALS['mad4b_schema_fail_option'] = MAD4B_SCP_Schema::MIGRATION_RECEIPT_OPTION;
$receipt_fail = MAD4B_SCP_Schema::install_or_upgrade();
mad4b_schema_assert( is_wp_error( $receipt_fail ) && 'mad4b_schema_migration_receipt_persist_failed' === $receipt_fail->get_error_code(), 'Physical receipt persistence failure must fail closed.', $receipt_fail );
mad4b_schema_assert( 6 === (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ), 'Receipt failure must not advance schema version.' );
mad4b_schema_assert( '' === (string) get_option( MAD4B_SCP_Schema::INTEGRITY_OPTION, '' ), 'Receipt failure must not advance integrity marker.' );

// Readiness-marker failure can partially persist version but retry must recover from receipt origin.
mad4b_schema_reset_fixture( 6 );
$GLOBALS['mad4b_schema_fail_option'] = MAD4B_SCP_Schema::INTEGRITY_OPTION;
$marker_fail = MAD4B_SCP_Schema::install_or_upgrade();
mad4b_schema_assert( is_wp_error( $marker_fail ) && 'mad4b_schema_migration_readiness_persist_failed' === $marker_fail->get_error_code(), 'Readiness marker persistence failure must fail closed.', $marker_fail );
mad4b_schema_assert( ! MAD4B_SCP_Schema::is_ready(), 'Partial readiness-marker persistence must not become ready.' );
$GLOBALS['mad4b_schema_fail_option'] = '';
mad4b_schema_reset_cache();
$marker_retry = MAD4B_SCP_Schema::install_or_upgrade();
mad4b_schema_assert( true === $marker_retry, 'Readiness-marker retry must converge.', $marker_retry );
mad4b_schema_assert_receipt( 6, 'upgrade' );

// Fresh install has a distinct origin/run type.
mad4b_schema_reset_fixture( 0 );
$fresh = MAD4B_SCP_Schema::install_or_upgrade();
mad4b_schema_assert( true === $fresh, 'Fresh v0 -> v10 installation must succeed.', $fresh );
mad4b_schema_assert_receipt( 0, 'fresh_install' );

// Future schema identity must never be downgraded by v10 code.
mad4b_schema_reset_fixture( 11 );
$future_calls = $GLOBALS['mad4b_schema_dbdelta_calls'];
$future = MAD4B_SCP_Schema::install_or_upgrade();
mad4b_schema_assert( is_wp_error( $future ) && 'mad4b_schema_migration_preflight_failed' === $future->get_error_code(), 'Future schema must fail preflight.', $future );
mad4b_schema_assert( $future_calls === $GLOBALS['mad4b_schema_dbdelta_calls'], 'Future-schema rejection must occur before dbDelta.' );
$future_data = $future->get_error_data();
mad4b_schema_assert( is_array( $future_data ) && in_array( 'future_schema_downgrade_forbidden', $future_data['blockers'] ?? array(), true ), 'Future-schema blocker must be explicit.', $future_data );

echo "mad4b.site-control-plane.schema-migration.runtime.v1: PASS\n";
