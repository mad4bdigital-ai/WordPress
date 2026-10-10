<?php
/* Hermetic selected-HEAD plan/apply acceptance. No GitHub or WordPress mutation. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function expect( $v, $message ) {
    $GLOBALS['assertions']++;
    if ( ! $v ) { fwrite( STDERR, "FAILED: $message\n" ); exit( 1 ); }
}
function add_action( $name, $cb, $priority = 10 ) { $GLOBALS['actions'][] = $name; }
function wp_has_ability( $name ) { return false; }
function wp_register_ability( $name, $data ) { $GLOBALS['registered'][ $name ] = $data; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_json_encode( $s ) { return json_encode( $s ); }
function wp_get_environment_type() { return 'staging'; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
$GLOBALS['assertions'] = 0;
$GLOBALS['source_calls'] = 0;
$GLOBALS['download_calls'] = 0;
$GLOBALS['apply_calls'] = 0;
$GLOBALS['registered'] = array();
$GLOBALS['events'] = array();
$GLOBALS['case'] = isset( $argv[1] ) ? $argv[1] : 'normal';
$head = str_repeat( 'a', 40 );
$other = str_repeat( 'b', 40 );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
if ( 'disabled' !== $GLOBALS['case'] ) {
    define( 'MAD4B_SCP_SELECTED_HEAD_UPDATES_ENABLED', true );
    define( 'MAD4B_SCP_STAGING_CANDIDATE_UPDATES_ENABLED', true );
}
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
class MAD4B_SCP_Authorization {
    public static function authorize_mutation() { return true; }
}
class MAD4B_SCP_Site_Profile {
    public static function wordpress_environment_explicit() { return true; }
    public static function status() {
        return array( 'deployment_binding_configured' => true, 'same_origin_clone_protection' => true );
    }
}
class MAD4B_SCP_Staging_Source_Selector {
    public static function resolve( $input ) {
        $GLOBALS['source_calls']++;
        if ( ! is_array( $input ) ||
            ( $input['repository'] ?? '' ) !== 'mad4bdigital-ai/WordPress' ||
            ! in_array( $input['type'] ?? '', array( 'pull_request', 'branch', 'commit' ), true ) ||
            empty( $input['reference'] ) ) return new WP_Error( 'invalid_source' );
        $sha = str_repeat( 'a', 40 );
        if ( $GLOBALS['case'] === 'drift' && $GLOBALS['source_calls'] >= 3 )
            $sha = str_repeat( 'b', 40 );
        return array(
            'repository' => $input['repository'], 'type' => $input['type'],
            'reference' => $input['reference'], 'resolved_sha' => $sha, 'remote_verified' => true,
        );
    }
}
class MAD4B_SCP_Self_Update {
    public static function can_upload_apply( $x ) { return true; }
    public static function upload_plan( $input ) {
        $GLOBALS['upload_plan_calls'] = ( $GLOBALS['upload_plan_calls'] ?? 0 ) + 1;
        expect( $input['channel'] === 'staging_candidate_upload',
            'optional plan accidentally replaced default release channel' );
        return array( 'eligible' => true, 'blockers' => array(),
            'plan_sha256' => hash( 'sha256', json_encode( $input ) ) );
    }
    public static function upload_apply( $input ) {
        $GLOBALS['apply_calls']++;
        expect( $input['channel'] === 'staging_candidate_upload', 'wrong update channel' );
        expect( $input['candidate_confirmation'] === 'INSTALL EXACT STAGING CANDIDATE',
            'existing safety confirmation bypassed' );
        expect( base64_decode( $input['package_base64'], true ) === 'CERTIFIED_ZIP_BYTES',
            'caller data replaced the exact GitHub-owned package' );
        return array( 'applied' => true, 'test_only' => true );
    }
}
function wp_safe_remote_get( $url, $options ) {
    $GLOBALS['events'][] = $url;
    expect( strpos( $url, 'https://github.com/mad4bdigital-ai/WordPress/releases/download/mad4b-site-control-plane-update-channel/' ) === 0,
        'non-allowlisted package host/path' );
    expect( $options['sslverify'] === true, 'SSL verification disabled' );
    $sha = str_repeat( 'a', 40 );
    if ( substr( $url, -5 ) === '.json' ) {
        if ( $GLOBALS['case'] === 'missing' ) return array( 'code' => 404, 'body' => '' );
        $m = array(
            'version' => '0.4.0-rc.98', 'source_commit_sha' => $sha,
            'archive_sha256' => hash( 'sha256', 'CERTIFIED_ZIP_BYTES' ),
            'build_fingerprint' => str_repeat( 'c', 64 ),
            'package_manifest_digest' => str_repeat( 'd', 64 ),
            'size_bytes' => strlen( 'CERTIFIED_ZIP_BYTES' ),
            'release_verdict_run_id' => 999, 'release_verdict_success' => true,
            'staging_candidate_certified' => $GLOBALS['case'] !== 'uncertified',
            'package_url' => 'https://github.com/mad4bdigital-ai/WordPress/releases/download/mad4b-site-control-plane-update-channel/mad4b-site-control-plane-' . $sha . '.zip',
        );
        return array( 'code' => 200, 'body' => json_encode( $m ) );
    }
    $GLOBALS['download_calls']++;
    return array( 'code' => 200, 'body' => 'bad-archive' === $GLOBALS['case'] ? 'CORRUPTED' : 'CERTIFIED_ZIP_BYTES' );
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-selected-head-update.php';
$cls = 'MAD4B_SCP_Selected_Head_Update';
$cls::boot();
$cls::register_abilities();
expect( count( $GLOBALS['registered'] ) === 2, 'both plan/apply abilities must be explicitly registered' );
expect( $GLOBALS['registered'][$cls::PLAN_ABILITY]['meta']['annotations']['readonly'] === true, 'plan must be read-only' );
expect( $GLOBALS['registered'][$cls::APPLY_ABILITY]['meta']['mcp']['public'] === false, 'apply must not be public' );
$select = array( 'candidate_source' => array(
    'repository' => 'mad4bdigital-ai/WordPress', 'type' => 'pull_request', 'reference' => '258'
), 'reason' => 'Test explicitly chosen source on staging' );
$plan = $cls::plan( $select );
expect( is_array( $plan ) && $plan['automatic_update'] === false &&
    $plan['default_release_channel_unchanged'] === true, 'selected update became default' );
if ( 'disabled' === $GLOBALS['case'] ) {
    expect( !$plan['eligible'] && in_array( 'selected_head_opt_in_disabled', $plan['blockers'], true ),
        'optional mode enabled without host opt-in' );
    expect( is_wp_error( $cls::apply( array_merge( $select, array(
        'confirmation' => $cls::CONFIRMATION,
        'expected_plan_sha256' => $plan['plan_sha256']
    ) ) ) ), 'disabled mode applied an update' );
} elseif ( 'missing' === $GLOBALS['case'] || 'uncertified' === $GLOBALS['case'] ) {
    expect( !$plan['eligible'], 'missing/uncertified artifact considered eligible' );
    expect( $GLOBALS['download_calls'] === 0, 'unsigned package was downloaded' );
} else {
    expect( $plan['eligible'], 'valid optional Staging plan unexpectedly blocked' );
    $action = array_merge( $select, array(
        'confirmation' => $cls::CONFIRMATION, 'expected_plan_sha256' => $plan['plan_sha256'],
    ) );
    expect( is_wp_error( $cls::apply( array_merge( $action, array( 'confirmation' => 'YES' ) ) ) ),
        'missing typed approval accepted' );
    expect( is_wp_error( $cls::apply( array_merge( $action, array( 'expected_plan_sha256' => str_repeat( '0', 64 ) ) ) ) ),
        'stale approval accepted' );
    $result = $cls::apply( $action );
    if ( $GLOBALS['case'] === 'drift' || $GLOBALS['case'] === 'bad-archive' ) {
        expect( is_wp_error( $result ) && $GLOBALS['apply_calls'] === 0,
            'moved source or corrupted archive was installed' );
    } else {
        expect( is_array( $result ) && $result['applied'] === true && $GLOBALS['apply_calls'] === 1,
            'certified exact HEAD failed governed handoff' );
    }
}
echo "PASS selected-head opt-in {$GLOBALS['case']}: {$GLOBALS['assertions']} checks\n";
