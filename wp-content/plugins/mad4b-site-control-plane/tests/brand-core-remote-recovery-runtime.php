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
    public static $bound = false;
    public static function status() {
        return array( 'environment' => self::$environment, 'origin_match' => true,
            'configured' => true, 'authority_ready' => true,
            'deployment_binding_configured' => self::$bound,
            'deployment_binding_bound' => self::$bound,
            'deployment_binding_match' => true,
            'environment_match' => true, 'site_uuid' => 'site-a',
            'revision' => 3, 'profile_digest' => str_repeat( 'a', 64 ) );
    }
}
class MAD4B_SCP_Context_Authority {
    public static $source_count = 1;
    public static $asset_count = 12;
    public static $ready = false;
    public static $proof_version = '1';
    public static $drift_second_census = false;
    public static $census_reads = 0;
    public static $scope_verified = true;
    public static function legacy_reconciliation_census() {
        ++self::$census_reads;
        $sources = self::$source_count;
        if ( self::$drift_second_census && 0 === self::$census_reads % 2 ) ++$sources;
        return array( 'source_quarantine_count' => $sources,
            'asset_quarantine_count' => self::$asset_count,
            'scope_fingerprint' => self::$scope_verified ? str_repeat( 'b', 64 ) : '',
            'state' => self::$scope_verified ? 'TRUSTED_SCOPE_VERIFIED' : 'UNVERIFIED_DEPLOYMENT_BINDING',
            'operational_scope_verified' => self::$scope_verified );
    }
    public static function profile() {
        return array( 'brand_id' => str_repeat( 'c', 32 ),
            'brand_name' => 'All Royal Egypt', 'revision' => 2 );
    }
    public static function sources() { return $GLOBALS['onboarding_sources'] ?? array(); }
    public static function brand_core_coverage() {
        $e = array( 'ready' => self::$ready );
        return array( 'ready' => self::$ready,
            'conflicting_required_context_sets' => array(),
            'coverage' => array( 'brand_strategy' => $e, 'tone_of_voice' => $e,
                'editorial_guidelines' => $e ) );
    }
    public static function legacy_owner_transfer_discover( $input = array() ) {
        return array( 'eligible_unbound_candidates' => self::$source_count && self::$scope_verified ? array(
            array( 'source_id' => str_repeat( 'b', 64 ), 'asset_count' => self::$asset_count )
        ) : array(), 'foreign_or_conflicted_records' =>
            self::$source_count && ! self::$scope_verified ? self::$source_count : 0 );
    }
    public static function review_queue() {
        return array( 'items' => empty( $GLOBALS['review_item'] ) ? array() :
            array( $GLOBALS['review_item'] ) );
    }
    public static function remote_owner_review_plan( $input ) {
        if ( empty( $GLOBALS['review_item'] ) ) return new WP_Error( 'no_review_item' );
        if ( 'ready' !== ( $GLOBALS['review_item']['normalization_status'] ?? '' ) )
            return new WP_Error( 'content_incomplete' );
        return array( 'asset_id' => $input['asset_id'],
            'plan_sha256' => hash( 'sha256', $input['asset_id'] ) );
    }
    public static function legacy_owner_transfer_evidence( $input ) {
        return array( 'folder_membership_verified' => true,
            'migration_authorized' => false,
            'source_id' => $input['source_id'],
            'provider_proof_sha256' => hash( 'sha256', self::$proof_version ),
            'matched_file_count' => 12 );
    }
}
class MAD4B_SCP_Google_Drive_Context {
    public static $write = false;
    public static function connection_status() {
        return array( 'connected' => true, 'read_available' => true,
            'write_available' => self::$write, 'revocation_pending' => false,
            'token_unreadable' => false );
    }
}
class MAD4B_SCP_Deployment_Binding_Native {
    public static function status() {
        return array( 'state' => 'READY_FOR_OWNER_APPROVAL',
            'eligible' => true );
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
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-recovery-alternatives.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-brand-core-remote-recovery.php';
function brand_remote_assert( $ok, $why ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $why . PHP_EOL ); exit( 1 ); }
}
$stages = MAD4B_SCP_Brand_Core_Remote_Recovery::stages();
brand_remote_assert( 8 === count( $stages ), 'eight original recovery stages' );
MAD4B_SCP_Brand_Core_Remote_Recovery::register_abilities();
foreach ( array( 'mad4b/brand-core-remote-recovery-status',
    'mad4b/brand-core-remote-recovery-prepare',
    'mad4b/brand-core-recovery-alternatives',
    'mad4b/brand-core-wordpress-mode',
    'mad4b/context-source-onboarding-preflight' ) as $name ) {
    $ability = $GLOBALS['mad4b_abilities'][ $name ];
    brand_remote_assert( $ability['category'] === 'mad4b-read' &&
        $ability['meta']['annotations']['readonly'] === true &&
        $ability['meta']['mcp']['public'] === false, 'read-only governed ability' );
    brand_remote_assert( $ability['input_schema']['additionalProperties'] === false,
        'no arbitrary runtime fields' );
}
foreach ( $stages as $stage ) {
    foreach ( array( 'read', 'preflight', 'next', 'readback' ) as $slot ) {
        if ( $stage[ $slot ] !== '' ) $GLOBALS['mad4b_abilities'][ $stage[ $slot ] ] = array( 'read_only_stub' => true );
    }
}
brand_remote_assert( $stages['brand_approval']['read'] === 'context/review-queue'
    && $stages['brand_approval']['preflight'] === 'context/remote-owner-review-plan'
    && $stages['brand_approval']['next'] === 'context/remote-owner-review-apply'
    && $stages['brand_approval']['approval'] === true,
    'Brand approval lifecycle must use exact remote owner plan and separately governed decision' );
