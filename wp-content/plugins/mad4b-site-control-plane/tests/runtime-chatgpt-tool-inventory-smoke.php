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
	'mad4b-tool-discover',
	'mad4b-tool-info',
	'mad4b-read-execute',
	'mad4b-write-discover',
	'mad4b-write-info',
	'mad4b-write-execute',
	'mad4b-enrollment-info',
	'mad4b-enrollment-execute',
	'mad4b-diagnostics-health',
	'mad4b-runtime-authority-status',
	'mad4b-connection-status',
	'mad4b-plugin-package-plan',
	'mad4b-write-authority-status',
	'mad4b-write-authority-reconciliation-plan',
	'mad4b-write-runtime-certification',
	'mad4b-rest-compatibility-status',
	'mad4b-staging-certification-status',
	'mad4b-staging-write-candidate-binding-audit',
	'mad4b-full-staging-authority-status',
	'mad4b-full-staging-authority-plan',
	'mad4b-full-staging-authority-apply',
);
foreach ( $direct_required as $tool_name ) {
	if ( ! in_array( $tool_name, $actual_names, true ) ) {
		$fail( 'Required minimal Staging ChatGPT direct tool is missing.', $tool_name );
	}
}

// Enrollment implementation details remain registered internally, but the
// single user-facing ChatGPT app must expose only the composite step-up.
$internal_only_direct_forbidden = array(
	'mad4b-site-profile-feature-reenroll',
	'mad4b-site-profile-write-enable',
	'mad4b-staging-write-grant-reconcile',
	'mad4b-staging-write-candidate-bind',
	'mad4b-reconcile-managed-skills',
	'mad4b-frontend-performance-sample-run',
	'mad4b-admin-query-performance-apply',
	'mad4b-admin-query-performance-reconcile',
	'mad4b-remote-operation-work-claim',
	'mad4b-remote-operation-work-complete',
);
foreach ( $internal_only_direct_forbidden as $tool_name ) {
	if ( in_array( $tool_name, $actual_names, true ) ) {
		$fail( 'Low-level enrollment mutation leaked into the single-app direct catalog.', $tool_name );
	}
}

