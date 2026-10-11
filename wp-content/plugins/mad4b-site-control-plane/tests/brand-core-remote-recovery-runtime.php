<?php
/* Isolated deterministic orchestration tests. Never access production, DB or Drive. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['mad4b_abilities'] = array();
$GLOBALS['mad4b_admin'] = true;
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action( $hook, $callback, $priority = 10 ) {}
function current_user_can( $cap ) { return $GLOBALS['mad4b_admin']; }
function wp_has_ability( $name ) { return ! empty( $GLOBALS['mad4b_abilities'][ $name ] ); }
function wp_register_ability( $name, $definition ) { $GLOBALS['mad4b_abilities'][ $name ] = $definition; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
class MAD4B_SCP_Site_Profile {
    public static $environment = 'staging';
    public static function status() {
        return array( 'environment' => self::$environment, 'origin_match' => true,
            'environment_match' => true );
    }
}
class MAD4B_SCP_Context_Authority {
    public static $source_count = 1;
    public static $asset_count = 12;
    public static $ready = false;
    public static $proof_version = '1';
    public static function legacy_reconciliation_census() {
        return array( 'source_quarantine_count' => self::$source_count,
            'asset_quarantine_count' => self::$asset_count );
    }
    public static function brand_core_coverage() {
        $e = array( 'ready' => self::$ready );
        return array( 'ready' => self::$ready,
            'conflicting_required_context_sets' => array(),
            'coverage' => array( 'brand_strategy' => $e, 'tone_of_voice' => $e,
                'editorial_guidelines' => $e ) );
    }
    public static function legacy_owner_transfer_evidence( $input ) {
        return array( 'folder_membership_verified' => true,
            'migration_authorized' => false,
            'source_id' => $input['source_id'],
            'provider_proof_sha256' => hash( 'sha256', self::$proof_version ),
            'matched_file_count' => 12 );
    }
}
class MAD4B_SCP_Self_Update {
    public static function current_identity() {
        return array( 'source_commit_sha' => str_repeat( 'a', 40 ) );
    }
}
class MAD4B_SCP_Host_Identity_Live {
    public static $verified = false;
    public static function observe( $site ) {
        return array( 'verified' => self::$verified );
    }
}
class MAD4B_SCP_Selected_Head_Update {
    public static $eligible = false;
    public static function plan( $input ) {
        $sha = $input['candidate_source']['reference'];
        return array( 'eligible' => self::$eligible, 'blockers' => self::$eligible ? array() : array( 'unsigned' ),
            'source' => array( 'resolved_sha' => $sha ), 'plan_sha256' => hash( 'sha256', $sha ) );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-brand-core-remote-recovery.php';
function brand_remote_assert( $ok, $why ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $why . PHP_EOL ); exit( 1 ); }
}
$stages = MAD4B_SCP_Brand_Core_Remote_Recovery::stages();
brand_remote_assert( 8 === count( $stages ), 'eight original recovery stages' );
MAD4B_SCP_Brand_Core_Remote_Recovery::register_abilities();
foreach ( array( 'mad4b/brand-core-remote-recovery-status',
    'mad4b/brand-core-remote-recovery-prepare' ) as $name ) {
    $ability = $GLOBALS['mad4b_abilities'][ $name ];
    brand_remote_assert( $ability['category'] === 'mad4b-read' &&
        $ability['meta']['annotations']['readonly'] === true &&
        $ability['meta']['mcp']['public'] === false, 'read-only governed ability' );
    brand_remote_assert( $ability['input_schema']['additionalProperties'] === false,
        'no arbitrary runtime fields' );
}
foreach ( $stages as $stage ) {
    foreach ( array( 'read', 'next', 'readback' ) as $slot ) {
        if ( $stage[ $slot ] !== '' ) $GLOBALS['mad4b_abilities'][ $stage[ $slot ] ] = array( 'read_only_stub' => true );
    }
}
$normal = MAD4B_SCP_Brand_Core_Remote_Recovery::status();
brand_remote_assert( ! is_wp_error( $normal ) && $normal['state'] === 'REMOTE_RECOVERY_INCOMPLETE',
    'normal plan must be incomplete' );
brand_remote_assert( $normal['stages']['source_inventory']['evidence_observed'] === true,
    'fresh admin census observed' );
brand_remote_assert( $normal['stages']['owner_attestation']['evidence_observed'] === false,
    'census cannot attest ownership' );
brand_remote_assert( $normal['stages']['durable_db_recovery']['state'] === 'REMOTE_EXECUTOR_INTEGRATION_REQUIRED',
    'no implied database certification' );
brand_remote_assert( $normal['stages']['owner_attestation']['waiting_on_upstream'] ===
    array( 'original_file_identity', 'host_identity', 'durable_db_recovery' ),
    'owner transfer waits on genuine evidence' );
brand_remote_assert( $normal['full_operational_acceptance'] === false &&
    $normal['production_mutation_authorized'] === false && $normal['approval_issued'] === false,
    'new orchestrator cannot approve, publish or promote' );
$source = str_repeat( 'b', 64 );
$scan = MAD4B_SCP_Brand_Core_Remote_Recovery::status( array(
    'source_id' => $source, 'include_provider_scan' => true,
    'include_host_challenge' => true ) );
brand_remote_assert( $scan['stages']['original_file_identity']['evidence_observed'] === true &&
    $scan['stages']['host_identity']['evidence_observed'] === false,
    'provider proof remains separate from Host attestation' );
MAD4B_SCP_Host_Identity_Live::$verified = true;
$host = MAD4B_SCP_Brand_Core_Remote_Recovery::status( array(
    'source_id' => $source, 'include_provider_scan' => true,
    'include_host_challenge' => true ) );
brand_remote_assert( $host['stages']['host_identity']['evidence_observed'] === true &&
    !$host['stages']['durable_db_recovery']['evidence_observed'],
    'Host signature is not a database crash receipt' );
$prepare = MAD4B_SCP_Brand_Core_Remote_Recovery::prepare( array(
    'stage_id' => 'original_file_identity',
    'source_id' => $source, 'include_provider_scan' => true,
    'include_host_challenge' => true,
    'expected_plan_sha256' => $host['plan_sha256'] ) );
brand_remote_assert( ! is_wp_error( $prepare ) && $prepare['ready_for_dispatch'] === false &&
    $prepare['mutation_performed'] === false, 'fresh same-plan handoff is not write authority' );
MAD4B_SCP_Context_Authority::$proof_version = '2';
$stale = MAD4B_SCP_Brand_Core_Remote_Recovery::prepare( array(
    'stage_id' => 'original_file_identity',
    'source_id' => $source, 'include_provider_scan' => true,
    'include_host_challenge' => true,
    'expected_plan_sha256' => $host['plan_sha256'] ) );
brand_remote_assert( is_wp_error( $stale ) &&
    $stale->get_error_code() === 'mad4b_brand_remote_snapshot_changed_replan_required',
    'changed provider proof must reject old plan' );
$unsupported = MAD4B_SCP_Brand_Core_Remote_Recovery::status( array( 'raw_sql' => true ) );
brand_remote_assert( is_wp_error( $unsupported ), 'arbitrary remote SQL parameter rejected' );
$unverified = MAD4B_SCP_Brand_Core_Remote_Recovery::prepare( array(
    'stage_id' => 'arbitrary_shell', 'expected_plan_sha256' => $normal['plan_sha256'] ) );
brand_remote_assert( is_wp_error( $unverified ), 'only declared stages can be prepared' );
MAD4B_SCP_Site_Profile::$environment = 'production';
$prod = MAD4B_SCP_Brand_Core_Remote_Recovery::status();
brand_remote_assert( is_wp_error( $prod ), 'production denied' );
MAD4B_SCP_Site_Profile::$environment = 'staging';
$GLOBALS['mad4b_admin'] = false;
brand_remote_assert( is_wp_error( MAD4B_SCP_Brand_Core_Remote_Recovery::status() ),
    'nonadministrator denied' );
$GLOBALS['mad4b_admin'] = true;
MAD4B_SCP_Context_Authority::$source_count = 0;
MAD4B_SCP_Context_Authority::$asset_count = 0;
MAD4B_SCP_Context_Authority::$ready = true;
MAD4B_SCP_Selected_Head_Update::$eligible = true;
$possible = MAD4B_SCP_Brand_Core_Remote_Recovery::status( array(
    'candidate_sha' => str_repeat( 'c', 40 ) ) );
brand_remote_assert( $possible['stages']['brand_approval']['evidence_observed'] === true
    && $possible['stages']['certified_package']['evidence_observed'] === false
    && $possible['stages']['draft_acceptance']['evidence_observed'] === false
    && $possible['full_operational_acceptance'] === false,
    'brand approval and signed manifest preflight cannot silently certify installation/draft' );
echo "mad4b.site-control-plane.brand-core-remote-recovery.runtime.v1: PASS\n";
