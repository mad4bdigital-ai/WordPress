<?php
/* Isolated G9 site-local kernel tests, PHP 7.4/8.3; no live site or provider. */
define( 'ABSPATH', __DIR__ . '/' );
$_SERVER['DOCUMENT_ROOT'] = __DIR__;
define( 'MAD4B_SCP_G9_RELEASE_FENCE_ENABLED', true );
define( 'MAD4B_SCP_RESILIENCE_ANCHOR_DIRECTORY',
    sys_get_temp_dir() . '/mad4b-g9-state-' . bin2hex( random_bytes( 8 ) ) );
$GLOBALS['g9_test_options'] = array();
$GLOBALS['g9_registered_abilities'] = array();
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_option( $key, $default = false ) { return $GLOBALS['g9_test_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = false ) {
    $GLOBALS['g9_test_options'][ $key ] = $value; return true;
}
function add_action( $action, $cb, $priority = 10 ) { return true; }
function wp_register_ability( $name, $args ) { $GLOBALS['g9_registered_abilities'][ $name ] = $args; return true; }
function wp_has_ability( $name ) { return isset( $GLOBALS['g9_registered_abilities'][ $name ] ); }
function get_current_blog_id() { return 1; }
class WP_Error {
    private $code;
    public function __construct( $code, $message, $data = null ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function g9_assert( $ok, $reason ) {
    if ( ! $ok ) { fwrite( STDERR, 'G9 state FAIL: ' . $reason . "\n" ); exit( 1 ); }
}
function g9_denied( $value, $reason ) {
    g9_assert( is_wp_error( $value ) && false !== strpos( $value->get_error_code(), $reason ), $reason );
}
class MAD4B_SCP_Site_Profile {
    public static $revision = 1;
    public static function site_uuid() { return '11111111-1111-4111-8111-111111111111'; }
    public static function current_environment() { return 'staging'; }
    public static function current_origin() { return 'https://staging.example.invalid'; }
    public static function revision() { return self::$revision; }
    public static function profile_digest() { return hash( 'sha256', 'profile-' . self::$revision ); }
    public static function origin_enrolled() { return true; }
    public static function site_urls_match_enrollment() { return true; }
    public static function nonproduction_governed() { return true; }
}
class MAD4B_SCP_Runtime_Generation_Fence {
    public static function status() {
        return array(
            'ready' => true, 'generation_sha256' => hash( 'sha256', 'generation' ),
            'blockers' => array(), 'material' => array(
                'schema_installed_version' => 40,
                'persisted_contract_registry_sha256' => hash( 'sha256', 'registry' ),
                'disk_runtime_file_sha256' => hash( 'sha256', 'file' ),
                'disk_provenance_sha256' => hash( 'sha256', 'provenance' ),
                'config_generation_sha256' => hash( 'sha256', 'config' ),
            ),
        );
    }
}
class MAD4B_SCP_Restore_Epoch {
    public static function status( $initialize = false, $refresh = false ) {
        return array( 'ready'=>true, 'epoch'=>1,
            'external_record_sha256'=>hash( 'sha256', 'external-record' ), 'blockers'=>array() );
    }
}
class MAD4B_SCP_Live_Acceptance_Observer {
    public static function build_provenance_identity_status() {
        return array( 'package_manifest_digest'=>hash( 'sha256', 'package' ) );
    }
}
class MAD4B_SCP_Certification_Pack_Registry {
    public static function status() { return array( 'revision'=>0, 'active'=>array() ); }
}
class MAD4B_SCP_Staging_Write_Authority {
    public static $call_count = 0;
    public static $deny_on_call = 0;
    public static function current_execution_readiness() {
        self::$call_count++;
        $ready = self::$deny_on_call !== self::$call_count;
        return array( 'ready'=>$ready, 'current_grant_snapshot_ready'=>$ready,
            'candidate_binding_match'=>$ready,
            'grant_rows_fingerprint'=>hash( 'sha256', 'grants' ) );
    }
    public static function candidate_binding_status() { return array( 'match'=>true ); }
}
class MAD4B_SCP_Policy {
    public static $mutable = true;
    public static function can_mutate() { return self::$mutable; }
    public static function can_read() { return true; }
}
class MAD4B_SCP_Authorization {
    public static $admitted = true;
    public static function authorize_mutation( $ability, $category, $provider, $input ) {
        return self::$admitted && $ability === 'mad4b/g9-release-reserve';
    }
}
require_once __DIR__ . '/../includes/class-mad4b-scp-g9-read-surface.php';
class G9_Exact_Reader implements MAD4B_SCP_Resilience_Reader {
    public function read_local( array $binding ) {
        $key = MAD4B_SCP_Resilience_Context::site_key( $binding );
        return array(
            'binding_sha256' => MAD4B_SCP_Resilience_Context::digest( $binding ),
            'providers' => array( 'certified' => array(
                'site_key'=>$key, 'generation_sha256'=>$binding['runtime_generation_sha256'],
                'certification_sha256'=>hash( 'sha256', 'cert' ), 'ready'=>true, 'revoked'=>false,
            ) ),
            'host' => array( 'isolation_verified'=>true, 'local_readback_verified'=>true ),
            'health' => array( 'sample_count'=>90, 'error_rate_bps'=>1, 'p95_ms'=>40,
                'observed_at'=>MAD4B_SCP_Resilience_Context::now() ),
            'external_effects' => array(), 'gates'=>array(
                'prior_ring_health_accepted'=>false,
                'provider_inventory_complete'=>true, 'host_inventory_complete'=>true,
                'external_effect_inventory_complete'=>true, 'health_sample_window_complete'=>true ),
        );
    }
}
g9_assert( true === MAD4B_SCP_Resilience_Context::register_reader( new G9_Exact_Reader() ), 'reader registration' );
$observation = MAD4B_SCP_Resilience_Context::capture();
g9_assert( ! is_wp_error( $observation ) && $observation['authority']['eligible'], 'current capture' );
g9_denied( MAD4B_SCP_Resilience_Context::register_reader( new G9_Exact_Reader() ), 'reader_already_registered' );
$binding = $observation['binding'];
$init = MAD4B_SCP_Resilience_Anchor::transact( $binding, 0, function ( $record ) {
    $record['scopes']['g9:init'] = array( 'initialized'=>true ); return $record;
} );
g9_assert( ! is_wp_error( $init ) && 1 === $init['revision'], 'durable initialize' );
$key = MAD4B_SCP_Resilience_Context::site_key( $binding );
$target = array(
    'contract'=>MAD4B_SCP_G9_Resilience_Gates::RING_CONTRACT,
    'ring'=>'pilot', 'cohort_id'=>'alpha-one',
    'site_key'=>$key, 'binding_sha256'=>$observation['binding_sha256'],
    'baseline_snapshot_sha256'=>$observation['snapshot_sha256'],
);
$limits = array( 'min_samples'=>30, 'max_error_rate_bps'=>10, 'max_p95_ms'=>80 );
$plan = MAD4B_SCP_G9_Release_Fence::plan( $target, $limits );
g9_assert( ! is_wp_error( $plan ) && !$plan['authorizing'] && !$plan['execution_supported'], 'bounded read plan' );
$bad = $plan; $bad['expires_at']++;
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $bad ), 'plan_tampered' );
MAD4B_SCP_Policy::$mutable = new WP_Error( 'blocked', 'deny' );
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $plan ), 'execution_authority_unavailable' );
MAD4B_SCP_Policy::$mutable = true;
MAD4B_SCP_Authorization::$admitted = false;
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $plan ), 'not_admitted' );
MAD4B_SCP_Authorization::$admitted = true;
// Revoke exact current authority between first grant check and locked
// external CAS. Neither reservation nor provider dispatch is permitted.
MAD4B_SCP_Staging_Write_Authority::$deny_on_call =
    MAD4B_SCP_Staging_Write_Authority::$call_count + 3;
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $plan ), 'grants_changed_before_cas' );
MAD4B_SCP_Staging_Write_Authority::$deny_on_call = 0;
$reserved = MAD4B_SCP_G9_Release_Fence::reserve( $plan );
g9_assert( ! is_wp_error( $reserved ) && 2 === $reserved['anchor_revision']
    && $reserved['state'] === 'fenced_not_dispatched'
    && !$reserved['release_accepted'] && !$reserved['provider_mutation_performed'],
    'site-local immutable reservation' );
