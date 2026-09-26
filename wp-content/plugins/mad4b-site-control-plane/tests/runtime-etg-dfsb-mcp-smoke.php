<?php
/** Exact Alpha13 runtime proof for the read-only ETG DFSB MCP adapter. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };

$check( current_user_can( 'manage_options' ), 'ETG MCP runtime smoke requires an administrator.' );
$check( defined( 'ETG_DFSB_VERSION' ) && '0.4.0-alpha.13' === ETG_DFSB_VERSION, 'Exact ETG Alpha13 runtime is not active.' );
$check( class_exists( 'MAD4B_SCP_ETG_DFSB_Adapter' ), 'MAD4B ETG adapter class is unavailable.' );

$registry = MAD4B_SCP_Adapter_Registry::instance();
$adapter = $registry->get( 'etg-dfsb' );
$check( $adapter instanceof MAD4B_SCP_ETG_DFSB_Adapter, 'ETG adapter was not registered.' );
$check( $adapter->is_available(), 'ETG adapter did not accept the exact Alpha13 service contract.' );

$full_chatgpt_candidates = MAD4B_SCP_Servers::chatgpt_full_catalog_candidates();
$direct_chatgpt_tools = MAD4B_SCP_Servers::chatgpt_tools();
$read_dispatch = wp_get_ability( 'mad4b/read-execute' );
$check( is_object( $read_dispatch ) && method_exists( $read_dispatch, 'execute' ), 'Governed ChatGPT readonly dispatcher is unavailable.' );

$dispatch_read = static function ( $ability_name, array $input = array() ) use ( $check, $read_dispatch ) {
	$result = $read_dispatch->execute(
		array(
			'ability_name' => (string) $ability_name,
			'input' => $input,
		)
	);
	$check( ! is_wp_error( $result ), 'Governed readonly dispatcher failed for ' . $ability_name . ( is_wp_error( $result ) ? ': ' . $result->get_error_code() : '' ) );
	$check( 'mad4b.chatgpt-read-execute.v1' === (string) ( $result['contract'] ?? '' ), 'Unexpected readonly dispatcher contract for ' . $ability_name );
	$check( ! empty( $result['read_only'] ) && empty( $result['mutation_performed'] ), 'Readonly dispatcher authority boundary drifted for ' . $ability_name );
	$check( isset( $result['result'] ) && is_array( $result['result'] ), 'Readonly dispatcher returned no structured target result for ' . $ability_name );
	return $result['result'];
};

$mad4b_owned_read_abilities = array(
	'etg-dfsb/status',
	'etg-dfsb/build-identity',
	'etg-dfsb/configuration',
	'etg-dfsb/runtime-inventory',
	'etg-dfsb/profiles',
	'etg-dfsb/profile-blueprint',
	'etg-dfsb/profile-plan',
	'etg-dfsb/content-catalog',
);
foreach ( $mad4b_owned_read_abilities as $name ) {
	$check( wp_has_ability( $name ), 'Missing ETG MCP ability: ' . $name );
	$ability = wp_get_ability( $name );
	$meta = $ability->get_meta();
	$check( ! empty( $meta['annotations']['readonly'] ), 'ETG ability is not readonly: ' . $name );
	$check( empty( $meta['annotations']['destructive'] ), 'ETG ability is marked destructive: ' . $name );
	$check( empty( $meta['public'] ) && empty( $meta['mcp']['public'] ), 'MAD4B-owned ETG ability leaked to default/public MCP: ' . $name );
	$check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', $name ), 'ETG ability is not mounted on mad4b-read: ' . $name );
	$check( in_array( $name, $full_chatgpt_candidates, true ), 'ETG ability was lost from the governed ChatGPT read universe: ' . $name );
	$check( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $name ), 'ETG heavy read schema leaked directly into ChatGPT tools/list: ' . $name );
	$check( ! in_array( $name, $direct_chatgpt_tools, true ), 'Canonical direct ChatGPT projection leaked ETG heavy read schema: ' . $name );
	foreach ( array( 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' ) as $server ) {
		$check( ! MAD4B_SCP_Servers::ability_is_mounted( $server, $name ), 'ETG read ability leaked to ' . $server . ': ' . $name );
	}
}

$native_evidence_abilities = array( 'etg-dfsb/evidence-provider', 'etg-dfsb/evidence-query' );
$write_tools = MAD4B_SCP_Servers::write_tools();
foreach ( $native_evidence_abilities as $name ) {
	$check( wp_has_ability( $name ), 'Missing native ETG evidence ability: ' . $name );
	$ability = wp_get_ability( $name );
	$check( is_object( $ability ) && method_exists( $ability, 'execute' ), 'Native ETG evidence ability is not callable: ' . $name );
	$meta = $ability->get_meta();
	$check( true === ( isset( $meta['annotations']['readonly'] ) ? $meta['annotations']['readonly'] : null ), 'Native ETG evidence ability is not readonly: ' . $name );
	$check( false === ( isset( $meta['annotations']['destructive'] ) ? $meta['annotations']['destructive'] : null ), 'Native ETG evidence ability is destructive or unbounded: ' . $name );
	$check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', $name ), 'Native ETG evidence ability is not mounted on mad4b-read: ' . $name );
	$check( in_array( $name, $full_chatgpt_candidates, true ), 'Native ETG evidence ability was lost from governed ChatGPT discovery: ' . $name );
	$check( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $name ), 'Native ETG evidence schema leaked directly into ChatGPT tools/list: ' . $name );
	$check( ! in_array( $name, $direct_chatgpt_tools, true ), 'Direct ChatGPT transport leaked native ETG evidence schema: ' . $name );
	$check( ! in_array( $name, $write_tools, true ), 'Native ETG evidence ability leaked into write_tools(): ' . $name );
	foreach ( array( 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' ) as $server ) {
		$check( ! MAD4B_SCP_Servers::ability_is_mounted( $server, $name ), 'Native ETG evidence ability leaked to ' . $server . ': ' . $name );
	}
}

$descriptor = $dispatch_read( 'etg-dfsb/evidence-provider' );
$check( ! is_wp_error( $descriptor ), 'Native ETG evidence-provider execution failed.' );
$check( 'etg.dfsb.evidence-provider.v1' === (string) $descriptor['contract'], 'Unexpected native ETG evidence-provider contract.' );
$check( 'etg-dfsb' === (string) $descriptor['provider_id'], 'Native ETG evidence-provider id drifted.' );
$check( ! empty( $descriptor['read_only'] ) && empty( $descriptor['authorizing'] ) && empty( $descriptor['profile_mutation'] ), 'Native ETG evidence-provider authority boundary drifted.' );

$query = $dispatch_read( 'etg-dfsb/evidence-query', array( 'section' => 'summary' ) );
$check( ! is_wp_error( $query ), 'Native ETG evidence-query execution failed.' );
$check( 'etg.dfsb.evidence-provider.v1' === (string) $query['contract'], 'Unexpected native ETG evidence-query contract.' );
$check( 'etg-dfsb' === (string) $query['provider_id'], 'Native ETG evidence-query provider id drifted.' );
$check( 'summary' === (string) $query['section'], 'Native ETG evidence-query did not execute the bounded summary section.' );
$check( ! empty( $query['read_only'] ) && empty( $query['authorizing'] ) && empty( $query['profile_mutation'] ), 'Native ETG evidence-query authority boundary drifted.' );

$status = $dispatch_read( 'etg-dfsb/status' );
$check( ! is_wp_error( $status ), 'ETG adapter status failed.' );
$check( 'mad4b.etg-dfsb-read-adapter.v1' === (string) $status['contract'], 'Unexpected ETG adapter contract.' );
$check( ! empty( $status['version_compatible'] ), 'ETG version compatibility was not proven.' );
$check( 'read_only_non_authorizing' === (string) $status['authority_mode'], 'ETG authority mode drifted.' );
$check( empty( $status['mutation_exposed'] ) && empty( $status['profile_mutation_exposed'] ) && empty( $status['seo_publication_mutation_exposed'] ) && empty( $status['ajax_proxy_exposed'] ), 'ETG adapter opened a mutation or side-channel surface.' );
$projected_native = isset( $status['native_evidence_projection'] ) && is_array( $status['native_evidence_projection'] ) ? $status['native_evidence_projection'] : array();
sort( $projected_native );
$expected_native = $native_evidence_abilities;
sort( $expected_native );
$check( $expected_native === $projected_native, 'Adapter status did not report the exact native ETG evidence projection.' );

$identity = $dispatch_read( 'etg-dfsb/build-identity' );
$check( ! is_wp_error( $identity ), 'ETG exact build identity ability failed.' );
$check( ! empty( $identity['valid'] ) && ! empty( $identity['embedded'] ), 'ETG exact build identity is not valid/embedded.' );
$check( ! empty( $identity['read_only'] ) && empty( $identity['authorizing'] ) && empty( $identity['mutation_exposed'] ), 'ETG build identity authority boundary drifted.' );
$check( 'etg.dfsb.embedded-build-identity.v1' === (string) $identity['contract'], 'Unexpected ETG build identity contract.' );
$check( 'da80d11c0232a809f7b52c7809d881722964bd23' === (string) $identity['git_sha'], 'ETG build identity git SHA drifted.' );
$check( '585e6447cb65c2a602f7a105447ad58daa83daa9' === (string) $identity['tree_sha'], 'ETG build identity tree SHA drifted.' );
$check( '0.4.0-alpha.13' === (string) $identity['plugin_version'], 'ETG build identity plugin version drifted.' );

$config = $dispatch_read( 'etg-dfsb/configuration' );
$check( ! is_wp_error( $config ) && ! empty( $config['read_only'] ) && empty( $config['authorizing'] ), 'ETG configuration read contract failed.' );
$check( isset( $config['enabled'] ) && false === $config['enabled'], 'ETG global bridge must remain OFF in the disposable runtime.' );

$inventory = $dispatch_read( 'etg-dfsb/runtime-inventory' );
$check( ! is_wp_error( $inventory ), 'ETG runtime inventory ability failed.' );
$check( ! empty( $inventory['read_only'] ) && empty( $inventory['authorizing'] ) && empty( $inventory['profile_mutation'] ), 'ETG runtime inventory authority boundary drifted.' );
$check( in_array( (string) $inventory['contract'], array( 'etg.dfsb.runtime-inventory.v2', 'etg.dfsb.runtime-inventory-unavailable.v1' ), true ), 'Unexpected ETG runtime inventory contract.' );

$profiles = $dispatch_read( 'etg-dfsb/profiles' );
$check( ! is_wp_error( $profiles ) && ! empty( $profiles['read_only'] ) && empty( $profiles['authorizing'] ) && empty( $profiles['profile_mutation'] ), 'ETG profiles read contract failed.' );
$check( (int) $profiles['profile_count'] >= 1, 'ETG default profile inventory is empty.' );

$blueprint = $dispatch_read( 'etg-dfsb/profile-blueprint', array( 'post_type' => 'post', 'taxonomies' => array( 'category' ), 'profile_id' => 'mcp-ci' ) );
$check( ! is_wp_error( $blueprint ) && ! empty( $blueprint['synthetic'] ) && empty( $blueprint['authorizing'] ), 'ETG profile blueprint is not plan-only.' );
$check( empty( $blueprint['profile']['enabled'] ), 'ETG profile blueprint unexpectedly enables a profile.' );

$plan = $dispatch_read( 'etg-dfsb/profile-plan' );
$check( ! is_wp_error( $plan ) && ! empty( $plan['read_only'] ) && empty( $plan['authorizing'] ) && empty( $plan['profile_mutation'] ), 'ETG profile plan is not read-only.' );
$check( ! empty( $plan['requires_operator_review'] ), 'ETG profile plan omitted operator review.' );

$catalog = $dispatch_read( 'etg-dfsb/content-catalog' );
$check( ! is_wp_error( $catalog ) && ! empty( $catalog['read_only'] ) && empty( $catalog['authorizing'] ), 'ETG content catalog authority boundary failed.' );
$check( ! empty( $catalog['supports_ajax_runtime_state'] ) && ! empty( $catalog['supports_elementor_media_tags'] ), 'ETG dynamic-content capabilities were not surfaced.' );
$check( (int) $catalog['token_count'] > 0, 'ETG content catalog returned no tokens.' );

$coverage = wp_get_ability( 'mad4b/plugin-adapter-coverage' )->execute();
$check( ! is_wp_error( $coverage ), 'Plugin coverage ability failed.' );
$etg_plugin = null;
foreach ( $coverage['plugins'] as $item ) {
	if ( 'etg-dynamic-filter-seo-bridge/etg-dynamic-filter-seo-bridge.php' === (string) $item['plugin_file'] ) { $etg_plugin = $item; break; }
}
$check( is_array( $etg_plugin ), 'ETG plugin was not discovered by Adapter Coverage.' );
$check( 'etg-dfsb' === (string) $etg_plugin['family'] && 'etg-dfsb' === (string) $etg_plugin['adapter_id'], 'ETG plugin did not resolve to its dedicated adapter family.' );
$check( 'read_only_supported' === (string) $etg_plugin['coverage_state'], 'ETG plugin is not classified read_only_supported.' );
$check( empty( $etg_plugin['support_request'] ), 'ETG plugin still emits an adapter support request.' );

$all_maps = $adapter->ability_names();
$check( empty( $all_maps['content'] ) && empty( $all_maps['admin'] ), 'ETG adapter declared a write/admin ability.' );
foreach ( $native_evidence_abilities as $name ) {
	$check( in_array( $name, $all_maps['read'], true ), 'ETG adapter read map omitted native evidence ability: ' . $name );
}

echo "mad4b.site-control-plane.runtime-etg-dfsb-mcp.v3: PASS\n";
