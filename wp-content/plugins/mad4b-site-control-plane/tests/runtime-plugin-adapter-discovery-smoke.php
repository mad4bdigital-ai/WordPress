<?php
/** Disposable runtime proof for automatic plugin adapter coverage discovery. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );

// Backward-compatible evidence marker retained for the normative Spec Kit gate.
// mad4b.site-control-plane.runtime-plugin-adapter-discovery.v1

$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };
$check( class_exists( 'MAD4B_SCP_Plugin_Discovery' ), 'Plugin discovery class is unavailable.' );
$check( class_exists( 'MAD4B_SCP_Adapter_Coverage_Admin_UI' ), 'Adapter Coverage Admin UI is unavailable.' );
$check( class_exists( 'MAD4B_SCP_Repository_Artifact_Catalog' ), 'Repository artifact catalog is unavailable.' );
$check( class_exists( 'MAD4B_SCP_Repository_Family_Adapter' ), 'Repository family adapter class is unavailable.' );
$check( class_exists( 'MAD4B_SCP_Repository_Plugins_Adapter' ), 'Repository inventory adapter class is unavailable.' );
foreach ( array( 'mad4b/plugin-adapter-coverage', 'mad4b/adapter-support-requests', 'mad4b/provider-functional-coverage', 'mad4b/provider-contract-discovery', 'mad4b/functional-gap-runtime-evidence', 'repository-plugins/inventory', 'repository-plugins/get-artifact' ) as $ability_name ) {
	$check( wp_has_ability( $ability_name ), 'Missing discovery ability: ' . $ability_name );
	$ability = wp_get_ability( $ability_name );
	$meta = $ability->get_meta();
	$check( empty( $meta['public'] ) && empty( $meta['mcp']['public'] ), 'Discovery ability leaked to default/public MCP: ' . $ability_name );
	$check( ! empty( $meta['annotations']['readonly'] ), 'Discovery ability is not annotated readonly: ' . $ability_name );
	$check( empty( $meta['annotations']['destructive'] ), 'Discovery ability is destructive: ' . $ability_name );
	$check( 'read' === (string) ( $meta['mcp']['surface'] ?? '' ), 'Repository discovery ability escaped mad4b-read: ' . $ability_name );
}

$zero_touch_ability = wp_get_ability( 'mad4b/functional-gap-runtime-evidence' );
$zero_touch = $zero_touch_ability->execute();
$check( ! is_wp_error( $zero_touch ) && 'mad4b.functional-gap-zero-touch.v1' === (string) ( $zero_touch['contract'] ?? '' ), 'Zero-touch functional-gap evidence contract failed.' );
$check( ! empty( $zero_touch['read_only'] ) && empty( $zero_touch['authority_created'] ) && empty( $zero_touch['mutation_performed'] ) && empty( $zero_touch['remote_request_performed'] ) && empty( $zero_touch['secret_values_returned'] ), 'Zero-touch functional-gap evidence escaped read-only boundary.' );
$check( 'mad4b.runtime-functional-gap-evidence.v2' === (string) ( $zero_touch['runtime']['contract'] ?? '' ), 'Zero-touch runtime evidence contract drifted.' );
$check( 'mad4b.functional-gap-promotion-evaluation.v2' === (string) ( $zero_touch['evaluation']['contract'] ?? '' ), 'Zero-touch promotion evaluation contract drifted.' );
$check( empty( $zero_touch['evaluation']['promotion_authorized'] ), 'Zero-touch evaluator must never authorize promotion by itself.' );
if ( ! empty( $zero_touch['repository_evidence']['present'] ) ) {
	$check( ! empty( $zero_touch['repository_evidence']['valid'] ), 'Present build-embedded repository evidence must validate against build provenance.' );
} else {
	$check( empty( $zero_touch['evaluation']['ready'] ), 'Source/runtime without build-embedded repository evidence must fail closed.' );
}
$initial_zero_touch_identity = (string) ( $zero_touch['snapshot_identity_sha256'] ?? '' );
$check( 64 === strlen( $initial_zero_touch_identity ), 'Zero-touch snapshot identity was not exposed.' );
$check( ! empty( $zero_touch['snapshot_fixed_point_stable'] ), 'Zero-touch source-mode snapshot did not reach an identity fixed point.' );
$check( 1 <= (int) ( $zero_touch['snapshot_fixed_point_attempts'] ?? 0 ) && 2 >= (int) ( $zero_touch['snapshot_fixed_point_attempts'] ?? 0 ), 'Zero-touch fixed-point attempts escaped the bounded retry contract.' );
$check( empty( $zero_touch['snapshot_census_required'] ), 'Source-mode zero-touch snapshot incorrectly required deep census evidence.' );
$check( hash_equals( $initial_zero_touch_identity, (string) ( $zero_touch['snapshot_end_identity_sha256'] ?? '' ) ), 'Zero-touch source-mode snapshot start/end identity diverged.' );
$initial_dynamic_surface = (string) ( $zero_touch['snapshot_end_dynamic_surface_sha256'] ?? '' );
$check( 64 === strlen( $initial_dynamic_surface ) && ! empty( $zero_touch['snapshot_dynamic_surface_stable'] ), 'Zero-touch dynamic surface fixed point was not exposed.' );

$dynamic_probe_callback = static function () {};
add_action( 'wp_ajax_bte_zero_touch_dynamic_probe', $dynamic_probe_callback );
$zero_touch_dynamic = $zero_touch_ability->execute();
$check( ! is_wp_error( $zero_touch_dynamic ), 'Zero-touch evidence failed after in-memory dynamic surface changed.' );
$changed_dynamic_surface = (string) ( $zero_touch_dynamic['snapshot_end_dynamic_surface_sha256'] ?? '' );
$check( 64 === strlen( $changed_dynamic_surface ) && ! hash_equals( $initial_dynamic_surface, $changed_dynamic_surface ), 'Zero-touch cache did not invalidate after dynamic hook state changed.' );
$check( ! empty( $zero_touch_dynamic['snapshot_dynamic_surface_stable'] ) && ! empty( $zero_touch_dynamic['snapshot_fixed_point_stable'] ), 'Zero-touch dynamic re-evaluation did not reach a stable fixed point.' );
remove_action( 'wp_ajax_bte_zero_touch_dynamic_probe', $dynamic_probe_callback );
$zero_touch_dynamic_restored = $zero_touch_ability->execute();
$restored_dynamic_surface = (string) ( $zero_touch_dynamic_restored['snapshot_end_dynamic_surface_sha256'] ?? '' );
$check( hash_equals( $initial_dynamic_surface, $restored_dynamic_surface ), 'Zero-touch dynamic surface fingerprint did not return to baseline after in-memory hook removal.' );

$registry = MAD4B_SCP_Adapter_Registry::instance();

// Reproduce an ordinary wp-admin coverage read that arrives before MCP/WP-CLI
// reconciliation has populated the in-memory registry. Discovery must bootstrap
// deterministic adapters itself without persisting grants, approvals or mutation
// authority.
$registry_reflection = new ReflectionClass( $registry );
$adapters_property = $registry_reflection->getProperty( 'adapters' );
$defaults_property = $registry_reflection->getProperty( 'defaults_registered' );
$adapters_property->setAccessible( true );
$defaults_property->setAccessible( true );
$adapters_property->setValue( $registry, array() );
$defaults_property->setValue( $registry, false );

$early_admin_coverage = MAD4B_SCP_Plugin_Discovery::coverage();
$check( 'mad4b.plugin-adapter-discovery.v1' === (string) ( $early_admin_coverage['contract'] ?? '' ), 'Early admin coverage contract failed.' );
foreach ( array( 'elementor', 'jetengine', 'fluentforms', 'etg-dfsb', 'admin-utilities', 'astra', 'hostinger', 'jet-ecosystem', 'repository-plugins' ) as $adapter_id ) {
	$check( is_object( $registry->get( $adapter_id ) ), 'Coverage discovery did not bootstrap adapter registry: ' . $adapter_id );
}

foreach ( array( 'admin-utilities', 'astra', 'dangerous-code-execution', 'bulk-taxonomy-editor', 'custom-mega-menu', 'meta-catalog-feed-mapper', 'jet-ecosystem', 'google-tag-manager', 'fluentforms', 'hostinger', 'jetformbuilder', 'mad4b-platform', 'wpml', 'reviews', 'identity-admin', 'wp-import-export', 'wpl-client', 'repository-plugins', 'hostinger-onboarding', 'hostinger-ai', 'hostinger-reach', 'duplicator', 'elementskit', 'heic-support', 'wordpress-importer', 'ai-engine', 'external-mcp-server' ) as $adapter_id ) {
	$adapter = $registry->get( $adapter_id );
	$check( is_object( $adapter ), 'Repository family adapter was not registered: ' . $adapter_id );
	$map = $adapter->ability_names();
	$check( empty( $map['content'] ) && empty( $map['admin'] ), 'Repository family adapter opened mutation/admin surface: ' . $adapter_id );
}

$repo_inventory_ability = wp_get_ability( 'repository-plugins/inventory' );
$repo_inventory = $repo_inventory_ability->execute();
$check( ! is_wp_error( $repo_inventory ) && 'mad4b.repository-plugin-artifacts.v1' === $repo_inventory['contract'], 'Repository artifact inventory contract failed.' );
$check( ! empty( $repo_inventory['read_only'] ) && empty( $repo_inventory['authorizing'] ), 'Repository artifact inventory became authorizing.' );
$check( 'deny' === $repo_inventory['runtime_write_default'], 'Repository artifact inventory write default is not deny.' );
$check( MAD4B_SCP_Repository_Artifact_Catalog::artifact_count() === (int) $repo_inventory['artifact_count'], 'Repository artifact inventory count drift.' );
$check( $repo_inventory['artifact_count'] > 0 && $repo_inventory['family_count'] > 0, 'Repository artifact inventory is empty.' );

$get_artifact = wp_get_ability( 'repository-plugins/get-artifact' );
$fluent = $get_artifact->execute( array( 'artifact_id' => 'fluentform' ) );
$check( ! is_wp_error( $fluent ) && 'fluentforms' === $fluent['adapter_id'], 'Fluent Forms repository artifact is not mapped to its family adapter.' );
$check( ! empty( $fluent['adapter_registered'] ) && empty( $fluent['authorizing'] ), 'Fluent Forms repository mapping is not registered/read-only.' );
$risky_artifact = $get_artifact->execute( array( 'artifact_id' => 'code-snippets' ) );
$check( ! is_wp_error( $risky_artifact ) && 'dangerous-code-execution' === $risky_artifact['adapter_id'], 'High-risk repository artifact mapping is missing.' );
$check( 'breakglass_review_only' === $risky_artifact['mutation_scope'], 'High-risk repository artifact escaped breakglass-review-only scope.' );

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
$menu_dir = WP_PLUGIN_DIR . '/custom-mega-menu-v49';
$runtime_only_dir = WP_PLUGIN_DIR . '/duplicator';
$lookalike_dir = WP_PLUGIN_DIR . '/custom-mega-menu-villain';
wp_mkdir_p( $unknown_dir );
wp_mkdir_p( $risky_dir );
wp_mkdir_p( $menu_dir );
wp_mkdir_p( $runtime_only_dir );
wp_mkdir_p( $lookalike_dir );
file_put_contents( $unknown_dir . '/ci-unknown.php', "<?php\n/*\nPlugin Name: CI Unknown Adapter Target\nVersion: 9.9.9\n*/\n" );
file_put_contents( $risky_dir . '/code-snippets.php', "<?php\n/*\nPlugin Name: Code Snippets CI Fixture\nVersion: 9.9.9\n*/\n" );
file_put_contents( $menu_dir . '/custom-mega-menu.php', "<?php\n/*\nPlugin Name: Custom Mega Menu Widgets (All Styles)\nVersion: 1.1.49\n*/\n" );
file_put_contents( $runtime_only_dir . '/duplicator.php', "<?php\n/*\nPlugin Name: Duplicator\nVersion: 5.0.3\n*/\n" );
file_put_contents( $lookalike_dir . '/custom-mega-menu.php', "<?php\n/*\nPlugin Name: Custom Mega Menu Lookalike CI Fixture\nVersion: 9.9.9\n*/\n" );
update_option( 'active_plugins', array_values( array_unique( array_merge( (array) $original_active, array( 'ci-unknown-adapter-target/ci-unknown.php', 'code-snippets/code-snippets.php', 'custom-mega-menu-v49/custom-mega-menu.php', 'duplicator/duplicator.php', 'custom-mega-menu-villain/custom-mega-menu.php' ) ) ) ) );
if ( function_exists( 'wp_clean_plugins_cache' ) ) wp_clean_plugins_cache( true );

