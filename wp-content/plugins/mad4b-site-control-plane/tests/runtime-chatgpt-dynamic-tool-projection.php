<?php
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
require_once __DIR__ . '/prepared-dispatch-runtime-helper.php';

$fail = static function ( $message, $data = null ) {
	throw new RuntimeException( $message . ( null !== $data ? ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) : '' ) );
};

if ( ! class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) ) $fail( 'Dynamic ChatGPT tool projection registry is unavailable.' );
if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) $fail( 'MCP server registry is unavailable.' );
if ( ! class_exists( 'MAD4B_SCP_MCP_Catalog_Diagnostics' ) ) $fail( 'MCP catalog diagnostics are unavailable.' );


foreach ( array(
	MAD4B_SCP_ChatGPT_Tool_Projection::STATUS_ABILITY,
	MAD4B_SCP_ChatGPT_Tool_Projection::DISCOVER_ABILITY,
	MAD4B_SCP_ChatGPT_Tool_Projection::PLAN_ABILITY,
	MAD4B_SCP_ChatGPT_Tool_Projection::APPLY_ABILITY,
) as $ability_name ) {
	if ( ! wp_has_ability( $ability_name ) ) $fail( 'Projection control Ability is not registered.', $ability_name );
}

$all = MAD4B_SCP_ChatGPT_Tool_Projection::all_site_ability_names();
$wp_all = array_keys( wp_get_abilities() );
sort( $wp_all, SORT_STRING );
if ( $all !== $wp_all ) $fail( 'Projection universe must equal all currently registered WordPress Abilities.', array( 'projection' => count( $all ), 'wordpress' => count( $wp_all ) ) );

foreach ( array( 'mad4b/diagnostics-health', 'mad4b/plugin-package-apply', 'mad4b/database-raw-query', 'mad4b-ci/unclassified-projection-fixture' ) as $required ) {
	if ( ! in_array( $required, $all, true ) ) $fail( 'All-site projection universe omitted a registered Ability.', $required );
}

$discover = wp_get_ability( MAD4B_SCP_ChatGPT_Tool_Projection::DISCOVER_ABILITY );
$found = $discover->execute( array( 'query' => 'diagnostics health', 'limit' => 100, 'offset' => 0 ) );
if ( is_wp_error( $found ) ) $fail( 'Projection discovery failed.', $found->get_error_code() );
$found_names = array();
foreach ( $found['items'] ?? array() as $row ) if ( is_array( $row ) && isset( $row['ability_name'] ) ) $found_names[] = (string) $row['ability_name'];
if ( ! in_array( 'mad4b/diagnostics-health', $found_names, true ) ) $fail( 'Projection discovery did not search the full site Ability universe.', $found );
$addon_found = $discover->execute( array( 'query' => 'addon registry', 'limit' => 100, 'offset' => 0 ) );
if ( is_wp_error( $addon_found ) ) $fail( 'Token-normalized projection discovery failed.', $addon_found->get_error_code() );
$addon_names = array();
foreach ( $addon_found['items'] ?? array() as $row ) if ( is_array( $row ) && isset( $row['ability_name'] ) ) $addon_names[] = (string) $row['ability_name'];
if ( ! in_array( 'mad4b/addon-registry-status', $addon_names, true ) ) $fail( 'Token-normalized projection discovery did not bridge punctuation in Ability metadata.', $addon_found );
$raw_discovery = $discover->execute( array( 'query' => 'mad4b/database-raw-query', 'limit' => 10, 'offset' => 0 ) );
$raw_row = isset( $raw_discovery['items'][0] ) && is_array( $raw_discovery['items'][0] ) ? $raw_discovery['items'][0] : array();
if ( 'structural_classification_only' !== (string) ( $raw_row['execution_eligibility_scope'] ?? '' )
	|| ! empty( $raw_row['current_runtime_authority_evaluated'] )
	|| empty( $raw_row['current_runtime_authority_required_for_execution'] ) ) {
	$fail( 'Projection discovery structural eligibility must not imply current runtime authority.', $raw_row );
}



$base = MAD4B_SCP_Servers::chatgpt_base_tools();
if ( in_array( 'mad4b/diagnostics-health', $base, true ) ) $fail( 'Fixture Ability unexpectedly belongs to the stable base tools/list.' );

