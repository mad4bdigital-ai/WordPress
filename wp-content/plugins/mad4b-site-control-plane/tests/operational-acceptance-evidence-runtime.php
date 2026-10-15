<?php
/* Fake current WordPress reads for governed evidence aggregation; no writes. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$GLOBALS['abilities'] = array();
$GLOBALS['is_admin'] = true;
function current_user_can( $cap ) { return $GLOBALS['is_admin']; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function add_action( $hook, $callback, $priority = 10 ) {}
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
function wp_register_ability( $name, $spec ) { $GLOBALS['abilities'][ $name ] = $spec; }
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
class MAD4B_SCP_Site_Profile {
    public static $production = false;
    public static $revision = 7;
    public static function status() {
        $env = self::$production ? 'production' : 'staging';
        return array( 'configured_environment' => $env,
            'wordpress_environment' => $env,
            'origin_match' => true, 'environment_match' => true,
            'authority_ready' => true, 'revision' => self::$revision,
            'site_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'profile_digest' => str_repeat( 'a', 64 ),
            'canonical_origin' => 'https://staging.allroyalegypt.com' );
    }
}
class MAD4B_SCP_Context_Authority {
    public static $quarantine = 1;
    public static $ready = false;
    public static $drift = false;
    public static $census_calls = 0;
    public static $provider_sha = 'v1';
    public static $scan_calls = 0;
    public static function legacy_reconciliation_census() {
        ++self::$census_calls;
        $count = self::$quarantine;
        if ( self::$drift && 0 === self::$census_calls % 2 ) ++$count;
        return array( 'scope_fingerprint' => str_repeat( 'b', 64 ),
            'source_quarantine_count' => $count, 'asset_quarantine_count' => 12 * ( $count > 0 ) );
    }
    public static function brand_core_coverage() {
        $category = array( 'ready' => self::$ready,
            'eligible_asset_count' => self::$ready ? 1 : 0 );
        return array( 'ready' => self::$ready,
            'missing_required_context_sets' => self::$ready ? array() : array( 'brand_strategy' ),
            'conflicting_required_context_sets' => array(),
            'coverage' => array(
                'brand_strategy' => $category, 'tone_of_voice' => $category,
                'editorial_guidelines' => $category ) );
    }
    public static function legacy_owner_transfer_plan( $input ) {
        return array( 'plan_sha256' => hash( 'sha256', 'plan' ) );
    }
    public static function legacy_owner_transfer_evidence( $input ) {
        ++self::$scan_calls;
        return array( 'folder_membership_verified' => true,
            'migration_authorized' => false, 'source_id' => $input['source_id'],
            'plan_sha256' => hash( 'sha256', 'plan' ),
            'provider_proof_sha256' => hash( 'sha256', self::$provider_sha ),
            'matched_file_count' => 12, 'legal_owner_or_rights_verified' => false );
    }
    public static function legacy_owner_transfer_readback( $input ) {
        return array( 'ready_for_fresh_source_scan' => true,
            'existing_source_verified' => true,
            'asset_count' => 12, 'unreviewed_asset_count' => 12,
            'prior_review_evidence_still_present_count' => 0,
            'supplier_rights_authorized' => false );
    }
}
class MAD4B_SCP_Host_Identity_Live {
    public static $verified = false;
    public static function observe( $site ) {
        return array( 'verified' => self::$verified );
    }
}
class MAD4B_SCP_Self_Update {
    public static function current_identity() {
        return array( 'source_commit_sha' => str_repeat( 'c', 40 ) );
    }
}
class MAD4B_SCP_Native_Recovery {
    public static function status() {
        return array( 'state' => 'SCRATCH_REQUIRES_INDEPENDENT_READBACK',
            'active_job' => true, 'mariadb_crash_certified' => false );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-operational-acceptance-evidence.php';
function evidence_assert( $condition, $reason ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $reason . PHP_EOL ); exit( 1 ); }
}
MAD4B_SCP_Operational_Acceptance_Evidence::register_ability();
$ability = $GLOBALS['abilities'][MAD4B_SCP_Operational_Acceptance_Evidence::ABILITY] ?? array();
evidence_assert( !empty( $ability ) && $ability['category'] === 'mad4b-read'
    && $ability['meta']['annotations']['readonly'] === true
    && $ability['meta']['mcp']['public'] === false
    && $ability['input_schema']['additionalProperties'] === false,
    'bounded nonpublic read-only ability' );
foreach ( MAD4B_SCP_Operational_Acceptance_Evidence::paths() as $path )
    foreach ( array( 'read', 'preflight', 'execute', 'readback' ) as $key )
        if ( $path[ $key ] !== '' ) $GLOBALS['abilities'][ $path[ $key ] ] = array( 'stub' => true );
$basic = MAD4B_SCP_Operational_Acceptance_Evidence::collect();
evidence_assert( is_array( $basic ) && $basic['stage_count'] === 8
    && $basic['state'] === 'OPERATIONAL_ACCEPTANCE_INCOMPLETE'
    && !$basic['full_operational_acceptance'] && !$basic['mutation_performed']
    && !$basic['owner_transfer_authorized'],
    'eight stages; no inferred completion or mutation' );
evidence_assert( $basic['stages']['source_inventory']['evidence_observed'],
    'source census can be observed even if quarantined' );
evidence_assert( !$basic['stages']['original_file_identity']['evidence_observed']
    && !$basic['stages']['owner_attestation']['evidence_observed']
    && !$basic['stages']['durable_db_recovery']['evidence_observed'],
    'provider scan, owner and real MariaDB remain separate' );
evidence_assert( MAD4B_SCP_Context_Authority::$scan_calls === 0,
    'expensive Drive scan defaults to opt-out' );
$source = str_repeat( 'd', 64 );
$candidate = str_repeat( 'c', 40 );
MAD4B_SCP_Host_Identity_Live::$verified = true;
$scanned = MAD4B_SCP_Operational_Acceptance_Evidence::collect( array(
    'source_id' => $source, 'candidate_sha' => $candidate,
    'include_provider_scan' => true, 'include_host_challenge' => true ) );
evidence_assert( $scanned['stages']['original_file_identity']['evidence_observed']
    && $scanned['stages']['host_identity']['evidence_observed']
    && $scanned['supplemental_observations']['transfer_postcondition_verified']
    && $scanned['supplemental_observations']['installed_candidate_sha_matches']
    && $scanned['supplemental_observations']['native_scratch_active'],
    'exact provider, Host and installed SHA observations are separate and bounded' );
evidence_assert( !$scanned['stages']['owner_attestation']['evidence_observed']
    && !$scanned['stages']['certified_package']['evidence_observed']
    && !$scanned['stages']['durable_db_recovery']['evidence_observed']
    && !$scanned['stages']['draft_acceptance']['evidence_observed']
    && !$scanned['supplemental_observations']['legal_rights_verified'],
    'cannot turn any of those observations into a signed owner/DB/release/draft certificate' );
evidence_assert( $scanned['stages']['certified_package']['readiness']['execute']['mounted']
    && !$scanned['stages']['certified_package']['write_approval_issued'],
    'mounted exact updater is not write authority' );
$stable = MAD4B_SCP_Operational_Acceptance_Evidence::collect( array(
    'source_id' => $source, 'candidate_sha' => $candidate,
    'include_provider_scan' => true, 'include_host_challenge' => true,
    'expected_snapshot_sha256' => $scanned['snapshot_sha256'] ) );
evidence_assert( !is_wp_error( $stable ), 'matching independent snapshot can be revalidated' );
MAD4B_SCP_Context_Authority::$provider_sha = 'v2';
$stale = MAD4B_SCP_Operational_Acceptance_Evidence::collect( array(
    'source_id' => $source, 'candidate_sha' => $candidate,
    'include_provider_scan' => true, 'include_host_challenge' => true,
    'expected_snapshot_sha256' => $scanned['snapshot_sha256'] ) );
evidence_assert( is_wp_error( $stale )
    && $stale->get_error_code() === 'mad4b_operational_evidence_snapshot_changed_replan',
    'new Drive digest revokes stale exact acceptance snapshot' );
MAD4B_SCP_Context_Authority::$provider_sha = 'v1';
MAD4B_SCP_Context_Authority::$quarantine = 0;
MAD4B_SCP_Context_Authority::$ready = true;
$brand = MAD4B_SCP_Operational_Acceptance_Evidence::collect();
evidence_assert( $brand['stages']['brand_approval']['evidence_observed']
    && !$brand['full_operational_acceptance'],
    'fresh three-category approval with cleared quarantine still cannot certify full release' );
MAD4B_SCP_Context_Authority::$census_calls = 0;
MAD4B_SCP_Context_Authority::$drift = true;
$drift = MAD4B_SCP_Operational_Acceptance_Evidence::collect();
evidence_assert( $drift['state'] === 'SNAPSHOT_DRIFT_REPLAN'
    && !$drift['stages']['brand_approval']['evidence_observed'],
    'WordPress registry changes in independent second read must fail closed' );
MAD4B_SCP_Context_Authority::$drift = false;
foreach ( array(
    array( 'include_provider_scan' => true ),
    array( 'source_id' => 'not-an-id' ),
    array( 'database_crash_certified' => true ),
    array( 'include_host_challenge' => 'true' ),
    array( 'candidate_sha' => 'HEAD' )
) as $bad ) evidence_assert( is_wp_error(
    MAD4B_SCP_Operational_Acceptance_Evidence::collect( $bad ) ),
    'invalid scan, receipt, boolean and mutable HEAD strings are rejected' );
MAD4B_SCP_Site_Profile::$production = true;
evidence_assert( is_wp_error( MAD4B_SCP_Operational_Acceptance_Evidence::collect() ),
    'Production must be denied' );
MAD4B_SCP_Site_Profile::$production = false;
$GLOBALS['is_admin'] = false;
evidence_assert( is_wp_error( MAD4B_SCP_Operational_Acceptance_Evidence::collect() ),
    'non-admin must be denied' );
echo "mad4b.site-control-plane.operational-acceptance-evidence.runtime.v1: PASS\n";
