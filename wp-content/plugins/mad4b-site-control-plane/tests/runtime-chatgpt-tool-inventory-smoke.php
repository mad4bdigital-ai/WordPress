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
if ( ! class_exists( 'MAD4B_SCP_Post_Identity' ) ) {
	$fail( 'MAD4B narrow post identity primitive is unavailable.' );
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
	'mad4b-site-profile-status',
	'mad4b-build-provenance-status',
	'mad4b-list-post-types',
	'mad4b-post-identity',
	'mad4b-list-plugins',
	'mad4b-abilities-inventory',
	'mad4b-diagnostics-health',
	'mad4b-runtime-authority-status',
	'mad4b-connection-status',
	'mad4b-filesystem-list',
	'mad4b-filesystem-read',
	'mad4b-filesystem-write',
	'mad4b-filesystem-patch',
	'mad4b-database-list-tables',
	'mad4b-database-describe-table',
	'mad4b-database-select',
	'mad4b-database-update',
	'mad4b-content-get-post',
	'mad4b-content-update-post',
	'mad4b-plugin-activate',
	'mad4b-plugin-deactivate',
	'mad4b-audit-tail',
	'mad4b-mutation-get',
	'mad4b-mutation-undo',
	'mad4b-agent-list',
	'mad4b-agent-effective-access',
	'mad4b-approval-plan',
	'mad4b-site-profile-feature-reenroll',
);
foreach ( $core_required as $tool_name ) {
	if ( ! in_array( $tool_name, $actual_names, true ) ) {
		$fail( 'Required unified Staging ChatGPT Read/Write tool is missing.', $tool_name );
	}
}

if ( ! wp_has_ability( 'mad4b/post-identity' ) ) {
	$fail( 'Narrow post identity ability was not registered.' );
}
$identity_ability = wp_get_ability( 'mad4b/post-identity' );
if ( ! is_object( $identity_ability ) || ! method_exists( $identity_ability, 'get_meta' ) ) {
	$fail( 'Narrow post identity ability metadata is unavailable.' );
}
$identity_meta = $identity_ability->get_meta();
$identity_annotations = isset( $identity_meta['annotations'] ) && is_array( $identity_meta['annotations'] ) ? $identity_meta['annotations'] : array();
if ( empty( $identity_annotations['readonly'] ) || ! empty( $identity_annotations['destructive'] ) ) {
	$fail( 'Post identity ability must remain readonly and non-destructive.', $identity_meta );
}
if ( 'core' !== MAD4B_SCP_Servers::provider_for_ability( 'mad4b-read', 'mad4b/post-identity' ) ) {
	$fail( 'Post identity must be mounted on mad4b-read.' );
}
if ( 'core' !== MAD4B_SCP_Servers::provider_for_ability( 'mad4b-chatgpt', 'mad4b/post-identity' ) ) {
	$fail( 'Post identity must be mounted on mad4b-chatgpt.' );
}
foreach ( array( 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' ) as $server_id ) {
	if ( null !== MAD4B_SCP_Servers::provider_for_ability( $server_id, 'mad4b/post-identity' ) ) {
		$fail( 'Post identity leaked onto a privileged/non-target surface.', $server_id );
	}
}
if ( in_array( 'mad4b/post-identity', MAD4B_SCP_Servers::write_tools(), true ) || in_array( 'mad4b/post-identity', MAD4B_SCP_Servers::external_write_tools(), true ) ) {
	$fail( 'Post identity leaked into a write catalog.' );
}

$fixture_id = wp_insert_post(
	array(
		'post_title' => 'MAD4B post identity fixture',
		'post_content' => 'must-not-be-exposed',
		'post_excerpt' => 'must-not-be-exposed',
		'post_status' => 'draft',
		'post_type' => 'post',
	),
	true
);
if ( is_wp_error( $fixture_id ) || (int) $fixture_id < 1 ) {
	$fail( 'Unable to create disposable post identity fixture.', is_wp_error( $fixture_id ) ? $fixture_id->get_error_code() : $fixture_id );
}
update_post_meta( (int) $fixture_id, '_mad4b_identity_secret_fixture', 'must-not-be-exposed' );
$identity = MAD4B_SCP_Post_Identity::execute( array( 'post_id' => (int) $fixture_id ) );
if ( is_wp_error( $identity ) || empty( $identity['exists'] ) || (int) $fixture_id !== (int) $identity['ID'] || 'post' !== $identity['post_type'] || 'draft' !== $identity['post_status'] ) {
	wp_delete_post( (int) $fixture_id, true );
	$fail( 'Post identity returned incorrect bounded identity.', $identity );
}
$identity_keys = array_keys( $identity );
sort( $identity_keys );
$expected_identity_keys = array( 'ID', 'exists', 'post_status', 'post_type' );
sort( $expected_identity_keys );
if ( $identity_keys !== $expected_identity_keys ) {
	wp_delete_post( (int) $fixture_id, true );
	$fail( 'Post identity exposed fields outside the bounded contract.', $identity_keys );
}
wp_delete_post( (int) $fixture_id, true );
$missing_identity = MAD4B_SCP_Post_Identity::execute( array( 'post_id' => 2147483647 ) );
if ( is_wp_error( $missing_identity ) || ! array_key_exists( 'exists', $missing_identity ) || false !== $missing_identity['exists'] ) {
	$fail( 'Missing post identity must fail closed as exists=false without search/fallback.', $missing_identity );
}

// Breakglass remains a different server and Raw SQL must never appear in the
// unified ChatGPT catalog, even though normal filesystem/database reads and
// governed writes are intentionally exposed on exact enrolled Staging.
$forbidden = array(
	'mad4b-database-raw-query',
);
foreach ( $forbidden as $tool_name ) {
	if ( in_array( $tool_name, $actual_names, true ) ) {
		$fail( 'Breakglass/Raw SQL leaked into mad4b-chatgpt.', $tool_name );
	}
}

fwrite(
	STDOUT,
	'mad4b.site-control-plane.runtime-chatgpt-tool-inventory.v3: PASS ' .
	wp_json_encode( array( 'tool_count' => count( $actual_names ), 'tools' => $actual_names ), JSON_UNESCAPED_SLASHES ) . PHP_EOL
);