foreach ( array( 'internal', 'developer', 'breakglass' ) as $sensitive_lane ) {
	$row = MAD4B_SCP_ChatGPT_Tool_Projection::describe_ability( 'mad4b-ci/readonly-' . $sensitive_lane );
	if ( is_wp_error( $row ) || $row['lane'] !== $sensitive_lane ) $fail( 'Readonly downgraded a sensitive authority lane.' );
}
$spoofed = MAD4B_SCP_ChatGPT_Tool_Projection::describe_ability( 'mad4b-ci/spoofed-boundary' );
if ( is_wp_error( $spoofed ) || $spoofed['execution_boundary_verified'] || $spoofed['execution_eligible'] ) $fail( 'Metadata-only execution boundary admitted.' );
$late_read_plan = MAD4B_SCP_ChatGPT_Tool_Projection::plan( array(
	'mode' => 'replace',
	'ability_names' => array( 'mad4b-ci/late-read-admission-fixture' ),
	'include_breakglass' => false,
) );
if ( is_wp_error( $late_read_plan ) ) $fail( 'Late-filter read fixture planning failed unexpectedly.', $late_read_plan->get_error_code() );
$late_read_row = $late_read_plan['desired_abilities'][0] ?? array();
if ( ! empty( $late_read_plan['ready_for_apply'] )
	|| ! empty( $late_read_row['projection_eligible'] )
	|| ! in_array( 'final_execution_admission_required', $late_read_row['projection_blockers'] ?? array(), true ) ) {
	$fail( 'Later same-priority registration filter removed the final wrapper without blocking projection.', $late_read_plan );
}
$plan = MAD4B_SCP_ChatGPT_Tool_Projection::plan( array(
	'mode' => 'replace',
	'ability_names' => array( 'mad4b/diagnostics-health' ),
	'include_breakglass' => false,
) );
if ( is_wp_error( $plan ) ) $fail( 'Read projection plan failed.', $plan->get_error_code() );
if ( empty( $plan['mcp_preflight']['ready'] ) ) $fail( 'Read projection plan did not pass exact MCP preflight.', $plan['mcp_preflight'] );
if ( 1 !== (int) $plan['desired_count'] || 'mad4b/diagnostics-health' !== (string) $plan['desired_abilities'][0]['ability_name'] || empty( $plan['desired_abilities'][0]['readonly'] ) ) {
	$fail( 'Read projection plan classification is incorrect.', $plan );
}

$write_plan = MAD4B_SCP_ChatGPT_Tool_Projection::plan( array(
	'mode' => 'replace',
	'ability_names' => array( 'mad4b/plugin-package-apply' ),
	'include_breakglass' => false,
) );
if ( is_wp_error( $write_plan ) ) $fail( 'Mutation projection plan failed.', $write_plan->get_error_code() );
if ( empty( $write_plan['mcp_preflight']['ready'] ) ) $fail( 'Mutation projection plan did not pass exact MCP preflight.', $write_plan['mcp_preflight'] );
if ( ! isset( $write_plan['desired_abilities'][0]['readonly'] ) || false !== $write_plan['desired_abilities'][0]['readonly'] ) {
	$fail( 'Mutation projection plan lost readonly=false classification.', $write_plan );
}

$unclassified_plan = MAD4B_SCP_ChatGPT_Tool_Projection::plan( array(
	'mode' => 'replace',
	'ability_names' => array( 'mad4b-ci/unclassified-projection-fixture' ),
	'include_breakglass' => false,
) );
if ( is_wp_error( $unclassified_plan ) ) $fail( 'Unclassified third-party-style Ability projection plan failed.', $unclassified_plan->get_error_code() );
$unclassified_row = $unclassified_plan['desired_abilities'][0] ?? array();
if (
	'unclassified' !== (string) ( $unclassified_row['classification'] ?? '' )
	|| ! empty( $unclassified_row['readonly'] )
	|| ! empty( $unclassified_row['readonly_declared'] )
	|| empty( $unclassified_row['conservative_mutation'] )
	|| ! empty( $unclassified_row['projection_eligible'] )
	|| ! empty( $unclassified_row['execution_eligible'] )
	|| 'none' !== (string) ( $unclassified_row['execution_lane'] ?? '' )
	|| ! in_array( 'ability_classification_required', $unclassified_row['projection_blockers'] ?? array(), true )
) {
	$fail( 'Unclassified Ability did not remain visible but fail closed before direct projection or execution.', $unclassified_row );
}
if ( ! empty( $unclassified_plan['ready_for_apply'] ) ) $fail( 'Unclassified Ability unexpectedly became projection-ready.', $unclassified_plan );
if ( ! in_array( 'mad4b-ci/unclassified-projection-fixture', $unclassified_plan['unprojectable_abilities'] ?? array(), true ) ) {
	$fail( 'Unclassified Ability was not reported as unprojectable.', $unclassified_plan );
}
if ( ! in_array( 'ability_classification_required', $unclassified_plan['projection_policy_blockers']['mad4b-ci/unclassified-projection-fixture'] ?? array(), true ) ) {
	$fail( 'Unclassified Ability classification blocker was not surfaced.', $unclassified_plan );
}
if ( in_array( 'mad4b-ci/unclassified-projection-fixture', $unclassified_plan['mcp_preflight']['tools'] ?? array(), true ) ) {
	$fail( 'Unclassified Ability leaked into the MCP preflight candidate set.', $unclassified_plan );
}