// The same remote preflight is safe before binding, inside quarantine and
// when requesting broader provider write authority. It never writes.
$folder_intent = array( 'external_root_id' => 'original-drive-folder',
    'source_mode' => 'governed', 'write_policy' => 'managed', 'recursive' => true );
$before_binding = MAD4B_SCP_Brand_Core_Remote_Recovery::source_onboarding_preflight( $folder_intent );
brand_remote_assert( $before_binding['state'] === 'DEPLOYMENT_BINDING_RECOVERY_REQUIRED'
    && $before_binding['next_ability'] === 'mad4b/deployment-binding-native-plan'
    && $before_binding['quarantined_asset_count'] === 12
    && !$before_binding['operational_scope_verified']
    && !$before_binding['source_registry_modified']
    && !$before_binding['legacy_ownership_inferred'],
    'Missing native binding directs to exact per-site plan, never adopts quarantined source' );
MAD4B_SCP_Site_Profile::$bound = true;
$still_quarantined = MAD4B_SCP_Brand_Core_Remote_Recovery::source_onboarding_preflight( $folder_intent );
brand_remote_assert( 'LEGACY_SOURCE_RECONCILIATION_REQUIRED' === $still_quarantined['state']
    && $still_quarantined['next_ability'] === 'context/legacy-owner-transfer-discover',
    'A bound Site Profile does not skip quarantined source owner proof' );
MAD4B_SCP_Context_Authority::$source_count = 0;
MAD4B_SCP_Context_Authority::$asset_count = 0;
$write_pending = MAD4B_SCP_Brand_Core_Remote_Recovery::source_onboarding_preflight( $folder_intent );
brand_remote_assert( 'GOOGLE_WRITE_CONSENT_REQUIRED' === $write_pending['state']
    && !$write_pending['new_source_write_eligible'],
    'Managed library requires current provider consent in addition to policy selection' );
MAD4B_SCP_Google_Drive_Context::$write = true;
$ready_source = MAD4B_SCP_Brand_Core_Remote_Recovery::source_onboarding_preflight( $folder_intent );
brand_remote_assert( 'READY_FOR_GOVERNED_SOURCE_PLAN' === $ready_source['state']
    && $ready_source['new_source_write_eligible']
    && !$ready_source['new_source_authorized']
    && !$ready_source['folder_membership_verified'],
    'Current managed source can be planned, never registered or ownership-certified by readback alone' );
$GLOBALS['onboarding_sources'] = array(
    'a' => array( 'external_root_id' => 'original-drive-folder', 'mode' => 'governed' ) );
$existing_source = MAD4B_SCP_Brand_Core_Remote_Recovery::source_onboarding_preflight( $folder_intent );
brand_remote_assert( 'EXISTING_SOURCE_POLICY_REVIEW_REQUIRED' === $existing_source['state']
    && !$existing_source['new_source_write_eligible'],
    'Re-selecting original source must never create a duplicate record' );
