<?php
/** Hermetic owner decision fixture. No real WordPress or network operations. */
define( 'ABSPATH', __DIR__ );
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function current_user_can( $c ) { return $GLOBALS['admin'] && 'manage_options' === $c; }
function get_current_user_id() { return $GLOBALS['user']; }
function get_option( $k, $default = false ) { return isset( $GLOBALS['options'][$k] ) ? $GLOBALS['options'][$k] : $default; }
function update_option( $k, $v, $autoload = false ) { $GLOBALS['options'][$k] = $v; return true; }
function add_option( $k, $v, $deprecated = '', $autoload = false ) {
    if ( array_key_exists( $k, $GLOBALS['options'] ) ) return false;
    $GLOBALS['options'][$k] = $v; return true;
}
class WP_Error {
    private $code;
    function __construct( $code, $message = '' ) { $this->code = $code; }
    function get_error_code() { return $this->code; }
}
class MAD4B_SCP_Site_Profile {
    static function configured() { return true; }
    static function current_environment() { return $GLOBALS['environment']; }
    static function origin_enrolled() { return true; }
    static function site_urls_match_enrollment() { return true; }
    static function oauth_user_ids() { return array( 7 ); }
}
class MAD4B_SCP_Policy { static function can_breakglass() { return $GLOBALS['breakglass']; } }
class MAD4B_SCP_Audit {
    static function storage_status() { return array( 'ready' => $GLOBALS['audit_ready'] ); }
    static function record( $name, $payload, $state ) {
        $GLOBALS['events'][] = $name;
        return $GLOBALS['audit_fail'] ? new WP_Error( 'audit_failed' ) : true;
    }
}
class MAD4B_SCP_Staging_Write_Authority_Convergence { static function plan() { return $GLOBALS['plan']; } }
function assertion( $okay, $label ) {
    if ( ! $okay ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); }
}
$GLOBALS['options'] = array();
$GLOBALS['events'] = array();
$GLOBALS['user'] = 7;
$GLOBALS['admin'] = true;
$GLOBALS['environment'] = 'staging';
$GLOBALS['breakglass'] = false;
$GLOBALS['audit_ready'] = true;
$GLOBALS['audit_fail'] = false;
$GLOBALS['plan'] = array(
    'ready_to_apply' => true,
    'plan_sha256' => str_repeat( 'a', 64 ),
    'site_uuid' => 'staging-site-a',
    'site_profile_revision' => 6,
    'site_profile_digest' => str_repeat( 'b', 64 ),
    'source_commit_sha' => str_repeat( 'c', 40 ),
    'build_fingerprint' => str_repeat( 'd', 64 ),
    'package_manifest_digest' => str_repeat( 'e', 64 ),
    'artifact_identity' => 'artifact-a',
);
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-owner-intent.php';
$klass = 'MAD4B_SCP_Staging_Write_Owner_Intent';
$review = $klass::review();
assertion( is_array( $review ), 'Staging owner review available' );
$payload = array( 'review_digest' => $review['review_digest'], 'confirmation' => $klass::CONFIRM );
$record = $klass::attest( $payload );
assertion( is_array( $record ) && 'OWNER_INTENT_NOT_AUTHORITY' === $record['state'], 'Owner intent not a mutation approval' );
$receipt = $record['receipt_sha256'];

$wrong = $GLOBALS['plan'];
$wrong['site_uuid'] = 'other-site';
assertion( is_wp_error( $klass::claim( $wrong, array('owner_intent_sha256'=>$receipt) ) ), 'Other site denied' );
$wrong = $GLOBALS['plan'];
$wrong['source_commit_sha'] = str_repeat('f',40);
assertion( is_wp_error( $klass::claim( $wrong, array('owner_intent_sha256'=>$receipt) ) ), 'Other source denied' );
$wrong = $GLOBALS['plan'];
$wrong['package_manifest_digest'] = str_repeat('f',64);
assertion( is_wp_error( $klass::claim( $wrong, array('owner_intent_sha256'=>$receipt) ) ), 'Other manifest denied' );
$GLOBALS['user'] = 8;
assertion( is_wp_error( $klass::claim( $GLOBALS['plan'], array('owner_intent_sha256'=>$receipt) ) ), 'Unenrolled administrator denied' );
$GLOBALS['user'] = 7;
$GLOBALS['environment'] = 'production';
assertion( is_wp_error( $klass::claim( $GLOBALS['plan'], array('owner_intent_sha256'=>$receipt) ) ), 'Production denied' );
$GLOBALS['environment'] = 'staging';
$GLOBALS['breakglass'] = true;
assertion( is_wp_error( $klass::claim( $GLOBALS['plan'], array('owner_intent_sha256'=>$receipt) ) ), 'Breakglass denied' );
$GLOBALS['breakglass'] = false;
$GLOBALS['options'][ $klass::OPTION ]['expires'] = time()-1;
assertion( is_wp_error( $klass::claim( $GLOBALS['plan'], array('owner_intent_sha256'=>$receipt) ) ), 'Expired intent denied' );

$record = $klass::attest( $payload );
$receipt = $record['receipt_sha256'];
$GLOBALS['audit_fail'] = true;
assertion( is_wp_error( $klass::claim( $GLOBALS['plan'], array('owner_intent_sha256'=>$receipt) ) ), 'Audit failure fails closed' );
$GLOBALS['audit_fail'] = false;
assertion( is_wp_error( $klass::claim( $GLOBALS['plan'], array('owner_intent_sha256'=>$receipt) ) ), 'Unknown claim cannot replay' );
$record = $klass::attest( $payload );
$receipt = $record['receipt_sha256'];
$claim = $klass::claim( $GLOBALS['plan'], array('owner_intent_sha256'=>$receipt) );
assertion( is_array( $claim ) && $claim['claimed_once'], 'Exact claim accepted once' );
assertion( is_wp_error( $klass::claim( $GLOBALS['plan'], array('owner_intent_sha256'=>$receipt) ) ), 'Replay denied' );
assertion( in_array( 'mad4b/staging-write-owner-claimed', $GLOBALS['events'], true ), 'Claim audited' );
echo "STAGING_WRITE_OWNER_INTENT: PASS (hermetic fixture)\n";
