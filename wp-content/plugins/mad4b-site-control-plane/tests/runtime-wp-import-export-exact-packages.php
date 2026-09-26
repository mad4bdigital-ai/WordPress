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
	'PMXI_Plugin', 'PMXI_Import_Record', 'PMXI_Import_List', 'PMXI_Cli',
	'PMXE_Plugin', 'PMXE_Export_Record', 'PMXE_Export_List',
) as $class ) {
	if ( ! class_exists( $class ) ) $fail( 'Required provider class missing: ' . $class );
}
foreach ( array(
	array( 'PMXI_Cli', 'run' ),
	array( 'PMXI_Import_Record', 'execute' ),
	array( 'PMXI_Import_Record', 'process' ),
	array( 'PMXI_Import_Record', 'get_missing_records' ),
	array( 'PMXI_Import_Record', 'delete_missing_records' ),
	array( 'PMXE_Export_Record', 'execute' ),
	array( 'PMXE_Export_Record', 'generate_bundle' ),
) as $method ) {
	if ( ! method_exists( $method[0], $method[1] ) ) $fail( 'Required provider method missing: ' . implode( '::', $method ) );
}

$signature_evidence = array();
foreach ( array(
	array( 'PMXI_Cli', 'run' ),
	array( 'PMXI_Import_Record', 'execute' ),
	array( 'PMXI_Import_Record', 'process' ),
	array( 'PMXI_Import_Record', 'get_missing_records' ),
	array( 'PMXI_Import_Record', 'delete_missing_records' ),
	array( 'PMXE_Export_Record', 'execute' ),
	array( 'PMXE_Export_Record', 'generate_bundle' ),
) as $method ) {
	$reflection = new ReflectionMethod( $method[0], $method[1] );
	$params = array();
	foreach ( $reflection->getParameters() as $parameter ) {
		$params[] = array(
			'name' => $parameter->getName(),
			'optional' => $parameter->isOptional(),
			'variadic' => $parameter->isVariadic(),
			'by_reference' => $parameter->isPassedByReference(),
			'default_available' => $parameter->isDefaultValueAvailable(),
		);
	}
	$signature_evidence[ $method[0] . '::' . $method[1] ] = array(
		'public' => $reflection->isPublic(),
		'static' => $reflection->isStatic(),
		'required_parameters' => $reflection->getNumberOfRequiredParameters(),
		'total_parameters' => $reflection->getNumberOfParameters(),
		'parameters' => $params,
	);
}
echo 'MAD4B_WP_IMPORT_EXPORT_REFLECTION=' . wp_json_encode( $signature_evidence, JSON_UNESCAPED_SLASHES ) . PHP_EOL;

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

// Exact package identity must fail closed on file drift and recover only after
// the exact bytes are restored. This is disposable CI only.
$import_record_path = WP_PLUGIN_DIR . '/wp-all-import-pro/models/import/record.php';
$import_record_original = is_readable( $import_record_path ) ? file_get_contents( $import_record_path ) : false;
if ( false === $import_record_original ) $fail( 'Unable to read import critical file for drift proof.' );
if ( false === file_put_contents( $import_record_path, $import_record_original . "\n// MAD4B CI integrity drift probe\n" ) ) $fail( 'Unable to inject integrity drift probe.' );
$hash_drift = MAD4B_SCP_Provider_Contracts::runtime_status( 'wp-import-export', true );
if ( ! empty( $hash_drift['runtime_contract_ok'] ) || 'component_drift' !== ( $hash_drift['status'] ?? '' ) ) $fail( 'Critical-file drift did not fail closed.' );
$hash_mismatch = isset( $hash_drift['runtime_integrity']['mismatched'] ) ? (array) $hash_drift['runtime_integrity']['mismatched'] : array();
if ( ! array_key_exists( 'import:models/import/record.php', $hash_mismatch ) ) $fail( 'Critical-file drift evidence did not identify import record.php.' );
if ( false === file_put_contents( $import_record_path, $import_record_original ) ) $fail( 'Unable to restore exact import critical file.' );
$restored_hash = MAD4B_SCP_Provider_Contracts::runtime_status( 'wp-import-export', true );
if ( empty( $restored_hash['runtime_contract_ok'] ) || 'certified' !== ( $restored_hash['status'] ?? '' ) ) $fail( 'Exact runtime did not recover after byte restoration.' );

// Version metadata drift is separately fail-closed even when the provider code
// is already loaded in the current request.
$import_main_path = WP_PLUGIN_DIR . '/wp-all-import-pro/wp-all-import-pro.php';
$import_main_original = is_readable( $import_main_path ) ? file_get_contents( $import_main_path ) : false;
if ( false === $import_main_original ) $fail( 'Unable to read import main file for version drift proof.' );
$import_main_drifted = preg_replace( '/(^[ \t*#\/]*Version\s*:\s*)5\.0\.8(\s*$)/mi', '${1}5.0.9${2}', $import_main_original, 1, $version_replacements );
if ( 1 !== $version_replacements || ! is_string( $import_main_drifted ) ) $fail( 'Unable to create deterministic provider version drift fixture.' );
if ( false === file_put_contents( $import_main_path, $import_main_drifted ) ) $fail( 'Unable to inject provider version drift.' );
$version_drift = MAD4B_SCP_Provider_Contracts::runtime_status( 'wp-import-export', true );
if ( ! empty( $version_drift['runtime_contract_ok'] ) || 'component_drift' !== ( $version_drift['status'] ?? '' ) ) $fail( 'Provider version drift did not fail closed.' );
if ( 'version_drift' !== ( $version_drift['components']['import']['status'] ?? '' ) ) $fail( 'Import component version drift was not explicit.' );
if ( false === file_put_contents( $import_main_path, $import_main_original ) ) $fail( 'Unable to restore exact import main file.' );
$restored_version = MAD4B_SCP_Provider_Contracts::runtime_status( 'wp-import-export', true );
if ( empty( $restored_version['runtime_contract_ok'] ) || 'certified' !== ( $restored_version['status'] ?? '' ) ) $fail( 'Exact runtime did not recover after version restoration.' );

echo "mad4b.wp-import-export-exact-package-runtime.v2: PASS\n";
