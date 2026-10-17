<?php
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message, $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_salt( $x = 'auth' ) { return 'test-owner-secret-20261017-' . $x; }
function wp_json_encode( $x, $opts = 0 ) { return json_encode( $x, $opts ); }
$GLOBALS['options'] = array();
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['options'] ) ? $GLOBALS['options'][$name] : $default;
}
function add_option( $key, $value, $unused = '', $autoload = false ) {
    // Simulate a concurrent add_option that reports success even though the
    // actual wp_options lock belongs to a different site operation.
    if ( !empty($GLOBALS['simulate_nominal_lock_success'])
        && 0===strpos($key,'mad4b_scp_staging_operation_active_v1_') ) {
        $GLOBALS['options'][$key]=str_repeat('f',64);
        return true;
    }
    if ( isset( $GLOBALS['options'][$key] ) ) return false;
    $GLOBALS['options'][$key] = $value;
    return true;
}
function delete_option( $key ) {
    if ( ! empty($GLOBALS['delete_fail']) ) return false;
    if ( ! isset( $GLOBALS['options'][$key] ) ) return false;
    unset( $GLOBALS['options'][$key] ); return true;
}
function update_option( $key, $value, $autoload = false ) {
    if ( ! empty( $GLOBALS['block_updates'] )
        || ( !empty($GLOBALS['block_verified_update']) && is_array($value)
            && ($value['state'] ?? '') === 'VERIFIED' ) ) return false;
    $GLOBALS['options'][$key] = $value;
    return true;
}
function check_journal( $condition, $label ) {
    if ( ! $condition ) { fwrite(STDERR, 'FAIL ' . $label . "\n"); exit(1); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-operation-journal.php';
$J = 'MAD4B_SCP_Staging_Operation_Journal';
$plan = array(
    'plan_sha256' => str_repeat('a',64), 'site_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    'source_commit_sha' => str_repeat('b',40), 'build_fingerprint' => str_repeat('c',64),
    'package_manifest_digest' => str_repeat('d',64),
    'hard_blockers' => array('no-op-reconcilable'),
);
$id = $J::id( $plan );
check_journal( preg_match('/^[a-f0-9]{64}$/D', $id), 'deterministic bounded id' );
check_journal( $J::id( array( 'site_uuid' => $plan['site_uuid'] ) ) === '', 'invalid plan blocked' );
check_journal( $J::inspect_plan( $plan )['state'] === 'UNVERIFIED_OR_UNAVAILABLE',
    'unseen plan read is not a success claim' );
$reservation = $J::reserve( $plan );
check_journal( is_array($reservation) && $reservation['operation_id'] === $id
    && $J::inspect_plan( $plan )['state'] === 'EXECUTION_UNCERTAIN',
    'atomic reservation and signed readback' );
check_journal( is_wp_error($J::reserve($plan)), 'replay same plan denied' );
check_journal( $J::latest($plan['site_uuid'])['operation_id'] === $id,
    'latest pointer refers to independently verified operation' );
$altered = $plan;
$altered['hard_blockers'] = array();
check_journal( $J::id($altered) === $id, 'diagnostic-only changes do not create new operation id' );
check_journal( is_wp_error($J::reserve($altered)), 'same plan cannot replay when volatile blockers change' );
check_journal( $J::suspend($plan) && $J::inspect_plan($plan)['state'] === 'RECOVERY_REQUIRED',
    'unknown exit becomes sticky recovery-required' );
check_journal( !$J::complete($plan, str_repeat('e',64)), 'suspended operation not auto completed or reset' );
check_journal( is_wp_error($J::reserve($plan)), 'suspended tombstone forbids retry' );
$new = $plan; $new['plan_sha256'] = str_repeat('9',64);
check_journal( $J::id($new) !== $id, 'new exact plan is independent operation' );
check_journal( is_wp_error($J::reserve($new)),
    'new plan cannot bypass an earlier uncertain operation for the same site' );
$new['site_uuid'] = '11111111-2222-3333-4444-555555555555';
check_journal( is_array($J::reserve($new)), 'different site may reserve independent operation' );
check_journal( !$J::complete($new,'not-a-digest'), 'invalid postcondition receipt blocked' );
check_journal( $J::complete($new,str_repeat('e',64),str_repeat('f',64)),
    'independently supplied postcondition stores verified state and post-plan' );
check_journal( $J::inspect_plan($new)['state'] === 'VERIFIED', 'verified state readback' );
check_journal( $J::inspect_plan($new)['post_execution_plan_sha256'] === str_repeat('f',64),
    'initial approval plan and post-execution plan explicitly linked' );
$third = $new; $third['plan_sha256'] = str_repeat('8',64);
check_journal( is_array($J::reserve($third)),
    'completed operation releases site lock for independently reviewed new plan' );
check_journal( $J::suspend($third), 'later uncertain attempt retains site lock' );
check_journal( $J::suspend($new) && $J::inspect_plan($new)['state'] === 'VERIFIED',
    'later failure handler cannot corrupt already verified tombstone' );
check_journal( is_wp_error($J::reserve($new)), 'verified operation never blindly replayed' );
$k = $J::PREFIX . $J::id($new);
$GLOBALS['options'][$k]['state'] = 'EXECUTION_UNCERTAIN';
check_journal( $J::inspect_plan($new)['state'] === 'UNVERIFIED_OR_UNAVAILABLE',
    'tampered status fails closed' );
check_journal( is_wp_error($J::reserve($new)), 'tampered existing row cannot be overwritten as fresh' );
$site_other='99999999-2222-3333-4444-555555555555';
check_journal( $J::latest($site_other)['state'] === 'NO_CURRENT_OPERATION_POINTER',
    'cross-site latest pointer denied' );
$GLOBALS['options'][$J::ACTIVE_PREFIX . substr(hash('sha256',$site_other),0,32)] = str_repeat('f',64);
check_journal( $J::latest($site_other)['state'] === 'OPERATION_LOCK_WITHOUT_INDEX',
    'orphan atomic site lock is visible and denies blind retry' );
$orphan = $plan; $orphan['site_uuid'] = $site_other;
check_journal( is_wp_error($J::reserve($orphan)), 'orphan site lock blocks new plan safely' );
// Failure injection 1: failure to delete the site lock must never
// publish VERIFIED. All subsequent plans must remain blocked.
$GLOBALS['options']=array(); $GLOBALS['delete_fail']=false;
$a=$plan; $a['site_uuid']='33333333-2222-3333-4444-555555555555';
check_journal(is_array($J::reserve($a)),'reserve failure-injection site');
$GLOBALS['delete_fail']=true;
check_journal(!$J::complete($a,str_repeat('d',64),str_repeat('e',64)),
    'injected lock deletion failure surfaced');
check_journal($J::inspect_plan($a)['state']==='FINALIZATION_LOCK_HELD',
    'never claim success while a lock survives');
$b=$a; $b['plan_sha256']=str_repeat('2',64);
check_journal(is_wp_error($J::reserve($b)),'new operation blocked while release pending');
$GLOBALS['delete_fail']=false;

// Failure injection 2: lock released but persistence of VERIFIED fails.
// An independent request must observe a FINALIZATION_INCOMPLETE blocker.
$GLOBALS['options']=array();
$a=$plan; $a['site_uuid']='44444444-2222-3333-4444-555555555555';
check_journal(is_array($J::reserve($a)), 'reserve post-release persist failure');
$GLOBALS['block_verified_update']=true;
check_journal(!$J::complete($a,str_repeat('d',64),str_repeat('e',64)),
    'injected final status persistence failure surfaced');
check_journal($J::latest($a['site_uuid'])['state']==='FINALIZATION_INCOMPLETE',
    'interrupted final commit cannot claim VERIFIED');
$b=$a; $b['plan_sha256']=str_repeat('2',64);
check_journal(is_wp_error($J::reserve($b)), 'new plan blocked by incomplete final record');
$GLOBALS['block_verified_update']=false;

// Failure injection 3: old VERIFIED row with an orphan lock must be visible.
$GLOBALS['options']=array();
$a=$plan; $a['site_uuid']='55555555-2222-3333-4444-555555555555';
check_journal(is_array($J::reserve($a)), 'reserve legacy-lock case');
check_journal($J::complete($a,str_repeat('d',64),str_repeat('e',64)), 'normal verify first');
$GLOBALS['options'][$J::ACTIVE_PREFIX . substr(hash('sha256',$a['site_uuid']),0,32)]=$J::id($a);
check_journal($J::latest($a['site_uuid'])['state']==='VERIFIED_LOCK_HELD',
    'legacy VERIFIED record with retained lock is visibly inconsistent');
$b=$a; $b['plan_sha256']=str_repeat('2',64);
check_journal(is_wp_error($J::reserve($b)), 'legacy orphan lock blocks repeat');

// Failure injection 4: no index after a reservation surfaces a recovery state.
$GLOBALS['options']=array();
$a=$plan; $a['site_uuid']='66666666-2222-3333-4444-555555555555';
$GLOBALS['block_updates']=true;
check_journal(is_wp_error($J::reserve($a)), 'missing operation index reports failure');
check_journal($J::latest($a['site_uuid'])['state']==='OPERATION_LOCK_WITHOUT_INDEX',
    'missing index retains evidence of active lock');
$GLOBALS['block_updates']=false;

// Failure injection 5: a nominal add_option success without DB lock ownership
// may never create a second distinct operation tombstone.
$GLOBALS['options']=array();
$a=$plan; $a['site_uuid']='77777777-2222-3333-4444-555555555555';
$GLOBALS['simulate_nominal_lock_success']=true;
$attempt=$J::reserve($a);
check_journal(is_wp_error($attempt)
    && $attempt->get_error_code()==='mad4b_site_operation_lock_claim_unverified',
    'false positive lock claim must fail closed before journal append');
check_journal(!isset($GLOBALS['options'][$J::PREFIX.$J::id($a)]),
    'unowned lock must not create an immutable second operation');
$GLOBALS['simulate_nominal_lock_success']=false;

echo "STAGING_OPERATION_JOURNAL_RUNTIME_PASS\n";
