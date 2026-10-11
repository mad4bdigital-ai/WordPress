<?php
/** Isolated, no-network self-certified Staging release policy fixture. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function assert_self_approval( $yes, $reason ) {
    if ( ! $yes ) { fwrite( STDERR, "FAIL: " . $reason . "\n" ); exit( 1 ); }
}
function add_action( $name, $fn, $priority = 10 ) { $GLOBALS['actions'][] = $name; }
function wp_has_ability( $name ) { return isset( $GLOBALS['registered'][ $name ] ); }
function wp_register_ability( $name, $args ) { $GLOBALS['registered'][ $name ] = $args; }
function sanitize_text_field( $s ) { return trim( $s ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_get_environment_type() { return $GLOBALS['env'] ?? 'staging'; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['owner'] ); }
function get_current_user_id() { return ! empty( $GLOBALS['owner'] ) ? 15 : 0; }
class MAD4B_SCP_Policy {
    public static function can_read() { return true; }
    public static function can_mutate() { return true; }
}
class MAD4B_SCP_Site_Profile {
    public static function status() { return array( 'site_uuid' => 'site-a',
        'profile_digest' => 'profile-a', 'canonical_origin' => 'https://staging.example.test' ); }
    public static function user_is_enrolled( $user ) { return $user === 15; }
}
class MAD4B_SCP_WordPress_Native_Opt_In {
    public static function enabled() { return true; }
}
class MAD4B_SCP_WP_Native_Build {
    public static function capabilities() { return array( 'eligible' => true ); }
    public static function simulate( $input ) {
        return array( 'state' => $GLOBALS['verified'] ?
            'LOCAL_SELF_CHECKS_PASSED_UNTRUSTED' : 'LOCAL_SELF_CHECKS_BLOCKED',
            'local_checks' => array( 'archive_sha256_bytes' => $GLOBALS['verified'] ? 'LOCAL_PASS' : 'BLOCKED' ) );
    }
    public static function status() {
        return array( 'head' => str_repeat( 'a', 40 ), 'state' => 'BUILT_UNVERIFIED',
            'canonical_receipt' => array( 'control_plane_version' => '0.4.0-rc.97' ),
            'archive_sha256' => hash( 'sha256', 'ZIP' ), 'archive_bytes' => 3,
            'build_fingerprint' => str_repeat( 'b', 64 ),
            'package_manifest_digest' => str_repeat( 'c', 64 ),
            'canonical_receipt_sha256' => str_repeat( 'd', 64 ) );
    }
    public static function local_package_bytes( $sha ) { return $GLOBALS['package_bytes']; }
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    const AUTHORITY_STEP_UP_SCOPE = 'owner-step-up';
    public static function verified_bearer_active() { return ! empty( $GLOBALS['oauth'] ); }
    public static function verified_bearer_has_scope( $scope ) { return ! empty( $GLOBALS['oauth'] ); }
    public static function verified_bearer_client_is( $client ) { return ! empty( $GLOBALS['oauth'] ); }
}
class MAD4B_SCP_Local_OAuth_Server { const CHATGPT_CIMD_CLIENT_ID = 'mad4b-chatgpt'; }
class MAD4B_SCP_Authorization {
    public static function permission_result_from_authorization( $r ) {
        return is_array( $r ) && !empty( $r['allowed'] )
            && ( $r['reason_code'] ?? '' ) === 'preflight_allowed';
    }
}
class MAD4B_SCP_Self_Update {
    public static function upload_plan( $input ) {
        assert_self_approval( $input['channel'] === 'wordpress_native_candidate_upload'
            && $input['candidate_source']['type'] === 'commit'
            && $input['source_commit_sha'] === $input['candidate_source']['reference'],
            'Only exact WordPress-native Staging candidate can be staged' );
        return array( 'eligible' => true, 'blockers' => array(),
            'plan_sha256' => str_repeat( 'f', 64 ) );
    }
    public static function can_upload_apply( $input ) {
        return !empty( $GLOBALS['grant'] )
            ? array( 'allowed' => true, 'reason_code' => 'preflight_allowed' )
            : new WP_Error( 'grant_denied' );
    }
    public static function upload_apply( $input ) {
        $GLOBALS['installs']++;
        assert_self_approval( $input['channel'] === 'wordpress_native_candidate_upload'
            && $input['expected_plan_sha256'] === str_repeat( 'f', 64 )
            && $input['candidate_confirmation'] === 'INSTALL EXACT STAGING CANDIDATE'
            && base64_decode( $input['package_base64'], true ) === 'ZIP',
            'Existing package updater required and retains exact plan' );
        return array( 'installed' => true, 'rollback_ownership' => 'existing_updater' );
    }
}
class MAD4B_SCP_Audit {
    public static function record( $ability, $summary, $result ) {
        $GLOBALS['audit']++;
        return ! empty( $GLOBALS['audit_ready'] ) ? true : new WP_Error( 'audit_failed' );
    }
}
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-wp-native-self-approval.php';
$c = 'MAD4B_SCP_WP_Native_Self_Approval';
$c::register_abilities(); $c::register_abilities();
assert_self_approval( count( $GLOBALS['registered'] ) === 2,
    'Plan and install Abilities are idempotently registered' );
assert_self_approval( $GLOBALS['registered'][$c::PLAN]['meta']['annotations']['readonly'] === true
    && $GLOBALS['registered'][$c::APPLY]['meta']['annotations']['readonly'] === false
    && $GLOBALS['registered'][$c::APPLY]['meta']['mcp']['production_mutation_allowed'] === false,
    'Read classification and Production denial are explicit' );
$GLOBALS['owner'] = true;
$GLOBALS['oauth'] = true;
$GLOBALS['grant'] = true;
$GLOBALS['verified'] = true;
$GLOBALS['audit_ready'] = true;
$GLOBALS['package_bytes'] = 'ZIP';
$GLOBALS['installs'] = 0;
$GLOBALS['audit'] = 0;
$sha = str_repeat( 'a', 40 );
$args = array( 'expected_head' => $sha, 'reason' => 'Owner delegates Staging package self-certification' );
assert_self_approval( is_wp_error( $c::plan( array(
    'expected_head' => 'main', 'reason' => 'invalid source' ) ) ),
    'A mutable Git source is never eligible' );
$GLOBALS['verified'] = false;
$bad = $c::plan( $args );
assert_self_approval( ! $bad['eligible'] && in_array( 'local_zip_receipt_or_profile_unverified',
    $bad['blockers'], true ), 'Incomplete local evidence cannot self-certify' );
$GLOBALS['verified'] = true;
$plan = $c::plan( $args );
assert_self_approval( $plan['eligible'] &&
    $plan['evidence_mode'] === 'site_self_certified_staging' &&
    $plan['release_certified'] === false && $plan['independent_signature_present'] === false
    && $plan['native_php_matrix_executed'] === false,
    'Self-certification is admitted, but never falsely described as independent CI' );
$apply = array_merge( $args, array(
    'expected_plan_sha256' => $plan['plan_sha256'],
    'confirmation' => $c::CONFIRM ) );
$bad_confirm = $apply; $bad_confirm['confirmation'] = 'INSTALL PRODUCTION';
assert_self_approval( is_wp_error( $c::apply( $bad_confirm ) ),
    'Owner confirmation is exact and Staging-only' );
$GLOBALS['package_bytes'] = 'MODIFIED';
assert_self_approval( is_wp_error( $c::apply( $apply ) ) &&
    $GLOBALS['installs'] === 0, 'Tampered package is never installed' );
$GLOBALS['package_bytes'] = 'ZIP';
$GLOBALS['oauth'] = false;
assert_self_approval( is_wp_error( $c::apply( $apply ) ) &&
    $GLOBALS['installs'] === 0, 'OAuth step-up cannot be skipped' );
$GLOBALS['oauth'] = true;
$GLOBALS['grant'] = false;
assert_self_approval( is_wp_error( $c::apply( $apply ) ) &&
    $GLOBALS['installs'] === 0, 'Existing governed upload grant is mandatory' );
$GLOBALS['grant'] = true;
$GLOBALS['audit_ready'] = false;
assert_self_approval( is_wp_error( $c::apply( $apply ) ) &&
    $GLOBALS['installs'] === 0, 'Failed audit blocks self-certified update' );
$GLOBALS['audit_ready'] = true;
$GLOBALS['env'] = 'production';
$production = $c::plan( $args );
assert_self_approval( ! $production['eligible'] &&
    is_wp_error( $c::apply( $apply ) ) &&
    $GLOBALS['installs'] === 0, 'Production cannot use self-certification' );
$GLOBALS['env'] = 'staging';
$accepted = $c::apply( $apply );
assert_self_approval( is_array( $accepted ) && !empty( $accepted['installed'] )
    && $accepted['evidence_mode'] === 'site_self_certified_staging'
    && $accepted['independent_attestation_verified'] === false
    && $accepted['production_authorized'] === false
    && $GLOBALS['installs'] === 1 && $GLOBALS['audit'] > 0,
    'Exact owner-delegated Staging self-approval uses protected installer' );
echo "PASS: local self-approval, original update gate, tamper, audit, OAuth and Production denial\n";
