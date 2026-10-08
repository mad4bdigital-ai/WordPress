<?php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { public function __construct( $code, $message = '', $data = array() ) {} }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x, $flags = 0 ) { return json_encode( $x, $flags ); }
class MAD4B_SCP_Site_Profile {
  public static function configured() { return true; }
  public static function origin_enrolled() { return true; }
  public static function site_urls_match_enrollment() { return true; }
  public static function site_uuid() { return 'site-test'; }
  public static function current_environment() { return 'staging'; }
  public static function profile_digest() { return str_repeat( 'a', 64 ); }
  public static function current_origin() { return 'https://test.invalid'; }
}
class MAD4B_SCP_Runtime_Generation_Fence {
  public static function capture() { return array( 'generation_sha256' => str_repeat( 'b', 64 ) ); }
}
class MAD4B_SCP_Live_Acceptance_Observer {
  public static function build_provenance_status() { return array( 'runtime_manifest_match' => true, 'package_manifest_digest' => str_repeat( 'c', 64 ) ); }
}
class MAD4B_SCP_Restore_Epoch {
  public static function status( $a = false, $b = false ) { return array( 'ready' => true, 'epoch' => 1, 'external_record_sha256' => str_repeat( 'd', 64 ) ); }
}
class MAD4B_SCP_Execution_Receipt {
  const CONTRACT = 'mad4b.execution-receipt.v1';
  public static function verify( array $receipt ) {
    if ( empty( $receipt['signed_test_receipt'] ) ) return new WP_Error( 'invalid_crypto' );
    return array( 'valid' => true, 'cryptographic_signature_verified' => true, 'receipt_sha256' => $receipt['receipt_sha256'] );
  }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g7-compensation-audit.php';
function verify_gate( $pass, $msg ) { if ( ! $pass ) { fwrite( STDERR, "FAIL: $msg\n" ); exit( 1 ); } }
$original = array( 'contract' => MAD4B_SCP_Execution_Receipt::CONTRACT, 'signed_test_receipt' => true,
  'receipt_sha256' => str_repeat( 'e', 64 ), 'target_fingerprint' => 'post:7',
  'resource_set_sha256' => str_repeat( 'f', 64 ),
  'stages' => array( 'readback_reconciliation' => array( 'status' => 'PASS' ), 'terminal_outcome' => array( 'status' => 'PASS' ) ) );
$compensation = $original; $compensation['receipt_sha256'] = str_repeat( '1', 64 );
$bind = MAD4B_SCP_Adaptive_Operations_Context::current();
verify_gate( ! is_wp_error( $bind ), 'site binding' );
$ctx = array( 'binding' => $bind, 'original_before_sha256' => str_repeat( '2', 64 ),
  'original_after_sha256' => str_repeat( '3', 64 ),
  'compensation_before_sha256' => str_repeat( '3', 64 ),
  'compensation_after_sha256' => str_repeat( '2', 64 ),
  'external_effects_reconciled' => true, 'independent_post_restore_readback' => true,
  'original_outcome_committed' => true, 'compensation_outcome_committed' => true );
$result = MAD4B_SCP_G7_Compensation_Audit::assess( $original, $compensation, $ctx );
verify_gate( $result['state'] === 'APPROVAL_REQUIRED' && ! $result['undo_certified'] &&
  $result['evidence_consistent'] && ! $result['compensation_performed'], 'signed evidence still not Undo proof' );
$invalid = $original; unset( $invalid['signed_test_receipt'] );
$result = MAD4B_SCP_G7_Compensation_Audit::assess( $invalid, $compensation, $ctx );
verify_gate( $result['state'] === 'RECONCILIATION_REQUIRED', 'forged receipt denied' );
$bad = $compensation; $bad['resource_set_sha256'] = str_repeat( '4', 64 );
$result = MAD4B_SCP_G7_Compensation_Audit::assess( $original, $bad, $ctx );
verify_gate( $result['reason'] === 'target_or_resource_lineage_mismatch', 'foreign resource denied' );
$bad = $compensation; $bad['stages']['readback_reconciliation']['status'] = 'MISSING';
$result = MAD4B_SCP_G7_Compensation_Audit::assess( $original, $bad, $ctx );
verify_gate( $result['reason'] === 'native_readback_or_terminal_evidence_missing', 'partial apply denied' );
$bad = $compensation; $bad['receipt_sha256'] = $original['receipt_sha256'];
$result = MAD4B_SCP_G7_Compensation_Audit::assess( $original, $bad, $ctx );
verify_gate( $result['reason'] === 'self_compensation_denied', 'replayed original receipt denied' );
$badctx = $ctx; $badctx['binding']['restore_epoch'] = 2;
$result = MAD4B_SCP_G7_Compensation_Audit::assess( $original, $compensation, $badctx );
verify_gate( $result['reason'] === 'site_restore_or_runtime_binding_changed', 'restore drift denied' );
$badctx = $ctx; $badctx['external_effects_reconciled'] = false;
$result = MAD4B_SCP_G7_Compensation_Audit::assess( $original, $compensation, $badctx );
verify_gate( $result['reason'] === 'external_effect_or_independent_readback_unproven', 'external irreversible effect denied' );
$badctx = $ctx; $badctx['compensation_after_sha256'] = str_repeat( '5', 64 );
$result = MAD4B_SCP_G7_Compensation_Audit::assess( $original, $compensation, $badctx );
verify_gate( $result['reason'] === 'reversal_diffs_not_inverse', 'history-only rollback denied' );
echo "mad4b.feature007-g7-compensation-safety.v1: PASS\n";
