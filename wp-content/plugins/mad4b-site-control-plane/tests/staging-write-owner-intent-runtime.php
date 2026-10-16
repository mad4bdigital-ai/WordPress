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
function is_admin() { return ! empty( $GLOBALS['is_admin_request'] ); }
function is_user_logged_in() { return true; }
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
    static function status() { return array( 'write_ready' => false,
        'candidate_binding' => $GLOBALS['plan']['write_reconciliation']['candidate_binding'] ?? array(),
        'write_plan' => $GLOBALS['plan']['write_reconciliation'] ?? array() ); }
    static function plan_for_local_owner() { return isset( $GLOBALS['plan_local'] )
        ? $GLOBALS['plan_local'] : $GLOBALS['plan']; }
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
    'write_reconciliation' => array(
        'write_tool_count' => 102, 'exact_grants_existing' => 102,
        'exact_grants_missing_count' => 0, 'current_ready' => false,
        'write_inventory_fingerprint' => str_repeat( '1', 64 ),
        'grant_rows_fingerprint' => str_repeat( '2', 64 ),
        'candidate_binding' => array( 'match' => false,
            'stored_source_commit_sha' => str_repeat( '3', 40 ),
            'current_source_commit_sha' => str_repeat( 'c', 40 ) ),
    ),
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
$diagnostic = $klass::diagnose();
assertion( is_array( $diagnostic ) && $diagnostic['owner_intent_state'] === 'OWNER_INTENT_CURRENT'
    && !$diagnostic['identity_drift'] && !$diagnostic['review_digest_differs'],
    'same plan across local/remote is a matched diagnostic, not a grant' );
assertion( $diagnostic['read_only'] && !$diagnostic['mutation_performed']
    && !$diagnostic['write_ready_readback'] && $diagnostic['grant_rows_existing'] === 102
    && $diagnostic['registered_write_abilities'] === 102
    && !$diagnostic['candidate_binding_match'],
    '102/102 grants cannot certify candidate binding or effective writes' );
$before_drift = $GLOBALS['plan'];
$GLOBALS['plan']['source_commit_sha'] = str_repeat( 'f', 40 );
$drift = $klass::diagnose();
assertion( $drift['state'] === 'OWNER_REVIEW_PLAN_DRIFT'
    && in_array( 'source_commit_sha', $drift['identity_fields_changed'], true )
    && $drift['next_action'] === 'compare_exact_owner_plan_fields_no_blind_retry',
    'different exact plan must fail closed, no blind apply' );
$GLOBALS['plan'] = $before_drift;
$GLOBALS['plan']['write_reconciliation']['grant_rows_fingerprint'] = str_repeat( 'f', 64 );
$fingerprint_drift = $klass::diagnose();
assertion( $fingerprint_drift['state'] === 'OWNER_REVIEW_PLAN_DRIFT'
    && in_array( 'grant_rows_fingerprint',
        $fingerprint_drift['non_authorizing_plan_evidence_fields_changed'], true ),
    'grant inventory snapshot drift is diagnosed, never silently normalized' );
$GLOBALS['plan'] = $before_drift;
assertion( is_wp_error( $klass::diagnose( array( 'apply' => true ) ) ),
    'diagnostic refuses mutation-like input' );

$plan_unavailable = $klass::page_intent_status( null );
assertion( 'OWNER_INTENT_PLAN_UNAVAILABLE' === $plan_unavailable['state'],
    'missing current plan must not be mislabeled a changed digest' );
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
$claimed_diagnosis = $klass::diagnose();
assertion( $claimed_diagnosis['state'] === 'CLAIMED_EXECUTION_UNVERIFIED_DO_NOT_RETRY'
    && $claimed_diagnosis['next_action'] === 'readback_write_runtime_binding_audit_do_not_replay',
    'claimed but unverified Write does not blindly repeat an MCP apply' );
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

// Separate WordPress Admin and OAuth transports must agree on EXACT plan
// identity. A local approval is never silently recast as a remote approval.
$GLOBALS['is_admin_request'] = true;
$GLOBALS['bearer'] = false;
$GLOBALS['plan_local'] = $GLOBALS['plan'];
$admin_review = $klass::review();
assertion( is_array( $admin_review ), 'local admin review uses read-only preview' );
$local_attest = $klass::attest( array(
    'review_digest' => $admin_review['review_digest'], 'confirmation' => $klass::CONFIRM ) );
assertion( is_array( $local_attest ), 'admin owner decision recorded' );
$stored_admin = $GLOBALS['options'][ $klass::OPTION ];
assertion( $stored_admin['review_channel'] === 'wp_admin', 'record signing remembers approval transport' );
$GLOBALS['is_admin_request'] = false;
$GLOBALS['bearer'] = true;
$parity = $klass::diagnose();
assertion( $parity['owner_intent_state'] === 'OWNER_INTENT_CURRENT'
    && $parity['identity_fields_changed'] === array()
    && $parity['stored_plan_sha256'] === $parity['current_plan_sha256'],
    'identical admin and OAuth plans admit signed read-only parity' );
$GLOBALS['plan']['plan_sha256'] = str_repeat( '9', 64 );
$cross_transport_drift = $klass::diagnose();
assertion( $cross_transport_drift['state'] === 'OWNER_REVIEW_PLAN_DRIFT'
    && in_array( 'plan_sha256', $cross_transport_drift['identity_fields_changed'], true )
    && $cross_transport_drift['recorded_review_channel'] === 'wp_admin',
    'distinct exact remote plan exposed without substituting manual approval' );
assertion( $cross_transport_drift['read_only']
    && $cross_transport_drift['requested_plan_not_replayed'],
    'mismatch cannot cause a retry or write' );
$GLOBALS['plan']['plan_sha256'] = $GLOBALS['plan_local']['plan_sha256'];
// Already-signed legacy receipts lacking a field-level identity must be
// reported as unexplainable, not retroactively inferred from a digest.
$legacy = $stored_admin;
unset( $legacy['plan_identity'], $legacy['review_channel'], $legacy['receipt_sha256'] );
$legacy['receipt_sha256'] = hash_hmac( 'sha256',
    wp_json_encode( $legacy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), wp_salt( 'auth' ) );
$GLOBALS['options'][ $klass::OPTION ] = $legacy;
$legacy_status = $klass::diagnose();
assertion( $legacy_status['state'] === 'LEGACY_RECEIPT_IDENTITY_UNAVAILABLE'
    && $legacy_status['recorded_receipt_legacy_identity_missing']
    && !$legacy_status['identity_drift'],
    'legacy receipt mismatch cannot invent fields' );
$GLOBALS['options'][ $klass::OPTION ] = $stored_admin;
$GLOBALS['scope'] = false;
assertion( is_wp_error( $klass::diagnose() ), 'OAuth diagnostic requires owner step-up' );
$GLOBALS['scope'] = true;

echo "STAGING_WRITE_OWNER_INTENT: PASS (hermetic fixture)\n";
