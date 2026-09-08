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

$read_abilities = array(
	'etg-dfsb/status',
	'etg-dfsb/configuration',
	'etg-dfsb/runtime-inventory',
	'etg-dfsb/profiles',
	'etg-dfsb/profile-blueprint',
	'etg-dfsb/profile-plan',
	'etg-dfsb/content-catalog',
);
foreach ( $read_abilities as $name ) {
	$check( wp_has_ability( $name ), 'Missing ETG MCP ability: ' . $name );
	$ability = wp_get_ability( $name );
	$meta = $ability->get_meta();
	$check( ! empty( $meta['annotations']['readonly'] ), 'ETG ability is not readonly: ' . $name );
	$check( empty( $meta['annotations']['destructive'] ), 'ETG ability is marked destructive: ' . $name );
	$check( empty( $meta['public'] ) && empty( $meta['mcp']['public'] ), 'ETG ability leaked to default/public MCP: ' . $name );
	$check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', $name ), 'ETG ability is not mounted on mad4b-read: ' . $name );
	foreach ( array( 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' ) as $server ) {
		$check( ! MAD4B_SCP_Servers::ability_is_mounted( $server, $name ), 'ETG read ability leaked to ' . $server . ': ' . $name );
	}
}

$status = wp_get_ability( 'etg-dfsb/status' )->execute();
$check( ! is_wp_error( $status ), 'ETG adapter status failed.' );
$check( 'mad4b.etg-dfsb-read-adapter.v1' === (string) $status['contract'], 'Unexpected ETG adapter contract.' );
$check( ! empty( $status['version_compatible'] ), 'ETG version compatibility was not proven.' );
$check( 'read_only_non_authorizing' === (string) $status['authority_mode'], 'ETG authority mode drifted.' );
$check( empty( $status['mutation_exposed'] ) && empty( $status['profile_mutation_exposed'] ) && empty( $status['seo_publication_mutation_exposed'] ) && empty( $status['ajax_proxy_exposed'] ), 'ETG adapter opened a mutation or side-channel surface.' );

$config = wp_get_ability( 'etg-dfsb/configuration' )->execute();
$check( ! is_wp_error( $config ) && ! empty( $config['read_only'] ) && empty( $config['authorizing'] ), 'ETG configuration read contract failed.' );
$check( isset( $config['enabled'] ) && false === $config['enabled'], 'ETG global bridge must remain OFF in the disposable runtime.' );

$inventory = wp_get_ability( 'etg-dfsb/runtime-inventory' )->execute();
$check( ! is_wp_error( $inventory ), 'ETG runtime inventory ability failed.' );
$check( ! empty( $inventory['read_only'] ) && empty( $inventory['authorizing'] ) && empty( $inventory['profile_mutation'] ), 'ETG runtime inventory authority boundary drifted.' );
$check( in_array( (string) $inventory['contract'], array( 'etg.dfsb.runtime-inventory.v2', 'etg.dfsb.runtime-inventory-unavailable.v1' ), true ), 'Unexpected ETG runtime inventory contract.' );

$profiles = wp_get_ability( 'etg-dfsb/profiles' )->execute();
$check( ! is_wp_error( $profiles ) && ! empty( $profiles['read_only'] ) && empty( $profiles['authorizing'] ) && empty( $profiles['profile_mutation'] ), 'ETG profiles read contract failed.' );
$check( (int) $profiles['profile_count'] >= 1, 'ETG default profile inventory is empty.' );

$blueprint = wp_get_ability( 'etg-dfsb/profile-blueprint' )->execute( array( 'post_type' => 'post', 'taxonomies' => array( 'category' ), 'profile_id' => 'mcp-ci' ) );
$check( ! is_wp_error( $blueprint ) && ! empty( $blueprint['synthetic'] ) && empty( $blueprint['authorizing'] ), 'ETG profile blueprint is not plan-only.' );
$check( empty( $blueprint['profile']['enabled'] ), 'ETG profile blueprint unexpectedly enables a profile.' );

$plan = wp_get_ability( 'etg-dfsb/profile-plan' )->execute();
$check( ! is_wp_error( $plan ) && ! empty( $plan['read_only'] ) && empty( $plan['authorizing'] ) && empty( $plan['profile_mutation'] ), 'ETG profile plan is not read-only.' );
$check( ! empty( $plan['requires_operator_review'] ), 'ETG profile plan omitted operator review.' );

$catalog = wp_get_ability( 'etg-dfsb/content-catalog' )->execute();
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

echo "mad4b.site-control-plane.runtime-etg-dfsb-mcp.v1: PASS\n";