if ( ! wp_has_ability( 'mad4b/enrollment-info' ) || ! wp_has_ability( 'mad4b/enrollment-execute' ) ) {
	$fail( 'Bounded enrollment inspect/execute transport was not fully registered.' );
}
$enrollment_info_ability = wp_get_ability( 'mad4b/enrollment-info' );
$enrollment_dispatcher = wp_get_ability( 'mad4b/enrollment-execute' );
if ( ! is_object( $enrollment_info_ability ) || ! is_object( $enrollment_dispatcher ) || ! method_exists( $enrollment_dispatcher, 'get_meta' ) ) {
	$fail( 'Bounded enrollment transport metadata is unavailable.' );
}
$enrollment_meta = $enrollment_dispatcher->get_meta();
$enrollment_annotations = isset( $enrollment_meta['annotations'] ) && is_array( $enrollment_meta['annotations'] ) ? $enrollment_meta['annotations'] : array();
$enrollment_mcp = isset( $enrollment_meta['mcp'] ) && is_array( $enrollment_meta['mcp'] ) ? $enrollment_meta['mcp'] : array();
if ( true === ( isset( $enrollment_annotations['readonly'] ) ? $enrollment_annotations['readonly'] : null )
	|| ! empty( $enrollment_annotations['destructive'] )
	|| 'enrollment-dispatch' !== ( isset( $enrollment_mcp['surface'] ) ? (string) $enrollment_mcp['surface'] : '' )
	|| ! empty( $enrollment_mcp['generic_remote_admin'] )
	|| ! array_key_exists( 'production_mutation_allowed', $enrollment_mcp )
	|| false !== $enrollment_mcp['production_mutation_allowed'] ) {
	$fail( 'Bounded enrollment dispatcher metadata widened its authority contract.', $enrollment_meta );
}
foreach ( array( 'mad4b/enrollment-info', 'mad4b/enrollment-execute' ) as $transport_ability ) {
	if ( in_array( $transport_ability, MAD4B_SCP_Servers::write_tools(), true )
		|| in_array( $transport_ability, MAD4B_SCP_Servers::external_write_tools(), true ) ) {
		$fail( 'Bounded enrollment transport leaked into the normal write catalog.', $transport_ability );
	}
	foreach ( array( 'mad4b-admin', 'mad4b-write', 'mad4b-breakglass', 'mad4b-developer', 'mad4b-developer-breakglass' ) as $server_id ) {
		if ( null !== MAD4B_SCP_Servers::provider_for_ability( $server_id, $transport_ability ) ) {
			$fail( 'Bounded enrollment transport leaked onto a privileged/non-target server.', array( 'ability' => $transport_ability, 'server_id' => $server_id ) );
		}
	}
}
$enrollment_info = MAD4B_SCP_Remote_Operation_Parity::enrollment_info(
	array( 'ability_name' => MAD4B_SCP_Remote_Operation_Parity::SKILLS_ABILITY )
);
if ( is_wp_error( $enrollment_info )
	|| empty( $enrollment_info['read_only'] )
	|| ! empty( $enrollment_info['mutation_performed'] )
	|| empty( $enrollment_info['input_schema_sha256'] )
	|| 64 !== strlen( (string) $enrollment_info['input_schema_sha256'] )
	|| 'mad4b-enrollment' !== (string) $enrollment_info['authority_surface']
	|| false !== $enrollment_info['normal_write_authority_required']
	|| 'deny' !== (string) $enrollment_info['production_policy'] ) {
	$fail( 'Enrollment info did not expose a bounded exact target contract.', $enrollment_info );
}
$dispatch_denied = MAD4B_SCP_Remote_Operation_Parity::dispatch_enrollment_ability(
	array(
		'ability_name' => 'mad4b/plugin-activate',
		'expected_input_schema_sha256' => str_repeat( '0', 64 ),
		'input' => array(),
	)
);
if ( ! is_wp_error( $dispatch_denied ) || 'mad4b_enrollment_dispatch_target_not_allowed' !== $dispatch_denied->get_error_code() ) {
	$fail( 'Enrollment dispatcher did not fail closed for a non-enrollment target.', $dispatch_denied );
}
$schema_drift = MAD4B_SCP_Remote_Operation_Parity::dispatch_enrollment_ability(
	array(
		'ability_name' => MAD4B_SCP_Remote_Operation_Parity::SKILLS_ABILITY,
		'expected_input_schema_sha256' => str_repeat( '0', 64 ),
		'input' => array(),
	)
);
if ( ! is_wp_error( $schema_drift ) || 'mad4b_enrollment_dispatch_schema_drift' !== $schema_drift->get_error_code() ) {
	$fail( 'Enrollment dispatcher did not fail closed on target input schema drift.', $schema_drift );
}
$dispatch_validation = MAD4B_SCP_Remote_Operation_Parity::dispatch_enrollment_ability(
	array(
		'ability_name' => MAD4B_SCP_Remote_Operation_Parity::SKILLS_ABILITY,
		'expected_input_schema_sha256' => (string) $enrollment_info['input_schema_sha256'],
		'input' => array(),
	)
);
if ( ! is_wp_error( $dispatch_validation ) || 'ability_invalid_input' !== $dispatch_validation->get_error_code() ) {
	$fail( 'Enrollment dispatcher did not preserve target Ability input validation after schema fencing.', $dispatch_validation );
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
		'mad4b/post-identity',
		'mad4b/audit-tail',
		'mad4b/mutation-get',
		'mad4b/agent-list',
		'mad4b/agent-effective-access',
);
$full_candidates = MAD4B_SCP_Servers::chatgpt_full_catalog_candidates();
if ( ! in_array( 'mad4b/full-staging-authority-apply', $full_candidates, true ) ) {
	$fail( 'Single-app logical catalog lost the composite Full Staging Authority step-up.' );
}
foreach ( array(
	'mad4b/site-profile-feature-reenroll',
	'mad4b/site-profile-write-enable',
	'mad4b/staging-write-grant-reconcile',
	'mad4b/staging-write-candidate-bind',
) as $ability_name ) {
	if ( in_array( $ability_name, $full_candidates, true ) ) {
		$fail( 'Low-level enrollment mutation leaked into logical ChatGPT discovery.', $ability_name );
	}
}
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

