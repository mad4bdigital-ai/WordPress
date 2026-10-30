<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
function mad4b_mixed_class_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}
// A foreign validator class is telemetry and must not require maintenance.
$before = MAD4B_SCP_MCP_Runtime_Conflict_Guard::status();
if ( ! empty( $before['blocker'] ) || empty( $before['source_provenance_observational'] ) )
    mad4b_mixed_class_fail( 'Class source observation must not block ordinary requests.', $before );
if ( ! empty( $before['repair_applied'] ) )
    mad4b_mixed_class_fail( 'Source observation initiated an unrequested repair.', $before );
// Explicit maintenance retains its independent lease, audit and next-request checks.
$repair = MAD4B_SCP_MCP_Runtime_Recovery::run( '', true );
if ( is_wp_error( $repair ) ) mad4b_mixed_class_fail( 'governed recovery failed before mixed-class readback', $repair->get_error_code() );
$guard = MAD4B_SCP_MCP_Runtime_Conflict_Guard::status();
$provenance = MAD4B_SCP_MCP_Class_Provenance::status( true );

if ( empty( $guard['runtime_from_official_plugin'] ) ) mad4b_mixed_class_fail( 'Adapter entrypoint must remain owned by the official plugin.', $guard );
if ( empty( $guard['runtime_class_provenance_enforced'] ) || ! empty( $guard['runtime_class_provenance_ready'] ) ) mad4b_mixed_class_fail( 'Mixed class set was not detected.', $guard );
if ( empty( $guard['runtime_provenance_mismatch'] ) ) mad4b_mixed_class_fail( 'Mixed class observation disappeared.', $guard );
if ( ! empty( $guard['blocker'] ) ) mad4b_mixed_class_fail( 'Source mismatch must not block explicit maintenance.', $guard );
if ( ! in_array( $guard['state'] ?? '', array( 'mu_bootstrap_installed_for_class_set_next_request', 'class_set_repair_armed_for_next_request' ), true ) ) mad4b_mixed_class_fail( 'Mixed-class repair was not armed for the next request.', $guard );
if ( empty( $guard['next_request_required'] ) ) mad4b_mixed_class_fail( 'Mixed-class repair must require a fresh request.', $guard );
if ( empty( $guard['mu_bootstrap_present'] ) || empty( $guard['mu_bootstrap_integrity'] ) ) mad4b_mixed_class_fail( 'Managed MU bootstrap was not installed with exact integrity.', $guard );
if ( ! empty( $guard['load_order_repair_applied'] ) ) mad4b_mixed_class_fail( 'Class-only drift must not rewrite active_plugins.', $guard );
if ( ! empty( $provenance['ready'] ) || empty( $provenance['mixed_runtime'] ) ) mad4b_mixed_class_fail( 'Direct class provenance did not classify mixed runtime.', $provenance );

$failures = array_column( $provenance['failures'] ?? array(), null, 'alias' );
if ( empty( $failures['tool_validator'] ) ) mad4b_mixed_class_fail( 'Foreign validator was not identified.', $provenance );
if ( 'runtime_class_source_mismatch' !== ( $failures['tool_validator']['reason'] ?? '' ) ) mad4b_mixed_class_fail( 'Foreign validator mismatch reason was not source-bound.', $failures['tool_validator'] );
if ( 'outside-wp-plugin-dir' !== (string) ( $failures['tool_validator']['observed_source'] ?? '' ) ) mad4b_mixed_class_fail( 'Foreign validator source outside the normal plugin root must remain bounded without leaking an absolute path.', $failures['tool_validator'] );

echo 'mad4b.mcp-mixed-class-observational-and-explicit-repair.v2: PASS' . PHP_EOL;