unset($GLOBALS['onboarding_sources']);
brand_remote_assert( is_wp_error( MAD4B_SCP_Brand_Core_Remote_Recovery::source_onboarding_preflight(
    array( 'external_root_id' => 'root' ) ) )
    && is_wp_error( MAD4B_SCP_Brand_Core_Remote_Recovery::source_onboarding_preflight(
    array( 'production_override' => true ) ) ),
    'Root and caller-invented authority parameters are denied' );
MAD4B_SCP_Site_Profile::$bound = false;
MAD4B_SCP_Context_Authority::$source_count = 1;
MAD4B_SCP_Context_Authority::$asset_count = 12;
// WordPress-only mode never treats a saved legacy folder as owned.
$native_view = MAD4B_SCP_Brand_Core_Remote_Recovery::wordpress_mode();
brand_remote_assert( ! is_wp_error( $native_view )
    && $native_view['binding_lifecycle']['eligible']
    && $native_view['binding_lifecycle']['next_ability'] ===
        'mad4b/deployment-binding-native-plan'
    && $native_view['workflow']['native_binding_apply'] ===
        'mad4b/deployment-binding-native-apply'
    && $native_view['workflow']['functional_transfer_readback'] ===
        'context/native-owner-transfer-readback'
    && $native_view['workflow']['native_shadow_cleanup'] ===
        'mad4b/native-recovery-cleanup',
    'WordPress lifecycle links exact site binding, functional transfer and cleanup' );
brand_remote_assert( ! is_wp_error( $native_view )
    && 'OWNER_TRANSFER_BLOCKED' === $native_view['state']
    && $native_view['source']['selected_source_id'] === str_repeat( 'b', 64 )
    && $native_view['quarantine_count'] === 13
    && !$native_view['automatic_owner_transfer']
    && !$native_view['automatic_file_approval']
    && !$native_view['native_crash_recovery_certified']
    && !$native_view['release_or_publication_authorized']
    && !$native_view['mutation_performed'], 'Host-free native recovery is a safe read-only workflow' );
MAD4B_SCP_Context_Authority::$scope_verified = false;
$binding_missing = MAD4B_SCP_Brand_Core_Remote_Recovery::wordpress_mode();
brand_remote_assert( ! is_wp_error( $binding_missing )
    && 'WORDPRESS_NATIVE_DEPLOYMENT_BINDING_REQUIRED' === $binding_missing['state']
    && 'mad4b/deployment-binding-native-plan' === $binding_missing['source']['next_ability']
    && $binding_missing['source']['unclassified_quarantined_sources'] === 1
    && !$binding_missing['source']['scope_verified']
    && !$binding_missing['native_crash_recovery_certified'],
    'Unverified deployment identity needs WordPress-native binding before any file review' );
MAD4B_SCP_Context_Authority::$scope_verified = true;
$foreign = MAD4B_SCP_Brand_Core_Remote_Recovery::wordpress_mode(
    array( 'source_id' => str_repeat( 'c', 64 ) ) );
brand_remote_assert( is_wp_error( $foreign ), 'Cannot choose foreign source' );
brand_remote_assert( is_wp_error( MAD4B_SCP_Brand_Core_Remote_Recovery::wordpress_mode(
    array( 'permission_override' => true ) ) ), 'Cannot request an undocumented authority flag' );
brand_remote_assert( is_wp_error( MAD4B_SCP_Brand_Core_Remote_Recovery::wordpress_mode(
    array( 'limit' => 200 ) ) ), 'Review inventory is bounded' );
$GLOBALS['review_item'] = array( 'asset_id' => str_repeat( 'd', 64 ),
    'source_id' => str_repeat( 'e', 64 ), 'title' => 'Brand Strategy.md',
    'category' => 'brand_strategy', 'required' => true,
    'content_hash' => str_repeat( 'f', 64 ), 'review_status' => 'unreviewed',
    'normalization_status' => 'ready' );
$with_file = MAD4B_SCP_Brand_Core_Remote_Recovery::wordpress_mode();
brand_remote_assert( ! is_wp_error( $with_file )
    && 1 === $with_file['file_count']
    && $with_file['files'][0]['plan_ready']
    && $with_file['files'][0]['next_governed_write'] === 'context/remote-owner-review-apply'
    && $with_file['files'][0]['separate_human_approved_ticket_required']
    && !$with_file['automatic_file_approval'],
    'Native mode discovers exact file review plan, never approves the file itself' );
