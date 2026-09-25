<?php
define( 'ABSPATH', '/tmp/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['mad4b_perf_env'] = 'staging';
$GLOBALS['mad4b_perf_admin'] = true;
$GLOBALS['mad4b_perf_options'] = array();

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = (string) $code; $this->message = (string) $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class MAD4B_Perf_WPDB {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $termmeta = 'wp_termmeta';
	public $options = 'wp_options';
	public $last_error = '';
	public $queries = array();
	public $indexes = array();
	public $agent_query_count = 0;
	public $agent_row = array( 'id' => 7, 'public_id' => 'agent-public-1', 'slug' => 'chatgpt', 'label' => 'ChatGPT', 'status' => 'enabled', 'environment' => 'staging', 'revision' => 1 );

	public function __construct() {
		$this->indexes = array(
			'wp_postmeta' => array(
				array( 'Key_name' => 'PRIMARY', 'Column_name' => 'meta_id', 'Seq_in_index' => 1 ),
				array( 'Key_name' => 'post_id', 'Column_name' => 'post_id', 'Seq_in_index' => 1 ),
				array( 'Key_name' => 'meta_key', 'Column_name' => 'meta_key', 'Seq_in_index' => 1 ),
			),
			'wp_termmeta' => array(
				array( 'Key_name' => 'PRIMARY', 'Column_name' => 'meta_id', 'Seq_in_index' => 1 ),
				array( 'Key_name' => 'term_id', 'Column_name' => 'term_id', 'Seq_in_index' => 1 ),
				array( 'Key_name' => 'meta_key', 'Column_name' => 'meta_key', 'Seq_in_index' => 1 ),
			),
		);
	}
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$sql = preg_replace( '/%[ds]/', $replacement, $sql, 1 );
		}
		return $sql;
	}
	public function get_row( $sql, $output = null ) {
		if ( false !== strpos( $sql, 'FROM wp_mad4b_scp_agents' ) ) {
			$this->agent_query_count++;
			return false !== strpos( $sql, "'agent-public-1'" ) ? $this->agent_row : null;
		}
		return null;
	}
	public function get_results( $sql, $output = null ) {
		if ( preg_match( '/SHOW INDEX FROM .([^'.chr(96).']+)./', $sql, $m ) ) return isset( $this->indexes[$m[1]] ) ? $this->indexes[$m[1]] : array();
		return array();
	}
	public function delete( $table, $where, $formats = array() ) {
		if ( 'wp_options' !== $table || empty( $where['option_name'] ) ) return false;
		$key = (string) $where['option_name'];
		if ( ! array_key_exists( $key, $GLOBALS['mad4b_perf_options'] ) ) return 0;
		$expected = isset( $where['option_value'] ) ? (string) $where['option_value'] : '';
		if ( maybe_serialize( $GLOBALS['mad4b_perf_options'][ $key ] ) !== $expected ) return 0;
		unset( $GLOBALS['mad4b_perf_options'][ $key ] );
		return 1;
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( '/ALTER TABLE .([^'.chr(96).']+). ADD INDEX .([^'.chr(96).']+). \\(.([^'.chr(96).']+).,.([^'.chr(96).']+).\\(191\\)\\)/', $sql, $m ) ) {
			$this->indexes[$m[1]][] = array( 'Key_name' => $m[2], 'Column_name' => $m[3], 'Seq_in_index' => 1 );
			$this->indexes[$m[1]][] = array( 'Key_name' => $m[2], 'Column_name' => $m[4], 'Seq_in_index' => 2 );
			return 0;
		}
		$this->last_error = 'unexpected_sql';
		return false;
	}
}
$GLOBALS['wpdb'] = new MAD4B_Perf_WPDB();

