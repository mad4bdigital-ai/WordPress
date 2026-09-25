<?php
define( 'ABSPATH', '/tmp/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['mad4b_perf_env'] = 'staging';
$GLOBALS['mad4b_perf_admin'] = true;
$GLOBALS['mad4b_perf_options'] = array();

class MAD4B_Perf_WPDB {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $termmeta = 'wp_termmeta';
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
function wp_get_environment_type() { return $GLOBALS['mad4b_perf_env']; }
function is_admin() { return ! empty( $GLOBALS['mad4b_perf_admin'] ); }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['mad4b_perf_options'] ) ? $GLOBALS['mad4b_perf_options'][$key] : $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['mad4b_perf_options'][$key] = $value; return true; }
function wp_register_ability() {}
function wp_has_ability() { return false; }

final class MAD4B_SCP_Site_Profile {
	public static function current_environment() { return $GLOBALS['mad4b_perf_env']; }
}
final class MAD4B_SCP_Policy { public static function can_read() { return true; } }
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

$applied = MAD4B_SCP_Admin_Query_Performance::maybe_ensure_staging_indexes();
mad4b_perf_assert( ! empty( $applied['ready'] ), 'Staging compound indexes were not applied' );
mad4b_perf_assert( 2 === count( $GLOBALS['wpdb']->queries ), 'exactly two bounded ALTER TABLE statements are expected' );
foreach ( $GLOBALS['wpdb']->queries as $sql ) {
	mad4b_perf_assert( 0 === strpos( $sql, 'ALTER TABLE ' ), 'only ALTER TABLE is allowed by the bounded performance bootstrap' );
	mad4b_perf_assert( false !== strpos( $sql, ' ADD INDEX ' ), 'performance bootstrap may only add indexes' );
	mad4b_perf_assert( false === stripos( $sql, 'DROP ' ), 'performance bootstrap must never drop schema objects' );
}
$first_count = count( $GLOBALS['wpdb']->queries );
$again = MAD4B_SCP_Admin_Query_Performance::maybe_ensure_staging_indexes();
mad4b_perf_assert( $first_count === count( $GLOBALS['wpdb']->queries ), 'successful index bootstrap must not repeat DDL on later admin requests' );
mad4b_perf_assert( 'already_applied' === $again['state'], 'successful index bootstrap should short-circuit by version marker' );

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

echo "mad4b.admin-query-performance.runtime.v2: PASS\n";
