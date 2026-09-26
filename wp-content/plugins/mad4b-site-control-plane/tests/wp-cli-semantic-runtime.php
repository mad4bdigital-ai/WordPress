<?php

$root = isset( $argv[1] ) ? rtrim( (string) $argv[1], "/\\") : '';
if ( '' === $root || ! is_dir( $root ) || ! is_file( $root . '/wp-config.php' ) ) {
	fwrite( STDERR, "invalid semantic runtime fixture root\n" );
	exit( 2 );
}

define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
define( 'WP_PLUGIN_DIR', $root . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'plugins' );
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
			'source_commit_sha' => str_repeat( 'b', 40 ),
		);
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-cli.php';

if ( ! isset( WP_CLI::$commands['mad4b status'] ) ) {
	fwrite( STDERR, "mad4b status command not registered\n" );
	exit( 3 );
}
call_user_func( WP_CLI::$commands['mad4b status'], array(), array() );
$row = json_decode( end( WP_CLI::$lines ), true );
if ( ! is_array( $row ) || ! isset( $row['semantic_result'] ) || ! is_array( $row['semantic_result'] ) ) {
	fwrite( STDERR, "semantic_result missing\n" );
	exit( 4 );
}
echo json_encode( $row['semantic_result'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
