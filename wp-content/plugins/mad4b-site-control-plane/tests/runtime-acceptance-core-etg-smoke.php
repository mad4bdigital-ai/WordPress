<?php
/** Exact runtime proof for the generic MAD4B Acceptance Core with ETG provider discovery. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };

$check( current_user_can( 'manage_options' ), 'Acceptance runtime smoke requires an administrator.' );
$check( class_exists( 'MAD4B_SCP_Acceptance_Core' ), 'MAD4B Acceptance Core class is unavailable.' );
$check( class_exists( '\\ETG\\DynamicFilterSEOBridge\\Acceptance\\LiveAcceptanceProvider' ), 'Exact ETG Acceptance Provider is unavailable.' );

$full_chatgpt_candidates = MAD4B_SCP_Servers::chatgpt_full_catalog_candidates();
$direct_chatgpt_tools = MAD4B_SCP_Servers::chatgpt_tools();
$read_dispatch = wp_get_ability( 'mad4b/read-execute' );
$check( is_object( $read_dispatch ) && method_exists( $read_dispatch, 'execute' ), 'Governed ChatGPT readonly dispatcher is unavailable.' );
$dispatch_read = static function ( $ability_name, array $input = array() ) use ( $check, $read_dispatch ) {
	$result = $read_dispatch->execute( array( 'ability_name' => (string) $ability_name, 'input' => $input ) );
	$check( ! is_wp_error( $result ), 'Readonly dispatcher failed for ' . $ability_name . ( is_wp_error( $result ) ? ': ' . $result->get_error_code() : '' ) );
	$check( 'mad4b.chatgpt-read-execute.v1' === (string) ( $result['contract'] ?? '' ), 'Unexpected readonly dispatcher contract for ' . $ability_name );
	$check( ! empty( $result['read_only'] ) && empty( $result['mutation_performed'] ), 'Readonly dispatcher authority boundary drifted for ' . $ability_name );
	$check( isset( $result['result'] ) && is_array( $result['result'] ), 'Readonly dispatcher returned no structured target result for ' . $ability_name );
	return $result['result'];
};

$ability_names = array( 'mad4b/acceptance-capabilities', 'mad4b/acceptance-plan', 'mad4b/acceptance-run', 'mad4b/acceptance-result' );
$write_tools = MAD4B_SCP_Servers::write_tools();
foreach ( $ability_names as $name ) {
	$check( wp_has_ability( $name ), 'Missing Acceptance Core ability: ' . $name );
	$ability = wp_get_ability( $name );
	$check( is_object( $ability ) && method_exists( $ability, 'execute' ), 'Acceptance Core ability is not callable: ' . $name );
	$meta = $ability->get_meta();
	$check( true === ( isset( $meta['annotations']['readonly'] ) ? $meta['annotations']['readonly'] : null ), 'Acceptance ability is not readonly: ' . $name );
	$check( false === ( isset( $meta['annotations']['destructive'] ) ? $meta['annotations']['destructive'] : null ), 'Acceptance ability is destructive: ' . $name );
	$check( empty( $meta['public'] ) && empty( $meta['mcp']['public'] ), 'Acceptance ability leaked to default/public MCP: ' . $name );
	$check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', $name ), 'Acceptance ability is not mounted on mad4b-read: ' . $name );
	$check( in_array( $name, $full_chatgpt_candidates, true ), 'Acceptance ability was lost from governed ChatGPT discovery: ' . $name );
	$check( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $name ), 'Acceptance heavy read schema leaked directly into ChatGPT tools/list: ' . $name );
	$check( ! in_array( $name, $direct_chatgpt_tools, true ), 'Direct ChatGPT projection leaked Acceptance schema: ' . $name );
	$check( ! in_array( $name, $write_tools, true ), 'Acceptance ability leaked into write_tools(): ' . $name );
	foreach ( array( 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' ) as $server ) {
		$check( ! MAD4B_SCP_Servers::ability_is_mounted( $server, $name ), 'Acceptance read ability leaked to ' . $server . ': ' . $name );
	}
}

$capabilities = $dispatch_read( 'mad4b/acceptance-capabilities' );
$check( ! is_wp_error( $capabilities ), 'Acceptance capabilities execution failed.' );
$check( 'mad4b.acceptance-capabilities.v1' === (string) $capabilities['contract'], 'Unexpected Acceptance capabilities contract.' );
$check( ! empty( $capabilities['read_only'] ) && empty( $capabilities['authorizing'] ) && empty( $capabilities['transport_authority'] ), 'Acceptance Core authority boundary drifted.' );
$etg = null;
foreach ( (array) $capabilities['providers'] as $provider ) {
	if ( 'etg-dfsb' === (string) ( $provider['provider_id'] ?? '' ) ) { $etg = $provider; break; }
}
$check( is_array( $etg ), 'ETG Acceptance Provider was not discovered through the generic registry.' );
$check( 'etg.dfsb.live-acceptance-provider.v1' === (string) $etg['contract'], 'ETG Acceptance Provider contract drifted.' );
$descriptor = (array) $etg['descriptor'];
$check( ! empty( $descriptor['read_only'] ) && empty( $descriptor['authorizing'] ) && empty( $descriptor['profile_mutation'] ) && empty( $descriptor['transport_owned_by_provider'] ), 'ETG provider descriptor opened authority.' );
$effects = isset( $descriptor['effects'] ) && is_array( $descriptor['effects'] ) ? $descriptor['effects'] : array();
foreach ( array( 'business_state_mutation', 'authority_mutation', 'seo_mutation', 'profile_mutation', 'observational_persistence' ) as $key ) {
	$check( array_key_exists( $key, $effects ) && false === $effects[ $key ], 'ETG provider effect boundary drifted: ' . $key );
}

$blocked = $dispatch_read( 'mad4b/acceptance-plan', array( 'provider_id' => 'etg-dfsb', 'profile_id' => 'tours', 'url' => 'https://example.invalid' ) );
$check( ! is_wp_error( $blocked ), 'Acceptance plan rejected bounded failure as transport error.' );
$check( 'blocked' === (string) $blocked['state'], 'Arbitrary input did not fail closed.' );
$check( in_array( 'unsupported_request_fields', (array) $blocked['blocking_reasons'], true ), 'Arbitrary URL rejection reason was not preserved.' );

$plan = $dispatch_read( 'mad4b/acceptance-plan', array( 'provider_id' => 'etg-dfsb', 'profile_id' => 'tours', 'suite' => 'semantic' ) );
$check( ! is_wp_error( $plan ), 'Governed ETG acceptance plan execution failed.' );
$check( 'mad4b.acceptance-plan.v1' === (string) $plan['contract'], 'Unexpected MAD4B acceptance plan contract.' );
$check( 'etg-dfsb' === (string) $plan['provider_id'], 'Acceptance plan provider identity drifted.' );
$check( empty( $plan['authorizing'] ) && ! empty( $plan['read_only'] ), 'Acceptance plan authority boundary drifted.' );
$check( in_array( (string) $plan['state'], array( 'ready', 'blocked' ), true ), 'Acceptance plan returned an invalid state.' );

$run = $dispatch_read( 'mad4b/acceptance-run', array( 'provider_id' => 'etg-dfsb', 'profile_id' => 'tours', 'suite' => 'semantic' ) );
$check( ! is_wp_error( $run ), 'Governed ETG acceptance run execution failed.' );
$check( 'mad4b.acceptance-run.v1' === (string) $run['contract'], 'Unexpected MAD4B acceptance run contract.' );
$check( empty( $run['authorizing'] ) && ! empty( $run['read_only'] ), 'Acceptance run authority boundary drifted.' );
$authority = isset( $run['authority'] ) && is_array( $run['authority'] ) ? $run['authority'] : array();
foreach ( array( 'authorizing', 'persistent_mutation', 'profile_mutation', 'seo_publication', 'production_activation' ) as $key ) {
	$check( array_key_exists( $key, $authority ) && false === $authority[ $key ], 'Acceptance run opened authority: ' . $key );
}
$result = (array) $run['result'];
$check( 'mad4b.acceptance-result.v1' === (string) $result['contract'], 'Unexpected canonical acceptance result contract.' );
$check( false === (bool) $result['verification']['browser_runtime_parity_verified'], 'Server-side acceptance falsely claimed Browser Runtime verification.' );
$check( in_array( (string) $result['verdict'], array( 'PASS', 'FAIL', 'BLOCKED', 'INCOMPLETE_EVIDENCE', 'STALE', 'NOT_APPLICABLE' ), true ), 'Canonical acceptance verdict is invalid.' );

$canonical = $dispatch_read( 'mad4b/acceptance-result', array( 'provider_id' => 'etg-dfsb', 'profile_id' => 'tours', 'suite' => 'semantic' ) );
$check( ! is_wp_error( $canonical ), 'Canonical acceptance-result execution failed.' );
$check( 'mad4b.acceptance-result.v1' === (string) $canonical['contract'], 'Acceptance-result did not return canonical reducer output.' );
$check( 'etg-dfsb' === (string) $canonical['provider_id'], 'Canonical result omitted provider identity.' );
$check( false === (bool) $canonical['verification']['browser_runtime_parity_verified'], 'Canonical result falsely claimed Browser Runtime verification.' );

$status_ability = wp_get_ability( 'mad4b/live-acceptance-status' );
$check( is_object( $status_ability ) && method_exists( $status_ability, 'execute' ) && method_exists( $status_ability, 'get_input_schema' ), 'Existing live-acceptance-status ability is missing.' );
$status_schema = (array) $status_ability->get_input_schema();
$check( 'object' === (string) ( $status_schema['type'] ?? '' ), 'Existing live-acceptance-status input contract changed type.' );
$check( isset( $status_schema['properties']['client_snapshot_token'] ), 'Existing live-acceptance-status lost optional client snapshot input.' );
$check( false === ( $status_schema['additionalProperties'] ?? null ), 'Existing live-acceptance-status input boundary changed.' );
$status = $status_ability->execute( array() );
$check( ! is_wp_error( $status ), 'Existing live-acceptance-status became unavailable.' );
$check( 'mad4b.live-acceptance-status.v1' === (string) $status['contract'], 'Acceptance Core redefined the existing aggregate live-acceptance contract.' );

echo "mad4b.site-control-plane.acceptance-core-etg-runtime.v2: PASS\n";