$oversized_name = 'mad4b-ci/oversized-read-projection-fixture';
$oversized_prepare = MAD4B_SCP_Unified_Capability_Gateway::dispatch( array(
	'action' => 'prepare',
	'ability_names' => array( $oversized_name ),
	'client_capabilities' => array(
		'max_response_bytes' => 65536,
		'max_inline_schema_bytes' => 1024,
	),
), 'internal' );
if ( is_wp_error( $oversized_prepare ) ) $fail( 'Oversized Ability could not use lazy preparation.', $oversized_prepare->get_error_code() );
$oversized_prepared = $oversized_prepare['abilities'][0] ?? array();
if (
	'chunked' !== ( $oversized_prepared['schema_transfer_mode'] ?? '' )
	|| empty( $oversized_prepared['source']['sha256'] )
	|| empty( $oversized_prepared['projection_eligible'] )
	|| 'governed_dispatch' !== ( $oversized_prepared['execution']['state'] ?? '' )
) {
	$fail( 'Oversized Ability did not remain available through lazy transport/dispatcher planning.', $oversized_prepared );
}
$read_dispatch = wp_get_ability( 'mad4b/read-execute' );
$oversized_identity = mad4b_test_prepared_dispatch_identity( $oversized_name );
if ( is_wp_error( $oversized_identity ) ) $fail( 'Oversized Ability signed preparation failed.', $oversized_identity->get_error_code() );
$oversized_execution = $read_dispatch->execute( array_merge(
    array( 'ability_name' => $oversized_name, 'input' => array() ),
    $oversized_identity
) );
if ( is_wp_error( $oversized_execution ) || empty( $oversized_execution['result']['ok'] ) ) {
    $fail( 'Oversized Ability could not actually execute through the governed dispatcher.', is_wp_error( $oversized_execution ) ? $oversized_execution->get_error_code() : $oversized_execution );
}
$drifted_identity = $oversized_identity;
$drifted_identity['expected_input_schema_sha256'] = str_repeat( '0', 64 );
$drifted_execution = $read_dispatch->execute( array_merge(
    array( 'ability_name' => $oversized_name, 'input' => array() ),
    $drifted_identity
) );
if ( ! is_wp_error( $drifted_execution ) || 'mad4b_read_dispatch_schema_drift' !== $drifted_execution->get_error_code() ) {
    $fail( 'Oversized dispatcher accepted a mismatched schema pin.' );
}
// A valid read lane never replaces the target's own permission decision.
$denied_read = wp_get_ability( 'mad4b-ci/denied-read-dispatch' );
$denied_identity = mad4b_test_prepared_dispatch_identity( 'mad4b-ci/denied-read-dispatch' );
if ( is_wp_error( $denied_identity ) ) $fail( 'Denied-read signed preparation failed.', $denied_identity->get_error_code() );
$denied_execution = $read_dispatch->execute( array_merge(
    array( 'ability_name' => 'mad4b-ci/denied-read-dispatch', 'input' => array() ),
    $denied_identity
) );
if ( ! is_wp_error( $denied_execution ) ) $fail( 'Read dispatcher ignored original permission denial.' );
$oversized_plan = MAD4B_SCP_ChatGPT_Tool_Projection::plan( array(
	'mode' => 'replace',
	'ability_names' => array( $oversized_name ),
	'include_breakglass' => false,
) );
if ( is_wp_error( $oversized_plan ) ) $fail( 'Oversized Ability projection planning failed unexpectedly.', $oversized_plan->get_error_code() );
if (
	! empty( $oversized_plan['ready_for_apply'] )
	|| ! in_array( $oversized_name, $oversized_plan['unprojectable_abilities'] ?? array(), true )
	|| ! in_array( $oversized_name, array_column( array_filter(
		$oversized_plan['mcp_preflight']['failures'] ?? array(),
		static function ( $failure ) { return is_array( $failure ) && 'mcp_optional_catalog_size_excluded' === ( $failure['error_code'] ?? '' ); }
	), 'failing_ability' ), true )
) {
	$fail( 'Oversized direct schema did not fail closed to dispatcher fallback.', $oversized_plan );
}
if (
	( $oversized_plan['mcp_preflight']['serialized_tool_bytes'] ?? PHP_INT_MAX ) > MAD4B_SCP_MCP_Catalog_Diagnostics::MAX_SERIALIZED_TOOL_BYTES
	|| 'bounded_serialized_tool_bytes' !== ( $oversized_plan['mcp_preflight']['size_policy'] ?? '' )
) {
	$fail( 'Direct catalog byte budget was not enforced.', $oversized_plan['mcp_preflight'] ?? array() );
}