$GLOBALS['review_item']['normalization_status'] = 'stale';
$unready = MAD4B_SCP_Brand_Core_Remote_Recovery::wordpress_mode();
brand_remote_assert( ! $unready['files'][0]['plan_ready']
    && '' === $unready['files'][0]['next_governed_write']
    && 'content_incomplete' === $unready['files'][0]['blocker'],
    'Incomplete source is ineligible for approval' );
unset( $GLOBALS['review_item'] );
$normal = MAD4B_SCP_Brand_Core_Remote_Recovery::status();
brand_remote_assert( isset( $normal['alternative_routes']['domains']['host_runner'] )
    && !$normal['alternative_routes']['all_remote_steps_execution_certified'],
    'main status integrates safe alternative route search without claiming acceptance' );
$known_routes = MAD4B_SCP_Brand_Core_Remote_Recovery::alternatives(
    array( 'domain' => 'candidate_delivery' ) );
brand_remote_assert( ! is_wp_error( $known_routes )
    && isset( $known_routes['domains']['candidate_delivery'] )
    && !isset( $known_routes['domains']['host_runner'] ),
    'MCP alternate route selector returns only requested domain' );
$alternatives = MAD4B_SCP_Recovery_Alternatives::assess( array(
    'mad4b/staging-offline-attestor-enroll-plan' => true,
    'mad4b/control-plane-selected-head-apply' => true,
    'mad4b/build-provenance-status' => true,
), 'candidate_delivery' );
brand_remote_assert( !is_wp_error( $alternatives )
    && $alternatives['domains']['candidate_delivery']['suggested_read_only_preflight_route']
        === 'signed_offline_staging'
    && !$alternatives['all_remote_steps_execution_certified'],
    'offline CI fallback discoverable even when primary Actions plan not mounted' );
$missing_db = MAD4B_SCP_Recovery_Alternatives::assess( array(), 'database_recovery' );
foreach ( $missing_db['domains']['database_recovery']['candidates'] as $route ) {
    brand_remote_assert( !$route['execution_authorized']
        && !$route['automatic_fallback_allowed'],
        'isolated MariaDB alternative cannot bypass independent crash/rollback test' );
}
brand_remote_assert( ! is_wp_error( $normal ) && $normal['state'] === 'REMOTE_RECOVERY_INCOMPLETE',
    'normal plan must be incomplete' );
brand_remote_assert( $normal['stages']['source_inventory']['evidence_observed'] === true,
    'fresh admin census observed' );
brand_remote_assert( $normal['stages']['owner_attestation']['evidence_observed'] === false,
    'census cannot attest ownership' );
brand_remote_assert( in_array( 'owner_attestation',
    $normal['stages']['brand_approval']['waiting_on_upstream'], true ),
    'Quarantined sources must still require independent original owner proof' );
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
// Deliberately mixed snapshots must block all observed ready states even
// when a provider proof and Host signature are individually true.
$drift = MAD4B_SCP_Brand_Core_Remote_Recovery::reduce(
    array( 'site_ready' => true, 'evidence_drift' => true,
        'source_id' => $source, 'host_verified' => true,
        'host_challenge_attempted' => true,
        'census' => array( 'source_quarantine_count' => 0,
            'asset_quarantine_count' => 0 ),
        'proof' => array( 'folder_membership_verified' => true,
            'migration_authorized' => false, 'source_id' => $source,
            'provider_proof_sha256' => str_repeat( 'a', 64 ) ),
        'brand_coverage' => array( 'ready' => true,
            'coverage' => array(
                'brand_strategy' => array( 'ready' => true ),
                'tone_of_voice' => array( 'ready' => true ),
                'editorial_guidelines' => array( 'ready' => true ),
            ) ),
    ), array() );
brand_remote_assert( $drift['state'] === 'SNAPSHOT_DRIFT_REPLAN_REQUIRED',
    'mixed registry/provider generations cannot be accepted' );
brand_remote_assert( $drift['functional_lifecycle']['state'] ===
    'SNAPSHOT_DRIFT_REPLAN_REQUIRED'
    && $drift['disaster_recovery']['state'] === 'SNAPSHOT_DRIFT_REPLAN_REQUIRED'
    && !$drift['functional_lifecycle']['mariadb_crash_certified'],
    'Both separated recovery tiers must reject mixed provenance snapshots' );
