<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CLI', true );

function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_get_environment_type() { return 'staging'; }

class WP_CLI {
	public static $commands = array();
	public static $lines = array();
	public static function add_command( $name, $callback ) { self::$commands[ $name ] = $callback; }
	public static function line( $line ) { self::$lines[] = $line; }
	public static function error( $message ) { throw new RuntimeException( $message ); }
}

final class MAD4B_SCP_Site_Profile {
	public static function status() { return array( 'configured' => true, 'environment' => 'staging' ); }
}
final class MAD4B_SCP_Schema {
	public static function status( $fresh = false ) { return array( 'ready' => true, 'fresh' => (bool) $fresh ); }
}
final class MAD4B_SCP_Live_Acceptance_Observer {
	public static function build_provenance_status() {
		return array(
			'manifest_present' => true,
			'manifest_valid' => true,
			'runtime_manifest_match' => true,
			'stale' => false,
			'provenance_mismatch' => array(),
			'source_commit_sha' => str_repeat( 'a', 40 ),
		);
	}
}
$GLOBALS['wp_version'] = '7.1.2';

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-cli.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL wp-cli-readonly-contract: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };

$expected = array(
	'mad4b status',
	'mad4b diagnostics schema',
	'mad4b diagnostics runtime',
	'mad4b package verify',
	'mad4b operations',
);
$actual = array_keys( WP_CLI::$commands );
sort( $expected );
sort( $actual );
$check( $expected === $actual, 'unexpected CLI command catalog: ' . json_encode( $actual ) );

foreach ( WP_CLI::$commands as $name => $callback ) {
	$before = count( WP_CLI::$lines );
	call_user_func( $callback, array(), array() );
	$check( count( WP_CLI::$lines ) === $before + 1, $name . ' did not emit exactly one machine response' );
	$row = json_decode( WP_CLI::$lines[ $before ], true );
	$check( is_array( $row ), $name . ' output is not JSON' );
	$check( 'mad4b.wp-cli.v1' === $row['cli_contract'], $name . ' lost CLI contract' );
	$check( false === $row['mutation_performed'], $name . ' reported mutation' );
	$check( false === $row['authorizing'], $name . ' became authorizing' );
}

$catalog = json_decode( end( WP_CLI::$lines ), true );
$check( false === $catalog['write_commands_available'], 'CLI exposed write commands' );
$check( false === $catalog['generic_shell_available'], 'CLI exposed generic shell' );
$check( false === $catalog['raw_sql_available'], 'CLI exposed raw SQL' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-cli.php' );
foreach ( array(
	'wp_insert_post(',
	'wp_update_post(',
	'update_option(',
	'delete_option(',
	'$wpdb->insert',
	'$wpdb->update',
	'WP_CLI::runcommand',
	'shell_exec(',
	'exec(',
	'proc_open(',
	'passthru(',
	'system(',
	'eval(',
) as $forbidden ) {
	$check( false === strpos( $source, $forbidden ), 'CLI contains forbidden mutation/command primitive: ' . $forbidden );
}

echo "mad4b.wp-cli.readonly.v1: PASS\n";
