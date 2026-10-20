<?php
/** Hermetic advice regressions: no WordPress writes or provider requests. */
define( 'ABSPATH', __DIR__ . '/' );
foreach ( array( 'staging-write-authority', 'skill-runtime-certification', 'google-drive-context' ) as $file ) {
 require dirname( __DIR__ ) . '/includes/class-mad4b-scp-' . $file . '.php';
}
function guidance_check( $ok, $message ) { if ( ! $ok ) throw new RuntimeException( $message ); }
$current = array( 'ready' => false, 'persisted_ready' => true, 'current_grant_snapshot_ready' => true,
 'candidate_binding_required' => true, 'candidate_binding_match' => false,
 'blockers' => array( 'runtime_authority_candidate_not_reconciled' ) );
$advice = MAD4B_SCP_Staging_Write_Authority::repair_guidance( $current );
guidance_check( $advice['candidate_binding_only'] && ! $advice['grant_recreation_required_by_binding_drift'] && $advice['existing_reconciliation_preflight_required'], 'clean grants plus binding drift must retain exact binding-only preflight' );
foreach ( array( 'persisted_ready', 'current_grant_snapshot_ready', 'candidate_binding_required' ) as $field ) {
 $changed = $current; $changed[$field] = false;
 guidance_check( ! MAD4B_SCP_Staging_Write_Authority::repair_guidance( $changed )['candidate_binding_only'], 'partial authority became binding-only: ' . $field );
 $changed[$field] = 'true';
 guidance_check( ! MAD4B_SCP_Staging_Write_Authority::repair_guidance( $changed )['candidate_binding_only'], 'truthy authority became binding-only' );
}
$changed = $current; $changed['blockers'][] = 'subject_revoked';
guidance_check( ! MAD4B_SCP_Staging_Write_Authority::repair_guidance( $changed )['candidate_binding_only'], 'additional blocker was hidden' );
guidance_check( ! MAD4B_SCP_Staging_Write_Authority::repair_guidance( array() )['candidate_binding_only'], 'unknown scan became binding-only' );
$live = array( 'ready' => true, 'persistence' => 'read_only_live_inspection', 'checks' => array( 'build_provenance_identity_ready' => true ), 'blockers' => array() );
$historical = array( 'ready' => false, 'build_identity_current' => false );
$skills = MAD4B_SCP_Skill_Runtime_Certification::repair_guidance( $live, $historical );
guidance_check( $skills['local_runtime_ready'] && false === $skills['skills_reconcile_required'] && 'record_current_build_certification' === $skills['next_action'], 'stale certificate caused healthy Skills file repair' );
$changed = $live; $changed['persistence'] = 'historical_evidence_only';
guidance_check( ! MAD4B_SCP_Skill_Runtime_Certification::repair_guidance( $changed, $historical )['local_runtime_ready'], 'historical certificate became live evidence' );
$changed = $live; $changed['checks']['build_provenance_identity_ready'] = false;
guidance_check( ! MAD4B_SCP_Skill_Runtime_Certification::repair_guidance( $changed, $historical )['local_runtime_ready'], 'unbound live evidence became ready' );
$changed = $live; $changed['ready'] = false; $changed['blockers'] = array( 'seed_pack_not_ready' );
guidance_check( true === MAD4B_SCP_Skill_Runtime_Certification::repair_guidance( $changed, $historical )['skills_reconcile_required'], 'real seed drift was ignored' );
foreach ( array(
 'refresh_required' => 'perform_governed_refresh_then_verify_provider_read',
 'refresh_failed' => 'inspect_refresh_failure_and_backoff',
 'healthy' => 'verify_provider_read_continuity',
 'revocation_pending' => 'complete_governed_revocation',
 'disconnected' => 'inspect_connection_state',
) as $state => $action ) {
 $connection = MAD4B_SCP_Google_Drive_Context::connection_verification_guidance( array( 'health_state' => $state, 'read_available' => true, 'write_available' => true ) );
 guidance_check( $action === $connection['next_action'] && ! $connection['provider_continuity_verified_by_status'] && ! $connection['write_certification_evaluated'], 'scope availability falsely proved continuity/certification: ' . $state );
 guidance_check( ! $connection['execution_allowed_by_guidance'] && ! $connection['mutation_performed'], 'advice became provider execution' );
}
$connection = MAD4B_SCP_Google_Drive_Context::connection_verification_guidance( array( 'health_state' => 'refresh_failed', 'reconnect_required' => true ) );
guidance_check( 'reconnect_provider_with_owner' === $connection['next_action'], 'invalid-grant reconnect path lost' );
// A provider budget timeout is a bounded incomplete scan, never proof of
// OAuth refresh failure, missing files or an authorized Source Scan Apply.
if ( ! class_exists( 'WP_Error' ) ) {
 class WP_Error { private $code; function __construct( $code, $message = '' ) { $this->code = $code; } function get_error_code() { return $this->code; } }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ) { return $value instanceof WP_Error; } }
$budget = new WP_Error( 'mad4b_google_drive_scan_time_budget_exhausted', 'Budget exhausted' );
$auth = new WP_Error( 'mad4b_google_drive_token_refresh_failed', 'Not a scan budget error' );
guidance_check( MAD4B_SCP_Google_Drive_Context::is_scan_budget_exhaustion( $budget ), 'Provider scan budget not classified' );
guidance_check( ! MAD4B_SCP_Google_Drive_Context::is_scan_budget_exhaustion( $auth ), 'OAuth failure was masked as partial scan' );
guidance_check( ! MAD4B_SCP_Google_Drive_Context::is_scan_budget_exhaustion( array( 'complete' => false ) ), 'Raw partial data was treated as an exact budget error' );
echo "POST_MANUAL_UPDATE_GUIDANCE: PASS (hermetic; no live acceptance)\n";
