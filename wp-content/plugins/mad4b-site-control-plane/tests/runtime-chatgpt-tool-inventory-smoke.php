<?php
/**
 * Runtime proof that the ChatGPT MCP server actually contains the governed
 * tool inventory after the official MCP Adapter has converted abilities into
 * protocol DTOs. Server creation alone is not sufficient because the Adapter
 * may skip an ability that is unavailable or invalid at registration time.
 */

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$fail = static function ( $message, $data = null ) {
	throw new RuntimeException(
		$message . ( null !== $data ? ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) : '' )
	);
};

if ( ! class_exists( 'WP\\MCP\\Core\\McpAdapter' ) ) {
	$fail( 'Official MCP Adapter is unavailable.' );
}
if ( ! class_exists( 'WP\\MCP\\Domain\\Utils\\McpNameSanitizer' ) ) {
	$fail( 'MCP tool-name sanitizer is unavailable.' );
}
if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) {
	$fail( 'MAD4B MCP server registry is unavailable.' );
}

$adapter = \WP\MCP\Core\McpAdapter::instance();
$server  = $adapter->get_server( 'mad4b-chatgpt' );
if ( ! $server ) {
	$fail( 'mad4b-chatgpt server is not registered in the Adapter runtime.' );
}

$tools = $server->get_tools();
if ( empty( $tools ) ) {
	$fail( 'mad4b-chatgpt registered with an empty protocol tool inventory.' );
}

$actual_names = array();
foreach ( $tools as $tool ) {
	if ( ! is_object( $tool ) || ! method_exists( $tool, 'getName' ) ) {
		$fail( 'ChatGPT server contains a non-protocol tool entry.' );
	}
	$actual_names[] = (string) $tool->getName();
}
$actual_names = array_values( array_unique( $actual_names ) );
sort( $actual_names );

$expected_abilities = MAD4B_SCP_Servers::chatgpt_tools();
if ( empty( $expected_abilities ) ) {
	$fail( 'Governed ChatGPT allowlist unexpectedly resolved to zero abilities.' );
}

$expected_names = array();
foreach ( $expected_abilities as $ability_name ) {
	if ( ! wp_has_ability( $ability_name ) ) {
		$fail( 'Governed ChatGPT ability is unavailable at MCP registration time.', $ability_name );
	}
	$name = \WP\MCP\Domain\Utils\McpNameSanitizer::sanitize_name( (string) $ability_name );
	if ( is_wp_error( $name ) ) {
		$fail( 'Governed ChatGPT ability cannot be represented as an MCP tool.', array( 'ability' => $ability_name, 'error' => $name->get_error_code() ) );
	}
	$expected_names[] = (string) $name;
}
$expected_names = array_values( array_unique( $expected_names ) );
sort( $expected_names );

if ( $actual_names !== $expected_names ) {
	$fail(
		'mad4b-chatgpt protocol inventory drifted from the governed allowlist.',
		array(
			'expected' => $expected_names,
			'actual'   => $actual_names,
			'missing'  => array_values( array_diff( $expected_names, $actual_names ) ),
			'extra'    => array_values( array_diff( $actual_names, $expected_names ) ),
		)
	);
}

$core_required = array(
	'mad4b-site-info',
	'mad4b-list-post-types',
	'mad4b-list-plugins',
	'mad4b-abilities-inventory',
	'mad4b-diagnostics-health',
	'mad4b-runtime-authority-status',
	'mad4b-connection-status',
);
foreach ( $core_required as $tool_name ) {
	if ( ! in_array( $tool_name, $actual_names, true ) ) {
		$fail( 'Required ChatGPT safe-read tool is missing.', $tool_name );
	}
}

$forbidden = array(
	'mad4b-filesystem-list',
	'mad4b-filesystem-read',
	'mad4b-filesystem-write',
	'mad4b-filesystem-patch',
	'mad4b-database-list-tables',
	'mad4b-database-describe-table',
	'mad4b-database-select',
	'mad4b-database-update',
	'mad4b-database-raw-query',
	'mad4b-content-update-post',
	'mad4b-plugin-activate',
	'mad4b-plugin-deactivate',
	'mad4b-mutation-undo',
);
foreach ( $forbidden as $tool_name ) {
	if ( in_array( $tool_name, $actual_names, true ) ) {
		$fail( 'Forbidden privileged tool leaked into mad4b-chatgpt.', $tool_name );
	}
}

fwrite(
	STDOUT,
	'mad4b.site-control-plane.runtime-chatgpt-tool-inventory.v1: PASS ' .
	wp_json_encode( array( 'tool_count' => count( $actual_names ), 'tools' => $actual_names ), JSON_UNESCAPED_SLASHES ) . PHP_EOL
);
