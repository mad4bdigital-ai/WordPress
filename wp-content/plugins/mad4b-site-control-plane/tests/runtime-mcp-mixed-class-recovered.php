<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
function mad4b_mixed_class_recovered_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}
$guard = MAD4B_SCP_MCP_Runtime_Conflict_Guard::status();
$provenance = MAD4B_SCP_MCP_Class_Provenance::status( true );
$mu = isset( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] ) && is_array( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] ) ? $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] : array();

if ( 'canonical_runtime' !== ( $guard['state'] ?? '' ) ) mad4b_mixed_class_recovered_fail( 'Recovered request is not canonical.', $guard );
if ( empty( $guard['runtime_from_official_plugin'] ) || ! empty( $guard['runtime_provenance_mismatch'] ) ) mad4b_mixed_class_recovered_fail( 'Recovered runtime ownership mismatch remains.', $guard );
if ( empty( $guard['runtime_class_provenance_enforced'] ) || empty( $guard['runtime_class_provenance_ready'] ) || 0 !== (int) ( $guard['runtime_class_provenance_failure_count'] ?? -1 ) ) mad4b_mixed_class_recovered_fail( 'Recovered class set is not certified.', $guard );
if ( empty( $provenance['ready'] ) || 'certified_class_set' !== ( $provenance['state'] ?? '' ) || 0 !== (int) ( $provenance['failure_count'] ?? -1 ) ) mad4b_mixed_class_recovered_fail( 'Direct class provenance did not converge.', $provenance );
if ( empty( $mu['critical_class_set_pinned'] ) || (int) ( $mu['critical_class_pin_count'] ?? 0 ) !== count( MAD4B_SCP_MCP_Class_Provenance::critical_classes() ) ) mad4b_mixed_class_recovered_fail( 'MU v4 did not pin every critical MCP class.', $mu );
if ( 'canonical_runtime_pinned_adapter_hook_armed' !== ( $mu['state'] ?? '' ) ) mad4b_mixed_class_recovered_fail( 'MU v4 did not arm canonical Adapter lifecycle.', $mu );

$validator = new ReflectionClass( 'WP\\MCP\\Domain\\Tools\\McpToolValidator' );
$validator_file = wp_normalize_path( (string) realpath( $validator->getFileName() ) );
$official_root = rtrim( wp_normalize_path( (string) realpath( trailingslashit( WP_PLUGIN_DIR ) . 'mcp-adapter' ) ), '/' ) . '/';
if ( 0 !== strpos( $validator_file, $official_root ) ) mad4b_mixed_class_recovered_fail( 'Validator is still owned by a foreign source.', array( 'validator_source' => $validator_file ) );

echo 'mad4b.mcp-mixed-class-repair-recovered.v1: PASS' . PHP_EOL;
