<?php
/* Isolated G9 site-local kernel tests, PHP 7.4/8.3; no live site or provider. */
define( 'ABSPATH', __DIR__ . '/' );
$_SERVER['DOCUMENT_ROOT'] = __DIR__;
define( 'MAD4B_SCP_G9_RELEASE_FENCE_ENABLED', true );
define( 'MAD4B_SCP_G9_RELEASE_LIMITS', array(
    'min_samples'=>30, 'max_error_rate_bps'=>10, 'max_p95_ms'=>80,
) );
define( 'MAD4B_SCP_RESILIENCE_ANCHOR_DIRECTORY',
    sys_get_temp_dir() . '/mad4b-g9-state-' . bin2hex( random_bytes( 8 ) ) );
$GLOBALS['g9_test_options'] = array();
$GLOBALS['g9_test_option_fail'] = false;
$GLOBALS['g9_registered_abilities'] = array();
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_option( $key, $default = false ) { return $GLOBALS['g9_test_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = false ) {
    if ( ! empty( $GLOBALS['g9_test_option_fail'] ) ) return false;
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
    const CONTRACT = 'mad4b.runtime-generation-fence.v1';
    public static $revoked = false;
    public static function assert_current( array $expected ) {
        $status = self::status();
        if ( self::$revoked || ( $expected['contract'] ?? '' ) !== self::CONTRACT
            || ( $expected['generation_sha256'] ?? '' ) !== $status['generation_sha256'] )
            return new WP_Error( 'generation_changed', 'generation drift' );
        return $status;
    }
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
    public static $valid = true;
    public static $full_valid = true;
    public static function build_provenance_status() {
        return array( 'manifest_valid'=>self::$full_valid,
            'runtime_manifest_match'=>self::$full_valid, 'stale'=>!self::$full_valid,
            'package_manifest_digest'=>hash( 'sha256', 'package' ) );
    }
    public static function build_provenance_identity_status() {
        return array( 'package_manifest_digest'=>hash( 'sha256', 'package' ),
            'identity_ready'=>self::$valid, 'manifest_valid'=>self::$valid );
    }
}
class MAD4B_SCP_Certification_Pack_Registry {
    const REGISTRY_CONTRACT = 'mad4b.certification-pack-registry.v1';
    public static $epoch = 1;
    public static function status() {
        return array( 'contract'=>self::REGISTRY_CONTRACT, 'revision'=>0,
            'restore_epoch'=>self::$epoch, 'active'=>array() );
    }
}
class MAD4B_SCP_Staging_Write_Authority {
    public static $call_count = 0;
    public static $deny_on_call = 0;
    public static $revoke_generation_on_call = 0;
    public static function current_execution_readiness() {
        self::$call_count++;
        if ( self::$revoke_generation_on_call === self::$call_count )
            MAD4B_SCP_Runtime_Generation_Fence::$revoked = true;
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
    public static $invalid_claim = false;
    public static function authorize_mutation( $ability, $category, $provider, $input ) {
        if ( ! self::$admitted ) return new WP_Error( 'not_admitted', 'deny' );
        return array(
            'ability'=>$ability, 'server_id'=>$category, 'provider'=>$provider,
            'grant_id'=>self::$invalid_claim ? 0 : 42,
            'policy_decision_sha256'=>hash( 'sha256', 'policy' ),
            'resource_set_sha256'=>hash( 'sha256', 'resource-set' ),
            'approval_impact_binding_sha256'=>hash( 'sha256', 'approved-impact' ),
        );
    }
}
require_once __DIR__ . '/../includes/class-mad4b-scp-g9-read-surface.php';
class G9_Exact_Reader implements MAD4B_SCP_Resilience_Reader {
    public static $host_certified = true;
    private $observed_at;
    public function __construct() { $this->observed_at = MAD4B_SCP_Resilience_Context::now(); }
    public function read_local( array $binding ) {
        $key = MAD4B_SCP_Resilience_Context::site_key( $binding );
        return array(
            'binding_sha256' => MAD4B_SCP_Resilience_Context::digest( $binding ),
            'providers' => array( 'certified' => array(
                'site_key'=>$key, 'generation_sha256'=>$binding['runtime_generation_sha256'],
                'certification_sha256'=>hash( 'sha256', 'cert' ), 'ready'=>true, 'revoked'=>false,
            ) ),
            'host' => array( 'isolation_verified'=>self::$host_certified,
                'local_readback_verified'=>self::$host_certified,
                'single_host_exclusive_verified'=>self::$host_certified ),
            'health' => array( 'sample_count'=>90, 'error_rate_bps'=>1, 'p95_ms'=>40,
                'observed_at'=>$this->observed_at ),
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
// An apparently well-formed manifest digest is not accepted while provenance
// identity_ready / manifest_valid is false. A restored registry epoch cannot
// silently be reused under a different restore binding.
MAD4B_SCP_Live_Acceptance_Observer::$valid = false;
$invalid_artifact = MAD4B_SCP_Resilience_Context::capture();
g9_assert( ! is_wp_error( $invalid_artifact ) && !$invalid_artifact['authority']['eligible']
    && in_array( 'artifact_identity_unready', $invalid_artifact['identity_blockers'], true ),
    'unsigned or unverified artifact identity denied' );
MAD4B_SCP_Live_Acceptance_Observer::$valid = true;
MAD4B_SCP_Certification_Pack_Registry::$epoch = 2;
$invalid_epoch = MAD4B_SCP_Resilience_Context::capture();
g9_assert( ! is_wp_error( $invalid_epoch ) && !$invalid_epoch['authority']['eligible']
    && in_array( 'registry_restore_epoch_mismatch', $invalid_epoch['identity_blockers'], true ),
    'restored registry epoch cannot inherit current authority' );
MAD4B_SCP_Certification_Pack_Registry::$epoch = 1;
g9_denied( MAD4B_SCP_Resilience_Context::register_reader( new G9_Exact_Reader() ), 'reader_already_registered' );
$binding = $observation['binding'];
// A world-writable leaf directory allows another OS account to replace
// the lock/state filenames and must never pass the local path policy.
$dir = MAD4B_SCP_RESILIENCE_ANCHOR_DIRECTORY;
if ( ! is_dir( $dir ) ) mkdir( $dir, 0700, true );
chmod( $dir, 0777 );
clearstatcache( true, $dir );
g9_denied( MAD4B_SCP_Resilience_Anchor::read( $binding ), 'directory_permissions_unsafe' );
chmod( $dir, 0700 );
clearstatcache( true, $dir );
chmod( $dir, 0770 );
clearstatcache( true, $dir );
g9_denied( MAD4B_SCP_Resilience_Anchor::read( $binding ), 'directory_permissions_unsafe' );
chmod( $dir, 0700 );
clearstatcache( true, $dir );
$wrong_blog = $binding; $wrong_blog['blog_id'] = 2;
g9_denied( MAD4B_SCP_Resilience_Anchor::read( $wrong_blog ), 'local_blog_mismatch' );
$wrong_origin = $binding; $wrong_origin['canonical_origin'] = 'https://clone.example.invalid';
g9_denied( MAD4B_SCP_Resilience_Anchor::read( $wrong_origin ), 'local_site_mismatch' );
$GLOBALS['g9_test_option_fail'] = true;
g9_denied( MAD4B_SCP_Resilience_Anchor::transact( $binding, 0, function( $record ) {
    $record['scopes']['g9:unsafe-before-marker'] = array( 'present'=>true );
    return $record;
} ), 'mirror_failed' );
$uninitialized = MAD4B_SCP_Resilience_Anchor::read( $binding );
g9_assert( ! is_wp_error( $uninitialized ) && 0 === $uninitialized['revision'],
    'marker failure must not publish external reservation' );
$GLOBALS['g9_test_option_fail'] = false;
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
$lax = $limits; $lax['max_p95_ms'] = 10000000;
$lax_plan = MAD4B_SCP_G9_Release_Fence::plan( $target, $lax );
g9_assert( ! is_wp_error( $lax_plan ), 'descriptive threshold preview' );
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $lax_plan ), 'policy_not_pinned' );
MAD4B_SCP_Live_Acceptance_Observer::$full_valid = false;
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $plan ), 'runtime_package_unverified' );
MAD4B_SCP_Live_Acceptance_Observer::$full_valid = true;
$bad = $plan; $bad['expires_at']++;
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $bad ), 'plan_tampered' );
MAD4B_SCP_Policy::$mutable = new WP_Error( 'blocked', 'deny' );
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $plan ), 'execution_authority_unavailable' );
MAD4B_SCP_Policy::$mutable = true;
MAD4B_SCP_Authorization::$admitted = false;
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $plan ), 'not_admitted' );
MAD4B_SCP_Authorization::$admitted = true;
MAD4B_SCP_Authorization::$invalid_claim = true;
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $plan ), 'not_admitted' );
MAD4B_SCP_Authorization::$invalid_claim = false;
// Revoke exact current authority between first grant check and locked
// external CAS. Neither reservation nor provider dispatch is permitted.
// Cause generation drift after plan read but before external CAS.
MAD4B_SCP_Staging_Write_Authority::$revoke_generation_on_call =
    MAD4B_SCP_Staging_Write_Authority::$call_count + 2;
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $plan ), 'generation_changed_before_cas' );
MAD4B_SCP_Runtime_Generation_Fence::$revoked = false;
MAD4B_SCP_Staging_Write_Authority::$revoke_generation_on_call = 0;
MAD4B_SCP_Staging_Write_Authority::$deny_on_call =
    MAD4B_SCP_Staging_Write_Authority::$call_count + 3;
