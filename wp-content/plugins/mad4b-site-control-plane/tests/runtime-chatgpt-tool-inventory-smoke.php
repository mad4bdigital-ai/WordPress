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

$direct_required = array(
	'mad4b-site-info',
	'mad4b-site-profile-status',
	'mad4b-build-provenance-status',
	'mad4b-list-post-types',
	'mad4b-post-identity',
	'mad4b-list-plugins',
	'mad4b-abilities-inventory',
	'mad4b-tool-discover',
	'mad4b-tool-info',
	'mad4b-read-execute',
	'mad4b-diagnostics-health',
	'mad4b-runtime-authority-status',
	'mad4b-connection-status',
	'mad4b-plugin-lifecycle-plan',
	'mad4b-plugin-package-plan',
	'mad4b-write-authority-status',
	'mad4b-write-authority-reconciliation-plan',
	'mad4b-write-runtime-certification',
	'mad4b-rest-compatibility-status',
	'mad4b-staging-certification-status',
	'mad4b-filesystem-write',
	'mad4b-filesystem-patch',
	'mad4b-database-update',
	'mad4b-content-update-post',
	'mad4b-plugin-activate',
	'mad4b-plugin-deactivate',
	'mad4b-mutation-undo',
	'mad4b-approval-plan',
	'mad4b-site-profile-feature-reenroll',
	'mad4b-site-profile-write-enable',
	'mad4b-staging-write-grant-reconcile',
	'mad4b-staging-write-candidate-bind',
);
foreach ( $direct_required as $tool_name ) {
	if ( ! in_array( $tool_name, $actual_names, true ) ) {
		$fail( 'Required compact Staging ChatGPT direct tool is missing.', $tool_name );
	}
}

// Heavy/read-only inventory stays out of tools/list so client Refresh remains
// bounded. These abilities remain in the governed capability universe and are
// reachable only through the read-only discovery/info/execute surface.
$hidden_read_required = array(
		'mad4b/filesystem-list',
		'mad4b/filesystem-read',
		'mad4b/database-list-tables',
		'mad4b/database-describe-table',
		'mad4b/database-select',
		'mad4b/content-get-post',
		'mad4b/audit-tail',
		'mad4b/mutation-get',
		'mad4b/agent-list',
		'mad4b/agent-effective-access',
);
$full_candidates = MAD4B_SCP_Servers::chatgpt_full_catalog_candidates();
foreach ( $hidden_read_required as $ability_name ) {
	if ( ! in_array( $ability_name, $full_candidates, true ) ) {
		$fail( 'Compact catalog lost a governed read capability.', $ability_name );
	}
	$tool_name = \WP\MCP\Domain\Utils\McpNameSanitizer::sanitize_name( $ability_name );
	if ( is_wp_error( $tool_name ) ) {
		$fail( 'Hidden governed read ability cannot be represented as an MCP name.', $ability_name );
	}
	if ( in_array( (string) $tool_name, $actual_names, true ) ) {
		$fail( 'Heavy read ability leaked back into direct tools/list.', $tool_name );
	}
}

if ( count( $actual_names ) > 128 ) {
	$fail( 'Compact ChatGPT tools/list exceeded the refresh-safety budget.', array( 'tool_count' => count( $actual_names ), 'budget' => 128 ) );
}

$discover = wp_get_ability( 'mad4b/tool-discover' );
$info = wp_get_ability( 'mad4b/tool-info' );
$read_execute = wp_get_ability( 'mad4b/read-execute' );
if ( ! $discover || ! $info || ! $read_execute ) {
	$fail( 'Compact read discovery surface is not fully registered.' );
}
$discovered = $discover->execute( array( 'query' => 'filesystem', 'limit' => 100 ) );
if ( is_wp_error( $discovered ) ) {
	$fail( 'Read discovery failed.', $discovered->get_error_code() );
}
$discovered_names = array();
foreach ( (array) ( $discovered['items'] ?? array() ) as $item ) {
	if ( is_array( $item ) && ! empty( $item['ability_name'] ) ) {
		$discovered_names[] = (string) $item['ability_name'];
	}
}
if ( ! in_array( 'mad4b/filesystem-list', $discovered_names, true ) ) {
	$fail( 'Read discovery did not expose hidden filesystem-list capability.', $discovered );
}

$filesystem_info = $info->execute( array( 'ability_name' => 'mad4b/filesystem-list' ) );
if ( is_wp_error( $filesystem_info ) || empty( $filesystem_info['read_only'] ) || empty( $filesystem_info['annotations']['readonly'] ) ) {
	$fail( 'Read info did not preserve readonly metadata for hidden capability.', $filesystem_info );
}

$database_read = $read_execute->execute(
	array(
		'ability_name' => 'mad4b/database-list-tables',
		'input' => array(),
	)
);
if ( is_wp_error( $database_read ) || empty( $database_read['read_only'] ) || ! isset( $database_read['result']['tables'] ) ) {
	$fail( 'Readonly dispatcher could not execute a hidden governed read ability.', $database_read );
}

$mutation_denied = $read_execute->execute(
	array(
		'ability_name' => 'mad4b/plugin-package-apply',
		'input' => array(),
	)
);
if ( ! is_wp_error( $mutation_denied ) || 'mad4b_read_dispatch_mutation_denied' !== $mutation_denied->get_error_code() ) {
	$fail( 'Readonly dispatcher did not fail closed for a mutating ability.', $mutation_denied );
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
	'mad4b.site-control-plane.runtime-chatgpt-tool-inventory.v4: PASS ' .
	wp_json_encode( array( 'tool_count' => count( $actual_names ), 'tools' => $actual_names ), JSON_UNESCAPED_SLASHES ) . PHP_EOL
);
