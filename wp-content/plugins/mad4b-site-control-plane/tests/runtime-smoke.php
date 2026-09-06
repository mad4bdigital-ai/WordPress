<?php
/**
 * Isolated WP-CLI runtime acceptance smoke for MAD4B Site Control Plane.
 *
 * Runs only inside CI's disposable WordPress/MySQL environment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$check = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$check( function_exists( 'wp_register_ability' ), 'WordPress Abilities API is unavailable.' );
$check( class_exists( 'WP\\MCP\\Core\\McpAdapter' ), 'Official MCP Adapter is not active.' );
$check( class_exists( 'WP\\MCP\\Domain\\Utils\\AbilityArgumentNormalizer' ), 'MCP zero-argument normalizer is unavailable.' );
$check( class_exists( 'MAD4B_SCP_Provider_Contracts' ), 'Provider certification authority is unavailable.' );
$check( class_exists( 'MAD4B_SCP_Servers' ), 'MAD4B MCP server registry is unavailable.' );
$check( class_exists( 'MAD4B_SCP_Schema' ) && MAD4B_SCP_Schema::is_ready(), 'MAD4B governance schema is unavailable.' );
$check( MAD4B_SCP_Schema::VERSION === (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ), 'MAD4B governance schema version was not persisted.' );

// Fresh activation must create storage but no authority.
$initial_counts = MAD4B_SCP_Agent_Registry::counts();
$check( 0 === (int) $initial_counts['enabled_agents'], 'Fresh activation unexpectedly created an enabled NHI.' );
$check( 0 === (int) $initial_counts['enabled_subjects'], 'Fresh activation unexpectedly created a subject binding.' );
$check( 0 === (int) $initial_counts['grants'], 'Fresh activation unexpectedly created an ability grant.' );
$check( 0 === (int) $initial_counts['wildcard_grants'], 'Fresh activation contains wildcard grants.' );

$authority = MAD4B_SCP_Authorization::authority_status();
$check( ! empty( $authority['schema_ready'] ), 'Authority status did not report schema readiness.' );
$check( empty( $authority['mutation_global_enabled'] ), 'Mutation must not be configured on fresh activation.' );
$check( empty( $authority['mutation_effective_for_request'] ), 'Mutation must not be effective on fresh activation.' );
$check( 'ready_read_only' === $authority['status'], 'Fresh activation must report ready_read_only authority state: ' . wp_json_encode( $authority ) );

// Identity normalization rejects raw credential material and wildcard scopes.
$secret_context = MAD4B_SCP_Identity_Context::normalize(
	array(
		'authenticated' => true,
		'subject_type' => 'ci',
		'subject_identifier' => 'ci-subject',
		'access_token' => 'must-never-be-accepted',
	)
);
$check( is_wp_error( $secret_context ) && 'mad4b_identity_secret_field_denied' === $secret_context->get_error_code(), 'Raw credential material was accepted into identity context.' );
$wildcard_scope = MAD4B_SCP_Identity_Context::normalize(
	array(
		'authenticated' => true,
		'subject_type' => 'ci',
		'subject_identifier' => 'ci-subject',
		'token_scopes' => array( 'ability:*' ),
	)
);
$check( is_wp_error( $wildcard_scope ) && 'mad4b_identity_wildcard_scope_denied' === $wildcard_scope->get_error_code(), 'Wildcard token scope was accepted.' );

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
	'mad4b/runtime-authority-status',
);
foreach ( $core_abilities as $ability_name ) {
	$check( wp_has_ability( $ability_name ), 'Required ability is missing at runtime: ' . $ability_name );
	$ability = wp_get_ability( $ability_name );
	$check( is_object( $ability ) && method_exists( $ability, 'get_meta' ), 'Ability metadata unavailable: ' . $ability_name );
	$meta = $ability->get_meta();
	$check( empty( $meta['public'] ), 'MAD4B ability leaked through public metadata: ' . $ability_name );
	$check( empty( $meta['mcp']['public'] ), 'MAD4B ability leaked to the default MCP server: ' . $ability_name );
}

$servers = MAD4B_SCP_Servers::registration_status();
foreach ( MAD4B_SCP_Servers::expected_server_ids() as $server_id ) {
	$check( ! empty( $servers[ $server_id ]['registered'] ), 'Custom MCP server failed to register: ' . $server_id );
}

$mcp_status = MAD4B_SCP_Provider_Contracts::runtime_status( 'mcp_adapter', true );
$check( ! empty( $mcp_status['runtime_contract_ok'] ), 'MCP Adapter runtime certification failed: ' . wp_json_encode( $mcp_status ) );

$self_test_ability = wp_get_ability( 'mad4b/runtime-self-test' );
$check( is_object( $self_test_ability ) && method_exists( $self_test_ability, 'execute' ), 'Runtime self-test ability is not executable.' );
$normalized_empty = WP\MCP\Domain\Utils\AbilityArgumentNormalizer::normalize( $self_test_ability, array() );
$check( null === $normalized_empty, 'MCP Adapter did not normalize an empty object to null for a zero-argument ability.' );
$self_test = $self_test_ability->execute( $normalized_empty );
$check( ! is_wp_error( $self_test ), 'Runtime self-test returned WP_Error: ' . ( is_wp_error( $self_test ) ? $self_test->get_error_message() : '' ) );
$check( isset( $self_test['status'] ) && 'passed' === $self_test['status'], 'Runtime self-test did not pass: ' . wp_json_encode( $self_test ) );
$check( ! empty( $self_test['custom_server_isolation'] ), 'Custom-server isolation did not pass.' );
$check( ! empty( $self_test['custom_server_registration_ok'] ), 'Custom-server registration did not pass.' );
$check( empty( $self_test['default_server_exposure_leaks'] ), 'Default MCP server exposure leak detected.' );

// Exercise fail-closed policy boundaries with real WordPress constants/paths.
$source_write = MAD4B_SCP_Policy::can_mutate_file( 'plugins', WP_PLUGIN_DIR . '/mad4b-site-control-plane/mad4b-site-control-plane.php' );
$check( is_wp_error( $source_write ) && 'mad4b_executable_file_mutation_denied' === $source_write->get_error_code(), 'PHP source mutation was not denied.' );
$check( false === MAD4B_SCP_Policy::plugin_lifecycle_allowed( 'hello-dolly/hello.php', 'activate' ), 'Plugin lifecycle must remain disabled without explicit opt-in.' );
$check( false === MAD4B_SCP_Policy::can_breakglass(), 'Breakglass must remain inaccessible by default.' );
$check( false === MAD4B_SCP_Policy::can_mutate(), 'Global mutation must remain inaccessible while MAD4B_MCP_MUTATION_ENABLED is absent.' );

// Prove the Ability execution path itself rejects a valid mutation request before side effects.
// WordPress intentionally masks permission_callback WP_Error details as ability_invalid_permissions.
$uploads = wp_upload_dir( null, false );
$blocked_relative = 'mad4b-runtime-smoke/mutation-must-not-run.txt';
$blocked_path = trailingslashit( $uploads['basedir'] ) . $blocked_relative;
if ( file_exists( $blocked_path ) ) {
	unlink( $blocked_path );
}
$write_ability = wp_get_ability( 'mad4b/filesystem-write' );
$check( is_object( $write_ability ) && method_exists( $write_ability, 'execute' ), 'Filesystem write ability is not executable for gate testing.' );
$blocked_write = $write_ability->execute(
	array(
		'root' => 'uploads',
		'path' => $blocked_relative,
		'content' => 'must-not-write',
		'allow_create' => true,
		'create_backup' => false,
	)
);
$check( is_wp_error( $blocked_write ) && 'ability_invalid_permissions' === $blocked_write->get_error_code(), 'Core mutation master switch did not fail closed through the WordPress Abilities permission contract.' );
$check( ! file_exists( $blocked_path ), 'A blocked mutation produced a filesystem side effect.' );

// Prove approval canonicalization, exact payload binding, single use, and replay resistance in the disposable DB.
$agent = MAD4B_SCP_Agent_Registry::create_agent(
	array(
		'slug' => 'ci-approval-agent',
		'label' => 'CI Approval Agent',
		'status' => 'enabled',
		'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
	)
);
$check( is_array( $agent ) && ! empty( $agent['public_id'] ), 'Unable to create disposable CI NHI.' );
$input_a = array( 'zeta' => 2, 'alpha' => array( 'b' => true, 'a' => 'x' ) );
$input_b = array( 'alpha' => array( 'a' => 'x', 'b' => true ), 'zeta' => 2 );
$hash_a = MAD4B_SCP_Approval_Tickets::canonical_payload_hash( $agent['public_id'], 'mad4b-admin', 'mad4b/database-update', 'core', 'ci-target', $input_a, 'mutation' );
$hash_b = MAD4B_SCP_Approval_Tickets::canonical_payload_hash( $agent['public_id'], 'mad4b-admin', 'mad4b/database-update', 'core', 'ci-target', $input_b, 'mutation' );
$check( is_string( $hash_a ) && hash_equals( $hash_a, $hash_b ), 'Canonical approval hash changed with object key order.' );

$ticket = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-admin', 'mad4b/database-update', 'core', 'ci-target', $input_a, 'mutation', 'CI exact approval test', 600 );
$check( is_array( $ticket ) && 'pending' === $ticket['status'], 'Pending approval ticket was not created.' );
$approved = MAD4B_SCP_Approval_Tickets::approve( $ticket['ticket_id'] );
$check( is_array( $approved ) && 'approved' === $approved['status'], 'Approval ticket was not approved.' );
$wrong = MAD4B_SCP_Approval_Tickets::consume_exact( $ticket['ticket_id'], $agent, 'mad4b-admin', 'mad4b/database-update', 'core', 'ci-target', array( 'zeta' => 3, 'alpha' => array( 'a' => 'x', 'b' => true ) ), 'mutation' );
$check( is_wp_error( $wrong ) && 'mad4b_approval_payload_mismatch' === $wrong->get_error_code(), 'Wrong approval payload was not rejected.' );
$consumed = MAD4B_SCP_Approval_Tickets::consume_exact( $ticket['ticket_id'], $agent, 'mad4b-admin', 'mad4b/database-update', 'core', 'ci-target', $input_b, 'mutation' );
$check( is_array( $consumed ), 'Exact approved payload could not consume its ticket.' );
$replay = MAD4B_SCP_Approval_Tickets::consume_exact( $ticket['ticket_id'], $agent, 'mad4b-admin', 'mad4b/database-update', 'core', 'ci-target', $input_b, 'mutation' );
$check( is_wp_error( $replay ) && 'mad4b_approval_not_approved' === $replay->get_error_code(), 'Approval replay was not rejected.' );

// Prove nested audit evidence is preserved, bounded, redacted, and chain-verifiable.
MAD4B_SCP_Audit::record(
	'mad4b/ci-runtime-smoke',
	array(
		'keys' => array( 'alpha', 'beta' ),
		'api_token' => 'must-not-appear',
	)
);
$audit_tail = MAD4B_SCP_Audit::tail( 1 );
$check( 1 === count( $audit_tail ), 'Runtime audit entry was not recorded.' );
$summary = isset( $audit_tail[0]['summary'] ) && is_array( $audit_tail[0]['summary'] ) ? $audit_tail[0]['summary'] : array();
$check( isset( $summary['keys'] ) && is_array( $summary['keys'] ) && 2 === count( $summary['keys'] ), 'Nested audit evidence was not preserved.' );
$check( isset( $summary['api_token'] ) && '[REDACTED]' === $summary['api_token'], 'Sensitive audit metadata was not redacted.' );
$check( MAD4B_SCP_Audit::verify_chain(), 'Audit hash chain verification failed.' );

echo "mad4b.site-control-plane.runtime-smoke.v2: PASS\n";
