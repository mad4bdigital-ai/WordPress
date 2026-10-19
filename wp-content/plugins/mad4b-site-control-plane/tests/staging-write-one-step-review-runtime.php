<?php
/** Hermetic one-call owner-review transport contract. No WordPress, files or network. */
define( 'ABSPATH', __DIR__ );
function is_wp_error( $value ) { return $value instanceof WP_Error; }
final class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function assert_contract( $pass, $label ) {
    if ( ! $pass ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); }
}
$GLOBALS['scope_allowed'] = true;
$GLOBALS['attest_calls'] = 0;
$GLOBALS['claim_calls'] = 0;
$GLOBALS['native_apply_calls'] = 0;
$GLOBALS['owner_claims'] = array();
final class MAD4B_SCP_Deployment_Binding_Native {
    public static $state = 'READY_FOR_OWNER_APPROVAL';
    public static function status() { return array( 'state' => self::$state ); }
    public static function apply( $payload ) { ++$GLOBALS['native_apply_calls']; return new WP_Error( 'should_never_run' ); }
}
final class MAD4B_SCP_Staging_Write_Owner_Intent {
    const CONFIRM = 'AUTHORIZE EXACT STAGING WRITE CONVERGENCE';
    public static function digest( array $plan ) {
        return hash( 'sha256', $plan['site_uuid'] . ':' . $plan['plan_sha256'] );
    }
    public static function can_remote_attest() {
        return $GLOBALS['scope_allowed'] ? true : new WP_Error( 'oauth_step_up_denied' );
    }
    public static function attest( $input ) {
        ++$GLOBALS['attest_calls'];
        if ( self::CONFIRM !== ( $input['confirmation'] ?? '' ) ) return new WP_Error( 'bad_confirmation' );
        return array( 'receipt_sha256' => hash( 'sha256', 'signed:' . $input['review_digest'] ) );
    }
    public static function validate_claimable( array $plan, $input ) {
        $hash = $input['owner_intent_sha256'] ?? '';
        return preg_match( '/^[a-f0-9]{64}$/D', $hash ) && ! isset( $GLOBALS['owner_claims'][$hash] )
            ? array( 'owner_user' => 7, 'receipt_sha256' => $hash, 'preflight_only' => true )
            : new WP_Error( 'invalid_or_replayed_receipt' );
    }
    public static function claim( array $plan, $input ) {
        ++$GLOBALS['claim_calls'];
        $hash = $input['owner_intent_sha256'] ?? '';
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) return new WP_Error( 'invalid_receipt' );
        if ( isset( $GLOBALS['owner_claims'][$hash] ) ) return new WP_Error( 'receipt_replayed' );
        $GLOBALS['owner_claims'][$hash] = true;
        return array( 'owner_user' => 7, 'receipt_sha256' => $hash, 'claimed_once' => true );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-authority-convergence.php';
$klass = 'MAD4B_SCP_Staging_Write_Authority_Convergence';
$plan = array( 'plan_sha256' => str_repeat( 'a', 64 ), 'site_uuid' => 'staging-site-a' );
$review = MAD4B_SCP_Staging_Write_Owner_Intent::digest( $plan );
assert_contract( is_wp_error( $klass::authorize_reviewed_owner_intent( $plan, array() ) ), 'missing proof rejected' );
assert_contract( 0 === $GLOBALS['attest_calls'], 'no attestation for missing proof' );
// Enrollment may present signed owner intent together with the SAME exact
// read-only review digest. A mismatched review is still a hard stop.
$existing_receipt = hash( 'sha256', 'signed:' . $review );
$dual_preflight = $klass::authorize_reviewed_owner_intent( $plan, array(
    'owner_review_digest' => $review, 'owner_intent_sha256' => $existing_receipt ), false );
assert_contract( is_array( $dual_preflight ) && ! empty( $dual_preflight['preflight_only'] ), 'matching dual proof preflight accepted' );
assert_contract( 0 === $GLOBALS['attest_calls'] && 0 === $GLOBALS['claim_calls'], 'dual preflight has no side effects' );
$bad_dual = $klass::authorize_reviewed_owner_intent( $plan, array(
    'owner_review_digest' => str_repeat( 'b', 64 ), 'owner_intent_sha256' => $existing_receipt ), false );
assert_contract( is_wp_error( $bad_dual ) && 'mad4b_staging_owner_review_stale' === $bad_dual->get_error_code(), 'mismatched dual proof denied' );
assert_contract( is_wp_error( $klass::authorize_reviewed_owner_intent( $plan, array(
    'owner_review_digest' => str_repeat( 'c', 64 ) ) ) ), 'stale review rejected' );
assert_contract( 0 === $GLOBALS['attest_calls'], 'stale review never attested' );
$GLOBALS['scope_allowed'] = false;
$denied = $klass::authorize_reviewed_owner_intent( $plan, array( 'owner_review_digest' => $review ) );
assert_contract( is_wp_error( $denied ) && 'oauth_step_up_denied' === $denied->get_error_code(), 'wrong OAuth step-up denied' );
assert_contract( 0 === $GLOBALS['attest_calls'], 'denied step-up never attested' );
$GLOBALS['scope_allowed'] = true;
$dual_claim = $klass::authorize_reviewed_owner_intent( $plan, array(
    'owner_review_digest' => $review, 'owner_intent_sha256' => $existing_receipt ) );
assert_contract( is_array( $dual_claim ) && ! empty( $dual_claim['claimed_once'] ), 'matching dual receipt claimed exactly once' );
assert_contract( 0 === $GLOBALS['attest_calls'] && 1 === $GLOBALS['claim_calls'], 'dual claim never reattests' );
$dual_replayed = $klass::authorize_reviewed_owner_intent( $plan, array(
    'owner_review_digest' => $review, 'owner_intent_sha256' => $existing_receipt ), false );
assert_contract( is_wp_error( $dual_replayed ), 'dual receipt cannot replay' );
$GLOBALS['owner_claims'] = array();
$GLOBALS['claim_calls'] = 0;
$allowed = $klass::authorize_reviewed_owner_intent( $plan, array( 'owner_review_digest' => $review ) );
assert_contract( is_array( $allowed ) && ! empty( $allowed['claimed_once'] ), 'same-call review to signed receipt to one claim' );
assert_contract( 1 === $GLOBALS['attest_calls'] && 1 === $GLOBALS['claim_calls'], 'exactly one signed claim' );
assert_contract( is_wp_error( $klass::authorize_reviewed_owner_intent( $plan, array(
    'owner_intent_sha256' => $allowed['receipt_sha256'] ) ) ), 'one-use receipt cannot replay' );
assert_contract( 1 === $GLOBALS['attest_calls'], 'legacy receipt path does not reattest' );
$step = $klass::deployment_binding_guidance();
assert_contract( 'READY_FOR_OWNER_APPROVAL' === $step['state'] && $step['owner_ticket_required'], 'host binding approval distinct' );
assert_contract( ! empty( $step['not_authorized_by_write_owner_intent'] ) && 0 === $GLOBALS['native_apply_calls'], 'never bypass ticket' );
MAD4B_SCP_Deployment_Binding_Native::$state = 'ALREADY_HAS_PROFILE_BINDING';
$step = $klass::deployment_binding_guidance();
assert_contract( ! $step['owner_ticket_required'] && 'mad4b/deployment-binding-native-readback' === $step['next_ability'], 'binding readback required' );
echo "STAGING_WRITE_ONE_STEP_REVIEW: PASS (hermetic fixture)\n";