$manifest = MAD4B_SCP_ChatGPT_Tool_Projection::discover( array( 'transport_action' => 'manifest', 'limit' => 1 ) );
if ( is_wp_error( $manifest ) || empty( $manifest['snapshot'] ) || empty( $manifest['items'][0]['schema_sha256'] ) ) $fail( 'Central manifest failed in real WordPress.' );
$schema = MAD4B_SCP_ChatGPT_Tool_Projection::discover( array( 'transport_action' => 'schema', 'snapshot' => $manifest['snapshot'], 'schema_sha256' => $manifest['items'][0]['schema_sha256'] ) );
if ( is_wp_error( $schema ) || ! isset( $schema['schema']->inputSchema ) ) $fail( 'Central schema retrieval failed in real WordPress.' );

// Registration-order tampering is already exercised by
// mad4b-ci/late-read-admission-fixture from runtime-chatgpt-projection-fixtures.php.
// That Ability is registered during the real Abilities lifecycle while a
// later same-priority PHP_INT_MAX filter replaces MAD4B's final wrapper.
// Planning must therefore keep it unprojectable with
// final_execution_admission_required rather than relying on filter order alone.

$raw_plan = MAD4B_SCP_ChatGPT_Tool_Projection::plan( array(
	'mode' => 'replace',
	'ability_names' => array( 'mad4b/database-raw-query' ),
	'include_breakglass' => false,
) );
if ( ! is_wp_error( $raw_plan ) || 'mad4b_chatgpt_projection_breakglass_explicit_opt_in_required' !== $raw_plan->get_error_code() ) {
	$fail( 'Breakglass projection did not require explicit opt-in.', $raw_plan );
}

$previous = get_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, false );
$read_row = $plan['desired_abilities'][0];
$fixture_state = array(
	'contract' => MAD4B_SCP_ChatGPT_Tool_Projection::CONTRACT,
	'revision' => 101,
	'abilities' => array( 'mad4b/diagnostics-health' => $read_row ),
	'updated_at' => gmdate( 'c' ),
	'last_plan_sha256' => (string) $plan['plan_sha256'],
	'binding' => MAD4B_SCP_ChatGPT_Tool_Projection::current_binding(),
);
update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $fixture_state, false );

$bounded_status = MAD4B_SCP_ChatGPT_Tool_Projection::status( array( 'limit' => 1, 'offset' => 0 ) );
if ( is_wp_error( $bounded_status ) ) $fail( 'Bounded projection status failed.', $bounded_status->get_error_code() );
if ( 1 !== (int) ( $bounded_status['stored_count'] ?? -1 )
	|| count( $bounded_status['abilities'] ?? array() ) > 1
	|| empty( $bounded_status['page']['snapshot'] )
	|| 64 !== strlen( (string) $bounded_status['page']['snapshot'] )
	|| ! array_key_exists( 'tool_count', $bounded_status['catalog_preflight'] ?? array() )
	|| array_key_exists( 'runtime_class_provenance', $bounded_status['catalog_preflight'] ?? array() ) ) {
	$fail( 'Projection status default page is not bounded.', $bounded_status );
}
$summary_only = MAD4B_SCP_ChatGPT_Tool_Projection::status( array( 'include_abilities' => false ) );
if ( is_wp_error( $summary_only ) || ! empty( $summary_only['abilities'] ) || ! empty( $summary_only['page']['abilities_included'] ) ) {
	$fail( 'Projection status summary-only mode returned Ability rows.', $summary_only );
}
$detail_status = MAD4B_SCP_ChatGPT_Tool_Projection::status( array( 'include_preflight_details' => true, 'limit' => 1 ) );
if ( is_wp_error( $detail_status )
	|| empty( $detail_status['page']['preflight_details_included'] )
	|| ! array_key_exists( 'tools', $detail_status['catalog_preflight_details'] ?? array() )
	|| count( $detail_status['catalog_preflight_details']['tools'] ?? array() ) > MAD4B_SCP_ChatGPT_Tool_Projection::STATUS_MAX_PREFLIGHT_TOOLS
	|| count( $detail_status['catalog_preflight_details']['failures'] ?? array() ) > MAD4B_SCP_ChatGPT_Tool_Projection::STATUS_MAX_PREFLIGHT_FAILURES ) {
	$fail( 'Projection status detail mode is not bounded.', $detail_status );
}
$snapshot_status = MAD4B_SCP_ChatGPT_Tool_Projection::status( array(
	'limit' => 1,
	'expected_snapshot' => (string) $bounded_status['page']['snapshot'],
) );
if ( is_wp_error( $snapshot_status ) ) $fail( 'Projection status rejected its current snapshot.', $snapshot_status->get_error_code() );
$stale_status = MAD4B_SCP_ChatGPT_Tool_Projection::status( array(
	'limit' => 1,
	'expected_snapshot' => str_repeat( '0', 64 ),
) );
if ( ! is_wp_error( $stale_status ) || 'mad4b_chatgpt_projection_status_page_stale' !== $stale_status->get_error_code() ) {
	$fail( 'Projection status paging accepted a stale snapshot.', $stale_status );
}

