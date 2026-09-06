<?php
/** Disposable runtime proof for automatic plugin adapter coverage discovery. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );

$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };
$check( class_exists( 'MAD4B_SCP_Plugin_Discovery' ), 'Plugin discovery class is unavailable.' );
$check( class_exists( 'MAD4B_SCP_Adapter_Coverage_Admin_UI' ), 'Adapter Coverage Admin UI is unavailable.' );
foreach ( array( 'mad4b/plugin-adapter-coverage', 'mad4b/adapter-support-requests' ) as $ability_name ) {
	$check( wp_has_ability( $ability_name ), 'Missing discovery ability: ' . $ability_name );
	$ability = wp_get_ability( $ability_name );
	$meta = $ability->get_meta();
	$check( empty( $meta['public'] ) && empty( $meta['mcp']['public'] ), 'Discovery ability leaked to default/public MCP: ' . $ability_name );
	$check( ! empty( $meta['annotations']['readonly'] ), 'Discovery ability is not annotated readonly: ' . $ability_name );
}

$coverage_ability = wp_get_ability( 'mad4b/plugin-adapter-coverage' );
// These abilities intentionally define no input schema. WordPress Abilities API requires
// null/no argument for no-input abilities; passing an empty object is invalid on WP 7.1+.
$initial = $coverage_ability->execute();
$check( ! is_wp_error( $initial ) && 'mad4b.plugin-adapter-discovery.v1' === $initial['contract'], 'Initial plugin discovery contract failed.' );
$check( ! empty( $initial['discovery_only'] ) && empty( $initial['auto_install'] ) && empty( $initial['auto_generate_adapter'] ) && empty( $initial['auto_create_authority'] ), 'Discovery can create authority/code/install plugins.' );
$check( 'deny' === $initial['unknown_plugin_write_default'], 'Unknown plugin write default is not deny.' );

$priority_ids = array();
foreach ( $initial['priority_external'] as $item ) if ( isset( $item['id'] ) ) $priority_ids[] = $item['id'];
$check( in_array( 'woocommerce', $priority_ids, true ), 'WooCommerce is missing from first-class external coverage.' );
$check( in_array( 'polylang', $priority_ids, true ), 'Polylang is missing from first-class external coverage.' );

$t = MAD4B_SCP_Schema::tables();
global $wpdb;
$governance_before = array(
	'agents' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['agents']}" ),
	'grants' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['grants']}" ),
	'approvals' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['approvals']}" ),
	'mutations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['mutations']}" ),
);

$original_active = get_option( 'active_plugins', array() );
$unknown_dir = WP_PLUGIN_DIR . '/ci-unknown-adapter-target';
$risky_dir = WP_PLUGIN_DIR . '/code-snippets';
wp_mkdir_p( $unknown_dir );
wp_mkdir_p( $risky_dir );
file_put_contents( $unknown_dir . '/ci-unknown.php', "<?php\n/*\nPlugin Name: CI Unknown Adapter Target\nVersion: 9.9.9\n*/\n" );
file_put_contents( $risky_dir . '/code-snippets.php', "<?php\n/*\nPlugin Name: Code Snippets CI Fixture\nVersion: 9.9.9\n*/\n" );
update_option( 'active_plugins', array_values( array_unique( array_merge( (array) $original_active, array( 'ci-unknown-adapter-target/ci-unknown.php', 'code-snippets/code-snippets.php' ) ) ) ) );
if ( function_exists( 'wp_clean_plugins_cache' ) ) wp_clean_plugins_cache( true );

try {
	$discovered = $coverage_ability->execute();
	$check( ! is_wp_error( $discovered ), 'Plugin discovery failed with CI fixtures.' );
	$unknown = null; $risky = null;
	foreach ( $discovered['plugins'] as $item ) {
		if ( 'ci-unknown-adapter-target/ci-unknown.php' === $item['plugin_file'] ) $unknown = $item;
		if ( 'code-snippets/code-snippets.php' === $item['plugin_file'] ) $risky = $item;
	}
	$check( is_array( $unknown ) && ! empty( $unknown['active'] ), 'Unknown active plugin fixture was not discovered.' );
	$check( 'adapter_required' === $unknown['coverage_state'], 'Unknown plugin did not fail closed to adapter_required.' );
	$check( is_array( $unknown['support_request'] ) && 'no_registered_adapter' === $unknown['support_request']['reason_code'], 'Unknown plugin did not produce an adapter support request.' );
	$check( empty( $unknown['support_request']['normal_write_allowed'] ) && empty( $unknown['support_request']['auto_install'] ) && empty( $unknown['support_request']['auto_create_authority'] ), 'Unknown plugin support request opened authority.' );
	$check( is_array( $risky ) && 'excluded_high_risk' === $risky['coverage_state'], 'High-risk code execution plugin was not excluded from normal writer support.' );
	$check( 'normal_writer_excluded_by_risk' === $risky['support_request']['reason_code'], 'High-risk support request reason is incorrect.' );

	$requests_ability = wp_get_ability( 'mad4b/adapter-support-requests' );
	$requests_one = $requests_ability->execute();
	$requests_two = $requests_ability->execute();
	$check( ! is_wp_error( $requests_one ) && ! is_wp_error( $requests_two ), 'Adapter support request ability failed.' );
	$ids_one = wp_list_pluck( $requests_one['requests'], 'support_request_id' );
	$ids_two = wp_list_pluck( $requests_two['requests'], 'support_request_id' );
	$check( $ids_one === $ids_two, 'Adapter support request IDs are not deterministic.' );
	$check( empty( $requests_one['network_request_sent'] ) && empty( $requests_one['authority_created'] ), 'Support request discovery performed an external/action mutation.' );

	$ui_snapshot = MAD4B_SCP_Adapter_Coverage_Admin_UI::snapshot();
	$check( ! is_wp_error( $ui_snapshot ) && 'mad4b.plugin-adapter-discovery.v1' === $ui_snapshot['contract'], 'Adapter Coverage Admin snapshot failed.' );
} finally {
	update_option( 'active_plugins', $original_active );
	@unlink( $unknown_dir . '/ci-unknown.php' );
	@rmdir( $unknown_dir );
	@unlink( $risky_dir . '/code-snippets.php' );
	@rmdir( $risky_dir );
	if ( function_exists( 'wp_clean_plugins_cache' ) ) wp_clean_plugins_cache( true );
}

$governance_after = array(
	'agents' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['agents']}" ),
	'grants' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['grants']}" ),
	'approvals' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['approvals']}" ),
	'mutations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['mutations']}" ),
);
$check( $governance_before === $governance_after, 'Read-only plugin discovery mutated governance authority/state.' );

echo "mad4b.site-control-plane.runtime-plugin-adapter-discovery.v1: PASS\n";