$receipt = MAD4B_SCP_G9_Release_Fence::inspect( $binding, $reserved['operation_sha256'] );
g9_assert( ! is_wp_error( $receipt ) && $receipt['external_effect_unknown']
    && !$receipt['blind_retry_allowed'], 'unknown external effect remains uncertain' );
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $plan ), 'plan_superseded' );
$replanned = MAD4B_SCP_G9_Release_Fence::plan( $target, $limits );
g9_assert( ! is_wp_error( $replanned )
    && $replanned['plan_sha256'] !== $plan['plan_sha256'],
    'fresh plan has different issuance/anchor fingerprint' );
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $replanned ), 'replay_or_capacity' );
$foreign = $binding; $foreign['site_uuid'] = '22222222-2222-4222-8222-222222222222';
g9_denied( MAD4B_SCP_G9_Release_Fence::inspect( $foreign, $reserved['operation_sha256'] ), 'foreign_site' );
$baseline = MAD4B_SCP_Resilience_Context::capture();
$effects = array( 'payment'=>array( 'state'=>'unknown', 'site_key'=>$key,
    'receipt_sha256'=>hash( 'sha256', 'effect' ) ) );
$dr = MAD4B_SCP_G9_Restore_Convergence::inspect( $baseline, $effects );
g9_assert( ! is_wp_error( $dr ) && $dr['requires_quarantine']
    && count( $dr['unresolved_effect_keys'] )===1 && !$dr['post_restore_acceptance_issued'],
    'unrewound external effects remain quarantined' );