try {
	// Certification shares the projection's exact schema/classification and
	// callback fence proof, without executing the selected mutation.
	$write_name = 'mad4b/plugin-package-apply';
	$write_state = $fixture_state;
	$write_state['abilities'] = array( $write_name => $write_plan['desired_abilities'][0] );
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $write_state, false );
	if ( array( $write_name ) !== MAD4B_SCP_ChatGPT_Tool_Projection::governed_write_projection_names() ) $fail( 'A current fenced mutation projection was classified as an ungoverned schema leak.' );
	foreach ( array( 'input_schema_sha256', 'classification_sha256' ) as $pin ) {
		$stale_write = $write_state; $stale_write['abilities'][ $write_name ][ $pin ] = str_repeat( '0', 64 );
		update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $stale_write, false );
		if ( MAD4B_SCP_ChatGPT_Tool_Projection::governed_write_projection_names() ) $fail( 'Stale mutation projection was treated as governed exposure: ' . $pin );
	}
	$foreign_write = $write_state; $foreign_write['binding']['site_uuid'] = 'foreign-site';
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $foreign_write, false );
	if ( MAD4B_SCP_ChatGPT_Tool_Projection::governed_write_projection_names() ) $fail( 'Foreign-site mutation projection was treated as governed exposure.' );
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $fixture_state, false );
	if ( MAD4B_SCP_ChatGPT_Tool_Projection::governed_write_projection_names() ) $fail( 'A read hot-set member became write certification evidence.' );

	$projected = MAD4B_SCP_ChatGPT_Tool_Projection::projected_ability_names();
	if ( ! in_array( 'mad4b/diagnostics-health', $projected, true ) ) $fail( 'Schema-pinned dynamic projection did not become effective.', $projected );

	$final = MAD4B_SCP_Servers::chatgpt_tools();
	if ( ! in_array( 'mad4b/diagnostics-health', $final, true ) ) $fail( 'Dynamic Ability was not composed into ChatGPT tools/list candidates.', $final );
	if ( 'dynamic_projection' !== MAD4B_SCP_Servers::provider_for_ability( 'mad4b-chatgpt', 'mad4b/diagnostics-health' ) ) {
		$fail( 'Dynamic projection provider identity was not preserved.' );
	}

	$optional = MAD4B_SCP_MCP_Catalog_Diagnostics::optional_projections( $final );
	if ( ! in_array( 'mad4b/diagnostics-health', $optional, true ) ) $fail( 'Dynamic projection was not classified as optional for isolation.', $optional );
	$preflight = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( $final, $optional );
	if ( empty( $preflight['ready'] ) || ! in_array( 'mad4b/diagnostics-health', $preflight['tools'], true ) ) {
		$fail( 'Dynamic projection did not survive exact MCP preflight.', $preflight );
	}

	// A cached tool must lose admission after demotion or site binding drift.
	$server_fixture = new class { public function get_server_id() { return 'mad4b-chatgpt'; } };
	$read_tool = \WP\MCP\Domain\Tools\McpTool::fromAbility( wp_get_ability( 'mad4b/diagnostics-health' ) );
	if ( is_wp_error( $read_tool ) ) $fail( 'Official read tool fixture failed.' );
	$identity = MAD4B_SCP_ChatGPT_Tool_Projection::callback_identity( $read_tool );
	if ( ! is_array( $identity ) || 2 !== count( $identity ) || ! is_callable( $identity[0] ) || ! is_callable( $identity[1] ) || ! MAD4B_SCP_ChatGPT_Tool_Projection::materialized_tool_matches( $read_tool ) ) $fail( 'Upstream callback identity compatibility certification failed.' );
	$detached = clone $read_tool;
	$bound_property = ( new ReflectionObject( $detached ) )->getProperty( 'ability' ); $bound_property->setAccessible( true );
	$bound = clone $bound_property->getValue( $detached ); $bound_property->setValue( $detached, $bound );
	foreach ( array( 'execute_callback', 'permission_callback' ) as $callback_property ) {
		$property = ( new ReflectionObject( $bound ) )->getProperty( $callback_property ); $property->setAccessible( true ); $original = $property->getValue( $bound );
		try {
			$property->setValue( $bound, static function() { return true; } );
			if ( MAD4B_SCP_ChatGPT_Tool_Projection::materialized_tool_matches( $detached ) ) $fail( 'Same-schema callback identity substitution admitted: ' . $callback_property );
		} finally { $property->setValue( $bound, $original ); }
	}
	if ( null !== MAD4B_SCP_ChatGPT_Tool_Projection::callback_identity( new stdClass() ) || MAD4B_SCP_ChatGPT_Tool_Projection::materialized_tool_matches( new stdClass() ) ) $fail( 'Unknown upstream internals did not fail closed.' );
	echo "PASS upstream callback identity certification: positive baseline, execute/permission substitution and unknown-layout denial\n";

	$target = wp_get_ability( 'mad4b/diagnostics-health' ); $schema_property = ( new ReflectionObject( $target ) )->getProperty( 'input_schema' ); $schema_property->setAccessible( true ); $old_schema = $schema_property->getValue( $target );
	try {
		$schema_property->setValue( $target, array( 'type' => 'object', 'properties' => array( 'changed' => array( 'type' => 'integer' ) ) ) );
		if ( MAD4B_SCP_ChatGPT_Tool_Projection::materialized_tool_matches( $read_tool ) ) $fail( 'Stale materialized DTO admitted.' );
	} finally { $schema_property->setValue( $target, $old_schema ); }

	$allowed = MAD4B_SCP_ChatGPT_Tool_Projection::guard_tool_call( null, '', $read_tool, $server_fixture );
	if ( is_wp_error( $allowed ) ) $fail( 'Read projection admission unexpectedly denied.', $allowed->get_error_code() );
	if ( ! class_exists( 'MAD4B_SCP_Execution_Fence' ) || ! MAD4B_SCP_Execution_Fence::final_execution_wrapper_verified( 'mad4b/diagnostics-health' ) ) {
		$fail( 'Projected read Ability is missing its final execution-admission wrapper.' );
	}
	$tampered = MAD4B_SCP_Execution_Fence::consume_projected_call( 'mad4b/diagnostics-health', array( 'changed_after_guard' => true ) );
	if ( ! is_wp_error( $tampered ) || 'mad4b_projection_execution_seal_mismatch' !== $tampered->get_error_code() ) {
		$fail( 'Projected call argument mutation after final guard was not rejected.', $tampered );
	}

	$metadata_seal = MAD4B_SCP_ChatGPT_Tool_Projection::guard_tool_call( null, '', $read_tool, $server_fixture );
	if ( is_wp_error( $metadata_seal ) ) $fail( 'Metadata-drift seal fixture could not mint a fresh seal.', $metadata_seal->get_error_code() );
	$metadata_target = wp_get_ability( 'mad4b/diagnostics-health' );
	$metadata_property = new ReflectionProperty( 'WP_Ability', 'meta' );
	$metadata_property->setAccessible( true );
	$metadata_original = $metadata_property->getValue( $metadata_target );
	try {
		$metadata_changed = $metadata_original;
		$metadata_changed['annotations']['readonly'] = false;
		$metadata_property->setValue( $metadata_target, $metadata_changed );
		$metadata_tampered = MAD4B_SCP_Execution_Fence::consume_projected_call( 'mad4b/diagnostics-health', null );
		if ( ! is_wp_error( $metadata_tampered ) ) {
			$fail( 'Projected metadata mutation after final guard was not rejected.', $metadata_tampered );
		}
	} finally {
		$metadata_property->setValue( $metadata_target, $metadata_original );
	}

	$request_fixture = new class { public function get_route() { return '/mcp/mad4b-chatgpt'; } };
	$bound = MAD4B_SCP_Transport_Context::bind( 'mad4b-chatgpt', $request_fixture );
	if ( is_wp_error( $bound ) ) $fail( 'ChatGPT transport fixture could not bind for final execution seal proof.', $bound->get_error_code() );
	$allowed_again = MAD4B_SCP_ChatGPT_Tool_Projection::guard_tool_call( null, '', $read_tool, $server_fixture );
	if ( is_wp_error( $allowed_again ) ) $fail( 'Second projected admission could not mint a fresh one-time seal.', $allowed_again->get_error_code() );
	$projected_execution = wp_get_ability( 'mad4b/diagnostics-health' )->execute( null );
	if ( is_wp_error( $projected_execution ) ) $fail( 'Fresh projected execution seal did not survive the final callback wrapper.', $projected_execution->get_error_code() );
	$replayed_execution = wp_get_ability( 'mad4b/diagnostics-health' )->execute( null );
	if ( ! is_wp_error( $replayed_execution ) || 'mad4b_projection_execution_seal_required' !== $replayed_execution->get_error_code() ) {
		$fail( 'Projected execution seal was reusable or absent final callback enforcement.', $replayed_execution );
	}

	// A stale client may retain the old DTO after another actor removes the hot
	// set entry. Visibility is not authority: the cached direct call must fail,
	// while exact governed fixed dispatch for the same Ability remains valid.
	$removed_hotset = $fixture_state;
	$removed_hotset['revision'] = (int) $fixture_state['revision'] + 1;
	$removed_hotset['abilities'] = array();
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $removed_hotset, false );
	$cached_removed = MAD4B_SCP_ChatGPT_Tool_Projection::guard_tool_call( null, '', $read_tool, $server_fixture );
	if ( ! is_wp_error( $cached_removed ) || 'mad4b_projection_not_selected' !== $cached_removed->get_error_code() ) {
		$fail( 'Cached client executed or admitted an Ability removed from the site hot set.', $cached_removed );
	}
	$removed_fixed_identity = mad4b_test_prepared_dispatch_identity( 'mad4b/diagnostics-health' );
	if ( is_wp_error( $removed_fixed_identity ) ) $fail( 'Removed-hot-set fixed-dispatch preparation failed.', $removed_fixed_identity->get_error_code() );
	$fixed_dispatch = wp_get_ability( 'mad4b/read-execute' );
	if ( ! is_object( $fixed_dispatch ) ) $fail( 'Governed fixed read dispatcher Ability is unavailable.' );
	$removed_fixed = $fixed_dispatch->execute( array_merge(
		array( 'ability_name' => 'mad4b/diagnostics-health', 'input' => array() ),
		$removed_fixed_identity
	) );
	if ( is_wp_error( $removed_fixed ) ) $fail( 'Hot-set removal changed fixed-dispatch correctness for the same Ability.', $removed_fixed->get_error_code() );
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $fixture_state, false );
	MAD4B_SCP_Transport_Context::clear();

	// A later same-priority pre-tool filter may try to erase our denial, but it
	// cannot mint the private one-time callback seal.
	$copied_for_late_filter = $fixture_state;
	$copied_for_late_filter['binding']['origin'] = 'https://late-filter.invalid';
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $copied_for_late_filter, false );
	$late_pre_tool = static function ( $value ) { return is_wp_error( $value ) ? array() : $value; };
	add_filter( 'mcp_adapter_pre_tool_call', $late_pre_tool, PHP_INT_MAX, 4 );
	$overridden = apply_filters( 'mcp_adapter_pre_tool_call', null, '', $read_tool, $server_fixture );
	if ( is_wp_error( $overridden ) ) $fail( 'Late-filter attack fixture did not override the earlier filter result as intended.' );
	$bound = MAD4B_SCP_Transport_Context::bind( 'mad4b-chatgpt', $request_fixture );
	if ( is_wp_error( $bound ) ) $fail( 'ChatGPT transport fixture could not bind for late-filter proof.', $bound->get_error_code() );
	$late_bypass = wp_get_ability( 'mad4b/diagnostics-health' )->execute( null );
	if ( ! is_wp_error( $late_bypass ) || 'mad4b_projection_execution_seal_required' !== $late_bypass->get_error_code() ) {
		$fail( 'Later same-priority pre-tool filter bypassed final projected execution admission.', $late_bypass );
	}
	MAD4B_SCP_Transport_Context::clear();
	remove_filter( 'mcp_adapter_pre_tool_call', $late_pre_tool, PHP_INT_MAX );
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $fixture_state, false );
	$copied = $fixture_state;
	$copied['binding']['origin'] = 'https://different.invalid';
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $copied, false );
	$denied = MAD4B_SCP_ChatGPT_Tool_Projection::guard_tool_call( null, '', $read_tool, $server_fixture );
	if ( ! is_wp_error( $denied ) || 'mad4b_projection_binding_mismatch' !== $denied->get_error_code() ) $fail( 'Copied-site binding did not block a cached tool call.' );
	if ( MAD4B_SCP_ChatGPT_Tool_Projection::projected_ability_names() ) $fail( 'Copied-site registry remained effective.' );
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $fixture_state, false );

	// The database CAS rejects a second writer using the same original state.
	$cas = new ReflectionMethod( 'MAD4B_SCP_ChatGPT_Tool_Projection', 'persist_compare_and_swap' );
	$cas->setAccessible( true );
	$winner = $fixture_state;
	$winner['revision']++;
	if ( true !== $cas->invoke( null, $fixture_state, $winner ) ) $fail( 'First exact CAS writer failed.' );
	$loser = $winner;
	$loser['abilities'] = array();
	$raced = $cas->invoke( null, $fixture_state, $loser );
	if ( ! is_wp_error( $raced ) || 'mad4b_projection_concurrent_update' !== $raced->get_error_code() ) $fail( 'Stale writer overwrote the winning projection.' );
	if ( get_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION ) !== $winner ) $fail( 'CAS loser changed stored state.' );
	// A different session can replace the site's hot set; stable dispatcher
	// execution must remain independent of that semantic catalog contention.
	$session_b = $winner;
	$session_b['abilities'] = array();
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $session_b, false );
	$fixed_name = 'mad4b-ci/readonly-projection-fixture';
	$fixed_identity = mad4b_test_prepared_dispatch_identity( $fixed_name );
	if ( is_wp_error( $fixed_identity ) ) $fail( 'Fixed-dispatch signed preparation failed.', $fixed_identity->get_error_code() );
	$stable_dispatch = ( new MAD4B_SCP_Abilities() )->read_execute( array_merge(
		array( 'ability_name' => $fixed_name, 'input' => array() ),
		$fixed_identity
	) );
	if ( is_wp_error( $stable_dispatch ) || empty( $stable_dispatch['result']['ok'] ) ) $fail( 'Another session hot-set replacement disrupted fixed dispatch.' );

	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $fixture_state, false );

	// Selecting a required base tool cannot turn it into an optional eviction candidate.
	$base_plan = MAD4B_SCP_ChatGPT_Tool_Projection::plan( array( 'ability_names' => array( 'mad4b/site-info' ) ) );
	if ( is_wp_error( $base_plan ) ) $fail( 'Required base projection plan failed.' );
	$overlap = $fixture_state;
	$overlap['abilities']['mad4b/site-info'] = $base_plan['desired_abilities'][0];
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $overlap, false );
	if ( in_array( 'mad4b/site-info', MAD4B_SCP_MCP_Catalog_Diagnostics::optional_projections( MAD4B_SCP_Servers::chatgpt_tools() ), true ) ) $fail( 'Required base tool became optional through projection overlap.' );
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $fixture_state, false );

	// Plans are bound to the registry revision, even when the desired set is unchanged.
	$reviewed = MAD4B_SCP_ChatGPT_Tool_Projection::plan( array( 'ability_names' => array( 'mad4b/diagnostics-health' ) ) );
	$next_fixture = $fixture_state;
	$next_fixture['revision']++;
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $next_fixture, false );
	$replanned = MAD4B_SCP_ChatGPT_Tool_Projection::plan( array( 'ability_names' => array( 'mad4b/diagnostics-health' ) ) );
	if ( $reviewed['plan_sha256'] === $replanned['plan_sha256'] ) $fail( 'Registry revision drift did not invalidate the reviewed plan.' );
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $fixture_state, false );

	// Same schema with changed authority metadata must also deactivate the projection.
	$ability = wp_get_ability( 'mad4b/diagnostics-health' );
	$meta_property = new ReflectionProperty( 'WP_Ability', 'meta' );
	$meta_property->setAccessible( true );
	$original_meta = $meta_property->getValue( $ability );
	try {
		$changed_meta = $original_meta;
		$changed_meta['annotations']['readonly'] = false;
		$meta_property->setValue( $ability, $changed_meta );
		if ( MAD4B_SCP_ChatGPT_Tool_Projection::is_projected( 'mad4b/diagnostics-health' ) ) $fail( 'Authority classification drift remained effective.' );
	} finally {
		$meta_property->setValue( $ability, $original_meta );
	}

	// Schema pinning: any input schema drift disables only this projection.
	$ability = wp_get_ability( 'mad4b/diagnostics-health' );
	$schema_property = new ReflectionProperty( 'WP_Ability', 'input_schema' );
	$schema_property->setAccessible( true );
	$original_schema = $schema_property->getValue( $ability );
	try {
		$schema_property->setValue( $ability, array(
			'type' => 'object',
			'properties' => array( 'drift' => array( 'type' => 'string' ) ),
			'additionalProperties' => false,
		) );
		if ( in_array( 'mad4b/diagnostics-health', MAD4B_SCP_ChatGPT_Tool_Projection::projected_ability_names(), true ) ) {
			$fail( 'Schema drift did not deactivate the pinned dynamic projection.' );
		}
		if ( in_array( 'mad4b/diagnostics-health', MAD4B_SCP_Servers::chatgpt_tools(), true ) ) {
			$fail( 'Schema-drifted projection remained in ChatGPT tools/list candidates.' );
		}
	} finally {
		$schema_property->setValue( $ability, $original_schema );
	}
} finally {
	if ( false === $previous ) delete_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION );
	else update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $previous, false );
}


