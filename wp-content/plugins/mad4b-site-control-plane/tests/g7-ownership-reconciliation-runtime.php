<?php
/* Hermetic G7 ownership gates. No WordPress DB, writes or live provider calls. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code; private $data;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
class MAD4B_SCP_Site_Profile {
    public static $verification_error = false;
    public static function configured() { return true; }
    public static function origin_enrolled() { return true; }
    public static function site_urls_match_enrollment() { return true; }
    public static function site_uuid() { return 'site-test'; }
    public static function current_environment() { return 'staging'; }
    public static function profile_digest() { return str_repeat( 'a', 64 ); }
    public static function current_origin() { return 'https://unit.test'; }
    public static function deployment_binding_proof( $purpose, $digest ) { return hash_hmac( 'sha256', $purpose . ':' . $digest, 'g7-test-key' ); }
    public static function verify_deployment_binding_proof( $purpose, $digest, $proof ) {
        if ( self::$verification_error ) return new WP_Error( 'native_verifier_failed' );
        return is_string( $proof ) && hash_equals( self::deployment_binding_proof( $purpose, $digest ), $proof );
    }
}
class MAD4B_SCP_Runtime_Generation_Fence {
    public static $generation = '';
    public static function capture() { return array( 'generation_sha256' => self::$generation ); }
}
class MAD4B_SCP_Live_Acceptance_Observer {
    public static function build_provenance_status() {
        return array( 'runtime_manifest_match' => true, 'package_manifest_digest' => str_repeat( 'd', 64 ) );
    }
}
class MAD4B_SCP_Restore_Epoch {
    public static function status( $init = false, $refresh = false ) {
        return array( 'ready' => true, 'epoch' => 1, 'external_record_sha256' => str_repeat( 'e', 64 ) );
    }
}
MAD4B_SCP_Runtime_Generation_Fence::$generation = str_repeat( 'c', 64 );
class MAD4B_SCP_Execution_Receipt {
    public static function verify( $receipt ) {
        if ( empty( $receipt['signed_test_receipt'] ) ) return new WP_Error( 'invalid_receipt' );
        return array( 'valid' => true, 'cryptographic_signature_verified' => true,
            'receipt_sha256' => $receipt['receipt_sha256'] );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-ownership-reconciliation.php';
function g7_assert( $ok, $message ) { if ( ! $ok ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
function g7_error( $value, $part ) { g7_assert( is_wp_error( $value ) && false !== strpos( $value->get_error_code(), $part ), 'expected fail closed: ' . $part ); }
function g7_binding() {
    return array( 'contract' => MAD4B_SCP_Adaptive_Operations_Context::CONTRACT, 'site_uuid' => 'site-test', 'environment' => 'staging', 'profile_digest' => str_repeat( 'a', 64 ), 'origin_sha256' => hash( 'sha256', 'https://unit.test' ), 'runtime_generation' => str_repeat( 'c', 64 ), 'artifact_sha256' => str_repeat( 'd', 64 ), 'restore_epoch' => 1, 'external_record_sha256' => str_repeat( 'e', 64 ) );
}
function g7_baseline( $fields, $owners ) {
    $snapshot = array( 'resource_id' => 'post:7', 'revision' => 1, 'owner_revision' => 1, 'fields' => $fields, 'owners' => $owners, 'target_fingerprint' => str_repeat( 'f', 64 ) );
    $readback = MAD4B_SCP_Ownership_Reconciliation::baseline_readback_material( $snapshot );
    g7_assert( ! is_wp_error( $readback ), 'normalized snapshot readback payload' );
    $sha = hash( 'sha256', wp_json_encode( array( 'readback' => $readback ),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    $receipt = array( 'signed_test_receipt' => true, 'receipt_sha256' => str_repeat( '9', 64 ),
        'target_fingerprint' => $snapshot['target_fingerprint'],
        'stages' => array( 'readback_reconciliation' => array(
            'status' => 'PASS', 'evidence_type' => 'readback_or_reconciliation',
            'evidence_sha256' => $sha ) ) );
    $unbound = $receipt;
    $unbound['stages']['readback_reconciliation']['evidence_sha256'] = str_repeat( '0', 64 );
    g7_error( MAD4B_SCP_Ownership_Reconciliation::managed_baseline( $snapshot, g7_binding(), $unbound ),
        'baseline_snapshot_readback_unbound' );
    $foreign = $snapshot; $foreign['fields']['foreign_field'] = 'not-in-signed-readback';
    g7_error( MAD4B_SCP_Ownership_Reconciliation::managed_baseline( $foreign, g7_binding(), $receipt ),
        'baseline_snapshot_readback_unbound' );
    $missing = $receipt; unset( $missing['signed_test_receipt'] );
    g7_assert( is_wp_error( MAD4B_SCP_Ownership_Reconciliation::managed_baseline( $snapshot, g7_binding(), $missing ) ),
        'unsigned readback cannot initialize Last Managed' );
    return MAD4B_SCP_Ownership_Reconciliation::managed_baseline( $snapshot, g7_binding(), $receipt );
}
$policy = array( 'title' => array( 'owner' => 'managed', 'bounded' => true, 'non_authorizing' => true ) );
$baseline = g7_baseline( array( 'title' => 'previous' ), array( 'title' => 'managed' ) );
g7_assert( ! is_wp_error( $baseline ), 'sealed managed baseline' );
$current = $baseline; unset( $current['lineage_proof'], $current['lineage_sha256'] );
$desired = $current; $desired['fields']['title'] = 'safe';
$plan = MAD4B_SCP_Ownership_Reconciliation::plan( $baseline, $current, $desired, $policy, g7_binding() );
g7_assert( ! is_wp_error( $plan ) && 'BOUNDED_REPAIR_PLANNED' === $plan['state'] && ! $plan['authorizing'], 'bounded plan no authority' );
MAD4B_SCP_Site_Profile::$verification_error = true;
g7_error( MAD4B_SCP_Ownership_Reconciliation::commit_guard( $plan, $current, $policy, g7_binding() ), 'sealed_evidence_foreign' );
MAD4B_SCP_Site_Profile::$verification_error = false;
$wrong_contract = g7_binding(); $wrong_contract['contract'] = 'foreign.binding.v1';
g7_error( MAD4B_SCP_Adaptive_Operations_Context::validate( $wrong_contract ), 'binding_contract_invalid' );
$guard = MAD4B_SCP_Ownership_Reconciliation::commit_guard( $plan, $current, $policy, g7_binding() );
g7_assert( is_array( $guard ) && ! empty( $guard['native_cas_required'] ), 'guard still requires native CAS' );
$after = $current; $after['fields']['title'] = 'safe'; $after['revision']++;
$readback = MAD4B_SCP_Ownership_Reconciliation::verify_readback( $plan, $after, g7_binding() );
g7_assert( is_array( $readback ) && true === $readback['verified'] && ! $readback['authorizing'], 'full readback' );
MAD4B_SCP_Runtime_Generation_Fence::$generation = str_repeat( '9', 64 );
g7_error( MAD4B_SCP_Ownership_Reconciliation::commit_guard( $plan, $current, $policy, g7_binding() ), 'binding_runtime_generation_changed' );
g7_error( MAD4B_SCP_Ownership_Reconciliation::verify_readback( $plan, $after, g7_binding() ), 'binding_runtime_generation_changed' );
MAD4B_SCP_Runtime_Generation_Fence::$generation = str_repeat( 'c', 64 );
$partial = $after; $partial['fields']['title'] = 'wrong';
g7_error( MAD4B_SCP_Ownership_Reconciliation::verify_readback( $plan, $partial, g7_binding() ), 'partial_apply_readback' );
$other = $after; $other['fields']['unrelated'] = 123;
g7_error( MAD4B_SCP_Ownership_Reconciliation::verify_readback( $plan, $other, g7_binding() ), 'unrelated_state' );
$concurrent = $current; $concurrent['revision']++;
g7_error( MAD4B_SCP_Ownership_Reconciliation::commit_guard( $plan, $concurrent, $policy, g7_binding() ), 'concurrent_edit' );
$other_binding = g7_binding(); $other_binding['restore_epoch'] = 2;
g7_error( MAD4B_SCP_Ownership_Reconciliation::commit_guard( $plan, $current, $policy, $other_binding ), 'binding_restore_epoch_changed' );
$bad_plan_type = $plan; $bad_plan_type['sealed_plan'] = 'invalid-object';
g7_error( MAD4B_SCP_Ownership_Reconciliation::commit_guard( $bad_plan_type, $current, $policy, g7_binding() ), 'plan_evidence_format_invalid' );
$tampered = $plan; $tampered['plan_sha256'] = array( 'not-a-hash' );
g7_error( MAD4B_SCP_Ownership_Reconciliation::commit_guard( $tampered, $current, $policy, g7_binding() ), 'plan_digest_changed' );
$unknown = $current; $unknown['owners']['title'] = 'nobody';
g7_error( MAD4B_SCP_Ownership_Reconciliation::plan( $baseline, $unknown, $desired, $policy, g7_binding() ), 'owner_record_invalid' );
$locked_policy = $policy; $locked_policy['title']['locked'] = true;
$locked = MAD4B_SCP_Ownership_Reconciliation::plan( $baseline, $current, $desired, $locked_policy, g7_binding() );
g7_assert( 'APPROVAL_REQUIRED' === $locked['state'], 'locked requires review' );
g7_error( MAD4B_SCP_Ownership_Reconciliation::commit_guard( $locked, $current, $locked_policy, g7_binding() ), 'review_required' );
$provider = $current; $provider['owners']['title'] = 'provider';
$pplan = MAD4B_SCP_Ownership_Reconciliation::plan( $baseline, $provider, $desired, $policy, g7_binding() );
g7_assert( 'APPROVAL_REQUIRED' === $pplan['state'], 'provider ownership change requires review' );
$invalid_lineage = $baseline; $invalid_lineage['lineage_proof'] = 'invalid-object';
g7_error( MAD4B_SCP_Ownership_Reconciliation::plan( $invalid_lineage, $current, $desired, $policy, g7_binding() ), 'baseline_lineage_format_invalid' );
$missing = $baseline; unset( $missing['lineage_proof'] );
$missing_plan = MAD4B_SCP_Ownership_Reconciliation::plan( $missing, $current, $desired, $policy, g7_binding() );
g7_assert( 'APPROVAL_REQUIRED' === $missing_plan['state'], 'missing managed lineage blocks auto-repair' );
g7_error( MAD4B_SCP_Ownership_Reconciliation::commit_guard( $missing_plan, $current, $policy, g7_binding() ), 'missing_managed_baseline' );
$missing_noop = MAD4B_SCP_Ownership_Reconciliation::plan( $missing, $current, $current, $policy, g7_binding() );
g7_assert( 'APPROVAL_REQUIRED' === $missing_noop['state'], 'invalid baseline cannot masquerade as harmless no-op' );
g7_error( MAD4B_SCP_Ownership_Reconciliation::commit_guard( $missing_noop, $current, $policy, g7_binding() ), 'missing_managed_baseline' );
g7_error( MAD4B_SCP_Ownership_Reconciliation::verify_readback( $missing_noop, $current, g7_binding() ), 'missing_managed_baseline' );
$invalid_snapshot = $baseline; $invalid_snapshot['fields'] = 'not-an-array';
g7_error( MAD4B_SCP_Ownership_Reconciliation::plan( $invalid_snapshot, $current, $desired, $policy, g7_binding() ), 'snapshot_incomplete' );
$secret = g7_baseline( array( 'api_token' => 'old' ), array( 'api_token' => 'managed' ) );
$secret_current = $secret; unset( $secret_current['lineage_proof'], $secret_current['lineage_sha256'] );
$secret_want = $secret_current; $secret_want['fields']['api_token'] = 'new';
$secret_policy = array( 'api_token' => array( 'owner' => 'managed', 'bounded' => true, 'non_authorizing' => true ) );
$splan = MAD4B_SCP_Ownership_Reconciliation::plan( $secret, $secret_current, $secret_want, $secret_policy, g7_binding() );
g7_assert( 'APPROVAL_REQUIRED' === $splan['state'], 'sensitive fields never auto-repaired' );
$human_base = g7_baseline( array( 'title' => 'human-original' ), array( 'title' => 'human' ) );
$human_current = $human_base; unset( $human_current['lineage_proof'], $human_current['lineage_sha256'] ); $human_current['fields']['title'] = 'human-edit';
$human_want = $human_current; $human_want['fields']['title'] = 'policy';
$human_policy = array( 'title' => array( 'preserve_human' => true, 'human_delta_valid' => true, 'merge_strategy' => 'preserve', 'bounded' => true, 'non_authorizing' => true ) );
$human_plan = MAD4B_SCP_Ownership_Reconciliation::plan( $human_base, $human_current, $human_want, $human_policy, g7_binding() );
g7_assert( 'NO_OP' === $human_plan['state'] && in_array( 'title', $human_plan['preserved_fields'], true ), 'valid human edit preserved' );
$hbad = $human_current; $hbad['fields']['title'] = 'overwritten';
g7_error( MAD4B_SCP_Ownership_Reconciliation::verify_readback( $human_plan, $hbad, g7_binding() ), 'human_delta_overwritten' );
$lang_base = g7_baseline( array( 'languages' => array( 'en' ) ), array( 'languages' => 'managed' ) );
$lang_current = $lang_base; unset( $lang_current['lineage_proof'], $lang_current['lineage_sha256'] ); $lang_current['fields']['languages'] = array( 'en', 'ar' );
$lang_want = $lang_current; $lang_want['fields']['languages'] = array( 'en', 'fr' );
$lang_policy = array( 'languages' => array( 'owner' => 'managed', 'preserve_human' => true, 'human_delta_valid' => true, 'merge_strategy' => 'additive_language_set', 'bounded' => true, 'non_authorizing' => true ) );
$lang_plan = MAD4B_SCP_Ownership_Reconciliation::plan( $lang_base, $lang_current, $lang_want, $lang_policy, g7_binding() );
g7_assert( 'BOUNDED_REPAIR_PLANNED' === $lang_plan['state'], 'language addition rebased' );
$lang_guard = MAD4B_SCP_Ownership_Reconciliation::commit_guard( $lang_plan, $lang_current, $lang_policy, g7_binding() );
g7_assert( 'VALID_HUMAN_REBASE' === $lang_guard['changes']['languages']['classification'] && $lang_guard['changes']['languages']['after']['value'] === array( 'ar', 'en', 'fr' ), 'additive language union and classification' );
$prod = g7_binding(); $prod['environment'] = 'production';
$prod_base = g7_baseline( array( 'title' => 'old' ), array( 'title' => 'managed' ) );
g7_error( MAD4B_SCP_Ownership_Reconciliation::commit_guard( $plan, $current, $policy, $prod ), 'binding_environment_changed' );
echo "mad4b.feature007-g7-ownership-safety.v1: PASS\n";