// Stable write abilities remain fully cataloged, but their large schemas stay
// behind the governed write discovery/info/execute transport.
$hidden_write_required = array(
	'mad4b/content-update-post',
	'mad4b/plugin-activate',
	'mad4b/plugin-deactivate',
	'mad4b/plugin-package-apply',
	'mad4b/filesystem-write',
	'mad4b/filesystem-patch',
	'mad4b/database-update',
	'mad4b/mutation-undo',
	'mad4b/approval-plan',
);
$external_write_catalog = MAD4B_SCP_Servers::external_write_tools();
foreach ( $hidden_write_required as $ability_name ) {
	if ( ! in_array( $ability_name, $external_write_catalog, true ) ) {
		$fail( 'Minimal transport lost a stable governed write capability.', $ability_name );
	}
	$tool_name = \WP\MCP\Domain\Utils\McpNameSanitizer::sanitize_name( $ability_name );
	if ( is_wp_error( $tool_name ) ) {
		$fail( 'Hidden governed write ability cannot be represented as an MCP name.', $ability_name );
	}
	if ( in_array( (string) $tool_name, $actual_names, true ) ) {
		$fail( 'Large write ability leaked back into direct tools/list.', $tool_name );
	}
}

if ( count( $actual_names ) > 48 ) {
	$fail( 'Minimal ChatGPT tools/list exceeded the refresh-safety budget.', array( 'tool_count' => count( $actual_names ), 'budget' => 48 ) );
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

$write_discover = wp_get_ability( 'mad4b/write-discover' );
$write_info = wp_get_ability( 'mad4b/write-info' );
$write_execute = wp_get_ability( 'mad4b/write-execute' );
if ( ! $write_discover || ! $write_info || ! $write_execute ) {
	$fail( 'Minimal governed write transport is not fully registered.' );
}
$write_discovered = $write_discover->execute( array( 'query' => 'plugin package', 'limit' => 100 ) );
if ( is_wp_error( $write_discovered ) ) {
	$fail( 'Write discovery failed.', $write_discovered->get_error_code() );
}
$write_names = array();
foreach ( (array) ( $write_discovered['items'] ?? array() ) as $item ) {
	if ( is_array( $item ) && ! empty( $item['ability_name'] ) ) $write_names[] = (string) $item['ability_name'];
}
if ( ! in_array( 'mad4b/plugin-package-apply', $write_names, true ) ) {
	$fail( 'Write discovery did not expose plugin-package-apply.', $write_discovered );
}
$package_write_info = $write_info->execute( array( 'ability_name' => 'mad4b/plugin-package-apply' ) );
if ( is_wp_error( $package_write_info ) || empty( $package_write_info['input_schema_sha256'] ) || 64 !== strlen( (string) $package_write_info['input_schema_sha256'] ) ) {
	$fail( 'Write info did not return an exact input schema fingerprint.', $package_write_info );
}
if ( ! array_key_exists( 'runtime_eligible', $package_write_info ) ) {
	$fail( 'Write info omitted runtime eligibility.', $package_write_info );
}
$write_attempt = $write_execute->execute(
	array(
		'ability_name' => 'mad4b/plugin-package-apply',
		'expected_input_schema_sha256' => (string) $package_write_info['input_schema_sha256'],
		'input' => array(),
	)
);
if ( ! is_wp_error( $write_attempt ) ) {
	$fail( 'Write dispatcher unexpectedly executed without exact governed authority and target input.', $write_attempt );
}
if ( ! in_array( $write_attempt->get_error_code(), array(
	'mad4b_write_dispatch_mutation_disabled',
	'mad4b_write_dispatch_target_not_runtime_eligible',
	'rest_invalid_param',
	'invalid_input',
	'mad4b_authorization_unavailable',
	'mad4b_mutation_disabled',
	'ability_invalid_permissions',
), true ) ) {
	$fail( 'Write dispatcher failed closed with an unexpected contract.', array( 'code' => $write_attempt->get_error_code(), 'message' => $write_attempt->get_error_message() ) );
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
if ( ! in_array( 'mad4b/post-identity', $full_candidates, true ) ) {
	$fail( 'Post identity was lost from the governed ChatGPT discovery universe.' );
}
if ( null !== MAD4B_SCP_Servers::provider_for_ability( 'mad4b-chatgpt', 'mad4b/post-identity' ) ) {
	$fail( 'Post identity heavy schema leaked directly into mad4b-chatgpt tools/list.' );
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
	'mad4b.site-control-plane.runtime-chatgpt-tool-inventory.v6: PASS ' .
	wp_json_encode( array( 'tool_count' => count( $actual_names ), 'tools' => $actual_names ), JSON_UNESCAPED_SLASHES ) . PHP_EOL
);
