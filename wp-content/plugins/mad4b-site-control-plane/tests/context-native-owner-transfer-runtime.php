<?php
/**
 * Functional-only Brand Core legacy transfer acceptance.
 * Pure PHP fixtures, no WordPress DB, no Host, no Google Drive network and NO writes.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function current_user_can( $cap ) { return ! empty( $GLOBALS['admin'] ); }
function get_current_user_id() { return ! empty( $GLOBALS['admin'] ) ? 13 : 0; }
function get_option( $key, $default = false ) { return $GLOBALS['opts'][ $key ] ?? $default; }
function check( $truth, $why ) {
    if ( ! $truth ) { fwrite( STDERR, "FAIL: " . $why . PHP_EOL ); exit( 1 ); }
}
class MAD4B_SCP_Policy {
    public static function can_mutate() { return ! empty( $GLOBALS['write_allowed'] ); }
}
class MAD4B_SCP_Site_Profile {
    public static $env = 'staging';
    public static function status() {
        return array( 'configured' => true, 'site_uuid' => $GLOBALS['site_uuid'],
            'profile_digest' => str_repeat( 'e', 64 ),
            'environment' => self::$env,
            'origin_match' => true, 'environment_match' => true );
    }
    public static function user_is_enrolled( $user ) { return 13 === (int) $user; }
}
class MAD4B_SCP_Operational_Scope_Guard {
    public static function require_current() { return $GLOBALS['scope']; }
}
class MAD4B_SCP_Google_Drive_Context {
    public static $changed = false;
    public static function scan_folder( $root, $recursive ) {
        return array(
            'folder' => array( 'id' => $root, 'mimeType' => 'application/vnd.google-apps.folder' ),
            'assets' => array( array( 'file_id' => 'existing-file-A',
                'parent_folder_id' => $root,
                'mimeType' => 'application/vnd.google-apps.document',
                'modifiedTime' => self::$changed ? '2026-10-14T01:00:00Z' : '2026-10-13T01:00:00Z',
                'content_hash' => hash( 'sha256', self::$changed ? 'changed' : 'original' ) ) ),
            'complete' => true, 'truncated' => false, 'recursive' => (bool) $recursive );
    }
}
class MAD4B_SCP_Native_Recovery {
    public static $wrong_source = false;
    public static $same_request = false;
    public static $stale = false;
    public static function readback( $input ) {
        $proof = MAD4B_SCP_Context_Authority::legacy_owner_transfer_evidence(
            array( 'source_id' => $GLOBALS['source_id'] ) );
        return array(
            'state' => 'WP_NATIVE_CROSS_REQUEST_VERIFIED',
            'mode' => 'shadow', 'source_id' => self::$wrong_source ? str_repeat( 'f', 64 ) : $GLOBALS['source_id'],
            'independent_request_observed' => ! self::$same_request,
            'scratch_functional_rehearsal_verified' => ! self::$same_request,
            'provider_proof_still_exact' => ! self::$stale,
            'shadow_plan_still_exact' => ! self::$stale,
            'fresh' => true,
            'stored_provider_proof_sha256' => $GLOBALS['original_proof_sha256'],
        );
    }
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    const AUTHORITY_STEP_UP_SCOPE = 'mad4b:authority:step-up';
    public static $active = true;
    public static function verified_bearer_active() { return self::$active; }
    public static function verified_bearer_has_scope( $scope ) { return self::$active; }
    public static function verified_bearer_client_is( $client ) { return $client === 'mad4b-chatgpt'; }
}
class MAD4B_SCP_Local_OAuth_Server { const CHATGPT_CIMD_CLIENT_ID = 'mad4b-chatgpt'; }
class MAD4B_SCP_Identity_Context {
    public static $valid = true;
    public static function current() {
        return array( 'authenticated' => self::$valid, 'auth_method' => 'oauth2_bearer',
            'wp_user_id' => self::$valid ? 13 : 0, 'subject_fingerprint' => str_repeat( 'c', 64 ),
            'approval_ticket_id' => 'ticket-123' );
    }
}
class MAD4B_SCP_Approval_Tickets {
    public static $approved_by = 0;
    public static $ability = 'context/native-owner-transfer-apply';
    public static function get( $id ) {
        return array( 'status' => 'executing', 'approved_by' => self::$approved_by,
            'ability_name' => self::$ability );
    }
}
$GLOBALS['admin'] = true;
$GLOBALS['write_allowed'] = true;
$GLOBALS['site_uuid'] = '11111111-2222-3333-4444-555555555555';
$GLOBALS['brand_id'] = str_repeat( 'a', 32 );
$GLOBALS['scope'] = array( 'site_uuid' => $GLOBALS['site_uuid'], 'brand_ref' => $GLOBALS['brand_id'],
    'tenant_ref' => 'tenant-1', 'blog_id' => '1', 'network_id' => '1',
    'environment' => 'staging', 'deployment_mode' => 'wordpress_dedicated' );
$root = 'original-brand-folder';
$GLOBALS['source_id'] = hash( 'sha256', $GLOBALS['site_uuid'] . '|google_drive|governed|' . $root . '|' );
$asset_id = hash( 'sha256', $GLOBALS['source_id'] . '|existing-file-A' );
$GLOBALS['opts'] = array(
    'mad4b_scp_brand_context_profile_v1' => array(
        'contract' => 'mad4b.brand-context-profile.v1',
        'site_uuid' => $GLOBALS['site_uuid'], 'brand_id' => $GLOBALS['brand_id'],
        'brand_name' => 'Original brand', 'revision' => 1 ),
    'mad4b_scp_context_sources_v1' => array( $GLOBALS['source_id'] => array(
        'contract' => 'mad4b.context-source.v1', 'source_id' => $GLOBALS['source_id'],
        'site_uuid' => $GLOBALS['site_uuid'], 'provider' => 'google_drive',
        'mode' => 'governed', 'task_scope' => '', 'external_root_id' => $root,
        'brand_id' => '', 'recursive' => true ) ),
    'mad4b_scp_context_assets_v1' => array( $asset_id => array(
        'contract' => 'mad4b.context-asset.v1', 'asset_id' => $asset_id,
        'site_uuid' => $GLOBALS['site_uuid'], 'source_id' => $GLOBALS['source_id'],
        'source_mode' => 'governed', 'file_id' => 'existing-file-A', 'brand_id' => '' ) ),
    'mad4b_scp_context_registry_revision_v1' => 3,
);
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';
$original = MAD4B_SCP_Context_Authority::legacy_owner_transfer_evidence(
    array( 'source_id' => $GLOBALS['source_id'] ) );
check( is_array( $original ) && $original['folder_membership_verified'], 'Original Drive member set proves only identity.' );
$GLOBALS['original_proof_sha256'] = $original['provider_proof_sha256'];
$job = str_repeat( '1', 32 );
$input = array( 'source_id' => $GLOBALS['source_id'], 'job_id' => $job );
$plan = MAD4B_SCP_Context_Authority::native_owner_transfer_plan( $input );
check( is_array( $plan ) && $plan['eligible'] && $plan['asset_count'] === 1
    && !$plan['mariadb_crash_certified'] && !$plan['rollback_certified']
    && !$plan['production_mutation_authorized'] && !$plan['mutation_performed'],
    'Original source can be planned without a Host while refusing disaster certification.' );
MAD4B_SCP_Native_Recovery::$same_request = true;
check( is_wp_error( MAD4B_SCP_Context_Authority::native_owner_transfer_plan( $input ) ),
    'Same request never proves shadow write persistence.' );
MAD4B_SCP_Native_Recovery::$same_request = false;
MAD4B_SCP_Native_Recovery::$wrong_source = true;
check( is_wp_error( MAD4B_SCP_Context_Authority::native_owner_transfer_plan( $input ) ),
    'Foreign brand source must be rejected.' );
MAD4B_SCP_Native_Recovery::$wrong_source = false;
MAD4B_SCP_Native_Recovery::$stale = true;
check( is_wp_error( MAD4B_SCP_Context_Authority::native_owner_transfer_plan( $input ) ),
    'Stale snapshot must be rejected.' );
MAD4B_SCP_Native_Recovery::$stale = false;
MAD4B_SCP_Google_Drive_Context::$changed = true;
check( is_wp_error( MAD4B_SCP_Context_Authority::native_owner_transfer_plan( $input ) ),
    'Provider file-version change invalidates shadow receipt.' );
MAD4B_SCP_Google_Drive_Context::$changed = false;
MAD4B_SCP_Site_Profile::$env = 'production';
check( is_wp_error( MAD4B_SCP_Context_Authority::native_owner_transfer_plan( $input ) ),
    'Production transfers denied even without an external host.' );
MAD4B_SCP_Site_Profile::$env = 'staging';
$transfer = $input + array(
    'expected_native_plan_sha256' => $plan['plan_sha256'],
    'expected_plan_sha256' => $plan['legacy_plan_sha256'],
    'expected_provider_proof_sha256' => $plan['provider_proof_sha256'],
    'expected_brand_id' => $plan['brand_id'],
    'reviewed_external_root_id' => $root, 'owner_evidence_reference' => 'reviewer-ticket-exact',
    'confirmation' => 'APPROVE WORDPRESS FUNCTIONAL TRANSFER ONLY',
);
MAD4B_SCP_OAuth_Resource_Bridge::$active = false;
$unstepped = MAD4B_SCP_Context_Authority::native_owner_transfer_apply( $transfer );
check( is_wp_error( $unstepped )
    && $unstepped->get_error_code() === 'mad4b_native_transfer_stepup_required',
    'No owner bearer step-up means no mutation.' );
MAD4B_SCP_OAuth_Resource_Bridge::$active = true;
$missing_human = MAD4B_SCP_Context_Authority::native_owner_transfer_apply( $transfer );
check( is_wp_error( $missing_human ) && $missing_human->get_error_code()
    === 'mad4b_native_transfer_human_ticket_required',
    'AI-signed or missing human write ticket never transfers source.' );
MAD4B_SCP_Approval_Tickets::$approved_by = 13;
MAD4B_SCP_Approval_Tickets::$ability = 'context/remote-owner-review-apply';
check( is_wp_error( MAD4B_SCP_Context_Authority::native_owner_transfer_apply( $transfer ) ),
    'Approved ticket for a different ability never transfers ownership.' );
MAD4B_SCP_Approval_Tickets::$ability = 'context/native-owner-transfer-apply';
$transfer['expected_native_plan_sha256'] = str_repeat( 'f', 64 );
$drift = MAD4B_SCP_Context_Authority::native_owner_transfer_apply( $transfer );
check( is_wp_error( $drift ) && $drift->get_error_code() === 'mad4b_native_transfer_stale_plan',
    'Even an approved human ticket cannot bypass exact transfer plan.' );
$transfer['expected_native_plan_sha256'] = $plan['plan_sha256'];
$transfer['confirmation'] = 'APPROVE EXACT UNBOUND BRAND TRANSFER';
check( is_wp_error( MAD4B_SCP_Context_Authority::native_owner_transfer_apply( $transfer ) ),
    'Host-certified confirmation is not native functional approval.' );
$legacy = MAD4B_SCP_Context_Authority::legacy_owner_transfer_apply( $transfer );
check( is_wp_error( $legacy )
    && $legacy->get_error_code() === 'mad4b_legacy_transfer_rollback_certification_required',
    'Existing independent disaster-recovery gate is still enforced.' );
check( !empty($GLOBALS['opts']['mad4b_scp_context_sources_v1'][$GLOBALS['source_id']])
    && '' === $GLOBALS['opts']['mad4b_scp_context_sources_v1'][$GLOBALS['source_id']]['brand_id'],
    'All denial paths preserve original quarantined registry.' );
echo "PASS: WordPress-native plan source, shadow, version, site and explicit human approval safety\n";
