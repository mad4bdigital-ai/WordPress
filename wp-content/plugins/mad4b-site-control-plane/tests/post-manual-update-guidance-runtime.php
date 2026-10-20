<?php
/** Hermetic advice regressions: no WordPress writes or provider requests. */
define( 'ABSPATH', __DIR__ . '/' );
foreach ( array( 'staging-write-authority', 'skill-runtime-certification', 'google-drive-context', 'brand-context-builder' ) as $file ) {
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
// Enforce complete, unique, content-verified provider evidence before any
// Source Scan Apply, even with a matching owner ticket and plan.
$verified = array( 'complete' => true, 'truncated' => false, 'truncation_reasons' => array(),
 'assets' => array( array( 'file_id' => 'file-1', 'content_complete' => true,
 'content_hash' => str_repeat( 'a', 64 ) ) ) );
$plan = array( 'scan_complete' => true );
guidance_check( true === MAD4B_SCP_Brand_Context_Builder::source_scan_apply_admission( $plan, $verified ), 'complete provider scan was blocked' );
$bad = $verified; $bad['complete'] = false;
guidance_check( is_wp_error( MAD4B_SCP_Brand_Context_Builder::source_scan_apply_admission( $plan, $bad ) ), 'partial scan admitted write' );
$bad = $verified; $bad['truncated'] = true;
guidance_check( is_wp_error( MAD4B_SCP_Brand_Context_Builder::source_scan_apply_admission( $plan, $bad ) ), 'truncated scan admitted write' );
$bad = $verified; $bad['truncation_reasons'] = array( 'scan_time_budget' );
guidance_check( is_wp_error( MAD4B_SCP_Brand_Context_Builder::source_scan_apply_admission( $plan, $bad ) ), 'budget-truncated scan admitted write' );
$bad = $verified; $bad['partial_scan_requires_complete_rescan'] = true;
guidance_check( is_wp_error( MAD4B_SCP_Brand_Context_Builder::source_scan_apply_admission( $plan, $bad ) ), 'needs-rescan admitted write' );
$bad = $verified; $bad['assets'][0]['content_complete'] = false;
guidance_check( is_wp_error( MAD4B_SCP_Brand_Context_Builder::source_scan_apply_admission( $plan, $bad ) ), 'unnormalized file admitted write' );
$bad = $verified; $bad['assets'][0]['content_hash'] = 'not-a-sha';
guidance_check( is_wp_error( MAD4B_SCP_Brand_Context_Builder::source_scan_apply_admission( $plan, $bad ) ), 'unhashed file admitted write' );
$bad = $verified; $bad['assets'][] = $bad['assets'][0];
guidance_check( is_wp_error( MAD4B_SCP_Brand_Context_Builder::source_scan_apply_admission( $plan, $bad ) ), 'duplicate file identity admitted write' );
$bad = array( 'scan_complete' => false );
guidance_check( is_wp_error( MAD4B_SCP_Brand_Context_Builder::source_scan_apply_admission( $bad, $verified ) ), 'incomplete plan admitted write' );
// Provider scan planning must never call a missing asset absent unless the
// entire exact source inventory completed. The scan Apply path is separately
// rejected before any Context Authority registry mutation.
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $text ) { return trim( (string) $text ); } }
class MAD4B_SCP_Context_Authority {
 public static $apply_calls = 0;
 static function source( $id ) { return array( 'source_id' => $id, 'provider' => 'google_drive' ); }
 static function registry_revision() { return 9; }
 static function assets() { return array( array( 'source_id' => str_repeat( 'a', 64 ),
   'file_id' => 'previous-file', 'content_hash' => str_repeat( 'b', 64 ) ) ); }
 static function replace_source_assets( $source_id, $assets, $scan ) { ++self::$apply_calls; return array( 'written' => true ); }
}
class MAD4B_SCP_Context_Provider_Gateway {
 public static $mock_scan;
 static function scan_source( $id ) { return self::$mock_scan; }
}
$source_id = str_repeat( 'a', 64 );
MAD4B_SCP_Context_Provider_Gateway::$mock_scan = array( 'complete' => false, 'truncated' => true,
 'truncation_reasons' => array( 'scan_time_budget' ),
 'assets' => array( array( 'file_id' => 'new-file', 'content_complete' => true,
 'content_hash' => str_repeat( 'c', 64 ) ) ) );
$partial_plan = MAD4B_SCP_Brand_Context_Builder::source_scan_plan( array( 'source_id' => $source_id ) );
guidance_check( is_array( $partial_plan ) && false === $partial_plan['scan_complete']
 && false === $partial_plan['missing_files_certified'] && array() === $partial_plan['missing_files'],
 'partial folder scan falsely inferred missing prior files' );
$denied = MAD4B_SCP_Brand_Context_Builder::source_scan_apply( array( 'source_id' => $source_id,
 'expected_plan_sha256' => $partial_plan['plan_sha256'],
 'expected_provider_inventory_digest' => $partial_plan['provider_inventory_digest'],
 'expected_registry_revision' => 9 ) );
guidance_check( is_wp_error( $denied ) && 'mad4b_context_source_scan_complete_inventory_required' === $denied->get_error_code()
 && 0 === MAD4B_SCP_Context_Authority::$apply_calls, 'partial scan bypassed registry write admission' );
MAD4B_SCP_Context_Provider_Gateway::$mock_scan = array( 'complete' => true, 'truncated' => false,
 'truncation_reasons' => array(), 'assets' => array() );
$completed_plan = MAD4B_SCP_Brand_Context_Builder::source_scan_plan( array( 'source_id' => $source_id ) );
guidance_check( is_array( $completed_plan ) && true === $completed_plan['missing_files_certified']
 && array( 'previous-file' ) === $completed_plan['missing_files'], 'complete inventory did not produce precise missing-file evidence' );
echo "POST_MANUAL_UPDATE_GUIDANCE: PASS (hermetic; no live acceptance)\n";
