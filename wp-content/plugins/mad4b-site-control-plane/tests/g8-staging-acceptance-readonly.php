<?php
/**
 * Read-only observational evidence run inside an already booted WordPress
 * Staging installation using "wp eval-file". This cannot issue a release
 * certificate, activate writes, repair a grant or acknowledge a restore.
 */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'G8_READONLY_ACCEPTANCE' ) ) {
	fwrite( STDERR, "Booted WordPress + G8_READONLY_ACCEPTANCE=1 required.\n" );
	exit( 2 );
}
$ready = static function ( $class, $method ) {
	return class_exists( $class, false ) && method_exists( $class, $method );
};
$site = $ready( 'MAD4B_SCP_G8_Record', 'staging' ) && MAD4B_SCP_G8_Record::staging();
if ( ! $site ) {
	fwrite( STDERR, "G8 readonly probe refused: enrolled Staging is required.\n" );
	exit( 2 );
}
$binding = MAD4B_SCP_G8_Record::binding();
$runtime = MAD4B_SCP_G8_Record::runtime_binding();
$slo = $ready( 'MAD4B_SCP_Automation_SLO', 'status' )
	? MAD4B_SCP_Automation_SLO::status() : array();
$frontend = $ready( 'MAD4B_SCP_Live_Acceptance_Observer', 'frontend_performance_status' )
	? MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status() : array();
$external = $ready( 'MAD4B_SCP_Live_Acceptance_Observer', 'external_handshake_attestation_status' )
	? MAD4B_SCP_Live_Acceptance_Observer::external_handshake_attestation_status() : array();
$packs = $ready( 'MAD4B_SCP_Certification_Pack_Registry', 'status' )
	? MAD4B_SCP_Certification_Pack_Registry::status() : array();
$restore = $ready( 'MAD4B_SCP_G8_Restore_Convergence', 'status' )
	? MAD4B_SCP_G8_Restore_Convergence::status() : array();
$g9 = $ready( 'MAD4B_SCP_G9_Restore_Convergence', 'status' )
	? MAD4B_SCP_G9_Restore_Convergence::status() : array();
$accepted_client = is_array( $external ) && true === ( $external['verified'] ?? false )
	&& true === ( $external['inventory_match'] ?? false )
	&& true === ( $external['package_identity_match'] ?? false );
$sample_count = is_array( $frontend ) ? (int) ( $frontend['evaluation_window']['sample_count'] ?? 0 ) : 0;
$checks = array(
	'exact_staging' => $site,
	'restore_binding_current' => is_array( $binding ),
	'exact_runtime_binding_present' => is_string( $runtime ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $runtime ),
	'slo_history_integrity' => is_array( $slo ) && true === ( $slo['integrity_valid'] ?? false )
		&& is_string( $slo['outcome_chain_sha256'] ?? null ),
	'unknown_worker_outcomes_absent' => is_array( $slo ) && 0 === ( $slo['pending_count'] ?? -1 ),
	'frontend_min_three_real_samples' => is_array( $frontend ) && true === ( $frontend['ready'] ?? false )
		&& $sample_count >= 3,
	'external_real_client_inventory' => $accepted_client,
	'pack_registry_current_restore_epoch' => is_array( $packs ) && is_array( $binding )
		&& ( $packs['restore_epoch'] ?? null ) === ( $binding['epoch'] ?? null ),
	'g9_restore_fence_current' => is_array( $g9 )
		&& ( $g9['contract'] ?? '' ) === 'mad4b.g9.restore-convergence.v1'
		&& true === ( $g9['origin_and_generation_ready'] ?? false ),
);
$missing = array();
foreach ( $checks as $name => $passed ) if ( ! $passed ) $missing[] = $name;
$evidence = array(
	'contract' => 'mad4b.g8.readonly-staging-acceptance-evidence.v1',
	'observed_at' => gmdate( 'c' ),
	'profile_sha256' => MAD4B_SCP_G8_Record::profile(),
	'runtime_sha256' => is_string( $runtime ) ? $runtime : '',
	'restore_epoch' => is_array( $binding ) ? $binding['epoch'] : null,
	'external_restore_record_sha256' => is_array( $binding ) ? $binding['external_record_sha256'] : null,
	'checks' => $checks,
	'missing_technical_evidence' => $missing,
	'frontend_sample_count' => $sample_count,
	'local_automation_outcome_root_sha256' => is_array( $slo ) ? ( $slo['outcome_chain_sha256'] ?? null ) : null,
	'local_restore_blockers' => is_array( $restore ) ? ( $restore['blockers'] ?? array( 'unknown_restore_readback' ) ) : array( 'unknown_restore_readback' ),
	'technical_observations_ready' => empty( $missing ),
	'independent_provider_effect_inventory_certified' => false,
	'signed_post_restore_governed_approval_verified' => false,
	'release_acceptance' => false,
	'write_resume_allowed' => false,
	'authorizing' => false, 'mutation_performed' => false,
);
$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
	: json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
if ( ! is_string( $json ) ) {
	fwrite( STDERR, "Could not encode read-only acceptance snapshot.\n" ); exit( 1 );
}
echo $json . PHP_EOL;
