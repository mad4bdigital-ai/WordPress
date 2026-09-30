<?php
/**
 * Exact-package non-admin/non-WP-CLI bootstrap regression for WP All Import reads.
 *
 * Run as a plain PHP process with MAD4B_WP_PATH pointing at the disposable
 * WordPress root. No provider job execution or mutation is performed.
 */
$root = getenv( 'MAD4B_WP_PATH' );
if ( ! is_string( $root ) || '' === trim( $root ) ) {
	fwrite( STDERR, "[MAD4B WP Import REST bootstrap] MAD4B_WP_PATH is required.\n" );
	exit( 1 );
}
$root = rtrim( $root, "/\\" );
$_SERVER['HTTP_HOST'] = 'bulk-io.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
// This is a MAD4B-owned read bootstrap. Unrelated provider REST is intentionally
// zero-touch and must not load the adapter registry at all.
$_SERVER['REQUEST_URI'] = '/wp-json/mad4b/v1/ci/wp-import-export';
require $root . '/wp-load.php';
if ( ! defined( 'REST_REQUEST' ) ) define( 'REST_REQUEST', true );

$fail = static function ( $message ) {
	fwrite( STDERR, '[MAD4B WP Import REST bootstrap] ' . $message . PHP_EOL );
	exit( 1 );
};

if ( defined( 'WP_CLI' ) && WP_CLI ) $fail( 'Regression must not run inside WP-CLI.' );
if ( is_admin() ) $fail( 'Regression must not run as an admin request.' );
if ( ! class_exists( 'PMXI_Plugin', false ) ) $fail( 'WP All Import plugin bootstrap is unavailable.' );
if ( ! class_exists( 'MAD4B_SCP_WP_Import_Export_Adapter', false ) ) $fail( 'MAD4B WP Import/Export adapter is unavailable.' );

$adapter = new MAD4B_SCP_WP_Import_Export_Adapter();
$status = $adapter->status();
if ( ! is_array( $status ) ) $fail( 'Adapter status is unavailable.' );
if ( empty( $status['provider_certification']['runtime_contract_ok'] ) ) $fail( 'Exact composite provider certification failed.' );
if ( empty( $status['import_runtime_available'] ) ) $fail( 'Import read runtime remained unavailable after bounded bootstrap.' );

$diag = isset( $status['runtime_symbol_diagnostic'] ) && is_array( $status['runtime_symbol_diagnostic'] )
	? $status['runtime_symbol_diagnostic']
	: array();
$import = isset( $diag['import'] ) && is_array( $diag['import'] ) ? $diag['import'] : array();
if ( empty( $import['complete'] ) ) $fail( 'Import runtime symbols are incomplete after bounded bootstrap.' );
if ( ! empty( $import['missing_classes'] ) ) $fail( 'Import runtime still reports missing classes.' );
if ( empty( $diag['provider_readonly_autoload_exact_artifact_required'] ) ) $fail( 'Exact-artifact prerequisite diagnostic is missing.' );
if ( ! empty( $diag['autoload_or_bootstrap_mutation_attempted'] ) ) $fail( 'Read-only bootstrap was misclassified as mutation.' );
if ( ! empty( $diag['filesystem_scan_performed'] ) ) $fail( 'Read-only bootstrap performed a filesystem scan.' );

$list = $adapter->list_imports( array( 'limit' => 5 ) );
if ( is_wp_error( $list ) ) $fail( 'Governed import list still fails in plain WordPress bootstrap: ' . $list->get_error_code() );
if ( ! is_array( $list ) || 'mad4b.wp-all-import-list.v1' !== ( $list['contract'] ?? '' ) ) $fail( 'Unexpected governed import list contract.' );
if ( ! empty( $list['secrets_exposed'] ) ) $fail( 'Governed import list exposed secret material.' );

echo "mad4b.wp-import-export-rest-bootstrap.runtime.v1: PASS\n";
