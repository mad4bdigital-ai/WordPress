<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

function mad4b_readonly_diagnostics_fail( $message, $data = null ) {
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}

if ( ! class_exists( 'MAD4B_SCP_Governed_Ability_Overrides' ) ) mad4b_readonly_diagnostics_fail( 'Governed ability overrides unavailable.' );
if ( ! class_exists( 'MAD4B_SCP_Policy' ) ) mad4b_readonly_diagnostics_fail( 'Policy class unavailable.' );

$base = trailingslashit( get_temp_dir() ) . 'mad4b-readonly-diagnostics-' . wp_generate_uuid4();
$backup = trailingslashit( $base ) . 'missing-backup-root';
if ( file_exists( $base ) ) mad4b_readonly_diagnostics_fail( 'Unexpected pre-existing diagnostics fixture.', $base );
if ( ! wp_mkdir_p( $base ) ) mad4b_readonly_diagnostics_fail( 'Unable to create test parent directory.', $base );
@chmod( $base, 0750 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test fixture only.
$mode_before = fileperms( $base );

add_filter(
	'mad4b_scp_backup_root',
	static function () use ( $backup ) {
		return $backup;
	},
	999
);

if ( file_exists( $backup ) ) mad4b_readonly_diagnostics_fail( 'Backup-root fixture unexpectedly exists before diagnostics.', $backup );

$result = MAD4B_SCP_Governed_Ability_Overrides::diagnostics_health_readonly();
if ( ! is_array( $result ) ) mad4b_readonly_diagnostics_fail( 'Readonly diagnostics did not return an array.', $result );
if ( 'mad4b.readonly-diagnostics.v1' !== ( isset( $result['contract'] ) ? $result['contract'] : '' ) ) mad4b_readonly_diagnostics_fail( 'Readonly diagnostics contract drifted.', $result );
if ( empty( $result['observational_only'] ) || ! isset( $result['prepares_backup_root'] ) || false !== $result['prepares_backup_root'] ) mad4b_readonly_diagnostics_fail( 'Diagnostics no longer truthfully reports observational semantics.', $result );
if ( file_exists( $backup ) ) mad4b_readonly_diagnostics_fail( 'Diagnostics created a missing backup root.', $backup );
if ( ! isset( $result['backup_root'] ) || ! is_array( $result['backup_root'] ) ) mad4b_readonly_diagnostics_fail( 'Backup-root status missing.', $result );
if ( ! empty( $result['backup_root']['exists'] ) || empty( $result['backup_root']['requires_preparation'] ) ) mad4b_readonly_diagnostics_fail( 'Missing backup root was not reported observationally.', $result['backup_root'] );
if ( ! isset( $result['backup_root']['path_disclosed'] ) || false !== $result['backup_root']['path_disclosed'] ) mad4b_readonly_diagnostics_fail( 'Diagnostics disclosed backup-root path state.', $result['backup_root'] );

clearstatcache( true, $base );
$mode_after = fileperms( $base );
if ( false !== $mode_before && false !== $mode_after && ( $mode_before & 0777 ) !== ( $mode_after & 0777 ) ) {
	mad4b_readonly_diagnostics_fail( 'Diagnostics changed parent directory permissions.', array( 'before' => $mode_before & 0777, 'after' => $mode_after & 0777 ) );
}

// Repeat the call to prove it remains observational and does not lazily prepare
// mutation state on a later health probe.
$second = MAD4B_SCP_Governed_Ability_Overrides::diagnostics_health_readonly();
if ( ! is_array( $second ) || file_exists( $backup ) ) mad4b_readonly_diagnostics_fail( 'Repeated diagnostics mutated backup state.', $second );

remove_filter( 'mad4b_scp_backup_root', '__return_false', 999 );
@rmdir( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test fixture cleanup only.

echo 'mad4b.site-control-plane.runtime-readonly-diagnostics.v1: PASS' . PHP_EOL;