g9_denied( MAD4B_SCP_G9_Release_Fence::reserve( $plan ), 'grants_changed_before_cas' );
MAD4B_SCP_Staging_Write_Authority::$deny_on_call = 0;
$reserved = MAD4B_SCP_G9_Release_Fence::reserve( $plan );
g9_assert( ! is_wp_error( $reserved ) && 2 === $reserved['anchor_revision']
    && $reserved['state'] === 'fenced_not_dispatched'
    && !$reserved['release_accepted'] && !$reserved['provider_mutation_performed'],
    'site-local immutable reservation' );
$original_marker = $GLOBALS['g9_test_options'][ MAD4B_SCP_Resilience_Anchor::MIRROR_OPTION ];
unset( $GLOBALS['g9_test_options'][ MAD4B_SCP_Resilience_Anchor::MIRROR_OPTION ] );
g9_denied( MAD4B_SCP_Resilience_Anchor::read( $binding ), 'mirror_missing' );
$GLOBALS['g9_test_options'][ MAD4B_SCP_Resilience_Anchor::MIRROR_OPTION ] = array(
    'site'=>$original_marker['site'], 'anchor_seen'=>false
);
g9_denied( MAD4B_SCP_Resilience_Anchor::read( $binding ), 'mirror_identity_mismatch' );
$GLOBALS['g9_test_options'][ MAD4B_SCP_Resilience_Anchor::MIRROR_OPTION ] = $original_marker;
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
// Exercise the REAL receipt builder/verifier and state normalization. Only the
// hermetic crypto transport and journal persistence are test doubles. The native
// UUID, transport request and authorization target deliberately remain distinct.
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
class MAD4B_SCP_Crypto_Profile {
    public static function default_profile( $purpose ) { return 'g9-hermetic-crypto'; }
    public static function sign_digest( $profile, $digest ) {
        return array( 'profile_id'=>$profile, 'kid'=>'g9-fixture',
            'signature'=>hash_hmac( 'sha256', $digest, 'hermetic-not-runtime-authority' ) );
    }
    public static function verify_digest_for_purpose( array $signature, $digest, $purpose ) {
        if ( $purpose !== 'execution_receipt'
            || ( $signature['profile_id'] ?? '' ) !== 'g9-hermetic-crypto'
            || ( $signature['kid'] ?? '' ) !== 'g9-fixture'
            || ! hash_equals( hash_hmac( 'sha256', $digest, 'hermetic-not-runtime-authority' ),
                (string) ( $signature['signature'] ?? '' ) ) )
            return new WP_Error( 'crypto_invalid', 'Hermetic signature denied' );
        return array( 'valid'=>true );
    }
}
class MAD4B_SCP_Operation_Journal {
    public static $state = 'RECONCILING';
    public static $foreign = false;
    public static $journal_unbound = false;
    public static $read_count = 0;
    public static $drift_on_read = 0;
    public static $trace = array();
    public static function status( $id ) {
        self::$read_count++;
        $drift = self::$drift_on_read === self::$read_count;
        return array(
            'contract'=>'mad4b.dynamic-operation-status.v1',
            'operation_id'=>self::$foreign ? '33333333-3333-4333-8333-333333333333' : $id,
            'operation_key'=>'native-release:fixture-site-0001',
            'operation_binding_sha256'=>hash( 'sha256', 'native-context-binding' ),
            'journal_head_sha256'=>self::$journal_unbound ? '' : hash( 'sha256', $drift ? 'changed-native-head' : 'append-only-head' ),
            'latest_sequence'=>5,
            'lifecycle_state'=>self::$state==='COMMITTED' ? 'completed' : 'running',
            'terminal_outcome'=>self::$state==='COMMITTED' ? 'committed' : '',
            'orphan_candidate'=>self::$state!=='COMMITTED',
            'stale_heartbeat'=>false, 'lock_expired'=>false,
            'hard_deadline_exceeded'=>false,
        );
    }
    public static function trace( $id, $limit = 200 ) { return self::$trace; }
}
require_once __DIR__ . '/../includes/class-mad4b-scp-execution-state-view.php';
require_once __DIR__ . '/../includes/class-mad4b-scp-execution-receipt.php';
$operation_id = '22222222-2222-4222-8222-222222222222';
$claim = array(
    'ability'=>'mad4b/runtime-release-set-apply', 'provider'=>'core',
    'request_id'=>'transport-request-release-0001',
    'target_fingerprint'=>hash( 'sha256', 'native-authorization-target-with-resource-set' ),
    'resource_set_sha256'=>hash( 'sha256', 'native-resource-set' ),
    'context_receipt_sha256'=>hash( 'sha256', 'native-preparation' ),
    'capability_descriptor_sha256'=>hash( 'sha256', 'native-descriptor' ),
    'policy_decision_sha256'=>hash( 'sha256', 'native-policy-decision' ),
    'approval_impact_binding_sha256'=>hash( 'sha256', 'native-impact' ),
);
$terminal = array( 'receipt_id'=>'native-terminal:fixture-0001',
    'receipt_sha256'=>hash( 'sha256', 'native-terminal' ),
    'terminal_material_sha256'=>hash( 'sha256', 'native-terminal-material' ) );