function add_action() {}
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function wp_unslash( $v ) { return $v; }
function wp_get_environment_type() { return $GLOBALS['mad4b_perf_env']; }
function is_admin() { return ! empty( $GLOBALS['mad4b_perf_admin'] ); }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['mad4b_perf_options'] ) ? $GLOBALS['mad4b_perf_options'][$key] : $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['mad4b_perf_options'][$key] = $value; return true; }
function add_option( $key, $value, $deprecated = '', $autoload = false ) { if ( array_key_exists( $key, $GLOBALS['mad4b_perf_options'] ) ) return false; $GLOBALS['mad4b_perf_options'][$key] = $value; return true; }
function maybe_serialize( $value ) { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value; }
function wp_cache_delete() { return true; }
function wp_generate_uuid4() { static $i = 0; $i++; return sprintf( '00000000-0000-4000-8000-%012d', $i ); }
function wp_next_scheduled() { return false; }
function wp_schedule_single_event() { return true; }
function wp_register_ability() {}
function wp_has_ability() { return false; }

final class MAD4B_SCP_Site_Profile {
	public static function current_environment() { return $GLOBALS['mad4b_perf_env']; }
}
final class MAD4B_SCP_Policy { public static function can_read() { return true; } }
final class MAD4B_SCP_Live_Acceptance_Observer {
	public static function build_provenance_status() {
		return array(
			'manifest_valid' => true,
			'runtime_manifest_match' => true,
			'stale' => false,
			'source_commit_sha' => str_repeat( 'a', 40 ),
			'build_fingerprint' => str_repeat( 'b', 64 ),
			'package_manifest_digest' => str_repeat( 'c', 64 ),
		);
	}
}
final class MAD4B_SCP_Schema {
	public static function tables() { return array( 'agents' => 'wp_mad4b_scp_agents' ); }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-admin-query-performance.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-agent-registry.php';

function mad4b_perf_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$initial = MAD4B_SCP_Admin_Query_Performance::status();
mad4b_perf_assert( empty( $initial['ready'] ), 'standard single-column meta indexes must not masquerade as compound optimization' );

$GLOBALS['pagenow'] = 'update.php';
$_REQUEST['action'] = 'upload-plugin';
$protected = MAD4B_SCP_Admin_Query_Performance::maybe_ensure_staging_indexes();
mad4b_perf_assert( 'lifecycle_protected' === $protected['state'], 'plugin upload lifecycle must be protected from performance DDL' );
mad4b_perf_assert( 0 === count( $GLOBALS['wpdb']->queries ), 'plugin upload lifecycle must execute zero performance DDL statements' );
unset( $GLOBALS['pagenow'], $_REQUEST['action'] );

$queued_only = MAD4B_SCP_Admin_Query_Performance::maybe_ensure_staging_indexes();
mad4b_perf_assert( 'queue_required' === $queued_only['state'], 'ordinary Staging admin requests must never execute performance DDL directly' );
mad4b_perf_assert( 0 === count( $GLOBALS['wpdb']->queries ), 'queue-only public path must execute zero ALTER TABLE statements' );
$legacy_direct = MAD4B_SCP_Admin_Query_Performance::apply_explicit();
mad4b_perf_assert( is_wp_error( $legacy_direct ) && 'mad4b_admin_query_performance_queue_required' === $legacy_direct->get_error_code(), 'legacy public direct-apply path must fail closed to queued maintenance' );

$worker = new ReflectionMethod( 'MAD4B_SCP_Admin_Query_Performance', 'apply_indexes' );
$worker->setAccessible( true );
$applied = $worker->invoke( null );
mad4b_perf_assert( ! empty( $applied['ready'] ), 'scheduled-worker index implementation did not reach ready state' );
mad4b_perf_assert( 2 === count( $GLOBALS['wpdb']->queries ), 'exactly two bounded ALTER TABLE statements are expected inside the private worker implementation' );
foreach ( $GLOBALS['wpdb']->queries as $sql ) {
	mad4b_perf_assert( 0 === strpos( $sql, 'ALTER TABLE ' ), 'only ALTER TABLE is allowed by the bounded performance worker' );
	mad4b_perf_assert( false !== strpos( $sql, ' ADD INDEX ' ), 'performance worker may only add indexes' );
	mad4b_perf_assert( false === stripos( $sql, 'DROP ' ), 'performance worker must never drop schema objects' );
}
$first_count = count( $GLOBALS['wpdb']->queries );
$again = $worker->invoke( null );
mad4b_perf_assert( $first_count === count( $GLOBALS['wpdb']->queries ), 'successful private worker must not repeat DDL after readiness' );
mad4b_perf_assert( 'already_applied' === $again['state'], 'successful private worker should short-circuit by version marker' );

$expected_identity = array(
	'source_commit_sha' => str_repeat( 'a', 40 ),
	'build_fingerprint' => str_repeat( 'b', 64 ),
	'package_manifest_digest' => str_repeat( 'c', 64 ),
);
$GLOBALS['mad4b_perf_options']['mad4b_scp_admin_query_performance_job_v1'] = array(
	'contract' => 'mad4b.admin-query-performance-job.v1',
	'job_id' => '11111111-1111-4111-8111-111111111111',
	'status' => 'running',
	'expected_identity' => $expected_identity,
	'queued_at' => gmdate( 'c', time() - 4000 ),
	'started_at' => gmdate( 'c', time() - 3600 ),
	'completed_at' => '',
	'result' => array(),
	'production_changed' => false,
);
$reconciled_ready = MAD4B_SCP_Admin_Query_Performance::reconcile_stale_job( $expected_identity );
mad4b_perf_assert( is_array( $reconciled_ready ) && ! empty( $reconciled_ready['ready'] ), 'stale running job with verified ready indexes must reconcile to completed' );
mad4b_perf_assert( 'completed_after_reconciliation' === $reconciled_ready['state'], 'ready stale reconciliation returned unexpected state' );
mad4b_perf_assert( 2 === count( $GLOBALS['wpdb']->queries ), 'ready reconciliation must execute zero additional DDL' );

$GLOBALS['wpdb'] = new MAD4B_Perf_WPDB();
$GLOBALS['mad4b_perf_options']['mad4b_scp_admin_query_performance_job_v1'] = array(
	'contract' => 'mad4b.admin-query-performance-job.v1',
	'job_id' => '22222222-2222-4222-8222-222222222222',
	'status' => 'running',
	'expected_identity' => $expected_identity,
	'queued_at' => gmdate( 'c', time() - 4000 ),
	'started_at' => gmdate( 'c', time() - 3600 ),
	'completed_at' => '',
	'result' => array(),
	'production_changed' => false,
);
$reconciled_incomplete = MAD4B_SCP_Admin_Query_Performance::reconcile_stale_job( $expected_identity );
mad4b_perf_assert( is_array( $reconciled_incomplete ) && empty( $reconciled_incomplete['ready'] ), 'incomplete stale job must remain fail-closed' );
mad4b_perf_assert( 'verified_incomplete' === $reconciled_incomplete['state'], 'incomplete stale reconciliation returned unexpected state' );
mad4b_perf_assert( 'failed_verified_incomplete' === $reconciled_incomplete['job']['status'], 'incomplete stale reconciliation must create an explicit verified terminal state' );
mad4b_perf_assert( 0 === count( $GLOBALS['wpdb']->queries ), 'stale reconciliation must never retry uncertain DDL' );

$GLOBALS['mad4b_perf_env'] = 'production';
$GLOBALS['mad4b_perf_options'] = array();
$GLOBALS['wpdb'] = new MAD4B_Perf_WPDB();
$production = MAD4B_SCP_Admin_Query_Performance::maybe_ensure_staging_indexes();
mad4b_perf_assert( 0 === count( $GLOBALS['wpdb']->queries ), 'Production must never receive Staging performance DDL' );
mad4b_perf_assert( 'not_applicable' === $production['state'], 'Production index bootstrap must be not applicable' );
mad4b_perf_assert( empty( $production['production_changed'] ), 'Production unchanged truth must remain explicit' );

$GLOBALS['mad4b_perf_env'] = 'staging';
$GLOBALS['wpdb'] = new MAD4B_Perf_WPDB();
for ( $i = 0; $i < 2000; $i++ ) {
	$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( 'agent-public-1' );
	mad4b_perf_assert( is_array( $agent ) && 7 === (int) $agent['id'], 'request-local cached agent lookup returned an unexpected row' );
}
mad4b_perf_assert( 1 === $GLOBALS['wpdb']->agent_query_count, 'repeated request-local agent lookups must collapse to one database query' );

echo "mad4b.admin-query-performance.runtime.v4: PASS\n";
