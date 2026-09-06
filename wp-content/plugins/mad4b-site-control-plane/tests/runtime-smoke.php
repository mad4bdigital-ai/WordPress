<?php
/**
 * Isolated WP-CLI runtime acceptance smoke for MAD4B Site Control Plane.
 *
 * Runs only inside CI's disposable WordPress/MySQL environment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert( function_exists( 'wp_register_ability' ), 'WordPress Abilities API is unavailable.' );
$assert( class_exists( 'WP\\MCP\\Core\\McpAdapter' ), 'Official MCP Adapter is not active.' );
$assert( class_exists( 'MAD4B_SCP_Provider_Contracts' ), 'Provider certification authority is unavailable.' );
$assert( class_exists( 'MAD4B_SCP_Servers' ), 'MAD4B MCP server registry is unavailable.' );

// This isolated smoke intentionally certifies only the transport dependency.
// Commercial providers are exercised by packaged static certification and later target-site runtime acceptance.
add_filter(
	'mad4b_scp_required_providers',
	static function () {
		return array( 'mcp_adapter' );
	},
	999
);

$core_abilities = array(
	'mad4b/site-info',
	'mad4b/filesystem-read',
	'mad4b/filesystem-write',
	'mad4b/database-update',
	'mad4b/runtime-self-test',
);
foreach ( $core_abilities as $ability_name ) {
	$assert( wp_has_ability( $ability_name ), 'Required ability is missing at runtime: ' . $ability_name );
	$ability = wp_get_ability( $ability_name );
	$assert( is_object( $ability ) && method_exists( $ability, 'get_meta' ), 'Ability metadata unavailable: ' . $ability_name );
	$meta = $ability->get_meta();
	$assert( empty( $meta['public'] ), 'MAD4B ability leaked through public metadata: ' . $ability_name );
	$assert( empty( $meta['mcp']['public'] ), 'MAD4B ability leaked to the default MCP server: ' . $ability_name );
}

$servers = MAD4B_SCP_Servers::registration_status();
foreach ( MAD4B_SCP_Servers::expected_server_ids() as $server_id ) {
	$assert( ! empty( $servers[ $server_id ]['registered'] ), 'Custom MCP server failed to register: ' . $server_id );
}

$mcp_status = MAD4B_SCP_Provider_Contracts::runtime_status( 'mcp_adapter', true );
$assert( ! empty( $mcp_status['runtime_contract_ok'] ), 'MCP Adapter runtime certification failed: ' . wp_json_encode( $mcp_status ) );

$self_test_ability = wp_get_ability( 'mad4b/runtime-self-test' );
$assert( is_object( $self_test_ability ) && method_exists( $self_test_ability, 'execute' ), 'Runtime self-test ability is not executable.' );
$self_test = $self_test_ability->execute( array() );
$assert( ! is_wp_error( $self_test ), 'Runtime self-test returned WP_Error: ' . ( is_wp_error( $self_test ) ? $self_test->get_error_message() : '' ) );
$assert( isset( $self_test['status'] ) && 'passed' === $self_test['status'], 'Runtime self-test did not pass: ' . wp_json_encode( $self_test ) );
$assert( ! empty( $self_test['custom_server_isolation'] ), 'Custom-server isolation did not pass.' );
$assert( ! empty( $self_test['custom_server_registration_ok'] ), 'Custom-server registration did not pass.' );
$assert( empty( $self_test['default_server_exposure_leaks'] ), 'Default MCP server exposure leak detected.' );

// Exercise fail-closed policy boundaries with real WordPress constants/paths.
$source_write = MAD4B_SCP_Policy::can_mutate_file( 'plugins', WP_PLUGIN_DIR . '/mad4b-site-control-plane/mad4b-site-control-plane.php' );
$assert( is_wp_error( $source_write ) && 'mad4b_executable_file_mutation_denied' === $source_write->get_error_code(), 'PHP source mutation was not denied.' );
$assert( false === MAD4B_SCP_Policy::plugin_lifecycle_allowed( 'hello-dolly/hello.php', 'activate' ), 'Plugin lifecycle must remain disabled without explicit opt-in.' );
$assert( false === MAD4B_SCP_Policy::can_breakglass(), 'Breakglass must remain inaccessible by default.' );
$assert( false === MAD4B_SCP_Policy::can_mutate(), 'Global mutation must remain inaccessible while MAD4B_MCP_MUTATION_ENABLED is absent.' );

// Prove the Ability execution path itself rejects a valid mutation request before side effects.
$uploads = wp_upload_dir( null, false );
$blocked_relative = 'mad4b-runtime-smoke/mutation-must-not-run.txt';
$blocked_path = trailingslashit( $uploads['basedir'] ) . $blocked_relative;
if ( file_exists( $blocked_path ) ) {
	unlink( $blocked_path );
}
$write_ability = wp_get_ability( 'mad4b/filesystem-write' );
$assert( is_object( $write_ability ) && method_exists( $write_ability, 'execute' ), 'Filesystem write ability is not executable for gate testing.' );
$blocked_write = $write_ability->execute(
	array(
		'root' => 'uploads',
		'path' => $blocked_relative,
		'content' => 'must-not-write',
		'allow_create' => true,
		'create_backup' => false,
	)
);
$assert( is_wp_error( $blocked_write ) && 'mad4b_mutation_disabled' === $blocked_write->get_error_code(), 'Core mutation master switch did not fail closed.' );
$assert( ! file_exists( $blocked_path ), 'A blocked mutation produced a filesystem side effect.' );

// Prove nested audit evidence is preserved, bounded, redacted, and chain-verifiable.
MAD4B_SCP_Audit::record(
	'mad4b/ci-runtime-smoke',
	array(
		'keys' => array( 'alpha', 'beta' ),
		'api_token' => 'must-not-appear',
	)
);
$audit_tail = MAD4B_SCP_Audit::tail( 1 );
$assert( 1 === count( $audit_tail ), 'Runtime audit entry was not recorded.' );
$summary = isset( $audit_tail[0]['summary'] ) && is_array( $audit_tail[0]['summary'] ) ? $audit_tail[0]['summary'] : array();
$assert( isset( $summary['keys'] ) && is_array( $summary['keys'] ) && 2 === count( $summary['keys'] ), 'Nested audit evidence was not preserved.' );
$assert( isset( $summary['api_token'] ) && '[REDACTED]' === $summary['api_token'], 'Sensitive audit metadata was not redacted.' );
$assert( MAD4B_SCP_Audit::verify_chain(), 'Audit hash chain verification failed.' );

echo "mad4b.site-control-plane.runtime-smoke.v1: PASS\n";