$native = MAD4B_SCP_Execution_Receipt::build( $claim, array( 'readback'=>array( 'ready'=>true ) ), $terminal );
g9_assert( ! is_wp_error( $native ) && $native['request_id'] !== $operation_id
    && $native['target_fingerprint'] !== $reserved['operation_sha256'],
    'native namespaces are independent, not manufactured G9 equality' );
$wrong_ability = $native; $wrong_ability['ability'] = 'mad4b/content-update-post';
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $wrong_ability ),
    'native_receipt_unbound' );
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $native ),
    'native_execution_uncertain' );
MAD4B_SCP_Operation_Journal::$state = 'COMMITTED';
// Current native release claims do not yet carry operation_id. A valid signed
// receipt and an unrelated completed journal are insufficient for verification.
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $native ),
    'native_link_unavailable' );
$linked_claim = $claim; $linked_claim['operation_id'] = $operation_id;
$native = MAD4B_SCP_Execution_Receipt::build( $linked_claim, array( 'readback'=>array( 'ready'=>true ) ), $terminal );
g9_assert( ! is_wp_error( $native ), 'real signed operation stage built' );
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $native ),
    'native_link_unavailable' ); // Signed UUID alone does not link the G9 fence.
$incorrect = $native; $incorrect['target_fingerprint'] = hash( 'sha256', 'foreign' );
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $incorrect ),
    'native_signature_invalid' );
