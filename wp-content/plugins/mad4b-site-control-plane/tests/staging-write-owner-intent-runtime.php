<?php
/** Hermetic owner decision fixture. No real WordPress or network operations. */
define( 'ABSPATH', __DIR__ );
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_salt( $scheme = 'auth' ) { return 'fixture-random-local-secret-2026-'. $scheme; }
function nocache_headers() { $GLOBALS['no_cache_called'] = true; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return esc_html( $v ); }
function admin_url( $p = '' ) { return 'https://staging.test/wp-admin/' . ltrim( $p, '/' ); }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="fixture" />'; }
function submit_button( $label ) { echo '<button>' . esc_html( $label ) . '</button>'; }
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
class MAD4B_SCP_Staging_Write_Authority_Convergence {
    const HANDSHAKE_ABILITY = 'mad4b/staging-write-convergence-handshake';
    const APPLY_ABILITY = 'mad4b/staging-write-authority-convergence-apply';
    static function plan() { return $GLOBALS['plan']; }
}
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
// Verified owner OAuth step-up is independent of Write candidate freshness.
class MAD4B_SCP_Local_OAuth_Server { const CHATGPT_CIMD_CLIENT_ID='https://chatgpt.com/oauth/client.json'; }
class MAD4B_SCP_OAuth_Resource_Bridge {
 const AUTHORITY_STEP_UP_SCOPE='mad4b:authority:step-up';
 static function verified_bearer_active(){return $GLOBALS['bearer'];}
 static function verified_bearer_has_scope($s){return $GLOBALS['scope'] && self::AUTHORITY_STEP_UP_SCOPE===$s;}
 static function verified_bearer_client_is($s){return $GLOBALS['client'] && MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID===$s;}
}
class MAD4B_SCP_Identity_Context {
 static function current(){return array('authenticated'=>$GLOBALS['identity'],'subject_type'=>'oauth','auth_method'=>'oauth2_bearer');}
}
$GLOBALS['bearer']=true;$GLOBALS['scope']=true;$GLOBALS['client']=true;$GLOBALS['identity']=true;
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-owner-intent.php';
$klass = 'MAD4B_SCP_Staging_Write_Owner_Intent';
$review = $klass::review();
assertion( is_array( $review ), 'Staging owner review available' );
$payload = array( 'review_digest' => $review['review_digest'], 'confirmation' => $klass::CONFIRM );
$record = $klass::attest( $payload );
assertion( is_array( $record ) && 'OWNER_INTENT_NOT_AUTHORITY' === $record['state'], 'Owner intent not a mutation approval' );
$receipt = $record['receipt_sha256'];
$status = $klass::page_intent_status( $review );
assertion( 'OWNER_INTENT_CURRENT' === $status['state'] && $receipt === $status['receipt_sha256'], 'fresh GET recognizes signed confirmation' );
ob_start();
$klass::page();
$view_html = ob_get_clean();
assertion( false !== strpos( $view_html, 'Owner confirmation recorded and verified.' ), 'confirmation shows refreshed post-save state' );
assertion( false === strpos( $view_html, '<form' ), 'active intent does not immediately repeat same confirmation form' );
assertion( $GLOBALS['no_cache_called'] === true, 'owner result page disables caching' );
$modified = $GLOBALS['options'][ $klass::OPTION ];
$GLOBALS['options'][ $klass::OPTION ]['expires'] = time()-2;
assertion( 'NO_VERIFIED_OWNER_INTENT' === $klass::page_intent_status( $review )['state'], 'tampered signed state never accepted' );
$_GET['intent'] = 'recorded';
ob_start();
$klass::page();
$forged_url = ob_get_clean();
assertion( false === strpos( $forged_url, 'Owner confirmation recorded and verified.' )
    && false !== strpos( $forged_url, '<form' ), 'success query alone never disguises stale persisted state' );
unset( $_GET['intent'] );
$GLOBALS['options'][ $klass::OPTION ] = $modified;
$changed_review = $review;
$changed_review['review_digest'] = str_repeat( '0', 64 );
assertion( 'OWNER_INTENT_PLAN_CHANGED' === $klass::page_intent_status( $changed_review )['state'], 'stale plan never displays success' );
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
$claimed_view = $klass::page_intent_status( $klass::review() );
assertion( 'OWNER_INTENT_CLAIMED' === $claimed_view['state'], 'claimed receipt has a distinct post-refresh status' );
ob_start();
$klass::page();
$claimed_html = ob_get_clean();
assertion( false !== strpos( $claimed_html, 'Owner confirmation consumed.' )
    && false === strpos( $claimed_html, '<form' ), 'claimed flow does not display a duplicate confirmation' );
assertion( is_wp_error( $klass::claim( $GLOBALS['plan'], array('owner_intent_sha256'=>$receipt) ) ), 'Replay denied' );
assertion( in_array( 'mad4b/staging-write-owner-claimed', $GLOBALS['events'], true ), 'Claim audited' );

// Remote owner decision must preserve the exact OAuth client/step-up boundary.
assertion( true === $klass::can_remote_attest(), 'Normal enrolled owner OAuth admitted' );
$GLOBALS['bearer']=false;
assertion( is_wp_error( $klass::can_remote_attest() ), 'Missing bearer denied' );
$GLOBALS['bearer']=true;$GLOBALS['scope']=false;
assertion( is_wp_error( $klass::can_remote_attest() ), 'Missing step-up denied' );
$GLOBALS['scope']=true;$GLOBALS['client']=false;
assertion( is_wp_error( $klass::can_remote_attest() ), 'Foreign client denied' );
$GLOBALS['client']=true;$GLOBALS['identity']=false;
assertion( is_wp_error( $klass::can_remote_attest() ), 'Unauthenticated subject denied' );
$GLOBALS['identity']=true;$GLOBALS['environment']='production';
assertion( is_wp_error( $klass::can_remote_attest() ), 'Production remote owner attest denied' );
$GLOBALS['environment']='staging';

echo "STAGING_WRITE_OWNER_INTENT: PASS (hermetic fixture)\n";
