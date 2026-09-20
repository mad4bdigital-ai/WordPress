<?php
/**
 * Disposable exact-package runtime certification for WP All Import / Export.
 * No provider import/export job is executed here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fail = static function ( $message ) {
	fwrite( STDERR, '[MAD4B exact WP Import/Export runtime] ' . $message . PHP_EOL );
	exit( 1 );
};
if ( ! current_user_can( 'manage_options' ) ) $fail( 'Administrator context required.' );

if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugins = get_plugins();
$expected = array(
	'wp-all-import-pro/wp-all-import-pro.php' => '5.0.8',
	'wp-all-export-pro/wp-all-export-pro.php' => '1.9.15',
);
foreach ( $expected as $plugin_file => $version ) {
	if ( empty( $plugins[ $plugin_file ]['Version'] ) ) $fail( 'Provider plugin missing: ' . $plugin_file );
	if ( $version !== (string) $plugins[ $plugin_file ]['Version'] ) $fail( 'Provider version drift: ' . $plugin_file );
}

foreach ( array(
	'PMXI_Plugin', 'PMXI_Import_Record', 'PMXI_Import_List',
	'PMXE_Plugin', 'PMXE_Export_Record', 'PMXE_Export_List',
) as $class ) {
	if ( ! class_exists( $class ) ) $fail( 'Required provider class missing: ' . $class );
}
foreach ( array(
	array( 'PMXI_Import_Record', 'execute' ),
	array( 'PMXI_Import_Record', 'process' ),
	array( 'PMXI_Import_Record', 'get_missing_records' ),
	array( 'PMXI_Import_Record', 'delete_missing_records' ),
	array( 'PMXE_Export_Record', 'execute' ),
	array( 'PMXE_Export_Record', 'generate_bundle' ),
) as $method ) {
	if ( ! method_exists( $method[0], $method[1] ) ) $fail( 'Required provider method missing: ' . implode( '::', $method ) );
}

if ( ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) $fail( 'Provider contract authority unavailable.' );
$runtime = MAD4B_SCP_Provider_Contracts::runtime_status( 'wp-import-export', true );
if ( ! is_array( $runtime ) || 'certified' !== ( $runtime['status'] ?? '' ) || empty( $runtime['runtime_contract_ok'] ) ) {
	$fail( 'Composite exact-provider runtime did not certify.' );
}
if ( 2 !== (int) ( $runtime['component_count'] ?? 0 ) ) $fail( 'Composite provider must contain exactly two components.' );
foreach ( array( 'import' => '5.0.8', 'export' => '1.9.15' ) as $key => $version ) {
	$component = isset( $runtime['components'][ $key ] ) && is_array( $runtime['components'][ $key ] ) ? $runtime['components'][ $key ] : array();
	if ( 'certified' !== ( $component['status'] ?? '' ) ) $fail( 'Component is not exact-certified: ' . $key );
	if ( $version !== ( $component['installed_version'] ?? '' ) ) $fail( 'Component installed-version mismatch: ' . $key );
	if ( empty( $component['runtime_integrity']['manifest_present'] ) ) $fail( 'Component integrity manifest missing: ' . $key );
	if ( ! empty( $component['runtime_integrity']['missing'] ) || ! empty( $component['runtime_integrity']['mismatched'] ) ) $fail( 'Component integrity mismatch: ' . $key );
}

if ( ! class_exists( 'MAD4B_SCP_WP_Import_Export_Adapter' ) ) $fail( 'Bulk I/O adapter unavailable.' );
$adapter = new MAD4B_SCP_WP_Import_Export_Adapter();
if ( ! $adapter->is_available() ) $fail( 'Bulk I/O adapter should be available with both exact providers active.' );
$map = $adapter->ability_names();
if ( ! empty( $map['content'] ) || ! empty( $map['admin'] ) || ! empty( $map['write'] ) ) $fail( 'Execution ability leaked into mounted write surfaces.' );

$status = $adapter->status();
if ( empty( $status['provider_certification']['runtime_contract_ok'] ) ) $fail( 'Adapter did not inherit exact composite artifact certification.' );
if ( empty( $status['capability_certification']['capabilities'] ) ) $fail( 'Capability certification missing.' );
$caps = $status['capability_certification']['capabilities'];

foreach ( array( 'jobs.read', 'import.plan', 'export.plan' ) as $id ) {
	if ( empty( $caps[ $id ]['structural_compatible'] ) ) $fail( 'Read/plan structural contract failed: ' . $id );
	if ( empty( $caps[ $id ]['read_eligible'] ) ) $fail( 'Read/plan capability should be eligible: ' . $id );
	if ( 'FULLY_CERTIFIED' !== ( $caps[ $id ]['certification_level'] ?? '' ) ) $fail( 'Read/plan exact certification missing: ' . $id );
}
foreach ( array( 'import.execute', 'export.execute' ) as $id ) {
	if ( empty( $caps[ $id ]['structural_compatible'] ) ) $fail( 'Execution structural contract failed: ' . $id );
	if ( 'DISCOVERED' !== ( $caps[ $id ]['certification_level'] ?? '' ) ) $fail( 'High-risk execution must remain DISCOVERED: ' . $id );
	if ( 'shadow' !== ( $caps[ $id ]['activation_stage'] ?? '' ) ) $fail( 'High-risk execution must remain shadow: ' . $id );
	if ( ! empty( $caps[ $id ]['write_eligible'] ) ) $fail( 'High-risk execution must not be write-eligible: ' . $id );
	if ( empty( $caps[ $id ]['behavioral_probe_required'] ) ) $fail( 'High-risk execution must require behavioral evidence: ' . $id );
}

$projection = isset( $status['capability_mount_projection'] ) && is_array( $status['capability_mount_projection'] ) ? $status['capability_mount_projection'] : array();
if ( ! empty( $projection['write_abilities'] ) || ! empty( $projection['eligible'] ) ) $fail( 'No bulk execution ability may compile into normal write projection.' );

$readiness = $adapter->execution_readiness();
if ( ! empty( $readiness['mounted_execution_abilities'] ) ) $fail( 'Execution readiness unexpectedly mounted provider mutations.' );
if ( ! empty( $readiness['caller_supplied_secret_allowed'] ) || ! empty( $readiness['secret_material_exposed'] ) || ! empty( $readiness['cron_url_execution_allowed'] ) ) $fail( 'Secret/cron safety invariant failed.' );

echo "mad4b.wp-import-export-exact-package-runtime.v1: PASS\n";