$unsigned = $native; unset( $unsigned['signature'] );
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $unsigned ),
    'native_signature_invalid' );
MAD4B_SCP_Operation_Journal::$foreign = true;
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $native ),
    'native_execution_uncertain' );
MAD4B_SCP_Operation_Journal::$foreign = false;
MAD4B_SCP_Operation_Journal::$journal_unbound = true;
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $native ),
    'native_execution_uncertain' );
MAD4B_SCP_Operation_Journal::$journal_unbound = false;

// Synthetic FUTURE producer record: this tests the passive verifier only.
// No production executor currently writes this G9 link, creates its grant or
// registers its Capability Descriptor.
$inspection = MAD4B_SCP_G9_Release_Fence::inspect( $binding, $reserved['operation_sha256'] );
$link = array(
    'link_contract'=>'mad4b.g9.native-release-link.v1',
    'g9_operation_sha256'=>$reserved['operation_sha256'],
    'g9_plan_sha256'=>$inspection['plan_sha256'],
    'site_binding_sha256'=>$inspection['binding_sha256'],
    'native_request_id'=>$native['request_id'],
    'native_target_fingerprint'=>$native['target_fingerprint'],
    'resource_set_sha256'=>$native['resource_set_sha256'],
    'terminal_receipt_sha256'=>$native['terminal_receipt_sha256'],
);
$events = array();
for ( $i = 1; $i <= 5; $i++ ) $events[] = array(
    'sequence'=>$i,
    'event_sha256'=>$i===5 ? hash( 'sha256', 'append-only-head' ) : hash( 'sha256', 'fixture-event-' . $i ),
    'lifecycle_state'=>$i===5 ? 'completed' : 'running',
    'terminal_outcome'=>$i===5 ? 'committed' : '',
    'safe_metadata'=>$i===5 ? $link : array(),
);
$trace = array( 'contract'=>'mad4b.dynamic-operation-trace.v1',
    'operation_id'=>$operation_id, 'chain_valid'=>true, 'complete'=>true,
    'count'=>5, 'events'=>$events );