$mad4b_real_runtime_fixture = static function ( $file ) {
	require $file;
};
foreach ( array(
	'runtime-database-transaction-guard.php',
	'runtime-request-generation.php',
	'runtime-execution-fence-recursion.php',
	'runtime-compatibility-profile.php',
) as $mad4b_runtime_fixture ) {
	$mad4b_real_runtime_fixture( __DIR__ . '/' . $mad4b_runtime_fixture );
}

$status = MAD4B_SCP_ChatGPT_Tool_Projection::status();
if ( empty( $status['read_only'] ) || ! empty( $status['mutation_performed'] ) || ! empty( $status['projection_changes_authority'] ) ) {
	$fail( 'Projection status changed its non-authorizing read boundary.', $status );
}

fwrite(
	STDOUT,
	'mad4b.chatgpt-dynamic-tool-projection.v1: PASS ' .
	wp_json_encode(
		array(
			'universe_count' => count( $all ),
			'base_tool_count' => count( $base ),
			'read_projection_verified' => true,
			'mutation_projection_classified' => true,
			'unclassified_ability_visible_fail_closed_verified' => true,
			'schema_drift_fail_closed' => true,
			'breakglass_opt_in_required' => true,
			'oversized_schema_dispatcher_fallback_verified' => true,
		),
		JSON_UNESCAPED_SLASHES
	) . PHP_EOL
);
