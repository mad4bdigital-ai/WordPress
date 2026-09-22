<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );

$GLOBALS['mad4b_behavior_mode'] = 'none';

function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function wp_register_ability( $name, $args ) { return true; }
function wp_has_ability( $name ) { return false; }
function jet_engine() { return true; }
class Jet_Engine {}
require_once __DIR__ . '/fixtures/provider-compatibility-symbols.php';
class WP_Error { public $code; public $message; public $data; public function __construct( $code, $message, $data = array() ) { $this->code=$code; $this->message=$message; $this->data=$data; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

function apply_filters( $hook, $value ) {
    $args = func_get_args();
    if ( 'mad4b_provider_behavioral_evidence_verifiers' === $hook ) {
        if ( 'none' === $GLOBALS['mad4b_behavior_mode'] ) return array();
        $callback = 'external_verifier' === $GLOBALS['mad4b_behavior_mode']
            ? 'strlen'
            : static function ( $receipt, $context, $canonical ) {
                return array( 'verified' => 'signed-fixture' === (string) $receipt['signature'], 'evidence_digest' => (string) $receipt['evidence_digest'] );
            };
        return array(
            'fixture-verifier' => array(
                'verifier_id' => 'fixture-verifier',
                'issuer_id' => 'fixture-issuer',
                'scopes' => array( 'behavioral', 'rollback' ),
                'signature_scheme' => 'fixture-sha256-v1',
                'trusted' => true,
                'read_only_verifier' => true,
                'authorizing' => false,
                'verify_callback' => $callback,
            ),
        );
    }
    if ( 'mad4b_provider_behavioral_evidence_receipts' === $hook ) {
        if ( 'none' === $GLOBALS['mad4b_behavior_mode'] ) return array();
        $context = isset( $args[2] ) && is_array( $args[2] ) ? $args[2] : array();
        $artifact = isset( $context['artifact_fingerprint'] ) ? (string) $context['artifact_fingerprint'] : '';
        if ( 'wrong_artifact' === $GLOBALS['mad4b_behavior_mode'] ) $artifact = str_repeat( 'f', 64 );
        $issued = time() - 60;
        $expires = time() + 3600;
        $canonical = array(
            'contract' => 'mad4b.provider-behavioral-evidence-receipt.v1',
            'provider_id' => (string) $context['provider_id'],
            'capability_id' => (string) $context['capability_id'],
            'artifact_fingerprint' => $artifact,
            'capability_contract_digest' => (string) $context['capability_contract_digest'],
            'verifier_id' => 'fixture-verifier',
            'issuer_id' => 'fixture-issuer',
            'issued_at' => $issued,
            'expires_at' => $expires,
            'observations' => array( 'behavioral_passed' => true, 'rollback_passed' => true ),
        );
        return array( array_merge( $canonical, array(
            'signature' => 'signed-fixture',
            'evidence_digest' => hash( 'sha256', json_encode( $canonical ) ),
        ) ) );
    }
    return $value;
}

final class MAD4B_SCP_Provider_Contracts {
    public static $exact = false;
    public static function get( $provider ) {
        return array(
            'plugin_file' => 'bit_pi' === $provider ? 'bit-pi/bit-pi.php' : 'jet-engine/jet-engine.php',
            'archive_sha256' => str_repeat( 'a', 64 ),
        );
    }
    public static function runtime_status( $provider, $available = null ) {
        return array(
            'provider' => $provider,
            'status' => self::$exact ? 'certified' : 'version_drift',
            'runtime_contract_ok' => self::$exact,
            'installed_version' => 'bit_pi' === $provider ? '1.9.0' : '3.8.15',
            'certified_versions' => array( 'bit_pi' === $provider ? '1.9.0' : '3.8.11.2' ),
            'certification_authority' => 'test-fixture',
            'runtime_integrity' => array(
                'required' => true,
                'manifest_present' => true,
                'verified' => array( 'plugin.php' ),
                'missing' => array(),
                'mismatched' => self::$exact ? array() : array( 'plugin.php' => array( 'reason' => 'hash_mismatch' ) ),
            ),
        );
    }
}

final class FakeJetEngineAdapter {
    public function id(){ return 'jetengine'; }
    public function provider_key(){ return 'jetengine'; }
    public function is_available(){ return true; }
    public function ability_names(){ return array( 'read'=>array( 'jetengine/get-post-meta','jetengine/list-post-meta','jetengine/get-cpt-definition' ), 'content'=>array( 'jetengine/update-post-meta' ), 'admin'=>array() ); }
    public function reversible_contracts(){ return array( 'jetengine/update-post-meta'=>'mad4b.rollback.jetengine-post-meta.v1' ); }
}
final class FakeBitFlowsAdapter {
    public function id(){ return 'bitflows'; }
    public function provider_key(){ return 'bit_pi'; }
    public function is_available(){ return true; }
    public function ability_names(){ return array( 'read'=>array( 'bitflows/list-flows','bitflows/get-flow','bitflows/get-executions' ), 'content'=>array(), 'admin'=>array( 'bitflows/run-flow' ) ); }
    public function reversible_contracts(){ return array(); }
}
final class MAD4B_SCP_Adapter_Registry {
    private static $instance;
    private $jetengine;
    private $bitflows;
    public static function instance(){ if ( ! self::$instance ) self::$instance = new self(); return self::$instance; }
    public function __construct(){ $this->jetengine = new FakeJetEngineAdapter(); $this->bitflows = new FakeBitFlowsAdapter(); }
    public function register_defaults(){}
    public function get( $id ){ if ( 'jetengine' === $id ) return $this->jetengine; if ( 'bitflows' === $id ) return $this->bitflows; return null; }
    public function register( $adapter ){ return true; }
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-compatibility-certification.php';

function expect_true( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
function expect_same( $expected, $actual, $message ) { if ( $expected !== $actual ) { fwrite( STDERR, "FAIL: $message expected=" . var_export( $expected, true ) . " actual=" . var_export( $actual, true ) . "\n" ); exit( 1 ); } }

$jetengine = new FakeJetEngineAdapter();
MAD4B_SCP_Provider_Contracts::$exact = false;
$GLOBALS['mad4b_behavior_mode'] = 'none';
MAD4B_SCP_Provider_Compatibility_Certification::clear_request_cache();
$missing = MAD4B_SCP_Provider_Compatibility_Certification::assess_provider( 'jetengine', $jetengine );
expect_same( 'compatible_unattested', $missing['compatibility_state'], 'artifact drift remains provider-level compatible_unattested truth' );
expect_same( 'DISCOVERED', $missing['capabilities']['post_meta.bounded-write']['certification_level'], 'drifted write stays discovered without a trusted receipt' );
expect_same( false, $missing['capabilities']['post_meta.bounded-write']['write_eligible'], 'missing behavioral evidence cannot open write' );
expect_same( 'missing', $missing['capabilities']['post_meta.bounded-write']['behavioral_evidence']['state'], 'missing trusted receipt is explicit' );

$GLOBALS['mad4b_behavior_mode'] = 'valid';
MAD4B_SCP_Provider_Compatibility_Certification::clear_request_cache();
$recertified = MAD4B_SCP_Provider_Compatibility_Certification::assess_provider( 'jetengine', $jetengine );
$write = $recertified['capabilities']['post_meta.bounded-write'];
expect_same( 'compatible_unattested', $recertified['compatibility_state'], 'behavioral evidence must not forge exact artifact certification' );
expect_same( 'REVERSIBLE_WRITE_CERTIFIED', $write['certification_level'], 'behavioral plus rollback receipt can recertify one reversible capability' );
expect_same( 'behavioral_receipt', $write['certification_source'], 'recertified capability records its evidence authority' );
expect_same( 'active', $write['activation_stage'], 'bounded reversible capability can return active after trusted recertification' );
expect_same( true, $write['write_eligible'], 'trusted current-artifact behavioral+rollback evidence can restore bounded write eligibility' );
expect_same( true, $write['behavioral_evidence']['behavioral_verified'], 'behavioral observation is verified' );
expect_same( true, $write['behavioral_evidence']['rollback_verified'], 'rollback observation is verified' );
expect_same( 'mad4b_control_plane_source', $write['behavioral_evidence']['accepted_receipt']['verifier_provenance'], 'accepted verifier provenance is pinned to MAD4B source' );
expect_same( false, $write['behavioral_evidence']['authorizing'], 'behavioral verifier is evidence, not authority' );
expect_same( false, $write['behavioral_evidence']['mutation_granted'], 'behavioral verifier never grants mutation' );
expect_same( true, MAD4B_SCP_Provider_Compatibility_Certification::mutation_guard( 'jetengine', 'jetengine/update-post-meta', true, $jetengine ), 'per-capability guard accepts a trusted recertified bounded write' );
$plan = MAD4B_SCP_Provider_Compatibility_Certification::recertification_plan( array( 'provider_id'=>'jetengine' ) );
expect_same( 'BEHAVIORALLY_RECERTIFIED', $plan['classification'], 'plan distinguishes behavioral recertification from exact artifact certification' );

$GLOBALS['mad4b_behavior_mode'] = 'external_verifier';
MAD4B_SCP_Provider_Compatibility_Certification::clear_request_cache();
$untrusted = MAD4B_SCP_Provider_Compatibility_Certification::assess_provider( 'jetengine', $jetengine );
$untrusted_write = $untrusted['capabilities']['post_meta.bounded-write'];
expect_same( 'DISCOVERED', $untrusted_write['certification_level'], 'external verifier implementation cannot recertify a capability' );
expect_same( false, $untrusted_write['write_eligible'], 'external verifier implementation cannot open write' );
expect_true( in_array( 'trusted_verifier_unavailable', $untrusted_write['behavioral_evidence']['rejection_reasons'], true ), 'external verifier is absent from trusted registry after provenance check' );

$GLOBALS['mad4b_behavior_mode'] = 'wrong_artifact';
MAD4B_SCP_Provider_Compatibility_Certification::clear_request_cache();
$stale = MAD4B_SCP_Provider_Compatibility_Certification::assess_provider( 'jetengine', $jetengine );
$stale_write = $stale['capabilities']['post_meta.bounded-write'];
expect_same( 'DISCOVERED', $stale_write['certification_level'], 'wrong-artifact receipt cannot recertify capability' );
expect_same( false, $stale_write['write_eligible'], 'wrong-artifact receipt cannot open write' );
expect_true( in_array( 'artifact_binding_mismatch', $stale_write['behavioral_evidence']['rejection_reasons'], true ), 'artifact mismatch is explicit evidence rejection' );
expect_true( is_wp_error( MAD4B_SCP_Provider_Compatibility_Certification::mutation_guard( 'jetengine', 'jetengine/update-post-meta', true, $jetengine ) ), 'stale receipt leaves execution fail closed' );

$bitflows = new FakeBitFlowsAdapter();
MAD4B_SCP_Provider_Contracts::$exact = true;
$GLOBALS['mad4b_behavior_mode'] = 'valid';
MAD4B_SCP_Provider_Compatibility_Certification::clear_request_cache();
$high = MAD4B_SCP_Provider_Compatibility_Certification::assess_provider( 'bit_pi', $bitflows );
$execute = $high['capabilities']['flow.execute'];
expect_same( 'DISCOVERED', $execute['certification_level'], 'high-risk execution never becomes fully certified from artifact or behavioral receipt alone' );
expect_same( 'canary', $execute['activation_stage'], 'trusted behavioral evidence may only advance high-risk execution to canary' );
expect_same( true, $execute['canary_eligible'], 'trusted behavioral evidence marks high-risk capability canary eligible' );
expect_same( true, $execute['owner_promotion_required'], 'high-risk canary still requires separately governed owner promotion' );
expect_same( false, $execute['write_eligible'], 'canary high-risk capability is not normal write-mount eligible' );
$high_projection = MAD4B_SCP_Provider_Compatibility_Certification::adapter_mount_projection( 'bit_pi', $bitflows );
expect_true( in_array( 'bitflows/run-flow', array_column( $high_projection['blocked'], 'ability' ), true ), 'high-risk canary stays blocked from normal MCP write surface' );
$high_guard = MAD4B_SCP_Provider_Compatibility_Certification::mutation_guard( 'bit_pi', 'bitflows/run-flow', true, $bitflows );
expect_true( is_wp_error( $high_guard ), 'high-risk canary cannot bypass execution guard' );
expect_true( in_array( 'high_risk_activation_required', $high_guard->data['violations'], true ), 'high-risk canary retains explicit activation blocker' );
$high_plan = MAD4B_SCP_Provider_Compatibility_Certification::recertification_plan( array( 'provider_id'=>'bit_pi' ) );
expect_same( 'OWNER_REVIEW_REQUIRED', $high_plan['classification'], 'high-risk canary remains owner-governed' );
expect_same( 'owner_governed_canary_execution_required', $high_plan['steps'][0]['action'], 'plan never turns behavioral receipt into owner authorization' );

$status = MAD4B_SCP_Provider_Compatibility_Certification::behavioral_evidence_status( array( 'provider_id'=>'bit_pi', 'capability_id'=>'flow.execute' ) );
expect_same( false, $status['authorizing'], 'behavioral evidence status is non-authorizing' );
expect_same( false, $status['activation_granted'], 'behavioral evidence status cannot grant activation' );
expect_same( false, $status['mutation_granted'], 'behavioral evidence status cannot grant mutation' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-compatibility-certification.php' );
expect_true( false === strpos( $source, 'owner_approved' ), 'caller-supplied owner approval boolean must not exist' );
expect_true( false === strpos( $source, "'activation_granted' => true" ), 'compatibility engine must not self-grant activation' );

echo "MAD4B provider behavioral recertification contract passed.\n";