MAD4B_SCP_Operation_Journal::$trace = $trace;
$verified = MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $native );
g9_assert( ! is_wp_error( $verified ) && $verified['native_execution_evidence_verified']
    && !$verified['site_local_release_accepted']
    && $verified['native_request_id'] !== $verified['native_operation_id']
    && $verified['native_target_fingerprint'] !== $verified['operation_sha256'],
    'explicit native journal linkage verifies independent identities only' );

foreach ( array( 'g9_operation_sha256', 'g9_plan_sha256', 'site_binding_sha256',
    'native_request_id', 'native_target_fingerprint', 'resource_set_sha256',
    'terminal_receipt_sha256' ) as $field ) {
    MAD4B_SCP_Operation_Journal::$trace = $trace;
    MAD4B_SCP_Operation_Journal::$trace['events'][4]['safe_metadata'][ $field ] = hash( 'sha256', 'foreign-' . $field );
    g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
        $binding, $reserved['operation_sha256'], $operation_id, $native ),
        'native_link_unavailable' );
}
MAD4B_SCP_Operation_Journal::$trace = $trace;
MAD4B_SCP_Operation_Journal::$trace['events'][4]['safe_metadata']['execution_receipt_sha256'] =
    $native['receipt_sha256'];
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $native ),
    'native_link_unavailable' ); // Self-referential terminal producer is forbidden.
foreach ( array( 'chain_valid', 'complete' ) as $field ) {
    MAD4B_SCP_Operation_Journal::$trace = $trace;
    MAD4B_SCP_Operation_Journal::$trace[ $field ] = false;
    g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
        $binding, $reserved['operation_sha256'], $operation_id, $native ),
        'native_link_unavailable' );
}
MAD4B_SCP_Operation_Journal::$trace = $trace;
MAD4B_SCP_Operation_Journal::$trace['events'][4]['event_sha256'] = hash( 'sha256', 'stale-head' );
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $native ),
    'native_link_unavailable' );
MAD4B_SCP_Operation_Journal::$trace = $trace;
$substituted_claim = $linked_claim; $substituted_claim['request_id'] = $operation_id;
$substituted_claim['target_fingerprint'] = $reserved['operation_sha256'];
$substituted = MAD4B_SCP_Execution_Receipt::build( $substituted_claim, array(), $terminal );
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $substituted ),
    'native_link_unavailable' ); // Legacy equality cannot bypass native metadata.