foreach ( $drift['stages'] as $row ) {
    brand_remote_assert( $row['state'] === 'SNAPSHOT_DRIFT_REPLAN_REQUIRED'
        && ! $row['evidence_observed']
        && in_array( 'cross_read_snapshot_drift', $row['blockers'], true ),
        'snapshot drift denies all cross-source evidence and writable handoffs' );
}
// A provider scan may race a Context registry change on the second
// independent WordPress observation. It must not generate a green plan.
MAD4B_SCP_Context_Authority::$census_reads = 0;
MAD4B_SCP_Context_Authority::$drift_second_census = true;
$raced = MAD4B_SCP_Brand_Core_Remote_Recovery::status();
brand_remote_assert( ! is_wp_error( $raced )
    && $raced['state'] === 'SNAPSHOT_DRIFT_REPLAN_REQUIRED'
    && in_array( 'cross_read_snapshot_drift',
        $raced['stages']['source_inventory']['blockers'], true ),
    'actual second read racing the Context census must stop recovery' );
MAD4B_SCP_Context_Authority::$drift_second_census = false;
MAD4B_SCP_Context_Authority::$source_count = 0;
MAD4B_SCP_Context_Authority::$asset_count = 0;
MAD4B_SCP_Context_Authority::$ready = true;
MAD4B_SCP_Selected_Head_Update::$eligible = true;
$possible = MAD4B_SCP_Brand_Core_Remote_Recovery::status( array(
    'candidate_sha' => str_repeat( 'c', 40 ) ) );
brand_remote_assert( ! in_array( 'owner_attestation',
    $possible['stages']['brand_approval']['upstream_stages'], true ),
    'A clean first-party registry must not wait forever for a nonexistent legacy transfer' );
brand_remote_assert( $possible['functional_lifecycle']['state'] ===
    'BRAND_CORE_COVERAGE_READY'
    && $possible['disaster_recovery']['state'] === 'REMOTE_RECOVERY_INCOMPLETE'
    && !$possible['functional_lifecycle']['release_certified'],
    'Functional Brand Core readiness never equals disaster recovery or release readiness' );
brand_remote_assert( $possible['stages']['brand_approval']['evidence_observed'] === true
    && $possible['stages']['certified_package']['evidence_observed'] === false
    && $possible['stages']['draft_acceptance']['evidence_observed'] === false
    && $possible['full_operational_acceptance'] === false,
    'brand approval and signed manifest preflight cannot silently certify installation/draft' );
// Native recovery functional reducer is a separate WordPress-only
// rehearsal. No Host Runner and no original Context write is needed.
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-native-recovery.php';
brand_remote_assert( MAD4B_SCP_Native_Recovery::cross_request_verified(
    str_repeat( 'a', 32 ), str_repeat( 'b', 32 ), 100, 99, true ),
    'native cross-request scratch proof can be observed without Host' );
brand_remote_assert( ! MAD4B_SCP_Native_Recovery::cross_request_verified(
    str_repeat( 'a', 32 ), str_repeat( 'a', 32 ), 100, 99, true ),
    'same request is never valid native recovery' );
brand_remote_assert( ! MAD4B_SCP_Native_Recovery::cross_request_verified(
    str_repeat( 'a', 32 ), str_repeat( 'b', 32 ), 100, 101, true ),
    'expired native evidence denied' );
brand_remote_assert( ! MAD4B_SCP_Native_Recovery::cross_request_verified(
    str_repeat( 'a', 32 ), str_repeat( 'b', 32 ), 100, 99, false ),
    'source drift invalidates native shadow' );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-operator-journeys.php';
$journeys = MAD4B_SCP_Operator_Journeys::reduce( array() );
brand_remote_assert( isset( $journeys['journeys']['setup_and_permissions'],
    $journeys['journeys']['forms_and_integration'],
    $journeys['journeys']['plugin_upgrades'],
    $journeys['journeys']['wordpress_native_recovery'] )
    && !$journeys['execution_authorized'] && !$journeys['workflow_completed'],
    'whole-plugin task-first UX never self-certifies or executes' );
$all_available = array();
foreach ( MAD4B_SCP_Operator_Journeys::recipes() as $recipe )
    foreach ( $recipe['steps'] as $step ) $all_available[$step[1]] = true;
$resolved = MAD4B_SCP_Operator_Journeys::reduce( $all_available );
brand_remote_assert( $resolved['journeys']['plugin_upgrades']['all_steps_mounted']
    && !$resolved['journeys']['plugin_upgrades']['live_acceptance_verified'],
    'all registered buttons are still not release acceptance' );
echo "mad4b.site-control-plane.brand-core-remote-recovery.runtime.v1: PASS\n";
