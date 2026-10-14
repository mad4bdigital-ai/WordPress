<?php
/** Safe auto-discovery and exact selected source evidence; no WordPress mutation. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $r ) { return $r instanceof WP_Error; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][$name] ); }
function insist( $truth, $message ) {
    if ( ! $truth ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}
class MAD4B_SCP_Policy {
    public static function can_read() { return ! empty( $GLOBALS['site_read'] ); }
}
class MAD4B_SCP_Site_Profile {
    public static function status() {
        return array( 'site_uuid' => $GLOBALS['site_uuid'],
            'profile_digest' => 'profile-exact', 'canonical_origin' => 'https://staging.example.test',
            'environment' => $GLOBALS['site_environment'], 'origin_match' => true, 'environment_match' => true );
    }
}
class MAD4B_SCP_Context_Authority {
    public static function legacy_owner_transfer_discover( $input ) {
        return array( 'eligible_unbound_candidates' => $GLOBALS['candidates'] );
    }
    public static function legacy_owner_transfer_evidence( $input ) {
        if ( ! empty( $GLOBALS['oauth_blocked'] ) ) return new WP_Error( 'provider_refresh_required' );
        if ( ! empty( $GLOBALS['site_drift'] ) ) $GLOBALS['site_uuid'] = 'foreign';
        return array( 'source_id' => $input['source_id'],
            'folder_membership_verified' => ! empty( $GLOBALS['complete_scan'] ),
            'provider_proof_sha256' => str_repeat( 'f', 64 ),
            'plan_sha256' => str_repeat( 'a', 64 ),
            'original_folder_id' => 'selected-source-folder', 'matched_file_count' => 12 );
    }
}
class MAD4B_SCP_Brand_Core_Remote_Recovery {
    public static function source_onboarding_preflight( $input ) {
        return array( 'state' => 'DEPLOYMENT_BINDING_RECOVERY_REQUIRED',
            'next_ability' => 'mad4b/deployment-binding-native-plan',
            'source_registry_modified' => false, 'mutation_performed' => false );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-obstacle-resolution.php';
$GLOBALS['site_uuid'] = 'site-1';
$GLOBALS['site_environment'] = 'staging';
$GLOBALS['site_read'] = true;
$GLOBALS['oauth_blocked'] = false;
$GLOBALS['site_drift'] = false;
$GLOBALS['complete_scan'] = true;
$id = str_repeat( 'b', 64 );
$GLOBALS['candidates'] = array( array( 'source_id' => $id ) );
$GLOBALS['abilities'] = array_fill_keys( array(
    'mad4b/selected-folder-scope-proof', 'mad4b/scrape-transfer-plan',
    'mad4b/content-model-discover', 'mad4b/semantic-structure-inspect',
    'mad4b/content-orchestration-plan', 'mad4b/content-bundle-readback',
    'mad4b/content-update-post', 'mad4b/publication-verification-evaluate',
), true );
$folder = MAD4B_SCP_Runtime_Obstacle_Resolution::selected_folder_proof();
insist( ! is_wp_error( $folder ) && $folder['scope_verified'] &&
    $folder['source_id'] === $id && $folder['matched_file_count'] === 12 &&
    !$folder['ownership_transferred'] && !$folder['approved_brand_core_issued'] &&
    !$folder['mutation_performed'], 'Selected source should gain a read-only exact provider scope proof.' );
$input = array( 'blockers' => array( 'BLOCKED_OWNERSHIP_QUARANTINE',
    'refresh_required', 'package_identity_complete', 'jetengine_relation_drift',
    'tone_of_voice_missing', 'source_rights_unknown', 'source_rights_unknown' ),
    'target_post_type' => 'products', 'perform_safe_probes' => true );
$plan = MAD4B_SCP_Runtime_Obstacle_Resolution::plan( $input );
insist( ! is_wp_error( $plan ) &&
    count( $plan['resolution_routes'] ) === 6 &&
    $plan['selected_folder_live_probe']['scope_verified'] &&
    !$plan['authorizing'] && !$plan['mutation_performed'],
    'Idempotent plan should dedupe blockers and probe only selected source membership.' );
$cats = array_column( $plan['resolution_routes'], 'category' );
insist( in_array( 'selected_folder_scope', $cats, true ) &&
    in_array( 'google_drive_auth', $cats, true ) &&
    in_array( 'semantic_structure', $cats, true ) &&
    in_array( 'brand_context', $cats, true ) &&
    in_array( 'source_rights', $cats, true ), 'Distinct remediation routes preserved.' );
// A missing integrity scope must route into bound-site recovery BEFORE
// attempting quarantined source membership or any write.
$integrity = MAD4B_SCP_Runtime_Obstacle_Resolution::plan( array(
    'blockers' => array( 'mad4b_integrity_scope_blocked' ),
    'perform_safe_probes' => true ) );
insist( 'source_onboarding' === $integrity['resolution_routes'][0]['category']
    && 'mad4b/context-source-onboarding-preflight' ===
        $integrity['resolution_routes'][0]['next_read_ability']
    && 'DEPLOYMENT_BINDING_RECOVERY_REQUIRED' ===
        $integrity['source_onboarding_live_probe']['state']
    && !$integrity['source_onboarding_live_probe']['mutation_performed'],
    'Missing integrity scope must never be sent into legacy folder transfer or unknown blocker.' );
$GLOBALS['candidates'][] = array( 'source_id' => str_repeat( 'c', 64 ) );
$ambiguous = MAD4B_SCP_Runtime_Obstacle_Resolution::selected_folder_proof();
insist( ! is_wp_error( $ambiguous ) && $ambiguous['state'] === 'EXACT_FOLDER_SELECTION_REQUIRED',
    'Multiple possible selected folders must not be auto-reassigned.' );
$GLOBALS['candidates'] = array( array( 'source_id' => $id ) );
$GLOBALS['complete_scan'] = false;
$partial = MAD4B_SCP_Runtime_Obstacle_Resolution::selected_folder_proof();
insist( is_wp_error( $partial ) &&
    $partial->get_error_code() === 'mad4b_selected_folder_provider_proof_incomplete',
    'Incomplete provider inventory must not verify scope.' );
$GLOBALS['complete_scan'] = true;
$GLOBALS['site_drift'] = true;
$drift = MAD4B_SCP_Runtime_Obstacle_Resolution::selected_folder_proof();
insist( is_wp_error( $drift ) &&
    $drift->get_error_code() === 'mad4b_selected_folder_site_drift',
    'Mid-scan site identity change must fail closed.' );
$GLOBALS['site_uuid'] = 'site-1';
$GLOBALS['site_drift'] = false;
$GLOBALS['oauth_blocked'] = true;
$pending = MAD4B_SCP_Runtime_Obstacle_Resolution::plan( $input );
insist( ! is_wp_error( $pending ) &&
    $pending['selected_folder_live_probe']['error_code'] === 'provider_refresh_required',
    'OAuth provider failure should become a route, never fake success.' );
$GLOBALS['oauth_blocked'] = false;
$GLOBALS['site_environment'] = 'production';
$production = MAD4B_SCP_Runtime_Obstacle_Resolution::selected_folder_proof();
insist( is_wp_error( $production ), 'Legacy scope review is Staging only.' );
$GLOBALS['site_environment'] = 'staging';
$GLOBALS['site_read'] = false;
insist( is_wp_error( MAD4B_SCP_Runtime_Obstacle_Resolution::plan( $input ) ),
    'Read authority is mandatory.' );
echo "PASS: selected folder scope, exact identity, Drive pending, mid-scan drift, route discovery, zero permission escalation\n";