$foreign_operation_claim = $linked_claim;
$foreign_operation_claim['operation_id'] = '33333333-3333-4333-8333-333333333333';
$substituted = MAD4B_SCP_Execution_Receipt::build( $foreign_operation_claim, array(), $terminal );
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $substituted ),
    'native_link_unavailable' );
MAD4B_SCP_Operation_Journal::$drift_on_read = MAD4B_SCP_Operation_Journal::$read_count + 2;
g9_denied( MAD4B_SCP_G9_Release_Fence::native_execution_evidence(
    $binding, $reserved['operation_sha256'], $operation_id, $native ),
    'native_evidence_changed' );
MAD4B_SCP_Operation_Journal::$drift_on_read = 0;
// A separately registered test reader must not be silently displaced by
// WordPress boot. The production read-surface declines to publish abilities
// when the server-owned reader cannot be pinned.
g9_denied( MAD4B_SCP_G9_Read_Surface::boot(), 'reader_already_registered' );
g9_assert( !$GLOBALS['g9_registered_abilities'], 'failed boot never publishes read abilities' );
// A direct manual registration call after failed reader pinning must still
// NOT expose any Abilities. A separate positive bootstrap fixture certifies
// the three private read schemas on a clean request.
MAD4B_SCP_G9_Read_Surface::register_abilities();
g9_assert( !$GLOBALS['g9_registered_abilities'],
    'untrusted observer cannot expose Abilities through direct registration' );
$read = MAD4B_SCP_G9_Read_Surface::site_observation();
g9_assert( ! is_wp_error( $read ) && $read['provider_evidence_verified']
    && $read['host_isolation_verified'] && $read['health_window_verified'],
    'complete certified fixture is reported separately from evidence presence' );
G9_Exact_Reader::$host_certified = false;
$unverified_host = MAD4B_SCP_G9_Read_Surface::site_observation();
g9_assert( ! is_wp_error( $unverified_host )
    && $unverified_host['host_evidence_present'] && !$unverified_host['host_isolation_verified'],
    'host diagnostics are not host-isolation certification' );
G9_Exact_Reader::$host_certified = true;
$marker_backup = $GLOBALS['g9_test_options'][ MAD4B_SCP_Resilience_Anchor::MIRROR_OPTION ];
$marker_foreign = $marker_backup; $marker_foreign['site']['canonical_origin'] = 'https://foreign.example.invalid';
$GLOBALS['g9_test_options'][ MAD4B_SCP_Resilience_Anchor::MIRROR_OPTION ] = $marker_foreign;
g9_denied( MAD4B_SCP_Resilience_Anchor::read( $binding ), 'mirror_identity_mismatch' );
$GLOBALS['g9_test_options'][ MAD4B_SCP_Resilience_Anchor::MIRROR_OPTION ] = $marker_backup;
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
$closure = MAD4B_SCP_G9_Read_Surface::closure_status();
g9_assert( ! is_wp_error( $closure ) && !$closure['operationally_closed']
    && !$closure['ready_for_production'] && $closure['blocker_count'] >= 3
    && in_array( 'g7_signed_host_and_release_acceptance_integration_missing',
        $closure['blockers'], true )
    && in_array( 'native_executor_g9_reservation_binding_unimplemented',
        $closure['blockers'], true ), 'closure truth remains fail-closed' );
g9_denied( MAD4B_SCP_G9_Read_Surface::closure_status( array( 'site_id'=>2 ) ), 'read_input_invalid' );
$state = MAD4B_SCP_G9_Restore_Convergence::status();
g9_assert( ! is_wp_error( $state ) && !$state['write_reenabled'], 'restore status never grants' );
$path = MAD4B_SCP_RESILIENCE_ANCHOR_DIRECTORY . '/resilience-' . $binding['site_uuid'] . '-1-staging.json';
@unlink( $path ); @unlink( $path . '.lock' ); @rmdir( MAD4B_SCP_RESILIENCE_ANCHOR_DIRECTORY );
echo "G9 release/DR site-local runtime: PASS (admission, drift, replay, isolation, bounded receipts)\n";