MAD4B_SCP_Site_Profile::$revision = 2;
$changed = MAD4B_SCP_G9_Restore_Convergence::inspect( $baseline, array() );
g9_assert( ! is_wp_error( $changed ) && $changed['requires_quarantine']
    && in_array( 'site_profile', $changed['changed_facets'], true ), 'profile restore drift' );
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $plan ), 'live_drift' );
MAD4B_SCP_Site_Profile::$revision = 1; // Return to original exact binding for receipt correlation.
// Native journal/receipt correlation cannot turn a foreign or unknown result
// into a release certificate. Real cryptographic verification is owned by
// existing WordPress runtime; this hermetic stub checks fail-closed wiring.
class MAD4B_SCP_Execution_State_View {
    const CONTRACT = 'mad4b.execution-state-view.v1';
    const COMMITTED = 'COMMITTED';
    public static $state = 'RECONCILING';
    public static function operation( $id ) { return array(
        'contract'=>self::CONTRACT, 'canonical_state'=>self::$state,
        'terminal'=>self::$state==='COMMITTED',
        'reconciliation_required'=>self::$state!=='COMMITTED',
    ); }
}
class MAD4B_SCP_Execution_Receipt {
    public static $valid = true;
    public static function verify( array $receipt ) {
        if ( ! self::$valid ) return new WP_Error( 'crypto_invalid', 'signature denied' );
        return array( 'valid'=>true, 'cryptographic_signature_verified'=>true,
            'receipt_sha256'=>hash( 'sha256', 'verified-native' ) );
    }
}
$operation_id = 'g9-native:operation-0001';
$native = array( 'request_id'=>$operation_id,
    'target_fingerprint'=>$reserved['operation_sha256'] );
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $native ),
    'native_execution_uncertain' );
MAD4B_SCP_Execution_State_View::$state = 'COMMITTED';
$incorrect = $native; $incorrect['target_fingerprint'] = hash( 'sha256', 'foreign' );
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $incorrect ),
    'native_receipt_unbound' );
MAD4B_SCP_Execution_Receipt::$valid = false;
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $native ),
    'native_signature_invalid' );
MAD4B_SCP_Execution_Receipt::$valid = true;
$verified = MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $native );
g9_assert( ! is_wp_error( $verified ) && $verified['native_execution_evidence_verified']
    && !$verified['site_local_release_accepted'], 'native receipt is not release acceptance' );
MAD4B_SCP_G9_Read_Surface::boot();
MAD4B_SCP_G9_Read_Surface::register_abilities();
g9_assert( count( $GLOBALS['g9_registered_abilities'] ) === 2, 'two read-only abilities' );
foreach ( $GLOBALS['g9_registered_abilities'] as $ability => $args ) {
    g9_assert( $args['meta']['annotations']['readonly'] === true
        && $args['meta']['mcp']['surface'] === 'read'
        && $args['input_schema']['additionalProperties'] === false, 'read-only ability ' . $ability );
}
$read = MAD4B_SCP_G9_Read_Surface::site_observation();
g9_assert( ! is_wp_error( $read ) && !$read['release_execution_supported'], 'site observation not executing' );
g9_denied( MAD4B_SCP_G9_Read_Surface::site_observation( array('site'=>'foreign') ), 'read_input_invalid' );
$unsigned = MAD4B_SCP_G9_Restore_Convergence::inspect( $baseline, array(
    'declared_success' => array( 'state'=>'verified_reconciled',
        'site_key'=>$key, 'receipt_sha256'=>hash( 'sha256', 'untrusted-claim' ) ),
) );
g9_assert( ! is_wp_error( $unsigned ) && !$unsigned['external_effects_verified']
    && $unsigned['requires_quarantine'] && !$unsigned['post_restore_acceptance_issued'],
    'unsigned success claim cannot clear restore quarantine' );
$empty = MAD4B_SCP_G9_Restore_Convergence::inspect( $baseline, array() );
g9_assert( ! is_wp_error( $empty ) && !$empty['external_effects_verified']
    && $empty['requires_quarantine'], 'empty inventory cannot imply native effect verification' );
$state = MAD4B_SCP_G9_Restore_Convergence::status();
g9_assert( ! is_wp_error( $state ) && !$state['write_reenabled'], 'restore status never grants' );
$path = MAD4B_SCP_RESILIENCE_ANCHOR_DIRECTORY . '/resilience-' . $binding['site_uuid'] . '-1-staging.json';
@unlink( $path ); @unlink( $path . '.lock' ); @rmdir( MAD4B_SCP_RESILIENCE_ANCHOR_DIRECTORY );
echo "G9 release/DR site-local runtime: PASS (admission, drift, replay, isolation, bounded receipts)\n";