try {
	$discovered = $coverage_ability->execute();
	$check( ! is_wp_error( $discovered ), 'Plugin discovery failed with CI fixtures.' );
	$unknown = null; $risky = null; $menu = null; $runtime_only = null; $lookalike = null;
	foreach ( $discovered['plugins'] as $item ) {
		if ( 'ci-unknown-adapter-target/ci-unknown.php' === $item['plugin_file'] ) $unknown = $item;
		if ( 'code-snippets/code-snippets.php' === $item['plugin_file'] ) $risky = $item;
		if ( 'custom-mega-menu-v49/custom-mega-menu.php' === $item['plugin_file'] ) $menu = $item;
		if ( 'duplicator/duplicator.php' === $item['plugin_file'] ) $runtime_only = $item;
		if ( 'custom-mega-menu-villain/custom-mega-menu.php' === $item['plugin_file'] ) $lookalike = $item;
	}
	$check( is_array( $unknown ) && ! empty( $unknown['active'] ), 'Unknown active plugin fixture was not discovered.' );
	$check( 'adapter_required' === $unknown['coverage_state'], 'Unknown plugin did not fail closed to adapter_required.' );
	$check( is_array( $unknown['support_request'] ) && 'no_registered_adapter' === $unknown['support_request']['reason_code'], 'Unknown plugin did not produce an adapter support request.' );
	$check( empty( $unknown['support_request']['normal_write_allowed'] ) && empty( $unknown['support_request']['auto_install'] ) && empty( $unknown['support_request']['auto_create_authority'] ), 'Unknown plugin support request opened authority.' );
	$check( is_array( $risky ) && 'excluded_high_risk' === $risky['coverage_state'], 'High-risk code execution plugin was not excluded from normal writer support.' );
	$check( 'normal_writer_excluded_by_risk' === $risky['support_request']['reason_code'], 'High-risk support request reason is incorrect.' );
	$check( 'dangerous-code-execution' === $risky['adapter_id'] && ! empty( $risky['adapter_registered'] ), 'High-risk plugin lost its read-only inspection adapter.' );
	$check( is_array( $menu ) && ! empty( $menu['active'] ), 'Normalized Custom Mega Menu runtime fixture was not discovered.' );
	$check( 'custom-mega-menu' === $menu['family'] && 'custom-mega-menu' === $menu['adapter_id'], 'Normalized Custom Mega Menu runtime slug did not resolve to its governed family.' );
	$check( ! empty( $menu['adapter_registered'] ), 'Custom Mega Menu family adapter was not registered.' );
	$check( 'contract_discovery_required' === (string) ( $menu['functional_coverage']['state'] ?? '' ), 'Custom Mega Menu must remain contract-discovery-only rather than adapter-missing or functionally ready.' );
	$check( 'plugin_status_read' === (string) ( $menu['functional_coverage']['safe_now'][0] ?? '' ), 'Custom Mega Menu safe-now scope drifted.' );
	$check( in_array( 'menu_structure_write', (array) ( $menu['functional_coverage']['prohibited_until_certified'] ?? array() ), true ), 'Custom Mega Menu write boundary was not preserved.' );
	$check( is_array( $runtime_only ) && ! empty( $runtime_only['active'] ), 'Runtime-only Duplicator fixture was not discovered.' );
	$check( 'duplicator' === $runtime_only['family'] && 'duplicator' === $runtime_only['adapter_id'], 'Runtime-only family identity did not resolve.' );
	$check( ! empty( $runtime_only['adapter_registered'] ) && ! empty( $runtime_only['adapter_runtime_available'] ), 'Runtime-only family adapter was not available.' );
	$check( 'read_only_supported' === $runtime_only['coverage_state'], 'Runtime-only family did not remain read-only supported.' );
	$check( 'contract_discovery_required' === (string) ( $runtime_only['functional_coverage']['state'] ?? '' ), 'Runtime-only family must remain contract-discovery-only.' );
	$check( 'mad4b.runtime-family-read-adapter.v1' === (string) ( $runtime_only['adapter_contract'] ?? '' ), 'Runtime-only plugin item did not expose its adapter contract.' );
	$check( 'runtime_match_only' === (string) ( $runtime_only['adapter_runtime_source'] ?? '' ), 'Runtime-only plugin item did not expose runtime provenance.' );
	$check( 0 === (int) ( $runtime_only['repository_artifact_count'] ?? -1 ), 'Runtime-only plugin item falsely reported a repository artifact.' );
	$runtime_only_status = $registry->get( 'duplicator' )->status();
	$check( 'mad4b.runtime-family-read-adapter.v1' === (string) ( $runtime_only_status['contract'] ?? '' ), 'Runtime-only adapter contract drifted.' );
	$check( 0 === (int) ( $runtime_only_status['repository_artifact_count'] ?? -1 ), 'Runtime-only family fabricated a repository artifact.' );
	$check( 'runtime_match_only' === (string) ( $runtime_only_status['runtime_source'] ?? '' ), 'Runtime-only family source mode drifted.' );
	$check( empty( $runtime_only_status['mutation_exposed'] ) && 'none' === (string) ( $runtime_only_status['mutation_scope'] ?? '' ), 'Runtime-only family opened mutation scope.' );
	$check( is_array( $lookalike ) && ! empty( $lookalike['active'] ), 'Versioned-family lookalike fixture was not discovered.' );
	$check( 'unknown' === $lookalike['family'] && '' === $lookalike['adapter_id'], 'Non-numeric versioned-family lookalike was incorrectly classified.' );
	$check( 'adapter_required' === $lookalike['coverage_state'], 'Non-numeric versioned-family lookalike did not fail closed.' );
	$check( 'unknown-ci-unknown-adapter-target' === (string) ( $unknown['functional_family_key'] ?? '' ), 'Unknown plugin family key was not isolated by plugin root.' );
	$check( 'unknown-custom-mega-menu-villain' === (string) ( $lookalike['functional_family_key'] ?? '' ), 'Lookalike unknown family key was not isolated by plugin root.' );
	$check( isset( $discovered['functional_family_states']['unknown-ci-unknown-adapter-target'] ), 'Unknown fixture missing from family-state projection.' );
	$check( isset( $discovered['functional_family_states']['unknown-custom-mega-menu-villain'] ), 'Lookalike fixture missing from family-state projection.' );
	$check( 'adapter_missing' === (string) $discovered['functional_family_states']['unknown-ci-unknown-adapter-target'], 'Unknown fixture family state drifted.' );
	$check( 'adapter_missing' === (string) $discovered['functional_family_states']['unknown-custom-mega-menu-villain'], 'Lookalike fixture family state drifted.' );

	$zero_touch_after = $zero_touch_ability->execute();
	$check( ! is_wp_error( $zero_touch_after ), 'Zero-touch evidence failed after runtime identity changed.' );
	$menu_runtime_rows = (array) ( $zero_touch_after['runtime']['families']['custom-mega-menu'] ?? array() );
	$menu_runtime_files = wp_list_pluck( $menu_runtime_rows, 'plugin_file' );
	$check( in_array( 'custom-mega-menu-v49/custom-mega-menu.php', $menu_runtime_files, true ), 'Numeric versioned Custom Mega Menu identity was not captured by zero-touch policy.' );
	$check( ! in_array( 'custom-mega-menu-villain/custom-mega-menu.php', $menu_runtime_files, true ), 'Versioned-family lookalike leaked into zero-touch Custom Mega Menu identity.' );
	$check( ! hash_equals( $initial_zero_touch_identity, (string) ( $zero_touch_after['snapshot_identity_sha256'] ?? '' ) ), 'Zero-touch direct ability cache did not invalidate after runtime identity changed.' );
	$check( ! empty( $zero_touch_after['snapshot_fixed_point_stable'] ), 'Zero-touch direct ability did not reach a new fixed point after runtime identity changed.' );
	$check( hash_equals( (string) ( $zero_touch_after['snapshot_identity_sha256'] ?? '' ), (string) ( $zero_touch_after['snapshot_end_identity_sha256'] ?? '' ) ), 'Zero-touch direct ability returned a mixed start/end runtime identity.' );
	$check( 1 <= (int) ( $zero_touch_after['snapshot_fixed_point_attempts'] ?? 0 ) && 2 >= (int) ( $zero_touch_after['snapshot_fixed_point_attempts'] ?? 0 ), 'Zero-touch retry count exceeded the fixed-point bound.' );

	$requests_ability = wp_get_ability( 'mad4b/adapter-support-requests' );
	$requests_one = $requests_ability->execute();
	$requests_two = $requests_ability->execute();
	$check( ! is_wp_error( $requests_one ) && ! is_wp_error( $requests_two ), 'Adapter support request ability failed.' );
	$ids_one = wp_list_pluck( $requests_one['requests'], 'support_request_id' );
	$ids_two = wp_list_pluck( $requests_two['requests'], 'support_request_id' );
	$check( $ids_one === $ids_two, 'Adapter support request IDs are not deterministic.' );
	$check( empty( $requests_one['network_request_sent'] ) && empty( $requests_one['authority_created'] ), 'Support request discovery performed an external/action mutation.' );
	foreach ( $requests_one['requests'] as $request ) {
		$check( 'duplicator/duplicator.php' !== (string) ( $request['plugin_file'] ?? '' ), 'Runtime-only known family incorrectly remained an adapter-support request.' );
	}

	$contract_discovery_ability = wp_get_ability( 'mad4b/provider-contract-discovery' );
	$contract_discovery = $contract_discovery_ability->execute();
	$check( ! is_wp_error( $contract_discovery ) && 'mad4b.provider-contract-discovery.v1' === (string) ( $contract_discovery['contract'] ?? '' ), 'Contract discovery report failed.' );
	$duplicator_discovery = null;
	foreach ( (array) ( $contract_discovery['items'] ?? array() ) as $family_item ) {
		if ( 'duplicator' === (string) ( $family_item['family'] ?? '' ) ) $duplicator_discovery = $family_item;
	}
	$check( is_array( $duplicator_discovery ), 'Runtime-only Duplicator family did not move into contract discovery.' );
	$check( 'runtime_match_only' === (string) ( $duplicator_discovery['adapter_runtime_source'] ?? '' ), 'Contract discovery lost runtime-only provenance.' );
	$check( empty( $duplicator_discovery['repository_artifact_backed'] ), 'Contract discovery falsely marked runtime-only family as repository-backed.' );
	$check( in_array( 'duplicator/duplicator.php', (array) ( $duplicator_discovery['plugin_files'] ?? array() ), true ), 'Contract discovery did not retain exact runtime plugin identity.' );
	$duplicator_runtime_identity = null;
	foreach ( (array) ( $duplicator_discovery['runtime_identities'] ?? array() ) as $runtime_identity ) {
		if ( 'duplicator/duplicator.php' === (string) ( $runtime_identity['plugin_file'] ?? '' ) ) $duplicator_runtime_identity = $runtime_identity;
	}
	$check( is_array( $duplicator_runtime_identity ), 'Contract discovery runtime identity record is missing.' );
	$check( '5.0.3' === (string) ( $duplicator_runtime_identity['plugin_version'] ?? '' ), 'Contract discovery lost exact runtime version.' );

	$functional_ability = wp_get_ability( 'mad4b/provider-functional-coverage' );
	$functional_report = $functional_ability->execute();
	$check( ! is_wp_error( $functional_report ) && 'mad4b.provider-functional-coverage.v1' === (string) ( $functional_report['contract'] ?? '' ), 'Functional coverage report failed.' );
	$duplicator_functional = null;
	foreach ( (array) ( $functional_report['items'] ?? array() ) as $functional_item ) {
		if ( 'duplicator' === (string) ( $functional_item['family'] ?? '' ) ) $duplicator_functional = $functional_item;
	}
	$check( is_array( $duplicator_functional ), 'Functional coverage report lost Duplicator family.' );

	$ui_snapshot = MAD4B_SCP_Adapter_Coverage_Admin_UI::snapshot();
	$check( ! is_wp_error( $ui_snapshot ) && 'mad4b.plugin-adapter-discovery.v1' === $ui_snapshot['contract'], 'Adapter Coverage Admin snapshot failed.' );
} finally {
	update_option( 'active_plugins', $original_active );
	@unlink( $unknown_dir . '/ci-unknown.php' );
	@rmdir( $unknown_dir );
	@unlink( $risky_dir . '/code-snippets.php' );
	@rmdir( $risky_dir );
	@unlink( $menu_dir . '/custom-mega-menu.php' );
	@rmdir( $menu_dir );
	@unlink( $runtime_only_dir . '/duplicator.php' );
	@rmdir( $runtime_only_dir );
	@unlink( $lookalike_dir . '/custom-mega-menu.php' );
	@rmdir( $lookalike_dir );
	if ( function_exists( 'wp_clean_plugins_cache' ) ) wp_clean_plugins_cache( true );
}

$governance_after = array(
	'agents' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['agents']}" ),
	'grants' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['grants']}" ),
	'approvals' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['approvals']}" ),
	'mutations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['mutations']}" ),
);
$check( $governance_before === $governance_after, 'Read-only plugin discovery mutated governance authority/state.' );

echo "mad4b.site-control-plane.runtime-plugin-adapter-discovery.v2: PASS\n";