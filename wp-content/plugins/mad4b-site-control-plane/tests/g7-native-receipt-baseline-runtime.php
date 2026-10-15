<?php
/* Exact G7 native receipt ↔ owned-field readback contract (PHP 7.4 / 8.3).
 * Hermetic: no database, network, WordPress writes or signing service. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
class MAD4B_SCP_Crypto_Profile {
    public static $reject_as_false = false;
    public static $verification_override = '';
    public static function default_profile( $purpose ) { return 'fixture-signing-profile'; }
    public static function sign_digest( $profile_id, $sha ) {
        return array( 'profile_id' => $profile_id, 'kid' => 'fixture-kid',
            'signature' => hash_hmac( 'sha256', 'execution_receipt:' . $sha, 'fixture-private-key' ) );
    }
    public static function verify_digest_for_purpose( $signature, $sha, $purpose ) {
        if ( self::$reject_as_false ) return false;
        if ( 'execution_receipt' !== $purpose || ! is_array( $signature ) ||
            ! isset( $signature['signature'], $signature['profile_id'], $signature['kid'] ) ||
            ! hash_equals( hash_hmac( 'sha256', $purpose . ':' . $sha, 'fixture-private-key' ),
                (string) $signature['signature'] ) ) {
            return new WP_Error( 'fixture_signature_invalid' );
        }
        $verified = array( 'contract' => 'mad4b.detached-signature-verification.v1',
            'valid' => true, 'signed_sha256' => $sha, 'profile_id' => $signature['profile_id'],
            'kid' => $signature['kid'], 'purpose' => $purpose );
        switch ( self::$verification_override ) {
            case 'bad_valid': $verified['valid'] = false; break;
            case 'bad_digest': $verified['signed_sha256'] = str_repeat( '0', 64 ); break;
            case 'bad_kid': $verified['kid'] = 'foreign-kid'; break;
            case 'bad_profile': $verified['profile_id'] = 'foreign-profile'; break;
            case 'bad_purpose': $verified['purpose'] = 'foreign_purpose'; break;
        }
        return $verified;
    }
}
class MAD4B_SCP_Site_Profile {
    public static function configured() { return true; }
    public static function origin_enrolled() { return true; }
    public static function site_urls_match_enrollment() { return true; }
    public static function site_uuid() { return 'site-receipt-fixture'; }
    public static function current_environment() { return 'staging'; }
    public static function profile_digest() { return str_repeat( 'a', 64 ); }
    public static function current_origin() { return 'https://fixture.invalid'; }
    public static function deployment_binding_proof( $purpose, $digest ) {
        return hash_hmac( 'sha256', $purpose . ':' . $digest, 'fixture-binding-key' );
    }
    public static function verify_deployment_binding_proof( $purpose, $digest, $proof ) {
        return is_string( $proof ) && hash_equals( self::deployment_binding_proof( $purpose, $digest ), $proof );
    }
}
class MAD4B_SCP_Runtime_Generation_Fence {
    public static function capture() { return array( 'generation_sha256' => str_repeat( 'c', 64 ) ); }
}
class MAD4B_SCP_Live_Acceptance_Observer {
    public static function build_provenance_status() {
        return array( 'runtime_manifest_match' => true, 'package_manifest_digest' => str_repeat( 'd', 64 ) );
    }
}
class MAD4B_SCP_Restore_Epoch {
    public static function status( $initialize = false, $refresh = false ) {
        return array( 'ready' => true, 'epoch' => 1, 'external_record_sha256' => str_repeat( 'e', 64 ) );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-ownership-reconciliation.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-execution-receipt.php';
function g7_native_check( $condition, $why ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $why . PHP_EOL ); exit( 1 ); }
}
function g7_native_binding() { return MAD4B_SCP_Adaptive_Operations_Context::current(); }
function g7_native_receipt( array $snapshot, array $binding, array $extra = array() ) {
    $readback = MAD4B_SCP_Ownership_Reconciliation::baseline_readback_material( $snapshot, $binding );
    g7_native_check( ! is_wp_error( $readback ), 'generate current-owned-field exact readback payload' );
    $result = array_merge( array( 'readback' => $readback ), $extra );
    $claim = array(
        'ability' => 'mad4b/typed-owned-configuration-write',
        'provider' => 'core',
        'context_receipt_sha256' => str_repeat( '1', 64 ),
        'capability_descriptor_sha256' => str_repeat( '2', 64 ),
        'policy_decision_sha256' => str_repeat( '3', 64 ),
        'target_fingerprint' => $snapshot['target_fingerprint'],
        'resource_set_sha256' => str_repeat( '4', 64 ),
    );
    $terminal = array( 'receipt_id' => 'terminal:fixture',
        'receipt_sha256' => str_repeat( '5', 64 ) );
    return MAD4B_SCP_Execution_Receipt::build( $claim, $result, $terminal );
}
$binding = g7_native_binding();
g7_native_check( ! is_wp_error( $binding ), 'site/runtime/restore binding resolved' );
$snapshot = array( 'resource_id' => 'post:42', 'revision' => 6, 'owner_revision' => 2,
    'fields' => array( 'title' => 'Fixture title', 'languages' => array( 'en', 'ar' ) ),
    'owners' => array( 'title' => 'managed', 'languages' => 'managed' ),
    'target_fingerprint' => str_repeat( 'f', 64 ) );
$receipt = g7_native_receipt( $snapshot, $binding );
g7_native_check( ! is_wp_error( $receipt ), 'native receipt built' );
$verified = MAD4B_SCP_Execution_Receipt::verify( $receipt );
g7_native_check( ! is_wp_error( $verified ) && true === ( $verified['cryptographic_signature_verified'] ?? false ),
    'native cryptographic verifier accepted exact signed envelope' );
MAD4B_SCP_Crypto_Profile::$reject_as_false = true;
$false_signature = MAD4B_SCP_Execution_Receipt::verify( $receipt );
g7_native_check( is_wp_error( $false_signature ) &&
    'mad4b_execution_receipt_signature_verification_invalid' === $false_signature->get_error_code(),
    'false non-WP_Error verifier return is not a verified signature' );
$false_baseline = MAD4B_SCP_Ownership_Reconciliation::managed_baseline( $snapshot, $binding, $receipt );
g7_native_check( is_wp_error( $false_baseline ), 'Last Managed cannot accept false native crypto verification' );
MAD4B_SCP_Crypto_Profile::$reject_as_false = false;
foreach ( array( 'bad_valid', 'bad_digest', 'bad_kid', 'bad_profile', 'bad_purpose' ) as $adversarial ) {
    MAD4B_SCP_Crypto_Profile::$verification_override = $adversarial;
    $invalid_verification = MAD4B_SCP_Execution_Receipt::verify( $receipt );
    g7_native_check( is_wp_error( $invalid_verification ) &&
        'mad4b_execution_receipt_signature_verification_invalid' === $invalid_verification->get_error_code(),
        'native cryptographic verifier proof must be exact: ' . $adversarial );
}
MAD4B_SCP_Crypto_Profile::$verification_override = '';
$material = MAD4B_SCP_Ownership_Reconciliation::baseline_readback_material( $snapshot, $binding );
$expected = hash( 'sha256', wp_json_encode( array( 'readback' => $material ),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
g7_native_check( hash_equals( $expected, $receipt['stages']['readback_reconciliation']['evidence_sha256'] ),
    'native recursive canonicalizer and G7 readback hash agree exactly' );
$baseline = MAD4B_SCP_Ownership_Reconciliation::managed_baseline( $snapshot, $binding, $receipt );
g7_native_check( ! is_wp_error( $baseline ) && isset( $baseline['lineage_proof'] ) &&
    hash_equals( $receipt['receipt_sha256'], $baseline['lineage_sha256'] ),
    'Last Managed derives lineage from verified native signed receipt' );
$foreign = $snapshot; $foreign['fields']['title'] = 'Unverified edit';
$result = MAD4B_SCP_Ownership_Reconciliation::managed_baseline( $foreign, $binding, $receipt );
g7_native_check( is_wp_error( $result ) &&
    false !== strpos( $result->get_error_code(), 'baseline_snapshot_readback_unbound' ),
    'signed receipt cannot cover a changed snapshot' );
$old_binding = $binding; $old_binding['restore_epoch']++;
$old_receipt = g7_native_receipt( $snapshot, $old_binding );
g7_native_check( ! is_wp_error( $old_receipt ), 'build cryptographically valid old-epoch fixture' );
$result = MAD4B_SCP_Ownership_Reconciliation::managed_baseline( $snapshot, $binding, $old_receipt );
g7_native_check( is_wp_error( $result ) &&
    false !== strpos( $result->get_error_code(), 'baseline_snapshot_readback_unbound' ),
    'same snapshot signed under old restore epoch does not seed new baseline' );
$extra_receipt = g7_native_receipt( $snapshot, $binding,
    array( 'reconciliation_ref' => str_repeat( '7', 64 ) ) );
$result = MAD4B_SCP_Ownership_Reconciliation::managed_baseline( $snapshot, $binding, $extra_receipt );
g7_native_check( is_wp_error( $result ) &&
    false !== strpos( $result->get_error_code(), 'baseline_snapshot_readback_unbound' ),
    'ambiguous combined readback/reconciliation evidence cannot seed Last Managed' );
$tampered = $receipt; $tampered['stages']['readback_reconciliation']['evidence_sha256'] = str_repeat( '0', 64 );
g7_native_check( is_wp_error( MAD4B_SCP_Execution_Receipt::verify( $tampered ) ),
    'native receipt catches modified stage before G7 reads it' );
echo "mad4b.feature007-g7-native-receipt-baseline.v1: PASS\n";
