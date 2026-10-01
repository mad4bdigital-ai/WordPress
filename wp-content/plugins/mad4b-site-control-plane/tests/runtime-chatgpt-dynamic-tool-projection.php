<?php
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );

$fail = static function ( $message, $data = null ) {
	throw new RuntimeException( $message . ( null !== $data ? ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) : '' ) );
};

if ( ! class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) ) $fail( 'Dynamic ChatGPT tool projection registry is unavailable.' );
if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) $fail( 'MCP server registry is unavailable.' );
if ( ! class_exists( 'MAD4B_SCP_MCP_Catalog_Diagnostics' ) ) $fail( 'MCP catalog diagnostics are unavailable.' );

if ( ! wp_has_ability( 'mad4b-ci/unclassified-projection-fixture' ) ) {
	$registry = class_exists( 'WP_Abilities_Registry' ) ? WP_Abilities_Registry::get_instance() : null;
	if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) $fail( 'WordPress Ability registry is unavailable for the runtime fixture.' );
	$registered_fixture = $registry->register( 'mad4b-ci/unclassified-projection-fixture', array(
		'label' => 'Unclassified Projection Fixture',
		'description' => 'CI fixture proving a registered third-party-style Ability without annotations.readonly can still be projected conservatively.',
		'category' => 'mad4b-read',
		'execute_callback' => static function ( $input = null ) { unset( $input ); return array( 'ok' => true ); },
		'permission_callback' => static function () { return true; },
		'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
		'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
		'meta' => array( 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ) ),
	) );
	if ( ! is_object( $registered_fixture ) || ! wp_has_ability( 'mad4b-ci/unclassified-projection-fixture' ) ) {
		$fail( 'Unclassified projection fixture could not be registered.' );
	}
}

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

$base = MAD4B_SCP_Servers::chatgpt_base_tools();
if ( in_array( 'mad4b/diagnostics-health', $base, true ) ) $fail( 'Fixture Ability unexpectedly belongs to the stable base tools/list.' );

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
);
update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $fixture_state, false );

try {
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
		),
		JSON_UNESCAPED_SLASHES
	) . PHP_EOL
);
